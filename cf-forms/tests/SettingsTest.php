<?php
namespace CFForms\Tests;

use CFForms\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	protected function setUp(): void {
		cff_test_reset_state();
	}

	public function test_falls_back_to_admin_email_when_unset(): void {
		$GLOBALS['cff_test_options']['admin_email'] = 'admin@example.test';

		$this->assertSame( 'admin@example.test', ( new Settings() )->get( 'notification_email' ) );
	}

	public function test_clamps_corrupted_numeric_values(): void {
		$GLOBALS['cff_test_options']['cff_settings'] = [
			'rate_limit_max'      => 0,
			'rate_limit_window'   => 5,
			'min_elapsed_seconds' => -10,
		];

		$settings = new Settings();

		// A corrupted option row cannot silently disable a defence.
		$this->assertGreaterThanOrEqual( 1, $settings->get( 'rate_limit_max' ) );
		$this->assertGreaterThanOrEqual( 60, $settings->get( 'rate_limit_window' ) );
		$this->assertGreaterThanOrEqual( 0, $settings->get( 'min_elapsed_seconds' ) );
	}

	public function test_non_array_option_is_tolerated(): void {
		$GLOBALS['cff_test_options']['cff_settings'] = 'corrupt';

		$this->assertSame( 10, ( new Settings() )->get( 'rate_limit_max' ) );
	}
}
