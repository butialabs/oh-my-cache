<?php
/**
 * Integration harness: exercises the coordinator and the queue against an in-memory wpdb and a
 * stub driver whose failures we control.
 *
 * This is where the behaviour that actually matters gets checked: that only failed URLs are
 * re-queued, that a chunk is the unit of work, that dedupe holds, and that the backoff and
 * dead-letter transitions happen when they should.
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'YEAR_IN_SECONDS', 31536000 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );

$GLOBALS['__options'] = [];

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
function add_filter( ...$a ) { return true; }
function add_action( ...$a ) { return true; }
function do_action( ...$a ) { return null; }
function wp_parse_url( $u, $c = -1 ) { return -1 === $c ? parse_url( $u ) : parse_url( $u, $c ); }
function home_url( $p = '' ) { return 'https://example.com' . $p; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function wp_rand( $min = 0, $max = 0 ) { return random_int( $min, $max ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function __( $t, $d = null ) { return $t; }
function _n( $s, $p, $n, $d = null ) { return 1 === $n ? $s : $p; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function get_transient( $k ) { return $GLOBALS['__options'][ '_t_' . $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__options'][ '_t_' . $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__options'][ '_t_' . $k ] ); return true; }
function wp_generate_password( $len = 12, ...$rest ) { return substr( bin2hex( random_bytes( 32 ) ), 0, $len ); }
function wp_cache_delete( ...$a ) { return true; }
function is_admin() { return true; }
function wp_doing_cron() { return false; }
function spawn_cron() { return true; }
function wp_next_scheduled( ...$a ) { return false; }
function wp_schedule_single_event( ...$a ) { return true; }
function is_multisite() { return false; }

/**
 * Enough of wpdb to run the queue: an array-backed table with the handful of operations
 * QueueRepository actually performs.
 */
