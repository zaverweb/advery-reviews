<?php
namespace Advery\Reviews\Integrations;

use Advery\Reviews\Support\Settings;
use Advery\Reviews\Support\Aggregate;
use Advery\Reviews\Database\ReviewRepository;

/**
 * Merges the plugin's collected product reviews into WooCommerce's OWN product
 * JSON-LD, rather than emitting a second, competing schema block. WooCommerce
 * builds the product structured data itself; we hook its
 * `woocommerce_structured_data_product` filter and replace the `aggregateRating`
 * with the combined figure (Woo native + our approved reviews) when the owner
 * has opted into merging. This keeps a single, correct aggregateRating on the
 * product page and avoids the duplicate/conflict the SchemaBridge deliberately
 * left to Woo.
 */
class WooSchema {

	public function register() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		if ( ! Settings::get( 'schema_output' ) || ! Settings::get( 'woo_merge_native' ) ) {
			return;
		}
		add_filter( 'woocommerce_structured_data_product', [ $this, 'merge' ], 20, 2 );
	}

	/**
	 * @param array  $markup  Woo's product structured-data array.
	 * @param object $product WC_Product
	 * @return array
	 */
	public function merge( $markup, $product ) {
		if ( ! is_array( $markup ) || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $markup;
		}

		$id  = (int) $product->get_id();
		$agg = Aggregate::for( 'product', $id ); // native + ours when merging is on

		if ( $agg['count'] < 1 ) {
			return $markup;
		}

		$markup['aggregateRating'] = [
			'@type'       => 'AggregateRating',
			'ratingValue' => (string) $agg['avg'],
			'reviewCount' => (string) max( $agg['review_count'], $agg['count'] ),
			'bestRating'  => '5',
			'worstRating' => '1',
		];

		// Append our approved reviews to whatever Woo already listed.
		$reviews = [];
		foreach ( ReviewRepository::approved_for( 'product', $id, 5 ) as $r ) {
			$node = [
				'@type'         => 'Review',
				'author'        => [ '@type' => 'Person', 'name' => $r['author_name'] ?: __( 'Anonymous', 'advery-reviews' ) ],
				'datePublished' => mysql2date( 'Y-m-d', $r['created_at'] ),
			];
			if ( $r['rating'] > 0 ) {
				$node['reviewRating'] = [ '@type' => 'Rating', 'ratingValue' => (string) $r['rating'], 'bestRating' => '5', 'worstRating' => '1' ];
			}
			if ( '' !== $r['content'] ) {
				$node['reviewBody'] = wp_strip_all_tags( $r['content'] );
			}
			$reviews[] = $node;
		}
		if ( ! empty( $reviews ) ) {
			$existing         = isset( $markup['review'] ) ? (array) $markup['review'] : [];
			$markup['review'] = array_merge( $existing, $reviews );
		}

		return $markup;
	}
}
