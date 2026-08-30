<?php
/**
 * Cache-Control for guest HTML.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Http;

use OhMyCache\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Tells Cloudflare how long it may keep a page.
 *
 * The guest Cache Rule and this header are one feature, not two. The rule decides which
 * requests are cacheable; s-maxage from the origin decides for how long. Ship the rule without
 * the header and the edge caches nothing useful; ship the header without the rule and
 * Cloudflare ignores it for HTML.
 *
 * Driving the TTL from the origin also means changing it costs no API call, which matters
 * because the alternative is editing a Cloudflare rule every time.
 */
final class EdgeHeaders {

	public static function register(): void {
		/*
		 * template_redirect, not wp_headers, and the difference is a cached shopping cart.
		 *
		 * wp_headers fires inside WP::send_headers(), before the `wp` action. WooCommerce refuses
		 * to answer is_cart() and is_checkout() before that (CartCheckoutUtils::is_page_type()
		 * returns null until did_action('wp')) and memoises the false it hands back, so asking
		 * early both caches the cart and gives every other plugin in the request a wrong answer.
		 *
		 * template_redirect runs after `wp` and before output: the conditional tags are correct
		 * and there is still time to send a header. Neither hook fires for wp-admin or wp-login.
		 */
		add_action( 'template_redirect', [ self::class, 'send' ], 100 );
	}

	/**
	 * Stamp the header, if this response may be cached at the edge.
	 */
	public static function send(): void {
		$ttl = (int) Options::get( 'edge.ttl_seconds', 0 );

		if ( $ttl < 1 || headers_sent() ) {
			return;
		}

		if ( self::is_uncacheable() ) {
			return;
		}

		header( 'Cache-Control: max-age=0, s-maxage=' . $ttl );
	}

	/**
	 * Whether this response must not be cached at the edge.
	 */
	private static function is_uncacheable(): bool {
		$no_cache = is_user_logged_in();

		if ( ! $no_cache ) {
			$no_cache = self::has_identifying_cookie();
		}

		// Conditional tags, not a Content-Type header: the response has none yet.
		if ( ! $no_cache && ( is_feed() || is_robots() || is_trackback() || is_embed() ) ) {
			$no_cache = true;
		}

		if ( ! $no_cache && ( is_404() || is_preview() || is_search() ) ) {
			$no_cache = true;
		}

		// A URL WooCommerce answers over AJAX from the front page, such as the cart fragments.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $no_cache && isset( $_GET['wc-ajax'] ) ) {
			$no_cache = true;
		}

		if ( ! $no_cache && ! empty( $_SERVER['REQUEST_METHOD'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$method = strtoupper( (string) $_SERVER['REQUEST_METHOD'] );

			if ( 'GET' !== $method && 'HEAD' !== $method ) {
				$no_cache = true;
			}
		}

		if ( ! $no_cache && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$no_cache = true;
		}

		if ( ! $no_cache && self::is_woocommerce_personal() ) {
			$no_cache = true;
		}

		/**
		 * Filters whether the current response should skip edge caching.
		 *
		 * @param bool $no_cache Whether to skip.
		 */
		$no_cache = (bool) apply_filters( 'oh_my_cache_no_cache', $no_cache );

		/*
		 * Unprefixed on purpose: this is App for Cloudflare's own filter name, honoured so a site
		 * migrating from it keeps the rule deciding which pages must never be cached. Losing that
		 * rule serves a logged-in page to the public, so compatibility matters more here than a
		 * naming convention.
		 */

		/** This filter is documented in App for Cloudflare. */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		return (bool) apply_filters( 'app_for_cf_no_cache', $no_cache );
	}

	/**
	 * Whether a cookie marks this visitor as non-anonymous.
	 *
	 * Kept in step with the Cache Rule expression: if the edge decides on cookies, the origin
	 * has to decide on the same cookies or the two disagree about what "a guest" means.
	 */
	private static function has_identifying_cookie(): bool {
		if ( empty( $_COOKIE ) || ! is_array( $_COOKIE ) ) {
			return false;
		}

		foreach ( array_keys( $_COOKIE ) as $name ) {
			$name = (string) $name;

			foreach ( self::cookie_prefixes() as $prefix ) {
				if ( str_starts_with( $name, $prefix ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Cookie prefixes that mean "this visitor gets a personalised page".
	 *
	 * @return array<int, string>
	 */
	public static function cookie_prefixes(): array {
		/**
		 * Filters the cookie prefixes that make a response uncacheable.
		 *
		 * Changing this must be mirrored in the Cloudflare Cache Rule expression, which the
		 * wizard rebuilds from the same list.
		 *
		 * @param array<int, string> $prefixes Cookie name prefixes.
		 */
		return (array) apply_filters(
			'oh_my_cache_guest_cookie_prefixes',
			[ 'wp-', 'wordpress_', 'comment_', 'woocommerce_', 'wp_woocommerce_session_' ]
		);
	}

	/**
	 * WooCommerce pages that are personal by definition: a cart, a checkout, an account.
	 */
	private static function is_woocommerce_personal(): bool {
		/*
		 * Only correct once `wp` has fired; see register(). is_wc_endpoint_url() covers the pages
		 * with no page id of their own, such as order-received and view-order.
		 */
		foreach ( [ 'is_cart', 'is_checkout', 'is_account_page', 'is_wc_endpoint_url' ] as $check ) {
			if ( function_exists( $check ) && $check() ) {
				return true;
			}
		}

		return false;
	}
}
