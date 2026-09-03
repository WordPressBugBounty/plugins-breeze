<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Breeze_Store_Files
 *
 * Downloads and caches third-party files (Google Fonts, Google Tag Manager /
 * Analytics, and Facebook Pixel) locally under wp-content/uploads/breeze/.
 *
 * Every remote download performed by this class is restricted to a fixed
 * host allowlist per service, and the response body is validated against an
 * expected file signature before it is written to disk. This prevents a
 * compromised CDN, DNS hijack, or open-redirect from smuggling a PHP/ASP
 * payload onto the server disguised as a font, script, or stylesheet.
 */
class Breeze_Store_Files {

	var $store_files_dir = '/breeze/';

	/**
	 * Allowed remote hosts per "Host Files Locally" service.
	 *
	 * Only exact matches (or their listed subdomains) are permitted; a URL
	 * whose host is not present here is rejected before any request is made.
	 */
	private const ALLOWED_REMOTE_HOSTS = array(
		'google_fonts_css'  => array( 'fonts.googleapis.com' ),
		'google_fonts_file' => array( 'fonts.gstatic.com' ),
	);

	/**
	 * File extensions this class is ever allowed to write to disk.
	 */
	private const ALLOWED_FILE_EXTENSIONS = array( 'css', 'js', 'woff', 'woff2', 'ttf', 'otf', 'eot' );

	/**
	 * Magic-byte signatures for binary font formats, matched against the
	 * start of the downloaded response body.
	 */
	private const BINARY_FILE_SIGNATURES = array(
		'woff'  => 'wOFF',
		'woff2' => 'wOF2',
		'otf'   => 'OTTO',
	);

	public function init( $html = 0, $options = array() ) {

		if ( empty( $options ) || ! $html ) {
			return $html;
		}

		$wp_upload       = wp_upload_dir();
		$store_files_uri = $wp_upload['baseurl'] . $this->store_files_dir;
		$store_files_dir = $wp_upload['basedir'] . $this->store_files_dir;

		// Google Analytics and Facebook Pixel are no longer hosted locally; only
		// Google Fonts remain (see changelog 2.5.14).
		if ( ! empty( $options['breeze-store-googlefonts-locally'] ) ) {
			$this->ensure_uploads_breeze_directory_secured( $store_files_dir );
			$html = $this->extract_google_fonts_css( $html, $store_files_dir, $store_files_uri );
		}

		return $html;
	}

	/**
	 * Get all google fonts css files
	 *
	 * @param $html
	 *
	 * @return mixed
	 */
	public function extract_google_fonts_css( $html, $files_dir, $stored_files_uri ) {

		// Preset routes
		$files_dir        = $files_dir . 'google/fonts/';
		$stored_files_uri = $stored_files_uri . 'google/fonts/';

		// Extract Google Fonts declarations using link tags
		$pattern = '/<link[^>]+?href=["\'](https:\/\/fonts\.googleapis\.com\/css[^"\']+)["\'][^>]+?>/i';
		preg_match_all( $pattern, $html, $matches );

		$font_declarations = $matches[1];

		foreach ( $font_declarations as $font_url ) {

			// The regex above already anchors the URL to fonts.googleapis.com,
			// but re-validate explicitly in case the pattern is ever loosened.
			if ( ! $this->is_allowed_remote_host( $font_url, 'google_fonts_css' ) ) {
				continue;
			}

			$url_structured = parse_url( $font_url );
			parse_str( $url_structured['query'], $query );
			$font_title = explode( ':', $query['family'] );
			$font_title = explode( ' ', $font_title[0] );
			$font_title = strtolower( implode( '_', $font_title ) );

			$file_dir        = $files_dir . $font_title . '/';
			$stored_file_uri = $stored_files_uri . $font_title . '/';

			$font_api_response = wp_remote_get(
				esc_url_raw( $font_url ),
				array(
					'headers' => 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3',
				)
			);

			// Skip a non-200 response (e.g. an HTML error page) before it is treated as CSS.
			if ( 200 !== (int) wp_remote_retrieve_response_code( $font_api_response ) ) {
				continue;
			}

			$font_content = wp_remote_retrieve_body( $font_api_response );

			// Reject a response that does not look like CSS before it is
			// parsed further or ever written to disk.
			if ( ! $this->is_valid_downloaded_content( $font_content, 'fonts.css' ) ) {
				continue;
			}

			$local_css = $this->rewrite_google_fonts_files( $font_content, $font_title, $file_dir, $stored_file_uri );

			if ( $local_css ) {
				$html = str_replace( $font_url, $local_css, $html );
			}
		}

		return $html;
	}

