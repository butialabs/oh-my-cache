<?php
/**
 * Compatibility with the two donor plugins.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Purge;

use OhMyCache\Container;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps integrations written for Nginx Helper or App for Cloudflare working unchanged.
 *
 * Sites replacing those plugins usually have a snippet or two calling their actions, and a
 * theme or plugin that purges via rt_nginx_helper_purge_all should not quietly stop working
 * just because the cache plugin underneath it changed.
 */
final class LegacyHooks {

	public function __construct( private readonly Container $container ) {}

	public function register(): void {
		// Nginx Helper.
		add_action( 'rt_nginx_helper_purge_all', [ $this, 'on_purge_all' ] );

		// App for Cloudflare.
		add_action( 'cloudflare_purge_everything', [ $this, 'on_purge_all' ] );

		/*
		 * Autoptimize announces its own cache clear; both donors listen for it, and a site
		 * running Autoptimize expects a purge to follow.
		 */
		add_action( 'autoptimize_action_cachepurged', [ $this, 'on_purge_all' ] );

		// Our own action-based API entry points.
		add_action( 'oh_my_cache_purge_all', [ $this, 'on_api_purge_all' ], 10, 1 );
		add_action( 'oh_my_cache_purge_url', [ $this, 'on_api_purge_url' ], 10, 2 );
		add_action( 'oh_my_cache_purge_post', [ $this, 'on_api_purge_post' ], 10, 2 );
	}

	public function on_purge_all(): void {
		$this->container->get( 'coordinator' )->add_purge_all( 'legacy:action' );
	}

	/**
	 * @param array<string, mixed> $args Optional API arguments.
	 */
	public function on_api_purge_all( $args = [] ): void {
		if ( function_exists( 'oh_my_cache_purge_all' ) ) {
			oh_my_cache_purge_all( is_array( $args ) ? $args : [] );
		}
	}

	/**
	 * @param string|array<int, string> $urls URLs.
	 * @param array<string, mixed>      $args Optional API arguments.
	 */
	public function on_api_purge_url( $urls, $args = [] ): void {
		if ( function_exists( 'oh_my_cache_purge_url' ) ) {
			oh_my_cache_purge_url( $urls, is_array( $args ) ? $args : [] );
		}
	}

	/**
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $args    Optional API arguments.
	 */
	public function on_api_purge_post( $post_id, $args = [] ): void {
		if ( function_exists( 'oh_my_cache_purge_post' ) ) {
			oh_my_cache_purge_post( (int) $post_id, is_array( $args ) ? $args : [] );
		}
	}

	/**
	 * Apply the donor filters to a URL set, so existing customisations still bite.
	 *
	 * Called from the coordinator path via oh_my_cache_purge_urls.
	 *
	 * @param array<int, string> $urls URLs.
	 * @return array<int, string>
	 */
	public static function apply_legacy_filters( array $urls ): array {
		/*
		 * These two names are unprefixed on purpose, which is the whole point of this class. They
		 * belong to the plugins being replaced, and a site migrating here almost certainly has a
		 * snippet hooked to one of them. Renaming them to our prefix would silently break exactly
		 * the customisation this file exists to preserve.
		 */

		/** This filter is documented in Nginx Helper. */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$urls = (array) apply_filters( 'rt_nginx_helper_purge_urls', $urls, true );

		/** This filter is documented in App for Cloudflare. */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$urls = (array) apply_filters( 'cloudflare_purge_by_url', $urls, 0 );

		return array_values( array_filter( array_map( 'strval', $urls ) ) );
	}
}
