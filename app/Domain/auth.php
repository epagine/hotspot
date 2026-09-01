<?php

declare(strict_types=1);

function permission_catalog(): array
{
    return [
        'dashboard' => 'Dashboard',
        'company' => 'Empresa',
        'hotspots' => 'Hotspots',
        'clients' => 'Clientes',
        'access' => 'Acessos',
        'campaigns' => 'Campanhas',
        'coupons' => 'Cupons',
        'reports' => 'Relatórios',
        'users' => 'Usuários',
        'billing' => 'Assinatura',
    ];
}

function role_default_permissions(string $role): array
{
    return match ($role) {
        'super_admin' => array_keys(permission_catalog()),
        'company_admin' => array_keys(permission_catalog()),
        'operator' => ['dashboard', 'hotspots', 'clients', 'access'],
        default => ['dashboard'],
    };
}

function current_user(): ?array
{
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    static $cache = [];
    if (isset($cache[$id])) {
        return $cache[$id];
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND status = ?');
    $stmt->execute([$id, 'active']);
    $row = $stmt->fetch();
    $cache[$id] = $row ?: null;
    return $cache[$id];
}

function current_company_id(): int
{
    if (!empty($GLOBALS['force_company_id'])) {
        return (int) $GLOBALS['force_company_id'];
    }
    $user = current_user();
    if ($user && ($user['role'] ?? '') === 'super_admin') {
        return (int) ($_SESSION['company_id'] ?? 0);
    }
    return (int) ($_SESSION['company_id'] ?? 0);
}

function current_company(): ?array
{
    $id = current_company_id();
    if ($id <= 0) {
        return null;
    }
    return find_company($id);
}

function user_permissions(?array $user = null, ?int $companyId = null): array
{
    $user = $user ?? current_user();
    if (!$user) {
        return [];
    }
    if (($user['role'] ?? '') === 'super_admin') {
        return array_keys(permission_catalog());
    }
    $companyId = $companyId ?? current_company_id();
    if ($companyId <= 0) {
        return role_default_permissions((string) ($user['role'] ?? 'operator'));
    }
    $stmt = db()->prepare('SELECT permissions FROM company_users WHERE user_id = ? AND company_id = ?');
    $stmt->execute([(int) $user['id'], $companyId]);
    $row = $stmt->fetch();
    if (!$row) {
        return role_default_permissions((string) ($user['role'] ?? 'operator'));
    }
    $perms = json_decode((string) ($row['permissions'] ?? '[]'), true);
    if (!is_array($perms) || $perms === []) {
        return role_default_permissions((string) ($user['role'] ?? 'operator'));
    }
    return array_values(array_unique(array_map('strval', $perms)));
}

function user_can(string $permission, ?array $user = null, ?int $companyId = null): bool
{
    return in_array($permission, user_permissions($user, $companyId), true);
}

function require_login(): void
{
    if (!current_user()) {
        // legacy admin bridge
        if (!empty($_SESSION['admin'])) {
            ensure_legacy_admin_user();
            if (current_user()) {
                return;
            }
        }
        header('Location: /entrar');
        exit;
    }
}

function require_super_admin(): void
{
    require_login();
    $user = current_user();
    if (!$user || ($user['role'] ?? '') !== 'super_admin') {
        http_response_code(403);
        echo 'Acesso restrito ao Super Admin.';
        exit;
    }
}

function require_company_access(string $permission = 'dashboard'): void
{
    require_login();
    $user = current_user();
    if (($user['role'] ?? '') === 'super_admin') {
        return;
    }
    if (current_company_id() <= 0 || !user_can($permission, $user)) {
        http_response_code(403);
        echo 'Você não tem permissão para este módulo.';
        exit;
    }
}

function auth_attempt(string $email, string $password): ?array
{
    $email = strtolower(trim($email));
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || ($user['status'] ?? '') !== 'active') {
        return null;
    }
    if (!password_verify($password, (string) ($user['pass_hash'] ?? ''))) {
        return null;
    }
    return $user;
}

