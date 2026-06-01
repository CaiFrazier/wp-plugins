<?php

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

/**
 * Filesystem path / URL helpers.
 *
 * Paths is constructed once per request from the current uploads-dir config
 * and is otherwise stateless — every public method is a pure function of its
 * arguments + the upload base. This makes the security-sensitive containment
 * logic unit-testable in isolation, since we can construct a Paths instance
 * with a fixture upload root and exercise every branch.
 */
final class Paths {

	private string $upload_dir;  // absolute path, trailing slash
	private string $upload_url;  // public URL, trailing slash
	private ?string $upload_real; // realpath of upload_dir + DIRECTORY_SEPARATOR, or null if missing

	public function __construct( string $upload_dir, string $upload_url ) {
		$this->upload_dir = trailingslashit( $upload_dir );
		$this->upload_url = trailingslashit( $upload_url );

		$resolved          = realpath( $this->upload_dir );
		$this->upload_real = $resolved
			? rtrim( $resolved, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR
			: null;
	}

	public function upload_dir(): string {
		return $this->upload_dir;
	}

	public function upload_url(): string {
		return $this->upload_url;
	}

	/**
	 * Verify that $path resolves to a location inside the uploads directory.
	 * Used everywhere the plugin reads, writes, or deletes a file derived from
	 * attachment metadata or filesystem iteration. Blocks path-traversal (../)
	 * and symlink-to-outside attacks.
	 *
	 * For paths that don't yet exist (e.g. a target .webp before write), the
	 * parent directory must exist and be inside uploads.
	 */
	public function within_upload_dir( string $path ): bool {
		if ( $this->upload_real === null ) {
			return false;
		}

		// Self-contained input validation — this is the plugin's filesystem
		// security boundary, so callers that forgot to sanitize upstream
		// (poisoned attachment metadata, third-party hooks, etc.) still get
		// safe behavior.
		//
		// PHP 8 `realpath()` throws ValueError on null bytes; reject up front
		// so a poisoned path can't crash an admin / status / conversion
		// endpoint before we return a clean false.
		if ( '' === $path || false !== strpos( $path, "\0" ) ) {
			return false;
		}
		// Reject literal `..` traversal segments even before realpath. The
		// rewriter and url_to_path already filter these, but `within_upload_dir`
		// is called from many other places (CLI, AJAX) and shouldn't depend
		// on every caller having done the same.
		if ( preg_match( '#(^|[\\\\/])\.\.([\\\\/]|$)#', $path ) ) {
			return false;
		}

		$real = realpath( $path );
		if ( $real ) {
			return strpos( $real . DIRECTORY_SEPARATOR, $this->upload_real ) === 0;
		}

		// Path doesn't exist — validate parent so we don't allow writes outside.
		$parent_real = realpath( dirname( $path ) );
		if ( ! $parent_real ) {
			return false;
		}
		return strpos( rtrim( $parent_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR, $this->upload_real ) === 0;
	}

	/**
	 * Convert an absolute path under the uploads tree to its upload-relative
	 * form, or null when the path doesn't sit under uploads.
	 *
	 * Pure string operation — no `realpath()`, no I/O. Use {@see within_upload_dir()}
	 * first when you need realpath-based containment checking against
	 * symlinks; this method is for the common case where the caller already
	 * has an absolute path computed from an attachment row + just needs to
	 * strip the uploads-dir prefix.
	 *
	 * Returns null for:
	 *   - empty input
	 *   - any path not starting with the uploads dir
	 *   - inputs that resolve to the uploads root itself (rel would be empty)
	 *
	 * Centralizes the `rtrim + strpos + substr` idiom that was duplicated
	 * across Ajax, VariantManifest, and a few smaller call sites.
	 *
	 * @param string $abs Absolute filesystem path to convert.
	 */
	public function to_rel( string $abs ): ?string {
		if ( '' === $abs ) {
			return null;
		}
		$base = rtrim( $this->upload_dir(), '/' ) . '/';
		if ( 0 !== strpos( $abs, $base ) ) {
			return null;
		}
		$rel = substr( $abs, strlen( $base ) );
		return '' === $rel ? null : $rel;
	}

	/**
	 * Convenience form of {@see to_rel()} for sites that want an empty
	 * string for "no rel" instead of null — typically diagnostic / display
	 * surfaces where the caller treats unresolvable paths as "(unknown)".
	 *
	 * @param string $abs Absolute filesystem path to convert.
	 */
	public function to_rel_or_empty( string $abs ): string {
		$rel = $this->to_rel( $abs );
		return null === $rel ? '' : $rel;
	}

	/**
	 * Normalize an inbound upload URL to its canonical absolute form, or
	 * return null when the URL doesn't actually point into our uploads tree.
	 *
	 * Accepts three URL shapes that turn up in real-world rendered HTML:
	 *
	 *   1. Absolute      `https://site.com/wp-content/uploads/foo.png`
	 *      — what WP core + most Gutenberg block output emits.
	 *
	 *   2. Protocol-relative `//site.com/wp-content/uploads/foo.png`
	 *      — used by older themes and some HTTPS-migration helpers.
	 *
	 *   3. Root-relative `/wp-content/uploads/foo.png`
	 *      — the canonical form for hand-coded HTML in page-builder code
	 *      blocks (Divi Code Module, Elementor HTML widget, WPBakery raw
	 *      HTML, etc.). Best practice for hand-coded markup since it
	 *      survives HTTP/HTTPS toggles and dev/staging/prod domain swaps.
	 *
	 * Returns the absolute form (with our scheme + host) for any of the
	 * three. Returns null for cross-host URLs (other domains, including the
	 * site's own auction subdomain), URLs that don't sit under the uploads
	 * path, or anything malformed.
	 *
	 * Pure string transform — does not touch the filesystem.
	 */
	public function normalize_upload_url( string $url ): ?string {
		$url = trim( $url );
		if ( '' === $url ) {
			return null;
		}

		$base = rtrim( $this->upload_url, '/' );

		// Already absolute under our base — fast path, common case.
		if ( 0 === strpos( $url, $base . '/' ) ) {
			return $url;
		}

		// Anything else needs scheme/host/path comparison.
		$base_parts = wp_parse_url( $base );
		if ( ! is_array( $base_parts ) ) {
			return null;
		}
		$base_scheme = isset( $base_parts['scheme'] ) ? (string) $base_parts['scheme'] : 'https';
		$base_host   = isset( $base_parts['host'] )   ? (string) $base_parts['host']   : '';
		$base_path   = isset( $base_parts['path'] )   ? rtrim( (string) $base_parts['path'], '/' ) : '';

		// Protocol-relative: //host/path/...
		if ( 0 === strpos( $url, '//' ) ) {
			if ( '' === $base_host ) {
				return null;
			}
			$expected = '//' . $base_host . $base_path . '/';
			if ( 0 === strpos( $url, $expected ) ) {
				return $base_scheme . ':' . $url;
			}
			return null;
		}

		// Root-relative: /path/...
		if ( 0 === strpos( $url, '/' ) ) {
			if ( '' === $base_path ) {
				// Site lives at domain root with uploads under it; the
				// "path-only" form is just the path itself with leading /.
				// Fall through and try matching against a bare /wp-content/...
				// pattern via the base URL.
				if ( 0 === strpos( $url, '/wp-content/uploads/' ) ) {
					return $base . substr( $url, strlen( '/wp-content/uploads' ) );
				}
				return null;
			}
			if ( 0 === strpos( $url, $base_path . '/' ) ) {
				return $base . substr( $url, strlen( $base_path ) );
			}
			return null;
		}

		// Other absolute URLs (different host, or http vs https mismatch
		// where our base is the other scheme) — reject. Cross-host URLs
		// genuinely don't belong to us. http/https mismatches on the same
		// host are uncommon enough on modern installs that we don't try to
		// rewrite them; if it becomes a real-world need we can extend.
		return null;
	}

	/**
	 * Map an upload-directory URL to its filesystem path. Returns null if the
	 * URL doesn't actually point into the uploads tree, or if the resolved
	 * path escapes the uploads root.
	 *
	 * Accepts the same URL shapes as {@see normalize_upload_url()} —
	 * absolute, protocol-relative, and root-relative — so any code path
	 * that hands us a hand-coded HTML URL still resolves correctly.
	 *
	 * Strips query string and fragment before mapping — a URL like
	 * `…/photo.webp?ver=123` resolves to the underlying file, not a path
	 * that includes the cache buster (which would fail file_exists() and
	 * silently break the variant_exists check used by the rewriter).
	 *
	 * Percent-decodes the path so filenames with spaces (`%20`) or unicode
	 * characters round-trip back to their on-disk representation.
	 *
	 * Every returned path is realpath-validated against the uploads root so a
	 * symlink under uploads that resolves outside the tree is rejected even
	 * when the URL itself contains no `..` sequence.
	 */
	public function url_to_path( string $url ): ?string {
		// Drop query string and fragment — they aren't part of the filename.
		$q = strpos( $url, '?' );
		if ( false !== $q ) {
			$url = substr( $url, 0, $q );
		}
		$h = strpos( $url, '#' );
		if ( false !== $h ) {
			$url = substr( $url, 0, $h );
		}

		// Normalize to absolute under our base. Rejects cross-host and
		// non-uploads URLs.
		$url = $this->normalize_upload_url( $url );
		if ( null === $url ) {
			return null;
		}

		$base_url  = rtrim( $this->upload_url, '/' );
		$base_path = rtrim( $this->upload_dir, '/' );

		$rel = substr( $url, strlen( $base_url ) );
		if ( '' === $rel || false === $rel ) {
			return null;
		}

		// %20 / %2F etc. — undo URL-encoding so we can match the on-disk file.
		$rel = rawurldecode( $rel );

		// Defense in depth — rawurldecode could in principle reintroduce `..`
		// sequences that the literal containment check below would catch via
		// realpath, but reject them early so the caller sees a clean miss.
		if ( false !== strpos( $rel, "\0" ) || preg_match( '#(^|/)\.\.(/|$)#', $rel ) ) {
			return null;
		}

		$path = $base_path . $rel;

		if ( ! $this->within_upload_dir( $path ) ) {
			return null;
		}
		return $path;
	}

	/**
	 * Map a filesystem source path (.jpg/.jpeg/.png) to its target variant
	 * path (.webp or .avif). Pure string transform — does not stat the disk.
	 */
	public static function src_to_variant_path( string $src, string $ext ): string {
		return preg_replace( '/\.(jpe?g|png)$/i', '.' . $ext, $src );
	}

	/**
	 * Map a URL ending in .jpg/.jpeg/.png to the same URL with a different
	 * extension. Returns null if the URL doesn't end in a recognized extension.
	 *
	 * Pure string transform: does not consult the filesystem.
	 */
	public static function swap_extension( string $url, string $new_ext ): ?string {
		if ( ! preg_match( '#^(.+?)\.(jpe?g|png)(\?.*)?$#i', $url, $m ) ) {
			return null;
		}
		return $m[1] . '.' . $new_ext . ( $m[3] ?? '' );
	}
}
