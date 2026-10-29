<?php
/**
 * Addresses: the language folder (/en/), permalinks in the language of each post and
 * term, the locale, the front page and menus per language.
 *
 * WordPress works out which page was asked for from home_url(). Once that carries the
 * language folder, the rewrite rules, canonical redirects, pagination, feeds and the
 * REST API all work under /en/ with no rules of their own.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'home_url',
	function ( $url, $path ) {
		if ( ! Langosh::$front || Langosh::$raw || Langosh::current() === Langosh::default_lang() ) {
			return $url;
		}
		// Files and screens that exist once, not per language.
		if ( is_string( $path ) && preg_match( '#^/?(wp-content|wp-includes|wp-admin|wp-login\.php|xmlrpc\.php)#', $path ) ) {
			return $url;
		}

		return Langosh::url_lang( $url, Langosh::current() );
	},
	10,
	2
);

/**
 * Language a post or term is linked in: its own, or the language of the request while it
 * stands in for a version that does not exist yet (types shown "with the original").
 */
function langosh_link_lang( $id, $row, $type = 'post' ) {
	if ( ! Langosh::$front || Langosh::$no_filter || $row[0] === Langosh::current() || 'all' === Langosh::$current ) {
		return $row[0];
	}

	if ( 'term' === $type ) {
		$term = get_term( $id );
		$mode = $term instanceof WP_Term ? Langosh::tax_mode( $term->taxonomy ) : 0;
	} else {
		$mode = Langosh::post_mode( get_post_type( $id ) );
	}

	return 2 === $mode && Langosh::stands_in( $id, $type, Langosh::current() ) ? Langosh::current() : $row[0];
}

/**
 * A permalink is always in the language of the post, wherever it is printed.
 */
function langosh_post_link( $url, $post ) {
	$id  = is_object( $post ) ? (int) $post->ID : (int) $post;
	$row = Langosh::get( $id );

	if ( ! $row ) {
		return $url;
	}

	// Every language version of the front page is the home address of its language.
	if ( 'page' === get_option( 'show_on_front' ) ) {
		$front = Langosh::get( (int) Langosh::raw_option( 'page_on_front' ) );

		if ( $front && $front[1] === $row[1] ) {
			return Langosh::home( $row[0] );
		}
	}

	return Langosh::url_lang( $url, langosh_link_lang( $id, $row ) );
}
add_filter( 'post_link', 'langosh_post_link', 10, 2 );
add_filter( 'page_link', 'langosh_post_link', 10, 2 );
add_filter( 'post_type_link', 'langosh_post_link', 10, 2 );

add_filter(
	'term_link',
	function ( $url, $term ) {
		$row = Langosh::get( $term->term_id, 'term' );
		return $row ? Langosh::url_lang( $url, langosh_link_lang( $term->term_id, $row, 'term' ) ) : $url;
	},
	10,
	2
);

/**
 * The site speaks the locale of its language (dates, core and plugin texts, <html lang>).
 * Admin screens keep the locale of the user.
 */
add_filter(
	'locale',
	function ( $locale ) {
		if ( ! Langosh::$front ) {
			return $locale;
		}

		$languages = Langosh::languages();
		$current   = Langosh::current();

		return empty( $languages[ $current ]['locale'] ) ? $locale : $languages[ $current ]['locale'];
	}
);

/**
 * Options that hold the ID of a page (front page, posts page, privacy page, the pages of a
 * shop): the version in the language of the request. Admin screens keep the stored page.
 */
function langosh_page_option( $value ) {
	if ( ! $value || ! is_numeric( $value ) || ! Langosh::$front || Langosh::$raw ) {
		return $value;
	}

	return Langosh::translate_id( (int) $value, Langosh::current(), 'post', true );
}

