<?php

declare(strict_types=1);

function find_user(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_user_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function create_user(array $data): array
{
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Informe um e-mail válido.');
    }
    if (find_user_by_email($email)) {
        throw new RuntimeException('Já existe um usuário com este e-mail.');
    }
    $pass = (string) ($data['password'] ?? '');
    if (strlen($pass) < 8) {
        throw new RuntimeException('A senha deve ter no mínimo 8 caracteres.');
    }
    $role = (string) ($data['role'] ?? 'operator');
    if (!in_array($role, ['super_admin', 'company_admin', 'operator'], true)) {
        $role = 'operator';
    }
    if ($role === 'super_admin') {
        $caller = current_user();
        if (!$caller || ($caller['role'] ?? '') !== 'super_admin') {
            $role = 'company_admin';
        }
    }
    $now = date('Y-m-d H:i:s');
    db()->prepare(
        'INSERT INTO users (name, email, pass_hash, role, status, created_at) VALUES (?,?,?,?,?,?)'
    )->execute([
        trim((string) ($data['name'] ?? '')),
        $email,
        password_hash($pass, PASSWORD_DEFAULT),
        $role,
        'active',
        $now,
    ]);
    $id = (int) db()->lastInsertId();
    audit_log('user.create', null, null, ['user_id' => $id, 'email' => $email, 'role' => $role]);
    return find_user($id) ?? ['id' => $id, 'email' => $email];
}

function attach_user_to_company(int $userId, int $companyId, array $permissions = []): void
{
    if ($permissions === []) {
        $user = find_user($userId);
        $permissions = role_default_permissions((string) ($user['role'] ?? 'operator'));
    }
    $stmt = db()->prepare('SELECT id FROM company_users WHERE user_id = ? AND company_id = ?');
    $stmt->execute([$userId, $companyId]);
    if ($stmt->fetch()) {
        db()->prepare('UPDATE company_users SET permissions = ? WHERE user_id = ? AND company_id = ?')->execute([
            json_encode(array_values($permissions)),
            $userId,
            $companyId,
        ]);
        return;
    }
    db()->prepare(
        'INSERT INTO company_users (company_id, user_id, permissions, created_at) VALUES (?,?,?,?)'
    )->execute([$companyId, $userId, json_encode(array_values($permissions)), date('Y-m-d H:i:s')]);
}

function company_users(int $companyId): array
{
    $stmt = db()->prepare(
        'SELECT u.*, cu.permissions
         FROM company_users cu
         INNER JOIN users u ON u.id = cu.user_id
         WHERE cu.company_id = ?
         ORDER BY u.name ASC'
    );
    $stmt->execute([$companyId]);
    return $stmt->fetchAll() ?: [];
}

function platform_users(): array
{
    return db()->query('SELECT * FROM users ORDER BY id DESC')->fetchAll() ?: [];
}

function update_user_password(int $userId, string $current, string $next): void
{
    $user = find_user($userId);
    if (!$user) {
        throw new RuntimeException('Usuário não encontrado.');
    }
    $hash = (string) ($user['pass_hash'] ?? '');
    if (!password_verify($current, $hash)) {
        throw new RuntimeException('Senha atual incorreta.');
    }
    if (strlen($next) < 8) {
        throw new RuntimeException('A nova senha deve ter no mínimo 8 caracteres.');
    }
    db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([
        password_hash($next, PASSWORD_DEFAULT),
        $userId,
    ]);
}
