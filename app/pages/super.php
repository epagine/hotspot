<?php

declare(strict_types=1);

require_super_admin();

$tab = preg_replace('/[^a-z]/', '', (string) ($_GET['tab'] ?? 'dashboard')) ?: 'dashboard';
$cfgSec = preg_replace('/[^a-z]/', '', (string) ($_GET['sec'] ?? 'politicas')) ?: 'politicas';
$kpis = dashboard_platform_kpis();
$flashOk = (string) ($_SESSION['flash_ok'] ?? '');
$flashErr = (string) ($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

$pageTitle = match ($tab) {
    'empresas' => 'Empresas',
    'planos' => 'Planos',
    'assinaturas' => 'Assinaturas',
    'usuarios' => 'Usuários',
    'logs' => 'Logs',
    'instalador' => 'Instalador',
    'configuracoes' => 'Configurações',
    default => 'Dashboard',
};
$setupFile = installer_setup_path();
$setupReady = $setupFile !== null && is_file($setupFile);
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> · Super Admin</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="app">
<aside class="app-side">
    <a class="app-brand" href="/super">
        <img class="app-logo app-logo-side" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
        <div>
            <strong>Wi-Fi da loja</strong>
            <small>Super Admin</small>
        </div>
    </a>
    <nav class="app-nav">
        <a class="<?= $tab === 'dashboard' ? 'active' : '' ?>" href="/super">Dashboard</a>
        <a class="<?= $tab === 'empresas' ? 'active' : '' ?>" href="/super?tab=empresas">Empresas</a>
        <a class="<?= $tab === 'planos' ? 'active' : '' ?>" href="/super?tab=planos">Planos</a>
        <a class="<?= $tab === 'assinaturas' ? 'active' : '' ?>" href="/super?tab=assinaturas">Assinaturas</a>
        <a class="<?= $tab === 'usuarios' ? 'active' : '' ?>" href="/super?tab=usuarios">Usuários</a>
        <a class="<?= $tab === 'logs' ? 'active' : '' ?>" href="/super?tab=logs">Logs</a>
        <a class="<?= $tab === 'instalador' ? 'active' : '' ?>" href="/super?tab=instalador">Instalador</a>
        <a class="<?= $tab === 'configuracoes' ? 'active' : '' ?>" href="/super?tab=configuracoes">Configurações</a>
        <a href="/admin/financeiro">Financeiro legado</a>
    </nav>
    <div class="app-side-foot">
        <a class="btn ghost btn-sm" href="/sair">Sair</a>
    </div>
</aside>
<div class="app-body">
    <header class="app-top"><div><h1><?= h($pageTitle) ?></h1><p class="lead">Administração da plataforma.</p></div></header>
    <main class="app-main">
        <?php if ($flashErr): ?><p class="alert"><?= h($flashErr) ?></p><?php endif; ?>
        <?php if ($flashOk): ?><p class="hint flash-ok"><?= h($flashOk) ?></p><?php endif; ?>

        <?php if ($tab === 'dashboard'): ?>
            <div class="stats">
                <article><span>Empresas</span><strong><?= (int) $kpis['companies'] ?></strong></article>
                <article><span>Ativas</span><strong><?= (int) $kpis['companies_active'] ?></strong></article>
                <article><span>Trials</span><strong><?= (int) $kpis['trials'] ?></strong></article>
                <article><span>Assinaturas</span><strong><?= (int) $kpis['subscriptions'] ?></strong></article>
                <article><span>MRR</span><strong><?= h(cents_label((int) $kpis['mrr_cents'])) ?></strong></article>
                <article><span>Hotspots</span><strong><?= (int) $kpis['hotspots'] ?></strong></article>
            </div>

        <?php elseif ($tab === 'empresas'): ?>
            <section class="card">
                <form method="post" action="/super/empresas" class="form form-inline" style="margin-bottom:16px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="create">
                    <label>Nome fantasia<input name="trade_name" required></label>
                    <label>E-mail admin<input type="email" name="admin_email" required></label>
                    <label>Senha admin<input type="password" name="admin_pass" minlength="8" required></label>
                    <button class="btn" type="submit">Criar empresa</button>
                </form>
                <div class="table-wrap">
                    <table class="saas-table">
                        <thead><tr><th>Empresa</th><th>Status</th><th>Assinatura</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach (all_companies() as $c): ?>
                            <?php $s = company_subscription((int) $c['id']); ?>
                            <tr>
                                <td><strong><?= h((string) $c['trade_name']) ?></strong><br><small><?= h((string) $c['email']) ?></small></td>
                                <td><?= h((string) $c['status']) ?></td>
                                <td><?= h(company_subscription_label($s)) ?> · <?= h((string) ($s['plan_name'] ?? '')) ?></td>
                                <td>
                                    <form method="post" action="/super/empresas" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="do" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                        <button class="btn ghost btn-sm" type="submit"><?= ($c['status'] ?? '') === 'active' ? 'Bloquear' : 'Ativar' ?></button>
                                    </form>
                                    <form method="post" action="/super/empresas" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="do" value="impersonate">
                                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                        <button class="btn ghost btn-sm" type="submit">Abrir</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        <?php elseif ($tab === 'planos'): ?>
            <section class="card">
                <div class="table-wrap">
                    <table class="saas-table">
                        <thead><tr><th>Plano</th><th>Preço</th><th>Hotspots</th><th>Clientes</th><th>Ativo</th></tr></thead>
                        <tbody>
                        <?php foreach (all_plans() as $p): ?>
                            <tr>
                                <td><strong><?= h((string) $p['name']) ?></strong><br><small><?= h((string) $p['code']) ?></small></td>
                                <td><?= h(cents_label((int) $p['price_cents'])) ?></td>
                                <td><?= (int) $p['max_hotspots'] === 0 ? '∞' : (int) $p['max_hotspots'] ?></td>
                                <td><?= (int) $p['max_clients'] === 0 ? '∞' : (int) $p['max_clients'] ?></td>
                                <td><?= !empty($p['active']) ? 'Sim' : 'Não' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <form method="post" action="/super/planos" class="form" style="margin-top:20px">
                    <?= csrf_field() ?>
                    <h2>Novo / atualizar por código</h2>
                    <label>Código<input name="code" required></label>
                    <label>Nome<input name="name" required></label>
                    <label>Preço (centavos)<input name="price_cents" type="number" value="2990"></label>
                    <label>Máx. hotspots (0=ilimitado)<input name="max_hotspots" type="number" value="1"></label>
                    <label>Máx. clientes (0=ilimitado)<input name="max_clients" type="number" value="0"></label>
                    <label class="check"><input type="checkbox" name="active" value="1" checked> Ativo</label>
                    <button class="btn" type="submit">Salvar plano</button>
                </form>
            </section>

        <?php elseif ($tab === 'assinaturas'): ?>
            <?php
            $rows = db()->query(
                'SELECT s.*, c.trade_name, p.name AS plan_name FROM subscriptions s
                 LEFT JOIN companies c ON c.id = s.company_id
                 LEFT JOIN plans p ON p.id = s.plan_id
                 ORDER BY s.id DESC'
            )->fetchAll() ?: [];
            ?>
            <section class="card">
                <div class="table-wrap">
                    <table class="saas-table">
                        <thead><tr><th>Empresa</th><th>Plano</th><th>Status</th><th>Trial/Fim</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?= h((string) ($r['trade_name'] ?? '')) ?></td>
                                <td><?= h((string) ($r['plan_name'] ?? '')) ?></td>
                                <td><?= h(company_subscription_label($r)) ?></td>
                                <td><?= h((string) (($r['trial_ends_at'] ?: $r['ends_at']) ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        <?php elseif ($tab === 'usuarios'): ?>
            <section class="card">
                <div class="table-wrap">
                    <table class="saas-table">
                        <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach (platform_users() as $u): ?>
                            <tr>
                                <td><?= h((string) $u['name']) ?></td>
                                <td><?= h((string) $u['email']) ?></td>
                                <td><?= h((string) $u['role']) ?></td>
                                <td><?= h((string) $u['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        <?php elseif ($tab === 'logs'): ?>
            <?php $logs = db()->query('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 100')->fetchAll() ?: []; ?>
            <section class="card">
                <ul class="steps">
                <?php foreach ($logs as $log): ?>
                    <li><?= h((string) $log['created_at']) ?> — <?= h((string) $log['action']) ?></li>
                <?php endforeach; ?>
                <?php if (!$logs): ?><li>Nenhum log ainda.</li><?php endif; ?>
                </ul>
            </section>

        <?php elseif ($tab === 'instalador'): ?>
            <section class="card card-narrow">
                <?php if ($setupReady): ?>
                    <div class="actions row">
                        <a class="btn" href="/super/instalador/baixar">Baixar WiFiDaLoja-Setup.exe</a>
                    </div>
                    <p class="hint"><?= h(basename($setupFile)) ?> · <?= h((string) round((int) filesize($setupFile) / 1048576, 1)) ?> MB</p>
                <?php else: ?>
                    <p class="hint">Ainda não há arquivo publicado. Envie o .exe gerado com <code>installer\Empacotar.ps1</code>.</p>
                <?php endif; ?>
                <form method="post" action="/super/instalador" class="form" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="return_to" value="/super?tab=instalador">
                    <label>Publicar .exe
                        <input name="setup" type="file" accept=".exe,application/vnd.microsoft-portable-executable" required>
                    </label>
                    <button class="btn <?= $setupReady ? 'ghost' : '' ?>" type="submit"><?= $setupReady ? 'Substituir arquivo' : 'Enviar arquivo' ?></button>
                </form>
                <p class="hint">URL do painel: <code><?= h(guess_panel_url()) ?></code></p>
            </section>

        <?php elseif ($tab === 'configuracoes'): ?>
            <nav class="app-subnav">
                <a class="<?= $cfgSec === 'politicas' ? 'active' : '' ?>" href="/super?tab=configuracoes&sec=politicas">Políticas SaaS</a>
                <a class="<?= $cfgSec === 'integracao' ? 'active' : '' ?>" href="/super?tab=configuracoes&sec=integracao">PagSeguro</a>
            </nav>
            <?php if ($cfgSec === 'integracao'): ?>
            <section class="card card-narrow">
                <h2>PagSeguro / PagBank</h2>
                <ol class="steps">
                    <li>Token em PagSeguro / PagBank (sandbox ou produção).</li>
                    <li>Webhook: <code><?= h(pagseguro_webhook_url()) ?></code></li>
                    <li>Cobranças SaaS usam referência <code>wlc-{empresa}-…</code></li>
                </ol>
                <form method="post" action="/admin/pagseguro" class="form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="save">
                    <input type="hidden" name="return_to" value="/super?tab=configuracoes&sec=integracao">
                    <label>Ambiente
                        <select name="pagseguro_env">
                            <option value="sandbox" <?= pagseguro_env() === 'sandbox' ? 'selected' : '' ?>>Sandbox</option>
                            <option value="production" <?= pagseguro_env() === 'production' ? 'selected' : '' ?>>Produção</option>
                        </select>
                    </label>
                    <label>Token da API
                        <input name="pagseguro_token" type="password" autocomplete="off" placeholder="<?= pagseguro_configured() ? h(pagseguro_mask_token()) : 'Cole o token' ?>">
                    </label>
                    <p class="hint"><?= pagseguro_configured() ? 'Token salvo (' . h(pagseguro_mask_token()) . ').' : 'O token não aparece inteiro depois de salvar.' ?></p>
                    <label class="check"><input type="hidden" name="pagseguro_auto" value="0"><input type="checkbox" name="pagseguro_auto" value="1" <?= pagseguro_auto_enabled() ? 'checked' : '' ?>> Cobrança automática (empresas SaaS + lojas legadas)</label>
                    <label>Antecedência (dias)
                        <input name="pagseguro_advance_days" type="number" min="0" max="30" value="<?= (int) pagseguro_advance_days() ?>">
                    </label>
                    <button class="btn" type="submit">Salvar integração</button>
                </form>
                <?php if (pagseguro_configured()): ?>
                    <p class="hint">Cron diário:<br><code><?= h(pagseguro_cron_url()) ?></code></p>
                    <div class="actions row">
                        <form method="post" action="/admin/pagseguro">
                            <?= csrf_field() ?>
                            <input type="hidden" name="do" value="test">
                            <input type="hidden" name="return_to" value="/super?tab=configuracoes&sec=integracao">
                            <button class="btn ghost" type="submit">Testar token</button>
                        </form>
                        <form method="post" action="/admin/pagseguro">
                            <?= csrf_field() ?>
                            <input type="hidden" name="do" value="run">
                            <input type="hidden" name="return_to" value="/super?tab=configuracoes&sec=integracao">
                            <button class="btn ghost" type="submit">Gerar cobranças agora</button>
                        </form>
                    </div>
                <?php endif; ?>
                <p class="hint">Financeiro legado por loja: <a href="/admin/financeiro">/admin/financeiro</a></p>
            </section>
            <?php else: ?>
            <section class="card card-narrow">
                <h2>Políticas SaaS</h2>
                <form method="post" action="/admin/configuracoes/politicas" class="form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="return_to" value="/super?tab=configuracoes&sec=politicas">
                    <label>Dias de trial por empresa
                        <input name="saas_trial_days" type="number" min="0" max="90" value="<?= (int) saas_trial_days() ?>">
                    </label>
                    <label>Tolerância após vencimento (dias)
                        <input name="saas_grace_days" type="number" min="0" max="30" value="<?= (int) saas_grace_days() ?>">
                    </label>
                    <label class="check"><input type="hidden" name="saas_auto_suspend" value="0"><input type="checkbox" name="saas_auto_suspend" value="1" <?= saas_auto_suspend_enabled() ? 'checked' : '' ?>> Suspender serviço após a tolerância</label>
                    <button class="btn" type="submit">Salvar políticas</button>
                </form>
            </section>
            <?php endif; ?>
    </main>
</div>
</body>
</html>
