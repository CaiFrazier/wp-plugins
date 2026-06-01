<?php

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

/**
 * Detects attachments whose post_mime_type row disagrees with their stored
 * filename extension.
 *
 * Background: the plugin's global status counter enumerates JPEG/PNG by
 * `post_mime_type IN ('image/jpeg','image/png')`, while the In-use scanner
 * enumerates by `_wp_attached_file` extension match. A discrepancy between
 * the two is almost always an attachment whose post row carries the wrong
 * mime type (blank, generic, or a CDN-imported value) but whose file on
 * disk is a real JPEG or PNG.
 *
 * Classification is a pure function of (mime, relative_path) so the same
 * code drives the CLI report, the (future) admin-side notice, and tests
 * without touching $wpdb.
 */
final class MimeDoctor {

	/**
	 * Map a file path's extension to the post_mime_type the plugin treats
	 * as canonical for that extension. Returns null when the extension is
	 * not one we convert.
	 *
	 * @param string $relative_file Upload-relative path, e.g. "2024/01/x.jpg".
	 * @return string|null One of "image/jpeg", "image/png", or null.
	 */
	public static function expected_mime( string $relative_file ): ?string {
		if ( '' === $relative_file ) {
			return null;
		}
		$ext = strtolower( pathinfo( $relative_file, PATHINFO_EXTENSION ) );
		if ( 'jpg' === $ext || 'jpeg' === $ext ) {
			return 'image/jpeg';
		}
		if ( 'png' === $ext ) {
			return 'image/png';
		}
		return null;
	}

	/**
	 * True when (post_mime_type, file extension) disagree in a way that
	 * would cause the global JPEG/PNG counter to undercount this row.
	 *
	 * Cases returning true:
	 *   - File ends in .jpg/.jpeg but post_mime_type is anything other
	 *     than image/jpeg (blank, image/png, application/octet-stream, etc.)
	 *   - File ends in .png but post_mime_type is anything other than
	 *     image/png.
	 *
	 * Files with unrelated extensions (.gif, .pdf, .webp) are not
	 * mismatches — they're simply not in this plugin's domain.
	 *
	 * @param string $stored_mime Current value of post_mime_type column.
	 * @param string $relative_file Upload-relative path.
	 */
	public static function is_mismatch( string $stored_mime, string $relative_file ): bool {
		$expected = self::expected_mime( $relative_file );
		if ( null === $expected ) {
			return false;
		}
		return strtolower( trim( $stored_mime ) ) !== $expected;
	}

	/**
	 * Apply classification to a batch of rows.
	 *
	 * @param array<int, array{ID:int, post_mime_type:string, file:string}> $rows
	 *   Rows from a SELECT joining wp_posts to wp_postmeta on
	 *   _wp_attached_file. Each row must have ID, post_mime_type, file.
	 * @return array<int, array{ID:int, current_mime:string, expected_mime:string, file:string}>
	 *   Only rows classified as mismatched. expected_mime is the value the
	 *   plugin would expect for the file's extension.
	 */
	public static function find_mismatches( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$file = isset( $row['file'] ) ? (string) $row['file'] : '';
			$mime = isset( $row['post_mime_type'] ) ? (string) $row['post_mime_type'] : '';
			if ( ! self::is_mismatch( $mime, $file ) ) {
				continue;
			}
			$out[] = array(
				'ID'            => isset( $row['ID'] ) ? (int) $row['ID'] : 0,
				'current_mime'  => $mime,
				'expected_mime' => (string) self::expected_mime( $file ),
				'file'          => $file,
			);
		}
		return $out;
	}

	/**
	 * Fetch every attachment whose stored filename ends in .jpg/.jpeg/.png.
	 * Returned rows are ready to feed to find_mismatches().
	 *
	 * Returns an empty array when $wpdb isn't available (test bootstrap,
	 * uninstall context).
	 *
	 * @return array<int, array{ID:int, post_mime_type:string, file:string}>
	 */
	public static function fetch_candidate_rows(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_mime_type, pm.meta_value AS file
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm
			   ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
			 WHERE p.post_type = 'attachment'
			   AND pm.meta_value REGEXP '\\\\.(jpe?g|png)$'",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'ID'             => isset( $row['ID'] ) ? (int) $row['ID'] : 0,
				'post_mime_type' => isset( $row['post_mime_type'] ) ? (string) $row['post_mime_type'] : '',
				'file'           => isset( $row['file'] ) ? (string) $row['file'] : '',
			);
		}
		return $out;
	}

	/**
	 * Repair a single attachment's post_mime_type to match its file
	 * extension. Returns true on a successful UPDATE.
	 */
	public static function repair( int $attachment_id, string $expected_mime ): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || $attachment_id <= 0 ) {
			return false;
		}
		if ( ! in_array( $expected_mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$wpdb->posts,
			array( 'post_mime_type' => $expected_mime ),
			array( 'ID' => $attachment_id ),
			array( '%s' ),
			array( '%d' )
		);
		return false !== $updated;
	}

	private function __construct() {}
}
