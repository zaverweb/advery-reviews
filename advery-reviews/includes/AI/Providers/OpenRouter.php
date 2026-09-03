<?php
namespace ZaverWeb\Reviews\AI\Providers;

/**
 * OpenRouter — OpenAI-compatible gateway to many models.
 */
class OpenRouter extends OpenAI {

	protected $base = 'https://openrouter.ai/api/v1';

	public function default_model() {
		return 'anthropic/claude-3.5-sonnet';
	}
}
