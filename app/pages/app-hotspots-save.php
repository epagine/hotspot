<?php

declare(strict_types=1);

require_company_access('hotspots');
require_post_csrf();

$companyId = current_company_id();
$do = (string) ($_POST['do'] ?? 'save');

if ($do === 'create') {
    if (!company_within_hotspot_limit($companyId)) {
        $_SESSION['flash_error'] = company_limit_error('hotspots');
        header('Location: /app/hotspots');
        exit;
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        $_SESSION['flash_error'] = 'Informe o nome do hotspot.';
        header('Location: /app/hotspots?novo=1');
        exit;
    }
    $store = create_store($name, trim((string) ($_POST['city'] ?? '')));
    $id = (int) $store['id'];
    db()->prepare('UPDATE stores SET provider = ?, hotspot_status = ? WHERE id = ?')
        ->execute(['windows', 'ativo', $id]);
    $ssid = trim((string) ($_POST['ssid'] ?? 'WifiDaLoja'));
    set_setting_for_store($id, 'wifi_ssid', $ssid !== '' ? $ssid : 'WifiDaLoja');
    set_setting_for_store($id, 'store_name', $name);
    save_portal_config($id, [
        'title' => 'Bem-vindo à ' . $name,
        'subtitle' => 'Conecte-se gratuitamente ao Wi-Fi',
        'button_label' => 'Continuar',
    ]);
    audit_log('hotspot.create', $companyId, null, ['id' => $id]);
    $_SESSION['flash_ok'] = 'Hotspot criado.';
    header('Location: /app/hotspots/' . $id);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$store = find_store($id);
if (!$store || (int) ($store['company_id'] ?? 0) !== $companyId) {
    $_SESSION['flash_error'] = 'Hotspot não encontrado.';
    header('Location: /app/hotspots');
    exit;
}

if ($do === 'rotate') {
    rotate_store_token($id);
    audit_log('hotspot.rotate_token', $companyId, null, ['id' => $id]);
    $_SESSION['flash_ok'] = 'Token do agente renovado. Revincule o PC em Agente Windows ou na bandeja.';
    header('Location: /app/hotspots/' . $id);
    exit;
}

$oldSsid = setting_for_store($id, 'wifi_ssid', 'WifiDaLoja');
$oldPass = setting_for_store($id, 'wifi_pass', '');
$oldStatus = (string) ($store['hotspot_status'] ?? 'ativo');
$newStatus = (string) ($_POST['hotspot_status'] ?? 'ativo');
$newProvider = (string) ($_POST['provider'] ?? $store['provider'] ?? 'windows');

db()->prepare(
    'UPDATE stores SET name=?, description=?, location=?, provider=?, hotspot_status=?, terms_html=?, privacy_html=? WHERE id=?'
)->execute([
    trim((string) ($_POST['name'] ?? $store['name'])),
    trim((string) ($_POST['description'] ?? '')),
    trim((string) ($_POST['location'] ?? '')),
    (string) ($_POST['provider'] ?? 'windows'),
    (string) ($_POST['hotspot_status'] ?? 'ativo'),
    trim((string) ($_POST['terms_html'] ?? '')),
    trim((string) ($_POST['privacy_html'] ?? '')),
    $id,
]);
set_setting_for_store($id, 'wifi_ssid', trim((string) ($_POST['ssid'] ?? 'WifiDaLoja')));
$wifiPass = trim((string) ($_POST['wifi_pass'] ?? ''));
$newSsid = setting_for_store($id, 'wifi_ssid', 'WifiDaLoja');
if ($wifiPass !== '') {
    set_setting_for_store($id, 'wifi_pass', $wifiPass);
}
set_setting_for_store($id, 'store_name', trim((string) ($_POST['name'] ?? $store['name'])));
save_portal_config($id, [
    'title' => $_POST['portal_title'] ?? 'Bem-vindo',
    'subtitle' => $_POST['portal_subtitle'] ?? '',
    'button_label' => $_POST['portal_button'] ?? 'Continuar',
    'require_name' => 1,
    'require_phone' => 1,
    'require_terms' => 1,
]);
$approval = (string) ($_POST['approval_mode'] ?? 'instant');
if (!in_array($approval, ['instant', 'manual'], true)) {
    $approval = 'instant';
}
set_setting_for_store($id, 'approval_mode', $approval);
$template = trim((string) ($_POST['status_template'] ?? ''));
if ($template !== '') {
    set_setting_for_store($id, 'status_template', $template);
}
$hours = max(1, min(24, (int) ($_POST['session_hours'] ?? 2)));
set_setting_for_store($id, 'session_hours', (string) $hours);
$oldAdapterGuid = setting_for_store($id, 'wifi_adapter_guid', '');
$newAdapterGuid = trim((string) ($_POST['wifi_adapter_guid'] ?? ''));
set_setting_for_store($id, 'wifi_adapter_guid', $newAdapterGuid);
set_setting_for_store($id, 'wifi_isolate_others', '1');
$wifiChanged = $newSsid !== $oldSsid || ($wifiPass !== '' && $wifiPass !== $oldPass);
$adapterChanged = $newAdapterGuid !== $oldAdapterGuid;
if (($wifiChanged || $adapterChanged) && $newProvider === 'windows') {
    queue_store_command($id, 'apply');
}
if ($newStatus !== $oldStatus && $newProvider === 'windows') {
    if ($newStatus === 'ativo') {
        $updated = find_store($id);
        if ($updated !== null && portal_access_allowed($updated)) {
            queue_store_command($id, 'start');
        }
    } else {
        queue_store_command($id, 'stop');
    }
}
audit_log('hotspot.update', $companyId, null, ['id' => $id]);
$_SESSION['flash_ok'] = 'Hotspot atualizado.';
header('Location: /app/hotspots/' . $id);
exit;
