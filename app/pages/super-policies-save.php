<?php

declare(strict_types=1);

require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /super?tab=configuracoes&sec=politicas');
    exit;
}

set_setting('saas_grace_days', (string) max(0, min(30, (int) ($_POST['saas_grace_days'] ?? 3))));
set_setting('saas_trial_days', (string) max(0, min(90, (int) ($_POST['saas_trial_days'] ?? 7))));
set_setting('saas_auto_suspend', (string) ($_POST['saas_auto_suspend'] ?? '0') === '1' ? '1' : '0');
$_SESSION['flash_ok'] = 'Políticas SaaS salvas.';
$returnTo = trim((string) ($_POST['return_to'] ?? ''));
header('Location: ' . ($returnTo !== '' ? $returnTo : '/super?tab=configuracoes&sec=politicas'));
exit;
