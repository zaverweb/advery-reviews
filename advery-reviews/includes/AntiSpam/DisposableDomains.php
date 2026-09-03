<?php
namespace ZaverWeb\Reviews\AntiSpam;

/**
 * A small, curated set of common disposable / throwaway email domains used by
 * spammers. Intentionally short and fast (no remote list); extend via the
 * `zaverweb_reviews_disposable_domains` filter to plug in a larger list.
 */
class DisposableDomains {

	private static $list = [
		'mailinator.com', 'guerrillamail.com', 'guerrillamail.info', 'sharklasers.com',
		'10minutemail.com', '10minutemail.net', 'tempmail.com', 'temp-mail.org',
		'trashmail.com', 'yopmail.com', 'getnada.com', 'dispostable.com',
		'maildrop.cc', 'mailnesia.com', 'throwawaymail.com', 'fakeinbox.com',
		'mytemp.email', 'moakt.com', 'mohmal.com', 'emailondeck.com',
		'spamgourmet.com', 'mailcatch.com', 'tempinbox.com', 'burnermail.io',
	];

	/**
	 * @param string $domain lowercase domain
	 * @return bool
	 */
	public static function is_disposable( $domain ) {
		$list = apply_filters( 'zaverweb_reviews_disposable_domains', self::$list );
		return in_array( strtolower( $domain ), array_map( 'strtolower', (array) $list ), true );
	}
}
