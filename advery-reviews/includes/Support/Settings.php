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
			'woo_takeover'       => false,  // show OUR reviews in the Woo product reviews tab

			// Submission rules (all configurable per the user's request).
			'who_can_submit'     => 'anyone',  // 'anyone' | 'logged_in'
			'moderation'         => 'manual',  // 'manual' (pending) | 'auto' (approved)
			'one_per_user'       => true,
			'rating_required'    => true,
			'min_content_length' => 0,

			// Front-end display.
			'auto_append'        => true,      // append the widget to enabled post types
			'reviews_per_page'   => 10,        // front-end widget page size
			'admin_per_page'     => 20,        // admin lists (reviews + spam log) page size
			'load_mode'          => 'all',     // 'all' | 'load_more' | 'paginate'
			'replace_comments'   => false,     // take over the native WP comments area
			'custom_css'         => '',        // owner CSS, printed where the widget renders

			// Our relationship to each reviewed post type, which decides the AI
			// reply voice: 'owner' (we sell/run it — reply as the business) vs
			// 'listing' (a third-party directory entry — we are the platform, not
			// the business, and must not speak for it). Map post_type => role.
			'roles'              => [],        // e.g. [ 'product' => 'owner', 'listing' => 'listing' ]

			// Schema (JSON-LD aggregateRating / review).
			'schema_output'      => true,      // master on/off
			'schema_mode'        => 'auto',    // 'auto' | 'core' | 'standalone' | 'off'
			'schema_type'        => 'LocalBusiness', // @type for standalone non-product items
			'woo_merge_native'   => false,     // merge Woo native ratings into the aggregate

			// Email reporting.
			'email_instant'      => true,
			'email_recipient'    => '',        // empty → admin_email
			'digest_frequency'   => 'off',     // 'off' | 'weekly' | 'monthly'

			// Reviewer avatar on the front end. 'gravatar' is the ONLY mode that
			// makes an external request; the others are fully local.
			'avatar_mode'        => 'initials', // 'none' | 'initials' | 'default' | 'gravatar'
			'avatar_default'     => '',         // image URL used by the 'default' mode

			// Front-end widget appearance (colors / radius / density) → CSS vars.
			'appearance'         => self::appearance_defaults(),

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
			'business_context'    => '',    // optional "about us" the reply can draw on
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
	 * Front-end widget appearance. These map 1:1 to CSS custom properties on the
	 * `.advery-reviews` container, so the whole widget restyles from settings
	 * without touching CSS. Empty color ⇒ the stylesheet default is kept.
	 *
	 * @return array
	 */
	public static function appearance_defaults() {
		return [
			'skin'         => 'card',    // 'classic' (simple list) | 'card' (modern cards)
			'accent'       => '#2271b1', // buttons, links, active page, submit
			'accent_ink'   => '#ffffff', // text on the accent
			'star'         => '#f5a623', // star color
			'text'         => '',        // body text ('' ⇒ inherit the theme)
			'surface'      => '',        // form/card background ('' ⇒ subtle default)
			'border'       => '',        // borders ('' ⇒ subtle default)
			'radius'       => 8,         // corner radius, px (0–40)
			'density'      => 'comfortable', // 'comfortable' | 'compact'
			'font_size'    => 15,        // base font size, px (12–20)
			'star_size'    => 18,        // star size, px (12–40) — independent of font size
			'max_width'    => 0,         // widget max width, px (0 = full width)
		];
	}

	/**
	 * @return array Merged appearance config.
	 */
	public static function appearance() {
		$stored = self::get( 'appearance', [] );
		return wp_parse_args( is_array( $stored ) ? $stored : [], self::appearance_defaults() );
	}

	/**
	 * Build the CSS custom-property block for the front-end widget from the
	 * appearance settings. Density expands to concrete spacing/size vars so the
	 * stylesheet stays purely var-driven. Only non-empty values are emitted, so
	 * blanks fall back to the stylesheet defaults.
	 *
	 * @return string A `.advery-reviews{ … }` rule, or '' when all defaults.
	 */
	public static function appearance_css() {
		$a    = self::appearance();
		$vars = [];

		$color = static function ( $v ) {
			$v = trim( (string) $v );
			// Allow #hex, rgb()/rgba(), and simple CSS color keywords only.
			return preg_match( '/^(#[0-9a-fA-F]{3,8}|rgba?\([0-9.,\s%]+\)|[a-zA-Z]{3,20})$/', $v ) ? $v : '';
		};

		if ( $c = $color( $a['accent'] ) ) {
			$vars['--ar-accent'] = $c;
		}
		if ( $c = $color( $a['accent_ink'] ) ) {
			$vars['--ar-accent-ink'] = $c;
		}
		if ( $c = $color( $a['star'] ) ) {
			$vars['--ar-star'] = $c;
		}
		if ( $c = $color( $a['text'] ) ) {
			$vars['--ar-text'] = $c;
		}
		if ( $c = $color( $a['surface'] ) ) {
			$vars['--ar-surface'] = $c;
		}
		if ( $c = $color( $a['border'] ) ) {
			$vars['--ar-border'] = $c;
		}

		$radius = max( 0, min( 40, (int) $a['radius'] ) );
		$vars['--ar-radius'] = $radius . 'px';

		$font = max( 12, min( 20, (int) $a['font_size'] ) );
		$vars['--ar-font-size'] = $font . 'px';

		$star = max( 12, min( 40, (int) $a['star_size'] ) );
		$vars['--ar-star-size'] = $star . 'px';

		// Density → spacing vars.
		if ( 'compact' === $a['density'] ) {
			$vars['--ar-gap']      = '0.5em';
			$vars['--ar-form-pad'] = '0.9em';
		} else {
			$vars['--ar-gap']      = '0.9em';
			$vars['--ar-form-pad'] = '1.2em';
		}

		$mw = max( 0, min( 2000, (int) $a['max_width'] ) );
		if ( $mw > 0 ) {
			$vars['--ar-max-width'] = $mw . 'px';
		}

		if ( empty( $vars ) ) {
			return '';
		}

		$decls = '';
		foreach ( $vars as $k => $v ) {
			$decls .= $k . ':' . $v . ';';
		}
		return '.advery-reviews{' . $decls . '}';
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
			// Guard WordPress's OWN native comment form (wp-comments-post.php).
			// Spam bots POST straight to that endpoint, bypassing our review form
			// entirely, so this applies the content-policy checks (links, blocked
			// words, blocked/disposable emails) to native comments too — or turns
			// native commenting off site-wide. 'off' leaves WordPress untouched.
			'native_comment_guard' => 'off',  // 'off' | 'filter' | 'disable'
			'max_links'           => 0,       // 0 = no links allowed at all (default)
			'link_action'         => 'reject', // 'off' | 'hold' | 'spam' | 'reject'
			// Opt-in strict link detection (empty = off, safe default). Each line
			// is either a domain ending (".com", "com", ".co.uk") that makes a bare
			// "word.ending" count as a link, or a raw regex prefixed with "re:".
			// This is deliberately owner-controlled: plain "word.tld" is otherwise
			// NOT treated as a link (v0.29.1) to avoid false positives on emails/
			// tech terms, so only endings the owner lists here get the strict rule.
			'link_tlds'           => '',
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
			// Diagnostic spam log (opt-in; contains IPs + text, so default OFF).
			'spam_log_enabled'        => false,
			'spam_log_retention_days' => 10,  // daily WP-Cron purges rows older than this
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
	 * Our relationship to a reviewed post type: 'owner' (we run/sell it) or
	 * 'listing' (a third-party directory entry we don't own). Drives the AI
	 * reply voice. Products default to 'owner'; everything else defaults to
	 * 'owner' unless the site owner marks it as a directory 'listing'.
	 *
	 * @param string $post_type
	 * @return string
	 */
	public static function role_for( $post_type ) {
		$roles = self::get( 'roles', [] );
		if ( is_array( $roles ) && isset( $roles[ $post_type ] ) && 'listing' === $roles[ $post_type ] ) {
			return 'listing';
		}
		return 'owner';
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
