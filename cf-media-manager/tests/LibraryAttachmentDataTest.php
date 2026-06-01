<?php

namespace CFMediaManager\Tests;

use CFMediaManager\LibraryAttachmentData;
use CFMediaManager\LibraryColumnRegistry;
use PHPUnit\Framework\TestCase;

/**
 * LibraryAttachmentData::resolve() has 40+ branches. The first test locks
 * in the type contract for every registered column (always a string, never
 * null/bool/array). The remaining tests target the branches with real
 * transformation logic where a silent regression is most likely.
 */
final class LibraryAttachmentDataTest extends TestCase {

	protected function setUp(): void {
		cf_media_manager_test_reset_state();
	}

	private function post( array $props = array() ): \WP_Post {
		return new \WP_Post( array_merge( array( 'ID' => 1 ), $props ) );
	}

	// -------------------------------------------------------------------------
	// Type contract — every column key, every code path, returns a string.
	// -------------------------------------------------------------------------

	public function test_every_column_returns_a_string_for_a_bare_attachment(): void {
		$post = $this->post( array( 'ID' => 99, 'post_title' => 'X' ) );
		$keys = LibraryColumnRegistry::valid_keys();

		$row = LibraryAttachmentData::for_post( $post, $keys );

		self::assertSame( $keys, array_keys( $row ), 'Row keys must match requested columns, in order.' );
		foreach ( $row as $key => $value ) {
			self::assertIsString( $value, "Column '{$key}' must resolve to a string." );
		}
	}

	public function test_every_column_returns_a_string_with_rich_metadata_present(): void {
		$post = $this->post( array(
			'ID'          => 42,
			'post_parent' => 7,
			'post_author' => 3,
		) );

		$GLOBALS['cf_media_manager_test_attachments'][42] = array(
			'url'  => 'https://ex.com/wp-content/uploads/2024/05/p.jpg',
			'meta' => array(
				'width'      => 1200,
				'height'     => 800,
				'sizes'      => array( 'thumbnail' => array(), 'medium' => array() ),
				'image_meta' => array(
					'camera'            => 'Canon EOS R5',
					'aperture'          => '2.8',
					'shutter_speed'     => '0.004',
					'iso'               => '400',
					'focal_length'      => '50',
					'created_timestamp' => 1700000000,
					'orientation'       => '1',
					'color_space'       => 'sRGB',
				),
			),
		);
		$GLOBALS['cf_media_manager_test_post_meta'][42]['_wp_attachment_image_alt'] = 'A photo';
		$GLOBALS['cf_media_manager_test_post_objects'][7] = new \WP_Post( array( 'ID' => 7, 'post_title' => 'Parent', 'post_type' => 'page' ) );
		$GLOBALS['cf_media_manager_test_users'][3]        = (object) array( 'user_login' => 'cai', 'display_name' => 'Cai F' );

		foreach ( LibraryAttachmentData::for_post( $post, LibraryColumnRegistry::valid_keys() ) as $key => $value ) {
			self::assertIsString( $value, "Column '{$key}' must resolve to a string." );
		}
	}

	// -------------------------------------------------------------------------
	// has_alt
	// -------------------------------------------------------------------------

	public function test_has_alt_marks_present_and_absent_alt_text(): void {
		$post = $this->post();

		$GLOBALS['cf_media_manager_test_post_meta'][1]['_wp_attachment_image_alt'] = 'desc';
		self::assertSame( '✓', LibraryAttachmentData::for_post( $post, array( 'has_alt' ) )['has_alt'] );

		$GLOBALS['cf_media_manager_test_post_meta'][1]['_wp_attachment_image_alt'] = '   ';
		self::assertSame( '✗', LibraryAttachmentData::for_post( $post, array( 'has_alt' ) )['has_alt'] );

		unset( $GLOBALS['cf_media_manager_test_post_meta'][1] );
		self::assertSame( '✗', LibraryAttachmentData::for_post( $post, array( 'has_alt' ) )['has_alt'] );
	}

