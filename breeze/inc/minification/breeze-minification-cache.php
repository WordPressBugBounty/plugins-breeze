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

	/**
	 * Short fingerprint of a bundle's final bytes, appended to its filename.
	 *
	 * Bundle names used to be derived from the page alone, so a content change
	 * overwrote the existing file in place. With the fingerprint in the name,
	 * identical output always resolves to the same file (nothing is rewritten)
	 * and changed output is written under a NEW name instead of replacing the
	 * old one. HTML still held in the page/Varnish/CDN cache therefore keeps
	 * pointing at the exact file it was rendered with, which stays on disk.
	 *
	 * @param string $content Final bundle content, exactly as it will be written.
	 *
	 * @return string 12 hex characters.
	 */
	public static function content_hash( $content ) {
		return substr( hash( 'sha256', (string) $content ), 0, 12 );
	}

	/**
	 * Whether a fingerprinted bundle is already on disk and worth reusing.
	 *
	 * check() is satisfied by a zero-byte file, which a crashed or half-finished
	 * write can leave behind. Callers keyed on content used to be rescued by the
	 * stored-hash comparison forcing a rewrite; they no longer are, so an empty
	 * file has to be treated as a miss or it would never be repaired.
	 *
	 * @return bool
	 */
	public function has_usable_copy() {
		if ( ! $this->check() ) {
			return false;
		}

		$size = @filesize( $this->cachedir . $this->filename );

		return ( false !== $size && $size > 0 );
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
				self::mark_purge();
				restore_current_blog();
			}
		} else {
			self::clear_site_minification( $blog_id );
			self::mark_purge();
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
						// Match the lock file EXACTLY. A substring test on 'lock'
						// false-matches real bundles whose asset name contains it
						// (e.g. "jquery.blockUI.min.js", "wc-blocks.css"), which
						// then get deleted on every purge and 404 until re-render.
						$is_lock   = ( 'process.lock' === $filename || '.lock' === substr( $filename, -5 ) );
						$is_bundle = ( 0 === strpos( $filename, BREEZE_CACHEFILE_PREFIX ) );

						// Keep the served minified JS/CSS bundles on purge. Pages
						// already loaded in a browser (or held at the Varnish/CDN
						// edge) keep requesting these files; deleting them here is
						// what produced the intermittent 404s. Bundle names carry
						// a fingerprint of their content, so a change writes a new
						// file rather than needing the old one gone. The leftovers
						// are reclaimed a grace window later by maybe_sweep_orphans()
						// and immediately when their post is trashed or deleted.
						if ( $is_bundle && ! $is_lock && in_array( $scandirName, array( 'js', 'css' ), true ) ) {
							continue;
						}

						if ( ( $is_lock || $is_bundle ) && is_file( $thisAoCacheDir . $filename ) ) {
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
						// Match the lock file EXACTLY. A substring test on 'lock'
						// false-matches real bundles whose asset name contains it
						// (e.g. "jquery.blockUI.min.js", "wc-blocks.css"), which
						// then get deleted on every purge and 404 until re-render.
						$is_lock   = ( 'process.lock' === $filename || '.lock' === substr( $filename, -5 ) );
						$is_bundle = ( 0 === strpos( $filename, BREEZE_CACHEFILE_PREFIX ) );

						// Keep the served minified JS/CSS bundles on purge. Pages
						// already loaded in a browser (or held at the Varnish/CDN
						// edge) keep requesting these files; deleting them here is
						// what produced the intermittent 404s. Bundle names carry
						// a fingerprint of their content, so a change writes a new
						// file rather than needing the old one gone. The leftovers
						// are reclaimed a grace window later by maybe_sweep_orphans()
						// and immediately when their post is trashed or deleted.
						if ( $is_bundle && ! $is_lock && in_array( $scandirName, array( 'js', 'css' ), true ) ) {
							continue;
						}

						if ( ( $is_lock || $is_bundle ) && is_file( $thisAoCacheDir . $filename ) ) {
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

	/**
	 * Remove the minified bundles that belong to a single post.
	 *
	 * Called only when a post is trashed or permanently deleted — the one
	 * moment we can be certain that no page (the origin HTML cache or the
	 * Varnish/CDN edge) will ever request that post's bundles again. Bundle
	 * filenames embed the post identity "<sanitized-url>-<blog>-<post_id>"
	 * (see Breeze_MinificationBase::create_cache_file_name()), so we delete
	 * only the files carrying THIS post's exact identity and leave every other
	 * page's bundles untouched.
	 *
	 * We never delete bundles by file age: a stable third-party asset keeps an
	 * old mtime precisely because its content never changes, yet it stays
	 * actively referenced — age-based deletion of such files is what caused the
	 * intermittent 404s under concurrency.
	 *
	 * @param int $post_id Post being trashed or deleted.
	 *
	 * @return void
	 */
	public static function delete_post_minification( $post_id ) {
		$post_id = absint( $post_id );
		if ( empty( $post_id ) || ! defined( 'BREEZE_MINIFICATION_CACHE' ) ) {
			return;
		}

		// Revisions/autosaves have no bundles of their own; skip the scan so
		// bulk revision deletes stay cheap.
		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$blog_id    = get_current_blog_id();
		$identities = self::post_identity_fragments( $post_id, $blog_id );
		if ( empty( $identities ) ) {
			return;
		}

		// Everything we touch must stay strictly inside the cache directory.
		// realpath() drops the trailing separator, so the comparison below adds
		// one back: without it a sibling folder whose name merely begins with
		// the cache path (e.g. breeze-minification-evil) would pass. Both sides
		// are normalised first so the appended '/' matches on Windows too.
		$root = realpath( BREEZE_MINIFICATION_CACHE );
		if ( false === $root ) {
			return;
		}

		$root = trailingslashit( wp_normalize_path( $root ) );

		$base = BREEZE_MINIFICATION_CACHE;
		if ( is_multisite() ) {
			$base .= $blog_id . '/';
		}

		$cache_folders = function_exists( 'breeze_all_user_folders' ) ? breeze_all_user_folders() : array( '' );

		foreach ( $cache_folders as $user_folder ) {
			$user_path = $base . ( ! empty( $user_folder ) ? $user_folder . '/' : '' );

			foreach ( array( 'js', 'css' ) as $sub ) {
				$dir = $user_path . $sub . '/';
				if ( ! is_dir( $dir ) ) {
					continue;
				}
				$files = @scandir( $dir );
				if ( empty( $files ) ) {
					continue;
				}
				foreach ( $files as $filename ) {
					if ( '.' === $filename || '..' === $filename ) {
						continue;
					}
					// Only Breeze bundles that carry this post's identity.
					if ( 0 !== strpos( $filename, BREEZE_CACHEFILE_PREFIX ) ) {
						continue;
					}
					if ( ! self::filename_belongs_to_post( $filename, $identities ) ) {
						continue;
					}

					$path = $dir . $filename;
					// Defense-in-depth: never let a crafted name escape the dir.
					$real = realpath( $path );
					if ( false === $real || 0 !== strpos( wp_normalize_path( $real ), $root ) ) {
						continue;
					}
					if ( is_file( $real ) ) {
						@unlink( $real );
					}
				}
			}
		}
	}

	/**
	 * Build the identity fragments that a post's bundle filenames contain.
	 *
	 * Mirrors Breeze_MinificationBase::create_cache_file_name(): the base name
	 * is "sanitize_title(<permalink-path>)-<blog>-<post_id>". Group bundles use
	 * the full slug; non-group per-file bundles truncate the slug to 50 chars,
	 * so both variants are returned. Matching is fail-safe: if the permalink
	 * cannot be resolved we return nothing and therefore delete nothing.
	 *
	 * @param int $post_id Post ID.
	 * @param int $blog_id Blog ID.
	 *
	 * @return array List of "<slug>-<blog>-<post_id>" fragments (may be empty).
	 */
	private static function post_identity_fragments( $post_id, $blog_id ) {
		$permalink = get_permalink( $post_id );
		if ( false === $permalink ) {
			return array();
		}

		// A trashed post's permalink carries "__trashed"; strip it so the slug
		// matches what was written at render time.
		$permalink = str_replace( '__trashed', '', urldecode( $permalink ) );

		$home = untrailingslashit( urldecode( get_home_url( $blog_id ) ) );
		$path = str_replace( $home, '', $permalink );

		$slug = sanitize_title( $path );
		if ( '' === $slug ) {
			$slug = sanitize_title( $home );
		}
		$slug = str_replace( array( 'http-', 'https-' ), '', $slug );
		if ( '' === $slug ) {
			return array();
		}

		$suffix    = '-' . $blog_id . '-' . $post_id;
		$fragments = array( $slug . $suffix );

		// Non-group bundles truncate the slug to 50 chars before the suffix.
		$slug_50 = substr( $slug, 0, 50 );
		if ( $slug_50 !== $slug ) {
			$fragments[] = $slug_50 . $suffix;
		}

		return array_values( array_unique( $fragments ) );
	}

	/**
	 * True when $filename carries one of the post's identity fragments as a
	 * bounded token — i.e. immediately followed by "-" (per-file bundles),
	 * "." (group bundles and .none/.deflate/.gzip sidecars) or end of string.
	 * The boundary check stops a slug that merely ends in similar digits from
	 * matching, and because the fragment includes the post-specific slug it
	 * cannot collide with another page's bundles.
	 *
	 * @param string $filename   Bundle filename (basename).
	 * @param array  $identities Fragments from post_identity_fragments().
	 *
	 * @return bool
	 */
	private static function filename_belongs_to_post( $filename, array $identities ) {
		foreach ( $identities as $identity ) {
			$len = strlen( $identity );
			$pos = strpos( $filename, $identity );
			while ( false !== $pos ) {
				$next = $filename[ $pos + $len ] ?? '';
				if ( '-' === $next || '.' === $next || '' === $next ) {
					return true;
				}
				$pos = strpos( $filename, $identity, $pos + 1 );
			}
		}

		return false;
	}

	/**
	 * Whether renders should stamp the bundles they reference as "used now".
	 *
	 * The stamp is the file's mtime (see the @touch calls in the scripts and
	 * styles cache() methods). It is the only evidence the cleanup below has
	 * that a bundle is still reachable from a live page, so it stays on unless
	 * a site opts out of automatic cleanup entirely.
	 *
	 * @return bool
	 */
	public static function track_bundle_usage() {
		return (bool) apply_filters( 'breeze_minify_track_bundle_usage', true );
	}

	/**
	 * Records the moment at which every cached page was invalidated.
	 *
	 * A purge drops the HTML cache, Varnish and the CDN, so from this instant
	 * onwards no page being generated can still name a bundle it isn't linking
	 * to. That makes the purge a far better reference point for cleanup than
	 * plain file age: a stable asset (jQuery, WooCommerce, ...) is written once
	 * and reused forever, so "old file" never meant "unused file", and deleting
	 * on age is exactly what produced the intermittent 404s.
	 *
	 * @return void
	 */
	public static function mark_purge() {
		$now = time();
		update_option( 'breeze_minify_purged_at', $now, false );

		// Set an alarm for when the waiting time is up, so the cleanup happens
		// on its own instead of sitting idle until someone opens a page. It gets
		// its own alarm name because WordPress ignores a new one-time alarm if a
		// similar one is already set for the next ten minutes, and the hourly
		// cleanup alarm would make it do exactly that.
		wp_clear_scheduled_hook( 'breeze_minify_sweep_now' );
		wp_schedule_single_event( $now + self::purge_grace(), 'breeze_minify_sweep_now' );

		// Start listening again: the renders that follow decide whether the
		// warning below is still deserved.
		delete_option( 'breeze_minify_unwritable_file' );
	}

	/**
	 * Records a bundle whose last-used stamp could not be written.
	 *
	 * The file belongs to a different user than the one PHP runs as, so the
	 * cleanup cannot tell it is still in use and will eventually delete it
	 * from under the pages that link to it. Kept until the next purge, which
	 * is when the check starts over, and shown as an admin notice by
	 * Breeze_File_Permissions.
	 *
	 * @param string $path Bundle that could not be stamped.
	 *
	 * @return void
	 */
	public static function record_unwritable_file( $path = '' ) {
		if ( '' === (string) $path || '' !== self::unwritable_file() ) {
			return;
		}

		update_option( 'breeze_minify_unwritable_file', (string) $path, false );
	}

	/**
	 * The bundle that could not be stamped since the last purge, if any.
	 *
	 * @return string Empty string while every bundle can be stamped.
	 */
	public static function unwritable_file() {
		return (string) get_option( 'breeze_minify_unwritable_file', '' );
	}

	/**
	 * How long after a purge its orphaned bundles may be reclaimed.
	 *
	 * HTML routinely outlives the purge meant to kill it: a CDN purge
	 * propagates asynchronously, a browser can already hold the markup while
	 * its asset requests are still queued, and back/forward navigation replays
	 * a page from the browser's own cache. The grace window lets all of that
	 * drain before anything is deleted.
	 *
	 * @return int Seconds.
	 */
	public static function purge_grace() {
		return (int) apply_filters( 'breeze_minify_purge_grace', 1 * MINUTE_IN_SECONDS );
	}

	/**
	 * Reclaims the bundles the last purge orphaned, once that is safe to do.
	 *
	 * Runs once per purge, a full grace window after it, and deletes only
	 * bundles that no render has referenced since that purge. Anything still
	 * reachable was stamped by the render that linked to it, so it survives;
	 * anything left behind was invalidated along with every page that could
	 * have named it and has had no taker since.
	 *
	 * @return void
	 */
	public static function maybe_sweep_orphans() {
		if ( ! self::track_bundle_usage() ) {
			return;
		}

		$purged_at = (int) get_option( 'breeze_minify_purged_at', 0 );
		if ( $purged_at <= 0 ) {
			return;
		}

		// Already reclaimed for this purge — nothing else can have expired
		// since, because mtimes only ever move forward.
		if ( (int) get_option( 'breeze_minify_swept_at', 0 ) >= $purged_at ) {
			return;
		}

		if ( ( time() - $purged_at ) < self::purge_grace() ) {
			return;
		}

		// Claim the slot up-front so concurrent requests don't all sweep.
		update_option( 'breeze_minify_swept_at', $purged_at, false );

		self::sweep_orphans( $purged_at );
	}

	/**
	 * Delete breeze_ bundles that no render has touched since $purged_at.
	 *
	 * @param int $purged_at Timestamp of the purge being reclaimed.
	 *
	 * @return void
	 */
	private static function sweep_orphans( $purged_at ) {
		if ( ! defined( 'BREEZE_MINIFICATION_CACHE' ) || $purged_at <= 0 ) {
			return;
		}

		// Let the delete loop finish even if the visitor closed the tab (only
		// relevant to the shutdown path; harmless under WP-Cron).
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		// realpath() drops the trailing separator, so the comparison below adds
		// one back: without it a sibling folder whose name merely begins with
		// the cache path (e.g. breeze-minification-evil) would pass. Both sides
		// are normalised first so the appended '/' matches on Windows too.
		$root = realpath( BREEZE_MINIFICATION_CACHE );
		if ( false === $root ) {
			return;
		}

		$root = trailingslashit( wp_normalize_path( $root ) );

		$base = BREEZE_MINIFICATION_CACHE;
		if ( is_multisite() ) {
			$base .= get_current_blog_id() . '/';
		}

		$cache_folders = function_exists( 'breeze_all_user_folders' ) ? breeze_all_user_folders() : array( '' );

		foreach ( $cache_folders as $user_folder ) {
			$user_path = $base . ( ! empty( $user_folder ) ? $user_folder . '/' : '' );

			foreach ( array( 'js', 'css' ) as $sub ) {
				$dir = $user_path . $sub . '/';
				if ( ! is_dir( $dir ) ) {
					continue;
				}
				$files = @scandir( $dir );
				if ( empty( $files ) ) {
					continue;
				}
				foreach ( $files as $filename ) {
					if ( '.' === $filename || '..' === $filename ) {
						continue;
					}
					// Only Breeze bundles.
					if ( 0 !== strpos( $filename, BREEZE_CACHEFILE_PREFIX ) ) {
						continue;
					}
					$path = $dir . $filename;
					$real = realpath( $path );
					if ( false === $real || 0 !== strpos( wp_normalize_path( $real ), $root ) ) {
						continue;
					}
					if ( ! is_file( $real ) ) {
						continue;
					}
					$mtime = @filemtime( $real );
					if ( false !== $mtime && $mtime < $purged_at ) {
						@unlink( $real );
					}
				}
			}
		}
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

// This is what the alarm set in mark_purge() actually runs when the waiting time
// is up. It lives here next to the code that sets the alarm, and it works because
// this file is loaded on every request, scheduled background jobs included.
if ( function_exists( 'add_action' ) ) {
	add_action( 'breeze_minify_sweep_now', array( 'Breeze_MinificationCache', 'maybe_sweep_orphans' ) );
}
