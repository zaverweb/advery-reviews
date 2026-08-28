<?php
namespace Advery\Reviews\Database;

/**
 * CRUD and queries for reviews. Every write that can change what is publicly
 * visible (a new approved review, a status change, a delete) refreshes the
 * aggregate cache for the affected object so reads stay O(1).
 */
class ReviewRepository {

	const STATUSES = [ 'pending', 'approved', 'spam', 'trash' ];

	/**
	 * Insert a review. Returns the new id (0 on failure).
	 *
	 * @param array $data
	 * @return int
	 */
	public static function create( array $data ) {
		global $wpdb;

		$row = [
			'object_type'    => isset( $data['object_type'] ) ? $data['object_type'] : 'post',
			'object_id'      => (int) ( $data['object_id'] ?? 0 ),
			'rating'         => max( 0, min( 5, (int) ( $data['rating'] ?? 0 ) ) ),
			'author_name'    => (string) ( $data['author_name'] ?? '' ),
			'author_email'   => (string) ( $data['author_email'] ?? '' ),
			'author_user_id' => (int) ( $data['author_user_id'] ?? 0 ),
			'title'          => (string) ( $data['title'] ?? '' ),
			'content'        => (string) ( $data['content'] ?? '' ),
			'status'         => in_array( ( $data['status'] ?? '' ), self::STATUSES, true ) ? $data['status'] : 'pending',
			'author_ip'      => (string) ( $data['author_ip'] ?? '' ),
			'created_at'     => current_time( 'mysql' ),
		];

		$ok = $wpdb->insert(
			Installer::reviews_table(),
			$row,
			[ '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( ! $ok ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;

		if ( 'approved' === $row['status'] ) {
			StatsRepository::recompute( $row['object_type'], $row['object_id'] );
		}

		return $id;
	}

	/**
	 * @param int $id
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM " . Installer::reviews_table() . " WHERE id = %d", $id ),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Change a review's status and refresh the object's aggregate.
	 *
	 * @param int    $id
	 * @param string $status
	 * @return bool
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		$review = self::find( $id );
		if ( ! $review ) {
			return false;
		}
		$ok = false !== $wpdb->update(
			Installer::reviews_table(),
			[ 'status' => $status ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);
		if ( $ok ) {
			StatsRepository::recompute( $review['object_type'], $review['object_id'] );
		}
		return $ok;
	}

	/**
	 * Permanently delete a review and refresh the object's aggregate.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$review = self::find( $id );
		if ( ! $review ) {
			return false;
		}
		$ok = (bool) $wpdb->delete( Installer::reviews_table(), [ 'id' => $id ], [ '%d' ] );
		if ( $ok ) {
			StatsRepository::recompute( $review['object_type'], $review['object_id'] );
		}
		return $ok;
	}

	/**
	 * Whether a user (by id, or by email for guests) already reviewed an object.
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 * @param int    $user_id
	 * @param string $email
	 * @return bool
	 */
	public static function has_reviewed( $object_type, $object_id, $user_id, $email ) {
		global $wpdb;
		$table = Installer::reviews_table();

		if ( $user_id > 0 ) {
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE object_type = %s AND object_id = %d AND author_user_id = %d LIMIT 1",
					$object_type,
					$object_id,
					$user_id
				)
			);
			if ( $found ) {
				return true;
			}
		}

		if ( '' !== $email ) {
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE object_type = %s AND object_id = %d AND author_email = %s LIMIT 1",
					$object_type,
					$object_id,
					$email
				)
			);
			if ( $found ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Public, approved reviews for one object (for front-end display).
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 * @param int    $limit
	 * @param int    $offset
	 * @return array[]
	 */
	public static function approved_for( $object_type, $object_id, $limit = 20, $offset = 0 ) {
		global $wpdb;
		$table = Installer::reviews_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE object_type = %s AND object_id = %d AND status = 'approved' ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$object_type,
				$object_id,
				$limit,
				$offset
			),
			ARRAY_A
		);
		return array_map( [ self::class, 'hydrate' ], $rows ?: [] );
	}

	/**
	 * Admin listing with filters + pagination.
	 *
	 * @param array $args { status, object_type, search, rating, per_page, page, orderby, order }
	 * @return array{ items: array[], total: int }
	 */
	public static function query( array $args ) {
		global $wpdb;
		$table = Installer::reviews_table();

		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['object_type'] ) ) {
			$where[]  = 'object_type = %s';
			$params[] = $args['object_type'];
		}
		if ( ! empty( $args['rating'] ) ) {
			$where[]  = 'rating = %d';
			$params[] = (int) $args['rating'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(author_name LIKE %s OR author_email LIKE %s OR title LIKE %s OR content LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		$orderby = in_array( ( $args['orderby'] ?? '' ), [ 'created_at', 'rating', 'status' ], true ) ? $args['orderby'] : 'created_at';
		$order   = 'ASC' === strtoupper( $args['order'] ?? 'DESC' ) ? 'ASC' : 'DESC';

		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) : $wpdb->get_var( $total_sql ) );

		$list_params   = array_merge( $params, [ $per_page, $offset ] );
		$list_sql      = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$rows          = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );

		return [
			'items' => array_map( [ self::class, 'hydrate' ], $rows ?: [] ),
			'total' => $total,
		];
	}

	/**
	 * Counts per status, for the admin filter tabs and badge.
	 *
	 * @return array<string,int>
	 */
	public static function status_counts() {
		global $wpdb;
		$table = Installer::reviews_table();
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		$out   = array_fill_keys( self::STATUSES, 0 );
		foreach ( $rows ?: [] as $r ) {
			$out[ $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Most recent reviews (any status) for the dashboard widget.
	 *
	 * @param int $limit
	 * @return array[]
	 */
	public static function recent( $limit = 5 ) {
		global $wpdb;
		$table = Installer::reviews_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", $limit ),
			ARRAY_A
		);
		return array_map( [ self::class, 'hydrate' ], $rows ?: [] );
	}

	/**
	 * Approved reviews created since a timestamp (for the email digest).
	 *
	 * @param string $since MySQL datetime.
	 * @return array[]
	 */
	public static function since( $since ) {
		global $wpdb;
		$table = Installer::reviews_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s ORDER BY created_at DESC", $since ),
			ARRAY_A
		);
		return array_map( [ self::class, 'hydrate' ], $rows ?: [] );
	}

	/**
	 * @param array $row
	 * @return array
	 */
	private static function hydrate( array $row ) {
		$row['id']             = (int) $row['id'];
		$row['object_id']      = (int) $row['object_id'];
		$row['rating']         = (int) $row['rating'];
		$row['author_user_id'] = (int) $row['author_user_id'];
		return $row;
	}
}
