<?php

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility with WooCommerce Multilingual & Multicurrency plugin
 */
class Breeze_WCML_Compatibility {



	/**
	 * @var Breeze_WCML_Compatibility|null The singleton instance
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance
	 *
	 * @return Breeze_WCML_Compatibility The singleton instance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to prevent direct instantiation
	 */
	private function __construct() {
		$this->add_hooks();
	}

	/**
	 * Adds necessary hooks and actions to integrate with specific functionalities.
	 *
	 * @return void
	 */
	private function add_hooks() {
		add_filter( 'wcml_user_store_strategy', array( $this, 'set_cookie_strategy' ) );
		add_filter( 'wcml_is_cache_enabled_for_switching_currency', '__return_true' );
		add_filter( 'breeze_cache_variation_allowlist', array( $this, 'get_cache_variation_allowlist' ) );
		add_filter( 'breeze_cache_variation_wcml_active', array( $this, 'is_multi_currency_active' ) );
	}

	public function set_cookie_strategy() {
		return 'cookie';
	}

	/**
	 * Add WCML's configured currencies to Breeze's trusted variation metadata.
	 *
	 * @param array<string,array<int,string>> $allowlist Existing variation metadata.
	 * @return array<string,array<int,string>>
	 */
	public function get_cache_variation_allowlist( $allowlist ) {
		if ( ! is_array( $allowlist ) || ! $this->is_multi_currency_active() ) {
			return is_array( $allowlist ) ? $allowlist : array();
		}

		return Breeze_Cache_Variation_Context::merge_allowlist(
			$allowlist,
			array( 'currency' => $this->get_configured_currency_codes() )
		);
	}

	/**
	 * Check that WCML's independent multi-currency mode is active.
	 *
	 * Merely having a WCML class or a client cookie is not sufficient to enable
	 * cache variation.
	 *
	 * @return bool
	 */
	public function is_multi_currency_active() {
		$settings = get_option( '_wcml_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings ) ) {
			global $woocommerce_wpml;
			$settings = is_object( $woocommerce_wpml ) && isset( $woocommerce_wpml->settings ) && is_array( $woocommerce_wpml->settings )
				? $woocommerce_wpml->settings
				: array();
		}

		if (
			! defined( 'WCML_MULTI_CURRENCIES_INDEPENDENT' ) ||
			! isset( $settings['enable_multi_currency'] )
		) {
			return false;
		}

		return WCML_MULTI_CURRENCIES_INDEPENDENT === (int) $settings['enable_multi_currency'];
	}

	/**
	 * Read configured currency codes from WCML, never from WooCommerce's global
	 * currency registry.
	 *
	 * @return array<int,string>
	 */
	private function get_configured_currency_codes() {
		$settings = get_option( '_wcml_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings ) ) {
			global $woocommerce_wpml;
			$settings = is_object( $woocommerce_wpml ) && isset( $woocommerce_wpml->settings ) && is_array( $woocommerce_wpml->settings )
				? $woocommerce_wpml->settings
				: array();
		}

		if (
			! isset( $settings['currency_options'] ) ||
			! is_array( $settings['currency_options'] )
		) {
			return array();
		}

		return array_keys( $settings['currency_options'] );
	}
}

// Initialize the compatibility class
Breeze_WCML_Compatibility::get_instance();
