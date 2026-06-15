<?php

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

/**
 * Alt text bulk audit and inline editor.
 *
 * Reads and writes the standard WordPress `_wp_attachment_image_alt` postmeta
 * key — i.e. the same field WP's own Media Library edit screen shows under
 * "Alternative Text". This is the field most themes and plugins resolve when
 * rendering an attachment, so updating it propagates everywhere.
 *
 * Adds an opt-in `_cf_media_manager_decorative` flag for images the admin
 * marks as intentionally decorative. The audit suppresses the "missing alt"
 * badge for these so empty alt — which is the WCAG-correct value for purely
 * decorative imagery — doesn't get flagged as an accessibility defect.
 *
 * Known limitation: this audits the attachment-level field. If a Gutenberg
 * image block or page-builder element overrides alt inline (rendering with a
 * different alt than the attachment field carries), the override is invisible
 * to this audit. Detecting that would require rendering every page in the
 * site, which is out of scope. The audit UI surfaces this limitation in copy.
 */
final class AltTextManager {

	/** WordPress core meta key for attachment alt text. */
	const META_KEY_ALT = '_wp_attachment_image_alt';

	/** Our flag for "intentionally empty alt — decorative image." */
	const META_KEY_DECORATIVE = '_cf_media_manager_decorative';

	/** Default rows per page in the audit UI. */
	const PAGE_SIZE = 25;

	/** Hard upper bound on rows per AJAX response. */
	const MAX_PAGE_SIZE = 100;

	/**
	 * Valid filter modes for ajax_list_alts():
	 *   all            — every image attachment
	 *   missing        — images whose alt is empty/missing AND not decorative
	 *   in_use         — images referenced on the front end (any alt state)
	 *   in_use_missing — intersection: in-use AND missing-alt
	 */
	const VALID_FILTERS = array( 'all', 'missing', 'in_use', 'in_use_missing' );

	private InUseScanner $in_use;

	public function __construct( InUseScanner $in_use ) {
		$this->in_use = $in_use;
	}

	public function register_hooks(): void {
		add_action( 'wp_ajax_' . Plugin::AJAX_PREFIX . 'alt_list', array( $this, 'ajax_list_alts' ) );
		add_action( 'wp_ajax_' . Plugin::AJAX_PREFIX . 'alt_save', array( $this, 'ajax_save_alt' ) );
		add_action( 'wp_ajax_' . Plugin::AJAX_PREFIX . 'alt_save_bulk', array( $this, 'ajax_save_alt_bulk' ) );
	}

