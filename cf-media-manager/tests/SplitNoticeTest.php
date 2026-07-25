<?php

namespace CFMediaManager\Tests;

use CFMediaManager\SplitNotice;
use PHPUnit\Framework\TestCase;

/**
 * Covers the decision logic — the part that decides whether a 2.x site is
 * silently missing its conversion half. Rendering is a thin escaped-markup
 * wrapper over should_show() and isn't worth a DOM assertion; the conditions
 * are what carry the risk.
 *
 * The notice must be self-limiting in both directions: it has to fire for a
 * real 2.x upgrader, and it must never fire on a fresh 3.0.0 install (a false
 * positive there tells a user they lost something they never had).
 */
final class SplitNoticeTest extends TestCase {

	protected function setUp(): void {
		cf_media_manager_test_reset_state();
		// The notice targets people who can act on it.
		$GLOBALS['cf_media_manager_test_user_caps'] = array( 'activate_plugins' => true );
		$GLOBALS['cf_media_manager_test_current_user'] = 7;
	}

	// -----------------------------------------------------------------------
	// legacy_conversion_state_present
	// -----------------------------------------------------------------------

	public function test_fresh_install_has_no_legacy_state(): void {
		self::assertFalse( SplitNotice::legacy_conversion_state_present() );
	}

	public function test_saved_2x_settings_count_as_legacy_state(): void {
		update_option( 'cf_media_manager_quality', 82 );
		self::assertTrue( SplitNotice::legacy_conversion_state_present() );
	}

	public function test_queue_state_counts_as_legacy_state(): void {
		update_option( 'cf_media_manager_queue_state', array( 'ids' => array( 1, 2 ) ) );
		self::assertTrue( SplitNotice::legacy_conversion_state_present() );
	}

	/**
	 * The pre-rename May 2026 optimizer keys have to count too, or sites
	 * carrying the oldest variant data get misread as fresh.
	 */
	public function test_pre_rename_optimizer_keys_count_as_legacy_state(): void {
		update_option( 'cf_media_optimizer_backfill_done', 1 );
		self::assertTrue( SplitNotice::legacy_conversion_state_present() );
	}

	/**
	 * A rewrite toggle explicitly saved as "off" is still 2.x lineage. Guards
	 * against a falsy-value probe (get_option default false) reading a real
	 * stored false as "absent".
	 */
	public function test_falsy_stored_value_still_counts_as_legacy_state(): void {
		update_option( 'cf_media_manager_rewrite', 0 );
		self::assertTrue( SplitNotice::legacy_conversion_state_present() );
	}

	// -----------------------------------------------------------------------
	// should_show
	// -----------------------------------------------------------------------

	public function test_shows_for_2x_upgrader_without_optimizer(): void {
		update_option( 'cf_media_manager_quality', 82 );
		self::assertTrue( SplitNotice::should_show() );
	}

	public function test_does_not_show_on_fresh_install(): void {
		self::assertFalse( SplitNotice::should_show() );
	}

	public function test_does_not_show_without_the_capability(): void {
		update_option( 'cf_media_manager_quality', 82 );
		$GLOBALS['cf_media_manager_test_user_caps'] = array();
		self::assertFalse( SplitNotice::should_show() );
	}

	public function test_does_not_show_once_dismissed(): void {
		update_option( 'cf_media_manager_quality', 82 );
		update_user_meta( 7, SplitNotice::DISMISSED_META, 1 );
		self::assertFalse( SplitNotice::should_show() );
	}

	// -----------------------------------------------------------------------
	// handle_dismiss
	// -----------------------------------------------------------------------

	public function test_dismiss_persists_and_silences_the_notice(): void {
		update_option( 'cf_media_manager_quality', 82 );
		self::assertTrue( SplitNotice::should_show() );

		$_GET[ SplitNotice::DISMISS_ARG ] = '1';
		$_GET['_wpnonce']                 = 'valid-nonce';
		( new SplitNotice() )->handle_dismiss();
		unset( $_GET[ SplitNotice::DISMISS_ARG ], $_GET['_wpnonce'] );

		self::assertTrue( SplitNotice::dismissed() );
		self::assertFalse( SplitNotice::should_show() );
	}

	public function test_dismiss_is_ignored_without_a_valid_nonce(): void {
		update_option( 'cf_media_manager_quality', 82 );

		$_GET[ SplitNotice::DISMISS_ARG ]                 = '1';
		$_GET['_wpnonce']                                 = 'forged';
		$GLOBALS['cf_media_manager_test_nonce_invalid']   = true;
		( new SplitNotice() )->handle_dismiss();
		unset( $_GET[ SplitNotice::DISMISS_ARG ], $_GET['_wpnonce'] );

		self::assertFalse( SplitNotice::dismissed() );
		self::assertTrue( SplitNotice::should_show() );
	}

	public function test_dismiss_is_ignored_without_the_capability(): void {
		update_option( 'cf_media_manager_quality', 82 );
		$GLOBALS['cf_media_manager_test_user_caps'] = array();

		$_GET[ SplitNotice::DISMISS_ARG ] = '1';
		$_GET['_wpnonce']                 = 'valid-nonce';
		( new SplitNotice() )->handle_dismiss();
		unset( $_GET[ SplitNotice::DISMISS_ARG ], $_GET['_wpnonce'] );

		self::assertFalse( SplitNotice::dismissed() );
	}

	public function test_dismiss_is_a_noop_without_the_query_arg(): void {
		update_option( 'cf_media_manager_quality', 82 );
		( new SplitNotice() )->handle_dismiss();
		self::assertFalse( SplitNotice::dismissed() );
	}

	// -----------------------------------------------------------------------
	// Sentinel parity with CF Media Optimizer
	// -----------------------------------------------------------------------

	/**
	 * The sentinel list is duplicated from the Optimizer's own
	 * Plugin::is_fresh_install(). The two halves must agree on what counts as
	 * a converting site; this pins the set so a future edit to one side is a
	 * deliberate, visible change rather than silent drift.
	 */
	public function test_sentinel_set_is_pinned(): void {
		self::assertSame(
			array(
				'cf_media_manager_quality',
				'cf_media_manager_rewrite',
				'cf_media_manager_queue_state',
				'cf_media_manager_backfill_done',
				'cf_media_optimizer_quality',
				'cf_media_optimizer_rewrite',
				'cf_media_optimizer_queue_state',
				'cf_media_optimizer_backfill_done',
			),
			SplitNotice::LEGACY_SENTINELS
		);
	}
}
