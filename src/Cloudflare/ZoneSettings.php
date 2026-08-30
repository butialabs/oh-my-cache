<?php
/**
 * Apply and revert the recommended zone settings.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cloudflare;

use OhMyCache\Cloudflare\Exception\ApiException;
use OhMyCache\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * A one-click "make this zone sensible for WordPress".
 *
 * The important part is the snapshot. App for Cloudflare's easy mode is one-way: it overwrites
 * nineteen settings with no record of what was there before, so an operator who did not like the
 * result has no way back. Here the previous values are captured first and revert() puts them
 * back.
 */
final class ZoneSettings {

	public function __construct( private readonly Client $client ) {}

	/**
	 * Settings applied in a single batch PATCH.
	 *
	 * @return array<string, mixed>
	 */
	public static function batch(): array {
		return [
			'0rtt'                     => 'on',
			'browser_cache_ttl'        => 0,
			'cache_level'              => 'aggressive',
			'early_hints'              => 'on',
			'http3'                    => 'on',
			'ip_geolocation'           => 'on',
			'ipv6'                     => 'on',
			'min_tls_version'          => '1.2',
			'opportunistic_encryption' => 'on',
			'opportunistic_onion'      => 'on',
			'pseudo_ipv4'              => 'off',
			'rocket_loader'            => 'off',
			'tls_1_3'                  => 'zrt',
			'websockets'               => 'on',
			'security_level'           => 'medium',
		];
	}

	/**
	 * Settings that live on their own endpoints.
	 *
	 * Several are plan-gated and will answer 4xx on Free, so each is applied individually and a
	 * failure is reported rather than aborting the step.
	 *
	 * @return array<string, array{endpoint: string, body: array<string, mixed>, method: string, label: string}>
	 */
	public static function individual(): array {
		return [
			'nel'                      => [
				'endpoint' => 'settings/nel',
				'body'     => [ 'value' => [ 'enabled' => false ] ],
				'method'   => 'PATCH',
				'label'    => 'Network Error Logging',
			],
			'origin_max_http_version'  => [
				'endpoint' => 'settings/origin_max_http_version',
				'body'     => [ 'value' => '2' ],
				'method'   => 'PATCH',
				'label'    => 'Origin max HTTP version',
			],
			'speed_brain'              => [
				'endpoint' => 'settings/speed_brain',
				'body'     => [ 'value' => 'on' ],
				'method'   => 'PATCH',
				'label'    => 'Speed Brain',
			],
			'fonts'                    => [
				'endpoint' => 'settings/fonts',
				'body'     => [ 'value' => 'on' ],
				'method'   => 'PATCH',
				'label'    => 'Cloudflare Fonts',
			],
			'tiered_caching'           => [
				'endpoint' => 'argo/tiered_caching',
				'body'     => [ 'value' => 'on' ],
				'method'   => 'PATCH',
				'label'    => 'Tiered Cache',
			],
		];
	}

	/**
	 * Apply everything, reporting per-setting outcomes.
	 *
	 * @param string $zone_id Zone id.
	 * @return array<string, array{status: string, label: string, detail: string}>
	 */
	public function apply( string $zone_id ): array {
		$this->snapshot( $zone_id );

		$results = [];
		$batch   = self::batch();

		$items = [];
		foreach ( $batch as $id => $value ) {
			$items[] = [
				'id'    => $id,
				'value' => $value,
			];
		}

		try {
			$this->client->patch_zone_settings( $zone_id, $items );

			foreach ( array_keys( $batch ) as $id ) {
				$results[ $id ] = [
					'status' => 'applied',
					'label'  => $id,
					'detail' => '',
				];
			}
		} catch ( ApiException $e ) {
			foreach ( array_keys( $batch ) as $id ) {
				$results[ $id ] = [
					'status' => 'failed',
					'label'  => $id,
					'detail' => $e->getMessage(),
				];
			}
		}

		foreach ( self::individual() as $id => $spec ) {
			try {
				$this->client->patch_zone_setting( $zone_id, $spec['endpoint'], $spec['body'], $spec['method'] );

				$results[ $id ] = [
					'status' => 'applied',
					'label'  => $spec['label'],
					'detail' => '',
				];
			} catch ( ApiException $e ) {
				/*
				 * A 4xx here almost always means "not available on your plan", which is
				 * information, not an error worth failing the whole step over.
				 */
				$results[ $id ] = [
					'status' => $e->getCode() >= 400 && $e->getCode() < 500 ? 'unavailable' : 'failed',
					'label'  => $spec['label'],
					'detail' => $e->getMessage(),
				];
			}
		}

		return $results;
	}

	/**
	 * Capture the current values of everything we are about to change.
	 *
	 * @param string $zone_id Zone id.
	 */
	public function snapshot( string $zone_id ): void {
		// Never overwrite an existing snapshot: the first one is the true "before".
		if ( ! empty( Options::cf_state( 'settings_before' ) ) ) {
			return;
		}

		try {
			$current = $this->client->get_zone_settings( $zone_id );
		} catch ( ApiException $e ) {
			return;
		}

		$wanted = array_keys( self::batch() );
		$before = [];

		foreach ( $current as $setting ) {
			$id = (string) ( $setting['id'] ?? '' );

			if ( in_array( $id, $wanted, true ) ) {
				$before[ $id ] = $setting['value'] ?? null;
			}
		}

		Options::set_cf_state( [ 'settings_before' => $before ] );
	}

	/**
	 * Put the snapshot back.
	 *
	 * Only the batch settings are reverted. The individual endpoints are left alone on purpose:
	 * they are plan-gated and mostly not harmful, and guessing at their previous state would be
	 * worse than leaving them.
	 *
	 * @param string $zone_id Zone id.
	 * @return bool Whether anything was restored.
	 */
	public function revert( string $zone_id ): bool {
		$before = Options::cf_state( 'settings_before' );

		if ( ! is_array( $before ) || ! $before ) {
			return false;
		}

		$items = [];

		foreach ( $before as $id => $value ) {
			if ( null === $value ) {
				continue;
			}

			$items[] = [
				'id'    => (string) $id,
				'value' => $value,
			];
		}

		if ( ! $items ) {
			return false;
		}

		try {
			$this->client->patch_zone_settings( $zone_id, $items );
		} catch ( ApiException $e ) {
			return false;
		}

		Options::set_cf_state( [ 'settings_before' => [] ] );

		return true;
	}
}
