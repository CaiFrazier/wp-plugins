<?php

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrite the front-end HTML so browsers receive WebP/AVIF when available.
 *
 * Strategy:
 *
 *   1. <img> tags whose src points into the uploads directory get wrapped in
 *      <picture><source type="image/avif"><source type="image/webp"><img></picture>.
 *      The original <img> stays untouched as the fallback. srcset and sizes
 *      are mirrored onto the <source> elements so responsive images still
 *      work end-to-end.
 *   2. Upload-dir image URLs that occur outside <img> tags (CSS background,
 *      data attributes, JSON-LD, etc.) get a direct .jpg/.png → .webp
 *      substitution. AVIF is intentionally not substituted blindly here —
 *      there's no <picture>-style negotiation outside <img> markup.
 *   3. Pages that have no <img> tag AND no uploads-URL substring skip the
 *      regex pipeline entirely.
 *
 * <img> tags already inside an existing <picture> block are left untouched —
 * the author has already opted into picture-mode and probably has their own
 * source ordering. Likewise tags carrying `data-no-webp` are skipped.
 */
final class Rewriter {

	/**
	 * Favicon / touch-icon <link> tags. Shared between the masking pass (so
	 * their hrefs are not swapped) and the verifier (so PNG favicons are
	 * counted separately, not as "non-modern outside <picture>" failures).
	 *
	 * rel values covered: icon, shortcut icon, apple-touch-icon,
	 * apple-touch-icon-precomposed, mask-icon (Safari pinned tab), fluid-icon.
	 */
	public const FAVICON_LINK_REGEX = '#<link\b[^>]*\brel\s*=\s*["\']?(?:shortcut\s+icon|icon|apple-touch-icon(?:-precomposed)?|mask-icon|fluid-icon)["\']?[^>]*>#i';

	private Paths $paths;
	private VariantManifest $manifest;

	/** Per-request file-existence cache: path → bool. */
	private array $exists_cache = [];

	public function __construct( Paths $paths, ?VariantManifest $manifest = null ) {
		$this->paths    = $paths;
		$this->manifest = $manifest ?? new VariantManifest( $paths );
	}

	public function reset_exists_cache(): void {
		$this->exists_cache = [];
	}

	public function register_hooks(): void {
		add_action( 'template_redirect', [ $this, 'buffer_start' ], 1 );

		// SEO plugins: rewrite Open Graph and Twitter card image URLs at the source.
		// The output buffer catches OG tags in <head>, but some plugins emit URLs
		// into JSON or hand them to other systems before the buffer runs — these
		// filters make the swap deterministic.
		$single_url_filters = [
			'wpseo_opengraph_image_url',
			'wpseo_twitter_image',
			'wpseo_twitter_image_url',
			'rank_math/opengraph/facebook/og_image',
			'rank_math/opengraph/facebook/og_image_url',
			'rank_math/opengraph/twitter/twitter_image',
			'seopress_social_og_thumb_img',
			'seopress_social_twitter_card_thumb',
		];
		foreach ( $single_url_filters as $f ) {
			add_filter( $f, [ $this, 'maybe_swap_url' ], 99 );
		}
	}

