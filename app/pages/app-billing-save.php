<?php

declare(strict_types=1);

require_company_access('billing');
require_post_csrf();

$companyId = current_company_id();
$do = (string) ($_POST['do'] ?? '');

if ($do === 'plan') {
    $planId = (int) ($_POST['plan_id'] ?? 0);
    try {
        company_change_plan($companyId, $planId);
        $_SESSION['flash_ok'] = 'Plano atualizado.';
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
    header('Location: /app?tab=assinatura');
    exit;
}

if ($do === 'charge') {
    $planId = (int) ($_POST['plan_id'] ?? 0);
    try {
        $created = payment_create_company_charge($companyId, true, $planId > 0 ? $planId : null);
        $url = (string) ($created['pay_url'] ?? '');
        $_SESSION['flash_ok'] = $url !== ''
            ? 'Link de pagamento gerado. Conclua o pagamento para ativar sua assinatura.'
            : 'Cobrança criada.';
        if ($url !== '') {
            $_SESSION['flash_pay_url'] = $url;
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
    header('Location: /app?tab=assinatura');
    exit;
}

header('Location: /app?tab=assinatura');
exit;
