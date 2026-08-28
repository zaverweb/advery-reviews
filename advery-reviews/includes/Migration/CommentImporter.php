<?php
namespace Advery\Reviews\Migration;

use Advery\Reviews\Database\Installer;
use Advery\Reviews\Database\ReviewRepository;
use Advery\Reviews\Support\Sanitizer;

/**
 * Imports existing WordPress post comments and WooCommerce product reviews into
 * the plugin's tables, mapping every field so nothing is lost and de-duplicating
 * on re-run via (external_source, external_id). Non-destructive by default: the
 * source comments are copied, not moved, unless the owner explicitly asks to
 * delete them. Comments the plugin itself exported are skipped, so import and
 * export can't feed each other in a loop.
 */
class CommentImporter {

	/** Comment meta flag set on comments the plugin exported (loop guard). */
	const EXPORTED_FLAG = '_advery_exported';

	/**
	 * How many comments are available to import, and how many are already in.
	 *
	 * @return array{wp:int,wc:int,imported:int}
	 */
	public static function preview() {
		global $wpdb;
		$c = $wpdb->comments;
		$m = $wpdb->commentmeta;

		$exclude = " AND NOT EXISTS (SELECT 1 FROM {$m} em WHERE em.comment_id = {$c}.comment_ID AND em.meta_key = '" . esc_sql( self::EXPORTED_FLAG ) . "')";

		$wp = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c} WHERE comment_type IN ('','comment'){$exclude}" );
		$wc = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c} WHERE comment_type = 'review'{$exclude}" );

		$reviews  = Installer::reviews_table();
		$imported = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$reviews} WHERE external_source IN ('wp_comment','wc_review')" );

		return [ 'wp' => $wp, 'wc' => $wc, 'imported' => $imported ];
	}

	/**
	 * Import one batch.
	 *
	 * @param array $args { sources:string[], update_existing:bool,
	 *                      delete_source:bool, limit:int, offset:int }
	 * @return array counts + paging
	 */
	public static function run( array $args ) {
		global $wpdb;

		$sources         = (array) ( $args['sources'] ?? [ 'wp_comment', 'wc_review' ] );
		$update_existing = ! empty( $args['update_existing'] );
		$delete_source   = ! empty( $args['delete_source'] );
		$limit           = max( 1, min( 500, (int) ( $args['limit'] ?? 100 ) ) );
		$offset          = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$types = [];
		if ( in_array( 'wp_comment', $sources, true ) ) {
			$types[] = '';
			$types[] = 'comment';
		}
		if ( in_array( 'wc_review', $sources, true ) ) {
			$types[] = 'review';
		}
		if ( empty( $types ) ) {
			return self::result( 0, 0, 0, 0, true, $offset );
		}

		$c            = $wpdb->comments;
		$m            = $wpdb->commentmeta;
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		$sql = "SELECT * FROM {$c}
			WHERE comment_type IN ({$placeholders})
			AND comment_approved IN ('0','1','spam','trash')
			AND NOT EXISTS (SELECT 1 FROM {$m} em WHERE em.comment_id = {$c}.comment_ID AND em.meta_key = %s)
			ORDER BY comment_ID ASC
			LIMIT %d OFFSET %d";

		$params   = array_merge( $types, [ self::EXPORTED_FLAG, $limit, $offset ] );
		$comments = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		$imported = 0;
		$updated  = 0;
		$skipped  = 0;

		foreach ( $comments as $comment ) {
			$outcome = self::import_one( $comment, $update_existing );
			if ( 'imported' === $outcome ) {
				$imported++;
			} elseif ( 'updated' === $outcome ) {
				$updated++;
			} else {
				$skipped++;
			}

			if ( $delete_source && 'skipped' !== $outcome ) {
				wp_delete_comment( (int) $comment->comment_ID, true );
			}
		}

		$processed = count( $comments );
		$done      = $processed < $limit;
		// When deleting sources the rows vanish, so keep querying from 0.
		$next      = $delete_source ? 0 : $offset + $processed;

		return self::result( $processed, $imported, $updated, $skipped, $done, $next );
	}

	/**
	 * Map + upsert a single comment. Returns 'imported' | 'updated' | 'skipped'.
	 *
	 * @param object $comment
	 * @param bool   $update_existing
	 * @return string
	 */
	private static function import_one( $comment, $update_existing ) {
		$post_id = (int) $comment->comment_post_ID;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return 'skipped'; // orphan comment
		}

		$is_review   = ( 'review' === $comment->comment_type );
		$source      = $is_review ? 'wc_review' : 'wp_comment';
		$post_type   = get_post_type( $post_id );
		$object_type = ( 'product' === $post_type ) ? 'product' : 'post';

		$rating = (int) get_comment_meta( $comment->comment_ID, 'rating', true );
		$rating = max( 0, min( 5, $rating ) );

		$status = self::map_status( $comment->comment_approved );

		// Preserve any extra comment meta (except the rating we mapped and our
		// own flags) so nothing is lost.
		$extra = [];
		foreach ( get_comment_meta( $comment->comment_ID ) as $key => $vals ) {
			if ( in_array( $key, [ 'rating', self::EXPORTED_FLAG, '_advery_review_id' ], true ) ) {
				continue;
			}
			$extra[ $key ] = is_array( $vals ) && 1 === count( $vals ) ? $vals[0] : $vals;
		}

		$data = [
			'object_type'     => $object_type,
			'object_id'       => $post_id,
			'rating'          => $rating,
			'author_name'     => Sanitizer::text( $comment->comment_author, 150 ),
			'author_email'    => Sanitizer::email( $comment->comment_author_email ),
			'author_user_id'  => (int) $comment->user_id,
			'title'           => '',
			'content'         => Sanitizer::content( $comment->comment_content ),
			'status'          => $status,
			'author_ip'       => (string) $comment->comment_author_IP,
			'external_source' => $source,
			'external_id'     => (int) $comment->comment_ID,
			'created_at'      => $comment->comment_date,
			'meta'            => [ 'comment_meta' => $extra, 'migrated_from' => $source ],
		];

		$existing = ReviewRepository::find_id_by_external( $source, (int) $comment->comment_ID );
		if ( $existing ) {
			if ( ! $update_existing ) {
				return 'skipped';
			}
			ReviewRepository::update(
				$existing,
				[
					'rating'       => $rating,
					'author_name'  => $data['author_name'],
					'author_email' => $data['author_email'],
					'content'      => $data['content'],
					'status'       => $status,
					'created_at'   => $comment->comment_date,
					'meta'         => $data['meta'],
				]
			);
			return 'updated';
		}

		return ReviewRepository::create( $data ) ? 'imported' : 'skipped';
	}

	/**
	 * @param string $approved WP comment_approved value
	 * @return string
	 */
	private static function map_status( $approved ) {
		switch ( (string) $approved ) {
			case '1':
				return 'approved';
			case 'spam':
				return 'spam';
			case 'trash':
			case 'post-trashed':
				return 'trash';
			default:
				return 'pending';
		}
	}

	private static function result( $processed, $imported, $updated, $skipped, $done, $next ) {
		return [
			'processed'   => $processed,
			'imported'    => $imported,
			'updated'     => $updated,
			'skipped'     => $skipped,
			'done'        => (bool) $done,
			'next_offset' => (int) $next,
		];
	}
}
