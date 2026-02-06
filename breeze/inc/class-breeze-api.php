<?php
if ( ! defined( 'ABSPATH' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	exit;
}


/**
 * Class Breeze_Api_Handler
 *
 * Handles API interactions for Breeze, including authentication, rate limiting,
 * and performing cache-clearing actions via REST API endpoints.
 */
class Breeze_Api_Handler {
	var $options = array();

	public function __construct( $options ) {
		add_action( 'rest_api_init', array( $this, 'register_breeze_api_route' ) );

		$this->options = $options;
	}

	/**
	 * Registers a REST API route for clearing all cache.
	 *
	 * @return void
	 */
	public function register_breeze_api_route() {
		register_rest_route(
			'breeze/v1',
			'/clear-all-cache',
			array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'breeze_clear_cache' ),
				'permission_callback' => array( $this, 'permission_check_purge' ),
			)
		);
	}

	/**
	 * Performs a permission check for a purge request by verifying rate limits and API token authentication.
	 *
	 * @param WP_REST_Request $request The incoming REST API request object.
	 * @return true|WP_Error Returns true if the permission check passes, or a WP_Error object on failure.
	 */
	public function permission_check_purge( $request ) {
		// Get client IP for rate limiting
		$client_ip = $this->get_client_ip();
		// Check rate limiting (max 5 attempts per minute)
		if ( $this->is_rate_limited( $client_ip ) ) {
			$this->log_auth_attempt( $client_ip, 'rate_limited' );
			return new WP_Error(
				'rest_rate_limited',
				__( 'Too many requests. Please try again later.', 'breeze' ),
				array( 'status' => 429 )
			);
		}

		// Get and validate authorization header
		$auth_header = $request->get_header( 'authorization' );
		$key         = $auth_header ? str_replace( 'Bearer ', '', $auth_header ) : '';

		// Validate token is not empty and meets minimum length
		if ( empty( $key ) || strlen( $key ) < 16 ) {
			$this->log_auth_attempt( $client_ip, 'invalid_token' );
			$this->increment_rate_limit( $client_ip );
			return new WP_Error(
				'rest_invalid_token',
				__( 'Invalid or missing authentication token.', 'breeze' ),
				array( 'status' => 401 )
			);
	}

		// Get stored token
		$stored_token = isset( $this->options['breeze-api-token'] ) ? $this->options['breeze-api-token'] : '';

		// Use constant-time comparison to prevent timing attacks
		if ( ! hash_equals( $stored_token, $key ) ) {
			$this->log_auth_attempt( $client_ip, 'auth_failed' );
			$this->increment_rate_limit( $client_ip );
			return new WP_Error(
				'rest_authentication_failed',
				__( 'Authentication failed.', 'breeze' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Handles the cache clearing functionality with authentication and rate limiting.
	 *
	 * This method validates incoming API requests to clear the cache.
	 * It enforces rate limiting based on client IP address, validates an authentication token,
	 * and clears all cache when authentication is successful.
	 *
	 * @param WP_REST_Request $request The incoming REST API request object.
	 *
	 * @return WP_REST_Response A response object containing the result of the cache clearing operation.
	 *                           The response includes an appropriate HTTP status code and message.
	 */
	public function breeze_clear_cache( $request ) {
		// Get client IP for rate limiting
		$client_ip = $this->get_client_ip();

		// Authentication successful - clear cache
		( new Breeze_Admin() )->breeze_clear_all_cache();
		$this->log_auth_attempt( $client_ip, 'success' );

		return new WP_REST_Response(
			array( 'message' => __( 'Cache Cleared', 'breeze' ) ),
			200
			);
	}

	/**
	 * Retrieve the client's IP address from server variables.
	 *
	 * Checks for the IP address in the `HTTP_X_FORWARDED_FOR` header first, and falls back to `REMOTE_ADDR` if not found.
	 *
	 * @return string The sanitized client IP address.
	 */
	private function get_client_ip() {
		$ip = '';
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$ip = explode( ',', $ip )[0];
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return $ip;
	}

	/**
	 * Checks if the given IP address is rate-limited based on the number of attempts.
	 *
	 * @param string $ip The IP address to check for rate-limiting.
	 * @return bool True if the IP address is rate-limited, false otherwise.
	 */
	private function is_rate_limited( $ip ) {
		$transient_key = 'breeze_api_attempts_' . hash( 'sha256', $ip );
		$attempts      = get_transient( $transient_key );
		return false !== $attempts && $attempts >= 5;
	}

	/**
	 * Increments the rate limit attempt count for a given IP address.
	 *
	 * @param string $ip The IP address for which the rate limit attempt count should be incremented.
	 * @return void
	 */
	private function increment_rate_limit( $ip ) {
		$transient_key = 'breeze_api_attempts_' . hash( 'sha256', $ip );
		$attempts      = get_transient( $transient_key );
		if ( false === $attempts ) {
			set_transient( $transient_key, 1, 60 ); // 60 seconds
		} else {
			set_transient( $transient_key, $attempts + 1, 60 );
		}
		}

	/**
	 * Logs an authentication attempt for debugging purposes.
	 *
	 * @param string $ip The IP address from which the authentication attempt was made.
	 * @param string $status The authentication status (e.g., success or failure).
	 * @return void
	 */
	private function log_auth_attempt( $ip, $status ) {
		if ( defined( 'BREEZE_DEBUG' ) && true === BREEZE_DEBUG && function_exists( 'error_log' ) ) {
			error_log(
				sprintf(
					'Breeze API Auth [%s] - IP: %s - Status: %s - Time: %s',
					gmdate( 'Y-m-d H:i:s' ),
					$ip,
					$status,
					time()
				)
			);
	    }
    }
}
