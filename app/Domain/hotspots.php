<?php

declare(strict_types=1);

function setting_for_store(int $storeId, string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT v FROM store_settings WHERE store_id = ? AND k = ?');
    $stmt->execute([$storeId, $key]);
    $row = $stmt->fetch();
    return $row ? (string) $row['v'] : $default;
}

function set_setting_for_store(int $storeId, string $key, string $value): void
{
    $sql = db_upsert_sql('store_settings', ['store_id', 'k', 'v'], 'store_id, k');
    db()->prepare($sql)->execute([$storeId, $key, $value]);
}

/** Limite de clientes Wi-Fi enviado ao agente (config do hotspot × plano da empresa). */
function store_agent_max_clients(int $storeId): int
{
    $configured = max(1, (int) setting_for_store($storeId, 'max_clients', '8'));
    $store = find_store($storeId);
    $companyId = (int) ($store['company_id'] ?? 0);
    if ($companyId <= 0) {
        return max(1, min(8, $configured));
    }
    $planMax = (int) (company_plan_limits($companyId)['max_clients'] ?? 0);
    if ($planMax <= 0) {
        return $configured;
    }

    return max(1, min($configured, $planMax));
}

function portal_config_for(int $hotspotId): array
{
    $stmt = db()->prepare('SELECT * FROM portal_configs WHERE hotspot_id = ?');
    $stmt->execute([$hotspotId]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }
    return [
        'hotspot_id' => $hotspotId,
        'title' => 'Bem-vindo',
        'subtitle' => 'Conecte-se gratuitamente ao Wi-Fi',
        'button_label' => 'Continuar',
        'require_name' => 1,
        'require_phone' => 1,
        'require_email' => 0,
        'require_terms' => 1,
    ];
}

function save_portal_config(int $hotspotId, array $data): void
{
    $existing = db()->prepare('SELECT id FROM portal_configs WHERE hotspot_id = ?');
    $existing->execute([$hotspotId]);
    $now = date('Y-m-d H:i:s');
    if ($existing->fetch()) {
        db()->prepare(
            'UPDATE portal_configs SET title=?, subtitle=?, button_label=?, require_name=?, require_phone=?, require_email=?, require_terms=?, updated_at=? WHERE hotspot_id=?'
        )->execute([
            trim((string) ($data['title'] ?? 'Bem-vindo')),
            trim((string) ($data['subtitle'] ?? '')),
            trim((string) ($data['button_label'] ?? 'Conectar à internet')),
            !empty($data['require_name']) ? 1 : 0,
            !empty($data['require_phone']) ? 1 : 0,
            !empty($data['require_email']) ? 1 : 0,
            !empty($data['require_terms']) ? 1 : 0,
            $now,
            $hotspotId,
        ]);
        return;
    }
    db()->prepare(
        'INSERT INTO portal_configs (hotspot_id, title, subtitle, button_label, require_name, require_phone, require_email, require_terms, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([
        $hotspotId,
        trim((string) ($data['title'] ?? 'Bem-vindo')),
        trim((string) ($data['subtitle'] ?? '')),
        trim((string) ($data['button_label'] ?? 'Conectar à internet')),
        1,
        1,
        0,
        1,
        $now,
    ]);
}

function parse_user_agent(string $ua): array
{
    $device = 'Desktop';
    if (preg_match('/Mobile|Android|iPhone/i', $ua)) {
        $device = 'Mobile';
    } elseif (preg_match('/iPad|Tablet/i', $ua)) {
        $device = 'Tablet';
    }
    $os = 'Outro';
    if (stripos($ua, 'Windows') !== false) {
        $os = 'Windows';
    } elseif (stripos($ua, 'Android') !== false) {
        $os = 'Android';
    } elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
        $os = 'iOS';
    } elseif (stripos($ua, 'Mac OS') !== false) {
        $os = 'macOS';
    }
    $browser = 'Outro';
    if (stripos($ua, 'Edg') !== false) {
        $browser = 'Edge';
    } elseif (stripos($ua, 'Chrome') !== false) {
        $browser = 'Chrome';
    } elseif (stripos($ua, 'Safari') !== false) {
        $browser = 'Safari';
    } elseif (stripos($ua, 'Firefox') !== false) {
        $browser = 'Firefox';
    }
    return ['device' => $device, 'os_name' => $os, 'browser' => $browser];
}

function record_access_session(int $companyId, int $hotspotId, int $clientId, string $ip, string $ua): int
{
    $parsed = parse_user_agent($ua);
    db()->prepare(
        'INSERT INTO access_sessions (company_id, hotspot_id, client_id, started_at, device, os_name, browser, ip_hash, auth_status)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([
        $companyId,
        $hotspotId,
        $clientId,
        date('Y-m-d H:i:s'),
        $parsed['device'],
        $parsed['os_name'],
        $parsed['browser'],
        hash('sha256', $ip),
        'authorized',
    ]);
    return (int) db()->lastInsertId();
}

