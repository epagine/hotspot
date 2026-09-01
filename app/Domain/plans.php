<?php

declare(strict_types=1);

function find_plan(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM plans WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_plan_by_code(string $code): ?array
{
    $stmt = db()->prepare('SELECT * FROM plans WHERE code = ? LIMIT 1');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function all_plans(bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM plans';
    if ($activeOnly) {
        $sql .= ' WHERE active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';
    return db()->query($sql)->fetchAll() ?: [];
}

function save_plan(array $data, ?int $id = null): int
{
    $now = date('Y-m-d H:i:s');
    $fields = [
        trim((string) ($data['code'] ?? '')),
        trim((string) ($data['name'] ?? '')),
        (int) ($data['price_cents'] ?? 0),
        (string) ($data['billing_period'] ?? 'mensal'),
        (int) ($data['max_hotspots'] ?? 1),
        (int) ($data['max_clients'] ?? 100),
        (int) ($data['max_users'] ?? 2),
        is_string($data['features_json'] ?? null)
            ? (string) $data['features_json']
            : json_encode($data['features'] ?? [], JSON_UNESCAPED_UNICODE),
        !empty($data['active']) ? 1 : 0,
        (int) ($data['sort_order'] ?? 0),
    ];
    if ($id) {
        db()->prepare(
            'UPDATE plans SET code=?, name=?, price_cents=?, billing_period=?, max_hotspots=?, max_clients=?, max_users=?, features_json=?, active=?, sort_order=? WHERE id=?'
        )->execute([...$fields, $id]);
        audit_log('plan.update', null, null, ['id' => $id]);
        return $id;
    }
    db()->prepare(
        'INSERT INTO plans (code, name, price_cents, billing_period, max_hotspots, max_clients, max_users, features_json, active, sort_order, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([...$fields, $now]);
    $newId = (int) db()->lastInsertId();
    audit_log('plan.create', null, null, ['id' => $newId]);
    return $newId;
}

function plan_has_feature(?array $plan, string $feature): bool
{
    if (!$plan) {
        return false;
    }
    $features = json_decode((string) ($plan['features_json'] ?? '[]'), true);
    return is_array($features) && in_array($feature, $features, true);
}

function company_current_plan(int $companyId): ?array
{
    $sub = company_subscription($companyId);
    if (!$sub) {
        return null;
    }
    $planId = (int) ($sub['plan_id'] ?? 0);
    return $planId > 0 ? find_plan($planId) : null;
}

function company_has_feature(int $companyId, string $feature): bool
{
    return plan_has_feature(company_current_plan($companyId), $feature);
}

function company_feature_error(string $feature): string
{
    return match ($feature) {
        'campaigns' => 'Campanhas não estão incluídas no seu plano. Faça upgrade em Assinatura.',
        'coupons' => 'Cupons não estão incluídos no seu plano. Faça upgrade em Assinatura.',
        'reports' => 'Relatórios avançados não estão incluídos no seu plano. Faça upgrade em Assinatura.',
        default => 'Recurso não disponível no seu plano.',
    };
}

function company_count_hotspots(int $companyId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM stores WHERE company_id = ?');
    $stmt->execute([$companyId]);
    return (int) $stmt->fetchColumn();
}

function company_count_clients(int $companyId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM clients WHERE company_id = ?');
    $stmt->execute([$companyId]);
    return (int) $stmt->fetchColumn();
}

function company_count_users(int $companyId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM company_users WHERE company_id = ?');
    $stmt->execute([$companyId]);
    return (int) $stmt->fetchColumn();
}

function plan_limit_is_unlimited(int $max): bool
{
    return $max <= 0;
}

function plan_limit_reached(int $used, int $max): bool
{
    return $max > 0 && $used >= $max;
}

function plan_usage_label(int $used, int $max): string
{
    if (plan_limit_is_unlimited($max)) {
        return $used . ' / ilimitado';
    }
    return $used . ' / ' . $max;
}

function company_plan_limits(int $companyId): array
{
    $sub = company_subscription($companyId);
    return [
        'max_hotspots' => (int) ($sub['max_hotspots'] ?? 1),
        'max_clients' => (int) ($sub['max_clients'] ?? 0),
        'max_users' => (int) ($sub['max_users'] ?? 2),
    ];
}

function company_limit_usage(int $companyId): array
{
    $limits = company_plan_limits($companyId);
    return [
        'hotspots' => [
            'used' => company_count_hotspots($companyId),
            'max' => $limits['max_hotspots'],
        ],
        'clients' => [
            'used' => company_count_clients($companyId),
            'max' => $limits['max_clients'],
        ],
        'users' => [
            'used' => company_count_users($companyId),
            'max' => $limits['max_users'],
        ],
    ];
}

function company_within_hotspot_limit(int $companyId): bool
{
    $limits = company_plan_limits($companyId);
    return !plan_limit_reached(company_count_hotspots($companyId), $limits['max_hotspots']);
}

function company_within_client_limit(int $companyId): bool
{
    $limits = company_plan_limits($companyId);
    return !plan_limit_reached(company_count_clients($companyId), $limits['max_clients']);
}

function company_within_user_limit(int $companyId): bool
{
    $limits = company_plan_limits($companyId);
    return !plan_limit_reached(company_count_users($companyId), $limits['max_users']);
}

function company_limit_error(string $resource): string
{
    return match ($resource) {
        'clients' => 'Limite de clientes do plano atingido. Faça upgrade em Assinatura.',
        'users' => 'Limite de usuários do plano atingido. Faça upgrade em Assinatura.',
        'hotspots' => 'Limite de hotspots do plano atingido. Faça upgrade em Assinatura.',
        default => 'Limite do plano atingido.',
    };
}
