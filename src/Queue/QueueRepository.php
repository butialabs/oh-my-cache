<?php
/**
 * Persistence and lifecycle for queued jobs.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Queue;

use OhMyCache\Support\Options;
use OhMyCache\Support\Redactor;

defined( 'ABSPATH' ) || exit;

/*
 * PHPCS exemption, file scope, with the reasoning written down rather than repeated as forty
 * inline ignores.
 *
 * Every query below addresses one table whose name is built from $wpdb->prefix and never from
 * input, and every value is passed as a placeholder to $wpdb->prepare(). PHPCS cannot follow a
 * table name held in a property, so it flags each one as unprepared, and it cannot count
 * placeholders when the replacements arrive as a dynamically sized array. Both are false here.
 *
 * Caching sniffs are exempted for the same reason a queue exists: these rows are the work list,
 * and reading a cached copy of it would mean processing jobs that another worker already took.
 */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

/**
 * The jobs table.
 *
 * Every value goes through $wpdb->prepare(). The table name is interpolated because it comes
 * from $wpdb->prefix and never from user input; PHPCS cannot tell the difference, so each
 * query carries an explicit ignore rather than a blanket one at the top of the file.
 */
final class QueueRepository {

	/** How long a claimed job may sit before a worker is presumed dead. */
	private const STALE_CLAIM_SECONDS = 300;

	private string $table;

	public function __construct() {
		$this->table = Schema::table();
	}

	/* --------------------------------------------------------------------- */
	/* Writing                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Enqueue a job, unless an identical one is already pending.
	 *
	 * @param string               $driver   Driver id.
	 * @param string               $action   purge_urls, purge_all or preload.
	 * @param array<string, mixed> $payload  Payload.
	 * @param string               $reason   Who asked.
	 * @param int                  $priority Lower runs first.
	 * @param int                  $delay    Seconds to wait before the first attempt.
	 * @return int Row id, or 0 when deduped or on failure.
	 */
	public function enqueue(
		string $driver,
		string $action,
		array $payload = [],
		string $reason = '',
		int $priority = 10,
		int $delay = 0
	): int {
		global $wpdb;

		$payload = wp_parse_args(
			$payload,
			[
				'urls'   => [],
				'cursor' => null,
				'meta'   => [],
			]
		);

		$hash = Job::hash( $driver, $action, $payload );

		if ( $this->has_pending( $hash ) ) {
			return 0;
		}

		$now = Schema::now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$this->table,
			[
				'driver'       => $driver,
				'action'       => $action,
				'payload'      => (string) wp_json_encode( $payload ),
				'payload_hash' => $hash,
				'status'       => JobStatus::Pending->value,
				'priority'     => $priority,
				'attempts'     => 0,
				'max_attempts' => (int) Options::get( 'queue.max_attempts', 6 ),
				'available_at' => Schema::from_now( max( 0, $delay ) ),
				'reason'       => mb_substr( $reason, 0, 191 ),
				'created_at'   => $now,
				'updated_at'   => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
		);

		if ( ! $inserted ) {
			return 0;
		}

		Options::bump_queue_depth( 1 );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Whether an identical job is already waiting.
	 *
	 * Stops an importer from stacking fifty identical "purge the homepage" jobs.
	 *
	 * @param string $hash Payload hash.
	 */
	public function has_pending( string $hash ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE payload_hash = %s AND status = %s LIMIT 1",
				$hash,
				JobStatus::Pending->value
			)
		);

