<?php

namespace BulkMetaEditor;

defined( 'ABSPATH' ) || exit;

use CFShared\Logger as SharedLogger;

class PostDataService {

	/**
	 * Hard ceiling on the size of a single submitted cell value, in bytes.
	 * Anything larger is rejected at the boundary with a structured
	 * `value_too_large` error so the request continues processing the rest
	 * of the batch.
	 *
	 * Rationale: WP's postmeta `longtext` column will happily store megabytes
	 * but every subsequent read pulls the full value into PHP memory. An
	 * editor pasting a 10 MB string (intentionally or by accident) would OOM
	 * the next request that fetches the row. 64 KB comfortably fits real SEO
	 * meta descriptions, schema JSON, and long custom-field text while
	 * keeping per-row memory well under control.
	 */
	public const MAX_VALUE_BYTES = 65536;

	private Settings $settings;
	private SharedLogger $logger;

	public function __construct( Settings $settings, SharedLogger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Fetch a paginated list of posts for the picker.
	 */
	public function get_posts_for_picker( array $args ): array {
		$started       = microtime( true );
		$settings      = $this->settings->get();
		$enabled_types = $settings['enabled_types'];
		$post_type     = sanitize_key( $args['post_type'] ?? '' );

		if ( ! in_array( $post_type, $enabled_types, true ) ) {
			$this->logger->warn(
				'picker',
				'post_type not enabled',
				[
					'requested'     => $post_type,
					'enabled_types' => $enabled_types,
				]
			);
			return [
				'posts' => [],
				'total' => 0,
				'pages' => 0,
			];
		}

		// Ceiling matches Settings::sanitize() default_per_page so the value
		// configured in Settings is the value the picker actually honours.
		$per_page = max( 10, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$status   = sanitize_text_field( $args['status'] ?? 'any' );
		$search   = sanitize_text_field( $args['search'] ?? '' );

		$query_args = [
			'post_type'      => $post_type,
			'post_status'    => $status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'perm'           => 'editable',
			'no_found_rows'  => false,
		];
		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		$query   = new \WP_Query( $query_args );
		$posts   = [];
		$denied  = 0;
		$missing = 0;

		foreach ( $query->posts as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				++$denied;
				continue;
			}
			$post = get_post( $id );
			if ( ! $post ) {
				++$missing;
				continue;
			}
			$posts[] = [
				'id'     => (int) $id,
				'title'  => get_the_title( $post ),
				'status' => $post->post_status,
				'date'   => get_post_modified_time( 'c', false, $post ),
			];
		}

		$this->logger->info(
			'picker',
			'query complete',
			[
				'post_type'   => $post_type,
				'page'        => $page,
				'per_page'    => $per_page,
				'status'      => $status,
				'search'      => $search,
				'found_posts' => (int) $query->found_posts,
				'returned'    => count( $posts ),
				'perm_denied' => $denied,
				'missing'     => $missing,
				'duration_ms' => round( ( microtime( true ) - $started ) * 1000, 2 ),
			]
		);

		return [
			'posts' => $posts,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		];
	}

	/**
	 * Fetch full row data for a set of post IDs.
	 *
	 * Primes the post and meta caches up-front so the per-row get_post_meta
	 * calls inside the loop are object-cache hits rather than separate
	 * database queries (~3 queries per post collapses to 2 queries total
	 * regardless of batch size).
	 */
	public function get_rows( array $ids ): array {
		$started  = microtime( true );
		$settings = $this->settings->get();
		$rows     = [];
		$denied   = 0;
		$missing  = 0;

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( ! empty( $ids ) ) {
			// Prime the post cache: pulls every post in one query.
			_prime_post_caches( $ids, false, false );
			// Prime the postmeta cache: pulls every meta row in one query.
			update_meta_cache( 'post', $ids );
			// Prime the author cache: batches every unique post_author into a
			// single users-table query. Without this, the get_the_author_meta()
			// call below would trigger one SELECT per row — a thousand-row
			// load would issue ~1000 extra queries.
			$author_ids = [];
			foreach ( $ids as $id ) {
				$post = get_post( (int) $id );
				if ( $post && $post->post_author ) {
					$author_ids[] = (int) $post->post_author;
				}
			}
			if ( ! empty( $author_ids ) ) {
				cache_users( array_values( array_unique( $author_ids ) ) );
			}
		}

		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( ! current_user_can( 'edit_post', $id ) ) {
				++$denied;
				continue;
			}
			$post = get_post( $id );
			if ( ! $post ) {
				++$missing;
				continue;
			}

			$row = [
				'id'            => $id,
				'post_title'    => $post->post_title,
				'post_name'     => $post->post_name,
				'post_status'   => $post->post_status,
				'post_modified' => get_post_modified_time( 'c', false, $post ),
				'post_author'   => get_the_author_meta( 'display_name', (int) $post->post_author ),
				'edit_link'     => get_edit_post_link( $id, 'raw' ),
				'permalink'     => get_permalink( $id ),
				'excerpt'       => $this->get_excerpt( $post ),
			];

			if ( $settings['meta_title_key'] ) {
				$row['meta_title'] = (string) get_post_meta( $id, $settings['meta_title_key'], true );
			}
			if ( $settings['meta_desc_key'] ) {
				$row['meta_desc'] = (string) get_post_meta( $id, $settings['meta_desc_key'], true );
			}

			foreach ( $settings['custom_columns'] as $col ) {
				if ( ! empty( $col['key'] ) ) {
					$row[ 'custom__' . $col['key'] ] = (string) get_post_meta( $id, $col['key'], true );
				}
			}

			$rows[] = $row;
		}

		$this->logger->info(
			'rows',
			'fetched',
			[
				'requested'   => count( $ids ),
				'returned'    => count( $rows ),
				'perm_denied' => $denied,
				'missing'     => $missing,
				'duration_ms' => round( ( microtime( true ) - $started ) * 1000, 2 ),
			]
		);

		return $rows;
	}

