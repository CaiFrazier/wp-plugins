<?php

namespace CFMediaManager\Tests;

use CFMediaManager\Paths;
use CFMediaManager\Rewriter;
use CFMediaManager\VariantManifest;
use PHPUnit\Framework\TestCase;

final class RewriterTest extends TestCase {

	private string $root;
	private Paths $paths;
	private Rewriter $rewriter;
	private string $base_url = 'https://example.test/wp-content/uploads';

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/cf-media-manager-rewriter-' . uniqid();
		mkdir( $this->root . '/2026/05', 0777, true );

		// Source + variants
		file_put_contents( $this->root . '/2026/05/photo.jpg',  'jpeg' );
		file_put_contents( $this->root . '/2026/05/photo.webp', 'webp' );
		file_put_contents( $this->root . '/2026/05/photo.avif', 'avif' );

		// A srcset variant — small width has webp, no avif
		file_put_contents( $this->root . '/2026/05/photo-300.jpg',  'jpeg-300' );
		file_put_contents( $this->root . '/2026/05/photo-300.webp', 'webp-300' );

		// PNG with webp only
		file_put_contents( $this->root . '/2026/05/diagram.png',  'png' );
		file_put_contents( $this->root . '/2026/05/diagram.webp', 'webp-png' );

		// JPG with no variants on disk
		file_put_contents( $this->root . '/2026/05/orphan.jpg', 'jpeg-orphan' );

		// Favicons with .webp siblings on disk — the rewriter must still leave
		// the <link rel="icon"|"apple-touch-icon"|...> hrefs alone.
		file_put_contents( $this->root . '/2026/05/favicon-32x32.png',     'png-fav' );
		file_put_contents( $this->root . '/2026/05/favicon-32x32.webp',    'webp-fav' );
		file_put_contents( $this->root . '/2026/05/apple-touch-icon.png',  'png-apple' );
		file_put_contents( $this->root . '/2026/05/apple-touch-icon.webp', 'webp-apple' );

		$this->paths    = new Paths( $this->root, $this->base_url );
		$this->rewriter = new Rewriter( $this->paths );

