<?php
/**
 * Smoke test: load the plugin exactly as WordPress would and confirm the bootstrap runs clean.
 *
 * Catches the class of mistake the other suites cannot: a hook callback pointing at a method
 * that does not exist, a service factory that throws, a use statement for a class that was
 * renamed. Runs the admin path too, since that is where most of the wiring lives.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'YEAR_IN_SECONDS', 31536000 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );

// Fail loudly on anything WordPress would report as a notice.
error_reporting( E_ALL );
set_error_handler(
	static function ( int $errno, string $message, string $file, int $line ): bool {
		echo "  PHP NOTICE: {$message} in {$file}:{$line}\n";
		$GLOBALS['__notices'] = ( $GLOBALS['__notices'] ?? 0 ) + 1;
		return true;
	}
);

$GLOBALS['__options']  = [];
$GLOBALS['__actions']  = [];
$GLOBALS['__notices']  = 0;
$GLOBALS['__is_admin'] = true;

function get_option( $n, $d = false ) { return $GLOBALS['__options'][ $n ] ?? $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['__options'][ $n ] = $v; return true; }
function add_option( $n, $v, $x = '', $a = null ) {
	if ( array_key_exists( $n, $GLOBALS['__options'] ) ) { return false; }
	$GLOBALS['__options'][ $n ] = $v; return true;
}
function delete_option( $n ) { unset( $GLOBALS['__options'][ $n ] ); return true; }
function get_site_option( $n, $d = false ) { return get_option( $n, $d ); }
function maybe_serialize( $v ) { return is_array( $v ) || is_object( $v ) ? serialize( $v ) : $v; }
function apply_filters( $t, $v, ...$r ) { return $v; }
function add_filter( $t, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $t ][] = $cb; return true; }
function add_action( $t, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $t ][] = $cb; return true; }
function do_action( ...$a ) { return null; }
function did_action( $tag ) { return 0; }
function wp_parse_url( $u, $c = -1 ) { return -1 === $c ? parse_url( $u ) : parse_url( $u, $c ); }
function home_url( $p = '' ) { return 'https://example.com' . $p; }
function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . ltrim( $p, '/' ); }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://example.com/wp-content/plugins/oh-my-cache/'; }
function plugin_basename( $f ) { return 'oh-my-cache/oh-my-cache.php'; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function wp_rand( $min = 0, $max = 0 ) { return random_int( $min, $max ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function __( $t, $d = null ) { return $t; }
function _n( $s, $p, $n, $d = null ) { return 1 === $n ? $s : $p; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url( $t ) { return (string) $t; }
function get_transient( $k ) { return $GLOBALS['__options'][ '_t_' . $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__options'][ '_t_' . $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__options'][ '_t_' . $k ] ); return true; }
function wp_generate_password( $len = 12, ...$r ) { return substr( bin2hex( random_bytes( 32 ) ), 0, $len ); }
function wp_cache_delete( ...$a ) { return true; }
function is_admin() { return (bool) $GLOBALS['__is_admin']; }
function is_multisite() { return false; }
function is_network_admin() { return false; }
function wp_doing_cron() { return false; }
function wp_next_scheduled( ...$a ) { return false; }
function wp_schedule_event( ...$a ) { return true; }
function wp_schedule_single_event( ...$a ) { return true; }
function wp_clear_scheduled_hook( ...$a ) { return true; }
function spawn_cron() { return true; }
function register_activation_hook( ...$a ) { return true; }
function register_deactivation_hook( ...$a ) { return true; }
function load_plugin_textdomain( ...$a ) { return true; }
function get_bloginfo( $what = '' ) { return 'version' === $what ? '6.8' : ''; }
function current_user_can( ...$a ) { return true; }
function get_post_types( ...$a ) { return [ 'post', 'page' ]; }
function register_setting( ...$a ) { return true; }
function add_menu_page( ...$a ) { return 'toplevel_page_oh-my-cache'; }
function add_submenu_page( ...$a ) { return 'oh-my-cache_page_sub'; }
function wp_enqueue_style( ...$a ) { return true; }
function wp_enqueue_script( ...$a ) { return true; }
function wp_localize_script( ...$a ) { return true; }
function wp_create_nonce( ...$a ) { return 'nonce'; }
function human_time_diff( ...$a ) { return '1 minute'; }

final class BootWpdb {
	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public int $insert_id = 0;
	public function prepare( $sql, ...$a ) { return $sql; }
	public function esc_like( $t ) { return (string) $t; }
	public function query( $s ) { return 0; }
	public function get_var( $s ) { return null; }
	public function get_row( $s, $m = null ) { return null; }
	public function get_results( $s, $m = null ) { return []; }
	public function insert( ...$a ) { return 1; }
	public function update( ...$a ) { return 1; }
	public function get_charset_collate() { return ''; }
}

$GLOBALS['wpdb'] = new BootWpdb();

$pass = 0;
$fail = 0;

function check( string $label, $actual, $expected ): void {
	global $pass, $fail;
	if ( $actual === $expected ) { ++$pass; echo "  ok   {$label}\n"; return; }
	++$fail;
	echo "  FAIL {$label}\n       expected: " . var_export( $expected, true ) . "\n       actual:   " . var_export( $actual, true ) . "\n";
}
function check_true( string $l, $a ): void { check( $l, (bool) $a, true ); }

echo "== bootstrap (admin request) ==\n";

try {
	require dirname( __DIR__ ) . '/oh-my-cache.php';
	check_true( 'plugin file loaded without fatal', true );
} catch ( \Throwable $e ) {
	check( 'plugin file loaded without fatal', $e->getMessage(), 'no exception' );
	exit( 1 );
}

$plugin = \OhMyCache\Plugin::instance();
check_true( 'Plugin::boot produced an instance', $plugin instanceof \OhMyCache\Plugin );

echo "== public API is declared ==\n";
foreach ( [
	'oh_my_cache_purge_url',
	'oh_my_cache_purge_post',
	'oh_my_cache_purge_term',
	'oh_my_cache_purge_pattern',
	'oh_my_cache_purge_all',
	'oh_my_cache_is_active',
	'oh_my_cache_register_trigger',
	'oh_my_cache_register_driver',
] as $fn ) {
	check_true( "{$fn}() exists", function_exists( $fn ) );
}

echo "== every service resolves ==\n";
foreach ( [ 'drivers', 'queue', 'collector', 'coordinator', 'worker', 'triggers', 'cloudflare', 'preloader' ] as $service ) {
	try {
		$resolved = $plugin->container()->get( $service );
		check_true( "service '{$service}'", is_object( $resolved ) );
	} catch ( \Throwable $e ) {
		check( "service '{$service}'", $e->getMessage(), 'resolves' );
	}
}

echo "== built-in drivers registered in order ==\n";
$drivers = $plugin->container()->get( 'drivers' );
check( 'driver order', array_keys( $drivers->all() ), [ 'nginx', 'redis', 'cloudflare' ] );
check( 'none enabled by default', $drivers->enabled(), [] );

echo "== every registered hook callback is callable ==\n";
$uncallable = [];
foreach ( $GLOBALS['__actions'] as $hook => $callbacks ) {
	foreach ( $callbacks as $callback ) {
		if ( ! is_callable( $callback ) ) {
			$uncallable[] = $hook . ' => ' . ( is_array( $callback ) ? ( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0] ) . '::' . $callback[1] : 'closure' );
		}
	}
}
check( 'no dangling hook callbacks', $uncallable, [] );

/*
 * Regression guard for a real bug this suite originally missed.
 *
 * WP_Hook always invokes a callback with at least one argument. For an action fired with no
 * arguments of its own, such as do_action( 'shutdown' ), that argument is an empty string. A
 * callback whose first parameter is typed array, int or object therefore takes a TypeError and
 * fatals the request. A stubbed add_action() that never invokes anything cannot catch this,
 * which is exactly how Coordinator::dispatch() shipped hooked directly to shutdown.
 */
