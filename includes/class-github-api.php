<?php

namespace WPTEDZGithub;

defined( 'ABSPATH' ) || exit;

class GithubApi {

	private const API_BASE   = 'https://api.github.com';
	private const USER_AGENT = 'WordPress/WPTE-DZ-Github';

	// ── Public API ───────────────────────────────────────────────────────────

	/**
	 * Generate a short hash of the stored token for use in transient keys.
	 */
	public static function token_hash( string $token = '' ): string {
		if ( ! $token ) {
			$token = get_option( WPTE_DZ_GITHUB_OPTION_TOKEN, '' );
		}
		return substr( md5( $token ), 0, 12 );
	}

	/**
	 * Validate a PAT and return basic user info.
	 *
	 * @return array{login:string,name:string,avatar_url:string}|\WP_Error
	 */
	public static function validate_token( string $token ) {
		$response = wp_remote_get( self::API_BASE . '/user', [
			'headers' => [
				'Authorization' => "Bearer {$token}",
				'Accept'        => 'application/vnd.github+json',
				'User-Agent'    => self::USER_AGENT,
			],
			'timeout' => 10,
		] );

		$body = self::parse_response( $response, 'auth_failed' );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		return [
			'login'      => $body['login'],
			'name'       => $body['name'] ?? $body['login'],
			'avatar_url' => $body['avatar_url'] ?? '',
		];
	}

	/**
	 * Fetch all repos (personal + all orgs), deduped, sorted by updated_at desc.
	 *
	 * @return list<array>|\WP_Error
	 */
	public static function get_all_repos() {
		$personal = self::paginate( '/user/repos?type=owner&sort=updated&per_page=100' );
		if ( is_wp_error( $personal ) ) {
			return $personal;
		}
		$repos = $personal;

		$orgs = self::get( '/user/orgs?per_page=100' );
		if ( ! is_wp_error( $orgs ) ) {
			foreach ( $orgs as $org ) {
				$org_repos = self::paginate( "/orgs/{$org['login']}/repos?type=all&sort=updated&per_page=100" );
				if ( ! is_wp_error( $org_repos ) ) {
					$repos = array_merge( $repos, $org_repos );
				}
			}
		}

		$seen   = [];
		$result = [];
		foreach ( $repos as $r ) {
			if ( isset( $seen[ $r['id'] ] ) ) {
				continue;
			}
			$seen[ $r['id'] ] = true;
			$result[]         = [
				'id'          => $r['id'],
				'full_name'   => $r['full_name'],
				'name'        => $r['name'],
				'owner'       => $r['owner']['login'],
				'private'     => $r['private'],
				'description' => $r['description'] ?? '',
				'updated_at'  => $r['updated_at'] ?? '',
				'html_url'    => $r['html_url'] ?? '',
				'language'    => $r['language'] ?? '',
				'stars'       => (int) ( $r['stargazers_count'] ?? 0 ),
				'forks'       => (int) ( $r['forks_count'] ?? 0 ),
			];
		}

		usort( $result, static fn( $a, $b ) => strcmp( $b['updated_at'], $a['updated_at'] ) );

		return $result;
	}

	/**
	 * Get releases for a repository (drafts excluded).
	 *
	 * @return list<array>|\WP_Error
	 */
	public static function get_releases( string $full_name ) {
		$data = self::paginate( "/repos/{$full_name}/releases?per_page=100", 10 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$result = [];
		foreach ( $data as $r ) {
			if ( $r['draft'] ) {
				continue;
			}
			$result[] = [
				'id'         => $r['id'],
				'tag'        => $r['tag_name'],
				'name'       => $r['name'] ?: $r['tag_name'],
				'body'       => $r['body'] ?? '',
				'prerelease' => $r['prerelease'],
				'draft'      => $r['draft'],
				'published'  => $r['published_at'] ?? '',
				'zip_url'    => self::best_zip( $r, $full_name ),
				'html_url'   => $r['html_url'] ?? '',
			];
		}

		return $result;
	}

	/**
	 * Return the login names of all orgs the authenticated user belongs to.
	 * Result is cached in a transient for 1 hour.
	 *
	 * @return string[]|\WP_Error
	 */
	public static function get_user_orgs() {
		$cache_key = 'wpte_dz_gh_orgs_' . self::token_hash();
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return $cached;
		}

		$orgs = self::get( '/user/orgs?per_page=100' );
		if ( is_wp_error( $orgs ) ) {
			return $orgs;
		}

		$logins = array_column( $orgs, 'login' );
		set_transient( $cache_key, $logins, HOUR_IN_SECONDS );
		return $logins;
	}