	/**
	 * Rewriting .css file contents with local font files
	 *
	 * @param $css
	 * @param $font_title
	 * @param $file_dir
	 * @param $stored_files_uri
	 *
	 * @return false|string
	 */
	public function rewrite_google_fonts_files( $css, $font_title, $file_dir, $stored_files_uri ) {
		$font_declarations = array();

		// Extract Google Fonts declarations from css file
		$pattern = '/url\((https:\/\/fonts\.gstatic\.com\/[^)]+)\)/';
		preg_match_all( $pattern, $css, $font_declarations );

		foreach ( $font_declarations[0] as $font_declaration ) {

			$clean_font_url = str_replace( array( 'url(', ')' ), '', $font_declaration );
			$filename       = basename( $clean_font_url );

			$local_font_file = $this->download_files_locally( $clean_font_url, $file_dir, $filename, '.font', 'google_fonts_file' );

			if ( $local_font_file ) {
				$css = str_replace( $clean_font_url, $stored_files_uri . $filename, $css );
			}
		}

		$local_css_file_name = $font_title . '.css';
		$local_css_file_dir  = $file_dir . $local_css_file_name;
		$local_css_file_uri  = $stored_files_uri . $local_css_file_name;

		$wp_filesystem = breeze_get_filesystem();

		if ( $wp_filesystem->exists( $file_dir ) && $this->is_valid_downloaded_content( $css, $local_css_file_name ) ) {
			$wp_filesystem->put_contents( $local_css_file_dir, $css );
		}

		if ( $wp_filesystem->exists( $local_css_file_dir ) ) {
			return $local_css_file_uri;
		}

		return false;
	}

	// Helper Functions

	/**
	 * Ensures index.html and a PHP-deny .htaccess exist under uploads/breeze/.
	 *
	 * @param string $dir Absolute directory path (root or subfolder under uploads/breeze/).
	 *
	 * @return bool True when both protections are in place or were written successfully.
	 */
	private function ensure_uploads_breeze_directory_secured( string $dir ): bool {
		if ( ! function_exists( 'breeze_secure_cache_directory' ) ) {
			return false;
		}

		return breeze_secure_cache_directory( untrailingslashit( $dir ) );
	}

	/**
	 * Helper to download files locally (be it font or others).
	 *
	 * The remote host is checked against the {@see self::ALLOWED_REMOTE_HOSTS}
	 * allowlist for the given `$service`, and the response body is checked
	 * against an expected file signature before anything is written to disk.
	 *
	 * @param string $url       Remote file URL.
	 * @param string $file_dir  Local directory to store the file in.
	 * @param string $file_name Local file name.
	 * @param string $file_type Logical file type, used only to pick a cache lifetime
	 *                          ('.css' => 7 days, anything else => 30 days).
	 * @param string $service   Key into {@see self::ALLOWED_REMOTE_HOSTS} identifying
	 *                          which third-party service this download belongs to.
	 *                          Downloads are rejected when this does not match the
	 *                          allowlist for `$url`.
	 *
	 * @return bool True when the file already exists and is fresh, or was downloaded
	 *              and validated successfully. False otherwise.
	 */
	public function download_files_locally( $url, $file_dir, $file_name, $file_type = '.css', $service = '' ) {
		$wp_filesystem = breeze_get_filesystem();

		if ( ! $wp_filesystem->exists( $file_dir ) ) {
			// Use PHP filesystem function with FS_CHMOD_DIR. $wp_filesystem->mkdir does not support a recursive flag.
			mkdir( $file_dir, defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : 0775, true );
		}

		$this->ensure_uploads_breeze_directory_secured( $file_dir );

		$max_age_in_seconds = ( '.css' === $file_type ) ? 7 * 24 * 60 * 60 : 30 * 24 * 60 * 60;

		$local_file_path = $file_dir . $file_name;

		// Check if the file exists and is less than 1 week old.
		if (
			$wp_filesystem->exists( $local_file_path ) &&
			time() - filemtime( $local_file_path ) < $max_age_in_seconds
		) {
			return true; // File is already up-to-date
		}

		// Validate the url.
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		// Reject anything whose host is not on the allowlist for this service,
		// e.g. a redirect or a spoofed URL pointing away from the real CDN.
		if ( ! $this->is_allowed_remote_host( $url, $service ) ) {
			if ( defined( 'BREEZE_DEBUG' ) && true === BREEZE_DEBUG ) {
				error_log( sprintf( '[Breeze] Host Files Locally: rejected disallowed host for service "%s": %s', $service, $url ) ); // phpcs:ignore
			}
			return false;
		}

		$response = wp_safe_remote_get( esc_url_raw( $url ) );

		// Do not cache an error page (e.g. a Google 404) as if it were the asset.
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		// A 200 can still be an HTML error page. css/js have no magic bytes to
		// check, so verify the content-type instead. Fonts are checked by their
		// magic bytes in is_valid_downloaded_content().
		$extension    = strtolower( (string) pathinfo( $file_name, PATHINFO_EXTENSION ) );
		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$expected     = array( 'js' => 'javascript', 'css' => 'css' );
		if ( isset( $expected[ $extension ] ) && '' !== $content_type && false === strpos( $content_type, $expected[ $extension ] ) ) {
			return false;
		}

		$original_file_content = wp_remote_retrieve_body( $response );

		if ( ! $this->is_valid_downloaded_content( $original_file_content, $file_name ) ) {
			if ( defined( 'BREEZE_DEBUG' ) && true === BREEZE_DEBUG ) {
				error_log( sprintf( '[Breeze] Host Files Locally: rejected invalid content for "%s" from %s', $file_name, $url ) ); // phpcs:ignore
			}
			return false;
		}

		// Write the file.
		$result = $wp_filesystem->put_contents( $local_file_path, $original_file_content );

		// Check if file was created successfully.
		if (
			! $result ||
			! $wp_filesystem->exists( $local_file_path )
		) {
			return false;
		}

		return true;
	}

