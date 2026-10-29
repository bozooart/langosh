<?php
/**
 * Admin: the language picker in the toolbar, the "Jezici" column and box on posts and
 * terms, and the handlers behind them. The settings screen is in admin/settings.php.
 */

defined( 'ABSPATH' ) || exit;

require LANGOSH_DIR . '/admin/settings.php';

add_filter(
	'plugin_action_links_' . plugin_basename( LANGOSH_FILE ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=langosh' ) ) . '">Postavke</a>' );
		return $links;
	}
);

if ( Langosh::$blocked ) {
	add_action(
		'admin_notices',
		function () {
			$screen = get_current_screen();

			if ( $screen && in_array( $screen->id, array( 'plugins', 'settings_page_langosh' ), true ) && current_user_can( 'manage_options' ) ) {
				echo '<div class="notice notice-warning"><p><strong>Langosh miruje dok je WPML aktivan.</strong> Preuzmite prijevode pod <a href="' . esc_url( admin_url( 'options-general.php?page=langosh&tab=alati' ) ) . '">Postavke → Langosh → Alati</a>, a zatim deaktivirajte WPML.</p></div>';
			}
		}
	);

	return;
}

/**
 * Translate link of a post: creates the translation as a draft copy and opens it.
 */
function langosh_translate_url( $post_id, $lang ) {
	return wp_nonce_url( admin_url( 'admin-post.php?action=langosh_translate&post=' . (int) $post_id . '&to=' . $lang ), 'langosh_translate_' . (int) $post_id );
}

/* -------------------------------------------------------------------------
 * Language of the admin screens
 * ---------------------------------------------------------------------- */

add_action(
	'admin_init',
	function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lang = isset( $_GET['langosh_admin'] ) ? sanitize_key( wp_unslash( $_GET['langosh_admin'] ) ) : '';

		if ( $lang && ( 'all' === $lang || Langosh::is_lang( $lang ) ) ) {
			update_user_meta( get_current_user_id(), 'langosh_admin_lang', $lang );
			wp_safe_redirect( remove_query_arg( 'langosh_admin' ) );
			exit;
		}
	}
);

add_action(
	'admin_bar_menu',
	function ( $bar ) {
		$languages = Langosh::languages();

		if ( count( $languages ) < 2 ) {
			return;
		}

		$current = Langosh::$current;

		$bar->add_node(
			array(
				'id'    => 'langosh',
				'title' => '<span class="ab-icon dashicons dashicons-translation" style="top:2px"></span>' . esc_html( isset( $languages[ $current ] ) ? $languages[ $current ]['name'] : 'Svi jezici' ),
				'meta'  => array( 'title' => 'Jezik sadržaja koji se prikazuje i uređuje' ),
			)
		);

		foreach ( $languages + array( 'all' => array( 'name' => 'Svi jezici' ) ) as $code => $language ) {
			$bar->add_node(
				array(
					'parent' => 'langosh',
					'id'     => 'langosh-' . $code,
					'title'  => esc_html( $language['name'] ) . ( $code === $current ? ' ✓' : '' ),
					'href'   => esc_url( add_query_arg( 'langosh_admin', $code ) ),
				)
			);
		}
	},
	80
);

/**
 * The counts above a list ("Sve (12) | Objavljeno (9)") follow the language of the list.
 */
