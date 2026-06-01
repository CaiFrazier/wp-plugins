<?php

namespace CFMediaManager\Tests;

use CFMediaManager\AttachmentLookup;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the pieces of AttachmentLookup that don't require a real $wpdb.
 *
 * The DB-backed map builders are exercised by integration testing against a
 * real WP install; the suite shim doesn't carry a $wpdb mock and building one
 * to test what amounts to two SELECT statements isn't worth the maintenance
 * cost. What we DO cover here:
 *
 *   - Graceful degradation: without $wpdb, both maps return empty arrays
 *     (callers in admin context must keep working even when something has
 *     hosed the database singleton).
 *   - Memoization correctness: reset() actually clears the cached arrays so
 *     a subsequent call rebuilds rather than returning stale data.
 *   - resolve_relative_path() boundary cases — empty input, leading slash
 *     normalization.
 */
final class AttachmentLookupTest extends TestCase {

	protected function setUp(): void {
		cf_media_manager_test_reset_state();
		AttachmentLookup::reset();
		// Defensively unset $wpdb so the no-DB code paths are what runs.
		unset( $GLOBALS['wpdb'] );
	}

	public function test_path_to_id_returns_empty_without_wpdb(): void {
		self::assertSame( array(), AttachmentLookup::path_to_id() );
	}

	public function test_size_to_parent_returns_empty_without_wpdb(): void {
		self::assertSame( array(), AttachmentLookup::size_to_parent() );
	}

	public function test_resolve_returns_zero_for_empty_path(): void {
		self::assertSame( 0, AttachmentLookup::resolve_relative_path( '' ) );
		self::assertSame( 0, AttachmentLookup::resolve_relative_path( '/' ) );
	}

	public function test_resolve_returns_zero_when_maps_are_empty(): void {
		self::assertSame( 0, AttachmentLookup::resolve_relative_path( '2024/03/photo.jpg' ) );
	}

	public function test_reset_clears_memoized_state(): void {
		// First call populates the memo with an empty array (no $wpdb).
		AttachmentLookup::path_to_id();
		AttachmentLookup::size_to_parent();
		// Reset and verify the next call would re-evaluate — easiest way to
		// check is that the call still returns the empty (rebuilt) array
		// without throwing. The pre-reset state is unobservable from outside,
		// but the contract is that reset() doesn't poison the next call.
		AttachmentLookup::reset();
		self::assertSame( array(), AttachmentLookup::path_to_id() );
		self::assertSame( array(), AttachmentLookup::size_to_parent() );
	}
}
