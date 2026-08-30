<?php
/**
 * Live Cloudflare test against fixtures. Run with:
 *
 *     wp eval-file wp-content/plugins/oh-my-cache/tests/live-cloudflare.php
 *
 * The API is never actually contacted. Responses are injected through pre_http_request, so this
 * exercises the real WordPress HTTP plumbing, the real client, the real error classification and
 * the real queue, while staying deterministic and safe to run on a laptop with no zone.
 *
 * The cases that matter are the ones the donor plugins get wrong: a 429 must be retryable and
 * must honour Retry-After, a bad token must not burn six attempts, and error codes 971 and 1134
 * must count as "nothing to purge" rather than failure.
 *
 * @package OhMyCache
 */

// No declare(strict_types) here: wp eval-file evals this file.

use OhMyCache\Cache\CloudflareDriver;
use OhMyCache\Cache\Cooldown;
use OhMyCache\Cloudflare\Client;
use OhMyCache\Cloudflare\Exception\AuthException;
use OhMyCache\Cloudflare\Exception\ClientException;
use OhMyCache\Cloudflare\Exception\RateLimitException;
use OhMyCache\Cloudflare\Exception\ServerException;
use OhMyCache\Cloudflare\Exception\TransportException;
use OhMyCache\Plugin;
use OhMyCache\Queue\JobStatus;
use OhMyCache\Support\Options;

/** Tally holder; `global` does not reach outer scope under wp eval-file. */
final class OmcCfTally {
	public static int $pass = 0;
	public static int $fail = 0;
	/** @var array<string, mixed> */
	public static array $next = [];
	public static int $calls = 0;
	public static string $last_body = '';
}

function c_check( string $label, $actual, $expected ): void {
	if ( $actual === $expected ) {
		++OmcCfTally::$pass;
		WP_CLI::log( "  ok   {$label}" );
		return;
	}
	++OmcCfTally::$fail;
	WP_CLI::log( "  FAIL {$label}" );
	WP_CLI::log( '       expected: ' . var_export( $expected, true ) );
	WP_CLI::log( '       actual:   ' . var_export( $actual, true ) );
}

function c_true( string $label, $actual ): void {
	c_check( $label, (bool) $actual, true );
}

/**
 * Queue the response the next API call will receive.
 *
 * @param int   $status  HTTP status.
 * @param array $body    Decoded body.
 * @param array $headers Response headers.
 */
function cf_will_answer( int $status, array $body, array $headers = [] ): void {
	OmcCfTally::$next = [
		'status'  => $status,
		'body'    => $body,
		'headers' => $headers,
	];
}

/** Make the next call fail at the transport layer. */
function cf_will_fail_transport(): void {
	OmcCfTally::$next = [ 'wp_error' => true ];
}

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( ! str_contains( (string) $url, 'api.cloudflare.com' ) ) {
			return $preempt;
		}

		++OmcCfTally::$calls;
		OmcCfTally::$last_body = (string) ( $args['body'] ?? '' );

		$next             = OmcCfTally::$next;
		OmcCfTally::$next = [];

		if ( ! $next ) {
			return new WP_Error( 'omc_test', 'No fixture queued for this call.' );
		}

		if ( ! empty( $next['wp_error'] ) ) {
			return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
		}

		return [
			'headers'  => $next['headers'],
			'body'     => wp_json_encode( $next['body'] ),
			'response' => [
				'code'    => $next['status'],
				'message' => 'x',
			],
			'cookies'  => [],
			'filename' => null,
		];
	},
	10,
	3
);

// --- Configure a zone and a token so the driver considers itself usable -------------------------

$settings                                     = Options::all();
$settings['enabled']                          = true;
$settings['drivers']['cloudflare']['enabled'] = true;
Options::save( $settings );
Options::flush();

Options::save_secrets( [ 'cf_api_token' => 'test-token-not-a-real-one' ] );
Options::set_cf_state(
	[
		'zone_id'   => 'testzone123',
		'zone_name' => 'wordpress.local',
		'plan'      => 'free',
	]
);

$client = new Client( 5.0 );
$driver = new CloudflareDriver( $client );

WP_CLI::log( '== driver availability ==' );
c_true( 'driver is available with a token and a zone', $driver->availability()->ok );
c_check( 'free plan chunks at 30', $driver->max_urls_per_job(), 30 );
c_true( 'wildcards unsupported below enterprise', ! $driver->supports_wildcards() );

