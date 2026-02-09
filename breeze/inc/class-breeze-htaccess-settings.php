<?php
if ( ! defined( 'ABSPATH' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	exit;
}

/**
 * Class Breeze_Htaccess_Settings
 *
 * Handles the generation and management of .htaccess rules for Gzip compression
 * and browser caching. It also manages configurations for multisite and single site setups.
 */
class Breeze_Htaccess_Settings {

	private $sub_sites_data = array();

	public function __construct() {

	}

	/**
	 * Updates the .htaccess file with Gzip compression rules.
	 *
	 * @param bool $clean Determines whether the Gzip rules should be removed from the .htaccess file.
	 *                    If true, the existing Gzip rules will be deleted.
	 *
	 * @return void
	 */
	public function update_gzip_htaccess( bool $clean = false ) {
		$rules           = array();
		$rules['before'] = '# Begin GzipofBreezeWPCache'; // used to identify start of the code.
		$rules['after']  = '# End GzipofBreezeWPCache'; // used to identify the end of the code.
		$rules['clean']  = $clean;

		if ( true === $clean ) {
			self::write_to_htaccess( $rules );
		}

		$extract_sub_blogs = $this->collect_site_configurations();

		// For multisite with different settings
		$content = $this->generate_gzip_rules(
			array(
				'enabled_sites'  => $extract_sub_blogs['gzip_enabled_sites'],
				'disabled_sites' => $extract_sub_blogs['gzip_disabled_sites'],
			)
		);
		// This content will be written to .htaccess.
		$rules['content'] = $content;

		// Then use your existing method or a new one to write the rules to .htaccess
		self::write_to_htaccess( $rules );
	}

	/**
	 * Updates the .htaccess file with expires headers configuration.
	 *
	 * @param bool $clean Whether to clean existing expires rules before updating. When true, existing entries for expires headers will be removed.
	 *
	 * @return void
	 */
	public function update_expires_htaccess( bool $clean = false ) {
		$rules           = array();
		$rules['before'] = '#Expires headers configuration added by BREEZE WP CACHE plugin'; // used to identify start of the code.
		$rules['after']  = '#End of expires headers configuration'; // used to identify the end of the code.
		$rules['clean']  = $clean;

		if ( true === $clean ) {
			self::write_to_htaccess( $rules );
		}

		$extract_sub_blogs = $this->collect_site_configurations();

		// For multisite with different settings
		$content = $this->generate_expires_rules(
			array(
				'enabled_sites'  => $extract_sub_blogs['expires_enabled_sites'],
				'disabled_sites' => $extract_sub_blogs['expires_disabled_sites'],
			)
		);
		// This content will be written to .htaccess.
		$rules['content'] = $content;

		// Then use your existing method or a new one to write the rules to .htaccess
		self::write_to_htaccess( $rules );
	}

	/**
	 * Converts a given value to a boolean.
	 *
	 * @param mixed $value The value to be converted to a boolean
	 *
	 * @return bool The boolean representation of the value
	 */
	private function to_bool( $value ): bool {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Collects and organizes sites based on their gzip compression settings
	 *
	 * @return array{
	 *     enabled_sites: array<string>,
	 *     disabled_sites: array<string>,
	 *     is_subdomains: bool,
	 *     is_multisite: bool
	 * }
	 */
	public function collect_site_configurations(): array {
		if ( ! empty( $this->sub_sites_data ) ) {
			return $this->sub_sites_data;
		}

		$site_config      = array(
			'gzip_enabled_sites'     => array(),
			'gzip_disabled_sites'    => array(),
			'expires_enabled_sites'  => array(),
			'expires_disabled_sites' => array(),
			'is_subdomains'          => defined( 'SUBDOMAIN_INSTALL' ) && SUBDOMAIN_INSTALL,
			'is_multisite'           => is_multisite(),
		);
		$is_breeze_active = Breeze_Options_Reader::get_option_value( 'breeze-active' );

		// Single site handling
		if ( false === $site_config['is_multisite'] ) {
			$gzip_enabled    = Breeze_Options_Reader::get_option_value( 'breeze-gzip-compression' );
			$expires_enabled = Breeze_Options_Reader::get_option_value( 'breeze-browser-cache' );

			$is_breeze_active = $this->to_bool( $is_breeze_active );
			$gzip_enabled     = $this->to_bool( $gzip_enabled );
			$expires_enabled  = $this->to_bool( $expires_enabled );

			$site_url = preg_replace( '(^https?://)', '', site_url() );

			if ( true === $is_breeze_active ) {
				if ( true === $gzip_enabled ) {
					$site_config['gzip_enabled_sites'][] = $site_url;
				} else {
					$site_config['gzip_disabled_sites'][] = $site_url;
				}

				if ( true === $expires_enabled ) {
					$site_config['expires_enabled_sites'][] = $site_url;
				} else {
					$site_config['expires_disabled_sites'][] = $site_url;
				}
			}
			$this->sub_sites_data = $site_config;

			return $site_config;
		}

		// Multisite handling
		$sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		// Store current blog ID to restore later
		$current_blog_id = get_current_blog_id();

		foreach ( $sites as $blog_id ) {
			switch_to_blog( $blog_id );

			$site_url = preg_replace( '(^https?://)', '', site_url() );

			if ( false === $site_config['is_subdomains'] ) {
				$site_url_network = preg_replace( '(^https?://)', '', untrailingslashit( network_site_url() ) );
				$site_url         = str_replace( $site_url_network, '', $site_url );
				$site_url         = empty( $site_url ) ? '/' : $site_url;
			}

			$is_breeze_active = $this->get_option_value( 'breeze-active', $blog_id );

			if ( true === $is_breeze_active ) {
				// Check if gzip is enabled

				$gzip_enabled    = $this->get_option_value( 'breeze-gzip-compression', $blog_id );
				$expires_enabled = $this->get_option_value( 'breeze-browser-cache', $blog_id );

				$gzip_enabled    = $this->to_bool( $gzip_enabled );
				$expires_enabled = $this->to_bool( $expires_enabled );

				if ( true === $gzip_enabled ) {
					$site_config['gzip_enabled_sites'][] = $site_url;
				} else {
					$site_config['gzip_disabled_sites'][] = $site_url;
				}

				if ( true === $expires_enabled ) {
					$site_config['expires_enabled_sites'][] = $site_url;
				} else {
					$site_config['expires_disabled_sites'][] = $site_url;
				}
			} else {
				$site_config['gzip_disabled_sites'][]    = $site_url;
				$site_config['expires_disabled_sites'][] = $site_url;
			}
		}

		// Restore the original blog
		switch_to_blog( $current_blog_id );
		$this->sub_sites_data = $site_config;

		return $site_config;
	}

	/**
	 * Retrieves the value of a specified option for a blog, considering inheritance settings.
	 *
	 * @param string $option_name The name of the option to retrieve.
	 * @param int $blog_id The ID of the blog for which to fetch the option value.
	 *
	 * @return bool The boolean representation of the option value.
	 */
	private function get_option_value( string $option_name, int $blog_id ): bool {
		$blog_id = intval( $blog_id );

		$get_inherit = get_blog_option( $blog_id, 'breeze_inherit_settings', '1' );
		$is_custom   = filter_var( $get_inherit, FILTER_VALIDATE_BOOLEAN );
		// Settings are inherited.
		if ( true === $is_custom ) {
			// Retrieve the network-wide option if inheritance is disabled.
			$get_option_group = get_network_option( null, 'breeze_basic_settings', array() );
			if ( array_key_exists( $option_name, $get_option_group ) ) {
				return $this->to_bool( $get_option_group[ $option_name ] );
			}
		}

		// Fetch the option value for a specific blog ID.
		$get_option_group = get_blog_option( $blog_id, 'breeze_basic_settings', array() );
		if ( array_key_exists( $option_name, $get_option_group ) ) {
			return $this->to_bool( $get_option_group[ $option_name ] );
		}

		return false;
	}

	/**
	 * Generate browser cache rules using negation with line breaks
	 *
	 * @param array $sites Array with 'enabled_sites' and 'disabled_sites'
	 *
	 * @return string Generated htaccess rules
	 */
	private function generate_expires_rules( array $sites ): string {
		$indent = '    ';
		$result = array();

		// If there are no disabled sites, apply caching to all
		if ( empty( $sites['disabled_sites'] ) ) { // enabled_sites
			$result[] = '#Expires headers configuration added by BREEZE WP CACHE plugin';
			$result[] = $indent . $this->get_expires_directives( 1 );
			$result[] = '#End of expires headers configuration';

			return implode( "\n", $result );
		}

		// If there are some disabled sites, use negation
		// Split into chunks with line breaks for readability
		$chunks   = $this->chunk_pattern_for_negation( $sites['disabled_sites'], $indent );
		$result[] = '#Expires headers configuration added by BREEZE WP CACHE plugin';
		$result[] = $indent . '<If "' . $chunks . '">';
		$result[] = $indent . $indent . '# Browser cache directives for all sites except those excluded';
		$result[] = $this->get_expires_directives( 2 );
		$result[] = $indent . '</If>';
		$result[] = '#End of expires headers configuration';

		return implode( "\n", $result );
	}

	/**
	 * Generate GZIP compression rules using negation with line breaks
	 *
	 * @param array $sites Array with 'enabled_sites' and 'disabled_sites'
	 *
	 * @return string Generated htaccess rules
	 */
	private function generate_gzip_rules( array $sites ): string {
		$indent = '    ';
		$result = array();

		// If there are no disabled sites, apply gzip to all
		if ( empty( $sites['disabled_sites'] ) ) {
			$result[] = '# Begin GzipofBreezeWPCache';
			$result[] = '<IfModule mod_deflate.c>';
			$result[] = $indent . '# Enable GZIP compression for all sites';
			$result[] = $indent . $this->get_gzip_directives( 1 );
			$result[] = '</IfModule>';
			$result[] = '# End GzipofBreezeWPCache';

			return implode( "\n", $result );
		}

		// Split into chunks with line breaks for readability
		$chunks   = $this->chunk_pattern_for_negation( $sites['disabled_sites'], $indent );
		$result[] = '# Begin GzipofBreezeWPCache';
		$result[] = '<IfModule mod_deflate.c>';
		$result[] = $indent . '<If "' . $chunks . '">';
		$result[] = $indent . $indent . '# Enable GZIP compression for all sites except those excluded';
		$result[] = $this->get_gzip_directives( 2 );
		$result[] = $indent . '</If>';
		$result[] = '</IfModule>';
		$result[] = '# End GzipofBreezeWPCache';

		return implode( "\n", $result );
	}

	/**
	 * Chunks a long pattern list for negation into a manageable format with line breaks
	 *
	 * @param array $patterns Site patterns to negate
	 * @param string $indent Indentation string
	 *
	 * @return string Complex condition string with line breaks
	 */
	private function chunk_pattern_for_negation( array $patterns, string $indent = '    ' ): string {
		if ( empty( $patterns ) ) {
			return '';
		}

		// Escape special regex characters in each pattern
		$escaped_patterns = array_map(
			function ( $pattern ) {
				// Escape dots, hyphens, and other special regex characters
				return preg_replace( '/([\.|\-|\+|\?|\[|\]|\(|\)|\{|\}|\^|\$|\|])/', '\\\\$1', $pattern );
			},
			$patterns
		);

		// Split patterns into chunks to avoid line length issues
		$chunks          = array_chunk( $escaped_patterns, 30 );
		$condition_parts = array();

		foreach ( $chunks as $chunk ) {
			if ( is_multisite() ) {
				$subdomain = defined( 'SUBDOMAIN_INSTALL' ) && SUBDOMAIN_INSTALL;
				if ( false === $subdomain ) {
					$condition_parts[] = '%{THE_REQUEST} !~ m#^GET (' . implode( '|', $chunk ) . ')#';
				} else {
					$condition_parts[] = '%{HTTP_HOST} !~ m#^(' . implode( '|', $chunk ) . ')#';
				}
			} else {
				$condition_parts[] = '%{THE_REQUEST} !~ m#^GET (' . implode( '|', $chunk ) . ')#';
			}
		}

		// The first condition
		$first  = array_shift( $condition_parts );
		$result = $first;

		// For additional conditions, add line breaks and indentation
		foreach ( $condition_parts as $part ) {
			$result .= ' && \\' . PHP_EOL . $indent . $indent . $part;
		}

		return $result;
	}

	/**
	 * Generates combined mod_deflate and browser cache configuration
	 *
	 * @param int $indent_level Number of indentation levels
	 *
	 * @return string Generated .htaccess rules for compression and caching
	 */
	private function get_expires_directives( int $indent_level = 1 ): string {
		$indent = str_repeat( '    ', $indent_level );

		$directives = array(
			'# Browser-specific compression rules',
			'# Browser cache TTL',
			'<IfModule mod_env.c>',
			'   SetEnv BREEZE_BROWSER_CACHE_ON 1',
			'</IfModule>',
			'<IfModule mod_expires.c>',
			'    ExpiresActive On',
			'    # Images',
			'    ExpiresByType image/jpg "access plus 1 year"',
			'    ExpiresByType image/jpeg "access plus 1 year"',
			'    ExpiresByType image/gif "access plus 1 year"',
			'    ExpiresByType image/png "access plus 1 year"',
			'    ExpiresByType image/webp "access plus 1 year"',
			'    ExpiresByType image/svg+xml "access plus 1 year"',
			'    ExpiresByType image/x-icon "access plus 1 year"',
			'    ExpiresByType image/bmp "access plus 1 year"',
			'',
			'    # Video',
			'    ExpiresByType video/mp4 "access plus 1 year"',
			'    ExpiresByType video/mpeg "access plus 1 year"',
			'    ExpiresByType video/ogg "access plus 1 year"',
			'    ExpiresByType video/webm "access plus 1 year"',
			'',
			'    # Audio',
			'    ExpiresByType audio/ogg "access plus 1 year"',
			'',
			'    # CSS, JavaScript',
			'    ExpiresByType text/css "access plus 1 month"',
			'    ExpiresByType text/javascript "access plus 1 month"',
			'    ExpiresByType application/javascript "access plus 1 month"',
			'    ExpiresByType application/x-javascript "access plus 1 month"',
			'',
			'    # Fonts',
			'    ExpiresByType application/x-font-ttf "access plus 1 year"',
			'    ExpiresByType application/x-font-woff "access plus 1 year"',
			'    ExpiresByType application/font-woff2 "access plus 1 year"',
			'    ExpiresByType font/ttf "access plus 1 year"',
			'    ExpiresByType font/woff "access plus 1 year"',
			'    ExpiresByType font/woff2 "access plus 1 year"',
			'    ExpiresByType application/vnd.ms-fontobject "access plus 1 year"',
			'    ExpiresByType font/eot "access plus 1 year"',
			'    ExpiresByType font/opentype "access plus 1 year"',
			'    ExpiresByType application/font-woff "access plus 1 year"',
			'',
			'    # Data interchange',
			'    ExpiresByType application/xml "access plus 0 seconds"',
			'    ExpiresByType application/json "access plus 0 seconds"',
			'    ExpiresByType application/ld+json "access plus 0 seconds"',
			'    ExpiresByType application/schema+json "access plus 0 seconds"',
			'    ExpiresByType application/vnd.geo+json "access plus 0 seconds"',
			'    ExpiresByType text/xml "access plus 0 seconds"',
			'    ExpiresByType application/rdf+xml "access plus 1 hour"',
			'',
			'    # Manifest files',
			'    ExpiresByType application/manifest+json "access plus 1 week"',
			'    ExpiresByType application/x-web-app-manifest+json "access plus 0 seconds"',
			'    ExpiresByType text/cache-manifest  "access plus 0 seconds"',
			'',
			'   # Favicon',
			'    ExpiresByType image/vnd.microsoft.icon "access plus 1 week"',
			'    ExpiresByType image/x-icon "access plus 1 week"',
			'',
			'    # HTML no caching',
			'    ExpiresByType text/html "access plus 0 seconds"',
			'',
			'    # Feeds',
			'    ExpiresByType application/rss+xml "access plus 1 hour"',
			'    ExpiresByType application/atom+xml "access plus 1 hour"',
			'',
			'    # PDF',
			'    ExpiresByType application/pdf "access plus 1 month"',
			'',
			'   # Other',
			'   ExpiresByType application/xhtml-xml "access plus 1 month"',
			'   ExpiresByType application/x-shockwave-flash "access plus 1 month"',
			'   ExpiresByType text/x-cross-domain-policy "access plus 1 week"',
			'',
			'    # Default',
			'    ExpiresDefault "access plus 1 month"',
			'</IfModule>',
			'',
			'# Set proper headers for caching',
			'<IfModule mod_headers.c>',
			'    Header append Vary Accept-Encoding',
			'    # One year for image and font files',
			'    <FilesMatch "\.(jpg|jpeg|gif|png|ico|svg|webp|ttf|ttc|otf|eot|woff|woff2)$">',
			'        Header set Cache-Control "max-age=31536000, public"',
			'    </FilesMatch>',
			'',
			'    # One month for css and js',
			'    <FilesMatch "\.(css|js)$">',
			'        Header set Cache-Control "max-age=2592000, public"',
			'    </FilesMatch>',
			'</IfModule>',
		);

		return $indent . implode( PHP_EOL . $indent, $directives );
	}

	/**
	 * Returns the mod_deflate directives with proper indentation
	 *
	 * @param int $indent_level Number of indentation levels
	 *
	 * @return string Indented directives
	 */
	private function get_gzip_directives( int $indent_level = 1 ): string {
		$indent     = str_repeat( '     ', $indent_level );
		$directives = array(
			'# Enable GZIP compression',
			'<IfModule mod_env.c>',
			'    SetEnv BREEZE_GZIP_ON 1',
			'</IfModule>',
			'<IfModule mod_setenvif.c>',
			'    # Handle older browsers that can\'t handle compression properly',
			'    BrowserMatch ^Mozilla/4 gzip-only-text/html',
			'    BrowserMatch ^Mozilla/4\.0[678] no-gzip',
			'    BrowserMatch \bMSIE !no-gzip !gzip-only-text/html',
			'    BrowserMatch \bMSI[E] !no-gzip !gzip-only-text/html',
			'</IfModule>',
			'<IfModule mod_headers.c>',
			'   Header append Vary User-Agent env=!dont-vary',
			'</IfModule>',
			'AddType x-font/woff .woff',
			'AddOutputFilterByType DEFLATE text/plain',
			'AddOutputFilterByType DEFLATE text/html',
			'AddOutputFilterByType DEFLATE text/xml',
			'AddOutputFilterByType DEFLATE text/css',
			'AddOutputFilterByType DEFLATE text/javascript',
			'AddOutputFilterByType DEFLATE text/vtt',
			'AddOutputFilterByType DEFLATE text/x-component',
			'AddOutputFilterByType DEFLATE application/xml',
			'AddOutputFilterByType DEFLATE application/xhtml+xml',
			'AddOutputFilterByType DEFLATE application/rss+xml',
			'AddOutputFilterByType DEFLATE application/javascript',
			'AddOutputFilterByType DEFLATE application/x-javascript',
			'AddOutputFilterByType DEFLATE application/x-httpd-php',
			'AddOutputFilterByType DEFLATE application/x-httpd-fastphp',
			'AddOutputFilterByType DEFLATE application/atom+xml',
			'AddOutputFilterByType DEFLATE application/json',
			'AddOutputFilterByType DEFLATE application/ld+json',
			'AddOutputFilterByType DEFLATE application/vnd.ms-fontobject',
			'AddOutputFilterByType DEFLATE application/x-font-ttf',
			'AddOutputFilterByType DEFLATE application/x-web-app-manifest+json',
			'AddOutputFilterByType DEFLATE application/font-woff2',
			'AddOutputFilterByType DEFLATE application/x-font-woff',
			'AddOutputFilterByType DEFLATE font/woff',
			'AddOutputFilterByType DEFLATE font/opentype',
			'AddOutputFilterByType DEFLATE font/ttf',
			'AddOutputFilterByType DEFLATE font/eot',
			'AddOutputFilterByType DEFLATE font/otf',
			'AddOutputFilterByType DEFLATE image/svg+xml',
			'AddOutputFilterByType DEFLATE image/x-icon',
			'AddOutputFilterByType DEFLATE application/js',
		);

		return $indent . implode( PHP_EOL . $indent, $directives );
	}

	/**
	 * Writes or modifies the .htaccess file based on provided rules and content.
	 *
	 * @param array $args An associative array of arguments:
	 *                    - 'before' (string): The marker indicating the start of rules to replace.
	 *                    - 'after' (string): The marker indicating the end of rules to replace.
	 *                    - 'content' (string): The new rules content to be inserted.
	 *                    - 'clean' (bool, optional): If set to false, appends the new content instead of completely replacing old rules.
	 *
	 * @return bool True on successful modification of the .htaccess file, false otherwise.
	 */
	public static function write_to_htaccess( array $args ): bool {
		$htaccess_path = trailingslashit( ABSPATH ) . '.htaccess';

		if ( ! is_super_admin() && 'cli' !== php_sapi_name() ) {
			return false;
		}
		// open htaccess file
		if ( file_exists( $htaccess_path ) ) {
			$htaccess_content = file_get_contents( $htaccess_path );
		}
		if ( empty( $htaccess_content ) ) {
			return false;
		}

		// Remove old rules.
		$htaccess_content = preg_replace( "/{$args['before']}[\s\S]*{$args['after']}" . PHP_EOL . '/im', '', $htaccess_content );

		if ( array_key_exists( 'clean', $args ) && false === $args['clean'] ) {
			$htaccess_content = $args['content'] . PHP_EOL . $htaccess_content;
		}

		file_put_contents( $htaccess_path, $htaccess_content );

		return true;
	}
}
