<?php

namespace CFChunkedUpload;

defined( 'ABSPATH' ) || exit;

/**
 * Streaming reassembly. Never holds more than one block in memory, so an 8 GB
 * file assembles with ~1 MB peak. Each part is unlink()ed immediately after
 * its bytes are appended, so peak disk usage stays near 1x the final file
 * size rather than 2x (worst case is 2x only at the very start, which the
 * caller's disk pre-check accounts for).
 *
 * The whole-file SHA-256 is accumulated during the single pass — no second
 * read of the assembled file is needed to verify it.
 *
 * Pure with respect to WordPress: returns a result array, never a WP_Error,
 * so it is unit-testable without a WP runtime. The REST/job layer maps the
 * result to the wire shape.
 */
final class Assembler {

	const BLOCK_BYTES = 1048576;

	/**
	 * Stream every chunk into one file, hashing as we go.
	 *
	 * @param UploadSession $session     The session whose parts to assemble.
	 * @param string        $dest_path   Absolute path to write the assembled file.
	 * @param callable|null $on_progress Optional fn(int $done, int $total)
	 *                                   invoked after each part is consumed,
	 *                                   for finalize-job progress reporting.
	 * @return array{ok:bool, sha256?:string, size?:int, error?:string, message?:string}
	 */
	public static function assemble( UploadSession $session, string $dest_path, ?callable $on_progress = null ): array {
		$total = $session->total_chunks();
		if ( $total < 1 ) {
			return self::fail( 'no_metadata', 'Session metadata missing or has no chunk count.' );
		}
		if ( ! $session->has_all_chunks() ) {
			return self::fail( 'incomplete', 'Not all chunks are present; cannot assemble.' );
		}

		// Streaming reassembler: one ~1 MB block at a time so an 8 GB file never
		// lands in memory. WP_Filesystem exposes only whole-file get_contents()/
		// put_contents(), which would buffer the entire file and defeat the
		// plugin's reason to exist — direct fopen/fread/fwrite/fclose are
		// intentional and unavoidable here.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$out = fopen( $dest_path, 'wb' );
		if ( false === $out ) {
			return self::fail( 'dest_unwritable', 'Could not open the assembly destination for writing.' );
		}

		$ctx     = hash_init( 'sha256' );
		$written = 0;

		for ( $i = 0; $i < $total; $i++ ) {
			$part = $session->chunk_path( $i );

			// A zero-byte part in a multi-chunk upload is corruption (a
			// partial store, a truncating copy fallback). Every slice of a
			// non-empty file is non-empty; only a single-chunk empty-file
			// upload legitimately has a 0-byte part. Reject otherwise so
			// silent truncation can't reach the destination even when no
			// whole-file digest was supplied to catch it downstream.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$psize = @filesize( $part );
			if ( $total > 1 && ( false === $psize || 0 === $psize ) ) {
				fclose( $out );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $dest_path );
				return self::fail( 'empty_part', sprintf( 'Chunk %d is empty or missing; refusing to assemble a truncated file.', $i ) );
			}

			$in = fopen( $part, 'rb' );
			if ( false === $in ) {
				fclose( $out );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $dest_path );
				return self::fail( 'part_unreadable', sprintf( 'Chunk %d could not be read during assembly.', $i ) );
			}

			while ( ! feof( $in ) ) {
				$buf = fread( $in, self::BLOCK_BYTES );
				if ( false === $buf ) {
					fclose( $in );
					fclose( $out );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					@unlink( $dest_path );
					return self::fail( 'read_error', sprintf( 'Read error in chunk %d during assembly.', $i ) );
				}
				if ( '' === $buf ) {
					continue;
				}
				$n = fwrite( $out, $buf );
				if ( false === $n || strlen( $buf ) !== $n ) {
					fclose( $in );
					fclose( $out );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					@unlink( $dest_path );
					return self::fail( 'write_error', 'Write error during assembly (disk full?).' );
				}
				hash_update( $ctx, $buf );
				$written += $n;
			}

			fclose( $in );
			// Drop the part as soon as its bytes are committed so peak disk
			// stays near 1x final size.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $part );

			if ( null !== $on_progress ) {
				$on_progress( $i + 1, $total );
			}
		}

		fclose( $out );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return [
			'ok'     => true,
			'sha256' => hash_final( $ctx ),
			'size'   => $written,
		];
	}

	private static function fail( string $code, string $message ): array {
		return [
			'ok'      => false,
			'error'   => $code,
			'message' => $message,
		];
	}
}
