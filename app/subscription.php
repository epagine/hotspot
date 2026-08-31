<?php

declare(strict_types=1);

function saas_trial_days(): int
{
    return max(0, min(90, (int) setting('saas_trial_days', '7')));
}

function saas_grace_days(): int
{
    return max(0, min(30, (int) setting('saas_grace_days', '3')));
}

function saas_auto_suspend_enabled(): bool
{
    return setting('saas_auto_suspend', '1') !== '0';
}

function subscription_statuses(): array
{
    return [
        'trial' => 'Trial',
        'ativa' => 'Ativa',
        'pendente' => 'Pendente',
        'atrasada' => 'Atrasada',
        'suspensa' => 'Suspensa',
        'cortesia' => 'Cortesia',
        'cancelada' => 'Cancelada',
        'encerrada' => 'Encerrada',
    ];
}

function normalize_subscription_status(string $status): string
{
    return match ($status) {
        'em_dia' => 'ativa',
        'atrasado' => 'atrasada',
        'cancelado' => 'cancelada',
        default => array_key_exists($status, subscription_statuses()) ? $status : 'ativa',
    };
}

function subscription_label(string $status): string
{
    $status = normalize_subscription_status($status);
    return subscription_statuses()[$status] ?? $status;
}

function subscription_tag_class(string $status): string
{
    return match (normalize_subscription_status($status)) {
        'ativa', 'trial', 'cortesia' => 'online',
        'pendente' => 'pending',
        'atrasada', 'suspensa', 'cancelada', 'encerrada' => 'blocked',
        default => 'pending',
    };
}

function subscription_service_allowed(string $status): bool
{
    return in_array(normalize_subscription_status($status), ['trial', 'ativa', 'pendente', 'cortesia'], true);
}

function ensure_subscription_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS subscription_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL,
            event_type TEXT NOT NULL,
            from_status TEXT NOT NULL DEFAULT \'\',
            to_status TEXT NOT NULL DEFAULT \'\',
            note TEXT NOT NULL DEFAULT \'\',
            actor TEXT NOT NULL DEFAULT \'system\',
            created_at TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sub_events_store ON subscription_events (store_id, id)');

    $cols = array_column($pdo->query('PRAGMA table_info(stores)')->fetchAll(), 'name');
    $add = [
        'trial_ends_at' => "TEXT NOT NULL DEFAULT ''",
        'suspended_at' => "TEXT NOT NULL DEFAULT ''",
        'cancelled_at' => "TEXT NOT NULL DEFAULT ''",
        'next_billing_at' => "TEXT NOT NULL DEFAULT ''",
    ];
    foreach ($add as $col => $def) {
        if (!in_array($col, $cols, true)) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN {$col} {$def}");
        }
    }

    $pdo->exec("UPDATE stores SET billing_status = 'ativa' WHERE billing_status = 'em_dia'");
    $pdo->exec("UPDATE stores SET billing_status = 'atrasada' WHERE billing_status = 'atrasado'");
    $pdo->exec("UPDATE stores SET billing_status = 'cancelada' WHERE billing_status = 'cancelado'");
}

function subscription_log_event(int $storeId, string $eventType, string $from, string $to, string $note = '', string $actor = 'system'): void
{
    db()->prepare(
        'INSERT INTO subscription_events (store_id, event_type, from_status, to_status, note, actor, created_at)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([
        $storeId,
        $eventType,
        normalize_subscription_status($from),
        normalize_subscription_status($to),
        $note,
        $actor,
        date('Y-m-d H:i:s'),
    ]);
}

function subscription_events(int $storeId, int $limit = 20): array
{
    $stmt = db()->prepare(
        'SELECT * FROM subscription_events WHERE store_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit)
    );
    $stmt->execute([$storeId]);
    return $stmt->fetchAll() ?: [];
}

function subscription_sync_contract(int $storeId): void
{
    $store = find_store($storeId);
    if (!$store) {
        return;
    }
    $status = normalize_subscription_status((string) ($store['billing_status'] ?? 'ativa'));
    $shouldRun = subscription_service_allowed($status);
    $active = (int) ($store['active'] ?? 1) === 1;
    if ($shouldRun && !$active) {
        db()->prepare('UPDATE stores SET active = 1, suspended_at = ? WHERE id = ?')->execute(['', $storeId]);
        queue_store_command($storeId, 'start');
        return;
    }
    if (!$shouldRun && $active) {
        db()->prepare('UPDATE stores SET active = 0 WHERE id = ?')->execute([$storeId]);
        queue_store_command($storeId, 'stop');
    }
}

