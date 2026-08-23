<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Berlin');

if (isset($_GET['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

const CACHE_TTL = 3600;   // stündlich nachsehen – dank ETag/Hash-Abgleich günstig
const CACHE_V = 2;

/*
 * Alles unter einer Domain: Regionen sind Unterordner in /regions/<land>/
 * (je eine YAML-Datei pro Club), Flaggen liegen in /flag/<land>.ico.
 * Neue Region = neuen Ordner mit YAMLs anlegen und die passende <land>.ico
 * in /flag/ ablegen – die Flagge erscheint dann von selbst.
 * Liegen stattdessen Dateien direkt in /data/connector/, läuft die Seite
 * einregionig ganz ohne Länderwahl.
 */
/* Woher die Connectoren kommen. Diese Datei enthält nur Logik – die
   Clubdaten liegen in github.com/florianthepro/clubconnectors und werden
   entweder daneben abgelegt (Ordner connectors/) oder per ?sync geholt. */
const CONNECTOR_REPO = 'florianthepro/clubconnectors';
const CONNECTOR_BRANCH = 'main';
const CONNECTOR_DIRS = ['data/connectors', 'connectors', 'regions']; // erster Treffer gewinnt
const REGIONS_DIR = 'regions';           // Altbestand, nur noch Fallback
const FLAG_DIR = 'flag';                  // relativer Pfad zu den Flaggen-Icons
const REGION_NAMES = ['de' => 'Deutschland', 'us' => 'USA'];
/* Ordnername je Bundesland → Anzeigename (fehlt einer, wird der Ordner genutzt) */
const AREA_NAMES = [
    'baden-wuerttemberg' => 'Baden-Württemberg', 'bayern' => 'Bayern', 'berlin' => 'Berlin',
    'brandenburg' => 'Brandenburg', 'bremen' => 'Bremen', 'hamburg' => 'Hamburg',
    'hessen' => 'Hessen', 'mecklenburg-vorpommern' => 'Mecklenburg-Vorpommern',
    'niedersachsen' => 'Niedersachsen', 'nordrhein-westfalen' => 'Nordrhein-Westfalen',
    'rheinland-pfalz' => 'Rheinland-Pfalz', 'saarland' => 'Saarland', 'sachsen' => 'Sachsen',
    'sachsen-anhalt' => 'Sachsen-Anhalt', 'schleswig-holstein' => 'Schleswig-Holstein',
    'thueringen' => 'Thüringen',
];

/*
 * Clubs kommen aus Connector-Dateien in /data/*.yaml (eine je Club).
 * Flaches YAML: "key: value" pro Zeile, Kommentare mit #.
 * hours-Format: "Fr,Sa 23:00-07:00; Mo-Do 22:00-06:00"
 */
/*
 * Einen YAML-Wert entpacken: erst die Anführungszeichen, dann fällt ein
 * nachgestellter Kommentar weg; ein '#' innerhalb der Anführungszeichen
 * bleibt Teil des Werts. Validator (tools/validate.php) nutzt dieselbe Logik.
 */
function flat_value(string $v): string
{
    $v = trim($v);
    if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) {
        $q = $v[0];
        $end = strpos($v, $q, 1);
        if ($end !== false) {
            return substr($v, 1, $end - 1);
        }
    }
    return rtrim((string)preg_replace('/\s+#.*$/', '', $v));
}

function yaml_flat(string $text): array
{
    $out = [];
    $text = (string)preg_replace('/^\xEF\xBB\xBF/', '', $text);
    foreach (preg_split('#\r?\n#', $text) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!preg_match('#^([A-Za-z_][\w-]*):\s*(.*)$#', $line, $m)) {
            continue;
        }
        $out[$m[1]] = flat_value($m[2]);
    }
    return $out;
}

function parse_hours(string $s): array
{
    $names = ['Mo' => 1, 'Di' => 2, 'Mi' => 3, 'Do' => 4, 'Fr' => 5, 'Sa' => 6, 'So' => 7];
    $out = [];
    foreach (array_filter(array_map('trim', explode(';', $s))) as $part) {
        if (!preg_match('#^(.+?)\s+(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$#', $part, $m)) {
            continue;
        }
        $days = [];
        foreach (array_map('trim', explode(',', $m[1])) as $tok) {
            if (isset($names[$tok])) {
                $days[] = $names[$tok];
            } elseif (preg_match('#^(\w\w)-(\w\w)$#', $tok, $r) && isset($names[$r[1]], $names[$r[2]])) {
                for ($d = $names[$r[1]]; ; $d = $d % 7 + 1) {
                    $days[] = $d;
                    if ($d === $names[$r[2]]) {
                        break;
                    }
                }
            }
        }
        if ($days) {
            $out[] = [array_values(array_unique($days)), $m[2], $m[3]];
        }
    }
    return $out;
}

/* Alle YAML-Dateien eines Ordners, auch in Unterordnern (Bundesland/Stadt) */
function yaml_files(string $dir): array
{
    $out = glob($dir . '/*.yaml') ?: [];
    foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        if (substr(basename($d), 0, 1) === '_') {
            continue; // Entwürfe und Klärfälle (SPEC.md Abschnitt 2)
        }
        $out = array_merge($out, yaml_files($d));
    }
    return $out;
}

/* Gibt es hier irgendwo Connectoren? Bricht beim ersten Treffer ab. */
function has_yaml(string $dir, int $depth = 4): bool
{
    if ($depth < 0 || !is_dir($dir)) {
        return false;
    }
    if (glob($dir . '/*.yaml')) {
        return true;
    }
    foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        if (substr(basename($d), 0, 1) !== '_' && has_yaml($d, $depth - 1)) {
            return true;
        }
    }
    return false;
}

/* Der Ordner, aus dem gerade gelesen wird – samt Herkunft für ?diag. */
function connector_root(): array
{
    static $r = null;
    if ($r !== null) {
        return $r;
    }
    foreach (CONNECTOR_DIRS as $rel) {
        if (has_yaml(__DIR__ . '/' . $rel)) {
            return $r = [__DIR__ . '/' . $rel, $rel];
        }
    }
    return $r = [__DIR__ . '/' . CONNECTOR_DIRS[1], CONNECTOR_DIRS[1]];
}

function local_mode(): bool
{
    static $m = null;
    return $m ?? $m = (bool)yaml_files(__DIR__ . '/data/connector');
}

/* Verzeichnis einer Region (Länderordner unter der Connector-Wurzel) */
function region_dir(string $cc): string
{
    return connector_root()[0] . '/' . $cc;
}

