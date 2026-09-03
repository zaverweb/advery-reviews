<?php
/**
 * Loaded via the `comments_template` filter when "replace comments" is on.
 * Prints the Zaver Web Reviews widget for the current target in place of the
 * theme's comment list + form.
 *
 * @package ZaverWeb\Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$zaverweb_target = \ZaverWeb\Reviews\Support\Targets::current();
if ( $zaverweb_target ) {
	// Display::render() returns already-escaped markup.
	echo ( new \ZaverWeb\Reviews\Frontend\Display() )->render( $zaverweb_target[0], $zaverweb_target[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
