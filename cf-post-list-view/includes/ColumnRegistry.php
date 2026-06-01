<?php
/**
 * ColumnRegistry — static column definitions for CF Post List View.
 *
 * Taxonomy columns are NOT defined here; they are discovered at runtime via
 * the /post-types REST endpoint and injected by the JS into the column modal.
 * The PostData resolver handles any column key prefixed with "tax_" dynamically.
 *
 * @package CF_Post_List_View
 */

namespace CFPostListView;

class ColumnRegistry {

	/**
	 * Returns all static column groups.
	 *
	 * Each group is:
	 *   [ 'label' => string, 'columns' => [ key => [ label, desc, default, width, sortable ] ] ]
	 *
	 * @return array<string, array>
	 */
	public static function all(): array {
		return [
			'identity'   => [
				'label'   => __( 'Identity', 'cf-post-list-view' ),
				'columns' => [
					'id'            => [
						'label'    => __( 'ID', 'cf-post-list-view' ),
						'desc'     => __( 'WordPress post ID.', 'cf-post-list-view' ),
						'default'  => true,
						'width'    => 60,
						'sortable' => true,
					],
					'title'         => [
						'label'    => __( 'Title', 'cf-post-list-view' ),
						'desc'     => __( 'Post title (post_title).', 'cf-post-list-view' ),
						'default'  => true,
						'width'    => 220,
						'sortable' => true,
					],
					'slug'          => [
						'label'    => __( 'Slug', 'cf-post-list-view' ),
						'desc'     => __( 'URL slug (post_name).', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 160,
						'sortable' => true,
					],
					'full_url'      => [
						'label'    => __( 'Full URL', 'cf-post-list-view' ),
						'desc'     => __( 'Full permalink as returned by get_permalink().', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 240,
						'sortable' => false,
					],
					'relative_path' => [
						'label'    => __( 'Relative Path', 'cf-post-list-view' ),
						'desc'     => __( 'Path portion of the permalink (no scheme or host).', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 200,
						'sortable' => false,
					],
					'guid'          => [
						'label'    => __( 'GUID', 'cf-post-list-view' ),
						'desc'     => __( 'WordPress GUID (guid field in wp_posts).', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 200,
						'sortable' => false,
					],
				],
			],
			'content'    => [
				'label'   => __( 'Content', 'cf-post-list-view' ),
				'columns' => [
					'word_count'          => [
						'label'    => __( 'Word Count', 'cf-post-list-view' ),
						'desc'     => __( 'Number of words in post_content (HTML stripped).', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 90,
						'sortable' => false,
					],
					'character_count'     => [
						'label'    => __( 'Character Count', 'cf-post-list-view' ),
						'desc'     => __( 'Number of characters in post_content (HTML stripped).', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 100,
						'sortable' => false,
					],
					'excerpt'             => [
						'label'    => __( 'Excerpt', 'cf-post-list-view' ),
						'desc'     => __( 'Manual excerpt (post_excerpt).', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 200,
						'sortable' => false,
					],
					'has_featured_image'  => [
						'label'    => __( 'Has Featured Image', 'cf-post-list-view' ),
						'desc'     => __( 'Whether a featured image (post thumbnail) is set.', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 90,
						'sortable' => false,
					],
					'featured_image_url'  => [
						'label'    => __( 'Featured Image URL', 'cf-post-list-view' ),
						'desc'     => __( 'Full URL of the featured image (full size).', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 200,
						'sortable' => false,
					],
					'page_template'       => [
						'label'    => __( 'Page Template', 'cf-post-list-view' ),
						'desc'     => __( 'Template file slug assigned via the Page Attributes panel (pages only).', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 140,
						'sortable' => false,
					],
				],
			],
			'seo'        => [
				'label'   => __( 'SEO Meta', 'cf-post-list-view' ),
				'columns' => [
					'yoast_title'       => [
						'label'    => __( 'Yoast Title', 'cf-post-list-view' ),
						'desc'     => __( 'Yoast SEO meta title (_yoast_wpseo_title). Empty if Yoast not active or not set.', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 180,
						'sortable' => false,
					],
					'yoast_description' => [
						'label'    => __( 'Yoast Description', 'cf-post-list-view' ),
						'desc'     => __( 'Yoast SEO meta description (_yoast_wpseo_metadesc). Empty if not set.', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 220,
						'sortable' => false,
					],
					'yoast_robots'      => [
						'label'    => __( 'Yoast Robots', 'cf-post-list-view' ),
						'desc'     => __( 'Yoast robots directive (_yoast_wpseo_meta-robots-noindex). "1" = noindex.', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 100,
						'sortable' => false,
					],
					'rank_math_title'       => [
						'label'    => __( 'Rank Math Title', 'cf-post-list-view' ),
						'desc'     => __( 'Rank Math meta title (rank_math_title). Empty if not set.', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 180,
						'sortable' => false,
					],
					'rank_math_description' => [
						'label'    => __( 'Rank Math Description', 'cf-post-list-view' ),
						'desc'     => __( 'Rank Math meta description (rank_math_description). Empty if not set.', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 220,
						'sortable' => false,
					],
					'rank_math_robots'      => [
						'label'    => __( 'Rank Math Robots', 'cf-post-list-view' ),
						'desc'     => __( 'Rank Math robots array (rank_math_robots). E.g. "noindex,nofollow".', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 120,
						'sortable' => false,
					],
					'canonical_url'         => [
						'label'    => __( 'Canonical URL', 'cf-post-list-view' ),
						'desc'     => __( 'Canonical URL from Yoast (_yoast_wpseo_canonical) or Rank Math (rank_math_canonical_url). Empty if not set.', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 220,
						'sortable' => false,
					],
				],
			],
			'hierarchy'  => [
				'label'   => __( 'Hierarchy & Structure', 'cf-post-list-view' ),
				'columns' => [
					'post_parent_id'    => [
						'label'    => __( 'Parent ID', 'cf-post-list-view' ),
						'desc'     => __( 'ID of the parent post (0 = no parent).', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 70,
						'sortable' => true,
					],
					'post_parent_title' => [
						'label'    => __( 'Parent Title', 'cf-post-list-view' ),
						'desc'     => __( 'Title of the parent post. Empty for top-level posts.', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 180,
						'sortable' => false,
					],
					'menu_order'        => [
						'label'    => __( 'Menu Order', 'cf-post-list-view' ),
						'desc'     => __( 'menu_order value from wp_posts. Used for ordering pages and some CPTs.', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 80,
						'sortable' => true,
					],
					'depth'             => [
						'label'    => __( 'Depth', 'cf-post-list-view' ),
						'desc'     => __( 'Number of ancestor posts (0 = top-level, 1 = child, 2 = grandchild, etc.).', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 60,
						'sortable' => false,
					],
					'child_count'       => [
						'label'    => __( 'Child Count', 'cf-post-list-view' ),
						'desc'     => __( 'Number of immediate children (direct post_parent matches).', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 80,
						'sortable' => false,
					],
				],
			],
			'status'     => [
				'label'   => __( 'Status & Timestamps', 'cf-post-list-view' ),
				'columns' => [
					'post_status'          => [
						'label'    => __( 'Status', 'cf-post-list-view' ),
						'desc'     => __( 'Post status (publish, draft, pending, future, private, etc.).', 'cf-post-list-view' ),
						'default'  => true,
						'width'    => 90,
						'sortable' => false,
					],
					'date_published'       => [
						'label'    => __( 'Published', 'cf-post-list-view' ),
						'desc'     => __( 'Date the post was published (post_date in local time).', 'cf-post-list-view' ),
						'default'  => true,
						'width'    => 130,
						'sortable' => true,
					],
					'date_modified'        => [
						'label'    => __( 'Modified', 'cf-post-list-view' ),
						'desc'     => __( 'Date the post was last modified (post_modified in local time).', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 130,
						'sortable' => true,
					],
					'scheduled_date'       => [
						'label'    => __( 'Scheduled Date', 'cf-post-list-view' ),
						'desc'     => __( 'Scheduled publish date. Only populated for future-status posts.', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 130,
						'sortable' => false,
					],
					'comment_count'        => [
						'label'    => __( 'Comments', 'cf-post-list-view' ),
						'desc'     => __( 'Total comment count (comment_count field).', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 70,
						'sortable' => true,
					],
					'comment_status'       => [
						'label'    => __( 'Comment Status', 'cf-post-list-view' ),
						'desc'     => __( 'Whether comments are open or closed.', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 110,
						'sortable' => false,
					],
					'ping_status'          => [
						'label'    => __( 'Ping Status', 'cf-post-list-view' ),
						'desc'     => __( 'Whether pings/trackbacks are open or closed.', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 100,
						'sortable' => false,
					],
					'is_sticky'            => [
						'label'    => __( 'Sticky', 'cf-post-list-view' ),
						'desc'     => __( 'Whether the post is sticky (appears first in the loop).', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 60,
						'sortable' => false,
					],
					'is_password_protected' => [
						'label'    => __( 'Password Protected', 'cf-post-list-view' ),
						'desc'     => __( 'Whether the post requires a password to view.', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 80,
						'sortable' => false,
					],
				],
			],
			'author'     => [
				'label'   => __( 'Author & Taxonomy', 'cf-post-list-view' ),
				'columns' => [
					'author_id'           => [
						'label'    => __( 'Author ID', 'cf-post-list-view' ),
						'desc'     => __( 'WordPress user ID of the post author.', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 70,
						'sortable' => false,
					],
					'author_login'        => [
						'label'    => __( 'Author Login', 'cf-post-list-view' ),
						'desc'     => __( 'Username (user_login) of the post author.', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 120,
						'sortable' => false,
					],
					'author_display_name' => [
						'label'    => __( 'Author', 'cf-post-list-view' ),
						'desc'     => __( 'Display name of the post author.', 'cf-post-list-view' ),
						'default'  => true,
						'width'    => 130,
						'sortable' => false,
					],
					// Taxonomy columns (tax_{slug}) are injected dynamically by the JS
					// based on the /post-types endpoint response.
				],
			],
			'internals'  => [
				'label'   => __( 'WordPress Internals', 'cf-post-list-view' ),
				'columns' => [
					'post_type'        => [
						'label'    => __( 'Post Type', 'cf-post-list-view' ),
						'desc'     => __( 'Registered post type slug.', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 100,
						'sortable' => false,
					],
					'custom_field_keys' => [
						'label'    => __( 'Custom Field Keys', 'cf-post-list-view' ),
						'desc'     => __( 'Comma-separated list of all postmeta keys present on this post. Useful for debugging CPT data.', 'cf-post-list-view' ),
						'default'  => false,
						'width'    => 240,
						'sortable' => false,
					],
				],
			],
		];
	}

	/**
	 * Returns a flat key => column-definition map (static columns only).
	 *
	 * @return array<string, array>
	 */
	public static function flat(): array {
		$flat = [];
		foreach ( self::all() as $group ) {
			foreach ( $group['columns'] as $key => $col ) {
				$flat[ $key ] = $col;
			}
		}
		return $flat;
	}

	/**
	 * Returns the keys of all default-on static columns.
	 *
	 * @return string[]
	 */
	public static function defaults(): array {
		$keys = [];
		foreach ( self::flat() as $key => $col ) {
			if ( ! empty( $col['default'] ) ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	/**
	 * Returns all valid static column key strings.
	 *
	 * @return string[]
	 */
	public static function valid_keys(): array {
		return array_keys( self::flat() );
	}

	/**
	 * Whether a column key is valid — either a known static column or a
	 * dynamic taxonomy column (prefix "tax_").
	 *
	 * @param string $key
	 * @return bool
	 */
	public static function is_valid( string $key ): bool {
		return in_array( $key, self::valid_keys(), true )
			|| strncmp( $key, 'tax_', 4 ) === 0;
	}
}
