<?php

namespace CFChunkedUpload\Tests;

use CFChunkedUpload\Paths;
use CFChunkedUpload\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	private Settings $settings;
	private string $sandbox;

	protected function setUp(): void {
		cf_chunked_upload_test_reset_state();
		$this->sandbox = sys_get_temp_dir() . '/cf-cu-set-' . uniqid();
		mkdir( $this->sandbox, 0777, true );
		$this->settings = new Settings( new Paths( $this->sandbox ) );
	}

	protected function tearDown(): void {
		\CFChunkedUpload\UploadSession::rrmdir( $this->sandbox );
	}

	public function test_defaults_returned_when_unset(): void {
		$s = $this->settings->get();
		self::assertSame( 8, $s['chunk_size_mb'] );
		self::assertSame( 3, $s['concurrency'] );
		self::assertSame( 'timestamp', $s['collision_policy'] );
	}

	public function test_numeric_fields_are_clamped(): void {
		$res = $this->settings->update(
			[
				'concurrency'       => 99,
				'max_retries'       => 0,
				'threshold_mb'      => -5,
				'cleanup_age_hours' => 9999,
			]
		);
		$s = $res['settings'];
		self::assertSame( 6, $s['concurrency'] );   // max 6
		self::assertSame( 1, $s['max_retries'] );   // min 1
		self::assertSame( 1, $s['threshold_mb'] );  // min 1
		self::assertSame( 168, $s['cleanup_age_hours'] ); // max 168
	}

	public function test_invalid_collision_policy_falls_back(): void {
		$res = $this->settings->update( [ 'collision_policy' => 'nuke' ] );
		self::assertSame( 'timestamp', $res['settings']['collision_policy'] );
	}

	public function test_extensions_are_normalized(): void {
		$res = $this->settings->update( [ 'allowed_extensions' => [ '.ZIP', 'tar.gz', ' Sql ', '!!!', 'zip' ] ] );
		$ext = $res['settings']['allowed_extensions'];
		self::assertContains( 'zip', $ext );
		self::assertContains( 'sql', $ext );
		self::assertNotContains( '', $ext );
		// De-duplicated.
		self::assertSame( count( $ext ), count( array_unique( $ext ) ) );
	}

	public function test_arbitrary_absolute_imports_dir_is_rejected(): void {
		$res = $this->settings->update( [ 'imports_dir' => '/etc' ] );
		self::assertSame( '', $res['settings']['imports_dir'] );
		self::assertNotEmpty( $res['notices'] );
		// Falls back to the default cf-imports location.
		self::assertStringEndsWith( '/cf-imports', $this->settings->imports_dir() );
	}

	public function test_imports_dir_inside_sandbox_is_accepted(): void {
		// Treat the sandbox as wp-content for this check.
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', $this->sandbox );
		}
		$target = $this->sandbox . '/backups';
		$res    = $this->settings->update( [ 'imports_dir' => $target ] );
		self::assertSame( $target, $res['settings']['imports_dir'] );
	}

	public function test_mime_gate_media_requires_known_type(): void {
		$gate = $this->settings->mime_gate();
		// wp_get_mime_types is not shimmed → gate falls back to "non-empty mime".
		self::assertTrue( $gate( 'a.zip', 'application/zip', 'media' ) );
		self::assertFalse( $gate( 'a.zip', '', 'media' ) );
	}

	public function test_mime_gate_importer_allowlist(): void {
		$this->settings->update( [ 'allowed_extensions' => [ 'zip', 'sql' ] ] );
		$gate = $this->settings->mime_gate();
		self::assertTrue( $gate( 'backup.zip', 'application/zip', 'import' ) );
		self::assertFalse( $gate( 'evil.php', 'application/x-php', 'import' ) );
	}

	public function test_mime_gate_importer_empty_allowlist_allows_all(): void {
		$gate = $this->settings->mime_gate();
		self::assertTrue( $gate( 'anything.xyz', 'application/octet-stream', 'import' ) );
	}

	public function test_mime_gate_importer_blocks_executables_even_with_empty_allowlist(): void {
		// Empty allowlist (the default) accepts arbitrary extensions, but the
		// always-on executable blocklist must still reject server-interpreted
		// types — otherwise the importer would accept a .php payload by default.
		$gate = $this->settings->mime_gate();
		self::assertFalse( $gate( 'shell.php', 'application/octet-stream', 'import' ) );
		self::assertFalse( $gate( 'page.phtml', '', 'import' ) );
		self::assertFalse( $gate( '.htaccess', '', 'import' ) );
	}

	public function test_mime_gate_importer_blocks_double_extension_bypass(): void {
		// The classic evil.php.jpg trick: a trailing "safe" extension hiding a
		// server-interpreted one. Every dotted segment is checked.
		$gate = $this->settings->mime_gate();
		self::assertFalse( $gate( 'evil.php.jpg', 'image/jpeg', 'import' ) );
		self::assertTrue( $gate( 'holiday.jpg', 'image/jpeg', 'import' ) );
	}

	public function test_is_blocked_extension(): void {
		self::assertTrue( Settings::is_blocked_extension( 'x.php' ) );
		self::assertTrue( Settings::is_blocked_extension( 'X.PHP5' ) );
		self::assertTrue( Settings::is_blocked_extension( 'a.php.txt' ) );
		self::assertTrue( Settings::is_blocked_extension( '.htpasswd' ) );
		self::assertFalse( Settings::is_blocked_extension( 'report.csv' ) );
		self::assertFalse( Settings::is_blocked_extension( 'archive.zip' ) );
		// "user" is no longer in the blocklist, so legit names are not caught.
		self::assertFalse( Settings::is_blocked_extension( 'jane.user.csv' ) );
	}

	public function test_administrator_capability_maps_to_manage_options(): void {
		$this->settings->update( [ 'importer_capability' => 'administrator' ] );
		self::assertSame( 'manage_options', $this->settings->importer_capability() );
	}
}
