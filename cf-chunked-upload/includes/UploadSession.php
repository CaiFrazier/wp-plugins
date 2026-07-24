<?php

namespace CFChunkedUpload;

defined( 'ABSPATH' ) || exit;

/**
 * One chunked-upload session, identified by a client-generated UUID v4. Owns
 * the on-disk layout for that session:
 *
 *   {chunks_root}/{uploadId}/
 *     .meta          JSON: fileName, mimeType, destination, totalChunks,
 *                    totalSha256, fileSize  — written ONCE by the first chunk
 *     .heartbeat     touched on every chunk so cleanup uses newest-file age
 *     {index}.part   one file per received chunk
 *
 * The .meta file is the server's source of truth for totalChunks. The plan's
 * original design trusted the per-request totalChunks field; a client could
 * then declare different totals across chunks (or two clients could collide on
 * one uploadId). First-writer-wins persistence closes that: ensure_meta() uses
 * an exclusive-create write, and every subsequent chunk validates its declared
 * fields against the persisted record.
 */
final class UploadSession {

	const META_FILE        = '.meta';
	const HEARTBEAT_FILE   = '.heartbeat';
	const ASSEMBLING_FILE  = '.assembling'; // SEC-7: signals finalize job queued or running
	const MAX_TOTAL_CHUNKS = 100000;

	private Paths $paths;
	private string $upload_id;
	private string $dir;

