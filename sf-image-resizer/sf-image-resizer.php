<?php
/**
 * Plugin Name:       SFimageResizer
 * Plugin URI:        https://web-format.net
 * Description:       On-demand image resizing, cropping and WebP/JPG conversion for theme developers, straight from PHP templates, with automatic disk caching.
 * Version:           1.1.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            saytformat
 * Author URI:        https://web-format.net
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sf-image-resizer
 * Domain Path:       /languages
 *
 * @package SFimageResizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version.
 */
define( 'SFIR_VERSION', '1.1.0' );

/**
 * Absolute path to the main plugin file.
 */
define( 'SFIR_PLUGIN_FILE', __FILE__ );

/**
 * Absolute path to the plugin directory, with a trailing slash.
 */
define( 'SFIR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * URL of the plugin directory, with a trailing slash.
 */
define( 'SFIR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Hard ceiling for the requested width and height, in pixels.
 */
defined( 'SFIR_MAX_DIMENSION' ) || define( 'SFIR_MAX_DIMENSION', 5000 );

require_once SFIR_PLUGIN_DIR . 'includes/class-sfir-core.php';
require_once SFIR_PLUGIN_DIR . 'includes/class-sfir-logger.php';
require_once SFIR_PLUGIN_DIR . 'includes/class-sfir-security.php';
require_once SFIR_PLUGIN_DIR . 'includes/class-sfir-cache.php';
require_once SFIR_PLUGIN_DIR . 'includes/class-sfir-placeholder.php';
require_once SFIR_PLUGIN_DIR . 'includes/class-sfir-resizer.php';
require_once SFIR_PLUGIN_DIR . 'includes/class-sfir-endpoint.php';
require_once SFIR_PLUGIN_DIR . 'includes/class-sfir-diagnostics.php';
require_once SFIR_PLUGIN_DIR . 'includes/functions.php';

if ( is_admin() ) {
	require_once SFIR_PLUGIN_DIR . 'admin/class-sfir-admin.php';
}

/**
 * Option holding the version the working directory was last prepared for.
 */
define( 'SFIR_VERSION_OPTION', 'sfir_version' );

/**
 * Boots the plugin.
 *
 * @return void
 */
function sfir_bootstrap() {
	SFIR_Endpoint::init();

	if ( is_admin() ) {
		SFIR_Admin::init();
		add_action( 'admin_init', 'sfir_maybe_upgrade' );
	}
}
add_action( 'plugins_loaded', 'sfir_bootstrap' );

/**
 * Refreshes the working directory after an update.
 *
 * Updating a plugin does not run its activation hook, so the protection files,
 * which now also route missing cache files to WordPress, are rewritten here
 * whenever the stored version differs from the running one.
 *
 * @return void
 */
function sfir_maybe_upgrade() {
	if ( get_option( SFIR_VERSION_OPTION ) === SFIR_VERSION ) {
		return;
	}

	SFIR_Cache::prepare_directories( true );
	delete_transient( SFIR_Diagnostics::TRANSIENT );

	// Drops the rewrite rule of the retired sfir-generate endpoint.
	flush_rewrite_rules();

	update_option( SFIR_VERSION_OPTION, SFIR_VERSION, true );
}

/**
 * Runs on plugin activation: prepares working directories, the HMAC secret and rewrite rules.
 *
 * @return void
 */
function sfir_activate() {
	SFIR_Security::get_secret();
	SFIR_Cache::prepare_directories( true );
	update_option( SFIR_VERSION_OPTION, SFIR_VERSION, true );
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'sfir_activate' );

/**
 * Runs on plugin deactivation. Removes nothing; only rewrite rules are flushed.
 *
 * @return void
 */
function sfir_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'sfir_deactivate' );
