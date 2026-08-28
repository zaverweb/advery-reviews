<?php
namespace Advery\Reviews\Email;

use Advery\Reviews\Support\Settings;
use Advery\Reviews\Support\Targets;
use Advery\Reviews\Database\ReviewRepository;

/**
 * Optional weekly / monthly digest email summarising the reviews received in
 * the period. Driven by WP-Cron with custom recurrences, (re)scheduled whenever
 * the digest frequency setting changes.
 */
class Digest {

	const HOOK      = 'advery_reviews_digest';
	const LAST_SENT = 'advery_reviews_digest_last';

	public function register() {
		add_filter( 'cron_schedules', [ $this, 'schedules' ] );
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	/**
	 * @param array $schedules
	 * @return array
	 */
	public function schedules( $schedules ) {
		$schedules['advery_weekly']  = [ 'interval' => WEEK_IN_SECONDS, 'display' => __( 'Once Weekly (Advery)', 'advery-reviews' ) ];
		$schedules['advery_monthly'] = [ 'interval' => 30 * DAY_IN_SECONDS, 'display' => __( 'Once Monthly (Advery)', 'advery-reviews' ) ];
		return $schedules;
	}

	/**
	 * (Re)schedule the digest to match the current setting. Called on activation
	 * and whenever settings are saved.
	 */
	public static function reschedule() {
		self::clear();

		$freq = Settings::get( 'digest_frequency', 'off' );
		if ( 'weekly' === $freq ) {
			wp_schedule_event( time() + WEEK_IN_SECONDS, 'advery_weekly', self::HOOK );
		} elseif ( 'monthly' === $freq ) {
			wp_schedule_event( time() + 30 * DAY_IN_SECONDS, 'advery_monthly', self::HOOK );
		}
	}

	/**
	 * Remove any scheduled digest event.
	 */
	public static function clear() {
		$ts = wp_next_scheduled( self::HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
			$ts = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * Build and send the digest for the elapsed period.
	 */
	public function run() {
		$freq = Settings::get( 'digest_frequency', 'off' );
		if ( 'off' === $freq ) {
			return;
		}

		$window = 'monthly' === $freq ? 30 * DAY_IN_SECONDS : WEEK_IN_SECONDS;
		$since  = get_option( self::LAST_SENT );
		if ( ! $since ) {
			$since = gmdate( 'Y-m-d H:i:s', time() - $window );
		}

		$reviews = ReviewRepository::since( $since );
		update_option( self::LAST_SENT, current_time( 'mysql' ) );

		$counts = ReviewRepository::status_counts();
		$blog   = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		$subject = sprintf(
			/* translators: 1: site name, 2: frequency */
			__( '[%1$s] %2$s review digest', 'advery-reviews' ),
			$blog,
			ucfirst( $freq )
		);

		$body = [];
		$body[] = sprintf(
			/* translators: %d: number of new reviews */
			_n( '%d new review this period.', '%d new reviews this period.', count( $reviews ), 'advery-reviews' ),
			count( $reviews )
		);
		$body[] = sprintf(
			__( 'Pending: %1$d · Approved: %2$d · Spam: %3$d', 'advery-reviews' ),
			$counts['pending'],
			$counts['approved'],
			$counts['spam']
		);
		$body[] = '';

		foreach ( array_slice( $reviews, 0, 30 ) as $r ) {
			$stars  = $r['rating'] > 0 ? str_repeat( '★', $r['rating'] ) : '—';
			$body[] = sprintf(
				'• [%s] %s — %s: %s',
				$stars,
				Targets::label( $r['object_type'], $r['object_id'] ),
				$r['author_name'],
				wp_trim_words( wp_strip_all_tags( $r['content'] ), 20 )
			);
		}

		$body[] = '';
		$body[] = sprintf( __( 'Manage: %s', 'advery-reviews' ), admin_url( 'admin.php?page=advery-reviews' ) );

		wp_mail( Settings::recipient(), $subject, implode( "\n", $body ) );
	}
}
