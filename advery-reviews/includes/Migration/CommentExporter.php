<?php
namespace Advery\Reviews\Migration;

use Advery\Reviews\Database\Installer;
use Advery\Reviews\Database\ReviewRepository;

/**
 * Reverse migration: recreates native WordPress comments / WooCommerce reviews
 * from the plugin's reviews, for reversibility and interoperability with tools
 * that read the comment tables. Only *natively collected* reviews are exported
 * (never ones that were themselves imported from comments), and each created
 * comment is flagged so the importer skips it — so the two directions never
 * loop. Idempotent: a review already linked to a comment is skipped.
 */
class CommentExporter {

	/**
	 * @return array{eligible:int,exported:int}
	 */
	public static function preview() {
		global $wpdb;
		$reviews = Installer::reviews_table();

		$eligible = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$reviews}
			 WHERE external_source NOT IN ('wp_comment','wc_review')
			 AND object_type IN ('post','product')"
		);
		// Rough "already exported" = reviews whose meta references a comment.
		$exported = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$reviews}
			 WHERE meta LIKE '%exported_comment_id%'"
		);

		return [ 'eligible' => $eligible, 'exported' => $exported ];
	}

	/**
	 * Export one batch.
	 *
	 * @param array $args { limit:int, offset:int }
	 * @return array
	 */
	public static function run( array $args ) {
		global $wpdb;

		$limit  = max( 1, min( 500, (int) ( $args['limit'] ?? 100 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$reviews = Installer::reviews_table();
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$reviews}
				 WHERE external_source NOT IN ('wp_comment','wc_review')
				 AND object_type IN ('post','product')
				 ORDER BY id ASC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		$exported = 0;
		$skipped  = 0;

		foreach ( $rows as $row ) {
			$meta = is_string( $row['meta'] ) ? json_decode( $row['meta'], true ) : [];
			$meta = is_array( $meta ) ? $meta : [];

			// Already linked to a still-existing comment → skip (idempotent).
			if ( ! empty( $meta['exported_comment_id'] ) && get_comment( (int) $meta['exported_comment_id'] ) ) {
				$skipped++;
				continue;
			}
			if ( ! get_post( (int) $row['object_id'] ) ) {
				$skipped++;
				continue;
			}

			$comment_id = self::export_one( $row );
			if ( $comment_id ) {
				$meta['exported_comment_id'] = $comment_id;
				ReviewRepository::update( (int) $row['id'], [ 'meta' => $meta ] );
				$exported++;
			} else {
				$skipped++;
			}
		}

		$processed = count( $rows );
		return [
			'processed'   => $processed,
			'exported'    => $exported,
			'skipped'     => $skipped,
			'done'        => $processed < $limit,
			'next_offset' => $offset + $processed,
		];
	}

	/**
	 * Create the comment for one review. Returns the new comment id (0 on fail).
	 *
	 * @param array $row
	 * @return int
	 */
	private static function export_one( array $row ) {
		$is_product   = ( 'product' === $row['object_type'] );
		$comment_type = $is_product ? 'review' : 'comment';

		$approved = 'approved' === $row['status'] ? 1 : ( 'spam' === $row['status'] ? 'spam' : 0 );

		$comment_id = wp_insert_comment(
			[
				'comment_post_ID'      => (int) $row['object_id'],
				'comment_author'       => $row['author_name'],
				'comment_author_email' => $row['author_email'],
				'comment_content'      => $row['content'],
				'comment_type'         => $comment_type,
				'comment_approved'     => $approved,
				'comment_date'         => $row['created_at'],
				'user_id'              => (int) $row['author_user_id'],
				'comment_agent'        => 'AdveryReviews',
			]
		);

		if ( ! $comment_id ) {
			return 0;
		}

		if ( (int) $row['rating'] > 0 ) {
			add_comment_meta( $comment_id, 'rating', (int) $row['rating'] );
		}
		// Loop guard + back-reference.
		add_comment_meta( $comment_id, CommentImporter::EXPORTED_FLAG, 1 );
		add_comment_meta( $comment_id, '_advery_review_id', (int) $row['id'] );

		return (int) $comment_id;
	}
}
