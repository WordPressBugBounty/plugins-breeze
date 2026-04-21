<?php
/**
 * Class Breeze_Cache_CronJobs
 *
 * Handles cron jobs and cache-related functionality for the Breeze plugin.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class Breeze_Cache_CronJobs {

	/**
	 * Option prefix for the DB.
	 */
	private const OPT_PREFIX = 'breeze_';

	function __construct( $enabled ) {
		$current_time_utc = current_time( 'timestamp' );  //phpcs:ignore

		if ( $enabled ) {

			/**
			 * Will handle the cache Gravatars.
			 */
			if ( ! wp_next_scheduled( 'breeze_clear_remote_gravatar', array( 'gravatars' ) ) ) {
				wp_schedule_event( $current_time_utc, 'weekly', 'breeze_clear_remote_gravatar', array( 'gravatars' ) );
			}
			add_action( 'breeze_clear_remote_gravatar', array( &$this, 'extra_cache_cleanup' ), 10, 1 );
			add_filter( 'get_avatar', array( &$this, 'breeze_replace_gravatar_image' ) );
		} else {

			if ( wp_next_scheduled( 'breeze_clear_remote_gravatar', array( 'gravatars' ) ) ) {
				wp_unschedule_event( $current_time_utc, 'weekly', 'breeze_clear_remote_gravatar', 'gravatars' );
			}
		}
	}

	/**
	 * Clean the cache for the extra options.
	 *
	 * @param string $folder_for extra ache folder for which to clean the cache.
	 *
	 * @return void
	 */
	public function extra_cache_cleanup( string $folder_for = '' ) {
		$allowed_actions = array(
			'gravatars',
		);
		if ( empty( $folder_for ) || ! in_array( $folder_for, $allowed_actions, true ) ) {
			return;
		}
		$blog_id         = $this->get_blog_id();
		$folder_for_dash = $folder_for . '/';
		$directory       = BREEZE_MINIFICATION_EXTRA . $folder_for_dash . $blog_id;
		if ( is_dir( $directory ) ) {
			$files_list = scandir( $directory );
			if ( ! empty( $files_list ) ) {
				if ( ! isset( $scanned_files[ $folder_for ] ) ) { // TODO check if $directory or $folder_for
					$scanned_files[ $folder_for ] = array();
				}
				foreach ( $files_list as $index => $filename ) {
					if ( ! in_array( $filename, $scanned_files[ $folder_for ] ) ) {
						$scanned_files[ $folder_for ][] = $filename;
					}
				}
			}
		}
		if ( ! empty( $scanned_files ) ) {
			foreach ( $scanned_files as $scan_dir_name => $scanned_dir ) {
				$current_cache_dir = rtrim( BREEZE_MINIFICATION_EXTRA . $folder_for_dash . $blog_id, '/' ) . '/';
				foreach ( $scanned_dir as $file ) {
					if (
						! in_array( $file, array( '.', '..' ), true ) &&
						is_file( $current_cache_dir . $file ) ) {
						@unlink( $current_cache_dir . $file );
					}
				}
			}
		}
	}

	/**
	 * Replaces gravatar URLs in the provided HTML with locally cached gravatar URLs.
	 *
	 * @param string $gravatar The HTML content containing gravatar links.
	 *
	 * @return string The updated HTML content with replaced gravatar URLs.
	 */
	public function breeze_replace_gravatar_image( string $gravatar ): string {
		// Require quoted attribute values preceded by whitespace to prevent
		// matching attacker-controlled text inside other attributes (e.g. alt).
		if ( preg_match( '/\ssrcset=["\']([^"\']+)["\']/', $gravatar, $srcset_match ) ) {
			$url             = explode( ' ', trim( $srcset_match[1] ) )[0];
			$local_gravatars = $this->fetch_gravatar_from_remote( $url );
			if ( $local_gravatars !== $url ) {
				$gravatar = str_replace( $url, $local_gravatars, $gravatar );
			}
		}
		if ( preg_match( '/\ssrc=["\']([^"\']+)["\']/', $gravatar, $src_match ) ) {
			$url             = explode( ' ', trim( $src_match[1] ) )[0];
			$local_gravatars = $this->fetch_gravatar_from_remote( $url );
			if ( $local_gravatars !== $url ) {
				$gravatar = str_replace( $url, $local_gravatars, $gravatar );
			}
		}
		if ( ! is_string( $gravatar ) ) {
			$gravatar = '';
		}

		return $gravatar;
	}

	/**
	 * Fetches a Gravatar image from a remote URL, saves it locally in the cache directory,
	 * and returns the local path to the cached image. If the image already exists in the cache,
	 * the local cached version is returned directly. If unable to fetch and save the image,
	 * the original URL is returned.
	 *
	 * @param string $url The URL of the Gravatar image to fetch.
	 *
	 * @return string The local cached URL of the Gravatar image, or the original URL if the operation fails.
	 */
	private function fetch_gravatar_from_remote( string $url = '' ): string {
		if ( empty( $url ) ) {
			return '';
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( 'gravatar.com' !== $host && '.gravatar.com' !== substr( $host, -13 ) ) {
			return $url;
		}

		$blog_id        = $this->get_blog_id();
		$gravatar_name  = basename( wp_parse_url( $url, PHP_URL_PATH ) );
		$filetype       = wp_check_filetype( $gravatar_name );
		$allowed_images = array( 'image/jpeg', 'image/png', 'image/gif' );

		if ( ! empty( $filetype['type'] ) && ! in_array( $filetype['type'], $allowed_images, true ) ) {
			return $url;
		}

		if ( empty( $filetype['ext'] ) || ! in_array( $filetype['type'], $allowed_images, true ) ) {
			$gravatar_name .= '.jpg';
		}

		$saved_gravatar = $this->check_for_content( 'gravatars', $gravatar_name );
		if ( ! empty( $saved_gravatar ) ) {
			return $saved_gravatar;
		}

		$wp_filesystem       = breeze_get_filesystem();
		$gravatar_local_path = $this->get_local_extra_cache_directory( 'gravatars' );

		if ( ! file_exists( $gravatar_local_path . $gravatar_name ) ) {
			if ( ! function_exists( 'download_url' ) ) {
				require_once wp_normalize_path( ABSPATH . '/wp-admin/includes/file.php' );
			}

			$temp_gravatar = download_url( $url );
			if ( is_wp_error( $temp_gravatar ) ) {
				return $url;
			}

			$file_check = wp_check_filetype_and_ext( $temp_gravatar, $gravatar_name );
			if ( empty( $file_check['type'] ) || 0 !== strpos( $file_check['type'], 'image/' ) ) {
				@unlink( $temp_gravatar );
				return $url;
			}

			$is_saved = $wp_filesystem->move( $temp_gravatar, $gravatar_local_path . $gravatar_name, true );
			if ( ! $is_saved ) {
				@unlink( $temp_gravatar );
				return $url;
			}
			@unlink( $temp_gravatar );
		}

		return content_url( '/cache/breeze-extra/gravatars/' . $blog_id . $gravatar_name );
	}

	/**
	 * Checks if a specific file exists in the designated cache folder and returns its URL
	 * if found. If the file does not exist, an empty string is returned.
	 *
	 * @param string $folder The name of the folder to check within the cache directory.
	 * @param string $filename The name of the file to check for in the specified folder.
	 *
	 * @return string The URL of the found file in the cache, or an empty string if the file does not exist.
	 */
	private function check_for_content( string $folder = '', string $filename = '' ): string {
		if ( empty( $folder ) || empty( $filename ) ) {
			return '';
		}
		$blog_id        = $this->get_blog_id();
		$gravatar_cache = WP_CONTENT_DIR . '/cache/breeze-extra/' . $folder . '/' . $blog_id . $filename;
		if ( file_exists( $gravatar_cache ) ) {
			return content_url( '/cache/breeze-extra/' . $folder . '/' . $blog_id . $filename );
		}

		return '';
	}

	/**
	 * Retrieves the blog ID for the current WordPress site. For multisite installations,
	 * it appends a forward slash ("/") to the blog ID. If multisite-specific functions
	 * are unavailable, it fetches the blog ID from a global configuration or defaults to 1.
	 *
	 * @return string|int The blog ID as a string|int, with a forward slash appended for multisite installations.
	 */
	private function get_blog_id() {
		$blog_id = '';
		if ( function_exists( 'is_multisite' ) && function_exists( 'get_current_blog_id' ) ) {
			if ( is_multisite() ) {
				$blog_id = get_current_blog_id() . '/';
			}
		} else {
			$blog_id = isset( $GLOBALS['breeze_config']['blog_id'] ) ? $GLOBALS['breeze_config']['blog_id'] : 1;
		}

		return $blog_id;
	}

	/**
	 * Retrieves the local extra cache directory path for a specified folder and blog ID.
	 * Ensures that the directory structure exists by creating it if necessary.
	 *
	 * @param string $folder The specific folder name for which the cache directory is retrieved.
	 *                       Defaults to an empty string if not specified.
	 *
	 * @return string The path to the corresponding local extra cache directory.
	 */
	private function get_local_extra_cache_directory( string $folder = '' ): string {
		$blog_id = $this->get_blog_id();
		Breeze_MinificationCache::checkCacheDir( BREEZE_MINIFICATION_EXTRA );
		Breeze_MinificationCache::checkCacheDir( BREEZE_MINIFICATION_EXTRA . '/' . $folder . '/' );
		if ( ! empty( $blog_id ) ) {
			Breeze_MinificationCache::checkCacheDir( BREEZE_MINIFICATION_EXTRA . '/' . $folder . '/' . $blog_id );
		}

		return BREEZE_MINIFICATION_EXTRA . '/' . $folder . '/' . $blog_id;
	}
}
