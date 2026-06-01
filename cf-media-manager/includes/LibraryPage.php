<?php
/**
 * LibraryPage — registers the Media → List View submenu page, enqueues the
 * library list view assets, and handles the CSV export.
 *
 * Separate from AdminPage (which owns the Media Manager settings screen)
 * because the list view is a full-page table experience with its own assets,
 * REST surface, and capability gate (`upload_files`, vs Manager's
 * `manage_options`).
 */

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

final class LibraryPage {

	const PAGE_SLUG       = 'cf-media-library';
	const HOOK_SUFFIX     = 'media_page_cf-media-library';
	const EXPORT_NONCE    = 'cf_media_manager_export_library_csv';
	const EXPORT_TRIGGER  = 'cf_media_library_export';

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_csv_export' ) );
	}

	public function admin_menu(): void {
		add_media_page(
			__( 'Media Library — List View', 'cf-media-manager' ),
			__( 'List View', 'cf-media-manager' ),
			'upload_files',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( self::HOOK_SUFFIX !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'cf-media-manager-library',
			plugins_url( 'assets/library.css', CF_MEDIA_MANAGER_FILE ),
			array(),
			CF_MEDIA_MANAGER_VERSION
		);

		wp_enqueue_script(
			'cf-media-manager-library',
			plugins_url( 'assets/library.js', CF_MEDIA_MANAGER_FILE ),
			array( 'wp-i18n' ),
			CF_MEDIA_MANAGER_VERSION,
			true
		);

		$languages_path = plugin_dir_path( CF_MEDIA_MANAGER_FILE ) . 'languages';
		wp_set_script_translations( 'cf-media-manager-library', 'cf-media-manager', $languages_path );

		wp_localize_script(
			'cf-media-manager-library',
			'cfMediaLibrary',
			array(
				'restUrl'     => esc_url_raw( rest_url( LibraryRestController::REST_NAMESPACE . LibraryRestController::ROUTE ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'columns'     => LibraryColumnRegistry::all(),
				'defaults'    => LibraryColumnRegistry::defaults(),
				'exportUrl'   => admin_url( 'upload.php?page=' . self::PAGE_SLUG . '&' . self::EXPORT_TRIGGER . '=csv' ),
				'exportNonce' => wp_create_nonce( self::EXPORT_NONCE ),
			)
		);
	}

	public function render_page(): void {
		?>
		<div class="wrap cf-mlv-wrap">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Media Library — List View', 'cf-media-manager' ); ?>
			</h1>
			<span class="cf-mlv-version">
				v<?php echo esc_html( CF_MEDIA_MANAGER_VERSION ); ?>
			</span>

			<div class="cf-mlv-toolbar">
				<div class="cf-mlv-toolbar-left">
					<input
						type="search"
						id="cf-mlv-search"
						class="cf-mlv-search"
						placeholder="<?php esc_attr_e( 'Search title or slug…', 'cf-media-manager' ); ?>"
					/>
					<select id="cf-mlv-mime" class="cf-mlv-select">
						<option value=""><?php esc_html_e( 'All types', 'cf-media-manager' ); ?></option>
						<option value="image"><?php esc_html_e( 'Images', 'cf-media-manager' ); ?></option>
						<option value="video"><?php esc_html_e( 'Video', 'cf-media-manager' ); ?></option>
						<option value="audio"><?php esc_html_e( 'Audio', 'cf-media-manager' ); ?></option>
						<option value="application/pdf">PDF</option>
						<option value="text"><?php esc_html_e( 'Text', 'cf-media-manager' ); ?></option>
					</select>
					<select id="cf-mlv-parent" class="cf-mlv-select">
						<option value=""><?php esc_html_e( 'All attachments', 'cf-media-manager' ); ?></option>
						<option value="unattached"><?php esc_html_e( 'Unattached only', 'cf-media-manager' ); ?></option>
					</select>
				</div>
				<div class="cf-mlv-toolbar-right">
					<select id="cf-mlv-per-page" class="cf-mlv-select">
						<option value="25">25 / <?php esc_html_e( 'page', 'cf-media-manager' ); ?></option>
						<option value="50" selected>50 / <?php esc_html_e( 'page', 'cf-media-manager' ); ?></option>
						<option value="100">100 / <?php esc_html_e( 'page', 'cf-media-manager' ); ?></option>
						<option value="200">200 / <?php esc_html_e( 'page', 'cf-media-manager' ); ?></option>
					</select>
					<button type="button" id="cf-mlv-columns-btn" class="button">
						<?php esc_html_e( 'Columns', 'cf-media-manager' ); ?> &#9663;
					</button>
					<button type="button" id="cf-mlv-export-btn" class="button button-secondary">
						&#8659; <?php esc_html_e( 'Export CSV', 'cf-media-manager' ); ?>
					</button>
				</div>
			</div>

			<div id="cf-mlv-summary" class="cf-mlv-summary"></div>

			<div class="cf-mlv-table-wrap">
				<table
					class="wp-list-table widefat fixed striped cf-mlv-table"
					id="cf-mlv-table"
				>
					<thead id="cf-mlv-thead"><tr></tr></thead>
					<tbody id="cf-mlv-tbody">
						<tr>
							<td colspan="99" class="cf-mlv-loading">
								<?php esc_html_e( 'Loading…', 'cf-media-manager' ); ?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="tablenav bottom" id="cf-mlv-pagination"></div>
		</div>

		<!-- Column selector modal (populated by JS) -->
		<div
			id="cf-mlv-modal"
			class="cf-mlv-modal"
			role="dialog"
			aria-modal="true"
			aria-label="<?php esc_attr_e( 'Select columns', 'cf-media-manager' ); ?>"
			hidden
		>
			<div class="cf-mlv-modal-backdrop"></div>
			<div class="cf-mlv-modal-panel">
				<div class="cf-mlv-modal-header">
					<h2><?php esc_html_e( 'Select Columns', 'cf-media-manager' ); ?></h2>
					<button
						type="button"
						class="cf-mlv-modal-close"
						aria-label="<?php esc_attr_e( 'Close', 'cf-media-manager' ); ?>"
					>&#10005;</button>
				</div>
				<div class="cf-mlv-modal-body" id="cf-mlv-modal-body"></div>
				<div class="cf-mlv-modal-footer">
					<button type="button" id="cf-mlv-modal-reset" class="button">
						<?php esc_html_e( 'Reset to Defaults', 'cf-media-manager' ); ?>
					</button>
					<div class="cf-mlv-modal-footer-right">
						<button type="button" id="cf-mlv-modal-cancel" class="button">
							<?php esc_html_e( 'Cancel', 'cf-media-manager' ); ?>
						</button>
						<button type="button" id="cf-mlv-modal-apply" class="button button-primary">
							<?php esc_html_e( 'Apply', 'cf-media-manager' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// CSV export
	// -------------------------------------------------------------------------

	public function handle_csv_export(): void {
		if (
			empty( $_GET['page'] ) ||
			self::PAGE_SLUG !== $_GET['page'] ||
			empty( $_GET[ self::EXPORT_TRIGGER ] ) ||
			'csv' !== $_GET[ self::EXPORT_TRIGGER ]
		) {
			return;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'cf-media-manager' ) );
		}

		check_admin_referer( self::EXPORT_NONCE );

		// Resolve columns and filters from the request (sanitized here,
		// shaped into query args + rows by LibraryCsvExporter).
		$col_param = isset( $_GET['cols'] )
			? sanitize_text_field( wp_unslash( $_GET['cols'] ) )
			: '';

		$search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$mime          = isset( $_GET['mime'] ) ? sanitize_text_field( wp_unslash( $_GET['mime'] ) ) : '';
		$parent_filter = isset( $_GET['parent'] ) ? sanitize_text_field( wp_unslash( $_GET['parent'] ) ) : '';

		$columns = LibraryCsvExporter::resolve_columns( $col_param );
		$args    = LibraryCsvExporter::build_query_args( $search, $mime, $parent_filter );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( LibraryCsvExporter::filename() ) . '"' );
		header( 'Pragma: no-cache' );
		header( 'X-Content-Type-Options: nosniff' );

		// php://output streaming: WP_Filesystem cannot stream to the HTTP
		// response body. fputcsv() is the canonical CSV writer; no WP
		// alternative exists for this code path.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( 'php://output', 'w' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		fputcsv( $out, LibraryCsvExporter::headers( $columns ) );

		$query = new \WP_Query( $args );
		// Rows are already CSV-injection-neutralised by LibraryCsvExporter::rows().
		foreach ( LibraryCsvExporter::rows( $query->posts, $columns ) as $row ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
			fputcsv( $out, $row );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		exit;
	}
}
