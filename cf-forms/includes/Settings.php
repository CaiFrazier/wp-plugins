<?php
namespace CFForms;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'cff_settings';

	private array $defaults = [
		'notification_email'  => '',
		'rate_limit_max'      => 10,
		'rate_limit_window'   => HOUR_IN_SECONDS,
		'min_elapsed_seconds' => 3,
	];

	public function all(): array {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		$merged = array_merge( $this->defaults, $stored );

		// Clamp numeric settings so a hand-edited or corrupted option row can't
		// silently disable a defence (e.g. min_elapsed 0 turns off the time-trap,
		// a huge window makes the limiter permanent). RateLimiter also clamps its
		// own inputs; this keeps the values sane at the source too.
		$merged['rate_limit_max']      = max( 1, (int) $merged['rate_limit_max'] );
		$merged['rate_limit_window']   = max( 60, (int) $merged['rate_limit_window'] );
		$merged['min_elapsed_seconds'] = max( 0, (int) $merged['min_elapsed_seconds'] );

		if ( '' === $merged['notification_email'] ) {
			$merged['notification_email'] = get_option( 'admin_email' );
		}

		return $merged;
	}

	public function get( string $key ) {
		$all = $this->all();
		return $all[ $key ] ?? ( $this->defaults[ $key ] ?? null );
	}
}
