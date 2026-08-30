<?php
/**
 * Drains the queue.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Queue;

use OhMyCache\Cache\Cooldown;
use OhMyCache\Cache\DriverInterface;
use OhMyCache\Cache\DriverManager;
use OhMyCache\Cache\PurgeResult;
use OhMyCache\Support\Lock;
use OhMyCache\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Claims batches of due jobs and runs them within a wall-clock budget.
 */
final class Worker {

	private const LOCK = 'worker';

	public function __construct(
		private readonly QueueRepository $repository,
		private readonly DriverManager $drivers
	) {}

	/**
	 * One worker pass.
	 *
	 * @param int|null    $limit  Hard cap on jobs, for WP-CLI.
	 * @param string|null $driver Restrict to one driver, for WP-CLI.
	 * @return int Jobs processed.
	 */
	public function run( ?int $limit = null, ?string $driver = null ): int {
		/*
		 * Empty-queue guard. Under WP-Cron the worker fires off real traffic, so on a busy site
		 * this runs all day. Reading the autoloaded depth hint costs no query at all, because
		 * the value already arrived with alloptions. Without this, every idle tick would spend
		 * an UPDATE and a SELECT to discover there is nothing to do.
		 */
		if ( null === $limit && Options::queue_depth() < 1 ) {
			return 0;
		}

		if ( ! Lock::acquire( self::LOCK, 55 ) ) {
			return 0;
		}

		$processed = 0;

		try {
			$this->repository->reclaim_stale();

			$batch    = max( 1, (int) Options::get( 'queue.batch_size', 10 ) );
			$budget   = max( 1, (int) Options::get( 'queue.budget_seconds', 20 ) );
			$deadline = microtime( true ) + $budget;

			while ( microtime( true ) < $deadline ) {
				$take = null === $limit ? $batch : min( $batch, $limit - $processed );
				if ( $take < 1 ) {
					break;
				}

				$jobs = $this->repository->claim( $take, $driver );
				if ( ! $jobs ) {
					break;
				}

				foreach ( $jobs as $index => $job ) {
					// Out of time: hand back everything still untouched without spending attempts.
					if ( microtime( true ) >= $deadline ) {
						$this->repository->release( array_slice( $jobs, $index ) );
						break 2;
					}

					// A benched driver goes back in the pool rather than burning an attempt.
					if ( Cooldown::active( $job->driver ) ) {
						$this->repository->release( [ $job ] );
						continue;
					}

					$this->process( $job );
					++$processed;
				}
			}
		} finally {
			Lock::release( self::LOCK );
			update_option( Options::OPTION_LAST_RUN, time(), false );
		}

		return $processed;
	}

	/**
	 * Run one job and record the outcome.
	 *
	 * @param Job $job Job.
	 */
	public function process( Job $job ): void {
		// Housekeeping work that belongs to no cache backend.
		if ( 'system' === $job->driver ) {
			$this->process_system( $job );
			return;
		}

		$driver = $this->drivers->get( $job->driver );

		if ( ! $driver instanceof DriverInterface ) {
			$this->repository->kill(
				$job,
				sprintf(
					/* translators: %s: driver id. */
					__( 'Unknown driver "%s"; it was removed or its plugin is inactive.', 'oh-my-cache' ),
					$job->driver
				),
				$job->attempts + 1
			);
			return;
		}

		$availability = $driver->availability();
		if ( ! $availability->ok ) {
			// Retryable: an unreachable Redis or an unmounted cache path usually comes back.
			$this->repository->fail( $job, $availability->reason, true );
			return;
		}

		try {
			$result = $this->execute( $driver, $job );
		} catch ( \Throwable $e ) {
			$this->repository->fail( $job, $e->getMessage(), true );
			return;
		}

		$this->record( $job, $result );
	}

	/**
	 * Run a job that has no cache backend behind it, such as sitemap warming.
	 *
	 * @param Job $job Job.
	 */
	private function process_system( Job $job ): void {
		if ( 'preload' !== $job->action ) {
			$this->repository->kill(
				$job,
				sprintf(
					/* translators: %s: job action. */
					__( 'Unknown system action "%s".', 'oh-my-cache' ),
					$job->action
				),
				$job->attempts + 1
			);
			return;
		}

		$plugin = \OhMyCache\Plugin::instance();

		if ( ! $plugin ) {
			$this->repository->fail( $job, __( 'The plugin is not fully loaded.', 'oh-my-cache' ), true );
			return;
		}

		$warmed = $plugin->container()->get( 'preloader' )->warm( $job->urls() );

		$this->repository->complete(
			$job,
			sprintf(
				/* translators: %d: number of URLs requested. */
				_n( '%d URL warmed', '%d URLs warmed', $warmed, 'oh-my-cache' ),
				$warmed
			)
		);
	}

	/**
	 * Dispatch a job to its driver.
	 *
	 * @param DriverInterface $driver Driver.
	 * @param Job             $job    Job.
	 */
	private function execute( DriverInterface $driver, Job $job ): PurgeResult {
		return match ( $job->action ) {
			'purge_all'     => $driver->purge_all(),
			'purge_pattern' => $driver->purge_pattern(
				(string) ( $job->meta()['pattern'] ?? '' ),
				$job->cursor()
			),
			default         => $driver->purge_urls( $job->urls() ),
		};
	}

	/**
	 * Translate a result into a job state transition.
	 *
	 * @param Job         $job    Job.
	 * @param PurgeResult $result Outcome.
	 */
	private function record( Job $job, PurgeResult $result ): void {
		// A 429 benches the whole driver, not just this job.
		$retry_after = $result->get_retry_after();
		if ( null !== $retry_after ) {
			Cooldown::open( $job->driver, $retry_after );
		}

		// A bounded sweep that ran out of budget continues from where it stopped.
		$cursor = $result->resume_cursor();
		if ( null !== $cursor ) {
			$payload           = $job->payload;
			$payload['cursor'] = $cursor;

			$this->repository->fail(
				$job,
				$result->summary(),
				true,
				0,
				$payload
			);
			return;
		}

		if ( ! $result->has_failures() ) {
			$this->repository->complete( $job, $result->summary() );
			return;
		}

		/*
		 * Only the URLs that actually failed go round again. Successes and "was not cached"
		 * skips are done, and re-queueing them would mean a job that can never fully succeed.
		 */
		$payload         = $job->payload;
		$payload['urls'] = $result->failed_urls();

		$this->repository->fail(
			$job,
			$result->summary(),
			$result->is_retryable(),
			$retry_after,
			$payload
		);
	}
}
