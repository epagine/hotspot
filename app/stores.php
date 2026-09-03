<?php

declare(strict_types=1);

function owner_setting_keys(): array
{
    return [
        'admin_user', 'admin_pass_hash',
        'pagseguro_env', 'pagseguro_token',
        'pagseguro_cron_key', 'pagseguro_last_run', 'pagseguro_advance_days', 'pagseguro_auto',
        'payment_provider', 'payment_auto', 'payment_advance_days', 'payment_last_run',
        'picpay_env', 'picpay_client_id', 'picpay_client_secret', 'picpay_seller_token',
        'saas_grace_days', 'saas_trial_days', 'saas_auto_suspend',
        'evolution_base_url', 'evolution_api_key', 'evolution_instance', 'evolution_enabled',
        'notify_on_charge_created', 'notify_on_payment_paid', 'notify_on_trial_started',
        'notify_on_trial_ending', 'notify_on_overdue', 'notify_on_suspended',
        'notify_tpl_charge_created', 'notify_tpl_payment_paid', 'notify_tpl_trial_started',
        'notify_tpl_trial_ending', 'notify_tpl_overdue', 'notify_tpl_suspended',
        'notify_trial_reminder_days',
    ];
}

function is_owner_key(string $key): bool
{
    return in_array($key, owner_setting_keys(), true);
}

function cloud_config_path(): string
{
    return storage_dir() . DIRECTORY_SEPARATOR . 'cloud.json';
}

function cloud_config(): array
{
    return read_json_file(cloud_config_path(), []);
}

function write_cloud_config(string $panelUrl, string $token): void
{
    write_json_file(cloud_config_path(), [
        'panel_url' => rtrim($panelUrl, '/'),
        'token' => $token,
        'updated_at' => date('c'),
    ]);
}

function new_store_token(): string
{
    return bin2hex(random_bytes(16));
}

function migrate_multi_store(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    ensure_core_schema($pdo);
    ensure_store_saas_columns($pdo);
    ensure_payments_table($pdo);
    ensure_subscription_schema($pdo);
    ensure_portal_schema($pdo);

    $names = db_column_names($pdo, 'clients');
    if ($names !== [] && !in_array('store_id', $names, true)) {
        $t = db_type_map();
        $pdo->exec('ALTER TABLE clients ADD COLUMN store_id ' . $t['int'] . ' DEFAULT 1');
    }
    db_ensure_index($pdo, 'idx_clients_store', 'clients', 'store_id, id');

    $count = (int) $pdo->query('SELECT COUNT(*) FROM stores')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $legacy = [];
    try {
        foreach ($pdo->query('SELECT k, v FROM settings') as $row) {
            $legacy[(string) $row['k']] = (string) $row['v'];
        }
    } catch (Throwable $e) {
        return;
    }
    $hasClients = (int) $pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn() > 0;
    if (($legacy['store_name'] ?? '') === '' && !$hasClients) {
        return;
    }
    $name = $legacy['store_name'] ?? 'Loja 1';
    $city = $legacy['store_city'] ?? '';
    $token = new_store_token();
    $pdo->prepare(
        'INSERT INTO stores (name, city, token, created_at) VALUES (?,?,?,?)'
    )->execute([$name, $city, $token, date('Y-m-d H:i:s')]);
    $id = (int) $pdo->lastInsertId();

    $storeKeys = [
        'store_name', 'store_city', 'wifi_ssid', 'wifi_pass', 'portal_ip',
        'max_clients', 'approval_mode', 'status_template', 'dns_allowlist', 'session_hours',
    ];
    $ins = $pdo->prepare(db_upsert_sql('store_settings', ['store_id', 'k', 'v'], 'store_id, k'));
    foreach ($storeKeys as $k) {
        if (array_key_exists($k, $legacy)) {
            $ins->execute([$id, $k, $legacy[$k]]);
        }
    }
    $ins->execute([$id, 'store_name', $name]);
    $ins->execute([$id, 'store_city', $city]);

    $oldBrand = storage_dir() . DIRECTORY_SEPARATOR . 'brand.png';
    $newBrand = brand_image_path_for($id);
    if (is_file($oldBrand) && !is_file($newBrand)) {
        $dir = dirname($newBrand);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        @rename($oldBrand, $newBrand);
    }

    if (!is_file(cloud_config_path())) {
        write_cloud_config(guess_panel_url(), $token);
    }
}

