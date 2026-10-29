<?php
/**
 * Texts that are not posts: options (site title, tagline, SEO title templates…) and
 * strings a theme or plugin registers with langosh_t().
 *
 * All translations live in one autoloaded option, so reading them costs no query.
 * Stored as language => key => text, where a key is
 *   opt:blogname                        an option
 *   opt:wpseo_titles[title-home-wpseo]  one entry of an option that is an array
 *   str:<name>                          a registered string
 */

defined( 'ABSPATH' ) || exit;

final class Langosh_Strings {

	private static $map = null;

	/**
	 * @return array language => key => text
	 */
	public static function all() {
		$all = get_option( 'langosh_strings' );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Translations of options, as language => option name => ( text | entry => text ).
	 */
	private static function map() {
		if ( null === self::$map ) {
			self::$map = array();

			foreach ( self::all() as $lang => $texts ) {
				foreach ( (array) $texts as $key => $text ) {
					if ( '' === (string) $text || ! preg_match( '/^opt:([^\[]+)(?:\[(.+)\])?$/', $key, $match ) ) {
						continue;
					}
					if ( isset( $match[2] ) ) {
						self::$map[ $lang ][ $match[1] ][ $match[2] ] = $text;
					} else {
						self::$map[ $lang ][ $match[1] ] = $text;
					}
				}
			}
		}

		return self::$map;
	}

	public static function reset() {
		self::$map = null;
	}

	/**
	 * Names of the options that have a translation in some language.
	 */
	public static function option_names() {
		$names = array();

		foreach ( self::map() as $options ) {
			$names += array_fill_keys( array_keys( $options ), true );
		}

		return array_keys( $names );
	}

	public static function filter_option( $value, $option ) {
		$lang = Langosh::current();

		// Settings screens edit the text of the default language.
		if ( ! Langosh::$front || Langosh::$raw || $lang === Langosh::default_lang() ) {
			return $value;
		}

		$map = self::map();

		if ( ! isset( $map[ $lang ][ $option ] ) ) {
			return $value;
		}
		if ( ! is_array( $map[ $lang ][ $option ] ) ) {
			return $map[ $lang ][ $option ];
		}
		if ( is_array( $value ) ) {
			return array_merge( $value, $map[ $lang ][ $option ] );
		}

		return $value;
	}

	/**
	 * Translation of a registered string, or the text itself.
	 */
	public static function translate( $name, $text, $lang = '' ) {
		$lang = $lang ? $lang : Langosh::current();

		if ( $lang !== Langosh::default_lang() ) {
			$all = self::all();

			if ( isset( $all[ $lang ][ 'str:' . $name ] ) && '' !== (string) $all[ $lang ][ 'str:' . $name ] ) {
				return $all[ $lang ][ 'str:' . $name ];
			}
		}

		return $text;
	}

	/**
	 * Lists a string on the translation screen. The list is not autoloaded and is only
	 * written by an administrator's request, so visitors never pay for it.
	 */
	public static function register( $name, $text ) {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$registry = get_option( 'langosh_registry' );
		$registry = is_array( $registry ) ? $registry : array();

		if ( ! isset( $registry[ $name ] ) || $registry[ $name ] !== $text ) {
			$registry[ $name ] = (string) $text;
			update_option( 'langosh_registry', $registry, false );
		}
	}

	/**
	 * Everything offered for translation: key => text in the default language.
	 */
	public static function entries() {
		$entries = array();

		foreach ( Langosh::settings()['options'] as $path ) {
			if ( ! preg_match( '/^([^\[]+)(?:\[(.+)\])?$/', trim( $path ), $match ) ) {
				continue;
			}

			$value = Langosh::raw_option( $match[1] );

			if ( ! isset( $match[2] ) ) {
				if ( is_scalar( $value ) ) {
					$entries[ 'opt:' . $match[1] ] = (string) $value;
				}
				continue;
			}

			// "wpseo_titles[title-*]" stands for every entry that matches.
			foreach ( is_array( $value ) ? $value : array() as $entry => $text ) {
				if ( is_string( $text ) && '' !== $text && fnmatch( $match[2], (string) $entry ) ) {
					$entries[ 'opt:' . $match[1] . '[' . $entry . ']' ] = $text;
				}
			}
		}

		$registry = get_option( 'langosh_registry' );

		foreach ( is_array( $registry ) ? $registry : array() as $name => $text ) {
			$entries[ 'str:' . $name ] = (string) $text;
		}

		return $entries;
	}
}

foreach ( Langosh_Strings::option_names() as $langosh_option ) {
	add_filter( 'option_' . $langosh_option, array( 'Langosh_Strings', 'filter_option' ), 20, 2 );
}
unset( $langosh_option );