/* Alle Länder mit mindestens einer Connector-Datei */
function region_list(): array
{
    $out = [];
    foreach (glob(connector_root()[0] . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (preg_match('#/([a-z0-9-]+)$#', $dir, $m) && has_yaml($dir)) {
            $out[] = $m[1];
        }
    }
    sort($out);
    return $out;
}

function region(): string
{
    $c = $_GET['c'] ?? '';
    return is_string($c) && !local_mode() && in_array($c, region_list(), true) ? $c : '';
}

/* Liefert [clubs, connectors] einer Region (bzw. der lokalen Dateien) */
function load_connectors(string $cc = ''): array
{
    if (local_mode()) {
        $files = yaml_files(__DIR__ . '/data/connector');
    } elseif ($cc !== '') {
        $files = yaml_files(region_dir($cc));
    } else {
        $files = [];
    }
    // Ordnername unterhalb der Region = Bundesland (macht es mitsuchbar)
    $base = local_mode() ? __DIR__ . '/data/connector' : region_dir($cc);
    $texts = [];
    foreach ($files as $f) {
        $rel = trim(str_replace('\\', '/', substr($f, strlen($base))), '/');
        $parts = explode('/', $rel);
        $texts[] = [(string)file_get_contents($f), count($parts) > 1 ? $parts[0] : ''];
    }
    $clubs = [];
    $conn = [];
    $seen = [];
    foreach ($texts as [$text, $stateDir]) {
        $y = yaml_flat($text);
        if (empty($y['id']) || empty($y['name']) || !isset($y['lat'], $y['lng'])
            || !is_numeric($y['lat']) || !is_numeric($y['lng']) || isset($seen[$y['id']])) {
            continue;
        }
        $seen[$y['id']] = true;
        $club = [
            'id' => $y['id'],
            'name' => $y['name'],
            'city' => $y['city'] ?? 'München',
            'addr' => $y['address'] ?? '',
            'lat' => (float)$y['lat'],
            'lng' => (float)$y['lng'],
            'url' => $y['website'] ?? '',
            'genres' => array_values(array_filter(array_map('trim', explode(',', $y['genres'] ?? '')))),
            'hours' => parse_hours($y['hours'] ?? ''),
        ];
        if (!empty($y['note'])) {
            $club['note'] = $y['note'];
        }
        if (!empty($y['pause'])) {
            $club['pause'] = $y['pause'];
        }
        $st = $y['state'] ?? $stateDir;
        if ($st !== '') {
            $club['state'] = AREA_NAMES[$st] ?? ucwords(str_replace('-', ' ', $st));
        }
        $clubs[] = $club;
        if (!empty($y['scrape_url'])) {
            $conn[$y['id']] = [
                'url' => $y['scrape_url'],
                'events' => $y['scrape_events'] ?? 'auto',
                'images' => $y['scrape_images'] ?? 'auto',
                'info' => $y['scrape_info'] ?? 'auto',
                'from' => $y['scrape_from'] ?? null,
                'to' => $y['scrape_to'] ?? null,
                // optionale Deeplinks: eigene Unterseiten für Infotext/Bilder
                'info_url' => $y['scrape_info_url'] ?? null,
                'images_url' => $y['scrape_images_url'] ?? null,
                'closed' => $y['scrape_closed'] ?? 'auto', // Sonderschließungs-Hinweise erkennen
            ];
        }
    }
    usort($clubs, fn($a, $b) => strcmp($a['name'], $b['name']));
    return [$clubs, $conn];
}

if (isset($_GET['icon'])) {
    icon(is_string($_GET['icon']) ? $_GET['icon'] : '');
    exit;
}
if (isset($_GET['sync'])) {
    // Connectoren aus dem GitHub-Repo holen. Nur mit Admin-Schlüssel,
    // weil dabei Dateien geschrieben werden.
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    if (!admin_ok()) {
        http_response_code(403);
        echo admin_key() === ''
            ? "Sync ist aus. Datei data/admin.key mit einem geheimen Schlüssel anlegen,\ndann ?sync=1&key=<schlüssel> aufrufen.\n"
            : "Falscher oder fehlender Schlüssel (?sync=1&key=…).\n";
        exit;
    }
    @set_time_limit(120);
    [$ok, $msg] = sync_connectors();
    if (!$ok) {
        // Drosselung und bewusste Verweigerung sind keine Serverfehler
        http_response_code(strpos($msg, '5 Minuten') !== false ? 429
            : (strpos($msg, 'nichts geändert') !== false ? 409 : 500));
    }
    echo ($ok ? 'OK: ' : 'FEHLER: ') . $msg . "\n";
    exit;
}
if (isset($_GET['diag'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PHP ' . PHP_VERSION . "\n";
    foreach (['curl', 'gd', 'iconv', 'posix'] as $ext) {
        echo $ext . ': ' . (extension_loaded($ext) ? 'ok' : 'FEHLT') . "\n";
    }
    $rl = region_list();
    [, $src] = connector_root();
    echo 'connector-quelle: ' . (local_mode() ? 'data/connector (Einzelregion)' : $src . '/')
        . ($src === 'data/connectors' ? ' – per ?sync von ' . CONNECTOR_REPO : '') . "\n";
    $stamp = __DIR__ . '/data/connectors.stamp';
    if (is_file($stamp)) {
        echo 'letzter sync: ' . date('d.m.Y H:i', (int)filemtime($stamp)) . "\n";
    }
    echo 'zip-Erweiterung: ' . (class_exists('ZipArchive') ? 'ok (?sync möglich)' : 'FEHLT (?sync geht nicht)') . "\n";
    echo 'admin/sync: ' . (admin_key() === '' ? 'aus (data/admin.key fehlt)' : 'aktiv') . "\n";
    echo 'modus: ' . (local_mode() ? 'lokal (/data/connector)' : 'regionen (' . (implode(', ', $rl) ?: 'keine') . ')') . "\n";
    $dcc = region() !== '' ? region() : (local_mode() ? '' : ($rl[0] ?? ''));
    $t = load_connectors($dcc);
    echo 'connectoren: ' . count($t[0]) . ' Clubs, ' . count($t[1]) . " mit Scrape-URL\n";
    $dc = cache_read($dcc);
    $ev = $im = $in = 0;
    foreach ((array)($dc['data'] ?? []) as $e) {
        $ev += !empty($e['events']) ? 1 : 0;
        $im += !empty($e['images']) ? 1 : 0;
        $in += !empty($e['info']) ? 1 : 0;
    }
    echo 'cache: ' . $ev . ' Clubs mit Events, ' . $im . ' mit Bildern, ' . $in . ' mit Infotext'
        . (isset($dc['ts']) ? ', Stand ' . date('d.m. H:i', (int)$dc['ts']) : ' (noch leer – Seite einmal aufrufen und ~2 min warten)') . "\n";
    $tmp = sys_get_temp_dir();
    echo 'temp ' . $tmp . ': ' . (@is_writable($tmp) ? 'beschreibbar' : 'gesperrt (nutze Skriptordner)') . "\n";
    echo 'skriptordner: ' . (@is_writable(__DIR__) ? 'beschreibbar' : 'NICHT beschreibbar – kein Event-Cache') . "\n";
    if (function_exists('curl_init')) {
        // gleicher Modus wie der Scraper (SSL-tolerant, Browser-UA)
        $ch = curl_init('https://www.muffatwerk.de/de/veranstaltungen');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36']);
        $b = curl_exec($ch);
        echo 'outbound-test (einfach): ' . ($b !== false && $b !== '' ? strlen($b) . ' Bytes ok' : 'FEHLER: ' . curl_error($ch)) . "\n";
        echo 'curl_multi: ' . (function_exists('curl_multi_init') ? 'vorhanden' : 'FEHLT (nutze Einzelabruf)') . "\n";
        // gleicher Weg wie der Scraper: zeigt sofort, woran es wirklich liegt
        $dt = array_slice($t[1], 0, 3, true);
        if ($dt) {
            $dr = fetch_all(array_map(fn($c) => $c['url'], $dt), [], 20);
            foreach ($dt as $did => $dcfg) {
                $x = $dr[$did] ?? null;
                $len = $x && $x['body'] !== '' ? strlen($x['body']) : 0;
                echo '  scraper-test ' . str_pad(substr($did, 0, 14), 15)
                    . ($len ? $len . ' Bytes ok, ' . count(extract_images($x['body'], $x['url'] ?: $dcfg['url'], 'auto')) . ' Bilder, '
                        . (extract_info($x['body']) !== '' ? 'Infotext ja' : 'Infotext nein')
                      : 'FEHLER: ' . ($x['err'] ?? 'keine Antwort'))
                    . "\n";
            }
        }
        echo "  (steht bei scraper-test FEHLER, kommt der Scraper nicht durch –\n   dann gibt es weder Programm noch Bilder)\n";
    }
    exit;
}
if (isset($_GET['live'])) {
    // aktueller Datenstand als JSON – der Browser holt damit frisch
    // gescrapte Bilder/Programme nach, ohne die Seite neu zu laden
    header('Content-Type: application/json; charset=utf-8');
    [, $conn] = load_connectors(region());
    if (isset($_GET['work'])) {
        // Notbetrieb: Der Hoster beendet den ?cron-Prozess offenbar sofort.
        // Dann wird hier gearbeitet – die Antwort geht erst danach raus.
        @set_time_limit(60);
        // knapp halten: viele Hoster kappen bei 30 s max_execution_time
        scrape_refresh(cache_read(region())['data'] ?? [], $conn, 6, region(), 10);
    }
    $cache = cache_read(region());
    $pending = 0;
    foreach ($conn as $cid => $c) {
        if ((int)($cache['data'][$cid]['ft'] ?? 0) === 0) {
            $pending++;
        }
    }
    $netFile = cache_file('net-' . (region() === '' ? 'local' : region()));
    $net = $netFile && is_file($netFile) ? (json_decode((string)file_get_contents($netFile), true) ?: []) : [];
    echo json_encode([
        'live' => array_map(fn($e) => array_intersect_key((array)$e, ['events' => 1, 'images' => 1, 'info' => 1, 'warn' => 1]), $cache['data'] ?? []),
        'pending' => $pending,
        'neterr' => (isset($net['ok']) && !$net['ok']) ? (string)($net['err'] ?: 'blockiert') : '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    exit;
}
if (isset($_GET['cron'])) {
    // Hintergrund-Ping vom Browser: einen kleinen Batch aktualisieren.
    // Drosselung (5 min) und Lock stecken in scrape_refresh selbst.
    http_response_code(204);
    header('Connection: close');
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
    $cc = region();
    [, $conn] = load_connectors($cc);
    // Batch pro Ping; das Zeitbudget in fetch_all begrenzt den Lauf real
    scrape_refresh(cache_read($cc)['data'] ?? [], $conn, 24, $cc);
    exit;
}
if (isset($_GET['check'])) {
    // Prüft jede Connector-Seite live: erreichbar? Liefert die Extraktion Events/Bilder/Text?
    header('Content-Type: text/plain; charset=utf-8');
    $cc = region();
    $stamp = (cache_file(scrape_key($cc)) ?: sys_get_temp_dir() . '/clubkarte-check') . '.check';
    if (is_file($stamp) && time() - (int)@filemtime($stamp) < 300) {
        http_response_code(429);
        exit("Check lief vor weniger als 5 Minuten – bitte kurz warten.\n");
    }
    @touch($stamp);
    @set_time_limit(180);
    [, $conn] = load_connectors($cc);
    echo 'Connector-Check · ' . count($conn) . " Seiten\n";
    echo str_repeat('-', 78) . "\n";
    $results = fetch_all(array_map(fn($c) => $c['url'], $conn), [], 140);
    $ok = 0;
    foreach ($conn as $id => $cfg) {
        $r = $results[$id] ?? null;
        if (!$r || $r['code'] === 0 || $r['code'] >= 400) {
            printf("%-16s NICHT ERREICHBAR   %s  [%s]\n", $id, $cfg['url'], $r['err'] ?? ('HTTP ' . ($r['code'] ?? '—')));
            continue;
        }
        try {
            $ev = $cfg['events'] === 'none' ? [] : auto_events($r['body'], $cfg['from'], $cfg['to']);
            $im = $cfg['images'] === 'none' ? [] : extract_images($r['body'], $r['url'] ?: $cfg['url'], $cfg['images']);
            $in = $cfg['info'] === 'none' ? '' : extract_info($r['body']);
            printf("%-16s ok  %2d Events  %d Bilder  Info: %-4s %s\n",
                $id, count($ev), count($im), $in !== '' ? 'ja' : '—', $cfg['url']);
            $ok++;
        } catch (Throwable $e) {
            printf("%-16s EXTRAKTIONSFEHLER  %s\n", $id, $cfg['url']);
        }
    }
    echo str_repeat('-', 78) . "\n";
    echo $ok . ' von ' . count($conn) . " Seiten liefern Daten.\n";
    exit;
}
if (isset($_GET['manifest'])) {
    header('Content-Type: application/manifest+json');
    $cc = region();
    echo json_encode([
        'name' => 'Nightclubmap', 'short_name' => 'Clubs',
        'start_url' => './' . ($cc !== '' ? '?c=' . $cc : ''), 'display' => 'standalone',
        'background_color' => '#000000', 'theme_color' => '#000000',
        'icons' => [['src' => '?icon=touch', 'sizes' => '180x180', 'type' => 'image/png']],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
if (isset($_GET['admin'])) {
    admin_page();
    exit;
}

/*
 * Admin-Bereich (?admin=1&key=…): Club-URL einwerfen, die Seite wird
 * analysiert, Inhalte/Bilder werden ausgewählt – daraus wird der
 * Extraktions-Agent gemappt und die Connector-YAML erzeugt/gespeichert.
 * Schutz: Schlüssel in data/admin.key (Datei anlegen = Admin aktivieren).
 */
/* Bundesland-Slug: wie admin_slug, aber Bindestriche bleiben (baden-wuerttemberg) */
function admin_area_slug(string $s): string
{
    $s = strtolower(strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue']));
    $s = trim((string)preg_replace('#[^a-z0-9]+#', '-', $s), '-');
    return substr($s, 0, 40);
}

function admin_slug(string $s): string
{
    $s = strtolower(strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue']));
    $s = (string)preg_replace('#[^a-z0-9]+#', '', $s);
    return $s !== '' ? substr($s, 0, 40) : 'club';
}

function admin_yaml_val(string $v): string
{
    $v = trim(str_replace(["\r", "\n"], ' ', $v));
    return strpbrk($v, '#"') !== false ? '"' . str_replace('"', '', $v) . '"' : $v;
}

/* Der Schlüssel aus data/admin.key. Leer = Admin und Sync sind aus. */
function admin_key(): string
{
    $f = __DIR__ . '/data/admin.key';
    return is_file($f) ? trim((string)file_get_contents($f)) : '';
}

function admin_ok(): bool
{
    $k = admin_key();
    return $k !== '' && hash_equals($k, (string)($_REQUEST['key'] ?? ''));
}

/*
 * Connectoren direkt aus dem GitHub-Repo holen: Zipball laden, nur die
 * YAML-Dateien unter connectors/ herausschreiben, dann den fertigen Ordner
 * in einem Zug austauschen. Es wird nie in einen Ordner geschrieben, aus
 * dem gerade gelesen wird.
 */
function sync_connectors(): array
{
    $data = __DIR__ . '/data';
    if (!is_dir($data)) {
        @mkdir($data, 0755, true);
    }
    if (!is_dir($data) || !@is_writable($data)) {
        return [false, 'Ordner data/ ist nicht beschreibbar – Connectoren bitte per FTP hochladen.'];
    }
    if (!class_exists('ZipArchive')) {
        return [false, 'Die PHP-Erweiterung zip fehlt auf diesem Hoster. Ordner connectors/ aus dem Repo bitte per FTP neben die index.php legen.'];
    }
    if (!function_exists('curl_init')) {
        return [false, 'curl fehlt – ohne das kommt der Server nicht an GitHub.'];
    }
    $stamp = $data . '/connectors.stamp';
    if (is_file($stamp) && time() - (int)filemtime($stamp) < 300 && empty($_GET['force'])) {
        return [false, 'Vor weniger als 5 Minuten schon geholt. Mit &force=1 trotzdem.'];
    }
    @touch($stamp);

    $zipFile = $data . '/connectors-' . substr(md5((string)mt_rand()), 0, 8) . '.zip';
    $url = 'https://codeload.github.com/' . CONNECTOR_REPO . '/zip/refs/heads/' . CONNECTOR_BRANCH;
    $fh = @fopen($zipFile, 'wb');
    if (!$fh) {
        return [false, 'Konnte keine Zwischendatei anlegen.'];
    }
    // Anders als beim Scrapen (Club-Seiten mit kaputten Zertifikaten) MUSS
    // der Sync das Zertifikat prüfen: die einzige Vertrauensannahme ist
    // "kommt echt von GitHub". Ohne Prüfung könnte ein MITM beliebige
    // Connectoren unterschieben.
    $ch = curl_init($url);
    $opts = [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, // kein Downgrade auf http
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_MAXFILESIZE => 33554432, // 32 MB reichen weit, deckelt einen Zip-Bombe-Download
        CURLOPT_USERAGENT => 'clubmap-sync',
    ];
    // Manche Umgebungen liefern das CA-Bundle nur über einen bekannten Pfad.
    foreach (['/root/.ccr/ca-bundle.crt', '/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt'] as $ca) {
        if (is_file($ca)) {
            $opts[CURLOPT_CAINFO] = $ca;
            break;
        }
    }
    curl_setopt_array($ch, $opts);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $host = (string)parse_url((string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), PHP_URL_HOST);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    if (!$ok || $code >= 400) {
        @unlink($zipFile);
        return [false, 'Download von GitHub fehlgeschlagen (' . ($err ?: 'HTTP ' . $code) . ').'];
    }
    // Nach allen Redirects muss die Antwort wirklich von GitHub kommen.
    if ($host !== '' && !preg_match('/(^|\.)(github\.com|githubusercontent\.com)$/i', $host)) {
        @unlink($zipFile);
        return [false, 'Antwort kam nicht von GitHub, sondern von ' . $host . ' – abgebrochen.'];
    }

    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        @unlink($zipFile);
        return [false, 'Das Archiv von GitHub ließ sich nicht öffnen.'];
    }
    $new = $data . '/connectors.new';
    rm_tree($new);
    $count = 0;
    $bytes = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        if (!$st) {
            continue;
        }
        // nur connectors/<…>.yaml – keine Pfade nach oben, nichts anderes
        if (!preg_match('#^[^/]+/connectors/((?:[A-Za-z0-9][A-Za-z0-9_-]*/)+[A-Za-z0-9][A-Za-z0-9_-]*\.yaml)$#', $st['name'], $m)) {
            continue;
        }
        if ($st['size'] > 65536 || $count >= 20000) {
            continue;
        }
        $bytes += (int)$st['size'];
        if ($bytes > 67108864) { // 64 MB entpackt ist weit jenseits jedes echten Bestands
            break;
        }
        $dst = $new . '/' . $m[1];
        if (!is_dir(dirname($dst)) && !@mkdir(dirname($dst), 0755, true)) {
            continue;
        }
        $body = $zip->getFromIndex($i);
        if ($body !== false && @file_put_contents($dst, $body) !== false) {
            $count++;
        }
    }
    $zip->close();
    @unlink($zipFile);
    if ($count === 0) {
        rm_tree($new);
        return [false, 'Im Archiv lagen keine Connectoren – Repo oder Branch prüfen.'];
    }
    // Ein halb übertragenes Archiv darf den Bestand nicht wegräumen –
    // gemessen am Baum, aus dem gerade wirklich gelesen wird (connectors/,
    // regions/ oder ein früherer Sync), nicht nur am letzten Sync-Ordner.
    $have = count(yaml_files(connector_root()[0]));
    $got = count(yaml_files($new));
    if ($have > 0 && $got < $have / 2 && empty($_GET['shrink'])) {
        rm_tree($new);
        return [false, 'Das Archiv enthält nur ' . $got . ' statt bisher ' . $have
            . ' Connectoren – nichts geändert. Ist das gewollt, mit &shrink=1 wiederholen.'];
    }

    // Austausch in einem Zug: erst wegdrehen, dann einhängen
    $live = $data . '/connectors';
    $old = $data . '/connectors.old';
    rm_tree($old);
    if (is_dir($live) && !@rename($live, $old)) {
        rm_tree($new);
        return [false, 'Der bisherige Ordner data/connectors ließ sich nicht ersetzen.'];
    }
    if (!@rename($new, $live)) {
        @rename($old, $live); // zurückdrehen
        return [false, 'Der neue Ordner ließ sich nicht einhängen.'];
    }
    rm_tree($old);
    return [true, $got . ' Connectoren von ' . CONNECTOR_REPO . ' (' . CONNECTOR_BRANCH . ') übernommen.'];
}

/* Ordner samt Inhalt löschen – nur unterhalb von data/, ohne Symlinks zu folgen */
function rm_tree(string $dir): void
{
    $base = realpath(__DIR__ . '/data');
    $real = realpath($dir);
    if (!$base || !$real || strpos($real, $base) !== 0 || is_link($dir)) {
        return;
    }
    foreach (scandir($real) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = $real . '/' . $f;
        if (is_dir($p) && !is_link($p)) {
            rm_tree($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($real);
}

function admin_page(): void
{
    header('X-Robots-Tag: noindex');
    header('Content-Type: text/html; charset=utf-8');
    $css = '<style>body{max-width:760px;margin:0 auto;padding:20px 16px;background:#fff;color:#111;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}'
        . '@media (prefers-color-scheme:dark){body{background:#0c0c0c;color:#f5f5f5}input,select,textarea,button{background:#1d1d1d;color:#f5f5f5;border-color:#383838}}'
        . 'h1{font-size:22px}h2{font-size:16px;margin:26px 0 8px}input,select,textarea{width:100%;padding:9px 11px;border:1.5px solid #ccc;border-radius:10px;font:inherit;margin:3px 0 10px}'
        . 'label{font-size:13px;color:#888}button{padding:11px 18px;border:1.5px solid currentColor;border-radius:10px;background:none;color:inherit;font:inherit;font-weight:650;cursor:pointer}'
        . '.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}.imgs{display:flex;flex-wrap:wrap;gap:10px}'
        . '.imgs label{display:block;width:150px;font-size:12px;color:inherit}.imgs img{width:150px;height:100px;object-fit:cover;border-radius:8px;background:#8883}'
        . '.cand{border:1.5px solid #8885;border-radius:10px;padding:9px 11px;margin:6px 0;display:block}textarea{min-height:260px;font-family:ui-monospace,monospace;font-size:13px}'
        . '.ok{color:#178c49}.err{color:#cf3434}.muted{color:#888;font-size:13px}</style>';
    echo "<!doctype html>\n<html lang=\"de\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>Connector-Admin</title>$css</head><body><h1>Connector-Admin</h1>";
    $key = admin_key();
    if ($key === '') {
        echo '<p>Der Admin-Bereich ist deaktiviert.</p><p class="muted">Aktivieren: Datei <code>data/admin.key</code> mit einem geheimen Schlüssel (eine Zeile) anlegen, dann <code>?admin=1&amp;key=&lt;schlüssel&gt;</code> aufrufen.</p></body></html>';
        return;
    }
    if (!admin_ok()) {
        http_response_code(403);
        echo '<p class="err">Falscher oder fehlender Schlüssel (?admin=1&amp;key=…).</p></body></html>';
        return;
    }
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    $kq = '&amp;key=' . $h(rawurlencode($key));

    // Schritt 3: YAML speichern
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveyaml'])) {
        $cc = admin_slug((string)($_POST['cc'] ?? ''));
        $land = admin_area_slug((string)($_POST['land'] ?? '')); // Bundesland: baden-wuerttemberg
        $stadt = admin_slug((string)($_POST['stadt'] ?? ''));
        $id = admin_slug((string)($_POST['cid'] ?? ''));
        $yaml = (string)($_POST['yaml'] ?? '');
        [, $csrc] = connector_root();
        if (!local_mode() && $csrc === CONNECTOR_DIRS[0]) {
            // Die Clubdaten kommen gerade aus dem Repo. Was hier landet, wäre
            // beim nächsten ?sync weg – also gar nicht erst hinschreiben.
            echo '<p class="err">Die Connectoren kommen zurzeit per <code>?sync</code> aus '
                . $h(CONNECTOR_REPO) . '. Ein hier gespeicherter Club würde beim nächsten Sync verschwinden.</p>'
                . '<p>Die YAML unten kopieren und als Pull Request ins Repo geben – oder den Ordner '
                . '<code>data/connectors</code> löschen und stattdessen ein eigenes <code>connectors/</code> pflegen.</p>'
                . '<textarea readonly>' . $h($yaml) . '</textarea>'
                . '<p><a href="?admin=1' . $kq . '">Zurück</a></p></body></html>';
            return;
        }
        $dir = local_mode()
            ? __DIR__ . '/data/connector'
            : region_dir($cc) . ($land !== 'club' ? '/' . $land : '') . ($stadt !== 'club' ? '/' . $stadt : '');
        @mkdir($dir, 0755, true);
        $file = $dir . '/' . $id . '.yaml';
        if ($yaml !== '' && @file_put_contents($file, $yaml) !== false) {
            echo '<p class="ok">Gespeichert: <code>' . $h(str_replace(__DIR__ . '/', '', $file)) . '</code> – der Club ist ab sofort auf der Karte.</p>';
        } else {
            echo '<p class="err">Konnte nicht speichern (Ordner beschreibbar?). YAML unten manuell ablegen.</p><textarea readonly>' . $h($yaml) . '</textarea>';
        }
        echo '<p><a href="?admin=1' . $kq . '">Weiteren Club anlegen</a></p></body></html>';
        return;
    }

    // Schritt 2: Auswahl → YAML erzeugen
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mkyaml'])) {
        $g = fn($k) => trim((string)($_POST[$k] ?? ''));
        $imgSel = (array)($_POST['img'] ?? []);
        $imgOg = (array)($_POST['imgog'] ?? []);
        // Agent-Mapping aus der Auswahl: nur og-Bilder → og, sonst auto, keine → none
        $selOg = array_intersect($imgSel, $imgOg);
        $imgMode = !$imgSel ? 'none' : (count($selOg) === count($imgSel) ? 'og' : 'auto');
        $infoMode = $g('infosel') === 'none' ? 'none' : 'auto';
        $evMode = in_array($g('evmode'), ['auto', 'jsonld', 'text', 'none'], true) ? $g('evmode') : 'auto';
        $id = admin_slug($g('cid') !== '' ? $g('cid') : $g('cname'));
        $y = [];
        $y[] = '# ' . $g('cname') . ' – ' . $g('city');
        $y[] = 'id: ' . $id;
        $y[] = 'name: ' . admin_yaml_val($g('cname'));
        $y[] = 'city: ' . admin_yaml_val($g('city'));
        if ($g('addr') !== '') $y[] = 'address: ' . admin_yaml_val($g('addr'));
        $y[] = 'lat: ' . $g('lat');
        $y[] = 'lng: ' . $g('lng');
        $site = $g('website') !== '' ? $g('website') : $g('aurl');
        if ($site !== '') $y[] = 'website: ' . admin_yaml_val($site);
        if ($g('genres') !== '') $y[] = 'genres: ' . admin_yaml_val($g('genres'));
        if ($g('hours') !== '') $y[] = 'hours: ' . admin_yaml_val($g('hours'));
        if ($g('note') !== '') $y[] = 'note: ' . admin_yaml_val($g('note'));
        $y[] = 'checked: ' . date('Y-m');   // SPEC.md: Pflichtfeld
        $y[] = '';
        $y[] = '# Wie die Website ausgelesen wird';
        $y[] = 'scrape_url: ' . admin_yaml_val($g('aurl'));
        $y[] = 'scrape_events: ' . $evMode;
        $y[] = 'scrape_images: ' . $imgMode;
        $y[] = 'scrape_info: ' . $infoMode;
        $y[] = 'scrape_closed: auto';
        $yaml = implode("\n", $y) . "\n";
        if (!is_numeric($g('lat')) || !is_numeric($g('lng'))) {
            echo '<p class="err">lat/lng fehlen oder sind keine Zahlen – bitte zurück und ergänzen.</p>';
        }
        echo '<h2>Erzeugte YAML</h2><form method="post"><textarea name="yaml">' . $h($yaml) . '</textarea>';
        echo '<div class="grid"><div><label>Region</label><select name="cc">';
        foreach (region_list() ?: ['de'] as $rc) echo '<option' . ($rc === 'de' ? ' selected' : '') . '>' . $h($rc) . '</option>';
        echo '</select></div><div><label>Bundesland-Ordner (leer = direkt)</label><input name="land" value="' . $h($g('land')) . '"></div></div>';
        echo '<input type="hidden" name="stadt" value="' . $h($g('city')) . '"><input type="hidden" name="cid" value="' . $h($id) . '">';
        echo '<input type="hidden" name="key" value="' . $h($key) . '"><input type="hidden" name="saveyaml" value="1">';
        echo '<button>Speichern</button> <span class="muted">oder YAML kopieren und selbst ablegen</span></form>';
        echo '<p><a href="?admin=1' . $kq . '">Zurück</a></p></body></html>';
        return;
    }

    // Schritt 1: URL analysieren
    $url = trim((string)($_GET['aurl'] ?? ''));
    echo '<form><input type="hidden" name="admin" value="1"><input type="hidden" name="key" value="' . $h($key) . '">';
    echo '<label>Link zur Club-Website</label><input name="aurl" value="' . $h($url) . '" placeholder="https://…" required><button>Analysieren</button></form>';
    if ($url !== '' && preg_match('#^https?://#i', $url)) {
        $res = fetch_all(['x' => $url], [], 20);
        $r = $res['x'] ?? null;
        if (!$r || $r['code'] === 0) {
            echo '<p class="err">Seite nicht erreichbar: ' . $h($r['err'] ?? 'unbekannt') . '</p>';
        } else {
            $html = $r['body'];
            $base = $r['url'] ?: $url;
            $title = preg_match('#<title[^>]*>(.*?)</title>#si', $html, $tm) ? trim(html_entity_decode(strip_tags($tm[1]), ENT_QUOTES)) : '';
            $title = trim((string)preg_replace('#\s*[|–-].*$#u', '', $title));
            $imgsAll = extract_images($html, $base, 'auto', 12);
            $imgsOg = extract_images($html, $base, 'og', 12);
            $info = extract_info($html);
            $events = auto_events($html, null, null);
            $hasJsonld = (bool)jsonld_events($html);
            $warn = detect_closure($html);
            $host = (string)parse_url($base, PHP_URL_HOST);
            echo '<form method="post"><input type="hidden" name="key" value="' . $h($key) . '"><input type="hidden" name="mkyaml" value="1"><input type="hidden" name="aurl" value="' . $h($url) . '">';
            echo '<h2>Basisdaten</h2><div class="grid">';
            echo '<div><label>Name</label><input name="cname" value="' . $h($title) . '" required></div>';
            echo '<div><label>ID (a-z0-9, leer = aus Name)</label><input name="cid" value="' . $h(admin_slug($title)) . '"></div>';
            echo '<div><label>Stadt</label><input name="city" required></div>';
            echo '<div><label>Bundesland-Ordner (z. B. bayern)</label><input name="land"></div>';
            echo '<div><label>Adresse (Straße Nr.)</label><input name="addr"></div>';
            echo '<div><label>Website</label><input name="website" value="' . $h('https://' . $host) . '"></div>';
            echo '<div><label>lat</label><input name="lat" required></div><div><label>lng</label><input name="lng" required></div>';
            echo '<div><label>Musik (kommagetrennt)</label><input name="genres"></div>';
            echo '<div><label>Öffnungszeiten (z. B. Fr,Sa 23:00-05:00; leer = nur Events)</label><input name="hours"></div>';
            echo '</div><label>Notiz (optional)</label><input name="note">';
            if ($warn !== '') {
                echo '<p class="muted">Erkannter Website-Hinweis: „' . $h($warn) . '“ (wird automatisch als Warnung angezeigt)</p>';
            }
            echo '<h2>Infotext – was passt?</h2>';
            echo '<label class="cand"><input type="radio" name="infosel" value="auto" checked> ' . ($info !== '' ? $h($info) : '<i>nichts gefunden – Agent versucht es weiter automatisch</i>') . '</label>';
            echo '<label class="cand"><input type="radio" name="infosel" value="none"> Keinen Infotext anzeigen</label>';
            echo '<h2>Bilder – welche sind brauchbar?</h2>';
            if ($imgsAll) {
                echo '<div class="imgs">';
                foreach ($imgsAll as $iu) {
                    $isOg = in_array($iu, $imgsOg, true);
                    echo '<label><img src="' . $h($iu) . '" referrerpolicy="no-referrer" loading="lazy" onerror="this.closest(\'label\').style.opacity=.3">';
                    echo '<input type="checkbox" name="img[]" value="' . $h($iu) . '" checked> übernehmen' . ($isOg ? ' <span class="muted">(Vorschaubild)</span>' : '') . '</label>';
                    if ($isOg) echo '<input type="hidden" name="imgog[]" value="' . $h($iu) . '">';
                }
                echo '</div><p class="muted">Auswahl bestimmt den Bild-Agenten: nur Vorschaubild → og, gemischt → auto, nichts → keine Bilder.</p>';
            } else {
                echo '<p class="muted">Keine Bilder gefunden.</p>';
            }
            echo '<h2>Programm/Events</h2>';
            echo '<p class="muted">' . count($events) . ' Termine erkannt' . ($hasJsonld ? ' (strukturierte Daten vorhanden)' : '') . ':</p>';
            foreach (array_slice($events, 0, 6) as $e) {
                echo '<div class="cand">' . $h(($e['date'] ?? '') . ' – ' . ($e['title'] ?? '')) . '</div>';
            }
            $evPre = $hasJsonld ? 'jsonld' : ($events ? 'auto' : 'none');
            echo '<label>Events-Agent</label><select name="evmode">';
            foreach (['auto', 'jsonld', 'text', 'none'] as $m) {
                echo '<option' . ($m === $evPre ? ' selected' : '') . '>' . $m . '</option>';
            }
            echo '</select><br><br><button>YAML erzeugen</button></form>';
        }
    }
    // Connectoren aus dem Repo nachziehen – die Clubdaten liegen ja dort.
    [, $csrc] = connector_root();
    echo '<h2>Connectoren</h2><p class="muted">Quelle: <code>' . $h($csrc) . '/</code>'
        . (is_file(__DIR__ . '/data/connectors.stamp')
            ? ', zuletzt geholt ' . $h(date('d.m.Y H:i', (int)filemtime(__DIR__ . '/data/connectors.stamp'))) : '')
        . '</p><p><a href="?sync=1' . $kq . '"><button type="button">Aus '
        . $h(CONNECTOR_REPO) . ' holen</button></a></p>';
    echo '<p class="muted">Alle Angaben ohne Gewähr – Inhalte werden von der Club-Website übernommen.</p></body></html>';
}

/* Karten-Pin mit Vinyl: Clubkarte in einem Bild */
function icon(string $kind): void
{
    header('Cache-Control: public, max-age=86400');
    if ($kind === 'touch' && function_exists('imagecreatetruecolor')) {
        $s = 180;
        $im = imagecreatetruecolor($s, $s);
        $black = imagecolorallocate($im, 12, 12, 12);
        $white = imagecolorallocate($im, 250, 250, 250);
        imagefill($im, 0, 0, $black);
        imagefilledpolygon($im, [56, 96, 124, 96, 90, 150], $white);
        imagefilledellipse($im, 90, 74, 88, 88, $white);
        imagefilledellipse($im, 90, 74, 54, 54, $black);
        imagefilledellipse($im, 90, 74, 14, 14, $white);
        header('Content-Type: image/png');
        imagepng($im);
        return;
    }
    header('Content-Type: image/svg+xml');
    echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
  <rect width="64" height="64" rx="14" fill="#0c0c0c"/>
  <polygon points="20,34 44,34 32,53" fill="#fafafa"/>
  <circle cx="32" cy="26" r="15.5" fill="#fafafa"/>
  <circle cx="32" cy="26" r="9.5" fill="#0c0c0c"/>
  <circle cx="32" cy="26" r="2.5" fill="#fafafa"/>
</svg>
SVG;
}

/*
 * Stapelweise (schont Verbindungslimits auf Shared Hosting), mit Zeitbudget.
 * $cond[id] = ['etag'=>…, 'lm'=>…] löst bedingte Requests aus; unveränderte
 * Seiten antworten dann mit 304 und kosten fast nichts.
 */
/* curl-Optionen für einen Abruf – für beide Verfahren identisch */
function curl_opts($id, array $cond, &$bodies, &$over, &$meta, int $tmo = 8): array
{
    $headers = [];
    if (!empty($cond[$id]['etag'])) {
        $headers[] = 'If-None-Match: ' . $cond[$id]['etag'];
    }
    if (!empty($cond[$id]['lm'])) {
        $headers[] = 'If-Modified-Since: ' . $cond[$id]['lm'];
    }
    $meta[$id] = ['etag' => '', 'lm' => ''];
    return [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => $tmo,
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$bodies, &$over, $id) {
            $bodies[$id] = ($bodies[$id] ?? '') . $chunk;
            if (strlen($bodies[$id]) > 3000000) {
                $over[$id] = true;
                return -1; // Abbruch: Seite unplausibel groß
            }
            return strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$meta, $id) {
            if (preg_match('#^(etag|last-modified):\s*(.+)$#i', trim($line), $h)) {
                $meta[$id][strtolower($h[1]) === 'etag' ? 'etag' : 'lm'] = trim($h[2]);
            }
            return strlen($line);
        },
    ];
}

/* Ergebnis eines Handles einheitlich auswerten */
function curl_result($ch, string $body, string $err, array $m): array
{
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($code === 304) {
        return ['code' => 304, 'body' => '', 'url' => '', 'etag' => '', 'lm' => ''];
    }
    if ($body !== '' && $code < 400) {
        return ['code' => $code, 'body' => $body,
            'url' => (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
            'etag' => $m['etag'], 'lm' => $m['lm']];
    }
    return ['code' => 0, 'body' => '', 'url' => '', 'etag' => '', 'lm' => '',
        'err' => $err ?: 'HTTP ' . $code];
}

/*
 * Notnagel: manche Hoster erlauben curl_multi nicht oder liefern damit
 * nichts. Dann eben nacheinander – langsamer, aber es kommt etwas an.
 */
function fetch_seq(array $urls, array $cond, int $deadline): array
{
    $out = [];
    $bodies = $over = $meta = [];
    foreach ($urls as $id => $url) {
        $left = $deadline - time();
        if ($left <= 0) {
            break;
        }
        $ch = curl_init($url);
        // nie länger warten als das Restbudget hergibt – sonst reißt der
        // Hoster den Request ab, bevor wir etwas speichern konnten
        curl_setopt_array($ch, curl_opts($id, $cond, $bodies, $over, $meta, (int)min(6, $left)));
        curl_exec($ch);
        $body = empty($over[$id]) ? ($bodies[$id] ?? '') : '';
        $out[$id] = curl_result($ch, $body, curl_error($ch), $meta[$id]);
        unset($bodies[$id]);
        curl_close($ch);
    }
    return $out;
}

function fetch_all(array $urls, array $cond = [], int $budget = 40): array
{
    if (!$urls) {
        return [];
    }
    $deadline = time() + $budget;
    if (!function_exists('curl_multi_init')) {
        return fetch_seq($urls, $cond, $deadline);
    }
    $out = [];
    $meta = [];
    $bodies = [];
    $over = [];
    foreach (array_chunk($urls, 6, true) as $chunk) {
        if (time() > $deadline) {
            break;
        }
        $mh = curl_multi_init();
        $handles = [];
        $tmo = (int)max(3, min(8, $deadline - time()));
        foreach ($chunk as $id => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, curl_opts($id, $cond, $bodies, $over, $meta, $tmo));
            curl_multi_add_handle($mh, $ch);
            $handles[$id] = $ch;
        }
        do {
            curl_multi_exec($mh, $running);
            if ($running > 0 && curl_multi_select($mh, 0.2) === -1) {
                usleep(50000);
            }
        } while ($running > 0);
        // konkrete Fehlgründe je Handle einsammeln (curl_error ist bei multi oft leer)
        $cerrs = [];
        while ($mi = curl_multi_info_read($mh)) {
            if ((int)$mi['result'] === 0) {
                continue;
            }
            foreach ($handles as $hid => $hch) {
                if ($hch === $mi['handle']) {
                    $cerrs[$hid] = function_exists('curl_strerror') ? curl_strerror((int)$mi['result']) : 'curl-Fehler ' . (int)$mi['result'];
                    break;
                }
            }
        }
        foreach ($handles as $id => $ch) {
            $body = empty($over[$id]) ? ($bodies[$id] ?? '') : '';
            unset($bodies[$id]);
            $out[$id] = curl_result($ch, $body, $cerrs[$id] ?? curl_error($ch), $meta[$id]);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    // Kein einziger Treffer, obwohl Adressen da waren? Dann liegt es an
    // curl_multi – der Reihe nach klappt es auf solchen Hostern doch.
    // Nur einmal je Request und nur für die tatsächlich gescheiterten
    // Adressen, sonst frisst der Rettungsversuch das ganze Zeitbudget.
    static $rescued = false;
    $hit = 0;
    foreach ($out as $r) {
        if ($r['code'] !== 0) {
            $hit++;
        }
    }
    if ($hit === 0 && !$rescued && $deadline - time() > 2) {
        $rescued = true;
        $seq = fetch_seq(array_intersect_key($urls, $out), $cond, $deadline);
        foreach ($seq as $id => $r) {
            if ($r['code'] !== 0 || !isset($out[$id])) {
                $out[$id] = $r;
            }
        }
    }
    return $out;
}

function jsonld_events(string $html): array
{
    $events = [];
    if (!preg_match_all('#<script[^>]+ld\+json[^>]*>(.*?)</script>#si', $html, $m)) {
        return $events;
    }
    foreach ($m[1] as $block) {
        $data = json_decode(trim($block), true);
        if (!is_array($data)) {
            continue;
        }
        $stack = [$data];
        while ($stack) {
            $node = array_pop($stack);
            if (!is_array($node)) {
                continue;
            }
            if (isset($node['@type']) && stripos(is_array($node['@type']) ? implode(',', $node['@type']) : $node['@type'], 'Event') !== false
                && isset($node['startDate'], $node['name'])) {
                $ts = strtotime((string)$node['startDate']);
                if ($ts) {
                    $ev = ['date' => date('Y-m-d', $ts), 'title' => trim(html_entity_decode((string)$node['name'], ENT_QUOTES))];
                    if (!empty($node['description']) && is_string($node['description'])) {
                        $d = cut_text(html_entity_decode(strip_tags($node['description']), ENT_QUOTES), 280);
                        if (strlen($d) > 3) {
                            $ev['desc'] = $d;
                        }
                    }
                    // Event-Bild, falls die Seite eines mitliefert
                    $img = $node['image'] ?? null;
                    if (is_array($img)) {
                        $img = $img['url'] ?? ($img[0]['url'] ?? ($img[0] ?? null));
                    }
                    if (is_string($img) && preg_match('#^https?://#i', $img)) {
                        $ev['img'] = (string)preg_replace('#^http://#i', 'https://', $img);
                    }
                    $events[] = $ev;
                }
            }
            foreach ($node as $v) {
                if (is_array($v)) {
                    $stack[] = $v;
                }
            }
        }
    }
    return $events;
}

function html_lines(string $html): array
{
    if (!preg_match('##u', $html)) {
        $conv = @iconv('Windows-1252', 'UTF-8//IGNORE', $html);
        if ($conv !== false) {
            $html = $conv;
        }
    }
    $html = preg_replace('#<(script|style|noscript|svg)[^>]*>.*?</\1>#si', ' ', $html);
    $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr|/td|/article|/section|/a|/span)[^>]*>#i', "\n", $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
    $lines = array_map(fn($l) => trim((string)preg_replace('#[ \t\x{00a0}]+#u', ' ', $l)), explode("\n", $text));
    return array_values(array_filter($lines, fn($l) => $l !== ''));
}

/* Zeilenweise Datums-/Titel-Extraktion; optional auf einen Seitenbereich begrenzt */
function text_events(string $html, ?string $from = null, ?string $to = null): array
{
    if ($from && @preg_match('#' . str_replace('#', '\#', $from) . '#si', $html, $m, PREG_OFFSET_CAPTURE) === 1) {
        $html = substr($html, $m[0][1]);
    }
    if ($to && @preg_match('#' . str_replace('#', '\#', $to) . '#si', $html, $m, PREG_OFFSET_CAPTURE) === 1) {
        $html = substr($html, 0, $m[0][1]);
    }
    $lines = html_lines($html);
    $events = [];
    foreach ($lines as $i => $line) {
        if (strlen($line) > 200 || !($date = de_date($line))) {
            continue;
        }
        $title = trim(preg_replace(
            '#^(montag|dienstag|mittwoch|donnerstag|freitag|samstag|sonntag|mo|di|mi|do|fr|sa|so)?\.?,?\s*\d{1,2}\.\s?(\d{1,2}\.|januar|februar|märz|april|mai|juni|juli|august|september|oktober|november|dezember)?\s?\d{0,4}\s*[–—\-·|:,]?\s*#iu',
            '', $line
        ));
        if (strlen($title) < 3) {
            foreach ([$i + 1, $i + 2] as $j) {
                if (isset($lines[$j]) && strlen($lines[$j]) >= 3 && strlen($lines[$j]) < 140 && !de_date($lines[$j])) {
                    $title = $lines[$j];
                    break;
                }
            }
        }
        if (strlen($title) >= 3 && strlen($title) <= 140) {
            $events[] = ['date' => $date, 'title' => $title];
        }
    }
    return $events;
}

function auto_events(string $html, ?string $from = null, ?string $to = null): array
{
    return jsonld_events($html) ?: text_events($html, $from, $to);
}

function cut_text(string $s, int $max): string
{
    $s = trim((string)preg_replace('#\s+#u', ' ', $s));
    if (strlen($s) <= $max) {
        return $s;
    }
    $cut = substr($s, 0, $max);
    while ($cut !== '' && (ord(substr($cut, -1)) & 0xC0) === 0x80) {
        $cut = substr($cut, 0, -1); // kein angeschnittenes UTF-8-Zeichen
    }
    if ($cut !== '' && ord(substr($cut, -1)) >= 0xC0) {
        $cut = substr($cut, 0, -1); // übriges Lead-Byte ohne Sequenz entfernen
    }
    return rtrim($cut, ' .,;:') . ' …';
}

function abs_url(string $src, string $base): ?string
{
    $src = trim($src);
    if ($src === '' || strpos($src, 'data:') === 0) {
        return null;
    }
    if (preg_match('#^https?://#i', $src)) {
        return $src;
    }
    $p = parse_url($base);
    if (empty($p['scheme']) || empty($p['host'])) {
        return null;
    }
    $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (strpos($src, '//') === 0) {
        return $p['scheme'] . ':' . $src;
    }
    if ($src[0] === '/') {
        return $origin . $src;
    }
    $dir = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/') ?: '/';
    return $origin . $dir . $src;
}

/* og:image zuerst, danach Content-Bilder; Logos/Icons u. ä. aussortiert */
function extract_images(string $html, string $base, string $mode, int $max = 4): array
{
    $found = [];
    if (preg_match_all('#<meta[^>]+(?:property|name)=["\'](?:og:image(?::url)?|twitter:image)["\'][^>]*>#i', $html, $tags)) {
        foreach ($tags[0] as $tag) {
            if (preg_match('#content=(["\'])(.*?)\1#is', $tag, $c)) {
                $found[] = html_entity_decode($c[2], ENT_QUOTES);
            }
        }
    }
    if ($mode !== 'og' && preg_match_all('#<(?:img|source)\b[^>]*>#is', $html, $m)) {
        foreach ($m[0] as $tag) {
            // Lazy-Load-Attribute zuerst: dort steckt das echte Bild,
            // src ist bei solchen Seiten nur ein Platzhalter
            foreach (['data-src', 'data-lazy-src', 'data-original', 'data-srcset', 'srcset', 'src'] as $attr) {
                if (!preg_match('#(?<![-\w])' . $attr . '=(["\'])(.*?)\1#is', $tag, $a)) {
                    continue;
                }
                $v = trim(html_entity_decode($a[2], ENT_QUOTES));
                if (strpos($attr, 'srcset') !== false) {
                    // erste Quelle des srcset, ohne Breitenangabe
                    $v = trim((string)strtok($v, ','));
                    $v = trim((string)strtok($v, ' '));
                }
                if ($v !== '' && strpos($v, 'data:') !== 0) {
                    $found[] = $v;
                    break;
                }
            }
        }
    }
    $out = [];
    $seen = [];
    foreach ($found as $src) {
        $u = abs_url($src, $base);
        if (!$u || preg_match('#\.svg(\?|$)|logo|icon|favicon|sprite|pixel|avatar|emoji|placeholder|blank|spacer|1x1|transparent|loading|badge|banner-cookie#i', $u)) {
            continue;
        }
        // HTTPS-Seiten blocken http-Bilder (Mixed Content) – Schema anheben
        $u = (string)preg_replace('#^http://#i', 'https://', $u);
        // dasselbe Bild in mehreren Größen (nur anderer Query-String) nicht doppelt
        $key = (string)preg_replace('#\?.*$#', '', $u);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $u;
        if (count($out) >= $max) {
            break;
        }
    }
    return $out;
}

/*
 * Standardisierte Erkennung von Sonderschließungen/Ankündigungen auf der
 * Club-Website ("Betriebsferien", "wegen Umbau geschlossen", …). Liefert den
 * betroffenen Satz als Hinweis – bewusst nur ein Hinweis, keine Garantie.
 */
function detect_closure(string $html): string
{
    $t = html_entity_decode(strip_tags(substr($html, 0, 120000)), ENT_QUOTES);
    $t = (string)preg_replace('#\s+#u', ' ', $t);
    $pats = 'betriebsferien|sommerpause|winterpause|betriebsurlaub'
        . '|(?:vorübergehend|vorerst|bis auf weiteres|aktuell|derzeit|heute|diese woche) geschlossen'
        . '|bleib(?:t|en) .{0,30}geschlossen|geschlossen (?:bis|wegen|vom)'
        . '|wegen (?:umbau|renovierung|sanierung)|temporarily closed|closed until';
    if (!preg_match('#(' . $pats . ')#iu', $t, $m, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    // genau den Satz mit der Fundstelle ausschneiden
    $pos = $m[1][1];
    $winStart = max(0, $pos - 160);
    $before = substr($t, $winStart, $pos - $winStart);
    $rel = max((int)strrpos($before, '.'), (int)strrpos($before, '!'), (int)strrpos($before, '?'));
    $start = $rel > 0 ? $winStart + $rel + 1 : $winStart;
    $after = $pos + strlen($m[1][0]);
    $end = $after + 120;
    if (preg_match('#[.!?]#', substr($t, $after, 200), $em, PREG_OFFSET_CAPTURE)) {
        $end = $after + $em[0][1] + 1;
    }
    return cut_text(trim(substr($t, $start, $end - $start)), 160);
}

function extract_info(string $html): string
{
    foreach (['#<meta[^>]+(?:property|name)=["\']og:description["\'][^>]*>#i',
              '#<meta[^>]+name=["\']description["\'][^>]*>#i',
              '#<meta[^>]+(?:property|name)=["\']twitter:description["\'][^>]*>#i'] as $pat) {
        if (preg_match($pat, $html, $m) && preg_match('#content=(["\'])(.*?)\1#is', $m[0], $c) && trim($c[2]) !== '') {
            return cut_text(html_entity_decode($c[2], ENT_QUOTES), 300);
        }
    }
    // Fallback: erster substanzieller Textabsatz der Seite
    if (preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $html, $ps)) {
        foreach ($ps[1] as $p) {
            $t = trim(html_entity_decode(strip_tags($p), ENT_QUOTES));
            $t = trim((string)preg_replace('#\s+#u', ' ', $t));
            if (strlen($t) >= 60 && !preg_match('#cookie|javascript|browser|newsletter#i', $t)) {
                return cut_text($t, 300);
            }
        }
    }
    return '';
}

/* "Sa 23.08." / "23.08.2026" / "23. August" → Y-m-d (nächstliegendes Datum) */
function de_date(string $s): ?string
{
    $months = ['januar' => 1, 'februar' => 2, 'märz' => 3, 'april' => 4, 'mai' => 5, 'juni' => 6,
        'juli' => 7, 'august' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'dezember' => 12];
    $d = $m = $y = 0;
    if (preg_match('#(\d{1,2})\.\s?(\d{1,2})\.\s?(\d{4})#', $s, $x)) {
        [, $d, $m, $y] = array_map('intval', $x);
    } elseif (preg_match('#(\d{1,2})\.\s?(\d{1,2})\.#', $s, $x)) {
        $d = (int)$x[1];
        $m = (int)$x[2];
    } elseif (preg_match('#(\d{1,2})\.\s*(' . implode('|', array_keys($months)) . ')#iu', $s, $x)) {
        $d = (int)$x[1];
        $m = $months[strtr(strtolower($x[2]), ['Ä' => 'ä'])];
    } else {
        return null;
    }
    if ($m < 1 || $m > 12 || !checkdate($m, $d, $y ?: 2024)) {
        return null;
    }
    if (!$y) {
        // Jahr so wählen, dass das Datum am nächsten an heute liegt
        $y = (int)date('Y');
        $now = time();
        foreach ([$y - 1, $y + 1] as $cand) {
            if (abs(mktime(12, 0, 0, $m, $d, $cand) - $now) < abs(mktime(12, 0, 0, $m, $d, $y) - $now)) {
                $y = $cand;
            }
        }
    }
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

function cache_file(string $key = 'live'): ?string
{
    $dir = __DIR__ . '/data/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && !is_link($dir) && @is_writable($dir)) {
        return $dir . '/' . $key . '.json';
    }
    // Fallback: Temp-Verzeichnis (z. B. wenn /data schreibgeschützt deployed ist)
    $base = sys_get_temp_dir();
    $dir = $base . '/clubkarte-' . substr(md5(__FILE__), 0, 12);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700);
    }
    $owned = !function_exists('posix_geteuid') || @fileowner($dir) === posix_geteuid();
    if (!is_dir($dir) || is_link($dir) || !@is_writable($dir) || !$owned) {
        return null;
    }
    return $dir . '/' . $key . '.json';
}

function json_write(?string $file, array $data): void
{
    if (!$file) {
        return;
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json !== false && @file_put_contents($file . '.tmp', $json) !== false) {
        @rename($file . '.tmp', $file);
    }
}

function scrape_key(string $cc): string
{
    return $cc === '' ? 'live' : 'live-' . $cc;
}

function cache_read(string $cc = ''): array
{
    $file = cache_file(scrape_key($cc));
    if (!$file || !is_file($file)) {
        return [];
    }
    $c = json_decode((string)file_get_contents($file), true) ?: [];
    return ($c['v'] ?? 0) === CACHE_V ? $c : []; // Formatwechsel: neu aufbauen
}

function cache_write(string $file, array $data): void
{
    $json = json_encode(['v' => CACHE_V, 'ts' => time(), 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json !== false && @file_put_contents($file . '.tmp', $json) !== false) {
        @rename($file . '.tmp', $file);
    }
}

/*
 * Holt pro Lauf nur die $limit am längsten nicht geprüften Connectoren
 * (kleine, schnelle Läufe – über mehrere Läufe wird alles abgedeckt).
 * $limit 0 = alle (für ?refresh und ?check).
 */
function scrape_refresh(array $old, array $connectors, int $limit = 24, string $cc = '', int $secs = 0): void
{
    if (!$connectors) {
        return; // nie mit leerer Liste den Datenbestand wegräumen
    }
    $file = cache_file(scrape_key($cc));
    if (!$file) {
        return;
    }
    $lock = fopen($file . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) {
            fclose($lock);
        }
        return;
    }
    @ignore_user_abort(true);
    @set_time_limit(90);
    // unter dem Lock erneut lesen: hat ein anderer Prozess gerade
    // aktualisiert, nichts überschreiben
    $cache = cache_read($cc);
    $unseen = 0;
    foreach ($connectors as $uid => $ucfg) {
        if (!isset($cache['data'][$uid]['ft'])) {
            $unseen++;
        }
    }
    // Drosselung gilt erst, wenn die Erstbefüllung durch ist
    if ($limit > 0 && $unseen === 0 && time() - ($cache['ts'] ?? 0) < 300) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return;
    }
    $old = $cache['data'] ?? $old;
    // Zeitstempel sofort setzen: bricht der Hoster den Lauf ab,
    // rennt nicht jeder folgende Request erneut hinein
    cache_write($file, $old);

    $data = array_intersect_key($old, $connectors); // entfernte Connectoren aufräumen
    // die am längsten nicht geprüften zuerst
    uksort($connectors, fn($a, $b) => (int)($old[$a]['ft'] ?? 0) <=> (int)($old[$b]['ft'] ?? 0));
    if ($limit > 0) {
        $connectors = array_slice($connectors, 0, $limit, true);
    }
    $cond = [];
    $cks = [];
    foreach ($connectors as $id => $cfg) {
        // Validatoren nur senden, wenn der Connector unverändert ist –
        // sonst muss die Seite trotz 304-fähigem Server neu verarbeitet werden.
        // Mit Info-/Bilder-Deeplinks immer voll laden: der Hash unten
        // vergleicht dann Haupt- und Unterseiten gemeinsam.
        $cks[$id] = md5(json_encode($cfg));
        $same = ($old[$id]['ck'] ?? '') === $cks[$id]
            && empty($cfg['info_url']) && empty($cfg['images_url']);
        $cond[$id] = $same
            ? ['etag' => $old[$id]['etag'] ?? '', 'lm' => $old[$id]['lm'] ?? '']
            : ['etag' => '', 'lm' => ''];
    }
    $night = date('Y-m-d', time() - 12 * 3600); // Tagesgrenze Mittag: laufende Nacht zählt als heute
    $deadline = time() + ($secs > 0 ? $secs : ($limit > 0 ? 22 : 70));
    $tried = $failed = 0;
    $lastErr = '';
    // In Teilpaketen arbeiten und nach jedem Paket speichern: bricht der
    // Hoster den Lauf ab, ist das bis dahin Geholte trotzdem gesichert.
    $chunks = array_chunk($connectors, 6, true);
    $rest = count($chunks);
    foreach ($chunks as $batch) {
        if (time() >= $deadline) {
            break;
        }
        $rest--;
        $urls = [];
        foreach ($batch as $id => $cfg) {
            $urls[$id] = $cfg['url'];
            // Deeplinks zu eigenen Unterseiten (Infotext, Bilder) mitladen
            if (!empty($cfg['info_url'])) {
                $urls[$id . '@info'] = $cfg['info_url'];
            }
            if (!empty($cfg['images_url'])) {
                $urls[$id . '@img'] = $cfg['images_url'];
            }
        }
        // Zeit gerecht auf die verbleibenden Pakete verteilen, sonst
        // verbraucht das erste Paket das gesamte Budget.
        $results = fetch_all($urls, $cond, (int)max(4, intdiv($deadline - time(), $rest + 1)));
        foreach ($batch as $tid => $tcfg) {
            if (!isset($results[$tid])) {
                continue; // gar nicht erst abgeschickt (Zeitbudget) – zählt nicht als Fehler
            }
            $tried++;
            if ($results[$tid]['code'] === 0) {
                $failed++;
                $lastErr = $results[$tid]['err'] ?? 'keine Antwort';
            }
        }
        foreach ($batch as $id => $cfg) {
        $data[$id]['ft'] = time(); // Versuch zählt, sonst klemmt ein toter Host die Rotation
        if (!isset($results[$id]) || $results[$id]['code'] === 0) {
            continue; // nicht erreichbar – bestehende Daten behalten
        }
        if ($results[$id]['code'] === 304) {
            continue; // Seite unverändert – bestehende Daten bleiben
        }
        $html = $results[$id]['body'];
        $base = $results[$id]['url'] ?: $cfg['url'];
        $hash = md5($html . ($results[$id . '@info']['body'] ?? '') . ($results[$id . '@img']['body'] ?? ''));
        if (($old[$id]['h'] ?? '') === $hash && ($old[$id]['ck'] ?? '') === $cks[$id]) {
            // Inhalt und Connector unverändert, nur Validatoren mitnehmen
            $data[$id]['etag'] = $results[$id]['etag'];
            $data[$id]['lm'] = $results[$id]['lm'];
            continue;
        }
        try {
            $entry = ['ft' => time(), 'etag' => $results[$id]['etag'], 'lm' => $results[$id]['lm'], 'h' => $hash, 'ck' => $cks[$id]];
            if ($cfg['events'] !== 'none') {
                $events = $cfg['events'] === 'jsonld' ? jsonld_events($html)
                    : ($cfg['events'] === 'text' ? text_events($html, $cfg['from'], $cfg['to'])
                    : auto_events($html, $cfg['from'], $cfg['to']));
                $events = array_values(array_filter($events, fn($e) => ($e['date'] ?? '') >= $night && ($e['title'] ?? '') !== ''));
                usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));
                $seen = [];
                $events = array_values(array_filter($events, function ($e) use (&$seen) {
                    $k = $e['date'] . '|' . strtolower($e['title']);
                    if (isset($seen[$k])) {
                        return false;
                    }
                    return $seen[$k] = true;
                }));
                $entry['events'] = array_slice($events, 0, 8);
            }
            if ($cfg['images'] !== 'none') {
                $ghtml = $results[$id . '@img']['body'] ?? '';
                $gbase = $ghtml !== '' ? ($results[$id . '@img']['url'] ?: $cfg['images_url']) : $base;
                $entry['images'] = extract_images($ghtml !== '' ? $ghtml : $html, $gbase, $cfg['images'])
                    ?: ($old[$id]['images'] ?? []);
            }
            if ($cfg['info'] !== 'none') {
                $ihtml = $results[$id . '@info']['body'] ?? '';
                $entry['info'] = extract_info($ihtml !== '' ? $ihtml : $html) ?: ($old[$id]['info'] ?? '');
            }
            if (($cfg['closed'] ?? 'auto') !== 'none') {
                // Sonderschließung/Ankündigung: frisch bewerten, nie veraltet stehen lassen
                $w = detect_closure($html);
                if ($w !== '') {
                    $entry['warn'] = $w;
                }
            }
            $data[$id] = $entry;
        } catch (Throwable $e) {
            continue;
        }
        }
        cache_write($file, $data); // Fortschritt sichern
    }
    // Scheitert wirklich jeder Abruf, blockiert der Hoster ausgehende
    // Verbindungen. Nur melden, wenn die Stichprobe groß genug war und es
    // zweimal hintereinander passiert – ein toter Host ist kein Ausfall.
    if ($tried > 0) {
        $nFile = cache_file('net-' . ($cc === '' ? 'local' : $cc));
        $prev = $nFile && is_file($nFile)
            ? (json_decode((string)file_get_contents($nFile), true) ?: []) : [];
        $dead = $failed >= $tried;
        $miss = $dead ? (int)($prev['miss'] ?? 0) + 1 : 0;   // Läufe ohne einen einzigen Treffer
        $seen = $dead ? (int)($prev['seen'] ?? 0) + $tried : 0; // dabei geprüfte Seiten
        $out = $miss >= 2 && $seen >= 12;
        json_write($nFile, ['ts' => time(), 'ok' => !$out, 'miss' => $miss, 'seen' => $seen,
            'err' => $out ? $lastErr : '']);
    }
    cache_write($file, $data);
    flock($lock, LOCK_UN);
    fclose($lock);
}

/*
 * Die Seite liest nur den Cache und ist damit immer sofort da.
 * Aktualisiert wird über einen unsichtbaren Hintergrund-Ping (?cron=1),
 * den der Browser nach dem Laden abschickt.
 */
$cc = region();
if (!local_mode() && $cc === '') {
    // Start: Standort entscheidet – Länderwahl nur als Fallback
    $regions = [];
    $cent = [];
    foreach (region_list() as $code) {
        [$rClubs] = load_connectors($code);
        if ($rClubs) {
            $regions[$code] = count($rClubs);
            $la = $lo = 0;
            foreach ($rClubs as $rc) {
                $la += $rc['lat'];
                $lo += $rc['lng'];
            }
            $cent[$code] = [round($la / count($rClubs), 4), round($lo / count($rClubs), 4)];
        }
    }
    if (count($regions) === 1) {
        // nur eine Region: direkt hinein, die Karte nutzt den Standort selbst
        header('Location: ?c=' . array_key_first($regions) . '&near=1');
        exit;
    }
    http_response_code(200);
    ?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Nightclubmap</title>
<meta name="description" content="Alle Clubs auf einer Karte – Öffnungszeiten, Musik, Programm.">
<link rel="icon" href="?icon=svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="?icon=touch">
<meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffffff">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0c0c0c">
<style>
:root { --bg: #ffffff; --fg: #111111; --muted: #555555; --line: #d4d4d4; }
@media (prefers-color-scheme: dark) {
    :root { --bg: #0c0c0c; --fg: #f5f5f5; --muted: #b8b8b8; --line: #383838; }
}
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { height: 100%; margin: 0; }
body {
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg);
    color: var(--fg);
    font: 17px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    -webkit-font-smoothing: antialiased;
}
main { display: flex; flex-direction: column; align-items: center; gap: 26px; padding: 24px; }
h1 { margin: 0; font-size: 26px; letter-spacing: -0.02em; }
.flag {
    display: flex;
    align-items: center;
    gap: 16px;
    width: min(320px, 82vw);
    padding: 16px 20px;
    border: 1.5px solid var(--line);
    border-radius: 16px;
    color: var(--fg);
    text-decoration: none;
    font-weight: 650;
    font-size: 19px;
}
.flag:active { background: var(--line); }
.flag img { width: 44px; height: 44px; border-radius: 8px; display: block; }
.flag span { flex: 1; }
.flag small { color: var(--muted); font-weight: 400; font-size: 14px; }
.none { color: var(--muted); }
</style>
</head>
<body>
<main>
    <h1>Nightclubmap</h1>
<?php if (!$regions): ?>
    <p class="none">Bald.</p>
<?php endif; ?>
    <p class="none" id="locmsg" hidden>Standort wird verwendet …</p>
<?php foreach ($regions as $code => $n): ?>
    <a class="flag" href="?c=<?= htmlspecialchars($code) ?>">
        <img src="<?= htmlspecialchars(FLAG_DIR . '/' . $code . '.ico') ?>" alt="" onerror="this.style.display='none'">
        <span><?= htmlspecialchars(REGION_NAMES[$code] ?? strtoupper($code)) ?></span>
        <small><?= $n ?> Clubs</small>
    </a>
<?php endforeach; ?>
    <p class="none" style="font-size:12.5px">Alle Angaben ohne Gewähr.</p>
</main>
<script>
// Standort entscheidet, welches Land geöffnet wird; die Flaggen bleiben Fallback
const CENT = <?= json_encode($cent, JSON_HEX_TAG) ?>;
if (navigator.geolocation && Object.keys(CENT).length > 1) {
    const msg = document.getElementById('locmsg');
    msg.hidden = false;
    navigator.geolocation.getCurrentPosition(p => {
        let best = null, bd = Infinity;
        for (const k in CENT) {
            const dla = CENT[k][0] - p.coords.latitude;
            const dlo = (CENT[k][1] - p.coords.longitude) * Math.cos(p.coords.latitude * Math.PI / 180);
            const d = dla * dla + dlo * dlo;
            if (d < bd) { bd = d; best = k; }
        }
        if (best) location.href = '?c=' + best + '&near=1';
        else msg.hidden = true;
    }, () => { msg.hidden = true; }, { timeout: 6000, maximumAge: 300000 });
}
</script>
</body>
</html>
    <?php
    exit;
}
[$clubs, $connectors] = load_connectors($cc);
if (isset($_GET['refresh'])) {
    // manuelle Wartung: alle Connectoren synchron neu holen
    scrape_refresh(cache_read($cc)['data'] ?? [], $connectors, 0, $cc);
}
$cache = cache_read($cc);
// interne Felder (ft/etag/lm/h/ck) nicht an den Browser ausliefern
$live = array_map(
    fn($e) => array_intersect_key((array)$e, ['events' => 1, 'images' => 1, 'info' => 1, 'warn' => 1]),
    $cache['data'] ?? []
);
$oldestFt = PHP_INT_MAX;
foreach ($connectors as $cid => $c) {
    $oldestFt = min($oldestFt, (int)($cache['data'][$cid]['ft'] ?? 0));
}
$needsCron = $connectors && $oldestFt < time() - CACHE_TTL;
$cities = array_values(array_unique(array_map(fn($c) => $c['city'], $clubs)));
$pageTitle = count($cities) === 1 ? 'Clubs ' . $cities[0] : 'Clubs';
$payload = json_encode(
    ['clubs' => $clubs, 'live' => $live, 'cron' => $needsCron, 'cc' => $cc],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG
);
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="description" content="Alle Clubs auf einer Karte – Öffnungszeiten, Musik, Programm.">
<link rel="icon" href="?icon=svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="?icon=touch">
<link rel="manifest" href="?manifest=1<?= $cc !== '' ? '&amp;c=' . htmlspecialchars($cc) : '' ?>">
<meta name="apple-mobile-web-app-title" content="Clubs">
<meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffffff">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0c0c0c">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""
      media="print" onload="this.media='all'">
<style>
:root {
    --bg: #ffffff;
    --fg: #111111;
    --muted: #555555;
    --line: #d4d4d4;
    --card: #f4f4f4;
    --inv-bg: #111111;
    --inv-fg: #ffffff;
    --ok: #178c49;
    --bad: #cf3434;
    --warnc: #a86400;
}
@media (prefers-color-scheme: dark) {
    :root {
        --bg: #0c0c0c;
        --fg: #f5f5f5;
        --muted: #b8b8b8;
        --line: #383838;
        --card: #1d1d1d;
        --inv-bg: #f5f5f5;
        --inv-fg: #0c0c0c;
        --ok: #3ecf78;
        --bad: #ff5f5f;
        --warnc: #ffb020;
    }
}
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; overflow: hidden; }
[hidden] { display: none !important; } /* hidden schlägt jedes display der Elemente */
:focus-visible { outline: 2px solid var(--fg); outline-offset: 2px; }
@media (prefers-reduced-motion: reduce) {
    #sheet, #veil, .pin { transition: none; }
}
body {
    background: var(--bg);
    color: var(--fg);
    font: 16px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    -webkit-font-smoothing: antialiased;
    overflow: hidden;
}
#map {
    position: fixed;
    inset: 0;
    background: var(--card);
    z-index: 0;
}
.maperr { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: var(--muted); }
.leaflet-container { font: inherit; }
.leaflet-tile { filter: saturate(0) brightness(0.95) contrast(1.28); }
@media (prefers-color-scheme: dark) {
    /* Straßen und Labels deutlich anheben, Hintergrund bleibt schwarz */
    .leaflet-tile { filter: saturate(0) brightness(2) contrast(1.6); }
    .leaflet-control-attribution { background: rgba(12,12,12,.7) !important; color: var(--muted) !important; }
    .leaflet-control-attribution a { color: var(--muted) !important; }
}
/* Pins: Farbe = Status. Grün voll = offen, grüner Ring = öffnet noch, rot = zu, grau = nur Events */
.pinbox { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; }
.pin {
    width: 15px; height: 15px;
    border-radius: 50%;
    background: var(--bg);
    border: 3px solid var(--fg);
    box-shadow: 0 1px 6px rgba(0,0,0,.65);
}
.pin.open { background: var(--ok); border-color: var(--ok); box-shadow: 0 0 0 2px var(--bg), 0 1px 6px rgba(0,0,0,.65); }
.pin.soon { border-color: var(--ok); }
.pin.closed { border-color: var(--bad); }
.pin.off { border-color: var(--muted); }
.pin.active { transform: scale(1.55); }
/* Club-Name am Punkt, sobald man nah genug dran ist (wie in Karten-Apps) */
.plabel {
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%);
    margin-top: 1px;
    font-size: 11.5px;
    font-weight: 650;
    color: var(--fg);
    white-space: nowrap;
    text-shadow: 0 0 3px var(--bg), 0 0 3px var(--bg), 0 0 4px var(--bg), 0 1px 2px var(--bg);
    pointer-events: none;
    display: none;
}
#map.zl .plabel { display: block; }
.youdot {
    width: 16px; height: 16px;
    border-radius: 50%;
    background: #2a7fff;
    border: 3px solid #ffffff;
    box-shadow: 0 0 0 5px rgba(42,127,255,.25), 0 1px 6px rgba(0,0,0,.5);
}
.st-open, .st-soon { color: var(--ok); }
.st-closed { color: var(--bad); }
.st-off { color: var(--muted); }
/* Hover-Kurzinfo auf den Punkten (nur mit Maus) */
.leaflet-tooltip.tip {
    background: var(--bg);
    color: var(--fg);
    border: 1px solid var(--line);
    border-radius: 14px;
    box-shadow: 0 10px 28px rgba(0,0,0,.3);
    padding: 12px 15px;
    max-width: min(320px, calc(100vw - 40px));
    white-space: normal;
    font: inherit;
    font-size: 15px;
    line-height: 1.4;
    pointer-events: auto;
}
.leaflet-tooltip-top.tip::before { border-top-color: var(--line); }
.tip .tname { font-weight: 700; font-size: 16.5px; }
.tip .tst { margin: 3px 0; }
.tip .tg { color: var(--muted); font-size: 13.5px; }
/* Status-Punkt in Auswahl-Listen (Kurzinfo und Mini-Karte) */
.gdot { flex: none; width: 11px; height: 11px; border-radius: 50%; background: var(--bg); border: 2.5px solid var(--fg); }
.gdot.open { background: var(--ok); border-color: var(--ok); }
.gdot.soon { border-color: var(--ok); }
.gdot.closed { border-color: var(--bad); }
.gdot.off { border-color: var(--muted); }
.tip .ti {
    color: var(--muted);
    font-size: 13.5px;
    margin-top: 6px;
    display: -webkit-box;
    -webkit-line-clamp: 4;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
/* Mehrere Clubs am selben Punkt: Auswahl-Liste in der Kurzinfo */
.tip .ghead { color: var(--muted); font-size: 13px; margin-bottom: 2px; }
.tip .grow {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 10px 2px;
    border: 0;
    border-top: 1px solid var(--line);
    background: none;
    color: var(--fg);
    font: inherit;
    font-size: 14.5px;
    text-align: left;
    cursor: pointer;
}
.tip .grow:active { background: var(--card); }
.tip .gname { flex: 1; min-width: 0; font-weight: 650; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tip .gst { flex: none; color: var(--muted); font-size: 13px; }
/* Kompakte Club-Karte am unteren Rand (Tipp auf einen Punkt, Apple-Maps-Stil) */
#mini {
    position: fixed;
    left: 10px;
    right: 10px;
    bottom: calc(12px + env(safe-area-inset-bottom));
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 18px;
    box-shadow: 0 12px 32px rgba(0,0,0,.32);
    padding: 5px;
    max-height: 46vh;
    overflow-y: auto;
    z-index: 15;
    animation: miniup .18s ease-out;
}
@keyframes miniup { from { transform: translateY(14px); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { #mini { animation: none; } }
#mini .mhead { color: var(--muted); font-size: 13px; padding: 9px 13px 3px; }
.mcard, .mrow {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 12px;
    border: 0;
    border-radius: 14px;
    background: none;
    color: var(--fg);
    font: inherit;
    text-align: left;
    cursor: pointer;
}
.mcard:active, .mrow:active { background: var(--card); }
.mtxt { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.mname { font-weight: 700; font-size: 17px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mst { font-size: 14.5px; }
.msub { color: var(--muted); font-size: 13.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.minfo {
    color: var(--muted);
    font-size: 13.5px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    white-space: normal;
}
.mpic { flex: none; width: 58px; height: 58px; border-radius: 12px; object-fit: cover; background: var(--card); }
.mchev { flex: none; color: var(--muted); font-size: 24px; line-height: 1; padding-right: 2px; }
.mrow .mname { font-weight: 650; font-size: 16px; flex: 1; }
.mrow .msub { flex: none; }
@media (min-width: 700px) {
    #mini { left: 14px; right: auto; width: 400px; bottom: 14px; }
}
/* Schwebende Such-Karte */
.bar {
    position: fixed;
    top: calc(10px + env(safe-area-inset-top));
    left: calc(10px + env(safe-area-inset-left));
    right: calc(10px + env(safe-area-inset-right));
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 10px;
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 16px;
    box-shadow: 0 10px 30px rgba(0,0,0,.28);
    max-height: calc(100vh - 24px);
    max-height: calc(100dvh - 24px);
    overflow-y: auto;
    z-index: 5;
}
.qrow { display: flex; gap: 8px; }
#q {
    flex: 1;
    min-width: 0;
    padding: 10px 14px;
    border: 1.5px solid var(--line);
    border-radius: 12px;
    background: var(--bg);
    color: var(--fg);
    font-size: 16px;
}
#q::placeholder { color: var(--muted); }
.icon-btn {
    flex: none;
    width: 44px;
    border: 1.5px solid var(--line);
    border-radius: 12px;
    background: var(--bg);
    color: var(--fg);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}
.icon-btn.busy svg { animation: locpulse 1s ease-in-out infinite; }
@keyframes locpulse { 50% { opacity: .25; } }
.icon-btn.on { border-color: var(--fg); }
.icon-btn img { width: 24px; height: 24px; border-radius: 5px; display: block; }
/* Suchvorschläge */
#sugg { border-top: 1px solid var(--line); padding-top: 4px; max-height: 316px; overflow-y: auto; }
.sg {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 11px 8px;
    border: 0;
    border-radius: 10px;
    background: none;
    color: var(--fg);
    font: inherit;
    text-align: left;
    cursor: pointer;
}
.sg:active { background: var(--card); }
.sg .k { flex: none; color: var(--muted); font-size: 13px; min-width: 38px; }
.sg.near .k { min-width: 0; display: flex; }
.sg .n { flex: 1; min-width: 0; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sg .d { flex: none; color: var(--muted); font-size: 13.5px; white-space: nowrap; }
.sg.on { background: var(--card); }
.sg-note { color: var(--muted); font-size: 14.5px; padding: 10px 8px; }
.chips {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
}
.chips::-webkit-scrollbar { display: none; }
.chip {
    flex: 0 0 auto;
    padding: 9px 15px;
    border: 1.5px solid var(--line);
    border-radius: 999px;
    background: var(--bg);
    color: var(--fg);
    font-size: 15px;
    cursor: pointer;
    user-select: none;
}
.chip.on { background: var(--inv-bg); color: var(--inv-fg); border-color: var(--inv-bg); }
.chip.dim { opacity: .45; }
.chip svg { display: block; }
#zero {
    display: flex;
    align-items: center;
    gap: 10px;
    border-top: 1px solid var(--line);
    padding-top: 10px;
    color: var(--muted);
    font-size: 14.5px;
}
/* Mini-Legende: erklärt die Punktfarben und zählt live mit */
#legend {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 14px;
    padding: 0 4px;
    color: var(--muted);
    font-size: 13px;
}
#legend .ld { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
#legend .dot { width: 9px; height: 9px; border-radius: 50%; background: var(--bg); border: 2px solid var(--fg); }
#legend .dot.open { background: var(--ok); border-color: var(--ok); }
#legend .dot.soon { border-color: var(--ok); }
#legend .dot.closed { border-color: var(--bad); }
#legend .dot.off { border-color: var(--muted); }
#legend .dot.pulse { border-color: var(--muted); animation: locpulse 1s ease-in-out infinite; }
#locnote {
    display: flex;
    align-items: center;
    gap: 10px;
    border-top: 1px solid var(--line);
    padding-top: 10px;
    color: var(--warnc);
    font-size: 13.5px;
    line-height: 1.4;
}
#locnote button { flex: none; }
/* Filter-Panels klappen in der Karte auf, keine schwebenden Ebenen */
#dd {
    border-top: 1px solid var(--line);
    padding-top: 12px;
    max-height: 42vh;   /* Karte muss sichtbar bleiben */
    overflow-y: auto;
}
.dd-h {
    font-size: 12.5px;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--muted);
    margin: 12px 0 8px;
}
.dd-h:first-child { margin-top: 0; }
.dd-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.dd-q { font-size: 16px; font-weight: 650; margin-bottom: 12px; }
.dd-choice {
    display: block;
    width: 100%;
    text-align: left;
    padding: 13px 14px;
    margin-bottom: 8px;
    border: 1.5px solid var(--line);
    border-radius: 12px;
    background: none;
    color: var(--fg);
    font: inherit;
    font-size: 15px;
    cursor: pointer;
}
.dd-choice b { font-weight: 750; }
.dd-cancel {
    border: 0;
    background: none;
    color: var(--muted);
    font: inherit;
    font-size: 14px;
    cursor: pointer;
    padding: 6px 0;
}
.seg {
    display: inline-flex;
    border: 1.5px solid var(--line);
    border-radius: 999px;
    overflow: hidden;
}
.seg button {
    padding: 8px 18px;
    background: none;
    border: 0;
    color: var(--muted);
    font: inherit;
    font-size: 14px;
    cursor: pointer;
}
.seg button.on { background: var(--inv-bg); color: var(--inv-fg); }
.seg button.dim { opacity: .4; cursor: default; }
.icobtn {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    overflow: hidden;
}
.icobtn input {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
    border: 0;
}
.icobtn input::-webkit-calendar-picker-indicator {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
}
.icobtn input:focus-visible {
    opacity: 1;
    background: var(--card);
    color: var(--fg);
    font: inherit;
    text-align: center;
}
.trash {
    border: 0;
    background: none;
    color: var(--muted);
    padding: 9px 6px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
}
/* Bottom-Sheet */
#veil {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.45);
    opacity: 0;
    pointer-events: none;
    transition: opacity .18s;
    z-index: 20;
}
#veil.show { opacity: 1; pointer-events: auto; }
#sheet {
    position: fixed;
    left: 0; right: 0; bottom: 0;
    max-height: 82vh;
    max-height: 82dvh;
    display: flex;
    flex-direction: column;
    background: var(--bg);
    border-radius: 18px 18px 0 0;
    border-top: 1px solid var(--line);
    padding-top: 10px;
    transform: translateY(105%);
    visibility: hidden;
    transition: transform .22s ease-out, visibility 0s linear .22s;
    z-index: 21;
    outline: none;
}
#sheet.show { transform: none; visibility: visible; transition: transform .22s ease-out; }
#sheet.drag { transition: none; }
#sheet .shead { flex: none; position: relative; padding: 0 20px; touch-action: none; }
#sheet .sbody { overflow-y: auto; padding: 0 20px calc(20px + env(safe-area-inset-bottom)); }
#sheet .grip { width: 40px; height: 4px; border-radius: 2px; background: var(--line); margin: 2px auto 14px; }
#sheet h2 { margin: 0 0 2px; font-size: 24px; letter-spacing: -0.02em; }
#sheet .status { font-size: 16px; margin-bottom: 10px; }
#sheet .status.st-open { font-weight: 700; }
#sheet .tags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
#sheet .tag {
    font-size: 13.5px;
    padding: 4px 10px;
    border: 1px solid var(--line);
    border-radius: 999px;
    background: var(--card);
    color: var(--muted);
    font-family: inherit;
    cursor: pointer;
}
#sheet .tag::after { content: '\203A'; margin-left: 6px; opacity: .55; }
#sheet .tag:active { background: var(--line); color: var(--fg); }
#sheet section { margin-bottom: 16px; }
#sheet h3 { margin: 0 0 6px; font-size: 13px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); }
.prog-head { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
.prog-head h3 { margin: 0; flex: 1; text-align: center; }
.pnav {
    width: 34px; height: 34px;
    border: 1.5px solid var(--line);
    border-radius: 50%;
    background: none;
    color: var(--fg);
    font: inherit;
    font-size: 17px;
    cursor: pointer;
}
.pnav.dim { opacity: .4; }
.ev-title { font-weight: 650; padding: 3px 0; }
.ev-img { width: 100%; max-height: 150px; object-fit: cover; border-radius: 10px; margin: 4px 0; background: var(--card); }
.muted-p { color: var(--muted); }
#sheet details.hrs { margin: 2px 0 16px; }
#sheet summary {
    cursor: pointer;
    color: var(--fg);
    font-size: 15px;
    font-weight: 650;
    padding: 6px 0;
}
#sheet details div { display: flex; gap: 12px; padding: 2px 0; }
#sheet details span { flex: 0 0 auto; min-width: 58px; color: var(--muted); }
#sheet details .today { font-weight: 700; }
#sheet details .today span { color: inherit; }
#sheet details .note { color: var(--muted); font-size: 14px; margin-top: 6px; display: block; }
.actions { display: flex; gap: 10px; margin-top: 4px; }
.actions a {
    flex: 1;
    text-align: center;
    padding: 13px;
    border-radius: 12px;
    font-weight: 650;
    text-decoration: none;
    border: 1.5px solid var(--fg);
    color: var(--fg);
}
.actions a.primary { background: var(--inv-bg); color: var(--inv-fg); border-color: var(--inv-bg); }
#close {
    position: absolute;
    top: 12px; right: 12px;
    width: 34px; height: 34px;
    border-radius: 50%;
    border: 0;
    background: var(--card);
    color: var(--fg);
    font-size: 18px;
    cursor: pointer;
}
/* Bilder im Club-Detail */
.pics { display: flex; gap: 8px; overflow-x: auto; margin-bottom: 14px; -webkit-overflow-scrolling: touch; }
.pics img {
    flex: 0 0 auto;
    height: 110px;
    max-width: 250px;
    border-radius: 10px;
    object-fit: cover;
    background: var(--card);
    cursor: zoom-in;
}
.ev-img { cursor: zoom-in; }
/* Bild-Vollansicht: Tipp aufs Foto öffnet, Tipp irgendwo schließt */
#lb {
    position: fixed;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 14px;
    background: rgba(0, 0, 0, .93);
    z-index: 40;
    cursor: zoom-out;
}
#lb img { max-width: 100%; max-height: 100%; border-radius: 10px; }
#lb .lbx {
    position: absolute;
    top: calc(env(safe-area-inset-top, 0px) + 10px);
    right: 12px;
    width: 38px;
    height: 38px;
    border: 0;
    border-radius: 999px;
    background: rgba(255, 255, 255, .18);
    color: #fff;
    font-size: 24px;
    line-height: 1;
    cursor: pointer;
}
.infotext { margin: 0; color: var(--fg); font-size: 15px; }
/* Beschreibungstext des Clubs in der Kachel */
#sheet .about { margin: 0 0 16px; color: var(--muted); font-size: 14.5px; line-height: 1.5; }
/* Sonderschließungs-Hinweis von der Club-Website */
.warnline {
    margin: 0 0 14px;
    padding: 10px 12px;
    border: 1px solid var(--warnc);
    border-radius: 12px;
    color: var(--warnc);
    font-size: 14px;
    line-height: 1.45;
}
#mini .warnline { margin: 2px 12px 8px; font-size: 13px; padding: 8px 10px; }
.nog { margin: 12px 0 0; color: var(--muted); font-size: 12.5px; text-align: center; }
@media (min-width: 700px) {
    .bar { right: auto; width: 390px; top: 14px; left: 14px; }
    #sheet {
        left: 14px; right: auto; bottom: 14px;
        width: 400px;
        max-height: 76vh;
        border-radius: 18px;
        border: 1px solid var(--line);
    }
    #sheet .grip { display: none; }
    #veil { background: rgba(0, 0, 0, .15); }
}
</style>
</head>
<body>
<noscript>
<style>#map, .bar, #veil, #sheet { display: none } html, body { overflow: auto }</style>
<p style="padding:16px 16px 0">Bitte JavaScript aktivieren.</p>
<ul style="padding:16px 16px 16px 32px">
<?php foreach ($clubs as $c): ?>
    <li><strong><?= htmlspecialchars($c['name']) ?></strong> – <?= htmlspecialchars($c['addr'] . ', ' . $c['city']) ?><?= empty($c['url']) ? '' : ' – <a href="' . htmlspecialchars($c['url']) . '">Website</a>' ?></li>
