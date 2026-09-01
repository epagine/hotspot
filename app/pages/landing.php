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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/landing.css">
</head>
<body class="lp">
<header class="lp-nav">
    <a class="lp-brand" href="/">
        <img class="lp-logo lp-logo-nav" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
    </a>
    <nav class="lp-nav-links">
        <a href="#como">Como funciona</a>
        <a href="#recursos">Recursos</a>
        <a href="#planos">Planos</a>
        <a href="#faq">FAQ</a>
        <a class="lp-nav-cta" href="<?= h($startUrl) ?>">Começar grátis</a>
    </nav>
</header>

<main>
    <section class="lp-hero">
        <div class="lp-hero-copy">
            <img class="lp-logo lp-logo-hero" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja — Internet para seus clientes. Dados para o seu negócio.">
            <h1>Transforme o Wi-Fi da sua empresa em uma ferramenta de relacionamento</h1>
            <p class="lp-lead">Ofereça internet aos seus clientes, conheça seu público e transforme acessos em oportunidades para o seu negócio.</p>
            <div class="lp-cta">
                <a class="lp-btn" href="<?= h($startUrl) ?>">Começar grátis</a>
                <a class="lp-btn lp-btn-ghost" href="<?= h($panelUrl) ?>">Já tenho conta</a>
            </div>
        </div>
        <div class="lp-hero-visual" aria-hidden="true">
            <div class="lp-hero-stage lp-hero-stage-logo">
                <img class="lp-logo lp-logo-stage" src="<?= h(platform_logo_url()) ?>" alt="">
            </div>
        </div>
    </section>

    <section class="lp-section" id="como">
        <div class="lp-section-head">
            <h2>Como funciona</h2>
            <p>Do Wi-Fi ao cadastro do cliente, em poucos passos.</p>
        </div>
        <ol class="lp-steps">
            <li><span>01</span><h3>Cliente conecta</h3><p>O visitante entra na rede Wi-Fi do estabelecimento.</p></li>
            <li><span>02</span><h3>Portal da empresa</h3><p>Ele se identifica com nome e WhatsApp e aceita os termos.</p></li>
            <li><span>03</span><h3>Internet liberada</h3><p>O acesso é registrado e você acompanha tudo no painel.</p></li>
        </ol>
    </section>

    <section class="lp-section lp-section-split" id="recursos">
        <div class="lp-section-head">
            <h2>Recursos pensados para o dia a dia</h2>
            <p>Simples de usar, mesmo sem conhecimento técnico.</p>
        </div>
        <ul class="lp-points">
            <li><h3>Hotspots e portal</h3><p>Personalize logo, cores, textos e termos por unidade.</p></li>
            <li><h3>Clientes e acessos</h3><p>Base de visitantes com histórico, filtros e exportação.</p></li>
            <li><h3>Marketing</h3><p>Campanhas e cupons após o login no Wi-Fi.</p></li>
            <li><h3>Multi-usuário</h3><p>Admin e operadores com permissões por módulo.</p></li>
            <li><h3>Assinatura SaaS</h3><p>Trial, planos e limites claros para crescer com segurança.</p></li>
            <li><h3>Pronto para equipamentos</h3><p>Arquitetura preparada para Windows, MikroTik e outros.</p></li>
        </ul>
    </section>

    <section class="lp-section" id="planos">
        <div class="lp-section-head">
            <h2>Planos</h2>
            <p>Comece grátis por 14 dias. Cancele quando quiser.</p>
        </div>
        <div class="lp-points">
            <?php if ($plans): ?>
                <?php foreach ($plans as $p): ?>
                    <div>
                        <h3><?= h((string) $p['name']) ?></h3>
                        <p><strong><?= (int) $p['price_cents'] === 0 ? 'Grátis' : h(cents_label((int) $p['price_cents'])) . '/mês' ?></strong></p>
                        <p>Hotspots: <?= (int) $p['max_hotspots'] === 0 ? 'ilimitados' : (int) $p['max_hotspots'] ?></p>
                        <p>Clientes: <?= (int) $p['max_clients'] === 0 ? 'ilimitados' : (int) $p['max_clients'] ?></p>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div><h3>Gratuito</h3><p>1 hotspot · 100 clientes</p></div>
                <div><h3>Essencial</h3><p>R$ 29,90/mês · portal personalizado</p></div>
                <div><h3>Profissional</h3><p>R$ 49,90/mês · campanhas e cupons</p></div>
                <div><h3>Empresa</h3><p>R$ 99,90/mês · múltiplas unidades</p></div>
            <?php endif; ?>
        </div>
        <div class="lp-cta" style="margin-top:28px">
            <a class="lp-btn" href="<?= h($startUrl) ?>">Começar grátis</a>
        </div>
    </section>

    <section class="lp-section" id="faq">
        <div class="lp-section-head">
            <h2>Perguntas frequentes</h2>
            <p>Respostas rápidas para quem está começando.</p>
        </div>
        <ul class="lp-points">
            <li><h3>Preciso de roteador especial?</h3><p>No MVP usamos o hotspot do Windows no PC da loja. Outros equipamentos virão depois.</p></li>
            <li><h3>Os dados são da minha empresa?</h3><p>Sim. Cada empresa vê apenas os próprios clientes, hotspots e campanhas.</p></li>
            <li><h3>Posso testar antes de pagar?</h3><p>Sim. Toda conta nova recebe 14 dias de trial.</p></li>
        </ul>
    </section>

    <section class="lp-section lp-closing">
        <h2>Pronto para conhecer seus clientes pelo Wi-Fi?</h2>
        <p>Crie sua conta, configure o portal e comece a registrar acessos hoje.</p>
        <div class="lp-cta">
            <a class="lp-btn" href="<?= h($startUrl) ?>">Começar grátis</a>
            <a class="lp-btn lp-btn-ghost" href="<?= h($panelUrl) ?>">Entrar</a>
        </div>
    </section>
</main>

<footer class="lp-foot">
    <span class="lp-brand-mini">
        <img class="lp-logo lp-logo-foot" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
    </span>
    <span>Hotspot com cadastro e relacionamento</span>
</footer>
</body>
</html>
