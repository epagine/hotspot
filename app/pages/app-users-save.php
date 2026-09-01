<?php

declare(strict_types=1);

require_company_access('users');
require_post_csrf();

$companyId = current_company_id();
try {
    if (!company_within_user_limit($companyId)) {
        throw new RuntimeException(company_limit_error('users'));
    }
    $role = (string) ($_POST['role'] ?? 'operator');
    $user = create_user([
        'name' => $_POST['name'] ?? '',
        'email' => $_POST['email'] ?? '',
        'password' => $_POST['password'] ?? '',
        'role' => $role === 'company_admin' ? 'company_admin' : 'operator',
    ]);
    attach_user_to_company((int) $user['id'], $companyId, role_default_permissions((string) $user['role']));
    $_SESSION['flash_ok'] = 'Usuário criado.';
} catch (Throwable $e) {
    $_SESSION['flash_error'] = $e->getMessage();
}
header('Location: /app?tab=usuarios');
exit;
