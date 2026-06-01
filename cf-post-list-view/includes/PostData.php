<?php
/**
 * PostData — resolves column values for a single WP_Post.
 *
 * Lazy-loads expensive data (ancestors, featured image, postmeta) at most
 * once per row regardless of how many columns reference that data.
 *
 * Dynamic taxonomy columns: any column key prefixed with "tax_" is resolved
 * by stripping the prefix and calling wp_get_object_terms() for that taxonomy.
 *
 * @package CF_Post_List_View
 */

namespace CFPostListView;

class PostData {

	/**
	 * Build a key => value array for the given post and active column set.
	 *
	 * @param \WP_Post $post
	 * @param string[] $columns Active column keys.
	 * @return array<string, string>
	 */
	public static function for_post( \WP_Post $post, array $columns ): array {
		// Lazy loaders — each closure is invoked at most once per row.
		$ancestors_loaded = false;
		$ancestors        = [];
		$get_ancestors    = static function () use ( $post, &$ancestors_loaded, &$ancestors ) {
			if ( ! $ancestors_loaded ) {
				$ancestors        = get_post_ancestors( $post->ID );
				$ancestors_loaded = true;
			}
			return $ancestors;
		};

		$parent_post_loaded = false;
		$parent_post        = null;
		$get_parent         = static function () use ( $post, &$parent_post_loaded, &$parent_post ) {
			if ( ! $parent_post_loaded ) {
				$parent_post        = $post->post_parent ? get_post( $post->post_parent ) : null;
				$parent_post_loaded = true;
			}
			return $parent_post;
		};

		$thumb_id_loaded = false;
		$thumb_id        = 0;
		$get_thumb_id    = static function () use ( $post, &$thumb_id_loaded, &$thumb_id ) {
			if ( ! $thumb_id_loaded ) {
				$thumb_id        = (int) get_post_thumbnail_id( $post->ID );
				$thumb_id_loaded = true;
			}
			return $thumb_id;
		};

		$author_loaded = false;
		$author        = null;
		$get_author    = static function () use ( $post, &$author_loaded, &$author ) {
			if ( ! $author_loaded ) {
				$author        = get_userdata( (int) $post->post_author );
				$author_loaded = true;
			}
			return $author;
		};

		$permalink_loaded = false;
		$permalink        = '';
		$get_permalink    = static function () use ( $post, &$permalink_loaded, &$permalink ) {
			if ( ! $permalink_loaded ) {
				$url              = get_permalink( $post->ID );
				$permalink        = $url ? $url : '';
				$permalink_loaded = true;
			}
			return $permalink;
		};

		// Term cache per taxonomy for this row.
		$term_cache = [];

		$row = [];
		foreach ( $columns as $key ) {
			$row[ $key ] = self::resolve(
				$key,
				$post,
				$get_ancestors,
				$get_parent,
				$get_thumb_id,
				$get_author,
				$get_permalink,
				$term_cache
			);
		}
		return $row;
	}

	/**
	 * Resolve a single column value to a string.
	 *
	 * @param string   $key
	 * @param \WP_Post $post
	 * @param callable $get_ancestors
	 * @param callable $get_parent
	 * @param callable $get_thumb_id
	 * @param callable $get_author
	 * @param callable $get_permalink
	 * @param array    &$term_cache
	 * @return string
	 */
	private static function resolve(
		string $key,
		\WP_Post $post,
		callable $get_ancestors,
		callable $get_parent,
		callable $get_thumb_id,
		callable $get_author,
		callable $get_permalink,
		array &$term_cache
	): string {
		// Dynamic taxonomy columns.
		if ( strncmp( $key, 'tax_', 4 ) === 0 ) {
			$taxonomy = substr( $key, 4 );
			if ( ! isset( $term_cache[ $taxonomy ] ) ) {
				$terms = wp_get_object_terms( $post->ID, $taxonomy, [ 'fields' => 'names' ] );
				$term_cache[ $taxonomy ] = ( is_array( $terms ) && ! is_wp_error( $terms ) )
					? implode( ', ', $terms )
					: '';
			}
			return $term_cache[ $taxonomy ];
		}

		switch ( $key ) {

			// ----------------------------------------------------------------
			// Identity
			// ----------------------------------------------------------------

			case 'id':
				return (string) $post->ID;

			case 'title':
				return $post->post_title;

			case 'slug':
				return $post->post_name;

			case 'full_url':
				return $get_permalink();

			case 'relative_path':
				$url = $get_permalink();
				if ( ! $url ) {
					return '';
				}
				$parsed = wp_parse_url( $url );
				$path   = isset( $parsed['path'] ) ? $parsed['path'] : '/';
				if ( ! empty( $parsed['query'] ) ) {
					$path .= '?' . $parsed['query'];
				}
				return $path;

			case 'guid':
				return $post->guid;

			// ----------------------------------------------------------------
			// Content
			// ----------------------------------------------------------------

			case 'word_count':
				$text = wp_strip_all_tags( $post->post_content );
				return $text ? (string) str_word_count( $text ) : '0';

			case 'character_count':
				$text = wp_strip_all_tags( $post->post_content );
				return (string) mb_strlen( $text );

			case 'excerpt':
				return $post->post_excerpt;

			case 'has_featured_image':
				return $get_thumb_id() ? "\u{2713}" : "\u{2717}";

			case 'featured_image_url':
				$tid = $get_thumb_id();
				if ( ! $tid ) {
					return '';
				}
				$src = wp_get_attachment_image_url( $tid, 'full' );
				return $src ? $src : '';

			case 'page_template':
				return (string) get_page_template_slug( $post->ID );

			// ----------------------------------------------------------------
			// SEO meta — reads postmeta directly; degrades gracefully
			// ----------------------------------------------------------------

			case 'yoast_title':
				return (string) get_post_meta( $post->ID, '_yoast_wpseo_title', true );

			case 'yoast_description':
				return (string) get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );

			case 'yoast_robots':
				$val = get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true );
				return $val !== '' && $val !== false ? (string) $val : '';

