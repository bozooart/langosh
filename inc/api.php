<?php
/**
 * Functions for themes and plugins. They exist whenever Langosh is active, so a theme
 * only has to check function_exists( 'langosh_current' ).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Code of the current language ('hr', 'en').
 */
function langosh_current() {
	return Langosh::current();
}

function langosh_default() {
	return Langosh::default_lang();
}

/**
 * @return array[] code => code, locale, name
 */
function langosh_languages() {
	return Langosh::languages();
}

/**
 * Language of a post or term ('' when it has none).
 */
function langosh_lang_of( $id, $type = 'post' ) {
	return Langosh::lang_of( $id, $type );
}

/**
 * ID of a post (or term, with $type 'term') in a language – the current one by default.
 * Returns 0 when there is no translation, or the given ID with $fallback.
 */
function langosh_id( $id, $lang = '', $type = 'post', $fallback = false ) {
	return Langosh::translate_id( $id, $lang, $type, $fallback );
}

/**
 * @return int[] language code => ID of every language version, the given one included
 */
function langosh_translations( $id, $type = 'post' ) {
	return Langosh::translations( $id, $type );
}

/**
 * Gives a post or term its language.
 */
function langosh_set_lang( $id, $lang, $type = 'post' ) {
	return Langosh::set( $id, $type, $lang );
}

/**
 * Makes posts (or terms) translations of each other: array( 'hr' => 12, 'en' => 34 ).
 */
function langosh_link( $ids, $type = 'post' ) {
	return Langosh::link( $ids, $type );
}

/**
 * Works in another language (queries, options, permalinks) until called without an argument.
 */
function langosh_switch( $lang = null ) {
	Langosh::switch_to( $lang );
}

/**
 * Home address of a language.
 */
function langosh_home( $lang = '', $path = '/' ) {
	return Langosh::home( $lang, $path );
}

/**
 * A text of the theme or a plugin in the current language. The name identifies the text
 * on Langosh → Tekstovi, where it shows up once an administrator has loaded a page using it.
 */
function langosh_t( $name, $text, $lang = '' ) {
	if ( ! class_exists( 'Langosh_Strings' ) ) {
		return $text;
	}
	if ( did_action( 'init' ) ) {
		Langosh_Strings::register( $name, $text );
	}

	return Langosh_Strings::translate( $name, $text, $lang );
}
