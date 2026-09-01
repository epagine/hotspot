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
        'button_label' => 'Conectar à internet',
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

/**
 * @return array{client_id:int,campaign:?array}
 */
function portal_register_guest(
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
    $now = date('Y-m-d H:i:s');
    $expires = date('Y-m-d H:i:s', time() + 2 * 3600);
    $existing = $companyId > 0 ? find_company_client_by_phone($companyId, $phone) : null;

    if ($existing) {
        $clientId = (int) $existing['id'];
        db()->prepare(
            'UPDATE clients SET store_id = ?, ip = ?, name = ?, email = ?, state = ?, user_agent = ?, authorized_at = ?, expires_at = ?, access_count = access_count + 1, last_access_at = ? WHERE id = ?'
        )->execute([
            $hotspotId,
            $ip,
            $name !== '' ? $name : (string) ($existing['name'] ?? ''),
            $email !== '' ? $email : (string) ($existing['email'] ?? ''),
            'online',
            $ua,
            $now,
            $expires,
            $now,
            $clientId,
        ]);
    } else {
        if ($companyId > 0 && !company_within_client_limit($companyId)) {
            throw new RuntimeException(company_limit_error('clients'));
        }
        $code = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        db()->prepare(
            'INSERT INTO clients (store_id, company_id, ip, phone, name, email, status_code, status_text, state, user_agent, created_at, authorized_at, expires_at, access_count, first_access_at, last_access_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $hotspotId,
            $companyId > 0 ? $companyId : null,
            $ip,
            $phone,
            $name,
            $email,
            $code,
            'portal',
            'online',
            $ua,
            $now,
            $now,
            $expires,
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

    $campaign = null;
    if ($companyId > 0) {
        $sessionId = record_access_session($companyId, $hotspotId, $clientId, $ip, $ua);
        $client = ['id' => $clientId, 'name' => $name, 'phone' => $phone];
        $provider = network_provider((string) ($store['provider'] ?? 'windows'));
        $provider->authorizeClient($store, $client, ['id' => $sessionId]);
        $campaign = active_campaign_for_hotspot($companyId, $hotspotId);
        if ($campaign) {
            record_campaign_view((int) $campaign['id'], $companyId, $clientId, $hotspotId);
        }
    }

    if (function_exists('sync_authorized_file') && (int) local_store_id() === $hotspotId) {
        try {
            db()->prepare("UPDATE clients SET state='online' WHERE id=?")->execute([$clientId]);
            sync_authorized_file();
        } catch (Throwable $e) {
        }
    }

    return ['client_id' => $clientId, 'campaign' => $campaign];
}
