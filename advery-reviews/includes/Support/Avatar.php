<?php
namespace Advery\Reviews\Support;

/**
 * Renders the reviewer avatar for the configured mode, in one place so the
 * server-rendered list and the AJAX-loaded items look identical. Only the
 * 'gravatar' mode ever contacts an external service — 'initials' and 'default'
 * are fully local, so with Gravatar off the front end makes no avatar request.
 */
class Avatar {

	/**
	 * @param array       $r    review row (needs author_name; author_email/author_user_id for gravatar)
	 * @param string|null $mode Override the avatar mode (null = the global setting).
	 * @return string HTML (already escaped)
	 */
	public static function html( array $r, $mode = null ) {
		if ( null === $mode || '' === $mode ) {
			$mode = Settings::get( 'avatar_mode', 'initials' );
		}
		if ( 'none' === $mode ) {
			return '';
		}

		if ( 'gravatar' === $mode ) {
			$id  = isset( $r['author_user_id'] ) ? (int) $r['author_user_id'] : 0;
			$src = $id ? $id : (string) ( $r['author_email'] ?? '' );
			// The ONLY branch that issues an external request.
			$html = get_avatar( $src, 40, '', $r['author_name'] ?? '', [ 'class' => 'advery-reviews__avatar-img', 'loading' => 'lazy' ] );
			return $html ? $html : self::initials( $r );
		}

		if ( 'default' === $mode ) {
			$url = (string) Settings::get( 'avatar_default', '' );
			if ( '' !== $url ) {
				return sprintf(
					'<img class="advery-reviews__avatar-img" src="%s" alt="" width="40" height="40" loading="lazy" />',
					esc_url( $url )
				);
			}
		}

		return self::initials( $r );
	}

	/**
	 * A local, request-free initials avatar.
	 *
	 * @param array $r
	 * @return string
	 */
	private static function initials( array $r ) {
		$name    = trim( (string) ( $r['author_name'] ?? '' ) );
		$initial = '' !== $name ? mb_substr( $name, 0, 1 ) : '?';
		return '<span class="advery-reviews__avatar" aria-hidden="true">' . esc_html( mb_strtoupper( $initial ) ) . '</span>';
	}
}
