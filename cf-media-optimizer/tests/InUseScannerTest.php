<?php

namespace CFMediaOptimizer\Tests;

use CFShared\Media\InUseScanner;
use PHPUnit\Framework\TestCase;

/**
 * Tests cover the pure extraction surface — url_to_relative_path() and
 * extract_refs_from_text(). The DB-backed scanners (post_content,
 * postmeta, theme mods) need a real wpdb shim that isn't worth building
 * for what amounts to thin glue over single SQL queries.
 */
final class InUseScannerTest extends TestCase {

	// -----------------------------------------------------------------------
	// url_to_relative_path
	// -----------------------------------------------------------------------

	public function test_url_to_relative_path_strips_host(): void {
		self::assertSame(
			'2024/01/photo.jpg',
			InUseScanner::url_to_relative_path( 'https://example.com/wp-content/uploads/2024/01/photo.jpg' )
		);
	}

	public function test_url_to_relative_path_strips_size_suffix(): void {
		self::assertSame(
			'2024/01/photo.jpg',
			InUseScanner::url_to_relative_path( 'https://example.com/wp-content/uploads/2024/01/photo-300x200.jpg' )
		);
	}

	public function test_url_to_relative_path_strips_query(): void {
		self::assertSame(
			'2024/01/photo.jpg',
			InUseScanner::url_to_relative_path( 'https://cdn.example.com/wp-content/uploads/2024/01/photo.jpg?v=12345' )
		);
	}

	public function test_url_to_relative_path_handles_root_relative(): void {
		self::assertSame(
			'2024/01/photo.png',
			InUseScanner::url_to_relative_path( '/wp-content/uploads/2024/01/photo.png' )
		);
	}

	public function test_url_to_relative_path_handles_cdn_rewrite(): void {
		self::assertSame(
			'2024/01/photo.jpg',
			InUseScanner::url_to_relative_path( 'https://cdn.cloudflare.example/wp-content/uploads/2024/01/photo-1024x768.jpg' )
		);
	}

	public function test_url_to_relative_path_rejects_non_upload(): void {
		self::assertNull( InUseScanner::url_to_relative_path( 'https://example.com/wp-content/plugins/foo/img.png' ) );
		self::assertNull( InUseScanner::url_to_relative_path( '' ) );
		self::assertNull( InUseScanner::url_to_relative_path( 'https://example.com/some/other/path.jpg' ) );
	}

	public function test_url_to_relative_path_strips_scaled_suffix(): void {
		self::assertSame(
			'2024/01/photo.jpg',
			InUseScanner::url_to_relative_path( 'https://example.com/wp-content/uploads/2024/01/photo-scaled.jpg' )
		);
	}

	public function test_url_to_relative_path_strips_edit_suffix(): void {
		self::assertSame(
			'2024/01/photo.jpg',
			InUseScanner::url_to_relative_path( 'https://example.com/wp-content/uploads/2024/01/photo-e1699999999.jpg' )
		);
	}

	public function test_url_to_relative_path_strips_scaled_and_size_suffix(): void {
		self::assertSame(
			'2024/01/photo.jpg',
			InUseScanner::url_to_relative_path( 'https://example.com/wp-content/uploads/2024/01/photo-scaled-1024x768.jpg' )
		);
	}

	public function test_url_to_relative_path_strips_edit_and_size_suffix(): void {
		self::assertSame(
			'2024/01/photo.png',
			InUseScanner::url_to_relative_path( 'https://example.com/wp-content/uploads/2024/01/photo-e1699999999-300x200.png' )
		);
	}

	public function test_url_to_relative_path_leaves_short_e_suffix_alone(): void {
		// "-e5" is not a WP edit timestamp (needs 9+ digits) — must not be
		// mistaken for one and stripped off a legitimate filename.
		self::assertSame(
			'2024/01/image-e5.jpg',
			InUseScanner::url_to_relative_path( 'https://example.com/wp-content/uploads/2024/01/image-e5.jpg' )
		);
	}

	// -----------------------------------------------------------------------
	// strip_edit_suffixes
	// -----------------------------------------------------------------------

	public function test_strip_edit_suffixes_handles_scaled_and_edit(): void {
		self::assertSame( '2024/01/a.jpg', InUseScanner::strip_edit_suffixes( '2024/01/a-scaled.jpg' ) );
		self::assertSame( '2024/01/a.png', InUseScanner::strip_edit_suffixes( '2024/01/a-e1700000000.png' ) );
		self::assertSame( '2024/01/plain.jpg', InUseScanner::strip_edit_suffixes( '2024/01/plain.jpg' ) );
	}

