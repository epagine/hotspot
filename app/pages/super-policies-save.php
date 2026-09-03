<?php

declare(strict_types=1);

require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /super/configuracoes/politicas');
    exit;
}

require_post_csrf();

set_setting('saas_grace_days', (string) max(0, min(30, (int) ($_POST['saas_grace_days'] ?? 3))));
set_setting('saas_trial_days', (string) max(0, min(90, (int) ($_POST['saas_trial_days'] ?? 7))));
set_setting('saas_auto_suspend', (string) ($_POST['saas_auto_suspend'] ?? '0') === '1' ? '1' : '0');
$_SESSION['flash_ok'] = 'Políticas SaaS salvas.';
audit_log('saas.policies.save', null, null, []);
$returnTo = trim((string) ($_POST['return_to'] ?? ''));
safe_internal_redirect($returnTo, '/super/configuracoes/politicas');
