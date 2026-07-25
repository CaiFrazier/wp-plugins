<?php

namespace CFChunkedUpload;

defined( 'ABSPATH' ) || exit;

/**
 * Background reassembly worker. Runs out of the request thread (Action
 * Scheduler, WP-Cron fallback) so multi-minute assembly never depends on a
 * host-specific max_execution_time and we never call set_time_limit() —
 * the snag WP.org review flags for large-file plugins.
 *
 * Concurrency: /finalize can be hit twice (double-click, retried POST). An
 * atomic add_option lock keyed by uploadId guarantees a single assembler per
 * upload; a duplicate dispatch bails immediately. Cancel deletes the session
 * directory, so the worker also re-checks session presence right before it
 * commits — a cancel that lands mid-flight aborts cleanly instead of moving a
 * file the user abandoned.
 */
final class FinalizeJob {

	const ACTION_HOOK = 'cf_chunked_upload_finalize';
	const AS_GROUP    = 'cf-chunked-upload';
	const LOCK_PREFIX = 'cf_cu_finalize_lock_';

	/**
	 * Lock lifetime. It bounds the ASSEMBLY phase only (the lock is taken
	 * when the job executes, after the upload has fully completed) — not the
	 * upload, which can run ~30 min for 8 GB and is finished before any lock
	 * exists. 2 h is generous headroom for streaming-assembling a very large
	 * file on slow storage; if a worker genuinely exceeds it the steal path
	 * is race-safe (see acquire_lock).
	 */
	const LOCK_TTL = 7200;

	private Paths $paths;
	private Settings $settings;

	public function __construct( Paths $paths, Settings $settings ) {
		$this->paths    = $paths;
		$this->settings = $settings;
	}

	public function register_hooks(): void {
		add_action( self::ACTION_HOOK, [ $this, 'run' ], 10, 3 );
	}

	public static function action_scheduler_available(): bool {
		return function_exists( 'as_schedule_single_action' );
	}

	/**
	 * Schedule the background assembly (Action Scheduler, WP-Cron fallback).
	 *
	 * @param string $job_id       Job identifier.
	 * @param string $upload_id    Upload session id.
	 * @param string $expected_sha Client whole-file SHA-256 (hex), or ''.
	 * @return void
	 */
	public static function enqueue( string $job_id, string $upload_id, string $expected_sha = '' ): void {
		JobStatus::pending( $job_id );
		$args = [ $job_id, $upload_id, $expected_sha ];
		if ( self::action_scheduler_available() ) {
			as_schedule_single_action( time(), self::ACTION_HOOK, $args, self::AS_GROUP );
			return;
		}
		wp_schedule_single_event( time() + 1, self::ACTION_HOOK, $args );

		// Poke WP-Cron via a non-blocking loopback so finalize runs now rather
		// than waiting for the next page load. Pattern matches WP core's
		// spawn_cron(): doing_wp_cron carries a microtime value that WP's own
		// cron concurrency lock keys on, so we don't defeat that lock.
		$doing_wp_cron = sprintf( '%.22F', microtime( true ) );
		$cron_url      = add_query_arg( 'doing_wp_cron', $doing_wp_cron, site_url( 'wp-cron.php' ) );
		wp_remote_post(
			$cron_url,
			[
				'timeout'   => 0.01,
				'blocking'  => false,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter (wp-includes/http.php), not a plugin-defined hook.
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			]
		);
	}

