<?php

namespace CFMediaManager\Tests;

use CFMediaManager\AltTextManager;
use CFShared\Media\InUseScanner;
use CFShared\Media\Paths;
use CFMediaManagerHttpExit;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the Accessibility-tab AJAX surface (alt_list / alt_save).
 *
 * The original version of this file was a smoke-only suite ("constants
 * are stable, hooks are registered") because the DB-touching paths
 * needed shims the test suite didn't carry. As of remediation pass B1
 * the bootstrap has a minimal `WP_Query` shim + post-store fixture
 * (`$GLOBALS['cf_media_manager_test_posts']`), so end-to-end coverage
 * of the endpoints is now feasible.
 *
 * What's covered:
 *   - Constants & registration smoke (legacy assertions, retained)
 *   - alt_list filter modes (all / missing / in_use / in_use_missing)
 *   - alt_list pagination + per_page clamping
 *   - alt_save validation paths (bad id, non-image, invalid post_type)
 *   - alt_save round-trip: meta write, decorative flag set/clear
 *   - build_item shape and the server-computed `missing` boolean
 *
 * The capability gate itself is exercised in `SecurityHardeningTest`;
 * this file grants `manage_options` in setUp so the endpoint bodies
 * run.
 */
final class AltTextManagerTest extends TestCase {

	private string $sandbox = '';

	protected function setUp(): void {
		cf_media_manager_test_reset_state();
		$_POST = array();

		// Grant manage_options so authorize() passes. The cap gate is
		// covered independently in SecurityHardeningTest.
		$GLOBALS['cf_media_manager_test_user_caps'] = array( 'manage_options' => true );

		$this->sandbox = sys_get_temp_dir() . '/cf-media-manager-alt-' . uniqid();
		mkdir( $this->sandbox . '/uploads', 0777, true );
	}

	protected function tearDown(): void {
		$_POST = array();
		if ( '' !== $this->sandbox ) {
			$this->rrmdir( $this->sandbox );
		}
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) && ! is_link( $path ) ? $this->rrmdir( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}

	private function make_manager(): AltTextManager {
		$paths = new Paths( $this->sandbox . '/uploads', 'https://example.test/wp-content/uploads' );
		return new AltTextManager( new InUseScanner( $paths ) );
	}

	/**
	 * Seed an attachment in the test post store. Defaults match a typical
	 * image attachment shape; pass `alt`, `decorative`, `file`, etc. to
	 * override.
	 */
	private function seed_attachment( int $id, array $overrides = array() ): void {
		$post = array_merge(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image/jpeg',
				'thumb_src'      => 'https://example.test/thumb-' . $id . '.jpg',
			),
			$overrides
		);
		$GLOBALS['cf_media_manager_test_posts'][ $id ] = $post;

		if ( isset( $overrides['alt'] ) ) {
			$GLOBALS['cf_media_manager_test_post_meta'][ $id ][ AltTextManager::META_KEY_ALT ] = $overrides['alt'];
		}
		if ( ! empty( $overrides['decorative'] ) ) {
			$GLOBALS['cf_media_manager_test_post_meta'][ $id ][ AltTextManager::META_KEY_DECORATIVE ] = '1';
		}
		if ( isset( $overrides['file'] ) ) {
			$GLOBALS['cf_media_manager_test_post_meta'][ $id ]['_wp_attached_file'] = $overrides['file'];
		}
	}

	/**
	 * Pre-seed the in-use-scan transient so in_use filters have data to
	 * intersect against. Uses reflection to read the production cache_key
	 * derivation, so this test follows any future change to the key.
	 */
	private function seed_in_use_scan( array $ids ): void {
		$paths   = new Paths( $this->sandbox . '/uploads', 'https://example.test/wp-content/uploads' );
		$scanner = new InUseScanner( $paths );
		$ref     = new \ReflectionMethod( InUseScanner::class, 'cache_key' );
		$key     = $ref->invoke( $scanner );

		set_transient(
			$key,
			array(
				'ids'        => $ids,
				'scanned_at' => time(),
				'sources'    => array(),
				'builders'   => array(),
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Invoke an AJAX endpoint and capture the wp_send_json_* exit via the
	 * bootstrap's CFMediaManagerHttpExit exception.
	 */
	private function invoke( callable $endpoint ): CFMediaManagerHttpExit {
		try {
			$endpoint();
			$this->fail( 'endpoint did not call wp_send_json_*' );
		} catch ( CFMediaManagerHttpExit $e ) {
			return $e;
		}
		return new CFMediaManagerHttpExit( false, null, 0 ); // unreachable
	}

	public function test_meta_key_constants_are_stable(): void {
		// These names are part of the on-disk contract — once shipped, changing
		// them silently breaks "decorative" flags users have set. Lock them in.
		self::assertSame( '_wp_attachment_image_alt', AltTextManager::META_KEY_ALT );
		self::assertSame( '_cf_media_manager_decorative', AltTextManager::META_KEY_DECORATIVE );
	}

	public function test_valid_filters_includes_all_documented_modes(): void {
		// The JS sends one of these strings; the PHP rejects anything else and
		// falls back to 'all'. Both ends need to agree on the vocabulary.
		self::assertContains( 'all', AltTextManager::VALID_FILTERS );
		self::assertContains( 'missing', AltTextManager::VALID_FILTERS );
		self::assertContains( 'in_use', AltTextManager::VALID_FILTERS );
		self::assertContains( 'in_use_missing', AltTextManager::VALID_FILTERS );
	}

	public function test_page_size_bounds_are_sensible(): void {
		self::assertGreaterThan( 0, AltTextManager::PAGE_SIZE );
		self::assertLessThanOrEqual( AltTextManager::MAX_PAGE_SIZE, AltTextManager::PAGE_SIZE );
		// MAX_PAGE_SIZE caps the AJAX response size — should be modest enough
		// to keep response payloads bounded even on a high-megapixel site
		// where each thumb URL adds 100+ bytes.
		self::assertLessThanOrEqual( 200, AltTextManager::MAX_PAGE_SIZE );
	}

	public function test_register_hooks_adds_both_ajax_actions(): void {
		$paths   = new Paths( sys_get_temp_dir(), 'http://example.test/uploads' );
		$scanner = new InUseScanner( $paths );
		$manager = new AltTextManager( $scanner );

		$manager->register_hooks();

		// The bootstrap's add_action shim records under $GLOBALS['cf_media_manager_test_hooks'].
		// Both endpoints must be registered or the JS panel hangs on "Loading…".
		$hooks = $GLOBALS['cf_media_manager_test_hooks'] ?? array();
		self::assertArrayHasKey( 'wp_ajax_cf_media_manager_alt_list', $hooks );
		self::assertArrayHasKey( 'wp_ajax_cf_media_manager_alt_save', $hooks );
		self::assertArrayHasKey( 'wp_ajax_cf_media_manager_alt_save_bulk', $hooks );
	}

	// =========================================================================
	// alt_save — validation & happy-path
	// =========================================================================

	public function test_save_rejects_zero_or_negative_id(): void {
		$_POST['id'] = 0;
		$exit = $this->invoke( array( $this->make_manager(), 'ajax_save_alt' ) );
		self::assertFalse( $exit->success );
		self::assertSame( 400, $exit->status );
	}

	public function test_save_rejects_non_attachment_post(): void {
		$GLOBALS['cf_media_manager_test_posts'][7] = array(
			'post_type'      => 'page',
			'post_mime_type' => '',
		);
		$_POST['id'] = 7;
		$exit        = $this->invoke( array( $this->make_manager(), 'ajax_save_alt' ) );
		self::assertFalse( $exit->success );
		self::assertSame( 400, $exit->status );
	}

	public function test_save_rejects_non_image_attachment(): void {
		$this->seed_attachment( 8, array( 'post_mime_type' => 'application/pdf' ) );
		$_POST['id'] = 8;
		$exit        = $this->invoke( array( $this->make_manager(), 'ajax_save_alt' ) );
		self::assertFalse( $exit->success );
		self::assertSame( 400, $exit->status );
	}

	public function test_save_writes_alt_text_to_postmeta(): void {
		$this->seed_attachment( 10 );
		$_POST['id']         = 10;
		$_POST['alt']        = 'A red panda yawning';
		$_POST['decorative'] = 0;

		$exit = $this->invoke( array( $this->make_manager(), 'ajax_save_alt' ) );

		self::assertTrue( $exit->success );
		self::assertSame(
			'A red panda yawning',
			get_post_meta( 10, AltTextManager::META_KEY_ALT, true )
		);
		self::assertSame(
			'',
			get_post_meta( 10, AltTextManager::META_KEY_DECORATIVE, true ),
			'decorative=0 must not leave the flag set'
		);
		self::assertSame( 10, $exit->data['id'] );
		self::assertSame( 'A red panda yawning', $exit->data['alt'] );
		self::assertFalse( $exit->data['decorative'] );
		self::assertFalse( $exit->data['missing'] );
	}

	public function test_save_sets_decorative_flag_and_clears_when_unchecked(): void {
		$this->seed_attachment( 11 );

		// First save: decorative=1, empty alt — flag SET, not missing.
		$_POST['id']         = 11;
		$_POST['alt']        = '';
		$_POST['decorative'] = 1;
		$exit = $this->invoke( array( $this->make_manager(), 'ajax_save_alt' ) );
		self::assertTrue( $exit->success );
		self::assertSame(
			'1',
			get_post_meta( 11, AltTextManager::META_KEY_DECORATIVE, true ),
			'decorative=1 must set the meta flag'
		);
		self::assertFalse( $exit->data['missing'], 'decorative + empty alt is intentional, not missing' );

		// Second save: decorative=0, still empty alt — flag CLEARED, now missing.
		$_POST['decorative'] = 0;
		$exit = $this->invoke( array( $this->make_manager(), 'ajax_save_alt' ) );
		self::assertTrue( $exit->success );
		self::assertSame(
			'',
			get_post_meta( 11, AltTextManager::META_KEY_DECORATIVE, true ),
			'decorative=0 must delete the flag'
		);
		self::assertTrue( $exit->data['missing'], 'empty alt without decorative is missing' );
	}

	// =========================================================================
	// alt_save_bulk — multi-row write
	// =========================================================================

	public function test_bulk_save_writes_alt_and_decorative_across_rows(): void {
		$this->seed_attachment( 20 );
		$this->seed_attachment( 21 );
		$this->seed_attachment( 22 );

		$_POST['ids'] = array( 20, 21, 22 );
		$_POST['alt'] = array(
			20 => 'First photo',
			21 => 'Second photo',
			22 => '', // left empty, marked decorative below
		);
		$_POST['decorative'] = array( 22 );

		$exit = $this->invoke( array( $this->make_manager(), 'ajax_save_alt_bulk' ) );

		self::assertTrue( $exit->success );
		self::assertSame( 3, $exit->data['saved'] );
		self::assertSame( 0, $exit->data['skipped'] );

		self::assertSame( 'First photo', get_post_meta( 20, AltTextManager::META_KEY_ALT, true ) );
		self::assertSame( 'Second photo', get_post_meta( 21, AltTextManager::META_KEY_ALT, true ) );
		self::assertSame( '', get_post_meta( 22, AltTextManager::META_KEY_ALT, true ) );
		self::assertSame( '1', get_post_meta( 22, AltTextManager::META_KEY_DECORATIVE, true ) );

		// Returned items mirror build_item() so the JS can swap rows in place.
		$ids = array_column( $exit->data['items'], 'id' );
		sort( $ids );
		self::assertSame( array( 20, 21, 22 ), $ids );
	}

	public function test_bulk_save_clears_decorative_when_not_listed(): void {
		$this->seed_attachment( 30, array( 'alt' => '', 'decorative' => true ) );

		// Same id submitted with real alt and NOT in the decorative list —
		// the flag must be cleared.
		$_POST['ids']        = array( 30 );
		$_POST['alt']        = array( 30 => 'Now described' );
		$_POST['decorative'] = array();

		$exit = $this->invoke( array( $this->make_manager(), 'ajax_save_alt_bulk' ) );

		self::assertTrue( $exit->success );
		self::assertSame( 'Now described', get_post_meta( 30, AltTextManager::META_KEY_ALT, true ) );
		self::assertSame(
			'',
			get_post_meta( 30, AltTextManager::META_KEY_DECORATIVE, true ),
			'id absent from decorative[] must clear the flag'
		);
	}

	public function test_bulk_save_skips_non_image_and_missing_ids(): void {
		$this->seed_attachment( 40 );                                       // valid image
		$this->seed_attachment( 41, array( 'post_mime_type' => 'application/pdf' ) ); // not an image
		// 42 is never seeded — get_post_type() returns '' so it's skipped.

		$_POST['ids'] = array( 40, 41, 42 );
		$_POST['alt'] = array(
			40 => 'Valid',
			41 => 'Should be skipped',
			42 => 'Ghost',
		);

		$exit = $this->invoke( array( $this->make_manager(), 'ajax_save_alt_bulk' ) );

		self::assertTrue( $exit->success );
		self::assertSame( 1, $exit->data['saved'] );
		self::assertSame( 2, $exit->data['skipped'] );
		self::assertSame( 'Valid', get_post_meta( 40, AltTextManager::META_KEY_ALT, true ) );
		self::assertSame( '', get_post_meta( 41, AltTextManager::META_KEY_ALT, true ) );
	}

	public function test_bulk_save_with_no_ids_saves_nothing(): void {
		$_POST['ids'] = array();
		$exit = $this->invoke( array( $this->make_manager(), 'ajax_save_alt_bulk' ) );

		self::assertTrue( $exit->success );
		self::assertSame( 0, $exit->data['saved'] );
		self::assertSame( array(), $exit->data['items'] );
	}

	// =========================================================================
	// alt_list — filter modes
	// =========================================================================

	public function test_list_returns_all_image_attachments_for_default_filter(): void {
		$this->seed_attachment( 1, array( 'alt' => 'alpha' ) );
		$this->seed_attachment( 2, array( 'alt' => '' ) );
		$this->seed_attachment( 3, array( 'alt' => 'gamma', 'decorative' => true ) );
		$this->seed_attachment( 4, array( 'post_mime_type' => 'application/pdf', 'alt' => 'doc' ) ); // not an image

		$_POST['filter'] = 'all';
		$exit = $this->invoke( array( $this->make_manager(), 'ajax_list_alts' ) );

		self::assertTrue( $exit->success );
		self::assertSame( 3, $exit->data['total'] );
		$ids = array_column( $exit->data['items'], 'id' );
		sort( $ids );
		self::assertSame( array( 1, 2, 3 ), $ids );
	}

	public function test_list_missing_filter_includes_empty_alt_and_excludes_decorative(): void {
		$this->seed_attachment( 1, array( 'alt' => 'alpha' ) );                       // not missing
		$this->seed_attachment( 2, array( 'alt' => '' ) );                            // MISSING
		$this->seed_attachment( 3, array( 'alt' => '', 'decorative' => true ) );      // intentionally empty
		$this->seed_attachment( 4 );                                                  // no alt meta row at all — MISSING

		$_POST['filter'] = 'missing';
		$exit = $this->invoke( array( $this->make_manager(), 'ajax_list_alts' ) );

		self::assertTrue( $exit->success );
		$ids = array_column( $exit->data['items'], 'id' );
		sort( $ids );
		self::assertSame( array( 2, 4 ), $ids );
	}

	public function test_list_in_use_filter_intersects_against_scanner_result(): void {
		$this->seed_attachment( 1 );
		$this->seed_attachment( 2 );
		$this->seed_attachment( 3 );
		$this->seed_in_use_scan( array( 1, 3 ) );

		$_POST['filter'] = 'in_use';
		$exit = $this->invoke( array( $this->make_manager(), 'ajax_list_alts' ) );

		self::assertTrue( $exit->success );
		$ids = array_column( $exit->data['items'], 'id' );
		sort( $ids );
		self::assertSame( array( 1, 3 ), $ids );
	}

	public function test_list_in_use_missing_is_intersection(): void {
		$this->seed_attachment( 1, array( 'alt' => 'alpha' ) ); // in_use but NOT missing
		$this->seed_attachment( 2, array( 'alt' => '' ) );      // in_use AND missing
		$this->seed_attachment( 3, array( 'alt' => '' ) );      // missing but NOT in_use
		$this->seed_attachment( 4 );                            // in_use AND missing
		$this->seed_in_use_scan( array( 1, 2, 4 ) );

		$_POST['filter'] = 'in_use_missing';
		$exit = $this->invoke( array( $this->make_manager(), 'ajax_list_alts' ) );

		self::assertTrue( $exit->success );
		$ids = array_column( $exit->data['items'], 'id' );
		sort( $ids );
		self::assertSame( array( 2, 4 ), $ids );
	}

	public function test_list_in_use_with_empty_scanner_returns_zero_total(): void {
		$this->seed_attachment( 1 );
		$this->seed_in_use_scan( array() );

		$_POST['filter'] = 'in_use';
		$exit = $this->invoke( array( $this->make_manager(), 'ajax_list_alts' ) );

		self::assertTrue( $exit->success );
		self::assertSame( 0, $exit->data['total'] );
		self::assertSame( array(), $exit->data['items'] );
	}

	public function test_list_invalid_filter_falls_back_to_all(): void {
		$this->seed_attachment( 1 );
		$_POST['filter'] = 'not-a-real-filter';
		$exit            = $this->invoke( array( $this->make_manager(), 'ajax_list_alts' ) );

		self::assertSame( 'all', $exit->data['filter'] );
	}

	// =========================================================================
	// alt_list — pagination
	// =========================================================================

	public function test_list_clamps_per_page_to_max(): void {
		$_POST['filter']   = 'all';
		$_POST['per_page'] = 9999;
		$exit              = $this->invoke( array( $this->make_manager(), 'ajax_list_alts' ) );

		self::assertSame( AltTextManager::MAX_PAGE_SIZE, $exit->data['per_page'] );
	}

	public function test_list_floors_per_page_to_one(): void {
		$_POST['filter']   = 'all';
		$_POST['per_page'] = 0;
		$exit              = $this->invoke( array( $this->make_manager(), 'ajax_list_alts' ) );

		self::assertSame( 1, $exit->data['per_page'] );
	}

	public function test_list_pagination_returns_correct_page(): void {
		for ( $i = 1; $i <= 30; $i++ ) {
			$this->seed_attachment( $i );
		}
		$_POST['filter']   = 'all';
		$_POST['per_page'] = 10;
		$_POST['page']     = 2;
		$exit              = $this->invoke( array( $this->make_manager(), 'ajax_list_alts' ) );

		self::assertSame( 30, $exit->data['total'] );
		self::assertSame( 3, $exit->data['total_pages'] );
		self::assertCount( 10, $exit->data['items'] );
	}

	// =========================================================================
	// build_item shape
	// =========================================================================

	public function test_build_item_marks_empty_alt_without_decorative_as_missing(): void {
		$this->seed_attachment( 99, array( 'file' => '2026/05/photo.jpg' ) );
		$_POST['filter'] = 'all';
		$exit = $this->invoke( array( $this->make_manager(), 'ajax_list_alts' ) );

		$item = $exit->data['items'][0];
		self::assertSame( 99, $item['id'] );
		self::assertSame( 'photo.jpg', $item['filename'] );
		self::assertTrue( $item['missing'] );
		self::assertFalse( $item['decorative'] );
		self::assertStringContainsString( 'post.php?post=99', $item['edit_url'] );
		// The full-size source powers the click-to-enlarge popup.
		self::assertArrayHasKey( 'full', $item );
	}
}
