<?php
/**
 * LibraryCsvExporter — pure logic for the Media Library CSV export: column
 * resolution, query argument construction, header row, and per-attachment
 * data rows.
 *
 * The HTTP plumbing (capability check, nonce, header() emission, streaming,
 * exit) lives in LibraryPage::handle_csv_export(). Keeping the data shaping
 * here makes it unit-testable without sending headers or terminating the
 * process.
 */

namespace CFMediaManager;

use CFShared\Csv\Escaper as CsvEscaper;

defined( 'ABSPATH' ) || exit;

class LibraryCsvExporter {

	/**
	 * Resolve the requested columns down to a valid, ordered list.
	 *
	 * Accepts the raw comma-separated `cols` request value (already
	 * sanitized by the caller). Falls back to the registry defaults when
	 * the parameter is empty or resolves to no valid keys.
	 *
	 * @param string $col_param Comma-separated column keys, or ''.
	 * @return string[]
	 */
	public static function resolve_columns( string $col_param ): array {
		$columns = '' !== $col_param
			? array_map( 'sanitize_key', explode( ',', $col_param ) )
			: LibraryColumnRegistry::defaults();

		$valid   = LibraryColumnRegistry::valid_keys();
		$columns = array_values( array_intersect( $columns, $valid ) );

		if ( empty( $columns ) ) {
			$columns = LibraryColumnRegistry::defaults();
		}

		return $columns;
	}

	/**
	 * Build the WP_Query argument array for the export, applying the
	 * forwarded search / mime / parent filters.
	 *
	 * @param string $search        Search term, or ''.
	 * @param string $mime          MIME group/type, or ''.
	 * @param string $parent_filter 'unattached' or ''.
	 * @return array<string, mixed>
	 */
	public static function build_query_args( string $search, string $mime, string $parent_filter ): array {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		if ( '' !== $mime ) {
			$args['post_mime_type'] = $mime;
		}
		if ( 'unattached' === $parent_filter ) {
			$args['post_parent'] = 0;
		}

		return $args;
	}

	/**
	 * The CSV header row — human-readable column labels in column order.
	 *
	 * @param string[] $columns Ordered column keys.
	 * @return string[]
	 */
	public static function headers( array $columns ): array {
		$flat = LibraryColumnRegistry::flat();
		return array_map(
			static fn( $k ) => $flat[ $k ]['label'] ?? $k,
			$columns
		);
	}

	/**
	 * Build the data rows — one ordered value array per attachment.
	 *
	 * Each cell is run through the CSV formula-injection neutraliser
	 * (CWE-1236): attachment titles, captions, and alt text are user-editable
	 * and would otherwise be evaluated as formulas when the export is opened
	 * in Excel / Sheets / Numbers. Escaping here (rather than at the streaming
	 * call site) keeps the safety guarantee in the unit-tested core.
	 *
	 * @param \WP_Post[] $posts   Attachment posts.
	 * @param string[]   $columns Ordered column keys.
	 * @return array<int, array<int, string>>
	 */
	public static function rows( array $posts, array $columns ): array {
		$rows = array();
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$row    = LibraryAttachmentData::for_post( $post, $columns );
				$rows[] = CsvEscaper::escape_row( array_values( $row ) );
			}
		}
		return $rows;
	}

	/**
	 * The download filename, dated in UTC.
	 */
	public static function filename(): string {
		return 'media-list-' . gmdate( 'Y-m-d' ) . '.csv';
	}
}
