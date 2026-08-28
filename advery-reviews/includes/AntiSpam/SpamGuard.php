<?php
namespace Advery\Reviews\AntiSpam;

use Advery\Reviews\Support\Settings;
use Advery\Reviews\Database\ReviewRepository;

/**
 * Layered, score-based spam evaluation. Cheap local checks run first and each
 * contributes to a spam score with a human-readable reason; a couple of checks
 * are hard rejects (rate limit, failed CAPTCHA, length bounds) that short-circuit.
 * The final score maps to an outcome the submit handler acts on:
 *
 *   reject   → refuse the submission (visible error)
 *   spam     → store, hidden, as spam
 *   hold     → store as pending regardless of the auto-approve setting
 *   approve  → not spam (final status still respects the moderation setting)
 *
 * Every layer is individually toggleable in settings; defaults reflect common
 * spam patterns. A trusted author (logged-in with prior approved reviews) is
 * fast-tracked. The result is returned as data — the handler decides — so this
 * class never writes or emails.
 */
class SpamGuard {

	/** A stable secret for signing the timing token (per-site). */
	private static function secret() {
		return wp_hash( 'advery-reviews-timing' );
	}

	/**
	 * Token the front-end form embeds so we can measure fill time without a
	 * server session. Signed so a bot can't forge a plausible timestamp.
	 *
	 * @return array{ts:int,tk:string}
	 */
	public static function issue_token() {
		$ts = time();
		return [ 'ts' => $ts, 'tk' => hash_hmac( 'sha256', (string) $ts, self::secret() ) ];
	}

	/**
	 * @param int    $ts
	 * @param string $tk
	 * @return bool Valid, untampered token.
	 */
	private static function token_valid( $ts, $tk ) {
		if ( $ts <= 0 || '' === $tk ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', (string) $ts, self::secret() );
		return hash_equals( $expected, (string) $tk );
	}

	/**
	 * @param array $sub {
	 *   content, title, author_name, author_email, author_user_id, author_ip,
	 *   object_type, object_id, ts, tk, captcha_token
	 * }
	 * @return array{outcome:string,score:int,reasons:string[]}
	 */
	public static function evaluate( array $sub ) {
		$as      = self::config();
		$score   = 0;
		$reasons = [];

		$content    = (string) ( $sub['content'] ?? '' );
		$email      = (string) ( $sub['author_email'] ?? '' );
		$user_id    = (int) ( $sub['author_user_id'] ?? 0 );
		$ip         = (string) ( $sub['author_ip'] ?? '' );
		$word_count = self::word_count( $content );

		// --- Hard rejects (short-circuit) ---

		// Length bounds.
		if ( $as['max_chars'] > 0 && mb_strlen( $content ) > $as['max_chars'] ) {
			return self::reject( __( 'Your review is too long.', 'advery-reviews' ) );
		}
		if ( $as['min_words'] > 0 && $word_count < $as['min_words'] ) {
			return self::reject( __( 'Please write a little more.', 'advery-reviews' ) );
		}
		if ( $as['max_words'] > 0 && $word_count > $as['max_words'] ) {
			return self::reject( __( 'Your review is too long.', 'advery-reviews' ) );
		}

		// CAPTCHA (if configured).
		if ( 'none' !== $as['captcha_provider'] && '' !== $as['captcha_secret_key'] ) {
			if ( ! CaptchaVerifier::verify( $as, (string) ( $sub['captcha_token'] ?? '' ), $ip ) ) {
				return self::reject( __( 'CAPTCHA verification failed. Please try again.', 'advery-reviews' ) );
			}
		}

		// Rate limit (per IP + per email in a rolling window).
		if ( $as['rate_enabled'] ) {
			$since_window = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $as['rate_window'] ) );
			$since_day    = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
			$recent       = ReviewRepository::count_recent( $ip, $email, $since_window );
			$today        = ReviewRepository::count_recent( $ip, $email, $since_day );
			if ( $recent >= (int) $as['rate_max'] || ( $as['rate_day_max'] > 0 && $today >= (int) $as['rate_day_max'] ) ) {
				return self::reject( __( 'You are submitting too quickly. Please try again later.', 'advery-reviews' ) );
			}
		}

		// --- Trusted fast-track ---
		if ( $as['trusted_autoapprove'] && $user_id > 0
			&& ReviewRepository::has_approved_by_user( $user_id ) ) {
			return [ 'outcome' => 'approve', 'score' => -100, 'reasons' => [ 'trusted-author' ] ];
		}

		// --- Scored signals ---

		// Honeypot already handled at the REST layer, but double-check here.
		if ( '' !== trim( (string) ( $sub['website_hp'] ?? '' ) ) ) {
			$score += 10;
			$reasons[] = 'honeypot';
		}