<?php endforeach; ?>
</ul>
</noscript>
<div id="map"></div>
<div class="bar">
    <div class="qrow">
<?php if ($cc !== '' && count(region_list()) > 1): // Länderwahl nur anbieten, wenn es etwas zu wählen gibt ?>
        <a class="icon-btn" href="./" aria-label="Land wechseln" title="Land wechseln"><img src="<?= htmlspecialchars(FLAG_DIR . '/' . $cc . '.ico') ?>" alt="<?= htmlspecialchars(REGION_NAMES[$cc] ?? strtoupper($cc)) ?>" onerror="this.remove()"></a>
<?php endif; ?>
        <input id="q" type="search" placeholder="Club oder Ort …" autocomplete="off">
        <button id="loc" class="icon-btn" aria-label="Meinen Standort verwenden"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="6.5"/><path d="M12 1.5V5M12 19v3.5M1.5 12H5M19 12h3.5"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg></button>
    </div>
    <div id="sugg" hidden></div>
    <div class="chips" id="chips"></div>
    <div id="dd" hidden></div>
    <div id="legend" hidden></div>
    <div id="locnote" hidden></div>
    <div id="zero" hidden></div>
</div>
<div id="mini" hidden></div>
<div id="veil"></div>
<div id="sheet" role="dialog" aria-modal="true" tabindex="-1" inert></div>
<div id="lb" hidden></div>
<script>
const DATA = <?= $payload ?>;
const DAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
const MULTI_CITY = new Set(DATA.clubs.map(c => c.city)).size > 1;
const DAYNUM = { Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6, Sun: 7 };
const HOVER = matchMedia('(hover: hover)').matches;

