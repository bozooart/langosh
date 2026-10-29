<?php
/**
 * Takes over a site translated with WPML: languages, which content is translated, the
 * language and translations of every post and term, menus per language and the custom
 * fields WPML copied between translations.
 *
 * Only reads WPML's tables and settings; nothing of WPML is changed or deleted, so the
 * import can be repeated and WPML can be switched back on.
 */

defined( 'ABSPATH' ) || exit;

function langosh_wpml_available() {
	global $wpdb;

	return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'icl_translations' ) ) );
}

/**
 * WPML codes that become another code here.
 *
 * Langosh uses the code as the folder in the address. WPML 5 keeps regional languages
 * under codes like `en-us` but puts their `display_code` (`en`) in the address, so
 * taking the code over as is would move /en/ to /en-us/. A language whose display code
 * is set, valid and not the code of another language takes that code.
 *
 * @return array WPML code => Langosh code, only for codes that change
 */
function langosh_wpml_codes() {
	global $wpdb;
	static $map = null;

	if ( null !== $map ) {
		return $map;
	}

	$map = array();

	if ( ! langosh_wpml_available() ) {
		return $map;
	}

	$table = $wpdb->prefix . 'icl_languages';
	// phpcs:ignore WordPress.DB.PreparedSQL
	$has  = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", 'display_code' ) );
	$rows = $has ? (array) $wpdb->get_results( "SELECT code, display_code FROM $table WHERE active = 1" ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL

	$taken = wp_list_pluck( $rows, 'code' );

	foreach ( $rows as $row ) {
		$to = strtolower( trim( (string) $row->display_code ) );

		if ( '' !== $to && $to !== $row->code && preg_match( '/^[a-z_-]{2,10}$/', $to ) && ! in_array( $to, $taken, true ) ) {
			$map[ $row->code ] = $to;
			$taken[]           = $to;
		}
	}

	return $map;
}

/**
 * @param string $code WPML language code.
 * @return string the code it has here
 */
function langosh_wpml_code( $code ) {
	$map = langosh_wpml_codes();

	return isset( $map[ $code ] ) ? $map[ $code ] : (string) $code;
}

/**
 * SQL expression giving the Langosh code of a column holding a WPML code.
 *
 * @param string $column e.g. "i.language_code"
 * @return string
 */
function langosh_wpml_code_sql( $column ) {
	$map = langosh_wpml_codes();

	if ( ! $map ) {
		return $column;
	}

	$sql = "CASE $column";

	foreach ( $map as $from => $to ) {
		$sql .= " WHEN '" . esc_sql( $from ) . "' THEN '" . esc_sql( $to ) . "'";
	}

	return "$sql ELSE $column END";
}

/**
 * @return array|WP_Error counts of what was taken over
 */
function langosh_import_wpml() {
	global $wpdb;

	if ( ! langosh_wpml_available() ) {
		return new WP_Error( 'langosh_no_wpml', 'U bazi nema WPML tablica.' );
	}

	Langosh::install();

	$wpml     = (array) get_option( 'icl_sitepress_settings', array() );
	$settings = Langosh::settings();
	$t        = Langosh::table();

	/* Languages --------------------------------------------------------- */

	$languages = array();
	$rows      = $wpdb->get_results(
		"SELECT l.code, l.default_locale, l.english_name, n.name
		FROM {$wpdb->prefix}icl_languages l
		LEFT JOIN {$wpdb->prefix}icl_languages_translations n ON n.language_code = l.code AND n.display_language_code = l.code
		WHERE l.active = 1"
	);

	foreach ( (array) $rows as $row ) {
		$languages[ $row->code ] = array(
			'code'   => $row->code,
			'locale' => $row->default_locale ? $row->default_locale : $row->code,
			'name'   => $row->name ? $row->name : $row->english_name,
		);
	}

	if ( ! $languages ) {
		return new WP_Error( 'langosh_no_languages', 'WPML nema aktivnih jezika.' );
	}

	$default = isset( $wpml['default_language'], $languages[ $wpml['default_language'] ] ) ? $wpml['default_language'] : (string) key( $languages );

	// The default language first: it is the one listed first everywhere.
	$languages = array( $default => $languages[ $default ] ) + $languages;

	// WPML's codes stay for reading its tables; from here on languages carry the codes they have here.
	$wpml_default = $default;
	$wpml_codes   = array_keys( $languages );
	$default      = langosh_wpml_code( $default );
	$mapped       = array();

	foreach ( $languages as $code => $language ) {
		$language['code']                        = langosh_wpml_code( $code );
		$mapped[ langosh_wpml_code( $code ) ] = $language;
	}

	$languages = $mapped;

	/* What is translated -------------------------------------------------- */

	$post_types = array();

	foreach ( isset( $wpml['custom_posts_sync_option'] ) ? (array) $wpml['custom_posts_sync_option'] : array() as $type => $mode ) {
		if ( (int) $mode && post_type_exists( $type ) && ! in_array( $type, langosh_untranslatable_post_types(), true ) ) {
			$post_types[ $type ] = 2 === (int) $mode ? 2 : 1;
		}
	}

	/*
	 * A taxonomy is translated here when WPML translated it and it has terms in another
	 * language, or when – whatever its WPML setting – posts are filed under such terms.
	 * One whose terms all are in the default language is left alone: its terms serve every language.
	 */
	$taxonomies   = array();
	$tax_fallback = array();
	$wpml_modes   = isset( $wpml['taxonomies_sync_option'] ) ? (array) $wpml['taxonomies_sync_option'] : array();
	// phpcs:ignore WordPress.DB.PreparedSQL
	$foreign = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT tt.taxonomy, COUNT(*) AS terms, SUM( tt.count ) AS uses
			FROM {$wpdb->prefix}icl_translations i
			JOIN $wpdb->term_taxonomy tt ON tt.term_taxonomy_id = i.element_id AND i.element_type = CONCAT( 'tax_', tt.taxonomy )
			WHERE i.language_code <> %s AND i.trid > 0
			GROUP BY tt.taxonomy",
			$wpml_default
		),
		OBJECT_K
	);

	foreach ( (array) $foreign as $taxonomy => $row ) {
		$mode = isset( $wpml_modes[ $taxonomy ] ) ? (int) $wpml_modes[ $taxonomy ] : 0;

		if ( ! taxonomy_exists( $taxonomy ) || in_array( $taxonomy, langosh_untranslatable_taxonomies(), true ) || ( ! $mode && ! (int) $row->uses ) ) {
			continue;
		}

		$taxonomies[] = $taxonomy;

		// Terms of content shown with its original are shown with theirs.
		$types = array_intersect( (array) get_taxonomy( $taxonomy )->object_type, array_keys( $post_types ) );

		if ( 2 === $mode || ( $types && ! array_diff( $types, array_keys( $post_types, 2, true ) ) ) ) {
			$tax_fallback[] = $taxonomy;
		}
	}

	/*
	 * Custom fields WPML copied to every translation: those among them that content with
	 * translations really has. Fields of ACF are left to ACF (see langosh_wpml_acf_groups()).
	 */
	$copy  = $settings['copy_meta'];
	$tm    = isset( $wpml['translation-management']['custom_fields_translation'] ) ? (array) $wpml['translation-management']['custom_fields_translation'] : array();
	$names = array();

	foreach ( $tm as $name => $mode ) {
		if ( 1 === (int) $mode ) {
			$names[] = (string) $name;
		}
	}
	if ( ! empty( $wpml['sync_page_template'] ) ) {
		$names[] = '_wp_page_template';
	}

	if ( $names && $post_types ) {
		$in    = "'" . implode( "','", array_map( 'esc_sql', $names ) ) . "'";
		$types = "'post_" . implode( "','post_", array_map( 'esc_sql', array_keys( $post_types ) ) ) . "'";
		// phpcs:ignore WordPress.DB.PreparedSQL
		$used = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT m.meta_key
				FROM {$wpdb->prefix}icl_translations i
				JOIN $wpdb->postmeta m ON m.post_id = i.element_id AND m.meta_key IN ( $in )
				LEFT JOIN $wpdb->postmeta k ON k.post_id = m.post_id AND k.meta_key = CONCAT( '_', m.meta_key ) AND k.meta_value LIKE %s
				WHERE i.element_type IN ( $types ) AND i.language_code <> %s AND k.meta_id IS NULL AND m.meta_key NOT LIKE %s AND m.meta_value NOT LIKE %s",
				'field\_%',
				$wpml_default,
				'\_wpml%',
				'field\_%'
			)
		);

		$copy = array_merge( $copy, (array) $used );
	}

	// Custom fields the theme asked WPML to copy to every translation.
	$config = get_stylesheet_directory() . '/wpml-config.xml';

	if ( is_readable( $config ) && function_exists( 'simplexml_load_file' ) ) {
		$xml = simplexml_load_file( $config );

		foreach ( $xml && isset( $xml->{'custom-fields'} ) ? $xml->{'custom-fields'}->{'custom-field'} : array() as $field ) {
			if ( 'copy' === (string) $field['action'] ) {
				$copy[] = trim( (string) $field );
			}
		}
	}

	$settings['languages']  = $languages;
	$settings['default']    = $default;
	$settings['post_types'] = $post_types ? $post_types : $settings['post_types'];
	$settings['taxonomies']   = $taxonomies;
	$settings['tax_fallback'] = $tax_fallback;
	$settings['copy_meta']  = array_values( array_unique( array_filter( $copy ) ) );

	Langosh::save_settings( $settings );

	/* Languages of posts and terms ---------------------------------------- */

	$codes = "'" . implode( "','", array_map( 'esc_sql', $wpml_codes ) ) . "'";
	$lang  = langosh_wpml_code_sql( 'i.language_code' );
	$posts = 0;
	$terms = 0;

	if ( $settings['post_types'] ) {
		$types = "'post_" . implode( "','post_", array_map( 'esc_sql', array_keys( $settings['post_types'] ) ) ) . "'";
		// phpcs:ignore WordPress.DB.PreparedSQL
		$posts = (int) $wpdb->query(
			"REPLACE INTO $t ( type, id, lang, grp )
			SELECT 'post', i.element_id, $lang, i.trid
			FROM {$wpdb->prefix}icl_translations i
			JOIN $wpdb->posts p ON p.ID = i.element_id
			WHERE i.element_type IN ( $types ) AND i.language_code IN ( $codes ) AND i.trid > 0"
		);
	}

	/*
	 * Terms of a taxonomy the site no longer registers (a theme that dropped it but still
	 * reads its terms) keep their language and translations as well: the rows cost nothing
	 * and a translated name is better than the original.
	 */
	$term_taxonomies = $settings['taxonomies'];

	foreach ( (array) $foreign as $taxonomy => $row ) {
		if ( ! taxonomy_exists( $taxonomy ) && ! in_array( $taxonomy, langosh_untranslatable_taxonomies(), true ) ) {
			$term_taxonomies[] = $taxonomy;
		}
	}

	if ( $term_taxonomies ) {
		$list = "'" . implode( "','", array_map( 'esc_sql', $term_taxonomies ) ) . "'";
		// phpcs:ignore WordPress.DB.PreparedSQL
		$terms = (int) $wpdb->query(
			"REPLACE INTO $t ( type, id, lang, grp )
			SELECT 'term', tt.term_id, $lang, i.trid
			FROM {$wpdb->prefix}icl_translations i
			JOIN $wpdb->term_taxonomy tt ON tt.term_taxonomy_id = i.element_id AND i.element_type = CONCAT( 'tax_', tt.taxonomy )
			WHERE tt.taxonomy IN ( $list ) AND i.language_code IN ( $codes ) AND i.trid > 0"
		);
	}

	/* Menus ---------------------------------------------------------------- */

	// WPML translated the menu assigned to a location; here each language has its own location.
	// While WPML is active it rewrites menu IDs in the theme's settings as they are read and saved.
	remove_all_filters( 'option_theme_mods_' . get_stylesheet() );
	remove_all_filters( 'pre_update_option_theme_mods_' . get_stylesheet() );

	$mods      = get_option( 'theme_mods_' . get_stylesheet() );
	$locations = is_array( $mods ) && isset( $mods['nav_menu_locations'] ) ? (array) $mods['nav_menu_locations'] : array();
	$menus     = 0;

	foreach ( $locations as $location => $menu_id ) {
		if ( ! $menu_id || false !== strpos( $location, '___' ) ) {
			continue;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL
		$others = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_id, o.language_code
				FROM {$wpdb->prefix}icl_translations i
				JOIN $wpdb->term_taxonomy mt ON mt.term_taxonomy_id = i.element_id AND mt.taxonomy = 'nav_menu'
				JOIN {$wpdb->prefix}icl_translations o ON o.trid = i.trid AND o.element_type = 'tax_nav_menu'
				JOIN $wpdb->term_taxonomy tt ON tt.term_taxonomy_id = o.element_id AND tt.taxonomy = 'nav_menu'
				WHERE i.element_type = 'tax_nav_menu' AND mt.term_id = %d",
				$menu_id
			)
		);

		foreach ( (array) $others as $other ) {
			$code = langosh_wpml_code( $other->language_code );

			if ( $code !== $default && isset( $languages[ $code ] ) ) {
				$locations[ $location . '___' . $code ] = (int) $other->term_id;
				++$menus;
			}
		}
	}

	if ( $menus ) {
		$mods['nav_menu_locations'] = $locations;
		update_option( 'theme_mods_' . get_stylesheet(), $mods );
	}

	/*
	 * ACF option pages: ACFML saved each language under options_<WPML code>_…, which ACF
	 * reads here as options_<code>_…. Rows of a language whose code changes are renamed,
	 * unless that language already has a row of the same name.
	 */
	foreach ( langosh_wpml_codes() as $from => $to ) {
		foreach ( array( 'options_', '_options_' ) as $prefix ) {
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s", $wpdb->esc_like( $prefix . $from . '_' ) . '%' ) );

			foreach ( (array) $names as $name ) {
				$new = $prefix . $to . '_' . substr( $name, strlen( $prefix . $from . '_' ) );

				if ( null === $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM $wpdb->options WHERE option_name = %s", $new ) ) ) {
					$wpdb->update( $wpdb->options, array( 'option_name' => $new ), array( 'option_name' => $name ) );
				}
			}
		}
	}

	langosh_import_wpml_texts( $default, $languages );

	wp_cache_flush();

	// Content WPML never gave a language to (added by imports) is in the default language.
	if ( function_exists( 'langosh_assign_default' ) ) {
		langosh_assign_default();
	}

	return array(
		'languages' => count( $languages ),
		'posts'     => $posts,
		'terms'     => $terms,
		'menus'     => $menus,
	);
}

