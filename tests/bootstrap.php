<?php
/**
 * Bootstrap for the standalone unit tests.
 *
 * The pure-logic classes of the plugin are loaded without a running WordPress
 * instance. Only the handful of WordPress functions they touch are stubbed.
 *
 * @package SFimageResizer
 */

define( 'ABSPATH', __DIR__ . '/fixtures/fake-abspath/' );
define( 'SFIR_MAX_DIMENSION', 5000 );

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stub.
	 *
	 * @param string $text   Text to return.
	 * @param string $domain Unused text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escaping stub.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	function esc_html( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Escaping stub.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	function esc_attr( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	/**
	 * Argument merging stub.
	 *
	 * @param array|string $args     Arguments to merge.
	 * @param array        $defaults Default values.
	 * @return array
	 */
	function wp_parse_args( $args, $defaults = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( is_string( $args ) ) {
			parse_str( $args, $parsed );
			$args = $parsed;
		}

		return array_merge( $defaults, (array) $args );
	}
}

$GLOBALS['sfir_test_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Option store stub.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Value when the option is unset.
	 * @return mixed
	 */
	function get_option( $name, $default = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return array_key_exists( $name, $GLOBALS['sfir_test_options'] ) ? $GLOBALS['sfir_test_options'][ $name ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Option store stub.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value to store.
	 * @param bool   $autoload Unused.
	 * @return bool
	 */
	function update_option( $name, $value, $autoload = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $autoload );
		$GLOBALS['sfir_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Filter stub honouring the overrides a test registers.
	 *
	 * @param string $hook  Hook name.
	 * @param mixed  $value Value to pass through.
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( isset( $GLOBALS['sfir_test_filters'][ $hook ] ) ) {
			return call_user_func( $GLOBALS['sfir_test_filters'][ $hook ], $value );
		}

		return $value;
	}
}

require_once __DIR__ . '/../sf-image-resizer/includes/class-sfir-core.php';
require_once __DIR__ . '/../sf-image-resizer/includes/class-sfir-security.php';
require_once __DIR__ . '/../sf-image-resizer/includes/class-sfir-placeholder.php';

/**
 * Stands in for the diagnostics class, which needs a running WordPress.
 *
 * Only the one method SFIR_Generator asks about is provided.
 */
class SFIR_Diagnostics { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound

	/**
	 * Timestamp the tests want get_confirmed_at() to report.
	 *
	 * @var int
	 */
	public static $confirmed = 0;

	/**
	 * Returns the moment pretty URLs were last confirmed.
	 *
	 * @return int
	 */
	public static function get_confirmed_at() {
		return (int) self::$confirmed;
	}
}

require_once __DIR__ . '/../sf-image-resizer/includes/class-sfir-generator.php';
