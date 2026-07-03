<?php

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

/**
 * Discovers attachment IDs that are actually referenced on the front end.
 *
 * Many sites accumulate stale media (uploads from removed pages, abandoned
 * imports, prior themes) which makes "Convert All" wasteful. This scanner
 * builds the set of JPEG/PNG attachment IDs that are still in use, so the
 * bulk converter can target only what visitors will actually see.
 *
 * Sources scanned (in order):
 *   1. post_content of every published post in user-public post types
 *      (WPBakery shortcode attributes `image="N"` / `images="1,2,3"` are
 *      extracted here too when WPBakery is detected)
 *   2. post_content of page-builder template/library post types (Divi,
 *      Elementor, Beaver Builder, Bricks) — only when the builder is active
 *   3. _thumbnail_id postmeta (featured images)
 *   4. Builder-specific postmeta blobs (Divi shortcodes live in
 *      post_content; Elementor/_elementor_data, Beaver/_fl_builder_data,
 *      Bricks/_bricks_page_content_2 + header/footer variants, and
 *      WPBakery/_vc_post_settings are JSON/serialized blobs holding URLs
 *      and IDs)
 *   5. Site identity: custom-logo theme_mod, site_icon option
 *   6. Divi theme-options blob (`et_divi` option)
 *   7. Reusable blocks (wp_block post type — images inside render on every
 *      page that embeds the block)
 *   8. Block theme templates and template parts (wp_template,
 *      wp_template_part — site-wide layout images on block-theme sites)
 *   9. Widget areas (text, image, block, gallery, and custom HTML widgets)
 *  10. ACF fields — gated on ACF being active. Covers bare-integer image
 *      fields (postmeta values that are attachment IDs on keys without the
 *      underscore-prefix convention), serialized ID arrays (gallery /
 *      repeater fields), and URL-stored fields (image "Image URL" return
 *      format, file/link fields)
 *  11. WooCommerce product gallery images (_product_image_gallery) — gated
 *      on WooCommerce being active
 *
 * URL→ID resolution uses a single indexed query over `_wp_attached_file`
 * postmeta. Size suffixes (`-300x200`), WP's `-scaled` auto-scale suffix, and
 * `-e{timestamp}` "Edit Image" suffixes are stripped so thumbnail, scaled, and
 * edited-image URLs resolve back to the parent attachment.
 *
 * Result is cached in a transient until {@see invalidate_cache()} is
 * called (on post save / attachment update) or the user clicks Rescan.
 */
final class InUseScanner {

	/**
	 * Cache-key prefix. The actual transient key appends an md5 of the active
	 * builder set (see {@see builder_fingerprint()}). Activating or
	 * deactivating a builder produces a fresh key so the old cached scan —
	 * computed under different assumptions about which `post_content` hides
	 * builder JSON vs. user prose — is bypassed automatically without an
	 * explicit invalidation race.
	 */
	const TRANSIENT_KEY = 'cf_media_manager_in_use_scan';
	const TRANSIENT_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Post statuses whose content is treated as capable of referencing a live
	 * image. Restricting the scan to `publish` alone (the prior behavior)
	 * classed images used ONLY on scheduled (`future`), private, pending, or
	 * draft content as unused — and the Unused Attachments report turns each
	 * miss into a "delete me" suggestion for an image that is, or is about to
	 * be, live. Password-protected posts have `post_status = publish`, so they
	 * are already covered by including `publish`.
	 */
	const SCANNED_POST_STATUSES = array( 'publish', 'future', 'private', 'pending', 'draft' );

	/**
	 * Action Scheduler / WP-Cron hook that runs a background in-use scan so the
	 * whole-library scan (heavy on 50k-300k-attachment sites) happens off the
	 * request that triggered it, mirroring the converter queue's model.
	 */
	const CRON_HOOK  = 'cf_media_manager_in_use_scan_bg';
	const AS_GROUP   = 'cf-media-manager';

	/** Coalescing guard so rapid invalidations schedule at most one background scan. */
	const WARM_PENDING_TRANSIENT = 'cf_media_manager_in_use_warm_pending';

	/**
	 * How long after the last audit-report read we keep proactively warming the
	 * cache in the background. Bounds background work to sites actively using
	 * the audit reports.
	 */
	const WARM_WANTED_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * Post types whose post_content holds the *builder's* layout data
	 * rather than user-authored prose. Scanned only when the matching
	 * builder is detected as active.
	 */
	const BUILDER_POST_TYPES = array(
		// Divi Library + Theme Builder layouts.
		'divi'      => array(
			'et_pb_layout',
			'et_template',
			'et_header_layout',
			'et_body_layout',
			'et_footer_layout',
			'et_theme_builder_layout',
		),
		// Elementor templates, kits, popups, landing pages.
		'elementor' => array(
			'elementor_library',
			'e-landing-page',
		),
		// Beaver Builder saved layouts + Theme Builder.
		'beaver'    => array(
			'fl-builder-template',
			'fl-theme-layout',
		),
		// Bricks templates (headers, footers, popups, archives, single
		// templates, sections). Page-level Bricks content lives on regular
		// post types and is picked up by the postmeta scan below.
		'bricks'    => array(
			'bricks_template',
		),
	);

	/**
	 * Builder postmeta keys whose values hold image URLs/IDs as a JSON
	 * or serialized blob. We treat them as opaque text — regex extracts
	 * upload URLs and `wp-image-{id}` refs without deserializing the
	 * builder's data structure.
	 */
	const BUILDER_META_KEYS = array(
		'elementor' => array(
			'_elementor_data',
			'_elementor_page_settings',
		),
		'beaver'    => array(
			'_fl_builder_data',
			'_fl_builder_draft',
		),
		// Bricks 1.4+ stores rendered page / header / footer content as a
		// serialized PHP array keyed by these meta names. Image elements
		// carry both numeric `id` fields and full uploads URLs, so the
		// generic URL extractor covers them.
		'bricks'    => array(
			'_bricks_page_content_2',
			'_bricks_header_content_2',
			'_bricks_footer_content_2',
		),
		// WPBakery stores per-post Page Settings (custom CSS, background
		// images set at the page level) here. Most WPBakery image refs
		// live inline in post_content as shortcode attributes — those are
		// picked up by extract_wpbakery_shortcode_ids() during the
		// post_content scan, gated on WPBakery being active.
		'wpbakery'  => array(
			'_vc_post_settings',
		),
	);

