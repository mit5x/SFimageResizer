<?php
/**
 * Input parsing, geometry maths, path and URL building.
 *
 * Every method in the "pure logic" section of this class works without a
 * loaded WordPress instance so that it can be unit tested in isolation.
 *
 * @package SFimageResizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Core helper: parameters, geometry, cache names, source resolution.
 */
class SFIR_Core {

	/**
	 * Minimum accepted output quality.
	 */
	const MIN_QUALITY = 1;

	/**
	 * Maximum accepted output quality.
	 */
	const MAX_QUALITY = 95;

	/**
	 * Default output quality.
	 */
	const DEFAULT_QUALITY = 75;

	/**
	 * Source-key prefix for files inside the uploads directory.
	 */
	const ROOT_UPLOADS = 'u';

	/**
	 * Source-key prefix for files inside ABSPATH but outside uploads.
	 */
	const ROOT_ABSPATH = 'a';

	/**
	 * Cache sub-directory used to mirror files that live outside the uploads directory.
	 */
	const ABSPATH_CACHE_PREFIX = '_abs';

	// ---------------------------------------------------------------------
	// Pure logic (no WordPress dependencies).
	// ---------------------------------------------------------------------

	/**
	 * Returns the list of output formats the plugin knows about.
	 *
	 * @return string[]
	 */
	public static function get_output_formats() {
		return array( 'webp', 'jpg' );
	}

	/**
	 * Parses and normalises a parameter string.
	 *
	 * Unknown parameters are dropped. Out-of-range values are clamped, invalid
	 * values fall back to their defaults. The returned "notices" entry holds
	 * human readable messages for the caller to log; it never takes part in
	 * cache file naming or signing.
	 *
	 * @param string|array $params Query-string style parameters, or an array of them.
	 * @return array {
	 *     Normalised parameters.
	 *
	 *     @type int      $w        Requested maximum width, 0 for "unset".
	 *     @type int      $h        Requested maximum height, 0 for "unset".
	 *     @type string   $f        Output format, "webp" or "jpg".
	 *     @type int      $q        Output quality, 1-95.
	 *     @type string   $bg       Background colour as six uppercase hex digits, or an empty string.
	 *     @type int      $crop     1 when centre cropping is requested and possible, 0 otherwise.
	 *     @type string[] $notices  Messages describing values that had to be corrected.
	 * }
	 */
	public static function parse_params( $params ) {
		$raw = array();

		if ( is_array( $params ) ) {
			$raw = $params;
		} elseif ( is_string( $params ) && '' !== $params ) {
			parse_str( ltrim( $params, '?&' ), $raw );
		}

		$notices = array();

		$width  = isset( $raw['w'] ) ? (int) $raw['w'] : 0;
		$height = isset( $raw['h'] ) ? (int) $raw['h'] : 0;

		$width  = $width < 0 ? 0 : $width;
		$height = $height < 0 ? 0 : $height;

		if ( $width > SFIR_MAX_DIMENSION ) {
			$width = SFIR_MAX_DIMENSION;
		}
		if ( $height > SFIR_MAX_DIMENSION ) {
			$height = SFIR_MAX_DIMENSION;
		}

		$format = isset( $raw['f'] ) ? strtolower( trim( (string) $raw['f'] ) ) : 'webp';
		if ( 'jpeg' === $format ) {
			$format = 'jpg';
		}
		if ( ! in_array( $format, self::get_output_formats(), true ) ) {
			if ( '' !== $format && 'webp' !== $format ) {
				$notices[] = 'Unknown output format, falling back to webp.';
			}
			$format = 'webp';
		}

		$quality = isset( $raw['q'] ) ? (int) $raw['q'] : self::DEFAULT_QUALITY;
		if ( $quality < self::MIN_QUALITY ) {
			$quality = self::MIN_QUALITY;
		}
		if ( $quality > self::MAX_QUALITY ) {
			$quality = self::MAX_QUALITY;
		}

		$background = self::normalize_color( isset( $raw['bg'] ) ? $raw['bg'] : '' );

		$crop = ( isset( $raw['crop'] ) && '' !== $raw['crop'] && '0' !== (string) $raw['crop'] && false !== $raw['crop'] ) ? 1 : 0;

		// Cropping needs both sides; without them it is silently equivalent to a plain fit.
		if ( $width < 1 || $height < 1 ) {
			$crop = 0;
		}

		return array(
			'w'       => $width,
			'h'       => $height,
			'f'       => $format,
			'q'       => $quality,
			'bg'      => $background,
			'crop'    => $crop,
			'notices' => $notices,
		);
	}

