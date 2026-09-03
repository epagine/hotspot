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
    <?php require __DIR__ . '/../partials/tw-head.php'; ?>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="font-sans bg-surface text-ink min-h-screen grid grid-cols-1 lg:grid-cols-[260px_1fr]">
<?php
$superNavItems = [
    ['dashboard', 'Dashboard', '/super', '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25a2.25 2.25 0 0 1-2.25-2.25v-2.25Z"/>'],
    ['empresas', 'Empresas', '/super/empresas', '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/>'],
    ['planos', 'Planos', '/super/planos', '<path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z"/>'],
    ['assinaturas', 'Assinaturas', '/super/assinaturas', '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/>'],
    ['usuarios', 'Usuários', '/super/usuarios', '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/>'],
    ['logs', 'Logs', '/super/logs', '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'],
    ['instalador', 'Instalador', '/super/instalador', '<path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/>'],
    ['configuracoes', 'Configurações', '/super/configuracoes', '<path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>'],
];
?>
<aside id="app-sidebar" class="bg-white border-r border-line p-4 flex flex-col gap-6 sticky top-0 h-screen overflow-y-auto max-lg:h-auto max-lg:sticky max-lg:z-20 max-lg:flex-row max-lg:flex-wrap max-lg:items-center max-lg:gap-3 max-lg:p-3 max-lg:border-b max-lg:border-r-0 transition-all" data-sidebar>
    <a class="flex items-center gap-3 no-underline text-inherit" href="/super">
        <img class="w-10 h-10 rounded-[10px] bg-white object-cover object-left-center flex-shrink-0" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
        <div class="max-lg:hidden">
            <strong class="block text-sm">Wi-Fi da loja</strong>
            <span class="text-xs text-muted">Super Admin</span>
        </div>
    </a>
    <button type="button" id="app-hamburger" aria-label="Menu" aria-expanded="false"
            class="hidden max-lg:flex ml-auto flex-col gap-[5px] items-center justify-center p-1.5 bg-transparent border-0 cursor-pointer">
        <span class="block w-[22px] h-[2px] bg-ink rounded-sm transition-transform"></span>
        <span class="block w-[22px] h-[2px] bg-ink rounded-sm transition-opacity"></span>
        <span class="block w-[22px] h-[2px] bg-ink rounded-sm transition-transform"></span>
    </button>
    <nav class="flex flex-col gap-1 flex-1 max-lg:hidden" data-nav>
        <?php foreach ($superNavItems as [$key, $label, $href, $icon]): ?>
            <a href="<?= $href ?>" class="flex items-center gap-2.5 px-3 py-2.5 rounded-[10px] text-sm font-semibold no-underline transition
                <?= $tab === $key ? 'bg-accent/10 text-accent' : 'text-muted hover:bg-hover hover:text-ink' ?>">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><?= $icon ?></svg>
                <?= h($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="border-t border-line pt-3 max-lg:hidden" data-foot>
        <a class="inline-block text-sm font-semibold text-muted border border-line rounded-btn px-3 py-2 hover:text-ink hover:border-ink/20 transition no-underline" href="/sair">Sair</a>
    </div>
</aside>
<div class="min-w-0 flex flex-col">
    <header class="px-8 pt-6 pb-0 max-md:px-4">
        <h1 class="text-2xl font-bold tracking-tight"><?= h($pageTitle) ?></h1>
        <p class="text-muted text-sm mt-1">Administração da plataforma.</p>
    </header>
    <main class="px-8 py-6 max-w-[1180px] w-full max-md:px-4">
        <?php if ($flashErr): ?><div class="bg-danger-bg text-danger border border-danger/20 rounded-xl px-4 py-3 text-sm mb-4"><?= h($flashErr) ?></div><?php endif; ?>
        <?php if ($flashOk): ?><div class="bg-ok-bg text-ok border border-ok/20 rounded-xl px-4 py-3 text-sm mb-4"><?= h($flashOk) ?></div><?php endif; ?>

        <?php if ($tab === 'dashboard'): ?>
            <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-3 mb-4">
                <?php foreach ([
                    ['Empresas', (int) $kpis['companies']],
                    ['Ativas', (int) $kpis['companies_active']],
                    ['Trials', (int) $kpis['trials']],
                    ['Assinaturas', (int) $kpis['subscriptions']],
                    ['MRR', cents_label((int) $kpis['mrr_cents'])],
                    ['Hotspots', (int) $kpis['hotspots']],
                ] as [$kLabel, $kVal]): ?>
                    <article class="bg-white border border-line rounded-xl p-4 shadow-sm">
                        <span class="block text-xs text-muted mb-1"><?= h($kLabel) ?></span>
                        <strong class="block text-xl font-bold tracking-tight"><?= h((string) $kVal) ?></strong>
                    </article>
                <?php endforeach; ?>
            </div>

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

        <?php elseif ($tab === 'planos'): ?>
            <?php require __DIR__ . '/super-plans-tab.php'; ?>

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
                    <input type="hidden" name="return_to" value="/super/instalador">
                    <label>Publicar .exe
                        <input name="setup" type="file" accept=".exe,application/vnd.microsoft-portable-executable" required>
                    </label>
                    <button class="btn <?= $setupReady ? 'ghost' : '' ?>" type="submit"><?= $setupReady ? 'Substituir arquivo' : 'Enviar arquivo' ?></button>
                </form>
                <p class="hint">URL do painel: <code><?= h(guess_panel_url()) ?></code></p>
            </section>

        <?php elseif ($tab === 'configuracoes'): ?>
            <nav class="flex gap-2 mb-5 overflow-x-auto pb-1">
                <?php foreach ([
                    ['politicas', 'Políticas SaaS'],
                    ['integracao', 'Pagamentos'],
                    ['whatsapp', 'WhatsApp'],
                    ['sistema', 'Sistema'],
                ] as [$secKey, $secLabel]): ?>
                    <a href="/super/configuracoes/<?= h($secKey) ?>"
                       class="flex-shrink-0 text-sm font-semibold px-4 py-2 rounded-full border no-underline transition
                           <?= $cfgSec === $secKey ? 'bg-accent/10 text-accent border-accent/40' : 'bg-white text-muted border-line hover:text-ink' ?>"><?= h($secLabel) ?></a>
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

                    <h3 style="margin-top:24px">Mensagens automáticas</h3>
                    <?php foreach (notification_events() as $event => $label): ?>
                        <fieldset style="margin:16px 0;padding:12px;border:1px solid var(--border,#ddd);border-radius:8px">
                            <legend><label class="check" style="font-weight:600"><input type="hidden" name="notify_on_<?= h($event) ?>" value="0"><input type="checkbox" name="notify_on_<?= h($event) ?>" value="1" <?= setting('notify_on_' . $event, '1') !== '0' ? 'checked' : '' ?>> <?= h($label) ?></label></legend>
                            <label>Mensagem
                                <textarea name="notify_tpl_<?= h($event) ?>" rows="5" placeholder="<?= h(notification_default_template($event)) ?>"><?= h(notification_template($event)) ?></textarea>
                            </label>
                        </fieldset>
                    <?php endforeach; ?>
                    <button class="btn" type="submit">Salvar WhatsApp</button>
                </form>
                <?php if (evolution_configured()): ?>
                    <form method="post" action="/super/whatsapp" class="form" style="margin-top:16px">
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
            <section class="card" style="margin-top:16px">
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
            <?php $payProvider = payment_provider(); ?>
            <section class="card card-narrow">
                <h2>Pagamentos online</h2>
                <p class="hint">Provedor ativo: <strong><?= h(payment_provider_label($payProvider)) ?></strong></p>
                <form method="post" action="/super/pagseguro" class="form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="save">
                    <input type="hidden" name="return_to" value="/super/configuracoes/integracao">
                    <label>Provedor ativo
                        <select name="payment_provider">
                            <option value="pagseguro" <?= $payProvider === 'pagseguro' ? 'selected' : '' ?>>PagSeguro / PagBank</option>
                            <option value="picpay" <?= $payProvider === 'picpay' ? 'selected' : '' ?>>PicPay</option>
                        </select>
                    </label>
                    <label class="check"><input type="hidden" name="payment_auto" value="0"><input type="checkbox" name="payment_auto" value="1" <?= payment_auto_enabled() ? 'checked' : '' ?>> Cobrança automática (empresas SaaS + lojas legadas)</label>
                    <p class="hint">O cron gera links de pagamento antes do vencimento; o cliente paga manualmente a cada ciclo. A assinatura renova quando o webhook confirma o pagamento.</p>
                    <label>Antecedência (dias)
                        <input name="payment_advance_days" type="number" min="0" max="30" value="<?= (int) payment_advance_days() ?>">
                    </label>

                    <h3 style="margin-top:24px">PagSeguro / PagBank</h3>
                    <ol class="steps">
                        <li>Token em PagSeguro / PagBank (sandbox ou produção).</li>
                        <li>Webhook: <code><?= h(pagseguro_webhook_url()) ?></code></li>
                        <li>Cobranças SaaS usam referência <code>wlc-{empresa}-…</code></li>
                    </ol>
                    <label>Ambiente PagSeguro
                        <select name="pagseguro_env">
                            <option value="sandbox" <?= pagseguro_env() === 'sandbox' ? 'selected' : '' ?>>Sandbox</option>
                            <option value="production" <?= pagseguro_env() === 'production' ? 'selected' : '' ?>>Produção</option>
                        </select>
                    </label>
                    <label>Token da API
                        <input name="pagseguro_token" type="password" autocomplete="off" placeholder="<?= pagseguro_configured() ? h(pagseguro_mask_token()) : 'Cole o token' ?>">
                    </label>
                    <p class="hint"><?= pagseguro_configured() ? 'Token salvo (' . h(pagseguro_mask_token()) . ').' : 'O token não aparece inteiro depois de salvar.' ?></p>

                    <h3 style="margin-top:24px">PicPay E-commerce</h3>
                    <ol class="steps">
                        <li>Ative Carteira E-commerce no painel PicPay e gere credenciais.</li>
                        <li>Webhook (callback): <code><?= h(picpay_webhook_url()) ?></code></li>
                        <li>Use o mesmo <code>x-seller-token</code> no campo abaixo.</li>
                    </ol>
                    <label>Ambiente PicPay
                        <select name="picpay_env">
                            <option value="sandbox" <?= picpay_env() === 'sandbox' ? 'selected' : '' ?>>Sandbox</option>
                            <option value="production" <?= picpay_env() === 'production' ? 'selected' : '' ?>>Produção</option>
                        </select>
                    </label>
                    <label>Client ID
                        <input name="picpay_client_id" value="<?= h(picpay_client_id()) ?>" autocomplete="off">
                    </label>
                    <label>Client secret
                        <input name="picpay_client_secret" type="password" autocomplete="off" placeholder="<?= picpay_client_secret() !== '' ? h(picpay_mask_secret(picpay_client_secret())) : 'Cole o secret' ?>">
                    </label>
                    <label>x-seller-token (webhook)
                        <input name="picpay_seller_token" type="password" autocomplete="off" placeholder="<?= picpay_seller_token() !== '' ? h(picpay_mask_secret(picpay_seller_token())) : 'Token do callback' ?>">
                    </label>
                    <button class="btn" type="submit">Salvar integração</button>
                </form>
                <?php if (payment_configured()): ?>
                    <p class="hint">Webhook ativo: <code><?= h(payment_webhook_url()) ?></code></p>
                    <p class="hint">Cron diário:<br><code><?= h(payment_cron_url()) ?></code></p>
                    <div class="actions row">
                        <form method="post" action="/super/pagseguro">
                            <?= csrf_field() ?>
                            <input type="hidden" name="do" value="test">
                            <input type="hidden" name="return_to" value="/super/configuracoes/integracao">
                            <button class="btn ghost" type="submit">Testar <?= h(payment_provider_label()) ?></button>
                        </form>
                        <form method="post" action="/super/pagseguro">
                            <?= csrf_field() ?>
                            <input type="hidden" name="do" value="run">
                            <input type="hidden" name="return_to" value="/super/configuracoes/integracao">
                            <button class="btn ghost" type="submit">Gerar cobranças agora</button>
                        </form>
                    </div>
                <?php endif; ?>
            </section>
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
<script>
(function(){
  var btn=document.getElementById('app-hamburger'),side=document.getElementById('app-sidebar');
  if(!btn||!side)return;
  var nav=side.querySelector('[data-nav]'),foot=side.querySelector('[data-foot]');
  btn.addEventListener('click',function(){
    var open=!nav.classList.contains('max-lg:hidden')||nav.classList.contains('!flex');
    if(open){nav.classList.remove('!flex','!flex-col');nav.classList.add('max-lg:hidden');if(foot)foot.classList.add('max-lg:hidden');}
    else{nav.classList.add('!flex','!flex-col');nav.classList.remove('max-lg:hidden');if(foot){foot.classList.remove('max-lg:hidden');}}
    btn.setAttribute('aria-expanded',(!open)?'true':'false');
  });
  side.querySelectorAll('[data-nav] a').forEach(function(a){
    a.addEventListener('click',function(){nav.classList.add('max-lg:hidden');if(foot)foot.classList.add('max-lg:hidden');btn.setAttribute('aria-expanded','false');});
  });
})();
</script>
</body>
</html>
