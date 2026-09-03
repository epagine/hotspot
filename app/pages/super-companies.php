<?php

declare(strict_types=1);

require_super_admin();
require_post_csrf();
$do = (string) ($_POST['do'] ?? '');

if ($do === 'create') {
    try {
        $user = create_user([
            'name' => 'Admin',
            'email' => $_POST['admin_email'] ?? '',
            'password' => $_POST['admin_pass'] ?? '',
            'role' => 'company_admin',
        ]);
        create_company([
            'trade_name' => $_POST['trade_name'] ?? '',
            'legal_name' => $_POST['trade_name'] ?? '',
            'email' => $_POST['admin_email'] ?? '',
        ], (int) $user['id'], 'essencial');
        $_SESSION['flash_ok'] = 'Empresa criada com trial.';
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
}

if ($do === 'toggle') {
    $id = (int) ($_POST['id'] ?? 0);
    $c = find_company($id);
    if ($c) {
        set_company_status($id, ($c['status'] ?? '') === 'active' ? 'blocked' : 'active');
        $_SESSION['flash_ok'] = 'Status atualizado.';
    }
}

if ($do === 'impersonate') {
    $_SESSION['company_id'] = (int) ($_POST['id'] ?? 0);
    header('Location: /app');
    exit;
}

if ($do === 'attach_store') {
    try {
        $storeId = (int) ($_POST['store_id'] ?? 0);
        $companyId = (int) ($_POST['company_id'] ?? 0);
        $store = attach_store_to_company($storeId, $companyId);
        $_SESSION['flash_ok'] = 'Loja "' . (string) ($store['name'] ?? '') . '" vinculada à empresa.';
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
}

if ($do === 'promote_store') {
    try {
        $storeId = (int) ($_POST['store_id'] ?? 0);
        $company = promote_store_to_company($storeId, [
            'name' => $_POST['admin_name'] ?? 'Admin',
            'email' => $_POST['admin_email'] ?? '',
            'password' => $_POST['admin_pass'] ?? '',
        ], (string) ($_POST['plan_code'] ?? 'essencial'));
        $_SESSION['flash_ok'] = 'Loja promovida à empresa "' . (string) ($company['trade_name'] ?? '') . '".';
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
}

header('Location: /super/empresas');
exit;
