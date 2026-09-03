<?php
namespace ZaverWeb\Reviews;

use ZaverWeb\Reviews\Frontend\Display;
use ZaverWeb\Reviews\Frontend\CommentsTakeover;
use ZaverWeb\Reviews\Frontend\CommentGuard;
use ZaverWeb\Reviews\Support\Maintenance;
use ZaverWeb\Reviews\Schema\SchemaBridge;
use ZaverWeb\Reviews\Schema\StandaloneSchema;
use ZaverWeb\Reviews\Integrations\WooSchema;
use ZaverWeb\Reviews\Integrations\WooTakeover;
use ZaverWeb\Reviews\Integrations\ElementorBridge;
use ZaverWeb\Reviews\Integrations\GutenbergBlock;
use ZaverWeb\Reviews\Email\Notifier;
use ZaverWeb\Reviews\Email\Digest;
use ZaverWeb\Reviews\Admin\AdminPage;
use ZaverWeb\Reviews\Admin\DashboardWidget;
use ZaverWeb\Reviews\Admin\PostMetabox;
use ZaverWeb\Reviews\Admin\TermMetabox;
use ZaverWeb\Reviews\Rest\RestController;
use ZaverWeb\Reviews\Database\Installer;

/**
 * Bootstrapper: wires the plugin's subsystems and nothing else. The plugin is
 * fully standalone (collection, moderation, display, email); the schema bridge
 * only does anything when Advery Schema Plus is active — its filter simply
 * never fires otherwise.
 */
class Plugin {

	public function boot() {
		load_plugin_textdomain( 'zaverweb-reviews', false, dirname( plugin_basename( ZAVERWEB_REVIEWS_FILE ) ) . '/languages' );

		// One-time rebrand migration (advery_* → zaverweb_* tables/options/cron).
		// No-op once migrated or on a fresh install.
		Installer::maybe_migrate();

		// Front-end display + submission.
		( new Display() )->register();

		// Optional takeover of the native comments area (no page builder needed).
		( new CommentsTakeover() )->register();

		// Guard the native WP comment form against direct-POST spam (or disable it).
		( new CommentGuard() )->register();

		// Diagnostic spam log + its daily auto-purge cron (opt-in; idle when off).
		( new \ZaverWeb\Reviews\AntiSpam\SpamLog() )->register();

		// Table hygiene: remove reviews when their post/term is deleted.
		( new Maintenance() )->register();

		// Schema.org aggregateRating/review injection via the core (no-op without it).
		( new SchemaBridge() )->register();

		// Standalone JSON-LD for when the core isn't used (schema_mode).
		( new StandaloneSchema() )->register();

		// Merge our collected product reviews into WooCommerce's own schema.
		( new WooSchema() )->register();

		// Optionally show our reviews in the Woo product reviews tab (our stars).
		( new WooTakeover() )->register();

		// Gutenberg block (dynamic, server-rendered).
		( new GutenbergBlock() )->register();

		// Elementor widget (feature-detected; inert without Elementor).
		( new ElementorBridge() )->register();

		// Email: instant notification + scheduled digest.
		( new Notifier() )->register();
		( new Digest() )->register();

		// REST API (front + admin).
		add_action(
			'rest_api_init',
			function () {
				( new RestController() )->register_routes();
			}
		);

		if ( is_admin() ) {
			$admin = new AdminPage();
			add_action( 'admin_menu', [ $admin, 'register_menu' ] );
			add_action( 'admin_enqueue_scripts', [ $admin, 'enqueue' ] );
			add_action( 'admin_init', [ $admin, 'maybe_upgrade' ] );

			$widget = new DashboardWidget();
			add_action( 'wp_dashboard_setup', [ $widget, 'register' ] );

			// Reviews box on the post/product edit screen.
			( new PostMetabox() )->register();

			// Reviews section on the taxonomy term edit screen (enabled taxonomies).
			( new TermMetabox() )->register();
		}
	}
}
