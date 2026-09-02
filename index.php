<?php
declare(strict_types=1);
/*
 * Nightclubmap – die ganze App in einer Datei.
 *
 * Aufbau, von oben nach unten:
 *   1. Einstellungen
 *   2. Clubdaten lesen (connectors/**.yaml – Format siehe SPEC.md)
 *   3. Öffnungszeiten und Status
 *   4. Club-Website auslesen (Programm, Bilder, Infotext)
 *   5. Endpunkte: ?club=<id>&json=1 und ?diag=1
 *   6. Die Seite: CSS, HTML, JavaScript
 *
 * Was diese Datei bewusst NICHT tut: im Hintergrund arbeiten. Es gibt keinen
 * Ladebalken, keine Stempel, keine Warteschleife. Die Karte steht sofort;
 * das Programm eines Clubs wird geholt, wenn jemand ihn antippt – eine
 * Anfrage, ein Ergebnis. Geschrieben wird genau eine Datei:
 * data/cache/live.json.
 */

date_default_timezone_set('Europe/Berlin');
if (isset($_GET['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

/* ---------- 1. Einstellungen ---------- */

const CLUB_TTL   = 1800;      // so lange gilt das Geholte als frisch (Sekunden)
const FETCH_SECS = 12;        // Zeitdeckel für einen Website-Abruf
const MAX_BYTES  = 3145728;   // 3 MB je Seite reichen weit
const MAX_BILDER = 8;
const MAX_TERMINE = 12;
/*
 * Kartenkacheln von OpenStreetMap. Bewusst diese Quelle: sie braucht keinen
 * Schlüssel und keine Anmeldung. (CARTO verlangt seit Neuestem einen API-Key
 * und legt sonst „API KEY REQUIRED" über die Karte.)
 * Hell und Dunkel entstehen daraus per CSS-Filter – eine Quelle, zwei Looks.
 */
const TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
/* Browsername für Club-Seiten: viele sperren unbekannte Aufrufer aus. */
const UA = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36';
/*
 * Woher die Clubdaten kommen, wenn sie nicht danebenliegen. Wer nur die
 * index.php hochlädt, soll trotzdem eine volle Karte bekommen: fehlt der
 * Ordner connectors/, holt die Seite ihn EINMAL aus dem Repo nach
 * data/connectors/. Danach sind es normale Dateien auf der Platte.
 */
const REPO_ZIP = 'https://codeload.github.com/florianthepro/clubconnectors/zip/refs/heads/main';
const REPO_NAME = 'florianthepro/clubconnectors';

/* Geschlossene Liste aus SPEC.md Abschnitt 6 */
const GENRES = ['80er/90er', 'Black Music', 'Drum and Bass', 'Electro', 'Goa', 'Hip-Hop',
    'House', 'Indie', 'Latin', 'Live', 'Mixed', 'Rock', 'Schlager', 'Techno'];
const LAENDER = ['baden-wuerttemberg' => 'Baden-Württemberg', 'bayern' => 'Bayern',
    'berlin' => 'Berlin', 'brandenburg' => 'Brandenburg', 'bremen' => 'Bremen',
    'hamburg' => 'Hamburg', 'hessen' => 'Hessen',
    'mecklenburg-vorpommern' => 'Mecklenburg-Vorpommern', 'niedersachsen' => 'Niedersachsen',
    'nordrhein-westfalen' => 'Nordrhein-Westfalen', 'rheinland-pfalz' => 'Rheinland-Pfalz',
    'saarland' => 'Saarland', 'sachsen' => 'Sachsen', 'sachsen-anhalt' => 'Sachsen-Anhalt',
    'schleswig-holstein' => 'Schleswig-Holstein', 'thueringen' => 'Thüringen'];

/* ---------- 2. Clubdaten lesen ---------- */

/*
 * Wo liegen die Connectoren? Beide Orte zählen: connectors/ (selbst
 * hingelegt) und data/connectors/ (von der Seite geholt). Bei gleicher id
 * gewinnt connectors/.
 */
function connector_dirs(): array
{
    $out = [];
    foreach (['connectors', 'data/connectors'] as $rel) {
        if (is_dir(__DIR__ . '/' . $rel)) {
            $out[] = __DIR__ . '/' . $rel;
        }
    }
    return $out;
}

/*
 * Alle .yaml unter einem Ordner als [Pfad, Pfad relativ zur Wurzel]. Ordner
 * und Dateien, die mit '_' beginnen, werden übergangen – dort liegen
 * Entwürfe (siehe README).
 */
function yaml_dateien(string $wurzel): Generator
{
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS),
            fn($f) => $f->getFilename()[0] !== '_' && $f->getFilename()[0] !== '.'
        )
    );
    foreach ($it as $datei) {
        if (substr($datei->getFilename(), -5) === '.yaml') {
            $rel = str_replace('\\', '/', substr($datei->getPathname(), strlen($wurzel) + 1));
            yield [$datei->getPathname(), $rel];
        }
    }
}

/*
 * Die Clubdaten einmalig aus dem Repo holen. Rückgabe: [Anzahl, Meldung].
 * Kein Hintergrundlauf, kein Stempel: entweder liegen die Dateien danach da –
 * dann fragt niemand mehr – oder es steht auf der Seite, woran es lag.
 */
function clubdaten_holen(): array
{
    $ziel = __DIR__ . '/data/connectors';
    if (!is_dir($ziel) && !@mkdir($ziel, 0775, true) && !is_dir($ziel)) {
        return [0, 'Ordner data/connectors lässt sich nicht anlegen – PHP darf hier nicht schreiben.'];
    }
    if (!@is_writable($ziel)) {
        return [0, 'data/connectors ist nicht beschreibbar – PHP darf hier nicht schreiben.'];
    }
    // Liegt dort schon etwas, wird nicht noch einmal geholt – sonst würde die
    // Seite bei unlesbaren Dateien endlos neu laden.
    if (yaml_dateien($ziel)->valid()) {
        return [0, 'In data/connectors liegen schon Dateien, aber keine ist ein lesbarer Connector – Ordner leeren und Seite neu laden.'];
    }
    if (!class_exists('ZipArchive')) {
        return [0, 'PHP-Erweiterung zip fehlt – bitte connectors/ von Hand neben die index.php legen.'];
    }
    // Nur einer lädt: ein zweiter Aufruf wartet nicht, er meldet sich einfach.
    $sperre = $ziel . '/.holen.lock';
    $lock = fopen($sperre, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) {
            fclose($lock);
        }
        return [0, 'Wird gerade geholt – Seite gleich neu laden.'];
    }
    @set_time_limit(120);
    $tmp = $ziel . '/.repo.zip';
    $fh = @fopen($tmp, 'wb');
    if (!$fh) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return [0, 'Kann nicht in data/connectors schreiben.'];
    }
    $ch = curl_init(REPO_ZIP);
    curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 90, CURLOPT_USERAGENT => 'Nightclubmap/2.0']);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $fehler = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    if (!$ok || $code !== 200) {
        @unlink($tmp);
        flock($lock, LOCK_UN);
        fclose($lock);
        return [0, 'Der Server kommt nicht an github.com heran' . ($fehler ? ' (' . $fehler . ')' : '')
            . ' – bitte connectors/ von Hand neben die index.php legen.'];
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        flock($lock, LOCK_UN);
        fclose($lock);
        return [0, 'Das Archiv ließ sich nicht öffnen.'];
    }
    $n = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        // nur die ausgelieferten Connectoren: <repo>-main/connectors/de/... .yaml
        if (!preg_match('#^[^/]+/connectors/((?:[a-z0-9-]+/)+[a-z0-9-]+\.yaml)$#i', $name, $m)) {
            continue;
        }
        if (strpos($m[1], '_') === 0 || strpos($m[1], '/_') !== false) {
            continue; // Entwürfe bleiben draußen
        }
        $inhalt = $zip->getFromIndex($i);
        if ($inhalt === false) {
            continue;
        }
        $datei = $ziel . '/' . $m[1];
        if (!is_dir(dirname($datei)) && !@mkdir(dirname($datei), 0775, true)) {
            continue;
        }
        if (@file_put_contents($datei, $inhalt) !== false) {
            $n++;
        }
    }
    $zip->close();
    @unlink($tmp);
    flock($lock, LOCK_UN);
    fclose($lock);
    @unlink($sperre);
    return $n > 0 ? [$n, ''] : [0, 'Im Archiv war keine einzige Connector-Datei.'];
}

