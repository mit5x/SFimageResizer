<?php
/**
 * Configuration self-check.
 *
 * @package SFimageResizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds out which of the two ways of producing a copy works on this server.
 *
 * There are two independent questions, and a server can answer them
 * differently, so each one is tested on its own and reported on its own:
 *
 * 1. **On request.** Does a request for a cache file that does not exist yet
 *    reach the plugin? Apache does this through the .htaccess the plugin
 *    writes; nginx needs a rule in its configuration and answers 404 without
 *    one. Tested by asking the browser to load a signed, deliberately missing
 *    cache URL. The request handler also records the answer whenever it really
 *    generates and serves a copy, which is the strongest evidence there is.
 * 2. **While rendering.** Can the plugin produce a copy during a page render,
 *    and does the web server then serve that file? Tested by producing one
 *    here and asking the browser to load it. This never depends on the server
 *    passing anything to WordPress, which is why it works where the first one
 *    does not.
 *
 * A site that answers "no" to the first and "yes" to the second is not broken:
 * it is exactly the case the render-time mode exists for.
 *
 * Both questions are put to the administrator's own browser. A server side
 * loopback request is deliberately not used: bot protection, split horizon DNS
 * and CDNs make a site calling itself prove nothing about what a browser
 * experiences.
 */
class SFIR_Diagnostics {

	/**
	 * Option holding the timestamp of the last "on request" confirmation.
	 */
	const CONFIRMED_OPTION = 'sfir_pretty_urls_confirmed';

	/**
	 * Option holding the timestamp of the last "while rendering" confirmation.
	 */
	const RENDER_OPTION = 'sfir_render_confirmed';

	/**
	 * Name of the check that asks whether missing files reach the plugin.
	 */
	const CHECK_REQUEST = 'request';

	/**
	 * Name of the check that asks whether a rendered copy is served.
	 */
	const CHECK_RENDER = 'render';

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
	 * Returns the moment a rendered copy was last confirmed to be served.
	 *
	 * @return int Unix timestamp, or 0 when there is no confirmation yet.
	 */
	public static function get_render_confirmed_at() {
		return (int) get_option( self::RENDER_OPTION, 0 );
	}

	/**
	 * Records that a copy produced while rendering was served to a browser.
	 *
	 * @return void
	 */
	public static function confirm_render() {
		update_option( self::RENDER_OPTION, time(), false );
	}

	/**
	 * Records the answer of one of the two checks.
	 *
	 * @param string $which Either CHECK_REQUEST or CHECK_RENDER.
	 * @return bool Whether the name was recognised.
	 */
	public static function confirm_check( $which ) {
		if ( self::CHECK_RENDER === $which ) {
			self::confirm_render();
			return true;
		}

		if ( self::CHECK_REQUEST === $which ) {
			self::confirm( true );
			return true;
		}

		return false;
	}

	/**
	 * Returns what is known about both ways of producing a copy.
	 *
	 * @return array {
	 *     @type int $request Timestamp of the last "on request" confirmation, 0 if none.
	 *     @type int $render  Timestamp of the last "while rendering" confirmation, 0 if none.
	 * }
	 */
	public static function get_results() {
		return array(
			self::CHECK_REQUEST => self::get_confirmed_at(),
			self::CHECK_RENDER  => self::get_render_confirmed_at(),
		);
	}

	/**
	 * Forgets both confirmations, so the browser checks run again.
	 *
	 * @return void
	 */
	public static function reset() {
		delete_option( self::CONFIRMED_OPTION );
		delete_option( self::RENDER_OPTION );
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
	 * Builds a cache URL whose file this method has just produced.
	 *
	 * This is what the render-time mode does on every page, so loading the
	 * result proves that the mode works on this server. Nothing is asked of the
	 * web server beyond serving a file that exists.
	 *
	 * @return string URL, or an empty string when the copy could not be made.
	 */
	public static function get_render_probe_url() {
		$source = self::ensure_probe_image();

		if ( '' === $source ) {
			return '';
		}

		$resolved = SFIR_Core::resolve_path( $source );

		if ( empty( $resolved['ok'] ) ) {
			return '';
		}

		// A different width every time, so a copy left over from an earlier
		// check can never be mistaken for one produced just now. The range has
		// to stay under SFIR_MAX_DIMENSION, or every width would be clamped to
		// the same number and the file name would stop changing. The quality
		// differs from the one the other probe uses, so the two can never end
		// up asking for the same file.
		$params = SFIR_Core::apply_format_support( SFIR_Core::parse_params( 'w=' . wp_rand( 3000, 4999 ) . '&q=62' ) );

		$size = SFIR_Cache::get_source_size( $resolved['path'], $resolved['id'] );

		if ( empty( $size['ok'] ) || ! SFIR_Cache::prepare_directories() ) {
			return '';
		}

		$hash         = SFIR_Security::short_hash( SFIR_Core::signature_payload( $resolved['key'], $params ) );
		$relative_dir = SFIR_Core::cache_relative_dir( $resolved );
		$filename     = SFIR_Core::build_cache_filename( basename( $resolved['relative'] ), $params, $hash );
		$cache_path   = SFIR_Cache::get_file_path( $relative_dir, $filename );

		if ( '' === $cache_path || ! SFIR_Cache::is_inside_cache( $cache_path ) || ! SFIR_Cache::make_dir( dirname( $cache_path ) ) ) {
			return '';
		}

		$geometry = SFIR_Core::calculate_dimensions( $size['width'], $size['height'], $params );

		// Deliberately outside the rendering budget: this is one small file,
		// asked for by an administrator looking at the screen.
		$result = SFIR_Generator::generate_locked( $cache_path, $resolved, $size, $params, $geometry, 0 );

		clearstatcache( true, $cache_path );

		if ( ! in_array( $result['status'], array( 'generated', 'ready' ), true ) || ! is_file( $cache_path ) ) {
			return '';
		}

		return SFIR_Cache::get_file_url( $relative_dir, $filename );
	}

	/**
	 * Handles a confirmation sent by one of the browser checks.
	 *
	 * @return void
	 */
	public static function handle_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'sf-image-resizer' ) ), 403 );
		}

		check_ajax_referer( self::AJAX_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer() above verified it.
		$which = isset( $_POST['which'] ) ? sanitize_key( wp_unslash( $_POST['which'] ) ) : self::CHECK_REQUEST;

		if ( ! self::confirm_check( $which ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown check.', 'sf-image-resizer' ) ), 400 );
		}

		wp_send_json_success( array( 'which' => $which ) );
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
