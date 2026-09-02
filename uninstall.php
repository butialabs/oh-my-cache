<?php
/**
 * Uninstall routine.
 *
 * Deliberately standalone: it does not rely on the autoloader or on any plugin class, because
 * WordPress runs this file with the plugin already deactivated.
 *
 * Nothing is deleted unless the operator explicitly opted in. Deactivating to debug a conflict
 * and losing every setting would be a nasty surprise, and this file also runs when a plugin is
 * removed from the plugins screen.
 *
 * Cloudflare changes are never reverted here. Rewriting somebody's zone settings and deleting
 * their cache rules as a side effect of removing a plugin would be both destructive and
 * unexpected; the Settings screen has an explicit button for that.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * PHPCS exemption, file scope. Removing a plugin's own table on uninstall is a schema change by
 * definition, and the table name is built from $wpdb->prefix rather than from input. Caching is
 * irrelevant when the next thing that happens is the table ceasing to exist.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

/**
 * Remove everything belonging to one site.
 */
function oh_my_cache_uninstall_site(): void {
	global $wpdb;

	$settings = get_option( 'oh_my_cache_settings', [] );

	if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	foreach ( [ 'oh_my_cache_jobs', 'omc_jobs' ] as $suffix ) {
		$table = $wpdb->prefix . $suffix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	$options = [
		'oh_my_cache_settings',
		'oh_my_cache_secrets',
		'oh_my_cache_cf_state',
		'oh_my_cache_wizard',
		'oh_my_cache_db_version',
		'oh_my_cache_queue_depth',
		'oh_my_cache_last_worker_run',
		'oh_my_cache_cf_ips',
		'oh_my_cache_importable',
		'oh_my_cache_custom_urls',
		'oh_my_cache_sitemap_index',
		'oh_my_cache_lock_worker',
	];

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Locks and cooldowns are created dynamically, so they are swept by prefix.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'oh_my_cache_lock_' ) . '%',
			$wpdb->esc_like( '_transient_oh_my_cache_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_oh_my_cache_' ) . '%',
			$wpdb->esc_like( '_transient_omc_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_omc_' ) . '%'
		)
	);

	foreach ( [ 'oh_my_cache_worker', 'oh_my_cache_worker_now', 'oh_my_cache_cleanup', 'oh_my_cache_cf_ips', 'oh_my_cache_sitemaps', 'oh_my_cache_sitemaps_now' ] as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
}

/**
 * Run the removal across every site.
 *
 * Wrapped in a function rather than left at file scope: variables at the top level of an
 * uninstall script are globals, and a stray $sites or $site_id in the global namespace is both
 * a naming-collision risk and something WordPress rightly complains about.
 */
function oh_my_cache_uninstall(): void {
	if ( ! is_multisite() ) {
		oh_my_cache_uninstall_site();

		return;
	}

	foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $site_id ) {
		switch_to_blog( (int) $site_id );
		oh_my_cache_uninstall_site();
		restore_current_blog();
	}
}

oh_my_cache_uninstall();
