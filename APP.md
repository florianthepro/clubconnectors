# Nightclubmap – App-Handbuch

> Was die `index.php` tut, wie man sie aufspielt und was zu tun ist, wenn
> etwas fehlt. Der Connector-Standard steht in [SPEC.md](SPEC.md).

`index.php` ist die ganze App – rund 1400 Zeilen, eine Datei, keine
Abhängigkeiten außer PHP mit `curl`. Sie enthält **keine einzige Clubadresse**;
die Clubdaten liegen als Connectoren in `connectors/`.

## Aufspielen

1. `index.php` ins Web-Root legen.
2. Dafür sorgen, dass PHP in `data/` schreiben darf:
   `sudo chown -R www-data: /var/www/clubs`
3. Seite aufrufen. Findet sie keine Clubdaten, holt sie sie einmal selbst aus
   dem Repo (`data/connectors/`) und lädt neu. Fertig.

Wer die Daten lieber selbst mitbringt, legt den Ordner `connectors/` neben die
`index.php` – dann wird nichts geholt. Ohne die PHP-Erweiterung `zip` geht nur
dieser Weg.

`?diag=1` beantwortet in acht Zeilen, ob alles stimmt: PHP-Version, curl,
gefundene Connectoren, Zwischenspeicher und ob der Server ins Netz kommt.

Optional: Liegen `vendor/leaflet.js` und `vendor/leaflet.css` daneben, nimmt
die Seite die statt der Fassung von unpkg.com.

**Kein API-Schlüssel nötig.** Die Kacheln kommen von OpenStreetMap, das keinen
verlangt. (CARTO, die frühere Quelle, verlangt inzwischen einen und legt sonst
„API KEY REQUIRED" über die Karte.) Hell und Dunkel entstehen aus derselben
Quelle per Filter.

## Wie sie arbeitet

- **Beim Aufruf** liest sie die Connectoren und zeigt die Karte. Mehr nicht –
  kein Ladebalken, keine Hintergrundläufe, keine Warteschleife.
- **Beim Antippen eines Clubs** fragt sie genau einmal dessen Website ab und
  zeigt Programm, Fotos und Beschreibungstext. Das Ergebnis liegt danach
  30 Minuten im Zwischenspeicher; in der Zeit kostet ein weiteres Öffnen
  nichts.
- **Geschrieben wird genau eine Datei:** `data/cache/live.json`.

Was aus dem Connector kommt (Name, Ort, Adresse, Musik, Öffnungszeiten,
`about`), steht sofort da – auch wenn die Club-Website gerade nicht antwortet.

## Bedienung

- **Suche** über Clubname, Ort, Bundesland, Adresse und Musikrichtung.
  Umlaute sind egal: „muenchen" findet München.
- **Tag** – heute, morgen, ein beliebiger Tag oder alle. Je Zeile steht,
  wie viele Clubs übrig blieben.
- **Musik** – nach Anzahl sortiert, mehrere gleichzeitig wählbar.
- **Pin**: gefüllt = an diesem Tag geöffnet, Umriss = geschlossen.
- **Kachel**: nach unten ziehen schließt sie; ein Tipp auf eine Musikmarke
  filtert die Karte danach; „Route" öffnet die Karten-App des Geräts.
- `?club=<id>` öffnet einen Club direkt – der Link lässt sich teilen.

## Wenn etwas fehlt

| Beobachtung | Ursache | Abhilfe |
|---|---|---|
| „0 Clubs" bleibt stehen | Daten ließen sich nicht holen | Grund steht auf der Seite und in `?diag`; sonst `connectors/` von Hand daneben legen |
| „zwischenspeicher: NICHT BESCHREIBBAR" | PHP darf nicht in `data/` schreiben | `chown -R www-data: <web-root>` |
| „netz: FEHLER" | Server kommt nicht raus | Firewall/Proxy prüfen. Karte, Zeiten und Adressen gehen trotzdem |
| Karte bleibt leer | Leaflet nicht geladen | Meldung steht auf der Seite; Suche funktioniert weiter |
| Ein Club ohne Programm | Website ohne lesbare Termine | `scrape_events`/`scrape_from` im Connector anpassen (SPEC.md) |

## Entwürfe auf die Karte holen

In `connectors/_review/` liegen recherchierte Clubs, denen nur die Koordinate
fehlt. Einmal im Repo-Ordner ausführen:

```bash
php tools/geocode.php --dry     # zeigt, was es fände, ändert nichts
php tools/geocode.php           # holt die Koordinaten, verschiebt die Dateien
php tools/validate.php          # muss 0 Fehler melden
git add -A && git commit -m "Koordinaten aus OpenStreetMap" && git push
```

Die laufende Seite geocodiert **nichts** – Koordinaten gehören als Daten ins
Repo, damit jede Installation sie sofort hat.

## Webserver

Die App braucht keine Umschreibregeln. Nötig ist nur, dass die Clubdaten und
`data/` nicht als Dateien ausgeliefert werden – dafür liegen `.htaccess`
(Apache) und `Caddyfile` (Caddy) bei.

## Ohne Gewähr

Programm und Fotos stammen von den Websites der Clubs und können falsch oder
veraltet sein. Vor der Fahrt lieber dort nachsehen.
