# Club-Connector-Standard 1.0

Ein **Connector** ist eine Textdatei, die einen einzelnen Club beschreibt: wo er
steht, wann er offen hat – und **wie** seine Website ausgelesen wird. Er enthält
niemals ausgelesene Inhalte, nur die Anweisung, wie man sie holt.

Dieses Dokument ist der Standard. Was hier nicht steht, ist nicht erlaubt.
`tools/validate.php` prüft jede Datei gegen genau diese Regeln; die GitHub-Action
lässt keinen Pull Request durch, der dagegen verstößt.

---

## 1. Grundregeln

| # | Regel |
|---|---|
| G1 | **Eine Datei = ein Club.** Keine Sammel- oder Ketten-Dateien. |
| G2 | **Keine Falschinfos.** Jedes Feld muss auf der Website des Clubs (oder einem in `source` genannten Beleg) nachlesbar sein. Im Zweifel Feld weglassen. |
| G3 | **Keine ausgelesenen Inhalte.** Kein Programm, keine Bildadressen, keine Beschreibungstexte im Connector – das holt der Scraper zur Laufzeit. |
| G4 | **Kein Ratewert.** Öffnungszeiten, die nirgends stehen, gehören nicht in `hours`. Ein Club ohne belegte Zeiten läuft als reiner Event-Club. |
| G5 | **Datei ist UTF-8, ohne BOM, mit `\n`-Zeilenenden und endet mit genau einer Leerzeile.** |
| G6 | **`checked` ist Pflicht.** Wer eine Datei anfasst, prüft sie und setzt das Datum neu. |

---

## 2. Ablage

```
connectors/<land>/<bundesland>/<stadt>/<id>.yaml
```

| Ebene | Regel | Beispiel |
|---|---|---|
| `<land>` | ISO 3166-1 alpha-2, klein | `de`, `at`, `ch` |
| `<bundesland>` | Slug des Bundeslands/Kantons, aus der Liste in Abschnitt 8 | `bayern` |
| `<stadt>` | Slug des Ortes, aus `city` gebildet (Abschnitt 3) | `muenchen` |
| `<id>.yaml` | Dateiname **ist** das Feld `id`, Endung immer `.yaml` | `rotesonne.yaml` |

Ordner mit einem führenden `_` überspringen Validator **und** Karte. Dort
liegt, was noch nicht ausgeliefert werden soll:

```
connectors/_review/de/bayern/muenchen/xy.yaml    <- Klärfall, nicht auf der Karte
```

Warum ein Eintrag dort liegt, gehört in sein `source`-Feld. Ist die Sache
geklärt, wandert die Datei zurück an ihren regulären Platz.

## 3. Slug-Regeln

Ein Slug entsteht aus einem Namen so, und nur so:

1. Kleinbuchstaben
2. `ä→ae  ö→oe  ü→ue  ß→ss`
3. übrige Akzente fallen weg (`é→e`)
4. alles außer `a–z` und `0–9` wird gelöscht (auch Leerzeichen und Bindestriche)
5. Klammerzusätze im Ortsnamen entfallen vorher: `Frankfurt (Oder)` → `frankfurt`

`München` → `muenchen`, `Rote Sonne` → `rotesonne`, `P1` → `p1`.

**Bundesland-Slugs sind die Ausnahme:** sie behalten ihre Bindestriche
(`baden-wuerttemberg`, `nordrhein-westfalen`, `mecklenburg-vorpommern`).

---

## 4. Dateiformat

Eine Zeile je Feld, flach, kein verschachteltes YAML:

```
schlüssel: wert
```

- `#` am Zeilenanfang ist ein Kommentar. Ein `#` **nach** einem Wert leitet
  ebenfalls einen Kommentar ein – deshalb müssen Werte mit `#` oder `:` in
  `"…"` stehen.
- Leerzeilen sind erlaubt und gliedern die Datei.
- Reihenfolge der Felder: wie in Abschnitt 5. Der Validator warnt bei Abweichung.
- Jede Datei beginnt mit `# <name> – <city>`.

Beispiel, vollständig:

