<?php
namespace Advery\Reviews\AI\Providers;

use Advery\Reviews\AI\ProviderInterface;

/**
 * Google Gemini adapter (generateContent).
 */
class Gemini implements ProviderInterface {

	public function default_model() {
		return 'gemini-1.5-flash';
	}

	public function chat( $system, $user, array $opts ) {
		$key = $opts['api_key'] ?? '';
		if ( '' === $key ) {
			return new \WP_Error( 'advery_ai_key', __( 'No API key configured.', 'advery-reviews' ) );
		}
		$base  = ! empty( $opts['base_url'] ) ? untrailingslashit( $opts['base_url'] ) : 'https://generativelanguage.googleapis.com/v1beta';
		$model = $opts['model'] ?: $this->default_model();

		$response = wp_remote_post(
			$base . '/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $key ),
			[
				'timeout' => 45,
				'headers' => [ 'content-type' => 'application/json' ],
				'body'    => wp_json_encode(
					[
						'systemInstruction' => [ 'parts' => [ [ 'text' => (string) $system ] ] ],
						'contents'          => [ [ 'role' => 'user', 'parts' => [ [ 'text' => (string) $user ] ] ] ],
						'generationConfig'  => [
							'temperature'     => (float) ( $opts['temperature'] ?? 0.7 ),
							'maxOutputTokens' => (int) ( $opts['max_tokens'] ?? 600 ),
						],
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( isset( $data['error'] ) ) {
			return new \WP_Error( 'advery_ai_api', $data['error']['message'] ?? 'API error' );
		}
		if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return (string) $data['candidates'][0]['content']['parts'][0]['text'];
		}
		return new \WP_Error( 'advery_ai_parse', __( 'Unexpected AI response.', 'advery-reviews' ) );
	}
}
