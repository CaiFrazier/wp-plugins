<?php

namespace CFMediaOptimizer\Tests;

use CFMediaOptimizer\Ajax;
use CFMediaOptimizer\CachePurger;
use CFMediaOptimizer\Converter;
use CFShared\Media\InUseScanner;
use CFMediaOptimizer\Options;
use CFShared\Media\Paths;
use CFMediaOptimizer\Plugin;
use CFMediaOptimizer\Queue;
use CFMediaOptimizer\Rewriter;
use CFMediaOptimizer\Security;
use CFMediaOptimizer\UrlVerifier;
use CFMediaOptimizer\VariantManifest;
use CFMediaOptimizerHttpExit;
use PHPUnit\Framework\TestCase;

/**
 * Security hardening regression tests landing in the 2.0.1 pass.
 *
 * Each phase of the hardening work appends cases here so a single suite
 * captures the cross-class invariants we don't want to silently regress
 * (path containment, capability gates, nonce verification, output-buffer
 * scoping, MIME-vs-extension agreement, etc.). Per-class behavior stays
 * in the existing per-class test files; this file is the cross-cutting
 * security harness.
 *
 * Conventions:
 *   - Test globals (multisite mode, current user caps, nonce validity) are
 *     reset in setUp() via cf_media_manager_test_reset_state().
 *   - Endpoints fail via wp_send_json_error which the bootstrap shim
 *     converts into a CFMediaOptimizerHttpExit exception so the test process
 *     survives and we can assert on status code.
 */
final class SecurityHardeningTest extends TestCase {

	protected function setUp(): void {
		cf_media_manager_test_reset_state();
		UrlVerifier::set_resolver_for_test( null );
		UrlVerifier::set_http_for_test( null );
		$_POST = array();
	}

	protected function tearDown(): void {
		UrlVerifier::set_resolver_for_test( null );
		UrlVerifier::set_http_for_test( null );
		$_POST = array();
	}

	// ====================================================================
	// C1 — Multisite capability gates (Security::authorize_ajax_network)
	// ====================================================================

	public function test_network_gate_allows_manage_options_on_single_site(): void {
		$GLOBALS['cf_media_manager_test_is_multisite'] = false;
		$GLOBALS['cf_media_manager_test_user_caps']    = array( 'manage_options' => true );

		// No exception thrown == authorized.
		Security::authorize_ajax_network( Plugin::NONCE_ACTION );
		self::assertTrue( true );
	}

	public function test_network_gate_blocks_when_no_caps_on_single_site(): void {
		$GLOBALS['cf_media_manager_test_is_multisite'] = false;
		$GLOBALS['cf_media_manager_test_user_caps']    = array();

		try {
			Security::authorize_ajax_network( Plugin::NONCE_ACTION );
			self::fail( 'Expected CFMediaOptimizerHttpExit was not thrown' );
		} catch ( CFMediaOptimizerHttpExit $e ) {
			self::assertFalse( $e->success );
			self::assertSame( 403, $e->status );
		}
	}

	public function test_network_gate_blocks_manage_options_only_on_multisite(): void {
		$GLOBALS['cf_media_manager_test_is_multisite'] = true;
		$GLOBALS['cf_media_manager_test_user_caps']    = array( 'manage_options' => true );

		try {
			Security::authorize_ajax_network( Plugin::NONCE_ACTION );
			self::fail( 'Expected CFMediaOptimizerHttpExit — manage_options must NOT be enough on multisite' );
		} catch ( CFMediaOptimizerHttpExit $e ) {
			self::assertFalse( $e->success );
			self::assertSame( 403, $e->status );
			self::assertStringContainsString( 'network-admin', (string) $e->data );
		}
	}

	public function test_network_gate_allows_manage_network_options_on_multisite(): void {
		$GLOBALS['cf_media_manager_test_is_multisite'] = true;
		$GLOBALS['cf_media_manager_test_user_caps']    = array( 'manage_network_options' => true );

		Security::authorize_ajax_network( Plugin::NONCE_ACTION );
		self::assertTrue( true );
	}

	public function test_network_gate_rejects_invalid_nonce(): void {
		$GLOBALS['cf_media_manager_test_is_multisite']  = false;
		$GLOBALS['cf_media_manager_test_user_caps']     = array( 'manage_options' => true );
		$GLOBALS['cf_media_manager_test_nonce_invalid'] = true;

		try {
			Security::authorize_ajax_network( Plugin::NONCE_ACTION );
			self::fail( 'Expected CFMediaOptimizerHttpExit on bad nonce' );
		} catch ( CFMediaOptimizerHttpExit $e ) {
			self::assertFalse( $e->success );
			self::assertSame( 403, $e->status );
		}
	}

