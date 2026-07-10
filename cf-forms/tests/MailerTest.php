<?php
namespace CFForms\Tests;

use CFForms\Mailer;
use PHPUnit\Framework\TestCase;

final class MailerTest extends TestCase {

	protected function setUp(): void {
		cff_test_reset_state();
	}

	public function test_sends_to_configured_recipient(): void {
		$sent = Mailer::notify( 'owner@example.test', 'contact', [ 'name' => 'Jane' ], 42 );

		$this->assertTrue( $sent );
		$this->assertCount( 1, $GLOBALS['cff_test_mail'] );
		$this->assertSame( 'owner@example.test', $GLOBALS['cff_test_mail'][0]['to'] );
		$this->assertStringContainsString( 'contact', $GLOBALS['cff_test_mail'][0]['subject'] );
		$this->assertStringContainsString( 'name: Jane', $GLOBALS['cff_test_mail'][0]['message'] );
	}

	public function test_invalid_recipient_sends_nothing(): void {
		$this->assertFalse( Mailer::notify( 'not-an-email', 'contact', [ 'name' => 'Jane' ], 42 ) );
		$this->assertCount( 0, $GLOBALS['cff_test_mail'] );
	}

	public function test_recipient_filter_can_clear_the_send(): void {
		add_filter( 'cff_notification_recipient', static fn() => '' );

		$this->assertFalse( Mailer::notify( 'owner@example.test', 'contact', [ 'name' => 'Jane' ], 42 ) );
		$this->assertCount( 0, $GLOBALS['cff_test_mail'] );
	}

	public function test_reply_to_uses_first_valid_email_field(): void {
		Mailer::notify(
			'owner@example.test',
			'contact',
			[ 'name' => 'Jane', 'work_email' => 'jane@example.com', 'alt_email' => 'other@example.com' ],
			42
		);

		$headers = $GLOBALS['cff_test_mail'][0]['headers'];
		$this->assertContains( 'Reply-To: jane@example.com', $headers );
	}

	public function test_no_reply_to_when_no_email_field(): void {
		Mailer::notify( 'owner@example.test', 'contact', [ 'name' => 'Jane', 'message' => 'hi' ], 42 );

		$headers = $GLOBALS['cff_test_mail'][0]['headers'];
		foreach ( $headers as $header ) {
			$this->assertStringStartsNotWith( 'Reply-To:', $header );
		}
	}
}
