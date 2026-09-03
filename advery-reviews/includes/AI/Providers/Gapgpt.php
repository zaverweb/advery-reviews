<?php
namespace ZaverWeb\Reviews\AI\Providers;

/**
 * GapGPT — an OpenAI-compatible gateway (popular in Iran). Uses the OpenAI
 * request format; set your GapGPT key. Override the base URL in settings if it
 * differs from the default.
 */
class Gapgpt extends OpenAI {

	protected $base = 'https://api.gapgpt.app/v1';

	public function default_model() {
		return 'gpt-4o-mini';
	}
}
