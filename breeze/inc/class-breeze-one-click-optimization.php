<?php

defined( 'ABSPATH' ) || die( 'No direct script access allowed!' );

/**
 * Class Breeze_One_Click_Optimization
 *
 * Handles the One-Click Optimization feature
 */
class Breeze_One_Click_Optimization {


	/**
	 * Option name used to store the one-click optimization settings in the database.
	 *
	 * @var string
	 */
	public static string $option_name = 'one_click_optimization_settings';
	/**
	 * Known compatibility issues with themes and plugins
	 *
	 * @var array
	 */
	private static array $known_compatibility_issues = array(
		'themes'  => array(
			'avada' => array(
				'level'   => 'expert',
				'message' => 'Avada theme may conflict with JS Delay and Combine CSS/JS options.',
			),
		),
		'plugins' => array(
			'woocommerce'          => array(
				'level'   => 'expert',
				'message' => 'WooCommerce may conflict with JS Delay and Combine JS options, especially on cart and checkout pages.',
			),
			'elementor'            => array(
				'level'   => 'expert',
				'message' => 'Elementor plugin may conflict with JS Delay and Combine CSS/JS options.',
			),
			'wp-rocket'            => array(
				'level'   => 'basic',
				'message' => 'WP Rocket is another caching plugin and may conflict with Breeze. We recommend disabling one of them.',
			),
			'w3-total-cache'       => array(
				'level'   => 'basic',
				'message' => 'W3 Total Cache is another caching plugin and may conflict with Breeze. We recommend disabling one of them.',
			),
			'wp-super-cache'       => array(
				'level'   => 'basic',
				'message' => 'WP Super Cache is another caching plugin and may conflict with Breeze. We recommend disabling one of them.',
			),
			'litespeed-cache'      => array(
				'level'   => 'basic',
				'message' => 'LiteSpeed Cache is another caching plugin and may conflict with Breeze. We recommend disabling one of them.',
			),
			'autoptimize'          => array(
				'level'   => 'basic',
				'message' => 'Autoptimize may conflict with Breeze minification features. We recommend disabling one of them.',
			),
			'wp-optimize'          => array(
				'level'   => 'basic',
				'message' => 'WP-Optimize may conflict with Breeze minification features. We recommend disabling one of them.',
			),
			'siteground-optimizer' => array(
				'level'   => 'basic',
				'message' => 'SiteGround Optimizer may conflict with Breeze. We recommend disabling one of them.',
			),
		),
	);

	/**
	 * Optimization presets for each level
	 *
	 * @var array
	 */
	private static array $optimization_presets = array(
		'basic'    => array(
			'basic'    => array(
				'breeze-active'            => '1',
				'breeze-gzip-compression'  => '1',
				'breeze-browser-cache'     => '1',
				'breeze-lazy-load'         => '1',
				'breeze-lazy-load-native'  => '0',
				'breeze-lazy-load-iframes' => '0',
				'breeze-lazy-load-videos'  => '0',
			),
			'file'     => array(
				'breeze-minify-html'        => '1',
				'breeze-minify-css'         => '1',
				'breeze-group-css'          => '0',
				'breeze-minify-js'          => '0',
				'breeze-group-js'           => '0',
				'breeze-include-inline-js'  => '0',
				'breeze-include-inline-css' => '0',
				'breeze-delay-all-js'       => '0',
			),
			'preload'  => array(),
			'advanced' => array(
				'breeze-wp-emoji' => '1',
			),
		),
		'advanced' => array(
			'basic'    => array(
				'breeze-active'            => '1',
				'breeze-gzip-compression'  => '1',
				'breeze-browser-cache'     => '1',
				'breeze-lazy-load'         => '1',
				'breeze-lazy-load-native'  => '0',
				'breeze-lazy-load-iframes' => '1',
				'breeze-lazy-load-videos'  => '1',
			),
			'file'     => array(
				'breeze-minify-html'        => '1',
				'breeze-minify-css'         => '1',
				'breeze-group-css'          => '1',
				'breeze-include-inline-css' => '1',
				'breeze-minify-js'          => '0',
				'breeze-group-js'           => '0',
				'breeze-include-inline-js'  => '0',
				'breeze-delay-all-js'       => '0',
			),
			'preload'  => array(
				'breeze-preload-links' => '1',
			),
			'advanced' => array(
				'breeze-wp-emoji'                      => '1',
				'breeze-preload-links'                 => '1',
				'breeze-store-googlefonts-locally'     => '1',
				'breeze-store-googleanalytics-locally' => '0',
				'breeze-store-facebookpixel-locally'   => '0',
				'breeze-store-gravatars-locally'       => '0',
			),
		),
		'expert'   => array(
			'basic'    => array(
				'breeze-active'            => '1',
				'breeze-gzip-compression'  => '1',
				'breeze-browser-cache'     => '1',
				'breeze-lazy-load'         => '1',
				'breeze-lazy-load-native'  => '0',
				'breeze-lazy-load-iframes' => '1',
				'breeze-lazy-load-videos'  => '1',
			),
			'file'     => array(
				'breeze-minify-html'        => '1',
				'breeze-minify-css'         => '1',
				'breeze-group-css'          => '1',
				'breeze-include-inline-css' => '1',
				'breeze-minify-js'          => '1',
				'breeze-group-js'           => '0',
				'breeze-include-inline-js'  => '1',
				'breeze-delay-all-js'       => '1',
			),
			'preload'  => array(
				'breeze-preload-links' => '1',
			),
			'advanced' => array(
				'breeze-wp-emoji'                      => '1',
				'breeze-preload-links'                 => '1',
				'breeze-store-googlefonts-locally'     => '1',
				'breeze-store-googleanalytics-locally' => '1',
				'breeze-store-facebookpixel-locally'   => '1',
				'breeze-store-gravatars-locally'       => '1',
			),
		),
	);

