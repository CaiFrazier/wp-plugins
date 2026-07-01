<?php
/**
 * Part of CF QR Redirect.
 * Licensed under the GNU GPL v2 or later — see the LICENSE block in cf-qr-redirect.php.
 *
 * Third-party SEO plugin compatibility for the cfqr_code post type.
 *
 * The cfqr_code CPT must be public + publicly_queryable so /r/{slug} resolves
 * through the rewrite rule (see class-cpt.php). SEO plugins key off `public`
 * and, left alone, treat every QR code as indexable content: they bolt their
 * metabox onto the edit screen and — worse — list the /r/{slug} URLs in their
 * XML sitemap, advertising to Google the exact endpoints the router already
 * serves with `X-Robots-Tag: noindex, nofollow`.
 *
 * Note the front-end meta output of these plugins never actually renders for a
 * live QR code: the router intercepts the singular request and redirects/exits
 * before wp_head runs. So the only two surfaces that matter are the admin
 * metabox (cosmetic noise) and the XML sitemap (real indexing leak). Each
 * filter below neutralizes one or both for whichever SEO plugin is installed.
 *
 * @package CFQR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFQR_SEO_Compat {

	public static function init() {
		// Yoast SEO — removing the post type from "accessible" post types drops
		// the metabox, the indexable, and the sitemap entry in one shot.
		add_filter( 'wpseo_accessible_post_types', array( __CLASS__, 'remove_from_array' ) );

		// SEOPress — the master post-types filter governs titles/metas, social,
		// and sitemap for the post type.
		add_filter( 'seopress_post_types', array( __CLASS__, 'remove_from_array' ) );

		// All in One SEO — its public post types drive the metabox and sitemap.
		add_filter( 'aioseo_public_post_types', array( __CLASS__, 'remove_from_values' ) );

		// The SEO Framework — a single boolean gate disables the metabox, meta
		// output, and sitemap when it returns false for our post type.
		add_filter( 'the_seo_framework_supported_post_type', array( __CLASS__, 'tsf_unsupport' ), 10, 2 );

		// Rank Math — no single master filter; target the sitemap (the real leak).
		// The metabox is governed by Rank Math's own settings UI.
		add_filter( 'rank_math/sitemap/exclude_post_type', array( __CLASS__, 'rank_math_exclude' ), 10, 2 );

		// Slim SEO — two separate filters: one for the metabox, one for the sitemap.
		add_filter( 'slim_seo_meta_box_post_types', array( __CLASS__, 'remove_from_values' ) );
		add_filter( 'slim_seo_sitemap_post_types', array( __CLASS__, 'remove_from_values' ) );
	}

	/**
	 * Drop CFQR_POST_TYPE from an associative array keyed by post-type name
	 * (Yoast, SEOPress).
	 *
	 * @param array $post_types Post types keyed by name.
	 * @return array
	 */
	public static function remove_from_array( $post_types ) {
		if ( is_array( $post_types ) ) {
			unset( $post_types[ CFQR_POST_TYPE ] );
		}
		return $post_types;
	}

	/**
	 * Drop CFQR_POST_TYPE from a flat list of post-type names (AIOSEO, Slim SEO).
	 *
	 * @param array $post_types List of post-type names.
	 * @return array
	 */
	public static function remove_from_values( $post_types ) {
		if ( is_array( $post_types ) ) {
			$post_types = array_values( array_diff( $post_types, array( CFQR_POST_TYPE ) ) );
		}
		return $post_types;
	}

	/**
	 * The SEO Framework: report our post type as unsupported.
	 *
	 * @param bool   $supported Whether the post type is supported.
	 * @param string $post_type The evaluated post type.
	 * @return bool
	 */
	public static function tsf_unsupport( $supported, $post_type ) {
		return CFQR_POST_TYPE === $post_type ? false : $supported;
	}

	/**
	 * Rank Math: exclude our post type from the XML sitemap.
	 *
	 * @param bool   $exclude Whether the post type is excluded.
	 * @param string $type    The post type being evaluated.
	 * @return bool
	 */
	public static function rank_math_exclude( $exclude, $type ) {
		return CFQR_POST_TYPE === $type ? true : $exclude;
	}
}
