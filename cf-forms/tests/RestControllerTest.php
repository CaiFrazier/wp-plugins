<?php
namespace CFForms\Tests;

use CFForms\EntryPostType;
use CFForms\RestController;
use CFForms\Settings;
use PHPUnit\Framework\TestCase;

final class RestControllerTest extends TestCase {

	protected function setUp(): void {
		cff_test_reset_state();
		$GLOBALS['cff_test_options']['cff_settings'] = [
			'notification_email'  => 'owner@example.test',
			'rate_limit_max'      => 10,
			'rate_limit_window'   => 3600,
			'min_elapsed_seconds' => 3,
		];
	}

	private function controller(): RestController {
		return new RestController( new Settings() );
	}

	private function actions_named( string $hook ): array {
		return array_values(
			array_filter(
				$GLOBALS['cff_test_actions'],
				static fn( $a ) => $a['hook'] === $hook
			)
		);
	}

	public function test_valid_submission_is_stored_and_notified(): void {
		$request = new \WP_REST_Request(
			[
				'form_id'     => 'Contact Us',
				'fields'      => [ 'name' => 'Jane', 'email' => 'jane@example.com' ],
				'rendered_at' => time() - 10,
			]
		);

		$response = $this->controller()->handle_submit( $request );
		$data     = $response->get_data();

		$this->assertSame( [ 'success' => true ], $data );
		$this->assertCount( 1, $GLOBALS['cff_test_inserted_posts'] );
		$this->assertCount( 1, $GLOBALS['cff_test_mail'] );
		$this->assertSame( 'owner@example.test', $GLOBALS['cff_test_mail'][0]['to'] );

		// Mail-sent status and the entry-created action are recorded.
		$this->assertCount( 1, $this->actions_named( 'cff_entry_created' ) );
		$entry_id = $this->actions_named( 'cff_entry_created' )[0]['args'][0];
		$this->assertSame( '1', get_post_meta( $entry_id, EntryPostType::META_MAIL_SENT, true ) );
	}

	public function test_success_response_is_indistinguishable_from_spam_rejection(): void {
		$good = $this->controller()->handle_submit(
			new \WP_REST_Request(
				[ 'form_id' => 'contact', 'fields' => [ 'name' => 'Jane' ], 'rendered_at' => time() - 10 ]
			)
		);

		cff_test_reset_state();
		$GLOBALS['cff_test_options']['cff_settings'] = [ 'notification_email' => 'owner@example.test' ];

		$honeypot = $this->controller()->handle_submit(
			new \WP_REST_Request(
				[ 'form_id' => 'contact', 'fields' => [ 'name' => 'Bot' ], 'hp_field' => 'x', 'rendered_at' => time() - 10 ]
			)
		);

		// Byte-for-byte identical: a bot cannot tell it was dropped.
		$this->assertSame( $good->get_data(), $honeypot->get_data() );
	}

	public function test_honeypot_is_dropped_and_flagged(): void {
		$request = new \WP_REST_Request(
			[
				'form_id'     => 'contact',
				'fields'      => [ 'name' => 'Bot' ],
				'hp_field'    => 'filled-in-by-a-bot',
				'rendered_at' => time() - 10,
			]
		);

		$response = $this->controller()->handle_submit( $request );

		$this->assertSame( [ 'success' => true ], $response->get_data() );
		$this->assertCount( 0, $GLOBALS['cff_test_inserted_posts'] );
		$this->assertCount( 0, $GLOBALS['cff_test_mail'] );

		$spam = $this->actions_named( 'cff_spam_detected' );
		$this->assertCount( 1, $spam );
		$this->assertSame( 'honeypot', $spam[0]['args'][2] );
	}

	public function test_too_fast_submission_is_dropped_and_flagged(): void {
		$request = new \WP_REST_Request(
			[ 'form_id' => 'contact', 'fields' => [ 'name' => 'Bot' ], 'rendered_at' => time() ]
		);

		$response = $this->controller()->handle_submit( $request );

		$this->assertSame( [ 'success' => true ], $response->get_data() );
		$this->assertCount( 0, $GLOBALS['cff_test_inserted_posts'] );
		$this->assertSame( 'time_trap', $this->actions_named( 'cff_spam_detected' )[0]['args'][2] );
	}

	public function test_future_timestamp_is_dropped(): void {
		$request = new \WP_REST_Request(
			[ 'form_id' => 'contact', 'fields' => [ 'name' => 'Bot' ], 'rendered_at' => time() + 3600 ]
		);

		$response = $this->controller()->handle_submit( $request );

		$this->assertSame( [ 'success' => true ], $response->get_data() );
		$this->assertCount( 0, $GLOBALS['cff_test_inserted_posts'] );
	}

	public function test_missing_form_id_is_rejected(): void {
		$request  = new \WP_REST_Request( [ 'fields' => [ 'name' => 'Jane' ] ] );
		$response = $this->controller()->handle_submit( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'cff_invalid_form_id', $response->get_error_code() );
	}

