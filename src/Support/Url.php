<?php
/**
 * URL normalisation and cache-key construction.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Support;

defined( 'ABSPATH' ) || exit;

/**
 * One place that decides what a URL looks like.
 *
 * The NGINX and Redis drivers must agree byte for byte on the cache key, or one of them
 * silently purges nothing. Deriving both from the same normalisation and the same key template
 * is what keeps them honest.
 */
final class Url {

	/**
	 * Canonical form of a URL for purging.
	 *
	 * Scheme comes from home_url() rather than the input, because a site behind Cloudflare
	 * Flexible SSL sees http internally and https externally, and the cache key uses one of
	 * them consistently. Host is lowercased; the path is not, because paths are case sensitive.
	 *
	 * @param string $url Absolute or root-relative URL.
	 * @return string Empty string when the URL is unusable.
	 */
	public static function normalize( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		// Root-relative input is resolved against the site.
		if ( str_starts_with( $url, '/' ) ) {
			$url = home_url( $url );
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? self::site_scheme() ) );
		$host   = strtolower( (string) $parts['host'] );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = (string) ( $parts['path'] ?? '/' );

		if ( '' === $path ) {
			$path = '/';
		}

		$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';

		// Fragments never reach the server, so they cannot be part of a cache key.
		return $scheme . '://' . $host . $port . $path . $query;
	}

	/**
	 * Normalise a list, dropping empties and duplicates while preserving order.
	 *
	 * @param array<int, mixed> $urls Raw URLs.
	 * @return array<int, string>
	 */
	public static function normalize_all( array $urls ): array {
		$out = [];

		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) ) {
				continue;
			}

			$normalized = self::normalize( $url );
			if ( '' !== $normalized ) {
				$out[ $normalized ] = true;
			}
		}

		return array_keys( $out );
	}

	/**
	 * The scheme the site canonically uses.
	 */
	public static function site_scheme(): string {
		$scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );

		return is_string( $scheme ) && '' !== $scheme ? strtolower( $scheme ) : 'http';
	}

	/**
	 * Whether a URL belongs to this site.
	 *
	 * @param string $url Absolute URL.
	 */
	public static function is_same_host( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) && is_string( $home ) && strtolower( $host ) === strtolower( $home );
	}

	/**
	 * Build the cache key nginx would have used for a URL.
	 *
	 * The template is configurable because nginx_cache_key is configurable. Nginx Helper
	 * hardcodes "$scheme$request_method$host$request_uri" and silently purges nothing on any
	 * server whose proxy_cache_key differs, which is a failure mode with no error message.
	 *
	 * @param string $url      Absolute URL.
	 * @param string $template Key template using nginx variable names.
	 * @param string $method   Request method the cache was populated with.
	 */
	public static function cache_key( string $url, string $template, string $method = 'GET' ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$path        = (string) ( $parts['path'] ?? '/' );
		$query       = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';
		$request_uri = ( '' === $path ? '/' : $path ) . $query;

		$replacements = [
			'$scheme'          => strtolower( (string) ( $parts['scheme'] ?? self::site_scheme() ) ),
			'$request_method'  => strtoupper( $method ),
			'$host'            => strtolower( (string) $parts['host'] ),
			'$http_host'       => strtolower( (string) $parts['host'] ),
			'$request_uri'     => $request_uri,
			'$uri'             => '' === $path ? '/' : $path,
			'$args'            => (string) ( $parts['query'] ?? '' ),
			'$is_args'         => '' === (string) ( $parts['query'] ?? '' ) ? '' : '?',
			'$query_string'    => (string) ( $parts['query'] ?? '' ),
		];

		return strtr( $template, $replacements );
	}

	/**
	 * Feed variants of a URL.
	 *
	 * These live here rather than inside the FastCGI driver on purpose. Nginx Helper appends
	 * feeds only in its FastCGI purger, so its Redis path never purges them: the same edit
	 * purges different URL sets depending on which backend is configured. Enumerating once,
	 * centrally, is what stops the three drivers from disagreeing.
	 *
	 * @param string $url Absolute URL.
	 * @return array<int, string>
	 */
	public static function feed_variants( string $url ): array {
		$base = self::with_trailing_slash( $url );

		return [
			$base . 'feed/',
			$base . 'feed/atom/',
			$base . 'feed/rdf/',
		];
	}

	/**
	 * Add a trailing slash, ignoring any query string.
	 *
	 * @param string $url Absolute URL.
	 */
	public static function with_trailing_slash( string $url ): string {
		$query = '';
		$mark  = strpos( $url, '?' );

		if ( false !== $mark ) {
			$query = substr( $url, $mark );
			$url   = substr( $url, 0, $mark );
		}

		return rtrim( $url, '/' ) . '/' . $query;
	}
}