add_action(
	'plugins_loaded',
	function () {
		$options = array( 'page_on_front', 'page_for_posts', 'wp_page_for_privacy_policy', 'woocommerce_shop_page_id', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id', 'woocommerce_terms_page_id' );

		foreach ( apply_filters( 'langosh_page_options', $options ) as $option ) {
			add_filter( 'option_' . $option, 'langosh_page_option' );
		}
	},
	1
);

/**
 * Menus: next to every menu location of the theme there is one per additional language
 * ("Glavni izbornik (English)"). The theme keeps asking for its own location and gets
 * the menu of the language.
 */
function langosh_menu_location( $location, $lang ) {
	return $lang === Langosh::default_lang() ? $location : $location . '___' . $lang;
}

add_action(
	'after_setup_theme',
	function () {
		foreach ( get_registered_nav_menus() as $location => $label ) {
			if ( false !== strpos( $location, '___' ) ) {
				continue;
			}
			foreach ( Langosh::languages() as $code => $language ) {
				if ( $code !== Langosh::default_lang() ) {
					register_nav_menu( langosh_menu_location( $location, $code ), $label . ' (' . $language['name'] . ')' );
				}
			}
		}
	},
	99
);

add_filter(
	'theme_mod_nav_menu_locations',
	function ( $locations ) {
		if ( ! is_array( $locations ) || ! Langosh::$front || Langosh::current() === Langosh::default_lang() ) {
			return $locations;
		}

		$suffix = '___' . Langosh::current();

		foreach ( $locations as $location => $menu ) {
			if ( false === strpos( $location, '___' ) ) {
				// Without a menu of its own the language shows none, rather than the menu of another language.
				$locations[ $location ] = empty( $locations[ $location . $suffix ] ) ? 0 : $locations[ $location . $suffix ];
			}
		}

		return $locations;
	}
);

/**
 * Two languages may use the same slug (/graviola/ and /en/graviola/). WordPress looks a
 * page – or a post of any type that has parents – up by its path alone, so when a path is
 * ambiguous the one in the language of the request is picked here.
 */
add_filter(
	'request',
	function ( $vars ) {
		global $wpdb;

		if ( ! Langosh::$front ) {
			return $vars;
		}

		// Query variable that holds the path => post type.
		$asked = array();

		if ( ! empty( $vars['pagename'] ) ) {
			$asked['pagename'] = 'page';
		}

		foreach ( array_keys( array_filter( Langosh::settings()['post_types'] ) ) as $type ) {
			$object = 'page' === $type ? null : get_post_type_object( $type );

			if ( $object && $object->hierarchical && $object->query_var && ! empty( $vars[ $object->query_var ] ) ) {
				$asked[ $object->query_var ] = $type;
			}
		}

		foreach ( $asked as $var => $type ) {
			if ( ! Langosh::post_mode( $type ) ) {
				continue;
			}

			$path = trim( (string) $vars[ $var ], '/' );
			// phpcs:ignore WordPress.DB.PreparedSQL
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, l.lang FROM $wpdb->posts p LEFT JOIN " . Langosh::table() . " l ON l.type = 'post' AND l.id = p.ID WHERE p.post_name = %s AND p.post_type = %s AND p.post_status NOT IN ( 'trash', 'auto-draft' )", sanitize_title( basename( $path ) ), $type ) );

			if ( count( (array) $rows ) < 2 ) {
				continue;
			}

			foreach ( $rows as $row ) {
				if ( $row->lang === Langosh::current() && get_page_uri( (int) $row->ID ) === $path ) {
					if ( 'page' === $type ) {
						$vars['page_id'] = (int) $row->ID;
					} else {
						$vars['p']         = (int) $row->ID;
						$vars['post_type'] = $type;
						unset( $vars['name'] );
					}

					unset( $vars[ $var ] );
					break;
				}
			}
		}

		return $vars;
	}
);

/**
 * An address of one language asked for under the folder of another (/en/rjecnik/ where
 * the English page is /en/glossary/) is not found, and WordPress would guess its way to
 * the page in the other language – the visitor silently changes language. When the page
 * has a version in the language of the request, that version is where the visitor goes.
 * This also makes paths a theme or an option holds in one language (/kontakt/) work in all.
 */
add_action(
	'template_redirect',
	function () {
		if ( ! is_404() || ! Langosh::$front || ! isset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		global $wp;

		$lang = Langosh::current();

		// A term asked for by the slug it has in another language.
		$single = ! empty( $wp->query_vars['name'] ) || ! empty( $wp->query_vars['pagename'] ) || ! empty( $wp->query_vars['p'] );

		foreach ( $single ? array() : Langosh::settings()['taxonomies'] as $name ) {
			$taxonomy = get_taxonomy( $name );
			$slug     = $taxonomy && $taxonomy->query_var && ! empty( $wp->query_vars[ $taxonomy->query_var ] ) ? wp_basename( (string) $wp->query_vars[ $taxonomy->query_var ] ) : '';

			if ( '' === $slug ) {
				continue;
			}

			$found = get_terms(
				array(
					'taxonomy'   => $name,
					'slug'       => $slug,
					'hide_empty' => false,
					'fields'     => 'ids',
					'lang'       => 'all',
				)
			);

			foreach ( is_array( $found ) ? $found : array() as $id ) {
				$other = Langosh::translate_id( (int) $id, $lang, 'term' );
				$link  = $other && $other !== (int) $id ? get_term_link( $other, $name ) : '';

				if ( $link && ! is_wp_error( $link ) ) {
					wp_safe_redirect( $link, 301, 'Langosh' );
					exit;
				}
			}
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$here  = esc_url_raw( set_url_scheme( 'http://' . wp_unslash( $_SERVER['HTTP_HOST'] ) . wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
		$query = (string) wp_parse_url( $here, PHP_URL_QUERY );
		$path  = (string) strtok( $here, '?#' );

		foreach ( Langosh::languages() as $code => $language ) {
			if ( $code === $lang ) {
				continue;
			}

			// Looked up as that language would: its folder in the address, its posts in the query.
			Langosh::switch_to( $code );
			$id = url_to_postid( Langosh::url_lang( $path, $code ) );
			Langosh::switch_to();

			$other = $id && $code === Langosh::lang_of( $id ) ? Langosh::translate_id( $id, $lang ) : 0;

			if ( $other && $other !== $id && 'publish' === get_post_status( $other ) ) {
				$url = get_permalink( $other );

				if ( $url && untrailingslashit( (string) strtok( $url, '?' ) ) !== untrailingslashit( $path ) ) {
					wp_safe_redirect( $query ? $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $query : $url, 301, 'Langosh' );
					exit;
				}
			}
		}
	},
	1
);

/**
 * A slug only has to be unique within its language.
 */
add_filter(
	'wp_unique_post_slug',
	function ( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {
		global $wpdb;

		if ( $slug === $original_slug || ! Langosh::post_mode( $post_type ) ) {
			return $slug;
		}

		$lang = Langosh::lang_of( $post_id );
		$lang = $lang ? $lang : Langosh::current();

		// Pages only clash with their siblings, other posts with every post of their type.
		$parent = is_post_type_hierarchical( $post_type ) ? $wpdb->prepare( ' AND p.post_parent = %d', $post_parent ) : '';

		// phpcs:ignore WordPress.DB.PreparedSQL
		$clash = $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM $wpdb->posts p LEFT JOIN " . Langosh::table() . " l ON l.type = 'post' AND l.id = p.ID WHERE p.post_name = %s AND p.post_type = %s AND p.ID <> %d AND ( l.lang IS NULL OR l.lang = %s )$parent LIMIT 1", $original_slug, $post_type, $post_id, $lang ) );

		return $clash ? $slug : $original_slug;
	},
	10,
	6
);

/**
 * Sitemaps list every language; nothing in them is narrowed to the language of the request.
 */
add_action(
	'parse_request',
	function ( $wp ) {
		if ( ! empty( $wp->query_vars['sitemap'] ) || ! empty( $wp->query_vars['sitemap-stylesheet'] ) ) {
			Langosh::$no_filter = true;
		}
	}
);
