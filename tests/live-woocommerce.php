<?php
/**
 * Live WooCommerce test. Run with:
 *
 *     wp eval-file wp-content/plugins/oh-my-cache/tests/live-woocommerce.php
 *
 * Two halves, and the second is the one that matters most. The first checks that the changes
 * WooCommerce makes without saving a post still clear the cache. The second checks that the cart,
 * the checkout and the account page are never handed to the edge, by making real requests and
 * reading the header that comes back.
 *
 * @package OhMyCache
 */

// No declare(strict_types) here: wp eval-file evals this file.

use OhMyCache\Cache\RedisDriver;
use OhMyCache\Cloudflare\Expression;
use OhMyCache\Plugin;
use OhMyCache\Support\Options;

/** Tally holder; `global` does not reach outer scope under wp eval-file. */
final class OmcWooTally {
	public static int $pass = 0;
	public static int $fail = 0;
}

function w_check( string $label, $actual, $expected ): void {
	if ( $actual === $expected ) {
		++OmcWooTally::$pass;
		WP_CLI::log( "  ok   {$label}" );
		return;
	}
	++OmcWooTally::$fail;
	WP_CLI::log( "  FAIL {$label}" );
	WP_CLI::log( '       expected: ' . var_export( $expected, true ) );
	WP_CLI::log( '       actual:   ' . var_export( $actual, true ) );
}

function w_true( string $label, $actual ): void {
	w_check( $label, (bool) $actual, true );
}

if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::warning( 'WooCommerce is not active, so there is nothing to test here.' );
	WP_CLI::halt( 0 );
}

// --- Configure ---------------------------------------------------------------------------------

$restore_ttl = (int) Options::get( 'edge.ttl_seconds', 0 );

/*
 * A fresh WooCommerce install starts in coming soon mode, where it serves its own holding page
 * with its own short Cache-Control. That is WooCommerce doing the right thing, but it hides what
 * this test is checking, so the store is opened for the duration and put back afterwards.
 */
$restore_coming_soon = get_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_coming_soon', 'no' );

$settings                                 = Options::all();
$settings['enabled']                      = true;
$settings['drivers']['redis']['enabled']  = true;
$settings['drivers']['redis']['database'] = 9;
$settings['drivers']['redis']['prefix']   = 'oh-my-cache-woo:';
$settings['drivers']['nginx']['enabled']  = false;
$settings['dispatch']['mode']             = 'realtime';
$settings['purge']['woocommerce']         = true;
$settings['purge']['post_types_off']      = [];
$settings['edge']['ttl_seconds']          = 300;
Options::save( $settings );
Options::flush();

$driver = new RedisDriver();
$redis  = $driver->connection()->connect();

if ( ! $redis instanceof Redis ) {
	WP_CLI::error( 'Redis unavailable: ' . (string) $driver->connection()->error() );
}

$redis->flushDB();

if ( ! Plugin::instance() ) {
	WP_CLI::error( 'Plugin is not booted.' );
}

// --- Fixtures ----------------------------------------------------------------------------------

$product = new WC_Product_Simple();
$product->set_name( 'Oh My Cache test product' );
$product->set_regular_price( '10.00' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 5 );
$product->set_status( 'publish' );
$product_id = $product->save();

$term = wp_insert_term( 'Oh My Cache test category', 'product_cat' );
$cat_id = is_wp_error( $term ) ? 0 : (int) $term['term_id'];

if ( $cat_id ) {
	wp_set_object_terms( $product_id, [ $cat_id ], 'product_cat' );
}

$variable = new WC_Product_Variable();
$variable->set_name( 'Oh My Cache test variable' );
$variable->set_status( 'publish' );
$variable_id = $variable->save();

$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Size' );
$attribute->set_options( [ 'S', 'M' ] );
$attribute->set_visible( true );
$attribute->set_variation( true );
$variable->set_attributes( [ $attribute ] );
$variable->save();

$variation = new WC_Product_Variation();
$variation->set_parent_id( $variable_id );
$variation->set_attributes( [ 'size' => 'S' ] );
$variation->set_regular_price( '20.00' );
$variation->set_manage_stock( true );
$variation->set_stock_quantity( 4 );
$variation_id = $variation->save();

$permalink  = get_permalink( $product_id );
$parent_url = get_permalink( $variable_id );
$shop       = get_permalink( wc_get_page_id( 'shop' ) );
$category   = $cat_id ? get_term_link( $cat_id, 'product_cat' ) : '';

/*
 * Creating the fixtures queued purges of their own. Dispatch them now, or the first scenario
 * below would be cleared by the setup rather than by what it is testing.
 */
do_action( 'shutdown' );

WP_CLI::log( '== what a product change enumerates ==' );

$urls = Plugin::instance()->container()->get( 'collector' )->for_post( $product_id );

w_true( 'the product page', in_array( $permalink, $urls, true ) );
w_true( 'the shop page', in_array( $shop, $urls, true ) );

if ( is_string( $category ) && '' !== $category ) {
	w_true( 'its category archive', in_array( $category, $urls, true ) );
}

// --- The changes WooCommerce makes without saving a post ------------------------------------------

/**
 * Seed a URL, run something, and report whether it was cleared.
 *
 * @param callable $change What to do.
 */
function w_scenario( string $label, string $url, callable $change, $redis, $driver ): void {
	$redis->flushDB();
	$redis->set( $driver->key_for( $url ), 'cached' );

	$change();

	do_action( 'shutdown' );

	w_check( $label, $redis->exists( $driver->key_for( $url ) ), 0 );
}

WP_CLI::log( '== changes that never touch save_post ==' );

