<?php
/**
 * Live end-to-end test. Run with:
 *
 *     wp eval-file wp-content/plugins/oh-my-cache/tests/live-pipeline.php
 *
 * Seeds Redis with the pages a post touches, then edits the post through the normal WordPress
 * API and checks that the cache cleared itself. No purge is called directly anywhere below:
 * the whole point is to prove the hooks fire, the collector enumerates, and the coordinator
 * dispatches without being asked.
 *
 * @package OhMyCache
 */

// No declare(strict_types) here: wp eval-file evals this file.

use OhMyCache\Cache\RedisDriver;
use OhMyCache\Plugin;
use OhMyCache\Queue\QueueRepository;
use OhMyCache\Support\Options;

/** Tally holder; `global` does not reach outer scope under wp eval-file. */
final class OmcPipelineTally {
	public static int $pass = 0;
	public static int $fail = 0;
}

function p_check( string $label, $actual, $expected ): void {
	if ( $actual === $expected ) {
		++OmcPipelineTally::$pass;
		WP_CLI::log( "  ok   {$label}" );
		return;
	}
	++OmcPipelineTally::$fail;
	WP_CLI::log( "  FAIL {$label}" );
	WP_CLI::log( '       expected: ' . var_export( $expected, true ) );
	WP_CLI::log( '       actual:   ' . var_export( $actual, true ) );
}

function p_true( string $label, $actual ): void {
	p_check( $label, (bool) $actual, true );
}

// --- Configure -------------------------------------------------------------------------------

$settings                                 = Options::all();
$settings['enabled']                      = true;
$settings['drivers']['redis']['enabled']  = true;
$settings['drivers']['redis']['database'] = 9;
$settings['drivers']['redis']['prefix']   = 'oh-my-cache-test:';
$settings['drivers']['nginx']['enabled']  = false;
$settings['dispatch']['mode']             = 'realtime';
Options::save( $settings );
Options::flush();

$driver = new RedisDriver();
$redis  = $driver->connection()->connect();

if ( ! $redis instanceof Redis ) {
	WP_CLI::error( 'Redis unavailable: ' . (string) $driver->connection()->error() );
}

$redis->flushDB();

$plugin = Plugin::instance();

if ( ! $plugin ) {
	WP_CLI::error( 'Plugin is not booted.' );
}

/** @var QueueRepository $queue */
$queue = $plugin->container()->get( 'queue' );

// --- Create a post to work with ----------------------------------------------------------------

WP_CLI::log( '== fixture ==' );

$category_id = wp_create_category( 'oh-my-cache-test-category' );

/*
 * post_author must be explicit: under WP-CLI there is no current user, so WordPress would
 * create the post with author 0 and the collector would rightly skip the author archive.
 */
$author_id = (int) ( get_users( [ 'number' => 1, 'fields' => 'ID' ] )[0] ?? 1 );

$post_id = wp_insert_post(
	[
		'post_title'    => 'Oh My Cache pipeline test',
		'post_content'  => 'Testing.',
		'post_status'   => 'publish',
		'post_type'     => 'post',
		'post_author'   => $author_id,
		'post_category' => [ $category_id ],
	]
);

if ( is_wp_error( $post_id ) || ! $post_id ) {
	WP_CLI::error( 'Could not create the test post.' );
}

WP_CLI::log( '  post id: ' . $post_id );
WP_CLI::log( '  permalink: ' . get_permalink( $post_id ) );

// --- What does the collector think this post touches? --------------------------------------------

WP_CLI::log( '== url collection ==' );

$collector = $plugin->container()->get( 'collector' );
$urls      = $collector->for_post( (int) $post_id );

WP_CLI::log( '  collected ' . count( $urls ) . ' urls' );

foreach ( array_slice( $urls, 0, 8 ) as $url ) {
	WP_CLI::log( '    ' . $url );
}

p_true( 'collector found urls', count( $urls ) > 0 );
p_true( 'homepage included', in_array( untrailingslashit( home_url( '/' ) ) . '/', $urls, true ) || in_array( home_url( '/' ), $urls, true ) );

