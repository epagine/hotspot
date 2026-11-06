<?php

declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'method'], 405);
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    json_out(['ok' => false, 'error' => 'json'], 400);
}

$token = agent_request_token();
$store = find_store_by_agent_token($token);
if (!$store) {
    json_out(['ok' => false, 'error' => 'token'], 401);
}

$sid = (int) $store['id'];
$GLOBALS['force_store_id'] = $sid;

subscription_sync_contract($sid);
$store = find_store($sid) ?? $store;

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
$companyName = '';
if ($companyId > 0) {
    $company = find_company($companyId);
    $companyName = trim((string) ($company['trade_name'] ?? ''));
}
$appHotspotUrl = $panelBase . '/app/hotspots/' . $sid;
$policy = agent_policy_for_store($store);
$cfg = [
    'store_name' => trim((string) ($store['name'] ?? '')) !== ''
        ? (string) $store['name']
        : setting('store_name'),
    'store_city' => trim((string) ($store['city'] ?? '')) !== ''
        ? (string) $store['city']
        : setting('store_city'),
    'wifi_ssid' => setting('wifi_ssid', 'WifiDaLoja'),
    'wifi_pass' => setting('wifi_pass', ''),
    'wifi_adapter_guid' => setting_for_store($sid, 'wifi_adapter_guid', ''),
    'wifi_isolate_others' => setting_for_store($sid, 'wifi_isolate_others', '1'),
    'portal_ip' => setting('portal_ip', '192.168.137.1'),
    'dns_allowlist' => dns_allowlist_for_agent($sid),
    'status_template' => setting('status_template'),
    'max_clients' => $policy['max_clients'],
    'session_hours' => $policy['session_hours'],
    'approval_mode' => $policy['approval_mode'],
    'service_allowed' => $policy['service_allowed'],
    'hotspot_enabled' => $policy['hotspot_enabled'],
    'accept_new_clients' => $policy['accept_new_clients'],
    'require_status_share' => $policy['require_status_share'],
    'personalized_portal' => $policy['personalized_portal'],
    'portal_title' => $policy['portal_title'],
    'portal_subtitle' => $policy['portal_subtitle'],
];

$portalIp = (string) ($cfg['portal_ip'] ?? '192.168.137.1');
$revoke = [];
foreach (pending_client_patches($sid) as $row) {
    $ip = trim((string) ($row['ip'] ?? ''));
    if ($ip !== '') {
        $revoke[] = $ip;
    }
}

json_out([
    'ok' => true,
    'store_id' => $sid,
    'store' => (string) $store['name'],
    'portal_token' => (string) ($store['token'] ?? ''),
    'company_id' => $companyId > 0 ? $companyId : null,
    'company_name' => $companyName !== '' ? $companyName : null,
    'hotspot_status' => (string) ($store['hotspot_status'] ?? 'ativo'),
    'connection_owner' => 'agent',
    'policy' => $policy,
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
        'portal' => 'http://' . $portalIp . '/',
    ],
    'command' => $command,
    'authorized' => $policy['allow_ips'],
    'allow_ips' => $policy['allow_ips'],
    'revoke_ips' => array_values(array_unique($revoke)),
    'patches' => pending_client_patches($sid),
    'has_brand' => is_file(brand_image_path_for($sid)),
]);
