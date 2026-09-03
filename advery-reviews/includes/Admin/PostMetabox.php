<?php
namespace ZaverWeb\Reviews\Admin;

use ZaverWeb\Reviews\Support\Settings;

/**
 * Adds an "Zaver Web Reviews" box to the post/product edit screen showing that
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
				'zaverweb_reviews_box',
				__( 'Zaver Web Reviews', 'zaverweb-reviews' ),
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
			'<div id="zaverweb-reviews-metabox" data-object-type="%s" data-object-id="%d">%s</div>',
			esc_attr( $object_type ),
			(int) $post->ID,
			esc_html__( 'Loading…', 'zaverweb-reviews' )
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

		self::enqueue_assets();
	}

	/**
	 * Enqueue and configure the shared metabox script/style. Used by both the
	 * post/product edit screen and the taxonomy term edit screen. Idempotent.
	 */
	public static function enqueue_assets() {
		wp_enqueue_style( 'zaverweb-reviews-metabox', ZAVERWEB_REVIEWS_URL . 'assets/metabox.css', [], ZAVERWEB_REVIEWS_VERSION );
		wp_enqueue_script( 'zaverweb-reviews-metabox', ZAVERWEB_REVIEWS_URL . 'assets/metabox.js', [], ZAVERWEB_REVIEWS_VERSION, true );

		$user = wp_get_current_user();

		wp_localize_script(
			'zaverweb-reviews-metabox',
			'ZaverWebReviewsMeta',
			[
				'rest'        => esc_url_raw( rest_url( ZAVERWEB_REVIEWS_REST_NAMESPACE ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'currentUser' => [
					'name'  => $user ? $user->display_name : '',
					'email' => $user ? $user->user_email : '',
				],
				'i18n'        => [
					'none'    => __( 'No reviews yet.', 'zaverweb-reviews' ),
					'add'     => __( 'Add a review', 'zaverweb-reviews' ),
					'asMe'    => __( 'Add as me', 'zaverweb-reviews' ),
					'name'    => __( 'Name', 'zaverweb-reviews' ),
					'email'   => __( 'Email (optional)', 'zaverweb-reviews' ),
					'content' => __( 'Review', 'zaverweb-reviews' ),
					'rating'  => __( 'Rating', 'zaverweb-reviews' ),
					'save'    => __( 'Add review', 'zaverweb-reviews' ),
					'approve' => __( 'Approve', 'zaverweb-reviews' ),
					'pending' => __( 'Pending', 'zaverweb-reviews' ),
					'spam'    => __( 'Spam', 'zaverweb-reviews' ),
					'trash'   => __( 'Trash', 'zaverweb-reviews' ),
					'delete'  => __( 'Delete', 'zaverweb-reviews' ),
					'error'   => __( 'Something went wrong.', 'zaverweb-reviews' ),
				],
			]
		);
	}
}
