<?php
/**
 * Plugin Name: WPTE DevZone – GitHub
 * Plugin URI:  https://github.com/CodeSawMir/wptravelengine-github-releases
 * Description: Adds a "GitHub" tab to WP Travel Engine Dev Zone for GitHub release management.
 * Version:     1.0.1
 * Author:      Samir Shrestha
 * Requires:    wptravelengine-devzone-plugin
 * Requires WP: 6.9
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/constants.php';
require_once __DIR__ . '/includes/plugin.php';

add_action( 'plugins_loaded', [ \WPTEDZGithub\Plugin::class, 'boot' ] );
