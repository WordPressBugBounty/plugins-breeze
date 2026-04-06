<?php

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
	}

	public function set_cookie_strategy() {
		return 'cookie';
	}
}

// Initialize the compatibility class
Breeze_WCML_Compatibility::get_instance();