function ensure_core_schema(PDO $pdo): void
{
    $t = db_type_map();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS settings (
            k {$t['text']} NOT NULL PRIMARY KEY,
            v {$t['long']}
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stores (
            id {$t['auto']},
            name {$t['text']} NOT NULL,
            city {$t['text']} NOT NULL DEFAULT '',
            token {$t['text']} NOT NULL UNIQUE,
            pending_command {$t['long']},
            pending_command_id {$t['text']},
            last_seen_at {$t['text']},
            last_status {$t['long']},
            created_at {$t['text']} NOT NULL
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS store_settings (
            store_id {$t['int']},
            k {$t['text']} NOT NULL,
            v {$t['long']},
            PRIMARY KEY (store_id, k)
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS clients (
            id {$t['auto']},
            store_id {$t['int']} DEFAULT 1,
            ip {$t['text']} NOT NULL,
            mac {$t['text']},
            phone {$t['text']},
            status_code {$t['text']} NOT NULL,
            status_text {$t['long']} NOT NULL,
            state {$t['text']} NOT NULL DEFAULT 'pending',
            user_agent {$t['long']},
            created_at {$t['text']} NOT NULL,
            authorized_at {$t['text']},
            expires_at {$t['text']}
        )"
    );
    db_ensure_index($pdo, 'idx_ip_state', 'clients', 'ip, state');
    db_ensure_index($pdo, 'idx_code', 'clients', 'status_code');
    db_ensure_index($pdo, 'idx_clients_store', 'clients', 'store_id, id');
}

function ensure_store_saas_columns(PDO $pdo): void
{
    $t = db_type_map();
    $add = [
        'active' => $t['bool'] . ' DEFAULT 1',
        'billing_status' => "{$t['text']} NOT NULL DEFAULT 'em_dia'",
        'plan' => "{$t['text']} NOT NULL DEFAULT 'mensal'",
        'monthly_fee' => "{$t['text']} NOT NULL DEFAULT ''",
        'paid_until' => "{$t['text']} NOT NULL DEFAULT ''",
        'contact' => "{$t['text']} NOT NULL DEFAULT ''",
        'notes' => $t['long'],
        'auto_billing' => $t['bool'] . ' DEFAULT 1',
    ];
    foreach ($add as $col => $def) {
        db_add_column($pdo, 'stores', $col, $def);
    }
}

function ensure_payments_table(PDO $pdo): void
{
    $t = db_type_map();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS payments (
            id {$t['auto']},
            store_id {$t['int']},
            reference_id {$t['text']} NOT NULL UNIQUE,
            checkout_id {$t['text']} NOT NULL DEFAULT '',
            pay_url {$t['long']} NOT NULL,
            amount_cents {$t['int']} DEFAULT 0,
            status {$t['text']} NOT NULL DEFAULT 'pending',
            raw {$t['long']},
            created_at {$t['text']} NOT NULL,
            paid_at {$t['text']}
        )"
    );
    db_ensure_index($pdo, 'idx_payments_store', 'payments', 'store_id, id');
}

function money_to_cents(string $raw): int
{
    $s = trim(preg_replace('/[^\d,.\-]/', '', $raw) ?? '');
    if ($s === '' || $s === '-' || $s === '.' || $s === ',') {
        return 0;
    }
    if (str_contains($s, ',') && str_contains($s, '.')) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif (str_contains($s, ',')) {
        $s = str_replace(',', '.', $s);
    }
    $n = (float) $s;
    if ($n <= 0) {
        return 0;
    }
    return (int) round($n * 100);
}

function cents_label(int $cents): string
{
    return 'R$ ' . number_format($cents / 100, 2, ',', '.');
}

function plan_meta(string $plan): array
{
    return match ($plan) {
        'trimestral' => ['label' => 'Trimestral', 'unit' => 'MONTH', 'length' => 3, 'months' => 3],
        'anual' => ['label' => 'Anual', 'unit' => 'YEAR', 'length' => 1, 'months' => 12],
        default => ['label' => 'Mensal', 'unit' => 'MONTH', 'length' => 1, 'months' => 1],
    };
}

function next_paid_until(array $store): string
{
    $months = (int) plan_meta((string) ($store['plan'] ?? 'mensal'))['months'];
    $base = time();
    $until = trim((string) ($store['paid_until'] ?? ''));
    $t = $until !== '' ? strtotime($until) : false;
    if ($t !== false && $t > $base) {
        $base = $t;
    }
    return date('Y-m-d', strtotime('+' . $months . ' months', $base) ?: $base);
}

function store_connection_health(array $store): array
{
    $status = store_status_payload($store);
    $error = trim((string) ($status['error'] ?? ''));
    $alive = !empty($status['agent_alive']);
    $seen = (string) ($store['last_seen_at'] ?? '');
    if (!$alive) {
        return [
            'key' => 'offline',
            'label' => 'Offline',
            'detail' => $seen !== '' ? 'Sem contato recente' : 'PC ainda não vinculou',
        ];
    }
    if ($error !== '') {
        return [
            'key' => 'erro',
            'label' => 'Erro',
            'detail' => $error,
        ];
    }
    $hot = !empty($status['hotspot_on']);
    return [
        'key' => 'ok',
        'label' => 'OK',
        'detail' => $hot ? 'Conexão ok · hotspot ligado' : 'Conexão ok · hotspot desligado',
    ];
}

function billing_label(string $status): string
{
    return subscription_label($status);
}

function saas_overview(): array
{
    $kpi = [
        'total' => 0,
        'ativos' => 0,
        'ok' => 0,
        'erro' => 0,
        'offline' => 0,
        'atrasados' => 0,
    ];
    $rows = [];
    foreach (all_stores() as $store) {
        $health = store_connection_health($store);
        $active = (int) ($store['active'] ?? 1) === 1;
        $bill = normalize_subscription_status((string) ($store['billing_status'] ?? 'ativa'));
        $st = store_status_payload($store);
        $kpi['total']++;
        if ($active) {
            $kpi['ativos']++;
        }
        if ($health['key'] === 'ok') {
            $kpi['ok']++;
        } elseif ($health['key'] === 'erro') {
            $kpi['erro']++;
        } else {
            $kpi['offline']++;
        }
        if ($bill === 'atrasada') {
            $kpi['atrasados']++;
        }
        $seen = parse_time_any((string) ($store['last_seen_at'] ?? ''));
        $rows[] = [
            'id' => (int) $store['id'],
            'name' => (string) $store['name'],
            'city' => (string) ($store['city'] ?? ''),
            'contact' => (string) ($store['contact'] ?? ''),
            'active' => $active,
            'plan' => (string) ($store['plan'] ?? 'mensal'),
            'monthly_fee' => (string) ($store['monthly_fee'] ?? ''),
            'paid_until' => (string) ($store['paid_until'] ?? ''),
            'billing_status' => $bill,
            'billing_label' => billing_label($bill),
            'auto_billing' => (int) ($store['auto_billing'] ?? 1) === 1,
            'health' => $health,
            'hotspot_on' => !empty($st['hotspot_on']),
            'ssid' => (string) ($st['ssid'] ?? ''),
            'internet_ip' => (string) ($st['internet_ip'] ?? ''),
            'last_seen' => $seen > 0 ? date('d/m H:i', $seen) : '—',
            'token' => (string) $store['token'],
        ];
    }
    return ['kpis' => $kpi, 'clients' => $rows];
}

function update_store_saas(int $id, array $fields): void
{
    $store = find_store($id);
    if (!$store) {
        return;
    }
    $wasActive = (int) ($store['active'] ?? 1) === 1;
    db()->prepare(
        'UPDATE stores SET name = ?, city = ?, active = ?, contact = ? WHERE id = ?'
    )->execute([
        (string) $fields['name'],
        (string) $fields['city'],
        !empty($fields['active']) ? 1 : 0,
        (string) ($fields['contact'] ?? ''),
        $id,
    ]);
    $ins = db()->prepare(db_upsert_sql('store_settings', ['store_id', 'k', 'v'], 'store_id, k'));
    $ins->execute([$id, 'store_name', (string) $fields['name']]);
    $ins->execute([$id, 'store_city', (string) $fields['city']]);
    $nowActive = !empty($fields['active']);
    if ($wasActive && !$nowActive) {
        queue_store_command($id, 'stop');
    } elseif (!$wasActive && $nowActive && subscription_service_allowed((string) ($store['billing_status'] ?? 'ativa'))) {
        queue_store_command($id, 'start');
    }
}

function guess_panel_url(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8080');
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if ($host === '') {
        $host = '127.0.0.1:8080';
    }
    return ($https ? 'https://' : 'http://') . $host;
}

function all_stores(): array
{
    return db()->query('SELECT * FROM stores ORDER BY id ASC')->fetchAll() ?: [];
}

function find_store(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM stores WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_store_by_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM stores WHERE token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function local_store_portal_url(): ?string
{
    $token = trim((string) (cloud_config()['token'] ?? ''));
    if ($token === '') {
        $store = find_store(local_store_id());
        $token = trim((string) ($store['token'] ?? ''));
    }
    if ($token === '') {
        return null;
    }
    return '/portal/' . rawurlencode($token);
}

function local_store_id(): int
{
    $cfg = cloud_config();
    $token = (string) ($cfg['token'] ?? '');
    if ($token !== '') {
        $store = find_store_by_token($token);
        if ($store) {
            return (int) $store['id'];
        }
    }
    $id = (int) db()->query('SELECT id FROM stores ORDER BY id ASC LIMIT 1')->fetchColumn();
    return max(1, $id);
}

function current_store_id(): int
{
    if (!empty($GLOBALS['force_store_id'])) {
        return (int) $GLOBALS['force_store_id'];
    }
    if (!empty($_SESSION['admin']) && (int) ($_SESSION['store_id'] ?? 0) > 0) {
        $sid = (int) $_SESSION['store_id'];
        if (find_store($sid)) {
            return $sid;
        }
    }
    return local_store_id();
}

function select_store(int $id): void
{
    if (find_store($id)) {
        $_SESSION['store_id'] = $id;
        $cache =& store_setting_cache();
        $cache = null;
    }
}

function is_local_store(?int $id = null): bool
{
    $id = $id ?? current_store_id();
    return $id === local_store_id();
}

function &store_setting_cache()
{
    static $cache = null;
    return $cache;
}

function store_defaults(): array
{
    return [
        'store_name' => 'Nova loja',
        'store_city' => '',
        'wifi_ssid' => 'WifiDaLoja',
        'wifi_pass' => 'loja1234',
        'portal_ip' => '192.168.137.1',
        'max_clients' => '8',
        'approval_mode' => 'instant',
        'status_template' => 'Estou na {loja} agora! 🔥 Venha conferir. Código {codigo}',
        'dns_allowlist' => default_dns_allowlist(),
        'session_hours' => '2',
    ];
}

function create_store(string $name, string $city = '', ?array $settings = null, ?string $forcedToken = null): array
{
    $name = trim($name) ?: 'Nova loja';
    $city = trim($city);
    $token = $forcedToken ?: new_store_token();
    $companyId = current_company_id();
    db()->prepare(
        'INSERT INTO stores (name, city, token, company_id, provider, hotspot_status, created_at) VALUES (?,?,?,?,?,?,?)'
    )->execute([$name, $city, $token, $companyId > 0 ? $companyId : null, 'windows', 'ativo', date('Y-m-d H:i:s')]);
    $id = (int) db()->lastInsertId();
    $vals = array_merge(store_defaults(), $settings ?? []);
    $vals['store_name'] = $name;
    $vals['store_city'] = $city;
    foreach ($vals as $k => $v) {
        if (is_owner_key($k)) {
            continue;
        }
        set_setting_for_store($id, $k, (string) $v);
    }
    return find_store($id) ?? ['id' => $id, 'token' => $token, 'name' => $name];
}

function create_store_with_trial(string $name, string $city = '', ?array $settings = null): array
{
    $store = create_store($name, $city, $settings);
    subscription_init_trial((int) $store['id']);
    return find_store((int) $store['id']) ?? $store;
}

function rotate_store_token(int $id): string
{
    $wasLocal = is_local_store($id);
    $local = cloud_config();
    $token = new_store_token();
    db()->prepare('UPDATE stores SET token = ? WHERE id = ?')->execute([$token, $id]);
    if ($wasLocal) {
        write_cloud_config((string) ($local['panel_url'] ?? guess_panel_url()), $token);
    }
    return $token;
}

function queue_store_command(int $storeId, string $action): void
{
    $id = bin2hex(random_bytes(8));
    db()->prepare(
        'UPDATE stores SET pending_command = ?, pending_command_id = ? WHERE id = ?'
    )->execute([$action, $id, $storeId]);
    if (is_local_store($storeId)) {
        write_json_file(storage_dir() . DIRECTORY_SEPARATOR . 'command.json', [
            'id' => $id,
            'action' => $action,
            'at' => date('c'),
        ]);
        start_windows_agent();
    }
}

function peek_store_command(int $storeId): ?array
{
    $store = find_store($storeId);
    if (!$store || empty($store['pending_command'])) {
        return null;
    }
    return [
        'id' => (string) $store['pending_command_id'],
        'action' => (string) $store['pending_command'],
    ];
}

function ack_store_command(int $storeId, string $cmdId): void
{
    $cmdId = trim($cmdId);
    if ($cmdId === '') {
        return;
    }
    db()->prepare(
        'UPDATE stores SET pending_command = NULL, pending_command_id = NULL WHERE id = ? AND pending_command_id = ?'
    )->execute([$storeId, $cmdId]);
}

function save_store_heartbeat(int $storeId, array $status): void
{
    db()->prepare(
        'UPDATE stores SET last_seen_at = ?, last_status = ? WHERE id = ?'
    )->execute([date('c'), json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $storeId]);
}

function store_status_payload(array $store): array
{
    $raw = (string) ($store['last_status'] ?? '');
    $data = $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($data)) {
        $data = [];
    }
    $seen = parse_time_any((string) ($store['last_seen_at'] ?? ''));
    $data['agent_alive'] = $seen > (time() - 45);
    $data['wifi_adapters'] = json_list($data['wifi_adapters'] ?? []);
    $data['neighbors'] = json_list($data['neighbors'] ?? []);
    $data['ips'] = json_list($data['ips'] ?? []);
    $data['tethering_clients'] = json_list($data['tethering_clients'] ?? []);
    return $data;
}

function brand_image_path_for(int $storeId): string
{
    $dir = storage_dir() . DIRECTORY_SEPARATOR . 'brand';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir . DIRECTORY_SEPARATOR . $storeId . '.png';
}

function upsert_synced_clients(int $storeId, array $clients): void
{
    if ($clients !== [] && isset($clients['status_code'])) {
        $clients = [$clients];
    }
    $store = find_store($storeId);
    $companyId = (int) ($store['company_id'] ?? 0);
    $sel = db()->prepare('SELECT id FROM clients WHERE store_id = ? AND status_code = ? ORDER BY id DESC LIMIT 1');
    $upd = db()->prepare(
        'UPDATE clients SET ip = ?, mac = ?, phone = ?, status_text = ?, state = ?, user_agent = ?, created_at = ?, authorized_at = ?, expires_at = ?, company_id = COALESCE(company_id, ?) WHERE id = ?'
    );
    $ins = db()->prepare(
        'INSERT INTO clients (store_id, company_id, ip, mac, phone, status_code, status_text, state, user_agent, created_at, authorized_at, expires_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    foreach ($clients as $c) {
        if (!is_array($c)) {
            continue;
        }
        $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($c['status_code'] ?? '')) ?? '');
        if ($code === '') {
            continue;
        }
        $sel->execute([$storeId, $code]);
        $id = $sel->fetchColumn();
        $ip = (string) ($c['ip'] ?? '');
        $mac = $c['mac'] ?? null;
        $phone = $c['phone'] ?? null;
        $text = (string) ($c['status_text'] ?? '');
        $state = (string) ($c['state'] ?? 'pending');
        $ua = (string) ($c['user_agent'] ?? '');
        $created = (string) ($c['created_at'] ?? date('Y-m-d H:i:s'));
        $auth = $c['authorized_at'] ?? null;
        $exp = $c['expires_at'] ?? null;
        if ($id) {
            $upd->execute([$ip, $mac, $phone, $text, $state, $ua, $created, $auth, $exp, $companyId > 0 ? $companyId : null, $id]);
        } elseif ($companyId <= 0 || company_within_client_limit($companyId)) {
            $ins->execute([
                $storeId,
                $companyId > 0 ? $companyId : null,
                $ip,
                $mac,
                $phone,
                $code,
                $text,
                $state,
                $ua,
                $created,
                $auth,
                $exp,
            ]);
        }
    }
}

