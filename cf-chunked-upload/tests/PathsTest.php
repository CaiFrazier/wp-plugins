<?php

namespace CFChunkedUpload\Tests;

use CFChunkedUpload\Paths;
use CFChunkedUpload\UploadSession;
use PHPUnit\Framework\TestCase;

final class PathsTest extends TestCase {

	private string $sandbox;

	protected function setUp(): void {
		cf_chunked_upload_test_reset_state();
		$this->sandbox = sys_get_temp_dir() . '/cf-cu-paths-' . uniqid();
		mkdir( $this->sandbox, 0777, true );
	}

	protected function tearDown(): void {
		UploadSession::rrmdir( $this->sandbox );
	}

	public function test_roots_are_outside_uploads(): void {
		$p = new Paths( $this->sandbox );
		self::assertStringEndsWith( '/cf-chunks', $p->chunks_root() );
		self::assertStringEndsWith( '/cf-imports', $p->default_imports_dir() );
		self::assertStringNotContainsString( 'uploads', $p->chunks_root() );
	}

	public function test_trailing_slash_in_content_dir_is_normalized(): void {
		$p = new Paths( $this->sandbox . '/' );
		self::assertSame( $this->sandbox . '/cf-chunks', $p->chunks_root() );
	}

	public function test_ensure_hardened_dir_drops_silence_files(): void {
		$p   = new Paths( $this->sandbox );
		$dir = $p->chunks_root() . '/x';
		self::assertTrue( $p->ensure_hardened_dir( $dir ) );

		self::assertFileExists( $dir . '/.htaccess' );
		self::assertFileExists( $dir . '/index.php' );
		self::assertFileExists( $dir . '/web.config' );
		self::assertStringContainsString( 'Deny from all', file_get_contents( $dir . '/.htaccess' ) );
	}

	public function test_ensure_hardened_dir_is_idempotent(): void {
		$p   = new Paths( $this->sandbox );
		$dir = $p->chunks_root() . '/y';
		self::assertTrue( $p->ensure_hardened_dir( $dir ) );
		self::assertTrue( $p->ensure_hardened_dir( $dir ) );
	}
}
