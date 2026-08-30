<?php
/**
 * CDN providers.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cdn;

use OhMyCache\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Which CDNs this install can be pointed at.
 *
 * Cloudflare is the only one today, so this is a registry of one. It earns its place because the
 * wizard and the settings screen ask the same question and would otherwise store the answer in
 * two places.
 *
 * A second provider means a driver whose is_cdn() returns true, plus a label through the filter
 * below. Neither screen needs to know it happened.
 */
final class Providers {

	public const NONE = 'none';

	public const DEFAULT_PROVIDER = 'cloudflare';

	/**
	 * Provider id to label.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		/**
		 * Filters the CDN providers on offer.
		 *
		 * @param array<string, string> $providers Provider id to label.
		 */
		$providers = (array) apply_filters(
			'oh_my_cache_cdn_providers',
			[
				self::DEFAULT_PROVIDER => __( 'Cloudflare', 'oh-my-cache' ),
				self::NONE             => __( 'None, or one this plugin does not manage', 'oh-my-cache' ),
			]
		);

		return array_filter( $providers, 'is_string' );
	}

	/**
	 * The selected provider.
	 *
	 * An empty stored value falls back to the default, not only a missing key. A key written as an
	 * empty string would leave every radio unchecked, and the form would post nothing.
	 */
	public static function current(): string {
		$provider = (string) Options::get( 'cdn.provider', '' );

		if ( '' === $provider || ! array_key_exists( $provider, self::all() ) ) {
			return self::DEFAULT_PROVIDER;
		}

		return $provider;
	}

	/**
	 * Whether a CDN is being managed at all.
	 */
	public static function is_managed(): bool {
		return self::NONE !== self::current();
	}

	/**
	 * Human label for a provider id, falling back to the id itself.
	 *
	 * @param string $id Provider id.
	 */
	public static function label( string $id ): string {
		return self::all()[ $id ] ?? $id;
	}

	/**
	 * Validate a submitted provider id.
	 *
	 * @param mixed $id Raw value.
	 */
	public static function sanitize( mixed $id ): string {
		$id = sanitize_key( (string) $id );

		return array_key_exists( $id, self::all() ) ? $id : self::DEFAULT_PROVIDER;
	}
}
