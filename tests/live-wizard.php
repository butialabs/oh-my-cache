<?php
/**
 * Live wizard test. Run with:
 *
 *     wp eval-file wp-content/plugins/oh-my-cache/tests/live-wizard.php
 *
 * Walks the four setup steps in order and checks each one does what it claims, including the
 * parts that are easy to get wrong by hand: that a step only reports success when its test
 * actually passed, that choosing one local driver switches the other off, and that a later step
 * does not quietly undo an earlier one.
 *
 * @package OhMyCache
 */

// No declare(strict_types) here: wp eval-file evals this file.

use OhMyCache\Cdn\Providers;
use OhMyCache\Plugin;
use OhMyCache\Support\Options;
use OhMyCache\Wizard\Steps\CdnStep;
use OhMyCache\Wizard\Steps\DriverStep;
use OhMyCache\Wizard\Steps\FinishStep;
use OhMyCache\Wizard\Steps\RulesStep;

/** Tally holder; `global` does not reach outer scope under wp eval-file. */
final class OmcWizardTally {
	public static int $pass = 0;
	public static int $fail = 0;
}

function w_check( string $label, $actual, $expected ): void {
	if ( $actual === $expected ) {
		++OmcWizardTally::$pass;
		WP_CLI::log( "  ok   {$label}" );
		return;
	}
	++OmcWizardTally::$fail;
	WP_CLI::log( "  FAIL {$label}" );
	WP_CLI::log( '       expected: ' . var_export( $expected, true ) );
	WP_CLI::log( '       actual:   ' . var_export( $actual, true ) );
}

function w_true( string $label, $actual ): void {
	w_check( $label, (bool) $actual, true );
}

$container = Plugin::instance()->container();

// Clean slate.
$settings                                     = Options::all();
$settings['drivers']['nginx']['enabled']      = false;
$settings['drivers']['redis']['enabled']      = false;
$settings['drivers']['cloudflare']['enabled'] = false;
$settings['drivers']['redis']['database']     = 9;
$settings['drivers']['redis']['prefix']       = 'oh-my-cache-wizard:';
$settings['cdn']['provider']                  = 'cloudflare';
Options::save( $settings );
Options::set_cf_state(
	[
		'driver_tested' => false,
		'test_purge_ok' => false,
		'zone_id'       => '',
		'zone_name'     => '',
	]
);
Options::flush();

// --- Step 1: driver ---------------------------------------------------------------------------

WP_CLI::log( '== step 1: driver ==' );

$driver = new DriverStep( $container );

w_check( 'starts incomplete', $driver->is_complete(), false );
w_true( 'cannot be skipped', ! $driver->can_skip() );

// A driver that cannot work must not let you past.
$settings                               = Options::all();
$settings['drivers']['redis']['prefix'] = '';
Options::save( $settings );
Options::flush();

$result = $driver->apply( [ 'local_driver' => 'redis' ] );

w_check( 'a broken configuration fails the test', $result->ok, false );
w_check( 'and does not mark the step done', $driver->is_complete(), false );
WP_CLI::log( '  refused with: ' . $result->message );

// Now a working one.
$result = $driver->apply(
	[
		'local_driver' => 'redis',
		'redis_prefix' => 'oh-my-cache-wizard:',
	]
);

w_true( 'a working configuration passes', $result->ok );
WP_CLI::log( '  ' . $result->message );
w_true( 'step marked done', $driver->is_complete() );
w_check( 'redis is on', Options::get( 'drivers.redis.enabled' ), true );
w_check( 'nginx is off: the two are mutually exclusive', Options::get( 'drivers.nginx.enabled' ), false );

// Switching to nginx must switch redis off, even though nginx will fail here.
$result = $driver->apply( [ 'local_driver' => 'nginx' ] );

w_check( 'redis switched off by choosing nginx', Options::get( 'drivers.redis.enabled' ), false );
w_check( 'nginx selected', Options::get( 'drivers.nginx.enabled' ), true );
w_check( 'but nginx has no cache folder here, so the test fails', $result->ok, false );

// Back to a working state for the rest of the run.
$driver->apply(
	[
		'local_driver' => 'redis',
		'redis_prefix' => 'oh-my-cache-wizard:',
	]
);
Options::flush();

// --- Step 2: CDN ------------------------------------------------------------------------------

WP_CLI::log( '== step 2: cdn ==' );

$cdn = new CdnStep( $container );

w_true( 'can be skipped', $cdn->can_skip() );
w_true( 'cloudflare is offered', array_key_exists( 'cloudflare', Providers::all() ) );

// Choosing Cloudflare without a token must not pretend to succeed.
$result = $cdn->apply( [ 'cdn_provider' => 'cloudflare' ] );
w_check( 'cloudflare without a token fails', $result->ok, false );
WP_CLI::log( '  refused with: ' . $result->message );

// Choosing none is a legitimate answer.
$result = $cdn->apply( [ 'cdn_provider' => 'none' ] );
w_true( 'choosing none succeeds', $result->ok );
w_check( 'cloudflare driver switched off', Options::get( 'drivers.cloudflare.enabled' ), false );
w_check( 'the choice is stored in settings', Providers::current(), 'none' );

// The critical regression: a later step must not undo step 1.
Options::flush();
w_check( 'redis still on after the CDN step', Options::get( 'drivers.redis.enabled' ), true );

// --- Step 3: rules ----------------------------------------------------------------------------

WP_CLI::log( '== step 3: caching ==' );

$rules = new RulesStep( $container );

w_true( 'can be skipped', $rules->can_skip() );

$result = $rules->apply( [ 'cache_guest' => '1' ] );
w_check( 'without a zone it refuses rather than erroring', $result->ok, false );
WP_CLI::log( '  refused with: ' . $result->message );

Options::flush();
w_check( 'redis still on after the caching step', Options::get( 'drivers.redis.enabled' ), true );

// --- Step 4: the real purge -------------------------------------------------------------------

WP_CLI::log( '== step 4: test ==' );

$finish = new FinishStep( $container );

w_check( 'starts incomplete', $finish->is_complete(), false );

$result = $finish->apply( [] );

w_true( 'the test purge succeeded', $result->ok );
WP_CLI::log( '  ' . $result->message );

foreach ( $result->details as $detail ) {
	WP_CLI::log( '    ' . $detail['label'] . ': ' . $detail['status'] . ' — ' . $detail['detail'] );
}

w_true( 'step marked done', $finish->is_complete() );
w_true( 'a non-zero edge TTL is now unlocked', (bool) Options::cf_state( 'test_purge_ok', false ) );

// --- The edge TTL interlock ---------------------------------------------------------------------

WP_CLI::log( '== edge TTL interlock ==' );

Options::set_cf_state( [ 'test_purge_ok' => false ] );
Options::flush();

$rules->apply(
	[
		'cache_guest' => '1',
		'edge_ttl'    => '600',
	]
);
Options::flush();

w_check(
	'a TTL is refused until a purge has been proved to work',
	(int) Options::get( 'edge.ttl_seconds' ),
	0
);

// --- Clean up -----------------------------------------------------------------------------------

$settings                                = Options::all();
$settings['drivers']['redis']['prefix']  = 'oh-my-cache-live:';
$settings['edge']['ttl_seconds']         = 0;
Options::save( $settings );
Options::set_cf_state( [ 'test_purge_ok' => false, 'driver_tested' => false ] );

WP_CLI::log( '' );
WP_CLI::log( OmcWizardTally::$pass . ' passed, ' . OmcWizardTally::$fail . ' failed' );

if ( OmcWizardTally::$fail > 0 ) {
	WP_CLI::halt( 1 );
}