	/**
	 * Checks whether a URL's host is allowed for the given Host Files Locally service.
	 *
	 * @param string $url     The remote URL to check.
	 * @param string $service Key into {@see self::ALLOWED_REMOTE_HOSTS}.
	 *
	 * @return bool True when the URL's host matches the service's allowlist.
	 */
	private function is_allowed_remote_host( string $url, string $service ): bool {
		if ( ! isset( self::ALLOWED_REMOTE_HOSTS[ $service ] ) ) {
			return false;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( empty( $host ) ) {
			return false;
		}

		return in_array( $host, self::ALLOWED_REMOTE_HOSTS[ $service ], true );
	}

	/**
	 * Validates downloaded remote content before it is written to disk.
	 *
	 * Combines an extension allowlist with a magic-byte / signature check so a
	 * compromised CDN, DNS hijack, or open redirect cannot smuggle a PHP/ASP
	 * payload onto the server disguised as a font, script, or stylesheet.
	 *
	 * @param string $content   Raw response body (or composed content, e.g. rewritten CSS).
	 * @param string $file_name Target file name; its extension determines the expected format.
	 *
	 * @return bool True when the content matches the expected file type.
	 */
	private function is_valid_downloaded_content( $content, string $file_name ): bool {
		if ( ! is_string( $content ) || '' === $content ) {
			return false;
		}

		$extension = strtolower( (string) pathinfo( $file_name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, self::ALLOWED_FILE_EXTENSIONS, true ) ) {
			return false;
		}

		// Reject a payload embedding a PHP/ASP opening tag regardless of the
		// declared extension; this is the primary guard for text formats
		// (css/js) where a binary signature check does not apply.
		if ( preg_match( '/<\?php|<\?=|<%/i', substr( $content, 0, 4096 ) ) ) {
			return false;
		}

		if ( isset( self::BINARY_FILE_SIGNATURES[ $extension ] ) ) {
			return 0 === strpos( $content, self::BINARY_FILE_SIGNATURES[ $extension ] );
		}

		if ( 'ttf' === $extension ) {
			$header = substr( $content, 0, 4 );
			return in_array( $header, array( "\x00\x01\x00\x00", 'true', 'ttcf' ), true );
		}

		// css, js, and eot: the extension allowlist plus the PHP/ASP-tag
		// rejection above are the applicable checks for these formats.
		return true;
	}

	/**
	 * Function to delete temporary files
	 *
	 * @return bool
	 */
	public static function cleanup_all_extra_folder() {
		$wp_filesystem = breeze_get_filesystem();

		$ret = true;

		$folder = untrailingslashit( WP_CONTENT_DIR ) . '/uploads/breeze';

		if ( ! $wp_filesystem->delete( $folder, true ) ) {
			$ret = false;
		}

		/**
		 * For cache folder we only delete specific folders since the folder is being used by other plugins.
		 */
		$folder = untrailingslashit( WP_CONTENT_DIR ) . '/cache/breeze'; // HTML cache

		if ( ! $wp_filesystem->delete( $folder, true ) ) {
			$ret = false;
		}

		$folder = untrailingslashit( WP_CONTENT_DIR ) . '/cache/breeze-extra'; // Gravatars.

		if ( ! $wp_filesystem->delete( $folder, true ) ) {
			$ret = false;
		}

		$folder = untrailingslashit( WP_CONTENT_DIR ) . '/cache/breeze-minification'; // CSS/JS minified files.

		if ( ! $wp_filesystem->delete( $folder, true ) ) {
			$ret = false;
		}

		$folder = untrailingslashit( WP_CONTENT_DIR ) . '/cache/uncss'; // Clean of unneeded CSS.

		if ( ! $wp_filesystem->delete( $folder, true ) ) {
			$ret = false;
		}

		$folder = untrailingslashit( WP_CONTENT_DIR ) . '/cache'; // Cache folder in wp-content
		if ( true === breeze_is_folder_empty( $folder ) ) {
			$wp_filesystem->delete( $folder, true );
		}

		return $ret;
	}
}
