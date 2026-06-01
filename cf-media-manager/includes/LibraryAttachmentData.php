<?php
/**
 * LibraryAttachmentData — resolves column values for a single WP_Post attachment
 * in the Media Library list view.
 *
 * wp_get_attachment_metadata() is called at most once per row, lazily, and
 * only when at least one column in the active set needs it.
 */

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

class LibraryAttachmentData {

	/**
	 * Build a keyed array of column_key => display_value for one attachment.
	 *
	 * @param \WP_Post $post    The attachment post object.
	 * @param string[] $columns Ordered list of column keys to resolve.
	 * @return array<string, string>
	 */
	public static function for_post( \WP_Post $post, array $columns ): array {
		// Lazy-load image metadata only once.
		$image_meta = null;
		$get_meta   = static function () use ( $post, &$image_meta ) {
			if ( null === $image_meta ) {
				$image_meta = wp_get_attachment_metadata( $post->ID );
				if ( ! is_array( $image_meta ) ) {
					$image_meta = array();
				}
			}
			return $image_meta;
		};

		$row = array();
		foreach ( $columns as $col ) {
			$row[ $col ] = self::resolve( $post, $col, $get_meta );
		}
		return $row;
	}

	// phpcs:disable Generic.Metrics.CyclomaticComplexity.TooHigh
	private static function resolve( \WP_Post $post, string $col, callable $get_meta ): string {
		switch ( $col ) {

			// --- Identity ---------------------------------------------------
			case 'id':
				return (string) $post->ID;

			case 'title':
				return $post->post_title;

			case 'slug':
				return $post->post_name;

			case 'full_url':
				return (string) ( wp_get_attachment_url( $post->ID ) ?: '' );

			case 'relative_path':
				$url = wp_get_attachment_url( $post->ID );
				return $url ? '/' . ltrim( wp_make_link_relative( $url ), '/' ) : '';

			case 'guid':
				return $post->guid;

			case 'file_name':
				$url = wp_get_attachment_url( $post->ID );
				return $url ? basename( $url ) : '';

			// --- File metadata ----------------------------------------------
			case 'mime_type':
				return $post->post_mime_type;

			case 'file_size':
				$path = get_attached_file( $post->ID );
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$size = ( $path && @file_exists( $path ) ) ? @filesize( $path ) : false;
				return false !== $size ? size_format( $size ) : '';

			case 'file_size_bytes':
				$path = get_attached_file( $post->ID );
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$size = ( $path && @file_exists( $path ) ) ? @filesize( $path ) : false;
				return false !== $size ? (string) $size : '';

			case 'dimensions':
				$m = $get_meta();
				return ( ! empty( $m['width'] ) && ! empty( $m['height'] ) )
					? $m['width'] . ' × ' . $m['height']
					: '';

			case 'width':
				$m = $get_meta();
				return ! empty( $m['width'] ) ? (string) $m['width'] : '';

			case 'height':
				$m = $get_meta();
				return ! empty( $m['height'] ) ? (string) $m['height'] : '';

			case 'file_ext':
				$url = wp_get_attachment_url( $post->ID );
				return $url ? strtolower( pathinfo( $url, PATHINFO_EXTENSION ) ) : '';

			case 'color_space':
				$m = $get_meta();
				return (string) ( $m['image_meta']['color_space'] ?? '' );

			// --- Content & SEO ----------------------------------------------
			case 'alt_text':
				return (string) ( get_post_meta( $post->ID, '_wp_attachment_image_alt', true ) ?: '' );

			case 'has_alt':
				$alt = get_post_meta( $post->ID, '_wp_attachment_image_alt', true );
				return ( $alt && '' !== trim( (string) $alt ) ) ? '✓' : '✗';

			case 'caption':
				return $post->post_excerpt;

			case 'description':
				return wp_strip_all_tags( $post->post_content );

			// --- Attachment context -----------------------------------------
			case 'parent_id':
				return (string) $post->post_parent;

			case 'parent_title':
				if ( $post->post_parent > 0 ) {
					$parent = get_post( $post->post_parent );
					return $parent instanceof \WP_Post ? $parent->post_title : '';
				}
				return '';

			case 'parent_type':
				if ( $post->post_parent > 0 ) {
					$parent = get_post( $post->post_parent );
					return $parent instanceof \WP_Post ? $parent->post_type : '';
				}
				return '';

			case 'is_unattached':
				return 0 === $post->post_parent ? 'true' : 'false';

			case 'author_id':
				return (string) $post->post_author;

			case 'author_login':
				$user = get_userdata( (int) $post->post_author );
				return $user ? $user->user_login : '';

			case 'author_display_name':
				$user = get_userdata( (int) $post->post_author );
				return $user ? $user->display_name : '';

			// --- Timestamps -------------------------------------------------
			case 'date_uploaded':
				return $post->post_date;

			case 'date_uploaded_gmt':
				return $post->post_date_gmt;

			case 'date_modified':
				return $post->post_modified;

			case 'date_modified_gmt':
				return $post->post_modified_gmt;

			// --- EXIF / Camera data -----------------------------------------
			case 'exif_camera':
				$m = $get_meta();
				return (string) ( $m['image_meta']['camera'] ?? '' );

			case 'exif_aperture':
				$m  = $get_meta();
				$ap = $m['image_meta']['aperture'] ?? '';
				return $ap ? 'f/' . $ap : '';

			case 'exif_shutter':
				$m = $get_meta();
				$s = $m['image_meta']['shutter_speed'] ?? '';
				if ( '' === $s ) {
					return '';
				}
				// Convert decimal to fraction for readability.
				$fv = (float) $s;
				if ( $fv > 0 && $fv < 1 ) {
					return '1/' . round( 1 / $fv );
				}
				return (string) $s;

			case 'exif_iso':
				$m = $get_meta();
				return (string) ( $m['image_meta']['iso'] ?? '' );

			case 'exif_focal_length':
				$m  = $get_meta();
				$fl = $m['image_meta']['focal_length'] ?? '';
				return $fl ? $fl . ' mm' : '';

			case 'exif_created_at':
				$m  = $get_meta();
				$ts = $m['image_meta']['created_timestamp'] ?? 0;
				return $ts ? gmdate( 'Y-m-d H:i:s', (int) $ts ) : '';

			case 'exif_orientation':
				$m = $get_meta();
				return (string) ( $m['image_meta']['orientation'] ?? '' );

			// --- WordPress internals ----------------------------------------
			case 'post_status':
				return $post->post_status;

			case 'menu_order':
				return (string) $post->menu_order;

			case 'comment_status':
				return $post->comment_status;

			case 'num_sizes':
				$m = $get_meta();
				return isset( $m['sizes'] ) ? (string) count( $m['sizes'] ) : '0';

			case 'sizes_list':
				$m = $get_meta();
				return isset( $m['sizes'] ) ? implode( ', ', array_keys( $m['sizes'] ) ) : '';

			case 'attached_file':
				return (string) ( get_post_meta( $post->ID, '_wp_attached_file', true ) ?: '' );

			default:
				return '';
		}
	}
	// phpcs:enable
}
