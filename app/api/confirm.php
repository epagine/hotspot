<?php

declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'method'], 405);
}

$token = trim((string) ($GLOBALS['portal_token'] ?? ''));
if ($token === '') {
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $token = trim((string) ($body['token'] ?? ''));
}
$store = portal_store_from_token($token);
if (!$store) {
    json_out(['ok' => false, 'error' => 'token'], 404);
}

$hotspotId = (int) $store['id'];
$companyId = (int) ($store['company_id'] ?? 0);
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$phone = preg_replace('/\D+/', '', (string) ($body['phone'] ?? '')) ?: null;
$client = current_client();
$ip = client_ip();
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);

try {
    $result = portal_confirm_guest($store, $hotspotId, $companyId, $client, $phone, $ip, $ua);
    json_out([
        'ok' => true,
        'state' => $result['state'],
        'expires_at' => $result['expires_at'],
    ]);
} catch (RuntimeException $e) {
    $code = str_contains($e->getMessage(), 'cheia') ? 409 : 400;
    json_out(['ok' => false, 'error' => 'confirm', 'message' => $e->getMessage()], $code);
}
