<?php

namespace BulkMetaEditor\Tests;

use BulkMetaEditor\PostDataService;
use BulkMetaEditor\Settings;
use CFShared\Logger as SharedLogger;
use PHPUnit\Framework\TestCase;

/**
 * Boundary + authorization tests for PostDataService::save_batch — the
 * single write-side surface where plugin security and the 64 KB length cap
 * are enforced. Every test goes through save_batch (not direct DB writes) so
 * the gates fire in the same order they do in production.
 */
final class PostDataServiceBoundaryTest extends TestCase {

	private Settings $settings;
	private SharedLogger $logger;
	private PostDataService $service;

	protected function setUp(): void {
		bme_test_reset_state();
		$GLOBALS['bme_test_post_types'] = [
			'post' => (object) [ 'name' => 'post', 'label' => 'Posts', 'public' => true ],
		];

		$this->settings = new Settings();
		$this->settings->update( [
			'meta_title_key' => '_seo_title',
			'meta_desc_key'  => '_seo_desc',
			'custom_columns' => [ [ 'key' => '_sku', 'label' => 'SKU' ] ],
		] );

		// Threshold = ERROR so warn/info/debug calls inside save_batch return
		// early. Keeps the suite I/O-free.
		$this->logger = SharedLogger::for_plugin( [
			'slug'               => 'cf-bulk-meta-editor',
			'threshold_resolver' => static fn() => SharedLogger::LEVEL_ERROR,
		] );

		$this->service = new PostDataService( $this->settings, $this->logger );
	}

	/**
	 * Tally results array by error code (null = success). Mirrors the
	 * internal $counts the service maintains for its log line.
	 */
	private static function tally( array $results ): array {
		$counts = [];
		foreach ( $results as $r ) {
			$bucket            = $r['success'] ? ( 'success' ) : ( $r['error'] ?? 'unknown' );
			$counts[ $bucket ] = ( $counts[ $bucket ] ?? 0 ) + 1;
		}
		return $counts;
	}

	// -------------------------------------------------------------------------
	// Length-cap boundary (BME-P0-003 / MAX_VALUE_BYTES = 65536)
	// -------------------------------------------------------------------------

	public function test_save_batch_accepts_value_exactly_at_cap(): void {
		$value = str_repeat( 'a', PostDataService::MAX_VALUE_BYTES );

		$results = $this->service->save_batch( [
			[ 'id' => 42, 'key' => 'meta_title', 'value' => $value ],
		] );

		self::assertTrue( $results[0]['success'] );
		// And it actually landed in postmeta.
		self::assertSame( $value, $GLOBALS['bme_test_postmeta'][42]['_seo_title'] );
	}

	public function test_save_batch_rejects_value_one_byte_over_cap(): void {
		$value = str_repeat( 'a', PostDataService::MAX_VALUE_BYTES + 1 );

		$results = $this->service->save_batch( [
			[ 'id' => 42, 'key' => 'meta_title', 'value' => $value ],
		] );

		self::assertFalse( $results[0]['success'] );
		self::assertSame( 'value_too_large', $results[0]['error'] );
		self::assertSame( PostDataService::MAX_VALUE_BYTES, $results[0]['error_context']['max'] );
		// Critically: no write happened.
		self::assertArrayNotHasKey( 42, $GLOBALS['bme_test_postmeta'] );
	}

	// -------------------------------------------------------------------------
	// Allow-list — column key must map through Settings::column_to_meta_key
	// -------------------------------------------------------------------------

	public function test_save_batch_rejects_columns_outside_settings_allowlist(): void {
		$results = $this->service->save_batch( [
			[ 'id' => 42, 'key' => 'custom___secret', 'value' => 'pwned' ],
		] );

		self::assertFalse( $results[0]['success'] );
		self::assertSame( 'key_not_allowed', $results[0]['error'] );
		// Critically: the attacker-supplied key was never written.
		self::assertArrayNotHasKey( 42, $GLOBALS['bme_test_postmeta'] );
	}

	public function test_save_batch_writes_only_to_configured_custom_columns(): void {
		$results = $this->service->save_batch( [
			[ 'id' => 42, 'key' => 'custom___sku', 'value' => 'ABC-123' ],
		] );

		self::assertTrue( $results[0]['success'] );
		self::assertSame( 'ABC-123', $GLOBALS['bme_test_postmeta'][42]['_sku'] );
	}

	// -------------------------------------------------------------------------
	// Per-row capability check — even with a valid column, save_batch must
	// reject writes when the current user lacks edit_post on the specific id.
	// -------------------------------------------------------------------------

	public function test_save_batch_rejects_writes_when_user_lacks_edit_post_on_specific_id(): void {
		// Permission gate: yes only for post id 100.
		$GLOBALS['bme_test_caps'] = function ( $cap, $object_id ) {
			if ( 'edit_post' === $cap ) {
				return 100 === (int) $object_id;
			}
			return true;
		};

		$results = $this->service->save_batch( [
			[ 'id' => 100, 'key' => 'meta_title', 'value' => 'allowed' ],
			[ 'id' => 200, 'key' => 'meta_title', 'value' => 'forbidden' ],
		] );

		$tally = self::tally( $results );
		self::assertSame( 1, $tally['success']   ?? 0 );
		self::assertSame( 1, $tally['forbidden'] ?? 0 );
		// id 100 wrote, id 200 did not.
		self::assertSame( 'allowed', $GLOBALS['bme_test_postmeta'][100]['_seo_title'] );
		self::assertArrayNotHasKey( 200, $GLOBALS['bme_test_postmeta'] );
	}

	public function test_save_batch_rejects_missing_id(): void {
		$results = $this->service->save_batch( [
			[ 'id' => 0, 'key' => 'meta_title', 'value' => 'x' ],
		] );

		self::assertFalse( $results[0]['success'] );
		self::assertSame( 'missing_params', $results[0]['error'] );
	}

	public function test_save_batch_rejects_missing_key(): void {
		$results = $this->service->save_batch( [
			[ 'id' => 42, 'key' => '', 'value' => 'x' ],
		] );

		self::assertFalse( $results[0]['success'] );
		self::assertSame( 'missing_params', $results[0]['error'] );
	}

	public function test_save_batch_accepts_empty_value_as_a_meta_clear(): void {
		// Pre-seed and then write empty — legitimate way to clear a field.
		$GLOBALS['bme_test_postmeta'][42]['_seo_title'] = 'existing';

		$results = $this->service->save_batch( [
			[ 'id' => 42, 'key' => 'meta_title', 'value' => '' ],
		] );

		self::assertTrue( $results[0]['success'] );
		self::assertSame( '', $GLOBALS['bme_test_postmeta'][42]['_seo_title'] );
	}

	public function test_save_batch_classifies_unchanged_writes_distinctly_from_success(): void {
		// Pre-seed so the write is a no-op (existing === new).
		$GLOBALS['bme_test_postmeta'][42]['_seo_title'] = 'already-here';

		$results = $this->service->save_batch( [
			[ 'id' => 42, 'key' => 'meta_title', 'value' => 'already-here' ],
		] );

		// Result row reports success=true (the value is in place) AND error is
		// null. The "unchanged" bucket is internal accounting in the log — the
		// REST consumer only sees success/failure.
		self::assertTrue( $results[0]['success'] );
		self::assertNull( $results[0]['error'] );
	}
}
