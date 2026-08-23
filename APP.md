# Nightclubmap – App-Handbuch

> Bedienung, Admin-Bereich, `?sync`, `?diag` und Betrieb der `index.php`.
> Der Connector-Standard steht in [SPEC.md](SPEC.md), das Aufspielen in [README.md](README.md).


`index.php` ist die ganze App und enthält **nur Logik** – keine einzige
Clubadresse. Die Clubdaten stehen als Connectoren in einem eigenen Repo:
**github.com/florianthepro/clubconnectors**, beschrieben durch den
Connector-Standard (`SPEC.md` dort).

```
/                         <- den Inhalt dieses Ordners ins Web-Root legen
  index.php               <- die App: Karte, Scraper, Admin. Sonst nichts.
  connectors/             <- die Clubdaten aus dem Repo
    de/bayern/muenchen/rotesonne.yaml …
    de/baden-wuerttemberg/stuttgart/…
    de/berlin/berlin/…
  data/
    .htaccess             <- sperrt Direktzugriffe auf /data
    admin.key             <- selbst anlegen: schaltet ?admin und ?sync frei
    cache/                <- wird automatisch angelegt (Scrape-Cache je Land)
    connectors/           <- entsteht bei ?sync (Kopie aus dem Repo)
  flag/
    de.ico  us.ico        <- Flaggen der Länder
    index.php             <- optionale JSON-API (wird nicht benötigt)
```

## Woher die Clubdaten kommen

`index.php` sucht sie in dieser Reihenfolge und nimmt den ersten Treffer:

| Reihenfolge | Ordner | wann |
|---|---|---|
| 1 | `data/connectors/` | wurde per `?sync` von GitHub geholt |
| 2 | `connectors/` | Ordner aus dem Repo daneben gelegt |
| 3 | `regions/` | Altbestand aus früheren Versionen |

`?diag=1` sagt, welcher es gerade ist.

### Aktualisieren, ohne etwas hochzuladen

```
/?sync=1&key=<admin-schlüssel>
```

holt den aktuellen Stand aus dem Repo, entpackt nur die `*.yaml` unter
`connectors/` und tauscht den Ordner in einem Zug aus. Der Knopf dafür steht
auch im Admin. Voraussetzungen: `data/admin.key` und die PHP-Erweiterung `zip`
(zeigt `?diag=1` an). Schutzmaßnahmen: höchstens alle 5 Minuten (`&force=1`
überspringt das), und wenn plötzlich weniger als die Hälfte der bisherigen
Connectoren ankommt, bleibt der alte Stand stehen (`&shrink=1` erlaubt es
ausdrücklich).

Wer lieber Git nutzt: `connectors/` aus dem Repo klonen oder als Submodul
einhängen, `git pull` genügt.

## So funktioniert es

- Start (`/`): **der Standort entscheidet.** Bei genau einer aktiven Region
  geht es direkt hinein; bei mehreren wählt die Position automatisch das
  nächstgelegene Land – die Flaggen bleiben als Fallback sichtbar, falls der
  Standort abgelehnt wird. `us/` ist leer und erscheint erst, wenn dort
  YAMLs liegen (`flag/us.ico` ist schon da).
- Danach läuft alles unter `?c=<land>` (z. B. `?c=de`). Der Flaggen-Button
  oben links erscheint nur, wenn es mehr als eine Region gibt.
- Website-Daten (Programm, Bilder, Infotexte) werden nach dem Laden im
  Hintergrund geholt, je Region in `data/cache/live-<land>.json` abgelegt und
  ohne Neuladen nachgereicht.