w_scenario(
	'stock quantity clears the product page',
	$permalink,
	static function () use ( $product_id ): void {
		wc_update_product_stock( $product_id, 3, 'set' );
	},
	$redis,
	$driver
);

w_scenario(
	'stock quantity clears the shop page too',
	$shop,
	static function () use ( $product_id ): void {
		wc_update_product_stock( $product_id, 2, 'set' );
	},
	$redis,
	$driver
);

w_scenario(
	'selling out clears the product page',
	$permalink,
	static function () use ( $product_id ): void {
		$p = wc_get_product( $product_id );
		$p->set_stock_status( 'outofstock' );
		$p->save();
	},
	$redis,
	$driver
);

w_scenario(
	'a price change clears the product page',
	$permalink,
	static function () use ( $product_id ): void {
		$p = wc_get_product( $product_id );
		$p->set_regular_price( '8.00' );
		$p->save();
	},
	$redis,
	$driver
);

w_scenario(
	'a variation price clears the parent product',
	$parent_url,
	static function () use ( $variation_id ): void {
		$v = wc_get_product( $variation_id );
		$v->set_regular_price( '15.00' );
		$v->save();
	},
	$redis,
	$driver
);

w_scenario(
	'a variation selling out clears the parent product',
	$parent_url,
	static function () use ( $variation_id ): void {
		wc_update_product_stock( $variation_id, 0, 'set' );
	},
	$redis,
	$driver
);

// --- The case a shop actually hits: somebody buys the last one ---------------------------------------

WP_CLI::log( '== an order reduces stock ==' );

$p = wc_get_product( $product_id );
$p->set_stock_status( 'instock' );
$p->set_stock_quantity( 2 );
$p->save();

do_action( 'shutdown' );

$redis->flushDB();
$redis->set( $driver->key_for( $permalink ), 'cached' );
$redis->set( $driver->key_for( $shop ), 'cached' );

$order = wc_create_order();
$order->add_product( wc_get_product( $product_id ), 2 );
$order->calculate_totals();
$order->save();

wc_reduce_stock_levels( $order->get_id() );

do_action( 'shutdown' );

w_check( 'the product page was cleared', $redis->exists( $driver->key_for( $permalink ) ), 0 );
w_check( 'the shop page was cleared', $redis->exists( $driver->key_for( $shop ) ), 0 );
w_check( 'and the product really did sell out', wc_get_product( $product_id )->get_stock_quantity(), 0 );

$order->delete( true );

// --- Nothing personal may be handed to the edge -----------------------------------------------------

WP_CLI::log( '== what may be cached at the edge ==' );

/**
 * Fetch a path as a visitor with no cookies and return its Cache-Control.
 */
function w_cache_control( string $path ): string {
	$response = wp_remote_get( home_url( $path ), [ 'sslverify' => false, 'timeout' => 30 ] );

	if ( is_wp_error( $response ) ) {
		return 'error: ' . $response->get_error_message();
	}

	$header = wp_remote_retrieve_header( $response, 'cache-control' );

	return is_array( $header ) ? implode( ', ', $header ) : (string) $header;
}

$shop_path = (string) wp_parse_url( $shop, PHP_URL_PATH );

foreach ( [ '/' => 'the front page', $shop_path => 'the shop page' ] as $path => $label ) {
	$header = w_cache_control( $path );
	WP_CLI::log( "    {$path} => {$header}" );
	w_true( "{$label} may be cached", str_contains( $header, 's-maxage=300' ) );
}

foreach ( [ 'cart', 'checkout', 'myaccount' ] as $page ) {
	$path   = (string) wp_parse_url( get_permalink( wc_get_page_id( $page ) ), PHP_URL_PATH );
	$header = w_cache_control( $path );

	WP_CLI::log( "    {$path} => {$header}" );
	w_true( "{$path} is never handed to the edge", ! str_contains( $header, 's-maxage' ) );
}

$fragments = w_cache_control( '/?wc-ajax=get_refreshed_fragments' );
WP_CLI::log( "    ?wc-ajax=get_refreshed_fragments => {$fragments}" );
w_true( 'cart fragments are never handed to the edge', ! str_contains( $fragments, 's-maxage' ) );

// --- And the edge rule says the same thing --------------------------------------------------------

WP_CLI::log( '== the Cloudflare rule agrees ==' );

$expression = Expression::guest_html();

foreach ( [ 'cart', 'checkout', 'myaccount' ] as $page ) {
	$path = rtrim( (string) wp_parse_url( get_permalink( wc_get_page_id( $page ) ), PHP_URL_PATH ), '/' );

	w_true( "the rule excludes {$path}", str_contains( $expression, 'not starts_with(http.request.uri.path, "' . $path . '")' ) );
}

w_true( 'the rule excludes wc-ajax', str_contains( $expression, 'not http.request.uri.query contains "wc-ajax="' ) );

// --- Clean up ---------------------------------------------------------------------------------------

wp_delete_post( (int) $variation_id, true );
wp_delete_post( (int) $variable_id, true );
wp_delete_post( (int) $product_id, true );

if ( $cat_id ) {
	wp_delete_term( $cat_id, 'product_cat' );
}

$redis->flushDB();

$settings                        = Options::all();
$settings['edge']['ttl_seconds'] = $restore_ttl;
Options::save( $settings );
Options::flush();

update_option( 'woocommerce_coming_soon', $restore_coming_soon );

WP_CLI::log( '' );
WP_CLI::log( OmcWooTally::$pass . ' passed, ' . OmcWooTally::$fail . ' failed' );

if ( OmcWooTally::$fail > 0 ) {
	WP_CLI::halt( 1 );
}
