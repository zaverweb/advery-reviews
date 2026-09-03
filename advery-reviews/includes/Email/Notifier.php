<?php
namespace ZaverWeb\Reviews\Email;

use ZaverWeb\Reviews\Support\Settings;
use ZaverWeb\Reviews\Support\Targets;
use ZaverWeb\Reviews\Database\ReviewRepository;

/**
 * Sends the instant "new review" email to the site owner when enabled. Hooked
 * to the `zaverweb_reviews_created` action fired right after a review is stored.
 */
class Notifier {

	public function register() {
		add_action( 'zaverweb_reviews_created', [ $this, 'on_created' ], 10, 1 );
	}

	/**
	 * @param int $review_id
	 */
	public function on_created( $review_id ) {
		if ( ! Settings::get( 'email_instant' ) ) {
			return;
		}
		$review = ReviewRepository::find( (int) $review_id );
		if ( ! $review ) {
			return;
		}

		$label   = Targets::label( $review['object_type'], $review['object_id'] );
		$stars   = $review['rating'] > 0 ? str_repeat( '★', $review['rating'] ) . str_repeat( '☆', 5 - $review['rating'] ) : __( '(no rating)', 'zaverweb-reviews' );
		$admin   = admin_url( 'admin.php?page=zaverweb-reviews' );
		$blog    = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		$subject = sprintf(
			/* translators: 1: site name, 2: item label */
			__( '[%1$s] New review on “%2$s”', 'zaverweb-reviews' ),
			$blog,
			$label
		);

		$lines = [
			sprintf( __( 'Item: %s', 'zaverweb-reviews' ), $label ),
			sprintf( __( 'Rating: %s', 'zaverweb-reviews' ), $stars ),
			sprintf( __( 'By: %1$s <%2$s>', 'zaverweb-reviews' ), $review['author_name'], $review['author_email'] ),
			sprintf( __( 'Status: %s', 'zaverweb-reviews' ), $review['status'] ),
			'',
			$review['title'] ? $review['title'] : '',
			$review['content'],
			'',
			sprintf( __( 'Moderate: %s', 'zaverweb-reviews' ), $admin ),
		];

		wp_mail( Settings::recipient(), $subject, implode( "\n", array_filter( $lines, static function ( $l ) {
			return '' !== $l || true; // keep blank separators
		} ) ) );
	}
}
