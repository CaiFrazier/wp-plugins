<?php
/**
 * OversizedOriginals — attachments whose source file exceeds a size or
 * dimension threshold.
 *
 * Field research surfaces this as a chronic problem: clients upload
 * 4000-pixel phone photos and WordPress auto-scales to 2560 but keeps
 * the original on disk for re-cropping. A 5 MB photo becomes 5 MB +
 * 650 KB of thumbnails + 1.2 MB of "scaled" + the original — easily
 * 7 MB on disk per upload. Multiply by hundreds of uploads and the
 * filesystem balloons.
 *
 * This report is informational with action affordances:
 *   - Detection: file size > threshold OR longest-side pixels > threshold
 *   - The user reads the report and decides whether to:
 *       a) trash the attachment outright (with the usual safety rail)
 *       b) ignore the attachment (intentional — large hero images, etc.)
 *       c) re-export from source at smaller dimensions and re-upload
 *         (outside the audit's scope, but the receipt informs the work)
 *
 * Thresholds are config-driven via ScanContext::config:
 *   - 'min_bytes'                — file size in bytes (default 2 MB)
 *   - 'min_pixels_longest_side'  — pixel dimension on longest side
 *                                  (default 2560, matching WP's auto-
 *                                  scale threshold)
 *
 * Receipts include `triggered_by` so the user sees whether size or
 * dimensions (or both) tripped the flag. The `has_scaled_variant` flag
 * surfaces WP's "original_image is set" condition — those attachments
 * have an extra full-size copy on disk beyond the working file.
 */

namespace CFMediaManager\Audit\Reports;

use CFMediaManager\Audit\ActionResult;
use CFMediaManager\Audit\AuditChunk;
use CFMediaManager\Audit\AuditReportCsvExportable;
use CFMediaManager\Audit\AuditReportInterface;
use CFMediaManager\Audit\IgnoredStore;
use CFMediaManager\Audit\ScanContext;

defined( 'ABSPATH' ) || exit;

final class OversizedOriginals implements AuditReportInterface, AuditReportCsvExportable {

	const ID = 'oversized_originals';

	const BATCH_SIZE = 500;

	const DEFAULT_MIN_BYTES        = 2 * 1024 * 1024;  // 2 MB
	const DEFAULT_MIN_LONGEST_SIDE = 2560;             // WP's auto-scale threshold

	const CONFIG_MIN_BYTES        = 'min_bytes';
	const CONFIG_MIN_LONGEST_SIDE = 'min_pixels_longest_side';

	private IgnoredStore $ignored;

