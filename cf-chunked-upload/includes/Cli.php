<?php

namespace CFChunkedUpload;

defined( 'ABSPATH' ) || exit;

/**
 * FEAT-3: WP-CLI command suite for CF Chunked Upload.
 *
 * Registered as `wp cf-cu` in Plugin::boot() when WP_CLI is defined.
 *
 * ## EXAMPLES
 *
 *   wp cf-cu sessions
 *   wp cf-cu sessions --user=42
 *   wp cf-cu status 6ba7b810-9dad-41d1-80b4-00c04fd430c8
 *   wp cf-cu cancel 6ba7b810-9dad-41d1-80b4-00c04fd430c8
 *   wp cf-cu cleanup --dry-run
 *   wp cf-cu locks
 *   wp cf-cu locks --clear
 *   wp cf-cu host-info
 */
final class Cli extends \WP_CLI_Command {

	private Paths $paths;
	private Settings $settings;
	private FinalizeJob $finalize;
	private Cleanup $cleanup;

	public function __construct( Paths $paths, Settings $settings, FinalizeJob $finalize, Cleanup $cleanup ) {
		$this->paths    = $paths;
		$this->settings = $settings;
		$this->finalize = $finalize;
		$this->cleanup  = $cleanup;
	}

	/**
	 * List active upload sessions.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<id>]
	 * : Filter to sessions owned by this WordPress user ID.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format. Options: table, csv, json, yaml, ids, count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *   wp cf-cu sessions
	 *   wp cf-cu sessions --user=7
	 *
	 * @subcommand sessions
	 */
	public function sessions( array $args, array $assoc ): void {
		$filter_user = isset( $assoc['user'] ) ? (int) $assoc['user'] : null;
		$root        = $this->paths->chunks_root();
		$rows        = [];

		if ( is_dir( $root ) ) {
			foreach ( (array) scandir( $root ) as $entry ) {
				if ( ! UploadSession::is_valid_id( (string) $entry ) ) {
					continue;
				}
				try {
					$session = new UploadSession( $this->paths, $entry );
				} catch ( \InvalidArgumentException $e ) {
					continue;
				}
				$meta = $session->meta();
				if ( null === $meta ) {
					continue;
				}
				$owner = (int) ( $meta['owner_id'] ?? 0 );
				if ( null !== $filter_user && $owner !== $filter_user ) {
					continue;
				}
				$age    = time() - (int) ( $meta['created_at'] ?? 0 );
				$rows[] = [
					'upload_id'   => $entry,
					'owner_id'    => $owner,
					'file_name'   => $meta['file_name'] ?? '—',
					'destination' => $meta['destination'] ?? '—',
					'chunks'      => $session->received_count() . ' / ' . $session->total_chunks(),
					'complete'    => $session->has_all_chunks() ? 'yes' : 'no',
					'assembling'  => $session->is_assembling() ? 'yes' : 'no',
					'age'         => gmdate( 'H:i:s', $age ),
				];
			}
		}

		\WP_CLI\Utils\format_items(
			$assoc['format'] ?? 'table',
			$rows,
			[
				'upload_id',
				'owner_id',
				'file_name',
				'destination',
				'chunks',
				'complete',
				'assembling',
				'age',
			]
		);
	}

