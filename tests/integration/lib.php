<?php
/**
 * Shared helpers for the WordPress integration tests.
 *
 * @package SFimageResizer
 */

/**
 * Collects the outcome of every check.
 */
class SFIR_TestRunner {

	/**
	 * Number of checks that passed.
	 *
	 * @var int
	 */
	public static $passed = 0;

	/**
	 * Number of checks that failed.
	 *
	 * @var int
	 */
	public static $failed = 0;

	/**
	 * Name of the group currently being executed.
	 *
	 * @var string
	 */
	public static $group = '';

	/**
	 * Starts a new group of checks.
	 *
	 * @param string $name Group name.
	 * @return void
	 */
	public static function group( $name ) {
		self::$group = $name;
		echo "\n## " . $name . "\n";
	}

	/**
	 * Records the outcome of one check.
	 *
	 * @param bool   $condition Whether the check passed.
	 * @param string $name      Check description.
	 * @param string $detail    Extra information printed on failure.
	 * @return bool
	 */
	public static function check( $condition, $name, $detail = '' ) {
		if ( $condition ) {
			++self::$passed;
			echo 'PASS | ' . self::$group . ' | ' . $name . "\n";
			return true;
		}

		++self::$failed;
		echo 'FAIL | ' . self::$group . ' | ' . $name . ( '' === $detail ? '' : ' -- ' . $detail ) . "\n";
		return false;
	}

	/**
	 * Records an equality check.
	 *
	 * @param mixed  $expected Expected value.
	 * @param mixed  $actual   Actual value.
	 * @param string $name     Check description.
	 * @return bool
	 */
	public static function equals( $expected, $actual, $name ) {
		return self::check(
			$expected === $actual,
			$name,
			'expected ' . self::describe( $expected ) . ', got ' . self::describe( $actual )
		);
	}

	/**
	 * Renders a value for a failure message.
	 *
	 * @param mixed $value Value to render.
	 * @return string
	 */
	public static function describe( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_scalar( $value ) || null === $value ) {
			return var_export( $value, true );
		}

		return wp_json_encode( $value );
	}

	/**
	 * Prints the summary line and returns the process exit code.
	 *
	 * @return int
	 */
	public static function summary() {
		echo "\nSUMMARY passed=" . self::$passed . ' failed=' . self::$failed . "\n";

		return self::$failed > 0 ? 1 : 0;
	}
}

/**
 * Performs an HTTP request without going through any configured proxy.
 *
 * @param string $url     Absolute URL.
 * @param array  $options Extra cURL options.
 * @return array {
 *     Response description.
 *
 *     @type int    $status  HTTP status code.
 *     @type string $body    Response body.
 *     @type array  $headers Lower-cased response headers.
 * }
 */
function sfir_http( $url, $options = array() ) {
	$handle = curl_init();

	curl_setopt_array(
		$handle,
		array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_PROXY          => '',
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_FOLLOWLOCATION => false,
		) + $options
	);

	$raw    = curl_exec( $handle );
	$status = (int) curl_getinfo( $handle, CURLINFO_HTTP_CODE );
	$split  = (int) curl_getinfo( $handle, CURLINFO_HEADER_SIZE );
	curl_close( $handle );

	if ( false === $raw ) {
		return array(
			'status'  => 0,
			'body'    => '',
			'headers' => array(),
		);
	}

	$headers = array();

	foreach ( explode( "\n", substr( $raw, 0, $split ) ) as $line ) {
		$parts = explode( ':', $line, 2 );
		if ( 2 === count( $parts ) ) {
			$headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
		}
	}

	return array(
		'status'  => $status,
		'body'    => substr( $raw, $split ),
		'headers' => $headers,
	);
}

/**
 * Rewrites a URL produced by the plugin so that it points at the test server.
 *
 * @param string $url URL produced by the plugin.
 * @return string
 */
function sfir_test_url( $url ) {
	return str_replace( rtrim( home_url(), '/' ), SFIR_TEST_BASE_URL, html_entity_decode( $url, ENT_QUOTES ) );
}

/**
 * Fetches a URL produced by the plugin, then drops everything the plugin
 * memoised for this PHP process.
 *
 * A normal page load is a fresh process, so the memo is empty; the test suite
 * runs many "renders" inside one process and has to reset it by hand.
 *
 * @param string $url URL produced by the plugin.
 * @return array See sfir_http().
 */