echo "== callbacks survive the argument WordPress actually passes ==\n";

/*
 * Some hooks are registered lazily. The coordinator only hooks shutdown once it has work, so
 * without staging something here the guard below would inspect a hook list that never contains
 * the very callback that caused the bug. Give it work first.
 */
$plugin->container()->get( 'coordinator' )->add( [ 'https://example.com/warm/' ], 'boot-test' );

$argless_hooks = [ 'shutdown', 'init', 'admin_init', 'plugins_loaded', 'admin_notices', 'admin_menu', 'switch_theme', 'wp_dashboard_setup' ];
$unsafe        = [];

foreach ( $GLOBALS['__actions'] as $hook => $callbacks ) {
	if ( ! in_array( $hook, $argless_hooks, true ) ) {
		continue;
	}

	foreach ( $callbacks as $callback ) {
		try {
			$reflection = is_array( $callback )
				? new ReflectionMethod( $callback[0], $callback[1] )
				: new ReflectionFunction( $callback );
		} catch ( \Throwable $e ) {
			continue;
		}

		$params = $reflection->getParameters();

		// No parameters at all: PHP discards the extra argument harmlessly.
		if ( ! $params ) {
			continue;
		}

		$type = $params[0]->getType();

		if ( ! $type instanceof ReflectionNamedType ) {
			continue;
		}

		// An empty string is what arrives; anything that cannot accept it will fatal.
		if ( ! in_array( $type->getName(), [ 'string', 'mixed' ], true ) ) {
			$label = is_array( $callback )
				? ( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0] ) . '::' . $callback[1]
				: 'closure';

			$unsafe[] = $hook . ' => ' . $label . '( ' . $type->getName() . ' $' . $params[0]->getName() . ' )';
		}
	}
}

check( 'no callback would take a TypeError from an argument-less action', $unsafe, [] );

echo "== API is safe to call while nothing is configured ==\n";
$ticket = oh_my_cache_purge_url( 'https://example.com/a/' );
check_true( 'purge_url returns a ticket', $ticket instanceof \OhMyCache\Api\PurgeTicket );
check( 'no drivers means nothing happened', $ticket->inline_results(), [] );
check_true( 'is_active is false with no drivers', ! oh_my_cache_is_active() );

echo "== no PHP notices during boot ==\n";
check( 'notice count', $GLOBALS['__notices'], 0 );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
