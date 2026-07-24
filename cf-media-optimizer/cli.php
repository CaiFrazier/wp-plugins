<?php
/**
 * CF Media Optimizer — WP-CLI commands.
 *
 * Loaded only when running under WP-CLI. See cf-media-optimizer.php bootstrap.
 *
 * Examples:
 *   wp cf-media-optimizer status
 *   wp cf-media-optimizer convert
 *   wp cf-media-optimizer convert --force
 *   wp cf-media-optimizer convert --ids=12,34,56
 *   wp cf-media-optimizer convert --quality=78
 *   wp cf-media-optimizer queue start
 *   wp cf-media-optimizer queue status
 *   wp cf-media-optimizer queue cancel
 */

defined( 'ABSPATH' ) || exit;

use CFMediaOptimizer\Plugin;
use CFMediaOptimizer\Options;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- WP-CLI command class registered as `cf-media-optimizer`; the class name carries the full plugin slug.
class CF_Media_Optimizer_CLI {

	private function plugin(): Plugin {
		return Plugin::instance();
	}

	/**
	 * Show conversion status for the media library.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: table, json, yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *     wp cf-media-optimizer status
	 *     wp cf-media-optimizer status --format=json
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ): void {
		[ $total, $done, $pending ] = $this->plugin()->ajax()->get_conversion_counts();

		$engine = extension_loaded( 'imagick' )
			? 'Imagick'
			: ( function_exists( 'imagewebp' ) ? 'GD' : 'NONE' );

		$rows = [
			[
				'metric' => __( 'Engine', 'cf-media-optimizer' ),
				'value'  => $engine,
			],
			[
				'metric' => __( 'AVIF', 'cf-media-optimizer' ),
				'value'  => \CFMediaOptimizer\Converter::avif_supported() ? __( 'available', 'cf-media-optimizer' ) : __( 'unavailable', 'cf-media-optimizer' ),
			],
			[
				'metric' => __( 'Total', 'cf-media-optimizer' ),
				'value'  => (string) $total,
			],
			[
				'metric' => __( 'Converted', 'cf-media-optimizer' ),
				'value'  => (string) $done,
			],
			[
				'metric' => __( 'Pending', 'cf-media-optimizer' ),
				'value'  => (string) count( $pending ),
			],
			[
				'metric' => __( 'Quality', 'cf-media-optimizer' ),
				'value'  => (string) get_option( Options::QUALITY, Options::DEFAULT_QUALITY ),
			],
			[
				'metric' => __( 'Rewriting', 'cf-media-optimizer' ),
				'value'  => get_option( Options::REWRITE, true ) ? __( 'enabled', 'cf-media-optimizer' ) : __( 'disabled', 'cf-media-optimizer' ),
			],
		];

		WP_CLI\Utils\format_items(
			$assoc_args['format'] ?? 'table',
			$rows,
			[ 'metric', 'value' ]
		);
	}

	/**
	 * Convert JPEG/PNG attachments to WebP (and AVIF when available).
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Re-encode even files that are already up-to-date.
	 *
	 * [--ids=<ids>]
	 * : Comma-separated attachment IDs to convert. Default: all pending.
	 *
	 * [--quality=<quality>]
	 * : Override the saved quality for this run (1-100).
	 *
	 * ## EXAMPLES
	 *     wp cf-media-optimizer convert
	 *     wp cf-media-optimizer convert --force
	 *     wp cf-media-optimizer convert --ids=12,34,56
	 *     wp cf-media-optimizer convert --quality=78 --force
	 *
	 * @when after_wp_load
	 */
	public function convert( $args, $assoc_args ): void {
		$force   = ! empty( $assoc_args['force'] );
		$quality = isset( $assoc_args['quality'] ) ? max( 1, min( 100, (int) $assoc_args['quality'] ) ) : null;

		if ( ! empty( $assoc_args['ids'] ) ) {
			$ids = array_filter( array_map( 'intval', explode( ',', $assoc_args['ids'] ) ) );
		} else {
			[ , , $pending, $all ] = $this->plugin()->ajax()->get_conversion_counts();
			$ids                   = $force ? $all : $pending;
		}

		if ( empty( $ids ) ) {
			WP_CLI::success( __( 'Nothing to convert.', 'cf-media-optimizer' ) );
			return;
		}

		// Honor the --quality flag for this run only. The restore is wrapped
		// in try/finally so a Ctrl-C, fatal error, or exception mid-run
		// can't leave the site's stored quality stuck at the per-run value.
		$saved = null;
		if ( $quality !== null ) {
			$saved = get_option( Options::QUALITY, Options::DEFAULT_QUALITY );
			update_option( Options::QUALITY, $quality );
		}

		$progress     = WP_CLI\Utils\make_progress_bar( __( 'Converting', 'cf-media-optimizer' ), count( $ids ) );
		$converted    = 0;
		$failed       = 0;
		$gd_fallbacks = 0;
		$bytes_saved  = 0;
		$avif_written = 0;

		try {
			$converter = $this->plugin()->converter();
			foreach ( $ids as $id ) {
				[ $c, $f, $b, $g, $a ] = $converter->convert_attachment( (int) $id, $force );
				$converted            += $c;
				$failed               += $f;
				$bytes_saved          += $b;
				$gd_fallbacks         += $g;
				$avif_written         += $a;
				$progress->tick();
			}

			$progress->finish();
		} finally {
			if ( $quality !== null && $saved !== null ) {
				update_option( Options::QUALITY, $saved );
			}
		}

		if ( $bytes_saved > 0 || $converted > 0 ) {
			update_option( Options::PURGE_FLAG, time() );
		}

		WP_CLI::success(
			sprintf(
				/* translators: 1: number converted, 2: number failed, 3: number of AVIF files written, 4: number of GD fallbacks, 5: size_format() of bytes saved. */
				__( '%1$d converted, %2$d failed, %3$d AVIF, %4$d GD fallback(s) — saved %5$s.', 'cf-media-optimizer' ),
				$converted,
				$failed,
				$avif_written,
				$gd_fallbacks,
				size_format( $bytes_saved )
			)
		);

		if ( $gd_fallbacks > 0 ) {
			WP_CLI::warning(
				sprintf(
					/* translators: %d: number of attachments that fell back to the GD library after Imagick failed. */
					_n(
						'%d file used GD fallback — Imagick failed for it.',
						'%d files used GD fallback — Imagick failed for these.',
						$gd_fallbacks,
						'cf-media-optimizer'
					),
					$gd_fallbacks
				)
			);
		}
	}