final class FakeWpdb {
	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public int $insert_id = 0;
	/** @var array<int, array<string, mixed>> */
	public array $rows = [];
	private int $next_id = 1;

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$sql = str_replace( [ '%d', '%s', '%f' ], '__ARG__', $sql );
		$out = '';
		$parts = explode( '__ARG__', $sql );
		foreach ( $parts as $i => $part ) {
			$out .= $part;
			if ( array_key_exists( $i, $args ) ) {
				$out .= is_numeric( $args[ $i ] ) && ! is_string( $args[ $i ] )
					? (string) $args[ $i ]
					: "'" . str_replace( "'", "''", (string) $args[ $i ] ) . "'";
			}
		}
		return $out;
	}

	public function esc_like( $t ) { return addcslashes( (string) $t, '_%\\' ); }

	public function insert( $table, $data, $format = null ) {
		$data['id'] = $this->next_id++;
		$this->rows[ $data['id'] ] = $data;
		$this->insert_id = $data['id'];
		return 1;
	}

	public function update( $table, $data, $where, $f = null, $wf = null ) {
		$n = 0;
		foreach ( $this->rows as $id => $row ) {
			$match = true;
			foreach ( $where as $k => $v ) {
				if ( (string) ( $row[ $k ] ?? '' ) !== (string) $v ) { $match = false; break; }
			}
			if ( $match ) { $this->rows[ $id ] = array_merge( $row, $data ); ++$n; }
		}
		return $n;
	}

	/** Only the shapes QueueRepository issues are understood. */
	public function query( $sql ) {
		if ( preg_match( "/UPDATE .* SET status = 'claimed', claim_token = '([^']+)'.*WHERE status = 'pending' AND available_at <= '([^']+)'.*LIMIT (\d+)/s", $sql, $m ) ) {
			$token = $m[1]; $now = $m[2]; $limit = (int) $m[3];
			$eligible = array_filter( $this->rows, fn( $r ) => 'pending' === $r['status'] && $r['available_at'] <= $now );
			uasort( $eligible, fn( $a, $b ) => [ $a['priority'], $a['available_at'], $a['id'] ] <=> [ $b['priority'], $b['available_at'], $b['id'] ] );
			$n = 0;
			foreach ( $eligible as $id => $row ) {
				if ( $n >= $limit ) { break; }
				$this->rows[ $id ]['status'] = 'claimed';
				$this->rows[ $id ]['claim_token'] = $token;
				++$n;
			}
			return $n;
		}
		if ( preg_match( "/UPDATE .* SET status = 'pending'.*WHERE id IN \(([^)]+)\)/s", $sql, $m ) ) {
			$ids = array_map( 'intval', explode( ',', $m[1] ) );
			foreach ( $ids as $id ) {
				if ( isset( $this->rows[ $id ] ) ) {
					$this->rows[ $id ]['status'] = 'pending';
					$this->rows[ $id ]['claim_token'] = null;
				}
			}
			return count( $ids );
		}
		if ( preg_match( "/UPDATE .* WHERE status = 'claimed' AND claimed_at < '([^']+)'/s", $sql, $m ) ) {
			return 0;
		}
		if ( preg_match( "/UPDATE .* SET status = 'dead'.*WHERE status = 'pending' AND attempts >= max_attempts/s", $sql ) ) {
			return 0;
		}
		if ( preg_match( '/DELETE FROM .* WHERE id IN \(([^)]+)\)/s', $sql, $m ) ) {
			$ids = array_map( 'intval', explode( ',', $m[1] ) );
			foreach ( $ids as $id ) { unset( $this->rows[ $id ] ); }
			return count( $ids );
		}
		return 0;
	}

	public function get_var( $sql ) {
		if ( preg_match( "/SELECT id FROM .* WHERE payload_hash = '([^']+)' AND status = '([^']+)'/s", $sql, $m ) ) {
			foreach ( $this->rows as $row ) {
				if ( $row['payload_hash'] === $m[1] && $row['status'] === $m[2] ) { return (string) $row['id']; }
			}
			return null;
		}
		if ( preg_match( '/SELECT COUNT\(\*\) FROM .* WHERE status IN \( ?\'([^\']+)\', ?\'([^\']+)\' ?\)/s', $sql, $m ) ) {
			return (string) count( array_filter( $this->rows, fn( $r ) => in_array( $r['status'], [ $m[1], $m[2] ], true ) ) );
		}
		if ( preg_match( "/SELECT created_at FROM .* WHERE status = '([^']+)'/s", $sql, $m ) ) {
			$rows = array_filter( $this->rows, fn( $r ) => $r['status'] === $m[1] );
			if ( ! $rows ) { return null; }
			usort( $rows, fn( $a, $b ) => $a['created_at'] <=> $b['created_at'] );
			return $rows[0]['created_at'];
		}
		return null;
	}

	public function get_results( $sql, $mode = null ) {
		if ( preg_match( "/SELECT \* FROM .* WHERE claim_token = '([^']+)'/s", $sql, $m ) ) {
			$rows = array_values( array_filter( $this->rows, fn( $r ) => ( $r['claim_token'] ?? null ) === $m[1] ) );
			usort( $rows, fn( $a, $b ) => [ $a['priority'], $a['id'] ] <=> [ $b['priority'], $b['id'] ] );
			return $rows;
		}
		if ( preg_match( '/SELECT status, COUNT\(\*\)/s', $sql ) ) {
			$counts = [];
			foreach ( $this->rows as $row ) {
				$counts[ $row['status'] ] = ( $counts[ $row['status'] ] ?? 0 ) + 1;
			}
			$out = [];
			foreach ( $counts as $status => $total ) { $out[] = [ 'status' => $status, 'total' => $total ]; }
			return $out;
		}
		return [];
	}

	public function get_row( $sql, $mode = null ) {
		if ( preg_match( '/SELECT \* FROM .* WHERE id = (\d+)/s', $sql, $m ) ) {
			return $this->rows[ (int) $m[1] ] ?? null;
		}
		return null;
	}
}

$GLOBALS['wpdb'] = new FakeWpdb();

spl_autoload_register( function ( string $class ): void {
	if ( ! str_starts_with( $class, 'OhMyCache\\' ) ) { return; }
	$rel  = str_replace( '\\', '/', substr( $class, strlen( 'OhMyCache\\' ) ) );
	$path = dirname( __DIR__ ) . '/src/' . $rel . '.php';
	if ( is_readable( $path ) ) { require_once $path; }
} );

use OhMyCache\Cache\AbstractDriver;
use OhMyCache\Cache\DriverManager;
use OhMyCache\Cache\PurgeResult;
use OhMyCache\Purge\Coordinator;
use OhMyCache\Queue\JobStatus;
use OhMyCache\Queue\QueueRepository;
use OhMyCache\Support\Options;

/** A driver whose behaviour the test dictates. */
final class StubDriver extends AbstractDriver {
	public int $calls = 0;
	/** @var array<int, array<int, string>> */
	public array $seen = [];

	public function __construct(
		private string $stub_id,
		private bool $remote = false,
		private int $chunk = 200,
		private ?\Closure $behaviour = null,
		private bool $cdn = false
	) {}

	public function id(): string { return $this->stub_id; }
	public function label(): string { return 'Stub ' . $this->stub_id; }
	public function is_enabled(): bool { return true; }
	public function is_remote(): bool { return $this->remote; }
	public function is_cdn(): bool { return $this->cdn; }
	public function max_urls_per_job(): int { return $this->chunk; }

