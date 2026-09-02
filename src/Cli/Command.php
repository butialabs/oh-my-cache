<?php
/**
 * WP-CLI commands.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cli;

use OhMyCache\Admin\Doctor;
use OhMyCache\Plugin;
use OhMyCache\Queue\JobStatus;
use OhMyCache\Queue\QueueRepository;
use OhMyCache\Queue\Schema;
use OhMyCache\Queue\Worker;
use OhMyCache\Support\Migrator;
use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/*
 * PHPCS exemption, file scope. The listing query reads the plugin's own jobs table, named from
 * $wpdb->prefix, with every value passed as a placeholder. Results are deliberately uncached:
 * `wp oh-my-cache queue list` exists to show the queue as it is right now.
 */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

/**
 * Manage caching from the shell.
 *
 * The commands run through the same public API as everything else, so a deploy script and a
 * post save take identical code paths.
 */
final class Command {

	/**
	 * Purge one or more URLs.
	 *
	 * ## OPTIONS
	 *
	 * <url>...
	 * : URLs to purge.
	 *
	 * [--driver=<driver>]
	 * : Restrict to one driver: nginx, redis, cloudflare, or all.
	 * ---
	 * default: all
	 * ---
	 *
	 * [--now]
	 * : Purge synchronously, ignoring the inline budget. Use this in deploy scripts.
	 *
	 * ## EXAMPLES
	 *
	 *     wp oh-my-cache purge https://example.com/ --now
	 *
	 * @param array<int, string>    $args       URLs.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function purge( array $args, array $assoc_args ): void {
		$ticket = oh_my_cache_purge_url(
			$args,
			[
				'drivers' => [ (string) ( $assoc_args['driver'] ?? 'all' ) ],
				'mode'    => isset( $assoc_args['now'] ) ? 'now' : null,
				'reason'  => 'cli',
			]
		);

		if ( ! $ticket->accepted() ) {
			WP_CLI::error( $ticket->rejection() );
		}

		WP_CLI::success( $ticket->summary() );
	}

	/**
	 * Purge everything.
	 *
	 * ## OPTIONS
	 *
	 * [--driver=<driver>]
	 * : Restrict to one driver.
	 * ---
	 * default: all
	 * ---
	 *
	 * [--now]
	 * : Purge synchronously.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @subcommand purge-all
	 *
	 * @param array<int, string>    $args       Unused.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function purge_all( array $args, array $assoc_args ): void {
		WP_CLI::confirm( 'Purge the entire cache?', $assoc_args );

		$ticket = oh_my_cache_purge_all(
			[
				'drivers' => [ (string) ( $assoc_args['driver'] ?? 'all' ) ],
				'mode'    => isset( $assoc_args['now'] ) ? 'now' : null,
				'reason'  => 'cli',
			]
		);

		if ( ! $ticket->accepted() ) {
			WP_CLI::error( $ticket->rejection() );
		}

		WP_CLI::success( $ticket->summary() );
	}

	/**
	 * Inspect and drive the queue.
	 *
	 * ## OPTIONS
	 *
	 * <subcommand>
	 * : list, run, retry, clear or status.
	 *
	 * [--status=<status>]
	 * : Filter by pending, claimed, done or dead.
	 *
	 * [--driver=<driver>]
	 * : Filter by driver.
	 *
	 * [--limit=<n>]
	 * : Maximum jobs to process.
	 *
	 * [--all]
	 * : Keep draining until the queue is empty. This is what a system crontab should call.
	 *
	 * [--format=<format>]
	 * : table, json, csv or ids.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # The documented escape hatch when DISABLE_WP_CRON is set.
	 *     * * * * * wp oh-my-cache queue run --all --quiet
	 *
	 * @param array<int, string>    $args       Subcommand.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function queue( array $args, array $assoc_args ): void {
		$sub        = $args[0] ?? 'status';
		$repository = $this->repository();

		switch ( $sub ) {
			case 'status':
				$counts = $repository->counts();
				$rows   = [];

				foreach ( $counts as $status => $total ) {
					$rows[] = [
						'status' => $status,
						'jobs'   => $total,
					];
				}

				Utils\format_items( 'table', $rows, [ 'status', 'jobs' ] );
				return;

			case 'list':
				$this->queue_list( $assoc_args );
				return;

			case 'run':
				$this->queue_run( $assoc_args );
				return;

			case 'retry':
				$affected = $repository->retry_dead();
				WP_CLI::success( sprintf( '%d job(s) re-queued.', $affected ) );
				return;

			case 'clear':
				$removed = $repository->gc();
				WP_CLI::success( sprintf( '%d finished job(s) removed.', $removed ) );
				return;
		}

		WP_CLI::error( sprintf( 'Unknown subcommand: %s', $sub ) );
	}

	/**
	 * Warm the cache from the sitemap.
	 *
	 * ## OPTIONS
	 *
	 * [--url=<sitemap>]
	 * : Sitemap URL. Defaults to the WordPress core sitemap.
	 *
	 * @param array<int, string>    $args       Unused.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function preload( array $args, array $assoc_args ): void {
		$queued = Plugin::service( 'preloader' )->schedule( (string) ( $assoc_args['url'] ?? '' ), 'cli:preload' );

		WP_CLI::success( sprintf( '%d preload batch(es) queued.', $queued ) );
	}

	/**
	 * Import settings from the plugin being replaced.
	 *
	 * ## OPTIONS
	 *
	 * [--from=<plugin>]
	 * : nginx-helper, app-for-cf or all.
	 * ---
	 * default: all
	 * ---
	 *
	 * [--dry-run]
	 * : Report what would change without saving.
	 *
	 * @param array<int, string>    $args       Unused.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function migrate( array $args, array $assoc_args ): void {
		$from    = (string) ( $assoc_args['from'] ?? 'all' );
		$dry_run = isset( $assoc_args['dry-run'] );

		if ( in_array( $from, [ 'all', 'nginx-helper' ], true ) ) {
			$result = Migrator::import_nginx_helper( $dry_run );
			WP_CLI::log( $result ? 'Nginx Helper settings read.' : 'No Nginx Helper settings found.' );
		}

		if ( in_array( $from, [ 'all', 'app-for-cf' ], true ) ) {
			$result = Migrator::import_app_for_cf( $dry_run );
			WP_CLI::log( $result ? 'App for Cloudflare settings read.' : 'No App for Cloudflare settings found.' );
		}

		WP_CLI::success( $dry_run ? 'Dry run complete; nothing was saved.' : 'Import complete.' );
	}

	/**
	 * Diagnose the setup.
	 *
	 * @param array<int, string>    $args       Unused.
	 * @param array<string, string> $assoc_args Unused.
	 */
	public function doctor( array $args, array $assoc_args ): void {
		$plugin = Plugin::instance();

		if ( ! $plugin ) {
			WP_CLI::error( 'Oh My Cache is not running.' );
		}

		$results = ( new Doctor( $plugin->container() ) )->run();
		$rows    = [];

		foreach ( $results as $result ) {
			$rows[] = [
				'check'  => $result['label'],
				'status' => strtoupper( $result['status'] ),
				'detail' => $result['detail'],
			];
		}

		Utils\format_items( 'table', $rows, [ 'check', 'status', 'detail' ] );

		if ( Doctor::has_errors( $results ) ) {
			WP_CLI::halt( 1 );
		}
	}

