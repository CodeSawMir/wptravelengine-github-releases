<?php

namespace WPTEDZGithub;

defined( 'ABSPATH' ) || exit;

class Plugin {

	public static function boot(): void {
		self::register_autoloader();
		self::register_rest_routes();

		if ( ! is_admin() ) {
			return;
		}
		if ( ! class_exists( \WPTravelEngineDevZone\Tools\AbstractTool::class ) ) {
			return;
		}

		self::register_tabs();
		self::register_tools();
	}

	private static function register_autoloader(): void {
		spl_autoload_register( function ( string $class ): void {
			$prefix = 'WPTEDZGithub\\';
			if ( strpos( $class, $prefix ) !== 0 ) {
				return;
			}
			$relative = substr( $class, strlen( $prefix ) );
			$kebab    = strtolower( preg_replace( '/([A-Z])/', '-$1', lcfirst( $relative ) ) );
			$file     = WPTE_DZ_GITHUB_DIR . 'includes/class-' . $kebab . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		} );
	}

	private static function register_rest_routes(): void {
		if ( get_option( WPTE_DZ_GITHUB_OPTION_AUTO_INSTALL, 'no' ) !== 'yes' ) {
			return;
		}
		add_action( 'rest_api_init', [ GithubRestController::class, 'register_routes' ] );
	}

	private static function register_tabs(): void {
		add_filter( 'wpte_devzone_tabs', function ( array $tabs ): array {
			$tabs['github'] = [
				'title'    => __( 'GitHub', 'wpte-devzone-github' ),
				'subtabs'  => [
					'__inject_markup' => function (): void {
						echo '<div id="gh-toolbar-inject"></div>';
					},
				],
				'priority' => 4,
			];
			return $tabs;
		} );

		// Priority 20 — runs after core registers 'logs' group at priority 10.
		add_filter( 'wpte_devzone_tabs', function ( array $tabs ): array {
			if ( isset( $tabs['logs']['subtabs'] ) ) {
				$tabs['logs']['subtabs']['github-downloads'] = __( 'GitHub Downloads', 'wpte-devzone-github' );
			}
			return $tabs;
		}, 20 );
	}

	private static function register_tools(): void {
		add_filter( 'wpte_devzone_tools', fn( array $tools ): array => [
			...$tools,
			new GithubTool(),
			new GithubDownloadsTool(),
		] );
	}
}
