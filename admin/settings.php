<?php
/**
 * Postavke → Langosh: languages and translated content, texts (options and registered
 * strings) and tools (WPML import, giving a language to content that has none).
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_menu',
	function () {
		add_options_page( 'Langosh', 'Langosh', 'manage_options', 'langosh', 'langosh_settings_page' );
	}
);

/**
 * Lines of a textarea as a clean list.
 */
function langosh_lines( $text ) {
	return array_values( array_unique( array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', (string) $text ) ) ) ) );
}

/**
 * Number of posts and terms of translated types that have no language yet.
 */
function langosh_unassigned() {
	global $wpdb;

	$settings = Langosh::settings();
	$t        = Langosh::table();
	$count    = 0;

	if ( array_filter( $settings['post_types'] ) ) {
		$types = "'" . implode( "','", array_map( 'esc_sql', array_keys( array_filter( $settings['post_types'] ) ) ) ) . "'";
		// phpcs:ignore WordPress.DB.PreparedSQL
		$count += (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts p LEFT JOIN $t l ON l.type = 'post' AND l.id = p.ID WHERE l.id IS NULL AND p.post_type IN ( $types ) AND p.post_status <> 'auto-draft'" );
	}
	if ( $settings['taxonomies'] ) {
		$list = "'" . implode( "','", array_map( 'esc_sql', $settings['taxonomies'] ) ) . "'";
		// phpcs:ignore WordPress.DB.PreparedSQL
		$count += (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->term_taxonomy tt LEFT JOIN $t l ON l.type = 'term' AND l.id = tt.term_id WHERE l.id IS NULL AND tt.taxonomy IN ( $list )" );
	}

	return $count;
}

/**
 * Gives the default language to everything of a translated type that has none.
 */
function langosh_assign_default() {
	global $wpdb;

	$settings = Langosh::settings();
	$t        = Langosh::table();
	$lang     = esc_sql( Langosh::default_lang() );

	// phpcs:disable WordPress.DB.PreparedSQL
	$wpdb->query( "SET @langosh_grp := ( SELECT COALESCE( MAX(grp), 0 ) FROM $t )" );

	if ( array_filter( $settings['post_types'] ) ) {
		$types = "'" . implode( "','", array_map( 'esc_sql', array_keys( array_filter( $settings['post_types'] ) ) ) ) . "'";
		$wpdb->query( "INSERT IGNORE INTO $t ( type, id, lang, grp ) SELECT 'post', p.ID, '$lang', @langosh_grp := @langosh_grp + 1 FROM $wpdb->posts p LEFT JOIN $t l ON l.type = 'post' AND l.id = p.ID WHERE l.id IS NULL AND p.post_type IN ( $types ) AND p.post_status <> 'auto-draft'" );
	}
	if ( $settings['taxonomies'] ) {
		$list = "'" . implode( "','", array_map( 'esc_sql', $settings['taxonomies'] ) ) . "'";
		$wpdb->query( "INSERT IGNORE INTO $t ( type, id, lang, grp ) SELECT 'term', tt.term_id, '$lang', @langosh_grp := @langosh_grp + 1 FROM $wpdb->term_taxonomy tt LEFT JOIN $t l ON l.type = 'term' AND l.id = tt.term_id WHERE l.id IS NULL AND tt.taxonomy IN ( $list )" );
	}
	// phpcs:enable

	wp_cache_flush();
}

/* -------------------------------------------------------------------------
 * Saving
 * ---------------------------------------------------------------------- */

add_action(
	'admin_post_langosh_save',
	function () {
		check_admin_referer( 'langosh_save' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nemate ovlasti za ove postavke.' );
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		$tab      = isset( $_POST['tab'] ) ? sanitize_key( $_POST['tab'] ) : 'jezici';
		$settings = Langosh::settings();

		if ( 'jezici' === $tab ) {
			$languages = array();

			foreach ( isset( $_POST['langs'] ) ? (array) wp_unslash( $_POST['langs'] ) : array() as $row ) {
				$code = isset( $row['code'] ) ? preg_replace( '/[^a-z_-]/', '', strtolower( (string) $row['code'] ) ) : '';

				// Codes of folders WordPress itself uses would break the site.
				if ( strlen( $code ) < 2 || strlen( $code ) > 10 || 0 === strpos( $code, 'wp-' ) ) {
					continue;
				}

				$locale = isset( $row['locale'] ) ? preg_replace( '/[^A-Za-z0-9_]/', '', (string) $row['locale'] ) : '';
				$name   = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';

				$languages[ $code ] = array(
					'code'   => $code,
					'locale' => $locale ? $locale : $code,
					'name'   => $name ? $name : strtoupper( $code ),
				);
			}

			if ( $languages ) {
				$default = isset( $_POST['default'] ) ? sanitize_key( $_POST['default'] ) : '';
				$default = isset( $languages[ $default ] ) ? $default : (string) key( $languages );

				$settings['languages'] = array( $default => $languages[ $default ] ) + $languages;
				$settings['default']   = $default;
			}

			$settings['post_types'] = array();
			foreach ( isset( $_POST['pt'] ) ? (array) $_POST['pt'] : array() as $type => $mode ) {
				if ( post_type_exists( $type ) && (int) $mode ) {
					$settings['post_types'][ $type ] = 2 === (int) $mode ? 2 : 1;
				}
			}

			$settings['taxonomies']   = array();
			$settings['tax_fallback'] = array();
			foreach ( isset( $_POST['tax'] ) ? (array) $_POST['tax'] : array() as $taxonomy => $mode ) {
				if ( taxonomy_exists( $taxonomy ) && (int) $mode ) {
					$settings['taxonomies'][] = $taxonomy;
					if ( 2 === (int) $mode ) {
						$settings['tax_fallback'][] = $taxonomy;
					}
				}
			}
			$settings['copy_meta']  = langosh_lines( isset( $_POST['copy_meta'] ) ? sanitize_textarea_field( wp_unslash( $_POST['copy_meta'] ) ) : '' );

			Langosh::save_settings( $settings );
		}

		if ( 'tekstovi' === $tab ) {
			$settings['options'] = langosh_lines( isset( $_POST['options'] ) ? sanitize_textarea_field( wp_unslash( $_POST['options'] ) ) : '' );
			Langosh::save_settings( $settings );

			$all  = Langosh_Strings::all();
			$keys = isset( $_POST['k'] ) ? (array) wp_unslash( $_POST['k'] ) : array();

			foreach ( isset( $_POST['v'] ) ? (array) wp_unslash( $_POST['v'] ) : array() as $lang => $values ) {
				if ( ! Langosh::is_lang( $lang ) || $lang === Langosh::default_lang() ) {
					continue;
				}
				foreach ( (array) $values as $i => $value ) {
					if ( ! isset( $keys[ $i ] ) ) {
						continue;
					}

					$key   = (string) $keys[ $i ];
					$value = trim( current_user_can( 'unfiltered_html' ) ? (string) $value : wp_kses_post( (string) $value ) );

					if ( '' === $value ) {
						unset( $all[ $lang ][ $key ] );
					} else {
						$all[ $lang ][ $key ] = $value;
					}
				}
			}

			update_option( 'langosh_strings', $all, true );
		}

		if ( 'alati' === $tab && isset( $_POST['langosh_tool'] ) ) {
			$tool = sanitize_key( $_POST['langosh_tool'] );
			$note = '';

			if ( 'wpml' === $tool ) {
				$result = langosh_import_wpml();
				$note   = is_wp_error( $result ) ? $result->get_error_message() : sprintf( 'Preuzeto iz WPML-a: jezika %d, objava i stranica %d, pojmova %d, izbornika %d.', $result['languages'], $result['posts'], $result['terms'], $result['menus'] );
			}
			if ( 'wpml_acf' === $tool ) {
				$result = langosh_wpml_acf_groups();
				$note   = sprintf( 'Isključeno dvostrukih grupa polja: %d; preusmjerenih zapisa: %d; polja označenih kao ista u svim jezicima: %d.', $result['groups'], $result['repointed'], $result['copied'] );
			}
			if ( 'wpml_media' === $tool ) {
				$result = langosh_wpml_media_cleanup();
				$note   = sprintf( 'Uklonjeno kopija medija: %d od %d; preusmjerenih polja: %d; objava s ispravljenim tekstom: %d.', $result['removed'], $result['copies'], $result['repointed'], $result['rewritten'] );
			}
			if ( 'assign' === $tool ) {
				langosh_assign_default();
				$note = 'Sadržaj bez jezika dobio je zadani jezik.';
			}

			set_transient( 'langosh_note_' . get_current_user_id(), $note, 60 );
		}
		// phpcs:enable

		wp_safe_redirect( admin_url( 'options-general.php?page=langosh&tab=' . $tab . '&updated=1' ) );
		exit;
	}
);

/* -------------------------------------------------------------------------
 * Screen
 * ---------------------------------------------------------------------- */

function langosh_settings_page() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'jezici';
	$tabs = array(
		'jezici'   => 'Jezici i sadržaj',
		'tekstovi' => 'Tekstovi',
		'pregled'  => 'Pregled prijevoda',
		'alati'    => 'Alati',
	);
	$tab  = isset( $tabs[ $tab ] ) ? $tab : 'jezici';
	$note = get_transient( 'langosh_note_' . get_current_user_id() );

	echo '<div class="wrap"><h1>Langosh</h1>';

	if ( $note ) {
		delete_transient( 'langosh_note_' . get_current_user_id() );
		echo '<div class="notice notice-success"><p>' . esc_html( $note ) . '</p></div>';
	} elseif ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-success is-dismissible"><p>Spremljeno.</p></div>';
	}

	echo '<h2 class="nav-tab-wrapper">';
	foreach ( $tabs as $slug => $label ) {
		printf( '<a class="nav-tab%s" href="%s">%s</a>', $slug === $tab ? ' nav-tab-active' : '', esc_url( admin_url( 'options-general.php?page=langosh&tab=' . $slug ) ), esc_html( $label ) );
	}
	echo '</h2>';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="langosh_save"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
	wp_nonce_field( 'langosh_save' );

	call_user_func( 'langosh_tab_' . $tab );

	echo '</form></div>';
}

function langosh_tab_jezici() {
	$settings = Langosh::settings();
	$rows     = array_values( $settings['languages'] );
	$rows[]   = array(
		'code'   => '',
		'locale' => '',
		'name'   => '',
	);

	echo '<h2>Jezici</h2>';
	echo '<p>Zadani jezik je na adresi stranice, ostali u svojoj mapi (<code>/en/</code>). Kôd je naziv te mape; lokal određuje jezik WordPressa i datuma (<code>hr</code>, <code>en_US</code>, <code>de_DE</code>). Za uklanjanje jezika obrišite njegov kôd.</p>';
	echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>Zadani</th><th>Kôd</th><th>Lokal</th><th>Naziv</th></tr></thead><tbody>';

	foreach ( $rows as $i => $row ) {
		printf(
			'<tr><td>%2$s</td><td><input type="text" name="langs[%1$d][code]" value="%3$s" size="6" placeholder="de"></td><td><input type="text" name="langs[%1$d][locale]" value="%4$s" size="8" placeholder="de_DE"></td><td><input type="text" name="langs[%1$d][name]" value="%5$s" class="regular-text" placeholder="Deutsch"></td></tr>',
			$i, // phpcs:ignore WordPress.Security.EscapeOutput
			$row['code'] ? '<input type="radio" name="default" value="' . esc_attr( $row['code'] ) . '"' . checked( $row['code'], $settings['default'], false ) . '>' : '<span class="description">novi</span>', // phpcs:ignore WordPress.Security.EscapeOutput
			esc_attr( $row['code'] ),
			esc_attr( $row['locale'] ),
			esc_attr( $row['name'] )
		);
	}

	echo '</tbody></table>';

	echo '<h2>Sadržaj koji se prevodi</h2>';
	echo '<p>„S izvornikom” znači da se u drugom jeziku prikazuje izvornik dok prijevod ne postoji (iskustva, termini).</p>';
	echo '<table class="form-table" role="presentation"><tbody>';

	foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
		if ( in_array( $type->name, langosh_untranslatable_post_types(), true ) ) {
			continue;
		}

		$mode = Langosh::post_mode( $type->name );

		echo '<tr><th scope="row">' . esc_html( $type->labels->name ) . ' <code>' . esc_html( $type->name ) . '</code></th><td><select name="pt[' . esc_attr( $type->name ) . ']">';
		foreach ( array( 'Ne prevodi se', 'Prevodi se', 'Prevodi se, s izvornikom dok nema prijevoda' ) as $value => $label ) {
			printf( '<option value="%d"%s>%s</option>', $value, selected( $value, $mode, false ), esc_html( $label ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</select></td></tr>';
	}

	echo '</tbody></table><h2>Taksonomije</h2><table class="form-table" role="presentation"><tbody>';
	foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $taxonomy ) {
		if ( in_array( $taxonomy->name, langosh_untranslatable_taxonomies(), true ) ) {
			continue;
		}

		$mode = Langosh::tax_mode( $taxonomy->name );

		echo '<tr><th scope="row">' . esc_html( $taxonomy->labels->name ) . ' <code>' . esc_html( $taxonomy->name ) . '</code></th><td><select name="tax[' . esc_attr( $taxonomy->name ) . ']">';
		foreach ( array( 'Ne prevodi se', 'Prevodi se', 'Prevodi se, s izvornikom dok nema prijevoda' ) as $value => $label ) {
			printf( '<option value="%d"%s>%s</option>', $value, selected( $value, $mode, false ), esc_html( $label ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</select></td></tr>';
	}

	echo '<tr><th scope="row"><label for="copy_meta">Polja koja se kopiraju</label></th><td><textarea name="copy_meta" id="copy_meta" rows="6" class="regular-text code">' . esc_textarea( implode( "\n", $settings['copy_meta'] ) ) . '</textarea><p class="description">Nazivi prilagođenih polja (jedno po retku) koja su ista u svim prijevodima – datumi, cijene, brojevi. Pri spremanju se upisuju u sve prijevode.</p></td></tr>';

	echo '</tbody></table>';
	echo '<p class="description">Izbornici: svaka lokacija izbornika teme ima svoju inačicu po jeziku pod <a href="' . esc_url( admin_url( 'nav-menus.php?action=locations' ) ) . '">Izgled → Izbornici → Upravljanje lokacijama</a>.</p>';

	submit_button( 'Spremi' );
}

function langosh_tab_tekstovi() {
	$settings = Langosh::settings();
	$entries  = Langosh_Strings::entries();
	$all      = Langosh_Strings::all();
	$others   = array_diff_key( Langosh::languages(), array( Langosh::default_lang() => 1 ) );

	echo '<p>Tekstovi koji nisu objave ni stranice: naziv i slogan stranice, predlošci SEO naslova i opisa te tekstovi koje tema ili dodaci prijave funkcijom <code>langosh_t()</code>. Prazno polje znači da se koristi izvorni tekst.</p>';

	if ( ! $others ) {
		echo '<p><strong>Dodajte još jedan jezik da biste mogli prevoditi tekstove.</strong></p>';
	} elseif ( $entries ) {
		echo '<table class="widefat striped"><thead><tr><th style="width:22%">Tekst</th><th>Izvornik</th>';
		foreach ( $others as $language ) {
			echo '<th>' . esc_html( $language['name'] ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$i = 0;
		foreach ( $entries as $key => $text ) {
			$long = strlen( $text ) > 70 || false !== strpos( $text, "\n" );

			echo '<tr><td><code>' . esc_html( preg_replace( '/^(opt|str):/', '', $key ) ) . '</code><input type="hidden" name="k[' . (int) $i . ']" value="' . esc_attr( $key ) . '"></td><td>' . nl2br( esc_html( $text ) ) . '</td>';

			foreach ( $others as $code => $language ) {
				$value = isset( $all[ $code ][ $key ] ) ? $all[ $code ][ $key ] : '';
				$name  = 'v[' . $code . '][' . (int) $i . ']';

				echo '<td>' . ( $long
					? '<textarea name="' . esc_attr( $name ) . '" rows="3" style="width:100%">' . esc_textarea( $value ) . '</textarea>'
					: '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" style="width:100%">' ) . '</td>';
			}

			echo '</tr>';
			++$i;
		}

		echo '</tbody></table>';
	}

	echo '<h2>Opcije koje se prevode</h2>';
	echo '<p><textarea name="options" rows="6" class="large-text code">' . esc_textarea( implode( "\n", $settings['options'] ) ) . '</textarea></p>';
	echo '<p class="description">Jedna po retku: naziv opcije (<code>blogname</code>), jedan unos opcije koja je polje (<code>wpseo_titles[title-home-wpseo]</code>) ili više njih odjednom (<code>wpseo_titles[title-*]</code>, <code>wpseo_titles[metadesc-*]</code>). Nakon spremanja pojavljuju se u tablici iznad.</p>';

	submit_button( 'Spremi' );
}

function langosh_tab_alati() {
	global $wpdb;

	$counts = $wpdb->get_results( 'SELECT type, lang, COUNT(*) AS num FROM ' . Langosh::table() . ' GROUP BY type, lang' ); // phpcs:ignore WordPress.DB.PreparedSQL

	echo '<h2>Stanje</h2><ul class="ul-disc">';
	foreach ( (array) $counts as $row ) {
		printf( '<li>%s · %s: <strong>%d</strong></li>', 'term' === $row->type ? 'pojmovi' : 'objave i stranice', esc_html( strtoupper( $row->lang ) ), (int) $row->num );
	}
	if ( ! $counts ) {
		echo '<li>Još nijedan sadržaj nema jezik.</li>';
	}
	echo '</ul>';

	$unassigned = langosh_unassigned();

	echo '<h2>Sadržaj bez jezika</h2>';
	if ( $unassigned ) {
		echo '<p>Bez jezika: <strong>' . (int) $unassigned . '</strong>. Takav sadržaj vidi se u svim jezicima.</p>';
		echo '<p><button class="button" name="langosh_tool" value="assign">Dodijeli zadani jezik</button></p>';
	} else {
		echo '<p>Sav sadržaj koji se prevodi ima svoj jezik.</p>';
	}

	if ( langosh_wpml_available() ) {
		echo '<h2>Preuzimanje iz WPML-a</h2>';
		echo '<p>Preuzima jezike, popis sadržaja koji se prevodi, jezik i prijevode svih objava, stranica i pojmova, izbornike po jeziku i polja koja je WPML kopirao. WPML-ovi podaci se samo čitaju i ostaju netaknuti, pa se preuzimanje može ponoviti.</p>';
		echo '<p><button class="button button-primary" name="langosh_tool" value="wpml">Preuzmi iz WPML-a</button></p>';
		if ( Langosh::$blocked ) {
			echo '<p class="description">Nakon preuzimanja deaktivirajte WPML (i njegove dodatke); Langosh tada preuzima stranicu.</p>';
		}

		if ( ! Langosh::$blocked && function_exists( 'acf_get_field' ) ) {
			echo '<h2>ACF grupe polja iz WPML-a</h2>';
			echo '<p>WPML (ACFML) je za svaki jezik držao zasebnu kopiju svake grupe polja. Ovdje jedna grupa služi svim jezicima: sadržaj se preusmjerava na polja grupe zadanog jezika, a kopije se isključuju (ne brišu). Nakon toga povratak na WPML više nije potpun.</p>';
			echo '<p><button class="button" name="langosh_tool" value="wpml_acf" onclick="return confirm(\'Spojiti dvostruke grupe polja?\')">Spoji grupe polja</button></p>';
		}

		$copies = count( langosh_wpml_media_copies() );

		if ( $copies && ! Langosh::$blocked ) {
			echo '<h2>WPML-ove kopije medija</h2>';
			echo '<p>WPML je za svaki jezik držao zaseban unos iste datoteke u knjižnici medija: <strong>' . (int) $copies . '</strong>. Mediji ovdje nemaju jezik, pa se ti unosi vide kao duplikati. Uklanjanje preusmjerava istaknute slike i ACF polja na izvorni unos i briše samo unos kopije – datoteke ostaju. Nakon toga povratak na WPML više nije potpun.</p>';
			echo '<p><button class="button" name="langosh_tool" value="wpml_media" onclick="return confirm(\'Ukloniti unose kopija iz knjižnice medija?\')">Ukloni kopije medija</button></p>';
		}
	}
}

/**
 * How much of each translated kind of content has a version in every language, and a
 * list of what is still missing – a to-do list for translators.
 */
function langosh_tab_pregled() {
	global $wpdb;

	$settings = Langosh::settings();
	$default  = Langosh::default_lang();
	$others   = array_diff_key( Langosh::languages(), array( $default => 1 ) );
	$t        = Langosh::table();

	if ( ! $others ) {
		echo '<p>Dodajte još jedan jezik da bi se imalo što prevoditi.</p>';
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.DB.PreparedSQL
	$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
	$name = isset( $_GET['name'] ) ? sanitize_text_field( wp_unslash( $_GET['name'] ) ) : '';
	$lang = isset( $_GET['to'] ) ? sanitize_key( wp_unslash( $_GET['to'] ) ) : '';
	$url  = admin_url( 'options-general.php?page=langosh&tab=pregled' );

	echo '<p>Koliko sadržaja na zadanom jeziku (' . esc_html( Langosh::languages()[ $default ]['name'] ) . ') ima prijevod. Klik na broj onih bez prijevoda otvara njihov popis.</p>';
	echo '<table class="widefat striped" style="max-width:860px"><thead><tr><th>Sadržaj</th><th>' . esc_html( strtoupper( $default ) ) . '</th>';
	foreach ( $others as $language ) {
		echo '<th>' . esc_html( $language['name'] ) . ': prevedeno</th><th>bez prijevoda</th>';
	}
	echo '</tr></thead><tbody>';

	$rows = array();

	foreach ( array_keys( array_filter( $settings['post_types'] ) ) as $type ) {
		$object = get_post_type_object( $type );

		if ( $object ) {
			$rows[] = array( 'post', $type, $object->labels->name, $wpdb->prepare( "FROM $wpdb->posts o JOIN $t l ON l.type = 'post' AND l.id = o.ID AND l.lang = %s WHERE o.post_type = %s AND o.post_status = 'publish'", $default, $type ) );
		}
	}
	foreach ( $settings['taxonomies'] as $taxonomy ) {
		$object = get_taxonomy( $taxonomy );

		if ( $object ) {
			$rows[] = array( 'term', $taxonomy, $object->labels->name, $wpdb->prepare( "FROM $wpdb->term_taxonomy o JOIN $t l ON l.type = 'term' AND l.id = o.term_id AND l.lang = %s WHERE o.taxonomy = %s", $default, $taxonomy ) );
		}
	}

	foreach ( $rows as $row ) {
		list( $row_kind, $row_name, $label, $from ) = $row;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) $from" );

		if ( ! $total ) {
			continue;
		}

		echo '<tr><td>' . esc_html( $label ) . ' <code>' . esc_html( $row_name ) . '</code></td><td>' . esc_html( number_format_i18n( $total ) ) . '</td>';

		foreach ( $others as $code => $language ) {
			$done = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) $from AND EXISTS ( SELECT 1 FROM $t x WHERE x.grp = l.grp AND x.type = l.type AND x.lang = %s )", $code ) );
			$link = add_query_arg(
				array(
					'kind' => $row_kind,
					'name' => $row_name,
					'to'   => $code,
				),
				$url
			);

			printf( '<td>%s</td><td>%s</td>', esc_html( number_format_i18n( $done ) ), $total > $done ? '<a href="' . esc_url( $link ) . '">' . esc_html( number_format_i18n( $total - $done ) ) . '</a>' : '0' );
		}

		echo '</tr>';
	}

	echo '</tbody></table>';

	if ( ! in_array( $kind, array( 'post', 'term' ), true ) || ! isset( $others[ $lang ] ) ) {
		return;
	}

	echo '<h2>Bez prijevoda (' . esc_html( $others[ $lang ]['name'] ) . '): <code>' . esc_html( $name ) . '</code></h2>';

	if ( 'post' === $kind ) {
		$list = $wpdb->get_results( $wpdb->prepare( "SELECT o.ID AS id, o.post_title AS title FROM $wpdb->posts o JOIN $t l ON l.type = 'post' AND l.id = o.ID AND l.lang = %s WHERE o.post_type = %s AND o.post_status = 'publish' AND NOT EXISTS ( SELECT 1 FROM $t x WHERE x.grp = l.grp AND x.type = 'post' AND x.lang = %s ) ORDER BY o.post_title LIMIT 300", $default, $name, $lang ) );
	} else {
		$list = $wpdb->get_results( $wpdb->prepare( "SELECT tm.term_id AS id, tm.name AS title, o.count AS uses FROM $wpdb->term_taxonomy o JOIN $wpdb->terms tm ON tm.term_id = o.term_id JOIN $t l ON l.type = 'term' AND l.id = o.term_id AND l.lang = %s WHERE o.taxonomy = %s AND NOT EXISTS ( SELECT 1 FROM $t x WHERE x.grp = l.grp AND x.type = 'term' AND x.lang = %s ) ORDER BY o.count DESC, tm.name LIMIT 300", $default, $name, $lang ) );
	}
	// phpcs:enable

	echo '<ul class="ul-disc">';
	foreach ( (array) $list as $item ) {
		$edit = 'post' === $kind ? get_edit_post_link( (int) $item->id ) : get_edit_term_link( (int) $item->id, $name );
		$add  = 'post' === $kind && current_user_can( 'edit_post', (int) $item->id ) ? ' – <a href="' . esc_url( langosh_translate_url( (int) $item->id, $lang ) ) . '">dodaj prijevod</a>' : '';

		printf( '<li><a href="%s">%s</a>%s%s</li>', esc_url( (string) $edit ), esc_html( $item->title ? $item->title : '(bez naslova)' ), isset( $item->uses ) ? ' <span class="description">(' . (int) $item->uses . ')</span>' : '', $add ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</ul>';

	if ( count( (array) $list ) >= 300 ) {
		echo '<p class="description">Prikazano je prvih 300.</p>';
	}
}