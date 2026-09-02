<?php
/**
 * Dashboard screen.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Admin;

use OhMyCache\Cache\Cooldown;
use OhMyCache\Cache\DriverInterface;
use OhMyCache\Cache\DriverManager;
use OhMyCache\Cloudflare\Credentials;
use OhMyCache\Container;
use OhMyCache\Queue\QueueRepository;
use OhMyCache\Queue\Scheduler;
use OhMyCache\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * At-a-glance state, plus the three buttons people actually come here for.
 */
final class Dashboard {

	public const ACTION = 'oh_my_cache_dashboard';

	public function __construct( private readonly Container $container ) {}

	/**
	 * Act on the dashboard buttons, then redirect.
	 *
	 * Called from admin_init so the diagnostics below describe the state after the action, and
	 * so a refresh does not purge the cache a second time.
	 */
	public function handle_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( Menu::SLUG !== $page ) {
			return;
		}

		$notice = $this->handle_post();

		if ( '' === $notice ) {
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'       => Menu::SLUG,
					'oh-my-cache-notice' => $notice,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render(): void {
		// A message we put in the URL ourselves after a redirect; displayed, never acted on.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['oh-my-cache-notice'] ) ? sanitize_text_field( wp_unslash( $_GET['oh-my-cache-notice'] ) ) : '';

		if ( '' !== $notice ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $notice )
			);
		}

		/** @var DriverManager $drivers */
		$drivers = $this->container->get( 'drivers' );
		/** @var QueueRepository $queue */
		$queue = $this->container->get( 'queue' );

		$counts  = $queue->counts();
		$oldest  = $queue->oldest_pending_age();
		$doctor  = ( new Doctor( $this->container ) )->run();
		$last    = Scheduler::last_run_age();

		echo '<div class="wrap oh-my-cache">';
		printf( '<h1>%s</h1>', esc_html__( 'Oh My Cache!', 'oh-my-cache' ) );

		$this->render_actions();

		/*
		 * Two cards, one per half of the setup, each naming the category first and the concrete
		 * product underneath. Listing every registered driver instead would always show one of
		 * NGINX or Redis sitting there "Disabled", since they are mutually exclusive, and would
		 * treat Cloudflare as though it were the category rather than one answer to it.
		 */
		echo '<div class="oh-my-cache-cards">';

		$this->render_card(
			__( 'Driver', 'oh-my-cache' ),
			$drivers->active_local(),
			__( 'No local cache', 'oh-my-cache' )
		);

		$this->render_card(
			__( 'CDN edge cache', 'oh-my-cache' ),
			$drivers->active_cdn(),
			__( 'No CDN', 'oh-my-cache' )
		);

		echo '</div>';

		$this->render_cloudflare();
		$this->render_queue_summary( $counts, $oldest, $last );
		$this->render_doctor( $doctor );

