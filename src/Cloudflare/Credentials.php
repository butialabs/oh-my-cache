<?php
/**
 * Cloudflare authentication.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cloudflare;

use OhMyCache\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the API token and, just as importantly, keeps it out of the database when the
 * environment supplies it.
 *
 * API token only. Cloudflare's global API key is not supported and is never asked for: it
 * authenticates as the entire account, cannot be scoped to a single zone or a single permission,
 * and revoking it breaks every other integration that uses it. A token can be scoped to exactly
 * the four permissions this plugin needs and thrown away on its own. Not supporting the key is a
 * deliberate feature, not a gap. Dropping it also removes the need for an account email, which
 * only ever existed to pair with the key.
 *
 * Resolution order is environment, then constant, then option. When either of the first two
 * wins, the token is never written to wp_options: the settings form renders no input, and the
 * sanitizer discards any value that arrives in the POST anyway, so a tampered form cannot plant
 * one. The practical payoff is that the token stops appearing in database dumps, backups and
 * staging copies, and becomes something Docker or systemd manages.
 */
final class Credentials {

	/**
	 * API token, from wherever it lives.
	 */
	public static function token(): string {
		return Options::secret( 'cf_api_token' );
	}

	/**
	 * Where the token came from: env, constant, database or unset.
	 */
	public static function token_source(): string {
		return Options::secret_source( 'cf_api_token' );
	}

	/**
	 * Whether the token is supplied by the environment and therefore must not be persisted.
	 */
	public static function token_is_external(): bool {
		return Options::secret_is_external( 'cf_api_token' );
	}

	/**
	 * Whether we have something to authenticate with.
	 */
	public static function configured(): bool {
		return '' !== self::token();
	}

	/**
	 * Authentication headers.
	 *
	 * @return array<string, string>
	 */
	public static function headers(): array {
		$token = self::token();

		return '' === $token ? [] : [ 'Authorization' => 'Bearer ' . $token ];
	}

	/**
	 * Zone id, honouring an environment override before the discovered value.
	 */
	public static function zone_id(): string {
		$external = Options::external( 'cf_zone_id' );

		if ( null !== $external && '' !== $external ) {
			return $external;
		}

		return (string) Options::cf_state( 'zone_id', '' );
	}

	/**
	 * Zone name, for display.
	 */
	public static function zone_name(): string {
		return (string) Options::cf_state( 'zone_name', '' );
	}

	/**
	 * Account id discovered during onboarding.
	 */
	public static function account_id(): string {
		return (string) Options::cf_state( 'account_id', '' );
	}
}
