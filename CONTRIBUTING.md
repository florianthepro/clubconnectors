# Einen Club nachtragen

Der ganze Vorgang dauert fünf Minuten. Verbindlich ist [SPEC.md](SPEC.md) –
hier steht nur, wie man vorgeht.

## 1. Datei anlegen

```
connectors/<land>/<bundesland>/<stadt>/<id>.yaml
```

`<id>` ist der Name in Kleinbuchstaben, ohne Leer- und Sonderzeichen:
`Rote Sonne` → `rotesonne`. Gibt es die `id` schon, hängst du den Ort an
(`tresorberlin`).

Kopiervorlage:

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
scrape_events: auto
scrape_images: auto
scrape_info: auto
scrape_closed: auto
```

## 2. Die vier Dinge, die schiefgehen

| | |
|---|---|
| **Koordinate** | Vier Nachkommastellen, sonst steht der Punkt bis zu einen Kilometer daneben. In OpenStreetMap oder einer Karten-App auf den Eingang zoomen und ablesen. |
| **Öffnungszeiten** | Nur was auf der Website steht. Nichts schätzen. Kein fester Rhythmus? Dann `hours` weglassen – der Club läuft als Event-Club. |
| **Musikrichtung** | Nur aus der Liste in SPEC.md Abschnitt 6, höchstens drei. `Mixed` steht allein. |
| **Beleg** | Keine Website? Dann gehört in `source`, woher die Angaben stammen. |

## 3. Prüfen

```
php tools/validate.php
```

0 Fehler heißt: der Standard ist eingehalten. Hinweise sind Arbeitsliste,
kein Hindernis. Dieselbe Prüfung läuft automatisch auf jedem Pull Request.

Wer am Validator selbst schraubt, führt zusätzlich `php tools/selftest.php`
aus – der prüft den Prüfer gegen jede einzelne Regel aus SPEC.md.

## 4. Commit

Eine Datei je Commit, und in die Nachricht kommt, **was** und **warum**:

```
Rote Sonne: Öffnungszeiten auf Fr/Sa korrigiert

Laut rote-sonne.com/programm seit 09/2026 donnerstags zu.
```

Änderungshistorie gehört in die Commit-Nachricht, nicht in `note`. Wer später
wissen will, warum eine Koordinate verschoben wurde, liest `git log`.

## Einen Club ändern

Gleiche Datei bearbeiten, `checked` auf den aktuellen Monat setzen. Wer das
Datum nicht anfasst, hat nicht nachgesehen.

## Einen Club entfernen

Geschlossen? Datei löschen, im Commit die Quelle nennen. Nur vorübergehend zu
(Umbau, Winterpause)? Nicht löschen, sondern `pause: JJJJ-MM-TT` setzen.

## Was hier nicht hineingehört

- Programm, Eventlisten, Bildadressen, Beschreibungstexte – das holt der
  Scraper zur Laufzeit von der Club-Website.
- Bars und Kneipen ohne Tanzfläche.
- Werbetexte.
- Vermutungen. Lieber ein Club weniger als eine Falschinfo.
