<?php
/**
 * IgnoredStore — hybrid storage for "user has chosen to ignore this item
 * in this report" decisions.
 *
 * Two backends, one API:
 *
 *   - Attachment-keyed reports (int $key) use postmeta. The post ID is the
 *     row's natural anchor; storage piggybacks on WordPress' built-in
 *     meta-cache so reads are essentially free during a list render.
 *
 *   - Path-keyed reports (string $key) use a single option blob, because
 *     orphan files have no attachment row to hang postmeta on. The blob
 *     is keyed by upload-relative path, normalized through normalize_key()
 *     so callers don't have to remember the encoding.
 *
 * Both stores carry a per-report metadata bag (ignored_at by default,
 * arbitrary keys allowed) so future audits can record richer context.
 * Uninstall sweeps both stores via purge_all() when DELETE_ON_UNINSTALL.
 */

namespace CFMediaManager\Audit;

use CFMediaManager\Options;

defined( 'ABSPATH' ) || exit;

final class IgnoredStore {

	/**
	 * Postmeta key. Stores an array shaped:
	 *   [ report_id => meta_array, ... ]
	 * where meta_array always contains an 'ignored_at' unix timestamp and
	 * may carry report-specific extras.
	 */
	const POSTMETA_KEY = '_cf_audit_ignored_in';

	/**
	 * Mark an item ignored for a given report.
	 *
	 * The same item may be ignored across multiple reports; entries are
	 * merged rather than overwriting. Re-ignoring the same (report, key)
	 * refreshes the 'ignored_at' timestamp and merges meta.
	 *
	 * @param string     $report_id Report id, see AuditReportInterface::id().
	 * @param int|string $key       Attachment ID (int) or upload-relative path (string).
	 * @param array      $meta      Optional metadata bag.
	 */
	public function ignore( string $report_id, $key, array $meta = array() ): void {
		$meta['ignored_at'] = $meta['ignored_at'] ?? time();

		if ( is_int( $key ) ) {
			$this->set_attachment_entry( $key, $report_id, $meta );
			return;
		}
		$this->set_path_entry( self::normalize_key( (string) $key ), $report_id, $meta );
	}

	/**
	 * Check whether an item is ignored for a given report.
	 */
	public function is_ignored( string $report_id, $key ): bool {
		if ( is_int( $key ) ) {
			$bag = $this->read_attachment_bag( $key );
			return isset( $bag[ $report_id ] );
		}
		$bag = $this->read_path_bag( self::normalize_key( (string) $key ) );
		return isset( $bag[ $report_id ] );
	}

	/**
	 * Reverse a previous ignore. No-op if the item wasn't ignored.
	 */
	public function unignore( string $report_id, $key ): void {
		if ( is_int( $key ) ) {
			$this->clear_attachment_entry( $key, $report_id );
			return;
		}
		$this->clear_path_entry( self::normalize_key( (string) $key ), $report_id );
	}

	/**
	 * Read the metadata bag a caller stored for one (report, key) pair, or
	 * null when the item isn't ignored. Useful when the UI wants to show
	 * "ignored Apr 12 by …" without making a second roundtrip.
	 */
	public function meta( string $report_id, $key ): ?array {
		if ( is_int( $key ) ) {
			$bag = $this->read_attachment_bag( $key );
			return $bag[ $report_id ] ?? null;
		}
		$bag = $this->read_path_bag( self::normalize_key( (string) $key ) );
		return $bag[ $report_id ] ?? null;
	}

