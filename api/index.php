<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/holidays.php';
require __DIR__ . '/lib/opening.php';
require __DIR__ . '/lib/places.php';
require __DIR__ . '/lib/ps_attese.php';

const DISCLAIMER = 'In emergenza chiama il 118 (o il 112). Orari soggetti a variazione: controlla la bacheca della farmacia o il sito ufficiale.';
const REPORT_KINDS = ['aperto', 'chiuso', 'di_turno'];

cors();
$path = route_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = json_body();

function parse_at(?string $raw): DateTimeImmutable {
    $raw = trim((string) $raw);
    if ($raw === '' || $raw === 'now') {
        return new DateTimeImmutable('now');
    }
    $d = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', substr($raw, 0, 16));
    if (!$d) {
        fail('Ora di riferimento non valida.');
    }
    return $d;
}

function parse_cats(mixed $raw): array {
    $list = is_array($raw) ? $raw : explode(',', (string) $raw);
    return array_values(array_intersect(array_map('trim', $list), array_keys(CATEGORIES)));
}

function coord(mixed $v, float $min, float $max): ?float {
    if ($v === null || $v === '' || !is_numeric($v)) {
        return null;
    }
    $f = (float) $v;
    return $f >= $min && $f <= $max ? $f : null;
}

function day_info(DateTimeImmutable $at): array {
    $hol = italian_holidays((int) $at->format('Y'));
    return [
        'at' => $at->format('Y-m-d\TH:i'),
        'holiday' => $hol[$at->format('Y-m-d')] ?? null,
        'sunday' => (int) $at->format('N') === 7,
    ];
}

function app_link(string $hash): string {
    return app_url() . '/#' . $hash;
}

function send_verify(array $u): string {
    $tok = issue_email_token((int) $u['id'], 'verify', '+24 hours');
    $url = app_link('/verifica/' . $tok);
    app_mail($u['email'], 'Conferma l’email — Aperto Adesso',
        '<p>Ciao ' . htmlspecialchars($u['name']) . ',</p><p>Conferma l’indirizzo per attivare gli avvisi:</p><p><a href="' . $url . '">' . $url . '</a></p>');
    return $url;
}

function load_user(int $id): array {
    $st = db()->prepare('SELECT * FROM aperto_users WHERE id=?');
    $st->execute([$id]);
    return $st->fetch();
}

