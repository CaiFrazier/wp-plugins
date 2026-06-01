<?php
/**
 * GhostAttachments — Media Library entries whose underlying file is
 * missing from disk.
 *
 * Detection logic per attachment is cheap:
 *   1. Read the attachment row from wp_posts (paginated batch, ID ASC).
 *   2. Resolve the expected file path via get_attached_file().
 *   3. file_exists() on that path. False = ghost.
 *
 * Receipts capture every signal we can produce cheaply, so the user can
 * decide informed:
 *   - expected_path: what the DB thought the file should be
 *   - attached_file_meta: the raw _wp_attached_file value (catches the
 *     "DB says /foo/bar but get_attached_file resolved differently" case)
 *   - attached_to_post_id: post_parent (0 means unattached)
 *   - featured_for_post_ids: reverse lookup on _thumbnail_id postmeta —
 *     a ghost that's STILL a featured image somewhere is a higher-stakes
 *     fix than an unused ghost
 *
 * What we DON'T do (intentional):
 *   - Scan post_content / builders / ACF / widgets. That's InUseScanner's
 *     job; the audit framework lets a separate "Unused Attachments"
 *     report consume those signals without ghost-detection paying the
 *     cost on every chunk.
 */

namespace CFMediaManager\Audit\Reports;

use CFMediaManager\Audit\ActionResult;
use CFMediaManager\Audit\AuditChunk;
use CFMediaManager\Audit\AuditReportCsvExportable;
use CFMediaManager\Audit\AuditReportInterface;
use CFMediaManager\Audit\IgnoredStore;
use CFMediaManager\Audit\ScanContext;

defined( 'ABSPATH' ) || exit;

final class GhostAttachments implements AuditReportInterface, AuditReportCsvExportable {

	const ID = 'ghost_attachments';

	/**
	 * Soft cap on attachments per chunk. The scan is cheap (one filesystem
	 * stat per row + a couple of DB roundtrips), so chunks can be larger
	 * than the runner's generic default without timing out.
	 */
	const BATCH_SIZE = 500;

	/**
	 * Cap on the number of post IDs we'll surface as "this ghost is the
	 * featured image of these posts" in the receipt. Effectively unbounded
	 * for realistic sites; protects the receipt size if someone has
	 * pathologically duplicated _thumbnail_id meta.
	 */
	const FEATURED_LOOKUP_CAP = 50;

	private IgnoredStore $ignored;

