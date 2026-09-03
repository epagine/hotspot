<?php

declare(strict_types=1);

require_super_admin();
require_post_csrf();

$returnTo = trim((string) ($_POST['return_to'] ?? ''));
if ($returnTo === '' || !str_starts_with($returnTo, '/super')) {
    $returnTo = '/super?tab=planos';
}

$id = (int) ($_POST['id'] ?? 0);
$priceRaw = (string) ($_POST['price_reais'] ?? '0');
$features = [];
foreach ((array) ($_POST['features'] ?? []) as $feature) {
    if (is_string($feature) && $feature !== '') {
        $features[] = $feature;
    }
}

try {
    if ($id > 0 && !find_plan($id)) {
        throw new InvalidArgumentException('Plano não encontrado.');
    }

    $planId = save_plan([
        'code' => (string) ($_POST['code'] ?? ''),
        'name' => (string) ($_POST['name'] ?? ''),
        'price_cents' => money_to_cents($priceRaw),
        'billing_period' => (string) ($_POST['billing_period'] ?? 'mensal'),
        'max_hotspots' => (int) ($_POST['max_hotspots'] ?? 1),
        'max_clients' => (int) ($_POST['max_clients'] ?? 0),
        'max_users' => (int) ($_POST['max_users'] ?? 2),
        'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        'active' => (string) ($_POST['active'] ?? '0') === '1',
        'features' => $features,
    ], $id > 0 ? $id : null);

    $_SESSION['flash_ok'] = $id > 0 ? 'Plano atualizado.' : 'Plano criado.';
} catch (Throwable $e) {
    $_SESSION['flash_error'] = $e->getMessage();
}

header('Location: ' . $returnTo);
exit;
