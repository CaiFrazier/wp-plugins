<?php

namespace CFChunkedUpload;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only view of the PHP/server limits the chunker exists to work around.
 * Powers the settings readout and the server-side chunk-size clamp. None of
 * these values are secret, but they are not public either — the REST route
 * gates on manage_options.
 */
final class HostInfo {

	/**
	 * OPT-2: Request-scoped cache. disk_free_space() is slow on network FS,
	 * and ini_get() is called 4× per snapshot. Admin page loads hit snapshot()
	 * twice (enqueue boot object + GET /settings response); cache collapses that
	 * to one read. PHP-FPM workers handle requests sequentially, so a process
	 * that lives across multiple requests sees mildly stale disk values — that
	 * is acceptable for an informational settings readout. Use reset_cache() in
	 * test setUp to avoid cross-test pollution.
	 */
	private static ?array $snapshot_cache = null;

	public static function reset_cache(): void {
		self::$snapshot_cache = null;
	}

	/**
	 * Parse a PHP shorthand byte value ("64M", "1G", "512K", "0") to bytes.
	 * Returns 0 for unlimited/empty so callers treat "no limit" as "do not
	 * clamp" rather than "clamp to zero".
	 *
	 * @param string $value PHP shorthand byte string.
	 * @return int
	 */
	public static function parse_bytes( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}
		$unit = strtolower( $value[ strlen( $value ) - 1 ] );
		$num  = (float) $value;
		switch ( $unit ) {
			case 'g':
				$num *= 1024;
				// Fall through.
			case 'm':
				$num *= 1024;
				// Fall through.
			case 'k':
				$num *= 1024;
		}
		return (int) $num;
	}

	public static function upload_max_filesize(): int {
		return self::parse_bytes( (string) ini_get( 'upload_max_filesize' ) );
	}

	public static function post_max_size(): int {
		return self::parse_bytes( (string) ini_get( 'post_max_size' ) );
	}

	/**
	 * Largest chunk the host will accept in one request: the smaller of
	 * upload_max_filesize and post_max_size. A 0 (unlimited) on either side
	 * is ignored for the min so it doesn't collapse the budget to zero.
	 */
	public static function max_request_bytes(): int {
		$u          = self::upload_max_filesize();
		$p          = self::post_max_size();
		$candidates = array_filter( [ $u, $p ], static fn( $n ) => $n > 0 );
		return $candidates ? (int) min( $candidates ) : 0;
	}

	/**
	 * Safe chunk ceiling: 90% of the host request limit (10% headroom for
	 * multipart boundary + field overhead). 0 means the host declared no
	 * limit, so there is nothing to clamp against.
	 */
	public static function chunk_ceiling_bytes(): int {
		$max = self::max_request_bytes();
		return $max > 0 ? (int) floor( $max * 0.9 ) : 0;
	}

	public static function snapshot(): array {
		if ( null !== self::$snapshot_cache ) {
			return self::$snapshot_cache;
		}

		$uploads = wp_upload_dir();
		$basedir = $uploads['basedir'] ?? '';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$free = $basedir ? @disk_free_space( $basedir ) : false;

		self::$snapshot_cache = [
			'upload_max_filesize' => self::upload_max_filesize(),
			'post_max_size'       => self::post_max_size(),
			'memory_limit'        => self::parse_bytes( (string) ini_get( 'memory_limit' ) ),
			'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
			'max_request_bytes'   => self::max_request_bytes(),
			'chunk_ceiling_bytes' => self::chunk_ceiling_bytes(),
			'free_disk_space'     => false === $free ? null : (int) $free,
			'server_software'     => isset( $_SERVER['SERVER_SOFTWARE'] )
				? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
				: '',
			'isNginx'             => self::is_nginx(),
			// Assembly runs on a background task runner; if WP-Cron is
			// disabled and no system cron replaces it, jobs never fire.
			'wpCronDisabled'      => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		];
		return self::$snapshot_cache;
	}

	/** Heuristic: is the front-end web server Nginx (which ignores .htaccess)? */
	public static function is_nginx(): bool {
		$sw = isset( $_SERVER['SERVER_SOFTWARE'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) )
			: '';
		return false !== strpos( $sw, 'nginx' );
	}
}
