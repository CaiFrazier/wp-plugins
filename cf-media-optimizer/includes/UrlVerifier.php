<?php

namespace CFMediaOptimizer;

defined( 'ABSPATH' ) || exit;

use CFShared\Media\Paths;

/**
 * SSRF-hardened HTTP fetch for the admin "Live page verifier" tool.
 *
 * Extracted out of {@see Ajax::verify_url()} so the security-sensitive
 * fetch surface lives in one auditable class. Ajax stays responsible for
 * the AJAX boundary (auth, JSON encoding) and for parsing the returned
 * HTML for WebP/AVIF coverage; this class is responsible for proving
 * that the URL we're about to fetch is safe.
 *
 * Defenses (defense-in-depth, in order):
 *
 *   1. Scheme allowlist — http or https only. Rejects javascript:, file:,
 *      data:, ftp:, etc.
 *   2. Same-host gate — the host must equal the site's home_url() host.
 *      A redirect to any other host (even a subdomain) is rejected.
 *   3. Path policy — admin, login, XML-RPC, and REST API paths are
 *      rejected, as are URLs that carry `_wpnonce`. The verifier is for
 *      front-end coverage; admin paths have no business being fetched
 *      and would only widen the SSRF surface.
 *   4. DNS resolution + IP allowlist — resolve every A and AAAA record
 *      for the target host. Each address is rejected if it is private,
 *      reserved, loopback, link-local, ULA, or an IPv4-mapped
 *      embedding of any of the above. AWS / GCP / Azure metadata IPs
 *      (169.254.169.254) fall out of the FILTER_FLAG_NO_RES_RANGE check
 *      automatically (169.254/16 is link-local).
 *   5. IP pinning — close the DNS rebinding TOCTOU window between
 *      validation and connect. We pick one resolved IP and inject it via
 *      curl's CURLOPT_RESOLVE so the actual TCP connect uses the IP we
 *      already validated, regardless of what a second DNS lookup would
 *      return. The Host header still carries the original hostname so
 *      virtual-host routing and TLS SNI still work.
 *   6. Same-host enforcement at every redirect hop — re-validate scheme,
 *      host, and re-resolve+re-pin DNS for each Location target.
 *
 * Resource limits: VERIFY_TIMEOUT seconds, VERIFY_MAX_BYTES body cap,
 * VERIFY_MAX_HOPS redirects. The body cap is enforced by the WP HTTP
 * API (`limit_response_size`); the timeout by the underlying transport.
 *
 * Test seams: `set_resolver_for_test()` and `set_http_for_test()` swap
 * in callable overrides so SecurityHardeningTest can simulate DNS
 * rebinding scenarios and HTTP responses without touching the network.
 * Both are null in production; the public API has no other entry points
 * that bypass the defenses above.
 */
final class UrlVerifier {

	/**
	 * Paths that must never be fetched even on the legitimate site host.
	 * Admin and API surfaces have no place in a coverage check.
	 *
	 * Matches `/wp-admin`, `/wp-admin/...`, `/wp-login.php`, `/xmlrpc.php`,
	 * `/wp-json`, `/wp-json/...`. Case-insensitive.
	 */
	const PATH_DENYLIST_REGEX = '#^/wp-admin(?:/|$)|^/wp-login\.php$|^/xmlrpc\.php$|^/wp-json(?:/|$)#i';

	/**
	 * Test seam: when set, replaces the live DNS resolution. Signature
	 * `fn( string $host ): string[]` returning a list of IP literals.
	 * Production leaves this null and `resolve_all()` does real DNS.
	 *
	 * @var callable|null
	 */
	private static $resolver_override = null;

	/**
	 * Test seam: when set, replaces the actual HTTP fetch. Signature
	 * `fn( string $url, string $pinned_ip, array $args ): array|\WP_Error`
	 * returning a wp_remote_get-shaped response. Production leaves this
	 * null and the real wp_safe_remote_get runs (with CURLOPT_RESOLVE
	 * pinning the connection).
	 *
	 * @var callable|null
	 */
	private static $http_override = null;

