<?php

declare(strict_types=1);

$panelUrl = '/entrar';
$startUrl = '/comecar';
$plans = [];
try {
    if (is_installed()) {
        $plans = all_plans(true);
    }
} catch (Throwable $e) {
    $plans = [];
}
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wi-Fi da loja · Transforme o Wi-Fi em relacionamento</title>
    <meta name="description" content="Ofereça internet aos seus clientes, conheça seu público e transforme acessos em oportunidades.">
    <?php require __DIR__ . '/../partials/tw-head.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        .font-display{font-family:'Syne','Figtree',system-ui,sans-serif}
    </style>
</head>
<body class="bg-white text-ink font-sans antialiased">

<!-- Nav -->
<header class="sticky top-0 z-30 bg-white/90 backdrop-blur border-b border-line/50">
    <div class="max-w-6xl mx-auto flex items-center justify-between px-5 py-3">
        <a href="/" class="flex-shrink-0">
            <img class="h-10 w-auto rounded-lg bg-white object-contain" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
        </a>
        <nav class="hidden sm:flex items-center gap-6 text-sm font-semibold text-muted">
            <a href="#como" class="hover:text-ink transition">Como funciona</a>
            <a href="#recursos" class="hover:text-ink transition">Recursos</a>
            <a href="#planos" class="hover:text-ink transition">Planos</a>
            <a href="#faq" class="hover:text-ink transition">FAQ</a>
            <a href="<?= h($startUrl) ?>" class="bg-accent hover:bg-accent/90 text-white px-4 py-2 rounded-btn transition">Começar grátis</a>
        </nav>
        <a href="<?= h($startUrl) ?>" class="sm:hidden bg-accent hover:bg-accent/90 text-white text-sm font-bold px-4 py-2 rounded-btn transition">Começar</a>
    </div>
</header>

<!-- Hero -->
<section class="relative overflow-hidden">
    <div class="absolute inset-0 bg-gradient-to-br from-accent/5 via-transparent to-surface pointer-events-none"></div>
    <div class="relative max-w-6xl mx-auto px-5 py-20 sm:py-28 lg:py-36 text-center">
        <img class="h-16 w-auto mx-auto mb-6 rounded-xl bg-white object-contain" src="<?= h(platform_logo_url()) ?>" alt="">
        <h1 class="font-display text-4xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight leading-tight max-w-3xl mx-auto">Transforme o Wi-Fi em <span class="text-accent">relacionamento</span></h1>
        <p class="mt-5 text-lg text-muted max-w-2xl mx-auto">Ofereça internet aos seus clientes, conheça seu público e transforme acessos em oportunidades para o seu negócio.</p>
        <div class="mt-8 flex flex-wrap justify-center gap-4">
            <a href="<?= h($startUrl) ?>" class="bg-accent hover:bg-accent/90 text-white font-bold px-8 py-3.5 rounded-btn text-base transition shadow-lg shadow-accent/20">Começar grátis</a>
            <a href="<?= h($panelUrl) ?>" class="bg-white hover:bg-hover text-ink font-bold px-8 py-3.5 rounded-btn text-base border border-line transition">Já tenho conta</a>
        </div>
    </div>
</section>

<!-- Como funciona -->
<section class="py-20 bg-surface" id="como">
    <div class="max-w-6xl mx-auto px-5">
        <div class="text-center mb-12">
            <h2 class="font-display text-3xl sm:text-4xl font-bold">Como funciona</h2>
            <p class="text-muted mt-2">Do Wi-Fi ao cadastro do cliente, em poucos passos.</p>
        </div>
        <div class="grid sm:grid-cols-3 gap-8">
            <?php foreach ([
                ['01', 'Cliente conecta', 'O visitante entra na rede Wi-Fi do estabelecimento.'],
                ['02', 'Portal da empresa', 'Ele se identifica com nome e WhatsApp e aceita os termos.'],
                ['03', 'Internet liberada', 'O acesso é registrado e você acompanha tudo no painel.'],
            ] as [$num, $title, $desc]): ?>
                <div class="bg-white rounded-2xl p-6 border border-line shadow-sm">
                    <span class="inline-block bg-accent/10 text-accent text-sm font-extrabold w-10 h-10 rounded-full flex items-center justify-center mb-4"><?= $num ?></span>
                    <h3 class="text-lg font-bold mb-2"><?= h($title) ?></h3>
                    <p class="text-muted text-sm"><?= h($desc) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Recursos -->
<section class="py-20" id="recursos">
    <div class="max-w-6xl mx-auto px-5">
        <div class="text-center mb-12">
            <h2 class="font-display text-3xl sm:text-4xl font-bold">Recursos pensados para o dia a dia</h2>
            <p class="text-muted mt-2">Simples de usar, mesmo sem conhecimento técnico.</p>
        </div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ([
                ['Hotspots e portal', 'Personalize logo, cores, textos e termos por unidade.'],
                ['Clientes e acessos', 'Base de visitantes com histórico, filtros e exportação.'],
                ['Marketing', 'Campanhas e cupons após o login no Wi-Fi.'],
                ['Multi-usuário', 'Admin e operadores com permissões por módulo.'],
                ['Assinatura SaaS', 'Trial, planos e limites claros para crescer com segurança.'],
                ['Pronto para equipamentos', 'Arquitetura preparada para Windows, MikroTik e outros.'],
            ] as [$title, $desc]): ?>
                <div class="bg-surface rounded-xl p-5 border border-line/60">
                    <h3 class="font-bold mb-1"><?= h($title) ?></h3>
                    <p class="text-muted text-sm"><?= h($desc) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Planos -->
