<?php
namespace Advery\Reviews\AI;

/**
 * The prompt system. Each task has a clear default system prompt tuned to sound
 * natural and human — especially the owner *reply* task, which must read like a
 * real business responding to a real review. Every default is overridable in
 * settings. These tasks operate on REAL reviews (drafting a reply, moderating,
 * translating, summarising); the plugin does not generate fake reviews.
 */
class Tasks {

	const TASKS = [ 'reply', 'moderate', 'translate', 'summarize' ];

	/**
	 * Default system prompt for a task.
	 *
	 * @param string $task
	 * @return string
	 */
	public static function default_prompt( $task ) {
		switch ( $task ) {
			case 'reply':
				return "You are the owner/manager of \"{business}\" personally replying to a customer's review on your website. "
					. "Write the reply in the SAME language as the review. Keep it warm, professional and specific — 2 to 4 sentences. "
					. "Thank the reviewer and address their actual points. If the review is negative, apologise sincerely and offer to make it right, without being defensive or making excuses. "
					. "Do NOT invent facts, prices, names, discounts or promises. Never pretend to be the customer. Sound like a real person, not a template. "
					. 'Output only the reply text, with no quotation marks or preamble.';

			case 'moderate':
				return "You moderate user-submitted reviews for a website. Decide whether a review is genuine and safe to publish. "
					. "Reply with EXACTLY one word: APPROVE, REVIEW, or SPAM. "
					. "SPAM = advertising, links/promotion, gibberish, hateful or offensive content, or an obviously fake review. "
					. "REVIEW = borderline or uncertain. APPROVE = a genuine, harmless review. No other output.";

			case 'translate':
				return 'Translate the customer review below into {target}. Preserve the tone, meaning and any rating context. Output only the translation, nothing else.';

			case 'summarize':
				return 'You summarise customer reviews. Write 2-3 short, neutral sentences capturing the main recurring themes (the "highlights"). Do not invent details or quote prices. Output only the summary.';
		}
		return '';
	}

	/**
	 * Build the user message for a task from context.
	 *
	 * @param string $task
	 * @param array  $ctx
	 * @return string
	 */
	public static function user_message( $task, array $ctx ) {
		switch ( $task ) {
			case 'reply':
			case 'moderate':
			case 'translate':
				$lines = [];
				if ( ! empty( $ctx['business'] ) ) {
					$lines[] = 'Item / business being reviewed: ' . $ctx['business'];
				}
				if ( 'reply' === $task ) {
					if ( ! empty( $ctx['site_name'] ) ) {
						$lines[] = 'Our website: ' . $ctx['site_name'] . ( ! empty( $ctx['site_description'] ) ? ' — ' . $ctx['site_description'] : '' );
					}
					if ( ! empty( $ctx['page_excerpt'] ) ) {
						$lines[] = "Page content (for context):\n" . $ctx['page_excerpt'];
					}
					if ( ! empty( $ctx['business_context'] ) ) {
						$lines[] = "About us (use only if relevant):\n" . $ctx['business_context'];
					}
				}
				if ( isset( $ctx['rating'] ) && $ctx['rating'] > 0 ) {
					$lines[] = 'Rating: ' . (int) $ctx['rating'] . '/5';
				}
				if ( ! empty( $ctx['author'] ) ) {
					$lines[] = 'Reviewer: ' . $ctx['author'];
				}
				$lines[] = 'Review:';
				$lines[] = (string) ( $ctx['content'] ?? '' );
				return implode( "\n", $lines );

			case 'summarize':
				return (string) ( $ctx['content'] ?? '' );
		}
		return (string) ( $ctx['content'] ?? '' );
	}

