<?php

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

/**
 * Incremental scanner for WebP/AVIF variant files under the uploads tree.
 *
 * Built to replace the legacy "walk-the-entire-tree-in-one-AJAX-call" pattern
 * that OOM'd on sites with 100K+ attachments. Operates one top-level subtree
 * per call (typically a year folder on WP year/month-organized sites), with
 * an opaque cursor returned to the client for the next tick.
 *
 * The class is intentionally a pure walker — it doesn't know about deletion,
 * counting, or HTTP responses. Callers compose it with whatever work they
 * need to do per file.
 *
 * Scan unit "0" is a synthetic non-recursive pass over the uploads root
 * itself — for installs that disable "Organize my uploads into month- and
 * year-based folders" and end up with files at the upload root. Subsequent
 * scan units are top-level subdirectories.
 */
final class VariantScanner {

	const ROOT_CURSOR = '__root__';

	private Paths $paths;

	public function __construct( Paths $paths ) {
		$this->paths = $paths;
	}

	/**
	 * Resolve the next scan unit, given an opaque cursor from a prior tick
	 * (or empty string for the first call). The first unit is a non-recursive
	 * pass over the uploads root itself; subsequent units are top-level
	 * subdirectories, sorted alphabetically for deterministic ordering.
	 *
	 * @return array{ subtree: ?string, next_cursor: ?string, progress: array{ index: int, total: int, current?: string }, scope?: string }
	 *   subtree:    absolute path to scan on this tick, or null when done
	 *   next_cursor: opaque token to send next, or null when done
	 *   progress:    { index: 1-based position, total: count of units, current?: human label }
	 *   scope:      'root' for the upload-root pass (non-recursive), 'subtree' for top-level dirs
	 */
	public function next_subtree( string $cursor ): array {
		$upload_dir = rtrim( $this->paths->upload_dir(), '/' );
		$entries    = @scandir( $upload_dir );
		if ( false === $entries ) {
			return [
				'subtree'     => null,
				'next_cursor' => null,
				'progress'    => [
					'index' => 0,
					'total' => 0,
				],
			];
		}

		$dirs           = [];
		$has_root_files = false;
		foreach ( $entries as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $upload_dir . '/' . $name;
			if ( is_link( $path ) ) {
				continue;
			}
			if ( is_dir( $path ) ) {
				$dirs[] = $name;
			} elseif ( is_file( $path ) ) {
				$has_root_files = true;
			}
		}
		sort( $dirs );

		// Build the scan-unit list. Root pass goes first when there's at
		// least one regular file at the upload root.
		$units = [];
		if ( $has_root_files ) {
			$units[] = [
				'cursor' => self::ROOT_CURSOR,
				'path'   => $upload_dir,
				'label'  => '(root)',
				'scope'  => 'root',
			];
		}
		foreach ( $dirs as $name ) {
			$units[] = [
				'cursor' => $name,
				'path'   => $upload_dir . '/' . $name,
				'label'  => $name,
				'scope'  => 'subtree',
			];
		}

		$total = count( $units );
		if ( 0 === $total ) {
			return [
				'subtree'     => null,
				'next_cursor' => null,
				'progress'    => [
					'index' => 0,
					'total' => 0,
				],
			];
		}

		$index = 0;
		if ( '' !== $cursor ) {
			$found = false;
			foreach ( $units as $i => $unit ) {
				if ( $unit['cursor'] === $cursor ) {
					$found = $i;
					break;
				}
			}
			if ( false === $found ) {
				// Cursor refers to a unit that no longer exists. Treat as done.
				return [
					'subtree'     => null,
					'next_cursor' => null,
					'progress'    => [
						'index' => $total,
						'total' => $total,
					],
				];
			}
			$index = (int) $found;
		}

		$current     = $units[ $index ];
		$next_cursor = isset( $units[ $index + 1 ] ) ? $units[ $index + 1 ]['cursor'] : null;
		$progress    = [
			'index'   => $index + 1,
			'total'   => $total,
			'current' => $current['label'],
		];

		return [
			'subtree'     => $current['path'],
			'next_cursor' => $next_cursor,
			'progress'    => $progress,
			'scope'       => $current['scope'],
		];
	}

	/**
	 * Generator over WebP/AVIF variant files in a single scan unit. Yields
	 * { path, ext, size, owned } for each file. Filters applied:
	 *  - extension is webp or avif
	 *  - resolves inside the upload_dir (defends against symlink escape)
	 *  - has a JPG/JPEG/PNG sibling (orphan filter)
	 *
	 * When $owned_set is provided, the `owned` flag on each yield reflects
	 * membership. Callers that want to skip foreign files (Delete All) can
	 * filter on `owned === true`; callers that want a side-by-side count
	 * (count_variants UI) see both groups.
	 *
	 * Passing null for $owned_set means "ownership unknown" — every yield
	 * has owned=false, which is the conservative default for deletion.
	 *
	 * $non_recursive=true scans only the immediate directory (used for the
	 * upload-root pass — recursing would re-walk every subdirectory).
	 *
	 * @param string                  $subtree        Absolute directory path inside uploads.
	 * @param array<string,true>|null $owned_set      Optional set of plugin-owned absolute paths.
	 * @param bool                    $non_recursive  When true, only scan the immediate directory.
	 */
	public function walk_variants( string $subtree, ?array $owned_set = null, bool $non_recursive = false ): \Generator {
		if ( ! is_dir( $subtree ) ) {
			return;
		}
		if ( $non_recursive ) {
			$iter = new \FilesystemIterator( $subtree, \FilesystemIterator::SKIP_DOTS );
		} else {
			$iter = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $subtree, \FilesystemIterator::SKIP_DOTS )
			);
		}
		foreach ( $iter as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$ext = strtolower( $file->getExtension() );
			if ( 'webp' !== $ext && 'avif' !== $ext ) {
				continue;
			}
			$path = $file->getPathname();
			if ( ! $this->paths->within_upload_dir( $path ) ) {
				continue;
			}
			if ( ! self::variant_has_source( $path ) ) {
				continue;
			}
			$owned = null !== $owned_set && isset( $owned_set[ $path ] );
			yield [
				'path'  => $path,
				'ext'   => $ext,
				'size'  => (int) $file->getSize(),
				'owned' => $owned,
			];
		}
	}

	private static function variant_has_source( string $variant_path ): bool {
		$src_jpg  = preg_replace( '/\.(webp|avif)$/i', '.jpg', $variant_path );
		$src_jpeg = preg_replace( '/\.(webp|avif)$/i', '.jpeg', $variant_path );
		$src_png  = preg_replace( '/\.(webp|avif)$/i', '.png', $variant_path );
		return file_exists( $src_jpg ) || file_exists( $src_jpeg ) || file_exists( $src_png );
	}
}
