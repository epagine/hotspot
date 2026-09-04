<?php

declare(strict_types=1);

require_super_admin();

$tab = preg_replace('/[^a-z]/', '', (string) ($_GET['tab'] ?? 'dashboard')) ?: 'dashboard';
$cfgSec = 'politicas';
if ($tab === 'configuracoes') {
    $cfgSec = preg_replace('/[^a-z]/', '', (string) ($_GET['sec'] ?? 'politicas')) ?: 'politicas';
}
$finSec = 'cobrancas';
if ($tab === 'financeiro') {
    $finSec = preg_replace('/[^a-z]/', '', (string) ($_GET['sec'] ?? 'cobrancas')) ?: 'cobrancas';
    if (!in_array($finSec, ['cobrancas', 'assinaturas'], true)) {
        $finSec = 'cobrancas';
    }
}
$kpis = dashboard_platform_kpis();
$financeKpis = dashboard_finance_kpis();
$flashOk = (string) ($_SESSION['flash_ok'] ?? '');
$flashErr = (string) ($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

$pageTitle = match ($tab) {
    'empresas' => 'Empresas',
    'planos' => 'Planos',
    'financeiro' => 'Financeiro',
    'usuarios' => 'Usuários',
    'logs' => 'Logs',
    'instalador' => 'Instalador',
    'configuracoes' => 'Configurações',
    default => 'Dashboard',
};
$setupFile = installer_setup_path();
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> · Super Admin</title>
    <?php require __DIR__ . '/../partials/tw-head.php'; ?>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="admin-shell font-sans">
<?php
$superNavItems = [
    ['dashboard', 'Dashboard', '/super', '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25a2.25 2.25 0 0 1-2.25-2.25v-2.25Z"/>'],
    ['empresas', 'Empresas', '/super/empresas', '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/>'],
    ['planos', 'Planos', '/super/planos', '<path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z"/>'],
    ['financeiro', 'Financeiro', '/super/financeiro/cobrancas', '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/>'],
    ['usuarios', 'Usuários', '/super/usuarios', '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/>'],
    ['logs', 'Logs', '/super/logs', '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'],
    ['instalador', 'Instalador', '/super/instalador', '<path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/>'],
    ['configuracoes', 'Configurações', '/super/configuracoes', '<path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>'],
];
?>
<aside id="app-sidebar" data-sidebar>
    <a class="admin-brand" href="/super">
        <img src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
        <div class="admin-brand-text">
            <strong>Wi-Fi da loja</strong>
            <span>Super Admin</span>
        </div>
    </a>
    <button type="button" id="app-hamburger" aria-label="Menu" aria-expanded="false">
        <span></span><span></span><span></span>
    </button>
    <nav data-nav>
        <?php foreach ($superNavItems as [$key, $label, $href, $icon]): ?>
            <a href="<?= $href ?>" class="admin-nav-link<?= $tab === $key ? ' is-active' : '' ?>">
                <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><?= $icon ?></svg>
                <?= h($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div data-foot>
        <a class="admin-signout" href="/sair">Sair</a>
    </div>
</aside>
<div class="admin-main">
    <header class="admin-top">
        <h1 class="admin-page-title"><?= h($pageTitle) ?></h1>
    </header>
    <main class="admin-page">
        <?php if ($flashErr): ?><div class="admin-alert admin-alert-error"><?= h($flashErr) ?></div><?php endif; ?>
        <?php if ($flashOk): ?><div class="admin-alert admin-alert-success"><?= h($flashOk) ?></div><?php endif; ?>

        <?php if ($tab === 'dashboard'): ?>
            <section class="admin-stat-panel">
                <div class="admin-stat-item">
                    <span class="admin-stat-label">Empresas</span>
                    <strong class="admin-stat-value"><?= (int) $kpis['companies'] ?></strong>
                </div>
                <div class="admin-stat-item">
                    <span class="admin-stat-label">Ativas</span>
                    <strong class="admin-stat-value"><?= (int) $kpis['companies_active'] ?></strong>
                </div>
                <div class="admin-stat-item">
                    <span class="admin-stat-label">MRR</span>
                    <strong class="admin-stat-value"><?= h(cents_label((int) $kpis['mrr_cents'])) ?></strong>
                </div>
                <div class="admin-stat-item">
                    <span class="admin-stat-label">Hotspots</span>
                    <strong class="admin-stat-value"><?= (int) $kpis['hotspots'] ?></strong>
                </div>
            </section>
            <section class="admin-stat-panel admin-stat-panel-secondary">
                <a href="/super/financeiro/cobrancas?status=pending" class="admin-stat-item admin-stat-link">
                    <span class="admin-stat-label">Cobranças em aberto</span>
                    <strong class="admin-stat-value"><?= (int) $financeKpis['pending_payments'] ?></strong>
                    <span class="admin-stat-meta"><?= h(cents_label((int) $financeKpis['pending_cents'])) ?></span>
                </a>
                <a href="/super/financeiro/assinaturas?filtro=atrasada" class="admin-stat-item admin-stat-link">
                    <span class="admin-stat-label">Assinaturas atrasadas</span>
                    <strong class="admin-stat-value"><?= (int) $financeKpis['overdue_subscriptions'] ?></strong>
                </a>
                <a href="/super/financeiro/assinaturas?filtro=suspensa" class="admin-stat-item admin-stat-link">
                    <span class="admin-stat-label">Assinaturas suspensas</span>
                    <strong class="admin-stat-value"><?= (int) $financeKpis['suspended_subscriptions'] ?></strong>
                </a>
            </section>

        <?php elseif ($tab === 'empresas'): ?>
            <?php $orphans = orphan_stores(); $companies = all_companies(); ?>
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
                        <?php foreach ($companies as $c): ?>
                            <?php
                            $s = company_subscription_effective((int) $c['id']) ?? company_subscription((int) $c['id']);
                            $billStatus = (string) ($s['billing_status'] ?? $s['status'] ?? '');
                            $billLabel = (string) ($s['billing_label'] ?? company_subscription_label($s));
                            $billTag = (string) ($s['tag_class'] ?? subscription_tag_class($billStatus));
                            ?>
                            <tr>
                                <td><strong><?= h((string) $c['trade_name']) ?></strong><br><small><?= h((string) $c['email']) ?></small></td>
                                <td><?= h((string) $c['status']) ?></td>
                                <td>
                                    <span class="tag <?= h($billTag) ?>"><?= h($billLabel) ?></span>
                                    <?php if (!empty($s['plan_name'])): ?><br><small><?= h((string) $s['plan_name']) ?></small><?php endif; ?>
                                    · <a href="/super/financeiro/cobrancas?empresa=<?= (int) $c['id'] ?>">Financeiro</a>
                                </td>
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

            <section class="card" style="margin-top:16px">
                <h2>Lojas legadas (sem empresa)</h2>
                <?php if ($orphans === []): ?>
                    <p class="hint">Nenhuma loja órfã. Todas já estão vinculadas a uma empresa SaaS.</p>
                <?php else: ?>
                    <p class="hint">Vincule a uma empresa existente ou promova cada loja a uma nova empresa (com login).</p>
                    <div class="table-wrap">
                        <table class="saas-table">
                            <thead><tr><th>Loja</th><th>Status</th><th>Vincular</th><th>Promover a empresa</th></tr></thead>
                            <tbody>
                            <?php foreach ($orphans as $o): ?>
                                <tr>
                                    <td>
                                        <strong><?= h((string) $o['name']) ?></strong>
                                        <?php if (($o['city'] ?? '') !== ''): ?><br><small><?= h((string) $o['city']) ?></small><?php endif; ?>
                                        <br><small>ID <?= (int) $o['id'] ?> · token <?= h(substr((string) $o['token'], 0, 8)) ?>…</small>
                                    </td>
                                    <td><?= h(subscription_label((string) ($o['billing_status'] ?? ''))) ?></td>
                                    <td>
                                        <form method="post" action="/super/empresas" class="form form-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="do" value="attach_store">
                                            <input type="hidden" name="store_id" value="<?= (int) $o['id'] ?>">
                                            <select name="company_id" required>
                                                <option value="">Empresa…</option>
                                                <?php foreach ($companies as $c): ?>
                                                    <option value="<?= (int) $c['id'] ?>"><?= h((string) $c['trade_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn ghost btn-sm" type="submit">Vincular</button>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="post" action="/super/empresas" class="form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="do" value="promote_store">
                                            <input type="hidden" name="store_id" value="<?= (int) $o['id'] ?>">
                                            <label>E-mail admin<input type="email" name="admin_email" required placeholder="dono@loja.com"></label>
                                            <label>Senha<input type="password" name="admin_pass" minlength="8" required></label>
                                            <label>Plano
                                                <select name="plan_code">
                                                    <?php foreach (all_plans() as $p): ?>
                                                        <?php if (empty($p['active'])) { continue; } ?>
                                                        <option value="<?= h((string) $p['code']) ?>" <?= ($p['code'] ?? '') === 'essencial' ? 'selected' : '' ?>><?= h((string) $p['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <button class="btn btn-sm" type="submit">Promover</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

        <?php elseif ($tab === 'financeiro'): ?>
            <?php require __DIR__ . '/super-financeiro-tab.php'; ?>

        <?php elseif ($tab === 'planos'): ?>
            <?php require __DIR__ . '/super-plans-tab.php'; ?>

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
                <div class="table-wrap">
                    <table class="saas-table">
                        <thead><tr><th>Quando</th><th>Ação</th><th>Usuário</th><th>Empresa</th><th>IP</th></tr></thead>
                        <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?= h((string) $log['created_at']) ?></td>
                                <td><?= h((string) $log['action']) ?></td>
                                <td><?= (int) ($log['actor_user_id'] ?? 0) ?: '—' ?></td>
                                <td><?= (int) ($log['company_id'] ?? 0) ?: '—' ?></td>
                                <td><?= h((string) ($log['ip'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$logs): ?><tr class="empty"><td colspan="5">Nenhum log ainda.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        <?php elseif ($tab === 'instalador'): ?>
            <section class="card card-narrow">
                <h2>Agente Windows</h2>
                <p class="hint">Pacote cloud para produção — bandeja, hotspot e DNS cativo, sem PHP/MySQL local. Gere com <code>installer\Empacotar.ps1</code>.</p>
                <?php if ($setupFile): ?>
                    <?php
                    $setupBytes = (int) filesize($setupFile);
                    $setupSize = $setupBytes >= 1048576
                        ? round($setupBytes / 1048576, 1) . ' MB'
                        : max(1, (int) round($setupBytes / 1024)) . ' KB';
                    ?>
                    <div class="actions row">
                        <a class="btn" href="/super/instalador/baixar">Baixar <?= h(installer_agent_filename()) ?></a>
                    </div>
                    <p class="hint"><?= h(basename($setupFile)) ?> · <?= h($setupSize) ?></p>
                <?php else: ?>
                    <p class="hint">Ainda não publicado. Envie o .exe abaixo ou copie para <code>storage/downloads/</code>.</p>
                <?php endif; ?>

                <form method="post" action="/super/instalador" class="form" enctype="multipart/form-data" style="margin-top:20px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="return_to" value="/super/instalador">
                    <label>Publicar <?= h(installer_agent_filename()) ?>
                        <input name="setup" type="file" accept=".exe,application/vnd.microsoft-portable-executable" required>
                    </label>
                    <button class="btn ghost" type="submit">Enviar arquivo</button>
                </form>
                <p class="hint">URL do painel: <code><?= h(guess_panel_url()) ?></code></p>
            </section>

        <?php elseif ($tab === 'configuracoes'): ?>
            <nav class="admin-config-nav">
                <?php foreach ([
                    ['politicas', 'Políticas SaaS'],
                    ['integracao', 'Pagamentos'],
                    ['whatsapp', 'WhatsApp'],
                    ['sistema', 'Sistema'],
                ] as [$secKey, $secLabel]): ?>
                    <a href="/super/configuracoes/<?= h($secKey) ?>"
                       class="admin-config-tab<?= $cfgSec === $secKey ? ' is-active' : '' ?>"><?= h($secLabel) ?></a>
                <?php endforeach; ?>
            </nav>
            <?php if ($cfgSec === 'sistema'): ?>
            <?php
                $migRows = migrations_status(db());
                $migPending = migrations_pending_count(db());
            ?>
            <section class="card">
                <h2>URL canônica do painel</h2>
                <p class="hint">Usada em links de cron, webhooks e no agente. Em produção use HTTPS. Deixe em branco só em localhost.</p>
                <form method="post" action="/super/migrations" class="form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="panel_url">
                    <input type="hidden" name="return_to" value="/super/configuracoes/sistema">
                    <label>URL do painel
                        <input name="panel_url" type="url" placeholder="https://wifidaloja.com.br" value="<?= h(setting('panel_url', (string) env('APP_URL', ''))) ?>">
                    </label>
                    <button class="btn" type="submit">Salvar URL</button>
                </form>
                <p class="hint">URL efetiva agora: <code><?= h(guess_panel_url()) ?></code></p>
            </section>
            <section class="card">
                <h2>Migrations do banco</h2>
                <p class="hint">Atualizações de schema rodam automaticamente ao abrir o painel. Use este painel ou <code>php scripts/migrate.php</code> no deploy.</p>
                <p>Driver: <strong><?= h(db_driver()) ?></strong>
                    · Pendentes: <strong><?= (int) $migPending ?></strong></p>
                <?php if ($migPending > 0): ?>
                    <form method="post" action="/super/migrations" class="form" style="margin:12px 0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="return_to" value="/super/configuracoes/sistema">
                        <button class="btn" type="submit">Aplicar pendentes agora</button>
                    </form>
                <?php else: ?>
                    <p class="hint">Schema atualizado.</p>
                <?php endif; ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Migration</th>
                            <th>Status</th>
                            <th>Aplicada em</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($migRows as $m): ?>
                            <tr>
                                <td><code><?= h($m['id']) ?></code></td>
                                <td><?= $m['status'] === 'applied' ? 'Aplicada' : 'Pendente' ?></td>
                                <td><?= h((string) ($m['applied_at'] ?? '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php elseif ($cfgSec === 'whatsapp'): ?>
            <section class="card card-narrow">
                <h2>WhatsApp (Evolution API)</h2>
                <p class="hint">Envio automático de mensagens para empresas e lojas legadas. Placeholders: <?= h(notification_placeholder_help()) ?>.</p>
                <form method="post" action="/super/whatsapp" class="form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="save">
                    <input type="hidden" name="return_to" value="/super/configuracoes/whatsapp">
                    <label class="check"><input type="hidden" name="evolution_enabled" value="0"><input type="checkbox" name="evolution_enabled" value="1" <?= evolution_enabled() ? 'checked' : '' ?>> Ativar envio por WhatsApp</label>
                    <label>URL da Evolution API
                        <input name="evolution_base_url" value="<?= h(evolution_base_url()) ?>" placeholder="https://api.seudominio.com">
                    </label>
                    <label>Nome da instância
                        <input name="evolution_instance" value="<?= h(evolution_instance()) ?>" autocomplete="off" placeholder="minha-instancia">
                    </label>
                    <label>API key
                        <input name="evolution_api_key" type="password" autocomplete="off" placeholder="<?= evolution_api_key() !== '' ? h(evolution_mask_secret()) : 'Cole a apikey' ?>">
                    </label>
                    <p class="hint"><?= evolution_configured() ? 'API key salva (' . h(evolution_mask_secret()) . ').' : 'A chave não aparece inteira depois de salvar.' ?></p>
                    <label>Lembrete de trial (dias antes)
                        <input name="notify_trial_reminder_days" type="number" min="1" max="14" value="<?= (int) notification_trial_reminder_days() ?>">
                    </label>

                    <h3 class="form-section-title">Mensagens automáticas</h3>
                    <?php foreach (notification_events() as $event => $label): ?>
                        <fieldset class="notify-fieldset">
                            <legend class="notify-fieldset-legend">
                                <label class="check notify-fieldset-toggle"><input type="hidden" name="notify_on_<?= h($event) ?>" value="0"><input type="checkbox" name="notify_on_<?= h($event) ?>" value="1" <?= setting('notify_on_' . $event, '1') !== '0' ? 'checked' : '' ?>> <?= h($label) ?></label>
                            </legend>
                            <label>Mensagem
                                <textarea name="notify_tpl_<?= h($event) ?>" rows="5" placeholder="<?= h(notification_default_template($event)) ?>"><?= h(notification_template($event)) ?></textarea>
                            </label>
                        </fieldset>
                    <?php endforeach; ?>
                    <div class="form-actions">
                        <button class="btn" type="submit">Salvar WhatsApp</button>
                    </div>
                </form>
                <?php if (evolution_configured()): ?>
                    <form method="post" action="/super/whatsapp" class="form form-test">
                        <?= csrf_field() ?>
                        <input type="hidden" name="do" value="test">
                        <input type="hidden" name="return_to" value="/super/configuracoes/whatsapp">
                        <label>Telefone para teste (opcional)
                            <input name="test_phone" inputmode="tel" placeholder="11 99999-0000">
                        </label>
                        <button class="btn ghost" type="submit">Testar conexão<?= evolution_enabled() ? ' e enviar mensagem' : '' ?></button>
                    </form>
                <?php endif; ?>
            </section>
            <?php
            $msgLog = notification_recent_log(20);
            if ($msgLog !== []):
            ?>
            <section class="card admin-log-card">
                <h2>Últimos envios</h2>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Quando</th><th>Evento</th><th>Telefone</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($msgLog as $row): ?>
                            <tr>
                                <td><?= h((string) $row['created_at']) ?></td>
                                <td><?= h((string) (notification_events()[(string) $row['event_type']] ?? $row['event_type'])) ?></td>
                                <td><?= h((string) $row['phone']) ?></td>
                                <td><?= h((string) $row['status']) ?><?php if (!empty($row['error'])): ?> <span class="hint">— <?= h((string) $row['error']) ?></span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>
            <?php elseif ($cfgSec === 'integracao'): ?>
            <?php
            $payProvider = payment_provider();
            $pagseguroOk = pagseguro_configured();
            $picpayOk = picpay_configured();
            ?>
            <form method="post" action="/super/pagseguro" class="admin-pay-config-form">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="save">
                <input type="hidden" name="return_to" value="/super/configuracoes/integracao">

                <section class="card">
                    <h2>Operação</h2>
                    <p class="hint">Provedor em uso: <strong><?= h(payment_provider_label($payProvider)) ?></strong><?php if (payment_configured()): ?> · integração pronta<?php else: ?> · pendente de credenciais<?php endif; ?></p>
                    <div class="form form-grid-2" style="margin-top:0">
                        <label>Provedor ativo
                            <select name="payment_provider">
                                <option value="pagseguro" <?= $payProvider === 'pagseguro' ? 'selected' : '' ?>>PagSeguro / PagBank</option>
                                <option value="picpay" <?= $payProvider === 'picpay' ? 'selected' : '' ?>>PicPay</option>
                            </select>
                        </label>
                        <label>Antecedência (dias)
                            <input name="payment_advance_days" type="number" min="0" max="30" value="<?= (int) payment_advance_days() ?>">
                        </label>
                    </div>
                    <label class="check"><input type="hidden" name="payment_auto" value="0"><input type="checkbox" name="payment_auto" value="1" <?= payment_auto_enabled() ? 'checked' : '' ?>> Cobrança automática (empresas SaaS + lojas legadas)</label>
                    <p class="hint">O cron gera links antes do vencimento; a assinatura renova quando o webhook confirma o pagamento.</p>
                    <?php if (payment_configured()): ?>
                        <p class="hint">Webhook ativo: <code class="admin-code-break"><?= h(payment_webhook_url()) ?></code></p>
                        <p class="hint">Cron e testes em <a href="/super/financeiro/cobrancas">Financeiro → Cobranças</a>.</p>
                    <?php endif; ?>
                </section>

                <section class="card admin-pay-provider-card<?= $payProvider === 'pagseguro' ? ' is-active' : '' ?>">
                    <header class="admin-pay-provider-head">
                        <div>
                            <h2>PagSeguro / PagBank</h2>
                            <p class="hint">Checkout via token da API PagSeguro.</p>
                        </div>
                        <span class="badge <?= $pagseguroOk ? 'badge-ok' : 'badge-muted' ?>"><?= $pagseguroOk ? 'Configurado' : 'Pendente' ?></span>
                    </header>
                    <ol class="steps">
                        <li>Gere o token em PagSeguro / PagBank (sandbox ou produção).</li>
                        <li>Webhook: <code class="admin-code-break"><?= h(pagseguro_webhook_url()) ?></code></li>
                        <li>Cobranças SaaS usam referência <code>wlc-{empresa}-…</code></li>
                    </ol>
                    <div class="form form-grid-2" style="margin-top:0">
                        <label>Ambiente
                            <select name="pagseguro_env">
                                <option value="sandbox" <?= pagseguro_env() === 'sandbox' ? 'selected' : '' ?>>Sandbox</option>
                                <option value="production" <?= pagseguro_env() === 'production' ? 'selected' : '' ?>>Produção</option>
                            </select>
                        </label>
                        <label>Token da API
                            <input name="pagseguro_token" type="password" autocomplete="off" placeholder="<?= $pagseguroOk ? h(pagseguro_mask_token()) : 'Cole o token' ?>">
                        </label>
                    </div>
                    <p class="hint"><?= $pagseguroOk ? 'Token salvo (' . h(pagseguro_mask_token()) . ').' : 'O token não aparece inteiro depois de salvar.' ?></p>
                </section>

                <section class="card admin-pay-provider-card<?= $payProvider === 'picpay' ? ' is-active' : '' ?>">
                    <header class="admin-pay-provider-head">
                        <div>
                            <h2>PicPay E-commerce</h2>
                            <p class="hint">Carteira E-commerce com client_id, secret e seller token.</p>
                        </div>
                        <span class="badge <?= $picpayOk ? 'badge-ok' : 'badge-muted' ?>"><?= $picpayOk ? 'Configurado' : 'Pendente' ?></span>
                    </header>
                    <ol class="steps">
                        <li>Ative Carteira E-commerce no painel PicPay e gere credenciais.</li>
                        <li>Webhook (callback): <code class="admin-code-break"><?= h(picpay_webhook_url()) ?></code></li>
                        <li>Use o mesmo <code>x-seller-token</code> no campo abaixo.</li>
                    </ol>
                    <div class="form" style="margin-top:0">
                        <label>Ambiente
                            <select name="picpay_env">
                                <option value="sandbox" <?= picpay_env() === 'sandbox' ? 'selected' : '' ?>>Sandbox</option>
                                <option value="production" <?= picpay_env() === 'production' ? 'selected' : '' ?>>Produção</option>
                            </select>
                        </label>
                        <div class="form-grid-2">
                            <label>Client ID
                                <input name="picpay_client_id" value="<?= h(picpay_client_id()) ?>" autocomplete="off">
                            </label>
                            <label>Client secret
                                <input name="picpay_client_secret" type="password" autocomplete="off" placeholder="<?= picpay_client_secret() !== '' ? h(picpay_mask_secret(picpay_client_secret())) : 'Cole o secret' ?>">
                            </label>
                        </div>
                        <label>x-seller-token (webhook)
                            <input name="picpay_seller_token" type="password" autocomplete="off" placeholder="<?= picpay_seller_token() !== '' ? h(picpay_mask_secret(picpay_seller_token())) : 'Token do callback' ?>">
                        </label>
                    </div>
                </section>

                <div class="form-actions">
                    <button class="btn" type="submit">Salvar integração</button>
                </div>
            </form>
            <?php else: ?>
            <section class="card card-narrow">
                <h2>Políticas SaaS</h2>
                <form method="post" action="/super/politicas" class="form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="return_to" value="/super/configuracoes/politicas">
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
        <?php endif; ?>
    </main>
</div>
<?php require __DIR__ . '/../partials/admin-shell.js.php'; ?>
</body>
</html>
