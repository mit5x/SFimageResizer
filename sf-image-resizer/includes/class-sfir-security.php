<?php
/**
 * Request signing and file type validation.
 *
 * @package SFimageResizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Security helpers: HMAC signatures and content based type detection.
 */
class SFIR_Security {

	/**
	 * Option holding the random signing secret.
	 */
	const SECRET_OPTION = 'sfir_secret';

	/**
	 * Runtime copy of the signing secret.
	 *
	 * @var string
	 */
	protected static $secret = '';

	/**
	 * Returns the signing secret, creating it on first use.
	 *
	 * @return string
	 */
	public static function get_secret() {
		if ( '' !== self::$secret ) {
			return self::$secret;
		}

		$secret = get_option( self::SECRET_OPTION, '' );

		if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( self::SECRET_OPTION, $secret, false );
		}

		self::$secret = $secret;

		return $secret;
	}

	/**
	 * Signs a payload.
	 *
	 * @param string $payload Payload to sign.
	 * @param string $secret  Optional explicit secret, used by the unit tests.
	 * @return string Hexadecimal signature.
	 */
	public static function sign( $payload, $secret = null ) {
		if ( null === $secret ) {
			$secret = self::get_secret();
		}

		return hash_hmac( 'sha256', (string) $payload, (string) $secret );
	}

	/**
	 * Verifies a signature in constant time.
	 *
	 * @param string $payload   Payload that was signed.
	 * @param string $signature Signature received with the request.
	 * @param string $secret    Optional explicit secret, used by the unit tests.
	 * @return bool
	 */
	public static function verify( $payload, $signature, $secret = null ) {
		if ( ! is_string( $signature ) || '' === $signature ) {
			return false;
		}

		return hash_equals( self::sign( $payload, $secret ), $signature );
	}

	/**
	 * Detects the real type of a file from its content.
	 *
	 * @param string $path Absolute path to the file.
	 * @return array {
	 *     Detected type.
	 *
	 *     @type bool   $ok    Whether the file is a supported image.
	 *     @type string $error Error code when $ok is false.
	 *     @type string $mime  Detected MIME type.
	 *     @type int    $width Image width in pixels.
	 *     @type int    $height Image height in pixels.
	 * }
	 */
	public static function inspect_image( $path ) {
		$failure = array(
			'ok'     => false,
			'error'  => 'E02',
			'mime'   => '',
			'width'  => 0,
			'height' => 0,
		);

		if ( ! is_string( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			$failure['error'] = 'E01';
			return $failure;
		}

		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) || empty( $info['mime'] ) ) {
			return $failure;
		}

		$mime = strtolower( $info['mime'] );

		if ( ! in_array( $mime, self::get_supported_input_types(), true ) ) {
			return $failure;
		}

		$width  = (int) $info[0];
		$height = (int) $info[1];

		// Report the dimensions the image will have once its EXIF orientation is
		// applied, so that the announced size always matches the generated file.
		if ( SFIR_Resizer::orientation_swaps_axes( SFIR_Resizer::get_orientation( $path, $mime ) ) ) {
			$swap   = $width;
			$width  = $height;
			$height = $swap;
		}

		return array(
			'ok'     => true,
			'error'  => '',
			'mime'   => $mime,
			'width'  => $width,
			'height' => $height,
		);
	}

	/**
	 * Lists the MIME types accepted as input.
	 *
	 * SVG is deliberately absent: it can carry scripts and is never decoded.
	 *
	 * @return string[]
	 */
	public static function get_supported_input_types() {
		return array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
	}
}