/*
 * Eine Zeile je Feld, flach. '#' am Zeilenanfang ist Kommentar, Werte dürfen
 * in Anführungszeichen stehen (nötig, sobald ein ':' vorkommt).
 */
function yaml_flat(string $text): array
{
    $out = [];
    foreach (explode("\n", str_replace("\r", '', $text)) as $zeile) {
        $zeile = rtrim($zeile);
        if ($zeile === '' || $zeile[0] === '#') {
            continue;
        }
        if (!preg_match('/^([a-z_]{2,20}):\s*(.*)$/', $zeile, $m)) {
            continue;
        }
        $wert = trim($m[2]);
        if ($wert !== '' && ($wert[0] === '"' || $wert[0] === "'")) {
            $q = $wert[0];
            $ende = strrpos($wert, $q);
            $wert = $ende > 0 ? substr($wert, 1, $ende - 1) : substr($wert, 1);
        } elseif (($h = strpos($wert, ' #')) !== false) {
            $wert = rtrim(substr($wert, 0, $h)); // Kommentar hinter dem Wert
        }
        $out[$m[1]] = $wert;
    }
    return $out;
}

/*
 * Alle Clubs. Rückgabe: [clubs, scrapebare].
 * Ein Club ohne gültige Koordinate zählt nicht: er hätte auf der Karte
 * keinen Ort, und geraten wird hier nichts.
 */
function load_clubs(): array
{
    $clubs = [];
    $scrape = [];
    $gesehen = [];
    $dateien = [];
    foreach (connector_dirs() as $wurzel) {
        foreach (yaml_dateien($wurzel) as $d) {
            $dateien[] = $d;
        }
    }
    foreach ($dateien as [$pfad, $rel]) {
        $y = yaml_flat((string)@file_get_contents($pfad));
        if (empty($y['id']) || empty($y['name']) || isset($gesehen[$y['id']])) {
            continue;
        }
        if (!isset($y['lat'], $y['lng']) || !is_numeric($y['lat']) || !is_numeric($y['lng'])) {
            continue;
        }
        $gesehen[$y['id']] = true;
        // Bundesland steckt im Pfad: connectors/de/<land>/<stadt>/<id>.yaml
        $teile = explode('/', $rel);
        $landSlug = $y['state'] ?? ($teile[1] ?? '');
        $club = [
            'id' => $y['id'],
            'name' => $y['name'],
            'city' => $y['city'] ?? '',
            'addr' => $y['address'] ?? '',
            'lat' => round((float)$y['lat'], 6),
            'lng' => round((float)$y['lng'], 6),
            'url' => $y['website'] ?? '',
            'land' => LAENDER[$landSlug] ?? '',
            'genres' => array_values(array_intersect(
                array_map('trim', explode(',', $y['genres'] ?? '')), GENRES)),
            'hours' => parse_hours($y['hours'] ?? ''),
        ];
        foreach (['about', 'note', 'pause'] as $feld) {
            if (!empty($y[$feld])) {
                $club[$feld] = $y[$feld];
            }
        }
        if (empty($y['scrape_url'])) {
            $club['nolive'] = true; // hat keine Quelle, gar nicht erst fragen
        } else {
            $scrape[$y['id']] = [
                'url' => $y['scrape_url'],
                'events' => $y['scrape_events'] ?? 'auto',
                'images' => $y['scrape_images'] ?? 'auto',
                'info' => $y['scrape_info'] ?? 'auto',
                'closed' => $y['scrape_closed'] ?? 'auto',
                'info_url' => $y['scrape_info_url'] ?? '',
                'images_url' => $y['scrape_images_url'] ?? '',
                'from' => $y['scrape_from'] ?? '',
                'to' => $y['scrape_to'] ?? '',
            ];
        }
        $clubs[] = $club;
    }
    usort($clubs, fn($a, $b) => strcoll($a['name'], $b['name']));
    return [$clubs, $scrape];
}

/* ---------- 3. Öffnungszeiten und Status ---------- */

/*
 * "Fr,Sa 23:00-07:00; Di 22:00-03:00" -> [[[Tage 1..7], "23:00", "07:00"], …]
 * Montag ist 1. Eine Schließzeit kleiner als die Öffnungszeit heißt: über
 * Mitternacht hinaus.
 */
function parse_hours(string $s): array
{
    $tage = ['Mo' => 1, 'Di' => 2, 'Mi' => 3, 'Do' => 4, 'Fr' => 5, 'Sa' => 6, 'So' => 7];
    $out = [];
    foreach (explode(';', $s) as $block) {
        $block = trim($block);
        if ($block === '' || !preg_match('/^([A-Za-z,\-]+)\s+(\d{2}:\d{2})-(\d{2}:\d{2})$/', $block, $m)) {
            continue;
        }
        $liste = [];
        foreach (explode(',', $m[1]) as $spanne) {
            if (preg_match('/^([A-Za-z]{2})-([A-Za-z]{2})$/', $spanne, $p)
                && isset($tage[$p[1]], $tage[$p[2]])) {
                $a = $tage[$p[1]];
                $b = $tage[$p[2]];
                for ($i = 0; $i < 7; $i++) {
                    $t = (($a - 1 + $i) % 7) + 1;
                    $liste[] = $t;
                    if ($t === $b) {
                        break;
                    }
                }
            } elseif (isset($tage[$spanne])) {
                $liste[] = $tage[$spanne];
            }
        }
        if ($liste) {
            $out[] = [array_values(array_unique($liste)), $m[2], $m[3]];
        }
    }
    return $out;
}

/* ---------- 4. Club-Website auslesen ---------- */

function cache_datei(): ?string
{
    $dir = __DIR__ . '/data/cache';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }
    return @is_writable($dir) ? $dir . '/live.json' : null;
}

function cache_lesen(): array
{
    $f = cache_datei();
    if (!$f || !is_file($f)) {
        return [];
    }
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : [];
}

/* true = gespeichert. Fehlschläge werden nicht verschluckt, ?diag zeigt sie. */
function cache_schreiben(array $daten): bool
{
    $f = cache_datei();
    if (!$f) {
        return false;
    }
    $json = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || @file_put_contents($f . '.tmp', $json, LOCK_EX) === false) {
        return false;
    }
    return @rename($f . '.tmp', $f);
}

/* Eine Seite holen. Rückgabe: [html, endgültige Adresse] oder null. */
function hole(string $url): ?array
{
    if (!function_exists('curl_init') || !preg_match('#^https?://#i', $url)) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_TIMEOUT => FETCH_SECS,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT => UA,
        CURLOPT_ENCODING => '',
        // Viele Clubseiten haben schlampige Zertifikate. Wir lesen hier
        // öffentliche Programmseiten, keine Geheimnisse – deshalb tolerant.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: de,en'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ziel = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 400) {
        return null;
    }
    return [substr((string)$body, 0, MAX_BYTES), $ziel ?: $url];
}

/* Relative Adresse auf absolute bringen. */
function abs_url(string $u, string $basis): string
{
    $u = trim(html_entity_decode($u, ENT_QUOTES, 'UTF-8'));
    if ($u === '' || preg_match('#^(data|javascript|mailto):#i', $u)) {
        return '';
    }
    if (preg_match('#^https?://#i', $u)) {
        return $u;
    }
    $p = parse_url($basis);
    if (!$p || empty($p['scheme']) || empty($p['host'])) {
        return '';
    }
    $root = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (strpos($u, '//') === 0) {
        return $p['scheme'] . ':' . $u;
    }
    if ($u[0] === '/') {
        return $root . $u;
    }
    $pfad = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');
    return $root . $pfad . $u;
}

