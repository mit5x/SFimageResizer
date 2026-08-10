<?php
/**
 * Public template API.
 *
 * @package SFimageResizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a source and a parameter string into everything the API functions need.
 *
 * The result is memoised per request, so calling sf_img(), sf_img_width() and
 * sf_img_height() with the same arguments costs a single resolution and a
 * single dimension lookup.
 *
 * @internal
 *
 * @param mixed        $source Image URL, attachment ID, or an ACF style array.
 * @param string|array $params Query-string style parameters.
 * @return array {
 *     Everything the template functions need.
 *
 *     @type bool   $ok     Whether the image could be prepared.
 *     @type string $error  Error code when $ok is false.
 *     @type string $url    URL of the cached file, the generation endpoint or the placeholder.
 *     @type int    $width  Width of the image the URL points at.
 *     @type int    $height Height of the image the URL points at.
 * }
 */
function sfir_prepare( $source, $params = '' ) {
	$params_key = is_array( $params ) ? wp_json_encode( $params ) : (string) $params;
	$memo_key   = md5( SFIR_Core::describe_source( $source ) . '|' . $params_key );

	$memo = SFIR_Cache::get_memo( $memo_key );

	if ( null !== $memo ) {
		return $memo;
	}

	try {
		$result = sfir_prepare_uncached( $source, $params );
	} catch ( Throwable $e ) {
		SFIR_Logger::log( 'E05', 'Unexpected failure: ' . $e->getMessage(), $source, $params );
		$result = sfir_prepare_failure( 'E05', SFIR_Core::parse_params( $params ) );
	}

	SFIR_Cache::set_memo( $memo_key, $result );

	return $result;
}

/**
 * Does the actual work behind sfir_prepare().
 *
 * @internal
 *
 * @param mixed        $source Image source.
 * @param string|array $params Requested parameters.
 * @return array See sfir_prepare().
 */
function sfir_prepare_uncached( $source, $params ) {
	$parsed = SFIR_Core::parse_params( $params );

	foreach ( $parsed['notices'] as $notice ) {
		SFIR_Logger::log( 'notice', $notice, $source, $parsed );
	}

	$parsed = SFIR_Core::apply_format_support( $parsed );

	$resolved = SFIR_Core::resolve_source( $source );

	if ( empty( $resolved['ok'] ) ) {
		SFIR_Logger::log( $resolved['error'], 'Source could not be resolved to a local image file.', $source, $parsed );
		return sfir_prepare_failure( $resolved['error'], $parsed );
	}

	$size = SFIR_Cache::get_source_size( $resolved['path'], $resolved['id'] );

	if ( empty( $size['ok'] ) ) {
		SFIR_Logger::log( $size['error'], 'Source file is not a supported image.', $source, $parsed );
		return sfir_prepare_failure( $size['error'], $parsed );
	}

	$geometry = SFIR_Core::calculate_dimensions( $size['width'], $size['height'], $parsed );

	if ( $geometry['dst_w'] < 1 || $geometry['dst_h'] < 1 ) {
		SFIR_Logger::log( 'E02', 'Source dimensions are unusable.', $source, $parsed );
		return sfir_prepare_failure( 'E02', $parsed );
	}

	$hash         = SFIR_Security::short_hash( SFIR_Core::signature_payload( $resolved['key'], $parsed ) );
	$relative_dir = SFIR_Core::cache_relative_dir( $resolved );
	$filename     = SFIR_Core::build_cache_filename( basename( $resolved['relative'] ), $parsed, $hash );
	$cache_path   = SFIR_Cache::get_file_path( $relative_dir, $filename );

	// The URL never changes: it is the cache file, whether or not it exists yet.
	// A copy older than its source is removed here, so that the next browser
	// request misses the static file and reaches the generator again.
	if ( '' !== $cache_path && file_exists( $cache_path ) && ! SFIR_Cache::is_fresh( $cache_path, $resolved['path'] ) ) {
		SFIR_Cache::delete_file( $cache_path );
	}

	return array(
		'ok'     => true,
		'error'  => '',
		'url'    => esc_url_raw( SFIR_Cache::get_file_url( $relative_dir, $filename ) ),
		'width'  => (int) $geometry['dst_w'],
		'height' => (int) $geometry['dst_h'],
	);
}

