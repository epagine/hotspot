<?php

declare(strict_types=1);

function ensure_portal_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $cols = array_column($pdo->query('PRAGMA table_info(stores)')->fetchAll(), 'name');
    $add = [
        'portal_email' => "TEXT NOT NULL DEFAULT ''",
        'portal_pass_hash' => "TEXT NOT NULL DEFAULT ''",
        'portal_enabled' => 'INTEGER NOT NULL DEFAULT 0',
    ];
    foreach ($add as $col => $def) {
        if (!in_array($col, $cols, true)) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN {$col} {$def}");
        }
    }
}

function portal_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function client_url(string $section = 'painel'): string
{
    return match ($section) {
        'entrar', 'login' => '/cliente/entrar',
        'sair', 'logout' => '/cliente/sair',
        'conta' => '/cliente/conta',
        default => '/cliente',
    };
}

function client_redirect(string $url, int $code = 302): void
{
    header('Location: ' . $url, true, $code);
    exit;
}

function current_client_store_id(): int
{
    return (int) ($_SESSION['client_store_id'] ?? 0);
}

function current_client_store(): ?array
{
    $id = current_client_store_id();
    if ($id <= 0) {
        return null;
    }
    $store = find_store($id);
    if (!$store || (int) ($store['portal_enabled'] ?? 0) !== 1) {
        return null;
    }
    return $store;
}

function require_client_login(): void
{
    if (current_client_store() === null) {
        unset($_SESSION['client_store_id']);
        client_redirect(client_url('entrar'));
    }
}

function find_store_by_portal_email(string $email): ?array
{
    $email = portal_normalize_email($email);
    if ($email === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM stores WHERE portal_email = ? COLLATE NOCASE LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function portal_credentials_ready(array $store): bool
{
    return (int) ($store['portal_enabled'] ?? 0) === 1
        && portal_normalize_email((string) ($store['portal_email'] ?? '')) !== ''
        && trim((string) ($store['portal_pass_hash'] ?? '')) !== '';
}

function portal_try_login(string $email, string $password): ?array
{
    $store = find_store_by_portal_email($email);
    if (!$store || !portal_credentials_ready($store)) {
        return null;
    }
    $hash = (string) ($store['portal_pass_hash'] ?? '');
    if ($hash === '' || !password_verify($password, $hash)) {
        return null;
    }
    return $store;
}

function portal_update(int $storeId, array $fields): void
{
    $store = find_store($storeId);
    if (!$store) {
        throw new RuntimeException('Cliente não encontrado.');
    }
    $enabled = !empty($fields['enabled']);
    $email = portal_normalize_email((string) ($fields['email'] ?? ''));
    $pass = (string) ($fields['password'] ?? '');

    if ($enabled && $email === '') {
        throw new RuntimeException('Informe o e-mail de acesso ao portal.');
    }
    if ($enabled && $pass === '' && trim((string) ($store['portal_pass_hash'] ?? '')) === '') {
        throw new RuntimeException('Defina uma senha para habilitar o portal do cliente.');
    }

    $hash = trim((string) ($store['portal_pass_hash'] ?? ''));
    if ($pass !== '') {
        if (strlen($pass) < 8) {
            throw new RuntimeException('A senha do portal deve ter no mínimo 8 caracteres.');
        }
        $hash = password_hash($pass, PASSWORD_DEFAULT);
    }

    db()->prepare(
        'UPDATE stores SET portal_enabled = ?, portal_email = ?, portal_pass_hash = ? WHERE id = ?'
    )->execute([
        $enabled ? 1 : 0,
        $email,
        $hash,
        $storeId,
    ]);
}

function portal_update_password(int $storeId, string $current, string $next): void
{
    $store = find_store($storeId);
    if (!$store || !portal_credentials_ready($store)) {
        throw new RuntimeException('Acesso indisponível.');
    }
    $hash = (string) ($store['portal_pass_hash'] ?? '');
    if (!password_verify($current, $hash)) {
        throw new RuntimeException('Senha atual incorreta.');
    }
    if (strlen($next) < 8) {
        throw new RuntimeException('A nova senha deve ter no mínimo 8 caracteres.');
    }
    db()->prepare('UPDATE stores SET portal_pass_hash = ? WHERE id = ?')->execute([
        password_hash($next, PASSWORD_DEFAULT),
        $storeId,
    ]);
}

function portal_pending_payment(array $payments): ?array
{
    foreach ($payments as $p) {
        if (($p['status'] ?? '') !== 'paid' && trim((string) ($p['pay_url'] ?? '')) !== '') {
            return $p;
        }
    }
    return null;
}

function portal_can_request_charge(array $store): bool
{
    if (!pagseguro_configured()) {
        return false;
    }
    $status = normalize_subscription_status((string) ($store['billing_status'] ?? ''));
    if (in_array($status, ['cancelada', 'encerrada', 'cortesia'], true)) {
        return false;
    }
    if (store_pending_payment((int) $store['id'])) {
        return false;
    }
    return money_to_cents((string) ($store['monthly_fee'] ?? '')) >= 100;
}
