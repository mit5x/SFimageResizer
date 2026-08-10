<?php
/**
 * GD based image processing.
 *
 * @package SFimageResizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads, rotates, crops, resizes and re-encodes images with GD.
 */
class SFIR_Resizer {

	/**
	 * Hard ceiling on the number of source pixels, as a decompression bomb guard.
	 */
	const MAX_SOURCE_PIXELS = 80000000;

	/**
	 * Bytes assumed to be needed per pixel while GD holds an image in memory.
	 */
	const BYTES_PER_PIXEL = 4;

	/**
	 * Safety factor applied on top of the raw pixel estimate.
	 */
	const MEMORY_OVERHEAD = 1.8;

	/**
	 * Tells whether this GD build can write WebP files.
	 *
	 * @return bool
	 */
	public static function supports_webp() {
		return function_exists( 'imagewebp' );
	}

	/**
	 * Tells whether GD is available at all.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagecopyresampled' );
	}

	/**
	 * Returns the EXIF orientation of a JPEG file.
	 *
	 * @param string $path Absolute path to the file.
	 * @param string $mime MIME type of the file.
	 * @return int Orientation between 1 and 8, or 1 when unknown.
	 */
	public static function get_orientation( $path, $mime ) {
		if ( 'image/jpeg' !== $mime || ! function_exists( 'exif_read_data' ) ) {
			return 1;
		}

		$exif = @exif_read_data( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $exif ) || empty( $exif['Orientation'] ) ) {
			return 1;
		}

		$orientation = (int) $exif['Orientation'];

