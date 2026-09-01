<?php

declare(strict_types=1);

function find_company(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM companies WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function all_companies(): array
{
    return db()->query('SELECT * FROM companies ORDER BY id DESC')->fetchAll() ?: [];
}

function create_company(array $data, ?int $ownerUserId = null, string $planCode = 'essencial'): array
{
    $now = date('Y-m-d H:i:s');
    $trade = trim((string) ($data['trade_name'] ?? $data['name'] ?? 'Nova empresa'));
    $legal = trim((string) ($data['legal_name'] ?? $trade));
    db()->prepare(
        'INSERT INTO companies (
            legal_name, trade_name, document, phone, whatsapp, email, address, city, state,
            primary_color, secondary_color, status, created_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $legal,
        $trade,
        trim((string) ($data['document'] ?? '')),
        trim((string) ($data['phone'] ?? '')),
        trim((string) ($data['whatsapp'] ?? '')),
        trim((string) ($data['email'] ?? '')),
        trim((string) ($data['address'] ?? '')),
        trim((string) ($data['city'] ?? '')),
        trim((string) ($data['state'] ?? '')),
        trim((string) ($data['primary_color'] ?? '#c8892a')),
        trim((string) ($data['secondary_color'] ?? '#15202b')),
        'active',
        $now,
    ]);
    $companyId = (int) db()->lastInsertId();

    $plan = find_plan_by_code($planCode) ?? find_plan_by_code('gratuito');
    $planId = (int) ($plan['id'] ?? 1);
    $trialDays = max(1, (int) setting('saas_trial_days', '14'));
    if ($trialDays < 14) {
        $trialDays = 14;
    }
    $trialEnds = date('Y-m-d', strtotime('+' . $trialDays . ' days') ?: time());
    db()->prepare(
        'INSERT INTO subscriptions (company_id, plan_id, status, trial_ends_at, starts_at, ends_at, created_at)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([$companyId, $planId, 'trial', $trialEnds, $now, $trialEnds, $now]);

    if ($ownerUserId) {
        db()->prepare(
            'INSERT INTO company_users (company_id, user_id, permissions, created_at) VALUES (?,?,?,?)'
        )->execute([
            $companyId,
            $ownerUserId,
            json_encode(array_keys(permission_catalog())),
            $now,
        ]);
    }

    audit_log('company.create', $companyId, $ownerUserId, ['trade_name' => $trade]);
    return find_company($companyId) ?? ['id' => $companyId, 'trade_name' => $trade];
}

function update_company(int $id, array $data): void
{
    db()->prepare(
        'UPDATE companies SET
            legal_name = ?, trade_name = ?, document = ?, phone = ?, whatsapp = ?, email = ?,
            address = ?, city = ?, state = ?, primary_color = ?, secondary_color = ?, social_json = ?
         WHERE id = ?'
    )->execute([
        trim((string) ($data['legal_name'] ?? '')),
        trim((string) ($data['trade_name'] ?? '')),
        trim((string) ($data['document'] ?? '')),
        trim((string) ($data['phone'] ?? '')),
        trim((string) ($data['whatsapp'] ?? '')),
        trim((string) ($data['email'] ?? '')),
        trim((string) ($data['address'] ?? '')),
        trim((string) ($data['city'] ?? '')),
        trim((string) ($data['state'] ?? '')),
        trim((string) ($data['primary_color'] ?? '#c8892a')),
        trim((string) ($data['secondary_color'] ?? '#15202b')),
        is_string($data['social_json'] ?? null)
            ? (string) $data['social_json']
            : json_encode($data['social'] ?? [], JSON_UNESCAPED_UNICODE),
        $id,
    ]);
    audit_log('company.update', $id, null, ['fields' => array_keys($data)]);
}

function set_company_status(int $id, string $status): void
{
    if (!in_array($status, ['active', 'blocked'], true)) {
        $status = 'active';
    }
    db()->prepare('UPDATE companies SET status = ? WHERE id = ?')->execute([$status, $id]);
    audit_log('company.status', $id, null, ['status' => $status]);
}

function company_subscription(int $companyId): ?array
{
    $stmt = db()->prepare(
        'SELECT s.*, p.code AS plan_code, p.name AS plan_name, p.price_cents, p.max_hotspots, p.max_clients, p.max_users, p.features_json
         FROM subscriptions s
         LEFT JOIN plans p ON p.id = s.plan_id
         WHERE s.company_id = ? LIMIT 1'
    );
    $stmt->execute([$companyId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function company_subscription_label(?array $sub): string
{
    if (!$sub) {
        return 'Sem assinatura';
    }
    return match ((string) ($sub['status'] ?? '')) {
        'trial' => 'Trial',
        'ativa', 'active' => 'Ativa',
        'pendente' => 'Pendente',
        'atrasada' => 'Atrasada',
        'suspensa', 'suspended' => 'Suspensa',
        'cancelada', 'cancelled' => 'Cancelada',
        default => (string) ($sub['status'] ?? ''),
    };
}

function company_change_plan(int $companyId, int $planId): void
{
    $plan = find_plan($planId);
    if (!$plan) {
        throw new RuntimeException('Plano não encontrado.');
    }
    $sub = company_subscription($companyId);
    if (!$sub) {
        throw new RuntimeException('Assinatura não encontrada.');
    }
    db()->prepare('UPDATE subscriptions SET plan_id = ? WHERE company_id = ?')->execute([$planId, $companyId]);
    if ((int) ($plan['price_cents'] ?? 0) === 0) {
        db()->prepare(
            'UPDATE subscriptions SET status = ?, trial_ends_at = ?, ends_at = ? WHERE company_id = ?'
        )->execute([
            'ativa',
            '',
            date('Y-m-d', strtotime('+10 years') ?: time()),
            $companyId,
        ]);
    }
    audit_log('subscription.plan_change', $companyId, null, [
        'plan_id' => $planId,
        'plan_code' => (string) $plan['code'],
    ]);
}

function company_on_payment_paid(int $companyId, ?int $planId = null): void
{
    $sub = company_subscription($companyId);
    if (!$sub) {
        return;
    }
    $base = time();
    $endsAt = trim((string) ($sub['ends_at'] ?? ''));
    $t = $endsAt !== '' ? strtotime($endsAt) : false;
    if ($t !== false && $t > $base) {
        $base = $t;
    }
    $newEnds = date('Y-m-d', strtotime('+1 month', $base) ?: $base);
    $planId = $planId && $planId > 0 ? $planId : (int) ($sub['plan_id'] ?? 0);
    db()->prepare(
        'UPDATE subscriptions SET status = ?, trial_ends_at = ?, ends_at = ?, plan_id = ? WHERE company_id = ?'
    )->execute(['ativa', '', $newEnds, $planId, $companyId]);
    company_reconcile_subscription($companyId);
    audit_log('subscription.paid', $companyId, null, ['ends_at' => $newEnds, 'plan_id' => $planId]);
}

function company_on_charge_created(int $companyId): void
{
    company_reconcile_subscription($companyId);
}
