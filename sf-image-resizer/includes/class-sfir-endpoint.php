<?php
/**
 * Request handling: on-demand generation behind the cache URL.
 *
 * @package SFimageResizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves cache files that do not exist yet.
 *
 * A cached copy is a plain static file, so the web server answers it without
 * ever reaching PHP. When the file is missing, the standard WordPress rewrite
 * sends the request to index.php, this class recognises the cache URL, proves
 * it was signed by this site, generates the file and returns it.
 */
class SFIR_Endpoint {

	/**
	 * Maximum number of seconds spent waiting for a concurrent generation.
	 */
	const LOCK_WAIT = 5;

	/**
	 * Maximum number of directory entries scanned when looking for a source.
	 */
	const MAX_SCAN_ENTRIES = 20000;

	/**
	 * Registers the request handlers.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'init', array( __CLASS__, 'maybe_handle_cache_request' ), 0 );
		add_action( 'parse_request', array( __CLASS__, 'maybe_handle_placeholder' ) );
	}

	/**
	 * Declares the public query var used by the placeholder route.
	 *
	 * @param array $vars Registered query vars.
	 * @return array
	 */
	public static function register_query_vars( $vars ) {
		$vars[] = 'sfir_placeholder';

		return $vars;
	}

	/**
	 * Builds the URL of the placeholder route.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	public static function build_url( array $args ) {
		return home_url( '/' ) . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	// ---------------------------------------------------------------------
	// The cache route.
	// ---------------------------------------------------------------------

	/**
	 * Handles a request that points at a cache file which does not exist.
	 *
	 * Anything that is not addressed to the cache directory leaves this method
	 * after a single string comparison.
	 *
	 * @return void
	 */
	public static function maybe_handle_cache_request() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$prefix = self::get_cache_url_path();

		if ( '' === $prefix ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The raw path is split and decoded segment by segment below.
		$request = wp_unslash( $_SERVER['REQUEST_URI'] );
		$path    = wp_parse_url( $request, PHP_URL_PATH );

		if ( ! is_string( $path ) || 0 !== strpos( $path, $prefix ) ) {
			return;
		}

		self::handle_cache_request( substr( $path, strlen( $prefix ) ) );
	}

