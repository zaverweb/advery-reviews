<?php
namespace ZaverWeb\Reviews\Integrations;

/**
 * Registers the Zaver Web Reviews Elementor widget when Elementor is active.
 * Elementor is optional and feature-detected — nothing here loads (and the
 * widget class, which extends an Elementor base class, is never required)
 * unless Elementor fires its registration hook.
 */
class ElementorBridge {

	public function register() {
		// Elementor 3.5+ uses this hook with a manager exposing register().
		add_action( 'elementor/widgets/register', [ $this, 'register_widget' ] );
		// Back-compat for older Elementor (< 3.5).
		add_action( 'elementor/widgets/widgets_registered', [ $this, 'register_widget_legacy' ] );
	}

	/**
	 * @param object $widgets_manager
	 */
	public function register_widget( $widgets_manager ) {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		require_once ZAVERWEB_REVIEWS_PATH . 'includes/Integrations/Elementor/ReviewsWidget.php';
		if ( method_exists( $widgets_manager, 'register' ) ) {
			$widgets_manager->register( new Elementor\ReviewsWidget() );
		}
	}

	/**
	 * @param object $widgets_manager
	 */
	public function register_widget_legacy( $widgets_manager ) {
		if ( ! class_exists( '\Elementor\Widget_Base' ) || ! method_exists( $widgets_manager, 'register_widget_type' ) ) {
			return;
		}
		require_once ZAVERWEB_REVIEWS_PATH . 'includes/Integrations/Elementor/ReviewsWidget.php';
		$widgets_manager->register_widget_type( new Elementor\ReviewsWidget() );
	}
}
