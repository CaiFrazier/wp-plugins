<?php
namespace CFShared;

defined( 'ABSPATH' ) || exit;

/**
 * Structured JSONL logger, parametrized for use by any of the Cai Frazier
 * WordPress plugins. Originally extracted from BulkMetaEditor\Logger.
 *
 * Each plugin instantiates one Logger via {@see for_plugin()} and exposes it
 * through a thin per-plugin facade so call sites read like
 * `MyPlugin\Logger::instance()->info(...)`.
 *
 * Per-plugin configuration:
 *   - slug: directory name in uploads/, prefix in error_log mirror, basis of
 *           the random log filename option key.
 *   - debug_constant: a PHP constant (e.g. "BME_DEBUG") that, when defined and
 *                     truthy, forces the threshold to debug. Optional.
 *   - threshold_resolver: a callable returning one of the LEVEL_* constants.
 *                         Called once at instance construction. Optional;
 *                         default is LEVEL_WARN.
 */
class Logger {

	const LEVEL_DEBUG = 'debug';
	const LEVEL_INFO  = 'info';
	const LEVEL_WARN  = 'warn';
	const LEVEL_ERROR = 'error';

	const LEVEL_RANK = [
		self::LEVEL_DEBUG => 0,
		self::LEVEL_INFO  => 1,
		self::LEVEL_WARN  => 2,
		self::LEVEL_ERROR => 3,
	];

	const MAX_BYTES       = 5 * 1024 * 1024;
	const MAX_ROTATIONS   = 3;
	const READ_TAIL_LINES = 1000;
	const TAIL_BUF_SIZE   = 8192;

	private string  $slug;
	private ?string $debug_constant;
	private $threshold_resolver;

	private string  $filename_option;
	private ?string $log_path = null;
	private string  $request_id;
	private string  $threshold;

	/**
	 * Construct a per-plugin logger.
	 *
	 * @param array $config {
	 *     @type string        $slug                Plugin slug (required).
	 *     @type string|null   $debug_constant      Optional PHP constant name.
	 *     @type callable|null $threshold_resolver  Returns LEVEL_*. Default: LEVEL_WARN.
	 * }
	 */
	public static function for_plugin( array $config ): self {
		return new self( $config );
	}

	private function __construct( array $config ) {
		$slug = $config['slug'] ?? '';
		if ( '' === $slug || ! preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
			throw new \InvalidArgumentException( 'Logger requires a "slug" of [a-z0-9-]+.' );
		}
		$this->slug               = $slug;
		$this->debug_constant     = $config['debug_constant']     ?? null;
		$this->threshold_resolver = $config['threshold_resolver'] ?? null;
		$this->filename_option    = $slug . '_log_filename';

		$this->request_id = function_exists( 'wp_generate_uuid4' )
			? wp_generate_uuid4()
			: bin2hex( random_bytes( 16 ) );

		$this->threshold = $this->resolve_threshold();
	}

	public function request_id(): string {
		return $this->request_id;
	}

	public function set_threshold( string $level ): void {
		if ( isset( self::LEVEL_RANK[ $level ] ) ) {
			$this->threshold = $level;
		}
	}

	public function debug( string $channel, string $message, array $context = [] ): void {
		$this->write( self::LEVEL_DEBUG, $channel, $message, $context );
	}

	public function info( string $channel, string $message, array $context = [] ): void {
		$this->write( self::LEVEL_INFO, $channel, $message, $context );
	}

	public function warn( string $channel, string $message, array $context = [] ): void {
		$this->write( self::LEVEL_WARN, $channel, $message, $context );
	}

	public function error( string $channel, string $message, array $context = [] ): void {
		$this->write( self::LEVEL_ERROR, $channel, $message, $context );
	}