function sfir_fetch( $url ) {
	$response = sfir_http( sfir_test_url( $url ) );

	clearstatcache();
	SFIR_Cache::flush_runtime_cache();

	return $response;
}

/**
 * Fetches two URLs at the same time.
 *
 * @param string[] $urls URLs to request in parallel.
 * @return array List of array( status, body ) in the same order.
 */
function sfir_http_parallel( array $urls ) {
	$multi   = curl_multi_init();
	$handles = array();

	foreach ( $urls as $index => $url ) {
		$handle = curl_init();
		curl_setopt_array(
			$handle,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_PROXY          => '',
				CURLOPT_TIMEOUT        => 60,
			)
		);
		curl_multi_add_handle( $multi, $handle );
		$handles[ $index ] = $handle;
	}

	$running = null;
	do {
		curl_multi_exec( $multi, $running );
		curl_multi_select( $multi, 0.1 );
	} while ( $running > 0 );

	$results = array();

	foreach ( $handles as $index => $handle ) {
		$results[ $index ] = array(
			'status' => (int) curl_getinfo( $handle, CURLINFO_HTTP_CODE ),
			'body'   => (string) curl_multi_getcontent( $handle ),
		);
		curl_multi_remove_handle( $multi, $handle );
		curl_close( $handle );
	}

	curl_multi_close( $multi );

	return $results;
}

/**
 * Returns the pixel dimensions and MIME type of an image held in a string.
 *
 * @param string $data Raw image bytes.
 * @return array|false
 */
function sfir_image_info( $data ) {
	if ( '' === $data ) {
		return false;
	}

	$info = @getimagesizefromstring( $data );

	if ( ! is_array( $info ) ) {
		return false;
	}

	return array(
		'width'  => (int) $info[0],
		'height' => (int) $info[1],
		'mime'   => isset( $info['mime'] ) ? $info['mime'] : '',
	);
}

/**
 * Reads the colour of one pixel of an image held in a string.
 *
 * @param string $data Raw image bytes.
 * @param int    $x    Horizontal position.
 * @param int    $y    Vertical position.
 * @return array|false array( r, g, b, alpha ) or false.
 */
function sfir_pixel( $data, $x, $y ) {
	$image = @imagecreatefromstring( $data );

	if ( ! $image ) {
		return false;
	}

	$index = imagecolorat( $image, $x, $y );
	$rgba  = imagecolorsforindex( $image, $index );
	imagedestroy( $image );

	return array(
		(int) $rgba['red'],
		(int) $rgba['green'],
		(int) $rgba['blue'],
		(int) $rgba['alpha'],
	);
}

/**
 * Turns a URL inside the uploads directory back into an absolute path.
 *
 * @param string $url Absolute URL.
 * @return string
 */
function sfir_url_to_path( $url ) {
	$uploads = wp_get_upload_dir();
	$url     = html_entity_decode( $url, ENT_QUOTES );
	$path    = str_replace( $uploads['baseurl'], $uploads['basedir'], $url );

	return rawurldecode( (string) wp_parse_url( $path, PHP_URL_PATH ) );
}

/**
 * Replaces the six character hash of a cache URL with a different one.
 *
 * @param string $url Cache URL.
 * @return string
 */
function sfir_corrupt_hash( $url ) {
	return preg_replace_callback(
		'/-([a-f0-9]{6})(\.(?:webp|jpg))$/',
		function ( $matches ) {
			$hash = $matches[1];
			$hash[0] = ( '0' === $hash[0] ) ? '1' : '0';
			return '-' . $hash . $matches[2];
		},
		$url
	);
}

/**
 * Drops the option cache of this PHP process.
 *
 * A page load is a fresh process, so it always sees what other requests wrote.
 * The suite runs many "page loads" inside one process, where WordPress would
 * otherwise keep serving the values, and the misses, it cached earlier.
 *
 * @return void
 */
function sfir_forget_options() {
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( 'alloptions', 'options' );

	foreach ( func_get_args() as $option ) {
		wp_cache_delete( $option, 'options' );
	}
}

/**
 * Drops the cached user meta of a user.
 *
 * The web server writes the meta, this process reads it; without dropping the
 * cache the value this process read earlier would be returned again.
 *
 * @param int $user_id User to forget.
 * @return void
 */
function sfir_forget_user_meta( $user_id ) {
	wp_cache_delete( (int) $user_id, 'user_meta' );
}

/**
 * Reads the URLs of the configuration-check images out of the screen.
 *
 * They live in the markup rather than in the localized script, because the
 * check is settled by the browser loading the images themselves.
 *
 * @param string $html Page markup.
 * @return array<string,string> Check name to image URL.
 */
