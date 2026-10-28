<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Rome');

function is_local(): bool {
    return !isset($_SERVER['SERVER_ADDR']) || in_array($_SERVER['SERVER_ADDR'], ['127.0.0.1', '::1', 'localhost'], true);
}

/** Un file della cartella private/ del sito (www.gianlucadario.com), cercato nelle cartelle superiori. */
function site_private(string $file): ?string {
    for ($dir = __DIR__, $i = 0; $i < 7; $i++, $dir = dirname($dir)) {
        if (is_file($dir . '/private/' . $file)) {
            return $dir . '/private/' . $file;
        }
    }
    return null;
}

function cfg(): array {
    static $c;
    if (!$c) {
        $c = require dirname(__DIR__) . '/config.php';
        // sul sito: segreti (cron_token, chiavi) in private/aperto-adesso.php; in sviluppo: config.local.php
        foreach ([site_private('aperto-adesso.php'), dirname(__DIR__) . '/config.local.php'] as $file) {
            if ($file && is_file($file)) {
                $extra = require $file;
                if (is_array($extra)) {
                    $c = array_replace_recursive($c, $extra);
                }
            }
        }
        $c['debug'] ??= is_local();
    }
    return $c;
}

/** Indirizzo dell'app (per i link nelle email): da config, altrimenti dalla richiesta. */
function app_url(): string {
    if (!empty(cfg()['app_url'])) {
        return rtrim((string) cfg()['app_url'], '/');
    }
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/index.php', 2)), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base;
}

