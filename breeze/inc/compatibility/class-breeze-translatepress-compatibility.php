<?php
/**
 * Compatibility with TranslatePress.
 */
class Breeze_TranslatePress_Compatibility {

	private static $instance = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'breeze_collect_urls_for_cache_purge', array( $this, 'add_translatepress_urls' ), 10, 2 );
	}

	/**
	 * Add TranslatePress language URLs to the cache purge list.
	 *
	 * @param array $urls    The existing list of URLs.
	 * @param int   $post_id The post ID.
	 * @return array         The modified list of URLs.
	 */
	public function add_translatepress_urls( $urls, $post_id ) {
		// Check if TranslatePress class exists
		if ( ! class_exists( 'TRP_Translate_Press' ) ) {
			return $urls;
		}

		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return $urls;
		}

		// Clean up the permalink similar to the main function logic
		$permalink = str_replace( '__trashed', '', $permalink );

		// Get TranslatePress Instance
		$trp = TRP_Translate_Press::get_trp_instance();

		// Get required components
		$url_converter = $trp->get_component( 'url_converter' );
		$trp_settings  = $trp->get_component( 'settings' );
		$settings      = $trp_settings->get_settings();

		// Loop through active languages
		if ( ! empty( $settings['publish-languages'] ) ) {
			foreach ( $settings['publish-languages'] as $language_code ) {
				// Generate URL for specific language
				// get_url_for_language( $language, $url, $abs_home )
				$translated_url = $url_converter->get_url_for_language( $language_code, $permalink, '' );

				if ( ! empty( $translated_url ) ) {
					$urls[] = $translated_url;
				}
			}
		}

		return $urls;
	}

	/**
	 * Singleton Instance
	 */
	public static function get_instance(): ?Breeze_TranslatePress_Compatibility {
		if ( null === self::$instance ) {
			self::$instance = new Breeze_TranslatePress_Compatibility();
		}

		return self::$instance;
	}
}

// Initialize the class
Breeze_TranslatePress_Compatibility::get_instance();
