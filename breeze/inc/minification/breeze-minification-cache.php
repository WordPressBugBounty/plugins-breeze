<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly
/*
 *  Based on some work of autoptimize plugin
 */

class Breeze_MinificationCache {
	private $filename;
	private $mime;
	private $cachedir;
	private $delayed;
	private $nogzip;

	/**
	 * Whether the generated cache path remains inside the cache directory.
	 *
	 * @var bool
	 */
	private $path_is_safe = true;

	public function __construct( $md5, $ext = 'php' ) {
		$separate_cache = breeze_mobile_detect();
		$this->cachedir = BREEZE_MINIFICATION_CACHE . breeze_current_user_type();
		if ( is_multisite() ) {
			$blog_id        = get_current_blog_id();
			$this->cachedir = BREEZE_MINIFICATION_CACHE . $blog_id . '/' . breeze_current_user_type();
		}

		$this->delayed   = BREEZE_CACHE_DELAY;
		$this->nogzip    = BREEZE_CACHE_NOGZIP;
		$variation       = breeze_get_cache_variation_context();
		$currency_suffix = '';
		if ( $this->nogzip && in_array( $ext, array( 'js', 'css' ), true ) ) {
			$currency_suffix = $variation->get_asset_suffix();
		}
		$this->filename = $this->build_cache_filename( $md5, $ext, $separate_cache, $currency_suffix );

		$this->path_is_safe = $variation->can_create_asset_cache() && $this->is_cache_path_safe();
		if ( ! $variation->can_create_asset_cache() ) {
			$this->filename = $this->build_cache_filename(
				hash( 'sha256', (string) $md5 ),
				$ext,
				$separate_cache,
				Breeze_Cache_Variation_Context::INVALID_ASSET_SUFFIX
			);

			return;
		}

		if ( ! $this->path_is_safe ) {
			$safe_hash          = hash( 'sha256', $this->filename );
			$this->filename     = $this->build_cache_filename(
				$safe_hash,
				$ext,
				$separate_cache,
				$currency_suffix
			);
			$this->path_is_safe = $this->is_cache_path_safe();
		}
	}

	/**
	 * Builds a cache filename from normalized cache-key components.
	 *
	 * @param string $cache_key Cache key or a safe hash of the intended filename.
	 * @param string $ext Cache file extension.
	 * @param string $separate_cache Device-specific cache prefix.
	 * @param string $currency_suffix Currency-specific cache suffix.
	 *
	 * @return string Relative cache filename.
	 */
	private function build_cache_filename( $cache_key, $ext, $separate_cache, $currency_suffix ) {
		if ( ! $this->nogzip ) {
			return BREEZE_CACHEFILE_PREFIX . $separate_cache . $cache_key . '.php';
		}

		if ( in_array( $ext, array( 'js', 'css' ), true ) ) {
			return $ext . '/'
					. BREEZE_CACHEFILE_PREFIX
					. $separate_cache
					. $cache_key
					. $currency_suffix
					. '.'
					. $ext;
		}

		return '/' . BREEZE_CACHEFILE_PREFIX . $separate_cache . $cache_key . '.' . $ext;
	}

	/**
	 * Checks that the cache filename cannot escape the cache directory.
	 *
	 * @return bool True when the cache filename resolves inside the cache root.
	 */
	private function is_cache_path_safe() {
		$relative_filename = str_replace( '\\', '/', $this->filename );
		$relative_filename = ltrim( $relative_filename, '/' );

		if (
			empty( $relative_filename ) ||
			preg_match( '#(^|/)\.\.?(/|$)#', $relative_filename )
		) {
			return false;
		}

		$cache_root = realpath( $this->cachedir );
		if ( false === $cache_root ) {
			/*
			 * The cache directory may not exist yet during first-time setup. The
			 * traversal-component check above still prevents path escape.
			 */
			return true;
		}

		$cache_root = trailingslashit( wp_normalize_path( $cache_root ) );
		$cache_path = wp_normalize_path( $this->cachedir . $this->filename );
		$target_dir = realpath( dirname( $cache_path ) );

		if ( false === $target_dir ) {
			return false;
		}

		$target_dir = trailingslashit( wp_normalize_path( $target_dir ) );
		if ( 0 !== strpos( $target_dir, $cache_root ) ) {
			return false;
		}

		if ( file_exists( $cache_path ) ) {
			$resolved_path = realpath( $cache_path );
			if (
				false !== $resolved_path &&
				0 !== strpos( wp_normalize_path( $resolved_path ), $cache_root )
			) {
				return false;
			}
		}

		return true;
	}

