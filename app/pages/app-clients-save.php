<?php

declare(strict_types=1);

require_company_access('clientes');
require_post_csrf();

$companyId = current_company_id();
$do = (string) ($_POST['do'] ?? '');
$clientId = (int) ($_POST['client_id'] ?? 0);

if ($do !== 'approve' || $clientId <= 0) {
    $_SESSION['flash_error'] = 'Ação inválida.';
    header('Location: /app/clientes');
    exit;
}

$stmt = db()->prepare('SELECT * FROM clients WHERE id = ? AND company_id = ?');
$stmt->execute([$clientId, $companyId]);
$client = $stmt->fetch();
if (!$client) {
    $_SESSION['flash_error'] = 'Cliente não encontrado.';
    header('Location: /app/clientes');
    exit;
}

$hotspotId = (int) ($client['store_id'] ?? 0);
$store = find_store($hotspotId);
if (!$store || (int) ($store['company_id'] ?? 0) !== $companyId) {
    $_SESSION['flash_error'] = 'Hotspot não encontrado.';
    header('Location: /app/clientes');
    exit;
}

try {
    portal_approve_guest($hotspotId, $clientId, $companyId);
    audit_log('client.approve', $companyId, null, ['client_id' => $clientId, 'hotspot_id' => $hotspotId]);
    $_SESSION['flash_ok'] = 'Cliente aprovado. O Wi-Fi será liberado em alguns segundos.';
} catch (RuntimeException $e) {
    $_SESSION['flash_error'] = $e->getMessage();
}

header('Location: /app/clientes');
exit;