			case 'rank_math_title':
				return (string) get_post_meta( $post->ID, 'rank_math_title', true );

			case 'rank_math_description':
				return (string) get_post_meta( $post->ID, 'rank_math_description', true );

			case 'rank_math_robots':
				$val = get_post_meta( $post->ID, 'rank_math_robots', true );
				if ( is_array( $val ) ) {
					return implode( ', ', $val );
				}
				return (string) $val;

			case 'canonical_url':
				// Prefer Yoast; fall back to Rank Math.
				$yoast = (string) get_post_meta( $post->ID, '_yoast_wpseo_canonical', true );
				if ( $yoast ) {
					return $yoast;
				}
				return (string) get_post_meta( $post->ID, 'rank_math_canonical_url', true );

			// ----------------------------------------------------------------
			// Hierarchy & structure
			// ----------------------------------------------------------------

			case 'post_parent_id':
				return $post->post_parent ? (string) $post->post_parent : '';

			case 'post_parent_title':
				$parent = $get_parent();
				return $parent ? $parent->post_title : '';

			case 'menu_order':
				return (string) $post->menu_order;

			case 'depth':
				return (string) count( $get_ancestors() );

			case 'child_count':
				global $wpdb;
				$count = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->posts}
						 WHERE post_parent = %d
						   AND post_status NOT IN ('auto-draft','trash')
						   AND post_type = %s",
						$post->ID,
						$post->post_type
					)
				);
				return (string) $count;

			// ----------------------------------------------------------------
			// Status & timestamps
			// ----------------------------------------------------------------

			case 'post_status':
				return $post->post_status;

			case 'date_published':
				return $post->post_date ? date_i18n( 'Y-m-d H:i', strtotime( $post->post_date ) ) : '';

			case 'date_modified':
				return $post->post_modified ? date_i18n( 'Y-m-d H:i', strtotime( $post->post_modified ) ) : '';

			case 'scheduled_date':
				return $post->post_status === 'future'
					? date_i18n( 'Y-m-d H:i', strtotime( $post->post_date ) )
					: '';

			case 'comment_count':
				return (string) $post->comment_count;

			case 'comment_status':
				return $post->comment_status;

			case 'ping_status':
				return $post->ping_status;

			case 'is_sticky':
				return is_sticky( $post->ID ) ? "\u{2713}" : "\u{2717}";

			case 'is_password_protected':
				return $post->post_password !== '' ? "\u{2713}" : "\u{2717}";

			// ----------------------------------------------------------------
			// Author
			// ----------------------------------------------------------------

			case 'author_id':
				return (string) $post->post_author;

			case 'author_login':
				$user = $get_author();
				return $user ? $user->user_login : '';

			case 'author_display_name':
				$user = $get_author();
				return $user ? $user->display_name : '';

			// ----------------------------------------------------------------
			// WordPress internals
			// ----------------------------------------------------------------

			case 'post_type':
				return $post->post_type;

			case 'custom_field_keys':
				$keys = get_post_custom_keys( $post->ID );
				if ( ! $keys ) {
					return '';
				}
				// Filter out leading-underscore "protected" keys that are WP internals.
				$public = array_filter( $keys, static function ( $k ) {
					return strncmp( $k, '_', 1 ) !== 0;
				} );
				$all = array_merge( $public, array_diff( $keys, $public ) );
				return implode( ', ', $all );

			default:
				return '';
		}
	}
}
