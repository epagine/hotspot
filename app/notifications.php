<?php

declare(strict_types=1);

function notification_events(): array
{
    return [
        'charge_created' => 'Cobrança gerada (link de pagamento)',
        'payment_paid' => 'Pagamento confirmado',
        'trial_started' => 'Trial iniciado',
        'trial_ending' => 'Trial acabando em breve',
        'overdue' => 'Assinatura em atraso',
        'suspended' => 'Serviço suspenso',
    ];
}

function notification_default_template(string $event): string
{
    return match ($event) {
        'charge_created' => "Olá, {empresa}!\n\nSua mensalidade de {valor} vence em {vencimento}.\nPague aqui: {link_pagamento}\n\nWi-Fi da Loja",
        'payment_paid' => "Olá, {empresa}!\n\nRecebemos seu pagamento de {valor}. Sua assinatura está ativa até {vencimento}.\n\nObrigado!\nWi-Fi da Loja",
        'trial_started' => "Olá, {empresa}!\n\nSeu período de teste ({plano}) começou e vai até {vencimento}.\nAcesse o painel: {link_painel}\n\nWi-Fi da Loja",
        'trial_ending' => "Olá, {empresa}!\n\nSeu trial termina em {vencimento}. Escolha um plano para continuar:\n{link_painel}\n\nWi-Fi da Loja",
        'overdue' => "Olá, {empresa}!\n\nSua assinatura está em atraso (venceu em {vencimento}).\nRegularize em: {link_pagamento}\n\nWi-Fi da Loja",
        'suspended' => "Olá, {empresa}!\n\nSeu serviço foi suspenso por falta de pagamento.\nPara reativar: {link_pagamento}\n\nWi-Fi da Loja",
        default => '',
    };
}

function notification_template(string $event): string
{
    $key = 'notify_tpl_' . $event;
    $tpl = trim(setting($key, ''));
    return $tpl !== '' ? $tpl : notification_default_template($event);
}

function notification_event_enabled(string $event): bool
{
    if (!evolution_enabled() || !evolution_configured()) {
        return false;
    }
    $key = 'notify_on_' . $event;
    return setting($key, '1') !== '0';
}

function notification_placeholder_help(): string
{
    return '{empresa}, {plano}, {valor}, {vencimento}, {link_pagamento}, {link_painel}, {status}';
}

function normalize_whatsapp_phone(string $raw): string
{
    $digits = preg_replace('/\D/', '', $raw) ?? '';
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) >= 10 && strlen($digits) <= 11 && !str_starts_with($digits, '55')) {
        $digits = '55' . $digits;
    }
    return $digits;
}

function notification_render(string $event, array $vars): string
{
    $tpl = notification_template($event);
    if ($tpl === '') {
        return '';
    }
    $map = [];
    foreach ($vars as $key => $value) {
        $map['{' . $key . '}'] = (string) $value;
    }
    return strtr($tpl, $map);
}

function notification_company_phone(array $company): string
{
    $wa = normalize_whatsapp_phone((string) ($company['whatsapp'] ?? ''));
    if ($wa !== '') {
        return $wa;
    }
    return normalize_whatsapp_phone((string) ($company['phone'] ?? ''));
}

function notification_store_phone(array $store): string
{
    return normalize_whatsapp_phone((string) ($store['contact'] ?? ''));
}

function notification_company_vars(int $companyId, array $extra = []): array
{
    $company = find_company($companyId);
    if (!$company) {
        return $extra;
    }
    $sub = company_subscription_effective($companyId) ?? company_subscription($companyId);
    $plan = $sub ? find_plan((int) ($sub['plan_id'] ?? 0)) : null;
    $pending = company_pending_payment($companyId);
    $panel = rtrim(guess_panel_url(), '/');

    return array_merge([
        'empresa' => (string) ($company['trade_name'] ?? ''),
        'plano' => (string) ($sub['plan_name'] ?? ($plan['name'] ?? '')),
        'valor' => $sub ? cents_label((int) ($sub['price_cents'] ?? 0)) : '',
        'vencimento' => format_date_br((string) ($sub['ends_at'] ?? $sub['trial_ends_at'] ?? '')),
        'link_pagamento' => (string) ($pending['pay_url'] ?? $panel . '/cliente'),
        'link_painel' => $panel . '/app',
        'status' => (string) ($sub['billing_label'] ?? subscription_label((string) ($sub['status'] ?? ''))),
    ], $extra);
}

function notification_store_vars(int $storeId, array $extra = []): array
{
    $store = find_store($storeId);
    if (!$store) {
        return $extra;
    }
    $pending = store_pending_payment($storeId);
    $panel = rtrim(guess_panel_url(), '/');
    $status = normalize_subscription_status((string) ($store['billing_status'] ?? ''));

    return array_merge([
        'empresa' => (string) ($store['name'] ?? ''),
        'plano' => plan_meta((string) ($store['plan'] ?? 'mensal'))['label'],
        'valor' => cents_label(money_to_cents((string) ($store['monthly_fee'] ?? ''))),
        'vencimento' => format_date_br((string) ($store['paid_until'] ?? $store['trial_ends_at'] ?? '')),
        'link_pagamento' => (string) ($pending['pay_url'] ?? $panel . '/cliente'),
        'link_painel' => $panel . '/cliente',
        'status' => subscription_label($status),
    ], $extra);
}

function format_date_br(string $isoDate): string
{
    $isoDate = trim($isoDate);
    if ($isoDate === '') {
        return '';
    }
    $t = strtotime($isoDate);
    return $t !== false ? date('d/m/Y', $t) : $isoDate;
}