	/**
	 * Open the output buffer that rewrites image URLs before they leave PHP.
	 *
	 * WP Engine caching note: the full-page cache stores the already-rewritten
	 * HTML from the first PHP-generated response. Subsequent requests are
	 * served by nginx directly — PHP never runs. Once the first request writes
	 * <picture> markup into cache, everyone after gets it for free.
	 *
	 * Scoped to HTML responses only — feeds, REST, AJAX, JSON, and any plugin
	 * that emits XML through `template_redirect` are skipped. Mutating a JSON
	 * body, or an XML feed, or a page-builder's content endpoint, breaks
	 * parsers and is not a goal of this plugin.
	 */
	public function buffer_start(): void {
		if ( ! get_option( Options::REWRITE, true ) ) {
			return;
		}
		if ( get_option( Options::SCOPE, 'all' ) === 'guests' && is_user_logged_in() ) {
			return;
		}

		// Skip non-HTML contexts. is_feed/is_robots are core; the AJAX/REST/CLI
		// constants are stable cross-version. wp_is_json_request() exists from
		// WP 5.7+; we guard with function_exists in case of much older cores.
		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return;
		}
		if ( function_exists( 'is_robots' ) && is_robots() ) {
			return;
		}
		if ( function_exists( 'is_trackback' ) && is_trackback() ) {
			return;
		}
		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return;
		}
		if ( function_exists( 'wp_is_jsonp_request' ) && wp_is_jsonp_request() ) {
			return;
		}
		// Sitemap requests on WP 5.5+ go through template_redirect and emit XML.
		if ( function_exists( 'get_query_var' ) && '' !== (string) get_query_var( 'sitemap', '' ) ) {
			return;
		}

		$filter = get_option( Options::FILTER_MODE, 'none' );
		if ( $filter !== 'none' ) {
			$patterns = (string) get_option( Options::FILTER_PATTERNS, '' );
			$matches  = PatternMatcher::matches_current_request( $patterns );
			if ( $filter === 'blacklist' && $matches ) {
				return;
			}
			if ( $filter === 'whitelist' && ! $matches ) {
				return;
			}
		}

		ob_start( [ $this, 'rewrite_html' ] );
	}

	/**
	 * Rewrite an HTML document in-memory. Public + pure-ish so tests can
	 * exercise it without touching ob_start().
	 */
	public function rewrite_html( string $html ): string {
		if ( $html === '' ) {
			return $html;
		}

		// Cheap pre-check: if the document neither has an <img> nor references
		// the uploads directory, there is nothing to do. This is the common
		// case for AJAX, JSON, REST responses, and any page that happens to
		// have no images.
		$base = rtrim( $this->paths->upload_url(), '/' );
		if ( stripos( $html, '<img' ) === false && stripos( $html, $base ) === false ) {
			return $html;
		}

		// Mask everything that the URL-substitution pass must NOT touch:
		// - existing <picture> blocks (author already opted into picture mode)
		// - <img> tags (we either wrap them or leave them alone — never
		// do a blind src-swap that would break the fallback contract)
		//
		// Placeholders use null bytes, which are invalid in real HTML, so
		// they cannot collide with anything in the source document.
		$masks = [];

		// Every regex pass over the full HTML buffer is wrapped in a
		// preg_last_error() guard: PCRE backtrack/recursion limits can be
		// blown by pathological input (very deeply nested page-builder
		// markup, gigantic unclosed <picture> attempts, etc.). When that
		// happens preg_* returns null and we'd otherwise emit the literal
		// string "null" into the response body. Return the unmodified
		// $original on any failure so the page still renders correctly,
		// just without WebP wrapping.
		$original = $html;

		$html = self::safe_preg_replace_callback(
			'#<picture\b[^>]*>.*?</picture>#is',
			static function ( $m ) use ( &$masks ) {
				$idx           = count( $masks );
				$masks[ $idx ] = $m[0];
				return "\0CFP{$idx}\0";
			},
			$html
		);
		if ( null === $html ) {
			return $original;
		}

		$html = self::safe_preg_replace_callback(
			'#<img\b[^>]*>#i',
			function ( $m ) use ( &$masks ) {
				$result        = $this->maybe_wrap_img( $m[0] );
				$idx           = count( $masks );
				$masks[ $idx ] = $result;
				return "\0CFP{$idx}\0";
			},
			$html
		);
		if ( null === $html ) {
			return $original;
		}

		// Mask favicon / touch-icon <link> tags so their PNG/ICO hrefs are not
		// swapped to .webp. iOS does not honor .webp for apple-touch-icon, and
		// browsers still expect a multi-format declaration (.ico + PNG sizes +
		// Apple touch) — WebP belongs alongside those, not in place of them.
		// Opt-in override via Options::REWRITE_FAVICONS for hosts that have
		// confirmed every consumer can handle .webp favicons.
		if ( ! get_option( Options::REWRITE_FAVICONS, false ) ) {
			$html = self::safe_preg_replace_callback(
				self::FAVICON_LINK_REGEX,
				static function ( $m ) use ( &$masks ) {
					$idx           = count( $masks );
					$masks[ $idx ] = $m[0];
					return "\0CFP{$idx}\0";
				},
				$html
			);
			if ( null === $html ) {
				return $original;
			}
		}

		// URL-substitution pass for everything else (CSS, JSON, data attrs).
		// WebP only — AVIF is not substituted blindly because there is no
		// browser-side negotiation outside <picture>.
		$html = $this->substitute_remaining_urls( $html );

		// Restore masked regions verbatim. Failure here is unlikely (the
		// pattern is a trivial null-byte sentinel match) but a null return
		// would propagate to the caller as a broken response body. The
		// safe wrapper bails to $original which already has the original
		// markup including any masked-but-now-orphaned regions; better an
		// unrewritten page than a blank one.
		$replaced = self::safe_preg_replace_callback(
			"#\0CFP(\d+)\0#",
			static function ( $m ) use ( $masks ) {
				return $masks[ (int) $m[1] ] ?? '';
			},
			$html
		);
		if ( null === $replaced ) {
			return $original;
		}

		return $replaced;
	}

	/**
	 * Wrap a single <img> tag in <picture> if it points at an upload-dir
	 * image AND a WebP or AVIF variant exists on disk. Returns the original
	 * tag unchanged when nothing useful can be done.
	 */
	public function maybe_wrap_img( $match ): string {
		$tag = is_array( $match ) ? $match[0] : (string) $match;

		// Author opt-out.
		if ( preg_match( '#\bdata-no-webp\b#i', $tag ) ) {
			return $tag;
		}

		$src = self::extract_attr( $tag, 'src' );
		if ( $src === null || $src === '' ) {
			return $tag;
		}

		// Normalize the src to the canonical absolute upload URL. This
		// accepts absolute, protocol-relative (`//host/...`), and
		// root-relative (`/wp-content/uploads/...`) forms — the last is
		// what hand-coded HTML inside page-builder code modules emits,
		// and was previously dropped on the floor by a strict
		// `startsWith(absolute_base)` check. Returns null for cross-host
		// URLs and anything outside our uploads tree.
		$normalized = $this->paths->normalize_upload_url( $src );
		if ( null === $normalized ) {
			return $tag;
		}
		$src = $normalized;
		if ( ! preg_match( '#\.(jpe?g|png)(?:\?.*)?$#i', $src ) ) {
			return $tag;
		}

		// Build candidate variants for the primary src.
		$webp_url = Paths::swap_extension( $src, 'webp' );
		$avif_url = Paths::swap_extension( $src, 'avif' );

		$has_webp = $webp_url !== null && $this->variant_exists( $webp_url );
		$has_avif = $avif_url !== null && $this->variant_exists( $avif_url );

		if ( ! $has_webp && ! $has_avif ) {
			return $tag;
		}

		// srcset → per-format srcsets. We rebuild rather than swap so a missing
		// variant for one descriptor doesn't poison the whole picture.
		[ $srcset_webp, $srcset_avif ] = $this->build_variant_srcsets( self::extract_attr( $tag, 'srcset' ) );

		// Fall back to the primary URL when no srcset was given.
		if ( $srcset_webp === '' && $has_webp ) {
			$srcset_webp = $webp_url;
		}
		if ( $srcset_avif === '' && $has_avif ) {
			$srcset_avif = $avif_url;
		}

		if ( $srcset_webp === '' && $srcset_avif === '' ) {
			return $tag;
		}

		// Mirror sizes from the <img> onto each <source> for correct viewport math.
		$sizes      = self::extract_attr( $tag, 'sizes' );
		$sizes_attr = $sizes !== null && $sizes !== ''
			? ' sizes="' . esc_attr( $sizes ) . '"'
			: '';

		$sources = '';
		if ( $srcset_avif !== '' ) {
			$sources .= '<source type="image/avif" srcset="' . esc_attr( $srcset_avif ) . '"' . $sizes_attr . '>';
		}
		if ( $srcset_webp !== '' ) {
			$sources .= '<source type="image/webp" srcset="' . esc_attr( $srcset_webp ) . '"' . $sizes_attr . '>';
		}

		return '<picture>' . $sources . $tag . '</picture>';
	}

	/**
	 * Parse a srcset value and produce two parallel srcsets (webp, avif),
	 * skipping descriptors whose target variant doesn't exist on disk.
	 */
	private function build_variant_srcsets( ?string $srcset ): array {
		if ( $srcset === null || $srcset === '' ) {
			return [ '', '' ];
		}

		$webp_parts = [];
		$avif_parts = [];

		foreach ( preg_split( '#\s*,\s*#', $srcset ) as $entry ) {
			$entry = trim( $entry );
			if ( $entry === '' ) {
				continue;
			}
			// "url descriptor" — descriptor may be omitted, "1x", "2x", "320w", etc.
			if ( ! preg_match( '#^(\S+)(?:\s+(.+))?$#', $entry, $m ) ) {
				continue;
			}
			$url        = $m[1];
			$descriptor = $m[2] ?? '';

			$webp_url = Paths::swap_extension( $url, 'webp' );
			$avif_url = Paths::swap_extension( $url, 'avif' );

			if ( $webp_url !== null && $this->variant_exists( $webp_url ) ) {
				$webp_parts[] = trim( $webp_url . ( $descriptor !== '' ? ' ' . $descriptor : '' ) );
			}
			if ( $avif_url !== null && $this->variant_exists( $avif_url ) ) {
				$avif_parts[] = trim( $avif_url . ( $descriptor !== '' ? ' ' . $descriptor : '' ) );
			}
		}

		return [ implode( ', ', $webp_parts ), implode( ', ', $avif_parts ) ];
	}

	/**
	 * Substitute upload-dir .jpg/.png URLs with .webp where the file exists.
	 * Used for URL contexts outside <img> tags — CSS background images, JSON
	 * blobs, OG meta in body, etc. <img> tags have already been masked into
	 * <picture> wrappers by this point.
	 */
	private function substitute_remaining_urls( string $html ): string {
		// Match upload-dir JPG/PNG URLs in any of three shapes that show up
		// in real-world HTML:
		//   - Absolute            https://site.com/wp-content/uploads/foo.png
		//   - Protocol-relative   //site.com/wp-content/uploads/foo.png
		//   - Root-relative       /wp-content/uploads/foo.png
		//
		// The root-relative form is what hand-coded HTML in Divi Code Modules,
		// Elementor HTML widgets, and similar page-builder blocks tends to
		// use — and was previously missed by the absolute-only regex.
		//
		// Cross-host URLs that happen to share the uploads path (e.g. a
		// different WP install) won't survive `variant_exists()` (their
		// path won't resolve under our uploads dir), so they fall through
		// to the original match and the HTML is left untouched.
		$base_parts = wp_parse_url( rtrim( $this->paths->upload_url(), '/' ) );
		$base_host  = is_array( $base_parts ) && isset( $base_parts['host'] ) ? (string) $base_parts['host'] : '';
		$base_path  = is_array( $base_parts ) && isset( $base_parts['path'] ) ? rtrim( (string) $base_parts['path'], '/' ) : '';
		if ( '' === $base_path ) {
			// Without a known uploads path component we can't safely build a
			// pattern. (`parse_url` would only return empty path on a base
			// URL like `https://host` with no path, which doesn't happen for
			// `wp_upload_dir()['baseurl']` in practice.)
			return $html;
		}

		$escaped_host = preg_quote( $base_host, '#' );
		$escaped_path = preg_quote( $base_path, '#' );

		$pattern = '#(' .
			'(?:https?:)?//' . $escaped_host . $escaped_path . // absolute or protocol-relative
			'|' .
			'(?<![A-Za-z0-9_./-])' . $escaped_path .            // root-relative, with lookbehind to avoid mid-path false matches
			')/[^"\'>\s\)\?]+\.(jpe?g|png)(\?[^"\'>\s\)]*)?#i';

		$result = self::safe_preg_replace_callback(
			$pattern,
			function ( $m ) {
				$original = $m[0];
				$webp_url = Paths::swap_extension( $original, 'webp' );
				if ( null === $webp_url ) {
					return $original;
				}
				if ( $this->variant_exists( $webp_url ) ) {
					return $webp_url;
				}
				return $original;
			},
			$html
		);
		// On PCRE failure the safe wrapper has already logged (under
		// WP_DEBUG); fall back to the unmodified buffer so non-<img> URL
		// substitution doesn't leave the page broken.
		return null === $result ? $html : $result;
	}

	/**
	 * Filter callback for SEO plugins that hand us a single URL outside the
	 * HTML output buffer. Swaps to .webp when the file exists, otherwise
	 * returns the URL untouched.
	 */
	public function maybe_swap_url( $url ) {
		if ( ! is_string( $url ) || $url === '' ) {
			return $url;
		}
		if ( ! get_option( Options::REWRITE, true ) ) {
			return $url;
		}

		$webp_url = Paths::swap_extension( $url, 'webp' );
		if ( $webp_url === null ) {
			return $url;
		}
		if ( $this->variant_exists( $webp_url ) ) {
			return $webp_url;
		}
		return $url;
	}

	/**
	 * Ownership-aware existence check for variant URLs. Returns true only when
	 * the URL maps into the uploads tree, the file exists on disk, AND the
	 * variant manifest confirms the plugin generated it.
	 *
	 * The ownership gate prevents the rewriter from substituting a
	 * user-uploaded `.webp` (or any third-party-written file) into the
	 * <picture> source for an unrelated JPG/PNG that happens to share a
	 * basename. Pre-1.2 installs see unowned legacy variants treated as
	 * absent until an admin runs the backfill flow that adopts them — that is
	 * the documented migration path.
	 *
	 * When the WP database is unreachable (`is_owned()` returns null — CLI
	 * bootstrap, test harness), we treat ownership as indeterminate and fall
	 * back to "exists" to keep non-WP environments and edge-bootstrap cases
	 * functional. Production always has $wpdb available.
	 */
	private function variant_exists( string $url ): bool {
		$path = $this->paths->url_to_path( $url );
		if ( $path === null ) {
			return false;
		}
		if ( array_key_exists( $path, $this->exists_cache ) ) {
			return $this->exists_cache[ $path ];
		}
		if ( ! file_exists( $path ) ) {
			$this->exists_cache[ $path ] = false;
			return false;
		}
		$owned = $this->manifest->is_owned( $path );
		// null → indeterminate (no $wpdb); permissive fallback.
		$this->exists_cache[ $path ] = ( null === $owned ) ? true : (bool) $owned;
		return $this->exists_cache[ $path ];
	}

	/**
	 * preg_replace_callback wrapper that reports PCRE failures (backtrack
	 * limit, recursion limit, JIT stack overflow, etc.) instead of silently
	 * substituting the literal string "null" into the response body.
	 *
	 * Returns null on failure so the caller can fall back to its original
	 * buffer; returns the replaced string on success (mirroring
	 * preg_replace_callback's contract).
	 *
	 * @param string   $pattern
	 * @param callable $callback
	 * @param string   $subject
	 */
	private static function safe_preg_replace_callback( string $pattern, callable $callback, string $subject ): ?string {
		$out = preg_replace_callback( $pattern, $callback, $subject );
		if ( null === $out || PREG_NO_ERROR !== preg_last_error() ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated diagnostic.
				error_log( sprintf(
					'[cf-media-manager] rewriter PCRE failure (%d) — falling back to unchanged HTML',
					preg_last_error()
				) );
			}
			return null;
		}
		return $out;
	}

	/**
	 * Pull the value of a single attribute from an HTML tag string. Supports
	 * double, single, and unquoted forms. Returns null when the attribute is
	 * absent. Limited to flat attributes — does not handle templating or
	 * embedded expressions.
	 */
	private static function extract_attr( string $tag, string $name ): ?string {
		$pattern = '#\b' . preg_quote( $name, '#' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#i';
		if ( preg_match( $pattern, $tag, $m ) ) {
			if ( isset( $m[1] ) && $m[1] !== '' ) {
				return $m[1];
			}
			if ( isset( $m[2] ) && $m[2] !== '' ) {
				return $m[2];
			}
			return $m[3] ?? null;
		}
		return null;
	}
}
