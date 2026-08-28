<?php
namespace Advery\Reviews\Frontend;

use Advery\Reviews\Support\Settings;
use Advery\Reviews\Support\Targets;

/**
 * Optionally takes over the theme's native comments area with the reviews
 * widget — no theme edits and no page builder required. It swaps the template
 * WordPress loads for the comment section (`comments_template`) for a tiny
 * plugin template that prints the widget, but only on enabled targets.
 */
class CommentsTakeover {

	public function register() {
		if ( ! Settings::get( 'replace_comments' ) ) {
			return;
		}
		add_filter( 'comments_template', [ $this, 'template' ], 100 );
	}

	/**
	 * @param string $theme_template Path the theme would load.
	 * @return string
	 */
	public function template( $theme_template ) {
		if ( Targets::current() ) {
			return ADVERY_REVIEWS_PATH . 'includes/Frontend/comments-template.php';
		}
		return $theme_template;
	}
}
