<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Berlin');

if (isset($_GET['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

const CACHE_V = 2;
/* Scrapen läuft im Vordergrund: leerer Cache -> Ladebalken, der alles einmal
   holt; danach wird ein Club beim Öffnen frisch nachgeholt. Kein Ratelimit,
   aber die PHP-Laufzeit je Aufruf bleibt begrenzt – deshalb in Häppchen.
   SETUP_SECS/CLUB_SECS bewusst unter dem üblichen 30-s-Deckel vieler Hoster. */
const SETUP_BATCH = 12;   // Clubs je Ladebalken-Schritt (Zeit begrenzt real)
const SETUP_SECS = 20;    // Zeitbudget je Ladebalken-Schritt
const CLUB_SECS = 15;     // Zeitbudget beim Öffnen eines einzelnen Clubs
const CLUB_FRESH = 900;   // so lange gilt ein Club als frisch (kein Neuscrape)
/* Wie oft höchstens im Repo nachgesehen wird, ob sich Connectoren geändert
   haben. Der Abgleich selbst ist ein einziger Zipball-Download. */
const UPDATE_EVERY = 21600;

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
const CONNECTOR_AUTO = true;   // leere Seite holt sich die Clubs selbst aus dem Repo
const CONNECTOR_REFRESH = 86400; // Selbst-Aktualisierung der geholten Clubs (Sekunden)

/* Alles über die eigene Domain: Bilder, Kacheln und Leaflet kommen über
   diese index.php – der Browser spricht nur noch mit dem eigenen Server. */
const PROXY_IMAGES = true;   // Clubfotos über ?img= statt direkt vom Club-Server
/*
 * Kartenkacheln über den eigenen Server. Eine Kartenansicht lädt 50–150
 * Kacheln; damit daraus nicht 50–150 PHP-Aufrufe werden, landen sie in
 * cache/tile/ und Apache liefert jede weitere Anfrage selbst aus – PHP läuft
 * nur noch beim allerersten Mal.
 *
 * true heißt hier "wenn der Server es verträgt": auf einem stark
 * eingeschränkten Gratis-Hoster (InfinityFree & Co. – kein posix, /tmp
 * gesperrt) würde jede Kachel gegen das Tageslimit zählen und den Account
 * sperren. Dort schaltet host_limited() den Kachel-Proxy von selbst ab und
 * der Browser holt die Kacheln direkt vom Karten-CDN. false erzwingt das
 * überall, unabhängig vom Host.
 *
 * Ungeklärt: ob CARTO das serverseitige Zwischenspeichern seiner Kacheln
 * erlaubt, ist NICHT nachgeprüft – die Bedingungen unter
 * docs.carto.com/faqs/carto-basemaps und carto.com/attributions waren beim
 * Bauen nicht erreichbar. Wer auf Nummer sicher gehen will, liest dort nach
 * und stellt notfalls diese eine Zeile zurück auf false.
 */
const PROXY_TILES = true;
const IMG_TTL = 604800;      // Clubfotos 7 Tage vorhalten
const TILE_TTL = 604800;     // Kacheln 7 Tage – Untergrenze der OSM-Kachelregeln
const IMG_MAX = 4194304;     // 4 MB je Bild reicht weit
/* Wie viele Kacheln je Stil/Zoom/x-Spalte höchstens liegen bleiben. Auf
   eigenem Server ist Platte billig – veraltete räumt TILE_TTL weg. */
const TILE_KEEP = 4096;   // je Stil/Zoom/x-Spalte – eine Spalte hat nie mehr
const IMG_KEEP = 20000;   // Clubfotos insgesamt, eine Datei je Foto
/* Ein ehrlicher Name für den Kachel-Abruf: der Anbieter soll sehen, wer da
   holt. Für Clubseiten bleibt es beim Browser-Namen, sonst sperren sie aus. */
const TILE_UA = 'Nightclubmap/1.0 (Kartenanzeige, +https://github.com/florianthepro/clubconnectors)';
/* Welche Bildtypen der Proxy weiterreicht – und als was er sie ausliefert.
   Bewusst ohne SVG: eine SVG-Datei darf Skript enthalten und käme von der
   eigenen Domain, könnte also im Namen der Seite mitlesen. */
const IMG_TYPES = ['image/png' => 'image/png', 'image/jpeg' => 'image/jpeg', 'image/jpg' => 'image/jpeg',
    'image/gif' => 'image/gif', 'image/webp' => 'image/webp', 'image/avif' => 'image/avif'];
/* Endung je geprüftem Typ. Liefert Apache die Datei direkt aus, entscheidet
   allein die Endung über den Content-Type – sie muss also aus dem GEPRÜFTEN
   Typ kommen, nie aus dem, was der fremde Server behauptet. */
const IMG_EXT = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif',
    'image/webp' => 'webp', 'image/avif' => 'avif'];

/*
 * Schutz für den öffentlichen Cache. Wird beim ersten Schreiben angelegt.
 * Absichtlich als POSITIVE Liste: erst alles verbieten, dann genau die fünf
 * Bildendungen erlauben. Ein "RemoveHandler .php" oder "php_flag engine off"
 * würde bei PHP-FPM nämlich gar nichts bewirken – eine Zugriffsverweigerung
 * dagegen kann kein Handler überstimmen.
 * Bewusst ohne "Options"-Zeile: die verlangt AllowOverride Options und würde
 * sonst den ganzen Ordner mit Fehler 500 lahmlegen. Wer den eigenen Apache
 * härten will, setzt "Options -FollowSymLinks -MultiViews" in der vHost.
 */
const PUB_CACHE_HTACCESS = <<<'HTA'
# Hier liegen nur Bytes von fremden Servern - niemals ausfuehren.
<IfModule mod_authz_core.c>
    Require all denied
    <FilesMatch "\.(png|jpe?g|gif|webp|avif)$">
        Require all granted
    </FilesMatch>
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    <FilesMatch "\.(png|jpe?g|gif|webp|avif)$">
        Allow from all
    </FilesMatch>
</IfModule>
<IfModule mod_mime.c>
    AddType image/webp .webp
    AddType image/avif .avif
</IfModule>
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/png  "access plus 7 days"
    ExpiresByType image/jpeg "access plus 7 days"
    ExpiresByType image/gif  "access plus 7 days"
    ExpiresByType image/webp "access plus 7 days"
    ExpiresByType image/avif "access plus 7 days"
</IfModule>
<IfModule mod_headers.c>
    # Ueberschreibt die Ein-Jahr-Regel aus dem Web-Root: was hier liegt, ist
    # geholt und kann sich aendern - "immutable" liesse sich nie zurueckrufen.
    Header set Cache-Control "public, max-age=604800"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Content-Security-Policy "default-src 'none'; sandbox"
</IfModule>
HTA;
const TILE_URL = 'https://{s}.basemaps.cartocdn.com/{style}/{z}/{x}/{y}{r}.png';

/* Geschlossene Liste aus SPEC.md Abschnitt 6 */
const OK_GENRES = ['80er/90er', 'Black Music', 'Drum and Bass', 'Electro', 'Goa', 'Hip-Hop',
    'House', 'Indie', 'Latin', 'Live', 'Mixed', 'Rock', 'Schlager', 'Techno'];
/* Land-Rahmen aus SPEC.md Abschnitt 8: lat_min lat_max lng_min lng_max */
const BBOX = ['de' => [47.20, 55.10, 5.80, 15.10], 'at' => [46.30, 49.10, 9.50, 17.20],
    'ch' => [45.80, 47.85, 5.90, 10.55]];
/* Untereinheiten je Land, ebenfalls SPEC.md Abschnitt 8 */
const OK_AREAS = [
    'de' => ['baden-wuerttemberg', 'bayern', 'berlin', 'brandenburg', 'bremen', 'hamburg', 'hessen',
        'mecklenburg-vorpommern', 'niedersachsen', 'nordrhein-westfalen', 'rheinland-pfalz', 'saarland',
        'sachsen', 'sachsen-anhalt', 'schleswig-holstein', 'thueringen'],
    'at' => ['burgenland', 'kaernten', 'niederoesterreich', 'oberoesterreich', 'salzburg', 'steiermark',
        'tirol', 'vorarlberg', 'wien'],
    'ch' => ['ag', 'ai', 'ar', 'be', 'bl', 'bs', 'fr', 'ge', 'gl', 'gr', 'ju', 'lu', 'ne', 'nw', 'ow',
        'sg', 'sh', 'so', 'sz', 'tg', 'ti', 'ur', 'vd', 'vs', 'zg', 'zh'],
];
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
        if (!empty($y['about'])) {
            // Kurzbeschreibung aus dem Connector – das "was erwartet mich",
            // auch wenn die Website nichts liefert oder gar nicht gelesen wird
            $club['about'] = $y['about'];
        }
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
        if (empty($y['scrape_url'])) {
            $club['nolive'] = true; // Kachel erst gar nicht nach frischen Daten fragen
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
/* Leaflet & Co. aus dem eigenen Haus: vendor/ neben der index.php oder
   data/vendor/ (per Sync geholt). Kein fremder CDN, kein fremdes Zertifikat. */
function vendor_file(string $name): ?string
{
    foreach ([__DIR__ . '/vendor', __DIR__ . '/data/vendor'] as $dir) {
        foreach ([$dir . '/' . $name, $dir . '/images/' . $name] as $f) {
            if (is_file($f)) {
                return $f;
            }
        }
    }
    return null;
}

function have_vendor(): bool
{
    return vendor_file('leaflet.js') !== null && vendor_file('leaflet.css') !== null;
}

/*
 * Adresse für eine mitgelieferte Datei. Liegt vendor/ offen im Web-Root,
 * holt der Browser sie direkt bei Apache – das spart auf Gratis-Hostern je
 * Besucher zwei PHP-Prozesse. Nur wenn die Dateien im geschützten
 * data/vendor/ liegen (per Sync geholt), muss PHP sie durchreichen.
 */
function asset_url(string $name): string
{
    return is_file(__DIR__ . '/vendor/' . $name) ? 'vendor/' . $name . '?v=1' : '?asset=' . $name;
}

/*
 * Der öffentliche Byte-Cache: cache/ NEBEN der index.php, nicht unter data/.
 * Was hier liegt, darf Apache direkt ausliefern – deshalb 0755/0644 und nicht
 * 0700 wie beim privaten Cache: der Apache-Prozess ist oft ein anderer
 * Benutzer als PHP und käme sonst nicht an die eigenen Dateien.
 * data/ bleibt unverändert komplett gesperrt.
 */
/*
 * Legt einen Ordner samt Zwischenstufen an und setzt jede Ebene ausdrücklich
 * auf 0755. mkdir() zieht die umask ab – bei umask 077 entstünden sonst
 * 0700-Ordner, und Apache (oft ein anderer Benutzer als PHP) käme nicht
 * einmal durch die oberen Ebenen hindurch. Ein chmod nur auf die letzte
 * Ebene reicht dafür nicht.
 */
function mkdir_pub(string $dir): bool
{
    if (is_dir($dir)) {
        return true;
    }
    $teile = explode('/', str_replace('\\', '/', $dir));
    $pfad = '';
    foreach ($teile as $t) {
        $pfad .= $t . '/';
        if ($t === '') {
            continue;
        }
        if (!is_dir($pfad) && !@mkdir($pfad, 0755)) {
            return false;
        }
        @chmod($pfad, 0755);
    }
    return is_dir($dir);
}

function pub_cache_dir(string $sub = ''): ?string
{
    static $root = false;
    if ($root === false) {
        $p = __DIR__ . '/cache';
        mkdir_pub($p);
        $root = (is_dir($p) && !is_link($p) && @is_writable($p)) ? $p : null;
        if ($root !== null && !is_file($p . '/.htaccess')) {
            // Apache MUSS die Schutzdatei lesen können, sonst antwortet es mit
            // Fehler 500 statt sie anzuwenden.
            if (@file_put_contents($p . '/.htaccess', PUB_CACHE_HTACCESS) !== false) {
                @chmod($p . '/.htaccess', 0644);
            }
        }
    }
    if ($root === null || $sub === '') {
        return $root;
    }
    $d = $root . '/' . $sub;
    if (!mkdir_pub($d)) {
        return null;
    }
    return is_dir($d) && @is_writable($d) ? $d : null;
}

/*
 * Kann der Webserver Anfragen umschreiben? Apache setzt die Variable in der
 * .htaccess (`[E=NCM_REWRITE:1]`), Caddy im Caddyfile (`env NCM_REWRITE 1`).
 * Sie ist zugleich das Versprechen: „Ich liefere gecachte Kacheln direkt aus
 * und leite fehlende an die index.php." Nur aus der FastCGI-/Prozess-Umgebung,
 * nie aus einem Client-Header (`HTTP_*`) – den könnte der Aufrufer fälschen
 * und die Kacheln liefen dann ins Leere.
 */
function can_rewrite(): bool
{
    return !empty($_SERVER['NCM_REWRITE']) || !empty($_SERVER['REDIRECT_NCM_REWRITE']);
}

/*
 * Welcher Webserver bedient uns? Nur für die Hinweise in ?diag – die Logik
 * hängt nie daran, sondern immer an can_rewrite(). '' heißt: unbekannt
 * (dann werden beide Wege genannt).
 */
function server_kind(): string
{
    $s = strtolower((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));
    if (strpos($s, 'caddy') !== false) {
        return 'caddy';
    }
    if (strpos($s, 'apache') !== false) {
        return 'apache';
    }
    return '';
}

/*
 * Kacheltoken. Steht offen im Seitenquelltext – es ist kein Geheimnis,
 * sondern eine Hürde: ohne den Wert taugt die eigene Adresse nicht als
 * Kachelquelle für fremde Karten. Bleibt über Wochen gleich, damit der
 * Browser-Cache nicht täglich verfällt; ein neuer Schlüssel entwertet es.
 */
function tile_token(): string
{
    $sec = app_secret();
    return $sec === '' ? '' : substr(hash_hmac('sha256', 'tile', $sec), 0, 12);
}

/*
 * Darf dieser Abruf eine Kachel NACHLADEN? Das ist der teure Weg: er kostet
 * einen Abruf beim Karten-Anbieter und Platz auf der Platte.
 *
 * Verlangt wird eine nachweisbare Herkunft von der eigenen Seite. Das Token
 * allein reicht nicht – es steht im Seitenquelltext und ist damit für jeden
 * abschreibbar. Fehlt Referer UND Origin, wird abgewiesen: die eigene Seite
 * schickt bei gleicher Domain immer einen Referer mit, eine fremde Karte mit
 * referrerPolicy="no-referrer" dagegen nicht – und genau die soll draußen
 * bleiben. Preis: wer den Referer im Browser komplett abschaltet, sieht nur
 * noch bereits zwischengespeicherte Kacheln.
 *
 * Für schon zwischengespeicherte Kacheln greift das hier NICHT – die liefert
 * Apache aus, ohne dass PHP läuft. Dagegen hilft nur die Referer-Regel in
 * der .htaccess, und die stoppt bloß den bequemen Fall.
 */
function tile_allowed(): bool
{
    $tok = tile_token();
    if ($tok !== '' && !hash_equals($tok, (string)($_GET['t'] ?? ''))) {
        return false;
    }
    $me = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $me = (string)(strpos($me, ':') !== false ? strstr($me, ':', true) : $me);
    if ($me === '') {
        return false; // ohne eigenen Namen ist nichts vergleichbar
    }
    $herkunft = false;
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $k) {
        $v = (string)($_SERVER[$k] ?? '');
        if ($v === '') {
            continue;
        }
        $herkunft = true;
        $h = strtolower((string)(parse_url($v, PHP_URL_HOST) ?: ''));
        if ($h !== $me) {
            return false; // fremde Seite bindet unsere Kacheln ein
        }
    }
    return $herkunft;
}

/*
 * Läuft die Seite auf einem stark eingeschränkten Gratis-Hoster? Solche
 * Hoster (InfinityFree, iFastNet & Co.) sperren posix, machen /tmp dicht und
 * schalten allow_url_fopen ab – und zählen JEDEN Treffer gegen ein
 * Tageslimit. Genau das verträgt sich nicht mit einem Kachel-Proxy, der pro
 * Kachel einen PHP-Prozess kostet. Zwei dieser Merkmale genügen als Beweis;
 * ein echter Server hat sie praktisch nie zusammen. Falsch positiv ist
 * harmlos (Kacheln kommen dann direkt vom CDN), falsch negativ wäre die
 * Kontosperre – deshalb im Zweifel eher als eingeschränkt einstufen.
 */
function host_limited(): bool
{
    static $r = null;
    if ($r !== null) {
        return $r;
    }
    // open_basedir ist der Jail, mit dem Gratis-/Shared-Hoster jedes Konto
    // in sein Verzeichnis einsperren. Ein eigener Server setzt ihn so gut wie
    // nie – gesetzt heißt: eingeschränkter Hoster.
    if ((string)ini_get('open_basedir') !== '') {
        return $r = true;
    }
    // posix gehört auf jedem echten Linux-Server zur Standardausstattung.
    // Fehlt es dort, hat der Hoster es abgeklemmt – ein sicheres Zeichen für
    // einen eingeschränkten Shared-Host (genau so sieht InfinityFree aus).
    // Windows kennt posix nie; dort ist das Fehlen kein Hinweis.
    $windows = stripos(PHP_OS, 'WIN') === 0;
    if (!$windows && !function_exists('posix_getpid')) {
        return $r = true;
    }
    // Rückfall für alles andere: erst mehrere schwächere Zeichen zusammen
    // zählen als eingeschränkt.
    $marks = 0;
    if (!ini_get('allow_url_fopen')) {
        $marks++;
    }
    if (!@is_writable(sys_get_temp_dir())) {
        $marks++;
    }
    return $r = $marks >= 2;
}

/*
 * Kacheln überhaupt über den eigenen Server holen? Der Wunsch steht in
 * PROXY_TILES, aber auf einem eingeschränkten Hoster wird er ausgesetzt –
 * sonst sperrt das Tageslimit den Account. Kein Handanlegen nötig: auf einem
 * echten Server ist host_limited() false und der Proxy läuft voll.
 */
function tile_proxy_on(): bool
{
    return PROXY_TILES && !host_limited();
}

/* Kacheln als Dateipfad ausliefern (Apache) statt über ?tile= (PHP)? */
function tile_static(): bool
{
    return tile_proxy_on() && can_rewrite() && pub_cache_dir('tile') !== null;
}

/*
 * Pfad-Präfix der eigenen Seite, z. B. "/" oder "/karte/". Nötig, weil ein
 * relativer Pfad wie "cache/tile/…" sich gegen das VERZEICHNIS auflöst: bei
 * /karte (ohne Schrägstrich) läge er sonst plötzlich unter /.
 */
function url_base(): string
{
    $d = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    return rtrim($d, '/') . '/';
}

/*
 * Dateiname eines zwischengespeicherten Bildes. Aus dem Geheimnis abgeleitet,
 * nicht aus der Adresse: sonst könnte jeder durchprobieren, welche Clubseiten
 * der Server je besucht hat. Neuer Schlüssel = kalter Cache, das ist gewollt.
 */
function img_key(string $url): string
{
    $sec = app_secret();
    return $sec === '' ? sha1($url) : substr(hash_hmac('sha256', 'img|' . $url, $sec), 0, 32);
}

/*
 * Was sagen die ersten Bytes? Der behauptete Content-Type reicht nicht: bei
 * statischer Auslieferung entscheidet die Endung, und die soll zum wirklichen
 * Inhalt passen. Bewusst ohne fileinfo – die Erweiterung fehlt oft, und
 * ältere libmagic kennt AVIF/WebP nicht.
 */
/*
 * Liegt das Bild schon da? Zurück kommt [Pfad, Typ] oder null. Probiert die
 * Endungen in der Reihenfolge, in der sie auf Clubseiten vorkommen – meist
 * ist beim ersten Versuch Schluss.
 */
/*
 * Einmal den Ordner lesen statt je Bild fünfmal nachzufragen.
 * live_payload() geht über ALLE Clubs mit allen Fotos – mit je fünf
 * Dateiabfragen wären das bei 244 Clubs schnell zehntausend Systemaufrufe,
 * und zwar bei jedem Seitenaufbau und jeder Live-Abfrage.
 * Rückgabe: Schlüssel => Endung.
 */
function img_index(string $dir): array
{
    static $map = [];
    if (isset($map[$dir])) {
        return $map[$dir];
    }
    $m = [];
    foreach (@scandir($dir) ?: [] as $f) {
        if ($f === '' || $f[0] === '.') {
            continue; // Zwischendateien und . / .. übergehen
        }
        $punkt = strrpos($f, '.');
        if ($punkt !== false && $punkt > 0) {
            $m[substr($f, 0, $punkt)] = substr($f, $punkt + 1);
        }
    }
    return $map[$dir] = $m;
}

function img_cached(string $dir, string $key, int $maxAge = 0): ?array
{
    foreach (['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
              'gif' => 'image/gif', 'avif' => 'image/avif'] as $ext => $type) {
        $f = $dir . '/' . $key . '.' . $ext;
        if (!is_file($f)) {
            continue;
        }
        // Auf dem statischen Weg kommt PHP nie wieder an dieser Datei vorbei –
        // die Frist muss also hier gelten, sonst hängt ein Foto ewig fest.
        if ($maxAge > 0 && time() - (int)@filemtime($f) > $maxAge) {
            return null;
        }
        return [$f, $type];
    }
    return null;
}

function sniff_image(string $b): string
{
    if (strncmp($b, "\x89PNG\r\n\x1a\n", 8) === 0) {
        return 'image/png';
    }
    if (strncmp($b, "\xff\xd8\xff", 3) === 0) {
        return 'image/jpeg';
    }
    if (strncmp($b, 'GIF87a', 6) === 0 || strncmp($b, 'GIF89a', 6) === 0) {
        return 'image/gif';
    }
    if (strncmp($b, 'RIFF', 4) === 0 && substr($b, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    // ISO-BMFF: ....ftyp<marke>, danach die Liste verträglicher Marken.
    // Manche Werkzeuge schreiben 'mif1' als Hauptmarke und nennen 'avif'
    // erst in der Liste – deshalb beides ansehen.
    if (substr($b, 4, 4) === 'ftyp') {
        $box = max(16, min(64, (int)unpack('N', substr($b . str_repeat("\0", 4), 0, 4))[1]));
        $marken = substr($b, 8, $box - 8);
        if (strpos($marken, 'avif') !== false || strpos($marken, 'avis') !== false) {
            return 'image/avif';
        }
    }
    return '';
}

if (isset($_GET['asset'])) {
    // feste Liste – es wird nichts ausgeliefert, was nicht hier steht
    $types = [
        'leaflet.js' => 'application/javascript; charset=utf-8',
        'leaflet.css' => 'text/css; charset=utf-8',
        'layers.png' => 'image/png', 'layers-2x.png' => 'image/png',
        'marker-icon.png' => 'image/png', 'marker-icon-2x.png' => 'image/png',
        'marker-shadow.png' => 'image/png',
    ];
    $name = (string)$_GET['asset'];
    $file = isset($types[$name]) ? vendor_file($name) : null;
    if (!$file) {
        http_response_code(404);
        exit;
    }
    $body = (string)file_get_contents($file);
    if ($name === 'leaflet.css') {
        // die CSS zeigt auf images/… – bei ?asset= liegt das woanders
        $body = preg_replace('#url\((["\']?)images/([A-Za-z0-9._-]+)\1\)#', 'url(?asset=$2)', $body);
    }
    serve_bytes($body, $types[$name], 31536000);
}

if (isset($_GET['img'])) {
    // Clubfoto über die eigene Domain. Nur signierte Adressen – sonst wäre
    // das ein offener Proxy, über den Fremde beliebige Inhalte spiegeln.
    $url = unproxy_img((string)$_GET['img'], (string)($_GET['s'] ?? ''));
    if ($url === null) {
        http_response_code(403);
        exit;
    }
    // Die Endung steckt jetzt im Namen: liefert Apache die Datei direkt aus,
    // entscheidet allein sie über den Content-Type.
    $dir = pub_cache_dir('img') ?? cache_dir('img');
    $key = img_key($url);
    $hit = $dir ? img_cached($dir, $key) : null;
    if ($hit && time() - (int)filemtime($hit[0]) < IMG_TTL) {
        serve_bytes((string)file_get_contents($hit[0]), $hit[1], IMG_TTL);
    }
    $got = fetch_binary($url, IMG_MAX, IMG_TYPES);
    if (!$got) {
        // altes Bild lieber zeigen als gar keins
        if ($hit) {
            serve_bytes((string)file_get_contents($hit[0]), $hit[1], 3600);
        }
        http_response_code(404);
        exit;
    }
    if ($dir) {
        $bin = $dir . '/' . $key . '.' . IMG_EXT[$got[1]];
        // Punkt voran: die halbfertige Datei ist als versteckte Datei gesperrt.
        // Der Name muss je Vorgang eindeutig sein – zwei gleichzeitige Abrufe
        // desselben Bildes würden sich sonst gegenseitig die Datei zerschreiben.
        $tmp = $dir . '/.' . $key . '.' . getmypid() . '.' . random_int(1000, 9999) . '.tmp';
        // Kurzschreiber (Platte voll, Kontingent erschöpft) meldet keinen
        // Fehler, schreibt aber nur einen Teil – Länge vergleichen.
        $wrote = @file_put_contents($tmp, $got[0]);
        if ($wrote === strlen($got[0]) && @rename($tmp, $bin)) {
            @chmod($bin, 0644);
            if ($hit && $hit[0] !== $bin) {
                @unlink($hit[0]); // Typ hat gewechselt – alte Endung wegräumen
            }
        } else {
            @unlink($tmp); // nur wegräumen, das alte Bild bleibt liegen
        }
        if (random_int(1, 60) === 1) {
            cache_prune($dir, IMG_KEEP, IMG_TTL);
        }
    }
    serve_bytes($got[0], $got[1], IMG_TTL);
}

if (isset($_GET['tile'])) {
    // Kartenkacheln über die eigene Domain, mit Plattencache
    if (!tile_proxy_on() || !preg_match('#^(\d{1,2})/(\d{1,7})/(\d{1,7})$#', (string)$_GET['tile'], $m)) {
        http_response_code(404);
        exit;
    }
    if (!tile_allowed()) {
        // Sonst wäre das ein Kachelserver für fremde Karten: die kosten uns
        // ausgehende Abrufe und Platte, ohne dass jemand die Seite besucht.
        http_response_code(403);
        exit;
    }
    [, $z, $x, $y] = $m;
    $z = (int)$z;
    $x = (int)$x;
    $y = (int)$y;
    $max = $z < 31 ? (1 << $z) : 0;
    if ($z > 19 || $max === 0 || $x >= $max || $y >= $max) {
        http_response_code(404);
        exit;
    }
    // $style entsteht hier und nur hier – nie aus dem Pfad übernehmen,
    // sonst wandert Fremdes in den Dateipfad UND in die Adresse beim CDN.
    $style = ($_GET['m'] ?? '') === 'dark' ? 'dark_all' : 'light_all';
    $r = preg_match('/^@?2x$/', (string)($_GET['r'] ?? '')) ? '@2x' : '';
    // Bevorzugt der öffentliche Cache: von dort holt Apache die Kachel beim
    // nächsten Mal selbst. Sonst der private – dann bleibt es bei PHP.
    $pub = pub_cache_dir('tile');
    $root = $pub;
    if ($root === null) {
        $priv = cache_file('x');
        $root = $priv ? dirname((string)$priv) . '/tile' : null;
    }
    $dir = $root !== null ? $root . '/' . $style . '/' . $z . '/' . $x : '';
    $bin = $dir !== '' ? $dir . '/' . $y . $r . '.png' : null;
    if ($bin && is_file($bin) && time() - (int)filemtime($bin) < TILE_TTL) {
        serve_bytes((string)file_get_contents($bin), 'image/png', TILE_TTL);
    }
    $up = strtr(TILE_URL, [
        '{s}' => ['a', 'b', 'c'][($x + $y) % 3],
        '{style}' => $style, '{z}' => (string)$z, '{x}' => (string)$x, '{y}' => (string)$y, '{r}' => $r,
    ]);
    $got = fetch_binary($up, 1048576, ['image/png' => 'image/png'], TILE_UA, true);
    if (!$got) {
        if ($bin && is_file($bin)) {
            serve_bytes((string)file_get_contents($bin), 'image/png', 3600);
        }
        http_response_code(404);
        exit;
    }
    // erst jetzt Ordner anlegen: ein Fehlschlag hinterlässt keine leeren Reste.
    // 0755/0644 im öffentlichen Cache – Apache läuft oft als anderer Benutzer
    // als PHP und käme sonst nicht an die eigenen Dateien.
    $angelegt = $pub ? mkdir_pub($dir) : (is_dir($dir) || @mkdir($dir, 0700, true));
    if ($bin && $angelegt) {
        $tmp = $dir . '/.' . $y . $r . '.' . getmypid() . '.' . random_int(1000, 9999) . '.tmp';
        $wrote = @file_put_contents($tmp, $got[0]);
        if ($wrote === strlen($got[0]) && @rename($tmp, $bin)) {
            if ($pub) {
                @chmod($bin, 0644);
            }
        } else {
            @unlink($tmp);
        }
        if (random_int(1, 50) === 1) {
            // Liefert Apache direkt aus, läuft die Verfallsprüfung oben nie –
            // TILE_TTL muss deshalb hier durchgesetzt werden.
            cache_prune($dir, TILE_KEEP, TILE_TTL);
        }
    }
    serve_bytes($got[0], $got[1], TILE_TTL);
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
        $fehlt = $ext === 'gd'
            ? 'fehlt (optional, nur fürs App-Icon; Ubuntu: sudo apt install php-gd)'
            : 'FEHLT';
        echo $ext . ': ' . (extension_loaded($ext) ? 'ok' : $fehlt) . "\n";
    }
    $rl = region_list();
    [, $src] = connector_root();
    echo 'connector-quelle: ' . (local_mode() ? 'data/connector (Einzelregion)' : $src . '/')
        . ($src === 'data/connectors' ? ' – per ?sync von ' . CONNECTOR_REPO : '') . "\n";
    $stamp = __DIR__ . '/data/connectors.stamp';
    if (is_file($stamp)) {
        echo 'letzter sync: ' . date('d.m.Y H:i', (int)filemtime($stamp)) . "\n";
    }
    $canSync = class_exists('ZipArchive') || class_exists('PharData');
    echo 'sync-fähig: ' . ($canSync ? 'ja (' . (class_exists('ZipArchive') ? 'zip' : 'tar.gz/phar') . ')'
        : 'nein – weder zip noch phar, dann connectors/ per FTP hochladen') . "\n";
    echo 'admin/sync: ' . (admin_key() === '' ? 'aus (data/admin.key fehlt)' : 'aktiv') . "\n";
    // Alles über die eigene Domain?
    $vsrc = vendor_file('leaflet.js');
    echo 'leaflet: ' . ($vsrc ? 'eigene Domain (' . (strpos($vsrc, '/data/') !== false ? 'data/vendor' : 'vendor') . ')'
        : 'FEHLT – fällt auf unpkg.com zurück ('
          . (@is_writable(__DIR__) ? 'der nächste Connector-Abgleich holt es nach data/vendor'
              : 'heilt sich, sobald der skriptordner beschreibbar ist – s. unten') . ')') . "\n";
    $du = function (string $sub) {
        // erst im öffentlichen Cache nachsehen, dort landet jetzt das meiste
        $d = pub_cache_dir() !== null ? pub_cache_dir() . '/' . $sub : '';
        if ($d === '' || !is_dir($d)) {
            $c = cache_file('x');
            $d = $c ? dirname($c) . '/' . $sub : '';
        }
        if (!$d || !is_dir($d)) {
            return '0 Dateien';
        }
        $n = $b = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) {
                $n++;
                $b += $f->getSize();
            }
        }
        return $n . ' Dateien, ' . round($b / 1048576, 1) . ' MB';
    };
    echo 'bild-proxy: ' . (PROXY_IMAGES ? 'an' : 'aus') . ', Cache ' . $du('img') . "\n";
    echo 'kachel-proxy: ' . (!PROXY_TILES ? 'aus (fest)'
        : (host_limited() ? 'AUS – Gratis-Hoster erkannt, Kacheln kommen direkt vom CDN (Konto-Schutz)'
            : 'an')) . ', Cache ' . $du('tile') . "\n";
    // Der gefährliche Zustand ist "läuft halb": nichts sieht kaputt aus,
    // aber jede Kachel kostet weiterhin einen PHP-Prozess.
    $warum = [];
    if (pub_cache_dir() === null) {
        $warum[] = 'cache/ nicht beschreibbar';
    }
    if (!can_rewrite()) {
        $warum[] = server_kind() === 'caddy'
            ? 'NCM_REWRITE fehlt – Caddyfile nicht aktiv'
            : (server_kind() === 'apache' ? 'mod_rewrite/AllowOverride fehlt'
                : 'Webserver schreibt fehlende Kacheln nicht auf die index.php um');
    }
    echo 'statische auslieferung: ' . (tile_static() ? 'an – der Webserver liefert gecachte Kacheln selbst aus'
        : 'aus (' . (implode(', ', $warum) ?: 'kachel-proxy aus') . '), alles läuft über PHP') . "\n";
    if (!tile_static() && $warum) {
        if (pub_cache_dir() === null) {
            echo "  fix: skriptordner beschreibbar machen (s. unten), cache/ entsteht dann von selbst\n";
        }
        if (!can_rewrite()) {
            $srv = server_kind();
            if ($srv === 'caddy') {
                echo "  fix: den mitgelieferten Caddyfile verwenden – er liefert gecachte Kacheln\n"
                   . "  direkt aus und setzt env NCM_REWRITE 1; danach  caddy reload\n";
            } elseif ($srv === 'apache') {
                echo "  fix: sudo a2enmod rewrite  und im Apache-vHost  AllowOverride All  für dieses\n"
                   . "  Verzeichnis setzen, dann  sudo systemctl reload apache2\n";
            } else {
                echo "  fix: Caddy → mitgelieferten Caddyfile nutzen; Apache → mod_rewrite +\n"
                   . "  AllowOverride All (Beispiele in APP.md)\n";
            }
        }
    }
    echo 'kachel-schutz: ' . (tile_token() === '' ? 'keiner (ohne Schlüssel kein Token'
          . (@is_writable(__DIR__) ? '' : ' – data/secret.key entsteht von selbst, sobald der skriptordner beschreibbar ist') . ')'
        : 'Nachladen nur mit Herkunft von der eigenen Domain; bereits '
          . 'zwischengespeicherte Kacheln liefert der Webserver an jeden aus') . "\n";
    echo 'modus: ' . (local_mode() ? 'lokal (/data/connector)' : 'regionen (' . (implode(', ', $rl) ?: 'keine') . ')') . "\n";
    $dcc = region() !== '' ? region() : (local_mode() ? '' : ($rl[0] ?? ''));
    $t = load_connectors($dcc);
    echo 'connectoren: ' . count($t[0]) . ' Clubs, ' . count($t[1]) . " mit Scrape-URL\n";
    if (!count($t[0]) && !local_mode()) {
        echo @is_writable(__DIR__)
            ? "  (leer – Seite einmal im Browser aufrufen, sie holt die Clubs selbst aus dem Repo)\n"
            : "  (leer – entweder connectors/ aus dem Repo neben die index.php hochladen ODER den\n"
            . "  skriptordner beschreibbar machen, dann holt die Seite die Clubs selbst; s. unten)\n";
    }
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
    $ob = (string)ini_get('open_basedir');
    echo 'host-merkmale: posix ' . (function_exists('posix_getpid') ? 'da' : 'FEHLT')
        . ', open_basedir ' . ($ob !== '' ? 'gesetzt' : 'nein')
        . ', allow_url_fopen ' . (ini_get('allow_url_fopen') ? 'an' : 'aus') . "\n";
    echo 'host-typ: ' . (host_limited()
        ? 'eingeschränkter Gratis-Hoster – Kachel-Proxy automatisch aus (Konto-Schutz)'
        : 'voll (Kachel-Proxy erlaubt)') . "\n";
    if (@is_writable(__DIR__)) {
        echo "skriptordner: beschreibbar\n";
    } else {
        // Ohne beschreibbaren Ordner: kein Connector-Abgleich, kein data/vendor,
        // kein Schlüssel, kein ?sync. Events/Bilder cachen solange nach /tmp.
        $wer = function_exists('posix_geteuid')
            ? ((posix_getpwuid(posix_geteuid())['name'] ?? '') ?: get_current_user())
            : get_current_user();
        echo 'skriptordner: NICHT beschreibbar – Connector-Abgleich, data/ und ?sync gehen nicht,'
            . " Events/Bilder cachen nur nach /tmp (weg bei Neustart)\n"
            . '  fix (eigener Server): sudo chown -R ' . $wer . ': ' . __DIR__ . "\n"
            . "  danach Seite neu aufrufen – Clubs, Leaflet und Schlüssel richten sich selbst ein\n";
    }
    $disabled = array_map('trim', explode(',', strtolower((string)ini_get('disable_functions'))));
    $stl = in_array('set_time_limit', $disabled, true) ? 'gesperrt' : 'nutzbar';
    if (function_exists('curl_init')) {
        // gleicher Modus wie der Scraper (SSL-tolerant, Browser-UA)
        $ch = curl_init('https://www.muffatwerk.de/de/veranstaltungen');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36']);
        $b = curl_exec($ch);
        echo 'outbound-test (einfach): ' . ($b !== false && $b !== '' ? strlen($b) . ' Bytes ok' : 'FEHLER: ' . curl_error($ch)) . "\n";
        echo 'curl_multi: ' . (function_exists('curl_multi_init') ? 'vorhanden' : 'FEHLT (nutze Einzelabruf)') . "\n";
        // Scrapen läuft im Vordergrund (Ladebalken beim ersten Aufruf, dann
        // je Club beim Öffnen) – kein Hintergrundlauf nötig, läuft überall.
        echo 'scrape-modell: Ladebalken einmalig, dann je Club beim Öffnen' . "\n";
        echo 'max_execution_time: ' . ((int)ini_get('max_execution_time') ?: 'unbegrenzt')
            . ', set_time_limit ' . ($stl ?? '?') . "\n";
        // Wie weit ist der Scraper? getrennt zeigen: versucht vs. mit Inhalt
        $nTried = $nBody = 0;
        foreach ((array)($dc['data'] ?? []) as $e) {
            if (!empty($e['ft'])) { $nTried++; }
            if (!empty($e['events']) || !empty($e['images']) || !empty($e['info'])) { $nBody++; }
        }
        echo 'scrape-fortschritt: ' . $nTried . ' von ' . count($t[1]) . ' geladen, '
            . $nBody . " mit Inhalt (Ladebalken bis alle durch sind)\n";
        // Zwei getrennte Live-Tests, jeder für sich abgesichert, damit ?diag
        // NIE mittendrin abbricht: erst Einzel-curl, dann curl_multi.
        $dt = array_slice($t[1], 0, 2, true);
        $one = $dt ? array_slice($dt, 0, 1, true) : [];
        try {
            if ($one) {
                $r1 = fetch_seq(array_map(fn($c) => $c['url'], $one), [], time() + 8);
                foreach ($one as $id => $cfg) {
                    $x = $r1[$id] ?? null;
                    $len = $x && $x['body'] !== '' ? strlen($x['body']) : 0;
                    echo '  einzel-curl ' . str_pad(substr($id, 0, 14), 15)
                        . ($len ? $len . ' Bytes ok' : 'FEHLER: ' . ($x['err'] ?? 'keine Antwort')) . "\n";
                }
            }
        } catch (Throwable $e) {
            echo '  einzel-curl: Ausnahme ' . $e->getMessage() . "\n";
        }
        if (function_exists('curl_multi_init') && $dt) {
            try {
                // direkt curl_multi testen, ohne den seq-Notnagel von fetch_all
                $mh = curl_multi_init();
                $hs = [];
                $bd = $ov = $mt = [];
                foreach ($dt as $id => $cfg) {
                    $ch = curl_init($cfg['url']);
                    curl_setopt_array($ch, curl_opts($id, [], $bd, $ov, $mt, 8));
                    curl_multi_add_handle($mh, $ch);
                    $hs[$id] = $ch;
                }
                $t0 = time();
                do {
                    curl_multi_exec($mh, $run);
                    if ($run > 0 && curl_multi_select($mh, 0.2) === -1) { usleep(50000); }
                } while ($run > 0 && time() - $t0 < 10);
                $got = 0;
                foreach ($hs as $id => $ch) {
                    if (($bd[$id] ?? '') !== '') { $got++; }
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                }
                curl_multi_close($mh);
                echo '  curl_multi-test: ' . $got . ' von ' . count($dt) . ' geliefert'
                    . ($got === 0 ? ' – curl_multi bringt hier nichts, Scraper nutzt Einzelabruf' : '') . "\n";
            } catch (Throwable $e) {
                echo '  curl_multi-test: Ausnahme ' . $e->getMessage() . "\n";
            }
        }
        echo "  (einzel-curl ok + curl_multi 0 -> der Scraper stellt automatisch\n   auf Einzelabruf um; einzel-curl FEHLER -> Hoster blockt Club-Seiten)\n";
    }


    // Was kostet ein Besuch den Hoster? (InfinityFree & Co.: ~50.000
    // Zugriffe/Tag, ~10 gleichzeitige PHP-Prozesse, ~30.000 Dateien)
    $perVisit = 1;                       // die Seite selbst
    $perVisit += have_vendor() ? 2 : 0;  // leaflet.js + .css (einmal, dann 1 Jahr im Browser-Cache)
    $phpPerVisit = 1;
    if (tile_proxy_on()) {
        $perVisit += 100;                // rund 100 Kacheln je Kartenansicht
        // Liefert Apache die Kacheln direkt aus, kostet nur der allererste
        // Abruf je Kachel einen PHP-Prozess – danach keinen mehr.
        $phpPerVisit += tile_static() ? 0 : 100;
    }
    $dConn = $t[1];
    $pend = 0;
    foreach ($dConn as $cid => $c) {
        if ((int)($dc['data'][$cid]['ft'] ?? 0) === 0) {
            $pend++;
        }
    }
    $poll = $pend > 0 ? 25 : 1;          // Nachlade-Anfragen, mit wachsendem Abstand
    echo "\n-- Hoster-Limits --\n";
    echo 'zugriffe je Besuch: ~' . ($perVisit + $poll) . ' (davon PHP: ~' . ($phpPerVisit + $poll) . ')'
        . ($pend > 0 ? ' – solange die Erstbefüllung läuft' : '') . "\n";
    echo 'bei 50.000 Zugriffen/Tag reicht das für ~' . (int)floor(50000 / max(1, $perVisit + $poll)) . " Besuche/Tag\n";
    $inodes = 0;
    $cbase = cache_file('x') ? dirname(cache_file('x')) : '';
    if ($cbase && is_dir($cbase)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cbase, FilesystemIterator::SKIP_DOTS)) as $f) {
            $inodes++;
        }
    }
    $pubRoot = pub_cache_dir();
    if ($pubRoot !== null && is_dir($pubRoot)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pubRoot, FilesystemIterator::SKIP_DOTS)) as $f) {
            $inodes++;
        }
    }
    echo 'dateien im cache: ' . $inodes . ' (Bilder ab ' . IMG_KEEP . ', Kacheln ab '
        . TILE_KEEP . ' je Spalte; älter als die Frist räumt der Hintergrund-Ping weg)' . "\n";
    echo 'kacheln: ' . (!tile_proxy_on()
        ? 'direkt vom Karten-CDN – kostet den eigenen Server nichts'
          . (PROXY_TILES && host_limited() ? ' (Proxy wegen Gratis-Hoster automatisch aus)' : '')
        : (tile_static()
            ? 'über den eigenen Server, gecachte liefert der Webserver ohne PHP aus'
            : 'über den eigenen Server, jede einzelne durch PHP'
              . (host_limited() ? ' – auf Gratis-Hostern schnell gesperrt'
                  : ' (mit dem Caddyfile/mod_rewrite liefe das ohne PHP, s. „statische auslieferung")'))) . "\n";
    exit;
}
if (isset($_GET['update'])) {
    // Abgleich mit dem Repo: hat sich dort ein Connector geändert (oder kam
    // einer dazu / fiel weg), wird der Ladebalken neu scharfgestellt – er
    // holt dann dank der ck-Regel nur die Neuen und Geänderten. Der Browser
    // pingt das einmal je Seitenaufruf an; gearbeitet wird höchstens alle
    // UPDATE_EVERY Sekunden, alles dazwischen ist ein billiger Sofort-Exit.
    header('Content-Type: application/json; charset=utf-8');
    $cc = region();
    $base = cache_file('x');
    $stamp = $base ? dirname((string)$base) . '/update-check.stamp' : null;
    $faellig = $stamp !== null
        && (!is_file($stamp) || time() - (int)filemtime($stamp) > UPDATE_EVERY || !empty($_GET['force']));
    if (!$faellig) {
        echo json_encode(['update' => false, 'checked' => false]);
        exit;
    }
    @touch($stamp);
    @chmod($stamp, 0644);
    // Im Sync-Modus frisch von GitHub holen. Liegen die Connectoren als
    // Dateien neben der index.php (FTP/git), erkennt der Fingerabdruck-
    // Vergleich darunter deren Änderungen genauso – nur ohne Download.
    if (CONNECTOR_AUTO && connector_root()[1] === CONNECTOR_DIRS[0]) {
        @set_time_limit(120);
        sync_connectors();
    }
    [, $conn] = load_connectors($cc);
    $fp = conn_fingerprint($conn);
    $alt = setup_done_fp($cc);
    // Nur wenn ein fertiger Durchlauf existiert UND der Stand abweicht.
    // Läuft gerade einer (kein done-Stempel), nichts anfassen.
    $update = $conn && $alt !== null && $alt !== $fp;
    if ($update) {
        // Beide Stempel erneuern: bliebe der alte Startzeitpunkt stehen,
        // gälte ein eben erst geänderter Club über sein altes ft als
        // erledigt und würde nie neu geholt.
        foreach (['done', 'start'] as $k) {
            $f = setup_file($cc, $k);
            if ($f !== null && is_file($f)) {
                @unlink($f);
            }
        }
    }
    echo json_encode(['update' => $update, 'checked' => true]);
    exit;
}
if (isset($_GET['setup'])) {
    // Erstbefüllung in Häppchen. Der Ladebalken ruft das so lange auf, bis
    // alle Clubs einmal durch sind. Kein Ratelimit – nur die PHP-Laufzeit
    // begrenzt, was ein Aufruf schafft; der Rest kommt beim nächsten.
    header('Content-Type: application/json; charset=utf-8');
    $cc = region();
    [, $conn] = load_connectors($cc);
    $ganz = count($conn);
    // "Weiter zur Karte": der Nutzer bricht ab – dann nicht bei jedem Aufruf
    // erneut mit dem Balken nerven.
    if (isset($_GET['skip'])) {
        // Nur abschalten, wenn wirklich ein Durchlauf begonnen hat – sonst
        // wäre ein einzelner Aufruf von aussen ein Aus-Schalter für alles.
        $sf = setup_file($cc, 'start');
        if ($sf === null || !is_file($sf)) {
            echo json_encode(['done' => 0, 'withData' => 0, 'total' => $ganz, 'moved' => 0, 'fertig' => false]);
            exit;
        }
        setup_finish($cc, conn_fingerprint($conn));
        echo json_encode(['done' => $ganz, 'withData' => scrape_done($cc, $conn)[1],
            'total' => $ganz, 'moved' => 0, 'fertig' => true]);
        exit;
    }
    if (!$conn) {
        // Nichts zu tun – aber NICHT als erledigt abstempeln, sonst kommt der
        // Ladebalken nie wieder, falls die Connectoren gleich da sind.
        echo json_encode(['done' => 0, 'withData' => 0, 'total' => 0, 'moved' => 0, 'fertig' => false]);
        exit;
    }
    @set_time_limit(0); // wo erlaubt; sonst greift der Hoster-Deckel, dann eben weniger
    $start = setup_begin($cc);
    if ($start === null) {
        // Kein beschreibbarer Stempel -> der Durchlauf ließe sich nicht
        // verfolgen. Ehrlich melden statt endlos zu drehen.
        echo json_encode(['done' => 0, 'withData' => scrape_done($cc, $conn)[1],
            'total' => $ganz, 'moved' => 0, 'fertig' => false, 'kaputt' => true]);
        exit;
    }
    // Erledigt ist ein Club, wenn er in DIESEM Durchlauf angefasst wurde
    // (ft >= Start; auch ein Fehlschlag zählt, sonst steht der Balken ewig) –
    // ODER wenn ein gelungener Scrape mit unveränderter Konfiguration
    // vorliegt (gespeicherte ck passt). Zweiteres macht Updates billig: nach
    // einer Repo-Änderung läuft der Balken nur über Neue und Geänderte, der
    // Rest zählt sofort.
    $erledigt = function (array $e, array $cfg, int $start): bool {
        if ((int)($e['ft'] ?? 0) >= $start) {
            return true;
        }
        return ($e['ck'] ?? '') !== '' && ($e['ck'] ?? '') === md5(json_encode($cfg));
    };
    $zaehl = function (array $conn, int $start) use ($cc, $erledigt): array {
        $data = cache_read($cc)['data'] ?? [];
        $fertig = $mit = 0;
        foreach ($conn as $id => $cfg) {
            $e = $data[$id] ?? [];
            if ($erledigt($e, $cfg, $start)) {
                $fertig++;
            }
            if (!empty($e['events']) || !empty($e['images']) || !empty($e['info'])) {
                $mit++;
            }
        }
        return [$fertig, $mit, $data];
    };
    [$vorher, , $data] = $zaehl($conn, $start);
    $offen = [];
    foreach ($conn as $id => $cfg) {
        if (!$erledigt($data[$id] ?? [], $cfg, $start)) {
            $offen[$id] = $cfg;
        }
    }
    $busy = false;
    if ($offen) {
        // $force: sonst würgt die 180-Sekunden-Drosselung den Durchlauf ab.
        // $keep: sonst wirft der Teil-Lauf alle anderen Clubs aus dem Cache.
        scrape_refresh($data, $offen, SETUP_BATCH, $cc, SETUP_SECS, true, $conn, $busy);
    }
    [$fertig, $mitDaten] = $zaehl($conn, $start);
    // $ganz > 0: sonst wäre eine leere Liste sofort "fertig" und der Stempel
    // stünde für immer, ohne dass je ein Club geholt wurde.
    if ($ganz > 0 && $fertig >= $ganz) {
        setup_finish($cc, conn_fingerprint($conn));
    }
    echo json_encode([
        'done' => $fertig,
        'withData' => $mitDaten,
        'total' => $ganz,
        'moved' => $fertig - $vorher, // 0 = kein Fortschritt -> Balken hört auf
        // Ein anderer Besucher hält gerade die Sperre – das ist KEIN Stillstand
        'busy' => $busy,
        'fertig' => $ganz > 0 && $fertig >= $ganz,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (isset($_GET['fresh'])) {
    // Eine einzelne Kachel frisch holen – passiert beim Öffnen eines Clubs.
    // Ein Club = ein Abruf, das passt immer ins Laufzeitbudget.
    // Bewusst NICHT ?club= – das ist der Deeplink, mit dem die Seite selbst
    // einen Club in der Adresse teilt; der muss die Karte liefern, kein JSON.
    header('Content-Type: application/json; charset=utf-8');
    $cc = region();
    [, $conn] = load_connectors($cc);
    $id = (string)$_GET['fresh'];
    $out = null;
    if (isset($conn[$id])) {
        $cache = cache_read($cc);
        $ft = (int)($cache['data'][$id]['ft'] ?? 0);
        // nur wirklich neu holen, wenn die letzte Aktualisierung her ist –
        // sonst reicht der Cache und die Kachel geht ohne Wartezeit auf
        if (time() - $ft >= CLUB_FRESH) {
            @set_time_limit(0);
            scrape_refresh($cache['data'] ?? [], [$id => $conn[$id]], 1, $cc, CLUB_SECS, true, $conn);
            $cache = cache_read($cc);
        }
        $lp = live_payload([$id => ($cache['data'][$id] ?? [])]);
        $out = $lp[$id] ?? null;
    }
    echo json_encode(['id' => $id, 'live' => $out],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    exit;
}if (isset($_GET['check'])) {
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
/*
 * Öffnungszeiten streng nach SPEC.md Abschnitt 7 prüfen. parse_hours() ist
 * der tolerante Leser der Karte – zum Anlegen muss es exakt stimmen.
 */
function hours_strict(string $s): array
{
    $days = ['Mo' => 1, 'Di' => 2, 'Mi' => 3, 'Do' => 4, 'Fr' => 5, 'Sa' => 6, 'So' => 7];
    $err = [];
    $used = [];
    if (trim($s) !== $s) {
        $err[] = 'Leerzeichen am Rand';
    }
    foreach (explode(';', $s) as $i => $raw) {
        $blk = trim($raw);
        if ($i > 0 && substr($raw, 0, 1) !== ' ') {
            $err[] = 'Blöcke mit "; " trennen';
        }
        if ($blk === '') {
            $err[] = 'leerer Block';
            continue;
        }
        if (!preg_match('#^([A-Za-z,\-]+) (\d{2}):(\d{2})-(\d{2}):(\d{2})$#', $blk, $m)) {
            $err[] = '"' . $blk . '" passt nicht auf: Tage HH:MM-HH:MM';
            continue;
        }
        if ((int)$m[2] > 23 || (int)$m[3] > 59 || (int)$m[4] > 23 || (int)$m[5] > 59) {
            $err[] = 'Uhrzeit gibt es nicht in "' . $blk . '"';
        }
        if ($m[2] . $m[3] === $m[4] . $m[5]) {
            $err[] = 'Öffnen und Schließen gleich in "' . $blk . '"';
        }
        foreach (explode(',', $m[1]) as $tok) {
            $list = [];
            if (isset($days[$tok])) {
                $list[] = $days[$tok];
            } elseif (preg_match('#^(\w\w)-(\w\w)$#', $tok, $r) && isset($days[$r[1]], $days[$r[2]])) {
                for ($d = $days[$r[1]], $g = 0; $g < 8; $d = $d % 7 + 1, $g++) {
                    $list[] = $d;
                    if ($d === $days[$r[2]]) {
                        break;
                    }
                }
            } else {
                $err[] = 'unbekannter Tag "' . $tok . '"';
                continue;
            }
            foreach ($list as $d) {
                if (isset($used[$d])) {
                    $err[] = 'Tag ' . array_search($d, $days, true) . ' kommt mehrfach vor';
                }
                $used[$d] = true;
            }
        }
    }
    return $err;
}

/*
 * Eine Zeile des Massen-Imports zerlegen und gegen den Standard prüfen.
 * Format (mit | oder Tab getrennt):
 *   URL | Name | Stadt | Bundesland | Adresse | lat | lng | Genres [| hours]
 * Rückgabe: ['ok'=>bool, 'err'=>[…], 'y'=>[Feld=>Wert], 'land'=>…]
 */
function bulk_parse(string $line, string $cc): array
{
    $err = [];
    $f = preg_split('#\s*\|\s*|\t+#', trim($line));
    $f = array_map('trim', (array)$f);
    if (count($f) < 8) {
        return ['ok' => false, 'err' => ['zu wenige Felder (' . count($f) . ' von mindestens 8)'], 'y' => []];
    }
    [$url, $name, $city, $land, $addr, $lat, $lng, $genres] = array_slice($f, 0, 8);
    $hours = $f[8] ?? '';

    if (!preg_match('#^https?://#i', $url)) {
        $err[] = 'Website ist keine http(s)-Adresse';
    }
    if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
        $err[] = 'Name muss 2–60 Zeichen haben';
    }
    if ($city === '') {
        $err[] = 'Stadt fehlt';
    }
    $land = admin_area_slug($land);
    if (!isset(BBOX[$cc])) {
        $err[] = 'unbekanntes Land "' . $cc . '" – erlaubt: ' . implode(', ', array_keys(BBOX));
    } elseif (!in_array($land, OK_AREAS[$cc], true)) {
        $err[] = 'unbekanntes Bundesland "' . $land . '" für ' . $cc;
    }
    if (mb_strlen($addr) < 4 || mb_strlen($addr) > 60) {
        $err[] = 'Adresse muss 4–60 Zeichen haben (nur Straße + Hausnummer)';
    }
    if (preg_match('/\b\d{5}\b/', $addr)) {
        $err[] = 'Adresse enthält eine Postleitzahl';
    }
    foreach (['lat' => $lat, 'lng' => $lng] as $k => $v) {
        if (!preg_match('/^-?\d{1,3}\.\d{3,6}$/', $v)) {
            $err[] = $k . ' braucht 3–6 Nachkommastellen (bekommen: "' . $v . '")';
        }
    }
    $bb = BBOX[$cc] ?? null;
    if ($bb && !$err) {
        if ((float)$lat < $bb[0] || (float)$lat > $bb[1] || (float)$lng < $bb[2] || (float)$lng > $bb[3]) {
            $err[] = 'Koordinate liegt außerhalb von ' . $cc . ' – lat und lng vertauscht?';
        }
    }
    $gs = [];
    foreach (preg_split('#\s*,\s*#', $genres) as $g) {
        if ($g === '') {
            continue;
        }
        $hit = null;
        foreach (OK_GENRES as $ok) {
            if (strcasecmp($ok, $g) === 0) {
                $hit = $ok;
            }
        }
        if ($hit === null) {
            $err[] = 'unbekannte Musikrichtung "' . $g . '"';
        } elseif (!in_array($hit, $gs, true)) {
            $gs[] = $hit;
        }
    }
    if (!$gs && !$err) {
        $err[] = 'mindestens eine Musikrichtung nötig';
    }
    if (count($gs) > 3) {
        $err[] = 'höchstens 3 Musikrichtungen';
    }
    if (count($gs) > 1 && in_array('Mixed', $gs, true)) {
        $err[] = '"Mixed" steht allein oder gar nicht';
    }
    foreach ($hours !== '' ? hours_strict($hours) : [] as $he) {
        $err[] = 'Öffnungszeiten: ' . $he;
    }
    $id = admin_slug($name);
    if (!preg_match('/^[a-z0-9]{2,40}$/', $id) || $id === 'club') {
        $err[] = 'aus "' . $name . '" lässt sich keine brauchbare id bilden – bitte umbenennen';
    }
    return ['ok' => !$err, 'err' => $err, 'land' => $land, 'y' => [
        'id' => $id, 'name' => $name, 'city' => $city, 'address' => $addr,
        'lat' => $lat, 'lng' => $lng, 'website' => $url, 'genres' => implode(', ', $gs),
        'hours' => $hours,
    ]];
}

/* Aus den geprüften Feldern die YAML nach SPEC.md bauen */
function bulk_yaml(array $y, string $imgMode, string $evMode, string $infoMode): string
{
    $out = ['# ' . $y['name'] . ' – ' . $y['city']];
    foreach (['id', 'name', 'city', 'address', 'lat', 'lng', 'website', 'genres', 'hours'] as $k) {
        if (($y[$k] ?? '') !== '') {
            $out[] = $k . ': ' . admin_yaml_val((string)$y[$k]);
        }
    }
    $out[] = 'checked: ' . date('Y-m');
    $out[] = '';
    $out[] = '# Wie die Website ausgelesen wird';
    $out[] = 'scrape_url: ' . admin_yaml_val((string)$y['website']);
    $out[] = 'scrape_events: ' . $evMode;
    $out[] = 'scrape_images: ' . $imgMode;
    $out[] = 'scrape_info: ' . $infoMode;
    $out[] = 'scrape_closed: auto';
    return implode("\n", $out) . "\n";
}

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
    $useZip = class_exists('ZipArchive');
    $usePhar = !$useZip && class_exists('PharData');
    if (!$useZip && !$usePhar) {
        return [false, 'Weder zip noch phar auf diesem Hoster – Ordner connectors/ aus dem Repo bitte per FTP neben die index.php legen.'];
    }
    if (!function_exists('curl_init')) {
        return [false, 'curl fehlt – ohne das kommt der Server nicht an GitHub.'];
    }
    $stamp = $data . '/connectors.stamp';
    if (is_file($stamp) && time() - (int)filemtime($stamp) < 300 && empty($_GET['force'])) {
        return [false, 'Vor weniger als 5 Minuten schon geholt. Mit &force=1 trotzdem.'];
    }
    @touch($stamp);

    $fmt = $useZip ? 'zip' : 'tar.gz';           // tar.gz braucht nur phar, kein zip
    $zipFile = $data . '/connectors-' . substr(md5((string)mt_rand()), 0, 8) . '.' . $fmt;
    $url = 'https://codeload.github.com/' . CONNECTOR_REPO . '/' . $fmt . '/refs/heads/' . CONNECTOR_BRANCH;
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

    $new = $data . '/connectors.new';
    rm_tree($new);
    $count = 0;
    $bytes = 0;
    $vend = 0;
    rm_tree($data . '/vendor.new');
    // Ein Eintrag im Archiv -> eine Datei unter $new, mit denselben Grenzen für
    // beide Formate. $inner ist der Pfad im Archiv (z. B. repo-main/connectors/…).
    $write = function (string $inner, int $size, callable $read) use (&$count, &$bytes, &$vend, $new, $data) {
        // Connectoren: nur connectors/<…>.yaml – keine Pfade nach oben
        $isYaml = (bool)preg_match('#^[^/]+/connectors/((?:[A-Za-z0-9][A-Za-z0-9_-]*/)+[A-Za-z0-9][A-Za-z0-9_-]*\.yaml)$#', $inner, $m);
        // Leaflet & Co. aus vendor/ – feste Namensliste, sonst nichts
        $isVend = (bool)preg_match('#^[^/]+/vendor/(?:images/)?(leaflet\.(?:js|css)|[a-z0-9-]+\.png)$#', $inner, $v);
        if (!$isYaml && !$isVend) {
            return;
        }
        if ($isVend) {
            if ($size > 1048576) {
                return;
            }
            $dstv = $data . '/vendor.new/' . basename($v[1]);
            if (!is_dir(dirname($dstv)) && !@mkdir(dirname($dstv), 0755, true)) {
                return;
            }
            $bodyv = $read();
            if ($bodyv !== false && @file_put_contents($dstv, $bodyv) !== false) {
                $vend++;
            }
            return;
        }
        if ($size > 65536 || $count >= 20000 || $bytes > 67108864) {
            return;
        }
        $bytes += $size;
        $dst = $new . '/' . $m[1];
        if (!is_dir(dirname($dst)) && !@mkdir(dirname($dst), 0755, true)) {
            return;
        }
        $body = $read();
        if ($body !== false && @file_put_contents($dst, $body) !== false) {
            $count++;
        }
    };

    if ($useZip) {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            @unlink($zipFile);
            return [false, 'Das Archiv von GitHub ließ sich nicht öffnen.'];
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st) {
                $write($st['name'], (int)$st['size'], fn() => $zip->getFromIndex($i));
            }
        }
        $zip->close();
    } else {
        // tar.gz ohne zip-Erweiterung: PharData ist fast überall vorhanden
        try {
            $phar = new PharData($zipFile);
            $pfx = 'phar://' . str_replace('\\', '/', $zipFile) . '/';
            foreach (new RecursiveIteratorIterator($phar) as $f) {
                $pn = str_replace('\\', '/', $f->getPathname());
                if (strpos($pn, $pfx) === 0) {
                    $write(substr($pn, strlen($pfx)), (int)$f->getSize(), fn() => @file_get_contents($f->getPathname()));
                }
            }
        } catch (Throwable $e) {
            @unlink($zipFile);
            return [false, 'Das tar.gz von GitHub ließ sich nicht öffnen (' . $e->getMessage() . ').'];
        }
    }
    @unlink($zipFile);
    // Leaflet einhängen, sobald vollständig – unabhängig von den Connectoren
    if ($vend >= 2 && is_file($data . '/vendor.new/leaflet.js') && is_file($data . '/vendor.new/leaflet.css')) {
        rm_tree($data . '/vendor.old');
        if (is_dir($data . '/vendor')) {
            @rename($data . '/vendor', $data . '/vendor.old');
        }
        if (!@rename($data . '/vendor.new', $data . '/vendor')) {
            @rename($data . '/vendor.old', $data . '/vendor');
        }
        rm_tree($data . '/vendor.old');
    }
    rm_tree($data . '/vendor.new');
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

/* Sind überhaupt Connectoren da (lokal, im Repo-Ordner oder per Sync geholt)? */
function connectors_present(): bool
{
    return local_mode() || region_list() !== [];
}

/*
 * Selbst-Start: ist die Seite leer und der Server darf ins Netz, holt sie die
 * Clubs beim ersten Aufruf allein aus dem festen Repo – ohne Schlüssel, ohne
 * Handgriff. Nur Apache + PHP + index.php, den Rest macht das Script.
 * Sicher, weil ausschließlich das fest verdrahtete CONNECTOR_REPO geladen wird
 * (TLS-geprüft, auf connectors/<…>.yaml gefiltert, größen- und schrumpfgesichert).
 */
function maybe_bootstrap(): string
{
    // 'filled' = frisch geholt (Aufrufer leitet auf frischen Request um),
    // 'pending' = wird geholt/gerade probiert (Ladeseite), 'cannot' = geht hier nicht.
    if (!CONNECTOR_AUTO || connectors_present()) {
        return 'cannot';
    }
    if (!function_exists('curl_init') || !(class_exists('ZipArchive') || class_exists('PharData'))) {
        return 'cannot';
    }
    $data = __DIR__ . '/data';
    if (!is_dir($data)) {
        @mkdir($data, 0755, true);
    }
    if (!is_dir($data) || !@is_writable($data)) {
        return 'cannot';
    }
    // nicht bei jedem Treffer neu versuchen, falls GitHub gerade klemmt
    $stamp = $data . '/bootstrap.stamp';
    if (is_file($stamp) && time() - (int)filemtime($stamp) < 20) {
        return 'pending';
    }
    @touch($stamp);
    @set_time_limit(180);
    [$ok] = sync_connectors(); // holt NUR das feste Repo
    return $ok ? 'filled' : 'pending';
}

/*
 * Eigenes Geheimnis je Installation – damit signieren wir die Bild-Adressen.
 * Ohne Signatur wäre ?img= ein offener Proxy für beliebige Fremdinhalte.
 */
/* Was der Browser vom Cache sehen darf – Bildadressen laufen über uns. */
function live_payload(array $data): array
{
    $out = [];
    foreach ($data as $id => $e) {
        $e = array_intersect_key((array)$e, ['events' => 1, 'images' => 1, 'info' => 1, 'warn' => 1]);
        if (!empty($e['images']) && is_array($e['images'])) {
            // http->https und Größen-Dubletten (gleicher Pfad, andere Query)
            // hier abräumen – nach dem Signieren ginge das nicht mehr.
            $seen = [];
            $imgs = [];
            foreach ($e['images'] as $u) {
                $u = (string)$u;
                if ($u === '') {
                    continue;
                }
                $u = (string)preg_replace('#^http://#i', 'https://', $u);
                $k = strtok($u, '?');
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $imgs[] = proxy_img($u);
            }
            $e['images'] = $imgs;
        }
        if (!empty($e['events']) && is_array($e['events'])) {
            $e['events'] = array_map(function ($ev) {
                // jsonld_events() speichert unter 'img' – 'image' gibt es nicht
                if (is_array($ev) && !empty($ev['img'])) {
                    $ev['img'] = proxy_img((string)preg_replace('#^http://#i', 'https://', (string)$ev['img']));
                }
                return $ev;
            }, $e['events']);
        }
        $out[$id] = $e;
    }
    return $out;
}

function app_secret(): string
{
    static $sec = null;
    if ($sec !== null) {
        return $sec;
    }
    $f = __DIR__ . '/data/secret.key';
    if (is_file($f)) {
        $sec = trim((string)file_get_contents($f));
        if ($sec !== '') {
            return $sec;
        }
    }
    try {
        $new = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        return $sec = ''; // ohne Zufall kein Schlüssel – lieber gar keiner
    }
    if (!is_dir(__DIR__ . '/data')) {
        @mkdir(__DIR__ . '/data', 0755, true);
    }
    if (@file_put_contents($f, $new) === false) {
        // Nicht speicherbar. Einen aus Pfad und Datum abgeleiteten Schlüssel
        // könnte jeder nachrechnen (die Seite zeigt ja Adresse UND Signatur) –
        // dann wäre ?img= ein offener Proxy. Also lieber ohne Proxy arbeiten.
        return $sec = '';
    }
    @chmod($f, 0600);
    return $sec = $new;
}

function sign_url(string $u): string
{
    return substr(hash_hmac('sha256', $u, app_secret()), 0, 16);
}

/* Adresse -> eigener Link. base64url hält es ohne Server-Register selbsttragend. */
function proxy_img(string $u): string
{
    if (!PROXY_IMAGES || $u === '' || strpos($u, 'data:') === 0 || strpos($u, '?img=') === 0
        || app_secret() === '') {
        return $u; // kein Schlüssel speicherbar -> lieber direkt als unsigniert
    }
    // Liegt das Bild schon im öffentlichen Cache, gibt es die Adresse direkt
    // auf die Datei – Apache liefert sie aus, PHP läuft dafür gar nicht erst
    // an. Eine Signatur braucht dieser Weg nicht: er holt nichts nach,
    // sondern liest nur, was ohnehin schon geprüft auf der Platte liegt.
    $dir = pub_cache_dir('img');
    if ($dir !== null) {
        // Nur ein Blick in die einmal gelesene Ordnerliste – kein
        // Dateizugriff je Bild. Das Verfallsdatum setzt cache_sweep()
        // durch (stündlich beim Hintergrund-Ping); ein Foto höchstens eine
        // Stunde über der Frist zu zeigen ist bei sieben Tagen belanglos.
        $ext = img_index($dir)[img_key($u)] ?? '';
        if ($ext !== '') {
            return url_base() . 'cache/img/' . img_key($u) . '.' . $ext;
        }
    }
    $b = rtrim(strtr(base64_encode($u), '+/', '-_'), '=');
    return '?img=' . $b . '&s=' . sign_url($u);
}

function unproxy_img(string $b64, string $sig): ?string
{
    if (app_secret() === '') {
        return null; // ohne Schlüssel ist keine Signatur prüfbar
    }
    $u = base64_decode(strtr($b64, '-_', '+/'), true);
    if ($u === false || $u === '' || !hash_equals(sign_url($u), $sig)) {
        return null;
    }
    return $u;
}

/*
 * Holt eine Binärdatei sicher: nur http/https, nur Port 80/443, keine
 * privaten Adressen (sonst wäre der Proxy ein Weg ins interne Netz), jeder
 * Umleitungsschritt wird erneut geprüft, harte Größengrenze.
 * Rückgabe [bytes, content-type] oder null.
 */
function fetch_binary(string $url, int $maxBytes, array $okTypes, string $ua = '', bool $strictTls = false): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    for ($hop = 0; $hop < 4; $hop++) {
        $u = parse_url($url);
        if (!$u || empty($u['host'])) {
            return null;
        }
        $scheme = strtolower($u['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $port = (int)($u['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port !== 80 && $port !== 443) {
            return null; // ausdrücklich nur die Web-Ports
        }
        // Namen selbst auflösen und die Verbindung darauf festnageln:
        // sonst könnte zwischen Prüfung und Abruf eine andere IP kommen.
        $ips = @gethostbynamel($u['host']);
        if (!$ips) {
            return null;
        }
        $ip = '';
        foreach ($ips as $cand) {
            if (filter_var($cand, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $ip = $cand;
                break;
            }
        }
        if ($ip === '') {
            return null; // nur private/reservierte Adressen -> nicht anfassen
        }
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, // Umleitungen selbst prüfen
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$u['host'] . ':' . $port . ':' . $ip],
            // Clubserver haben oft kaputte Zertifikate; ein Karten-CDN nicht,
            // dort wird ordentlich geprüft.
            CURLOPT_SSL_VERIFYPEER => $strictTls,
            CURLOPT_SSL_VERIFYHOST => $strictTls ? 2 : 0,
            CURLOPT_USERAGENT => $ua !== '' ? $ua
                : 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36',
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$body, $maxBytes) {
                $body .= $chunk;
                return strlen($body) > $maxBytes ? -1 : strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ct = strtolower(trim(explode(';', (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
        $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        if ($code >= 300 && $code < 400 && $loc !== '') {
            $url = $loc; // nächste Runde prüft die neue Adresse genauso
            continue;
        }
        if ($code !== 200 || $body === '' || strlen($body) > $maxBytes) {
            return null;
        }
        if (!isset($okTypes[$ct])) {
            return null; // svg, html, alles andere: nicht anfassen
        }
        // Entscheidend sind die ersten Bytes, nicht die Kopfzeile: bei
        // statischer Auslieferung bestimmt die Endung den Content-Type, und
        // die soll den wirklichen Inhalt benennen. Ein Server, der ein JPEG
        // als image/png ausgibt, wird damit richtiggestellt statt verworfen –
        // wer aber HTML oder SVG als Bild ausgibt, fliegt raus.
        $real = sniff_image($body);
        if ($real === '' || !isset($okTypes[$real])) {
            return null;
        }
        return [$body, $okTypes[$real]];
    }
    return null;
}

/* Datei mit Cache-Kopfzeilen ausliefern, inkl. 304 bei unverändertem ETag */
function serve_bytes(string $body, string $ct, int $maxAge): void
{
    $etag = '"' . substr(sha1($body), 0, 20) . '"';
    header('Content-Type: ' . $ct);
    header('Cache-Control: public, max-age=' . $maxAge . ', immutable');
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

/*
 * Cache-Ordner klein halten. Gratis-Hoster begrenzen nicht nur den Platz,
 * sondern die ANZAHL Dateien (InfinityFree: ~30.000 inodes gesamt). Läuft der
 * Ordner voll, fliegen die ältesten Dateien raus. Wird nur gelegentlich
 * ausgeführt, damit es nichts kostet.
 */
/*
 * Wie viele Seiten gleichzeitig geholt werden. Die Grenze ist nicht der
 * Hoster, sondern der Speicher: jeder Abruf hält seinen Text bis zu 3 MB im
 * RAM, bei 12 parallel sind das gut 36 MB Spitze. Also am memory_limit
 * ausrichten statt eine Zahl zu raten.
 */
function curl_batch(): int
{
    static $n = 0;
    if ($n > 0) {
        return $n;
    }
    $lim = trim((string)ini_get('memory_limit'));
    if ($lim === '' || $lim === '-1') {
        return $n = 12; // unbegrenzt: trotzdem höflich bleiben
    }
    $mb = (int)$lim;
    $unit = strtoupper(substr($lim, -1));
    if ($unit === 'G') {
        $mb *= 1024;
    } elseif ($unit === 'K') {
        $mb = intdiv($mb, 1024);
    } elseif (ctype_digit(substr($lim, -1))) {
        $mb = intdiv($mb, 1048576); // Angabe in Bytes
    }
    // 3 MB je Abruf, und höchstens die Hälfte des Limits dafür verplanen
    return $n = max(4, min(12, intdiv(max($mb, 0), 6)));
}

/*
 * Räumt den öffentlichen Cache auf. Nötig, weil cache_prune() nur im
 * Fehltreffer-Zweig läuft: sobald alles zwischengespeichert ist, gibt es
 * keine Fehltreffer mehr – und damit ohne diesen Durchgang auch nie wieder
 * eine Aufräumung. Liefert Apache die Dateien direkt aus, kommt PHP an der
 * Frist ohnehin nicht mehr vorbei.
 * Läuft höchstens einmal je Stunde und nur beim Hintergrund-Ping.
 */
function cache_sweep(): void
{
    $root = pub_cache_dir();
    if ($root === null) {
        return;
    }
    $stamp = $root . '/.sweep';
    if (is_file($stamp) && time() - (int)@filemtime($stamp) < 3600) {
        return;
    }
    @touch($stamp);
    @chmod($stamp, 0644);
    foreach (['img' => IMG_TTL, 'tile' => TILE_TTL] as $sub => $ttl) {
        $d = $root . '/' . $sub;
        if (!is_dir($d)) {
            continue;
        }
        $now = time();
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $path = $f->getPathname();
            if ($f->isDir()) {
                @rmdir($path); // greift nur, wenn leer – sonst zählen Ordner ewig mit
                continue;
            }
            if (!$f->isFile()) {
                continue;
            }
            $age = $now - (int)@filemtime($path);
            // Übriggebliebene Zwischendateien eines abgebrochenen Laufs
            if (substr($f->getFilename(), -4) === '.tmp') {
                if ($age > 3600) {
                    @unlink($path);
                }
                continue;
            }
            if ($age > $ttl) {
                @unlink($path);
            }
        }
    }
}

function cache_prune(string $dir, int $maxFiles, int $maxAge = 0): void
{
    $files = glob($dir . '/*') ?: [];
    if (count($files) <= $maxFiles && $maxAge === 0) {
        return;
    }
    $byAge = [];
    $now = time();
    foreach ($files as $f) {
        if (!is_file($f)) {
            continue;
        }
        $mt = (int)@filemtime($f);
        // Liefert Apache die Datei direkt aus, kommt PHP nie mehr am
        // Verfallsdatum vorbei – also muss es hier durchgesetzt werden.
        if ($maxAge > 0 && $now - $mt > $maxAge) {
            @unlink($f);
            continue;
        }
        $byAge[$f] = $mt;
    }
    if (count($byAge) <= $maxFiles) {
        return;
    }
    asort($byAge); // älteste zuerst
    $drop = count($byAge) - $maxFiles;
    foreach (array_keys($byAge) as $f) {
        if ($drop-- <= 0) {
            break;
        }
        @unlink($f);
    }
}

/* Ordner im Cache, z. B. data/cache/img – null wenn nicht beschreibbar */
function cache_dir(string $sub): ?string
{
    $base = cache_file('x');
    if (!$base) {
        return null;
    }
    $dir = dirname($base) . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return null;
    }
    return is_dir($dir) && @is_writable($dir) ? $dir : null;
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

    // Wohin darf geschrieben werden? (nicht in den Sync-Ordner, der wird überschrieben)
    $writeBlocked = !local_mode() && connector_root()[1] === CONNECTOR_DIRS[0];

    // Massen-Import: viele Clubs auf einmal, eine Zeile je Club
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['bulkgo']) || isset($_POST['bulksave']))) {
        $cc = admin_slug((string)($_POST['bcc'] ?? 'de')) ?: 'de';
        $raw = (string)($_POST['bulk'] ?? '');
        $save = isset($_POST['bulksave']);
        $rows = [];
        foreach (preg_split('#\r?\n#', $raw) as $ln => $line) {
            if (trim($line) === '' || substr(trim($line), 0, 1) === '#') {
                continue;
            }
            $rows[$ln + 1] = bulk_parse($line, $cc);
        }
        if (!$rows) {
            echo '<p class="err">Keine Zeilen erkannt.</p>';
        }
        // Was es schon gibt: id und Koordinate dürfen sich nicht wiederholen
        [$exClubs] = load_connectors(local_mode() ? '' : $cc);
        $haveId = $haveXY = [];
        foreach ($exClubs as $ec) {
            $haveId[$ec['id']] = true;
            $haveXY[number_format($ec['lat'], 4, '.', '') . ',' . number_format($ec['lng'], 4, '.', '')] = $ec['name'];
        }
        foreach ($rows as $i => $r) {
            if (!$r['ok']) {
                continue;
            }
            $id = $r['y']['id'];
            $xy = number_format((float)$r['y']['lat'], 4, '.', '') . ',' . number_format((float)$r['y']['lng'], 4, '.', '');
            if (isset($haveId[$id])) {
                $rows[$i]['ok'] = false;
                $rows[$i]['err'][] = 'id "' . $id . '" gibt es schon – Ortsnamen anhängen';
            }
            if (isset($haveXY[$xy])) {
                $rows[$i]['ok'] = false;
                $rows[$i]['err'][] = 'gleiche Koordinate wie "' . $haveXY[$xy] . '"';
            }
            $haveId[$id] = true;
            $haveXY[$xy] = $r['y']['name'];
        }
        // Erreichbarkeit in einem Rutsch prüfen (sagt auch, wie gescrapt wird)
        $urls = [];
        foreach ($rows as $i => $r) {
            if ($r['ok']) {
                $urls[$i] = $r['y']['website'];
            }
        }
        $res = $urls ? fetch_all($urls, [], 60) : [];
        echo '<h2>' . ($save ? 'Gespeichert' : 'Prüfung') . '</h2><table style="width:100%;border-collapse:collapse;font-size:13.5px">';
        echo '<tr><th align="left">Zeile</th><th align="left">Club</th><th align="left">Ergebnis</th></tr>';
        $good = $bad = 0;
        foreach ($rows as $i => $r) {
            $nameCell = $h($r['y']['name'] ?? '?') . ' <span class="muted">' . $h($r['y']['city'] ?? '') . '</span>';
            if (!$r['ok']) {
                $bad++;
                echo '<tr><td>' . $i . '</td><td>' . $nameCell . '</td><td class="err">' . $h(implode('; ', $r['err'])) . '</td></tr>';
                continue;
            }
            $hit = $res[$i] ?? null;
            if (!$hit || $hit['code'] === 0) {
                $bad++;
                echo '<tr><td>' . $i . '</td><td>' . $nameCell . '</td><td class="err">Website nicht erreichbar: '
                    . $h($hit['err'] ?? 'keine Antwort') . '</td></tr>';
                continue;
            }
            // Extraktions-Modi aus dem, was die Seite wirklich hergibt
            $html = $hit['body'];
            $base = $hit['url'] ?: $r['y']['website'];
            $imgMode = extract_images($html, $base, 'auto', 4) ? 'auto' : (extract_images($html, $base, 'og', 2) ? 'og' : 'none');
            $evMode = jsonld_events($html) ? 'jsonld' : (auto_events($html, null, null) ? 'auto' : 'none');
            $infoMode = extract_info($html) !== '' ? 'auto' : 'none';
            $yaml = bulk_yaml($r['y'], $imgMode, $evMode, $infoMode);
            $note = 'Bilder ' . $imgMode . ', Programm ' . $evMode . ', Info ' . $infoMode;
            if ($save) {
                if ($writeBlocked) {
                    $bad++;
                    echo '<tr><td>' . $i . '</td><td>' . $nameCell . '</td><td class="err">nicht gespeichert (Sync-Ordner aktiv)</td></tr>';
                    continue;
                }
                $dir = local_mode() ? __DIR__ . '/data/connector'
                    : region_dir($cc) . '/' . $r['land'] . '/' . admin_slug($r['y']['city']);
                @mkdir($dir, 0755, true);
                $file = $dir . '/' . $r['y']['id'] . '.yaml';
                if (is_file($file)) {
                    $bad++;
                    echo '<tr><td>' . $i . '</td><td>' . $nameCell . '</td><td class="err">gibt es schon: '
                        . $h(str_replace(__DIR__ . '/', '', $file)) . '</td></tr>';
                    continue;
                }
                if (@file_put_contents($file, $yaml) === false) {
                    $bad++;
                    echo '<tr><td>' . $i . '</td><td>' . $nameCell . '</td><td class="err">Schreiben fehlgeschlagen</td></tr>';
                    continue;
                }
                $good++;
                echo '<tr><td>' . $i . '</td><td>' . $nameCell . '</td><td class="ok">' . $h(str_replace(__DIR__ . '/', '', $file))
                    . ' <span class="muted">(' . $h($note) . ')</span></td></tr>';
            } else {
                $good++;
                echo '<tr><td>' . $i . '</td><td>' . $nameCell . '</td><td class="ok">bereit – ' . $h($note) . '</td></tr>';
            }
        }
        echo '</table><p>' . $good . ' in Ordnung, ' . $bad . ' mit Problem.</p>';
        if (!$save && $good > 0) {
            if ($writeBlocked) {
                echo '<p class="err">Die Connectoren kommen zurzeit per <code>?sync</code> aus ' . $h(CONNECTOR_REPO)
                    . ' – hier Gespeichertes wäre beim nächsten Sync weg. Zeilen ins Repo geben.</p>';
            } else {
                echo '<form method="post"><input type="hidden" name="key" value="' . $h($key) . '">'
                    . '<input type="hidden" name="bcc" value="' . $h($cc) . '">'
                    . '<textarea name="bulk" hidden>' . $h($raw) . '</textarea>'
                    . '<button name="bulksave" value="1">' . $good . ' Clubs anlegen</button></form>';
            }
        }
        echo '<p><a href="?admin=1' . $kq . '">Zurück</a></p></body></html>';
        return;
    }

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
    // Viele Clubs auf einmal: eine Zeile je Club
    echo '<h2>Viele Clubs auf einmal</h2>';
    echo '<p class="muted">Eine Zeile je Club, Felder mit <code>|</code> getrennt:<br>'
        . '<code>Website | Name | Stadt | Bundesland | Straße Nr. | lat | lng | Musik [| Öffnungszeiten]</code><br>'
        . 'Beispiel: <code>https://rote-sonne.com | Rote Sonne | München | bayern | Maximiliansplatz 5 | 48.1414 | 11.5706 | Techno, House | Fr,Sa 23:00-07:00</code><br>'
        . 'Der Server prüft jede Zeile gegen den Standard, ruft die Website auf und stellt die Extraktion selbst ein. '
        . 'Koordinaten mit 4 Nachkommastellen; Musik nur aus: ' . $h(implode(', ', OK_GENRES)) . '.</p>';
    echo '<form method="post"><input type="hidden" name="key" value="' . $h($key) . '">';
    echo '<div class="grid"><div><label>Land</label><input name="bcc" value="de"></div><div></div></div>';
    echo '<textarea name="bulk" placeholder="https://… | Name | Stadt | bayern | Straße 1 | 48.1414 | 11.5706 | Techno"></textarea>';
    echo '<button name="bulkgo" value="1">Zeilen prüfen</button></form>';

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
    // Eingeschränkte Gratis-Hoster haben curl_multi zwar vorhanden, liefern
    // damit aber oft nichts (Einzel-curl geht dagegen). Dort von vornherein
    // sequenziell – zuverlässiger als der Umweg über den Rettungspfad.
    static $seqOnly = null;
    if ($seqOnly === null) {
        $seqOnly = host_limited() || !function_exists('curl_multi_init');
    }
    if ($seqOnly) {
        return fetch_seq($urls, $cond, $deadline);
    }
    $out = [];
    $meta = [];
    $bodies = [];
    $over = [];
    foreach (array_chunk($urls, curl_batch(), true) as $chunk) {
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
    $hit = 0;
    foreach ($out as $r) {
        if ($r['code'] !== 0) {
            $hit++;
        }
    }
    if ($hit === 0 && $out && $deadline - time() > 2) {
        // curl_multi hat für diesen Prozess nichts gebracht – ab jetzt
        // sequenziell, und zwar auch für alle folgenden Pakete.
        $seqOnly = true;
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
function extract_images(string $html, string $base, string $mode, int $max = 8): array
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
/*
 * Stempel neben dem Cache. Der Ladebalken hängt daran und NICHT an 'ft':
 * 'ft' heißt nur "Versuch gelaufen" und wird auch bei Fehlschlag gesetzt –
 * eine ältere Fassung hat es für alle Clubs gesetzt, ohne dass je Daten kamen.
 */
function setup_file(string $cc, string $kind): ?string
{
    $c = cache_file('x');
    return $c ? dirname($c) . '/setup-' . $kind . '-' . ($cc !== '' ? $cc : 'local') . '.stamp' : null;
}
/* Läuft der Ladebalken noch? Ohne beschreibbaren Cache gar nicht erst anfangen. */
function setup_needed(string $cc): bool
{
    $f = setup_file($cc, 'done');
    return $f !== null && !is_file($f);
}
/* Beginn des Durchlaufs. Alles, was ab dieser Sekunde geholt wurde, gilt als
   erledigt – auch ein Fehlschlag, sonst bliebe der Balken für immer stehen. */
function setup_begin(string $cc): ?int
{
    $f = setup_file($cc, 'start');
    if ($f === null) {
        return null;
    }
    // Der Startzeitpunkt steht IM Stempel, nicht in seiner Änderungszeit:
    // 'ft' kommt von time() (Uhr des PHP-Prozesses), filemtime von der Uhr
    // des Dateisystems – auf Gratis-Hostern laufen die auseinander.
    if (is_file($f)) {
        $t = (int)trim((string)@file_get_contents($f));
        // Steht der Stempel und ist trotzdem nichts fertig geworden, ist der
        // Lauf hängengeblieben – nach 6 Stunden frisch anfangen, sonst gilt
        // nach einem Zurücksetzen alles sofort als erledigt.
        if ($t > 0 && time() - $t < 6 * 3600) {
            return $t;
        }
    }
    $t = time();
    if (@file_put_contents($f, (string)$t) === false) {
        return null; // nicht schreibbar
    }
    @chmod($f, 0644);
    return $t;
}
/*
 * Fingerabdruck über alle Scrape-Konfigurationen. Ändert sich im Repo ein
 * Connector (oder kommt einer dazu / fällt weg), ändert er sich mit – daran
 * erkennt der Update-Abgleich, dass der Ladebalken noch einmal laufen muss.
 */
function conn_fingerprint(array $conn): string
{
    $teile = [];
    foreach ($conn as $id => $cfg) {
        $teile[$id] = md5(json_encode($cfg));
    }
    ksort($teile);
    return md5(json_encode($teile));
}

function setup_finish(string $cc, string $fp = ''): void
{
    $f = setup_file($cc, 'done');
    if ($f !== null) {
        // Der Stempel trägt den Stand, GEGEN den der Durchlauf lief – der
        // Update-Abgleich vergleicht dagegen.
        @file_put_contents($f, $fp);
        @chmod($f, 0644);
    }
}

/* Gegen welchen Connector-Stand lief der letzte fertige Durchlauf? */
function setup_done_fp(string $cc): ?string
{
    $f = setup_file($cc, 'done');
    if ($f === null || !is_file($f)) {
        return null;
    }
    return trim((string)@file_get_contents($f));
}

/*
 * Fortschritt der Erstbefüllung: [wieviele versucht, wieviele mit Inhalt].
 * "versucht" (ft gesetzt) treibt den Ladebalken – auch ein nicht erreichbarer
 * Club zählt als erledigt, sonst bliebe der Balken für immer stehen.
 */
function scrape_done(string $cc, array $conn): array
{
    $data = cache_read($cc)['data'] ?? [];
    $done = $withData = 0;
    foreach ($conn as $id => $c) {
        $e = $data[$id] ?? [];
        if (!empty($e['ft'])) {
            $done++;
        }
        if (!empty($e['events']) || !empty($e['images']) || !empty($e['info'])) {
            $withData++;
        }
    }
    return [$done, $withData];
}

function scrape_refresh(array $old, array $connectors, int $limit = 24, string $cc = '', int $secs = 0, bool $force = false, ?array $keep = null, ?bool &$busy = null): void
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
        // Ein anderer Aufruf arbeitet gerade. Das dem Aufrufer sagen, sonst
        // hält der Ladebalken das für Stillstand und gibt zu früh auf.
        $busy = true;
        return;
    }
    @ignore_user_abort(true);
    @set_time_limit(180);
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
    // Dieses Fenster schützt nicht den eigenen Server, sondern die fremden
    // Club-Websites: den ?cron-Ping schickt JEDER Besucher, ohne die Sperre
    // würde der ausgehende Verkehr mit der Besucherzahl mitwachsen – und
    // genau dafür sperren Club-Hoster IP-Adressen.
    // Abstand je Club-Website = Fenster × (Clubs ÷ Batch).
    // Heute: 180 s × (244 ÷ 48) ≈ 15 Minuten. Wer die Zahlen ändert, rechnet
    // das nach; unter ~10 Minuten wird es unhöflich.
    // Während der Erstbefüllung greift die Sperre nicht ($unseen > 0) –
    // die Startgeschwindigkeit hängt am Batch und am Zeitbudget, nicht hier.
    if (!$force && $limit > 0 && $unseen === 0 && time() - ($cache['ts'] ?? 0) < 180) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return;
    }
    $old = $cache['data'] ?? $old;
    // Zeitstempel sofort setzen: bricht der Hoster den Lauf ab,
    // rennt nicht jeder folgende Request erneut hinein
    cache_write($file, $old);

    // Aufräumen NUR gegen die vollständige Connector-Liste. Wird die Funktion
    // mit einer Teilmenge gerufen (ein Club beim Öffnen, ein Häppchen der
    // Erstbefüllung), würde $connectors sonst alles andere aus dem Cache werfen.
    $data = array_intersect_key($old, $keep ?? $connectors);
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
    $deadline = time() + ($secs > 0 ? $secs : ($limit > 0 ? 45 : 120));
    $tried = $failed = 0;
    $lastErr = '';
    // In Teilpaketen arbeiten und nach jedem Paket speichern: bricht der
    // Hoster den Lauf ab, ist das bis dahin Geholte trotzdem gesichert.
    $chunks = array_chunk($connectors, curl_batch(), true);
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
        if (!isset($results[$id])) {
            // Im Zeitbudget gar nicht abgeschickt – das ist kein Versuch.
            // Sonst gilt ein nie abgerufener Club als erledigt und die
            // Erstbefüllung wäre "fertig", ohne ihn je geholt zu haben.
            continue;
        }
        $data[$id]['ft'] = time(); // Versuch zählt, sonst klemmt ein toter Host die Rotation
        if ($results[$id]['code'] === 0) {
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
 * Die Seite liest den Cache und ist damit sofort da. Ist der Cache noch leer
 * (erster Aufruf), zeigt der Browser einen Ladebalken und holt über ?setup=1
 * alle Clubs in Häppchen. Danach wird ein Club beim Öffnen über ?club= frisch
 * nachgeholt. Kein Hintergrundlauf, kein Polling.
 */
// Selbst-Start: leere Seite füllt sich beim ersten Aufruf aus dem Repo
$bootState = 'cannot';
if (!local_mode() && !connectors_present()) {
    $bootState = maybe_bootstrap();
    if ($bootState === 'filled') {
        // frisch geholt – sauberer neuer Request, damit alle Caches greifen
        header('Location: ' . strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '#'));
        exit;
    }
}
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
<?php if (!$regions && $bootState === 'pending'): ?>
    <p class="none">Clubs werden geladen …</p>
    <script>setTimeout(function(){ location.reload(); }, 3000);</script>
<?php elseif (!$regions): ?>
    <p class="none">Noch keine Clubs. Ordner <code>connectors/</code> aus dem
    Repo neben die <code>index.php</code> legen – oder <code>?diag=1</code>
    zeigt, warum der Selbst-Start nicht greift.</p>
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
function pickByLocation() {
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
if (navigator.geolocation && Object.keys(CENT).length > 1) {
    if (navigator.permissions && navigator.permissions.query) {
        navigator.permissions.query({ name: 'geolocation' })
            .then(st => { if (st.state === 'granted') pickByLocation(); })
            .catch(() => {});
    } else {
        try {
            if (localStorage.getItem('ncm.geo.ok') === '1') pickByLocation();
        } catch (e) { /* privater Modus: dann eben die Flaggen */ }
    }
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
$live = live_payload((array)($cache['data'] ?? []));
$needSetup = $connectors && setup_needed($cc); // Ladebalken bis der Durchlauf steht
$cities = array_values(array_unique(array_map(fn($c) => $c['city'], $clubs)));
$pageTitle = count($cities) === 1 ? 'Clubs ' . $cities[0] : 'Clubs';
$payload = json_encode(
    ['clubs' => $clubs, 'live' => $live, 'cc' => $cc,
        'setup' => $needSetup, 'total' => count($connectors)],
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
<?php if (have_vendor()): // aus dem eigenen Haus – kein fremder CDN ?>
<link rel="stylesheet" href="<?= htmlspecialchars(asset_url('leaflet.css')) ?>" media="print" onload="this.media='all'">
<?php else: ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""
      media="print" onload="this.media='all'">
<?php endif; ?>
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
/* iOS fragt nur auf Fingertipp – deshalb den Knopf sichtbar anbieten */
.icon-btn.hint { border-color: var(--fg); animation: lochint 1.4s ease-in-out 3; }
@keyframes lochint { 50% { transform: scale(1.12); } }
@media (prefers-reduced-motion: reduce) { .icon-btn.hint { animation: none; } }
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
#sheet .about.lead { color: var(--fg); font-size: 15px; }
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

/* Ladebalken der Erstbefüllung – füllt sich einmal, dann ist Ruhe */
#setup { position: fixed; inset: 0; z-index: 4000; display: flex;
    align-items: center; justify-content: center; background: var(--bg);
    padding: 24px; }
#setup[hidden] { display: none; }
#setup .box { width: 100%; max-width: 360px; text-align: center; }
#setup .ttl { font-size: 17px; font-weight: 600; color: var(--fg); margin-bottom: 16px; }
#setup .pbar { height: 8px; border-radius: 999px; background: var(--line);
    overflow: hidden; }
#setup .pbar > i { display: block; height: 100%; width: 0;
    background: var(--fg); border-radius: 999px; transition: width .35s ease; }
#setup .txt { margin-top: 10px; font-variant-numeric: tabular-nums;
    font-size: 14px; color: var(--fg); }
#setup .sub { margin-top: 6px; font-size: 13px; color: var(--muted);
    line-height: 1.4; }
#setup .setupgo { margin-top: 16px; padding: 10px 18px; border: 0;
    border-radius: 999px; background: var(--inv-bg); color: var(--inv-fg);
    font-size: 14px; font-weight: 600; cursor: pointer; }
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
<div id="setup" hidden>
    <div class="box">
        <div class="ttl">Clubs werden geladen …</div>
        <div class="pbar"><i></i></div>
        <div class="txt">0 / 0</div>
        <div class="sub">Das passiert nur beim ersten Aufruf. Einen Moment.</div>
    </div>
