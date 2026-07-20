<?php
/**
 * Part of CF QR Redirect.
 * Licensed under the GNU GPL v2 or later — see the LICENSE block in cf-qr-redirect.php.
 *
 * CSV bulk import for the standard redirect manager.
 *
 * Accepts a CSV file with required headers `source` and `destination`. Optional
 * headers: `mode`, `status`, `active`, `groups`, `label`, `active_from`,
 * `active_until`. Each row is validated using the same predicates the meta-box
 * save handler uses (CFQR_URL, CFQR_Redirect_CPT::sanitize_*), so any row that
 * imports successfully behaves identically to one created via the editor.
 *
 * @package CFQR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFQR_Redirect_Import {

	const NONCE_ACTION = 'cfqr_rd_import';
	const NONCE_FIELD  = 'cfqr_rd_import_nonce';

	// Hard cap on rows per import to prevent runaway memory + DB writes from a
	// malformed or hostile CSV. A site with thousands of redirects can run the
	// import in batches.
	const MAX_ROWS = 5000;

	// Hard cap on the uploaded file size. The row cap can't catch a single
	// pathological line with no newline (fgetcsv would buffer it whole), so we
	// reject oversized uploads up front. MAX_ROWS of real redirect CSV is well
	// under 5 MB; 10 MB leaves generous headroom.
	const MAX_FILE_BYTES = 10485760; // 10 MB.

	/** Required headers, case-insensitive and present in the first non-blank line. */
	private static $required_headers = array( 'source', 'destination' );
	/** Recognized headers. Anything else is ignored with a warning. */
	private static $known_headers = array(
		'source',
		'destination',
		'mode',
		'status',
		'active',
		'groups',
		'label',
		'active_from',
		'active_until',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_post_cfqr_rd_import', array( __CLASS__, 'handle_upload' ) );
	}

	/**
	 * Add the "Import Redirects" submenu under the QR Codes top-level menu.
	 * Gated by cfqr_create_redirects — bulk-creating is a stronger right than
	 * editing existing ones.
	 */
	public static function register_page() {
		add_submenu_page(
			CFQR_MENU_SLUG,
			__( 'Import Redirects', 'cf-qr-redirect' ),
			__( 'Import Redirects', 'cf-qr-redirect' ),
			CFQR_REDIRECT_CAP_CREATE,
			'cfqr-redirect-import',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( CFQR_REDIRECT_CAP_CREATE ) ) {
			wp_die( esc_html__( 'You do not have permission to import redirects.', 'cf-qr-redirect' ), 403 );
		}

		// Read any flash from the upload handler so the summary survives the
		// post-redirect-get pattern.
		$flash_key = 'cfqr_rd_import_' . get_current_user_id();
		$flash     = get_transient( $flash_key );
		if ( is_array( $flash ) ) {
			delete_transient( $flash_key );
		}

		$action_url = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<h1 class="cfqr-h1">
				<span class="cfqr-h1-text"><?php esc_html_e( 'Import Redirects', 'cf-qr-redirect' ); ?></span>
			</h1>
			<hr class="wp-header-end">

			<?php if ( is_array( $flash ) ) : ?>
				<?php self::render_summary( $flash ); ?>
			<?php endif; ?>

			<div class="cfqr-section">
				<h2><?php esc_html_e( 'Upload a CSV', 'cf-qr-redirect' ); ?></h2>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
					<input type="hidden" name="action" value="cfqr_rd_import">

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="cfqr_rd_csv"><?php esc_html_e( 'CSV file', 'cf-qr-redirect' ); ?></label></th>
							<td>
								<input type="file" name="cfqr_rd_csv" id="cfqr_rd_csv" accept=".csv,text/csv" required>
								<p class="description">
									<?php
									printf(
										/* translators: %d = max rows per import */
										esc_html__( 'Up to %d rows per file. UTF-8 encoded. First row must be a header row.', 'cf-qr-redirect' ),
										(int) self::MAX_ROWS
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'On duplicate source', 'cf-qr-redirect' ); ?></th>
							<td>
								<label>
									<input type="radio" name="cfqr_rd_duplicate" value="skip" checked>
									<?php esc_html_e( 'Skip — leave the existing redirect untouched (recommended).', 'cf-qr-redirect' ); ?>
								</label>
								<br>
								<label>
									<input type="radio" name="cfqr_rd_duplicate" value="update">
									<?php esc_html_e( 'Update — overwrite the existing redirect with the CSV row.', 'cf-qr-redirect' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Only applies to exact-mode redirects (the only mode with reliable dup detection).', 'cf-qr-redirect' ); ?></p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Import', 'cf-qr-redirect' ) ); ?>
				</form>
			</div>

			<div class="cfqr-section">
				<h2><?php esc_html_e( 'CSV format', 'cf-qr-redirect' ); ?></h2>
				<p><?php esc_html_e( 'Required headers: source, destination. Optional headers: mode, status, active, groups, label, active_from, active_until.', 'cf-qr-redirect' ); ?></p>
				<pre class="cfqr-codeblock">source,destination,mode,status,active,groups,label