	// -----------------------------------------------------------------------
	// extract_ids_from_serialized_ids (ACF gallery / repeater)
	// -----------------------------------------------------------------------

	public function test_extract_serialized_ids_from_string_id_array(): void {
		// ACF gallery stores IDs as strings in a serialized array.
		$blob = serialize( array( '11', '12', '13' ) );
		$ids  = InUseScanner::extract_ids_from_serialized_ids( $blob );
		sort( $ids );
		self::assertSame( array( 11, 12, 13 ), $ids );
	}

	public function test_extract_serialized_ids_from_nested_array(): void {
		$blob = serialize( array( 'gallery' => array( 21, 22 ), 'other' => 'not-an-id' ) );
		$ids  = InUseScanner::extract_ids_from_serialized_ids( $blob );
		sort( $ids );
		self::assertSame( array( 21, 22 ), $ids );
	}

	public function test_extract_serialized_ids_ignores_non_arrays(): void {
		self::assertSame( array(), InUseScanner::extract_ids_from_serialized_ids( serialize( 'just-a-string' ) ) );
		self::assertSame( array(), InUseScanner::extract_ids_from_serialized_ids( 'not serialized at all' ) );
		self::assertSame( array(), InUseScanner::extract_ids_from_serialized_ids( '' ) );
	}

	// -----------------------------------------------------------------------
	// extract_refs_from_text
	// -----------------------------------------------------------------------

	public function test_extract_picks_up_wp_image_class(): void {
		$html = '<img src="https://example.com/wp-content/uploads/2024/01/hero.jpg" class="wp-image-42" />';
		$refs = InUseScanner::extract_refs_from_text( $html );
		self::assertContains( 42, $refs['ids'] );
		self::assertContains( '2024/01/hero.jpg', $refs['paths'] );
	}

	public function test_extract_handles_divi_shortcode(): void {
		// Divi stores image references inline in post_content via shortcodes,
		// almost always as URLs (not attachment IDs).
		$content = '[et_pb_image src="https://example.com/wp-content/uploads/2023/06/team-photo.jpg" _builder_version="4.21"]'
			. '[/et_pb_image]'
			. '[et_pb_section background_image="https://example.com/wp-content/uploads/2023/06/bg-1920x1080.jpg"]';

		$refs = InUseScanner::extract_refs_from_text( $content );
		self::assertContains( '2023/06/team-photo.jpg', $refs['paths'] );
		self::assertContains( '2023/06/bg.jpg', $refs['paths'] );
		self::assertSame( [], $refs['ids'] );
	}

	public function test_extract_handles_elementor_json(): void {
		// Realistic snippet from _elementor_data: image widgets carry both
		// the attachment ID and the URL.
		$json = '{"id":"abc","elType":"widget","settings":{"image":{"url":"https:\/\/example.com\/wp-content\/uploads\/2024\/02\/about.jpg","id":117}}}'
			. ',{"id":"def","settings":{"background_image":{"url":"https:\/\/example.com\/wp-content\/uploads\/2024\/02\/banner-2400x1200.png","id":118}}}';

		$refs = InUseScanner::extract_refs_from_text( $json );
		self::assertContains( '2024/02/about.jpg', $refs['paths'] );
		self::assertContains( '2024/02/banner.png', $refs['paths'] );
	}

	public function test_extract_handles_beaver_serialized(): void {
		// Beaver Builder stores _fl_builder_data as serialized PHP. The
		// photo_src field carries the upload URL.
		$serialized = 's:9:"photo_src";s:62:"https://example.com/wp-content/uploads/2024/03/product-photo.jpg";'
			. 's:3:"src";s:71:"https://example.com/wp-content/uploads/2024/03/banner-bg-1600x900.png";';

		$refs = InUseScanner::extract_refs_from_text( $serialized );
		self::assertContains( '2024/03/product-photo.jpg', $refs['paths'] );
		self::assertContains( '2024/03/banner-bg.png', $refs['paths'] );
	}

	public function test_extract_dedupes(): void {
		$content = str_repeat( '<img class="wp-image-99" src="/wp-content/uploads/2024/a.jpg">', 5 );
		$refs    = InUseScanner::extract_refs_from_text( $content );
		self::assertSame( [ 99 ], $refs['ids'] );
		self::assertSame( [ '2024/a.jpg' ], $refs['paths'] );
	}

