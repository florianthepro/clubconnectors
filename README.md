# clubconnectors

Die komplette Nightclub-Karte in einem Repo: die App **und** die Clubdaten.

- `index.php` ist die ganze Anwendung – nur Logik, keine einzige Clubadresse.
- `connectors/` sind die Clubdaten, eine Textdatei je Club, die sagt, **wo ein
  Club steht und wie seine Website ausgelesen wird** – nie, was gerade dort steht.

```
index.php                                    <- die App (nur Logik)
connectors/de/bayern/muenchen/rotesonne.yaml <- ein Club
           │  │     │         └── <id>.yaml
           │  │     └── Stadt
           │  └── Bundesland
           └── Land
flag/  data/  tools/  SPEC.md                 <- Icons, Cache-Sperre, Prüfer, Standard
```

| | |
|---|---|
| **Standard** | [SPEC.md](SPEC.md) – verbindlich, jede Regel ist maschinell geprüft |
| **Prüfen** | `php tools/validate.php` (und `php tools/selftest.php` für den Prüfer selbst) |
| **App-Handbuch** | [APP.md](APP.md) – Bedienung, Admin, `?sync`, Betrieb |
| **Mitmachen** | [CONTRIBUTING.md](CONTRIBUTING.md) |
| **Format-Version** | siehe [VERSION](VERSION) |

## Stand

**244 Connectoren** in 26 Städten, jeder einzeln belegt.

| Bundesland | Clubs | Städte |
|---|---:|---|
| Bayern | 109 | München, Nürnberg, Augsburg, Würzburg, Regensburg, Erlangen, Fürth, Bamberg, Bayreuth, Landshut, Passau, Aschaffenburg, Kempten |
| Baden-Württemberg | 80 | Stuttgart, Freiburg, Karlsruhe, Mannheim, Heidelberg, Ulm, Konstanz, Tübingen, Reutlingen, Esslingen, Ravensburg, Friedrichshafen |
| Berlin | 55 | Berlin |

Noch leer: Nordrhein-Westfalen, Niedersachsen, Hessen, Sachsen, Rheinland-Pfalz,
Schleswig-Holstein, Hamburg, Bremen, Brandenburg, Sachsen-Anhalt, Thüringen,
Mecklenburg-Vorpommern, Saarland. Die Ordner entstehen mit dem ersten Connector.

In `connectors/_review/` liegen **4 Einträge, deren Koordinate zu ungenau ist**
(1–2 Nachkommastellen, also bis zu einem Kilometer daneben). Ordner mit `_`
werden von Validator und Karte übergangen – wer die Koordinate nachmisst,
verschiebt die Datei zurück und der Club ist wieder auf der Karte.

## Aufspielen

Der Repo-Inhalt **ist** das fertige Web-Root. `index.php` liegt neben
`connectors/` und findet die Clubs von selbst.

- **Nur `index.php` kopieren.** Du legst die eine Datei auf deinen Webspace.
  Beim ersten Aufruf steht die Karte da, aber noch leer; einmal
  `?sync=1&key=<schlüssel>` aufrufen (siehe unten) holt die Clubs aus genau
  diesem Repo nach. Danach nur diese eine Datei aktualisieren.
- **Oder alles kopieren.** Repo als ZIP herunterladen oder klonen und den
  Inhalt ins Web-Root legen – dann sind die Clubs sofort da, ohne Sync.
  Aktuell halten mit `git pull`.

### Clubs ohne Upload nachziehen

```
/?sync=1&key=<admin-schlüssel>
```

holt den aktuellen Stand direkt aus diesem Repo und tauscht die Clubdaten in
einem Zug aus. Voraussetzung: eine Datei `data/admin.key` mit einem geheimen
Schlüssel (eine Zeile) und die PHP-Erweiterung `zip`. So bleibt die eine
`index.php` auf dem Server immer aktuell, ohne dass du etwas hochlädst.

## Warum App und Daten zusammen

`index.php` ändert sich selten, die Clubdaten dauernd. Beides im selben Repo
heißt: du kopierst genau eine Datei auf deinen Server, und jede Datenänderung
hat trotzdem einen Autor, ein Datum und eine Begründung in der Commit-Historie
– genau dafür ist Git da, und genau deshalb steht in `note` keine
Änderungshistorie mehr.

## Ohne Gewähr

Alle Angaben stammen aus öffentlichen Quellen und können falsch oder veraltet
sein. Vor der Fahrt lieber einmal auf der Website des Clubs nachsehen.