</div>
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
const TILEPROXY = <?= tile_proxy_on() ? 'true' : 'false' ?>;
/* Dateipfad statt Abfrage: liegt die Kachel schon im Cache, liefert Apache
   sie selbst aus und PHP läuft dafür gar nicht erst an. Nur ein Fehltreffer
   wird per .htaccess auf die index.php umgeschrieben. Ist das nicht
   eingerichtet (kein mod_rewrite, cache/ nicht beschreibbar), bleibt es beim
   Abfrage-Weg – dann geht jede Kachel durch PHP, aber nichts ist kaputt. */
const TILESTATIC = <?= tile_static() ? 'true' : 'false' ?>;
const TILEBASE = <?= json_encode(url_base() . 'cache/tile/', JSON_HEX_TAG) ?>;
const TILETOK = <?= json_encode(tile_token(), JSON_HEX_TAG) ?>;
function tileUrl(dark) {
    const style = dark ? 'dark_all' : 'light_all';
    // über die eigene Domain (443/80) statt direkt vom Karten-CDN
    if (TILESTATIC) return TILEBASE + style + '/{z}/{x}/{y}{r}.png' + (TILETOK ? '?t=' + TILETOK : '');
    if (TILEPROXY) return '?tile={z}/{x}/{y}&m=' + (dark ? 'dark' : 'light') + '&r={r}'
        + (TILETOK ? '&t=' + TILETOK : '');
    return 'https://{s}.basemaps.cartocdn.com/' + style + '/{z}/{x}/{y}{r}.png';
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
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap-Mitwirkende</a> &copy; <a href="https://carto.com/attributions">CARTO</a> · Ohne Gewähr'
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
let lastFp = '', liveBusy = false;
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
            const anker = body.querySelector('.about.lead') || body.querySelector('.tags');
            if (anker) anker.after(buildPics(imgs));
        }
        if (body && alive.info && !body.querySelector('.about:not(.lead)')) {
            const anker = body.querySelector('.pics') || body.querySelector('.about.lead') || body.querySelector('.tags');
            if (anker) anker.after(el('p', 'about', alive.info));
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
function setUserPos(lat, lng, pan, still) {
    userPos = { lat, lng };
    $('loc').classList.add('on');
    // Weit außerhalb? Dann nicht wortlos auf eine leere Karte fahren.
    let near = Infinity;
    for (const c of DATA.clubs) near = Math.min(near, distKm(userPos, c));
    const far = isFinite(near) && near > 150;
    placeYou(far ? false : pan);
    render();
    // Nur nach einem echten Fingertipp erklären – ein automatischer Versuch
    // beim Laden darf niemanden ungefragt mit Meldungen begrüßen.
    if (far && !still) {
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
    if (err && err.code === 1) {
        // iOS nennt den Weg anders als der Rest – hier den konkreten nennen,
        // sonst sucht man sich auf dem iPhone tot.
        return /iPhone|iPad|iPod/.test(navigator.userAgent)
            ? 'Standort ist für diese Seite blockiert. In Safari oben auf „AA“ → '
              + 'Website-Einstellungen → Standort → Erlauben. Hilft das nicht: '
              + 'Einstellungen → Safari → Standort.'
            : 'Standort ist für diese Seite blockiert – im Browser unter „Website-Einstellungen“ erlauben.';
    }
    if (err && err.code === 3) return 'Standort dauert zu lange – draußen oder mit WLAN nochmal versuchen.';
    return 'Standort gerade nicht verfügbar.';
}
let locBusy = false;
const GEOKEY = 'ncm.geo.ok';
/* Ein Aufruf, überall gleich. WICHTIG: muss synchron in der Tap-Behandlung
   stehen – iOS/Safari zeigt den Systemdialog nur mit frischer Nutzergeste.
   still = automatischer Versuch ohne Fingertipp: der darf NIE eine Meldung
   zeigen. Sonst begrüßt die Seite jeden Besucher mit „Standort blockiert“,
   obwohl er nie danach gefragt hat. */
let geoReq = 0;
let locStill = false; // läuft gerade ein stiller (automatischer) Versuch?
function geoAsk(pan, still) {
    if (locBusy) {
        // Tippt jemand, während der stille Automatik-Versuch noch läuft,
        // wird der laufende Versuch laut – sonst verpufft der Tap wortlos.
        if (!still) locStill = false;
        return;
    }
    locStill = !!still;
    locBusy = true;
    const my = ++geoReq;           // nur der jüngste Versuch darf etwas ändern
    $('loc').classList.remove('hint');
    $('loc').classList.add('busy');
    const done = () => {
        if (my !== geoReq) return false;  // veralteter Rückruf: ignorieren
        locBusy = false;
        clearTimeout(watchdog);
        $('loc').classList.remove('busy');
        return true;
    };
    // Antwortet der Browser gar nicht (iOS bei blockierter Seite), nicht ewig hängen
    const watchdog = setTimeout(() => {
        if (!done()) return;
        if (locStill) { $('loc').classList.add('hint'); return; }
        locNote(locFail({ code: 1 })); // gleicher Weg-Text wie bei Ablehnung
    }, 15000);
    navigator.geolocation.getCurrentPosition(p => {
        if (!done()) return;
        try { localStorage.setItem(GEOKEY, '1'); } catch (e) { /* privater Modus */ }
        setUserPos(p.coords.latitude, p.coords.longitude, pan && uiIdle(), locStill);
        if (uiIdle()) { suggMode = 'near'; renderSugg(); }
    }, err => {
        if (!done()) return;
        if (err && err.code === 1) {
            try { localStorage.removeItem(GEOKEY); } catch (e) { /* egal */ }
        }
        if (locStill) {
            // Automatik gescheitert: nichts melden, aber den Knopf anbieten –
            // sonst verschwände selbst das Blinken kommentarlos.
            $('loc').classList.add('hint');
        } else if (!userPos) {
            locNote(locFail(err));
        }
    }, { enableHighAccuracy: false, timeout: 12000, maximumAge: 120000 });
}

/* Ohne Fingertipp fragen wir nur, wenn die Erlaubnis schon erteilt ist –
   sonst verpufft der Aufruf auf dem iPhone und der Dialog kommt nie mehr. */
function geoAuto(pan) {
    if (locBlocked()) return;
    // still: ohne Fingertipp wird nie gemeckert, höchstens der Knopf blinkt
    const ask = () => geoAsk(pan, true);
    const offer = () => { $('loc').classList.add('hint'); };
    if (navigator.permissions && navigator.permissions.query) {
        let q = null;
        // Safari wirft hier je nach Version sofort, statt abzulehnen – ohne
        // dieses try/catch stirbt der ganze Startblock und mit ihm der Ladebalken
        try { q = navigator.permissions.query({ name: 'geolocation' }); } catch (e) { q = null; }
        if (!q || typeof q.then !== 'function') { offer(); return; }
        q.then(st => {
                if (st.state === 'granted') { ask(); return; }
                // abgelehnt: blinken würde nur zu einem Knopf locken, der
                // ohne Umweg über die Browser-Einstellungen nichts tut
                if (st.state !== 'denied') { offer(); }
            })
            .catch(offer);
        return;
    }
    // Kein Permissions-API: nur wenn es hier schon einmal geklappt hat
    let known = false;
    try { known = localStorage.getItem(GEOKEY) === '1'; } catch (e) { /* egal */ }
    known ? ask() : offer();
}
$('loc').onclick = () => {
    const blocked = locBlocked();
    if (blocked) { locNote(blocked); return; }
    locNote('');
    if (userPos) { suggMode = 'near'; renderSugg(); }
    geoAsk(true); // direkt in der Geste – sonst kein Dialog auf dem iPhone
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
/* Aufräumen macht der Server (live_payload) – hier nur noch harte Dubletten */
function cleanImgs(list) {
    const seen = new Set(), out = [];
    for (const u of list || []) {
        if (!u || seen.has(u)) continue;
        seen.add(u);
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
    // Diesen Club frisch nachladen und ins offene Sheet einspielen. Kein
    // Ratelimit – ein Abruf je Öffnen ist unkritisch. render() reicht die
    // frischen Bilder/Infos/Programme in die schon offene Kachel nach.
    // Clubs ohne Scrape-Quelle gar nicht erst fragen – da kommt nie etwas.
    if (!c.nolive) fetch(EP('fresh=' + encodeURIComponent(c.id))).then(r => r.json()).then(j => {
        if (j && j.live && activeId === c.id) {
            DATA.live[c.id] = j.live;
            render();
        }
    }).catch(() => {});
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

    // Kurzprofil aus dem Connector: steht immer da, egal was die Website
    // liefert – damit man weiß, was einen erwartet, bevor Fotos laden.
    if (c.about) body.appendChild(el('p', 'about lead', c.about));

    const imgs = cleanImgs(live.images).slice(0, 4);
    if (imgs.length) body.appendChild(buildPics(imgs));

    if (live.info && live.info !== c.about) body.appendChild(el('p', 'about', live.info));

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
// Ladebalken zuerst anstoßen: ein Fehler im Standort-Teil darunter darf ihn
// niemals verschlucken (alles hier liegt in EINEM <script>-Block).
if (DATA.setup) { runSetup(); }
if (!DATA.setup) {
    // Einmal je Besuch beim Server nachfragen, ob das Connector-Repo neuere
    // Stände hat. Meistens ein Billig-Ping; höchstens alle paar Stunden lädt
    // der Server das Repo nach und stellt dann den Ladebalken neu scharf –
    // der holt dank ck-Regel nur die neuen und geänderten Clubs.
    setTimeout(() => {
        fetch(EP('update=1')).then(r => r.json()).then(j => {
            if (j && j.update) { runSetup(); }
        }).catch(() => {});
    }, 2500);
}
const bootClub = DATA.clubs.find(c => c.id === boot.get('club'));
if (bootClub) openSheet(bootClub, true);
if (boot.get('near')) {
    // Vom Start weitergereicht. Ohne Fingertipp fragen wir nur, wenn die
    // Erlaubnis schon steht; sonst blinkt der Standort-Knopf einmal.
    // Ohne Fingertipp keine Meldung: kann der Browser keinen Standort oder
    // fehlt HTTPS, wird das erst beim Tipp auf den Knopf erklärt.
    if (locBlocked()) {
        $('loc').classList.add('hint');
    } else {
        geoAuto(!bootClub);
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
// Erstbefüllung: leerer Cache -> Ladebalken, der alles einmal holt. Danach
// wird jeder Club beim Öffnen frisch nachgeholt (siehe openSheet). Kein
// Hintergrundlauf, kein Polling – läuft überall, auch auf Gratis-Hostern.
function setupSleep(ms) { return new Promise(r => setTimeout(r, ms)); }
async function runSetup() {
    const ov = $('setup');
    if (!ov) { return; }
    ov.hidden = false;
    const bar = ov.querySelector('.pbar > i');
    const txt = ov.querySelector('.txt');
    const sub = ov.querySelector('.sub');
    const total0 = DATA.total || 1;
    let stall = 0, last = null;
    while (true) {
        let j;
        try {
            j = await fetch(EP('setup=1')).then(r => r.json());
        } catch (e) {
            if (++stall >= 4) { break; }
            await setupSleep(1500);
            continue;
        }
        const total = j.total || total0;
        const done = j.done || 0;
        bar.style.width = Math.min(100, Math.round(done / total * 100)) + '%';
        txt.textContent = done + ' / ' + total + ' Clubs';
        last = j;
        if (j.fertig || done >= total) { break; }
        if (j.kaputt) { break; } // Stempel nicht schreibbar – nicht endlos drehen
        if (j.busy) {
            // Ein anderer Besucher arbeitet gerade – das ist kein Stillstand
            await setupSleep(2000);
            continue;
        }
        // kein Fortschritt mehr (Hoster kappt jeden Aufruf) -> nicht ewig drehen.
        // Mit Pause, sonst schlägt "kein Fortschritt" in Millisekunden dreimal zu.
        if (!j.moved) {
            if (++stall >= 3) { break; }
            await setupSleep(1500);
        } else {
            stall = 0;
        }
    }
    const fertig = last && last.fertig;
    const leer = !last || (last.withData || 0) === 0;
    if (fertig && !leer) {
        location.reload(); // alles da -> einmal frisch laden
        return;
    }
    if (!leer) {
        // Teilstand: etwas ist angekommen, aber der Durchlauf steht noch.
        // NICHT neu laden – das ergäbe eine Endlosschleife aus Reloads.
        sub.textContent = (last.done || 0) + ' von ' + (last.total || total0)
            + ' geladen. Der Rest kommt beim nächsten Besuch dazu.';
        const w = el('button', 'setupgo', 'Weiter zur Karte');
        w.onclick = () => { ov.hidden = true; };
        ov.querySelector('.box').appendChild(w);
        return;
    }
    {
        // Nichts angekommen – entweder kommt der Server nicht ins Netz oder er
        // bricht jeden Durchlauf ab. Klartext statt endlos drehendem Balken.
        sub.textContent = fertig
            ? 'Programm und Bilder ließen sich nicht laden – der Server kommt nicht '
              + 'ins Netz. Die Karte, Öffnungszeiten und Routen gehen trotzdem.'
            : 'Das Laden kommt nicht voran – der Server bricht jeden Durchlauf ab. '
              + 'Die Karte, Öffnungszeiten und Routen gehen trotzdem.';
        const b = el('button', 'setupgo', 'Weiter zur Karte');
        b.onclick = () => {
            ov.hidden = true;
            // nicht bei jedem Aufruf erneut nerven
            fetch(EP('setup=1&skip=1')).catch(() => {});
        };
        ov.querySelector('.box').appendChild(b);
        return;
    }
    // fertig -> mit vollem Datenstand frisch laden
    location.reload();
}
</script>
<?php if (have_vendor()): ?>
<script async src="<?= htmlspecialchars(asset_url('leaflet.js')) ?>" onload="initMap()" onerror="mapFailed()"></script>
<?php else: ?>
<script async src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""
        onload="initMap()" onerror="mapFailed()"></script>
<?php endif; ?>
</body>
</html>
