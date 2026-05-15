<?php
/**
 * Breeze file-based page cache (refactored).
 *
 * This file is intended as a modern, class-based replacement for the legacy
 * `execute-cache.php` drop-in. It is loaded very early, via `advanced-cache.php`,
 * so it MUST NOT rely on late WordPress bootstrap logic, themes, or plugins.
 *
 * Key goals:
 * - Preserve the existing behaviour and condition ordering from `execute-cache.php`.
 * - Provide a clearer, testable structure using classes and namespaces.
 * - Keep all logic PHP 7.4+ compatible.
 * - Avoid introducing new global state where possible.
 *
 * @package Breeze
 */

namespace Breeze\Cache;

use Breeze_Query_Strings_Rules;

if ( ! defined( 'ABSPATH' ) ) {
	// Match legacy behaviour: block direct access.
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	exit;
}

/**
 * Immutable request context for cache decisions.
 *
 * This class encapsulates the pieces of state that were previously spread
 * across global variables in `execute-cache.php` (such as
 * `$breeze_current_url_path`, `$filename_guest_suffix`, etc.).
 * @final
 */
final class Request_Context {

	/** @var string */
	public $request_uri;

	/** @var string */
	public $current_url;

	/** @var string */
	public $cache_key_url;

	/** @var string */
	public $filename;

	/** @var string */
	public $filename_guest_suffix;

	/** @var bool */
	public $user_logged;

	/** @var array<string,mixed> */
	public $config;

	/**
	 * @param string               $request_uri           Raw request URI.
	 * @param string               $current_url           Fully-qualified URL used for exclusions.
	 * @param string               $cache_key_url         URL used to build the cache key/hash.
	 * @param string               $filename              Per-request filename identifier (including user / guest suffix).
	 * @param string               $filename_guest_suffix Guest suffix that was used when no user is logged in.
	 * @param bool                 $user_logged           Whether a WP user is logged in (cookie-based detection).
	 * @param array<string,mixed>  $config                Breeze configuration array (`$GLOBALS['breeze_config']`).
	 */
	public function __construct(
		string $request_uri,
		string $current_url,
		string $cache_key_url,
		string $filename,
		string $filename_guest_suffix,
		bool $user_logged,
		array $config
	) {
		$this->request_uri           = $request_uri;
		$this->current_url           = $current_url;
		$this->cache_key_url         = $cache_key_url;
		$this->filename              = $filename;
		$this->filename_guest_suffix = $filename_guest_suffix;
		$this->user_logged           = $user_logged;
		$this->config                = $config;
	}
}

/**
 * Circuit Breaker for cache operations.
 *
 * Implements the Circuit Breaker pattern to prevent cascading failures
 * when the cache system is experiencing issues. This is a fail-safe system
 * that temporarily disables caching when errors spike, preventing site outages.
 *
 * Uses file-based storage (no WordPress dependencies) for early execution compatibility.
 * Optimized for zero overhead during normal operation.
 *
 * States:
 * - CLOSED: Normal operation, cache is working
 * - OPEN: Cache is failing, bypass cache operations
 * - HALF_OPEN: Testing if cache has recovered
 *
 * @final
 */
final class Cache_Circuit_Breaker {

	/** @var string Circuit breaker state: closed (normal), open (failing), half_open (testing) */
	private const STATE_CLOSED    = 'closed';
	private const STATE_OPEN      = 'open';
	private const STATE_HALF_OPEN = 'half_open';

	/** @var int Failure threshold - open circuit after this many failures */
	private const FAILURE_THRESHOLD = 5;

	/** @var int Success threshold in half-open state before closing */
	private const SUCCESS_THRESHOLD = 2;

	/** @var int Time window for counting failures (seconds) */
	private const FAILURE_WINDOW = 60;

	/** @var int How long to keep circuit open before testing recovery (seconds) */
	private const OPEN_TIMEOUT = 10;

	/** @var int How long to keep circuit half-open before going back to open (seconds) */
	private const HALF_OPEN_TIMEOUT = 60;

	/** @var string State file path (relative to WP_CONTENT_DIR) */
	private const STATE_FILE = 'cache/breeze-circuit-breaker.json';

	/** @var array|null Cached state data for this request (avoid repeated file reads) */
	private static $state_cache = null;

	/** @var bool Whether we've checked state this request (optimization) */
	private static $checked_this_request = false;

	/** @var bool Ensure at most one failure increment per request. */
	private static $failure_recorded_this_request = false;

