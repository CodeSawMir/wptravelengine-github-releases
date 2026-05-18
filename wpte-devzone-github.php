<?php
/**
 * Plugin Name: WPTE DevZone – GitHub
 * Plugin URI:  https://github.com/CodeSawMir/wptravelengine-github-releases
 * Description: Adds a "GitHub" tab to WP Travel Engine Dev Zone for GitHub release management.
 * Version:     1.0.0
 * Author:      Samir Shrestha
 * Requires:    wptravelengine-devzone-plugin
 * Requires WP: 6.9
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'WPTE_DZ_GITHUB_VERSION',      '1.0.0' );
define( 'WPTE_DZ_GITHUB_DIR',          plugin_dir_path( __FILE__ ) );
define( 'WPTE_DZ_GITHUB_URL',          plugin_dir_url( __FILE__ ) );
define( 'WPTE_DZ_GITHUB_OPTION_TOKEN', 'wpte_dz_github_token' );

add_action( 'plugins_loaded', function (): void {
	// Guard: DevZone must be active and its AbstractTool must be loaded.
	if ( ! class_exists( \WPTravelEngineDevZone\Tools\AbstractTool::class ) ) {
		return;
	}
	if ( ! is_admin() ) {
		return;
	}

	// Autoloader: WPTEDZGithub\ClassName → includes/class-classname.php
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

	// Register 'github' group. The nav bar is kept visible via __inject_markup
	// (DevZone layout.php and nav-manager.js both check for inject presence).
	add_filter( 'wpte_devzone_tabs', function ( array $tabs ): array {
		$tabs['github'] = [
			'title'   => __( 'GitHub', 'wpte-devzone-github' ),
			'subtabs' => [
				// No real subtabs — only inject. DevZone layout.php will still render
				// the nav bar because __inject_markup is present.
				'__inject_markup' => function (): void {
					// JS fills this with the full toolbar (tabs, search, controls,
					// user identity) after boot.
					echo '<div id="gh-toolbar-inject"></div>';
				},
			],
			'priority' => 4
		];
		return $tabs;
	} );

	// Append GithubTool to the tools array.
	add_filter( 'wpte_devzone_tools', fn( array $tools ): array => [ ...$tools, new \WPTEDZGithub\GithubTool() ] );
} );
