<?php
namespace Advery\Reviews\AI;

/**
 * A light audit log + daily rate cap for AI calls. Stored in a single option:
 * today's date, a per-day counter, and a short ring buffer of recent calls
 * (task, provider, ok/failed, time) so the owner can see what the AI did and
 * costs stay bounded. No prompt/response text is stored.
 */
class AuditLog {

	const OPTION = 'advery_reviews_ai_log';
	const KEEP   = 30;

	private static function state() {
		$s = get_option( self::OPTION, [] );
		if ( ! is_array( $s ) ) {
			$s = [];
		}
		$today = gmdate( 'Y-m-d' );
		if ( ( $s['date'] ?? '' ) !== $today ) {
			$s['date']  = $today;
			$s['count'] = 0;
		}
		if ( ! isset( $s['recent'] ) || ! is_array( $s['recent'] ) ) {
			$s['recent'] = [];
		}
		return $s;
	}

	/**
	 * @return int Calls made today.
	 */
	public static function today() {
		$s = self::state();
		return (int) ( $s['count'] ?? 0 );
	}

	/**
	 * @param int $cap
	 * @return bool True when under the daily cap.
	 */
	public static function under_cap( $cap ) {
		$cap = (int) $cap;
		return $cap <= 0 || self::today() < $cap;
	}

	/**
	 * Record a call.
	 *
	 * @param string $task
	 * @param string $provider
	 * @param bool   $ok
	 */
	public static function record( $task, $provider, $ok ) {
		$s          = self::state();
		$s['count'] = (int) ( $s['count'] ?? 0 ) + 1;
		array_unshift(
			$s['recent'],
			[
				'task'     => $task,
				'provider' => $provider,
				'ok'       => (bool) $ok,
				'time'     => current_time( 'mysql' ),
			]
		);
		$s['recent'] = array_slice( $s['recent'], 0, self::KEEP );
		update_option( self::OPTION, $s );
	}

	/**
	 * @return array
	 */
	public static function recent() {
		$s = self::state();
		return $s['recent'];
	}
}