	/**
	 * Get the full path to the state file.
	 *
	 * @return string Full path to state file.
	 */
	private static function get_state_file_path(): string {
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return WP_CONTENT_DIR . '/' . self::STATE_FILE;
		}
		// Fallback if WP_CONTENT_DIR not defined yet
		return dirname( ABSPATH ) . '/wp-content/' . self::STATE_FILE;
	}

	/**
	 * Read state from file (with file locking).
	 *
	 * @return array State data or default state.
	 */
	private static function read_state(): array {
		$file_path = self::get_state_file_path();

		// If file doesn't exist, return default (CLOSED state)
		if ( ! @file_exists( $file_path ) ) {
			return array(
				'state'         => self::STATE_CLOSED,
				'timestamp'     => 0,
				'failure_count' => 0,
				'success_count' => 0,
			);
		}

		// Read file with shared lock
		$fp = @fopen( $file_path, 'r' );
		if ( false === $fp ) {
			// Can't read file, assume CLOSED (fail-safe)
			return array(
				'state'         => self::STATE_CLOSED,
				'timestamp'     => 0,
				'failure_count' => 0,
				'success_count' => 0,
			);
		}

		// Acquire shared lock (multiple readers allowed)
		if ( ! @flock( $fp, LOCK_SH ) ) {
			fclose( $fp );
			return array(
				'state'         => self::STATE_CLOSED,
				'timestamp'     => 0,
				'failure_count' => 0,
				'success_count' => 0,
			);
		}

		$json = stream_get_contents( $fp );
		flock( $fp, LOCK_UN );
		fclose( $fp );

		if ( false === $json ) {
			return array(
				'state'         => self::STATE_CLOSED,
				'timestamp'     => 0,
				'failure_count' => 0,
				'success_count' => 0,
			);
		}

		$data = @json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return array(
				'state'         => self::STATE_CLOSED,
				'timestamp'     => 0,
				'failure_count' => 0,
				'success_count' => 0,
			);
		}

		return $data;
	}

	/**
	 * Write state to file (with file locking for atomicity).
	 *
	 * @param array $state State data to write.
	 * @return bool True on success, false on failure.
	 */
	private static function write_state( array $state ): bool {
		$file_path = self::get_state_file_path();
		$dir       = dirname( $file_path );

		// Ensure directory exists
		if ( ! @file_exists( $dir ) && ! @mkdir( $dir, 0755, true ) && ! @is_dir( $dir ) ) {
			return false;
		}

		$json = json_encode( $state );
		if ( false === $json ) {
			return false;
		}

		// Write with exclusive lock (atomic)
		// Unpredictable temp name + exclusive create ('xb') prevents symlink/TOCTOU
		// attacks on shared hosts: an attacker can no longer pre-plant a symlink at
		// the temp path to redirect our write to a sensitive file.
		try {
			$random_suffix = bin2hex( random_bytes( 8 ) );
		} catch ( \Exception $e ) {
			return false;
		}
		$temp_file = $file_path . '.' . $random_suffix . '.tmp';
		$fp        = @fopen( $temp_file, 'xb' );
		if ( false === $fp ) {
			return false;
		}

		if ( ! @flock( $fp, LOCK_EX ) ) {
			fclose( $fp );
			@unlink( $temp_file );
			return false;
		}

		$result = fwrite( $fp, $json );
		flock( $fp, LOCK_UN );
		fclose( $fp );

		if ( false === $result ) {
			@unlink( $temp_file );
			return false;
		}

		// Atomic rename
		if ( ! @rename( $temp_file, $file_path ) ) {
			@unlink( $temp_file );
			return false;
		}

		// Update cache
		self::$state_cache = $state;

		return true;
	}

	/**
	 * Get current state (cached in memory for request).
	 *
	 * @return array State data.
	 */
	private static function get_cached_state(): array {
		if ( null === self::$state_cache ) {
			self::$state_cache = self::read_state();
		}
		return self::$state_cache;
	}

	/**
	 * Check if cache operations are allowed (circuit is closed or half-open).
	 *
	 * Optimized for zero file I/O during normal operation.
	 *
	 * @return bool True if cache operations should proceed, false if circuit is open.
	 */
	public static function is_cache_allowed(): bool {
		// Fast path: If we already checked and it's CLOSED, skip everything
		if ( self::$checked_this_request && null !== self::$state_cache && self::STATE_CLOSED === self::$state_cache['state'] ) {
			$GLOBALS['breeze_bypass_cache'] = false;
			return true; // Zero overhead!
		}

		self::$checked_this_request = true;
		$state_data                 = self::get_cached_state();
		$state                      = $state_data['state'];
		$timestamp                  = $state_data['timestamp'];
		$current_time               = time();

		// If circuit is open, check if timeout has passed to transition to half-open
		if ( self::STATE_OPEN === $state ) {
			if ( ( $current_time - $timestamp ) >= self::OPEN_TIMEOUT ) {
				self::transition_to_half_open();
				$GLOBALS['breeze_bypass_cache'] = false; // Allow test request
				return true; // Allow test request
			}
			$GLOBALS['breeze_bypass_cache'] = true; // Circuit OPEN - disable minification
			return false; // Circuit still open
		}

		// If half-open, check timeout
		if ( self::STATE_HALF_OPEN === $state ) {
			if ( ( $current_time - $timestamp ) >= self::HALF_OPEN_TIMEOUT ) {
				// Half-open timeout - go back to open
				self::transition_to_open();
				$GLOBALS['breeze_bypass_cache'] = true; // Circuit reopened - disable minification
				return false;
			}
			$GLOBALS['breeze_bypass_cache'] = false; // Allow test requests
			return true; // Allow test requests
		}

		// Circuit closed - normal operation
		$GLOBALS['breeze_bypass_cache'] = false;
		return true;
	}

	/**
	 * Record a successful cache operation.
	 *
	 * Only does work when needed (HALF_OPEN or CLOSED with failures).
	 *
	 * @return void
	 */
	public static function record_success(): void {
		$state_data = self::get_cached_state();
		$state      = $state_data['state'];

		if ( self::STATE_HALF_OPEN === $state ) {
			// Increment success count
			++$state_data['success_count'];

			// If enough successes, close the circuit
			if ( $state_data['success_count'] >= self::SUCCESS_THRESHOLD ) {
				self::transition_to_closed();
			} else {
				// Update state file with new success count
				self::write_state( $state_data );
			}
		} elseif ( self::STATE_CLOSED === $state && $state_data['failure_count'] > 0 ) {
			// Healthy again in CLOSED state: clear counters and remove state file.
			// File existence should indicate an active/non-default circuit state.
			$file_path = self::get_state_file_path();
			if ( @file_exists( $file_path ) ) {
				@unlink( $file_path );
			}

			self::$state_cache = array(
				'state'         => self::STATE_CLOSED,
				'timestamp'     => 0,
				'failure_count' => 0,
				'success_count' => 0,
			);
		}
		// If CLOSED with no failures, do nothing (zero overhead)
	}

	/**
	 * Record a failed cache operation.
	 *
	 * Only called when actual failure occurs.
	 *
	 * @param string $error_message Optional error message for logging.
	 * @return void
	 */
	public static function record_failure( string $error_message = '' ): void {
		$state_data = self::get_cached_state();
		$state      = $state_data['state'];

		if ( self::STATE_OPEN === $state ) {
			return; // Already open, no need to count
		}

		// Guardrail: only one failure increment per request.
		if ( self::$failure_recorded_this_request ) {
			if ( ! empty( $error_message ) ) {
				error_log( '[Breeze Circuit Breaker] Additional failure in same request (not counted): ' . $error_message );
			}
			return;
		}

		self::$failure_recorded_this_request = true;

		if ( self::STATE_HALF_OPEN === $state ) {
			// Failure in half-open state - go back to open
			self::transition_to_open();
			if ( ! empty( $error_message ) ) {
				error_log( '[Breeze Circuit Breaker] Failure in half-open state: ' . $error_message );
			}
			return;
		}

		// Closed state - count failures
		++$state_data['failure_count'];
		$failure_count = $state_data['failure_count'];

		if ( ! empty( $error_message ) ) {
			error_log( '[Breeze Circuit Breaker] Failure recorded (' . $failure_count . '/' . self::FAILURE_THRESHOLD . '): ' . $error_message );
		}

		$GLOBALS['breeze_bypass_cache'] = true; // Circuit OPEN - disable minification

		// Check if threshold exceeded
		if ( $failure_count >= self::FAILURE_THRESHOLD ) {
			self::transition_to_open();
		} else {
			// Update state file with new failure count
			self::write_state( $state_data );
		}
	}

	/**
	 * Transition to OPEN state (cache is failing).
	 *
	 * @return void
	 */
	private static function transition_to_open(): void {
		$state_data = array(
			'state'         => self::STATE_OPEN,
			'timestamp'     => time(),
			'failure_count' => 0,
			'success_count' => 0,
		);

		self::write_state( $state_data );
		error_log( '[Breeze Circuit Breaker] OPENED - Cache operations disabled for ' . self::OPEN_TIMEOUT . ' seconds' );

		// Send header for monitoring
		if ( ! headers_sent() ) {
			header( 'X-Breeze-Circuit-Breaker: OPEN' );
		}
	}

	/**
	 * Transition to HALF_OPEN state (testing recovery).
	 *
	 * @return void
	 */
	private static function transition_to_half_open(): void {
		$state_data = array(
			'state'         => self::STATE_HALF_OPEN,
			'timestamp'     => time(),
			'failure_count' => 0,
			'success_count' => 0,
		);

		self::write_state( $state_data );
		error_log( '[Breeze Circuit Breaker] HALF-OPEN - Testing cache recovery' );

		// Send header for monitoring
		if ( ! headers_sent() ) {
			header( 'X-Breeze-Circuit-Breaker: HALF-OPEN' );
		}
	}

	/**
	 * Transition to CLOSED state (cache is working).
	 *
	 * @return void
	 */
	private static function transition_to_closed(): void {
		$file_path = self::get_state_file_path();

		// Delete state file (CLOSED is default)
		@unlink( $file_path );

		// Clear cache
		self::$state_cache = null;

		error_log( '[Breeze Circuit Breaker] CLOSED - Cache operations resumed' );

		// Send header for monitoring
		if ( ! headers_sent() ) {
			header( 'X-Breeze-Circuit-Breaker: CLOSED' );
		}
	}

	/**
	 * Get circuit breaker status for monitoring/debugging.
	 *
	 * @return array<string,mixed> Status information.
	 */
	public static function get_status(): array {
		$state_data    = self::get_cached_state();
		$time_in_state = ( $state_data['timestamp'] > 0 ) ? ( time() - $state_data['timestamp'] ) : 0;

		return array(
			'state'             => $state_data['state'],
			'failure_count'     => $state_data['failure_count'],
			'success_count'     => $state_data['success_count'],
			'time_in_state'     => $time_in_state,
			'failure_threshold' => self::FAILURE_THRESHOLD,
			'success_threshold' => self::SUCCESS_THRESHOLD,
			'open_timeout'      => self::OPEN_TIMEOUT,
			'half_open_timeout' => self::HALF_OPEN_TIMEOUT,
			'cache_allowed'     => self::is_cache_allowed(),
		);
	}

	/**
	 * Manually reset the circuit breaker (for emergency recovery).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::transition_to_closed();
		error_log( '[Breeze Circuit Breaker] Manually reset to CLOSED state' );
	}
}

/**
 * Bootstrap entry point for Breeze page caching.
 *
 * This class orchestrates:
 * - Early request eligibility checks (HTTP method, path, query, cookies).
 * - Cache exclusion rules.
 * - Serving existing cache when present.
 * - Registering the output buffer callback to generate new cache.
 * @final
 */
final class Execute_Cache {

	/** @var int Maximum accepted auth cookie value length. */
	private const MAX_AUTH_COOKIE_VALUE_LENGTH = 1024;

	/** @var int Expected auth cookie name length (`wordpress_logged_in_` + 32 hex). */
	private const LOGGED_IN_COOKIE_NAME_LENGTH = 52;