function clients_for_sync(int $storeId): array
{
    $stmt = db()->prepare(
        'SELECT ip, mac, phone, status_code, status_text, state, user_agent, created_at, authorized_at, expires_at
         FROM clients WHERE store_id = ? ORDER BY id DESC LIMIT 120'
    );
    $stmt->execute([$storeId]);
    return $stmt->fetchAll() ?: [];
}

function apply_client_patches(int $storeId, array $patches): void
{
    if ($patches !== [] && isset($patches['status_code'])) {
        $patches = [$patches];
    }
    $upd = db()->prepare(
        'UPDATE clients SET state = ?, authorized_at = ?, expires_at = ? WHERE store_id = ? AND status_code = ?'
    );
    foreach ($patches as $p) {
        if (!is_array($p)) {
            continue;
        }
        $code = strtoupper((string) ($p['status_code'] ?? ''));
        if ($code === '') {
            continue;
        }
        $upd->execute([
            (string) ($p['state'] ?? 'pending'),
            $p['authorized_at'] ?? null,
            $p['expires_at'] ?? null,
            $storeId,
            $code,
        ]);
    }
}

function pending_client_patches(int $storeId): array
{
    $stmt = db()->prepare(
        'SELECT status_code, state, authorized_at, expires_at FROM clients WHERE store_id = ? AND state IN (\'online\',\'blocked\',\'expired\',\'awaiting_approval\')'
    );
    $stmt->execute([$storeId]);
    return $stmt->fetchAll() ?: [];
}
