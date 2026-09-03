<?php

declare(strict_types=1);

require_company_access('company');
require_post_csrf();

$companyId = current_company_id();
update_company($companyId, [
    'legal_name' => $_POST['legal_name'] ?? '',
    'trade_name' => $_POST['trade_name'] ?? '',
    'document' => $_POST['document'] ?? '',
    'phone' => $_POST['phone'] ?? '',
    'whatsapp' => $_POST['whatsapp'] ?? '',
    'email' => $_POST['email'] ?? '',
    'address' => $_POST['address'] ?? '',
    'city' => $_POST['city'] ?? '',
    'state' => $_POST['state'] ?? '',
    'primary_color' => $_POST['primary_color'] ?? '#c8892a',
    'secondary_color' => $_POST['secondary_color'] ?? '#15202b',
]);
$_SESSION['flash_ok'] = 'Dados da empresa salvos.';
header('Location: /app/empresa');
exit;
