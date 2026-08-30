<?php
/**
 * Setup guide.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Wizard;

use OhMyCache\Admin\Menu;
use OhMyCache\Container;
use OhMyCache\Wizard\Steps\CdnStep;
use OhMyCache\Wizard\Steps\DriverStep;
use OhMyCache\Wizard\Steps\FinishStep;
use OhMyCache\Wizard\Steps\RulesStep;

defined( 'ABSPATH' ) || exit;

/**
 * Four tabs: pick your driver, connect your CDN, choose what it caches, prove it works.
 *
 * Moving forward always goes through the step's own test, so nobody reaches the end with a
 * configuration that was never verified. POST then redirect then GET, so a refresh after
 * applying a step cannot apply it twice.
 */
final class Wizard {

	public const ACTION = 'oh_my_cache_wizard';

	/** @var array<int, StepInterface> */
	private array $steps;

	public function __construct( private readonly Container $container ) {
		$this->steps = [
			new DriverStep( $container ),
			new CdnStep( $container ),
			new RulesStep( $container ),
			new FinishStep( $container ),
		];
	}

	/**
	 * @return array<int, StepInterface>
	 */
	public function steps(): array {
		return $this->steps;
	}

	/**
	 * Act on a submitted step, then redirect.
	 *
	 * Runs on admin_init, never while rendering. Every branch below ends in a redirect, and by
	 * the time a page callback runs, admin-header.php has already sent output, so a redirect
	 * from there is a "headers already sent" warning and a dead page.
	 */
	public function handle_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( Menu::SLUG_SETUP !== $page ) {
			return;
		}

