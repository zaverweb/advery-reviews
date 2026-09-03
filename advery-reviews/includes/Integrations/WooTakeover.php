<?php
namespace ZaverWeb\Reviews\Integrations;

use ZaverWeb\Reviews\Support\Settings;
use ZaverWeb\Reviews\Support\Aggregate;
use ZaverWeb\Reviews\Frontend\Display;

/**
 * Optionally takes over the WooCommerce product "Reviews" tab so the reviews
 * collected by this plugin show there — with OUR stars and card skin — instead
 * of Woo's own review list/form.
 *
 * This is display-only: it swaps the reviews tab's content (and title count)
 * for our widget via `woocommerce_product_tabs`. The product's structured data
 * is untouched — WooCommerce still owns the product JSON-LD (our own schema
 * always leaves products to Woo), and our reviews are folded into that single
 * `aggregateRating` by WooSchema when "Merge Woo native ratings" is on. So
 * there is never a duplicate/competing aggregateRating.
 */
class WooTakeover {

	public function register() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return; // inert without WooCommerce
		}
		if ( ! Settings::get( 'woo_enabled' ) || ! Settings::get( 'woo_takeover' ) ) {
			return;
		}
		// Run late so we override Woo's own reviews tab.
		add_filter( 'woocommerce_product_tabs', [ $this, 'tabs' ], 98 );
	}

	/**
	 * Replace (or add) the Reviews tab with our own renderer.
	 *
	 * @param array $tabs
	 * @return array
	 */
	public function tabs( $tabs ) {
		$id = $this->product_id();
		if ( ! $id ) {
			return $tabs;
		}

		$count    = (int) Aggregate::for( 'product', $id )['review_count'];
		$priority = isset( $tabs['reviews']['priority'] ) ? $tabs['reviews']['priority'] : 30;

		$tabs['reviews'] = [
			/* translators: %d: number of reviews */
			'title'    => sprintf( __( 'Reviews (%d)', 'zaverweb-reviews' ), $count ),
			'priority' => $priority,
			'callback' => [ $this, 'render' ],
		];

		return $tabs;
	}

	/**
	 * Print our widget inside the product Reviews tab.
	 */
	public function render() {
		$id = $this->product_id();
		if ( $id ) {
			// Display::widget() returns HTML built with esc_* helpers internally.
			echo Display::widget( 'product', $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * @return int Current product id (0 when none).
	 */
	private function product_id() {
		global $product;
		if ( $product && is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			return (int) $product->get_id();
		}
		$gid = get_the_ID();
		return ( $gid && 'product' === get_post_type( $gid ) ) ? (int) $gid : 0;
	}
}
