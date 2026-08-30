<?php
/**
 * Live credential-storage test. Run twice, once without and once with the environment variable:
 *
 *     wp eval-file wp-content/plugins/oh-my-cache/tests/live-secrets.php
 *     OH_MY_CACHE_CF_API_TOKEN=from-the-environment wp eval-file wp-content/plugins/oh-my-cache/tests/live-secrets.php
 *
 * Proves the rule the whole credential design rests on: when the environment supplies a token,
 * it is never written to the database, and a value posted anyway is discarded rather than
 * persisted. That last part is what stops a tampered form from planting a token.
 *
 * @package OhMyCache
 */

// No declare(strict_types) here: wp eval-file evals this file.

use OhMyCache\Cloudflare\Credentials;
use OhMyCache\Support\Options;

/** Tally holder; `global` does not reach outer scope under wp eval-file. */
final class OmcSecretTally {
	public static int $pass = 0;
	public static int $fail = 0;
}

function s_check( string $label, $actual, $expected ): void {
	if ( $actual === $expected ) {
		++OmcSecretTally::$pass;
		WP_CLI::log( "  ok   {$label}" );
		return;
	}
	++OmcSecretTally::$fail;
	WP_CLI::log( "  FAIL {$label}" );
	WP_CLI::log( '       expected: ' . var_export( $expected, true ) );
	WP_CLI::log( '       actual:   ' . var_export( $actual, true ) );
}

function s_true( string $label, $actual ): void {
	s_check( $label, (bool) $actual, true );
}

/** Read the raw option, bypassing every accessor, to see what really landed in the database. */
function stored_token(): string {
	$raw = get_option( Options::OPTION_SECRETS, [] );

	return is_array( $raw ) && isset( $raw['cf_api_token'] ) ? (string) $raw['cf_api_token'] : '';
}

// Start clean.
delete_option( Options::OPTION_SECRETS );

$from_env = getenv( 'OH_MY_CACHE_CF_API_TOKEN' );
$has_env  = is_string( $from_env ) && '' !== $from_env;

WP_CLI::log( $has_env ? '== environment supplies a token ==' : '== no environment token ==' );

if ( ! $has_env ) {
	WP_CLI::log( '  (run again with OH_MY_CACHE_CF_API_TOKEN set to cover the other half)' );

	s_check( 'source is unset to begin with', Credentials::token_source(), 'unset' );
	s_true( 'not configured', ! Credentials::configured() );

	Options::save_secrets( [ 'cf_api_token' => 'token-typed-into-the-form' ] );

	s_check( 'source is the database', Credentials::token_source(), 'database' );
	s_check( 'value round-trips', Credentials::token(), 'token-typed-into-the-form' );
	s_check( 'it really is in the database', stored_token(), 'token-typed-into-the-form' );
	s_true( 'not treated as external', ! Credentials::token_is_external() );

	$headers = Credentials::headers();
	s_check( 'bearer header built', $headers['Authorization'] ?? '', 'Bearer token-typed-into-the-form' );

	// Leave the database copy behind so the env run can detect it as an orphan.
	WP_CLI::log( '  left a database token in place for the second run' );
} else {
	// Simulate an install that already had one stored before moving to the environment.
	update_option( Options::OPTION_SECRETS, [ 'cf_api_token' => 'stale-database-copy' ], false );

	s_check( 'environment wins', Credentials::token(), $from_env );
	s_check( 'source reported as env', Credentials::token_source(), 'env' );
	s_true( 'reported as external', Credentials::token_is_external() );

	// The rule: a posted value must not be persisted while the environment supplies one.
	Options::save_secrets( [ 'cf_api_token' => 'value-posted-by-a-tampered-form' ] );

	s_check( 'the posted value was discarded, not stored', stored_token(), 'stale-database-copy' );
	s_check( 'the resolved token is still the environment one', Credentials::token(), $from_env );

	$orphans = Options::orphaned_secrets();
	s_true( 'the stale database copy is reported as an orphan', in_array( 'cf_api_token', $orphans, true ) );

	Options::forget_stored_secret( 'cf_api_token' );
	s_check( 'orphan can be deleted', stored_token(), '' );
	s_check( 'environment token survives the deletion', Credentials::token(), $from_env );

	$headers = Credentials::headers();
	s_check( 'bearer header uses the environment token', $headers['Authorization'] ?? '', 'Bearer ' . $from_env );
}

delete_option( Options::OPTION_SECRETS );

WP_CLI::log( '' );
WP_CLI::log( OmcSecretTally::$pass . ' passed, ' . OmcSecretTally::$fail . ' failed' );

if ( OmcSecretTally::$fail > 0 ) {
	WP_CLI::halt( 1 );
}