	/**
	 * Belt-and-braces: assert the gate still rejects on multisite even when
	 * the caller has the full single-site admin set (manage_options +
	 * activate_plugins etc.) but lacks manage_network_options. Catches the
	 * mistake of "the user is admin somewhere" being treated as authorized.
	 */
	public function test_network_gate_blocks_full_single_site_admin_on_multisite(): void {
		$GLOBALS['cf_media_manager_test_is_multisite'] = true;
		$GLOBALS['cf_media_manager_test_user_caps']    = array(
			'manage_options'   => true,
			'activate_plugins' => true,
			'edit_users'       => true,
			'delete_plugins'   => true,
		);

		try {
			Security::authorize_ajax_network( Plugin::NONCE_ACTION );
			self::fail( 'Expected CFMediaOptimizerHttpExit' );
		} catch ( CFMediaOptimizerHttpExit $e ) {
			self::assertSame( 403, $e->status );
		}
	}

	/**
	 * Regression contract: the original {@see Security::authorize_ajax()}
	 * still gates on manage_options regardless of multisite — used by the
	 * read-only / per-user endpoints (status, queue_status, dismiss_*, etc.).
	 */
	public function test_single_site_gate_still_uses_manage_options_on_multisite(): void {
		$GLOBALS['cf_media_manager_test_is_multisite'] = true;
		$GLOBALS['cf_media_manager_test_user_caps']    = array( 'manage_options' => true );

		Security::authorize_ajax();
		self::assertTrue( true );
	}

	// ====================================================================
	// C2 / C3 — UrlVerifier SSRF defenses
	// ====================================================================

	/**
	 * Most SSRF tests run against home_url = https://example.test so the
	 * same-host gate is exercised first. When we want to assert that a
	 * private IP literal is rejected by the IP allowlist (not just by the
	 * same-host gate), we set home_url to the literal in question.
	 */

	public function test_ssrf_rejects_loopback_host_literal(): void {
		$GLOBALS['cf_media_manager_test_home_url'] = 'http://127.0.0.1';
		// home_url same-host check passes; private-IP filter must reject.
		$result = UrlVerifier::fetch( 'http://127.0.0.1/wp-content/uploads/test.html' );
		self::assertFalse( $result['ok'] );
		self::assertSame( 502, $result['status'] );
		self::assertStringContainsString( 'private', $result['error'] );
	}

	public function test_ssrf_rejects_metadata_ip_169_254_169_254(): void {
		$GLOBALS['cf_media_manager_test_home_url'] = 'http://169.254.169.254';
		$result = UrlVerifier::fetch( 'http://169.254.169.254/latest/meta-data/iam/security-credentials/' );
		self::assertFalse( $result['ok'] );
		self::assertSame( 502, $result['status'] );
		self::assertStringContainsString( 'private', $result['error'] );
	}

	public function test_ssrf_rejects_ipv6_loopback(): void {
		$GLOBALS['cf_media_manager_test_home_url'] = 'http://[::1]';
		$result = UrlVerifier::fetch( 'http://[::1]/' );
		self::assertFalse( $result['ok'] );
		self::assertSame( 502, $result['status'] );
	}

	public function test_ssrf_rejects_off_site_host(): void {
		// home_url is example.test; attacker tries to point us at evil.com.
		$result = UrlVerifier::fetch( 'http://evil.com/' );
		self::assertFalse( $result['ok'] );
		self::assertSame( 502, $result['status'] );
		self::assertStringContainsString( 'off-site', $result['error'] );
	}

	public function test_ssrf_rejects_non_http_scheme(): void {
		$result = UrlVerifier::fetch( 'file:///etc/passwd' );
		self::assertFalse( $result['ok'] );
		self::assertSame( 400, $result['status'] );
	}

	public function test_path_policy_blocks_wp_admin(): void {
		UrlVerifier::set_resolver_for_test( static fn( $h ) => array( '203.0.113.10' ) );
		$result = UrlVerifier::fetch( 'https://example.test/wp-admin/admin.php?page=foo' );
		self::assertFalse( $result['ok'] );
		self::assertSame( 403, $result['status'] );
	}

