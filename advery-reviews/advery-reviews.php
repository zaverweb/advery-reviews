<?php
/**
 * Plugin Name:       Zaver Web Reviews
 * Plugin URI:        https://zaverweb.com
 * Description:       Fast, self-contained ratings & reviews for any post type, taxonomy term, or WooCommerce product. Collects reviews in its own optimized tables (not one comment split across many meta rows), reads WooCommerce's native ratings, and — when Advery Schema Plus is active — injects aggregateRating/review into the JSON-LD graph. Configurable submission rules, a smooth React moderation panel, a dashboard widget, a pending-count menu badge, and instant + digest email reports.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Zaver Web
 * Author URI:        https://zaverweb.com
 * Text Domain:       zaverweb-reviews
 * Domain Path:       /languages
 *
 * @package ZaverWeb\Reviews
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZAVERWEB_REVIEWS_VERSION', '1.0.0' );
define( 'ZAVERWEB_REVIEWS_FILE', __FILE__ );
define( 'ZAVERWEB_REVIEWS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ZAVERWEB_REVIEWS_URL', plugin_dir_url( __FILE__ ) );
define( 'ZAVERWEB_REVIEWS_REST_NAMESPACE', 'zaverweb-reviews/v1' );

/**
 * PSR-4 autoloader for the ZaverWeb\Reviews\ namespace.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'ZaverWeb\\Reviews\\';
		$len    = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class, $len ) ) {
			return;
		}
		$file = ZAVERWEB_REVIEWS_PATH . 'includes/' . str_replace( '\\', '/', substr( $class, $len ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// Activation: create the custom tables and schedule the digest cron.
register_activation_hook(
	__FILE__,
	function () {
		\ZaverWeb\Reviews\Database\Installer::install();
		\ZaverWeb\Reviews\Email\Digest::reschedule();
	}
);

// Deactivation: clear scheduled events (tables are kept; removed on uninstall).
register_deactivation_hook(
	__FILE__,
	function () {
		\ZaverWeb\Reviews\Email\Digest::clear();
		\ZaverWeb\Reviews\AntiSpam\SpamLog::clear_cron();
	}
);

add_action(
	'plugins_loaded',
	function () {
		( new \ZaverWeb\Reviews\Plugin() )->boot();
	}
);
