<?php
namespace CFForms\Tests;

use CFForms\Sanitizer;
use PHPUnit\Framework\TestCase;

final class SanitizerTest extends TestCase {

	protected function setUp(): void {
		cff_test_reset_state();
	}

	public function test_sanitizes_scalar_fields(): void {
		$fields = Sanitizer::sanitize_fields(
			[
				'name'         => '  Jane <b>Doe</b>  ',
				'email'        => 'jane@example.com ',
				'Contact_Email' => 'not-an-email',
			]
		);

		$this->assertSame( 'Jane Doe', $fields['name'] );
		$this->assertSame( 'jane@example.com', $fields['email'] );
		// Key contains "email" (case-insensitively) → routed through sanitize_email.
		$this->assertArrayHasKey( 'contact_email', $fields );
	}

	public function test_rejects_non_array_input(): void {
		$this->assertSame( [], Sanitizer::sanitize_fields( 'not-an-array' ) );
		$this->assertSame( [], Sanitizer::sanitize_fields( null ) );
	}

	public function test_drops_empty_keys(): void {
		$fields = Sanitizer::sanitize_fields( [ '' => 'value', '   ' => 'value2', 'ok' => 'value3' ] );
		$this->assertSame( [ 'ok' => 'value3' ], $fields );
	}

	public function test_caps_field_count(): void {
		$raw = [];
		for ( $i = 0; $i < 100; $i++ ) {
			$raw[ 'field_' . $i ] = 'value';
		}
		$fields = Sanitizer::sanitize_fields( $raw );
		$this->assertLessThanOrEqual( Sanitizer::MAX_FIELD_COUNT, count( $fields ) );
	}

	public function test_truncates_long_values(): void {
		$fields = Sanitizer::sanitize_fields( [ 'note' => str_repeat( 'a', 10000 ) ] );
		$this->assertLessThanOrEqual( Sanitizer::MAX_FIELD_VALUE_LEN, strlen( $fields['note'] ) );
	}

	public function test_sanitize_form_id(): void {
		$this->assertSame( 'contactus', Sanitizer::sanitize_form_id( 'Contact Us!' ) );
		$this->assertSame( 'contact-form', Sanitizer::sanitize_form_id( 'contact-form' ) );
		$this->assertSame( '', Sanitizer::sanitize_form_id( null ) );
	}
}