/**
 * Builds the placeholder answer used whenever an image cannot be prepared.
 *
 * @internal
 *
 * @param string $code   Error code.
 * @param array  $parsed Normalised parameters.
 * @return array See sfir_prepare().
 */
function sfir_prepare_failure( $code, array $parsed ) {
	$width  = isset( $parsed['w'] ) ? (int) $parsed['w'] : 0;
	$height = isset( $parsed['h'] ) ? (int) $parsed['h'] : 0;
	$size   = SFIR_Placeholder::get_size( $width, $height );

	return array(
		'ok'     => false,
		'error'  => $code,
		'url'    => esc_url_raw( SFIR_Placeholder::get_url( $code, $width, $height ) ),
		'width'  => (int) $size['width'],
		'height' => (int) $size['height'],
	);
}

if ( ! function_exists( 'sf_img' ) ) {
	/**
	 * Returns the URL of a resized copy of an image.
	 *
	 * When the copy is already cached, a static file URL is returned and PHP is
	 * not involved when the browser loads it. Otherwise a signed generation URL
	 * is returned, which produces and caches the file on first request.
	 *
	 * Output the result with `echo esc_url( sf_img( ... ) )`.
	 *
	 * @param mixed        $source Image URL on this site, attachment ID, or an ACF image array.
	 * @param string|array $params Parameters such as 'w=900&h=0&crop=1&q=75&f=webp&bg=FFFFFF'.
	 * @return string Image URL, or the URL of an SVG placeholder when something went wrong.
	 */
	function sf_img( $source, $params = '' ) {
		$prepared = sfir_prepare( $source, $params );

		return $prepared['url'];
	}
}

if ( ! function_exists( 'sf_img_width' ) ) {
	/**
	 * Returns the width the resized copy will have, without generating it.
	 *
	 * @param mixed        $source Image URL on this site, attachment ID, or an ACF image array.
	 * @param string|array $params Parameters such as 'w=900&h=0&crop=1'.
	 * @return int Width in pixels, or the placeholder width when the source is unusable.
	 */
	function sf_img_width( $source, $params = '' ) {
		$prepared = sfir_prepare( $source, $params );

		return $prepared['width'];
	}
}

if ( ! function_exists( 'sf_img_height' ) ) {
	/**
	 * Returns the height the resized copy will have, without generating it.
	 *
	 * @param mixed        $source Image URL on this site, attachment ID, or an ACF image array.
	 * @param string|array $params Parameters such as 'w=900&h=0&crop=1'.
	 * @return int Height in pixels, or the placeholder height when the source is unusable.
	 */
	function sf_img_height( $source, $params = '' ) {
		$prepared = sfir_prepare( $source, $params );

		return $prepared['height'];
	}
}

if ( ! function_exists( 'sf_img_tag' ) ) {
	/**
	 * Returns a complete, escaped <img> tag.
	 *
	 * `loading="lazy"` and `decoding="async"` are applied by default and can be
	 * overridden, like every other attribute, through $attrs.
	 *
	 * @param mixed        $source Image URL on this site, attachment ID, or an ACF image array.
	 * @param string|array $params Parameters such as 'w=900&h=0&crop=1'.
	 * @param array        $attrs  Extra attributes, for example array( 'alt' => '...', 'class' => '...' ).
	 * @return string
	 */
	function sf_img_tag( $source, $params = '', $attrs = array() ) {
		$prepared = sfir_prepare( $source, $params );

		if ( ! is_array( $attrs ) ) {
			$attrs = array();
		}

		$attributes = array_merge(
			array(
				'src'      => $prepared['url'],
				'width'    => $prepared['width'],
				'height'   => $prepared['height'],
				'alt'      => '',
				'loading'  => 'lazy',
				'decoding' => 'async',
			),
			$attrs
		);

		$html = '<img';

		foreach ( $attributes as $name => $value ) {
			if ( false === $value || null === $value ) {
				continue;
			}

			$name = preg_replace( '/[^A-Za-z0-9:_-]/', '', (string) $name );

			if ( '' === $name ) {
				continue;
			}

			if ( true === $value ) {
				$html .= ' ' . $name;
				continue;
			}

			if ( 'src' === $name ) {
				$html .= ' src="' . esc_url( (string) $value ) . '"';
				continue;
			}

			$html .= ' ' . $name . '="' . esc_attr( (string) $value ) . '"';
		}

		return $html . ' />';
	}
}
