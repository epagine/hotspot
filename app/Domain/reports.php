<?php

declare(strict_types=1);

function company_report_days(?int $days = null): int
{
    $days = $days ?? (int) ($_GET['days'] ?? 30);
    return in_array($days, [7, 30, 90], true) ? $days : 30;
}

function company_report_range(int $days): array
{
    $days = company_report_days($days);
    $end = date('Y-m-d');
    $start = date('Y-m-d', strtotime('-' . ($days - 1) . ' days') ?: time());
    return [
        'days' => $days,
        'start' => $start,
        'end' => $end,
        'start_at' => $start . ' 00:00:00',
        'end_at' => date('Y-m-d', strtotime($end . ' +1 day') ?: time()) . ' 00:00:00',
        'label' => match ($days) {
            7 => 'Últimos 7 dias',
            90 => 'Últimos 90 dias',
            default => 'Últimos 30 dias',
        },
    ];
}

function company_report_summary(int $companyId, int $days = 30): array
{
    $range = company_report_range($days);
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM access_sessions WHERE company_id = ? AND started_at >= ? AND started_at < ?'
    );
    $stmt->execute([$companyId, $range['start_at'], $range['end_at']]);
    $accesses = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT client_id) FROM access_sessions
         WHERE company_id = ? AND started_at >= ? AND started_at < ? AND client_id IS NOT NULL AND client_id > 0'
    );
    $stmt->execute([$companyId, $range['start_at'], $range['end_at']]);
    $uniqueClients = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM clients WHERE company_id = ? AND created_at >= ? AND created_at < ?'
    );
    $stmt->execute([$companyId, $range['start_at'], $range['end_at']]);
    $newClients = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT AVG(duration_seconds) FROM access_sessions
         WHERE company_id = ? AND started_at >= ? AND started_at < ?
           AND duration_seconds IS NOT NULL AND duration_seconds > 0'
    );
    $stmt->execute([$companyId, $range['start_at'], $range['end_at']]);
    $avgDuration = (int) round((float) ($stmt->fetchColumn() ?: 0));

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM campaign_views WHERE company_id = ? AND created_at >= ? AND created_at < ?'
    );
    $stmt->execute([$companyId, $range['start_at'], $range['end_at']]);
    $views = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM campaign_clicks WHERE company_id = ? AND created_at >= ? AND created_at < ?'
    );
    $stmt->execute([$companyId, $range['start_at'], $range['end_at']]);
    $clicks = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM coupon_redemptions WHERE company_id = ? AND generated_at >= ? AND generated_at < ?'
    );
    $stmt->execute([$companyId, $range['start_at'], $range['end_at']]);
    $coupons = (int) $stmt->fetchColumn();

    return [
        'range' => $range,
        'accesses' => $accesses,
        'unique_clients' => $uniqueClients,
        'new_clients' => $newClients,
        'avg_duration_s' => $avgDuration,
        'avg_duration_label' => report_duration_label($avgDuration),
        'campaign_views' => $views,
        'campaign_clicks' => $clicks,
        'campaign_ctr' => $views > 0 ? round(($clicks / $views) * 100, 1) : 0.0,
        'coupons_issued' => $coupons,
        'avg_per_day' => $range['days'] > 0 ? round($accesses / $range['days'], 1) : 0.0,
    ];
}

function report_duration_label(int $seconds): string
{
    if ($seconds <= 0) {
        return '—';
    }
    if ($seconds < 60) {
        return $seconds . 's';
    }
    $m = intdiv($seconds, 60);
    if ($m < 60) {
        return $m . ' min';
    }
    $h = intdiv($m, 60);
    $rm = $m % 60;
    return $h . 'h' . ($rm > 0 ? ' ' . $rm . 'm' : '');
}

function company_report_access_by_day(int $companyId, int $days = 30): array
{
    return dashboard_access_by_day($companyId, company_report_days($days));
}

function company_report_by_hotspot(int $companyId, int $days = 30): array
{
    $range = company_report_range($days);
    $stmt = db()->prepare(
        'SELECT s.id, s.name,
                COUNT(a.id) AS total,
                COUNT(DISTINCT a.client_id) AS unique_clients
         FROM stores s
         LEFT JOIN access_sessions a
           ON a.hotspot_id = s.id
          AND a.company_id = s.company_id
          AND a.started_at >= ?
          AND a.started_at < ?
         WHERE s.company_id = ?
         GROUP BY s.id, s.name
         ORDER BY total DESC, s.name ASC'
    );
    $stmt->execute([$range['start_at'], $range['end_at'], $companyId]);
    return $stmt->fetchAll() ?: [];
}

function company_report_by_hour(int $companyId, int $days = 30): array
{
    $range = company_report_range($days);
    $buckets = array_fill(0, 24, 0);
    $stmt = db()->prepare(
        'SELECT started_at FROM access_sessions
         WHERE company_id = ? AND started_at >= ? AND started_at < ?'
    );
    $stmt->execute([$companyId, $range['start_at'], $range['end_at']]);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $t = strtotime((string) ($row['started_at'] ?? ''));
        if ($t === false) {
            continue;
        }
        $hour = (int) date('G', $t);
        $buckets[$hour]++;
    }
    $out = [];
    for ($h = 0; $h < 24; $h++) {
        $out[] = [
            'hour' => $h,
            'label' => sprintf('%02d', $h),
            'total' => $buckets[$h],
        ];
    }
    return $out;
}

