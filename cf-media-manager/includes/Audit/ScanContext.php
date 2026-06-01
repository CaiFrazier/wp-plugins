<?php
/**
 * ScanContext — input passed into AuditReportInterface::scan_chunk().
 *
 * Holds the per-run knobs that aren't part of the cursor: chunk size,
 * force-rescan flag, and a free-form config map for report-specific
 * thresholds (oversized byte threshold, duplicate hash algo, etc.). The
 * cursor itself stays out of the context because it's an opaque,
 * per-chunk token rather than a run-wide setting.
 */

namespace CFMediaManager\Audit;

defined( 'ABSPATH' ) || exit;

final class ScanContext {

	const DEFAULT_CHUNK_SIZE = 500;

	/**
	 * @param bool  $force      When true, the report skips any cached
	 *                          incremental state and re-examines every
	 *                          candidate. Tied to the user's manual
	 *                          rescan click.
	 * @param int   $chunk_size Soft target for items-per-chunk. Reports
	 *                          treat this as an upper bound; they're free
	 *                          to yield a smaller chunk if their work
	 *                          batches naturally below the target.
	 * @param array $config     Report-scoped configuration: e.g.,
	 *                          OversizedOriginals reads
	 *                          $config['min_bytes'].
	 */
	public function __construct(
		public readonly bool $force = false,
		public readonly int $chunk_size = self::DEFAULT_CHUNK_SIZE,
		public readonly array $config = array()
	) {}

	/**
	 * Read a report-scoped config value with a default. Reports SHOULD call
	 * this rather than touching $config directly so the missing-key path is
	 * consistent.
	 */
	public function config( string $key, $default = null ) {
		return $this->config[ $key ] ?? $default;
	}
}