	/**
	 * Background queue control.
	 *
	 * ## OPTIONS
	 *
	 * <subcommand>
	 * : start | status | cancel
	 *
	 * [--force]
	 * : With `start`: re-encode every attachment, not only pending ones.
	 *
	 * ## EXAMPLES
	 *     wp cf-media-optimizer queue start
	 *     wp cf-media-optimizer queue start --force
	 *     wp cf-media-optimizer queue status
	 *     wp cf-media-optimizer queue cancel
	 *
	 * @when after_wp_load
	 */
	public function queue( $args, $assoc_args ): void {
		$sub   = $args[0] ?? '';
		$queue = $this->plugin()->queue();

		switch ( $sub ) {
			case 'start':
				$force                 = ! empty( $assoc_args['force'] );
				[ , , $pending, $all ] = $this->plugin()->ajax()->get_conversion_counts();
				$ids                   = $force ? $all : $pending;
				$state                 = $queue->start( $ids, $force );
				WP_CLI::success(
					sprintf(
						/* translators: 1: number of attachments queued, 2: queue backend name (e.g. action-scheduler, wp-cron). */
						__( 'Queued %1$d attachments (backend: %2$s).', 'cf-media-optimizer' ),
						$state['total'],
						$state['backend']
					)
				);
				break;

			case 'status':
				$state = $queue->state();
				WP_CLI::log(
					sprintf(
						/* translators: 1: queue status (running/complete/cancelled), 2: backend, 3: total, 4: processed, 5: converted, 6: failed, 7: human-readable bytes saved. */
						__( "status:    %1\$s\nbackend:   %2\$s\ntotal:     %3\$d\nprocessed: %4\$d\nconverted: %5\$d\nfailed:    %6\$d\nbytes saved: %7\$s", 'cf-media-optimizer' ),
						$state['status'],
						$state['backend'],
						$state['total'],
						$state['processed'],
						$state['converted'],
						$state['failed'],
						size_format( $state['bytes_saved'] )
					)
				);
				break;

			case 'cancel':
				$queue->cancel();
				WP_CLI::success( __( 'Queue cancelled.', 'cf-media-optimizer' ) );
				break;

			default:
				WP_CLI::error( __( 'Unknown subcommand. Use: start | status | cancel', 'cf-media-optimizer' ) );
		}
	}
	/**
	 * Adopt legacy `.webp` / `.avif` variants into the ownership manifest.
	 *
	 * For installs that ran older versions of this plugin (before 1.2.0), the
	 * variants on disk have no ownership record. The rewriter (which is
	 * ownership-aware on 1.2.1+) will refuse to serve them, and "Delete All"
	 * will not touch them. Run this once after upgrade. Files that are
	 * themselves Media Library attachments are skipped.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report how many files would be adopted without writing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cf-media-optimizer backfill --dry-run
	 *     wp cf-media-optimizer backfill
	 *
	 * @when after_wp_load
	 */
	public function backfill( $args, $assoc_args ): void {
		$dry_run  = ! empty( $assoc_args['dry-run'] );
		$plugin   = \CFMediaOptimizer\Plugin::instance();
		$paths    = $plugin->paths();
		$manifest = $plugin->manifest();
		$scanner  = new \CFMediaOptimizer\VariantScanner( $paths );

		// Build the lookup maps and owned-pairs set ONCE for the whole CLI run.
		// The CLI loops every subtree itself, so the maps stay hot across
		// iterations — no per-chunk rebuild like the AJAX path needs.
		$path_to_id     = \CFMediaOptimizer\AttachmentLookup::path_to_id();
		$size_to_parent = \CFMediaOptimizer\AttachmentLookup::size_to_parent();
		$owned_pairs    = $manifest->build_owned_pairs_set();

		$total  = 0;
		$cursor = '';
		do {
			$resolved = $scanner->next_subtree( $cursor );
			if ( null === $resolved['subtree'] ) {
				break;
			}
			$non_recursive = ( $resolved['scope'] ?? 'subtree' ) === 'root';

			// Drain the subtree in inner-chunk slices. The bulk method
			// returns a next_offset when the subtree isn't fully processed;
			// we keep calling with the same subtree until next_offset is null.
			$offset = 0;
			do {
				$result = $manifest->backfill_subtree_bulk(
					$resolved['subtree'],
					$path_to_id,
					$size_to_parent,
					$owned_pairs,
					$offset,
					\CFMediaOptimizer\Options::BACKFILL_CHUNK_FILES,
					$non_recursive,
					$dry_run
				);
				$total += (int) ( $result['claimed'] ?? 0 );
				$offset = $result['next_offset'];
			} while ( null !== $offset );

			$cursor = (string) ( $resolved['next_cursor'] ?? '' );
		} while ( null !== $resolved['next_cursor'] );

		if ( ! $dry_run ) {
			update_option( \CFMediaOptimizer\Options::BACKFILL_DONE, 1, false );
		}

		WP_CLI::success(
			$dry_run
				? sprintf(
					/* translators: %d: number of files that would be adopted. */
					__( '%d file(s) would be adopted. Re-run without --dry-run to commit.', 'cf-media-optimizer' ),
					$total
				)
				: sprintf(
					/* translators: %d: number of files adopted. */
					__( 'Adopted %d file(s) into the manifest.', 'cf-media-optimizer' ),
					$total
				)
		);
	}
}

WP_CLI::add_command( 'cf-media-optimizer', CF_Media_Optimizer_CLI::class );
