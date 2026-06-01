<?php
/**
 * AuditReportInterface — the contract every audit report implements.
 *
 * Reports are stateless services. They expose:
 *   - identity (id, label, description) for the dashboard
 *   - a chunked scan (cursor in, cursor out, until is_complete)
 *   - an optional bulk action surface (when supports_bulk() is non-empty)
 *
 * The differentiator across the whole Audit subsystem is the "receipts"
 * pattern: every item in an AuditChunk MUST carry a 'why' field
 * describing the provenance the report used to flag it. Competitors
 * surface a count and a checkbox; we surface a paper trail.
 */

namespace CFMediaManager\Audit;

defined( 'ABSPATH' ) || exit;

interface AuditReportInterface {

	/**
	 * Stable machine identifier. Used in transient keys, IgnoredStore
	 * routing, AJAX param values, and the dashboard URL hash. Must be
	 * lower_snake_case and unique across all registered reports.
	 *
	 * Examples: 'orphan_files', 'ghost_attachments', 'unused_attachments'.
	 */
	public function id(): string;

	/**
	 * Short human label shown on the dashboard card header.
	 * Returned translated.
	 */
	public function label(): string;

	/**
	 * One-sentence card subtitle explaining what this report finds.
	 * Returned translated. Example: "Files on disk with no matching
	 * Media Library record."
	 */
	public function description(): string;

	/**
	 * Run one slice of the scan. The cursor is opaque to the orchestrator —
	 * the report defines its own format. A null cursor means "start fresh."
	 *
	 * Implementations MUST:
	 *   - Return AuditChunk::complete(...) for single-shot reports.
	 *   - Return AuditChunk::partial(...) with a next_cursor when more
	 *     work remains, so the orchestrator can resume on a subsequent
	 *     scan_chunk call without losing place.
	 *   - Include a 'why' field on every item in the chunk, describing
	 *     the provenance the report used to flag it.
	 *
	 * The returned chunk's `running_totals` MUST be CUMULATIVE across the
	 * whole scan (not chunk-local). Chunked reports get the previous
	 * chunk's totals via `$prior_totals` so they can compute the new
	 * cumulative value without re-reading state.
	 *
	 * @param ScanContext $ctx          Run-scoped knobs (force, chunk_size, config).
	 * @param string|null $cursor       Opaque resume token, or null for a fresh scan.
	 * @param array       $prior_totals The running_totals from the previous
	 *                                  chunk in this scan. Empty on the first
	 *                                  chunk (cursor === null).
	 */
	public function scan_chunk( ScanContext $ctx, ?string $cursor, array $prior_totals = array() ): AuditChunk;

	/**
	 * Apply a bulk action to a set of items. Returns ActionResult.
	 *
	 * The set of valid $action strings is whatever supports_bulk() returns
	 * (plus the special action 'noop' which the orchestrator may probe).
	 *
	 * Items not handled by this report (wrong type, already gone, locked)
	 * should land in $result->skipped or $result->errors rather than
	 * raising — bulk actions in WP admin UIs are best-effort and the UI
	 * needs the granular outcome to render a useful summary banner.
	 *
	 * @param string                    $action One of supports_bulk().
	 * @param array<int,int|string>     $ids    Item identifiers. Type matches
	 *                                          the IgnoredStore key shape:
	 *                                          ints for attachment-backed
	 *                                          reports, strings for path-
	 *                                          backed reports.
	 * @param array                     $args   Action-specific arguments.
	 */
	public function bulk_action( string $action, array $ids, array $args = array() ): ActionResult;

	/**
	 * The set of bulk-action strings this report accepts.
	 *
	 * Conventional values:
	 *   - 'trash'    — move attachments to trash (never permanent delete)
	 *   - 'restore'  — undo a previous trash within this report's items
	 *   - 'ignore'   — mark item(s) ignored via IgnoredStore
	 *   - 'unignore' — reverse a previous ignore
	 *   - 'delete'   — permanent delete of orphan-file paths (no attachment)
	 *
	 * Returning an empty array means the report is read-only — the UI
	 * hides the bulk-action bar.
	 *
	 * @return array<string>
	 */
	public function supports_bulk(): array;
}
