<?php

declare(strict_types=1);

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('conta'));
    exit;
}

try {
    $user = trim((string) ($_POST['admin_user'] ?? ''));
    if ($user !== '') {
        set_setting('admin_user', $user);
    }
    $newPass = (string) ($_POST['admin_pass'] ?? '');
    if ($newPass !== '') {
        set_setting('admin_pass_hash', password_hash($newPass, PASSWORD_DEFAULT));
    }
    $_SESSION['flash_ok'] = 'Conta do painel salva.';
} catch (Throwable $e) {
    $_SESSION['flash_error'] = $e->getMessage();
}
header('Location: ' . admin_url('conta'));
