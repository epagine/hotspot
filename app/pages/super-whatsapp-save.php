<?php

declare(strict_types=1);

require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /super?tab=configuracoes&sec=whatsapp');
    exit;
}

$returnTo = trim((string) ($_POST['return_to'] ?? ''));
$redirect = static function () use ($returnTo): void {
    header('Location: ' . ($returnTo !== '' ? $returnTo : '/super?tab=configuracoes&sec=whatsapp'));
    exit;
};

$do = (string) ($_POST['do'] ?? 'save');

if ($do === 'save') {
    $enabled = (string) ($_POST['evolution_enabled'] ?? '0') === '1' ? '1' : '0';
    set_setting('evolution_enabled', $enabled);

    $baseUrl = rtrim(trim((string) ($_POST['evolution_base_url'] ?? '')), '/');
    if ($baseUrl !== '') {
        set_setting('evolution_base_url', $baseUrl);
    }

    $instance = trim((string) ($_POST['evolution_instance'] ?? ''));
    if ($instance !== '') {
        set_setting('evolution_instance', $instance);
    }

    $apiKey = trim((string) ($_POST['evolution_api_key'] ?? ''));
    if ($apiKey !== '') {
        set_setting('evolution_api_key', $apiKey);
    }

    foreach (array_keys(notification_events()) as $event) {
        $on = (string) ($_POST['notify_on_' . $event] ?? '0') === '1' ? '1' : '0';
        set_setting('notify_on_' . $event, $on);
        $tpl = trim((string) ($_POST['notify_tpl_' . $event] ?? ''));
        set_setting('notify_tpl_' . $event, $tpl);
    }
    $reminderDays = (int) ($_POST['notify_trial_reminder_days'] ?? 3);
    set_setting('notify_trial_reminder_days', (string) max(1, min(14, $reminderDays)));

    if ($enabled === '1' && !evolution_configured()) {
        $_SESSION['flash_error'] = 'Informe URL da API, API key e nome da instância.';
        $redirect();
    }
    $_SESSION['flash_ok'] = 'WhatsApp (Evolution API) e mensagens salvos.';
    $redirect();
}

if ($do === 'test') {
    $phone = trim((string) ($_POST['test_phone'] ?? ''));
    $r = evolution_test_credentials($phone !== '' ? $phone : null);
    if ($r['ok']) {
        $_SESSION['flash_ok'] = $r['message'];
    } else {
        $_SESSION['flash_error'] = $r['message'];
    }
    $redirect();
}

header('Location: /super?tab=configuracoes&sec=whatsapp');
exit;