```yaml
# Rote Sonne – München
id: rotesonne
name: Rote Sonne
city: München
address: Maximiliansplatz 5
lat: 48.1414
lng: 11.5706
website: https://rote-sonne.com
genres: Techno, House
hours: Fr,Sa 23:00-07:00
note: Einzelne Events auch donnerstags
checked: 2026-08

# Wie die Website ausgelesen wird
scrape_url: https://rote-sonne.com/
scrape_events: auto
scrape_images: auto
scrape_info: auto
scrape_closed: auto
```

---

## 5. Felder

### 5.1 Stammdaten

| Feld | Pflicht | Format | Regeln |
|---|---|---|---|
| `id` | **ja** | `^[a-z0-9]{2,40}$` | Slug aus `name`. **Weltweit eindeutig** über alle Länder. Gleicher Name in zwei Städten → Stadt-Slug anhängen: `tresorberlin`, `tresormuenchen`. Eine einmal veröffentlichte `id` wird nie umgewidmet. |
| `name` | **ja** | Text, 2–60 Zeichen | Schreibweise wie der Club selbst sie führt, inklusive Groß-/Kleinschreibung. Keine Zusätze wie „Club" oder „Diskothek", wenn sie nicht zum Namen gehören. |
| `city` | **ja** | Text, 2–40 Zeichen | Amtlicher Ortsname mit Umlauten. Nicht der Stadtteil. Muss zum Ordner passen: `slug(city) == <stadt>`. |
| `address` | **ja** | Text, 4–60 Zeichen | Straße und Hausnummer. **Ohne** PLZ, ohne Ort, ohne Land. |
| `lat` | **ja** | Dezimalzahl, 3–6 Nachkommastellen | Punkt als Trenner. Muss im Land-Rahmen liegen (Abschnitt 8). 4 Stellen (≈ 11 m) sind der Richtwert; bei 3 warnt der Validator. |
| `lng` | **ja** | wie `lat` | |
| `website` | **soll** | absolute URL | `https://` bevorzugt. Startseite des Clubs. Instagram nur, wenn es keine eigene Seite gibt. |
| `genres` | **ja** | 1–3 Werte, mit `, ` getrennt | Nur Wörter aus der Liste in Abschnitt 6, exakte Schreibweise. |
| `hours` | nein | Grammatik aus Abschnitt 7 | Nur reguläre, wiederkehrende Öffnungszeiten. |
| `about` | **soll** | 1–2 Sätze, 40–220 Zeichen | **Was einen dort erwartet.** Vollständige Sätze mit Punkt, nüchtern und belegbar: Art des Ladens, Lage/Gebäude, was ihn auszeichnet („Technoclub im ehemaligen Heizkraftwerk; strenger Einlass, Fotoverbot."). Nur Aussagen, die aus den übrigen Feldern oder der belegten Quelle folgen – keine Werbetexte, nichts Erfundenes. |
| `note` | nein | Text, ≤ 90 Zeichen | **Was der Besucher lesen soll.** Ein Satz, kein Punkt am Ende, für das, was `hours` nicht ausdrückt („Einzelne Events auch donnerstags"). Keine Werbung, keine Änderungshistorie, keine Recherchenotiz – die eine gehört in die Commit-Nachricht, die andere in `source`. |
| `pause` | nein | `JJJJ-MM-TT` | Betrieb ruht **bis** zu diesem Datum (Umbau, Winterpause). Vergangene Daten werden entfernt, nicht stehengelassen. |
| `checked` | **ja** | `JJJJ-MM` | Monat, in dem die Angaben zuletzt an der Quelle geprüft wurden. Nicht in der Zukunft, nicht älter als 24 Monate. |
| `source` | nein | URL oder Text ≤ 500 Zeichen | **Woher die Angaben stammen.** Pflicht, wenn `website` fehlt. Erscheint nie in der Oberfläche – hier darf stehen, was der Nächste wissen muss („Koordinate über die Haltestelle geschätzt", „Öffnungszeiten laut OSM"). |
| `state` | nein | Bundesland-Slug | Überschreibt den Ordnernamen. Nur nötig, wenn eine Datei bewusst woanders liegt. |

### 5.2 Extraktions-Direktiven

Diese Felder sagen dem Scraper, **wie** er die Seite liest – nie, **was** dort steht.
Fehlt `scrape_url`, wird für diesen Club nichts geholt.

| Feld | Werte | Standard | Bedeutung |
|---|---|---|---|
| `scrape_url` | absolute URL | – | Die Seite mit dem Programm. Oft die Startseite, manchmal `/programm`. |
| `scrape_events` | `auto` \| `jsonld` \| `text` \| `none` | `auto` | `jsonld` nur, wenn die Seite sauberes `schema.org/Event` liefert. `text` bei Terminlisten ohne Auszeichnung. `none` schaltet ab. |
| `scrape_images` | `auto` \| `og` \| `none` | `auto` | `og` nimmt nur das Vorschaubild der Seite – richtig, wenn `auto` Logos und Icons einsammelt. |
| `scrape_info` | `auto` \| `none` | `auto` | Kurzbeschreibung des Clubs. |
| `scrape_closed` | `auto` \| `none` | `auto` | Erkennt Sonderschließungen („Betriebsferien", „wegen Umbau geschlossen"). Abschalten nur, wenn die Seite dauerhaft falsch anschlägt. |
| `scrape_info_url` | absolute URL | – | Unterseite mit dem Beschreibungstext, falls er nicht auf `scrape_url` steht. |
| `scrape_images_url` | absolute URL | – | Unterseite mit der Galerie. |
| `scrape_from` | Regex | – | Textauswertung beginnt erst ab diesem Treffer. |
| `scrape_to` | Regex | – | … und endet dort. |

Regeln:

- **E1** `scrape_*` ohne `scrape_url` ist ein Fehler.
- **E2** Alle URLs absolut, mit Schema. Kein `http://`, wenn die Seite `https` kann.
- **E3** `scrape_from`/`scrape_to` sind gültige PCRE-Ausdrücke ohne Trennzeichen und ohne Modifikatoren; sie werden vom Leser in `#…#i` gesetzt.
- **E4** Ein Connector wird nicht auf fremde Portale (Eventim, Facebook-Events, Aggregatoren) gerichtet, sondern nur auf Seiten des Clubs selbst.

Unbekannte Felder sind **kein** Fehler – der Leser ignoriert sie, der Validator
weist sie als Warnung aus. So bleibt der Standard erweiterbar.

---

## 6. Musikrichtungen (geschlossene Liste)

```
80er/90er   Black Music   Drum and Bass   Electro   Goa   Hip-Hop   House
Indie       Latin         Live            Mixed     Rock  Schlager  Techno
```

- Höchstens **drei** je Club, das Prägendste zuerst.
- `Mixed` ist der Auffangwert für „querbeet" und steht **allein oder gar nicht**. Neben einer konkreten Richtung sagt er nichts aus.
- `Live` heißt: dort spielen regelmäßig Bands.
- Neue Richtung? Erst ein Pull Request auf diese Liste, dann die Connectoren.

Die Liste ist absichtlich kurz. Vierzig Feinabstufungen machen den Musikfilter
auf dem Telefon unbenutzbar; das ist ein Bedienproblem, kein Datenproblem.

---

## 7. Grammatik von `hours`

```
hours   := block ( ";" block )*
block   := tage " " zeit "-" zeit
tage    := spanne ( "," spanne )*
spanne  := tag | tag "-" tag
tag     := "Mo" | "Di" | "Mi" | "Do" | "Fr" | "Sa" | "So"
zeit    := HH ":" MM          00:00 – 23:59, immer zweistellig
```

- Trennzeichen zwischen Blöcken ist `; ` (Semikolon + Leerzeichen).
- Innerhalb von `tage` **keine** Leerzeichen: `Fr,Sa`, nicht `Fr, Sa`.
- `Sa-Mo` ist erlaubt und läuft über den Sonntag hinweg.
- Schließzeit kleiner Öffnungszeit heißt: es geht über Mitternacht.
  `23:00-07:00` ist normal, `23:00-23:00` ist ein Fehler.
- Ein Tag darf in **einer** `hours`-Angabe nur einmal vorkommen.
- Steht die Zeile in Anführungszeichen (wegen des `:`), dann ganz:
  `hours: "Di,Do 23:00-03:00; Fr,Sa 23:00-04:00"`.

Was **nicht** hineingehört: einzelne Termine, Sommer-/Winterzeiten,
Feiertagsregelungen, „ab 23 Uhr bis open end". Dafür ist `note` da –
oder gar nichts.

---

## 8. Länder und Bundesländer

Ein Land wird durch einen Ordner unter `connectors/` angelegt und braucht einen
Eintrag hier. Punkte außerhalb des Rahmens weist der Validator ab – das fängt
vertauschte `lat`/`lng` zuverlässig ab.

| Land | Rahmen (lat) | Rahmen (lng) |
|---|---|---|
| `de` | 47.20 – 55.10 | 5.80 – 15.10 |
| `at` | 46.30 – 49.10 | 9.50 – 17.20 |
| `ch` | 45.80 – 47.85 | 5.90 – 10.55 |

Bundesland-Slugs für `de`:

```
baden-wuerttemberg  bayern  berlin  brandenburg  bremen  hamburg  hessen
mecklenburg-vorpommern  niedersachsen  nordrhein-westfalen  rheinland-pfalz
saarland  sachsen  sachsen-anhalt  schleswig-holstein  thueringen
```

Für `at` die neun Bundesländer (`wien`, `niederoesterreich`, …), für `ch` die
Kantonskürzel (`zh`, `be`, …). Neue Länder: Zeile in dieser Tabelle, Liste der
Untereinheiten, fertig.

---

## 9. Prüfen

```
php tools/validate.php                 # alles, Klartext
php tools/validate.php connectors/de   # nur ein Land
php tools/validate.php --json          # maschinenlesbar
php tools/validate.php --strict        # Hinweise zählen als Fehler
php tools/selftest.php                 # prüft den Prüfer gegen jede Regel hier
```

Rückgabewert 0 = sauber, 1 = Fehler. **Fehler** verletzen den Standard und
blockieren jeden Pull Request; **Hinweise** sind eine Arbeitsliste (ungenaue
Koordinate, fehlender Beleg, `checked` wird alt) und blockieren nichts. Die
GitHub-Action prüft ohne `--strict` und schreibt die Hinweise in die
Zusammenfassung des Laufs.

Der Validator prüft: Pflichtfelder, Wertebereiche, Slug-Übereinstimmung von
Dateiname/Ordner/`id`/`city`, globale Eindeutigkeit der `id`, den Land-Rahmen,
doppelte Koordinaten, die `hours`-Grammatik samt Tagesdopplung, das
Genre-Vokabular, gültige URLs, gültige Regexe in `scrape_from`/`scrape_to`,
Datumsformate, Zeilenenden und Kodierung.

Er prüft **nicht**, ob die Angaben stimmen. Das macht G2, und dafür ist der
Mensch zuständig, der `checked` setzt.

`tools/selftest.php` hält den Validator ehrlich: zu jeder Regel in diesem
Dokument gibt es dort eine Datei, die sie bricht, und der Validator muss
genau darüber meckern. Wer eine Regel ergänzt, ergänzt auch den Fall –
sonst fällt sie beim nächsten Umbau still aus.

---

## 10. Änderungen am Standard

Die Versionsnummer steht in `VERSION` und oben in dieser Datei.

- **Patch** (1.0 → 1.0.1): Formulierungen, Beispiele.
- **Minor** (1.0 → 1.1): neues optionales Feld, neuer Genre-Wert, neues Land.
  Alte Connectoren bleiben gültig.
- **Major** (1.0 → 2.0): ein Pflichtfeld kommt hinzu oder ändert seine Bedeutung.
  Nur mit Umstellungsskript in `tools/`.

Der Leser (`index.php`) muss jede 1.x-Datei verarbeiten können und unbekannte
Felder stillschweigend übergehen.
