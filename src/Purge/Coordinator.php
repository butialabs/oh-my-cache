<?php
/**
 * Decides what runs now and what goes to the queue.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Purge;

use OhMyCache\Cache\Cooldown;
use OhMyCache\Cache\DriverInterface;
use OhMyCache\Cache\DriverManager;
use OhMyCache\Cache\PurgeResult;
use OhMyCache\Queue\QueueRepository;
use OhMyCache\Queue\Scheduler;
use OhMyCache\Support\Options;
use OhMyCache\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Real time by default; the queue is the safety net, not the normal path.
 *
 * Ordering matters and is not negotiable: nginx, then redis, then cloudflare. Purging the local
 * cache before the edge means Cloudflare's next MISS reaches an origin that has already
 * forgotten the old HTML. Do it the other way round and the edge dutifully re-caches the stale
 * page it just fetched.
 */
final class Coordinator {

	private PurgeRequest $request;

	private bool $shutdown_registered = false;

	public function __construct(
		private readonly DriverManager $drivers,
		private readonly QueueRepository $queue
	) {
		$this->request = new PurgeRequest();
	}

	/**
	 * Queue URLs for dispatch at the end of this request.
	 *
	 * @param array<int, string> $urls   URLs.
	 * @param string             $reason Who asked.
	 */
	public function add( array $urls, string $reason = '' ): void {
		if ( ! $urls ) {
			return;
		}

		$this->request->add( $urls, $reason );
		$this->ensure_shutdown();
	}

	/**
	 * Ask for a full purge at the end of this request.
	 *
	 * @param string $reason Who asked.
	 */
	public function add_purge_all( string $reason = '' ): void {
		$this->request->add_purge_all( $reason );
		$this->ensure_shutdown();
	}

	public function request(): PurgeRequest {
		return $this->request;
	}

	/**
	 * Register the shutdown handler, once, and only when there is something to do.
	 *
	 * A front-end request that changes nothing never reaches this, so it pays nothing.
	 */
	private function ensure_shutdown(): void {
		if ( $this->shutdown_registered ) {
			return;
		}

		/*
		 * on_shutdown(), not dispatch(). WP_Hook always calls a callback with at least one
		 * argument, so hooking dispatch() directly hands its typed array parameter an empty
		 * string and fatals the request. A dedicated no-argument entry point keeps the hook
		 * signature and the API signature from having to be the same thing.
		 */
		add_action( 'shutdown', [ $this, 'on_shutdown' ], 10 );
		$this->shutdown_registered = true;
	}

	/**
	 * Shutdown hook entry point.
	 *
	 * Takes no arguments on purpose; see ensure_shutdown().
	 */
	public function on_shutdown(): void {
		$this->dispatch();
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Send the accumulated work to the drivers.
	 *
	 * @param array<int, string> $driver_ids Restrict to these drivers.
	 * @param string|null        $mode       Override the configured dispatch mode.
	 * @return array<string, PurgeResult|null> Inline results, keyed by driver id.
	 */
	public function dispatch( array $driver_ids = [], ?string $mode = null ): array {
		if ( $this->request->is_empty() ) {
			return [];
		}

		$request = $this->request;
		$this->request = new PurgeRequest();

		$drivers = $this->drivers->resolve( $driver_ids );

		if ( ! $drivers ) {
			return [];
		}

		$urls = $request->urls();

		if ( ! $request->is_purge_all() ) {
			/**
			 * Filters the final URL set, after collection and deduplication.
			 *
			 * @param array<int, string> $urls   URLs about to be purged.
			 * @param string             $reason Who asked.
			 */
			$urls = (array) apply_filters( 'oh_my_cache_purge_urls', $urls, $request->reason() );

			// Existing customisations written for either donor plugin still get a say.
			$urls = LegacyHooks::apply_legacy_filters( $urls );

			/*
			 * Paths listed under "When to purge". A generated file like llms.txt goes stale on any
			 * change, so it rides along with every purge instead of one kind of edit. Not added to
			 * a full purge, where everything is already going.
			 */
			$urls = array_merge( $urls, Options::custom_urls() );

			/*
			 * Normalise at the end. Collection normalises its own output, but a filter callback can
			 * hand back anything, and a relative URL reaching the Redis driver becomes a key that
			 * matches nothing without looking like a failure.
			 */
			$urls = Url::normalize_all( $urls );
		}

		$results  = [];
		$deadline = microtime( true ) + (float) Options::get( 'dispatch.inline_budget', 8.0 );
		$queued   = false;

		foreach ( $drivers as $id => $driver ) {
			$run_inline = $this->should_run_inline( $driver, $mode, $deadline );

			if ( ! $run_inline ) {
				$queued = $this->enqueue_for( $driver, $request, $urls ) || $queued;
				$results[ $id ] = null;
				continue;
			}

			$result        = $this->run_inline( $driver, $request, $urls );
			$results[ $id ] = $result;

			// Whatever failed goes round again, and only that.
			if ( $result->has_failures() ) {
				$queued = $this->enqueue_failures( $driver, $request, $result ) || $queued;
			}
		}

		if ( $queued ) {
			Scheduler::kick();
		}

		return $results;
	}

	/**
	 * Whether this driver gets an inline attempt.
	 *
	 * @param DriverInterface $driver   Driver.
	 * @param string|null     $mode     Forced mode, or null to use settings.
	 * @param float           $deadline Inline budget deadline.
	 */
	private function should_run_inline( DriverInterface $driver, ?string $mode, float $deadline ): bool {
		if ( 'now' === $mode ) {
			return true;
		}

		if ( 'queue' === $mode ) {
			return false;
		}

		$configured = method_exists( $driver, 'dispatch_mode' ) ? $driver->dispatch_mode() : 'realtime';

		if ( 'queue' === $configured ) {
			return false;
		}

		// The breaker is open: this driver has been failing or timing out inline.
		if ( Cooldown::active( $driver->id() ) ) {
			return false;
		}

		/*
		 * Local drivers cost microseconds, so they run inline even on a front-end request: a
		 * visitor's comment should leave the local page cache immediately rather than waiting on
		 * cron. Only network-crossing drivers are governed by inline_on_frontend.
		 */
		if ( $driver->is_remote() && ! is_admin() && ! wp_doing_cron() && ! $this->is_cli() ) {
			if ( ! Options::flag( 'dispatch.inline_on_frontend', false ) ) {
				return false;
			}
		}

		// Bulk imports must not attempt hundreds of inline dispatches.
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return false;
		}

		// Out of budget: everything remaining is queued without an attempt.
		return microtime( true ) < $deadline;
	}

