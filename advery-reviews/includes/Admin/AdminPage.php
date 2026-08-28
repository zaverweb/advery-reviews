<?php
namespace Advery\Reviews\Admin;

use Advery\Reviews\Database\Installer;
use Advery\Reviews\Database\ReviewRepository;

/**
 * The top-level "Advery Reviews" admin menu (with a pending-count badge, like
 * core Comments) hosting the React moderation panel. PHP prints a root node and
 * enqueues the built bundle + a small config object; the app does the rest.
 */
class AdminPage {

	const MENU_SLUG = 'advery-reviews';

	public function register_menu() {
		$pending = ReviewRepository::status_counts()['pending'] ?? 0;
		$title   = __( 'Advery Reviews', 'advery-reviews' );
		$menu    = __( 'Reviews', 'advery-reviews' );

		if ( $pending > 0 ) {
			$menu .= sprintf(
				' <span class="awaiting-mod"><span class="pending-count">%d</span></span>',
				(int) $pending
			);
		}

		add_menu_page(
			$title,
			$menu,
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_root' ],
			'dashicons-star-filled',
			26
		);
	}

	public function maybe_upgrade() {
		Installer::maybe_upgrade();
	}

	public function enqueue( $hook ) {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		$asset_file = ADVERY_REVIEWS_PATH . 'build/index.asset.php';
		$asset      = is_readable( $asset_file )
			? require $asset_file
			: [ 'dependencies' => [ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ], 'version' => ADVERY_REVIEWS_VERSION ];

		wp_enqueue_script(
			'advery-reviews-admin',
			ADVERY_REVIEWS_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_enqueue_style( 'wp-components' );
		if ( is_readable( ADVERY_REVIEWS_PATH . 'build/style-index.css' ) ) {
			wp_enqueue_style( 'advery-reviews-admin', ADVERY_REVIEWS_URL . 'build/style-index.css', [ 'wp-components' ], $asset['version'] );
		}

		wp_localize_script(
			'advery-reviews-admin',
			'AdveryReviewsConfig',
			[
				'restUrl' => esc_url_raw( rest_url( ADVERY_REVIEWS_REST_NAMESPACE ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			]
		);

		wp_set_script_translations( 'advery-reviews-admin', 'advery-reviews', ADVERY_REVIEWS_PATH . 'languages' );
	}

	public function render_root() {
		echo '<div class="wrap"><div id="advery-reviews-root"></div></div>';
	}
}