function auth_login(array $user, ?int $companyId = null): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['admin'] = ($user['role'] ?? '') === 'super_admin' || ($user['role'] ?? '') === 'company_admin';
    if ($companyId !== null && $companyId > 0) {
        $_SESSION['company_id'] = $companyId;
    } elseif (($user['role'] ?? '') !== 'super_admin') {
        $stmt = db()->prepare('SELECT company_id FROM company_users WHERE user_id = ? ORDER BY id ASC LIMIT 1');
        $stmt->execute([(int) $user['id']]);
        $row = $stmt->fetch();
        $_SESSION['company_id'] = (int) ($row['company_id'] ?? 0);
    }
    audit_log('login', (int) ($_SESSION['company_id'] ?? 0) ?: null, (int) $user['id']);
}

function auth_logout(): void
{
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    $cid = (int) ($_SESSION['company_id'] ?? 0) ?: null;
    if ($uid > 0) {
        audit_log('logout', $cid, $uid);
    }
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function ensure_legacy_admin_user(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $email = strtolower(trim(setting('admin_user', 'admin')));
        if (!str_contains($email, '@')) {
            $email = $email . '@wifidaloja.local';
        }
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? OR role = ? LIMIT 1');
        $stmt->execute([$email, 'super_admin']);
        $user = $stmt->fetch();
        if ($user) {
            $_SESSION['user_id'] = (int) $user['id'];
            if (empty($_SESSION['company_id'])) {
                bootstrap_default_company_for_legacy((int) $user['id']);
            }
            return;
        }
        $hash = setting('admin_pass_hash', '');
        if ($hash === '') {
            $hash = password_hash('admin123', PASSWORD_DEFAULT);
        }
        $now = date('Y-m-d H:i:s');
        db()->prepare(
            'INSERT INTO users (name, email, pass_hash, role, status, created_at) VALUES (?,?,?,?,?,?)'
        )->execute(['Administrador', $email, $hash, 'super_admin', 'active', $now]);
        $uid = (int) db()->lastInsertId();
        $_SESSION['user_id'] = $uid;
        bootstrap_default_company_for_legacy($uid);
    } catch (Throwable $e) {
        // tables may not exist yet during early boot
    }
}

function bootstrap_default_company_for_legacy(int $userId): void
{
    $company = db()->query('SELECT * FROM companies ORDER BY id ASC LIMIT 1')->fetch();
    if (!$company) {
        $now = date('Y-m-d H:i:s');
        $name = setting('store_name', 'Minha empresa');
        db()->prepare(
            'INSERT INTO companies (legal_name, trade_name, email, city, status, created_at)
             VALUES (?,?,?,?,?,?)'
        )->execute([$name, $name, setting('admin_user', 'admin') . '@wifidaloja.local', '', 'active', $now]);
        $companyId = (int) db()->lastInsertId();
        $plan = db()->query("SELECT id FROM plans WHERE code = 'profissional' LIMIT 1")->fetch();
        $planId = (int) ($plan['id'] ?? 1);
        $trialEnds = date('Y-m-d', strtotime('+14 days') ?: time());
        db()->prepare(
            'INSERT INTO subscriptions (company_id, plan_id, status, trial_ends_at, starts_at, ends_at, created_at)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$companyId, $planId, 'trial', $trialEnds, $now, $trialEnds, $now]);
        $company = find_company($companyId);
    }
    $companyId = (int) $company['id'];
    $link = db()->prepare('SELECT id FROM company_users WHERE company_id = ? AND user_id = ?');
    $link->execute([$companyId, $userId]);
    if (!$link->fetch()) {
        db()->prepare(
            'INSERT INTO company_users (company_id, user_id, permissions, created_at) VALUES (?,?,?,?)'
        )->execute([
            $companyId,
            $userId,
            json_encode(array_keys(permission_catalog())),
            date('Y-m-d H:i:s'),
        ]);
    }
    db()->prepare('UPDATE stores SET company_id = ? WHERE company_id IS NULL OR company_id = 0')->execute([$companyId]);
    $_SESSION['company_id'] = $companyId;
}
