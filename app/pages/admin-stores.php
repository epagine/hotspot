<?php

declare(strict_types=1);

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('clientes'));
    exit;
}

$do = (string) ($_POST['do'] ?? 'create');

if ($do === 'create') {
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        $_SESSION['flash_error'] = 'Informe o nome do cliente.';
        header('Location: ' . admin_url('clientes'));
        exit;
    }
    $store = create_store_with_trial($name, trim((string) ($_POST['city'] ?? '')));
    $id = (int) $store['id'];
    $contact = trim((string) ($_POST['contact'] ?? ''));
    if ($contact !== '') {
        db()->prepare('UPDATE stores SET contact = ? WHERE id = ?')->execute([$contact, $id]);
    }
    select_store($id);
    $_SESSION['flash_ok'] = 'Cliente cadastrado. Copie o token na ficha e instale no PC da loja.';
    header('Location: ' . admin_url('clientes', $id));
    exit;
}

$id = (int) ($_POST['id'] ?? $GLOBALS['route_id'] ?? 0);
if ($do === 'rotate' && $id > 0) {
    rotate_store_token($id);
    $_SESSION['flash_ok'] = 'Token novo gerado. Atualize o PC da loja.';
    header('Location: ' . admin_url('clientes', $id));
    exit;
}

if ($do === 'save' && $id > 0) {
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        $_SESSION['flash_error'] = 'Informe o nome do cliente.';
        header('Location: ' . admin_url('clientes', $id));
        exit;
    }
    $bill = (string) ($_POST['billing_status'] ?? 'em_dia');
    if (!in_array($bill, ['em_dia', 'atrasado', 'cortesia', 'cancelado'], true)) {
        $bill = 'em_dia';
    }
    $plan = (string) ($_POST['plan'] ?? 'mensal');
    if (!in_array($plan, ['mensal', 'trimestral', 'anual'], true)) {
        $plan = 'mensal';
    }
    update_store_saas($id, [
        'name' => $name,
        'city' => trim((string) ($_POST['city'] ?? '')),
        'active' => (string) ($_POST['active'] ?? '1') === '1',
        'contact' => trim((string) ($_POST['contact'] ?? '')),
    ]);
    $_SESSION['flash_ok'] = 'Gestão do cliente salva.';
    header('Location: ' . admin_url('clientes', $id));
    exit;
}

header('Location: ' . admin_url('clientes'));
