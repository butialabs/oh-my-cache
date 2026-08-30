<?php
/**
 * Receipt for a purge request.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Api;

use OhMyCache\Cache\PurgeResult;

defined( 'ABSPATH' ) || exit;

/**
 * What the caller gets back, so a purge is checkable rather than a shrug.
 *
 * Callers that do not care can ignore it entirely; callers that do (a deploy script, the
 * wizard's test purge) can ask what actually happened instead of assuming.
 */
final class PurgeTicket {

	/** @var array<int, int> */
	private array $job_ids = [];

	/** @var array<string, PurgeResult|null> */
	private array $inline = [];

	private bool $accepted = true;

	private string $rejection = '';

	/**
	 * A ticket for a request the plugin declined to act on.
	 *
	 * @param string $reason Why nothing happened.
	 */
	public static function rejected( string $reason ): self {
		$ticket            = new self();
		$ticket->accepted  = false;
		$ticket->rejection = $reason;

		return $ticket;
	}

	/**
	 * Record the per-driver inline outcomes.
	 *
	 * @param array<string, PurgeResult|null> $results Keyed by driver id; null means queued.
	 */
	public function with_inline( array $results ): self {
		$this->inline = $results;

		return $this;
	}

	/**
	 * Record the ids of jobs created.
	 *
	 * @param array<int, int> $ids Job ids.
	 */
	public function with_jobs( array $ids ): self {
		$this->job_ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		return $this;
	}

	/**
	 * Whether the plugin acted at all.
	 */
	public function accepted(): bool {
		return $this->accepted;
	}

	/**
	 * Why the request was declined, when it was.
	 */
	public function rejection(): string {
		return $this->rejection;
	}

	/**
	 * @return array<int, int>
	 */
	public function job_ids(): array {
		return $this->job_ids;
	}

	/**
	 * Inline results per driver. A null value means that driver was queued instead.
	 *
	 * @return array<string, PurgeResult|null>
	 */
	public function inline_results(): array {
		return $this->inline;
	}

	/**
	 * Whether any work was deferred to the queue.
	 */
	public function was_queued(): bool {
		if ( $this->job_ids ) {
			return true;
		}

		foreach ( $this->inline as $result ) {
			if ( null === $result ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether every driver that ran inline reported no failures.
	 *
	 * False does not mean the purge failed permanently: queued work may still succeed. It means
	 * it was not all done by the time this call returned.
	 */
	public function fully_purged_inline(): bool {
		if ( ! $this->accepted || $this->was_queued() ) {
			return false;
		}

		foreach ( $this->inline as $result ) {
			if ( $result instanceof PurgeResult && $result->has_failures() ) {
				return false;
			}
		}

		return [] !== $this->inline;
	}

	/**
	 * One-line summary across every driver.
	 */
	public function summary(): string {
		if ( ! $this->accepted ) {
			return $this->rejection;
		}

		$parts = [];

		foreach ( $this->inline as $driver => $result ) {
			$parts[] = $result instanceof PurgeResult
				? $driver . ': ' . $result->summary()
				: $driver . ': queued';
		}

		if ( $this->job_ids ) {
			$parts[] = sprintf(
				/* translators: %d: number of queued jobs. */
				_n( '%d job queued', '%d jobs queued', count( $this->job_ids ), 'oh-my-cache' ),
				count( $this->job_ids )
			);
		}

		return $parts ? implode( '; ' , $parts ) : __( 'nothing to do', 'oh-my-cache' );
	}
}