function find_company_client_by_phone(int $companyId, string $phone): ?array
{
    $phone = preg_replace('/\D+/', '', $phone) ?? '';
    if ($companyId <= 0 || strlen($phone) < 10) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM clients WHERE company_id = ? AND phone = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$companyId, $phone]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function portal_bind_store(array $store): string
{
    $hotspotId = (int) $store['id'];
    $GLOBALS['force_store_id'] = $hotspotId;
    $GLOBALS['portal_token'] = trim((string) ($store['token'] ?? ''));
    return (string) $GLOBALS['portal_token'];
}

function portal_store_from_token(string $token): ?array
{
    $store = find_store_by_token(trim($token));
    if (!$store) {
        return null;
    }
    portal_bind_store($store);
    return $store;
}

function status_message_for_store(int $storeId, string $code): string
{
    $tpl = setting_for_store(
        $storeId,
        'status_template',
        'Estou na {loja} agora! 🔥 Venha conferir. Código {codigo}'
    );
    $storeName = setting_for_store($storeId, 'store_name', 'nossa loja');
    $city = setting_for_store($storeId, 'store_city', '');
    if ($city === '') {
        $row = find_store($storeId);
        $city = trim((string) ($row['city'] ?? ''));
    }
    return strtr($tpl, [
        '{loja}' => $storeName,
        '{codigo}' => $code,
        '{cidade}' => $city,
    ]);
}

function portal_store_max_clients(int $hotspotId): int
{
    return store_agent_max_clients($hotspotId);
}

function portal_store_online_count(int $hotspotId): int
{
    expire_overdue_clients();
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM clients WHERE store_id = ? AND state = 'online' AND ip NOT LIKE '127.%' AND ip != '::1'"
    );
    $stmt->execute([$hotspotId]);
    return (int) $stmt->fetchColumn();
}

/**
 * @return array{client_id:int}
 */
function portal_begin_guest(
    array $store,
    int $companyId,
    int $hotspotId,
    string $name,
    string $phone,
    string $email,
    bool $terms,
    string $ip,
    string $ua
): array {
    if (!portal_access_allowed($store)) {
        throw new RuntimeException('Wi-Fi indisponível no momento. Serviço suspenso.');
    }
    if ($companyId > 0 && !company_within_client_limit($companyId)) {
        throw new RuntimeException(company_limit_error('clients'));
    }
    $now = date('Y-m-d H:i:s');
    $code = random_code();
    $text = status_message_for_store($hotspotId, $code);
    $existing = $companyId > 0 && strlen($phone) >= 10 ? find_company_client_by_phone($companyId, $phone) : null;

    if ($existing) {
        $clientId = (int) $existing['id'];
        db()->prepare(
            'UPDATE clients SET store_id = ?, ip = ?, mac = ?, name = ?, email = ?, phone = ?, status_code = ?, status_text = ?, state = ?, user_agent = ?, authorized_at = NULL, expires_at = NULL, created_at = ? WHERE id = ?'
        )->execute([
            $hotspotId,
            $ip,
            lookup_mac($ip),
            $name !== '' ? $name : (string) ($existing['name'] ?? ''),
            $email !== '' ? $email : (string) ($existing['email'] ?? ''),
            $phone !== '' ? $phone : (string) ($existing['phone'] ?? ''),
            $code,
            $text,
            'pending',
            $ua,
            $now,
            $clientId,
        ]);
    } else {
        db()->prepare(
            'INSERT INTO clients (store_id, company_id, ip, mac, phone, name, email, status_code, status_text, state, user_agent, created_at, access_count, first_access_at, last_access_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $hotspotId,
            $companyId > 0 ? $companyId : null,
            $ip,
            lookup_mac($ip),
            $phone,
            $name,
            $email,
            $code,
            $text,
            'pending',
            $ua,
            $now,
            1,
            $now,
            $now,
        ]);
        $clientId = (int) db()->lastInsertId();
    }

    if ($terms && $companyId > 0) {
        db()->prepare(
            'INSERT INTO client_consents (client_id, company_id, consent_type, accepted, ip, user_agent, created_at)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$clientId, $companyId, 'terms', 1, $ip, $ua, $now]);
    }

    return ['client_id' => $clientId];
}

/**
 * @return array{state:string,expires_at:?string,campaign:?array}
 */
