<?php
namespace CFForms;

defined( 'ABSPATH' ) || exit;

/**
 * Storage for form submissions. Deliberately a non-public CPT: entries are
 * never queried on the front end, only read through wp-admin.
 */
final class EntryPostType {

	const SLUG = 'cff_entry';

	const META_FORM_ID    = '_cff_form_id';
	const META_FIELDS     = '_cff_fields';
	const META_IP         = '_cff_ip';
	const META_USER_AGENT = '_cff_user_agent';
	const META_STATUS     = '_cff_status';
	const META_MAIL_SENT  = '_cff_mail_sent';

	const STATUS_NEW  = 'new';
	const STATUS_READ = 'read';
	const STATUS_SPAM = 'spam';

	public static function register(): void {
		register_post_type(
			self::SLUG,
			[
				'labels'              => [
					'name'          => __( 'Form Entries', 'cf-forms' ),
					'singular_name' => __( 'Form Entry', 'cf-forms' ),
					'menu_name'     => __( 'CF Forms', 'cf-forms' ),
					'all_items'     => __( 'Entries', 'cf-forms' ),
					'not_found'     => __( 'No submissions yet.', 'cf-forms' ),
				],
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'menu_icon'           => 'dashicons-email-alt2',
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
				'supports'            => [ 'title' ],
				'has_archive'         => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'rewrite'             => false,
			]
		);

		register_post_meta(
			self::SLUG,
			self::META_STATUS,
			[
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => false,
			]
		);
	}

	/**
	 * Persist one validated submission.
	 *
	 * @param string $form_id Caller-supplied form slug (already sanitize_key'd).
	 * @param array  $fields  Sanitized field map.
	 * @param string $ip      Submitter IP.
	 * @param string $user_agent Submitter user agent.
	 * @return int Post ID, or 0 on failure.
	 */
	public static function create( string $form_id, array $fields, string $ip, string $user_agent ): int {
		$post_id = wp_insert_post(
			[
				'post_type'   => self::SLUG,
				'post_status' => 'publish',
				// translators: 1: form id, 2: submission date/time.
				'post_title'  => sprintf( __( '%1$s (%2$s)', 'cf-forms' ), $form_id, current_time( 'mysql' ) ),
			],
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		update_post_meta( $post_id, self::META_FORM_ID, $form_id );
		update_post_meta( $post_id, self::META_FIELDS, wp_json_encode( $fields ) );
		update_post_meta( $post_id, self::META_IP, $ip );
		update_post_meta( $post_id, self::META_USER_AGENT, $user_agent );
		update_post_meta( $post_id, self::META_STATUS, self::STATUS_NEW );

		return (int) $post_id;
	}

	/**
	 * Record whether the notification email was accepted by wp_mail(), so a
	 * deliverability problem is visible in the entry list instead of silent.
	 *
	 * @param int  $entry_id Stored entry post ID.
	 * @param bool $sent     wp_mail() return value.
	 */
	public static function record_notification( int $entry_id, bool $sent ): void {
		update_post_meta( $entry_id, self::META_MAIL_SENT, $sent ? '1' : '0' );
	}
}