function db(): PDO {
    static $pdo;
    if ($pdo) {
        return $pdo;
    }
    $c = cfg()['db'];
    // sul sito: credenziali in /private/db.php (sezione "aperto"), DB condiviso con altre app: tabelle aperto_
    if ($file = site_private('db.php')) {
        $all = require $file;
        if (isset($all['aperto'])) {
            $p = $all['aperto'][is_local() ? 'local' : 'prod'];
            $c = ['host' => $p['host'], 'name' => $p['db'], 'user' => $p['user'], 'pass' => $p['password'], 'charset' => 'utf8mb4', 'create' => false];
        }
    }
    $name = preg_replace('/[^a-z0-9_]/i', '', $c['name']);
    if ($c['create'] ?? true) {
        $root = new PDO("mysql:host={$c['host']};charset={$c['charset']}", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $root->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    $pdo = new PDO(
        "mysql:host={$c['host']};dbname={$name};charset={$c['charset']}",
        $c['user'], $c['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    schema_install($pdo);
    return $pdo;
}

function schema_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS aperto_users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        email_verified TINYINT(1) NOT NULL DEFAULT 0,
        lat DECIMAL(9,6) NULL, lng DECIMAL(9,6) NULL,
        place_name VARCHAR(160) NULL, radius_km INT NOT NULL DEFAULT 5,
        cats VARCHAR(200) NOT NULL DEFAULT 'farmacia,pronto_soccorso,poste,comune',
        alert_on TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS aperto_sessions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS aperto_email_tokens (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        purpose VARCHAR(20) NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS aperto_favorites (
        user_id INT UNSIGNED NOT NULL,
        place_key VARCHAR(80) NOT NULL,
        snapshot MEDIUMTEXT NULL,
        PRIMARY KEY (user_id, place_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS aperto_reports (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        place_key VARCHAR(80) NOT NULL,
        kind VARCHAR(20) NOT NULL,
        note VARCHAR(200) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        INDEX (place_key, expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS aperto_rate_limits (
        ip VARCHAR(45) NOT NULL, action VARCHAR(40) NOT NULL, window_start DATETIME NOT NULL, hits INT UNSIGNED NOT NULL DEFAULT 1,
        PRIMARY KEY (ip, action, window_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS aperto_alert_log (
        user_id INT UNSIGNED NOT NULL, sent_on DATE NOT NULL, PRIMARY KEY (user_id, sent_on)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Preferenze di ricerca (filtri iniziali), aggiunte dopo la prima versione: le tabelle online esistono già.
    // NULL = mai scelte, si usano quelle di base.
    $have = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'aperto_users'")->fetchAll(PDO::FETCH_COLUMN);
    $add = [
        'pref_cats' => 'VARCHAR(200) NULL',
        'pref_radius' => 'TINYINT UNSIGNED NULL',
        'pref_only_open' => 'TINYINT(1) NULL',
    ];
    foreach ($add as $col => $def) {
        if (!in_array($col, $have, true)) {
            $pdo->exec("ALTER TABLE aperto_users ADD COLUMN $col $def");
        }
    }
}

function cors(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function json_body(): array {
    $raw = file_get_contents('php://input') ?: '';
    $data = $raw === '' ? [] : json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function out(mixed $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $msg, int $code = 400): never { out(['error' => $msg], $code); }

function rate_limit(string $action, int $max): void {
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0'), 0, 45);
    $hour = (new DateTimeImmutable('now'))->format('Y-m-d H:00:00');
    db()->prepare('INSERT INTO aperto_rate_limits (ip, action, window_start, hits) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE hits = hits + 1')
        ->execute([$ip, $action, $hour]);
    $q = db()->prepare('SELECT hits FROM aperto_rate_limits WHERE ip=? AND action=? AND window_start=?');
    $q->execute([$ip, $action, $hour]);
    if ((int) $q->fetchColumn() > $max) {
        fail('Troppe richieste, riprova tra un’ora.', 429);
    }
}

function route_path(): string {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if ($base !== '' && strpos($path, $base) === 0) {
        $path = substr($path, strlen($base));
    }
    return rtrim($path ?: '/', '/') ?: '/';
}

function bearer(): ?string {
    // Apache + mod_rewrite often hides the header: fall back to the redirect copy and getallheaders().
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) $h = $v;
        }
    }
    return preg_match('/Bearer\s+(.+)/i', $h, $m) ? trim($m[1]) : null;
}

function public_user(array $u): array {
    return [
        'id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'],
        'email_verified' => (bool) $u['email_verified'],
        'lat' => $u['lat'] !== null ? (float) $u['lat'] : null,
        'lng' => $u['lng'] !== null ? (float) $u['lng'] : null,
        'place_name' => $u['place_name'], 'radius_km' => (int) $u['radius_km'],
        'cats' => $u['cats'] !== '' ? explode(',', $u['cats']) : [], 'alert_on' => (bool) $u['alert_on'],
        // filtri iniziali della ricerca; null se l'utente non li ha mai scelti
        'prefs' => ($u['pref_cats'] ?? null) === null ? null : [
            'cats' => $u['pref_cats'] !== '' ? explode(',', $u['pref_cats']) : [],
            'r' => (int) $u['pref_radius'],
            'only_open' => (bool) $u['pref_only_open'],
        ],
    ];
}

function issue_token(int $userId): string {
    $raw = bin2hex(random_bytes(32));
    $exp = (new DateTimeImmutable('+' . ((int) cfg()['token_days']) . ' days'))->format('Y-m-d H:i:s');
    db()->prepare('INSERT INTO aperto_sessions (user_id, token_hash, expires_at) VALUES (?,?,?)')
        ->execute([$userId, hash('sha256', $raw), $exp]);
    return $raw;
}

function require_user(): array {
    $raw = bearer();
    if (!$raw) fail('Devi entrare.', 401);
    $st = db()->prepare('SELECT u.* FROM aperto_sessions s JOIN aperto_users u ON u.id=s.user_id WHERE s.token_hash=? AND s.expires_at > NOW()');
    $st->execute([hash('sha256', $raw)]);
    $u = $st->fetch();
    if (!$u) fail('Sessione scaduta.', 401);
    return $u;
}

function optional_user(): ?array {
    $raw = bearer();
    if (!$raw) return null;
    $st = db()->prepare('SELECT u.* FROM aperto_sessions s JOIN aperto_users u ON u.id=s.user_id WHERE s.token_hash=? AND s.expires_at > NOW()');
    $st->execute([hash('sha256', $raw)]);
    $u = $st->fetch();
    return $u ?: null;
}

function issue_email_token(int $userId, string $purpose, string $ttl): string {
    $raw = bin2hex(random_bytes(24));
    db()->prepare('INSERT INTO aperto_email_tokens (user_id, purpose, token_hash, expires_at) VALUES (?,?,?,?)')
        ->execute([$userId, $purpose, hash('sha256', $raw), (new DateTimeImmutable($ttl))->format('Y-m-d H:i:s')]);
    return $raw;
}

function app_mail(string $to, string $subject, string $html): void {
    // sul sito: la casella SMTP comune (private/mailer.php); in locale le email finiscono in data/outbox
    if (!is_local() && ($mailer = site_private('mailer.php'))) {
        require_once $mailer;
        site_send_mail($to, $subject, $html);
        return;
    }
    $dir = dirname(__DIR__) . '/data/outbox';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = date('Ymd-His') . '-' . preg_replace('/[^a-z0-9]+/i', '-', $to) . '.html';
    file_put_contents($dir . '/' . $name, "<p><strong>A:</strong> ".htmlspecialchars($to)."<br><strong>Oggetto:</strong> ".htmlspecialchars($subject)."</p>".$html);
}

/** Nominatim with a 7-day disk cache and at most 1 request/second (usage policy). */
function nominatim(string $endpoint, array $params): mixed {
    $dir = dirname(__DIR__) . '/cache/geo';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $url = 'https://nominatim.openstreetmap.org/' . $endpoint . '?' . http_build_query($params + ['format' => 'jsonv2', 'accept-language' => 'it']);
    $ck = $dir . '/' . md5($url) . '.json';
    if (is_file($ck) && filemtime($ck) > time() - 7 * 86400) {
        return json_decode((string) file_get_contents($ck), true);
    }
    $lock = fopen($dir . '/.throttle', 'c+');
    flock($lock, LOCK_EX);
    $last = (float) stream_get_contents($lock);
    $wait = $last + 1.0 - microtime(true);
    if ($wait > 0) usleep((int) ($wait * 1e6));
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_HTTPHEADER => ['User-Agent: ApertoAdesso/1.0 (localhost; educational)']]);
    $body = (string) curl_exec($ch);
    curl_close($ch);
    ftruncate($lock, 0);
    rewind($lock);
    fwrite($lock, (string) microtime(true));
    flock($lock, LOCK_UN);
    fclose($lock);
    $json = json_decode($body, true);
    if (is_array($json)) file_put_contents($ck, $body);
    return $json;
}

function geo_comune(array $addr): string {
    return $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['municipality'] ?? $addr['hamlet'] ?? '';
}

function geocode_it(string $q): array {
    $q = trim($q);
    if (mb_strlen($q) < 2) return [];
    $json = nominatim('search', ['q' => $q, 'addressdetails' => 1, 'countrycodes' => 'it', 'limit' => 6]);
    if (!is_array($json)) return [];
    $out = [];
    foreach ($json as $row) {
        $addr = $row['address'] ?? [];
        // Nominatim returns e.g. both the city and the province with the same label: keep the first.
        if (in_array($row['display_name'] ?? '', array_column($out, 'label'), true)) continue;
        $out[] = [
            'label' => $row['display_name'] ?? '',
            'lat' => (float) $row['lat'], 'lng' => (float) $row['lon'],
            'comune' => geo_comune($addr),
            'provincia' => $addr['county'] ?? '',
        ];
    }
    // Towns before provinces/regions with the same name.
    usort($out, static fn($a, $b) => ($a['comune'] === '') <=> ($b['comune'] === ''));
    return $out;
}

function reverse_it(float $lat, float $lng): ?array {
    $json = nominatim('reverse', ['lat' => round($lat, 4), 'lon' => round($lng, 4), 'zoom' => 14, 'addressdetails' => 1]);
    if (!is_array($json) || empty($json['address'])) return null;
    if (($json['address']['country_code'] ?? '') !== 'it') return ['comune' => '', 'label' => $json['display_name'] ?? '', 'italy' => false];
    return ['comune' => geo_comune($json['address']), 'label' => $json['display_name'] ?? '', 'italy' => true];
}

function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2)**2;
    return 2 * $r * asin(min(1, sqrt($a)));
}
