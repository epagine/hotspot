<?php

declare(strict_types=1);

$path = (string) ($GLOBALS['api_path'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

if ($path === '/auth' && $method === 'POST') {
    $user = auth_attempt((string) ($body['email'] ?? ''), (string) ($body['password'] ?? ''));
    if (!$user) {
        json_out(['ok' => false, 'error' => 'invalid_credentials'], 401);
    }
    auth_login($user);
    json_out([
        'ok' => true,
        'user' => [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'role' => (string) $user['role'],
        ],
        'company_id' => current_company_id(),
    ]);
}

if ($path === '/hotspot/authenticate' && $method === 'POST') {
    $token = trim((string) ($body['token'] ?? ''));
    $store = find_store_by_token($token);
    if (!$store) {
        json_out(['ok' => false, 'error' => 'token'], 401);
    }
    json_out([
        'ok' => true,
        'hotspot_id' => (int) $store['id'],
        'company_id' => (int) ($store['company_id'] ?? 0),
        'name' => (string) $store['name'],
        'provider' => (string) ($store['provider'] ?? 'windows'),
    ]);
}

if ($path === '/hotspot/status' && $method === 'GET') {
    $token = trim((string) ($_GET['token'] ?? ''));
    $store = find_store_by_token($token);
    if (!$store) {
        json_out(['ok' => false, 'error' => 'token'], 401);
    }
    $provider = network_provider((string) ($store['provider'] ?? 'windows'));
    json_out(['ok' => true, 'status' => $provider->status($store)]);
}

if ($path === '/hotspot/session' && $method === 'POST') {
    $token = trim((string) ($body['token'] ?? ''));
    $store = find_store_by_token($token);
    if (!$store) {
        json_out(['ok' => false, 'error' => 'token'], 401);
    }
    json_out(['ok' => true, 'message' => 'session_ack', 'hotspot_id' => (int) $store['id']]);
}

if ($path === '/hotspot/disconnect' && $method === 'POST') {
    $token = trim((string) ($body['token'] ?? ''));
    $store = find_store_by_token($token);
    if (!$store) {
        json_out(['ok' => false, 'error' => 'token'], 401);
    }
    $provider = network_provider((string) ($store['provider'] ?? 'windows'));
    $res = $provider->disconnectClient($store, [], []);
    json_out(['ok' => !empty($res['ok']), 'result' => $res]);
}

if ($path === '/client' && $method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT id, name, phone, email, state, access_count, last_access_at FROM clients WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        json_out(['ok' => false, 'error' => 'not_found'], 404);
    }
    json_out(['ok' => true, 'client' => $row]);
}

if ($path === '/client' && $method === 'POST') {
    json_out(['ok' => false, 'error' => 'use_portal'], 400);
}

if ($path === '/campaign' && $method === 'GET') {
    $token = trim((string) ($_GET['token'] ?? ''));
    $store = find_store_by_token($token);
    if (!$store) {
        json_out(['ok' => false, 'error' => 'token'], 401);
    }
    $cid = (int) ($store['company_id'] ?? 0);
    $camp = $cid > 0 ? active_campaign_for_hotspot($cid, (int) $store['id']) : null;
    json_out(['ok' => true, 'campaign' => $camp]);
}

if ($path === '/campaign/click' && $method === 'POST') {
    $campaignId = (int) ($body['campaign_id'] ?? 0);
    $companyId = (int) ($body['company_id'] ?? 0);
    if ($campaignId > 0 && $companyId > 0) {
        record_campaign_click($campaignId, $companyId, null, null);
    }
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'error' => 'not_found'], 404);
