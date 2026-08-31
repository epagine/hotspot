<?php

declare(strict_types=1);

function pagseguro_env(): string
{
    $env = setting('pagseguro_env', 'sandbox');
    return $env === 'production' ? 'production' : 'sandbox';
}

function pagseguro_api_base(): string
{
    return pagseguro_env() === 'production'
        ? 'https://api.pagseguro.com'
        : 'https://sandbox.api.pagseguro.com';
}

function pagseguro_normalize_token(string $token): string
{
    $token = preg_replace('/^\xEF\xBB\xBF/', '', $token) ?? $token;
    $token = trim($token);
    $token = trim($token, "\"'");
    if (preg_match('/^bearer\s+/i', $token)) {
        $token = (string) preg_replace('/^bearer\s+/i', '', $token);
    }
    return preg_replace('/\s+/', '', $token) ?? $token;
}

function pagseguro_token(): string
{
    $raw = (string) setting('pagseguro_token', '');
    $clean = pagseguro_normalize_token($raw);
    if ($clean !== '' && $clean !== $raw) {
        set_setting('pagseguro_token', $clean);
    }
    return $clean;
}

function pagseguro_configured(): bool
{
    return pagseguro_token() !== '';
}

function pagseguro_mask_token(?string $token = null): string
{
    $token = $token ?? pagseguro_token();
    $len = strlen($token);
    if ($len < 8) {
        return $len > 0 ? '•••• salva' : '';
    }
    return '••••' . substr($token, -4);
}

function pagseguro_webhook_url(): string
{
    return rtrim(guess_panel_url(), '/') . '/notificacoes/pagbank';
}

function http_json(string $method, string $url, array $headers = [], ?array $payload = null): array
{
    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headerLines = $headers;
    if ($body !== null && !preg_grep('/^content-type:/i', $headerLines)) {
        $headerLines[] = 'Content-Type: application/json; charset=utf-8';
    }
    $raw = '';
    $code = 0;

    if (function_exists('curl_init') && php_cmd_allowed('curl_exec')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === '' && $err !== '') {
            return ['ok' => false, 'code' => 0, 'error' => $err, 'data' => [], 'raw' => ''];
        }
    } else {
        $opts = [
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headerLines),
                'ignore_errors' => true,
                'timeout' => 25,
            ],
        ];
        if ($body !== null) {
            $opts['http']['content'] = $body;
        }
        $raw = (string) @file_get_contents($url, false, stream_context_create($opts));
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        if ($raw === '' && $code === 0) {
            return ['ok' => false, 'code' => 0, 'error' => 'Sem cURL e allow_url_fopen na hospedagem.', 'data' => [], 'raw' => ''];
        }
    }

    $data = json_decode($raw, true);
    return [
        'ok' => $code >= 200 && $code < 300,
        'code' => $code,
        'error' => '',
        'data' => is_array($data) ? $data : [],
        'raw' => $raw,
    ];
}

function pagseguro_request(string $method, string $path, ?array $payload = null): array
{
    $token = pagseguro_token();
    if ($token === '') {
        return ['ok' => false, 'code' => 0, 'error' => 'Informe o token PagSeguro.', 'data' => [], 'raw' => ''];
    }
    return http_json($method, pagseguro_api_base() . $path, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ], $payload);
}

function pagseguro_request_base(string $base, string $method, string $path, ?array $payload = null): array
{
    $token = pagseguro_token();
    if ($token === '') {
        return ['ok' => false, 'code' => 0, 'error' => 'Informe o token PagSeguro.', 'data' => [], 'raw' => ''];
    }
    return http_json($method, rtrim($base, '/') . $path, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ], $payload);
}

