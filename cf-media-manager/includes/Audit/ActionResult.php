<?php
/**
 * ActionResult — return value from AuditReportInterface::bulk_action().
 *
 * The shape lets the UI render a faithful summary after a destructive
 * action: "Trashed 8, skipped 2 (locked by another user), 0 errors."
 * Errors map item key → reason so the UI can show per-item failure detail
 * without the report having to format messages.
 */

namespace CFMediaManager\Audit;

defined( 'ABSPATH' ) || exit;

final class ActionResult {

	/**
	 * @param bool                   $success   Whether the action ran end-to-end.
	 *                                          A partial run with per-item
	 *                                          errors still counts as success
	 *                                          if the action's framing worked.
	 * @param int                    $processed Count of items successfully acted on.
	 * @param int                    $skipped   Count of items intentionally not touched
	 *                                          (already-ignored, already-trashed, …).
	 * @param array<int|string,string> $errors  Map of item key → human-readable
	 *                                          error message for failed items.
	 * @param string|null            $message   Optional summary message for the UI
	 *                                          banner. When null, the UI synthesizes
	 *                                          one from the counts above.
	 */
	public function __construct(
		public readonly bool $success,
		public readonly int $processed,
		public readonly int $skipped,
		public readonly array $errors = array(),
		public readonly ?string $message = null
	) {}

	public static function ok( int $processed, int $skipped = 0, ?string $message = null ): self {
		return new self( true, $processed, $skipped, array(), $message );
	}

	public static function partial( int $processed, int $skipped, array $errors, ?string $message = null ): self {
		return new self( true, $processed, $skipped, $errors, $message );
	}

	public static function failed( string $message, array $errors = array() ): self {
		return new self( false, 0, 0, $errors, $message );
	}
}
