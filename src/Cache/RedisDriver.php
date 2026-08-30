<?php
/**
 * Redis page cache driver.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cache;

use OhMyCache\Cache\Redis\Connection;
use OhMyCache\Support\Options;
use OhMyCache\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes nginx srcache keys out of Redis.
 */
final class RedisDriver extends AbstractDriver {

	/** Wall-clock budget for one wildcard sweep before it yields and resumes later. */
	private const SCAN_BUDGET_SECONDS = 10.0;

	private Connection $connection;

	/**
	 * @param Connection|null $connection Injectable for tests.
	 */
	public function __construct( ?Connection $connection = null ) {
		$this->connection = $connection ?? new Connection();
	}

	public function id(): string {
		return 'redis';
	}

	public function label(): string {
		return __( 'Redis page cache', 'oh-my-cache' );
	}

	public function supports_wildcards(): bool {
		return true;
	}

	/**
	 * A local socket call, so it always runs inline, even on a front-end request.
	 */
	public function is_remote(): bool {
		return false;
	}

	public function availability(): Availability {
		if ( ! Connection::extension_ok() ) {
			return Availability::unavailable(
				sprintf(
					/* translators: 1: installed version or "none", 2: minimum required version. */
					__( 'phpredis %1$s is installed; %2$s or newer is required.', 'oh-my-cache' ),
					Connection::extension_version() ?: __( 'none', 'oh-my-cache' ),
					Connection::MIN_EXTENSION
				),
				__( 'Install or upgrade the redis PHP extension. This plugin does not bundle a pure-PHP fallback.', 'oh-my-cache' )
			);
		}

		$interlock = $this->interlock();
		if ( null !== $interlock ) {
			return Availability::unavailable( $interlock );
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

		$redis = $this->connection->connect();

		if ( ! $redis instanceof \Redis ) {
			return PurgeResult::fatal( $urls, (string) $this->connection->error() );
		}

		$result = PurgeResult::make();
		$keys   = [];

		foreach ( $urls as $url ) {
			$key = $this->key_for( $url );

			if ( '' === $key ) {
				$result->skip( $url, __( 'Could not derive a cache key for this URL.', 'oh-my-cache' ) );
				continue;
			}

			$keys[ $url ] = $key;
		}

		if ( ! $keys ) {
			return $result;
		}

		try {
			$deleted = $this->delete_keys( $redis, array_values( $keys ) );
		} catch ( \Throwable $e ) {
			return PurgeResult::fatal( $urls, $e->getMessage() );
		}

		$index = 0;
		foreach ( $keys as $url => $key ) {
			// A zero return means the key was not there: not cached, not a failure.
			if ( ( $deleted[ $index ] ?? 0 ) > 0 ) {
				$result->succeed( $url );
			} else {
				$result->skip( $url, __( 'Not cached.', 'oh-my-cache' ) );
			}
			++$index;
		}

		return $result;
	}

	/**
	 * Purge everything under our prefix.
	 */
	public function purge_all(): PurgeResult {
		return $this->purge_pattern( $this->prefix() . '*' );
	}

	/**
	 * Wildcard purge via a bounded, resumable SCAN.
	 *
	 * Nginx Helper does this with an EVAL that calls redis.call('KEYS', ...) inside Lua. KEYS is
	 * O(N) over the entire keyspace and Lua runs single-threaded on the server, so on a large
	 * instance that blocks every other client for the duration. On a shared Redis it is an
	 * outage. SCAN with a cursor is the supported way to do this, and yielding a cursor lets a
	 * multi-million-key sweep finish across several jobs instead of timing out forever.
	 *
	 * @param string      $pattern Key pattern.
	 * @param string|null $cursor  Continuation cursor.
	 */
	public function purge_pattern( string $pattern, ?string $cursor = null ): PurgeResult {
		$redis = $this->connection->connect();

		if ( ! $redis instanceof \Redis ) {
			return PurgeResult::fatal( [], (string) $this->connection->error() );
		}

		$interlock = $this->interlock();
		if ( null !== $interlock ) {
			return PurgeResult::fatal( [], $interlock, false );
		}

		$result   = PurgeResult::make();
		$count    = max( 100, (int) Options::get( 'drivers.redis.scan_count', 1000 ) );
		$deadline = microtime( true ) + self::SCAN_BUDGET_SECONDS;
		$removed  = 0;

		// phpredis takes the cursor by reference; null means "start from the beginning".
		$iterator = ( null === $cursor || '' === $cursor || '0' === $cursor ) ? null : (int) $cursor;

		try {
			while ( true ) {
				$keys = $redis->scan( $iterator, $pattern, $count );

				if ( false === $keys ) {
					// Cursor exhausted: the sweep is complete.
					break;
				}

				if ( $keys ) {
					$removed += array_sum( $this->delete_keys( $redis, $keys ) );
				}

				if ( null === $iterator || 0 === $iterator ) {
					break;
				}

				if ( microtime( true ) >= $deadline ) {
					// Out of budget. Hand back where we stopped so a follow-up job continues.
					return $result
						->resume_from( (string) $iterator )
						->note(
							sprintf(
								/* translators: %d: number of keys deleted so far. */
								__( '%d keys deleted so far; continuing in a follow-up job.', 'oh-my-cache' ),
								$removed
							)
						);
				}
			}
		} catch ( \Throwable $e ) {
			return PurgeResult::fatal( [], $e->getMessage() );
		}

		/**
		 * Fires after a wildcard Redis purge completes.
		 *
		 * @param string $pattern Pattern swept.
		 * @param int    $removed Keys deleted.
		 */
		do_action( 'oh_my_cache_redis_purged_pattern', $pattern, $removed );

		return $result->note(
			sprintf(
				/* translators: %d: number of Redis keys deleted. */
				_n( '%d key deleted', '%d keys deleted', $removed, 'oh-my-cache' ),
				$removed
			)
		);
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Delete keys, preferring the non-blocking UNLINK.
	 *
	 * @param \Redis             $redis Handle.
	 * @param array<int, string> $keys  Keys.
	 * @return array<int, int> Per-key delete counts, in order.
	 */
	private function delete_keys( \Redis $redis, array $keys ): array {
		$use_unlink = $this->connection->supports_unlink();

		$pipeline = $redis->multi( \Redis::PIPELINE );

		foreach ( $keys as $key ) {
			if ( $use_unlink ) {
				$pipeline->unlink( $key );
			} else {
				$pipeline->del( $key );
			}
		}

		$results = $pipeline->exec();

		return is_array( $results ) ? array_map( 'intval', $results ) : [];
	}

	/**
	 * The Redis key nginx would have written for a URL.
	 *
	 * Derived from the same key template as the NGINX driver, so the two cannot disagree about
	 * what a given URL is called.
	 *
	 * @param string $url Absolute URL.
	 */
	public function key_for( string $url ): string {
		$template = (string) Options::get( 'drivers.nginx.cache_key', '$scheme$request_method$host$request_uri' );
		$key      = Url::cache_key( $url, $template );

		if ( '' === $key ) {
			return '';
		}

		$key = $this->prefix() . $key;

		/**
		 * Filters the Redis key about to be deleted.
		 *
		 * Appending a suffix here is the documented way to handle device-variant cache keys.
		 *
		 * @param string $key Full key including prefix.
		 * @param string $url URL being purged.
		 */
		return (string) apply_filters( 'oh_my_cache_redis_key', $key, $url );
	}

	/**
	 * Configured key prefix, honouring env, our constant and the legacy Nginx Helper one.
	 */
	public function prefix(): string {
		$external = Options::external( 'redis_prefix', [ 'RT_WP_NGINX_HELPER_REDIS_PREFIX' ] );

		if ( null !== $external && '' !== $external ) {
			return $external;
		}

		return (string) Options::get( 'drivers.redis.prefix', 'nginx-cache:' );
	}

	/**
	 * Refuse to run when a purge could take the object cache with it.
	 *
	 * This is the classic incident: the page cache and the persistent object cache share a
	 * Redis instance, someone purges with a wildcard, and the whole site's object cache
	 * evaporates along with it. An empty prefix makes it certain; a prefix that collides with
	 * the object cache's makes it likely.
	 *
	 * @return string|null Reason to refuse, or null when safe.
	 */
	private function interlock(): ?string {
		$prefix = $this->prefix();

		if ( '' === trim( $prefix ) ) {
			return __( 'The Redis key prefix is empty, which would let a purge delete every key in the database. Set a prefix that matches your nginx srcache configuration.', 'oh-my-cache' );
		}

		foreach ( [ 'WP_REDIS_PREFIX', 'WP_CACHE_KEY_SALT' ] as $constant ) {
			if ( ! defined( $constant ) ) {
				continue;
			}

			$other = (string) constant( $constant );

			if ( '' === $other ) {
				continue;
			}

			// Either direction is a collision: one prefix sweeping up the other's keys.
			if ( str_starts_with( $prefix, $other ) || str_starts_with( $other, $prefix ) ) {
				return sprintf(
					/* translators: 1: configured page cache prefix, 2: constant name, 3: object cache prefix. */
					__( 'The page cache prefix "%1$s" overlaps %2$s ("%3$s"). Purging would delete object cache keys too. Give the page cache its own prefix.', 'oh-my-cache' ),
					$prefix,
					$constant,
					$other
				);
			}
		}

		if ( defined( 'WP_REDIS_DATABASE' ) && (int) constant( 'WP_REDIS_DATABASE' ) === $this->connection->database() ) {
			return sprintf(
				/* translators: %d: Redis database index. */
				__( 'The page cache and the object cache are both on Redis database %d. Move one of them to a different database.', 'oh-my-cache' ),
				$this->connection->database()
			);
		}

		return null;
	}

	/**
	 * Expose the connection for the settings screen and the doctor.
	 */
	public function connection(): Connection {
		return $this->connection;
	}
}
