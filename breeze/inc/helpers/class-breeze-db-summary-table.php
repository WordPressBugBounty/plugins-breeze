<?php

global $hook_suffix;
$hook_suffix = 'breeze_display_db_summary';

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Breeze_Db_Summary_List_Table extends WP_List_Table {

	/**
	 * Table header slugs and header titles
	 *
	 * @var string[]
	 */
	var $table_columns = array(
		'number'     => '#',
		'table_name' => 'Name',
		'table_size' => 'Size',
	);

	/**
	 * Get table columns
	 *
	 * @return array|string[]
	 */
	public function get_columns() {
		return $this->table_columns;
	}

	/**
	 * Prepare items before displaying
	 *
	 * @return void
	 */
	public function prepare_items() {
		$data = $this->get_data();

		$columns               = $this->get_columns();
		$hidden                = array();
		$sortable              = array();
		$this->_column_headers = array( $columns, $hidden, $sortable );
		$this->items           = $data;
	}

	/**
	 * Get database schema data
	 *
	 * @return array
	 */
	public function get_data() {
		global $wpdb;

		// Try to get cached data first
		$cache_key = 'breeze_db_summary_data';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$summary = $wpdb->get_results( "SELECT option_name, char_length(option_value) AS option_value_length FROM `$wpdb->options` WHERE autoload='yes' ORDER BY option_value_length DESC LIMIT 30", ARRAY_A );

		// Cache the results for 3 hours
		wp_cache_set( $cache_key, $summary, 'breeze', 3 * HOUR_IN_SECONDS );

		$table_formatted = array();
		$n               = 0;
		foreach ( $summary as $key => $table ) {
			if ( '0' == $table['option_value_length'] ) {
				continue;
			}

			$table_formatted[ $key ]['number']     = $n++;
			$table_formatted[ $key ]['table_name'] = $table['option_name'];
			$table_formatted[ $key ]['table_size'] = round( $table['option_value_length'] / 1024, 2 );
		}

		return $table_formatted;
	}

	/**
	 * Output the overall statistics of the database
	 *
	 * @return string
	 */
	public function get_statistics() {
		global $wpdb;

		// Try to get cached data first
		$cache_key_size  = 'breeze_db_summary_size';
		$cache_key_count = 'breeze_db_summary_count';

		$summary_size = wp_cache_get( $cache_key_size, 'breeze' );
		if ( false === $summary_size ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$summary_size = floatval( $wpdb->get_var( "SELECT ( sum(char_length(option_value) ) / 1024 ) FROM `$wpdb->options` WHERE autoload='yes'" ) );

			// Cache the results for 3 hours
			wp_cache_set( $cache_key_size, $summary_size, 'breeze', 3 * HOUR_IN_SECONDS );
		}

		$summary_count = wp_cache_get( $cache_key_count, 'breeze' );
		if ( false === $summary_count ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$summary_count = floatval( $wpdb->get_var( "SELECT count(*) FROM `$wpdb->options` WHERE autoload='yes'" ) );

			// Cache the results for 3 hours
			wp_cache_set( $cache_key_count, $summary_count, 'breeze', 3 * HOUR_IN_SECONDS );
		}
		$class = 'normal';

		if ( $summary_size > 900 ) {
			$class = 'critical';
		}

		$markup_output  = '<h4  class="' . $class . ' db-summary-size" >' . __( 'Autoload Total Size: ', 'breeze' ) . round( $summary_size, 2 ) . ' KB</h4>';
		$markup_output .= '<h5 class="db-summary-count">' . __( 'Autoload Count: ', 'breeze' ) . $summary_count . '</h5>';

		return $markup_output;
	}

	/**
	 * Default column display
	 *
	 * @param $item
	 * @param $column_name
	 *
	 * @return bool|mixed|string|void
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'number':
			case 'table_name':
				return $item[ $column_name ];
			case 'table_size':
				return $item[ $column_name ] . ' KB';
			default:
				return print_r( $item, true ); //Show the whole array for troubleshooting purposes
		}
	}
}