- **Sonderschließungen**: Meldungen wie „Betriebsferien" oder „wegen Umbau
  geschlossen" auf der Club-Website werden standardisiert erkannt und als
  Hinweis („Hinweis der Website: …") in Mini-Karte und Club-Kachel gezeigt –
  ohne Gewähr, abschaltbar per `scrape_closed: none` im Connector.

## Admin-Bereich (?admin)

Neuen Club ohne Handarbeit anlegen:

1. Datei `data/admin.key` mit einem geheimen Schlüssel (eine Zeile) anlegen –
   erst dadurch wird der Admin aktiv.
2. `/?admin=1&key=<schlüssel>` öffnen, Link zur Club-Website einwerfen.
3. Die Seite wird analysiert: Basisdaten ausfüllen, Infotext bestätigen,
   brauchbare Bilder anhaken, Events-Modus wählen. Aus der Auswahl wird der
   Extraktions-Agent gemappt (nur Vorschaubild → `og`, gemischt → `auto`,
   nichts → `none`) – es landen keine statischen Links in der YAML.
4. „YAML erzeugen" → „Speichern" legt die Datei nach dem Standard in
   `connectors/<land>/<bundesland>/<stadt>/` ab; der Club ist sofort auf der Karte.

Kommen die Connectoren gerade per `?sync` aus dem Repo, speichert der Admin
**nicht** – ein Club dort wäre beim nächsten Sync weg. Stattdessen zeigt er die
fertige YAML zum Kopieren: die gehört als Pull Request ins Connector-Repo.

## Datenstand

**244 Connectoren** in 26 Städten (Bayern 109, Baden-Württemberg 80,
Berlin 55). Für jeden lag ein konkreter Beleg vor; Einträge ohne Beleg wurden
nicht aufgenommen. Vier Einträge mit zu ungenauer Koordinate liegen im Repo
unter `connectors/_review/` und erscheinen bewusst nicht auf der Karte.

Noch leer: Nordrhein-Westfalen, Niedersachsen, Hessen, Sachsen,
Rheinland-Pfalz, Schleswig-Holstein, Hamburg, Bremen, Brandenburg,
Sachsen-Anhalt, Thüringen, Mecklenburg-Vorpommern, Saarland. Nachtragen geht
über den Admin-Bereich oder als Pull Request im Connector-Repo.


## Neue Region hinzufügen

1. Ordner `connectors/<land>/` anlegen und die Connector-YAMLs hineinlegen.
2. Passende Flagge als `flag/<land>.ico` ablegen.
3. Anzeigenamen optional in `index.php` bei `REGION_NAMES` ergänzen
   (ohne Eintrag wird das Kürzel groß geschrieben angezeigt).

Das war's – die Flagge und die Region erscheinen automatisch.

## Connector-Format

Verbindlich ist **SPEC.md** im Repo `florianthepro/clubconnectors`. Kurz:
eine Datei je Club unter `connectors/<land>/<bundesland>/<stadt>/<id>.yaml`,
flaches YAML, und die `scrape_*`-Felder beschreiben, **wie** die Website
ausgelesen wird – nie, was dort steht.

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
checked: 2026-08

# Wie die Website ausgelesen wird
scrape_url: https://rote-sonne.com/
scrape_events: auto        # auto | jsonld | text | none
scrape_images: auto        # auto | og | none
scrape_info: auto          # auto | none
scrape_closed: auto        # auto | none  (Sonderschließungen erkennen)
```

Ordner, die mit `_` beginnen, werden übergangen – dort liegen im Repo die
Fälle, bei denen noch etwas zu klären ist.

`php tools/validate.php` im Repo prüft jede Datei gegen den Standard;
dieselbe Prüfung läuft dort automatisch auf jedem Pull Request.


## Einregionig ohne Länderwahl

Wer nur eine Stadt/Region will, legt die YAMLs stattdessen direkt in
`data/connector/` ab. Dann entfällt die Flaggen-Startseite und die Karte
startet sofort. `connectors/` und `flag/` werden in dem Fall ignoriert.

## Bedienung

- **Zurück-Taste/-Geste** schließt erst das Foto, dann die Club-Kachel – erst
  danach verlässt man die Seite. Das Foto hat zusätzlich ein × oben rechts.
- **Suche** ist schreibweisentolerant: „muenchen", „München" und „MUNCHEN"
  finden dasselbe; gesucht wird in Name, Ort, Bundesland, Adresse und Stil.
- Wenn der Tagesfilter alle Treffer wegfiltert, steht das da – samt Knopf
  **„Alle Tage"**, statt nur „keine Treffer".
- Ein Standort weit außerhalb springt nicht auf eine leere Karte, sondern
  sagt, wie weit der nächste Club entfernt ist.
- Die Karte merkt sich die zuletzt betrachtete Stelle (nur im Browser).

## Betrieb & Wartung

- Aktualisierung: stündlich, aber effizient – unveränderte Seiten werden per
  ETag/Last-Modified (HTTP 304) bzw. Inhalts-Hash erkannt.
- Zeitbudgets sind auf Billig-Hoster ausgelegt: ein Hintergrund-Ping (`?cron=1`)
  arbeitet höchstens 22 s, der Notbetrieb im Vordergrund höchstens 10 s – damit
  bleibt beides unter dem üblichen `max_execution_time` von 30 s.
- „Hoster blockiert ausgehende Verbindungen" meldet die Seite erst, wenn zwei
  Läufe hintereinander und mindestens 12 Seiten komplett scheitern. Einzelne
  tote Club-Websites lösen die Meldung nicht mehr aus, und sie verschwindet
  von selbst, sobald wieder etwas durchkommt.
- `?c=de&check=1` prüft alle Connector-Seiten live (erreichbar? Events/Bilder/
  Infotext?), `?c=de&refresh=1` erzwingt einen Scrape (max. alle 5 min).
- `?diag=1` zeigt PHP-Version, Extensions, die aktive Connector-Quelle, den
  letzten Sync und die Connector-Zahl.
- `?debug=1` zeigt Laufzeitfehler.
- Teilbare Links: `?c=de&search=…&club=…&date=JJJJ-MM-TT` (oder `date=alle`);
  ohne `date` gilt die aktuelle Partynacht, alte Links bleiben immer gültig.
- Voraussetzungen: PHP ≥ 7.4 mit curl; gd (Homescreen-Icon) und iconv optional.

Die `index.php` in `flag/` ist eine reine Bonus-API (JSON-Liste des
Ordnerinhalts) und wird vom Betrieb nicht benötigt – man kann sie löschen,
ohne dass sich etwas ändert.
