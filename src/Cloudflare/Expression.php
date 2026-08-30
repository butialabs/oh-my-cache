<?php
/**
 * Cloudflare Ruleset expressions.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cloudflare;

use OhMyCache\Http\EdgeHeaders;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the two rule expressions the wizard installs.
 */
final class Expression {

	/**
	 * Requests that may be served from the edge cache.
	 *
	 * Built from the same cookie list the origin uses in EdgeHeaders, because if the edge and
	 * the origin disagree about what counts as a guest, logged-in users get served cached pages
	 * belonging to someone else.
	 *
	 * Three clauses here are missing from App for Cloudflare's version and matter: wp-admin and
	 * wp-json are excluded by path rather than relying on a cookie being present, and the method
	 * is pinned to GET so a POST is never treated as cacheable.
	 */
	public static function guest_html(): string {
		$clauses = [];

		foreach ( EdgeHeaders::cookie_prefixes() as $prefix ) {
			$clauses[] = sprintf( 'not http.cookie contains "%s"', $prefix );
		}

		$clauses[] = 'not http.request.uri.path contains "/wp-login.php"';
		$clauses[] = 'not starts_with(http.request.uri.path, "/wp-admin")';
		$clauses[] = 'not starts_with(http.request.uri.path, "/wp-json")';
		$clauses[] = 'http.request.method eq "GET"';

		foreach ( self::woocommerce_clauses() as $clause ) {
			$clauses[] = $clause;
		}

		/**
		 * Filters the guest HTML cache rule expression.
		 *
		 * @param array<int, string> $clauses Expression clauses, joined with "and".
		 */
		$clauses = (array) apply_filters( 'oh_my_cache_guest_cache_expression', $clauses );

		return '(' . implode( ' and ', $clauses ) . ')';
	}

	/**
	 * Keep a shop's personal pages away from the edge, by path.
	 *
	 * A second lock on a door the origin already holds shut. The cookie clauses above only exclude
	 * a visitor who has a cart, and the first person to open the checkout has no such cookie; if
	 * the origin header ever fails, somebody gets served a checkout built for someone else.
	 *
	 * Paths come from the store's settings, not the English defaults, since a shop in Portuguese
	 * has /carrinho/ and /finalizar-compra/.
	 *
	 * @return array<int, string>
	 */
	public static function woocommerce_clauses(): array {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return [];
		}

		$clauses = [];

		foreach ( [ 'cart', 'checkout', 'myaccount' ] as $page ) {
			$path = self::page_path( (int) wc_get_page_id( $page ) );

			if ( '' !== $path ) {
				$clauses[] = sprintf( 'not starts_with(http.request.uri.path, "%s")', $path );
			}
		}

		// Cart fragments and the other AJAX WooCommerce answers from the front page.
		$clauses[] = 'not http.request.uri.query contains "wc-ajax="';

		return $clauses;
	}

	/**
	 * The path part of a page's permalink, without a trailing slash so it matches both forms.
	 *
	 * @param int $page_id Page id.
	 */
	private static function page_path( int $page_id ): string {
		if ( $page_id < 1 ) {
			return '';
		}

		$link = get_permalink( $page_id );

		if ( ! is_string( $link ) ) {
			return '';
		}

		$path = (string) wp_parse_url( $link, PHP_URL_PATH );
		$path = rtrim( $path, '/' );

		// "/" would exclude the whole site, which is what a misconfigured page id gives.
		return '' === $path ? '' : $path;
	}

	/**
	 * Static assets, matched by extension.
	 *
	 * @return string
	 */
	public static function static_content(): string {
		$extensions = [
			'7z', 'avi', 'avif', 'apk', 'bin', 'bmp', 'bz2', 'class', 'css', 'csv', 'doc',
			'docx', 'dmg', 'ejs', 'eot', 'eps', 'exe', 'flac', 'gif', 'gz', 'ico', 'iso',
			'jar', 'jpg', 'jpeg', 'js', 'mid', 'midi', 'mkv', 'mp3', 'mp4', 'ogg', 'otf',
			'pdf', 'pict', 'pls', 'png', 'ppt', 'pptx', 'ps', 'rar', 'svg', 'svgz', 'swf',
			'tar', 'tif', 'tiff', 'ttf', 'webm', 'webp', 'woff', 'woff2', 'xls', 'xlsx',
			'zip', 'zst',
		];

		/**
		 * Filters the file extensions treated as static content.
		 *
		 * @param array<int, string> $extensions Extensions without the leading dot.
		 */
		$extensions = (array) apply_filters( 'oh_my_cache_static_extensions', $extensions );

		$quoted = array_map( static fn ( string $ext ): string => '"' . $ext . '"', $extensions );

		return '(http.request.uri.path.extension in {' . implode( ' ', $quoted ) . '})';
	}
}
