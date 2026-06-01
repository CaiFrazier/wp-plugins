<?php
/**
 * LibraryColumnRegistry — column definitions for the Media Library list view.
 *
 * Single source of truth for every column the REST endpoint, CSV exporter,
 * and JS modal accept. Each column entry:
 *   label    — human-readable name shown in the modal and table header
 *   desc     — developer-facing explanation of the data source / caveats
 *   default  — whether the column is visible on first load
 *   width    — suggested CSS width for the table header
 *   sortable — whether WP_Query can order by this field
 */

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

class LibraryColumnRegistry {

	public static function all(): array {
		return array(
			'identity'     => array(
				'label'   => 'Identity',
				'columns' => array(
					'id'            => array(
						'label'    => 'ID',
						'desc'     => 'WordPress post ID of the attachment record. Stable, never changes.',
						'default'  => true,
						'width'    => '60px',
						'sortable' => true,
					),
					'title'         => array(
						'label'    => 'Title',
						'desc'     => 'post_title — the human-readable name assigned at upload or edit time.',
						'default'  => true,
						'width'    => '200px',
						'sortable' => true,
					),
					'slug'          => array(
						'label'    => 'Slug',
						'desc'     => 'post_name — the URL-safe slug stored in wp_posts. Unique within post type.',
						'default'  => true,
						'width'    => '180px',
						'sortable' => true,
					),
					'full_url'      => array(
						'label'    => 'Full URL',
						'desc'     => 'Absolute URL returned by wp_get_attachment_url(). Reflects any CDN / offload rewrite.',
						'default'  => true,
						'width'    => '320px',
						'sortable' => false,
					),
					'relative_path' => array(
						'label'    => 'Relative Path',
						'desc'     => 'Domain-relative version of the attachment URL (strips scheme + host).',
						'default'  => false,
						'width'    => '260px',
						'sortable' => false,
					),
					'guid'          => array(
						'label'    => 'GUID',
						'desc'     => 'The raw guid column from wp_posts — the original URL at upload time. May differ from current URL if the site has moved or uploads were migrated.',
						'default'  => false,
						'width'    => '280px',
						'sortable' => false,
					),
					'file_name'     => array(
						'label'    => 'File Name',
						'desc'     => 'Basename of the file (e.g. photo.jpg). Derived from the attachment URL.',
						'default'  => false,
						'width'    => '180px',
						'sortable' => false,
					),
				),
			),
			'file'         => array(
				'label'   => 'File Metadata',
				'columns' => array(
					'mime_type'       => array(
						'label'    => 'MIME Type',
						'desc'     => 'post_mime_type — e.g. image/jpeg, application/pdf, video/mp4.',
						'default'  => true,
						'width'    => '140px',
						'sortable' => false,
					),
					'file_size'       => array(
						'label'    => 'File Size',
						'desc'     => 'Size of the source file on disk, formatted (e.g. 1.2 MB). Reads from the filesystem; empty if file is missing.',
						'default'  => true,
						'width'    => '95px',
						'sortable' => false,
					),
					'file_size_bytes' => array(
						'label'    => 'File Size (bytes)',
						'desc'     => 'Raw byte count of the source file — useful for programmatic use or precise sorting.',
						'default'  => false,
						'width'    => '120px',
						'sortable' => false,
					),
					'dimensions'      => array(
						'label'    => 'Dimensions',
						'desc'     => 'Width × Height in pixels for image attachments. Empty for non-image types.',
						'default'  => false,
						'width'    => '110px',
						'sortable' => false,
					),
					'width'           => array(
						'label'    => 'Width (px)',
						'desc'     => 'Image width in pixels from wp_attachment_metadata.',
						'default'  => false,
						'width'    => '80px',
						'sortable' => false,
					),
					'height'          => array(
						'label'    => 'Height (px)',
						'desc'     => 'Image height in pixels from wp_attachment_metadata.',
						'default'  => false,
						'width'    => '80px',
						'sortable' => false,
					),
					'file_ext'        => array(
						'label'    => 'Extension',
						'desc'     => 'File extension (jpg, png, pdf, mp4, …) derived from the attachment URL.',
						'default'  => false,
						'width'    => '75px',
						'sortable' => false,
					),
					'color_space'     => array(
						'label'    => 'Color Space',
						'desc'     => 'Color space from image_meta (e.g. RGB, CMYK). Only present for images processed by WordPress core.',
						'default'  => false,
						'width'    => '105px',
						'sortable' => false,
					),
				),
			),
			'content'      => array(
				'label'   => 'Content & SEO',
				'columns' => array(
					'alt_text'    => array(
						'label'    => 'Alt Text',
						'desc'     => '_wp_attachment_image_alt postmeta — the value served as the img alt attribute.',
						'default'  => false,
						'width'    => '200px',
						'sortable' => false,
					),
					'has_alt'     => array(
						'label'    => 'Has Alt',
						'desc'     => 'Boolean: ✓ if a non-empty alt text exists, ✗ otherwise. Useful for a11y audits.',
						'default'  => false,
						'width'    => '70px',
						'sortable' => false,
					),
					'caption'     => array(
						'label'    => 'Caption',
						'desc'     => 'post_excerpt — the short caption displayed in galleries and media embeds.',
						'default'  => false,
						'width'    => '200px',
						'sortable' => false,
					),
					'description' => array(
						'label'    => 'Description',
						'desc'     => 'post_content — the long-form attachment description. HTML stripped for display.',
						'default'  => false,
						'width'    => '220px',
						'sortable' => false,
					),
				),
			),
			'context'      => array(
				'label'   => 'Attachment Context',
				'columns' => array(
					'parent_id'          => array(
						'label'    => 'Parent ID',
						'desc'     => 'post_parent — the ID of the post or page this file is attached to. 0 means unattached.',
						'default'  => false,
						'width'    => '80px',
						'sortable' => false,
					),
					'parent_title'       => array(
						'label'    => 'Parent Title',
						'desc'     => 'Title of the parent post, if any. Performs a get_post() lookup per row.',
						'default'  => false,
						'width'    => '200px',
						'sortable' => false,
					),
					'parent_type'        => array(
						'label'    => 'Parent Type',
						'desc'     => 'Post type of the parent (e.g. post, page, product). Performs a get_post() lookup per row.',
						'default'  => false,
						'width'    => '110px',
						'sortable' => false,
					),
					'is_unattached'      => array(
						'label'    => 'Unattached',
						'desc'     => 'true when post_parent = 0; false when attached to a post.',
						'default'  => false,
						'width'    => '90px',
						'sortable' => false,
					),
					'author_id'          => array(
						'label'    => 'Author ID',
						'desc'     => 'post_author — the numeric ID of the WordPress user who uploaded this file.',
						'default'  => false,
						'width'    => '80px',
						'sortable' => false,
					),
					'author_login'       => array(
						'label'    => 'Author Login',
						'desc'     => 'Username (user_login) of the uploading user.',
						'default'  => false,
						'width'    => '130px',
						'sortable' => false,
					),
					'author_display_name' => array(
						'label'    => 'Author Name',
						'desc'     => 'Display name of the uploading user.',
						'default'  => false,
						'width'    => '140px',
						'sortable' => false,
					),
				),
			),
			'timestamps'   => array(
				'label'   => 'Timestamps',
				'columns' => array(
					'date_uploaded'     => array(
						'label'    => 'Date Uploaded',
						'desc'     => 'post_date — upload date/time in the site\'s configured timezone.',
						'default'  => true,
						'width'    => '145px',
						'sortable' => true,
					),
					'date_uploaded_gmt' => array(
						'label'    => 'Date Uploaded (GMT)',
						'desc'     => 'post_date_gmt — upload date/time in UTC.',
						'default'  => false,
						'width'    => '160px',
						'sortable' => true,
					),
					'date_modified'     => array(
						'label'    => 'Date Modified',
						'desc'     => 'post_modified — last-modified date/time in the site\'s configured timezone.',
						'default'  => false,
						'width'    => '145px',
						'sortable' => true,
					),
					'date_modified_gmt' => array(
						'label'    => 'Date Modified (GMT)',
						'desc'     => 'post_modified_gmt — last-modified date/time in UTC.',
						'default'  => false,
						'width'    => '160px',
						'sortable' => true,
					),
				),
			),
			'exif'         => array(
				'label'   => 'EXIF / Camera Data',
				'columns' => array(
					'exif_camera'        => array(
						'label'    => 'Camera Model',
						'desc'     => 'Camera make + model from EXIF (image_meta[camera]). Empty for non-photo files.',
						'default'  => false,
						'width'    => '170px',
						'sortable' => false,
					),
					'exif_aperture'      => array(
						'label'    => 'Aperture',
						'desc'     => 'f-stop value from EXIF image_meta[aperture] (e.g. f/2.8).',
						'default'  => false,
						'width'    => '85px',
						'sortable' => false,
					),
					'exif_shutter'       => array(
						'label'    => 'Shutter Speed',
						'desc'     => 'Shutter speed from EXIF image_meta[shutter_speed] (e.g. 0.004 → displayed as 1/250).',
						'default'  => false,
						'width'    => '110px',
						'sortable' => false,
					),
					'exif_iso'           => array(
						'label'    => 'ISO',
						'desc'     => 'ISO sensitivity from EXIF image_meta[iso].',
						'default'  => false,
						'width'    => '70px',
						'sortable' => false,
					),
					'exif_focal_length'  => array(
						'label'    => 'Focal Length',
						'desc'     => 'Focal length in mm from EXIF image_meta[focal_length].',
						'default'  => false,
						'width'    => '105px',
						'sortable' => false,
					),
					'exif_created_at'    => array(
						'label'    => 'EXIF Date',
						'desc'     => 'image_meta[created_timestamp] — when the photo was taken (not uploaded). UTC.',
						'default'  => false,
						'width'    => '145px',
						'sortable' => false,
					),
					'exif_orientation'   => array(
						'label'    => 'Orientation',
						'desc'     => 'EXIF orientation integer (1–8). 1 = normal; values > 1 indicate rotation or flip.',
						'default'  => false,
						'width'    => '95px',
						'sortable' => false,
					),
				),
			),
			'wp_internals' => array(
				'label'   => 'WordPress Internals',
				'columns' => array(
					'post_status'     => array(
						'label'    => 'Post Status',
						'desc'     => 'wp_posts.post_status — almost always "inherit" for attachments.',
						'default'  => false,
						'width'    => '100px',
						'sortable' => false,
					),
					'menu_order'      => array(
						'label'    => 'Menu Order',
						'desc'     => 'post_menu_order — used by some gallery and ordering plugins to control display sequence.',
						'default'  => false,
						'width'    => '95px',
						'sortable' => true,
					),
					'comment_status'  => array(
						'label'    => 'Comment Status',
						'desc'     => '"open" or "closed" — controls whether comments are allowed on the attachment page.',
						'default'  => false,
						'width'    => '125px',
						'sortable' => false,
					),
					'num_sizes'       => array(
						'label'    => 'Registered Sizes',
						'desc'     => 'Number of sub-sizes generated by WordPress for this image (from _wp_attachment_metadata[sizes]).',
						'default'  => false,
						'width'    => '115px',
						'sortable' => false,
					),
					'sizes_list'      => array(
						'label'    => 'Size Names',
						'desc'     => 'Comma-separated list of registered sub-size keys available for this image (e.g. thumbnail, medium, large).',
						'default'  => false,
						'width'    => '220px',
						'sortable' => false,
					),
					'attached_file'   => array(
						'label'    => '_wp_attached_file',
						'desc'     => 'Raw value of the _wp_attached_file postmeta key — server-relative path from the uploads root (e.g. 2024/05/photo.jpg).',
						'default'  => false,
						'width'    => '230px',
						'sortable' => false,
					),
				),
			),
		);
	}

	/**
	 * Returns an ordered flat map of column_key => column_definition.
	 */
	public static function flat(): array {
		$flat = array();
		foreach ( self::all() as $group ) {
			foreach ( $group['columns'] as $key => $col ) {
				$flat[ $key ] = $col;
			}
		}
		return $flat;
	}

	/**
	 * Returns the keys of columns that are on by default.
	 */
	public static function defaults(): array {
		$defaults = array();
		foreach ( self::all() as $group ) {
			foreach ( $group['columns'] as $key => $col ) {
				if ( $col['default'] ) {
					$defaults[] = $key;
				}
			}
		}
		return $defaults;
	}

	/**
	 * Returns a set of all valid column keys.
	 *
	 * @return array<string>
	 */
	public static function valid_keys(): array {
		return array_keys( self::flat() );
	}
}
