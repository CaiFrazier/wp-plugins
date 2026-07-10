<?php
namespace CFForms;

defined( 'ABSPATH' ) || exit;

/**
 * Per-IP submission throttle backed by transients. Coarse by design: it
 * exists to blunt scripted spam floods, not to be a precise rate limiter.
 */
final class RateLimiter {

	private int $max;
	private int $window;

	public function __construct( int $max, int $window ) {
		$this->max    = max( 1, $max );
		$this->window = max( 60, $window );
	}

	public function is_exceeded( string $ip ): bool {
		$key   = $this->transient_key( $ip );
		$count = (int) get_transient( $key );
		return $count >= $this->max;
	}

	public function record( string $ip ): void {
		$key   = $this->transient_key( $ip );
		$count = (int) get_transient( $key );

		if ( 0 === $count ) {
			set_transient( $key, 1, $this->window );
			return;
		}

		set_transient( $key, $count + 1, $this->window );
	}

	private function transient_key( string $ip ): string {
		return 'cff_rl_' . md5( $ip );
	}
}