/**
 * Library entries WPML Media made for the other languages: second entries for a file
 * that is already in the library.
 *
 * @return array[] each with 'copy' and 'original' (attachment IDs)
 */
function langosh_wpml_media_copies() {
	global $wpdb;

	if ( ! langosh_wpml_available() ) {
		return array();
	}

	// phpcs:ignore WordPress.DB.PreparedSQL
	return (array) $wpdb->get_results(
		"SELECT c.element_id AS copy, o.element_id AS original, c.language_code AS lang
		FROM {$wpdb->prefix}icl_translations c
		JOIN {$wpdb->prefix}icl_translations o ON o.trid = c.trid AND o.element_type = 'post_attachment' AND o.source_language_code IS NULL
		JOIN $wpdb->posts cp ON cp.ID = c.element_id AND cp.post_type = 'attachment'
		JOIN $wpdb->posts op ON op.ID = o.element_id AND op.post_type = 'attachment'
		JOIN $wpdb->postmeta cf ON cf.post_id = cp.ID AND cf.meta_key = '_wp_attached_file'
		JOIN $wpdb->postmeta of ON of.post_id = op.ID AND of.meta_key = '_wp_attached_file' AND of.meta_value = cf.meta_value
		WHERE c.element_type = 'post_attachment' AND c.source_language_code IS NOT NULL AND c.element_id <> o.element_id",
		ARRAY_A
	);
}

