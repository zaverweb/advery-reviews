<?php
namespace ZaverWeb\Reviews\AntiSpam;

/**
 * Server-side verification for the supported CAPTCHA providers. All four use the
 * same "POST secret+response, get {success,...}" shape, so one verifier handles
 * them; reCAPTCHA v3 additionally checks the returned score against a threshold.
 */
class CaptchaVerifier {

	const ENDPOINTS = [
		'recaptcha_v2' => 'https://www.google.com/recaptcha/api/siteverify',
		'recaptcha_v3' => 'https://www.google.com/recaptcha/api/siteverify',
		'hcaptcha'     => 'https://api.hcaptcha.com/siteverify',
		'turnstile'    => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
	];

	/**
	 * @param array  $config anti-spam config (provider, secret, threshold)
	 * @param string $token  the client-side response token
	 * @param string $ip
	 * @return bool
	 */
	public static function verify( array $config, $token, $ip = '' ) {
		$provider = $config['captcha_provider'] ?? 'none';
		$secret   = $config['captcha_secret_key'] ?? '';

		if ( 'none' === $provider || '' === $secret || ! isset( self::ENDPOINTS[ $provider ] ) ) {
			return true; // not configured → don't block
		}
		if ( '' === $token ) {
			return false;
		}

		$response = wp_remote_post(
			self::ENDPOINTS[ $provider ],
			[
				'timeout' => 8,
				'body'    => [
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => $ip,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			// Fail open on a network error so a provider outage can't block all reviews.
			return true;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['success'] ) ) {
			return false;
		}

		if ( 'recaptcha_v3' === $provider && isset( $data['score'] ) ) {
			$threshold = (float) ( $config['captcha_threshold'] ?? 0.5 );
			return (float) $data['score'] >= $threshold;
		}

		return true;
	}
}
