<?php
/**
 * Implementation behind the public API functions.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Api;

use OhMyCache\Cache\DriverManager;
use OhMyCache\Plugin;
use OhMyCache\Purge\Coordinator;
use OhMyCache\Purge\UrlCollector;
use OhMyCache\Queue\QueueRepository;
use OhMyCache\Queue\Scheduler;
use OhMyCache\Support\Url;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Everything oh_my_cache_purge_*() actually does.
 *
 * The thin functions in api.php exist so callers do not have to know a namespace, and so the
 * file that declares them can be loaded before the plugin boots without dragging the whole
 * class graph along with it.
 */
final class Facade {

	/**
	 * Normalise caller arguments.
	 *
	 * @param array<string, mixed> $args Raw arguments.
	 * @return array{drivers: array<int, string>, mode: string|null, priority: int, reason: string}
	 */
	public static function parse_args( array $args ): array {
		$drivers = $args['drivers'] ?? [ 'all' ];
		$drivers = is_string( $drivers ) ? [ $drivers ] : (array) $drivers;
		$drivers = array_values( array_filter( array_map( 'strval', $drivers ) ) );

		$mode = isset( $args['mode'] ) ? (string) $args['mode'] : null;
		if ( ! in_array( $mode, [ 'realtime', 'queue', 'now' ], true ) ) {
			$mode = null;
		}

		return [
			'drivers'  => $drivers ?: [ 'all' ],
			'mode'     => $mode,
			'priority' => isset( $args['priority'] ) ? (int) $args['priority'] : 10,
			'reason'   => isset( $args['reason'] ) ? (string) $args['reason'] : '',
		];
	}

	/**
	 * Purge specific URLs.
	 *
	 * @param string|array<int, string> $urls URLs.
	 * @param array<string, mixed>      $args Options.
	 */
	public static function purge_url( string|array $urls, array $args = [] ): PurgeTicket {
		$plugin = Plugin::instance();

		if ( ! $plugin ) {
			return PurgeTicket::rejected( 'Oh My Cache is not running.' );
		}

		$urls = Url::normalize_all( (array) $urls );

		if ( ! $urls ) {
			return PurgeTicket::rejected( 'No usable URLs were supplied.' );
		}

		$parsed = self::parse_args( $args );

		return self::dispatch(
			static function ( Coordinator $coordinator ) use ( $urls, $parsed ): void {
				$coordinator->add( $urls, self::reason( $parsed['reason'], 'api' ) );
			},
			$parsed
		);
	}

	/**
	 * Purge everything a post touches.
	 *
	 * @param int|WP_Post          $post Post or id.
	 * @param array<string, mixed> $args Options.
	 */
	public static function purge_post( int|WP_Post $post, array $args = [] ): PurgeTicket {
		$plugin = Plugin::instance();

		if ( ! $plugin ) {
			return PurgeTicket::rejected( 'Oh My Cache is not running.' );
		}

		$post_id = $post instanceof WP_Post ? $post->ID : (int) $post;

		/** @var UrlCollector $collector */
		$collector = $plugin->container()->get( 'collector' );
		$urls      = $collector->for_post( $post_id );

		if ( ! $urls ) {
			return PurgeTicket::rejected( 'That post produced no purgeable URLs.' );
		}

		$parsed = self::parse_args( $args );

		return self::dispatch(
			static function ( Coordinator $coordinator ) use ( $urls, $parsed ): void {
				$coordinator->add( $urls, self::reason( $parsed['reason'], 'api:post' ) );
			},
			$parsed
		);
	}

	/**
	 * Purge a term archive.
	 *
	 * @param int                  $term_id  Term id.
	 * @param string               $taxonomy Taxonomy.
	 * @param array<string, mixed> $args     Options.
	 */
	public static function purge_term( int $term_id, string $taxonomy, array $args = [] ): PurgeTicket {
		$plugin = Plugin::instance();

		if ( ! $plugin ) {
			return PurgeTicket::rejected( 'Oh My Cache is not running.' );
		}

		/** @var UrlCollector $collector */
		$collector = $plugin->container()->get( 'collector' );
		$urls      = $collector->for_term( $term_id, $taxonomy );

		if ( ! $urls ) {
			return PurgeTicket::rejected( 'That term produced no purgeable URLs.' );
		}

		$parsed = self::parse_args( $args );

		return self::dispatch(
			static function ( Coordinator $coordinator ) use ( $urls, $parsed ): void {
				$coordinator->add( $urls, self::reason( $parsed['reason'], 'api:term' ) );
			},
			$parsed
		);
	}

