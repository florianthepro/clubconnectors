<?php
/*
 * Prüft jeden Connector gegen SPEC.md 1.0.
 * Ohne Abhängigkeiten, läuft ab PHP 7.4.
 *
 *   php tools/validate.php [pfad …] [--json] [--strict] [--quiet]
 *
 * Rückgabewert 0 = sauber, 1 = Fehler (mit --strict zählen Warnungen mit).
 */
declare(strict_types=1);

const SPEC_VERSION = '1.0';

/* Abschnitt 6: geschlossene Liste */
const GENRES = ['80er/90er', 'Black Music', 'Drum and Bass', 'Electro', 'Goa',
    'Hip-Hop', 'House', 'Indie', 'Latin', 'Live', 'Mixed', 'Rock', 'Schlager', 'Techno'];

/* Abschnitt 8: Rahmen je Land, lat_min lat_max lng_min lng_max */
const COUNTRIES = [
    'de' => [47.20, 55.10, 5.80, 15.10],
    'at' => [46.30, 49.10, 9.50, 17.20],
    'ch' => [45.80, 47.85, 5.90, 10.55],
];

const AREAS = [
    'de' => ['baden-wuerttemberg', 'bayern', 'berlin', 'brandenburg', 'bremen', 'hamburg',
        'hessen', 'mecklenburg-vorpommern', 'niedersachsen', 'nordrhein-westfalen',
        'rheinland-pfalz', 'saarland', 'sachsen', 'sachsen-anhalt', 'schleswig-holstein',
        'thueringen'],
    'at' => ['burgenland', 'kaernten', 'niederoesterreich', 'oberoesterreich', 'salzburg',
        'steiermark', 'tirol', 'vorarlberg', 'wien'],
    'ch' => ['ag', 'ai', 'ar', 'be', 'bl', 'bs', 'fr', 'ge', 'gl', 'gr', 'ju', 'lu', 'ne',
        'nw', 'ow', 'sg', 'sh', 'so', 'sz', 'tg', 'ti', 'ur', 'vd', 'vs', 'zg', 'zh'],
];

/* Abschnitt 5, Reihenfolge der Felder */
const ORDER = ['id', 'name', 'city', 'address', 'lat', 'lng', 'website', 'genres', 'hours',
    'about', 'note', 'pause', 'checked', 'source', 'state', 'scrape_url', 'scrape_events',
    'scrape_images', 'scrape_info', 'scrape_closed', 'scrape_info_url', 'scrape_images_url',
    'scrape_from', 'scrape_to'];

const ENUMS = [
    'scrape_events' => ['auto', 'jsonld', 'text', 'none'],
    'scrape_images' => ['auto', 'og', 'none'],
    'scrape_info' => ['auto', 'none'],
    'scrape_closed' => ['auto', 'none'],
];

const DAYS = ['Mo' => 1, 'Di' => 2, 'Mi' => 3, 'Do' => 4, 'Fr' => 5, 'Sa' => 6, 'So' => 7];

/* ---------- Werkzeuge ---------- */

/** Abschnitt 3: Slug-Bildung. Genau diese Schritte, in dieser Reihenfolge. */
const ACCENTS = [
    'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
    'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ĉ' => 'c',
    'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
    'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'į' => 'i',
    'ñ' => 'n', 'ń' => 'n',
    'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o', 'ő' => 'o',
    'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u',
    'ý' => 'y', 'ÿ' => 'y',
    'ś' => 's', 'š' => 's', 'ż' => 'z', 'ź' => 'z', 'ž' => 'z', 'ł' => 'l', 'đ' => 'd', 'ð' => 'd', 'þ' => 'th',
];

/** Abschnitt 3: Slug-Bildung. Genau diese Schritte, in dieser Reihenfolge. */
function slug(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']); // Schritt 2
    $s = strtr($s, ACCENTS);                                              // Schritt 3, ohne iconv
    return (string)preg_replace('/[^a-z0-9]/', '', $s);                   // Schritt 4
}

/** Ortsname vor dem Slug: Klammerzusätze weg. */
function city_slug(string $s): string
{
    return slug(trim((string)preg_replace('/\s*\(.*?\)/', '', $s)));
}

