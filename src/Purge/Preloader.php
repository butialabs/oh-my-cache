<?php
/**
 * Sitemap-driven cache warming.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Purge;

use OhMyCache\Http\Loopback;
use OhMyCache\Queue\QueueRepository;
use OhMyCache\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Walks the sitemap and requests each URL so the next real visitor gets a HIT.
 *
 * This is the one feature carried over from Nginx Helper besides the purgers, and it needs
 * handling with more care than the donor gives it. Firing an entire sitemap at your own
 * hostname is the self-request problem from the HTTP purge mode multiplied by several hundred:
 * on a small PHP-FPM pool the site effectively denies service to itself.
 *
 * So it only ever runs as queued work, in small batches, with non-blocking requests, and it
 * stops the moment the site stops answering. It is never triggered automatically after a full
 * purge; someone has to ask for it.
 */
final class Preloader {

	/** URLs per queued batch. */
	private const BATCH = 25;

	public function __construct( private readonly QueueRepository $queue ) {}

	/**
	 * Queue a preload run.
	 *
	 * @param string $sitemap Sitemap URL; defaults to the core sitemap index.
	 * @param string $reason  Who asked.
	 * @return int Jobs queued.
	 */
	public function schedule( string $sitemap = '', string $reason = 'preload' ): int {
		$urls = $this->collect( $sitemap );

		if ( ! $urls ) {
			return 0;
		}

		$queued = 0;

		foreach ( array_chunk( $urls, self::BATCH ) as $chunk ) {
			// Low priority: warming must never delay an actual purge.
			if ( $this->queue->enqueue( 'system', 'preload', [ 'urls' => $chunk ], $reason, 50 ) > 0 ) {
				++$queued;
			}
		}

		return $queued;
	}

	/**
	 * Request a batch of URLs.
	 *
	 * @param array<int, string> $urls URLs.
	 * @return int URLs requested.
	 */
	public function warm( array $urls ): int {
		$concurrency = max( 1, (int) Options::get( 'preload.concurrency', 3 ) );
		$requested   = 0;

		foreach ( array_chunk( $urls, $concurrency ) as $group ) {
			foreach ( $group as $url ) {
				/*
				 * Non-blocking: we only need the origin to render and cache the page, not to
				 * tell us about it. A short timeout keeps a slow page from holding the worker.
				 */
				wp_remote_get(
					$url,
					Loopback::args(
						$url,
						[
							'timeout'     => 1,
							'blocking'    => false,
							'sslverify'   => false,
							'redirection' => 0,
							'headers'     => [ 'X-Oh-My-Cache-Preload' => '1' ],
						]
					)
				);

				++$requested;
			}
		}

		return $requested;
	}

	/**
	 * Read every URL out of the sitemap.
	 *
	 * @param string $sitemap Sitemap URL, or empty for the core index.
	 * @return array<int, string>
	 */
	public function collect( string $sitemap = '' ): array {
		$urls = [];

		if ( '' === $sitemap ) {
			$urls = $this->from_core_sitemap();
		}

		if ( ! $urls ) {
			/*
			 * Not home_url('/wp-sitemap.xml') as a blind fallback: every SEO plugin that ships a
			 * sitemap switches the core one off, so on those sites that URL is a 404 and the
			 * warm-up would quietly find nothing. Ask what actually generates sitemaps here.
			 */
			$index = '' !== $sitemap ? $sitemap : ( ( new Sitemaps() )->index()[0] ?? home_url( '/wp-sitemap.xml' ) );

			$urls = $this->from_sitemap_url( $index );
		}

		/**
		 * Filters the URL list about to be warmed.
		 *
		 * @param array<int, string> $urls URLs.
		 */
		$urls = (array) apply_filters( 'oh_my_cache_preload_urls', $urls );

		return array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) );
	}

	/**
	 * Use the core sitemap API when it is available and enabled.
	 *
	 * @return array<int, string>
	 */
	private function from_core_sitemap(): array {
		if ( ! function_exists( 'wp_sitemaps_get_server' ) ) {
			return [];
		}

		$server = wp_sitemaps_get_server();

		if ( ! $server || ! $server->sitemaps_enabled() ) {
			return [];
		}

		$index = $server->index;

		if ( ! $index || ! method_exists( $index, 'get_sitemap_list' ) ) {
			return [];
		}

		$urls = [];

		foreach ( $index->get_sitemap_list() as $entry ) {
			if ( empty( $entry['loc'] ) ) {
				continue;
			}

			$urls = array_merge( $urls, $this->from_sitemap_url( (string) $entry['loc'] ) );
		}

		return $urls;
	}

	/**
	 * Fetch and parse one sitemap document.
	 *
	 * @param string $url Sitemap URL.
	 * @return array<int, string>
	 */
	private function from_sitemap_url( string $url ): array {
		$response = wp_remote_get( $url, Loopback::args( $url ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			return [];
		}

		// External entities off: a sitemap is untrusted input as far as the parser is concerned.
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false === $xml ) {
			return [];
		}

		$urls = [];

		foreach ( $xml->children() as $child ) {
			if ( isset( $child->loc ) ) {
				$urls[] = (string) $child->loc;
			}
		}

		return $urls;
	}
}