// --- Error classification -------------------------------------------------------------------------

WP_CLI::log( '== error classification ==' );

$cases = [
	[ 401, [ 'success' => false, 'errors' => [ [ 'code' => 10000, 'message' => 'Authentication error' ] ] ], AuthException::class, false ],
	[ 403, [ 'success' => false, 'errors' => [ [ 'code' => 9109, 'message' => 'Forbidden' ] ] ], AuthException::class, false ],
	[ 400, [ 'success' => false, 'errors' => [ [ 'code' => 1012, 'message' => 'Bad request' ] ] ], ClientException::class, false ],
	[ 500, [ 'success' => false, 'errors' => [ [ 'code' => 0, 'message' => 'Internal error' ] ] ], ServerException::class, true ],
	[ 429, [ 'success' => false, 'errors' => [ [ 'code' => 10000, 'message' => 'Rate limited' ] ] ], RateLimitException::class, true ],
];

foreach ( $cases as [ $status, $body, $expected_class, $retryable ] ) {
	cf_will_answer( $status, $body );

	try {
		$client->purge_cache( 'testzone123', [ 'purge_everything' => true ] );
		c_check( "HTTP {$status} threw", 'no exception', $expected_class );
	} catch ( \Throwable $e ) {
		c_check( "HTTP {$status} maps to " . substr( strrchr( $expected_class, '\\' ), 1 ), get_class( $e ), $expected_class );
		c_check( "HTTP {$status} retryable flag", $e->is_retryable(), $retryable );
	}
}

WP_CLI::log( '== 429 carries Retry-After ==' );
cf_will_answer(
	429,
	[ 'success' => false, 'errors' => [ [ 'code' => 10000, 'message' => 'Rate limited' ] ] ],
	[ 'retry-after' => '42' ]
);

try {
	$client->purge_cache( 'testzone123', [ 'purge_everything' => true ] );
	c_check( 'threw', false, true );
} catch ( RateLimitException $e ) {
	c_check( 'Retry-After parsed', $e->retry_after(), 42 );
}

/*
 * Regression guard. The skip-code check used to run before anything looked at the HTTP status,
 * so a 429 whose body happened to carry code 971 was quietly downgraded to "these URLs are not
 * in your zone". That lost the purge and left the circuit breaker closed, which is the exact
 * failure this plugin exists to prevent.
 */
WP_CLI::log( '== a 429 is never downgraded to a skip ==' );

Cooldown::close( 'cloudflare' );
cf_will_answer(
	429,
	[ 'success' => false, 'errors' => [ [ 'code' => 971, 'message' => 'Rate limited' ] ] ],
	[ 'retry-after' => '15' ]
);

$result = $driver->purge_urls( [ 'https://wordpress.local/limited/' ] );

c_true( 'reported as a failure, not a skip', $result->has_failures() );
c_check( 'no skips', count( $result->skipped() ), 0 );
c_true( 'retryable', $result->is_retryable() );
c_check( 'Retry-After survives into the result', $result->get_retry_after(), 15 );

WP_CLI::log( '== transport failure is retryable ==' );
cf_will_fail_transport();

try {
	$client->purge_cache( 'testzone123', [ 'purge_everything' => true ] );
	c_check( 'threw', false, true );
} catch ( \Throwable $e ) {
	c_check( 'maps to TransportException', get_class( $e ), TransportException::class );
	c_true( 'retryable', $e->is_retryable() );
}

WP_CLI::log( '== HTTP 200 with success:false is still a failure ==' );
cf_will_answer( 200, [ 'success' => false, 'errors' => [ [ 'code' => 1012, 'message' => 'Nope' ] ] ] );

try {
	$client->purge_cache( 'testzone123', [ 'purge_everything' => true ] );
	c_check( '200 with success:false threw', false, true );
} catch ( \Throwable $e ) {
	c_true( '200 with success:false is treated as an error', $e instanceof ClientException );
}

// --- Codes 971 and 1134 are skips, not failures -----------------------------------------------------

WP_CLI::log( '== 971 and 1134 are skips ==' );

cf_will_answer( 400, [ 'success' => false, 'errors' => [ [ 'code' => 971, 'message' => 'Not in zone' ] ] ] );

$result = $driver->purge_urls( [ 'https://elsewhere.example/a/' ] );

