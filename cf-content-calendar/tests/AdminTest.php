<?php

use CFContentCalendar\Admin;
use PHPUnit\Framework\TestCase;

/**
 * Covers the security-relevant filtering Admin feeds into the localized data:
 * attachment exclusion from the type list, and the author roster. The methods
 * are private (they're internal helpers), so we exercise them via reflection.
 */
class AdminTest extends TestCase {

	protected function setUp(): void {
		cfcal_test_reset_state();
	}

	private function call_private( Admin $admin, string $method ) {
		$ref = new ReflectionMethod( Admin::class, $method );
		// Required on PHP < 8.1; a deprecated no-op from 8.5 on.
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}
		return $ref->invoke( $admin );
	}

	public function test_available_post_types_excludes_attachment(): void {
		$admin = new Admin();
		$types = $this->call_private( $admin, 'get_available_post_types' );

		$this->assertArrayHasKey( 'post', $types );
		$this->assertArrayHasKey( 'page', $types );
		$this->assertArrayNotHasKey( 'attachment', $types );
	}

	public function test_available_post_types_maps_slug_to_label(): void {
		$admin = new Admin();
		$types = $this->call_private( $admin, 'get_available_post_types' );

		$this->assertSame( 'Posts', $types['post'] );
		$this->assertSame( 'Pages', $types['page'] );
	}

	public function test_get_authors_returns_id_keyed_display_names(): void {
		$GLOBALS['cfcal_test_users'] = [
			(object) [ 'ID' => 3, 'display_name' => 'Casey' ],
			(object) [ 'ID' => 7, 'display_name' => 'Devon' ],
		];

		$admin   = new Admin();
		$authors = $this->call_private( $admin, 'get_authors' );

		$this->assertSame(
			[ 3 => 'Casey', 7 => 'Devon' ],
			$authors
		);
	}
}
