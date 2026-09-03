<?php
namespace ZaverWeb\Reviews\Admin;

use ZaverWeb\Reviews\Support\Settings;

/**
 * Adds a reviews section to the Edit Term screen for every enabled taxonomy
 * (custom or built-in), mirroring the post/product metabox: it lists that
 * term's reviews and lets a manager add one (as themselves or under any name)
 * and approve / mark pending / spam / trash / delete each. Term screens don't
 * use add_meta_box, so we inject into the `{$taxonomy}_edit_form` action and
 * reuse the same vanilla-JS metabox bundle (it reads the object type/id off the
 * container element).
 */
class TermMetabox {

	public function register() {
		add_action( 'admin_init', [ $this, 'hook_forms' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Enabled taxonomies (from settings).
	 *
	 * @return string[]
	 */
	private function taxonomies() {
		return array_values( array_filter( (array) Settings::get( 'enabled_taxonomies', [] ) ) );
	}

	/**
	 * Attach our section to the edit-term form of each enabled taxonomy.
	 */
	public function hook_forms() {
		foreach ( $this->taxonomies() as $tax ) {
			add_action( "{$tax}_edit_form", [ $this, 'render' ], 10, 2 );
		}
	}

	/**
	 * @param \WP_Term $term
	 * @param string   $taxonomy
	 */
	public function render( $term, $taxonomy ) {
		$term_id = is_object( $term ) ? (int) $term->term_id : (int) $term;
		echo '<h2 style="margin-top:24px">' . esc_html__( 'Zaver Web Reviews', 'zaverweb-reviews' ) . '</h2>';
		printf(
			'<div id="zaverweb-reviews-metabox" class="zaverweb-mb-term" data-object-type="term" data-object-id="%d">%s</div>',
			$term_id,
			esc_html__( 'Loading…', 'zaverweb-reviews' )
		);
	}

	/**
	 * Load the shared metabox assets on the Edit Term screen of an enabled
	 * taxonomy (the edit-term screen is `term.php`).
	 *
	 * @param string $hook
	 */
	public function enqueue( $hook ) {
		if ( 'term.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->taxonomy, $this->taxonomies(), true ) ) {
			return;
		}
		PostMetabox::enqueue_assets();
	}
}
