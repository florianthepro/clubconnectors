# clubconnectors

Alle Club-Connectoren an einem Ort. Ein Connector ist eine kleine Textdatei, die
sagt, **wo ein Club steht und wie seine Website ausgelesen wird** – nie, was
gerade auf der Website steht.

Die Karte (`index.php`) enthält nur Logik. Die Daten stehen hier.

```
connectors/de/bayern/muenchen/rotesonne.yaml
           │  │     │         └── <id>.yaml
           │  │     └── Stadt
           │  └── Bundesland
           └── Land
```

| | |
|---|---|
| **Standard** | [SPEC.md](SPEC.md) – verbindlich, jede Regel ist maschinell geprüft |
| **Prüfen** | `php tools/validate.php` (und `php tools/selftest.php` für den Prüfer selbst) |
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

## Wie die Karte hier drankommt

Drei Wege, alle gleichwertig:

1. **Ordner kopieren** – `connectors/` ins Web-Root neben die `index.php` legen.
2. **Klonen** – `git clone https://github.com/florianthepro/clubconnectors`
   und den Ordner `connectors` verlinken. Aktualisieren mit `git pull`.
3. **Aus der Karte heraus** – `?sync=1` mit Admin-Schlüssel holt den aktuellen
   Stand direkt von GitHub und packt ihn in den Cache. Nichts installieren.

## Wozu ein eigenes Repo

Weil Clubdaten sich ständig ändern und Code nicht. Getrennt kann jeder einen
Club nachtragen, ohne die Karte anzufassen – und jede Änderung hat einen Autor,
ein Datum und eine Begründung in der Commit-Historie. Genau dafür ist Git da,
und genau deshalb steht in `note` keine Änderungshistorie mehr.

## Ohne Gewähr

Alle Angaben stammen aus öffentlichen Quellen und können falsch oder veraltet
sein. Vor der Fahrt lieber einmal auf der Website des Clubs nachsehen.
