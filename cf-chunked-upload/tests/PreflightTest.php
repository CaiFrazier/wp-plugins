<?php

namespace CFChunkedUpload\Tests;

use CFChunkedUpload\Paths;
use CFChunkedUpload\Preflight;
use CFChunkedUpload\Settings;
use CFChunkedUpload\UploadSession;
use PHPUnit\Framework\TestCase;

final class PreflightTest extends TestCase {

	private string $sandbox;
	private Paths $paths;
	private Settings $settings;

	protected function setUp(): void {
		cf_chunked_upload_test_reset_state();
		$this->sandbox  = sys_get_temp_dir() . '/cf-cu-pf-' . uniqid();
		mkdir( $this->sandbox, 0777, true );
		$this->paths    = new Paths( $this->sandbox );
		$this->settings = new Settings( $this->paths );
	}

	protected function tearDown(): void {
		UploadSession::rrmdir( $this->sandbox );
	}

	public function test_returns_expected_keys(): void {
		$pf     = new Preflight( $this->paths, $this->settings );
		$result = $pf->check( 'import', 'file.zip', 1024 );

		foreach ( [ 'diskOk', 'destinationOk', 'wpCronHealthy', 'finfoAvailable',
					'extensionAllowed', 'estimatedChunks', 'warnings' ] as $key ) {
			self::assertArrayHasKey( $key, $result, "Missing key: $key" );
		}
	}

	public function test_estimated_chunks_calculated_from_chunk_size(): void {
		$pf = new Preflight( $this->paths, $this->settings );
		// Default chunk size is 8 MB. 16 MB file → 2 chunks.
		$result = $pf->check( 'import', 'file.zip', 8 * 1048576 * 2 );
		self::assertSame( 2, $result['estimatedChunks'] );
	}

	public function test_zero_file_size_yields_one_estimated_chunk(): void {
		$pf     = new Preflight( $this->paths, $this->settings );
		$result = $pf->check( 'import', 'file.zip', 0 );
		self::assertSame( 1, $result['estimatedChunks'] );
	}

	public function test_extension_disallowed_when_not_in_allowlist(): void {
		// Save settings with a restrictive allowlist.
		update_option(
			Settings::OPTION,
			array_merge( Settings::defaults(), [ 'allowed_extensions' => [ 'zip' ] ] )
		);
		$pf     = new Preflight( $this->paths, $this->settings );
		$result = $pf->check( 'import', 'file.exe', 1024 );

		self::assertFalse( $result['extensionAllowed'] );
		self::assertNotEmpty( $result['warnings'] );
	}

	public function test_extension_allowed_when_in_allowlist(): void {
		update_option(
			Settings::OPTION,
			array_merge( Settings::defaults(), [ 'allowed_extensions' => [ 'zip' ] ] )
		);
		$pf     = new Preflight( $this->paths, $this->settings );
		$result = $pf->check( 'import', 'backup.zip', 1024 );
		self::assertTrue( $result['extensionAllowed'] );
	}

	public function test_extension_allowed_when_allowlist_is_empty(): void {
		// Empty allowlist means "allow all".
		$pf     = new Preflight( $this->paths, $this->settings );
		$result = $pf->check( 'import', 'backup.exe', 1024 );
		self::assertTrue( $result['extensionAllowed'] );
	}

	public function test_no_extension_warning_for_media_destination(): void {
		update_option(
			Settings::OPTION,
			array_merge( Settings::defaults(), [ 'allowed_extensions' => [ 'zip' ] ] )
		);
		$pf = new Preflight( $this->paths, $this->settings );
		// Media destination does not apply the importer allowlist.
		$result = $pf->check( 'media', 'video.mp4', 1024 );
		self::assertTrue( $result['extensionAllowed'] );
	}

	public function test_quota_remaining_reported_when_limit_set(): void {
		$limit_gb = 10;
		update_option(
			Settings::OPTION,
			array_merge( Settings::defaults(), [ 'per_user_quota_gb' => $limit_gb ] )
		);
		$GLOBALS['cf_chunked_upload_test_current_user'] = 5;
		$used_bytes = 3 * 1073741824; // 3 GB used
		update_option( 'cf_cu_quota_5', $used_bytes );

		$pf     = new Preflight( $this->paths, $this->settings );
		$result = $pf->check( 'import', 'file.zip', 1024 );

		$expected_remaining = ( $limit_gb * 1073741824 ) - $used_bytes;
		self::assertSame( $expected_remaining, $result['quotaRemainingBytes'] );
	}

	public function test_quota_null_when_unlimited(): void {
		// per_user_quota_gb defaults to 50 but let's explicitly set 0 = unlimited.
		update_option(
			Settings::OPTION,
			array_merge( Settings::defaults(), [ 'per_user_quota_gb' => 0 ] )
		);
		$GLOBALS['cf_chunked_upload_test_current_user'] = 5;
		$pf     = new Preflight( $this->paths, $this->settings );
		$result = $pf->check( 'import', 'file.zip', 1024 );
		self::assertNull( $result['quotaRemainingBytes'] );
	}

	public function test_concurrent_slots_reported_when_cap_set(): void {
		update_option(
			Settings::OPTION,
			array_merge( Settings::defaults(), [ 'max_concurrent_uploads_per_user' => 3 ] )
		);
		$GLOBALS['cf_chunked_upload_test_current_user'] = 7;
		// No active sessions yet → 3 slots left.
		$pf     = new Preflight( $this->paths, $this->settings );
		$result = $pf->check( 'import', 'file.zip', 1024 );
		self::assertSame( 3, $result['concurrentSlotsLeft'] );
	}

	public function test_concurrent_slots_null_when_cap_disabled(): void {
		update_option(
			Settings::OPTION,
			array_merge( Settings::defaults(), [ 'max_concurrent_uploads_per_user' => 0 ] )
		);
		$GLOBALS['cf_chunked_upload_test_current_user'] = 7;
		$pf     = new Preflight( $this->paths, $this->settings );
		$result = $pf->check( 'import', 'file.zip', 1024 );
		self::assertNull( $result['concurrentSlotsLeft'] );
	}
}
