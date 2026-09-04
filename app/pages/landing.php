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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="saas-landing">

<header class="saas-nav">
    <div class="saas-nav-inner">
        <a href="/"><img class="saas-nav-logo" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja"></a>
        <nav class="saas-nav-links">
            <a href="#como">Como funciona</a>
            <a href="#recursos">Recursos</a>
            <a href="#planos">Planos</a>
            <a href="#faq">FAQ</a>
        </nav>
        <div class="saas-nav-cta">
            <a href="<?= h($startUrl) ?>" class="btn btn-sm saas-nav-cta-mobile">Começar</a>
            <a href="<?= h($startUrl) ?>" class="btn btn-sm">Começar grátis</a>
        </div>
    </div>
</header>

<section class="saas-hero">
    <img class="saas-hero-logo" src="<?= h(platform_logo_url()) ?>" alt="">
    <h1>Transforme o Wi-Fi em relacionamento</h1>
    <p class="saas-hero-lead">Ofereça internet aos seus clientes, conheça seu público e transforme acessos em oportunidades para o seu negócio.</p>
    <div class="saas-hero-actions">
        <a href="<?= h($startUrl) ?>" class="btn">Começar grátis</a>
        <a href="<?= h($panelUrl) ?>" class="btn ghost">Já tenho conta</a>
    </div>
</section>

<section class="saas-section saas-section--muted" id="como">
    <div class="saas-section-inner">
        <div class="saas-section-head">
            <h2>Como funciona</h2>
            <p>Do Wi-Fi ao cadastro do cliente, em poucos passos.</p>
        </div>
        <div class="saas-grid-3">
            <?php foreach ([
                ['01', 'Cliente conecta', 'O visitante entra na rede Wi-Fi do estabelecimento.'],
                ['02', 'Portal da empresa', 'Ele se identifica com nome e WhatsApp e aceita os termos.'],
                ['03', 'Internet liberada', 'O acesso é registrado e você acompanha tudo no painel.'],
            ] as [$num, $title, $desc]): ?>
            <article class="saas-feature-card">
                <span class="saas-step-num"><?= $num ?></span>
                <h3><?= h($title) ?></h3>
                <p><?= h($desc) ?></p>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="saas-section" id="recursos">
    <div class="saas-section-inner">
        <div class="saas-section-head">
            <h2>Recursos pensados para o dia a dia</h2>
            <p>Simples de usar, mesmo sem conhecimento técnico.</p>
        </div>
        <div class="saas-grid-2">
            <?php foreach ([
                ['Hotspots e portal', 'Personalize logo, cores, textos e termos por unidade.'],
                ['Clientes e acessos', 'Base de visitantes com histórico, filtros e exportação.'],
                ['Marketing', 'Campanhas e cupons após o login no Wi-Fi.'],
                ['Multi-usuário', 'Admin e operadores com permissões por módulo.'],
                ['Assinatura SaaS', 'Trial, planos e limites claros para crescer com segurança.'],
                ['Pronto para equipamentos', 'Arquitetura preparada para Windows, MikroTik e outros.'],
            ] as [$title, $desc]): ?>
            <article class="saas-feature-card">
                <h3><?= h($title) ?></h3>
                <p><?= h($desc) ?></p>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="saas-section saas-section--muted" id="planos">
    <div class="saas-section-inner">
        <div class="saas-section-head">
            <h2>Planos</h2>
            <p>Comece grátis por 14 dias. Cancele quando quiser.</p>
        </div>
        <div class="saas-grid-plans">
            <?php if ($plans): ?>
                <?php foreach ($plans as $p): ?>
                <article class="saas-plan-card">
                    <h3><?= h((string) $p['name']) ?></h3>
                    <p class="saas-plan-price"><?= (int) $p['price_cents'] === 0 ? 'Grátis' : h(cents_label((int) $p['price_cents'])) ?><?php if ((int) $p['price_cents'] > 0): ?><span>/mês</span><?php endif; ?></p>
                    <div class="saas-plan-meta">
                        <p>Hotspots: <?= (int) $p['max_hotspots'] === 0 ? 'ilimitados' : (int) $p['max_hotspots'] ?></p>
                        <p>Clientes: <?= (int) $p['max_clients'] === 0 ? 'ilimitados' : (int) $p['max_clients'] ?></p>
                    </div>
                </article>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ([
                    ['Gratuito', '1 hotspot · 100 clientes'],
                    ['Essencial', 'R$ 29,90/mês · portal personalizado'],
                    ['Profissional', 'R$ 49,90/mês · campanhas e cupons'],
                    ['Empresa', 'R$ 99,90/mês · múltiplas unidades'],
                ] as [$name, $desc]): ?>
                <article class="saas-plan-card">
                    <h3><?= h($name) ?></h3>
                    <div class="saas-plan-meta"><p><?= h($desc) ?></p></div>
                </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="saas-hero-actions" style="margin-top:32px">
            <a href="<?= h($startUrl) ?>" class="btn">Começar grátis</a>
        </div>
    </div>
</section>

<section class="saas-section" id="faq">
    <div class="saas-section-inner">
        <div class="saas-section-head">
            <h2>Perguntas frequentes</h2>
            <p>Respostas rápidas para quem está começando.</p>
        </div>
        <div class="saas-faq-list">
            <?php foreach ([
                ['Preciso de roteador especial?', 'No MVP usamos o hotspot do Windows no PC da loja. Outros equipamentos virão depois.'],
                ['Os dados são da minha empresa?', 'Sim. Cada empresa vê apenas os próprios clientes, hotspots e campanhas.'],
                ['Posso testar antes de pagar?', 'Sim. Toda conta nova recebe 14 dias de trial.'],
            ] as [$q, $a]): ?>
            <details class="saas-faq-item">
                <summary><?= h($q) ?></summary>
                <p><?= h($a) ?></p>
            </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="saas-section saas-section--muted">
    <div class="saas-section-inner">
        <div class="saas-cta-block">
            <h2>Pronto para conhecer seus clientes pelo Wi-Fi?</h2>
            <p>Crie sua conta, configure o portal e comece a registrar acessos hoje.</p>
            <div class="saas-hero-actions">
                <a href="<?= h($startUrl) ?>" class="btn">Começar grátis</a>
                <a href="<?= h($panelUrl) ?>" class="btn ghost">Entrar</a>
            </div>
        </div>
    </div>
</section>

<footer class="saas-footer">
    <div class="saas-footer-inner">
        <img src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
        <span>Hotspot com cadastro e relacionamento</span>
    </div>
</footer>
</body>
</html>
