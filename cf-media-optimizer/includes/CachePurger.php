<?php

namespace CFMediaOptimizer;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Detect and purge known caching layers in the correct order.
 *
 * Order matters. Application-level caches (object cache, page-builder
 * compiled CSS) must be invalidated before page caches, and page caches
 * before edge/CDN — otherwise a page cache can re-cache stale HTML
 * generated from a stale object cache, and the edge can re-cache stale
 * HTML from the page cache.
 */
final class CachePurger {

	/**
	 * Each layer is [ key, label, detect, purge ].
	 *
	 * detect() returns true when the layer is loaded in this WP install.
	 * purge() does the work; it may return void/bool/throw — outcomes are
	 * captured by purge_all().
	 *
	 * Closures rather than method names so the registry stays declarative
	 * and a test can replace any single layer with a mock.
	 */
	public function registry(): array {
		return [
			[
				'key'    => 'wp_object',
				'label'  => 'WordPress object cache',
				'detect' => static function () {
					return function_exists( 'wp_cache_flush' );
				},
				'purge'  => static function () {
					wp_cache_flush();
				},
			],
			[
				'key'    => 'divi_static',
				'label'  => 'Divi static resources',
				'detect' => static function () {
					return class_exists( 'ET_Core_PageResource' );
				},
				'purge'  => static function () {
					if ( method_exists( 'ET_Core_PageResource', 'remove_static_resources' ) ) {
						\ET_Core_PageResource::remove_static_resources( 'all', 'all', true );
					}
				},
			],
			[
				'key'    => 'divi_template',
				'label'  => 'Divi template cache',
				'detect' => static function () {
					return function_exists( 'et_core_clear_template_cache' );
				},
				'purge'  => static function () {
					et_core_clear_template_cache();
				},
			],
			[
				'key'    => 'wpe',
				'label'  => 'WP Engine page cache',
				'detect' => static function () {
					return class_exists( 'WpeCommon' );
				},
				'purge'  => static function () {
					if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
						\WpeCommon::purge_memcached();
					}
					if ( method_exists( 'WpeCommon', 'clear_maxcdn_cache' ) ) {
						\WpeCommon::clear_maxcdn_cache();
					}
					if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
						\WpeCommon::purge_varnish_cache();
					}
				},
			],
			[
				'key'    => 'rocket',
				'label'  => 'WP Rocket',
				'detect' => static function () {
					return function_exists( 'rocket_clean_domain' );
				},
				'purge'  => static function () {
					rocket_clean_domain();
					if ( function_exists( 'rocket_clean_minify' ) ) {
						rocket_clean_minify();
					}
				},
			],
			[
				'key'    => 'litespeed',
				'label'  => 'LiteSpeed Cache',
				'detect' => static function () {
					return defined( 'LSCWP_V' ) || class_exists( '\\LiteSpeed\\Purge' ) || class_exists( 'LiteSpeed_Cache_API' );
				},
				'purge'  => static function () {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook (LiteSpeed Cache); intentional integration.
					do_action( 'litespeed_purge_all' );
				},
			],
			[
				'key'    => 'w3tc',
				'label'  => 'W3 Total Cache',
				'detect' => static function () {
					return function_exists( 'w3tc_flush_all' );
				},
				'purge'  => static function () {
					w3tc_flush_all();
				},
			],
			[
				'key'    => 'wp_super_cache',
				'label'  => 'WP Super Cache',
				'detect' => static function () {
					return function_exists( 'wp_cache_clear_cache' );
				},
				'purge'  => static function () {
					wp_cache_clear_cache();
				},
			],
			[
				'key'    => 'sg_optimizer',
				'label'  => 'SG Optimizer (SiteGround)',
				'detect' => static function () {
					return function_exists( 'sg_cachepress_purge_cache' );
				},
				'purge'  => static function () {
					sg_cachepress_purge_cache();
				},
			],
			[
				'key'    => 'autoptimize',
				'label'  => 'Autoptimize',
				'detect' => static function () {
					return class_exists( 'autoptimizeCache' );
				},
				'purge'  => static function () {
					if ( method_exists( 'autoptimizeCache', 'clearall' ) ) {
						\autoptimizeCache::clearall();
					}
				},
			],
			[
				'key'    => 'cache_enabler',
				'label'  => 'Cache Enabler',
				'detect' => static function () {
					return class_exists( 'Cache_Enabler' );
				},
				'purge'  => static function () {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook (Cache Enabler); intentional integration.
					do_action( 'cache_enabler_clear_complete_cache' );
				},
			],
			[
				'key'    => 'hummingbird',
				'label'  => 'Hummingbird',
				'detect' => static function () {
					return class_exists( '\\Hummingbird\\WP_Hummingbird' );
				},
				'purge'  => static function () {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook (Hummingbird); intentional integration.
					do_action( 'wphb_clear_page_cache' );
				},
			],
			[
				'key'    => 'borlabs',
				'label'  => 'Borlabs Cache',
				'detect' => static function () {
					return defined( 'BORLABS_CACHE_PLUGIN_FILE' );
				},
				'purge'  => static function () {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook (Borlabs Cache); intentional integration.
					do_action( 'borlabsCache/cache/flushAll' );
				},
			],
			[
				'key'    => 'cloudflare',
				'label'  => 'Cloudflare (official plugin)',
				'detect' => static function () {
					return class_exists( '\\CF\\WordPress\\Hooks' );
				},
				'purge'  => static function () {
					if ( method_exists( '\\CF\\WordPress\\Hooks', 'purgeCacheEverything' ) ) {
						( new \CF\WordPress\Hooks() )->purgeCacheEverything();
					}
				},
			],
		];
	}

	/**
	 * Return active layers as [ [key, label], ... ] in purge order.
	 */
	public function detect_active(): array {
		$active = [];
		foreach ( $this->registry() as $layer ) {
			try {
				if ( call_user_func( $layer['detect'] ) ) {
					$active[] = [
						'key'   => $layer['key'],
						'label' => $layer['label'],
					];
				}
			} catch ( Throwable $e ) {
				// Detection should never throw; if it does, skip the layer.
			}
		}
		return $active;
	}

	/**
	 * Run the purge across every active layer. Returns per-layer outcomes:
	 * status is 'ok' or 'failed'; failures include the error message.
	 *
	 * Each call is wrapped in try/catch so one broken cache plugin can't
	 * abort the rest of the run.
	 */
	public function purge_all(): array {
		$results = [];
		foreach ( $this->registry() as $layer ) {
			try {
				if ( ! call_user_func( $layer['detect'] ) ) {
					continue;
				}
			} catch ( Throwable $e ) {
				continue;
			}

			try {
				call_user_func( $layer['purge'] );
				$results[] = [
					'key'    => $layer['key'],
					'label'  => $layer['label'],
					'status' => 'ok',
				];
			} catch ( Throwable $e ) {
				$results[] = [
					'key'    => $layer['key'],
					'label'  => $layer['label'],
					'status' => 'failed',
					'error'  => $e->getMessage(),
				];
			}
		}

		// Generic extension point for site owners with non-listed cache layers:
		// add_action( 'cf_media_manager_purge_caches', function () { my_custom_purge(); } );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook name carries the full plugin slug.
		do_action( 'cf_media_manager_purge_caches' );

		// Clear the pending-purge admin notice — the user has acted on it.
		delete_option( Options::PURGE_FLAG );

		return $results;
	}
}