		echo '</div>';
	}

	/* --------------------------------------------------------------------- */

	/**
	 * One dashboard card: category, then the product filling it, then its state.
	 *
	 * @param string               $title   Category name, such as Driver or CDN edge cache.
	 * @param DriverInterface|null $driver  The active driver, or null when nothing is configured.
	 * @param string               $empty   What to show in place of a product name when null.
	 */
	private function render_card( string $title, ?DriverInterface $driver, string $empty ): void {
		echo '<div class="oh-my-cache-card">';
		printf( '<h3>%s</h3>', esc_html( $title ) );

		if ( ! $driver instanceof DriverInterface ) {
			printf( '<p class="oh-my-cache-provider">%s</p>', esc_html( $empty ) );
			printf( '<p class="oh-my-cache-state">%s</p>', esc_html__( 'Not configured', 'oh-my-cache' ) );
			echo '</div>';

			return;
		}

		$availability = $driver->availability();

		printf( '<p class="oh-my-cache-provider">%s</p>', esc_html( $driver->label() ) );
		printf(
			'<p class="oh-my-cache-state">%s</p>',
			esc_html( $availability->ok ? __( 'Ready', 'oh-my-cache' ) : __( 'Unavailable', 'oh-my-cache' ) )
		);

		if ( ! $availability->ok ) {
			printf( '<p class="oh-my-cache-reason">%s</p>', esc_html( $availability->reason ) );

			if ( '' !== $availability->hint ) {
				printf( '<p class="oh-my-cache-hint">%s</p>', esc_html( $availability->hint ) );
			}
		}

		printf(
			'<p class="oh-my-cache-meta">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: dispatch mode. */
					__( 'Dispatch: %s', 'oh-my-cache' ),
					method_exists( $driver, 'dispatch_mode' ) ? $driver->dispatch_mode() : 'realtime'
				)
			)
		);

		if ( Cooldown::active( $driver->id() ) ) {
			printf(
				'<p class="oh-my-cache-warning">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: seconds. */
						__( 'Circuit breaker open for %d more seconds; work is going straight to the queue.', 'oh-my-cache' ),
						Cooldown::remaining( $driver->id() )
					)
				)
			);
		}

		echo '</div>';
	}

	private function render_actions(): void {
		echo '<form method="post" class="oh-my-cache-actions">';
		wp_nonce_field( self::ACTION );
		printf( '<input type="hidden" name="oh_my_cache_action" value="1" />' );

		printf(
			'<button type="submit" name="do" value="purge_all" class="button button-primary">%s</button> ',
			esc_html__( 'Purge everything', 'oh-my-cache' )
		);

		printf(
			'<button type="submit" name="do" value="preload" class="button">%s</button> ',
			esc_html__( 'Preload from sitemap', 'oh-my-cache' )
		);

		printf(
			'<span class="oh-my-cache-inline-field"><input type="url" name="url" placeholder="%s" class="regular-text" /> <button type="submit" name="do" value="purge_url" class="button">%s</button></span>',
			esc_attr__( 'https://example.com/a-page/', 'oh-my-cache' ),
			esc_html__( 'Purge this URL', 'oh-my-cache' )
		);

		echo '</form>';
	}

	private function render_cloudflare(): void {
		$driver = $this->container->get( 'drivers' )->get( 'cloudflare' );

		if ( ! $driver || ! $driver->is_enabled() ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Cloudflare', 'oh-my-cache' ) . '</h2>';
		echo '<table class="widefat striped oh-my-cache-table"><tbody>';

		$rows = [
			__( 'Zone', 'oh-my-cache' )       => (string) Options::cf_state( 'zone_name', '' ) ?: __( 'not resolved', 'oh-my-cache' ),
			__( 'Plan', 'oh-my-cache' )       => (string) Options::cf_state( 'plan', '' ) ?: __( 'unknown', 'oh-my-cache' ),
			__( 'Credential', 'oh-my-cache' ) => $this->credential_label(),
			__( 'Edge TTL', 'oh-my-cache' )   => (int) Options::get( 'edge.ttl_seconds', 0 ) > 0
				? sprintf(
					/* translators: %d: seconds. */
					__( '%d seconds', 'oh-my-cache' ),
					(int) Options::get( 'edge.ttl_seconds', 0 )
				)
				: __( 'off', 'oh-my-cache' ),
		];

		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><th scope="row">%s</th><td>%s</td></tr>',
				esc_html( (string) $label ),
				esc_html( (string) $value )
			);
		}

		echo '</tbody></table>';
	}

	private function credential_label(): string {
		return match ( Credentials::token_source() ) {
			'env'      => __( 'API token from an environment variable (not stored in the database)', 'oh-my-cache' ),
			'constant' => __( 'API token from a constant (not stored in the database)', 'oh-my-cache' ),
			'database' => __( 'API token stored in the database', 'oh-my-cache' ),
			default    => __( 'none configured', 'oh-my-cache' ),
		};
	}

	/**
	 * @param array<string, int> $counts Status counts.
	 * @param int|null           $oldest Age of the oldest pending job.
	 * @param int|null           $last   Age of the last worker run.
	 */
	private function render_queue_summary( array $counts, ?int $oldest, ?int $last ): void {
		echo '<h2>' . esc_html__( 'Queue', 'oh-my-cache' ) . '</h2>';

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: pending count, 2: dead count. */
					__( '%1$d waiting, %2$d gave up.', 'oh-my-cache' ),
					(int) ( $counts['pending'] ?? 0 ),
					(int) ( $counts['dead'] ?? 0 )
				)
			)
		);

		/*
		 * The oldest pending age is shown rather than a claim that the queue is instantaneous.
		 * spawn_cron() refuses to spawn more than once a minute, so queued work can genuinely
		 * wait, and hiding that just makes it confusing when it happens.
		 */
		if ( null !== $oldest ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: human readable duration. */
						__( 'Oldest job has been waiting %s.', 'oh-my-cache' ),
						human_time_diff( time() - $oldest, time() )
					)
				)
			);
		}

		printf(
			'<p>%s</p>',
			esc_html(
				null === $last
					? __( 'The worker has not run yet.', 'oh-my-cache' )
					: sprintf(
						/* translators: %s: human readable duration. */
						__( 'Worker last ran %s ago.', 'oh-my-cache' ),
						human_time_diff( time() - $last, time() )
					)
			)
		);

		printf(
			'<p><a href="%s" class="button">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_QUEUE ) ),
			esc_html__( 'Open the queue', 'oh-my-cache' )
		);
	}

	/**
	 * @param array<int, array<string, string>> $results Doctor results.
	 */
	private function render_doctor( array $results ): void {
		echo '<h2>' . esc_html__( 'Diagnostics', 'oh-my-cache' ) . '</h2>';
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
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Act on the dashboard buttons.
	 *
	 * @return string Notice text for the redirect, or empty when nothing happened.
	 */
	private function handle_post(): string {
		if ( empty( $_POST['oh_my_cache_action'] ) ) {
			return '';
		}

		check_admin_referer( self::ACTION );

		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to purge the cache.', 'oh-my-cache' ), '', [ 'response' => 403 ] );
		}

		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';

		if ( 'purge_all' === $do ) {
			return oh_my_cache_purge_all( [ 'reason' => 'admin' ] )->summary();
		}

		if ( 'purge_url' === $do && ! empty( $_POST['url'] ) ) {
			$url = esc_url_raw( wp_unslash( $_POST['url'] ) );

			if ( '' !== $url ) {
				return oh_my_cache_purge_url( $url, [ 'reason' => 'admin' ] )->summary();
			}

			return '';
		}

		if ( 'preload' === $do ) {
			$queued = $this->container->get( 'preloader' )->schedule( '', 'admin:preload' );

			return sprintf(
				/* translators: %d: number of queued batches. */
				_n( '%d preload batch queued.', '%d preload batches queued.', $queued, 'oh-my-cache' ),
				$queued
			);
		}

		return '';
	}
}
