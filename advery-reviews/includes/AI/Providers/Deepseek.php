<?php
namespace Advery\Reviews\AI\Providers;

/**
 * DeepSeek — OpenAI-compatible API (api.deepseek.com). Override the base URL in
 * settings if DeepSeek changes their endpoint.
 */
class Deepseek extends OpenAI {

	protected $base = 'https://api.deepseek.com';

	public function default_model() {
		return 'deepseek-chat';
	}
}
