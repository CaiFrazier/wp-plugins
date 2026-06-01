<?php

namespace CFChunkedUpload;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin options plus the derived helpers the rest of the plugin reads
 * (chunk-size budget, mime gate, retention window, importer capability).
 *
 * The chunk size is clamped to the live host ceiling at SAVE time, not read
 * time: a host config change is rare, and clamping on save means the stored
 * value is always the value the UI shows. The clamp uses HostInfo so a 16 MB
 * pick on an 8 MB host is reduced and surfaced with a warning.
 */
final class Settings {

	const OPTION = 'cf_chunked_upload_settings';

	const COLLISION_POLICIES = [ 'timestamp', 'reject', 'overwrite' ];
	// Capabilities, not roles. 'administrator' is a role and conflates the
	// two in the UI; "admin-level" is expressed as the manage_options cap.
	const IMPORTER_CAPS = [ 'upload_files', 'manage_options' ];

	/**
	 * Extensions that are ALWAYS rejected for the importer destination,
	 * regardless of the configured allowlist — including the default empty
	 * allowlist, which otherwise accepts every extension. Imported files land
	 * in a deny-all-hardened directory so they are not web-served, but a
	 * default-deny on server-interpreted types is defense in depth against a
	 * misconfigured host (e.g. a stray AddHandler/AddType rule, or an admin who
	 * later points the imports dir somewhere served). Scoped deliberately to
	 * types a web server executes in a typical WordPress/Apache/nginx stack —
	 * binaries (.exe/.dll) and shell/scripting files (.sh/.py) are not an RCE
	 * vector via this surface, so they are not blocked here. Every dotted
	 * segment of the filename is checked, so the classic `payload.php.jpg`
	 * double-extension bypass is caught, not just a trailing `.php`.
	 */
	const BLOCKED_EXTENSIONS = [
		'php', 'php3', 'php4', 'php5', 'php7', 'php8',
		'phtml', 'pht', 'phar', 'phps',
		'asp', 'aspx', 'jsp', 'jspx', 'cfm', 'cgi', 'shtml',
		'htaccess', 'htpasswd',
	];

	private Paths $paths;

	public function __construct( Paths $paths ) {
		$this->paths = $paths;
	}

	public static function defaults(): array {
		return [
			'chunk_size_mb'       => 8,
			'concurrency'         => 3,
			'threshold_mb'        => 10,
			'max_retries'         => 3,
			'imports_dir'         => '',
			'allowed_extensions'  => [],
			'collision_policy'    => 'timestamp',
			'importer_capability' => 'manage_options',
			'cleanup_age_hours'   => 2,
			// SEC-1: 0 = disabled (trusted/single-user installs).
			'chunks_per_minute'              => 60,
			// SEC-2: 0 = unlimited. Stored as GB; converted to bytes at runtime.
			'per_user_quota_gb'              => 50,
			// FEAT-8: 0 = unlimited concurrent uploads per user.
			'max_concurrent_uploads_per_user' => 5,
			// FEAT-5: 0 = never auto-delete imported files.
			'imports_retention_days'         => 0,
		];
	}

