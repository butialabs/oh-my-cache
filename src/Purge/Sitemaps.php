<?php
/**
 * Sitemap URLs for the plugin that generates them.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Purge;

use OhMyCache\Http\Loopback;
use OhMyCache\Queue\Scheduler;
use OhMyCache\Support\Url;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * A sitemap is a cached page, and it goes stale on the same events.
 *
 * File names are read from the site rather than guessed: the index is fetched, its entries stored,
 * and a change clears the entries related to it. Guessing would miss the news sitemap, page two of
 * a paginated one, a custom post type. Only the index URL is known in advance, and each one below
 * came from activating that plugin on a real install.
 *
 * Core is asked for its list through wp_sitemaps_get_server() instead, which is exact and free.
 *
 * For a generator that is not listed, use the paths field on the settings screen or the
 * oh_my_cache_sitemap_urls filter.
 */
final class Sitemaps {

	public const NONE = '';

	/** Not autoloaded: the list is unbounded and read rarely. */
	public const OPTION_INDEX = 'oh_my_cache_sitemap_index';

	/** A new file only appears when a page fills up, so twice a day is enough. */
	public const REFRESH_SECONDS = 43200;

	/** So a malformed index cannot become an enormous purge. */
	private const MAX_ENTRIES = 200;

	/**
	 * Index URL per provider, in detection order.
	 *
	 * Detected by the symbols each plugin declares, not by folder, because folders change between
	 * free and premium builds.
	 */
	private const PROVIDERS = [
		'yoast'    => [ 'class' => 'WPSEO_Sitemaps_Router', 'label' => 'Yoast SEO', 'index' => '/sitemap_index.xml' ],
		'rankmath' => [ 'class' => 'RankMath\Sitemap\Router', 'label' => 'Rank Math', 'index' => '/sitemap_index.xml' ],
		'aioseo'   => [ 'function' => 'aioseo', 'label' => 'All in One SEO', 'index' => '/sitemap.xml' ],
		'seopress' => [ 'const' => 'SEOPRESS_VERSION', 'label' => 'SEOPress', 'index' => '/sitemaps.xml' ],
	];

	/**
	 * Which generator is answering on this site.
	 *
	 * @return string Provider id, 'core', or an empty string when nothing generates sitemaps.
	 */
	public function provider(): string {
		foreach ( self::PROVIDERS as $id => $spec ) {
			if ( isset( $spec['class'] ) && class_exists( $spec['class'] ) ) {
				return $id;
			}

			if ( isset( $spec['function'] ) && function_exists( $spec['function'] ) ) {
				return $id;
			}

			if ( isset( $spec['const'] ) && defined( $spec['const'] ) ) {
				return $id;
			}
		}

		return $this->core_enabled() ? 'core' : self::NONE;
	}

	/**
	 * Human name for whatever was detected.
	 */
	public function provider_label(): string {
		$provider = $this->provider();

		if ( self::NONE === $provider ) {
			return __( 'none found', 'oh-my-cache' );
		}

		return 'core' === $provider
			? __( 'WordPress core', 'oh-my-cache' )
			: (string) self::PROVIDERS[ $provider ]['label'];
	}

	/**
	 * The index URL for the detected generator, or an empty string.
	 */
	public function index_url(): string {
		$provider = $this->provider();

		if ( self::NONE === $provider ) {
			return '';
		}

		return 'core' === $provider
			? home_url( '/wp-sitemap.xml' )
			: home_url( (string) self::PROVIDERS[ $provider ]['index'] );
	}

	/**
	 * The sitemap URLs a post change invalidates.
	 *
	 * More than its own file: a post also dates the sitemaps of its taxonomies, the author file
	 * and, for a couple of days, the news one.
	 *
	 * @param WP_Post $post The post.
	 * @return array<int, string>
	 */
	public function for_post( WP_Post $post ): array {
		$tokens = [ $post->post_type, 'author', 'users' ];

		foreach ( get_object_taxonomies( $post->post_type, 'names' ) as $taxonomy ) {
			$tokens[] = $taxonomy;
		}

		return $this->build( $tokens, true, $post );
	}