	public function test_extract_ignores_non_image_extensions(): void {
		$content = 'A link to /wp-content/uploads/2024/document.pdf and a video /wp-content/uploads/2024/clip.mp4.';
		$refs    = InUseScanner::extract_refs_from_text( $content );
		self::assertSame( [], $refs['paths'] );
	}

	public function test_extract_handles_empty(): void {
		$refs = InUseScanner::extract_refs_from_text( '' );
		self::assertSame( [ 'ids' => [], 'paths' => [] ], $refs );
	}

	public function test_extract_handles_bricks_serialized(): void {
		// Bricks 1.4+ serializes page content as PHP. Image elements carry
		// both a numeric id and a url field — the URL is enough for the
		// generic extractor to resolve through the filename map.
		$serialized = 'a:1:{i:0;a:3:{s:2:"id";s:3:"abc";s:4:"name";s:5:"image";s:8:"settings";'
			. 'a:1:{s:5:"image";a:2:{s:2:"id";i:512;s:3:"url";'
			. 's:67:"https://example.com/wp-content/uploads/2024/05/bricks-hero-1920x1080.jpg";}}}}';

		$refs = InUseScanner::extract_refs_from_text( $serialized );
		self::assertContains( '2024/05/bricks-hero.jpg', $refs['paths'] );
	}

	// -----------------------------------------------------------------------
	// extract_wpbakery_shortcode_ids
	// -----------------------------------------------------------------------

	public function test_wpbakery_extracts_single_image_id(): void {
		$content = '[vc_single_image image="42" img_size="full" alignment="center"]';
		self::assertSame( [ 42 ], InUseScanner::extract_wpbakery_shortcode_ids( $content ) );
	}

	public function test_wpbakery_extracts_gallery_ids(): void {
		$content = '[vc_gallery type="image_grid" images="11,12,13" img_size="medium"]';
		$ids     = InUseScanner::extract_wpbakery_shortcode_ids( $content );
		sort( $ids );
		self::assertSame( [ 11, 12, 13 ], $ids );
	}

	public function test_wpbakery_dedupes_across_attributes(): void {
		$content = '[vc_single_image image="7"]'
			. '[vc_gallery images="7, 8, 9"]'
			. '[vc_single_image image="8"]';
		$ids     = InUseScanner::extract_wpbakery_shortcode_ids( $content );
		sort( $ids );
		self::assertSame( [ 7, 8, 9 ], $ids );
	}

	public function test_wpbakery_ignores_empty_or_zero(): void {
		$content = '[vc_single_image image="0"][vc_gallery images=""]';
		self::assertSame( [], InUseScanner::extract_wpbakery_shortcode_ids( $content ) );
	}

	public function test_wpbakery_handles_empty_input(): void {
		self::assertSame( [], InUseScanner::extract_wpbakery_shortcode_ids( '' ) );
	}

	public function test_extract_stops_at_url_boundaries(): void {
		// Make sure the URL regex doesn't run past quotes/whitespace/closing tags.
		$html = '<img src="https://example.com/wp-content/uploads/2024/inner.jpg" alt="and another image">'
			. '<a href="/wp-content/uploads/2024/other.png">link</a>';
		$refs = InUseScanner::extract_refs_from_text( $html );
		self::assertContains( '2024/inner.jpg', $refs['paths'] );
		self::assertContains( '2024/other.png', $refs['paths'] );
		// Should NOT have captured anything past the quote.
		foreach ( $refs['paths'] as $p ) {
			self::assertStringNotContainsString( '"', $p );
			self::assertStringNotContainsString( '>', $p );
		}
	}

	// =========================================================================
	// M6 — Builder fingerprint in cache key + plugin-activation invalidation
	// =========================================================================

	/**
	 * Returns the cache key the scanner would write to right now. Uses
	 * reflection so the test follows any future change to cache_key().
	 */
	private function current_cache_key(): string {
		$paths   = new \CFShared\Media\Paths( sys_get_temp_dir(), 'http://example.test/uploads' );
		$scanner = new InUseScanner( $paths );
		$ref     = new \ReflectionMethod( InUseScanner::class, 'cache_key' );
		return $ref->invoke( $scanner );
	}

	public function test_cache_key_starts_with_documented_prefix(): void {
		cf_media_manager_test_reset_state();
		self::assertStringStartsWith( InUseScanner::TRANSIENT_KEY . '_', $this->current_cache_key() );
	}