		$GLOBALS['cf_media_manager_test_options'] = [
			'cf_media_manager_rewrite' => true,
		];
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->root );
		unset( $GLOBALS['cf_media_manager_test_options'] );
	}

	public function test_short_circuits_when_no_img_or_uploads_url(): void {
		$html = '<p>hello world</p>';
		self::assertSame( $html, $this->rewriter->rewrite_html( $html ) );
	}

	public function test_wraps_img_in_picture_with_avif_and_webp_sources(): void {
		$html = '<img src="' . $this->base_url . '/2026/05/photo.jpg" alt="x">';
		$out  = $this->rewriter->rewrite_html( $html );

		self::assertStringStartsWith( '<picture>', $out );
		self::assertStringContainsString( '<source type="image/avif" srcset="' . $this->base_url . '/2026/05/photo.avif"', $out );
		self::assertStringContainsString( '<source type="image/webp" srcset="' . $this->base_url . '/2026/05/photo.webp"', $out );
		self::assertStringContainsString( '<img src="' . $this->base_url . '/2026/05/photo.jpg" alt="x">', $out );
		self::assertStringEndsWith( '</picture>', $out );
	}

	public function test_avif_source_appears_before_webp(): void {
		// Browsers select the first matching <source>; AVIF must be listed first.
		$html = '<img src="' . $this->base_url . '/2026/05/photo.jpg">';
		$out  = $this->rewriter->rewrite_html( $html );
		$avif_pos = strpos( $out, 'image/avif' );
		$webp_pos = strpos( $out, 'image/webp' );
		self::assertNotFalse( $avif_pos );
		self::assertNotFalse( $webp_pos );
		self::assertLessThan( $webp_pos, $avif_pos );
	}

	public function test_omits_avif_source_when_only_webp_exists(): void {
		$html = '<img src="' . $this->base_url . '/2026/05/diagram.png">';
		$out  = $this->rewriter->rewrite_html( $html );
		self::assertStringContainsString( 'image/webp', $out );
		self::assertStringNotContainsString( 'image/avif', $out );
	}

	public function test_does_not_wrap_when_no_variant_exists(): void {
		$html = '<img src="' . $this->base_url . '/2026/05/orphan.jpg">';
		$out  = $this->rewriter->rewrite_html( $html );
		self::assertSame( $html, $out );
		self::assertStringNotContainsString( '<picture>', $out );
	}

	public function test_preserves_srcset_with_per_descriptor_variants(): void {
		$html = '<img src="' . $this->base_url . '/2026/05/photo.jpg" '
		      . 'srcset="' . $this->base_url . '/2026/05/photo-300.jpg 300w, '
		      .          $this->base_url . '/2026/05/photo.jpg 1024w" '
		      . 'sizes="(max-width: 600px) 300px, 1024px">';
		$out  = $this->rewriter->rewrite_html( $html );

		// The webp <source> should carry both descriptors (both have .webp on disk).
		self::assertMatchesRegularExpression(
			'#<source type="image/webp" srcset="[^"]*photo-300\.webp 300w, [^"]*photo\.webp 1024w"[^>]*sizes="[^"]+"#',
			$out
		);

		// AVIF <source> should only include the larger size (no photo-300.avif on disk).
		self::assertMatchesRegularExpression(
			'#<source type="image/avif" srcset="[^"]*photo\.avif 1024w"[^>]*sizes="[^"]+"#',
			$out
		);

		// Original <img> with original srcset must still be present as fallback.
		self::assertStringContainsString( 'photo-300.jpg 300w', $out );
	}

	public function test_data_no_webp_attribute_skips_wrapping(): void {
		$html = '<img src="' . $this->base_url . '/2026/05/photo.jpg" data-no-webp>';
		$out  = $this->rewriter->rewrite_html( $html );
		self::assertSame( $html, $out );
	}

	public function test_does_not_wrap_imgs_inside_existing_picture(): void {
		$inner = '<source type="image/avif" srcset="' . $this->base_url . '/2026/05/photo.avif">'
		       . '<img src="' . $this->base_url . '/2026/05/photo.jpg">';
		$html  = '<picture>' . $inner . '</picture>';
		$out   = $this->rewriter->rewrite_html( $html );

		// Block must come back exactly once (no double-wrap).
		self::assertSame( 1, substr_count( $out, '<picture>' ) );
		self::assertStringContainsString( $inner, $out );
	}

	public function test_substitutes_remaining_jpg_urls_outside_img(): void {
		// CSS-style url() in inline style — no <img>, but the uploads URL is present.
		$html = '<div style="background-image:url(' . $this->base_url . '/2026/05/photo.jpg)"></div>';
		$out  = $this->rewriter->rewrite_html( $html );
		self::assertStringContainsString( '/photo.webp', $out );
		self::assertStringNotContainsString( '/photo.jpg', $out );
	}

	public function test_does_not_rewrite_favicon_link_to_webp(): void {
		$html = '<link rel="icon" type="image/png" sizes="32x32" href="' . $this->base_url . '/2026/05/favicon-32x32.png">';
		$out  = $this->rewriter->rewrite_html( $html );
		self::assertSame( $html, $out );
		self::assertStringNotContainsString( '.webp', $out );
	}

	public function test_does_not_rewrite_shortcut_icon_link_to_webp(): void {
		$html = '<link rel="shortcut icon" href="' . $this->base_url . '/2026/05/favicon-32x32.png">';
		$out  = $this->rewriter->rewrite_html( $html );
		self::assertSame( $html, $out );
	}

	public function test_does_not_rewrite_apple_touch_icon_to_webp(): void {
		// iOS rejects .webp for apple-touch-icon — the home-screen icon must stay PNG.
		$html = '<link rel="apple-touch-icon" sizes="180x180" href="' . $this->base_url . '/2026/05/apple-touch-icon.png">';
		$out  = $this->rewriter->rewrite_html( $html );
		self::assertSame( $html, $out );
	}

	public function test_does_not_rewrite_apple_touch_icon_precomposed_to_webp(): void {
		$html = '<link rel="apple-touch-icon-precomposed" href="' . $this->base_url . '/2026/05/apple-touch-icon.png">';
		$out  = $this->rewriter->rewrite_html( $html );
		self::assertSame( $html, $out );
	}

	public function test_does_not_rewrite_mask_icon_to_webp(): void {
		$html = '<link rel="mask-icon" href="' . $this->base_url . '/2026/05/favicon-32x32.png" color="#000">';
		$out  = $this->rewriter->rewrite_html( $html );
		self::assertSame( $html, $out );
	}

	public function test_favicon_is_rewritten_when_toggle_is_enabled(): void {
		// Opt-in escape hatch for sites that have verified every consumer can
		// handle .webp favicons. Default remains off (above tests cover the
		// default path).
		$GLOBALS['cf_media_manager_test_options']['cf_media_manager_rewrite_favicons'] = true;

		$html = '<link rel="icon" type="image/png" sizes="32x32" href="' . $this->base_url . '/2026/05/favicon-32x32.png">';
		$out  = $this->rewriter->rewrite_html( $html );
		self::assertStringContainsString( 'favicon-32x32.webp', $out );
		self::assertStringNotContainsString( 'favicon-32x32.png', $out );
	}

	public function test_favicon_toggle_off_is_the_default(): void {
		// Belt-and-braces: when the option is absent from storage (fresh
		// install), the rewriter must default to leaving favicons alone.
		unset( $GLOBALS['cf_media_manager_test_options']['cf_media_manager_rewrite_favicons'] );

		$html = '<link rel="apple-touch-icon" href="' . $this->base_url . '/2026/05/apple-touch-icon.png">';
		$out  = $this->rewriter->rewrite_html( $html );
		self::assertSame( $html, $out );
	}

	public function test_still_rewrites_non_favicon_link_contexts(): void {
		// A favicon link in the same document must not blanket-suppress nearby
		// substitutable URLs (e.g., a CSS background-image right after it).
		$html = '<link rel="icon" href="' . $this->base_url . '/2026/05/favicon-32x32.png">'
		      . '<div style="background-image:url(' . $this->base_url . '/2026/05/photo.jpg)"></div>';
		$out  = $this->rewriter->rewrite_html( $html );
		self::assertStringContainsString( 'favicon-32x32.png', $out );
		self::assertStringContainsString( '/photo.webp', $out );
	}

	public function test_does_not_substitute_when_webp_missing(): void {
		$html = '<div style="background-image:url(' . $this->base_url . '/2026/05/orphan.jpg)"></div>';
		$out  = $this->rewriter->rewrite_html( $html );
		self::assertSame( $html, $out );
	}

	public function test_maybe_swap_url_returns_webp_when_present(): void {
		$swapped = $this->rewriter->maybe_swap_url( $this->base_url . '/2026/05/photo.jpg' );
		self::assertSame( $this->base_url . '/2026/05/photo.webp', $swapped );
	}

	public function test_maybe_swap_url_returns_input_when_webp_missing(): void {
		$url = $this->base_url . '/2026/05/orphan.jpg';
		self::assertSame( $url, $this->rewriter->maybe_swap_url( $url ) );
	}

	public function test_maybe_swap_url_respects_master_toggle(): void {
		$GLOBALS['cf_media_manager_test_options']['cf_media_manager_rewrite'] = false;
		$url = $this->base_url . '/2026/05/photo.jpg';
		self::assertSame( $url, $this->rewriter->maybe_swap_url( $url ) );
	}

	// -------------------------------------------------------------------------
	// Ownership-aware variant serving — Rewriter must not substitute a
	// `.webp` / `.avif` that the manifest does not claim. Pre-1.2 this was
	// purely an existence check, which let a user-uploaded `logo.webp` get
	// served in place of a JPG that happened to share the basename.
	// -------------------------------------------------------------------------

	public function test_does_not_wrap_when_variant_is_unowned(): void {
		// Manifest reports the on-disk webp as foreign (someone uploaded
		// `photo.webp` directly into the media library — same basename as
		// the JPG sibling, but not our derivative).
		$manifest = $this->mockManifest( static function ( $path ): ?bool {
			return false; // every variant is foreign
		} );
		$rewriter = new Rewriter( $this->paths, $manifest );

		$html = '<img src="' . $this->base_url . '/2026/05/photo.jpg" alt="x">';
		$out  = $rewriter->rewrite_html( $html );

		self::assertSame( $html, $out, 'unowned variants must not be wrapped into <picture>' );
		self::assertStringNotContainsString( '<picture>', $out );
	}

	public function test_does_not_substitute_unowned_webp_in_css_url(): void {
		$manifest = $this->mockManifest( static function ( $path ): ?bool {
			return false;
		} );
		$rewriter = new Rewriter( $this->paths, $manifest );

		$html = '<div style="background-image:url(' . $this->base_url . '/2026/05/photo.jpg)"></div>';
		$out  = $rewriter->rewrite_html( $html );

		// The unowned .webp on disk must not be substituted into the URL.
		self::assertStringContainsString( '/photo.jpg', $out );
		self::assertStringNotContainsString( '/photo.webp', $out );
	}

	public function test_maybe_swap_url_refuses_unowned_variant(): void {
		$manifest = $this->mockManifest( static function ( $path ): ?bool {
			return false;
		} );
		$rewriter = new Rewriter( $this->paths, $manifest );

		$swapped = $rewriter->maybe_swap_url( $this->base_url . '/2026/05/photo.jpg' );
		self::assertSame( $this->base_url . '/2026/05/photo.jpg', $swapped );
	}

	public function test_wraps_when_variant_is_owned(): void {
		// Same fixtures as the happy-path test, but this time the manifest
		// claims ownership of both .webp and .avif. The rewriter must wrap.
		$manifest = $this->mockManifest( static function ( $path ): ?bool {
			return true;
		} );
		$rewriter = new Rewriter( $this->paths, $manifest );

		$html = '<img src="' . $this->base_url . '/2026/05/photo.jpg" alt="x">';
		$out  = $rewriter->rewrite_html( $html );

		self::assertStringContainsString( '<picture>', $out );
		self::assertStringContainsString( 'image/avif', $out );
		self::assertStringContainsString( 'image/webp', $out );
	}

	public function test_indeterminate_ownership_falls_back_to_existence(): void {
		// In CLI / no-$wpdb environments is_owned() returns null. The
		// rewriter must keep serving variants in that case — otherwise it
		// would break every site that boots through an early-stage hook.
		$manifest = $this->mockManifest( static function ( $path ): ?bool {
			return null;
		} );
		$rewriter = new Rewriter( $this->paths, $manifest );

		$html = '<img src="' . $this->base_url . '/2026/05/photo.jpg">';
		$out  = $rewriter->rewrite_html( $html );

		self::assertStringContainsString( '<picture>', $out );
	}

	/**
	 * Build a VariantManifest test double whose is_owned() returns whatever
	 * the callable returns. The class is final, so PHPUnit's createMock can't
	 * subclass it — we wire a mockBuilder that disables the final constraint
	 * and stub is_owned() to delegate to the callable.
	 */
	private function mockManifest( callable $is_owned ): VariantManifest {
		$mock = $this->getMockBuilder( VariantManifest::class )
			->setConstructorArgs( [ $this->paths ] )
			->onlyMethods( [ 'is_owned' ] )
			->getMock();
		$mock->method( 'is_owned' )->willReturnCallback( $is_owned );
		return $mock;
	}

	public function test_rewrite_preserves_other_attributes(): void {
		$html = '<img class="hero" src="' . $this->base_url . '/2026/05/photo.jpg" alt="hero" loading="lazy" width="1024" height="683">';
		$out  = $this->rewriter->rewrite_html( $html );
		self::assertStringContainsString( 'class="hero"', $out );
		self::assertStringContainsString( 'alt="hero"', $out );
		self::assertStringContainsString( 'loading="lazy"', $out );
		self::assertStringContainsString( 'width="1024"', $out );
		self::assertStringContainsString( 'height="683"', $out );
	}

	// ------------------------------------------------------------------
	// Root-relative and protocol-relative URLs — Divi Code Module case.
	// ------------------------------------------------------------------

	public function test_wraps_img_with_root_relative_src(): void {
		// This is the form that Divi Code Modules, Elementor HTML widgets,
		// and similar hand-coded page-builder content emit. Until 2.0.0c
		// the rewriter silently dropped these because of a strict
		// `startsWith(absolute_base_url)` check.
		$html = '<img decoding="async" src="/wp-content/uploads/2026/05/photo.jpg" alt="x">';
		$out  = $this->rewriter->rewrite_html( $html );

		self::assertStringStartsWith( '<picture>', $out );
		self::assertStringContainsString( '<source type="image/webp"', $out );
		// Original <img> stays inside the <picture> unchanged so existing
		// browsers using src="" continue to work as the native fallback.
		self::assertStringContainsString( 'src="/wp-content/uploads/2026/05/photo.jpg"', $out );
	}

	public function test_wraps_img_with_protocol_relative_src(): void {
		$html = '<img src="//example.test/wp-content/uploads/2026/05/photo.jpg" alt="y">';
		$out  = $this->rewriter->rewrite_html( $html );

		self::assertStringStartsWith( '<picture>', $out );
		self::assertStringContainsString( '<source type="image/webp"', $out );
	}

	public function test_does_not_wrap_root_relative_src_pointing_outside_uploads(): void {
		// Defensive: a root-relative URL that happens to look like it's in
		// uploads but isn't (e.g. a theme asset) must NOT be wrapped.
		$html = '<img src="/wp-content/themes/foo/logo.png">';
		$out  = $this->rewriter->rewrite_html( $html );
		self::assertSame( $html, $out );
	}

	public function test_does_not_wrap_cross_host_src(): void {
		// A different domain's uploads path is not ours to rewrite.
		$html = '<img src="https://other-site.com/wp-content/uploads/2026/05/photo.jpg">';
		$out  = $this->rewriter->rewrite_html( $html );
		self::assertSame( $html, $out );
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS );
		$rii = new \RecursiveIteratorIterator( $it, \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $rii as $f ) {
			if ( $f->isLink() || $f->isFile() ) {
				@unlink( $f->getPathname() );
			} else {
				@rmdir( $f->getPathname() );
			}
		}
		@rmdir( $dir );
	}
}
