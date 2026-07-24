<?php

namespace CFChunkedUpload;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and serves every cf-chunked-upload/v1 route. Thin: structural
 * validation and capability gating live here; the real work is in
 * ChunkReceiver / FinalizeJob / Settings, which are unit-tested directly.
 *
 * All routes require a valid wp_rest nonce (WP enforces this for
 * cookie-authenticated REST when the route is hit from admin JS). Capability
 * callbacks are destination-aware via Capabilities.
 */
final class RestApi {

	const NS = 'cf-chunked-upload/v1';

	private Paths $paths;
	private Settings $settings;
	private Capabilities $caps;

	public function __construct( Paths $paths, Settings $settings, Capabilities $caps ) {
		$this->paths    = $paths;
		$this->settings = $settings;
		$this->caps     = $caps;
	}

	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/chunk',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_chunk' ],
				'permission_callback' => [ $this->caps, 'can_upload' ],
			]
		);

		register_rest_route(
			self::NS,
			'/finalize',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_finalize' ],
				'permission_callback' => [ $this->caps, 'can_upload' ],
			]
		);

		register_rest_route(
			self::NS,
			'/finalize-status/(?P<jobId>[A-Za-z0-9\-]+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_finalize_status' ],
				'permission_callback' => [ $this->caps, 'can_progress' ],
			]
		);

		register_rest_route(
			self::NS,
			'/status/(?P<uploadId>[0-9a-fA-F\-]+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_status' ],
				'permission_callback' => [ $this->caps, 'can_progress' ],
			]
		);

		register_rest_route(
			self::NS,
			'/upload/(?P<uploadId>[0-9a-fA-F\-]+)',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'handle_cancel' ],
				'permission_callback' => [ $this->caps, 'can_progress' ],
			]
		);

		register_rest_route(
			self::NS,
			'/preflight',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_preflight' ],
				'permission_callback' => [ $this->caps, 'can_upload' ],
			]
		);

		register_rest_route(
			self::NS,
			'/settings',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'handle_get_settings' ],
					'permission_callback' => [ $this->caps, 'can_manage' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'handle_update_settings' ],
					'permission_callback' => [ $this->caps, 'can_manage' ],
				],
			]
		);
	}

	public function handle_chunk( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();

		// SEC-1: token-bucket rate limit per user.
		if ( ! RateLimit::check_chunk( $user_id, $this->settings->chunks_per_minute() ) ) {
			return new \WP_Error(
				'cf_cu_rate_limited',
				__( 'Too many chunk requests. Please wait a moment before retrying.', 'cf-chunked-upload' ),
				[ 'status' => 429 ]
			);
		}

		// New-session guard — runs only when the session dir doesn't exist yet.
		// Existing sessions already passed this on their first chunk. The per-user
		// storage quota is NOT reserved here: it is enforced per chunk inside
		// ChunkReceiver against actual on-disk bytes (SEC-9), so a spoofed or
		// zero fileSize cannot bypass it at session creation.
		$upload_id_param = (string) $request->get_param( 'uploadId' );
		if ( UploadSession::is_valid_id( $upload_id_param ) ) {
			$probe = new UploadSession( $this->paths, $upload_id_param );
			if ( ! $probe->exists() ) {
				// FEAT-8: per-user concurrent upload cap.
				$max_concurrent = $this->settings->max_concurrent_uploads_per_user();
				if ( $max_concurrent > 0 && $user_id > 0
					&& UploadSession::count_user_sessions( $this->paths, $user_id ) >= $max_concurrent ) {
					return new \WP_Error(
						'cf_cu_too_many_uploads',
						__( 'You have reached the maximum number of concurrent uploads. Finish or cancel an existing upload before starting another.', 'cf-chunked-upload' ),
						[ 'status' => 429 ]
					);
				}
			}
		}

		$files = $request->get_file_params();
		$tmp   = isset( $files['chunk']['tmp_name'] ) ? (string) $files['chunk']['tmp_name'] : '';

		$receiver = new ChunkReceiver(
			$this->paths,
			$this->settings->mime_gate(),
			$this->settings->per_user_quota_bytes(),
			$this->settings->per_session_max_bytes(),
			$this->settings->min_free_disk_bytes()
		);
		$result   = $receiver->receive(
			[
				'upload_id'    => $request->get_param( 'uploadId' ),
				'chunk_index'  => $request->get_param( 'chunkIndex' ),
				'total_chunks' => $request->get_param( 'totalChunks' ),
				'file_name'    => $request->get_param( 'fileName' ),
				'mime_type'    => $request->get_param( 'mimeType' ),
				'destination'  => $request->get_param( 'destination' ),
				'chunk_sha256' => $request->get_param( 'chunkSha256' ),
				'total_sha256' => $request->get_param( 'totalSha256' ),
				// Server-trusted, never from the request body — binds the
				// session to its creator for the ownership checks below.
				'owner_id'     => $user_id,
			],
			$tmp
		);

		if ( ! $result['ok'] ) {
			return new \WP_Error( 'cf_cu_' . $result['error'], $result['message'], [ 'status' => $result['status'] ] );
		}
		return new \WP_REST_Response( $result['data'], 200 );
	}

	public function handle_finalize( \WP_REST_Request $request ) {
		$upload_id   = (string) $request->get_param( 'uploadId' );
		$destination = (string) $request->get_param( 'destination' );

		// Explicit whitelist — never branch on truthiness.
		if ( 'media' !== $destination && 'import' !== $destination ) {
			return new \WP_Error( 'cf_cu_invalid_destination', __( 'Invalid destination.', 'cf-chunked-upload' ), [ 'status' => 400 ] );
		}
		if ( ! UploadSession::is_valid_id( $upload_id ) ) {
			return new \WP_Error( 'cf_cu_invalid_upload_id', __( 'Malformed upload id.', 'cf-chunked-upload' ), [ 'status' => 400 ] );
		}

		try {
			$session = new UploadSession( $this->paths, $upload_id );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'cf_cu_invalid_upload_id', __( 'Malformed upload id.', 'cf-chunked-upload' ), [ 'status' => 400 ] );
		}

		$meta = $session->meta();
		if ( null === $meta ) {
			return new \WP_Error( 'cf_cu_no_session', __( 'Upload session not found.', 'cf-chunked-upload' ), [ 'status' => 404 ] );
		}
		$owner_err = $this->owner_error( $session );
		if ( null !== $owner_err ) {
			return $owner_err;
		}
		if ( (string) ( $meta['destination'] ?? '' ) !== $destination ) {
			return new \WP_Error( 'cf_cu_destination_conflict', __( 'Finalize destination does not match the session.', 'cf-chunked-upload' ), [ 'status' => 409 ] );
		}
		if ( ! $session->has_all_chunks() ) {
			return new \WP_Error(
				'cf_cu_incomplete',
				__( 'Not all chunks have been received yet.', 'cf-chunked-upload' ),
				[ 'status' => 409 ]
			);
		}

		// SEC-4: idempotent finalize — if a job was already scheduled for this
		// upload, return the existing job id rather than enqueuing duplicate
		// assembly. This handles the common retry case where the client lost the
		// /finalize response and POSTs again.
		$existing_job_id = $session->finalize_job_id();
		if ( null !== $existing_job_id ) {
			return new \WP_REST_Response(
				[
					'jobId'  => $existing_job_id,
					'status' => JobStatus::PENDING,
				],
				202
			);
		}

		// The whole-file digest is supplied here (the client has finished
		// hashing by upload's end) rather than read from the immutable
		// first-chunk meta. Accept only a well-formed hex digest or empty
		// (empty disables the whole-file check for non-browser callers).
		$expected_sha = (string) $request->get_param( 'totalSha256' );
		if ( '' !== $expected_sha && ! Integrity::is_sha256_hex( $expected_sha ) ) {
			return new \WP_Error(
				'cf_cu_invalid_total_hash',
				__( 'totalSha256 must be a 64-char hex digest.', 'cf-chunked-upload' ),
				[ 'status' => 400 ]
			);
		}

		$job_id = wp_generate_uuid4();
		// Persist the job id before enqueuing so a concurrent /finalize or a
		// retry that arrives before the job runs returns this same id (SEC-4).
		$session->set_finalize_job_id( $job_id );
		// SEC-7: touch .assembling now (in the request thread) so Cleanup does
		// not reap the session during the WP-Cron queue delay — which can be
		// many minutes on low-traffic sites.
		$session->mark_assembling();
		FinalizeJob::enqueue( $job_id, $upload_id, $expected_sha );

		return new \WP_REST_Response(
			[
				'jobId'  => $job_id,
				'status' => JobStatus::PENDING,
			],
			202
		);
	}

	public function handle_finalize_status( \WP_REST_Request $request ) {
		$job_id = (string) $request->get_param( 'jobId' );
		if ( 1 !== preg_match( '/^[A-Za-z0-9\-]{1,64}$/', $job_id ) ) {
			return new \WP_Error( 'cf_cu_invalid_job_id', __( 'Malformed job id.', 'cf-chunked-upload' ), [ 'status' => 400 ] );
		}
		$record = JobStatus::get( $job_id );
		if ( null === $record ) {
			return new \WP_Error( 'cf_cu_unknown_job', __( 'No such job (it may have expired).', 'cf-chunked-upload' ), [ 'status' => 404 ] );
		}
		return new \WP_REST_Response( $record, 200 );
	}

	public function handle_status( \WP_REST_Request $request ) {
		$upload_id = (string) $request->get_param( 'uploadId' );
		if ( ! UploadSession::is_valid_id( $upload_id ) ) {
			return new \WP_Error( 'cf_cu_invalid_upload_id', __( 'Malformed upload id.', 'cf-chunked-upload' ), [ 'status' => 400 ] );
		}
		$session = new UploadSession( $this->paths, $upload_id );
		if ( ! $session->exists() ) {
			return new \WP_Error( 'cf_cu_no_session', __( 'Upload session not found.', 'cf-chunked-upload' ), [ 'status' => 404 ] );
		}
		$owner_err = $this->owner_error( $session );
		if ( null !== $owner_err ) {
			return $owner_err;
		}
		return new \WP_REST_Response(
			[
				'uploadId'    => $upload_id,
				'received'    => $session->received_indices(),
				'totalChunks' => $session->total_chunks(),
				'complete'    => $session->has_all_chunks(),
			],
			200
		);
	}

	public function handle_cancel( \WP_REST_Request $request ) {
		$upload_id = (string) $request->get_param( 'uploadId' );
		if ( ! UploadSession::is_valid_id( $upload_id ) ) {
			return new \WP_Error( 'cf_cu_invalid_upload_id', __( 'Malformed upload id.', 'cf-chunked-upload' ), [ 'status' => 400 ] );
		}
		$session   = new UploadSession( $this->paths, $upload_id );
		$owner_err = $this->owner_error( $session );
		if ( null !== $owner_err ) {
			return $owner_err;
		}
		// SEC-2: release the quota reservation before deleting so the counter
		// stays accurate. Read meta first — it's gone after delete().
		$meta = $session->meta();
		if ( is_array( $meta ) ) {
			UserQuota::release( (int) ( $meta['owner_id'] ?? 0 ), (int) ( $meta['file_size'] ?? 0 ) );
		}
		// Deleting the dir is also how an in-flight finalize job is aborted:
		// FinalizeJob::process() / place_* re-check session->exists() right
		// before committing and bail with 'cancelled' if it's gone.
		$session->delete();
		// Drop any finalize lock so a future re-upload with a new id isn't
		// affected; the lock is keyed by this (now dead) upload id.
		delete_option( FinalizeJob::LOCK_PREFIX . $upload_id );
		return new \WP_REST_Response( [ 'cancelled' => true ], 200 );
	}

	public function handle_preflight( \WP_REST_Request $request ): \WP_REST_Response {
		$destination = (string) $request->get_param( 'destination' );
		$file_name   = sanitize_file_name( (string) $request->get_param( 'fileName' ) );
		$file_size   = max( 0, (int) $request->get_param( 'fileSize' ) );

		if ( 'media' !== $destination && 'import' !== $destination ) {
			$destination = 'import';
		}

		$preflight = new Preflight( $this->paths, $this->settings );
		return new \WP_REST_Response( $preflight->check( $destination, $file_name, $file_size ), 200 );
	}

	public function handle_get_settings(): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'settings' => $this->settings->get(),
				'hostInfo' => HostInfo::snapshot(),
			],
			200
		);
	}

	public function handle_update_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		$res  = $this->settings->update( is_array( $body ) ? $body : [] );
		return new \WP_REST_Response( $res, 200 );
	}

	/**
	 * Object-level ownership check for the per-session routes. The capability
	 * gate (can_progress) only proves "an upload-capable user"; this binds
	 * status/cancel/finalize to the session's creator so one uploader can't
	 * poll or cancel another's transfer. manage_options users bypass it
	 * (admins legitimately manage any session). A legacy/0 owner is
	 * unrestricted — it can only exist before this field shipped.
	 *
	 * @param UploadSession $session The session being acted on.
	 * @return \WP_Error|null Null when access is allowed.
	 */
	private function owner_error( UploadSession $session ): ?\WP_Error {
		$owner = $session->owner_id();
		if ( 0 === $owner || get_current_user_id() === $owner || current_user_can( 'manage_options' ) ) {
			return null;
		}
		return new \WP_Error(
			'cf_cu_not_owner',
			__( 'This upload belongs to another user.', 'cf-chunked-upload' ),
			[ 'status' => 403 ]
		);
	}
}
