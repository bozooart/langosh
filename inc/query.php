<?php
/**
 * Queries: lists of posts and terms show one language; new posts and terms get the
 * language they were created in; chosen custom fields stay the same in every translation.
 *
 * The language is one join on the primary key of the plugin's table. Queries for types
 * that are not translated (menu items, media, ACF fields, forms) are left untouched.
 * Pass 'lang' => 'all' (or a language code) to a query to choose for yourself.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Language a query is narrowed to: its own 'lang' argument, else the language of the request.
 */
function langosh_query_lang( $lang ) {
	if ( 'all' === $lang ) {
		return '';
	}

	return Langosh::is_lang( $lang ) ? $lang : Langosh::filter_lang();
}

/**
 * SQL condition for one language on the joined table alias.
 *
 * @param string $alias    alias of the language table
 * @param string $lang     language code
 * @param string $fallback SQL condition that picks the rows whose original shows until a
 *                         translation exists ("wp_posts.post_type IN ( 'product' )"), or ''
 * @param string $type     'post' or 'term'
 */
function langosh_lang_where( $alias, $lang, $fallback = '', $type = 'post' ) {
	global $wpdb;

	$where = $wpdb->prepare( "$alias.lang IS NULL OR $alias.lang = %s", $lang ); // phpcs:ignore WordPress.DB.PreparedSQL

	if ( $fallback && $lang !== Langosh::default_lang() ) {
		// phpcs:ignore WordPress.DB.PreparedSQL
		$where .= $wpdb->prepare( " OR ( $fallback AND $alias.lang = %s AND NOT EXISTS ( SELECT 1 FROM " . Langosh::table() . " lgsh_x WHERE lgsh_x.grp = $alias.grp AND lgsh_x.type = %s AND lgsh_x.lang = %s ) )", Langosh::default_lang(), $type, $lang );
	}

	return ' AND ( ' . $where . ' )';
}

/**
 * Quoted list of the queried post types that fall back to the original, '' when none does.
 */
function langosh_fallback_types( $post_types = null ) {
	$list = array();

	foreach ( Langosh::settings()['post_types'] as $type => $mode ) {
		if ( 2 === (int) $mode && ( null === $post_types || in_array( $type, $post_types, true ) ) ) {
			$list[] = "'" . esc_sql( $type ) . "'";
		}
	}

	return implode( ',', $list );
}

add_filter(
	'posts_clauses',
	function ( $clauses, $query ) {
		global $wpdb;

		$lang = langosh_query_lang( $query->get( 'lang' ) );

		if ( ! $lang ) {
			return $clauses;
		}

		$types = $query->get( 'post_type' );
		$known = null;

		// A query for named post types is skipped when none of them is translated.
		if ( $types && 'any' !== $types ) {
			$known = (array) $types;

			if ( ! array_filter( array_map( array( 'Langosh', 'post_mode' ), $known ) ) ) {
				return $clauses;
			}
		}

		$clauses['join']  .= ' LEFT JOIN ' . Langosh::table() . " lgsh ON lgsh.type = 'post' AND lgsh.id = {$wpdb->posts}.ID";
		$fallback          = langosh_fallback_types( $known );
		$clauses['where'] .= langosh_lang_where( 'lgsh', $lang, $fallback ? "{$wpdb->posts}.post_type IN ( $fallback )" : '' );

		return $clauses;
	},
	10,
	2
);

/**
 * Posts of a result are about to be linked to; their languages are read in one query.
 */
add_filter(
	'the_posts',
	function ( $posts ) {
		if ( $posts && is_array( $posts ) ) {
			$ids = array();

			foreach ( $posts as $post ) {
				if ( is_object( $post ) && Langosh::post_mode( $post->post_type ) ) {
					$ids[] = $post->ID;
				}
			}

			Langosh::prime( $ids );

			// Links to posts standing in for a missing translation need their group as well.
			if ( Langosh::$front && Langosh::current() !== Langosh::default_lang() ) {
				Langosh::prime_groups( $ids );
			}
		}

		return $posts;
	}
);