	/**
	 * Main entry point, equivalent to the top-level logic in `execute-cache.php`.
	 *
	 * This is designed to be invoked once from `advanced-cache.php`, e.g.:
	 *
	 * `\Breeze\Cache\Execute_Cache::bootstrap();`
	 * @access public
	 * @static
	 *
	 * @return void
	 */
	public static function bootstrap(): void {
		if ( ! isset( $GLOBALS['breeze_config'] ) || ! is_array( $GLOBALS['breeze_config'] ) ) {
			// Without configuration there is nothing we can safely do.
			return;
		}

		$config = $GLOBALS['breeze_config'];

		if ( ! self::is_caching_active( $config ) ) {
			$GLOBALS['breeze_bypass_cache'] = true; // Circuit reopened - disable minification
			return;
		}

		self::ensure_user_agent_is_set();

		// Load legacy helper functions early (matches old behaviour).
		require_once dirname( __DIR__ ) . '/functions.php';

		// Respect "disable per admin user" settings from config and cookies.
		if ( self::is_cache_disabled_for_current_user( $config ) ) {
			$GLOBALS['breeze_bypass_cache'] = true; // Circuit reopened - disable minification
			return;
		}

		// Lazy load class is used later in the buffer handler.
		require_once dirname( __DIR__ ) . '/class-breeze-lazy-load.php';

		$detect = \breeze_mobile_detect_library();
		if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$_SERVER['HTTP_USER_AGENT'] = '';
		}
		$detect->setUserAgent( (string) $_SERVER['HTTP_USER_AGENT'] );

		// Early request-level bypass rules (robots.txt, cron, non-GET, disallowed extensions, etc).
		if ( self::should_bypass_entire_request() ) {

			$GLOBALS['breeze_bypass_cache'] = true; // Circuit reopened - disable minification
			return;
		}

		// Build per-request context (URL, filename, guest suffix, etc).
		$context = self::build_request_context( $config );

		// Page-level exclusion rules.
		if ( self::is_excluded_page( $config, $context ) ) {
			// Match legacy: enforce no-cache header for excluded pages.
			header( 'Cache-Control: no-cache' );
			$GLOBALS['breeze_bypass_cache'] = true; // Circuit reopened - disable minification
			return;
		}

		// Serve from cache when possible.
		self::try_serve_cache( $config, $context, $detect );