	/**
	 * Save a batch of cell edits.
	 */
	public function save_batch( array $edits ): array {
		$started = microtime( true );
		$results = [];
		$counts  = [
			'success'         => 0,
			'missing_params'  => 0,
			'key_not_allowed' => 0,
			'forbidden'       => 0,
			'value_too_large' => 0,
			'meta_failed'     => 0,
			'unchanged'       => 0,
		];

		foreach ( $edits as $i => $edit ) {
			$id        = (int) ( $edit['id'] ?? 0 );
			$col_key   = isset( $edit['key'] ) ? sanitize_text_field( $edit['key'] ) : '';
			$raw_value = isset( $edit['value'] ) ? (string) $edit['value'] : '';
			// Sanitizer strategy is per-column (BME-P1-007). Built-ins resolve
			// to 'textarea' (the legacy behavior); custom columns honor the
			// editor's choice. The size cap below is applied to the sanitized
			// value, so 'raw' is bounded by MAX_VALUE_BYTES the same way.
			$strategy = $this->settings->column_to_sanitizer( $col_key );
			$value    = self::apply_sanitizer( $strategy, $raw_value );

			if ( ! $id || '' === $col_key ) {
				++$counts['missing_params'];
				$this->logger->warn(
					'save',
					'missing params',
					[
						'index' => $i,
						'edit'  => $edit,
					]
				);
				$results[] = [
					'id'      => $id,
					'key'     => $col_key,
					'success' => false,
					'error'   => 'missing_params',
				];
				continue;
			}

			$meta_key = $this->settings->column_to_meta_key( $col_key );
			if ( null === $meta_key ) {
				++$counts['key_not_allowed'];
				$this->logger->warn(
					'save',
					'column key not in allowlist',
					[
						'id'      => $id,
						'col_key' => $col_key,
					]
				);
				$results[] = [
					'id'      => $id,
					'key'     => $col_key,
					'success' => false,
					'error'   => 'key_not_allowed',
				];
				continue;
			}

			if ( ! current_user_can( 'edit_post', $id ) ) {
				++$counts['forbidden'];
				$this->logger->warn(
					'save',
					'edit_post denied',
					[
						'id'      => $id,
						'col_key' => $col_key,
					]
				);
				$results[] = [
					'id'      => $id,
					'key'     => $col_key,
					'success' => false,
					'error'   => 'forbidden',
				];
				continue;
			}

			// Length cap (BME-P0-003): reject oversized payloads at the boundary
			// so a 10 MB paste from one editor can't OOM the next request that
			// reads this postmeta row. We measure the sanitized value (what
			// would actually get stored) against the cap; the raw byte count is
			// included in error_context so the UI can show the editor what
			// they sent vs the limit.
			$value_len = strlen( $value );
			if ( $value_len > self::MAX_VALUE_BYTES ) {
				++$counts['value_too_large'];
				$this->logger->warn(
					'save',
					'value exceeds size cap',
					[
						'id'       => $id,
						'col_key'  => $col_key,
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- structured-log context field, not a meta_query clause.
						'meta_key' => $meta_key,
						'actual'   => $value_len,
						'raw'      => strlen( $raw_value ),
						'max'      => self::MAX_VALUE_BYTES,
					]
				);
				$results[] = [
					'id'            => $id,
					'key'           => $col_key,
					'success'       => false,
					'error'         => 'value_too_large',
					'error_context' => [
						'actual' => $value_len,
						'max'    => self::MAX_VALUE_BYTES,
					],
				];
				continue;
			}

			$before  = get_post_meta( $id, $meta_key, true );
			$updated = update_post_meta( $id, $meta_key, $value );
			$after   = get_post_meta( $id, $meta_key, true );

			$success   = ( false !== $updated ) || ( $after === $value );
			$unchanged = ( false === $updated ) && ( $before === $value );

			if ( $unchanged ) {
				++$counts['unchanged'];
			} elseif ( $success ) {
				++$counts['success'];
			} else {
				++$counts['meta_failed'];
			}

			$this->logger->debug(
				'save',
				'cell write',
				[
					'id'              => $id,
					'col_key'         => $col_key,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- structured-log context field, not a meta_query clause.
					'meta_key'        => $meta_key,
					'before_len'      => is_string( $before ) ? strlen( $before ) : null,
					'after_len'       => is_string( $after ) ? strlen( $after ) : null,
					'requested_len'   => strlen( $value ),
					'after_matches'   => $after === $value,
					'update_returned' => $updated,
					'success'         => $success,
					'unchanged'       => $unchanged,
				]
			);

			// If after-value doesn't match what we tried to write, an external
			// filter is interfering — escalate so it's visible at warn level too.
			if ( $after !== $value ) {
				$this->logger->warn(
					'save',
					'meta value diverged from requested after write',
					[
						'id'              => $id,
						'col_key'         => $col_key,
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- structured-log context field, not a meta_query clause.
						'meta_key'        => $meta_key,
						'requested_value' => $value,
						'observed_value'  => is_string( $after ) ? $after : '[non-string]',
					]
				);
			}

			$results[] = [
				'id'      => $id,
				'key'     => $col_key,
				'success' => $success,
				'error'   => $success ? null : 'meta_failed',
			];
		}

		$this->logger->info(
			'save',
			'batch complete',
			array_merge(
				$counts,
				[
					'total'       => count( $edits ),
					'duration_ms' => round( ( microtime( true ) - $started ) * 1000, 2 ),
				]
			)
		);

		return $results;
	}

