<?php
namespace ZaverWeb\Reviews\Integrations;

/**
 * Thin read-only bridge to WooCommerce's native product ratings (which Woo
 * stores as comment meta). Used to optionally merge Woo's existing reviews into
 * the aggregate the plugin reports, without collecting or duplicating them.
 */
class WooCommerce {

	/**
	 * @return bool
	 */
	public static function active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Native rating for a product: average + count.
	 *
	 * @param int $product_id
	 * @return array{count:int,avg:float}
	 */
	public static function native_rating( $product_id ) {
		if ( ! self::active() ) {
			return [ 'count' => 0, 'avg' => 0.0 ];
		}
		$product = wc_get_product( (int) $product_id );
		if ( ! $product ) {
			return [ 'count' => 0, 'avg' => 0.0 ];
		}
		return [
			'count' => (int) $product->get_review_count(),
			'avg'   => (float) $product->get_average_rating(),
		];
	}
}
