<?php
/**
 * AuditAjax — five AJAX endpoints that back the Audit tab UI.
 *
 *   - dashboard:     read all-cards summary
 *   - scan_chunk:    advance one chunk of a report's scan (idempotent under
 *                    concurrent calls via AuditRunner's lock)
 *   - scan_cancel:   abort an in-progress scan
 *   - detail:        paginated results for one report's detail view
 *   - bulk:          apply a bulk action to a set of items
 *
 * Each handler's first line is `$this->authorize()` (read endpoints) or
 * `$this->authorize_network()` (bulk action — destructive, lifts the cap
 * required on multisite to match the rest of the destructive-action set
 * in Ajax.php).
 *
 * Nonce: the existing Plugin::NONCE_ACTION is shared across the plugin's
 * full AJAX surface — keeps the JS-side nonce constant simple.
 *
 * No CSV export here yet — that lives behind its own per-report column
 * shape and lands when the first report wants to expose one.
 */

namespace CFMediaManager;

use CFMediaManager\Audit\AuditReportCsvExportable;
use CFMediaManager\Audit\AuditRunner;
use CFShared\Csv\Escaper as CsvEscaper;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- authorize() runs check_ajax_referer for every endpoint.

final class AuditAjax {

	private AuditRunner $runner;

	/**
	 * Closure returning the count of image attachments missing alt text.
	 * Injectable via constructor so tests can stub the count without
	 * constructing a real AltTextManager (which depends on InUseScanner
	 * and the full WP runtime).
	 *
	 * @var callable|null
	 */
	private $missing_alt_count;

	public function __construct( AuditRunner $runner, ?callable $missing_alt_count = null ) {
		$this->runner            = $runner;
		$this->missing_alt_count = $missing_alt_count;
	}

	public function register_hooks(): void {
		$endpoints = array(
			'audit_dashboard'   => 'dashboard',
			'audit_scan_chunk'  => 'scan_chunk',
			'audit_scan_cancel' => 'scan_cancel',
			'audit_detail'      => 'detail',
			'audit_bulk'        => 'bulk',
			'audit_export_csv'  => 'export_csv',
		);
		foreach ( $endpoints as $slug => $method ) {
			add_action( 'wp_ajax_' . Plugin::AJAX_PREFIX . $slug, array( $this, $method ) );
		}
	}

	// =====================================================================
	// Authorization gates
	// =====================================================================

	private function authorize(): void {
		Security::authorize_ajax();
	}

	private function authorize_network(): void {
		Security::authorize_ajax_network( Plugin::NONCE_ACTION );
	}

	// =====================================================================
	// Endpoints
	// =====================================================================

	/**
	 * Read all-cards summary for the dashboard grid. Cheap — reads one
	 * tiny transient per registered report.
	 */
	public function dashboard(): void {
		$this->authorize();
		nocache_headers();

		wp_send_json_success(
			array(
				'reports' => $this->runner->dashboard(),
				'links'   => $this->build_link_cards(),
				'now'     => time(),
			)
		);
	}

	/**
	 * Dashboard "link cards" — health-signal indicators that aren't real
	 * audit reports (no scan, no detail view), they just count something
	 * and CTA somewhere else in the plugin. Currently:
	 *
	 *   - Missing alt text → deep-links to the Accessibility tab where
	 *     the bulk editor already lives.
	 *
	 * Empty array when no link cards have count data to surface.
	 *
	 * @return array<int,array{
	 *     id:string,
	 *     label:string,
	 *     description:string,
	 *     count:int,
	 *     cta_label:string,
	 *     cta_target:string
	 * }>
	 */
	private function build_link_cards(): array {
		$cards = array();

		if ( null !== $this->missing_alt_count ) {
			$cards[] = array(
				'id'          => 'missing_alt_text',
				'label'       => __( 'Missing alt text', 'cf-media-manager' ),
				'description' => __( 'Image attachments without an alt attribute or decorative flag.', 'cf-media-manager' ),
				'count'       => (int) ( $this->missing_alt_count )(),
				'cta_label'   => __( 'Manage in Accessibility', 'cf-media-manager' ),
				// Manager's tab system reads data-tab attributes; the JS
				// switches to this tab when the CTA is clicked.
				'cta_target'  => 'accessibility',
			);
		}

		return $cards;
	}

