<?php

namespace CFMediaManager\Tests\Audit\Reports;

use CFMediaManager\Audit\AuditChunk;
use CFMediaManager\Audit\IgnoredStore;
use CFMediaManager\Audit\Reports\OversizedOriginals;
use CFMediaManager\Audit\ScanContext;
use PHPUnit\Framework\TestCase;

/**
 * OversizedOriginals flags attachments by file-size and/or dimension
 * threshold. Tests verify both gates, config overrides, the savings
 * estimate, the scaled-variant flag, and the bulk-action surface.
 *
 * The fake $wpdb pattern matches the other audit-report tests — sees
 * the SELECT-with-ORDER-BY shape, returns matching attachment rows.
 */
final class OversizedOriginalsTest extends TestCase {

	private IgnoredStore $ignored;
	private OversizedOriginals $report;

	protected function setUp(): void {
		cf_media_manager_test_reset_state();
		$this->ignored                       = new IgnoredStore();
		$this->report                        = new OversizedOriginals( $this->ignored );
		$GLOBALS['fake_wpdb_attachments']    = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['fake_wpdb_attachments'] );
	}

	// =====================================================================
	// Fixtures
	// =====================================================================

	private function add_image( int $id, array $meta_overrides = array(), array $row_overrides = array() ): void {
		$GLOBALS['cf_media_manager_test_post_meta'][ $id ]['_wp_attachment_metadata'] = $meta_overrides;
		$GLOBALS['fake_wpdb_attachments'][ $id ] = array_merge(
			array(
				'ID'             => $id,
				'post_title'     => 'image-' . $id,
				'post_parent'    => 0,
				'post_date'      => '2024-01-01 00:00:00',
				'post_mime_type' => 'image/jpeg',
			),
			$row_overrides
		);
	}

	private function install_fake_wpdb(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $posts    = 'wp_posts';
			public string $postmeta = 'wp_postmeta';

			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			public function prepare( string $sql, ...$args ): string {
				// Real wpdb::prepare unwraps a single-array arg.
				if ( count( $args ) === 1 && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				return $sql . '|args=' . implode( ',', array_map( 'strval', $args ) );
			}

			public function get_results( string $sql, $output ): array {
				// OversizedOriginals query: args = [ esc_like('image/').'%', $after_id, $limit ]
				// — three params, with the LIKE pattern first. Extract the
				// trailing two ints from the encoded arg sentinel.
				if ( ! preg_match( '/\|args=.*?,(\d+),(\d+)$/', $sql, $m ) ) {
					return array();
				}
				$after_id = (int) $m[1];
				$limit    = (int) $m[2];

				$matches = array();
				foreach ( $GLOBALS['fake_wpdb_attachments'] as $att ) {
					if ( (int) $att['ID'] > $after_id ) {
						$matches[] = $att;
					}
				}
				usort( $matches, fn( $a, $b ) => (int) $a['ID'] <=> (int) $b['ID'] );
				return array_slice( $matches, 0, $limit );
			}
		};
	}

	private function scan_to_completion( array $config = array() ): array {
		$items  = array();
		$cursor = null;
		$priors = array();
		$ctx    = new ScanContext( false, 1000, $config );
		do {
			$chunk  = $this->report->scan_chunk( $ctx, $cursor, $priors );
			$items  = array_merge( $items, $chunk->items );
			$cursor = $chunk->next_cursor;
			$priors = $chunk->running_totals;
		} while ( ! $chunk->is_complete );
		return $items;
	}

	// =====================================================================
	// Identity
	// =====================================================================

	public function test_report_identity(): void {
		self::assertSame( 'oversized_originals', $this->report->id() );
		self::assertNotEmpty( $this->report->label() );
		self::assertSame( array( 'trash', 'ignore', 'unignore' ), $this->report->supports_bulk() );
	}

	// =====================================================================
	// Threshold gates
	// =====================================================================

	public function test_attachment_below_both_thresholds_is_skipped(): void {
		$this->add_image( 1, array(
			'filesize' => 500_000,    // 500 KB
			'width'    => 1024,
			'height'   => 768,
		) );
		$this->install_fake_wpdb();

		$items = $this->scan_to_completion();
		self::assertSame( array(), $items );
	}

	public function test_attachment_over_size_threshold_is_flagged(): void {
		$this->add_image( 1, array(
			'filesize' => 5_000_000,  // 5 MB > 2 MB default
			'width'    => 1024,
			'height'   => 768,
		) );
		$this->install_fake_wpdb();

		$items = $this->scan_to_completion();
		self::assertCount( 1, $items );
		self::assertSame( array( 'size' ), $items[0]['why']['triggered_by'] );
	}

	public function test_attachment_over_dimension_threshold_is_flagged(): void {
		$this->add_image( 1, array(
			'filesize' => 200_000,    // small file
			'width'    => 4000,       // big pixels
			'height'   => 3000,
		) );
		$this->install_fake_wpdb();

		$items = $this->scan_to_completion();
		self::assertCount( 1, $items );
		self::assertSame( array( 'dimensions' ), $items[0]['why']['triggered_by'] );
		self::assertSame( 4000, $items[0]['longest_side'] );
	}

	public function test_attachment_over_both_thresholds_reports_both(): void {
		$this->add_image( 1, array(
			'filesize' => 5_000_000,
			'width'    => 4000,
			'height'   => 3000,
		) );
		$this->install_fake_wpdb();

		$items = $this->scan_to_completion();
		self::assertCount( 1, $items );
		self::assertSame( array( 'size', 'dimensions' ), $items[0]['why']['triggered_by'] );
	}

	// =====================================================================
	// Config overrides
	// =====================================================================

	public function test_config_min_bytes_override_changes_gate(): void {
		$this->add_image( 1, array(
			'filesize' => 1_500_000,  // 1.5 MB — under default 2 MB
			'width'    => 1024,
			'height'   => 768,
		) );
		$this->install_fake_wpdb();

		// Under default config: not flagged.
		self::assertSame( array(), $this->scan_to_completion() );

		// Lower threshold to 1 MB: now flagged.
		$items = $this->scan_to_completion( array(
			OversizedOriginals::CONFIG_MIN_BYTES => 1_000_000,
		) );
		self::assertCount( 1, $items );
		self::assertSame( 1_000_000, $items[0]['why']['threshold_bytes'] );
	}

	public function test_config_longest_side_override_changes_gate(): void {
		$this->add_image( 1, array(
			'filesize' => 200_000,
			'width'    => 1600,
			'height'   => 1200,
		) );
		$this->install_fake_wpdb();

		// Default 2560 → not flagged.
		self::assertSame( array(), $this->scan_to_completion() );

		// Lower to 1500 → flagged on dimensions.
		$items = $this->scan_to_completion( array(
			OversizedOriginals::CONFIG_MIN_LONGEST_SIDE => 1500,
		) );
		self::assertCount( 1, $items );
		self::assertSame( 1500, $items[0]['why']['threshold_longest_side'] );
	}

	// =====================================================================
	// Receipt content
	// =====================================================================

	public function test_receipt_includes_size_dimensions_and_savings(): void {
		$this->add_image( 42, array(
			'filesize' => 4_000_000,
			'width'    => 4000,
			'height'   => 3000,
		), array(
			'post_title' => 'Hero',
			'post_date'  => '2023-09-15 12:00:00',
		) );
		$this->install_fake_wpdb();

		$items = $this->scan_to_completion();
		$item  = $items[0];

		self::assertSame( 42, $item['id'] );
		self::assertSame( 'Hero', $item['title'] );
		self::assertSame( 4_000_000, $item['size_bytes'] );
		self::assertSame( '4000 × 3000', $item['dimensions'] );
		self::assertSame( 4000, $item['longest_side'] );
		self::assertSame( '2023-09-15 12:00:00', $item['date_uploaded'] );
		self::assertSame( 'oversized_original', $item['why']['reason'] );
	}

	public function test_scaled_variant_flag_surfaces_metadata_original_image(): void {
		$this->add_image( 1, array(
			'filesize'        => 5_000_000,
			'width'           => 2560,
			'height'          => 1920,
			'original_image'  => 'photo.jpg',  // present → WP auto-scaled this
		) );
		$this->install_fake_wpdb();

		$item = $this->scan_to_completion()[0];

		self::assertTrue( $item['has_scaled_variant'] );
		self::assertTrue( $item['why']['has_scaled_variant'] );
	}

	public function test_scaled_variant_flag_is_false_when_no_original_image(): void {
		$this->add_image( 1, array(
			'filesize' => 5_000_000,
			'width'    => 2000,
			'height'   => 1500,
		) );
		$this->install_fake_wpdb();

		$item = $this->scan_to_completion()[0];
		self::assertFalse( $item['has_scaled_variant'] );
	}

	public function test_running_totals_accumulate_savings_bytes(): void {
		// Two flagged images. Pin the threshold to a round 2,000,000 so
		// the savings math is unambiguous (the default constant is
		// 2 * 1024 * 1024 = 2,097,152).
		$this->add_image( 1, array(
			'filesize' => 5_000_000,  // savings = 3,000,000
			'width'    => 1024,
			'height'   => 768,
		) );
		$this->add_image( 2, array(
			'filesize' => 8_000_000,  // savings = 6,000,000
			'width'    => 1024,
			'height'   => 768,
		) );
		$this->install_fake_wpdb();

		$ctx = new ScanContext( false, 1000, array(
			OversizedOriginals::CONFIG_MIN_BYTES => 2_000_000,
		) );
		$chunk = $this->report->scan_chunk( $ctx, null );

		self::assertSame(
			9_000_000,
			$chunk->running_totals[ AuditChunk::TOTAL_SAVINGS_BYTES ]
		);
	}

	// =====================================================================
	// Ignored filtering
	// =====================================================================

	public function test_ignored_attachments_are_skipped(): void {
		$this->add_image( 1, array( 'filesize' => 5_000_000, 'width' => 1024, 'height' => 768 ) );
		$this->add_image( 2, array( 'filesize' => 5_000_000, 'width' => 1024, 'height' => 768 ) );
		$this->install_fake_wpdb();

		$this->ignored->ignore( 'oversized_originals', 1 );

		$items = $this->scan_to_completion();
		self::assertCount( 1, $items );
		self::assertSame( 2, $items[0]['id'] );
	}

	// =====================================================================
	// Metadata fallback
	// =====================================================================

	public function test_falls_back_to_disk_filesize_when_metadata_lacks_it(): void {
		$path = tempnam( sys_get_temp_dir(), 'cfmm_oversize_' );
		// Write a 3 MB file.
		$fp = fopen( $path, 'wb' );
		fwrite( $fp, str_repeat( 'x', 3 * 1024 * 1024 ) );
		fclose( $fp );

		$this->add_image( 1, array(
			// No 'filesize' key in metadata.
			'width'  => 1024,
			'height' => 768,
		) );
		$GLOBALS['cf_media_manager_test_attachments'][1] = array( 'file' => $path );
		$this->install_fake_wpdb();

		$items = $this->scan_to_completion();
		self::assertCount( 1, $items );
		self::assertSame( 3 * 1024 * 1024, $items[0]['size_bytes'] );

		unlink( $path );
	}

	// =====================================================================
	// Bulk actions
	// =====================================================================

	public function test_bulk_trash_uses_force_false(): void {
		$result = $this->report->bulk_action( 'trash', array( 11, 22 ) );

		self::assertTrue( $result->success );
		self::assertSame( 2, $result->processed );
		foreach ( $GLOBALS['cf_media_manager_test_deleted_attachments'] as $call ) {
			self::assertFalse( $call['force'] );
		}
	}

	public function test_bulk_ignore_unignore_round_trip(): void {
		$this->report->bulk_action( 'ignore', array( 5 ) );
		self::assertTrue( $this->ignored->is_ignored( 'oversized_originals', 5 ) );

		$this->report->bulk_action( 'unignore', array( 5 ) );
		self::assertFalse( $this->ignored->is_ignored( 'oversized_originals', 5 ) );
	}

	public function test_unknown_bulk_action_fails(): void {
		$result = $this->report->bulk_action( 'thanos_snap', array( 1 ) );
		self::assertFalse( $result->success );
	}

	public function test_invalid_trash_ids_are_rejected_per_item(): void {
		$result = $this->report->bulk_action( 'trash', array( 0, -3, 'oops' ) );
		self::assertSame( 0, $result->processed );
		self::assertCount( 3, $result->errors );
	}
}
