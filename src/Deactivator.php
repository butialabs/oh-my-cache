<?php
/**
 * Deactivation routine.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache;

use OhMyCache\Queue\Scheduler;
use OhMyCache\Support\Lock;

defined( 'ABSPATH' ) || exit;

/**
 * Stops the scheduled work and lets go of the worker lock.
 *
 * The table and the settings survive: deactivation is not uninstallation, and someone
 * deactivating to debug a conflict should not lose their configuration. Data removal lives in
 * uninstall.php, behind an explicit opt-in.
 */
final class Deactivator {

	public static function deactivate(): void {
		Scheduler::unschedule();

		// A worker killed by the deactivation must not leave the queue wedged until the lock
		// ages out.
		Lock::release( 'worker' );
	}
}
