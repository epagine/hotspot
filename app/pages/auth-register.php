<?php

declare(strict_types=1);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (honeypot_tripped()) {
        $error = 'Não foi possível criar a conta.';
    } elseif (rate_limit_is_blocked('register')) {
        $error = rate_limit_reject_message();
    } else {
    try {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        $companyName = trim((string) ($_POST['company'] ?? ''));
        if ($name === '' || $companyName === '') {
            throw new RuntimeException('Informe seu nome e o nome da empresa.');
        }
        $user = create_user([
            'name' => $name,
            'email' => $email,
            'password' => $pass,
            'role' => 'company_admin',
        ]);
        $company = create_company([
            'trade_name' => $companyName,
            'legal_name' => $companyName,
            'email' => $email,
        ], (int) $user['id'], 'essencial');
        auth_login($user, (int) $company['id']);
        rate_limit_clear('register');
        header('Location: /app');
        exit;
    } catch (Throwable $e) {
        rate_limit_fail('register');
        $error = $e->getMessage();
    }
    }
}
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Começar grátis · Wi-Fi da loja</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="saas-auth">
<section class="saas-auth-card saas-auth">
    <div class="saas-auth-brand">
        <img class="saas-auth-logo" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
        <span class="saas-auth-badge">14 dias grátis</span>
    </div>
    <h1 class="saas-auth-title">Criar conta</h1>
    <p class="saas-auth-lead">Comece o trial e configure seu primeiro hotspot.</p>
    <?php if ($error): ?>
        <div class="alert"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/comecar" class="form" style="position:relative">
        <?= csrf_field() ?>
        <div class="hidden" aria-hidden="true">
            <label>Website<input name="website" tabindex="-1" autocomplete="off"></label>
        </div>
        <label>
            Seu nome
            <input name="name" required>
        </label>
        <label>
            Nome da empresa
            <input name="company" required>
        </label>
        <label>
            E-mail
            <input name="email" type="email" required autocomplete="username">
        </label>
        <label>
            Senha
            <input name="password" type="password" required minlength="8" autocomplete="new-password">
        </label>
        <button type="submit" class="btn btn-block">Começar grátis</button>
    </form>
    <p class="saas-auth-footer">Já tem conta? <a href="/entrar">Entrar</a></p>
</section>
</body>
</html>
