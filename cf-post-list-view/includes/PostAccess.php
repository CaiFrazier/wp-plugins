<?php
namespace CFPostListView;

/** Share the same permissions between the list, totals and CSV export. */
final class PostAccess {
	public static function allowed_type( string $type ): bool {
		$object = get_post_type_object( $type );
		return $object && $object->public && $object->show_ui
			&& 'attachment' !== $type && current_user_can( $object->cap->edit_posts );
	}

	public static function scope( array $args ): array {
		$type = (string) $args['post_type'];
		if ( ! self::allowed_type( $type ) ) {
			$args['post__in'] = [ 0 ];
			return $args;
		}
		$args['perm'] = 'readable';
		$object = get_post_type_object( $type );
		if ( ! current_user_can( $object->cap->edit_others_posts ) ) {
			$args['author'] = get_current_user_id();
		}
		return $args;
	}
}
