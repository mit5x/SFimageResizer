<?php
/**
 * Configuration self-check.
 *
 * @package SFimageResizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verifies that a request for a missing cache file reaches this plugin.
 *
 * Cache URLs point straight at the file. When the file is not there yet, the
 * web server has to fall through to WordPress; that is the default everywhere,
 * but a few hosts answer their own 404 instead. This class finds out which of
 * the two happens on the current server.
 */
class SFIR_Diagnostics {

	/**
	 * Relative path, inside the plugin directory in uploads, of the probe image.
	 */
	const PROBE_PATH = 'selftest/probe.png';

	/**
	 * How long a result stays valid.
	 */
	const TTL = 43200;

	/**
	 * Transient holding the outcome of the check.
	 */
	const TRANSIENT = 'sfir_self_test';

	/**
	 * Returns the stored result, running the check when there is none.
	 *
	 * @return array See run().
	 */
	public static function get_cached() {
		$cached = get_transient( self::TRANSIENT );

		if ( is_array( $cached ) && isset( $cached['ok'], $cached['message'], $cached['url'] ) ) {
			return $cached;
		}

		return self::run();
	}

	/**
	 * Runs the check and stores the result.
	 *
	 * @return array {
	 *     Outcome of the check.
	 *
	 *     @type bool   $ok      Whether missing cache files reach the plugin.
	 *     @type string $message Human readable explanation.
	 *     @type string $url     The URL that was requested.
	 * }
	 */
	public static function run() {
		$result = self::probe();

		set_transient( self::TRANSIENT, $result, self::TTL );

		return $result;
	}

	/**
	 * Performs the actual request.
	 *
	 * @return array See run().
	 */
	protected static function probe() {
		$source = self::ensure_probe_image();

		if ( '' === $source ) {
			return self::result( false, __( 'The check could not create its test image in the uploads directory.', 'sf-image-resizer' ), '' );
		}

		// A width nothing else would ask for, so the file is certain to be missing.
		$params = SFIR_Core::apply_format_support( SFIR_Core::parse_params( 'w=' . wp_rand( 3000, 4999 ) . '&q=61' ) );

		$resolved = SFIR_Core::resolve_path( $source );

		if ( empty( $resolved['ok'] ) ) {
			return self::result( false, __( 'The check could not read back its own test image.', 'sf-image-resizer' ), '' );
		}

		$hash         = SFIR_Security::short_hash( SFIR_Core::signature_payload( $resolved['key'], $params ) );
		$relative_dir = SFIR_Core::cache_relative_dir( $resolved );
		$filename     = SFIR_Core::build_cache_filename( basename( $resolved['relative'] ), $params, $hash );
		$url          = SFIR_Cache::get_file_url( $relative_dir, $filename );
		$path         = SFIR_Cache::get_file_path( $relative_dir, $filename );

		clearstatcache( true, $path );

		if ( file_exists( $path ) ) {
			SFIR_Cache::delete_file( $path );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 15,
				'sslverify' => false,
				'headers'   => array( 'Cache-Control' => 'no-cache' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::result(
				false,
				sprintf(
					/* translators: %s: error message returned by the HTTP request. */
					__( 'The site could not call itself over HTTP: %s. This often means loopback requests are blocked, which does not prove that pretty URLs are broken.', 'sf-image-resizer' ),
					$response->get_error_message()
				),
				$url
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$type   = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$marker = (string) wp_remote_retrieve_header( $response, 'x-sfir' );

		// The file was written by the process that answered the request above,
		// so this one still has the "missing" result in its stat cache.
		clearstatcache( true, $path );

		$created = file_exists( $path );

		if ( $created ) {
			SFIR_Cache::delete_file( $path );
		}

		if ( 200 === $status && 'generated' === $marker && 0 === strpos( $type, 'image/' ) && $created ) {
			return self::result( true, __( 'A missing cache file was requested and the plugin generated it.', 'sf-image-resizer' ), $url );
		}

		if ( 404 === $status ) {
			return self::result( false, __( 'The server answered 404 for a missing cache file instead of passing the request to WordPress.', 'sf-image-resizer' ), $url );
		}

		if ( 0 === strpos( $type, 'text/html' ) ) {
			return self::result( false, __( 'The server answered with an HTML page instead of an image, so the request never reached the plugin.', 'sf-image-resizer' ), $url );
		}

		return self::result(
			false,
			sprintf(
				/* translators: 1: HTTP status code, 2: content type of the response. */
				__( 'Unexpected answer for a missing cache file: HTTP %1$d, content type %2$s.', 'sf-image-resizer' ),
				$status,
				'' === $type ? '-' : $type
			),
			$url
		);
	}

	/**
	 * Creates the small PNG the check resizes, if it is not there yet.
	 *
	 * @return string Absolute path, or an empty string on failure.
	 */
	protected static function ensure_probe_image() {
		$base = SFIR_Cache::get_base_dir();

		if ( '' === $base ) {
			return '';
		}

		$path = $base . '/' . self::PROBE_PATH;

		if ( is_file( $path ) ) {
			return $path;
		}

		if ( ! SFIR_Cache::make_dir( dirname( $path ) ) || ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagepng' ) ) {
			return '';
		}

		$image = imagecreatetruecolor( 64, 64 );

		if ( ! $image ) {
			return '';
		}

		imagefilledrectangle( $image, 0, 0, 63, 63, imagecolorallocate( $image, 120, 140, 160 ) );
		$written = @imagepng( $image, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		imagedestroy( $image );

		return $written ? $path : '';
	}

	/**
	 * Shapes a result array.
	 *
	 * @param bool   $ok      Whether the check succeeded.
	 * @param string $message Explanation.
	 * @param string $url     URL that was requested.
	 * @return array
	 */
	protected static function result( $ok, $message, $url ) {
		return array(
			'ok'      => (bool) $ok,
			'message' => $message,
			'url'     => $url,
		);
	}
}
