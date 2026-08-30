<?php
/**
 * Contract every cache backend implements.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * A purgeable cache layer.
 *
 * Third parties can register their own (Varnish, Fastly, LiteSpeed) via
 * oh_my_cache_register_driver() and inherit the queue, the backoff, the circuit breaker and a
 * row on the dashboard for free.
 */
interface DriverInterface {

	/**
	 * Stable machine id, stored in the jobs table: nginx, redis, cloudflare.
	 */
	public function id(): string;

	/**
	 * Human name for the admin screens.
	 */
	public function label(): string;

	/**
	 * Whether the operator has switched this driver on.
	 */
	public function is_enabled(): bool;

	/**
	 * Whether it can actually run, with a readable reason when it cannot.
	 */
	public function availability(): Availability;

	/**
	 * Seconds a single inline attempt may take before we give up and queue instead.
	 *
	 * Local drivers return 0: unlink() and UNLINK are filesystem and socket operations
	 * measured in microseconds, so they always run inline, even on a front-end request.
	 */
	public function inline_timeout(): float;

	/**
	 * True for drivers that cross the network, which are the ones inline_on_frontend governs.
	 */
	public function is_remote(): bool;

	/**
	 * True for a CDN sitting in front of the site, false for a cache on this server.
	 *
	 * Separate from is_remote(), which answers "does this cost a network round trip" and is true
	 * for NGINX in HTTP purge mode, still a local cache. This one says which half of the setup a
	 * driver belongs to, and is what the dashboard and the settings screen group by.
	 */
	public function is_cdn(): bool;

	/**
	 * Whether purge-by-pattern means anything here.
	 *
	 * False for NGINX in unlink mode: the cache key is an md5 hash, so there is no prefix to
	 * match. Saying so honestly is better than silently reporting success.
	 */
	public function supports_wildcards(): bool;

	/**
	 * How many URLs belong in one queued job.
	 *
	 * 30 for Cloudflare, because that is the API limit and the chunk is the unit of failure.
	 */
	public function max_urls_per_job(): int;

	/**
	 * Purge a set of URLs.
	 *
	 * @param array<int, string> $urls Absolute URLs.
	 */
	public function purge_urls( array $urls ): PurgeResult;

	/**
	 * Purge everything this driver owns.
	 */
	public function purge_all(): PurgeResult;

	/**
	 * Purge by wildcard pattern. Drivers without support should skip, not fail.
	 *
	 * @param string      $pattern Pattern, may contain *.
	 * @param string|null $cursor  Continuation cursor from a previous partial sweep.
	 */
	public function purge_pattern( string $pattern, ?string $cursor = null ): PurgeResult;
}
