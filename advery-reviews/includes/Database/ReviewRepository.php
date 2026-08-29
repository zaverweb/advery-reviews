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
			'spam_score'      => (int) ( $data['spam_score'] ?? 0 ),
			'meta'            => isset( $data['meta'] ) ? wp_json_encode( $data['meta'] ) : null,
			'external_source' => (string) ( $data['external_source'] ?? '' ),
			'external_id'     => (string) ( $data['external_id'] ?? '' ),
			// Allow importers to preserve the original timestamp.
			'created_at'      => ! empty( $data['created_at'] ) ? $data['created_at'] : current_time( 'mysql' ),
		];

		$ok = $wpdb->insert(
			Installer::reviews_table(),
			$row,
			[ '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
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
		if ( ! empty( $args['object_id'] ) ) {
			$where[]  = 'object_id = %d';
			$params[] = (int) $args['object_id'];
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
	 * Count reviews from an IP or email since a datetime (rate limiting).
	 *
	 * @param string $ip
	 * @param string $email
	 * @param string $since MySQL datetime
	 * @return int
	 */
	public static function count_recent( $ip, $email, $since ) {
		global $wpdb;
		$table = Installer::reviews_table();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND ( ( author_ip <> '' AND author_ip = %s ) OR ( author_email <> '' AND author_email = %s ) )",
				$since,
				$ip,
				$email
			)
		);
	}

	/**
	 * Whether a user has any approved review (trusted fast-track).
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function has_approved_by_user( $user_id ) {
		global $wpdb;
		$table = Installer::reviews_table();
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE author_user_id = %d AND status = 'approved' LIMIT 1",
				$user_id
			)
		);
	}

	/**
	 * Whether identical content was already submitted for this object.
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 * @param string $content
	 * @return bool
	 */
	public static function content_exists( $object_type, $object_id, $content ) {
		global $wpdb;
		$table = Installer::reviews_table();
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE object_type = %s AND object_id = %d AND content = %s LIMIT 1",
				$object_type,
				$object_id,
				$content
			)
		);
	}

	/**
	 * Find a review id by its external source + id (import de-duplication).
	 * external_id is a string so it fits provider ids (e.g. Google review ids)
	 * as well as numeric comment ids.
	 *
	 * @param string $source
	 * @param string $external_id
	 * @return int 0 when none.
	 */
	public static function find_id_by_external( $source, $external_id ) {
		global $wpdb;
		if ( '' === (string) $external_id ) {
			return 0;
		}
		$table = Installer::reviews_table();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE external_source = %s AND external_id = %s LIMIT 1",
				$source,
				(string) $external_id
			)
		);
	}

	/**
	 * Generic column update. Recomputes the object's aggregate when a field
	 * affecting it (status/rating) changes.
	 *
	 * @param int   $id
	 * @param array $fields associative column => value (whitelisted here)
	 * @return bool
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;

		$review = self::find( $id );
		if ( ! $review ) {
			return false;
		}

		$allowed = [ 'rating', 'author_name', 'author_email', 'author_user_id', 'title', 'content', 'status', 'created_at', 'spam_score' ];
		$set     = [];
		$fmt     = [];
		foreach ( $allowed as $col ) {
			if ( ! array_key_exists( $col, $fields ) ) {
				continue;
			}
			$set[ $col ] = $fields[ $col ];
			$fmt[]       = in_array( $col, [ 'rating', 'author_user_id', 'spam_score' ], true ) ? '%d' : '%s';
		}
		if ( array_key_exists( 'meta', $fields ) ) {
			$set['meta'] = wp_json_encode( $fields['meta'] );
			$fmt[]       = '%s';
		}
		if ( empty( $set ) ) {
			return false;
		}

		$ok = false !== $wpdb->update( Installer::reviews_table(), $set, [ 'id' => $id ], $fmt, [ '%d' ] );
		if ( $ok ) {
			StatsRepository::recompute( $review['object_type'], $review['object_id'] );
		}
		return $ok;
	}

	/**
	 * Store (or clear, when $text is empty) the owner's reply on a review, in
	 * its meta. Kept in meta so no schema change is needed.
	 *
	 * @param int    $id
	 * @param string $text
	 * @param string $by
	 * @return bool
	 */
	public static function set_reply( $id, $text, $by = '' ) {
		$review = self::find( $id );
		if ( ! $review ) {
			return false;
		}
		$meta = is_array( $review['meta'] ) ? $review['meta'] : [];
		if ( '' === trim( (string) $text ) ) {
			unset( $meta['reply'], $meta['reply_by'], $meta['reply_at'] );
		} else {
			$meta['reply']    = (string) $text;
			$meta['reply_by'] = (string) $by;
			$meta['reply_at'] = current_time( 'mysql' );
		}
		return self::update( $id, [ 'meta' => $meta ] );
	}

	/**
	 * @param array $row
	 * @return array
	 */
	private static function hydrate( array $row ) {
		$row['id']              = (int) $row['id'];
		$row['object_id']       = (int) $row['object_id'];
		$row['rating']          = (int) $row['rating'];
		$row['author_user_id']  = (int) $row['author_user_id'];
		$row['external_id']     = isset( $row['external_id'] ) ? (string) $row['external_id'] : '';
		$row['external_source'] = isset( $row['external_source'] ) ? $row['external_source'] : '';
		$row['spam_score']      = isset( $row['spam_score'] ) ? (int) $row['spam_score'] : 0;
		if ( isset( $row['meta'] ) && is_string( $row['meta'] ) ) {
			$decoded     = json_decode( $row['meta'], true );
			$row['meta'] = is_array( $decoded ) ? $decoded : [];
		} else {
			$row['meta'] = [];
		}
		return $row;
	}
}
