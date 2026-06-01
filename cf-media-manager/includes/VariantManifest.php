<?php

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

/**
 * Ownership tracking for generated WebP/AVIF variant files.
 *
 * Without this layer the plugin cannot distinguish a `.webp` it generated
 * from a `.webp` the user uploaded directly. That gap caused three risks:
 *
 *   1. Overwriting a user-uploaded `logo.webp` during a forced re-encode
 *      because its basename happens to match the generated derivative of
 *      `logo.jpg` in the same directory.
 *   2. Deleting a user-uploaded `banner.webp` during "Delete All Variants"
 *      because the scanner only knew that `banner.jpg` existed alongside it.
 *   3. The front-end rewriter substituting a user-uploaded `.webp` into a
 *      `<picture>` source for an unrelated same-basename JPG.
 *
 * Storage (1.2.2+):
 *   - One postmeta row per variant, keyed `_cf_media_manager_owns_<md5(rel)>` with
 *     `meta_value` = upload-relative path. `meta_key` is indexed in
 *     `wp_postmeta` so reverse lookups are O(log n) seeks rather than the
 *     full-table serialized-array LIKE scan that 1.2.0/1.2.1 used. Critical
 *     for front-end use: `Rewriter::variant_exists()` calls `is_owned()`
 *     on every candidate URL on every render.
 *   - Object cache layer in front of the DB lookup, keyed by the same hash.
 *     Cache hits never touch the DB.
 *
 * Legacy storage:
 *   - Pre-1.2.2 internal builds wrote `_cf_media_manager_variants` (serialized array
 *     of rel paths). Reads still consult that key as a fallback so installs
 *     that upgraded mid-cycle keep working until the admin re-runs backfill.
 *     Writes only touch the new format.
 */
class VariantManifest {

	/**
	 * Shared key prefix matched by every owns-row LIKE query in this class.
	 * Both versioned (`v1_<md5>`) and unversioned-legacy (`<md5>`) rows
	 * share this prefix, so a single LIKE catches the union.
	 */
	const META_KEY_PREFIX = '_cf_media_manager_owns_';

	/**
	 * Per-version exact-key prefixes. Writes always go to the current
	 * version ({@see META_KEY_PREFIX_V1}); reads check the current version
	 * first then fall back through earlier versions. Bumping
	 * {@see Plugin::MANIFEST_SCHEMA_VERSION} signals a format change that
	 * a new prefix here should accompany — and the read path should add
	 * a fallback for the previous version so existing installs keep
	 * working through the upgrade.
	 */
	const META_KEY_PREFIX_V1 = '_cf_media_manager_owns_v1_';
	const META_KEY_PREFIX_V0 = '_cf_media_manager_owns_';

	/**
	 * Pre-1.2.2 serialized-array key (the cf-webp era). Read-only — kept
	 * for forward-compat with installs that upgraded mid-cycle in early
	 * cf-webp versions before the per-variant key format landed.
	 */
	const META_KEY_LEGACY = '_cf_media_manager_variants';

	const CACHE_GROUP = 'cf_media_manager_owns';
	const CACHE_TTL   = 300;

	/**
	 * Compute the current-version meta key for a given upload-relative path.
	 * Writes go through this; reads start here and fall back if it misses.
	 */
	public static function meta_key_for( string $rel ): string {
		return self::META_KEY_PREFIX_V1 . md5( $rel );
	}

	/**
	 * Compute the v0 (unversioned) meta key for fallback reads.
	 */
	public static function meta_key_for_v0( string $rel ): string {
		return self::META_KEY_PREFIX_V0 . md5( $rel );
	}

	private Paths $paths;

	public function __construct( Paths $paths ) {
		$this->paths = $paths;
	}