/**
 * Media have no language here, so WPML's copies would show as duplicates in the library.
 * Featured images, ACF image, file and gallery fields and galleries and images in the
 * text of posts that use a copy are pointed at the original, then the copy's library
 * entry is removed. The file is shared with the original and stays where it is. A copy
 * still named in the text of a post in some other way is left alone.
 *
 * Unlike the import this changes the content WPML would read, so it is a step for after the move.
 *
 * @return array counts: copies, removed, repointed, rewritten (posts whose text changed)
 */
function langosh_wpml_media_cleanup() {
	global $wpdb;

	$map   = array();
	$langs = array();

	foreach ( langosh_wpml_media_copies() as $row ) {
		$map[ (int) $row['copy'] ]   = (int) $row['original'];
		$langs[ (int) $row['copy'] ] = langosh_wpml_code( (string) $row['lang'] );
	}

	$result = array(
		'copies'    => count( $map ),
		'removed'   => 0,
		'repointed' => 0,
	);

	// While WPML is active, deleting a library entry could take its other language versions along.
	if ( ! $map || Langosh::$blocked ) {
		return $result;
	}

	$swap =function ( $value ) use ( $map ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $i => $id ) {
				$value[ $i ] = is_numeric( $id ) && isset( $map[ (int) $id ] ) ? (string) $map[ (int) $id ] : $id;
			}
			return $value;
		}

		return is_numeric( $value ) && isset( $map[ (int) $value ] ) ? (string) $map[ (int) $value ] : $value;
	};

	$ids = implode( ',', array_keys( $map ) );

	// phpcs:ignore WordPress.DB.PreparedSQL
	foreach ( (array) $wpdb->get_results( "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_thumbnail_id' AND meta_value IN ( $ids )" ) as $row ) {
		update_post_meta( (int) $row->post_id, '_thumbnail_id', $map[ (int) $row->meta_value ] );
		++$result['repointed'];
	}

	if ( function_exists( 'acf_get_field' ) ) {
		$media = array( 'image', 'file', 'gallery' );

		// phpcs:ignore WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( "SELECT m.post_id, m.meta_key, m.meta_value, k.meta_value AS field FROM $wpdb->postmeta m JOIN $wpdb->postmeta k ON k.post_id = m.post_id AND k.meta_key = CONCAT( '_', m.meta_key ) WHERE k.meta_value LIKE 'field\_%' AND m.meta_value <> ''" );

		foreach ( (array) $rows as $row ) {
			$field = acf_get_field( $row->field );
			$old   = maybe_unserialize( $row->meta_value );
			$now   = $field && in_array( $field['type'], $media, true ) ? $swap( $old ) : $old;

			if ( $now !== $old ) {
				update_post_meta( (int) $row->post_id, $row->meta_key, wp_slash( $now ) );
				++$result['repointed'];
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( "SELECT o.option_name, o.option_value, k.option_value AS field FROM $wpdb->options o JOIN $wpdb->options k ON k.option_name = CONCAT( '_', o.option_name ) WHERE k.option_value LIKE 'field\_%' AND o.option_value <> ''" );

		foreach ( (array) $rows as $row ) {
			$field = acf_get_field( $row->field );
			$old   = maybe_unserialize( $row->option_value );
			$now   = $field && in_array( $field['type'], $media, true ) ? $swap( $old ) : $old;

			if ( $now !== $old ) {
				update_option( $row->option_name, $now );
				++$result['repointed'];
			}
		}
	}

	/*
	 * Galleries ([gallery ids="…"], [gallery include="…"]) and images (class wp-image-N,
	 * "id":N of an image block) that name a copy in the text of a post are pointed at the
	 * original, so the copy can go without the post losing a picture.
	 */
	$result['rewritten'] = 0;

	$renumber = function ( $list ) use ( $map ) {
		$ids = array();

		foreach ( wp_parse_id_list( $list ) as $id ) {
			$ids[] = isset( $map[ $id ] ) ? $map[ $id ] : $id;
		}

		return implode( ',', array_unique( $ids ) );
	};

	// phpcs:ignore WordPress.DB.PreparedSQL
	$posts = $wpdb->get_results( "SELECT ID, post_content FROM $wpdb->posts WHERE post_status NOT IN ( 'auto-draft', 'inherit' ) AND post_type NOT IN ( 'revision', 'attachment' ) AND ( post_content LIKE '%[gallery%' OR post_content LIKE '%wp-image-%' OR post_content LIKE '%wp:image%' OR post_content LIKE '%wp:gallery%' )" );

	foreach ( (array) $posts as $post ) {
		$content = preg_replace_callback(
			'/(\[gallery\b[^\]]*\b(?:ids|include)=")([^"]+)(")/',
			function ( $m ) use ( $renumber ) {
				return $m[1] . $renumber( $m[2] ) . $m[3];
			},
			$post->post_content
		);
		$content = preg_replace_callback(
			'/\bwp-image-(\d+)\b/',
			function ( $m ) use ( $map ) {
				return 'wp-image-' . ( isset( $map[ (int) $m[1] ] ) ? $map[ (int) $m[1] ] : $m[1] );
			},
			$content
		);
		$content = preg_replace_callback(
			'/("id":)(\d+)\b/',
			function ( $m ) use ( $map ) {
				return $m[1] . ( isset( $map[ (int) $m[2] ] ) ? $map[ (int) $m[2] ] : $m[2] );
			},
			$content
		);
		$content = preg_replace_callback(
			'/("ids":\[)([\d,]+)(\])/',
			function ( $m ) use ( $renumber ) {
				return $m[1] . $renumber( $m[2] ) . $m[3];
			},
			$content
		);

		if ( is_string( $content ) && $content !== $post->post_content ) {
			$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => (int) $post->ID ) );
			clean_post_cache( (int) $post->ID );
			++$result['rewritten'];
		}
	}

	/*
	 * A copy may carry a title, caption, description or alt text of its own language.
	 * Those are kept on the original as its texts in that language (see inc/media.php),
	 * so a gallery shown in English still has its English captions.
	 */
	$result['texts'] = 0;

	foreach ( $map as $copy => $original ) {
		$lang = isset( $langs[ $copy ] ) ? $langs[ $copy ] : '';
		$cp   = get_post( $copy );
		$op   = get_post( $original );

		if ( ! $lang || ! Langosh::is_lang( $lang ) || $lang === Langosh::default_lang() || ! $cp || ! $op ) {
			continue;
		}

		$texts = array(
			'title'       => array( $cp->post_title, $op->post_title ),
			'caption'     => array( $cp->post_excerpt, $op->post_excerpt ),
			'description' => array( $cp->post_content, $op->post_content ),
			'alt'         => array( (string) get_post_meta( $copy, '_wp_attachment_image_alt', true ), (string) get_post_meta( $original, '_wp_attachment_image_alt', true ) ),
		);
		$own   = get_post_meta( $original, '_langosh_media_' . $lang, true );
		$own   = is_array( $own ) ? $own : array();
		$found = false;

		foreach ( $texts as $field => $pair ) {
			// A title that is just the file name says nothing about the language.
			if ( 'title' === $field && sanitize_title( $pair[0] ) === sanitize_title( pathinfo( (string) get_post_meta( $copy, '_wp_attached_file', true ), PATHINFO_FILENAME ) ) ) {
				continue;
			}
			if ( '' !== trim( (string) $pair[0] ) && trim( (string) $pair[0] ) !== trim( (string) $pair[1] ) && empty( $own[ $field ] ) ) {
				$own[ $field ] = (string) $pair[0];
				$found         = true;
			}
		}

		if ( $found ) {
			update_post_meta( $original, '_langosh_media_' . $lang, wp_slash( $own ) );
			++$result['texts'];
		}
	}

	// Only the library entry goes; every file of the copy belongs to the original as well.
	add_filter( 'wp_delete_file', '__return_empty_string', 999 );

	foreach ( $map as $copy => $original ) {
		$used = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_status <> 'inherit' AND ( post_content LIKE %s OR post_content LIKE %s ) LIMIT 1", '%wp-image-' . $copy . '"%', '%wp-image-' . $copy . ' %' ) );

		if ( ! $used && wp_delete_attachment( $copy, true ) ) {
			++$result['removed'];
		}
	}

	remove_filter( 'wp_delete_file', '__return_empty_string', 999 );

	return $result;
}

