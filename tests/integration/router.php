<?php
/**
 * Router for the PHP built-in web server.
 *
 * The integration suite needs a server that can serve both the static files of
 * a WordPress installation and its pretty permalinks. Start it with:
 *
 *   PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:8899 -t /path/to/wordpress tests/integration/router.php
 *
 * More than one worker is required: the admin self-check asks the site for one
 * of its own URLs, and a single worker server would deadlock on that request.
 *
 * @package SFimageResizer
 */

$sfir_path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$sfir_root = rtrim( $_SERVER['DOCUMENT_ROOT'], '/' );
$sfir_file = $sfir_root . $sfir_path;

// Existing static files are served by the server itself.
if ( '/' !== $sfir_path && file_exists( $sfir_file ) && ! is_dir( $sfir_file ) ) {
	return false;
}

// Directories with their own index.php, such as /wp-admin/.
if ( is_dir( $sfir_file ) && file_exists( rtrim( $sfir_file, '/' ) . '/index.php' ) ) {
	$_SERVER['SCRIPT_NAME'] = rtrim( $sfir_path, '/' ) . '/index.php';
	require rtrim( $sfir_file, '/' ) . '/index.php';
	return true;
}

// Everything else goes through the WordPress front controller.
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $sfir_root . '/index.php';
