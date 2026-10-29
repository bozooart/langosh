# Prelazak s WPML-a

Langosh preuzima sve što WPML zna o sadržaju, a WPML-ove podatke samo čita. Dok se ne pokrene korak 5, povratak na WPML je moguć.

## Prije početka

- **Sigurnosna kopija baze.** Koraci 4 i 5 mijenjaju sadržaj.
- Provjeriti adrese engleske (ili druge) inačice, npr. otvoriti `/en/` – nakon prelaska trebaju ostati iste.

## Koraci

1. **Aktivirati Langosh dok je WPML još aktivan.** Langosh tada miruje i ne mijenja ništa na stranici.
2. ***Postavke → Langosh → Alati → Preuzmi iz WPML-a*** (`wp langosh import-wpml`). Preuzima:
   - jezike (s kodom koji WPML koristi u adresi, vidi niže) i zadani jezik;
   - koji se tipovi sadržaja prevode, a koje taksonomije stvarno imaju pojmove u drugom jeziku;
   - jezik i prijevode svih objava i pojmova;
   - izbornike po jeziku (lokacija `primary___en` …);
   - polja koja je WPML kopirao (iz njegovih postavki i `wpml-config.xml` teme);
   - prijevode naziva i slogana stranice, formata datuma i „admin texts" (npr. Yoastovi predlošci naslova);
   - ACF opcije po jeziku (`options_<kod>_…`).
   
   Sadržaj kojem WPML nije dao jezik dobiva zadani. Uvoz se smije ponoviti.
3. **Deaktivirati WPML i njegove dodatke** (WPML Multilingual CMS, String Translation, Media, ACFML). Langosh preuzima rad.
4. **ACF** (samo ako je bio aktivan ACFML): ***Alati → Spoji grupe polja*** (`wp langosh wpml-acf`). ACFML je držao kopiju svake grupe polja po jeziku; ostaje jedna grupa za sve jezike, sadržaj se preusmjerava na polja grupe zadanog jezika, polja koja je WPML kopirao označavaju se kao „Ista vrijednost u svim jezicima", a kopije grupa se isključuju (ne brišu).
5. **Mediji:** ***Alati → Ukloni kopije medija*** (`wp langosh wpml-media`). WPML Media je za svaki jezik napravio kopiju unosa slike. Istaknute slike, ACF polja, galerije i slike u tekstu objava preusmjeravaju se na izvorni unos; naslov, opis i alt kopije koji se razlikuju čuvaju se kao tekstovi tog jezika na izvorniku; briše se samo unos kopije – datoteke ostaju. **Nakon ovog koraka povratak na WPML više nije potpun.**

Provjera: `wp langosh status` – jezici, što se prevodi, koliko sadržaja ima svaki jezik i koliko ga je ostalo bez jezika.

## Kodovi jezika (WPML 5)

WPML 5 regionalne jezike drži pod kodom poput `en-us`, a u adresi koristi `display_code` (`/en/`). Langosh kôd jezika koristi kao mapu, pa od verzije 1.3.3 pri uvozu uzima WPML-ov `display_code` kad je postavljen i nije zauzet: adrese ostaju `/en/`, a sve što se preuzima (objave, izbornici, tekstovi, ACF opcije, tekstovi medija) dobiva kôd `en`.

## Tema

Langosh odgovara na WPML-ove hookove (`wpml_current_language`, `wpml_object_id`, `wpml_active_languages` …, popis u [Za programere](za-programere.md#kompatibilnost-s-wpml-om)), pa tema pisana za WPML radi bez izmjena. `wpml-config.xml` teme Langosh čita samo pri uvozu (polja s `action="copy"`).

## Čišćenje

Kad je sve provjereno, WPML-ove tablice (`{prefix}icl_*`) i opcije (`icl_*`, `wpml_*`, `WPML(…)`, `otgs_*`) mogu se obrisati. Langosh ih nakon uvoza ne koristi. Prije brisanja napraviti sigurnosnu kopiju – nakon toga povratak na WPML je moguć samo iz nje.

## Povratak na WPML

Do koraka 5: deaktivirati Langosh i ponovno aktivirati WPML. WPML-ovi podaci su netaknuti.
