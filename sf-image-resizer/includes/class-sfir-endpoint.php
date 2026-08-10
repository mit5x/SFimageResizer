<?php
/**
 * The on-demand generation endpoint.
 *
 * @package SFimageResizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Receives signed generation requests, produces the cache file and serves it.
 */
class SFIR_Endpoint {

	/**
	 * Path used by the pretty permalink form of the endpoint.
	 */
	const ROUTE = 'sfir-generate';

	/**
	 * Maximum number of seconds spent waiting for a concurrent generation.
	 */
	const LOCK_WAIT = 5;

	/**
	 * Registers the endpoint.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_action( 'parse_request', array( __CLASS__, 'maybe_handle' ) );
	}

	/**
	 * Declares the public query vars used by the endpoint.
	 *
	 * @param array $vars Registered query vars.
	 * @return array
	 */
	public static function register_query_vars( $vars ) {
		$vars[] = 'sfir_generate';
		$vars[] = 'sfir_placeholder';

		return $vars;
	}

	/**
	 * Adds the pretty permalink rule for the endpoint.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules() {
		add_rewrite_rule( '^' . self::ROUTE . '/?$', 'index.php?sfir_generate=1', 'top' );
	}

	/**
	 * Builds an endpoint URL.
	 *
	 * Uses the pretty permalink form when permalinks are enabled and falls back
	 * to a plain query string otherwise. The query var is present in both forms,
	 * so the endpoint keeps working even when rewrite rules were never flushed.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	public static function build_url( array $args ) {
		$structure = get_option( 'permalink_structure' );
		$base      = $structure ? home_url( '/' . self::ROUTE . '/' ) : home_url( '/' );

		// http_build_query() is used instead of add_query_arg() because the
		// latter leaves the "&" and "=" inside the packed parameter value raw.
		return $base . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Builds the signed URL that generates one cached image.
	 *
	 * @param string $source_key Source key from SFIR_Core::resolve_source().
	 * @param array  $params     Normalised parameters.
	 * @return string
	 */
	public static function build_generate_url( $source_key, array $params ) {
		$normalized = SFIR_Core::params_to_string( $params );

		return self::build_url(
			array(
				'sfir_generate' => 1,
				'src'           => $source_key,
				'p'             => $normalized,
				's'             => SFIR_Security::sign( SFIR_Core::signature_payload( $source_key, $params ) ),
			)
		);
	}

	/**
	 * Dispatches the request when it targets the endpoint.
	 *
	 * @param WP $wp Current WordPress environment instance.
	 * @return void
	 */
	public static function maybe_handle( $wp ) {
		if ( ! isset( $wp->query_vars['sfir_generate'] ) && ! isset( $wp->query_vars['sfir_placeholder'] ) ) {
			return;
		}

		if ( isset( $wp->query_vars['sfir_placeholder'] ) ) {
			self::handle_placeholder( (string) $wp->query_vars['sfir_placeholder'] );
			return;
		}

		self::handle_generate();
	}

