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
    notify_company($companyId, 'trial_started');
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
    company_sync_hotspots($id);
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
    notify_company($companyId, 'payment_paid');
    company_sync_hotspots($companyId);
}

function company_on_charge_created(int $companyId): void
{
    company_reconcile_subscription($companyId);
    notify_company($companyId, 'charge_created');
}

function orphan_stores(): array
{
    return db()->query(
        'SELECT * FROM stores WHERE company_id IS NULL OR company_id = 0 ORDER BY id ASC'
    )->fetchAll() ?: [];
}

function attach_store_to_company(int $storeId, int $companyId, bool $force = false): array
{
    $store = find_store($storeId);
    if (!$store) {
        throw new RuntimeException('Loja não encontrada.');
    }
    $current = (int) ($store['company_id'] ?? 0);
    if ($current > 0 && $current === $companyId) {
        return $store;
    }
    if ($current > 0 && !$force) {
        throw new RuntimeException('Esta loja já pertence a outra empresa.');
    }
    $company = find_company($companyId);
    if (!$company) {
        throw new RuntimeException('Empresa não encontrada.');
    }
    if ($current !== $companyId && !company_within_hotspot_limit($companyId)) {
        throw new RuntimeException(company_limit_error('hotspots'));
    }

    db()->prepare('UPDATE stores SET company_id = ? WHERE id = ?')->execute([$companyId, $storeId]);
    db()->prepare('UPDATE clients SET company_id = ? WHERE store_id = ?')->execute([$companyId, $storeId]);
    company_sync_hotspots($companyId);
    audit_log('store.attach', $companyId, null, ['store_id' => $storeId, 'from_company_id' => $current]);
    return find_store($storeId) ?? $store;
}

function promote_store_to_company(int $storeId, array $admin = [], string $planCode = 'essencial'): array
{
    $store = find_store($storeId);
    if (!$store) {
        throw new RuntimeException('Loja não encontrada.');
    }
    if ((int) ($store['company_id'] ?? 0) > 0) {
        throw new RuntimeException('Esta loja já está vinculada a uma empresa.');
    }

    $ownerId = null;
    $email = trim((string) ($admin['email'] ?? ''));
    $password = (string) ($admin['password'] ?? '');
    if ($email !== '') {
        if ($password === '' || strlen($password) < 8) {
            throw new RuntimeException('Informe uma senha com pelo menos 8 caracteres para o admin.');
        }
        $user = create_user([
            'name' => (string) ($admin['name'] ?? 'Admin'),
            'email' => $email,
            'password' => $password,
            'role' => 'company_admin',
        ]);
        $ownerId = (int) $user['id'];
    }

    $company = create_company([
        'trade_name' => (string) ($store['name'] ?? 'Nova empresa'),
        'legal_name' => (string) ($store['name'] ?? 'Nova empresa'),
        'email' => $email !== '' ? $email : (string) ($store['portal_email'] ?? $store['contact'] ?? ''),
        'phone' => (string) ($store['contact'] ?? ''),
        'whatsapp' => (string) ($store['contact'] ?? ''),
        'city' => (string) ($store['city'] ?? ''),
    ], $ownerId, $planCode);

    $companyId = (int) $company['id'];
    attach_store_to_company($storeId, $companyId, true);

    $paidUntil = trim((string) ($store['paid_until'] ?? ''));
    $billing = normalize_subscription_status((string) ($store['billing_status'] ?? 'trial'));
    if ($paidUntil !== '' || in_array($billing, ['ativa', 'atrasada', 'pendente', 'suspensa', 'cortesia'], true)) {
        $status = $billing === 'trial' && $paidUntil !== '' ? 'ativa' : $billing;
        if ($status === 'active') {
            $status = 'ativa';
        }
        db()->prepare(
            'UPDATE subscriptions SET status = ?, trial_ends_at = ?, ends_at = ? WHERE company_id = ?'
        )->execute([
            $status,
            $status === 'trial' ? trim((string) ($store['trial_ends_at'] ?? '')) : '',
            $paidUntil !== '' ? $paidUntil : trim((string) ($store['trial_ends_at'] ?? '')),
            $companyId,
        ]);
        company_reconcile_subscription($companyId);
    }

    return find_company($companyId) ?? $company;
}
