<?php
namespace ZaverWeb\Reviews\Support;

use ZaverWeb\Reviews\Database\StatsRepository;
use ZaverWeb\Reviews\Integrations\WooCommerce;

/**
 * Computes the public rating aggregate for one object, combining the plugin's
 * own approved reviews with (optionally) WooCommerce's native product ratings,
 * per settings. This is the single source both the front-end summary and the
 * schema `aggregateRating` read from.
 */
class Aggregate {

	/**
	 * @param string $object_type
	 * @param int    $object_id
	 * @return array{count:int,avg:float,review_count:int}
	 *   count        number of ratings behind the average
	 *   avg          average rating (0 when none)
	 *   review_count number of reviews (text), for reviewCount
	 */
	public static function for( $object_type, $object_id ) {
		$own          = StatsRepository::get( $object_type, $object_id );
		$rating_count = $own['rating_count'];
		$review_count = $own['review_count'];
		$rating_sum   = (int) round( $own['rating_avg'] * $own['rating_count'] );

		if ( 'product' === $object_type
			&& WooCommerce::active()
			&& Settings::get( 'woo_merge_native' ) ) {
			$native = WooCommerce::native_rating( $object_id );
			if ( $native['count'] > 0 ) {
				$rating_sum   += (int) round( $native['avg'] * $native['count'] );
				$rating_count += $native['count'];
				$review_count += $native['count'];
			}
		}

		$avg = $rating_count > 0 ? round( $rating_sum / $rating_count, 2 ) : 0.0;

		return [
			'count'        => $rating_count,
			'avg'          => $avg,
			'review_count' => $review_count,
		];
	}
}
