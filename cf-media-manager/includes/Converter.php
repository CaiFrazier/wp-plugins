<?php

namespace CFMediaManager;

use Imagick;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Convert JPEG/PNG sources into WebP and (optionally) AVIF variants.
 *
 * Imagick is preferred — better quality, correct PNG-alpha handling, and the
 * only path that supports AVIF. GD is the fallback for WebP only.
 *
 * Every public conversion entry-point validates its inputs through Paths so
 * crafted attachment metadata cannot coerce reads or writes outside the
 * uploads tree, and through getimagesize() to reject decompression bombs
 * before handing bytes to a decoder.
 */
final class Converter {

	/** Engine used by the most recent successful WebP conversion. */
	private ?string $last_webp_engine = null;

	/** True when the most recent run also wrote an AVIF variant. */
	private bool $last_avif_written = false;

	/**
	 * Human-readable reason for the most recent `convert()` false return. Set
	 * on every failure path before returning so the batch UI can surface the
	 * actual cause (foreign variant blocked, source too large, decode failed,
	 * etc.) instead of an opaque "skipped" message. Reset at the top of every
	 * `convert()` call, just like `last_webp_engine`.
	 */
	private ?string $last_skip_reason = null;

	/** Per-attempt counter of GD fallbacks, exposed via convert_attachment(). */
	private int $gd_fallback_count = 0;

	private Paths $paths;

	private VariantManifest $manifest;

	public function __construct( Paths $paths, ?VariantManifest $manifest = null ) {
		$this->paths    = $paths;
		$this->manifest = $manifest ?? new VariantManifest( $paths );
	}

	public function manifest(): VariantManifest {
		return $this->manifest;
	}

	public function get_last_engine(): ?string {
		return $this->last_webp_engine;
	}

	public function did_last_write_avif(): bool {
		return $this->last_avif_written;
	}

	/**
	 * Translated, human-readable reason the most recent `convert()` call
	 * returned false. Null on success or when no reason was set (e.g. before
	 * any conversion has been attempted on this instance).
	 */
	public function last_skip_reason(): ?string {
		return $this->last_skip_reason;
	}

	/**
	 * True if at least one WebP-capable encoder is available — Imagick with the
	 * WEBP coder, or GD with imagewebp(). Activation refuses to install when
	 * this returns false; runtime falls back to graceful skip.
	 */
	public static function webp_supported(): bool {
		static $supported = null;
		if ( $supported !== null ) {
			return $supported;
		}
		if ( function_exists( 'imagewebp' ) ) {
			$supported = true;
			return true;
		}
		if ( extension_loaded( 'imagick' ) ) {
			try {
				$formats   = method_exists( 'Imagick', 'queryFormats' ) ? \Imagick::queryFormats( 'WEBP' ) : [];
				$supported = ! empty( $formats );
				return $supported;
			} catch ( \Throwable $e ) {
				$supported = false;
				return false;
			}
		}
		$supported = false;
		return false;
	}

	/**
	 * True if the Imagick build advertises a working AVIF coder.
	 *
	 * Memoized: a single static check is cheap and Imagick coders don't change
	 * within a request.
	 */
	public static function avif_supported(): bool {
		static $supported = null;
		if ( $supported !== null ) {
			return $supported;
		}
		if ( ! extension_loaded( 'imagick' ) ) {
			$supported = false;
			return false;
		}
		try {
			$formats   = method_exists( 'Imagick', 'queryFormats' ) ? Imagick::queryFormats( 'AVIF' ) : [];
			$supported = ! empty( $formats );
		} catch ( Throwable $e ) {
			$supported = false;
		}
		return $supported;
	}