	public function __construct( IgnoredStore $ignored ) {
		$this->ignored = $ignored;
	}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Oversized originals', 'cf-media-manager' );
	}

	public function description(): string {
		return __( 'Image attachments whose source file exceeds the size or dimension threshold. Most often phone photos uploaded at full resolution.', 'cf-media-manager' );
	}

	// =====================================================================
	// scan_chunk
	// =====================================================================

	public function scan_chunk( ScanContext $ctx, ?string $cursor, array $prior_totals = array() ): AuditChunk {
		global $wpdb;

		$batch_size = max( 1, min( self::BATCH_SIZE, $ctx->chunk_size ) );
		$after_id   = ( null === $cursor ) ? 0 : (int) $cursor;
		$min_bytes  = max( 1, (int) $ctx->config( self::CONFIG_MIN_BYTES, self::DEFAULT_MIN_BYTES ) );
		$min_pixels = max( 1, (int) $ctx->config( self::CONFIG_MIN_LONGEST_SIDE, self::DEFAULT_MIN_LONGEST_SIDE ) );

		if ( ! isset( $wpdb ) ) {
			return AuditChunk::complete( array(), $prior_totals );
		}

		// LIKE wildcard passes through the placeholder + esc_like so PHPCS's
		// LikeWildcardsInQuery sniff is satisfied. Equivalent SQL to a literal
		// LIKE 'image/%' on the column.
		$like_image = $wpdb->esc_like( 'image/' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- audit reports walk attachments by ID cursor for chunked pacing; WP_Query would lose cursor control. No caching by design — each audit chunk reads fresh.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_parent, post_date, post_mime_type
				   FROM {$wpdb->posts}
				  WHERE post_type = 'attachment'
				    AND post_status = 'inherit'
				    AND post_mime_type LIKE %s
				    AND ID > %d
				  ORDER BY ID ASC
				  LIMIT %d",
				$like_image,
				$after_id,
				$batch_size
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return AuditChunk::complete( array(), $prior_totals );
		}

		$items   = array();
		$flagged = 0;
		$savings = 0;

		foreach ( $rows as $row ) {
			$id = (int) $row['ID'];
			if ( $this->ignored->is_ignored( self::ID, $id ) ) {
				continue;
			}

			$assessment = $this->assess( $id, $min_bytes, $min_pixels );
			if ( null === $assessment ) {
				continue;
			}

			$items[] = $this->build_receipt( $id, $row, $assessment, $min_bytes, $min_pixels );
			++$flagged;
			$savings += max( 0, $assessment['size_bytes'] - $min_bytes );
		}

		$last_id  = (int) end( $rows )['ID'];
		$has_more = count( $rows ) >= $batch_size;

		$totals = array(
			AuditChunk::TOTAL_FLAGGED       => (int) ( $prior_totals[ AuditChunk::TOTAL_FLAGGED ] ?? 0 ) + $flagged,
			AuditChunk::TOTAL_SCANNED       => (int) ( $prior_totals[ AuditChunk::TOTAL_SCANNED ] ?? 0 ) + count( $rows ),
			AuditChunk::TOTAL_SAVINGS_BYTES => (int) ( $prior_totals[ AuditChunk::TOTAL_SAVINGS_BYTES ] ?? 0 ) + $savings,
		);

		return $has_more
			? AuditChunk::partial( $items, (string) $last_id, $totals )
			: AuditChunk::complete( $items, $totals );
	}

	/**
	 * Read size + dimensions for one attachment and decide whether either
	 * threshold trips. Returns null when neither triggers — caller skips
	 * the receipt build entirely.
	 *
	 * @return array{
	 *     size_bytes:int,
	 *     width:int,
	 *     height:int,
	 *     longest_side:int,
	 *     triggered_by:string[],
	 *     has_scaled_variant:bool
	 * }|null
	 */
	private function assess( int $id, int $min_bytes, int $min_pixels ): ?array {
		$meta = get_post_meta( $id, '_wp_attachment_metadata', true );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$size_bytes = isset( $meta['filesize'] ) ? (int) $meta['filesize'] : 0;
		if ( $size_bytes <= 0 ) {
			// Metadata didn't cache filesize — fall back to disk stat.
			$path = function_exists( 'get_attached_file' ) ? get_attached_file( $id ) : '';
			if ( $path ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$disk       = @filesize( $path );
				$size_bytes = is_int( $disk ) ? $disk : 0;
			}
		}

		$width        = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$height       = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
		$longest_side = max( $width, $height );

		$triggered = array();
		if ( $size_bytes > $min_bytes ) {
			$triggered[] = 'size';
		}
		if ( $longest_side > $min_pixels ) {
			$triggered[] = 'dimensions';
		}
		if ( empty( $triggered ) ) {
			return null;
		}

		// `original_image` in metadata means WP auto-scaled this attachment
		// at upload time — the full-resolution original sits on disk
		// alongside the scaled working copy. Worth surfacing in the
		// receipt as a "you have two big files for this one attachment"
		// hint.
		$has_scaled = ! empty( $meta['original_image'] );

		return array(
			'size_bytes'         => $size_bytes,
			'width'              => $width,
			'height'             => $height,
			'longest_side'       => $longest_side,
			'triggered_by'       => $triggered,
			'has_scaled_variant' => $has_scaled,
		);
	}

	private function build_receipt( int $id, array $row, array $assessment, int $min_bytes, int $min_pixels ): array {
		$dimensions = ( $assessment['width'] > 0 && $assessment['height'] > 0 )
			? sprintf( '%d × %d', $assessment['width'], $assessment['height'] )
			: '';

		return array(
			'id'                  => $id,
			'title'               => (string) $row['post_title'],
			'mime'                => (string) $row['post_mime_type'],
			'date_uploaded'       => (string) $row['post_date'],
			'attached_to_post_id' => (int) $row['post_parent'],
			'size_bytes'          => $assessment['size_bytes'],
			'size_human'          => function_exists( 'size_format' )
				? size_format( $assessment['size_bytes'] )
				: '',
			'width'               => $assessment['width'],
			'height'              => $assessment['height'],
			'longest_side'        => $assessment['longest_side'],
			'dimensions'          => $dimensions,
			'has_scaled_variant'  => $assessment['has_scaled_variant'],
			'why'                 => array(
				'reason'                 => 'oversized_original',
				'threshold_bytes'        => $min_bytes,
				'threshold_longest_side' => $min_pixels,
				'triggered_by'           => $assessment['triggered_by'],
				'has_scaled_variant'     => $assessment['has_scaled_variant'],
			),
		);
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
			array(
				'key'   => 'id',
				'label' => __( 'Attachment ID', 'cf-media-manager' ),
			),
			array(
				'key'   => 'title',
				'label' => __( 'Title', 'cf-media-manager' ),
			),
			array(
				'key'   => 'mime',
				'label' => __( 'MIME', 'cf-media-manager' ),
			),
			array(
				'key'   => 'date_uploaded',
				'label' => __( 'Date Uploaded', 'cf-media-manager' ),
			),
			array(
				'key'   => 'size_bytes',
				'label' => __( 'Size (bytes)', 'cf-media-manager' ),
			),
			array(
				'key'   => 'size_human',
				'label' => __( 'Size', 'cf-media-manager' ),
			),
			array(
				'key'   => 'width',
				'label' => __( 'Width', 'cf-media-manager' ),
			),
			array(
				'key'   => 'height',
				'label' => __( 'Height', 'cf-media-manager' ),
			),
			array(
				'key'   => 'longest_side',
				'label' => __( 'Longest Side', 'cf-media-manager' ),
			),
			array(
				'key'   => 'has_scaled_variant',
				'label' => __( 'Has Scaled Variant', 'cf-media-manager' ),
			),
			array(
				'key'   => 'triggered_by',
				'label' => __( 'Triggered By', 'cf-media-manager' ),
			),
		);
	}

	public function csv_row( array $item ): array {
		$triggers = isset( $item['why']['triggered_by'] ) && is_array( $item['why']['triggered_by'] )
			? implode( ',', $item['why']['triggered_by'] )
			: '';
		return array(
			(string) ( $item['id'] ?? '' ),
			(string) ( $item['title'] ?? '' ),
			(string) ( $item['mime'] ?? '' ),
			(string) ( $item['date_uploaded'] ?? '' ),
			(string) ( $item['size_bytes'] ?? '' ),
			(string) ( $item['size_human'] ?? '' ),
			(string) ( $item['width'] ?? '' ),
			(string) ( $item['height'] ?? '' ),
			(string) ( $item['longest_side'] ?? '' ),
			! empty( $item['has_scaled_variant'] ) ? 'true' : 'false',
			$triggers,
		);
	}
}
