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

function pagseguro_token(): string
{
    return trim(setting('pagseguro_token', ''));
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
    return rtrim(guess_panel_url(), '/') . '/webhooks/pagbank';
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

function pagseguro_error_message(array $res): string
{
    if (($res['error'] ?? '') !== '') {
        return (string) $res['error'];
    }
    $data = $res['data'] ?? [];
    if (!empty($data['error_messages'][0]['description'])) {
        return (string) $data['error_messages'][0]['description'];
    }
    if (!empty($data['message'])) {
        return (string) $data['message'];
    }
    $code = (int) ($res['code'] ?? 0);
    if ($code === 401) {
        return 'Token recusado. Confira o ambiente (sandbox ou produção) e gere um token novo.';
    }
    if ($code === 0) {
        return 'Não foi possível falar com o PagSeguro.';
    }
    return 'PagSeguro HTTP ' . $code;
}

function pagseguro_test_token(): array
{
    $res = pagseguro_request('GET', '/checkouts/CHEC_00000000-0000-0000-0000-000000000000');
    $code = (int) ($res['code'] ?? 0);
    if ($code === 401 || $code === 403) {
        return ['ok' => false, 'message' => pagseguro_error_message($res)];
    }
    if ($code === 0 && ($res['error'] ?? '') !== '') {
        return ['ok' => false, 'message' => pagseguro_error_message($res)];
    }
    $env = pagseguro_env() === 'production' ? 'produção' : 'sandbox';
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
    db()->prepare(
        'UPDATE stores SET billing_status = ?, paid_until = ? WHERE id = ?'
    )->execute(['em_dia', $until, $storeId]);
}

function pagseguro_create_charge(int $storeId): array
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
    $plan = (string) ($store['plan'] ?? 'mensal');
    $planLabel = match ($plan) {
        'trimestral' => 'Trimestral',
        'anual' => 'Anual',
        default => 'Mensal',
    };
    $reference = 'wl-' . $storeId . '-' . bin2hex(random_bytes(6));
    $notify = pagseguro_webhook_url();
    $return = rtrim(guess_panel_url(), '/') . '/admin?tab=clientes&id=' . $storeId;
    $exp = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->modify('+7 days');
    $itemName = 'Wi-Fi da loja ' . $planLabel . ' — ' . (string) $store['name'];
    if (strlen($itemName) > 100) {
        $itemName = substr($itemName, 0, 100);
    }

    $res = pagseguro_request('POST', '/checkouts', [
        'reference_id' => $reference,
        'expiration_date' => $exp->format('c'),
        'customer_modifiable' => true,
        'items' => [[
            'reference_id' => 'plano-' . $storeId,
            'name' => $itemName,
            'quantity' => 1,
            'unit_amount' => $cents,
        ]],
        'payment_methods' => [
            ['type' => 'PIX'],
            ['type' => 'CREDIT_CARD'],
            ['type' => 'BOLETO'],
        ],
        'payment_notification_urls' => [$notify],
        'notification_urls' => [$notify],
        'return_url' => $return,
        'soft_descriptor' => 'WIFIDALOJA',
    ]);
    if (!$res['ok']) {
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
    return [
        'pay_url' => $payUrl,
        'checkout_id' => $checkoutId,
        'reference_id' => $reference,
        'amount_cents' => $cents,
    ];
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
    if (!$payment) {
        return;
    }
    $paid = pagseguro_payload_paid($payload);
    if (!$paid && !empty($payment['checkout_id'])) {
        $check = pagseguro_request('GET', '/checkouts/' . rawurlencode((string) $payment['checkout_id']));
        if ($check['ok']) {
            $paid = pagseguro_payload_paid($check['data']);
            $payload = $check['data'];
        }
    }
    if ($paid) {
        mark_payment_paid($payment, $payload);
    }
}
