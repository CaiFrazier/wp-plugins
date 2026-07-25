<?php

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

/**
 * One-time upgrade notice for sites carrying conversion state from CF Media
 * Manager 2.x.
 *
 * 3.0.0 moved the delivery half (WebP/AVIF conversion + <picture> rewriting)
 * out to the separate CF Media Optimizer plugin. readme.txt covers the move in
 * its Description, FAQ, and changelog — but readme prose only reaches someone
 * who visits the plugin page. A site that updates in place and never opens it
 * simply stops serving modern formats with nothing on screen to say why. The
 * readme's `== Upgrade Notice ==` block covers the update screen; this class
 * covers the site itself.
 *
 * Fires only when all four hold: the visitor can act on it (activate_plugins),
 * the site carries conversion state written by 2.x, CF Media Optimizer is not
 * active, and the notice hasn't been dismissed. That makes it self-limiting —
 * fresh 3.0.0 installs never see it, and it goes away for good once the
 * Optimizer is installed or an admin dismisses it.
 *
 * Doctrine note: this is interop, not promotion (PRODUCT_PRINCIPLES.md bans
 * upsell surfaces outright). It names the plugin that now carries a capability
 * the site already had and was silently losing; it advertises nothing the user
 * didn't already have. Deliberately no install/search link — the notice
 * explains, it doesn't sell.
 */
final class SplitNotice {

	/**
	 * User meta flag set when an admin dismisses the notice. Per-user rather
	 * than site-wide: dismissal is "I have read this", which is personal, and
	 * a second admin still needs to see it.
	 */
	const DISMISSED_META = 'cf_media_manager_split_notice_dismissed';

	/**
	 * Query arg carrying the dismissal, paired with a nonce.
	 */
	const DISMISS_ARG = 'cf-media-manager-dismiss-split-notice';

	/**
	 * Nonce action for the dismissal link.
	 */
	const DISMISS_NONCE = 'cf_media_manager_dismiss_split_notice';

	/**
	 * Option keys that prove this site converted images under the 2.x lineage.
	 *
	 * Deliberately the same sentinel set CF Media Optimizer's own
	 * `Plugin::is_fresh_install()` uses, including the pre-rename
	 * `cf_media_optimizer_*` names from the original May 2026 optimizer, so
	 * both halves of the split agree on what "this site was converting" means.
	 * Keeping the two lists in sync matters: a site either half misreads as
	 * fresh is a site that silently loses variants.
	 *
	 * All of these are autoloaded, so the probe costs no extra query.
	 */
	const LEGACY_SENTINELS = array(
		'cf_media_manager_quality',
		'cf_media_manager_rewrite',
		'cf_media_manager_queue_state',
		'cf_media_manager_backfill_done',
		'cf_media_optimizer_quality',
		'cf_media_optimizer_rewrite',
		'cf_media_optimizer_queue_state',
		'cf_media_optimizer_backfill_done',
	);

	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'handle_dismiss' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render' ) );
	}

	/**
	 * True when this site carries conversion settings or queue state written
	 * by the 2.x lineage. A fresh 3.0.0 install writes none of these keys —
	 * the management half dropped them at the split — so their presence is a
	 * reliable "this site used to convert" signal.
	 */
	public static function legacy_conversion_state_present(): bool {
		foreach ( self::LEGACY_SENTINELS as $option ) {
			if ( false !== get_option( $option, false ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when the current user has permanently dismissed the notice.
	 * Unauthenticated contexts count as not dismissed; they're gated out by
	 * the capability check in should_show() anyway.
	 */
	public static function dismissed(): bool {
		if ( ! function_exists( 'get_current_user_id' ) || ! function_exists( 'get_user_meta' ) ) {
			return false;
		}
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}
		return (bool) get_user_meta( $user_id, self::DISMISSED_META, true );
	}

	/**
	 * The full decision. Kept pure and static so the conditions are testable
	 * without a WP runtime or a rendered page.
	 */
	public static function should_show(): bool {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return false;
		}
		if ( AdminPage::optimizer_active() ) {
			return false;
		}
		if ( ! self::legacy_conversion_state_present() ) {
			return false;
		}
		return ! self::dismissed();
	}

	/**
	 * Persist a dismissal. Nonce-verified because it writes user meta off a
	 * GET request.
	 */
	public function handle_dismiss(): void {
		if ( ! isset( $_GET[ self::DISMISS_ARG ] ) ) {
			return;
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::DISMISS_NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::DISMISSED_META, 1 );
		}
	}

	/**
	 * Render the notice when the conditions hold.
	 */
	public function maybe_render(): void {
		if ( ! self::should_show() ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( self::DISMISS_ARG, '1' ),
			self::DISMISS_NONCE
		);
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'CF Media Manager 3.0.0 no longer converts images.', 'cf-media-manager' ); ?></strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: %s: the CF Media Optimizer plugin name, emphasized. */
					esc_html__( 'WebP and AVIF conversion and %s delivery moved to a separate plugin, CF Media Optimizer. This site still has conversion settings from version 2.x, so those images are no longer being served in modern formats.', 'cf-media-manager' ),
					'<code>&lt;picture&gt;</code>'
				);
				?>
			</p>
			<p>
				<?php esc_html_e( 'Nothing was deleted. Your converted files, ownership records, and settings are untouched, and installing CF Media Optimizer picks delivery back up exactly where it left off. If you no longer want image conversion, you can ignore this.', 'cf-media-manager' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss this notice', 'cf-media-manager' ); ?></a>
			</p>
		</div>
		<?php
	}
}
