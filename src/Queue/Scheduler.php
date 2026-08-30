<?php
/**
 * Cron wiring for the queue.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Queue;

use OhMyCache\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and fires the recurring events.
 *
 * Every event is argument-free on purpose. App for Cloudflare schedules
 * wp_schedule_single_event( time(), 'cfPurgeCache', [ $urls ] ), which serialises an entire URL
 * array into the `cron` option. That bloats an autoloaded option on every purge and defeats
 * wp_next_scheduled() deduplication, because two different URL lists are two different events.
 * The work belongs in a table; cron only needs to say "go".
 */
final class Scheduler {

	public const HOOK_WORKER  = 'oh_my_cache_worker';
	public const HOOK_NOW     = 'oh_my_cache_worker_now';
	public const HOOK_CLEANUP = 'oh_my_cache_cleanup';
	public const HOOK_CF_IPS  = 'oh_my_cache_cf_ips';
	public const HOOK_SITEMAPS = 'oh_my_cache_sitemaps';

	/*
	 * The "do it now" twin of HOOK_SITEMAPS, separate for the same reason HOOK_NOW is separate
	 * from HOOK_WORKER: wp_next_scheduled() cannot tell a recurring event due in eleven hours from
	 * a one-off due in thirty seconds, so asking on the recurring hook does nothing.
	 */
	public const HOOK_SITEMAPS_NOW = 'oh_my_cache_sitemaps_now';

	public const SCHEDULE_MINUTE = 'oh_my_cache_minute';

	/** Beyond this, the dashboard says cron looks dead. */
	public const STALE_WORKER_SECONDS = 900;

	/**
	 * Add the one-minute interval.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE_MINUTE ] = [
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (Oh My Cache)', 'oh-my-cache' ),
		];

		return $schedules;
	}

	/**
	 * Ensure the recurring events exist. Idempotent, safe to call on every request.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK_WORKER ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE_MINUTE, self::HOOK_WORKER );
		}

		if ( ! wp_next_scheduled( self::HOOK_CLEANUP ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_CLEANUP );
		}

		if ( ! wp_next_scheduled( self::HOOK_CF_IPS ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::HOOK_CF_IPS );
		}

		if ( ! wp_next_scheduled( self::HOOK_SITEMAPS ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'twicedaily', self::HOOK_SITEMAPS );
		}
	}

	/**
	 * Remove every event this plugin owns.
	 */
	public static function unschedule(): void {
		foreach ( [ self::HOOK_WORKER, self::HOOK_NOW, self::HOOK_CLEANUP, self::HOOK_CF_IPS, self::HOOK_SITEMAPS, self::HOOK_SITEMAPS_NOW ] as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Ask for the queue to be drained soon.
	 *
	 * Honest about what this does: spawn_cron() refuses to spawn if any cron ran in the last
	 * WP_CRON_LOCK_TIMEOUT seconds (60 by default, see wp-includes/cron.php). So this is a
	 * nudge, not a guarantee, and queued work can wait up to about a minute.
	 *
	 * That is acceptable because inline dispatch is the normal path and the queue is the
	 * exception. Sites that need a hard guarantee have two real options, both documented:
	 * queue.worker_mode = inline|both, or a system crontab running `wp omc queue run --all`.
	 */
	public static function kick(): void {
		if ( ! wp_next_scheduled( self::HOOK_NOW ) ) {
			wp_schedule_single_event( time(), self::HOOK_NOW );
		}

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return;
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Whether cron looks dead, which on a DISABLE_WP_CRON site with no real crontab means
	 * purges have silently stopped happening.
	 */
	public static function looks_stalled(): bool {
		if ( Options::queue_depth() < 1 ) {
			return false;
		}

		$last = (int) get_option( Options::OPTION_LAST_RUN, 0 );

		if ( 0 === $last ) {
			// Never run, but there is work waiting.
			return true;
		}

		return ( time() - $last ) > self::STALE_WORKER_SECONDS;
	}

	/**
	 * Seconds since the worker last completed a pass, or null if it never has.
	 */
	public static function last_run_age(): ?int {
		$last = (int) get_option( Options::OPTION_LAST_RUN, 0 );

		return $last > 0 ? max( 0, time() - $last ) : null;
	}
}