	public function test_empty_fields_are_rejected_but_still_counted(): void {
		$request  = new \WP_REST_Request( [ 'form_id' => 'contact', 'fields' => [] ] );
		$response = $this->controller()->handle_submit( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'cff_empty_fields', $response->get_error_code() );

		// The rejected request still filled the rate-limit bucket, so a bot can't
		// hammer the endpoint with empty payloads for free.
		$this->assertNotEmpty( $GLOBALS['cff_test_transients'] );
	}

	public function test_validate_filter_can_reject(): void {
		add_filter(
			'cff_validate_submission',
			static fn() => new \WP_Error( 'missing_name', 'Name is required.', [ 'status' => 422 ] )
		);

		$request  = new \WP_REST_Request(
			[ 'form_id' => 'contact', 'fields' => [ 'email' => 'j@example.com' ], 'rendered_at' => time() - 10 ]
		);
		$response = $this->controller()->handle_submit( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'missing_name', $response->get_error_code() );
		$this->assertCount( 0, $GLOBALS['cff_test_inserted_posts'] );
	}

	public function test_reply_to_is_set_from_a_submitted_email(): void {
		$request = new \WP_REST_Request(
			[
				'form_id'     => 'contact',
				'fields'      => [ 'name' => 'Jane', 'email' => 'jane@example.com' ],
				'rendered_at' => time() - 10,
			]
		);

		$this->controller()->handle_submit( $request );

		$headers = $GLOBALS['cff_test_mail'][0]['headers'];
		$this->assertContains( 'Reply-To: jane@example.com', $headers );
	}

	public function test_failed_mail_is_recorded_on_the_entry(): void {
		$GLOBALS['cff_test_mail_result'] = false;

		$request = new \WP_REST_Request(
			[ 'form_id' => 'contact', 'fields' => [ 'name' => 'Jane' ], 'rendered_at' => time() - 10 ]
		);
		$this->controller()->handle_submit( $request );

		$entry_id = $this->actions_named( 'cff_entry_created' )[0]['args'][0];
		$this->assertSame( '0', get_post_meta( $entry_id, EntryPostType::META_MAIL_SENT, true ) );
	}

	public function test_rate_limit_exceeded_returns_429(): void {
		$GLOBALS['cff_test_options']['cff_settings']['rate_limit_max'] = 1;

		$make_request = fn() => new \WP_REST_Request(
			[ 'form_id' => 'contact', 'fields' => [ 'name' => 'Jane' ], 'rendered_at' => time() - 10 ]
		);

		$this->controller()->handle_submit( $make_request() );
		$response = $this->controller()->handle_submit( $make_request() );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'cff_rate_limited', $response->get_error_code() );
	}

	public function test_continuum_support_stores_and_mails_a_valid_zip(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'cff-zip-' );
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $tmp, \ZipArchive::OVERWRITE ) );
		$zip->addFromString( 'diagnostics.json', '{"version":"test"}' );
		$zip->close();
		$request = new \WP_REST_Request(
			[],
			[
				'description' => 'Continuum crashed while opening a project dashboard.',
				'email'       => 'owner@example.com',
				'app_version' => '1.0.0-beta.12',
				'rendered_at' => time() - 10,
			],
			[
				'diagnostics' => [
					'name' => 'continuum-diagnostics.zip', 'tmp_name' => $tmp,
					'size' => filesize( $tmp ), 'error' => UPLOAD_ERR_OK,
				],
			]
		);

		$response = $this->controller()->handle_support( $request );
		$this->assertSame( [ 'success' => true ], $response->get_data() );
		$this->assertCount( 1, $GLOBALS['cff_test_inserted_posts'] );
		$this->assertCount( 1, $GLOBALS['cff_test_mail'] );
		$this->assertCount( 1, $GLOBALS['cff_test_mail'][0]['attachments'] );
		$entry_id = $this->actions_named( 'cff_entry_created' )[0]['args'][0];
		$this->assertFileExists( get_post_meta( $entry_id, EntryPostType::META_ATTACHMENT, true ) );
	}

	public function test_continuum_support_rejects_a_fake_zip(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'cff-not-zip-' );
		file_put_contents( $tmp, "PK\x03\x04not actually a zip" );
		$request = new \WP_REST_Request(
			[],
			[ 'description' => 'The app fails every time the project is opened.', 'rendered_at' => time() - 10 ],
			[ 'diagnostics' => [ 'name' => 'fake.zip', 'tmp_name' => $tmp, 'size' => filesize( $tmp ), 'error' => UPLOAD_ERR_OK ] ]
		);

		$response = $this->controller()->handle_support( $request );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'cff_support_upload_type', $response->get_error_code() );
		$this->assertCount( 0, $GLOBALS['cff_test_inserted_posts'] );
		@unlink( $tmp );
	}

	public function test_continuum_support_honeypot_is_silently_dropped(): void {
		$request = new \WP_REST_Request(
			[],
			[ 'description' => 'Bot generated support report content here.', 'hp_field' => 'filled', 'rendered_at' => time() - 10 ]
		);
		$response = $this->controller()->handle_support( $request );
		$this->assertSame( [ 'success' => true ], $response->get_data() );
		$this->assertCount( 0, $GLOBALS['cff_test_inserted_posts'] );
	}
}
