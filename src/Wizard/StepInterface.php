<?php
/**
 * Wizard step contract.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Wizard;

defined( 'ABSPATH' ) || exit;

/**
 * One tab of setup.
 *
 * Advancing always goes through apply(), which saves and then proves the configuration actually
 * works. A step that cannot be verified does not let you past it, because setup that "completed"
 * while the cache never clears is worse than no setup at all: it looks finished.
 *
 * apply() must be idempotent and is_complete() must be a read-only probe. This is something
 * people re-run, to change a setting or to recover from a half-finished attempt, and running it
 * twice must not create two of anything.
 */
interface StepInterface {

	/**
	 * Machine id, used in the URL.
	 */
	public function id(): string;

	/**
	 * Tab label.
	 */
	public function title(): string;

	/**
	 * Whether this step is already done. Read-only: it must not change anything.
	 */
	public function is_complete(): bool;

	/**
	 * Render the step body.
	 */
	public function render(): void;

	/**
	 * Save what was entered, then verify it. Advancing depends on a passing result.
	 *
	 * @param array<string, mixed> $input Sanitised form input.
	 */
	public function apply( array $input ): StepResult;

	/**
	 * Undo whatever apply() did.
	 */
	public function revert(): StepResult;

	/**
	 * Label for the primary button.
	 */
	public function primary_label(): string;

	/**
	 * Whether this step may be skipped. Only genuinely optional steps say yes.
	 */
	public function can_skip(): bool;
}
