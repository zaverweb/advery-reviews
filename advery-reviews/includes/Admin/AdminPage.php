<?php
namespace Advery\Reviews\Admin;

use Advery\Reviews\Database\Installer;
use Advery\Reviews\Database\ReviewRepository;

/**
 * The "Advery Reviews" admin menu with three focused sub-pages — Reviews,
 * Settings and Migration — each its own screen so nothing is one giant scroll.
 * PHP prints a root node tagged with which screen to show and enqueues the
 * built React bundle; the app renders the rest.
 */
class AdminPage {

	const MENU_SLUG      = 'advery-reviews';
	const REPORTS_SLUG   = 'advery-reviews-reports';
	const SETTINGS_SLUG  = 'advery-reviews-settings';
	const MIGRATION_SLUG = 'advery-reviews-migration';

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
			function () {
				$this->render_root( 'reviews' );
			},
			'dashicons-star-filled',
			26
		);

		// Rename the auto-created first submenu item to "All Reviews".
		add_submenu_page(
			self::MENU_SLUG,
			__( 'All Reviews', 'advery-reviews' ),
			__( 'All Reviews', 'advery-reviews' ),
			'manage_options',
			self::MENU_SLUG,
			function () {
				$this->render_root( 'reviews' );
			}
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Reports', 'advery-reviews' ),
			__( 'Reports', 'advery-reviews' ),
			'manage_options',
			self::REPORTS_SLUG,
			function () {
				$this->render_root( 'reports' );
			}
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'advery-reviews' ),
			__( 'Settings', 'advery-reviews' ),
			'manage_options',
			self::SETTINGS_SLUG,
			function () {
				$this->render_root( 'settings' );
			}
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Migration', 'advery-reviews' ),
			__( 'Migration', 'advery-reviews' ),
			'manage_options',
			self::MIGRATION_SLUG,
			function () {
				$this->render_root( 'migration' );
			}
		);
	}

	public function maybe_upgrade() {
		Installer::maybe_upgrade();
	}

	public function enqueue( $hook ) {
		// Load on any of our three screens (top-level + the two submenus).
		if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
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
				'restUrl'   => esc_url_raw( rest_url( ADVERY_REVIEWS_REST_NAMESPACE ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'menuSlug'  => self::MENU_SLUG,
				'reportsUrl'   => esc_url_raw( admin_url( 'admin.php?page=' . self::REPORTS_SLUG ) ),
				'settingsUrl'  => esc_url_raw( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ),
				'migrationUrl' => esc_url_raw( admin_url( 'admin.php?page=' . self::MIGRATION_SLUG ) ),
				'reviewsUrl'   => esc_url_raw( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
			]
		);

		wp_set_script_translations( 'advery-reviews-admin', 'advery-reviews', ADVERY_REVIEWS_PATH . 'languages' );
	}

	/**
	 * @param string $screen 'reviews' | 'settings' | 'migration'
	 */
	public function render_root( $screen ) {
		printf( '<div class="wrap"><div id="advery-reviews-root" data-screen="%s"></div></div>', esc_attr( $screen ) );
	}
}
