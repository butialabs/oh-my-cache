<?php
/**
 * Jobs table schema.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Queue;

use OhMyCache\Support\Options;

defined( 'ABSPATH' ) || exit;

/*
 * PHPCS exemption, file scope. This class owns the plugin's own table: creating it, checking it
 * exists and dropping it on uninstall are schema operations by definition, and the table name is
 * built from $wpdb->prefix rather than from input.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

/**
 * Creates and upgrades the jobs table.
 *
 * dbDelta is famously pedantic. The DDL below obeys its rules deliberately:
 * two spaces after PRIMARY KEY, KEY rather than INDEX, no IF NOT EXISTS, lowercase types,
 * one field per line, and no CURRENT_TIMESTAMP defaults (dbDelta rewrites those every run
 * and would report a permanent, pointless diff).
 *
 * All datetimes are UTC via gmdate(). Mixing current_time() and gmdate() in available_at
 * comparisons is how queues end up running hours early, or never.
 */
final class Schema {

	/**
	 * Bumping this triggers maybeUpgrade on the next request; no reactivation needed.
	 */
	public const DB_VERSION = 2;

	/**
	 * Fully qualified table name for this site.
	 *
	 * One table per site: $wpdb->prefix already scopes it, so a site that wedges its queue
	 * cannot affect its neighbours on a network.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'oh_my_cache_jobs';
	}

	/**
	 * Create or update the table.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	driver varchar(32) NOT NULL default '',
	action varchar(32) NOT NULL default '',
	payload longtext NOT NULL,
	payload_hash char(40) NOT NULL default '',
	status varchar(16) NOT NULL default 'pending',
	priority tinyint(4) NOT NULL default 10,
	attempts smallint(5) unsigned NOT NULL default 0,
	max_attempts smallint(5) unsigned NOT NULL default 6,
	available_at datetime NOT NULL default '0000-00-00 00:00:00',
	claimed_at datetime default NULL,
	claim_token char(32) default NULL,
	reason varchar(191) NOT NULL default '',
	last_error text,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	updated_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY status_available (status, available_at, priority, id),
	KEY claim (claim_token),
	KEY dedupe (payload_hash, status),
	KEY driver_status (driver, status)
) {$collate};";

		dbDelta( $sql );

		update_option( Options::OPTION_DB_VERSION, self::DB_VERSION, true );
	}

	/**
	 * Install or upgrade when the stored version lags.
	 *
	 * Runs on plugins_loaded behind an autoloaded option read, so the steady state costs no
	 * query. This is what lets a plugin update add the table without a reactivation, and what
	 * makes lazy per-site installation on a large network viable.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( Options::OPTION_DB_VERSION, 0 ) >= self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Whether the table actually exists. Used by the doctor and by defensive callers.
	 */
	public static function exists(): bool {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return $found === $table;
	}

	/**
	 * Drop the table. Only ever called from uninstall, behind an explicit opt-in.
	 */
	public static function drop(): void {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Current UTC datetime in the exact format the table stores.
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * UTC datetime offset from now.
	 *
	 * @param int $seconds Offset, may be negative.
	 */
	public static function from_now( int $seconds ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $seconds );
	}
}