	public function __construct( IgnoredStore $ignored ) {
		$this->ignored = $ignored;
	}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Ghost attachments', 'cf-media-manager' );
	}

	public function description(): string {
		return __( 'Media Library entries whose underlying file is missing from disk.', 'cf-media-manager' );
	}

	// =====================================================================
	// scan_chunk
	// =====================================================================

	public function scan_chunk( ScanContext $ctx, ?string $cursor, array $prior_totals = array() ): AuditChunk {
		global $wpdb;

		$batch_size = max( 1, min( self::BATCH_SIZE, $ctx->chunk_size ) );
		$after_id   = ( null === $cursor ) ? 0 : (int) $cursor;

		// Defensive: if running in an environment without $wpdb (e.g., a
		// test forgot to install the double), return a clean completion
		// rather than crashing. Production always has $wpdb.
		if ( ! isset( $wpdb ) ) {
			return AuditChunk::complete( array(), $prior_totals );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- audit reports walk attachments by ID cursor for chunked pacing; WP_Query would lose cursor control and pull every column. No caching by design — each audit chunk reads fresh.
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
			// Exhausted the table. Final cumulative totals carry over.
			return AuditChunk::complete( array(), $prior_totals );
		}

		$ghosts = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['ID'];

			if ( $this->ignored->is_ignored( self::ID, $id ) ) {
				continue;
			}

			$path = get_attached_file( $id );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$exists = $path && @file_exists( $path );
			if ( $exists ) {
				continue;
			}

			$ghosts[] = $this->build_receipt( $id, $row, (string) $path );
		}

		$last_id  = (int) end( $rows )['ID'];
		$has_more = count( $rows ) >= $batch_size;

		$totals = array(
			AuditChunk::TOTAL_FLAGGED => (int) ( $prior_totals[ AuditChunk::TOTAL_FLAGGED ] ?? 0 ) + count( $ghosts ),
			AuditChunk::TOTAL_SCANNED => (int) ( $prior_totals[ AuditChunk::TOTAL_SCANNED ] ?? 0 ) + count( $rows ),
		);

		return $has_more
			? AuditChunk::partial( $ghosts, (string) $last_id, $totals )
			: AuditChunk::complete( $ghosts, $totals );
	}

	private function build_receipt( int $id, array $row, string $expected_path ): array {
		$attached_file = get_post_meta( $id, '_wp_attached_file', true );
		$featured_for  = $this->find_featured_posts( $id );
		$parent        = (int) $row['post_parent'];

		return array(
			'id'                  => $id,
			'title'               => (string) $row['post_title'],
			'mime'                => (string) $row['post_mime_type'],
			'date_uploaded'       => (string) $row['post_date'],
			'expected_path'       => $expected_path,
			'attached_file_meta'  => (string) $attached_file,
			'why'                 => array(
				'reason'                => 'file_missing',
				'checked'               => array( 'get_attached_file', 'file_exists' ),
				'attached_to_post_id'   => $parent,
				'featured_for_post_ids' => $featured_for,
				'has_active_references' => $parent > 0 || ! empty( $featured_for ),
			),
		);
	}

	/**
	 * Reverse-lookup posts whose _thumbnail_id postmeta points at this
	 * attachment. Capped at FEATURED_LOOKUP_CAP to bound the receipt
	 * payload — realistic sites have one or two such references, never
	 * thousands.
	 *
	 * @return int[]
	 */
	private function find_featured_posts( int $attachment_id ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- targeted reverse-lookup of _thumbnail_id postmeta; WP_Query meta_query for this is no faster and obscures intent.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				  WHERE meta_key = '_thumbnail_id'
				    AND meta_value = %d
				  LIMIT %d",
				$attachment_id,
				self::FEATURED_LOOKUP_CAP
			)
		);

		return is_array( $rows ) ? array_map( 'intval', $rows ) : array();
	}

	// =====================================================================
	// bulk_action
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

			// false = trash (recoverable), not permanent delete. This is
			// the safety rail the strategy doc called non-negotiable.
			$result = wp_delete_attachment( $id, false );
			if ( false === $result || null === $result ) {
				$errors[ $id ] = __( 'Could not trash attachment.', 'cf-media-manager' );
				continue;
			}
			++$processed;
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
			++$processed;
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
			array( 'key' => 'id',                  'label' => __( 'Attachment ID', 'cf-media-manager' ) ),
			array( 'key' => 'title',               'label' => __( 'Title', 'cf-media-manager' ) ),
			array( 'key' => 'mime',                'label' => __( 'MIME', 'cf-media-manager' ) ),
			array( 'key' => 'date_uploaded',       'label' => __( 'Date Uploaded', 'cf-media-manager' ) ),
			array( 'key' => 'expected_path',       'label' => __( 'Expected Path', 'cf-media-manager' ) ),
			array( 'key' => 'attached_file_meta',  'label' => __( '_wp_attached_file', 'cf-media-manager' ) ),
			array( 'key' => 'attached_to_post_id', 'label' => __( 'Attached To Post ID', 'cf-media-manager' ) ),
			array( 'key' => 'featured_for_posts',  'label' => __( 'Featured For Post IDs', 'cf-media-manager' ) ),
			array( 'key' => 'has_active_refs',     'label' => __( 'Has Active References', 'cf-media-manager' ) ),
		);
	}

	public function csv_row( array $item ): array {
		$featured = isset( $item['why']['featured_for_post_ids'] ) && is_array( $item['why']['featured_for_post_ids'] )
			? implode( ',', $item['why']['featured_for_post_ids'] )
			: '';
		return array(
			(string) ( $item['id'] ?? '' ),
			(string) ( $item['title'] ?? '' ),
			(string) ( $item['mime'] ?? '' ),
			(string) ( $item['date_uploaded'] ?? '' ),
			(string) ( $item['expected_path'] ?? '' ),
			(string) ( $item['attached_file_meta'] ?? '' ),
			(string) ( $item['why']['attached_to_post_id'] ?? '' ),
			$featured,
			! empty( $item['why']['has_active_references'] ) ? 'true' : 'false',
		);
	}
}
