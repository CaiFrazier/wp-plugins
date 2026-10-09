<?php
namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

/** A trash action must never fall through to permanent deletion. */
final class AttachmentTrash {
	public static function available(): bool {
		return defined( 'MEDIA_TRASH' ) && MEDIA_TRASH
			&& defined( 'EMPTY_TRASH_DAYS' ) && EMPTY_TRASH_DAYS > 0;
	}

	/** Null means success; a message means no destructive action was taken. */
	public static function move( int $id ): ?string {
		if ( ! self::available() ) {
			return __( 'Media Trash is disabled. Enable MEDIA_TRASH and a positive EMPTY_TRASH_DAYS value before moving attachments to Trash.', 'cf-media-manager' );
		}
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return __( 'Invalid attachment.', 'cf-media-manager' );
		}
		if ( 'trash' === $post->post_status ) {
			return __( 'Attachment is already in Trash.', 'cf-media-manager' );
		}
		$result = wp_trash_post( $id );
		return $result ? null : __( 'Could not trash attachment.', 'cf-media-manager' );
	}
}
