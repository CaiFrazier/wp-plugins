<?php
namespace SchemaOverrideManager;

defined( 'ABSPATH' ) || exit;

class MetaBox {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function init(): void {
		add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );
		// All persistence goes through the REST controller (capability + nonce
		// checked there). No save_post hook is needed — the meta box renders a
		// React app and POSTs through wp.apiFetch.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function register_meta_box(): void {
		$settings   = $this->settings->get_settings();
		$post_types = $settings['enabled_post_types'] ?? [ 'post', 'page' ];

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'som-meta-box',
				__( 'Schema Override', 'schema-override-manager' ),
				[ $this, 'render_meta_box' ],
				$post_type,
				'normal',
				'default'
			);
		}
	}

	public function render_meta_box( \WP_Post $post ): void {
		echo '<div id="som-meta-box-root" data-post-id="' . esc_attr( $post->ID ) . '"></div>';
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$post = $this->get_current_post();
		if ( ! $post ) {
			return;
		}

		$settings   = $this->settings->get_settings();
		$post_types = $settings['enabled_post_types'] ?? [ 'post', 'page' ];
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return;
		}

		$this->enqueue_meta_box_script( $post );
	}

	private function get_current_post(): ?\WP_Post {
		global $post;
		if ( $post instanceof \WP_Post ) {
			return $post;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection; no state change.
		$id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		return $id ? get_post( $id ) : null;
	}

	private function enqueue_meta_box_script( \WP_Post $post ): void {
		$asset_file = SOM_DIR . 'build/meta-box/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => SOM_VERSION,
			];

		wp_enqueue_script(
			'som-meta-box',
			SOM_URL . 'build/meta-box/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'som-meta-box',
			SOM_URL . 'build/meta-box/index.css',
			[ 'wp-components' ],
			$asset['version']
		);

		// wp-scripts lists wp-i18n in the built asset's dependencies, so point WP
		// at the .json language files it serves for this handle.
		wp_set_script_translations( 'som-meta-box', 'schema-override-manager', SOM_DIR . 'languages' );

		$settings = $this->settings->get_settings();
		$detector = new SchemaDetector();
		// Only expose a URL the external validators can actually fetch. A draft/
		// pending/private post has a permalink, but Google's Rich Results Test
		// and validator.schema.org fetch anonymously and would 404, so leave it
		// empty (the sidebar hides the validate links) until the post is public.
		$permalink = is_post_publicly_viewable( $post ) ? get_permalink( $post->ID ) : '';

		wp_localize_script(
			'som-meta-box',
			'somMetaBox',
			[
				'restUrl'          => esc_url_raw( rest_url( 'som/v1' ) ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'postId'           => $post->ID,
				'postType'         => $post->post_type,
				// Consumed by the Block Editor entry point to gate PluginSidebar
				// registration to enabled types (defense in depth: the script is
				// already only enqueued on enabled types, see enqueue_assets).
				'enabledPostTypes' => array_values( $settings['enabled_post_types'] ?? [ 'post', 'page' ] ),
				// Public URL for the "Validate in Google Rich Results Test" link.
				// Empty (link hidden) when the post has no resolvable permalink.
				'postUrl'          => $permalink ? esc_url_raw( $permalink ) : '',
				'schema'           => $this->settings->get_page_schema( $post->ID ),
				'suppression'      => $this->settings->get_page_suppression( $post->ID ),
				'template'         => $this->settings->get_template_schema( $post->post_type ),
				// Known suppressible types per SEO plugin, so per-type checkboxes
				// appear even when live filter detection returns nothing.
				'knownTypes'       => [
					'yoast'     => $detector->get_yoast_types(),
					'rank_math' => $detector->get_rank_math_types(),
				],
			]
		);
	}
}