/* Bilder: Vorschaubild der Seite zuerst, dann echte Fotos aus dem Text. */
function bilder_aus(string $html, string $basis, string $modus): array
{
    $out = [];
    if (preg_match_all('#<meta[^>]+(?:property|name)=["\'](?:og:image(?::url)?|twitter:image)["\'][^>]*>#i', $html, $tags)) {
        foreach ($tags[0] as $tag) {
            if (preg_match('#content=(["\'])(.*?)\1#is', $tag, $c)) {
                $out[] = abs_url($c[2], $basis);
            }
        }
    }
    if ($modus !== 'og' && preg_match_all('#<(?:img|source)\b[^>]*>#is', $html, $m)) {
        foreach ($m[0] as $tag) {
            $u = '';
            // Lazy-Load zuerst: dort steht das echte Bild, in src oft ein Platzhalter
            foreach (['data-src', 'data-lazy-src', 'data-original', 'src'] as $attr) {
                if (preg_match('#\b' . $attr . '=(["\'])(.*?)\1#is', $tag, $a) && trim($a[2]) !== '') {
                    $u = $a[2];
                    break;
                }
            }
            if ($u === '' && preg_match('#\b(?:data-)?srcset=(["\'])(.*?)\1#is', $tag, $a)) {
                $u = trim(explode(' ', trim(explode(',', $a[2])[0]))[0]);
            }
            $u = abs_url($u, $basis);
            // Logos, Icons, Pixel und Platzhalter draußen lassen
            if ($u === '' || preg_match('#(logo|icon|sprite|avatar|placeholder|blank|spacer|pixel|1x1)#i', $u)) {
                continue;
            }
            if (!preg_match('#\.(jpe?g|png|webp|avif)(\?|$)#i', $u)) {
                continue;
            }
            $out[] = $u;
        }
    }
    $sauber = [];
    foreach ($out as $u) {
        if ($u === '') {
            continue;
        }
        $u = (string)preg_replace('#^http://#i', 'https://', $u);
        $schluessel = strtok($u, '?');           // gleiche Datei, andere Größe = Dublette
        if (isset($sauber[$schluessel])) {
            continue;
        }
        $sauber[$schluessel] = $u;
        if (count($sauber) >= MAX_BILDER) {
            break;
        }
    }
    return array_values($sauber);
}

/* Kurzbeschreibung: das, was die Seite selbst über sich sagt. */
function info_aus(string $html): string
{
    foreach (['og:description', 'description', 'twitter:description'] as $name) {
        if (preg_match('#<meta[^>]+(?:property|name)=["\']' . preg_quote($name, '#') . '["\'][^>]*>#i', $html, $tag)
            && preg_match('#content=(["\'])(.*?)\1#is', $tag[0], $c)) {
            $t = trim(html_entity_decode($c[2], ENT_QUOTES, 'UTF-8'));
            $t = trim((string)preg_replace('/\s+/u', ' ', $t));
            if (mb_strlen($t) >= 40) {
                return mb_substr($t, 0, 400);
            }
        }
    }
    return '';
}

/* Sonderschließung: „Betriebsferien", „wegen Umbau geschlossen" und Ähnliches. */
function schliessung_aus(string $html): string
{
    $text = strip_tags($html);
    $muster = '/(betriebsferien|sommerpause|winterpause|vorübergehend geschlossen|'
        . 'bleibt geschlossen|wegen umbau geschlossen|dauerhaft geschlossen|wir schließen)/iu';
    if (!preg_match($muster, $text, $m, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $von = max(0, $m[0][1] - 60);
    $satz = trim((string)preg_replace('/\s+/u', ' ', mb_strcut($text, $von, 220)));
    return mb_substr($satz, 0, 160);
}

/* Termine aus schema.org-Auszeichnung – die zuverlässigste Quelle. */
function termine_jsonld(string $html): array
{
    $out = [];
    if (!preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $m)) {
        return $out;
    }
    $sammle = function ($knoten) use (&$sammle, &$out) {
        if (!is_array($knoten)) {
            return;
        }
        $typ = $knoten['@type'] ?? '';
        $typen = is_array($typ) ? $typ : [$typ];
        foreach ($typen as $t) {
            if (is_string($t) && stripos($t, 'event') !== false && !empty($knoten['startDate'])) {
                $datum = substr((string)$knoten['startDate'], 0, 10);
                $titel = trim((string)($knoten['name'] ?? ''));
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) && $titel !== '') {
                    $out[] = ['date' => $datum, 'title' => mb_substr($titel, 0, 120)];
                }
            }
        }
        foreach ($knoten as $wert) {
            if (is_array($wert)) {
                $sammle($wert);
            }
        }
    };
    foreach ($m[1] as $roh) {
        $j = json_decode(trim($roh), true);
        if (is_array($j)) {
            $sammle($j);
        }
    }
    return $out;
}

/* Termine aus dem Fließtext: Datum, dann die Überschrift daneben. */
function termine_text(string $html, string $von, string $bis): array
{
    $text = (string)preg_replace('/\s+/u', ' ', strip_tags($html, '<h1><h2><h3><h4><li><p><br>'));
    if ($von !== '' && preg_match('#' . $von . '#i', $text, $m, PREG_OFFSET_CAPTURE)) {
        $text = substr($text, (int)$m[0][1]);
    }
    if ($bis !== '' && preg_match('#' . $bis . '#i', $text, $m, PREG_OFFSET_CAPTURE)) {
        $text = substr($text, 0, (int)$m[0][1]);
    }
    $text = (string)preg_replace('/<[^>]+>/', ' | ', $text);
    $jahr = (int)date('Y');
    $out = [];
    // 05.09. / 05.09.2026 / 5.9.26
    if (preg_match_all('/(\d{1,2})\.\s?(\d{1,2})\.\s?(\d{2,4})?([^|]{0,90})/u', $text, $tr, PREG_SET_ORDER)) {
        foreach ($tr as $t) {
            $tag = (int)$t[1];
            $mon = (int)$t[2];
            $j = $t[3] === '' ? $jahr : (int)$t[3];
            if ($j < 100) {
                $j += 2000;
            }
            if ($tag < 1 || $tag > 31 || $mon < 1 || $mon > 12 || $j < $jahr - 1 || $j > $jahr + 2) {
                continue;
            }
            $titel = trim((string)preg_replace('/\s+/u', ' ', $t[4]), " |–-\t");
            if (mb_strlen($titel) < 3) {
                continue;
            }
            $out[] = ['date' => sprintf('%04d-%02d-%02d', $j, $mon, $tag), 'title' => mb_substr($titel, 0, 120)];
        }
    }
    return $out;
}

/*
 * Einen Club holen und auswerten. Rückgabe: der Eintrag für den Cache.
 * 'ft' ist der Zeitpunkt des Versuchs – auch ein Fehlschlag zählt, sonst
 * würde eine tote Seite bei jedem Antippen neu abgerufen.
 */
function club_holen(array $cfg): array
{
    $eintrag = ['ft' => time()];
    $seite = hole($cfg['url']);
    if (!$seite) {
        return $eintrag;
    }
    [$html, $basis] = $seite;
    if ($cfg['events'] !== 'none') {
        $termine = $cfg['events'] === 'jsonld' ? termine_jsonld($html)
            : ($cfg['events'] === 'text' ? termine_text($html, $cfg['from'], $cfg['to'])
                : array_merge(termine_jsonld($html), termine_text($html, $cfg['from'], $cfg['to'])));
        // ab heute (die laufende Nacht zählt noch zum Vortag), doppelte weg
        $ab = date('Y-m-d', time() - 6 * 3600);
        $gesehen = [];
        $liste = [];
        foreach ($termine as $t) {
            if ($t['date'] < $ab) {
                continue;
            }
            $k = $t['date'] . '|' . mb_strtolower($t['title']);
            if (isset($gesehen[$k])) {
                continue;
            }
            $gesehen[$k] = true;
            $liste[] = $t;
        }
        usort($liste, fn($a, $b) => strcmp($a['date'], $b['date']));
        $eintrag['events'] = array_slice($liste, 0, MAX_TERMINE);
    }
    if ($cfg['images'] !== 'none') {
        $q = $cfg['images_url'] !== '' ? hole($cfg['images_url']) : null;
        $eintrag['images'] = bilder_aus($q ? $q[0] : $html, $q ? $q[1] : $basis, $cfg['images']);
    }
    if ($cfg['info'] !== 'none') {
        $q = $cfg['info_url'] !== '' ? hole($cfg['info_url']) : null;
        $eintrag['info'] = info_aus($q ? $q[0] : $html);
    }
    if ($cfg['closed'] !== 'none') {
        $w = schliessung_aus($html);
        if ($w !== '') {
            $eintrag['warn'] = $w;
        }
    }
    return $eintrag;
}

/* ---------- 5. Endpunkte ---------- */

[$CLUBS, $SCRAPE] = load_clubs();

/*
 * Live-Daten eines Clubs. Wird beim Antippen einmal aufgerufen – und nur dann.
 * Steht Frisches im Zwischenspeicher, kommt es von dort; sonst wird die
 * Club-Website jetzt gelesen. Ein Aufruf, ein Ergebnis, kein Nachfassen.
 */
