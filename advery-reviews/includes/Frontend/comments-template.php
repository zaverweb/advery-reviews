<?php
/**
 * Loaded via the `comments_template` filter when "replace comments" is on.
 * Prints the Advery Reviews widget for the current target in place of the
 * theme's comment list + form.
 *
 * @package Advery\Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$advery_target = \Advery\Reviews\Support\Targets::current();
if ( $advery_target ) {
	// Display::render() returns already-escaped markup.
	echo ( new \Advery\Reviews\Frontend\Display() )->render( $advery_target[0], $advery_target[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