function subscription_transition(int $storeId, string $toStatus, string $note = '', string $actor = 'system'): void
{
    $store = find_store($storeId);
    if (!$store) {
        return;
    }
    $from = normalize_subscription_status((string) ($store['billing_status'] ?? 'ativa'));
    $to = normalize_subscription_status($toStatus);
    if ($from === $to) {
        return;
    }

    $now = date('Y-m-d H:i:s');
    $suspendedAt = (string) ($store['suspended_at'] ?? '');
    $cancelledAt = (string) ($store['cancelled_at'] ?? '');
    if ($to === 'suspensa') {
        $suspendedAt = $now;
    } elseif (in_array($to, ['ativa', 'trial', 'pendente', 'cortesia'], true)) {
        $suspendedAt = '';
    }
    if ($to === 'cancelada') {
        $cancelledAt = $now;
    } elseif ($to !== 'cancelada' && $to !== 'encerrada') {
        $cancelledAt = '';
    }

    db()->prepare(
        'UPDATE stores SET billing_status = ?, suspended_at = ?, cancelled_at = ? WHERE id = ?'
    )->execute([$to, $suspendedAt, $cancelledAt, $storeId]);

    subscription_log_event($storeId, 'status_change', $from, $to, $note, $actor);
    subscription_sync_contract($storeId);
}

function subscription_init_trial(int $storeId): void
{
    $days = saas_trial_days();
    if ($days <= 0) {
        return;
    }
    $ends = date('Y-m-d', strtotime('+' . $days . ' days') ?: time());
    db()->prepare(
        'UPDATE stores SET billing_status = ?, trial_ends_at = ?, paid_until = ?, active = 1 WHERE id = ?'
    )->execute(['trial', $ends, $ends, $storeId]);
    subscription_log_event($storeId, 'trial_started', '', 'trial', $days . ' dia(s) de trial', 'system');
}

function subscription_on_charge_created(int $storeId): void
{
    $store = find_store($storeId);
    if (!$store) {
        return;
    }
    $status = normalize_subscription_status((string) ($store['billing_status'] ?? 'ativa'));
    if (in_array($status, ['ativa', 'atrasada', 'trial', 'pendente'], true)) {
        subscription_transition($storeId, 'pendente', 'Cobrança gerada', 'system');
    }
}

function subscription_on_payment_paid(int $storeId, string $note = 'Pagamento confirmado'): void
{
    $store = find_store($storeId);
    if (!$store) {
        return;
    }
    $until = next_paid_until($store);
    db()->prepare(
        'UPDATE stores SET paid_until = ?, trial_ends_at = ?, next_billing_at = ? WHERE id = ?'
    )->execute([$until, '', $until, $storeId]);
    subscription_transition($storeId, 'ativa', $note, 'system');
}

function subscription_mrr_cents(array $stores): int
{
    $total = 0;
    foreach ($stores as $store) {
        $status = normalize_subscription_status((string) ($store['billing_status'] ?? ''));
        if (!in_array($status, ['trial', 'ativa', 'pendente', 'atrasada'], true)) {
            continue;
        }
        $cents = money_to_cents((string) ($store['monthly_fee'] ?? ''));
        $months = (int) plan_meta((string) ($store['plan'] ?? 'mensal'))['months'];
        if ($cents < 100 || $months < 1) {
            continue;
        }
        $total += (int) round($cents * 12 / $months);
    }
    return $total;
}

function subscription_row(array $store): array
{
    $status = normalize_subscription_status((string) ($store['billing_status'] ?? 'ativa'));
    $plan = (string) ($store['plan'] ?? 'mensal');
    return [
        'id' => (int) $store['id'],
        'name' => (string) $store['name'],
        'city' => (string) ($store['city'] ?? ''),
        'contact' => (string) ($store['contact'] ?? ''),
        'plan' => $plan,
        'plan_label' => plan_meta($plan)['label'],
        'cycle_amount' => (string) ($store['monthly_fee'] ?? ''),
        'paid_until' => (string) ($store['paid_until'] ?? ''),
        'trial_ends_at' => (string) ($store['trial_ends_at'] ?? ''),
        'next_billing_at' => (string) ($store['next_billing_at'] ?? ''),
        'billing_status' => $status,
        'billing_label' => subscription_label($status),
        'tag_class' => subscription_tag_class($status),
        'auto_billing' => (int) ($store['auto_billing'] ?? 1) === 1,
        'active' => (int) ($store['active'] ?? 1) === 1,
        'notes' => (string) ($store['notes'] ?? ''),
    ];
}

