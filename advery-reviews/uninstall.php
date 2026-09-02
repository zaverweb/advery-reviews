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

$reviews  = $wpdb->prefix . 'advery_reviews';
$stats    = $wpdb->prefix . 'advery_review_stats';
$spam_log = $wpdb->prefix . 'advery_review_spam_log';

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$reviews}" );
$wpdb->query( "DROP TABLE IF EXISTS {$stats}" );
$wpdb->query( "DROP TABLE IF EXISTS {$spam_log}" );
// phpcs:enable

// Clear the spam-log purge cron in case it is still scheduled.
wp_clear_scheduled_hook( 'advery_reviews_spam_log_purge' );

delete_option( 'advery_reviews_settings' );
delete_option( 'advery_reviews_db_version' );
delete_option( 'advery_reviews_digest_last' );
