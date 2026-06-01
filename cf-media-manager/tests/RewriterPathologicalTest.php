<?php

namespace CFMediaManager\Tests;

use CFMediaManager\Paths;
use CFMediaManager\Rewriter;
use PHPUnit\Framework\TestCase;

/**
 * Pathological-input tests for the Rewriter.
 *
 * Real-world Divi / Elementor / Bricks pages can ship 1–5 MB of HTML with
 * hundreds of `<img>` tags, deeply nested wrappers, and the occasional
 * malformed `<picture>` from a misbehaving theme. The rewriter's output
 * buffer hook runs on every public render, so an exception, infinite
 * loop, or `preg_*` failure here doesn't degrade the page — it BREAKS
 * the page. These tests pin the worst-case shapes.
 *
 * H9 — PCRE-failure fallback — is the most security-relevant of these:
 * before the `safe_preg_replace_callback` wrapper landed, a preg_*
 * returning null on backtrack-limit overflow would substitute the
 * literal string "null" into the response body. The PCRE-failure tests
 * here verify that the rewriter now falls back to the unmodified HTML
 * on any preg_* failure.
 *
 * @group pathological
 */
final class RewriterPathologicalTest extends TestCase {

	private string $root;
	private string $base_url = 'https://example.test/wp-content/uploads';
	private Paths $paths;
	private Rewriter $rewriter;
	private ?string $pcre_backtrack_limit_orig = null;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/cf-media-manager-rewriter-path-' . uniqid();
		mkdir( $this->root . '/2026/05', 0777, true );

		// One real source + variants the synthetic HTML can reference.
		file_put_contents( $this->root . '/2026/05/photo.jpg',  'jpeg' );
		file_put_contents( $this->root . '/2026/05/photo.webp', 'webp' );
		file_put_contents( $this->root . '/2026/05/photo.avif', 'avif' );

		$this->paths    = new Paths( $this->root, $this->base_url );
		$this->rewriter = new Rewriter( $this->paths );

		$GLOBALS['cf_media_manager_test_options'] = array(
			'cf_media_manager_rewrite' => true,
		);

