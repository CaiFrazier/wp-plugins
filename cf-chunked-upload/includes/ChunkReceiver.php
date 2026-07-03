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
 *
 * SEC-9 (abuse guards): before a chunk is written to disk this class enforces
 * three limits against the ACTUAL bytes on disk (never the client-declared
 * fileSize, which can be spoofed to 0): the per-user storage quota, an optional
 * per-session absolute byte ceiling, and available free disk space. All three
 * reject the chunk before it is stored, so a rejected byte never lands on disk.
 */
final class ChunkReceiver {

	const DESTINATIONS = [ 'media', 'import' ];

	/**
	 * SEC-9: probe free disk space on a session's first stored part and again
	 * every time its cumulative on-disk size crosses another multiple of this
	 * threshold. A byte-based cadence (rather than a chunk-index one) cannot be
	 * skipped by a client that chooses non-consecutive chunk indices. 256 MiB.
	 */
	const DISK_PROBE_THRESHOLD = 256 * 1048576;

	private Paths $paths;

	/**
	 * Chunk-time type gate.
	 *
	 * @var callable fn(string $file_name, string $mime, string $destination): bool
	 */
	private $mime_gate;

	private int $quota_limit_bytes;
	private int $session_max_bytes;
	private int $disk_min_free_bytes;

	/**
	 * Free-space probe, injectable so tests can simulate a full disk. Receives a
	 * directory path, returns bytes free or false (mirrors disk_free_space()).
	 *
	 * @var callable|null fn(string $dir): int|false
	 */
	private $disk_free_probe;

	/**
	 * Build a receiver with the abuse guards it should enforce.
	 *
	 * @param Paths         $paths               Filesystem layout helper.
	 * @param callable      $mime_gate           Chunk-time type gate.
	 * @param int           $quota_limit_bytes   Per-user quota ceiling; 0 = unlimited.
	 * @param int           $session_max_bytes   Per-session byte ceiling; 0 = unlimited.
	 * @param int           $disk_min_free_bytes Minimum free disk to keep after a chunk; 0 = disabled.
	 * @param callable|null $disk_free_probe     Optional free-space probe (defaults to disk_free_space).
	 */
	public function __construct(
		Paths $paths,
		callable $mime_gate,
		int $quota_limit_bytes = 0,
		int $session_max_bytes = 0,
		int $disk_min_free_bytes = 0,
		?callable $disk_free_probe = null
	) {
		$this->paths               = $paths;
		$this->mime_gate           = $mime_gate;
		$this->quota_limit_bytes   = max( 0, $quota_limit_bytes );
		$this->session_max_bytes   = max( 0, $session_max_bytes );
		$this->disk_min_free_bytes = max( 0, $disk_min_free_bytes );
		$this->disk_free_probe     = $disk_free_probe;
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
					// SEC-9: the accounting baseline is 0, NOT the client-declared
					// fileSize. set_file_size() grows it to the real bytes on disk
					// as chunks land, so quota can never be understated by a
					// spoofed or zero fileSize.
					'file_size'    => 0,
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

		// SEC-9: abuse guards, enforced against ACTUAL bytes before the chunk is
		// written so a rejected byte never lands on disk. parts_total_bytes() is
		// the authoritative on-disk size; the incoming/existing sizes let us
		// project what the session WOULD hold once this chunk is stored (an
		// idempotent retry of an already-present index nets zero new bytes).
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$incoming = (int) @filesize( $tmp_path );
		$existing = 0;
		if ( is_file( $session->chunk_path( $chunk_index ) ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$existing = (int) @filesize( $session->chunk_path( $chunk_index ) );
		}
		$prev_actual         = $session->parts_total_bytes();
		$prospective_session = max( 0, $prev_actual - $existing + $incoming );

		// (1) Per-session absolute ceiling. A single upload cannot grow without
		// bound even when the per-user quota is disabled or generous.
		if ( $this->session_max_bytes > 0 && $prospective_session > $this->session_max_bytes ) {
			return $this->err( 413, 'session_too_large', 'This upload exceeds the maximum allowed size for a single file.' );
		}

		// (2) Disk-space guard. Probe on the first stored part and whenever the
		// session's size crosses another DISK_PROBE_THRESHOLD boundary. Rejecting
		// here stops a client from streaming the volume to exhaustion mid-upload,
		// long before finalize's own disk check would fire.
		if ( $this->disk_min_free_bytes > 0 ) {
			$crossed = intdiv( $prospective_session, self::DISK_PROBE_THRESHOLD )
				> intdiv( $prev_actual, self::DISK_PROBE_THRESHOLD );
			if ( 0 === $session->received_count() || $crossed ) {
				$free = $this->probe_free_space( $this->paths->chunks_root() );
				if ( false !== $free && $free < ( $incoming + $this->disk_min_free_bytes ) ) {
					return $this->err( 507, 'insufficient_disk', 'The server is low on disk space. Try again later or contact your administrator.' );
				}
			}
		}

		// (3) Per-user quota. Reserve only the NEW bytes this chunk adds
		// (delta), reconciled against what the session has already reserved. A
		// spoofed fileSize is irrelevant: the delta is computed from real bytes.
		$owner_id = (int) ( $meta['owner_id'] ?? 0 );
		$delta    = $prospective_session - (int) ( $meta['file_size'] ?? 0 );
		if ( $this->quota_limit_bytes > 0 && $owner_id > 0
			&& ! UserQuota::check_and_reserve( $owner_id, $delta, $this->quota_limit_bytes ) ) {
			return $this->err( 507, 'quota_exceeded', 'Storage quota exceeded. Wait for in-progress uploads to finish or contact your administrator.' );
		}

		if ( ! $session->store_chunk( $chunk_index, $tmp_path ) ) {
			// Give back the reservation we just took so a write failure can't
			// permanently consume the user's quota.
			if ( $delta > 0 ) {
				UserQuota::release( $owner_id, $delta );
			}
			return $this->err( 500, 'chunk_store_failed', 'Could not write the chunk to temp storage.' );
		}

		// Persist the new authoritative size so releases (cancel/cleanup/
		// finalize) free exactly what was reserved.
		$session->set_file_size( $prospective_session );

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

	/**
	 * Bytes free on the filesystem holding $dir. Routes through the injected
	 * probe when present (tests) and falls back to disk_free_space().
	 *
	 * @param string $dir Directory to probe.
	 * @return int|false Bytes free, or false when the probe is unavailable.
	 */
	private function probe_free_space( string $dir ) {
		if ( null !== $this->disk_free_probe ) {
			return call_user_func( $this->disk_free_probe, $dir );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return @disk_free_space( $dir );
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
