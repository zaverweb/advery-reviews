<?php
namespace Advery\Reviews\AntiSpam;

/**
 * Optional Akismet signal. If the site has Akismet installed and configured
 * (an API key), a review is sent to Akismet's comment-check endpoint and its
 * verdict is used as one more spam signal (never the sole gate). If Akismet is
 * not available this is a no-op that returns false.
 */
class Akismet {

	/**
	 * @return bool
	 */
	public static function available() {
		return (bool) self::key();
	}

	private static function key() {
		if ( function_exists( 'akismet_get_key' ) ) {
			$k = akismet_get_key();
			if ( $k ) {
				return $k;
			}
		}
		$k = get_option( 'wordpress_api_key' );
		return $k ? $k : '';
	}

	/**
	 * @param array $sub submission fields
	 * @return bool True if Akismet classifies it as spam.
	 */
	public static function is_spam( array $sub ) {
		$key = self::key();
		if ( ! $key ) {
			return false;
		}

		$body = [
			'blog'                 => home_url(),
			'user_ip'              => $sub['author_ip'] ?? '',
			'user_agent'           => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'comment_type'         => 'review',
			'comment_author'       => $sub['author_name'] ?? '',
			'comment_author_email' => $sub['author_email'] ?? '',
			'comment_content'      => trim( ( $sub['title'] ?? '' ) . "\n" . ( $sub['content'] ?? '' ) ),
			'blog_lang'            => get_locale(),
		];

		$response = wp_remote_post(
			sprintf( 'https://%s.rest.akismet.com/1.1/comment-check', $key ),
			[ 'timeout' => 8, 'body' => $body, 'headers' => [ 'User-Agent' => 'Advery Reviews/' . ADVERY_REVIEWS_VERSION ] ]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return 'true' === trim( (string) wp_remote_retrieve_body( $response ) );
	}
}
