<?php

namespace CFChunkedUpload;

defined( 'ABSPATH' ) || exit;

/**
 * Filesystem layout. All chunk temp storage lives under a single root OUTSIDE
 * wp-content/uploads/ so that even on hosts where .htaccess is ignored (Nginx)
 * the path carries no permissive static-file rule. The root and the imports
 * directory are created with index.php + .htaccess + web.config silence files.
 *
 * Pure path computation — the constructor takes the base directory so tests
 * can point it at a temp sandbox without a WordPress install.
 */
final class Paths {

	private string $content_dir;

	public function __construct( string $content_dir ) {
		$this->content_dir = untrailingslashit( $content_dir );
	}

	public function chunks_root(): string {
		return $this->content_dir . '/cf-chunks';
	}

	public function default_imports_dir(): string {
		return $this->content_dir . '/cf-imports';
	}

	/**
	 * Absolute path to a session's chunk directory. The caller MUST have
	 * validated $upload_id with UploadSession::is_valid_id() first — this
	 * method does not re-validate and will happily build a traversal path
	 * from a hostile id otherwise.
	 *
	 * @param string $upload_id Pre-validated UUID v4.
	 * @return string
	 */
	public function session_dir( string $upload_id ): string {
		return $this->chunks_root() . '/' . $upload_id;
	}

	/**
	 * Create a directory (recursively) and drop the three silence files used
	 * across this repo's plugins. Idempotent. Returns false if the directory
	 * could not be created.
	 *
	 * @param string $dir Absolute directory path.
	 * @return bool
	 */
	public function ensure_hardened_dir( string $dir ): bool {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $htaccess, "Order allow,deny\nDeny from all\n" );
		}

		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$webconfig = $dir . '/web.config';
		if ( ! file_exists( $webconfig ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents(
				$webconfig,
				"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n"
			);
		}

		return true;
	}
}