	public function get_cache_dir() {
		return $this->cachedir;
	}

	public function get_file_name() {
		return $this->filename;
	}

	public function check() {
		if ( ! $this->path_is_safe ) {
			return false;
		}

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! defined( 'FS_CHMOD_FILE' ) ) {
			define( 'FS_CHMOD_FILE', ( 0664 & ~umask() ) );
		}

		if ( ! $wp_filesystem->exists( $this->cachedir . $this->filename, FS_CHMOD_FILE ) ) {

			// No cached file, sorry
			return false;
		}

		// Cache exists!
		return true;
	}

	public function retrieve() {
		if ( $this->check() ) {
			if ( $this->nogzip == false ) {
				return file_get_contents( $this->cachedir . $this->filename . '.none' );
			} else {
				return file_get_contents( $this->cachedir . $this->filename );
			}
		}

		return false;
	}

	public function cache( $code, $mime ) {
		if ( ! $this->path_is_safe ) {
			return false;
		}

		if ( $this->nogzip == false ) {
			$file    = ( $this->delayed ? 'delayed.php' : 'default.php' );
			$phpcode = file_get_contents( BREEZE_PLUGIN_DIR . '/inc/minification/config/' . $file );
			$phpcode = str_replace( array( '%%CONTENT%%', 'exit;' ), array( $mime, '' ), $phpcode );

			//file_put_contents( $this->cachedir . $this->filename, $phpcode, LOCK_EX );
			breeze_read_write_file( $this->cachedir . $this->filename, $phpcode );

			// file_put_contents( $this->cachedir . $this->filename . '.none', $code, LOCK_EX );
			breeze_read_write_file( $this->cachedir . $this->filename . '.none', $code );

			if ( ! $this->delayed ) {
				// Compress now!
				// file_put_contents( $this->cachedir . $this->filename . '.deflate', gzencode( $code, 9, FORCE_DEFLATE ), LOCK_EX );
				breeze_read_write_file( $this->cachedir . $this->filename . '.deflate', gzencode( $code, 9, FORCE_DEFLATE ) );

				// file_put_contents( $this->cachedir . $this->filename . '.gzip', gzencode( $code, 9, FORCE_GZIP ), LOCK_EX );
				breeze_read_write_file( $this->cachedir . $this->filename . '.gzip', gzencode( $code, 9, FORCE_GZIP ) );
			}
		} else {
			// Write code to cache without doing anything else
			// file_put_contents( $this->cachedir . $this->filename, $code, LOCK_EX );
			breeze_read_write_file( $this->cachedir . $this->filename, $code );
		}
	}

	public function getname() {
		apply_filters( 'breeze_filter_cache_getname', breeze_CACHE_URL . breeze_current_user_type() . $this->filename );

		return $this->filename;
	}

	//create folder cache
	public static function create_cache_minification_folder() {
		if ( ! defined( 'BREEZE_MINIFICATION_CACHE' ) ) {
			// We didn't set a cache
			return false;
		}

		breeze_ensure_cache_index_html( BREEZE_MINIFICATION_CACHE );

		if ( is_multisite() ) {
			breeze_secure_cache_directory( BREEZE_MINIFICATION_CACHE );

			$blog_id = get_current_blog_id();
			breeze_ensure_cache_index_html( BREEZE_MINIFICATION_CACHE . $blog_id );

			foreach ( array( '', 'js', 'css' ) as $checkDir ) {
				if ( ! Breeze_MinificationCache::checkCacheDir( BREEZE_MINIFICATION_CACHE . $blog_id . '/' . breeze_current_user_type() . $checkDir ) ) {
					return false;
				}
			}

			breeze_ensure_cache_index_html( BREEZE_MINIFICATION_CACHE . $blog_id . '/' . rtrim( breeze_current_user_type(), '/' ) );

			/** write .htaccess here to overrule wp_super_cache */
			$htAccess = BREEZE_MINIFICATION_CACHE . $blog_id . '/' . rtrim( breeze_current_user_type(), '/' ) . '/.htaccess';
		} else {
			foreach ( array( '', 'js', 'css' ) as $checkDir ) {
				if ( ! Breeze_MinificationCache::checkCacheDir( BREEZE_MINIFICATION_CACHE . breeze_current_user_type() . $checkDir ) ) {
					return false;
				}
			}

			breeze_ensure_cache_index_html( BREEZE_MINIFICATION_CACHE . rtrim( breeze_current_user_type(), '/' ) );

			/** write .htaccess here to overrule wp_super_cache */
			$htAccess = BREEZE_MINIFICATION_CACHE . rtrim( breeze_current_user_type(), '/' ) . '/.htaccess';
		}

		if ( ! is_file( $htAccess ) ) {
			/**
			 * create wp-content/AO_htaccess_tmpl with
			 * whatever htaccess rules you might need
			 * if you want to override default AO htaccess
			 */
			$htaccess_tmpl = WP_CONTENT_DIR . '/AO_htaccess_tmpl';
			if ( is_file( $htaccess_tmpl ) ) {
				$htAccessContent = file_get_contents( $htaccess_tmpl );
			} elseif ( is_multisite() ) {
				$htAccessContent = '<IfModule mod_headers.c>
        Header set Vary "Accept-Encoding"
        Header set Cache-Control "max-age=10672000, must-revalidate"
</IfModule>
<IfModule mod_expires.c>
        ExpiresActive On
        ExpiresByType text/css A30672000
        ExpiresByType text/javascript A30672000
        ExpiresByType application/javascript A30672000
</IfModule>
<IfModule mod_deflate.c>
        <FilesMatch "\.(js|css)$">
        SetOutputFilter DEFLATE
    </FilesMatch>
</IfModule>
<IfModule mod_authz_core.c>
    <FilesMatch "\.(?i:php|phtml|php3|php4|php5|php7|php8|phar|pht|phps)$">
        Require all denied
    </FilesMatch>
</IfModule>
<IfModule !mod_authz_core.c>
	<FilesMatch "\.(?i:php|phtml|php3|php4|php5|php7|php8|phar|pht|phps)$">
		Order deny,allow
		Deny from all
	</FilesMatch>
</IfModule>';
			} else {
				$htAccessContent = '<IfModule mod_headers.c>
        Header set Vary "Accept-Encoding"
        Header set Cache-Control "max-age=10672000, must-revalidate"
</IfModule>
<IfModule mod_expires.c>
        ExpiresActive On
        ExpiresByType text/css A30672000
        ExpiresByType text/javascript A30672000
        ExpiresByType application/javascript A30672000
</IfModule>
<IfModule mod_deflate.c>
    <FilesMatch "\.(js|css)$">
        SetOutputFilter DEFLATE
    </FilesMatch>
</IfModule>
<IfModule mod_authz_core.c>
    <FilesMatch "\.(?i:php|phtml|php3|php4|php5|php7|php8|phar|pht|phps)$">
        Require all denied
    </FilesMatch>
</IfModule>
<IfModule !mod_authz_core.c>
	<FilesMatch "\.(?i:php|phtml|php3|php4|php5|php7|php8|phar|pht|phps)$">
		Order deny,allow
		Deny from all
	</FilesMatch>
</IfModule>';
			}

			@file_put_contents( $htAccess, $htAccessContent );
		}

		// All OK
		return true;
	}

	//      check dir cache
	static function checkCacheDir( $dir ) {
		// Check and create if not exists
		if ( ! file_exists( $dir ) ) {
			@mkdir( $dir, defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : 0775, true );
			if ( ! file_exists( $dir ) ) {
				return false;
			}
		}

		// check if we can now write
		if ( ! is_writable( $dir ) ) {
			return false;
		}

		// and write index.html here to avoid prying eyes
		$indexFile = $dir . '/index.html';
		if ( ! is_file( $indexFile ) ) {
			@file_put_contents( $indexFile, '<html><head><meta name="robots" content="noindex, nofollow"></head><body></body></html>' );
		}

		return true;
	}

	public static function clear_minification( $blog_id = null ) {
		if ( true === Breeze_CloudFlare_Helper::is_log_enabled() ) {
			error_log( '######### PURGE LOCAL CACHE MINIFICATION; ###: ' . var_export( 'true', true ) );
		}
		if ( is_multisite() && is_network_admin() ) {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $sites as $blog_id ) {
				switch_to_blog( $blog_id );
				self::clear_site_minification();
				restore_current_blog();
			}
		} else {
			self::clear_site_minification( $blog_id );
		}
		// Delete the stored minified files code hashes.
		delete_option( 'breeze_minified_hashes' );
	}

	public static function clear_site_minification( $blog_id_custom = null ) {
		if ( ! isset( $_GET['breeze_purge'] ) && ! Breeze_MinificationCache::create_cache_minification_folder() ) {
			return false;
		}
		// $start_time  = microtime( true );
		// $files_count = 0;
		$cache_folders = breeze_all_user_folders();

		if ( is_multisite() ) {
			if ( ! is_null( $blog_id_custom ) ) {
				$blog_id = absint( $blog_id_custom );
			} else {
				$blog_id = get_current_blog_id();
			}
			// Scan and clear each cache directory in a single pass — O(N).
			foreach ( $cache_folders as $user_folder ) {
				$user_path = BREEZE_MINIFICATION_CACHE . $blog_id . '/' . ( ! empty( $user_folder ) ? $user_folder . '/' : '' );

				foreach ( array( '', 'js', 'css' ) as $scandirName ) {
					$directory = $user_path . $scandirName;

					if ( ! is_dir( $directory ) ) {
						continue;
					}

					$files_list = scandir( $directory );
					if ( empty( $files_list ) ) {
						continue;
					}

					$thisAoCacheDir = rtrim( $directory, '/' ) . '/';

					foreach ( $files_list as $filename ) {
						if ( '.' === $filename || '..' === $filename ) {
							continue;
						}
						if ( ( strpos( $filename, 'lock' ) !== false || strpos( $filename, BREEZE_CACHEFILE_PREFIX ) !== false ) && is_file( $thisAoCacheDir . $filename ) ) {
							@unlink( $thisAoCacheDir . $filename );
							// $files_count++;
						}
					}
				}

				@unlink( $user_path . '.htaccess' );
				@unlink( $user_path . 'process.lock' );
			}
		} else {

			// Scan and clear each cache directory in a single pass — O(N).
			foreach ( $cache_folders as $user_folder ) {
				$user_path = BREEZE_MINIFICATION_CACHE . ( ! empty( $user_folder ) ? $user_folder . '/' : '' );

				foreach ( array( '', 'js', 'css' ) as $scandirName ) {
					$directory = $user_path . $scandirName;

					if ( ! is_dir( $directory ) ) {
						continue;
					}

					$files_list = scandir( $directory );
					if ( empty( $files_list ) ) {
						continue;
					}

					$thisAoCacheDir = rtrim( $directory, '/' ) . '/';

					foreach ( $files_list as $filename ) {
						if ( '.' === $filename || '..' === $filename ) {
							continue;
						}
						if ( ( strpos( $filename, 'lock' ) !== false || strpos( $filename, BREEZE_CACHEFILE_PREFIX ) !== false ) && is_file( $thisAoCacheDir . $filename ) ) {
							@unlink( $thisAoCacheDir . $filename );
							// $files_count++;
						}
					}
				}

				@unlink( $user_path . '.htaccess' );
				@unlink( $user_path . 'process.lock' );
			}
		}

		// $elapsed = round( microtime( true ) - $start_time, 4 );
		// error_log( "[Breeze] Minification cache cleared: {$files_count} files deleted in {$elapsed}s" );

		return true;
	}

	public static function factory() {

		static $instance;

		if ( ! $instance ) {
			$instance = new self();
			$instance->set_action();
		}

		return $instance;
	}
}
