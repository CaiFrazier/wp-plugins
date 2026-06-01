<?php

namespace CFMediaManager\Tests;

use CFMediaManager\MimeDoctor;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pure classification logic. fetch_candidate_rows() and
 * repair() touch $wpdb and are smoke-checked by the WP-CLI command on a
 * real site — building a full wpdb shim for them isn't worth it for what
 * amounts to a single SELECT + UPDATE pair.
 */
final class MimeDoctorTest extends TestCase {

	// -----------------------------------------------------------------------
	// expected_mime
	// -----------------------------------------------------------------------

	public function test_expected_mime_for_jpg(): void {
		self::assertSame( 'image/jpeg', MimeDoctor::expected_mime( '2024/01/photo.jpg' ) );
	}

	public function test_expected_mime_for_jpeg(): void {
		self::assertSame( 'image/jpeg', MimeDoctor::expected_mime( '2024/01/photo.jpeg' ) );
	}

	public function test_expected_mime_for_png(): void {
		self::assertSame( 'image/png', MimeDoctor::expected_mime( '2024/01/photo.PNG' ) );
	}

	public function test_expected_mime_is_null_for_unrelated_extensions(): void {
		self::assertNull( MimeDoctor::expected_mime( '2024/01/icon.gif' ) );
		self::assertNull( MimeDoctor::expected_mime( '2024/01/doc.pdf' ) );
		self::assertNull( MimeDoctor::expected_mime( '2024/01/photo.webp' ) );
		self::assertNull( MimeDoctor::expected_mime( '' ) );
	}

	// -----------------------------------------------------------------------
	// is_mismatch
	// -----------------------------------------------------------------------

	public function test_jpg_with_matching_mime_is_not_mismatch(): void {
		self::assertFalse( MimeDoctor::is_mismatch( 'image/jpeg', '2024/01/a.jpg' ) );
		self::assertFalse( MimeDoctor::is_mismatch( 'image/jpeg', '2024/01/a.jpeg' ) );
		self::assertFalse( MimeDoctor::is_mismatch( 'image/png',  '2024/01/a.png' ) );
	}

	public function test_blank_mime_on_jpg_is_mismatch(): void {
		self::assertTrue( MimeDoctor::is_mismatch( '', '2024/01/photo.jpg' ) );
	}

	public function test_wrong_mime_on_png_is_mismatch(): void {
		self::assertTrue( MimeDoctor::is_mismatch( 'image/jpeg', '2024/01/diagram.png' ) );
	}

	public function test_generic_mime_is_mismatch(): void {
		// CDN-imported attachments commonly land here.
		self::assertTrue( MimeDoctor::is_mismatch( 'application/octet-stream', '2024/01/photo.jpg' ) );
	}

	public function test_case_insensitive_mime_compare(): void {
		self::assertFalse( MimeDoctor::is_mismatch( 'IMAGE/JPEG', '2024/01/photo.jpg' ) );
	}

	public function test_unrelated_extension_is_never_mismatch(): void {
		// We don't claim authority over .gif / .pdf / .webp — even with a
		// weird mime row those aren't in scope for this plugin.
		self::assertFalse( MimeDoctor::is_mismatch( 'image/jpeg', '2024/01/anim.gif' ) );
		self::assertFalse( MimeDoctor::is_mismatch( '',           '2024/01/doc.pdf' ) );
	}

	// -----------------------------------------------------------------------
	// find_mismatches
	// -----------------------------------------------------------------------

	public function test_find_mismatches_filters_to_offenders_only(): void {
		$rows = array(
			array( 'ID' => 1, 'post_mime_type' => 'image/jpeg', 'file' => '2024/01/ok.jpg' ),
			array( 'ID' => 2, 'post_mime_type' => '',           'file' => '2024/01/blank-mime.jpg' ),
			array( 'ID' => 3, 'post_mime_type' => 'image/jpeg', 'file' => '2024/01/diagram.png' ),
			array( 'ID' => 4, 'post_mime_type' => 'image/png',  'file' => '2024/01/icon.png' ),
			array( 'ID' => 5, 'post_mime_type' => 'image/jpeg', 'file' => '2024/01/anim.gif' ),
		);

		$bad = MimeDoctor::find_mismatches( $rows );

		self::assertCount( 2, $bad );
		self::assertSame( 2, $bad[0]['ID'] );
		self::assertSame( 'image/jpeg', $bad[0]['expected_mime'] );
		self::assertSame( '', $bad[0]['current_mime'] );
		self::assertSame( 3, $bad[1]['ID'] );
		self::assertSame( 'image/png', $bad[1]['expected_mime'] );
	}

	public function test_find_mismatches_tolerates_missing_keys(): void {
		// A defensive row missing post_mime_type still gets classified
		// (missing -> empty string -> mismatch on a jpg row).
		$rows = array(
			array( 'ID' => 42, 'file' => '2024/02/lost.jpg' ),
		);

		$bad = MimeDoctor::find_mismatches( $rows );

		self::assertCount( 1, $bad );
		self::assertSame( 42, $bad[0]['ID'] );
		self::assertSame( 'image/jpeg', $bad[0]['expected_mime'] );
	}

	public function test_find_mismatches_empty_for_empty_input(): void {
		self::assertSame( array(), MimeDoctor::find_mismatches( array() ) );
	}
}
