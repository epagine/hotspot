<?php

declare(strict_types=1);

require_super_admin();
require_post_csrf();
$code = trim((string) ($_POST['code'] ?? ''));
$existing = find_plan_by_code($code);
save_plan([
    'code' => $code,
    'name' => $_POST['name'] ?? $code,
    'price_cents' => (int) ($_POST['price_cents'] ?? 0),
    'max_hotspots' => (int) ($_POST['max_hotspots'] ?? 1),
    'max_clients' => (int) ($_POST['max_clients'] ?? 0),
    'max_users' => 5,
    'active' => !empty($_POST['active']),
    'features' => ['stats', 'portal', 'campaigns'],
], $existing ? (int) $existing['id'] : null);
$_SESSION['flash_ok'] = 'Plano salvo.';
header('Location: /super?tab=planos');
exit;