add_filter(
	'terms_clauses',
	function ( $clauses, $taxonomies, $args ) {
		$lang = langosh_query_lang( isset( $args['lang'] ) ? $args['lang'] : '' );

		// Terms of a given post, or terms asked for by ID, are what they are in any language.
		if ( ! $lang || ! empty( $args['object_ids'] ) || ! empty( $args['include'] ) || ! empty( $args['term_taxonomy_id'] ) ) {
			return $clauses;
		}
		if ( ! array_filter( array_map( array( 'Langosh', 'is_translated_taxonomy' ), (array) $taxonomies ) ) ) {
			return $clauses;
		}

		$fallback = array();

		foreach ( (array) $taxonomies as $taxonomy ) {
			if ( 2 === Langosh::tax_mode( $taxonomy ) ) {
				$fallback[] = "'" . esc_sql( $taxonomy ) . "'";
			}
		}

		$clauses['join']  .= ' LEFT JOIN ' . Langosh::table() . " lgsh ON lgsh.type = 'term' AND lgsh.id = t.term_id";
		$clauses['where'] .= langosh_lang_where( 'lgsh', $lang, $fallback ? 'tt.taxonomy IN ( ' . implode( ',', $fallback ) . ' )' : '', 'term' );

		return $clauses;
	},
	10,
	3
);

/**
 * Posts shown in place of a missing translation carry the terms of their own language.
 * A list narrowed to a term of the request's language (the English category page) also
 * takes the posts filed under that term's version in the default language.
 */
add_action(
	'parse_tax_query',
	function ( $query ) {
		$lang = langosh_query_lang( $query->get( 'lang' ) );

		if ( ! $lang || $lang === Langosh::default_lang() || empty( $query->tax_query->queries ) ) {
			return;
		}

		$types = $query->get( 'post_type' );
		$types = $types && 'any' !== $types ? (array) $types : null;

		foreach ( $query->tax_query->queries as $i => $clause ) {
			if ( ! is_array( $clause ) || empty( $clause['taxonomy'] ) || empty( $clause['terms'] ) || ! Langosh::is_translated_taxonomy( $clause['taxonomy'] ) ) {
				continue;
			}
			if ( isset( $clause['operator'] ) && ! in_array( strtoupper( $clause['operator'] ), array( 'IN', 'NOT IN' ), true ) ) {
				continue;
			}

			$taxonomy = get_taxonomy( $clause['taxonomy'] );

			if ( ! $taxonomy || ! langosh_fallback_types( null === $types ? (array) $taxonomy->object_type : $types ) ) {
				continue;
			}

			$field = isset( $clause['field'] ) ? $clause['field'] : 'term_id';
			$found = get_terms(
				array(
					'taxonomy'   => $clause['taxonomy'],
					'hide_empty' => false,
					'fields'     => 'ids',
					'lang'       => $lang,
					'term_id' === $field ? 'include' : $field => $clause['terms'],
				)
			);

			if ( is_wp_error( $found ) || ! $found ) {
				continue;
			}

			$ids = array();

			foreach ( $found as $id ) {
				$ids[] = (int) $id;
				$own   = Langosh::translate_id( (int) $id, Langosh::default_lang(), 'term' );

				if ( $own ) {
					$ids[] = $own;
				}
			}

			$query->tax_query->queries[ $i ]['terms'] = array_values( array_unique( $ids ) );
			$query->tax_query->queries[ $i ]['field'] = 'term_id';
		}
	}
);

/**
 * Terms printed with such a post (breadcrumbs, category lists) are given in the language
 * of the request where they have a version in it.
 */
function langosh_display_terms( $terms, $taxonomy ) {
	if ( ! is_array( $terms ) || ! $terms || ! Langosh::$front || Langosh::$no_filter || Langosh::current() === Langosh::default_lang() || ! Langosh::is_translated_taxonomy( $taxonomy ) ) {
		return $terms;
	}

	$lang = Langosh::current();

	foreach ( $terms as $i => $term ) {
		if ( ! $term instanceof WP_Term ) {
			return $terms;
		}

		$other = Langosh::translate_id( $term->term_id, $lang, 'term' );

		if ( $other && $other !== (int) $term->term_id ) {
			$other = get_term( $other, $taxonomy );

			if ( $other instanceof WP_Term ) {
				$terms[ $i ] = $other;
			}
		}
	}

	return $terms;
}

add_filter(
	'get_the_terms',
	function ( $terms, $post_id, $taxonomy ) {
		return langosh_display_terms( $terms, $taxonomy );
	},
	10,
	3
);

/**
 * Terms of a list are about to be linked to; their languages are read in one query.
 */
