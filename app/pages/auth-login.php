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
        // Compat: instalação antiga com "usuário" (sem @) ou e-mail gravado em settings.
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
    <?php require __DIR__ . '/../partials/tw-head.php'; ?>
</head>
<body class="bg-gradient-to-b from-surface to-white min-h-screen flex items-center justify-center p-4 font-sans">
<section class="w-full max-w-md bg-card border border-line rounded-2xl shadow-lg p-8">
    <div class="flex flex-col items-center mb-6">
        <img class="h-14 w-auto rounded-xl bg-white object-contain" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
    </div>
    <h1 class="text-2xl font-bold text-ink text-center">Entrar</h1>
    <p class="text-muted text-sm text-center mt-1 mb-6">Gerencie hotspots, clientes e assinatura.</p>
    <?php if ($error): ?>
        <div class="bg-danger-bg text-danger border border-danger/20 rounded-xl px-4 py-3 text-sm mb-4"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/entrar" class="space-y-4">
        <?= csrf_field() ?>
        <label class="block">
            <span class="text-sm font-medium text-muted">E-mail</span>
            <input name="email" type="email" required autofocus autocomplete="username"
                   class="mt-1 block w-full rounded-btn border border-line bg-input px-4 py-3 text-ink placeholder:text-muted/60 focus:outline-none focus:ring-2 focus:ring-accent/30 focus:border-accent transition">
        </label>
        <label class="block">
            <span class="text-sm font-medium text-muted">Senha</span>
            <input name="pass" type="password" required autocomplete="current-password"
                   class="mt-1 block w-full rounded-btn border border-line bg-input px-4 py-3 text-ink placeholder:text-muted/60 focus:outline-none focus:ring-2 focus:ring-accent/30 focus:border-accent transition">
        </label>
        <button type="submit" class="w-full bg-accent hover:bg-accent/90 text-white font-bold py-3 px-4 rounded-btn transition">Entrar</button>
    </form>
    <p class="text-muted text-sm text-center mt-6">Use o e-mail e a senha definidos na instalação. <a href="/comecar" class="text-accent hover:underline font-semibold">Ainda não tem conta?</a></p>
</section>
</body>
</html>
