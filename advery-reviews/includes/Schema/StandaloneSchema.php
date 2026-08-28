<?php
namespace Advery\Reviews\Schema;

use Advery\Reviews\Support\Settings;
use Advery\Reviews\Support\Targets;
use Advery\Reviews\Support\Aggregate;
use Advery\Reviews\Database\ReviewRepository;

/**
 * Emits our OWN JSON-LD (`aggregateRating` + a few `review` nodes) so the plugin
 * produces schema even when Advery Schema Plus isn't installed. Whether this
 * runs is decided by the `schema_mode` setting:
 *
 *   auto        use the core plugin when it's active, otherwise emit our own
 *   core        only via Advery Schema Plus (this class stays silent)
 *   standalone  always emit our own here (the SchemaBridge stays silent)
 *   off         no schema at all
 *
 * WooCommerce products are always left to Woo's own product schema to avoid a
 * duplicate/competing block.
 */
class StandaloneSchema {

	public function register() {
		add_action( 'wp_head', [ $this, 'render' ], 20 );
	}

	public function render() {
		if ( ! Settings::get( 'schema_output' ) ) {
			return;
		}
		$mode = Settings::get( 'schema_mode', 'auto' );
		if ( 'off' === $mode || 'core' === $mode ) {
			return;
		}
		if ( 'auto' === $mode && defined( 'ADVERY_SCHEMA_VERSION' ) ) {
			return; // the core engine (via SchemaBridge) handles it
		}

		$target = Targets::current();
		if ( ! $target ) {
			return;
		}
		list( $object_type, $object_id ) = $target;

		if ( 'product' === $object_type && class_exists( 'WooCommerce' ) ) {
			return; // Woo outputs product schema itself
		}

		$agg = Aggregate::for( $object_type, $object_id );
		if ( $agg['count'] < 1 ) {
			return;
		}

		$name = ( 'term' === $object_type ) ? Targets::label( $object_type, $object_id ) : get_the_title( $object_id );
		$url  = Targets::link( $object_type, $object_id );
		$type = ( 'product' === $object_type ) ? 'Product' : (string) Settings::get( 'schema_type', 'LocalBusiness' );

		$node = [
			'@context' => 'https://schema.org',
			'@type'    => $type,
			'name'     => $name ? $name : get_bloginfo( 'name' ),
		];
		if ( $url ) {
			$node['url'] = $url;
			$node['@id'] = $url . '#advery-reviews';
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

		echo "\n<!-- Advery Reviews schema -->\n";
		echo '<script type="application/ld+json">' . wp_json_encode( $node, JSON_UNESCAPED_UNICODE ) . "</script>\n";
	}

	/**
	 * @param string $object_type
	 * @param int    $object_id
	 * @return array[]
	 */
	private function review_nodes( $object_type, $object_id ) {
		$out = [];
		foreach ( ReviewRepository::approved_for( $object_type, $object_id, 5 ) as $r ) {
			$node = [
				'@type'         => 'Review',
				'author'        => [ '@type' => 'Person', 'name' => $r['author_name'] ?: __( 'Anonymous', 'advery-reviews' ) ],
				'datePublished' => mysql2date( 'Y-m-d', $r['created_at'] ),
			];
			if ( $r['rating'] > 0 ) {
				$node['reviewRating'] = [ '@type' => 'Rating', 'ratingValue' => (string) $r['rating'], 'bestRating' => '5', 'worstRating' => '1' ];
			}
			if ( '' !== $r['content'] ) {
				$node['reviewBody'] = wp_strip_all_tags( $r['content'] );
			}
			$out[] = $node;
		}
		return $out;
	}
}
