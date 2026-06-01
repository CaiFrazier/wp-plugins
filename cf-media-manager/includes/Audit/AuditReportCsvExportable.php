<?php
/**
 * AuditReportCsvExportable — optional companion interface for
 * AuditReportInterface implementations that want to expose a CSV
 * download of their results.
 *
 * Reports opt in by implementing this interface. The
 * `audit_export_csv` AJAX endpoint checks `instanceof` before streaming;
 * the detail-view payload sets `csv_exportable: true` so the JS can
 * surface the Export CSV button only for reports that support it.
 *
 * Column shape is intentionally simple — { key, label } — so the
 * endpoint can write a header row from labels and stream values keyed
 * by the report's own item structure. Reports with nested item shapes
 * (e.g., DuplicateOriginals groups) flatten in csv_row() so the CSV
 * stays a flat-table format.
 */

namespace CFMediaManager\Audit;

defined( 'ABSPATH' ) || exit;

interface AuditReportCsvExportable {

	/**
	 * Ordered list of column descriptors. Each entry:
	 *   - key   (string) — short machine name; not emitted in CSV
	 *   - label (string) — human-readable header (already translated)
	 *
	 * @return array<int,array{key:string,label:string}>
	 */
	public function csv_columns(): array;

	/**
	 * Convert one report item into its CSV row, in column order. Values
	 * MUST be scalar (string|int|float|bool). Arrays/objects should be
	 * flattened or joined by the report before return — the endpoint
	 * passes them straight to fputcsv().
	 *
	 * @param array $item One item from the report's results.
	 * @return array<int,scalar>
	 */
	public function csv_row( array $item ): array;
}
