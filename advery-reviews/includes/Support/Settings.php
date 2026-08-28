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

			// Schema (only used when Advery Schema Plus is active).
			'schema_output'      => true,
			'woo_merge_native'   => false,     // merge Woo native ratings into the aggregate

			// Email reporting.
			'email_instant'      => true,
			'email_recipient'    => '',        // empty → admin_email
			'digest_frequency'   => 'off',     // 'off' | 'weekly' | 'monthly'
		];
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
