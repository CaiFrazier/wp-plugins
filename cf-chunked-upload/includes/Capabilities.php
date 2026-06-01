<?php

namespace CFChunkedUpload;

defined( 'ABSPATH' ) || exit;

/**
 * REST permission callbacks. The chunk/finalize/status/cancel routes serve
 * two destinations with different capability requirements, so the check is
 * destination-aware: 'media' needs the standard upload_files; 'import' needs
 * the configurable importer capability (default manage_options). Routes that
 * only expose host config gate on manage_options.
 */
final class Capabilities {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Destination is read from the request (query, body, or JSON). An
	 * unrecognized/absent destination falls back to the STRICTER importer
	 * capability so a missing field can never widen access.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool
	 */
	public function can_upload( \WP_REST_Request $request ): bool {
		$destination = (string) $request->get_param( 'destination' );
		if ( 'media' === $destination ) {
			return current_user_can( 'upload_files' );
		}
		return current_user_can( $this->settings->importer_capability() );
	}

	/**
	 * Progress/cancel routes (/status, /finalize-status, DELETE /upload)
	 * carry no destination — they are keyed by an unguessable UUID. Gate them
	 * on "is an upload-capable user at all" (either destination's capability)
	 * so a non-admin editor can poll and cancel their own media upload. The
	 * UUID is the object-level guard; the capability is the coarse gate.
	 *
	 * @return bool
	 */
	public function can_progress(): bool {
		return current_user_can( 'upload_files' )
			|| current_user_can( $this->settings->importer_capability() );
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}
}
