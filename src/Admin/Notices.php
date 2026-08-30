<?php
/**
 * Admin notices.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Admin;

use OhMyCache\Container;
use OhMyCache\Queue\QueueRepository;
use OhMyCache\Queue\Scheduler;
use OhMyCache\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces the failures that are otherwise invisible.
 *
 * A cache plugin fails quietly by nature: nothing errors, pages just go stale. Two states
 * genuinely hurt, a dead worker and a pinned edge TTL while clearing is broken, and those are
 * the ones said out loud here.
 */
final class Notices {

	public function __construct( private readonly Container $container ) {}

	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->cron_stalled();
		$this->dead_jobs();
		$this->edge_ttl_interlock();
	}

	/**
	 * Work is waiting and nothing is draining it.
	 */
	private function cron_stalled(): void {
		if ( ! Scheduler::looks_stalled() ) {
			return;
		}

		$this->notice(
			'error',
			__( 'Oh My Cache: purges are queued but the worker is not running.', 'oh-my-cache' ),
			sprintf(
				/* translators: %s: WP-CLI command, already escaped. */
				__( 'If DISABLE_WP_CRON is set, add a system cron entry such as %s, otherwise queued purges will never happen and pages will stay stale.', 'oh-my-cache' ),
				'<code>* * * * * wp omc queue run --all --quiet</code>'
			)
		);
	}

	/**
	 * Jobs that ran out of attempts.
	 */
	private function dead_jobs(): void {
		/** @var QueueRepository $queue */
		$queue = $this->container->get( 'queue' );

		$counts = $queue->counts();
		$dead   = (int) ( $counts['dead'] ?? 0 );

		if ( $dead < 1 ) {
			return;
		}

		$this->notice(
			'warning',
			sprintf(
				/* translators: %d: number of failed jobs. */
				_n(
					'Oh My Cache: %d purge job gave up after exhausting its retries.',
					'Oh My Cache: %d purge jobs gave up after exhausting their retries.',
					$dead,
					'oh-my-cache'
				),
				$dead
			),
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=oh-my-cache-queue&status=dead' ) ),
				esc_html__( 'Review them and retry', 'oh-my-cache' )
			)
		);
	}

	/**
	 * The genuinely dangerous combination: pages pinned at the edge with no working purge.
	 */
	private function edge_ttl_interlock(): void {
		$ttl = (int) Options::get( 'edge.ttl_seconds', 0 );

		if ( $ttl < 1 ) {
			return;
		}

		$driver = $this->container->get( 'drivers' )->get( 'cloudflare' );
		$broken = ! $driver || ! $driver->is_enabled() || ! $driver->availability()->ok;

		if ( ! $broken ) {
			return;
		}

		$this->notice(
			'error',
			__( 'Oh My Cache: guest pages are cached at the Cloudflare edge, but Cloudflare purging is not working.', 'oh-my-cache' ),
			sprintf(
				/* translators: %d: TTL in seconds. */
				__( 'Visitors will keep seeing stale HTML for up to %d seconds after every edit. Either fix the Cloudflare connection or set the edge TTL back to zero until you do.', 'oh-my-cache' ),
				$ttl
			)
		);
	}

	/**
	 * @param string $type    error, warning or success.
	 * @param string $title   Plain text.
	 * @param string $message May contain a small amount of trusted markup.
	 */
	private function notice( string $type, string $title, string $message = '' ): void {
		printf(
			'<div class="notice notice-%s"><p><strong>%s</strong>%s</p></div>',
			esc_attr( $type ),
			esc_html( $title ),
			'' === $message ? '' : ' ' . wp_kses( $message, [
				'a'    => [ 'href' => [] ],
				'code' => [],
			] )
		);
	}
}