if (isset($_GET['club'], $_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $id = is_string($_GET['club']) ? $_GET['club'] : '';
    $cache = cache_lesen();
    $eintrag = $cache[$id] ?? null;
    $frisch = $eintrag && (time() - (int)($eintrag['ft'] ?? 0)) < CLUB_TTL;
    $gespeichert = true;
    if (isset($SCRAPE[$id]) && !$frisch) {
        @set_time_limit(FETCH_SECS * 4);   // bis zu drei Seitenabrufe, plus Luft
        $eintrag = club_holen($SCRAPE[$id]);
        $cache[$id] = $eintrag;
        $gespeichert = cache_schreiben($cache);
    }
    unset($eintrag['ft']);
    echo json_encode([
        'id' => $id,
        'live' => $eintrag ?: null,
        // false heißt: der Server kann nichts behalten und müsste jedes Mal
        // neu bei der Club-Website anklopfen. Die Seite sagt das dann ehrlich.
        'gespeichert' => $gespeichert,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    exit;
}

/* Clubdaten holen. Löst die Seite genau dann aus, wenn noch keine da sind. */
if (isset($_GET['holen'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($CLUBS) {
        echo json_encode(['anzahl' => count($CLUBS), 'meldung' => '']);
        exit;
    }
    [$n, $meldung] = clubdaten_holen();
    echo json_encode(['anzahl' => $n, 'meldung' => $meldung], JSON_UNESCAPED_UNICODE);
    exit;
}

/* Kurzer Selbstbericht – beantwortet die Frage „warum sehe ich nichts?" */
if (isset($_GET['diag'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $cd = connector_dirs();
    $cf = cache_datei();
    $cache = cache_lesen();
    $mitDaten = 0;
    foreach ($cache as $e) {
        if (!empty($e['events']) || !empty($e['images']) || !empty($e['info'])) {
            $mitDaten++;
        }
    }
    echo 'PHP ' . PHP_VERSION . "\n";
    echo 'curl: ' . (function_exists('curl_init') ? 'ok' : 'FEHLT – ohne curl kein Programm, keine Bilder') . "\n";
    echo 'connectoren: ' . ($cd ? implode(', ', $cd) : 'KEIN ORDNER gefunden') . "\n";
    echo '  ' . count($CLUBS) . ' Clubs, ' . count($SCRAPE) . " davon mit Scrape-URL\n";
    if (!$CLUBS) {
        echo '  Die Seite holt sie beim Aufruf selbst aus ' . REPO_NAME . " (Ordner data/connectors).\n"
           . '  Geht das nicht, connectors/ aus dem Repo neben die index.php legen.' . "\n"
           . '  zip-Erweiterung: ' . (class_exists('ZipArchive') ? 'vorhanden' : 'FEHLT – ohne sie geht nur der Handweg') . "\n";
    }
    echo 'zwischenspeicher: ' . ($cf === null ? 'NICHT BESCHREIBBAR – data/cache anlegen und PHP schreiben lassen'
        : $cf . ' (' . (is_file($cf) ? round((int)@filesize($cf) / 1024) . ' KB' : 'noch leer') . ')') . "\n";
    echo '  ' . count($cache) . ' Clubs geholt, ' . $mitDaten . " davon mit Inhalt\n";
    if (function_exists('curl_init')) {
        $t = hole('https://www.muffatwerk.de/de/veranstaltungen');
        echo 'netz: ' . ($t ? strlen($t[0]) . ' Bytes von einer Club-Seite geholt – ok'
            : 'FEHLER – der Server kommt nicht ins Netz, Programm und Bilder bleiben leer') . "\n";
    }
    echo 'schreibrechte skriptordner: ' . (@is_writable(__DIR__) ? 'ja' : 'nein (nur data/cache wird gebraucht)') . "\n";
    exit;
}

/* ---------- 6. Die Seite ---------- */

$cache = cache_lesen();
$live = [];
foreach ($cache as $id => $e) {
    $teil = array_intersect_key((array)$e, ['events' => 1, 'images' => 1, 'info' => 1, 'warn' => 1]);
    if ($teil) {
        $live[$id] = $teil;
    }
}
$staedte = array_values(array_unique(array_filter(array_column($CLUBS, 'city'))));
sort($staedte);
$titel = count($staedte) === 1 ? 'Clubs in ' . $staedte[0] : 'Clubkarte';
$daten = json_encode(['clubs' => $CLUBS, 'live' => $live, 'leer' => count($CLUBS) === 0],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
$eigenesLeaflet = is_file(__DIR__ . '/vendor/leaflet.js') && is_file(__DIR__ . '/vendor/leaflet.css');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<title><?= htmlspecialchars($titel) ?></title>
<?php if ($eigenesLeaflet): ?>
<link rel="stylesheet" href="vendor/leaflet.css">
<?php else: ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<?php endif; ?>
<style>
/*
 * Ruhiges Schwarz-Weiß, iOS-nah: Systemschrift, weiche Ecken, wenig Linien,
 * Farbe nur dort, wo sie etwas bedeutet (offen / geschlossen / Hinweis).
 */
:root {
    --bg: #ffffff;
    --fg: #101010;
    --muted: #6b6b6b;
    --line: #e4e4e6;
    --card: #f3f3f5;
    --inv-bg: #101010;
    --inv-fg: #ffffff;
    --ok: #1a8a4c;
    --warn: #9a6400;
    --schatten: 0 1px 3px rgba(0,0,0,.07), 0 8px 28px rgba(0,0,0,.09);
    --rand: 14px;
}
@media (prefers-color-scheme: dark) {
    :root {
        --bg: #000000;
        --fg: #f2f2f2;
        --muted: #9a9a9e;
        --line: #2a2a2c;
        --card: #161618;
        --inv-bg: #f2f2f2;
        --inv-fg: #000000;
        --ok: #40d07a;
        --warn: #ffb300;
        --schatten: 0 1px 3px rgba(0,0,0,.5), 0 8px 28px rgba(0,0,0,.6);
    }
}
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { height: 100%; margin: 0; }
body {
    background: var(--bg);
    color: var(--fg);
    font: 17px/1.45 -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", Roboto, sans-serif;
    -webkit-font-smoothing: antialiased;
    overflow: hidden;
}
button, input { font: inherit; color: inherit; }
button { cursor: pointer; }

/* ---- Karte ---- */
#map { position: fixed; inset: 0; background: var(--card); }
.kartefehlt {
    position: absolute; inset: 0; z-index: 900;   /* über den Kartenebenen */
    display: grid; align-content: center; gap: 6px; padding: 0 32px;
    text-align: center; color: var(--muted); background: var(--card);
}
.kartefehlt p { margin: 0; font-size: 15px; }
.leaflet-container { font: inherit; background: var(--card); }
/* Aus der bunten OSM-Karte wird ein ruhiger Untergrund: entfärbt, und im
   Dunkelmodus invertiert. So bleibt die Aufmerksamkeit bei den Clubs. */
.leaflet-tile { filter: grayscale(1) contrast(.92) brightness(1.06); }
@media (prefers-color-scheme: dark) {
    .leaflet-tile { filter: grayscale(1) invert(1) brightness(.82) contrast(1.05); }
}
.leaflet-control-attribution {
    background: rgba(255,255,255,.72) !important;
    font-size: 10px !important;
}
@media (prefers-color-scheme: dark) {
    .leaflet-control-attribution { background: rgba(0,0,0,.6) !important; }
    .leaflet-control-attribution, .leaflet-control-attribution a { color: var(--muted) !important; }
}
/* Pin: schlichter Punkt, offen = gefüllt, sonst nur Umriss */
.pin {
    width: 15px; height: 15px; border-radius: 50%;
    background: var(--bg); border: 3px solid var(--muted);
    box-shadow: 0 1px 4px rgba(0,0,0,.35);
}
.pin.auf { background: var(--ok); border-color: var(--ok); }
.pin.aktiv { transform: scale(1.5); border-color: var(--fg); background: var(--fg); }

/* ---- Kopfleiste ---- */
.bar {
    position: fixed; z-index: 500;
    top: max(10px, env(safe-area-inset-top)); left: 10px; right: 10px;
    background: var(--bg); border-radius: 18px; box-shadow: var(--schatten);
    padding: 10px; max-width: 560px; margin: 0 auto;
}
.suchzeile { display: flex; gap: 8px; }
#q {
    flex: 1; min-width: 0; background: var(--card); border: 0;
    border-radius: 12px; padding: 11px 14px; outline: none;
}
#q::placeholder { color: var(--muted); }
.rund {
    flex: none; width: 44px; height: 44px; display: grid; place-items: center;
    background: var(--card); border: 0; border-radius: 12px; color: var(--fg);
}
.rund.an { background: var(--inv-bg); color: var(--inv-fg); }
.knopfzeile { display: flex; gap: 8px; margin-top: 8px; }
.knopf {
    background: var(--card); border: 0; border-radius: 999px;
    padding: 8px 15px; font-size: 15px; color: var(--fg);
    display: inline-flex; align-items: center; gap: 6px;
}
.knopf.an { background: var(--inv-bg); color: var(--inv-fg); }
.knopf .pfeil { font-size: 10px; opacity: .6; }
.zaehler { margin-left: auto; align-self: center; color: var(--muted); font-size: 14px; }

/* ---- Menü unter der Leiste ---- */
#menu {
    margin-top: 10px; border-top: 1px solid var(--line); padding-top: 10px;
    max-height: 52vh; overflow-y: auto;   /* die Karte muss sichtbar bleiben */
    -webkit-overflow-scrolling: touch;
}
#menu[hidden] { display: none; }
.mtitel {
    font-size: 12px; text-transform: uppercase; letter-spacing: .07em;
    color: var(--muted); margin: 2px 0 8px;
}
.liste { background: var(--card); border-radius: var(--rand); overflow: hidden; }
.zeile {
    display: flex; align-items: center; gap: 12px; width: 100%; cursor: pointer;
    padding: 12px 14px; background: none; border: 0; text-align: left;
    font-size: 16px; position: relative;
}
.zeile + .zeile { border-top: 1px solid var(--line); }
.zeile .was { flex: 1; min-width: 0; }
.zeile .wieviel { color: var(--muted); font-size: 14px; font-variant-numeric: tabular-nums; }
/* Haken nur beim Gewählten – der Platz bleibt frei, damit nichts springt */
.zeile .haken { width: 18px; display: flex; opacity: 0; }
.zeile.an .haken { opacity: 1; }
.zeile.an .was { font-weight: 600; }
.zeile.leer .was, .zeile.leer .wieviel { color: var(--muted); }
.zeile:active { background: var(--line); }
.zeile input[type=date] {
    position: absolute; inset: 0; width: 100%; height: 100%;
    opacity: 0; border: 0; padding: 0;
}
.mfuss {
    display: block; background: none; border: 0; color: var(--muted);
    font-size: 14px; padding: 10px 2px 2px;
}

/* ---- Vorschläge ---- */
#vor { margin-top: 10px; max-height: 46vh; overflow-y: auto; }
#vor[hidden] { display: none; }
.vz { display: block; width: 100%; text-align: left; background: none; border: 0; padding: 10px 4px; }
.vz + .vz { border-top: 1px solid var(--line); }
.vz b { font-weight: 600; }
.vz span { display: block; color: var(--muted); font-size: 13.5px; }

/* ---- Kachel ---- */
#schleier {
    position: fixed; inset: 0; z-index: 600; background: rgba(0,0,0,.35);
    opacity: 0; pointer-events: none; transition: opacity .2s;
}
#schleier.auf { opacity: 1; pointer-events: auto; }
#blatt {
    position: fixed; z-index: 601; left: 0; right: 0; bottom: 0;
    max-height: 86dvh; display: flex; flex-direction: column;
    background: var(--bg); border-radius: 20px 20px 0 0;
    box-shadow: var(--schatten);
    transform: translateY(101%); visibility: hidden;
    transition: transform .26s cubic-bezier(.32,.72,0,1), visibility 0s linear .26s;
    max-width: 560px; margin: 0 auto;
}
#blatt.auf { transform: none; visibility: visible; transition: transform .26s cubic-bezier(.32,.72,0,1); }
#blatt.zieh { transition: none; }
.kopf { flex: none; padding: 8px 20px 0; touch-action: none; position: relative; }
.griff { width: 36px; height: 5px; border-radius: 3px; background: var(--line); margin: 0 auto 12px; }
.kopf h2 { margin: 0 0 3px; font-size: 26px; line-height: 1.2; letter-spacing: -.024em; padding-right: 44px; }
.status { font-size: 15px; color: var(--muted); margin: 0 0 14px; }
.status.auf { color: var(--ok); font-weight: 600; }
.zu {
    position: absolute; top: 8px; right: 16px; width: 32px; height: 32px;
    border: 0; border-radius: 50%; background: var(--card); color: var(--muted);
    font-size: 18px; line-height: 1; display: grid; place-items: center;
}
.leib { overflow-y: auto; padding: 0 20px calc(24px + env(safe-area-inset-bottom)); }
.hinweis {
    background: var(--card); border-left: 3px solid var(--warn);
    border-radius: 8px; padding: 10px 12px; margin: 0 0 14px; font-size: 14.5px;
}
.gross {
    display: block; width: 100%; aspect-ratio: 16/9; object-fit: cover;
    border-radius: var(--rand); background: var(--card);
}
.klein { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-top: 6px; }
.klein img { width: 100%; height: 62px; object-fit: cover; border-radius: 9px; background: var(--card); }
.bilder { margin-bottom: 16px; }
.lead { margin: 0 0 10px; font-size: 16px; line-height: 1.45; }
.quelle { margin: 0 0 16px; font-size: 14.5px; line-height: 1.5; color: var(--muted); }
.marken { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 16px; }
.marke {
    background: var(--card); border: 0; border-radius: 999px;
    padding: 7px 14px; font-size: 14.5px; color: var(--fg);
}
.fakten { background: var(--card); border-radius: var(--rand); overflow: hidden; margin: 0 0 18px; }
.faktzeile { display: flex; gap: 14px; padding: 11px 14px; align-items: baseline; }
.faktzeile + .faktzeile { border-top: 1px solid var(--line); }
.faktzeile .k { flex: 0 0 88px; color: var(--muted); font-size: 14px; }
.faktzeile .v { flex: 1; min-width: 0; font-size: 15px; }
.tagzeile { display: flex; justify-content: space-between; gap: 12px; }
.tagzeile .d { color: var(--muted); }
.tagzeile.heute { font-weight: 600; }
.tagzeile.heute .d { color: inherit; }
.abschnitt { margin: 0 0 18px; }
.abschnitt h3 {
    margin: 0 0 8px; font-size: 12px; text-transform: uppercase;
    letter-spacing: .07em; color: var(--muted); font-weight: 600;
}
.termin { display: flex; gap: 12px; padding: 8px 0; }
.termin + .termin { border-top: 1px solid var(--line); }
.termin .wann { flex: 0 0 86px; color: var(--muted); font-size: 14px; font-variant-numeric: tabular-nums; }
.termin .titel { flex: 1; min-width: 0; font-size: 15px; }
.laedt { color: var(--muted); font-size: 14.5px; padding: 6px 0; }
.tun { display: flex; gap: 10px; margin-bottom: 4px; }
.tun a {
    flex: 1; text-align: center; text-decoration: none; padding: 13px 10px;
    border-radius: 12px; background: var(--card); color: var(--fg); font-size: 16px;
}
.tun a.stark { background: var(--inv-bg); color: var(--inv-fg); font-weight: 600; }
.fuss { color: var(--muted); font-size: 12.5px; line-height: 1.45; margin: 14px 0 0; }

/* ---- Bild groß ---- */
#lupe {
    position: fixed; inset: 0; z-index: 700; background: rgba(0,0,0,.94);
    display: grid; place-items: center; padding: 20px;
}
#lupe[hidden] { display: none; }
#lupe img { max-width: 100%; max-height: 100%; border-radius: 10px; }

@media (min-width: 620px) {
    .bar { top: 16px; }
    #blatt { border-radius: 20px; bottom: 16px; left: 16px; right: auto; width: 400px; max-height: 82dvh; }
    #blatt:not(.auf) { transform: translateY(calc(100% + 24px)); }
}
</style>
</head>
<body>
<div id="map"></div>

<div class="bar">
    <div class="suchzeile">
        <input id="q" type="search" placeholder="Club oder Ort …" autocomplete="off" aria-label="Suche">
        <button class="rund" id="hier" aria-label="In meiner Nähe">
            <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="6.5"/><path d="M12 1.5V5M12 19v3.5M1.5 12H5M19 12h3.5"/><circle cx="12" cy="12" r="1.7" fill="currentColor" stroke="none"/></svg>
        </button>
    </div>
    <div class="knopfzeile">
        <button class="knopf" id="kTag">Heute <span class="pfeil">▼</span></button>
        <button class="knopf" id="kMusik">Musik <span class="pfeil">▼</span></button>
        <span class="zaehler" id="zaehler"></span>
    </div>
    <div id="menu" hidden></div>
    <div id="vor" hidden></div>
</div>

<div id="schleier"></div>
<div id="blatt" role="dialog" aria-modal="true" tabindex="-1" aria-label="Club"></div>
<div id="lupe" hidden></div>

<?php if ($eigenesLeaflet): ?>
<script src="vendor/leaflet.js"></script>
<?php else: ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<?php endif; ?>
<script>
"use strict";
const DATEN = <?= $daten ?>;
const TILE_URL = <?= json_encode(TILE_URL) ?>;
const GENRES = <?= json_encode(GENRES) ?>;
const clubs = DATEN.clubs;
const live = DATEN.live;
const TAGE = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

const $ = id => document.getElementById(id);
function el(tag, klasse, text) {
    const n = document.createElement(tag);
    if (klasse) n.className = klasse;
    if (text !== undefined) n.textContent = text;
    return n;
}
/* Umlaute und Groß/Klein egal – „muenchen" soll München finden. */
const falte = s => (s || '').toLowerCase()
    .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '');

/* ---- Zeitrechnung ---- */
/* Die Nacht gehört zum Vortag: um 3 Uhr früh ist „heute" noch gestern. */
function nachtDatum(d) {
    const t = new Date((d || new Date()).getTime() - 6 * 3600 * 1000);
    return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0');
}
function plusTage(iso, n) {
    const d = new Date(iso + 'T12:00:00');
    d.setDate(d.getDate() + n);
    return nachtDatum(new Date(d.getTime() + 6 * 3600 * 1000));
}
const wochentag = iso => (new Date(iso + 'T12:00:00').getDay() + 6) % 7 + 1;  // Mo=1
const minuten = hhmm => parseInt(hhmm.slice(0, 2), 10) * 60 + parseInt(hhmm.slice(3), 10);
function datumText(iso) {
    const d = new Date(iso + 'T12:00:00');
    return TAGE[(d.getDay() + 6) % 7] + ' ' + d.getDate() + '.' + (d.getMonth() + 1) + '.';
}

/* Hat der Club an diesem Tag geöffnet? */
function offenAm(c, iso) {
    const tag = wochentag(iso);
    return (c.hours || []).some(([tage]) => tage.includes(tag));
}
/* Läuft gerade etwas? Berücksichtigt die Nacht vom Vortag. */
function geradeOffen(c) {
    const jetzt = new Date();
    const tag = (jetzt.getDay() + 6) % 7 + 1;
    const min = jetzt.getHours() * 60 + jetzt.getMinutes();
    const vortag = tag === 1 ? 7 : tag - 1;
    return (c.hours || []).some(([tage, auf, zu]) => {
        const a = minuten(auf), z = minuten(zu);
        if (z < a) {   // über Mitternacht
            if (tage.includes(tag) && min >= a) return true;
            if (tage.includes(vortag) && min < z) return true;
            return false;
        }
        return tage.includes(tag) && min >= a && min < z;
    });
}
/* Termine eines Clubs an einem Tag (aus dem, was die Website hergab). */
const termineAm = (c, iso) => ((live[c.id] || {}).events || []).filter(e => e.date === iso);

/* Ein Satz zum Zustand – das, was oben in der Kachel steht. */
function statusText(c) {
    if (c.pause && c.pause >= nachtDatum()) return { text: 'Pausiert bis ' + datumText(c.pause), auf: false };
    if (geradeOffen(c)) return { text: 'Jetzt geöffnet', auf: true };
    const heute = nachtDatum();
    for (let i = 0; i < 14; i++) {
        const tag = plusTage(heute, i);
        if (offenAm(c, tag) || termineAm(c, tag).length) {
            return { text: i === 0 ? 'Heute geöffnet' : (i === 1 ? 'Morgen geöffnet' : 'Wieder ' + datumText(tag)), auf: i === 0 };
        }
    }
    return { text: (c.hours || []).length ? 'Zurzeit geschlossen' : 'Nur zu Veranstaltungen', auf: false };
}

/* ---- Zustand der Ansicht ---- */
const stand = { tag: nachtDatum(), suche: '', musik: [], menu: null };
let karte = null, marker = {}, offenerClub = null, meinOrt = null;

/* Welche Clubs sind gerade zu sehen? */
function passtMusik(c) { return !stand.musik.length || stand.musik.some(g => c.genres.includes(g)); }
function passtSuche(c) {
    if (!stand.suche) return true;
    const h = falte(c.name + ' ' + c.city + ' ' + c.land + ' ' + c.addr + ' ' + c.genres.join(' '));
    return stand.suche.split(/\s+/).every(w => h.includes(w));
}
function passtTag(c) {
    if (!stand.tag) return true;                       // „Alle Tage"
    return offenAm(c, stand.tag) || termineAm(c, stand.tag).length > 0;
}
const sichtbare = () => clubs.filter(c => passtMusik(c) && passtSuche(c) && passtTag(c));

/* ---- Karte ---- */
function pinBild(c, aktiv) {
    const tag = stand.tag || nachtDatum();
    const auf = offenAm(c, tag) || termineAm(c, tag).length > 0;
    return L.divIcon({
        className: '',
        html: '<div class="pin' + (auf ? ' auf' : '') + (aktiv ? ' aktiv' : '') + '"></div>',
        iconSize: [15, 15], iconAnchor: [7, 7],
    });
}
function karteBauen() {
    if (typeof L === 'undefined') {
        // Kartenbibliothek nicht geladen (kein Netz, Werbeblocker, CDN aus).
        // Die Seite bleibt benutzbar: Suche und Kacheln gehen weiter.
        const hinweis = el('div', 'kartefehlt');
        hinweis.appendChild(el('p', '', 'Die Karte konnte nicht geladen werden.'));
        hinweis.appendChild(el('p', '', 'Such einen Club oder Ort – die Angaben stehen trotzdem bereit.'));
        $('map').appendChild(hinweis);
        return;
    }
    karte = L.map('map', { zoomControl: false, attributionControl: true })
        .setView([51.2, 10.4], 6);
    L.tileLayer(TILE_URL, {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>-Mitwirkende · ohne Gewähr',
    }).addTo(karte);
    for (const c of clubs) {
        const m = L.marker([c.lat, c.lng], { icon: pinBild(c), title: c.name });
        m.on('click', () => kachelOeffnen(c, false));
        marker[c.id] = m;
    }
    pinsSetzen();
    if (clubs.length) karte.fitBounds(L.latLngBounds(clubs.map(c => [c.lat, c.lng])), { padding: [40, 40], maxZoom: 13 });
    karte.on('click', () => { if (stand.menu) menuZu(); });
}
/* Nur die sichtbaren Pins auf der Karte lassen. */
function pinsSetzen() {
    const drin = new Set(sichtbare().map(c => c.id));
    if (offenerClub) drin.add(offenerClub);
    for (const c of clubs) {
        const m = marker[c.id];
        if (!m || !karte) continue;
        const soll = drin.has(c.id);
        if (soll && !karte.hasLayer(m)) m.addTo(karte);
        if (!soll && karte.hasLayer(m)) m.remove();
        if (soll) m.setIcon(pinBild(c, c.id === offenerClub));
    }
}

/* ---- Kopfleiste ---- */
function kopfSetzen() {
    const n = sichtbare().length;
    $('zaehler').textContent = n === clubs.length ? n + ' Clubs' : n + ' von ' + clubs.length;
    const heute = nachtDatum();
    $('kTag').firstChild.nodeValue =
        (stand.tag === null ? 'Alle Tage' : stand.tag === heute ? 'Heute'
            : stand.tag === plusTage(heute, 1) ? 'Morgen' : datumText(stand.tag)) + ' ';
    $('kTag').classList.toggle('an', stand.menu === 'tag');
    $('kMusik').firstChild.nodeValue = (stand.musik.length ? stand.musik.join(', ') : 'Musik') + ' ';
    $('kMusik').classList.toggle('an', stand.menu === 'musik' || stand.musik.length > 0);
}
function zeichnen() { pinsSetzen(); kopfSetzen(); }

/* ---- Menüs: eine Zeile je Möglichkeit, rechts wie viele Clubs ---- */
const HAKEN = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12.5l5 5L20 6.5"/></svg>';
function menuZeile(text, anzahl, aktiv, klick, tag) {
    const b = el(tag || 'button', 'zeile' + (aktiv ? ' an' : '') + (anzahl === 0 ? ' leer' : ''));
    b.appendChild(el('span', 'was', text));
    if (anzahl !== null) b.appendChild(el('span', 'wieviel', String(anzahl)));
    const h = el('span', 'haken');
    h.innerHTML = HAKEN;
    b.appendChild(h);
    if (klick) b.onclick = klick;
    return b;
}
function menuZu() { stand.menu = null; $('menu').hidden = true; kopfSetzen(); }
function menuAuf(welches) {
    if (stand.menu === welches) { menuZu(); return; }
    stand.menu = welches;
    vorZu();
    menuZeichnen();
}
function menuZeichnen() {
    const m = $('menu');
    m.textContent = '';
    m.hidden = false;
    (stand.menu === 'tag' ? tagMenu : musikMenu)(m);
    kopfSetzen();
}
function tagMenu(m) {
    const heute = nachtDatum(), morgen = plusTage(heute, 1);
    const zaehl = iso => clubs.filter(c => passtMusik(c) && passtSuche(c)
        && (iso === null || offenAm(c, iso) || termineAm(c, iso).length > 0)).length;
    const waehle = iso => { stand.tag = iso; menuZeichnen(); zeichnen(); };
    const liste = el('div', 'liste');
    const eigen = stand.tag !== null && stand.tag !== heute && stand.tag !== morgen;
    liste.appendChild(menuZeile('Heute', zaehl(heute), stand.tag === heute, () => waehle(heute)));
    liste.appendChild(menuZeile('Morgen', zaehl(morgen), stand.tag === morgen, () => waehle(morgen)));
    // Anderer Tag: ein <label> mit dem Datumsfeld darin – so öffnet ein Tipp
    // auf die ganze Zeile den Systemkalender, auch auf dem iPhone.
    const wahl = menuZeile(eigen ? datumText(stand.tag) : 'Anderer Tag …',
        eigen ? zaehl(stand.tag) : null, eigen, null, 'label');
    const feld = document.createElement('input');
    feld.type = 'date';
    feld.min = plusTage(heute, -7);
    feld.max = plusTage(heute, 60);
    feld.setAttribute('aria-label', 'Tag wählen');
    if (stand.tag) feld.value = stand.tag;
    feld.onchange = () => waehle(feld.value || heute);
    wahl.onclick = e => {
        if (!feld.showPicker) return;           // dann übernimmt der Browser
        e.preventDefault();
        try { feld.showPicker(); } catch (_) { feld.focus(); }
    };
    wahl.appendChild(feld);
    liste.appendChild(wahl);
    liste.appendChild(menuZeile('Alle Tage', zaehl(null), stand.tag === null, () => waehle(null)));
    m.appendChild(liste);
}
function musikMenu(m) {
    // Sortiert nach Anzahl: was am meisten bringt, steht oben.
    const zahl = {};
    for (const g of GENRES) {
        zahl[g] = clubs.filter(c => c.genres.includes(g) && passtSuche(c) && passtTag(c)).length;
    }
    const angebot = GENRES.filter(g => zahl[g] > 0 || stand.musik.includes(g))
        .sort((a, b) => (zahl[b] - zahl[a]) || a.localeCompare(b, 'de'));
    if (!angebot.length) {
        m.appendChild(el('div', 'mtitel', 'Kein Stil passt zur aktuellen Auswahl'));
        const z = el('button', 'mfuss', 'Filter zurücksetzen');
        z.onclick = allesZurueck;
        m.appendChild(z);
        return;
    }
    m.appendChild(el('div', 'mtitel', 'Musik · mehrere möglich'));
    const liste = el('div', 'liste');
    for (const g of angebot) {
        liste.appendChild(menuZeile(g, zahl[g], stand.musik.includes(g), () => {
            stand.musik = stand.musik.includes(g)
                ? stand.musik.filter(x => x !== g) : stand.musik.concat(g);
            menuZeichnen();
            zeichnen();
        }));
    }
    m.appendChild(liste);
    if (stand.musik.length) {
        const z = el('button', 'mfuss', 'Musikfilter entfernen');
        z.onclick = () => { stand.musik = []; menuZeichnen(); zeichnen(); };
        m.appendChild(z);
    }
}
function allesZurueck() {
    stand.tag = nachtDatum();
    stand.musik = [];
    stand.suche = '';
    $('q').value = '';
    menuZu();
    zeichnen();
}

/* ---- Suchvorschläge ---- */
function vorZu() { $('vor').hidden = true; }
function vorZeichnen() {
    const box = $('vor');
    box.textContent = '';
    if (!stand.suche) { box.hidden = true; return; }
    const treffer = clubs.filter(passtSuche).slice(0, 8);
    if (!treffer.length) {
        box.appendChild(el('div', 'laedt', 'Kein Club und kein Ort passt dazu.'));
        box.hidden = false;
        return;
    }
    for (const c of treffer) {
        const b = el('button', 'vz');
        b.appendChild(el('b', '', c.name));
        b.appendChild(el('span', '', [c.city, c.land].filter(Boolean).join(' · ')));
        b.onclick = () => { vorZu(); kachelOeffnen(c, true); };
        box.appendChild(b);
    }
    box.hidden = false;
}

/* ---- Die Kachel ---- */
function kachelZu() {
    if (!offenerClub) return;
    offenerClub = null;
    $('blatt').classList.remove('auf');
    $('schleier').classList.remove('auf');
    pinsSetzen();
}
/*
 * Beim Öffnen wird der Connector dieses Clubs einmal befragt – und nur dann.
 * Kein Takt, keine Wiederholung: kommt etwas, wird es eingesetzt; kommt
 * nichts, steht trotzdem alles da, was im Connector belegt ist.
 */
function kachelOeffnen(c, springen) {
    menuZu();
    vorZu();
    offenerClub = c.id;
    kachelZeichnen(c, !live[c.id] && !c.nolive);
    $('blatt').classList.add('auf');
    $('schleier').classList.add('auf');
    pinsSetzen();
    if (karte && springen !== false) {
        karte.setView([c.lat, c.lng], Math.max(karte.getZoom(), 14), { animate: true });
    }
    if (!c.nolive && !live[c.id]) {
        fetch('?club=' + encodeURIComponent(c.id) + '&json=1')
            .then(r => r.json())
            .then(j => {
                live[c.id] = j.live || {};
                zeichnen();
                if (offenerClub === c.id) kachelZeichnen(c, false);
            })
            .catch(() => { if (offenerClub === c.id) kachelZeichnen(c, false); });
    }
}
function bildBlock(bilder) {
    const box = el('div', 'bilder');
    const rest = bilder.slice(1);   // drei werden gezeigt, der Rest springt bei Fehlern ein
    const gross = el('img', 'gross');
    gross.src = bilder[0];
    gross.alt = '';
    gross.loading = 'eager';
    gross.referrerPolicy = 'no-referrer';   // viele Clubseiten sperren fremdes Einbetten
    gross.onclick = () => lupeAuf(gross.src);
    gross.onerror = () => {
        const n = rest.shift();
        if (n) { gross.src = n; kleineNeu(); } else { box.remove(); }
    };
    box.appendChild(gross);
    const reihe = el('div', 'klein');
    function kleineNeu() {
        reihe.textContent = '';
        for (const u of rest.slice(0, 3)) {
            const t = document.createElement('img');
            t.src = u;
            t.alt = '';
            t.loading = 'lazy';
            t.referrerPolicy = 'no-referrer';
            t.onerror = () => t.remove();
            t.onclick = () => lupeAuf(u);
            reihe.appendChild(t);
        }
        reihe.hidden = rest.length === 0;
    }
    kleineNeu();
    box.appendChild(reihe);
    return box;
}
function faktZeile(k, v) {
    const z = el('div', 'faktzeile');
    z.appendChild(el('div', 'k', k));
    const w = el('div', 'v');
    if (typeof v === 'string') w.textContent = v; else w.appendChild(v);
    z.appendChild(w);
    return z;
}
function kachelZeichnen(c, laedt) {
    const blatt = $('blatt');
    blatt.textContent = '';
    blatt.setAttribute('aria-label', c.name);
    const l = live[c.id] || {};
    const s = statusText(c);

    const kopf = el('div', 'kopf');
    kopf.appendChild(el('div', 'griff'));
    const x = el('button', 'zu', '×');
    x.setAttribute('aria-label', 'Schließen');
    x.onclick = kachelZu;
    kopf.appendChild(x);
    kopf.appendChild(el('h2', '', c.name));
    kopf.appendChild(el('p', 'status' + (s.auf ? ' auf' : ''), s.text));
    blatt.appendChild(kopf);

    const leib = el('div', 'leib');
    if (l.warn) leib.appendChild(el('p', 'hinweis', 'Hinweis der Website: ' + l.warn));
    if ((l.images || []).length) leib.appendChild(bildBlock(l.images));
    if (c.about) leib.appendChild(el('p', 'lead', c.about));
    if (l.info && l.info !== c.about) {
        const t = l.info.length > 300 ? l.info.slice(0, 280).replace(/\s+\S*$/, '') + ' …' : l.info;
        leib.appendChild(el('p', 'quelle', t));
    }
    if (c.genres.length) {
        const marken = el('div', 'marken');
        for (const g of c.genres) {
            const b = el('button', 'marke', g);
            b.onclick = () => { stand.musik = [g]; kachelZu(); zeichnen(); };
            marken.appendChild(b);
        }
        leib.appendChild(marken);
    }

    const fakten = el('div', 'fakten');
    if ((c.hours || []).length) {
        const heute = wochentag(stand.tag || nachtDatum());
        const box = el('div');
        for (const [tage, auf, zu] of c.hours) {
            const z = el('div', 'tagzeile' + (tage.includes(heute) ? ' heute' : ''));
            z.appendChild(el('span', 'd', tage.map(t => TAGE[t - 1]).join(', ')));
            z.appendChild(el('span', '', auf + ' – ' + zu));
            box.appendChild(z);
        }
        fakten.appendChild(faktZeile('Geöffnet', box));
    } else {
        fakten.appendChild(faktZeile('Geöffnet', 'Nur zu Veranstaltungen'));
    }
    if (c.addr) fakten.appendChild(faktZeile('Adresse', [c.addr, c.city].filter(Boolean).join(', ')));
    if (c.note) fakten.appendChild(faktZeile('Hinweis', c.note));
    leib.appendChild(fakten);

    // Programm: was die Website hergibt, ab heute
    const prog = el('div', 'abschnitt');
    prog.appendChild(el('h3', '', 'Programm'));
    const termine = (l.events || []).slice(0, 8);
    if (laedt) {
        prog.appendChild(el('p', 'laedt', 'Wird von der Website geholt …'));
    } else if (!termine.length) {
        prog.appendChild(el('p', 'laedt', c.nolive
            ? 'Dieser Club hat keine Website, die sich auslesen lässt.'
            : 'Die Website nennt gerade keine Termine.'));
    } else {
        for (const t of termine) {
            const z = el('div', 'termin');
            z.appendChild(el('div', 'wann', datumText(t.date)));
            z.appendChild(el('div', 'titel', t.title));
            prog.appendChild(z);
        }
    }
    leib.appendChild(prog);

    const tun = el('div', 'tun');
    const route = el('a', 'stark', 'Route');
    const ziel = encodeURIComponent([c.addr, c.city].filter(Boolean).join(', ') || (c.lat + ',' + c.lng));
    route.href = /iPhone|iPad|Macintosh/.test(navigator.userAgent)
        ? 'https://maps.apple.com/?daddr=' + ziel
        : 'https://www.google.com/maps/dir/?api=1&destination=' + ziel;
    route.target = '_blank';
    route.rel = 'noopener';
    tun.appendChild(route);
    if (/^https?:\/\//i.test(c.url || '')) {
        const w = el('a', '', c.url.includes('instagram.') ? 'Instagram' : 'Website');
        w.href = c.url;
        w.target = '_blank';
        w.rel = 'noopener';
        tun.appendChild(w);
    }
    leib.appendChild(tun);
    leib.appendChild(el('p', 'fuss', 'Programm und Fotos stammen von der Website des Clubs. Alle Angaben ohne Gewähr.'));
    blatt.appendChild(leib);
    zieheZu(kopf, blatt);
}
/* Am Kopf nach unten ziehen schließt die Kachel – wie am Telefon gewohnt. */
function zieheZu(kopf, blatt) {
    let start = null, weg = 0;
    kopf.addEventListener('pointerdown', e => {
        if (e.target.closest('.zu')) return;
        start = e.clientY; weg = 0;
        blatt.classList.add('zieh');
        if (kopf.setPointerCapture) kopf.setPointerCapture(e.pointerId);
    });
    kopf.addEventListener('pointermove', e => {
        if (start === null) return;
        weg = Math.max(0, e.clientY - start);
        blatt.style.transform = weg ? 'translateY(' + weg + 'px)' : '';
    });
    const fertig = () => {
        if (start === null) return;
        start = null;
        blatt.classList.remove('zieh');
        blatt.style.transform = '';
        if (weg > 80) kachelZu();
    };
    kopf.addEventListener('pointerup', fertig);
    kopf.addEventListener('pointercancel', fertig);
}

/* ---- Bild groß ---- */
function lupeAuf(src) {
    const l = $('lupe');
    l.textContent = '';
    const i = document.createElement('img');
    i.src = src;
    i.alt = '';
    i.referrerPolicy = 'no-referrer';
    l.appendChild(i);
    l.hidden = false;
}
$('lupe').onclick = () => { $('lupe').hidden = true; };

/* ---- Standort ---- */
$('hier').onclick = () => {
    if (!navigator.geolocation) return;
    $('hier').classList.add('an');
    navigator.geolocation.getCurrentPosition(p => {
        $('hier').classList.remove('an');
        meinOrt = [p.coords.latitude, p.coords.longitude];
        if (karte) karte.setView(meinOrt, 13, { animate: true });
    }, () => { $('hier').classList.remove('an'); }, { enableHighAccuracy: true, timeout: 8000 });
};

/* ---- Bedienung ---- */
$('kTag').onclick = () => menuAuf('tag');
$('kMusik').onclick = () => menuAuf('musik');
$('schleier').onclick = kachelZu;
let tippTimer = null;
$('q').addEventListener('input', () => {
    clearTimeout(tippTimer);
    tippTimer = setTimeout(() => {
        stand.suche = falte($('q').value.trim());
        menuZu();
        vorZeichnen();
        zeichnen();
    }, 140);
});
$('q').addEventListener('focus', vorZeichnen);
addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (!$('lupe').hidden) { $('lupe').hidden = true; return; }
    if (offenerClub) { kachelZu(); return; }
    if (stand.menu) menuZu();
});
document.addEventListener('click', e => {
    if (!e.target.closest('.bar')) { vorZu(); }
}, true);

