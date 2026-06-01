<?php

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

/**
 * Disk-space estimation and sufficient-space check used by bulk conversion
 * paths (foreground AJAX, queue start, WP-CLI).
 *
 * The output size of a WebP/AVIF encode varies enormously by content —
 * a photographic JPEG might shrink to 70% of source, a PNG with alpha
 * might balloon to 110% — so this is a heuristic, not a guarantee.
 * Tuned to overestimate slightly so the pre-check is conservative.
 *
 * When disk_free_space() can't answer (sandboxed hosts, open_basedir
 * restrictions, network filesystems), the check returns null and the
 * caller proceeds. Better to attempt the conversion than to refuse on
 * a host where we can't tell — the worst case is the OS ENOSPC error,
 * which is recoverable.
 */
final class Disk {

	/** Headroom kept free after conversion (bytes). 100 MB by default. */
	const DEFAULT_RESERVE = 100_000_000;

	/** Rough output-size ratios vs source. Tuned to over-estimate. */
	private const WEBP_RATIO = 0.6;
	private const AVIF_RATIO = 0.4;

	/**
	 * Estimate the total bytes a conversion of the given attachments will
	 * write, including all size variants and (optionally) AVIF alongside
	 * WebP. Missing source files contribute 0.
	 *
	 * Containment-checked: when $paths is provided we refuse to stat any
	 * file that doesn't resolve inside the uploads tree, even though the
	 * subsequent convert() call would reject it too. Poisoned attachment
	 * metadata pointing at `/etc/passwd` should not even get a filesize().
	 */
	public static function estimate_required_space( array $attachment_ids, bool $include_avif, ?Paths $paths = null ): int {
		$total = 0;

		// Pre-warm the postmeta cache in chunks. `get_attached_file()` and
		// `wp_get_attachment_metadata()` are both `get_post_meta`-backed
		// and trigger a fresh SELECT per attachment on a cold cache. For
		// 50K-id batches that's 100K serialized cache misses. One
		// update_meta_cache per chunk turns each chunk into a single
		// indexed `WHERE post_id IN (...)` SELECT.
		$int_ids = array_values( array_filter( array_map( 'intval', $attachment_ids ) ) );
		if ( function_exists( 'update_meta_cache' ) ) {
			foreach ( array_chunk( $int_ids, 500 ) as $batch_ids ) {
				update_meta_cache( 'post', $batch_ids );
			}
		}

		foreach ( $int_ids as $id ) {
			$file = get_attached_file( $id );
			if ( ! $file ) {
				continue;
			}
			// Containment check BEFORE any filesystem stat. Poisoned attachment
			// metadata pointing at `/etc/passwd` should never see file_exists()
			// or filesize().
			if ( null !== $paths && ! $paths->within_upload_dir( $file ) ) {
				continue;
			}
			if ( ! file_exists( $file ) ) {
				continue;
			}

			$total += self::estimate_one( (int) @filesize( $file ), $include_avif );

			$meta = wp_get_attachment_metadata( $id );
			if ( ! is_array( $meta ) || empty( $meta['file'] ) || empty( $meta['sizes'] ) ) {
				continue;
			}
			$year_mon = dirname( $file );
			foreach ( $meta['sizes'] as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$size_path = $year_mon . '/' . $size['file'];
				// Containment check first — see the original-file path note above.
				if ( null !== $paths && ! $paths->within_upload_dir( $size_path ) ) {
					continue;
				}
				if ( ! file_exists( $size_path ) ) {
					continue;
				}
				$total += self::estimate_one( (int) @filesize( $size_path ), $include_avif );
			}
		}

		return $total;
	}

	private static function estimate_one( int $source_bytes, bool $include_avif ): int {
		if ( $source_bytes <= 0 ) {
			return 0;
		}
		$out = (int) ceil( $source_bytes * self::WEBP_RATIO );
		if ( $include_avif ) {
			$out += (int) ceil( $source_bytes * self::AVIF_RATIO );
		}
		return $out;
	}

	/**
	 * Verify the uploads filesystem has enough free space for the conversion.
	 *
	 * Returns null on success or "can't tell" (which is also a success — we
	 * don't block on hosts that hide disk stats). Returns a human-readable
	 * error message string on insufficient space.
	 */
	public static function check_sufficient_space(
		string $upload_dir,
		int $required_bytes,
		int $reserve_bytes = self::DEFAULT_RESERVE
	): ?string {
		$free = @disk_free_space( $upload_dir );
		if ( false === $free ) {
			// Sandboxed host or open_basedir restriction. Skip the check.
			return null;
		}

		$needed = $required_bytes + $reserve_bytes;
		if ( (float) $free >= (float) $needed ) {
			return null;
		}

		return sprintf(
			/* translators: 1: estimated conversion size, 2: reserve headroom, 3: current free space */
			__( 'Not enough disk space for this conversion. Estimated need: %1$s (plus %2$s reserve). Free in uploads directory: %3$s.', 'cf-media-manager' ),
			self::format_bytes( $required_bytes ),
			self::format_bytes( $reserve_bytes ),
			self::format_bytes( (int) $free )
		);
	}

	/**
	 * Bytes formatter — uses size_format() when WP is available, else a
	 * vendored equivalent so the helper is testable without a WP install.
	 */
	private static function format_bytes( int $bytes ): string {
		if ( function_exists( 'size_format' ) ) {
			$out = size_format( $bytes, 1 );
			if ( $out ) {
				return $out;
			}
		}
		$units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		$i     = 0;
		$v     = (float) max( 0, $bytes );
		while ( $v >= 1024 && $i < count( $units ) - 1 ) {
			$v /= 1024;
			++$i;
		}
		return number_format( $v, 1 ) . ' ' . $units[ $i ];
	}
}
