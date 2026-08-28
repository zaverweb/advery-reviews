<?php
namespace Advery\Reviews\Support;

/**
 * Reads and writes the single settings option. Everything about who can submit,
 * how reviews are moderated, where the widget appears, WooCommerce behaviour,
 * schema output and email reporting lives here so it round-trips as one JSON
 * document to the React admin.
 */
class Settings {

	const OPTION = 'advery_reviews_settings';

	/**
	 * @return array
	 */
	public static function defaults() {
		return [
			// Where reviews can be collected / shown.
			'enabled_post_types' => [ 'post' ],
			'enabled_taxonomies' => [],
			'woo_enabled'        => true,   // collect on products + read native ratings

			// Submission rules (all configurable per the user's request).
			'who_can_submit'     => 'anyone',  // 'anyone' | 'logged_in'
			'moderation'         => 'manual',  // 'manual' (pending) | 'auto' (approved)
			'one_per_user'       => true,
			'rating_required'    => true,
			'min_content_length' => 0,

			// Front-end display.
			'auto_append'        => true,      // append the widget to enabled post types
			'reviews_per_page'   => 10,
			'load_mode'          => 'all',     // 'all' | 'load_more' | 'paginate'
			'replace_comments'   => false,     // take over the native WP comments area
			'custom_css'         => '',        // owner CSS, printed where the widget renders

			// Schema (only used when Advery Schema Plus is active).
			'schema_output'      => true,
			'woo_merge_native'   => false,     // merge Woo native ratings into the aggregate

			// Email reporting.
			'email_instant'      => true,
			'email_recipient'    => '',        // empty → admin_email
			'digest_frequency'   => 'off',     // 'off' | 'weekly' | 'monthly'

			// Anti-spam (layered, score-based). See antispam_defaults().
			'antispam'           => self::antispam_defaults(),

			// AI subsystem (reply drafting, moderation assist, translate, summarize).
			'ai'                 => self::ai_defaults(),
		];
	}

	/**
	 * AI defaults. One provider + model, a daily call cap, and per-task enable +
	 * optional prompt override (empty prompt ⇒ the built-in default is used).
	 *
	 * @return array
	 */
	public static function ai_defaults() {
		return [
			'provider'    => 'anthropic', // anthropic|openai|openrouter|ollama|gemini
			'api_key'     => '',
			'base_url'    => '',          // optional override
			'model'       => '',          // empty ⇒ provider default
			'temperature' => 0.7,
			'max_tokens'  => 600,
			'daily_cap'   => 200,         // max AI calls per day (0 = unlimited)
			'moderation_autospam' => false, // let moderation-assist auto-mark SPAM verdicts
			'tasks'       => [
				'reply'     => [ 'enabled' => true, 'prompt' => '' ],
				'moderate'  => [ 'enabled' => false, 'prompt' => '' ],
				'translate' => [ 'enabled' => true, 'prompt' => '' ],
				'summarize' => [ 'enabled' => true, 'prompt' => '' ],
			],
		];
	}

	/**
	 * @return array Merged AI config.
	 */
	public static function ai() {
		$stored = self::get( 'ai', [] );
		$ai     = wp_parse_args( is_array( $stored ) ? $stored : [], self::ai_defaults() );
		// Ensure every task key exists.
		foreach ( self::ai_defaults()['tasks'] as $k => $def ) {
			$ai['tasks'][ $k ] = wp_parse_args( $ai['tasks'][ $k ] ?? [], $def );
		}
		return $ai;
	}

	/**
	 * Anti-spam defaults, tuned from common spam patterns. Every layer is
	 * individually toggleable; scores accumulate and map to an outcome via the
	 * two thresholds.
	 *
	 * @return array
	 */
	public static function antispam_defaults() {
		return [
			'timing_enabled'      => true,
			'timing_min'          => 3,       // seconds; faster ⇒ likely bot
			'max_links'           => 0,       // 0 = no links allowed at all (default)
			'link_action'         => 'reject', // 'off' | 'hold' | 'spam' | 'reject'
			'blocklist_words'     => "viagra\ncialis\ncasino\nporn\nloan\nseo service\ncrypto\nbinary option",
			'blocklist_emails'    => '',
			'block_disposable'    => true,
			'rate_enabled'        => true,
			'rate_window'         => 600,     // seconds
			'rate_max'            => 3,        // per window (IP or email)
			'rate_day_max'        => 20,      // per day (0 = off)
			'duplicate_check'     => true,
			'min_chars'           => 10,      // minimum review characters (visible text)
			'max_chars'           => 1500,    // maximum review characters
			'max_name_chars'      => 35,      // cap on the author name length
			'trusted_autoapprove' => true,
			'hold_threshold'      => 2,       // score ≥ ⇒ hold for moderation
			'spam_threshold'      => 5,       // score ≥ ⇒ mark spam
			// CAPTCHA
			'captcha_provider'    => 'none',  // none|recaptcha_v2|recaptcha_v3|hcaptcha|turnstile
			'captcha_site_key'    => '',
			'captcha_secret_key'  => '',
			'captcha_threshold'   => 0.5,     // reCAPTCHA v3 score floor
			// Akismet (optional extra signal)
			'akismet_enabled'     => false,
		];
	}

	/**
	 * Merged anti-spam config.
	 *
	 * @return array
	 */
	public static function antispam() {
		$stored = self::get( 'antispam', [] );
		return wp_parse_args( is_array( $stored ) ? $stored : [], self::antispam_defaults() );
	}

	/**
	 * @return array Stored settings merged over defaults.
	 */
	public static function all() {
		$stored = get_option( self::OPTION, [] );
		return wp_parse_args( is_array( $stored ) ? $stored : [], self::defaults() );
	}

	/**
	 * @param string $key
	 * @param mixed  $fallback
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * @param array $values Already sanitized.
	 * @return bool
	 */
	public static function save( array $values ) {
		return update_option( self::OPTION, wp_parse_args( $values, self::defaults() ) );
	}

	/**
	 * Resolved email recipient (falls back to the site admin).
	 *
	 * @return string
	 */
	public static function recipient() {
		$r = trim( (string) self::get( 'email_recipient', '' ) );
		return '' !== $r ? $r : get_option( 'admin_email' );
	}
}