function pagseguro_error_message(array $res): string
{
    if (($res['error'] ?? '') !== '') {
        return (string) $res['error'];
    }
    $data = $res['data'] ?? [];
    $detail = '';
    $errCode = '';
    if (!empty($data['error_messages'][0]) && is_array($data['error_messages'][0])) {
        $row = $data['error_messages'][0];
        $detail = (string) ($row['description'] ?? $row['message'] ?? '');
        $errCode = (string) ($row['code'] ?? $row['error'] ?? '');
    }
    if ($detail === '' && !empty($data['message'])) {
        $detail = (string) $data['message'];
    }
    $authFail = str_contains(strtolower($detail), 'authorization')
        || str_contains(strtolower($errCode), 'authorization')
        || (int) ($res['code'] ?? 0) === 401;
    if ($authFail) {
        $env = pagseguro_env() === 'production' ? 'produção' : 'sandbox';
        return 'O PagBank recusou o token neste ambiente (' . $env . '). '
            . 'Use o token do mesmo ambiente: sandbox no Portal do Desenvolvedor (Tokens), '
            . 'produção em Vendas → Integrações. Cole só o token, sem a palavra Bearer.';
    }
    if ($detail !== '') {
        return $detail;
    }
    $code = (int) ($res['code'] ?? 0);
    if ($code === 0) {
        return 'Não foi possível falar com o PagSeguro.';
    }
    return 'PagSeguro HTTP ' . $code;
}

function pagseguro_test_token(): array
{
    $path = '/checkouts/CHEC_00000000-0000-0000-0000-000000000000';
    $current = pagseguro_env();
    $res = pagseguro_request('GET', $path);
    $code = (int) ($res['code'] ?? 0);
    $authFail = $code === 401 || $code === 403
        || str_contains(strtolower(json_encode($res['data'] ?? []) ?: ''), 'authorization');
    if ($authFail) {
        $otherBase = $current === 'production'
            ? 'https://sandbox.api.pagseguro.com'
            : 'https://api.pagseguro.com';
        $other = pagseguro_request_base($otherBase, 'GET', $path);
        $otherCode = (int) ($other['code'] ?? 0);
        $otherAuth = $otherCode === 401 || $otherCode === 403
            || str_contains(strtolower(json_encode($other['data'] ?? []) ?: ''), 'authorization');
        if (!$otherAuth && $otherCode !== 0) {
            $hint = $current === 'production' ? 'sandbox' : 'produção';
            return [
                'ok' => false,
                'message' => 'Este token não vale no ambiente atual. Troque para ' . $hint . ' e salve de novo.',
            ];
        }
        return ['ok' => false, 'message' => pagseguro_error_message($res)];
    }
    if ($code === 0 && ($res['error'] ?? '') !== '') {
        return ['ok' => false, 'message' => pagseguro_error_message($res)];
    }
    $env = $current === 'production' ? 'produção' : 'sandbox';
    return ['ok' => true, 'message' => 'Token aceito no ambiente ' . $env . '.'];
}

function pagseguro_pay_url(array $checkout): string
{
    foreach ($checkout['links'] ?? [] as $link) {
        if (!is_array($link)) {
            continue;
        }
        $rel = strtoupper((string) ($link['rel'] ?? ''));
        if ($rel === 'PAY' || $rel === 'CHECKOUT') {
            return (string) ($link['href'] ?? '');
        }
    }
    return '';
}

function pagseguro_payload_paid(array $data): bool
{
    $status = strtoupper((string) ($data['status'] ?? ''));
    if (in_array($status, ['PAID', 'AVAILABLE', 'COMPLETED'], true)) {
        return true;
    }
    foreach ($data['charges'] ?? [] as $charge) {
        if (!is_array($charge)) {
            continue;
        }
        $st = strtoupper((string) ($charge['status'] ?? ''));
        if (in_array($st, ['PAID', 'AVAILABLE'], true)) {
            return true;
        }
    }
    return false;
}

