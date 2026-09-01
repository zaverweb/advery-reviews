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
			'heading',
			[
				'label'       => __( 'Heading (optional)', 'advery-reviews' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => __( 'e.g. Customer reviews', 'advery-reviews' ),
			]
		);

		$this->add_control(
			'source',
			[
				'label'   => __( 'Show reviews for', 'advery-reviews' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'current',
				'options' => [
					'current' => __( 'The current page', 'advery-reviews' ),
					'custom'  => __( 'A specific item', 'advery-reviews' ),
				],
			]
		);

		$this->add_control(
			'object_type',
			[
				'label'     => __( 'Item type', 'advery-reviews' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => 'post',
				'options'   => [
					'post'    => __( 'Post', 'advery-reviews' ),
					'product' => __( 'Product', 'advery-reviews' ),
					'term'    => __( 'Taxonomy term', 'advery-reviews' ),
				],
				'condition' => [ 'source' => 'custom' ],
			]
		);

		$this->add_control(
			'object_id',
			[
				'label'       => __( 'Post / product / term ID', 'advery-reviews' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'condition'   => [ 'source' => 'custom' ],
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style',
			[ 'label' => __( 'Appearance', 'advery-reviews' ) ]
		);

		$this->add_control(
			'skin',
			[
				'label'   => __( 'Layout style', 'advery-reviews' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'inherit',
				'options' => [
					'inherit' => __( 'Use global setting', 'advery-reviews' ),
					'card'    => __( 'Cards (modern)', 'advery-reviews' ),
					'classic' => __( 'Classic list', 'advery-reviews' ),
				],
			]
		);

		$this->add_control(
			'avatar',
			[
				'label'   => __( 'Avatar style', 'advery-reviews' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'inherit',
				'options' => [
					'inherit'  => __( 'Use global setting', 'advery-reviews' ),
					'initials' => __( 'Initials (local, no request)', 'advery-reviews' ),
					'default'  => __( 'One default image', 'advery-reviews' ),
					'gravatar' => __( 'Gravatar (external request)', 'advery-reviews' ),
					'none'     => __( 'No avatar', 'advery-reviews' ),
				],
			]
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		// Per-widget overrides (empty ⇒ the global Appearance settings).
		$opts = [];
		if ( ! empty( $settings['skin'] ) && 'inherit' !== $settings['skin'] ) {
			$opts['skin'] = $settings['skin'];
		}
		if ( ! empty( $settings['avatar'] ) && 'inherit' !== $settings['avatar'] ) {
			$opts['avatar'] = $settings['avatar'];
		}

		// Resolve the target.
		$type = '';
		$id   = 0;
		if ( 'custom' === ( $settings['source'] ?? 'current' ) && ! empty( $settings['object_id'] ) ) {
			$id   = (int) $settings['object_id'];
			$type = in_array( ( $settings['object_type'] ?? '' ), [ 'post', 'product', 'term' ], true ) ? $settings['object_type'] : 'post';
		} else {
			$target = Targets::current();
			if ( $target ) {
				list( $type, $id ) = $target;
			}
		}

		if ( ! $type || ! $id ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="advery-reviews advery-reviews--placeholder">' .
					esc_html__( 'Advery Reviews will appear here on a page that is an enabled review target.', 'advery-reviews' ) .
					'</div>';
			}
			return;
		}

		if ( ! empty( $settings['heading'] ) ) {
			echo '<h3 class="advery-reviews__heading">' . esc_html( $settings['heading'] ) . '</h3>';
		}
		echo Display::widget( $type, $id, $opts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
