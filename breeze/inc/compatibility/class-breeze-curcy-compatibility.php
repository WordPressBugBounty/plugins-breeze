<?php

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility with CURCY - Multi Currency for WooCommerce.
 */
class Breeze_CURCY_Compatibility {

	/**
	 * @var Breeze_CURCY_Compatibility|null The singleton instance.
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Breeze_CURCY_Compatibility The singleton instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor to prevent direct instantiation.
	 */
	private function __construct() {
		add_filter( 'breeze_cache_variation_allowlist', array( $this, 'get_cache_variation_allowlist' ) );
	}

	/**
	 * Add CURCY's configured currencies to Breeze's trusted variation metadata.
	 *
	 * @param array<string,array<int,string>> $allowlist Existing variation metadata.
	 *
	 * @return array<string,array<int,string>>
	 */
	public function get_cache_variation_allowlist( $allowlist ) {
		if ( ! is_array( $allowlist ) || ! $this->is_active() ) {
			return is_array( $allowlist ) ? $allowlist : array();
		}

		$settings = get_option( 'woo_multi_currency_params', array() );
		if ( ! is_array( $settings ) || ! isset( $settings['currency'] ) || ! is_array( $settings['currency'] ) ) {
			return $allowlist;
		}

		return Breeze_Cache_Variation_Context::merge_allowlist(
			$allowlist,
			array( 'currency' => $settings['currency'] )
		);
	}

	/**
	 * CURCY must be loaded and enabled before its values can affect caching.
	 *
	 * @return bool
	 */
	private function is_active() {
		if ( ! class_exists( 'WOOMULTI_CURRENCY' ) && ! class_exists( 'WOOMULTI_CURRENCY_F' ) ) {
			return false;
		}

		$settings = get_option( 'woo_multi_currency_params', array() );

		return is_array( $settings ) &&
				isset( $settings['enable'] ) &&
				is_scalar( $settings['enable'] ) &&
				filter_var( $settings['enable'], FILTER_VALIDATE_BOOLEAN );
	}
}

Breeze_CURCY_Compatibility::get_instance();