add_filter(
	'get_terms',
	function ( $terms, $taxonomies ) {
		if ( $terms && is_array( $terms ) && array_filter( array_map( array( 'Langosh', 'is_translated_taxonomy' ), (array) $taxonomies ) ) ) {
			$ids = array();

			foreach ( $terms as $term ) {
				if ( $term instanceof WP_Term ) {
					$ids[] = $term->term_id;
				} elseif ( is_numeric( $term ) ) {
					$ids[] = (int) $term;
				}
			}

			Langosh::prime( $ids, 'term' );

			if ( Langosh::$front && Langosh::current() !== Langosh::default_lang() ) {
				Langosh::prime_groups( $ids, 'term' );
			}
		}

		return $terms;
	},
	10,
	2
);

/**
 * Previous / next post and the archive lists stay within the language.
 */
foreach ( array( 'previous', 'next' ) as $langosh_side ) {
	add_filter(
		"get_{$langosh_side}_post_join",
		function ( $join ) {
			return Langosh::filter_lang() ? $join . ' LEFT JOIN ' . Langosh::table() . " lgsh ON lgsh.type = 'post' AND lgsh.id = p.ID" : $join;
		}
	);
	add_filter(
		"get_{$langosh_side}_post_where",
		function ( $where ) {
			return Langosh::filter_lang() ? $where . langosh_lang_where( 'lgsh', Langosh::filter_lang() ) : $where;
		}
	);
}
unset( $langosh_side );

add_filter(
	'getarchives_join',
	function ( $join ) {
		global $wpdb;
		return Langosh::filter_lang() ? $join . ' LEFT JOIN ' . Langosh::table() . " lgsh ON lgsh.type = 'post' AND lgsh.id = {$wpdb->posts}.ID" : $join;
	}
);

add_filter(
	'getarchives_where',
	function ( $where ) {
		return Langosh::filter_lang() ? $where . langosh_lang_where( 'lgsh', Langosh::filter_lang() ) : $where;
	}
);

/* -------------------------------------------------------------------------
 * New and deleted content
 * ---------------------------------------------------------------------- */

/**
 * A new post is in the language it was created in.
 */
add_action(
	'wp_insert_post',
	function ( $post_id, $post ) {
		if ( ! Langosh::post_mode( $post->post_type ) || Langosh::get( $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$asked = isset( $_REQUEST['langosh_lang'] ) ? (string) $_REQUEST['langosh_lang'] : '';

		Langosh::set( $post_id, 'post', Langosh::is_lang( $asked ) ? $asked : Langosh::current() );
	},
	10,
	2
);

add_action(
	'created_term',
	function ( $term_id, $tt_id, $taxonomy ) {
		if ( ! Langosh::is_translated_taxonomy( $taxonomy ) || Langosh::get( $term_id, 'term' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$asked = isset( $_POST['langosh_lang'] ) ? (string) $_POST['langosh_lang'] : '';

		Langosh::set( $term_id, 'term', Langosh::is_lang( $asked ) ? $asked : Langosh::current() );
	},
	5,
	3
);

add_action(
	'deleted_post',
	function ( $post_id ) {
		Langosh::delete( $post_id );
	}
);

add_action(
	'delete_term',
	function ( $term_id ) {
		Langosh::delete( $term_id, 'term' );
	}
);

/**
 * Custom fields listed under "Polja koja se kopiraju" (dates, prices, places left) are
 * written to every translation when a post is saved, so they are entered once.
 */
add_action(
	'save_post',
	function ( $post_id, $post ) {
		static $busy = false;

		$keys = Langosh::settings()['copy_meta'];

		if ( $busy || ! $keys || ! Langosh::post_mode( $post->post_type ) || wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		$busy = true;

		foreach ( Langosh::translations( $post_id ) as $other ) {
			if ( $other === (int) $post_id ) {
				continue;
			}
			foreach ( $keys as $key ) {
				// The second key is where ACF notes which field the value belongs to.
				foreach ( array( $key, '_' . $key ) as $meta_key ) {
					if ( metadata_exists( 'post', $post_id, $meta_key ) ) {
						update_post_meta( $other, $meta_key, wp_slash( get_post_meta( $post_id, $meta_key, true ) ) );
					}
				}
			}
		}

		$busy = false;
	},
	100,
	2
);
