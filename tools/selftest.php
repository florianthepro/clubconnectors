<?php
/*
 * Prüft den Prüfer: jede Regel aus SPEC.md bekommt eine Datei, die sie
 * bricht, und der Validator muss genau darüber meckern.
 *
 *   php tools/selftest.php
 *
 * Ohne das hier merkt niemand, wenn eine Regel beim Umbauen still ausfällt.
 */
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/ccselftest-' . getmypid();
$root = $tmp . '/connectors/de/bayern/muenchen';
@mkdir($root, 0700, true);

/* Eine Datei, die alles richtig macht – Grundlage für die Abwandlungen. */
const GOOD = "# Rote Sonne – München\n"
    . "id: pruefling\n"
    . "name: Prüfling\n"
    . "city: München\n"
    . "address: Maximiliansplatz 5\n"
    . "lat: 48.1414\n"
    . "lng: 11.5706\n"
    . "website: https://example.org\n"
    . "genres: Techno, House\n"
    . "hours: Fr,Sa 23:00-07:00\n"
    . "checked: 2026-08\n"
    . "\n"
    . "# Wie die Website ausgelesen wird\n"
    . "scrape_url: https://example.org/\n"
    . "scrape_events: auto\n"
    . "scrape_images: auto\n"
    . "scrape_info: auto\n"
    . "scrape_closed: auto\n";

/* [Name, Ersetzung im Muster, erwarteter Textbaustein in der Meldung] */
$cases = [
    ['id in Großbuchstaben', ['id: pruefling' => 'id: PRUEFLING'], 'verstößt gegen'],
    ['id passt nicht zum Dateinamen', ['id: pruefling' => 'id: anders'], 'müssen gleich sein'],
    ['name fehlt', ['name: Prüfling' . "\n" => ''], 'Pflichtfeld "name" fehlt'],
    ['city passt nicht zum Ordner', ['city: München' => 'city: Nürnberg'], 'der Ordner heißt aber'],
    ['Postleitzahl in der Adresse', ['address: Maximiliansplatz 5' => 'address: Maximiliansplatz 5, 80333'], 'Postleitzahl'],
    ['Ortsname in der Adresse', ['address: Maximiliansplatz 5' => 'address: Maximiliansplatz 5 München'], 'enthält den Ortsnamen'],
    ['lat ohne Nachkommastellen', ['lat: 48.1414' => 'lat: 48'], '3–6 Nachkommastellen'],
    ['lat außerhalb von Deutschland', ['lat: 48.1414' => 'lat: 11.5706'], 'außerhalb von de'],
    ['lat und lng vertauscht', ['lng: 11.5706' => 'lng: 48.1414'], 'außerhalb von de'],
    ['ungenaue Koordinate warnt', ['lat: 48.1414' => 'lat: 48.141'], 'Nachkommastellen'],
    ['website ohne Schema', ['website: https://example.org' => 'website: example.org'], 'keine absolute URL'],
    ['unbekanntes Genre', ['genres: Techno, House' => 'genres: Techno, Trance'], 'unbekannte Musikrichtung'],
    ['Genre nur falsch geschrieben', ['genres: Techno, House' => 'genres: techno'], 'gemeint ist "Techno"'],
    ['vier Genres', ['genres: Techno, House' => 'genres: Techno, House, Rock, Indie'], 'höchstens 3'],
    ['Mixed neben anderem', ['genres: Techno, House' => 'genres: Techno, Mixed'], 'steht allein'],
    ['Genres ohne Leerzeichen getrennt', ['genres: Techno, House' => 'genres: Techno,House'], 'mit ", " getrennt'],
    ['Uhrzeit gibt es nicht', ['hours: Fr,Sa 23:00-07:00' => 'hours: "Fr 23:00-25:00"'], 'gibt es nicht'],
    ['Öffnen gleich Schließen', ['hours: Fr,Sa 23:00-07:00' => 'hours: "Fr 23:00-23:00"'], 'sind gleich'],
    ['Tag doppelt', ['hours: Fr,Sa 23:00-07:00' => 'hours: "Fr 22:00-02:00; Fr,Sa 23:00-05:00"'], 'kommt mehrfach vor'],
    ['unbekannter Tag', ['hours: Fr,Sa 23:00-07:00' => 'hours: "Frei 23:00-05:00"'], 'unbekannter Tag'],
    ['Leerzeichen in der Tagesliste', ['hours: Fr,Sa 23:00-07:00' => 'hours: "Fr, Sa 23:00-05:00"'], 'passt nicht auf'],
    ['note zu lang', ['checked: 2026-08' => 'note: ' . str_repeat('x', 91) . "\nchecked: 2026-08"], 'erlaubt sind 90'],
    ['pause kein gültiges Datum', ['checked: 2026-08' => "pause: 2026-02-31\nchecked: 2026-08"], 'gültiges Datum'],
    ['checked fehlt', ['checked: 2026-08' . "\n" => ''], 'Pflichtfeld "checked" fehlt'],
    ['checked im falschen Format', ['checked: 2026-08' => 'checked: 08/2026'], 'muss JJJJ-MM sein'],
    ['checked in der Zukunft', ['checked: 2026-08' => 'checked: 2099-01'], 'in der Zukunft'],
    ['kein Beleg', ['website: https://example.org' . "\n" => ''], 'ist source Pflicht'],
    ['scrape_events unbekannt', ['scrape_events: auto' => 'scrape_events: xml'], 'muss auto | jsonld | text | none sein'],
    ['scrape ohne scrape_url', ['scrape_url: https://example.org/' . "\n" => ''], 'ohne scrape_url'],
    ['scrape_from kaputter Regex', ['scrape_closed: auto' => 'scrape_closed: auto' . "\n" . 'scrape_from: "([auf"'], 'kein gültiger Regex'],
    ['fremdes Portal', ['scrape_url: https://example.org/' => 'scrape_url: https://www.eventim.de/x'], 'fremdes Portal'],
    ['Feld doppelt', ['name: Prüfling' => "name: Prüfling\nname: Zweimal"], 'steht mehrfach'],
    ['unbekanntes Feld', ['checked: 2026-08' => "checked: 2026-08\nfarbe: blau"], 'unbekanntes Feld'],
    ['Reihenfolge vertauscht', ['id: pruefling' . "\n" . 'name: Prüfling' => 'name: Prüfling' . "\n" . 'id: pruefling'], 'nicht in der Reihenfolge'],
    ['Windows-Zeilenenden', [], 'Zeilenenden müssen', 'crlf'],
    ['BOM am Anfang', [], 'BOM', 'bom'],
    ['unlesbare Zeile', ['checked: 2026-08' => "checked: 2026-08\neinfach nur Text"], 'unlesbare Zeile'],
];

