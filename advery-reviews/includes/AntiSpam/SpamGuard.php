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
		$title      = (string) ( $sub['title'] ?? '' );
		$name       = (string) ( $sub['author_name'] ?? '' );
		$email      = (string) ( $sub['author_email'] ?? '' );
		$user_id    = (int) ( $sub['author_user_id'] ?? 0 );
		$ip         = (string) ( $sub['author_ip'] ?? '' );
		// Length is measured on the visible plain text.
		$plain      = trim( wp_strip_all_tags( $content ) );
		$char_count = function_exists( 'mb_strlen' ) ? mb_strlen( $plain ) : strlen( $plain );

		// --- Hard rejects (short-circuit) ---

		// Character bounds (default 10–1500).
		if ( $as['min_chars'] > 0 && $char_count < $as['min_chars'] ) {
			return self::reject(
				sprintf(
					/* translators: %d: minimum characters */
					__( 'Your review is too short (at least %d characters).', 'advery-reviews' ),
					(int) $as['min_chars']
				)
			);
		}
		if ( $as['max_chars'] > 0 && $char_count > $as['max_chars'] ) {
			return self::reject(
				sprintf(
					/* translators: %d: maximum characters */
					__( 'Your review is too long (at most %d characters).', 'advery-reviews' ),
					(int) $as['max_chars']
				)
			);
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

		// Links in ANY field (content, title, name) — plain, marked-up or
		// obfuscated. Checked on the RAW input (before HTML was stripped). This is
		// a *content policy*, not a bot heuristic, so it is evaluated up here and
		// applies to trusted authors too: "Reject" is a hard reject for everyone,
		// and a link hit also disqualifies the trusted fast-track below.
		$raw_blob = ( $sub['raw_content'] ?? $content ) . " \n " . ( $sub['raw_title'] ?? $title ) . " \n " . ( $sub['raw_name'] ?? $name );
		$links    = self::count_links( $raw_blob );
		$link_hit = ( $links > (int) $as['max_links'] && 'off' !== $as['link_action'] );

		if ( $link_hit && 'reject' === $as['link_action'] ) {
			return self::reject( __( 'Links are not allowed in reviews.', 'advery-reviews' ) );
		}

		// --- Trusted fast-track ---
		// A logged-in author with a prior approved review skips the bot heuristics
		// below — but never when their content trips a link-policy hit, so an
		// explicit no-links rule can't be bypassed by a trusted account.
		if ( $as['trusted_autoapprove'] && $user_id > 0 && ! $link_hit
			&& ReviewRepository::has_approved_by_user( $user_id ) ) {
			return [ 'outcome' => 'approve', 'score' => -100, 'reasons' => [ 'trusted-author' ] ];
		}

		// --- Scored signals ---

		// Honeypot already handled at the REST layer, but double-check here.
		if ( '' !== trim( (string) ( $sub['advery_hp'] ?? '' ) ) ) {
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

		// Links (computed above). 'reject' was already handled as a hard reject;
		// here 'hold'/'spam' add to the score. Trusted authors never reach this
		// point with a link hit (the fast-track excluded them).
		if ( $link_hit ) {
			$score    += ( 'spam' === $as['link_action'] ) ? 6 : 3;
			$reasons[] = 'links(' . $links . ')';
		}

		// Blocklisted words / phrases (plain + regex lines starting with re:).
		if ( self::matches_blocklist( $content . ' ' . $title . ' ' . $name, $as['blocklist_words'] ) ) {
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

	/** Common TLDs used to recognise a bare/obfuscated domain like "example.com". */
	const TLDS = 'com|net|org|io|co|info|biz|xyz|online|site|shop|store|app|dev|me|tv|cc|ly|gg|ai|link|click|top|live|life|world|fun|pro|tech|space|website|blog|uk|us|ca|de|fr|ru|ir|in|au|nl|it|es|se|no|fi|pl|br|jp|cn|kr|tr|ua|cz|gr|ro|hu|be|ch|at|dk|pt|ie|nz|mx|ar|cl|za|sg|hk|ae|sa|il|id|my|th|vn|ph|eu';

	/**
	 * Count link-like signals in text, catching plain, obfuscated and marked-up
	 * links. Obfuscation ("example dot com", "example[.]com", "example (dot)
	 * com") is normalised to a dot first, then bare domains are matched only
	 * against a known-TLD list so ordinary phrases like "dot matrix" don't count.
	 *
	 * @param string $text
	 * @return int
	 */
	private static function count_links( $text ) {
		$t = strtolower( (string) $text );

		$count = 0;
		// Definite signals.
		$count += preg_match_all( '#https?://#i', $t );                 // http(s)://
		$count += preg_match_all( '#\bwww\.[a-z0-9-]#i', $t );          // www.something
		$count += preg_match_all( '#<a\b#i', $t );                      // <a ...>
		$count += preg_match_all( '#\[url[=\]]#i', $t );                // [url]...[/url]
		$count += preg_match_all( '#\b(?:\d{1,3}\.){3}\d{1,3}\b#', $t ); // IPv4

		// De-obfuscate: " dot ", "(dot)", "[dot]", "{dot}", "[.]", "(.)" → "."
		$deob = preg_replace( '#\s*[\(\[\{]?\s*(?:dot|d0t)\s*[\)\]\}]?\s*#i', '.', $t );
		$deob = preg_replace( '#[\(\[\{]\s*\.\s*[\)\]\}]#', '.', $deob );   // [.] (.) {.}
		$deob = preg_replace( '#\s+\.\s+#', '.', $deob );                    // "example . com"

		// Bare domain with a known TLD (word.tld or word.tld.tld).
		$count += preg_match_all( '#\b[a-z0-9][a-z0-9-]{1,}\.(?:' . self::TLDS . ')(?:\.[a-z]{2,})?\b#i', $deob );

		return $count;
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
