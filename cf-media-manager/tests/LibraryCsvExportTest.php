<?php

namespace CFMediaManager\Tests;

use CFMediaManager\LibraryColumnRegistry;
use CFMediaManager\LibraryCsvExporter;
use PHPUnit\Framework\TestCase;

/**
 * LibraryCsvExporter is the testable core extracted from
 * LibraryPage::handle_csv_export(). The HTTP plumbing (headers, streaming,
 * exit) stays in LibraryPage; everything that shapes the output is
 * asserted here.
 */
final class LibraryCsvExportTest extends TestCase {

	protected function setUp(): void {
		cf_media_manager_test_reset_state();
	}

	// -------------------------------------------------------------------------
	// Column resolution
	// -------------------------------------------------------------------------

	public function test_empty_param_resolves_to_registry_defaults(): void {
		self::assertSame( LibraryColumnRegistry::defaults(), LibraryCsvExporter::resolve_columns( '' ) );
	}

	public function test_all_invalid_keys_fall_back_to_defaults(): void {
		self::assertSame( LibraryColumnRegistry::defaults(), LibraryCsvExporter::resolve_columns( 'nope,still_nope' ) );
	}

	public function test_valid_keys_are_filtered_and_kept_in_order(): void {
		self::assertSame( array( 'title', 'id' ), LibraryCsvExporter::resolve_columns( 'title,bogus,id' ) );
	}

	public function test_keys_are_sanitized_before_validation(): void {
		// 'I D!' sanitizes to 'id' (valid); '@@@' sanitizes to '' (dropped).
		self::assertSame( array( 'id' ), LibraryCsvExporter::resolve_columns( 'I D!,@@@' ) );
	}

	// -------------------------------------------------------------------------
	// Query args
	// -------------------------------------------------------------------------

	public function test_query_args_have_the_unbounded_export_shape(): void {
		$args = LibraryCsvExporter::build_query_args( '', '', '' );

		self::assertSame( 'attachment', $args['post_type'] );
		self::assertSame( 'inherit', $args['post_status'] );
		self::assertSame( -1, $args['posts_per_page'] );
		self::assertSame( 'ID', $args['orderby'] );
		self::assertSame( 'ASC', $args['order'] );
		self::assertTrue( $args['no_found_rows'] );
		self::assertArrayNotHasKey( 's', $args );
		self::assertArrayNotHasKey( 'post_mime_type', $args );
		self::assertArrayNotHasKey( 'post_parent', $args );
	}

	public function test_query_args_apply_forwarded_filters(): void {
		$args = LibraryCsvExporter::build_query_args( 'banner', 'image', 'unattached' );

		self::assertSame( 'banner', $args['s'] );
		self::assertSame( 'image', $args['post_mime_type'] );
		self::assertSame( 0, $args['post_parent'] );
	}

	// -------------------------------------------------------------------------
	// Header row
	// -------------------------------------------------------------------------

	public function test_header_row_uses_registry_labels_in_column_order(): void {
		$flat    = LibraryColumnRegistry::flat();
		$columns = array( 'id', 'title', 'full_url' );

		self::assertSame(
			array( $flat['id']['label'], $flat['title']['label'], $flat['full_url']['label'] ),
			LibraryCsvExporter::headers( $columns )
		);
	}

	public function test_header_falls_back_to_key_for_unknown_column(): void {
		self::assertSame( array( 'mystery' ), LibraryCsvExporter::headers( array( 'mystery' ) ) );
	}

	// -------------------------------------------------------------------------
	// Data rows
	// -------------------------------------------------------------------------

	public function test_rows_emit_one_ordered_value_array_per_attachment(): void {
		$posts = array(
			new \WP_Post( array( 'ID' => 1, 'post_title' => 'Alpha' ) ),
			new \WP_Post( array( 'ID' => 2, 'post_title' => 'Beta' ) ),
		);

		$rows = LibraryCsvExporter::rows( $posts, array( 'id', 'title' ) );

		self::assertSame( array( array( '1', 'Alpha' ), array( '2', 'Beta' ) ), $rows );
	}

	public function test_rows_neutralise_csv_formula_injection_in_user_fields(): void {
		// A malicious attachment title beginning with a formula trigger must be
		// prefixed with a single quote so spreadsheets render it as text, not a
		// live formula (CWE-1236).
		$posts = array(
			new \WP_Post( array( 'ID' => 1, 'post_title' => '=cmd|\'/c calc\'!A1' ) ),
			new \WP_Post( array( 'ID' => 2, 'post_title' => '+1+1' ) ),
			new \WP_Post( array( 'ID' => 3, 'post_title' => 'Safe Title' ) ),
		);

		$rows = LibraryCsvExporter::rows( $posts, array( 'id', 'title' ) );

		self::assertSame( "'=cmd|'/c calc'!A1", $rows[0][1] );
		self::assertSame( "'+1+1", $rows[1][1] );
		self::assertSame( 'Safe Title', $rows[2][1] );
	}

	public function test_rows_skip_non_wp_post_entries(): void {
		$posts = array(
			new \WP_Post( array( 'ID' => 1, 'post_title' => 'Ok' ) ),
			'not-a-post',
			null,
		);

		self::assertSame( array( array( '1', 'Ok' ) ), LibraryCsvExporter::rows( $posts, array( 'id', 'title' ) ) );
	}

	public function test_filename_is_dated_csv(): void {
		self::assertSame( 'media-list-' . gmdate( 'Y-m-d' ) . '.csv', LibraryCsvExporter::filename() );
	}
}