<section class="py-20 bg-surface" id="planos">
    <div class="max-w-6xl mx-auto px-5">
        <div class="text-center mb-12">
            <h2 class="font-display text-3xl sm:text-4xl font-bold">Planos</h2>
            <p class="text-muted mt-2">Comece grátis por 14 dias. Cancele quando quiser.</p>
        </div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-<?= count($plans) >= 4 ? '4' : (count($plans) ?: '4') ?> gap-6 max-w-4xl mx-auto">
            <?php if ($plans): ?>
                <?php foreach ($plans as $p): ?>
                    <div class="bg-white rounded-2xl p-6 border border-line shadow-sm text-center">
                        <h3 class="text-lg font-bold"><?= h((string) $p['name']) ?></h3>
                        <p class="text-2xl font-extrabold mt-2"><?= (int) $p['price_cents'] === 0 ? 'Grátis' : h(cents_label((int) $p['price_cents'])) ?><span class="text-sm font-normal text-muted"><?= (int) $p['price_cents'] > 0 ? '/mês' : '' ?></span></p>
                        <div class="text-sm text-muted mt-3 space-y-1">
                            <p>Hotspots: <?= (int) $p['max_hotspots'] === 0 ? 'ilimitados' : (int) $p['max_hotspots'] ?></p>
                            <p>Clientes: <?= (int) $p['max_clients'] === 0 ? 'ilimitados' : (int) $p['max_clients'] ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ([
                    ['Gratuito', '1 hotspot · 100 clientes'],
                    ['Essencial', 'R$ 29,90/mês · portal personalizado'],
                    ['Profissional', 'R$ 49,90/mês · campanhas e cupons'],
                    ['Empresa', 'R$ 99,90/mês · múltiplas unidades'],
                ] as [$name, $desc]): ?>
                    <div class="bg-white rounded-2xl p-6 border border-line shadow-sm text-center">
                        <h3 class="text-lg font-bold"><?= h($name) ?></h3>
                        <p class="text-sm text-muted mt-2"><?= h($desc) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="text-center mt-10">
            <a href="<?= h($startUrl) ?>" class="bg-accent hover:bg-accent/90 text-white font-bold px-8 py-3.5 rounded-btn text-base transition shadow-lg shadow-accent/20">Começar grátis</a>
        </div>
    </div>
</section>

<!-- FAQ -->
<section class="py-20" id="faq">
    <div class="max-w-3xl mx-auto px-5">
        <div class="text-center mb-12">
            <h2 class="font-display text-3xl sm:text-4xl font-bold">Perguntas frequentes</h2>
            <p class="text-muted mt-2">Respostas rápidas para quem está começando.</p>
        </div>
        <div class="space-y-4">
            <?php foreach ([
                ['Preciso de roteador especial?', 'No MVP usamos o hotspot do Windows no PC da loja. Outros equipamentos virão depois.'],
                ['Os dados são da minha empresa?', 'Sim. Cada empresa vê apenas os próprios clientes, hotspots e campanhas.'],
                ['Posso testar antes de pagar?', 'Sim. Toda conta nova recebe 14 dias de trial.'],
            ] as [$q, $a]): ?>
                <details class="group bg-surface border border-line/60 rounded-xl p-5">
                    <summary class="cursor-pointer font-bold text-ink flex items-center justify-between">
                        <?= h($q) ?>
                        <svg class="w-5 h-5 text-muted transition group-open:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                    </summary>
                    <p class="text-muted text-sm mt-3"><?= h($a) ?></p>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- CTA final -->
<section class="py-20 bg-gradient-to-br from-accent/5 to-surface text-center">
    <div class="max-w-2xl mx-auto px-5">
        <h2 class="font-display text-3xl sm:text-4xl font-bold">Pronto para conhecer seus clientes pelo Wi-Fi?</h2>
        <p class="text-muted mt-3 text-lg">Crie sua conta, configure o portal e comece a registrar acessos hoje.</p>
        <div class="mt-8 flex flex-wrap justify-center gap-4">
            <a href="<?= h($startUrl) ?>" class="bg-accent hover:bg-accent/90 text-white font-bold px-8 py-3.5 rounded-btn text-base transition shadow-lg shadow-accent/20">Começar grátis</a>
            <a href="<?= h($panelUrl) ?>" class="bg-white hover:bg-hover text-ink font-bold px-8 py-3.5 rounded-btn text-base border border-line transition">Entrar</a>
        </div>
    </div>
</section>

<footer class="border-t border-line py-6">
    <div class="max-w-6xl mx-auto px-5 flex items-center justify-center gap-3 text-sm text-muted">
        <img class="h-6 w-auto rounded bg-white object-contain" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
        <span>Hotspot com cadastro e relacionamento</span>
    </div>
</footer>
</body>
</html>