	public function test_cache_key_is_deterministic_for_same_builder_set(): void {
		cf_media_manager_test_reset_state();
		$a = $this->current_cache_key();
		$b = $this->current_cache_key();
		self::assertSame( $a, $b, 'identical builder state must produce identical cache key' );
	}

	public function test_cache_key_changes_when_builder_set_changes(): void {
		// Baseline — no builders active. `did_action('elementor/loaded')`
		// returns 0 so is_elementor_active() is false.
		cf_media_manager_test_reset_state();
		$before = $this->current_cache_key();

		// Activate Elementor via the did_action shim.
		$GLOBALS['cf_media_manager_test_did_action']['elementor/loaded'] = 1;
		$after = $this->current_cache_key();

		self::assertNotSame(
			$before,
			$after,
			'activating Elementor must produce a different cache key (fingerprint contributes to the suffix)'
		);
	}

	public function test_get_writes_under_fingerprinted_key(): void {
		cf_media_manager_test_reset_state();
		$paths   = new \CFShared\Media\Paths( sys_get_temp_dir(), 'http://example.test/uploads' );
		$scanner = new InUseScanner( $paths );

		$scanner->get( true ); // force scan + cache write

		$key = $this->current_cache_key();
		self::assertNotFalse(
			get_transient( $key ),
			'fresh scan must persist under the current builder fingerprint'
		);
	}

	public function test_invalidate_cache_deletes_only_current_fingerprint_entry(): void {
		cf_media_manager_test_reset_state();

		// Write an entry under the CURRENT fingerprint.
		$paths   = new \CFShared\Media\Paths( sys_get_temp_dir(), 'http://example.test/uploads' );
		$scanner = new InUseScanner( $paths );
		$scanner->get( true );
		$key_now = $this->current_cache_key();
		self::assertNotFalse( get_transient( $key_now ) );

		// Plant an old-fingerprint entry directly (simulating a stale
		// row from before a builder activation). invalidate_cache must
		// NOT touch this; it's the activated_plugin hook's job.
		$stale_key = InUseScanner::TRANSIENT_KEY . '_stalefp';
		set_transient(
			$stale_key,
			array( 'ids' => array(), 'scanned_at' => time() ),
			HOUR_IN_SECONDS
		);

		$scanner->invalidate_cache();

		self::assertFalse(
			get_transient( $key_now ),
			'current-fingerprint entry must be deleted'
		);
		self::assertNotFalse(
			get_transient( $stale_key ),
			'old-fingerprint entry must NOT be deleted by invalidate_cache (it survives until TTL or the wildcard sweep)'
		);
	}

