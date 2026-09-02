<?php
/**
 * Settings that change what WordPress itself does with uploads.
 *
 * @package SFimageResizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Holds the handful of switches that reach outside the plugin.
 *
 * Everything else the plugin does is decided per call, in a template. These are
 * the exceptions: they change WordPress behaviour for the whole site, so they
 * have to be stored, and they belong on a screen rather than in a template.
 */
class SFIR_Settings {

	/**
	 * Option holding the switch that keeps WordPress from shrinking uploads.
	 */
	const BIG_IMAGE_OPTION = 'sfir_keep_full_size_uploads';

	/**
	 * Registers the filters the stored settings ask for.
	 *
	 * Called on every request, front end included: an upload can arrive through
	 * the media library, the REST API, XML-RPC or WP-CLI, and the threshold has
	 * to be lifted for all of them alike.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::keeps_full_size_uploads() ) {
			add_filter( 'big_image_size_threshold', '__return_false', 20 );
		}
	}

	/**
	 * Tells whether WordPress should leave large uploads at their full size.
	 *
	 * @return bool
	 */
	public static function keeps_full_size_uploads() {
		return (bool) get_option( self::BIG_IMAGE_OPTION, false );
	}

	/**
	 * Stores that switch.
	 *
	 * @param bool $enabled Whether to keep uploads at their full size.
	 * @return void
	 */
	public static function set_keeps_full_size_uploads( $enabled ) {
		update_option( self::BIG_IMAGE_OPTION, $enabled ? 1 : 0, true );
	}

	/**
	 * Returns the threshold WordPress applies to uploads right now.
	 *
	 * Reported on the screen so the setting can be seen to have taken effect,
	 * including when another plugin or the theme has its own opinion.
	 *
	 * @return int Threshold in pixels, or 0 when nothing is scaled down.
	 */
	public static function get_effective_threshold() {
		/**
		 * This filter is documented in wp-admin/includes/image.php. It is read
		 * here, not introduced: asking WordPress what it would do is the only
		 * way to report a number that stays true when another plugin or the
		 * theme also has an opinion about it. Core's own filter is a plain
		 * value filter with no side effects.
		 *
		 * @param int $threshold Threshold WordPress would apply.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reading a core filter to report its value, not defining one.
		$threshold = apply_filters( 'big_image_size_threshold', 2560, array( 0, 0 ), '', 0 );

		return $threshold ? (int) $threshold : 0;
	}
}