		return null !== $found;
	}

	/* --------------------------------------------------------------------- */
	/* Claiming                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Atomically claim up to $limit due jobs.
	 *
	 * UPDATE ... ORDER BY ... LIMIT is atomic under InnoDB row locking, so two concurrent cron
	 * requests cannot claim the same rows. No explicit transaction: $wpdb offers no transaction
	 * API and another plugin may already hold one open on this connection.
	 *
	 * @param int         $limit  Maximum jobs to take.
	 * @param string|null $driver Restrict to one driver.
	 * @return array<int, Job>
	 */
	public function claim( int $limit, ?string $driver = null ): array {
		global $wpdb;

		$limit = max( 1, $limit );
		$token = wp_generate_password( 32, false, false );
		$now   = Schema::now();

		if ( null !== $driver ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"UPDATE {$this->table}
				 SET status = %s, claim_token = %s, claimed_at = %s, updated_at = %s
				 WHERE status = %s AND available_at <= %s AND driver = %s
				 ORDER BY priority ASC, available_at ASC, id ASC
				 LIMIT %d",
				JobStatus::Claimed->value,
				$token,
				$now,
				$now,
				JobStatus::Pending->value,
				$now,
				$driver,
				$limit
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"UPDATE {$this->table}
				 SET status = %s, claim_token = %s, claimed_at = %s, updated_at = %s
				 WHERE status = %s AND available_at <= %s
				 ORDER BY priority ASC, available_at ASC, id ASC
				 LIMIT %d",
				JobStatus::Claimed->value,
				$token,
				$now,
				$now,
				JobStatus::Pending->value,
				$now,
				$limit
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$claimed = (int) $wpdb->query( $sql );

		if ( $claimed < 1 ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE claim_token = %s ORDER BY priority ASC, id ASC",
				$token
			),
			ARRAY_A
		);

		return array_map( [ Job::class, 'from_row' ], (array) $rows );
	}

	/**
	 * Return claimed-but-unprocessed jobs to the pool without spending an attempt.
	 *
	 * Used when the worker runs out of budget mid-batch, and when a driver is in cooldown.
	 *
	 * @param array<int, Job>|array<int, int> $jobs Jobs or ids.
	 */
	public function release( array $jobs ): void {
		global $wpdb;

		$ids = array_map(
			static fn ( $job ): int => $job instanceof Job ? $job->id : (int) $job,
			$jobs
		);
		$ids = array_values( array_filter( $ids ) );

		if ( ! $ids ) {
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table}
				 SET status = %s, claim_token = NULL, claimed_at = NULL, updated_at = %s
				 WHERE id IN ({$placeholders})",
				array_merge( [ JobStatus::Pending->value, Schema::now() ], $ids )
			)
		);
	}

	/**
	 * Hand abandoned claims back to the pool, spending an attempt.
	 *
	 * Covers a worker killed mid-flight by a PHP timeout or an OOM. Spending an attempt is
	 * deliberate: a job that reliably kills its worker must eventually dead-letter rather than
	 * cycling forever.
	 *
	 * @return int Rows reclaimed.
	 */
	public function reclaim_stale(): int {
		global $wpdb;

		$cutoff = Schema::from_now( -self::STALE_CLAIM_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$reclaimed = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table}
				 SET status = %s, claim_token = NULL, claimed_at = NULL,
				     attempts = attempts + 1, updated_at = %s,
				     last_error = %s
				 WHERE status = %s AND claimed_at < %s",
				JobStatus::Pending->value,
				Schema::now(),
				'Worker vanished mid-flight; claim reclaimed.',
				JobStatus::Claimed->value,
				$cutoff
			)
		);

		if ( $reclaimed > 0 ) {
			$this->kill_exhausted();
		}

		return $reclaimed;
	}

	/* --------------------------------------------------------------------- */
	/* Completing                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Mark a job done, recording what actually happened.
	 *
	 * The summary lands in last_error on purpose: with no log file, the Done view of the queue
	 * screen is what answers "did my purge actually work?".
	 *
	 * @param Job    $job     Job.
	 * @param string $summary Human summary.
	 */
	public function complete( Job $job, string $summary = '' ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->table,
			[
				'status'      => JobStatus::Done->value,
				'claim_token' => null,
				'attempts'    => $job->attempts + 1,
				'last_error'  => Redactor::scrub( $summary ),
				'updated_at'  => Schema::now(),
			],
			[ 'id' => $job->id ],
			[ '%s', '%s', '%d', '%s', '%s' ],
			[ '%d' ]
		);

		Options::bump_queue_depth( -1 );
	}

	/**
	 * Reschedule a failed job, or dead-letter it when it is out of attempts.
	 *
	 * @param Job         $job         Job.
	 * @param string      $error       Failure message.
	 * @param bool        $retryable   False sends it straight to dead-letter.
	 * @param int|null    $retry_after Server-supplied delay, honoured when longer than backoff.
	 * @param array|null  $payload     Replacement payload, for a partial retry.
	 */
	public function fail(
		Job $job,
		string $error,
		bool $retryable = true,
		?int $retry_after = null,
		?array $payload = null
	): void {
		global $wpdb;

		$attempts = $job->attempts + 1;
		$error    = Redactor::scrub( $error );

		if ( ! $retryable || $attempts >= $job->max_attempts ) {
			$this->kill( $job, $error, $attempts );
			return;
		}

		$delay = Backoff::delay_for( $attempts );
		if ( null !== $retry_after ) {
			$delay = max( $delay, $retry_after );
		}

		$data   = [
			'status'       => JobStatus::Pending->value,
			'claim_token'  => null,
			'claimed_at'   => null,
			'attempts'     => $attempts,
			'available_at' => Schema::from_now( $delay ),
			'last_error'   => $error,
			'updated_at'   => Schema::now(),
		];
		$format = [ '%s', '%s', '%s', '%d', '%s', '%s', '%s' ];

		if ( null !== $payload ) {
			$data['payload']      = (string) wp_json_encode( $payload );
			$data['payload_hash'] = Job::hash( $job->driver, $job->action, $payload );
			$format[]             = '%s';
			$format[]             = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $this->table, $data, [ 'id' => $job->id ], $format, [ '%d' ] );
	}

	/**
	 * Move a job to dead-letter.
	 *
	 * @param Job    $job      Job.
	 * @param string $error    Final error.
	 * @param int    $attempts Attempts spent.
	 */
	public function kill( Job $job, string $error, int $attempts ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->table,
			[
				'status'      => JobStatus::Dead->value,
				'claim_token' => null,
				'claimed_at'  => null,
				'attempts'    => $attempts,
				'last_error'  => Redactor::scrub( $error ),
				'updated_at'  => Schema::now(),
			],
			[ 'id' => $job->id ],
			[ '%s', '%s', '%s', '%d', '%s', '%s' ],
			[ '%d' ]
		);

		Options::bump_queue_depth( -1 );

		/**
		 * Fires when a job exhausts its attempts or fails unrecoverably.
		 *
		 * @param Job    $job   The job.
		 * @param string $error Final error message, already redacted.
		 */
		do_action( 'oh_my_cache_job_dead', $job, $error );
	}

	/**
	 * Sweep pending jobs that are already past their attempt budget.
	 */
	private function kill_exhausted(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table}
				 SET status = %s, updated_at = %s
				 WHERE status = %s AND attempts >= max_attempts",
				JobStatus::Dead->value,
				Schema::now(),
				JobStatus::Pending->value
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Reading and maintenance                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Fetch one job.
	 *
	 * @param int $id Row id.
	 */
	public function find( int $id ): ?Job {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
			ARRAY_A
		);

		return $row ? Job::from_row( $row ) : null;
	}

	/**
	 * Counts per status, for the dashboard and the list table views.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		global $wpdb;

		$counts = array_fill_keys( JobStatus::values(), 0 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$this->table} GROUP BY status", ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Age in seconds of the oldest pending job, or null when the queue is empty.
	 *
	 * The dashboard shows this instead of implying the queue is instantaneous, because
	 * spawn_cron() will not spawn more than once a minute.
	 */
	public function oldest_pending_age(): ?int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$created = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT created_at FROM {$this->table} WHERE status = %s ORDER BY created_at ASC LIMIT 1",
				JobStatus::Pending->value
			)
		);

		if ( ! $created ) {
			return null;
		}

		return max( 0, time() - (int) strtotime( (string) $created . ' UTC' ) );
	}

	/**
	 * Re-queue dead jobs.
	 *
	 * @param array<int, int> $ids Row ids. Empty means every dead job.
	 * @return int Rows affected.
	 */
	public function retry_dead( array $ids = [] ): int {
		global $wpdb;

		$now = Schema::now();

		if ( $ids ) {
			$ids          = array_values( array_filter( array_map( 'intval', $ids ) ) );
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$affected = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->table}
					 SET status = %s, attempts = 0, available_at = %s, updated_at = %s, last_error = NULL
					 WHERE status = %s AND id IN ({$placeholders})",
					array_merge( [ JobStatus::Pending->value, $now, $now, JobStatus::Dead->value ], $ids )
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$affected = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->table}
					 SET status = %s, attempts = 0, available_at = %s, updated_at = %s, last_error = NULL
					 WHERE status = %s",
					JobStatus::Pending->value,
					$now,
					$now,
					JobStatus::Dead->value
				)
			);
		}

		if ( $affected > 0 ) {
			$this->resync_depth();
		}

		return $affected;
	}

	/**
	 * Make jobs due immediately.
	 *
	 * @param array<int, int> $ids Row ids.
	 * @return int Rows affected.
	 */
	public function run_now( array $ids ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table}
				 SET available_at = %s, updated_at = %s
				 WHERE status = %s AND id IN ({$placeholders})",
				array_merge( [ Schema::now(), Schema::now(), JobStatus::Pending->value ], $ids )
			)
		);
	}

	/**
	 * Delete jobs by id.
	 *
	 * @param array<int, int> $ids Row ids.
	 * @return int Rows deleted.
	 */
	public function delete( array $ids ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$deleted = (int) $wpdb->query(
			// The IN() list is built entirely from %d placeholders, one per id; PHPCS cannot see
			// placeholders that were assembled into a variable.
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->prepare( "DELETE FROM {$this->table} WHERE id IN ({$placeholders})", $ids )
		);

		if ( $deleted > 0 ) {
			$this->resync_depth();
		}

		return $deleted;
	}

	/**
	 * Drop finished rows past their retention window, then reconcile the depth hint.
	 *
	 * @return int Rows removed.
	 */
	public function gc(): int {
		global $wpdb;

		$done_cutoff = Schema::from_now( -60 * max( 1, (int) Options::get( 'queue.retain_done_minutes', 60 ) ) );
		$dead_cutoff = Schema::from_now( -DAY_IN_SECONDS * max( 1, (int) Options::get( 'queue.retain_dead_days', 30 ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$removed = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table}
				 WHERE ( status = %s AND updated_at < %s )
				    OR ( status = %s AND updated_at < %s )",
				JobStatus::Done->value,
				$done_cutoff,
				JobStatus::Dead->value,
				$dead_cutoff
			)
		);

		$this->resync_depth();

		return $removed;
	}

	/**
	 * Recompute the autoloaded depth hint from a real COUNT.
	 *
	 * The hint is allowed to drift; this is what makes the drift self-correcting rather than
	 * something that silently wedges the queue.
	 */
	public function resync_depth(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pending = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE status IN ( %s, %s )",
				JobStatus::Pending->value,
				JobStatus::Claimed->value
			)
		);

		Options::set_queue_depth( $pending );

		return $pending;
	}
}
