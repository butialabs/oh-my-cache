<?php
/**
 * Admin bar controls.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Admin;

use OhMyCache\Container;
use OhMyCache\Queue\QueueRepository;
use OhMyCache\Support\Options;
use WP_Admin_Bar;

defined( 'ABSPATH' ) || exit;

/**
 * Purge from wherever you happen to be.
 *
 * The node never purges anything while rendering. It links to admin-post.php with a nonce, so
 * the purge happens on a POST-shaped request that redirects afterwards, and a stray prefetch or
 * a refresh cannot clear the cache by accident.
 */
final class AdminBar {

	public const ACTION = 'oh_my_cache_purge';

	public function __construct( private readonly Container $container ) {}

	public function register(): void {
		add_action( 'admin_bar_menu', [ $this, 'add_nodes' ], 100 );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
	}

	/**
	 * @param WP_Admin_Bar $bar Admin bar.
	 */
	public function add_nodes( $bar ): void {
		if ( ! $bar instanceof WP_Admin_Bar || ! current_user_can( $this->capability() ) ) {
			return;
		}

		/** @var QueueRepository $queue */
		$queue = $this->container->get( 'queue' );

		$depth = Options::queue_depth();
		$title = __( 'Oh My Cache!', 'oh-my-cache' );

		if ( $depth > 0 ) {
			$title .= sprintf( ' <span class="omc-badge">%d</span>', $depth );
		}

		$bar->add_node(
			[
				'id'    => 'oh-my-cache',
				'title' => $title,
				'href'  => admin_url( 'admin.php?page=oh-my-cache' ),
			]
		);

		if ( ! is_admin() ) {
			$bar->add_node(
				[
					'id'     => 'oh-my-cache-this',
					'parent' => 'oh-my-cache',
					'title'  => __( 'Purge this URL', 'oh-my-cache' ),
					'href'   => $this->action_url( 'url', $this->current_url() ),
				]
			);
		}

		$bar->add_node(
			[
				'id'     => 'oh-my-cache-all',
				'parent' => 'oh-my-cache',
				'title'  => __( 'Purge everything', 'oh-my-cache' ),
				'href'   => $this->action_url( 'all' ),
			]
		);

		if ( $depth > 0 ) {
			$age = $queue->oldest_pending_age();

			$bar->add_node(
				[
					'id'     => 'oh-my-cache-queue',
					'parent' => 'oh-my-cache',
					'title'  => null === $age
						? sprintf(
							/* translators: %d: number of queued jobs. */
							__( 'Queue: %d waiting', 'oh-my-cache' ),
							$depth
						)
						: sprintf(
							/* translators: 1: number of queued jobs, 2: human readable age. */
							__( 'Queue: %1$d waiting, oldest %2$s', 'oh-my-cache' ),
							$depth,
							human_time_diff( time() - $age, time() )
						),
					'href'   => admin_url( 'admin.php?page=oh-my-cache-queue' ),
				]
			);
		}
	}

	/**
	 * Handle the purge request.
	 */
	public function handle(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to purge the cache.', 'oh-my-cache' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::ACTION );

		$what = isset( $_GET['what'] ) ? sanitize_key( wp_unslash( $_GET['what'] ) ) : '';

		if ( 'all' === $what ) {
			oh_my_cache_purge_all( [ 'reason' => 'admin-bar' ] );
		} elseif ( 'url' === $what && ! empty( $_GET['url'] ) ) {
			$url = esc_url_raw( wp_unslash( $_GET['url'] ) );

			if ( '' !== $url ) {
				oh_my_cache_purge_url( $url, [ 'reason' => 'admin-bar' ] );
			}
		}

		$back = wp_get_referer();

		wp_safe_redirect( add_query_arg( 'omc-purged', '1', $back ?: home_url( '/' ) ) );
		exit;
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Build a nonced action URL.
	 *
	 * @param string $what Either "all" or "url".
	 * @param string $url  URL, when purging one.
	 */
	private function action_url( string $what, string $url = '' ): string {
		$args = [
			'action' => self::ACTION,
			'what'   => $what,
		];

		if ( '' !== $url ) {
			$args['url'] = rawurlencode( $url );
		}

		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::ACTION );
	}

	/**
	 * The URL currently being viewed.
	 */
	private function current_url(): string {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return home_url( '/' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$uri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );

		return home_url( $uri );
	}

	private function capability(): string {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}
}