	// -------------------------------------------------------------------------
	// EXIF transformations
	// -------------------------------------------------------------------------

	public function test_exif_shutter_converts_sub_second_decimal_to_fraction(): void {
		$post = $this->post();
		$GLOBALS['cf_media_manager_test_attachments'][1] = array(
			'meta' => array( 'image_meta' => array( 'shutter_speed' => '0.004' ) ),
		);

		self::assertSame( '1/250', LibraryAttachmentData::for_post( $post, array( 'exif_shutter' ) )['exif_shutter'] );
	}

	public function test_exif_shutter_passes_through_whole_second_exposures(): void {
		$post = $this->post();
		$GLOBALS['cf_media_manager_test_attachments'][1] = array(
			'meta' => array( 'image_meta' => array( 'shutter_speed' => '2' ) ),
		);

		self::assertSame( '2', LibraryAttachmentData::for_post( $post, array( 'exif_shutter' ) )['exif_shutter'] );
	}

	public function test_exif_aperture_and_focal_length_are_formatted(): void {
		$post = $this->post();
		$GLOBALS['cf_media_manager_test_attachments'][1] = array(
			'meta' => array( 'image_meta' => array( 'aperture' => '2.8', 'focal_length' => '50' ) ),
		);

		$row = LibraryAttachmentData::for_post( $post, array( 'exif_aperture', 'exif_focal_length' ) );
		self::assertSame( 'f/2.8', $row['exif_aperture'] );
		self::assertSame( '50 mm', $row['exif_focal_length'] );
	}

	public function test_exif_created_at_formats_timestamp_as_utc(): void {
		$post = $this->post();
		$GLOBALS['cf_media_manager_test_attachments'][1] = array(
			'meta' => array( 'image_meta' => array( 'created_timestamp' => 1700000000 ) ),
		);

		self::assertSame(
			gmdate( 'Y-m-d H:i:s', 1700000000 ),
			LibraryAttachmentData::for_post( $post, array( 'exif_created_at' ) )['exif_created_at']
		);
	}

	// -------------------------------------------------------------------------
	// Dimensions
	// -------------------------------------------------------------------------

	public function test_dimensions_empty_for_non_image_and_formatted_for_image(): void {
		$post = $this->post();
		self::assertSame( '', LibraryAttachmentData::for_post( $post, array( 'dimensions' ) )['dimensions'] );

		$GLOBALS['cf_media_manager_test_attachments'][1] = array(
			'meta' => array( 'width' => 1920, 'height' => 1080 ),
		);
		self::assertSame( '1920 × 1080', LibraryAttachmentData::for_post( $post, array( 'dimensions' ) )['dimensions'] );
	}

	// -------------------------------------------------------------------------
	// relative_path host stripping
	// -------------------------------------------------------------------------

	public function test_relative_path_strips_scheme_and_host(): void {
		$post = $this->post();
		$GLOBALS['cf_media_manager_test_attachments'][1] = array(
			'url' => 'https://example.com/wp-content/uploads/2024/05/p.jpg',
		);

		self::assertSame(
			'/wp-content/uploads/2024/05/p.jpg',
			LibraryAttachmentData::for_post( $post, array( 'relative_path' ) )['relative_path']
		);
	}

	// -------------------------------------------------------------------------
	// File size reads the real filesystem.
	// -------------------------------------------------------------------------

	public function test_file_size_reads_filesystem_and_is_empty_when_missing(): void {
		$post = $this->post();

		self::assertSame( '', LibraryAttachmentData::for_post( $post, array( 'file_size' ) )['file_size'] );

		$tmp = tempnam( sys_get_temp_dir(), 'cfmlv' );
		file_put_contents( $tmp, str_repeat( 'x', 2048 ) );
		$GLOBALS['cf_media_manager_test_attachments'][1] = array( 'file' => $tmp );

		$row = LibraryAttachmentData::for_post( $post, array( 'file_size', 'file_size_bytes' ) );
		self::assertSame( '2 KB', $row['file_size'] );
		self::assertSame( '2048', $row['file_size_bytes'] );

		unlink( $tmp );
	}