/*
 * Einen YAML-Wert entpacken – exakt wie flat_value() in index.php.
 * Erst die Anführungszeichen, dann fällt ein nachgestellter Kommentar weg;
 * ein '#' innerhalb der Anführungszeichen bleibt Teil des Werts.
 */
function flat_value(string $v): string
{
    $v = trim($v);
    if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) {
        $q = $v[0];
        $end = strpos($v, $q, 1);
        if ($end !== false) {
            return substr($v, 1, $end - 1); // alles hinter dem schließenden Quote verwerfen
        }
    }
    return rtrim((string)preg_replace('/\s+#.*$/', '', $v));
}

/*
 * Flaches YAML lesen – dieselbe Werte-Logik wie yaml_flat() in index.php,
 * plus getrennt geführte Metadaten (Zeilennummern, unlesbare Zeilen,
 * doppelte Schlüssel). Die Metadaten liegen NICHT im Wertearray, damit ein
 * Feld namens "__line" den Leser nicht abstürzen lässt.
 * Rückgabe: [fields, line, junk, dupe].
 */
function read_flat(string $text): array
{
    $out = $lines = $junk = $dupes = [];
    $text = (string)preg_replace('/^\xEF\xBB\xBF/', '', $text); // wie yaml_flat()
    foreach (preg_split('#\r?\n#', $text) as $i => $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!preg_match('#^([A-Za-z_][\w-]*):\s*(.*)$#', $line, $m)) {
            $junk[] = [$i + 1, $line];
            continue;
        }
        if (isset($out[$m[1]])) {
            $dupes[] = $m[1];
        }
        $out[$m[1]] = flat_value($m[2]);
        $lines[$m[1]] = $i + 1;
    }
    return [$out, $lines, $junk, $dupes];
}

function is_url(string $u, bool $httpsOnly = false): bool
{
    if (!preg_match('#^https?://[^\s/$.?\#][^\s]*$#i', $u)) {
        return false;
    }
    return !$httpsOnly || stripos($u, 'https://') === 0;
}

/** Abschnitt 7: hours zerlegen und prüfen. Gibt Fehlermeldungen zurück. */
function check_hours(string $s): array
{
    $err = [];
    if ($s === '') {
        return ['hours ist leer – Feld weglassen'];
    }
    if (trim($s) !== $s) {
        $err[] = 'hours hat führende oder folgende Leerzeichen';
    }
    $blocks = explode(';', $s);
    $used = [];
    foreach ($blocks as $n => $raw) {
        $blk = trim($raw);
        if ($n > 0 && substr($raw, 0, 1) !== ' ') {
            $err[] = 'Blöcke werden mit "; " getrennt (Semikolon + Leerzeichen)';
        }
        if ($blk === '') {
            $err[] = 'leerer Block in hours';
            continue;
        }
        if (!preg_match('#^([A-Za-zÄÖÜ,\-]+) (\d{2}:\d{2})-(\d{2}:\d{2})$#u', $blk, $m)) {
            $err[] = 'Block "' . $blk . '" passt nicht auf: Tage HH:MM-HH:MM';
            continue;
        }
        [, $dayList, $open, $close] = $m;
        foreach ([$open, $close] as $t) {
            [$h, $mi] = array_map('intval', explode(':', $t));
            if ($h > 23 || $mi > 59) {
                $err[] = 'Uhrzeit "' . $t . '" gibt es nicht';
            }
        }
        if ($open === $close) {
            $err[] = 'Block "' . $blk . '": Öffnen und Schließen sind gleich';
        }
        foreach (explode(',', $dayList) as $tok) {
            $days = [];
            if (isset(DAYS[$tok])) {
                $days[] = DAYS[$tok];
            } elseif (preg_match('#^(\w\w)-(\w\w)$#u', $tok, $r) && isset(DAYS[$r[1]], DAYS[$r[2]])) {
                for ($d = DAYS[$r[1]], $guard = 0; $guard < 8; $d = $d % 7 + 1, $guard++) {
                    $days[] = $d;
                    if ($d === DAYS[$r[2]]) {
                        break;
                    }
                }
            } else {
                $err[] = 'unbekannter Tag "' . $tok . '" (erlaubt: ' . implode(' ', array_keys(DAYS)) . ')';
                continue;
            }
            foreach ($days as $d) {
                if (isset($used[$d])) {
                    $err[] = 'Tag ' . array_search($d, DAYS, true) . ' kommt mehrfach vor';
                }
                $used[$d] = true;
            }
        }
    }
    return $err;
}