	/**
	 * Serves a placeholder image.
	 *
	 * @param string $code   Error code.
	 * @param int    $status HTTP status code to send.
	 * @return void
	 */
	protected static function handle_placeholder( $code, $status = 200 ) {
		// The endpoint is public and unauthenticated by design; the request only
		// selects a whitelisted error code and two integers, so there is nothing
		// to protect with a nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$width  = isset( $_GET['w'] ) ? (int) $_GET['w'] : 0;
		$height = isset( $_GET['h'] ) ? (int) $_GET['h'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		self::serve_placeholder( $code, $width, $height, $status );
	}

	/**
	 * Handles a generation request.
	 *
	 * @return void
	 */
	protected static function handle_generate() {
		// Public, HMAC signed endpoint: the signature check below replaces the
		// nonce, which cannot be used for anonymous, cacheable image requests.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$source_key = isset( $_GET['src'] ) ? sanitize_text_field( wp_unslash( $_GET['src'] ) ) : '';
		$raw_params = isset( $_GET['p'] ) ? sanitize_text_field( wp_unslash( $_GET['p'] ) ) : '';
		$signature  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$params = SFIR_Core::apply_format_support( SFIR_Core::parse_params( $raw_params ) );

		if ( '' === $source_key || '' === $signature
			|| ! SFIR_Security::verify( SFIR_Core::signature_payload( $source_key, $params ), $signature )
		) {
			SFIR_Logger::log( 'E04', 'Generation request rejected: invalid or missing signature.', $source_key, $params );
			self::serve_placeholder( 'E04', $params['w'], $params['h'], 403 );
			return;
		}

		$resolved = SFIR_Core::resolve_source_key( $source_key );

		if ( empty( $resolved['ok'] ) ) {
			SFIR_Logger::log( $resolved['error'], 'Generation request could not resolve the source file.', $source_key, $params );
			self::serve_placeholder( $resolved['error'], $params['w'], $params['h'] );
			return;
		}

		$size = SFIR_Cache::get_source_size( $resolved['path'], $resolved['id'] );

		if ( empty( $size['ok'] ) ) {
			SFIR_Logger::log( $size['error'], 'Generation request received an unreadable source file.', $source_key, $params );
			self::serve_placeholder( $size['error'], $params['w'], $params['h'] );
			return;
		}

		if ( ! SFIR_Cache::prepare_directories() ) {
			SFIR_Logger::log( 'E06', 'Cache directory is not writable.', $source_key, $params );
			self::serve_placeholder( 'E06', $params['w'], $params['h'] );
			return;
		}

		$geometry     = SFIR_Core::calculate_dimensions( $size['width'], $size['height'], $params );
		$relative_dir = SFIR_Core::cache_relative_dir( $resolved );
		$filename     = SFIR_Core::build_cache_filename( basename( $resolved['relative'] ), $params );
		$cache_path   = SFIR_Cache::get_file_path( $relative_dir, $filename );

		if ( '' === $cache_path || ! self::is_inside_cache( $cache_path ) ) {
			SFIR_Logger::log( 'E06', 'Refusing to write outside the cache directory.', $source_key, $params );
			self::serve_placeholder( 'E06', $params['w'], $params['h'] );
			return;
		}

		if ( ! SFIR_Cache::make_dir( dirname( $cache_path ) ) ) {
			SFIR_Logger::log( 'E06', 'Cache sub-directory could not be created.', $source_key, $params );
			self::serve_placeholder( 'E06', $params['w'], $params['h'] );
			return;
		}

		if ( ! SFIR_Resizer::can_process( $size['width'], $size['height'], $geometry['dst_w'], $geometry['dst_h'] ) ) {
			SFIR_Logger::log( 'E07', 'Image too large to process within the memory limit.', $source_key, $params );
			self::serve_placeholder( 'E07', $params['w'], $params['h'] );
			return;
		}

		self::generate_with_lock( $cache_path, $resolved, $size, $params, $geometry, $source_key );
	}

	/**
	 * Generates the cache file under an exclusive lock and serves the result.
	 *
	 * @param string $cache_path Absolute path of the cache file.
	 * @param array  $resolved   Resolved source.
	 * @param array  $size       Source dimensions and MIME type.
	 * @param array  $params     Normalised parameters.
	 * @param array  $geometry   Output geometry.
	 * @param string $source_key Source key, for logging.
	 * @return void
	 */
	protected static function generate_with_lock( $cache_path, array $resolved, array $size, array $params, array $geometry, $source_key ) {
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
				SFIR_Logger::log( $error, 'GD failed to generate the resized copy.', $source_key, $params );
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

	/**
	 * Sends a file to the browser and stops the request.
	 *
	 * @param string $path     Absolute path of the file to send.
	 * @param string $mime     MIME type to announce.
	 * @param bool   $cacheable Whether the response may be cached for a long time.
	 * @return void
	 */
	protected static function serve_file( $path, $mime, $cacheable ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			self::serve_placeholder( 'E01', 0, 0 );
			return;
		}

		$size  = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mtime = (int) @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		self::clean_output();

		status_header( 200 );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . $size );
		header( 'X-Content-Type-Options: nosniff' );

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
