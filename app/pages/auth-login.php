<?php

declare(strict_types=1);

require_database_or_install();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (rate_limit_is_blocked('login')) {
        $error = rate_limit_reject_message();
    } else {
    $email = (string) ($_POST['email'] ?? $_POST['user'] ?? '');
    $pass = (string) ($_POST['pass'] ?? '');
    $user = auth_attempt($email, $pass);
    if (!$user) {
        $stored = strtolower(trim(setting('admin_user', 'admin')));
        $storedEmail = str_contains($stored, '@') ? $stored : ($stored . '@wifidaloja.local');
        $legacyLogin = strtolower(trim($email));
        $legacyUser = trim((string) ($_POST['user'] ?? ''));
        if ($legacyUser === '' && str_contains($legacyLogin, '@')) {
            $legacyUser = trim(explode('@', $legacyLogin)[0]);
        } elseif ($legacyUser === '') {
            $legacyUser = $legacyLogin;
        }
        $matchUser = hash_equals($stored, $legacyUser)
            || hash_equals($stored, $legacyLogin)
            || hash_equals($storedEmail, $legacyLogin);
        if ($matchUser && password_verify($pass, setting('admin_pass_hash', ''))) {
            $_SESSION['admin'] = true;
            ensure_legacy_admin_user();
            $stmt = db()->prepare('SELECT * FROM users WHERE role = ? ORDER BY id ASC LIMIT 1');
            $stmt->execute(['super_admin']);
            $user = $stmt->fetch() ?: null;
            if ($user) {
                auth_login($user);
            }
        }
    } else {
        auth_login($user);
    }
    if (current_user()) {
        rate_limit_clear('login');
        $u = current_user();
        if (($u['role'] ?? '') === 'super_admin') {
            header('Location: /super');
            exit;
        }
        header('Location: /app');
        exit;
    }
    rate_limit_fail('login');
    $error = 'E-mail ou senha inválidos.';
    }
}
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar · Wi-Fi da loja</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="saas-auth">
<section class="saas-auth-card saas-auth">
    <div class="saas-auth-brand">
        <img class="saas-auth-logo" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
    </div>
    <h1 class="saas-auth-title">Entrar</h1>
    <p class="saas-auth-lead">Gerencie hotspots, clientes e assinatura.</p>
    <?php if ($error): ?>
        <div class="alert"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/entrar" class="form">
        <?= csrf_field() ?>
        <label>
            E-mail
            <input name="email" type="email" required autofocus autocomplete="username">
        </label>
        <label>
            Senha
            <input name="pass" type="password" required autocomplete="current-password">
        </label>
        <button type="submit" class="btn btn-block">Entrar</button>
    </form>
    <p class="saas-auth-footer">Use o e-mail e a senha definidos na instalação. <a href="/comecar">Ainda não tem conta?</a></p>
</section>
</body>
</html>
