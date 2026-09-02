<?php
/**
 * Live sitemap test. Run with:
 *
 *     wp eval-file wp-content/plugins/oh-my-cache/tests/live-sitemaps.php
 *
 * Runs against whichever sitemap generator is active, so the same file proves the behaviour for
 * core, Yoast, Rank Math, All in One SEO and SEOPress by activating one and running it again.
 *
 * Every URL it seeds is fetched first: a sitemap URL that does not answer 200 would make this
 * test pass against a page that does not exist, which is the failure it exists to catch.
 *
 * @package OhMyCache
 */

// No declare(strict_types) here: wp eval-file evals this file.

use OhMyCache\Cache\RedisDriver;
use OhMyCache\Plugin;
use OhMyCache\Purge\Sitemaps;
use OhMyCache\Support\Options;

/** Tally holder; `global` does not reach outer scope under wp eval-file. */
final class OmcSitemapTally {
	public static int $pass = 0;
	public static int $fail = 0;
}

function s_check( string $label, $actual, $expected ): void {
	if ( $actual === $expected ) {
		++OmcSitemapTally::$pass;
		WP_CLI::log( "  ok   {$label}" );
		return;
	}
	++OmcSitemapTally::$fail;
	WP_CLI::log( "  FAIL {$label}" );
	WP_CLI::log( '       expected: ' . var_export( $expected, true ) );
	WP_CLI::log( '       actual:   ' . var_export( $actual, true ) );
}

function s_true( string $label, $actual ): void {
	s_check( $label, (bool) $actual, true );
}

// --- Configure ---------------------------------------------------------------------------------

$settings                                 = Options::all();
$settings['enabled']                      = true;
$settings['drivers']['redis']['enabled']  = true;
$settings['drivers']['redis']['database'] = 9;
$settings['drivers']['redis']['prefix']   = 'oh-my-cache-sitemap:';
$settings['drivers']['nginx']['enabled']  = false;
$settings['dispatch']['mode']             = 'realtime';
$settings['purge']['sitemaps']            = true;
$settings['purge']['post_types_off']      = [];
Options::save( $settings );
Options::flush();

$sitemaps = new Sitemaps();
$provider = $sitemaps->provider();

WP_CLI::log( '== detection ==' );
WP_CLI::log( '  provider: ' . ( '' === $provider ? '(none)' : $provider ) . ' — ' . $sitemaps->provider_label() );
WP_CLI::log( '  index:    ' . $sitemaps->index_url() );

if ( '' === $provider ) {
	WP_CLI::warning( 'No sitemap generator is active, so there is nothing to test here.' );
	WP_CLI::halt( 0 );
}

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

// --- Discovery: read the index and see what it lists ----------------------------------------------

WP_CLI::log( '== discovery ==' );

$discovered = $sitemaps->refresh();

foreach ( $discovered as $entry ) {
	WP_CLI::log( '    ' . $entry );
}

s_true( 'the index listed something', count( $discovered ) > 0 );
s_true( 'and it was stored', $sitemaps->discovered() === $discovered );

foreach ( $discovered as $entry ) {
	$response = wp_remote_get( $entry, [ 'sslverify' => false, 'timeout' => 30 ] );
	$code     = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response );

	s_check( "{$entry} answers", $code, 200 );
}

// --- What a post change clears ---------------------------------------------------------------------

WP_CLI::log( '== what a post change clears ==' );

$author_id = (int) ( get_users( [ 'number' => 1, 'fields' => 'ID' ] )[0] ?? 1 );

$post_id = wp_insert_post(
	[
		'post_title'    => 'Oh My Cache sitemap test',
		'post_content'  => 'Testing.',
		'post_status'   => 'publish',
		'post_type'     => 'post',
		'post_author'   => $author_id,
		'post_category' => [ (int) get_option( 'default_category' ) ],
	]
);

if ( is_wp_error( $post_id ) || ! $post_id ) {
	WP_CLI::error( 'Could not create the test post.' );
}

$post = get_post( $post_id );
$urls = $sitemaps->for_post( $post );

foreach ( $urls as $url ) {
	WP_CLI::log( '    ' . $url );
}

s_true( 'the index is in there', in_array( $sitemaps->index_url(), $urls, true ) );

/*
 * The three shapes worth naming, checked only when this generator produces them. A news sitemap
 * needs the news add-on, and a second page needs more posts than fit in one file, so neither is
 * guaranteed to exist here; when it does exist, it must be cleared.
 */
