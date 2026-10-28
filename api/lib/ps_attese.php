<?php
declare(strict_types=1);

/**
 * Live ER waiting times. There is no national feed: v1 only reads a regional JSON
 * configured in config.local.php ('ps_json_url'). Expected shape, flexible:
 * [{"ospedale": "...", "lat": 45.1, "lng": 7.6, "codice": "verde", "in_attesa": 4, "aggiornato": "..."}]
 * or {"items": [...]}. Returns [] when nothing is configured or reachable.
 */
function fetch_wait(float $lat, float $lng, float $maxKm = 30): array {
    $url = trim((string) (cfg()['ps_json_url'] ?? ''));
    if ($url === '') {
        return [];
    }
    $ck = cache_dir() . '/ps-' . md5($url) . '.json';
    if (is_file($ck) && filemtime($ck) > time() - 300) {
        $data = json_decode((string) file_get_contents($ck), true);
    } else {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['User-Agent: ApertoAdesso/1.0', 'Accept: application/json'],
        ]);
        $data = json_decode((string) curl_exec($ch), true);
        curl_close($ch);
        if (is_array($data)) {
            file_put_contents($ck, json_encode($data));
        }
    }
    if (!is_array($data)) {
        return [];
    }
    $items = $data['items'] ?? $data;
    $out = [];
    foreach ($items as $it) {
        if (!is_array($it) || !isset($it['lat'], $it['lng'])) {
            continue;
        }
        $km = haversine($lat, $lng, (float) $it['lat'], (float) $it['lng']);
        if ($km > $maxKm) {
            continue;
        }
        $out[] = [
            'ospedale' => (string) ($it['ospedale'] ?? $it['name'] ?? 'Pronto soccorso'),
            'codice' => (string) ($it['codice'] ?? ''),
            'in_attesa' => isset($it['in_attesa']) ? (int) $it['in_attesa'] : null,
            'fonte' => (string) ($it['fonte'] ?? parse_url($url, PHP_URL_HOST)),
            'aggiornato' => (string) ($it['aggiornato'] ?? ''),
            'km' => round($km, 1),
        ];
    }
    usort($out, static fn($a, $b) => $a['km'] <=> $b['km']);
    return $out;
}
