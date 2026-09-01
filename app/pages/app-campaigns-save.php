<?php

declare(strict_types=1);

require_company_access('campaigns');
require_post_csrf();
$companyId = current_company_id();
try {
    if (!company_has_feature($companyId, 'campaigns')) {
        throw new RuntimeException(company_feature_error('campaigns'));
    }
    save_campaign($companyId, [
        'name' => $_POST['name'] ?? '',
        'type' => $_POST['type'] ?? 'banner',
        'title' => $_POST['title'] ?? '',
        'description' => $_POST['description'] ?? '',
        'button_label' => $_POST['button_label'] ?? '',
        'button_url' => $_POST['button_url'] ?? '',
        'starts_at' => $_POST['starts_at'] ?? '',
        'ends_at' => $_POST['ends_at'] ?? '',
        'status' => 'ativa',
    ]);
    $_SESSION['flash_ok'] = 'Campanha salva.';
} catch (Throwable $e) {
    $_SESSION['flash_error'] = $e->getMessage();
}
header('Location: /app?tab=campanhas');
exit;
