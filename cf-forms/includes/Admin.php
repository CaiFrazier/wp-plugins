<?php
namespace CFForms;

defined( 'ABSPATH' ) || exit;

/**
 * Admin list-table polish for the cff_entry CPT: a form / submission / status
 * column set so entries are readable without opening each one.
 */
final class Admin {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function init(): void {
		add_filter( 'manage_' . EntryPostType::SLUG . '_posts_columns', [ $this, 'columns' ] );
		add_action( 'manage_' . EntryPostType::SLUG . '_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
		add_filter( 'post_row_actions', [ $this, 'filter_row_actions' ], 10, 2 );
	}

	public function columns( array $columns ): array {
		$date = $columns['date'] ?? __( 'Date', 'cf-forms' );
		unset( $columns['title'], $columns['date'] );

		return array_merge(
			[
				'cff_form'   => __( 'Form', 'cf-forms' ),
				'cff_status' => __( 'Status', 'cf-forms' ),
				'cff_fields' => __( 'Submission', 'cf-forms' ),
				'cff_mail'   => __( 'Notified', 'cf-forms' ),
			],
			$columns,
			[ 'date' => $date ]
		);
	}

	public function render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'cff_form':
				echo esc_html( (string) get_post_meta( $post_id, EntryPostType::META_FORM_ID, true ) );
				break;

			case 'cff_status':
				echo esc_html( (string) get_post_meta( $post_id, EntryPostType::META_STATUS, true ) );
				break;

			case 'cff_fields':
				$fields = json_decode( (string) get_post_meta( $post_id, EntryPostType::META_FIELDS, true ), true );
				if ( ! is_array( $fields ) ) {
					break;
				}
				$preview = [];
				foreach ( $fields as $key => $value ) {
					$preview[] = esc_html( $key ) . ': ' . esc_html( wp_trim_words( (string) $value, 8 ) );
				}
				echo implode( '<br>', $preview ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each segment already escaped above.
				break;

			case 'cff_mail':
				$sent = get_post_meta( $post_id, EntryPostType::META_MAIL_SENT, true );
				if ( '' === $sent ) {
					echo '<span aria-hidden="true">&ndash;</span>';
				} else {
					echo '1' === $sent
						? esc_html__( 'Sent', 'cf-forms' )
						: '<span style="color:#b32d2e;">' . esc_html__( 'Failed', 'cf-forms' ) . '</span>';
				}
				break;
		}
	}

	/**
	 * Entries are not posts an editor writes, so "Quick Edit" only invites
	 * accidental mangling of stored submission data. Drop it.
	 *
	 * @param array    $actions Row actions keyed by action name.
	 * @param \WP_Post $post    The current row's post.
	 */
	public function filter_row_actions( array $actions, \WP_Post $post ): array {
		if ( EntryPostType::SLUG === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'] );
		}
		return $actions;
	}
}