	/**
	 * The sitemap URLs a term change invalidates.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array<int, string>
	 */
	public function for_taxonomy( string $taxonomy ): array {
		return $this->build( [ $taxonomy ], false, null );
	}

	/**
	 * Everything: the index and every file it lists.
	 *
	 * @return array<int, string>
	 */
	public function all(): array {
		return $this->build( [], true, null, true );
	}

	/**
	 * Just the index.
	 *
	 * @return array<int, string>
	 */
	public function index(): array {
		return $this->build( [], false, null );
	}

	/* --------------------------------------------------------------------- */
	/* Discovery                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * The stored entries, or an empty list when there are none for this provider yet.
	 *
	 * @return array<int, string>
	 */
	public function discovered(): array {
		$stored = $this->stored();

		return array_values( array_filter( (array) ( $stored['urls'] ?? [] ), 'is_string' ) );
	}

	/**
	 * When the list was last read, or null if it has never been read for this generator.
	 */
	public function discovered_at(): ?int {
		$stored = $this->stored();

		return isset( $stored['time'] ) ? (int) $stored['time'] : null;
	}

	/**
	 * The stored record, only if it belongs to the generator running now.
	 *
	 * List and timestamp come from the same record on purpose. A blocked loopback stores an empty
	 * list with a fresh timestamp, and reading that as "never looked" would refetch on every purge
	 * for as long as the site stayed broken.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		$stored = get_option( self::OPTION_INDEX, [] );

		if ( ! is_array( $stored ) || ( $stored['provider'] ?? '' ) !== $this->provider() ) {
			return [];
		}

		return $stored;
	}

	/**
	 * Fetch the index and store what it lists.
	 *
	 * On cron, never during a purge: nobody pressing Publish should wait on an HTTP request. A
	 * purge with no list clears the index alone and asks for a refresh afterwards.
	 *
	 * @return array<int, string> The entries now stored.
	 */
	public function refresh(): array {
		$provider = $this->provider();
		$index    = $this->index_url();

		if ( self::NONE === $provider || '' === $index ) {
			delete_option( self::OPTION_INDEX );

			return [];
		}

		$urls = 'core' === $provider ? $this->core_list() : $this->fetch_list( $index );

		update_option(
			self::OPTION_INDEX,
			[
				'provider' => $provider,
				'index'    => $index,
				'urls'     => $urls,
				'time'     => time(),
			],
			false
		);

		return $urls;
	}

	/**
	 * Ask for a refresh soon, without blocking whatever is happening now.
	 */
	public function schedule_refresh(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || wp_next_scheduled( Scheduler::HOOK_SITEMAPS_NOW ) ) {
			return;
		}

