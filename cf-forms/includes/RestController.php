<?php
namespace CFForms;

defined( 'ABSPATH' ) || exit;

/**
 * Public submission endpoint. No front-end forms exist yet; this is the
 * contract they POST to once built:
 *
 *   POST /wp-json/cf-forms/v1/submit
 *   {
 *     "form_id": "contact",              // required, becomes a sanitize_key'd slug
 *     "fields": { "name": "...", ... },  // required, assoc array of scalars
 *     "hp_field": "",                    // honeypot, must arrive empty
 *     "rendered_at": 1720450000          // unix seconds when the form was rendered
 *   }
 *
 * Every accepted request returns the exact same body on success, spam-rejection,
 * and honeypot trip: `{"success":true}`. Callers cannot tell a stored submission
 * apart from a silently-dropped one, so scripted submitters get no signal to
 * adapt against.
 */
final class RestController {

	const REST_NAMESPACE = 'cf-forms/v1';
	const HONEYPOT_FIELD = 'hp_field';

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/submit',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_submit' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'form_id' => [ 'required' => true ],
					'fields'  => [ 'required' => true ],
				],
			]
		);
	}

	public function handle_submit( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}

		$form_id = Sanitizer::sanitize_form_id( $body['form_id'] ?? '' );
		if ( '' === $form_id ) {
			return new \WP_Error( 'cff_invalid_form_id', __( 'A valid form_id is required.', 'cf-forms' ), [ 'status' => 400 ] );
		}

		$ip      = $this->get_client_ip();
		$limiter = new RateLimiter(
			(int) $this->settings->get( 'rate_limit_max' ),
			(int) $this->settings->get( 'rate_limit_window' )
		);

		if ( $limiter->is_exceeded( $ip ) ) {
			return new \WP_Error( 'cff_rate_limited', __( 'Too many submissions. Please try again later.', 'cf-forms' ), [ 'status' => 429 ] );
		}

		// Count every well-formed request against the bucket before branching, so
		// honeypot trips, time-trap trips, and validation failures are all
		// throttled the same way a stored submission is. Recording only on the
		// happy path would let a bot hammer the endpoint with rejected payloads
		// forever without ever filling its bucket.
		$limiter->record( $ip );

		// Honeypot: a field named hp_field should stay empty for a human filling
		// a visually-hidden input. Bots that fill every field trip it.
		if ( ! empty( $body[ self::HONEYPOT_FIELD ] ) ) {
			do_action( 'cff_spam_detected', $form_id, $ip, 'honeypot' );
			return $this->success();
		}

		// Time-trap: reject submissions faster than a human could plausibly fill
		// the form. The front end sends the unix timestamp of when it rendered
		// the form as `rendered_at`. A future timestamp (negative elapsed) is
		// treated as tampering and dropped too.
		$min_elapsed = (int) $this->settings->get( 'min_elapsed_seconds' );
		$rendered_at = isset( $body['rendered_at'] ) ? (int) $body['rendered_at'] : 0;
		if ( $min_elapsed > 0 && $rendered_at > 0 && ( time() - $rendered_at ) < $min_elapsed ) {
			do_action( 'cff_spam_detected', $form_id, $ip, 'time_trap' );
			return $this->success();
		}

		$fields = Sanitizer::sanitize_fields( $body['fields'] ?? [] );
		if ( empty( $fields ) ) {
			return new \WP_Error( 'cff_empty_fields', __( 'No valid fields were submitted.', 'cf-forms' ), [ 'status' => 400 ] );
		}

		/**
		 * Gate storage on caller-supplied validation. Return a WP_Error to reject
		 * (its message/status flow back to the client); return true to accept.
		 * Lets a front end enforce per-form required fields without patching the
		 * plugin.
		 *
		 * @param true|\WP_Error $valid   Accept by default.
		 * @param string         $form_id Sanitized form slug.
		 * @param array          $fields  Sanitized field map.
		 */
		$valid = apply_filters( 'cff_validate_submission', true, $form_id, $fields );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$entry_id   = EntryPostType::create( $form_id, $fields, $ip, $user_agent );

		if ( ! $entry_id ) {
			return new \WP_Error( 'cff_storage_failed', __( 'Could not store the submission.', 'cf-forms' ), [ 'status' => 500 ] );
		}

		$sent = Mailer::notify( (string) $this->settings->get( 'notification_email' ), $form_id, $fields, $entry_id );
		EntryPostType::record_notification( $entry_id, $sent );

		/**
		 * Fires after a submission is stored and the notification attempted.
		 *
		 * @param int    $entry_id Stored entry post ID.
		 * @param string $form_id  Sanitized form slug.
		 * @param array  $fields   Sanitized field map.
		 */
		do_action( 'cff_entry_created', $entry_id, $form_id, $fields );

		return $this->success();
	}

	/**
	 * The single success shape. Deliberately carries no entry id or other
	 * distinguishing data so a stored submission and a silently-dropped spam
	 * submission are byte-for-byte identical to the caller.
	 */
	private function success(): \WP_REST_Response {
		return rest_ensure_response( [ 'success' => true ] );
	}

	/**
	 * Best-effort client IP. Trusts CF-Connecting-IP / X-Forwarded-For only
	 * because this value feeds rate-limiting and a stored audit field, never an
	 * access-control decision; a spoofed IP at worst lets a submission dodge its
	 * own throttle bucket. Sites behind a known proxy can refine the result
	 * through the `cff_client_ip` filter.
	 */
	private function get_client_ip(): string {
		$ip = '0.0.0.0';

		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				$first = trim( explode( ',', $value )[0] );
				if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
					$ip = $first;
					break;
				}
			}
		}

		/**
		 * Filter the resolved client IP.
		 *
		 * @param string $ip      Resolved IP, or '0.0.0.0' when none validated.
		 * @param array  $headers The $_SERVER superglobal, for custom resolution.
		 */
		$ip = (string) apply_filters( 'cff_client_ip', $ip, $_SERVER );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}
}