	/**
	 * Returns the path component of the cache URL, with a trailing slash.
	 *
	 * @return string
	 */
	public static function get_cache_url_path() {
		$url = SFIR_Cache::get_cache_url();

		if ( '' === $url ) {
			return '';
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		return rtrim( $path, '/' ) . '/';
	}

	/**
	 * Resolves, generates and serves one cache file.
	 *
	 * @param string $relative_url Requested path relative to the cache root, still percent-encoded.
	 * @return void
	 */
	protected static function handle_cache_request( $relative_url ) {
		$segments = SFIR_Core::decode_url_path( $relative_url );

		if ( false === $segments ) {
			SFIR_Logger::log( 'E01', 'Cache request with an unusable path.', $relative_url, '' );
			self::serve_placeholder( 'E01', 0, 0, 404 );
			return;
		}

		$filename = array_pop( $segments );
		$parsed   = SFIR_Core::parse_cache_filename( $filename );

		if ( false === $parsed ) {
			SFIR_Logger::log( 'E04', 'Cache request with a malformed file name.', $filename, '' );
			self::serve_placeholder( 'E04', 0, 0, 404 );
			return;
		}

		$params = $parsed['params'];

		$match = self::find_source( $segments, $parsed['stem'], $params, $parsed['hash'] );

		if ( '' !== $match['error'] ) {
			SFIR_Logger::log(
				$match['error'],
				'E04' === $match['error']
					? 'Cache request rejected: the file name carries an invalid signature.'
					: 'Cache request could not find a source file.',
				implode( '/', $segments ) . '/' . $filename,
				$params
			);
			self::serve_placeholder( $match['error'], $params['w'], $params['h'], 'E04' === $match['error'] ? 403 : 404 );
			return;
		}

		$resolved = $match['resolved'];

		$size = SFIR_Cache::get_source_size( $resolved['path'], $resolved['id'] );

		if ( empty( $size['ok'] ) ) {
			SFIR_Logger::log( $size['error'], 'Cache request received an unreadable source file.', $resolved['path'], $params );
			self::serve_placeholder( $size['error'], $params['w'], $params['h'] );
			return;
		}

		if ( ! SFIR_Cache::prepare_directories() ) {
			SFIR_Logger::log( 'E06', 'Cache directory is not writable.', $resolved['path'], $params );
			self::serve_placeholder( 'E06', $params['w'], $params['h'] );
			return;
		}

		$cache_path = SFIR_Cache::get_file_path( implode( '/', $segments ), $filename );

		if ( '' === $cache_path || ! self::is_inside_cache( $cache_path ) ) {
			SFIR_Logger::log( 'E06', 'Refusing to write outside the cache directory.', $cache_path, $params );
			self::serve_placeholder( 'E06', $params['w'], $params['h'] );
			return;
		}

		if ( ! SFIR_Cache::make_dir( dirname( $cache_path ) ) ) {
			SFIR_Logger::log( 'E06', 'Cache sub-directory could not be created.', $cache_path, $params );
			self::serve_placeholder( 'E06', $params['w'], $params['h'] );
			return;
		}

		$geometry = SFIR_Core::calculate_dimensions( $size['width'], $size['height'], $params );

		if ( ! SFIR_Resizer::can_process( $size['width'], $size['height'], $geometry['dst_w'], $geometry['dst_h'] ) ) {
			SFIR_Logger::log( 'E07', 'Image too large to process within the memory limit.', $resolved['path'], $params );
			self::serve_placeholder( 'E07', $params['w'], $params['h'] );
			return;
		}

		self::generate_with_lock( $cache_path, $resolved, $size, $params, $geometry );
	}

	/**
	 * Finds the source file a cache file name refers to.
	 *
	 * The name only carries a sanitised stem, so the extension, and sometimes
	 * the exact spelling, have to be recovered from the mirrored source
	 * directory. The short HMAC decides which candidate is the right one, which
	 * also means an unsigned name never resolves to anything.
	 *
	 * @param array  $segments Decoded directory segments below the cache root.
	 * @param string $stem     Sanitised stem taken from the file name.
	 * @param array  $params   Normalised parameters taken from the file name.
	 * @param string $hash     Short HMAC taken from the file name.
	 * @return array {
	 *     Lookup result.
	 *
	 *     @type string $error    Empty on success, E04 for a bad signature, E01 when nothing was found.
	 *     @type array  $resolved Resolved source, see SFIR_Core::resolve_source().
	 * }
	 */
	protected static function find_source( array $segments, $stem, array $params, $hash ) {
		$uploads  = wp_get_upload_dir();
		$searched = false;

		foreach ( self::get_source_roots( $segments, $uploads ) as $root ) {
			$directory = rtrim( $root['base'], '/' );

			if ( '' !== $root['dir'] ) {
				$directory .= '/' . $root['dir'];
			}

			if ( ! is_dir( $directory ) ) {
				continue;
			}

			foreach ( self::find_candidates( $directory, $stem ) as $candidate ) {
				$searched = true;

				$relative = '' === $root['dir'] ? $candidate : $root['dir'] . '/' . $candidate;
				$key      = $root['root'] . ':' . $relative;

				if ( ! SFIR_Security::verify_short_hash( SFIR_Core::signature_payload( $key, $params ), $hash ) ) {
					continue;
				}

				$resolved = SFIR_Core::resolve_path( $directory . '/' . $candidate );

				if ( empty( $resolved['ok'] ) || $resolved['key'] !== $key ) {
					continue;
				}

				return array(
					'error'    => '',
					'resolved' => $resolved,
				);
			}
		}

		return array(
			'error'    => $searched ? 'E04' : 'E01',
			'resolved' => array(),
		);
	}

	/**
	 * Lists the roots a cache directory can be mirroring.
	 *
	 * @param array $segments Decoded directory segments below the cache root.
	 * @param array $uploads  Result of wp_get_upload_dir().
	 * @return array
	 */
	protected static function get_source_roots( array $segments, array $uploads ) {
		$roots = array();

		if ( ! empty( $uploads['basedir'] ) ) {
			$roots[] = array(
				'root' => SFIR_Core::ROOT_UPLOADS,
				'base' => $uploads['basedir'],
				'dir'  => implode( '/', $segments ),
			);
		}

		if ( isset( $segments[0] ) && SFIR_Core::ABSPATH_CACHE_PREFIX === $segments[0] ) {
			$roots[] = array(
				'root' => SFIR_Core::ROOT_ABSPATH,
				'base' => ABSPATH,
				'dir'  => implode( '/', array_slice( $segments, 1 ) ),
			);
		}

		return $roots;
	}

	/**
	 * Lists the files of a directory whose sanitised stem matches.
	 *
	 * The usual extensions are probed first, so the common case costs a handful
	 * of stat calls; the directory is only walked when none of them matches.
	 *
	 * @param string $directory Absolute path of the directory to look in.
	 * @param string $stem      Sanitised stem to match.
	 * @return string[] Candidate file names.
	 */
	protected static function find_candidates( $directory, $stem ) {
		$candidates = array();

		foreach ( array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'JPG', 'JPEG', 'PNG', 'GIF', 'WEBP' ) as $extension ) {
			$name = $stem . '.' . $extension;

			if ( is_file( $directory . '/' . $name ) ) {
				$candidates[] = $name;
			}
		}

		if ( ! empty( $candidates ) ) {
			return $candidates;
		}

		// The stem lost characters on the way in: look for anything that
		// sanitises down to the same string.
		$entries = @scandir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $entries ) ) {
			return $candidates;
		}

		$scanned = 0;

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			++$scanned;

			if ( $scanned > self::MAX_SCAN_ENTRIES ) {
				break;
			}

			if ( ! is_file( $directory . '/' . $entry ) ) {
				continue;
			}

			$dot  = strrpos( $entry, '.' );
			$base = ( false !== $dot && $dot > 0 ) ? substr( $entry, 0, $dot ) : $entry;

			if ( SFIR_Core::sanitize_name( $base ) === $stem ) {
				$candidates[] = $entry;
			}
		}

		return $candidates;
	}

	/**
	 * Generates the cache file under an exclusive lock and serves the result.
	 *
	 * @param string $cache_path Absolute path of the cache file.
	 * @param array  $resolved   Resolved source.
	 * @param array  $size       Source dimensions and MIME type.
	 * @param array  $params     Normalised parameters.
	 * @param array  $geometry   Output geometry.
	 * @return void
	 */
	protected static function generate_with_lock( $cache_path, array $resolved, array $size, array $params, array $geometry ) {
		$lock_path = $cache_path . '.lock';

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = @fopen( $lock_path, 'c' );

		if ( $handle && flock( $handle, LOCK_EX | LOCK_NB ) ) {
			clearstatcache( true, $cache_path );

			$error = '';

			if ( ! SFIR_Cache::is_fresh( $cache_path, $resolved['path'] ) ) {
				$error = SFIR_Resizer::generate( $resolved['path'], $size['mime'], $cache_path, $params, $geometry );
			}

			flock( $handle, LOCK_UN );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $lock_path );

			if ( '' !== $error ) {
				SFIR_Logger::log( $error, 'GD failed to generate the resized copy.', $resolved['path'], $params );
				self::serve_placeholder( $error, $params['w'], $params['h'] );
				return;
			}

			self::serve_file( $cache_path, self::mime_for_format( $params['f'] ), true );
			return;
		}

		if ( $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		// Another request is already generating this file: wait briefly for it.
		$deadline = microtime( true ) + self::LOCK_WAIT;

		while ( microtime( true ) < $deadline ) {
			usleep( 150000 );
			clearstatcache( true, $cache_path );

			if ( SFIR_Cache::is_fresh( $cache_path, $resolved['path'] ) ) {
				self::serve_file( $cache_path, self::mime_for_format( $params['f'] ), true );
				return;
			}
		}

		// Still not ready: serve the untouched original rather than break the page.
		self::serve_file( $resolved['path'], $size['mime'], false );
	}

	/**
	 * Tells whether a path stays inside the cache directory.
	 *
	 * @param string $path Absolute path, the file itself need not exist yet.
	 * @return bool
	 */
	protected static function is_inside_cache( $path ) {
		$root = SFIR_Cache::get_cache_dir();

		if ( '' === $root ) {
			return false;
		}

		$root_real = realpath( $root );

		if ( false === $root_real ) {
			return false;
		}

		$root_real = str_replace( '\\', '/', $root_real );

		$dir_real = realpath( dirname( $path ) );

		if ( false !== $dir_real ) {
			return SFIR_Core::is_path_within( str_replace( '\\', '/', $dir_real ) . '/' . basename( $path ), $root_real );
		}

		// The sub-directory does not exist yet: check the normalised path instead.
		return SFIR_Core::is_path_within( str_replace( '\\', '/', $path ), $root_real );
	}

	/**
	 * Maps an output format onto its MIME type.
	 *
	 * @param string $format Output format.
	 * @return string
	 */
	protected static function mime_for_format( $format ) {
		return 'webp' === $format ? 'image/webp' : 'image/jpeg';
	}

	// ---------------------------------------------------------------------
	// The placeholder route.
	// ---------------------------------------------------------------------

	/**
	 * Serves a placeholder when the request asks for one.
	 *
	 * @param WP $wp Current WordPress environment instance.
	 * @return void
	 */
	public static function maybe_handle_placeholder( $wp ) {
		if ( ! isset( $wp->query_vars['sfir_placeholder'] ) ) {
			return;
		}

		// The placeholder route is public and unauthenticated by design; it only
		// selects a whitelisted error code and two integers, so there is nothing
		// for a nonce to protect.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$width  = isset( $_GET['w'] ) ? (int) $_GET['w'] : 0;
		$height = isset( $_GET['h'] ) ? (int) $_GET['h'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		self::serve_placeholder( (string) $wp->query_vars['sfir_placeholder'], $width, $height );
	}

	// ---------------------------------------------------------------------
	// Responses.
	// ---------------------------------------------------------------------

	/**
	 * Sends a file to the browser and stops the request.
	 *
	 * @param string $path      Absolute path of the file to send.
	 * @param string $mime      MIME type to announce.
	 * @param bool   $cacheable Whether the response may be cached for a long time.
	 * @return void
	 */
	protected static function serve_file( $path, $mime, $cacheable ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			self::serve_placeholder( 'E01', 0, 0, 404 );
			return;
		}

		$size  = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mtime = (int) @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		self::clean_output();

		status_header( 200 );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . $size );
		header( 'X-Content-Type-Options: nosniff' );

		// Marks the responses this plugin produced. A cached file is answered by
		// the web server itself and never carries this header, which is what the
		// admin self-check and the test suite look at.
		header( 'X-SFIR: generated' );

		if ( $mtime ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
		}

		if ( $cacheable ) {
			header( 'Cache-Control: public, max-age=31536000, immutable' );
		} else {
			header( 'Cache-Control: no-store, must-revalidate' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );

		exit;
	}

	/**
	 * Sends a placeholder SVG and stops the request.
	 *
	 * @param string $code   Error code.
	 * @param int    $width  Requested width.
	 * @param int    $height Requested height.
	 * @param int    $status HTTP status code.
	 * @return void
	 */
	protected static function serve_placeholder( $code, $width = 0, $height = 0, $status = 200 ) {
		$svg = SFIR_Placeholder::render( $code, $width, $height );

		self::clean_output();

		status_header( (int) $status );
		header( 'Content-Type: image/svg+xml; charset=utf-8' );
		header( 'Content-Length: ' . strlen( $svg ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-SFIR: placeholder' );
		header( 'Cache-Control: no-store, must-revalidate' );

		echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by SFIR_Placeholder::render(), which escapes every dynamic part.

		exit;
	}

	/**
	 * Discards anything that was buffered before the image response.
	 *
	 * @return void
	 */
	protected static function clean_output() {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}
}