	/**
	 * Purge cached HTML/minified assets for the current site or all sites.
	 *
	 * @return void
	 */
	private static function purge_site_caches() {
		$purge_all_sites = is_multisite() && ( is_network_admin() || breeze_does_inherit_settings() );

		if ( $purge_all_sites ) {
			$sites = get_sites(
				array(
					'fields' => 'ids',
				)
			);

			foreach ( $sites as $blog_id ) {
				switch_to_blog( $blog_id );
				Breeze_MinificationCache::clear_minification( $blog_id );
				Breeze_PurgeCache::breeze_cache_flush( true, true, true );
				Breeze_PurgeCache::__flush_object_cache();
				restore_current_blog();
			}

			return;
		}

		if ( class_exists( 'Breeze_Configuration' ) ) {
			Breeze_Configuration::breeze_clean_cache();
		}
	}

	/**
	 * Apply the same post-save side effects used by the settings "Save" action.
	 *
	 * @param array $basic_settings Basic settings array used for cache + cron decisions.
	 *
	 * @return void
	 */
	private static function run_save_side_effects( array $basic_settings ) {
		Breeze_Options_Reader::fetch_all_saved_settings( is_network_admin() );

		// Update config files.
		Breeze_ConfigCache::factory()->write();
		Breeze_ConfigCache::factory()->write_config_cache();

		$is_cache_enabled = isset( $basic_settings['breeze-active'] ) && '1' === $basic_settings['breeze-active'];
		Breeze_ConfigCache::factory()->toggle_caching( $is_cache_enabled );

		if ( $is_cache_enabled ) {
			Breeze_PurgeCacheTime::factory()->unschedule_events();
			if ( isset( $basic_settings['breeze-b-ttl'] ) ) {
				Breeze_PurgeCacheTime::factory()->schedule_events( (int) $basic_settings['breeze-b-ttl'] );
			}
		}

		Breeze_Configuration::update_htaccess();
	}