		// No cache hit: register output buffering callback to generate cache.
		$handler = new Page_Cache_Handler( $config, $context );
		\ob_start( array( $handler, 'handle_buffer' ) );
	}

	/**
	 * Check if caching is enabled in Breeze configuration.
	 *
	 * @param array<string,mixed> $config Breeze configuration.
	 * @access private
	 * @static
	 *
	 * @return bool
	 */
	private static function is_caching_active( array $config ): bool {
		if ( ! isset( $config['cache_options']['breeze-active'] ) ) {
			return false;
		}

		return (bool) filter_var( $config['cache_options']['breeze-active'], FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Ensure `$_SERVER['HTTP_USER_AGENT']` is always set.
	 *
	 * Some parts of the logic assume a non-empty user agent and this matches
	 * the legacy behaviour (fallback to "empty_agent" when missing).
	 *
	 * @access private
	 * @static
	 * @return void
	 */
	private static function ensure_user_agent_is_set(): void {
		if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$_SERVER['HTTP_USER_AGENT'] = 'empty_agent';
		}
	}

	/**
	 * Detect browser-driven `<link rel="prefetch">` requests.
	 *
	 * Browsers can mark prefetch requests with different headers:
	 * - `Sec-Purpose: prefetch` (modern Chromium family)
	 * - `Purpose: prefetch` / `X-Purpose: prefetch` (older implementations)
	 * - `X-Moz: prefetch` (Firefox)
	 *
	 * @access private
	 * @static
	 * @return bool
	 */
	private static function is_browser_prefetch_request(): bool {
		$prefetch_headers = array(
			'HTTP_SEC_PURPOSE',
			'HTTP_PURPOSE',
			'HTTP_X_PURPOSE',
			'HTTP_X_MOZ',
		);

		foreach ( $prefetch_headers as $header_key ) {
			if (
				isset( $_SERVER[ $header_key ] ) &&
				false !== strpos( strtolower( (string) $_SERVER[ $header_key ] ), 'prefetch' )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether cache should be bypassed entirely for this request.
	 *
	 * Mirrors the sequence of early returns from the legacy implementation:
	 * - Search queries.
	 * - Specific URIs (robots.txt, .htaccess, some JSON endpoints).
	 * - Certain query parameters (e.g. wc-api).
	 * - Non-GET requests.
	 * - Disallowed extensions (php, xml, xsl except index.php).
	 *
	 * @access private
	 * @static
	 * @return bool
	 */
	private static function should_bypass_entire_request(): bool {
		// Skip caching for search results.
		if ( isset( $_GET['s'] ) && ! empty( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return true;
		}

		$request_uri = (string) $_SERVER['REQUEST_URI'];

		// Skip non-page request types (AJAX/REST/fetch-style requests).
		if ( defined( 'DOING_AJAX' ) && true === DOING_AJAX ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && true === REST_REQUEST ) {
			return true;
		}

		if ( false !== strpos( $request_uri, '/wp-json/' ) || false !== strpos( $request_uri, 'rest_route=' ) ) {
			return true;
		}

		if ( isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && 'xmlhttprequest' === strtolower( (string) $_SERVER['HTTP_X_REQUESTED_WITH'] ) ) {
			return true;
		}

		if (
			isset( $_SERVER['HTTP_SEC_FETCH_MODE'] ) &&
			'navigate' !== strtolower( (string) $_SERVER['HTTP_SEC_FETCH_MODE'] ) &&
			false === self::is_browser_prefetch_request()
		) {
			return true;
		}

		// robots.txt / .htaccess should never be cached.
		if ( false !== strpos( $request_uri, 'robots.txt' ) || false !== strpos( $request_uri, '.htaccess' ) ) {
			return true;
		}

		// Specific endpoints that are not safe or useful to cache.
		if ( false !== strpos( $request_uri, '/wp-json/geodir/' ) ) {
			return true;
		}

		// WooCommerce legacy API calls.
		if ( isset( $_GET['wc-api'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		// Breeze internal/minification endpoints, favicon, and wp-cron.
		if (
			false !== strpos( $request_uri, 'breeze-minification' ) ||
			false !== strpos( $request_uri, 'favicon.ico' ) ||
			false !== strpos( $request_uri, 'wp-cron.php' )
		) {

			return true;
		}

		// Only cache GET requests.
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return true;
		}

		// Do not cache disallowed extensions (prevents caching xmlrpc.php, arbitrary PHP, etc).
		$file_extension = $request_uri;
		$file_extension = (string) preg_replace( '#^(.*?)\?.*$#', '$1', $file_extension );
		$file_extension = trim( (string) preg_replace( '#^.*\.(.*)$#', '$1', $file_extension ) );

		if ( ! preg_match( '#index\.php$#i', $request_uri ) && in_array( $file_extension, array( 'php', 'xml', 'xsl' ), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Build and return request context.
	 *
	 * This reconstructs:
	 * - `$breeze_current_url_path`
	 * - `$filename` / `$filename_guest_suffix`
	 * - Logged-in detection via cookies.
	 *
	 * @param array<string,mixed> $config Breeze configuration.
	 * @access private
	 * @static
	 * @return Request_Context
	 */
	private static function build_request_context( array $config ): Request_Context {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';

		// Build full domain + path URL for exclusion checks.
		$scheme = ( ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) || ( isset( $_SERVER['SERVER_PORT'] ) && '443' === (string) $_SERVER['SERVER_PORT'] ) )
			? 'https://'
			: 'http://';

		$host        = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';
		$current_url = $scheme . $host . rawurldecode( $request_uri );

		// URL used as cache key base (preserves existing query string normalization logic).
		$cache_key_url = self::build_cache_key_url();

		$filename_guest_suffix = '';
		$filename              = $cache_key_url;

		// Default guest suffix.
		if ( substr_count( $cache_key_url, '?' ) > 0 ) {
			$filename             .= '&guest';
			$filename_guest_suffix = '&guest';
		} else {
			$filename             .= '?guest';
			$filename_guest_suffix = '?guest';
		}

		$user_logged = false;

		// Cookie-based logged-in detection with hardening against forged cookie names/values.
		$logged_in_cache_suffix = self::get_logged_in_cache_suffix_from_cookies();
		if ( '' !== $logged_in_cache_suffix ) {
			$user_logged = true;
			if ( substr_count( $cache_key_url, '?' ) > 0 ) {
				$filename = $cache_key_url . '&' . $logged_in_cache_suffix;
			} else {
				$filename = $cache_key_url . '?' . $logged_in_cache_suffix;
			}
		}

		if ( ! empty( $_COOKIE ) ) {

			// If user commented on this post, do not cache (matches legacy rule).
			if ( ! empty( $_COOKIE['breeze_commented_posts'] ) && is_array( $_COOKIE['breeze_commented_posts'] ) ) {
				foreach ( $_COOKIE['breeze_commented_posts'] as $path ) {
					if ( ! empty( $path ) && rtrim( (string) $path, '/' ) === rtrim( $request_uri, '/' ) ) {
						// Signal exclusion via header, actual bypass is handled by caller.
						header( 'Cache-control: must-revalidate, max-age=0' );
						// We still build context; the caller decides to return early.
					}
				}
			}
		}

		return new Request_Context(
			$request_uri,
			$current_url,
			$cache_key_url,
			$filename,
			$filename_guest_suffix,
			$user_logged,
			$config
		);
	}

	/**
	 * Build a logged-in cache suffix from WordPress auth cookies.
	 *
	 * This function runs in early bootstrap where pluggable auth helpers may
	 * not be available. To avoid trusting forgeable username prefixes, it uses
	 * a session-bound HMAC of the full cookie value.
	 *
	 * @return string Empty string when no valid logged-in cookie is found.
	 */
	public static function get_logged_in_cache_suffix_from_cookies(): string {
		$logged_in_cookie_value = self::get_wp_logged_in_cookie_value();
		if ( '' === $logged_in_cookie_value ) {
			return '';
		}

		$secret = '';
		if ( defined( 'LOGGED_IN_SALT' ) && is_string( LOGGED_IN_SALT ) ) {
			$secret = trim( LOGGED_IN_SALT );
		}

		if ( '' === $secret ) {
			return '';
		}

		return 'user_' . hash_hmac( 'sha256', $logged_in_cookie_value, $secret );
	}

	/**
	 * Return the first valid WordPress logged-in cookie value.
	 *
	 * @return string Cookie value or empty string when not found/invalid.
	 */
	private static function get_wp_logged_in_cookie_value(): string {
		if ( empty( $_COOKIE ) || ! is_array( $_COOKIE ) ) {
			return '';
		}

		foreach ( $_COOKIE as $cookie_name => $cookie_value ) {
			$cookie_name  = (string) $cookie_name;
			$cookie_value = (string) $cookie_value;

			// Core format uses wordpress_logged_in_<32-hex-cookiehash>.
			if (
				self::LOGGED_IN_COOKIE_NAME_LENGTH !== strlen( $cookie_name ) ||
				1 !== preg_match( '/^wordpress_logged_in_[a-f0-9]{32}$/', $cookie_name )
			) {
				continue;
			}

			if (
				'' === $cookie_value ||
				strlen( $cookie_value ) > self::MAX_AUTH_COOKIE_VALUE_LENGTH ||
				false !== strpos( $cookie_value, "\0" )
			) {
				continue;
			}

			// Expected auth cookie payload: username|expiration|token|hmac.
			$cookie_parts = explode( '|', $cookie_value, 5 );
			if ( 4 !== count( $cookie_parts ) ) {
				continue;
			}

			$username   = trim( (string) $cookie_parts[0] );
			$expiration = trim( (string) $cookie_parts[1] );
			$token      = trim( (string) $cookie_parts[2] );
			$hmac       = trim( (string) $cookie_parts[3] );

			if (
				'' === $username ||
				'' === $expiration ||
				'' === $token ||
				'' === $hmac
			) {
				continue;
			}

			// Username segment must be a safe printable value without separators.
			if ( 1 !== preg_match( '/^[^\x00-\x1F\x7F|]{1,191}$/', $username ) ) {
				continue;
			}

			// Expiration must be a valid future Unix timestamp.
			if ( ! ctype_digit( $expiration ) ) {
				continue;
			}
			$expiration_timestamp = (int) $expiration;
			if ( $expiration_timestamp <= time() ) {
				continue;
			}

			// Session token and HMAC format validation.
			if ( 1 !== preg_match( '/^[A-Za-z0-9]{20,255}$/', $token ) ) {
				continue;
			}
			if ( 1 !== preg_match( '/^[a-f0-9]{40}$|^[a-f0-9]{64}$/', $hmac ) ) {
				continue;
			}

			return $cookie_value;
		}

		return '';
	}

	/**
	 * Rebuild the URL used as cache key base, mirroring `breeze_get_url_path()`.
	 *
	 * @access private
	 * @static
	 * @return string
	 */
	private static function build_cache_key_url(): string {
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';
		$domain = ( ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) || ( ! empty( $_SERVER['SERVER_PORT'] ) && '443' === (string) $_SERVER['SERVER_PORT'] ) )
			? 'https://'
			: 'http://';

		$the_url = $domain . rtrim( $host, '/' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/' );

		$query_instance         = Breeze_Query_Strings_Rules::get_instance();
		$breeze_query_vars_list = $query_instance->check_query_var_group( $the_url );
		if ( 0 !== (int) $breeze_query_vars_list['ignored_no'] ) {
			$the_url = $query_instance->rebuild_url( $the_url, $breeze_query_vars_list );
		}

		return $the_url;
	}

	/**
	 * Determine if the current page is excluded from caching.
	 *
	 * @param array<string,mixed> $config  Breeze configuration.
	 * @param Request_Context     $context Current request context.
	 *
	 * @return bool
	 * @access private
	 * @static
	 */
	private static function is_excluded_page( array $config, Request_Context $context ): bool {
		$is_feed = \breeze_is_feed( $context->current_url );

		if ( true === $is_feed ) {
			return true;
		}

		$is_amp = \breeze_uri_amp_check( $context->current_url );
		if ( true === $is_amp ) {
			return true;
		}

		if ( ! empty( $_COOKIE['breeze_commented_posts'] ) ) {
			foreach ( $_COOKIE['breeze_commented_posts'] as $path ) {
				if ( ! empty( $path ) ) {
					if ( rtrim( (string) $path, '/' ) === rtrim( (string) $_SERVER['REQUEST_URI'], '/' ) ) {
						// User commented on this post
						return true;
					}
				}
			}
		}

		// Exclude password-protected URLs early so cache file reads are bypassed too.
		if ( function_exists( '\breeze_is_current_request_password_protected_cached' ) && \breeze_is_current_request_password_protected_cached() ) {
			return true;
		}

		if ( empty( $config['exclude_url'] ) || ! is_array( $config['exclude_url'] ) ) {
			return false;
		}

		// First pass: regexp based exclusions using helper equivalent to legacy code.
		$is_exclude = self::exec_breeze_check_for_exclude_values( $context->current_url, $config['exclude_url'] );
		if ( ! empty( $is_exclude ) ) {
			return true;
		}

		// Second pass: explicit rules (regex and full URL matches).
		foreach ( $config['exclude_url'] as $exclude_url ) {
			$exclude_url = trim( (string) $exclude_url );
			if ( '' === $exclude_url ) {
				continue;
			}

			if ( preg_match( '/(\&?\/?\(\.?\*\)|\/\*|\*)$/', $exclude_url, $matches ) ) {
				// Rule ends with *, /*, [&][/](*) , [&][/](.*).
				$pattern = substr( $exclude_url, 0, strpos( $exclude_url, $matches[0] ) );
				if ( '' === $pattern ) {
					continue;
				}

				if ( '/' === $exclude_url[0] ) {
					// Path-based regex.
					if ( @preg_match( '@' . $pattern . '@', $context->current_url, $matches ) > 0 ) {
						return true;
					}
				} else {
					// Full URL substring match.
					if ( false !== strpos( $context->current_url, $pattern ) ) {
						return true;
					}
				}
			} else {
				if ( '/' === $exclude_url[0] ) {
					// Path of exclude.
					if ( @preg_match( '@' . $exclude_url . '@', $context->current_url, $matches ) > 0 ) {
						return true;
					}
				} else {
					// Whole URL match.
					$exclude_url_normalized = ltrim( $exclude_url, 'https:' );
					$current_url_normalized = ltrim( $context->current_url, 'https:' );

					if (
						mb_strtolower( $exclude_url_normalized ) === mb_strtolower( $current_url_normalized ) ||
						self::trailingslashit( mb_strtolower( $exclude_url_normalized ) ) === self::trailingslashit( mb_strtolower( $current_url_normalized ) )
					) {
						return true;
					}
				}
			}
		}

		// Additional exclusion based on query vars list (matches legacy flow).
		$query_instance         = Breeze_Query_Strings_Rules::get_instance();
		$breeze_query_vars_list = $query_instance->check_query_var_group( $context->current_url );
		if ( 0 !== (int) $breeze_query_vars_list['extra_query_no'] ) {
			header( 'Cache-control: must-revalidate, max-age=0' );
			return true;
		}

		return false;
	}

	/**
	 * Attempt to serve a cached response for the current request.
	 *
	 * If a valid cache file is found, this method sends the headers and body
	 * and terminates execution (via `exit`), matching the legacy behaviour.
	 *
	 * @param array<string,mixed> $config  Breeze configuration.
	 * @param Request_Context     $context Current request context.
	 * @param mixed               $detect  Mobile detect instance.
	 * @access private
	 * @static
	 *
	 * @return void
	 */
	private static function try_serve_cache( array $config, Request_Context $context, $detect ): void {
		if ( empty( $config['cache_options'] ) || ! is_array( $config['cache_options'] ) ) {
			return;
		}

		// Check for user's currency and generate ETag to handle currency-specific caching
		if (isset($_COOKIE['wcml_client_currency'])) {
			// Create unique hash based on currency and request URI
			$hash = hash('sha1', $_COOKIE['wcml_client_currency'] .  ( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/' ));
			// Add currency-specific ETag header
			header('ETag: "currency-' . $hash . '"');
		}

		$devices = $config['cache_options'];
		$X1      = '';

		// Detect device and adjust filename suffix, mirroring legacy ordering.
		if ( $detect->isMobile() && ! $detect->isTablet() ) {
			if ( isset( $devices['breeze-mobile-cache'] ) && 1 === (int) $devices['breeze-mobile-cache'] ) {
				$context->filename .= '_breeze_cache_desktop';
			}
			if ( isset( $devices['breeze-mobile-cache'] ) && 2 === (int) $devices['breeze-mobile-cache'] ) {
				$context->filename .= '_breeze_cache_mobile';
			}
		} else {
			if ( isset( $devices['breeze-desktop-cache'] ) && 1 === (int) $devices['breeze-desktop-cache'] ) {
				$context->filename .= '_breeze_cache_desktop';
			}
		}

		$X1 = 'D';

		if ( true === \is_breeze_mobile_cache() ) {
			if ( true === \breeze_is_cloudways_server() ) {
				$X1 = \breeze_cache_type_return();
			} else {
				if ( $detect->isMobile() ) {
					if ( ! $detect->isTablet() ) {
						$X1 = 'M';
					} else {
						$X1 = 'T';
					}
				} else {
					$X1 = 'D';
				}
			}
		}

		self::serve_cache_file( $context, $X1 );
	}

	/**
	 * Low-level cache file reader and responder.
	 *
	 * This closely mirrors the legacy `breeze_serve_cache()` function, but is
	 * now encapsulated and documented. For now, it still relies on
	 * `unserialize()` and simple error handling to keep behaviour identical.
	 *
	 * @param Request_Context $context Request context.
	 * @param string          $X1      Cache-provider suffix (`D`, `M`, `T`).
	 * @access private
	 * @static
	 *
	 * @return void
	 */
	private static function serve_cache_file( Request_Context $context, string $X1 ): void {
		// Circuit Breaker: Check if cache operations are allowed
		if ( ! Cache_Circuit_Breaker::is_cache_allowed() ) {
			// Cache is disabled due to circuit breaker - bypass cache
			if ( ! headers_sent() ) {
				header( 'X-Breeze-Cache: BYPASSED-CIRCUIT-BREAKER' );
			}
			return;
		}

		if ( false === strpos( $context->filename, '_breeze_cache_' ) ) {
			return;
		}

		$is_suffix   = \breeze_currency_switcher_cache();
		$should_gzip = \function_exists( 'gzencode' ) && self::should_gzip_output( $context->config );

		if ( $should_gzip ) {
			$file_name = hash( 'sha256', $context->filename . '/index.gzip.html' ) . $is_suffix . '.html';
		} else {
			$file_name = hash( 'sha256', $context->filename . '/index.html' ) . $is_suffix . '.html';
		}

		$blog_id_requested = isset( $context->config['blog_id'] ) ? (int) $context->config['blog_id'] : 0;
		$path              = \breeze_get_cache_base_path( false, $blog_id_requested ) . hash( 'sha256', $context->cache_key_url ) . '/' . \breeze_mobile_detect() . $file_name;

		if ( ! @file_exists( $path ) ) {
			return;
		}

		// $cache_file = @file_get_contents( $path );
		// Use shared lock (LOCK_SH) - multiple readers allowed simultaneously
		$fp = @fopen( $path, 'r' );
		if ( false === $fp ) {
			$error_msg = 'Failed to open cache file: ' . $path;
			error_log( '[Breeze] ' . $error_msg );
			Cache_Circuit_Breaker::record_failure( $error_msg );
			return;
		}

		if ( ! flock( $fp, LOCK_SH ) ) {  // Shared lock
			fclose( $fp );
			Cache_Circuit_Breaker::record_failure( 'Failed to acquire shared lock on cache file' );
			return;
		}

		$cache_file = stream_get_contents( $fp );
		flock( $fp, LOCK_UN );
		fclose( $fp );

		if ( false === $cache_file ) {
			$error     = error_get_last();
			$error_msg = 'Failed to read cache: ' . $path . ' - ' . ( $error['message'] ?? 'unknown' );
			error_log( '[Breeze] ' . $error_msg );
			Cache_Circuit_Breaker::record_failure( $error_msg );
			return;
		}

		$datas = @unserialize( $cache_file, array( 'allowed_classes' => false ) );
		if ( ! is_array( $datas ) || empty( $datas['headers'] ) ) {
			// Cache file is corrupted - delete it
			$error_msg = 'Corrupted cache file detected: ' . $path;
			error_log( '[Breeze] ' . $error_msg );
			Cache_Circuit_Breaker::record_failure( $error_msg );
			@unlink( $path );  // Remove corrupted cache
			return;
		}

		// 304 Not Modified Support: Check If-Modified-Since header
		$cache_modified_time = @filemtime( $path );
		if ( false !== $cache_modified_time && isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
			$if_modified_since = strtotime( $_SERVER['HTTP_IF_MODIFIED_SINCE'] );

			// If client's cached version is still valid (cache file hasn't been modified since client's version)
			if ( false !== $if_modified_since && $cache_modified_time <= $if_modified_since ) {
				// Send 304 Not Modified response - no body needed
				header( 'HTTP/1.1 304 Not Modified', true, 304 );
				header( 'Cache-Provider:CLOUDWAYS-CACHE-' . $X1 . 'E' );
				header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $cache_modified_time ) . ' GMT' );

				// Add Vary header if gzip compression is enabled
				if ( ! empty( $context->config['cache_options']['breeze-gzip-compression'] ) && self::should_bypass_php_gzip() ) {
					header( 'Vary: Accept-Encoding' );
				}

				// Record success for circuit breaker
				Cache_Circuit_Breaker::record_success();

				exit; // Exit without sending body - saves bandwidth!
			}
		}

		if ( isset( $datas['headers'] ) && is_array( $datas['headers'] ) ) {
			self::send_sanitized_cached_headers( $datas['headers'] );
		}

		// Set cache provider header.
		header( 'Cache-Provider:CLOUDWAYS-CACHE-' . $X1 . 'E' );

		// Send Last-Modified header for future 304 checks
		if ( false !== $cache_modified_time ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $cache_modified_time ) . ' GMT' );
		}

		$client_support_gzip = true;
		if ( empty( $_SERVER['HTTP_ACCEPT_ENCODING'] ) || false === stripos( (string) $_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip' ) ) {
			$client_support_gzip = false;
		}

		if ( isset( $context->config['breeze_custom_headers'] ) && is_array( $context->config['breeze_custom_headers'] ) ) {
			foreach ( $context->config['breeze_custom_headers'] as $header_name => $header_value ) {
				header( $header_name . ': ' . $header_value );
			}
		}

		if ( ! empty( $context->config['cache_options']['breeze-gzip-compression'] ) && self::should_bypass_php_gzip() ) {
			header( 'Vary: Accept-Encoding' );
		}

		if ( $client_support_gzip && \function_exists( 'gzencode' ) && self::should_gzip_output( $context->config ) ) {
			$content = gzencode( (string) $datas['body'], 9 );
			header( 'Content-Encoding: gzip' );
			header( 'Content-Length: ' . strlen( $content ) );
			header( 'Vary: Accept-Encoding' );

			// Record success for circuit breaker
			Cache_Circuit_Breaker::record_success();

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary gzipped content cannot be escaped.
			echo $content;
		} else {
			header( 'Content-Length: ' . strlen( (string) $datas['body'] ) );

				// Record success for circuit breaker
				Cache_Circuit_Breaker::record_success();

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Cached HTML content from WordPress.
			echo $datas['body'];
		}

		exit;
	}

	/**
	 * Send sanitized headers restored from cache payload.
	 *
	 * Keeps user-defined security headers while blocking dangerous hop-by-hop
	 * and transport-level headers that should be controlled at runtime.
	 *
	 * @param array<int,array<string,string>> $cached_headers Cached header pairs.
	 * @return void
	 */
	private static function send_sanitized_cached_headers( array $cached_headers ): void {
		$blocked_headers = array(
			'set-cookie',
			'content-length',
			'transfer-encoding',
			'connection',
			'keep-alive',
			'proxy-authenticate',
			'proxy-authorization',
			'te',
			'trailer',
			'upgrade',
			'status',
		);

		foreach ( $cached_headers as $header_item ) {
			if ( ! isset( $header_item['name'], $header_item['value'] ) ) {
				continue;
			}

			$header_name  = trim( (string) $header_item['name'] );
			$header_value = (string) $header_item['value'];

			// Header name token validation.
			if ( '' === $header_name || 1 !== preg_match( '/^[A-Za-z0-9-]+$/', $header_name ) ) {
				continue;
			}

			$header_name_lc = strtolower( $header_name );
			if ( in_array( $header_name_lc, $blocked_headers, true ) ) {
				continue;
			}

			// Allow larger policy headers (CSP) while keeping tighter limits for others.
			$header_value_limit = 8192;
			if (
				'content-security-policy' === $header_name_lc ||
				'content-security-policy-report-only' === $header_name_lc
			) {
				$header_value_limit = 12288;
			}

			if ( strlen( $header_value ) > $header_value_limit ) {
				continue;
			}

			// Block all ASCII control characters (0x00–0x1F except HT 0x09, and DEL 0x7F).
			// This covers CR (\r 0x0D), LF (\n 0x0A), null (\0 0x00), VT (\x0B), FF (\x0C), etc.
			if ( 1 === preg_match( '/[\x00-\x08\x0A-\x1F\x7F]/', $header_value ) ) {
				continue;
			}

			header( $header_name . ': ' . $header_value );
		}
	}

	/**
	 * Check if caching is disabled for current admin user based on cookies and config.
	 *
	 * Logic adapted from the top of the legacy file under `$GLOBALS['breeze_config']['disable_per_adminuser']`.
	 *
	 * @param array<string,mixed> $config Breeze configuration.
	 * @access private
	 * @static
	 *
	 * @return bool True when caching should be disabled for this request.
	 */
	private static function is_cache_disabled_for_current_user( array $config ): bool {
		if ( ! isset( $config['disable_per_adminuser'] ) || ! is_array( $config['disable_per_adminuser'] ) ) {
			return false;
		}

		$breeze_user_logged      = false;
		$breeze_use_cache_system = (bool) filter_var( $config['cache_options']['breeze-active'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$folder_cache            = array();

		foreach ( $_COOKIE as $key => $value ) {
			if ( false !== strpos( (string) $key, 'wordpress_logged_in_' ) ) {
				$breeze_user_logged = true;
			}

			if ( defined( 'BREEZE_WP_COOKIE' ) && BREEZE_WP_COOKIE === $key ) {
				$folder_cache = \breeze_which_role_folder( (string) $value );
			}
		}

		$breeze_any_role_logged_in_cache = false;
		foreach ( $config['disable_per_adminuser'] as $flag ) {
			if ( true === filter_var( $flag, FILTER_VALIDATE_BOOLEAN ) ) {
				$breeze_any_role_logged_in_cache = true;
				break;
			}
		}

		// Legacy upgrade compatibility: if user is logged in but Breeze role cookie is missing,
		// allow caching only when at least one logged-in role cache option is enabled.
		if ( true === $breeze_user_logged && empty( $folder_cache ) && ! $breeze_any_role_logged_in_cache ) {
			return true;
		}

		if ( ! empty( $folder_cache ) ) {
			$is_active = false;
			foreach ( $folder_cache as $cache_role ) {
				if (
					isset( $config['disable_per_adminuser'][ $cache_role ] ) &&
					true === filter_var( $config['disable_per_adminuser'][ $cache_role ], FILTER_VALIDATE_BOOLEAN )
				) {
					$is_active = true;
				}
			}

			$breeze_use_cache_system = $is_active;
		}

		return ( true === $breeze_user_logged && false === $breeze_use_cache_system );
	}

	/**
	 * Determine whether PHP gzip should be bypassed for multisite + Varnish.
	 *
		 * This mirrors the legacy `breeze_should_bypass_php_gzip()` behaviour.
		 *
		 * @access public
		 * @static
	 * @return bool
	 */
	public static function should_bypass_php_gzip(): bool {
		if ( ! \is_multisite() ) {
			return false;
		}

		if ( empty( $_SERVER['HTTP_X_VARNISH'] ) ) {
			return false;
		}

		if ( empty( $_SERVER['HTTP_X_APPLICATION'] ) ) {
			return false;
		}

		$application = trim( (string) $_SERVER['HTTP_X_APPLICATION'] );
		if ( '' === $application || 'varnishpass' === $application || 'bypass' === $application ) {
			return false;
		}

		return true;
	}

	/**
	 * Determine whether Breeze should gzip responses at the PHP layer.
	 *
		 * This mirrors the legacy `breeze_should_gzip_output()` function but uses
		 * the provided configuration array instead of `$GLOBALS`.
		 *
		 * @param array<string,mixed> $config Breeze configuration.
		 * @access public
		 * @static
		 *
	 * @return bool
	 */
	public static function should_gzip_output( array $config ): bool {
		$setting_enabled = ! empty( $config['cache_options']['breeze-gzip-compression'] );
		$should_gzip     = $setting_enabled;

		$ini_output_compression = (string) \ini_get( 'zlib.output_compression' );
		$array_values           = array( '1', 'On', 'on' );
		if ( \in_array( $ini_output_compression, $array_values, true ) ) {
			$should_gzip = false;
		}

		$should_bypass = self::should_bypass_php_gzip();
		if ( $should_bypass ) {
			$should_gzip = false;
		}

		/**
		 * Filter to allow other plugins to influence gzip behaviour.
		 *
		 * @param bool   $should_gzip            Whether Breeze should gzip output.
		 * @param bool   $setting_enabled        Whether the gzip setting is enabled in Breeze.
		 * @param bool   $should_bypass          Whether gzip should be bypassed due to Varnish/multisite.
		 * @param string $ini_output_compression Raw `zlib.output_compression` ini value.
		 */
		return (bool) \apply_filters( 'breeze_should_gzip_output', $should_gzip, $setting_enabled, $should_bypass, $ini_output_compression );
	}

	/**
	 * Used to check for regexp exclude pages.
	 *
	 * This is a direct adaptation of the legacy `exec_breeze_check_for_exclude_values()`
	 * helper, kept private to this class.
	 *
	 * @param string              $needle   URL to check.
	 * @param array<int,string>   $haystack Exclusion rules.
	 * @access private
	 * @static
	 * @return array<int,string>
	 */
	private static function exec_breeze_check_for_exclude_values( string $needle, array $haystack ): array {
		if ( '' === $needle || empty( $haystack ) ) {
			return array();
		}

		$needle             = trim( $needle );
		$is_string_in_array = array_filter(
			$haystack,
			static function ( $var ) use ( $needle ) {
				if ( self::exec_breeze_string_contains_exclude_regexp( $var ) ) {
					return self::exec_breeze_file_match_pattern( $needle, $var );
				}

				return false;
			}
		);

		return $is_string_in_array;
	}

	/**
		 * Determine if the excluded URL contains a regexp marker.
		 *
		 * @param string $file_url URL pattern.
		 * @param string $validate Validation pattern (default `(.*)`).
		 * @access private
		 * @static
	 *
		 * @return bool
		 */
	private static function exec_breeze_string_contains_exclude_regexp( string $file_url, string $validate = '(.*)' ): bool {
		if ( '' === $file_url || '' === $validate ) {
			return false;
		}

		return ( substr_count( $file_url, $validate ) !== 0 );
	}

	/**
	 * Prepare URLs escaped for `preg_match` and check if they match the pattern.
	 *
	 * @param string $file_url URL to test.
	 * @param string $pattern  Pattern containing `(.*)` wildcard.
	 * @access private
	 * @static
	 *
	 * @return int 1 when it matches, 0 when not, -1 on error (same as `preg_match`).
	 */
	private static function exec_breeze_file_match_pattern( string $file_url, string $pattern ): int {
		$remove_pattern   = str_replace( '(.*)', 'REG_EXP_ALL', $pattern );
		$prepared_pattern = preg_quote( $remove_pattern, '/' );
		$pattern          = str_replace( 'REG_EXP_ALL', '(.*)', $prepared_pattern );

		return (int) preg_match( '/' . $pattern . '/', $file_url );
	}

	/**
	 * Trim trailing slashes from a string and ensure exactly one slash at the end.
	 *
	 * Mirrors `br_trailingslashit()` / `br_untrailingslashit()` from legacy code
	 * but without adding global functions.
	 *
	 * @param string $value Input value.
	 * @access private
	 * @static
 *
 * @return string
	 */
	private static function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

/**
 * Output buffer handler responsible for generating and writing cache files.
 *
 * This is the class-based equivalent of the legacy `breeze_cache()` function.
 * @final
 */
final class Page_Cache_Handler {

	/** @var array<string,mixed> */
	private $config;

	/** @var Request_Context */
	private $context;

	/**
	 * @param array<string,mixed> $config  Breeze configuration.
	 * @param Request_Context     $context Request context.
	 */
	public function __construct( array $config, Request_Context $context ) {
		$this->config  = $config;
		$this->context = $context;
	}

	/**
	 * Output buffer callback.
	 *
	 * @param string $buffer HTML output from WordPress.
	 * @param int    $flags  Output buffer flags (passed through to gzip handler).
	 *
	 * @return string Buffer to ultimately send to the browser.
	 * @access public
	 */
	public function handle_buffer( string $buffer, int $flags ): string {
		// Circuit Breaker: Check if cache operations are allowed
		if ( ! Cache_Circuit_Breaker::is_cache_allowed() ) {

			// Cache is disabled due to circuit breaker - bypass cache, just return buffer
			if ( ! headers_sent() ) {
				header( 'X-Breeze-Cache: BYPASSED-CIRCUIT-BREAKER' );
			}
			return $buffer;
		}

		// Respect DONOTCACHEPAGE constant from WooCommerce and other plugins.
		if ( $this->constant_donotcachepage_found() ) {
			return $buffer;
		}

		// Only cache successful 200 responses.
		if ( \http_response_code() !== 200 ) {
			return $buffer;
		}

		$detect = \breeze_mobile_detect_library();
		if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$_SERVER['HTTP_USER_AGENT'] = '';
		}
		$detect->setUserAgent( (string) $_SERVER['HTTP_USER_AGENT'] );

		// Honour "disable optimization for admin users" setting.
		if ( $this->is_admin_user_cache_disabled() ) {
			return $buffer;
		}
 
		// Skip very small responses.
		if ( strlen( $buffer ) < 255 ) {
			return $buffer;
		}

		// Don't cache search, 404, or password-protected content.
		if ( \is_404() || \is_search() || \post_password_required() ) {
			return $buffer;
		}
 
		// Exclude password-protected pages from caching using prebuilt path index.
		if ( function_exists( '\breeze_is_current_request_password_protected_cached' ) && \breeze_is_current_request_password_protected_cached() ) {
			$GLOBALS['breeze_bypass_cache'] = true;
			return $buffer;
		}

		// Allow plugins to modify the buffer before caching.
		$buffer = (string) \apply_filters( 'breeze_cache_buffer_before_processing', $buffer );

		$blog_id_requested = isset( $this->config['blog_id'] ) ? (int) $this->config['blog_id'] : 0;
		$cache_base_path   = \breeze_get_cache_base_path( false, $blog_id_requested );
		$path              = $cache_base_path . hash( 'sha256', $this->context->cache_key_url );

		// Ensure cache directory exists and is writable.
		if ( ! \wp_mkdir_p( $path ) ) {
			Cache_Circuit_Breaker::record_failure( 'Failed to create cache directory: ' . $path );
			return $buffer;
		}
		$path         .= '/';
		$modified_time = time();

		$is_cross_origin_activated = false;
		if ( isset( $this->config['cache_options']['breeze-cross-origin'] ) ) {
			$is_cross_origin_activated = (bool) filter_var( $this->config['cache_options']['breeze-cross-origin'], FILTER_VALIDATE_BOOLEAN );
		}

		// Lazy load implementation.
		if ( \class_exists( '\Breeze_Lazy_Load' ) && isset( $this->config ) ) {
			if ( ! isset( $this->config['enabled-lazy-load'] ) ) {
				$this->config['enabled-lazy-load'] = false;
			}

			if ( ! isset( $this->config['use-lazy-load-native'] ) ) {
				$this->config['use-lazy-load-native'] = false;
			}

			$is_lazy_load_enabled = (bool) filter_var( $this->config['enabled-lazy-load'], FILTER_VALIDATE_BOOLEAN );
			$is_lazy_load_native  = (bool) filter_var( $this->config['use-lazy-load-native'], FILTER_VALIDATE_BOOLEAN );

			$lazy_load = new \Breeze_Lazy_Load( $buffer, $is_lazy_load_enabled, $is_lazy_load_native );
			$buffer    = $lazy_load->apply_lazy_load_feature();
		}

		// Cross-origin safe link functionality (unchanged behaviour).
		if ( true === $is_cross_origin_activated ) {
			if ( version_compare( PHP_VERSION, '8.2.0', '<' ) ) {
				$buffer = (string) \mb_convert_encoding( $buffer, 'HTML-ENTITIES', 'UTF-8' );
			} else {
				$buffer = (string) \mb_encode_numericentity(
					$buffer,
					array( 0x80, 0x10FFFF, 0, ~0 ),
					'UTF-8'
				);
			}

			$pattern = '/<a\s+(.*?)>/si';
			$buffer  = (string) \preg_replace_callback(
				$pattern,
				static function ( $matches ) {
					return breeze_cc_process_match( $matches );
				},
				$buffer
			);

				$buffer = (string) \mb_decode_numericentity( $buffer, array( 0x80, 0x10FFFF, 0, ~0 ), 'UTF-8' );
		}

		$cache_type = '';
		if ( \preg_match( '#</html>#i', $buffer ) ) {
			if ( true === \is_breeze_mobile_cache() ) {
				if ( true === \breeze_is_cloudways_server() ) {
					$cache_type_cloudways = \breeze_cache_type_return();
					if ( 'D' === $cache_type_cloudways ) {
						$cache_type = ' (Desktop)';
					} elseif ( 'T' === $cache_type_cloudways ) {
						$cache_type = ' (Tablet)';
					} elseif ( 'M' === $cache_type_cloudways ) {
						$cache_type = ' (Mobile)';
					}
				} else {
					if ( $detect->isMobile() ) {
						if ( ! $detect->isTablet() ) {
							$cache_type = ' (Mobile)';
						} else {
							$cache_type = ' (Tablet)';
						}
					} else {
						$cache_type = ' (Desktop)';
					}
				}
			}

			$buffer .= "\n<!-- Cache served by breeze CACHE{$cache_type} - Last modified: " . gmdate( 'D, d M Y H:i:s', $modified_time ) . " GMT -->\n";
		}

		$headers = array(
			array(
				'name'  => 'Content-Length',
				'value' => strlen( $buffer ),
			),
			array(
				'name'  => 'Content-Type',
				'value' => 'text/html; charset=utf-8',
			),
			array(
				'name'  => 'Last-Modified',
				'value' => gmdate( 'D, d M Y H:i:s', $modified_time ) . ' GMT',
			),
		);

		if ( isset( $this->config['breeze_custom_headers'] ) && is_array( $this->config['breeze_custom_headers'] ) ) {
			foreach ( $this->config['breeze_custom_headers'] as $header_name => $header_value ) {
				$headers[] = array(
					'name'  => $header_name,
					'value' => $header_value,
				);
			}
		}

		// NOTE: For now we keep serialize() to preserve exact behaviour.
		$data = serialize(
			array(
				'body'    => $buffer,
				'headers' => $headers,
			)
		);

		// Allow plugins to modify the buffer even after caching logic.
		$buffer = (string) \apply_filters( 'breeze_cache_buffer_after_processing', $buffer );

		// User-specific cache key handling.
		$cache_key_url = $this->context->cache_key_url;

		if ( \is_user_logged_in() ) {
			$logged_in_cache_suffix = Execute_Cache::get_logged_in_cache_suffix_from_cookies();
			if ( '' !== $logged_in_cache_suffix ) {
				if ( substr_count( $cache_key_url, '?' ) > 0 ) {
					$cache_key_url .= '&' . $logged_in_cache_suffix;
				} else {
					$cache_key_url .= '?' . $logged_in_cache_suffix;
				}
			}
		} else {
			$cache_key_url .= $this->context->filename_guest_suffix;
		}

		$devices = $this->config['cache_options'] ?? array();
		if ( $detect->isMobile() && ! $detect->isTablet() ) {
			if ( isset( $devices['breeze-mobile-cache'] ) && 1 === (int) $devices['breeze-mobile-cache'] ) {
				$cache_key_url .= '_breeze_cache_desktop';
			}
			if ( isset( $devices['breeze-mobile-cache'] ) && 2 === (int) $devices['breeze-mobile-cache'] ) {
				$cache_key_url .= '_breeze_cache_mobile';
			}
		} else {
			if ( isset( $devices['breeze-desktop-cache'] ) && 1 === (int) $devices['breeze-desktop-cache'] ) {
				$cache_key_url .= '_breeze_cache_desktop';
			}
		}
		$X1 = 'D';
		if ( true === \is_breeze_mobile_cache() ) {
			if ( true === \breeze_is_cloudways_server() ) {
				$X1 = \breeze_cache_type_return();
			} else {
				if ( $detect->isMobile() ) {
					if ( ! $detect->isTablet() ) {
						$X1 = 'M';
					} else {
						$X1 = 'T';
					}
				} else {
					$X1 = 'D';
				}
			}
		}

		$is_suffix = \breeze_currency_switcher_cache();

		if ( false !== strpos( $cache_key_url, '_breeze_cache_' ) ) {
			$trimmed_buffer = trim( $buffer );
			$is_json_buffer = (
				'' !== $trimmed_buffer &&
				( '{' === $trimmed_buffer[0] || '[' === $trimmed_buffer[0] ) &&
				null !== json_decode( $buffer )
			);
			if ( $is_json_buffer ) {
				return $buffer;
			}

			$should_gzip = \function_exists( 'gzencode' ) && Execute_Cache::should_gzip_output( $this->config );

			if ( $should_gzip ) {
				$cache_file_path = $path . \breeze_mobile_detect() . hash( 'sha256', $cache_key_url . '/index.gzip.html' ) . $is_suffix . '.html';
			} else {
				$cache_file_path = $path . \breeze_mobile_detect() . hash( 'sha256', $cache_key_url . '/index.html' ) . $is_suffix . '.html';
			}

			if ( ! file_exists( $cache_file_path ) ) {
				$cache_written = \breeze_safe_cache_write( $cache_file_path, $data, $modified_time, false );
			} else {
				$cache_written = true;
			}

			if ( ! $cache_written ) {
				// Log the failure but continue serving the page
				$error_msg = 'Cache write failed for URL: ' . $this->context->current_url . ' (Path: ' . $cache_file_path . ')';
				error_log( '[Breeze] ' . $error_msg );
				Cache_Circuit_Breaker::record_failure( $error_msg );
				// Add header to indicate cache write failed
				header( 'X-Breeze-Cache-Write: FAILED' );
			} else {
				// Indicate successful cache write
				Cache_Circuit_Breaker::record_success();
				header( 'X-Breeze-Cache-Write: SUCCESS' );
			}
		} else {
			return $buffer;
		}

		// Set cache provider header if cache file did not previously exist.
		header( 'Cache-Provider:CLOUDWAYS-CACHE-' . $X1 . 'C' );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified_time ) . ' GMT' );
		if (
			! empty( $this->config['cache_options']['breeze-gzip-compression'] ) &&
			Execute_Cache::should_bypass_php_gzip()
		) {
			header( 'Vary: Accept-Encoding' );
		}

		if ( \function_exists( 'ob_gzhandler' ) && Execute_Cache::should_gzip_output( $this->config ) ) {
			if ( defined( 'RedisCachePro\Version' ) ) {
				return $buffer;
			}

			$gz_output = \ob_gzhandler( $buffer, $flags );
			if ( is_string( $gz_output ) && '' !== $gz_output ) {
				return $gz_output;
			}
			return $buffer;
		}

		return $buffer;
	}

	/**
		 * Check for the DONOTCACHEPAGE constant used by WooCommerce and other plugins.
		 *
		 * @access private
		 * @return bool
	 */
	private function constant_donotcachepage_found(): bool {
		if ( ! defined( 'DONOTCACHEPAGE' ) || ! DONOTCACHEPAGE ) {
			return false;
		}

		return ! (bool) \apply_filters( 'breeze_override_donotcachepage', false );
	}

	/**
		 * Determine if cache is disabled for the current admin user.
	 *
		 * This mirrors the `$breeze_user_logged` / `$breeze_use_cache_system`
		 * logic from the legacy implementation.
	 *
		 * @access private
	 * @return bool
	 */
	private function is_admin_user_cache_disabled(): bool {
		if ( ! isset( $this->config['disable_per_adminuser'] ) || ! is_array( $this->config['disable_per_adminuser'] ) ) {
			return false;
		}

		$breeze_user_logged      = false;
		$breeze_use_cache_system = (bool) filter_var( $this->config['cache_options']['breeze-active'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$folder_cache            = array();

		foreach ( $_COOKIE as $key => $value ) {
			if ( false !== strpos( (string) $key, 'wordpress_logged_in_' ) ) {
				$breeze_user_logged = true;
			}

			if ( defined( 'BREEZE_WP_COOKIE' ) && BREEZE_WP_COOKIE === $key ) {
				$folder_cache = \breeze_which_role_folder( (string) $value );
			}
		}

		if ( ! empty( $folder_cache ) ) {
			$is_active = false;
			foreach ( $folder_cache as $cache_role ) {
				if (
					isset( $this->config['disable_per_adminuser'][ $cache_role ] ) &&
					true === filter_var( $this->config['disable_per_adminuser'][ $cache_role ], FILTER_VALIDATE_BOOLEAN )
				) {
					$is_active = true;
				}
			}

			$breeze_use_cache_system = $is_active;
		}

		return ( true === $breeze_user_logged && false === $breeze_use_cache_system );
	}
}

/**
 * Preg replace callback function for anchor handling.
 *
 * This is the namespaced equivalent of the legacy `breeze_cc_process_match()`
 * function and is kept as a function (not a method) so it can be used as a
 * simple callback in `preg_replace_callback`.
 *
 * @param array<int,string> $match Regex match data.
 *
 * @return string
 */
function breeze_cc_process_match( array $match ): string {
	// Get the home URL.
	$home_url = isset( $GLOBALS['breeze_config']['homepage'] ) ? (string) $GLOBALS['breeze_config']['homepage'] : '';
	$home_url = ltrim( $home_url, 'https:' );

	// Set the rel attribute values.
	$replacement_rel_arr = array( 'noopener', 'noreferrer' );

	// Extract the href and target attributes.
	$href_attr   = '';
	$target_attr = '';
	\preg_match( '/href=(\'|")(.*?)\\1/si', $match[1], $href_match );
	\preg_match( '/target=(\'|")(.*?)\\1/si', $match[1], $target_match );

	if ( $href_match ) {
		$href_attr = $href_match[2];
	}
	if ( $target_match ) {
		$target_attr = $target_match[2];
	}

	// Check if this is an external link.
	if (
		! empty( $href_attr ) &&
		\filter_var( $href_attr, FILTER_VALIDATE_URL ) &&
		false === strpos( $href_attr, $home_url ) &&
		false !== strpos( (string) $target_attr, '_blank' )
	) {
		// Extract the rel attribute, if present.
		$rel_attr = '';
		\preg_match( '/rel=(\'|")(.*?)\\1/si', $match[1], $rel_match );
		if ( $rel_match ) {
			$rel_attr = $rel_match[2];
		}

		// Set or modify the rel attribute as necessary.
		if ( '' === $rel_attr ) {
			return '<a ' . $match[1] . ' rel="noopener noreferrer">';
		}

			$existing_rels = explode( ' ', $rel_attr );
			$existing_rels = array_unique( array_merge( $replacement_rel_arr, $existing_rels ) );

			return '<a ' . str_replace( $rel_attr, implode( ' ', $existing_rels ), $match[1] ) . '>';
	}

	// If this is not an external link, just return the matched string.
		return '<a ' . $match[1] . '>';
}
