<?php

namespace CFContentCalendar;

defined( 'ABSPATH' ) || exit;

class Admin {

	private string $hook = '';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function register_page(): void {
		$this->hook = (string) add_posts_page(
			__( 'Content Calendar', 'cf-content-calendar' ),
			__( 'Calendar', 'cf-content-calendar' ),
			'edit_posts',
			'cf-content-calendar',
			[ $this, 'render_page' ]
		);
	}

	public function render_page(): void {
		echo '<div class="wrap"><div id="cf-cal-root"></div></div>';
	}

	public function enqueue_assets( string $hook ): void {
		if ( '' === $this->hook || $this->hook !== $hook ) {
			return;
		}

		$asset_file = CFCAL_DIR . 'build/calendar.asset.php';
		if ( ! is_file( $asset_file ) ) {
			// The bootstrap guards this at load time, but a build/ deleted from
			// under a running install shouldn't fatal the admin screen.
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			'cf-content-calendar',
			CFCAL_URL . 'build/calendar.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'cf-content-calendar',
			CFCAL_URL . 'build/calendar.css',
			// wp-components provides the Modal / Button / CheckboxControl styles
			// used by the reschedule-confirm dialog.
			[ 'wp-components' ],
			$asset['version']
		);

		wp_set_script_translations(
			'cf-content-calendar',
			'cf-content-calendar',
			CFCAL_DIR . 'languages'
		);

		$can_edit_others = current_user_can( 'edit_others_posts' );

		wp_localize_script(
			'cf-content-calendar',
			'cfCalData',
			[
				'restUrl'       => esc_url_raw( rest_url( 'cf-cal/v1' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'postTypes'     => $this->get_available_post_types(),
				'canPublish'    => current_user_can( 'publish_posts' ),
				'canEditOthers' => $can_edit_others,
				// Only surface the author roster to users who can actually
				// assign / filter by other authors — avoids leaking the user
				// list to Contributors.
				'authors'       => $can_edit_others ? $this->get_authors() : [],
				'timezone'      => wp_timezone_string(),
			]
		);
	}

	private function get_authors(): array {
		$users  = get_users(
			[
				'capability' => [ 'edit_posts' ],
				'fields'     => [ 'ID', 'display_name' ],
				'orderby'    => 'display_name',
				'number'     => 200,
			]
		);
		$result = [];
		foreach ( $users as $user ) {
			$result[ (int) $user->ID ] = $user->display_name;
		}
		return $result;
	}

	private function get_available_post_types(): array {
		$types  = get_post_types( [ 'public' => true ], 'objects' );
		$result = [];
		foreach ( $types as $slug => $obj ) {
			if ( 'attachment' === $slug ) {
				continue;
			}
			$result[ $slug ] = $obj->labels->name ?? $slug;
		}
		return $result;
	}
}
