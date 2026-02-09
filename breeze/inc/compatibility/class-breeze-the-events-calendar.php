<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compatibility with Events Calendar plugin
 */
class Breeze_The_Events_Calendar {

	private static $instance = null;

	public function __construct() {
		add_action( 'tec_common_settings_manager_post_set_options', array( &$this, 'clear_all_breeze_cache' ), 99 );
	}

	/**
	 * Clear all cache of breeze.
	 *
	 * @return void
	 */
	public function clear_all_breeze_cache() {
		do_action( 'breeze_clear_all_cache' );
	}

	public static function get_instance(): ?Breeze_The_Events_Calendar {
		if ( null === self::$instance ) {
			self::$instance = new Breeze_The_Events_Calendar();
		}

		return self::$instance;
	}
}

Breeze_The_Events_Calendar::get_instance();
