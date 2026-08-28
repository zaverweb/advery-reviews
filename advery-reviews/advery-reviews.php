<?php
/**
 * Plugin Name:       Advery Reviews
 * Plugin URI:        https://advery.ca
 * Description:       Fast, self-contained ratings & reviews for any post type, taxonomy term, or WooCommerce product. Collects reviews in its own optimized tables (not one comment split across many meta rows), reads WooCommerce's native ratings, and — when Advery Schema Plus is active — injects aggregateRating/review into the JSON-LD graph. Configurable submission rules, a smooth React moderation panel, a dashboard widget, a pending-count menu badge, and instant + digest email reports.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Advery
 * Author URI:        https://advery.ca
 * Text Domain:       advery-reviews
 * Domain Path:       /languages
 *
 * @package Advery\Reviews
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ADVERY_REVIEWS_VERSION', '0.2.0' );
define( 'ADVERY_REVIEWS_FILE', __FILE__ );
define( 'ADVERY_REVIEWS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ADVERY_REVIEWS_URL', plugin_dir_url( __FILE__ ) );
define( 'ADVERY_REVIEWS_REST_NAMESPACE', 'advery-reviews/v1' );

/**
 * PSR-4 autoloader for the Advery\Reviews\ namespace.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'Advery\\Reviews\\';
		$len    = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class, $len ) ) {
			return;
		}
		$file = ADVERY_REVIEWS_PATH . 'includes/' . str_replace( '\\', '/', substr( $class, $len ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// Activation: create the custom tables and schedule the digest cron.
register_activation_hook(
	__FILE__,
	function () {
		\Advery\Reviews\Database\Installer::install();
		\Advery\Reviews\Email\Digest::reschedule();
	}
);

// Deactivation: clear scheduled events (tables are kept; removed on uninstall).
register_deactivation_hook(
	__FILE__,
	function () {
		\Advery\Reviews\Email\Digest::clear();
	}
);

add_action(
	'plugins_loaded',
	function () {
		( new \Advery\Reviews\Plugin() )->boot();
	}
);
