<?php
namespace ZaverWeb\Reviews\AI;

/**
 * A light audit log + daily rate cap for AI calls. Stored in a single option:
 * today's date, a per-day counter, and a short ring buffer of recent calls
 * (task, provider, ok/failed, time) so the owner can see what the AI did and
 * costs stay bounded. No prompt/response text is stored.
 */
class AuditLog {

	const OPTION = 'zaverweb_reviews_ai_log';
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
	 * Record a call, accumulating all-time totals: number of calls, tokens in/out
	 * (when the provider reports them) and an approximate cost.
	 *
	 * @param string $task
	 * @param string $provider
	 * @param bool   $ok
	 * @param array  $usage  [ 'input' => int, 'output' => int ]
	 * @param string $model
	 */
	public static function record( $task, $provider, $ok, array $usage = [], $model = '' ) {
		$s          = self::state();
		$s['count'] = (int) ( $s['count'] ?? 0 ) + 1;

		$in  = max( 0, (int) ( $usage['input'] ?? 0 ) );
		$out = max( 0, (int) ( $usage['output'] ?? 0 ) );

		$s['calls_total'] = (int) ( $s['calls_total'] ?? 0 ) + 1;
		$s['tokens_in']   = (int) ( $s['tokens_in'] ?? 0 ) + $in;
		$s['tokens_out']  = (int) ( $s['tokens_out'] ?? 0 ) + $out;
		$s['cost_total']  = (float) ( $s['cost_total'] ?? 0 ) + self::estimate_cost( $model ?: $provider, $in, $out );

		array_unshift(
			$s['recent'],
			[
				'task'     => $task,
				'provider' => $provider,
				'ok'       => (bool) $ok,
				'in'       => $in,
				'out'      => $out,
				'time'     => current_time( 'mysql' ),
			]
		);
		$s['recent'] = array_slice( $s['recent'], 0, self::KEEP );
		update_option( self::OPTION, $s );
	}

	/**
	 * All-time usage summary for the admin. Cost is an ESTIMATE from public list
	 * prices and the tokens providers reported (0 for local/unknown models).
	 *
	 * @return array{today:int,calls_total:int,tokens_in:int,tokens_out:int,cost_total:float}
	 */
	public static function stats() {
		$s = self::state();
		return [
			'today'       => (int) ( $s['count'] ?? 0 ),
			'calls_total' => (int) ( $s['calls_total'] ?? 0 ),
			'tokens_in'   => (int) ( $s['tokens_in'] ?? 0 ),
			'tokens_out'  => (int) ( $s['tokens_out'] ?? 0 ),
			'cost_total'  => round( (float) ( $s['cost_total'] ?? 0 ), 4 ),
		];
	}

	/**
	 * Rough USD cost of one call from public per-1M-token list prices, matched by
	 * a substring of the model (or provider) name. Unknown/local ⇒ 0.
	 *
	 * @param string $model
	 * @param int    $in
	 * @param int    $out
	 * @return float
	 */
	private static function estimate_cost( $model, $in, $out ) {
		$m = strtolower( (string) $model );
		// [ needle, price_in_per_1M, price_out_per_1M ]. First match wins.
		$table = [
			[ 'gpt-4o-mini', 0.15, 0.60 ],
			[ 'gpt-4o', 2.50, 10.00 ],
			[ 'o1-mini', 3.00, 12.00 ],
			[ 'haiku', 0.80, 4.00 ],
			[ 'sonnet', 3.00, 15.00 ],
			[ 'opus', 15.00, 75.00 ],
			[ 'gemini-1.5-flash', 0.075, 0.30 ],
			[ 'flash', 0.075, 0.30 ],
			[ 'gemini-1.5-pro', 1.25, 5.00 ],
			[ 'deepseek', 0.27, 1.10 ],
			[ 'gpt-4', 30.00, 60.00 ],
		];
		foreach ( $table as $row ) {
			if ( false !== strpos( $m, $row[0] ) ) {
				return ( $in / 1000000 ) * $row[1] + ( $out / 1000000 ) * $row[2];
			}
		}
		return 0.0;
	}

	/**
	 * @return array
	 */
	public static function recent() {
		$s = self::state();
		return $s['recent'];
	}
}
