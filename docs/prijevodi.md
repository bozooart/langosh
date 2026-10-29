# Prijevodi

## Stranice, objave i vlastiti tipovi sadržaja

Svaki prijevod je **zasebna objava** povezana s izvornikom u grupu prijevoda. Ima vlastiti naslov, slug, sadržaj, polja i SEO postavke.

- Prijevod se dodaje u popisu (stupac jezika) ili u okviru **Jezik i prijevodi** → **Dodaj prijevod**. Nastaje skica s kopijom svega; prevede se i objavi.
- Za tip „prevodi se, a dok prijevoda nema prikazuje se izvornik", izvornik se u drugom jeziku prikazuje na adresi tog jezika (`/en/proizvod/x/`), a popisi i pojmovi uz njega su u jeziku stranice.
- Opcije koje pokazuju na stranicu (naslovnica, stranica objava, politika privatnosti, stranice WooCommercea) na javnoj strani vraćaju stranicu u jeziku zahtjeva. Popis se proširuje filtrom `langosh_page_options`.

## Kategorije, oznake i taksonomije

Pojam ima jezik i prijevode, kao objava. Taksonomija može biti „s izvornikom": dok pojam nema prijevod, u drugom jeziku se prikazuje izvornik, a popis po prevedenoj kategoriji uključuje i objave svrstane pod izvornu.

## Izbornici

Uz svaku lokaciju izbornika teme postoji lokacija po jeziku, npr. „Glavni izbornik (English)", pod *Izgled → Izbornici → Upravljanje lokacijama*. Za svaki jezik se napravi izbornik i dodijeli lokaciji tog jezika. Tema ne treba izmjene: `wp_nav_menu( array( 'theme_location' => 'primary' ) )` sam uzima izbornik trenutnog jezika.

## Tekstovi

*Postavke → Langosh → Tekstovi*:

- **Naziv i slogan stranice** (`blogname`, `blogdescription`) i formati datuma.
- **Bilo koja opcija ili njezin unos** – npr. Yoastov predložak naslova `wpseo_titles[title-home-wpseo]`. Upisuje se naziv opcije; vrijednost zadanog jezika ostaje u opciji, a ostali jezici su prijevodi.
- **Tekstovi teme** prijavljeni s `langosh_t( 'naziv', 'Tekst na zadanom jeziku' )`. Tekst se pojavi na popisu kad administrator jednom otvori stranicu koja ga koristi.

Tekstovi iz `.po/.mo` datoteka teme i dodataka ne prevode se ovdje – učitava ih WordPress prema lokalu jezika (npr. `languages/de_DE.mo` u temi).

## ACF

- **Stranice opcija** spremljene pod `options` vode se zasebno po jeziku (`options_en_…`). Jezik se bira u gornjoj traci admina, a obavijest na stranici kaže koji se jezik uređuje. Stranica opcija s vlastitim `post_id` zajednička je svim jezicima.
- **Ista vrijednost u svim jezicima** – postavka svakog polja (sprema se uz polje i u `acf-json`). Pri spremanju objave vrijednost se upisuje u sve prijevode; unutar ponavljača kopira se redak po redak.
- **Polja koja pokazuju na objave ili pojmove** (post object, relationship, page link, taxonomy) na javnoj strani vraćaju inačicu u trenutnom jeziku. U novom prijevodu takva se polja odmah prebacuju na inačice u jeziku prijevoda.
- **Pravila prikaza grupe polja** koja imenuju stranicu („Stranica je Kontakt", „Roditelj stranice je Usluge") vrijede i za prijevode te stranice.

## Mediji

Datoteka nema jezik – ista slika služi svim jezicima. Tekstovi uz nju (naslov, opis, dugi opis, alt) mogu biti po jeziku: čuvaju se na samom unosu kao `_langosh_media_<kod>`. Uređuju se na ekranu medija s odabranim jezikom u gornjoj traci.

`wp_get_attachment_image()` (alt) i `wp_get_attachment_caption()` sami uzimaju jezik stranice; za ostalo postoji `langosh_media_text( $id, 'title' )`.

## Prebacivač jezika

```php
<?php langosh_switcher(); ?>                                     // HR EN DE
<?php langosh_switcher( array( 'label' => 'name' ) ); ?>         // Hrvatski English Deutsch
<?php langosh_switcher( array( 'skip_missing' => true ) ); ?>    // samo jezici s prijevodom
```

Shortcode: `[langosh_switcher]`. Ispisuje `<ul class="langosh-switcher">` bez stilova – stilizira ga tema. Kad stranica nema prijevod, poveznica vodi na naslovnicu tog jezika. Za vlastiti prebacivač: `langosh_switcher_items()` (vidi [Za programere](za-programere.md)).
