<?php
/**
 * ACF: options pages per language, fields that hold the same value in every translation
 * (chosen on the field itself, so the choice travels with the field group and its JSON)
 * and fields that point at other posts or terms.
 */

defined( 'ABSPATH' ) || exit;

/**
 * An options page saved under "options" is kept per language (options_en_…), as ACF
 * does on its own once it knows the two languages.
 */
add_filter( 'acf/settings/default_language', array( 'Langosh', 'default_lang' ) );
add_filter( 'acf/settings/current_language', array( 'Langosh', 'current' ) );

/**
 * Field types whose value is the ID of a post or term (or a list of them).
 */
function langosh_acf_related_types() {
	return array( 'post_object', 'relationship', 'page_link', 'taxonomy' );
}

/**
 * The IDs a field points at, in a language. An ID without a version in that language is kept.
 */
function langosh_acf_translate_ids( $value, $field, $lang ) {
	$type = 'taxonomy' === $field['type'] ? 'term' : 'post';

	if ( ! $value || ( 'term' === $type && ! Langosh::is_translated_taxonomy( isset( $field['taxonomy'] ) ? $field['taxonomy'] : '' ) ) ) {
		return $value;
	}

	if ( is_array( $value ) ) {
		Langosh::prime_groups( array_filter( $value, 'is_numeric' ), $type );

		foreach ( $value as $i => $id ) {
			// A page link may also hold the address of an archive.
			$value[ $i ] = is_numeric( $id ) ? (string) Langosh::translate_id( (int) $id, $lang, $type, true ) : $id;
		}

		return $value;
	}

	return is_numeric( $value ) ? (string) Langosh::translate_id( (int) $value, $lang, $type, true ) : $value;
}

/**
 * On the site a field that points at a page gives the version of that page in the
 * language of the request. A page chosen once on an options page shared by all languages
 * (the contact page, the privacy page) therefore links correctly in each of them.
 */
function langosh_acf_load_related( $value, $post_id, $field ) {
	return Langosh::$front ? langosh_acf_translate_ids( $value, $field, Langosh::current() ) : $value;
}

foreach ( langosh_acf_related_types() as $langosh_type ) {
	add_filter( 'acf/load_value/type=' . $langosh_type, 'langosh_acf_load_related', 20, 3 );
}
unset( $langosh_type );

/**
 * A new translation starts as a copy of the original; what the copy points at is switched
 * to the versions in its own language.
 */
add_action(
	'langosh_duplicate_post',
	function ( $new, $post, $lang ) {
		if ( ! function_exists( 'acf_get_field' ) ) {
			return;
		}

		foreach ( (array) get_post_meta( $new ) as $key => $values ) {
			$reference = '_' === $key[0] ? '' : get_post_meta( $new, '_' . $key, true );

			if ( ! is_string( $reference ) || 0 !== strpos( $reference, 'field_' ) ) {
				continue;
			}

			$field = acf_get_field( $reference );

			if ( ! $field || ! in_array( $field['type'], langosh_acf_related_types(), true ) ) {
				continue;
			}

			$old = maybe_unserialize( $values[0] );
			$now = langosh_acf_translate_ids( $old, $field, $lang );

			if ( $now !== $old ) {
				update_post_meta( $new, $key, wp_slash( $now ) );
			}
		}
	},
	10,
	3
);

/* -------------------------------------------------------------------------
 * Fields that are the same in every translation
 * ---------------------------------------------------------------------- */

add_action(
	'acf/render_field_settings',
	function ( $field ) {
		if ( count( Langosh::languages() ) < 2 ) {
			return;
		}

		acf_render_field_setting(
			$field,
			array(
				'label'        => 'Ista vrijednost u svim jezicima',
				'instructions' => 'Langosh: pri spremanju se vrijednost upisuje u sve prijevode (datumi, cijene, brojevi). Vrijedi za polja prve razine; ponavljač, grupa i fleksibilni sadržaj kopiraju se cijeli.',
				'name'         => 'langosh_copy',
				'type'         => 'true_false',
				'ui'           => 1,
			),
			true
		);
	}
);

/**
 * Fields of a post marked as the same in every translation, by name.
 */
function langosh_acf_copy_fields( $post_id ) {
	$fields = array();

	foreach ( acf_get_field_groups( array( 'post_id' => $post_id ) ) as $group ) {
		foreach ( (array) acf_get_fields( $group ) as $field ) {
			if ( '' === (string) $field['name'] ) {
				continue;
			}
			if ( ! empty( $field['langosh_copy'] ) ) {
				$fields[ $field['name'] ] = $field;
				continue;
			}

			// A marked field inside a repeater is listed as "repeater_%_field".
			if ( 'repeater' === $field['type'] && ! empty( $field['sub_fields'] ) ) {
				foreach ( $field['sub_fields'] as $sub ) {
					if ( ! empty( $sub['langosh_copy'] ) && '' !== (string) $sub['name'] ) {
						$fields[ $field['name'] . '_%_' . $sub['name'] ] = $sub;
					}
				}
			}
		}
	}

	return $fields;
}

