<?php
declare(strict_types=1);

const CATEGORIES = [
    'farmacia' => 'Farmacia',
    'farmacia_turno' => 'Farmacia di turno',
    'guardia_medica' => 'Guardia medica',
    'pronto_soccorso' => 'Pronto soccorso',
    'poste' => 'Poste',
    'comune' => 'Comune',
    'caf' => 'CAF / patronato',
    'veterinario' => 'Veterinario',
];

const OVERPASS_ENDPOINTS = [
    'https://overpass.openstreetmap.fr/api/interpreter',
    'https://overpass-api.de/api/interpreter',
    'https://overpass.kumi.systems/api/interpreter',
    'https://overpass.private.coffee/api/interpreter',
];

function cache_dir(): string {
    $dir = dirname(__DIR__) . '/cache';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

/** Race the same query on every Overpass mirror, first valid answer wins. */
function overpass_race(string $q): ?array {
    $multi = curl_multi_init();
    $handles = [];
    foreach (OVERPASS_ENDPOINTS as $ep) {
        $ch = curl_init($ep);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'data=' . rawurlencode($q),
            CURLOPT_TIMEOUT => 28,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['User-Agent: ApertoAdesso/1.0', 'Content-Type: application/x-www-form-urlencoded'],
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[] = $ch;
    }
    $found = null;
    do {
        curl_multi_exec($multi, $running);
        while ($info = curl_multi_info_read($multi)) {
            $ch = $info['handle'];
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $json = json_decode((string) curl_multi_getcontent($ch), true);
            // An empty "elements" is a valid answer (nothing nearby); a remark means the server gave up.
            if ($code > 0 && $code < 400 && isset($json['elements']) && is_array($json['elements']) && empty($json['remark'])) {
                $found = $json['elements'];
                break 2;
            }
        }
        if ($running) {
            curl_multi_select($multi, 0.4);
        }
    } while ($running);
    foreach ($handles as $ch) {
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    return $found;
}

/**
 * Overpass selectors per category group. Name regexes server-side make public mirrors time out,
 * so we only filter on keys/values here and classify by name in classify_place().
 */
const OSM_GROUPS = [
    'farmacia' => ['["amenity"="pharmacy"]'],
    'pronto_soccorso' => ['["amenity"="hospital"]', '["emergency"="emergency_ward_entrance"]'],
    'guardia_medica' => ['["amenity"~"^(clinic|doctors)$"]["name"]', '["healthcare"~"^(clinic|centre|doctor)$"]["name"]'],
    'poste' => ['["amenity"="post_office"]'],
    'comune' => ['["amenity"="townhall"]', '["office"="government"]["name"]'],
    'caf' => ['["office"~"^(association|ngo|union|tax_advisor|accountant|government)$"]["name"]', '["amenity"="social_facility"]["name"]'],
    'veterinario' => ['["amenity"="veterinary"]'],
];

/** Which group an element was fetched for (an element can match several). */
function osm_groups_of(array $tags): array {
    $am = $tags['amenity'] ?? '';
    $hc = $tags['healthcare'] ?? '';
    $of = $tags['office'] ?? '';
    $has = isset($tags['name']);
    $g = [];
    if ($am === 'pharmacy') $g[] = 'farmacia';
    if ($am === 'hospital' || ($tags['emergency'] ?? '') === 'emergency_ward_entrance') $g[] = 'pronto_soccorso';
    if ($has && (in_array($am, ['clinic', 'doctors'], true) || in_array($hc, ['clinic', 'centre', 'doctor'], true))) $g[] = 'guardia_medica';
    if ($am === 'post_office') $g[] = 'poste';
    if ($am === 'townhall' || ($has && $of === 'government')) $g[] = 'comune';
    if ($has && (in_array($of, ['association', 'ngo', 'union', 'tax_advisor', 'accountant', 'government'], true) || $am === 'social_facility')) $g[] = 'caf';
    if ($am === 'veterinary') $g[] = 'veterinario';
    return $g;
}

/** Elements for the requested groups, cached 15 min per cell+radius+group. Null if Overpass failed. */
function overpass_places(float $lat, float $lng, int $radiusKm, array $groups): ?array {
    // A bbox is far cheaper than around: on public mirrors; distance is filtered in PHP afterwards.
    $dLat = $radiusKm / 111.0;
    $dLng = $radiusKm / (111.0 * max(0.2, cos(deg2rad($lat))));
    $bbox = sprintf('%.5f,%.5f,%.5f,%.5f', $lat - $dLat, $lng - $dLng, $lat + $dLat, $lng + $dLng);
    $cell = sprintf('%.3f:%.3f:%d', $lat, $lng, $radiusKm);
    $byGroup = [];
    $missing = [];
    foreach ($groups as $g) {
        $ck = cache_dir() . '/osm-' . md5("$cell:$g") . '.json';
        if (is_file($ck) && filemtime($ck) > time() - 900) {
            $byGroup[$g] = json_decode((string) file_get_contents($ck), true) ?: [];
        } else {
            $missing[] = $g;
        }
    }
    $failed = false;
    if ($missing) {
        $sel = '';
        foreach ($missing as $g) {
            foreach (OSM_GROUPS[$g] as $f) {
                $sel .= "nwr$f;";
            }
        }
        $els = overpass_race("[out:json][timeout:25][bbox:$bbox];($sel);out center tags 5000;");
        if ($els === null) {
            $failed = true;
        } else {
            foreach ($missing as $g) {
                $byGroup[$g] = [];
            }
            foreach ($els as $el) {
                foreach (array_intersect(osm_groups_of($el['tags'] ?? []), $missing) as $g) {
                    $byGroup[$g][] = $el;
                }
            }
            foreach ($missing as $g) {
                file_put_contents(cache_dir() . '/osm-' . md5("$cell:$g") . '.json', json_encode($byGroup[$g]));
            }
        }
    }
    if ($failed && !$byGroup) {
        return null;
    }
    $seen = [];
    $out = [];
    foreach ($byGroup as $list) {
        foreach ($list as $el) {
            $k = ($el['type'] ?? '') . ($el['id'] ?? '');
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = $el;
            }
        }
    }
    return $failed ? ['partial' => true, 'elements' => $out] : $out;
}

function classify_place(array $tags, string $name): ?string {
    $amenity = $tags['amenity'] ?? '';
    $n = mb_strtolower($name);
    if ($amenity === 'pharmacy') {
        // Parafarmacie do not dispense drugs and have no turni.
        return (($tags['dispensing'] ?? '') === 'no' || str_contains($n, 'parafarmacia')) ? null : 'farmacia';
    }
    if ($amenity === 'post_office') {
        return ($tags['post_office:type'] ?? '') === 'parcel_locker' ? null : 'poste';
    }
    if ($amenity === 'veterinary') {
        return 'veterinario';
    }
    if (str_contains($n, 'guardia medica') || str_contains($n, 'continuità assistenziale') || str_contains($n, 'continuita assistenziale') || str_contains($n, 'ex guardia')) {
        return 'guardia_medica';
    }
    $emergency = $tags['emergency'] ?? '';
    if (str_contains($n, 'ambulanz')) {
        return null; // ambulance-only entrance, not for the public
    }
    if (str_contains($n, 'pronto soccorso') || $emergency === 'emergency_ward_entrance' || ($amenity === 'hospital' && $emergency === 'yes')) {
        return 'pronto_soccorso';
    }
    if ($amenity === 'townhall' || (($tags['office'] ?? '') === 'government' && preg_match('/\b(comune|municipio)\b/iu', $name))) {
        return 'comune';
    }
    if (preg_match('/\b(caf|patronato|inas|inca|ital|acli)\b/iu', $name)) {
        return 'caf';
    }
    return null;
}

function place_key(string $type, $id): string {
    return $type . ':' . $id;
}

/** Spec §4: open according to hours and (Sunday, holiday, ≥20 or <8), or 24/7. */
function is_turno_heuristic(?bool $open, ?string $oh, DateTimeImmutable $at, bool $holiday): bool {
    if ($open !== true) {
        return false;
    }
    if (preg_match('/^\s*24\/7\s*$/', (string) $oh)) {
        return true;
    }
    $hour = (int) $at->format('G');
    return $holiday || (int) $at->format('N') === 7 || $hour >= 20 || $hour < 8;
}

function normalize_element(array $el, float $lat, float $lng, DateTimeImmutable $at, bool $holiday): ?array {
    $tags = $el['tags'] ?? [];
    if (!is_array($tags)) {
        return null;
    }
    $name = $tags['name:it'] ?? $tags['name'] ?? '';
    $cat = classify_place($tags, $name);
    if (!$cat) {
        return null;
    }
    $plat = (float) ($el['lat'] ?? $el['center']['lat'] ?? 0);
    $plng = (float) ($el['lon'] ?? $el['center']['lon'] ?? 0);
    if (!$plat || !$plng) {
        return null;
    }
    $oh = $tags['opening_hours'] ?? null;
    $open = oh_is_open($oh, $at, $holiday);
    $phone = $tags['phone'] ?? $tags['contact:phone'] ?? $tags['contact:mobile'] ?? null;
    $addr = trim(implode(' ', array_filter([
        trim(($tags['addr:street'] ?? '') . ' ' . ($tags['addr:housenumber'] ?? '')),
        $tags['addr:postcode'] ?? null,
        $tags['addr:city'] ?? null,
    ])));
    $osmType = $el['type'] ?? 'node';
    $osmId = $el['id'] ?? 0;
    return [
        'key' => place_key($osmType, $osmId),
        'source' => 'openstreetmap',
        'category' => $cat,
        'name' => $name !== '' ? $name : CATEGORIES[$cat],
        'lat' => $plat,
        'lng' => $plng,
        'km' => round(haversine($lat, $lng, $plat, $plng), 2),
        'open' => $open,
        'turno' => $cat === 'farmacia' && is_turno_heuristic($open, $oh, $at, $holiday),
        'opening_hours' => $oh,
        'phone' => $phone,
        'website' => $tags['website'] ?? $tags['contact:website'] ?? null,
        'address' => $addr !== '' ? $addr : null,
        'operator' => $tags['operator'] ?? null,
        'osm' => "https://www.openstreetmap.org/$osmType/$osmId",
    ];
}

/** The Ministry renames the file daily (FRM_FARMA_5_YYYYMMDD.csv): read the link from the dataset page. */
function farmacie_csv_urls(): array {
    $urls = [];
    $ch = curl_init('https://www.dati.salute.gov.it/it/dataset/farmacie/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['User-Agent: ApertoAdesso/1.0'],
    ]);
    $html = (string) curl_exec($ch);
    curl_close($ch);
    if (preg_match_all('#(?:https?://www\.dati\.salute\.gov\.it)?(/[A-Za-z0-9_/.-]*FRM_FARMA_\d+_\d+\.csv)#i', $html, $m)) {
        foreach (array_unique($m[1]) as $path) {
            $urls[] = 'https://www.dati.salute.gov.it' . $path;
        }
    }
    return array_merge($urls, cfg()['farmacie_csv_urls'] ?? []);
}

