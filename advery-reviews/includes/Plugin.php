<?php
namespace Advery\Reviews;

use Advery\Reviews\Frontend\Display;
use Advery\Reviews\Frontend\CommentsTakeover;
use Advery\Reviews\Support\Maintenance;
use Advery\Reviews\Schema\SchemaBridge;
use Advery\Reviews\Schema\StandaloneSchema;
use Advery\Reviews\Integrations\WooSchema;
use Advery\Reviews\Integrations\WooTakeover;
use Advery\Reviews\Integrations\ElementorBridge;
use Advery\Reviews\Integrations\GutenbergBlock;
use Advery\Reviews\Email\Notifier;
use Advery\Reviews\Email\Digest;
use Advery\Reviews\Admin\AdminPage;
use Advery\Reviews\Admin\DashboardWidget;
use Advery\Reviews\Admin\PostMetabox;
use Advery\Reviews\Admin\TermMetabox;
use Advery\Reviews\Rest\RestController;
use Advery\Reviews\Database\Installer;

/**
 * Bootstrapper: wires the plugin's subsystems and nothing else. The plugin is
 * fully standalone (collection, moderation, display, email); the schema bridge
 * only does anything when Advery Schema Plus is active — its filter simply
 * never fires otherwise.
 */
class Plugin {

	public function boot() {
		load_plugin_textdomain( 'advery-reviews', false, dirname( plugin_basename( ADVERY_REVIEWS_FILE ) ) . '/languages' );

		// Front-end display + submission.
		( new Display() )->register();

		// Optional takeover of the native comments area (no page builder needed).
		( new CommentsTakeover() )->register();

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
