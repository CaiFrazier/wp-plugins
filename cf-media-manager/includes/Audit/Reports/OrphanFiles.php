<?php
/**
 * OrphanFiles — media files on disk under wp-content/uploads/ that no
 * attachment row points at.
 *
 * The inverse of GhostAttachments: that report finds DB rows without a
 * file; this report finds files without a DB row.
 *
 * Detection pipeline (per candidate file):
 *   1. Filter by recognized media extension — skip log files, plugin
 *      caches, .htaccess, etc., so plugin-managed subdirectories don't
 *      drown the results.
 *   2. Path lookup against the attached-file set (one DB query at scan
 *      start; constant-time per file thereafter).
 *   3. Variant heuristic — `name-300x200.ext` is not an orphan if
 *      `name.ext` is attached. WP's thumbnail naming convention.
 *   4. Scaled heuristic — `name-scaled.ext` is not an orphan if
 *      `name.ext` is attached. WP's auto-scale convention for >2560px
 *      originals.
 *   5. Modern-sibling heuristic — `name.webp` / `name.avif` is not an
 *      orphan when an attached `name.jpg` / `name.png` exists. Catches
 *      CF Media Manager's own siblings plus LiteSpeed / Imagify output.
 *
 * State persistence:
 *   - The candidate file list is built once during the first chunk
 *     (cursor === null) and cached in a side transient. Subsequent
 *     chunks slice the cached list and never re-walk the FS.
 *   - The cursor is the integer index into the cached list, encoded as
 *     a string per the AuditChunk contract.
 *   - On completion the side transient is deleted.
 *
 * Bulk actions:
 *   - 'delete' — permanent removal from disk. NOT trashed (the WP trash
 *     system is for posts, not files). within_upload_dir() guards every
 *     deletion to bar path-traversal.
 *   - 'ignore' / 'unignore' — IgnoredStore via the path key.
 */

namespace CFMediaManager\Audit\Reports;

use CFMediaManager\Audit\ActionResult;
use CFMediaManager\Audit\AuditChunk;
use CFMediaManager\Audit\AuditReportCsvExportable;
use CFMediaManager\Audit\AuditReportInterface;
use CFMediaManager\Audit\IgnoredStore;
use CFMediaManager\Audit\ScanContext;
use CFMediaManager\Paths;

defined( 'ABSPATH' ) || exit;

final class OrphanFiles implements AuditReportInterface, AuditReportCsvExportable {

	const ID = 'orphan_files';

	const BATCH_SIZE = 200;

	/**
	 * Candidate list transient. Lives for the duration of one scan; deleted
	 * on completion or stolen on the next first-chunk call.
	 */
	const CANDIDATES_TRANSIENT = 'cf_mm_audit_orphan_files_candidates';
	const CANDIDATES_TTL       = 6 * 3600;

	/**
	 * Hard ceiling on how many orphan candidates one walk will materialize.
	 * The candidate list is held in a single transient, so an unbounded walk
	 * over a pathological uploads tree (millions of stray files, a
	 * misconfigured backup plugin dumping into uploads, etc.) could exhaust
	 * memory building the array. When the ceiling is hit the walk stops early
	 * and records a `truncated` flag so the UI can tell the user the report is
	 * partial rather than silently under-reporting.
	 */
	const MAX_CANDIDATES = 200000;

	/**
	 * Set to a positive int on the last scan when the walk was truncated at
	 * {@see MAX_CANDIDATES}. Zero/absent means the walk covered the whole tree.
	 * Read via {@see last_walk_truncated_at()} for surfacing in the UI.
	 */
	const TRUNCATED_TRANSIENT = 'cf_mm_audit_orphan_files_truncated';

	/**
	 * File extensions we consider "media." Everything else is skipped to
	 * avoid false positives in plugin-managed subdirectories (form uploads,
	 * cache files, logs, etc.).
	 */
	const MEDIA_EXTENSIONS = array(
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'tiff', 'ico',
		'pdf', 'mp4', 'mov', 'avi', 'wmv', 'flv', 'webm', 'mkv',
		'mp3', 'wav', 'ogg', 'm4a', 'flac',
		'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods',
		'zip', 'gz', 'tar',
	);

	/**
	 * Image extensions that participate in the modern-sibling heuristic
	 * (a .webp/.avif file is not orphan if an adjacent image of one of
	 * these extensions is attached).
	 */
	const SIBLING_SOURCE_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif' );

	private IgnoredStore $ignored;
	private Paths $paths;