	// -------------------------------------------------------------------------
	// Parent / author lookups
	// -------------------------------------------------------------------------

	public function test_parent_columns_resolve_via_get_post(): void {
		$post = $this->post( array( 'post_parent' => 5 ) );
		$GLOBALS['cf_media_manager_test_post_objects'][5] = new \WP_Post( array(
			'ID'         => 5,
			'post_title' => 'Home',
			'post_type'  => 'page',
		) );

		$row = LibraryAttachmentData::for_post( $post, array( 'parent_id', 'parent_title', 'parent_type', 'is_unattached' ) );
		self::assertSame( '5', $row['parent_id'] );
		self::assertSame( 'Home', $row['parent_title'] );
		self::assertSame( 'page', $row['parent_type'] );
		self::assertSame( 'false', $row['is_unattached'] );
	}

	public function test_unattached_post_reports_empty_parent_and_true_flag(): void {
		$post = $this->post( array( 'post_parent' => 0 ) );
		$row  = LibraryAttachmentData::for_post( $post, array( 'parent_title', 'parent_type', 'is_unattached' ) );

		self::assertSame( '', $row['parent_title'] );
		self::assertSame( '', $row['parent_type'] );
		self::assertSame( 'true', $row['is_unattached'] );
	}

	public function test_author_columns_resolve_via_get_userdata(): void {
		$post = $this->post( array( 'post_author' => 9 ) );
		$GLOBALS['cf_media_manager_test_users'][9] = (object) array( 'user_login' => 'cai', 'display_name' => 'Cai Frazier' );

		$row = LibraryAttachmentData::for_post( $post, array( 'author_id', 'author_login', 'author_display_name' ) );
		self::assertSame( '9', $row['author_id'] );
		self::assertSame( 'cai', $row['author_login'] );
		self::assertSame( 'Cai Frazier', $row['author_display_name'] );
	}

	// -------------------------------------------------------------------------
	// Description is tag-stripped; unknown column is empty.
	// -------------------------------------------------------------------------

	public function test_description_strips_html(): void {
		$post = $this->post( array( 'post_content' => '<p>Hello <strong>world</strong></p>' ) );
		self::assertSame( 'Hello world', LibraryAttachmentData::for_post( $post, array( 'description' ) )['description'] );
	}

	public function test_unknown_column_resolves_to_empty_string(): void {
		$row = LibraryAttachmentData::for_post( $this->post(), array( 'no_such_column' ) );
		self::assertSame( '', $row['no_such_column'] );
	}

	// -------------------------------------------------------------------------
	// Lazy metadata: loaded at most once per row regardless of column count.
	// -------------------------------------------------------------------------

	public function test_attachment_metadata_is_loaded_at_most_once_per_row(): void {
		$post = $this->post();
		$GLOBALS['cf_media_manager_test_attachments'][1] = array(
			'meta' => array(
				'width'      => 100,
				'height'     => 50,
				'sizes'      => array( 'thumbnail' => array() ),
				'image_meta' => array( 'camera' => 'Nikon', 'iso' => '200' ),
			),
		);

		LibraryAttachmentData::for_post( $post, array(
			'dimensions', 'width', 'height', 'num_sizes', 'sizes_list',
			'exif_camera', 'exif_iso', 'color_space',
		) );

		self::assertSame( 1, $GLOBALS['cf_media_manager_test_meta_calls'] );
	}

	public function test_metadata_not_loaded_when_no_column_needs_it(): void {
		LibraryAttachmentData::for_post( $this->post( array( 'post_title' => 'T' ) ), array( 'id', 'title', 'slug' ) );
		self::assertSame( 0, $GLOBALS['cf_media_manager_test_meta_calls'] );
	}
}