function subscriptions_overview(?string $filter = null): array
{
    $filter = $filter !== null ? normalize_subscription_status($filter) : null;
    $stores = all_stores();
    $kpis = [
        'total' => 0,
        'ativas' => 0,
        'trial' => 0,
        'pendentes' => 0,
        'atrasadas' => 0,
        'suspensas' => 0,
        'mrr_cents' => subscription_mrr_cents($stores),
    ];
    $rows = [];
    foreach ($stores as $store) {
        $row = subscription_row($store);
        $kpis['total']++;
        match ($row['billing_status']) {
            'trial' => $kpis['trial']++,
            'pendente' => $kpis['pendentes']++,
            'atrasada' => $kpis['atrasadas']++,
            'suspensa' => $kpis['suspensas']++,
            'ativa', 'cortesia' => $kpis['ativas']++,
            default => null,
        };
        if ($filter !== null && $row['billing_status'] !== $filter) {
            continue;
        }
        $rows[] = $row;
    }
    return ['kpis' => $kpis, 'rows' => $rows, 'filters' => subscription_statuses()];
}

function subscription_expire_trials(): int
{
    $n = 0;
    $today = date('Y-m-d');
    foreach (all_stores() as $store) {
        if (normalize_subscription_status((string) ($store['billing_status'] ?? '')) !== 'trial') {
            continue;
        }
        $ends = trim((string) ($store['trial_ends_at'] ?? ''));
        if ($ends === '' || $ends >= $today) {
            continue;
        }
        subscription_transition((int) $store['id'], 'atrasada', 'Trial encerrado', 'system');
        $n++;
    }
    return $n;
}

function subscription_apply_grace_suspensions(): int
{
    if (!saas_auto_suspend_enabled()) {
        return 0;
    }
    $n = 0;
    $grace = saas_grace_days();
    $today = date('Y-m-d');
    foreach (all_stores() as $store) {
        if (normalize_subscription_status((string) ($store['billing_status'] ?? '')) !== 'atrasada') {
            continue;
        }
        $until = trim((string) ($store['paid_until'] ?? ''));
        if ($until === '') {
            continue;
        }
        $deadline = date('Y-m-d', strtotime($until . ' +' . $grace . ' days') ?: time());
        if ($deadline >= $today) {
            continue;
        }
        subscription_transition((int) $store['id'], 'suspensa', 'Grace de ' . $grace . ' dia(s) esgotado', 'system');
        $n++;
    }
    return $n;
}

function subscription_mark_overdue(): int
{
    $n = 0;
    $today = date('Y-m-d');
    foreach (all_stores() as $store) {
        $status = normalize_subscription_status((string) ($store['billing_status'] ?? ''));
        if (!in_array($status, ['ativa', 'pendente', 'trial'], true)) {
            continue;
        }
        $until = trim((string) ($store['paid_until'] ?? ''));
        if ($until === '' || $until >= $today) {
            continue;
        }
        subscription_transition((int) $store['id'], 'atrasada', 'Vigência vencida', 'system');
        $n++;
    }
    return $n;
}

function subscription_run_daily(): array
{
    $trials = subscription_expire_trials();
    $overdue = subscription_mark_overdue();
    $suspended = subscription_apply_grace_suspensions();
    $billing = pagseguro_run_billing();
    return [
        'trials_ended' => $trials,
        'overdue' => $overdue,
        'suspended' => $suspended,
        'created' => (int) ($billing['created'] ?? 0),
        'errors' => $billing['errors'] ?? [],
    ];
}

function subscription_update(int $id, array $fields, string $actor = 'admin'): void
{
    $store = find_store($id);
    if (!$store) {
        return;
    }
    $plan = (string) ($fields['plan'] ?? 'mensal');
    if (!in_array($plan, ['mensal', 'trimestral', 'anual'], true)) {
        $plan = 'mensal';
    }
    $status = normalize_subscription_status((string) ($fields['billing_status'] ?? $store['billing_status'] ?? 'ativa'));
    db()->prepare(
        'UPDATE stores SET plan = ?, monthly_fee = ?, paid_until = ?, auto_billing = ?, notes = ? WHERE id = ?'
    )->execute([
        $plan,
        (string) ($fields['monthly_fee'] ?? ''),
        (string) ($fields['paid_until'] ?? ''),
        !empty($fields['auto_billing']) ? 1 : 0,
        (string) ($fields['notes'] ?? ''),
        $id,
    ]);
    $oldStatus = normalize_subscription_status((string) ($store['billing_status'] ?? 'ativa'));
    if ($oldStatus !== $status) {
        subscription_transition($id, $status, 'Alteração manual no painel', $actor);
    }
}
