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
.htaccess  Caddyfile                         <- statische Kacheln für Apache bzw. Caddy
```

| | |
|---|---|
| **Standard** | [SPEC.md](SPEC.md) – verbindlich, jede Regel ist maschinell geprüft |
| **Prüfen** | `php tools/validate.php` (und `php tools/selftest.php` für den Prüfer selbst) |
| **App-Handbuch** | [APP.md](APP.md) – Bedienung, Admin, `?sync`, Betrieb |
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
  Die Koordinate steht meist auf `NACHMESSEN`; wo sie schon eingetragen ist,
  ist sie doppelt belegt, der Eintrag bleibt aber wegen eines
  Zweifel-Vermerks hier (z. B. laut Treffern geschlossen oder eher
  Eventlocation/Bar als Club). Vor dem Verschieben jeden Eintrag einzeln an
  der Quelle prüfen – manche Fundstellen sind dünn (reine
  Verzeichnis-Listen).

Wer die Koordinate nachmisst, trägt sie ein und verschiebt die Datei in den
Länderordner – der Club erscheint dann von selbst auf der Karte (der
Update-Abgleich der Seite holt neue Connectoren automatisch nach).

## Aufspielen

Zwei Wege, such dir den bequemeren aus.

**A) Nur `index.php` – der Rest passiert von selbst.** Wenn dein Hoster
ausgehende Verbindungen erlaubt (siehe `?diag=1` → `outbound-test: … ok`),
brauchst du nichts weiter als Apache + PHP:

1. Die eine Datei `index.php` ins Web-Root legen.
2. Seite aufrufen. Findet sie keine Clubs, holt sie sie beim ersten Aufruf
   selbst aus diesem Repo („Clubs werden geladen …"), danach ist die Karte da.
   Aktuell hält sie sich danach allein (einmal am Tag im Hintergrund).

Kein Schlüssel, kein Ordner, kein Handgriff. Abschaltbar über
`CONNECTOR_AUTO = false` oben in der Datei.

**B) Alles hochladen – läuft überall, auch ohne ausgehende Verbindung.**

1. Repo herunterladen: oben **Code → Download ZIP** (oder `git clone`).
2. Entpacken, den **Inhalt** ins Web-Root legen (`index.php` und
   `connectors/` nebeneinander).
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