	public function get(): array {
		$stored = get_option( self::OPTION, [] );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Sanitize, clamp, persist. Returns the stored settings plus any
	 * `notices` the UI should surface (e.g. the chunk-size clamp message).
	 *
	 * @param array $input Raw settings input.
	 * @return array{settings:array, notices:array}
	 */
	public function update( array $input ): array {
		$current = $this->get();
		$notices = [];

		$out = $current;

		$out['concurrency']        = self::clamp_int( $input['concurrency'] ?? $current['concurrency'], 1, 6, 3 );
		$out['threshold_mb']       = self::clamp_int( $input['threshold_mb'] ?? $current['threshold_mb'], 1, 1024, 10 );
		$out['max_retries']        = self::clamp_int( $input['max_retries'] ?? $current['max_retries'], 1, 10, 3 );
		$out['cleanup_age_hours']  = self::clamp_int( $input['cleanup_age_hours'] ?? $current['cleanup_age_hours'], 1, 168, 2 );
		$out['chunks_per_minute']              = self::clamp_int( $input['chunks_per_minute'] ?? $current['chunks_per_minute'], 0, 300, 60 );
		$out['per_user_quota_gb']              = self::clamp_int( $input['per_user_quota_gb'] ?? $current['per_user_quota_gb'], 0, 10000, 50 );
		$out['max_concurrent_uploads_per_user'] = self::clamp_int( $input['max_concurrent_uploads_per_user'] ?? $current['max_concurrent_uploads_per_user'], 0, 20, 5 );
		$out['imports_retention_days']         = self::clamp_int( $input['imports_retention_days'] ?? $current['imports_retention_days'], 0, 365, 0 );

		$requested_mb = self::clamp_int( $input['chunk_size_mb'] ?? $current['chunk_size_mb'], 1, 1024, 8 );
		$ceiling      = HostInfo::chunk_ceiling_bytes();
		if ( $ceiling > 0 && ( $requested_mb * 1048576 ) > $ceiling ) {
			$capped_mb = max( 1, (int) floor( $ceiling / 1048576 ) );
			$notices[] = sprintf(
				/* translators: 1: requested MB 2: capped MB */
				__( 'Chunk size reduced from %1$d MB to %2$d MB — your host caps a single request below the requested size.', 'cf-chunked-upload' ),
				$requested_mb,
				$capped_mb
			);
			$requested_mb = $capped_mb;
		}
		$out['chunk_size_mb'] = $requested_mb;

		$policy                  = (string) ( $input['collision_policy'] ?? $current['collision_policy'] );
		$out['collision_policy'] = in_array( $policy, self::COLLISION_POLICIES, true ) ? $policy : 'timestamp';

		$cap                        = (string) ( $input['importer_capability'] ?? $current['importer_capability'] );
		$out['importer_capability'] = in_array( $cap, self::IMPORTER_CAPS, true ) ? $cap : 'manage_options';

		$exts                      = $input['allowed_extensions'] ?? $current['allowed_extensions'];
		$out['allowed_extensions'] = self::sanitize_extensions( is_array( $exts ) ? $exts : [] );

		$dir                        = isset( $input['imports_dir'] ) ? (string) $input['imports_dir'] : $current['imports_dir'];
		[ $clean_dir, $dir_notice ] = $this->sanitize_imports_dir( $dir );
		$out['imports_dir']         = $clean_dir;
		if ( null !== $dir_notice ) {
			$notices[] = $dir_notice;
		}

		update_option( self::OPTION, $out, false );

		return [
			'settings' => $out,
			'notices'  => $notices,
		];
	}

	// --- Derived helpers --------------------------------------------------

	/**
	 * Configured chunk size in bytes.
	 *
	 * @return int
	 */
	public function chunk_size_bytes(): int {
		return (int) $this->get()['chunk_size_mb'] * 1048576;
	}

	public function threshold_bytes(): int {
		return (int) $this->get()['threshold_mb'] * 1048576;
	}

	public function retention_seconds(): int {
		return (int) $this->get()['cleanup_age_hours'] * 3600;
	}

	public function importer_capability(): string {
		$cap = (string) $this->get()['importer_capability'];
		// Anything not in the allowlist (incl. a legacy stored 'administrator')
		// resolves to the admin-level capability.
		return in_array( $cap, self::IMPORTER_CAPS, true ) ? $cap : 'manage_options';
	}

	public function imports_dir(): string {
		$dir = trim( (string) $this->get()['imports_dir'] );
		if ( '' === $dir ) {
			return $this->paths->default_imports_dir();
		}
		return $dir;
	}

	public function allowed_extensions(): array {
		return (array) $this->get()['allowed_extensions'];
	}

	public function collision_policy(): string {
		return (string) $this->get()['collision_policy'];
	}

	/** SEC-1: chunks per minute limit. 0 = disabled. */
	public function chunks_per_minute(): int {
		return (int) $this->get()['chunks_per_minute'];
	}

	/** FEAT-8: max concurrent uploads per user. 0 = unlimited. */
	public function max_concurrent_uploads_per_user(): int {
		return (int) $this->get()['max_concurrent_uploads_per_user'];
	}

	/** FEAT-5: imported file retention in days. 0 = never auto-delete. */
	public function imports_retention_days(): int {
		return (int) $this->get()['imports_retention_days'];
	}

	/**
	 * SEC-2: per-user active-session quota in bytes. 0 = unlimited.
	 * Converts the stored GB value to bytes at read time.
	 *
	 * @return int
	 */
	public function per_user_quota_bytes(): int {
		$gb = (int) $this->get()['per_user_quota_gb'];
		return $gb > 0 ? $gb * 1073741824 : 0;
	}

	/**
	 * The gate ChunkReceiver consults at chunk time. Allowlist/declared check
	 * only — the authoritative content sniff happens post-assembly. For the
	 * media destination, declared MIME must be a WP-recognized type. For the
	 * importer, the extension must be in the configured allowlist (empty
	 * allowlist = allow all).
	 */
	public function mime_gate(): callable {
		$allowed = $this->allowed_extensions();
		return static function ( string $file_name, string $mime, string $destination ) use ( $allowed ): bool {
			if ( 'media' === $destination ) {
				if ( ! function_exists( 'wp_get_mime_types' ) ) {
					return '' !== $mime;
				}
				return in_array( $mime, array_values( wp_get_mime_types() ), true );
			}
			// Importer destination. The executable-extension blocklist is
			// always enforced first — it cannot be overridden by the allowlist
			// (or its empty default, which accepts everything else).
			if ( self::is_blocked_extension( $file_name ) ) {
				return false;
			}
			if ( [] === $allowed ) {
				return true;
			}
			$ext = strtolower( (string) pathinfo( $file_name, PATHINFO_EXTENSION ) );
			return '' !== $ext && in_array( $ext, $allowed, true );
		};
	}

	/**
	 * True when ANY dotted segment of the filename is a server-interpreted
	 * extension from BLOCKED_EXTENSIONS. Checking every segment (not just the
	 * trailing one) defeats the `evil.php.jpg` double-extension bypass.
	 */
	public static function is_blocked_extension( string $file_name ): bool {
		$base = strtolower( basename( $file_name ) );
		foreach ( explode( '.', $base ) as $segment ) {
			if ( '' !== $segment && in_array( $segment, self::BLOCKED_EXTENSIONS, true ) ) {
				return true;
			}
		}
		return false;
	}

	// --- Sanitizers -------------------------------------------------------

	/**
	 * Coerce to int and clamp to [min, max], falling back when non-numeric.
	 *
	 * @param mixed $v        Raw value.
	 * @param int   $min      Lower bound.
	 * @param int   $max      Upper bound.
	 * @param int   $fallback Used when $v is non-numeric.
	 * @return int
	 */
	private static function clamp_int( $v, int $min, int $max, int $fallback ): int {
		if ( ! is_numeric( $v ) ) {
			return $fallback;
		}
		return (int) max( $min, min( $max, (int) $v ) );
	}

	private static function sanitize_extensions( array $exts ): array {
		$out = [];
		foreach ( $exts as $e ) {
			$e = strtolower( ltrim( trim( (string) $e ), '.' ) );
			$e = preg_replace( '/[^a-z0-9]/', '', $e );
			if ( '' !== $e ) {
				$out[ $e ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Imports dir must resolve under WP_CONTENT_DIR (or ABSPATH) — an
	 * arbitrary absolute path would let an admin point uploads at a
	 * web-served or system directory. On rejection we fall back to '' (the
	 * default cf-imports dir) and return a notice.
	 *
	 * @param string $dir Raw imports directory.
	 * @return array{0:string,1:?string} [clean dir, notice|null]
	 */
	private function sanitize_imports_dir( string $dir ): array {
		$dir = trim( $dir );
		if ( '' === $dir ) {
			return [ '', null ];
		}

		$base = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ( defined( 'ABSPATH' ) ? ABSPATH : '' );
		$abs  = ( '/' === $dir[0] ) ? $dir : rtrim( $base, '/' ) . '/' . ltrim( $dir, '/' );
		$abs  = rtrim( $abs, '/' );

		$real_base = $base ? realpath( $base ) : false;
		$probe     = $abs;
		while ( $probe && ! file_exists( $probe ) ) {
			$probe = dirname( $probe );
			if ( '/' === $probe || '.' === $probe ) {
				break;
			}
		}
		$real_probe = $probe ? realpath( $probe ) : false;

		// Boundary-safe containment: a bare strpos() prefix test would accept
		// a sibling like "<base>-evil". Require an exact match or a real path
		// separator after the base.
		if ( $real_base && $real_probe ) {
			$real_base = rtrim( $real_base, '/' );
			if ( $real_probe === $real_base || 0 === strpos( $real_probe, $real_base . '/' ) ) {
				return [ $abs, null ];
			}
		}

		return [
			'',
			__( 'Imports directory must be inside wp-content; the default location was used instead.', 'cf-chunked-upload' ),
		];
	}
}