/**
 * Writes those fields of a post to all its translations.
 */
function langosh_acf_sync( $post_id ) {
	global $wpdb;

	if ( ! is_numeric( $post_id ) ) {
		return;
	}

	$post_id = (int) $post_id;
	$others  = array_diff( Langosh::translations( $post_id ), array( $post_id ) );

	if ( ! $others ) {
		return;
	}

	foreach ( langosh_acf_copy_fields( $post_id ) as $name => $field ) {
		// A field of a repeater: row by row, into the rows the translation has.
		if ( false !== strpos( $name, '_%_' ) ) {
			list( $repeater, $sub ) = explode( '_%_', $name, 2 );

			foreach ( $others as $other ) {
				$rows = min( (int) get_post_meta( $post_id, $repeater, true ), (int) get_post_meta( $other, $repeater, true ) );

				for ( $i = 0; $i < $rows; $i++ ) {
					foreach ( array( "{$repeater}_{$i}_{$sub}", "_{$repeater}_{$i}_{$sub}" ) as $key ) {
						if ( metadata_exists( 'post', $post_id, $key ) ) {
							update_post_meta( $other, $key, wp_slash( get_post_meta( $post_id, $key, true ) ) );
						}
					}
				}
			}

			continue;
		}

		$where =$wpdb->prepare( 'meta_key IN ( %s, %s )', $name, '_' . $name );

		// A field with rows keeps them under "name_0_subfield".
		if ( in_array( $field['type'], array( 'repeater', 'group', 'flexible_content' ), true ) ) {
			$like   = $wpdb->esc_like( $name . '_' ) . '%';
			$where .= $wpdb->prepare( ' OR meta_key LIKE %s OR meta_key LIKE %s', $like, '_' . $like );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM $wpdb->postmeta WHERE post_id = %d AND ( $where )", $post_id ) );

		foreach ( $others as $other ) {
			// phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM $wpdb->postmeta WHERE post_id = %d AND ( $where )", $other ) ) as $key ) {
				delete_post_meta( $other, $key );
			}
			foreach ( $rows as $row ) {
				add_post_meta( $other, $row->meta_key, wp_slash( maybe_unserialize( $row->meta_value ) ) );
			}
		}
	}
}
add_action( 'acf/save_post', 'langosh_acf_sync', 20 );

/* -------------------------------------------------------------------------
 * Location rules
 * ---------------------------------------------------------------------- */

/**
 * A field group shown on one page ("Page is equal to Kontakt", "Page parent is equal to
 * Prostori") is shown on the translations of that page as well, so one group serves all languages.
 */
add_filter(
	'acf/location/rule_match',
	function ( $match, $rule, $screen ) {
		if ( empty( $rule['param'] ) || ! in_array( $rule['param'], array( 'page', 'post', 'page_parent' ), true ) || empty( $screen['post_id'] ) || ! is_numeric( $screen['post_id'] ) || ! is_numeric( $rule['value'] ) ) {
			return $match;
		}

		$versions = Langosh::translations( (int) $rule['value'] );

		if ( count( $versions ) < 2 ) {
			return $match;
		}

		$id = (int) $screen['post_id'];

		if ( 'page_parent' === $rule['param'] ) {
			$id = isset( $screen['page_parent'] ) ? (int) $screen['page_parent'] : (int) wp_get_post_parent_id( $id );
		}

		$same = in_array( $id, $versions, true );

		return '!=' === $rule['operator'] ? ! $same : $same;
	},
	20,
	3
);

/* -------------------------------------------------------------------------
 * Options pages
 * ---------------------------------------------------------------------- */

/**
 * Says which language an options page is being edited in, with links to the others.
 */
add_action(
	'admin_notices',
	function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slug = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$page = $slug && function_exists( 'acf_get_options_page' ) ? acf_get_options_page( $slug ) : null;

		if ( ! $page || count( Langosh::languages() ) < 2 ) {
			return;
		}

		if ( ! in_array( $page['post_id'], array( 'options', 'option' ), true ) ) {
			echo '<div class="notice notice-info"><p>Ove su postavke zajedničke svim jezicima.</p></div>';
			return;
		}

		$links = array();

		foreach ( Langosh::languages() as $code => $language ) {
			$links[] = $code === Langosh::current()
				? '<strong>' . esc_html( $language['name'] ) . '</strong>'
				: '<a href="' . esc_url( add_query_arg( 'langosh_admin', $code ) ) . '">' . esc_html( $language['name'] ) . '</a>';
		}

		echo '<div class="notice notice-info"><p>Ovi se tekstovi spremaju zasebno za svaki jezik. Uređujete: ' . implode( ' · ', $links ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
);