	/**
	 * Strict UUID v4. This is the ONLY gate before $upload_id becomes a
	 * directory component, so it must reject anything with path separators,
	 * dot segments, or NUL bytes — the regex's character class does exactly
	 * that by construction.
	 *
	 * @param string $id Candidate upload id.
	 * @return bool
	 */
	public static function is_valid_id( string $id ): bool {
		return 1 === preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$id
		);
	}

	public function __construct( Paths $paths, string $upload_id ) {
		if ( ! self::is_valid_id( $upload_id ) ) {
			throw new \InvalidArgumentException( 'Invalid upload id.' );
		}
		$this->paths     = $paths;
		$this->upload_id = $upload_id;
		$this->dir       = $paths->session_dir( $upload_id );
	}

	public function id(): string {
		return $this->upload_id;
	}

	public function dir(): string {
		return $this->dir;
	}

	public function exists(): bool {
		return is_dir( $this->dir );
	}

	/**
	 * Return the session metadata, creating it from $declared on first call.
	 * First-writer-wins: the meta file is created with an exclusive handle so
	 * a concurrent second chunk for the same id observes the already-written
	 * record rather than overwriting it. The returned array is always the
	 * PERSISTED record; the caller compares its own declared values against it
	 * and rejects mismatches.
	 *
	 * $declared keys: file_name, mime_type, destination, total_chunks,
	 * total_sha256 (whole-file digest if supplied at chunk time; empty for
	 * browser uploads, which send it in the finalize body), file_size
	 * (optional at chunk time).
	 *
	 * @param array $declared Declared session fields.
	 * @return array The persisted record.
	 */
	public function ensure_meta( array $declared ): array {
		$existing = $this->meta();
		if ( null !== $existing ) {
			return $existing;
		}

		if ( ! $this->paths->ensure_hardened_dir( $this->dir ) ) {
			throw new \RuntimeException( 'Could not create session directory.' );
		}

		$record = [
			'upload_id'    => $this->upload_id,
			'file_name'    => (string) ( $declared['file_name'] ?? '' ),
			'mime_type'    => (string) ( $declared['mime_type'] ?? '' ),
			'destination'  => (string) ( $declared['destination'] ?? '' ),
			'total_chunks' => (int) ( $declared['total_chunks'] ?? 0 ),
			'total_sha256' => (string) ( $declared['total_sha256'] ?? '' ),
			'file_size'    => (int) ( $declared['file_size'] ?? 0 ),
			'owner_id'     => (int) ( $declared['owner_id'] ?? 0 ),
			'created_at'   => time(),
		];

		$path = $this->dir . '/' . self::META_FILE;
		// 'x' = exclusive create: fails if another chunk already wrote it,
		// which is exactly the race we want first-writer-wins on.
		// Exclusive-create + raw write: WP_Filesystem has no atomic 'x'-mode
		// equivalent, and the first-writer-wins race guard depends on it.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$fp = @fopen( $path, 'xb' );
		if ( false === $fp ) {
			// Lost the race — re-read whatever the winner persisted.
			$reread = $this->meta();
			if ( null !== $reread ) {
				return $reread;
			}
			throw new \RuntimeException( 'Could not persist session metadata.' );
		}
		fwrite( $fp, (string) wp_json_encode( $record ) );
		fclose( $fp );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $record;
	}

	public function meta(): ?array {
		$path = $this->dir . '/' . self::META_FILE;
		if ( ! is_file( $path ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$raw = @file_get_contents( $path );
		if ( false === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	public function total_chunks(): int {
		$meta = $this->meta();
		return $meta ? (int) ( $meta['total_chunks'] ?? 0 ) : 0;
	}

	/**
	 * WordPress user id that created the session (0 = unknown/legacy).
	 * Recorded first-writer-wins like the rest of the meta record.
	 *
	 * @return int
	 */
	public function owner_id(): int {
		$meta = $this->meta();
		return $meta ? (int) ( $meta['owner_id'] ?? 0 ) : 0;
	}

	public function chunk_path( int $index ): string {
		return $this->dir . '/' . $index . '.part';
	}

	/**
	 * Move an uploaded temp file into place as {index}.part. rename() is
	 * atomic within a filesystem, so a half-written part is never observable;
	 * a retried chunk simply replaces the prior one (idempotent).
	 *
	 * @param int    $index      Zero-based chunk index.
	 * @param string $source_tmp Uploaded temp file path.
	 * @return bool
	 */
	public function store_chunk( int $index, string $source_tmp ): bool {
		$dest = $this->chunk_path( $index );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( @rename( $source_tmp, $dest ) ) {
			return true;
		}
		// Cross-device fallback (tmp on a different mount than wp-content).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		if ( @copy( $source_tmp, $dest ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $source_tmp );
			return true;
		}
		return false;
	}

	/** Sorted ascending list of received chunk indices. */
	public function received_indices(): array {
		if ( ! is_dir( $this->dir ) ) {
			return [];
		}
		$out = [];
		foreach ( (array) scandir( $this->dir ) as $entry ) {
			if ( 1 === preg_match( '/^(\d+)\.part$/', (string) $entry, $m ) ) {
				$out[] = (int) $m[1];
			}
		}
		sort( $out, SORT_NUMERIC );
		return $out;
	}

	public function received_count(): int {
		return count( $this->received_indices() );
	}

	/**
	 * Total bytes currently on disk across all .part files. This is the
	 * authoritative size of what will be assembled — unlike the
	 * client-declared file_size, it cannot be spoofed to bypass the disk
	 * pre-check.
	 *
	 * @return int
	 */
	public function parts_total_bytes(): int {
		if ( ! is_dir( $this->dir ) ) {
			return 0;
		}
		$sum = 0;
		foreach ( $this->received_indices() as $i ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$sz = @filesize( $this->chunk_path( $i ) );
			if ( false !== $sz ) {
				$sum += (int) $sz;
			}
		}
		return $sum;
	}

	/**
	 * True only when every index in [0, total_chunks) has a part file. Counts
	 * alone are insufficient — a duplicated index 0 plus a missing index 5
	 * has the right count but is not complete.
	 */
	public function has_all_chunks(): bool {
		$total = $this->total_chunks();
		if ( $total < 1 ) {
			return false;
		}
		$have = array_flip( $this->received_indices() );
		for ( $i = 0; $i < $total; $i++ ) {
			if ( ! isset( $have[ $i ] ) ) {
				return false;
			}
		}
		return true;
	}

	public function touch_heartbeat(): void {
		$path = $this->dir . '/' . self::HEARTBEAT_FILE;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
		@touch( $path );
	}

	// --- SEC-7: .assembling marker -------------------------------------------

	/**
	 * Touch the .assembling sentinel so Cleanup skips this session while the
	 * finalize job is queued or running. Called by RestApi::handle_finalize()
	 * immediately before the job is enqueued — this covers the WP-Cron queue
	 * delay window (potentially hours on low-traffic sites), not just the
	 * assembly phase itself.
	 */
	public function mark_assembling(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
		@touch( $this->dir . '/' . self::ASSEMBLING_FILE );
	}

	/**
	 * Remove the .assembling sentinel. Called by FinalizeJob::run() in its
	 * finally block after assembly succeeds, fails, or throws.
	 */
	public function unmark_assembling(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $this->dir . '/' . self::ASSEMBLING_FILE );
	}

	public function is_assembling(): bool {
		return is_file( $this->dir . '/' . self::ASSEMBLING_FILE );
	}

	// --- SEC-4: idempotent finalize ------------------------------------------

	/**
	 * The job id stored when /finalize first accepted this upload. A second
	 * /finalize call returns this id instead of enqueuing a new job, so a
	 * retried POST (e.g. lost response) polls the original job rather than
	 * scheduling duplicate assembly.
	 *
	 * @return string|null
	 */
	public function finalize_job_id(): ?string {
		$meta = $this->meta();
		if ( ! is_array( $meta ) || ! isset( $meta['finalize_job_id'] ) ) {
			return null;
		}
		return (string) $meta['finalize_job_id'];
	}

	/**
	 * Persist the job id into the session meta. Uses LOCK_EX so concurrent
	 * writes don't corrupt the JSON; last writer wins, which is benign (both
	 * writers use the same per-job status transient flow and the lock in
	 * FinalizeJob serialises actual assembly).
	 *
	 * @param string $job_id
	 * @return void
	 */
	public function set_finalize_job_id( string $job_id ): void {
		$meta = $this->meta();
		if ( null === $meta ) {
			return;
		}
		$meta['finalize_job_id'] = $job_id;
		$path                    = $this->dir . '/' . self::META_FILE;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $path, (string) wp_json_encode( $meta ), LOCK_EX );
	}

	/**
	 * SEC-2/SEC-9: persist the running ACTUAL byte total this session has
	 * reserved against the owner's quota. Called after each chunk is stored so
	 * the value tracks parts_total_bytes() rather than the client-declared
	 * fileSize (which is spoofable). Every quota-release site reads this field,
	 * so keeping it accurate keeps releases exact — a session that stored 40 MB
	 * releases 40 MB, no matter what fileSize the client claimed.
	 *
	 * Uses LOCK_EX read-modify-write like set_finalize_job_id(): a concurrent
	 * chunk for the same id may lose an update (last writer wins), which is the
	 * documented best-effort accounting trade-off in UserQuota.
	 *
	 * @param int $bytes Actual bytes reserved so far for this session.
	 * @return void
	 */
	public function set_file_size( int $bytes ): void {
		$meta = $this->meta();
		if ( null === $meta ) {
			return;
		}
		$meta['file_size'] = max( 0, $bytes );
		$path              = $this->dir . '/' . self::META_FILE;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $path, (string) wp_json_encode( $meta ), LOCK_EX );
	}

	/**
	 * Newest mtime across every file in the session dir (parts, .meta,
	 * .heartbeat). Cleanup ages a session by its NEWEST file so a long upload
	 * sitting at the retention boundary is never wiped mid-transfer. Returns 0
	 * when the directory is absent.
	 */
	public function newest_mtime(): int {
		if ( ! is_dir( $this->dir ) ) {
			return 0;
		}
		$newest = 0;
		foreach ( (array) scandir( $this->dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$mt = @filemtime( $this->dir . '/' . $entry );
			if ( false !== $mt && $mt > $newest ) {
				$newest = $mt;
			}
		}
		return $newest;
	}

	/**
	 * Count active sessions owned by $user_id. Used by the concurrent-upload
	 * cap (FEAT-8) to reject a new session when the user is already at their
	 * limit. Scans the chunks root; acceptable cost given small N in practice.
	 *
	 * @param Paths $paths   Plugin path helper.
	 * @param int   $user_id WordPress user id.
	 * @return int
	 */
	public static function count_user_sessions( Paths $paths, int $user_id ): int {
		$root = $paths->chunks_root();
		if ( ! is_dir( $root ) ) {
			return 0;
		}
		$count = 0;
		foreach ( (array) scandir( $root ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( ! self::is_valid_id( (string) $entry ) ) {
				continue;
			}
			$meta_path = $root . '/' . $entry . '/' . self::META_FILE;
			if ( ! is_file( $meta_path ) ) {
				continue;
			}
			$raw  = @file_get_contents( $meta_path );
			$meta = $raw ? json_decode( $raw, true ) : null;
			if ( is_array( $meta ) && isset( $meta['owner_id'] ) && (int) $meta['owner_id'] === $user_id ) {
				++$count;
			}
		}
		return $count;
	}

	public function delete(): void {
		self::rrmdir( $this->dir );
	}

	public static function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				self::rrmdir( $path );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $dir );
	}
}
