<?php
/**
 * PHPUnit bootstrap for CFShared.
 *
 * Light pattern: no WP test harness; minimal WP function shims live alongside
 * the test cases that need them. Reserve heavy-harness tests for code that
 * actually needs full WP context.
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', '/tmp/' );

require_once __DIR__ . '/../src/Logger.php';