	/**
	 * Purge everything.
	 *
	 * @param array<string, mixed> $args Options.
	 */
	public static function purge_all( array $args = [] ): PurgeTicket {
		$plugin = Plugin::instance();

		if ( ! $plugin ) {
			return PurgeTicket::rejected( 'Oh My Cache is not running.' );
		}

		$parsed = self::parse_args( $args );

		return self::dispatch(
			static function ( Coordinator $coordinator ) use ( $parsed ): void {
				$coordinator->add_purge_all( self::reason( $parsed['reason'], 'api:all' ) );
			},
			$parsed
		);
	}

	/**
	 * Purge by wildcard pattern.
	 *
	 * Only drivers that can express this honour it. NGINX in unlink mode cannot: its cache key
	 * is an md5 hash, so there is no prefix to match, and it reports a skip with that reason
	 * rather than a success it did not achieve.
	 *
	 * @param string               $pattern Pattern.
	 * @param array<string, mixed> $args    Options.
	 */
	public static function purge_pattern( string $pattern, array $args = [] ): PurgeTicket {
		$plugin = Plugin::instance();

		if ( ! $plugin ) {
			return PurgeTicket::rejected( 'Oh My Cache is not running.' );
		}

		$pattern = trim( $pattern );

		if ( '' === $pattern ) {
			return PurgeTicket::rejected( 'No pattern was supplied.' );
		}

		$parsed = self::parse_args( $args );

		/** @var DriverManager $drivers */
		$drivers = $plugin->container()->get( 'drivers' );
		/** @var QueueRepository $queue */
		$queue = $plugin->container()->get( 'queue' );

		$ticket  = new PurgeTicket();
		$job_ids = [];
		$inline  = [];

		foreach ( $drivers->resolve( $parsed['drivers'] ) as $id => $driver ) {
			if ( ! $driver->supports_wildcards() ) {
				$inline[ $id ] = $driver->purge_pattern( $pattern );
				continue;
			}

			if ( 'now' === $parsed['mode'] ) {
				$inline[ $id ] = $driver->purge_pattern( $pattern );
				continue;
			}

			$job_id = $queue->enqueue(
				$id,
				'purge_pattern',
				[ 'meta' => [ 'pattern' => $pattern ] ],
				self::reason( $parsed['reason'], 'api:pattern' ),
				$parsed['priority']
			);

			if ( $job_id > 0 ) {
				$job_ids[]     = $job_id;
				$inline[ $id ] = null;
			}
		}

		if ( $job_ids ) {
			Scheduler::kick();
		}

		return $ticket->with_inline( $inline )->with_jobs( $job_ids );
	}

	/**
	 * Whether the plugin is loaded and switched on.
	 */
	public static function is_active(): bool {
		$plugin = Plugin::instance();

		if ( ! $plugin ) {
			return false;
		}

		/** @var DriverManager $drivers */
		$drivers = $plugin->container()->get( 'drivers' );

		return [] !== $drivers->enabled();
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Stage work on the coordinator and, unless the caller wants it deferred to shutdown,
	 * dispatch it immediately.
	 *
	 * @param callable                                                             $stage  Receives the coordinator.
	 * @param array{drivers: array<int, string>, mode: string|null, priority: int, reason: string} $parsed Arguments.
	 */
	private static function dispatch( callable $stage, array $parsed ): PurgeTicket {
		$plugin = Plugin::instance();

		if ( ! $plugin ) {
			return PurgeTicket::rejected( 'Oh My Cache is not running.' );
		}

		/** @var Coordinator $coordinator */
		$coordinator = $plugin->container()->get( 'coordinator' );

		$stage( $coordinator );

		$results = $coordinator->dispatch( $parsed['drivers'], $parsed['mode'] );

		return ( new PurgeTicket() )->with_inline( $results );
	}

	/**
	 * Label a job, prefixing caller-supplied text so the queue screen shows where it came from.
	 *
	 * @param string $supplied Caller reason.
	 * @param string $fallback Default label.
	 */
	private static function reason( string $supplied, string $fallback ): string {
		$supplied = trim( $supplied );

		return '' === $supplied ? $fallback : 'api: ' . $supplied;
	}
}
