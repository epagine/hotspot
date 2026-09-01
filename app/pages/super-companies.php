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

header('Location: /super?tab=empresas');
exit;
