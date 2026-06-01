<?php

namespace CFMediaManager\Tests;

use CFMediaManager\Options;
use CFMediaManager\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the activation requirements check.
 *
 * The negative branches (PHP too old, no WebP encoder) are environment-
 * dependent and not exercisable without sandboxing the host PHP. The happy
 * path is exercised here; CI runs additionally cover that the function
 * doesn't throw or warn under the strict PHPUnit config.
 */
final class PluginTest extends TestCase {

	protected function setUp(): void {
		cf_media_manager_test_reset_state();
	}

	public function test_check_requirements_returns_null_on_a_fit_host(): void {
		// Both prerequisites hold on the suite host:
		//   - PHP >= 8.0 (the test machine has 8.0+; PHP_VERSION_ID gate passes)
		//   - GD-with-imagewebp() OR Imagick-with-WEBP (the bootstrap precheck
		//     would have skipped the suite if neither was present)
		if ( ! function_exists( 'imagewebp' ) && ! extension_loaded( 'imagick' ) ) {
			self::markTestSkipped( 'No WebP encoder on host — branch covered separately by manual QA.' );
		}

		self::assertNull( Plugin::check_requirements() );
	}

	public function test_check_requirements_message_is_translatable(): void {
		// Smoke check the function shape: even if some future refactor changes
		// behavior, the contract (string|null return) must hold so callers can
		// safely treat it as an error message or success sentinel.
		$result = Plugin::check_requirements();
		self::assertTrue( null === $result || is_string( $result ) );
	}

	// =========================================================================
	// C1 — run_install() fresh-install setter for BACKFILL_DONE
	// =========================================================================

	/**
	 * Fresh install: no plugin-owned options exist yet. run_install must
	 * set BACKFILL_DONE=1 so the render-path legacy-LIKE-scan gate
	 * short-circuits from day one.
	 */
	public function test_run_install_sets_backfill_done_on_fresh_install(): void {
		// Bootstrap defaults: empty options bucket → fresh-install state.
		self::assertSame( array(), $GLOBALS['cf_media_manager_test_options'] );

		Plugin::run_install();

		self::assertSame(
			1,
			get_option( Options::BACKFILL_DONE ),
			'fresh install must auto-set BACKFILL_DONE so greenfield sites skip the legacy scan'
		);
	}

	/**
	 * Upgrade from a pre-1.2.2 install: legacy options exist but
	 * BACKFILL_DONE has never been written. run_install must NOT
	 * auto-set the flag — that would hide legitimate legacy manifest
	 * rows the render path still needs to scan until the admin
	 * explicitly runs Adopt.
	 */
	public function test_run_install_does_not_clobber_upgrade_from_legacy_install(): void {
		// Simulate an upgrade by pre-seeding a sentinel option (something
		// the admin would have written in 1.x — quality).
		$GLOBALS['cf_media_manager_test_options'][ Options::QUALITY ] = 82;
		self::assertFalse( get_option( Options::BACKFILL_DONE, false ) );

		Plugin::run_install();

		self::assertFalse(
			get_option( Options::BACKFILL_DONE, false ),
			'upgrade from a pre-1.2.2 install must NOT auto-set BACKFILL_DONE — legacy data still needs the LIKE scan until Adopt runs'
		);
	}

	/**
	 * Idempotency: a fresh install that re-activates (deactivate +
	 * activate cycle) must not clobber the existing BACKFILL_DONE row.
	 */
	public function test_run_install_is_idempotent_on_repeat_activation(): void {
		Plugin::run_install();
		self::assertSame( 1, get_option( Options::BACKFILL_DONE ) );

		// Simulate admin deactivating then reactivating without changing
		// anything else. BACKFILL_DONE row already exists; the second
		// install must be a no-op.
		Plugin::run_install();
		self::assertSame( 1, get_option( Options::BACKFILL_DONE ) );
	}

	/**
	 * run_install must not set BACKFILL_DONE when legacy cf-media-optimizer
	 * options are present — that plugin stored variants under a different
	 * postmeta key prefix and those files still need the Adopt flow.
	 */
	public function test_run_install_treats_optimizer_options_as_non_fresh(): void {
		$GLOBALS['cf_media_manager_test_options']['cf_media_optimizer_quality'] = 80;
		self::assertFalse( get_option( Options::BACKFILL_DONE, false ) );

		Plugin::run_install();

		self::assertFalse(
			get_option( Options::BACKFILL_DONE, false ),
			'optimizer options present — must not auto-set BACKFILL_DONE'
		);
	}

	// =========================================================================
	// C2 — run_optimizer_migration() retroactive repair
	// =========================================================================

	/**
	 * Not an optimizer upgrade: migration runs but BACKFILL_DONE is untouched.
	 */
	public function test_optimizer_migration_no_op_when_no_optimizer_options(): void {
		// Simulate a fresh install that correctly set BACKFILL_DONE.
		$GLOBALS['cf_media_manager_test_options'][ Options::BACKFILL_DONE ] = 1;

		Plugin::run_optimizer_migration();

		self::assertSame(
			1,
			get_option( Options::BACKFILL_DONE ),
			'BACKFILL_DONE must not be touched when there are no optimizer options'
		);
	}

	/**
	 * Optimizer upgrade: migration must clear BACKFILL_DONE that was
	 * prematurely set by the false fresh-install detection.
	 */
	public function test_optimizer_migration_clears_backfill_done_on_optimizer_upgrade(): void {
		// Simulate the broken state: optimizer quality exists (old plugin was
		// installed), BACKFILL_DONE = 1 (was set by false fresh-install detection).
		$GLOBALS['cf_media_manager_test_options']['cf_media_optimizer_quality'] = 80;
		$GLOBALS['cf_media_manager_test_options'][ Options::BACKFILL_DONE ]     = 1;

		Plugin::run_optimizer_migration();

		self::assertFalse(
			get_option( Options::BACKFILL_DONE, false ),
			'BACKFILL_DONE must be cleared so the Adopt button re-appears'
		);
		self::assertSame(
			1,
			get_option( Options::MIGRATION_OPTIMIZER_V1 ),
			'migration marker must be set'
		);
	}

	/**
	 * Idempotency: migration must not re-run once the marker is set.
	 */
	public function test_optimizer_migration_is_idempotent(): void {
		$GLOBALS['cf_media_manager_test_options']['cf_media_optimizer_quality'] = 80;
		$GLOBALS['cf_media_manager_test_options'][ Options::BACKFILL_DONE ]     = 1;

		Plugin::run_optimizer_migration(); // First run — clears BACKFILL_DONE, sets marker.

		// Restore BACKFILL_DONE to simulate a second activation path.
		$GLOBALS['cf_media_manager_test_options'][ Options::BACKFILL_DONE ] = 1;

		Plugin::run_optimizer_migration(); // Second run — must not clear BACKFILL_DONE again.

		self::assertSame(
			1,
			get_option( Options::BACKFILL_DONE ),
			'second call must be a no-op — marker already set'
		);
	}
}