	/**
	 * Dispatch the per-column sanitizer strategy. Unknown strategies fall
	 * through to 'textarea' (the safe default) — Settings validates at write
	 * time, but defense-in-depth here protects against a configuration that
	 * predates the allow-list addition.
	 *
	 * 'raw' is intentionally untransformed for power users storing JSON or
	 * other structured payloads. The 64 KB cap downstream still bounds it.
	 */
	private static function apply_sanitizer( string $strategy, string $raw_value ): string {
		switch ( $strategy ) {
			case 'text':
				return sanitize_text_field( $raw_value );
			case 'html':
				return wp_kses_post( $raw_value );
			case 'url':
				return esc_url_raw( trim( $raw_value ) );
			case 'number':
				// Keep digits, decimal points, leading +/- only. Empty if no
				// usable numeric content remains.
				$cleaned = preg_replace( '/[^0-9.+\-]/', '', $raw_value );
				return is_numeric( $cleaned ) ? $cleaned : '';
			case 'raw':
				return $raw_value;
			case 'textarea':
			default:
				return sanitize_textarea_field( $raw_value );
		}
	}

	private function get_excerpt( \WP_Post $post ): string {
		$content = strip_shortcodes( $post->post_content );
		$content = wp_strip_all_tags( $content );
		$content = preg_replace( '/\s+/', ' ', $content );
		return wp_trim_words( $content, 60, '...' );
	}
}
