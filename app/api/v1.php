<?php

declare(strict_types=1);

$path = (string) ($GLOBALS['api_path'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

if ($path === '/auth' && $method === 'POST') {
    if (rate_limit_is_blocked('api_auth')) {
        json_out(['ok' => false, 'error' => 'rate_limited', 'message' => rate_limit_reject_message()], 429);
    }
    $user = auth_attempt((string) ($body['email'] ?? ''), (string) ($body['password'] ?? ''));
    if (!$user) {
        rate_limit_fail('api_auth');
        json_out(['ok' => false, 'error' => 'invalid_credentials'], 401);
    }
    rate_limit_clear('api_auth');
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
    $token = agent_request_token($body);
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
    $token = agent_request_token($body);
    $store = find_store_by_token($token);
    if (!$store) {
        json_out(['ok' => false, 'error' => 'token'], 401);
    }
    $provider = network_provider((string) ($store['provider'] ?? 'windows'));
    json_out(['ok' => true, 'status' => $provider->status($store)]);
}

if ($path === '/hotspot/session' && $method === 'POST') {
    $token = agent_request_token($body);
    $store = find_store_by_token($token);
    if (!$store) {
        json_out(['ok' => false, 'error' => 'token'], 401);
    }
    json_out(['ok' => true, 'message' => 'session_ack', 'hotspot_id' => (int) $store['id']]);
}

if ($path === '/hotspot/disconnect' && $method === 'POST') {
    $token = agent_request_token($body);
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
    $user = current_user();
    $token = agent_request_token($body);
    $store = $token !== '' ? find_store_by_token($token) : null;
    if (!$user && !$store) {
        json_out(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    if ($id <= 0) {
        json_out(['ok' => false, 'error' => 'not_found'], 404);
    }
    if ($store) {
        $stmt = db()->prepare(
            'SELECT id, name, phone, email, state, access_count, last_access_at FROM clients WHERE id = ? AND store_id = ?'
        );
        $stmt->execute([$id, (int) $store['id']]);
    } else {
        $companyId = current_company_id();
        if (($user['role'] ?? '') === 'super_admin') {
            $stmt = db()->prepare(
                'SELECT id, name, phone, email, state, access_count, last_access_at FROM clients WHERE id = ?'
            );
            $stmt->execute([$id]);
        } else {
            if ($companyId <= 0) {
                json_out(['ok' => false, 'error' => 'not_found'], 404);
            }
            $stmt = db()->prepare(
                'SELECT id, name, phone, email, state, access_count, last_access_at FROM clients WHERE id = ? AND company_id = ?'
            );
            $stmt->execute([$id, $companyId]);
        }
    }
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
    $token = agent_request_token($body);
    $store = find_store_by_token($token);
    if (!$store) {
        json_out(['ok' => false, 'error' => 'token'], 401);
    }
    $cid = (int) ($store['company_id'] ?? 0);
    $camp = $cid > 0 ? active_campaign_for_hotspot($cid, (int) $store['id']) : null;
    json_out(['ok' => true, 'campaign' => $camp]);
}

if ($path === '/campaign/click' && $method === 'POST') {
    $token = agent_request_token($body);
    $store = find_store_by_token($token);
    if (!$store) {
        json_out(['ok' => false, 'error' => 'token'], 401);
    }
    $campaignId = (int) ($body['campaign_id'] ?? 0);
    $companyId = (int) ($store['company_id'] ?? 0);
    if ($campaignId <= 0 || $companyId <= 0) {
        json_out(['ok' => false, 'error' => 'invalid'], 400);
    }
    $stmt = db()->prepare('SELECT id FROM campaigns WHERE id = ? AND company_id = ? LIMIT 1');
    $stmt->execute([$campaignId, $companyId]);
    if (!$stmt->fetch()) {
        json_out(['ok' => false, 'error' => 'not_found'], 404);
    }
    record_campaign_click($campaignId, $companyId, null, null);
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'error' => 'not_found'], 404);
