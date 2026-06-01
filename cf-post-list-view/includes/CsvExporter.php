<?php
/**
 * CsvExporter — stateless helpers for CSV export.
 *
 * Extracted from Admin so these methods are unit-testable without HTTP context.
 * Handles both static column keys and dynamic taxonomy columns (tax_{slug}).
 *
 * @package CF_Post_List_View
 */

namespace CFPostListView;

class CsvExporter {

	/**
	 * Parse the raw comma-separated column param into a validated column array.
	 * Accepts static column keys and tax_* prefixed taxonomy columns.
	 *
	 * @param string $col_param
	 * @param string $post_type Used for fallback defaults (currently unused; reserved).
	 * @return string[]
	 */
	public static function resolve_columns( string $col_param, string $post_type = 'post' ): array {
		if ( ! $col_param ) {
			return ColumnRegistry::defaults();
		}

		$columns = [];
		foreach ( explode( ',', $col_param ) as $raw ) {
			$key = sanitize_key( trim( $raw ) );
			if ( $key && ColumnRegistry::is_valid( $key ) ) {
				$columns[] = $key;
			}
		}

		return $columns ?: ColumnRegistry::defaults();
	}

	/**
	 * Build WP_Query args from export request parameters.
	 *
	 * @param string $search
	 * @param string $post_type
	 * @param string $post_status
	 * @return array
	 */
	public static function build_query_args( string $search, string $post_type = 'post', string $post_status = '' ): array {
		$allowed_statuses = [ 'publish', 'draft', 'pending', 'future', 'private', 'trash' ];

		$args = [
			'post_type'      => $post_type ?: 'post',
			'post_status'    => in_array( $post_status, $allowed_statuses, true )
				? $post_status
				: [ 'publish', 'draft', 'pending', 'future', 'private' ],
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		if ( $search ) {
			$args['s'] = $search;
		}

		return $args;
	}

	/**
	 * Return the CSV header row (human-readable labels).
	 *
	 * For static columns, uses ColumnRegistry::flat() labels.
	 * For tax_{slug} columns, uses the taxonomy label from get_taxonomy().
	 *
	 * @param string[] $columns
	 * @return string[]
	 */
	public static function headers( array $columns ): array {
		$flat    = ColumnRegistry::flat();
		$headers = [];

		foreach ( $columns as $key ) {
			if ( isset( $flat[ $key ] ) ) {
				$headers[] = $flat[ $key ]['label'];
				continue;
			}

			if ( strncmp( $key, 'tax_', 4 ) === 0 ) {
				$slug = substr( $key, 4 );
				$tax  = get_taxonomy( $slug );
				$headers[] = $tax ? $tax->labels->name : $slug;
				continue;
			}

			$headers[] = $key;
		}

		return $headers;
	}

	/**
	 * Yield one CSV row array per post.
	 *
	 * @param \WP_Post[]|\stdClass[] $posts
	 * @param string[]               $columns
	 * @return iterable<string[]>
	 */
	public static function rows( array $posts, array $columns ): iterable {
		foreach ( $posts as $post ) {
			if ( ! ( $post instanceof \WP_Post ) ) {
				continue;
			}
			$data = PostData::for_post( $post, $columns );
			yield array_values( $data );
		}
	}

	/**
	 * Generate a timestamped CSV filename.
	 *
	 * @param string $post_type
	 * @return string
	 */
	public static function filename( string $post_type = 'posts' ): string {
		return 'cf-plv-' . sanitize_key( $post_type ) . '-' . gmdate( 'Y-m-d' ) . '.csv';
	}
}