/* ---------- Prüflauf ---------- */

class Report
{
    public array $errors = [];
    public array $warns = [];
    public int $files = 0;

    public function err(string $file, string $msg, int $line = 0): void
    {
        $this->errors[] = ['file' => $file, 'line' => $line, 'msg' => $msg];
    }

    public function warn(string $file, string $msg, int $line = 0): void
    {
        $this->warns[] = ['file' => $file, 'line' => $line, 'msg' => $msg];
    }
}

function yaml_files(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    // Der Iterator baut die Pfade aus GENAU diesem Präfix – deshalb den
    // Relativpfad davon abschneiden, nicht von realpath() (sonst greift die
    // "_"-Regel bei relativer Wurzel nie).
    $base = rtrim(str_replace('\\', '/', $dir), '/');
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        $path = str_replace('\\', '/', $f->getPathname());
        $rel = substr($path, strlen($base) + 1);
        // ein Segment, das mit "_" beginnt (Entwurf/Klärfall), überspringt die Datei
        $draft = false;
        foreach (explode('/', $rel) as $seg) {
            if ($seg !== '' && $seg[0] === '_') {
                $draft = true;
                break;
            }
        }
        if ($draft) {
            continue;
        }
        $ext = strtolower($f->getExtension());
        if ($f->isFile() && ($ext === 'yaml' || $ext === 'yml')) {
            $out[] = $path; // .yml wird unten als Endungs-Fehler gemeldet
        }
    }
    sort($out);
    return $out;
}

