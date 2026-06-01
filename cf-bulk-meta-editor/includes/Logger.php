<?php
namespace BulkMetaEditor;

defined( 'ABSPATH' ) || exit;

use CFShared\Logger as SharedLogger;

/**
 * Per-plugin facade over CFShared\Logger.
 *
 * The original BME logger was promoted to CFShared\Logger in shared/ so the
 * other CF plugins can adopt the same JSONL-with-rotation pattern. This file
 * remains as the BME-namespaced entry point so call sites keep reading like
 * `Logger::instance()->info( 'channel', 'message', [ ... ] )`.
 */
final class Logger {

	private static ?SharedLogger $instance = null;

	public static function instance(): SharedLogger {
		if ( null === self::$instance ) {
			self::$instance = SharedLogger::for_plugin(
				[
					'slug'               => 'cf-bulk-meta-editor',
					'debug_constant'     => 'BME_DEBUG',
					'threshold_resolver' => static function () {
						$opts  = get_option( Settings::OPTION_KEY, [] );
						$debug = is_array( $opts ) && ! empty( $opts['debug_mode'] );
						return $debug ? SharedLogger::LEVEL_DEBUG : SharedLogger::LEVEL_WARN;
					},
				]
			);
		}
		return self::$instance;
	}
}