function sfir_probe_urls( $html ) {
	$found = array();

	if ( ! preg_match_all( '#data-check="([a-z]+)"(.*?)</div>\s*</div>\s*</div>#s', $html, $rows, PREG_SET_ORDER ) ) {
		return $found;
	}

	foreach ( $rows as $row ) {
		if ( preg_match( '#<img class="sfir-probe" src="([^"]+)"#', $row[2], $image ) ) {
			$found[ $row[1] ] = html_entity_decode( $image[1], ENT_QUOTES, 'UTF-8' );
		}
	}

	return $found;
}

/**
 * Reads the settings wp_localize_script() printed for the admin script.
 *
 * @param string $html Page markup.
 * @return array
 */
function sfir_admin_settings( $html ) {
	if ( ! preg_match( '#var sfirAdmin = (\{.*?\});#s', $html, $matches ) ) {
		return array();
	}

	$decoded = json_decode( $matches[1], true );

	return is_array( $decoded ) ? $decoded : array();
}

/**
 * Returns the six character hash carried by a cache URL.
 *
 * @param string $url Cache URL.
 * @return string
 */
function sfir_hash_of( $url ) {
	return preg_match( '/-([a-f0-9]{6})\.(?:webp|jpg)$/', $url, $matches ) ? '-' . $matches[1] : '';
}

/**
 * Recursively lists the files of a directory.
 *
 * @param string $dir Directory to walk.
 * @return string[]
 */
function sfir_list_files( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return array();
	}

	$found = array();

	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $iterator as $file ) {
		if ( $file->isFile() ) {
			$found[] = str_replace( '\\', '/', $file->getPathname() );
		}
	}

	sort( $found );

	return $found;
}

/**
 * Lists the cached images, ignoring the directory protection files.
 *
 * @return string[]
 */
function sfir_cached_files() {
	$files = array();

	foreach ( sfir_list_files( SFIR_Cache::get_cache_dir() ) as $file ) {
		$name = basename( $file );

		if ( 'index.php' === $name || '.htaccess' === $name ) {
			continue;
		}

		$files[] = $file;
	}

	return $files;
}

/**
 * Builds the fixture images used by the integration tests.
 *
 * @return array Map of fixture name to absolute path.
 */
function sfir_build_fixtures() {
	$uploads = wp_get_upload_dir();
	$dir     = $uploads['basedir'] . '/sfir-fixtures/2026/08';

	wp_mkdir_p( $dir );

	$files = array();

	// JPEG 2000x1000, four coloured quadrants so crops are recognisable.
	$jpeg = imagecreatetruecolor( 2000, 1000 );
	imagefilledrectangle( $jpeg, 0, 0, 999, 499, imagecolorallocate( $jpeg, 200, 0, 0 ) );
	imagefilledrectangle( $jpeg, 1000, 0, 1999, 499, imagecolorallocate( $jpeg, 0, 200, 0 ) );
	imagefilledrectangle( $jpeg, 0, 500, 999, 999, imagecolorallocate( $jpeg, 0, 0, 200 ) );
	imagefilledrectangle( $jpeg, 1000, 500, 1999, 999, imagecolorallocate( $jpeg, 200, 200, 0 ) );
	imagejpeg( $jpeg, $dir . '/landscape.jpg', 92 );
	imagedestroy( $jpeg );
	$files['jpeg'] = $dir . '/landscape.jpg';

	// Small JPEG 400x300 for the "never upscale" checks.
	$small = imagecreatetruecolor( 400, 300 );
	imagefilledrectangle( $small, 0, 0, 399, 299, imagecolorallocate( $small, 30, 90, 150 ) );
	imagejpeg( $small, $dir . '/small.jpg', 92 );
	imagedestroy( $small );
	$files['small'] = $dir . '/small.jpg';

	// PNG 800x800, fully transparent except for an opaque centre square.
	$png = imagecreatetruecolor( 800, 800 );
	imagealphablending( $png, false );
	imagesavealpha( $png, true );
	imagefilledrectangle( $png, 0, 0, 799, 799, imagecolorallocatealpha( $png, 0, 0, 0, 127 ) );
	imagefilledrectangle( $png, 200, 200, 599, 599, imagecolorallocatealpha( $png, 0, 128, 255, 0 ) );
	imagepng( $png, $dir . '/transparent.png' );
	imagedestroy( $png );
	$files['png'] = $dir . '/transparent.png';

	// Animated GIF 300x200: two frames, the first one red, the second one green.
	$files['gif'] = $dir . '/animated.gif';
	sfir_write_animated_gif( $files['gif'] );

	// WebP 1200x600.
	if ( function_exists( 'imagewebp' ) ) {
		$webp = imagecreatetruecolor( 1200, 600 );
		imagefilledrectangle( $webp, 0, 0, 1199, 599, imagecolorallocate( $webp, 120, 40, 160 ) );
		imagewebp( $webp, $dir . '/photo.webp', 90 );
		imagedestroy( $webp );
		$files['webp'] = $dir . '/photo.webp';
	}

	// A text file wearing a .jpg extension.
	file_put_contents( $dir . '/broken.jpg', "this is not an image, not even a little bit\n" );
	$files['broken'] = $dir . '/broken.jpg';

	// A plain text file.
	file_put_contents( $dir . '/notes.txt', "just text\n" );
	$files['text'] = $dir . '/notes.txt';

	return $files;
}