	public function test_invalidate_all_fingerprinted_caches_runs_wildcard_delete(): void {
		cf_media_manager_test_reset_state();

		// Force the fingerprint-mismatch branch so the DELETE fires.
		// (C2 added a short-circuit: same fp → no-op.)
		$GLOBALS['cf_media_manager_test_options'][ \CFShared\Media\InUseScanner::OPT_LAST_BUILDER_FP ] = 'old-fp-from-before-plugin-event';

		// Install a tiny wpdb mock that records the DELETE statement.
		// invalidate_all_fingerprinted_caches issues a $wpdb->prepare +
		// $wpdb->query against wp_options with two LIKE patterns.
		$queries = array();
		$GLOBALS['wpdb'] = new class( $queries ) {
			public string $options = 'wp_options';
			private array $log;
			public function __construct( array &$log ) {
				$this->log = &$log;
			}
			public function prepare( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				return vsprintf( str_replace( '%s', "'%s'", $sql ), $args );
			}
			public function query( $sql ) {
				$this->log[] = $sql;
				return 1;
			}
			public function esc_like( $text ) {
				return addcslashes( (string) $text, '_%\\' );
			}
		};

		$paths   = new \CFShared\Media\Paths( sys_get_temp_dir(), 'http://example.test/uploads' );
		$scanner = new InUseScanner( $paths );
		$scanner->invalidate_all_fingerprinted_caches();

		self::assertCount( 1, $queries, 'wildcard sweep must issue exactly one DELETE' );
		self::assertStringContainsString( 'DELETE', $queries[0] );
		// esc_like escapes underscores in the LIKE pattern; assert on
		// the escaped form to mirror production SQL.
		self::assertStringContainsString( "\\_transient\\_cf", $queries[0], 'must target our transient prefix' );
		self::assertStringContainsString( "\\_transient\\_timeout\\_cf", $queries[0], 'must also clean up the transient-timeout option rows' );

		// After the DELETE runs, the stored builder fingerprint must be
		// updated to the current one so subsequent same-fp hook fires
		// can short-circuit.
		self::assertNotSame(
			'old-fp-from-before-plugin-event',
			$GLOBALS['cf_media_manager_test_options'][ \CFShared\Media\InUseScanner::OPT_LAST_BUILDER_FP ] ?? null,
			'stored fp must be updated to current after DELETE'
		);

		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * C2 — narrow the activated_plugin / deactivated_plugin hook so it
	 * only runs the wildcard DELETE when the builder set has actually
	 * changed. Activating a non-builder plugin (security plugin, SMTP,
	 * etc.) leaves the fp unchanged and must be a no-op.
	 */
	public function test_invalidate_all_no_op_when_builder_fingerprint_unchanged(): void {
		cf_media_manager_test_reset_state();

		// Seed the stored fp to MATCH what the scanner will compute right
		// now (no builders active in the test env).
		$key = $this->current_cache_key();
		// cache_key = TRANSIENT_KEY . '_' . fp ; strip the prefix.
		$current_fp = substr( $key, strlen( InUseScanner::TRANSIENT_KEY ) + 1 );
		$GLOBALS['cf_media_manager_test_options'][ \CFShared\Media\InUseScanner::OPT_LAST_BUILDER_FP ] = $current_fp;

		$queries = array();
		$GLOBALS['wpdb'] = new class( $queries ) {
			public string $options = 'wp_options';
			private array $log;
			public function __construct( array &$log ) {
				$this->log = &$log;
			}
			public function prepare( $sql, ...$args ) {
				return $sql; // unused on the no-op path
			}
			public function query( $sql ) {
				$this->log[] = $sql;
				return 1;
			}
			public function esc_like( $text ) {
				return (string) $text;
			}
		};

		$paths   = new \CFShared\Media\Paths( sys_get_temp_dir(), 'http://example.test/uploads' );
		$scanner = new InUseScanner( $paths );
		$scanner->invalidate_all_fingerprinted_caches();

		self::assertSame(
			array(),
			$queries,
			'unchanged builder fingerprint must SHORT-CIRCUIT — no DELETE on plugin events that did not change builder set'
		);

		unset( $GLOBALS['wpdb'] );
	}

	// =========================================================================
	// Non-publish status coverage (Must-Fix #1)
	// =========================================================================

	/**
	 * Install a $wpdb double that (a) returns a single attachment for the
	 * filename map and (b) returns an image reference from the post_content
	 * query only when the post-type group is the user group. It records the
	 * status placeholders every content query prepared so the test can assert
	 * non-publish statuses are included.
	 */
	private function install_status_aware_wpdb(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $posts    = 'wp_posts';
			public string $postmeta = 'wp_postmeta';
			public array $status_args_seen = array();

			public function esc_like( $t ) {
				return addcslashes( (string) $t, '_%\\' );
			}
			public function prepare( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				return $sql . '###ARGS###' . implode( '|', array_map( 'strval', $args ) );
			}
			public function get_results( $sql, $output = null ) {
				if ( false !== strpos( $sql, '_wp_attached_file' ) ) {
					return array(
						array( 'post_id' => 55, 'meta_value' => '2024/01/draft-only.jpg' ),
					);
				}
				return array();
			}
			public function get_col( $sql ) {
				$parts   = explode( '###ARGS###', $sql, 2 );
				$argstr  = $parts[1] ?? '';
				$args    = '' === $argstr ? array() : explode( '|', $argstr );
				if ( false !== strpos( $parts[0], '_thumbnail_id' ) ) {
					return array();
				}
				// A post_content query. Record the status placeholders.
				$this->status_args_seen[] = $args;
				// Only the user post-type group (post/page) carries the draft image.
				if ( in_array( 'post', $args, true ) || in_array( 'page', $args, true ) ) {
					return array(
						'<img src="https://example.test/wp-content/uploads/2024/01/draft-only.jpg">',
					);
				}
				return array();
			}
		};
	}