try {
    if ($path === '/config' && $method === 'GET') {
        $now = new DateTimeImmutable('now');
        out([
            'donate_url' => cfg()['donate_url'],
            'disclaimer' => DISCLAIMER,
            'turno_disclaimer' => 'Probabile turno dai dati in mappa — verifica la bacheca.',
            'categories' => CATEGORIES,
            'today' => day_info($now),
            'holidays' => italian_holidays((int) $now->format('Y')),
            'farmacie_turno_provider' => (string) (cfg()['farmacie_turno_key'] ?? '') !== '',
            'ps_live' => (string) (cfg()['ps_json_url'] ?? '') !== '',
            'report_hours' => 12,
        ]);
    }

    if ($path === '/geocode' && $method === 'GET') {
        rate_limit('geocode', 200);
        $lat = coord($_GET['lat'] ?? null, -90, 90);
        $lng = coord($_GET['lng'] ?? null, -180, 180);
        if ($lat !== null && $lng !== null) {
            out(['reverse' => reverse_it($lat, $lng)]);
        }
        out(['results' => geocode_it((string) ($_GET['q'] ?? ''))]);
    }

    if ($path === '/places' && $method === 'GET') {
        rate_limit('places', 400);
        $lat = coord($_GET['lat'] ?? null, 35, 48);
        $lng = coord($_GET['lng'] ?? null, 6, 19);
        if ($lat === null || $lng === null) {
            fail('Posizione fuori dall’Italia o non valida.');
        }
        $r = max(1, min(20, (int) ($_GET['r'] ?? 5)));
        $cats = parse_cats($_GET['cats'] ?? '');
        $at = parse_at($_GET['at'] ?? null);
        $res = search_places($lat, $lng, $r, $cats, $at);
        $wait = (!$cats || in_array('pronto_soccorso', $cats, true)) ? fetch_wait($lat, $lng) : [];
        out([
            'places' => $res['places'],
            'osm_ok' => $res['osm_ok'],
            'day' => day_info($at),
            'radius_km' => $r,
            'ps_wait' => $wait,
        ]);
    }

    if (preg_match('#^/places/(.+)$#', $path, $m) && $method === 'GET') {
        rate_limit('places', 400);
        $at = parse_at($_GET['at'] ?? null);
        $p = find_place(rawurldecode($m[1]), coord($_GET['lat'] ?? null, 35, 48), coord($_GET['lng'] ?? null, 6, 19), $at);
        if (!$p) {
            fail('Luogo non trovato.', 404);
        }
        $st = db()->prepare('SELECT kind, note, created_at FROM aperto_reports WHERE place_key=? AND expires_at > NOW() ORDER BY created_at DESC LIMIT 10');
        $st->execute([$p['key']]);
        out(['place' => $p, 'day' => day_info($at), 'recent_reports' => $st->fetchAll()]);
    }

    if ($path === '/auth/register' && $method === 'POST') {
        rate_limit('register', 5);
        $name = trim((string) ($body['name'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        if ($name === '' || mb_strlen($name) > 80 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || empty($body['privacy'])) {
            fail('Nome, email valida, password di almeno 8 caratteri e consenso privacy.');
        }
        try {
            db()->prepare('INSERT INTO aperto_users (name, email, password_hash) VALUES (?,?,?)')
                ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        } catch (PDOException) {
            fail('Questa email è già registrata.', 409);
        }
        $u = load_user((int) db()->lastInsertId());
        $url = send_verify($u);
        $res = ['token' => issue_token((int) $u['id']), 'user' => public_user($u)];
        if (!empty(cfg()['debug'])) {
            $res['dev_verify_url'] = $url;
        }
        out($res, 201);
    }

    if ($path === '/auth/login' && $method === 'POST') {
        rate_limit('login', 8);
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $st = db()->prepare('SELECT * FROM aperto_users WHERE email=?');
        $st->execute([$email]);
        $u = $st->fetch();
        if (!$u || !password_verify((string) ($body['password'] ?? ''), $u['password_hash'])) {
            fail('Email o password non corretti.', 401);
        }
        out(['token' => issue_token((int) $u['id']), 'user' => public_user($u)]);
    }

    if ($path === '/auth/verify' && $method === 'POST') {
        $raw = trim((string) ($body['token'] ?? ''));
        $st = db()->prepare('SELECT * FROM aperto_email_tokens WHERE token_hash=? AND purpose="verify" AND used_at IS NULL AND expires_at > NOW()');
        $st->execute([hash('sha256', $raw)]);
        $row = $st->fetch();
        if (!$row) {
            fail('Link non valido o scaduto.');
        }
        db()->prepare('UPDATE aperto_users SET email_verified=1 WHERE id=?')->execute([(int) $row['user_id']]);
        db()->prepare('UPDATE aperto_email_tokens SET used_at=NOW() WHERE id=?')->execute([(int) $row['id']]);
        out(['ok' => true]);
    }

    if ($path === '/auth/forgot' && $method === 'POST') {
        rate_limit('forgot', 5);
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $st = db()->prepare('SELECT * FROM aperto_users WHERE email=?');
        $st->execute([$email]);
        $u = $st->fetch();
        if ($u) {
            $tok = issue_email_token((int) $u['id'], 'reset', '+60 minutes');
            $url = app_link('/reset/' . $tok);
            app_mail($u['email'], 'Nuova password — Aperto Adesso',
                '<p>Reimposta la password (link valido 60 minuti):</p><p><a href="' . $url . '">' . $url . '</a></p>');
            if (!empty(cfg()['debug'])) {
                out(['ok' => true, 'dev_reset_url' => $url]);
            }
        }
        out(['ok' => true]);
    }

    if ($path === '/auth/reset' && $method === 'POST') {
        $raw = trim((string) ($body['token'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if (strlen($password) < 8) {
            fail('Password di almeno 8 caratteri.');
        }
        $st = db()->prepare('SELECT * FROM aperto_email_tokens WHERE token_hash=? AND purpose="reset" AND used_at IS NULL AND expires_at > NOW()');
        $st->execute([hash('sha256', $raw)]);
        $row = $st->fetch();
        if (!$row) {
            fail('Link non valido o scaduto.');
        }
        $uid = (int) $row['user_id'];
        db()->prepare('UPDATE aperto_users SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $uid]);
        db()->prepare('UPDATE aperto_email_tokens SET used_at=NOW() WHERE id=?')->execute([(int) $row['id']]);
        db()->prepare('DELETE FROM aperto_sessions WHERE user_id=?')->execute([$uid]);
        out(['ok' => true]);
    }

    if ($path === '/cron' && $method === 'GET') {
        $token = (string) cfg()['cron_token'];
        if ($token === '' || !hash_equals($token, (string) ($_GET['token'] ?? ''))) {
            fail('Non autorizzato.', 403);
        }
        set_time_limit(600);
        $farmacie = count(load_farmacie_csv());
        db()->exec('DELETE FROM aperto_reports WHERE expires_at < NOW() - INTERVAL 7 DAY');
        db()->exec('DELETE FROM aperto_sessions WHERE expires_at < NOW()');
        db()->exec('DELETE FROM aperto_rate_limits WHERE window_start < NOW() - INTERVAL 1 DAY');

        $now = new DateTimeImmutable('now');
        $isWindow = (int) $now->format('N') === 7 || is_italian_holiday($now) || (int) $now->format('G') >= 20;
        $sent = 0;
        $skipped = 0;
        if ($isWindow || !empty($_GET['force'])) {
            $today = $now->format('Y-m-d');
            $users = db()->query('SELECT * FROM aperto_users WHERE alert_on=1 AND lat IS NOT NULL AND lng IS NOT NULL')->fetchAll();
            foreach ($users as $u) {
                if (!(int) $u['email_verified']) {
                    $skipped++;
                    continue;
                }
                $chk = db()->prepare('SELECT 1 FROM aperto_alert_log WHERE user_id=? AND sent_on=?');
                $chk->execute([(int) $u['id'], $today]);
                if ($chk->fetchColumn()) {
                    continue;
                }
                $res = search_places((float) $u['lat'], (float) $u['lng'], (int) $u['radius_km'], ['farmacia'], $now);
                $open = array_slice(array_values(array_filter($res['places'], static fn($p) => $p['open'] === true)), 0, 5);
                if (!$open) {
                    continue;
                }
                $li = '';
                foreach ($open as $p) {
                    $li .= '<li><strong>' . htmlspecialchars($p['name']) . '</strong> — ' . number_format($p['km'], 1, ',', '') . ' km'
                        . ($p['address'] ? '<br>' . htmlspecialchars($p['address']) : '')
                        . ($p['phone'] ? '<br>Tel. ' . htmlspecialchars($p['phone']) : '')
                        . '<br><small>' . ($p['opening_hours'] ? 'Orari: ' . htmlspecialchars($p['opening_hours']) : 'Segnalata aperta da altri utenti') . '</small></li>';
                }
                db()->prepare('INSERT INTO aperto_alert_log (user_id, sent_on) VALUES (?,?)')->execute([(int) $u['id'], $today]);
                app_mail($u['email'], 'Farmacie aperte vicino a casa — Aperto Adesso',
                    '<p>Ciao ' . htmlspecialchars($u['name']) . ',</p><p>Secondo i dati in mappa, entro ' . (int) $u['radius_km']
                    . ' km da ' . htmlspecialchars((string) $u['place_name']) . ' risultano aperte ora:</p><ul>' . $li . '</ul>'
                    . '<p><em>' . DISCLAIMER . '</em></p><p><a href="' . app_link('/') . '">Apri Aperto Adesso</a> · '
                    . '<a href="' . app_link('/avvisi') . '">Disattiva avviso</a></p>');
                $sent++;
            }
        }
        out(['farmacie_ministero' => $farmacie, 'window' => $isWindow, 'sent' => $sent, 'skipped_unverified' => $skipped]);
    }

    $u = require_user();
    $uid = (int) $u['id'];

    if ($path === '/auth/logout' && $method === 'POST') {
        db()->prepare('DELETE FROM aperto_sessions WHERE token_hash=?')->execute([hash('sha256', (string) bearer())]);
        out(['ok' => true]);
    }

    if ($path === '/auth/resend' && $method === 'POST') {
        rate_limit('resend', 3);
        if ((int) $u['email_verified']) {
            out(['ok' => true]);
        }
        $url = send_verify($u);
        out(!empty(cfg()['debug']) ? ['ok' => true, 'dev_verify_url' => $url] : ['ok' => true]);
    }

    if ($path === '/me' && $method === 'GET') {
        out(['user' => public_user($u)]);
    }

    if ($path === '/me' && $method === 'PUT') {
        $name = trim((string) ($body['name'] ?? $u['name']));
        if ($name === '' || mb_strlen($name) > 80) {
            fail('Nome non valido.');
        }
        db()->prepare('UPDATE aperto_users SET name=? WHERE id=?')->execute([$name, $uid]);
        out(['user' => public_user(load_user($uid))]);
    }

    if ($path === '/me/home' && $method === 'PUT') {
        $lat = coord($body['lat'] ?? null, 35, 48);
        $lng = coord($body['lng'] ?? null, 6, 19);
        if ($lat === null || $lng === null) {
            fail('Scegli un comune o un indirizzo in Italia.');
        }
        $cats = parse_cats($body['cats'] ?? []);
        db()->prepare('UPDATE aperto_users SET lat=?, lng=?, place_name=?, radius_km=?, cats=? WHERE id=?')->execute([
            $lat, $lng,
            mb_substr(trim((string) ($body['place_name'] ?? '')), 0, 160),
            max(1, min(20, (int) ($body['radius_km'] ?? 5))),
            implode(',', $cats ?: ['farmacia']),
            $uid,
        ]);
        out(['user' => public_user(load_user($uid))]);
    }

    // Filtri iniziali della ricerca (categorie, raggio, solo aperti): li salva la pagina dei risultati quando cambiano
    if ($path === '/me/prefs' && $method === 'PUT') {
        $cats = parse_cats($body['cats'] ?? []);
        if (!$cats) {
            fail('Scegli almeno una categoria.');
        }
        db()->prepare('UPDATE aperto_users SET pref_cats=?, pref_radius=?, pref_only_open=? WHERE id=?')->execute([
            implode(',', $cats),
            max(1, min(20, (int) ($body['r'] ?? 5))),
            !empty($body['only_open']) ? 1 : 0,
            $uid,
        ]);
        out(['user' => public_user(load_user($uid))]);
    }

    if ($path === '/me/alerts' && $method === 'PUT') {
        $on = !empty($body['alert_on']);
        if ($on && $u['lat'] === null) {
            fail('Prima salva la tua “casa” in Impostazioni.');
        }
        db()->prepare('UPDATE aperto_users SET alert_on=? WHERE id=?')->execute([$on ? 1 : 0, $uid]);
        out(['user' => public_user(load_user($uid))]);
    }

    if ($path === '/me/password' && $method === 'POST') {
        if (!password_verify((string) ($body['current'] ?? ''), $u['password_hash'])) {
            fail('Password attuale non corretta.', 403);
        }
        $np = (string) ($body['password'] ?? '');
        if (strlen($np) < 8) {
            fail('Nuova password di almeno 8 caratteri.');
        }
        db()->prepare('UPDATE aperto_users SET password_hash=? WHERE id=?')->execute([password_hash($np, PASSWORD_DEFAULT), $uid]);
        out(['ok' => true]);
    }

    if ($path === '/me' && $method === 'DELETE') {
        foreach (['favorites', 'reports', 'sessions', 'email_tokens', 'alert_log'] as $t) {
            db()->prepare("DELETE FROM $t WHERE user_id=?")->execute([$uid]);
        }
        db()->prepare('DELETE FROM aperto_users WHERE id=?')->execute([$uid]);
        out(['ok' => true]);
    }

    if ($path === '/favorites' && $method === 'GET') {
        $st = db()->prepare('SELECT place_key, snapshot FROM aperto_favorites WHERE user_id=? ORDER BY place_key');
        $st->execute([$uid]);
        $rows = array_map(static fn($r) => ['key' => $r['place_key'], 'snapshot' => json_decode((string) $r['snapshot'], true)], $st->fetchAll());
        out(['favorites' => $rows]);
    }

    if ($path === '/favorites' && $method === 'POST') {
        $key = trim((string) ($body['key'] ?? ''));
        if (!preg_match('/^(node|way|relation|min):[A-Za-z0-9]{1,60}$/', $key)) {
            fail('Luogo non valido.');
        }
        $snap = is_array($body['snapshot'] ?? null) ? array_intersect_key($body['snapshot'], array_flip(['name', 'category', 'address', 'phone', 'lat', 'lng', 'opening_hours'])) : null;
        $cnt = db()->prepare('SELECT COUNT(*) FROM aperto_favorites WHERE user_id=?');
        $cnt->execute([$uid]);
        if ((int) $cnt->fetchColumn() >= 200) {
            fail('Hai raggiunto il massimo di 200 preferiti.');
        }
        db()->prepare('INSERT INTO aperto_favorites (user_id, place_key, snapshot) VALUES (?,?,?) ON DUPLICATE KEY UPDATE snapshot=VALUES(snapshot)')
            ->execute([$uid, $key, $snap ? json_encode($snap, JSON_UNESCAPED_UNICODE) : null]);
        out(['ok' => true], 201);
    }

    if (preg_match('#^/favorites/(.+)$#', $path, $m) && $method === 'DELETE') {
        db()->prepare('DELETE FROM aperto_favorites WHERE user_id=? AND place_key=?')->execute([$uid, rawurldecode($m[1])]);
        out(['ok' => true]);
    }

    if ($path === '/reports' && $method === 'POST') {
        $key = trim((string) ($body['place_key'] ?? ''));
        $kind = (string) ($body['kind'] ?? '');
        $note = trim((string) ($body['note'] ?? ''));
        if (!preg_match('/^(node|way|relation|min):[A-Za-z0-9]{1,60}$/', $key) || !in_array($kind, REPORT_KINDS, true)) {
            fail('Segnalazione non valida.');
        }
        if (mb_strlen($note) > 200) {
            fail('Nota di massimo 200 caratteri.');
        }
        $cnt = db()->prepare('SELECT COUNT(*) FROM aperto_reports WHERE user_id=? AND created_at > NOW() - INTERVAL 1 DAY');
        $cnt->execute([$uid]);
        if ((int) $cnt->fetchColumn() >= 20) {
            fail('Massimo 20 segnalazioni al giorno.', 429);
        }
        // One live report per user and place: a new one replaces the previous.
        db()->prepare('DELETE FROM aperto_reports WHERE user_id=? AND place_key=? AND expires_at > NOW()')->execute([$uid, $key]);
        db()->prepare('INSERT INTO aperto_reports (user_id, place_key, kind, note, expires_at) VALUES (?,?,?,?, NOW() + INTERVAL 12 HOUR)')
            ->execute([$uid, $key, $kind, $note !== '' ? $note : null]);
        out(['ok' => true], 201);
    }

    fail('Non trovato.', 404);
} catch (Throwable $e) {
    error_log('aperto-adesso: ' . $e->getMessage());
    fail(cfg()['debug'] ? $e->getMessage() : 'Servizio non disponibile.', 500);
}
