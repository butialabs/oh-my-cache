<?php
/**
 * WooCommerce events that invalidate cache.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Integrations;

use OhMyCache\Container;
use OhMyCache\Support\Options;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * A shop goes stale in ways a blog does not.
 *
 * WooCommerce writes stock, price and variations straight to the database through its own data
 * store, so save_post never fires and a purge plugin listening only to WordPress sees none of it.
 * The result is a product that says "In stock" at the old price after selling out.
 *
 * The hooks below are WooCommerce's answer to that. Each ends in the same enumeration as a post
 * edit, so a product clears its page, the shop page, its archives and the sitemap in one pass. A
 * variation has no page of its own and clears its parent.
 */
final class WooCommerce {

	public function __construct( private readonly Container $container ) {}

	/**
	 * Whether WooCommerce is running.
	 */
	public static function is_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Wait for the rest of the plugins before looking for WooCommerce.
	 *
	 * This plugin's folder sorts first, so at boot time the class does not exist yet and checking
	 * would register nothing on every site that has a shop.
	 */
	public function register(): void {
		if ( did_action( 'plugins_loaded' ) ) {
			$this->boot();

			return;
		}

		add_action( 'plugins_loaded', [ $this, 'boot' ], 20 );
	}

	public function boot(): void {
		if ( ! self::is_active() ) {
			return;
		}

		// Product created or saved through the CRUD, which is most of the admin.
		add_action( 'woocommerce_new_product', [ $this, 'on_product' ], 20 );
		add_action( 'woocommerce_update_product', [ $this, 'on_product' ], 20 );

		// Variations: no page of their own, so the parent is what goes stale.
		add_action( 'woocommerce_new_product_variation', [ $this, 'on_variation' ], 20 );
		add_action( 'woocommerce_update_product_variation', [ $this, 'on_variation' ], 20 );

		// Stock. The last one carries an id and is the path an order takes to reduce stock.
		add_action( 'woocommerce_product_set_stock', [ $this, 'on_stock_object' ], 20 );
		add_action( 'woocommerce_variation_set_stock', [ $this, 'on_variation_stock_object' ], 20 );
		add_action( 'woocommerce_product_set_stock_status', [ $this, 'on_product' ], 20 );
		add_action( 'woocommerce_variation_set_stock_status', [ $this, 'on_variation' ], 20 );
		add_action( 'woocommerce_updated_product_stock', [ $this, 'on_stock_id' ], 20 );

		// Scheduled sales start and end on cron, with no edit involved at all.
		add_action( 'wc_after_products_starting_sales', [ $this, 'on_sales' ], 20 );
		add_action( 'wc_after_products_ending_sales', [ $this, 'on_sales' ], 20 );
	}

	/* --------------------------------------------------------------------- */

	/**
	 * @param mixed $product_id Product id.
	 */
	public function on_product( $product_id ): void {
		$this->collect( (int) $product_id, 'woocommerce:product' );
	}

	/**
	 * @param mixed $variation_id Variation id.
	 */
	public function on_variation( $variation_id ): void {
		$this->collect( $this->parent_of( (int) $variation_id ), 'woocommerce:variation' );
	}

	/**
	 * @param mixed $product Product whose stock changed.
	 */
	public function on_stock_object( $product ): void {
		if ( $product instanceof WC_Product ) {
			$this->collect( $product->get_id(), 'woocommerce:stock' );
		}
	}

	/**
	 * @param mixed $variation Variation whose stock changed.
	 */
	public function on_variation_stock_object( $variation ): void {
		if ( $variation instanceof WC_Product ) {
			$this->collect( $this->parent_of( $variation->get_id() ), 'woocommerce:stock' );
		}
	}

	/**
	 * @param mixed $product_id Product or variation id.
	 */
	public function on_stock_id( $product_id ): void {
		$id = (int) $product_id;

		// A variation is only ever cleared through its parent, even when it has lost one.
		$target = 'product_variation' === get_post_type( $id ) ? $this->parent_of( $id ) : $id;

		$this->collect( $target, 'woocommerce:stock' );
	}

	/**
	 * @param mixed $product_ids Products whose sale started or ended.
	 */
	public function on_sales( $product_ids ): void {
		foreach ( (array) $product_ids as $product_id ) {
			$this->collect( (int) $product_id, 'woocommerce:sale' );
		}
	}

	/* --------------------------------------------------------------------- */

	/**
	 * A variation's parent, or 0 when the id is not a variation.
	 *
	 * @param int $id Product or variation id.
	 */
	private function parent_of( int $id ): int {
		if ( $id < 1 ) {
			return 0;
		}

		return 'product_variation' === get_post_type( $id ) ? (int) wp_get_post_parent_id( $id ) : 0;
	}

	/**
	 * Enumerate a product and hand the URLs over, as a post edit does.
	 *
	 * @param int    $product_id Product id.
	 * @param string $reason     Who asked.
	 */
	private function collect( int $product_id, string $reason ): void {
		if ( $product_id < 1 ) {
			return;
		}

		$post_type = (string) get_post_type( $product_id );

		// The settings screen can switch products off like any other post type.
		if ( '' === $post_type || ! Options::post_type_enabled( $post_type ) ) {
			return;
		}

		if ( ! Options::flag( 'purge.woocommerce', true ) ) {
			return;
		}

		$urls = $this->container->get( 'collector' )->for_post( $product_id );

		if ( $urls ) {
			$this->container->get( 'coordinator' )->add( $urls, $reason );
		}
	}
}
