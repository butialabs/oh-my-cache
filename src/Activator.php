<?php
/**
 * Activation routine.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache;

use OhMyCache\Queue\Schema;
use OhMyCache\Queue\Scheduler;
use OhMyCache\Support\Migrator;
use OhMyCache\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once, on activation.
 *
 * Version guarding is not done here: the `Requires PHP` and `Requires at least` headers make
 * WordPress refuse the activation outright, which is both earlier and more reliable than
 * anything this class could do.
 */
final class Activator {

	/**
	 * @param bool $network_wide Whether this is a network activation.
	 */
	public static function activate( bool $network_wide = false ): void {
		/*
		 * On a large network, looping get_sites() and running dbDelta hundreds of times in one
		 * request is how activation times out. Instead each site installs its own table lazily,
		 * on its first request, via Schema::maybe_upgrade() behind an autoloaded version check.
		 */
		if ( $network_wide && is_multisite() ) {
			return;
		}

		self::install_site();
	}

	/**
	 * Set up the current site.
	 */
	public static function install_site(): void {
		Schema::install();

		// Seed defaults without clobbering anything an existing install already saved.
		if ( false === get_option( Options::OPTION_SETTINGS, false ) ) {
			add_option( Options::OPTION_SETTINGS, Options::defaults(), '', true );
		}

		add_option( Options::OPTION_QUEUE_DEPTH, 0, '', true );

		/*
		 * Record what could be imported from the donor plugins, but import nothing. Silently
		 * adopting another plugin's settings on activation would be a surprise; the admin
		 * offers it as a choice instead.
		 */
		Migrator::detect();

		Scheduler::schedule();

		// Consumed once by the admin to send a new install to the wizard.
		set_transient( 'oh_my_cache_activation_redirect', 1, MINUTE_IN_SECONDS * 5 );
	}
}
