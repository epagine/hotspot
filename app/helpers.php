<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $configPath = __DIR__ . '/config.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('not_installed');
    }
    $config = require $configPath;
    $pdo = new PDO('sqlite:' . $config['sqlite'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    migrate_multi_store($pdo);
    return $pdo;
}

function setting(string $key, ?string $default = null): string
{
    if (is_owner_key($key)) {
        $cache =& setting_cache();
        if ($cache === null) {
            $cache = [];
            foreach (db()->query('SELECT k, v FROM settings')->fetchAll() as $row) {
                $cache[$row['k']] = (string) $row['v'];
            }
        }
        return $cache[$key] ?? (string) $default;
    }
    $sid = current_store_id();
    $bag =& store_setting_cache();
    if (!is_array($bag) || !isset($bag[$sid])) {
        if (!is_array($bag)) {
            $bag = [];
        }
        $bag[$sid] = [];
        $stmt = db()->prepare('SELECT k, v FROM store_settings WHERE store_id = ?');
        $stmt->execute([$sid]);
        foreach ($stmt->fetchAll() as $row) {
            $bag[$sid][$row['k']] = (string) $row['v'];
        }
    }
    return $bag[$sid][$key] ?? (string) $default;
}

function &setting_cache()
{
    static $cache = null;
    return $cache;
}

function set_setting(string $key, string $value): void
{
    if (is_owner_key($key)) {
        $stmt = db()->prepare(
            'INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v'
        );
        $stmt->execute([$key, $value]);
        $cache =& setting_cache();
        if (is_array($cache)) {
            $cache[$key] = $value;
        }
        return;
    }
    $sid = current_store_id();
    $stmt = db()->prepare(
        'INSERT INTO store_settings (store_id, k, v) VALUES (?, ?, ?) ON CONFLICT(store_id, k) DO UPDATE SET v = excluded.v'
    );
    $stmt->execute([$sid, $key, $value]);
    $bag =& store_setting_cache();
    if (!is_array($bag)) {
        $bag = [];
    }
    $bag[$sid][$key] = $value;
    if ($key === 'store_name' || $key === 'store_city') {
        db()->prepare('UPDATE stores SET name = ?, city = ? WHERE id = ?')->execute([
            setting('store_name', ''),
            setting('store_city', ''),
            $sid,
        ]);
    }
}

function storage_dir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function brand_image_path(): string
{
    return brand_image_path_for(current_store_id());
}

function brand_image_url(): string
{
    $path = brand_image_path();
    if (!is_file($path)) {
        return '';
    }
    return '/marca/' . filemtime($path) . '.png';
}

function output_brand_png(?int $storeId = null): void
{
    if ($storeId !== null && $storeId > 0) {
        $GLOBALS['force_store_id'] = $storeId;
    }
    $brand = brand_image_path();
    if (!is_file($brand)) {
        http_response_code(404);
        return;
    }
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=3600');
    readfile($brand);
}

function is_http_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function save_brand_upload(?array $file): void
{
    if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return;
    }
    if ((int) ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Não foi possível enviar a imagem da loja.');
    }
    if ((int) ($file['size'] ?? 0) > 3 * 1024 * 1024) {
        throw new RuntimeException('A imagem deve ter no máximo 3 MB.');
    }
    $bin = (string) file_get_contents((string) $file['tmp_name']);
    $src = @imagecreatefromstring($bin);
    if ($src === false) {
        throw new RuntimeException('Use uma imagem PNG, JPG ou WEBP.');
    }
    $sw = imagesx($src);
    $sh = imagesy($src);
    $max = 1600;
    $scale = min(1, $max / max($sw, $sh));
    $dw = max(1, (int) round($sw * $scale));
    $dh = max(1, (int) round($sh * $scale));
    $dst = imagecreatetruecolor($dw, $dh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $clear = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $dw, $dh, $clear);
    imagealphablending($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
    imagedestroy($src);
    imagepng($dst, brand_image_path(), 7);
    imagedestroy($dst);
}

function delete_brand_image(): void
{
    $path = brand_image_path();
    if (is_file($path)) {
        unlink($path);
    }
}

function expire_overdue_clients(): void
{
    $now = date('Y-m-d H:i:s');
    $stmt = db()->prepare(
        "UPDATE clients SET state = 'expired' WHERE store_id = ? AND state = 'online' AND expires_at IS NOT NULL AND expires_at < ?"
    );
    $stmt->execute([current_store_id(), $now]);
    if ($stmt->rowCount() > 0) {
        sync_authorized_file();
    }
}

function remaining_seconds(?string $expiresAt): int
{
    if (!$expiresAt) {
        return 0;
    }
    return max(0, strtotime($expiresAt) - time());
}

function format_duration(int $sec): string
{
    if ($sec <= 0) {
        return '—';
    }
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    if ($h > 0) {
        return $h . 'h ' . $m . 'min';
    }
    return max(1, $m) . ' min';
}

function format_remaining(?string $expiresAt): string
{
    return format_duration(remaining_seconds($expiresAt));
}

function state_label(string $state): string
{
    return match ($state) {
        'online' => 'online',
        'pending' => 'aguardando',
        'awaiting_approval' => 'no balcão',
        'blocked' => 'bloqueado',
        'expired' => 'encerrado',
        default => $state,
    };
}

function read_json_file(string $path, array $default = []): array
{
    if (!is_file($path)) {
        return $default;
    }
    $raw = (string) file_get_contents($path);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

function json_list(mixed $value): array
{
    if (!is_array($value) || $value === []) {
        return [];
    }
    if (array_is_list($value)) {
        return $value;
    }
    if (isset($value['name']) || isset($value['ip']) || isset($value['mac']) || isset($value['alias'])) {
        return [$value];
    }
    return array_values($value);
}

function write_json_file(string $path, array $data): void
{
    file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function start_windows_agent(): void
{
    $root = dirname(__DIR__);
    $ps1 = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'agente-hotspot.ps1';
    if (!is_file($ps1)) {
        return;
    }
    if (!php_cmd_allowed('popen')) {
        return;
    }
    $cmd = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File ' . escapeshellarg($ps1);
    pclose(popen('cmd /c start "" /B ' . $cmd, 'r'));
}
function write_hotspot_command(string $action): void
{
    write_json_file(storage_dir() . DIRECTORY_SEPARATOR . 'command.json', [
        'id' => bin2hex(random_bytes(8)),
        'action' => $action,
        'at' => date('c'),
    ]);
}

function parse_time_any(?string $value): int
{
    if (!$value) {
        return 0;
    }
    $clean = preg_replace('/\.\d+/', '', $value) ?? $value;
    $t = strtotime($clean);
    return $t !== false ? $t : 0;
}

function max_clients(): int
{
    return max(1, min(8, (int) setting('max_clients', '8')));
}

function online_count(): int
{
    expire_overdue_clients();
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM clients WHERE store_id = ? AND state = 'online' AND ip NOT LIKE '127.%' AND ip != '::1'"
    );
    $stmt->execute([current_store_id()]);
    return (int) $stmt->fetchColumn();
}

function hotspot_runtime(): array
{
    $sid = current_store_id();
    if (!is_local_store($sid)) {
        $store = find_store($sid);
        return store_status_payload($store ?? []);
    }
    $status = read_json_file(storage_dir() . DIRECTORY_SEPARATOR . 'status.json', []);
    $seen = parse_time_any(isset($status['agent_seen_at']) ? (string) $status['agent_seen_at'] : null);
    $status['agent_alive'] = $seen > (time() - 25);
    $detected = $status['portal_ip'] ?? null;
    if (is_string($detected) && filter_var($detected, FILTER_VALIDATE_IP)
        && !str_starts_with($detected, '127.')
        && $detected !== setting('portal_ip', '')) {
        set_setting('portal_ip', $detected);
        sync_authorized_file();
    }
    $status['wifi_adapters'] = json_list($status['wifi_adapters'] ?? []);
    $status['neighbors'] = json_list($status['neighbors'] ?? []);
    $status['ips'] = json_list($status['ips'] ?? []);
    $status['tethering_clients'] = json_list($status['tethering_clients'] ?? []);
    return $status;
}

function dashboard_payload(): array
{
    expire_overdue_clients();
    $today = date('Y-m-d');
    $sid = current_store_id();
    $realIp = "store_id = ? AND ip NOT LIKE '127.%' AND ip != '::1'";
    $stmt = db()->prepare("SELECT COUNT(*) FROM clients WHERE $realIp AND state = 'online'");
    $stmt->execute([$sid]);
    $online = (int) $stmt->fetchColumn();
    $stmt = db()->prepare("SELECT COUNT(*) FROM clients WHERE $realIp AND state IN ('pending','awaiting_approval')");
    $stmt->execute([$sid]);
    $pending = (int) $stmt->fetchColumn();
    $stmt = db()->prepare("SELECT COUNT(*) FROM clients WHERE $realIp AND state = 'blocked'");
    $stmt->execute([$sid]);
    $blocked = (int) $stmt->fetchColumn();
    $stmt = db()->prepare("SELECT COUNT(*) FROM clients WHERE $realIp AND substr(created_at, 1, 10) = ?");
    $stmt->execute([$sid, $today]);
    $visitsToday = (int) $stmt->fetchColumn();

    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM clients WHERE $realIp AND (state = 'online' OR (authorized_at IS NOT NULL AND substr(authorized_at, 1, 10) = ?))"
    );
    $stmt->execute([$sid, $today]);
    $onlineToday = (int) $stmt->fetchColumn();

    $remain = 0;
    $stmt = db()->prepare("SELECT expires_at FROM clients WHERE $realIp AND state = 'online'");
    $stmt->execute([$sid]);
    foreach ($stmt as $row) {
        $remain += remaining_seconds($row['expires_at'] ?? null);
    }

    $stmt = db()->prepare(
        "SELECT * FROM clients WHERE $realIp ORDER BY id DESC LIMIT 120"
    );
    $stmt->execute([$sid]);
    $clients = $stmt->fetchAll();
    foreach ($clients as &$c) {
        $c['remaining'] = $c['state'] === 'online' ? format_remaining($c['expires_at'] ?? null) : '—';
        $c['label'] = state_label((string) $c['state']);
    }
    unset($c);

    $hotspot = hotspot_runtime();
    $max = max_clients();
    $wifiOnWire = (int) ($hotspot['windows_clients'] ?? 0);
    return [
        'kpis' => [
            'online' => $online,
            'pending' => $pending,
            'blocked' => $blocked,
            'visits_today' => $visitsToday,
            'online_today' => $onlineToday,
            'remaining_label' => format_duration($remain),
            'remaining_seconds' => $remain,
            'ssid' => $hotspot['ssid'] ?? setting('wifi_ssid'),
            'slots' => $online . '/' . $max,
            'max_clients' => $max,
            'windows_clients' => $wifiOnWire,
            'internet_ip' => $hotspot['internet_ip'] ?? null,
            'internet_alias' => $hotspot['internet_alias'] ?? null,
        ],
        'hotspot' => $hotspot,
        'clients' => $clients,
        'store' => setting('store_name'),
        'store_id' => current_store_id(),
        'local_store' => is_local_store(),
        'portal_ip' => setting('portal_ip', '192.168.137.1'),
    ];
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return preg_replace('/^::ffff:/', '', $ip) ?: '0.0.0.0';
}

function random_code(int $length = 6): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function status_message(string $code): string
{
    $tpl = setting('status_template', 'Estou na {loja} agora! 🔥 Código {codigo}');
    return strtr($tpl, [
        '{loja}' => setting('store_name', 'nossa loja'),
        '{codigo}' => $code,
        '{cidade}' => setting('store_city', ''),
    ]);
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function is_installed(): bool
{
    return is_file(__DIR__ . '/config.php');
}

function current_client(): ?array
{
    $ip = client_ip();
    $stmt = db()->prepare(
        'SELECT * FROM clients WHERE store_id = ? AND ip = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([local_store_id(), $ip]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function client_is_online(?array $client): bool
{
    if (!$client || $client['state'] !== 'online') {
        return false;
    }
    if (!empty($client['expires_at']) && strtotime($client['expires_at']) < time()) {
        db()->prepare('UPDATE clients SET state = "expired" WHERE id = ?')->execute([$client['id']]);
        sync_authorized_file();
        return false;
    }
    return true;
}

function php_cmd_allowed(string $fn): bool
{
    if (!function_exists($fn)) {
        return false;
    }
    $disabled = strtolower((string) ini_get('disable_functions'));
    if ($disabled === '') {
        return true;
    }
    $names = array_filter(array_map('trim', explode(',', $disabled)));
    return !in_array(strtolower($fn), $names, true);
}

function is_hotspot_lan(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = explode(':', $host)[0];
    if ($host === '192.168.137.1' || str_starts_with($host, '192.168.137.')) {
        return true;
    }
    $addr = (string) ($_SERVER['SERVER_ADDR'] ?? '');
    return str_starts_with($addr, '192.168.137.');
}

function lookup_mac(string $ip): ?string
{
    if ($ip === '' || !php_cmd_allowed('exec')) {
        return null;
    }
    $out = [];
    @exec('arp -a ' . escapeshellarg($ip), $out);
    $text = strtolower(implode("\n", $out));
    if (preg_match('/([0-9a-f]{2}[-:]){5}[0-9a-f]{2}/', $text, $m)) {
        return strtoupper(str_replace('-', ':', $m[0]));
    }
    return null;
}

function sync_authorized_file(): void
{
    if (!is_local_store()) {
        return;
    }
    $dir = storage_dir();
    $stmt = db()->prepare(
        "SELECT ip, expires_at FROM clients WHERE store_id = ? AND state = 'online'"
    );
    $stmt->execute([local_store_id()]);
    $rows = $stmt->fetchAll();
    $ips = [];
    foreach ($rows as $row) {
        if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
            continue;
        }
        $ips[] = $row['ip'];
    }
    $payload = [
        'portal_ip' => setting('portal_ip', '192.168.137.1'),
        'authorized' => array_values(array_unique($ips)),
        'allow_suffixes' => array_values(array_filter(array_map(
            'trim',
            explode("\n", setting('dns_allowlist', default_dns_allowlist()))
        ))),
        'ssid' => setting('wifi_ssid', 'WifiDaLoja'),
        'wifi_pass' => setting('wifi_pass', ''),
        'updated_at' => date('c'),
    ];
    file_put_contents($dir . '/authorized.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function default_dns_allowlist(): string
{
    return implode("\n", [
        'whatsapp.com',
        'whatsapp.net',
        'wa.me',
        'facebook.com',
        'fbcdn.net',
        'fbsbx.com',
        'fb.com',
        'messenger.com',
        'cdninstagram.com',
        'instagram.com',
    ]);
}

function require_admin(): void
{
    if (empty($_SESSION['admin'])) {
        header('Location: /admin/entrar');
        exit;
    }
}

function admin_url(string $section = 'clientes', int $id = 0, string $sub = ''): string
{
    return match ($section) {
        'entrar', 'login' => '/admin/entrar',
        'sair', 'logout' => '/admin/sair',
        'conta' => '/admin/configuracoes/conta',
        'configuracoes' => match ($sub) {
            'integracao', 'pagseguro' => '/admin/configuracoes/integracao',
            'politicas' => '/admin/configuracoes/politicas',
            default => '/admin/configuracoes/conta',
        },
        'assinaturas' => $id > 0 ? '/admin/assinaturas/' . $id : '/admin/assinaturas',
        'instalador' => $sub === 'baixar' ? '/admin/instalador/baixar' : '/admin/instalador',
        'financeiro' => match ($sub) {
            'pagseguro' => '/admin/configuracoes/integracao',
            default => $id > 0 ? '/admin/financeiro/' . $id : '/admin/financeiro',
        },
        default => $id > 0 ? '/admin/clientes/' . $id : '/admin/clientes',
    };
}

function admin_legacy_url(): ?string
{
    $tab = preg_replace('/[^a-z]/', '', (string) ($_GET['tab'] ?? ''));
    $id = (int) ($_GET['id'] ?? 0);
    $sec = preg_replace('/[^a-z]/', '', (string) ($_GET['sec'] ?? ''));
    $loja = (int) ($_GET['loja'] ?? 0);
    if ($tab === '' && $id === 0 && $sec === '' && $loja === 0) {
        return null;
    }
    if (in_array($tab, ['pagamentos'], true)) {
        $tab = 'financeiro';
    }
    if (in_array($tab, ['config', 'configuracoes'], true)) {
        if ($sec === 'pagseguro') {
            return admin_url('configuracoes', 0, 'integracao');
        }
        if ($sec === 'politicas') {
            return admin_url('configuracoes', 0, 'politicas');
        }
        return admin_url('configuracoes', 0, $sec === 'integracao' ? 'integracao' : 'conta');
    }
    if ($tab === 'clientes' || ($tab === '' && $id > 0)) {
        return admin_url('clientes', $id);
    }
    if ($tab === 'financeiro' || ($tab === '' && ($loja > 0 || $sec === 'pagseguro'))) {
        if ($sec === 'pagseguro') {
            return admin_url('configuracoes', 0, 'integracao');
        }
        return admin_url('financeiro', $loja > 0 ? $loja : 0);
    }
    if ($tab === 'instalador') {
        return admin_url('instalador');
    }
    if ($tab === 'assinaturas') {
        return admin_url('assinaturas', $id);
    }
    if ($tab === 'conta') {
        return admin_url('conta');
    }
    return admin_url();
}

function admin_redirect(string $url, int $code = 302): void
{
    header('Location: ' . $url, true, $code);
    exit;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function installer_downloads_dir(): string
{
    $dir = storage_dir() . DIRECTORY_SEPARATOR . 'downloads';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function installer_setup_path(): ?string
{
    $name = 'WiFiDaLoja-Setup.exe';
    $root = dirname(__DIR__);
    foreach ([
        installer_downloads_dir() . DIRECTORY_SEPARATOR . $name,
        $root . DIRECTORY_SEPARATOR . $name,
        $root . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . $name,
    ] as $path) {
        if (is_file($path) && filesize($path) > 100000) {
            return $path;
        }
    }
    return null;
}

require_once __DIR__ . '/stores.php';
require_once __DIR__ . '/subscription.php';
require_once __DIR__ . '/client-portal.php';
require_once __DIR__ . '/pagseguro.php';