	/**
	 * Relationship clause appended to the reply system prompt so the AI knows
	 * whether it speaks AS the business (owner/seller) or AS the platform (a
	 * third-party listing in our directory — we must not speak for the business).
	 *
	 * @param string $role 'owner' | 'listing'
	 * @return string
	 */
	public static function role_clause( $role ) {
		if ( 'listing' === $role ) {
			return ' IMPORTANT RELATIONSHIP: the item reviewed is a THIRD-PARTY business listed in our directory. '
				. 'We are the DIRECTORY / platform, NOT that business. Do NOT speak on the business behalf, do NOT apologise or make promises for them, and never pretend to be their owner or staff. '
				. 'Reply briefly and neutrally as the platform: thank the reviewer for sharing their experience of the listed business on our site; if the review is negative, acknowledge it and note the feedback helps others and will be shared with the listing. Keep it short.';
		}
		return ' RELATIONSHIP: this is OUR OWN product/service on OUR site and you ARE the owner/seller replying. '
			. 'Use the page content and About-us context provided to be specific and accurate; do not invent facts beyond that context.';
	}

	/**
	 * Placeholders an owner can use in a custom prompt, with a short description.
	 * Used by the admin "Prompts" tab as a reference; substituted by fill().
	 *
	 * @return array<string,string> token => description
	 */
	public static function variables() {
		return [
			'{business}'         => __( 'Name of the item being reviewed (post / product / term title)', 'advery-reviews' ),
			'{reviewer_name}'    => __( 'Name the reviewer submitted', 'advery-reviews' ),
			'{rating}'           => __( 'The review’s star rating, 1–5 (0 when none)', 'advery-reviews' ),
			'{review}'           => __( 'The review text', 'advery-reviews' ),
			'{site_name}'        => __( 'Your site name', 'advery-reviews' ),
			'{site_description}' => __( 'Your site tagline / description', 'advery-reviews' ),
			'{business_context}' => __( 'The “About your business” text from AI settings', 'advery-reviews' ),
			'{target}'           => __( 'Target language (translate task only)', 'advery-reviews' ),
			'{field:KEY}'        => __( 'Any custom field of the reviewed post/term — replace KEY with the meta key (e.g. {field:phone})', 'advery-reviews' ),
		];
	}

	/**
	 * Fill placeholders in a system prompt from the review context. Supports the
	 * fixed tokens in variables() plus {field:meta_key}, which pulls a custom
	 * field off the reviewed post (or term). Unknown/empty tokens become ''.
	 *
	 * @param string $prompt
	 * @param array  $ctx
	 * @return string
	 */
	public static function fill( $prompt, array $ctx ) {
		// {field:meta_key} → the reviewed object's custom field.
		$prompt = preg_replace_callback(
			'/\{field:([a-zA-Z0-9_\-]{1,64})\}/',
			static function ( $m ) use ( $ctx ) {
				return self::field_value( $ctx, $m[1] );
			},
			$prompt
		);

		$rating = isset( $ctx['rating'] ) ? (int) $ctx['rating'] : 0;

		return strtr(
			$prompt,
			[
				'{business}'         => (string) ( $ctx['business'] ?? get_bloginfo( 'name' ) ),
				'{reviewer_name}'    => (string) ( $ctx['author'] ?? '' ),
				'{rating}'           => $rating > 0 ? (string) $rating : '',
				'{review}'           => (string) ( $ctx['content'] ?? '' ),
				'{site_name}'        => (string) ( $ctx['site_name'] ?? get_bloginfo( 'name' ) ),
				'{site_description}' => (string) ( $ctx['site_description'] ?? get_bloginfo( 'description' ) ),
				'{business_context}' => (string) ( $ctx['business_context'] ?? '' ),
				'{target}'           => (string) ( $ctx['target'] ?? 'English' ),
			]
		);
	}

	/**
	 * Resolve a {field:KEY} token to the reviewed object's meta value.
	 *
	 * @param array  $ctx
	 * @param string $key
	 * @return string
	 */
	private static function field_value( array $ctx, $key ) {
		$id = (int) ( $ctx['object_id'] ?? 0 );
		if ( $id <= 0 ) {
			return '';
		}
		$value = ( 'term' === ( $ctx['object_type'] ?? 'post' ) )
			? get_term_meta( $id, $key, true )
			: get_post_meta( $id, $key, true );
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}
}