	public function test_scan_includes_non_publish_statuses(): void {
		cf_media_manager_test_reset_state();
		$this->install_status_aware_wpdb();

		$paths   = new \CFShared\Media\Paths( sys_get_temp_dir(), 'https://example.test/wp-content/uploads' );
		$scanner = new InUseScanner( $paths );
		$result  = $scanner->scan();

		// The image lives (in this fixture) on non-publish content; it must be
		// treated as in-use, not flagged for deletion.
		self::assertContains( 55, $result['ids'], 'Attachment referenced on scanned content must be in-use.' );

		// Every content query must widen the status filter beyond publish.
		$flat = array();
		foreach ( $GLOBALS['wpdb']->status_args_seen as $args ) {
			$flat = array_merge( $flat, $args );
		}
		foreach ( array( 'publish', 'future', 'private', 'pending', 'draft' ) as $status ) {
			self::assertContains( $status, $flat, "Status '{$status}' must be scanned." );
		}

		unset( $GLOBALS['wpdb'] );
	}

	// =========================================================================
	// Background scan driver (Must-Fix #2 — cron / Action Scheduler)
	// =========================================================================

	private function fingerprint_cache_key(): string {
		$paths   = new \CFShared\Media\Paths( sys_get_temp_dir(), 'http://example.test/uploads' );
		$scanner = new InUseScanner( $paths );
		$ref     = new \ReflectionMethod( InUseScanner::class, 'cache_key' );
		return $ref->invoke( $scanner );
	}

	public function test_get_for_report_records_warm_intent(): void {
		cf_media_manager_test_reset_state();
		$paths   = new \CFShared\Media\Paths( sys_get_temp_dir(), 'http://example.test/uploads' );
		$scanner = new InUseScanner( $paths );

		$scanner->get_for_report();

		self::assertGreaterThan(
			0,
			(int) get_option( \CFShared\Media\InUseScanner::OPT_WARM_WANTED, 0 ),
			'Reading through get_for_report must record warm intent so future invalidations warm the cache.'
		);
	}

	public function test_schedule_scan_queues_wp_cron_event_and_coalesces(): void {
		cf_media_manager_test_reset_state();
		$paths   = new \CFShared\Media\Paths( sys_get_temp_dir(), 'http://example.test/uploads' );
		$scanner = new InUseScanner( $paths );

		$scanner->schedule_scan();
		self::assertNotFalse(
			wp_next_scheduled( InUseScanner::CRON_HOOK ),
			'schedule_scan must queue a background scan when Action Scheduler is unavailable.'
		);

		// Coalescing: a second call while one is pending is a no-op (guarded by
		// the WARM_PENDING transient). Clearing the cron slot proves the second
		// call did not re-queue.
		wp_clear_scheduled_hook( InUseScanner::CRON_HOOK );
		$scanner->schedule_scan();
		self::assertFalse(
			wp_next_scheduled( InUseScanner::CRON_HOOK ),
			'A pending scan must coalesce repeat schedule_scan calls.'
		);
	}

	public function test_invalidate_cache_schedules_warm_only_when_recently_read(): void {
		cf_media_manager_test_reset_state();
		$paths   = new \CFShared\Media\Paths( sys_get_temp_dir(), 'http://example.test/uploads' );
		$scanner = new InUseScanner( $paths );

		// Never read → no background warm scheduled.
		$scanner->invalidate_cache();
		self::assertFalse( wp_next_scheduled( InUseScanner::CRON_HOOK ) );

		// Recently read → invalidation warms the cache in the background.
		update_option( \CFShared\Media\InUseScanner::OPT_WARM_WANTED, time(), false );
		$scanner->invalidate_cache();
		self::assertNotFalse( wp_next_scheduled( InUseScanner::CRON_HOOK ) );
	}

	public function test_run_scheduled_scan_populates_cache(): void {
		cf_media_manager_test_reset_state();
		$paths   = new \CFShared\Media\Paths( sys_get_temp_dir(), 'http://example.test/uploads' );
		$scanner = new InUseScanner( $paths );

		// Arm the coalescing guard, then run the handler.
		set_transient( InUseScanner::WARM_PENDING_TRANSIENT, 1, HOUR_IN_SECONDS );
		$scanner->run_scheduled_scan();

		$cached = get_transient( $this->fingerprint_cache_key() );
		self::assertIsArray( $cached, 'Background scan must persist a result under the fingerprinted key.' );
		self::assertArrayHasKey( 'ids', $cached );
		self::assertFalse(
			get_transient( InUseScanner::WARM_PENDING_TRANSIENT ),
			'The handler must clear the coalescing guard so the next invalidation can re-warm.'
		);
	}
}
