<?php
/**
 * Outcome of one purge attempt.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * What a driver reports back, and the reason partial retry is possible at all.
 *
 * The load-bearing distinction is between failed and skipped.
 *
 * Nginx Helper logs a 404 from ngx_cache_purge, an absent cache file, and a Redis DEL that
 * returns 0 all as ERROR. Every one of those is the normal case: the URL simply was not
 * cached. Treating them as failures would fill the retry queue with jobs that can never
 * succeed, and they would grind through six attempts each before dead-lettering. So:
 *
 *   succeeded - it was cached and now it is not
 *   skipped   - it was not cached, or this driver cannot express this purge. Not an error.
 *   failed    - we could not tell. Only these are re-queued.
 */
final class PurgeResult {

	/** @var array<int, string> */
	private array $succeeded = [];

	/** @var array<string, string> URL to reason. */
	private array $failed = [];

	/** @var array<string, string> URL to reason. */
	private array $skipped = [];

	private bool $fatal = false;

	private bool $retryable = true;

	private ?int $retry_after = null;

	private ?string $resume_cursor = null;

	private string $note = '';

	public static function make(): self {
		return new self();
	}

	/**
	 * Whole-driver failure: Redis refusing connections, Cloudflare answering 401.
	 *
	 * Every input URL is moved into failed, because none of them can be assumed purged.
	 *
	 * @param array<int, string> $urls      URLs that were in flight.
	 * @param string             $reason    Failure message.
	 * @param bool               $retryable False sends the job straight to dead-letter.
	 */
	public static function fatal( array $urls, string $reason, bool $retryable = true ): self {
		$result            = new self();
		$result->fatal     = true;
		$result->retryable = $retryable;

		foreach ( $urls as $url ) {
			$result->failed[ $url ] = $reason;
		}

		if ( ! $urls ) {
			$result->note = $reason;
		}

		return $result;
	}

	/**
	 * @param string $url Purged URL.
	 */
	public function succeed( string $url ): self {
		$this->succeeded[] = $url;

		return $this;
	}

	/**
	 * @param string $url    URL.
	 * @param string $reason Why it failed.
	 */
	public function fail( string $url, string $reason ): self {
		$this->failed[ $url ] = $reason;

		return $this;
	}

	/**
	 * @param string $url    URL.
	 * @param string $reason Why it was skipped. Not an error.
	 */
	public function skip( string $url, string $reason ): self {
		$this->skipped[ $url ] = $reason;

		return $this;
	}

	/**
	 * Mark the result non-retryable, e.g. a malformed request Cloudflare will always reject.
	 */
	public function not_retryable(): self {
		$this->retryable = false;

		return $this;
	}

	/**
	 * Server-requested delay, from a 429 Retry-After.
	 *
	 * @param int|null $seconds Seconds.
	 */
	public function retry_after( ?int $seconds ): self {
		$this->retry_after = $seconds;

		return $this;
	}

	/**
	 * Continuation point for a bounded Redis SCAN sweep.
	 *
	 * @param string|null $cursor Cursor.
	 */
	public function resume_from( ?string $cursor ): self {
		$this->resume_cursor = $cursor;

		return $this;
	}

	/**
	 * Free-form addition to the summary.
	 *
	 * @param string $note Note.
	 */
	public function note( string $note ): self {
		$this->note = $note;

		return $this;
	}

	/** @return array<int, string> */
	public function succeeded(): array {
		return $this->succeeded;
	}

	/** @return array<string, string> */
	public function failed(): array {
		return $this->failed;
	}

	/** @return array<string, string> */
	public function skipped(): array {
		return $this->skipped;
	}

	public function is_fatal(): bool {
		return $this->fatal;
	}

	public function is_retryable(): bool {
		return $this->retryable;
	}

	public function has_failures(): bool {
		return [] !== $this->failed;
	}

	public function get_retry_after(): ?int {
		return $this->retry_after;
	}

	public function resume_cursor(): ?string {
		return $this->resume_cursor;
	}

	/**
	 * URLs that need re-queueing, and only those.
	 *
	 * @return array<int, string>
	 */
	public function failed_urls(): array {
		return array_keys( $this->failed );
	}

	/**
	 * First failure message, for the last_error column.
	 */
	public function first_error(): string {
		foreach ( $this->failed as $reason ) {
			return $reason;
		}

		return $this->note;
	}

	/**
	 * Merge another result into this one, for a driver that works in chunks.
	 *
	 * @param PurgeResult $other Result to absorb.
	 */
	public function merge( self $other ): self {
		$this->succeeded = array_merge( $this->succeeded, $other->succeeded );
		$this->failed    = $this->failed + $other->failed;
		$this->skipped   = $this->skipped + $other->skipped;

		$this->fatal     = $this->fatal || $other->fatal;
		$this->retryable = $this->retryable && $other->retryable;

		if ( null !== $other->retry_after ) {
			$this->retry_after = max( (int) $this->retry_after, $other->retry_after );
		}

		if ( null !== $other->resume_cursor ) {
			$this->resume_cursor = $other->resume_cursor;
		}

		if ( '' !== $other->note ) {
			$this->note = '' === $this->note ? $other->note : $this->note . '; ' . $other->note;
		}

		return $this;
	}

	/**
	 * One-line human summary, stored on the job so the queue screen can answer
	 * "did my purge actually work?".
	 */
	public function summary(): string {
		$parts = [];

		if ( $this->succeeded ) {
			$parts[] = sprintf(
				/* translators: %d: number of URLs purged. */
				_n( '%d purged', '%d purged', count( $this->succeeded ), 'oh-my-cache' ),
				count( $this->succeeded )
			);
		}

		if ( $this->skipped ) {
			$parts[] = sprintf(
				/* translators: %d: number of URLs that were not cached. */
				_n( '%d not cached', '%d not cached', count( $this->skipped ), 'oh-my-cache' ),
				count( $this->skipped )
			);
		}

		if ( $this->failed ) {
			$parts[] = sprintf(
				/* translators: %d: number of URLs that failed to purge. */
				_n( '%d failed', '%d failed', count( $this->failed ), 'oh-my-cache' ),
				count( $this->failed )
			);
		}

		/*
		 * A purge_all or a wildcard sweep has no per-URL tally, only a note. Reporting
		 * "nothing to do. 251 keys deleted" would be contradictory and alarming, so when the note
		 * is all we know, the note is the whole summary.
		 */
		if ( ! $parts ) {
			return '' !== $this->note ? $this->note : __( 'nothing to do', 'oh-my-cache' );
		}

		$summary = implode( ', ', $parts );

		if ( '' !== $this->note ) {
			$summary .= '. ' . $this->note;
		}

		if ( $this->failed ) {
			$summary .= '. ' . $this->first_error();
		}

		return $summary;
	}
}
