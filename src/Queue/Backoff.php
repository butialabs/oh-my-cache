<?php
/**
 * Retry backoff schedule.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Exponential backoff with jitter.
 *
 * The jitter is not decoration. When Cloudflare rate-limits a burst, two hundred jobs fail in
 * the same second; without jitter they would all retry in the same second and earn the same
 * 429 again, forever. Spreading them by +/-10% breaks the lockstep.
 */
final class Backoff {

	/** Seconds to wait before attempt 2, 3, 4, 5 and 6. */
	private const SCHEDULE = [ 60, 300, 900, 3600, 21600 ];

	/** Jitter as a fraction of the delay. */
	private const JITTER = 0.10;

	/**
	 * Delay before the next attempt, given how many attempts have already been spent.
	 *
	 * @param int $attempts Attempts already made (1 means one failure so far).
	 */
	public static function delay_for( int $attempts ): int {
		$schedule = self::schedule();

		$index = max( 0, $attempts - 1 );
		$base  = $schedule[ $index ] ?? end( $schedule );

		return self::jitter( (int) $base );
	}

	/**
	 * The schedule, filterable so a site can trade freshness for API budget.
	 *
	 * @return array<int, int>
	 */
	public static function schedule(): array {
		/**
		 * Filters the retry backoff schedule.
		 *
		 * @param array<int, int> $schedule Seconds to wait before each subsequent attempt.
		 */
		$schedule = apply_filters( 'oh_my_cache_backoff_schedule', self::SCHEDULE );

		$schedule = array_values( array_filter( array_map( 'intval', (array) $schedule ), static fn ( int $s ): bool => $s > 0 ) );

		return $schedule ?: self::SCHEDULE;
	}

	/**
	 * Apply +/- JITTER to a delay.
	 *
	 * @param int $seconds Base delay.
	 */
	private static function jitter( int $seconds ): int {
		$spread = (int) round( $seconds * self::JITTER );

		if ( $spread < 1 ) {
			return $seconds;
		}

		return max( 1, $seconds + wp_rand( -$spread, $spread ) );
	}
}
