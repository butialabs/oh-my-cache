<?php
/**
 * Cloudflare edge cache driver.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cache;

use OhMyCache\Cloudflare\Client;
use OhMyCache\Cloudflare\Credentials;
use OhMyCache\Cloudflare\Exception\ApiException;
use OhMyCache\Cloudflare\Exception\AuthException;
use OhMyCache\Cloudflare\Exception\RateLimitException;
use OhMyCache\Cloudflare\Exception\ServerException;
use OhMyCache\Cloudflare\Exception\TransportException;
use OhMyCache\Support\Lock;
use OhMyCache\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Purges the Cloudflare edge.
 */
final class CloudflareDriver extends AbstractDriver {

	/**
	 * Cloudflare accepts 30 files per call on Free, Pro and Business; Enterprise raises it.
	 */
	private const CHUNK        = 30;
	private const CHUNK_ENTERPRISE = 500;

	/**
	 * URL not in this zone, and URL not purgeable. Both mean "nothing to do", not "failed".
	 */
	private const SKIP_CODES = [ 971, 1134 ];

	private Client $client;

	/**
	 * @param Client|null $client Injectable for tests.
	 */
	public function __construct( ?Client $client = null ) {
		$this->client = $client ?? new Client();
	}

	public function id(): string {
		return 'cloudflare';
	}

	/**
	 * Just the provider name. The category, "CDN edge cache", comes from wherever this is shown.
	 */
	public function label(): string {
		return __( 'Cloudflare', 'oh-my-cache' );
	}

	public function is_remote(): bool {
		return true;
	}

	public function is_cdn(): bool {
		return true;
	}

	public function inline_timeout(): float {
		return 5.0;
	}

	/**
	 * Prefix purging exists, but only on Enterprise.
	 */
	public function supports_wildcards(): bool {
		return 'enterprise' === strtolower( (string) Options::cf_state( 'plan', '' ) );
	}

	public function max_urls_per_job(): int {
		return 'enterprise' === strtolower( (string) Options::cf_state( 'plan', '' ) )
			? self::CHUNK_ENTERPRISE
			: self::CHUNK;
	}

	public function availability(): Availability {
		if ( ! Credentials::configured() ) {
			return Availability::unavailable(
				__( 'No Cloudflare credentials are configured.', 'oh-my-cache' ),
				__( 'Run the setup wizard, or define OH_MY_CACHE_CF_API_TOKEN.', 'oh-my-cache' )
			);
		}

		if ( '' === Credentials::zone_id() ) {
			return Availability::unavailable(
				__( 'No Cloudflare zone has been resolved for this site.', 'oh-my-cache' ),
				__( 'Run the setup wizard, or define OH_MY_CACHE_CF_ZONE_ID.', 'oh-my-cache' )
			);
		}

		if ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL ) {
			$allowed = defined( 'WP_ACCESSIBLE_HOSTS' ) ? (string) constant( 'WP_ACCESSIBLE_HOSTS' ) : '';

			if ( ! str_contains( $allowed, 'api.cloudflare.com' ) ) {
				return Availability::unavailable(
					__( 'WP_HTTP_BLOCK_EXTERNAL is on and api.cloudflare.com is not in WP_ACCESSIBLE_HOSTS.', 'oh-my-cache' ),
					__( 'Add api.cloudflare.com to WP_ACCESSIBLE_HOSTS in wp-config.php.', 'oh-my-cache' )
				);
			}
		}

