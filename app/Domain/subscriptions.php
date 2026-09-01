<?php

declare(strict_types=1);

function company_derive_status(array $sub, ?array $company = null): string
{
    $current = normalize_subscription_status((string) ($sub['status'] ?? 'trial'));
    if (subscription_is_locked_status($current)) {
        return $current;
    }
    if ($company && ($company['status'] ?? '') === 'blocked') {
        return 'suspensa';
    }

    $today = date('Y-m-d');
    if ($current === 'trial') {
        $trialEnds = trim((string) ($sub['trial_ends_at'] ?? ''));
        if ($trialEnds !== '' && $trialEnds >= $today) {
            return 'trial';
        }
        if ($trialEnds !== '' && $trialEnds < $today) {
            return 'pendente';
        }
    }

    $endsAt = trim((string) ($sub['ends_at'] ?? ''));
    if ($endsAt === '' || $endsAt >= $today) {
        return $current === 'active' ? 'ativa' : $current;
    }

    $grace = saas_grace_days();
    $deadline = date('Y-m-d', strtotime($endsAt . ' +' . $grace . ' days') ?: time());
    if ($deadline >= $today) {
        return 'atrasada';
    }

    return saas_auto_suspend_enabled() ? 'suspensa' : 'atrasada';
}

function company_service_allowed(?array $sub, ?array $company = null): bool
{
    if ($company && ($company['status'] ?? '') === 'blocked') {
        return false;
    }
    if (!$sub) {
        return false;
    }
    $derived = company_derive_status($sub, $company);
    return subscription_service_allowed($derived);
}

function company_subscription_effective(int $companyId): ?array
{
    $sub = company_subscription($companyId);
    if (!$sub) {
        return null;
    }
    $company = find_company($companyId);
    $derived = company_derive_status($sub, $company);
    $sub['billing_status'] = $derived;
    $sub['billing_label'] = subscription_label($derived);
    $sub['tag_class'] = subscription_tag_class($derived);
    $sub['service_allowed'] = company_service_allowed($sub, $company);
    return $sub;
}

function company_reconcile_subscription(int $companyId): bool
{
    $sub = company_subscription($companyId);
    if (!$sub) {
        return false;
    }
    $company = find_company($companyId);
    $derived = company_derive_status($sub, $company);
    $current = normalize_subscription_status((string) ($sub['status'] ?? ''));
    if ($derived === $current) {
        return false;
    }
    db()->prepare('UPDATE subscriptions SET status = ? WHERE company_id = ?')->execute([$derived, $companyId]);
    audit_log('subscription.status', $companyId, null, ['from' => $current, 'to' => $derived]);
    return true;
}

function company_reconcile_all(): int
{
    $n = 0;
    foreach (all_companies() as $company) {
        if (company_reconcile_subscription((int) $company['id'])) {
            $n++;
        }
    }
    return $n;
}

function company_subscription_run_daily(): array
{
    return ['reconciled' => company_reconcile_all()];
}

function store_service_allowed(array $store): bool
{
    $companyId = (int) ($store['company_id'] ?? 0);
    if ($companyId > 0) {
        $effective = company_subscription_effective($companyId);
        if ($effective !== null) {
            return (bool) ($effective['service_allowed'] ?? false);
        }
    }
    $sr = subscription_row($store);
    return subscription_service_allowed($sr['billing_status']);
}

function store_subscription_payload(array $store): array
{
    $companyId = (int) ($store['company_id'] ?? 0);
    if ($companyId > 0) {
        $effective = company_subscription_effective($companyId);
        if ($effective !== null) {
            return [
                'scope' => 'company',
                'billing_status' => (string) $effective['billing_status'],
                'billing_label' => (string) $effective['billing_label'],
                'plan' => (string) ($effective['plan_code'] ?? ''),
                'plan_label' => (string) ($effective['plan_name'] ?? ''),
                'paid_until' => (string) ($effective['ends_at'] ?? ''),
                'trial_ends_at' => (string) ($effective['trial_ends_at'] ?? ''),
                'cycle_amount' => cents_label((int) ($effective['price_cents'] ?? 0)),
                'active' => (bool) ($effective['service_allowed'] ?? false),
                'service_allowed' => (bool) ($effective['service_allowed'] ?? false),
            ];
        }
    }
    $sr = subscription_row($store);
    return [
        'scope' => 'store',
        'billing_status' => $sr['billing_status'],
        'billing_label' => $sr['billing_label'],
        'plan' => $sr['plan'],
        'plan_label' => $sr['plan_label'],
        'paid_until' => $sr['paid_until'],
        'trial_ends_at' => $sr['trial_ends_at'],
        'cycle_amount' => $sr['cycle_amount'],
        'active' => $sr['active'],
        'service_allowed' => subscription_service_allowed($sr['billing_status']),
    ];
}
