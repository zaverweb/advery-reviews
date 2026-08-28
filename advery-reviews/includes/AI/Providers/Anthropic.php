<?php
namespace Advery\Reviews\AI\Providers;

use Advery\Reviews\AI\ProviderInterface;

/**
 * Anthropic Claude adapter (Messages API).
 */
class Anthropic implements ProviderInterface {

	public function default_model() {
		return 'claude-sonnet-4-5';
	}

	public function chat( $system, $user, array $opts ) {
		$key = $opts['api_key'] ?? '';
		if ( '' === $key ) {
			return new \WP_Error( 'advery_ai_key', __( 'No API key configured.', 'advery-reviews' ) );
		}
		$base = ! empty( $opts['base_url'] ) ? untrailingslashit( $opts['base_url'] ) : 'https://api.anthropic.com';

		$response = wp_remote_post(
			$base . '/v1/messages',
			[
				'timeout' => 45,
				'headers' => [
					'content-type'      => 'application/json',
					'x-api-key'         => $key,
					'anthropic-version' => '2023-06-01',
				],
				'body'    => wp_json_encode(
					[
						'model'       => $opts['model'] ?: $this->default_model(),
						'max_tokens'  => (int) ( $opts['max_tokens'] ?? 600 ),
						'temperature' => (float) ( $opts['temperature'] ?? 0.7 ),
						'system'      => (string) $system,
						'messages'    => [ [ 'role' => 'user', 'content' => (string) $user ] ],
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
		if ( isset( $data['content'][0]['text'] ) ) {
			return (string) $data['content'][0]['text'];
		}
		return new \WP_Error( 'advery_ai_parse', __( 'Unexpected AI response.', 'advery-reviews' ) );
	}
}