	/**
	 * Search GitHub issues scoped to the PAT user's own organisations.
	 *
	 * @return list<array>|\WP_Error
	 */
	public static function search_issues( string $query ) {
		$orgs = self::get_user_orgs();
		if ( is_wp_error( $orgs ) ) {
			return $orgs;
		}

		// Build org: qualifiers so results are restricted to the user's orgs.
		$org_scope = '';
		foreach ( $orgs as $login ) {
			$org_scope .= ' org:' . $login;
		}

		$q    = rawurlencode( $query . ' type:issue in:title,body' . $org_scope );
		$data = self::get( '/search/issues?q=' . $q . '&per_page=20&sort=updated' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return array_map( function ( $i ) {
			return [
				'number'       => (int) $i['number'],
				'title'        => $i['title'] ?? '',
				'body_excerpt' => mb_substr( strip_tags( $i['body'] ?? '' ), 0, 160 ),
				'state'        => $i['state'] ?? 'open',
				'html_url'     => $i['html_url'] ?? '',
				'full_name'    => ltrim( str_replace( 'https://api.github.com/repos', '', $i['repository_url'] ?? '' ), '/' ),
				'author'       => $i['user']['login'] ?? '',
				'updated_at'   => $i['updated_at'] ?? '',
				'comments'     => (int) ( $i['comments'] ?? 0 ),
			];
		}, $data['items'] ?? [] );
	}

	/**
	 * Parse a GitHub issue URL (standard or project board) into repo + issue number.
	 *
	 * Supported formats:
	 *   • https://github.com/org/repo/issues/123
	 *   • https://github.com/orgs/org/projects/N/...?...&issue=org|repo|123
	 *
	 * @return array{full_name:string,issue_number:int}|null  null if the URL is not recognised.
	 */
	public static function parse_issue_url( string $url ): ?array {
		// Standard issue URL.
		if ( preg_match( '#^https?://github\.com/([^/]+/[^/]+)/issues/(\d+)#i', $url, $m ) ) {
			return [ 'full_name' => $m[1], 'issue_number' => (int) $m[2] ];
		}

		// GitHub Projects URL: ?...&issue=Org%7Crepo%7C123  (%7C = pipe, may also arrive as literal |)
		// Use a targeted regex instead of parse_str to avoid misparse of complex filterQuery values.
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( $query && preg_match( '/(?:^|&)issue=([^&]+)/i', $query, $qm ) ) {
			// urldecode handles both %7C and literal | cases.
			$raw    = urldecode( $qm[1] );
			$parts  = explode( '|', $raw );
			if ( count( $parts ) === 3 && ctype_digit( $parts[2] ) && $parts[0] && $parts[1] ) {
				return [
					'full_name'    => $parts[0] . '/' . $parts[1],
					'issue_number' => (int) $parts[2],
				];
			}
		}

		return null;
	}

	/**
	 * Fetch a single issue by repo and number.
	 *
	 * @return array|\WP_Error
	 */
	public static function get_issue( string $full_name, int $issue_number ) {
		$data = self::get( "/repos/{$full_name}/issues/{$issue_number}" );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// Pull requests are also returned by the issues endpoint; exclude them.
		if ( ! empty( $data['pull_request'] ) ) {
			return new \WP_Error( 'not_an_issue', 'That URL points to a pull request, not an issue.' );
		}

		return [
			'number'       => (int) $data['number'],
			'title'        => $data['title'] ?? '',
			'body_excerpt' => mb_substr( strip_tags( $data['body'] ?? '' ), 0, 160 ),
			'state'        => $data['state'] ?? 'open',
			'html_url'     => $data['html_url'] ?? '',
			'full_name'    => $full_name,
			'author'       => $data['user']['login'] ?? '',
			'updated_at'   => $data['updated_at'] ?? '',
			'comments'     => (int) ( $data['comments'] ?? 0 ),
		];
	}

	/**
	 * Get pull requests linked to an issue via the GitHub GraphQL API.
	 *
	 * Queries both CrossReferencedEvent (PR body/title mentions the issue) and
	 * ConnectedEvent (PR linked via the Development sidebar) in a single request.
	 * Returns all PR fields in one call — no secondary per-PR lookups needed.
	 *
	 * @return list<array>|\WP_Error
	 */
	public static function get_issue_prs( string $full_name, int $issue_number ) {
		[ $owner, $repo ] = array_pad( explode( '/', $full_name, 2 ), 2, '' );

		$query = 'query($owner:String!,$repo:String!,$number:Int!){
			repository(owner:$owner,name:$repo){
				issue(number:$number){
					timelineItems(first:50,itemTypes:[CROSS_REFERENCED_EVENT,CONNECTED_EVENT]){
						nodes{
							__typename
							...on CrossReferencedEvent{
								source{__typename ...on PullRequest{
									number title state merged headRefName baseRefName
									headRepository{nameWithOwner} url
								}}
							}
							...on ConnectedEvent{
								subject{__typename ...on PullRequest{
									number title state merged headRefName baseRefName
									headRepository{nameWithOwner} url
								}}
							}
						}
					}
				}
			}
		}';

		$data = self::graphql( $query, [
			'owner'  => $owner,
			'repo'   => $repo,
			'number' => $issue_number,
		] );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$nodes = $data['repository']['issue']['timelineItems']['nodes'] ?? [];
		$prs   = [];
		$seen  = [];

		foreach ( $nodes as $node ) {
			$type = $node['__typename'] ?? '';

			if ( $type === 'CrossReferencedEvent' ) {
				$pr = $node['source'] ?? [];
			} elseif ( $type === 'ConnectedEvent' ) {
				$pr = $node['subject'] ?? [];
			} else {
				continue;
			}

			if ( ( $pr['__typename'] ?? '' ) !== 'PullRequest' ) {
				continue;
			}

			$pr_num  = (int) ( $pr['number'] ?? 0 );
			$pr_repo = $pr['headRepository']['nameWithOwner'] ?? $full_name;
			$key     = $pr_repo . '#' . $pr_num;

			if ( ! $pr_num || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			if ( count( $prs ) >= 5 ) {
				break;
			}

			// GraphQL state enum: OPEN | CLOSED | MERGED.
			$gql_state = $pr['state'] ?? 'OPEN';
			$prs[] = [
				'number'    => $pr_num,
				'title'     => $pr['title'] ?? '',
				'state'     => $gql_state === 'OPEN' ? 'open' : 'closed',
				'merged'    => ! empty( $pr['merged'] ),
				'head_ref'  => $pr['headRefName'] ?? '',
				'base_ref'  => $pr['baseRefName'] ?? '',
				'head_repo' => $pr_repo,
				'html_url'  => $pr['url'] ?? '',
			];
		}

		return $prs;
	}

	/**
	 * Return the names of all tags whose commit belongs to a specific PR.
	 *
	 * Fetches the PR's commits via GraphQL (up to 250) and all repo tags via
	 * REST, then intersects by commit SHA. Works even after the branch is deleted.
	 *
	 * @return list<string>|\WP_Error
	 */
	public static function get_tags_for_pr( string $full_name, int $pr_number ) {
		[ $owner, $repo ] = array_pad( explode( '/', $full_name, 2 ), 2, '' );

		$query = 'query($owner:String!,$repo:String!,$number:Int!){
			repository(owner:$owner,name:$repo){
				pullRequest(number:$number){
					commits(first:250){
						nodes{ commit{ oid } }
					}
				}
			}
		}';

		$data = self::graphql( $query, [
			'owner'  => $owner,
			'repo'   => $repo,
			'number' => $pr_number,
		] );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$commit_nodes = $data['repository']['pullRequest']['commits']['nodes'] ?? [];
		if ( empty( $commit_nodes ) ) {
			return [];
		}

		$sha_set = [];
		foreach ( $commit_nodes as $node ) {
			$oid = $node['commit']['oid'] ?? '';
			if ( $oid ) {
				$sha_set[ $oid ] = true;
			}
		}

		$tags = self::paginate( "/repos/{$full_name}/tags?per_page=100", 5 );
		if ( is_wp_error( $tags ) ) {
			return $tags;
		}

		$result = [];
		foreach ( $tags as $tag ) {
			$sha = $tag['commit']['sha'] ?? '';
			if ( $sha && isset( $sha_set[ $sha ] ) ) {
				$result[] = $tag['name'];
			}
		}

		return $result;
	}

