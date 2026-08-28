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
					$lines[] = 'Business: ' . $ctx['business'];
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
	 * Fill placeholders like {business} / {target} in a system prompt.
	 *
	 * @param string $prompt
	 * @param array  $ctx
	 * @return string
	 */
	public static function fill( $prompt, array $ctx ) {
		return strtr(
			$prompt,
			[
				'{business}' => (string) ( $ctx['business'] ?? get_bloginfo( 'name' ) ),
				'{target}'   => (string) ( $ctx['target'] ?? 'English' ),
			]
		);
	}
}