		// Submission timing.
		if ( $as['timing_enabled'] ) {
			$ts = (int) ( $sub['ts'] ?? 0 );
			$tk = (string) ( $sub['tk'] ?? '' );
			if ( ! self::token_valid( $ts, $tk ) ) {
				$score += 3;
				$reasons[] = 'timing-token-invalid';
			} elseif ( ( time() - $ts ) < (int) $as['timing_min'] ) {
				$score += 4;
				$reasons[] = 'submitted-too-fast';
			}
		}

		// Links in content.
		$links = self::count_links( $content );
		if ( $links > (int) $as['max_links'] && 'off' !== $as['link_action'] ) {
			if ( 'spam' === $as['link_action'] ) {
				$score += 6;
			} else {
				$score += 3;
			}
			$reasons[] = 'too-many-links(' . $links . ')';
		}

		// Blocklisted words / phrases (plain + regex lines starting with re:).
		if ( self::matches_blocklist( $content . ' ' . (string) ( $sub['title'] ?? '' ), $as['blocklist_words'] ) ) {
			$score += 5;
			$reasons[] = 'blocklisted-word';
		}

		// Blocklisted / disposable email domains.
		$domain = self::email_domain( $email );
		if ( '' !== $domain ) {
			if ( self::in_list( $domain, $as['blocklist_emails'] ) || self::in_list( $email, $as['blocklist_emails'] ) ) {
				$score += 6;
				$reasons[] = 'blocklisted-email';
			}
			if ( $as['block_disposable'] && DisposableDomains::is_disposable( $domain ) ) {
				$score += 5;
				$reasons[] = 'disposable-email';
			}
		}

		// Duplicate content already submitted for this object.
		if ( $as['duplicate_check']
			&& ReviewRepository::content_exists( (string) $sub['object_type'], (int) $sub['object_id'], $content ) ) {
			$score += 5;
			$reasons[] = 'duplicate-content';
		}

		// Optional Akismet as one more signal.
		if ( $as['akismet_enabled'] && Akismet::is_spam( $sub ) ) {
			$score += 5;
			$reasons[] = 'akismet';
		}

		// --- Map score → outcome ---
		$outcome = 'approve';
		if ( $score >= (int) $as['spam_threshold'] ) {
			$outcome = 'spam';
		} elseif ( $score >= (int) $as['hold_threshold'] ) {
			$outcome = 'hold';
		}

		return [ 'outcome' => $outcome, 'score' => $score, 'reasons' => $reasons ];
	}

	private static function reject( $message ) {
		return [ 'outcome' => 'reject', 'score' => 100, 'reasons' => [ 'reject' ], 'message' => $message ];
	}

	/**
	 * Anti-spam config with defaults (merged over any stored values).
	 *
	 * @return array
	 */
	public static function config() {
		return Settings::antispam();
	}

	/* ---------------- small helpers ---------------- */

	private static function word_count( $text ) {
		$text = trim( wp_strip_all_tags( $text ) );
		if ( '' === $text ) {
			return 0;
		}
		return count( preg_split( '/\s+/u', $text ) );
	}

	private static function count_links( $text ) {
		return preg_match_all( '#\bhttps?://#i', $text )
			+ preg_match_all( '#\bwww\.#i', $text )
			+ preg_match_all( '#\[url[=\]]#i', $text );
	}

	private static function matches_blocklist( $text, $list ) {
		$text = mb_strtolower( $text );
		foreach ( self::lines( $list ) as $needle ) {
			if ( 0 === stripos( $needle, 're:' ) ) {
				$pattern = '#' . str_replace( '#', '\#', substr( $needle, 3 ) ) . '#iu';
				if ( @preg_match( $pattern, $text ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					return true;
				}
			} elseif ( '' !== $needle && false !== mb_strpos( $text, mb_strtolower( $needle ) ) ) {
				return true;
			}
		}
		return false;
	}

	private static function in_list( $value, $list ) {
		$value = mb_strtolower( trim( $value ) );
		foreach ( self::lines( $list ) as $item ) {
			if ( mb_strtolower( trim( $item ) ) === $value ) {
				return true;
			}
		}
		return false;
	}

	private static function email_domain( $email ) {
		$at = strrpos( $email, '@' );
		return false === $at ? '' : strtolower( substr( $email, $at + 1 ) );
	}

	/**
	 * @param string $blob newline/comma separated
	 * @return string[]
	 */
	private static function lines( $blob ) {
		if ( is_array( $blob ) ) {
			return array_filter( array_map( 'trim', $blob ), 'strlen' );
		}
		$parts = preg_split( '/[\r\n,]+/', (string) $blob );
		return array_values( array_filter( array_map( 'trim', $parts ), 'strlen' ) );
	}
}
