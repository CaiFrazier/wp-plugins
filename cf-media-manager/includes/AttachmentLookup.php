<?php

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

/**
 * Pre-built attachment URL/path → ID maps for hot-path resolution.
 *
 * The WordPress core function `attachment_url_to_postid()` does the same
 * lookup one URL at a time: parse, normalize, run through a filter chain,
 * query the indexed `_wp_attached_file` row. For pipelines that resolve N
 * paths in a single request — the legacy-variant backfill walks every
 * `.webp`/`.avif` file in an entire subtree — the per-call overhead
 * dominates. Worse, the filter chain is hooked by every major image
 * optimizer, CDN, and offload plugin; any of them can silently return 0
 * for a path that genuinely belongs to an attachment, producing both a
 * slow path and an unreliable one in the same call.
 *
 * This class sidesteps both problems by reading the relevant postmeta
 * rows directly in one query and building in-memory hashes. Resolution
 * becomes an O(1) lookup with no filter chain involvement, no URL
 * normalization, and no per-call DB hit. The map is the source of truth
 * the database itself uses, so anything `_wp_attached_file` records gets
 * resolved; anything it doesn't, doesn't (which is the correct behavior).
 *
 * Two maps are exposed:
 *
 *   1. {@see path_to_id()} — every row of `_wp_attached_file` as
 *      `relative_path => attachment_id`. Covers original-file resolution
 *      (the WP attachment record points at the original upload).
 *
 *   2. {@see size_to_parent()} — for each `_wp_attachment_metadata` row,
 *      every entry in its `sizes[]` array, keyed as
 *      `dirname/size_filename => parent_id`. Covers thumbnail/size-variant
 *      resolution (WP doesn't store size filenames as their own rows;
 *      they live inside the parent's serialized metadata blob).
 *
 * Both maps are static-cached per request via {@see reset()} for tests.
 * Production code never invalidates mid-request — the underlying postmeta
 * rows can't change in a way that matters within a single AJAX call.
 */
final class AttachmentLookup {

	/** @var array<string,int>|null */
	private static ?array $path_to_id = null;

	/** @var array<string,int>|null */
	private static ?array $size_to_parent = null;

	/**
	 * Reset the cached maps. Used by tests; production code never invalidates
	 * mid-request.
	 */
	public static function reset(): void {
		self::$path_to_id     = null;
		self::$size_to_parent = null;
	}

	/**
	 * Get the original-file path → attachment ID map. Builds lazily on first
	 * call; subsequent calls return the cached array.
	 *
	 * Keys are upload-relative paths exactly as stored in `_wp_attached_file`
	 * (leading slash stripped). Values are attachment post IDs.
	 *
	 * @return array<string,int>
	 */
	public static function path_to_id(): array {
		if ( null === self::$path_to_id ) {
			self::$path_to_id = self::build_path_to_id_map();
		}
		return self::$path_to_id;
	}

	/**
	 * Get the size-variant path → parent attachment ID map. Builds lazily on
	 * first call; subsequent calls return the cached array.
	 *
	 * Keys are upload-relative paths to size variants (e.g. `2024/03/photo-300x200.jpg`).
	 * Values are the PARENT attachment ID (the original's post ID, not a
	 * separate row for the size — WordPress doesn't create one).
	 *
	 * @return array<string,int>
	 */
	public static function size_to_parent(): array {
		if ( null === self::$size_to_parent ) {
			self::$size_to_parent = self::build_size_to_parent_map();
		}
		return self::$size_to_parent;
	}

	/**
	 * Resolve a relative upload-dir path (like `2024/03/foo.png`) to an
	 * attachment ID. Returns 0 when no match exists in either map.
	 *
	 * Checks both maps in order: original-file first (most paths are
	 * originals), then size-variant fallback. Callers don't have to know
	 * which path type they're holding.
	 */
	public static function resolve_relative_path( string $relative_path ): int {
		$relative_path = ltrim( $relative_path, '/' );
		if ( '' === $relative_path ) {
			return 0;
		}
		$direct = self::path_to_id()[ $relative_path ] ?? 0;
		if ( $direct > 0 ) {
			return (int) $direct;
		}
		$size = self::size_to_parent()[ $relative_path ] ?? 0;
		return (int) $size;
	}

	private static function build_path_to_id_map(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$rows = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'",
			ARRAY_A
		);

		$map = array();
		if ( ! is_array( $rows ) ) {
			return $map;
		}
		foreach ( $rows as $row ) {
			$rel = ltrim( (string) ( $row['meta_value'] ?? '' ), '/' );
			if ( '' === $rel ) {
				continue;
			}
			$map[ $rel ] = (int) $row['post_id'];
		}
		return $map;
	}

	private static function build_size_to_parent_map(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}

		// Join _wp_attachment_metadata with _wp_attached_file so we get the
		// parent's relative path alongside the serialized sizes blob —
		// avoids a second lookup per row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$rows = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_value AS meta_blob, attached.meta_value AS attached_file
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->postmeta} attached
			   ON attached.post_id = pm.post_id AND attached.meta_key = '_wp_attached_file'
			 WHERE pm.meta_key = '_wp_attachment_metadata'",
			ARRAY_A
		);

		$map = array();
		if ( ! is_array( $rows ) ) {
			return $map;
		}
		foreach ( $rows as $row ) {
			$attached = ltrim( (string) ( $row['attached_file'] ?? '' ), '/' );
			if ( '' === $attached ) {
				continue;
			}
			// dirname() returns '.' for files at the upload root — normalize to ''.
			$dir_raw = dirname( $attached );
			$dir     = ( '.' === $dir_raw || '' === $dir_raw ) ? '' : trim( $dir_raw, '/' );

			$meta = maybe_unserialize( (string) ( $row['meta_blob'] ?? '' ) );
			if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
				continue;
			}
			foreach ( $meta['sizes'] as $size ) {
				if ( ! is_array( $size ) || empty( $size['file'] ) || ! is_string( $size['file'] ) ) {
					continue;
				}
				$rel         = ( '' !== $dir ? $dir . '/' : '' ) . $size['file'];
				$map[ $rel ] = (int) $row['post_id'];
			}
		}
		return $map;
	}
}
