<?php

namespace CFMediaOptimizer;

use Imagick;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

// Local binary profile/header I/O requires seekable streams; no remote requests.
// phpcs:disable WordPress.WP.AlternativeFunctions

/** Preserve appearance before removing identifying image metadata. */
final class ColorManagement {

	/** Convert through the embedded ICC profile, then strip metadata. */
	public static function prepare( Imagick $image ): void {
		$profiles = $image->getImageProfiles( 'icc', true );
		if ( ! empty( $profiles['icc'] ) ) {
			$srgb = file_get_contents( dirname( __DIR__ ) . '/assets/color/srgb.icc' );
			if ( false === $srgb || ! $image->profileImage( 'icc', $srgb ) ) {
				throw new RuntimeException( 'Could not convert the embedded color profile to sRGB.' );
			}
		} elseif ( Imagick::COLORSPACE_SRGB !== $image->getImageColorspace() ) {
			if ( ! $image->transformImageColorspace( Imagick::COLORSPACE_SRGB ) ) {
				throw new RuntimeException( 'Could not convert the image color space to sRGB.' );
			}
		}
		$image->stripImage();
	}

	/**
	 * GD cannot apply ICC profiles. Inspect JPEG/PNG headers before fallback
	 * and fail closed if the headers cannot be read. Never inspect compressed
	 * pixel bytes as if they were metadata.
	 */
	public static function requires_color_management( string $path ): bool {
		$stream = fopen( $path, 'rb' );
		if ( false === $stream ) {
			return true;
		}
		try {
			$size      = filesize( $path );
			$signature = fread( $stream, 8 );
			if ( "\x89PNG\r\n\x1a\n" === $signature ) {
				while ( ftell( $stream ) + 12 <= $size ) {
					$header = fread( $stream, 8 );
					$length = unpack( 'N', substr( $header, 0, 4 ) )[1];
					$type   = substr( $header, 4, 4 );
					if ( 'iCCP' === $type ) {
						return true;
					}
					if ( 'IDAT' === $type || 'IEND' === $type ) {
						return false;
					}
					if ( ftell( $stream ) + $length + 4 > $size || 0 !== fseek( $stream, $length + 4, SEEK_CUR ) ) {
						return true;
					}
				}
				return true;
			}
			if ( "\xff\xd8" !== substr( $signature, 0, 2 ) ) {
				return true;
			}
			fseek( $stream, 2 );
			while ( ftell( $stream ) + 2 <= $size ) {
				if ( "\xff" !== fread( $stream, 1 ) ) {
					return true;
				}
				do {
					$marker = fread( $stream, 1 );
				} while ( "\xff" === $marker );
				if ( '' === $marker ) {
					return true;
				}
				$marker = ord( $marker );
				if ( 0xda === $marker || 0xd9 === $marker ) {
					return false;
				}
				if ( 0x01 === $marker || ( $marker >= 0xd0 && $marker <= 0xd7 ) ) {
					continue;
				}
				$bytes = fread( $stream, 2 );
				if ( 2 !== strlen( $bytes ) ) {
					return true;
				}
				$length = unpack( 'n', $bytes )[1] - 2;
				if ( $length < 0 || ftell( $stream ) + $length > $size ) {
					return true;
				}
				if ( 0xe2 === $marker && $length >= 12 ) {
					if ( "ICC_PROFILE\0" === fread( $stream, 12 ) ) {
						return true;
					}
					$length -= 12;
				}
				if ( 0 !== fseek( $stream, $length, SEEK_CUR ) ) {
					return true;
				}
			}
			return true;
		} finally {
			fclose( $stream );
		}
	}
}
