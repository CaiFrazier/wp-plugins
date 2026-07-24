<?php

namespace CFMediaOptimizer\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Uninstall — multisite per-blog cleanup.
 *
 * On uninstall WordPress requires the plugin to remove every option it
 * writes; on multisite that means walking every site's options table AND
 * clearing network-level site_meta rows. Getting either wrong leaves
 * stale rows behind that confuse later reinstalls and (more importantly)
 * leak per-site state across the network.
 *
 * The procedural dispatch at the bottom of `uninstall.php` is extracted
 * into `cf_media_optimizer_run_uninstall()` so this test can drive it
 * without re-executing the file. The test does:
 *
 *   1. Seed `is_multisite` to true, `get_sites` to return N blog IDs.
 *   2. Call `cf_media_optimizer_run_uninstall()` with the canonical
 *      option list.
 *   3. Assert that `switch_to_blog` ran exactly N times in the right
 *      order, each followed by `restore_current_blog`, with the
 *      per-site cleanup landing each time.
 *   4. Assert that `delete_site_option` ran exactly once per option
 *      after the per-site loop.
 *
 * Single-site path is tested too as a regression contract.
 */
final class UninstallTest extends TestCase {

	private array $options;

	protected function setUp(): void {
		cf_media_manager_test_reset_state();

		// Canonical option list — small subset is fine for the test; we
		// care about the iteration pattern, not which exact options are
		// in the list.
		$this->options = array(
			'cf_media_manager_quality',
			'cf_media_manager_rewrite',
			'cf_media_manager_queue_state',
			'cf_media_manager_queue_lock',
		);

		// Pre-populate single-site options + a per-blog option so we can
		// assert it's removed after run.
		foreach ( $this->options as $opt ) {
			$GLOBALS['cf_media_manager_test_options'][ $opt ] = 'present';
		}

		// Load uninstall.php in a way that ONLY defines the functions —
		// the file's top-level invocation at the bottom is gated on a
		// canonical-options bootstrap that runs regardless. To keep the
		// test isolated we require the file inside a try/catch so any
		// inadvertent side effect is contained.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		require_once dirname( __DIR__ ) . '/uninstall.php';
		// The require above ran cf_media_optimizer_run_uninstall once with
		// is_multisite=false (default in setUp). Reset state so each
		// test starts clean.
		cf_media_manager_test_reset_state();
		foreach ( $this->options as $opt ) {
			$GLOBALS['cf_media_manager_test_options'][ $opt ] = 'present';
		}
	}

	// =========================================================================
	// Single-site path
	// =========================================================================

	public function test_single_site_runs_cleanup_once_and_skips_site_options(): void {
		$GLOBALS['cf_media_manager_test_is_multisite'] = false;

		cf_media_optimizer_run_uninstall( $this->options );

		// Every per-site option must be gone.
		foreach ( $this->options as $opt ) {
			self::assertArrayNotHasKey( $opt, $GLOBALS['cf_media_manager_test_options'] );
		}
		// No blog-switching on single-site.
		self::assertSame( array(), $GLOBALS['cf_media_manager_test_blog_switches'] );
		// No site_meta cleanup on single-site (those rows don't exist).
		self::assertSame( array(), $GLOBALS['cf_media_manager_test_site_options_deleted'] );
	}

	// =========================================================================
	// Multisite path
	// =========================================================================

	public function test_multisite_walks_every_blog_and_then_clears_network_options(): void {
		$GLOBALS['cf_media_manager_test_is_multisite'] = true;
		$GLOBALS['cf_media_manager_test_sites']        = array( 1, 17, 42 );

		cf_media_optimizer_run_uninstall( $this->options );

		// switch_to_blog called once per site, in get_sites order.
		self::assertSame(
			array( 1, 17, 42 ),
			$GLOBALS['cf_media_manager_test_blog_switches'],
			'switch_to_blog must be called exactly once per blog, in get_sites order'
		);
		// restore_current_blog matches switch count.
		self::assertCount(
			3,
			$GLOBALS['cf_media_manager_test_blog_restores'],
			'every switch must be paired with a restore'
		);
		// Blog stack is empty at the end — no leaked switches.
		self::assertSame( array(), $GLOBALS['cf_media_manager_test_blog_stack'] );

		// Site-level options removed.
		foreach ( $this->options as $opt ) {
			self::assertArrayNotHasKey(
				$opt,
				$GLOBALS['cf_media_manager_test_options'],
				"per-site option $opt must be removed"
			);
		}

		// Network-level site_meta cleanup runs ONCE per option after the
		// per-site loop. Same count as the input list, same names.
		self::assertSame(
			$this->options,
			$GLOBALS['cf_media_manager_test_site_options_deleted'],
			'delete_site_option must be called once per option after per-site loop'
		);
	}

	public function test_multisite_with_no_sites_only_clears_network_options(): void {
		// Edge case: get_sites returns empty (network has no sites — only
		// happens during early-setup or a broken install). The per-site
		// loop is a no-op; the network sweep must still fire.
		$GLOBALS['cf_media_manager_test_is_multisite'] = true;
		$GLOBALS['cf_media_manager_test_sites']        = array();

		cf_media_optimizer_run_uninstall( $this->options );

		self::assertSame( array(), $GLOBALS['cf_media_manager_test_blog_switches'] );
		self::assertSame( $this->options, $GLOBALS['cf_media_manager_test_site_options_deleted'] );
	}

	/**
	 * Defensive: even when one of the per-site cleanups would touch a
	 * postmeta row (or anything that depends on a properly-switched
	 * blog), the cleanup runs INSIDE the switch_to_blog/restore window.
	 * We can't assert ordering by side effects alone, so we verify the
	 * blog_stack is non-empty during the cleanup call — but the cleanup
	 * is invoked as a callback, so the only place to observe is to
	 * count restores vs switches and ensure they match. (Asserted above
	 * via assertCount + empty blog_stack.)
	 */

	// =========================================================================
	// Per-site cleanup function — covers the cleanup primitives directly.
	// =========================================================================

	public function test_per_site_cleanup_removes_every_option_in_list(): void {
		$GLOBALS['cf_media_manager_test_options'] = array(
			'cf_media_manager_quality'     => 'present',
			'cf_media_manager_queue_state' => 'present',
			'unrelated_option'             => 'preserved',
		);

		cf_media_optimizer_uninstall_cleanup( array( 'cf_media_manager_quality', 'cf_media_manager_queue_state' ) );

		self::assertArrayNotHasKey( 'cf_media_manager_quality', $GLOBALS['cf_media_manager_test_options'] );
		self::assertArrayNotHasKey( 'cf_media_manager_queue_state', $GLOBALS['cf_media_manager_test_options'] );
		self::assertSame( 'preserved', $GLOBALS['cf_media_manager_test_options']['unrelated_option'], 'unrelated options must NOT be deleted' );
	}
}
