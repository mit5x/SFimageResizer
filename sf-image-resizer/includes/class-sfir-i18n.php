<?php
/**
 * Language handling for the plugin screen.
 *
 * @package SFimageResizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets an administrator read the plugin screen in a language of their choice.
 *
 * By default the screen follows the language WordPress itself is running in.
 * The picker on the screen overrides that for this plugin only, and the choice
 * is remembered per user, so it survives the next visit.
 */
class SFIR_I18n {

	/**
	 * User meta key holding the chosen locale.
	 */
	const USER_META = 'sfir_admin_locale';

	/**
	 * Text domain of the plugin.
	 */
	const DOMAIN = 'sf-image-resizer';

	/**
	 * Registers the locale override.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'plugin_locale', array( __CLASS__, 'filter_plugin_locale' ), 10, 2 );
	}

	/**
	 * Returns the locales the plugin screen is translated into.
	 *
	 * The empty key means "follow the WordPress language".
	 *
	 * @return array<string,string> Locale code to native name.
	 */
	public static function get_available_locales() {
		return array(
			''      => __( 'Site language', 'sf-image-resizer' ),
			'en_US' => 'English',
			'ru_RU' => 'Русский',
			'es_ES' => 'Español',
			'de_DE' => 'Deutsch',
			'fr_FR' => 'Français',
			'it_IT' => 'Italiano',
			'pt_BR' => 'Português do Brasil',
			'zh_CN' => '简体中文',
		);
	}

	/**
	 * Returns the locale the current user picked, if any.
	 *
	 * @return string Locale code, or an empty string when the site language is used.
	 */
	public static function get_user_locale() {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return '';
		}

		$stored = get_user_meta( $user_id, self::USER_META, true );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return '';
		}

		return isset( self::get_available_locales()[ $stored ] ) ? $stored : '';
	}

	/**
	 * Stores the locale choice of the current user.
	 *
	 * @param string $locale Locale code, or an empty string to follow the site.
	 * @return void
	 */
	public static function set_user_locale( $locale ) {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		if ( '' === $locale || ! isset( self::get_available_locales()[ $locale ] ) ) {
			delete_user_meta( $user_id, self::USER_META );
			return;
		}

		update_user_meta( $user_id, self::USER_META, $locale );
	}

	/**
	 * Overrides the locale used for this plugin's translations.
	 *
	 * Only this text domain is affected, so the rest of the admin keeps the
	 * language WordPress is configured with.
	 *
	 * @param string $locale Locale WordPress would use.
	 * @param string $domain Text domain being loaded.
	 * @return string
	 */
	public static function filter_plugin_locale( $locale, $domain ) {
		if ( self::DOMAIN !== $domain ) {
			return $locale;
		}

		$chosen = self::get_user_locale();

		return '' === $chosen ? $locale : $chosen;
	}

	/**
	 * Returns the locale the plugin screen should be drawn in.
	 *
	 * @return string
	 */
	public static function get_effective_locale() {
		$chosen = self::get_user_locale();

		return '' === $chosen ? determine_locale() : $chosen;
	}

	/**
	 * Loads the translations of the plugin screen.
	 *
	 * The file is loaded by its full path rather than through
	 * load_plugin_textdomain(): the screen may have to switch away from the
	 * language WordPress itself determined, and only an explicit path is
	 * guaranteed to override a translation that was already loaded.
	 *
	 * Called while the screen is being rendered, which is after "init", so the
	 * just-in-time loading warning of WordPress 6.7 cannot be triggered.
	 *
	 * @return bool Whether a translation was loaded.
	 */
	public static function load_textdomain() {
		$locale = self::get_effective_locale();

		// Reloadable, so WordPress may still load this domain again later.
		unload_textdomain( self::DOMAIN, true );

		if ( '' === $locale || 'en_US' === $locale ) {
			return false;
		}

		$mofile = SFIR_PLUGIN_DIR . 'languages/' . self::DOMAIN . '-' . $locale . '.mo';

		if ( ! is_readable( $mofile ) ) {
			return false;
		}

		return load_textdomain( self::DOMAIN, $mofile, $locale );
	}
}