	/**
	 * Run a driver now, recording the outcome against its circuit breaker.
	 *
	 * @param DriverInterface $driver  Driver.
	 * @param PurgeRequest    $request The request.
	 * @param array<int, string> $urls URLs.
	 */
	private function run_inline( DriverInterface $driver, PurgeRequest $request, array $urls ): PurgeResult {
		try {
			$result = $request->is_purge_all()
				? $driver->purge_all()
				: $driver->purge_urls( $urls );
		} catch ( \Throwable $e ) {
			Cooldown::record_inline_failure( $driver->id() );

			return PurgeResult::fatal( $urls, $e->getMessage() );
		}

		if ( $result->has_failures() ) {
			Cooldown::record_inline_failure( $driver->id() );
		} else {
			Cooldown::record_inline_success( $driver->id() );
		}

		if ( null !== $result->get_retry_after() ) {
			Cooldown::open( $driver->id(), (int) $result->get_retry_after() );
		}

		return $result;
	}

	/**
	 * Queue the whole request for a driver.
	 *
	 * @param DriverInterface    $driver  Driver.
	 * @param PurgeRequest       $request The request.
	 * @param array<int, string> $urls    URLs.
	 * @return bool Whether anything was enqueued.
	 */
	private function enqueue_for( DriverInterface $driver, PurgeRequest $request, array $urls ): bool {
		if ( $request->is_purge_all() ) {
			return $this->queue->enqueue( $driver->id(), 'purge_all', [], $request->reason() ) > 0;
		}

		return $this->enqueue_urls( $driver, $urls, $request->reason() );
	}

	/**
	 * Queue only the URLs that failed inline.
	 *
	 * @param DriverInterface $driver  Driver.
	 * @param PurgeRequest    $request The request.
	 * @param PurgeResult     $result  Inline outcome.
	 */
	private function enqueue_failures( DriverInterface $driver, PurgeRequest $request, PurgeResult $result ): bool {
		// A whole-driver failure on a full purge has no URL list to narrow down.
		if ( $request->is_purge_all() ) {
			return $this->queue->enqueue( $driver->id(), 'purge_all', [], $request->reason() ) > 0;
		}

		return $this->enqueue_urls( $driver, $result->failed_urls(), $request->reason() );
	}

	/**
	 * Split URLs into driver-sized jobs.
	 *
	 * The chunk is the job on purpose. Cloudflare reports no per-URL status, so a call that
	 * fails fails all thirty of its URLs; making the chunk the unit of work means chunk four
	 * failing re-queues chunk four alone, instead of the donor behaviour of breaking out of the
	 * loop and silently dropping every chunk after the first failure.
	 *
	 * @param DriverInterface    $driver Driver.
	 * @param array<int, string> $urls   URLs.
	 * @param string             $reason Who asked.
	 */
	private function enqueue_urls( DriverInterface $driver, array $urls, string $reason ): bool {
		$urls = array_values( array_unique( $urls ) );

		if ( ! $urls ) {
			return false;
		}

		$enqueued = false;

		foreach ( array_chunk( $urls, max( 1, $driver->max_urls_per_job() ) ) as $chunk ) {
			if ( $this->queue->enqueue( $driver->id(), 'purge_urls', [ 'urls' => $chunk ], $reason ) > 0 ) {
				$enqueued = true;
			}
		}

		return $enqueued;
	}

	private function is_cli(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}
}
