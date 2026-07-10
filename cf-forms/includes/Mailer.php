<?php
namespace CFForms;

defined( 'ABSPATH' ) || exit;

/**
 * Notification email for a stored submission. Delivery reliability (SPF/DKIM,
 * actual SMTP transport) is intentionally out of scope here; that is handled
 * site-wide by an SMTP plugin hooked into wp_mail(), not per-plugin.
 */
final class Mailer {

	public static function notify( string $to, string $form_id, array $fields, int $entry_id ): bool {
		/**
		 * Filter the notification recipient. Return an empty string to skip mail
		 * entirely (the submission is still stored).
		 *
		 * @param string $to      Configured recipient.
		 * @param string $form_id Sanitized form slug.
		 * @param array  $fields  Sanitized field map.
		 */
		$to = (string) apply_filters( 'cff_notification_recipient', $to, $form_id, $fields );

		if ( '' === $to || ! is_email( $to ) ) {
			return false;
		}

		// translators: %s: form id.
		$subject = sprintf( __( '[CF Forms] New submission: %s', 'cf-forms' ), $form_id );
		$subject = (string) apply_filters( 'cff_notification_subject', $subject, $form_id, $fields );

		$lines = [];
		foreach ( $fields as $key => $value ) {
			$lines[] = $key . ': ' . $value;
		}

		$body  = implode( "\n", $lines );
		$body .= "\n\n" . sprintf(
			// translators: %s: admin edit link.
			__( 'View this entry: %s', 'cf-forms' ),
			admin_url( 'post.php?post=' . $entry_id . '&action=edit' )
		);
		$body = (string) apply_filters( 'cff_notification_body', $body, $form_id, $fields, $entry_id );

		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

		// If the submission carries a valid email, set Reply-To so a reply reaches
		// the sender instead of the site's from-address. is_email() guarantees no
		// newline, so this cannot be used for header injection.
		$reply_to = self::find_reply_to( $fields );
		if ( '' !== $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		$headers = (array) apply_filters( 'cff_notification_headers', $headers, $form_id, $fields );

		return wp_mail( $to, $subject, $body, $headers );
	}

	/**
	 * Pick the first field whose key contains "email" and holds a valid address.
	 *
	 * @param array $fields Sanitized field map.
	 * @return string A valid email, or '' if none found.
	 */
	private static function find_reply_to( array $fields ): string {
		foreach ( $fields as $key => $value ) {
			if ( false !== stripos( (string) $key, 'email' ) && is_email( (string) $value ) ) {
				return (string) $value;
			}
		}
		return '';
	}
}
