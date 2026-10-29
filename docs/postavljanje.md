# Postavljanje

## Instalacija

1. Kopirati mapu `langosh` u `wp-content/plugins/` (ili učitati ZIP u *Dodaci → Dodaj novi → Učitaj dodatak*).
2. Aktivirati dodatak. Pri aktivaciji nastaje tablica `{prefix}langosh` (tip, ID, jezik, grupa prijevoda).

Ako je na stranici aktivan WPML, Langosh **miruje**: dostupan je samo ekran postavki s preuzimanjem iz WPML-a. Vidi [Prelazak s WPML-a](prelazak-s-wpml-a.md).

## Postavke → Langosh

### Jezici i sadržaj

- **Jezici** – za svaki jezik: **kôd** (postaje mapa u adresi, npr. `en` → `/en/`), **lokal** WordPressa (`en_US`, `de_DE` – po njemu se učitavaju jezični paketi i `<html lang>`) i **naziv** (prikazuje se u prebacivaču, npr. „English").
- **Zadani jezik** – nalazi se na adresi stranice bez mape. Uvijek je prvi u popisima.
- **Tipovi sadržaja** – za svaki tip:
  - *ne prevodi se* – objave nemaju jezik i iste su pod svakom mapom;
  - *prevodi se* – svaki prijevod je zasebna objava;
  - *prevodi se, a dok prijevoda nema prikazuje se izvornik* – za sadržaj koji se ne prevodi u cijelosti (npr. katalog).
- **Taksonomije** – koje se prevode i koje se prikazuju „s izvornikom" dok pojam nema prijevod.
- **Polja koja se kopiraju** – nazivi metapodataka (jedan po retku) koji se pri spremanju upisuju u sve prijevode. Za ACF polja koristi se postavka polja, vidi [Prijevodi → ACF](prijevodi.md#acf).

Stavke izbornika, mediji, ACF grupe polja i obrasci nemaju jezik.

### Tekstovi

Prijevodi naziva i slogana stranice, bilo koje opcije ili njezinog unosa (npr. Yoastovi predlošci naslova) te tekstova koje tema prijavi funkcijom `langosh_t()`. Vidi [Prijevodi → Tekstovi](prijevodi.md#tekstovi).

### Pregled prijevoda

Koliko sadržaja po tipu ima prijevod u svakom jeziku i popis onoga što nedostaje, s poveznicom za dodavanje prijevoda.

### Alati

| Alat | Što radi | WP-CLI |
|---|---|---|
| Dodijeli zadani jezik | sav sadržaj prevodivih tipova koji nema jezik dobiva zadani | `wp langosh assign` |
| Preuzmi iz WPML-a | jezici, prijevodi, izbornici, tekstovi | `wp langosh import-wpml` |
| Spoji grupe polja | jedna ACF grupa za sve jezike umjesto ACFML-ovih kopija | `wp langosh wpml-acf` |
| Ukloni kopije medija | briše WPML-ove kopije unosa medija, čuva tekstove | `wp langosh wpml-media` |

## Admin

- **Jezik u adminu** – izbornik u gornjoj traci bira jezik sadržaja koji se prikazuje u popisima i na stranicama opcija. Izbor se pamti po korisniku.
- **Stupac jezika** u popisu objava, stranica i pojmova, s poveznicama na prijevode ili **Dodaj prijevod**.
- **Okvir „Jezik i prijevodi"** na ekranu uređivanja: jezik objave, njezini prijevodi i dodavanje novog.
- **Dodaj prijevod** stvara skicu s kopijom sadržaja, svih polja, predloška i istaknute slike; kategorije i nadređena stranica postavljaju se u jeziku prijevoda. SEO polja (Yoast, Rank Math) ostaju prazna, da se naslov izvornika nikad ne objavi greškom.

## Brisanje dodatka

Deaktivacija ne mijenja ništa u bazi. **Brisanje** dodatka uklanja tablicu `{prefix}langosh` i opcije `langosh`, `langosh_strings`, `langosh_registry`; objave, pojmovi i izbornici ostaju (prijevodi postaju obične, nepovezane objave).