function validate(array $roots, Report $r): void
{
    $ids = [];      // id => datei
    $coords = [];   // "lat,lng" => datei
    $files = [];    // realpath => [anzeige, rel, ccRoot, pfad] – realpath dedupt
    foreach ($roots as $root) {
        // Einzelne .yaml-Datei direkt prüfen
        if (is_file($root)) {
            if (!preg_match('/\.ya?ml$/i', $root)) {
                $r->err($root, 'keine .yaml-Datei');
                continue;
            }
            $rp = str_replace('\\', '/', (string)(realpath($root) ?: $root));
            $rel = implode('/', array_slice(explode('/', $rp), -4)); // land/bundesland/stadt/id.yaml
            $files[$rp] = ['connectors/' . $rel, $rel, '', $rp];
            continue;
        }
        if (!is_dir($root)) {
            $r->err($root, 'weder Ordner noch .yaml-Datei – Pfad prüfen');
            continue;
        }
        $real = str_replace('\\', '/', (string)(realpath($root) ?: $root));
        $name = basename($real);
        // Wurzel: connectors-Ordner (enthält Länderordner) oder direkt ein Länderordner
        $ccRoot = isset(COUNTRIES[$name]) ? $name : '';
        $isConnectorsRoot = false;
        foreach (array_keys(COUNTRIES) as $cc2) {
            if (is_dir($real . '/' . $cc2)) {
                $isConnectorsRoot = true;
                break;
            }
        }
        $found = yaml_files($root);
        if ($ccRoot === '' && !$isConnectorsRoot) {
            if ($found) {
                $r->err($name, 'Wurzel ist weder der connectors-Ordner noch ein Länderordner ('
                    . implode('/', array_keys(COUNTRIES)) . ') – bitte darauf zeigen');
            }
            continue; // nicht mit falscher Tiefe durch alle Dateien fluten
        }
        $cut = strlen($real) + 1;
        foreach ($found as $f) {
            $rp = str_replace('\\', '/', (string)(realpath($f) ?: $f));
            $files[$rp] = [$name . '/' . substr($rp, $cut), substr($rp, $cut), $ccRoot, $rp];
        }
    }
    uksort($files, fn($a, $b) => strcmp($files[$a][0], $files[$b][0]));

    foreach ($files as [$short, $rel, $ccRoot, $path]) {
        $r->files++;

        /* --- Ablage (Abschnitt 2): connectors/<land>/<bundesland>/<stadt>/<id>.yaml --- */
        $parts = explode('/', $rel);
        if (count($parts) !== ($ccRoot === '' ? 4 : 3)) {
            $r->err($short, 'Ablage muss connectors/<land>/<bundesland>/<stadt>/<id>.yaml sein');
            continue;
        }
        $cc = $ccRoot !== '' ? $ccRoot : array_shift($parts);
        [$area, $city] = $parts;
        $base = basename($path);
        $stem = substr($base, 0, -strlen(strrchr($base, '.') ?: ''));
        if (substr($base, -5) !== '.yaml') {
            $r->err($short, 'Endung muss .yaml sein, nicht ' . strrchr($base, '.'));
        }
        if (!isset(COUNTRIES[$cc])) {
            $r->err($short, 'unbekanntes Land "' . $cc . '" – erst in SPEC.md Abschnitt 8 eintragen');
            continue;
        }
        if (!in_array($area, AREAS[$cc], true)) {
            $r->err($short, 'unbekanntes Bundesland "' . $area . '" für ' . $cc);
        }
        if ($city !== slug($city)) {
            $r->err($short, 'Stadtordner "' . $city . '" ist kein sauberer Slug');
        }

        /* --- Datei (Abschnitt 4, G5) --- */
        $raw = (string)file_get_contents($path);
        if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
            $r->err($short, 'Datei hat ein BOM');
            $raw = substr($raw, 3);
        }
        if (strpos($raw, "\r") !== false) {
            $r->err($short, 'Zeilenenden müssen \n sein, nicht \r\n');
        }
        if (!preg_match('##u', $raw) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $raw)) {
            $r->err($short, 'Datei ist nicht sauberes UTF-8');
        }
        if ($raw !== '' && substr($raw, -1) !== "\n") {
            $r->warn($short, 'Datei endet ohne Zeilenumbruch');
        } elseif (substr($raw, -2) === "\n\n") {
            $r->warn($short, 'Datei endet mit mehreren Leerzeilen');
        }
        $firstLine = strtok($raw, "\n");
        if ($firstLine === false || substr(trim((string)$firstLine), 0, 1) !== '#') {
            $r->warn($short, 'erste Zeile sollte "# <name> – <city>" sein');
        }

        [$y, $line, $junk, $dupes] = read_flat($raw);
        foreach ($junk as [$ln, $txt]) {
            $r->err($short, 'unlesbare Zeile: ' . $txt, $ln);
        }
        foreach ($dupes as $k) {
            $r->err($short, 'Feld "' . $k . '" steht mehrfach in der Datei');
        }

        /* --- Pflichtfelder (Abschnitt 5.1) --- */
        foreach (['id', 'name', 'city', 'address', 'lat', 'lng', 'genres', 'checked'] as $k) {
            if (!isset($y[$k]) || trim((string)$y[$k]) === '') {
                $r->err($short, 'Pflichtfeld "' . $k . '" fehlt');
            }
        }
        if (empty($y['website']) && empty($y['source'])) {
            $r->err($short, 'ohne website ist source Pflicht – woher stammen die Angaben? (Regel G2)');
        }

        /* --- Reihenfolge und unbekannte Felder (Abschnitt 4/5.2) --- */
        $pos = -1;
        foreach (array_keys($y) as $k) {
            $i = array_search($k, ORDER, true);
            if ($i === false) {
                $r->warn($short, 'unbekanntes Feld "' . $k . '" (wird vom Leser übergangen)', $line[$k] ?? 0);
                continue;
            }
            if ($i < $pos) {
                $r->warn($short, 'Feld "' . $k . '" steht nicht in der Reihenfolge aus SPEC.md Abschnitt 5', $line[$k] ?? 0);
            }
            $pos = max($pos, $i);
        }

        /* --- id --- */
        $id = (string)($y['id'] ?? '');
        if ($id !== '') {
            if (!preg_match('/^[a-z0-9]{2,40}$/', $id)) {
                $r->err($short, 'id "' . $id . '" verstößt gegen ^[a-z0-9]{2,40}$', $line['id'] ?? 0);
            }
            if ($id !== $stem) {
                $r->err($short, 'Dateiname "' . $stem . '.yaml" und id "' . $id . '" müssen gleich sein');
            }
            if (isset($ids[$id])) {
                $r->err($short, 'id "' . $id . '" gibt es schon in ' . $ids[$id] . ' – Stadt-Slug anhängen');
            } else {
                $ids[$id] = $short;
            }
        }

        /* --- name --- */
        $name = (string)($y['name'] ?? '');
        if ($name !== '') {
            $len = mb_strlen($name, 'UTF-8');
            if ($len < 2 || $len > 60) {
                $r->err($short, 'name muss 2–60 Zeichen haben, hat ' . $len, $line['name'] ?? 0);
            }
            if (trim($name) !== $name) {
                $r->err($short, 'name hat Leerzeichen am Rand', $line['name'] ?? 0);
            }
        }

        /* --- city --- */
        $cityVal = (string)($y['city'] ?? '');
        if ($cityVal !== '') {
            $len = mb_strlen($cityVal, 'UTF-8');
            if ($len < 2 || $len > 40) {
                $r->err($short, 'city muss 2–40 Zeichen haben', $line['city'] ?? 0);
            }
            if (city_slug($cityVal) !== $city) {
                $r->err($short, 'city "' . $cityVal . '" ergibt Slug "' . city_slug($cityVal)
                    . '", der Ordner heißt aber "' . $city . '"', $line['city'] ?? 0);
            }
        }

        /* --- address --- */
        $addr = (string)($y['address'] ?? '');
        if ($addr !== '') {
            $len = mb_strlen($addr, 'UTF-8');
            if ($len < 4 || $len > 60) {
                $r->err($short, 'address muss 4–60 Zeichen haben, hat ' . $len, $line['address'] ?? 0);
            }
            if (preg_match('/\b\d{5}\b/', $addr)) {
                $r->err($short, 'address enthält eine Postleitzahl – nur Straße und Hausnummer', $line['address'] ?? 0);
            }
            if ($cityVal !== '' && preg_match('/(^|[\s,])' . preg_quote($cityVal, '/') . '\s*$/iu', $addr)) {
                $r->err($short, 'address enthält den Ortsnamen – nur Straße und Hausnummer', $line['address'] ?? 0);
            }
        }

        /* --- lat/lng --- */
        [$laMin, $laMax, $loMin, $loMax] = COUNTRIES[$cc];
        $la = $lo = null;
        foreach (['lat' => [$laMin, $laMax], 'lng' => [$loMin, $loMax]] as $k => [$min, $max]) {
            $v = (string)($y[$k] ?? '');
            if ($v === '') {
                continue;
            }
            if (!preg_match('/^-?\d{1,3}\.(\d{3,6})$/', $v, $dm)) {
                $r->err($short, $k . ' "' . $v . '" muss eine Dezimalzahl mit 3–6 Nachkommastellen sein', $line[$k] ?? 0);
                continue;
            }
            if (strlen($dm[1]) < 4) {
                $r->warn($short, $k . ' ' . $v . ' hat nur 3 Nachkommastellen (≈ 110 m ungenau)', $line[$k] ?? 0);
            }
            $f = (float)$v;
            if ($f < $min || $f > $max) {
                $r->err($short, $k . ' ' . $v . ' liegt außerhalb von ' . $cc
                    . ' (' . $min . '–' . $max . ') – lat und lng vertauscht?', $line[$k] ?? 0);
            }
            if ($k === 'lat') {
                $la = $v;
            } else {
                $lo = $v;
            }
        }
        if ($la !== null && $lo !== null) {
            $key = $la . ',' . $lo;
            if (isset($coords[$key])) {
                $r->err($short, 'gleiche Koordinate wie ' . $coords[$key] . ' – bitte nachmessen');
            } else {
                $coords[$key] = $short;
            }
        }

        /* --- website / source --- */
        if (isset($y['website']) && $y['website'] !== '' && !is_url($y['website'])) {
            $r->err($short, 'website ist keine absolute URL', $line['website'] ?? 0);
        }
        if (isset($y['website']) && stripos((string)$y['website'], 'http://') === 0) {
            $r->warn($short, 'website ist http:// – prüfen, ob https geht', $line['website'] ?? 0);
        }
        if (isset($y['source']) && mb_strlen((string)$y['source'], 'UTF-8') > 500) {
            $r->err($short, 'source ist länger als 500 Zeichen', $line['source'] ?? 0);
        }

        /* --- genres (Abschnitt 6) --- */
        $graw = (string)($y['genres'] ?? '');
        if ($graw !== '') {
            if ($graw !== implode(', ', array_map('trim', explode(',', $graw)))) {
                $r->err($short, 'genres müssen mit ", " getrennt sein', $line['genres'] ?? 0);
            }
            $gs = array_map('trim', explode(',', $graw));
            $gs = array_values(array_filter($gs, fn($g) => $g !== ''));
            if (!$gs) {
                $r->err($short, 'genres ist leer', $line['genres'] ?? 0);
            }
            if (count($gs) > 3) {
                $r->err($short, 'höchstens 3 Musikrichtungen, hier sind es ' . count($gs), $line['genres'] ?? 0);
            }
            if (count($gs) !== count(array_unique($gs))) {
                $r->err($short, 'genres enthält Dubletten', $line['genres'] ?? 0);
            }
            foreach ($gs as $g) {
                if (!in_array($g, GENRES, true)) {
                    $near = '';
                    foreach (GENRES as $ok) {
                        if (strcasecmp($ok, $g) === 0) {
                            $near = ' – gemeint ist "' . $ok . '"';
                        }
                    }
                    $r->err($short, 'unbekannte Musikrichtung "' . $g . '"' . $near
                        . ' (erlaubt: ' . implode(', ', GENRES) . ')', $line['genres'] ?? 0);
                }
            }
            if (count($gs) > 1 && in_array('Mixed', $gs, true)) {
                $r->err($short, '"Mixed" steht allein oder gar nicht (SPEC.md Abschnitt 6)', $line['genres'] ?? 0);
            }
        }

        /* --- hours (Abschnitt 7) --- */
        if (isset($y['hours'])) {
            foreach (check_hours((string)$y['hours']) as $msg) {
                $r->err($short, $msg, $line['hours'] ?? 0);
            }
        }

        /* --- about --- */
        if (isset($y['about'])) {
            $about = (string)$y['about'];
            $alen = mb_strlen($about, 'UTF-8');
            if ($alen < 40 || $alen > 220) {
                $r->err($short, 'about ist ' . $alen . ' Zeichen lang, erlaubt sind 40-220', $line['about'] ?? 0);
            }
            if ($alen && substr($about, -1) !== '.') {
                $r->warn($short, 'about soll aus ganzen Saetzen bestehen und mit einem Punkt enden', $line['about'] ?? 0);
            }
        }

        /* --- note --- */
        if (isset($y['note'])) {
            $note = (string)$y['note'];
            $len = mb_strlen($note, 'UTF-8');
            if ($len > 90) {
                $r->err($short, 'note ist ' . $len . ' Zeichen lang, erlaubt sind 90', $line['note'] ?? 0);
            }
            if ($len && substr($note, -1) === '.') {
                $r->warn($short, 'note endet mit einem Punkt', $line['note'] ?? 0);
            }
        }

        /* --- Datumsfelder --- */
        if (isset($y['pause'])) {
            $p = (string)$y['pause'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $p) || !checkdate((int)substr($p, 5, 2), (int)substr($p, 8, 2), (int)substr($p, 0, 4))) {
                $r->err($short, 'pause muss ein gültiges Datum JJJJ-MM-TT sein', $line['pause'] ?? 0);
            } elseif ($p < date('Y-m-d')) {
                $r->warn($short, 'pause liegt in der Vergangenheit – Feld entfernen', $line['pause'] ?? 0);
            }
        }
        if (isset($y['checked'])) {
            $c = (string)$y['checked'];
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $c)) {
                $r->err($short, 'checked muss JJJJ-MM sein', $line['checked'] ?? 0);
            } elseif ($c > date('Y-m')) {
                $r->err($short, 'checked liegt in der Zukunft', $line['checked'] ?? 0);
            } elseif ($c < date('Y-m', strtotime('-24 months'))) {
                $r->warn($short, 'checked ist älter als 24 Monate – bitte nachprüfen', $line['checked'] ?? 0);
            }
        }

        /* --- state --- */
        if (isset($y['state']) && !in_array($y['state'], AREAS[$cc], true)) {
            $r->err($short, 'state "' . $y['state'] . '" ist kein Bundesland-Slug für ' . $cc, $line['state'] ?? 0);
        }

        /* --- Extraktion (Abschnitt 5.2) --- */
        $hasScrape = false;
        foreach (array_keys($y) as $k) {
            if (strpos($k, 'scrape_') === 0 && $k !== 'scrape_url') {
                $hasScrape = true;
            }
        }
        if ($hasScrape && empty($y['scrape_url'])) {
            $r->err($short, 'scrape_*-Felder ohne scrape_url (Regel E1)');
        }
        foreach (['scrape_url', 'scrape_info_url', 'scrape_images_url'] as $k) {
            if (isset($y[$k]) && $y[$k] !== '' && !is_url((string)$y[$k])) {
                $r->err($short, $k . ' ist keine absolute URL (Regel E2)', $line[$k] ?? 0);
            }
        }
        foreach (ENUMS as $k => $vals) {
            if (isset($y[$k]) && !in_array($y[$k], $vals, true)) {
                $r->err($short, $k . ' muss ' . implode(' | ', $vals) . ' sein, ist "' . $y[$k] . '"', $line[$k] ?? 0);
            }
        }
        foreach (['scrape_from', 'scrape_to'] as $k) {
            if (!isset($y[$k]) || $y[$k] === '') {
                continue;
            }
            if (@preg_match('#' . $y[$k] . '#i', '') === false) {
                $r->err($short, $k . ' ist kein gültiger Regex', $line[$k] ?? 0);
            }
        }
        foreach (['eventim.', 'facebook.com/events', 'ra.co/events', 'eventbrite.'] as $bad) {
            if (isset($y['scrape_url']) && stripos((string)$y['scrape_url'], $bad) !== false) {
                $r->err($short, 'scrape_url zeigt auf ein fremdes Portal (Regel E4)', $line['scrape_url'] ?? 0);
            }
        }
        if (isset($y['website'], $y['scrape_url'])) {
            $h1 = parse_url((string)$y['website'], PHP_URL_HOST);
            $h2 = parse_url((string)$y['scrape_url'], PHP_URL_HOST);
            if ($h1 && $h2 && ltrim((string)$h1, 'w.') !== ltrim((string)$h2, 'w.')) {
                $r->warn($short, 'scrape_url liegt auf einem anderen Host als website', $line['scrape_url'] ?? 0);
            }
        }
    }
}

