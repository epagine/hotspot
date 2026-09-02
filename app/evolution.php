<?php

declare(strict_types=1);

function evolution_base_url(): string
{
    return rtrim(trim(setting('evolution_base_url', '')), '/');
}

function evolution_api_key(): string
{
    return trim(setting('evolution_api_key', ''));
}

function evolution_instance(): string
{
    return trim(setting('evolution_instance', ''));
}

function evolution_enabled(): bool
{
    return setting('evolution_enabled', '0') === '1';
}

function evolution_configured(): bool
{
    return evolution_base_url() !== ''
        && evolution_api_key() !== ''
        && evolution_instance() !== '';
}

function evolution_mask_secret(?string $value = null): string
{
    $value = $value ?? evolution_api_key();
    $len = strlen($value);
    if ($len < 8) {
        return $len > 0 ? '•••• salva' : '';
    }
    return '••••' . substr($value, -4);
}

function evolution_request(string $method, string $path, ?array $payload = null): array
{
    $base = evolution_base_url();
    if ($base === '') {
        return ['ok' => false, 'code' => 0, 'error' => 'URL da Evolution API não configurada.', 'data' => [], 'raw' => ''];
    }
    $url = $base . '/' . ltrim($path, '/');
    $headers = [
        'apikey: ' . evolution_api_key(),
        'Accept: application/json',
    ];
    return http_json($method, $url, $headers, $payload);
}

function evolution_connection_state(): array
{
    $instance = rawurlencode(evolution_instance());
    return evolution_request('GET', '/instance/connectionState/' . $instance);
}

function evolution_send_text(string $phone, string $text): array
{
    $phone = normalize_whatsapp_phone($phone);
    if ($phone === '') {
        return ['ok' => false, 'message' => 'Telefone inválido.'];
    }
    $body = trim($text);
    if ($body === '') {
        return ['ok' => false, 'message' => 'Mensagem vazia.'];
    }
    if (!evolution_configured()) {
        return ['ok' => false, 'message' => 'Evolution API não configurada.'];
    }
    if (!evolution_enabled()) {
        return ['ok' => false, 'message' => 'Envio por WhatsApp desativado.'];
    }

    $instance = rawurlencode(evolution_instance());
    $res = evolution_request('POST', '/message/sendText/' . $instance, [
        'number' => $phone,
        'text' => $body,
    ]);
    if (!$res['ok']) {
        $msg = evolution_error_message($res);
        return ['ok' => false, 'message' => $msg];
    }
    $data = $res['data'];
    $ref = (string) ($data['key']['id'] ?? $data['messageId'] ?? '');
    return ['ok' => true, 'message' => 'Mensagem enviada.', 'ref' => $ref, 'data' => $data];
}

function evolution_error_message(array $res): string
{
    $data = $res['data'] ?? [];
    if (is_array($data)) {
        foreach (['message', 'error', 'response'] as $key) {
            if (!empty($data[$key]) && is_string($data[$key])) {
                return $data[$key];
            }
        }
        if (!empty($data['response']['message']) && is_array($data['response']['message'])) {
            $parts = array_filter(array_map('strval', $data['response']['message']));
            if ($parts !== []) {
                return implode(' ', $parts);
            }
        }
    }
    if (!empty($res['error']) && is_string($res['error'])) {
        return $res['error'];
    }
    $code = (int) ($res['code'] ?? 0);
    return $code > 0 ? 'Evolution API respondeu HTTP ' . $code : 'Falha ao contactar Evolution API.';
}

function evolution_test_credentials(?string $testPhone = null): array
{
    if (!evolution_configured()) {
        return ['ok' => false, 'message' => 'Informe URL, API key e nome da instância.'];
    }
    $state = evolution_connection_state();
    if (!$state['ok']) {
        return ['ok' => false, 'message' => evolution_error_message($state)];
    }
    $connected = strtolower((string) ($state['data']['instance']['state'] ?? $state['data']['state'] ?? ''));
    if ($connected !== '' && !in_array($connected, ['open', 'connected'], true)) {
        return ['ok' => false, 'message' => 'Instância não conectada ao WhatsApp (estado: ' . $connected . ').'];
    }
    if ($testPhone !== null && trim($testPhone) !== '') {
        $phone = normalize_whatsapp_phone($testPhone);
        $instance = rawurlencode(evolution_instance());
        $res = evolution_request('POST', '/message/sendText/' . $instance, [
            'number' => $phone,
            'text' => 'Teste Wi-Fi da Loja — integração Evolution API OK.',
        ]);
        if (!$res['ok']) {
            return ['ok' => false, 'message' => evolution_error_message($res)];
        }
        return ['ok' => true, 'message' => 'Instância conectada e mensagem de teste enviada.'];
    }
    return ['ok' => true, 'message' => 'Instância conectada à Evolution API.'];
}
