<?php

declare(strict_types=1);

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin');
    exit;
}

$tab = 'config';
$sid = (int) ($_POST['store_id'] ?? 0);
if ($sid > 0) {
    select_store($sid);
}
try {
    foreach (['store_name', 'store_city', 'wifi_ssid', 'wifi_pass', 'portal_ip', 'session_hours', 'approval_mode', 'status_template'] as $key) {
        if (isset($_POST[$key])) {
            set_setting($key, trim((string) $_POST[$key]));
        }
    }
    if (!empty($_POST['remove_brand'])) {
        delete_brand_image();
    }
    save_brand_upload($_FILES['brand_image'] ?? null);
    $_SESSION['flash_ok'] = 'Configurações salvas.';
} catch (Throwable $e) {
    $_SESSION['flash_error'] = $e->getMessage();
    header('Location: /admin?tab=' . $tab . '&store=' . current_store_id());
    exit;
}

$user = trim((string) ($_POST['admin_user'] ?? ''));
if ($user !== '') {
    set_setting('admin_user', $user);
}
$newPass = (string) ($_POST['admin_pass'] ?? '');
if ($newPass !== '') {
    set_setting('admin_pass_hash', password_hash($newPass, PASSWORD_DEFAULT));
}

sync_authorized_file();
if (isset($_POST['wifi_ssid']) || isset($_POST['wifi_pass'])) {
    queue_store_command(current_store_id(), 'apply');
}
header('Location: /admin?tab=' . $tab . '&store=' . current_store_id());
