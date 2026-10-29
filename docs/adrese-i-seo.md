# Adrese i SEO

## Mape jezika

Zadani jezik je na adresi stranice (`https://primjer.hr/kontakt/`), ostali u mapi svog koda (`https://primjer.hr/en/contact/`). Jezik se čita **samo iz adrese** – nema kolačića jezika ni preusmjeravanja prema jeziku preglednika, pa stranica radi s bilo kojom predmemorijom (LiteSpeed, Varnish, Cloudflare).

Pravila prepisivanja, kanonska preusmjeravanja, straničenje i feedovi rade u svakoj mapi. Ako nakon dodavanja jezika adrese u novoj mapi vraćaju 404, osvježiti stalne veze: *Postavke → Stalne veze → Spremi* ili `wp rewrite flush`.

## Slugovi

- Isti slug smije postojati u različitim jezicima (`/graviola/` i `/en/graviola/`), i za stranice s hijerarhijom.
- **Adresa u krivom jeziku** vodi na pravu inačicu: `/en/kontakt/` (hrvatski slug pod engleskom mapom) preusmjerava na `/en/contact/`, a upit u adresi (`?q=…`) se čuva. Isto za pojmove (`/en/kategorija/hrvatski-slug/`). Zato i putovi koje tema ili opcije drže samo u jednom jeziku rade u svima.
- **Sadržaj koji se ne prevodi** isti je pod svakom mapom (`/pojam/x/` i `/en/pojam/x/`); kanonska adresa, `og:url` i adresa koju pamti Yoast uvijek su one zadanog jezika.

## Oznake u `<head>`

- `<link rel="alternate" hreflang="…">` za svaki jezik koji ima prijevod te `x-default` (zadani jezik).
- `og:locale` i `og:locale:alternate`.
- `<html lang>` i lokal WordPressa prema jeziku stranice.
- Kanonska adresa u jeziku objave.

Popis alternativnih adresa mijenja se filtrom `langosh_alternates` (vidi [Za programere](za-programere.md#hookovi)).

## Sitemap

Svi jezici ulaze u sitemap WordPressa, Yoasta i Rank Matha. Svaki prijevod je zasebna adresa.

## Yoast SEO i Rank Math

- Naslov i opis svake stranice ostaju u SEO dodatku – svaki prijevod ima svoja polja. **Dodaj prijevod** ih namjerno ostavlja praznima.
- Yoastovi naslovi za 404, pretragu i arhive prevode se u *Langosh → Tekstovi* (opcija `wpseo_titles`); preuzimanje iz WPML-a ih uzima automatski.
- Rank Math: kanonska adresa i `og:url` sadržaja bez jezika su oni zadanog jezika.
