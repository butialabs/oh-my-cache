<?php
/**
 * Outcome of a wizard step.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Wizard;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a step worked, and what to tell the operator.
 */
final class StepResult {

	/**
	 * @param bool                             $ok      Whether it succeeded.
	 * @param string                           $message Human summary.
	 * @param array<int, array<string, string>> $details Per-item outcomes, for the settings step.
	 */
	private function __construct(
		public readonly bool $ok,
		public readonly string $message = '',
		public readonly array $details = []
	) {}

	/**
	 * @param string                           $message Summary.
	 * @param array<int, array<string, string>> $details Per-item outcomes.
	 */
	public static function success( string $message = '', array $details = [] ): self {
		return new self( true, $message, $details );
	}

	/**
	 * @param string $message Why it failed.
	 */
	public static function failure( string $message ): self {
		return new self( false, $message );
	}
}
