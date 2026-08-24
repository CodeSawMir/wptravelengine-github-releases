<?php

namespace WPTEDZGithub;

use WPTravelEngineDevZone\Admin;
use WPTravelEngineDevZone\Tools\AbstractTool;

defined( 'ABSPATH' ) || exit;

class GithubTool extends AbstractTool {

	public function get_slug(): string     { return 'github'; }
	public function get_label(): string    { return __( 'GitHub', 'wpte-devzone-github' ); }
	public function get_template(): string { return WPTE_DZ_GITHUB_DIR . 'templates/tab-github.php'; }

	// ── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Read and sanitize a POST parameter.
	 */
	private function post_param( string $key, bool $trim = false ): string {
		$value = sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) );
		return $trim ? trim( $value ) : $value;
	}

	/**
	 * Send an API result as JSON, handling WP_Error automatically.
	 *
	 * @param array|\WP_Error $result  The API result.
	 * @param string          $key     The key to use in the success payload.
	 */
	private function send_result( $result, string $key ): void {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
		wp_send_json_success( [ $key => $result ] );
	}

	// ── Asset enqueue ────────────────────────────────────────────────────────

	public function enqueue_assets(): void {
		wp_enqueue_style(
			'wpte-dz-github',
			WPTE_DZ_GITHUB_URL . 'assets/github.css',
			[ 'wpte-devzone' ],
			WPTE_DZ_GITHUB_VERSION
		);

		wp_enqueue_script(
			'wpte-dz-github',
			WPTE_DZ_GITHUB_URL . 'assets/github.js',
			[],
			WPTE_DZ_GITHUB_VERSION,
			true
		);

		add_filter(
			'script_loader_tag',
			static function ( string $tag, string $handle ): string {
				if ( 'wpte-dz-github' === $handle ) {
					return str_replace( ' src=', ' type="module" src=', $tag );
				}
				return $tag;
			},
			10,
			2
		);

		$token     = get_option( WPTE_DZ_GITHUB_OPTION_TOKEN, '' );
		$user      = $token ? get_option( 'wpte_dz_github_user', [] ) : [];
		$has_token = ! empty( $token );

		// nonce is intentionally omitted — JS reads wpteDbg.nonce directly.
		$cache_key = 'wpte_dz_gh_repos_' . GithubApi::token_hash( $token );
		wp_localize_script( 'wpte-dz-github', 'WPTEDZGithub', [
			'ajax_url'         => admin_url( 'admin-ajax.php' ),
			'admin_url'        => admin_url(),
			'has_token'        => $has_token,
			'user'             => $user ?: (object) [],
			'has_cache'        => $has_token && (bool) get_transient( $cache_key ),
			'last_download_ts' => (int) get_option( WPTE_DZ_GITHUB_OPTION_LAST_DL_TS, 0 ),
			'webhook_url'      => get_rest_url( null, 'github/v1/webhook' ),
			'auto_install'     => get_option( WPTE_DZ_GITHUB_OPTION_AUTO_INSTALL, 'no' ) === 'yes',
			'favorites'        => array_values( (array) get_option( WPTE_DZ_GITHUB_OPTION_FAVORITES, [] ) ),
			'last_installed'   => (object) get_option( WPTE_DZ_GITHUB_OPTION_LAST_INSTALLED, [] ),
		] );
	}

	// ── AJAX registration ────────────────────────────────────────────────────

	public function register_ajax(): void {
		add_action( 'wp_ajax_wpte_dz_gh_validate',           [ $this, 'ajax_validate' ] );
		add_action( 'wp_ajax_wpte_dz_gh_save_token',        [ $this, 'ajax_save_token' ] );
		add_action( 'wp_ajax_wpte_dz_gh_disconnect',        [ $this, 'ajax_disconnect' ] );
		add_action( 'wp_ajax_wpte_dz_gh_fetch_repos',       [ $this, 'ajax_fetch_repos' ] );
		add_action( 'wp_ajax_wpte_dz_gh_get_releases',      [ $this, 'ajax_get_releases' ] );
		add_action( 'wp_ajax_wpte_dz_gh_install',           [ $this, 'ajax_install' ] );
		add_action( 'wp_ajax_wpte_dz_gh_activate',          [ $this, 'ajax_activate' ] );
		add_action( 'wp_ajax_wpte_dz_gh_installed_versions', [ $this, 'ajax_installed_versions' ] );
		add_action( 'wp_ajax_wpte_dz_gh_search_issues',     [ $this, 'ajax_search_issues' ] );
		add_action( 'wp_ajax_wpte_dz_gh_get_issue',         [ $this, 'ajax_get_issue' ] );
		add_action( 'wp_ajax_wpte_dz_gh_get_issue_prs',     [ $this, 'ajax_get_issue_prs' ] );
		add_action( 'wp_ajax_wpte_dz_gh_get_branch_tags',   [ $this, 'ajax_get_branch_tags' ] );
		add_action( 'wp_ajax_wpte_dz_gh_get_issue_by_url',  [ $this, 'ajax_get_issue_by_url' ] );
		add_action( 'wp_ajax_wpte_dz_gh_get_download_log',  [ $this, 'ajax_get_download_log' ] );
		add_action( 'wp_ajax_wpte_dz_gh_set_auto_install',  [ $this, 'ajax_set_auto_install' ] );
		add_action( 'wp_ajax_wpte_dz_gh_clear_log',         [ $this, 'ajax_clear_log' ] );
		add_action( 'wp_ajax_wpte_dz_gh_get_favorites',     [ $this, 'ajax_get_favorites' ] );
		add_action( 'wp_ajax_wpte_dz_gh_save_favorites',    [ $this, 'ajax_save_favorites' ] );
	}

	// ── AJAX handlers ────────────────────────────────────────────────────────

	/**
	 * Validates the currently stored token and returns the user object.
	 * Used by JS on boot to confirm the saved PAT is still valid before rendering the app.
	 */
	public function ajax_validate(): void {
		Admin::verify_request();

		$token = get_option( WPTE_DZ_GITHUB_OPTION_TOKEN, '' );
		if ( ! $token ) {
			wp_send_json_error( [ 'message' => __( 'No token stored.', 'wpte-devzone-github' ) ] );
		}

		// Return cached validation for 30 minutes to avoid a GitHub round-trip on every boot.
		$cache_key = 'wpte_dz_gh_user_' . GithubApi::token_hash( $token );
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			wp_send_json_success( [ 'user' => $cached ] );
		}

		$user = GithubApi::validate_token( $token );
		if ( is_wp_error( $user ) ) {
			// Token is no longer valid — clean up.
			delete_option( WPTE_DZ_GITHUB_OPTION_TOKEN );
			delete_option( 'wpte_dz_github_user' );
			wp_send_json_error( [ 'message' => $user->get_error_message() ] );
		}

		set_transient( $cache_key, $user, 30 * MINUTE_IN_SECONDS );
		update_option( 'wpte_dz_github_user', $user );
		wp_send_json_success( [ 'user' => $user ] );
	}

	public function ajax_save_token(): void {
		Admin::verify_request();

		$token = $this->post_param( 'token', true );
		if ( ! $token ) {
			wp_send_json_error( [ 'message' => __( 'Token is empty.', 'wpte-devzone-github' ) ] );
		}

		$user = GithubApi::validate_token( $token );
		if ( is_wp_error( $user ) ) {
			wp_send_json_error( [ 'message' => $user->get_error_message() ] );
		}

		update_option( WPTE_DZ_GITHUB_OPTION_TOKEN, $token );
		update_option( 'wpte_dz_github_user', $user );

		wp_send_json_success( [ 'user' => $user ] );
	}

	public function ajax_disconnect(): void {
		Admin::verify_request();

		$token = get_option( WPTE_DZ_GITHUB_OPTION_TOKEN, '' );
		if ( $token ) {
			$hash = GithubApi::token_hash( $token );
			delete_transient( 'wpte_dz_gh_user_'  . $hash );
			delete_transient( 'wpte_dz_gh_repos_' . $hash );
			delete_transient( 'wpte_dz_gh_orgs_'  . $hash );
		}

		delete_option( WPTE_DZ_GITHUB_OPTION_TOKEN );
		delete_option( 'wpte_dz_github_user' );
		wp_send_json_success( [] );
	}

	public function ajax_fetch_repos(): void {
		Admin::verify_request();

		$cache_key = 'wpte_dz_gh_repos_' . GithubApi::token_hash();
		$force     = ! empty( $_POST['force'] );

		if ( $force ) {
			delete_transient( $cache_key );
		} else {
			$cached = get_transient( $cache_key );
			if ( $cached !== false ) {
				wp_send_json_success( [ 'repos' => $cached, 'cached' => true ] );
			}
		}

		$repos = GithubApi::get_all_repos();
		if ( is_wp_error( $repos ) ) {
			wp_send_json_error( [ 'message' => $repos->get_error_message() ] );
		}

		set_transient( $cache_key, $repos, DAY_IN_SECONDS );
		wp_send_json_success( [ 'repos' => $repos, 'cached' => false ] );
	}

	public function ajax_get_releases(): void {
		Admin::verify_request();

		$full_name = $this->post_param( 'full_name' );
		if ( ! $full_name ) {
			wp_send_json_error( [ 'message' => __( 'No repo specified.', 'wpte-devzone-github' ) ] );
		}

		$this->send_result( GithubApi::get_releases( $full_name ), 'releases' );
	}

	/**
	 * @since next records the installed tag per repo in WPTE_DZ_GITHUB_OPTION_LAST_INSTALLED.
	 */
	public function ajax_install(): void {
		Admin::verify_request();

		$zip_url   = esc_url_raw( wp_unslash( $_POST['zip_url'] ?? '' ) );
		$repo_name = $this->post_param( 'repo_name' );
		$full_name = $this->post_param( 'full_name' );
		$tag       = $this->post_param( 'tag' );

		if ( ! $zip_url ) {
			wp_send_json_error( [ 'message' => __( 'No zip URL.', 'wpte-devzone-github' ) ] );
		}
		if ( ! $repo_name ) {
			wp_send_json_error( [ 'message' => __( 'No repo name.', 'wpte-devzone-github' ) ] );
		}

		@set_time_limit( 300 );

		$result = GithubInstaller::install_from_url( $zip_url, $repo_name );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

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

		$result['activated'] = $activated;
		if ( $activate_err ) {
			$result['activate_error'] = $activate_err;
		}

		if ( $full_name && $tag ) {
			$last_installed = (array) get_option( WPTE_DZ_GITHUB_OPTION_LAST_INSTALLED, [] );
			$entry           = [ 'tag' => $tag, 'installed_at' => time() ];
			$last_installed[ $full_name ] = $entry;
			update_option( WPTE_DZ_GITHUB_OPTION_LAST_INSTALLED, $last_installed );
			$result['last_installed'] = $entry;
		}

		wp_send_json_success( $result );
	}

	public function ajax_activate(): void {
		Admin::verify_request();

		$plugin_file = $this->post_param( 'plugin_file' );
		if ( ! $plugin_file ) {
			wp_send_json_error( [ 'message' => __( 'No plugin file specified.', 'wpte-devzone-github' ) ] );
		}

		if ( ! preg_match( '/^[a-zA-Z0-9_\-\.]+\/[a-zA-Z0-9_\-\.]+\.php$/', $plugin_file ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid plugin file path.', 'wpte-devzone-github' ) ] );
		}

		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
			wp_send_json_error( [ 'message' => __( 'Plugin file not found.', 'wpte-devzone-github' ) ] );
		}

		$result = activate_plugin( $plugin_file );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( [ 'plugin_file' => $plugin_file ] );
	}

	public function ajax_search_issues(): void {
		Admin::verify_request();

		$query = $this->post_param( 'query', true );
		if ( ! $query ) {
			wp_send_json_error( [ 'message' => __( 'No query.', 'wpte-devzone-github' ) ] );
		}

		$this->send_result( GithubApi::search_issues( $query ), 'issues' );
	}

	public function ajax_get_issue(): void {
		Admin::verify_request();

		$full_name    = $this->post_param( 'full_name' );
		$issue_number = absint( $_POST['issue_number'] ?? 0 );

		if ( ! $full_name || ! $issue_number ) {
			wp_send_json_error( [ 'message' => __( 'Missing parameters.', 'wpte-devzone-github' ) ] );
		}

		$this->send_result( GithubApi::get_issue( $full_name, $issue_number ), 'issue' );
	}

	public function ajax_get_issue_prs(): void {
		Admin::verify_request();

		$full_name    = $this->post_param( 'full_name' );
		$issue_number = absint( $_POST['issue_number'] ?? 0 );

		if ( ! $full_name || ! $issue_number ) {
			wp_send_json_error( [ 'message' => __( 'Missing parameters.', 'wpte-devzone-github' ) ] );
		}

		$this->send_result( GithubApi::get_issue_prs( $full_name, $issue_number ), 'prs' );
	}

	/**
	 * Accepts a raw GitHub URL (issue URL or project board URL), parses it
	 * server-side, and returns the issue data — same payload as ajax_get_issue.
	 */
	public function ajax_get_issue_by_url(): void {
		Admin::verify_request();

		// Use wp_unslash only — sanitize_text_field corrupts percent-encoded URLs.
		// We validate below by requiring a recognised GitHub URL pattern.
		$url = trim( wp_unslash( $_POST['url'] ?? '' ) );
		if ( ! $url ) {
			wp_send_json_error( [ 'message' => __( 'No URL provided.', 'wpte-devzone-github' ) ] );
		}

		if ( ! preg_match( '#^https?://github\.com/#i', $url ) ) {
			wp_send_json_error( [ 'message' => __( 'Only GitHub URLs are accepted.', 'wpte-devzone-github' ) ] );
		}

		$parsed = GithubApi::parse_issue_url( $url );
		if ( ! $parsed ) {
			wp_send_json_error( [ 'message' => __( 'Could not extract an issue from the provided URL.', 'wpte-devzone-github' ) ] );
		}

		$this->send_result( GithubApi::get_issue( $parsed['full_name'], $parsed['issue_number'] ), 'issue' );
	}

	public function ajax_get_branch_tags(): void {
		Admin::verify_request();

		$full_name = $this->post_param( 'full_name' );
		$pr_number = absint( $_POST['pr_number'] ?? 0 );

		if ( ! $full_name || ! $pr_number ) {
			wp_send_json_error( [ 'message' => __( 'Missing parameters.', 'wpte-devzone-github' ) ] );
		}

		$this->send_result( GithubApi::get_tags_for_pr( $full_name, $pr_number ), 'tags' );
	}

	/**
	 * Returns a name-keyed map of all installed plugins with version/active/file.
	 * Used client-side to show installed version badges next to release rows.
	 */
	public function ajax_get_download_log(): void {
		Admin::verify_request();

		$log = get_option( WPTE_DZ_GITHUB_OPTION_DOWNLOAD_LOG, [] );
		// Newest first.
		$log = array_reverse( $log );
		wp_send_json_success( [ 'log' => $log ] );
	}

	public function ajax_installed_versions(): void {
		Admin::verify_request();

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$map         = [];
		foreach ( $all_plugins as $file => $data ) {
			if ( ! empty( $data['Name'] ) ) {
				$map[ $data['Name'] ] = [
					'version' => $data['Version'] ?? '',
					'active'  => is_plugin_active( $file ),
					'file'    => $file,
				];
			}
		}

		wp_send_json_success( [ 'plugins' => $map ] );
	}

	public function ajax_clear_log(): void {
		Admin::verify_request();
		delete_option( WPTE_DZ_GITHUB_OPTION_DOWNLOAD_LOG );
		delete_option( WPTE_DZ_GITHUB_OPTION_LAST_DL_TS );
		wp_send_json_success();
	}

	public function ajax_set_auto_install(): void {
		Admin::verify_request();

		$enabled = ! empty( $_POST['enabled'] ) && '0' !== $_POST['enabled'];
		update_option( WPTE_DZ_GITHUB_OPTION_AUTO_INSTALL, $enabled ? 'yes' : 'no' );
		wp_send_json_success( [ 'auto_install' => $enabled ] );
	}

	public function ajax_get_favorites(): void {
		Admin::verify_request();

		$favorites = get_option( WPTE_DZ_GITHUB_OPTION_FAVORITES, [] );
		wp_send_json_success( [ 'favorites' => array_values( (array) $favorites ) ] );
	}

	public function ajax_save_favorites(): void {
		Admin::verify_request();

		$decoded   = json_decode( wp_unslash( $_POST['favorites'] ?? '' ), true );
		$raw       = is_array( $decoded ) ? $decoded : [];
		$favorites = array_values( array_unique( array_map( 'sanitize_text_field', $raw ) ) );
		update_option( WPTE_DZ_GITHUB_OPTION_FAVORITES, $favorites );
		wp_send_json_success( [ 'favorites' => $favorites ] );
	}
}
