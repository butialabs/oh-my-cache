<?php
/**
 * Queue screen.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Admin;

use OhMyCache\Container;
use OhMyCache\Queue\QueueRepository;
use OhMyCache\Queue\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the list table and handles its bulk actions.
 */
final class QueuePage {

	public const ACTION = 'oh_my_cache_queue';

	/**
	 * The nonce action WP_List_Table generates, derived from the `plural` argument passed to
	 * QueueListTable's constructor. Keep the two in step.
	 */
	public const BULK_NONCE = 'bulk-jobs';

	public function __construct( private readonly Container $container ) {}

	/**
	 * Act on a bulk submission, then redirect.
	 *
	 * Runs on admin_init, not while rendering, for two reasons. Rendering happens after
	 * admin-header.php has already fired admin_notices, so acting there leaves the "jobs gave
	 * up" banner describing a state that is one action out of date. And without the redirect,
	 * refreshing the page re-submits the POST and runs the action again.
	 */
	public function handle_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( Menu::SLUG_QUEUE !== $page ) {
			return;
		}

		/** @var QueueRepository $repository */
		$repository = $this->container->get( 'queue' );

		$notice = $this->handle_bulk( $repository );

		if ( '' === $notice ) {
			return;
		}

		$args = [
			'page'       => Menu::SLUG_QUEUE,
			'omc-notice' => $notice,
		];

		// Which view the operator was looking at, so the redirect returns them to it.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		if ( '' !== $status ) {
			$args['status'] = $status;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render(): void {
		/** @var QueueRepository $repository */
		$repository = $this->container->get( 'queue' );

		// A message we put in the URL ourselves after a redirect; displayed, never acted on.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['omc-notice'] ) ? sanitize_text_field( wp_unslash( $_GET['omc-notice'] ) ) : '';

		$table = new QueueListTable( $repository );
		$table->prepare_items();

		echo '<div class="wrap omc">';
		printf( '<h1>%s</h1>', esc_html__( 'Purge queue', 'oh-my-cache' ) );

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'An empty queue is the healthy state. Anything listed here either failed or was put off because a driver was slow.', 'oh-my-cache' )
		);

		if ( '' !== $notice ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $notice )
			);
		}

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( Menu::SLUG_QUEUE ) );

		// Carry the current view through the search form so searching does not lose the filter.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		if ( '' !== $status ) {
			printf( '<input type="hidden" name="status" value="%s" />', esc_attr( $status ) );
		}

		$table->views();
		$table->search_box( __( 'Search URLs', 'oh-my-cache' ), 'omc-search' );
		echo '</form>';

		/*
		 * No wp_nonce_field() of our own here. WP_List_Table::display_tablenav() emits its own
		 * `bulk-{plural}` nonce into this same form, and two inputs named _wpnonce means PHP
		 * keeps the last one, so a nonce of ours would simply be discarded and every bulk action
		 * would fail with "this link has expired". Verify the one the list table already owns.
		 */
		echo '<form method="post">';
		$table->display();
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Run whichever bulk action was submitted.
	 *
	 * @param QueueRepository $repository Queue.
	 * @return string Notice text, or empty.
	 */
	private function handle_bulk( QueueRepository $repository ): string {
		if ( empty( $_POST['job'] ) && empty( $_POST['action'] ) ) {
			return '';
		}

		// The nonce WP_List_Table generated for this form; see the note in render().
		check_admin_referer( self::BULK_NONCE );

		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage the queue.', 'oh-my-cache' ), '', [ 'response' => 403 ] );
		}

		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

		if ( '-1' === $action || '' === $action ) {
			$action = isset( $_POST['action2'] ) ? sanitize_key( wp_unslash( $_POST['action2'] ) ) : '';
		}

		$ids = isset( $_POST['job'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['job'] ) ) : [];
		$ids = array_values( array_filter( $ids ) );

		if ( ! $ids ) {
			return '';
		}

		switch ( $action ) {
			case 'run_now':
				$affected = $repository->run_now( $ids );
				Scheduler::kick();

				return sprintf(
					/* translators: %d: number of jobs. */
					_n( '%d job is due now.', '%d jobs are due now.', $affected, 'oh-my-cache' ),
					$affected
				);

			case 'retry':
				$affected = $repository->retry_dead( $ids );
				Scheduler::kick();

				return sprintf(
					/* translators: %d: number of jobs. */
					_n( '%d job re-queued.', '%d jobs re-queued.', $affected, 'oh-my-cache' ),
					$affected
				);

			case 'delete':
				$affected = $repository->delete( $ids );

				return sprintf(
					/* translators: %d: number of jobs. */
					_n( '%d job deleted.', '%d jobs deleted.', $affected, 'oh-my-cache' ),
					$affected
				);
		}

		return '';
	}
}
