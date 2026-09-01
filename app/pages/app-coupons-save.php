<?php

declare(strict_types=1);

require_company_access('coupons');
require_post_csrf();
$companyId = current_company_id();
create_coupon($companyId, [
    'code' => $_POST['code'] ?? '',
    'title' => $_POST['title'] ?? '',
    'description' => $_POST['description'] ?? '',
    'valid_until' => $_POST['valid_until'] ?? '',
]);
$_SESSION['flash_ok'] = 'Cupom criado.';
header('Location: /app?tab=cupons');
exit;