	/**
	 * Show details for one upload session.
	 *
	 * ## OPTIONS
	 *
	 * <uploadId>
	 * : The session UUID.
	 *
	 * ## EXAMPLES
	 *
	 *   wp cf-cu status 6ba7b810-9dad-41d1-80b4-00c04fd430c8
	 *
	 * @subcommand status
	 */
	public function status( array $args ): void {
		$upload_id = (string) ( $args[0] ?? '' );
		if ( ! UploadSession::is_valid_id( $upload_id ) ) {
			\WP_CLI::error( 'Invalid upload ID.' );
		}
		try {
			$session = new UploadSession( $this->paths, $upload_id );
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( 'Invalid upload ID.' );
		}
		if ( ! $session->exists() ) {
			\WP_CLI::error( "Session $upload_id not found." );
		}
		$meta = $session->meta();
		\WP_CLI\Utils\format_items(
			'table',
			[
				[
					'key'   => 'upload_id',
					'value' => $upload_id,
				],
				[
					'key'   => 'owner_id',
					'value' => $meta['owner_id'] ?? '0',
				],
				[
					'key'   => 'file_name',
					'value' => $meta['file_name'] ?? '—',
				],
				[
					'key'   => 'mime_type',
					'value' => $meta['mime_type'] ?? '—',
				],
				[
					'key'   => 'destination',
					'value' => $meta['destination'] ?? '—',
				],
				[
					'key'   => 'total_chunks',
					'value' => $session->total_chunks(),
				],
				[
					'key'   => 'received',
					'value' => $session->received_count(),
				],
				[
					'key'   => 'complete',
					'value' => $session->has_all_chunks() ? 'yes' : 'no',
				],
				[
					'key'   => 'assembling',
					'value' => $session->is_assembling() ? 'yes' : 'no',
				],
				[
					'key'   => 'finalize_job',
					'value' => $session->finalize_job_id() ?? '—',
				],
				[
					'key'   => 'file_size',
					'value' => $meta['file_size'] ?? '0',
				],
				[
					'key'   => 'created_at',
					'value' => isset( $meta['created_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $meta['created_at'] ) : '—',
				],
			],
			[ 'key', 'value' ]
		);
	}

	/**
	 * Cancel and delete an upload session.
	 *
	 * ## OPTIONS
	 *
	 * <uploadId>
	 * : The session UUID.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *   wp cf-cu cancel 6ba7b810-9dad-41d1-80b4-00c04fd430c8
	 *
	 * @subcommand cancel
	 */
	public function cancel( array $args, array $assoc ): void {
		$upload_id = (string) ( $args[0] ?? '' );
		if ( ! UploadSession::is_valid_id( $upload_id ) ) {
			\WP_CLI::error( 'Invalid upload ID.' );
		}
		try {
			$session = new UploadSession( $this->paths, $upload_id );
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( 'Invalid upload ID.' );
		}
		if ( ! $session->exists() ) {
			\WP_CLI::error( "Session $upload_id not found." );
		}

		\WP_CLI::confirm( "Delete session $upload_id?", $assoc );

		$meta = $session->meta();
		if ( is_array( $meta ) ) {
			UserQuota::release( (int) ( $meta['owner_id'] ?? 0 ), (int) ( $meta['file_size'] ?? 0 ) );
		}
		$session->delete();
		delete_option( FinalizeJob::LOCK_PREFIX . $upload_id );
		\WP_CLI::success( "Session $upload_id deleted." );
	}

	/**
	 * Run the stale-session cleanup sweep.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would be deleted without actually deleting.
	 *
	 * ## EXAMPLES
	 *
	 *   wp cf-cu cleanup
	 *   wp cf-cu cleanup --dry-run
	 *
	 * @subcommand cleanup
	 */
	public function cleanup( array $args, array $assoc ): void {
		if ( ! empty( $assoc['dry-run'] ) ) {
			// Dry run: just scan and report without deleting.
			$root         = $this->paths->chunks_root();
			$retention    = max( 60, $this->settings->retention_seconds() );
			$now          = time();
			$would_delete = 0;

			if ( is_dir( $root ) ) {
				foreach ( (array) scandir( $root ) as $entry ) {
					if ( ! UploadSession::is_valid_id( (string) $entry ) ) {
						continue;
					}
					$dir      = $root . '/' . $entry;
					$has_meta = is_file( $dir . '/' . UploadSession::META_FILE );
					$has_hb   = is_file( $dir . '/' . UploadSession::HEARTBEAT_FILE );
					if ( ! $has_meta && ! $has_hb ) {
						\WP_CLI::log( "[dry-run] Would delete (no meta/heartbeat): $entry" );
						++$would_delete;
						continue;
					}
					$assembling_file = $dir . '/' . UploadSession::ASSEMBLING_FILE;
					if ( is_file( $assembling_file ) ) {
						$marker_age = $now - ( @filemtime( $assembling_file ) ?: 0 );
						if ( $marker_age < $retention ) {
							\WP_CLI::log( "[dry-run] Skipping (assembling): $entry" );
							continue;
						}
					}
					$newest = 0;
					foreach ( (array) scandir( $dir ) as $f ) {
						if ( '.' === $f || '..' === $f ) {
							continue;
						}
						$mt = @filemtime( $dir . '/' . $f );
						if ( false !== $mt && $mt > $newest ) {
							$newest = $mt;
						}
					}
					if ( ( $now - $newest ) > $retention ) {
						\WP_CLI::log( "[dry-run] Would delete (stale): $entry" );
						++$would_delete;
					}
				}
			}
			\WP_CLI::success( "Dry run complete. Would delete $would_delete session(s)." );
			return;
		}

		$deleted = $this->cleanup->run();
		\WP_CLI::success( "Cleanup complete. Deleted $deleted session(s)." );
	}

	/**
	 * List or clear active finalize locks.
	 *
	 * ## OPTIONS
	 *
	 * [--clear]
	 * : Delete expired locks.
	 *
	 * [--clear-all]
	 * : Delete all finalize locks regardless of expiry.
	 *
	 * ## EXAMPLES
	 *
	 *   wp cf-cu locks
	 *   wp cf-cu locks --clear
	 *
	 * @subcommand locks
	 */
	public function locks( array $args, array $assoc ): void {
		global $wpdb;
		$prefix = FinalizeJob::LOCK_PREFIX;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WP-CLI diagnostic listing live finalize locks; must read current DB state, not a cache.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		$now  = time();
		$rows = [];
		foreach ( (array) $results as $row ) {
			$data      = maybe_unserialize( $row->option_value );
			$expires   = isset( $data['expires'] ) ? (int) $data['expires'] : 0;
			$expired   = $expires > 0 && $expires < $now;
			$upload_id = str_replace( $prefix, '', $row->option_name );
			$rows[]    = [
				'upload_id' => $upload_id,
				'expires'   => $expires > 0 ? gmdate( 'Y-m-d H:i:s', $expires ) : '—',
				'expired'   => $expired ? 'yes' : 'no',
			];

			if ( ( ! empty( $assoc['clear'] ) && $expired ) || ! empty( $assoc['clear-all'] ) ) {
				delete_option( $row->option_name );
				\WP_CLI::log( "Deleted lock for $upload_id." );
			}
		}

		if ( empty( $rows ) ) {
			\WP_CLI::log( 'No finalize locks found.' );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'upload_id', 'expires', 'expired' ] );
	}

	/**
	 * Show host info (PHP limits, disk space, WP-Cron status).
	 *
	 * ## EXAMPLES
	 *
	 *   wp cf-cu host-info
	 *
	 * @subcommand host-info
	 */
	public function host_info(): void {
		$info = HostInfo::snapshot();
		$fmt  = static function ( $v, $kind ) {
			if ( null === $v ) {
				return '—';
			}
			if ( 'seconds' === $kind ) {
				return 0 === $v ? 'unlimited' : "{$v}s";
			}
			if ( 'bool' === $kind ) {
				return $v ? 'yes' : 'no';
			}
			// bytes
			$units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
			$i     = 0;
			$n     = (float) $v;
			while ( $n >= 1024 && $i < 4 ) {
				$n /= 1024;
				++$i;
			}
			return round( $n, 1 ) . ' ' . $units[ $i ];
		};
		$rows = [
			[
				'key'   => 'upload_max_filesize',
				'value' => $fmt( $info['upload_max_filesize'] ?? null, 'bytes' ),
			],
			[
				'key'   => 'post_max_size',
				'value' => $fmt( $info['post_max_size'] ?? null, 'bytes' ),
			],
			[
				'key'   => 'memory_limit',
				'value' => $fmt( $info['memory_limit'] ?? null, 'bytes' ),
			],
			[
				'key'   => 'max_execution_time',
				'value' => $fmt( $info['max_execution_time'] ?? null, 'seconds' ),
			],
			[
				'key'   => 'free_disk_space',
				'value' => $fmt( $info['free_disk_space'] ?? null, 'bytes' ),
			],
			[
				'key'   => 'chunk_ceiling_bytes',
				'value' => $fmt( $info['chunk_ceiling_bytes'] ?? null, 'bytes' ),
			],
			[
				'key'   => 'is_nginx',
				'value' => $fmt( $info['isNginx'] ?? false, 'bool' ),
			],
			[
				'key'   => 'wp_cron_disabled',
				'value' => $fmt( $info['wpCronDisabled'] ?? false, 'bool' ),
			],
		];
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'key', 'value' ] );
	}

	/**
	 * Show active finalize job statuses.
	 *
	 * ## EXAMPLES
	 *
	 *   wp cf-cu jobs
	 *
	 * @subcommand jobs
	 */
	public function jobs(): void {
		global $wpdb;
		$prefix = '_transient_cf_cu_job_';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WP-CLI diagnostic listing live finalize jobs; must read current DB state, not a cache.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		$rows = [];
		foreach ( (array) $results as $row ) {
			$data   = maybe_unserialize( $row->option_value );
			$job_id = str_replace( $prefix, '', $row->option_name );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$rows[] = [
				'job_id'   => $job_id,
				'status'   => $data['status'] ?? '—',
				'progress' => isset( $data['progress'] ) ? round( $data['progress'] * 100 ) . '%' : '—',
				'error'    => $data['error']['code'] ?? '',
			];
		}

		if ( empty( $rows ) ) {
			\WP_CLI::log( 'No active job records found.' );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'job_id', 'status', 'progress', 'error' ] );
	}
}