/**
 * Writes a two frame animated GIF.
 *
 * GD cannot author animations, so the frames are assembled by hand from two
 * single frame GIFs produced by GD.
 *
 * @param string $path Destination path.
 * @return void
 */
function sfir_write_animated_gif( $path ) {
	$frames = array();

	foreach ( array( array( 220, 20, 20 ), array( 20, 200, 20 ) ) as $color ) {
		$image = imagecreate( 300, 200 );
		imagecolorallocate( $image, $color[0], $color[1], $color[2] );
		ob_start();
		imagegif( $image );
		$frames[] = ob_get_clean();
		imagedestroy( $image );
	}

	// Header and global colour table of the first frame.
	$first  = $frames[0];
	$gct    = 3 * pow( 2, ( ord( $first[10] ) & 0x07 ) + 1 );
	$header = substr( $first, 0, 13 + $gct );

	// Netscape looping extension.
	$output = $header . "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";

	foreach ( $frames as $index => $frame ) {
		$local_gct = 3 * pow( 2, ( ord( $frame[10] ) & 0x07 ) + 1 );
		$body      = substr( $frame, 13 + $local_gct, -1 );

		// Graphic control extension: 50/100s per frame.
		$output .= "\x21\xF9\x04\x00\x32\x00\x00\x00";

		// Re-attach the frame's own colour table to its image descriptor.
		if ( 0 === strpos( $body, "\x2C" ) ) {
			$descriptor = substr( $body, 0, 10 );
			$rest       = substr( $body, 10 );
			$flags      = ord( $descriptor[9] ) | 0x80 | ( ( ord( $frame[10] ) & 0x07 ) );
			$descriptor = substr( $descriptor, 0, 9 ) . chr( $flags );
			$body       = $descriptor . substr( $frame, 13, $local_gct ) . $rest;
		}

		$output .= $body;

		unset( $index );
	}

	$output .= "\x3B";

	file_put_contents( $path, $output );
}

/**
 * Registers a file as an attachment in the media library.
 *
 * @param string $path Absolute path to an existing file.
 * @return int Attachment ID.
 */
function sfir_attach( $path ) {
	$filetype = wp_check_filetype( basename( $path ), null );

	$attachment_id = wp_insert_attachment(
		array(
			'guid'           => wp_get_upload_dir()['baseurl'] . '/' . basename( $path ),
			'post_mime_type' => $filetype['type'],
			'post_title'     => sanitize_file_name( basename( $path ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		),
		$path
	);

	require_once ABSPATH . 'wp-admin/includes/image.php';

	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $path ) );

	return (int) $attachment_id;
}

/**
 * Turns an absolute uploads path into its public URL.
 *
 * @param string $path Absolute path inside the uploads directory.
 * @return string
 */
function sfir_path_to_url( $path ) {
	$uploads = wp_get_upload_dir();

	return str_replace( $uploads['basedir'], $uploads['baseurl'], $path );
}

/**
 * Empties the plugin log file.
 *
 * @return void
 */
function sfir_reset_log() {
	$file = SFIR_Logger::get_log_file();

	if ( '' !== $file ) {
		file_put_contents( $file, '' );
	}
}

/**
 * Returns the whole log file.
 *
 * @return string
 */
function sfir_read_log() {
	$file = SFIR_Logger::get_log_file();

	return ( '' !== $file && is_readable( $file ) ) ? (string) file_get_contents( $file ) : '';
}
