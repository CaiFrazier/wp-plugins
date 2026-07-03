<?php
namespace SchemaOverrideManager;

defined( 'ABSPATH' ) || exit;

use CFShared\Logger as SharedLogger;

/**
 * Per-plugin facade over CFShared\Logger. Keeps call sites short:
 *
 *     Logger::instance()->info( 'channel', 'message', [ ... ] );
 *
 * The underlying instance is configured once on first access with our slug,
 * debug constant, and a threshold resolver that pulls from plugin settings.
 */
final class Logger {

	private static ?SharedLogger $instance = null;

	public static function instance(): SharedLogger {
		if ( null === self::$instance ) {
			self::$instance = SharedLogger::for_plugin(
				[
					'slug'               => 'schema-override-manager',
					'debug_constant'     => 'SOM_DEBUG',
					'threshold_resolver' => static function () {
						$opts  = get_option( 'som_settings', [] );
						$debug = is_array( $opts ) && ! empty( $opts['debug_mode'] );
						return $debug ? SharedLogger::LEVEL_DEBUG : SharedLogger::LEVEL_WARN;
					},
				]
			);
		}
		return self::$instance;
	}
}