	/**
	 * List every ignored item for a given report. Returns a flat array of
	 * ints (attachment IDs) AND strings (paths) — the caller knows which
	 * type its report uses, so the mixed shape is unambiguous in practice.
	 *
	 * Implementation note: this is the only operation that can't piggyback
	 * on the postmeta cache. Attachment-keyed reports usually don't need
	 * to enumerate ignored IDs (they iterate live attachments and check
	 * each), so this scans wp_postmeta with a meta_key WHERE. Path-keyed
	 * reports read the option blob directly.
	 *
	 * @return array<int|string>
	 */
	public function list_ignored( string $report_id ): array {
		global $wpdb;
		$out = array();

		// Path-keyed entries from the option blob.
		$blob = $this->read_path_blob();
		foreach ( $blob as $path => $reports ) {
			if ( isset( $reports[ $report_id ] ) ) {
				$out[] = (string) $path;
			}
		}

		// Attachment-keyed entries from postmeta. Filter to attachments to
		// avoid surfacing IDs from unrelated post types if someone hand-
		// edits the meta key (defensive — the API never writes those).
		if ( isset( $wpdb ) ) {
			// list_ignored() enumerates the postmeta-side store: every
			// attachment row carrying our ignore-bag meta key. WP_Query
			// meta_query can't express "attachment post AND specific
			// meta_key present" without N+1 queries; the JOIN here is one
			// pass. Read-only, called only when the UI explicitly
			// enumerates ignored items.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.post_id AS id, pm.meta_value AS bag
					   FROM {$wpdb->postmeta} pm
					   JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					  WHERE pm.meta_key = %s
					    AND p.post_type = 'attachment'",
					self::POSTMETA_KEY
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$bag = maybe_unserialize( $row['bag'] );
					if ( is_array( $bag ) && isset( $bag[ $report_id ] ) ) {
						$out[] = (int) $row['id'];
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Drop every ignore record this store owns. Called from uninstall.php
	 * when DELETE_ON_UNINSTALL is set.
	 */
	public function purge_all(): void {
		global $wpdb;

		delete_option( Options::AUDIT_IGNORED_PATHS );

		if ( isset( $wpdb ) ) {
			// Uninstall sweep across every postmeta row carrying our
			// ignore-bag key. delete_post_meta is per-post and would
			// require enumerating every attachment first. meta_key here
			// is the column-name arg for $wpdb->delete, not a query input.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => self::POSTMETA_KEY ) );
		}
	}

	// =====================================================================
	// Path-key normalization
	// =====================================================================

	/**
	 * Canonicalize an upload-relative path so the same file always lands on
	 * the same blob key regardless of how the caller wrote it. Filesystems
	 * we care about are case-sensitive (Linux) — DO NOT lowercase.
	 */
	public static function normalize_key( string $key ): string {
		$key = str_replace( '\\', '/', $key );
		$key = ltrim( $key, '/' );
		// Collapse any accidental "./" segments and double-slashes.
		$key = preg_replace( '#/{2,}#', '/', $key );
		$key = preg_replace( '#(^|/)\./#', '$1', $key );
		return $key;
	}

	// =====================================================================
	// Postmeta-backed (attachment IDs)
	// =====================================================================

	private function read_attachment_bag( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::POSTMETA_KEY, true );
		return is_array( $raw ) ? $raw : array();
	}

	private function set_attachment_entry( int $post_id, string $report_id, array $meta ): void {
		$bag               = $this->read_attachment_bag( $post_id );
		$bag[ $report_id ] = array_merge( $bag[ $report_id ] ?? array(), $meta );
		update_post_meta( $post_id, self::POSTMETA_KEY, $bag );
	}

	private function clear_attachment_entry( int $post_id, string $report_id ): void {
		$bag = $this->read_attachment_bag( $post_id );
		if ( ! isset( $bag[ $report_id ] ) ) {
			return;
		}
		unset( $bag[ $report_id ] );
		if ( empty( $bag ) ) {
			delete_post_meta( $post_id, self::POSTMETA_KEY );
			return;
		}
		update_post_meta( $post_id, self::POSTMETA_KEY, $bag );
	}

	// =====================================================================
	// Option-blob-backed (paths)
	// =====================================================================

	private function read_path_blob(): array {
		$blob = get_option( Options::AUDIT_IGNORED_PATHS, array() );
		return is_array( $blob ) ? $blob : array();
	}

	private function read_path_bag( string $normalized_key ): array {
		$blob = $this->read_path_blob();
		return isset( $blob[ $normalized_key ] ) && is_array( $blob[ $normalized_key ] )
			? $blob[ $normalized_key ]
			: array();
	}

	private function set_path_entry( string $normalized_key, string $report_id, array $meta ): void {
		$blob                    = $this->read_path_blob();
		$bag                     = $blob[ $normalized_key ] ?? array();
		$bag[ $report_id ]       = array_merge( $bag[ $report_id ] ?? array(), $meta );
		$blob[ $normalized_key ] = $bag;
		update_option( Options::AUDIT_IGNORED_PATHS, $blob, false );
	}

	private function clear_path_entry( string $normalized_key, string $report_id ): void {
		$blob = $this->read_path_blob();
		if ( ! isset( $blob[ $normalized_key ][ $report_id ] ) ) {
			return;
		}
		unset( $blob[ $normalized_key ][ $report_id ] );
		if ( empty( $blob[ $normalized_key ] ) ) {
			unset( $blob[ $normalized_key ] );
		}
		if ( empty( $blob ) ) {
			delete_option( Options::AUDIT_IGNORED_PATHS );
			return;
		}
		update_option( Options::AUDIT_IGNORED_PATHS, $blob, false );
	}
}