	/**
	 * Record a generated variant as owned by the given attachment. Idempotent —
	 * re-recording the same path is a no-op.
	 *
	 * No-op when $attachment_id is 0 or negative (callers that don't know the
	 * source attachment fall through here without polluting the meta table).
	 */
	public function record( int $attachment_id, string $variant_path ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}
		$rel = $this->to_rel( $variant_path );
		if ( null === $rel ) {
			return;
		}
		$key = self::meta_key_for( $rel );
		if ( function_exists( 'add_post_meta' ) ) {
			// $unique=true makes this idempotent — add_post_meta returns
			// false (without writing) when an entry with this key already
			// exists on this post.
			add_post_meta( $attachment_id, $key, $rel, true );
		} elseif ( function_exists( 'update_post_meta' ) ) {
			update_post_meta( $attachment_id, $key, $rel );
		}
		$this->cache_set( $rel, true );
	}

	/**
	 * Fast ownership check when the source attachment is known. Single
	 * indexed postmeta read.
	 */
	public function is_owned_by( int $attachment_id, string $variant_path ): bool {
		if ( $attachment_id <= 0 ) {
			return false;
		}
		$rel = $this->to_rel( $variant_path );
		if ( null === $rel ) {
			return false;
		}
		// Current version (v1). WP caches all meta for the post on first
		// access, so checking multiple keys for the same post is one DB
		// trip, not three.
		$stored = get_post_meta( $attachment_id, self::meta_key_for( $rel ), true );
		if ( is_string( $stored ) && $stored === $rel ) {
			return true;
		}
		// v0 (pre-schema-versioning) fallback. Installs that ran Adopt on
		// 2.0.0a–2.0.0c wrote v0 rows; reading them keeps the existing
		// manifest intact across the upgrade without a forced re-run.
		$stored = get_post_meta( $attachment_id, self::meta_key_for_v0( $rel ), true );
		if ( is_string( $stored ) && $stored === $rel ) {
			return true;
		}
		// Pre-1.2.2 serialized-array fallback — cf-webp legacy.
		$legacy = get_post_meta( $attachment_id, self::META_KEY_LEGACY, true );
		return is_array( $legacy ) && in_array( $rel, $legacy, true );
	}

	/**
	 * Ownership check when the attachment id isn't known at the call site
	 * (e.g. the front-end rewriter sees a URL but not its attachment).
	 *
	 * Hot path. Goes through the object cache first; cache miss issues an
	 * exact-key indexed query against postmeta. The previous implementation
	 * used a leading-wildcard LIKE against the serialized-array `meta_value`
	 * column, which is unindexed `longtext` — a real performance footgun on
	 * the public render path.
	 *
	 * Three return values:
	 *   - true   : the variant is recorded as plugin-owned
	 *   - false  : no record claims this variant
	 *   - null   : ownership is undeterminable (no $wpdb — CLI bootstrap,
	 *              test harness, very early hook). Callers may treat null as
	 *              "indeterminate" and apply a permissive default.
	 *
	 * @return bool|null
	 */
	public function is_owned( string $variant_path ) {
		$rel = $this->to_rel( $variant_path );
		if ( null === $rel ) {
			return false;
		}

		$cached = $this->cache_get( $rel );
		if ( null !== $cached ) {
			return $cached;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return null;
		}

		// Check the current version first; fall back to v0 on miss. A v0
		// hit followed by a v1 miss means the row was written before the
		// schema-version marker was added — still owned, just by an older
		// writer.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				self::meta_key_for( $rel ),
				$rel
			)
		);
		if ( empty( $row ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			$row = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
					self::meta_key_for_v0( $rel ),
					$rel
				)
			);
		}
		$owned = ! empty( $row );

		// Pre-1.2.2 fallback — only consulted on a miss against the new
		// format, and only on this slow path. Object-cache the answer
		// either way so subsequent renders never repeat the work.
		//
		// Once the admin has run the legacy-variant backfill (BACKFILL_DONE
		// option is truthy), every legitimate legacy row has been migrated
		// to the v1 format and the LIKE scan can only ever return rows
		// that the rewriter shouldn't trust anyway. Skip it entirely —
		// that's a full-table scan on an unindexed LONGTEXT comparison,
		// and it runs on the public render path, so it's worth avoiding
		// once the migration is complete.
		if ( ! $owned && ! get_option( Options::BACKFILL_DONE ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			$legacy = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s LIMIT 1",
					self::META_KEY_LEGACY,
					'%' . $wpdb->esc_like( '"' . $rel . '"' ) . '%'
				)
			);
			$owned  = ! empty( $legacy );
		}

		$this->cache_set( $rel, $owned );
		return $owned;
	}

	/**
	 * Materialize the full set of owned absolute paths for the current site.
	 * Used by the deletion scanner so it can do an O(1) membership test per
	 * file without re-querying per row.
	 *
	 * @return array<string,true> map of absolute path => true
	 */
	public function build_owned_paths_set(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		$set  = array();
		$base = rtrim( $this->paths->upload_dir(), '/' ) . '/';

		// New format: one row per variant. Indexed prefix LIKE on meta_key.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( self::META_KEY_PREFIX ) . '%'
			)
		);
		if ( is_array( $rows ) ) {
			foreach ( $rows as $rel ) {
				if ( ! is_string( $rel ) || '' === $rel ) {
					continue;
				}
				$set[ $base . ltrim( $rel, '/' ) ] = true;
			}
		}

		// Legacy fallback so a Delete All on an upgraded install still
		// removes the variants the user adopted under the pre-1.2.2 format.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$legacy_rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_KEY_LEGACY
			)
		);
		if ( is_array( $legacy_rows ) ) {
			foreach ( $legacy_rows as $row ) {
				$list = function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $row ) : @unserialize( $row );
				if ( ! is_array( $list ) ) {
					continue;
				}
				foreach ( $list as $rel ) {
					if ( ! is_string( $rel ) || '' === $rel ) {
						continue;
					}
					$set[ $base . ltrim( $rel, '/' ) ] = true;
				}
			}
		}

		return $set;
	}

	/**
	 * Per-attachment ownership membership set for the bulk-backfill path.
	 *
	 * Unlike {@see build_owned_paths_set()} (path-only, used by Delete All),
	 * this returns a {`<abs_path>|<post_id>` => true} set so the backfill
	 * can verify "is THIS path owned by THIS attachment" in O(1). That
	 * distinction matters because of basename collisions: `logo.webp` can
	 * legitimately be claimed under both `logo.png`'s attachment AND
	 * `logo.jpg`'s attachment, and we don't want to skip the second claim
	 * just because the first one already exists.
	 *
	 * Only the new per-variant key format (`_cf_media_manager_owns_<md5>`) is
	 * considered. The legacy serialized-array format pre-dates the rename
	 * from cf-webp to cf-media-manager, which was a clean break — no
	 * cf-media-manager install can have legacy rows.
	 *
	 * @return array<string,true>
	 */
	public function build_owned_pairs_set(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		$set  = array();
		$base = rtrim( $this->paths->upload_dir(), '/' ) . '/';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( self::META_KEY_PREFIX ) . '%'
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return $set;
		}
		foreach ( $rows as $row ) {
			$rel = (string) ( $row['meta_value'] ?? '' );
			if ( '' === $rel ) {
				continue;
			}
			$abs = $base . ltrim( $rel, '/' );
			$set[ $abs . '|' . (int) $row['post_id'] ] = true;
		}
		return $set;
	}

	/**
	 * Bulk-optimized backfill: walks one filesystem subtree, resolves every
	 * variant via pre-built lookup maps (rather than the per-call
	 * `attachment_url_to_postid()` dance), and writes claims in batched
	 * `INSERT` statements.
	 *
	 * Per-variant resolution is an O(1) hash lookup against
	 * {@see AttachmentLookup} maps; no WP filter chain involvement,
	 * no URL normalization, no plugin-CDN interference. (The earlier
	 * callable-based `backfill_subtree()` was removed in 2.0.1.)
	 *
	 * Supports an inner offset cursor so a single large subtree (e.g. a
	 * year folder with 50K files) can be split across multiple AJAX calls.
	 * The walker pre-collects and sorts every variant path in the subtree
	 * on each call, then slices `[offset, offset+limit]`. Sort is stable
	 * by filename so offsets stay aligned across calls; the only way that
	 * breaks is if files are added or removed mid-backfill, which is an
	 * acceptable convergence regime (the worst case is a few extra
	 * `add_post_meta` no-ops on the next pass).
	 *
	 * Caller is responsible for:
	 *   - Building `$path_to_id` and `$size_to_parent` via
	 *     {@see AttachmentLookup} once per AJAX request.
	 *   - Building `$owned_pairs` via {@see build_owned_pairs_set()} once
	 *     per AJAX request.
	 *
	 * @param string             $subtree        Absolute directory path inside uploads.
	 * @param array<string,int>  $path_to_id     Pre-built relative_path => attachment_id map.
	 * @param array<string,int>  $size_to_parent Pre-built size_filename => parent_id map.
	 * @param array<string,bool> $owned_pairs    Pre-built "<abs>|<post_id>" => true set.
	 * @param int                $offset         Variant offset within the subtree to resume at.
	 * @param int                $limit          Max files to process this call.
	 * @param bool               $non_recursive  Scan only the immediate directory (upload root pass).
	 * @param bool               $dry_run        Count would-be claims without writing.
	 *
	 * @return array{
	 *   claimed:int,
	 *   processed:int,
	 *   next_offset:?int,
	 *   total_files:int,
	 * }
	 */
	public function backfill_subtree_bulk(
		string $subtree,
		array $path_to_id,
		array $size_to_parent,
		array $owned_pairs,
		int $offset,
		int $limit,
		bool $non_recursive = false,
		bool $dry_run = false
	): array {
		if ( ! is_dir( $subtree ) || $limit <= 0 ) {
			return array(
				'claimed'     => 0,
				'processed'   => 0,
				'next_offset' => null,
				'total_files' => 0,
			);
		}

		// Enumerate variant files. FilesystemIterator just reads dir entries
		// — even on a 50K-file subtree this typically completes in a few
		// hundred milliseconds. Sort gives stable iteration across chunks
		// so offset-based slicing stays aligned.
		$variant_paths = $this->enumerate_variants( $subtree, $non_recursive );
		sort( $variant_paths );

		$total = count( $variant_paths );
		if ( $offset >= $total ) {
			return array(
				'claimed'     => 0,
				'processed'   => 0,
				'next_offset' => null,
				'total_files' => $total,
			);
		}

		$slice = array_slice( $variant_paths, $offset, $limit );

		$writes  = array(); // rows to bulk-insert at the end of this slice
		$claimed = 0;

		foreach ( $slice as $path ) {
			if ( ! $this->paths->within_upload_dir( $path ) ) {
				continue;
			}
			$rel = $this->paths->to_rel( $path );
			if ( null === $rel ) {
				continue;
			}

			// Adopt-guard: if the variant's own relative path appears as an
			// attachment, skip it — it's a user-uploaded .webp/.avif and we
			// don't claim those. (Pre-built map equivalent of the previous
			// attachment_url_to_postid call.)
			if ( isset( $path_to_id[ $rel ] ) ) {
				continue;
			}

			// Find source candidates: .jpg, .jpeg, .png siblings with the same
			// basename. file_exists() is the disk-bound cost here, hence the
			// modest per-chunk limit.
			$source_rels = array();
			foreach ( array( 'jpg', 'jpeg', 'png' ) as $ext ) {
				$candidate_abs = preg_replace( '/\.(webp|avif)$/i', '.' . $ext, $path );
				if ( $candidate_abs && file_exists( $candidate_abs ) ) {
					$source_rel = $this->paths->to_rel( $candidate_abs );
					if ( null !== $source_rel ) {
						$source_rels[] = $source_rel;
					}
				}
			}
			if ( empty( $source_rels ) ) {
				continue; // Orphan variant, leave it alone.
			}

			$claimed_for_this = false;
			foreach ( $source_rels as $source_rel ) {
				// Resolve source path → attachment ID via the pre-built maps.
				// Direct match first (originals); size-variant fallback covers
				// `name-300x200.png` etc., where WP only stores the parent
				// path in _wp_attached_file but the size lives inside the
				// parent's _wp_attachment_metadata.sizes[] array.
				$attachment_id = $path_to_id[ $source_rel ] ?? 0;
				if ( 0 === $attachment_id ) {
					$attachment_id = $size_to_parent[ $source_rel ] ?? 0;
				}
				if ( $attachment_id <= 0 ) {
					continue;
				}

				$pair_key = $path . '|' . $attachment_id;
				if ( isset( $owned_pairs[ $pair_key ] ) ) {
					// Already claimed by this attachment — not a NEW claim, so
					// don't bump the counter. "claimed" means "newly recorded
					// into the manifest", not "has any ownership".
					continue;
				}

				if ( ! $dry_run ) {
					// meta_key/meta_value are mandatory column names for
					// $wpdb->insert($wpdb->postmeta, …) — not query inputs.
					$writes[] = array(
						'post_id'    => $attachment_id,
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_key'   => self::meta_key_for( ltrim( $rel, '/' ) ),
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
						'meta_value' => ltrim( $rel, '/' ),
					);
					// Mark in the in-memory set so a later iteration in the
					// same slice doesn't double-write under multi-claim.
					$owned_pairs[ $pair_key ] = true;
				}
				$claimed_for_this = true;
			}

			if ( $claimed_for_this ) {
				++$claimed;
			}
		}

		// One bulk insert per slice — replaces the per-file add_post_meta
		// SELECT+INSERT round-trip.
		if ( ! $dry_run && ! empty( $writes ) ) {
			$this->bulk_insert_owns( $writes );
		}

		$next_offset = $offset + count( $slice );
		if ( $next_offset >= $total ) {
			$next_offset = null;
		}

		return array(
			'claimed'     => $claimed,
			'processed'   => count( $slice ),
			'next_offset' => $next_offset,
			'total_files' => $total,
		);
	}

	/**
	 * Walker — enumerate every `.webp`/`.avif` file under a subtree. Returns
	 * absolute paths, unsorted. Used by the bulk backfill path; source
	 * siblings and ownership state are evaluated by the caller after
	 * slicing.
	 *
	 * @return string[]
	 */
	private function enumerate_variants( string $subtree, bool $non_recursive ): array {
		if ( ! is_dir( $subtree ) ) {
			return array();
		}
		if ( $non_recursive ) {
			$iter = new \FilesystemIterator( $subtree, \FilesystemIterator::SKIP_DOTS );
		} else {
			$iter = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $subtree, \FilesystemIterator::SKIP_DOTS )
			);
		}
		$paths = array();
		foreach ( $iter as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$ext = strtolower( $file->getExtension() );
			if ( 'webp' !== $ext && 'avif' !== $ext ) {
				continue;
			}
			$paths[] = $file->getPathname();
		}
		return $paths;
	}

	/**
	 * Insert buffered manifest ownership rows. Caller MUST have filtered
	 * against the in-memory `owned_pairs` set first; this method assumes
	 * each row is a new (post_id, meta_key) it can safely INSERT.
	 *
	 * Implementation note: an earlier alpha used a single multi-row
	 * `INSERT VALUES (?,?,?), (?,?,?), ...` per chunk. That collided with
	 * WordPress's stricter `wpdb::prepare()` validation in 6.x — the
	 * 1500-placeholder template silently failed validation on some hosts,
	 * `wpdb::query()` returned false, and the writes were lost without an
	 * error. The current code uses `wpdb::insert()` per row: slower by a
	 * factor of ~3x than a working bulk-insert would be, but bulletproof.
	 * Combined with the pre-built lookup maps (which were the actual big
	 * win) the backfill is still ~100x faster than the legacy path.
	 *
	 * Failures are logged when WP_DEBUG is on so the next time a "writes
	 * not landing" symptom appears we have a breadcrumb instead of having
	 * to read this code.
	 *
	 * @param array<int,array{post_id:int,meta_key:string,meta_value:string}> $writes
	 */
	private function bulk_insert_owns( array $writes ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $writes ) ) {
			return;
		}

		// Containment defense: refuse to record an ownership row whose
		// resolved absolute path doesn't live inside the uploads tree.
		// The backfill path resolves rel → abs by simple concatenation
		// (`upload_dir . meta_value`) and `meta_value` is server-built from
		// scanner output, so this is belt-and-suspenders against a
		// poisoned `_wp_attached_file` / scanner edge case that surfaces
		// a `../` traversal in the rel string. Without this filter, we'd
		// commit a manifest row that the rewriter could later resolve to
		// a path outside the uploads tree.
		$writes = self::filter_outside_upload_dir( $writes, $this->paths );
		if ( empty( $writes ) ) {
			return;
		}

		// Concurrency defense: re-check which (post_id, meta_key) pairs
		// already exist before inserting. The caller (backfill_subtree_bulk)
		// has already filtered against the in-memory `owned_pairs` set, but
		// that set was snapshotted at the start of the request. A parallel
		// backfill run (or a hand-recorded `record()` call from elsewhere
		// in the request) can insert the same pair in the gap between
		// snapshot and insert. Without this re-check, we'd produce duplicate
		// manifest rows (postmeta has no unique constraint by default).
		//
		// The SELECT scopes to the post_ids in this chunk and to the
		// specific meta_keys we're about to write — both indexed columns.
		// For BACKFILL_CHUNK_FILES (1000) and the realistic case where
		// most chunks have <100 unique post_ids, this is a single fast
		// indexed query, not a per-row round-trip.
		$writes = self::filter_existing_pairs( $wpdb, $writes );
		if ( empty( $writes ) ) {
			return;
		}

		$failures = 0;
		$post_ids = array();
		foreach ( $writes as $row ) {
			$post_ids[ (int) $row['post_id'] ] = true;
			// Adopt-variants flow inserts owned-variant postmeta rows in a
			// tight loop; add_post_meta would re-trigger meta-cache priming
			// for every iteration. meta_key/meta_value are insert columns,
			// not query inputs.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => (int) $row['post_id'],
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_key'   => (string) $row['meta_key'],
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value' => (string) $row['meta_value'],
				),
				array( '%d', '%s', '%s' )
			);
			if ( false === $result ) {
				++$failures;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && 1 === $failures ) {
					// Log only the first failure per chunk — repeated noise
					// when the underlying issue is a sustained DB problem.
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated diagnostic
					error_log( '[cf-media-manager] backfill insert failed for post ' . (int) $row['post_id'] . ': ' . (string) $wpdb->last_error );
				}
			}
		}

		// Post-INSERT convergence pass (A2 — TOCTOU hardening). The
		// filter_existing_pairs SELECT above + the backfill-overlap
		// transient lock narrow the (post_id, meta_key) duplicate
		// window, but they can't *close* it without a schema-level
		// UNIQUE index — and we can't add one to wp_postmeta because
		// it would break `add_post_meta` multi-value semantics other
		// plugins legitimately rely on (postmeta is a shared table).
		//
		// Instead: after the chunk's INSERTs, run a DELETE-JOIN
		// scoped to our meta_key prefix AND the post_ids touched in
		// this chunk. Whichever row landed FIRST (lowest meta_id)
		// wins — duplicate concurrent INSERTs converge to a single
		// row. Cost is bounded: the JOIN is filtered by indexed
		// post_id IN (...) AND meta_key LIKE '_cf_media_manager_owns_%',
		// so even on a 50K-postmeta site the scan only touches our
		// own rows for the chunk's post set.
		self::cleanup_duplicate_owns_rows( $wpdb, array_keys( $post_ids ) );

		// Object-cache layer used by Rewriter::variant_exists() isn't
		// pre-populated here. The Rewriter populates on first miss; users
		// typically run a cache purge after a big backfill anyway.
	}

	/**
	 * Convergence DELETE: keep only the earliest-`meta_id` row for any
	 * `(post_id, meta_key)` pair under our `_cf_media_manager_owns_` prefix that
	 * has more than one row for the given post_id set. Idempotent — runs
	 * to a no-op when there are no duplicates.
	 *
	 * Scoped to:
	 *   - meta_key LIKE `_cf_media_manager_owns_%` (our prefix only)
	 *   - post_id IN ($post_ids) (the chunk we just wrote to)
	 *
	 * No-op when $post_ids is empty.
	 *
	 * @param object $wpdb_obj wpdb-like object with ->postmeta and ->query
	 * @param int[]  $post_ids post_ids touched in the current chunk
	 */
	private static function cleanup_duplicate_owns_rows( $wpdb_obj, array $post_ids ): void {
		if ( empty( $post_ids ) ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$prefix_like  = $wpdb_obj->esc_like( self::META_KEY_PREFIX ) . '%';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- placeholders are %d list, all values pass through prepare()
		$sql = "DELETE p1 FROM {$wpdb_obj->postmeta} p1
		        INNER JOIN {$wpdb_obj->postmeta} p2
		            ON p1.post_id = p2.post_id
		           AND p1.meta_key = p2.meta_key
		           AND p1.meta_id  > p2.meta_id
		        WHERE p1.meta_key LIKE %s
		          AND p1.post_id IN ($placeholders)";
		// phpcs:enable
		$args = array_merge( array( $prefix_like ), array_map( 'intval', $post_ids ) );

		$prepared = $wpdb_obj->prepare( $sql, $args );
		if ( false === $prepared || null === $prepared ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- targeted dedup sweep on our own meta_key prefix.
		$wpdb_obj->query( $prepared );
	}

	/**
	 * Re-check the DB for (post_id, meta_key) pairs that already exist and
	 * filter them out of $writes. Defends against parallel writers committing
	 * the same row between the in-memory `owned_pairs` snapshot and our
	 * INSERT. Returns the still-needed writes.
	 *
	 * @param object                                                            $wpdb_obj
	 * @param array<int,array{post_id:int,meta_key:string,meta_value:string}> $writes
	 * @return array<int,array{post_id:int,meta_key:string,meta_value:string}>
	 */
	private static function filter_existing_pairs( $wpdb_obj, array $writes ): array {
		if ( empty( $writes ) ) {
			return $writes;
		}
		$post_ids = array();
		$meta_keys = array();
		foreach ( $writes as $row ) {
			$post_ids[ (int) $row['post_id'] ]   = true;
			$meta_keys[ (string) $row['meta_key'] ] = true;
		}
		$post_ids  = array_keys( $post_ids );
		$meta_keys = array_keys( $meta_keys );

		// Build placeholders for $wpdb->prepare. Both columns are indexed,
		// so the planner can use index_merge on the IN-IN intersection.
		$pid_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$key_placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are %d/%s lists, values pass through prepare()
		$sql = "SELECT post_id, meta_key FROM {$wpdb_obj->postmeta} WHERE post_id IN ($pid_placeholders) AND meta_key IN ($key_placeholders)";
		$prepared = $wpdb_obj->prepare( $sql, array_merge( $post_ids, $meta_keys ) );
		// phpcs:enable
		if ( false === $prepared || null === $prepared ) {
			// If prepare() rejected the template (would only happen on a
			// future WP that tightens validation further), fall through to
			// per-row INSERT — duplicates may result but data is not lost.
			return $writes;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- targeted dedup probe; cached by the bulk-insert flow.
		$existing = $wpdb_obj->get_results( $prepared, ARRAY_A );
		return self::dedupe_writes( $writes, (array) $existing );
	}

	/**
	 * Pure helper: filter $writes down to entries whose (post_id, meta_key)
	 * is NOT present in $existing. Extracted so unit tests can exercise the
	 * dedupe logic without a $wpdb mock.
	 *
	 * @internal Public for testability; not part of the stable API.
	 *
	 * @param array<int,array{post_id:int,meta_key:string,meta_value:string}> $writes
	 * @param array<int,array{post_id:int,meta_key:string}>                   $existing
	 * @return array<int,array{post_id:int,meta_key:string,meta_value:string}>
	 */
	public static function dedupe_writes( array $writes, array $existing ): array {
		if ( empty( $existing ) ) {
			return array_values( $writes );
		}
		$held = array();
		foreach ( $existing as $row ) {
			if ( ! isset( $row['post_id'], $row['meta_key'] ) ) {
				continue;
			}
			$held[ (int) $row['post_id'] . '|' . (string) $row['meta_key'] ] = true;
		}
		return array_values( array_filter(
			$writes,
			static function ( $row ) use ( $held ) {
				return ! isset( $held[ (int) $row['post_id'] . '|' . (string) $row['meta_key'] ] );
			}
		) );
	}

	/**
	 * Filter writes whose resolved absolute path falls outside the uploads
	 * tree. Each row's `meta_value` is a path relative to the uploads root;
	 * the absolute form is `upload_dir + meta_value`, and we run that
	 * through Paths::within_upload_dir() so realpath-based traversal
	 * defense catches any `../` segment that survived earlier scrubbing.
	 *
	 * Empty meta_value rows are also dropped — they couldn't be legitimate
	 * ownership records and would resolve to the uploads root itself.
	 *
	 * @internal Public static for testability (matches `dedupe_writes` next door);
	 *           not part of the stable API.
	 *
	 * @param array<int,array{post_id:int,meta_key:string,meta_value:string}> $writes Pending manifest writes.
	 * @param Paths                                                           $paths  Uploads-tree paths helper.
	 * @return array<int,array{post_id:int,meta_key:string,meta_value:string}>
	 */
	public static function filter_outside_upload_dir( array $writes, Paths $paths ): array {
		if ( empty( $writes ) ) {
			return $writes;
		}
		$base = rtrim( $paths->upload_dir(), '/' ) . '/';
		$kept = array();
		foreach ( $writes as $row ) {
			$rel = ltrim( (string) ( $row['meta_value'] ?? '' ), '/' );
			if ( '' === $rel ) {
				// No path → no containment to check, but no path is also
				// not a legitimate ownership row. Drop.
				continue;
			}
			$abs = $base . $rel;
			if ( ! $paths->within_upload_dir( $abs ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated security event log.
					error_log( '[cf-media-manager] refusing manifest write — path escapes uploads dir: ' . $abs );
				}
				continue;
			}
			$kept[] = $row;
		}
		return $kept;
	}

	/**
	 * Remove a single recorded variant from an attachment's manifest. Used
	 * after a successful unlink so future runs don't trust a stale entry.
	 */
	public function forget( int $attachment_id, string $variant_path ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}
		$rel = $this->to_rel( $variant_path );
		if ( null === $rel ) {
			return;
		}
		if ( function_exists( 'delete_post_meta' ) ) {
			// Delete both current (v1) and legacy (v0) rows. We don't know
			// which version wrote the row originally, and there could be
			// both if a re-Adopt landed under v1 while a v0 row from an
			// earlier install lingered.
			delete_post_meta( $attachment_id, self::meta_key_for( $rel ) );
			delete_post_meta( $attachment_id, self::meta_key_for_v0( $rel ) );
		}
		// Also clear any legacy serialized-array entry that might still claim
		// this rel path. Filter the array down and write back or delete.
		$legacy = get_post_meta( $attachment_id, self::META_KEY_LEGACY, true );
		if ( is_array( $legacy ) && in_array( $rel, $legacy, true ) ) {
			$filtered = array_values(
				array_filter(
					$legacy,
					static function ( $p ) use ( $rel ) {
						return $p !== $rel;
					}
				)
			);
			if ( array() === $filtered ) {
				delete_post_meta( $attachment_id, self::META_KEY_LEGACY );
			} else {
				update_post_meta( $attachment_id, self::META_KEY_LEGACY, $filtered );
			}
		}
		$this->cache_delete( $rel );
	}

	/**
	 * Slow path: forget a variant when we don't know which attachment claims it.
	 * Used by the bulk deletion routine after unlinking a file. Single indexed
	 * delete on the new format, plus a per-row sweep of any legacy rows.
	 */
	public function forget_anywhere( string $variant_path ): void {
		$rel = $this->to_rel( $variant_path );
		if ( null === $rel ) {
			return;
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}
		// Delete rows under both the current (v1) key and the legacy (v0)
		// key. Either or both may exist depending on which writer claimed
		// this variant.
		foreach ( array( self::meta_key_for( $rel ), self::meta_key_for_v0( $rel ) ) as $key ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- targeted delete by exact (key,value) for the ownership row we just unlinked; object cache below.
			$wpdb->delete(
				$wpdb->postmeta,
				array(
					'meta_key'   => $key,
					'meta_value' => $rel,
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		}

		// Legacy sweep — bounded to attachments that contain this rel as a
		// substring of their serialized array. Skip entirely once the
		// admin-driven backfill has completed: every legitimate legacy
		// row is already migrated to the v1 (exact-key) format that the
		// $wpdb->delete above covers, so this LIKE scan would only ever
		// match orphans the rewriter wasn't trusting anyway.
		if ( ! get_option( Options::BACKFILL_DONE ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
					self::META_KEY_LEGACY,
					'%' . $wpdb->esc_like( '"' . $rel . '"' ) . '%'
				)
			);
			if ( is_array( $ids ) ) {
				foreach ( $ids as $id ) {
					$this->forget( (int) $id, $variant_path );
				}
			}
		}

		$this->cache_delete( $rel );
	}

	/**
	 * Map a variant path back to the source path it was generated from, by
	 * probing for matching .jpg / .jpeg / .png siblings. Returns null when
	 * none exist (orphan variant — caller should leave it untouched).
	 *
	 * Returns the first match. Use `find_all_source_paths()` when the caller
	 * needs to handle basename collisions (multiple attachments sharing the
	 * same basename across different source extensions).
	 */
	public function guess_source_path( string $variant_path ): ?string {
		$all = $this->find_all_source_paths( $variant_path );
		return $all[0] ?? null;
	}

	/**
	 * Map a variant path back to ALL plausible source paths — every existing
	 * .jpg / .jpeg / .png sibling. The backfill uses this to claim a foreign
	 * variant under every attachment whose source matches by basename, so a
	 * legitimate `logo.png` attachment and a legitimate `logo.jpg` attachment
	 * each get a claim on `logo.webp` when only one of them produced it (we
	 * can't tell which from the filesystem alone, and either picking
	 * arbitrarily or skipping both leaves real attachments stuck).
	 *
	 * @return string[] absolute paths, in extension-priority order.
	 */
	public function find_all_source_paths( string $variant_path ): array {
		$paths = array();
		foreach ( array( 'jpg', 'jpeg', 'png' ) as $candidate_ext ) {
			$candidate = preg_replace( '/\.(webp|avif)$/i', '.' . $candidate_ext, $variant_path );
			if ( $candidate && file_exists( $candidate ) ) {
				$paths[] = $candidate;
			}
		}
		return $paths;
	}

	/**
	 * Convert an absolute path to an upload-relative path. Thin wrapper
	 * around {@see Paths::to_rel()} so internal call sites stay terse;
	 * see that method for the contract.
	 */
	private function to_rel( string $abs ): ?string {
		return $this->paths->to_rel( $abs );
	}

	// -------------------------------------------------------------------------
	// Object-cache helpers — coalesce repeat lookups on the front-end render
	// path so a single page with N candidate URLs costs at most N DB seeks the
	// first time and 0 on every subsequent render.
	// -------------------------------------------------------------------------

	private function cache_get( string $rel ): ?bool {
		if ( ! function_exists( 'wp_cache_get' ) ) {
			return null;
		}
		$found = false;
		$hit   = wp_cache_get( md5( $rel ), self::CACHE_GROUP, false, $found );
		if ( ! $found ) {
			return null;
		}
		return (bool) $hit;
	}

	private function cache_set( string $rel, bool $owned ): void {
		if ( ! function_exists( 'wp_cache_set' ) ) {
			return;
		}
		// Store as 1/0 so wp_cache_get's "miss returns false" semantics are
		// distinguishable from the "not owned" value (handled via $found).
		wp_cache_set( md5( $rel ), $owned ? 1 : 0, self::CACHE_GROUP, self::CACHE_TTL );
	}

	private function cache_delete( string $rel ): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		wp_cache_delete( md5( $rel ), self::CACHE_GROUP );
	}
}
