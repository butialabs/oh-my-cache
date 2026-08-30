<?php
/**
 * Admin AJAX endpoints.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Admin;

use OhMyCache\Cache\DriverManager;
use OhMyCache\Cache\Redis\Connection;
use OhMyCache\Cloudflare\Client;
use OhMyCache\Cloudflare\Exception\ApiException;
use OhMyCache\Container;
use OhMyCache\Queue\QueueRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The handful of things the settings and wizard screens ask for without a page reload.
 *
 * Every handler checks the nonce and the capability. The nonce proves the request came from our
 * form; the capability proves the person is allowed to do it. They answer different questions
 * and both are required.
 */
final class Ajax {

	public const NONCE = 'oh_my_cache_ajax';

	public function __construct( private readonly Container $container ) {}

	public function register(): void {
		add_action( 'wp_ajax_oh_my_cache_test_driver', [ $this, 'test_driver' ] );
		add_action( 'wp_ajax_oh_my_cache_queue_status', [ $this, 'queue_status' ] );
	}

	/**
	 * Test one driver and report back in plain language.
	 */
	public function test_driver(): void {
		// Verifies the nonce and the capability; see guard() below.
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() called check_ajax_referer() above.
		$id = isset( $_POST['driver'] ) ? sanitize_key( wp_unslash( $_POST['driver'] ) ) : '';

		/** @var DriverManager $drivers */
		$drivers = $this->container->get( 'drivers' );
		$driver  = $drivers->get( $id );

		if ( ! $driver ) {
			wp_send_json_error( [ 'message' => __( 'Unknown driver.', 'oh-my-cache' ) ] );
		}

		$availability = $driver->availability();

		if ( ! $availability->ok ) {
			wp_send_json_error(
				[
					'message' => $availability->reason,
					'hint'    => $availability->hint,
				]
			);
		}

		wp_send_json_success( [ 'message' => $this->probe( $id ) ] );
	}

	/**
	 * Current queue counts, used by the wizard's test purge to show a real result.
	 */
	public function queue_status(): void {
		$this->guard();

		/** @var QueueRepository $queue */
		$queue = $this->container->get( 'queue' );

		wp_send_json_success(
			[
				'counts' => $queue->counts(),
				'oldest' => $queue->oldest_pending_age(),
			]
		);
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Driver-specific liveness probe.
	 *
	 * @param string $id Driver id.
	 */
	private function probe( string $id ): string {
		if ( 'redis' === $id ) {
			$connection = new Connection();

			if ( ! $connection->ping() ) {
				return (string) $connection->error();
			}

			return sprintf(
				/* translators: 1: Redis server version, 2: phpredis extension version. */
				__( 'Connected. Redis %1$s via phpredis %2$s.', 'oh-my-cache' ),
				$connection->server_version(),
				Connection::extension_version()
			);
		}

		if ( 'cloudflare' === $id ) {
			try {
				$client = new Client( 10.0 );
				$token  = $client->verify_token();
			} catch ( ApiException $e ) {
				return $e->getMessage();
			}

			return sprintf(
				/* translators: %s: token status reported by Cloudflare. */
				__( 'Token is valid (status: %s).', 'oh-my-cache' ),
				(string) ( $token['status'] ?? 'active' )
			);
		}

		if ( 'nginx' === $id ) {
			return __( 'Cache directory exists and is writable.', 'oh-my-cache' );
		}

		return __( 'Ready.', 'oh-my-cache' );
	}

	/**
	 * Nonce plus capability, on every endpoint.
	 */
	private function guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', 'oh-my-cache' ) ], 403 );
		}
	}
}