function now() { return new Date(); }

/* Uhrzeit immer in München bewerten, egal wo der Besucher gerade ist */
const BERLIN = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Europe/Berlin', weekday: 'short', year: 'numeric', month: '2-digit',
    day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23'
});
function partsFor(date) {
    const p = Object.fromEntries(BERLIN.formatToParts(date).map(x => [x.type, x.value]));
    return {
        day: DAYNUM[p.weekday],
        min: (Number(p.hour) % 24) * 60 + Number(p.minute),
        iso: p.year + '-' + p.month + '-' + p.day
    };
}
function nowParts() { return partsFor(now()); }
/* Tagesgrenze ist Mittag: bis 12:00 zählt die laufende Nacht noch zum Vortag */
function nightIso() { return partsFor(new Date(now().getTime() - 12 * 3600e3)).iso; }
function addDays(iso, i) {
    return new Date(Date.parse(iso + 'T12:00:00Z') + i * 86400e3).toISOString().slice(0, 10);
}
function toMin(t) { const [h, m] = t.split(':').map(Number); return h * 60 + m; }

/* Status eines Clubs zum aktuellen Zeitpunkt */
function status(club) {
    const { day, min, iso } = nowParts();
    if (club.pause && iso < club.pause) {
        return { open: false, short: 'Pause', long: 'Pause bis ' + fmtDate(club.pause) };
    }
    const prev = day === 1 ? 7 : day - 1;
    for (const [days, o, c] of club.hours || []) {
        const om = toMin(o), cm = toMin(c);
        const openNow = (days.includes(day) && (cm > om ? (min >= om && min < cm) : min >= om))
            || (cm < om && days.includes(prev) && min < cm);
        if (openNow) {
            return { open: true, short: 'bis ' + c, long: 'Offen · bis ' + c };
        }
    }
    for (const [days, o] of club.hours || []) {
        if (days.includes(day) && min < toMin(o)) {
            return { open: false, tonight: true, short: 'ab ' + o, long: 'Öffnet heute · ' + o };
        }
    }
    const ev = todayEvent(club);
    if (ev) {
        return { open: false, tonight: true, short: 'heute Event', long: 'Heute: ' + ev.title };
    }
    if (!(club.hours || []).length) {
        const next = nextEvent(club);
        return { open: false, short: 'Events', long: next ? 'Nur bei Events · nächstes ' + fmtDate(next.date) : 'Nur bei Events' };
    }
    const d = [...new Set(club.hours.flatMap(h => h[0]))].sort();
    const lbl = d.map(x => DAYS[x - 1]).join(' + ');
    return { open: false, short: lbl, long: 'Heute zu · ' + lbl };
}
function events(club) {
    const night = nightIso();
    return ((DATA.live[club.id] || {}).events || []).filter(e => e.date >= night);
}
function todayEvent(club) {
    const iso = nowParts().iso, night = nightIso();
    return events(club).find(e => e.date === iso || e.date === night);
}
function nextEvent(club) { return events(club)[0]; }
function fmtDate(iso) {
    const d = new Date(iso + 'T12:00');
    return DAYS[(d.getDay() + 6) % 7] + ' ' + d.getDate() + '.' + (d.getMonth() + 1) + '.';
}

