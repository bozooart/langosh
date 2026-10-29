<?php
/**
 * WP-CLI: the tools of Postavke → Langosh → Alati and the linking of translations, for
 * setting a site up or importing content from a script.
 *
 *   wp langosh status
 *   wp langosh assign
 *   wp langosh set <id> <lang> [--type=term]
 *   wp langosh link hr:12 en:34 [--type=term]
 *   wp langosh import-wpml
 *   wp langosh wpml-media
 */

defined( 'ABSPATH' ) || exit;

final class Langosh_CLI {

	/**
	 * Languages, what is translated and how much content each language has.
	 */
	public function status() {
		global $wpdb;

		$settings = Langosh::settings();

		foreach ( $settings['languages'] as $code => $language ) {
			WP_CLI::line( sprintf( '%s  %s  %s%s', $code, $language['locale'], $language['name'], $code === $settings['default'] ? '  (zadani)' : '' ) );
		}

		$modes = array( 1 => 'prevodi se', 2 => 'prevodi se, s izvornikom' );
		foreach ( array_filter( $settings['post_types'] ) as $type => $mode ) {
			WP_CLI::line( sprintf( 'tip %s: %s', $type, $modes[ 2 === (int) $mode ? 2 : 1 ] ) );
		}
		if ( $settings['taxonomies'] ) {
			WP_CLI::line( 'taksonomije: ' . implode( ', ', $settings['taxonomies'] ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( (array) $wpdb->get_results( 'SELECT type, lang, COUNT(*) AS num FROM ' . Langosh::table() . ' GROUP BY type, lang' ) as $row ) {
			WP_CLI::line( sprintf( '%s · %s: %d', 'term' === $row->type ? 'pojmovi' : 'objave i stranice', $row->lang, $row->num ) );
		}

		WP_CLI::line( 'bez jezika: ' . langosh_unassigned() );
	}

	/**
	 * Gives the default language to all content of translated types that has none.
	 */
	public function assign() {
		$before = langosh_unassigned();
		langosh_assign_default();
		WP_CLI::success( sprintf( 'Zadani jezik (%s) dodijeljen: %d.', Langosh::default_lang(), $before - langosh_unassigned() ) );
	}

	/**
	 * Takes over languages, translations and menus from WPML (Alati → Preuzmi iz WPML-a).
	 *
	 * @subcommand import-wpml
	 */
	public function import_wpml() {
		$result = langosh_import_wpml();

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Preuzeto iz WPML-a: jezika %d, objava i stranica %d, pojmova %d, izbornika %d.', $result['languages'], $result['posts'], $result['terms'], $result['menus'] ) );
	}

	/**
	 * Merges the per-language copies of ACF field groups ACFML made (Alati → Spoji grupe polja).
	 *
	 * @subcommand wpml-acf
	 */
	public function wpml_acf() {
		if ( Langosh::$blocked ) {
			WP_CLI::error( 'Najprije deaktivirajte WPML.' );
		}

		$result = langosh_wpml_acf_groups();

		WP_CLI::success( sprintf( 'Isključeno grupa: %d, spojenih polja: %d, preusmjerenih zapisa: %d, označeno kao isto u svim jezicima: %d.', $result['groups'], $result['fields'], $result['repointed'], $result['copied'] ) );
	}

	/**
	 * Removes the library entries WPML Media made per language; the files stay.
	 *
	 * @subcommand wpml-media
	 */
	public function wpml_media() {
		if ( Langosh::$blocked ) {
			WP_CLI::error( 'Najprije deaktivirajte WPML.' );
		}

		$result = langosh_wpml_media_cleanup();

		WP_CLI::success( sprintf( 'Kopija medija: %d, uklonjeno: %d, preusmjerenih polja: %d, objava s ispravljenim tekstom: %d, sačuvanih tekstova po jeziku: %d.', $result['copies'], $result['removed'], $result['repointed'], $result['rewritten'], $result['texts'] ) );
	}

	/**
	 * Gives one post or term its language.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : ID of the post or term.
	 *
	 * <lang>
	 * : Language code.
	 *
	 * [--type=<type>]
	 * : post (default) or term.
	 */
	public function set( $args, $assoc ) {
		list( $id, $lang ) = $args;

		if ( ! Langosh::is_lang( $lang ) ) {
			WP_CLI::error( "Nepoznat jezik: $lang" );
		}
		if ( ! Langosh::set( (int) $id, isset( $assoc['type'] ) ? $assoc['type'] : 'post', $lang ) ) {
			WP_CLI::error( 'Jezik nije dodijeljen.' );
		}

		WP_CLI::success( "#$id → $lang" );
	}

	/**
	 * Makes posts or terms translations of each other.
	 *
	 * ## OPTIONS
	 *
	 * <pairs>...
	 * : Language and ID of each version, e.g. hr:12 en:34
	 *
	 * [--type=<type>]
	 * : post (default) or term.
	 */
	public function link( $args, $assoc ) {
		$ids = array();

		foreach ( $args as $pair ) {
			$parts = explode( ':', $pair );

			if ( 2 !== count( $parts ) || ! Langosh::is_lang( $parts[0] ) || (int) $parts[1] < 1 ) {
				WP_CLI::error( "Očekujem jezik:ID, dobio: $pair" );
			}

			$ids[ $parts[0] ] = (int) $parts[1];
		}

		if ( count( $ids ) < 2 ) {
			WP_CLI::error( 'Navedite barem dvije inačice, npr. hr:12 en:34' );
		}

		$grp = Langosh::link( $ids, isset( $assoc['type'] ) ? $assoc['type'] : 'post' );

		WP_CLI::success( 'Povezano (grupa ' . $grp . '): ' . implode( ' ', $args ) );
	}
}

WP_CLI::add_command( 'langosh', 'Langosh_CLI' );
