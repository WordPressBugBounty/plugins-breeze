<?php

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility with Weglot Translate.
 */
class Breeze_Weglot_Compatibility {

	/** @var Breeze_Weglot_Compatibility|null */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Breeze_Weglot_Compatibility
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register cache variation hooks.
	 */
	private function __construct() {
		add_filter( 'breeze_cache_variation_allowlist', array( $this, 'get_cache_variation_allowlist' ) );
		add_filter( 'breeze_cache_variation_request_values', array( $this, 'get_cache_variation_request_values' ) );
		add_filter(
			'breeze_cache_variation_weglot_original_language',
			array(
				$this,
				'get_original_language_for_config',
			)
		);
	}

	/**
	 * Add Weglot's configured URL languages to Breeze's trusted metadata.
	 *
	 * @param array<string,array<int,string>> $allowlist Existing variation metadata.
	 *
	 * @return array<string,array<int,string>>
	 */
	public function get_cache_variation_allowlist( $allowlist ) {
		if ( ! is_array( $allowlist ) || ! $this->is_active() ) {
			return is_array( $allowlist ) ? $allowlist : array();
		}

		$languages = $this->get_configured_languages();
		if ( empty( $languages ) ) {
			return $allowlist;
		}

		return Breeze_Cache_Variation_Context::merge_allowlist(
			$allowlist,
			array( 'language' => $languages )
		);
	}

	/**
	 * Add Weglot's current URL language to normal request variation values.
	 *
	 * @param array<string,mixed> $values Request variation values.
	 *
	 * @return array<string,mixed>
	 */
	public function get_cache_variation_request_values( $values ) {
		if ( ! is_array( $values ) || ! $this->is_active() ) {
			return is_array( $values ) ? $values : array();
		}

		if ( function_exists( 'weglot_get_current_language_custom' ) ) {
			$values['language'] = weglot_get_current_language_custom();
		} elseif ( function_exists( 'weglot_get_current_language' ) ) {
			$values['language'] = weglot_get_current_language();
		}

		return $values;
	}

	/**
	 * Preserve the original URL language in generated configuration.
	 *
	 * @param string $language Existing value.
	 *
	 * @return string
	 */
	public function get_original_language_for_config( $language ) {
		return $this->is_active() ? $this->get_original_language() : (string) $language;
	}

	/**
	 * Weglot is active only when it exposes configured languages.
	 *
	 * @return bool
	 */
	private function is_active() {
		return function_exists( 'weglot_get_option' ) &&
				function_exists( 'weglot_get_destination_languages' ) &&
				'' !== $this->get_original_language() &&
				! empty( $this->get_destination_languages() );
	}

	/**
	 * Get the configured original language URL code.
	 *
	 * @return string
	 */
	private function get_original_language() {
		$language = function_exists( 'weglot_get_original_language' )
			? weglot_get_original_language()
			: weglot_get_option( 'original_language' );

		if ( ! is_string( $language ) || '' === trim( $language ) ) {
			$language = weglot_get_option( 'language_from' );
		}

		return is_string( $language ) ? $language : '';
	}

	/**
	 * Get configured destination URL codes.
	 *
	 * @return array<int,string>
	 */
	private function get_destination_languages() {
		$destinations = weglot_get_destination_languages();
		if ( ! is_array( $destinations ) ) {
			return array();
		}

		$languages = array();
		foreach ( $destinations as $destination ) {
			if ( is_string( $destination ) ) {
				$languages[] = $destination;
				continue;
			}

			if ( ! is_array( $destination ) ) {
				continue;
			}

			$language = isset( $destination['custom_code'] ) && is_string( $destination['custom_code'] ) && '' !== trim( $destination['custom_code'] )
				? $destination['custom_code']
				: ( isset( $destination['language_to'] ) ? $destination['language_to'] : '' );

			if ( is_string( $language ) && '' !== trim( $language ) ) {
				$languages[] = $language;
			}
		}

		return $languages;
	}

	/**
	 * Get all configured URL languages.
	 *
	 * @return array<int,string>
	 */
	private function get_configured_languages() {
		$languages    = array( $this->get_original_language() );
		$destinations = $this->get_destination_languages();

		return array_values( array_unique( array_merge( $languages, $destinations ) ) );
	}
}

Breeze_Weglot_Compatibility::get_instance();
