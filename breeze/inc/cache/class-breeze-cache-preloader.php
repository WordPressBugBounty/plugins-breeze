<?php
/**
 * Breeze Cache Preloader - Proactive background cache warming.
 *
 * Collects a list of important URLs (auto-detected from installed plugins +
 * user-defined) and warms their cache in the background via Action Scheduler
 * whenever the full cache is cleared.
 *
 * @package Breeze
 * 
 */

namespace Breeze\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Proactive cache warming triggered on full cache purge.
 */
class Breeze_Cache_Preloader {

	/**
	 * Action Scheduler hook name for a single URL preload job (legacy — kept for
	 * backward compatibility with any jobs still in the AS queue from v1).
	 */
	const AS_HOOK = 'breeze_preload_single_url';

	/**
	 * Action Scheduler hook name for the self-scheduling chain worker.
	 * Only one of these is ever pending at a time; it pops one URL,
	 * warms it, then schedules the next.
	 */
	const CHAIN_HOOK = 'breeze_preload_next';

	/**
	 * Action Scheduler group for all preload jobs.
	 */
	const AS_GROUP = 'breeze-preload';

	/**
	 * Option key for the pending URL queue. Scoped per blog on multisite
	 * via get_queue_key().
	 */
	const QUEUE_KEY = 'breeze_preload_queue';

	/**
	 * Option key that stores the Unix timestamp of the most recently
	 * warmed URL. The heartbeat uses this to detect a stalled chain without
	 * having to inspect Action Scheduler's internal action status (which can
	 * be unreliable when the AS async loopback fails).
	 */
	const LAST_WARM_KEY = 'breeze_preload_last_warm';

	/**
	 * Maximum number of URLs to schedule. Filterable via breeze_preload_max_urls.
	 */
	const MAX_URLS = 30;

	/**
	 * Seconds of delay added between each scheduled URL action.
	 * Staggers HTTP requests to avoid hammering the server.
	 */
	const DELAY_SECONDS = 3;

	/**
	 * How many URLs to warm per AS job / heartbeat tick.
	 * All URLs in a batch are dispatched as non-blocking requests, so they
	 * generate cache in parallel on the server with no extra wait per URL.
	 * Filterable via breeze_preload_urls_per_batch.
	 */
	const URLS_PER_BATCH = 3;

	/**
	 * Maximum number of sub-blogs that may warm a URL synchronously in the
	 * foreground during a network-wide fan-out. Remaining blogs queue all of
	 * their URLs and rely on the async chain / heartbeat instead, so a large
	 * network (hundreds of sites) does not block the admin save request with
	 * hundreds of sequential HTTP dispatches.
	 *
	 * Filterable via breeze_preload_network_immediate_warm_limit.
	 */
	const NETWORK_IMMEDIATE_WARM_LIMIT = 5;

	/**
	 * Cache group used for short-lived preload queue mutex keys.
	 */
	const QUEUE_LOCK_GROUP = 'breeze-preload-locks';

	/**
	 * Queue mutex lifetime in seconds.
	 */
	const QUEUE_LOCK_TTL = 30;

	/**
	 * Cache group used for single-runner worker locks.
	 */
	const WORKER_LOCK_GROUP = 'breeze-preload-workers';

	/**
	 * Worker lock lifetime in seconds.
	 */
	const WORKER_LOCK_TTL = 30;

	/**
	 * Prevents scheduling more than once per PHP request per blog. Keyed by
	 * blog ID (0 on non-multisite) because a single PHP request may legitimately
	 * trigger breeze_clear_all_cache for multiple sub-blogs via switch_to_blog()
	 * (notably during the network-wide fan-out in schedule_preload_for_network).
	 *
	 * @var array<int, bool>
	 */
	private static array $scheduled_this_request = array();

	/**
	 * Cache whether Action Scheduler tables are available for the current blog.
	 * Keyed by blog ID (0 on non-multisite) to avoid repeated schema checks.
	 *
	 * @var array<int, bool>
	 */
	private static array $as_tables_ready_by_blog = array();

	/**
	 * Track whether automatic Action Scheduler schema repair was attempted for
	 * the active blog during this request.
	 *
	 * @var array<int, bool>
	 */
	private static array $as_schema_repair_attempted_by_blog = array();

