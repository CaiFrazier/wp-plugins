<?php
/**
 * Admin — registers the Posts → List View submenu page, enqueues assets,
 * and handles the CSV export action.
 *
 * @package CF_Post_List_View
 */

namespace CFPostListView;

class Admin {

	/** The WP admin hook suffix returned by add_posts_page(). */
	private string $hook_suffix = '';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_init', [ $this, 'handle_csv_export' ] );
	}

	public function add_menu(): void {
		$this->hook_suffix = (string) add_posts_page(
			__( 'Post List View', 'cf-post-list-view' ),
			__( 'List View', 'cf-post-list-view' ),
			'edit_posts',
			'cf-post-list-view',
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'cf-plv-admin',
			CF_PLV_URL . 'assets/css/admin.css',
			[],
			CF_PLV_VERSION
		);

		wp_enqueue_script(
			'cf-plv-admin',
			CF_PLV_URL . 'assets/js/admin.js',
			[],
			CF_PLV_VERSION,
			true
		);

		wp_localize_script(
			'cf-plv-admin',
			'cfPlvData',
			[
				'restUrl'       => esc_url_raw( rest_url( RestController::NAMESPACE . RestController::ROUTE ) ),
				'postTypesUrl'  => esc_url_raw( rest_url( RestController::NAMESPACE . RestController::POST_TYPES_ROUTE ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'columns'       => ColumnRegistry::all(),
				'defaults'      => ColumnRegistry::defaults(),
				'exportUrl'     => admin_url( 'edit.php?page=cf-post-list-view&cf_plv_export=csv' ),
				'exportNonce'   => wp_create_nonce( 'cf_plv_export_csv' ),
			]
		);
	}

	public function render_page(): void {
		?>
		<div class="wrap cf-plv-wrap">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Post List View', 'cf-post-list-view' ); ?>
			</h1>
			<span class="cf-plv-version">
				v<?php echo esc_html( CF_PLV_VERSION ); ?>
			</span>

			<div class="cf-plv-toolbar">
				<div class="cf-plv-toolbar-left">
					<input
						type="search"
						id="cf-plv-search"
						class="cf-plv-search"
						placeholder="<?php esc_attr_e( 'Search title or slug…', 'cf-post-list-view' ); ?>"
					/>
					<select id="cf-plv-post-type" class="cf-plv-select cf-plv-post-type-select">
						<option value=""><?php esc_html_e( 'Loading…', 'cf-post-list-view' ); ?></option>
					</select>
					<select id="cf-plv-status" class="cf-plv-select">
						<option value=""><?php esc_html_e( 'All statuses', 'cf-post-list-view' ); ?></option>
						<option value="publish"><?php esc_html_e( 'Published', 'cf-post-list-view' ); ?></option>
						<option value="draft"><?php esc_html_e( 'Draft', 'cf-post-list-view' ); ?></option>
						<option value="pending"><?php esc_html_e( 'Pending Review', 'cf-post-list-view' ); ?></option>
						<option value="future"><?php esc_html_e( 'Scheduled', 'cf-post-list-view' ); ?></option>
						<option value="private"><?php esc_html_e( 'Private', 'cf-post-list-view' ); ?></option>
						<option value="trash"><?php esc_html_e( 'Trash', 'cf-post-list-view' ); ?></option>
					</select>
				</div>
				<div class="cf-plv-toolbar-right">
					<select id="cf-plv-per-page" class="cf-plv-select">
						<option value="25">25 / <?php esc_html_e( 'page', 'cf-post-list-view' ); ?></option>
						<option value="50" selected>50 / <?php esc_html_e( 'page', 'cf-post-list-view' ); ?></option>
						<option value="100">100 / <?php esc_html_e( 'page', 'cf-post-list-view' ); ?></option>
						<option value="200">200 / <?php esc_html_e( 'page', 'cf-post-list-view' ); ?></option>
					</select>
					<button type="button" id="cf-plv-columns-btn" class="button">
						<?php esc_html_e( 'Columns', 'cf-post-list-view' ); ?> &#9663;
					</button>
					<button type="button" id="cf-plv-export-btn" class="button button-secondary">
						&#8659; <?php esc_html_e( 'Export CSV', 'cf-post-list-view' ); ?>
					</button>
				</div>
			</div>

			<div id="cf-plv-summary" class="cf-plv-summary"></div>

			<div class="cf-plv-table-wrap">
				<table
					class="wp-list-table widefat fixed striped cf-plv-table"
					id="cf-plv-table"
				>
					<thead id="cf-plv-thead"><tr></tr></thead>
					<tbody id="cf-plv-tbody">
						<tr>
							<td colspan="99" class="cf-plv-loading">
								<?php esc_html_e( 'Loading…', 'cf-post-list-view' ); ?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="tablenav bottom" id="cf-plv-pagination"></div>
		</div>

		<!-- Column selector modal (populated by JS) -->
		<div
			id="cf-plv-modal"
			class="cf-plv-modal"
			role="dialog"
			aria-modal="true"
			aria-label="<?php esc_attr_e( 'Select columns', 'cf-post-list-view' ); ?>"
			hidden
		>
			<div class="cf-plv-modal-backdrop"></div>
			<div class="cf-plv-modal-panel">
				<div class="cf-plv-modal-header">
					<h2><?php esc_html_e( 'Select Columns', 'cf-post-list-view' ); ?></h2>
					<button
						type="button"
						class="cf-plv-modal-close"
						aria-label="<?php esc_attr_e( 'Close', 'cf-post-list-view' ); ?>"
					>&#10005;</button>
				</div>
				<div class="cf-plv-modal-body" id="cf-plv-modal-body"></div>
				<div class="cf-plv-modal-footer">
					<button type="button" id="cf-plv-modal-reset" class="button">
						<?php esc_html_e( 'Reset to Defaults', 'cf-post-list-view' ); ?>
					</button>
					<div class="cf-plv-modal-footer-right">
						<button type="button" id="cf-plv-modal-cancel" class="button">
							<?php esc_html_e( 'Cancel', 'cf-post-list-view' ); ?>
						</button>
						<button type="button" id="cf-plv-modal-apply" class="button button-primary">
							<?php esc_html_e( 'Apply', 'cf-post-list-view' ); ?>
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
			'cf-post-list-view' !== $_GET['page'] ||
			empty( $_GET['cf_plv_export'] ) ||
			'csv' !== $_GET['cf_plv_export']
		) {
			return;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'cf-post-list-view' ) );
		}

		check_admin_referer( 'cf_plv_export_csv' );

		$col_param   = isset( $_GET['cols'] ) ? sanitize_text_field( wp_unslash( $_GET['cols'] ) ) : '';
		$search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$post_type   = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post';
		$post_status = isset( $_GET['post_status'] ) ? sanitize_key( wp_unslash( $_GET['post_status'] ) ) : '';

		$columns = CsvExporter::resolve_columns( $col_param, $post_type );
		$args    = CsvExporter::build_query_args( $search, $post_type, $post_status );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( CsvExporter::filename( $post_type ) ) . '"' );
		header( 'Pragma: no-cache' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, CsvExporter::headers( $columns ) );

		$query = new \WP_Query( $args );
		foreach ( CsvExporter::rows( $query->posts, $columns ) as $row ) {
			fputcsv( $out, $row );
		}

		fclose( $out );
		exit;
	}
}
