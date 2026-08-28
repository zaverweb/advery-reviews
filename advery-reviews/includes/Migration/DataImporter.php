<?php
namespace Advery\Reviews\Migration;

use Advery\Reviews\Database\ReviewRepository;
use Advery\Reviews\Support\Sanitizer;
use Advery\Reviews\Support\Settings;

/**
 * A generic importer for review data assembled elsewhere (CSV/JSON exported
 * from another platform, a scraped dataset, a spreadsheet). The plugin does not
 * scrape anything itself; it takes rows the owner supplies and maps their
 * columns onto reviews. Two kinds of "key" fields drive it:
 *
 *   1. A TARGET key — which column identifies the business/post each review
 *      belongs to, and how to resolve it (post id, slug, title, or a meta
 *      value such as an external business id). If the target doesn't exist the
 *      row is skipped ("if the business isn't here, move on").
 *   2. A UNIQUE key — external_source + a column holding the source's review
 *      id — used to de-duplicate: an already-imported review is updated (when
 *      enabled) instead of duplicated ("if it exists, update it").
 *
 * Compliance note: only import reviews you have the right to use, and don't feed
 * reviews you don't own into your own aggregateRating (schema output for
 * imported third-party reviews is the owner's responsibility).
 */
class DataImporter {

	/**
	 * @param array[] $rows    List of associative rows (column => value).
	 * @param array   $mapping Field/target/key mapping (see class doc).
	 * @param array   $options { update_existing:bool }
	 * @return array{imported:int,updated:int,skipped:int,errors:array}
	 */
	public static function import( array $rows, array $mapping, array $options = [] ) {
		$update_existing = ! empty( $options['update_existing'] );
		$imported        = 0;
		$updated         = 0;
		$skipped         = 0;
		$errors          = [];

		$cols   = isset( $mapping['columns'] ) && is_array( $mapping['columns'] ) ? $mapping['columns'] : [];
		$source = isset( $mapping['external_source'] ) ? sanitize_key( $mapping['external_source'] ) : 'import';
		if ( '' === $source ) {
			$source = 'import';
		}

		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) ) {
				$skipped++;
				continue;
			}

			$target = self::resolve_target( $row, $mapping );
			if ( ! $target ) {
				$skipped++;
				if ( count( $errors ) < 20 ) {
					$errors[] = sprintf( 'row %d: target not found', (int) $i + 1 );
				}
				continue;
			}
			list( $object_type, $object_id ) = $target;

			$content = isset( $cols['content'] ) && '' !== $cols['content'] ? Sanitizer::content( (string) self::col( $row, $cols['content'] ) ) : '';
			if ( '' === $content && empty( $cols['allow_empty_content'] ) ) {
				$skipped++;
				continue;
			}

			$external_id = isset( $mapping['external_id_column'] ) && '' !== $mapping['external_id_column']
				? (string) self::col( $row, $mapping['external_id_column'] )
				: '';

			$data = [
				'object_type'     => $object_type,
				'object_id'       => $object_id,
				'rating'          => self::rating( self::col( $row, $cols['rating'] ?? '' ) ),
				'author_name'     => Sanitizer::text( (string) self::col( $row, $cols['author_name'] ?? '' ), 150 ),
				'author_email'    => Sanitizer::email( (string) self::col( $row, $cols['author_email'] ?? '' ) ),
				'title'           => Sanitizer::text( (string) self::col( $row, $cols['title'] ?? '' ), 200 ),
				'content'         => $content,
				'status'          => self::status( $row, $mapping ),
				'external_source' => $source,
				'external_id'     => $external_id,
				'created_at'      => self::date( self::col( $row, $cols['created_at'] ?? '' ) ),
				'meta'            => [ 'imported_from' => $source ],
			];

			// De-dup on (source, external_id) when an external id is present.
			$existing = '' !== $external_id ? ReviewRepository::find_id_by_external( $source, $external_id ) : 0;
			if ( $existing ) {
				if ( $update_existing ) {
					ReviewRepository::update(
						$existing,
						[
							'rating'       => $data['rating'],
							'author_name'  => $data['author_name'],
							'author_email' => $data['author_email'],
							'title'        => $data['title'],
							'content'      => $data['content'],
							'status'       => $data['status'],
							'created_at'   => $data['created_at'],
						]
					);
					$updated++;
				} else {
					$skipped++;
				}
				continue;
			}

			if ( ReviewRepository::create( $data ) ) {
				$imported++;
			} else {
				$skipped++;
			}
		}

		return [
			'imported' => $imported,
			'updated'  => $updated,
			'skipped'  => $skipped,
			'errors'   => $errors,
		];
	}

	/**
	 * Resolve which post/product a row targets.
	 *
	 * @param array $row
	 * @param array $mapping
	 * @return array{0:string,1:int}|null
	 */
	private static function resolve_target( array $row, array $mapping ) {
		$mode   = $mapping['target_mode'] ?? 'post_id';
		$column = $mapping['target_column'] ?? '';
		$raw    = trim( (string) self::col( $row, $column ) );
		if ( '' === $raw ) {
			return null;
		}

		$post_id = 0;

		if ( 'post_id' === $mode ) {
			$post_id = (int) $raw;
		} else {
			// lookup by slug / title / meta among candidate post types.
			$post_types = self::candidate_post_types();
			$lookup_by  = $mapping['lookup_by'] ?? 'slug';

			if ( 'meta' === $lookup_by ) {
				$meta_key = sanitize_text_field( $mapping['lookup_meta_key'] ?? '' );
				if ( '' === $meta_key ) {
					return null;
				}
				$found = get_posts(
					[
						'post_type'   => $post_types,
						'post_status' => 'any',
						'numberposts' => 1,
						'fields'      => 'ids',
						'meta_key'    => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value'  => $raw,      // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					]
				);
				$post_id = $found ? (int) $found[0] : 0;
			} elseif ( 'title' === $lookup_by ) {
				$found = get_posts(
					[
						'post_type'   => $post_types,
						'post_status' => 'any',
						'numberposts' => 1,
						'fields'      => 'ids',
						'title'       => $raw,
					]
				);
				$post_id = $found ? (int) $found[0] : 0;
			} else { // slug
				$found = get_posts(
					[
						'post_type'   => $post_types,
						'post_status' => 'any',
						'numberposts' => 1,
						'fields'      => 'ids',
						'name'        => sanitize_title( $raw ),
					]
				);
				$post_id = $found ? (int) $found[0] : 0;
			}
		}

		if ( ! $post_id || ! get_post( $post_id ) ) {
			return null;
		}

		$forced = $mapping['object_type'] ?? 'auto';
		if ( 'post' === $forced || 'product' === $forced ) {
			$object_type = $forced;
		} else {
			$object_type = ( 'product' === get_post_type( $post_id ) ) ? 'product' : 'post';
		}

		return [ $object_type, $post_id ];
	}

	/**
	 * Post types a lookup may resolve against (enabled ones + products).
	 *
	 * @return string[]
	 */
	private static function candidate_post_types() {
		$types = (array) Settings::get( 'enabled_post_types', [] );
		if ( Settings::get( 'woo_enabled' ) ) {
			$types[] = 'product';
		}
		$types = array_values( array_unique( array_filter( $types ) ) );
		return $types ? $types : [ 'post' ];
	}

	private static function col( array $row, $key ) {
		$key = (string) $key;
		return ( '' !== $key && array_key_exists( $key, $row ) ) ? $row[ $key ] : '';
	}

	private static function rating( $value ) {
		$value = is_numeric( $value ) ? (int) round( (float) $value ) : 0;
		return max( 0, min( 5, $value ) );
	}

	private static function status( array $row, array $mapping ) {
		if ( ! empty( $mapping['status_column'] ) ) {
			$v = strtolower( trim( (string) self::col( $row, $mapping['status_column'] ) ) );
			if ( in_array( $v, ReviewRepository::STATUSES, true ) ) {
				return $v;
			}
		}
		$default = $mapping['default_status'] ?? 'approved';
		return in_array( $default, ReviewRepository::STATUSES, true ) ? $default : 'approved';
	}

	private static function date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return current_time( 'mysql' );
		}
		$ts = strtotime( $value );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : current_time( 'mysql' );
	}
}
