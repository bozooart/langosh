<?php
/**
 * The hooks themes written for WPML call (wpml_current_language, wpml_object_id…),
 * answered by Langosh so such code keeps working. ACF is in inc/acf.php.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'wpml_current_language', array( 'Langosh', 'current' ) );
add_filter( 'wpml_default_language', array( 'Langosh', 'default_lang' ) );

add_action(
	'wpml_switch_language',
	function ( $lang = null ) {
		Langosh::switch_to( $lang );
	}
);

/**
 * 'page', 'post_page', 'category', 'tax_category' → 'post' or 'term'.
 */
function langosh_compat_type( $type ) {
	$type = (string) $type;

	if ( 0 === strpos( $type, 'tax_' ) ) {
		return 'term';
	}

	return 0 !== strpos( $type, 'post_' ) && taxonomy_exists( $type ) ? 'term' : 'post';
}

/**
 * WPML identifies a term by its term_taxonomy_id.
 */
function langosh_compat_element( $id, $element_type ) {
	if ( 0 === strpos( (string) $element_type, 'tax_' ) ) {
		$term = get_term_by( 'term_taxonomy_id', (int) $id );
		return $term ? (int) $term->term_id : 0;
	}

	return (int) $id;
}

add_filter(
	'wpml_object_id',
	function ( $id, $type = 'post', $return_original = false, $lang = null ) {
		if ( ! $id ) {
			return $id;
		}

		$found = Langosh::translate_id( (int) $id, $lang ? $lang : Langosh::current(), langosh_compat_type( $type ), (bool) $return_original );

		return $found ? $found : null;
	},
	10,
	4
);

add_filter(
	'wpml_element_language_code',
	function ( $code, $args ) {
		$type = isset( $args['element_type'] ) ? $args['element_type'] : 'post';
		$lang = isset( $args['element_id'] ) ? Langosh::lang_of( langosh_compat_element( $args['element_id'], $type ), langosh_compat_type( $type ) ) : '';

		return $lang ? $lang : $code;
	},
	10,
	2
);

add_filter(
	'wpml_post_language_details',
	function ( $details, $post_id = 0 ) {
		$lang      = Langosh::lang_of( $post_id ? $post_id : get_the_ID() );
		$languages = Langosh::languages();

		if ( ! $lang ) {
			return $details;
		}

		return array(
			'language_code'      => $lang,
			'locale'             => $languages[ $lang ]['locale'],
			'text_direction'     => false,
			'display_name'       => $languages[ $lang ]['name'],
			'native_name'        => $languages[ $lang ]['name'],
			'different_language' => $lang !== Langosh::current(),
		);
	},
	10,
	2
);

add_filter(
	'wpml_element_trid',
	function ( $trid, $id, $element_type = 'post_post' ) {
		$row = Langosh::get( langosh_compat_element( $id, $element_type ), langosh_compat_type( $element_type ) );
		return $row ? $row[1] : $trid;
	},
	10,
	3
);

add_action(
	'wpml_set_element_language_details',
	function ( $args ) {
		if ( empty( $args['element_id'] ) || empty( $args['language_code'] ) || empty( $args['element_type'] ) ) {
			return;
		}

		Langosh::set(
			langosh_compat_element( $args['element_id'], $args['element_type'] ),
			langosh_compat_type( $args['element_type'] ),
			$args['language_code'],
			empty( $args['trid'] ) ? 0 : (int) $args['trid']
		);
	}
);

add_filter(
	'wpml_active_languages',
	function ( $languages, $args = array() ) {
		$args  = wp_parse_args( $args, array( 'skip_missing' => 0 ) );
		$items = array();

		foreach ( langosh_switcher_items( (bool) $args['skip_missing'] ) as $code => $item ) {
			$items[ $code ] = array(
				'code'             => $code,
				'language_code'    => $code,
				'id'               => $code,
				'native_name'      => $item['name'],
				'translated_name'  => $item['name'],
				'default_locale'   => $item['locale'],
				'tag'              => str_replace( '_', '-', $item['locale'] ),
				'active'           => $item['active'] ? 1 : 0,
				'missing'          => $item['missing'] ? 1 : 0,
				'url'              => $item['url'],
				'country_flag_url' => '',
			);
		}

		return $items;
	},
	10,
	2
);

add_filter(
	'wpml_home_url',
	function () {
		return Langosh::home();
	}
);

add_filter(
	'wpml_permalink',
	function ( $url, $lang = null ) {
		return Langosh::url_lang( $url, $lang ? $lang : Langosh::current() );
	},
	10,
	2
);

add_filter(
	'wpml_is_translated_post_type',
	function ( $translated, $post_type ) {
		return (bool) Langosh::post_mode( $post_type );
	},
	10,
	2
);

add_filter(
	'wpml_is_translated_taxonomy',
	function ( $translated, $taxonomy ) {
		return Langosh::is_translated_taxonomy( $taxonomy );
	},
	10,
	2
);

add_filter(
	'wpml_translate_single_string',
	function ( $text, $domain, $name, $lang = null ) {
		return langosh_t( $domain . '/' . $name, $text, $lang ? $lang : '' );
	},
	10,
	4
);

add_action(
	'wpml_register_single_string',
	function ( $domain, $name, $text ) {
		if ( did_action( 'init' ) ) {
			Langosh_Strings::register( $domain . '/' . $name, $text );
		}
	},
	10,
	3
);