	/**
	 * Public entry point. Returns a structured result:
	 *
	 *   ['ok' => true,  'code' => int, 'body' => string, 'final_url' => string]
	 *   ['ok' => false, 'error' => string, 'status' => int]
	 *
	 * Never throws. Never makes more than one DNS resolution per host per
	 * hop. Never connects to a private/loopback/link-local address.
	 *
	 * @param string $url Absolute http/https URL to fetch.
	 */
	public static function fetch( string $url ): array {
		$current  = $url;
		$response = null;

		for ( $hop = 0; $hop <= Options::VERIFY_MAX_HOPS; $hop++ ) {
			$validated = self::validate( $current );
			if ( ! $validated['ok'] ) {
				return $validated;
			}

			$resolved = self::resolve_all( $validated['host'] );
			if ( empty( $resolved ) ) {
				return self::error( __( 'Target host did not resolve.', 'cf-media-optimizer' ), 502 );
			}
			foreach ( $resolved as $ip ) {
				if ( ! self::ip_is_publicly_routable( $ip ) ) {
					return self::error( __( 'Target resolves to a private or loopback address; refusing to fetch.', 'cf-media-optimizer' ), 502 );
				}
			}
			$pinned_ip = $resolved[0];

			$response = self::do_http( $current, $pinned_ip, $validated['host'] );
			if ( is_wp_error( $response ) ) {
				return self::error(
					sprintf(
						/* translators: %s: underlying HTTP transport error message. */
						__( 'Fetch failed: %s', 'cf-media-optimizer' ),
						$response->get_error_message()
					),
					502
				);
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 300 || $code >= 400 ) {
				// Terminal response — exit the redirect loop.
				break;
			}

			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( ! $location ) {
				break;
			}
			if ( Options::VERIFY_MAX_HOPS === $hop ) {
				return self::error( __( 'Too many redirects.', 'cf-media-optimizer' ), 502 );
			}
			$current = \WP_Http::make_absolute_url( $location, $current );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 400 ) {
			return self::error(
				sprintf(
					/* translators: %d: HTTP status code returned by the target URL. */
					__( 'HTTP %d from target URL.', 'cf-media-optimizer' ),
					$code
				),
				502
			);
		}
		if ( '' === $body ) {
			return self::error( __( 'Empty response body.', 'cf-media-optimizer' ), 502 );
		}

		return array(
			'ok'        => true,
			'code'      => $code,
			'body'      => $body,
			'final_url' => $current,
		);
	}