function portal_confirm_guest(array $store, int $hotspotId, int $companyId, ?array $client, ?string $phone, string $ip, string $ua): array
{
    if (!$client) {
        throw new RuntimeException('Sessão não encontrada. Recarregue a página.');
    }
    if (client_is_online($client)) {
        return [
            'state' => 'online',
            'expires_at' => (string) ($client['expires_at'] ?? ''),
            'campaign' => portal_guest_campaign($companyId, $hotspotId, (int) $client['id']),
        ];
    }
    if (($client['state'] ?? '') !== 'pending') {
        throw new RuntimeException('Este acesso não pode ser confirmado agora.');
    }

    $mode = setting_for_store($hotspotId, 'approval_mode', 'instant');
    $maxClients = portal_store_max_clients($hotspotId);
    if ($mode !== 'manual' && portal_store_online_count($hotspotId) >= $maxClients) {
        throw new RuntimeException('Rede cheia (' . $maxClients . ' aparelhos). Tente de novo daqui a pouco.');
    }

    $hours = max(1, (int) setting_for_store($hotspotId, 'session_hours', '2'));
    $next = $mode === 'manual' ? 'awaiting_approval' : 'online';
    $now = date('Y-m-d H:i:s');
    $expires = date('Y-m-d H:i:s', time() + $hours * 3600);

    db()->prepare(
        'UPDATE clients SET phone = COALESCE(?, phone), state = ?, authorized_at = ?, expires_at = ?, mac = COALESCE(?, mac), access_count = access_count + 1, last_access_at = ? WHERE id = ?'
    )->execute([
        $phone,
        $next,
        $next === 'online' ? $now : null,
        $next === 'online' ? $expires : null,
        lookup_mac($ip),
        $now,
        $client['id'],
    ]);

    $campaign = null;
    if ($next === 'online') {
        $campaign = portal_finalize_online_guest($store, $companyId, $hotspotId, (int) $client['id'], $ip, $ua);
    }

    if (function_exists('sync_authorized_file') && is_local_store($hotspotId)) {
        sync_authorized_file();
    }

    return ['state' => $next, 'expires_at' => $next === 'online' ? $expires : null, 'campaign' => $campaign];
}

function portal_finalize_online_guest(array $store, int $companyId, int $hotspotId, int $clientId, string $ip, string $ua): ?array
{
    $campaign = null;
    if ($companyId > 0) {
        $sessionId = record_access_session($companyId, $hotspotId, $clientId, $ip, $ua);
        $row = db()->prepare('SELECT name, phone FROM clients WHERE id = ?');
        $row->execute([$clientId]);
        $guest = $row->fetch() ?: [];
        $provider = network_provider((string) ($store['provider'] ?? 'windows'));
        $provider->authorizeClient($store, ['id' => $clientId, 'name' => $guest['name'] ?? '', 'phone' => $guest['phone'] ?? ''], ['id' => $sessionId]);
        $campaign = active_campaign_for_hotspot($companyId, $hotspotId);
        if ($campaign) {
            record_campaign_view((int) $campaign['id'], $companyId, $clientId, $hotspotId);
        }
    }
    return $campaign;
}

function portal_guest_campaign(int $companyId, int $hotspotId, int $clientId): ?array
{
    if ($companyId <= 0) {
        return null;
    }
    return active_campaign_for_hotspot($companyId, $hotspotId);
}

/**
 * @return array{state:string,expires_at:string}
 */
function portal_approve_guest(int $hotspotId, int $clientId, int $companyId): array
{
    $stmt = db()->prepare('SELECT * FROM clients WHERE id = ? AND store_id = ?');
    $stmt->execute([$clientId, $hotspotId]);
    $client = $stmt->fetch();
    if (!$client || ($client['state'] ?? '') !== 'awaiting_approval') {
        throw new RuntimeException('Cliente não encontrado ou já processado.');
    }
    if (portal_store_online_count($hotspotId) >= portal_store_max_clients($hotspotId)) {
        throw new RuntimeException('Rede cheia. Desconecte alguém antes de aprovar.');
    }
    $hours = max(1, (int) setting_for_store($hotspotId, 'session_hours', '2'));
    $now = date('Y-m-d H:i:s');
    $expires = date('Y-m-d H:i:s', time() + $hours * 3600);
    db()->prepare(
        'UPDATE clients SET state = ?, authorized_at = ?, expires_at = ?, last_access_at = ? WHERE id = ?'
    )->execute(['online', $now, $expires, $now, $clientId]);

    $store = find_store($hotspotId);
    if ($store) {
        portal_finalize_online_guest($store, $companyId, $hotspotId, $clientId, (string) ($client['ip'] ?? ''), (string) ($client['user_agent'] ?? ''));
    }
    if (function_exists('sync_authorized_file') && is_local_store($hotspotId)) {
        sync_authorized_file();
    }
    return ['state' => 'online', 'expires_at' => $expires];
}
