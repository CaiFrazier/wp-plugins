<?php
/**
 * Part of CF QR Redirect.
 * Licensed under the GNU GPL v2 or later — see the LICENSE block in cf-qr-redirect.php.
 *
 * Routing layer for the standard redirect manager.
 *
 * Hooks parse_request (priority 0) so we intercept incoming requests BEFORE
 * WordPress queries posts. If the path matches a published, active redirect,
 * we record the hit and wp_redirect() with the configured status. If nothing
 * matches, we fall through and WP routes normally.
 *
 * @package CFQR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFQR_Redirect_Router {

	const DEDUPE_TTL                = 30;
	const RATE_LIMIT_WRITES_PER_SEC = 10;

	// Object-cache key for the exact-match path → post_id lookup table. Primed
	// on first miss and busted on save/delete/trash/untrash. The table is small
	// (typically <1000 entries) so we keep the entire map in memory rather than
	// querying postmeta per request.
	const CACHE_GROUP        = 'cfqr';
	const CACHE_KEY_EXACT    = 'redirect_exact_map';
	const CACHE_KEY_PATTERNS = 'redirect_pattern_list';

	// Hard ceiling on how many hops we walk when checking a proposed exact-mode
	// redirect for a chain that loops back to its own source. Chains this long
	// are pathological; if one exists we fail open (allow the save) and let the
	// request-time browser redirect cap stop the loop.
	const MAX_LOOP_DEPTH = 25;

	public static function init() {
		// Fires very early — before WP_Query has resolved the request, before
		// any template logic, before the QR router's template_redirect hook.
		// Anything that matches here short-circuits the rest of WordPress.
		add_action( 'parse_request', array( __CLASS__, 'maybe_redirect' ), 0 );

		// Cache invalidation on any redirect post mutation.
		add_action( 'save_post_' . CFQR_REDIRECT_POST_TYPE, array( __CLASS__, 'invalidate_cache' ) );
		add_action( 'deleted_post', array( __CLASS__, 'invalidate_cache_if_redirect' ) );
		add_action( 'trashed_post', array( __CLASS__, 'invalidate_cache_if_redirect' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'invalidate_cache_if_redirect' ) );
	}

	/**
	 * Inspect the incoming request and act if it matches a published redirect.
	 */
	public static function maybe_redirect() {
		// Skip admin, cron, REST, AJAX — we only handle real public requests.
		if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		// Only GET/HEAD. Same rationale as CFQR_Router: scanners don't POST,
		// and we don't want a misconfigured form to count as a redirect hit.
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';
		if ( '' === $request_uri ) {
			return;
		}

		// Never hijack /r/{slug} — that namespace belongs to the QR router,
		// which fires later in the request lifecycle. A redirect with source
		// "/r/anything" would shadow QR codes and would have been rejected at
		// save time, but enforce here too as belt-and-suspenders.
		$path_only = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( 0 === strpos( ltrim( $path_only, '/' ), trailingslashit( CFQR_REWRITE_SLUG ) ) ) {
			return;
		}

		// Never hijack wp-admin, wp-login, wp-content, wp-includes, wp-json.
		$first_segment = strtolower( ltrim( $path_only, '/' ) );
		$first_segment = explode( '/', $first_segment, 2 )[0];
		$reserved      = array( 'wp-admin', 'wp-login.php', 'wp-content', 'wp-includes', 'wp-json', 'wp-cron.php', 'xmlrpc.php' );
		if ( in_array( $first_segment, $reserved, true ) ) {
			return;
		}

		$matched = self::match( $request_uri );
		if ( ! $matched ) {
			return;
		}
		$post_id  = (int) $matched['id'];
		$captures = isset( $matched['matches'] ) && is_array( $matched['matches'] ) ? $matched['matches'] : array();

		$destination = (string) get_post_meta( $post_id, CFQR_Redirect_CPT::META_DESTINATION, true );
		$destination = trim( $destination );

		// Pattern modes (wildcard / regex) embed capture references like $1, $2
		// in the destination template. Substitute them in before the safety check
		// — the substituted URL is the one we actually emit, so it's the one
		// that needs to be safe.
		if ( ! empty( $captures ) ) {
			$destination = self::apply_captures( $destination, $captures );
		}

		if ( ! CFQR_URL::is_safe_destination( $destination ) ) {
			// Misconfigured — don't redirect to a rejected destination; fall
			// through to normal WP routing so the user still sees a real page.
			return;
		}

		// Prevent self-redirect loops at the absolute last moment. Save-time
		// validation blocks the obvious case (destination's local path equals
		// source path), but pattern modes can substitute captures into a
		// destination that loops back through this same redirect — only
		// detectable post-substitution.
		$dest_path = self::extract_local_path( $destination );
		if ( null !== $dest_path && CFQR_Redirect_CPT::normalize_path( $dest_path ) === CFQR_Redirect_CPT::normalize_path( $request_uri ) ) {
			return;
		}

		$slug = (string) get_post_field( 'post_name', $post_id );
		if ( ! self::is_duplicate_scan( $slug ) && ! self::is_rate_limited( $slug ) ) {
			self::record_hit( $post_id );
		}

		$status = (int) get_post_meta( $post_id, CFQR_Redirect_CPT::META_STATUS_CODE, true );
		if ( ! in_array( $status, CFQR_Redirect_CPT::ALLOWED_STATUSES, true ) ) {
			$status = 302;
		}

		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Referrer-Policy: no-referrer', true );
		// Without no-store, an aggressive CDN or browser may cache the 301/302
		// itself and keep sending users to the old destination even after the
		// admin changes it. Explicit no-store keeps destination edits effective.
		header( 'Cache-Control: no-store, no-cache, must-revalidate, private', true );
		nocache_headers();

		// External destinations are the feature — wp_safe_redirect refuses
		// cross-host targets. CFQR_URL has already validated the destination.
		wp_redirect( $destination, $status ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Resolve a request URI to a matching redirect, if any.
	 * Tries exact match first (fast O(1) hash lookup), then falls through to
	 * pattern modes in specificity order (query-aware → wildcard → regex).
	 *
	 * @param string $request_uri Raw REQUEST_URI value (path + query).
	 * @return array{id:int,matches:array<int,string>}|null
	 *     - id      : matching post ID
	 *     - matches : capture groups from the matcher (empty for exact mode)
	 */
	private static function match( $request_uri ) {
		// 1. Exact path match. Hot path — single hash lookup.
		$normalized = CFQR_Redirect_CPT::normalize_path( $request_uri );
		$map        = self::get_exact_map();
		if ( isset( $map[ $normalized ] ) ) {
			$row = $map[ $normalized ];
			// Schedule window check is at request time, not cache-build time,
			// so a redirect that crosses an active_from boundary starts firing
			// without a cache flush. is_within_schedule is a cheap O(1) check.
			if ( ! CFQR_Redirect_CPT::is_within_schedule( $row['from'] ?? '', $row['until'] ?? '' ) ) {
				return null;
			}
			return array(
				'id'      => (int) $row['id'],
				'matches' => array(),
			);
		}

		// 2. Pattern modes. Iteration order is set by get_pattern_list() (longest
		// source first); within the same length, query-aware → wildcard → regex.
		$patterns = self::get_pattern_list();
		foreach ( $patterns as $pattern ) {
			// Same per-request schedule check as the exact-map path.
			if ( ! CFQR_Redirect_CPT::is_within_schedule( $pattern['from'] ?? '', $pattern['until'] ?? '' ) ) {
				continue;
			}
			$result = null;
			switch ( $pattern['mode'] ) {
				case CFQR_Redirect_CPT::MATCH_QUERY_AWARE:
					$result = self::match_query_aware( $request_uri, $pattern );
					break;
				case CFQR_Redirect_CPT::MATCH_WILDCARD:
					$result = self::match_wildcard( $request_uri, $pattern );
					break;
				case CFQR_Redirect_CPT::MATCH_REGEX:
					$result = self::match_regex( $request_uri, $pattern );
					break;
			}
			if ( null !== $result ) {
				return $result;
			}
		}
		return null;
	}

	/**
	 * Wildcard matcher. Translates every `*` in the source pattern into a
	 * regex capture group `(.*)`, then preg_matches the request path. Matches
	 * are returned for capture substitution into the destination template
	 * (e.g. source `/blog/*`, destination `/news/$1` rewrites `/blog/foo` to
	 * `/news/foo`).
	 *
	 * Case-insensitive: wildcards are a user-friendly UX feature, so
	 * `/Blog/Foo` and `/blog/foo` collapse to the same match. Regex mode is
	 * the escape hatch for users who need case-sensitive matching.
	 */
	private static function match_wildcard( $request_uri, $pattern ) {
		$request_path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$subject      = CFQR_Redirect_CPT::normalize_path( $request_path );
		$compiled     = self::compile_wildcard_to_regex( $pattern['source'] );
		if ( null === $compiled ) {
			return null;
		}
		// @-suppress: user-supplied source might compile to a pathological regex.
		// preg_match returns false on error (PCRE failure or backtrack-limit hit);
		// treat that as a non-match so the request falls through to normal WP routing.
		$matched = @preg_match( $compiled, $subject, $matches ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.NoSilencedErrors
		if ( 1 !== $matched ) {
			return null;
		}
		return array(
			'id'      => (int) $pattern['id'],
			'matches' => $matches,
		);
	}

	/**
	 * Compile a wildcard source pattern into an anchored, case-insensitive
	 * regex. The source is normalized first, then quoted to escape regex
	 * metachars, then the escaped "\*" sequences are turned back into capture
	 * groups. Source "/blog/*" becomes regex "#^/blog/(.*)$#i".
	 *
	 * @return string|null Delimited regex, or null if the source compiled to nothing useful.
	 */
	private static function compile_wildcard_to_regex( $source ) {
		$normalized = CFQR_Redirect_CPT::normalize_path( $source );
		if ( '' === $normalized || '/' === $normalized ) {
			return null;
		}
		$escaped = preg_quote( $normalized, '#' );
		// preg_quote escapes * as \*; turn that back into a capture group.
		// We use (.*) (greedy) rather than ([^/]+) so a single * can match
		// across path segments. Users who need single-segment matching can
		// drop into regex mode.
		$regex = str_replace( '\*', '(.*)', $escaped );
		return '#^' . $regex . '$#i';
	}

	/**
	 * Regex matcher. The user supplies a raw PCRE pattern (no delimiters);
	 * we wrap it in `#…#` at match time. Matches are returned for capture
	 * substitution into the destination template.
	 *
	 * Safety: pattern is length-capped at save time (validate_source in admin),
	 * @-suppression here catches PCRE compile failures or backtrack-limit hits
	 * without bubbling warnings to output. Case-sensitivity is up to the user
	 * (regex mode is the power-user escape hatch; default is case-sensitive).
	 */
	private static function match_regex( $request_uri, $pattern ) {
		$request_path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$compiled     = self::compile_user_regex( $pattern['source'] );
		if ( null === $compiled ) {
			return null;
		}
		$matched = @preg_match( $compiled, $request_path, $matches ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.NoSilencedErrors
		if ( 1 !== $matched ) {
			return null;
		}
		return array(
			'id'      => (int) $pattern['id'],
			'matches' => $matches,
		);
	}

	/**
	 * Wrap a user-supplied regex pattern with delimiters. Escapes any literal
	 * `#` in the pattern so the delimiter never clashes with the body.
	 * Returns null if the pattern fails to compile (verified with a dry-run
	 * preg_match against an empty subject — false return signals compile fail).
	 */
	public static function compile_user_regex( $source ) {
		$source = (string) $source;
		if ( '' === $source ) {
			return null;
		}
		$delimited = '#' . str_replace( '#', '\#', $source ) . '#';
		// Dry-run compile check. preg_match returns false on PCRE error,
		// 0 on no-match-against-empty, 1 on match-against-empty (unlikely
		// but possible for `.*`-style patterns). We only treat false as a
		// compile failure.
		$probe = @preg_match( $delimited, '' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.NoSilencedErrors
		if ( false === $probe ) {
			return null;
		}
		return $delimited;
	}

	/**
	 * Query-aware matcher. Matches when:
	 *   1. The request path equals the source path (after normalization).
	 *   2. Every query parameter named in the source is present in the request
	 *      with the same value. Extra params on the request are ignored.
	 *
	 * Example: source `/search?q=foo` matches `/search?q=foo&page=2` but not
	 * `/search?q=bar` or `/search`. This is a strict subset match — useful for
	 * funnelling a specific search-result or filter URL to a landing page.
	 *
	 * No captures: query-aware matches return an empty `matches` array. Use
	 * wildcard or regex mode if you need to substitute captured values into
	 * the destination.
	 */
	private static function match_query_aware( $request_uri, $pattern ) {
		$req       = wp_parse_url( $request_uri );
		$req_path  = is_array( $req ) ? (string) ( $req['path'] ?? '' ) : '';
		$req_query = array();
		if ( is_array( $req ) && isset( $req['query'] ) ) {
			wp_parse_str( $req['query'], $req_query );
		}

		$src       = wp_parse_url( $pattern['source'] );
		$src_path  = is_array( $src ) ? (string) ( $src['path'] ?? '' ) : '';
		$src_query = array();
		if ( is_array( $src ) && isset( $src['query'] ) ) {
			wp_parse_str( $src['query'], $src_query );
		}

		if ( CFQR_Redirect_CPT::normalize_path( $req_path ) !== CFQR_Redirect_CPT::normalize_path( $src_path ) ) {
			return null;
		}

		// Every param the source specifies must be present on the request with
		// the same value. Type-loose comparison: wp_parse_str returns strings
		// for scalar values.
		foreach ( $src_query as $key => $value ) {
			if ( ! array_key_exists( $key, $req_query ) ) {
				return null;
			}
			if ( (string) $req_query[ $key ] !== (string) $value ) {
				return null;
			}
		}

		return array(
			'id'      => (int) $pattern['id'],
			'matches' => array(),
		);
	}

	/**
	 * Substitute `$N` references in a destination template with the
	 * corresponding capture group. `$0` is the full match; `$1`..`$9` are
	 * capture groups. Missing references resolve to an empty string.
	 *
	 * Uses preg_replace_callback so multi-digit indices (`$10`, `$11`) work
	 * cleanly without `$10` colliding with `$1` + literal `0`.
	 */
	public static function apply_captures( $destination, $matches ) {
		if ( empty( $matches ) ) {
			return $destination;
		}
		return preg_replace_callback(
			'/\$(\d+)/',
			function ( $m ) use ( $matches ) {
				$idx = (int) $m[1];
				return isset( $matches[ $idx ] ) ? (string) $matches[ $idx ] : '';
			},
			$destination
		);
	}

	/**
	 * Build (or fetch from cache) the exact-match path → row map.
	 *
	 * Each row carries everything the request-time matcher needs to decide
	 * whether to fire: post_id, plus the optional schedule window. The window
	 * is checked at request time, not at cache-build time, so a redirect that
	 * crosses an active_from boundary starts firing without a cache flush.
	 *
	 * @return array<string,array{id:int,from:string,until:string}>
	 */
	public static function get_exact_map() {
		$cached = wp_cache_get( self::CACHE_KEY_EXACT, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		// Single pivot: one row per post with every relevant meta value flattened.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID,
				        MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS source_normalized,
				        MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS match_mode,
				        MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS active,
				        MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS active_from,
				        MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS active_until
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE p.post_type = %s
				   AND p.post_status = 'publish'
				   AND pm.meta_key IN (%s, %s, %s, %s, %s)
				 GROUP BY p.ID",
				CFQR_Redirect_CPT::META_SOURCE_NORMALIZED,
				CFQR_Redirect_CPT::META_MATCH_MODE,
				CFQR_Redirect_CPT::META_ACTIVE,
				CFQR_Redirect_CPT::META_ACTIVE_FROM,
				CFQR_Redirect_CPT::META_ACTIVE_UNTIL,
				CFQR_REDIRECT_POST_TYPE,
				CFQR_Redirect_CPT::META_SOURCE_NORMALIZED,
				CFQR_Redirect_CPT::META_MATCH_MODE,
				CFQR_Redirect_CPT::META_ACTIVE,
				CFQR_Redirect_CPT::META_ACTIVE_FROM,
				CFQR_Redirect_CPT::META_ACTIVE_UNTIL
			)
		);

		$map = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$mode   = (string) ( $row->match_mode ?? CFQR_Redirect_CPT::MATCH_EXACT );
				$active = (string) ( $row->active ?? '1' );
				if ( CFQR_Redirect_CPT::MATCH_EXACT !== $mode ) {
					continue;
				}
				if ( '0' === $active || '' === $active || 'false' === $active ) {
					continue;
				}
				$key = (string) ( $row->source_normalized ?? '' );
				if ( '' === $key ) {
					continue;
				}
				$map[ $key ] = array(
					'id'    => (int) $row->ID,
					'from'  => (string) ( $row->active_from ?? '' ),
					'until' => (string) ( $row->active_until ?? '' ),
				);
			}
		}

		wp_cache_set( self::CACHE_KEY_EXACT, $map, self::CACHE_GROUP, 0 );
		return $map;
	}

	/**
	 * Build (or fetch from cache) the list of pattern-mode redirects (wildcard,
	 * regex, query-aware). Kept as a separate cache slot from get_exact_map()
	 * so exact-match lookups stay O(1) even with a large pattern set.
	 *
	 * Ordering: by source length descending, then by mode-specificity
	 * (query-aware → wildcard → regex). Longer/more-specific patterns are
	 * evaluated first so `/blog/2020/*` beats `/blog/*` for `/blog/2020/foo`.
	 *
	 * @return array<int,array{id:int,mode:string,source:string}>
	 */
	public static function get_pattern_list() {
		$cached = wp_cache_get( self::CACHE_KEY_PATTERNS, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		// Same pivot pattern as get_exact_map(): one row per post with the
		// three meta values flattened. INNER JOIN means posts with none of
		// these meta keys won't appear, which is fine — they can't be routed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID,
				        MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS source,
				        MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS mode,
				        MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS active,
				        MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS active_from,
				        MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS active_until
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE p.post_type = %s
				   AND p.post_status = 'publish'
				   AND pm.meta_key IN (%s, %s, %s, %s, %s)
				 GROUP BY p.ID",
				CFQR_Redirect_CPT::META_SOURCE_PATH,
				CFQR_Redirect_CPT::META_MATCH_MODE,
				CFQR_Redirect_CPT::META_ACTIVE,
				CFQR_Redirect_CPT::META_ACTIVE_FROM,
				CFQR_Redirect_CPT::META_ACTIVE_UNTIL,
				CFQR_REDIRECT_POST_TYPE,
				CFQR_Redirect_CPT::META_SOURCE_PATH,
				CFQR_Redirect_CPT::META_MATCH_MODE,
				CFQR_Redirect_CPT::META_ACTIVE,
				CFQR_Redirect_CPT::META_ACTIVE_FROM,
				CFQR_Redirect_CPT::META_ACTIVE_UNTIL
			)
		);

		$pattern_modes = array(
			CFQR_Redirect_CPT::MATCH_WILDCARD,
			CFQR_Redirect_CPT::MATCH_REGEX,
			CFQR_Redirect_CPT::MATCH_QUERY_AWARE,
		);

		$patterns = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$mode   = (string) ( $row->mode ?? '' );
				$active = (string) ( $row->active ?? '1' );
				$source = (string) ( $row->source ?? '' );
				if ( '' === $source ) {
					continue;
				}
				if ( ! in_array( $mode, $pattern_modes, true ) ) {
					continue;
				}
				if ( '0' === $active || '' === $active || 'false' === $active ) {
					continue;
				}
				$patterns[] = array(
					'id'     => (int) $row->ID,
					'mode'   => $mode,
					'source' => $source,
					'from'   => (string) ( $row->active_from ?? '' ),
					'until'  => (string) ( $row->active_until ?? '' ),
				);
			}
		}

		// Sort: source length DESC primary (more-specific wins), mode tier
		// ASC secondary (query-aware tier 0, wildcard tier 1, regex tier 2 —
		// the more constrained mode wins on a length tie).
		$mode_tier = array(
			CFQR_Redirect_CPT::MATCH_QUERY_AWARE => 0,
			CFQR_Redirect_CPT::MATCH_WILDCARD    => 1,
			CFQR_Redirect_CPT::MATCH_REGEX       => 2,
		);
		usort(
			$patterns,
			static function ( $a, $b ) use ( $mode_tier ) {
				$len_diff = strlen( $b['source'] ) - strlen( $a['source'] );
				if ( 0 !== $len_diff ) {
					return $len_diff;
				}
				return ( $mode_tier[ $a['mode'] ] ?? 99 ) - ( $mode_tier[ $b['mode'] ] ?? 99 );
			}
		);

		wp_cache_set( self::CACHE_KEY_PATTERNS, $patterns, self::CACHE_GROUP, 0 );
		return $patterns;
	}

	public static function invalidate_cache() {
		wp_cache_delete( self::CACHE_KEY_EXACT, self::CACHE_GROUP );
		wp_cache_delete( self::CACHE_KEY_PATTERNS, self::CACHE_GROUP );
	}

	public static function invalidate_cache_if_redirect( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || CFQR_REDIRECT_POST_TYPE !== $post->post_type ) {
			return;
		}
		self::invalidate_cache();
	}

	/**
	 * Record a hit. Same atomic-CAST pattern as CFQR_Router::record_hit — done
	 * on shutdown so the redirect response goes out before we touch the DB.
	 */
	private static function record_hit( $post_id ) {
		$post_id = (int) $post_id;
		add_action(
			'shutdown',
			function () use ( $post_id ) {
				global $wpdb;
				$now = gmdate( 'c' );

				if ( '' === (string) get_post_meta( $post_id, CFQR_Redirect_CPT::META_HIT_COUNT, true ) ) {
					add_post_meta( $post_id, CFQR_Redirect_CPT::META_HIT_COUNT, 0, true );
				}
				if ( '' === (string) get_post_meta( $post_id, CFQR_Redirect_CPT::META_LAST_HIT_AT, true ) ) {
					add_post_meta( $post_id, CFQR_Redirect_CPT::META_LAST_HIT_AT, '', true );
				}

				// Atomic increment + timestamp stamp in one statement. There is
				// no postmeta API equivalent for CAST(meta_value AS UNSIGNED)+1,
				// and read-modify-write would race under concurrent traffic.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->postmeta} SET meta_value = CASE
							WHEN meta_key = %s THEN CAST(meta_value AS UNSIGNED) + 1
							WHEN meta_key = %s THEN %s
						END
						WHERE post_id = %d AND meta_key IN (%s, %s)",
						CFQR_Redirect_CPT::META_HIT_COUNT,
						CFQR_Redirect_CPT::META_LAST_HIT_AT,
						$now,
						$post_id,
						CFQR_Redirect_CPT::META_HIT_COUNT,
						CFQR_Redirect_CPT::META_LAST_HIT_AT
					)
				);
				wp_cache_delete( $post_id, 'post_meta' );
			},
			1
		);
	}

	/**
	 * Per-fingerprint dedupe. Uses a separate transient namespace from
	 * CFQR_Router so the two routers' dedupe windows never collide for the
	 * same IP+UA pair (a user might hit both a QR shortlink and a standard
	 * redirect in quick succession; those are different events).
	 */
	private static function is_duplicate_scan( $slug ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
		if ( '' === $ip && '' === $ua ) {
			return false;
		}
		$fingerprint = substr(
			hash_hmac(
				'sha256',
				$ip . '|' . $ua . '|' . (string) $slug,
				wp_salt( 'nonce' )
			),
			0,
			32
		);
		$key = 'cfqr_rd_seen_' . $fingerprint;
		if ( false !== get_transient( $key ) ) {
			return true;
		}
		set_transient( $key, 1, self::DEDUPE_TTL );
		return false;
	}

	private static function is_rate_limited( $slug ) {
		$slug = (string) $slug;
		if ( '' === $slug ) {
			return false;
		}
		$key   = 'cfqr_rd_rate_' . md5( $slug );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_WRITES_PER_SEC ) {
			return true;
		}
		set_transient( $key, $count + 1, 1 );
		return false;
	}

	/**
	 * Would saving an exact-mode redirect from $source_normalized to
	 * $destination create a loop that eventually routes back to the source?
	 *
	 * Walks the chain of exact-mode redirects starting at the destination's
	 * local path: dest → its redirect (if any) → that redirect's dest → … and
	 * reports true if the walk lands back on the source we're about to save.
	 * This catches both the direct self-loop (A → A) and multi-hop cycles
	 * (A → B → A) that the per-row self-loop guard misses.
	 *
	 * Only exact-mode redirects participate — pattern modes substitute captures
	 * at request time and can't be resolved statically here; their loops are
	 * caught by the request-time guard in maybe_redirect().
	 *
	 * Fail-open: a cross-host hop, a missing link in the chain, or a chain
	 * longer than MAX_LOOP_DEPTH all return false (allow the save). The
	 * request-time self-loop guard and the browser's redirect cap remain as
	 * backstops.
	 *
	 * @param string $source_normalized Normalized source path of the redirect being saved.
	 * @param string $destination       Proposed destination URL.
	 * @return bool True if the chain loops back to the source.
	 */
	public static function would_create_loop( $source_normalized, $destination ) {
		$path = self::extract_local_path( $destination );
		if ( null === $path ) {
			return false;
		}

		$map     = self::get_exact_map();
		$current = CFQR_Redirect_CPT::normalize_path( $path );
		$visited = array();

		for ( $depth = 0; $depth < self::MAX_LOOP_DEPTH; $depth++ ) {
			if ( $current === $source_normalized ) {
				return true;
			}
			// A cycle that doesn't include the source (e.g. B → C → B). Not our
			// problem to block here — the existing chain already had it — and we
			// must stop walking or we'd spin forever.
			if ( isset( $visited[ $current ] ) ) {
				return false;
			}
			$visited[ $current ] = true;

			if ( ! isset( $map[ $current ] ) ) {
				return false;
			}
			$next_dest = (string) get_post_meta( (int) $map[ $current ]['id'], CFQR_Redirect_CPT::META_DESTINATION, true );
			$next_path = self::extract_local_path( trim( $next_dest ) );
			if ( null === $next_path ) {
				return false;
			}
			$current = CFQR_Redirect_CPT::normalize_path( $next_path );
		}

		return false;
	}

	/**
	 * If a destination URL points at this same host, return its path so we can
	 * compare against the source for loop detection. Returns null for any
	 * cross-host destination (those can't loop back through this router).
	 */
	private static function extract_local_path( $destination ) {
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$parsed    = wp_parse_url( $destination );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return null;
		}
		if ( strtolower( (string) $parsed['host'] ) !== $home_host ) {
			return null;
		}
		return ( $parsed['path'] ?? '/' );
	}
}
