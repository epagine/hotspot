<?php

declare(strict_types=1);

function payment_status_label(string $status): string
{
    return match ($status) {
        'pending' => 'Em aberto',
        'paid' => 'Paga',
        'expired' => 'Expirada',
        default => $status,
    };
}

function payment_status_filter(?string $status): ?string
{
    if ($status === null || $status === '' || $status === 'todas') {
        return null;
    }
    return in_array($status, ['pending', 'paid', 'expired'], true) ? $status : null;
}

function platform_payment_kpis(): array
{
    $pendingCount = (int) db()->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn();
    $pendingCents = (int) db()->query("SELECT COALESCE(SUM(amount_cents), 0) FROM payments WHERE status = 'pending'")->fetchColumn();
    $expiredCount = (int) db()->query("SELECT COUNT(*) FROM payments WHERE status = 'expired'")->fetchColumn();
    $since = date('Y-m-d H:i:s', strtotime('-30 days') ?: time());
    $paidStmt = db()->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(amount_cents), 0) AS s FROM payments WHERE status = 'paid' AND COALESCE(paid_at, created_at) >= ?");
    $paidStmt->execute([$since]);
    $paidRow = $paidStmt->fetch() ?: ['c' => 0, 's' => 0];
    $subs = platform_subscriptions_overview(null);
    return [
        'pending_count' => $pendingCount,
        'pending_cents' => $pendingCents,
        'expired_count' => $expiredCount,
        'paid_30d_count' => (int) ($paidRow['c'] ?? 0),
        'paid_30d_cents' => (int) ($paidRow['s'] ?? 0),
        'overdue_count' => (int) ($subs['kpis']['atrasadas'] ?? 0),
        'suspended_count' => (int) ($subs['kpis']['suspensas'] ?? 0),
    ];
}

function platform_payments(?string $status, int $limit = 50, int $offset = 0, ?int $companyId = null): array
{
    $status = payment_status_filter($status);
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    $where = [];
    $params = [];
    if ($status !== null) {
        $where[] = 'p.status = ?';
        $params[] = $status;
    }
    if ($companyId !== null && $companyId > 0) {
        $where[] = 'p.company_id = ?';
        $params[] = $companyId;
    }
    $sqlWhere = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
    $countSql = "SELECT COUNT(*) FROM payments p {$sqlWhere}";
    $countStmt = db()->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $sql = "SELECT p.*, c.trade_name AS company_name, s.name AS store_name, pl.name AS plan_name
            FROM payments p
            LEFT JOIN companies c ON c.id = p.company_id
            LEFT JOIN stores s ON s.id = p.store_id
            LEFT JOIN plans pl ON pl.id = p.plan_id
            {$sqlWhere}
            ORDER BY p.id DESC
            LIMIT {$limit} OFFSET {$offset}";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return [
        'rows' => $stmt->fetchAll() ?: [],
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
    ];
}

function platform_payment_client_label(array $row): string
{
    $company = trim((string) ($row['company_name'] ?? ''));
    if ($company !== '') {
        return $company;
    }
    $store = trim((string) ($row['store_name'] ?? ''));
    if ($store !== '') {
        return $store . ' (legado)';
    }
    return '—';
}

function platform_subscriptions_overview(?string $billingFilter): array
{
    $billingFilter = $billingFilter !== null && $billingFilter !== '' && $billingFilter !== 'todas'
        ? normalize_subscription_status($billingFilter)
        : null;
    $kpis = [
        'total' => 0,
        'ativas' => 0,
        'trial' => 0,
        'pendentes' => 0,
        'atrasadas' => 0,
        'suspensas' => 0,
        'mrr_cents' => 0,
    ];
    $rows = [];
    foreach (all_companies() as $company) {
        $companyId = (int) $company['id'];
        $effective = company_subscription_effective($companyId);
        if (!$effective) {
            continue;
        }
        $billing = normalize_subscription_status((string) ($effective['billing_status'] ?? ''));
        $kpis['total']++;
        match ($billing) {
            'trial' => $kpis['trial']++,
            'pendente' => $kpis['pendentes']++,
            'atrasada' => $kpis['atrasadas']++,
            'suspensa' => $kpis['suspensas']++,
            'ativa', 'cortesia' => $kpis['ativas']++,
            default => null,
        };
        if (in_array($billing, ['ativa', 'cortesia'], true)) {
            $kpis['mrr_cents'] += (int) ($effective['price_cents'] ?? 0);
        }
        if ($billingFilter !== null && $billing !== $billingFilter) {
            continue;
        }
        $pending = company_pending_payment($companyId);
        $period = '';
        if ($billing === 'trial') {
            $period = (string) ($effective['trial_ends_at'] ?? '');
        } else {
            $period = (string) ($effective['ends_at'] ?? '');
        }
        $rows[] = [
            'company_id' => $companyId,
            'trade_name' => (string) ($company['trade_name'] ?? ''),
            'email' => (string) ($company['email'] ?? ''),
            'plan_name' => (string) ($effective['plan_name'] ?? ''),
            'billing_status' => $billing,
            'billing_label' => (string) ($effective['billing_label'] ?? subscription_label($billing)),
            'tag_class' => (string) ($effective['tag_class'] ?? subscription_tag_class($billing)),
            'period' => $period,
            'pending_payment' => $pending,
        ];
    }
    usort($rows, static fn (array $a, array $b): int => strcmp($a['trade_name'], $b['trade_name']));
    return [
        'kpis' => $kpis,
        'rows' => $rows,
        'filters' => subscription_statuses(),
    ];
}

function dashboard_finance_kpis(): array
{
    $pay = platform_payment_kpis();
    $subs = platform_subscriptions_overview(null);
    return [
        'pending_payments' => (int) $pay['pending_count'],
        'pending_cents' => (int) $pay['pending_cents'],
        'overdue_subscriptions' => (int) ($subs['kpis']['atrasadas'] ?? 0),
        'suspended_subscriptions' => (int) ($subs['kpis']['suspensas'] ?? 0),
    ];
}
