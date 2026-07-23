<?php
namespace CFForms;

defined( 'ABSPATH' ) || exit;

/** Validates and privately stores a Continuum diagnostics ZIP. */
final class SupportUpload {

	const MAX_BYTES = 20 * 1024 * 1024;

	/**
	 * Validate and store one diagnostics archive.
	 *
	 * @param array $file One normalized PHP upload entry.
	 * @return string|\WP_Error Stored absolute path or validation error.
	 */
	public static function store( array $file ) {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_OK !== $error ) {
			return new \WP_Error( 'cff_support_upload_failed', __( 'Choose a diagnostics ZIP to upload.', 'cf-forms' ), [ 'status' => 400 ] );
		}

		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $tmp || ! is_readable( $tmp ) ) {
			return new \WP_Error( 'cff_support_upload_type', __( 'The attachment must be a valid ZIP archive.', 'cf-forms' ), [ 'status' => 400 ] );
		}
		$size = filesize( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- The server-side file size is authoritative; the multipart size is caller-controlled.
		if ( false === $size || $size < 1 || $size > self::MAX_BYTES ) {
			return new \WP_Error( 'cff_support_upload_size', __( 'The diagnostics ZIP must be 20 MB or smaller.', 'cf-forms' ), [ 'status' => 413 ] );
		}
		if ( ! self::is_valid_zip( $tmp ) ) {
			return new \WP_Error( 'cff_support_upload_type', __( 'The attachment must be a valid ZIP archive.', 'cf-forms' ), [ 'status' => 400 ] );
		}

		$name = sanitize_file_name( (string) ( $file['name'] ?? 'continuum-diagnostics.zip' ) );
		if ( 'zip' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return new \WP_Error( 'cff_support_upload_type', __( 'The attachment must use the .zip extension.', 'cf-forms' ), [ 'status' => 400 ] );
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new \WP_Error( 'cff_support_upload_storage', __( 'The server could not prepare attachment storage.', 'cf-forms' ), [ 'status' => 500 ] );
		}
		$directory = trailingslashit( $uploads['basedir'] ) . 'cf-forms-support';
		if ( ! wp_mkdir_p( $directory ) ) {
			return new \WP_Error( 'cff_support_upload_storage', __( 'The server could not create attachment storage.', 'cf-forms' ), [ 'status' => 500 ] );
		}
		if ( ! self::protect_directory( $directory ) ) {
			return new \WP_Error( 'cff_support_upload_storage', __( 'The server could not protect attachment storage.', 'cf-forms' ), [ 'status' => 500 ] );
		}
		// Never expose a submitter-provided filename or a guessable basename in
		// the public uploads tree. The entry metadata and email attachment retain
		// the authoritative path.
		$private_name = wp_generate_uuid4() . '-continuum-diagnostics.zip';

		$destination = trailingslashit( $directory ) . wp_unique_filename( $directory, $private_name );
		$moved       = move_uploaded_file( $tmp, $destination );
		if ( ! $moved && defined( 'CFF_VERSION' ) && 'test' === CFF_VERSION ) {
			$moved = rename( $tmp, $destination ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Test-only fallback because CLI fixtures are not PHP uploads.
		}
		if ( ! $moved ) {
			return new \WP_Error( 'cff_support_upload_storage', __( 'The uploaded diagnostics file could not be stored.', 'cf-forms' ), [ 'status' => 500 ] );
		}
		chmod( $destination, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Support archives require owner-only permissions.
		return $destination;
	}

	private static function protect_directory( string $directory ): bool {
		$guards = [
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
			'index.php'  => "<?php\nhttp_response_code( 404 );\nexit;\n",
		];
		foreach ( $guards as $name => $contents ) {
			$path = trailingslashit( $directory ) . $name;
			if ( file_exists( $path ) ) {
				continue;
			}
			$written = file_put_contents( $path, $contents, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Server guard files must exist before retaining private support archives.
			if ( false === $written ) {
				return false;
			}
			chmod( $path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Guard files are owner-only configuration.
		}
		return true;
	}

	private static function is_valid_zip( string $path ): bool {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reads four signature bytes from a PHP upload temporary file.
		if ( false === $handle ) {
			return false;
		}
		$signature = fread( $handle, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Bounded four-byte signature check.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Matches the bounded signature read above.
		if ( ! in_array( $signature, [ "PK\x03\x04", "PK\x05\x06", "PK\x07\x08" ], true ) ) {
			return false;
		}

		if ( class_exists( '\\ZipArchive' ) ) {
			$archive = new \ZipArchive();
			$result  = $archive->open( $path, \ZipArchive::CHECKCONS );
			if ( true !== $result ) {
				return false;
			}
			$archive->close();
			return true;
		}

		// ZipArchive is optional in PHP. The end-of-central-directory record must
		// appear within the maximum ZIP comment window even for an empty archive.
		$tail = file_get_contents( $path, false, null, max( 0, filesize( $path ) - 65_557 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bounded structural validation of a local PHP upload.
		return false !== $tail && false !== strrpos( $tail, "PK\x05\x06" );
	}
}