/** Refresh the Ministry CSV (24 h) and return the compact list of active pharmacies with coordinates. */
function load_farmacie_csv(bool $force = false): array {
    $dir = cache_dir();
    $json = $dir . '/farmacie.json';
    if (!$force && is_file($json) && filemtime($json) > time() - 86400) {
        return json_decode((string) file_get_contents($json), true) ?: [];
    }
    foreach (farmacie_csv_urls() as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['User-Agent: ApertoAdesso/1.0'],
        ]);
        $bin = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300 && is_string($bin) && strlen($bin) > 100000 && !preg_match('/^\s*</', $bin)) {
            file_put_contents($dir . '/farmacie.csv', $bin);
            $rows = parse_farmacie_csv($dir . '/farmacie.csv');
            if ($rows) {
                file_put_contents($json, json_encode($rows, JSON_UNESCAPED_UNICODE));
                return $rows;
            }
        }
    }
    // Download failed: keep serving the stale copy rather than nothing.
    if (is_file($json)) {
        touch($json, time() - 86400 + 3600);
        return json_decode((string) file_get_contents($json), true) ?: [];
    }
    return [];
}

function parse_farmacie_csv(string $file): array {
    $fh = fopen($file, 'r');
    if (!$fh) {
        return [];
    }
    $first = (string) fgets($fh);
    $sep = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
    $header = array_map(static fn($h) => preg_replace('/[^a-z]/', '', strtolower((string) $h)), str_getcsv(trim($first), $sep));
    $idx = static function (array $cands) use ($header): ?int {
        foreach ($cands as $c) {
            $i = array_search($c, $header, true);
            if ($i !== false) {
                return $i;
            }
        }
        return null;
    };
    $iCod = $idx(['codfarmacia', 'codiceidentificativofarmacia']);
    $iName = $idx(['descrizionefarmacia', 'denominazione']);
    $iInd = $idx(['indirizzo']);
    $iCap = $idx(['cap']);
    $iCom = $idx(['comune', 'descrizionecomune']);
    $iProv = $idx(['siglaprovincia']);
    $iEnd = $idx(['datafinevalidita']);
    $iLat = $idx(['latitudine']);
    $iLng = $idx(['longitudine']);
    if ($iLat === null || $iLng === null) {
        fclose($fh);
        return [];
    }
    $clean = static fn($v) => trim(html_entity_decode(mb_convert_encoding((string) $v, 'UTF-8', 'UTF-8, ISO-8859-1'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $rows = [];
    while (($row = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
        if (count($row) < count($header)) {
            continue;
        }
        if ($iEnd !== null && !in_array(trim((string) $row[$iEnd]), ['-', ''], true)) {
            continue; // closed pharmacy
        }
        $lat = (float) str_replace(',', '.', (string) $row[$iLat]);
        $lng = (float) str_replace(',', '.', (string) $row[$iLng]);
        if ($lat < 35 || $lat > 48 || $lng < 6 || $lng > 19) {
            continue;
        }
        $name = $iName !== null ? $clean($row[$iName]) : '';
        $rows[] = [
            'c' => $iCod !== null ? $clean($row[$iCod]) : '',
            'n' => $name !== '' ? $name : 'Farmacia',
            'la' => round($lat, 6),
            'lo' => round($lng, 6),
            'a' => trim(implode(' ', array_filter([
                $iInd !== null ? $clean($row[$iInd]) : '',
                $iCap !== null ? $clean($row[$iCap]) : '',
                $iCom !== null ? mb_convert_case($clean($row[$iCom]), MB_CASE_TITLE) : '',
                $iProv !== null ? '(' . $clean($row[$iProv]) . ')' : '',
            ]))),
        ];
    }
    fclose($fh);
    // Many rows carry the town centroid instead of the real position: drop coordinates shared by 3+ pharmacies.
    $count = [];
    foreach ($rows as $r) {
        $k = $r['la'] . ',' . $r['lo'];
        $count[$k] = ($count[$k] ?? 0) + 1;
    }
    return array_values(array_filter($rows, static fn($r) => $count[$r['la'] . ',' . $r['lo']] < 3));
}

function ministero_place(array $f, float $lat, float $lng): array {
    return [
        'key' => 'min:' . ($f['c'] !== '' ? $f['c'] : md5($f['n'] . $f['la'])),
        'source' => 'ministero',
        'category' => 'farmacia',
        'name' => $f['n'],
        'lat' => $f['la'],
        'lng' => $f['lo'],
        'km' => round(haversine($lat, $lng, $f['la'], $f['lo']), 2),
        'open' => null,
        'turno' => false,
        'opening_hours' => null,
        'phone' => null,
        'website' => null,
        'address' => $f['a'] !== '' ? $f['a'] : null,
        'operator' => null,
        'osm' => null,
    ];
}

function merge_ministero(array $places, float $lat, float $lng, int $radiusKm): array {
    try {
        $csv = load_farmacie_csv();
    } catch (Throwable) {
        return $places;
    }
    $dLat = $radiusKm / 111.0;
    $dLng = $radiusKm / (111.0 * max(0.2, cos(deg2rad($lat))));
    $osmPharm = [];
    $osmIdx = [];
    foreach ($places as $i => $p) {
        if ($p['category'] === 'farmacia') {
            $osmPharm[] = $p;
            $osmIdx[] = $i;
        }
    }
    foreach ($csv as $f) {
        if (abs($f['la'] - $lat) > $dLat || abs($f['lo'] - $lng) > $dLng) {
            continue;
        }
        if (haversine($lat, $lng, $f['la'], $f['lo']) > $radiusKm) {
            continue;
        }
        $tok = pharmacy_tokens($f['n']);
        foreach ($osmPharm as $i => $p) {
            $d = haversine($p['lat'], $p['lng'], $f['la'], $f['lo']);
            if ($d < 0.08 || ($d < 0.3 && array_intersect($tok, pharmacy_tokens($p['name'])))) {
                // Same pharmacy: OSM wins, the Ministry fills the address if OSM lacks it.
                if (empty($p['address']) && $f['a'] !== '') {
                    $places[$osmIdx[$i]]['address'] = $f['a'];
                }
                continue 2;
            }
        }
        $places[] = ministero_place($f, $lat, $lng);
    }
    return $places;
}

function pharmacy_tokens(string $name): array {
    static $stop = ['farmacia', 'farmacie', 'comunale', 'dott', 'dottssa', 'dottori', 'dottor', 'della', 'delle', 'dello', 'degli', 'snc', 'sas', 'srl', 'spa', 'societa', 'ditta'];
    $words = preg_split('/[^a-z0-9]+/', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name)) ?: [];
    return array_values(array_diff(array_filter($words, static fn($w) => strlen($w) >= 4), $stop));
}

function attach_reports(array $places, DateTimeImmutable $at): array {
    if (!$places) {
        return $places;
    }
    $keys = array_column($places, 'key');
    $in = implode(',', array_fill(0, count($keys), '?'));
    $st = db()->prepare("SELECT place_key, kind, COUNT(*) c FROM aperto_reports WHERE expires_at > ? AND created_at <= ? AND place_key IN ($in) GROUP BY place_key, kind");
    $ts = $at->format('Y-m-d H:i:s');
    $st->execute(array_merge([$ts, $ts], $keys));
    $map = [];
    while ($r = $st->fetch()) {
        $map[$r['place_key']][$r['kind']] = (int) $r['c'];
    }
    foreach ($places as &$p) {
        $rep = $map[$p['key']] ?? [];
        $p['reports'] = (object) $rep;
        $ap = $rep['aperto'] ?? 0;
        $ch = $rep['chiuso'] ?? 0;
        if ($ap > $ch) {
            $p['open'] = true;
        } elseif ($ch > $ap) {
            $p['open'] = false;
        }
        if (($rep['di_turno'] ?? 0) > 0 && $p['category'] === 'farmacia') {
            $p['turno'] = true;
            $p['open'] = true;
        }
    }
    unset($p);
    return $places;
}

/** @return array{places: array, osm_ok: bool} */
function search_places(float $lat, float $lng, int $radiusKm, array $cats, DateTimeImmutable $at): array {
    $groups = array_keys(OSM_GROUPS);
    if ($cats) {
        $groups = array_values(array_intersect($groups, array_map(static fn($c) => $c === 'farmacia_turno' ? 'farmacia' : $c, $cats)));
    }
    $els = overpass_places($lat, $lng, $radiusKm, $groups);
    $osmOk = true;
    if ($els === null) {
        $osmOk = false;
        $els = [];
    } elseif (isset($els['partial'])) {
        $osmOk = false;
        $els = $els['elements'];
    }
    $holiday = is_italian_holiday($at);
    $places = [];
    foreach ($els as $el) {
        $p = normalize_element($el, $lat, $lng, $at, $holiday);
        if ($p && $p['km'] <= $radiusKm) {
            $places[] = $p;
        }
    }
    if (!$cats || in_array('farmacia', $cats, true) || in_array('farmacia_turno', $cats, true)) {
        $places = merge_ministero($places, $lat, $lng, $radiusKm);
    }
    $places = attach_reports($places, $at);
    if ($cats) {
        $places = array_values(array_filter($places, static function ($p) use ($cats) {
            if (in_array($p['category'], $cats, true)) {
                return true;
            }
            return in_array('farmacia_turno', $cats, true) && !empty($p['turno']);
        }));
    }
    usort($places, static fn($a, $b) => $a['km'] <=> $b['km']);
    return ['places' => array_slice($places, 0, 250), 'osm_ok' => $osmOk];
}

/** Single place by key: "node:123" / "way:…" / "relation:…" from OSM, "min:CODE" from the Ministry list. */
function find_place(string $key, ?float $lat, ?float $lng, DateTimeImmutable $at): ?array {
    $holiday = is_italian_holiday($at);
    if (preg_match('/^min:(.+)$/', $key, $m)) {
        foreach (load_farmacie_csv() as $f) {
            if ($f['c'] === $m[1] || md5($f['n'] . $f['la']) === $m[1]) {
                $p = ministero_place($f, $lat ?? $f['la'], $lng ?? $f['lo']);
                return attach_reports([$p], $at)[0];
            }
        }
        return null;
    }
    if (!preg_match('/^(node|way|relation):(\d+)$/', $key, $m)) {
        return null;
    }
    $ck = cache_dir() . '/place-' . $m[1] . '-' . $m[2] . '.json';
    if (is_file($ck) && filemtime($ck) > time() - 3600) {
        $el = json_decode((string) file_get_contents($ck), true);
    } else {
        $els = overpass_race("[out:json][timeout:20];{$m[1]}({$m[2]});out center tags;");
        $el = $els[0] ?? null;
        if ($el) {
            file_put_contents($ck, json_encode($el));
        }
    }
    if (!$el) {
        return null;
    }
    $plat = (float) ($el['lat'] ?? $el['center']['lat'] ?? 0);
    $plng = (float) ($el['lon'] ?? $el['center']['lon'] ?? 0);
    $p = normalize_element($el, $lat ?? $plat, $lng ?? $plng, $at, $holiday);
    return $p ? attach_reports([$p], $at)[0] : null;
}
