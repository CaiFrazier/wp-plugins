<?php

namespace CFChunkedUpload;

defined( 'ABSPATH' ) || exit;

/**
 * Business logic for POST /chunk, decoupled from WP_REST_Request so it is
 * unit-testable without a WP runtime. RestApi adapts the request (params +
 * $_FILES temp path + nonce/capability) into a call here.
 *
 * Validation order is deliberate: cheap structural checks first, the
 * expensive per-chunk hash last, and the metadata-conflict check only after
 * the bytes are known good — a corrupted chunk should not be able to create
 * or mutate session state.
 *
 * MIME note: at chunk time only an allowlist/declared check is possible —
 * a partial chunk cannot be finfo-inspected. The authoritative content-based
 * MIME check happens once, post-assembly, in the finalize job.
 */
final class ChunkReceiver {

	const DESTINATIONS = [ 'media', 'import' ];

	private Paths $paths;

	/**
	 * Chunk-time type gate.
	 *
	 * @var callable fn(string $file_name, string $mime, string $destination): bool
	 */
	private $mime_gate;

	public function __construct( Paths $paths, callable $mime_gate ) {
		$this->paths     = $paths;
		$this->mime_gate = $mime_gate;
	}

	/**
	 * Validate and store one chunk.
	 *
	 * @param array  $input    Parsed request fields.
	 * @param string $tmp_path Server-side temp path of the uploaded chunk blob.
	 * @return array{ok:bool, status:int, data?:array, error?:string, message?:string}
	 */
	public function receive( array $input, string $tmp_path ): array {
		$upload_id = (string) ( $input['upload_id'] ?? '' );
		if ( ! UploadSession::is_valid_id( $upload_id ) ) {
			return $this->err( 400, 'invalid_upload_id', 'Malformed upload id.' );
		}

		$destination = (string) ( $input['destination'] ?? '' );
		if ( ! in_array( $destination, self::DESTINATIONS, true ) ) {
			return $this->err( 400, 'invalid_destination', 'Destination must be "media" or "import".' );
		}

		if ( ! self::is_intish( $input['chunk_index'] ?? null ) || ! self::is_intish( $input['total_chunks'] ?? null ) ) {
			return $this->err( 400, 'invalid_indices', 'chunk_index and total_chunks must be integers.' );
		}
		$chunk_index  = (int) $input['chunk_index'];
		$total_chunks = (int) $input['total_chunks'];

		if ( $total_chunks < 1 || $total_chunks > UploadSession::MAX_TOTAL_CHUNKS ) {
			return $this->err( 400, 'invalid_total_chunks', 'total_chunks is out of range.' );
		}
		if ( $chunk_index < 0 || $chunk_index >= $total_chunks ) {
			return $this->err( 400, 'chunk_index_out_of_range', 'chunk_index is outside [0, total_chunks).' );
		}

		$file_name = sanitize_file_name( (string) ( $input['file_name'] ?? '' ) );
		if ( '' === $file_name ) {
			return $this->err( 400, 'invalid_file_name', 'A file name is required.' );
		}

		$mime_type = (string) ( $input['mime_type'] ?? '' );
		if ( ! call_user_func( $this->mime_gate, $file_name, $mime_type, $destination ) ) {
			return $this->err( 415, 'disallowed_type', 'This file type is not allowed for the selected destination.' );
		}

		$chunk_sha256 = (string) ( $input['chunk_sha256'] ?? '' );
		if ( ! Integrity::is_sha256_hex( $chunk_sha256 ) ) {
			return $this->err( 400, 'invalid_chunk_hash', 'chunk_sha256 must be a 64-char hex digest.' );
		}

		if ( '' === $tmp_path || ! is_file( $tmp_path ) ) {
			return $this->err( 400, 'no_chunk_body', 'No chunk payload was received.' );
		}

		// Verify the bytes BEFORE touching session state. A corrupted or
		// truncated chunk must not be able to create the session or store a
		// part. 422 = the entity is well-formed but failed integrity.
		$actual = Integrity::hash_file( $tmp_path );
		if ( null === $actual || ! Integrity::digests_match( $actual, $chunk_sha256 ) ) {
			return $this->err( 422, 'chunk_integrity_mismatch', 'Chunk hash did not match; the chunk was corrupted in transit.' );
		}

		try {
			$session = new UploadSession( $this->paths, $upload_id );
		} catch ( \InvalidArgumentException $e ) {
			return $this->err( 400, 'invalid_upload_id', 'Malformed upload id.' );
		}

		try {
			$meta = $session->ensure_meta(
				[
					'file_name'    => $file_name,
					'mime_type'    => $mime_type,
					'destination'  => $destination,
					'total_chunks' => $total_chunks,
					'total_sha256' => (string) ( $input['total_sha256'] ?? '' ),
					'file_size'    => (int) ( $input['file_size'] ?? 0 ),
					'owner_id'     => (int) ( $input['owner_id'] ?? 0 ),
				]
			);
		} catch ( \RuntimeException $e ) {
			return $this->err( 500, 'session_init_failed', 'Could not initialize the upload session on disk.' );
		}

		// The persisted record is authoritative. A chunk that disagrees with
		// it is either a buggy client splitting inconsistently or a second
		// client colliding on the same uploadId — reject either way.
		if ( (int) $meta['total_chunks'] !== $total_chunks
			|| (string) $meta['file_name'] !== $file_name
			|| (string) $meta['destination'] !== $destination ) {
			return $this->err( 409, 'session_conflict', 'This chunk disagrees with the established session metadata.' );
		}

		if ( ! $session->store_chunk( $chunk_index, $tmp_path ) ) {
			return $this->err( 500, 'chunk_store_failed', 'Could not write the chunk to temp storage.' );
		}

		$session->touch_heartbeat();

		$received = $session->received_count();
		return [
			'ok'     => true,
			'status' => 200,
			'data'   => [
				'received'  => $chunk_index,
				'count'     => $received,
				'remaining' => max( 0, $total_chunks - $received ),
				'complete'  => $session->has_all_chunks(),
			],
		];
	}

	private static function is_intish( $v ): bool {
		return is_int( $v ) || ( is_string( $v ) && 1 === preg_match( '/^\d+$/', $v ) );
	}

	private function err( int $status, string $code, string $message ): array {
		return [
			'ok'      => false,
			'status'  => $status,
			'error'   => $code,
			'message' => $message,
		];
	}
}