/**
 * Post types that never have a language: files, menu items and the internals of editors and plugins.
 */
function langosh_untranslatable_post_types() {
	return array( 'attachment', 'nav_menu_item', 'revision', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page', 'wpcf7_contact_form' );
}

function langosh_untranslatable_taxonomies() {
	return array( 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category', 'translation_priority' );
}

/**
 * Texts WPML String Translation kept for options: the site title and tagline, and the
 * date and time formats. WPML stored the format of whichever language the site was set up
 * in; here the option holds the format of the default language and the others are translations.
 */
function langosh_import_wpml_texts( $default, $languages ) {
	global $wpdb;

	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'icl_strings' ) ) ) ) {
		return;
	}

	$options = array(
		'Blog Title'                => 'blogname',
		'Tagline'                   => 'blogdescription',
		'date_format'               => 'date_format',
		'time_format'               => 'time_format',
		'links_updated_date_format' => 'links_updated_date_format',
	);
	$all      = Langosh_Strings::all();
	$settings = Langosh::settings();
	// phpcs:ignore WordPress.DB.PreparedSQL
	$rows = $wpdb->get_results(
		"SELECT s.name, s.value, t.language, t.value AS translation
		FROM {$wpdb->prefix}icl_strings s
		JOIN {$wpdb->prefix}icl_string_translations t ON t.string_id = s.id AND t.status = 10 AND t.value <> ''
		WHERE s.context = 'WP' OR s.context IN ( 'admin_texts_date_format', 'admin_texts_time_format', 'admin_texts_links_updated_date_format' )"
	);

	foreach ( (array) $rows as $row ) {
		$row->language = langosh_wpml_code( $row->language );

		if ( ! isset( $options[ $row->name ], $languages[ $row->language ] ) || $row->translation === $row->value ) {
			continue;
		}

		$option = $options[ $row->name ];

		if ( $row->language === $default ) {
			// The stored value was the text of another language: every other language keeps it, the option takes the default one.
			foreach ( $languages as $code => $language ) {
				if ( $code !== $default && empty( $all[ $code ][ 'opt:' . $option ] ) ) {
					$all[ $code ][ 'opt:' . $option ] = $row->value;
				}
			}
			if ( Langosh::raw_option( $option ) === $row->value ) {
				update_option( $option, $row->translation );
			}
		} else {
			$all[ $row->language ][ 'opt:' . $option ] = $row->translation;
		}

		$settings['options'][] = $option;
	}

	/*
	 * Texts of other options WPML String Translation kept ("admin texts": SEO title
	 * templates, breadcrumb labels, a company name). The context is admin_texts_<option>;
	 * the name is [<option>]<entry> for one entry of an array option, or the option name
	 * for a plain one. Only entries whose stored text is still what WPML translated are
	 * taken over – a translation of a text that has since changed would be stale.
	 */
	// phpcs:ignore WordPress.DB.PreparedSQL
	$rows = $wpdb->get_results(
		"SELECT s.context, s.name, s.value, t.language, t.value AS translation
		FROM {$wpdb->prefix}icl_strings s
		JOIN {$wpdb->prefix}icl_string_translations t ON t.string_id = s.id AND t.status = 10 AND t.value <> ''
		WHERE s.context LIKE 'admin\_texts\_%'"
	);

	foreach ( (array) $rows as $row ) {
		$row->language = langosh_wpml_code( $row->language );
		$option        = substr( $row->context, strlen( 'admin_texts_' ) );

		if ( isset( $options[ $row->name ] ) || ! isset( $languages[ $row->language ] ) || $row->language === $default || $row->translation === $row->value ) {
			continue;
		}

		$stored = Langosh::raw_option( $option );

		if ( preg_match( '/^\[' . preg_quote( $option, '/' ) . '\]([^\[\]]+)$/', $row->name, $match ) ) {
			$entry   = $match[1];
			$current = is_array( $stored ) && isset( $stored[ $entry ] ) ? $stored[ $entry ] : null;
			$key     = 'opt:' . $option . '[' . $entry . ']';
			$path    = $option . '[' . $entry . ']';
		} elseif ( $row->name === $option ) {
			$current = is_scalar( $stored ) ? $stored : null;
			$key     = 'opt:' . $option;
			$path    = $option;
		} else {
			continue;   // an entry nested deeper than one level
		}

		if ( null === $current || (string) $current !== (string) $row->value ) {
			continue;
		}

		$all[ $row->language ][ $key ] = $row->translation;
		$settings['options'][]         = $path;
	}

	$settings['options'] = array_values( array_unique( $settings['options'] ) );

	Langosh::save_settings( $settings );
	update_option( 'langosh_strings', $all, true );
	Langosh_Strings::reset();
}