	/**
	 * Normalises a hexadecimal colour.
	 *
	 * @param mixed $color Raw colour value, three or six hex digits, with or without a leading "#".
	 * @return string Six uppercase hex digits, or an empty string when the value is unusable.
	 */
	public static function normalize_color( $color ) {
		if ( ! is_string( $color ) && ! is_numeric( $color ) ) {
			return '';
		}

		$color = ltrim( trim( (string) $color ), '#' );

		if ( ! preg_match( '/^[0-9A-Fa-f]{3}$|^[0-9A-Fa-f]{6}$/', $color ) ) {
			return '';
		}

		if ( 3 === strlen( $color ) ) {
			$color = $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2];
		}

		return strtoupper( $color );
	}

	/**
	 * Renders normalised parameters back into a canonical query string.
	 *
	 * The key order is fixed so that the string can be used both for signing
	 * and for comparison.
	 *
	 * @param array $params Normalised parameters as returned by parse_params().
	 * @return string
	 */
	public static function params_to_string( array $params ) {
		return sprintf(
			'w=%d&h=%d&f=%s&q=%d&crop=%d&bg=%s',
			isset( $params['w'] ) ? (int) $params['w'] : 0,
			isset( $params['h'] ) ? (int) $params['h'] : 0,
			isset( $params['f'] ) ? $params['f'] : 'webp',
			isset( $params['q'] ) ? (int) $params['q'] : self::DEFAULT_QUALITY,
			isset( $params['crop'] ) ? (int) $params['crop'] : 0,
			isset( $params['bg'] ) ? $params['bg'] : ''
		);
	}

	/**
	 * Calculates the geometry of the resized image.
	 *
	 * The image is never enlarged: when the source is smaller than the request,
	 * the result keeps the source size (for a fit) or is cropped to the largest
	 * region matching the requested aspect ratio (for a crop).
	 *
	 * @param int   $src_w  Source width in pixels.
	 * @param int   $src_h  Source height in pixels.
	 * @param array $params Normalised parameters as returned by parse_params().
	 * @return array {
	 *     Geometry description. All values are zero when the source size is unusable.
	 *
	 *     @type int $dst_w  Output width.
	 *     @type int $dst_h  Output height.
	 *     @type int $src_x  Left offset of the region copied from the source.
	 *     @type int $src_y  Top offset of the region copied from the source.
	 *     @type int $src_w  Width of the region copied from the source.
	 *     @type int $src_h  Height of the region copied from the source.
	 * }
	 */
	public static function calculate_dimensions( $src_w, $src_h, array $params ) {
		$src_w = (int) $src_w;
		$src_h = (int) $src_h;

		$empty = array(
			'dst_w' => 0,
			'dst_h' => 0,
			'src_x' => 0,
			'src_y' => 0,
			'src_w' => 0,
			'src_h' => 0,
		);

		if ( $src_w < 1 || $src_h < 1 ) {
			return $empty;
		}

		$want_w = isset( $params['w'] ) ? (int) $params['w'] : 0;
		$want_h = isset( $params['h'] ) ? (int) $params['h'] : 0;
		$crop   = ! empty( $params['crop'] ) && $want_w > 0 && $want_h > 0;

		// No target size at all: straight copy in the original dimensions.
		if ( $want_w < 1 && $want_h < 1 ) {
			return array(
				'dst_w' => $src_w,
				'dst_h' => $src_h,
				'src_x' => 0,
				'src_y' => 0,
				'src_w' => $src_w,
				'src_h' => $src_h,
			);
		}

		if ( $crop ) {
			// Largest region of the source that matches the requested aspect ratio.
			if ( $src_w * $want_h > $src_h * $want_w ) {
				$region_h = $src_h;
				$region_w = (int) round( $src_h * $want_w / $want_h );
			} else {
				$region_w = $src_w;
				$region_h = (int) round( $src_w * $want_h / $want_w );
			}

			$region_w = min( max( 1, $region_w ), $src_w );
			$region_h = min( max( 1, $region_h ), $src_h );

			$scale = min( 1.0, $want_w / $region_w, $want_h / $region_h );

			return array(
				'dst_w' => max( 1, (int) round( $region_w * $scale ) ),
				'dst_h' => max( 1, (int) round( $region_h * $scale ) ),
				'src_x' => (int) floor( ( $src_w - $region_w ) / 2 ),
				'src_y' => (int) floor( ( $src_h - $region_h ) / 2 ),
				'src_w' => $region_w,
				'src_h' => $region_h,
			);
		}

		$scale = 1.0;
		if ( $want_w > 0 ) {
			$scale = min( $scale, $want_w / $src_w );
		}
		if ( $want_h > 0 ) {
			$scale = min( $scale, $want_h / $src_h );
		}

		return array(
			'dst_w' => max( 1, (int) round( $src_w * $scale ) ),
			'dst_h' => max( 1, (int) round( $src_h * $scale ) ),
			'src_x' => 0,
			'src_y' => 0,
			'src_w' => $src_w,
			'src_h' => $src_h,
		);
	}

	/**
	 * Builds the cache file name for a source file and a set of parameters.
	 *
	 * The name carries everything the request handler needs to rebuild the
	 * request: the sanitised source stem, the parameters and a short HMAC that
	 * proves the combination was produced by this site.
	 *
	 * @param string $source_basename Base name of the source file, with or without extension.
	 * @param array  $params          Normalised parameters as returned by parse_params().
	 * @param string $hash            Short HMAC from SFIR_Security::short_hash(), empty to omit it.
	 * @return string File name limited to the [A-Za-z0-9._-] character set.
	 */
	public static function build_cache_filename( $source_basename, array $params, $hash = '' ) {
		$name = (string) $source_basename;

		$dot = strrpos( $name, '.' );
		if ( false !== $dot && $dot > 0 ) {
			$name = substr( $name, 0, $dot );
		}

		$name = self::sanitize_name( $name );

		$suffix = sprintf(
			'-%dx%d-c%d-q%d',
			isset( $params['w'] ) ? (int) $params['w'] : 0,
			isset( $params['h'] ) ? (int) $params['h'] : 0,
			empty( $params['crop'] ) ? 0 : 1,
			isset( $params['q'] ) ? (int) $params['q'] : self::DEFAULT_QUALITY
		);

		if ( ! empty( $params['bg'] ) ) {
			$suffix .= '-bg' . $params['bg'];
		}

		if ( is_string( $hash ) && '' !== $hash ) {
			$suffix .= '-' . $hash;
		}

		$format = isset( $params['f'] ) ? $params['f'] : 'webp';
		if ( ! in_array( $format, self::get_output_formats(), true ) ) {
			$format = 'webp';
		}

		return $name . $suffix . '.' . $format;
	}

	/**
	 * Parses a cache file name back into its components.
	 *
	 * This is the exact inverse of build_cache_filename(): feeding the result
	 * back into that method reproduces the name it was given.
	 *
	 * @param string $filename Cache file name.
	 * @return array|false {
	 *     Parsed components, or false when the name is not one of ours.
	 *
	 *     @type string $stem   Sanitised stem of the source file name.
	 *     @type array  $params Normalised parameters.
	 *     @type string $hash   Short HMAC carried by the name.
	 * }
	 */
	public static function parse_cache_filename( $filename ) {
		if ( ! is_string( $filename ) || '' === $filename ) {
			return false;
		}

		$pattern = '/^(?P<stem>[A-Za-z0-9._-]+)-(?P<w>\d{1,5})x(?P<h>\d{1,5})-c(?P<crop>[01])-q(?P<q>\d{1,2})(?:-bg(?P<bg>[0-9A-F]{6}))?-(?P<hash>[a-f0-9]{'
			. SFIR_Security::HASH_LENGTH . '})\.(?P<ext>webp|jpg)$/';

		if ( ! preg_match( $pattern, $filename, $matches ) ) {
			return false;
		}

		$width  = (int) $matches['w'];
		$height = (int) $matches['h'];
		$q      = (int) $matches['q'];

		if ( $width > SFIR_MAX_DIMENSION || $height > SFIR_MAX_DIMENSION ) {
			return false;
		}

		if ( $q < self::MIN_QUALITY || $q > self::MAX_QUALITY ) {
			return false;
		}

		$crop = (int) $matches['crop'];

		// The same rule build_cache_filename() was given: cropping needs both sides.
		if ( $width < 1 || $height < 1 ) {
			if ( 0 !== $crop ) {
				return false;
			}
		}

		return array(
			'stem'   => $matches['stem'],
			'hash'   => $matches['hash'],
			'params' => array(
				'w'       => $width,
				'h'       => $height,
				'f'       => $matches['ext'],
				'q'       => $q,
				'bg'      => isset( $matches['bg'] ) ? $matches['bg'] : '',
				'crop'    => $crop,
				'notices' => array(),
			),
		);
	}

	/**
	 * Reduces an arbitrary string to a safe file name component.
	 *
	 * @param string $name Raw name.
	 * @return string Non-empty string made of [A-Za-z0-9._-] only.
	 */
	public static function sanitize_name( $name ) {
		$name = preg_replace( '/[^A-Za-z0-9._-]+/', '-', (string) $name );
		$name = preg_replace( '/-{2,}/', '-', (string) $name );
		$name = trim( (string) $name, '-.' );

		if ( '' === $name ) {
			$name = 'image';
		}

		if ( strlen( $name ) > 100 ) {
			$name = substr( $name, 0, 100 );
			$name = trim( $name, '-.' );
			if ( '' === $name ) {
				$name = 'image';
			}
		}

		return $name;
	}

	/**
	 * Normalises a relative path and rejects anything that could escape its root.
	 *
	 * Percent-encoded input is decoded once. Null bytes, backslash separators
	 * and parent-directory segments are treated as an attack and rejected.
	 *
	 * @param string $path Relative path, possibly percent-encoded.
	 * @return string|false Normalised path without leading slash, or false when unusable.
	 */
	public static function normalize_relative_path( $path ) {
		if ( ! is_string( $path ) || '' === $path ) {
			return false;
		}

		if ( false !== strpos( $path, "\0" ) ) {
			return false;
		}

		$decoded = rawurldecode( $path );

		if ( false !== strpos( $decoded, "\0" ) ) {
			return false;
		}

		$decoded = str_replace( '\\', '/', $decoded );

		$segments = explode( '/', $decoded );
		$clean    = array();

		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				return false;
			}
			$clean[] = $segment;
		}

		if ( empty( $clean ) ) {
			return false;
		}

		return implode( '/', $clean );
	}

	/**
	 * Returns the directory part of a relative path.
	 *
	 * The directory tree is mirrored verbatim inside the cache, so that the
	 * request handler can map a cache path back onto its source directory. The
	 * segments come from an already validated path, so they carry no traversal.
	 *
	 * @param string $relative_path Normalised relative path of the source file.
	 * @return string Relative directory without leading or trailing slash, may be empty.
	 */
	public static function relative_dir( $relative_path ) {
		$parts = explode( '/', (string) $relative_path );
		array_pop( $parts );

		$clean = array();
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				continue;
			}
			$clean[] = $part;
		}

		return implode( '/', $clean );
	}

	/**
	 * Splits a URL path into decoded, validated segments.
	 *
	 * Each segment is decoded on its own, so an encoded slash can never turn
	 * into a path separator, and traversal or null bytes are refused outright.
	 *
	 * @param string $path Percent-encoded path.
	 * @return array|false List of decoded segments, or false when the path is unusable.
	 */
	public static function decode_url_path( $path ) {
		if ( ! is_string( $path ) || '' === $path ) {
			return false;
		}

		$segments = array();

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment ) {
				continue;
			}

			$decoded = rawurldecode( $segment );

			if ( '' === $decoded || '.' === $decoded || '..' === $decoded ) {
				return false;
			}

			if ( false !== strpos( $decoded, "\0" ) || false !== strpos( $decoded, '/' ) || false !== strpos( $decoded, '\\' ) ) {
				return false;
			}

			$segments[] = $decoded;
		}

		return empty( $segments ) ? false : $segments;
	}

	/**
	 * Checks whether a path is located inside a directory.
	 *
	 * Both arguments are expected to be already resolved, absolute paths.
	 *
	 * @param string $path      Absolute path to test.
	 * @param string $directory Absolute directory that must contain the path.
	 * @return bool
	 */
	public static function is_path_within( $path, $directory ) {
		if ( ! is_string( $path ) || ! is_string( $directory ) || '' === $path || '' === $directory ) {
			return false;
		}

		$path      = str_replace( '\\', '/', $path );
		$directory = rtrim( str_replace( '\\', '/', $directory ), '/' );

		if ( '' === $directory ) {
			return false;
		}

		return $path === $directory || 0 === strpos( $path, $directory . '/' );
	}

	/**
	 * Builds the payload that gets signed for a generation request.
	 *
	 * @param string $source_key Source key, as produced by resolve_source().
	 * @param array  $params     Normalised parameters.
	 * @return string
	 */
	public static function signature_payload( $source_key, array $params ) {
		return self::params_to_string( $params ) . '|' . (string) $source_key;
	}

	// ---------------------------------------------------------------------
	// WordPress-aware helpers.
	// ---------------------------------------------------------------------

	/**
	 * Downgrades the requested format when the current GD build cannot produce it.
	 *
	 * @param array $params Normalised parameters.
	 * @return array Parameters with a supported format.
	 */
	public static function apply_format_support( array $params ) {
		if ( isset( $params['f'] ) && 'webp' === $params['f'] && ! SFIR_Resizer::supports_webp() ) {
			$params['f'] = 'jpg';
			SFIR_Logger::notice_once( 'webp_fallback', 'This GD build has no WebP support, falling back to JPG output.' );
		}

		return $params;
	}

	/**
	 * Resolves any supported source value into a concrete local file.
	 *
	 * @param mixed $source Image URL, attachment ID, or an ACF style array.
	 * @return array {
	 *     Resolution result.
	 *
	 *     @type bool   $ok       Whether the source could be resolved.
	 *     @type string $error    Error code (E01/E02/E03) when $ok is false.
	 *     @type string $path     Absolute path to the source file.
	 *     @type string $key      Source key used in generation URLs and signatures.
	 *     @type string $relative Path of the source relative to its root.
	 *     @type int    $id       Attachment ID when known, 0 otherwise.
	 * }
	 */
	public static function resolve_source( $source ) {
		$attachment_id = 0;

		if ( is_array( $source ) ) {
			$found = self::extract_from_array( $source );
			if ( null === $found ) {
				return self::resolve_error( 'E01' );
			}
			$source = $found;
		}

		if ( is_object( $source ) ) {
			return self::resolve_error( 'E01' );
		}

		if ( is_bool( $source ) || null === $source ) {
			return self::resolve_error( 'E01' );
		}

		if ( is_int( $source ) || ( is_string( $source ) && ctype_digit( $source ) ) ) {
			$attachment_id = (int) $source;

			if ( $attachment_id < 1 ) {
				return self::resolve_error( 'E01' );
			}

			$path = get_attached_file( $attachment_id );

			if ( ! $path || ! is_string( $path ) ) {
				return self::resolve_error( 'E01' );
			}

			return self::resolve_path( $path, $attachment_id );
		}

		if ( ! is_string( $source ) || '' === trim( $source ) ) {
			return self::resolve_error( 'E01' );
		}

		return self::resolve_url( trim( $source ) );
	}

	/**
	 * Digs an image reference out of an ACF style array.
	 *
	 * Looks at "url" first, then "ID"/"id", then recurses into nested arrays so
	 * that gallery and repeater values are handled too.
	 *
	 * @param array $value Array to inspect.
	 * @param int   $depth Current recursion depth.
	 * @return string|int|null The first usable reference, or null.
	 */
	protected static function extract_from_array( array $value, $depth = 0 ) {
		if ( $depth > 5 ) {
			return null;
		}

		if ( isset( $value['url'] ) && is_string( $value['url'] ) && '' !== trim( $value['url'] ) ) {
			return trim( $value['url'] );
		}

		foreach ( array( 'ID', 'id' ) as $key ) {
			if ( isset( $value[ $key ] ) && ( is_int( $value[ $key ] ) || ctype_digit( (string) $value[ $key ] ) ) && (int) $value[ $key ] > 0 ) {
				return (int) $value[ $key ];
			}
		}

		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				$found = self::extract_from_array( $item, $depth + 1 );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Resolves a URL belonging to this site into a local file.
	 *
	 * @param string $url Absolute, protocol relative or root relative URL.
	 * @return array Resolution result, see resolve_source().
	 */
	protected static function resolve_url( $url ) {
		if ( false !== strpos( $url, "\0" ) ) {
			return self::resolve_error( 'E01' );
		}

		// Protocol relative URLs.
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}

		$parts = wp_parse_url( $url );

		if ( false === $parts || ! is_array( $parts ) ) {
			return self::resolve_error( 'E03' );
		}

		if ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return self::resolve_error( 'E03' );
		}

		if ( isset( $parts['host'] ) && '' !== $parts['host'] && ! self::is_local_host( $parts['host'] ) ) {
			return self::resolve_error( 'E03' );
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '';

		if ( '' === $path ) {
			return self::resolve_error( 'E01' );
		}

		$uploads = wp_get_upload_dir();

		$uploads_path = self::url_path( $uploads['baseurl'] );
		$site_path    = self::url_path( site_url( '/' ) );
		$home_path    = self::url_path( home_url( '/' ) );

		// Uploads first: it can live outside the site root.
		$relative = self::strip_prefix( $path, $uploads_path );
		if ( false !== $relative ) {
			$relative = self::normalize_relative_path( $relative );
			if ( false === $relative ) {
				return self::resolve_error( 'E01' );
			}
			return self::resolve_path( trailingslashit( $uploads['basedir'] ) . $relative );
		}

		foreach ( array( $home_path, $site_path ) as $prefix ) {
			$relative = self::strip_prefix( $path, $prefix );
			if ( false !== $relative ) {
				$relative = self::normalize_relative_path( $relative );
				if ( false === $relative ) {
					return self::resolve_error( 'E01' );
				}
				return self::resolve_path( trailingslashit( ABSPATH ) . $relative );
			}
		}

		// A path that does not match any known root but has no host either.
		if ( ! isset( $parts['host'] ) || '' === $parts['host'] ) {
			$relative = self::normalize_relative_path( $path );
			if ( false === $relative ) {
				return self::resolve_error( 'E01' );
			}
			return self::resolve_path( trailingslashit( ABSPATH ) . $relative );
		}

		return self::resolve_error( 'E01' );
	}

	/**
	 * Extracts the path component of a URL, always with a leading slash.
	 *
	 * @param string $url URL to inspect.
	 * @return string
	 */
	protected static function url_path( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return '/';
		}

		return '/' . ltrim( $path, '/' );
	}

	/**
	 * Removes a directory prefix from a path.
	 *
	 * @param string $path   Path to trim.
	 * @param string $prefix Prefix to remove.
	 * @return string|false Remainder without leading slash, or false when the prefix does not match.
	 */
	protected static function strip_prefix( $path, $prefix ) {
		$prefix = rtrim( $prefix, '/' );

		if ( '' === $prefix ) {
			return ltrim( $path, '/' );
		}

		if ( 0 !== strpos( $path, $prefix . '/' ) ) {
			return false;
		}

		return substr( $path, strlen( $prefix ) + 1 );
	}

	/**
	 * Tells whether a host belongs to this site.
	 *
	 * @param string $host Host name to compare.
	 * @return bool
	 */
	protected static function is_local_host( $host ) {
		$host = strtolower( $host );

		foreach ( array( home_url( '/' ), site_url( '/' ) ) as $url ) {
			$known = wp_parse_url( $url, PHP_URL_HOST );
			if ( is_string( $known ) && strtolower( $known ) === $host ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validates an absolute path and derives its source key.
	 *
	 * @param string $path          Absolute path to the source file.
	 * @param int    $attachment_id Attachment ID when known.
	 * @return array Resolution result, see resolve_source().
	 */
	public static function resolve_path( $path, $attachment_id = 0 ) {
		if ( ! is_string( $path ) || '' === $path || false !== strpos( $path, "\0" ) ) {
			return self::resolve_error( 'E01' );
		}

		$real = realpath( $path );

		if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
			return self::resolve_error( 'E01' );
		}

		$real = str_replace( '\\', '/', $real );

		$uploads      = wp_get_upload_dir();
		$uploads_real = realpath( $uploads['basedir'] );
		$abspath_real = realpath( ABSPATH );

		if ( $uploads_real ) {
			$uploads_real = str_replace( '\\', '/', $uploads_real );
			if ( self::is_path_within( $real, $uploads_real ) ) {
				$relative = ltrim( substr( $real, strlen( rtrim( $uploads_real, '/' ) ) ), '/' );
				return self::resolve_success( $real, self::ROOT_UPLOADS, $relative, $attachment_id );
			}
		}

		if ( $abspath_real ) {
			$abspath_real = str_replace( '\\', '/', $abspath_real );
			if ( self::is_path_within( $real, $abspath_real ) ) {
				$relative = ltrim( substr( $real, strlen( rtrim( $abspath_real, '/' ) ) ), '/' );
				return self::resolve_success( $real, self::ROOT_ABSPATH, $relative, $attachment_id );
			}
		}

		return self::resolve_error( 'E01' );
	}

	/**
	 * Resolves a source key coming back from a signed generation URL.
	 *
	 * @param string $key Source key in the "<root>:<relative path>" form.
	 * @return array Resolution result, see resolve_source().
	 */
	public static function resolve_source_key( $key ) {
		if ( ! is_string( $key ) || strlen( $key ) < 3 || ':' !== substr( $key, 1, 1 ) ) {
			return self::resolve_error( 'E01' );
		}

		$root     = substr( $key, 0, 1 );
		$relative = self::normalize_relative_path( substr( $key, 2 ) );

		if ( false === $relative ) {
			return self::resolve_error( 'E01' );
		}

		if ( self::ROOT_UPLOADS === $root ) {
			$uploads = wp_get_upload_dir();
			return self::resolve_path( trailingslashit( $uploads['basedir'] ) . $relative );
		}

		if ( self::ROOT_ABSPATH === $root ) {
			return self::resolve_path( trailingslashit( ABSPATH ) . $relative );
		}

		return self::resolve_error( 'E01' );
	}

	/**
	 * Builds a successful resolution result.
	 *
	 * @param string $path          Absolute path to the source file.
	 * @param string $root          Root marker, ROOT_UPLOADS or ROOT_ABSPATH.
	 * @param string $relative      Path relative to that root.
	 * @param int    $attachment_id Attachment ID when known.
	 * @return array
	 */
	protected static function resolve_success( $path, $root, $relative, $attachment_id = 0 ) {
		return array(
			'ok'       => true,
			'error'    => '',
			'path'     => $path,
			'key'      => $root . ':' . $relative,
			'relative' => $relative,
			'root'     => $root,
			'id'       => (int) $attachment_id,
		);
	}

	/**
	 * Builds a failed resolution result.
	 *
	 * @param string $code Error code.
	 * @return array
	 */
	protected static function resolve_error( $code ) {
		return array(
			'ok'       => false,
			'error'    => $code,
			'path'     => '',
			'key'      => '',
			'relative' => '',
			'root'     => '',
			'id'       => 0,
		);
	}

	/**
	 * Returns the cache directory, relative to the cache root, that mirrors a source.
	 *
	 * @param array $resolved Resolution result from resolve_source().
	 * @return string Relative directory without leading or trailing slash.
	 */
	public static function cache_relative_dir( array $resolved ) {
		$dir = self::relative_dir( $resolved['relative'] );

		if ( self::ROOT_ABSPATH === $resolved['root'] ) {
			$dir = '' === $dir ? self::ABSPATH_CACHE_PREFIX : self::ABSPATH_CACHE_PREFIX . '/' . $dir;
		}

		return $dir;
	}

	/**
	 * Returns the public URL of a resolved source file.
	 *
	 * Used when a resized copy cannot be produced in time: the untouched
	 * original is a correct image, which a cache URL with no file behind it
	 * would not be.
	 *
	 * @param array $resolved Resolution result from resolve_source().
	 * @return string URL, or an empty string when it cannot be built.
	 */
	public static function source_url( array $resolved ) {
		if ( empty( $resolved['ok'] ) || ! isset( $resolved['relative'] ) ) {
			return '';
		}

		if ( self::ROOT_UPLOADS === $resolved['root'] ) {
			$uploads = wp_get_upload_dir();

			if ( empty( $uploads['baseurl'] ) ) {
				return '';
			}

			return trailingslashit( $uploads['baseurl'] ) . $resolved['relative'];
		}

		return trailingslashit( site_url( '/' ) ) . $resolved['relative'];
	}

	/**
	 * Converts a value into a printable, single line string for the log.
	 *
	 * @param mixed $value Value to describe.
	 * @return string
	 */
	public static function describe_source( $value ) {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		if ( is_array( $value ) ) {
			$found = self::extract_from_array( $value );
			return null === $found ? 'array' : 'array(' . $found . ')';
		}

		return gettype( $value );
	}
}