	public function test_path_policy_blocks_wp_login(): void {
		UrlVerifier::set_resolver_for_test( static fn( $h ) => array( '203.0.113.10' ) );
		$result = UrlVerifier::fetch( 'https://example.test/wp-login.php' );
		self::assertFalse( $result['ok'] );
		self::assertSame( 403, $result['status'] );
	}

	public function test_path_policy_blocks_xmlrpc(): void {
		UrlVerifier::set_resolver_for_test( static fn( $h ) => array( '203.0.113.10' ) );
		$result = UrlVerifier::fetch( 'https://example.test/xmlrpc.php' );
		self::assertFalse( $result['ok'] );
		self::assertSame( 403, $result['status'] );
	}

	public function test_path_policy_blocks_wp_json(): void {
		UrlVerifier::set_resolver_for_test( static fn( $h ) => array( '203.0.113.10' ) );
		$result = UrlVerifier::fetch( 'https://example.test/wp-json/wp/v2/users' );
		self::assertFalse( $result['ok'] );
		self::assertSame( 403, $result['status'] );
	}

	public function test_path_policy_blocks_rest_route_query(): void {
		UrlVerifier::set_resolver_for_test( static fn( $h ) => array( '203.0.113.10' ) );
		$result = UrlVerifier::fetch( 'https://example.test/?rest_route=/wp/v2/users' );
		self::assertFalse( $result['ok'] );
		self::assertSame( 403, $result['status'] );
	}

	public function test_path_policy_blocks_wpnonce_in_query(): void {
		UrlVerifier::set_resolver_for_test( static fn( $h ) => array( '203.0.113.10' ) );
		$result = UrlVerifier::fetch( 'https://example.test/some-page/?_wpnonce=abc123' );
		self::assertFalse( $result['ok'] );
		self::assertSame( 403, $result['status'] );
	}