	private Paths $paths;

	/** filename-relative-path => attachment_id (built lazily on first scan). */
	private ?array $filename_map = null;

	/** id => true for every attachment in the filename map (O(1) membership test). */
	private ?array $valid_id_set = null;

	/**
	 * True for the duration of a scan when WPBakery is detected. Gates the
	 * WPBakery shortcode-attribute extractor so we don't false-positive
	 * `image="123"` patterns on sites that aren't running WPBakery.
	 */
	private bool $wpbakery_active = false;

	public function __construct( Paths $paths ) {
		$this->paths = $paths;
	}

	/**
	 * Active hooks: invalidate the cached result whenever a post or
	 * attachment is saved/deleted, since either may add or remove image
	 * references.
	 */
	public function register_hooks(): void {
		// save_post fires for revisions and autosaves too; route those
		// through maybe_invalidate_on_save so we only invalidate when
		// the live, published row actually changes.
		add_action( 'save_post', array( $this, 'maybe_invalidate_on_save' ), 10, 2 );

		$invalidate = array( $this, 'invalidate_cache' );
		add_action( 'deleted_post', $invalidate );
		add_action( 'add_attachment', $invalidate );
		add_action( 'delete_attachment', $invalidate );
		add_action( 'switch_theme', $invalidate );
		add_action( 'customize_save_after', $invalidate );

		// Activating or deactivating a plugin can change which builders the
		// scanner trusts (Bricks templates, ACF fields, WooCommerce
		// galleries, etc.). The builder-fingerprint cache key handles new
		// scans naturally, but stale entries under the OLD fingerprint
		// would still occupy DB rows for the 12h TTL — clear them all on
		// any plugin event so the options table doesn't bloat with dead
		// transients.
		$flush_all = array( $this, 'invalidate_all_fingerprinted_caches' );
		add_action( 'activated_plugin', $flush_all );
		add_action( 'deactivated_plugin', $flush_all );

		// Background scan driver — the same cron / Action Scheduler model the
		// converter queue uses. A cache invalidation (below) schedules this so
		// the heavy whole-library scan runs off the request that triggered it,
		// keeping subsequent audit reads warm instead of blocking.
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_scan' ) );
	}

	/**
	 * True when Action Scheduler is available (bundled with WooCommerce and
	 * many other plugins). Mirrors Queue::action_scheduler_available().
	 */
	public static function action_scheduler_available(): bool {
		return function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_unschedule_all_actions' );
	}

