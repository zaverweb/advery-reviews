<?php
namespace Advery\Reviews\AI\Providers;

/**
 * AvalAI — an OpenAI-compatible gateway (popular in Iran). Uses the OpenAI
 * request format; set your AvalAI key. Override the base URL in settings if it
 * differs from the default.
 */
class Avalai extends OpenAI {

	protected $base = 'https://api.avalai.ir/v1';

	public function default_model() {
		return 'gpt-4o-mini';
	}
}