/* Ampel-Einordnung eines Status für Pin- und Textfarben */
function kindOf(s) {
    if (s.open) return 'open';
    if (s.tonight) return 'soon';
    return s.short === 'Events' || s.short === 'Pause' ? 'off' : 'closed';
}

/* ---- Entfernung (nach Standort-Freigabe) ---- */
let userPos = null;
function distKm(p, c) {
    const r = x => x * Math.PI / 180;
    const a = Math.sin(r(c.lat - p.lat) / 2) ** 2
        + Math.cos(r(p.lat)) * Math.cos(r(c.lat)) * Math.sin(r(c.lng - p.lng) / 2) ** 2;
    return 12742 * Math.asin(Math.sqrt(a));
}
function fmtKm(km) {
    if (km < 1) return Math.round(km * 100) * 10 + ' m';
    return (km < 10 ? km.toFixed(1).replace('.', ',') : String(Math.round(km))) + ' km';
}
function byDist(a, b) {
    return distKm(userPos, a) - distKm(userPos, b) || a.name.localeCompare(b.name, 'de');
}

/* Route in der Standard-Karten-App des Geräts öffnen */
function appRoute(c) {
    const ua = navigator.userAgent;
    if (/iPhone|iPad|iPod/.test(ua)) {
        return { label: 'Apple Karten', href: 'https://maps.apple.com/?daddr=' + c.lat + ',' + c.lng };
    }
    if (/Android/.test(ua)) {
        return { label: 'Karten-App', href: 'geo:' + c.lat + ',' + c.lng + '?q=' + c.lat + ',' + c.lng + '(' + encodeURIComponent(c.name) + ')' };
    }
    return { label: 'Google Maps', href: 'https://www.google.com/maps/dir/?api=1&destination=' + c.lat + ',' + c.lng };
}