	private function write( string $level, string $channel, string $message, array $context ): void {
		if ( self::LEVEL_RANK[ $level ] < self::LEVEL_RANK[ $this->threshold ] ) {
			return;
		}

		$entry = [
			'ts'         => gmdate( 'c' ),
			'level'      => $level,
			'channel'    => $channel,
			'request_id' => $this->request_id,
			'user_id'    => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
			'message'    => $message,
			'context'    => $this->scrub( $context ),
		];

		$encoded = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES );
		if ( false === $encoded ) {
			$encoded = wp_json_encode( [
				'ts'         => gmdate( 'c' ),
				'level'      => self::LEVEL_ERROR,
				'channel'    => 'logger',
				'request_id' => $this->request_id,
				'message'    => 'Failed to encode log entry',
				'context'    => [ 'original_channel' => $channel ],
			] );
		}

		$path = $this->path();
		if ( $path ) {
			$this->maybe_rotate( $path );
			@file_put_contents( $path, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX );
		}

		// Mirror warnings and errors so they land in standard server logs even
		// when the JSONL file is unreachable. The mirror payload is INTENTIONALLY
		// slimmed: shared error_log infrastructure (Cloudwatch, Papertrail,
		// hosted log aggregators) often retains lines indefinitely and can be
		// readable by multiple sites/tenants. Don't leak raw post content or
		// scrubbed-but-still-sensitive context blobs through that channel —
		// the full entry stays in the plugin's own JSONL file, which is
		// behind a randomized filename inside wp-content/uploads/<slug>/.
		if ( self::LEVEL_RANK[ $level ] >= self::LEVEL_RANK[ self::LEVEL_WARN ] ) {
			$mirror = [
				'ts'         => $entry['ts'],
				'level'      => $entry['level'],
				'channel'    => $entry['channel'],
				'request_id' => $entry['request_id'],
				'message'    => $entry['message'],
				// Context replaced with size hints only — no values.
				'context_keys' => array_keys( $context ),
			];
			$mirror_encoded = wp_json_encode( $mirror, JSON_UNESCAPED_SLASHES );
			if ( false === $mirror_encoded ) {
				$mirror_encoded = '[' . $this->slug . '] log mirror encode failed';
			}
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[' . $this->slug . '] ' . $mirror_encoded );
		}
	}

	private function resolve_threshold(): string {
		if ( $this->debug_constant && defined( $this->debug_constant ) && constant( $this->debug_constant ) ) {
			return self::LEVEL_DEBUG;
		}
		if ( is_callable( $this->threshold_resolver ) ) {
			$level = call_user_func( $this->threshold_resolver );
			if ( is_string( $level ) && isset( self::LEVEL_RANK[ $level ] ) ) {
				return $level;
			}
		}
		return self::LEVEL_WARN;
	}

	private function path(): ?string {
		if ( null !== $this->log_path ) {
			return '' === $this->log_path ? null : $this->log_path;
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			$this->log_path = '';
			return null;
		}

		$dir = trailingslashit( $uploads['basedir'] ) . $this->slug;
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			// Apache: deny direct access. Nginx ignores .htaccess (the randomized
			// filename below is the real defense on those hosts), but we drop the
			// file for hosts that read it.
			@file_put_contents( $dir . '/.htaccess', "Order allow,deny\nDeny from all\n" );
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			// IIS.
			@file_put_contents(
				$dir . '/web.config',
				"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n"
			);
		}

		$filename = get_option( $this->filename_option );
		if ( ! is_string( $filename ) || ! preg_match( '/^log-[a-f0-9]{32}\.jsonl$/', $filename ) ) {
			$filename = 'log-' . bin2hex( random_bytes( 16 ) ) . '.jsonl';
			update_option( $this->filename_option, $filename, false );
		}

		$this->log_path = $dir . '/' . $filename;
		return $this->log_path;
	}

	private function maybe_rotate( string $path ): void {
		if ( ! file_exists( $path ) ) {
			return;
		}
		if ( filesize( $path ) < self::MAX_BYTES ) {
			return;
		}
		for ( $i = self::MAX_ROTATIONS; $i > 1; $i-- ) {
			$src = $path . '.' . ( $i - 1 );
			$dst = $path . '.' . $i;
			if ( file_exists( $src ) ) {
				@rename( $src, $dst );
			}
		}
		@rename( $path, $path . '.1' );
	}

	private function scrub( array $context ): array {
		$out = [];
		foreach ( $context as $key => $value ) {
			$lower = is_string( $key ) ? strtolower( $key ) : '';
			if ( in_array( $lower, [ 'password', 'pass', 'secret', 'token', 'authorization', 'cookie' ], true ) ) {
				$out[ $key ] = '[redacted]';
				continue;
			}
			if ( is_array( $value ) ) {
				$out[ $key ] = $this->scrub( $value );
			} elseif ( is_string( $value ) && strlen( $value ) > 2000 ) {
				$out[ $key ] = substr( $value, 0, 2000 ) . '…[truncated ' . ( strlen( $value ) - 2000 ) . ' bytes]';
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Read the most recent N entries from the log file as decoded objects,
	 * newest-first. Backward chunked read so a 5 MB log doesn't pull tens of
	 * thousands of entries into memory just to slice the last 200.
	 */
	public function read_tail( int $lines = 200, ?string $level = null ): array {
		$path = $this->path();
		if ( ! $path || ! file_exists( $path ) ) {
			return [];
		}

		$lines = max( 1, min( self::READ_TAIL_LINES, $lines ) );
		$fp    = fopen( $path, 'rb' );
		if ( ! $fp ) {
			return [];
		}

		fseek( $fp, 0, SEEK_END );
		$position  = ftell( $fp );
		$collected = [];
		$tail      = '';
		$target    = ( null === $level ) ? $lines : $lines * 3;

		while ( $position > 0 && count( $collected ) < $target ) {
			$read_size  = (int) min( self::TAIL_BUF_SIZE, $position );
			$position  -= $read_size;
			fseek( $fp, $position, SEEK_SET );
			$buffer = fread( $fp, $read_size );
			if ( false === $buffer ) {
				break;
			}
			$tail = $buffer . $tail;

			$first_newline = strpos( $tail, "\n" );
			if ( false === $first_newline ) {
				continue;
			}

			$complete = substr( $tail, $first_newline + 1 );
			$tail     = substr( $tail, 0, $first_newline );

			$pieces = explode( "\n", $complete );
			for ( $i = count( $pieces ) - 1; $i >= 0; $i-- ) {
				$line = trim( $pieces[ $i ] );
				if ( '' === $line ) {
					continue;
				}
				$decoded = json_decode( $line, true );
				if ( ! is_array( $decoded ) ) {
					continue;
				}
				if ( null !== $level && ( $decoded['level'] ?? '' ) !== $level ) {
					continue;
				}
				$collected[] = $decoded;
				if ( count( $collected ) >= $target ) {
					break;
				}
			}
		}

		if ( $position === 0 && '' !== trim( $tail ) && count( $collected ) < $target ) {
			$decoded = json_decode( trim( $tail ), true );
			if ( is_array( $decoded ) && ( null === $level || ( $decoded['level'] ?? '' ) === $level ) ) {
				$collected[] = $decoded;
			}
		}

		fclose( $fp );

		return array_slice( $collected, 0, $lines );
	}

	public function clear(): bool {
		$path = $this->path();
		if ( ! $path ) {
			return false;
		}
		$ok = true;
		foreach ( array_merge( [ $path ], array_map( fn( $i ) => $path . '.' . $i, range( 1, self::MAX_ROTATIONS ) ) ) as $p ) {
			if ( file_exists( $p ) ) {
				$ok = $ok && @unlink( $p );
			}
		}
		return $ok;
	}

	public function log_path(): ?string {
		return $this->path();
	}
}
