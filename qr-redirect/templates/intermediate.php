<?php
/**
 * Part of CF QR Redirect.
 * Licensed under the GNU GPL v2 or later — see the LICENSE block in cf-qr-redirect.php.
 *
 * Intermediate redirect page (Mode B).
 * Renders a minimal HTML document, fires a GA4 event, then JS-redirects.
 *
 * Variables in scope (set by CFQR_Analytics::render_intermediate):
 *   $ga4_id      string   Resolved GA4 Measurement ID (G-XXXXXXX)
 *   $short_code  string   The /r/{slug}
 *   $destination string   Final destination URL
 *   $campaign    string   Campaign name (may be empty)
 *   $nonce       string   Per-request nonce for CSP script-src
 *
 * @package CFQR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Variables defined below are template-scope locals consumed by the include
// in CFQR_Analytics::render_intermediate() — they're not WordPress globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// The <script> blocks below carry per-request CSP nonces and dynamic JSON
// payloads (destination, GA4 ID, event payload) that change every render —
// they cannot be enqueued via wp_enqueue_script.
// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript

// Send only the destination host to GA4, not the full URL. Full destination
// URLs may contain tokens, signed parameters, coupon codes, or customer
// identifiers that should not be forwarded to a third-party analytics service.
// The short_code (slug) already identifies which code fired — the host gives
// enough context to see where the traffic landed without leaking URL parameters.
$dest_parts       = wp_parse_url( $destination );
$destination_host = isset( $dest_parts['host'] ) ? strtolower( (string) $dest_parts['host'] ) : '';

$payload = array(
	'short_code'       => (string) $short_code,
	'destination_host' => $destination_host,
	'campaign'         => (string) $campaign,
	'traffic_type'     => 'qr',
);

// Tighten JSON encoding for <script> embedding: hex-encode characters that could
// terminate the script tag or break out of string literals.
$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="no-referrer">
<?php
/**
 * 10s meta-refresh is a true belt-and-suspenders fallback. The JS path
		below normally wins within ~500ms; raising the delay from 3s to 10s
		avoids losing GA4 events on slow (e.g. 3G) connections where the
		gtag library hadn't finished loading at the 3s mark. JS-off users
		are handled by the immediate-fire <noscript> path elsewhere.
 */
?>
<meta http-equiv="refresh" content="10;url=<?php echo esc_url( $destination ); ?>">
<title>Redirecting&hellip;</title>
<style>html,body{margin:0;padding:0;background:#fff;color:#222;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif}.wrap{display:flex;align-items:center;justify-content:center;min-height:100vh;font-size:14px;color:#666}</style>
</head>
<body>
<div class="wrap">Redirecting&hellip;</div>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( rawurlencode( $ga4_id ) ); ?>" nonce="<?php echo esc_attr( $nonce ); ?>"></script>
<script nonce="<?php echo esc_attr( $nonce ); ?>">
(function(){
	window.dataLayer = window.dataLayer || [];
	function gtag(){ dataLayer.push(arguments); }
	gtag('js', new Date());
	gtag('config', <?php echo wp_json_encode( $ga4_id, $json_flags ); ?>, { 'send_page_view': false });
	var dest = <?php echo wp_json_encode( $destination, $json_flags ); ?>;
	var payload = <?php echo wp_json_encode( $payload, $json_flags ); ?>;
	var redirected = false;
	function go(){
		if (redirected) return;
		redirected = true;
		window.location.replace(dest);
	}
	try {
		gtag('event', 'qr_redirect', Object.assign({}, payload, {
			event_callback: go,
			event_timeout: 500
		}));
	} catch (e) {
		go();
		return;
	}
	// Safety net: if gtag never fires the callback (blocker, network failure), redirect anyway.
	// 500ms captures the vast majority of successful gtag fires while keeping perceived
	// latency well under the threshold where users start to think a QR scan is broken.
	setTimeout(go, 500);
})();
</script>
<noscript><meta http-equiv="refresh" content="0;url=<?php echo esc_url( $destination ); ?>"></noscript>
</body>
</html>
<?php
// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
