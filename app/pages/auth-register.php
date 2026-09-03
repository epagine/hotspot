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
    <?php require __DIR__ . '/../partials/tw-head.php'; ?>
</head>
<body class="bg-gradient-to-b from-surface to-white min-h-screen flex items-center justify-center p-4 font-sans">
<section class="w-full max-w-md bg-card border border-line rounded-2xl shadow-lg p-8">
    <div class="flex flex-col items-center mb-4">
        <img class="h-14 w-auto rounded-xl bg-white object-contain" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
        <span class="mt-2 inline-block bg-accent/10 text-accent text-xs font-bold px-3 py-1 rounded-full">14 dias grátis</span>
    </div>
    <h1 class="text-2xl font-bold text-ink text-center">Criar conta</h1>
    <p class="text-muted text-sm text-center mt-1 mb-6">Comece o trial e configure seu primeiro hotspot.</p>
    <?php if ($error): ?>
        <div class="bg-danger-bg text-danger border border-danger/20 rounded-xl px-4 py-3 text-sm mb-4"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/comecar" class="space-y-4 relative">
        <?= csrf_field() ?>
        <div class="absolute -left-[9999px] h-0 overflow-hidden" aria-hidden="true">
            <label>Website<input name="website" tabindex="-1" autocomplete="off"></label>
        </div>
        <label class="block">
            <span class="text-sm font-medium text-muted">Seu nome</span>
            <input name="name" required class="mt-1 block w-full rounded-btn border border-line bg-input px-4 py-3 text-ink focus:outline-none focus:ring-2 focus:ring-accent/30 focus:border-accent transition">
        </label>
        <label class="block">
            <span class="text-sm font-medium text-muted">Nome da empresa</span>
            <input name="company" required class="mt-1 block w-full rounded-btn border border-line bg-input px-4 py-3 text-ink focus:outline-none focus:ring-2 focus:ring-accent/30 focus:border-accent transition">
        </label>
        <label class="block">
            <span class="text-sm font-medium text-muted">E-mail</span>
            <input name="email" type="email" required autocomplete="username" class="mt-1 block w-full rounded-btn border border-line bg-input px-4 py-3 text-ink focus:outline-none focus:ring-2 focus:ring-accent/30 focus:border-accent transition">
        </label>
        <label class="block">
            <span class="text-sm font-medium text-muted">Senha</span>
            <input name="password" type="password" required minlength="8" autocomplete="new-password" class="mt-1 block w-full rounded-btn border border-line bg-input px-4 py-3 text-ink focus:outline-none focus:ring-2 focus:ring-accent/30 focus:border-accent transition">
        </label>
        <button type="submit" class="w-full bg-accent hover:bg-accent/90 text-white font-bold py-3 px-4 rounded-btn transition">Começar grátis</button>
    </form>
    <p class="text-muted text-sm text-center mt-6">Já tem conta? <a href="/entrar" class="text-accent hover:underline font-semibold">Entrar</a></p>
</section>
</body>
</html>
