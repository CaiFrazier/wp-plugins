<?php
/**
 * DuplicateOriginals — exact-content duplicate detection across the Media
 * Library.
 *
 * The classic complaint: a client uploads the same logo eight times. Each
 * upload makes WordPress write a fresh `name-1.jpg`, `name-2.jpg`, …
 * variant; the result is a media library bloated with byte-identical
 * copies that nothing knows are duplicates.
 *
 * This report computes a SHA-256 hash of each attachment's source file
 * and groups by hash. Any group with ≥2 members is a duplicate set.
 * Receipts identify a "primary" copy to keep (in-use first, oldest
 * tie-break) and the other copies as candidates for trashing.
 *
 * One row per duplicate GROUP, not per copy — a 3-way duplicate shows
 * as a single audit row that the user can act on once.
 *
 * Two-phase scan:
 *   1. gather — chunk through attachments, hash each file, accumulate
 *      `hash → [id, id, …]` into a side transient
 *   2. emit — once gathering is exhausted, iterate the groups with ≥2
 *      members and emit one item per group, with usage context pulled
 *      from the InUseScanner result snapshot
 *
 * Cursor encoding:
 *   - first chunk:           null
 *   - subsequent gather:     "gather:<last_attachment_id>"
 *   - emit:                  "emit:<group_offset>"
 *
 * Bulk action ID types are deliberately mixed by verb — same dispatch
 * pattern as the existing reports, just with different shapes:
 *   - trash   → int attachment IDs (JS sends the non-primary copies of
 *               each selected group)
 *   - ignore / unignore → string group hashes (the hash is the IgnoredStore
 *               key, so future scans skip whole groups)
 *
 * Out of scope for P1:
 *   - Perceptual hashing (cropped/resized near-duplicates). The exact-hash
 *     algorithm catches the "uploaded the same file twice" case, which is
 *     the actual gripe in the field research. Perceptual is P3.
 *   - Per-attachment reverse usage lookup ("attachment 12 is used as the
 *     featured image of post 5"). InUseScanner gives a binary is-in-use;
 *     deeper attribution can be added once a UI for it exists.
 */

namespace CFMediaManager\Audit\Reports;

use CFMediaManager\Audit\ActionResult;
use CFMediaManager\Audit\AuditChunk;
use CFMediaManager\Audit\AuditReportCsvExportable;
use CFMediaManager\Audit\AuditReportInterface;
use CFMediaManager\Audit\IgnoredStore;
use CFMediaManager\Audit\ScanContext;

defined( 'ABSPATH' ) || exit;


final class DuplicateOriginals implements AuditReportInterface, AuditReportCsvExportable {

	const ID = 'duplicate_originals';

	/**
	 * Attachments hashed per gather chunk. Hashing is disk-bound, so the
	 * default is smaller than the read-only attachment-row reports.
	 */
	const GATHER_BATCH = 100;

	/**
	 * Duplicate groups emitted per emit chunk. Cheap; can be larger.
	 */
	const EMIT_BATCH = 100;

	/**
	 * Files larger than this are skipped during hashing — a 1 GB video
	 * upload doesn't need a SHA-256 pass for the duplicate-image use
	 * case, and would otherwise tank the chunk budget. Skipped files
	 * are counted in `scanned` but never appear as duplicates.
	 */
	const MAX_HASH_BYTES = 100 * 1024 * 1024;

	const HASH_ALGO = 'sha256';

	const STATE_TRANSIENT = 'cf_mm_audit_duplicate_originals_state';
	const STATE_TTL       = 6 * 3600;

	private IgnoredStore $ignored;

	/** @var callable */
	private $in_use_callback;