	/* --------------------------------------------------------------------- */

	/**
	 * @param array<string, string> $assoc_args Flags.
	 */
	private function queue_list( array $assoc_args ): void {
		global $wpdb;

		$table  = Schema::table();
		$status = (string) ( $assoc_args['status'] ?? '' );
		$limit  = (int) ( $assoc_args['limit'] ?? 50 );

		if ( in_array( $status, JobStatus::values(), true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
				ARRAY_A
			);
		}

		$items = [];

		foreach ( (array) $rows as $row ) {
			$items[] = [
				'id'       => $row['id'],
				'driver'   => $row['driver'],
				'action'   => $row['action'],
				'status'   => $row['status'],
				'attempts' => $row['attempts'] . '/' . $row['max_attempts'],
				'reason'   => $row['reason'],
				'next'     => $row['available_at'],
				'outcome'  => (string) $row['last_error'],
			];
		}

		Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			$items,
			[ 'id', 'driver', 'action', 'status', 'attempts', 'reason', 'next', 'outcome' ]
		);
	}

	/**
	 * @param array<string, string> $assoc_args Flags.
	 */
	private function queue_run( array $assoc_args ): void {
		$plugin = Plugin::instance();

		if ( ! $plugin ) {
			WP_CLI::error( 'Oh My Cache is not running.' );
		}

		/** @var Worker $worker */
		$worker = $plugin->container()->get( 'worker' );

		$driver = isset( $assoc_args['driver'] ) ? (string) $assoc_args['driver'] : null;
		$limit  = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : null;
		$total  = 0;

		if ( isset( $assoc_args['all'] ) ) {
			// Keep going until a pass does nothing, so a crontab entry drains a backlog.
			do {
				$processed = $worker->run( 1000, $driver );
				$total    += $processed;
			} while ( $processed > 0 );
		} else {
			$total = $worker->run( $limit ?? 1000, $driver );
		}

		WP_CLI::success( sprintf( '%d job(s) processed.', $total ) );
	}

	private function repository(): QueueRepository {
		$plugin = Plugin::instance();

		if ( ! $plugin ) {
			WP_CLI::error( 'Oh My Cache is not running.' );
		}

		return $plugin->container()->get( 'queue' );
	}
}