	/**
	 * Register all hooks. Called once from breeze.php.
	 */
	public static function init(): void {
		// Trigger scheduling when the full cache is cleared.
		// Priority 999 keeps this late in the clear-all sequence so queue writes
		// happen after other cache-clear side effects in the same request.
		add_action( 'breeze_clear_all_cache', array( __CLASS__, 'schedule_preload' ), 999 );

		// Action Scheduler job handlers.
		add_action( self::AS_HOOK,   array( __CLASS__, 'preload_url'  ) ); // legacy individual jobs
		add_action( self::CHAIN_HOOK, array( __CLASS__, 'preload_next' ) ); // self-scheduling chain

		// Heartbeat: kick a stalled chain when an admin is in the dashboard.
		// heartbeat_send fires unconditionally on every tick (unlike
		// heartbeat_received which only fires when the client sends data).
		add_filter( 'heartbeat_send', array( __CLASS__, 'heartbeat_kick' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Scheduling
	// -------------------------------------------------------------------------

	/**
	 * Save the URL queue and dispatch the first chain job via AS async runner.
	 *
	 * Hooked to breeze_clear_all_cache. The static guard ensures this runs only
	 * once per request even when the action fires multiple times (e.g. saving
	 * settings triggers it 6–8 times in breeze-configuration.php).
	 *
	 * Using as_enqueue_async_action (not as_schedule_single_action) means AS
	 * dispatches the job via its own loopback HTTP request, completely bypassing
	 * WP-Cron. The DELAY_SECONDS pause happens inside preload_next() via sleep(),
	 * so the gap between warmings is always accurate regardless of WP-Cron state.
	 */
	public static function schedule_preload(): void {
		// Network-wide purge → fan out and schedule a preload for every blog.
		// Breeze's `breeze_clear_all_cache` action only fires ONCE per purge,
		// in the main-site context, even when a network-admin purge has wiped
		// the cache for every sub-site (see Breeze_Admin::breeze_clear_all_cache
		// which calls set_as_network_screen() before doing the actual work).
		// Without this fan-out, only the main site (blog 1) ever gets warmed.
		if ( self::is_network_wide_purge() ) {
			self::schedule_preload_for_network();
			return;
		}

		self::schedule_preload_for_current_blog();
	}

	/**
	 * Detect whether the in-flight purge spans the whole network.
	 *
	 * Trigger conditions (any one is sufficient):
	 *  - We're in network admin (Network Admin "Purge All Cache" button or a
	 *    settings save at the network scope).
	 *  - WP_NETWORK_ADMIN was defined earlier in the request (e.g. by Breeze's
	 *    set_as_network_screen() helper) even though we're now executing
	 *    further along the hook chain.
	 *  - The request explicitly opted into network scope via the same strict
	 *    boolean parser + capability gate used by set_as_network_screen().
	 *  - The HTTP referer is a /wp-admin/network/ URL (covers admin-ajax.php
	 *    requests originating from the Network Admin UI, where
	 *    is_network_admin() returns false but the purge logically spans the
	 *    network).
	 *
	 * Single-site installs and per-sub-blog purges return false, so the
	 * single-blog scheduling path remains unchanged for those cases.
	 *
	 * @return bool
	 */
	private static function is_network_wide_purge(): bool {
		if ( ! is_multisite() ) {
			return false;
		}

		if ( function_exists( 'is_network_admin' ) && is_network_admin() ) {
			return true;
		}

		if ( defined( 'WP_NETWORK_ADMIN' ) && WP_NETWORK_ADMIN ) {
			return true;
		}

		// Mirror set_as_network_screen(): only treat this as network-scoped when
		// the request flag is explicitly true and the current user can operate
		// at network scope. Presence of is-network=false must remain site-local.
		if ( function_exists( 'breeze_request_wants_network_scope' ) && function_exists( 'breeze_user_can_manage_network' ) ) {
			if ( breeze_request_wants_network_scope() && breeze_user_can_manage_network() ) {
				return true;
			}
		}

		// HTTP referer fallback: the AJAX "Purge All Cache" button on the
		// Network Admin UI POSTs to /wp-admin/admin-ajax.php (not the network
		// equivalent), so is_network_admin() returns false inside the handler.
		// Keep the capability gate and validate host/path explicitly.
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) && function_exists( 'breeze_user_can_manage_network' ) && breeze_user_can_manage_network() ) {
			$referer = esc_url_raw( wp_unslash( (string) $_SERVER['HTTP_REFERER'] ) );
			if ( ! empty( $referer ) && self::is_network_admin_referer( $referer ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate whether a referer is truly a Network Admin URL.
	 *
	 * @param string $referer Referer URL.
	 * @return bool
	 */
	private static function is_network_admin_referer( string $referer ): bool {
		$parts           = wp_parse_url( $referer );
		$network_admin   = network_admin_url( '/' );
		$network_parts   = wp_parse_url( $network_admin );

		if ( ! is_array( $parts ) || ! is_array( $network_parts ) ) {
			return false;
		}

		$referer_scheme   = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$network_scheme   = isset( $network_parts['scheme'] ) ? strtolower( (string) $network_parts['scheme'] ) : '';
		$referer_host     = isset( $parts['host'] ) ? (string) $parts['host'] : '';
		$network_host     = isset( $network_parts['host'] ) ? (string) $network_parts['host'] : '';
		$referer_path     = self::normalize_url_path( isset( $parts['path'] ) ? (string) $parts['path'] : '' );
		$network_adm_path = self::normalize_url_path( isset( $network_parts['path'] ) ? (string) $network_parts['path'] : '' );

		if ( empty( $referer_scheme ) || empty( $network_scheme ) || $referer_scheme !== $network_scheme ) {
			return false;
		}

		if ( empty( $referer_host ) || empty( $network_host ) || 0 !== strcasecmp( $referer_host, $network_host ) ) {
			return false;
		}

		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return false;
		}

		$referer_port = isset( $parts['port'] ) ? (int) $parts['port'] : self::get_default_port_for_scheme( $referer_scheme );
		$network_port = isset( $network_parts['port'] ) ? (int) $network_parts['port'] : self::get_default_port_for_scheme( $network_scheme );

		if ( $referer_port <= 0 || $network_port <= 0 || $referer_port !== $network_port ) {
			return false;
		}

		if ( empty( $network_adm_path ) || '/' === $network_adm_path ) {
			return false;
		}

		return 0 === strpos( $referer_path, $network_adm_path );
	}

	/**
	 * Iterate over every sub-blog and schedule a preload for each one.
	 *
	 * Each blog gets its own queue, its own last-warm marker, and its own
	 * Action Scheduler group (see get_blog_group()), so the chains run
	 * independently in parallel without colliding on uniqueness checks or
	 * cancelling each other via as_unschedule_all_actions.
	 *
	 * Archived, spam and deleted blogs are skipped.
	 */
	private static function schedule_preload_for_network(): void {
		if ( ! function_exists( 'get_sites' ) ) {
			// Should never happen on multisite, but guard anyway.
			self::schedule_preload_for_current_blog( true );
			return;
		}

		$site_ids = get_sites(
			array(
				'number'   => 0,
				'fields'   => 'ids',
				'archived' => 0,
				'spam'     => 0,
				'deleted'  => 0,
			)
		);

		if ( empty( $site_ids ) ) {
			return;
		}

		self::log( '[Breeze Preload] Network-wide purge detected — fanning out to ' . count( $site_ids ) . ' sub-blog(s).' );

		// Cap how many sub-blogs warm a URL synchronously in this request. Once
		// the budget is spent, remaining blogs queue everything and let the
		// async chain / heartbeat warm them, so a large network does not block
		// the admin save with hundreds of foreground HTTP dispatches.
		$immediate_warm_budget = (int) apply_filters(
			'breeze_preload_network_immediate_warm_limit',
			self::NETWORK_IMMEDIATE_WARM_LIMIT
		);
		$immediate_warm_budget = max( 0, $immediate_warm_budget );
		$immediate_warms_done  = 0;

		foreach ( $site_ids as $site_id ) {
			$site_id = (int) $site_id;
			switch_to_blog( $site_id );
			try {
				$allow_immediate_warm = ( $immediate_warms_done < $immediate_warm_budget );
				$performed_warm       = self::schedule_preload_for_current_blog( true, $allow_immediate_warm );
				if ( $performed_warm ) {
					++$immediate_warms_done;
				}
			} finally {
				restore_current_blog();
			}
		}
	}

	/**
	 * Schedule the preload chain for whichever blog is currently active.
	 *
	 * This is the original per-blog scheduling logic, now extracted so
	 * schedule_preload_for_network() can call it once per sub-blog under
	 * switch_to_blog(). It always operates on get_current_blog_id().
	 *
	 * @param bool $is_network_fanout    Whether this runs inside a network-wide
	 *                                   fan-out (affects URL collection scope).
	 * @param bool $allow_immediate_warm Whether one URL may be warmed
	 *                                   synchronously in the foreground. During
	 *                                   network fan-out this is capped to a small
	 *                                   number of blogs so the admin save request
	 *                                   does not block on hundreds of dispatches.
	 * @return bool True when a URL was warmed synchronously in this call.
	 */
	private static function schedule_preload_for_current_blog( bool $is_network_fanout = false, bool $allow_immediate_warm = true ): bool {
		$blog_id = is_multisite() ? get_current_blog_id() : 0;

		if ( ! empty( self::$scheduled_this_request[ $blog_id ] ) ) {
			return false;
		}
		self::$scheduled_this_request[ $blog_id ] = true;

		if ( ! self::is_action_scheduler_available() ) {
			self::log( '[Breeze Preload] Action Scheduler not available — skipping preload scheduling.' );
			return false;
		}

		if ( ! self::has_action_scheduler_tables() ) {
			self::log( '[Breeze Preload] Action Scheduler tables missing for blog ' . $blog_id . ' — skipping preload scheduling.' );
			return false;
		}

		if ( ! self::is_cache_warmup_enabled() ) {
			delete_option( self::get_queue_key() );
			delete_option( self::get_last_warm_key() );
			self::log( '[Breeze Preload] Cache warmup is disabled for blog ' . $blog_id . ' — skipping preload scheduling.' );
			return false;
		}

		$urls = self::collect_urls( $is_network_fanout );

		if ( empty( $urls ) ) {
			self::log( '[Breeze Preload] No URLs to preload for blog ' . $blog_id . '.' );
			return false;
		}

		$group = self::get_blog_group();

		// Drain legacy v1 single-URL jobs. They were scheduled before the
		// per-blog group existed, so use the original base group string.
		as_unschedule_all_actions( self::AS_HOOK, array(), self::AS_GROUP );

		// Cancel pending chain jobs for THIS blog only — using the per-blog
		// group ensures we don't kill a chain belonging to another sub-blog.
		as_unschedule_all_actions( self::CHAIN_HOOK, array(), $group );

		// Reset the last-warm timestamp so the heartbeat doesn't think a
		// previous run already happened.
		delete_option( self::get_last_warm_key() );

		$total_urls    = count( $urls );
		$performed_warm = false;

		// Warm exactly one URL immediately so users get instant progress, but
		// only when the immediate-warm budget allows it. When it does not (later
		// blogs in a large network fan-out), every URL is queued instead and the
		// async chain / heartbeat warms them with no foreground blocking.
		if ( $allow_immediate_warm ) {
			$first_url = array_shift( $urls );
			if ( ! empty( $first_url ) ) {
				self::preload_url( $first_url );
				update_option( self::get_last_warm_key(), time(), false );
				$performed_warm = true;
			}
		}

		if ( ! empty( $urls ) ) {
			// Persist whatever is left to warm. When an immediate warm happened
			// the first URL was already shifted off; otherwise the full list is
			// queued.
			update_option( self::get_queue_key(), $urls, false );

			// Continue the rest via Action Scheduler chain.
			$action_id = as_enqueue_async_action(
				self::CHAIN_HOOK,
				array( 'blog_id' => $blog_id ),
				$group,
				true
			);
			if ( $action_id <= 0 ) {
				// Inline continuation would re-introduce synchronous warming. We
				// only allow it outside network fan-out; within a fan-out the
				// heartbeat rescues the queue without blocking the save request.
				if ( ! $is_network_fanout ) {
					self::log( '[Breeze Preload] Failed to dispatch async chain for blog ' . $blog_id . ' — continuing inline.' );
					self::preload_next( $blog_id );
				} else {
					self::log( '[Breeze Preload] Failed to dispatch async chain for blog ' . $blog_id . ' — queued for heartbeat recovery.' );
				}
				return $performed_warm;
			}
		} else {
			delete_option( self::get_queue_key() );
		}

		if ( $performed_warm ) {
			self::log( '[Breeze Preload] Queued ' . $total_urls . ' URL(s) for blog ' . $blog_id . ' — first URL started immediately.' );
		} else {
			self::log( '[Breeze Preload] Queued ' . $total_urls . ' URL(s) for blog ' . $blog_id . ' — all URLs deferred to async/heartbeat.' );
		}

		return $performed_warm;
	}

	// -------------------------------------------------------------------------
	// Chain worker
	// -------------------------------------------------------------------------

	/**
	 * Pop the next batch of URLs from the option-backed queue, warm them, then
	 * dispatch the next chain job immediately via AS async runner.
	 *
	 * The DELAY_SECONDS pause is enforced here with sleep() rather than by
	 * scheduling a future-dated AS action. This keeps the timing accurate:
	 * sleep() runs in the current background process and is not subject to
	 * WP-Cron lock delays or queue runner scheduling windows.
	 *
	 * Flow per call:
	 *   sleep(DELAY_SECONDS) → pop batch → warm all (non-blocking) → dispatch next (if any)
	 *
	 * @param int $blog_id Blog context for this chain worker.
	 */
	public static function preload_next( $blog_id = 0 ): void {
		$blog_id  = (int) $blog_id;
		$switched = false;

		// The AS runner fires this in an undefined blog context (usually the
		// main site). Switch into the originating blog so option lookups,
		// home_url() checks, and option reads all target the right site.
		if ( is_multisite() && $blog_id > 0 && $blog_id !== get_current_blog_id() ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		try {
			if ( ! self::acquire_worker_lock() ) {
				self::log( '[Breeze Preload] Worker lock busy — skipping overlapping worker for blog ' . $blog_id . '.' );
				return;
			}

			// Enforce the inter-batch delay at the START of each async job so
			// spacing remains predictable.
			sleep( self::DELAY_SECONDS );

			if ( ! self::acquire_queue_lock() ) {
				self::log( '[Breeze Preload] Queue lock busy — skipping overlapping worker for blog ' . $blog_id . '.' );
				return;
			}

			$batch     = array();
			$remaining = 0;

			try {
				$queue = get_option( self::get_queue_key(), array() );

				if ( empty( $queue ) || ! is_array( $queue ) ) {
					delete_option( self::get_queue_key() );
					self::log( '[Breeze Preload] Queue empty — chain complete for blog ' . $blog_id . '.' );
					return;
				}

				$batch_size = (int) apply_filters( 'breeze_preload_urls_per_batch', self::URLS_PER_BATCH );
				$batch_size = max( 1, $batch_size );

				// Splice the next batch off the front of the queue while holding
				// a short mutex so overlapping workers cannot pop/write the same
				// queue state concurrently.
				$batch     = array_splice( $queue, 0, $batch_size );
				$remaining = count( $queue );

				if ( $remaining > 0 ) {
					update_option( self::get_queue_key(), $queue, false );
				} else {
					delete_option( self::get_queue_key() );
				}
			} finally {
				self::release_queue_lock();
			}

			// Warm all URLs in the batch. With blocking=false each request is
			// dispatched instantly — they run in parallel on the server.
			foreach ( $batch as $url ) {
				self::preload_url( $url );
			}

			// Record when this batch was dispatched. The heartbeat uses this
			// timestamp to detect a stalled chain without relying on AS state.
			update_option( self::get_last_warm_key(), time(), false );

			// Dispatch the next job immediately via AS async runner (loopback).
			// Pass blog_id and use the per-blog group so the next job runs in
			// the same blog context and doesn't collide with other sub-blogs.
			if ( $remaining > 0 && function_exists( 'as_enqueue_async_action' ) && self::has_action_scheduler_tables() ) {
				$action_id = as_enqueue_async_action(
					self::CHAIN_HOOK,
					array( 'blog_id' => $blog_id ),
					self::get_blog_group(),
					true
				);
				if ( $action_id > 0 ) {
					self::log( '[Breeze Preload] ' . $remaining . ' URL(s) remaining for blog ' . $blog_id . ' — next batch dispatched via async runner.' );
				} else {
					self::log( '[Breeze Preload] Async dispatch failed for blog ' . $blog_id . ' with ' . $remaining . ' URL(s) remaining — waiting for next runner tick.' );
				}
			}
		} finally {
			self::release_worker_lock();
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	// -------------------------------------------------------------------------
	// Heartbeat kick
	// -------------------------------------------------------------------------

	/**
	 * During every Heartbeat tick, inspect the preload chain and act if needed.
	 *
	 * Hooked to heartbeat_send (not heartbeat_received). heartbeat_send fires
	 * unconditionally on every Heartbeat tick. heartbeat_received only fires
	 * when the JS client includes application data in the POST, which does not
	 * happen by default (only when something calls wp.heartbeat.isQueued()).
	 *
	 * Detection strategy: instead of inspecting Action Scheduler's internal
	 * action state (which is unreliable when the AS async loopback fails — e.g.
	 * self-signed SSL or custom TLDs on local dev environments), we track *when
	 * the last URL was actually warmed* via a dedicated option (LAST_WARM_KEY).
	 *
	 * Decision logic:
	 *  1. Queue empty → nothing to do.
	 *  2. Time since last warm < DELAY_SECONDS + 10s → chain is alive, leave it.
	 *  3. Otherwise (chain stalled or never started) → cancel any ghost AS jobs,
	 *     warm the next URL synchronously here, record the timestamp, then
	 *     dispatch a fresh async job for the remainder. If that async job also
	 *     stalls, the next heartbeat tick handles the following URL the same way.
	 *
	 * On production servers where the AS loopback works normally, jobs process
	 * every ~DELAY_SECONDS seconds and the last-warm timestamp stays fresh, so
	 * the heartbeat never reaches step 3. On local dev it activates after
	 * DELAY_SECONDS + 10 seconds of inactivity — roughly one heartbeat cycle.
	 *
	 * @param array  $response Data returned to the Heartbeat client.
	 * @param string $screen_id Admin screen ID (unused).
	 * @return array
	 */
	public static function heartbeat_kick( array $response, string $screen_id = '' ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $response;
		}

		// On multisite, iterate over every active sub-blog and rescue the first
		// one whose chain is stalled. Processing only one blog per tick keeps
		// each heartbeat fast; subsequent ticks pick up the remaining blogs.
		// Without this loop, the heartbeat only rescues whichever blog the
		// admin happens to be viewing — so on installs where the AS loopback
		// fails (e.g. Cloudways), every other sub-blog's queue sits orphaned.
		if ( is_multisite() && function_exists( 'get_sites' ) ) {
			$site_ids = get_sites(
				array(
					'number'   => 0,
					'fields'   => 'ids',
					'archived' => 0,
					'spam'     => 0,
					'deleted'  => 0,
				)
			);
			$is_super = function_exists( 'is_super_admin' ) && is_super_admin();

			foreach ( $site_ids as $site_id ) {
				$site_id  = (int) $site_id;
				// Least privilege: non-super-admins only kick blogs where they
				// can manage options.
				if ( ! $is_super ) {
					if ( function_exists( 'current_user_can_for_site' ) ) {
						if ( ! current_user_can_for_site( $site_id, 'manage_options' ) ) {
							continue;
						}
					} else {
						$can_manage_site = false;
						$switched_cap    = false;
						if ( $site_id !== get_current_blog_id() ) {
							switch_to_blog( $site_id );
							$switched_cap = true;
						}
						try {
							$can_manage_site = current_user_can( 'manage_options' );
						} finally {
							if ( $switched_cap ) {
								restore_current_blog();
							}
						}

						if ( ! $can_manage_site ) {
							continue;
						}
					}
				}

				$switched = false;
				if ( $site_id !== get_current_blog_id() ) {
					switch_to_blog( $site_id );
					$switched = true;
				}

				try {
					if ( self::process_stalled_chain_for_current_blog() ) {
						$response['breeze_preload_kicked'] = true;
						return $response;
					}
				} finally {
					if ( $switched ) {
						restore_current_blog();
					}
				}
			}

			return $response;
		}

		if ( self::process_stalled_chain_for_current_blog() ) {
			$response['breeze_preload_kicked'] = true;
		}

		return $response;
	}

	/**
	 * Inspect and (if stalled) advance the preload chain for the active blog.
	 *
	 * Returns true when this tick actually warmed something, false when the
	 * chain is healthy or there's nothing to do. Used by heartbeat_kick() to
	 * limit work to one blog per tick on multisite.
	 *
	 * @return bool True if a batch was warmed in this call.
	 */
	private static function process_stalled_chain_for_current_blog(): bool {
		// How long ago was the last URL warmed? A missing option means the
		// chain never ran (or was just scheduled); treat as "very long ago" by
		// using PHP_INT_MAX rather than `time() - 0` which produced the
		// nonsensical "idle for 1781529173s" log lines.
		$last_warm      = (int) get_option( self::get_last_warm_key(), 0 );
		$seconds_idle   = ( $last_warm > 0 ) ? ( time() - $last_warm ) : PHP_INT_MAX;
		$idle_threshold = self::DELAY_SECONDS + 10; // 13 s by default

		if ( $seconds_idle < $idle_threshold ) {
			// Chain is alive and on schedule — let it run.
			return false;
		}

		if ( ! self::acquire_worker_lock() ) {
			return false;
		}

		try {
			// Another worker may have advanced the queue while we were waiting
			// to acquire the lock; re-check idle state in the serialized section.
			$last_warm    = (int) get_option( self::get_last_warm_key(), 0 );
			$seconds_idle = ( $last_warm > 0 ) ? ( time() - $last_warm ) : PHP_INT_MAX;
			if ( $seconds_idle < $idle_threshold ) {
				return false;
			}

			// Chain is stalled (or has not started). Cancel any ghost AS jobs for
			// THIS blog only (per-blog group) so we don't end up with a duplicate
			// warm if the loopback recovers later, and so we don't kill another
			// sub-blog's healthy chain.
			if ( self::is_action_scheduler_available() && self::has_action_scheduler_tables() ) {
				as_unschedule_all_actions( self::CHAIN_HOOK, array(), self::get_blog_group() );
			}

			$blog_id  = is_multisite() ? get_current_blog_id() : 0;
			$idle_log = ( PHP_INT_MAX === $seconds_idle ) ? '∞' : (string) $seconds_idle;

		self::log(
			sprintf(
				'[Breeze Preload] Heartbeat: chain idle for %ss (threshold %ds) for blog %d — processing next URL directly.',
				$idle_log,
				$idle_threshold,
				$blog_id
			)
		);

			if ( ! self::acquire_queue_lock() ) {
				return false;
			}

			$batch     = array();
			$remaining = 0;

			try {
				$queue = get_option( self::get_queue_key(), array() );

				if ( empty( $queue ) || ! is_array( $queue ) ) {
					return false;
				}

				// Re-check idle state while holding the queue lock. If another
				// worker already advanced the chain, skip this heartbeat run.
				$last_warm    = (int) get_option( self::get_last_warm_key(), 0 );
				$seconds_idle = ( $last_warm > 0 ) ? ( time() - $last_warm ) : PHP_INT_MAX;
				if ( $seconds_idle < $idle_threshold ) {
					return false;
				}

				$batch_size = (int) apply_filters( 'breeze_preload_urls_per_batch', self::URLS_PER_BATCH );
				$batch_size = max( 1, $batch_size );

				// Pop/write while locked so AS and heartbeat cannot race each other
				// on queue mutations.
				$batch     = array_splice( $queue, 0, $batch_size );
				$remaining = count( $queue );

				if ( $remaining > 0 ) {
					update_option( self::get_queue_key(), $queue, false );
				} else {
					delete_option( self::get_queue_key() );
				}
			} finally {
				self::release_queue_lock();
			}

			foreach ( $batch as $url ) {
				self::preload_url( $url );
			}

			// Mark warm time so the next heartbeat tick evaluates correctly.
			update_option( self::get_last_warm_key(), time(), false );

			if ( $remaining > 0 && self::is_action_scheduler_available() && self::has_action_scheduler_tables() ) {
				// Attempt async dispatch for the remainder. If the loopback works
				// (production) this is faster. If it fails again, the next heartbeat
				// tick will catch this blog again and process directly as above.
				$action_id = as_enqueue_async_action(
					self::CHAIN_HOOK,
					array( 'blog_id' => $blog_id ),
					self::get_blog_group(),
					true
				);
				if ( $action_id <= 0 ) {
					self::log( '[Breeze Preload] Heartbeat: async re-dispatch failed for blog ' . $blog_id . ' with ' . $remaining . ' URL(s) remaining.' );
				}
			}

			return true;
		} finally {
			self::release_worker_lock();
		}
	}

	/**
	 * Build queue mutex key for active blog context.
	 *
	 * @return string
	 */
	private static function get_queue_lock_key(): string {
		if ( is_multisite() ) {
			return 'breeze_preload_queue_lock_' . get_current_blog_id();
		}

		return 'breeze_preload_queue_lock';
	}

	/**
	 * Acquire a short-lived queue mutex for the active blog context.
	 *
	 * @return bool
	 */
	private static function acquire_queue_lock(): bool {
		if ( ! function_exists( 'wp_cache_add' ) ) {
			return true;
		}

		return wp_cache_add(
			self::get_queue_lock_key(),
			1,
			self::QUEUE_LOCK_GROUP,
			self::QUEUE_LOCK_TTL
		);
	}

	/**
	 * Release queue mutex for active blog context.
	 *
	 * @return void
	 */
	private static function release_queue_lock(): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		wp_cache_delete( self::get_queue_lock_key(), self::QUEUE_LOCK_GROUP );
	}

	/**
	 * Build worker mutex key for active blog context.
	 *
	 * @return string
	 */
	private static function get_worker_lock_key(): string {
		if ( is_multisite() ) {
			return 'breeze_preload_worker_lock_' . get_current_blog_id();
		}

		return 'breeze_preload_worker_lock';
	}

	/**
	 * Acquire a short-lived worker mutex for the active blog context.
	 *
	 * @return bool
	 */
	private static function acquire_worker_lock(): bool {
		if ( ! function_exists( 'wp_cache_add' ) ) {
			return true;
		}

		return wp_cache_add(
			self::get_worker_lock_key(),
			1,
			self::WORKER_LOCK_GROUP,
			self::WORKER_LOCK_TTL
		);
	}

	/**
	 * Release worker mutex for active blog context.
	 *
	 * @return void
	 */
	private static function release_worker_lock(): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		wp_cache_delete( self::get_worker_lock_key(), self::WORKER_LOCK_GROUP );
	}

	// -------------------------------------------------------------------------
	// URL collection
	// -------------------------------------------------------------------------

	/**
	 * Build the final list of URLs to warm, merging all sources.
	 *
	 * @return string[]
	 */
	public static function collect_urls( bool $is_network_fanout = false ): array {
		// Get the home URL.
		$home_url  = trailingslashit( home_url() );
		// Get the auto-detected URLs.
		$auto_urls = self::get_auto_detected_urls();
		// Get the user-defined URLs.
		$user_urls = self::get_user_defined_urls( $is_network_fanout );

		// The MAX_URLS cap applies ONLY to user-defined warmup URLs. The
		// homepage and auto-detected pages (shop, blog, etc.) are always
		// warmed in addition and never count against the user limit.
		$max = self::get_max_urls();
		if ( count( $user_urls ) > $max ) {
			$user_urls = array_slice( $user_urls, 0, $max );
		}

		// Merge the URLs and remove duplicates.
		$all_urls = array_merge( array( $home_url ), $auto_urls, $user_urls );
		// Remove duplicates and empty values.
		$all_urls = array_values( array_unique( array_filter( $all_urls ) ) );

		// Remove URLs that match the "Never Cache" list.
		$all_urls = self::filter_never_cached( $all_urls );

		/**
		 * Allows developers to add, remove, or reorder URLs before scheduling.
		 *
		 * @param string[] $all_urls Collected URLs.
		 */
		$all_urls = (array) apply_filters( 'breeze_preload_urls', $all_urls );

		return array_values( $all_urls );
	}

	/**
	 * Maximum number of URLs that may be scheduled for warmup.
	 *
	 * Single source of truth for the cap, so the admin UI and the save handler
	 * enforce exactly the same limit that scheduling applies. Always at least 1.
	 *
	 * @return int
	 */
	public static function get_max_urls(): int {
		/**
		 * Maximum number of URLs to schedule.
		 *
		 * @param int $max Default 30.
		 */
		$max = (int) apply_filters( 'breeze_preload_max_urls', self::MAX_URLS );

		return max( 1, $max );
	}

	/**
	 * Return URLs entered by the admin in the Preload settings tab.
	 *
	 * Multisite rules:
	 * - Network-wide fan-out: use only network-level custom warmup URLs.
	 * - Regular subsite purge: use effective subsite settings (local or inherited).
	 * - Sub-blog with custom settings → use that sub-blog's own list.
	 * - Network admin / single site → use the saved list normally.
	 *
	 * @param bool $is_network_fanout Whether this collection runs during
	 *                                network-wide fan-out scheduling.
	 * @return string[]
	 */
	private static function get_user_defined_urls( bool $is_network_fanout = false ): array {
		if ( $is_network_fanout && is_multisite() ) {
			$options = get_site_option( 'breeze_preload_settings' );
		} else {
			$options = breeze_get_option( 'preload_settings', false );
		}

		$raw     = isset( $options['breeze-preload-cache-urls'] ) ? $options['breeze-preload-cache-urls'] : array();

		if ( empty( $raw ) || ! is_array( $raw ) ) {
			return array();
		}

		return array_values( array_filter( $raw, array( __CLASS__, 'is_local_url' ) ) );
	}

	/**
	 * Check whether cache warmup URLs feature is enabled for the active blog.
	 *
	 * @return bool
	 */
	private static function is_cache_warmup_enabled(): bool {
		$options = breeze_get_option( 'preload_settings', false );
		$enabled = isset( $options['breeze-cache-warmup-enabled'] ) ? $options['breeze-cache-warmup-enabled'] : '0';

		return filter_var( $enabled, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Detect important URLs based on active plugins.
	 *
	 * Only includes pages that benefit from caching (archive/listing pages).
	 * Excludes per-user pages like cart, checkout, account, success.
	 *
	 * @return string[]
	 */
	private static function get_auto_detected_urls(): array {
		$urls = array();

		// WordPress: static blog index page.
		$blog_page_id = (int) get_option( 'page_for_posts', 0 );
		if ( $blog_page_id > 0 ) {
			$blog_url = get_permalink( $blog_page_id );
			if ( ! empty( $blog_url ) ) {
				$urls[] = trailingslashit( $blog_url );
			}
		}

		// WooCommerce: shop/products archive (not cart, checkout, or account).
		if ( function_exists( 'wc_get_page_id' ) ) {
			$shop_id = \wc_get_page_id( 'shop' );
			if ( $shop_id > 0 ) {
				$shop_url = get_permalink( $shop_id );
				if ( ! empty( $shop_url ) ) {
					$urls[] = trailingslashit( $shop_url );
				}
			}
		}

		// WP Job Manager: jobs listing page.
		if ( class_exists( 'WP_Job_Manager' ) ) {
			$jobs_page_id = (int) get_option( 'job_manager_jobs_page_id', 0 );
			if ( $jobs_page_id > 0 ) {
				$jobs_url = get_permalink( $jobs_page_id );
				if ( ! empty( $jobs_url ) ) {
					$urls[] = trailingslashit( $jobs_url );
				}
			}
		}

		// The Events Calendar: events archive.
		if ( function_exists( 'tribe_get_events_link' ) ) {
			$events_url = \tribe_get_events_link();
			if ( ! empty( $events_url ) ) {
				$urls[] = trailingslashit( $events_url );
			}
		}

		// bbPress: forum index.
		if ( function_exists( 'bbp_get_forums_url' ) ) {
			$forum_url = \bbp_get_forums_url();
			if ( ! empty( $forum_url ) ) {
				$urls[] = trailingslashit( $forum_url );
			}
		}

		// BuddyPress / BuddyBoss: activity feed.
		if ( function_exists( 'bp_get_activity_directory_permalink' ) ) {
			$activity_url = \bp_get_activity_directory_permalink();
			if ( ! empty( $activity_url ) ) {
				$urls[] = trailingslashit( $activity_url );
			}
		}

		// Easy Digital Downloads: store/downloads page (not checkout or success).
		if ( function_exists( 'EDD' ) ) {
			$edd_settings  = get_option( 'edd_settings', array() );
			$edd_store_id  = isset( $edd_settings['purchase_page'] ) ? (int) $edd_settings['purchase_page'] : 0;
			if ( $edd_store_id > 0 ) {
				$edd_url = get_permalink( $edd_store_id );
				if ( ! empty( $edd_url ) ) {
					$urls[] = trailingslashit( $edd_url );
				}
			}
		}

		// LearnDash: courses archive.
		if ( class_exists( 'SFWD_LMS' ) ) {
			$courses_url = get_post_type_archive_link( 'sfwd-courses' );
			if ( ! empty( $courses_url ) ) {
				$urls[] = trailingslashit( $courses_url );
			}
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	/**
	 * Remove any URLs that match Breeze's "Never Cache" list.
	 *
	 * Uses a simple substring check consistent with how execute-cache.php
	 * evaluates the exclude list.
	 *
	 * @param  string[] $urls
	 * @return string[]
	 */
	private static function filter_never_cached( array $urls ): array {
		$advanced     = breeze_get_option( 'advanced_settings', false );
		$never_cache  = isset( $advanced['breeze-exclude-urls'] ) ? (array) $advanced['breeze-exclude-urls'] : array();

		$never_cache = array_filter( array_map( 'trim', $never_cache ) );

		if ( empty( $never_cache ) ) {
			return $urls;
		}

		return array_values(
			array_filter(
				$urls,
				static function ( string $url ) use ( $never_cache ): bool {
					foreach ( $never_cache as $pattern ) {
						if ( false !== strpos( $url, $pattern ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}

	// -------------------------------------------------------------------------
	// Worker
	// -------------------------------------------------------------------------

	/**
	 * Action Scheduler job: warm the cache for a single URL.
	 *
	 * @param string $url Absolute local URL to warm.
	 */
	public static function preload_url( string $url ): void {
		if ( empty( $url ) ) {
			self::log( '[Breeze Preload] Received empty URL — skipping.' );
			return;
		}

		if ( ! self::is_local_url( $url ) ) {
			self::log( '[Breeze Preload] Security: rejected non-local URL: ' . self::safe_url_for_log( $url ) );
			return;
		}

		// Respect circuit breaker when available.
		if ( class_exists( 'Breeze\\Cache\\Cache_Circuit_Breaker' ) && ! Cache_Circuit_Breaker::is_cache_allowed() ) {
			self::log( '[Breeze Preload] Skipped — circuit breaker is OPEN: ' . self::safe_url_for_log( $url ) );
			return;
		}

		self::log( '[Breeze Preload] Warming: ' . self::safe_url_for_log( $url ) );

		// For fire-and-forget warmup requests, keep timeout extremely low so
		// dispatch returns immediately even if loopback/connectivity is slow.
		$non_blocking_timeout = (float) apply_filters( 'breeze_preload_non_blocking_timeout', 0.01 );
		if ( $non_blocking_timeout <= 0 ) {
			$non_blocking_timeout = 0.01;
		}

		wp_remote_get(
			$url,
			array(
				'timeout'     => $non_blocking_timeout,
				'blocking'    => false,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'breeze_ssl_check_certificate', false ),
				'headers'     => array(
					'X-Breeze-Preload' => '1',
				),
			)
		);

		// Non-blocking: the request is fired and forgotten. WordPress will
		// process it server-side and write the cache independently. There is
		// no response to inspect, so we simply log the dispatch.
		self::log( '[Breeze Preload] Dispatched (non-blocking): ' . self::safe_url_for_log( $url ) );
	}

	/**
	 * Sanitize a URL (or any string) before writing it to the error log.
	 *
	 * Admin-supplied URLs must never reach error_log() unfiltered: embedded
	 * CR/LF or control bytes can forge fake log lines or corrupt log readers
	 * (log injection / log forging). This strips all control characters,
	 * including \r and \n, and caps the length so a hostile value cannot bloat
	 * the log file.
	 *
	 * @param string $url   Raw URL or arbitrary string destined for the log.
	 * @param int    $limit Maximum number of bytes to keep. Default 2048.
	 * @return string Sanitized single-line value safe for error_log().
	 */
	private static function safe_url_for_log( string $url, int $limit = 2048 ): string {
		// Remove C0 control chars (\x00-\x1F), DEL (\x7F) — covers CR/LF.
		$clean = preg_replace( '/[\x00-\x1F\x7F]/', '', $url );
		if ( null === $clean ) {
			// preg_replace returns null on error (e.g. invalid UTF-8); fall back
			// to a conservative byte-level filter so we never log the raw value.
			$clean = '';
			$length = strlen( $url );
			for ( $i = 0; $i < $length; $i++ ) {
				$code = ord( $url[ $i ] );
				if ( $code > 0x1F && 0x7F !== $code ) {
					$clean .= $url[ $i ];
				}
			}
		}

		if ( $limit > 0 && strlen( $clean ) > $limit ) {
			$clean = substr( $clean, 0, $limit ) . '…[truncated]';
		}

		return $clean;
	}

	/**
	 * Write a preload diagnostic line to the PHP error log.
	 *
	 * All preload logging routes through here so it stays silent in production:
	 * messages are only emitted when both WP_DEBUG and WP_DEBUG_LOG are enabled.
	 * This keeps the log clean by default while preserving full diagnostics when
	 * debug logging is explicitly turned on.
	 *
	 * @param string $message Message to log (already sanitized if it holds a URL).
	 * @return void
	 */
	private static function log( string $message ): void {
		$debug_enabled = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$log_enabled   = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;

		if ( ! $debug_enabled || ! $log_enabled ) {
			return;
		}

		error_log( $message );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Check whether a URL belongs to this site.
	 *
	 * Enforces both host and path-prefix matching against the current blog's
	 * home URL. This prevents cross-subsite warmup on subfolder multisite.
	 *
	 * @param  string $url
	 * @return bool
	 */
	public static function is_local_url( string $url ): bool {
		if ( empty( $url ) ) {
			return false;
		}

		$site_home = home_url( '/' );
		if ( ! self::is_safe_local_candidate_url( $url, $site_home ) ) {
			return false;
		}

		if ( ! is_multisite() ) {
			$url_path  = self::normalize_url_path( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			$site_path = self::normalize_url_path( (string) wp_parse_url( $site_home, PHP_URL_PATH ) );

			return 0 === strpos( $url_path, $site_path );
		}

		$matched_blog_id = self::get_matching_blog_id_for_url( $url );
		if ( $matched_blog_id <= 0 ) {
			return false;
		}

		$current_blog_id = get_current_blog_id();
		return $matched_blog_id === $current_blog_id;
	}

	/**
	 * Resolve the blog that owns a URL (host + longest path prefix match).
	 *
	 * On subfolder multisite this prevents the main site path "/" from matching
	 * every subsite URL by choosing the most specific (longest) matching path.
	 *
	 * @param string $url Absolute URL to evaluate.
	 * @return int Blog ID, or 0 when URL does not belong to this install.
	 */
	private static function get_matching_blog_id_for_url( string $url ): int {
		if ( empty( $url ) ) {
			return 0;
		}

		$url_path = self::normalize_url_path( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		$sites = get_sites(
			array(
				'number'   => 0,
				'fields'   => 'ids',
				'archived' => 0,
				'spam'     => 0,
				'deleted'  => 0,
			)
		);

		$matched_blog_id = 0;
		$matched_len     = -1;

		foreach ( $sites as $site_id ) {
			$site_id   = (int) $site_id;
			$site_home = get_home_url( $site_id, '/' );
			if ( ! self::is_safe_local_candidate_url( $url, $site_home ) ) {
				continue;
			}

			$site_path = self::normalize_url_path( (string) wp_parse_url( $site_home, PHP_URL_PATH ) );
			if ( 0 !== strpos( $url_path, $site_path ) ) {
				continue;
			}

			$path_len = strlen( $site_path );
			if ( $path_len > $matched_len ) {
				$matched_len     = $path_len;
				$matched_blog_id = $site_id;
			}
		}

		return $matched_blog_id;
	}

	/**
	 * Validate a preload URL against site home URL constraints.
	 *
	 * Requires HTTP(S), blocks URL userinfo, and enforces same host/scheme/port.
	 *
	 * @param string $url Target URL.
	 * @param string $site_home Site home URL used as security baseline.
	 * @return bool
	 */
	private static function is_safe_local_candidate_url( string $url, string $site_home ): bool {
		$url_parts  = wp_parse_url( $url );
		$site_parts = wp_parse_url( $site_home );

		if ( ! is_array( $url_parts ) || ! is_array( $site_parts ) ) {
			return false;
		}

		$url_scheme  = isset( $url_parts['scheme'] ) ? strtolower( (string) $url_parts['scheme'] ) : '';
		$site_scheme = isset( $site_parts['scheme'] ) ? strtolower( (string) $site_parts['scheme'] ) : '';

		if ( empty( $url_scheme ) || empty( $site_scheme ) ) {
			return false;
		}

		if ( ! in_array( $url_scheme, array( 'http', 'https' ), true ) || $url_scheme !== $site_scheme ) {
			return false;
		}

		if ( ! empty( $url_parts['user'] ) || ! empty( $url_parts['pass'] ) ) {
			return false;
		}

		$url_host  = isset( $url_parts['host'] ) ? (string) $url_parts['host'] : '';
		$site_host = isset( $site_parts['host'] ) ? (string) $site_parts['host'] : '';

		if ( empty( $url_host ) || empty( $site_host ) || 0 !== strcasecmp( $url_host, $site_host ) ) {
			return false;
		}

		$url_port  = isset( $url_parts['port'] ) ? (int) $url_parts['port'] : self::get_default_port_for_scheme( $url_scheme );
		$site_port = isset( $site_parts['port'] ) ? (int) $site_parts['port'] : self::get_default_port_for_scheme( $site_scheme );

		if ( $url_port <= 0 || $site_port <= 0 || $url_port !== $site_port ) {
			return false;
		}

		return true;
	}

	/**
	 * Normalize URL path to slash-prefixed and slash-suffixed format.
	 *
	 * @param string $path Raw URL path.
	 * @return string
	 */
	private static function normalize_url_path( string $path ): string {
		return trailingslashit( '/' . ltrim( $path, '/' ) );
	}

	/**
	 * Resolve default port for supported HTTP schemes.
	 *
	 * @param string $scheme URL scheme.
	 * @return int
	 */
	private static function get_default_port_for_scheme( string $scheme ): int {
		if ( 'https' === $scheme ) {
			return 443;
		}

		if ( 'http' === $scheme ) {
			return 80;
		}

		return 0;
	}

	/**
	 * Returns the option key for the preload queue, scoped per blog on
	 * multisite so each sub-blog maintains its own independent queue.
	 *
	 * @return string
	 */
	private static function get_queue_key(): string {
		if ( is_multisite() ) {
			return self::QUEUE_KEY . '_' . get_current_blog_id();
		}
		return self::QUEUE_KEY;
	}

	/**
	 * Returns the option key for the last-warm timestamp, scoped per blog
	 * on multisite — mirrors get_queue_key() scoping so both keys always
	 * refer to the same logical preload session.
	 *
	 * @return string
	 */
	private static function get_last_warm_key(): string {
		if ( is_multisite() ) {
			return self::LAST_WARM_KEY . '_' . get_current_blog_id();
		}
		return self::LAST_WARM_KEY;
	}

	/**
	 * Returns the Action Scheduler group for the current blog. On multisite
	 * each sub-blog gets its own group (e.g. "breeze-preload-5") so that:
	 *
	 *   - as_unschedule_all_actions() only affects the calling blog's chain
	 *   - $unique=true in as_enqueue_async_action() is enforced per blog
	 *     rather than network-wide
	 *
	 * Without this, simultaneous purges on different sub-blogs collide and
	 * only one blog's chain ever runs.
	 *
	 * @return string
	 */
	private static function get_blog_group(): string {
		if ( is_multisite() ) {
			return self::AS_GROUP . '-' . get_current_blog_id();
		}
		return self::AS_GROUP;
	}

	/**
	 * Check whether Action Scheduler tables exist in the active blog context.
	 *
	 * On multisite, Action Scheduler uses per-blog table prefixes. Some sites
	 * can miss these tables (for example, blogs created after initial plugin
	 * setup). Guarding all AS calls prevents DB errors on those blogs.
	 *
	 * @return bool
	 */
	private static function has_action_scheduler_tables(): bool {
		if ( ! self::is_action_scheduler_available() ) {
			return false;
		}

		$blog_id = is_multisite() ? get_current_blog_id() : 0;

		if ( isset( self::$as_tables_ready_by_blog[ $blog_id ] ) ) {
			return self::$as_tables_ready_by_blog[ $blog_id ];
		}

		global $wpdb;

		$required_tables = array(
			$wpdb->prefix . 'actionscheduler_actions',
			$wpdb->prefix . 'actionscheduler_claims',
			$wpdb->prefix . 'actionscheduler_groups',
			$wpdb->prefix . 'actionscheduler_logs',
		);

		$tables_exist = self::check_tables_exist( $required_tables );
		if ( true === $tables_exist ) {
			self::$as_tables_ready_by_blog[ $blog_id ] = true;
			return true;
		}

		$repair_done = ! empty( self::$as_schema_repair_attempted_by_blog[ $blog_id ] );
		if ( ! $repair_done ) {
			self::$as_schema_repair_attempted_by_blog[ $blog_id ] = true;
			self::maybe_repair_action_scheduler_schema_for_blog( $blog_id );
			$tables_exist = self::check_tables_exist( $required_tables );
		}

		self::$as_tables_ready_by_blog[ $blog_id ] = ( true === $tables_exist );
		return self::$as_tables_ready_by_blog[ $blog_id ];
	}

	/**
	 * Check whether Action Scheduler procedural functions are available.
	 *
	 * @return bool
	 */
	private static function is_action_scheduler_available(): bool {
		// class_exists() guards against "class not found" fatals during a
		// self-rollback, when AS functions stay in memory but its files vanish.
		return function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_unschedule_all_actions' )
			&& class_exists( 'ActionScheduler_ActionFactory' )
			&& class_exists( 'ActionScheduler_Lock' );
	}

	/**
	 * Check whether all provided table names exist in the current DB context.
	 *
	 * @param string[] $required_tables Fully-qualified table names.
	 * @return bool
	 */
	private static function check_tables_exist( array $required_tables ): bool {
		global $wpdb;

		foreach ( $required_tables as $table_name ) {
			$pattern        = str_replace( '_', '\\_', $table_name );
			$existing_table = $wpdb->get_var(
				$wpdb->prepare(
					'SHOW TABLES LIKE %s',
					$pattern
				)
			);

			if ( $existing_table !== $table_name ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Attempt to create/fix Action Scheduler schema for the active blog.
	 *
	 * @param int $blog_id Blog ID for logging.
	 * @return void
	 */
	private static function maybe_repair_action_scheduler_schema_for_blog( int $blog_id ): void {
		if ( ! class_exists( '\ActionScheduler_StoreSchema' ) || ! class_exists( '\ActionScheduler_LoggerSchema' ) ) {
			return;
		}

		if ( ! self::can_attempt_action_scheduler_schema_repair( $blog_id ) ) {
			self::log( '[Breeze Preload] Action Scheduler schema repair skipped for blog ' . $blog_id . ' (not allowed in current context).' );
			return;
		}

		try {
			$store_schema = new \ActionScheduler_StoreSchema();
			$store_schema->init();
			$store_schema->register_tables( true );

			$logger_schema = new \ActionScheduler_LoggerSchema();
			$logger_schema->init();
			$logger_schema->register_tables( true );

			self::log( '[Breeze Preload] Action Scheduler schema repair attempted for blog ' . $blog_id . '.' );
		} catch ( \Throwable $error ) {
			self::log( '[Breeze Preload] Action Scheduler schema repair failed for blog ' . $blog_id . ': ' . $error->getMessage() );
		}
	}

	/**
	 * Decide whether AS schema repair is allowed in the current context.
	 *
	 * Default policy:
	 * - Multisite: require network-management capability.
	 * - Single site: require manage_options capability.
	 *
	 * The decision can be overridden for controlled environments with:
	 * - breeze_preload_allow_as_schema_repair filter.
	 *
	 * @param int $blog_id Blog ID.
	 * @return bool
	 */
	private static function can_attempt_action_scheduler_schema_repair( int $blog_id ): bool {
		$allowed = false;

		if ( is_multisite() ) {
			if ( function_exists( 'breeze_user_can_manage_network' ) ) {
				$allowed = breeze_user_can_manage_network();
			} elseif ( function_exists( 'current_user_can' ) ) {
				$allowed = current_user_can( 'manage_network_options' );
			}
		} elseif ( function_exists( 'current_user_can' ) ) {
			$allowed = current_user_can( 'manage_options' );
		}

		/**
		 * Filter whether Breeze may run AS schema repair DDL.
		 *
		 * @param bool $allowed Default permission decision.
		 * @param int  $blog_id Active blog ID.
		 */
		return (bool) apply_filters( 'breeze_preload_allow_as_schema_repair', $allowed, $blog_id );
	}
}
