<?php
/**
 * SVG placeholders shown instead of a broken image.
 *
 * @package SFimageResizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the placeholder SVG and its URL.
 *
 * The markup contains a background, a frame and two text lines only. No
 * scripts, no external references, nothing that depends on user input beyond a
 * whitelisted error code and two integers.
 */
class SFIR_Placeholder {

	/**
	 * Default placeholder width when no size was requested.
	 */
	const DEFAULT_WIDTH = 300;

	/**
	 * Default placeholder height when no size was requested.
	 */
	const DEFAULT_HEIGHT = 200;

	/**
	 * Returns the error codes the plugin can report, with their meaning.
	 *
	 * @return array<string,string> Code to description map.
	 */
	public static function get_codes() {
		return array(
			'E01' => __( 'Source file not found, or path outside the allowed directories.', 'sf-image-resizer' ),
			'E02' => __( 'Unsupported or unrecognised input file format.', 'sf-image-resizer' ),
			'E03' => __( 'External URL or disallowed scheme.', 'sf-image-resizer' ),
			'E04' => __( 'Invalid request parameters or invalid signature.', 'sf-image-resizer' ),
			'E05' => __( 'GD processing error.', 'sf-image-resizer' ),
			'E06' => __( 'Cache directory is not writable.', 'sf-image-resizer' ),
			'E07' => __( 'Image is too large to process (memory or pixel limit).', 'sf-image-resizer' ),
		);
	}

	/**
	 * Normalises an error code, falling back to E01.
	 *
	 * @param string $code Raw code.
	 * @return string
	 */
	public static function normalize_code( $code ) {
		$code  = strtoupper( trim( (string) $code ) );
		$codes = self::get_codes();

		return isset( $codes[ $code ] ) ? $code : 'E01';
	}

	/**
	 * Calculates the placeholder size for a set of requested dimensions.
	 *
	 * The same maths is used by sf_img_width() and sf_img_height() so that the
	 * reported size always matches the image that is actually served.
	 *
	 * @param int $width  Requested width, 0 when unset.
	 * @param int $height Requested height, 0 when unset.
	 * @return array {
	 *     Placeholder size.
	 *
	 *     @type int $width  Width in pixels.
	 *     @type int $height Height in pixels.
	 * }
	 */
	public static function get_size( $width, $height ) {
		$width  = max( 0, min( (int) $width, SFIR_MAX_DIMENSION ) );
		$height = max( 0, min( (int) $height, SFIR_MAX_DIMENSION ) );

		if ( $width > 0 && $height > 0 ) {
			return array(
				'width'  => $width,
				'height' => $height,
			);
		}

		if ( $width > 0 ) {
			return array(
				'width'  => $width,
				'height' => max( 1, (int) round( $width * self::DEFAULT_HEIGHT / self::DEFAULT_WIDTH ) ),
			);
		}

		if ( $height > 0 ) {
			return array(
				'width'  => max( 1, (int) round( $height * self::DEFAULT_WIDTH / self::DEFAULT_HEIGHT ) ),
				'height' => $height,
			);
		}

		return array(
			'width'  => self::DEFAULT_WIDTH,
			'height' => self::DEFAULT_HEIGHT,
		);
	}

	/**
	 * Renders the placeholder SVG.
	 *
	 * @param string $code   Error code.
	 * @param int    $width  Requested width, 0 when unset.
	 * @param int    $height Requested height, 0 when unset.
	 * @return string SVG markup.
	 */
	public static function render( $code, $width = 0, $height = 0 ) {
		$code = self::normalize_code( $code );
		$size = self::get_size( $width, $height );

		$w = $size['width'];
		$h = $size['height'];

		$font  = max( 10, min( 28, (int) round( min( $w, $h ) / 8 ) ) );
		$label = __( 'Image error', 'sf-image-resizer' );

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d" role="img" aria-label="%3$s %4$s">'
			. '<rect width="100%%" height="100%%" fill="#f0f0f1"/>'
			. '<rect x="0.5" y="0.5" width="%5$s" height="%6$s" fill="none" stroke="#c3c4c7"/>'
			. '<text x="50%%" y="50%%" font-family="sans-serif" font-size="%7$d" fill="#646970" text-anchor="middle" dy="-0.2em">%3$s</text>'
			. '<text x="50%%" y="50%%" font-family="sans-serif" font-size="%7$d" fill="#8c8f94" text-anchor="middle" dy="1.1em">%4$s</text>'
			. '</svg>',
			$w,
			$h,
			esc_html( $label ),
			esc_html( $code ),
			esc_attr( (string) max( 1, $w - 1 ) ),
			esc_attr( (string) max( 1, $h - 1 ) ),
			$font
		);
	}

	/**
	 * Builds the URL that serves the placeholder.
	 *
	 * @param string $code   Error code.
	 * @param int    $width  Requested width, 0 when unset.
	 * @param int    $height Requested height, 0 when unset.
	 * @return string
	 */
	public static function get_url( $code, $width = 0, $height = 0 ) {
		return SFIR_Endpoint::build_url(
			array(
				'sfir_placeholder' => self::normalize_code( $code ),
				'w'                => max( 0, min( (int) $width, SFIR_MAX_DIMENSION ) ),
				'h'                => max( 0, min( (int) $height, SFIR_MAX_DIMENSION ) ),
			)
		);
	}
}
