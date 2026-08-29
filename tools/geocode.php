<?php
declare(strict_types=1);
/*
 * Koordinaten für die Entwürfe holen – EINMAL, hier, nicht zur Laufzeit.
 *
 * In connectors/_review/ liegen recherchierte Clubs, denen genau eines fehlt:
 * die Koordinate (`lat: NACHMESSEN`). Adresse, Website und Quelle stehen drin.
 * Dieses Skript fragt die Adresse bei OpenStreetMap (Nominatim), trägt die
 * gefundene Koordinate in die Datei ein und verschiebt sie in den
 * Länderordner. Danach liegen die Clubs als Daten im Repo – jede Installation
 * hat sie sofort, ohne dass irgendein Besucher darauf wartet.
 *
 *   php tools/geocode.php            # loslegen (kann jederzeit abgebrochen
 *                                    #  und erneut gestartet werden)
 *   php tools/geocode.php --dry      # nur zeigen, nichts ändern
 *   php tools/geocode.php --limit=50 # nur die ersten 50 offenen Adressen
 *
 * Danach: git add -A && git commit && git push
 *
 * Regeln, damit nichts Falsches auf die Karte kommt:
 *  - Entwürfe, deren Quelle "geschlossen", "umbenannt" o. ä. vermerkt, werden
 *    übersprungen.
 *  - Ein Treffer zählt nur, wenn Straße UND Ort zur hinterlegten Adresse
 *    passen und die Koordinate in Deutschland liegt.
 *  - Höchstens eine Abfrage je Sekunde (so will es OpenStreetMap).
 */

const UA = 'Nightclubmap-Geocode/1.0 (+https://github.com/florianthepro/clubconnectors)';
const API = 'https://nominatim.openstreetmap.org/search';
const PAUSE = 1100000;   // Mikrosekunden zwischen zwei Abfragen

$wurzel = dirname(__DIR__);
$review = $wurzel . '/connectors/_review';
$dry = in_array('--dry', $argv, true);
$limit = 0;
foreach ($argv as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) {
        $limit = (int)$m[1];
    }
}

if (!is_dir($review)) {
    fwrite(STDERR, "Kein Ordner connectors/_review – nichts zu tun.\n");
    exit(1);
}

/* Alle Felder einer flachen YAML-Datei. */
function felder(string $text): array
{
    $out = [];
    foreach (explode("\n", $text) as $zeile) {
        if ($zeile === '' || $zeile[0] === '#') {
            continue;
        }
        if (preg_match('/^([a-z_]+):\s*(.*)$/', $zeile, $m)) {
            $out[$m[1]] = trim($m[2], " \t\"'");
        }
    }
    return $out;
}

function norm(string $s): string
{
    $s = strtr(mb_strtolower($s, 'UTF-8'), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $s = str_replace(['strasse', 'str.'], 'str', $s);
    return (string)preg_replace('/[^a-z0-9]/', '', $s);
}

function hole(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => UA,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: de']]);
    $b = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($b !== false && $b !== '' && $code === 200) ? (string)$b : null;
}

/* Adresse -> Koordinate, oder null wenn der Treffer nicht sauber passt. */
function suche(string $street, string $city): ?array
{
    $roh = hole(API . '?' . http_build_query([
        'street' => $street, 'city' => $city, 'country' => 'Deutschland',
        'format' => 'jsonv2', 'addressdetails' => '1', 'limit' => '1',
    ]));
    if ($roh === null) {
        return null;
    }
    $j = json_decode($roh, true);
    if (!is_array($j) || !$j || !isset($j[0]['lat'], $j[0]['lon'])) {
        return null;
    }
    $t = $j[0];
    $lat = (float)$t['lat'];
    $lng = (float)$t['lon'];
    if ($lat < 47.2 || $lat > 55.1 || $lng < 5.8 || $lng > 15.1) {
        return null;
    }
    $adr = (array)($t['address'] ?? []);
    $ort = (string)($adr['city'] ?? $adr['town'] ?? $adr['village'] ?? $adr['municipality'] ?? '');
    $ortOk = norm($ort) === norm($city)
        || strpos(norm((string)($t['display_name'] ?? '')), norm($city)) !== false;
    $gesucht = norm((string)preg_replace('/\d+.*$/', '', $street));
    $gefunden = norm((string)($adr['road'] ?? ''));
    $strOk = $gesucht !== '' && $gefunden !== ''
        && (strpos($gefunden, $gesucht) !== false || strpos($gesucht, $gefunden) !== false);
    return ($ortOk && $strOk) ? ['lat' => round($lat, 5), 'lng' => round($lng, 5)] : null;
}

/* Alle Entwurfsdateien einsammeln */
$dateien = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($review, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && substr($f->getFilename(), -5) === '.yaml') {
        $dateien[] = $f->getPathname();
    }
}
sort($dateien);