foreach ( $discovered as $entry ) {
	$name = basename( (string) wp_parse_url( $entry, PHP_URL_PATH ) );

	if ( preg_match( '/(^|[-_])news([-_.]|$)/i', $name ) ) {
		s_true( "news sitemap {$name} is cleared", in_array( $entry, $urls, true ) );
	}

	if ( preg_match( '/^post-sitemap\d*\.xml$/i', $name ) || preg_match( '/^wp-sitemap-posts-post-\d+\.xml$/i', $name ) ) {
		s_true( "post sitemap {$name} is cleared", in_array( $entry, $urls, true ) );
	}

	if ( preg_match( '/^(category|post_tag|wp-sitemap-taxonomies)/i', $name ) ) {
		s_true( "taxonomy sitemap {$name} is cleared", in_array( $entry, $urls, true ) );
	}
}

// --- Custom post types get their own file, when the site has one --------------------------------------

$custom = [];

foreach ( get_post_types( [ 'public' => true ], 'names' ) as $type ) {
	if ( ! in_array( $type, [ 'post', 'page', 'attachment' ], true ) ) {
		$custom[] = $type;
	}
}

if ( $custom ) {
	WP_CLI::log( '== custom post type: ' . $custom[0] . ' ==' );

	$cpt_id = wp_insert_post(
		[
			'post_title'  => 'Oh My Cache custom type test',
			'post_status' => 'publish',
			'post_type'   => $custom[0],
			'post_author' => $author_id,
		]
	);

	$cpt_urls = $sitemaps->for_post( get_post( $cpt_id ) );

	foreach ( $cpt_urls as $url ) {
		WP_CLI::log( '    ' . $url );
	}

	// Both conventions: livro-sitemap.xml from the SEO plugins, wp-sitemap-posts-livro-1.xml from core.
	$own = array_filter(
		$discovered,
		static function ( string $entry ) use ( $custom ): bool {
			$name = basename( (string) wp_parse_url( $entry, PHP_URL_PATH ) );

			return str_starts_with( $name, $custom[0] . '-sitemap' ) || str_contains( $name, '-' . $custom[0] . '-' );
		}
	);

	foreach ( $own as $entry ) {
		s_true( basename( (string) wp_parse_url( $entry, PHP_URL_PATH ) ) . ' is cleared', in_array( $entry, $cpt_urls, true ) );
	}

	s_true(
		'and the sitemap for ordinary posts is not',
		! in_array( home_url( '/post-sitemap.xml' ), $cpt_urls, true )
	);

	wp_delete_post( (int) $cpt_id, true );
}

// --- Seed them, edit the post, and see them go -------------------------------------------------------

WP_CLI::log( '== a post edit clears them ==' );

foreach ( $urls as $url ) {
	$redis->set( $driver->key_for( $url ), 'cached' );
}

s_check( 'every sitemap seeded', $redis->dbSize(), count( $urls ) );

wp_update_post(
	[
		'ID'         => $post_id,
		'post_title' => 'Oh My Cache sitemap test, edited',
	]
);

// The coordinator dispatches on shutdown, which WP-CLI does not reach mid-script.
do_action( 'shutdown' );

$left = [];

foreach ( $urls as $url ) {
	if ( $redis->exists( $driver->key_for( $url ) ) ) {
		$left[] = $url;
	}
}

s_check( 'no sitemap was left cached', $left, [] );

// --- Switching the post type off stops all of it ---------------------------------------------------

WP_CLI::log( '== post type switched off ==' );

$settings                            = Options::all();
$settings['purge']['post_types_off'] = [ 'post' ];
Options::save( $settings );
Options::flush();

s_check( 'post is off', Options::post_type_enabled( 'post' ), false );
s_check( 'page is still on', Options::post_type_enabled( 'page' ), true );

$permalink = get_permalink( $post_id );
$redis->set( $driver->key_for( $permalink ), 'cached' );

wp_update_post(
	[
		'ID'         => $post_id,
		'post_title' => 'Oh My Cache sitemap test, edited while off',
	]
);

do_action( 'shutdown' );

s_check( 'nothing was cleared for a switched-off type', $redis->exists( $driver->key_for( $permalink ) ), 1 );

$settings                            = Options::all();
$settings['purge']['post_types_off'] = [];
Options::save( $settings );
Options::flush();

// --- Clean up ---------------------------------------------------------------------------------------

wp_delete_post( (int) $post_id, true );
$redis->flushDB();

WP_CLI::log( '' );
WP_CLI::log( OmcSitemapTally::$pass . ' passed, ' . OmcSitemapTally::$fail . ' failed' );

if ( OmcSitemapTally::$fail > 0 ) {
	WP_CLI::halt( 1 );
}