	/**
	 * AJAX handler for applying optimization presets
	 */
	public static function apply_optimization() {

		// Check nonce
		if (
				! isset( $_POST['_wpnonce'] ) ||
				! wp_verify_nonce( $_POST['_wpnonce'], '_breeze_apply_optimization' )
		) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'breeze' ) ) );
		}

		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'breeze' ) ) );
		}

		set_as_network_screen();

		// Get optimization level
		if (
				! isset( $_POST['level'] ) ||
				! in_array( $_POST['level'], array( 'basic', 'advanced', 'expert' ), true )
		) {
			wp_send_json_error( array( 'message' => __( 'Invalid optimization level.', 'breeze' ) ) );
		}

		$level = sanitize_text_field( $_POST['level'] );

		// Check if settings are already tagged as one-click optimization
		$current_applied = get_option( 'breeze_applied_optimization', false );
		if ( ! $current_applied ) {
			// Backup current settings only if not already applied
			$backup_result = self::backup_settings();
			if ( is_wp_error( $backup_result ) ) {
				wp_send_json_error( array( 'message' => $backup_result->get_error_message() ) );
			}
		}

		// Apply optimization preset
		$result = self::apply_optimization_preset( $level );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Mark as applied from one-click optimization
		update_option( 'breeze_applied_optimization', $level );

		self::purge_site_caches();

		// Clear cache
		do_action( 'breeze_clear_all_cache' );

		wp_send_json_success(
			array(
				'message' => sprintf( __( '%s optimization level applied successfully.', 'breeze' ), ucfirst( $level ) ),
			)
		);
	}

	/**
	 * Apply optimization preset
	 *
	 * @param string $level Optimization level (basic, advanced, expert)
	 *
	 * @return true|WP_Error True on success, WP_Error on failure
	 */
	private static function apply_optimization_preset( $level ) {
		if ( ! isset( self::$optimization_presets[ $level ] ) ) {
			return new WP_Error( 'invalid_level', __( 'Invalid optimization level.', 'breeze' ) );
		}

		$preset = self::$optimization_presets[ $level ];
		$is_local_update = false;

		if ( is_multisite() && ! is_network_admin() && ! breeze_does_inherit_settings() ) {
			$is_local_update = true;
		}

		// Get current settings
		$current_basic    = breeze_get_option( 'basic_settings' );
		$current_file     = breeze_get_option( 'file_settings' );
		$current_advanced = breeze_get_option( 'advanced_settings' );
		$current_preload  = breeze_get_option( 'preload_settings' );

		// Merge preset with current settings
		$new_basic    = array_merge( $current_basic, $preset['basic'] );
		$new_file     = array_merge( $current_file, $preset['file'] );
		$new_preload  = array_merge( $current_preload, $preset['preload'] );
		$new_advanced = array_merge( $current_advanced, $preset['advanced'] );

		// Update settings
		breeze_update_option( 'basic_settings', $new_basic, $is_local_update );
		breeze_update_option( 'file_settings', $new_file, $is_local_update );
		breeze_update_option( 'preload_settings', $new_preload, $is_local_update );
		breeze_update_option( 'advanced_settings', $new_advanced, $is_local_update );

		self::run_save_side_effects( $new_basic );

		return true;
	}

	/**
	 * AJAX handler for checking compatibility
	 */
	public static function check_compatibility() {
		// Check nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], '_breeze_check_compat' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'breeze' ) ) );
		}

		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'breeze' ) ) );
		}

		// Get optimization level
		if ( ! isset( $_POST['level'] ) || ! in_array( $_POST['level'], array( 'basic', 'advanced', 'expert' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid optimization level.', 'breeze' ) ) );
		}

		$level = sanitize_text_field( $_POST['level'] );

		// Check compatibility
		$compatibility_issues = self::check_compatibility_issues( $level );

		if ( empty( $compatibility_issues ) ) {
			wp_send_json_success(
				array(
					'message' => __( 'No compatibility issues found.', 'breeze' ),
					'issues'  => array(),
				)
			);
		} else {
			wp_send_json_success(
				array(
					'message' => __( 'Compatibility issues found.', 'breeze' ),
					'issues'  => $compatibility_issues,
				)
			);
		}
	}

	/**
	 * Check for compatibility issues
	 *
	 * @param string $level Optimization level (basic, advanced, expert)
	 *
	 * @return array Array of compatibility issues
	 */
	private static function check_compatibility_issues( $level ) {
		$issues = array();

		// Check active theme
		$theme        = wp_get_theme();
		$theme_name   = strtolower( $theme->get( 'Name' ) );
		$theme_parent = strtolower( $theme->get( 'Template' ) );

		// Check if theme is in known compatibility issues
		foreach ( self::$known_compatibility_issues['themes'] as $theme_slug => $issue ) {
			if ( strpos( $theme_name, $theme_slug ) !== false || strpos( $theme_parent, $theme_slug ) !== false ) {
				// Check if the issue applies to the current level
				$issue_levels      = array( 'basic', 'advanced', 'expert' );
				$level_index       = array_search( $level, $issue_levels, true );
				$issue_level_index = array_search( $issue['level'], $issue_levels, true );

				if ( $level_index >= $issue_level_index ) {
					$issues[] = array(
						'type'    => 'theme',
						'name'    => $theme->get( 'Name' ),
						'message' => $issue['message'],
					);
				}
			}
		}

		// Check active plugins
		$active_plugins = get_option( 'active_plugins' );

		// Also check network active plugins in multisite
		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins' );
			if ( ! empty( $network_plugins ) ) {
				$active_plugins = array_merge( $active_plugins, array_keys( $network_plugins ) );
			}
		}

		// Check if plugins are in known compatibility issues
		foreach ( $active_plugins as $plugin ) {
			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
			$plugin_name = strtolower( $plugin_data['Name'] );
			$plugin_slug = dirname( $plugin );

			if ( empty( $plugin_slug ) ) {
				continue;
			}

			foreach ( self::$known_compatibility_issues['plugins'] as $plugin_key => $issue ) {
				if (
						strpos( $plugin_name, $plugin_key ) !== false ||
						strpos( $plugin_slug, $plugin_key ) !== false
				) {
					// Check if the issue applies to the current level
					$issue_levels      = array( 'basic', 'advanced', 'expert' );
					$level_index       = array_search( $level, $issue_levels, true );
					$issue_level_index = array_search( $issue['level'], $issue_levels, true );

					if ( $level_index >= $issue_level_index ) {
						$issues[] = array(
							'type'    => 'plugin',
							'name'    => $plugin_data['Name'],
							'message' => $issue['message'],
						);
					}
				}
			}
		}

		return $issues;
	}

	/**
	 * AJAX handler for backing up settings
	 */
	public static function backup_settings() {

		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'unauthorized', __( 'You do not have permission to perform this action.', 'breeze' ) );
		}

		// Get current settings
		$settings = array(
			'basic'     => breeze_get_option( 'basic_settings' ),
			'file'      => breeze_get_option( 'file_settings' ),
			'advanced'  => breeze_get_option( 'advanced_settings' ),
			'varnish'   => breeze_get_option( 'varnish_cache' ),
			'cdn'       => breeze_get_option( 'cdn_integration' ),
			'preload'   => breeze_get_option( 'preload_settings' ),
			'heartbeat' => breeze_get_option( 'heartbeat_settings' ),
		);

		// Save backup
		update_option( 'breeze_settings_backup', $settings );

		return true;
	}

	/**
	 * AJAX handler for restoring settings
	 */
	public static function restore_settings() {
		// Check nonce
		if (
				! isset( $_POST['nonce'] ) ||
				! wp_verify_nonce( $_POST['nonce'], '_breeze_restore_settings' )
		) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'breeze' ) ) );
		}

		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'breeze' ) ) );
		}

		set_as_network_screen();

		// Get backup settings
		$backup = get_option( 'breeze_settings_backup' );
		if ( empty( $backup ) ) {
			wp_send_json_error( array( 'message' => __( 'No backup settings found.', 'breeze' ) ) );
		}

		$is_local_update = false;
		if ( is_multisite() && ! is_network_admin() && ! breeze_does_inherit_settings() ) {
			$is_local_update = true;
		}

		// Restore settings
		breeze_update_option( 'basic_settings', $backup['basic'], $is_local_update );
		breeze_update_option( 'file_settings', $backup['file'], $is_local_update );
		breeze_update_option( 'advanced_settings', $backup['advanced'], $is_local_update );
		breeze_update_option( 'varnish_cache', $backup['varnish'], $is_local_update );
		breeze_update_option( 'cdn_integration', $backup['cdn'], $is_local_update );
		breeze_update_option( 'preload_settings', $backup['preload'], $is_local_update );
		breeze_update_option( 'heartbeat_settings', $backup['heartbeat'], $is_local_update );

		// Remove the one-click optimization tag
		delete_option( 'breeze_applied_optimization' );

		$restored_basic = isset( $backup['basic'] ) && is_array( $backup['basic'] ) ? $backup['basic'] : array();

		self::run_save_side_effects( $restored_basic );

		self::purge_site_caches();

		// Clear cache
		do_action( 'breeze_clear_all_cache' );

		wp_send_json_success( array( 'message' => __( 'Settings restored successfully.', 'breeze' ) ) );
	}

	/**
	 * Hide optimization notice.
	 */
	public static function breeze_hide_optimization_notice() {
		check_ajax_referer( '_breeze_save_options', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to perform this action.', 'breeze' ) );
		}
		breeze_update_option( self::$option_name, array( 'hide_notice' => true ) );
		wp_die();
	}

	/**
	 * Displays a one-click optimization notice if it has not been dismissed..
	 *
	 * @return void
	 */
	public static function one_click_optimization_notice() {
		$hide_optimization_notice = breeze_get_option( self::$option_name );

		if ( isset( $hide_optimization_notice['hide_notice'] ) && $hide_optimization_notice['hide_notice'] ) {
			return;
		}

		?>
		<div class="breeze-top-notice optimization-notice">
			<div class="notice-content">
				<h3><?php _e( 'Optimize Your Site in One Click', 'breeze' ); ?></h3>
				<p><?php _e( 'Save time with Breeze\'s predefined optimization presets. These presets simplify optimization by automatically enabling proven performance settings.', 'breeze' ); ?></p>
				<button class="button button-primary explore-optimization"><?php _e( 'Explore Optimization Options', 'breeze' ); ?></button>
			</div>
			<img class="close-notice" src="<?php echo BREEZE_PLUGIN_URL . 'assets/images/add_circle.svg'; ?>"
				alt="<?php _e( 'Close', 'breeze' ); ?>">
		</div>
		<?php
	}
}
