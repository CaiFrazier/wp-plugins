<?php

namespace BulkMetaEditor;

defined( 'ABSPATH' ) || exit;

use CFShared\Csv\Escaper as CsvEscaper;
use CFShared\Logger as SharedLogger;

class CsvExporter {

	private PostDataService $post_data;
	private SharedLogger $logger;

	public function __construct( PostDataService $post_data, SharedLogger $logger ) {
		$this->post_data = $post_data;
		$this->logger    = $logger;
	}

	/**
	 * Stream a CSV download for the given post IDs and visible columns.
	 */
	public function stream( array $ids, array $columns, string $post_type ): void {
		$started        = microtime( true );
		$post_type_safe = $post_type ? sanitize_key( $post_type ) : 'all';
		$date           = gmdate( 'Y-m-d' );
		// sanitize_file_name() is defence-in-depth: $post_type_safe already
		// passed sanitize_key() (alphanumeric + underscore + hyphen) and the
		// date is gmdate-formatted, so no attacker-controlled chars can reach
		// this point today. The extra pass guards against future callers
		// composing the filename from looser sources without re-auditing the
		// Content-Disposition emit.
		$filename       = sanitize_file_name( "bulk-meta-export-{$post_type_safe}-{$date}.csv" );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		// nosniff prevents the browser from re-interpreting the response as
		// HTML/JS even if a misconfigured proxy strips Content-Type. Combined
		// with the formula-injection escape on every cell, this closes the
		// remaining "open in browser instead of saving" foot-gun.
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$rows = $this->post_data->get_rows( $ids );

		// $handle here is the PHP output stream (php://output), not a real
		// file — fputcsv/fwrite/fclose against it are how WP streams a CSV
		// download to the client. WP_Filesystem is for actual file IO and
		// doesn't apply. Annotations cover the file-system rule that doesn't
		// distinguish output streams from disk writes.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( 'php://output', 'w' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $handle, "\xEF\xBB\xBF" );

		if ( empty( $rows ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );
			$this->logger->warn(
				'export',
				'no rows to export',
				[
					'ids_count' => count( $ids ),
				]
			);
			return;
		}

		$columns = array_values( array_filter( array_map( 'sanitize_text_field', $columns ) ) );
		if ( empty( $columns ) ) {
			$columns = array_keys( $rows[0] );
		}

		// Column headers come from sanitize_text_field above and from our own
		// $rows[0] keys (controlled set), so they don't carry attacker content.
		// But fingers slip — pass them through the escaper for defence-in-depth.
		fputcsv( $handle, CsvEscaper::escape_row( $columns ) );

		$bytes = 0;
		foreach ( $rows as $row ) {
			$line = [];
			foreach ( $columns as $col ) {
				// Every cell value flows through the CSV-injection escaper.
				// Any value whose first char is in [= + - @ tab CR] gets a
				// leading single-quote so spreadsheets render it as text
				// instead of evaluating it as a formula (CWE-1236).
				$line[] = CsvEscaper::escape_cell( $row[ $col ] ?? '' );
			}
			fputcsv( $handle, $line );
			$bytes += array_sum( array_map( 'strlen', $line ) );
		}

		// php://output stream — see fopen() comment above.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		$this->logger->info(
			'export',
			'csv stream complete',
			[
				'rows'         => count( $rows ),
				'columns'      => count( $columns ),
				'approx_bytes' => $bytes,
				'filename'     => $filename,
				'duration_ms'  => round( ( microtime( true ) - $started ) * 1000, 2 ),
			]
		);
	}
}
