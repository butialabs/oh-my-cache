<?php
/**
 * Lazy service locator.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache;

defined( 'ABSPATH' ) || exit;

/**
 * A deliberately tiny container.
 *
 * A DI library would be a dependency to ship, audit and keep compatible with whatever else the
 * site loads, for a plugin with fewer than a dozen services. Lazy factories keyed by name do
 * the one thing needed here: nothing is constructed until something asks for it, so a
 * front-end request that purges nothing builds nothing.
 */
final class Container {

	/** @var array<string, callable> */
	private array $factories = [];

	/** @var array<string, mixed> */
	private array $resolved = [];

	/**
	 * Register a factory.
	 *
	 * @param string   $id      Service id.
	 * @param callable $factory Receives the container, returns the service.
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->resolved[ $id ] );
	}

	/**
	 * Resolve a service, building it once.
	 *
	 * @param string $id Service id.
	 * @throws \RuntimeException When the id is unknown.
	 * @return mixed
	 */
	public function get( string $id ): mixed {
		if ( array_key_exists( $id, $this->resolved ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			// A programming error, never user input, but escaped anyway: the id is a short
			// identifier so escaping cannot distort it, and it keeps the check honest.
			throw new \RuntimeException( esc_html( sprintf( 'Unknown service: %s', $id ) ) );
		}

		$this->resolved[ $id ] = ( $this->factories[ $id ] )( $this );

		return $this->resolved[ $id ];
	}

	/**
	 * Whether a service is registered.
	 *
	 * @param string $id Service id.
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * Whether a service has already been built.
	 *
	 * @param string $id Service id.
	 */
	public function is_resolved( string $id ): bool {
		return array_key_exists( $id, $this->resolved );
	}
}