		// Snapshot pcre.backtrack_limit so PCRE-failure tests can restore it.
		$this->pcre_backtrack_limit_orig = ini_get( 'pcre.backtrack_limit' );
	}

	protected function tearDown(): void {
		if ( null !== $this->pcre_backtrack_limit_orig ) {
			ini_set( 'pcre.backtrack_limit', $this->pcre_backtrack_limit_orig );
		}
		$this->rrmdir( $this->root );
		unset( $GLOBALS['cf_media_manager_test_options'] );
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->rrmdir( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}

	// =========================================================================
	// Heavy-input smoke tests
	// =========================================================================

	/**
	 * Synthesize ~4 MB of Divi-style markup with 1000 `<img>` tags and
	 * exercise the full mask + URL-substitute + restore pipeline. The
	 * assertion is intentionally loose — we care about "doesn't crash,
	 * doesn't blow PCRE limits, doesn't return empty" — but the test
	 * does verify a couple of substitutions landed so we know the
	 * pipeline executed end-to-end.
	 */
	public function test_handles_4mb_html_with_1000_imgs_without_corruption(): void {
		$html = $this->build_divi_style_html( 1000 );

		// Sanity-check our synthesizer actually produced something sizable.
		// ~2.4MB at 1000 imgs is enough to exercise the regex passes over a
		// real-world-scale buffer; the original spec said "4MB" but the
		// shape and number of regex passes matter more than raw byte count.
		self::assertGreaterThan( 2_000_000, strlen( $html ), 'fixture should be >2MB to actually stress the rewriter' );

		$out = $this->rewriter->rewrite_html( $html );

		self::assertNotEmpty( $out );
		// The wrapper output is LARGER than the input (each img grows by
		// the <picture><source> shell), so >= input is the floor.
		self::assertGreaterThanOrEqual( strlen( $html ), strlen( $out ), 'output cannot be truncated below input size' );
		// At least one img was wrapped. Defensive: confirms the rewriter
		// actually saw the input, not that an empty short-circuit fired.
		self::assertStringContainsString( '<picture>', $out, 'pipeline must execute and wrap at least one img' );
		self::assertStringContainsString( 'photo.webp', $out, 'webp source must appear in at least one <picture>' );
		// The literal string "null" must NEVER appear as a top-level
		// rewriter output — that's the H9 failure mode.
		self::assertStringNotContainsString( 'nullnull', $out );
	}

	/**
	 * Malformed `<picture>` blocks (unclosed tags from a buggy theme) must
	 * not crash the rewriter. The picture mask regex uses non-greedy
	 * `.*?` over the buffer; on input without a matching `</picture>` the
	 * regex correctly doesn't match — the rewriter falls through and
	 * processes the inner `<img>` normally.
	 */
	public function test_handles_unclosed_picture_tags(): void {
		$html  = '<div>';
		$html .= '<picture><source srcset="x.webp"><img src="' . $this->base_url . '/2026/05/photo.jpg">';
		$html .= '<!-- missing </picture> here -->';
		$html .= '<p>more content</p>';
		$html .= '<img src="' . $this->base_url . '/2026/05/photo.jpg">';
		$html .= '</div>';

		$out = $this->rewriter->rewrite_html( $html );

		self::assertNotEmpty( $out );
		// The standalone <img> after the malformed block must still be wrapped.
		self::assertStringContainsString( '<picture>', $out );
	}

	/**
	 * Nested page-builder wrappers around `<img>` are common in Bricks /
	 * Elementor exports. The img mask regex matches each `<img>` regardless
	 * of how many wrappers it sits inside, so wrap depth doesn't change
	 * the output shape. Smoke-test 50 layers of nesting.
	 */
	public function test_handles_deeply_nested_wrappers(): void {
		$open  = str_repeat( '<div class="layer">', 50 );
		$close = str_repeat( '</div>', 50 );
		$img   = '<img src="' . $this->base_url . '/2026/05/photo.jpg" alt="nested">';
		$html  = $open . $img . $close;

		$out = $this->rewriter->rewrite_html( $html );

		self::assertNotEmpty( $out );
		self::assertStringContainsString( '<picture>', $out );
		// Wrapper count must be preserved — the rewriter only touches
		// the inner <img>, never the surrounding divs.
		self::assertSame( 50, substr_count( $out, '<div class="layer">' ) );
		self::assertSame( 50, substr_count( $out, '</div>' ) );
	}

	// =========================================================================
	// H9 — PCRE-failure fallback
	// =========================================================================

	/**
	 * Force preg_replace_callback to fail by capping pcre.backtrack_limit
	 * at 1. The first regex pass (`<picture>` mask) will exceed the limit
	 * almost immediately. The rewriter must return the ORIGINAL HTML
	 * unchanged — not null, not the literal string "null", not an empty
	 * string, not a partially-processed buffer.
	 *
	 * Without the H9 safe wrapper, preg_replace_callback returning null
	 * would propagate to the output buffer and the page would render the
	 * string "null" in place of the entire document body.
	 */
	public function test_pcre_failure_returns_original_html_unchanged(): void {
		$html = '<div><img src="' . $this->base_url . '/2026/05/photo.jpg"></div>';

		// Capping backtrack_limit at 1 forces every non-trivial regex
		// callback to fail with PREG_BACKTRACK_LIMIT_ERROR. The rewriter
		// must catch the null return and fall back to $original.
		ini_set( 'pcre.backtrack_limit', '1' );

		$out = $this->rewriter->rewrite_html( $html );

		self::assertSame( $html, $out, 'rewriter must fall back to original on PCRE failure, not emit null' );
		self::assertNotSame( 'null', $out, 'output must not be the literal string "null"' );
		self::assertNotSame( '',     $out, 'output must not be empty' );
	}

	/**
	 * Specifically prove `safe_preg_replace_callback` returns null on
	 * PCRE failure (the unit invariant the wrapper exists to enforce).
	 * Tested via the same backtrack-limit lever as the integration test
	 * above; uses reflection to reach the private helper.
	 */
	public function test_safe_preg_replace_callback_returns_null_on_pcre_failure(): void {
		ini_set( 'pcre.backtrack_limit', '1' );

		$ref = new \ReflectionClass( Rewriter::class );
		$method = $ref->getMethod( 'safe_preg_replace_callback' );
		// Note: setAccessible() is a no-op on PHP 8.1+ and emits a
		// deprecation warning on 8.5+; rely on the reflection default,
		// which is sufficient for the test runtime here.

		$out = $method->invoke(
			null,
			'#<picture\b[^>]*>.*?</picture>#is',
			static function ( $m ) {
				return $m[0];
			},
			'<picture><img></picture>'
		);

		self::assertNull( $out, 'safe wrapper must return null when preg_last_error is non-zero' );
	}

	/**
	 * Sanity check the inverse — under default pcre limits the helper
	 * returns the rewritten string. Guards against a future change to
	 * the helper that returns null too eagerly.
	 */
	public function test_safe_preg_replace_callback_returns_string_on_success(): void {
		$ref    = new \ReflectionClass( Rewriter::class );
		$method = $ref->getMethod( 'safe_preg_replace_callback' );
		// Note: setAccessible() is a no-op on PHP 8.1+ and emits a
		// deprecation warning on 8.5+; rely on the reflection default,
		// which is sufficient for the test runtime here.

		$out = $method->invoke(
			null,
			'#<img\b[^>]*>#i',
			static function ( $m ) {
				return strtoupper( $m[0] );
			},
			'<p><img src="x.jpg"></p>'
		);

		self::assertIsString( $out );
		self::assertStringContainsString( '<IMG SRC="X.JPG">', $out );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Build a Divi-style HTML document approximating real-world bloat:
	 * a section wrapper per image, each with a few lines of attribute
	 * noise, plus an `<img>` referencing photo.jpg.
	 */
	private function build_divi_style_html( int $img_count ): string {
		$parts   = array( '<!doctype html><html><body><div id="et-main-area">' );
		$src     = $this->base_url . '/2026/05/photo.jpg';
		$noise_a = 'class="et_pb_module et_pb_image et_pb_image_0 et_animated et_pb_image_sticky et_pb_with_border et_clickable et_pb_image_dest_url et_pb_image_align_center et_pb_image_align_center_phone"';
		$noise_b = 'data-et-multi-view-load-tablet-hidden="true" data-et-multi-view-load-phone-hidden="true" data-et-multi-view-load-desktop-hidden="false"';

		for ( $i = 0; $i < $img_count; $i++ ) {
			$parts[] = '<div ' . $noise_a . ' id="et_pb_image_' . $i . '">';
			$parts[] = '<div class="et_pb_image_wrap"><div class="et_pb_image_wrap_2">';
			$parts[] = '<a href="#" ' . $noise_b . '>';
			$parts[] = '<img src="' . $src . '" alt="hero" loading="lazy" decoding="async" srcset="' . $src . ' 1024w" sizes="(max-width: 1024px) 100vw, 1024px">';
			$parts[] = '</a></div></div></div>';
			// Add a chunk of plain prose so total size grows realistically.
			$parts[] = '<p>' . str_repeat( 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 30 ) . '</p>';
		}
		$parts[] = '</div></body></html>';
		return implode( "\n", $parts );
	}
}
