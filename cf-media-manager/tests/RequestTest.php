<?php

namespace CFMediaManager\Tests;

use CFMediaManager\Request;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the typed $_POST accessors. These helpers replace ~30
 * sites of repeated `isset + wp_unslash + sanitize_*` boilerplate across
 * the AJAX surface; centralized tests here guard the contract every
 * call site now depends on.
 */
final class RequestTest extends TestCase {

	protected function setUp(): void {
		cf_media_manager_test_reset_state();
		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();
	}

	// ----- post_string -------------------------------------------------

	public function test_post_string_returns_default_when_missing(): void {
		self::assertSame( '', Request::post_string( 'missing' ) );
		self::assertSame( 'fallback', Request::post_string( 'missing', 'fallback' ) );
	}

	public function test_post_string_sanitizes_value(): void {
		// sanitize_text_field strips tags + normalizes whitespace.
		$_POST['x'] = "  hello <script>alert('x')</script>  world\n";
		self::assertSame( 'hello alert(\'x\') world', Request::post_string( 'x' ) );
	}

	// ----- post_key ----------------------------------------------------

	public function test_post_key_returns_default_when_missing(): void {
		self::assertSame( '', Request::post_key( 'missing' ) );
		self::assertSame( 'all', Request::post_key( 'missing', 'all' ) );
	}

	public function test_post_key_lowercases_and_strips_invalid_chars(): void {
		$_POST['filter'] = 'In-Use_Missing!';
		// sanitize_key lowercases and strips anything outside [a-z0-9_-].
		self::assertSame( 'in-use_missing', Request::post_key( 'filter' ) );
	}

	// ----- post_int ----------------------------------------------------

	public function test_post_int_returns_default_when_missing(): void {
		self::assertSame( 0, Request::post_int( 'missing' ) );
		self::assertSame( 42, Request::post_int( 'missing', 42 ) );
	}

	public function test_post_int_casts_numeric_string(): void {
		$_POST['n'] = '123';
		self::assertSame( 123, Request::post_int( 'n' ) );
	}

	public function test_post_int_returns_zero_for_non_numeric(): void {
		$_POST['n'] = 'not-a-number';
		self::assertSame( 0, Request::post_int( 'n' ) );
	}

	// ----- post_bool ---------------------------------------------------

	public function test_post_bool_returns_default_when_missing(): void {
		self::assertFalse( Request::post_bool( 'missing' ) );
		self::assertTrue( Request::post_bool( 'missing', true ) );
	}

	public function test_post_bool_treats_truthy_values_as_true(): void {
		$_POST['x'] = '1';
		self::assertTrue( Request::post_bool( 'x' ) );
		$_POST['x'] = 1;
		self::assertTrue( Request::post_bool( 'x' ) );
		$_POST['x'] = 'on';
		self::assertTrue( Request::post_bool( 'x' ) );
	}

	public function test_post_bool_treats_falsy_values_as_false(): void {
		$_POST['x'] = '0';
		self::assertFalse( Request::post_bool( 'x' ) );
		$_POST['x'] = 0;
		self::assertFalse( Request::post_bool( 'x' ) );
		$_POST['x'] = '';
		self::assertFalse( Request::post_bool( 'x' ) );
	}

	// ----- post_int_array ----------------------------------------------

	public function test_post_int_array_returns_default_when_missing(): void {
		self::assertSame( array(), Request::post_int_array( 'missing' ) );
		self::assertSame( array( 1 ), Request::post_int_array( 'missing', array( 1 ) ) );
	}

	public function test_post_int_array_filters_to_positive_integers(): void {
		$_POST['ids'] = array( '1', '2', 'abc', '-5', '0', '100', '3.5' );
		// 3.5 → 3 via (int), kept. 'abc' → 0, dropped. '-5' → -5, dropped.
		// '0' → 0, dropped. Order preserved as encountered.
		self::assertSame( array( 1, 2, 100, 3 ), Request::post_int_array( 'ids' ) );
	}