	/**
	 * Count image attachments with empty/missing alt text that aren't
	 * flagged as decorative. Mirrors the 'missing' filter in
	 * ajax_list_alts() but skips item loading — just returns the count
	 * for the Audit dashboard's link card.
	 *
	 * Note: this is intentionally an SQL-pass count (WP_Query found_posts
	 * with no_found_rows=false, fields=ids), not a cached value. The
	 * audit card calls it on dashboard refresh; if real-world load
	 * justifies it later, wrap in a short-lived transient bumped by the
	 * existing AuditRunner::mark_all_stale() hook surface.
	 */
	public function missing_count(): int {
		$query = new \WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => 1,    // we only need found_posts; load nothing
			'fields'         => 'ids',
			'no_found_rows'  => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the entire feature is identifying images by alt-attribute state; meta_query is the only path.
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'relation' => 'OR',
					array(
						'key'     => self::META_KEY_ALT,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::META_KEY_ALT,
						'value'   => '',
						'compare' => '=',
					),
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => self::META_KEY_DECORATIVE,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::META_KEY_DECORATIVE,
						'value'   => '1',
						'compare' => '!=',
					),
				),
			),
		) );

		return (int) $query->found_posts;
	}

	/**
	 * Common gate: nonce + manage_options capability. Delegates to
	 * {@see Security::authorize_ajax()} so the entire plugin shares one
	 * security boundary implementation.
	 */
	private function authorize(): void {
		Security::authorize_ajax();
	}

	// ====================================================================
	// AJAX: list attachments with current alt + decorative state
	// ====================================================================

	/**
	 * POST params:
	 *   filter (string)  — one of VALID_FILTERS; defaults to 'all'
	 *   page (int)       — 1-based page number
	 *   per_page (int)   — rows per page; clamped to [1, MAX_PAGE_SIZE]
	 *
	 * Response payload:
	 *   items        — array of attachment summaries (see build_item)
	 *   page         — echoed page number
	 *   per_page     — actual page size used
	 *   total        — total attachments matching the filter
	 *   total_pages  — total page count
	 *   filter       — echoed filter mode
	 */
	public function ajax_list_alts(): void {
		$this->authorize();

		$filter   = Request::post_key( 'filter', 'all' );
		$page     = max( 1, Request::post_int( 'page', 1 ) );
		$per_page = max( 1, min( self::MAX_PAGE_SIZE, Request::post_int( 'per_page', self::PAGE_SIZE ) ) );

		if ( ! in_array( $filter, self::VALID_FILTERS, true ) ) {
			$filter = 'all';
		}

		$result = $this->query_attachments( $filter, $page, $per_page );
		wp_send_json_success( $result );
	}

	/**
	 * POST params:
	 *   id (int)              — attachment ID
	 *   alt (string)          — new alt text (will be sanitized)
	 *   decorative (0|1)      — whether to set/clear the decorative flag
	 *
	 * Response payload mirrors a single build_item() row so the JS can replace
	 * its in-place state without re-fetching the whole page.
	 */
	public function ajax_save_alt(): void {
		$this->authorize();

		$id         = Request::post_int( 'id' );
		$alt        = Request::post_string( 'alt' );
		$decorative = Request::post_bool( 'decorative' );

		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			wp_send_json_error( __( 'Invalid attachment.', 'cf-media-manager' ), 400 );
		}

		$mime = (string) get_post_mime_type( $id );
		if ( ! preg_match( '#^image/#', $mime ) ) {
			wp_send_json_error( __( 'Not an image attachment.', 'cf-media-manager' ), 400 );
		}

		update_post_meta( $id, self::META_KEY_ALT, $alt );

		if ( $decorative ) {
			update_post_meta( $id, self::META_KEY_DECORATIVE, '1' );
		} else {
			delete_post_meta( $id, self::META_KEY_DECORATIVE );
		}

		wp_send_json_success( $this->build_item( $id ) );
	}

	/**
	 * POST params (one row per attachment id the user edited on the current
	 * page — the JS only submits dirty rows):
	 *   ids[]              — attachment IDs to write
	 *   alt[<id>]          — new alt text for that id (sanitized per element)
	 *   decorative[]       — the subset of ids flagged decorative
	 *
	 * Bulk counterpart to {@see ajax_save_alt()}, powering the "Save all
	 * changes" button. Writes every valid image attachment in one request
	 * and returns a refreshed build_item() row per saved id so the JS can
	 * swap state in place without re-fetching the page. Non-image / invalid
	 * ids are skipped (counted in `skipped`) rather than failing the batch.
	 *
	 * Response payload:
	 *   items   — refreshed build_item() rows for every saved id
	 *   saved   — count of ids written
	 *   skipped — count of ids rejected (missing, non-attachment, non-image)
	 */
	public function ajax_save_alt_bulk(): void {
		$this->authorize();

		$ids  = Request::post_int_array( 'ids' );
		$alts = Request::post_string_map( 'alt' );
		// `decorative[]` carries only the ids the user checked; everything
		// else is implicitly cleared.
		$decorative_ids = array_fill_keys( Request::post_int_array( 'decorative' ), true );

		$items   = array();
		$saved   = 0;
		$skipped = 0;

		foreach ( $ids as $id ) {
			if ( 'attachment' !== get_post_type( $id ) ) {
				$skipped++;
				continue;
			}
			$mime = (string) get_post_mime_type( $id );
			if ( ! preg_match( '#^image/#', $mime ) ) {
				$skipped++;
				continue;
			}

			update_post_meta( $id, self::META_KEY_ALT, $alts[ $id ] ?? '' );

			if ( isset( $decorative_ids[ $id ] ) ) {
				update_post_meta( $id, self::META_KEY_DECORATIVE, '1' );
			} else {
				delete_post_meta( $id, self::META_KEY_DECORATIVE );
			}

			$items[] = $this->build_item( $id );
			$saved++;
		}

		wp_send_json_success( array(
			'items'   => $items,
			'saved'   => $saved,
			'skipped' => $skipped,
		) );
	}
	// Request::post_* helpers carry their own phpcs:ignore comments so
	// per-endpoint nonce-verification pragmas are no longer needed.

	// ====================================================================
	// Internal: query + item construction
	// ====================================================================

	/**
	 * Build the WP_Query for the requested filter mode and return a paginated
	 * result envelope. Image attachments only — non-image attachments are not
	 * scoped into this audit.
	 */
	private function query_attachments( string $filter, int $page, int $per_page ): array {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => false,
		);

		// "In-use" filters intersect against the InUseScanner result.
		$restrict_in_use = in_array( $filter, array( 'in_use', 'in_use_missing' ), true );
		if ( $restrict_in_use ) {
			$scan       = $this->in_use->get();
			$in_use_ids = isset( $scan['ids'] ) ? array_map( 'intval', (array) $scan['ids'] ) : array();

			if ( empty( $in_use_ids ) ) {
				return $this->empty_result( $filter, $page, $per_page );
			}
			$args['post__in'] = $in_use_ids;
		}

		// "Missing" filter: alt is empty/absent AND decorative is not set.
		$restrict_missing = in_array( $filter, array( 'missing', 'in_use_missing' ), true );
		if ( $restrict_missing ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- meta_query is the only path to filter by alt-state.
			$args['meta_query'] = array(
				'relation' => 'AND',
				array(
					'relation' => 'OR',
					array(
						'key'     => self::META_KEY_ALT,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::META_KEY_ALT,
						'value'   => '',
						'compare' => '=',
					),
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => self::META_KEY_DECORATIVE,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::META_KEY_DECORATIVE,
						'value'   => '1',
						'compare' => '!=',
					),
				),
			);
		}

		$query = new \WP_Query( $args );

		$items = array();
		foreach ( $query->posts as $id ) {
			$items[] = $this->build_item( (int) $id );
		}

		return array(
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'filter'      => $filter,
		);
	}

	private function empty_result( string $filter, int $page, int $per_page ): array {
		return array(
			'items'       => array(),
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => 0,
			'total_pages' => 0,
			'filter'      => $filter,
		);
	}

	/**
	 * Per-row payload sent to the JS table renderer. Kept stable across the
	 * list and save endpoints so the JS can swap a row in-place on save without
	 * re-fetching the page.
	 */
	private function build_item( int $id ): array {
		$alt        = (string) get_post_meta( $id, self::META_KEY_ALT, true );
		$decorative = (bool) get_post_meta( $id, self::META_KEY_DECORATIVE, true );
		$thumb_src  = wp_get_attachment_image_src( $id, array( 60, 60 ) );
		// Full-size source for the "view full image" popup — the cropped
		// 60×60 thumb is useless for actually reading what an image depicts
		// while writing alt text.
		$full_src   = wp_get_attachment_image_src( $id, 'full' );
		$file       = (string) get_post_meta( $id, '_wp_attached_file', true );
		$filename   = '' !== $file ? basename( $file ) : '';
		$edit_url   = get_edit_post_link( $id, 'raw' );

		return array(
			'id'         => $id,
			'filename'   => $filename,
			'thumb'      => is_array( $thumb_src ) && isset( $thumb_src[0] ) ? $thumb_src[0] : '',
			'full'       => is_array( $full_src ) && isset( $full_src[0] ) ? $full_src[0] : '',
			'alt'        => $alt,
			'decorative' => $decorative,
			// "Missing" is server-computed so the JS doesn't have to encode the
			// same business rule twice. Empty alt without a decorative flag is
			// the only state we badge as missing.
			'missing'    => '' === $alt && ! $decorative,
			'edit_url'   => $edit_url ? $edit_url : '',
		);
	}
}
