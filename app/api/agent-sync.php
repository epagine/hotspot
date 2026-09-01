<?php

declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'method'], 405);
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    json_out(['ok' => false, 'error' => 'json'], 400);
}

$token = trim((string) ($body['token'] ?? ''));
$store = find_store_by_token($token);
if (!$store) {
    json_out(['ok' => false, 'error' => 'token'], 401);
}

$sid = (int) $store['id'];
$GLOBALS['force_store_id'] = $sid;

$status = $body['status'] ?? [];
if (is_array($status)) {
    save_store_heartbeat($sid, $status);
}

$clients = $body['clients'] ?? null;
if (is_array($clients)) {
    upsert_synced_clients($sid, $clients);
}

if (!empty($body['ack_command_id'])) {
    ack_store_command($sid, (string) $body['ack_command_id']);
}

$command = peek_store_command($sid);
$panelBase = rtrim(guess_panel_url(), '/');
$subPayload = store_subscription_payload($store);
$companyId = (int) ($store['company_id'] ?? 0);
$appHotspotUrl = $companyId > 0
    ? $panelBase . '/app?tab=hotspots&id=' . $sid
    : $panelBase . '/admin/clientes/' . $sid;
$cfg = [
    'store_name' => trim((string) ($store['name'] ?? '')) !== ''
        ? (string) $store['name']
        : setting('store_name'),
    'store_city' => trim((string) ($store['city'] ?? '')) !== ''
        ? (string) $store['city']
        : setting('store_city'),
    'wifi_ssid' => setting('wifi_ssid', 'WifiDaLoja'),
    'wifi_pass' => setting('wifi_pass', ''),
    'portal_ip' => setting('portal_ip', '192.168.137.1'),
    'max_clients' => setting('max_clients', '8'),
    'dns_allowlist' => setting('dns_allowlist', default_dns_allowlist()),
    'session_hours' => setting('session_hours', '2'),
    'approval_mode' => setting('approval_mode', 'instant'),
    'status_template' => setting('status_template'),
];

$stmt = db()->prepare(
    "SELECT ip FROM clients WHERE store_id = ? AND state = 'online'"
);
$stmt->execute([$sid]);
$authorized = [];
foreach ($stmt as $row) {
    $authorized[] = $row['ip'];
}

json_out([
    'ok' => true,
    'store_id' => $sid,
    'store' => (string) $store['name'],
    'company_id' => $companyId > 0 ? $companyId : null,
    'config' => $cfg,
    'subscription' => [
        'scope' => $subPayload['scope'],
        'billing_status' => $subPayload['billing_status'],
        'billing_label' => $subPayload['billing_label'],
        'plan' => $subPayload['plan'],
        'plan_label' => $subPayload['plan_label'],
        'paid_until' => $subPayload['paid_until'],
        'trial_ends_at' => $subPayload['trial_ends_at'],
        'cycle_amount' => $subPayload['cycle_amount'],
        'active' => $subPayload['active'],
        'service_allowed' => $subPayload['service_allowed'],
    ],
    'links' => [
        'panel' => $panelBase,
        'admin' => $appHotspotUrl,
        'client' => $panelBase . client_url(),
        'portal' => $panelBase . '/portal/' . rawurlencode((string) $store['token']),
    ],
    'command' => $command,
    'authorized' => array_values(array_unique($authorized)),
    'patches' => pending_client_patches($sid),
    'has_brand' => is_file(brand_image_path_for($sid)),
]);