	/**
	 * Advance one chunk of a report's scan. Starts a scan if the report
	 * is idle. Returns the post-call state so the JS poll loop can render
	 * progress and decide whether to keep polling.
	 *
	 * Input:
	 *   - report    (string, required) — report id from Plugin::AJAX_PREFIX route
	 *   - force     (int, optional, default 0) — when 1, reset before scanning
	 *   - min_bytes / min_pixels_longest_side (int, optional) — OversizedOriginals
	 *                thresholds; ignored by reports that don't read them
	 *
	 * The config assembled here is persisted on the scan's state by
	 * AuditRunner::start() and threaded into every chunk's ScanContext. It
	 * carries the `force` flag (so a "force rescan" actually refreshes the
	 * InUseScanner transient the Unused Attachments report reads) and any
	 * report-specific thresholds (so OversizedOriginals' min_bytes / min_pixels
	 * are reachable instead of always falling back to the 2MB / 2560 defaults).
	 *
	 * Response shape: { phase, cursor, running_totals, chunks_done, is_locked,
	 *                   error, scanned_at?, completed_at? }
	 */
	public function scan_chunk(): void {
		$this->authorize();

		$report_id = Request::post_key( 'report' );
		$force     = (bool) Request::post_int( 'force', 0 );

		if ( '' === $report_id || ! $this->runner->get( $report_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown report.', 'cf-media-manager' ) ), 400 );
		}

		// Start (or resume) the scan, then run one chunk. The config is only
		// applied when a fresh scan begins (idle / failed / force); a resume
		// keeps the config captured at the start of the run.
		$state = $this->runner->state( $report_id );
		$reset = $force || AuditRunner::PHASE_IDLE === $state['phase'] || AuditRunner::PHASE_FAILED === $state['phase'];
		if ( $reset ) {
			$this->runner->start( $report_id, $this->build_scan_config( $force ), $force || AuditRunner::PHASE_FAILED === $state['phase'] );
		}
		$state = $this->runner->run_chunk( $report_id );

		wp_send_json_success( $state );
	}

	/**
	 * Build the per-run ScanContext config from the request.
	 *
	 * Always carries `force`. Report-specific thresholds are included only when
	 * the caller supplied a positive value, so an omitted field leaves the
	 * report's own default in place. The keys mirror
	 * OversizedOriginals::CONFIG_MIN_BYTES / CONFIG_MIN_LONGEST_SIDE; reports
	 * that don't read them simply ignore the extra keys.
	 *
	 * @param bool $force Whether this is a forced rescan.
	 * @return array<string,mixed>
	 */
	private function build_scan_config( bool $force ): array {
		$config = array( 'force' => $force );

		$min_bytes = Request::post_int( 'min_bytes', 0 );
		if ( $min_bytes > 0 ) {
			$config['min_bytes'] = $min_bytes;
		}
		$min_pixels = Request::post_int( 'min_pixels_longest_side', 0 );
		if ( $min_pixels > 0 ) {
			$config['min_pixels_longest_side'] = $min_pixels;
		}

		return $config;
	}

	/**
	 * Abort an in-progress scan. State moves to PHASE_FAILED with
	 * error='cancelled' — the UI can distinguish user cancel from a
	 * caught exception via that string.
	 */
	public function scan_cancel(): void {
		$this->authorize();

		$report_id = Request::post_key( 'report' );
		if ( '' === $report_id || ! $this->runner->get( $report_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown report.', 'cf-media-manager' ) ), 400 );
		}

		wp_send_json_success( $this->runner->cancel( $report_id ) );
	}

	/**
	 * Paginated items for a report's detail view.
	 *
	 * Input:
	 *   - report   (string, required)
	 *   - page     (int, optional, default 1)
	 *   - per_page (int, optional, default 50, clamped to [1, 200])
	 *
	 * Response: { items, page, per_page, total, pages, totals, scanned_at,
	 *             duration_ms, is_stale }  — or `{ items: [] }` and a hint
	 * when no scan has been run yet.
	 */
	public function detail(): void {
		$this->authorize();

		$report_id = Request::post_key( 'report' );
		$page      = max( 1, Request::post_int( 'page', 1 ) );
		$per_page  = max( 1, min( 200, Request::post_int( 'per_page', 50 ) ) );

		if ( '' === $report_id || ! $this->runner->get( $report_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown report.', 'cf-media-manager' ) ), 400 );
		}

		$results = $this->runner->results( $report_id, $page, $per_page );
		if ( null === $results ) {
			wp_send_json_success(
				array(
					'items'    => array(),
					'total'    => 0,
					'page'     => 1,
					'per_page' => $per_page,
					'pages'    => 0,
					'totals'   => array(),
					'is_stale' => false,
					'no_scan'  => true,
				)
			);
		}

		$report                    = $this->runner->get( $report_id );
		$results['supports_bulk']  = $report ? $report->supports_bulk() : array();
		$results['csv_exportable'] = $report instanceof AuditReportCsvExportable;

		wp_send_json_success( $results );
	}

	/**
	 * Apply a bulk action to a set of items in one report.
	 *
	 * Input:
	 *   - report (string, required)
	 *   - action (string, required) — one of the report's supports_bulk()
	 *   - ids    (mixed[], required) — int or string IDs depending on the
	 *                                  report's key shape. JS sends as
	 *                                  ids[] form-encoded.
	 *
	 * Response: ActionResult as { success, processed, skipped, errors, message }
	 */
	public function bulk(): void {
		$this->authorize_network();

		$report_id = Request::post_key( 'report' );
		$action    = Request::post_key( 'action_verb' );  // 'action' would collide with the WP AJAX action param
		// Sanitization happens immediately below: array_map → string cast,
		// then the report's bulk_action handler intval/normalizes per item.
		// authorize_network() above ran check_ajax_referer for the nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_ids = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array();

		if ( '' === $report_id || ! $this->runner->get( $report_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown report.', 'cf-media-manager' ) ), 400 );
		}
		if ( '' === $action ) {
			wp_send_json_error( array( 'message' => __( 'Missing action.', 'cf-media-manager' ) ), 400 );
		}
		if ( ! is_array( $raw_ids ) || empty( $raw_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No items selected.', 'cf-media-manager' ) ), 400 );
		}

		// IDs from the form are strings; the report converts to int|string
		// internally. Pass through raw (sanitized to string) — the
		// per-report bulk handlers normalize.
		$ids = array_map(
			static fn( $v ) => is_scalar( $v ) ? (string) $v : '',
			array_values( $raw_ids )
		);
		$ids = array_filter( $ids, static fn( $v ) => '' !== $v );

		// Cap defensively to avoid a 100K-item request taking down the
		// request worker. The UI never sends more than a page (200).
		if ( count( $ids ) > 500 ) {
			$ids = array_slice( $ids, 0, 500 );
		}

		// Coerce numeric-looking strings to int — attachment-backed
		// reports compare with strict int|string semantics inside
		// IgnoredStore. A path will look non-numeric; an attachment id
		// will look numeric. The is_numeric branch keeps both shapes
		// flowing through the same array.
		$ids = array_map(
			static fn( $v ) => is_numeric( $v ) ? (int) $v : $v,
			$ids
		);

		$result = $this->runner->bulk( $report_id, $action, $ids );

		wp_send_json_success(
			array(
				'success'   => $result->success,
				'processed' => $result->processed,
				'skipped'   => $result->skipped,
				'errors'    => $result->errors,
				'message'   => $result->message,
			)
		);
	}

	/**
	 * Stream a report's cached results as CSV. GET-friendly so the
	 * browser handles the file save dialog (window.location.href triggers
	 * a download, where a fetch() would have to manually save the blob).
	 *
	 * Auth model mirrors the List View's CSV export — current_user_can
	 * 'manage_options' + nonce. Reads results from AuditRunner; no DB
	 * writes, no state mutation.
	 *
	 * Output: text/csv with Content-Disposition: attachment so most
	 * browsers prompt to save. Streams via fputcsv on php://output to
	 * avoid loading the full result set into memory twice.
	 */
	public function export_csv(): void {
		$this->authorize();

		$report_id = Request::post_key( 'report' );
		if ( '' === $report_id ) {
			// Endpoint accepts GET (download links). Fall through to a
			// $_GET-aware accessor since post_key only reads $_POST.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- authorize() ran check_ajax_referer.
			$report_id = isset( $_GET['report'] ) ? sanitize_key( wp_unslash( (string) $_GET['report'] ) ) : '';
		}

		$report = '' !== $report_id ? $this->runner->get( $report_id ) : null;
		if ( ! ( $report instanceof AuditReportCsvExportable ) ) {
			wp_send_json_error( array( 'message' => __( 'Report does not support CSV export.', 'cf-media-manager' ) ), 400 );
		}

		$results = $this->runner->results( $report_id, 1, AuditRunner::ITEMS_HARD_CAP );
		if ( null === $results ) {
			wp_send_json_error( array( 'message' => __( 'No cached results — run a scan first.', 'cf-media-manager' ) ), 400 );
		}

		$filename = sprintf(
			'cf-audit-%s-%s.csv',
			$report_id,
			gmdate( 'Y-m-d' )
		);

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $filename ) . '"' );
		header( 'Pragma: no-cache' );
		header( 'X-Content-Type-Options: nosniff' );

		// php://output streaming: WP_Filesystem cannot stream to the HTTP
		// response body. fputcsv() is the canonical CSV writer; no WP
		// alternative exists for this code path.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out     = fopen( 'php://output', 'w' );
		$columns = $report->csv_columns();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		fputcsv( $out, array_map( static fn( $c ) => (string) ( $c['label'] ?? $c['key'] ?? '' ), $columns ) );
		foreach ( $results['items'] as $item ) {
			// Neutralise CSV formula injection (CWE-1236) — see LibraryPage.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
			fputcsv( $out, CsvEscaper::escape_row( $report->csv_row( is_array( $item ) ? $item : array() ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );

		// Mirror LibraryPage's pattern: exit so WP doesn't append an
		// admin-ajax success/failure JSON body to the CSV.
		exit;
	}
}

// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
