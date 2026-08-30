<?php
/**
 * Create, update and remove the plugin's cache rules.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cloudflare;

use OhMyCache\Cloudflare\Exception\ApiException;

defined( 'ABSPATH' ) || exit;

/**
 * Manages exactly two rules: guest HTML and static content.
 *
 * Rules are identified by their description, not by matching the expression.
 * deleteSpecialCacheRule() in App for Cloudflare compares `$rule['expression'] === $expression`,
 * which means the moment the expression changes, or somebody tweaks the rule in the Cloudflare
 * dashboard, the plugin can no longer find its own rule: disabling silently does nothing and
 * re-running onboarding appends a duplicate. A stable description key avoids all of that.
 */
final class CacheRules {

	public const GUEST  = 'guest';
	public const STATIC = 'static';

	private const DESCRIPTIONS = [
		self::GUEST  => 'Oh My Cache: guest HTML',
		self::STATIC => 'Oh My Cache: static content',
	];

	public function __construct( private readonly Client $client ) {}

	/**
	 * The description used to recognise one of our rules.
	 *
	 * @param string $type GUEST or STATIC.
	 */
	public static function description( string $type ): string {
		return self::DESCRIPTIONS[ $type ] ?? '';
	}

	/**
	 * Whether the rule is already installed.
	 *
	 * @param string $zone_id Zone id.
	 * @param string $type    GUEST or STATIC.
	 */
	public function exists( string $zone_id, string $type ): bool {
		return null !== $this->find( $zone_id, $type );
	}

	/**
	 * Locate one of our rules.
	 *
	 * @param string $zone_id Zone id.
	 * @param string $type    GUEST or STATIC.
	 * @return array{ruleset_id: string, rule: array<string, mixed>}|null
	 */
	public function find( string $zone_id, string $type ): ?array {
		$ruleset = $this->client->get_cache_ruleset( $zone_id );

		if ( ! $ruleset || empty( $ruleset['id'] ) ) {
			return null;
		}

		$description = self::description( $type );

		foreach ( (array) ( $ruleset['rules'] ?? [] ) as $rule ) {
			if ( ( $rule['description'] ?? '' ) === $description ) {
				return [
					'ruleset_id' => (string) $ruleset['id'],
					'rule'       => (array) $rule,
				];
			}
		}

		return null;
	}

	/**
	 * Install or update a rule. Safe to run repeatedly.
	 *
	 * @param string $zone_id Zone id.
	 * @param string $type    GUEST or STATIC.
	 * @return array<string, mixed> The rule as Cloudflare stored it.
	 * @throws ApiException When the API refuses.
	 */
	public function apply( string $zone_id, string $type ): array {
		$rule     = $this->build( $type );
		$existing = $this->find( $zone_id, $type );

		if ( null !== $existing ) {
			return $this->client->update_cache_rule(
				$zone_id,
				$existing['ruleset_id'],
				(string) $existing['rule']['id'],
				$rule
			);
		}

		$ruleset = $this->client->get_cache_ruleset( $zone_id );

		// The phase has no entrypoint ruleset yet, which is normal on an untouched zone.
		if ( ! $ruleset || empty( $ruleset['id'] ) ) {
			return $this->client->create_cache_ruleset( $zone_id, [ $rule ] );
		}

		return $this->client->create_cache_rule( $zone_id, (string) $ruleset['id'], $rule );
	}

	/**
	 * Remove a rule, if it is there.
	 *
	 * @param string $zone_id Zone id.
	 * @param string $type    GUEST or STATIC.
	 * @return bool Whether anything was removed.
	 * @throws ApiException When the API refuses.
	 */
	public function remove( string $zone_id, string $type ): bool {
		$existing = $this->find( $zone_id, $type );

		if ( null === $existing ) {
			return false;
		}

		$this->client->delete_cache_rule(
			$zone_id,
			$existing['ruleset_id'],
			(string) $existing['rule']['id']
		);

		return true;
	}

	/**
	 * How many cache rules the zone already has.
	 *
	 * The Free plan allows ten, so it is worth checking before adding an eleventh and getting a
	 * confusing API error.
	 *
	 * @param string $zone_id Zone id.
	 */
	public function count( string $zone_id ): int {
		$ruleset = $this->client->get_cache_ruleset( $zone_id );

		return count( (array) ( $ruleset['rules'] ?? [] ) );
	}

	/* --------------------------------------------------------------------- */

	/**
	 * The rule body.
	 *
	 * @param string $type GUEST or STATIC.
	 * @return array<string, mixed>
	 */
	private function build( string $type ): array {
		if ( self::STATIC === $type ) {
			return [
				'description' => self::description( self::STATIC ),
				'expression'  => Expression::static_content(),
				'action'      => 'set_cache_settings',
				'enabled'     => true,
				'action_parameters' => [
					'cache'       => true,
					'edge_ttl'    => [
						'mode'    => 'override_origin',
						'default' => YEAR_IN_SECONDS,
					],
					'browser_ttl' => [
						'mode'    => 'override_origin',
						'default' => YEAR_IN_SECONDS,
					],
				],
			];
		}

		return [
			'description' => self::description( self::GUEST ),
			'expression'  => Expression::guest_html(),
			'action'      => 'set_cache_settings',
			'enabled'     => true,
			/*
			 * No edge_ttl here on purpose: the TTL comes from the origin's s-maxage header, so
			 * changing it is a settings edit rather than an API call, and the origin and the
			 * edge can never disagree about it.
			 */
			'action_parameters' => [
				'cache' => true,
			],
		];
	}
}
