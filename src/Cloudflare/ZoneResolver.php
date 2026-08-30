<?php
/**
 * Work out which Cloudflare zone this site belongs to.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cloudflare;

use OhMyCache\Cloudflare\Exception\ApiException;
use OhMyCache\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the zone for the site hostname.
 */
final class ZoneResolver {

	public function __construct( private readonly Client $client ) {}

	/**
	 * Resolve and cache the zone.
	 *
	 * @param bool $force Ignore the cached value.
	 * @return array{zone_id: string, zone_name: string, account_id: string, plan: string}|null
	 * @throws ApiException When the API refuses.
	 */
	public function resolve( bool $force = false ): ?array {
		if ( ! $force ) {
			$cached = Options::cf_state();

			if ( is_array( $cached ) && ! empty( $cached['zone_id'] ) ) {
				return [
					'zone_id'    => (string) $cached['zone_id'],
					'zone_name'  => (string) ( $cached['zone_name'] ?? '' ),
					'account_id' => (string) ( $cached['account_id'] ?? '' ),
					'plan'       => (string) ( $cached['plan'] ?? '' ),
				];
			}
		}

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		if ( '' === $host ) {
			return null;
		}

		$zone = $this->walk_labels( $host ) ?? $this->longest_suffix_match( $host );

		if ( null === $zone ) {
			return null;
		}

		$resolved = [
			'zone_id'    => (string) ( $zone['id'] ?? '' ),
			'zone_name'  => (string) ( $zone['name'] ?? '' ),
			'account_id' => (string) ( $zone['account']['id'] ?? '' ),
			'plan'       => strtolower( (string) ( $zone['plan']['legacy_id'] ?? '' ) ),
		];

		Options::set_cf_state( $resolved + [ 'resolved_at' => time() ] );

		return $resolved;
	}

	/**
	 * Walk the hostname label by label, asking for an exact zone name each time.
	 *
	 * a.b.example.com becomes b.example.com, then example.com. Only an exact single match
	 * counts, so a token that can see several zones does not silently pick the wrong one.
	 *
	 * @param string $host Hostname.
	 * @return array<string, mixed>|null
	 */
	private function walk_labels( string $host ): ?array {
		$labels = explode( '.', $host );

		// Stop before a bare TLD; there is no zone called "com".
		while ( count( $labels ) >= 2 ) {
			$candidate = implode( '.', $labels );
			$zones     = $this->client->list_zones( $candidate );

			if ( 1 === count( $zones ) ) {
				return (array) $zones[0];
			}

			array_shift( $labels );
		}

		return null;
	}

	/**
	 * Fall back to the longest visible zone name that is a suffix of the hostname.
	 *
	 * The donor stops at the label walk, which fails for a token scoped to a single zone whose
	 * name it cannot look up by filter. This also gives us enough information to say something
	 * useful when nothing matches, rather than throwing a generic error.
	 *
	 * @param string $host Hostname.
	 * @return array<string, mixed>|null
	 */
	private function longest_suffix_match( string $host ): ?array {
		$zones = $this->client->list_zones();

		$best   = null;
		$length = 0;

		foreach ( $zones as $zone ) {
			$name = strtolower( (string) ( $zone['name'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			if ( $host === $name || str_ends_with( $host, '.' . $name ) ) {
				if ( strlen( $name ) > $length ) {
					$best   = (array) $zone;
					$length = strlen( $name );
				}
			}
		}

		return $best;
	}

	/**
	 * Every zone the token can see, for the manual picker and for error messages.
	 *
	 * @return array<int, array{id: string, name: string}>
	 */
	public function available_zones(): array {
		$zones = [];

		foreach ( $this->client->list_zones() as $zone ) {
			$zones[] = [
				'id'   => (string) ( $zone['id'] ?? '' ),
				'name' => (string) ( $zone['name'] ?? '' ),
			];
		}

		return $zones;
	}
}