	/**
	 * Action handler. $upload_id is already UUID-validated by the enqueue
	 * path, but we re-validate defensively since cron args round-trip through
	 * storage.
	 *
	 * @param string $job_id       Job identifier.
	 * @param string $upload_id    Upload session id.
	 * @param string $expected_sha Client whole-file SHA-256 (hex), or ''.
	 * @return void
	 */
	public function run( string $job_id, string $upload_id, string $expected_sha = '' ): void {
		if ( ! UploadSession::is_valid_id( $upload_id ) ) {
			JobStatus::error( $job_id, 'invalid_upload_id', 'Malformed upload id.' );
			return;
		}

		if ( ! $this->acquire_lock( $upload_id ) ) {
			// A peer worker owns this upload and will publish the result.
			// The .assembling marker was set by handle_finalize(); leave it for
			// the lock holder to unmark in its finally block (SEC-7).
			return;
		}

		try {
			JobStatus::running( $job_id, 0.0 );
			// Throttle progress writes: a 1,024-chunk assembly shouldn't do
			// 1,024 transient writes. Update at most every ~2% (and always
			// on the final chunk).
			$last     = -1;
			$progress = static function ( int $done, int $total ) use ( $job_id, &$last ) {
				$pct = $total > 0 ? (int) floor( ( $done / $total ) * 100 ) : 100;
				if ( $pct !== $last && ( 0 === $pct % 2 || $done === $total ) ) {
					$last = $pct;
					JobStatus::running( $job_id, $done / max( 1, $total ) );
				}
			};
			$result   = $this->process( $upload_id, $expected_sha, $progress );
			if ( $result['ok'] ) {
				JobStatus::complete( $job_id, $result['result'] );
			} else {
				JobStatus::error( $job_id, $result['error'], $result['message'] );
			}
		} catch ( \Throwable $e ) {
			JobStatus::error( $job_id, 'unexpected', $e->getMessage() );
		} finally {
			// SEC-7: unmark .assembling now that the job is done (or threw).
			// Unlink before releasing the lock so cleanup never observes both
			// "no lock" and "no marker" simultaneously.
			$assembling = $this->paths->session_dir( $upload_id ) . '/' . UploadSession::ASSEMBLING_FILE;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $assembling );
			$this->release_lock( $upload_id );
		}
	}

	/**
	 * The full pipeline, returning a result array. Pure with respect to the
	 * job/status layer so it is unit-testable for the importer destination.
	 *
	 * @param string        $upload_id    Upload session id.
	 * @param string        $expected_sha Client whole-file SHA-256 (hex).
	 *                                    Falls back to the first-chunk meta
	 *                                    digest when ''.
	 * @param callable|null $on_progress  Optional fn(int $done, int $total)
	 *                                    forwarded to the assembler.
	 * @return array{ok:bool, result?:array, error?:string, message?:string}
	 */
	public function process( string $upload_id, string $expected_sha = '', ?callable $on_progress = null ): array {
		try {
			$session = new UploadSession( $this->paths, $upload_id );
		} catch ( \InvalidArgumentException $e ) {
			return $this->fail( 'invalid_upload_id', 'Malformed upload id.' );
		}

		$meta = $session->meta();
		if ( null === $meta ) {
			return $this->fail( 'no_session', 'Upload session not found or already finalized.' );
		}
		if ( ! $session->has_all_chunks() ) {
			return $this->fail( 'incomplete', 'Not all chunks were received.' );
		}

		// Size the disk pre-check off the actual bytes on disk, not the
		// client-declared file_size (a client sending fileSize:0 would
		// otherwise skip the check entirely).
		$actual_size = $session->parts_total_bytes();
		$space_err   = $this->check_disk( $session->dir(), $actual_size );
		if ( null !== $space_err ) {
			return $this->fail( 'insufficient_disk', $space_err );
		}

		// Past this point the assembler consumes (unlinks) parts as it runs,
		// so a failure leaves the session unable to ever satisfy
		// has_all_chunks() again. Reap it on every post-assembly failure so
		// the state is unambiguous — "session gone, start over" — instead of
		// lingering as a half-eaten directory until the cleanup cron.
		$assembled = $session->dir() . '/assembled.tmp';
		$asm       = Assembler::assemble( $session, $assembled, $on_progress );
		if ( ! $asm['ok'] ) {
			$this->delete_session_with_quota_release( $session, $meta );
			return $this->fail( $asm['error'], $asm['message'] );
		}

		// Whole-file integrity. The finalize-supplied digest is authoritative
		// (the client finishes hashing by upload's end); fall back to the
		// first-chunk meta digest (non-empty only for programmatic callers
		// that hash upfront), then to "no check" for callers that supply
		// neither.
		$expected = '' !== $expected_sha ? $expected_sha : (string) ( $meta['total_sha256'] ?? '' );
		if ( '' !== $expected && Integrity::is_sha256_hex( $expected )
			&& ! Integrity::digests_match( $asm['sha256'], $expected ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $assembled );
			$this->delete_session_with_quota_release( $session, $meta );
			return $this->fail( 'integrity_mismatch', 'Assembled file failed whole-file hash verification.' );
		}

		// Content-based MIME — the authoritative check (extension/declared
		// MIME can lie). Done once, here, on the complete file.
		$detected    = $this->detect_mime( $assembled );
		$declared    = (string) ( $meta['mime_type'] ?? '' );
		$destination = (string) ( $meta['destination'] ?? '' );

		if ( 'media' === $destination ) {
			if ( function_exists( 'wp_get_mime_types' )
				&& null !== $detected
				&& ! in_array( $detected, array_values( wp_get_mime_types() ), true ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $assembled );
				$this->delete_session_with_quota_release( $session, $meta );
				return $this->fail( 'disallowed_type', 'Detected file type is not permitted in the media library.' );
			}
			return $this->place_media( $session, $assembled, $meta );
		}

		// Importer destination. The always-on executable-extension blocklist is
		// re-checked here against the real stored name — it holds even when the
		// allowlist is the (everything-accepting) empty default.
		if ( Settings::is_blocked_extension( (string) $meta['file_name'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $assembled );
			$this->delete_session_with_quota_release( $session, $meta );
			return $this->fail( 'disallowed_type', 'File type is not permitted by the importer.' );
		}

		// Importer extension allowlist re-check against the real name.
		$allowed = $this->settings->allowed_extensions();
		if ( [] !== $allowed ) {
			$ext = strtolower( (string) pathinfo( (string) $meta['file_name'], PATHINFO_EXTENSION ) );
			if ( '' === $ext || ! in_array( $ext, $allowed, true ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $assembled );
				$this->delete_session_with_quota_release( $session, $meta );
				return $this->fail( 'disallowed_type', 'File extension is not in the importer allowlist.' );
			}
		}

		return $this->place_import( $session, $assembled, $meta, $detected, $declared );
	}

	/**
	 * Move the assembled file into the configured imports directory applying
	 * the collision policy. Re-checks the session still exists immediately
	 * before the move so a cancel that landed during assembly aborts here.
	 *
	 * @param UploadSession $session   The upload session.
	 * @param string        $assembled Path to the assembled temp file.
	 * @param array         $meta      Session metadata.
	 * @param string|null   $detected  Content-sniffed MIME, if any.
	 * @param string        $declared  Client-declared MIME.
	 * @return array{ok:bool, result?:array, error?:string, message?:string}
	 */
	public function place_import( UploadSession $session, string $assembled, array $meta, ?string $detected, string $declared ): array {
		if ( ! $session->exists() ) {
			return $this->fail( 'cancelled', 'Upload was cancelled before it could be finalized.' );
		}

		$dir = $this->settings->imports_dir();
		if ( ! $this->paths->ensure_hardened_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $assembled );
			$this->delete_session_with_quota_release( $session, $meta );
			return $this->fail( 'imports_dir_unwritable', 'Imports directory could not be created.' );
		}

		$name   = sanitize_file_name( (string) $meta['file_name'] );
		$target = rtrim( $dir, '/' ) . '/' . $name;

		if ( file_exists( $target ) ) {
			switch ( $this->settings->collision_policy() ) {
				case 'reject':
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					@unlink( $assembled );
					$this->delete_session_with_quota_release( $session, $meta );
					return $this->fail( 'name_collision', 'A file with this name already exists and the collision policy is "reject".' );
				case 'overwrite':
					break;
				case 'timestamp':
				default:
					$ext    = pathinfo( $name, PATHINFO_EXTENSION );
					$base   = pathinfo( $name, PATHINFO_FILENAME );
					$stamp  = gmdate( 'Y-m-d-His' );
					$name   = $base . '-' . $stamp . ( '' !== $ext ? '.' . $ext : '' );
					$target = rtrim( $dir, '/' ) . '/' . $name;
					break;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! @rename( $assembled, $target ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			if ( ! @copy( $assembled, $target ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $assembled );
				$this->delete_session_with_quota_release( $session, $meta );
				return $this->fail( 'move_failed', 'Could not move the assembled file into the imports directory.' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $assembled );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$fsize = @filesize( $target );
		$size  = false === $fsize ? 0 : (int) $fsize;
		$this->delete_session_with_quota_release( $session, $meta );

		return [
			'ok'     => true,
			'result' => [
				'destination' => 'import',
				'path'        => $target,
				'fileName'    => $name,
				'fileSize'    => $size,
				'mimeType'    => $detected ?? $declared,
			],
		];
	}

	/**
	 * Media Library destination — hand the assembled file to
	 * media_handle_sideload() (the documented WP sideload entry point, which
	 * runs wp_handle_sideload + wp_check_filetype_and_ext internally). This is
	 * cleaner and safer than mutating the $_FILES superglobal. Needs the WP
	 * media stack, so it is exercised in integration, not the unit suite.
	 *
	 * @param UploadSession $session   The upload session.
	 * @param string        $assembled Path to the assembled temp file.
	 * @param array         $meta      Session metadata.
	 * @return array{ok:bool, result?:array, error?:string, message?:string}
	 */
	public function place_media( UploadSession $session, string $assembled, array $meta ): array {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if ( ! $session->exists() ) {
			return $this->fail( 'cancelled', 'Upload was cancelled before it could be finalized.' );
		}

		$name = sanitize_file_name( (string) $meta['file_name'] );
		$file = [
			'name'     => $name,
			'type'     => (string) $meta['mime_type'],
			'tmp_name' => $assembled,
			'error'    => 0,
			'size'     => $this->safe_filesize( $assembled ),
		];

		$attachment_id = media_handle_sideload( $file, 0 );
		if ( is_wp_error( $attachment_id ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $assembled );
			$this->delete_session_with_quota_release( $session, $meta );
			return $this->fail( 'sideload_failed', $attachment_id->get_error_message() );
		}

		$this->delete_session_with_quota_release( $session, $meta );

		return [
			'ok'     => true,
			'result' => [
				'destination'  => 'media',
				'attachmentId' => (int) $attachment_id,
				'url'          => (string) wp_get_attachment_url( (int) $attachment_id ),
				'fileName'     => $name,
				'fileSize'     => (int) $file['size'],
			],
		];
	}

	private function detect_mime( string $path ): ?string {
		if ( ! function_exists( 'finfo_open' ) ) {
			return null;
		}
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		if ( false === $finfo ) {
			return null;
		}
		$mime = finfo_file( $finfo, $path );
		// Drop the handle via unset() rather than finfo_close(): the latter
		// is deprecated as of PHP 8.5, while unset() frees the resource on
		// every supported version (7.4–8.x).
		unset( $finfo );
		return is_string( $mime ) && '' !== $mime ? $mime : null;
	}

	/**
	 * Require 2x the declared size free before assembling. Worst-case peak is
	 * 2x (all parts still on disk at the moment assembly starts), even though
	 * steady-state is ~1x because parts are unlinked as they are consumed.
	 * Unknown free space (disk_free_space false) does not block.
	 *
	 * @param string $probe_dir     Directory to measure free space on.
	 * @param int    $declared_size Client-declared final file size.
	 * @return string|null Error message, or null when there is room.
	 */
	private function check_disk( string $probe_dir, int $declared_size ): ?string {
		if ( $declared_size <= 0 ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$free = @disk_free_space( $probe_dir );
		if ( false === $free ) {
			return null;
		}
		if ( $free < ( $declared_size * 2 ) ) {
			return sprintf(
				/* translators: 1: required bytes 2: free bytes (human readable) */
				__( 'Not enough disk space to assemble this file. Need ~%1$s free, only %2$s available.', 'cf-chunked-upload' ),
				size_format( $declared_size * 2 ),
				size_format( (int) $free )
			);
		}
		return null;
	}

	// --- Lock (atomic add_option, mirrors the WebP queue pattern) ---------

	/**
	 * Atomic try-acquire. add_option() fails if the row exists (peer holds
	 * the lock); an expired lock is stolen. Mirrors the WebP queue pattern.
	 *
	 * @param string $upload_id Upload session id.
	 * @return bool True when this process now owns the lock.
	 */
	private function acquire_lock( string $upload_id ): bool {
		$key     = self::LOCK_PREFIX . $upload_id;
		$token   = uniqid( '', true );
		$created = add_option(
			$key,
			[
				'token'   => $token,
				'expires' => time() + self::LOCK_TTL,
			],
			'',
			false
		);
		if ( $created ) {
			return true;
		}
		$current = get_option( $key );
		if ( is_array( $current ) && isset( $current['expires'] ) && (int) $current['expires'] < time() ) {
			// Stealing an expired lock is the one place add_option's
			// atomicity doesn't help. update_option is not a compare-and-swap,
			// so two workers can both see "expired" and both write. Resolve
			// it with a write-then-read-back: both write, last writer wins
			// the row, and only the worker whose token actually survived
			// proceeds. The loser bails and lets the winner publish.
			update_option(
				$key,
				[
					'token'   => $token,
					'expires' => time() + self::LOCK_TTL,
				],
				false
			);
			$after = get_option( $key );
			return is_array( $after ) && ( $after['token'] ?? '' ) === $token;
		}
		return false;
	}

	private function release_lock( string $upload_id ): void {
		delete_option( self::LOCK_PREFIX . $upload_id );
	}

	private function safe_filesize( string $path ): int {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$size = @filesize( $path );
		return false === $size ? 0 : (int) $size;
	}

	/**
	 * SEC-2: Release the user's quota reservation and delete the session
	 * directory. Called at every normal deletion site in process/place_*.
	 *
	 * If the session no longer exists (cancelled by the user while assembly
	 * ran), handle_cancel already released the quota; skip to avoid a
	 * double-decrement. Note: concurrent cancel + finish still races — the
	 * counter is best-effort by design (see UserQuota).
	 *
	 * @param UploadSession $session Session to delete.
	 * @param array         $meta    Session metadata (owner_id, file_size).
	 * @return void
	 */
	private function delete_session_with_quota_release( UploadSession $session, array $meta ): void {
		if ( ! $session->exists() ) {
			return; // already gone (cancel won the race); handle_cancel released quota
		}
		UserQuota::release(
			(int) ( $meta['owner_id'] ?? 0 ),
			(int) ( $meta['file_size'] ?? 0 )
		);
		$session->delete();
	}

	private function fail( string $code, string $message ): array {
		return [
			'ok'      => false,
			'error'   => $code,
			'message' => $message,
		];
	}
}
