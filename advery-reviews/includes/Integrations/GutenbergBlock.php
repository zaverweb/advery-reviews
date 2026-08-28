<?php
namespace Advery\Reviews\Integrations;

use Advery\Reviews\Frontend\Display;
use Advery\Reviews\Support\Targets;

/**
 * A dynamic (server-rendered) Gutenberg block that places the reviews widget.
 * Server-rendered so the markup is identical to the shortcode/Elementor output
 * (same styling, loading modes, schema) and crawlable. The editor gets a live
 * ServerSideRender preview via a tiny no-build script.
 */
class GutenbergBlock {

	public function register() {
		add_action( 'init', [ $this, 'register_block' ] );
	}

	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'advery-reviews-block',
			ADVERY_REVIEWS_URL . 'assets/block.js',
			[ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ],
			ADVERY_REVIEWS_VERSION,
			true
		);

		register_block_type(
			'advery/reviews',
			[
				'api_version'     => 2,
				'editor_script'   => 'advery-reviews-block',
				'render_callback' => [ $this, 'render' ],
				'attributes'      => [
					'source' => [ 'type' => 'string', 'default' => 'current' ],
					'postId' => [ 'type' => 'number', 'default' => 0 ],
				],
			]
		);
	}

	/**
	 * @param array $attributes
	 * @return string
	 */
	public function render( $attributes ) {
		$source  = isset( $attributes['source'] ) ? $attributes['source'] : 'current';
		$post_id = ( 'custom' === $source && ! empty( $attributes['postId'] ) )
			? (int) $attributes['postId']
			: (int) get_the_ID();

		if ( ! $post_id ) {
			$target = Targets::current();
			if ( ! $target ) {
				return '';
			}
			return ( new Display() )->render( $target[0], $target[1] );
		}

		$object_type = ( 'product' === get_post_type( $post_id ) ) ? 'product' : 'post';

		if ( ! Targets::is_enabled( $object_type, $post_id ) ) {
			// Show a hint in the editor; nothing on the front end.
			return current_user_can( 'edit_posts' )
				? '<div class="advery-reviews advery-reviews--placeholder">' . esc_html__( 'Reviews are not enabled for this content type. Enable it in Advery Reviews → Settings.', 'advery-reviews' ) . '</div>'
				: '';
		}

		return ( new Display() )->render( $object_type, $post_id );
	}
}
