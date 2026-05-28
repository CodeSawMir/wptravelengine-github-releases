<?php

namespace WPTEDZGithub;

defined( 'ABSPATH' ) || exit;

class GithubInstaller {

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
		$final_slug    = $existing_slug ?: sanitize_title( $repo_name );

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