	/**
	 * Drop the in-use cache on save_post — but skip the noise.
	 * Autosaves, revisions, and trash/auto-draft states don't change
	 * what visitors actually see, so we'd just be paying for a rescan
	 * on the next admin load for nothing.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object (we only need post_status).
	 */
	public function maybe_invalidate_on_save( $post_id, $post = null ): void {
		if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
			return;
		}
		$status = is_object( $post ) && isset( $post->post_status ) ? $post->post_status : '';
		if ( 'auto-draft' === $status ) {
			return;
		}
		$this->invalidate_cache();
	}

	public function invalidate_cache(): void {
		// Delete only the cache entry that matches the CURRENT builder
		// fingerprint. Old-fingerprint entries (from before a plugin
		// activation) auto-expire via TTL and are bulk-deleted on the
		// activated_plugin / deactivated_plugin hooks.
		delete_transient( $this->cache_key() );

		// If the audit reports have been used recently, warm the cache in the
		// background so the next report read doesn't pay for a full rescan
		// synchronously. Sites that never open the reports never trigger this.
		$this->maybe_schedule_warm();
	}

	/**
	 * Schedule a background rescan when a report read has occurred within
	 * WARM_WANTED_TTL. Coalesced so bursts of invalidations (bulk edits,
	 * imports) don't pile up scans.
	 */
	private function maybe_schedule_warm(): void {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}
		$wanted = (int) get_option( Options::IN_USE_WARM_WANTED, 0 );
		if ( $wanted <= 0 || ( time() - $wanted ) > self::WARM_WANTED_TTL ) {
			return;
		}
		$this->schedule_scan();
	}

	/**
	 * Queue a background in-use scan via Action Scheduler (preferred) or
	 * WP-Cron. Idempotent within the coalescing window — a pending scan short-
	 * circuits repeat calls so a burst of invalidations schedules one scan.
	 */
	public function schedule_scan(): void {
		if ( get_transient( self::WARM_PENDING_TRANSIENT ) ) {
			return;
		}
		set_transient( self::WARM_PENDING_TRANSIENT, 1, HOUR_IN_SECONDS );

		if ( self::action_scheduler_available() ) {
			as_schedule_single_action( time() + 30, self::CRON_HOOK, array(), self::AS_GROUP );
			return;
		}
		if ( function_exists( 'wp_schedule_single_event' )
			&& ( ! function_exists( 'wp_next_scheduled' ) || ! wp_next_scheduled( self::CRON_HOOK ) )
		) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}
	}

	/**
	 * Cron/Action Scheduler handler: run a fresh whole-library scan and cache
	 * it under the current builder fingerprint. Clears the coalescing guard so
	 * a later invalidation can schedule the next warm.
	 */
	public function run_scheduled_scan(): void {
		delete_transient( self::WARM_PENDING_TRANSIENT );
		$this->get( true );
	}

	/**
	 * Cache-aware entry point for the audit reports. Records that a report read
	 * happened (so cache invalidations start warming the cache in the
	 * background) and returns the in-use scan result. The synchronous scan on a
	 * cold cache is intentional: the Unused Attachments report must never flag
	 * against an incomplete in-use set, so it always reads a COMPLETE result.
	 *
	 * @param bool $force_refresh Bypass the cache and rescan.
	 * @return array Scan result (see {@see get()}).
	 */
	public function get_for_report( bool $force_refresh = false ): array {
		if ( function_exists( 'update_option' ) ) {
			update_option( Options::IN_USE_WARM_WANTED, time(), false );
		}
		return $this->get( $force_refresh );
	}

	/**
	 * Wildcard-delete every cached scan we've ever written, regardless of
	 * which builder fingerprint produced it. Hooked to plugin activation
	 * events because that's when the builder set is most likely to
	 * change (and the old cached scan most likely to be wrong).
	 *
	 * The `activated_plugin` / `deactivated_plugin` hooks fire on EVERY
	 * plugin event, not just builder-related ones. To avoid running the
	 * DELETE on every plugin install / uninstall (most don't change the
	 * scanner's builder set at all), we compare the CURRENT builder
	 * fingerprint against the last-stored one. Same fingerprint →
	 * no-op. Different fingerprint → run the wildcard delete and
	 * update the stored fp.
	 *
	 * Implemented as a direct `$wpdb->query` because there is no
	 * `delete_transients_by_pattern()` in WP core, and iterating
	 * `delete_transient()` would require enumerating fingerprints we
	 * haven't recorded anywhere.
	 */
	public function invalidate_all_fingerprinted_caches(): void {
		// Short-circuit: builder set hasn't changed, the old cached scan
		// is still valid for the current request shape, no DELETE needed.
		$current_fp = $this->builder_fingerprint();
		$last_fp    = function_exists( 'get_option' ) ? (string) get_option( Options::LAST_BUILDER_FP, '' ) : '';
		if ( $current_fp === $last_fp && '' !== $last_fp ) {
			return;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			// Without $wpdb we can't run the wildcard. Still update the
			// stored fp so the next call's short-circuit can fire if
			// $wpdb comes back online.
			if ( function_exists( 'update_option' ) ) {
				update_option( Options::LAST_BUILDER_FP, $current_fp, true );
			}
			return;
		}
		$value_like   = $wpdb->esc_like( '_transient_' . self::TRANSIENT_KEY ) . '%';
		$timeout_like = $wpdb->esc_like( '_transient_timeout_' . self::TRANSIENT_KEY ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$value_like,
				$timeout_like
			)
		);

		// Update the stored fp AFTER the DELETE so a failure mid-delete
		// leaves us with the old fp + a guaranteed re-run on next hook.
		if ( function_exists( 'update_option' ) ) {
			update_option( Options::LAST_BUILDER_FP, $current_fp, true );
		}
	}

	/**
	 * Per-builder-set cache key. Activating a builder (or its plugin) makes
	 * the scanner trust additional builder-content post types and additional
	 * postmeta keys; the cached result before the activation no longer
	 * matches what a fresh scan would produce. By baking an md5 of the
	 * detected builder set into the key, the new builder set automatically
	 * misses the cache and the old entry retires via TTL.
	 */
	private function cache_key(): string {
		return self::TRANSIENT_KEY . '_' . $this->builder_fingerprint();
	}

	/**
	 * Stable hash over the active-builder detection result. The boolean map
	 * is `wp_json_encode`d for deterministic ordering (PHP's array order is
	 * insertion-stable, but the json+md5 step also collapses any
	 * environmental jitter between requests).
	 */
	private function builder_fingerprint(): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			$encoded = wp_json_encode( $this->detect_builders() );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- fallback for unit tests / non-WP environments where wp_json_encode is unavailable.
			$encoded = json_encode( $this->detect_builders() );
		}
		return substr( md5( (string) $encoded ), 0, 12 );
	}

	/**
	 * Return the cached scan if available, otherwise run a fresh scan
	 * and cache it. Pass true to bypass the cache and rescan.
	 *
	 * Result shape:
	 *   [
	 *     'ids'        => int[] (sorted, unique),
	 *     'scanned_at' => int   (unix ts),
	 *     'sources'    => [ 'post_content' => N, 'divi' => N, ... ],
	 *     'builders'   => [ 'divi' => bool, 'elementor' => bool, 'beaver' => bool ],
	 *   ]
	 */
	public function get( bool $force_refresh = false ): array {
		$key = $this->cache_key();
		if ( ! $force_refresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) && isset( $cached['ids'], $cached['scanned_at'] ) ) {
				return $cached;
			}
		}

		$result = $this->scan();
		set_transient( $key, $result, self::TRANSIENT_TTL );
		return $result;
	}

	/**
	 * Run a fresh scan unconditionally. Public so the AJAX layer can
	 * force a rescan independently of the cache layer above.
	 */
	public function scan(): array {
		$this->filename_map    = $this->build_filename_map();
		$this->valid_id_set    = array_fill_keys( array_values( $this->filename_map ), true );
		$builders              = $this->detect_builders();
		$this->wpbakery_active = (bool) ( $builders['wpbakery'] ?? false );

		$by_source = array(
			'post_content'    => array(),
			'featured'        => array(),
			'site_logo'       => array(),
			'site_icon'       => array(),
			'divi'            => array(),
			'elementor'       => array(),
			'beaver'          => array(),
			'bricks'          => array(),
			'wpbakery'        => array(),
			'reusable_blocks' => array(),
			'block_templates' => array(),
			'widgets'         => array(),
			'acf'             => array(),
			'woocommerce'     => array(),
		);

		// 1. post_content of user-public post types.
		$by_source['post_content'] = $this->scan_user_post_content();

		// 2. post_content of builder-owned template post types.
		foreach ( $builders as $builder => $active ) {
			if ( ! $active ) {
				continue;
			}
			$types = self::BUILDER_POST_TYPES[ $builder ] ?? array();
			if ( array() !== $types ) {
				$ids                   = $this->scan_post_content_for_post_types( $types );
				$by_source[ $builder ] = $this->merge_unique( $by_source[ $builder ], $ids );
			}
		}

		// 3. Featured images (works for all post types we care about).
		$by_source['featured'] = $this->scan_featured_images();

		// 4. Builder postmeta blobs. Divi shortcodes already live in post_content
		// and are covered by the scans above; the rest store their layouts as
		// JSON/serialized blobs in postmeta.
		foreach ( array( 'elementor', 'beaver', 'bricks', 'wpbakery' ) as $builder ) {
			if ( ! ( $builders[ $builder ] ?? false ) ) {
				continue;
			}
			$keys = self::BUILDER_META_KEYS[ $builder ] ?? array();
			foreach ( $keys as $meta_key ) {
				$ids                   = $this->scan_postmeta_blob( $meta_key );
				$by_source[ $builder ] = $this->merge_unique( $by_source[ $builder ], $ids );
			}
		}

		// 5. Site identity.
		$logo_id = (int) get_theme_mod( 'custom_logo', 0 );
		if ( $logo_id > 0 && $this->is_target_attachment( $logo_id ) ) {
			$by_source['site_logo'][] = $logo_id;
		}
		$icon_id = (int) get_option( 'site_icon', 0 );
		if ( $icon_id > 0 && $this->is_target_attachment( $icon_id ) ) {
			$by_source['site_icon'][] = $icon_id;
		}

		// 6. Divi theme-options blob.
		if ( $builders['divi'] ?? false ) {
			$et_divi = get_option( 'et_divi' );
			if ( $et_divi ) {
				$blob              = is_string( $et_divi ) ? $et_divi : maybe_serialize( $et_divi );
				$ids               = $this->extract_attachment_ids_from_text( (string) $blob );
				$by_source['divi'] = $this->merge_unique( $by_source['divi'], $ids );
			}
		}

		// 7. Reusable blocks — wp_block post type is excluded from public post
		// types but its content is rendered on every page that embeds the block.
		$by_source['reusable_blocks'] = $this->scan_post_content_for_post_types( array( 'wp_block' ) );

		// 8. Block theme templates and template parts — excluded from public
		// types but contain site-wide layout images on block-theme sites.
		$by_source['block_templates'] = $this->scan_post_content_for_post_types(
			array( 'wp_template', 'wp_template_part' )
		);

		// 9. Widget areas.
		$by_source['widgets'] = $this->scan_widget_areas();

		// 10. ACF fields: bare attachment IDs (image field, default return),
		// serialized ID arrays (gallery / repeater), and URL-stored values
		// (image field with URL return, file/link fields).
		if ( $builders['acf'] ?? false ) {
			$by_source['acf'] = $this->merge_unique(
				$this->scan_acf_postmeta(),
				$this->scan_acf_galleries_and_urls()
			);
		}

		// 11. WooCommerce product gallery images.
		if ( $builders['woocommerce'] ?? false ) {
			$by_source['woocommerce'] = $this->scan_woocommerce_galleries();
		}

		// Union everything.
		$all = array();
		foreach ( $by_source as $ids ) {
			foreach ( $ids as $id ) {
				$all[ $id ] = true;
			}
		}
		$all_ids = array_map( 'intval', array_keys( $all ) );
		sort( $all_ids );

		// Per-source counts (for the UI breakdown).
		$source_counts = array();
		foreach ( $by_source as $name => $ids ) {
			$source_counts[ $name ] = count( $ids );
		}

		// Release the (potentially large) filename map — only the final IDs matter from here.
		$this->filename_map = null;
		$this->valid_id_set = null;

		return array(
			'ids'        => $all_ids,
			'scanned_at' => time(),
			'sources'    => $source_counts,
			'builders'   => $builders,
		);
	}

	// ====================================================================
	// Builder detection
	// ====================================================================

	private function detect_builders(): array {
		return array(
			'divi'        => $this->is_divi_active(),
			'elementor'   => $this->is_elementor_active(),
			'beaver'      => $this->is_beaver_active(),
			'bricks'      => $this->is_bricks_active(),
			'wpbakery'    => $this->is_wpbakery_active(),
			'acf'         => $this->is_acf_active(),
			'woocommerce' => $this->is_woocommerce_active(),
		);
	}

	private function is_acf_active(): bool {
		return class_exists( 'ACF', false )
			|| function_exists( 'acf_register_block_type' )
			|| defined( 'ACF_VERSION' );
	}

	private function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce', false )
			|| defined( 'WC_VERSION' )
			|| function_exists( 'wc' );
	}

	private function is_divi_active(): bool {
		// Both the Divi theme and the Divi Builder plugin expose this.
		if ( function_exists( 'et_get_option' ) ) {
			return true;
		}
		$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
		if ( $theme ) {
			$name = strtolower( (string) $theme->get( 'Name' ) );
			if ( strpos( $name, 'divi' ) !== false || strpos( $name, 'extra' ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return bool True when Elementor (the page builder) is active.
	 */
	private function is_elementor_active(): bool {
		return did_action( 'elementor/loaded' ) > 0
			|| defined( 'ELEMENTOR_VERSION' )
			|| class_exists( '\\Elementor\\Plugin', false );
	}

	/**
	 * @return bool True when Beaver Builder is active.
	 */
	private function is_beaver_active(): bool {
		return class_exists( 'FLBuilder', false )
			|| class_exists( 'FLBuilderModel', false )
			|| defined( 'FL_BUILDER_VERSION' );
	}

	/**
	 * @return bool True when Bricks (theme or builder plugin) is active.
	 */
	private function is_bricks_active(): bool {
		if ( defined( 'BRICKS_VERSION' ) || defined( 'BRICKS_DB_TEMPLATE_SLUG' ) ) {
			return true;
		}
		if ( class_exists( '\\Bricks\\Plugin', false ) || function_exists( 'bricks_is_builder' ) ) {
			return true;
		}
		$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
		if ( $theme ) {
			$name = strtolower( (string) $theme->get( 'Name' ) );
			if ( 'bricks' === $name ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return bool True when WPBakery Page Builder (Visual Composer) is active.
	 */
	private function is_wpbakery_active(): bool {
		return defined( 'WPB_VC_VERSION' )
			|| class_exists( 'Vc_Manager', false )
			|| function_exists( 'vc_map' );
	}

	// ====================================================================
	// Filename map (URL → attachment_id)
	// ====================================================================

	/**
	 * Build a `relative-path => attachment_id` map from `_wp_attached_file`.
	 * Path stored there is upload-relative, e.g. `2024/01/photo.jpg`. URLs
	 * extracted from content have size suffixes (`-300x200`) that we strip
	 * before lookup; the parent file path is what `_wp_attached_file` holds.
	 */
	private function build_filename_map(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'",
			ARRAY_A
		);

		$map = array();
		if ( ! $rows ) {
			return $map;
		}
		foreach ( $rows as $row ) {
			$rel = ltrim( (string) $row['meta_value'], '/' );
			if ( '' === $rel ) {
				continue;
			}
			// Only index image attachments we'd actually convert.
			if ( ! preg_match( '/\.(jpe?g|png)$/i', $rel ) ) {
				continue;
			}
			$id          = (int) $row['post_id'];
			$map[ $rel ] = $id;

			// For a `-scaled` attachment `_wp_attached_file` holds the scaled
			// path (photo-scaled.jpg); the full-resolution original sits at the
			// parent path (photo.jpg) but has no postmeta row of its own. Index
			// the parent form too so content referencing the ORIGINAL URL still
			// resolves to this attachment. Never clobber a distinct attachment
			// that legitimately owns the parent path.
			$parent = self::strip_edit_suffixes( $rel );
			if ( $parent !== $rel && ! isset( $map[ $parent ] ) ) {
				$map[ $parent ] = $id;
			}
		}
		return $map;
	}

	/**
	 * Extract upload-relative path from any URL, root-relative path, or
	 * CDN-rewritten URL that contains the `/wp-content/uploads/` anchor.
	 * Strips query string and size suffix. Returns null when the URL
	 * doesn't look like an uploads path.
	 *
	 * Pure function — exposed publicly so tests can exercise it without
	 * standing up a fake $wpdb.
	 *
	 * @param string $url URL or path that may contain `/wp-content/uploads/`.
	 * @return string|null Upload-relative path, or null on no-match.
	 */
	public static function url_to_relative_path( string $url ): ?string {
		$pos = strpos( $url, '/wp-content/uploads/' );
		if ( false === $pos ) {
			return null;
		}
		$rel = substr( $url, $pos + strlen( '/wp-content/uploads/' ) );
		// Strip query/fragment from the trailing portion.
		$rel = preg_replace( '/[\?#].*$/', '', $rel );
		if ( '' === $rel ) {
			return null;
		}
		// Strip size suffix: photo-300x200.jpg -> photo.jpg.
		$rel = preg_replace( '/-\d+x\d+(\.(jpe?g|png))$/i', '$1', (string) $rel );
		// Strip WP's -scaled / -e{timestamp} suffixes so a raw URL pointing at
		// the auto-scaled copy or an "Edit Image" derivative resolves back to
		// the parent attachment. See strip_edit_suffixes() for why the parent
		// form is what the filename map indexes.
		$rel = self::strip_edit_suffixes( (string) $rel );
		return ltrim( (string) $rel, '/' );
	}

	/**
	 * Strip WordPress's `-scaled` and `-e{timestamp}` filename suffixes,
	 * returning the parent (base) upload-relative path.
	 *
	 *   - `-scaled` is appended when an upload wider than the big-image
	 *     threshold (2560px) is auto-scaled; the full-resolution original is
	 *     kept alongside as `original_image`.
	 *   - `-e{timestamp}` is appended to the copy WP writes when an admin uses
	 *     "Edit Image" (crop/rotate/scale). The timestamp is a unix time, so we
	 *     require at least nine digits to avoid mangling legitimate filenames
	 *     that merely end in `-e5.jpg`.
	 *
	 * {@see build_filename_map()} indexes BOTH the stored path and this parent
	 * form, so a reference to either the derivative or the base URL resolves to
	 * the same attachment. Pure string op — static + used by tests.
	 *
	 * @param string $rel Upload-relative path (already size-suffix stripped).
	 * @return string Parent upload-relative path.
	 */
	public static function strip_edit_suffixes( string $rel ): string {
		$rel = preg_replace( '/-scaled(\.(?:jpe?g|png))$/i', '$1', $rel );
		$rel = preg_replace( '/-e\d{9,}(\.(?:jpe?g|png))$/i', '$1', (string) $rel );
		return (string) $rel;
	}

	/**
	 * Pure extractor: pull every `wp-image-{id}` ref and every uploads
	 * URL out of $text. Returns:
	 *   [ 'ids' => int[], 'paths' => string[] ]
	 * where ids are direct numeric refs and paths are upload-relative
	 * (already stripped of size suffix and query). No filesystem or DB
	 * access. Static + public so tests can exercise it directly.
	 *
	 * @param string $text Arbitrary HTML/JSON/serialized text.
	 * @return array{ids:int[],paths:string[]}
	 */
	public static function extract_refs_from_text( string $text ): array {
		if ( '' === $text ) {
			return array(
				'ids'   => array(),
				'paths' => array(),
			);
		}

		// Elementor stores URLs as JSON, which escapes forward slashes
		// (`\/wp-content\/uploads\/...`). Normalize the escape so the
		// path regex matches both raw and JSON-encoded text without a
		// double-pattern. Safe for our purposes — we never need the
		// original encoded form again.
		$normalized = str_replace( '\\/', '/', $text );

		$ids = array();
		if ( preg_match_all( '/wp-image-(\d+)/', $normalized, $m ) ) {
			foreach ( $m[1] as $id_str ) {
				$id = (int) $id_str;
				if ( $id > 0 ) {
					$ids[ $id ] = true;
				}
			}
		}

		$paths = array();
		if ( preg_match_all( '#/wp-content/uploads/[^\s"\'<>\\\\)]+?\.(?:jpe?g|png)(?:\?[^\s"\'<>\\\\)]*)?#i', $normalized, $m ) ) {
			foreach ( array_unique( $m[0] ) as $url ) {
				$rel = self::url_to_relative_path( $url );
				if ( null !== $rel ) {
					$paths[ $rel ] = true;
				}
			}
		}

		return array(
			'ids'   => array_map( 'intval', array_keys( $ids ) ),
			'paths' => array_keys( $paths ),
		);
	}

	// ====================================================================
	// Core extractor — runs on any text blob
	// ====================================================================

	/**
	 * Find every JPEG/PNG attachment ID referenced in $text via:
	 *   - `wp-image-{id}` class refs (Gutenberg, classic editor)
	 *   - upload-URL substrings → resolved through the filename map
	 *   - WPBakery shortcode attributes (`image="123"`, `images="1,2,3"`)
	 *     — only when WPBakery is detected as active for this scan
	 *
	 * @param string $text Text to scan.
	 * @return int[]
	 */
	private function extract_attachment_ids_from_text( string $text ): array {
		$refs = self::extract_refs_from_text( $text );

		$ids = array();
		foreach ( $refs['ids'] as $id ) {
			$ids[ $id ] = true;
		}
		foreach ( $refs['paths'] as $rel ) {
			$attachment_id = $this->filename_map[ $rel ] ?? null;
			if ( $attachment_id ) {
				$ids[ $attachment_id ] = true;
			}
		}

		if ( $this->wpbakery_active ) {
			foreach ( self::extract_wpbakery_shortcode_ids( $text ) as $id ) {
				$ids[ $id ] = true;
			}
		}

		// The wp-image-N branch can technically point at non-image attachments;
		// the filename map (built only from JPEG/PNG attachments) is the source
		// of truth for what we'll actually convert.
		$final = array();
		foreach ( array_keys( $ids ) as $id ) {
			if ( $this->is_target_attachment( (int) $id ) ) {
				$final[] = (int) $id;
			}
		}
		return $final;
	}

	/**
	 * Pure extractor for WPBakery shortcode attachment IDs.
	 *
	 * WPBakery's image widgets store attachment IDs as bare numbers inside
	 * shortcode attributes — `[vc_single_image image="123"]`,
	 * `[vc_gallery images="1,2,3"]` — patterns the generic extractor doesn't
	 * catch. Kept conservative (anchored on `image=` / `images=`) so we don't
	 * sweep up unrelated attributes on non-WPBakery sites.
	 *
	 * Static + public so tests can exercise it without a class instance.
	 *
	 * @param string $text Arbitrary post_content / postmeta text.
	 * @return int[] Unique, positive integer attachment IDs.
	 */
	public static function extract_wpbakery_shortcode_ids( string $text ): array {
		if ( '' === $text ) {
			return array();
		}

		$ids = array();

		if ( preg_match_all( '/\bimage="(\d+)"/i', $text, $m ) ) {
			foreach ( $m[1] as $id_str ) {
				$id = (int) $id_str;
				if ( $id > 0 ) {
					$ids[ $id ] = true;
				}
			}
		}

		if ( preg_match_all( '/\bimages="([\d,\s]+)"/i', $text, $m ) ) {
			foreach ( $m[1] as $list ) {
				foreach ( preg_split( '/[,\s]+/', $list ) as $id_str ) {
					$id = (int) trim( (string) $id_str );
					if ( $id > 0 ) {
						$ids[ $id ] = true;
					}
				}
			}
		}

		return array_map( 'intval', array_keys( $ids ) );
	}

	/**
	 * True if $id is a JPEG/PNG attachment present in the filename map.
	 * Avoids loading the post object — the map covers everything we'd convert.
	 *
	 * @param int $id Attachment ID candidate.
	 * @return bool
	 */
	private function is_target_attachment( int $id ): bool {
		return $id > 0 && is_array( $this->valid_id_set ) && isset( $this->valid_id_set[ $id ] );
	}

	// ====================================================================
	// Source scanners
	// ====================================================================

	/**
	 * Scan post_content of every published user-public post type
	 * (post, page, and any other publicly_queryable CPT). We deliberately
	 * exclude attachment, revision, nav_menu_item, and builder-owned
	 * template types — those are handled by their own scanners.
	 */
	private function scan_user_post_content(): array {
		$post_types = $this->user_public_post_types();
		if ( array() === $post_types ) {
			return array();
		}
		return $this->scan_post_content_for_post_types( $post_types );
	}

	/**
	 * Streamed scan: pull post_content rows for $post_types in batches and
	 * extract IDs from each. Returns deduped IDs.
	 *
	 * @param string[] $post_types Post types to include.
	 * @return int[]
	 */
	private function scan_post_content_for_post_types( array $post_types ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || array() === $post_types ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$status_ph    = implode( ',', array_fill( 0, count( self::SCANNED_POST_STATUSES ), '%s' ) );
		$batch_size   = 200;
		$offset       = 0;
		$found        = array();

		// IN-clause placeholders are dynamic count, so the prepare() call
		// can't use a static format string. Values still flow through
		// prepare() — phpcs can't verify that, hence the ignores.
		while ( true ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$sql = $wpdb->prepare(
				"SELECT post_content FROM {$wpdb->posts}
				 WHERE post_status IN ($status_ph)
				   AND post_type IN ($placeholders)
				   AND post_content <> ''
				 ORDER BY ID
				 LIMIT %d OFFSET %d",
				array_merge( self::SCANNED_POST_STATUSES, $post_types, array( $batch_size, $offset ) )
			);
			// phpcs:enable
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_col( $sql );
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $content ) {
				foreach ( $this->extract_attachment_ids_from_text( (string) $content ) as $id ) {
					$found[ $id ] = true;
				}
			}
			if ( count( $rows ) < $batch_size ) {
				break;
			}
			$offset += $batch_size;
		}

		return array_map( 'intval', array_keys( $found ) );
	}

	/**
	 * Featured images: every `_thumbnail_id` postmeta value attached to a
	 * published post. Already-numeric IDs — no extraction needed.
	 */
	private function scan_featured_images(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}

		$status_ph = implode( ',', array_fill( 0, count( self::SCANNED_POST_STATUSES ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $status_ph is a server-built %s list (the query's only placeholders); all values flow through prepare().
		$sql = $wpdb->prepare(
			"SELECT pm.meta_value FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_thumbnail_id'
			   AND p.post_status IN ($status_ph)",
			self::SCANNED_POST_STATUSES
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col( $sql );
		if ( ! $rows ) {
			return array();
		}

		$found = array();
		foreach ( $rows as $val ) {
			$id = (int) $val;
			if ( $id > 0 && $this->is_target_attachment( $id ) ) {
				$found[ $id ] = true;
			}
		}
		return array_map( 'intval', array_keys( $found ) );
	}

	/**
	 * Scan a single postmeta key whose values are URL/ID-bearing text
	 * blobs (Elementor JSON, Beaver Builder serialized data). The blob
	 * is treated as opaque text — the shared regex pulls out both URLs
	 * and `wp-image-{id}` refs.
	 *
	 * @param string $meta_key postmeta key to scan.
	 * @return int[]
	 */
	private function scan_postmeta_blob( string $meta_key ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}

		$status_ph = implode( ',', array_fill( 0, count( self::SCANNED_POST_STATUSES ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $status_ph is a server-built %s list; all values flow through prepare().
		$sql = $wpdb->prepare(
			"SELECT pm.meta_value FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s
			   AND p.post_status IN ($status_ph)",
			array_merge( array( $meta_key ), self::SCANNED_POST_STATUSES )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col( $sql );
		if ( ! $rows ) {
			return array();
		}

		$found = array();
		foreach ( $rows as $blob ) {
			foreach ( $this->extract_attachment_ids_from_text( (string) $blob ) as $id ) {
				$found[ $id ] = true;
			}
		}
		return array_map( 'intval', array_keys( $found ) );
	}

	/**
	 * Scan active widget areas for image references.
	 *
	 * Covers text widgets, image widgets, block widgets (which can embed
	 * Gutenberg image blocks), gallery widgets, and custom HTML widgets.
	 * Widget option data is stored as serialized PHP in wp_options; the
	 * existing text/URL extractors handle it without deserializing.
	 */
	private function scan_widget_areas(): array {
		$option_keys = array(
			'widget_text',
			'widget_media_image',
			'widget_block',
			'widget_custom_html',
			'widget_media_gallery',
		);
		$found = array();
		foreach ( $option_keys as $key ) {
			$val = get_option( $key );
			if ( ! $val ) {
				continue;
			}
			$blob = is_string( $val ) ? $val : maybe_serialize( $val );
			foreach ( $this->extract_attachment_ids_from_text( $blob ) as $id ) {
				$found[ $id ] = true;
			}
		}
		return array_map( 'intval', array_keys( $found ) );
	}

	/**
	 * Scan ACF image fields for attachment IDs.
	 *
	 * ACF image fields store the attachment ID as a bare integer in postmeta.
	 * Because field key names are user-defined, we can't enumerate them.
	 * Instead we find all postmeta rows whose value is a positive integer
	 * that matches a known JPEG/PNG attachment, on keys without the underscore
	 * prefix (which is ACF's convention for field-key pointer rows and
	 * WordPress's convention for private/internal meta). Processed in chunks
	 * to keep the IN clause manageable on large libraries.
	 */
	private function scan_acf_postmeta(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || empty( $this->valid_id_set ) ) {
			return array();
		}

		$all_ids = array_map( 'intval', array_keys( $this->valid_id_set ) );
		$found   = array();

		// Previous implementation used `meta_value REGEXP '^[1-9][0-9]*$'`
		// to filter non-numeric strings AND `CAST(meta_value AS UNSIGNED) IN
		// (...)` to compare against attachment IDs. Both operations defeat
		// the meta_value column (LONGTEXT, unindexed) and forced a
		// full-table scan that ran the regex against every postmeta row.
		// On a 50K-row postmeta table this was the single hottest query
		// in the scanner.
		//
		// The new query drops the REGEXP entirely: MySQL implicitly
		// converts strings to integers for IN-against-integer-literals
		// comparison, so "abc"/""/"a:8:{...}" cleanly evaluate to 0 and
		// fail the membership test (no attachment has id 0). The double
		// CAST is gone too — we let MySQL do the comparison once.
		//
		// Semantic change worth being explicit about: MySQL's
		// string→number conversion stops at the first non-numeric
		// character, so a meta_value of "1abc" or "01" or "1.5" now
		// matches attachment id 1, where the old REGEXP rejected those.
		// The downstream `is_target_attachment()` filter still requires
		// the parsed id to be a real JPEG/PNG attachment, so the worst
		// case is a non-ACF plugin storing "<digits><garbage>" on a
		// public-prefix meta_key that happens to lead with the digits of
		// a valid attachment id — at which point that attachment is
		// treated as "in use" when it might not be. The downside is one
		// extra conversion (never a deletion, never a security issue);
		// the upside is removing a per-row regex from the front-end-
		// facing scanner. Tracked tradeoff, accepted.
		//
		// $wpdb->prepare with %d placeholders for every id provides
		// defense-in-depth even though $all_ids is server-built; if a
		// future caller threads user input into the valid-id set, the
		// query still won't be exploitable.
		foreach ( array_chunk( $all_ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a server-built %d list; every user value flows through $wpdb->prepare() below.
			$sql = "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
			         WHERE meta_key NOT LIKE %s
			           AND meta_value IN ($placeholders)";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			$rows = $wpdb->get_col(
				$wpdb->prepare( $sql, '\\_%', ...$chunk )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $rows ) {
				foreach ( $rows as $val ) {
					$id = (int) $val;
					if ( $this->is_target_attachment( $id ) ) {
						$found[ $id ] = true;
					}
				}
			}
		}

		return array_map( 'intval', array_keys( $found ) );
	}

	/**
	 * Scan ACF gallery fields and URL-stored fields for attachment references.
	 *
	 * {@see scan_acf_postmeta()} only catches image fields that store a bare
	 * attachment ID. It misses two very common ACF shapes:
	 *
	 *   - Gallery / repeater fields, which store a SERIALIZED PHP array of
	 *     attachment IDs (e.g. `a:3:{i:0;s:2:"11";...}`).
	 *   - Image fields set to the "Image URL" return format, and file/link
	 *     fields, which store a full uploads URL string.
	 *
	 * Both are surfaced here so galleries and URL-stored images don't get
	 * mis-flagged as unused. Gated on ACF being active (checked by the caller).
	 * The two LIKE scans hit the unindexed meta_value column, but the in-use
	 * scan runs off the request path (cache/rescan/cron), so the cost is
	 * acceptable in exchange for correctness — the alternative is telling a
	 * user their gallery images are safe to delete.
	 *
	 * As with the bare-ID scan, this errs toward marking IDs in-use (a false
	 * "in use" only wastes a conversion; a false "unused" risks a deletion).
	 *
	 * @return int[]
	 */
	private function scan_acf_galleries_and_urls(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || empty( $this->valid_id_set ) ) {
			return array();
		}

		$found          = array();
		$private_prefix = $wpdb->esc_like( '_' ) . '%';

		// (a) URL-stored ACF fields — the shared text extractor resolves any
		// uploads URL (and wp-image-{id} refs) through the filename map.
		$url_like = '%' . $wpdb->esc_like( '/wp-content/uploads/' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$url_rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
				  WHERE meta_key NOT LIKE %s
				    AND meta_value LIKE %s",
				$private_prefix,
				$url_like
			)
		);
		if ( $url_rows ) {
			foreach ( $url_rows as $blob ) {
				foreach ( $this->extract_attachment_ids_from_text( (string) $blob ) as $id ) {
					$found[ $id ] = true;
				}
			}
		}

		// (b) Serialized ID arrays — ACF gallery / repeater store attachment
		// IDs as a serialized PHP array. Collect numeric leaves that map to a
		// known JPEG/PNG attachment.
		$serialized_like = $wpdb->esc_like( 'a:' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$serialized_rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
				  WHERE meta_key NOT LIKE %s
				    AND meta_value LIKE %s",
				$private_prefix,
				$serialized_like
			)
		);
		if ( $serialized_rows ) {
			foreach ( $serialized_rows as $blob ) {
				foreach ( self::extract_ids_from_serialized_ids( (string) $blob ) as $id ) {
					if ( $this->is_target_attachment( $id ) ) {
						$found[ $id ] = true;
					}
				}
			}
		}

		return array_map( 'intval', array_keys( $found ) );
	}

	/**
	 * Pull every positive-integer leaf out of a serialized PHP array (the
	 * shape ACF gallery/repeater fields use to store attachment IDs). Returns
	 * an empty array for anything that doesn't unserialize to an array.
	 *
	 * Static + public so tests can exercise it without a class instance. The
	 * caller filters the result through {@see is_target_attachment()} so only
	 * real JPEG/PNG attachment IDs survive.
	 *
	 * @param string $blob Serialized PHP value.
	 * @return int[] Unique positive integers found as scalar leaves.
	 */
	public static function extract_ids_from_serialized_ids( string $blob ): array {
		if ( '' === $blob || 0 !== strpos( $blob, 'a:' ) ) {
			return array();
		}
		$data = maybe_unserialize( $blob );
		if ( ! is_array( $data ) ) {
			return array();
		}
		$ids = array();
		array_walk_recursive(
			$data,
			static function ( $value ) use ( &$ids ) {
				if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
					$id = (int) $value;
					if ( $id > 0 ) {
						$ids[ $id ] = true;
					}
				}
			}
		);
		return array_map( 'intval', array_keys( $ids ) );
	}

	/**
	 * Scan WooCommerce product gallery images.
	 *
	 * Product main images are already caught by scan_featured_images() via
	 * _thumbnail_id. This covers the gallery: _product_image_gallery stores
	 * a comma-separated list of attachment IDs.
	 */
	private function scan_woocommerce_galleries(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->postmeta}
			 WHERE meta_key = '_product_image_gallery'
			   AND meta_value != ''"
		);

		if ( ! $rows ) {
			return array();
		}

		$found = array();
		foreach ( $rows as $val ) {
			foreach ( preg_split( '/[,\s]+/', (string) $val ) as $id_str ) {
				$id = (int) trim( $id_str );
				if ( $id > 0 && $this->is_target_attachment( $id ) ) {
					$found[ $id ] = true;
				}
			}
		}
		return array_map( 'intval', array_keys( $found ) );
	}

	/**
	 * Public, user-authored post types. Excludes attachments, revisions,
	 * nav menu items, and any builder-owned template type (those are
	 * scanned separately).
	 */
	private function user_public_post_types(): array {
		$builder_owned = array();
		foreach ( self::BUILDER_POST_TYPES as $types ) {
			foreach ( $types as $t ) {
				$builder_owned[ $t ] = true;
			}
		}
		$excluded = $builder_owned + array(
			'attachment'          => true,
			'revision'            => true,
			'nav_menu_item'       => true,
			'custom_css'          => true,
			'customize_changeset' => true,
			'oembed_cache'        => true,
			'user_request'        => true,
			'wp_block'            => true, // Reusable blocks aren't rendered standalone on the front end.
			'wp_template'         => true,
			'wp_template_part'    => true,
			'wp_global_styles'    => true,
			'wp_navigation'       => true,
		);

		if ( ! function_exists( 'get_post_types' ) ) {
			return array( 'post', 'page' );
		}

		// public=true reliably includes 'page' (which has publicly_queryable=false
		// in WP core because pages route by pagename, not by post_type query).
		// Also picks up 'attachment', which we drop via the exclusion list.
		$types = (array) get_post_types( array( 'public' => true ), 'names' );
		$types = array_values(
			array_filter(
				$types,
				static function ( $t ) use ( $excluded ) {
					return ! isset( $excluded[ $t ] );
				}
			)
		);

		return $types;
	}

	// ====================================================================
	// Helpers
	// ====================================================================

	/**
	 * Merge two ID arrays and return a deduped, reindexed result.
	 *
	 * @param int[] $a Existing IDs.
	 * @param int[] $b Additional IDs.
	 * @return int[]
	 */
	private function merge_unique( array $a, array $b ): array {
		foreach ( $b as $id ) {
			$a[] = (int) $id;
		}
		return array_values( array_unique( $a ) );
	}
}