		return ( $orientation >= 1 && $orientation <= 8 ) ? $orientation : 1;
	}

	/**
	 * Tells whether an orientation swaps the width and the height.
	 *
	 * @param int $orientation EXIF orientation.
	 * @return bool
	 */
	public static function orientation_swaps_axes( $orientation ) {
		return in_array( (int) $orientation, array( 5, 6, 7, 8 ), true );
	}

	/**
	 * Checks that the image can be decoded within the available memory.
	 *
	 * @param int $src_w Source width.
	 * @param int $src_h Source height.
	 * @param int $dst_w Output width.
	 * @param int $dst_h Output height.
	 * @return bool
	 */
	public static function can_process( $src_w, $src_h, $dst_w, $dst_h ) {
		$src_pixels = (float) $src_w * (float) $src_h;

		if ( $src_pixels < 1 || $src_pixels > self::MAX_SOURCE_PIXELS ) {
			return false;
		}

		wp_raise_memory_limit( 'image' );

		$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );

		if ( $limit < 1 ) {
			// No limit configured: the pixel ceiling above is the only guard.
			return true;
		}

		$needed = ( $src_pixels + (float) $dst_w * (float) $dst_h ) * self::BYTES_PER_PIXEL * self::MEMORY_OVERHEAD;

		$available = (float) $limit - (float) memory_get_usage( true );

		return $needed < $available;
	}

	/**
	 * Generates a resized copy of an image.
	 *
	 * @param string $source_path Absolute path of the source file.
	 * @param string $source_mime MIME type of the source file.
	 * @param string $dest_path   Absolute path the result is written to.
	 * @param array  $params      Normalised parameters.
	 * @param array  $geometry    Geometry as returned by SFIR_Core::calculate_dimensions().
	 * @return string Empty string on success, an error code otherwise.
	 */
	public static function generate( $source_path, $source_mime, $dest_path, array $params, array $geometry ) {
		if ( ! self::is_available() ) {
			return 'E05';
		}

		if ( $geometry['dst_w'] < 1 || $geometry['dst_h'] < 1 ) {
			return 'E05';
		}

		$orientation = self::get_orientation( $source_path, $source_mime );

		$image = self::load( $source_path, $source_mime );

		if ( ! $image ) {
			return 'E05';
		}

		$image = self::apply_orientation( $image, $orientation );

		if ( ! $image ) {
			return 'E05';
		}

		if ( function_exists( 'imageistruecolor' ) && ! imageistruecolor( $image ) ) {
			imagepalettetotruecolor( $image );
		}

		$canvas = imagecreatetruecolor( $geometry['dst_w'], $geometry['dst_h'] );

		if ( ! $canvas ) {
			imagedestroy( $image );
			return 'E05';
		}

		$keep_alpha = 'webp' === $params['f'] && '' === $params['bg'];

		if ( $keep_alpha ) {
			// Alpha blending stays off for the copy so that source alpha is
			// written into the canvas instead of being composited away.
			imagealphablending( $canvas, false );
			imagesavealpha( $canvas, true );
			$transparent = imagecolorallocatealpha( $canvas, 255, 255, 255, 127 );
			imagefilledrectangle( $canvas, 0, 0, $geometry['dst_w'], $geometry['dst_h'], $transparent );
			imagealphablending( $image, false );
			imagesavealpha( $image, true );
		} else {
			$hex        = '' !== $params['bg'] ? $params['bg'] : 'FFFFFF';
			$background = imagecolorallocate(
				$canvas,
				(int) hexdec( substr( $hex, 0, 2 ) ),
				(int) hexdec( substr( $hex, 2, 2 ) ),
				(int) hexdec( substr( $hex, 4, 2 ) )
			);
			imagealphablending( $canvas, true );
			imagesavealpha( $canvas, false );
			imagefilledrectangle( $canvas, 0, 0, $geometry['dst_w'], $geometry['dst_h'], $background );
			imagealphablending( $image, true );
		}

		$copied = imagecopyresampled(
			$canvas,
			$image,
			0,
			0,
			$geometry['src_x'],
			$geometry['src_y'],
			$geometry['dst_w'],
			$geometry['dst_h'],
			$geometry['src_w'],
			$geometry['src_h']
		);

		imagedestroy( $image );

		if ( ! $copied ) {
			imagedestroy( $canvas );
			return 'E05';
		}

		if ( $keep_alpha ) {
			imagealphablending( $canvas, false );
			imagesavealpha( $canvas, true );
		}

		$written = self::write( $canvas, $dest_path, $params );

		imagedestroy( $canvas );

		if ( ! $written ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $dest_path );
			return 'E05';
		}

		@chmod( $dest_path, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		return '';
	}

	/**
	 * Loads an image resource from a file.
	 *
	 * @param string $path Absolute path.
	 * @param string $mime MIME type.
	 * @return resource|GdImage|false
	 */
	protected static function load( $path, $mime ) {
		switch ( $mime ) {
			case 'image/jpeg':
				return function_exists( 'imagecreatefromjpeg' ) ? @imagecreatefromjpeg( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			case 'image/png':
				return function_exists( 'imagecreatefrompng' ) ? @imagecreatefrompng( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			case 'image/gif':
				return function_exists( 'imagecreatefromgif' ) ? @imagecreatefromgif( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			case 'image/webp':
				return function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		return false;
	}

	/**
	 * Applies the EXIF orientation to a loaded image.
	 *
	 * @param resource|GdImage $image       Image resource.
	 * @param int              $orientation EXIF orientation.
	 * @return resource|GdImage|false
	 */
	protected static function apply_orientation( $image, $orientation ) {
		if ( 1 === (int) $orientation || ! function_exists( 'imagerotate' ) ) {
			return $image;
		}

		$angle = 0;
		$flip  = 0;

		switch ( (int) $orientation ) {
			case 2:
				$flip = IMG_FLIP_HORIZONTAL;
				break;
			case 3:
				$angle = 180;
				break;
			case 4:
				$flip = IMG_FLIP_VERTICAL;
				break;
			case 5:
				$flip  = IMG_FLIP_HORIZONTAL;
				$angle = 270;
				break;
			case 6:
				$angle = 270;
				break;
			case 7:
				$flip  = IMG_FLIP_HORIZONTAL;
				$angle = 90;
				break;
			case 8:
				$angle = 90;
				break;
		}

		if ( $flip && function_exists( 'imageflip' ) ) {
			imageflip( $image, $flip );
		}

		if ( $angle ) {
			imagealphablending( $image, false );
			imagesavealpha( $image, true );
			$rotated = imagerotate( $image, $angle, 0 );

			if ( $rotated ) {
				imagedestroy( $image );
				$image = $rotated;
			}
		}

		return $image;
	}

	/**
	 * Encodes the canvas into the requested output format.
	 *
	 * @param resource|GdImage $canvas    Image to encode.
	 * @param string           $dest_path Destination path.
	 * @param array            $params    Normalised parameters.
	 * @return bool
	 */
	protected static function write( $canvas, $dest_path, array $params ) {
		$quality = isset( $params['q'] ) ? (int) $params['q'] : SFIR_Core::DEFAULT_QUALITY;

		if ( 'webp' === $params['f'] && self::supports_webp() ) {
			return (bool) @imagewebp( $canvas, $dest_path, $quality ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( ! function_exists( 'imagejpeg' ) ) {
			return false;
		}

		return (bool) @imagejpeg( $canvas, $dest_path, $quality ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
