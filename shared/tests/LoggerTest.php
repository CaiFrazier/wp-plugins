<?php

use CFShared\Logger;
use PHPUnit\Framework\TestCase;

/**
 * WP function shims. Each test creates its own scratch log dir.
 */
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		return [ 'error' => '', 'basedir' => $GLOBALS['cf_shared_test_basedir'] ];
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $s ) {
		return rtrim( $s, '/' ) . '/';
	}
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $p ) {
		return is_dir( $p ) || mkdir( $p, 0755, true );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $d, $f = 0 ) {
		return json_encode( $d, $f );
	}
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		return bin2hex( random_bytes( 16 ) );
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 0;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = null ) {
		return $GLOBALS['cf_shared_test_options'][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['cf_shared_test_options'][ $key ] = $value;
		return true;
	}
}

final class LoggerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['cf_shared_test_basedir'] = sys_get_temp_dir() . '/cfshared-' . bin2hex( random_bytes( 4 ) );
		$GLOBALS['cf_shared_test_options'] = [];
		mkdir( $GLOBALS['cf_shared_test_basedir'], 0755, true );
	}

	protected function tearDown(): void {
		$this->rrmdir( $GLOBALS['cf_shared_test_basedir'] );
	}

	public function test_for_plugin_rejects_invalid_slug(): void {
		$this->expectException( \InvalidArgumentException::class );
		Logger::for_plugin( [ 'slug' => 'Bad Slug With Spaces' ] );
	}

	public function test_for_plugin_requires_slug(): void {
		$this->expectException( \InvalidArgumentException::class );
		Logger::for_plugin( [] );
	}

	public function test_writes_jsonl_entry_at_warn_or_above_by_default(): void {
		$logger = Logger::for_plugin( [ 'slug' => 'unit-warn' ] );
		$logger->warn( 'test-channel', 'a warning', [ 'k' => 'v' ] );

		$lines = $this->read_log_lines( 'unit-warn' );
		$this->assertCount( 1, $lines );
		$entry = json_decode( $lines[0], true );
		$this->assertSame( 'warn', $entry['level'] );
		$this->assertSame( 'test-channel', $entry['channel'] );
		$this->assertSame( 'a warning', $entry['message'] );
		$this->assertSame( 'v', $entry['context']['k'] );
	}

	public function test_info_is_suppressed_under_default_warn_threshold(): void {
		$logger = Logger::for_plugin( [ 'slug' => 'unit-info' ] );
		$logger->info( 'channel', 'should not write' );

		$this->assertSame( [], $this->read_log_lines( 'unit-info' ) );
	}

	public function test_debug_constant_lowers_threshold(): void {
		if ( ! defined( 'UNIT_DEBUG' ) ) {
			define( 'UNIT_DEBUG', true );
		}
		$logger = Logger::for_plugin( [ 'slug' => 'unit-debug', 'debug_constant' => 'UNIT_DEBUG' ] );
		$logger->debug( 'channel', 'debug visible' );

		$lines = $this->read_log_lines( 'unit-debug' );
		$this->assertCount( 1, $lines );
		$entry = json_decode( $lines[0], true );
		$this->assertSame( 'debug', $entry['level'] );
	}

	public function test_threshold_resolver_callback(): void {
		$logger = Logger::for_plugin(
			[
				'slug'               => 'unit-resolver',
				'threshold_resolver' => static function () {
					return Logger::LEVEL_INFO;
				},
			]
		);
		$logger->info( 'channel', 'now visible' );

		$lines = $this->read_log_lines( 'unit-resolver' );
		$this->assertCount( 1, $lines );
		$this->assertSame( 'info', json_decode( $lines[0], true )['level'] );
	}

	public function test_secrets_are_scrubbed_from_context(): void {
		$logger = Logger::for_plugin( [ 'slug' => 'unit-scrub' ] );
		$logger->warn(
			'sec',
			'auth event',
			[
				'password' => 'plaintext',
				'TOKEN'    => 'abc123',
				'nested'   => [ 'cookie' => 'session=xyz' ],
				'safe'     => 'kept',
			]
		);

		$entry = json_decode( $this->read_log_lines( 'unit-scrub' )[0], true );
		$this->assertSame( '[redacted]', $entry['context']['password'] );
		$this->assertSame( '[redacted]', $entry['context']['TOKEN'] );
		$this->assertSame( '[redacted]', $entry['context']['nested']['cookie'] );
		$this->assertSame( 'kept', $entry['context']['safe'] );
	}

	public function test_oversized_string_values_are_truncated(): void {
		$logger = Logger::for_plugin( [ 'slug' => 'unit-trunc' ] );
		$logger->warn( 'sec', 'big', [ 'blob' => str_repeat( 'x', 5000 ) ] );

		$entry = json_decode( $this->read_log_lines( 'unit-trunc' )[0], true );
		$this->assertStringContainsString( '…[truncated', $entry['context']['blob'] );
		$this->assertLessThan( 5000, strlen( $entry['context']['blob'] ) );
	}

	public function test_read_tail_returns_newest_first(): void {
		$logger = Logger::for_plugin( [ 'slug' => 'unit-tail' ] );
		$logger->warn( 'c', 'first' );
		$logger->warn( 'c', 'second' );
		$logger->warn( 'c', 'third' );

		$tail = $logger->read_tail( 10 );
		$this->assertCount( 3, $tail );
		$this->assertSame( 'third', $tail[0]['message'] );
		$this->assertSame( 'second', $tail[1]['message'] );
		$this->assertSame( 'first', $tail[2]['message'] );
	}

	public function test_slug_determines_log_subdirectory(): void {
		$a = Logger::for_plugin( [ 'slug' => 'plugin-a' ] );
		$b = Logger::for_plugin( [ 'slug' => 'plugin-b' ] );
		$a->warn( 'c', 'a-line' );
		$b->warn( 'c', 'b-line' );

		$this->assertDirectoryExists( $GLOBALS['cf_shared_test_basedir'] . '/plugin-a' );
		$this->assertDirectoryExists( $GLOBALS['cf_shared_test_basedir'] . '/plugin-b' );
	}

	private function read_log_lines( string $slug ): array {
		$dir = $GLOBALS['cf_shared_test_basedir'] . '/' . $slug;
		if ( ! is_dir( $dir ) ) {
			return [];
		}
		$files = glob( $dir . '/log-*.jsonl' );
		if ( ! $files ) {
			return [];
		}
		$content = file_get_contents( $files[0] );
		return array_values( array_filter( explode( "\n", $content ), 'strlen' ) );
	}

	private function rrmdir( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$full = $path . '/' . $entry;
			is_dir( $full ) ? $this->rrmdir( $full ) : @unlink( $full );
		}
		@rmdir( $path );
	}
}
