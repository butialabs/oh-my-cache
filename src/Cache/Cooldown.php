<?php
/**
 * Per-driver circuit breaker.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cache;

use OhMyCache\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Stops a failing driver from being hammered, in two situations that turn out to be the same
 * mechanism.
 *
 * Rate limits: when Cloudflare answers 429, the other forty queued jobs must not each spend a
 * request earning their own 429. One cooldown, honouring Retry-After, and the worker skips
 * that driver entirely until it lifts.
 *
 * Inline latency: NGINX in http mode issues a request to the site's own hostname. On a small
 * PHP-FPM pool that request blocks on itself. A 3s timeout turns a hang into a delay, and
 * after three consecutive timeouts this breaker opens so the site stops paying that toll on
 * every publish. It closes on its own.
 *
 * Transients are the right storage here (unlike the worker mutex): a lost cooldown is
 * harmless, and under a persistent object cache these never touch the database.
 */
final class Cooldown {

	private const PREFIX  = 'omc_cooldown_';
	private const FAILURE = 'omc_inline_fails_';

	/**
	 * Whether a driver is currently benched.
	 *
	 * @param string $driver Driver id.
	 */
	public static function active( string $driver ): bool {
		return (bool) get_transient( self::PREFIX . $driver );
	}

	/**
	 * Seconds left on a cooldown, best effort. Zero when not benched.
	 *
	 * @param string $driver Driver id.
	 */
	public static function remaining( string $driver ): int {
		$until = (int) get_transient( self::PREFIX . $driver );

		return $until > time() ? $until - time() : 0;
	}

	/**
	 * Bench a driver.
	 *
	 * @param string $driver  Driver id.
	 * @param int    $seconds How long.
	 */
	public static function open( string $driver, int $seconds ): void {
		$seconds = max( 1, $seconds );

		set_transient( self::PREFIX . $driver, time() + $seconds, $seconds );
	}

	/**
	 * Clear a cooldown and its failure streak.
	 *
	 * @param string $driver Driver id.
	 */
	public static function close( string $driver ): void {
		delete_transient( self::PREFIX . $driver );
		delete_transient( self::FAILURE . $driver );
	}

	/**
	 * Record a successful inline attempt, resetting the streak.
	 *
	 * @param string $driver Driver id.
	 */
	public static function record_inline_success( string $driver ): void {
		delete_transient( self::FAILURE . $driver );
	}

	/**
	 * Record a failed or timed-out inline attempt, opening the breaker at the threshold.
	 *
	 * @param string $driver Driver id.
	 * @return bool True when this failure opened the breaker.
	 */
	public static function record_inline_failure( string $driver ): bool {
		$threshold = max( 1, (int) Options::get( 'dispatch.inline_failure_threshold', 3 ) );
		$streak    = (int) get_transient( self::FAILURE . $driver ) + 1;

		if ( $streak >= $threshold ) {
			self::open( $driver, max( 1, (int) Options::get( 'dispatch.inline_cooldown', 300 ) ) );
			delete_transient( self::FAILURE . $driver );

			return true;
		}

		// Let a lone hiccup age out rather than accumulating across a whole day.
		set_transient( self::FAILURE . $driver, $streak, HOUR_IN_SECONDS );

		return false;
	}

	/**
	 * Current consecutive inline failure count, for the dashboard.
	 *
	 * @param string $driver Driver id.
	 */
	public static function failure_streak( string $driver ): int {
		return (int) get_transient( self::FAILURE . $driver );
	}
}
