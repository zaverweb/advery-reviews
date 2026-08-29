<?php
namespace Advery\Reviews\Admin;

use Advery\Reviews\Support\Settings;

/**
 * Adds an "Advery Reviews" box to the post/product edit screen showing that
 * item's reviews, with the ability to add a review under any name and to
 * approve / mark pending / spam / trash / delete it right there. A tiny
 * vanilla-JS file talks to the REST API, so the heavy React admin bundle is not
 * loaded on every edit screen.
 */
class PostMetabox {

	public function register() {
		add_action( 'add_meta_boxes', [ $this, 'add_boxes' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Post types the box appears on: the enabled ones, plus products when Woo
	 * reviews are on.
	 *
	 * @return string[]
	 */
	private function post_types() {
		$types = (array) Settings::get( 'enabled_post_types', [] );
		if ( Settings::get( 'woo_enabled' ) && class_exists( 'WooCommerce' ) ) {
			$types[] = 'product';
		}
		return array_values( array_unique( array_filter( $types ) ) );
	}

	public function add_boxes() {
		foreach ( $this->post_types() as $pt ) {
			add_meta_box(
				'advery_reviews_box',
				__( 'Advery Reviews', 'advery-reviews' ),
				[ $this, 'render' ],
				$pt,
				'normal',
				'default'
			);
		}
	}

	/**
	 * @param \WP_Post $post
	 */
	public function render( $post ) {
		$object_type = ( 'product' === $post->post_type ) ? 'product' : 'post';
		printf(
			'<div id="advery-reviews-metabox" data-object-type="%s" data-object-id="%d">%s</div>',
			esc_attr( $object_type ),
			(int) $post->ID,
			esc_html__( 'Loading…', 'advery-reviews' )
		);
	}

	public function enqueue( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, $this->post_types(), true ) ) {
			return;
		}

		wp_enqueue_style( 'advery-reviews-metabox', ADVERY_REVIEWS_URL . 'assets/metabox.css', [], ADVERY_REVIEWS_VERSION );
		wp_enqueue_script( 'advery-reviews-metabox', ADVERY_REVIEWS_URL . 'assets/metabox.js', [], ADVERY_REVIEWS_VERSION, true );
		wp_localize_script(
			'advery-reviews-metabox',
			'AdveryReviewsMeta',
			[
				'rest'  => esc_url_raw( rest_url( ADVERY_REVIEWS_REST_NAMESPACE ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => [
					'none'    => __( 'No reviews yet.', 'advery-reviews' ),
					'add'     => __( 'Add a review', 'advery-reviews' ),
					'name'    => __( 'Name', 'advery-reviews' ),
					'email'   => __( 'Email (optional)', 'advery-reviews' ),
					'content' => __( 'Review', 'advery-reviews' ),
					'rating'  => __( 'Rating', 'advery-reviews' ),
					'save'    => __( 'Add review', 'advery-reviews' ),
					'approve' => __( 'Approve', 'advery-reviews' ),
					'pending' => __( 'Pending', 'advery-reviews' ),
					'spam'    => __( 'Spam', 'advery-reviews' ),
					'trash'   => __( 'Trash', 'advery-reviews' ),
					'delete'  => __( 'Delete', 'advery-reviews' ),
					'error'   => __( 'Something went wrong.', 'advery-reviews' ),
				],
			]
		);
	}
}
