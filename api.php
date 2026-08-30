<?php
/**
 * Oh My Cache public API.
 *
 * Loaded before the plugin boots, so these functions exist for anything running from
 * plugins_loaded onward, including plugins that load earlier than this one.
 *
 * Function declarations only. No add_action, no get_option, no translatable strings: a __()
 * call before `init` trips the _load_textdomain_just_in_time notice added in WordPress 6.7.
 *
 * Every function is safe to call while the plugin is inactive or half-initialised, returning a
 * rejected ticket rather than fataling. Callers who would rather not depend on the plugin at all
 * can use the equivalent actions:
 *
 *     do_action( 'oh_my_cache_purge_url', $urls, $args );
 *     do_action( 'oh_my_cache_purge_post', $post_id, $args );
 *     do_action( 'oh_my_cache_purge_all', $args );
 *
 * Shared $args:
 *   drivers  array  Restrict to 'nginx', 'redis', 'cloudflare'. Default ['all'].
 *   mode     string 'realtime', 'queue', or 'now' (synchronous, ignores the inline budget).
 *   priority int    Lower runs first in the queue. Default 10.
 *   reason   string Free-form label shown on the queue screen.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'oh_my_cache_purge_url' ) ) {
	/**
	 * Purge one or more specific URLs.
	 *
	 * @param string|array<int, string> $urls One URL or a list.
	 * @param array<string, mixed>      $args Options; see the file header.
	 * @return \OhMyCache\Api\PurgeTicket
	 */
	function oh_my_cache_purge_url( string|array $urls, array $args = [] ): \OhMyCache\Api\PurgeTicket {
		return \OhMyCache\Api\Facade::purge_url( $urls, $args );
	}
}

if ( ! function_exists( 'oh_my_cache_purge_post' ) ) {
	/**
	 * Purge everything a post touches: its permalink, archives, terms, feeds and image sizes.
	 *
	 * @param int|\WP_Post         $post Post or post id.
	 * @param array<string, mixed> $args Options; see the file header.
	 * @return \OhMyCache\Api\PurgeTicket
	 */
	function oh_my_cache_purge_post( int|\WP_Post $post, array $args = [] ): \OhMyCache\Api\PurgeTicket {
		return \OhMyCache\Api\Facade::purge_post( $post, $args );
	}
}

if ( ! function_exists( 'oh_my_cache_purge_term' ) ) {
	/**
	 * Purge a term archive and its feeds.
	 *
	 * @param int                  $term_id  Term id.
	 * @param string               $taxonomy Taxonomy name.
	 * @param array<string, mixed> $args     Options; see the file header.
	 * @return \OhMyCache\Api\PurgeTicket
	 */
	function oh_my_cache_purge_term( int $term_id, string $taxonomy, array $args = [] ): \OhMyCache\Api\PurgeTicket {
		return \OhMyCache\Api\Facade::purge_term( $term_id, $taxonomy, $args );
	}
}

if ( ! function_exists( 'oh_my_cache_purge_pattern' ) ) {
	/**
	 * Purge by wildcard pattern.
	 *
	 * Honoured only by drivers that can express it. Redis turns it into a bounded SCAN. NGINX in
	 * unlink mode cannot do it at all, because its cache key is an md5 hash with no prefix to
	 * match, and it reports a skip with that reason rather than a success it did not achieve.
	 * Cloudflare needs an Enterprise plan for prefix purging.
	 *
	 * @param string               $pattern Pattern, may contain *.
	 * @param array<string, mixed> $args    Options; see the file header.
	 * @return \OhMyCache\Api\PurgeTicket
	 */
	function oh_my_cache_purge_pattern( string $pattern, array $args = [] ): \OhMyCache\Api\PurgeTicket {
		return \OhMyCache\Api\Facade::purge_pattern( $pattern, $args );
	}
}

if ( ! function_exists( 'oh_my_cache_purge_all' ) ) {
	/**
	 * Purge everything.
	 *
	 * @param array<string, mixed> $args Options; see the file header.
	 * @return \OhMyCache\Api\PurgeTicket
	 */
	function oh_my_cache_purge_all( array $args = [] ): \OhMyCache\Api\PurgeTicket {
		return \OhMyCache\Api\Facade::purge_all( $args );
	}
}

if ( ! function_exists( 'oh_my_cache_is_active' ) ) {
	/**
	 * Whether the plugin is running with at least one usable driver.
	 */
	function oh_my_cache_is_active(): bool {
		return \OhMyCache\Api\Facade::is_active();
	}
}

if ( ! function_exists( 'oh_my_cache_register_trigger' ) ) {
	/**
	 * Purge when some other plugin's hook fires, without writing a callback.
	 *
	 * Example:
	 *
	 *     oh_my_cache_register_trigger( 'woocommerce_variation_set_stock', [
	 *         'urls'   => fn( $variation ) => [ get_permalink( $variation->get_parent_id() ) ],
	 *         'reason' => 'woocommerce: stock',
	 *     ] );
	 *
	 * Mapping keys: urls (callable or array), post (callable or id), all (bool),
	 * reason (string), args (int, hook arguments to pass), priority (int).
	 *
	 * @param string               $hook    Hook name.
	 * @param array<string, mixed> $mapping What to purge when it fires.
	 */
	function oh_my_cache_register_trigger( string $hook, array $mapping ): void {
		$plugin = \OhMyCache\Plugin::instance();

		if ( ! $plugin ) {
			return;
		}

		$plugin->container()->get( 'triggers' )->register( $hook, $mapping );
	}
}

if ( ! function_exists( 'oh_my_cache_register_driver' ) ) {
	/**
	 * Add a purge driver of your own: Varnish, Fastly, LiteSpeed, anything.
	 *
	 * It joins the same queue, backoff, circuit breaker and dashboard as the built-in drivers.
	 * Call this on the oh_my_cache_register_drivers action, or any time before a purge runs.
	 *
	 * @param \OhMyCache\Cache\DriverInterface $driver Driver instance.
	 */
	function oh_my_cache_register_driver( \OhMyCache\Cache\DriverInterface $driver ): void {
		$plugin = \OhMyCache\Plugin::instance();

		if ( ! $plugin ) {
			return;
		}

		$plugin->container()->get( 'drivers' )->register( $driver );
	}
}
