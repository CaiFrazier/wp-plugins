<?php
/**
 * AuditPage — renders the Audit tab content inside AdminPage::render_page().
 *
 * The page is rendered as a thin server-side skeleton. JS hydrates the
 * dashboard cards by calling the AJAX endpoints in AuditAjax. URL-hash
 * routing flips the same DOM between dashboard and detail views without
 * navigating away from the Manager admin page.
 *
 * The Missing Alt Text card is a special case: it's a "health signal"
 * link card rather than a real audit report. Clicking through deep-links
 * to the existing Accessibility tab — we never reimplement the alt-text
 * editor in the audit subsystem.
 */

namespace CFMediaManager;

use CFMediaManager\Audit\AuditRunner;

defined( 'ABSPATH' ) || exit;

final class AuditPage {

	private AuditRunner $runner;

	public function __construct( AuditRunner $runner ) {
		$this->runner = $runner;
	}

	/**
	 * Render the Audit tab's panel content. Called from AdminPage::render_page()
	 * inside the existing `#cf-media-manager-panel-library` markup, so this
	 * method emits the dashboard shell only — no <div class="wrap"> wrapper.
	 */
	public function render_section(): void {
		?>
		<div class="cf-audit-root" data-cf-audit-root>

			<!-- ============================================================
				 Dashboard view — card grid
			============================================================ -->
			<section class="cf-audit-dashboard" data-cf-audit-view="dashboard">
				<header class="cf-audit-toolbar">
					<h2><?php esc_html_e( 'Library Audit', 'cf-media-manager' ); ?></h2>
					<div class="cf-audit-toolbar-actions">
						<span class="cf-audit-last-scan" data-cf-audit-last-all-scan></span>
						<button type="button" class="button" data-cf-audit-rescan-all>
							<?php esc_html_e( 'Rescan all', 'cf-media-manager' ); ?>
						</button>
					</div>
				</header>

				<p class="description cf-audit-intro">
					<?php
					esc_html_e(
						'Health reports across your media library. Every flagged item carries the provenance the report used to flag it — read the receipts before acting.',
						'cf-media-manager'
					);
					?>
				</p>

				<div class="cf-audit-cards" data-cf-audit-cards>
					<!-- JS injects card markup here. The empty state below
						 shows while the first dashboard fetch is in flight. -->
					<div class="cf-audit-cards-loading">
						<?php esc_html_e( 'Loading audit dashboard…', 'cf-media-manager' ); ?>
					</div>
				</div>
			</section>

			<!-- ============================================================
				 Detail view — full-pane takeover for one report
			============================================================ -->
			<section class="cf-audit-detail" data-cf-audit-view="detail" hidden>
				<header class="cf-audit-detail-header">
					<button type="button" class="button-link cf-audit-back" data-cf-audit-back>
						&larr; <?php esc_html_e( 'Audit', 'cf-media-manager' ); ?>
					</button>
					<h2 data-cf-audit-detail-title>&nbsp;</h2>
					<div class="cf-audit-detail-meta" data-cf-audit-detail-meta></div>
				</header>

				<div class="cf-audit-detail-actionbar" data-cf-audit-actionbar hidden>
					<label class="cf-audit-select-all">
						<input type="checkbox" data-cf-audit-select-all>
						<?php esc_html_e( 'Select all on this page', 'cf-media-manager' ); ?>
					</label>
					<select data-cf-audit-bulk-action class="cf-audit-bulk-select"></select>
					<button type="button" class="button" data-cf-audit-bulk-apply disabled>
						<?php esc_html_e( 'Apply', 'cf-media-manager' ); ?>
					</button>
					<span class="cf-audit-bulk-status" data-cf-audit-bulk-status></span>
				</div>

				<div class="cf-audit-detail-table-wrap">
					<table class="widefat striped cf-audit-table" data-cf-audit-table>
						<thead>
							<tr>
								<th class="cf-audit-th-check"><span class="screen-reader-text"><?php esc_html_e( 'Select', 'cf-media-manager' ); ?></span></th>
								<th><?php esc_html_e( 'Item', 'cf-media-manager' ); ?></th>
								<th><?php esc_html_e( 'Details', 'cf-media-manager' ); ?></th>
								<th><?php esc_html_e( 'Receipts', 'cf-media-manager' ); ?></th>
							</tr>
						</thead>
						<tbody data-cf-audit-tbody>
							<tr>
								<td colspan="4" class="cf-audit-table-loading">
									<?php esc_html_e( 'Loading…', 'cf-media-manager' ); ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="cf-audit-detail-pagination" data-cf-audit-pagination></div>
			</section>

		</div>
		<?php
	}
}