function store_payments(int $storeId, int $limit = 8): array
{
    $stmt = db()->prepare('SELECT * FROM payments WHERE store_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit));
    $stmt->execute([$storeId]);
    return $stmt->fetchAll() ?: [];
}

function find_payment_by_reference(string $reference): ?array
{
    $reference = trim($reference);
    if ($reference === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM payments WHERE reference_id = ?');
    $stmt->execute([$reference]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_payment_by_checkout(string $checkoutId): ?array
{
    $checkoutId = trim($checkoutId);
    if ($checkoutId === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM payments WHERE checkout_id = ?');
    $stmt->execute([$checkoutId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function mark_payment_paid(array $payment, array $raw = []): void
{
    if (($payment['status'] ?? '') === 'paid') {
        return;
    }
    $id = (int) $payment['id'];
    $storeId = (int) $payment['store_id'];
    $store = find_store($storeId);
    if (!$store) {
        return;
    }
    $until = next_paid_until($store);
    db()->prepare(
        'UPDATE payments SET status = ?, paid_at = ?, raw = ? WHERE id = ?'
    )->execute(['paid', date('Y-m-d H:i:s'), json_encode($raw, JSON_UNESCAPED_UNICODE), $id]);
    subscription_on_payment_paid($storeId);
}

function pagseguro_cron_key(): string
{
    $key = trim(setting('pagseguro_cron_key', ''));
    if ($key === '') {
        $key = bin2hex(random_bytes(16));
        set_setting('pagseguro_cron_key', $key);
    }
    return $key;
}

function pagseguro_cron_url(): string
{
    return rtrim(guess_panel_url(), '/') . '/cron/pagseguro/' . rawurlencode(pagseguro_cron_key());
}

function pagseguro_auto_enabled(): bool
{
    return setting('pagseguro_auto', '1') !== '0';
}

function pagseguro_advance_days(): int
{
    $n = (int) setting('pagseguro_advance_days', '5');
    return max(0, min(30, $n));
}

function store_id_from_reference(string $reference): int
{
    if (preg_match('/^wl-(\d+)-/', $reference, $m)) {
        return (int) $m[1];
    }
    return 0;
}

function store_pending_payment(int $storeId): ?array
{
    $stmt = db()->prepare(
        "SELECT * FROM payments WHERE store_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$storeId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function pagseguro_expire_pending(int $storeId): void
{
    $stmt = db()->prepare("SELECT * FROM payments WHERE store_id = ? AND status = 'pending'");
    $stmt->execute([$storeId]);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $cid = (string) ($row['checkout_id'] ?? '');
        if ($cid !== '') {
            pagseguro_request('POST', '/checkouts/' . rawurlencode($cid) . '/inactivate');
        }
        db()->prepare("UPDATE payments SET status = 'expired' WHERE id = ?")->execute([(int) $row['id']]);
    }
}

function pagseguro_expire_stale_pending(): void
{
    $stmt = db()->query(
        "SELECT * FROM payments WHERE status = 'pending' AND created_at < datetime('now', '-8 days')"
    );
    foreach ($stmt ? ($stmt->fetchAll() ?: []) : [] as $row) {
        $cid = (string) ($row['checkout_id'] ?? '');
        if ($cid !== '') {
            pagseguro_request('POST', '/checkouts/' . rawurlencode($cid) . '/inactivate');
        }
        db()->prepare("UPDATE payments SET status = 'expired' WHERE id = ?")->execute([(int) $row['id']]);
    }
}

function store_due_for_auto_charge(array $store): bool
{
    if ((int) ($store['active'] ?? 1) !== 1) {
        return false;
    }
    if ((int) ($store['auto_billing'] ?? 1) !== 1) {
        return false;
    }
    $bill = normalize_subscription_status((string) ($store['billing_status'] ?? 'ativa'));
    if (in_array($bill, ['cortesia', 'cancelada', 'suspensa', 'encerrada'], true)) {
        return false;
    }
    if (money_to_cents((string) ($store['monthly_fee'] ?? '')) < 100) {
        return false;
    }
    if (store_pending_payment((int) $store['id'])) {
        return false;
    }
    $until = trim((string) ($store['paid_until'] ?? ''));
    if ($until === '') {
        return true;
    }
    $t = strtotime($until . ' 23:59:59');
    if ($t === false) {
        return true;
    }
    return $t <= time() + pagseguro_advance_days() * 86400;
}

function pagseguro_mark_overdue(): int
{
    return subscription_mark_overdue();
}

function pagseguro_create_charge(int $storeId, bool $force = false): array
{
    $store = find_store($storeId);
    if (!$store) {
        throw new RuntimeException('Cliente não encontrado.');
    }
    if (!pagseguro_configured()) {
        throw new RuntimeException('Configure o token PagSeguro em Configuração.');
    }
    $cents = money_to_cents((string) ($store['monthly_fee'] ?? ''));
    if ($cents < 100) {
        throw new RuntimeException('Informe o valor do plano (mínimo R$ 1,00) na ficha do cliente.');
    }
    if ($force) {
        pagseguro_expire_pending($storeId);
    } elseif (store_pending_payment($storeId)) {
        throw new RuntimeException('Já existe uma cobrança aguardando pagamento para este cliente.');
    }

    $plan = (string) ($store['plan'] ?? 'mensal');
    $meta = plan_meta($plan);
    $recurring = (int) ($store['auto_billing'] ?? 1) === 1;
    $reference = 'wl-' . $storeId . '-' . bin2hex(random_bytes(6));
    $notify = pagseguro_webhook_url();
    $return = rtrim(guess_panel_url(), '/') . admin_url('clientes', $storeId);
    $exp = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->modify('+7 days');
    $itemName = 'Wi-Fi da loja ' . $meta['label'] . ' — ' . (string) $store['name'];
    if (strlen($itemName) > 100) {
        $itemName = substr($itemName, 0, 100);
    }

    $payload = [
        'reference_id' => $reference,
        'expiration_date' => $exp->format('c'),
        'customer_modifiable' => true,
        'items' => [[
            'reference_id' => 'plano-' . $storeId,
            'name' => $itemName,
            'quantity' => 1,
            'unit_amount' => $cents,
        ]],
        'payment_notification_urls' => [$notify],
        'notification_urls' => [$notify],
        'return_url' => $return,
        'soft_descriptor' => 'WIFIDALOJA',
    ];
    if ($recurring) {
        $payload['shipping'] = ['type' => 'FREE'];
        $payload['payment_methods'] = [['type' => 'CREDIT_CARD']];
        $payload['recurrence_plan'] = [
            'name' => 'Wi-Fi da loja ' . $meta['label'],
            'interval' => [
                'unit' => $meta['unit'],
                'length' => $meta['length'],
            ],
        ];
    } else {
        $payload['payment_methods'] = [
            ['type' => 'PIX'],
            ['type' => 'CREDIT_CARD'],
            ['type' => 'BOLETO'],
        ];
    }

    $res = pagseguro_request('POST', '/checkouts', $payload);
    if (!$res['ok'] && $recurring) {
        unset($payload['recurrence_plan'], $payload['shipping']);
        $payload['payment_methods'] = [
            ['type' => 'PIX'],
            ['type' => 'CREDIT_CARD'],
            ['type' => 'BOLETO'],
        ];
        $res = pagseguro_request('POST', '/checkouts', $payload);
        $recurring = false;
        if (!$res['ok']) {
            throw new RuntimeException(pagseguro_error_message($res));
        }
    } elseif (!$res['ok']) {
        throw new RuntimeException(pagseguro_error_message($res));
    }
    $data = $res['data'];
    $payUrl = pagseguro_pay_url($data);
    $checkoutId = (string) ($data['id'] ?? '');
    db()->prepare(
        'INSERT INTO payments (store_id, reference_id, checkout_id, pay_url, amount_cents, status, raw, created_at)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        $storeId,
        $reference,
        $checkoutId,
        $payUrl,
        $cents,
        'pending',
        json_encode($data, JSON_UNESCAPED_UNICODE),
        date('Y-m-d H:i:s'),
    ]);
    subscription_on_charge_created($storeId);
    return [
        'pay_url' => $payUrl,
        'checkout_id' => $checkoutId,
        'reference_id' => $reference,
        'amount_cents' => $cents,
        'recurring' => $recurring,
    ];
}

function pagseguro_run_billing(): array
{
    pagseguro_expire_stale_pending();
    $created = 0;
    $errors = [];
    if (!pagseguro_configured() || !pagseguro_auto_enabled()) {
        set_setting('pagseguro_last_run', date('c'));
        return ['created' => 0, 'overdue' => 0, 'errors' => $errors];
    }
    foreach (all_stores() as $store) {
        if (!store_due_for_auto_charge($store)) {
            continue;
        }
        try {
            pagseguro_create_charge((int) $store['id'], false);
            $created++;
        } catch (Throwable $e) {
            $errors[] = (string) $store['name'] . ': ' . $e->getMessage();
        }
    }
    set_setting('pagseguro_last_run', date('c'));
    return ['created' => $created, 'overdue' => 0, 'errors' => $errors];
}

function pagseguro_maybe_run_billing(): void
{
    if (!pagseguro_configured() || !pagseguro_auto_enabled()) {
        return;
    }
    $last = strtotime((string) setting('pagseguro_last_run', '')) ?: 0;
    if (time() - $last < 4 * 3600) {
        return;
    }
    try {
        subscription_run_daily();
    } catch (Throwable $e) {
        // o cron e o webhook tentam de novo
    }
}

function pagseguro_record_paid_store(int $storeId, string $reference, array $raw): void
{
    $existing = find_payment_by_reference($reference);
    if ($existing) {
        mark_payment_paid($existing, $raw);
        return;
    }
    $cents = (int) ($raw['charges'][0]['amount']['value'] ?? 0);
    if ($cents <= 0) {
        $store = find_store($storeId);
        $cents = $store ? money_to_cents((string) ($store['monthly_fee'] ?? '')) : 0;
    }
    db()->prepare(
        'INSERT INTO payments (store_id, reference_id, checkout_id, pay_url, amount_cents, status, raw, created_at, paid_at)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([
        $storeId,
        $reference !== '' ? $reference : 'wl-' . $storeId . '-auto-' . bin2hex(random_bytes(4)),
        (string) ($raw['id'] ?? ''),
        '',
        $cents,
        'paid',
        json_encode($raw, JSON_UNESCAPED_UNICODE),
        date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s'),
    ]);
    $store = find_store($storeId);
    if ($store) {
        subscription_on_payment_paid($storeId);
    }
}

function pagseguro_handle_notification(array $payload): void
{
    $reference = (string) ($payload['reference_id'] ?? '');
    $checkoutId = (string) ($payload['id'] ?? '');
    $payment = find_payment_by_reference($reference);
    if (!$payment && str_starts_with($checkoutId, 'CHEC_')) {
        $payment = find_payment_by_checkout($checkoutId);
    }
    if (!$payment) {
        foreach ($payload['charges'] ?? [] as $charge) {
            if (!is_array($charge)) {
                continue;
            }
            $payment = find_payment_by_reference((string) ($charge['reference_id'] ?? ''));
            if ($payment) {
                break;
            }
        }
    }
    $paid = pagseguro_payload_paid($payload);
    if ($payment && !$paid && !empty($payment['checkout_id'])) {
        $check = pagseguro_request('GET', '/checkouts/' . rawurlencode((string) $payment['checkout_id']));
        if ($check['ok']) {
            $paid = pagseguro_payload_paid($check['data']);
            $payload = $check['data'];
        }
    }
    if (!$paid) {
        return;
    }
    if ($payment) {
        mark_payment_paid($payment, $payload);
        return;
    }
    $storeId = store_id_from_reference($reference);
    if ($storeId < 1 && $payment) {
        $storeId = (int) $payment['store_id'];
    }
    if ($storeId > 0 && find_store($storeId)) {
        pagseguro_record_paid_store($storeId, $reference, $payload);
    }
}
