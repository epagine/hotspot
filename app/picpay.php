<?php

declare(strict_types=1);

function picpay_env(): string
{
    $env = setting('picpay_env', 'sandbox');
    return $env === 'production' ? 'production' : 'sandbox';
}

function picpay_api_base(): string
{
    return 'https://api.picpay.com';
}

function picpay_client_id(): string
{
    return trim(setting('picpay_client_id', ''));
}

function picpay_client_secret(): string
{
    return trim(setting('picpay_client_secret', ''));
}

function picpay_seller_token(): string
{
    return trim(setting('picpay_seller_token', ''));
}

function picpay_configured(): bool
{
    return picpay_client_id() !== '' && picpay_client_secret() !== '' && picpay_seller_token() !== '';
}

function picpay_mask_secret(string $value): string
{
    $len = strlen($value);
    if ($len < 6) {
        return $len > 0 ? '•••• salvo' : '';
    }
    return '••••' . substr($value, -4);
}

function picpay_webhook_url(): string
{
    return rtrim(guess_panel_url(), '/') . '/notificacoes/picpay';
}

function picpay_error_message(array $res): string
{
    if (($res['error'] ?? '') !== '') {
        return (string) $res['error'];
    }
    $data = $res['data'] ?? [];
    foreach (['message', 'error_description', 'error'] as $key) {
        if (!empty($data[$key]) && is_string($data[$key])) {
            return (string) $data[$key];
        }
    }
    $code = (int) ($res['code'] ?? 0);
    if ($code === 401 || $code === 403) {
        return 'Credenciais PicPay recusadas. Confira client_id, client_secret e ambiente.';
    }
    if ($code === 0) {
        return 'Não foi possível falar com o PicPay.';
    }
    return 'PicPay HTTP ' . $code;
}

function picpay_access_token(): string
{
    static $cache = ['token' => '', 'exp' => 0];
    if ($cache['token'] !== '' && $cache['exp'] > time() + 20) {
        return $cache['token'];
    }
    $clientId = picpay_client_id();
    $clientSecret = picpay_client_secret();
    if ($clientId === '' || $clientSecret === '') {
        return '';
    }
    $res = http_json('POST', picpay_api_base() . '/oauth2/token', [
        'Accept: application/json',
        'Content-Type: application/json',
    ], [
        'grant_type' => 'client_credentials',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
    ]);
    if (!$res['ok']) {
        return '';
    }
    $token = trim((string) ($res['data']['access_token'] ?? ''));
    $expiresIn = (int) ($res['data']['expires_in'] ?? 300);
    if ($token === '') {
        return '';
    }
    $cache = [
        'token' => $token,
        'exp' => time() + max(60, min(600, $expiresIn)),
    ];
    return $token;
}

function picpay_request(string $method, string $path, ?array $payload = null): array
{
    $token = picpay_access_token();
    if ($token === '') {
        return ['ok' => false, 'code' => 0, 'error' => 'Configure as credenciais PicPay.', 'data' => [], 'raw' => ''];
    }
    return http_json($method, picpay_api_base() . $path, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ], $payload);
}

function picpay_test_credentials(): array
{
    $token = picpay_access_token();
    if ($token === '') {
        $res = http_json('POST', picpay_api_base() . '/oauth2/token', [
            'Accept: application/json',
            'Content-Type: application/json',
        ], [
            'grant_type' => 'client_credentials',
            'client_id' => picpay_client_id(),
            'client_secret' => picpay_client_secret(),
        ]);
        if (!$res['ok']) {
            return ['ok' => false, 'message' => picpay_error_message($res)];
        }
        return ['ok' => false, 'message' => 'Token obtido, mas resposta inválida.'];
    }
    $env = picpay_env() === 'production' ? 'produção' : 'sandbox';
    return ['ok' => true, 'message' => 'Credenciais PicPay aceitas (' . $env . ').'];
}

function picpay_status_paid(string $status): bool
{
    return in_array(strtolower($status), ['paid', 'completed', 'authorized'], true);
}

function picpay_payment_status(string $referenceId): ?array
{
    $referenceId = trim($referenceId);
    if ($referenceId === '') {
        return null;
    }
    $res = picpay_request('GET', '/ecommerce/v2/payments/' . rawurlencode($referenceId) . '/status');
    if (!$res['ok']) {
        return null;
    }
    return $res['data'];
}