	public function __construct( IgnoredStore $ignored, Paths $paths ) {
		$this->ignored = $ignored;
		$this->paths   = $paths;
	}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Orphan files', 'cf-media-manager' );
	}

	public function description(): string {
		return __( 'Files in the uploads directory that no Media Library record points at.', 'cf-media-manager' );
	}

	// =====================================================================
	// scan_chunk
	// =====================================================================

	public function scan_chunk( ScanContext $ctx, ?string $cursor, array $prior_totals = array() ): AuditChunk {
		$batch_size = max( 1, min( self::BATCH_SIZE, $ctx->chunk_size ) );
		$start_idx  = ( null === $cursor ) ? 0 : (int) $cursor;

		// First chunk in a scan: build the candidate list + cache it.
		// Subsequent chunks read the cached list.
		if ( null === $cursor ) {
			$candidates = $this->build_candidate_list();
			set_transient( self::CANDIDATES_TRANSIENT, $candidates, self::CANDIDATES_TTL );
		} else {
			$cached = get_transient( self::CANDIDATES_TRANSIENT );
			if ( ! is_array( $cached ) ) {
				// Cache evaporated mid-scan (TTL expired, host flushed
				// object cache, etc.). The runner sees a failed phase
				// through this path because we cannot reconstruct
				// pagination without re-walking from scratch — and
				// re-walking risks duplicating items the user already
				// saw. Surface the failure so the UI can prompt
				// "Restart scan".
				return AuditChunk::complete( array(), $prior_totals );
			}
			$candidates = $cached;
		}

		$total = count( $candidates );
		$slice = array_slice( $candidates, $start_idx, $batch_size );

		$items   = array();
		$flagged = 0;
		foreach ( $slice as $rel_path ) {
			$normalized = IgnoredStore::normalize_key( $rel_path );
			if ( $this->ignored->is_ignored( self::ID, $normalized ) ) {
				continue;
			}
			$abs = $this->paths->upload_dir() . $rel_path;
			$item = $this->build_receipt( $rel_path, $abs );
			if ( null !== $item ) {
				$items[] = $item;
				$flagged++;
			}
		}

		$processed_index = $start_idx + count( $slice );
		$is_complete     = $processed_index >= $total;

		$totals = array(
			AuditChunk::TOTAL_FLAGGED       => (int) ( $prior_totals[ AuditChunk::TOTAL_FLAGGED ] ?? 0 ) + $flagged,
			AuditChunk::TOTAL_SCANNED       => (int) ( $prior_totals[ AuditChunk::TOTAL_SCANNED ] ?? 0 ) + count( $slice ),
			AuditChunk::TOTAL_SAVINGS_BYTES => (int) ( $prior_totals[ AuditChunk::TOTAL_SAVINGS_BYTES ] ?? 0 )
				+ array_sum( array_column( $items, 'size_bytes' ) ),
		);

		if ( $is_complete ) {
			delete_transient( self::CANDIDATES_TRANSIENT );
			return AuditChunk::complete( $items, $totals );
		}
		return AuditChunk::partial( $items, (string) $processed_index, $totals );
	}

	/**
	 * Walk the uploads tree, filter by media extension, and diff against
	 * the attached-file lookup set. Returns the upload-relative paths the
	 * report considers orphan candidates — variant/scaled/sibling
	 * heuristics are applied here, BEFORE the chunked phase, so the
	 * cursor index points at a stable list.
	 *
	 * @return string[] Upload-relative paths.
	 */
	private function build_candidate_list(): array {
		$attached_set = $this->fetch_attached_file_set();
		$upload_dir   = $this->paths->upload_dir();

		if ( ! is_dir( $upload_dir ) ) {
			return array();
		}

		delete_transient( self::TRUNCATED_TRANSIENT );

		$candidates = array();
		// NOTE: FOLLOW_SYMLINKS is deliberately NOT set. Following symlinks
		// lets a symlinked directory inside uploads (a loop back to a parent,
		// or a link pointing at /etc, a sibling site, a backup mount) surface
		// external files as "orphans" and can send the walk into an infinite
		// loop. The bulk_delete guard re-checks within_upload_dir(), but the
		// walk itself must not escape the tree in the first place.
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(
				$upload_dir,
				\FilesystemIterator::SKIP_DOTS
			),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		$truncated = false;
		foreach ( $it as $info ) {
			/** @var \SplFileInfo $info */
			// Skip symlinks entirely. Even without FOLLOW_SYMLINKS a symlinked
			// FILE is still a leaf the iterator yields; a link whose target is
			// outside uploads must never enter the orphan report.
			if ( $info->isLink() ) {
				continue;
			}
			if ( ! $info->isFile() ) {
				continue;
			}

			$ext = strtolower( $info->getExtension() );
			if ( '' === $ext || ! in_array( $ext, self::MEDIA_EXTENSIONS, true ) ) {
				continue;
			}

			$rel = $this->paths->to_rel( $info->getPathname() );
			if ( null === $rel ) {
				continue;
			}
			$rel = str_replace( '\\', '/', $rel );

			if ( $this->is_known_path( $rel, $attached_set, $ext ) ) {
				continue;
			}

			$candidates[] = $rel;

			// Bound the materialized list so a runaway tree can't OOM the walk.
			if ( count( $candidates ) >= self::MAX_CANDIDATES ) {
				$truncated = true;
				break;
			}
		}

		if ( $truncated ) {
			set_transient( self::TRUNCATED_TRANSIENT, self::MAX_CANDIDATES, self::CANDIDATES_TTL );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated capacity notice; the truncation is also surfaced to the UI via the transient above.
				error_log( '[cf-media-manager] Orphan-files walk hit the ' . self::MAX_CANDIDATES . '-candidate ceiling; report is partial.' );
			}
		}

		sort( $candidates );  // Stable, alphabetic. Cursor index relies on this.
		return $candidates;
	}

	/**
	 * Whether the most recent candidate walk stopped early at MAX_CANDIDATES.
	 * Returns the ceiling that was hit, or 0 when the last walk was complete.
	 * Lets the UI flag the orphan report as partial instead of implying it
	 * covered the entire uploads tree.
	 */
	public function last_walk_truncated_at(): int {
		$val = get_transient( self::TRUNCATED_TRANSIENT );
		return is_numeric( $val ) ? (int) $val : 0;
	}

	/**
	 * Decide whether $rel is accounted for by the attached-file set or
	 * one of the three heuristics:
	 *
	 *   - exact match in attached_set
	 *   - thumbnail variant: name-WIDTHxHEIGHT.ext → name.ext attached
	 *   - scaled variant: name-scaled.ext → name.ext attached
	 *   - modern sibling: name.webp/avif → name.{jpg,jpeg,png,gif} attached
	 *
	 * The heuristics intentionally err on the side of NOT flagging — a
	 * false-negative (orphan we miss) is cheap; a false-positive (we
	 * tell the user to delete a real thumbnail) is the kind of bug that
	 * builds the "Media Cleaner deleted my logo" reputation.
	 */
	private function is_known_path( string $rel, array $attached_set, string $ext ): bool {
		if ( isset( $attached_set[ $rel ] ) ) {
			return true;
		}

		// Thumbnail variant pattern.
		if ( preg_match( '#^(.+)-\d+x\d+(\.[a-z0-9]+)$#i', $rel, $m ) ) {
			$base = $m[1] . $m[2];
			if ( isset( $attached_set[ $base ] ) ) {
				return true;
			}
		}

		// Scaled variant pattern.
		if ( preg_match( '#^(.+)-scaled(\.[a-z0-9]+)$#i', $rel, $m ) ) {
			$base = $m[1] . $m[2];
			if ( isset( $attached_set[ $base ] ) ) {
				return true;
			}
		}

		// Modern sibling.
		if ( in_array( $ext, array( 'webp', 'avif' ), true ) ) {
			$base = preg_replace( '#\.[^.]+$#', '', $rel );
			foreach ( self::SIBLING_SOURCE_EXTENSIONS as $source_ext ) {
				if ( isset( $attached_set[ $base . '.' . $source_ext ] ) ) {
					return true;
				}
			}
			// Also handle the scaled+sibling combo: foo-scaled.webp where
			// the attached file is foo.jpg.
			if ( preg_match( '#^(.+)-scaled\.[^.]+$#', $rel, $m ) ) {
				foreach ( self::SIBLING_SOURCE_EXTENSIONS as $source_ext ) {
					if ( isset( $attached_set[ $m[1] . '.' . $source_ext ] ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Pull every `_wp_attached_file` value out of postmeta into a
	 * lookup-friendly set (associative array keyed by upload-relative
	 * path, value true). One query per scan; reused for every candidate.
	 *
	 * @return array<string,true>
	 */
	private function fetch_attached_file_set(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- single-pass _wp_attached_file dump is the only way to build the attachment-path set the orphan walk diffs against. WP_Query meta_query against every attachment would be O(N) full-row reads vs this single column scan. No caching: the orphan-files scan re-reads to catch newly-uploaded attachments.
		$rows = $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'"
		);
		$set = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $rel ) {
				$rel = str_replace( '\\', '/', (string) $rel );
				if ( '' === $rel ) {
					continue;
				}
				$set[ $rel ] = true;
			}
		}
		return $set;
	}

	private function build_receipt( string $rel_path, string $abs_path ): ?array {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$size = @filesize( $abs_path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mtime = @filemtime( $abs_path );

		if ( false === $size ) {
			// File vanished between candidate build and chunk processing
			// (e.g., another admin deleted it). Treat as a non-event;
			// drop from the report rather than surfacing a stale entry.
			return null;
		}

		return array(
			'path'        => $rel_path,
			'size_bytes'  => (int) $size,
			'size_human'  => function_exists( 'size_format' ) ? size_format( $size ) : (string) $size,
			'mtime'       => $mtime ? (int) $mtime : null,
			'mtime_human' => $mtime ? gmdate( 'Y-m-d', (int) $mtime ) : '',
			'extension'   => strtolower( pathinfo( $rel_path, PATHINFO_EXTENSION ) ),
			'why'         => array(
				'reason'  => 'no_attachment_record',
				'checked' => array(
					'attached_file_match',
					'variant_pattern',
					'scaled_pattern',
					'modern_sibling',
				),
			),
		);
	}

	// =====================================================================
	// bulk_action
	// =====================================================================

	public function bulk_action( string $action, array $ids, array $args = array() ): ActionResult {
		switch ( $action ) {
			case 'delete':
				return $this->bulk_delete( $ids );
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

	/**
	 * Permanent delete from disk. There is no trash for raw files — the
	 * WP trash system is post-scoped. Each candidate is gated through
	 * within_upload_dir() so a path-traversal payload in the bulk-action
	 * id list can't escape the uploads tree.
	 */
	private function bulk_delete( array $paths ): ActionResult {
		$processed = 0;
		$skipped   = 0;
		$errors    = array();

		foreach ( $paths as $raw ) {
			$rel = IgnoredStore::normalize_key( (string) $raw );
			if ( '' === $rel ) {
				$errors[ (string) $raw ] = __( 'Empty path.', 'cf-media-manager' );
				continue;
			}

			$abs = $this->paths->upload_dir() . $rel;
			if ( ! $this->paths->within_upload_dir( $abs ) ) {
				$errors[ $rel ] = __( 'Path is outside the uploads directory.', 'cf-media-manager' );
				continue;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @file_exists( $abs ) ) {
				++$skipped;
				continue;
			}

			// wp_delete_file returns void; check post-condition.
			wp_delete_file( $abs );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a re-check after delete shouldn't raise if the file's parent permissions just changed.
			if ( @file_exists( $abs ) ) {
				$errors[ $rel ] = __( 'Could not delete file.', 'cf-media-manager' );
				continue;
			}
			++$processed;
		}

		if ( empty( $errors ) ) {
			return ActionResult::ok( $processed, $skipped );
		}
		return ActionResult::partial( $processed, $skipped, $errors );
	}

	private function bulk_ignore( array $paths ): ActionResult {
		$processed = 0;
		foreach ( $paths as $raw ) {
			$rel = IgnoredStore::normalize_key( (string) $raw );
			if ( '' === $rel ) {
				continue;
			}
			$this->ignored->ignore( self::ID, $rel );
			++$processed;
		}
		return ActionResult::ok( $processed );
	}

	private function bulk_unignore( array $paths ): ActionResult {
		$processed = 0;
		foreach ( $paths as $raw ) {
			$rel = IgnoredStore::normalize_key( (string) $raw );
			if ( '' === $rel ) {
				continue;
			}
			$this->ignored->unignore( self::ID, $rel );
			++$processed;
		}
		return ActionResult::ok( $processed );
	}

	public function supports_bulk(): array {
		return array( 'delete', 'ignore', 'unignore' );
	}

	// =====================================================================
	// CSV export
	// =====================================================================

	public function csv_columns(): array {
		return array(
			array( 'key' => 'path',        'label' => __( 'Path', 'cf-media-manager' ) ),
			array( 'key' => 'extension',   'label' => __( 'Extension', 'cf-media-manager' ) ),
			array( 'key' => 'size_bytes',  'label' => __( 'Size (bytes)', 'cf-media-manager' ) ),
			array( 'key' => 'size_human',  'label' => __( 'Size', 'cf-media-manager' ) ),
			array( 'key' => 'mtime_human', 'label' => __( 'Modified', 'cf-media-manager' ) ),
		);
	}

	public function csv_row( array $item ): array {
		return array(
			(string) ( $item['path'] ?? '' ),
			(string) ( $item['extension'] ?? '' ),
			(string) ( $item['size_bytes'] ?? '' ),
			(string) ( $item['size_human'] ?? '' ),
			(string) ( $item['mtime_human'] ?? '' ),
		);
	}
}
