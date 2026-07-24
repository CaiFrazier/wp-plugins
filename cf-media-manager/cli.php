<?php
/**
 * CF Media Manager — WP-CLI commands.
 *
 * Loaded only when running under WP-CLI. See cf-media-manager.php bootstrap.
 *
 * Examples:
 *   wp cf-media-manager doctor
 *   wp cf-media-manager doctor --fix
 */

defined( 'ABSPATH' ) || exit;

use CFMediaManager\MimeDoctor;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- WP-CLI command class registered as `cf-media-manager`; the class name carries the full plugin slug.
class CF_Media_Manager_CLI {

	/**
	 * Report attachments whose post_mime_type row disagrees with their file
	 * extension, and optionally repair them. These rows are a common cause of
	 * media-library inconsistencies (an image saved with the wrong or empty
	 * mime type is invisible to mime-filtered queries but visible to
	 * filename-based scans).
	 *
	 * ## OPTIONS
	 *
	 * [--fix]
	 * : Repair each mismatched row by updating post_mime_type to match the
	 * file extension (image/jpeg or image/png). Without this flag, the
	 * command only reports.
	 *
	 * [--format=<format>]
	 * : Output format for the report. Accepts: table, json, yaml, csv.
	 * Default: table.
	 *
	 * ## EXAMPLES
	 *     wp cf-media-manager doctor
	 *     wp cf-media-manager doctor --fix
	 *     wp cf-media-manager doctor --format=json
	 *
	 * @when after_wp_load
	 */
	public function doctor( $args, $assoc_args ): void {
		$rows       = MimeDoctor::fetch_candidate_rows();
		$mismatches = MimeDoctor::find_mismatches( $rows );
		$total_scan = count( $rows );
		$total_bad  = count( $mismatches );

		WP_CLI::log(
			sprintf(
				/* translators: 1: number of JPEG/PNG attachments scanned, 2: number found with mismatched post_mime_type. */
				__( 'Scanned %1$d JPEG/PNG attachments by filename. Found %2$d with a mismatched post_mime_type row.', 'cf-media-manager' ),
				$total_scan,
				$total_bad
			)
		);

		if ( 0 === $total_bad ) {
			WP_CLI::success( __( 'No mime mismatches detected.', 'cf-media-manager' ) );
			return;
		}

		$display = array();
		foreach ( $mismatches as $m ) {
			$display[] = array(
				'ID'            => $m['ID'],
				'current_mime'  => '' === $m['current_mime'] ? '(empty)' : $m['current_mime'],
				'expected_mime' => $m['expected_mime'],
				'file'          => $m['file'],
			);
		}

		WP_CLI\Utils\format_items(
			$assoc_args['format'] ?? 'table',
			$display,
			array( 'ID', 'current_mime', 'expected_mime', 'file' )
		);

		if ( empty( $assoc_args['fix'] ) ) {
			WP_CLI::log( __( 'Re-run with --fix to repair these rows.', 'cf-media-manager' ) );
			return;
		}

		$repaired = 0;
		$failed   = 0;
		foreach ( $mismatches as $m ) {
			if ( MimeDoctor::repair( (int) $m['ID'], (string) $m['expected_mime'] ) ) {
				++$repaired;
			} else {
				++$failed;
			}
		}

		WP_CLI::success(
			sprintf(
				/* translators: 1: number of post_mime_type rows successfully updated, 2: number of failed updates. */
				__( 'Repaired %1$d row(s). %2$d failed.', 'cf-media-manager' ),
				$repaired,
				$failed
			)
		);
	}
}

WP_CLI::add_command( 'cf-media-manager', CF_Media_Manager_CLI::class );
