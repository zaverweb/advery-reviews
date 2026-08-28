<?php
/**
 * Uninstall cleanup: drop the plugin's tables and options. Runs only on an
 * explicit "Delete" from the Plugins screen.
 *
 * @package Advery\Reviews
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$reviews = $wpdb->prefix . 'advery_reviews';
$stats   = $wpdb->prefix . 'advery_review_stats';

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$reviews}" );
$wpdb->query( "DROP TABLE IF EXISTS {$stats}" );
// phpcs:enable

delete_option( 'advery_reviews_settings' );
delete_option( 'advery_reviews_db_version' );
delete_option( 'advery_reviews_digest_last' );
