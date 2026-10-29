# Za programere

Sve funkcije postoje dok je Langosh aktivan, pa tema provjerava samo `function_exists( 'langosh_current' )`. Kodovi jezika su oni iz postavki (`hr`, `en`), ne lokali.

## Funkcije

| Funkcija | Vraća |
|---|---|
| `langosh_current()` | kôd trenutnog jezika, npr. `'hr'` |
| `langosh_default()` | kôd zadanog jezika |
| `langosh_languages()` | `kôd => array( 'code', 'locale', 'name' )`, zadani prvi |
| `langosh_lang_of( $id, $type = 'post' )` | jezik objave ili pojma (`''` ako ga nema) |
| `langosh_id( $id, $lang = '', $type = 'post', $fallback = false )` | ID prijevoda u jeziku (zadano trenutnom); `0` ako ga nema, a s `$fallback` dani ID |
| `langosh_translations( $id, $type = 'post' )` | `array( 'hr' => 12, 'en' => 34 )`, uključujući dani ID |
| `langosh_set_lang( $id, $lang, $type = 'post' )` | dodjeljuje jezik objavi ili pojmu |
| `langosh_link( $ids, $type = 'post' )` | povezuje inačice kao prijevode: `array( 'hr' => 12, 'en' => 34 )` |
| `langosh_home( $lang = '', $path = '/' )` | početna adresa jezika, npr. `https://primjer.hr/en/` |
| `langosh_switch( $lang )` / `langosh_switch()` | privremeno radi u drugom jeziku (upiti, opcije, trajne veze) / vraća trenutni |
| `langosh_t( $name, $text, $lang = '' )` | tekst teme u jeziku stranice; prevodi se u *Langosh → Tekstovi* |
| `langosh_media_text( $id, $field = 'caption', $lang = '' )` | tekst slike u jeziku: `title`, `caption`, `description`, `alt` |
| `langosh_switcher( $args )` | ispisuje prebacivač; `skip_missing`, `label` (`code`/`name`), `class`, `echo` |
| `langosh_switcher_items( $skip_missing = false )` | podaci za vlastiti prebacivač: `kôd => code, name, locale, url, active, missing` |
| `langosh_alternates( $all = false )` | `kôd => URL` trenutne stranice u jezicima koji imaju prijevod |

Primjer vlastitog prebacivača:

```php
foreach ( langosh_switcher_items() as $item ) {
	printf(
		'<a href="%s" hreflang="%s"%s>%s</a>',
		esc_url( $item['url'] ),
		esc_attr( str_replace( '_', '-', $item['locale'] ) ),
		$item['active'] ? ' aria-current="true"' : '',
		esc_html( strtoupper( $item['code'] ) )
	);
}
```

Stranica po slugu izvornika, u trenutnom jeziku:

```php
$page = get_page_by_path( 'kontakt' );
$url  = $page ? get_permalink( langosh_id( $page->ID, '', 'post', true ) ) : '';
```

## Upiti

- Upiti na javnoj strani i u adminu filtriraju se na trenutni jezik (u adminu: jezik iz gornje trake).
- `'lang' => 'en'` ili `'lang' => 'all'` u `WP_Query` i `get_terms()` bira jezik izričito.
- `get_posts()` zadano preskače filtre (`suppress_filters`) – s `'suppress_filters' => false` vraća samo trenutni jezik.
- Upiti za tipove koji se ne prevode (stavke izbornika, mediji, ACF polja, obrasci) ostaju netaknuti.

## Hookovi

| Hook | Vrsta | Argumenti | Svrha |
|---|---|---|---|
| `langosh_alternates` | filter | `$urls`, `$all` | alternativne adrese za `hreflang` i prebacivač |
| `langosh_page_options` | filter | `$options` | opcije s ID-em stranice koje vraćaju prijevod (zadano: naslovnica, stranica objava, politika privatnosti, stranice WooCommercea) |
| `langosh_duplicate_skip_meta` | filter | `$keys`, `$post` | metapodaci koji se ne kopiraju u novi prijevod |
| `langosh_duplicate_post` | akcija | `$new_id`, `$post`, `$lang` | nakon što **Dodaj prijevod** stvori skicu |

## Kompatibilnost s WPML-om

Langosh odgovara na hookove koje teme i dodaci pisani za WPML pozivaju, pa takav kôd radi bez izmjena:

`wpml_current_language`, `wpml_default_language`, `wpml_active_languages`, `wpml_object_id`, `wpml_element_language_code`, `wpml_element_trid`, `wpml_post_language_details`, `wpml_switch_language`, `wpml_home_url`, `wpml_permalink`, `wpml_is_translated_post_type`, `wpml_is_translated_taxonomy`, `wpml_set_element_language_details`, `wpml_register_single_string`, `wpml_translate_single_string`.

`wpml_active_languages` vraća ključeve koje WPML vraća (`language_code`, `native_name`, `default_locale`, `url`, `active` …), a `skip_missing` se poštuje.

## WP-CLI

```
wp langosh status                 # jezici, što se prevodi, koliko sadržaja po jeziku
wp langosh assign                 # zadani jezik svemu što ga nema
wp langosh set 34 en              # jezik jedne objave
wp langosh set 7 en --type=term   # jezik pojma
wp langosh link hr:12 en:34       # povezuje inačice kao prijevode (--type=term za pojmove)
wp langosh import-wpml            # preuzimanje iz WPML-a (radi i dok je WPML aktivan)
wp langosh wpml-acf               # spajanje ACFML-ovih grupa polja
wp langosh wpml-media             # uklanjanje WPML-ovih kopija medija
```

## Podaci

| Gdje | Što |
|---|---|
| tablica `{prefix}langosh` | `type` (`post`/`term`), `id`, `lang`, `grp` (grupa prijevoda) |
| opcija `langosh` | jezici, zadani jezik, tipovi sadržaja, taksonomije, polja koja se kopiraju, opcije s prijevodima |
| opcija `langosh_strings` | prijevodi tekstova po jeziku |
| opcija `langosh_registry` | tekstovi koje je tema prijavila s `langosh_t()` |
| `options_<kôd>_…` | ACF stranice opcija u jeziku koji nije zadani |
| post meta `_langosh_media_<kôd>` | tekstovi slike u jeziku |
| user meta `langosh_admin_lang` | jezik koji je korisnik odabrao u adminu |
