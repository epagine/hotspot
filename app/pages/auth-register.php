<?php

declare(strict_types=1);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
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
        header('Location: /app');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Começar grátis · Wi-Fi da loja</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="app-auth">
<section class="card">
    <div class="app-brand app-brand-logo">
        <img class="app-logo" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
        <p class="hint" style="margin:8px 0 0;text-align:center">14 dias grátis</p>
    </div>
    <h1>Criar conta</h1>
    <p class="lead">Comece o trial e configure seu primeiro hotspot.</p>
    <?php if ($error): ?><p class="alert"><?= h($error) ?></p><?php endif; ?>
    <form method="post" action="/comecar" class="form">
        <?= csrf_field() ?>
        <label>Seu nome<input name="name" required></label>
        <label>Nome da empresa<input name="company" required></label>
        <label>E-mail<input name="email" type="email" required autocomplete="username"></label>
        <label>Senha<input name="password" type="password" required minlength="8" autocomplete="new-password"></label>
        <button class="btn" type="submit">Começar grátis</button>
    </form>
    <p class="hint" style="margin-top:16px">Já tem conta? <a href="/entrar">Entrar</a></p>
</section>
</body>
</html>
