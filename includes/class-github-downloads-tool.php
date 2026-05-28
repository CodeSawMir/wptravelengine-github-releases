<?php

namespace WPTEDZGithub;

use WPTravelEngineDevZone\Tools\AbstractTool;

defined( 'ABSPATH' ) || exit;

class GithubDownloadsTool extends AbstractTool {

	public function get_slug(): string     { return 'github-downloads'; }
	public function get_label(): string    { return __( 'GitHub Downloads', 'wpte-devzone-github' ); }
	public function get_template(): string { return WPTE_DZ_GITHUB_DIR . 'templates/tab-logs-github-downloads.php'; }

	public function enqueue_assets(): void {
		wp_enqueue_style(
			'wpte-dz-github',
			WPTE_DZ_GITHUB_URL . 'assets/github.css',
			[ 'wpte-devzone' ],
			WPTE_DZ_GITHUB_VERSION
		);
	}
}