$permalink = get_permalink( $post_id );
p_true( 'permalink included', in_array( $permalink, $urls, true ) );

$category_link = get_category_link( $category_id );
p_true( 'category archive included', in_array( $category_link, $urls, true ) );

$author_link = get_author_posts_url( $author_id );
p_true( 'author archive included', in_array( $author_link, $urls, true ) );

// --- Seed every one of those URLs as if nginx had cached it ---------------------------------------

WP_CLI::log( '== seeding the cache ==' );

foreach ( $urls as $url ) {
	$redis->set( $driver->key_for( $url ), 'cached' );
}

// Something belonging to another site: it must be untouched at the end.
$redis->set( 'other-app:something', 'not ours' );

$seeded = $redis->dbSize();
WP_CLI::log( '  seeded ' . $seeded . ' keys' );
p_check( 'seed count matches collection', $seeded, count( $urls ) + 1 );

// --- Now edit the post through the normal API and let the plugin react ------------------------------

WP_CLI::log( '== editing the post (no purge called directly) ==' );

$depth_before = Options::queue_depth();

wp_update_post(
	[
		'ID'         => $post_id,
		'post_title' => 'Oh My Cache pipeline test, edited',
	]
);

/*
 * The coordinator dispatches on shutdown, which WP-CLI does not reach mid-script. Firing it
 * explicitly is the honest way to test this: the hooks and the collector have already done
 * their work by now, and this only triggers the handoff they registered.
 */
do_action( 'shutdown' );

$remaining = $redis->dbSize();
WP_CLI::log( '  keys remaining: ' . $remaining );

p_check( 'every cached page for that post was purged', $remaining, 1 );
p_check( 'the unrelated key survived', $redis->exists( 'other-app:something' ), 1 );

// --- Nothing should have needed the queue, because Redis is local and fast --------------------------

WP_CLI::log( '== queue ==' );

$depth_after = Options::queue_depth();
p_check( 'nothing was queued: it all ran inline', $depth_after, $depth_before );

$counts = $queue->counts();
WP_CLI::log( '  queue counts: ' . wp_json_encode( $counts ) );

// --- Comment approval should purge the post page too --------------------------------------------------

WP_CLI::log( '== comment triggers a purge ==' );

$redis->set( $driver->key_for( $permalink ), 'cached again' );
p_check( 'post page cached again', $redis->exists( $driver->key_for( $permalink ) ), 1 );

$comment_id = wp_insert_comment(
	[
		'comment_post_ID'      => $post_id,
		'comment_content'      => 'Nice post.',
		'comment_author'       => 'Tester',
		'comment_author_email' => 'tester@example.com',
		'comment_approved'     => 1,
	]
);

do_action( 'shutdown' );

p_check( 'approved comment purged the post page', $redis->exists( $driver->key_for( $permalink ) ), 0 );

// --- Extra paths listed by the operator ride along with any purge ---------------------------------------

WP_CLI::log( '== custom paths ==' );

$custom_before = Options::custom_urls_raw();
Options::save_custom_urls( '/llms.txt' );

$llms = home_url( '/llms.txt' );
$redis->set( $driver->key_for( $llms ), 'generated' );
p_check( 'llms.txt cached', $redis->exists( $driver->key_for( $llms ) ), 1 );

wp_update_post(
	[
		'ID'         => $post_id,
		'post_title' => 'Oh My Cache pipeline test, edited again',
	]
);

do_action( 'shutdown' );

p_check( 'the extra path was cleared alongside the post', $redis->exists( $driver->key_for( $llms ) ), 0 );

Options::save_custom_urls( $custom_before );

// --- Clean up -------------------------------------------------------------------------------------------

if ( $comment_id ) {
	wp_delete_comment( (int) $comment_id, true );
}

wp_delete_post( (int) $post_id, true );
wp_delete_category( $category_id );
$redis->flushDB();

WP_CLI::log( '' );
WP_CLI::log( OmcPipelineTally::$pass . ' passed, ' . OmcPipelineTally::$fail . ' failed' );

if ( OmcPipelineTally::$fail > 0 ) {
	WP_CLI::halt( 1 );
}