	/**
	 * Download a release zip to a local temp file.
	 *
	 * For private repos GitHub redirects the API URL to a pre-signed S3 URL.
	 * S3 rejects Authorization headers, so we resolve the redirect with auth
	 * first, then download the S3 URL without auth.
	 *
	 * @return string|\WP_Error  Absolute path to a local .zip file.
	 */
	public static function download_zip( string $url ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$token             = get_option( WPTE_DZ_GITHUB_OPTION_TOKEN, '' );
		$is_github_api_url = (bool) preg_match( '#^https://api\.github\.com/#', $url );

		if ( $is_github_api_url && $token ) {
			$resolved = self::resolve_redirect( $url, $token );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}

			if ( strpos( $resolved, 'local:' ) === 0 ) {
				return substr( $resolved, 6 );
			}

			$url = $resolved;
		}

		$tmp = download_url( $url, 300 );

		if ( is_wp_error( $tmp ) ) {
			return new \WP_Error( 'download_failed', 'Download failed: ' . $tmp->get_error_message() );
		}

		$zip_tmp = $tmp . '.zip';
		rename( $tmp, $zip_tmp );

		return $zip_tmp;
	}

	// ── Internals ────────────────────────────────────────────────────────────

	/**
	 * Parse a wp_remote_* response into a decoded body array or WP_Error.
	 *
	 * @param array|\WP_Error $response  Raw response from wp_remote_get/post.
	 * @param string          $error_code Error code for the WP_Error on HTTP failure.
	 * @return array|\WP_Error
	 */
	private static function parse_response( $response, string $error_code = 'api_error' ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			return new \WP_Error(
				$error_code,
				isset( $body['message'] ) ? $body['message'] : "HTTP {$code}"
			);
		}

		return is_array( $body ) ? $body : [];
	}

	/**
	 * Build the common Authorization + User-Agent headers.
	 *
	 * @return array<string, string>
	 */
	private static function auth_headers( string $accept = 'application/vnd.github+json' ): array {
		$headers = [
			'Accept'     => $accept,
			'User-Agent' => self::USER_AGENT,
		];

		$token = get_option( WPTE_DZ_GITHUB_OPTION_TOKEN, '' );
		if ( $token ) {
			$headers['Authorization'] = "Bearer {$token}";
		}

		return $headers;
	}

	/**
	 * Execute a GitHub GraphQL query.
	 *
	 * @return array|\WP_Error  Parsed `data` object on success.
	 */
	private static function graphql( string $query, array $variables = [] ) {
		$token = get_option( WPTE_DZ_GITHUB_OPTION_TOKEN, '' );
		if ( ! $token ) {
			return new \WP_Error( 'no_token', 'No GitHub token configured.' );
		}

		$response = wp_remote_post( self::API_BASE . '/graphql', [
			'headers' => [
				'Authorization' => "Bearer {$token}",
				'Content-Type'  => 'application/json',
				'User-Agent'    => self::USER_AGENT,
			],
			'body'    => wp_json_encode( [ 'query' => $query, 'variables' => $variables ] ),
			'timeout' => 20,
		] );

		$body = self::parse_response( $response, 'graphql_http' );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		if ( ! empty( $body['errors'] ) ) {
			return new \WP_Error( 'graphql_error', $body['errors'][0]['message'] ?? 'GraphQL error.' );
		}

		return $body['data'] ?? [];
	}

	/**
	 * @return array|\WP_Error
	 */
	private static function get( string $path ) {
		$response = wp_remote_get( self::API_BASE . $path, [
			'headers' => self::auth_headers(),
			'timeout' => 20,
		] );

		return self::parse_response( $response );
	}

	/**
	 * @return list<array>|\WP_Error
	 */
	private static function paginate( string $path, int $max_pages = 5 ) {
		$results = [];
		$page    = 1;

		while ( $page <= $max_pages ) {
			$sep  = ( strpos( $path, '?' ) !== false ) ? '&' : '?';
			$data = self::get( $path . $sep . 'page=' . $page );

			if ( is_wp_error( $data ) ) {
				return $data;
			}
			if ( empty( $data ) ) {
				break;
			}

			$results = array_merge( $results, $data );

			if ( count( $data ) < 100 ) {
				break;
			}
			$page++;
		}

		return $results;
	}

	/**
	 * Follow a GitHub API redirect and return the final URL.
	 * If GitHub responds 200 directly (body is the zip), saves to temp and returns "local:{path}".
	 *
	 * @return string|\WP_Error
	 */
	private static function resolve_redirect( string $url, string $token ) {
		$response = wp_remote_get( $url, [
			'timeout'     => 30,
			'redirection' => 0,
			'headers'     => [
				'Authorization' => "Bearer {$token}",
				'Accept'        => 'application/octet-stream',
				'User-Agent'    => self::USER_AGENT,
			],
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code === 301 || $code === 302 ) {
			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( $location ) {
				return $location;
			}
			return new \WP_Error( 'no_redirect', 'GitHub redirect had no Location header.' );
		}

		if ( $code === 200 ) {
			$body = wp_remote_retrieve_body( $response );
			if ( empty( $body ) ) {
				return new \WP_Error( 'empty_body', 'GitHub returned empty body.' );
			}
			$tmp = wp_tempnam( 'wpte-dz-gh' ) . '.zip';
			file_put_contents( $tmp, $body );
			return 'local:' . $tmp;
		}

		return new \WP_Error( 'resolve_failed', "GitHub returned HTTP {$code} for asset URL." );
	}

	/**
	 * Select the best zip URL from a release — prefers an explicit .zip asset,
	 * falls back to the auto-generated zipball.
	 */
	private static function best_zip( array $release, string $full_name ): string {
		foreach ( $release['assets'] ?? [] as $asset ) {
			if ( substr( strtolower( $asset['name'] ), -4 ) === '.zip' ) {
				return $asset['url'];
			}
		}
		return $release['zipball_url'] ?? '';
	}
}
