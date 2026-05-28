<?php

namespace WPTEDZGithub;

defined( 'ABSPATH' ) || exit;

class GithubInstaller {

	/**
	 * Static slug map for WPTE addons.
	 *
	 * Keys are the GitHub repo name (just the repo part, without org).
	 * Values are the WordPress plugin folder slug.
	 */
	private const WPTE_ADDON_SLUGS = [
		// wptravelengine-* repos
		'wptravelengine-accommodation'             => 'wptravelengine-accommodation',
		'wptravelengine-activity-tour-booking'     => 'wptravelengine-activity-tour-booking',
		'wptravelengine-advanced-analytics'        => 'wptravelengine-advanced-analytics',
		'wptravelengine-advanced-email-automator'  => 'wptravelengine-advanced-email-automator',
		'wptravelengine-booking-fee'               => 'wptravelengine-booking-fee',
		'wptravelengine-conditional-price'         => 'wptravelengine-conditional-price',
		'wptravelengine-custom-booking-link'       => 'wptravelengine-custom-booking-link',
		'wptravelengine-email-customizer'          => 'wptravelengine-email-customizer',
		'wptravelengine-hbl-payments'              => 'wp-travel-engine-hbl-payment-gateway',
		'wptravelengine-installment-payments'      => 'wptravelengine-installment-payments',
		'wptravelengine-per-trip-emails'           => 'wptravelengine-per-trip-emails',
		'wptravelengine-pickup-points'             => 'wptravelengine-pickup-points',
		'wptravelengine-private-trips'             => 'wptravelengine-private-trips',
		'wptravelengine-pro'                       => 'wptravelengine-pro',
		'wptravelengine-razor-pay'                 => 'wptravelengine-razor-pay',
		'wptravelengine-scalapay'                  => 'wptravelengine-scalapay',
		'wptravelengine-slicewp-integration'                   => 'wptravelengine-slicewp-integration',	
		'wptravelengine-travel-insurance'          => 'wptravelengine-travel-insurance',
		'wptravelengine-waitlist'                  => 'wptravelengine-waitlist',
		'wptravelengine-webhooks-and-api'          => 'wptravelengine-webhooks-and-api',
		'wptravelengine-woocommerce-payments'      => 'wptravelengine-woocommerce-payments',
		// wp-travel-engine-* repos (repo name differs from folder slug)
		'wptravelengine-advanced-itinerary'     => 'wp-travel-engine-advanced-itinerary-builder',
		'wptravelengine-authorize-net'  => 'wp-travel-engine-authorize-net-payment-gateway',
		'wptravelengine-currency-converter'             => 'wp-travel-engine-currency-converter',
		'wptravelengine-extra-services'                 => 'wp-travel-engine-extra-services',
		'wptravelengine-file-downloads'                 => 'wp-travel-engine-file-downloads',
		'wptravelengine-form-editor'                    => 'wp-travel-engine-form-editor',
		'wptravelengine-group-discount'                 => 'wp-travel-engine-group-discount',
		'wptravelengine-itinerary-downloader'           => 'wp-travel-engine-itinerary-downloader',
		'wptravelengine-legal-documents'                => 'wp-travel-engine-legal-documents',
		'wptravelengine-midtrans-payments'       => 'wp-travel-engine-midtrans-payment-gateway',
		'wptravelengine-partial-payment'                => 'wp-travel-engine-partial-payment',
		'wptravelengine-payfast'        => 'wp-travel-engine-payfast-payment-gateway',
		'wptravelengine-paypal-express'         => 'wp-travel-engine-paypal-express-gateway',
		'wptravelengine-payu-payment-gateway'           => 'wp-travel-engine-payu-payment-gateway',
		'wptravelengine-payu-money-bolt'      => 'wp-travel-engine-payumoney-payment-gateway',
		'wptravelengine-social-proof'                   => 'wp-travel-engine-social-proof',
		'wptravelengine-stripe'         => 'wp-travel-engine-stripe-payment-gateway',
		'wptravelengine-trip-fixed-starting-dates'      => 'wp-travel-engine-trip-fixed-starting-dates',
		'wte-fixed-starting-dates-countdown' => 'wp-travel-engine-trip-fixed-starting-dates-countdown',
		'wptravelengine-trip-review'                   => 'wp-travel-engine-trip-reviews',
		'wptravelengine-trip-weather-forecast'          => 'wp-travel-engine-trip-weather-forecast',
		'wte-user-history'                   => 'wp-travel-engine-user-history',
		'wp-travel-engine-whatsapp-notification'          => 'wptravelengine-whatsapp-notification',
		'wp-travel-engine-whatsapp-server'                => 'wptravelengine-whatsapp-server',
		'wte-zapier'                         => 'wp-travel-engine-zapier',
		// misc
		'wptravelengine-elementor-widgets'                    => 'wte-elementor-widgets',
	];

