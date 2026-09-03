<?php
namespace ZaverWeb\Reviews\AI\Providers;

use ZaverWeb\Reviews\AI\ProviderInterface;

/**
 * OpenAI Chat Completions adapter. Also the base for any OpenAI-compatible
 * endpoint (OpenRouter, Ollama, LM Studio, …) — subclasses just change the
 * default base URL / model.
 */
class OpenAI implements ProviderInterface {

	protected $base = 'https://api.openai.com/v1';

	/** Token usage from the last call: [ 'input' => int, 'output' => int ]. */
	protected $usage = [ 'input' => 0, 'output' => 0 ];

	public function default_model() {
		return 'gpt-4o-mini';
	}

	/**
	 * @return array{input:int,output:int} Tokens used by the last chat() call.
	 */
	public function last_usage() {
		return $this->usage;
	}

	public function chat( $system, $user, array $opts ) {
		$key  = $opts['api_key'] ?? '';
		$base = ! empty( $opts['base_url'] ) ? untrailingslashit( $opts['base_url'] ) : $this->base;

		if ( '' === $key && false === strpos( $base, 'localhost' ) && false === strpos( $base, '127.0.0.1' ) ) {
			return new \WP_Error( 'zaverweb_ai_key', __( 'No API key configured.', 'zaverweb-reviews' ) );
		}

		$headers = [ 'content-type' => 'application/json' ];
		if ( '' !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		$response = wp_remote_post(
			$base . '/chat/completions',
			[
				'timeout' => 45,
				'headers' => $headers,
				'body'    => wp_json_encode(
					[
						'model'       => $opts['model'] ?: $this->default_model(),
						'temperature' => (float) ( $opts['temperature'] ?? 0.7 ),
						'max_tokens'  => (int) ( $opts['max_tokens'] ?? 600 ),
						'messages'    => [
							[ 'role' => 'system', 'content' => (string) $system ],
							[ 'role' => 'user', 'content' => (string) $user ],
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
			return new \WP_Error( 'zaverweb_ai_api', is_array( $data['error'] ) ? ( $data['error']['message'] ?? 'API error' ) : (string) $data['error'] );
		}
		if ( isset( $data['usage'] ) ) {
			// OpenAI-style usage; Ollama returns prompt_eval_count/eval_count.
			$this->usage = [
				'input'  => (int) ( $data['usage']['prompt_tokens'] ?? $data['usage']['prompt_eval_count'] ?? 0 ),
				'output' => (int) ( $data['usage']['completion_tokens'] ?? $data['usage']['eval_count'] ?? 0 ),
			];
		}
		if ( isset( $data['choices'][0]['message']['content'] ) ) {
			return (string) $data['choices'][0]['message']['content'];
		}
		return new \WP_Error( 'zaverweb_ai_parse', __( 'Unexpected AI response.', 'zaverweb-reviews' ) );
	}
}