add_filter(
	'wp_count_posts',
	function ( $counts, $type ) {
		global $wpdb;

		$lang = Langosh::filter_lang();

		if ( ! $lang || ! Langosh::post_mode( $type ) ) {
			return $counts;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.post_status, COUNT(*) AS num FROM $wpdb->posts p LEFT JOIN " . Langosh::table() . " l ON l.type = 'post' AND l.id = p.ID WHERE p.post_type = %s AND ( l.lang IS NULL OR l.lang = %s ) GROUP BY p.post_status", $type, $lang ) );

		foreach ( get_object_vars( $counts ) as $status => $num ) {
			$counts->$status = 0;
		}
		foreach ( (array) $rows as $row ) {
			$counts->{$row->post_status} = (int) $row->num;
		}

		return $counts;
	},
	10,
	2
);

/* -------------------------------------------------------------------------
 * Lists of posts and terms: the "Jezici" column
 * ---------------------------------------------------------------------- */

/**
 * "HR · EN ✎ · + DE": the language of the item, then a link to each translation or to adding it.
 */
function langosh_column( $id, $type = 'post', $taxonomy = '' ) {
	$own  = Langosh::lang_of( $id, $type );
	$list = Langosh::translations( $id, $type );
	$out  = array();

	if ( ! $own ) {
		return '—';
	}

	foreach ( Langosh::languages() as $code => $language ) {
		$label = esc_html( strtoupper( $code ) );

		if ( $code === $own ) {
			$out[] = '<strong>' . $label . '</strong>';
		} elseif ( isset( $list[ $code ] ) ) {
			$url   = 'term' === $type ? get_edit_term_link( $list[ $code ], $taxonomy ) : get_edit_post_link( $list[ $code ] );
			$out[] = '<a href="' . esc_url( (string) $url ) . '" title="' . esc_attr( 'Uredi prijevod: ' . $language['name'] ) . '">' . $label . ' <span class="dashicons dashicons-edit" style="font-size:14px;width:14px;height:14px;vertical-align:text-bottom"></span></a>';
		} elseif ( 'post' === $type && current_user_can( 'edit_post', $id ) ) {
			$out[] = '<a href="' . esc_url( langosh_translate_url( $id, $code ) ) . '" title="' . esc_attr( 'Dodaj prijevod: ' . $language['name'] ) . '">+ ' . $label . '</a>';
		} else {
			$out[] = '<span style="opacity:.5">' . $label . '</span>';
		}
	}

	return implode( ' · ', $out );
}

add_action(
	'admin_init',
	function () {
		if ( count( Langosh::languages() ) < 2 ) {
			return;
		}

		$add = function ( $columns ) {
			$columns['langosh'] = 'Jezici';
			return $columns;
		};

		foreach ( array_keys( array_filter( Langosh::settings()['post_types'] ) ) as $type ) {
			add_filter( "manage_{$type}_posts_columns", $add, 20 );
			add_action(
				"manage_{$type}_posts_custom_column",
				function ( $column, $post_id ) {
					if ( 'langosh' === $column ) {
						echo langosh_column( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput
					}
				},
				10,
				2
			);
		}

		foreach ( Langosh::settings()['taxonomies'] as $taxonomy ) {
			add_filter( "manage_edit-{$taxonomy}_columns", $add, 20 );
			add_filter(
				"manage_{$taxonomy}_custom_column",
				function ( $content, $column, $term_id ) use ( $taxonomy ) {
					return 'langosh' === $column ? langosh_column( $term_id, 'term', $taxonomy ) : $content;
				},
				10,
				3
			);

			add_action( "{$taxonomy}_add_form_fields", 'langosh_term_add_fields' );
			add_action( "{$taxonomy}_edit_form_fields", 'langosh_term_edit_fields', 10, 2 );
		}
	}
);

/* -------------------------------------------------------------------------
 * Posts: the "Jezik i prijevodi" box
 * ---------------------------------------------------------------------- */

add_action(
	'add_meta_boxes',
	function ( $post_type ) {
		if ( Langosh::post_mode( $post_type ) && count( Langosh::languages() ) > 1 ) {
			add_meta_box( 'langosh', 'Jezik i prijevodi', 'langosh_post_box', $post_type, 'side', 'high' );
		}
	}
);

function langosh_post_box( $post ) {
	$own  = Langosh::lang_of( $post->ID );
	$own  = $own ? $own : Langosh::current();
	$list = Langosh::translations( $post->ID );

	wp_nonce_field( 'langosh_box', 'langosh_box_nonce' );

	echo '<p><label for="langosh_lang"><strong>Jezik</strong></label><br><select name="langosh_lang" id="langosh_lang" style="width:100%">';
	foreach ( Langosh::languages() as $code => $language ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $code ), selected( $code, $own, false ), esc_html( $language['name'] ) );
	}
	echo '</select></p>';

	foreach ( Langosh::languages() as $code => $language ) {
		if ( $code === $own ) {
			continue;
		}

		echo '<p><strong>' . esc_html( $language['name'] ) . '</strong><br>';

		if ( isset( $list[ $code ] ) ) {
			$title = get_the_title( $list[ $code ] );
			printf( '<a href="%s">%s</a>', esc_url( (string) get_edit_post_link( $list[ $code ] ) ), esc_html( $title ? $title : '(bez naslova)' ) );
			printf( '<br><label><input type="checkbox" name="langosh_unlink[%s]" value="1"> Više nije prijevod ove stranice</label>', esc_attr( $code ) );
		} elseif ( 'auto-draft' === $post->post_status ) {
			echo '<span class="description">Prijevod se može dodati nakon prvog spremanja.</span>';
		} else {
			printf( '<a class="button" href="%s">Dodaj prijevod</a>', esc_url( langosh_translate_url( $post->ID, $code ) ) );
			printf( '<br><label class="description" style="display:block;margin-top:6px">ili ID postojećeg prijevoda: <input type="number" min="1" name="langosh_link[%s]" style="width:80px"></label>', esc_attr( $code ) );
		}

		echo '</p>';
	}

	echo '<p class="description">„Dodaj prijevod” stvara skicu s kopijom sadržaja i polja, spremnu za prevođenje.</p>';
}

