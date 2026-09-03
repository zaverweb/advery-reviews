<?php
namespace ZaverWeb\Reviews\AI;

/**
 * Contract every AI provider adapter implements. One method: turn a system +
 * user prompt into text. Keeping this tiny means adding a provider is a small,
 * self-contained class, and the rest of the plugin never cares which vendor is
 * behind it.
 */
interface ProviderInterface {

	/**
	 * @param string $system System / instruction prompt.
	 * @param string $user   User prompt (the review, text to translate, etc.).
	 * @param array  $opts   { model, api_key, base_url, temperature, max_tokens }
	 * @return string|\WP_Error The model's text, or a WP_Error on failure.
	 */
	public function chat( $system, $user, array $opts );

	/**
	 * @return string A sensible default model id for this provider.
	 */
	public function default_model();
}
