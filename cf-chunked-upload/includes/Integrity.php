<?php

namespace CFChunkedUpload;

defined( 'ABSPATH' ) || exit;

/**
 * SHA-256 integrity primitives.
 *
 * Per-chunk hashing catches network corruption and truncated chunks at upload
 * time; the whole-file hash (accumulated across chunks during streaming
 * assembly) catches assembly bugs and silent disk faults. A backup archive is
 * exactly the payload class where silent corruption is unacceptable, so both
 * checks are mandatory in the pipeline.
 */
final class Integrity {

	/** Streaming read block — bounds peak memory regardless of file size. */
	const BLOCK_BYTES = 1048576;

	public static function hash_bytes( string $bytes ): string {
		return hash( 'sha256', $bytes );
	}

	/**
	 * SHA-256 of a file, read in fixed blocks so an 8 GB part never lands in
	 * memory. Returns the lowercase hex digest, or null if the file cannot be
	 * opened.
	 *
	 * @param string $path Absolute file path.
	 * @return string|null
	 */
	public static function hash_file( string $path ): ?string {
		if ( ! is_file( $path ) ) {
			return null;
		}
		$ctx = hash_init( 'sha256' );
		// Streaming hash: fixed-block reads keep an 8 GB file out of memory.
		// WP_Filesystem only offers whole-file get_contents(), which would
		// buffer the entire file — direct fopen/fread/fclose are intentional.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$fp = fopen( $path, 'rb' );
		if ( false === $fp ) {
			return null;
		}
		while ( ! feof( $fp ) ) {
			$buf = fread( $fp, self::BLOCK_BYTES );
			if ( false === $buf ) {
				fclose( $fp );
				return null;
			}
			hash_update( $ctx, $buf );
		}
		fclose( $fp );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return hash_final( $ctx );
	}

	/**
	 * Constant-time, case-insensitive comparison of two hex digests. A plain
	 * === would leak timing and break on uppercase client digests.
	 *
	 * @param string $a Hex digest.
	 * @param string $b Hex digest.
	 * @return bool
	 */
	public static function digests_match( string $a, string $b ): bool {
		return hash_equals( strtolower( $a ), strtolower( $b ) );
	}

	public static function is_sha256_hex( string $value ): bool {
		return 1 === preg_match( '/^[0-9a-f]{64}$/i', $value );
	}
}
