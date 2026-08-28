<?php
namespace Advery\Reviews\Migration;

use Advery\Reviews\Database\Installer;

/**
 * Exports all reviews to a CSV string (for backup / migration to another site).
 * Streaming-free and bounded to a sane row cap; the admin turns the returned
 * string into a download client-side.
 */
class CsvExporter {

	const COLUMNS = [
		'id', 'object_type', 'object_id', 'rating', 'author_name', 'author_email',
		'author_user_id', 'title', 'content', 'status', 'created_at',
		'external_source', 'external_id',
	];

	/**
	 * @param int $max
	 * @return string CSV text.
	 */
	public static function generate( $max = 100000 ) {
		global $wpdb;
		$table = Installer::reviews_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id ASC LIMIT %d", (int) $max ),
			ARRAY_A
		);

		$fh = fopen( 'php://temp', 'r+' );
		fputcsv( $fh, self::COLUMNS );
		foreach ( $rows ?: [] as $row ) {
			$line = [];
			foreach ( self::COLUMNS as $col ) {
				$line[] = isset( $row[ $col ] ) ? $row[ $col ] : '';
			}
			fputcsv( $fh, $line );
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );

		return $csv;
	}
}
