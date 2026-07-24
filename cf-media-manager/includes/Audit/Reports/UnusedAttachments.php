<?php
/**
 * UnusedAttachments — the marquee audit report.
 *
 * Field research on r/Wordpress and the WP support forums identified
 * "Media Cleaner deleted my logo" as the dominant complaint about
 * library-cleanup plugins. The competitive opening is to ship a tool
 * where every flagged item carries the provenance the detector used,
 * and where the detector itself is so thorough that false positives
 * are rare BY CONSTRUCTION.
 *
 * The detector is InUseScanner — already shipped, already covering
 * post_content + featured images + site logo + site icon + Divi +
 * Elementor + Beaver Builder + Bricks + WPBakery + reusable blocks +
 * block templates + widgets + ACF + WooCommerce. This report inverts
 * that scanner's output: any attachment NOT in the in-use set is a
 * candidate.
 *
 * Snapshot strategy:
 *   The InUseScanner's cache may invalidate mid-audit (a post edit
 *   fires the hook). Subsequent chunks would then see a different
 *   in-use set than chunk 1 — silently flagging items that were
 *   actually in use moments earlier. We avoid this by snapshotting the
 *   scan result into a side transient on the first chunk and reading
 *   from that snapshot for every subsequent chunk. The snapshot is
 *   deleted on scan completion.
 *
 * Receipts are the differentiator. Every flagged item shows:
 *   - the exhaustive list of sources we checked
 *   - which builders the InUseScanner detected (and therefore probed)
 *   - the age of the in-use scan relative to this audit
 *
 * The user can read the receipt and trust that "nothing references
 * this" actually means we examined every location it could be
 * referenced from.
 */

namespace CFMediaManager\Audit\Reports;

use CFMediaManager\Audit\ActionResult;
use CFMediaManager\Audit\AuditChunk;
use CFMediaManager\Audit\AuditReportCsvExportable;
use CFMediaManager\Audit\AuditReportInterface;
use CFMediaManager\Audit\IgnoredStore;
use CFMediaManager\Audit\ScanContext;

defined( 'ABSPATH' ) || exit;


final class UnusedAttachments implements AuditReportInterface, AuditReportCsvExportable {

	const ID = 'unused_attachments';

	const BATCH_SIZE = 500;

	/**
	 * Per-audit-scan snapshot of the InUseScanner result. Created on the
	 * first chunk (cursor === null); read by every subsequent chunk;
	 * deleted on completion. Locks the in-use set against mid-scan
	 * invalidation.
	 */
	const SNAPSHOT_TRANSIENT = 'cf_mm_audit_unused_attachments_snapshot';
	const SNAPSHOT_TTL       = 6 * 3600;

	private IgnoredStore $ignored;

	/**
	 * Closure with signature `(bool $force): array` returning the
	 * InUseScanner result. Production: array($in_use_scanner, 'get').
	 * Tests: inject a fixture-returning closure.
	 *
	 * @var callable
	 */
	private $in_use_callback;