function picpay_buyer_from_name(string $name, string $document = ''): array
{
    $name = trim($name) !== '' ? trim($name) : 'Cliente Loja';
    $parts = preg_split('/\s+/u', $name, 2) ?: [$name];
    $doc = preg_replace('/\D+/', '', $document) ?? '';
    if (strlen($doc) < 11) {
        $doc = '00000000000';
    }
    return [
        'firstName' => $parts[0] ?: 'Cliente',
        'lastName' => $parts[1] ?? 'Loja',
        'document' => $doc,
    ];
}

function picpay_expire_pending(int $storeId): void
{
    $stmt = db()->prepare("SELECT * FROM payments WHERE store_id = ? AND status = 'pending'");
    $stmt->execute([$storeId]);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        db()->prepare("UPDATE payments SET status = 'expired' WHERE id = ?")->execute([(int) $row['id']]);
    }
    subscription_reconcile($storeId, 'Cobrança expirada', 'system');
}

function picpay_expire_pending_company(int $companyId): void
{
    $stmt = db()->prepare("SELECT * FROM payments WHERE company_id = ? AND status = 'pending'");
    $stmt->execute([$companyId]);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        db()->prepare("UPDATE payments SET status = 'expired' WHERE id = ?")->execute([(int) $row['id']]);
    }
    company_reconcile_subscription($companyId);
}

function picpay_create_charge(int $storeId, bool $force = false): array
{
    $store = find_store($storeId);
    if (!$store) {
        throw new RuntimeException('Cliente não encontrado.');
    }
    if (!picpay_configured()) {
        throw new RuntimeException('Configure o PicPay em Configurações.');
    }
    $cents = money_to_cents((string) ($store['monthly_fee'] ?? ''));
    if ($cents < 100) {
        throw new RuntimeException('Informe o valor do plano (mínimo R$ 1,00) na ficha do cliente.');
    }
    if ($force) {
        picpay_expire_pending($storeId);
    } elseif (store_pending_payment($storeId)) {
        throw new RuntimeException('Já existe uma cobrança aguardando pagamento para este cliente.');
    }

    $reference = 'wl-' . $storeId . '-' . bin2hex(random_bytes(6));
    $exp = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->modify('+7 days');
    $payload = [
        'referenceId' => $reference,
        'callbackUrl' => picpay_webhook_url(),
        'returnUrl' => rtrim(guess_panel_url(), '/') . client_url(),
        'expiresAt' => $exp->format('c'),
        'value' => round($cents / 100, 2),
        'buyer' => picpay_buyer_from_name((string) $store['name'], (string) ($store['contact'] ?? '')),
    ];

    $res = picpay_request('POST', '/ecommerce/v2/payments', $payload);
    if (!$res['ok']) {
        throw new RuntimeException(picpay_error_message($res));
    }
    $data = $res['data'];
    $payUrl = (string) ($data['paymentUrl'] ?? $data['qrcode']['content'] ?? '');
    db()->prepare(
        'INSERT INTO payments (store_id, reference_id, checkout_id, pay_url, amount_cents, status, raw, created_at)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        $storeId,
        $reference,
        $reference,
        $payUrl,
        $cents,
        'pending',
        json_encode($data, JSON_UNESCAPED_UNICODE),
        date('Y-m-d H:i:s'),
    ]);
    subscription_on_charge_created($storeId);
    return [
        'pay_url' => $payUrl,
        'checkout_id' => $reference,
        'reference_id' => $reference,
        'amount_cents' => $cents,
        'recurring' => false,
    ];
}

