<?php

namespace CFChunkedUpload;

defined( 'ABSPATH' ) || exit;

/**
 * FEAT-2: Pre-flight check for a prospective upload.
 *
 * Runs all server-side prerequisites before chunk 0 is sent. Surfaces
 * actionable errors in <1 s instead of letting the user discover them
 * 25 minutes into a large upload. The response shape is:
 *
 *   diskOk              bool  — 2× file_size free on the destination FS
 *   freeBytes           int|null — bytes free on the destination FS
 *   destinationOk       bool  — destination dir exists / is writable
 *   quotaRemainingBytes int|null — null when quota is disabled
 *   concurrentSlotsLeft int|null — null when cap is disabled
 *   wpCronHealthy       bool  — DISABLE_WP_CRON is not set
 *   finfoAvailable      bool  — finfo_open() exists
 *   extensionAllowed    bool  — file ext is in the importer allowlist
 *   estimatedChunks     int   — ceil(file_size / chunk_size_bytes)
 *   warnings            string[]
 */
final class Preflight {

	private Paths $paths;
	private Settings $settings;

	public function __construct( Paths $paths, Settings $settings ) {
		$this->paths    = $paths;
		$this->settings = $settings;
	}

	/**
	 * @param string $destination 'media' | 'import'
	 * @param string $file_name   Sanitized filename.
	 * @param int    $file_size   Declared file size in bytes.
	 * @return array
	 */
	public function check( string $destination, string $file_name, int $file_size ): array {
		$result = [
			'diskOk'               => true,
			'freeBytes'            => null,
			'destinationOk'        => true,
			'quotaRemainingBytes'  => null,
			'concurrentSlotsLeft'  => null,
			'wpCronHealthy'        => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
			'finfoAvailable'       => function_exists( 'finfo_open' ),
			'extensionAllowed'     => true,
			'estimatedChunks'      => max( 1, $file_size > 0
				? (int) ceil( $file_size / max( 1, $this->settings->chunk_size_bytes() ) )
				: 1
			),
			'warnings'             => [],
		];

		// --- Disk space -------------------------------------------------------
		$probe_dir = $this->resolve_probe_dir( $destination );
		if ( '' !== $probe_dir ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$free = @disk_free_space( $probe_dir );
			if ( false !== $free ) {
				$result['freeBytes'] = (int) $free;
				if ( $file_size > 0 && (int) $free < $file_size * 2 ) {
					$result['diskOk']   = false;
					$result['warnings'][] = __( 'Disk space may be insufficient to assemble this file.', 'cf-chunked-upload' );
				}
			}
		}

		// --- Destination writable ---------------------------------------------
		if ( 'import' === $destination ) {
			$dir = $this->settings->imports_dir();
			if ( is_dir( $dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only writability probe for a preflight diagnostic; WP_Filesystem is not initialized on this REST path.
				$result['destinationOk'] = is_writable( $dir );
			} else {
				$result['destinationOk'] = wp_mkdir_p( $dir );
			}
		} elseif ( 'media' === $destination && function_exists( 'wp_upload_dir' ) ) {
			$upload_dir              = wp_upload_dir();
			$result['destinationOk'] = ! $upload_dir['error'];
		}

		if ( ! $result['destinationOk'] ) {
			$result['warnings'][] = __( 'The upload destination is not writable.', 'cf-chunked-upload' );
		}

		// --- Quota ------------------------------------------------------------
		$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$limit   = $this->settings->per_user_quota_bytes();
		if ( $limit > 0 && $user_id > 0 ) {
			$used                        = (int) get_option( 'cf_cu_quota_' . $user_id, 0 );
			$remaining                   = max( 0, $limit - $used );
			$result['quotaRemainingBytes'] = $remaining;
			if ( $file_size > 0 && $remaining < $file_size ) {
				$result['warnings'][] = __( 'This file would exceed your storage quota.', 'cf-chunked-upload' );
			}
		}

		// --- Concurrent cap ---------------------------------------------------
		$cap = $this->settings->max_concurrent_uploads_per_user();
		if ( $cap > 0 && $user_id > 0 ) {
			$active                      = UploadSession::count_user_sessions( $this->paths, $user_id );
			$result['concurrentSlotsLeft'] = max( 0, $cap - $active );
			if ( $result['concurrentSlotsLeft'] === 0 ) {
				$result['warnings'][] = __( 'You have reached the maximum number of concurrent uploads.', 'cf-chunked-upload' );
			}
		}

		// --- WP-Cron ----------------------------------------------------------
		if ( ! $result['wpCronHealthy'] ) {
			$result['warnings'][] = __( 'WP-Cron is disabled. Ensure a system cron calls wp-cron.php or assembly jobs may not complete automatically.', 'cf-chunked-upload' );
		}

		// --- Extension allowlist (importer only) ------------------------------
		if ( 'import' === $destination && '' !== $file_name ) {
			$allowed = $this->settings->allowed_extensions();
			if ( [] !== $allowed ) {
				$ext                      = strtolower( (string) pathinfo( $file_name, PATHINFO_EXTENSION ) );
				$result['extensionAllowed'] = '' !== $ext && in_array( $ext, $allowed, true );
				if ( ! $result['extensionAllowed'] ) {
					$result['warnings'][] = __( 'This file type is not in the importer allowlist.', 'cf-chunked-upload' );
				}
			}
		}

		return $result;
	}

	private function resolve_probe_dir( string $destination ): string {
		if ( 'import' === $destination ) {
			$dir = $this->settings->imports_dir();
			// Walk up to an existing ancestor so disk_free_space has a real path.
			while ( '' !== $dir && ! is_dir( $dir ) ) {
				$parent = dirname( $dir );
				if ( $parent === $dir ) {
					break;
				}
				$dir = $parent;
			}
			return is_dir( $dir ) ? $dir : '';
		}
		if ( 'media' === $destination && function_exists( 'wp_upload_dir' ) ) {
			$upload_dir = wp_upload_dir();
			return is_dir( (string) ( $upload_dir['basedir'] ?? '' ) ) ? (string) $upload_dir['basedir'] : '';
		}
		return '';
	}
}
