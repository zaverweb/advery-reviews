<?php
namespace Advery\Reviews\AntiSpam;

use Advery\Reviews\Support\Settings;
use Advery\Reviews\Database\Installer;

/**
 * Optional, opt-in diagnostic log of submissions the anti-spam layer blocked,
 * held, or marked as spam — for both our review form and (in Filter mode) the
 * native WordPress comment form. It answers "why was this rejected, and who
 * keeps trying?" without bloating the lean reviews table: it lives in its own
 * table, records the target, reason and a capped snippet, and is auto-purged
 * after a configurable number of days by a daily WP-Cron event.
 *
 * Default OFF, because the rows contain IPs and submitted text (personal data);
 * the owner turns it on deliberately. All reads are prepared, indexed and paged.
 */
class SpamLog {

	const PURGE_HOOK = 'advery_reviews_spam_log_purge';

	/** Max characters of the offending text we keep. */
	const CONTENT_CAP = 2000;

	public function register() {
		add_action( self::PURGE_HOOK, [ __CLASS__, 'purge_expired' ] );

		// Keep the daily purge scheduled exactly while logging is enabled. This
		// self-heals on every load, so toggling the setting is enough.
		if ( self::enabled() ) {
			if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
			}
		} elseif ( wp_next_scheduled( self::PURGE_HOOK ) ) {
			self::clear_cron();
		}
	}

	/** @return bool */
	public static function enabled() {
		$as = Settings::antispam();
		return ! empty( $as['spam_log_enabled'] );
	}

	/** @return int Days to keep rows (≥ 1). */
	public static function retention_days() {
		$as = Settings::antispam();
		return max( 1, (int) ( $as['spam_log_retention_days'] ?? 10 ) );
	}

	/**
	 * Record one blocked/held/spam submission. No-op when logging is disabled.
	 *
	 * @param array $data { source, outcome, object_type, object_id, author_name,
	 *                      author_email, author_ip, reason, content }
	 */
	public static function record( array $data ) {
		if ( ! self::enabled() ) {
			return;
		}
		global $wpdb;

		$content = (string) ( $data['content'] ?? '' );
		$content = function_exists( 'mb_substr' )
			? mb_substr( $content, 0, self::CONTENT_CAP )
			: substr( $content, 0, self::CONTENT_CAP );

		// created_at is left to the column default (CURRENT_TIMESTAMP).
		$wpdb->insert(
			Installer::spam_log_table(),
			[
				'source'       => substr( (string) ( $data['source'] ?? 'review' ), 0, 20 ),
				'outcome'      => substr( (string) ( $data['outcome'] ?? 'spam' ), 0, 20 ),
				'object_type'  => substr( (string) ( $data['object_type'] ?? '' ), 0, 20 ),
				'object_id'    => (int) ( $data['object_id'] ?? 0 ),
				'author_name'  => substr( (string) ( $data['author_name'] ?? '' ), 0, 150 ),
				'author_email' => substr( (string) ( $data['author_email'] ?? '' ), 0, 200 ),
				'author_ip'    => substr( (string) ( $data['author_ip'] ?? '' ), 0, 45 ),
				'reason'       => substr( (string) ( $data['reason'] ?? '' ), 0, 191 ),
				'content'      => $content,
			],
			[ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Paged, filtered read for the admin log view. All prepared; uses the
	 * created_at index for the default newest-first ordering.
	 *
	 * @param array $args { source, outcome, search, per_page, page }
	 * @return array{items:array,total:int,page:int,per_page:int}
	 */
	public static function query( array $args ) {
		global $wpdb;
		$table = Installer::spam_log_table();

		$per    = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );
		$page   = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset = ( $page - 1 ) * $per;

		$where  = '1=1';
		$params = [];

		$src = isset( $args['source'] ) ? sanitize_key( (string) $args['source'] ) : '';
		if ( in_array( $src, [ 'review', 'comment' ], true ) ) {
			$where   .= ' AND source = %s';
			$params[] = $src;
		}
		$out = isset( $args['outcome'] ) ? sanitize_key( (string) $args['outcome'] ) : '';
		if ( in_array( $out, [ 'reject', 'spam', 'hold' ], true ) ) {
			$where   .= ' AND outcome = %s';
			$params[] = $out;
		}
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND (content LIKE %s OR author_ip LIKE %s OR author_email LIKE %s OR reason LIKE %s)';
			array_push( $params, $like, $like, $like, $like );
		}

		$total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) )
			: $wpdb->get_var( $total_sql ) );

		$list_sql    = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, [ $per, $offset ] );
		$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );

		return [
			'items'    => $items ?: [],
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per,
		];
	}

	/** Delete rows older than the retention window. Uses the DB clock (no TZ skew). */
	public static function purge_expired() {
		global $wpdb;
		$table = Installer::spam_log_table();
		$days  = self::retention_days();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < (NOW() - INTERVAL %d DAY)", $days ) ); // phpcs:ignore WordPress.DB
	}

	/** Empty the whole log (manual "Clear log" action). @return int|false rows */
	public static function clear_all() {
		global $wpdb;
		$table = Installer::spam_log_table();
		return $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	/** Unschedule every pending purge event. */
	public static function clear_cron() {
		$ts = wp_next_scheduled( self::PURGE_HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::PURGE_HOOK );
			$ts = wp_next_scheduled( self::PURGE_HOOK );
		}
	}
}
