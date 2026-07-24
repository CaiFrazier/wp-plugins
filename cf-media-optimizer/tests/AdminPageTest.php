<?php

namespace CFMediaOptimizer\Tests;

use CFMediaOptimizer\AdminPage;
use CFMediaOptimizer\Converter;
use CFShared\Media\Paths;
use PHPUnit\Framework\TestCase;

/**
 * Covers the small admin-side helpers that don't need a real WP install:
 * the Settings link injection on the Plugins screen and the per-user
 * dismissal check for the In-use / Background-queue explainer.
 */
final class AdminPageTest extends TestCase {

	private AdminPage $admin;

	protected function setUp(): void {
		cf_media_manager_test_reset_state();
		// AdminPage's constructor only stores the Converter — none of the
		// methods under test invoke it, so a real Paths/Converter pair is
		// sufficient.
		$paths       = new Paths( sys_get_temp_dir(), 'https://example.test/wp-content/uploads' );
		$converter   = new Converter( $paths );
		$this->admin = new AdminPage( $converter );
	}

	// -----------------------------------------------------------------------
	// add_settings_action_link
	// -----------------------------------------------------------------------

	public function test_settings_link_is_prepended_to_existing_action_links(): void {
		$links = $this->admin->add_settings_action_link( array( '<a href="#">Deactivate</a>' ) );

		self::assertCount( 2, $links );
		self::assertStringContainsString( 'upload.php?page=cf-media-optimizer', $links[0] );
		self::assertStringContainsString( 'Settings', $links[0] );
		self::assertSame( '<a href="#">Deactivate</a>', $links[1] );
	}

	public function test_settings_link_handles_empty_input(): void {
		$links = $this->admin->add_settings_action_link( array() );
		self::assertCount( 1, $links );
		self::assertStringContainsString( 'Settings', $links[0] );
	}

	public function test_settings_link_coerces_non_array_input(): void {
		// Some third-party filters drop the array — be defensive.
		$links = $this->admin->add_settings_action_link( null );
		self::assertCount( 1, $links );
		self::assertStringContainsString( 'Settings', $links[0] );
	}

	// -----------------------------------------------------------------------
	// explainer_dismissed
	// -----------------------------------------------------------------------

	public function test_explainer_dismissed_defaults_false_for_anonymous(): void {
		$GLOBALS['cf_media_manager_test_current_user'] = 0;
		self::assertFalse( AdminPage::explainer_dismissed() );
	}

	public function test_explainer_dismissed_defaults_false_for_logged_in_user_with_no_meta(): void {
		$GLOBALS['cf_media_manager_test_current_user'] = 42;
		self::assertFalse( AdminPage::explainer_dismissed() );
	}

	public function test_explainer_dismissed_true_after_meta_is_set(): void {
		$GLOBALS['cf_media_manager_test_current_user'] = 42;
		update_user_meta( 42, AdminPage::EXPLAINER_DISMISSED_META, 1 );
		self::assertTrue( AdminPage::explainer_dismissed() );
	}

	public function test_explainer_dismissed_is_per_user(): void {
		// User 7 dismisses; user 9 is a different admin on the same site.
		update_user_meta( 7, AdminPage::EXPLAINER_DISMISSED_META, 1 );

		$GLOBALS['cf_media_manager_test_current_user'] = 7;
		self::assertTrue( AdminPage::explainer_dismissed() );

		$GLOBALS['cf_media_manager_test_current_user'] = 9;
		self::assertFalse( AdminPage::explainer_dismissed() );
	}
}