/* ?search=, &club= und &date= in der Adresszeile spiegeln */
function syncUrl() {
    const p = new URLSearchParams(location.search);
    const q = $('q').value.trim();
    if (q) p.set('search', q); else p.delete('search');
    if (activeId) p.set('club', activeId); else p.delete('club');
    if (state.date === null) p.set('date', 'alle');
    else if (state.date === nightIso()) p.delete('date');
    else p.set('date', state.date);
    p.delete('time');
    p.delete('near'); // einmalige Start-Weiche, gehört nicht in geteilte Links
    const qs = p.toString();
    history.replaceState(null, '', qs ? '?' + qs : location.pathname);
}

/* ---- Filter ---- */
/* Musikrichtungen dynamisch aus allen Connectoren */
const GENRES = [...new Set(DATA.clubs.flatMap(c => c.genres))].sort((a, b) => a.localeCompare(b, 'de'));
/*
 * date: Start ist die aktuelle Partynacht (Mittag-zu-Mittag-Grenze),
 *       null = alle Tage. Echte Daten auch für heute/morgen, damit
 *       geteilte Links über Tagesgrenzen stabil bleiben.
 * music: gewählte Stile, verknüpft mit mode 'and'|'or'.
 */
const state = { q: '', date: nightIso(), music: { mode: 'or', genres: [] } };

function musicMatch(c) {
    const g = state.music.genres;
    if (!g.length) return true;
    const hit = k => c.genres.includes(k);
    return state.music.mode === 'and' ? g.every(hit) : g.some(hit);
}

/* „muenchen“, „München“, „MUNCHEN“ sollen dasselbe finden */
function fold(s) {
    return (s || '').toLowerCase()
        .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}
function searchMatch(c) {
    if (!state.q) return true;
    c._hay = c._hay || fold(c.name + ' ' + c.genres.join(' ') + ' ' + c.city + ' ' + (c.state || '') + ' ' + c.addr);
    return c._hay.includes(fold(state.q));
}

/* Status bezogen auf einen gewählten Kalendertag statt auf jetzt */
function statusOn(club, iso) {
    if (club.pause && iso < club.pause) {
        return { open: false, short: 'Pause', long: 'Pause bis ' + fmtDate(club.pause) };
    }
    const day = (new Date(iso + 'T12:00').getDay() + 6) % 7 + 1;
    for (const [days, o, c] of club.hours || []) {
        if (days.includes(day)) {
            const t = x => x.replace(':00', '');
            return { open: true, short: t(o) + '–' + t(c), long: fmtDate(iso) + ' · ' + o + ' – ' + c };
        }
    }
    const ev = ((DATA.live[club.id] || {}).events || []).find(e => e.date === iso);
    if (ev) {
        return { open: true, short: 'Event', long: fmtDate(iso) + ': ' + ev.title };
    }
    if (!(club.hours || []).length) {
        return { open: false, short: 'Events', long: 'Kein Termin ' + fmtDate(iso) };
    }
    const d = [...new Set(club.hours.flatMap(h => h[0]))].sort();
    const lbl = d.map(x => DAYS[x - 1]).join(' + ');
    return { open: false, short: lbl, long: fmtDate(iso) + ' zu · ' + lbl };
}

function dayOfIso(iso) { return (new Date(iso + 'T12:00').getDay() + 6) % 7 + 1; }

/* Für die laufende Nacht zählt der Live-Status, sonst der gewählte Tag.
   Ergebnis wird je Durchgang gemerkt – sonst rechnet die Seite den Status
   pro Club mehrfach aus, was bei vielen Clubs spürbar bremst. */
let statusCache = Object.create(null);
function freshStatus() { statusCache = Object.create(null); }
function refStatus(c) {
    const hit = statusCache[c.id];
    if (hit) return hit;
    return statusCache[c.id] =
        (state.date === null || state.date === nightIso()) ? status(c) : statusOn(c, state.date);
}

function dayFit(c) {
    if (state.date === null) return true;
    const s = refStatus(c);
    return s.open || !!s.tonight;
}

function filtered() {
    return DATA.clubs.filter(c => dayFit(c) && musicMatch(c) && searchMatch(c));
}

/* Wie viele Clubs blieben übrig, wenn der Tag egal wäre? */
function otherDays() {
    return state.date === null ? 0 : DATA.clubs.filter(c => musicMatch(c) && searchMatch(c)).length;
}
function allDaysBtn(after) {
    const b = el('button', 'chip', 'Alle Tage');
    b.onclick = () => { state.date = null; syncUrl(); renderChips(); render(); if (after) after(); };
    return b;
}

/* ---- Karte ---- */
let map, markers = {}, baseBounds = null, youMarker = null;