	/**
	 * Convert a single source file. Returns true if at least the WebP variant
	 * exists on disk after the call (including when it was already up-to-date).
	 *
	 * Pass $force=true to reconvert even when the destination is newer than
	 * the source — used after a quality change.
	 *
	 * AVIF is written alongside WebP when:
	 *   - Imagick has the AVIF coder, AND
	 *   - the cf_media_manager_enable_avif option is true.
	 *
	 * AVIF failure does not fail the call: if AVIF write throws, we still
	 * report success as long as WebP was written.
	 */
	public function convert( string $src, ?int $quality = null, bool $force = false, ?int $attachment_id = null ): bool {
		$this->last_webp_engine  = null;
		$this->last_avif_written = false;
		$this->last_skip_reason  = null;

		$ext = strtolower( pathinfo( $src, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, [ 'jpg', 'jpeg', 'png' ], true ) ) {
			$this->last_skip_reason = __( 'Source is not a supported JPG or PNG.', 'cf-media-manager' );
			return false;
		}
		// Containment check first — never stat or open a path that resolves
		// outside the uploads tree, even on attacker-controlled metadata.
		if ( ! $this->paths->within_upload_dir( $src ) ) {
			$this->last_skip_reason = __( 'Source path is outside the uploads directory.', 'cf-media-manager' );
			return false;
		}
		if ( ! is_readable( $src ) ) {
			$this->last_skip_reason = __( 'Source file is missing or unreadable.', 'cf-media-manager' );
			return false;
		}

		// Filesize cap: refuse to decode pathologically large sources before
		// asking the decoder to allocate memory for them. The cap is admin-
		// configurable (Settings → Max source filesize); 50 MB is the default
		// and 200 MB is the hard ceiling regardless of what the option says.
		$max_bytes = Options::resolve_max_source_bytes();
		$src_size  = (int) @filesize( $src );
		if ( $src_size > $max_bytes ) {
			$this->last_skip_reason = sprintf(
				/* translators: 1: actual source size in MB, 2: configured max in MB. */
				__( 'Source is %1$.1f MB, larger than the %2$d MB cap (Settings → Max source filesize).', 'cf-media-manager' ),
				$src_size / 1024 / 1024,
				(int) ( $max_bytes / 1024 / 1024 )
			);
			return false;
		}

		$webp_dest = Paths::src_to_variant_path( $src, 'webp' );
		if ( ! $this->paths->within_upload_dir( $webp_dest ) ) {
			$this->last_skip_reason = __( 'Derived WebP path resolved outside the uploads directory.', 'cf-media-manager' );
			return false;
		}

		$want_avif = self::avif_supported() && (bool) get_option( Options::ENABLE_AVIF, true );
		$avif_dest = $want_avif ? Paths::src_to_variant_path( $src, 'avif' ) : null;
		if ( $avif_dest !== null && ! $this->paths->within_upload_dir( $avif_dest ) ) {
			$avif_dest = null;
		}

		// AVIF crash circuit-breaker. AVIF encoding runs through libheif/libaom,
		// which can segfault or be OOM-killed mid-encode — a native process death
		// no PHP try/catch can trap. When that happens the arm/disarm pair around
		// the encode below is left armed, so on the next run for this source we
		// skip AVIF and let WebP (which never crashes) finish. Without this a
		// poison-pill image crash-loops the worker and, with the per-attachment
		// lock, bricks the attachment.
		$avif_breaker_key = ( null !== $avif_dest ) ? 'cfmm_avif_pp_' . md5( $src ) : null;
		if ( null !== $avif_breaker_key && function_exists( 'get_transient' ) && get_transient( $avif_breaker_key ) ) {
			$avif_dest = null;
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated diagnostic.
				error_log( '[cf-media-manager] AVIF circuit-breaker armed for ' . $src . ' — skipping AVIF (a prior encode crashed the worker).' );
			}
		}

		$quality = $quality ?? (int) get_option( Options::QUALITY, Options::DEFAULT_QUALITY );

		// Ownership gate — refuse to touch an existing variant we did not write.
		// Protects user-uploaded `.webp`/`.avif` files that happen to share a
		// basename with one of our generated derivatives (e.g. `logo.jpg` +
		// hand-uploaded `logo.webp`). When the manifest says the file is ours
		// we may freely re-encode under force; otherwise we leave it alone.
		$webp_blocked = file_exists( $webp_dest ) && ! $this->variant_is_owned( $attachment_id, $webp_dest );
		$avif_blocked = ( null !== $avif_dest )
			&& file_exists( $avif_dest )
			&& ! $this->variant_is_owned( $attachment_id, $avif_dest );