	public function purge_urls( array $urls ): PurgeResult {
		++$this->calls;
		$this->seen[] = $urls;
		if ( $this->behaviour ) { return ( $this->behaviour )( $urls ); }
		$r = PurgeResult::make();
		foreach ( $urls as $u ) { $r->succeed( $u ); }
		return $r;
	}

	public function purge_all(): PurgeResult {
		++$this->calls;
		return PurgeResult::make()->note( 'all' );
	}
}

$pass = 0; $fail = 0;
function check( string $label, $actual, $expected ): void {
	global $pass, $fail;
	if ( $actual === $expected ) { ++$pass; echo "  ok   {$label}\n"; return; }
	++$fail;
	echo "  FAIL {$label}\n       expected: " . var_export( $expected, true ) . "\n       actual:   " . var_export( $actual, true ) . "\n";
}
function check_true( string $l, $a ): void { check( $l, (bool) $a, true ); }

function reset_state(): void {
	$GLOBALS['wpdb'] = new FakeWpdb();
	$GLOBALS['__options'] = [];
	Options::flush();
}

echo "== enqueue and dedupe ==\n";
reset_state();
$queue = new QueueRepository();
$id1 = $queue->enqueue( 'nginx', 'purge_urls', [ 'urls' => [ 'https://example.com/a/' ] ], 'test' );
$id2 = $queue->enqueue( 'nginx', 'purge_urls', [ 'urls' => [ 'https://example.com/a/' ] ], 'test' );
check_true( 'first enqueue creates a job', $id1 > 0 );
check( 'identical job deduped', $id2, 0 );
check( 'depth hint tracks enqueue', Options::queue_depth(), 1 );

echo "== claim is exclusive ==\n";
reset_state();
$queue = new QueueRepository();
for ( $i = 0; $i < 5; $i++ ) {
	$queue->enqueue( 'nginx', 'purge_urls', [ 'urls' => [ "https://example.com/{$i}/" ] ], 'test' );
}
$first  = $queue->claim( 3 );
$second = $queue->claim( 3 );
check( 'first claim takes 3', count( $first ), 3 );
check( 'second claim takes the rest', count( $second ), 2 );
$ids_a = array_map( fn( $j ) => $j->id, $first );
$ids_b = array_map( fn( $j ) => $j->id, $second );
check( 'claims are disjoint', array_intersect( $ids_a, $ids_b ), [] );
check( 'third claim is empty', count( $queue->claim( 3 ) ), 0 );

echo "== fail applies backoff, then dead-letters ==\n";
reset_state();
$queue = new QueueRepository();
$queue->enqueue( 'nginx', 'purge_urls', [ 'urls' => [ 'https://example.com/a/' ] ], 'test' );
$job = $queue->claim( 1 )[0];
for ( $attempt = 1; $attempt <= 5; $attempt++ ) {
	$queue->fail( $job, 'boom' );
	$job = $queue->find( $job->id );
	check( "attempt {$attempt} recorded", $job->attempts, $attempt );
	check( "still pending after attempt {$attempt}", $job->status, JobStatus::Pending );
}
$queue->fail( $job, 'boom' );
$job = $queue->find( $job->id );
check( 'dead after the sixth failure', $job->status, JobStatus::Dead );

echo "== non-retryable dead-letters immediately ==\n";
reset_state();
$queue = new QueueRepository();
$queue->enqueue( 'cloudflare', 'purge_urls', [ 'urls' => [ 'https://example.com/a/' ] ], 'test' );
$job = $queue->claim( 1 )[0];
$queue->fail( $job, 'invalid token', false );
check( 'straight to dead', $queue->find( $job->id )->status, JobStatus::Dead );

echo "== partial retry keeps only the failures ==\n";
reset_state();
$queue = new QueueRepository();
$queue->enqueue( 'nginx', 'purge_urls', [ 'urls' => [ 'a', 'b', 'c' ] ], 'test' );
$job = $queue->claim( 1 )[0];
$queue->fail( $job, 'partial', true, null, [ 'urls' => [ 'b' ], 'cursor' => null, 'meta' => [] ] );
check( 'payload narrowed to the failure', $queue->find( $job->id )->urls(), [ 'b' ] );

echo "== coordinator: inline success does not queue ==\n";
reset_state();
$queue   = new QueueRepository();
$manager = new DriverManager();
$local   = new StubDriver( 'nginx' );
$manager->register( $local );
$coordinator = new Coordinator( $manager, $queue );
$coordinator->add( [ 'https://example.com/a/', 'https://example.com/b/' ], 'test' );
$coordinator->dispatch();
check( 'driver was called inline', $local->calls, 1 );
check( 'nothing queued', Options::queue_depth(), 0 );