	/**
	 * Download, extract, and install a plugin from a GitHub release zip.
	 *
	 * Returns an array on success:
	 *   { success: true, plugin_file: string, slug: string, plugin_name: string }
	 *
	 * @return array|\WP_Error
	 */
	public static function install_from_url( string $zip_url, string $repo_name ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		// unzip_file() requires $wp_filesystem to be initialized or it returns 'Could not access filesystem.'
		if ( empty( $GLOBALS['wp_filesystem'] ) ) {
			WP_Filesystem();
		}

		$tmp = GithubApi::download_zip( $zip_url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		if ( ! file_exists( $tmp ) ) {
			return new \WP_Error( 'no_file', 'Downloaded zip file not found.' );
		}

		$zip_size = filesize( $tmp );
		if ( $zip_size < 100 ) {
			@unlink( $tmp );
			return new \WP_Error( 'empty_zip', "Downloaded file is too small ({$zip_size} bytes)." );
		}

		$stage_dir = get_temp_dir() . 'wpte-dz-gh-stage-' . sanitize_title( $repo_name ) . '-' . uniqid() . '/';
		@mkdir( $stage_dir, 0755, true );

		$unzip = unzip_file( $tmp, $stage_dir );
		@unlink( $tmp );

		if ( is_wp_error( $unzip ) ) {
			self::rmdir_recursive( $stage_dir );
			return new \WP_Error( 'unzip_failed', 'Unzip failed: ' . $unzip->get_error_message() );
		}

		$top_dirs   = glob( $stage_dir . '*', GLOB_ONLYDIR );
		$source_dir = ! empty( $top_dirs ) ? trailingslashit( reset( $top_dirs ) ) : $stage_dir;

		$zip_plugin_name = self::get_plugin_name_from_dir( $source_dir );

		$existing_slug = self::find_installed_slug_by_name( $zip_plugin_name );
		$repo_key      = strtolower( basename( str_replace( '\\', '/', $repo_name ) ) );
		$mapped_slug   = self::WPTE_ADDON_SLUGS[ $repo_key ] ?? $repo_key;
		$final_slug    = $existing_slug ?: $mapped_slug;

		$plugin_dir   = WP_PLUGIN_DIR . '/' . $final_slug;
		$was_existing = is_dir( $plugin_dir );

		if ( $was_existing ) {
			self::rmdir_recursive( $plugin_dir );
		}

		if ( ! @rename( rtrim( $source_dir, '/' ), $plugin_dir ) ) {
			// rename() fails across filesystem boundaries (e.g. /tmp on tmpfs vs plugins on ext4).
			// Fall back to copy + delete so the install works regardless of mount setup.
			$copy_result = copy_dir( $source_dir, $plugin_dir );
			self::rmdir_recursive( rtrim( $source_dir, '/' ) );
			self::rmdir_recursive( $stage_dir );

			if ( is_wp_error( $copy_result ) ) {
				return new \WP_Error( 'move_failed', 'Could not install plugin: ' . $copy_result->get_error_message() );
			}
		} else {
			self::rmdir_recursive( $stage_dir );
		}

		$plugin_file = self::detect_plugin_file( $final_slug );

		return [
			'success'     => true,
			'plugin_file' => $plugin_file,
			'slug'        => $final_slug,
			'plugin_name' => $zip_plugin_name,
			'action'      => $was_existing ? 'replaced' : 'installed',
		];
	}

	private static function get_plugin_name_from_dir( string $dir ): string {
		$files = glob( rtrim( $dir, '/' ) . '/*.php' ) ?: [];
		foreach ( $files as $file ) {
			$data = get_plugin_data( $file, false, false );
			if ( ! empty( $data['Name'] ) ) {
				return $data['Name'];
			}
		}
		return '';
	}

	private static function find_installed_slug_by_name( string $plugin_name ): string {
		if ( ! $plugin_name ) {
			return '';
		}
		$all_plugins = get_plugins();
		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			if ( strtolower( $plugin_data['Name'] ) === strtolower( $plugin_name ) ) {
				return dirname( $plugin_file );
			}
		}
		return '';
	}

	private static function detect_plugin_file( string $slug ): string {
		$dir   = WP_PLUGIN_DIR . '/' . $slug;
		$files = glob( $dir . '/*.php' ) ?: [];

		foreach ( $files as $file ) {
			if ( basename( $file, '.php' ) === $slug ) {
				return $slug . '/' . basename( $file );
			}
		}

		foreach ( $files as $file ) {
			$data = get_plugin_data( $file, false, false );
			if ( ! empty( $data['Name'] ) ) {
				return $slug . '/' . basename( $file );
			}
		}

		return $slug . '/' . $slug . '.php';
	}

	private static function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = array_diff( scandir( $dir ), [ '.', '..' ] );
		foreach ( $items as $item ) {
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			is_dir( $path ) ? self::rmdir_recursive( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}
}