	/**
	 * Stage 1–3: URL shape, scheme, same-host, path policy. Returns the
	 * normalized host on success so callers don't re-parse the URL.
	 *
	 * Pure function aside from the home_url() lookup; tests don't need a
	 * seam because the test bootstrap already shims home_url.
	 *
	 * @param string $url Absolute URL to validate.
	 */
	private static function validate( string $url ): array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return self::error( __( 'Invalid URL.', 'cf-media-optimizer' ), 400 );
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return self::error( __( 'Only http and https are accepted.', 'cf-media-optimizer' ), 400 );
		}

		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$req_host  = strtolower( (string) $parts['host'] );
		if ( '' === $site_host ) {
			return self::error( __( 'Site host could not be determined.', 'cf-media-optimizer' ), 500 );
		}
		if ( $req_host !== $site_host ) {
			return self::error( __( 'Redirect to off-site host blocked.', 'cf-media-optimizer' ), 502 );
		}

		$path  = (string) ( $parts['path'] ?? '/' );
		$query = (string) ( $parts['query'] ?? '' );

		if ( preg_match( self::PATH_DENYLIST_REGEX, $path ) ) {
			return self::error( __( 'Admin, login, XML-RPC, and REST paths are not allowed.', 'cf-media-optimizer' ), 403 );
		}

		if ( '' !== $query ) {
			$qparams = array();
			parse_str( $query, $qparams );
			if ( isset( $qparams['rest_route'] ) ) {
				return self::error( __( 'REST API paths are not allowed.', 'cf-media-optimizer' ), 403 );
			}
			if ( isset( $qparams['_wpnonce'] ) ) {
				return self::error( __( 'URLs containing _wpnonce are not allowed.', 'cf-media-optimizer' ), 403 );
			}
		}

		return array(
			'ok'   => true,
			'host' => $req_host,
		);
	}

	/**
	 * Resolve every A and AAAA record for $host. Literal IPs are returned
	 * as-is. Returns an empty array when the host cannot be resolved.
	 *
	 * @param string $host Hostname or IP literal (IPv6 may include brackets).
	 * @return string[]
	 */
	public static function resolve_all( string $host ): array {
		if ( is_callable( self::$resolver_override ) ) {
			$ips = call_user_func( self::$resolver_override, $host );
			return is_array( $ips ) ? array_values( array_filter( $ips, 'is_string' ) ) : array();
		}
		// IPv6 literals arrive bracketed via wp_parse_url; strip for validation.
		$bare = trim( $host, '[]' );
		if ( filter_var( $bare, FILTER_VALIDATE_IP ) ) {
			return array( $bare );
		}
		$ips = array();
		$a4  = @gethostbynamel( $host );
		if ( is_array( $a4 ) ) {
			$ips = array_merge( $ips, $a4 );
		}
		// AAAA only when ext-dns is present; on hosts where it isn't,
		// we fall back to v4 only — strictly safer (one less attack
		// surface), at the cost of v6-only hosts being unreachable.
		if ( function_exists( 'dns_get_record' ) ) {
			$aaaa = @dns_get_record( $host, DNS_AAAA );
			if ( is_array( $aaaa ) ) {
				foreach ( $aaaa as $rr ) {
					if ( isset( $rr['ipv6'] ) && is_string( $rr['ipv6'] ) ) {
						$ips[] = $rr['ipv6'];
					}
				}
			}
		}
		return array_values( array_unique( $ips ) );
	}

	/**
	 * True when the given IP literal is publicly routable. Rejects:
	 *
	 *   - Loopback (127.0.0.0/8, ::1)
	 *   - RFC1918 private (10/8, 172.16/12, 192.168/16)
	 *   - Link-local (169.254/16 inc. AWS/GCP/Azure metadata IP, fe80::/10)
	 *   - CGNAT (100.64/10), multicast (224/4), reserved (240/4, 0/8)
	 *   - IPv6 ULA (fc00::/7)
	 *   - IPv4-mapped IPv6 wrapping any of the above (::ffff:127.0.0.1)
	 *
	 * @param string $ip IP literal (IPv4 or IPv6; brackets allowed for IPv6).
	 */
	public static function ip_is_publicly_routable( string $ip ): bool {
		if ( '' === $ip ) {
			return false;
		}
		$normalized = strtolower( trim( $ip, '[]' ) );

		// IPv6 special-cases.
		if ( '::1' === $normalized ) {
			return false;
		}
		if ( preg_match( '/^fe[89ab][0-9a-f]?:/i', $normalized ) ) {
			// fe80::/10 link-local — first 10 bits are 1111111010, so the
			// leading hex octet is fe8X .. febX.
			return false;
		}
		if ( self::ipv6_in_ula( $normalized ) ) {
			return false;
		}
		if ( preg_match( '/^::ffff:([0-9.]+)$/', $normalized, $m ) && filter_var( $m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return self::ip_is_publicly_routable( $m[1] );
		}

		return (bool) filter_var(
			$normalized,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * True when $ip is in the IPv6 ULA range fc00::/7 (first 7 bits ==
	 * 1111110, so the top byte is 0xFC or 0xFD).
	 *
	 * @param string $ip Lower-cased IPv6 literal without brackets.
	 */
	private static function ipv6_in_ula( string $ip ): bool {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return false;
		}
		$bin = @inet_pton( $ip );
		if ( false === $bin || strlen( $bin ) < 1 ) {
			return false;
		}
		return ( ( ord( $bin[0] ) & 0xFE ) === 0xFC );
	}

	/**
	 * Issue the actual HTTP request, pinning the TCP connection to
	 * $pinned_ip via curl's CURLOPT_RESOLVE map. The URL passed to
	 * wp_safe_remote_get keeps the original hostname so the Host header
	 * and TLS SNI are correct.
	 *
	 * Transport caveat: CURLOPT_RESOLVE only applies when the WP HTTP API
	 * routes through curl (the default on virtually every modern host).
	 * On installs that have forced the streams transport via the
	 * `use_streams_transport` filter, the `http_api_curl` action never
	 * fires and the OS will re-resolve at connect. The DNS-validation
	 * gate above still runs in that case, so the worst-case window is
	 * "DNS rebinds between resolve and TCP connect on a streams-only
	 * host" — narrow, but real. Callers that need to close that window
	 * absolutely should run on a curl-enabled host.
	 *
	 * @param string $url       The same URL that was validated.
	 * @param string $pinned_ip One of the publicly-routable IPs $host resolved to.
	 * @param string $host      The validated hostname (may include IPv6 brackets).
	 * @return array|\WP_Error
	 */
	private static function do_http( string $url, string $pinned_ip, string $host ) {
		$args = array(
			'timeout'             => Options::VERIFY_TIMEOUT,
			'user-agent'          => 'CF-WebP-Verifier/' . ( defined( 'CF_MEDIA_OPTIMIZER_VERSION' ) ? CF_MEDIA_OPTIMIZER_VERSION : 'dev' ),
			'redirection'         => 0,
			'limit_response_size' => Options::VERIFY_MAX_BYTES,
			'reject_unsafe_urls'  => true,
		);

		if ( is_callable( self::$http_override ) ) {
			return call_user_func( self::$http_override, $url, $pinned_ip, $args );
		}

		$parts  = wp_parse_url( $url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'http';
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

		// curl's --resolve format: HOST:PORT:ADDRESS. HOST must be
		// bare (no brackets) even when it's an IPv6 literal — brackets
		// are URL syntax, not Host syntax. ADDRESS must be bracketed
		// when it's IPv6.
		$bare_host      = trim( $host, '[]' );
		$ip_for_resolve = ( false !== strpos( $pinned_ip, ':' ) )
			? '[' . trim( $pinned_ip, '[]' ) . ']'
			: $pinned_ip;
		$resolve_entry  = $bare_host . ':' . $port . ':' . $ip_for_resolve;

		$pin_handler = static function ( $handle ) use ( $resolve_entry ) {
			if ( defined( 'CURLOPT_RESOLVE' ) ) {
				// IP pinning closes the DNS-rebind TOCTOU between our
				// SSRF allowlist check and the TCP connect. CURLOPT_RESOLVE
				// has no wp_remote_* equivalent — wp_safe_remote_get below
				// runs ON TOP of this handle via the http_api_curl filter,
				// so we keep WP's HTTP API for everything else and only
				// drop down to curl_setopt for this single resolver
				// override.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
				curl_setopt( $handle, CURLOPT_RESOLVE, array( $resolve_entry ) );
			}
		};
		add_action( 'http_api_curl', $pin_handler, 10, 1 );
		try {
			return wp_safe_remote_get( $url, $args );
		} finally {
			remove_action( 'http_api_curl', $pin_handler, 10 );
		}
	}

	private static function error( string $message, int $status ): array {
		return array(
			'ok'     => false,
			'error'  => $message,
			'status' => $status,
		);
	}

	public static function set_resolver_for_test( ?callable $callback ): void {
		self::$resolver_override = $callback;
	}

	public static function set_http_for_test( ?callable $callback ): void {
		self::$http_override = $callback;
	}

	private function __construct() {}
}
