<?php
/**
 * Whether a driver can run right now, and why not.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * A yes/no with a human reason attached.
 *
 * Drivers report unavailability instead of throwing, so a missing phpredis extension or an
 * unwritable cache directory surfaces as a readable line on the dashboard rather than a fatal
 * error on the front end.
 */
final class Availability {

	/**
	 * @param bool   $ok     Whether the driver can run.
	 * @param string $reason Why not, when it cannot. Empty when ok.
	 * @param string $hint   Optional remediation advice.
	 */
	private function __construct(
		public readonly bool $ok,
		public readonly string $reason = '',
		public readonly string $hint = ''
	) {}

	public static function ok(): self {
		return new self( true );
	}

	/**
	 * @param string $reason Why the driver cannot run.
	 * @param string $hint   How to fix it.
	 */
	public static function unavailable( string $reason, string $hint = '' ): self {
		return new self( false, $reason, $hint );
	}
}
