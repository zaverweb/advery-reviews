<?php
namespace ZaverWeb\Reviews\AI\Providers;

/**
 * Ollama (self-hosted) via its OpenAI-compatible endpoint. No API key needed;
 * the base URL points at the local Ollama server.
 */
class Ollama extends OpenAI {

	protected $base = 'http://localhost:11434/v1';

	public function default_model() {
		return 'llama3.1';
	}
}