/**
 * ACFML kept a copy of every field group per language, each with field keys of its own,
 * and content of a language may point at the keys of either copy. Here one group serves
 * all languages: content is pointed at the fields of the default language's group, fields
 * WPML copied between translations are marked "Ista vrijednost u svim jezicima", and the
 * other copies are switched off (not deleted).
 *
 * Changes content WPML would read, so it is a step for after the move.
 *
 * @return array counts: groups, fields, repointed, copied
 */
function langosh_wpml_acf_groups() {
	global $wpdb;

	$result = array(
		'groups'    => 0,
		'fields'    => 0,
		'repointed' => 0,
		'copied'    => 0,
	);

	if ( ! langosh_wpml_available() || Langosh::$blocked ) {
		return $result;
	}

	$default = Langosh::default_lang();
	// phpcs:ignore WordPress.DB.PreparedSQL
	$rows = $wpdb->get_results(
		"SELECT p.ID, i.trid, i.language_code AS lang
		FROM {$wpdb->prefix}icl_translations i
		JOIN $wpdb->posts p ON p.ID = i.element_id AND p.post_type = 'acf-field-group' AND p.post_status = 'publish'
		WHERE i.element_type = 'post_acf-field-group'
		ORDER BY i.trid, p.ID"
	);
	$sets = array();

	foreach ( (array) $rows as $row ) {
		$sets[ $row->trid ][ langosh_wpml_code( $row->lang ) ][] = (int) $row->ID;
	}

	/**
	 * Fields below a group or field as path => post, a path being the names from the top ("contacts/phone").
	 */
	$walk = function ( $parent, $prefix = '' ) use ( &$walk, $wpdb ) {
		$found = array();

		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name, post_excerpt, post_content FROM $wpdb->posts WHERE post_parent = %d AND post_type = 'acf-field' AND post_status = 'publish' ORDER BY menu_order, ID", $parent ) ) as $field ) {
			// Tabs and messages have no name; their place in the group identifies them.
			$name = '' === $field->post_excerpt ? '#' . count( $found ) : $field->post_excerpt;
			$path = $prefix . $name;

			if ( ! isset( $found[ $path ] ) ) {
				$found[ $path ] = $field;
			}

			$found += $walk( (int) $field->ID, $path . '/' );
		}

		return $found;
	};

	$copy = array();

	foreach ( $sets as $set ) {
		if ( empty( $set[ $default ] ) ) {
			continue;
		}

		$keep = $walk( $set[ $default ][0] );

		foreach ( $keep as $path => $field ) {
			$config = maybe_unserialize( $field->post_content );

			if ( isset( $config['wpml_cf_preferences'] ) && 1 === (int) $config['wpml_cf_preferences'] ) {
				$copy[ $field->ID ] = true;
			}
		}

		unset( $set[ $default ] );

		foreach ( $set as $ids ) {
			foreach ( $ids as $group ) {
				foreach ( $walk( $group ) as $path => $field ) {
					if ( ! isset( $keep[ $path ] ) ) {
						continue;
					}

					$old    = $field->post_name;
					$new    = $keep[ $path ]->post_name;
					$config = maybe_unserialize( $field->post_content );

					if ( isset( $config['wpml_cf_preferences'] ) ) {
						if ( 1 === (int) $config['wpml_cf_preferences'] ) {
							$copy[ $keep[ $path ]->ID ] = true;
						} elseif ( 2 === (int) $config['wpml_cf_preferences'] ) {
							$copy[ $keep[ $path ]->ID ] = false;
						}
					}

					if ( $old !== $new ) {
						$result['repointed'] += (int) $wpdb->query( $wpdb->prepare( "UPDATE $wpdb->postmeta SET meta_value = %s WHERE meta_value = %s AND meta_key LIKE %s", $new, $old, '\_%' ) );
						$result['repointed'] += (int) $wpdb->query( $wpdb->prepare( "UPDATE $wpdb->termmeta SET meta_value = %s WHERE meta_value = %s AND meta_key LIKE %s", $new, $old, '\_%' ) );
						++$result['fields'];
					}
				}

				$wpdb->update( $wpdb->posts, array( 'post_status' => 'acf-disabled' ), array( 'ID' => $group ) );
				clean_post_cache( $group );
				++$result['groups'];
			}
		}
	}

	foreach ( array_keys( array_filter( $copy ) ) as $id ) {
		$field  = get_post( $id );
		$config = $field ? maybe_unserialize( $field->post_content ) : null;

		if ( is_array( $config ) && empty( $config['langosh_copy'] ) ) {
			$config['langosh_copy'] = 1;
			$wpdb->update( $wpdb->posts, array( 'post_content' => maybe_serialize( $config ) ), array( 'ID' => $id ) );
			clean_post_cache( $id );
			++$result['copied'];
		}
	}

	wp_cache_flush();

	return $result;
}