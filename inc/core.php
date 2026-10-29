<?php
/**
 * Core: settings, the current language and the table that says which language a post
 * or a term is in and which other posts or terms are its translations.
 *
 * One row per translatable object: (type, id) → lang, grp. Objects sharing a grp are
 * translations of each other. Everything read from the table goes through the object
 * cache, so a request asks for a given object at most once.
 */

defined( 'ABSPATH' ) || exit;

final class Langosh {

	/** @var string Language of the request; 'all' is possible in the admin only. */
	public static $current = '';

	/** @var bool The request renders the site (or answers its AJAX), not an admin screen. */
	public static $front = true;

	/** @var bool WPML is active, Langosh does nothing but offer its settings. */
	public static $blocked = false;

	/** @var bool Queries are not narrowed to one language (sitemaps). */
	public static $no_filter = false;

	/** @var int Above zero while an option is read as stored, without translation. */
	public static $raw = 0;

	private static $settings = null;
	private static $original = '';

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	public static function settings() {
		if ( null === self::$settings ) {
			$saved          = get_option( 'langosh' );
			self::$settings = array_merge(
				array(
					'languages'  => array(),
					'default'    => '',
					'post_types' => array(
						'post' => 1,
						'page' => 1,
					),
					'taxonomies' => array( 'category', 'post_tag' ),
					'tax_fallback' => array(),
					'copy_meta'  => array(),
					'options'    => array( 'blogname', 'blogdescription' ),
				),
				is_array( $saved ) ? $saved : array()
			);

			// Until the plugin is set up the site has one language: its own.
			if ( ! self::$settings['languages'] ) {
				$locale                      = (string) get_option( 'WPLANG' );
				$locale                      = $locale ? $locale : 'en_US';
				$code                        = strtolower( substr( $locale, 0, 2 ) );
				self::$settings['languages'] = array(
					$code => array(
						'code'   => $code,
						'locale' => $locale,
						'name'   => strtoupper( $code ),
					),
				);
			}
			if ( ! isset( self::$settings['languages'][ self::$settings['default'] ] ) ) {
				self::$settings['default'] = (string) key( self::$settings['languages'] );
			}
		}

		return self::$settings;
	}

	public static function save_settings( $settings ) {
		self::$settings = null;
		update_option( 'langosh', $settings, true );
	}

	/**
	 * @return array[] code => code, locale, name
	 */
	public static function languages() {
		return self::settings()['languages'];
	}

	public static function default_lang() {
		return self::settings()['default'];
	}

	public static function is_lang( $code ) {
		return is_string( $code ) && isset( self::settings()['languages'][ $code ] );
	}

	/**
	 * Language of the request; the default one where the admin shows all languages.
	 */
	public static function current() {
		return self::is_lang( self::$current ) ? self::$current : self::default_lang();
	}

	/**
	 * Language lists are narrowed to, or '' when they are not narrowed.
	 */
	public static function filter_lang() {
		return self::$no_filter || ! self::is_lang( self::$current ) ? '' : self::$current;
	}

	/**
	 * 0 = not translated, 1 = translated, 2 = translated, the original is shown until a translation exists.
	 */
	public static function post_mode( $post_type ) {
		$types = self::settings()['post_types'];
		return isset( $types[ $post_type ] ) ? (int) $types[ $post_type ] : 0;
	}

	public static function is_translated_taxonomy( $taxonomy ) {
		return in_array( $taxonomy, self::settings()['taxonomies'], true );
	}

	/**
	 * 0 = not translated, 1 = translated, 2 = translated, the original term is shown until a translation exists.
	 */
	public static function tax_mode( $taxonomy ) {
		if ( ! self::is_translated_taxonomy( $taxonomy ) ) {
			return 0;
		}

		return in_array( $taxonomy, (array) self::settings()['tax_fallback'], true ) ? 2 : 1;
	}

	/**
	 * A post or term in another language stands in for the missing version in this language:
	 * its type shows the original until a translation exists, and no translation exists.
	 */
	public static function stands_in( $id, $type, $lang ) {
		$row = self::get( $id, $type );

		if ( ! $row || $row[0] === $lang || $row[0] !== self::default_lang() ) {
			return false;
		}

		return ! isset( self::translations( $id, $type )[ $lang ] );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'langosh';
	}

	/**
	 * An option as it is stored, whatever language the request is in.
	 */
	public static function raw_option( $name ) {
		++self::$raw;
		$value = get_option( $name );
		--self::$raw;

		return $value;
	}

