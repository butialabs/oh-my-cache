<?php
/**
 * Step 4: prove it works, then get out of the way.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Wizard\Steps;

use OhMyCache\Admin\Doctor;
use OhMyCache\Support\Options;
use OhMyCache\Wizard\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a real purge and reports what actually happened.
 *
 * "Setup finished" and "clearing works" are different claims, and only the second one makes
 * edge caching safe to switch on. So this step does not congratulate anybody: it clears the
 * homepage for real, across every driver that is switched on, and says what each one did.
 *
 * Passing here is what unlocks a non-zero edge TTL.
 */
final class FinishStep extends AbstractStep {

	public function id(): string {
		return 'finish';
	}

	public function title(): string {
		return __( 'Test', 'oh-my-cache' );
	}

	public function is_complete(): bool {
		return (bool) Options::cf_state( 'test_purge_ok', false );
	}

	public function primary_label(): string {
		return __( 'Run the test', 'oh-my-cache' );
	}

	public function render(): void {
		$this->paragraph(
			__( 'Last step. This clears your homepage for real and reports what each driver did.', 'oh-my-cache' )
		);

		$results = ( new Doctor( $this->container ) )->run();

		echo '<table class="widefat striped oh-my-cache-table"><tbody>';

		foreach ( $results as $result ) {
			printf(
				'<tr class="oh-my-cache-check oh-my-cache-check--%s"><th scope="row">%s</th><td>%s%s</td></tr>',
				esc_attr( $result['status'] ),
				esc_html( $result['label'] ),
				esc_html( $result['detail'] ),
				'' === $result['hint'] ? '' : '<br /><em>' . esc_html( $result['hint'] ) . '</em>'
			);
		}

		echo '</tbody></table>';

		if ( $this->is_complete() ) {
			printf(
				'<div class="notice notice-success inline"><p>%s</p></div>',
				esc_html__( 'A test purge has already succeeded here, so the edge cache time can safely be raised on the Caching tab or in Settings.', 'oh-my-cache' )
			);
		}
	}

	/**
	 * @param array<string, mixed> $input Form input.
	 */
	public function apply( array $input ): StepResult {
		/*
		 * mode "now" runs synchronously and ignores the inline budget. The point here is to find
		 * out the answer, not to be quick about it.
		 */
		$ticket = oh_my_cache_purge_url(
			home_url( '/' ),
			[
				'mode'   => 'now',
				'reason' => 'setup: test purge',
			]
		);

		if ( ! $ticket->accepted() ) {
			Options::set_cf_state( [ 'test_purge_ok' => false ] );

			return StepResult::failure( $ticket->rejection() );
		}

		$failed  = [];
		$details = [];

		foreach ( $ticket->inline_results() as $driver => $result ) {
			if ( null === $result ) {
				$details[] = [
					'label'  => $driver,
					'status' => __( 'queued', 'oh-my-cache' ),
					'detail' => __( 'will run in the background', 'oh-my-cache' ),
				];
				continue;
			}

			$details[] = [
				'label'  => $driver,
				'status' => $result->has_failures() ? __( 'failed', 'oh-my-cache' ) : __( 'worked', 'oh-my-cache' ),
				'detail' => $result->summary(),
			];

			if ( $result->has_failures() ) {
				$failed[] = $driver;
			}
		}

		if ( ! $details ) {
			Options::set_cf_state( [ 'test_purge_ok' => false ] );

			return StepResult::failure( __( 'Nothing is switched on to clear. Go back to the Driver tab and pick your cache.', 'oh-my-cache' ) );
		}

		if ( $failed ) {
			Options::set_cf_state( [ 'test_purge_ok' => false ] );

			return StepResult::failure(
				sprintf(
					/* translators: %s: comma separated driver names. */
					__( 'Clearing failed on: %s. Fix that before raising the edge cache time.', 'oh-my-cache' ),
					implode( ', ', $failed )
				),
				$details
			);
		}

		Options::set_cf_state( [ 'test_purge_ok' => true ] );

		return StepResult::success(
			__( 'Everything cleared. You are set up.', 'oh-my-cache' ),
			$details
		);
	}
}
