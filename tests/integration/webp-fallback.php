<?php
/**
 * WebP fallback test (spec 11.2.12).
 *
 * Must be executed by a PHP process where imagewebp() is unavailable:
 *   php -d disable_functions=imagewebp wp-cli.phar eval-file tests/integration/webp-fallback.php
 *
 * @package SFimageResizer
 */

require_once __DIR__ . '/lib.php';

define( 'SFIR_TEST_BASE_URL', rtrim( home_url(), '/' ) );

SFIR_TestRunner::group( '11.2.12 webp fallback' );

SFIR_TestRunner::check( ! function_exists( 'imagewebp' ), 'imagewebp() is unavailable in this process' );
SFIR_TestRunner::check( ! SFIR_Resizer::supports_webp(), 'the plugin reports no WebP support' );

// Start from a clean slate: no notice recorded yet, empty log.
delete_option( SFIR_Logger::NOTICE_OPTION );
sfir_reset_log();
SFIR_Cache::flush_runtime_cache();

$fixtures = sfir_build_fixtures();
$jpeg_url = sfir_path_to_url( $fixtures['jpeg'] );

$url = sf_img( $jpeg_url, 'w=250&f=webp' );

SFIR_TestRunner::check(
	false !== strpos( $url, 'f%3Djpg' ) || false !== strpos( $url, '.jpg' ),
	'the requested webp output is downgraded to jpg in the URL',
	$url
);

$params = SFIR_Core::apply_format_support( SFIR_Core::parse_params( 'w=250&f=webp' ) );

SFIR_TestRunner::equals( 'jpg', $params['f'], 'apply_format_support() rewrites the format' );

$filename = SFIR_Core::build_cache_filename( 'landscape.jpg', $params );

SFIR_TestRunner::equals( 'landscape-250x0-c0-q75.jpg', $filename, 'the cache file gets a .jpg extension' );

$resolved = SFIR_Core::resolve_source( $jpeg_url );
$size     = SFIR_Cache::get_source_size( $resolved['path'], $resolved['id'] );
$geometry = SFIR_Core::calculate_dimensions( $size['width'], $size['height'], $params );

$destination = SFIR_Cache::get_cache_dir() . '/' . $filename;

$error = SFIR_Resizer::generate( $resolved['path'], $size['mime'], $destination, $params, $geometry );

SFIR_TestRunner::equals( '', $error, 'the resizer produces a file without WebP support' );

$info = sfir_image_info( (string) file_get_contents( $destination ) );

SFIR_TestRunner::check(
	is_array( $info ) && 'image/jpeg' === $info['mime'] && 250 === $info['width'],
	'the produced file is a 250px wide JPEG',
	wp_json_encode( $info )
);

// Ask for more WebP output: the notice must not be repeated.
SFIR_Cache::flush_runtime_cache();
sf_img( $jpeg_url, 'w=300&f=webp' );
SFIR_Cache::flush_runtime_cache();
sf_img( $jpeg_url, 'w=350&f=webp' );
SFIR_Core::apply_format_support( SFIR_Core::parse_params( 'f=webp' ) );

$log     = sfir_read_log();
$notices = 0;

foreach ( explode( "\n", $log ) as $line ) {
	if ( false !== stripos( $line, 'WebP support' ) ) {
		++$notices;
	}
}

SFIR_TestRunner::equals( 1, $notices, 'exactly one WebP notice was written to the log', $log );

// Leave the installation as it was found.
if ( file_exists( $destination ) ) {
	unlink( $destination );
}

delete_option( SFIR_Logger::NOTICE_OPTION );
sfir_reset_log();

exit( SFIR_TestRunner::summary() );
