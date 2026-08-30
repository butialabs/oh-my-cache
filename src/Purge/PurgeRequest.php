<?php
/**
 * What one request wants purged.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Purge;

use OhMyCache\Support\Options;
use OhMyCache\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Accumulates URLs during a request without touching a cache or the network.
 *
 * Hooks only ever add to this. Nothing is purged until shutdown, so a post save that fires six
 * hooks results in one deduplicated dispatch rather than six overlapping ones.
 */
final class PurgeRequest {

	/** @var array<string, true> Used as a set, so duplicates collapse for free. */
	private array $urls = [];

	private bool $purge_all = false;

	private string $reason = '';

	private bool $escalated = false;

	/**
	 * @param array<int, string> $urls   URLs to add.
	 * @param string             $reason Who asked, for the queue screen.
	 */
	public function add( array $urls, string $reason = '' ): void {
		if ( $this->purge_all ) {
			// Already purging everything; individual URLs are moot.
			return;
		}

		foreach ( $urls as $url ) {
			$normalized = Url::normalize( (string) $url );

			if ( '' !== $normalized ) {
				$this->urls[ $normalized ] = true;
			}
		}

		if ( '' === $this->reason && '' !== $reason ) {
			$this->reason = $reason;
		}

		$this->maybe_escalate();
	}

	/**
	 * Escalate to a full purge.
	 *
	 * @param string $reason Who asked.
	 */
	public function add_purge_all( string $reason = '' ): void {
		$this->purge_all = true;
		$this->urls      = [];

		if ( '' === $this->reason && '' !== $reason ) {
			$this->reason = $reason;
		}
	}

	/**
	 * Past a threshold, purging everything is cheaper and faster than enumerating.
	 *
	 * A bulk publish of five hundred posts would otherwise produce thousands of URLs and,
	 * on Cloudflare alone, over a hundred API calls where one will do.
	 */
	private function maybe_escalate(): void {
		$max = max( 1, (int) Options::get( 'purge.max_urls', 1000 ) );

		if ( count( $this->urls ) <= $max ) {
			return;
		}

		$this->purge_all = true;
		$this->escalated = true;
		$this->urls      = [];
	}

	/**
	 * @return array<int, string>
	 */
	public function urls(): array {
		return array_keys( $this->urls );
	}

	public function is_purge_all(): bool {
		return $this->purge_all;
	}

	public function was_escalated(): bool {
		return $this->escalated;
	}

	public function reason(): string {
		return '' === $this->reason ? 'unknown' : $this->reason;
	}

	public function is_empty(): bool {
		return ! $this->purge_all && ! $this->urls;
	}

	public function clear(): void {
		$this->urls      = [];
		$this->purge_all = false;
		$this->escalated = false;
		$this->reason    = '';
	}
}