function notification_log(
    string $event,
    string $phone,
    string $body,
    string $status,
    int $companyId = 0,
    int $storeId = 0,
    string $providerRef = '',
    string $error = ''
): void {
    try {
        db()->prepare(
            'INSERT INTO message_log (company_id, store_id, phone, event_type, body, status, provider_ref, error, created_at)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $companyId > 0 ? $companyId : null,
            $storeId > 0 ? $storeId : null,
            $phone,
            $event,
            $body,
            $status,
            $providerRef,
            $error,
            date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // não interrompe fluxo de cobrança
    }
}

function notification_send(string $event, string $phone, string $body, int $companyId = 0, int $storeId = 0): bool
{
    if ($phone === '' || trim($body) === '') {
        notification_log($event, $phone, $body, 'skipped', $companyId, $storeId, '', 'Telefone ou mensagem vazios');
        return false;
    }
    if (!notification_event_enabled($event)) {
        notification_log($event, $phone, $body, 'skipped', $companyId, $storeId, '', 'Evento desativado ou integração off');
        return false;
    }
    $res = evolution_send_text($phone, $body);
    if ($res['ok']) {
        notification_log($event, $phone, $body, 'sent', $companyId, $storeId, (string) ($res['ref'] ?? ''));
        return true;
    }
    notification_log($event, $phone, $body, 'failed', $companyId, $storeId, '', (string) ($res['message'] ?? ''));
    return false;
}

function notify_company(int $companyId, string $event, array $extra = []): void
{
    if ($companyId <= 0 || !array_key_exists($event, notification_events())) {
        return;
    }
    try {
        $company = find_company($companyId);
        if (!$company) {
            return;
        }
        $phone = notification_company_phone($company);
        $vars = notification_company_vars($companyId, $extra);
        $body = notification_render($event, $vars);
        notification_send($event, $phone, $body, $companyId, 0);
    } catch (Throwable $e) {
        notification_log($event, '', '', 'failed', $companyId, 0, '', $e->getMessage());
    }
}

function notify_store(int $storeId, string $event, array $extra = []): void
{
    if ($storeId <= 0 || !array_key_exists($event, notification_events())) {
        return;
    }
    try {
        $store = find_store($storeId);
        if (!$store) {
            return;
        }
        $phone = notification_store_phone($store);
        $vars = notification_store_vars($storeId, $extra);
        $body = notification_render($event, $vars);
        notification_send($event, $phone, $body, 0, $storeId);
    } catch (Throwable $e) {
        notification_log($event, '', '', 'failed', 0, $storeId, '', $e->getMessage());
    }
}

function notify_company_status(int $companyId, string $status): void
{
    $status = normalize_subscription_status($status);
    if ($status === 'atrasada') {
        notify_company($companyId, 'overdue');
    } elseif ($status === 'suspensa') {
        notify_company($companyId, 'suspended');
    }
}

function notify_store_status(int $storeId, string $status): void
{
    $status = normalize_subscription_status($status);
    if ($status === 'atrasada') {
        notify_store($storeId, 'overdue');
    } elseif ($status === 'suspensa') {
        notify_store($storeId, 'suspended');
    }
}

function notification_recent_log(int $limit = 30): array
{
    $limit = max(1, min(100, $limit));
    $stmt = db()->query(
        'SELECT * FROM message_log ORDER BY id DESC LIMIT ' . $limit
    );
    return $stmt->fetchAll() ?: [];
}

function notification_sent_recently(string $event, int $companyId = 0, int $storeId = 0, int $hours = 72): bool
{
    $since = date('Y-m-d H:i:s', time() - max(1, $hours) * 3600);
    if ($companyId > 0) {
        $stmt = db()->prepare(
            "SELECT 1 FROM message_log WHERE company_id = ? AND event_type = ? AND status = 'sent' AND created_at >= ? LIMIT 1"
        );
        $stmt->execute([$companyId, $event, $since]);
        return (bool) $stmt->fetch();
    }
    if ($storeId > 0) {
        $stmt = db()->prepare(
            "SELECT 1 FROM message_log WHERE store_id = ? AND event_type = ? AND status = 'sent' AND created_at >= ? LIMIT 1"
        );
        $stmt->execute([$storeId, $event, $since]);
        return (bool) $stmt->fetch();
    }
    return false;
}

function notification_trial_reminder_days(): int
{
    return max(1, min(14, (int) setting('notify_trial_reminder_days', '3')));
}

function notification_run_trial_reminders(): int
{
    $sent = 0;
    $days = notification_trial_reminder_days();
    $target = date('Y-m-d', strtotime('+' . $days . ' days') ?: time());
    foreach (all_companies() as $company) {
        $companyId = (int) $company['id'];
        $sub = company_subscription($companyId);
        if (!$sub || normalize_subscription_status((string) ($sub['status'] ?? '')) !== 'trial') {
            continue;
        }
        $trialEnds = trim((string) ($sub['trial_ends_at'] ?? ''));
        if ($trialEnds === '' || $trialEnds !== $target) {
            continue;
        }
        if (notification_sent_recently('trial_ending', $companyId)) {
            continue;
        }
        notify_company($companyId, 'trial_ending');
        $sent++;
    }
    foreach (all_stores() as $store) {
        if ((int) ($store['company_id'] ?? 0) > 0) {
            continue;
        }
        $storeId = (int) $store['id'];
        if (normalize_subscription_status((string) ($store['billing_status'] ?? '')) !== 'trial') {
            continue;
        }
        $trialEnds = trim((string) ($store['trial_ends_at'] ?? ''));
        if ($trialEnds === '' || $trialEnds !== $target) {
            continue;
        }
        if (notification_sent_recently('trial_ending', 0, $storeId)) {
            continue;
        }
        notify_store($storeId, 'trial_ending');
        $sent++;
    }
    return $sent;
}