add_action(
	'save_post',
	function ( $post_id, $post ) {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		if ( ! isset( $_POST['langosh_box_nonce'] ) || ! wp_verify_nonce( $_POST['langosh_box_nonce'], 'langosh_box' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$lang = isset( $_POST['langosh_lang'] ) ? sanitize_key( $_POST['langosh_lang'] ) : '';

		if ( Langosh::is_lang( $lang ) && $lang !== Langosh::lang_of( $post_id ) ) {
			Langosh::set( $post_id, 'post', $lang );
		}

		$list = Langosh::translations( $post_id );

		foreach ( isset( $_POST['langosh_unlink'] ) ? (array) $_POST['langosh_unlink'] : array() as $code => $on ) {
			if ( isset( $list[ $code ] ) && $list[ $code ] !== (int) $post_id ) {
				Langosh::detach( $list[ $code ] );
			}
		}

		$row = Langosh::get( $post_id );

		foreach ( isset( $_POST['langosh_link'] ) ? (array) $_POST['langosh_link'] : array() as $code => $other ) {
			$other = get_post( (int) $other );

			if ( $row && $other && $other->ID !== (int) $post_id && $other->post_type === $post->post_type && Langosh::is_lang( $code ) && $code !== $row[0] ) {
				Langosh::set( $other->ID, 'post', $code, $row[1] );
			}
		}
		// phpcs:enable
	},
	20,
	2
);

/**
 * A translation starts as a copy: content, custom fields (the sections of a page), the
 * template and the featured image; categories and the parent page in their translated
 * versions. SEO fields are left empty so the original's title is never published by mistake.
 *
 * @return int|WP_Error ID of the new draft
 */
function langosh_duplicate_post( $post_id, $lang ) {
	$post = get_post( $post_id );
	$row  = $post ? Langosh::get( $post->ID ) : null;

	if ( ! $post || ! $row || ! Langosh::is_lang( $lang ) ) {
		return new WP_Error( 'langosh_source', 'Izvorni sadržaj nema jezik ili ne postoji.' );
	}

	$list = Langosh::translations( $post->ID );

	if ( isset( $list[ $lang ] ) ) {
		return $list[ $lang ];
	}

	Langosh::switch_to( $lang );

	$new = wp_insert_post(
		wp_slash(
			array(
				'post_type'      => $post->post_type,
				'post_status'    => 'draft',
				'post_title'     => $post->post_title,
				'post_content'   => $post->post_content,
				'post_excerpt'   => $post->post_excerpt,
				'post_author'    => $post->post_author,
				'post_parent'    => $post->post_parent ? Langosh::translate_id( $post->post_parent, $lang ) : 0,
				'menu_order'     => $post->menu_order,
				'comment_status' => $post->comment_status,
				'ping_status'    => $post->ping_status,
			)
		),
		true
	);

	Langosh::switch_to();

	if ( is_wp_error( $new ) ) {
		return $new;
	}

	Langosh::set( $new, 'post', $lang, $row[1] );

	$skip = apply_filters( 'langosh_duplicate_skip_meta', array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug' ), $post );

	foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
		if ( in_array( $key, $skip, true ) || 0 === strpos( $key, '_yoast_wpseo_' ) || 0 === strpos( $key, 'rank_math_' ) ) {
			continue;
		}
		foreach ( $values as $value ) {
			add_post_meta( $new, $key, wp_slash( maybe_unserialize( $value ) ) );
		}
	}

	foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
		$terms = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );

		if ( is_wp_error( $terms ) || ! $terms ) {
			continue;
		}
		if ( Langosh::is_translated_taxonomy( $taxonomy ) ) {
			foreach ( $terms as $i => $term ) {
				$terms[ $i ] = Langosh::translate_id( $term, $lang, 'term' );
			}
		}

		wp_set_object_terms( $new, array_map( 'intval', array_filter( $terms ) ), $taxonomy );
	}

	do_action( 'langosh_duplicate_post', (int) $new, $post, $lang );

	return (int) $new;
}

add_action(
	'admin_post_langosh_translate',
	function () {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		$lang    = isset( $_GET['to'] ) ? sanitize_key( $_GET['to'] ) : '';
		// phpcs:enable

		check_admin_referer( 'langosh_translate_' . $post_id );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( 'Nemate ovlasti za uređivanje ovog sadržaja.' );
		}

		$new = langosh_duplicate_post( $post_id, $lang );

		if ( is_wp_error( $new ) ) {
			wp_die( esc_html( $new->get_error_message() ) );
		}

		wp_safe_redirect( (string) get_edit_post_link( $new, 'raw' ) );
		exit;
	}
);

