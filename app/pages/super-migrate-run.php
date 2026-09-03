<?php

declare(strict_types=1);

require_super_admin();
csrf_verify();

$returnTo = trim((string) ($_POST['return_to'] ?? ''));
if ($returnTo === '' || !str_starts_with($returnTo, '/super')) {
    $returnTo = '/super/configuracoes/sistema';
}

try {
    $result = run_migrations(db(), true);
    if ($result['error']) {
        $_SESSION['flash_error'] = $result['error'];
    } elseif ($result['ran'] === []) {
        $_SESSION['flash_ok'] = 'Schema já está atualizado. Nenhuma migration pendente.';
    } else {
        $_SESSION['flash_ok'] = 'Migrations aplicadas: ' . implode(', ', $result['ran']);
    }
} catch (Throwable $e) {
    $_SESSION['flash_error'] = $e->getMessage();
}

header('Location: ' . $returnTo);
exit;