function company_report_breakdown(int $companyId, string $field, int $days = 30, int $limit = 8): array
{
    $allowed = ['device' => 'device', 'os_name' => 'os_name', 'browser' => 'browser'];
    if (!isset($allowed[$field])) {
        return [];
    }
    $col = $allowed[$field];
    $range = company_report_range($days);
    $limit = max(1, min(20, $limit));
    $stmt = db()->prepare(
        "SELECT CASE WHEN {$col} IS NULL OR TRIM({$col}) = '' THEN 'Não informado' ELSE {$col} END AS label,
                COUNT(*) AS total
         FROM access_sessions
         WHERE company_id = ? AND started_at >= ? AND started_at < ?
         GROUP BY CASE WHEN {$col} IS NULL OR TRIM({$col}) = '' THEN 'Não informado' ELSE {$col} END
         ORDER BY total DESC
         LIMIT {$limit}"
    );
    $stmt->execute([$companyId, $range['start_at'], $range['end_at']]);
    return $stmt->fetchAll() ?: [];
}

function company_report_top_clients(int $companyId, int $days = 30, int $limit = 10): array
{
    $range = company_report_range($days);
    $limit = max(1, min(50, $limit));
    $stmt = db()->prepare(
        "SELECT c.id, c.name, c.phone,
                COUNT(a.id) AS visits,
                MAX(a.started_at) AS last_visit
         FROM access_sessions a
         INNER JOIN clients c ON c.id = a.client_id
         WHERE a.company_id = ? AND a.started_at >= ? AND a.started_at < ?
         GROUP BY c.id, c.name, c.phone
         ORDER BY visits DESC, last_visit DESC
         LIMIT {$limit}"
    );
    $stmt->execute([$companyId, $range['start_at'], $range['end_at']]);
    return $stmt->fetchAll() ?: [];
}

function company_report_campaigns(int $companyId, int $days = 30): array
{
    $range = company_report_range($days);
    $campaigns = company_campaigns($companyId);
    $out = [];
    foreach ($campaigns as $c) {
        $id = (int) $c['id'];
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM campaign_views WHERE campaign_id = ? AND company_id = ? AND created_at >= ? AND created_at < ?'
        );
        $stmt->execute([$id, $companyId, $range['start_at'], $range['end_at']]);
        $views = (int) $stmt->fetchColumn();

        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM campaign_clicks WHERE campaign_id = ? AND company_id = ? AND created_at >= ? AND created_at < ?'
        );
        $stmt->execute([$id, $companyId, $range['start_at'], $range['end_at']]);
        $clicks = (int) $stmt->fetchColumn();

        $out[] = [
            'id' => $id,
            'name' => (string) ($c['name'] ?? ''),
            'title' => (string) ($c['title'] ?? ''),
            'status' => (string) ($c['status'] ?? ''),
            'views' => $views,
            'clicks' => $clicks,
            'ctr' => $views > 0 ? round(($clicks / $views) * 100, 1) : 0.0,
        ];
    }
    usort($out, static fn (array $a, array $b): int => $b['views'] <=> $a['views']);
    return $out;
}

function company_report_coupons(int $companyId, int $days = 30): array
{
    $range = company_report_range($days);
    $stmt = db()->prepare(
        'SELECT c.id, c.code, c.title, c.status,
                COUNT(r.id) AS issued,
                SUM(CASE WHEN r.used_at IS NOT NULL AND r.used_at != \'\' THEN 1 ELSE 0 END) AS used
         FROM coupons c
         LEFT JOIN coupon_redemptions r
           ON r.coupon_id = c.id AND r.company_id = c.company_id
          AND r.generated_at >= ? AND r.generated_at < ?
         WHERE c.company_id = ?
         GROUP BY c.id, c.code, c.title, c.status
         ORDER BY issued DESC, c.code ASC'
    );
    $stmt->execute([$range['start_at'], $range['end_at'], $companyId]);
    return $stmt->fetchAll() ?: [];
}

function report_chart_max(array $rows, string $key = 'total'): int
{
    $max = 0;
    foreach ($rows as $row) {
        $max = max($max, (int) ($row[$key] ?? 0));
    }
    return max(1, $max);
}

function export_clients_csv(int $companyId): string
{
    $stmt = db()->prepare(
        'SELECT id, name, phone, email, access_count, first_access_at, last_access_at, created_at, state
         FROM clients WHERE company_id = ? ORDER BY id DESC LIMIT 10000'
    );
    $stmt->execute([$companyId]);
    $rows = $stmt->fetchAll() ?: [];
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, ['id', 'nome', 'whatsapp', 'email', 'acessos', 'primeiro', 'ultimo', 'cadastro', 'status'], ';');
    foreach ($rows as $r) {
        fputcsv($fh, [
            $r['id'],
            $r['name'] ?? '',
            $r['phone'] ?? '',
            $r['email'] ?? '',
            $r['access_count'] ?? 0,
            $r['first_access_at'] ?? '',
            $r['last_access_at'] ?? '',
            $r['created_at'] ?? '',
            $r['state'] ?? '',
        ], ';');
    }
    rewind($fh);
    $csv = stream_get_contents($fh) ?: '';
    fclose($fh);
    return $csv;
}

function export_campaigns_csv(int $companyId, int $days = 30): string
{
    $rows = company_report_campaigns($companyId, $days);
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, ['id', 'campanha', 'titulo', 'status', 'views', 'clicks', 'ctr_pct'], ';');
    foreach ($rows as $r) {
        fputcsv($fh, [
            $r['id'],
            $r['name'],
            $r['title'],
            $r['status'],
            $r['views'],
            $r['clicks'],
            $r['ctr'],
        ], ';');
    }
    rewind($fh);
    $csv = stream_get_contents($fh) ?: '';
    fclose($fh);
    return $csv;
}
