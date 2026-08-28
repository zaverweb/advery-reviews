<?php
namespace Advery\Reviews\AI;

use Advery\Reviews\Support\Settings;
use Advery\Reviews\AI\Providers\Anthropic;
use Advery\Reviews\AI\Providers\OpenAI;
use Advery\Reviews\AI\Providers\OpenRouter;
use Advery\Reviews\AI\Providers\Ollama;
use Advery\Reviews\AI\Providers\Gemini;

/**
 * The single entry point for AI work: picks the configured provider, enforces
 * the per-task enable flag and the daily call cap, builds the system + user
 * prompts (default or the owner's override), calls the model, logs the call,
 * and returns clean text or a WP_Error. Nothing else in the plugin talks to a
 * provider directly.
 */
class Client {

	/**
	 * @return bool Whether a usable provider is configured.
	 */
	public static function configured() {
		$ai = Settings::ai();
		if ( empty( $ai['provider'] ) ) {
			return false;
		}
		// Local providers (Ollama) don't need a key.
		if ( 'ollama' === $ai['provider'] ) {
			return true;
		}
		return '' !== trim( (string) $ai['api_key'] );
	}

	/**
	 * @param string $provider
	 * @return ProviderInterface|null
	 */
	public static function provider( $provider ) {
		switch ( $provider ) {
			case 'anthropic':
				return new Anthropic();
			case 'openai':
				return new OpenAI();
			case 'openrouter':
				return new OpenRouter();
			case 'ollama':
				return new Ollama();
			case 'gemini':
				return new Gemini();
		}
		return null;
	}

	/**
	 * Run a task.
	 *
	 * @param string $task 'reply'|'moderate'|'translate'|'summarize'
	 * @param array  $ctx  { business, rating, author, content, target }
	 * @return string|\WP_Error
	 */
	public static function run( $task, array $ctx ) {
		if ( ! in_array( $task, Tasks::TASKS, true ) ) {
			return new \WP_Error( 'advery_ai_task', __( 'Unknown AI task.', 'advery-reviews' ) );
		}

		$ai       = Settings::ai();
		$task_cfg = $ai['tasks'][ $task ] ?? [];

		if ( empty( $task_cfg['enabled'] ) ) {
			return new \WP_Error( 'advery_ai_disabled', __( 'This AI task is turned off.', 'advery-reviews' ) );
		}
		if ( ! self::configured() ) {
			return new \WP_Error( 'advery_ai_config', __( 'AI is not configured (add a provider and API key in Settings → AI).', 'advery-reviews' ) );
		}
		if ( ! AuditLog::under_cap( $ai['daily_cap'] ) ) {
			return new \WP_Error( 'advery_ai_cap', __( 'The daily AI limit has been reached.', 'advery-reviews' ) );
		}

		$provider = self::provider( $ai['provider'] );
		if ( ! $provider ) {
			return new \WP_Error( 'advery_ai_provider', __( 'Unknown AI provider.', 'advery-reviews' ) );
		}

		$system = ! empty( $task_cfg['prompt'] ) ? $task_cfg['prompt'] : Tasks::default_prompt( $task );
		$system = Tasks::fill( $system, $ctx );
		if ( 'reply' === $task ) {
			// Tell the model who it is relative to the reviewed item.
			$system .= Tasks::role_clause( $ctx['role'] ?? 'owner' );
		}
		$user = Tasks::user_message( $task, $ctx );

		$result = $provider->chat(
			$system,
			$user,
			[
				'api_key'     => $ai['api_key'],
				'base_url'    => $ai['base_url'],
				'model'       => $ai['model'],
				'temperature' => ( 'moderate' === $task ) ? 0.0 : (float) $ai['temperature'],
				'max_tokens'  => ( 'moderate' === $task ) ? 8 : (int) $ai['max_tokens'],
			]
		);

		AuditLog::record( $task, $ai['provider'], ! is_wp_error( $result ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return trim( (string) $result );
	}

	/**
	 * Normalise a moderation reply to one of approve|review|spam.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function moderation_verdict( $text ) {
		$t = strtolower( $text );
		if ( false !== strpos( $t, 'spam' ) ) {
			return 'spam';
		}
		if ( false !== strpos( $t, 'approve' ) ) {
			return 'approve';
		}
		return 'review';
	}
}
