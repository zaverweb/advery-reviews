<?php
namespace Advery\Reviews\Support;

/**
 * Resolves *what* is being reviewed on the current request and validates a
 * submitted target against the settings, so a review can only ever be attached
 * to a post type / taxonomy the site owner enabled. Also provides human labels
 * and links for the admin panel and email reports.
 *
 * Object types:
 *   - 'post'    a singular post of an enabled post type
 *   - 'product' a WooCommerce product (kept distinct so Woo native ratings can
 *               be merged and Woo's own schema is respected)
 *   - 'term'    a taxonomy term archive of an enabled taxonomy
 */
class Targets {

	/**
	 * The review target for the current main query, or null when reviews are
	 * not enabled here.
	 *
	 * @return array{0:string,1:int}|null
	 */
	public static function current() {
		if ( is_singular() ) {
			$id = (int) get_queried_object_id();
			if ( ! $id ) {
				return null;
			}
			$pt = get_post_type( $id );

			if ( 'product' === $pt && self::woo_active() && Settings::get( 'woo_enabled' ) ) {
				return [ 'product', $id ];
			}
			if ( in_array( $pt, (array) Settings::get( 'enabled_post_types', [] ), true ) ) {
				return [ 'post', $id ];
			}
			return null;
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term && isset( $term->term_id, $term->taxonomy )
				&& in_array( $term->taxonomy, (array) Settings::get( 'enabled_taxonomies', [] ), true ) ) {
				return [ 'term', (int) $term->term_id ];
			}
		}

		return null;
	}

	/**
	 * Validate that reviews are allowed for a given target (used on submit).
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 * @return bool
	 */
	public static function is_enabled( $object_type, $object_id ) {
		$object_id = (int) $object_id;
		if ( $object_id <= 0 ) {
			return false;
		}

		switch ( $object_type ) {
			case 'product':
				return self::woo_active()
					&& Settings::get( 'woo_enabled' )
					&& 'product' === get_post_type( $object_id );
			case 'post':
				return in_array( get_post_type( $object_id ), (array) Settings::get( 'enabled_post_types', [] ), true );
			case 'term':
				$term = get_term( $object_id );
				return $term && ! is_wp_error( $term )
					&& in_array( $term->taxonomy, (array) Settings::get( 'enabled_taxonomies', [] ), true );
		}
		return false;
	}

	/**
	 * A human label for a target (post/term title).
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 * @return string
	 */
	public static function label( $object_type, $object_id ) {
		if ( 'term' === $object_type ) {
			$term = get_term( (int) $object_id );
			return ( $term && ! is_wp_error( $term ) ) ? $term->name : sprintf( '#%d', $object_id );
		}
		$title = get_the_title( (int) $object_id );
		return $title ? $title : sprintf( '#%d', $object_id );
	}

	/**
	 * A front-end link for a target.
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 * @return string
	 */
	public static function link( $object_type, $object_id ) {
		if ( 'term' === $object_type ) {
			$link = get_term_link( (int) $object_id );
			return is_wp_error( $link ) ? '' : $link;
		}
		$link = get_permalink( (int) $object_id );
		return $link ? $link : '';
	}

	/**
	 * @return bool
	 */
	public static function woo_active() {
		return class_exists( 'WooCommerce' );
	}
}
