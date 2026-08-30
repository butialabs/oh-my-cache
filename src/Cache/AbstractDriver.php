<?php
/**
 * Shared driver behaviour.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cache;

use OhMyCache\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Defaults every built-in driver shares.
 */
abstract class AbstractDriver implements DriverInterface {

	public function is_enabled(): bool {
		return Options::flag( 'drivers.' . $this->id() . '.enabled', false );
	}

	public function availability(): Availability {
		return Availability::ok();
	}

	public function inline_timeout(): float {
		return 0.0;
	}

	public function is_remote(): bool {
		return false;
	}

	public function is_cdn(): bool {
		return false;
	}

	public function supports_wildcards(): bool {
		return false;
	}

	public function max_urls_per_job(): int {
		return 200;
	}

	/**
	 * Drivers that cannot express a wildcard purge say so, rather than reporting a success
	 * they did not achieve.
	 *
	 * @param string      $pattern Pattern.
	 * @param string|null $cursor  Unused here.
	 */
	public function purge_pattern( string $pattern, ?string $cursor = null ): PurgeResult {
		return PurgeResult::make()->skip(
			$pattern,
			sprintf(
				/* translators: %s: driver label. */
				__( '%s cannot purge by pattern.', 'oh-my-cache' ),
				$this->label()
			)
		);
	}

	/**
	 * Per-driver dispatch preference, falling back to the global mode.
	 *
	 * Returns 'realtime' or 'queue'.
	 */
	public function dispatch_mode(): string {
		$own = (string) Options::get( 'drivers.' . $this->id() . '.dispatch', 'inherit' );

		if ( 'inherit' !== $own && in_array( $own, [ 'realtime', 'queue' ], true ) ) {
			return $own;
		}

		$global = (string) Options::get( 'dispatch.mode', 'realtime' );

		return in_array( $global, [ 'realtime', 'queue' ], true ) ? $global : 'realtime';
	}

	/**
	 * Read a driver setting.
	 *
	 * @param string $key           Setting key under drivers.<id>.
	 * @param mixed  $default_value Fallback.
	 * @return mixed
	 */
	protected function setting( string $key, mixed $default_value = null ): mixed {
		return Options::get( 'drivers.' . $this->id() . '.' . $key, $default_value );
	}
}
