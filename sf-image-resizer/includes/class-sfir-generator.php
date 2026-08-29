<?php
/**
 * When resized copies are produced.
 *
 * @package SFimageResizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a copy is produced while the page renders, and produces it.
 *
 * The plugin was built around producing a copy the first time a browser asks
 * for it: the URL points straight at the cache file, and while that file does
 * not exist the web server has to hand the request to WordPress. Some hosts
 * cannot be configured to do that — nginx without the fallback rule answers
 * such a request with 404 and never reaches PHP, so the image simply never
 * appears.
 *
 * Producing the copy while the template renders removes that dependency
 * entirely: by the time the markup reaches the browser the file is already on
 * disk, so a plain static request finds it. The cost is a slower first render
 * of each page, which is why it is bounded by a budget and why the default is
 * to do it only while the request path has not been shown to work.
 */
class SFIR_Generator {

	/**
	 * Option holding the chosen mode.
	 */
	const MODE_OPTION = 'sfir_generate_mode';

	/**
	 * Produce copies while rendering only until the request path is confirmed.
	 */
	const MODE_AUTO = 'auto';

	/**
	 * Always produce copies while rendering.
	 */
	const MODE_ALWAYS = 'always';

	/**
	 * Never produce copies while rendering; rely on the request path.
	 */
	const MODE_NEVER = 'never';

	/**
	 * Files produced during this request.
	 *
	 * @var int
	 */
	protected static $files = 0;

	/**
	 * Seconds spent producing them.
	 *
	 * @var float
	 */
	protected static $seconds = 0.0;

	/**
	 * Memoised answer of is_eager(), which every image asks for.
	 *
	 * @var bool|null
	 */
	protected static $eager = null;

	/**
	 * Returns the modes and their labels.
	 *
	 * @return array<string,string>
	 */
	public static function get_modes() {
		return array(
			self::MODE_AUTO   => __( 'Automatic', 'sf-image-resizer' ),
			self::MODE_ALWAYS => __( 'Generate every size up front, while the page is built', 'sf-image-resizer' ),
			self::MODE_NEVER  => __( 'Generate each size when a visitor\'s browser asks for it', 'sf-image-resizer' ),
		);
	}

	/**
	 * Returns the mode in force.
	 *
	 * @return string One of the MODE_* constants.
	 */
	public static function get_mode() {
		$stored = get_option( self::MODE_OPTION, self::MODE_AUTO );

		return isset( self::get_modes()[ $stored ] ) ? $stored : self::MODE_AUTO;
	}

	/**
	 * Stores the mode.
	 *
	 * @param string $mode One of the MODE_* constants.
	 * @return void
	 */
	public static function set_mode( $mode ) {
		if ( ! isset( self::get_modes()[ $mode ] ) ) {
			$mode = self::MODE_AUTO;
		}

		update_option( self::MODE_OPTION, $mode, true );

		self::$eager = null;
	}

	/**
	 * Tells whether copies are produced while the page renders.
	 *
	 * In the automatic mode the answer is yes until the configuration check has
	 * confirmed that requests for missing cache files really reach the plugin.
	 * That confirmation only ever comes from the network path a real browser
	 * uses, so it cannot be fooled by a server that answers itself.
	 *
	 * @return bool
	 */
	public static function is_eager() {
		if ( null !== self::$eager ) {
			return self::$eager;
		}

		$mode = self::get_mode();

		if ( self::MODE_ALWAYS === $mode ) {
			self::$eager = true;
		} elseif ( self::MODE_NEVER === $mode ) {
			self::$eager = false;
		} else {
			self::$eager = SFIR_Diagnostics::get_confirmed_at() < 1;
		}

		return self::$eager;
	}

	/**
	 * Returns how much work one request may do.
	 *
	 * A page holding many images, each offered at six widths, could otherwise
	 * ask for more work than PHP is allowed to finish. The budget keeps the
	 * first render slow rather than fatal; whatever is left over is produced by
	 * the next render, so a page converges after a few visits.
	 *
	 * @return array {
	 *     @type int   $files   Maximum number of files.
	 *     @type float $seconds Maximum time to spend on them.
	 * }
	 */
	public static function get_budget() {
		$budget = array(
			'files'   => 40,
			'seconds' => 8.0,
		);

		$limit = (int) ini_get( 'max_execution_time' );

		// Leave the rest of the request comfortably inside the time PHP allows.
		if ( $limit > 0 ) {
			$budget['seconds'] = min( $budget['seconds'], max( 2.0, $limit * 0.5 ) );
		}

		/**
		 * Filters how much rendering time one request may spend on images.
		 *
		 * @param array $budget Files and seconds, see get_budget().
		 */
		$budget = apply_filters( 'sfir_generation_budget', $budget );

		return array(
			'files'   => max( 0, (int) $budget['files'] ),
			'seconds' => max( 0.0, (float) $budget['seconds'] ),
		);
	}

	/**
	 * Tells whether this request may still produce a file.
	 *
	 * @return bool
	 */
	public static function has_budget() {
		$budget = self::get_budget();

		return self::$files < $budget['files'] && self::$seconds < $budget['seconds'];
	}

