<?php
namespace Advery\Reviews\Database;

/**
 * CRUD and queries for reviews. Every write that can change what is publicly
 * visible (a new approved review, a status change, a delete) refreshes the
 * aggregate cache for the affected object so reads stay O(1).
 */
class ReviewRepository {

	const STATUSES     = [ 'pending', 'approved', 'spam', 'trash' ];
	const OBJECT_TYPES = [ 'post', 'product', 'term' ];

	/**
	 * Shared WHERE/JOIN builder for the *reviewed-item* dimensions used by both
	 * the admin listing and the reports: a time window and — crucially — the
	 * real post type / taxonomy of the reviewed object.
	 *
	 * Reviews only store a coarse `object_type` (post / product / term), so
	 * filtering by an actual post type (e.g. a custom `doctor` CPT) or a
	 * taxonomy (e.g. a custom `تخصص`) resolves the object against wp_posts /
	 * wp_term_taxonomy with a join. The reviews table alias is always `r`.
	 *
	 * @param array $args { since, object_type, object_id, post_type, taxonomy }
	 * @return array{join:string,where:string[],params:array}
	 */
	private static function scope( array $args ) {
		global $wpdb;
		$join   = '';
		$where  = [];
		$params = [];

		if ( ! empty( $args['since'] ) ) {
			$where[]  = 'r.created_at >= %s';
			$params[] = $args['since'];
		}
		if ( ! empty( $args['object_type'] ) && in_array( $args['object_type'], self::OBJECT_TYPES, true ) ) {
			$where[]  = 'r.object_type = %s';
			$params[] = $args['object_type'];
		}
		if ( ! empty( $args['object_id'] ) ) {
			$where[]  = 'r.object_id = %d';
			$params[] = (int) $args['object_id'];
		}
		if ( ! empty( $args['post_type'] ) ) {
			// Products are posts too, so a post-type filter spans post + product.
			$join    .= " JOIN {$wpdb->posts} arp ON arp.ID = r.object_id ";
			$where[]  = "r.object_type IN ('post','product')";
			$where[]  = 'arp.post_type = %s';
			$params[] = sanitize_key( $args['post_type'] );
		}
		if ( ! empty( $args['taxonomy'] ) ) {
			$join    .= " JOIN {$wpdb->term_taxonomy} artt ON artt.term_id = r.object_id ";
			$where[]  = "r.object_type = 'term'";
			$where[]  = 'artt.taxonomy = %s';
			$params[] = sanitize_key( $args['taxonomy'] );
		}

		return [ 'join' => $join, 'where' => $where, 'params' => $params ];
	}

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

		$scope  = self::scope( $args );
		$where  = array_merge( [ '1=1' ], $scope['where'] );
		$params = $scope['params'];

		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'r.status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['rating'] ) ) {
			$where[]  = 'r.rating = %d';
			$params[] = (int) $args['rating'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(r.author_name LIKE %s OR r.author_email LIKE %s OR r.title LIKE %s OR r.content LIKE %s)';
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

		$total_sql = "SELECT COUNT(*) FROM {$table} r {$scope['join']} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) : $wpdb->get_var( $total_sql ) );

		$list_params = array_merge( $params, [ $per_page, $offset ] );
		$list_sql    = "SELECT r.* FROM {$table} r {$scope['join']} WHERE {$where_sql} ORDER BY r.{$orderby} {$order} LIMIT %d OFFSET %d";
		$rows        = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );

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

	/* ---------------- Reporting ---------------- */

	/**
	 * Headline totals for the reports screen. Trash is counted separately and
	 * excluded from `objects`/`avg_rating`, which describe live review activity.
	 *
	 * @param array $args { since?: string MySQL datetime (empty = all time) }
	 * @return array
	 */
	public static function report_summary( array $args = [] ) {
		global $wpdb;
		$table = Installer::reviews_table();

		$scope     = self::scope( $args );
		$where     = array_merge( [ '1=1' ], $scope['where'] );
		$params    = $scope['params'];
		$where_sql = implode( ' AND ', $where );

		$sql = "SELECT
				COUNT(*) AS total,
				SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) AS approved,
				SUM(CASE WHEN r.status = 'pending' THEN 1 ELSE 0 END) AS pending,
				SUM(CASE WHEN r.status = 'spam' THEN 1 ELSE 0 END) AS spam,
				SUM(CASE WHEN r.status = 'trash' THEN 1 ELSE 0 END) AS trash,
				COUNT(DISTINCT CASE WHEN r.status <> 'trash' THEN CONCAT(r.object_type, ':', r.object_id) END) AS objects,
				COALESCE( AVG( CASE WHEN r.status = 'approved' AND r.rating > 0 THEN r.rating END ), 0 ) AS avg_rating
			FROM {$table} r {$scope['join']}
			WHERE {$where_sql}";