/* Clubs mit fast identischer Position leicht auseinanderziehen */
const POS = (() => {
    const groups = {};
    for (const c of DATA.clubs) {
        const k = c.lat.toFixed(4) + ',' + c.lng.toFixed(4);
        (groups[k] = groups[k] || []).push(c);
    }
    const pos = {};
    for (const g of Object.values(groups)) {
        if (g.length === 1) { pos[g[0].id] = [g[0].lat, g[0].lng]; continue; }
        g.forEach((c, i) => {
            const a = 2 * Math.PI * i / g.length;
            pos[c.id] = [c.lat + 0.00022 * Math.cos(a), c.lng + 0.00033 * Math.sin(a)];
        });
    }
    return pos;
})();
function tileUrl(dark) {
    return 'https://{s}.basemaps.cartocdn.com/' + (dark ? 'dark_all' : 'light_all') + '/{z}/{x}/{y}{r}.png';
}
function mapFailed() {
    $('map').textContent = '';
    $('map').appendChild(el('p', 'maperr', 'Karte konnte nicht geladen werden – Suche funktioniert trotzdem.'));
}
function initMap() {
    if (typeof L === 'undefined') { mapFailed(); return; }
    const mq = matchMedia('(prefers-color-scheme: dark)');
    map = L.map('map', { zoomControl: false, maxBoundsViscosity: 1.0 }).setView([48.1372, 11.5755], 12);
    const tiles = L.tileLayer(tileUrl(mq.matches), {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a> · Ohne Gewähr'
    }).addTo(map);
    mq.addEventListener('change', e => tiles.setUrl(tileUrl(e.matches)));
    // Namen erst zeigen, wenn man nah genug dran ist – sonst wird es unlesbar
    const setZl = () => $('map').classList.toggle('zl', map.getZoom() >= 15);
    map.on('zoomend', setZl);
    setZl();
    for (const c of DATA.clubs) {
        const m = L.marker(POS[c.id], { icon: pinIcon(c), title: c.name }).addTo(map);
        // Hover-Vorschau nur mit Maus; am Telefon übernimmt die Mini-Karte unten
        if (HOVER) m.bindTooltip(() => tipEl(c), { direction: 'top', offset: [0, -10], className: 'tip', opacity: 1, interactive: true });
        m.on('click', () => tapPin(c));
        markers[c.id] = m;
    }
    map.on('click', closeTip); // Tipp neben die Punkte schließt die Kurzinfo
    // Rauszoomen nur so weit, dass alle Punkte sichtbar bleiben
    if (DATA.clubs.length) {
        baseBounds = L.latLngBounds(DATA.clubs.map(c => POS[c.id]));
        map.fitBounds(baseBounds, { padding: [30, 30], maxZoom: 15 });
        fitArea();
        // Beim nächsten Mal dort weitermachen, wo man aufgehört hat –
        // sonst startet man jedes Mal über dem ganzen Land.
        const last = loadView();
        if (last && !activeId) {
            map.setView([last.la, last.lo], last.z);
        }
        map.on('moveend', saveView);
    }
    refreshPins(filtered());
    if (userPos) placeYou();
    if (activeId) {
        markers[activeId].setIcon(pinIcon(DATA.clubs.find(c => c.id === activeId), true));
        map.setView(POS[activeId], 15);
    }
}
const VIEWKEY = 'ncm.view.' + (DATA.cc || 'local');
function loadView() {
    try {
        const v = JSON.parse(localStorage.getItem(VIEWKEY) || 'null');
        return v && isFinite(v.la) && isFinite(v.lo) && isFinite(v.z) ? v : null;
    } catch (e) { return null; }
}
let viewTimer = null;
function saveView() {
    clearTimeout(viewTimer);
    viewTimer = setTimeout(() => {
        try {
            const c = map.getCenter();
            localStorage.setItem(VIEWKEY, JSON.stringify({ la: c.lat, lo: c.lng, z: map.getZoom() }));
        } catch (e) { /* privater Modus: dann eben nicht */ }
    }, 600);
}
function fitArea() {
    if (!map || !baseBounds) return;
    // extend() verändert das Original – deshalb auf einer Kopie arbeiten,
    // sonst wächst der erlaubte Bereich mit jedem Standort-Fix weiter
    const area = (userPos
        ? L.latLngBounds(baseBounds.getSouthWest(), baseBounds.getNorthEast()).extend([userPos.lat, userPos.lng])
        : baseBounds).pad(0.3);
    map.setMaxBounds(area);
    map.setMinZoom(map.getBoundsZoom(area));
}
function esc(s) {
    return s.replace(/[&<>"]/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]));
}
function pinIcon(c, active) {
    const cls = 'pin ' + kindOf(refStatus(c)) + (active ? ' active' : '');
    return L.divIcon({
        className: '',
        html: '<div class="pinbox"><div class="' + cls + '"></div><span class="plabel">' + esc(c.name) + '</span></div>',
        iconSize: [30, 30], iconAnchor: [15, 15]
    });
}
const pinState = {}; // zuletzt gezeichneter Zustand je Marker
function refreshPins(list) {
    if (!map) return;
    const ids = new Set(list.map(c => c.id));
    for (const c of DATA.clubs) {
        const m = markers[c.id];
        if (ids.has(c.id) || c.id === activeId) {
            // Icon nur neu bauen, wenn sich wirklich etwas geändert hat
            const key = kindOf(refStatus(c)) + (c.id === activeId ? '+' : '');
            if (pinState[c.id] !== key) {
                pinState[c.id] = key;
                m.setIcon(pinIcon(c, c.id === activeId));
            }
            if (!map.hasLayer(m)) m.addTo(map);
        } else if (map.hasLayer(m)) {
            if (tipId === c.id) closeTip();
            delete pinState[c.id];
            m.remove();
        }
    }
}
/*
 * Telefon-Navigation: erster Tipp auf einen Punkt zeigt die Kurzinfo,
 * Tipp auf die Kurzinfo (oder den Punkt erneut) öffnet die Club-Kachel.
 * Mit Maus gilt: Hover zeigt die Kurzinfo, Klick öffnet direkt.
 * Überdecken sich mehrere Punkte, zeigt die Kurzinfo eine Auswahl-Liste.
 */
let tipId = null;
function closeTip() {
    if (tipId && markers[tipId] && markers[tipId].closeTooltip) markers[tipId].closeTooltip();
    tipId = null;
    const mn = $('mini');
    if (mn) { mn.hidden = true; mn.textContent = ''; }
}
/* Angetippten/aktiven Punkt über seine Nachbarn heben */
let raisedId = null;
function raise(id) {
    if (raisedId === id) return;
    if (raisedId && markers[raisedId] && markers[raisedId].setZIndexOffset) {
        markers[raisedId].setZIndexOffset(0);
    }
    if (id && markers[id] && markers[id].setZIndexOffset) markers[id].setZIndexOffset(1000);
    raisedId = id;
}
/* Sichtbare Clubs, deren Punkte sich beim aktuellen Zoom mit c überdecken */
function nearPins(c) {
    if (!map || !map.latLngToContainerPoint) return [c];
    const p0 = map.latLngToContainerPoint(POS[c.id]);
    // grob nach Koordinate vorfiltern – projizieren ist teuer
    const box = 60 / Math.pow(2, map.getZoom());
    const rest = DATA.clubs.filter(x => {
        if (x.id === c.id) return false;
        if (Math.abs(x.lat - c.lat) > box || Math.abs(x.lng - c.lng) > box * 2) return false;
        const m = markers[x.id];
        if (!m || !map.hasLayer(m)) return false;
        const p = map.latLngToContainerPoint(POS[x.id]);
        return (p.x - p0.x) ** 2 + (p.y - p0.y) ** 2 < 26 * 26;
    }).sort((a, b) => a.name.localeCompare(b.name, 'de'));
    return [c, ...rest];
}
function tapPin(c) {
    const group = nearPins(c);
    if (HOVER) {
        // Maus: Hover zeigt die Vorschau; bei überdeckten Punkten erst wählen lassen
        if (group.length > 1 && tipId !== c.id) {
            closeTip();
            tipId = c.id;
            raise(c.id);
            markers[c.id].openTooltip();
            return;
        }
        closeTip();
        openSheet(c, false);
        return;
    }
    // Telefon: kompakte Karte am unteren Rand; zweiter Tipp öffnet die Kachel
    if (tipId === c.id && group.length === 1) {
        closeTip();
        openSheet(c, false);
        return;
    }
    closeTip();
    tipId = c.id;
    raise(c.id);
    showMini(group);
}
/* Kompakte Karte am unteren Rand (Apple-Maps-Stil) für Touch-Geräte */
function showMini(group) {
    const box = $('mini');
    box.textContent = '';
    if (group.length > 1) {
        box.appendChild(el('div', 'mhead', group.length + ' Clubs an diesem Punkt'));
        for (const x of group.slice(0, 5)) box.appendChild(miniRow(x));
        if (group.length > 5) {
            const more = el('button', 'mrow');
            more.append(el('span', 'mname', 'Näher zoomen'), el('span', 'msub', '+' + (group.length - 5) + ' weitere'));
            more.onclick = () => {
                const at = POS[group[0].id];
                closeTip();
                if (map) map.setView(at, Math.min(map.getZoom() + 2, 17), { animate: true });
            };
            box.appendChild(more);
        }
    } else {
        box.appendChild(miniCard(group[0]));
        const w = (DATA.live[group[0].id] || {}).warn;
        if (w) box.appendChild(el('p', 'warnline', 'Hinweis der Website: „' + w + '“'));
    }
    box.hidden = false;
}
function miniRow(x) {
    const s = refStatus(x);
    const row = el('button', 'mrow');
    row.append(el('span', 'gdot ' + kindOf(s)), el('span', 'mname', x.name), el('span', 'msub', s.short));
    row.onclick = () => { closeTip(); openSheet(x, false); };
    return row;
}
function miniCard(c) {
    const s = refStatus(c);
    const card = el('button', 'mcard');
    const txt = el('span', 'mtxt');
    txt.appendChild(el('span', 'mname', c.name));
    txt.appendChild(el('span', 'mst st-' + kindOf(s), statusLine(c, s)));
    const g = c.genres.join(' · ') + (MULTI_CITY ? (c.genres.length ? ' · ' : '') + c.city : '');
    if (g) txt.appendChild(el('span', 'msub', g));
    const info = (DATA.live[c.id] || {}).info;
    if (info) txt.appendChild(el('span', 'minfo', info));
    card.appendChild(txt);
    const pic = cleanImgs((DATA.live[c.id] || {}).images)[0];
    if (pic) {
        const im = el('img', 'mpic');
        im.src = pic;
        im.alt = '';
        im.referrerPolicy = 'no-referrer';
        im.onerror = () => im.remove();
        card.appendChild(im);
    }
    card.appendChild(el('span', 'mchev', '›'));
    card.onclick = () => { closeTip(); openSheet(c, false); };
    return card;
}
/* Kurzinfo beim Überfahren eines Punkts – hier wohnt auch der Info-Text des Clubs */
function tipEl(c) {
    const group = nearPins(c);
    if (group.length > 1) return groupEl(group);
    const s = refStatus(c);
    const d = el('div');
    d.onclick = () => { closeTip(); openSheet(c, false); };
    d.appendChild(el('div', 'tname', c.name));
    d.appendChild(el('div', 'tst st-' + kindOf(s), statusLine(c, s)));
    const g = c.genres.join(' · ') + (MULTI_CITY ? (c.genres.length ? ' · ' : '') + c.city : '');
    if (g) d.appendChild(el('div', 'tg', g));
    const info = (DATA.live[c.id] || {}).info;
    if (info) d.appendChild(el('div', 'ti', info));
    return d;
}
/* Auswahl-Liste, wenn mehrere Clubs am selben Punkt liegen */
function groupEl(group) {
    const d = el('div');
    d.appendChild(el('div', 'ghead', group.length + ' Clubs an diesem Punkt'));
    for (const x of group.slice(0, 6)) {
        const s = refStatus(x);
        const row = el('button', 'grow');
        row.append(el('span', 'gdot ' + kindOf(s)), el('span', 'gname', x.name), el('span', 'gst', s.short));
        row.onclick = ev => {
            ev.stopPropagation();
            closeTip();
            openSheet(x, false);
        };
        d.appendChild(row);
    }
    if (group.length > 6) {
        const more = el('button', 'grow');
        more.append(el('span', 'gname', 'Näher zoomen'), el('span', 'gst', '+' + (group.length - 6) + ' weitere'));
        more.onclick = ev => {
            ev.stopPropagation();
            const at = POS[group[0].id];
            closeTip();
            map.setView(at, Math.min(map.getZoom() + 2, 17), { animate: true });
        };
        d.appendChild(more);
    }
    return d;
}
function statusLine(c, s) {
    return s.long + (userPos ? ' · ' + fmtKm(distKm(userPos, c)) : '');
}
function placeYou(pan) {
    if (!map || !userPos) return;
    if (youMarker) youMarker.remove();
    youMarker = L.marker([userPos.lat, userPos.lng], {
        icon: L.divIcon({ className: '', html: '<div class="youdot"></div>', iconSize: [16, 16], iconAnchor: [8, 8] }),
        title: 'Mein Standort'
    }).addTo(map);
    fitArea();
    if (pan !== false) map.setView([userPos.lat, userPos.lng], Math.max(map.getZoom(), 13));
}

/* ---- Rendern ---- */
const $ = id => document.getElementById(id);
function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
}
let lastFp = '', liveBusy = false, liveSig = '';
function render() {
    freshStatus();
    const list = filtered();
    refreshPins(list);
    const z = $('zero');
    if (!list.length) {
        z.textContent = '';
        const other = otherDays();
        z.appendChild(el('span', '', other
            ? timeLabel() + ': nichts. ' + other + ' Treffer an anderen Tagen.'
            : 'Kein Club gefunden.'));
        if (other) z.appendChild(allDaysBtn());
        const b = el('button', 'chip', 'Zurücksetzen');
        b.onclick = resetFilters;
        z.appendChild(b);
        z.hidden = false;
    } else {
        z.hidden = true;
    }
    // Mini-Legende: was die Punktfarben gerade bedeuten, mit Live-Zählern
    const lg = $('legend');
    lg.textContent = '';
    const kinds = { open: 0, soon: 0, closed: 0, off: 0 };
    for (const c of list) kinds[kindOf(refStatus(c))]++;
    const KL = { open: 'offen', soon: 'öffnet noch', closed: 'zu', off: 'nur Events' };
    for (const k of ['open', 'soon', 'closed', 'off']) {
        if (!kinds[k]) continue;
        const it = el('span', 'ld');
        it.append(el('span', 'dot ' + k), document.createTextNode(kinds[k] + ' ' + KL[k]));
        lg.appendChild(it);
    }
    if (liveBusy) {
        const it = el('span', 'ld');
        it.append(el('span', 'dot pulse'), document.createTextNode('lädt …'));
        lg.appendChild(it);
    }
    lg.hidden = !lg.childNodes.length;
    if (activeId) {
        const c = DATA.clubs.find(x => x.id === activeId);
        const s = refStatus(c);
        const line = $('sheet').querySelector('.status');
        if (line) {
            line.textContent = statusLine(c, s);
            line.className = 'status st-' + kindOf(s);
        }
        // frisch gescrapte Bilder/Infos/Programme in die offene Kachel nachreichen
        const body = $('sheet').querySelector('.sbody');
        const alive = DATA.live[activeId] || {};
        const imgs = cleanImgs(alive.images).slice(0, 4);
        if (body && imgs.length && !body.querySelector('.pics')) {
            const tags = body.querySelector('.tags');
            if (tags) tags.after(buildPics(imgs));
        }
        if (body && alive.info && !body.querySelector('.about')) {
            const anchor = body.querySelector('.pics') || body.querySelector('.tags');
            if (anchor) anchor.after(el('p', 'about', alive.info));
        }
        if (progRefresh) progRefresh();
    }
    // offene Vorschlagsliste nur neu bauen, wenn gerade nicht getippt wird –
    // sonst springt sie dem Nutzer alle paar Sekunden unter den Fingern weg
    if (!$('sugg').hidden && document.activeElement !== $('q')) renderSugg();
}
function resetFilters() {
    clearTimeout(qTimer);
    state.q = '';
    state.date = nightIso();
    state.music = { mode: 'or', genres: [] };
    $('q').value = '';
    closeSugg();
    syncUrl();
    renderChips(); render();
}

/* ---- Suchvorschläge (Ort / Club / In meiner Nähe) ---- */
let suggMode = null;
function closeSugg() { $('sugg').hidden = true; suggMode = null; suggSig = ''; }
function sgRow(kind, label, right, rightCls, fn) {
    const b = el('button', 'sg');
    b.appendChild(el('span', 'k', kind));
    b.appendChild(el('span', 'n', label));
    if (right != null) b.appendChild(el('span', 'd' + (rightCls ? ' ' + rightCls : ''), right));
    b.onclick = fn;
    return b;
}
function nearRow(active) {
    const b = el('button', 'sg near' + (active ? ' on' : ''));
    const k = el('span', 'k');
    k.innerHTML = ICONS.loc;
    b.append(k, el('span', 'n', 'In meiner Nähe'));
    if (active) b.appendChild(el('span', 'd', '×'));
    b.onclick = () => { suggMode = active ? null : 'near'; renderSugg(); };
    return b;
}
let suggSig = '';
function renderSugg() {
    const box = $('sugg');
    const next = document.createElement('div');
    buildSugg(next);
    box.hidden = false;
    // Gleicher Inhalt? Dann die Liste stehen lassen – sonst baut sie sich
    // beim Nachladen alle paar Sekunden unter dem Finger neu auf.
    const sig = next.textContent;
    if (sig === suggSig && box.firstChild) return;
    suggSig = sig;
    box.textContent = '';
    while (next.firstChild) box.appendChild(next.firstChild);
}
function buildSugg(box) {
    if (suggMode === 'err') {
        box.appendChild(el('div', 'sg-note', 'Standort nicht verfügbar.'));
        return;
    }
    const fit = c => dayFit(c) && musicMatch(c);
    const clubRow = c => {
        const s = refStatus(c);
        return sgRow('Club:', c.name,
            userPos ? fmtKm(distKm(userPos, c)) : s.short,
            userPos ? '' : 'st-' + kindOf(s),
            () => { clearTimeout(qTimer); $('q').blur(); closeSugg(); openSheet(c, true); });
    };
    if (suggMode === 'near' && userPos) {
        box.appendChild(nearRow(true));
        const list = DATA.clubs.filter(fit).sort(byDist).slice(0, 12);
        if (!list.length) box.appendChild(el('div', 'sg-note', 'Kein Club für die aktuellen Filter.'));
        for (const c of list) box.appendChild(clubRow(c));
        return;
    }
    const q = fold($('q').value.trim());
    if (userPos && !q) box.appendChild(nearRow(false));
    // Orte: nur solche mit passenden Clubs, nächstgelegene zuerst, kurz gehalten
    const cities = {};
    for (const c of DATA.clubs) {
        if (!fit(c)) continue;
        if (q && !fold(c.city).includes(q)) continue;
        const e = cities[c.city] || (cities[c.city] = { n: 0, d: Infinity });
        e.n++;
        if (userPos) e.d = Math.min(e.d, distKm(userPos, c));
    }
    const cityNames = Object.keys(cities).sort((a, b) =>
        (userPos ? cities[a].d - cities[b].d : 0) || a.localeCompare(b, 'de'));
    for (const city of cityNames.slice(0, 6)) {
        const e = cities[city];
        box.appendChild(sgRow('Ort:', city,
            userPos && e.d < Infinity ? fmtKm(e.d) : e.n + (e.n === 1 ? ' Club' : ' Clubs'), '', () => {
                clearTimeout(qTimer);
                $('q').value = city;
                state.q = city.toLowerCase();
                $('q').blur();
                closeSugg();
                syncUrl(); render();
            }));
    }
    const cl = DATA.clubs.filter(c => fit(c) && (!q || fold(c.name).includes(q)));
    cl.sort(userPos ? byDist : (a, b) => a.name.localeCompare(b.name, 'de'));
    for (const c of cl.slice(0, 7)) box.appendChild(clubRow(c));
    if (!box.childNodes.length) {
        const other = otherDays();
        box.appendChild(el('div', 'sg-note', other
            ? timeLabel() + ': nichts – an anderen Tagen schon.' : 'Keine Treffer.'));
        if (other) box.appendChild(allDaysBtn(renderSugg));
    }
}
function setUserPos(lat, lng, pan) {
    userPos = { lat, lng };
    $('loc').classList.add('on');
    // Weit außerhalb? Dann nicht wortlos auf eine leere Karte fahren.
    let near = Infinity;
    for (const c of DATA.clubs) near = Math.min(near, distKm(userPos, c));
    const far = isFinite(near) && near > 150;
    placeYou(far ? false : pan);
    render();
    if (far) {
        locNote('Hier gibt es noch keine Clubs – der nächste liegt ' + fmtKm(near) + ' entfernt.');
    }
}
/* Ein später GPS-Fix darf offene Menüs/Kacheln nicht überlagern */
function uiIdle() { return !sheetKind && !ddKind; }
/* Standort wie in der Karten-App des Telefons: Systemdialog beim ersten
   Tipp, danach still. Scheitert es, sagt die Seite auch warum. */
function locNote(msg) {
    const n = $('locnote');
    n.textContent = '';
    if (!msg) { n.hidden = true; return; }
    n.appendChild(el('span', '', msg));
    const x = el('button', 'chip', 'OK');
    x.onclick = () => locNote('');
    n.appendChild(x);
    n.hidden = false;
}
/* Ohne HTTPS verweigern alle Browser den Standort – stumm. Das sagen wir. */
function locBlocked() {
    if (!navigator.geolocation) return 'Dieser Browser kann keinen Standort bestimmen.';
    if (!window.isSecureContext && location.protocol !== 'https:'
        && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
        return 'Standort geht nur über HTTPS. Seite bitte mit https:// öffnen.';
    }
    return '';
}
function locFail(err) {
    if (err && err.code === 1) return 'Standort ist für diese Seite blockiert – im Browser unter „Website-Einstellungen“ erlauben.';
    if (err && err.code === 3) return 'Standort dauert zu lange – draußen oder mit WLAN nochmal versuchen.';
    return 'Standort gerade nicht verfügbar.';
}
let locBusy = false;
$('loc').onclick = () => {
    const blocked = locBlocked();
    if (blocked) { locNote(blocked); return; }
    if (locBusy) return;
    locBusy = true;
    locNote('');
    $('loc').classList.add('busy');
    if (userPos) { suggMode = 'near'; renderSugg(); }
    // erst grob und schnell (reicht für „in meiner Nähe“), das spart Wartezeit
    navigator.geolocation.getCurrentPosition(p => {
        locBusy = false;
        $('loc').classList.remove('busy');
        setUserPos(p.coords.latitude, p.coords.longitude, uiIdle());
        if (uiIdle()) { suggMode = 'near'; renderSugg(); }
    }, err => {
        locBusy = false;
        $('loc').classList.remove('busy');
        if (!userPos) locNote(locFail(err));
    }, { enableHighAccuracy: false, timeout: 12000, maximumAge: 120000 });
};

/* ---- Chips + Dropdowns ---- */
function timeLabel() {
    const t = nightIso();
    if (state.date === null) return 'Alle Tage';
    if (state.date === t) return 'Heute';
    if (state.date === addDays(t, 1)) return 'Morgen';
    return fmtDate(state.date);
}
function musicSummary() {
    const s = state.music.genres.join(state.music.mode === 'and' ? ' und ' : ' oder ');
    return s.length > 26 ? state.music.genres.length + ' Stile' : s;
}
function renderChips() {
    const box = $('chips');
    const sl = box.scrollLeft;
    const fkey = document.activeElement && document.activeElement.parentElement === box
        ? document.activeElement.dataset.fkey : null;
    box.textContent = '';
    const mk = (key, label, on, fn) => {
        const c = el('button', 'chip' + (on ? ' on' : ''), label);
        c.dataset.fkey = key;
        c.setAttribute('aria-expanded', ddKind === key ? 'true' : 'false');
        c.onclick = fn;
        box.appendChild(c);
    };
    mk('time', timeLabel() + ' ▾', state.date !== null, () => toggleDropdown('time'));
    const n = state.music.genres.length;
    mk('music', (n ? musicSummary() : 'Musik') + ' ▾', n > 0, () => toggleDropdown('music'));
    box.scrollLeft = sl;
    if (fkey) {
        const t = box.querySelector('[data-fkey="' + CSS.escape(fkey) + '"]');
        if (t) t.focus({ preventScroll: true });
    }
}

let ddKind = null, musicPending = null;
function closeDropdown() {
    if (!ddKind) return;
    ddKind = null;
    musicPending = null;
    $('dd').hidden = true;
    renderChips();
}
function toggleDropdown(kind) {
    if (ddKind === kind) { closeDropdown(); return; }
    closeSugg();
    ddKind = kind;
    $('dd').hidden = false;
    (kind === 'time' ? renderTimeDD : renderMusicDD)();
    renderChips();
}

const ICONS = {
    cal: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></svg>',
    trash: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>',
    loc: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="6.5"/><path d="M12 1.5V5M12 19v3.5M1.5 12H5M19 12h3.5"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>',
};

function renderTimeDD() {
    const dd = $('dd');
    dd.textContent = '';
    const sync = () => { renderTimeDD(); renderChips(); render(); syncUrl(); };
    const btn = (label, on, dim, fn) => {
        const b = el('button', 'chip' + (on ? ' on' : '') + (dim ? ' dim' : ''), label);
        b.onclick = fn;
        return b;
    };
    const trash = (label, fn) => {
        const t = el('button', 'trash');
        t.innerHTML = ICONS.trash;
        t.setAttribute('aria-label', label);
        t.onclick = fn;
        return t;
    };
    const today = nightIso();
    const tomorrow = addDays(today, 1);
    // Tage ohne einen einzigen passenden Club (Musik + Suche einbezogen) gar nicht anbieten
    const okToday = DATA.clubs.some(c => {
        const s = status(c);
        return (s.open || s.tonight) && musicMatch(c) && searchMatch(c);
    });
    const okOn = iso => DATA.clubs.some(c => statusOn(c, iso).open && musicMatch(c) && searchMatch(c));
    const okAll = DATA.clubs.some(c => musicMatch(c) && searchMatch(c));
    const customDate = !!state.date && state.date !== today && state.date !== tomorrow;
    const r = el('div', 'dd-row');
    if (okToday || state.date === today) {
        r.appendChild(btn('Heute', state.date === today, customDate, () => { state.date = today; sync(); }));
    }
    if (okOn(tomorrow) || state.date === tomorrow) {
        r.appendChild(btn('Morgen', state.date === tomorrow, customDate, () => { state.date = tomorrow; sync(); }));
    }
    const db = el('button', 'chip icobtn' + (customDate ? ' on' : ''));
    db.innerHTML = ICONS.cal;
    if (customDate) db.append(' ' + fmtDate(state.date));
    const di = el('input');
    di.type = 'date';
    di.min = addDays(today, -14);
    di.max = addDays(today, 14);
    di.setAttribute('aria-label', 'Tag wählen');
    db.tabIndex = -1;
    if (state.date) di.value = state.date;
    di.onchange = () => {
        state.date = di.value || today;
        sync();
    };
    db.appendChild(di);
    r.appendChild(db);
    if (customDate) {
        r.appendChild(trash('Datum auf heute setzen', () => { state.date = today; sync(); }));
    }
    if (okAll || state.date === null) {
        r.appendChild(btn('Alle Tage', state.date === null, false, () => { state.date = null; sync(); }));
    }
    dd.appendChild(r);
}

function renderMusicDD() {
    const dd = $('dd');
    dd.textContent = '';
    const sync = () => { renderMusicDD(); renderChips(); render(); };
    const sel = state.music.genres;
    const fits = c => dayFit(c) && searchMatch(c);
    if (musicPending) {
        dd.appendChild(el('div', 'dd-q', 'Wie kombinieren?'));
        const choice = (word, mode) => {
            const have = state.music.genres.join(' ' + word + ' ');
            const b = el('button', 'dd-choice');
            b.append('Clubs, die ', el('b', '', have), ' ', el('b', '', word), ' ', el('b', '', musicPending), ' anbieten');
            b.onclick = () => {
                state.music.mode = mode;
                state.music.genres.push(musicPending);
                musicPending = null;
                sync();
            };
            return b;
        };
        // "und" nur anbieten, wenn die Kombination überhaupt einen Club trifft
        if (DATA.clubs.some(c => fits(c) && [...sel, musicPending].every(x => c.genres.includes(x)))) {
            dd.appendChild(choice('und', 'and'));
        }
        dd.appendChild(choice('oder', 'or'));
        const cancel = el('button', 'dd-cancel', 'Abbrechen');
        cancel.onclick = () => { musicPending = null; sync(); };
        dd.appendChild(cancel);
        return;
    }
    if (sel.length >= 2) {
        dd.appendChild(el('div', 'dd-h', 'Verknüpfung'));
        const andOk = DATA.clubs.some(c => fits(c) && sel.every(x => c.genres.includes(x)));
        const seg = el('div', 'seg');
        const bAnd = el('button', state.music.mode === 'and' ? 'on' : (andOk ? '' : 'dim'), 'und');
        const bOr = el('button', state.music.mode === 'or' ? 'on' : '', 'oder');
        if (andOk || state.music.mode === 'and') {
            bAnd.onclick = () => { state.music.mode = 'and'; sync(); };
        } else {
            bAnd.disabled = true; // ohne gemeinsamen Club nicht wählbar
        }
        bOr.onclick = () => { state.music.mode = 'or'; sync(); };
        seg.append(bAnd, bOr);
        dd.appendChild(seg);
    }
    dd.appendChild(el('div', 'dd-h', 'Musik'));
    // Nur Richtungen anbieten, die mit den übrigen aktiven Filtern
    // mindestens einen Club liefern; Gewähltes bleibt immer sichtbar
    const offered = GENRES.filter(k => {
        if (sel.includes(k)) return true;
        return DATA.clubs.some(c => {
            if (!fits(c) || !c.genres.includes(k)) return false;
            return !(sel.length && state.music.mode === 'and') || sel.every(x => c.genres.includes(x));
        });
    });
    const row = el('div', 'dd-row');
    if (!offered.length) {
        // Sackgasse vermeiden: erklären und einen Ausweg anbieten
        dd.appendChild(el('div', 'sg-note', 'Zur aktuellen Auswahl passt kein Stil.'));
        const b = el('button', 'chip', 'Filter zurücksetzen');
        b.onclick = resetFilters;
        dd.appendChild(b);
        return;
    }
    for (const k of offered) {
        const on = sel.includes(k);
        const ch = el('button', 'chip' + (on ? ' on' : ''), k);
        ch.onclick = () => {
            if (on) {
                state.music.genres = state.music.genres.filter(x => x !== k);
            } else if (state.music.genres.length >= 1) {
                musicPending = k; // Und/Oder-Frage stellen
            } else {
                state.music.genres.push(k);
            }
            sync();
        };
        row.appendChild(ch);
    }
    dd.appendChild(row);
    if (state.music.genres.length) {
        const reset = el('button', 'dd-cancel', 'Musikfilter entfernen');
        reset.style.display = 'block';
        reset.onclick = () => { state.music = { mode: 'or', genres: [] }; sync(); };
        dd.appendChild(reset);
    }
}

/* ---- Sheets ---- */
let activeId = null, lastFocus = null, sheetKind = null, progRefresh = null;

/* Bild-Vollansicht: Tipp auf ein Foto öffnet, Tipp irgendwo schließt */
function openLb(src) {
    const lb = $('lb');
    lb.textContent = '';
    const x = el('button', 'lbx', '\u00d7');
    x.setAttribute('aria-label', 'Schließen');
    x.onclick = closeLb;
    lb.appendChild(x);
    if (lb.hidden) ovlOpen();
    const img = el('img');
    img.src = src;
    img.alt = '';
    img.referrerPolicy = 'no-referrer';
    lb.appendChild(img);
    lb.hidden = false;
}
function closeLb() {
    if ($('lb').hidden) return;
    ovlClose();
    $('lb').hidden = true;
    $('lb').textContent = '';
}
/* Bild-Liste säubern: http→https (Mixed Content) und Größen-Dubletten raus */
function cleanImgs(list) {
    const seen = new Set(), out = [];
    for (let u of list || []) {
        u = u.replace(/^http:\/\//i, 'https://');
        const k = u.split('?')[0];
        if (seen.has(k)) continue;
        seen.add(k);
        out.push(u);
    }
    return out;
}
function buildPics(imgs) {
    const pics = el('div', 'pics');
    for (const u of imgs) {
        const img = el('img');
        img.src = u;
        img.loading = 'lazy';
        img.alt = '';
        img.referrerPolicy = 'no-referrer'; // Hotlink-Schutz vieler Clubseiten umgehen
        img.onerror = () => img.remove();
        img.onclick = () => openLb(u);
        pics.appendChild(img);
    }
    return pics;
}

function sheetBase(title) {
    const sheet = $('sheet');
    sheet.setAttribute('aria-label', title);
    sheet.textContent = '';
    const head = el('div', 'shead');
    const close = el('button', '', '×');
    close.id = 'close';
    close.setAttribute('aria-label', 'Schließen');
    close.onclick = closeSheet;
    head.append(el('div', 'grip'), close, el('h2', '', title));
    const body = el('div', 'sbody');
    sheet.append(head, body);
    // Telefon-Geste: am Kopf nach unten ziehen schließt die Kachel
    let y0 = null, dy = 0;
    head.addEventListener('pointerdown', e => {
        if (e.target.closest('#close')) return;
        y0 = e.clientY;
        dy = 0;
        if (head.setPointerCapture) head.setPointerCapture(e.pointerId);
        sheet.classList.add('drag');
    });
    head.addEventListener('pointermove', e => {
        if (y0 === null) return;
        dy = Math.max(0, e.clientY - y0);
        sheet.style.transform = dy ? 'translateY(' + dy + 'px)' : '';
    });
    const dragEnd = () => {
        if (y0 === null) return;
        y0 = null;
        sheet.classList.remove('drag');
        sheet.style.transform = '';
        if (dy > 90) closeSheet();
    };
    head.addEventListener('pointerup', dragEnd);
    head.addEventListener('pointercancel', dragEnd);
    return { head, body };
}
/* Hintergrund während eines offenen Sheets für Tastatur/Screenreader sperren */
function bgInert(on) {
    for (const n of document.querySelectorAll('.bar, #map')) n.inert = on;
}
/* Zurück-Geste/-Taste soll erst die Kachel schließen, nicht die Seite verlassen */
let ovlDepth = 0, ovlBack = false;
function ovlOpen() { ovlDepth++; history.pushState({ ovl: ovlDepth }, ''); }
function ovlClose() { if (ovlDepth > 0 && !ovlBack) { ovlBack = true; history.back(); } }
addEventListener('popstate', () => {
    const fromUi = ovlBack;
    ovlBack = false;
    if (ovlDepth > 0) ovlDepth--;
    if (fromUi) return; // die UI hat schon geschlossen
    if (!$('lb').hidden) closeLb();
    else if (sheetKind) closeSheet();
    else if (ddKind) closeDropdown();
});
function showSheet(kind) {
    const sheet = $('sheet');
    if (!sheet.classList.contains('show')) { lastFocus = document.activeElement; ovlOpen(); }
    sheetKind = kind;
    document.documentElement.classList.add('sheet-open');
    sheet.classList.add('show');
    $('veil').classList.add('show');
    bgInert(true);
    sheet.inert = false;
    sheet.focus({ preventScroll: true });
}
function closeSheet() {
    if (!sheetKind) return;
    ovlClose();
    document.documentElement.classList.remove('sheet-open');
    const sheet = $('sheet');
    sheet.classList.remove('show');
    sheet.inert = true;
    $('veil').classList.remove('show');
    bgInert(false);
    if (activeId && map) markers[activeId].setIcon(pinIcon(DATA.clubs.find(c => c.id === activeId)));
    activeId = null;
    progRefresh = null;
    raise(null);
    syncUrl();
    refreshPins(filtered());
    sheetKind = null;
    if (lastFocus && lastFocus.isConnected) lastFocus.focus();
    lastFocus = null;
}

/* Programm mit Tages-Blättern (±14 Tage; per Deeplink auch weiter draußen einsehbar) */
function progSection(c) {
    const sec = el('section');
    const today = nowParts().iso;
    const min = addDays(today, -14), max = addDays(today, 14);
    let cur = state.date || nightIso();
    const head = el('div', 'prog-head');
    const prev = el('button', 'pnav', '‹');
    const next = el('button', 'pnav', '›');
    prev.setAttribute('aria-label', 'Vortag');
    next.setAttribute('aria-label', 'Folgetag');
    const title = el('h3');
    const dayBody = el('div');
    const renderDay = () => {
        title.textContent = 'Programm · ' + fmtDate(cur);
        prev.disabled = cur <= min;
        next.disabled = cur >= max;
        prev.classList.toggle('dim', prev.disabled);
        next.classList.toggle('dim', next.disabled);
        dayBody.textContent = '';
        const evs = ((DATA.live[c.id] || {}).events || []).filter(e => e.date === cur);
        if (evs.length) {
            for (const e of evs) {
                dayBody.appendChild(el('div', 'ev-title', e.title));
                if (e.img) {
                    const iu = e.img.replace(/^http:\/\//i, 'https://');
                    const im = el('img', 'ev-img');
                    im.src = iu;
                    im.loading = 'lazy';
                    im.alt = '';
                    im.referrerPolicy = 'no-referrer';
                    im.onerror = () => im.remove();
                    im.onclick = () => openLb(iu);
                    dayBody.appendChild(im);
                }
                if (e.desc) dayBody.appendChild(el('p', 'infotext', e.desc));
            }
        } else {
            const slot = (c.hours || []).find(h => h[0].includes(dayOfIso(cur)));
            dayBody.appendChild(el('p', 'infotext muted-p', slot
                ? 'Regulär offen · ' + slot[1] + ' – ' + slot[2]
                : ((c.hours || []).length ? 'Geschlossen' : 'Kein Programm bekannt')));
        }
    };
    prev.onclick = () => { if (cur > min) { cur = addDays(cur, -1); renderDay(); } };
    next.onclick = () => { if (cur < max) { cur = addDays(cur, 1); renderDay(); } };
    head.append(prev, title, next);
    sec.append(head, dayBody);
    renderDay();
    progRefresh = renderDay; // nachgeladene Programm-Daten in die offene Kachel spiegeln
    return sec;
}

function openSheet(c, pan) {
    closeSugg();
    closeTip();
    if (map) {
        // auch per Maus geöffnete Kurzinfos sauber wegräumen
        for (const k in markers) {
            if (markers[k].closeTooltip) markers[k].closeTooltip();
        }
    }
    if (activeId && activeId !== c.id && map) {
        markers[activeId].setIcon(pinIcon(DATA.clubs.find(x => x.id === activeId)));
    }
    activeId = c.id;
    syncUrl();
    const s = refStatus(c);
    const live = DATA.live[c.id] || {};
    const { head, body } = sheetBase(c.name);
    head.appendChild(el('div', 'status st-' + kindOf(s), statusLine(c, s)));

    if (live.warn) body.appendChild(el('p', 'warnline', 'Hinweis der Website: „' + live.warn + '“'));

    const tags = el('div', 'tags');
    for (const g of c.genres) {
        const t = el('button', 'tag', g);
        t.title = 'Karte auf ' + g + ' filtern';
        t.onclick = () => {
            // Tipp auf einen Stil: Karte auf diese Musik filtern
            state.music = { mode: 'or', genres: [g] };
            closeSheet();
            renderChips();
            render();
        };
        tags.appendChild(t);
    }
    body.appendChild(tags);

    const imgs = cleanImgs(live.images).slice(0, 4);
    if (imgs.length) body.appendChild(buildPics(imgs));

    if (live.info) body.appendChild(el('p', 'about', live.info));

    body.appendChild(progSection(c));

    // Öffnungszeiten komplett hinter einem Ausklapper
    const det = el('details', 'hrs');
    det.appendChild(el('summary', '', 'Öffnungszeiten'));
    if ((c.hours || []).length) {
        let refDay;
        if (state.date) {
            refDay = dayOfIso(state.date);
        } else {
            // läuft noch die Nacht vom Vortag, dessen Zeile markieren
            const { day, min } = nowParts();
            const prev = day === 1 ? 7 : day - 1;
            const carry = c.hours.some(([days, o, cl]) => toMin(cl) < toMin(o) && days.includes(prev) && min < toMin(cl));
            refDay = carry ? prev : day;
        }
        for (const [days, o, cl] of c.hours) {
            const row = el('div', days.includes(refDay) ? 'today' : '');
            row.append(el('span', '', days.map(d => DAYS[d - 1]).join(', ')), document.createTextNode(o + ' – ' + cl));
            det.appendChild(row);
        }
    } else {
        det.appendChild(el('div', '', 'Nur bei Events'));
    }
    if (c.note) det.appendChild(el('span', 'note', c.note));
    body.appendChild(det);

    const act = el('div', 'actions');
    const app = appRoute(c);
    const route = el('a', 'primary', 'Route');
    route.href = app.href;
    if (app.href.indexOf('geo:') !== 0) {
        route.target = '_blank';
        route.rel = 'noopener';
    }
    act.appendChild(route);
    if (c.url) {
        const site = el('a', '', c.url.includes('instagram.') ? 'Instagram' : 'Website');
        site.href = c.url;
        site.target = '_blank';
        site.rel = 'noopener';
        act.appendChild(site);
    }
    body.appendChild(act);
    body.appendChild(el('p', 'nog', 'Alle Angaben ohne Gewähr.'));

    showSheet('club');
    if (map) {
        // der geöffnete Club muss sichtbar sein, auch wenn ein Filter ihn ausblendet
        if (!map.hasLayer(markers[c.id])) refreshPins(filtered()); // activeId bleibt darin sichtbar
        markers[c.id].setIcon(pinIcon(c, true));
        raise(c.id);
        if (pan) map.setView(POS[c.id], Math.max(map.getZoom(), 15), { animate: true });
    }
}

$('veil').onclick = closeSheet;
$('lb').onclick = closeLb;
addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        if (!$('lb').hidden) closeLb();
        else if (ddKind) closeDropdown();
        else if (!$('sugg').hidden) closeSugg();
        else if (tipId) closeTip();
        else closeSheet();
    }
});
document.addEventListener('click', e => {
    if (ddKind && !e.target.closest('#dd') && !e.target.closest('.chips')) closeDropdown();
    if (!$('sugg').hidden && !e.target.closest('.bar')) closeSugg();
}, true);
$('q').addEventListener('focus', () => { closeDropdown(); renderSugg(); });
let qTimer = null;
$('q').addEventListener('input', e => {
    state.q = e.target.value.trim().toLowerCase();
    suggMode = null;
    clearTimeout(qTimer);
    // beim Tippen kurz sammeln – sonst rechnet die Karte je Buchstabe neu
    qTimer = setTimeout(() => {
        syncUrl();
        render();
        // nur nachziehen, solange die Suche wirklich noch offen ist
        if (document.activeElement === $('q')) renderSugg();
    }, 130);
});
$('q').addEventListener('keydown', e => {
    if (e.key === 'Enter') {
        clearTimeout(qTimer);
        syncUrl(); render();
        closeSugg();
        e.target.blur();
    }
});

