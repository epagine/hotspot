<?php

declare(strict_types=1);

function company_campaigns(int $companyId): array
{
    $stmt = db()->prepare('SELECT * FROM campaigns WHERE company_id = ? ORDER BY id DESC');
    $stmt->execute([$companyId]);
    return $stmt->fetchAll() ?: [];
}

function find_campaign(int $id, ?int $companyId = null): ?array
{
    if ($companyId) {
        $stmt = db()->prepare('SELECT * FROM campaigns WHERE id = ? AND company_id = ?');
        $stmt->execute([$id, $companyId]);
    } else {
        $stmt = db()->prepare('SELECT * FROM campaigns WHERE id = ?');
        $stmt->execute([$id]);
    }
    $row = $stmt->fetch();
    return $row ?: null;
}

function save_campaign(int $companyId, array $data, ?int $id = null): int
{
    $now = date('Y-m-d H:i:s');
    $payload = [
        trim((string) ($data['name'] ?? '')),
        (string) ($data['type'] ?? 'banner'),
        trim((string) ($data['title'] ?? '')),
        trim((string) ($data['description'] ?? '')),
        trim((string) ($data['image_path'] ?? '')),
        trim((string) ($data['button_label'] ?? '')),
        trim((string) ($data['button_url'] ?? '')),
        trim((string) ($data['starts_at'] ?? '')),
        trim((string) ($data['ends_at'] ?? '')),
        is_string($data['hotspot_ids_json'] ?? null)
            ? (string) $data['hotspot_ids_json']
            : json_encode($data['hotspot_ids'] ?? [], JSON_UNESCAPED_UNICODE),
        (string) ($data['status'] ?? 'ativa'),
    ];
    if ($id) {
        $existing = find_campaign($id, $companyId);
        if (!$existing) {
            throw new RuntimeException('Campanha não encontrada.');
        }
        db()->prepare(
            'UPDATE campaigns SET name=?, type=?, title=?, description=?, image_path=?, button_label=?, button_url=?, starts_at=?, ends_at=?, hotspot_ids_json=?, status=? WHERE id=? AND company_id=?'
        )->execute([...$payload, $id, $companyId]);
        audit_log('campaign.update', $companyId, null, ['id' => $id]);
        return $id;
    }
    db()->prepare(
        'INSERT INTO campaigns (company_id, name, type, title, description, image_path, button_label, button_url, starts_at, ends_at, hotspot_ids_json, status, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([$companyId, ...$payload, $now]);
    $newId = (int) db()->lastInsertId();
    audit_log('campaign.create', $companyId, null, ['id' => $newId]);
    return $newId;
}

function active_campaign_for_hotspot(int $companyId, int $hotspotId): ?array
{
    $today = date('Y-m-d');
    foreach (company_campaigns($companyId) as $c) {
        if (($c['status'] ?? '') !== 'ativa') {
            continue;
        }
        $starts = (string) ($c['starts_at'] ?? '');
        $ends = (string) ($c['ends_at'] ?? '');
        if ($starts !== '' && $starts > $today) {
            continue;
        }
        if ($ends !== '' && $ends < $today) {
            continue;
        }
        $ids = json_decode((string) ($c['hotspot_ids_json'] ?? '[]'), true);
        if (is_array($ids) && $ids !== [] && !in_array($hotspotId, array_map('intval', $ids), true)) {
            continue;
        }
        return $c;
    }
    return null;
}

function record_campaign_view(int $campaignId, int $companyId, ?int $clientId, ?int $hotspotId): void
{
    db()->prepare(
        'INSERT INTO campaign_views (campaign_id, company_id, client_id, hotspot_id, created_at) VALUES (?,?,?,?,?)'
    )->execute([$campaignId, $companyId, $clientId, $hotspotId, date('Y-m-d H:i:s')]);
}

function record_campaign_click(int $campaignId, int $companyId, ?int $clientId, ?int $hotspotId): void
{
    db()->prepare(
        'INSERT INTO campaign_clicks (campaign_id, company_id, client_id, hotspot_id, created_at) VALUES (?,?,?,?,?)'
    )->execute([$campaignId, $companyId, $clientId, $hotspotId, date('Y-m-d H:i:s')]);
}

function company_coupons(int $companyId): array
{
    $stmt = db()->prepare('SELECT * FROM coupons WHERE company_id = ? ORDER BY id DESC');
    $stmt->execute([$companyId]);
    return $stmt->fetchAll() ?: [];
}

function create_coupon(int $companyId, array $data): int
{
    $code = strtoupper(trim((string) ($data['code'] ?? '')));
    if ($code === '') {
        $code = 'WIFI' . strtoupper(bin2hex(random_bytes(3)));
    }
    db()->prepare(
        'INSERT INTO coupons (company_id, campaign_id, code, title, description, valid_until, status, created_at)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        $companyId,
        (int) ($data['campaign_id'] ?? 0) ?: null,
        $code,
        trim((string) ($data['title'] ?? '')),
        trim((string) ($data['description'] ?? '')),
        trim((string) ($data['valid_until'] ?? '')),
        'ativo',
        date('Y-m-d H:i:s'),
    ]);
    return (int) db()->lastInsertId();
}

function issue_coupon_to_client(int $couponId, int $companyId, ?int $clientId, ?int $campaignId = null): array
{
    db()->prepare(
        'INSERT INTO coupon_redemptions (coupon_id, company_id, client_id, campaign_id, status, generated_at)
         VALUES (?,?,?,?,?,?)'
    )->execute([$couponId, $companyId, $clientId, $campaignId, 'gerado', date('Y-m-d H:i:s')]);
    $stmt = db()->prepare('SELECT * FROM coupons WHERE id = ?');
    $stmt->execute([$couponId]);
    return $stmt->fetch() ?: [];
}

function export_access_csv(int $companyId): string
{
    $stmt = db()->prepare(
        'SELECT a.*, c.name AS client_name, c.phone AS client_phone, s.name AS hotspot_name
         FROM access_sessions a
         LEFT JOIN clients c ON c.id = a.client_id
         LEFT JOIN stores s ON s.id = a.hotspot_id
         WHERE a.company_id = ?
         ORDER BY a.id DESC LIMIT 5000'
    );
    $stmt->execute([$companyId]);
    $rows = $stmt->fetchAll() ?: [];
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, ['id', 'hotspot', 'cliente', 'whatsapp', 'inicio', 'fim', 'duracao_s', 'dispositivo', 'os', 'browser', 'status'], ';');
    foreach ($rows as $r) {
        fputcsv($fh, [
            $r['id'],
            $r['hotspot_name'] ?? '',
            $r['client_name'] ?? '',
            $r['client_phone'] ?? '',
            $r['started_at'] ?? '',
            $r['ended_at'] ?? '',
            $r['duration_seconds'] ?? 0,
            $r['device'] ?? '',
            $r['os_name'] ?? '',
            $r['browser'] ?? '',
            $r['auth_status'] ?? '',
        ], ';');
    }
    rewind($fh);
    $csv = stream_get_contents($fh) ?: '';
    fclose($fh);
    return $csv;
}
