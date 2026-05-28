<?php

defined( 'ABSPATH' ) || exit;

define( 'WPTE_DZ_GITHUB_VERSION',              '1.0.0' );
define( 'WPTE_DZ_GITHUB_DIR',                 plugin_dir_path( dirname( __FILE__ ) ) );
define( 'WPTE_DZ_GITHUB_URL',                 plugin_dir_url( dirname( __FILE__ ) ) );
define( 'WPTE_DZ_GITHUB_OPTION_TOKEN',        'wpte_dz_github_token' );
define( 'WPTE_DZ_GITHUB_OPTION_DOWNLOAD_LOG', 'wpte_dz_github_download_log' );
define( 'WPTE_DZ_GITHUB_OPTION_LAST_DL_TS',  'wpte_dz_github_last_download_ts' );
define( 'WPTE_DZ_GITHUB_OPTION_AUTO_INSTALL', 'wpte_dz_github_auto_install' );

// Override in wp-config.php for a custom secret; default is the static testing value.
if ( ! defined( 'WPTE_DZ_GITHUB_WEBHOOK_SECRET' ) ) {
	define( 'WPTE_DZ_GITHUB_WEBHOOK_SECRET', 'wpte-devzone-github-testing' );
}