	public function test_post_int_array_returns_default_when_value_is_not_an_array(): void {
		$_POST['ids'] = 'not-an-array';
		self::assertSame( array(), Request::post_int_array( 'ids' ) );
	}

	// ----- post_id_list (H1: CSV-string acceptance) -------------------

	public function test_post_id_list_returns_empty_when_missing(): void {
		self::assertSame( array(), Request::post_id_list( 'missing' ) );
	}

	public function test_post_id_list_accepts_array_shape(): void {
		$_POST['ids'] = array( '11', '22', '33' );
		self::assertSame( array( 11, 22, 33 ), Request::post_id_list( 'ids' ) );
	}

	/**
	 * H1 regression: the legacy parse_id_list did `(array) $raw` on a CSV
	 * string, producing `array("12,34,56")`, then intval'd the lone
	 * element, silently dropping every id after the first. The new helper
	 * must split on comma / whitespace and treat each token as an id.
	 */
	public function test_post_id_list_accepts_csv_string_and_splits(): void {
		$_POST['ids'] = '12,34,56';
		self::assertSame( array( 12, 34, 56 ), Request::post_id_list( 'ids' ) );
	}

	public function test_post_id_list_accepts_csv_with_spaces_and_mixed_separators(): void {
		$_POST['ids'] = '12, 34   56,78';
		self::assertSame( array( 12, 34, 56, 78 ), Request::post_id_list( 'ids' ) );
	}

	public function test_post_id_list_dedupes_and_strips_non_positive(): void {
		$_POST['ids'] = array( '5', '5', '0', '-3', 'abc', '7' );
		self::assertSame( array( 5, 7 ), Request::post_id_list( 'ids' ) );
	}

	public function test_post_id_list_dedupes_csv(): void {
		$_POST['ids'] = '5,5,7,0,7';
		self::assertSame( array( 5, 7 ), Request::post_id_list( 'ids' ) );
	}

	public function test_post_id_list_enforces_max_cap(): void {
		$_POST['ids'] = range( 1, 200 );
		self::assertCount( 50, Request::post_id_list( 'ids', 50 ) );
		self::assertSame( range( 1, 50 ), Request::post_id_list( 'ids', 50 ) );
	}

	public function test_post_id_list_default_cap_passes_through_modest_lists(): void {
		// Default cap is 50000; modest lists pass through untouched.
		$_POST['ids'] = range( 1, 100 );
		self::assertCount( 100, Request::post_id_list( 'ids' ) );
	}

	public function test_post_id_list_rejects_object_and_other_scalar_types(): void {
		$_POST['ids'] = new \stdClass();
		self::assertSame( array(), Request::post_id_list( 'ids' ) );

		$_POST['ids'] = true;
		self::assertSame( array(), Request::post_id_list( 'ids' ) );
	}

	public function test_post_id_list_empty_string_yields_empty(): void {
		$_POST['ids'] = '';
		self::assertSame( array(), Request::post_id_list( 'ids' ) );
	}

	public function test_post_id_list_csv_with_only_garbage_yields_empty(): void {
		$_POST['ids'] = 'abc, def, ghi';
		self::assertSame( array(), Request::post_id_list( 'ids' ) );
	}

	/**
	 * Defense-in-depth: PHP's intval() behavior on non-scalars is version-
	 * dependent. On PHP 8.5+ intval(array) and intval(object) both return 1,
	 * so an attacker sending `ids[][]=anything` could silently smuggle
	 * attachment id 1. The helper must filter to scalars first.
	 */
	public function test_post_id_list_drops_non_scalar_array_elements(): void {
		$_POST['ids'] = array( '5', array( 'nested' ), 7, new \stdClass() );
		self::assertSame( array( 5, 7 ), Request::post_id_list( 'ids' ) );
	}
}
