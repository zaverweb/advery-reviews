<?php
namespace Advery\Reviews\Database;

/**
 * Creates the plugin's two dedicated tables and upgrades them when the schema
 * version changes. Two purpose-built tables (one row per review, one aggregate
 * row per object) keep reads O(1) for schema output and avoid the many-rows
 * -per-item overhead of storing reviews as comments + comment-meta.
 */
class Installer {

	const DB_VERSION_OPTION = 'advery_reviews_db_version';
	const DB_VERSION        = '1.3.0';

	public static function reviews_table() {
		global $wpdb;
		return $wpdb->prefix . 'advery_reviews';
	}

	public static function stats_table() {
		global $wpdb;
		return $wpdb->prefix . 'advery_review_stats';
	}

	public static function install() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$reviews         = self::reviews_table();
		$stats           = self::stats_table();

		$sql_reviews = "CREATE TABLE {$reviews} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_type varchar(20) NOT NULL DEFAULT 'post',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			rating tinyint(3) unsigned NOT NULL DEFAULT 0,
			author_name varchar(150) NOT NULL DEFAULT '',
			author_email varchar(200) NOT NULL DEFAULT '',
			author_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			title varchar(200) NOT NULL DEFAULT '',
			content text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			author_ip varchar(45) NOT NULL DEFAULT '',
			spam_score int(11) NOT NULL DEFAULT 0,
			meta longtext NULL,
			external_source varchar(30) NOT NULL DEFAULT '',
			external_id varchar(191) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY object (object_type, object_id, status),
			KEY status (status),
			KEY created (created_at),
			KEY author_user_id (author_user_id),
			KEY external (external_source, external_id)
		) {$charset_collate};";

		$sql_stats = "CREATE TABLE {$stats} (
			object_type varchar(20) NOT NULL DEFAULT 'post',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			review_count int(10) unsigned NOT NULL DEFAULT 0,
			rating_count int(10) unsigned NOT NULL DEFAULT 0,
			rating_sum int(10) unsigned NOT NULL DEFAULT 0,
			rating_avg decimal(3,2) NOT NULL DEFAULT 0.00,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (object_type, object_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_reviews );
		dbDelta( $sql_stats );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Cheap self-heal: run install() only if the stored version is behind.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}
}