/* Filter aus der URL übernehmen; alte Daten in Links fallen sauber auf heute zurück */
const boot = new URLSearchParams(location.search);
if (boot.get('search')) {
    $('q').value = boot.get('search');
    state.q = boot.get('search').trim().toLowerCase();
}
const bootDate = boot.get('date');
if (bootDate === 'alle') {
    state.date = null;
} else if (bootDate && /^\d{4}-\d{2}-\d{2}$/.test(bootDate)) {
    const d = new Date(bootDate + 'T12:00:00Z');
    if (!isNaN(d) && d.toISOString().slice(0, 10) === bootDate) { // z. B. 31.02. abweisen
        state.date = bootDate; // Deeplinks gelten immer, auch außerhalb ±14 Tagen
    }
}
renderChips();
render();
syncUrl();
const bootClub = DATA.clubs.find(c => c.id === boot.get('club'));
if (bootClub) openSheet(bootClub, true);
if (boot.get('near')) {
    // vom Start weitergereicht: Standort direkt übernehmen
    const blocked = locBlocked();
    if (blocked) {
        locNote(blocked);
    } else {
        navigator.geolocation.getCurrentPosition(
            p => setUserPos(p.coords.latitude, p.coords.longitude, !bootClub),
            err => { if (err && err.code === 1) locNote(locFail(err)); },
            { enableHighAccuracy: false, timeout: 12000, maximumAge: 120000 }
        );
    }
}

let lastNight = nightIso();
setInterval(() => {
    const n = nightIso();
    if (n !== lastNight) {
        if (state.date === lastNight) state.date = n; // Standard „Heute“ wandert mit
        lastNight = n;
        renderChips();
        if (ddKind === 'time') renderTimeDD();
        syncUrl();
    }
    const fp = DATA.clubs.map(c => status(c).short).join('|');
    if (fp !== lastFp) { lastFp = fp; render(); }
}, 60000);

/* Hintergrund-Aktualisierung anstoßen und frische Daten (Bilder, Programm)
   ohne Neuladen einsammeln, bis die Erstbefüllung durch ist */
const EP = q => (DATA.cc ? '?c=' + DATA.cc + '&' : '?') + q;
if (DATA.cron) {
    let liveTries = 0;
    liveBusy = true; // dezenter „lädt“-Hinweis in der Legende
    render();
    let lastPending = -1, stuck = 0, netNote = false;
    const again = ms => { if (++liveTries < 45) { setTimeout(liveTick, ms); } else { liveBusy = false; render(); } };
    const liveTick = () => {
        // Bringt der Hintergrund-Ping nichts, den Poll selbst arbeiten lassen
        const work = stuck >= 2 ? '&work=1' : '';
        // Arbeitet der Server mit, kann das dauern – aber nicht ewig
        const opt = work && window.AbortSignal && AbortSignal.timeout ? { signal: AbortSignal.timeout(25000) } : {};
        fetch(EP('live=1') + work, opt).then(r => r.json()).then(j => {
            if (j.pending === lastPending) { stuck++; } else { stuck = 0; }
            lastPending = j.pending;
            let changed = false;
            if (j.live) {
                const sig = Object.keys(j.live).length + ':' + JSON.stringify(j.live).length;
                changed = sig !== liveSig;
                liveSig = sig;
                DATA.live = j.live;
            }
            if (j.neterr) {
                // Hoster lässt den Server nicht ins Netz – ohne das gibt es
                // weder Bilder noch Programm. Klartext statt endlosem Laden.
                // Trotzdem weiter nachsehen, falls es sich wieder einrenkt.
                liveBusy = false;
                netNote = true;
                locNote('Programm und Bilder können nicht geladen werden: Der Webhoster blockiert ausgehende Verbindungen (' + j.neterr + ').');
                render();
                again(j.pending > 0 ? 30000 : 60000); // auf Erholung warten
                return;
            }
            if (netNote) { netNote = false; locNote(''); } // geht wieder
            if (j.pending > 0) {
                fetch(EP('cron=1'), { keepalive: true }).catch(() => {});
                again(9000);
            } else {
                liveBusy = false;
                changed = true;
            }
            // nur zeichnen, wenn es etwas Neues gibt – sonst ruckelt die Karte grundlos
            if (changed) render();
        }).catch(() => {
            // Wackler im Netz oder abgebrochener Arbeitslauf: nicht aufgeben,
            // nur langsamer weitermachen und den Notbetrieb zurücksetzen
            stuck = 0;
            again(15000);
        });
    };
    setTimeout(() => {
        fetch(EP('cron=1'), { keepalive: true }).catch(() => {});
        setTimeout(liveTick, 9000);
    }, 1500);
}
</script>
<script async src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""
        onload="initMap()" onerror="mapFailed()"></script>
</body>
</html>
