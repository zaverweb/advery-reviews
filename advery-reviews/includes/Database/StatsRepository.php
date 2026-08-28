<?php
namespace Advery\Reviews\Database;

/**
 * Maintains and reads the per-object aggregate cache (count + average). The
 * cache is recomputed from approved reviews whenever a review's status changes,
 * so schema output and front-end summaries are a single indexed row read
 * instead of an aggregate query on every page load.
 */
class StatsRepository {

	/**
	 * Recompute and store the aggregate for one object from its APPROVED
	 * reviews. Called after any insert/status change/delete.
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 * @return array The freshly computed stats.
	 */
	public static function recompute( $object_type, $object_id ) {
		global $wpdb;

		$reviews = Installer::reviews_table();
		$stats   = Installer::stats_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS review_count,
					SUM(CASE WHEN rating > 0 THEN 1 ELSE 0 END) AS rating_count,
					COALESCE(SUM(rating), 0) AS rating_sum
				FROM {$reviews}
				WHERE object_type = %s AND object_id = %d AND status = 'approved'",
				$object_type,
				$object_id
			),
			ARRAY_A
		);

		$review_count = (int) ( $row['review_count'] ?? 0 );
		$rating_count = (int) ( $row['rating_count'] ?? 0 );
		$rating_sum   = (int) ( $row['rating_sum'] ?? 0 );
		$rating_avg   = $rating_count > 0 ? round( $rating_sum / $rating_count, 2 ) : 0.0;

		if ( 0 === $review_count ) {
			$wpdb->delete( $stats, [ 'object_type' => $object_type, 'object_id' => $object_id ], [ '%s', '%d' ] );
		} else {
			$wpdb->replace(
				$stats,
				[
					'object_type'  => $object_type,
					'object_id'    => $object_id,
					'review_count' => $review_count,
					'rating_count' => $rating_count,
					'rating_sum'   => $rating_sum,
					'rating_avg'   => $rating_avg,
					'updated_at'   => current_time( 'mysql' ),
				],
				[ '%s', '%d', '%d', '%d', '%d', '%f', '%s' ]
			);
		}

		return [
			'review_count' => $review_count,
			'rating_count' => $rating_count,
			'rating_sum'   => $rating_sum,
			'rating_avg'   => $rating_avg,
		];
	}

	/**
	 * Read the cached aggregate for one object (zeros when none).
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 * @return array{review_count:int,rating_count:int,rating_avg:float}
	 */
	public static function get( $object_type, $object_id ) {
		global $wpdb;

		$stats = Installer::stats_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT review_count, rating_count, rating_avg FROM {$stats} WHERE object_type = %s AND object_id = %d",
				$object_type,
				$object_id
			),
			ARRAY_A
		);

		return [
			'review_count' => (int) ( $row['review_count'] ?? 0 ),
			'rating_count' => (int) ( $row['rating_count'] ?? 0 ),
			'rating_avg'   => (float) ( $row['rating_avg'] ?? 0 ),
		];
	}

	/**
	 * Count reviews in a given status (for the menu badge / dashboard).
	 *
	 * @param string $status
	 * @return int
	 */
	public static function count_by_status( $status ) {
		global $wpdb;
		$reviews = Installer::reviews_table();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$reviews} WHERE status = %s", $status )
		);
	}
}
