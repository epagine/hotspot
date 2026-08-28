<?php

declare(strict_types=1);

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? 'create');
    if ($do === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $_SESSION['flash_error'] = 'Informe o nome da loja.';
            header('Location: /admin?tab=lojas');
            exit;
        }
        $store = create_store($name, trim((string) ($_POST['city'] ?? '')));
        select_store((int) $store['id']);
        $_SESSION['flash_ok'] = 'Loja criada. Copie o token para o PC dela.';
        header('Location: /admin?tab=lojas');
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    if ($do === 'rotate' && $id > 0) {
        rotate_store_token($id);
        $_SESSION['flash_ok'] = 'Token novo gerado. Atualize o PC da loja.';
    }
    if ($do === 'select' && $id > 0) {
        select_store($id);
        header('Location: /admin?tab=operacao&store=' . $id);
        exit;
    }
    header('Location: /admin?tab=lojas');
    exit;
}

header('Location: /admin?tab=lojas');