	/**
	 * Forgets what this request has produced so far.
	 *
	 * Only useful to long running processes and to the test suite, which
	 * simulate many page renders inside one PHP process.
	 *
	 * @return void
	 */
	public static function reset_budget() {
		self::$files   = 0;
		self::$seconds = 0.0;
		self::$eager   = null;
	}

	/**
	 * Returns what this request has produced so far.
	 *
	 * @return array {
	 *     @type int   $files   Files produced.
	 *     @type float $seconds Time spent on them.
	 * }
	 */
	public static function get_spent() {
		return array(
			'files'   => self::$files,
			'seconds' => self::$seconds,
		);
	}

	/**
	 * Makes sure a resized copy exists on disk, producing it if it does not.
	 *
	 * @param array  $resolved   Resolved source, see SFIR_Core::resolve_source().
	 * @param array  $size       Source size, see SFIR_Cache::get_source_size().
	 * @param array  $params     Normalised parameters.
	 * @param array  $geometry   Target geometry, see SFIR_Core::calculate_dimensions().
	 * @param string $cache_path Absolute path the copy belongs at.
	 * @return bool Whether the file is now there.
	 */
	public static function ensure( array $resolved, array $size, array $params, array $geometry, $cache_path ) {
		if ( '' === $cache_path ) {
			return false;
		}

		clearstatcache( true, $cache_path );

		if ( SFIR_Cache::is_fresh( $cache_path, $resolved['path'] ) ) {
			return true;
		}

		if ( ! self::has_budget() ) {
			return false;
		}

		if ( ! SFIR_Cache::prepare_directories() ) {
			SFIR_Logger::log( 'E06', 'Cache directory is not writable.', $resolved['path'], $params );
			return false;
		}

		if ( ! SFIR_Cache::is_inside_cache( $cache_path ) ) {
			SFIR_Logger::log( 'E06', 'Refusing to write outside the cache directory.', $cache_path, $params );
			return false;
		}

		if ( ! SFIR_Cache::make_dir( dirname( $cache_path ) ) ) {
			SFIR_Logger::log( 'E06', 'Cache sub-directory could not be created.', $cache_path, $params );
			return false;
		}

		if ( ! SFIR_Resizer::can_process( $size['width'], $size['height'], $geometry['dst_w'], $geometry['dst_h'] ) ) {
			SFIR_Logger::log( 'E07', 'Image too large to process within the memory limit.', $resolved['path'], $params );
			return false;
		}

		$started = microtime( true );

		// No waiting: another request holding the lock will finish on its own,
		// and this render has a page to deliver.
		$result = self::generate_locked( $cache_path, $resolved, $size, $params, $geometry, 0 );

		self::$seconds += microtime( true ) - $started;

		if ( 'generated' === $result['status'] ) {
			++self::$files;
			return true;
		}

		if ( 'ready' === $result['status'] ) {
			return true;
		}

		if ( 'error' === $result['status'] ) {
			SFIR_Logger::log( $result['error'], 'GD failed to generate the resized copy while rendering.', $resolved['path'], $params );
		}

		return false;
	}

	/**
	 * Produces one copy, holding an exclusive lock while doing so.
	 *
	 * Two requests asking for the same missing copy at the same time must not
	 * both write the file. The one that gets the lock produces it; the other
	 * either waits for it or is told that the file is busy.
	 *
	 * @param string $cache_path Absolute path of the copy.
	 * @param array  $resolved   Resolved source.
	 * @param array  $size       Source size.
	 * @param array  $params     Normalised parameters.
	 * @param array  $geometry   Target geometry.
	 * @param float  $wait       Seconds to wait for another request to finish.
	 * @return array {
	 *     @type string $status One of "generated", "ready", "busy" and "error".
	 *     @type string $error  Error code when the status is "error".
	 * }
	 */
	public static function generate_locked( $cache_path, array $resolved, array $size, array $params, array $geometry, $wait = 0 ) {
		$lock_path = $cache_path . '.lock';

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = @fopen( $lock_path, 'c' );

		if ( $handle && flock( $handle, LOCK_EX | LOCK_NB ) ) {
			clearstatcache( true, $cache_path );

			$error  = '';
			$status = 'ready';

			if ( ! SFIR_Cache::is_fresh( $cache_path, $resolved['path'] ) ) {
				$error  = SFIR_Resizer::generate( $resolved['path'], $size['mime'], $cache_path, $params, $geometry );
				$status = 'generated';
			}

			flock( $handle, LOCK_UN );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $lock_path );

			if ( '' !== $error ) {
				return array(
					'status' => 'error',
					'error'  => $error,
				);
			}

			return array(
				'status' => $status,
				'error'  => '',
			);
		}

		if ( $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		$deadline = microtime( true ) + (float) $wait;

		while ( microtime( true ) < $deadline ) {
			usleep( 150000 );
			clearstatcache( true, $cache_path );

			if ( SFIR_Cache::is_fresh( $cache_path, $resolved['path'] ) ) {
				return array(
					'status' => 'ready',
					'error'  => '',
				);
			}
		}

		return array(
			'status' => 'busy',
			'error'  => '',
		);
	}
}
