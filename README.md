# Langosh

Višejezičnost za WordPress u jednom dodatku: prijevodi stranica, objava, pojmova, izbornika i tekstova, ACF polja po jeziku te SEO oznake (`hreflang`, sitemap). Zamjenjuje WPML + WPML String Translation + WPML SEO na stranicama gdje je sadržaj u objavama, stranicama i ACF poljima.

**Verzija 1.3.3** · WordPress 6.3+ · PHP 7.4+ · autor: BozooArt · licenca: [GPL v2 ili novija](LICENSE)

## Zašto Langosh

- **Brz.** Na javnoj strani nema CSS-a, JavaScripta, kolačića ni AJAX poziva. Postavke i prijevodi tekstova su u dvije automatski učitane opcije, a jezik objave je jedan `JOIN` po primarnom ključu vlastite tablice.
- **Radi s predmemorijom.** Jezik se čita iz adrese (`/en/…`), nema preusmjeravanja po jeziku preglednika ni kolačića jezika.
- **Bez prepravljanja teme.** Odgovara na WPML-ove hookove (`wpml_current_language`, `wpml_object_id`, `wpml_active_languages`…), pa teme pisane za WPML rade bez izmjena.
- **Prelazak s WPML-a jednim klikom**, uključujući jezike, prijevode, izbornike, tekstove, ACF grupe polja i kopije medija.

## Brzi početak

1. Kopirati mapu `langosh` u `wp-content/plugins/` i aktivirati dodatak.
2. *Postavke → Langosh → Jezici i sadržaj*: dodati jezike (kôd, lokal, naziv), odabrati zadani i označiti što se prevodi.
3. *Postavke → Langosh → Alati → Dodijeli zadani jezik* – postojeći sadržaj dobiva zadani jezik.
4. U popisu stranica ili u okviru **Jezik i prijevodi** kliknuti **Dodaj prijevod**.
5. U temu dodati prebacivač jezika: `<?php langosh_switcher(); ?>` ili shortcode `[langosh_switcher]`.

Stranica na WPML-u? Vidi [Prelazak s WPML-a](docs/prelazak-s-wpml-a.md).

## Dokumentacija

| Vodič | Sadržaj |
|---|---|
| [Postavljanje](docs/postavljanje.md) | instalacija, jezici, što se prevodi, admin |
| [Prijevodi](docs/prijevodi.md) | stranice i objave, pojmovi, izbornici, tekstovi, ACF, mediji |
| [Adrese i SEO](docs/adrese-i-seo.md) | mape jezika, slugovi, `hreflang`, sitemap, Yoast i Rank Math |
| [Prelazak s WPML-a](docs/prelazak-s-wpml-a.md) | korak po korak, što se preuzima, povratak |
| [Za programere](docs/za-programere.md) | funkcije, upiti, hookovi, kompatibilnost s WPML-om, WP-CLI |
| [Promjene](CHANGELOG.md) | povijest verzija |

## Struktura

```
langosh.php          učitavanje; dok je WPML aktivan Langosh miruje
inc/core.php         klasa Langosh: jezici, postavke, tablica, trenutni jezik
inc/api.php          javne funkcije za teme (langosh_*)
inc/urls.php         mape jezika, trajne veze, preusmjeravanja
inc/query.php        filtriranje upita po jeziku
inc/seo.php          hreflang, sitemap, prebacivač jezika
inc/strings.php      prijevodi tekstova (opcije, langosh_t)
inc/acf.php          ACF: opcije po jeziku, „Ista vrijednost u svim jezicima"
inc/media.php        tekstovi medija po jeziku
inc/compat.php       odgovori na wpml_* hookove
inc/cli.php          WP-CLI naredbe
admin/               postavke, okvir „Jezik i prijevodi", stupci, uvoz iz WPML-a
uninstall.php        brisanje dodatka: tablica i opcije (sadržaj ostaje)
```

## Ograničenja

- Jedan način adresa: mape (`/en/`). Nema domena po jeziku ni parametra `?lang=` kao stalne adrese.
- Baze trajnih veza (`/kategorija/`, slug tipa sadržaja) iste su u svim jezicima.
- Tekstovi iz `.po/.mo` datoteka tema i dodataka dolaze iz jezičnih paketa WordPressa (prema lokalu jezika), ne prevode se u Langoshu.
- Nema strojnog prevođenja ni uređivača prijevoda „jedno uz drugo".

## Licenca

Langosh je slobodan softver pod licencom [GNU General Public License, verzija 2 ili novija](LICENSE) – istom kao WordPress. Smije se koristiti na bilo kojoj stranici (i komercijalnoj), mijenjati i dijeliti; izmijenjene inačice koje se dalje dijele moraju ostati pod istom licencom. Dodatak se daje bez ikakvog jamstva.