function picpay_create_company_charge(int $companyId, bool $force = false, ?int $planId = null): array
{
    $company = find_company($companyId);
    if (!$company) {
        throw new RuntimeException('Empresa não encontrada.');
    }
    if (!picpay_configured()) {
        throw new RuntimeException('PicPay não configurado. Peça ao administrador da plataforma.');
    }
    $sub = company_subscription($companyId);
    if (!$sub) {
        throw new RuntimeException('Assinatura não encontrada.');
    }
    if ($planId !== null && $planId > 0) {
        company_change_plan($companyId, $planId);
        $sub = company_subscription($companyId) ?? $sub;
    }
    $cents = (int) ($sub['price_cents'] ?? 0);
    if ($cents < 100) {
        throw new RuntimeException('Este plano é gratuito. Escolha um plano pago para gerar cobrança.');
    }
    if ($force) {
        picpay_expire_pending_company($companyId);
    } elseif (company_pending_payment($companyId)) {
        throw new RuntimeException('Já existe uma cobrança aguardando pagamento.');
    }

    $planId = (int) ($sub['plan_id'] ?? 0);
    $reference = 'wlc-' . $companyId . '-' . bin2hex(random_bytes(6));
    $exp = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->modify('+7 days');
    $payload = [
        'referenceId' => $reference,
        'callbackUrl' => picpay_webhook_url(),
        'returnUrl' => rtrim(guess_panel_url(), '/') . '/cliente',
        'expiresAt' => $exp->format('c'),
        'value' => round($cents / 100, 2),
        'buyer' => picpay_buyer_from_name((string) $company['trade_name'], (string) ($company['email'] ?? '')),
    ];

    $res = picpay_request('POST', '/ecommerce/v2/payments', $payload);
    if (!$res['ok']) {
        throw new RuntimeException(picpay_error_message($res));
    }
    $data = $res['data'];
    $payUrl = (string) ($data['paymentUrl'] ?? $data['qrcode']['content'] ?? '');
    db()->prepare(
        'INSERT INTO payments (store_id, company_id, plan_id, reference_id, checkout_id, pay_url, amount_cents, status, raw, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        0,
        $companyId,
        $planId,
        $reference,
        $reference,
        $payUrl,
        $cents,
        'pending',
        json_encode($data, JSON_UNESCAPED_UNICODE),
        date('Y-m-d H:i:s'),
    ]);
    company_on_charge_created($companyId);
    return [
        'pay_url' => $payUrl,
        'checkout_id' => $reference,
        'reference_id' => $reference,
        'amount_cents' => $cents,
        'recurring' => false,
    ];
}

function picpay_handle_notification(string $referenceId, array $raw = []): void
{
    $referenceId = trim($referenceId);
    if ($referenceId === '') {
        return;
    }
    $statusData = picpay_payment_status($referenceId) ?? $raw;
    $status = strtolower((string) ($statusData['status'] ?? ''));
    if (!picpay_status_paid($status)) {
        if ($status === 'expired') {
            $payment = find_payment_by_reference($referenceId);
            if ($payment && ($payment['status'] ?? '') === 'pending') {
                db()->prepare("UPDATE payments SET status = 'expired' WHERE id = ?")->execute([(int) $payment['id']]);
                $companyId = (int) ($payment['company_id'] ?? 0);
                if ($companyId > 0) {
                    company_reconcile_subscription($companyId);
                } else {
                    subscription_reconcile((int) $payment['store_id'], 'Cobrança expirada', 'system');
                }
            }
        }
        return;
    }

    $payment = find_payment_by_reference($referenceId);
    if ($payment) {
        mark_payment_paid($payment, $statusData);
        return;
    }
    $companyId = company_id_from_reference($referenceId);
    if ($companyId > 0 && find_company($companyId)) {
        pagseguro_record_paid_company($companyId, $referenceId, $statusData);
        return;
    }
    $storeId = store_id_from_reference($referenceId);
    if ($storeId > 0 && find_store($storeId)) {
        pagseguro_record_paid_store($storeId, $referenceId, $statusData);
    }
}

function picpay_run_billing(): array
{
    pagseguro_expire_stale_pending();
    $created = 0;
    $errors = [];
    if (!picpay_configured() || !payment_auto_enabled()) {
        set_setting('payment_last_run', date('c'));
        return ['created' => 0, 'overdue' => 0, 'errors' => $errors];
    }
    foreach (all_stores() as $store) {
        if ((int) ($store['company_id'] ?? 0) > 0) {
            continue;
        }
        if (!store_due_for_auto_charge($store)) {
            continue;
        }
        try {
            picpay_create_charge((int) $store['id'], false);
            $created++;
        } catch (Throwable $e) {
            $errors[] = (string) $store['name'] . ': ' . $e->getMessage();
        }
    }
    foreach (all_companies() as $company) {
        $companyId = (int) $company['id'];
        $sub = company_subscription($companyId);
        if (!$sub || !company_due_for_auto_charge($sub, $company)) {
            continue;
        }
        try {
            picpay_create_company_charge($companyId, false);
            $created++;
        } catch (Throwable $e) {
            $errors[] = (string) $company['trade_name'] . ': ' . $e->getMessage();
        }
    }
    set_setting('payment_last_run', date('c'));
    return ['created' => $created, 'overdue' => 0, 'errors' => $errors];
}