		$this->handle_post();
	}

	public function render(): void {
		$index = $this->current_index();
		$step  = $this->steps[ $index ];

		echo '<div class="wrap omc omc-wizard">';
		printf( '<h1>%s</h1>', esc_html__( 'Set up Oh My Cache!', 'oh-my-cache' ) );

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Four short steps. Each one is checked before you move on, so nothing here quietly fails later.', 'oh-my-cache' )
		);

		$this->render_nav( $index );
		$this->render_notice();

		echo '<form method="post" class="omc-wizard-form">';
		wp_nonce_field( self::ACTION );
		printf( '<input type="hidden" name="step" value="%s" />', esc_attr( $step->id() ) );

		printf( '<h2>%s</h2>', esc_html( $step->title() ) );

		$step->render();

		echo '<p class="submit">';

		printf(
			'<button type="submit" name="do" value="apply" class="button button-primary">%s</button> ',
			esc_html( $step->primary_label() )
		);

		if ( $step->can_skip() && $index < count( $this->steps ) - 1 ) {
			printf(
				'<button type="submit" name="do" value="skip" class="button">%s</button> ',
				esc_html__( 'Skip this step', 'oh-my-cache' )
			);
		}

		if ( $step->is_complete() ) {
			printf(
				'<button type="submit" name="do" value="revert" class="button button-link-delete">%s</button>',
				esc_html__( 'Undo this step', 'oh-my-cache' )
			);
		}

		echo '</p></form></div>';
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Standard WordPress tab navigation, with a tick on the steps already done.
	 *
	 * @param int $current Current step index.
	 */
	private function render_nav( int $current ): void {
		printf(
			'<nav class="nav-tab-wrapper wp-clearfix" aria-label="%s">',
			esc_attr__( 'Setup steps', 'oh-my-cache' )
		);

		foreach ( $this->steps as $index => $step ) {
			$classes = 'nav-tab';

			if ( $index === $current ) {
				$classes .= ' nav-tab-active';
			}

			printf(
				'<a href="%s" class="%s">%s%s</a>',
				esc_url(
					add_query_arg(
						[
							'page' => Menu::SLUG_SETUP,
							'step' => $step->id(),
						],
						admin_url( 'admin.php' )
					)
				),
				esc_attr( $classes ),
				$step->is_complete() ? '<span class="dashicons dashicons-yes omc-tab-done" aria-hidden="true"></span> ' : '',
				esc_html( $step->title() )
			);
		}

		echo '</nav>';
	}

	private function render_notice(): void {
		$notice = get_transient( 'oh_my_cache_wizard_notice' );

		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_transient( 'oh_my_cache_wizard_notice' );

		printf(
			'<div class="notice notice-%s"><p>%s</p>',
			esc_attr( (string) ( $notice['type'] ?? 'info' ) ),
			esc_html( (string) ( $notice['message'] ?? '' ) )
		);

		$details = (array) ( $notice['details'] ?? [] );

		if ( $details ) {
			echo '<ul class="omc-result-list">';

			foreach ( $details as $detail ) {
				printf(
					'<li><strong>%s</strong> %s %s</li>',
					esc_html( (string) ( $detail['label'] ?? '' ) ),
					esc_html( (string) ( $detail['status'] ?? '' ) ),
					esc_html( (string) ( $detail['detail'] ?? '' ) )
				);
			}

			echo '</ul>';
		}

		echo '</div>';
	}

	private function handle_post(): void {
		if ( empty( $_POST['step'] ) ) {
			return;
		}

		check_admin_referer( self::ACTION );

		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run setup.', 'oh-my-cache' ), '', [ 'response' => 403 ] );
		}

		$id    = sanitize_key( wp_unslash( $_POST['step'] ) );
		$do    = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : 'apply';
		$index = $this->index_of( $id );

		if ( $index < 0 ) {
			return;
		}

		$step = $this->steps[ $index ];
		$last = count( $this->steps ) - 1;

		if ( 'skip' === $do ) {
			$this->redirect( min( $index + 1, $last ) );
		}

		if ( 'revert' === $do ) {
			$this->flash( $step->revert() );
			$this->redirect( $index );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$input  = $this->sanitize_input( (array) wp_unslash( $_POST ) );
		$result = $step->apply( $input );

		$this->flash( $result );

		if ( ! $result->ok ) {
			$this->redirect( $index );
		}

		/*
		 * The last step passing means setup is genuinely done, verified rather than assumed, so
		 * there is nothing left to look at here. Send them somewhere useful.
		 */
		if ( $index === $last ) {
			wp_safe_redirect(
				add_query_arg(
					[
						'page'       => Menu::SLUG,
						'omc-notice' => $result->message,
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$this->redirect( $index + 1 );
	}

	/**
	 * @param StepResult $result Outcome to show after the redirect.
	 */
	private function flash( StepResult $result ): void {
		set_transient(
			'oh_my_cache_wizard_notice',
			[
				'type'    => $result->ok ? 'success' : 'error',
				'message' => $result->message,
				'details' => $result->details,
			],
			60
		);
	}

	/**
	 * Shallow sanitisation; each step re-validates what it actually uses.
	 *
	 * @param array<string, mixed> $input Raw POST.
	 * @return array<string, mixed>
	 */
	private function sanitize_input( array $input ): array {
		unset( $input['_wpnonce'], $input['_wp_http_referer'] );

		$clean = [];

		foreach ( $input as $key => $value ) {
			if ( is_array( $value ) ) {
				$clean[ sanitize_key( (string) $key ) ] = array_map( 'sanitize_text_field', $value );
				continue;
			}

			$clean[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
		}

		return $clean;
	}

	/**
	 * @param int $index Step index.
	 */
	private function redirect( int $index ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'page' => Menu::SLUG_SETUP,
					'step' => $this->steps[ $index ]->id(),
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function current_index(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';

		$index = $this->index_of( $id );

		return $index >= 0 ? $index : 0;
	}

	/**
	 * @param string $id Step id.
	 */
	private function index_of( string $id ): int {
		foreach ( $this->steps as $index => $step ) {
			if ( $step->id() === $id ) {
				return $index;
			}
		}

		return -1;
	}
}
