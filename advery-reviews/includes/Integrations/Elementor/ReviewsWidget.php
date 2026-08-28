<?php
namespace Advery\Reviews\Integrations\Elementor;

use Advery\Reviews\Frontend\Display;
use Advery\Reviews\Support\Targets;

// This file is only ever required from ElementorBridge, after Elementor has
// loaded, so \Elementor\Widget_Base is guaranteed to exist here.
if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

/**
 * Elementor widget that drops the Advery Reviews block onto a page. It renders
 * the exact same server-side markup as the shortcode, so styling, loading modes
 * and schema are identical; the de-dup guard in Display prevents it from also
 * being auto-appended to the content.
 */
class ReviewsWidget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'advery_reviews';
	}

	public function get_title() {
		return __( 'Advery Reviews', 'advery-reviews' );
	}

	public function get_icon() {
		return 'eicon-star-o';
	}

	public function get_categories() {
		return [ 'general' ];
	}

	public function get_keywords() {
		return [ 'reviews', 'ratings', 'testimonials', 'advery' ];
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			[ 'label' => __( 'Reviews', 'advery-reviews' ) ]
		);

		$this->add_control(
			'source',
			[
				'label'   => __( 'Show reviews for', 'advery-reviews' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'current',
				'options' => [
					'current' => __( 'The current page', 'advery-reviews' ),
					'custom'  => __( 'A specific post ID', 'advery-reviews' ),
				],
			]
		);

		$this->add_control(
			'post_id',
			[
				'label'     => __( 'Post ID', 'advery-reviews' ),
				'type'      => \Elementor\Controls_Manager::NUMBER,
				'condition' => [ 'source' => 'custom' ],
			]
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$display  = new Display();

		if ( 'custom' === ( $settings['source'] ?? 'current' ) && ! empty( $settings['post_id'] ) ) {
			$post_id     = (int) $settings['post_id'];
			$object_type = ( 'product' === get_post_type( $post_id ) ) ? 'product' : 'post';
			echo $display->render( $object_type, $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		$target = Targets::current();
		if ( $target ) {
			echo $display->render( $target[0], $target[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			echo '<div class="advery-reviews advery-reviews--placeholder">' .
				esc_html__( 'Advery Reviews will appear here on a page that is an enabled review target.', 'advery-reviews' ) .
				'</div>';
		}
	}
}