		return Availability::ok();
	}

	/**
	 * Purge a set of URLs.
	 *
	 * @param array<int, string> $urls Absolute URLs.
	 */
	public function purge_urls( array $urls ): PurgeResult {
		if ( ! $urls ) {
			return PurgeResult::make();
		}

		$zone = Credentials::zone_id();

		if ( '' === $zone ) {
			return PurgeResult::fatal( $urls, __( 'No Cloudflare zone is configured.', 'oh-my-cache' ), false );
		}

		$result = PurgeResult::make();

		/*
		 * The caller has normally already chunked this to max_urls_per_job(), because the chunk
		 * is the unit of work. Chunking again here keeps a direct API call honest.
		 */
		foreach ( array_chunk( $urls, $this->max_urls_per_job() ) as $chunk ) {
			$result->merge( $this->purge_chunk( $zone, $chunk ) );
		}

		return $result;
	}

	/**
	 * Purge the whole zone.
	 */
	public function purge_all(): PurgeResult {
		$zone = Credentials::zone_id();

		if ( '' === $zone ) {
			return PurgeResult::fatal( [], __( 'No Cloudflare zone is configured.', 'oh-my-cache' ), false );
		}

		/*
		 * purge_everything is rate limited hard. A theme switch that fires several site-wide
		 * purges in one request should make one API call, not five.
		 */
		if ( ! Lock::acquire( 'cf_purge_all_' . $zone, 60 ) ) {
			return PurgeResult::make()->note( __( 'A full Cloudflare purge already ran moments ago; skipping.', 'oh-my-cache' ) );
		}

		try {
			$this->client->purge_cache( $zone, [ 'purge_everything' => true ] );
		} catch ( ApiException $e ) {
			return $this->from_exception( [], $e );
		}

		return PurgeResult::make()->note( __( 'Cloudflare cache purged.', 'oh-my-cache' ) );
	}

	/**
	 * Prefix purge, Enterprise only.
	 *
	 * @param string      $pattern Pattern.
	 * @param string|null $cursor  Unused.
	 */
	public function purge_pattern( string $pattern, ?string $cursor = null ): PurgeResult {
		if ( ! $this->supports_wildcards() ) {
			return PurgeResult::make()->skip(
				$pattern,
				__( 'Cloudflare only supports prefix purging on Enterprise plans.', 'oh-my-cache' )
			);
		}

		$zone = Credentials::zone_id();

		try {
			$this->client->purge_cache( $zone, [ 'prefixes' => [ rtrim( $pattern, '*' ) ] ] );
		} catch ( ApiException $e ) {
			return $this->from_exception( [], $e );
		}

		return PurgeResult::make()->note( __( 'Prefix purged.', 'oh-my-cache' ) );
	}

	/* --------------------------------------------------------------------- */

	/**
	 * One API call, covering one chunk.
	 *
	 * Cloudflare reports no per-URL status, so the whole chunk shares one fate. That is exactly
	 * why the chunk is the queued job: chunk four failing re-queues chunk four and nothing else.
	 * App for Cloudflare loops chunks and breaks on the first failure, silently discarding every
	 * chunk after it.
	 *
	 * @param string             $zone  Zone id.
	 * @param array<int, string> $chunk Up to 30 URLs.
	 */
	private function purge_chunk( string $zone, array $chunk ): PurgeResult {
		$result = PurgeResult::make();

		try {
			$this->client->purge_cache( $zone, [ 'files' => array_values( $chunk ) ] );
		} catch ( ApiException $e ) {
			return $this->from_exception( $chunk, $e );
		}

		foreach ( $chunk as $url ) {
			$result->succeed( $url );
		}

		return $result;
	}

	/**
	 * Translate an API exception into a result.
	 *
	 * @param array<int, string> $urls URLs that were in flight.
	 * @param ApiException       $e    The exception.
	 */
	private function from_exception( array $urls, ApiException $e ): PurgeResult {
		$codes = $e->error_codes();

		/*
		 * Order matters here, and getting it wrong is how a purge disappears.
		 *
		 * A rate limit, an auth failure or a server error is about the request, not about the
		 * URLs, and it must never be downgraded to "nothing to purge" just because the body
		 * happened to carry a code that also appears in SKIP_CODES. Downgrading a 429 in
		 * particular would drop the work AND leave the circuit breaker closed, so the next
		 * forty jobs would each go and earn their own 429.
		 */
		$is_about_the_request = $e instanceof RateLimitException
			|| $e instanceof AuthException
			|| $e instanceof ServerException
			|| $e instanceof TransportException;

		/*
		 * 971 and 1134 mean the URL is not in this zone or is not purgeable, almost always
		 * because a subdomain is not proxied or home_url() disagrees with the zone. Nothing is
		 * broken and retrying will not help, so these are skips. Surfacing the count matters:
		 * silently swallowing them, as the donor does, lets someone believe purging works when
		 * it has never touched a single URL.
		 */
		if ( ! $is_about_the_request && $codes && ! array_diff( $codes, self::SKIP_CODES ) ) {
			$result = PurgeResult::make();

			foreach ( $urls as $url ) {
				$result->skip( $url, __( 'Not in this Cloudflare zone, or not purgeable.', 'oh-my-cache' ) );
			}

			return $result->note(
				__( 'Cloudflare does not recognise these URLs as belonging to the configured zone. Check that the hostname is proxied (orange cloud).', 'oh-my-cache' )
			);
		}

		return PurgeResult::fatal( $urls, $e->getMessage(), $e->is_retryable() )
			->retry_after( $e->retry_after() );
	}
}