/* ---------- Aufruf ---------- */

$argv = $argv ?? [];
$opts = ['json' => false, 'strict' => false, 'quiet' => false];
$roots = [];
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--') === 0) {
        $k = substr($a, 2);
        if (!array_key_exists($k, $opts)) {
            fwrite(STDERR, "unbekannte Option --$k\n");
            exit(2);
        }
        $opts[$k] = true;
    } elseif ($a !== '' && $a[0] === '-') {
        fwrite(STDERR, "Optionen haben zwei Bindestriche: meintest du -$a?\n");
        exit(2);
    } else {
        $roots[] = rtrim(str_replace('\\', '/', $a), '/');
    }
}
if (!$roots) {
    $roots = [dirname(__DIR__) . '/connectors'];
}

$r = new Report();
validate($roots, $r);

$fail = count($r->errors) > 0 || ($opts['strict'] && count($r->warns) > 0);

if ($opts['json']) {
    echo json_encode([
        'spec' => SPEC_VERSION,
        'files' => $r->files,
        'errors' => $r->errors,
        'warnings' => $r->warns,
        'ok' => !$fail,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit($fail ? 1 : 0);
}

$fmt = function (array $list, string $tag) {
    foreach ($list as $e) {
        printf("%s %s%s: %s\n", $tag, $e['file'], $e['line'] ? ':' . $e['line'] : '', $e['msg']);
    }
};
if (!$opts['quiet'] || $r->errors) {
    $fmt($r->errors, 'FEHLER ');
}
if (!$opts['quiet']) {
    $fmt($r->warns, 'HINWEIS');
}
printf("\n%d Connectoren geprüft gegen SPEC %s – %d Fehler, %d Hinweise\n",
    $r->files, SPEC_VERSION, count($r->errors), count($r->warns));
exit($fail ? 1 : 0);