$offen = $fertig = $ohne = $sprung = 0;
foreach ($dateien as $pfad) {
    $text = (string)file_get_contents($pfad);
    $y = felder($text);
    if (empty($y['lat']) || is_numeric($y['lat'])) {
        continue; // hat schon eine Koordinate
    }
    if (empty($y['address']) || empty($y['city'])) {
        $sprung++;
        continue;
    }
    if (preg_match('/geschlossen|CLOSED|umbenannt|Neuvermietung|abgerissen|aufgegeben/i', (string)($y['source'] ?? ''))) {
        $sprung++;
        continue;
    }
    $offen++;
    if ($limit > 0 && $offen > $limit) {
        break;
    }
    $treffer = suche($y['address'] . ', ' . $y['city'], $y['city']);
    usleep(PAUSE);
    if (!$treffer) {
        $ohne++;
        printf("  kein Treffer  %-28s %s\n", $y['city'], $y['name'] ?? basename($pfad));
        continue;
    }
    $fertig++;
    printf("  ok            %-28s %-34s %s, %s\n", $y['city'], $y['name'] ?? '', $treffer['lat'], $treffer['lng']);
    if ($dry) {
        continue;
    }
    // Koordinate eintragen und den Entwurfs-Vermerk aus source nehmen
    $neu = preg_replace('/^lat:.*$/mi', 'lat: ' . $treffer['lat'], $text, 1);
    $neu = preg_replace('/^lng:.*$/mi', 'lng: ' . $treffer['lng'], (string)$neu, 1);
    // [ \t]*$ statt \s*$: sonst frisst der Ausdruck die Leerzeile darunter
    $neu = preg_replace_callback('/^source:[ \t]*"?(.*?)"?[ \t]*$/mi', function ($m) {
        $teile = array_filter(array_map('trim', explode('; ', $m[1])), function ($t) {
            return $t !== '' && strpos($t, 'danach nach') !== 0
                && strpos($t, 'Koordinate nachmessen') === false
                && strpos($t, 'Entwurf aus TODO.md') !== 0;
        });
        $teile[] = 'Koordinate aus OpenStreetMap zur angegebenen Adresse';
        $s = implode('; ', $teile);
        return 'source: ' . (strpbrk($s, ':#"') !== false ? '"' . str_replace('"', "'", $s) . '"' : $s);
    }, (string)$neu, 1);

    // In den Länderordner verschieben – ab dort ist es ein normaler Club
    $ziel = str_replace('/connectors/_review/', '/connectors/', $pfad);
    @mkdir(dirname($ziel), 0755, true);
    file_put_contents($ziel, $neu);
    unlink($pfad);
}

echo "\n";
echo "offen gewesen: $offen\n";
echo "übernommen:    $fertig" . ($dry ? "  (--dry: nichts geschrieben)" : "  -> nach connectors/ verschoben") . "\n";
echo "ohne Treffer:  $ohne   (bleiben Entwurf)\n";
echo "übersprungen:  $sprung (ohne Adresse oder als geschlossen vermerkt)\n";
if (!$dry && $fertig > 0) {
    echo "\nJetzt: php tools/validate.php   und dann   git add -A && git commit && git push\n";
}
