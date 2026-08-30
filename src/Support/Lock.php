<?php
/**
 * Cross-request mutex.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Support;

defined( 'ABSPATH' ) || exit;

/*
 * PHPCS exemption, file scope. The one direct query here is a compare-and-swap on wp_options,
 * which is the entire point: it is what makes this a real mutex instead of a racy get-then-set.
 * Routing it through a caching layer would defeat it.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * A mutex built on add_option().
 *
 * Transients are the obvious choice and the wrong one: under a persistent object cache a
 * get/set pair is not atomic, so two concurrent cron requests can both believe they hold the
 * lock. add_option() is backed by a UNIQUE index on option_name, so exactly one caller wins
 * the insert. That is a real mutex.
 *
 * Locks carry an expiry so a worker killed by a PHP timeout cannot wedge the queue forever.
 */
final class Lock {

	private const PREFIX = 'oh_my_cache_lock_';

	/**
	 * Try to take a lock.
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds before the lock is considered abandoned.
	 */
	public static function acquire( string $name, int $ttl = 60 ): bool {
		$option  = self::PREFIX . $name;
		$expires = time() + max( 1, $ttl );

		// Never autoload: these are short-lived and irrelevant to the front end.
		if ( add_option( $option, (string) $expires, '', false ) ) {
			return true;
		}

		// The option exists. Steal it only when it is demonstrably stale.
		$held = (int) get_option( $option, 0 );
		if ( $held > time() ) {
			return false;
		}

		global $wpdb;

		/*
		 * Compare-and-swap on the stale value. Two racing workers both see the same stale
		 * timestamp, but only one UPDATE matches it, so only one gets a row count of 1.
		 */
		$updated = $wpdb->update(
			$wpdb->options,
			[ 'option_value' => (string) $expires ],
			[
				'option_name'  => $option,
				'option_value' => (string) $held,
			],
			[ '%s' ],
			[ '%s', '%s' ]
		);

		if ( 1 === $updated ) {
			wp_cache_delete( $option, 'options' );
			return true;
		}

		return false;
	}

	/**
	 * Release a lock.
	 *
	 * @param string $name Lock name.
	 */
	public static function release( string $name ): void {
		delete_option( self::PREFIX . $name );
	}

	/**
	 * Whether a lock is currently held by someone.
	 *
	 * @param string $name Lock name.
	 */
	public static function held( string $name ): bool {
		return (int) get_option( self::PREFIX . $name, 0 ) > time();
	}

	/**
	 * Option name for a lock, so uninstall can sweep them.
	 *
	 * @param string $name Lock name.
	 */
	public static function option_name( string $name ): string {
		return self::PREFIX . $name;
	}
}