	public function test_accepts_legitimate_same_host_uploads_url(): void {
		UrlVerifier::set_resolver_for_test( static fn( $h ) => array( '203.0.113.10' ) );
		UrlVerifier::set_http_for_test( static function ( $url, $pinned_ip, $args ) {
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => '<html><body><img src="/wp-content/uploads/2024/01/test.jpg"></body></html>',
			);
		} );
		$result = UrlVerifier::fetch( 'https://example.test/sample-page/' );
		self::assertTrue( $result['ok'], 'Legitimate fetch should succeed; got error: ' . ( $result['error'] ?? '' ) );
		self::assertSame( 200, $result['code'] );
		self::assertStringContainsString( 'test.jpg', $result['body'] );
	}

	/**
	 * DNS rebinding TOCTOU close: resolver returns a public IP on the
	 * first call. A naive implementation might re-resolve at connect
	 * time and reach a private IP the second time around. We pin to the
	 * first-resolved IP via CURLOPT_RESOLVE, so the second resolution
	 * never happens. This test asserts the resolver was called exactly
	 * once and the IP we pinned to is the one the HTTP layer saw.
	 */
	public function test_dns_rebind_blocked_by_ip_pinning(): void {
		$calls = 0;
		UrlVerifier::set_resolver_for_test( static function ( $host ) use ( &$calls ) {
			$calls++;
			// First (and should be only) call returns the public IP.
			// If the implementation re-resolves later it would pick up
			// 127.0.0.1 — a successful rebind. Our pinning prevents that.
			return $calls === 1 ? array( '203.0.113.10' ) : array( '127.0.0.1' );
		} );
		$seen_ip = null;
		UrlVerifier::set_http_for_test( static function ( $url, $pinned_ip, $args ) use ( &$seen_ip ) {
			$seen_ip = $pinned_ip;
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => '<html></html>',
			);
		} );

		$result = UrlVerifier::fetch( 'https://example.test/' );

		self::assertTrue( $result['ok'] );
		self::assertSame( 1, $calls, 'Resolver must be called exactly once per hop (TOCTOU close)' );
		self::assertSame( '203.0.113.10', $seen_ip, 'HTTP layer must connect to the pinned IP, not re-resolve' );
	}

	/**
	 * If ANY resolved address is private, the whole hop is rejected —
	 * defends against multi-record A responses where one record is
	 * intentionally a sentinel like 0.0.0.0 or 127.0.0.1 used as a
	 * rebinding-style trap.
	 */
	public function test_rejects_if_any_resolved_ip_is_private(): void {
		UrlVerifier::set_resolver_for_test( static fn( $h ) => array( '203.0.113.10', '127.0.0.1' ) );
		$result = UrlVerifier::fetch( 'https://example.test/' );
		self::assertFalse( $result['ok'] );
		self::assertSame( 502, $result['status'] );
	}

	public function test_ip_allowlist_rejects_rfc1918(): void {
		self::assertFalse( UrlVerifier::ip_is_publicly_routable( '10.0.0.1' ) );
		self::assertFalse( UrlVerifier::ip_is_publicly_routable( '172.16.0.1' ) );
		self::assertFalse( UrlVerifier::ip_is_publicly_routable( '192.168.1.1' ) );
	}

	public function test_ip_allowlist_rejects_loopback_and_linklocal(): void {
		self::assertFalse( UrlVerifier::ip_is_publicly_routable( '127.0.0.1' ) );
		self::assertFalse( UrlVerifier::ip_is_publicly_routable( '169.254.169.254' ) );
		self::assertFalse( UrlVerifier::ip_is_publicly_routable( '::1' ) );
		self::assertFalse( UrlVerifier::ip_is_publicly_routable( 'fe80::1' ) );
	}

	public function test_ip_allowlist_rejects_ipv6_ula(): void {
		self::assertFalse( UrlVerifier::ip_is_publicly_routable( 'fc00::1' ) );
		self::assertFalse( UrlVerifier::ip_is_publicly_routable( 'fd00::1' ) );
	}

	public function test_ip_allowlist_rejects_ipv4_mapped_loopback(): void {
		self::assertFalse( UrlVerifier::ip_is_publicly_routable( '::ffff:127.0.0.1' ) );
		self::assertFalse( UrlVerifier::ip_is_publicly_routable( '::ffff:10.0.0.1' ) );
	}

	public function test_ip_allowlist_accepts_public(): void {
		self::assertTrue( UrlVerifier::ip_is_publicly_routable( '8.8.8.8' ) );
		self::assertTrue( UrlVerifier::ip_is_publicly_routable( '203.0.113.10' ) );
		self::assertTrue( UrlVerifier::ip_is_publicly_routable( '2606:4700:4700::1111' ) );
	}

	// ====================================================================
	// H3 — Backfill overlap lock (Ajax::backfill_manifest)
	// ====================================================================

	/**
	 * Build a minimal Ajax instance. The backfill_manifest overlap-lock
	 * test only exercises the early-return path before any of these
	 * dependencies are reached, so the dummies are fine.
	 */
	private function build_ajax(): Ajax {
		$sandbox = sys_get_temp_dir() . '/cfmm-h3-' . uniqid();
		mkdir( $sandbox . '/uploads', 0777, true );
		$paths    = new Paths( $sandbox . '/uploads', 'https://example.test/wp-content/uploads' );
		$manifest = new VariantManifest( $paths );
		$conv     = new Converter( $paths, $manifest );
		$rewrite  = new Rewriter( $paths, $manifest );
		$cache    = new CachePurger();
		$queue    = new Queue( $conv );
		$scan     = new InUseScanner( $paths );
		return new Ajax( $paths, $conv, $rewrite, $cache, $queue, $scan, $manifest );
	}

	/**
	 * A fresh-start backfill click while another run is already in flight
	 * must reject with HTTP 409, NOT process anything, and leave the
	 * existing lock intact.
	 */
	public function test_backfill_overlap_lock_rejects_concurrent_fresh_start(): void {
		// Auth: cap + nonce both valid.
		$GLOBALS['cf_media_manager_test_user_caps'] = array( 'manage_options' => true );
		// In-flight peer marker.
		set_transient( Options::BACKFILL_LOCK, time(), Options::BACKFILL_LOCK_TTL );

		$_POST = array(
			'cursor'  => '',     // fresh start
			'dry_run' => '0',
			'nonce'   => 'whatever',
		);

		$ajax = $this->build_ajax();
		try {
			$ajax->backfill_manifest();
			self::fail( 'Expected CFMediaOptimizerHttpExit for concurrent backfill click' );
		} catch ( CFMediaOptimizerHttpExit $e ) {
			self::assertFalse( $e->success );
			self::assertSame( 409, $e->status, 'overlap-lock conflict must surface as HTTP 409' );
		}
		// Peer lock must still be in place — we rejected, not stole.
		self::assertNotFalse( get_transient( Options::BACKFILL_LOCK ) );
	}

	/**
	 * A dry-run request must not be blocked by an in-flight commit run —
	 * dry runs are read-only and used by the confirmation prompt.
	 */
	public function test_backfill_overlap_lock_allows_dry_run_during_in_flight_commit(): void {
		$GLOBALS['cf_media_manager_test_user_caps'] = array( 'manage_options' => true );
		set_transient( Options::BACKFILL_LOCK, time(), Options::BACKFILL_LOCK_TTL );

		$_POST = array(
			'cursor'  => '',
			'dry_run' => '1',
			'nonce'   => 'whatever',
		);

		$ajax = $this->build_ajax();
		try {
			$ajax->backfill_manifest();
			// Either path is OK — dry runs eventually wp_send_json_success
			// or wp_send_json_error from internal logic. What we're
			// asserting here is that we did NOT 409 on the overlap gate.
		} catch ( CFMediaOptimizerHttpExit $e ) {
			self::assertNotSame( 409, $e->status, 'dry-run must bypass the overlap lock' );
		}
	}

	/**
	 * Continuation chunks (cursor non-empty) must NOT be rejected by the
	 * lock even if the in-flight transient is set — that IS the run we
	 * are continuing.
	 */
	public function test_backfill_overlap_lock_allows_continuation_chunk(): void {
		$GLOBALS['cf_media_manager_test_user_caps'] = array( 'manage_options' => true );
		set_transient( Options::BACKFILL_LOCK, time(), Options::BACKFILL_LOCK_TTL );

		$_POST = array(
			'cursor'  => '2024',  // continuing
			'dry_run' => '0',
			'nonce'   => 'whatever',
		);

		$ajax = $this->build_ajax();
		try {
			$ajax->backfill_manifest();
		} catch ( CFMediaOptimizerHttpExit $e ) {
			self::assertNotSame( 409, $e->status, 'continuation chunk must bypass the overlap gate' );
		}
		$_POST = array();
	}

	// ====================================================================
	// H3 — bulk_insert_owns dedupe (VariantManifest::dedupe_writes)
	// ====================================================================

	public function test_dedupe_writes_drops_pairs_already_in_db(): void {
		$writes = array(
			array( 'post_id' => 1, 'meta_key' => '_cf_media_manager_owns_abc', 'meta_value' => 'a' ),
			array( 'post_id' => 1, 'meta_key' => '_cf_media_manager_owns_def', 'meta_value' => 'b' ),
			array( 'post_id' => 2, 'meta_key' => '_cf_media_manager_owns_abc', 'meta_value' => 'c' ),
		);
		$existing = array(
			array( 'post_id' => 1, 'meta_key' => '_cf_media_manager_owns_abc' ),
			array( 'post_id' => 2, 'meta_key' => '_cf_media_manager_owns_abc' ),
		);

		$remaining = VariantManifest::dedupe_writes( $writes, $existing );

		self::assertCount( 1, $remaining );
		self::assertSame( 1, $remaining[0]['post_id'] );
		self::assertSame( '_cf_media_manager_owns_def', $remaining[0]['meta_key'] );
	}

	public function test_dedupe_writes_is_noop_when_db_has_no_overlap(): void {
		$writes = array(
			array( 'post_id' => 1, 'meta_key' => '_cf_media_manager_owns_abc', 'meta_value' => 'a' ),
			array( 'post_id' => 2, 'meta_key' => '_cf_media_manager_owns_def', 'meta_value' => 'b' ),
		);
		$remaining = VariantManifest::dedupe_writes( $writes, array() );
		self::assertCount( 2, $remaining );
	}

	public function test_dedupe_writes_handles_post_id_collision_separate_keys(): void {
		// Same post_id, different meta_keys — only the matching pair should
		// be dropped, not "everything for post 1".
		$writes = array(
			array( 'post_id' => 1, 'meta_key' => '_cf_media_manager_owns_aaa', 'meta_value' => 'a' ),
			array( 'post_id' => 1, 'meta_key' => '_cf_media_manager_owns_bbb', 'meta_value' => 'b' ),
			array( 'post_id' => 1, 'meta_key' => '_cf_media_manager_owns_ccc', 'meta_value' => 'c' ),
		);
		$existing = array(
			array( 'post_id' => 1, 'meta_key' => '_cf_media_manager_owns_bbb' ),
		);
		$remaining = VariantManifest::dedupe_writes( $writes, $existing );
		self::assertCount( 2, $remaining );
		$keys = array_map( static fn( $r ) => $r['meta_key'], $remaining );
		self::assertContains( '_cf_media_manager_owns_aaa', $keys );
		self::assertContains( '_cf_media_manager_owns_ccc', $keys );
		self::assertNotContains( '_cf_media_manager_owns_bbb', $keys );
	}
}
