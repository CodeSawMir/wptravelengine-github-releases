<?php

namespace WPTEDZGithub;

defined( 'ABSPATH' ) || exit;

class GithubRestController {

	private const NAMESPACE    = 'github/v1';
	private const ROUTE        = '/webhook';
	private const MAX_LOG_SIZE = 10;

	public static function register_routes(): void {
		register_rest_route( self::NAMESPACE, self::ROUTE, [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'install_from_issue' ],
			'permission_callback' => [ self::class, 'permission_check' ],
		] );
	}

	/**
	 * Verify the GitHub HMAC-SHA256 webhook signature.
	 * Without this, the install endpoint is unauthenticated RCE.
	 */
	public static function permission_check( \WP_REST_Request $request ): bool|\WP_Error {
		$sig = $request->get_header( 'X-Hub-Signature-256' );
		if ( ! $sig || ! str_starts_with( $sig, 'sha256=' ) ) {
			return new \WP_Error( 'missing_signature', 'Missing X-Hub-Signature-256 header.', [ 'status' => 403 ] );
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', $request->get_body(), WPTE_DZ_GITHUB_WEBHOOK_SECRET );
		if ( ! hash_equals( $expected, $sig ) ) {
			return new \WP_Error( 'invalid_signature', 'Webhook signature verification failed.', [ 'status' => 403 ] );
		}

		// Replay protection via X-GitHub-Delivery UUID.
		$delivery_id = $request->get_header( 'X-GitHub-Delivery' );
		if ( $delivery_id ) {
			if ( ! preg_match( '/^[0-9a-f\-]{36,72}$/i', $delivery_id ) ) {
				return new \WP_Error( 'invalid_delivery_id', 'Invalid X-GitHub-Delivery header.', [ 'status' => 400 ] );
			}
			$key = 'wpte_dz_gh_delivery_' . $delivery_id;
			if ( get_transient( $key ) ) {
				return new \WP_Error( 'replayed_delivery', 'Delivery already processed.', [ 'status' => 409 ] );
			}
			set_transient( $key, 1, DAY_IN_SECONDS );
		}

		return true;
	}

	/**
	 * Handles GitHub Projects v2 webhook.
	 *
	 * Expected payload: projects_v2_item edited event where the item is an Issue.
	 * Resolves the issue's content_node_id via GraphQL, then walks the
	 * issue → linked PRs → tags → releases → install pipeline.
	 * Only repos whose owner is the stored user or one of their orgs are installed.
	 */
	public static function install_from_issue( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		@set_time_limit( 300 );

		$payload = $request->get_json_params();

		// ── 1. Validate this is an issue edit event ───────────────────────────
		$action       = $payload['action'] ?? '';
		$item         = $payload['projects_v2_item'] ?? [];
		$content_type = $item['content_type'] ?? '';
		$node_id      = $item['content_node_id'] ?? '';

		if ( $action !== 'edited' || $content_type !== 'Issue' || ! $node_id ) {
			return new \WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'not_an_issue_edit' ], 200 );
		}

		// ── 2. Gate on target column label ────────────────────────────────────
		// Only process when issue is moved to 'Testing' or 'Push Zips'.
		$allowed_columns = [ 'Testing', 'Push Zips' ];
		$to_column       = $payload['changes']['field_value']['to']['name'] ?? '';
		if ( ! in_array( $to_column, $allowed_columns, true ) ) {
			return new \WP_REST_Response( [ 'status' => 'skipped', 'reason' => 'column_not_allowed', 'column' => $to_column ], 200 );
		}

		// ── 3. Auth: sender (user who moved the issue) must be the stored user ──
		$sender_login = $payload['sender']['login'] ?? '';

		$stored_user = get_option( 'wpte_dz_github_user', [] );
		$stored_login = strtolower( $stored_user['login'] ?? '' );

		
		if ( ! $stored_login || strtolower( $sender_login ) !== $stored_login ) {
			return new \WP_Error(
				'unauthorized_sender',
				'Sender is not the authorized GitHub user.',
				[ 'status' => 403 ]
			);
		}

		// ── 4. Resolve node ID → full_name + issue_number via GraphQL ─────────
		$issue_ref = self::resolve_node_id( $node_id );
		if ( is_wp_error( $issue_ref ) ) {
			return new \WP_Error( $issue_ref->get_error_code(), $issue_ref->get_error_message(), [ 'status' => 422 ] );
		}

		$full_name    = $issue_ref['full_name'];
		$issue_number = $issue_ref['issue_number'];

		// Validate full_name format — blocks path traversal in API URLs.
		if ( ! preg_match( '#^[a-zA-Z0-9._\-]+/[a-zA-Z0-9._\-]+$#', $full_name ) ) {
			return new \WP_Error( 'invalid_repo', 'Invalid repository name format resolved from node ID.', [ 'status' => 422 ] );
		}

		// ── 5. Fetch the issue ────────────────────────────────────────────────
		$issue = GithubApi::get_issue( $full_name, $issue_number );
		if ( is_wp_error( $issue ) ) {
			return new \WP_Error( $issue->get_error_code(), $issue->get_error_message(), [ 'status' => 422 ] );
		}

		$issue_summary = self::issue_summary( $issue );

		// ── 6. Get PRs linked to the issue ────────────────────────────────────
		$prs = GithubApi::get_issue_prs( $full_name, $issue_number );
		if ( is_wp_error( $prs ) ) {
			return new \WP_Error( $prs->get_error_code(), $prs->get_error_message(), [ 'status' => 422 ] );
		}

		if ( empty( $prs ) ) {
			return new \WP_REST_Response( [
				'issue'     => $issue_summary,
				'installed' => [],
				'errors'    => [],
				'message'   => 'No pull requests linked to this issue.',
			], 200 );
		}

		// ── 7. Walk PRs → tags → releases → install ───────────────────────────
		$installed = [];
		$errors    = [];

		// Build trusted owner allowlist: stored user login + their org logins.
		// Only repos whose owner is in this set are installed — prevents a
		// compromised webhook secret from installing repos outside the account.
		$trusted_owners = [];
		if ( ! empty( $stored_user['login'] ) ) {
			$trusted_owners[] = strtolower( $stored_user['login'] );
		}
		$orgs = GithubApi::get_user_orgs();
		if ( ! is_wp_error( $orgs ) ) {
			foreach ( $orgs as $org ) {
				$trusted_owners[] = strtolower( $org );
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		foreach ( $prs as $pr ) {
			$pr_repo   = $pr['head_repo'] ?? $full_name;
			$pr_number = (int) $pr['number'];

			// Skip repos whose owner is not trusted.
			$pr_owner = strtolower( explode( '/', $pr_repo )[0] ?? '' );
			if ( $trusted_owners && ! in_array( $pr_owner, $trusted_owners, true ) ) {
				$errors[] = [
					'pr'      => $pr_number,
					'pr_repo' => $pr_repo,
					'message' => 'Repo owner not in trusted allowlist.',
				];
				continue;
			}

			$tags = GithubApi::get_tags_for_pr( $pr_repo, $pr_number );
			if ( is_wp_error( $tags ) || empty( $tags ) ) {
				continue;
			}

			$releases = GithubApi::get_releases( $pr_repo );
			if ( is_wp_error( $releases ) || empty( $releases ) ) {
				continue;
			}

			$release_by_tag = [];
			foreach ( $releases as $release ) {
				$release_by_tag[ $release['tag'] ] = $release;
			}

			foreach ( $tags as $tag ) {
				if ( ! isset( $release_by_tag[ $tag ] ) ) {
					continue;
				}

				$release = $release_by_tag[ $tag ];
				$zip_url = $release['zip_url'] ?? '';

				if ( ! $zip_url ) {
					$errors[] = [
						'pr'      => $pr_number,
						'pr_repo' => $pr_repo,
						'tag'     => $tag,
						'message' => 'Release has no zip URL.',
					];
					continue;
				}

				$result = GithubInstaller::install_from_url( $zip_url, $pr_repo );
				if ( is_wp_error( $result ) ) {
					$errors[] = [
						'pr'      => $pr_number,
						'pr_repo' => $pr_repo,
						'tag'     => $tag,
						'message' => $result->get_error_message(),
					];
				} else {
					$plugin_file = $result['plugin_file'] ?? '';
					$activated   = false;
					$activate_err = '';

					if ( $plugin_file && file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
						$act = activate_plugin( $plugin_file );
						if ( is_wp_error( $act ) ) {
							$activate_err = $act->get_error_message();
						} else {
							$activated = true;
						}
					}

					$entry = array_merge( $result, [
						'pr'       => $pr_number,
						'pr_repo'  => $pr_repo,
						'tag'      => $tag,
						'zip_url'  => $zip_url,
						'activated' => $activated,
					] );

					if ( $activate_err ) {
						$entry['activate_error'] = $activate_err;
					}

					$installed[] = $entry;
					self::log_download( $issue_summary, $entry );
				}
			}
		}

		return new \WP_REST_Response( [
			'issue'     => $issue_summary,
			'sender'    => $sender_login,
			'installed' => $installed,
			'errors'    => $errors,
		], 200 );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Resolve a GitHub GraphQL node ID to a repo full_name + issue_number.
	 * Used to convert projects_v2_item.content_node_id → actionable issue ref.
	 *
	 * @return array{full_name:string,issue_number:int}|\WP_Error
	 */
	private static function resolve_node_id( string $node_id ) {
		$token = get_option( WPTE_DZ_GITHUB_OPTION_TOKEN, '' );
		if ( ! $token ) {
			return new \WP_Error( 'no_token', 'No GitHub token configured.' );
		}

		$query = 'query($id:ID!){node(id:$id){...on Issue{number repository{nameWithOwner}}}}';

		$response = wp_remote_post( 'https://api.github.com/graphql', [
			'headers' => [
				'Authorization' => "Bearer {$token}",
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'WordPress/WPTE-DZ-Github',
			],
			'body'    => wp_json_encode( [ 'query' => $query, 'variables' => [ 'id' => $node_id ] ] ),
			'timeout' => 20,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$issue = $body['data']['node'] ?? null;

		if ( empty( $issue['number'] ) || empty( $issue['repository']['nameWithOwner'] ) ) {
			return new \WP_Error( 'resolve_failed', 'Could not resolve issue from node ID: ' . esc_html( $node_id ) );
		}

		return [
			'full_name'    => $issue['repository']['nameWithOwner'],
			'issue_number' => (int) $issue['number'],
		];
	}

	private static function issue_summary( array $issue ): array {
		return [
			'number'    => $issue['number'],
			'title'     => $issue['title'],
			'full_name' => $issue['full_name'],
			'html_url'  => $issue['html_url'],
		];
	}

	/**
	 * Append one download record to the persistent log (capped at MAX_LOG_SIZE).
	 */
	private static function log_download( array $issue_summary, array $install_entry ): void {
		$log   = get_option( WPTE_DZ_GITHUB_OPTION_DOWNLOAD_LOG, [] );
		$log[] = [
			'timestamp'   => time(),
			'issue'       => $issue_summary,
			'pr'          => $install_entry['pr'],
			'pr_repo'     => $install_entry['pr_repo'],
			'tag'         => $install_entry['tag'],
			'zip_url'     => $install_entry['zip_url'],
			'plugin_name' => $install_entry['plugin_name'] ?? '',
			'plugin_file' => $install_entry['plugin_file'] ?? '',
			'slug'        => $install_entry['slug'] ?? '',
			'action'      => $install_entry['action'] ?? 'installed',
			'activated'   => $install_entry['activated'] ?? false,
		];

		if ( count( $log ) > self::MAX_LOG_SIZE ) {
			$log = array_slice( $log, -self::MAX_LOG_SIZE );
		}

		update_option( WPTE_DZ_GITHUB_OPTION_DOWNLOAD_LOG, $log, false );
		update_option( WPTE_DZ_GITHUB_OPTION_LAST_DL_TS, time(), false );
	}
}
