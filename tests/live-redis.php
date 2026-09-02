<?php
/**
 * Live Redis test. Run with:
 *
 *     wp eval-file wp-content/plugins/oh-my-cache/tests/live-redis.php
 *
 * Seeds a real Redis instance with the exact keys the driver expects, purges, and checks what
 * actually got deleted. This is the test the stubbed suite cannot do: it proves the key the
 * plugin builds is the key that is really there.
 *
 * @package OhMyCache
 */

// No declare(strict_types) here: wp eval-file evals this file, so it cannot be the first
// statement of the script.

use OhMyCache\Cache\RedisDriver;
use OhMyCache\Support\Options;

/**
 * Tally holder.
 *
 * wp eval-file evals this file inside a function, so `global` does not reach the tallies the
 * way it would in a normal script. A static holder sidesteps that.
 */
final class OmcTally {
	public static int $pass = 0;
	public static int $fail = 0;
}

function t_check( string $label, $actual, $expected ): void {
	if ( $actual === $expected ) {
		++OmcTally::$pass;
		WP_CLI::log( "  ok   {$label}" );
		return;
	}
	++OmcTally::$fail;
	WP_CLI::log( "  FAIL {$label}" );
	WP_CLI::log( '       expected: ' . var_export( $expected, true ) );
	WP_CLI::log( '       actual:   ' . var_export( $actual, true ) );
}

function t_true( string $label, $actual ): void {
	t_check( $label, (bool) $actual, true );
}

// --- Configure the driver against the local Redis, on a database of its own. -----------------

$settings                                   = Options::all();
$settings['enabled']                        = true;
$settings['drivers']['redis']['enabled']    = true;
$settings['drivers']['redis']['host']       = '127.0.0.1';
$settings['drivers']['redis']['port']       = 6379;
$settings['drivers']['redis']['database']   = 9;
$settings['drivers']['redis']['prefix']     = 'oh-my-cache-test:';
Options::save( $settings );
Options::flush();

$driver = new RedisDriver();

WP_CLI::log( '== availability ==' );
$availability = $driver->availability();
t_true( 'driver reports available', $availability->ok );

if ( ! $availability->ok ) {
	WP_CLI::error( $availability->reason );
}

$connection = $driver->connection();
$redis      = $connection->connect();

if ( ! $redis instanceof Redis ) {
	WP_CLI::error( 'Could not connect: ' . (string) $connection->error() );
}

t_check( 'talking to database 9', $connection->database(), 9 );
t_true( 'server supports UNLINK', $connection->supports_unlink() );

// Start from a clean slate in our own database.
$redis->flushDB();

// --- Seed keys exactly as the driver would look for them -------------------------------------

WP_CLI::log( '== seeding ==' );

$urls = [
	home_url( '/' ),
	home_url( '/?p=1' ),
	home_url( '/some/page/' ),
];

$keys = [];

foreach ( $urls as $url ) {
	$key          = $driver->key_for( $url );
	$keys[ $url ] = $key;
	$redis->set( $key, 'cached-html' );
}

WP_CLI::log( '  homepage key: ' . $keys[ home_url( '/' ) ] );

// A key belonging to somebody else's prefix: it must survive everything below.
$redis->set( 'other-app:httpsGETwordpress.local/', 'not ours' );

t_check( 'seeded key count', $redis->dbSize(), count( $urls ) + 1 );

// --- Purge specific URLs ----------------------------------------------------------------------

WP_CLI::log( '== purge_urls ==' );

$result = $driver->purge_urls( [ home_url( '/' ), home_url( '/?p=1' ) ] );

t_check( 'two urls purged', count( $result->succeeded() ), 2 );
t_check( 'no failures', $result->failed(), [] );
t_check( 'homepage key gone', $redis->exists( $keys[ home_url( '/' ) ] ), 0 );
t_check( 'untouched url still cached', $redis->exists( $keys[ home_url( '/some/page/' ) ] ), 1 );
t_check( 'other prefix untouched', $redis->exists( 'other-app:httpsGETwordpress.local/' ), 1 );

// --- Purging something that was never cached is a skip, not a failure -------------------------

WP_CLI::log( '== not-cached is a skip ==' );

$result = $driver->purge_urls( [ home_url( '/never-cached/' ) ] );

t_check( 'nothing succeeded', count( $result->succeeded() ), 0 );
t_check( 'nothing failed', count( $result->failed() ), 0 );
t_check( 'one skip', count( $result->skipped() ), 1 );
t_true( 'result reports no failures', ! $result->has_failures() );

// --- Wildcard purge uses SCAN and respects the prefix ------------------------------------------

WP_CLI::log( '== purge_all via SCAN ==' );

for ( $i = 0; $i < 250; $i++ ) {
	$redis->set( 'oh-my-cache-test:httpsGETwordpress.local/bulk/' . $i, 'x' );
}

$before = $redis->dbSize();
$result = $driver->purge_all();

t_true( 'purge_all reported no failures', ! $result->has_failures() );
t_check( 'every prefixed key gone', count( $redis->keys( 'oh-my-cache-test:*' ) ), 0 );
t_check( 'the other prefix survived purge_all', $redis->exists( 'other-app:httpsGETwordpress.local/' ), 1 );

WP_CLI::log( '  swept ' . ( $before - 1 ) . ' keys, summary: ' . $result->summary() );

// --- The object cache interlock -----------------------------------------------------------------

WP_CLI::log( '== safety interlock ==' );

$settings                               = Options::all();
$settings['drivers']['redis']['prefix'] = '';
Options::save( $settings );
Options::flush();

$blank = new RedisDriver();
t_true( 'empty prefix refuses to run', ! $blank->availability()->ok );
WP_CLI::log( '  reason: ' . $blank->availability()->reason );

// Put it back.
$settings['drivers']['redis']['prefix'] = 'oh-my-cache-test:';
Options::save( $settings );
Options::flush();

// --- Clean up ------------------------------------------------------------------------------------

$redis->flushDB();

WP_CLI::log( '' );
WP_CLI::log( OmcTally::$pass . ' passed, ' . OmcTally::$fail . ' failed' );

if ( OmcTally::$fail > 0 ) {
	WP_CLI::halt( 1 );
}