	public function __construct( IgnoredStore $ignored, callable $in_use_callback ) {
		$this->ignored         = $ignored;
		$this->in_use_callback = $in_use_callback;
	}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Duplicate originals', 'cf-media-manager' );
	}

	public function description(): string {
		return __( 'Attachments whose source files are byte-identical (SHA-256). Receipts identify the in-use copy to keep.', 'cf-media-manager' );
	}

	// =====================================================================
	// scan_chunk — dispatches into gather / emit phase
	// =====================================================================

	public function scan_chunk( ScanContext $ctx, ?string $cursor, array $prior_totals = array() ): AuditChunk {
		$parsed = $this->parse_cursor( $cursor );

		if ( 'gather' === $parsed['phase'] ) {
			return $this->run_gather_chunk( $ctx, (int) $parsed['after_id'], $prior_totals );
		}
		if ( 'emit' === $parsed['phase'] ) {
			return $this->run_emit_chunk( $ctx, (int) $parsed['offset'], $prior_totals );
		}

		// Defensive fallback — unrecognized cursor restarts from scratch.
		return $this->run_gather_chunk( $ctx, 0, $prior_totals );
	}

	private function parse_cursor( ?string $cursor ): array {
		if ( null === $cursor ) {
			return array(
				'phase'    => 'gather',
				'after_id' => 0,
			);
		}
		if ( preg_match( '/^gather:(\d+)$/', $cursor, $m ) ) {
			return array(
				'phase'    => 'gather',
				'after_id' => (int) $m[1],
			);
		}
		if ( preg_match( '/^emit:(\d+)$/', $cursor, $m ) ) {
			return array(
				'phase'  => 'emit',
				'offset' => (int) $m[1],
			);
		}
		return array(
			'phase'    => 'gather',
			'after_id' => 0,
		);
	}

	// =====================================================================
	// Gather phase: hash a batch of attachments, accumulate into transient.
	// =====================================================================

	private function run_gather_chunk( ScanContext $ctx, int $after_id, array $prior_totals ): AuditChunk {
		global $wpdb;

		$batch_size = max( 1, min( self::GATHER_BATCH, $ctx->chunk_size ) );

		if ( ! isset( $wpdb ) ) {
			delete_transient( self::STATE_TRANSIENT );
			return AuditChunk::complete( array(), $prior_totals );
		}

		// Fresh scan: nuke any leftover state from a previous run.
		if ( 0 === $after_id ) {
			delete_transient( self::STATE_TRANSIENT );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- gather phase walks attachments by ID cursor; cursor-based pagination is the whole point. No caching by design.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID
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

		// Exhausted attachments. Transition to emit phase even if the
		// state transient is empty — the empty path naturally returns
		// is_complete with zero items.
		if ( empty( $rows ) ) {
			return $this->run_emit_chunk( $ctx, 0, $prior_totals );
		}

		$state        = $this->read_state();
		$hashed_added = 0;

		foreach ( $rows as $row ) {
			$id   = (int) $row['ID'];
			$path = get_attached_file( $id );
			if ( ! $path ) {
				continue;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$size = @filesize( $path );
			if ( false === $size || $size > self::MAX_HASH_BYTES ) {
				continue;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @file_exists( $path ) ) {
				continue;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$hash = @hash_file( self::HASH_ALGO, $path );
			if ( false === $hash || '' === $hash ) {
				continue;
			}

			if ( ! isset( $state['hashes'][ $hash ] ) ) {
				$state['hashes'][ $hash ] = array(
					'ids'  => array(),
					'size' => (int) $size,
				);
			}
			$state['hashes'][ $hash ]['ids'][] = $id;
			++$hashed_added;
		}

		$this->write_state( $state );

		$last_id  = (int) end( $rows )['ID'];
		$has_more = count( $rows ) >= $batch_size;

		$totals = array(
			AuditChunk::TOTAL_SCANNED => (int) ( $prior_totals[ AuditChunk::TOTAL_SCANNED ] ?? 0 ) + count( $rows ),
			AuditChunk::TOTAL_FLAGGED => (int) ( $prior_totals[ AuditChunk::TOTAL_FLAGGED ] ?? 0 ),
		);
		if ( isset( $prior_totals[ AuditChunk::TOTAL_SAVINGS_BYTES ] ) ) {
			$totals[ AuditChunk::TOTAL_SAVINGS_BYTES ] = (int) $prior_totals[ AuditChunk::TOTAL_SAVINGS_BYTES ];
		}

		if ( $has_more ) {
			return AuditChunk::partial( array(), 'gather:' . $last_id, $totals );
		}
		// Gather exhausted — emit phase starts on the next call.
		return AuditChunk::partial( array(), 'emit:0', $totals );
	}

	// =====================================================================
	// Emit phase: walk duplicate groups, build receipts.
	// =====================================================================

	private function run_emit_chunk( ScanContext $ctx, int $offset, array $prior_totals ): AuditChunk {
		$batch_size = max( 1, min( self::EMIT_BATCH, $ctx->chunk_size ) );
		$state      = $this->read_state();

		$groups = $this->materialize_groups( $state );

		if ( 0 === $offset ) {
			// On entry to emit phase, snapshot the in-use set once for
			// the whole phase so every group sees consistent attribution.
			$in_use_result       = ( $this->in_use_callback )( false );
			$state['in_use_ids'] = isset( $in_use_result['ids'] ) && is_array( $in_use_result['ids'] )
				? array_fill_keys( array_map( 'intval', $in_use_result['ids'] ), true )
				: array();
			$this->write_state( $state );
		}
		$in_use_set = is_array( $state['in_use_ids'] ?? null ) ? $state['in_use_ids'] : array();

		$slice   = array_slice( $groups, $offset, $batch_size );
		$items   = array();
		$flagged = 0;
		$savings = 0;

		foreach ( $slice as $group ) {
			$hash = (string) $group['hash'];
			if ( $this->ignored->is_ignored( self::ID, $hash ) ) {
				continue;
			}
			$receipt = $this->build_receipt( $hash, $group['ids'], $group['size'], $in_use_set );
			if ( null === $receipt ) {
				continue;
			}
			$items[] = $receipt;
			++$flagged;
			$savings += (int) $receipt['potential_savings_bytes'];
		}

		$next_offset = $offset + count( $slice );
		$is_complete = $next_offset >= count( $groups );

		$totals = array(
			AuditChunk::TOTAL_SCANNED       => (int) ( $prior_totals[ AuditChunk::TOTAL_SCANNED ] ?? 0 ),
			AuditChunk::TOTAL_FLAGGED       => (int) ( $prior_totals[ AuditChunk::TOTAL_FLAGGED ] ?? 0 ) + $flagged,
			AuditChunk::TOTAL_SAVINGS_BYTES => (int) ( $prior_totals[ AuditChunk::TOTAL_SAVINGS_BYTES ] ?? 0 ) + $savings,
		);

		if ( $is_complete ) {
			delete_transient( self::STATE_TRANSIENT );
			return AuditChunk::complete( $items, $totals );
		}
		return AuditChunk::partial( $items, 'emit:' . $next_offset, $totals );
	}

	/**
	 * Filter the accumulated hash map to groups with ≥2 members, sorted
	 * deterministically by hash (so pagination is stable across chunks).
	 *
	 * @return array<int,array{hash:string,ids:int[],size:int}>
	 */
	private function materialize_groups( array $state ): array {
		$hashes = $state['hashes'] ?? array();
		$groups = array();
		foreach ( $hashes as $hash => $bag ) {
			$ids = $bag['ids'] ?? array();
			if ( count( $ids ) < 2 ) {
				continue;
			}
			$groups[] = array(
				'hash' => (string) $hash,
				'ids'  => array_values( array_unique( array_map( 'intval', $ids ) ) ),
				'size' => (int) ( $bag['size'] ?? 0 ),
			);
		}
		usort( $groups, fn( $a, $b ) => strcmp( $a['hash'], $b['hash'] ) );
		return $groups;
	}

	// =====================================================================
	// Receipt: identify primary + list copies
	// =====================================================================

	/**
	 * Build a duplicate-group receipt.
	 *
	 *   - "primary" = the copy we suggest keeping. Heuristic: prefer
	 *     in-use, fall back to oldest by post_date, fall back to lowest ID
	 *     (the upload likely first chronologically).
	 *   - "duplicates" = every other copy in the group, in the same
	 *     resolution order as a flat list.
	 *   - "duplicate_ids" = convenience field for the JS bulk-trash flow.
	 */
	private function build_receipt( string $hash, array $ids, int $size_bytes, array $in_use_set ): ?array {
		global $wpdb;
		if ( count( $ids ) < 2 || ! isset( $wpdb ) ) {
			return null;
		}

		// Fetch row metadata for every id in the group. Indexed lookup by ID
		// is fine even on huge libraries because groups are small (typically
		// 2-3 members). $ids is already cast to int upstream — defense in
		// depth here re-casts and ensures the placeholder list cannot carry
		// hostile content even on a runtime that violated the upstream
		// invariant.
		$safe_ids     = array_map( 'intval', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $safe_ids ), '%d' ) );
		// The placeholder count is fixed by count($safe_ids) and every arg
		// is intval'd just above; this is the canonical "IN (?, ?, ?)"
		// idiom recommended by wpdb's own docblock since prepare() doesn't
		// natively support variable-length IN lists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				"SELECT ID, post_title, post_parent, post_date, post_mime_type FROM {$wpdb->posts} WHERE ID IN ({$placeholders})",
				$safe_ids
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return null;
		}

		$copies = array();
		foreach ( $rows as $row ) {
			$id       = (int) $row['ID'];
			$copies[] = array(
				'id'                  => $id,
				'title'               => (string) $row['post_title'],
				'mime'                => (string) $row['post_mime_type'],
				'date_uploaded'       => (string) $row['post_date'],
				'attached_to_post_id' => (int) $row['post_parent'],
				'is_in_use'           => isset( $in_use_set[ $id ] ),
			);
		}
		// Stable sort: in-use first, then oldest date, then lowest ID.
		usort(
			$copies,
			function ( $a, $b ) {
				if ( $a['is_in_use'] !== $b['is_in_use'] ) {
					return $a['is_in_use'] ? -1 : 1;
				}
				$cmp = strcmp( (string) $a['date_uploaded'], (string) $b['date_uploaded'] );
				if ( 0 !== $cmp ) {
					return $cmp;
				}
				return $a['id'] <=> $b['id'];
			}
		);

		$primary       = array_shift( $copies );
		$duplicate_ids = array_map( fn( $c ) => $c['id'], $copies );
		$savings       = $size_bytes * count( $duplicate_ids );

		return array(
			// IgnoredStore key shape: the hash string identifies the group.
			'id'                      => $hash,
			'group_hash'              => $hash,
			'primary'                 => $primary,
			'duplicates'              => $copies,
			'duplicate_ids'           => $duplicate_ids,
			'group_size'              => count( $duplicate_ids ) + 1,
			'size_bytes'              => $size_bytes,
			'size_human'              => function_exists( 'size_format' ) ? size_format( $size_bytes ) : (string) $size_bytes,
			'potential_savings_bytes' => $savings,
			'potential_savings_human' => function_exists( 'size_format' ) ? size_format( $savings ) : (string) $savings,
			'why'                     => array(
				'reason'     => 'exact_hash_match',
				'algorithm'  => self::HASH_ALGO,
				'group_size' => count( $duplicate_ids ) + 1,
			),
		);
	}

	// =====================================================================
	// State persistence
	// =====================================================================

	private function read_state(): array {
		$raw = get_transient( self::STATE_TRANSIENT );
		if ( ! is_array( $raw ) ) {
			return array(
				'hashes'     => array(),
				'in_use_ids' => array(),
			);
		}
		if ( ! isset( $raw['hashes'] ) || ! is_array( $raw['hashes'] ) ) {
			$raw['hashes'] = array();
		}
		if ( ! isset( $raw['in_use_ids'] ) || ! is_array( $raw['in_use_ids'] ) ) {
			$raw['in_use_ids'] = array();
		}
		return $raw;
	}

	private function write_state( array $state ): void {
		set_transient( self::STATE_TRANSIENT, $state, self::STATE_TTL );
	}

	// =====================================================================
	// Bulk actions
	// =====================================================================

	public function bulk_action( string $action, array $ids, array $args = array() ): ActionResult {
		switch ( $action ) {
			case 'trash':
				return $this->bulk_trash_attachments( $ids );
			case 'ignore':
				return $this->bulk_ignore_groups( $ids );
			case 'unignore':
				return $this->bulk_unignore_groups( $ids );
			default:
				return ActionResult::failed(
					/* translators: %s: requested bulk-action verb */
					sprintf( __( 'Unknown action: %s', 'cf-media-manager' ), $action )
				);
		}
	}

	/**
	 * Trash by attachment ID. JS is responsible for picking which copies
	 * to trash within each duplicate group (the receipt carries
	 * `duplicate_ids` for exactly this purpose). The handler never trashes
	 * a primary by surprise — it acts on the explicit IDs the client sent.
	 */
	private function bulk_trash_attachments( array $ids ): ActionResult {
		$processed = 0;
		$errors    = array();

		foreach ( $ids as $raw_id ) {
			$id = (int) $raw_id;
			if ( $id <= 0 ) {
				$errors[ $raw_id ] = __( 'Invalid attachment id.', 'cf-media-manager' );
				continue;
			}
			$result = wp_delete_attachment( $id, false );
			if ( false === $result || null === $result ) {
				$errors[ $id ] = __( 'Could not trash attachment.', 'cf-media-manager' );
				continue;
			}
			++$processed;
		}

		if ( empty( $errors ) ) {
			return ActionResult::ok( $processed );
		}
		return ActionResult::partial( $processed, 0, $errors );
	}

	private function bulk_ignore_groups( array $hashes ): ActionResult {
		$processed = 0;
		foreach ( $hashes as $hash ) {
			$hash = (string) $hash;
			if ( '' === $hash ) {
				continue;
			}
			$this->ignored->ignore( self::ID, $hash );
			++$processed;
		}
		return ActionResult::ok( $processed );
	}

	private function bulk_unignore_groups( array $hashes ): ActionResult {
		$processed = 0;
		foreach ( $hashes as $hash ) {
			$hash = (string) $hash;
			if ( '' === $hash ) {
				continue;
			}
			$this->ignored->unignore( self::ID, $hash );
			++$processed;
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
			array(
				'key'   => 'group_hash',
				'label' => __( 'Group Hash', 'cf-media-manager' ),
			),
			array(
				'key'   => 'group_size',
				'label' => __( 'Group Size', 'cf-media-manager' ),
			),
			array(
				'key'   => 'size_bytes',
				'label' => __( 'File Size (bytes)', 'cf-media-manager' ),
			),
			array(
				'key'   => 'size_human',
				'label' => __( 'File Size', 'cf-media-manager' ),
			),
			array(
				'key'   => 'potential_savings_bytes',
				'label' => __( 'Potential Savings (bytes)', 'cf-media-manager' ),
			),
			array(
				'key'   => 'primary_id',
				'label' => __( 'Primary ID (keep)', 'cf-media-manager' ),
			),
			array(
				'key'   => 'primary_title',
				'label' => __( 'Primary Title', 'cf-media-manager' ),
			),
			array(
				'key'   => 'primary_in_use',
				'label' => __( 'Primary In Use', 'cf-media-manager' ),
			),
			array(
				'key'   => 'duplicate_ids',
				'label' => __( 'Duplicate IDs (trash)', 'cf-media-manager' ),
			),
		);
	}

	public function csv_row( array $item ): array {
		$primary       = is_array( $item['primary'] ?? null ) ? $item['primary'] : array();
		$duplicate_ids = is_array( $item['duplicate_ids'] ?? null ) ? $item['duplicate_ids'] : array();
		return array(
			(string) ( $item['group_hash'] ?? '' ),
			(string) ( $item['group_size'] ?? '' ),
			(string) ( $item['size_bytes'] ?? '' ),
			(string) ( $item['size_human'] ?? '' ),
			(string) ( $item['potential_savings_bytes'] ?? '' ),
			(string) ( $primary['id'] ?? '' ),
			(string) ( $primary['title'] ?? '' ),
			! empty( $primary['is_in_use'] ) ? 'true' : 'false',
			implode( ',', $duplicate_ids ),
		);
	}
}