		if ( $webp_blocked ) {
			// A foreign file occupies the WebP slot. We cannot generate without
			// risking a user-data overwrite. Treat this as a deliberate skip,
			// and surface the reason only when WP_DEBUG is on so production
			// error logs stay quiet.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated security event log.
				error_log( '[cf-media-manager] Refusing to overwrite unowned variant: ' . $webp_dest );
			}
			$this->last_skip_reason = __( 'An unowned WebP exists in the destination slot — run "Adopt legacy variants" to claim it, or delete the foreign file.', 'cf-media-manager' );
			return false;
		}
		if ( $avif_blocked ) {
			// AVIF is best-effort; suppress AVIF writes for this run but allow
			// WebP to proceed — the WebP target was not blocked.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated security event log.
				error_log( '[cf-media-manager] Refusing to overwrite unowned variant: ' . $avif_dest );
			}
			$avif_dest = null;
		}

		// Skip when both targets are already up-to-date (or AVIF disabled and WebP fresh).
		$webp_fresh = file_exists( $webp_dest ) && filemtime( $webp_dest ) >= filemtime( $src );
		$avif_fresh = $avif_dest === null
			|| ( file_exists( $avif_dest ) && filemtime( $avif_dest ) >= filemtime( $src ) );
		if ( ! $force && $webp_fresh && $avif_fresh ) {
			$this->last_avif_written = $avif_dest !== null && file_exists( $avif_dest );
			// Adopt the existing files into the manifest when we have an ID
			// (covers fresh-on-disk files written by an earlier plugin version).
			if ( null !== $attachment_id ) {
				$this->manifest->record( $attachment_id, $webp_dest );
				if ( $this->last_avif_written ) {
					$this->manifest->record( $attachment_id, $avif_dest );
				}
			}
			return true;
		}

		// Reject decompression bombs before handing bytes to a decoder.
		$info = @getimagesize( $src );
		if ( ! is_array( $info ) || ! isset( $info[2] ) ) {
			$this->last_skip_reason = __( 'getimagesize() failed — source file is corrupt or not a real image.', 'cf-media-manager' );
			return false;
		}
		if ( ! in_array( (int) $info[2], [ IMAGETYPE_JPEG, IMAGETYPE_PNG ], true ) ) {
			$this->last_skip_reason = __( 'Source is not a JPEG or PNG (extension matches but file header does not).', 'cf-media-manager' );
			return false;
		}
		if ( ( (int) $info[0] ) * ( (int) $info[1] ) > Options::MAX_PIXELS ) {
			$this->last_skip_reason = sprintf(
				/* translators: 1: width, 2: height, 3: pixel cap in megapixels (e.g., 25). */
				__( 'Source is %1$d×%2$d, exceeding the %3$d-megapixel cap (decompression-bomb defense).', 'cf-media-manager' ),
				(int) $info[0],
				(int) $info[1],
				(int) ( Options::MAX_PIXELS / 1_000_000 )
			);
			return false;
		}

		// Raise memory ceiling before Imagick/GD decode large images. Honors
		// host limits — wp_raise_memory_limit() is a no-op when the host max
		// is already exceeded.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		$webp_ok        = false;
		$imagick_failed = false;

		if ( extension_loaded( 'imagick' ) ) {
			// Finally block frees the Imagick handle on every path, including
			// exceptions. Without it, bad images leak memory across a cron run.
			$im = null;
			try {
				$im = new Imagick();
				self::apply_imagick_resource_limits( $im );
				$im->readImage( $src );
				$im->stripImage();

				if ( ! $force && $webp_fresh ) {
					$webp_ok = true;
				} else {
					$webp_ok = $this->write_imagick_variant( $im, $webp_dest, 'webp', $quality, $ext );
				}

				if ( $avif_dest !== null && ( $force || ! $avif_fresh ) ) {
					// Arm the circuit-breaker BEFORE the encode. If writeImage()
					// crashes the process at the C level (libheif/libaom fault or
					// OOM-kill), this marker survives and trips the breaker on the
					// next run. The disarm in finally runs only when the encode
					// returned control to PHP — i.e. did NOT hard-crash.
					if ( null !== $avif_breaker_key && function_exists( 'set_transient' ) ) {
						set_transient( $avif_breaker_key, 1, self::AVIF_BREAKER_TTL );
					}
					try {
						if ( $this->write_imagick_variant( $im, $avif_dest, 'avif', $quality, $ext ) ) {
							$this->last_avif_written = true;
						}
					} catch ( Throwable $e ) {
						// AVIF writes are best-effort; never fail the whole call.
					} finally {
						if ( null !== $avif_breaker_key && function_exists( 'delete_transient' ) ) {
							delete_transient( $avif_breaker_key );
						}
					}
				} elseif ( $avif_dest !== null && file_exists( $avif_dest ) ) {
					$this->last_avif_written = true;
				}

				if ( $webp_ok ) {
					$this->last_webp_engine = 'imagick';
					$this->record_ownership( $attachment_id, $webp_dest, $this->last_avif_written ? $avif_dest : null );
					return true;
				}
				$imagick_failed = true;
				$this->last_skip_reason = __( 'Imagick writeImage returned false for the WebP target — encoder rejected the output.', 'cf-media-manager' );
			} catch ( Throwable $e ) {
				$imagick_failed = true;
				$this->last_skip_reason = sprintf(
					/* translators: %s: PHP exception message from Imagick. */
					__( 'Imagick threw: %s', 'cf-media-manager' ),
					$e->getMessage()
				);
			} finally {
				if ( $im instanceof Imagick ) {
					try {
						$im->clear();
						$im->destroy();
					} catch ( Throwable $e ) {
						// Cleanup is best-effort.
					}
				}
			}
		}

		// GD fallback — WebP only. AVIF in GD requires PHP 8.1+ AND a libavif-
		// linked build; coverage is too thin to rely on, so we stick to WebP.
		if ( ! function_exists( 'imagewebp' ) ) {
			$this->last_skip_reason = $imagick_failed
				? __( 'Imagick failed and GD has no imagewebp() — no WebP encoder available on this host.', 'cf-media-manager' )
				: __( 'No WebP encoder available (Imagick missing the WEBP coder and GD has no imagewebp()).', 'cf-media-manager' );
			return false;
		}

		try {
			if ( $ext === 'png' ) {
				$img = @imagecreatefrompng( $src );
				if ( ! $img ) {
					$this->last_skip_reason = __( 'GD imagecreatefrompng() failed — source PNG is corrupt or unsupported.', 'cf-media-manager' );
					return false;
				}
				imagepalettetotruecolor( $img );
				imagealphablending( $img, true );
				imagesavealpha( $img, true );
				$ok = imagewebp( $img, $webp_dest, $quality );
			} else {
				$img = @imagecreatefromjpeg( $src );
				if ( ! $img ) {
					$this->last_skip_reason = __( 'GD imagecreatefromjpeg() failed — source JPEG is corrupt or unsupported.', 'cf-media-manager' );
					return false;
				}
				$ok = imagewebp( $img, $webp_dest, $quality );
			}
			// GD image resources became GdImage objects in PHP 8.0; the
			// engine garbage-collects them when the variable goes out of
			// scope. `imagedestroy()` is a no-op since 8.0 and emits a
			// deprecation notice on 8.5+, so we let $img fall out of
			// scope on function return instead.
			if ( $ok ) {
				$this->last_webp_engine = $imagick_failed ? 'gd-fallback' : 'gd';
				$this->record_ownership( $attachment_id, $webp_dest, null );
				return true;
			}
			$this->last_skip_reason = __( 'GD imagewebp() returned false — encoder rejected the output (check disk space and write permissions).', 'cf-media-manager' );
			return false;
		} catch ( Throwable $e ) {
			$this->last_skip_reason = sprintf(
				/* translators: %s: PHP exception message from the GD encoder. */
				__( 'GD encoder threw: %s', 'cf-media-manager' ),
				$e->getMessage()
			);
			return false;
		}
	}

	/**
	 * Ownership-check helper. Two evaluation modes:
	 *
	 *   - **Known attachment** ($attachment_id > 0): the manifest entry for
	 *     that attachment is authoritative. If the attachment doesn't claim
	 *     this variant, return false — we refuse to overwrite. Backfill is
	 *     the explicit path to adopt legacy variants into the manifest.
	 *   - **Unknown attachment** ($attachment_id null/0): fall back to the
	 *     slow LIKE query across all attachments. Returns null when the
	 *     environment can't answer (no $wpdb — CLI bootstrap, test harness);
	 *     null is interpreted permissively so non-WP environments stay
	 *     functional.
	 *
	 * Production paths (on_upload, convert_attachment, queue worker) always
	 * pass an attachment ID, so the strict semantic is what runs in real use.
	 */
	private function variant_is_owned( ?int $attachment_id, string $variant_path ): bool {
		if ( null !== $attachment_id && $attachment_id > 0 ) {
			return $this->manifest->is_owned_by( $attachment_id, $variant_path );
		}
		$slow = $this->manifest->is_owned( $variant_path );
		if ( null === $slow ) {
			return true;
		}
		return (bool) $slow;
	}

	/**
	 * Record successful writes into the manifest. No-op when the source
	 * attachment isn't known — we'd rather miss a record than associate a
	 * variant with the wrong attachment.
	 */
	private function record_ownership( ?int $attachment_id, ?string $webp_path, ?string $avif_path ): void {
		if ( null === $attachment_id || $attachment_id <= 0 ) {
			return;
		}
		if ( null !== $webp_path ) {
			$this->manifest->record( $attachment_id, $webp_path );
		}
		if ( null !== $avif_path ) {
			$this->manifest->record( $attachment_id, $avif_path );
		}
	}

	/**
	 * Apply per-handle Imagick resource limits so a malicious image can't
	 * blow through the PHP memory limit or stall a worker indefinitely. Each
	 * setResourceLimit() call is wrapped because not every build exposes
	 * every constant.
	 */
	private static function apply_imagick_resource_limits( Imagick $im ): void {
		$limits = array(
			'RESOURCETYPE_MEMORY' => 256 * 1024 * 1024, // 256 MB
			'RESOURCETYPE_MAP'    => 512 * 1024 * 1024, // 512 MB
			'RESOURCETYPE_AREA'   => 128 * 1024 * 1024, // pixels
			'RESOURCETYPE_TIME'   => 30,                // seconds
			'RESOURCETYPE_THREAD' => 1,                 // single-threaded per encode
		);
		foreach ( $limits as $const_name => $value ) {
			$full = 'Imagick::' . $const_name;
			if ( ! defined( $full ) ) {
				continue;
			}
			try {
				$im->setResourceLimit( constant( $full ), $value );
			} catch ( Throwable $e ) {
				// Some builds refuse certain limits — fine, fall through.
			}
		}
	}

	/**
	 * Write a single variant (webp or avif) using a pre-loaded Imagick handle.
	 * Returns true when bytes were written, false otherwise. Never throws.
	 */
	private function write_imagick_variant( Imagick $im, string $dest, string $format, int $quality, string $ext ): bool {
		try {
			$im->setImageFormat( $format );
			$im->setImageCompressionQuality( $quality );

			if ( $format === 'webp' && $ext === 'png' ) {
				$im->setOption( 'webp:method', '6' );
			}
			if ( $format === 'avif' ) {
				// 6 is a reasonable default speed/quality trade for libavif.
				$im->setOption( 'heic:speed', '6' );
			}

			return (bool) $im->writeImage( $dest );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Convert one source and accumulate counters. Returns bytes saved (positive int).
	 *
	 * Bytes-saved is only counted for files that were actually (re)written —
	 * already-fresh files contribute 0.
	 */
	public function convert_and_measure( string $src, bool $force, int &$converted, int &$failed, int &$gd_fallbacks, int &$avif_written, ?int $attachment_id = null, ?array &$reasons = null ): int {
		// Local helper — push the reason for this iteration, deduplicated by
		// string. The batch UI surfaces the unique set so an attachment whose
		// 12 size variants all hit the same cap doesn't print 12 identical
		// warnings.
		$add_reason = static function ( ?string $r ) use ( &$reasons ) {
			if ( $reasons === null || $r === null || $r === '' ) {
				return;
			}
			if ( ! in_array( $r, $reasons, true ) ) {
				$reasons[] = $r;
			}
		};

		// Containment check first — never stat or read a path that points
		// outside the uploads tree, even though convert() would re-validate.
		// Cheaper to fail here than to filesize() something we'll reject anyway.
		if ( ! $this->paths->within_upload_dir( $src ) ) {
			++$failed;
			$add_reason( __( 'Source path is outside the uploads directory.', 'cf-media-manager' ) );
			return 0;
		}
		if ( ! is_readable( $src ) ) {
			++$failed;
			$add_reason( sprintf(
				/* translators: %s: filename of the missing or unreadable source image. */
				__( '%s is missing or unreadable on disk.', 'cf-media-manager' ),
				basename( $src )
			) );
			return 0;
		}

		$webp = Paths::src_to_variant_path( $src, 'webp' );
		// Derived variant path passes its own containment check before we
		// stat it — defends against the edge case where the source is inside
		// uploads but the swap_extension result somehow resolves outside
		// (symlink shenanigans). convert() re-validates, but doing it here
		// keeps the pre-stat-containment invariant honest.
		if ( ! $this->paths->within_upload_dir( $webp ) ) {
			++$failed;
			$add_reason( __( 'Derived WebP path resolved outside the uploads directory.', 'cf-media-manager' ) );
			return 0;
		}
		$pre_size = file_exists( $webp ) ? (int) @filesize( $webp ) : 0;
		$was_new  = $pre_size === 0;

		if ( ! $this->convert( $src, null, $force, $attachment_id ) ) {
			++$failed;
			$add_reason( $this->last_skip_reason );
			return 0;
		}
		++$converted;

		if ( $this->last_webp_engine === 'gd-fallback' ) {
			++$gd_fallbacks;
		}
		if ( $this->last_avif_written ) {
			++$avif_written;
		}

		if ( $was_new || $force ) {
			$src_size  = (int) @filesize( $src );
			$webp_size = (int) @filesize( $webp );
			return max( 0, $src_size - $webp_size );
		}
		return 0;
	}

	/**
	 * Per-attachment lock key + group used by {@see convert_attachment()}.
	 * Keeping these as constants so tests (and any future external coordinator)
	 * can address the same slot.
	 */
	const CONVERT_LOCK_GROUP = 'cf_media_manager_locks';
	const CONVERT_LOCK_TTL   = 300; // seconds — longer than any single attachment convert is expected to take.

	/** Option-name prefix for the cross-process per-attachment convert lock. */
	const CONVERT_LOCK_OPTION_PREFIX = 'cfmm_conv_lock_';

	/**
	 * Seconds the AVIF crash circuit-breaker stays armed after an AVIF encode
	 * that did not cleanly disarm. AVIF runs through libheif/libaom, which can
	 * segfault or be OOM-killed at the C level — a native death no PHP
	 * try/catch can trap. The breaker is keyed per source file: it lets WebP
	 * keep converting while a poison-pill source is skipped, and auto-expires
	 * so a transient OOM is retried later. See convert().
	 */
	const AVIF_BREAKER_TTL = 1800;

	/**
	 * Convert every size variant of an attachment.
	 *
	 * Returns [ converted, failed, bytes_saved, gd_fallbacks, avif_written, reasons ].
	 * `reasons` is a deduplicated array of human-readable strings for every
	 * variant that failed; empty when nothing failed.
	 *
	 * Concurrency: a per-attachment lock prevents two workers from converting
	 * the same attachment in parallel (cron tick + AJAX click, two cron
	 * workers under Action Scheduler's pool, the on_upload hook racing the
	 * background queue when AS picks up the same id mid-upload, etc.). When
	 * the lock is already held, returns a zero-state tuple with an
	 * "already in flight" reason so the caller's counters stay accurate.
	 *
	 * Two-tier lock — both layers atomic at the WP/MySQL level:
	 *   - `wp_cache_add()` is the SETNX-style fast path. On installs with
	 *     a persistent object cache (Redis, Memcached) this IS the
	 *     cross-process source of truth. Without persistent caching it
	 *     still gives in-process atomicity, catching the common "AJAX
	 *     click while a cron tick is mid-flight" race in the same
	 *     php-fpm worker.
	 *   - `add_option()` is the cross-process layer. MySQL's INSERT
	 *     IGNORE (which `add_option` issues for autoload=no options)
	 *     makes the acquire genuinely atomic across processes — no
	 *     check-then-set race window like the previous transient-based
	 *     fallback. A stale lock (TTL expired) is stolen via the same
	 *     verify-after-write pattern Queue::acquire_lock uses.
	 */
	public function convert_attachment( int $attachment_id, bool $force = false ): array {
		$cache_key  = 'cfmm_conv_' . $attachment_id;
		$option_key = self::CONVERT_LOCK_OPTION_PREFIX . $attachment_id;
		$pid        = function_exists( 'getmypid' ) ? getmypid() : 0;
		$token      = uniqid( '', true );
		$blocked    = array(
			0,
			0,
			0,
			0,
			0,
			array( __( 'Conversion already in progress for this attachment (another worker holds the lock).', 'cf-media-manager' ) ),
		);

		if ( ! $this->acquire_convert_lock( $cache_key, $option_key, (int) $pid, $token ) ) {
			return $blocked;
		}

		try {
			$file = get_attached_file( $attachment_id );
			$meta = wp_get_attachment_metadata( $attachment_id );

			$converted    = 0;
			$failed       = 0;
			$bytes_saved  = 0;
			$gd_fallbacks = 0;
			$avif_written = 0;
			$reasons      = array();

			if ( ! $file ) {
				$reasons[] = __( 'Attachment has no source file recorded (get_attached_file returned empty).', 'cf-media-manager' );
				return array( 0, 0, 0, 0, 0, $reasons );
			}
			if ( ! $meta ) {
				$reasons[] = __( 'Attachment has no metadata (wp_get_attachment_metadata returned empty).', 'cf-media-manager' );
				return array( 0, 0, 0, 0, 0, $reasons );
			}

			$bytes_saved += $this->convert_and_measure( $file, $force, $converted, $failed, $gd_fallbacks, $avif_written, $attachment_id, $reasons );

			// Without meta['file'] we can't compute the year/month subdir that
			// holds the size variants. dirname('') is '.', which would send size
			// conversions to upload-root/./<size> — wrong path. Original ($file)
			// is unaffected because get_attached_file() returns an absolute path.
			if ( empty( $meta['file'] ) ) {
				return array( $converted, $failed, $bytes_saved, $gd_fallbacks, $avif_written, $reasons );
			}

			$year_mon = trailingslashit( $this->paths->upload_dir() . dirname( $meta['file'] ) );
			foreach ( $meta['sizes'] ?? array() as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$bytes_saved += $this->convert_and_measure( $year_mon . $size['file'], $force, $converted, $failed, $gd_fallbacks, $avif_written, $attachment_id, $reasons );
			}

			return array( $converted, $failed, $bytes_saved, $gd_fallbacks, $avif_written, $reasons );
		} finally {
			$this->release_convert_lock( $cache_key, $option_key, $token );
		}
	}

	/**
	 * Acquire the two-layer per-attachment conversion lock, self-healing a stale
	 * lock left by a worker that died mid-convert (segfault, OOM-kill, or fatal).
	 * Returns true only when both layers are held under $token.
	 *
	 * The critical fix over the previous inline logic: staleness (expired TTL OR
	 * a dead owner PID) is evaluated BEFORE the object-cache fast path can
	 * short-circuit, so a leaked cache entry can no longer block the attachment
	 * for the full TTL on its own — and a crashed worker's lock is reclaimed on
	 * the very next attempt via the dead-PID check instead of waiting the TTL.
	 */
	private function acquire_convert_lock( string $cache_key, string $option_key, int $pid, string $token ): bool {
		// Self-heal: clear a stale option lock (and its cache shadow) up front.
		if ( function_exists( 'get_option' ) ) {
			$existing = get_option( $option_key );
			if ( is_array( $existing ) && self::lock_is_stale( $existing ) ) {
				if ( function_exists( 'delete_option' ) ) {
					delete_option( $option_key );
				}
				if ( function_exists( 'wp_cache_delete' ) ) {
					wp_cache_delete( $cache_key, self::CONVERT_LOCK_GROUP );
				}
			}
		}

		// Layer 1 — object-cache fast path (SETNX-style). A cache entry left by a
		// crashed worker is already cleared by the option-level self-heal above
		// (the option row persists and, once detected stale, deletes this shadow
		// too), so here we simply block when the slot is taken. We never steal a
		// bare cache entry — doing so would race a peer that is mid-acquire
		// between its own Layer 1 and Layer 2.
		$held_cache = false;
		if ( function_exists( 'wp_cache_add' ) ) {
			$held_cache = (bool) wp_cache_add( $cache_key, $pid, self::CONVERT_LOCK_GROUP, self::CONVERT_LOCK_TTL );
			if ( ! $held_cache ) {
				return false;
			}
		}

		// Layer 2 — cross-process atomic acquire via add_option (INSERT IGNORE),
		// with stale-lock steal using verify-after-write.
		if ( function_exists( 'add_option' ) ) {
			$payload = array(
				'pid'        => $pid,
				'token'      => $token,
				'expires'    => time() + self::CONVERT_LOCK_TTL,
				'started_at' => time(),
			);
			if ( ! add_option( $option_key, $payload, '', false ) ) {
				$current = function_exists( 'get_option' ) ? get_option( $option_key ) : false;
				if ( is_array( $current ) && self::lock_is_stale( $current ) ) {
					update_option( $option_key, $payload, false );
					if ( function_exists( 'wp_cache_delete' ) ) {
						wp_cache_delete( $option_key, 'options' );
					}
					$stored = function_exists( 'get_option' ) ? get_option( $option_key ) : false;
					if ( ! is_array( $stored ) || ( $stored['token'] ?? '' ) !== $token ) {
						if ( $held_cache && function_exists( 'wp_cache_delete' ) ) {
							wp_cache_delete( $cache_key, self::CONVERT_LOCK_GROUP );
						}
						return false; // lost the steal race
					}
				} else {
					// A live worker owns it — release the cache slot we took.
					if ( $held_cache && function_exists( 'wp_cache_delete' ) ) {
						wp_cache_delete( $cache_key, self::CONVERT_LOCK_GROUP );
					}
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Release both lock layers. The DB option is deleted only when its token
	 * still matches ours, so a peer that legitimately stole an expired lock
	 * mid-encode keeps its own lock.
	 */
	private function release_convert_lock( string $cache_key, string $option_key, string $token ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $cache_key, self::CONVERT_LOCK_GROUP );
		}
		if ( function_exists( 'get_option' ) && function_exists( 'delete_option' ) ) {
			$current = get_option( $option_key );
			if ( is_array( $current ) && ( $current['token'] ?? '' ) === $token ) {
				delete_option( $option_key );
			}
		}
	}

	/**
	 * A lock is stale when its TTL has expired OR its owner process is gone.
	 * The PID check enables immediate recovery from a crashed worker instead
	 * of waiting out the full TTL.
	 */
	private static function lock_is_stale( array $lock ): bool {
		$expires = (int) ( $lock['expires'] ?? 0 );
		if ( $expires > 0 && $expires < time() ) {
			return true;
		}
		$pid = (int) ( $lock['pid'] ?? 0 );
		return $pid > 0 && self::pid_is_dead( $pid );
	}

	/**
	 * True only when the PID is positively gone (ESRCH). When we cannot tell —
	 * posix unavailable, or EPERM because the process belongs to another user
	 * (CLI master vs php-fpm app user) — report "alive" so we never steal from
	 * a running worker; the TTL is the backstop for that case.
	 */
	private static function pid_is_dead( int $pid ): bool {
		if ( $pid <= 0 || ! function_exists( 'posix_kill' ) ) {
			return false;
		}
		if ( @posix_kill( $pid, 0 ) ) {
			return false; // signalable -> alive
		}
		// ESRCH (3) = no such process; EPERM (1) = exists but not ours.
		return function_exists( 'posix_get_last_error' ) && 3 === posix_get_last_error();
	}

	/**
	 * Hook callback for wp_generate_attachment_metadata. Converts every size
	 * generated by core's image-size pipeline. Returns $meta unchanged so we
	 * don't disturb other filters in the chain.
	 */
	public function on_upload( array $meta, int $attachment_id ): array {
		if ( empty( $meta['file'] ) ) {
			return $meta;
		}

		$base     = $this->paths->upload_dir();
		$year_mon = trailingslashit( dirname( $meta['file'] ) );

		$this->convert( $base . $meta['file'], null, false, $attachment_id );

		foreach ( $meta['sizes'] ?? [] as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$this->convert( $base . $year_mon . $size['file'], null, false, $attachment_id );
			}
		}

		return $meta;
	}

	/**
	 * True if the attachment has a fresh .webp on disk for its original file.
	 * Cheap — a single filesystem stat for the source plus its variant.
	 */
	/**
	 * True only if the attachment has a fresh, plugin-owned `.webp` on disk.
	 *
	 * Pre-1.2.2 this returned true on filename match alone, which produced a
	 * misleading "all converted" status when a user-uploaded `logo.webp` sat
	 * next to `logo.jpg` — the rewriter wouldn't actually serve it (since
	 * 1.2.1's ownership gate), but the dashboard called the source "done."
	 * The manifest check closes that gap so the UI agrees with reality.
	 *
	 * In test/CLI environments where the manifest is null or can't query
	 * (`is_owned_by` falls back to false without $wpdb), this returns false
	 * for foreign-looking variants. Production always has a manifest.
	 */
	public function is_attachment_converted( int $id ): bool {
		$file = get_attached_file( $id );
		if ( ! $file ) {
			return false;
		}
		// Containment check before any stat — poisoned attachment metadata
		// could point at a path outside the uploads tree.
		if ( ! $this->paths->within_upload_dir( $file ) ) {
			return false;
		}
		$webp = Paths::src_to_variant_path( $file, 'webp' );
		if ( ! $this->paths->within_upload_dir( $webp ) ) {
			return false;
		}
		// Both endpoints of the filemtime comparison must exist on disk before
		// we read them — a source that was deleted out from under the manifest
		// row (uploads-dir cleanup, accidental rm, etc.) would otherwise emit a
		// filemtime() warning AND return a misleading "not converted" boolean
		// driven by `false < <int>` rather than the actual freshness state.
		if ( ! file_exists( $file ) || ! file_exists( $webp ) ) {
			return false;
		}
		if ( filemtime( $webp ) < filemtime( $file ) ) {
			return false;
		}
		// Ownership gate — refuse to call a foreign same-basename .webp a
		// successful conversion. Without this gate the dashboard would tell
		// admins "all converted" while the rewriter (correctly) refused to
		// serve the foreign variant.
		return $this->manifest->is_owned_by( $id, $webp );
	}
}
