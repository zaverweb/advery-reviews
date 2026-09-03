<?php
namespace ZaverWeb\Reviews\Schema;

use ZaverWeb\Reviews\Support\Settings;
use ZaverWeb\Reviews\Support\Targets;
use ZaverWeb\Reviews\Support\Aggregate;
use ZaverWeb\Reviews\Database\ReviewRepository;

/**
 * Injects `aggregateRating` (and a few `review` nodes) into the Advery Schema
 * Plus graph. It hooks the core's `advery_schema_render_node` filter and, on a
 * singular/term page that is a review target, attaches the aggregate to the
 * node that represents that exact item — matched by a directory listing's post
 * id or by the node's `url` equalling the item's permalink. When the core is
 * not installed the filter simply never fires, so this is inert and safe.
 *
 * WooCommerce products are intentionally left to Woo's own product schema to
 * avoid duplicate/conflicting aggregateRating.
 */
class SchemaBridge {

	public function register() {
		add_filter( 'advery_schema_render_node', [ $this, 'augment_node' ], 10, 3 );
	}

	/**
	 * @param array $node
	 * @param array $template
	 * @param string $mode
	 * @return array
	 */
	public function augment_node( $node, $template, $mode = '' ) {
		if ( ! is_array( $node ) || ! Settings::get( 'schema_output' ) ) {
			return $node;
		}
		// Only augment the core's nodes in auto/core modes. In 'standalone' or
		// 'off' the StandaloneSchema class (or nothing) handles output instead.
		$mode = Settings::get( 'schema_mode', 'auto' );
		if ( 'auto' !== $mode && 'core' !== $mode ) {
			return $node;
		}

		$target = Targets::current();
		if ( ! $target ) {
			return $node;
		}
		list( $object_type, $object_id ) = $target;

		// Products are covered by WooCommerce's own schema; don't double up.
		if ( 'product' === $object_type ) {
			return $node;
		}

		if ( ! $this->node_is_item( $node, $template, $object_type, $object_id ) ) {
			return $node;
		}

		$agg = Aggregate::for( $object_type, $object_id );
		if ( $agg['count'] < 1 ) {
			return $node; // No ratings yet — nothing to add.
		}

		$node['aggregateRating'] = [
			'@type'       => 'AggregateRating',
			'ratingValue' => (string) $agg['avg'],
			'reviewCount' => (string) max( $agg['review_count'], $agg['count'] ),
			'bestRating'  => '5',
			'worstRating' => '1',
		];

		$reviews = $this->review_nodes( $object_type, $object_id );
		if ( ! empty( $reviews ) ) {
			$node['review'] = $reviews;
		}

		return $node;
	}

	/**
	 * Does this node represent the current review target?
	 *
	 * @param array  $node
	 * @param array  $template
	 * @param string $object_type
	 * @param int    $object_id
	 * @return bool
	 */
	private function node_is_item( array $node, $template, $object_type, $object_id ) {
		// A directory listing carries the post id as its template id.
		if ( is_array( $template )
			&& ( $template['schema_type'] ?? '' ) === 'directory_listing'
			&& (int) ( $template['id'] ?? 0 ) === (int) $object_id ) {
			return true;
		}

		// Otherwise match by URL: the node's url equals the item's permalink.
		$item_url = ( 'term' === $object_type )
			? get_term_link( (int) $object_id )
			: get_permalink( (int) $object_id );

		if ( is_wp_error( $item_url ) || ! $item_url ) {
			return false;
		}

		return isset( $node['url'] ) && untrailingslashit( (string) $node['url'] ) === untrailingslashit( $item_url );
	}

	/**
	 * Up to five recent approved reviews as schema.org Review nodes.
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 * @return array[]
	 */
	private function review_nodes( $object_type, $object_id ) {
		$out = [];
		foreach ( ReviewRepository::approved_for( $object_type, $object_id, 5 ) as $r ) {
			$review = [
				'@type'         => 'Review',
				'author'        => [ '@type' => 'Person', 'name' => $r['author_name'] ?: __( 'Anonymous', 'zaverweb-reviews' ) ],
				'datePublished' => mysql2date( 'Y-m-d', $r['created_at'] ),
			];
			if ( $r['rating'] > 0 ) {
				$review['reviewRating'] = [
					'@type'       => 'Rating',
					'ratingValue' => (string) $r['rating'],
					'bestRating'  => '5',
					'worstRating' => '1',
				];
			}
			if ( '' !== $r['content'] ) {
				$review['reviewBody'] = wp_strip_all_tags( $r['content'] );
			}
			if ( '' !== $r['title'] ) {
				$review['name'] = $r['title'];
			}
			$out[] = $review;
		}
		return $out;
	}
}