	public function __construct( IgnoredStore $ignored, callable $in_use_callback ) {
		$this->ignored         = $ignored;
		$this->in_use_callback = $in_use_callback;
	}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Unused attachments', 'cf-media-manager' );
	}

	public function description(): string {
		return __( 'Media Library entries the in-use scanner did not find referenced anywhere it looked. This is a best-effort scan, not a guarantee. Open each item and verify before deleting; items are sent to Trash (recoverable), never permanently deleted.', 'cf-media-manager' );
	}

	// =====================================================================
	// scan_chunk
	// =====================================================================

	public function scan_chunk( ScanContext $ctx, ?string $cursor, array $prior_totals = array() ): AuditChunk {
		global $wpdb;

		$batch_size = max( 1, min( self::BATCH_SIZE, $ctx->chunk_size ) );
		$after_id   = ( null === $cursor ) ? 0 : (int) $cursor;

		// First chunk: take a snapshot of the InUseScanner result and
		// stash it in a side transient. Subsequent chunks read the snapshot
		// to avoid the mid-scan invalidation drift described in the class
		// docblock.
		$snapshot = $this->load_or_create_snapshot( $cursor, $ctx->force );

		if ( ! isset( $wpdb ) ) {
			$this->maybe_clear_snapshot( true );
			return AuditChunk::complete( array(), $prior_totals );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- audit reports walk attachments by ID cursor for chunked pacing; WP_Query would lose cursor control. No caching by design — each audit chunk reads fresh.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_parent, post_date, post_mime_type
				   FROM {$wpdb->posts}
				  WHERE post_type = 'attachment'
				    AND post_status = 'inherit'
				    AND ID > %d
				  ORDER BY ID ASC
				  LIMIT %d",
				$after_id,
				$batch_size
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			$this->maybe_clear_snapshot( true );
			return AuditChunk::complete( array(), $prior_totals );
		}

		$in_use_set    = $snapshot['ids_set'];
		$checked_list  = self::checked_sources();
		$builders_seen = $snapshot['builders'] ?? array();
		$scan_age_sec  = isset( $snapshot['scanned_at'] ) ? max( 0, time() - (int) $snapshot['scanned_at'] ) : null;

		$items   = array();
		$flagged = 0;
		foreach ( $rows as $row ) {
			$id = (int) $row['ID'];

			if ( isset( $in_use_set[ $id ] ) ) {
				continue;  // referenced somewhere — not unused
			}
			if ( $this->ignored->is_ignored( self::ID, $id ) ) {
				continue;
			}

			$items[] = $this->build_receipt( $id, $row, $checked_list, $builders_seen, $scan_age_sec );
			$flagged++;
		}

		$last_id  = (int) end( $rows )['ID'];
		$has_more = count( $rows ) >= $batch_size;

		$totals = array(
			AuditChunk::TOTAL_FLAGGED       => (int) ( $prior_totals[ AuditChunk::TOTAL_FLAGGED ] ?? 0 ) + $flagged,
			AuditChunk::TOTAL_SCANNED       => (int) ( $prior_totals[ AuditChunk::TOTAL_SCANNED ] ?? 0 ) + count( $rows ),
			AuditChunk::TOTAL_SAVINGS_BYTES => (int) ( $prior_totals[ AuditChunk::TOTAL_SAVINGS_BYTES ] ?? 0 )
				+ array_sum( array_column( $items, 'size_bytes' ) ),
		);

		if ( $has_more ) {
			return AuditChunk::partial( $items, (string) $last_id, $totals );
		}

		// Final chunk: clear the snapshot before returning so the next scan
		// can take a fresh one.
		$this->maybe_clear_snapshot( true );
		return AuditChunk::complete( $items, $totals );
	}

	/**
	 * Load the per-scan snapshot from the side transient, or create one on
	 * the first chunk. Returns a normalized shape with `ids_set` (assoc
	 * array keyed by attachment id for O(1) lookup), plus the raw
	 * `scanned_at` and `builders` fields from the underlying scanner.
	 *
	 * @return array{ids_set:array<int,true>,scanned_at:?int,builders:array}
	 */
	private function load_or_create_snapshot( ?string $cursor, bool $force ): array {
		if ( null !== $cursor ) {
			$cached = get_transient( self::SNAPSHOT_TRANSIENT );
			if ( is_array( $cached ) && isset( $cached['ids_set'] ) ) {
				return $cached;
			}
			// Snapshot evaporated mid-scan (TTL expired, object cache
			// flush). Fall through and rebuild — preferable to crashing
			// the chunk; the drift this risks is bounded by the rest of
			// the scan window.
		}

		$result    = ( $this->in_use_callback )( $force );
		$ids       = is_array( $result['ids'] ?? null ) ? array_map( 'intval', $result['ids'] ) : array();
		$snapshot  = array(
			'ids_set'    => array_fill_keys( $ids, true ),
			'scanned_at' => isset( $result['scanned_at'] ) ? (int) $result['scanned_at'] : null,
			'builders'   => is_array( $result['builders'] ?? null ) ? $result['builders'] : array(),
		);
		set_transient( self::SNAPSHOT_TRANSIENT, $snapshot, self::SNAPSHOT_TTL );
		return $snapshot;
	}

	private function maybe_clear_snapshot( bool $always = false ): void {
		if ( $always ) {
			delete_transient( self::SNAPSHOT_TRANSIENT );
		}
	}

	// =====================================================================
	// Receipt
	// =====================================================================

	/**
	 * The exhaustive list of locations InUseScanner probes. Surfacing this
	 * verbatim is the receipts-pattern payoff — the user reads the list
	 * and sees that we covered every plausible source before flagging the
	 * item as unused.
	 *
	 * Mirror the source keys InUseScanner's `by_source` array uses so a
	 * future change to that scanner's coverage is detectable by a
	 * mismatch in the receipt.
	 *
	 * @return string[]
	 */
	public static function checked_sources(): array {
		return array(
			'post_content',
			'featured',
			'site_logo',
			'site_icon',
			'divi',
			'elementor',
			'beaver',
			'bricks',
			'wpbakery',
			'reusable_blocks',
			'block_templates',
			'widgets',
			'acf',
			'woocommerce',
		);
	}

	private function build_receipt(
		int $id,
		array $row,
		array $checked,
		array $builders_active,
		?int $scan_age_seconds
	): array {
		$size_bytes = $this->attachment_size_bytes( $id );

		return array(
			'id'                  => $id,
			'title'               => (string) $row['post_title'],
			'mime'                => (string) $row['post_mime_type'],
			'date_uploaded'       => (string) $row['post_date'],
			'attached_to_post_id' => (int) $row['post_parent'],
			'size_bytes'          => $size_bytes,
			'size_human'          => $size_bytes > 0 && function_exists( 'size_format' )
				? size_format( $size_bytes )
				: '',
			'why'                 => array(
				'reason'            => 'not_referenced_anywhere',
				'checked'           => $checked,
				'builders_active'   => $builders_active,
				'scan_age_seconds'  => $scan_age_seconds,
			),
		);
	}

	/**
	 * Read file size from `_wp_attachment_metadata['filesize']` when WP
	 * has cached it; fall back to `filesize()` on the resolved file when
	 * not. Avoids per-row disk hits on the common case (modern WP writes
	 * filesize into metadata at upload time).
	 */
	private function attachment_size_bytes( int $id ): int {
		$meta = get_post_meta( $id, '_wp_attachment_metadata', true );
		if ( is_array( $meta ) && isset( $meta['filesize'] ) && (int) $meta['filesize'] > 0 ) {
			return (int) $meta['filesize'];
		}

		$path = function_exists( 'get_attached_file' ) ? get_attached_file( $id ) : '';
		if ( ! $path ) {
			return 0;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$bytes = @filesize( $path );
		return is_int( $bytes ) ? $bytes : 0;
	}

	// =====================================================================
	// Bulk actions
	// =====================================================================

	public function bulk_action( string $action, array $ids, array $args = array() ): ActionResult {
		switch ( $action ) {
			case 'trash':
				return $this->bulk_trash( $ids );
			case 'ignore':
				return $this->bulk_ignore( $ids );
			case 'unignore':
				return $this->bulk_unignore( $ids );
			default:
				return ActionResult::failed(
					/* translators: %s: requested bulk-action verb */
					sprintf( __( 'Unknown action: %s', 'cf-media-manager' ), $action )
				);
		}
	}

	private function bulk_trash( array $ids ): ActionResult {
		$processed = 0;
		$skipped   = 0;
		$errors    = array();

		foreach ( $ids as $raw_id ) {
			$id = (int) $raw_id;
			if ( $id <= 0 ) {
				$errors[ $raw_id ] = __( 'Invalid attachment id.', 'cf-media-manager' );
				continue;
			}
			// Safety rail: force=false → goes to trash, recoverable.
			$result = wp_delete_attachment( $id, false );
			if ( false === $result || null === $result ) {
				$errors[ $id ] = __( 'Could not trash attachment.', 'cf-media-manager' );
				continue;
			}
			$processed++;
		}

		if ( empty( $errors ) ) {
			return ActionResult::ok( $processed, $skipped );
		}
		return ActionResult::partial( $processed, $skipped, $errors );
	}

	private function bulk_ignore( array $ids ): ActionResult {
		$processed = 0;
		foreach ( $ids as $raw_id ) {
			$id = (int) $raw_id;
			if ( $id <= 0 ) {
				continue;
			}
			$this->ignored->ignore( self::ID, $id );
			$processed++;
		}
		return ActionResult::ok( $processed );
	}

	private function bulk_unignore( array $ids ): ActionResult {
		$processed = 0;
		foreach ( $ids as $raw_id ) {
			$id = (int) $raw_id;
			if ( $id <= 0 ) {
				continue;
			}
			$this->ignored->unignore( self::ID, $id );
			$processed++;
		}
		return ActionResult::ok( $processed );
	}

	public function supports_bulk(): array {
		return array( 'trash', 'ignore', 'unignore' );
	}

	// =====================================================================
	// CSV export
	// =====================================================================

	public function csv_columns(): array {
		return array(
			array( 'key' => 'id',                  'label' => __( 'Attachment ID', 'cf-media-manager' ) ),
			array( 'key' => 'title',               'label' => __( 'Title', 'cf-media-manager' ) ),
			array( 'key' => 'mime',                'label' => __( 'MIME', 'cf-media-manager' ) ),
			array( 'key' => 'date_uploaded',       'label' => __( 'Date Uploaded', 'cf-media-manager' ) ),
			array( 'key' => 'attached_to_post_id', 'label' => __( 'Attached To Post ID', 'cf-media-manager' ) ),
			array( 'key' => 'size_bytes',          'label' => __( 'Size (bytes)', 'cf-media-manager' ) ),
			array( 'key' => 'size_human',          'label' => __( 'Size', 'cf-media-manager' ) ),
			array( 'key' => 'scan_age_seconds',    'label' => __( 'In-Use Scan Age (s)', 'cf-media-manager' ) ),
		);
	}

	public function csv_row( array $item ): array {
		return array(
			(string) ( $item['id'] ?? '' ),
			(string) ( $item['title'] ?? '' ),
			(string) ( $item['mime'] ?? '' ),
			(string) ( $item['date_uploaded'] ?? '' ),
			(string) ( $item['attached_to_post_id'] ?? '' ),
			(string) ( $item['size_bytes'] ?? '' ),
			(string) ( $item['size_human'] ?? '' ),
			(string) ( $item['why']['scan_age_seconds'] ?? '' ),
		);
	}
}