/old-page/,https://example.com/new-page/,exact,302,1,site-migration-2024,Old contact
/blog/*,https://news.example.com/$1,wildcard,302,1,site-migration-2024,Blog migration
^/products/widget-(\d+)$,https://shop.example.com/items/$1,regex,301,1,product-rename,Widget rename
/search?q=old-product,https://shop.example.com/new-product,query_aware,302,1,,Old search funnel</pre>
				<p class="description">
					<strong><?php esc_html_e( 'Field notes:', 'cf-qr-redirect' ); ?></strong>
					<br>
					<code>mode</code>: <?php esc_html_e( 'one of exact, wildcard, regex, query_aware (default: exact).', 'cf-qr-redirect' ); ?>
					<br>
					<code>status</code>: <?php esc_html_e( '301, 302, 307, or 308 (default: 302).', 'cf-qr-redirect' ); ?>
					<br>
					<code>active</code>: <?php esc_html_e( '1 or 0 (default: 1).', 'cf-qr-redirect' ); ?>
					<br>
					<code>groups</code>: <?php esc_html_e( 'comma-separated group names. Quote the cell if multiple, e.g. "spring-campaign, q2-launches". New groups are created automatically.', 'cf-qr-redirect' ); ?>
					<br>
					<code>active_from</code>, <code>active_until</code>: <?php esc_html_e( 'ISO 8601 datetimes (e.g. 2026-06-01T14:00:00). Empty = no bound.', 'cf-qr-redirect' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle the form POST. Validates the upload, parses the CSV, imports row
	 * by row, then redirects back to the page with a flashed summary.
	 */
	public static function handle_upload() {
		if ( ! current_user_can( CFQR_REDIRECT_CAP_CREATE ) ) {
			wp_die( esc_html__( 'You do not have permission to import redirects.', 'cf-qr-redirect' ), 403 );
		}
		if (
			! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
		) {
			wp_die( esc_html__( 'Security check failed. Reload the import page and try again.', 'cf-qr-redirect' ), 403 );
		}

		$duplicate_policy = isset( $_POST['cfqr_rd_duplicate'] ) && 'update' === $_POST['cfqr_rd_duplicate'] ? 'update' : 'skip';

		// $_FILES is an uploaded-file struct, not user-typed input — the keys
		// we read (tmp_name, error) are populated by PHP itself, and we
		// validate tmp_name with is_uploaded_file() before any filesystem op.
		// sanitize_text_field on the array would corrupt the struct, and the
		// individual values we use are never echoed.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$file = isset( $_FILES['cfqr_rd_csv'] ) ? $_FILES['cfqr_rd_csv'] : null;
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::flash_and_redirect( array( 'error' => __( 'No file was uploaded.', 'cf-qr-redirect' ) ) );
		}
		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			self::flash_and_redirect( array( 'error' => __( 'Upload failed. The file may be too large for this server.', 'cf-qr-redirect' ) ) );
		}

		$tmp = sanitize_text_field( (string) $file['tmp_name'] );

		$size = (int) @filesize( $tmp ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.NoSilencedErrors
		if ( $size > self::MAX_FILE_BYTES ) {
			self::flash_and_redirect(
				array(
					'error' => sprintf(
						/* translators: %d = maximum upload size in megabytes */
						__( 'The CSV file is too large. Maximum size is %d MB — split the import into smaller files.', 'cf-qr-redirect' ),
						(int) ( self::MAX_FILE_BYTES / 1048576 )
					),
				)
			);
		}

		$result = self::import_file( $tmp, $duplicate_policy );
		self::flash_and_redirect( $result );
	}

	/**
	 * Parse a CSV file and import its rows. Pure-ish — returns a result array
	 * for the caller to surface. Reads the file via fgetcsv so we don't pull
	 * the whole CSV into memory at once.
	 *
	 * @param string $path             Local path to the uploaded CSV.
	 * @param string $duplicate_policy 'skip' or 'update' — what to do when an
	 *                                 exact-mode source already exists.
	 * @return array{imported:int,updated:int,skipped:int,errors:array<int,array{row:int,reason:string}>,warnings:array<int,string>}
	 */
	public static function import_file( $path, $duplicate_policy = 'skip' ) {
		$result = array(
			'imported' => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'errors'   => array(),
			'warnings' => array(),
		);

		// fopen + fgetcsv is used here (not WP_Filesystem) so the CSV can be
		// streamed row-by-row without loading the whole upload into memory.
		// The path comes from PHP's $_FILES tmp_name and is validated with
		// is_uploaded_file() in handle_upload() before we get here, so there
		// is no traversal or arbitrary-path concern.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, Generic.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.NoSilencedErrors
		$handle = @fopen( $path, 'r' );
		if ( ! $handle ) {
			$result['errors'][] = array(
				'row'    => 0,
				'reason' => __( 'Could not open uploaded file.', 'cf-qr-redirect' ),
			);
			return $result;
		}

		// Header row — first non-empty line. Lowercase + trim each cell so
		// "Source" and " source " both map to "source".
		$headers      = null;
		$header_index = array();
		$line_number  = 0;
		while ( true ) {
			$row = fgetcsv( $handle );
			if ( false === $row ) {
				break;
			}
			++$line_number;
			if ( null === $row || ( count( $row ) === 1 && '' === trim( (string) $row[0] ) ) ) {
				continue; // Skip blank lines.
			}
			$headers = array_map(
				static function ( $h ) {
					return strtolower( trim( (string) $h ) );
				},
				$row
			);
			break;
		}
		if ( null === $headers ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$result['errors'][] = array(
				'row'    => 0,
				'reason' => __( 'CSV file is empty.', 'cf-qr-redirect' ),
			);
			return $result;
		}
		foreach ( self::$required_headers as $needed ) {
			if ( ! in_array( $needed, $headers, true ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$result['errors'][] = array(
					'row'    => $line_number,
					'reason' => sprintf(
						/* translators: %s = required column name */
						__( 'CSV is missing the required column "%s".', 'cf-qr-redirect' ),
						$needed
					),
				);
				return $result;
			}
		}
		foreach ( $headers as $h ) {
			if ( '' !== $h && ! in_array( $h, self::$known_headers, true ) ) {
				$result['warnings'][] = sprintf(
					/* translators: %s = unrecognized column name */
					__( 'Unrecognized column "%s" was ignored.', 'cf-qr-redirect' ),
					$h
				);
			}
		}
		$header_index = array_flip( $headers );

		$rows_processed = 0;
		while ( true ) {
			$row = fgetcsv( $handle );
			if ( false === $row ) {
				break;
			}
			++$line_number;
			if ( count( $row ) === 1 && '' === trim( (string) $row[0] ) ) {
				continue; // Skip blank lines.
			}
			if ( $rows_processed >= self::MAX_ROWS ) {
				$result['warnings'][] = sprintf(
					/* translators: %d = MAX_ROWS cap */
					__( 'Row cap reached at %d. Remaining rows in the file were not imported.', 'cf-qr-redirect' ),
					(int) self::MAX_ROWS
				);
				break;
			}
			++$rows_processed;

			$parsed = self::extract_row( $row, $header_index );
			$import = self::import_row( $parsed, $line_number, $duplicate_policy );
			if ( null !== $import['error'] ) {
				$result['errors'][] = array(
					'row'    => $line_number,
					'reason' => $import['error'],
				);
				continue;
			}
			switch ( $import['action'] ) {
				case 'imported':
					++$result['imported'];
					break;
				case 'updated':
					++$result['updated'];
					break;
				case 'skipped':
					++$result['skipped'];
					break;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// One cache flush at the end is much cheaper than busting after every
		// insert. The router will rebuild its map on next request.
		if ( class_exists( 'CFQR_Redirect_Router' ) ) {
			CFQR_Redirect_Router::invalidate_cache();
		}

		return $result;
	}

	/**
	 * Extract a row into a normalized assoc array keyed by header name.
	 * Missing columns become empty strings.
	 */
	public static function extract_row( $row, $header_index ) {
		$out = array();
		foreach ( self::$known_headers as $header ) {
			$out[ $header ] = isset( $header_index[ $header ], $row[ $header_index[ $header ] ] )
				? trim( (string) $row[ $header_index[ $header ] ] )
				: '';
		}
		return $out;
	}

	/**
	 * Import a single parsed row. Validates, then inserts (or updates if
	 * duplicate-policy='update'). Returns
	 *   ['action' => 'imported'|'updated'|'skipped', 'error' => null]
	 * or
	 *   ['action' => null, 'error' => '<translated reason>']
	 */
	public static function import_row( $parsed, $line_number, $duplicate_policy = 'skip' ) {
		// --- Mode (default: exact) ------------------------------------------
		$mode = '' !== $parsed['mode']
			? CFQR_Redirect_CPT::sanitize_match_mode( $parsed['mode'] )
			: CFQR_Redirect_CPT::MATCH_EXACT;

		// --- Source ----------------------------------------------------------
		$source = CFQR_Redirect_CPT::sanitize_source_path( $parsed['source'], $mode );
		if ( '' === $source ) {
			return array(
				'action' => null,
				'error'  => __( 'source is empty.', 'cf-qr-redirect' ),
			);
		}

		// --- Per-mode source validation -------------------------------------
		$source_error = self::validate_source_for_import( $source, $mode );
		if ( null !== $source_error ) {
			return array(
				'action' => null,
				'error'  => $source_error,
			);
		}

		// --- Destination -----------------------------------------------------
		$dest_raw = trim( (string) $parsed['destination'] );
		$dest     = '' !== $dest_raw ? esc_url_raw( $dest_raw ) : '';
		if ( '' === $dest ) {
			return array(
				'action' => null,
				'error'  => __( 'destination is empty.', 'cf-qr-redirect' ),
			);
		}
		if ( ! CFQR_URL::is_safe_destination( $dest ) ) {
			return array(
				'action' => null,
				'error'  => __( 'destination is not a safe http(s) URL.', 'cf-qr-redirect' ),
			);
		}

		// --- Status / active / schedule -------------------------------------
		$status = '' !== $parsed['status']
			? CFQR_Redirect_CPT::sanitize_status_code( (int) $parsed['status'] )
			: 302;
		$active = '' === $parsed['active'] ? 1 : ( CFQR_Redirect_CPT::sanitize_bool( $parsed['active'] ) ? 1 : 0 );
		$from   = '' !== $parsed['active_from'] ? CFQR_Redirect_CPT::sanitize_iso8601( $parsed['active_from'] ) : '';
		$until  = '' !== $parsed['active_until'] ? CFQR_Redirect_CPT::sanitize_iso8601( $parsed['active_until'] ) : '';
		if ( '' !== $from && '' !== $until && strtotime( $until ) <= strtotime( $from ) ) {
			return array(
				'action' => null,
				'error'  => __( 'active_until must be after active_from.', 'cf-qr-redirect' ),
			);
		}

		// --- Duplicate detection (exact mode only) --------------------------
		$existing_id = 0;
		if ( CFQR_Redirect_CPT::MATCH_EXACT === $mode ) {
			$existing_id = self::find_existing_exact( CFQR_Redirect_CPT::normalize_path( $source ) );
			if ( $existing_id && 'skip' === $duplicate_policy ) {
				return array(
					'action' => 'skipped',
					'error'  => null,
				);
			}
		}

		// --- Label -----------------------------------------------------------
		$label = '' !== $parsed['label'] ? sanitize_text_field( $parsed['label'] ) : $source;

		// --- Insert or update post ------------------------------------------
		$post_args = array(
			'post_type'   => CFQR_REDIRECT_POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $label,
		);
		if ( $existing_id > 0 ) {
			$post_args['ID'] = $existing_id;
			$post_id         = wp_update_post( $post_args, true );
			$action          = 'updated';
		} else {
			$post_id = wp_insert_post( $post_args, true );
			$action  = 'imported';
		}
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return array(
				'action' => null,
				'error'  => __( 'Failed to save row (WordPress error).', 'cf-qr-redirect' ),
			);
		}

		// --- Meta ------------------------------------------------------------
		update_post_meta( $post_id, CFQR_Redirect_CPT::META_SOURCE_PATH, $source );
		update_post_meta(
			$post_id,
			CFQR_Redirect_CPT::META_SOURCE_NORMALIZED,
			CFQR_Redirect_CPT::MATCH_EXACT === $mode ? CFQR_Redirect_CPT::normalize_path( $source ) : ''
		);
		update_post_meta( $post_id, CFQR_Redirect_CPT::META_DESTINATION, $dest );
		update_post_meta( $post_id, CFQR_Redirect_CPT::META_MATCH_MODE, $mode );
		update_post_meta( $post_id, CFQR_Redirect_CPT::META_STATUS_CODE, $status );
		update_post_meta( $post_id, CFQR_Redirect_CPT::META_ACTIVE, $active );
		update_post_meta( $post_id, CFQR_Redirect_CPT::META_ACTIVE_FROM, $from );
		update_post_meta( $post_id, CFQR_Redirect_CPT::META_ACTIVE_UNTIL, $until );

		// --- Groups ----------------------------------------------------------
		if ( '' !== $parsed['groups'] ) {
			$group_names = array_filter( array_map( 'trim', explode( ',', $parsed['groups'] ) ) );
			if ( ! empty( $group_names ) ) {
				wp_set_object_terms( $post_id, $group_names, CFQR_REDIRECT_TAXONOMY, false );
			}
		}

		return array(
			'action' => $action,
			'error'  => null,
		);
	}

	/**
	 * Lightweight per-mode source validation suitable for import context.
	 * The admin save-handler's validate_source() runs at HTTP request scope
	 * with $post_id and $wpdb dependencies; this one trades the duplicate
	 * check (the caller does that) for a faster pure-function shape check.
	 */
	public static function validate_source_for_import( $source, $mode ) {
		if ( CFQR_Redirect_CPT::MATCH_REGEX === $mode ) {
			if ( strlen( $source ) > CFQR_Redirect_Router::REGEX_MAX_PATTERN_LENGTH ) {
				return sprintf(
					/* translators: %d = max regex pattern length */
					__( 'regex pattern is too long (max %d chars).', 'cf-qr-redirect' ),
					CFQR_Redirect_Router::REGEX_MAX_PATTERN_LENGTH
				);
			}
			if ( null === CFQR_Redirect_Router::compile_user_regex( $source ) ) {
				return __( 'regex pattern does not compile.', 'cf-qr-redirect' );
			}
			return null;
		}
		if ( CFQR_Redirect_CPT::MATCH_WILDCARD === $mode && false === strpos( $source, '*' ) ) {
			return __( 'wildcard mode requires at least one * in the source.', 'cf-qr-redirect' );
		}
		if ( CFQR_Redirect_CPT::MATCH_QUERY_AWARE === $mode && false === strpos( $source, '?' ) ) {
			return __( 'query_aware mode requires a query string in the source.', 'cf-qr-redirect' );
		}
		$normalized = CFQR_Redirect_CPT::normalize_path( $source );
		if ( '/' === $normalized ) {
			return __( 'source path "/" is too broad to redirect.', 'cf-qr-redirect' );
		}
		$first         = explode( '/', ltrim( $normalized, '/' ), 2 )[0];
		$first_literal = str_replace( '*', '', $first );
		$reserved      = array( CFQR_REWRITE_SLUG, 'wp-admin', 'wp-login.php', 'wp-content', 'wp-includes', 'wp-json', 'wp-cron.php', 'xmlrpc.php' );
		if ( '' !== $first_literal && in_array( $first_literal, $reserved, true ) ) {
			return sprintf(
				/* translators: %s = reserved path segment */
				__( 'source starts with reserved segment "%s".', 'cf-qr-redirect' ),
				$first_literal
			);
		}
		return null;
	}

	/**
	 * Look up an existing exact-mode redirect by normalized source path.
	 * Returns 0 if no match.
	 */
	private static function find_existing_exact( $normalized_source ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s
				   AND pm.meta_value = %s
				   AND p.post_type = %s
				   AND p.post_status NOT IN ('trash','auto-draft')
				 LIMIT 1",
				CFQR_Redirect_CPT::META_SOURCE_NORMALIZED,
				$normalized_source,
				CFQR_REDIRECT_POST_TYPE
			)
		);
		return (int) $id;
	}

	private static function flash_and_redirect( $payload ) {
		$key = 'cfqr_rd_import_' . get_current_user_id();
		set_transient( $key, $payload, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=cfqr-redirect-import' ) );
		exit;
	}

	private static function render_summary( $payload ) {
		if ( isset( $payload['error'] ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( (string) $payload['error'] )
			);
			return;
		}
		$imported = (int) ( $payload['imported'] ?? 0 );
		$updated  = (int) ( $payload['updated'] ?? 0 );
		$skipped  = (int) ( $payload['skipped'] ?? 0 );
		$errors   = is_array( $payload['errors'] ?? null ) ? $payload['errors'] : array();
		$warnings = is_array( $payload['warnings'] ?? null ) ? $payload['warnings'] : array();

		$type = empty( $errors ) ? 'success' : 'warning';
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?>">
			<p>
				<?php
				printf(
					/* translators: 1: imported, 2: updated, 3: skipped, 4: error count */
					esc_html__( 'Imported %1$d, updated %2$d, skipped %3$d. Errors: %4$d.', 'cf-qr-redirect' ),
					(int) $imported,
					(int) $updated,
					(int) $skipped,
					(int) count( $errors )
				);
				?>
			</p>
			<?php if ( ! empty( $warnings ) ) : ?>
				<ul>
					<?php foreach ( $warnings as $w ) : ?>
						<li><?php echo esc_html( $w ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $errors ) ) : ?>
				<details>
					<summary><?php esc_html_e( 'Error details', 'cf-qr-redirect' ); ?></summary>
					<ul>
						<?php foreach ( $errors as $err ) : ?>
							<li>
								<?php
								printf(
									/* translators: 1: row number, 2: reason */
									esc_html__( 'Row %1$d: %2$s', 'cf-qr-redirect' ),
									(int) ( $err['row'] ?? 0 ),
									esc_html( (string) ( $err['reason'] ?? '' ) )
								);
								?>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}
}