	/* ---------------------------------------------------------------------
	 * Language of the request
	 * ------------------------------------------------------------------ */

	/**
	 * Reads the language from the address (/en/…). Runs while plugins load, so the
	 * locale and every later query already know it.
	 */
	public static function detect() {
		self::$current = self::default_lang();
		self::$front   = ! is_admin();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		if ( is_admin() ) {
			$referer = isset( $_SERVER['HTTP_REFERER'] ) ? (string) $_SERVER['HTTP_REFERER'] : '';

			// admin-ajax.php called from a page of the site answers in that page's language.
			if ( wp_doing_ajax() && $referer && false === strpos( $referer, '/wp-admin/' ) ) {
				self::$front   = true;
				self::$current = self::lang_from_path( (string) wp_parse_url( $referer, PHP_URL_PATH ) );
			} else {
				add_action( 'init', array( __CLASS__, 'detect_admin' ), 0 );
			}
		} elseif ( isset( $_SERVER['REQUEST_URI'] ) ) {
			self::$current = self::lang_from_path( (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
		}

		if ( self::$front && isset( $_GET['lang'] ) && self::is_lang( $_GET['lang'] ) ) {
			self::$current = $_GET['lang'];
		}
		// phpcs:enable

		self::$original = self::$current;
	}

	/**
	 * Language code a path starts with (after the folder WordPress lives in), or the default language.
	 */
	public static function lang_from_path( $path ) {
		$home = trim( (string) wp_parse_url( (string) self::raw_option( 'home' ), PHP_URL_PATH ), '/' );
		$path = trim( $path, '/' );

		if ( '' !== $home && 0 === stripos( $path . '/', $home . '/' ) ) {
			$path = ltrim( substr( $path, strlen( $home ) ), '/' );
		}

		$segment = strtolower( (string) strtok( $path, '/' ) );

		return $segment !== self::default_lang() && self::is_lang( $segment ) ? $segment : self::default_lang();
	}

	/**
	 * Admin screens work in the language picked in the toolbar (kept per user). The screen
	 * of one post or term works in the language of that post or term, so its categories,
	 * parent pages and related posts are offered in the right language.
	 */
	public static function detect_admin() {
		$user  = get_current_user_id();
		$saved = $user ? (string) get_user_meta( $user, 'langosh_admin_lang', true ) : '';

		self::$current = 'all' === $saved || self::is_lang( $saved ) ? $saved : self::default_lang();

		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$post = 0;
		$term = isset( $_REQUEST['tag_ID'] ) ? (int) $_REQUEST['tag_ID'] : 0;

		foreach ( array( 'post', 'post_ID', 'post_id' ) as $key ) {
			if ( ! $post && isset( $_REQUEST[ $key ] ) && is_numeric( $_REQUEST[ $key ] ) ) {
				$post = (int) $_REQUEST[ $key ];
			}
		}
		if ( ! $post && ! $term && wp_doing_ajax() && isset( $_SERVER['HTTP_REFERER'] ) ) {
			parse_str( (string) wp_parse_url( (string) $_SERVER['HTTP_REFERER'], PHP_URL_QUERY ), $query );
			$post = isset( $query['post'] ) ? (int) $query['post'] : 0;
		}
		// phpcs:enable

		$lang = $post ? self::lang_of( $post ) : ( $term ? self::lang_of( $term, 'term' ) : '' );

		if ( $lang ) {
			self::$current = $lang;
		}

		self::$original = self::$current;
	}

	/**
	 * Works in another language until switched back; without an argument returns to the language of the request.
	 */
	public static function switch_to( $lang = null ) {
		self::$current = self::is_lang( $lang ) || 'all' === $lang ? $lang : self::$original;
	}

	/* ---------------------------------------------------------------------
	 * Languages of posts and terms
	 * ------------------------------------------------------------------ */

	private static function type( $type ) {
		return 'term' === $type ? 'term' : 'post';
	}

	/**
	 * Reads the rows of several objects in one query; later lookups come from the cache.
	 */
	public static function prime( $ids, $type = 'post' ) {
		global $wpdb;

		$type = self::type( $type );
		$miss = array();

		foreach ( array_unique( array_map( 'intval', (array) $ids ) ) as $id ) {
			if ( $id > 0 && false === wp_cache_get( $type . ':' . $id, 'langosh' ) ) {
				$miss[ $id ] = 0;
			}
		}

		if ( ! $miss ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( 'SELECT id, lang, grp FROM ' . self::table() . " WHERE type = '$type' AND id IN (" . implode( ',', array_keys( $miss ) ) . ')' );

		foreach ( (array) $rows as $row ) {
			$miss[ (int) $row->id ] = array( $row->lang, (int) $row->grp );
		}
		foreach ( $miss as $id => $row ) {
			wp_cache_set( $type . ':' . $id, $row, 'langosh' );
		}
	}

	/**
	 * Reads the translations of several objects in one query, for lists where each item
	 * is about to be asked for its version in another language.
	 */
	public static function prime_groups( $ids, $type = 'post' ) {
		global $wpdb;

		$type = self::type( $type );
		$grps = array();

		self::prime( $ids, $type );

		foreach ( (array) $ids as $id ) {
			$row = self::get( $id, $type );

			if ( $row && false === wp_cache_get( 'g:' . $type . ':' . $row[1], 'langosh' ) ) {
				$grps[ $row[1] ] = array();
			}
		}

		if ( ! $grps ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( 'SELECT id, lang, grp FROM ' . self::table() . " WHERE type = '$type' AND grp IN (" . implode( ',', array_keys( $grps ) ) . ') ORDER BY id' );

		foreach ( (array) $rows as $row ) {
			$grps[ (int) $row->grp ][ $row->lang ] = (int) $row->id;
		}
		foreach ( $grps as $grp => $list ) {
			wp_cache_set( 'g:' . $type . ':' . $grp, $list, 'langosh' );
		}
	}

	/**
	 * @return array|null [ language code, group ] or null for an object without a language
	 */
	public static function get( $id, $type = 'post' ) {
		$type = self::type( $type );
		$id   = (int) $id;
		$row  = wp_cache_get( $type . ':' . $id, 'langosh' );

		if ( false === $row ) {
			self::prime( array( $id ), $type );
			$row = wp_cache_get( $type . ':' . $id, 'langosh' );
		}

		return $row ? $row : null;
	}

	public static function lang_of( $id, $type = 'post' ) {
		$row = self::get( $id, $type );
		return $row ? $row[0] : '';
	}

	/**
	 * All language versions of an object, itself included.
	 *
	 * @return int[] language code => ID
	 */
	public static function translations( $id, $type = 'post' ) {
		global $wpdb;

		$type = self::type( $type );
		$row  = self::get( $id, $type );

		if ( ! $row ) {
			return array();
		}

		$key  = 'g:' . $type . ':' . $row[1];
		$list = wp_cache_get( $key, 'langosh' );

		if ( false === $list ) {
			$list = array();
			// phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, lang FROM ' . self::table() . ' WHERE grp = %d AND type = %s ORDER BY id', $row[1], $type ) ) as $other ) {
				$list[ $other->lang ] = (int) $other->id;
				wp_cache_set( $type . ':' . $other->id, array( $other->lang, $row[1] ), 'langosh' );
			}
			wp_cache_set( $key, $list, 'langosh' );
		}

		return $list;
	}

	/**
	 * ID of the version in a language; 0 when there is none, or the given ID with $fallback.
	 * Objects without a language (types that are not translated) are their own version.
	 */
	public static function translate_id( $id, $lang = '', $type = 'post', $fallback = false ) {
		$id   = (int) $id;
		$lang = $lang ? $lang : self::current();
		$row  = self::get( $id, $type );

		if ( ! $row || $row[0] === $lang ) {
			return $id;
		}

		$list = self::translations( $id, $type );

		if ( isset( $list[ $lang ] ) ) {
			return $list[ $lang ];
		}

		return $fallback ? $id : 0;
	}

	/**
	 * Gives an object a language. With $grp it joins that group of translations; without,
	 * it stays in its group or starts a new one. A group holds one object per language,
	 * so joining a group that already has this language starts a new group instead.
	 *
	 * @return int group
	 */
	public static function set( $id, $type, $lang, $grp = 0 ) {
		global $wpdb;

		$type = self::type( $type );
		$id   = (int) $id;
		$grp  = (int) $grp;

		if ( ! $id || ! self::is_lang( $lang ) ) {
			return 0;
		}

		$old = self::get( $id, $type );

		if ( ! $grp && $old ) {
			$grp = $old[1];
		}
		if ( $grp ) {
			// phpcs:ignore WordPress.DB.PreparedSQL
			$taken = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE grp = %d AND type = %s AND lang = %s AND id <> %d LIMIT 1', $grp, $type, $lang, $id ) );
			$grp   = $taken ? 0 : $grp;
		}
		if ( ! $grp ) {
			// phpcs:ignore WordPress.DB.PreparedSQL
			$grp = 1 + (int) $wpdb->get_var( 'SELECT MAX(grp) FROM ' . self::table() );
		}

		$wpdb->replace(
			self::table(),
			array(
				'type' => $type,
				'id'   => $id,
				'lang' => $lang,
				'grp'  => $grp,
			),
			array( '%s', '%d', '%s', '%d' )
		);

		wp_cache_delete( $type . ':' . $id, 'langosh' );
		wp_cache_delete( 'g:' . $type . ':' . $grp, 'langosh' );
		if ( $old ) {
			wp_cache_delete( 'g:' . $type . ':' . $old[1], 'langosh' );
		}

		return $grp;
	}

	/**
	 * Makes the given objects translations of each other.
	 *
	 * @param int[] $ids language code => ID
	 */
	public static function link( $ids, $type = 'post' ) {
		$grp = 0;

		foreach ( $ids as $lang => $id ) {
			if ( $id && self::is_lang( $lang ) ) {
				$grp = self::set( $id, $type, $lang, $grp );
			}
		}

		return $grp;
	}

	/**
	 * Takes an object out of its group: it keeps its language and has no translations.
	 */
	public static function detach( $id, $type = 'post' ) {
		$old = self::get( $id, $type );

		if ( $old ) {
			self::delete( $id, $type );
			self::set( $id, $type, $old[0] );
		}
	}

	public static function delete( $id, $type = 'post' ) {
		global $wpdb;

		$type = self::type( $type );
		$old  = self::get( $id, $type );

		if ( $old ) {
			$wpdb->delete(
				self::table(),
				array(
					'type' => $type,
					'id'   => (int) $id,
				),
				array( '%s', '%d' )
			);
			wp_cache_delete( $type . ':' . (int) $id, 'langosh' );
			wp_cache_delete( 'g:' . $type . ':' . $old[1], 'langosh' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Addresses
	 * ------------------------------------------------------------------ */

	/**
	 * The site address as stored (without a language folder), no trailing slash.
	 */
	public static function base() {
		static $base = null;

		if ( null === $base ) {
			$base = untrailingslashit( (string) self::raw_option( 'home' ) );
		}

		return $base;
	}

	/**
	 * Home address of a language: /en/ for English, / for the default language.
	 */
	public static function home( $lang = '', $path = '/' ) {
		$lang = $lang ? $lang : self::current();
		$url  = set_url_scheme( self::base() ) . ( $lang === self::default_lang() ? '' : '/' . $lang );

		return $url . '/' . ltrim( $path, '/' );
	}

	/**
	 * The same address in another language: swaps, adds or removes the language folder.
	 * Addresses outside the site are returned as they are.
	 */
	public static function url_lang( $url, $lang ) {
		$url  = (string) $url;
		$base = preg_replace( '#^https?:#i', '', self::base() );
		$rest = preg_replace( '#^https?:#i', '', $url );

		if ( 0 !== stripos( $rest, $base ) ) {
			return $url;
		}

		$rest = (string) substr( $rest, strlen( $base ) );

		if ( '' !== $rest && false === strpos( '/?#', $rest[0] ) ) {
			return $url;
		}

		$head = substr( $url, 0, strlen( $url ) - strlen( $rest ) );

		if ( preg_match( '#^/([a-z_-]{2,10})(?=[/?\#]|$)#i', $rest, $match ) && self::is_lang( strtolower( $match[1] ) ) ) {
			$rest = (string) substr( $rest, strlen( $match[0] ) );
		}

		return $head . ( $lang === self::default_lang() || ! self::is_lang( $lang ) ? '' : '/' . $lang ) . $rest;
	}

	/* ---------------------------------------------------------------------
	 * Installation
	 * ------------------------------------------------------------------ */

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				type varchar(4) NOT NULL,
				id bigint(20) unsigned NOT NULL,
				lang varchar(10) NOT NULL,
				grp bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (type,id),
				KEY grp (grp),
				KEY lang (type,lang)
			) " . $wpdb->get_charset_collate() . ';'
		);

		if ( ! is_array( get_option( 'langosh' ) ) ) {
			self::save_settings( self::settings() );
		}
		if ( false === get_option( 'langosh_strings' ) ) {
			add_option( 'langosh_strings', array(), '', true );
		}
	}
}

Langosh::$blocked = in_array( 'sitepress-multilingual-cms/sitepress.php', (array) get_option( 'active_plugins', array() ), true );