function run(string $dir): array
{
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/validate.php')
        . ' ' . escapeshellarg($dir . '/connectors') . ' --json 2>/dev/null';
    $out = shell_exec($cmd);
    $j = json_decode((string)$out, true);
    return is_array($j) ? $j : ['errors' => [], 'warnings' => []];
}

/* Erst das Muster selbst: es muss fehlerfrei durchgehen. */
file_put_contents($root . '/pruefling.yaml', GOOD);
$base = run($tmp);
$fails = [];
if ($base['errors']) {
    $fails[] = 'Das Muster selbst wird beanstandet: ' . $base['errors'][0]['msg'];
}
if ($base['warnings']) {
    $fails[] = 'Das Muster erzeugt Hinweise: ' . $base['warnings'][0]['msg'];
}

foreach ($cases as $c) {
    [$name, $subs, $want] = $c;
    $mode = $c[3] ?? '';
    $text = GOOD;
    foreach ($subs as $from => $to) {
        if (strpos($text, $from) === false) {
            $fails[] = "$name: Vorlage enthält \"$from\" nicht";
            continue 2;
        }
        $text = str_replace($from, $to, $text);
    }
    if ($mode === 'crlf') {
        $text = str_replace("\n", "\r\n", $text);
    }
    if ($mode === 'bom') {
        $text = "\xEF\xBB\xBF" . $text;
    }
    file_put_contents($root . '/pruefling.yaml', $text);
    $res = run($tmp);
    $msgs = array_column(array_merge($res['errors'], $res['warnings']), 'msg');
    $hit = false;
    foreach ($msgs as $m) {
        if (strpos($m, $want) !== false) {
            $hit = true;
        }
    }
    if (!$hit) {
        $fails[] = "$name: erwartet \"$want\", bekommen: " . (implode(' | ', $msgs) ?: '(nichts)');
    }
    echo ($hit ? 'ok   ' : 'FEHL ') . $name . "\n";
}

/* Doppelte id über zwei Dateien hinweg */
file_put_contents($root . '/pruefling.yaml', GOOD);
file_put_contents($root . '/zweiter.yaml', str_replace('id: pruefling', 'id: pruefling', GOOD));
$res = run($tmp);
$dupe = false;
foreach ($res['errors'] as $e) {
    if (strpos($e['msg'], 'gibt es schon in') !== false) {
        $dupe = true;
    }
}
echo ($dupe ? 'ok   ' : 'FEHL ') . "doppelte id über zwei Dateien\n";
if (!$dupe) {
    $fails[] = 'doppelte id wird nicht erkannt';
}
$coord = false;
foreach ($res['errors'] as $e) {
    if (strpos($e['msg'], 'gleiche Koordinate') !== false) {
        $coord = true;
    }
}
echo ($coord ? 'ok   ' : 'FEHL ') . "gleiche Koordinate in zwei Dateien\n";
if (!$coord) {
    $fails[] = 'gleiche Koordinate wird nicht erkannt';
}
@unlink($root . '/zweiter.yaml');

/* Entwurfsordner werden übergangen */
@mkdir($tmp . '/connectors/_review/de/bayern/muenchen', 0700, true);
file_put_contents($tmp . '/connectors/_review/de/bayern/muenchen/kaputt.yaml', "id: X\n");
$res = run($tmp);
$skipped = !$res['errors'];
echo ($skipped ? 'ok   ' : 'FEHL ') . "_review wird übergangen\n";
if (!$skipped) {
    $fails[] = '_review-Ordner wird nicht übergangen';
}

/* aufräumen */
$rm = function ($d) use (&$rm) {
    foreach (glob($d . '/*') ?: [] as $f) {
        is_dir($f) ? $rm($f) : @unlink($f);
    }
    @rmdir($d);
};
$rm($tmp);

echo "\n";
if ($fails) {
    foreach ($fails as $f) {
        echo 'FEHLGESCHLAGEN: ' . $f . "\n";
    }
    echo count($fails) . " von " . (count($cases) + 3) . " Prüfungen fehlgeschlagen\n";
    exit(1);
}
echo (count($cases) + 3) . " Prüfungen bestanden – der Validator greift bei jeder Regel\n";
exit(0);
