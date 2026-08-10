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

require_once __DIR__ . '/../sf-image-resizer/includes/class-sfir-core.php';
require_once __DIR__ . '/../sf-image-resizer/includes/class-sfir-security.php';
require_once __DIR__ . '/../sf-image-resizer/includes/class-sfir-placeholder.php';
