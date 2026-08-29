# clubconnectors

Die komplette Nightclub-Karte in einem Repo: die App **und** die Clubdaten.

- `index.php` ist die ganze Anwendung – nur Logik, keine einzige Clubadresse.
- `connectors/` sind die Clubdaten, eine Textdatei je Club, die sagt, **wo ein
  Club steht und wie seine Website ausgelesen wird** – nie, was gerade dort steht.

```
index.php                                    <- die App (nur Logik, ~1400 Zeilen)
connectors/de/bayern/muenchen/rotesonne.yaml <- ein Club
           │  │     │         └── <id>.yaml
           │  │     └── Stadt
           │  └── Bundesland
           └── Land
data/cache/live.json                         <- die einzige Datei, die die App schreibt
tools/  SPEC.md                              <- Prüfer und Geocoder, Standard
.htaccess  Caddyfile                         <- sperren die Daten für den Webserver
```

| | |
|---|---|
| **Standard** | [SPEC.md](SPEC.md) – verbindlich, jede Regel ist maschinell geprüft |
| **Prüfen** | `php tools/validate.php` (und `php tools/selftest.php` für den Prüfer selbst) |
| **App-Handbuch** | [APP.md](APP.md) – Aufspielen, Bedienung, Fehlersuche |
| **Mitmachen** | [CONTRIBUTING.md](CONTRIBUTING.md) |
| **Format-Version** | siehe [VERSION](VERSION) |

## Stand

**248 Connectoren** in 27 Städten, jeder einzeln belegt.

| Bundesland | Clubs | Städte |
|---|---:|---|
| Bayern | 109 | München, Nürnberg, Augsburg, Würzburg, Regensburg, Erlangen, Fürth, Bamberg, Bayreuth, Landshut, Passau, Aschaffenburg, Kempten |
| Baden-Württemberg | 80 | Stuttgart, Freiburg, Karlsruhe, Mannheim, Heidelberg, Ulm, Konstanz, Tübingen, Reutlingen, Esslingen, Ravensburg, Friedrichshafen |
| Berlin | 56 | Berlin |
| Hamburg | 3 | Hamburg |

Noch ohne Karten-Einträge: Nordrhein-Westfalen, Niedersachsen, Hessen, Sachsen,
Rheinland-Pfalz, Schleswig-Holstein, Bremen, Brandenburg, Sachsen-Anhalt,
Thüringen, Mecklenburg-Vorpommern, Saarland. Für alle liegen aber bereits
recherchierte Entwürfe in `connectors/_review/` (Überblick in `TODO.md`);
die Länderordner entstehen mit dem ersten geprüften Connector.

In `connectors/_review/` liegen Einträge, die noch nicht auf die Karte dürfen –
Ordner mit `_` werden von Validator und Karte übergangen:

- **3 Bestandseinträge mit zu ungenauer Koordinate** (1–2 Nachkommastellen,
  also bis zu einem Kilometer daneben).
- **Recherche-Entwürfe aus allen 16 Bundesländern** (Überblick in
  `TODO.md`). Jede Datei enthält nur, was die im `source`-Feld genannte
  Fundstelle hergibt; fehlende Felder sind im `source`-Feld aufgezählt.
  Es fehlt fast immer nur die Koordinate (`lat: NACHMESSEN`).

  **Die holt `php tools/geocode.php` in einem Rutsch:** das Skript fragt jede
  Adresse einmal bei OpenStreetMap, trägt die Koordinate ein und verschiebt
  die Datei in den Länderordner – danach sind die Clubs Teil der Karte.
  Bewusst hier und nicht in der laufenden Seite: die Koordinaten gehören als
  Daten ins Repo, damit jede Installation sie sofort hat und kein Besucher
  darauf wartet. Übersprungen wird, was laut Quelle geschlossen oder
  umbenannt ist; ein Treffer zählt nur, wenn Straße und Ort passen.
  Was keinen sauberen Treffer hat, bleibt Entwurf und will von Hand geprüft
  werden – manche Fundstellen sind dünn (reine Verzeichnis-Listen).

Wer eine Koordinate von Hand nachmisst, trägt sie ein und verschiebt die Datei
in den Länderordner – der Club ist beim nächsten Seitenaufruf auf der Karte.

## Aufspielen

1. `index.php` und den Ordner `connectors/` ins Web-Root legen.
2. PHP in `data/` schreiben lassen: `sudo chown -R www-data: /var/www/clubs`
3. Seite aufrufen – die Karte ist da.

Mehr braucht es nicht: kein Schlüssel, keine Datenbank, keine
Umschreibregeln im Webserver. `?diag=1` sagt in acht Zeilen, ob etwas fehlt.
Einzelheiten in [APP.md](APP.md).

## Warum App und Daten zusammen

`index.php` ändert sich selten, die Clubdaten dauernd. Beides im selben Repo
heißt: du kopierst genau eine Datei auf deinen Server, und jede Datenänderung
hat trotzdem einen Autor, ein Datum und eine Begründung in der Commit-Historie
– genau dafür ist Git da, und genau deshalb steht in `note` keine
Änderungshistorie mehr.

## Ohne Gewähr

Alle Angaben stammen aus öffentlichen Quellen und können falsch oder veraltet
sein. Vor der Fahrt lieber einmal auf der Website des Clubs nachsehen.
