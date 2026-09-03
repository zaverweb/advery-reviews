<?php
namespace ZaverWeb\Reviews\AI\Providers;

use ZaverWeb\Reviews\AI\ProviderInterface;

/**
 * Anthropic Claude adapter (Messages API).
 */
class Anthropic implements ProviderInterface {

	/** Token usage from the last call: [ 'input' => int, 'output' => int ]. */
	protected $usage = [ 'input' => 0, 'output' => 0 ];

	public function default_model() {
		return 'claude-sonnet-4-5';
	}

	/**
	 * @return array{input:int,output:int} Tokens used by the last chat() call.
	 */
	public function last_usage() {
		return $this->usage;
	}

	public function chat( $system, $user, array $opts ) {
		$key = $opts['api_key'] ?? '';
		if ( '' === $key ) {
			return new \WP_Error( 'zaverweb_ai_key', __( 'No API key configured.', 'zaverweb-reviews' ) );
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
			return new \WP_Error( 'zaverweb_ai_api', $data['error']['message'] ?? 'API error' );
		}
		if ( isset( $data['usage'] ) ) {
			$this->usage = [
				'input'  => (int) ( $data['usage']['input_tokens'] ?? 0 ),
				'output' => (int) ( $data['usage']['output_tokens'] ?? 0 ),
			];
		}
		if ( isset( $data['content'][0]['text'] ) ) {
			return (string) $data['content'][0]['text'];
		}
		return new \WP_Error( 'zaverweb_ai_parse', __( 'Unexpected AI response.', 'zaverweb-reviews' ) );
	}
}
