<?php
/**
 * Requests this site makes to itself.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Http;

use OhMyCache\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Arguments for fetching one of our own URLs.
 *
 * This class exists for certificate verification. A staging site behind a self-signed certificate
 * fails every loopback request, and since a failed fetch here returns an empty list, the symptom
 * is not an error but a feature that quietly does nothing. Core answers the same problem when
 * spawning cron, with the https_local_ssl_verify filter.
 */
final class Loopback {

	/**
	 * @param string               $url   URL about to be fetched.
	 * @param array<string, mixed> $extra Additional wp_remote_get arguments.
	 * @return array<string, mixed>
	 */
	public static function args( string $url, array $extra = [] ): array {
		$args = [ 'timeout' => 10 ];

		if ( Url::is_same_host( $url ) ) {
			/*
			 * Core's filter, not ours, so a site that already sets it for cron gets the same
			 * answer here.
			 *
			 * This filter is documented in wp-includes/cron.php
			 */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$args['sslverify'] = (bool) apply_filters( 'https_local_ssl_verify', false );
		}

		return array_merge( $args, $extra );
	}
}