/* -------------------------------------------------------------------------
 * Terms: language and translations on the add and edit forms
 * ---------------------------------------------------------------------- */

/**
 * <option>s with the terms of a taxonomy in one language.
 */
function langosh_term_options( $taxonomy, $lang, $selected = 0 ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'lang'       => $lang,
			'fields'     => 'id=>name',
		)
	);
	$html  = '<option value="0">—</option>';

	foreach ( is_array( $terms ) ? $terms : array() as $id => $name ) {
		$html .= sprintf( '<option value="%d"%s>%s</option>', $id, selected( $id, $selected, false ), esc_html( $name ) );
	}

	return $html;
}

function langosh_term_add_fields( $taxonomy ) {
	wp_nonce_field( 'langosh_term', 'langosh_term_nonce' );

	echo '<div class="form-field"><label for="langosh_lang">Jezik</label><select name="langosh_lang" id="langosh_lang">';
	foreach ( Langosh::languages() as $code => $language ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $code ), selected( $code, Langosh::current(), false ), esc_html( $language['name'] ) );
	}
	echo '</select></div>';

	echo '<div class="form-field"><label for="langosh_of">Prijevod pojma</label><select name="langosh_of" id="langosh_of"><option value="0">— nije prijevod —</option>';
	foreach ( Langosh::languages() as $code => $language ) {
		$options = langosh_term_options( $taxonomy, $code );
		echo '<optgroup label="' . esc_attr( $language['name'] ) . '">' . substr( $options, strlen( '<option value="0">—</option>' ) ) . '</optgroup>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</select><p>Ako je ovaj pojam prijevod postojećeg, odaberite kojeg.</p></div>';
}

function langosh_term_edit_fields( $term, $taxonomy ) {
	$own  = Langosh::lang_of( $term->term_id, 'term' );
	$own  = $own ? $own : Langosh::current();
	$list = Langosh::translations( $term->term_id, 'term' );

	wp_nonce_field( 'langosh_term', 'langosh_term_nonce' );

	echo '<tr class="form-field"><th scope="row"><label for="langosh_lang">Jezik</label></th><td><select name="langosh_lang" id="langosh_lang">';
	foreach ( Langosh::languages() as $code => $language ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $code ), selected( $code, $own, false ), esc_html( $language['name'] ) );
	}
	echo '</select></td></tr>';

	foreach ( Langosh::languages() as $code => $language ) {
		if ( $code === $own ) {
			continue;
		}

		printf(
			'<tr class="form-field"><th scope="row"><label for="langosh_tr_%1$s">Prijevod: %2$s</label></th><td><select name="langosh_tr[%1$s]" id="langosh_tr_%1$s">%3$s</select></td></tr>',
			esc_attr( $code ),
			esc_html( $language['name'] ),
			langosh_term_options( $taxonomy, $code, isset( $list[ $code ] ) ? $list[ $code ] : 0 ) // phpcs:ignore WordPress.Security.EscapeOutput
		);
	}
}

function langosh_term_save( $term_id, $tt_id, $taxonomy ) {
	// phpcs:disable WordPress.Security.ValidatedSanitizedInput
	if ( ! isset( $_POST['langosh_term_nonce'] ) || ! wp_verify_nonce( $_POST['langosh_term_nonce'], 'langosh_term' ) || ! Langosh::is_translated_taxonomy( $taxonomy ) ) {
		return;
	}

	$lang = isset( $_POST['langosh_lang'] ) ? sanitize_key( $_POST['langosh_lang'] ) : '';

	if ( ! Langosh::is_lang( $lang ) ) {
		return;
	}

	// On the add form: this term is the translation of an existing one.
	$of  = isset( $_POST['langosh_of'] ) ? Langosh::get( (int) $_POST['langosh_of'], 'term' ) : null;
	$grp = Langosh::set( $term_id, 'term', $lang, $of && $of[0] !== $lang ? $of[1] : 0 );

	$list = Langosh::translations( $term_id, 'term' );

	foreach ( isset( $_POST['langosh_tr'] ) ? (array) $_POST['langosh_tr'] : array() as $code => $other ) {
		$other = (int) $other;
		$had   = isset( $list[ $code ] ) ? $list[ $code ] : 0;

		if ( ! Langosh::is_lang( $code ) || $code === $lang || $other === $had ) {
			continue;
		}
		if ( $had ) {
			Langosh::detach( $had, 'term' );
		}
		if ( $other && Langosh::lang_of( $other, 'term' ) === $code ) {
			Langosh::set( $other, 'term', $code, $grp );
		}
	}
	// phpcs:enable
}
add_action( 'created_term', 'langosh_term_save', 20, 3 );
add_action( 'edited_term', 'langosh_term_save', 20, 3 );
