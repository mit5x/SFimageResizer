<?php
/**
 * Configuration self-check.
 *
 * @package SFimageResizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds out whether requests for missing cache files reach this plugin.
 *
 * The question is only ever answered by the network path real visitors use, so
 * the check works on three levels, in descending order of certainty:
 *
 * 1. Whenever the request handler actually generates and serves a copy, it
 *    records that fact. Nothing beats having done the real thing.
 * 2. On a fresh installation the plugin screen asks the administrator's own
 *    browser to fetch a signed, deliberately missing cache URL.
 * 3. If neither happened yet, the screen says so plainly instead of claiming
 *    that something is broken.
 *
 * A server side loopback request is deliberately not used: bot protection,
 * split horizon DNS and CDNs make a site calling itself prove nothing about
 * what a browser experiences.
 */
class SFIR_Diagnostics {

	/**
	 * Option holding the timestamp of the last confirmation.
	 */
	const CONFIRMED_OPTION = 'sfir_pretty_urls_confirmed';

	/**
	 * Nonce action and name of the confirmation request.
	 */
	const AJAX_ACTION = 'sfir_confirm_pretty_urls';

	/**
	 * Relative path, inside the plugin directory in uploads, of the probe image.
	 */
	const PROBE_PATH = 'selftest/probe.png';

	/**
	 * Shortest interval between two writes of the confirmation option.
	 */
	const CONFIRM_INTERVAL = DAY_IN_SECONDS;

	/**
	 * Registers the confirmation endpoint.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_ajax' ) );
	}

	/**
	 * Returns the moment pretty URLs were last confirmed.
	 *
	 * @return int Unix timestamp, or 0 when there is no confirmation yet.
	 */
	public static function get_confirmed_at() {
		return (int) get_option( self::CONFIRMED_OPTION, 0 );
	}

	/**
	 * Records that a cache file was really generated and served.
	 *
	 * Called from the request handler, so the write is throttled to once a day:
	 * the fact does not become truer by being stored on every image.
	 *
	 * @param bool $force Write even when the stored value is still recent.
	 * @return void
	 */
	public static function confirm( $force = false ) {
		$now  = time();
		$last = self::get_confirmed_at();

		if ( ! $force && $last > 0 && ( $now - $last ) < self::CONFIRM_INTERVAL ) {
			return;
		}

		update_option( self::CONFIRMED_OPTION, $now, false );
	}

	/**
	 * Forgets the confirmation, so the browser check runs again.
	 *
	 * @return void
	 */
	public static function reset() {
		delete_option( self::CONFIRMED_OPTION );
	}

	/**
	 * Builds a signed cache URL that is certain not to exist yet.
	 *
	 * @return string URL, or an empty string when the probe image is unavailable.
	 */
	public static function get_probe_url() {
		$source = self::ensure_probe_image();

		if ( '' === $source ) {
			return '';
		}

		$resolved = SFIR_Core::resolve_path( $source );

		if ( empty( $resolved['ok'] ) ) {
			return '';
		}

		// A different width every time, so the cache always misses.
		$params = SFIR_Core::apply_format_support( SFIR_Core::parse_params( 'w=' . wp_rand( 1000, 4999 ) . '&q=61' ) );

		$hash         = SFIR_Security::short_hash( SFIR_Core::signature_payload( $resolved['key'], $params ) );
		$relative_dir = SFIR_Core::cache_relative_dir( $resolved );
		$filename     = SFIR_Core::build_cache_filename( basename( $resolved['relative'] ), $params, $hash );

		return SFIR_Cache::get_file_url( $relative_dir, $filename );
	}

	/**
	 * Handles the confirmation sent by the browser check.
	 *
	 * @return void
	 */
	public static function handle_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'sf-image-resizer' ) ), 403 );
		}

		check_ajax_referer( self::AJAX_ACTION );

		self::confirm( true );
		self::cleanup_probe_files();

		wp_send_json_success(
			array(
				'message' => __( 'Pretty URLs are working — checked from your browser just now.', 'sf-image-resizer' ),
			)
		);
	}

	/**
	 * Returns the directory holding the cache files of the browser check.
	 *
	 * @return string Absolute path without trailing slash, empty on failure.
	 */
	public static function get_probe_cache_dir() {
		$root = SFIR_Cache::get_cache_dir();

		if ( '' === $root ) {
			return '';
		}

		return $root . '/' . SFIR_Cache::BASE_DIRNAME . '/' . dirname( self::PROBE_PATH );
	}

	/**
	 * Removes the cache files the browser check produced.
	 *
	 * @return int Number of deleted files.
	 */
	public static function cleanup_probe_files() {
		$directory = self::get_probe_cache_dir();

		if ( '' === $directory || ! is_dir( $directory ) ) {
			return 0;
		}

		$entries = @scandir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $entries ) ) {
			return 0;
		}

		$deleted = 0;

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || 'index.php' === $entry || '.htaccess' === $entry ) {
				continue;
			}

			if ( SFIR_Cache::delete_file( $directory . '/' . $entry ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Creates the small PNG the check resizes, if it is not there yet.
	 *
	 * @return string Absolute path, or an empty string on failure.
	 */
	public static function ensure_probe_image() {
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

		$image = imagecreatetruecolor( 16, 16 );

		if ( ! $image ) {
			return '';
		}

		imagefilledrectangle( $image, 0, 0, 15, 15, imagecolorallocate( $image, 120, 140, 160 ) );
		$written = @imagepng( $image, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		imagedestroy( $image );

		if ( ! $written ) {
			return '';
		}

		@chmod( $path, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		return $path;
	}
}
