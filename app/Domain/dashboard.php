<?php

declare(strict_types=1);

function dashboard_company_kpis(int $companyId): array
{
    $hotspots = 0;
    $clients = 0;
    $today = 0;
    $d7 = 0;
    $d30 = 0;
    $new7 = 0;
    $recurring = 0;

    $stmt = db()->prepare('SELECT COUNT(*) FROM stores WHERE company_id = ?');
    $stmt->execute([$companyId]);
    $hotspots = (int) $stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(*) FROM clients WHERE company_id = ?');
    $stmt->execute([$companyId]);
    $clients = (int) $stmt->fetchColumn();

    $todayStart = date('Y-m-d') . ' 00:00:00';
    $stmt = db()->prepare('SELECT COUNT(*) FROM access_sessions WHERE company_id = ? AND started_at >= ?');
    $stmt->execute([$companyId, $todayStart]);
    $today = (int) $stmt->fetchColumn();

    $d7start = date('Y-m-d H:i:s', strtotime('-7 days') ?: time());
    $stmt->execute([$companyId, $d7start]);
    $d7 = (int) $stmt->fetchColumn();

    $d30start = date('Y-m-d H:i:s', strtotime('-30 days') ?: time());
    $stmt->execute([$companyId, $d30start]);
    $d30 = (int) $stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(*) FROM clients WHERE company_id = ? AND created_at >= ?');
    $stmt->execute([$companyId, $d7start]);
    $new7 = (int) $stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(*) FROM clients WHERE company_id = ? AND access_count > 1');
    $stmt->execute([$companyId]);
    $recurring = (int) $stmt->fetchColumn();

    $activeHotspots = 0;
    $stmt = db()->prepare("SELECT COUNT(*) FROM stores WHERE company_id = ? AND (hotspot_status = 'ativo' OR hotspot_status IS NULL OR hotspot_status = '') AND active = 1");
    $stmt->execute([$companyId]);
    $activeHotspots = (int) $stmt->fetchColumn();

    return [
        'clients' => $clients,
        'access_today' => $today,
        'access_7d' => $d7,
        'access_30d' => $d30,
        'new_clients_7d' => $new7,
        'hotspots' => $hotspots,
        'hotspots_active' => $activeHotspots,
        'recurring_clients' => $recurring,
    ];
}

function dashboard_access_by_day(int $companyId, int $days = 7): array
{
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime('-' . $i . ' days') ?: time());
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM access_sessions WHERE company_id = ? AND started_at >= ? AND started_at < ?'
        );
        $stmt->execute([$companyId, $day . ' 00:00:00', date('Y-m-d', strtotime($day . ' +1 day') ?: time()) . ' 00:00:00']);
        $out[] = ['day' => $day, 'label' => date('d/m', strtotime($day) ?: time()), 'total' => (int) $stmt->fetchColumn()];
    }
    return $out;
}

function dashboard_platform_kpis(): array
{
    $companies = (int) db()->query('SELECT COUNT(*) FROM companies')->fetchColumn();
    $active = (int) db()->query("SELECT COUNT(*) FROM companies WHERE status = 'active'")->fetchColumn();
    $trials = (int) db()->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'trial'")->fetchColumn();
    $subs = (int) db()->query("SELECT COUNT(*) FROM subscriptions WHERE status IN ('ativa','active','trial')")->fetchColumn();
    $clients = (int) db()->query('SELECT COUNT(*) FROM clients')->fetchColumn();
    $hotspots = (int) db()->query('SELECT COUNT(*) FROM stores')->fetchColumn();
    $access = 0;
    try {
        $access = (int) db()->query('SELECT COUNT(*) FROM access_sessions')->fetchColumn();
    } catch (Throwable $e) {
        $access = 0;
    }
    $mrr = 0;
    $rows = db()->query(
        "SELECT p.price_cents FROM subscriptions s INNER JOIN plans p ON p.id = s.plan_id WHERE s.status IN ('ativa','active')"
    )->fetchAll() ?: [];
    foreach ($rows as $r) {
        $mrr += (int) ($r['price_cents'] ?? 0);
    }
    return [
        'companies' => $companies,
        'companies_active' => $active,
        'trials' => $trials,
        'subscriptions' => $subs,
        'mrr_cents' => $mrr,
        'clients' => $clients,
        'access_total' => $access,
        'hotspots' => $hotspots,
    ];
}
