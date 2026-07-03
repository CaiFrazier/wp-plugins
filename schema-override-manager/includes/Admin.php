<?php
namespace SchemaOverrideManager;

defined( 'ABSPATH' ) || exit;

class Admin {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_settings_assets' ] );
	}

	public function register_menu(): void {
		add_options_page(
			__( 'Schema Override Manager', 'schema-override-manager' ),
			__( 'Schema Override', 'schema-override-manager' ),
			'manage_options',
			'schema-override-manager',
			[ $this, 'render_settings_page' ]
		);
	}

	public function render_settings_page(): void {
		echo '<div id="som-settings-root" class="wrap"></div>';
	}

	public function enqueue_settings_assets( string $hook ): void {
		if ( 'settings_page_schema-override-manager' !== $hook ) {
			return;
		}

		$asset_file = SOM_DIR . 'build/settings/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => SOM_VERSION,
			];

		wp_enqueue_script(
			'som-settings',
			SOM_URL . 'build/settings/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'som-settings',
			SOM_URL . 'build/settings/index.css',
			[ 'wp-components' ],
			$asset['version']
		);

		// wp-scripts lists wp-i18n in the built asset's dependencies (the source
		// uses @wordpress/i18n), so wp.i18n is on the page. Point WP at the .json
		// language files it serves for this handle.
		wp_set_script_translations( 'som-settings', 'schema-override-manager', SOM_DIR . 'languages' );

		wp_localize_script(
			'som-settings',
			'somSettings',
			[
				'restUrl'      => esc_url_raw( rest_url( 'som/v1' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'settings'     => $this->settings->get_settings(),
				'global'       => $this->settings->get_global_schema(),
				'suppress'     => $this->settings->get_global_suppression(),
				'postTypes'    => $this->get_post_types_with_templates(),
				'allPostTypes' => $this->get_all_public_post_types(),
			]
		);
	}

	private function get_post_types_with_templates(): array {
		$post_types = get_post_types( [ 'public' => true ], 'objects' );
		$result     = [];

		foreach ( $post_types as $slug => $obj ) {
			$result[] = [
				'slug'     => $slug,
				'label'    => $obj->labels->singular_name,
				'template' => $this->settings->get_template_schema( $slug ),
			];
		}

		return $result;
	}

	private function get_all_public_post_types(): array {
		$post_types = get_post_types( [ 'public' => true ], 'objects' );
		$result     = [];
		foreach ( $post_types as $slug => $obj ) {
			$result[] = [
				'slug'  => $slug,
				'label' => $obj->labels->singular_name,
			];
		}
		return $result;
	}
}
