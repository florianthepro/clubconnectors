<?php
/*
 * Optionale Flaggen-API: listet die vorhandenen Landesflaggen (<cc>.ico) in
 * diesem Ordner als JSON. Die Bilder selbst liefert der Webserver direkt aus
 * (z. B. /flag/de.ico) – die Haupt-App bindet sie so ein und braucht dieses
 * Skript nicht. Neue Region = passende <cc>.ico-Datei hier ablegen.
 */
declare(strict_types=1);
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

$flags = array_map(fn($f) => basename($f, '.ico'), glob(__DIR__ . '/*.ico') ?: []);
sort($flags);
echo json_encode(['count' => count($flags), 'flags' => $flags], JSON_UNESCAPED_SLASHES);
