<?php
/**
 * AuditChunk — one slice of a scan run, returned by AuditReportInterface::scan_chunk().
 *
 * Heavy reports (orphan-file walks, duplicate-hash workers) stream chunks
 * through Queue.php; light reports return everything in chunk 1 with
 * is_complete=true. The dashboard card polls until is_complete, then flips
 * to the "result ready" state.
 *
 * Chunks are value objects — no behavior beyond shape and a couple of
 * named constants for common running-total keys, so reports don't have to
 * memorize string conventions.
 */

namespace CFMediaManager\Audit;

defined( 'ABSPATH' ) || exit;

final class AuditChunk {

	// ---------------------------------------------------------------------
	// Conventional keys reports SHOULD use in $running_totals so the
	// dashboard renderer can read them without a per-report adapter.
	// Reports MAY add their own keys alongside these for niche stats.
	// ---------------------------------------------------------------------
	const TOTAL_FLAGGED       = 'flagged';        // int — items the user could act on
	const TOTAL_SCANNED       = 'scanned';        // int — items examined so far
	const TOTAL_SAVINGS_BYTES = 'savings_bytes';  // int — best-effort estimate of recoverable disk

	/**
	 * @param array       $items          This chunk's flagged items, each a
	 *                                    free-form array shaped by the report.
	 *                                    Must include 'why' provenance (the
	 *                                    receipts pattern).
	 * @param string|null $next_cursor    Opaque string the report uses to
	 *                                    resume the next scan_chunk call.
	 *                                    Null when the scan is complete.
	 * @param bool        $is_complete    True iff the scan has finished. The
	 *                                    next_cursor MUST be null when this
	 *                                    is true.
	 * @param array       $running_totals Cumulative across all chunks of this
	 *                                    scan. See the TOTAL_* constants for
	 *                                    conventional keys.
	 */
	public function __construct(
		public readonly array $items,
		public readonly ?string $next_cursor,
		public readonly bool $is_complete,
		public readonly array $running_totals = array()
	) {}

	/**
	 * Convenience constructor for a single-chunk scan that finishes in one
	 * call. Used by light reports (Ghost Attachments, Missing Alt count)
	 * that don't need cursor pacing.
	 */
	public static function complete( array $items, array $running_totals = array() ): self {
		return new self( $items, null, true, $running_totals );
	}

	/**
	 * Convenience constructor for an intermediate chunk in a multi-call scan.
	 */
	public static function partial( array $items, string $next_cursor, array $running_totals = array() ): self {
		return new self( $items, $next_cursor, false, $running_totals );
	}
}
