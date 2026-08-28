<?php
namespace Advery\Reviews\Support;

/**
 * Centralised input sanitisation for everything a visitor can submit. Defends
 * against injection via disallowed / control characters, invalid UTF-8, and
 * unwanted HTML — before anything is stored. Kept separate so the REST submit
 * path and the (future) migration importer clean data identically.
 *
 * Rules of thumb:
 *   - Names / titles / short fields: no HTML at all, no control chars.
 *   - Review body: a tiny, fixed HTML allowlist (basic formatting only); no
 *     scripts, no event handlers, no styles, no iframes/objects.
 *   - Everything is forced to valid UTF-8 and stripped of C0/C1 control bytes
 *     (except tab/newline in the body), so a payload can't smuggle a null byte
 *     or terminator to break out of a later context.
 */
class Sanitizer {

	/** HTML the review body may keep — deliberately minimal. */
	const ALLOWED_HTML = [
		'a'          => [ 'href' => true, 'title' => true, 'rel' => true ],
		'strong'     => [],
		'b'          => [],
		'em'         => [],
		'i'          => [],
		'br'         => [],
		'p'          => [],
		'ul'         => [],
		'ol'         => [],
		'li'         => [],
		'blockquote' => [],
	];

	/**
	 * A plain short text field (name, title): strip all HTML + control chars,
	 * collapse whitespace, cap length.
	 *
	 * @param string $value
	 * @param int    $max
	 * @return string
	 */
	public static function text( $value, $max = 200 ) {
		$value = self::normalize( (string) $value );
		$value = wp_strip_all_tags( $value, true );          // also removes < > tags entirely
		$value = preg_replace( '/[ \t]+/u', ' ', $value );   // collapse runs of spaces
		$value = trim( $value );
		return self::cap( $value, $max );
	}

	/**
	 * The review body: PLAIN TEXT only. Reviews never need HTML, and allowing
	 * any markup is an injection surface, so we strip ALL tags (script/style and
	 * their contents first, then every remaining tag) while keeping line breaks.
	 * Forces valid UTF-8 and removes control bytes. Never trust the raw input.
	 *
	 * @param string $value
	 * @param int    $max
	 * @return string
	 */
	public static function content( $value, $max = 1500 ) {
		$value = self::normalize( (string) $value, true );   // keep \n and \t
		// Remove script/style/iframe/object/embed/form WITH their contents first,
		// so their inner text can't survive tag-stripping.
		$value = preg_replace( '#<\s*(script|style|iframe|object|embed|form)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $value );
		// Drop any event-handler attributes defensively (in case of stray markup).
		$value = preg_replace( '#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $value );
		// Strip every remaining tag but keep line breaks. false = don't collapse breaks.
		$value = wp_strip_all_tags( $value, false );
		$value = trim( $value );
		return self::cap( $value, $max );
	}

	/**
	 * A validated email or '' when invalid.
	 *
	 * @param string $value
	 * @return string
	 */
	public static function email( $value ) {
		$value = sanitize_email( self::normalize( (string) $value ) );
		return is_email( $value ) ? $value : '';
	}

	/**
	 * Force valid UTF-8 and remove C0/C1 control characters and null bytes.
	 * When $keep_breaks is true, tab (\t), newline (\n) and carriage return
	 * (\r) are preserved (for the multi-line body).
	 *
	 * @param string $value
	 * @param bool   $keep_breaks
	 * @return string
	 */
	public static function normalize( $value, $keep_breaks = false ) {
		$value = wp_check_invalid_utf8( (string) $value, true );

		// Remove null bytes explicitly.
		$value = str_replace( "\0", '', $value );

		// Strip C0 controls (0x00–0x1F) and DEL/C1 (0x7F–0x9F). Optionally keep
		// tab/LF/CR. Uses the /u flag so multibyte text is untouched.
		$pattern = $keep_breaks
			? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u'
			: '/[\x00-\x1F\x7F-\x9F]/u';
		$stripped = preg_replace( $pattern, '', $value );

		// preg_replace returns null on a bad (non-UTF-8) subject; fall back.
		return null === $stripped ? $value : $stripped;
	}

	/**
	 * Cap a string to a maximum length (multibyte-aware).
	 *
	 * @param string $value
	 * @param int    $max
	 * @return string
	 */
	public static function cap( $value, $max ) {
		if ( $max > 0 && mb_strlen( $value ) > $max ) {
			return mb_substr( $value, 0, $max );
		}
		return $value;
	}
}