/*
 * Noch keine Clubdaten da? Dann liegt nur die index.php auf dem Server.
 * Die Seite holt sie einmal selbst – ein Aufruf, danach nie wieder.
 */
function erstesHolen() {
    const box = el('div', 'kartefehlt');
    box.appendChild(el('p', '', 'Clubdaten werden geholt …'));
    const zweite = el('p', '', 'Das dauert einen Moment und passiert nur dieses eine Mal.');
    box.appendChild(zweite);
    $('map').appendChild(box);
    fetch('?holen=1').then(r => r.json()).then(j => {
        if (j.anzahl > 0) { location.reload(); return; }
        box.textContent = '';
        box.appendChild(el('p', '', 'Es sind keine Clubdaten da.'));
        box.appendChild(el('p', '', j.meldung || 'Bitte den Ordner connectors/ neben die index.php legen.'));
    }).catch(() => {
        box.textContent = '';
        box.appendChild(el('p', '', 'Es sind keine Clubdaten da.'));
        box.appendChild(el('p', '', 'Bitte den Ordner connectors/ neben die index.php legen.'));
    });
}

/* ---- Start ---- */
if (DATEN.leer) erstesHolen(); else karteBauen();
zeichnen();
// Deeplink: ?club=<id> öffnet die Kachel direkt
const gewuenscht = new URLSearchParams(location.search).get('club');
if (gewuenscht) {
    const c = clubs.find(x => x.id === gewuenscht);
    if (c) kachelOeffnen(c, true);
}
</script>
</body>
</html>
