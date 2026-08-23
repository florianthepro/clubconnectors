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

**Der Repo-Inhalt ist die fertige Seite.** `index.php` liegt neben
`connectors/` und findet die Clubs von selbst – kein Einrichten, kein Sync.

1. Repo herunterladen: oben **Code → Download ZIP** (oder `git clone`).
2. Entpacken, den **Inhalt** ins Web-Root deines Webspace legen
   (`index.php` und `connectors/` müssen nebeneinander liegen).
3. Seite aufrufen – die Karte ist sofort da.

Das ist der einzige Weg, der überall funktioniert, auch auf kostenlosen
Hostern. Aktualisieren: neue Version wieder herunterladen und hochladen
(oder `git pull`).

> **„Bald" auf der Seite?** Dann liegt die `index.php` ohne `connectors/`
> daneben. `?diag=1` aufrufen: steht dort `connectoren: 0 Clubs`, fehlt der
> Ordner – einfach `connectors/` aus diesem Repo mit hochladen. Der Ordner
> muss klein geschrieben `connectors` heißen und direkt neben der
> `index.php` liegen.

### Optional: Clubs per Knopfdruck aktualisieren (`?sync`)

Wenn dein Hoster **ausgehende Verbindungen erlaubt** (viele kostenlose tun
das nicht), kann die Seite die Clubs selbst aus diesem Repo nachziehen:
`data/admin.key` mit einem geheimen Schlüssel anlegen, dann
`/?sync=1&key=<schlüssel>` aufrufen. Sie lädt das Repo als Archiv und tauscht
`data/connectors/` in einem Zug aus – nutzt `zip`, und wenn das fehlt, das
`tar.gz` über `phar` (fast überall vorhanden). Ob dein Hoster überhaupt raus
darf, zeigt `?diag=1`: steht dort `outbound-test … FEHLER`, geht **kein**
Sync (das ist eine Sperre des Hosters, kein Fehler der Seite) – dann bleibt
es beim Hochladen von oben, das geht immer.

## Warum App und Daten zusammen

`index.php` ändert sich selten, die Clubdaten dauernd. Beides im selben Repo
heißt: du kopierst genau eine Datei auf deinen Server, und jede Datenänderung
hat trotzdem einen Autor, ein Datum und eine Begründung in der Commit-Historie
– genau dafür ist Git da, und genau deshalb steht in `note` keine
Änderungshistorie mehr.

## Ohne Gewähr

Alle Angaben stammen aus öffentlichen Quellen und können falsch oder veraltet
sein. Vor der Fahrt lieber einmal auf der Website des Clubs nachsehen.
