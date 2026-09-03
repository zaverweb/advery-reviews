<?php
namespace ZaverWeb\Reviews\Integrations\Elementor;

use ZaverWeb\Reviews\Frontend\Display;
use ZaverWeb\Reviews\Support\Targets;

// This file is only ever required from ElementorBridge, after Elementor has
// loaded, so \Elementor\Widget_Base is guaranteed to exist here.
if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

/**
 * Elementor widget that drops the Zaver Web Reviews block onto a page. It renders
 * the exact same server-side markup as the shortcode, so styling, loading modes
 * and schema are identical; the de-dup guard in Display prevents it from also
 * being auto-appended to the content.
 */
class ReviewsWidget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'zaverweb_reviews';
	}

	public function get_title() {
		return __( 'Zaver Web Reviews', 'zaverweb-reviews' );
	}

	public function get_icon() {
		return 'eicon-star-o';
	}

	public function get_categories() {
		return [ 'general' ];
	}

	public function get_keywords() {
		return [ 'reviews', 'ratings', 'testimonials', 'zaverweb' ];
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			[ 'label' => __( 'Reviews', 'zaverweb-reviews' ) ]
		);

		$this->add_control(
			'heading',
			[
				'label'       => __( 'Heading (optional)', 'zaverweb-reviews' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => __( 'e.g. Customer reviews', 'zaverweb-reviews' ),
			]
		);

		$this->add_control(
			'source',
			[
				'label'   => __( 'Show reviews for', 'zaverweb-reviews' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'current',
				'options' => [
					'current' => __( 'The current page', 'zaverweb-reviews' ),
					'custom'  => __( 'A specific item', 'zaverweb-reviews' ),
				],
			]
		);

		$this->add_control(
			'object_type',
			[
				'label'     => __( 'Item type', 'zaverweb-reviews' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => 'post',
				'options'   => [
					'post'    => __( 'Post', 'zaverweb-reviews' ),
					'product' => __( 'Product', 'zaverweb-reviews' ),
					'term'    => __( 'Taxonomy term', 'zaverweb-reviews' ),
				],
				'condition' => [ 'source' => 'custom' ],
			]
		);

		$this->add_control(
			'object_id',
			[
				'label'       => __( 'Post / product / term ID', 'zaverweb-reviews' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'condition'   => [ 'source' => 'custom' ],
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style',
			[ 'label' => __( 'Appearance', 'zaverweb-reviews' ) ]
		);

		$this->add_control(
			'skin',
			[
				'label'   => __( 'Layout style', 'zaverweb-reviews' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'inherit',
				'options' => [
					'inherit' => __( 'Use global setting', 'zaverweb-reviews' ),
					'card'    => __( 'Cards (modern)', 'zaverweb-reviews' ),
					'classic' => __( 'Classic list', 'zaverweb-reviews' ),
					'minimal' => __( 'Minimal', 'zaverweb-reviews' ),
					'bubble'  => __( 'Bubble (chat)', 'zaverweb-reviews' ),
					'quote'   => __( 'Quote (testimonial)', 'zaverweb-reviews' ),
				],
			]
		);

		$this->add_control(
			'avatar',
			[
				'label'   => __( 'Avatar style', 'zaverweb-reviews' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'inherit',
				'options' => [
					'inherit'  => __( 'Use global setting', 'zaverweb-reviews' ),
					'initials' => __( 'Initials (local, no request)', 'zaverweb-reviews' ),
					'default'  => __( 'One default image', 'zaverweb-reviews' ),
					'gravatar' => __( 'Gravatar (external request)', 'zaverweb-reviews' ),
					'none'     => __( 'No avatar', 'zaverweb-reviews' ),
				],
			]
		);

		$this->add_control(
			'heading_colors',
			[
				'label'     => __( 'Colors (override global)', 'zaverweb-reviews' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		// Each control writes a CSS custom property scoped to this widget, so an
		// empty value simply falls back to the global Appearance settings.
		$vars = [
			'accent'     => [ '--zw-accent', __( 'Accent color', 'zaverweb-reviews' ) ],
			'accent_ink' => [ '--zw-accent-ink', __( 'Text on accent', 'zaverweb-reviews' ) ],
			'star'       => [ '--zw-star', __( 'Star color', 'zaverweb-reviews' ) ],
			'text'       => [ '--zw-text', __( 'Text color', 'zaverweb-reviews' ) ],
			'surface'    => [ '--zw-surface', __( 'Card / form background', 'zaverweb-reviews' ) ],
			'border'     => [ '--zw-border', __( 'Borders & dividers', 'zaverweb-reviews' ) ],
		];
		foreach ( $vars as $key => $meta ) {
			$this->add_control(
				'color_' . $key,
				[
					'label'     => $meta[1],
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => [ '{{WRAPPER}} .zaverweb-reviews' => $meta[0] . ': {{VALUE}};' ],
				]
			);
		}

		$this->add_control(
			'heading_sizes',
			[
				'label'     => __( 'Sizes (override global)', 'zaverweb-reviews' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);
		$sliders = [
			'star_size' => [ '--zw-star-size', __( 'Star size', 'zaverweb-reviews' ), 12, 40 ],
			'font_size' => [ '--zw-font-size', __( 'Base font size', 'zaverweb-reviews' ), 12, 24 ],
			'radius'    => [ '--zw-radius', __( 'Corner radius', 'zaverweb-reviews' ), 0, 40 ],
			'max_width' => [ '--zw-max-width', __( 'Max width', 'zaverweb-reviews' ), 240, 1200 ],
		];
		foreach ( $sliders as $key => $meta ) {
			$this->add_control(
				'size_' . $key,
				[
					'label'     => $meta[1],
					'type'      => \Elementor\Controls_Manager::SLIDER,
					'size_units' => [ 'px' ],
					'range'     => [ 'px' => [ 'min' => $meta[2], 'max' => $meta[3] ] ],
					'selectors' => [ '{{WRAPPER}} .zaverweb-reviews' => $meta[0] . ': {{SIZE}}{{UNIT}};' ],
				]
			);
		}

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
				echo '<div class="zaverweb-reviews zaverweb-reviews--placeholder">' .
					esc_html__( 'Zaver Web Reviews will appear here on a page that is an enabled review target.', 'zaverweb-reviews' ) .
					'</div>';
			}
			return;
		}

		if ( ! empty( $settings['heading'] ) ) {
			echo '<h3 class="zaverweb-reviews__heading">' . esc_html( $settings['heading'] ) . '</h3>';
		}
		echo Display::widget( $type, $id, $opts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
