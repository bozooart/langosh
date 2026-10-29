# Promjene

## 1.3.3 – 29. 10. 2026.
- Preuzimanje iz WPML-a uzima WPML-ov `display_code` kao kôd jezika kad se razlikuje od koda. WPML 5 regionalne jezike drži kao `en-us`, a u adresi ima `/en/`; Langosh kôd koristi kao mapu, pa bi se engleski preselio na `/en-us/`. Novi kôd dobivaju jezik, objave, pojmovi, izbornici, tekstovi, ACF opcije (`options_en-us_…` → `options_en_…`), tekstovi medija i spajanje ACF grupa.

## 1.3.2
- Tekstovi medija po jeziku (`_langosh_media_<kod>`: naslov, opis, dugi opis, alt); `langosh_media_text()`, filtri `wp_get_attachment_caption` i `wp_get_attachment_image_attributes`.
- Uklanjanje WPML-ovih kopija medija čuva te tekstove i ispravlja `[gallery ids]`, `wp-image-N` i blokove slika u tekstu objava.
- Preuzimanje iz WPML-a uzima i prijevode „admin texts" (Yoastovi naslovi i predlošci, nazivi opcija dodataka) te prijevode pojmova taksonomija koje sjedište više ne registrira.

## 1.3.1
- *Postavke → Langosh → Pregled prijevoda*: koliko sadržaja ima prijevod i popis onoga što nedostaje; jezici pojmova s popisa čitaju se jednim upitom.

## 1.3.0
- Taksonomije „s izvornikom"; adrese, popisi i pojmovi sadržaja koji se prikazuje s izvornikom.
- Isti slug u dva jezika i za tipove sadržaja s hijerarhijom; preusmjeravanje pojma u krivom jeziku.
- Rank Math.
- ACF: pravila prikaza za prijevode stranice, kopiranje polja unutar ponavljača, spajanje ACFML-ovih grupa polja.
- Opcije s ID-em stranice (naslovnica, stranice trgovine) u jeziku zahtjeva; potpunije preuzimanje iz WPML-a.

## 1.2.0
- ACF: postavka polja „Ista vrijednost u svim jezicima", polja koja pokazuju na objave vraćaju prijevod, obavijest o jeziku na stranicama opcija.
- `og:locale:alternate`; uklanjanje WPML-ovih kopija medija; WP-CLI `import-wpml` i `wpml-media`.

## 1.1.0
- Preusmjeravanje adrese u krivom jeziku na prijevod; kanonska adresa sadržaja bez jezika; WP-CLI naredbe.

## 1.0.0
- Prva verzija: jezici u mapama, prijevodi objava, stranica i pojmova, izbornici po jeziku, prijevodi tekstova, `hreflang` i sitemap.