c_true( 'no failures', ! $result->has_failures() );
c_check( 'one skip', count( $result->skipped() ), 1 );
WP_CLI::log( '  summary: ' . $result->summary() );

// --- A successful purge reports every URL in the chunk ------------------------------------------------

WP_CLI::log( '== successful purge ==' );

OmcCfTally::$calls = 0;
cf_will_answer( 200, [ 'success' => true, 'result' => [ 'id' => 'testzone123' ] ] );

$result = $driver->purge_urls( [ 'https://wordpress.local/a/', 'https://wordpress.local/b/' ] );

c_check( 'one API call for the chunk', OmcCfTally::$calls, 1 );
c_check( 'both urls reported purged', count( $result->succeeded() ), 2 );
c_true( 'request body carries files', str_contains( OmcCfTally::$last_body, 'files' ) );

// --- The chunk is the unit of failure ------------------------------------------------------------------

WP_CLI::log( '== a failed chunk fails all of its urls, and only those ==' );

cf_will_answer( 500, [ 'success' => false, 'errors' => [ [ 'code' => 0, 'message' => 'boom' ] ] ] );

$result = $driver->purge_urls( [ 'https://wordpress.local/x/', 'https://wordpress.local/y/' ] );

c_check( 'both urls failed together', count( $result->failed_urls() ), 2 );
c_true( 'retryable', $result->is_retryable() );

// --- Through the queue: a bad token must dead-letter immediately -----------------------------------------

WP_CLI::log( '== queue: a bad token does not burn six attempts ==' );

$plugin = Plugin::instance();
$queue  = $plugin->container()->get( 'queue' );
$worker = $plugin->container()->get( 'worker' );

Cooldown::close( 'cloudflare' );

$job_id = $queue->enqueue( 'cloudflare', 'purge_urls', [ 'urls' => [ 'https://wordpress.local/z/' ] ], 'cf-test' );
c_true( 'job enqueued', $job_id > 0 );

cf_will_answer( 401, [ 'success' => false, 'errors' => [ [ 'code' => 10000, 'message' => 'Authentication error' ] ] ] );

$worker->run( 1, 'cloudflare' );

$job = $queue->find( $job_id );
c_check( 'dead on the first attempt', $job->status, JobStatus::Dead );
c_check( 'exactly one attempt spent', $job->attempts, 1 );
WP_CLI::log( '  recorded: ' . (string) $job->last_error );

// --- Through the queue: a 429 benches the driver and reschedules ---------------------------------------------

WP_CLI::log( '== queue: 429 benches the driver ==' );

Cooldown::close( 'cloudflare' );

$job_id = $queue->enqueue( 'cloudflare', 'purge_urls', [ 'urls' => [ 'https://wordpress.local/rate/' ] ], 'cf-test' );

cf_will_answer(
	429,
	[ 'success' => false, 'errors' => [ [ 'code' => 971, 'message' => 'Rate limited' ] ] ],
	[ 'retry-after' => '30' ]
);

$worker->run( 1, 'cloudflare' );

$job = $queue->find( $job_id );
c_check( 'still pending, not dead', $job->status, JobStatus::Pending );
c_check( 'one attempt spent', $job->attempts, 1 );
c_true( 'driver benched', Cooldown::active( 'cloudflare' ) );
WP_CLI::log( '  cooldown remaining: ' . Cooldown::remaining( 'cloudflare' ) . 's' );

// A benched driver must not be attempted again.
OmcCfTally::$calls = 0;
$worker->run( 5, 'cloudflare' );
c_check( 'no further API calls while benched', OmcCfTally::$calls, 0 );

// --- Clean up -------------------------------------------------------------------------------------------------

Cooldown::close( 'cloudflare' );
$queue->delete( array_map( static fn ( $j ) => $j->id, [] ) );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->prefix}omc_jobs WHERE reason = 'cf-test'" );
$queue->resync_depth();

Options::forget_stored_secret( 'cf_api_token' );
Options::set_cf_state( [ 'zone_id' => '', 'zone_name' => '', 'plan' => '' ] );

$settings                                     = Options::all();
$settings['drivers']['cloudflare']['enabled'] = false;
Options::save( $settings );

WP_CLI::log( '' );
WP_CLI::log( OmcCfTally::$pass . ' passed, ' . OmcCfTally::$fail . ' failed' );

if ( OmcCfTally::$fail > 0 ) {
	WP_CLI::halt( 1 );
}
