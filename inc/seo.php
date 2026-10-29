<?php
/**
 * SEO: the address of the current page in every language, printed as hreflang links
 * and used by the language switcher; sitemaps that list all languages.
 *
 * Titles, descriptions, canonical addresses and og:locale stay the job of the SEO plugin
 * (or the theme): each translation is a post of its own with its own SEO fields, and
 * site-wide templates are translated under Langosh → Tekstovi.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Address of the page being shown in each language that has it.
 *
 * @param bool $all also list addresses that are the same page with another language
 *                  folder (search, date archives) – wanted by the switcher, not by hreflang
 * @return string[] language code => URL
 */
function langosh_alternates( $all = false ) {
	static $cache = array();

	$slot = Langosh::current() . ( $all ? '|all' : '' );

	if ( isset( $cache[ $slot ] ) ) {
		return $cache[ $slot ];
	}

	$urls = array();

	if ( is_404() ) {
		$urls = array();
	} elseif ( is_front_page() && is_home() ) {
		// The latest posts as the front page.
		foreach ( Langosh::languages() as $code => $language ) {
			$urls[ $code ] = Langosh::home( $code );
		}
	} elseif ( is_singular() || is_home() ) {
		// is_home() without is_front_page() is the page chosen to list the posts.
		$id = (int) get_queried_object_id();

		foreach ( Langosh::translations( $id ) as $code => $other ) {
			if ( $other === $id || 'publish' === get_post_status( $other ) ) {
				// In its own language: an original standing in for a missing version is linked in the request's language.
				$urls[ $code ] = Langosh::url_lang( get_permalink( $other ), $code );
			}
		}

		// Shown with the original: the same post is the page of every language that has no version of its own.
		if ( $urls && 2 === Langosh::post_mode( get_post_type( $id ) ) ) {
			$own = Langosh::lang_of( $id );

			foreach ( Langosh::languages() as $code => $language ) {
				if ( ! isset( $urls[ $code ] ) && isset( $urls[ $own ] ) && $own === Langosh::default_lang() ) {
					$urls[ $code ] = Langosh::url_lang( $urls[ $own ], $code );
				}
			}
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();

		if ( $term && Langosh::is_translated_taxonomy( $term->taxonomy ) ) {
			foreach ( Langosh::translations( $term->term_id, 'term' ) as $code => $other ) {
				$link = get_term_link( $other, $term->taxonomy );
				if ( ! is_wp_error( $link ) ) {
					$urls[ $code ] = $link;
				}
			}

			$own = Langosh::lang_of( $term->term_id, 'term' );

			if ( 2 === Langosh::tax_mode( $term->taxonomy ) && $own === Langosh::default_lang() && isset( $urls[ $own ] ) ) {
				foreach ( Langosh::languages() as $code => $language ) {
					if ( ! isset( $urls[ $code ] ) ) {
						$urls[ $code ] = Langosh::url_lang( $urls[ $own ], $code );
					}
				}
			}
		} elseif ( $term ) {
			foreach ( Langosh::languages() as $code => $language ) {
				$urls[ $code ] = Langosh::url_lang( get_term_link( $term ), $code );
			}
		}
	} elseif ( is_post_type_archive() ) {
		foreach ( Langosh::languages() as $code => $language ) {
			$urls[ $code ] = Langosh::url_lang( get_post_type_archive_link( get_query_var( 'post_type' ) ), $code );
		}
	}

	// Pages that are not translated objects (search, dates, posts of a type without languages).
	if ( $all && ! $urls && ! is_404() && isset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$here = set_url_scheme( 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );

		foreach ( Langosh::languages() as $code => $language ) {
			$urls[ $code ] = Langosh::url_lang( esc_url_raw( $here ), $code );
		}
	}

	$cache[ $slot ] = apply_filters( 'langosh_alternates', $urls, $all );

	return $cache[ $slot ];
}

/**
 * hreflang links, with x-default pointing at the default language.
 */
add_action(
	'wp_head',
	function () {
		if ( is_paged() ) {
			return;
		}

		$urls = langosh_alternates();

		if ( count( $urls ) < 2 ) {
			return;
		}

		foreach ( $urls as $code => $url ) {
			printf( '<link rel="alternate" hreflang="%s" href="%s">' . "\n", esc_attr( $code ), esc_url( $url ) );
		}
		if ( isset( $urls[ Langosh::default_lang() ] ) ) {
			printf( '<link rel="alternate" hreflang="x-default" href="%s">' . "\n", esc_url( $urls[ Langosh::default_lang() ] ) );
		}

		// Social networks are told about the other languages next to the og:locale of the SEO plugin.
		$languages = Langosh::languages();

		foreach ( $urls as $code => $url ) {
			if ( $code !== Langosh::current() ) {
				printf( '<meta property="og:locale:alternate" content="%s">' . "\n", esc_attr( langosh_og_locale( $languages[ $code ]['locale'] ) ) );
			}
		}
	},
	2
);

/**
 * Locale in the form Open Graph expects: hr → hr_HR, en_US stays.
 */
function langosh_og_locale( $locale ) {
	$locale = str_replace( '-', '_', (string) $locale );

	return false === strpos( $locale, '_' ) ? strtolower( $locale ) . '_' . strtoupper( $locale ) : $locale;
}

/**
 * A post of a type that is not translated is one document shown under every language
 * folder (/pojam/kolagen/ and /en/pojam/kolagen/). Search engines are pointed at its
 * address in the default language – whatever language the request is in, and whatever
 * language the request was in when the SEO plugin first stored the address.
 */
function langosh_neutral_canonical( $url ) {
	if ( ! $url || ! is_string( $url ) || ! Langosh::$front || ! is_singular() ) {
		return $url;
	}

	$id = (int) get_queried_object_id();

	if ( ! $id || Langosh::get( $id ) ) {
		return $url;
	}

	return Langosh::url_lang( $url, Langosh::default_lang() );
}
add_filter( 'get_canonical_url', 'langosh_neutral_canonical' );
add_filter( 'wpseo_canonical', 'langosh_neutral_canonical' );
add_filter( 'wpseo_opengraph_url', 'langosh_neutral_canonical' );
add_filter( 'rank_math/frontend/canonical', 'langosh_neutral_canonical' );
add_filter( 'rank_math/opengraph/url', 'langosh_neutral_canonical' );

/**
 * Yoast SEO stores the address of a post the first time it needs it. For a post without
 * a language that would be the address of whichever language asked first; the stored one
 * is always that of the default language, so schema and breadcrumbs agree with the canonical.
 */
add_filter(
	'wpseo_should_save_indexable',
	function ( $save, $indexable = null ) {
		if ( is_object( $indexable ) && isset( $indexable->object_type, $indexable->object_id ) && 'post' === $indexable->object_type && ! empty( $indexable->permalink ) && ! Langosh::get( (int) $indexable->object_id ) ) {
			$indexable->permalink = Langosh::url_lang( $indexable->permalink, Langosh::default_lang() );
		}

		return $save;
	},
	10,
	2
);

/**
 * Languages for a switcher. A language that does not have the current page links to its home page.
 *
 * @return array[] code => code, name, locale, url, active, missing
 */
function langosh_switcher_items( $skip_missing = false ) {
	$urls  = did_action( 'wp' ) ? langosh_alternates( true ) : array();
	$items = array();

	foreach ( Langosh::languages() as $code => $language ) {
		$missing = ! isset( $urls[ $code ] );

		if ( $missing && $skip_missing && $code !== Langosh::current() ) {
			continue;
		}

		$items[ $code ] = array(
			'code'    => $code,
			'name'    => $language['name'],
			'locale'  => $language['locale'],
			'url'     => $missing ? Langosh::home( $code ) : $urls[ $code ],
			'active'  => $code === Langosh::current(),
			'missing' => $missing,
		);
	}

	return $items;
}

/**
 * Prints the switcher as a plain list; the theme styles it (.langosh-switcher).
 */
function langosh_switcher( $args = array() ) {
	$args  = wp_parse_args(
		$args,
		array(
			'skip_missing' => false,
			'label'        => 'code', // 'code' (HR) or 'name' (Hrvatski)
			'class'        => 'langosh-switcher',
			'echo'         => true,
		)
	);
	$items = langosh_switcher_items( $args['skip_missing'] );
	$html  = '';

	if ( count( $items ) > 1 ) {
		$html = '<ul class="' . esc_attr( $args['class'] ) . '">';
		foreach ( $items as $item ) {
			$html .= sprintf(
				'<li><a href="%1$s" lang="%2$s" hreflang="%2$s"%3$s>%4$s</a></li>',
				esc_url( $item['url'] ),
				esc_attr( $item['code'] ),
				$item['active'] ? ' aria-current="true"' : '',
				esc_html( 'name' === $args['label'] ? $item['name'] : strtoupper( $item['code'] ) )
			);
		}
		$html .= '</ul>';
	}

	if ( $args['echo'] ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	return $html;
}

add_shortcode(
	'langosh_switcher',
	function ( $atts ) {
		return langosh_switcher( array_merge( (array) $atts, array( 'echo' => false ) ) );
	}
);

/**
 * WordPress' own sitemap lists the posts and terms of every language.
 */
foreach ( array( 'wp_sitemaps_posts_query_args', 'wp_sitemaps_taxonomies_query_args' ) as $langosh_hook ) {
	add_filter(
		$langosh_hook,
		function ( $args ) {
			$args['lang'] = 'all';
			return $args;
		}
	);
}
unset( $langosh_hook );

/**
 * Yoast SEO keeps the titles of pages that are not posts (404, search, date, author and
 * post type archives, the latest posts as front page) in its own table, as they were when
 * first built – in the default language. In another language the template translated
 * under Langosh → Tekstovi is used, unless the stored title was changed by hand.
 */
add_filter(
	'wpseo_frontend_presenters',
	function ( $presenters, $context = null ) {
		$lang = Langosh::current();

		if ( $lang === Langosh::default_lang() || empty( $context->presentation->model ) ) {
			return $presenters;
		}

		$model = $context->presentation->model;
		$slots = array(
			'home-page'         => 'home-wpseo',
			'date-archive'      => 'archive-wpseo',
			'user'              => 'author-wpseo',
			'post-type-archive' => 'ptarchive-' . $model->object_sub_type,
			'system-page'       => '404' === $model->object_sub_type ? '404-wpseo' : 'search-wpseo',
		);

		if ( ! isset( $slots[ $model->object_type ] ) ) {
			return $presenters;
		}

		$original   = (array) Langosh::raw_option( 'wpseo_titles' );
		$translated = (array) get_option( 'wpseo_titles' );

		foreach ( array(
			'title'            => array( 'title-', 'title' ),
			'meta_description' => array( 'metadesc-', 'description' ),
		) as $property => $names ) {
			$key    = $names[0] . $slots[ $model->object_type ];
			$stored = (string) $model->{$names[1]};

			if ( ! empty( $translated[ $key ] ) && isset( $original[ $key ] ) && $translated[ $key ] !== $original[ $key ] && ( '' === $stored || $stored === $original[ $key ] ) ) {
				$context->presentation->{$property} = $translated[ $key ];
			}
		}

		return $presenters;
	},
	10,
	2
);

/**
 * Rank Math builds its sitemaps with queries of its own; they list every language, and
 * each address is in the language of its post or term.
 */
add_filter(
	'rank_math/sitemap/enable_caching',
	function ( $cache ) {
		Langosh::$no_filter = true;
		return $cache;
	}
);
