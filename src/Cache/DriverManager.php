<?php
/**
 * Driver registry and ordering.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the drivers and, crucially, their order.
 *
 * nginx, then redis, then cloudflare. Purging the local cache before the edge matters: when
 * Cloudflare's next MISS reaches the origin it must find fresh HTML, not the stale copy that
 * is still sitting in the local page cache. Reversing this order produces a cache that looks
 * purged and serves stale content anyway.
 */
final class DriverManager {

	/** @var array<string, DriverInterface> */
	private array $drivers = [];

	/** Built-in order. Third-party drivers append after these. */
	private const ORDER = [ 'nginx', 'redis', 'cloudflare' ];

	/**
	 * Register a driver, replacing any existing one with the same id.
	 *
	 * @param DriverInterface $driver Driver.
	 */
	public function register( DriverInterface $driver ): void {
		$this->drivers[ $driver->id() ] = $driver;
	}

	/**
	 * Every registered driver, in dispatch order.
	 *
	 * @return array<string, DriverInterface>
	 */
	public function all(): array {
		$ordered = [];

		foreach ( self::ORDER as $id ) {
			if ( isset( $this->drivers[ $id ] ) ) {
				$ordered[ $id ] = $this->drivers[ $id ];
			}
		}

		foreach ( $this->drivers as $id => $driver ) {
			if ( ! isset( $ordered[ $id ] ) ) {
				$ordered[ $id ] = $driver;
			}
		}

		return $ordered;
	}

	/**
	 * Drivers the operator switched on that can actually run, in dispatch order.
	 *
	 * @return array<string, DriverInterface>
	 */
	public function enabled(): array {
		return array_filter(
			$this->all(),
			static fn ( DriverInterface $driver ): bool => $driver->is_enabled() && $driver->availability()->ok
		);
	}

	/**
	 * Caches running on this server, in dispatch order.
	 *
	 * @return array<string, DriverInterface>
	 */
	public function local(): array {
		return array_filter(
			$this->all(),
			static fn ( DriverInterface $driver ): bool => ! $driver->is_cdn()
		);
	}

	/**
	 * CDNs sitting in front of the site, in dispatch order.
	 *
	 * @return array<string, DriverInterface>
	 */
	public function cdn(): array {
		return array_filter(
			$this->all(),
			static fn ( DriverInterface $driver ): bool => $driver->is_cdn()
		);
	}

	/**
	 * The one local driver currently switched on, if any.
	 *
	 * At most one, enforced by the settings screen: a site has a single page cache in front of PHP.
	 */
	public function active_local(): ?DriverInterface {
		foreach ( $this->local() as $driver ) {
			if ( $driver->is_enabled() ) {
				return $driver;
			}
		}

		return null;
	}

	/**
	 * The CDN currently switched on, if any.
	 */
	public function active_cdn(): ?DriverInterface {
		foreach ( $this->cdn() as $driver ) {
			if ( $driver->is_enabled() ) {
				return $driver;
			}
		}

		return null;
	}

	/**
	 * One driver by id, whether or not it is enabled.
	 *
	 * @param string $id Driver id.
	 */
	public function get( string $id ): ?DriverInterface {
		return $this->drivers[ $id ] ?? null;
	}

	/**
	 * Whether a driver id is known.
	 *
	 * @param string $id Driver id.
	 */
	public function has( string $id ): bool {
		return isset( $this->drivers[ $id ] );
	}

	/**
	 * Resolve a caller-supplied driver list to enabled driver objects.
	 *
	 * Accepts ['all'] or a list of ids; unknown or disabled ids are dropped silently, because
	 * an API caller asking for a driver this site does not run should be a no-op, not an error.
	 *
	 * @param array<int, string> $ids Driver ids, or ['all'].
	 * @return array<string, DriverInterface>
	 */
	public function resolve( array $ids ): array {
		if ( ! $ids || in_array( 'all', $ids, true ) ) {
			return $this->enabled();
		}

		return array_filter(
			$this->enabled(),
			static fn ( DriverInterface $driver ): bool => in_array( $driver->id(), $ids, true )
		);
	}
}