		$row = $params
			? $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A )
			: $wpdb->get_row( $sql, ARRAY_A );

		return [
			'total'      => (int) ( $row['total'] ?? 0 ),
			'approved'   => (int) ( $row['approved'] ?? 0 ),
			'pending'    => (int) ( $row['pending'] ?? 0 ),
			'spam'       => (int) ( $row['spam'] ?? 0 ),
			'trash'      => (int) ( $row['trash'] ?? 0 ),
			'objects'    => (int) ( $row['objects'] ?? 0 ),
			'avg_rating' => round( (float) ( $row['avg_rating'] ?? 0 ), 2 ),
		];
	}

	/**
	 * The reviewed objects with the most reviews — the core of the report
	 * ("which page/business/category got the most reviews"). Ordered by total
	 * reviews (trash excluded); each row carries a per-status breakdown and the
	 * average of its approved ratings.
	 *
	 * @param array $args { since?: string, limit?: int, object_type?: string }
	 * @return array[]
	 */
	public static function top_objects( array $args = [] ) {
		global $wpdb;
		$table = Installer::reviews_table();
		$limit = max( 1, min( 100, (int) ( $args['limit'] ?? 20 ) ) );

		$scope     = self::scope( $args );
		$where     = array_merge( [ "r.status <> 'trash'" ], $scope['where'] );
		$params    = $scope['params'];
		$where_sql = implode( ' AND ', $where );

		$sql = "SELECT r.object_type, r.object_id,
				COUNT(*) AS total,
				SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) AS approved,
				SUM(CASE WHEN r.status = 'pending' THEN 1 ELSE 0 END) AS pending,
				SUM(CASE WHEN r.status = 'spam' THEN 1 ELSE 0 END) AS spam,
				COALESCE( AVG( CASE WHEN r.status = 'approved' AND r.rating > 0 THEN r.rating END ), 0 ) AS avg_rating
			FROM {$table} r {$scope['join']}
			WHERE {$where_sql}
			GROUP BY r.object_type, r.object_id
			ORDER BY total DESC, approved DESC
			LIMIT %d";

		$params[] = $limit;
		$rows      = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return array_map(
			static function ( $r ) {
				return [
					'object_type' => $r['object_type'],
					'object_id'   => (int) $r['object_id'],
					'total'       => (int) $r['total'],
					'approved'    => (int) $r['approved'],
					'pending'     => (int) $r['pending'],
					'spam'        => (int) $r['spam'],
					'avg_rating'  => round( (float) $r['avg_rating'], 2 ),
				];
			},
			$rows ?: []
		);
	}

	/**
	 * Review totals grouped by object type (post/product/term).
	 *
	 * @param array $args { since?: string }
	 * @return array[]
	 */
	public static function counts_by_type( array $args = [] ) {
		global $wpdb;
		$table = Installer::reviews_table();

		$scope     = self::scope( $args );
		$where     = array_merge( [ "r.status <> 'trash'" ], $scope['where'] );
		$params    = $scope['params'];
		$where_sql = implode( ' AND ', $where );

		$sql = "SELECT r.object_type,
				COUNT(*) AS total,
				SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) AS approved,
				COALESCE( AVG( CASE WHEN r.status = 'approved' AND r.rating > 0 THEN r.rating END ), 0 ) AS avg_rating
			FROM {$table} r {$scope['join']}
			WHERE {$where_sql}
			GROUP BY r.object_type
			ORDER BY total DESC";

		$rows = $params
			? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A )
			: $wpdb->get_results( $sql, ARRAY_A );

		return array_map(
			static function ( $r ) {
				return [
					'object_type' => $r['object_type'],
					'total'       => (int) $r['total'],
					'approved'    => (int) $r['approved'],
					'avg_rating'  => round( (float) $r['avg_rating'], 2 ),
				];
			},
			$rows ?: []
		);
	}

	/**
	 * How many approved reviews sit at each star rating (5..1). Always returns
	 * all five buckets so the chart has a stable shape.
	 *
	 * @param array $args { since?: string }
	 * @return array<int,int> rating => count
	 */
	public static function rating_distribution( array $args = [] ) {
		global $wpdb;
		$table = Installer::reviews_table();

		$scope     = self::scope( $args );
		$where     = array_merge( [ "r.status = 'approved'", 'r.rating > 0' ], $scope['where'] );
		$params    = $scope['params'];
		$where_sql = implode( ' AND ', $where );

		$sql = "SELECT r.rating AS rating, COUNT(*) AS n FROM {$table} r {$scope['join']} WHERE {$where_sql} GROUP BY r.rating";
		$rows = $params
			? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A )
			: $wpdb->get_results( $sql, ARRAY_A );

		$out = [ 5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0 ];
		foreach ( $rows ?: [] as $r ) {
			$rating = (int) $r['rating'];
			if ( isset( $out[ $rating ] ) ) {
				$out[ $rating ] = (int) $r['n'];
			}
		}
		return $out;
	}

	/**
	 * Reviews per calendar month over the trailing window (for the trend chart).
	 * Grouped in site-local time to match how created_at is stored.
	 *
	 * @param int   $months
	 * @param array $args { post_type, taxonomy, object_type, object_id } — the
	 *                     item-type filter (its own trailing-month window is used,
	 *                     so `since` in $args is ignored).
	 * @return array[] { ym: 'YYYY-MM', total: int, approved: int }
	 */
	public static function monthly_counts( $months = 12, array $args = [] ) {
		global $wpdb;
		$table  = Installer::reviews_table();
		$months = max( 1, min( 36, (int) $months ) );
		$since  = gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp' ) - ( $months - 1 ) * MONTH_IN_SECONDS );

		// Type/object filters share the scope builder; the window is our own.
		unset( $args['since'] );
		$scope     = self::scope( $args );
		$where     = array_merge( [ "r.status <> 'trash'", 'r.created_at >= %s' ], $scope['where'] );
		$params    = array_merge( [ $since ], $scope['params'] );
		$where_sql = implode( ' AND ', $where );

		$sql = "SELECT DATE_FORMAT(r.created_at, '%%Y-%%m') AS ym,
				COUNT(*) AS total,
				SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) AS approved
			FROM {$table} r {$scope['join']}
			WHERE {$where_sql}
			GROUP BY ym
			ORDER BY ym ASC";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return array_map(
			static function ( $r ) {
				return [
					'ym'       => (string) $r['ym'],
					'total'    => (int) $r['total'],
					'approved' => (int) $r['approved'],
				];
			},
			$rows ?: []
		);
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