echo "== coordinator: only failures are re-queued ==\n";
reset_state();
$queue   = new QueueRepository();
$manager = new DriverManager();
$flaky = new StubDriver( 'nginx', false, 200, function ( array $urls ): PurgeResult {
	$r = PurgeResult::make();
	foreach ( $urls as $u ) {
		if ( str_contains( $u, '/bad/' ) ) { $r->fail( $u, 'nope' ); } else { $r->succeed( $u ); }
	}
	return $r;
} );
$manager->register( $flaky );
$coordinator = new Coordinator( $manager, $queue );
$coordinator->add( [ 'https://example.com/good/', 'https://example.com/bad/' ], 'test' );
$coordinator->dispatch();
check( 'one job queued', Options::queue_depth(), 1 );
$queued = $queue->claim( 5 )[0];
check( 'only the failed url', $queued->urls(), [ 'https://example.com/bad/' ] );

echo "== coordinator: remote driver chunks into separate jobs ==\n";
reset_state();
$queue   = new QueueRepository();
$manager = new DriverManager();
// Remote, chunk of 30, forced to queue by dispatch mode.
$manager->register( new StubDriver( 'cloudflare', true, 30 ) );
$coordinator = new Coordinator( $manager, $queue );
$urls = [];
for ( $i = 0; $i < 75; $i++ ) { $urls[] = "https://example.com/p{$i}/"; }
$coordinator->add( $urls, 'test' );
$coordinator->dispatch( [], 'queue' );
check( '75 urls become 3 jobs of 30/30/15', Options::queue_depth(), 3 );
$jobs = $queue->claim( 10 );
$sizes = array_map( fn( $j ) => count( $j->urls() ), $jobs );
sort( $sizes );
check( 'chunk sizes', $sizes, [ 15, 30, 30 ] );

echo "== coordinator: escalation to purge_all ==\n";
reset_state();
$settings = Options::all();
$settings['purge']['max_urls'] = 5;
Options::save( $settings );
$queue   = new QueueRepository();
$manager = new DriverManager();
$driver  = new StubDriver( 'nginx' );
$manager->register( $driver );
$coordinator = new Coordinator( $manager, $queue );
$many = [];
for ( $i = 0; $i < 20; $i++ ) { $many[] = "https://example.com/x{$i}/"; }
$coordinator->add( $many, 'bulk' );
check_true( 'request escalated', $coordinator->request()->is_purge_all() );
$coordinator->dispatch();
check( 'one purge_all call, not twenty url purges', $driver->calls, 1 );

echo "== driver grouping ==\n";
reset_state();
$manager = new DriverManager();
// NGINX in HTTP purge mode is remote and still a local page cache: the case is_remote() gets wrong.
$manager->register( new StubDriver( 'nginx', true ) );
$manager->register( new StubDriver( 'cloudflare', true, 30, null, true ) );
check( 'nginx over http is local', array_keys( $manager->local() ), [ 'nginx' ] );
check( 'cloudflare is the cdn', array_keys( $manager->cdn() ), [ 'cloudflare' ] );
check( 'active local', $manager->active_local()?->id(), 'nginx' );
check( 'active cdn', $manager->active_cdn()?->id(), 'cloudflare' );

echo "== custom paths ride along with every purge ==\n";
reset_state();
update_option( 'oh_my_cache_custom_urls', "/llms.txt\n/sitemap.xml", false );
Options::flush();
$queue   = new QueueRepository();
$manager = new DriverManager();
$driver  = new StubDriver( 'nginx' );
$manager->register( $driver );
$coordinator = new Coordinator( $manager, $queue );
$coordinator->add( [ 'https://example.com/a/' ], 'test' );
$coordinator->dispatch();
check(
	'the extra paths were purged too',
	$driver->seen[0],
	[ 'https://example.com/a/', 'https://example.com/llms.txt', 'https://example.com/sitemap.xml' ]
);

reset_state();
update_option( 'oh_my_cache_custom_urls', "/llms.txt", false );
Options::flush();
$queue   = new QueueRepository();
$manager = new DriverManager();
$driver  = new StubDriver( 'nginx' );
$manager->register( $driver );
$coordinator = new Coordinator( $manager, $queue );
$coordinator->add_purge_all( 'test' );
$coordinator->dispatch();
check( 'a full purge does not enumerate them', $driver->seen, [] );

echo "== gc reconciles the depth hint ==\n";
reset_state();
$queue = new QueueRepository();
$queue->enqueue( 'nginx', 'purge_urls', [ 'urls' => [ 'a' ] ], 'test' );
Options::set_queue_depth( 99 );
check( 'hint drifted', Options::queue_depth(), 99 );
$queue->resync_depth();
check( 'hint corrected from a real count', Options::queue_depth(), 1 );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
