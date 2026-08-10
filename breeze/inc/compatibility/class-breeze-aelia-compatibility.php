<?php

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility with Aelia Currency Switcher for WooCommerce.
 */
class Breeze_Aelia_Compatibility {

	/** @var Breeze_Aelia_Compatibility|null */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Breeze_Aelia_Compatibility
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		add_filter( 'breeze_cache_variation_allowlist', array( $this, 'get_cache_variation_allowlist' ) );
		add_filter( 'breeze_cache_variation_request_values', array( $this, 'get_cache_variation_request_values' ) );
	}

	/**
	 * Add Aelia's enabled currencies to Breeze's trusted variation metadata.
	 *
	 * @param array<string,array<int,string>> $allowlist Existing variation metadata.
	 *
	 * @return array<string,array<int,string>>
	 */
	public function get_cache_variation_allowlist( $allowlist ) {
		if ( ! is_array( $allowlist ) || ! $this->is_active() ) {
			return is_array( $allowlist ) ? $allowlist : array();
		}

		$currencies = $this->get_enabled_currencies();
		if ( empty( $currencies ) ) {
			return $allowlist;
		}

		return Breeze_Cache_Variation_Context::merge_allowlist(
			$allowlist,
			array( 'currency' => $currencies )
		);
	}

	/**
	 * Preserve Aelia's selected-currency cookie on normal requests.
	 *
	 * @param array<string,mixed> $values Request variation values.
	 *
	 * @return array<string,mixed>
	 */
	public function get_cache_variation_request_values( $values ) {
		if ( ! is_array( $values ) || ! $this->is_active() ) {
			return is_array( $values ) ? $values : array();
		}

		if ( isset( $_COOKIE['aelia_cs_selected_currency'] ) ) {
			$values['currency'] = $_COOKIE['aelia_cs_selected_currency'];
		}

		return $values;
	}

	/**
	 * Aelia is active only after its global instance has been loaded.
	 *
	 * @return bool
	 */
	private function is_active() {
		return isset( $GLOBALS['woocommerce-aelia-currencyswitcher'] ) && ! empty( $GLOBALS['woocommerce-aelia-currencyswitcher'] );
	}

	/**
	 * Read currencies from Aelia's documented configuration filter.
	 *
	 * @return array<int,string>
	 */
	private function get_enabled_currencies() {
		if ( ! function_exists( 'apply_filters' ) ) {
			return array();
		}

		$currencies = apply_filters( 'wc_aelia_cs_enabled_currencies', array() );

		return is_array( $currencies ) ? $currencies : array();
	}
}

Breeze_Aelia_Compatibility::get_instance();
