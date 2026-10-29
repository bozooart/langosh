<?php
/**
 * Media have no language: one library entry serves every language. The texts shown with
 * a file – title, caption, description, alt text – can still differ by language. They are
 * kept on the attachment as one meta entry per language, `_langosh_media_<code>`, an
 * array with any of the keys title, caption, description and alt. The WPML import fills
 * them from the copies WPML Media made; otherwise they are set by code or a meta box of
 * the site's own.
 *
 * On the site, captions and alt texts printed through WordPress' own functions come out
 * in the language of the request where a text for it exists; a theme that reads
 * `$attachment->post_excerpt` itself asks langosh_media_text() instead.
 */

defined( 'ABSPATH' ) || exit;

/**
 * A text of an attachment in a language: the text kept for that language, or the
 * attachment's own.
 *
 * @param int    $attachment_id
 * @param string $field title | caption | description | alt
 * @param string $lang  language code; the current one by default
 */
function langosh_media_text( $attachment_id, $field = 'caption', $lang = '' ) {
	$attachment_id = (int) $attachment_id;
	$lang          = $lang ? $lang : Langosh::current();
	$post          = get_post( $attachment_id );

	if ( ! $post ) {
		return '';
	}

	if ( $lang !== Langosh::default_lang() ) {
		$texts = get_post_meta( $attachment_id, '_langosh_media_' . $lang, true );

		if ( is_array( $texts ) && isset( $texts[ $field ] ) && '' !== trim( (string) $texts[ $field ] ) ) {
			return (string) $texts[ $field ];
		}
	}

	switch ( $field ) {
		case 'title':
			return (string) $post->post_title;
		case 'description':
			return (string) $post->post_content;
		case 'alt':
			return (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		default:
			return (string) $post->post_excerpt;
	}
}

/**
 * Whether the request shows the site in a language other than the default.
 */
function langosh_media_translated_request() {
	return Langosh::$front && Langosh::current() !== Langosh::default_lang();
}

add_filter(
	'wp_get_attachment_caption',
	function ( $caption, $attachment_id ) {
		if ( ! langosh_media_translated_request() ) {
			return $caption;
		}

		$own = langosh_media_text( $attachment_id, 'caption' );

		return '' !== $own ? $own : $caption;
	},
	10,
	2
);

add_filter(
	'wp_get_attachment_image_attributes',
	function ( $attr, $attachment ) {
		if ( ! langosh_media_translated_request() || ! is_object( $attachment ) ) {
			return $attr;
		}

		$texts = get_post_meta( (int) $attachment->ID, '_langosh_media_' . Langosh::current(), true );

		if ( is_array( $texts ) && ! empty( $texts['alt'] ) ) {
			$attr['alt'] = (string) $texts['alt'];
		}

		return $attr;
	},
	10,
	2
);
