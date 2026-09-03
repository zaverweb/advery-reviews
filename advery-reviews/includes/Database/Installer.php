<?php
namespace ZaverWeb\Reviews\Database;

/**
 * Creates the plugin's two dedicated tables and upgrades them when the schema
 * version changes. Two purpose-built tables (one row per review, one aggregate
 * row per object) keep reads O(1) for schema output and avoid the many-rows
 * -per-item overhead of storing reviews as comments + comment-meta.
 */
class Installer {

	const DB_VERSION_OPTION = 'zaverweb_reviews_db_version';
	const DB_VERSION        = '1.5.0';

	public static function reviews_table() {
		global $wpdb;
		return $wpdb->prefix . 'zaverweb_reviews';
	}

	public static function stats_table() {
		global $wpdb;
		return $wpdb->prefix . 'zaverweb_review_stats';
	}

	public static function spam_log_table() {
		global $wpdb;
		return $wpdb->prefix . 'zaverweb_review_spam_log';
	}

	public static function install() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$reviews         = self::reviews_table();
		$stats           = self::stats_table();
		$spam_log        = self::spam_log_table();

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
			KEY external (external_source, external_id),
			KEY status_created (status, created_at),
			KEY obj_status_created (object_type, object_id, status, created_at)
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

		// Diagnostic log of blocked/held submissions (opt-in, auto-purged). Kept
		// in its own table so it never touches the lean reviews table and can be
		// truncated freely. Indexed by time (for the paged admin view + purge) and
		// by IP (to spot a repeat offender).
		$sql_spam_log = "CREATE TABLE {$spam_log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			source varchar(20) NOT NULL DEFAULT 'review',
			outcome varchar(20) NOT NULL DEFAULT 'spam',
			object_type varchar(20) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			author_name varchar(150) NOT NULL DEFAULT '',
			author_email varchar(200) NOT NULL DEFAULT '',
			author_ip varchar(45) NOT NULL DEFAULT '',
			reason varchar(191) NOT NULL DEFAULT '',
			content text NOT NULL,
			PRIMARY KEY  (id),
			KEY created (created_at),
			KEY ip (author_ip)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_reviews );
		dbDelta( $sql_stats );
		dbDelta( $sql_spam_log );

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

	/**
	 * One-time migration from the plugin's former "advery" identifiers (tables,
	 * options and cron events) to the "zaverweb" ones after the rebrand. Runs
	 * early on every load but is a no-op once the new DB-version option exists, so
	 * it costs a single option read on a migrated or fresh site. Data is moved,
	 * never copied-then-lost: tables are renamed in place and options carried over.
	 */
	public static function maybe_migrate() {
		global $wpdb;

		// Already on the new scheme (migrated, or a fresh install did install()).
		if ( get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}
		// Nothing from the old scheme to migrate → let normal install() handle it.
		if ( false === get_option( 'advery_reviews_settings' ) && false === get_option( 'advery_reviews_db_version' ) ) {
			return;
		}

		$renames = [
			'advery_reviews'          => 'zaverweb_reviews',
			'advery_review_stats'     => 'zaverweb_review_stats',
			'advery_review_spam_log'  => 'zaverweb_review_spam_log',
		];
		foreach ( $renames as $old => $new ) {
			$old_t = $wpdb->prefix . $old;
			$new_t = $wpdb->prefix . $new;
			$has_old = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_t ) ) === $old_t;
			$has_new = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_t ) ) === $new_t;
			if ( $has_old && ! $has_new ) {
				$wpdb->query( "RENAME TABLE `{$old_t}` TO `{$new_t}`" ); // phpcs:ignore WordPress.DB
			}
		}

		// Carry options over (only if a new value isn't already present).
		$opt_map = [
			'advery_reviews_settings'    => 'zaverweb_reviews_settings',
			'advery_reviews_digest_last' => 'zaverweb_reviews_digest_last',
		];
		foreach ( $opt_map as $old => $new ) {
			$val = get_option( $old, null );
			if ( null !== $val && false === get_option( $new, false ) ) {
				update_option( $new, $val );
			}
		}

		// Unschedule the old-named cron events; the Digest/SpamLog subsystems
		// reschedule under the new names on this same load, per current settings.
		wp_clear_scheduled_hook( 'advery_reviews_digest' );
		wp_clear_scheduled_hook( 'advery_reviews_spam_log_purge' );

		// Drop the old options.
		delete_option( 'advery_reviews_settings' );
		delete_option( 'advery_reviews_digest_last' );
		delete_option( 'advery_reviews_db_version' );

		// Create anything still missing and stamp the new DB version.
		self::install();
	}
}