		wp_schedule_single_event( time() + 30, Scheduler::HOOK_SITEMAPS_NOW );
	}

	/* --------------------------------------------------------------------- */

	/**
	 * @param array<int, string> $tokens  Names the change relates to.
	 * @param bool               $news    Whether a news sitemap is affected.
	 * @param WP_Post|null       $post    The post, when the change is a post change.
	 * @param bool               $all     Take every entry, not just the related ones.
	 * @return array<int, string>
	 */
	private function build( array $tokens, bool $news, ?WP_Post $post, bool $all = false ): array {
		$provider = $this->provider();
		$urls     = [];

		if ( self::NONE !== $provider ) {
			$index = $this->index_url();
			$urls  = [ $index ];

			$stored  = $this->stored();
			$entries = $this->discovered();
			$age     = isset( $stored['time'] ) ? (int) $stored['time'] : 0;

			// Never looked for this generator, or the list has aged. The index is cleared either
			// way, since that is the file that always changes.
			if ( ! $stored || time() - $age > self::REFRESH_SECONDS ) {
				$this->schedule_refresh();
			}

			foreach ( $entries as $entry ) {
				if ( $all || $this->relates( $entry, $tokens, $news ) ) {
					$urls[] = $entry;
				}
			}
		}

		/**
		 * Filters the sitemap URLs cleared for one change.
		 *
		 * How to teach the plugin about a generator it does not know, detected or not.
		 *
		 * @param array<int, string> $urls     Sitemap URLs.
		 * @param string             $provider Detected provider id, or an empty string.
		 * @param array<int, string> $tokens   Post type, taxonomies and so on the change touches.
		 * @param WP_Post|null       $post     The post, when the change is a post change.
		 */
		$urls = (array) apply_filters( 'oh_my_cache_sitemap_urls', $urls, $provider, $tokens, $post );

		return Url::normalize_all( $urls );
	}

	/**
	 * Whether one sitemap file has anything to do with this change.
	 *
	 * Two naming conventions, both copied from real output:
	 *
	 *   post-sitemap.xml, post-sitemap2.xml, product_cat-sitemap.xml   the SEO plugins
	 *   wp-sitemap-posts-post-1.xml, wp-sitemap-taxonomies-category-1.xml   core
	 *
	 * Pagination needs no special case: every page of a type matches the way page one does.
	 *
	 * @param string             $url    Sitemap URL.
	 * @param array<int, string> $tokens Names the change relates to.
	 * @param bool               $news   Whether a news sitemap counts as related.
	 */
	private function relates( string $url, array $tokens, bool $news ): bool {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = basename( $path );

		if ( '' === $name ) {
			return false;
		}

		// news-sitemap.xml or sitemap-news.xml, either order.
		if ( $news && preg_match( '/(^|[-_])news([-_.]|$)/i', $name ) ) {
			return true;
		}

		// Everything before "-sitemap": "post" in post-sitemap2.xml, "post-archive" in
		// post-archive-sitemap.xml.
		$marker = strpos( $name, '-sitemap' );
		$prefix = false === $marker ? '' : substr( $name, 0, $marker );

		foreach ( $tokens as $token ) {
			if ( '' === $token ) {
				continue;
			}

			// Comparing on the separator puts post-archive-sitemap.xml with the posts and leaves
			// post_tag-sitemap.xml out of them.
			if ( $prefix === $token || str_starts_with( $prefix, $token . '-' ) ) {
				return true;
			}

			if ( str_contains( $name, '-' . $token . '-' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Core knows its own list, so ask instead of fetching.
	 *
	 * @return array<int, string>
	 */
	private function core_list(): array {
		$urls   = [];
		$server = function_exists( 'wp_sitemaps_get_server' ) ? wp_sitemaps_get_server() : null;

		if ( ! $server || ! isset( $server->index ) || ! method_exists( $server->index, 'get_sitemap_list' ) ) {
			return [];
		}

		foreach ( $server->index->get_sitemap_list() as $entry ) {
			if ( ! empty( $entry['loc'] ) ) {
				$urls[] = (string) $entry['loc'];
			}
		}

		return array_slice( $urls, 0, self::MAX_ENTRIES );
	}

	/**
	 * Read the sub-sitemap URLs out of an index document.
	 *
	 * @param string $index Index URL.
	 * @return array<int, string>
	 */
	private function fetch_list( string $index ): array {
		$response = wp_remote_get(
			$index,
			Loopback::args(
				$index,
				[
					'timeout'    => 15,
					'user-agent' => 'Oh My Cache; ' . home_url( '/' ),
				]
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		$body = (string) wp_remote_retrieve_body( $response );

		// All in One SEO wraps every loc in CDATA.
		if ( ! preg_match_all( '#<loc>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*</loc>#is', $body, $matches ) ) {
			return [];
		}

		$urls = [];

		foreach ( $matches[1] as $url ) {
			$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_XML1, 'UTF-8' ) );

			// Same host only: an index must not decide what this site asks its CDN to clear.
			if ( '' !== $url && $url !== $index && Url::is_same_host( $url ) ) {
				$urls[] = $url;
			}
		}

		return array_slice( array_values( array_unique( $urls ) ), 0, self::MAX_ENTRIES );
	}

	/**
	 * Whether core is the one generating sitemaps.
	 *
	 * Every provider above switches this off when it takes over, so it also answers "nothing
	 * generates sitemaps here", as on a site that is not public.
	 */
	private function core_enabled(): bool {
		if ( ! function_exists( 'wp_sitemaps_get_server' ) ) {
			return false;
		}

		$server = wp_sitemaps_get_server();

		return $server && $server->sitemaps_enabled();
	}
}
