<?php

namespace WPTEDZGithub;

defined( 'ABSPATH' ) || exit;

class GithubRestController {

	private const NAMESPACE    = 'github/v1';
	private const ROUTE        = '/webhook';
	private const MAX_LOG_SIZE = 100;

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
		// Uses INSERT IGNORE so the lock is acquired atomically — no race between
		// concurrent requests both reading "not set" before either writes.
		$delivery_id = $request->get_header( 'X-GitHub-Delivery' );
		if ( $delivery_id ) {
			if ( ! preg_match( '/^[0-9a-f\-]{36,72}$/i', $delivery_id ) ) {
				return new \WP_Error( 'invalid_delivery_id', 'Invalid X-GitHub-Delivery header.', [ 'status' => 400 ] );
			}
			global $wpdb;
			$lock_key = 'wpte_dz_gh_dlck_' . $delivery_id;

			// Prune expired locks (amortised cleanup, no separate cron needed).
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
					'wpte_dz_gh_dlck_%',
					time() - DAY_IN_SECONDS
				)
			);

			$acquired = (bool) $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					$lock_key,
					(string) time()
				)
			);
			if ( ! $acquired ) {
				return new \WP_Error( 'replayed_delivery', 'Delivery already processed.', [ 'status' => 409 ] );
			}
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

		// Track repos already handled this call — multiple PRs from the same repo
		// must not trigger duplicate installs.
		$processed_repos = [];

		foreach ( $prs as $pr ) {
			$pr_repo   = $pr['head_repo'] ?? $full_name;
			$pr_number = (int) $pr['number'];

			if ( isset( $processed_repos[ $pr_repo ] ) ) {
				continue;
			}

			// Skip repos whose owner is not trusted.
			$pr_owner = strtolower( explode( '/', $pr_repo )[0] ?? '' );
			if ( $trusted_owners && ! in_array( $pr_owner, $trusted_owners, true ) ) {
				$err      = [ 'pr' => $pr_number, 'pr_repo' => $pr_repo, 'message' => 'Repo owner not in trusted allowlist.' ];
				$errors[] = $err;
				self::log_error( $issue_summary, $err );
				continue;
			}

			$head_ref = $pr['head_ref'] ?? '';
			$base_ref = $pr['base_ref'] ?? '';

			// Tag pattern v{N}.{N}.{N}-{branch}.{N} uses head_ref (source branch).
			$latest = $head_ref
				? GithubApi::get_latest_release_for_branch( $pr_repo, $head_ref )
				: GithubApi::get_releases( $pr_repo );

			if ( is_wp_error( $latest ) ) {
				continue;
			}

			// get_releases fallback returns an array — take first element.
			if ( is_array( $latest ) && isset( $latest[0] ) ) {
				$latest = $latest[0];
			}

			if ( empty( $latest ) ) {
				continue;
			}
			$tag     = $latest['tag'];
			$zip_url = $latest['zip_url'] ?? '';

			$processed_repos[ $pr_repo ] = true;

			if ( ! $zip_url ) {
				$err      = [ 'pr' => $pr_number, 'pr_repo' => $pr_repo, 'tag' => $tag, 'message' => 'Release has no zip URL.' ];
				$errors[] = $err;
				self::log_error( $issue_summary, $err );
				continue;
			}

			// Skip if this (repo, tag) was installed in the last 5 minutes —
			// guards against rapid duplicate fires with different delivery IDs.
			if ( self::recently_installed( $pr_repo, $tag ) ) {
				continue;
			}

			$result = GithubInstaller::install_from_url( $zip_url, $pr_repo );
			if ( is_wp_error( $result ) ) {
				$err      = [ 'pr' => $pr_number, 'pr_repo' => $pr_repo, 'tag' => $tag, 'message' => $result->get_error_message() ];
				$errors[] = $err;
				self::log_error( $issue_summary, $err );
			} else {
				$plugin_file  = $result['plugin_file'] ?? '';
				$activated    = false;
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
					'pr'        => $pr_number,
					'pr_repo'   => $pr_repo,
					'tag'       => $tag,
					'zip_url'   => $zip_url,
					'activated' => $activated,
				] );

				if ( $activate_err ) {
					$entry['activate_error'] = $activate_err;
				}

				$installed[] = $entry;
				self::log_download( $issue_summary, $entry );
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
	 * Return true if (repo, tag) appears in the log within the last $window seconds.
	 * Prevents duplicate installs from rapid-fire webhook deliveries with distinct UUIDs.
	 */
	private static function recently_installed( string $pr_repo, string $tag, int $window = 300 ): bool {
		$log       = get_option( WPTE_DZ_GITHUB_OPTION_DOWNLOAD_LOG, [] );
		$threshold = time() - $window;
		foreach ( $log as $entry ) {
			if (
				( $entry['pr_repo']   ?? '' ) === $pr_repo &&
				( $entry['tag']       ?? '' ) === $tag &&
				( $entry['timestamp'] ?? 0  ) >= $threshold &&
				( $entry['status']    ?? 'ok' ) !== 'failed'
			) {
				return true;
			}
		}
		return false;
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

	private static function log_error( array $issue_summary, array $error_entry ): void {
		$log   = get_option( WPTE_DZ_GITHUB_OPTION_DOWNLOAD_LOG, [] );
		$log[] = [
			'timestamp' => time(),
			'status'    => 'failed',
			'issue'     => $issue_summary,
			'pr'        => $error_entry['pr']      ?? 0,
			'pr_repo'   => $error_entry['pr_repo'] ?? '',
			'tag'       => $error_entry['tag']     ?? '',
			'message'   => $error_entry['message'] ?? '',
		];

		if ( count( $log ) > self::MAX_LOG_SIZE ) {
			$log = array_slice( $log, -self::MAX_LOG_SIZE );
		}

		update_option( WPTE_DZ_GITHUB_OPTION_DOWNLOAD_LOG, $log, false );
	}
}
