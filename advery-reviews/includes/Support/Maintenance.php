<?php
namespace Advery\Reviews\Support;

use Advery\Reviews\Database\Installer;
use Advery\Reviews\Database\StatsRepository;

/**
 * Table hygiene: when the object a review points at goes away (a post/product
 * deleted, or a term deleted), its reviews are removed automatically; an admin
 * can also purge any orphans left behind and optimise the tables. This keeps
 * the two tables lean and prevents "parentless" reviews accumulating.
 */
class Maintenance {

	public function register() {
		// A post/product was permanently deleted.
		add_action( 'deleted_post', [ $this, 'on_deleted_post' ], 10, 1 );
		// A term was deleted.
		add_action( 'delete_term', [ $this, 'on_deleted_term' ], 10, 1 );
	}

	/**
	 * @param int $post_id
	 */
	public function on_deleted_post( $post_id ) {
		self::delete_object( [ 'post', 'product' ], (int) $post_id );
	}

	/**
	 * @param int $term_id
	 */
	public function on_deleted_term( $term_id ) {
		self::delete_object( [ 'term' ], (int) $term_id );
	}

	/**
	 * Delete every review for an object id in the given types, and drop its
	 * aggregate rows.
	 *
	 * @param string[] $types
	 * @param int      $object_id
	 */
	private static function delete_object( array $types, $object_id ) {
		global $wpdb;
		$reviews = Installer::reviews_table();
		$stats   = Installer::stats_table();

		foreach ( $types as $type ) {
			$wpdb->delete( $reviews, [ 'object_type' => $type, 'object_id' => $object_id ], [ '%s', '%d' ] );
			$wpdb->delete( $stats, [ 'object_type' => $type, 'object_id' => $object_id ], [ '%s', '%d' ] );
		}
	}

	/**
	 * Remove reviews whose target no longer exists (posts/products missing from
	 * wp_posts, terms missing from wp_terms), then drop the matching aggregate
	 * rows. Returns the number of reviews removed.
	 *
	 * @return int
	 */
	public static function purge_orphans() {
		global $wpdb;
		$reviews = Installer::reviews_table();
		$stats   = Installer::stats_table();
		$posts   = $wpdb->posts;
		$terms   = $wpdb->terms;

		$removed = 0;

		// Posts / products with no surviving wp_posts row.
		$removed += (int) $wpdb->query(
			"DELETE r FROM {$reviews} r
			 LEFT JOIN {$posts} p ON p.ID = r.object_id
			 WHERE r.object_type IN ('post','product') AND p.ID IS NULL"
		);

		// Terms with no surviving wp_terms row.
		$removed += (int) $wpdb->query(
			"DELETE r FROM {$reviews} r
			 LEFT JOIN {$terms} t ON t.term_id = r.object_id
			 WHERE r.object_type = 'term' AND t.term_id IS NULL"
		);

		// Drop aggregate rows that no longer have any review.
		$wpdb->query(
			"DELETE s FROM {$stats} s
			 LEFT JOIN {$reviews} r
			   ON r.object_type = s.object_type AND r.object_id = s.object_id
			 WHERE r.id IS NULL"
		);

		return $removed;
	}

	/**
	 * Rebuild the aggregate cache for every object that still has reviews (used
	 * after a bulk purge / migration). Bounded work — one grouped query drives it.
	 */
	public static function rebuild_stats() {
		global $wpdb;
		$reviews = Installer::reviews_table();

		$objects = $wpdb->get_results(
			"SELECT DISTINCT object_type, object_id FROM {$reviews}",
			ARRAY_A
		);
		foreach ( $objects ?: [] as $o ) {
			StatsRepository::recompute( $o['object_type'], (int) $o['object_id'] );
		}
	}

	/**
	 * OPTIMIZE both tables (reclaim space, refresh indexes).
	 */
	public static function optimize() {
		global $wpdb;
		// Table names are internal constants, not user input.
		$wpdb->query( 'OPTIMIZE TABLE ' . Installer::reviews_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'OPTIMIZE TABLE ' . Installer::stats_table() );   // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
