<?php

namespace CFChunkedUpload;

defined( 'ABSPATH' ) || exit;

/**
 * Hourly sweep of abandoned/failed upload sessions.
 *
 * A session is aged by the NEWEST file in its directory (parts, .meta, and
 * the per-chunk-touched .heartbeat). Using the newest file is load-bearing:
 * with oldest-file logic a slow multi-hour upload sitting at the retention
 * boundary would be wiped mid-transfer. A session whose newest file is older
 * than the retention window is genuinely abandoned and safe to delete.
 *
 * A directory missing BOTH .meta and .heartbeat was not created by the chunk
 * path; it is removed regardless of age as a safety net so stray dirs under
 * the chunks root never accumulate.
 */
final class Cleanup {

	const HOOK     = 'cf_chunked_upload_cleanup';
	const SCHEDULE = 'hourly';

	private Paths $paths;

	/**
	 * Retention window resolver.
	 *
	 * @var callable fn(): int — retention window in seconds.
	 */
	private $retention_resolver;

	/** @var callable|null fn(): string — absolute path of the imports dir, or null to skip. */
	private $imports_dir_resolver;

	/** @var callable|null fn(): int — retention in days (0 = never delete), or null to skip. */
	private $imports_retention_days_resolver;

	public function __construct(
		Paths $paths,
		callable $retention_resolver,
		?callable $imports_dir_resolver = null,
		?callable $imports_retention_days_resolver = null
	) {
		$this->paths                           = $paths;
		$this->retention_resolver              = $retention_resolver;
		$this->imports_dir_resolver            = $imports_dir_resolver;
		$this->imports_retention_days_resolver = $imports_retention_days_resolver;
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::SCHEDULE, self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public function register_hooks(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	/**
	 * Sweep now. Returns the number of session directories deleted (so the
	 * suite can assert behavior without inspecting the filesystem).
	 */
	public function run(): int {
		$now     = time();
		$deleted = 0;

		$this->sweep_imports( $now );

		$root = $this->paths->chunks_root();
		if ( ! is_dir( $root ) ) {
			return 0;
		}

		$retention = max( 60, (int) call_user_func( $this->retention_resolver ) );

		foreach ( (array) scandir( $root ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$dir = $root . '/' . $entry;
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			if ( ! UploadSession::is_valid_id( (string) $entry ) ) {
				continue;
			}

			$has_meta      = is_file( $dir . '/' . UploadSession::META_FILE );
			$has_heartbeat = is_file( $dir . '/' . UploadSession::HEARTBEAT_FILE );

			if ( ! $has_meta && ! $has_heartbeat ) {
				$this->delete_session_dir( $dir );
				++$deleted;
				continue;
			}

			// SEC-7: skip sessions with a recent .assembling marker — the
			// finalize job is queued or actively running. A stale marker (older
			// than the retention window) means the job never ran or its worker
			// died; fall through to the normal age-based check so it is
			// eventually reaped rather than accumulating indefinitely.
			$assembling_file = $dir . '/' . UploadSession::ASSEMBLING_FILE;
			if ( is_file( $assembling_file ) ) {
				$marker_age = $now - ( @filemtime( $assembling_file ) ?: 0 );
				if ( $marker_age < $retention ) {
					continue; // actively assembling or recently queued
				}
				// Fall through — stale marker; apply normal retention check.
			}

			if ( ( $now - $this->newest_mtime( $dir ) ) > $retention ) {
				$this->delete_session_dir( $dir );
				++$deleted;
			}
		}

		global $wpdb;

		$prefix = FinalizeJob::LOCK_PREFIX;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup sweep for expired finalize locks; caching a transient sweep result serves no purpose.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		foreach ( (array) $results as $row ) {
			$data = maybe_unserialize( $row->option_value );
			if ( is_array( $data ) && isset( $data['expires'] ) && (int) $data['expires'] < $now ) {
				delete_option( $row->option_name );
			}
		}

		return $deleted;
	}

	/**
	 * FEAT-5: sweep the imports directory and delete files older than the
	 * configured retention window. No-op when resolvers are absent or retention
	 * is 0. Called unconditionally at the top of run() so it fires even when
	 * the chunks root is absent.
	 *
	 * @param int $now Unix timestamp to measure file ages against.
	 * @return void
	 */
	private function sweep_imports( int $now ): void {
		if ( null === $this->imports_dir_resolver || null === $this->imports_retention_days_resolver ) {
			return;
		}
		$imports_dir    = (string) call_user_func( $this->imports_dir_resolver );
		$retention_days = (int) call_user_func( $this->imports_retention_days_resolver );
		if ( $retention_days <= 0 || ! is_dir( $imports_dir ) ) {
			return;
		}
		$cutoff = $now - ( $retention_days * 86400 );
		foreach ( (array) scandir( $imports_dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$file = $imports_dir . '/' . $entry;
			if ( is_file( $file ) ) {
				$mt = @filemtime( $file );
				if ( false !== $mt && $mt < $cutoff ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					@unlink( $file );
				}
			}
		}
	}

	/**
	 * SEC-2: Release the user's quota reservation and remove the session
	 * directory. Reads the meta before deleting since it's needed for release.
	 *
	 * @param string $dir Absolute path to the session directory.
	 * @return void
	 */
	private function delete_session_dir( string $dir ): void {
		$meta_path = $dir . '/' . UploadSession::META_FILE;
		if ( is_file( $meta_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
			$raw  = @file_get_contents( $meta_path );
			$meta = $raw ? json_decode( $raw, true ) : null;
			if ( is_array( $meta ) ) {
				UserQuota::release(
					(int) ( $meta['owner_id'] ?? 0 ),
					(int) ( $meta['file_size'] ?? 0 )
				);
			}
		}
		UploadSession::rrmdir( $dir );
	}

	private function newest_mtime( string $dir ): int {
		$newest = 0;
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$mt = @filemtime( $dir . '/' . $entry );
			if ( false !== $mt && $mt > $newest ) {
				$newest = $mt;
			}
		}
		return $newest;
	}
}
