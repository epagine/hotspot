<?php

declare(strict_types=1);

require_company_access('dashboard');

$company = current_company();
if (!$company) {
    header('Location: /entrar');
    exit;
}
$companyId = (int) $company['id'];
$tab = preg_replace('/[^a-z]/', '', (string) ($_GET['tab'] ?? 'dashboard')) ?: 'dashboard';
$allowed = ['dashboard', 'empresa', 'hotspots', 'clientes', 'acessos', 'campanhas', 'cupons', 'relatorios', 'usuarios', 'assinatura'];
if (!in_array($tab, $allowed, true)) {
    $tab = 'dashboard';
}
$permMap = [
    'dashboard' => 'dashboard',
    'empresa' => 'company',
    'hotspots' => 'hotspots',
    'clientes' => 'clients',
    'acessos' => 'access',
    'campanhas' => 'campaigns',
    'cupons' => 'coupons',
    'relatorios' => 'reports',
    'usuarios' => 'users',
    'assinatura' => 'billing',
];
require_company_access($permMap[$tab] ?? 'dashboard');

$sub = company_subscription_effective($companyId) ?? company_subscription($companyId);
$serviceOk = $sub === null || (bool) ($sub['service_allowed'] ?? true);
$kpis = dashboard_company_kpis($companyId);
$limitUsage = company_limit_usage($companyId);
$chart = dashboard_access_by_day($companyId, 7);
$user = current_user();
$flashOk = (string) ($_SESSION['flash_ok'] ?? '');
$flashErr = (string) ($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

$pageTitle = match ($tab) {
    'empresa' => 'Empresa',
    'hotspots' => 'Hotspots',
    'clientes' => 'Clientes',
    'acessos' => 'Acessos',
    'campanhas' => 'Campanhas',
    'cupons' => 'Cupons',
    'relatorios' => 'Relatórios',
    'usuarios' => 'Usuários',
    'assinatura' => 'Assinatura',
    default => 'Dashboard',
};

$appNavItems = [
    ['label' => 'Principal'],
    ['dashboard', 'Dashboard', '/app/dashboard', 'dashboard', null, '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25a2.25 2.25 0 0 1-2.25-2.25v-2.25Z"/>'],
    ['hotspots', 'Hotspots', '/app/hotspots', 'hotspots', null, '<path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 0 1 7.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 0 1 1.06 0Z"/>'],
    ['agente', 'Agente Windows', '/app/instalador/baixar', 'hotspots', null, '<path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/>'],
];
$appNavItems = array_merge($appNavItems, [
    ['clientes', 'Clientes', '/app/clientes', 'clients', null, '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/>'],
    ['acessos', 'Acessos', '/app/acessos', 'access', null, '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z"/>'],
    ['label' => 'Marketing'],
    ['campanhas', 'Campanhas', '/app/campanhas', 'campaigns', 'campaigns', '<path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 0 8.835-2.535m0 0A23.74 23.74 0 0 0 18.795 3m.38 1.125a23.91 23.91 0 0 1 1.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 0 0 1.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 0 1 0 3.46"/>'],
    ['cupons', 'Cupons', '/app/cupons', 'coupons', 'coupons', '<path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 0 1 0 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 0 1 0-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z"/>'],
    ['relatorios', 'Relatórios', '/app/relatorios', 'reports', 'reports', '<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/>'],
    ['label' => 'Conta'],
    ['empresa', 'Empresa', '/app/empresa', 'company', null, '<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 0 0 3.75.614m-16.5 0a3.004 3.004 0 0 1-.621-4.72l1.189-1.19A1.5 1.5 0 0 1 5.378 3h13.243a1.5 1.5 0 0 1 1.06.44l1.19 1.189a3 3 0 0 1-.621 4.72M6.75 18h3.75a.75.75 0 0 0 .75-.75V13.5a.75.75 0 0 0-.75-.75H6.75a.75.75 0 0 0-.75.75v3.75c0 .414.336.75.75.75Z"/>'],
    ['usuarios', 'Usuários', '/app/usuarios', 'users', null, '<path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z"/>'],
    ['assinatura', 'Assinatura', '/app/assinatura', 'billing', null, '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/>'],
]);
function app_nav_tw(string $tab, array $items): void
{
    foreach ($items as $item) {
        if (isset($item['label'])) {
            echo '<div class="admin-nav-label">' . h($item['label']) . '</div>';
            continue;
        }
        [$key, $label, $href, $perm, $feature, $icon] = $item;
        if (!user_can($perm)) continue;
        if ($feature !== null && !company_has_feature(current_company_id(), $feature)) continue;
        $active = $tab === $key;
        $cls = 'admin-nav-link' . ($active ? ' is-active' : '');
        echo '<a href="' . h($href) . '" class="' . $cls . '">'
            . '<svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">' . $icon . '</svg>'
            . h($label) . '</a>';
    }
}
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> · <?= h((string) $company['trade_name']) ?></title>
    <?php require __DIR__ . '/../partials/tw-head.php'; ?>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="admin-shell font-sans">
<aside id="app-sidebar" data-sidebar>
    <a class="admin-brand" href="/app">
        <img src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
        <div class="admin-brand-text">
            <strong><?= h((string) $company['trade_name']) ?></strong>
            <span>Painel da empresa</span>
        </div>
    </a>
    <button type="button" id="app-hamburger" aria-label="Menu" aria-expanded="false">
        <span></span><span></span><span></span>
    </button>
    <nav data-nav>
        <?php app_nav_tw($tab, $appNavItems); ?>
    </nav>
    <div data-foot>
        <div class="admin-user-label"><?= h((string) ($user['name'] ?? $user['email'] ?? '')) ?></div>
        <a class="admin-signout" href="/sair">Sair</a>
    </div>
</aside>
<div class="admin-main">
    <header class="admin-top">
        <h1 class="admin-page-title"><?= h($pageTitle) ?></h1>
        <?php if ($sub): ?>
        <p class="admin-page-lead">
            <?php if (($sub['billing_status'] ?? $sub['status'] ?? '') === 'trial'): ?>
                Trial até <?= h(date('d/m/Y', strtotime((string) $sub['trial_ends_at']) ?: time())) ?> · <?= h((string) ($sub['plan_name'] ?? '')) ?>
            <?php else: ?>
                <?= h((string) ($sub['billing_label'] ?? company_subscription_label($sub))) ?> · <?= h((string) ($sub['plan_name'] ?? '')) ?>
            <?php endif; ?>
        </p>
        <?php endif; ?>
    </header>
    <main class="admin-page">
        <?php if ($flashErr): ?><div class="admin-alert admin-alert-error"><?= h($flashErr) ?></div><?php endif; ?>
        <?php if ($flashOk): ?><div class="admin-alert admin-alert-success"><?= h($flashOk) ?></div><?php endif; ?>
        <?php if (!$serviceOk && $tab !== 'assinatura'): ?>
            <div class="admin-alert admin-alert-error">Sua assinatura está <?= h((string) ($sub['billing_label'] ?? 'inativa')) ?>. Escolha um plano em <a href="/app/assinatura">Assinatura</a> para continuar.</div>
        <?php endif; ?>

        <?php if ($tab === 'dashboard'): ?>
            <section class="admin-stat-panel">
                <?php foreach ([
                    ['Clientes', plan_usage_label($limitUsage['clients']['used'], $limitUsage['clients']['max'])],
                    ['Acessos hoje', (string) (int) $kpis['access_today']],
                    ['7 dias', (string) (int) $kpis['access_7d']],
                    ['Hotspots', plan_usage_label($limitUsage['hotspots']['used'], $limitUsage['hotspots']['max'])],
                    ['Usuários', plan_usage_label($limitUsage['users']['used'], $limitUsage['users']['max'])],
                ] as [$kLabel, $kVal]): ?>
                <div class="admin-stat-item">
                    <span class="admin-stat-label"><?= h($kLabel) ?></span>
                    <strong class="admin-stat-value"><?= h($kVal) ?></strong>
                </div>
                <?php endforeach; ?>
            </section>
            <section class="card">
                <h2>Acessos nos últimos 7 dias</h2>
                <?php
                $max = max(1, ...array_map(static fn ($r) => (int) $r['total'], $chart));
                ?>
                <div class="lp-chart" style="display:flex;align-items:flex-end;gap:10px;height:180px;margin-top:16px">
                    <?php foreach ($chart as $bar): ?>
                        <?php $hgt = (int) round(((int) $bar['total'] / $max) * 140); ?>
                        <div style="flex:1;text-align:center">
                            <div style="height:140px;display:flex;align-items:flex-end;justify-content:center">
                                <div title="<?= (int) $bar['total'] ?>" style="width:100%;max-width:36px;height:<?= max(4, $hgt) ?>px;background:var(--accent);border-radius:6px 6px 0 0"></div>
                            </div>
                            <small><?= h($bar['label']) ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

        <?php elseif ($tab === 'empresa'): ?>
            <section class="card card-narrow">
                <form method="post" action="/app/empresa" class="form">
                    <?= csrf_field() ?>
                    <label>Nome fantasia<input name="trade_name" value="<?= h((string) $company['trade_name']) ?>" required></label>
                    <label>Razão social<input name="legal_name" value="<?= h((string) $company['legal_name']) ?>"></label>
                    <label>CNPJ/CPF<input name="document" value="<?= h((string) $company['document']) ?>"></label>
                    <label>Telefone<input name="phone" value="<?= h((string) $company['phone']) ?>"></label>
                    <label>WhatsApp<input name="whatsapp" value="<?= h((string) $company['whatsapp']) ?>"></label>
                    <label>E-mail<input name="email" type="email" value="<?= h((string) $company['email']) ?>"></label>
                    <label>Endereço<textarea name="address" rows="2"><?= h((string) $company['address']) ?></textarea></label>
                    <label>Cidade<input name="city" value="<?= h((string) $company['city']) ?>"></label>
                    <label>Estado<input name="state" value="<?= h((string) $company['state']) ?>" maxlength="2"></label>
                    <label>Cor principal<input name="primary_color" type="color" value="<?= h((string) ($company['primary_color'] ?: '#c8892a')) ?>"></label>
                    <label>Cor secundária<input name="secondary_color" type="color" value="<?= h((string) ($company['secondary_color'] ?: '#15202b')) ?>"></label>
                    <button class="btn" type="submit">Salvar empresa</button>
                </form>
            </section>

        <?php elseif ($tab === 'hotspots'): ?>
            <?php
            $hid = (int) ($_GET['id'] ?? 0);
            $hot = $hid > 0 ? find_store($hid) : null;
            $editing = $hot && (int) ($hot['company_id'] ?? 0) === $companyId;
            if ($editing):
                $pc = portal_config_for((int) $hot['id']);
                $health = store_connection_health($hot);
                $agentStatus = store_status_payload($hot);
                $agentVer = agent_version_info((string) ($agentStatus['agent_version'] ?? ''));
                $agentDiag = store_agent_diagnostic_summary($hot);
                $agentLog = store_agent_event_log($hot);
                $setupReady = installer_setup_path() !== null;
                $panelUrl = rtrim(guess_panel_url(), '/');
                $approval = setting_for_store((int) $hot['id'], 'approval_mode', 'instant');
            ?>
            <section class="card hotspot-edit">
                <header class="hotspot-edit-header">
                    <a class="btn ghost btn-sm hotspot-edit-back" href="/app/hotspots">← Hotspots</a>
                    <div class="hotspot-edit-heading">
                        <h2><?= h((string) $hot['name']) ?></h2>
                        <p class="hint">
                            <?php if (trim((string) ($hot['city'] ?? '')) !== ''): ?><?= h((string) $hot['city']) ?> · <?php endif; ?>
                            <a href="/portal/<?= h((string) $hot['token']) ?>" target="_blank" rel="noopener">Abrir portal cativo</a>
                        </p>
                    </div>
                    <div class="hotspot-edit-badges">
                        <span class="tag conn-<?= h((string) $health['key']) ?>"><?= h((string) $health['label']) ?></span>
                        <span class="tag <?= (($hot['hotspot_status'] ?? 'ativo') === 'ativo') ? 'online' : 'blocked' ?>"><?= h((string) ($hot['hotspot_status'] ?? 'ativo')) ?></span>
                        <span class="tag<?= $agentVer['outdated'] ? ' conn-erro' : '' ?>"><?= h($agentVer['label']) ?></span>
                    </div>
                </header>

                <div class="hotspot-edit-layout">
                    <aside class="hotspot-edit-side">
                        <div class="hotspot-panel">
                            <h3 class="hotspot-panel-title">Status do PC</h3>
                            <p class="hint hotspot-panel-lead"><?= h((string) $health['detail']) ?></p>
                            <?php if (!empty($hot['last_seen_at'])): ?>
                                <p class="hint">Último contato: <?= h((string) $hot['last_seen_at']) ?></p>
                            <?php endif; ?>
                            <details class="agent-diag" <?= ($health['key'] !== 'ok' || !empty($agentStatus['sync_error'] ?? '') || !empty($agentStatus['error'] ?? '')) ? 'open' : '' ?>>
                                <summary>Diagnóstico<?php if ($health['key'] !== 'ok'): ?> — atenção<?php endif; ?></summary>
                                <div class="agent-diag-grid">
                                    <?php foreach ($agentDiag as $row): ?>
                                        <div class="agent-diag-row">
                                            <span class="agent-diag-label"><?= h((string) $row['label']) ?></span>
                                            <span class="agent-diag-value<?= ($row['ok'] === false) ? ' agent-diag-bad' : (($row['ok'] === true) ? ' agent-diag-ok' : '') ?>"><?= h((string) $row['value']) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($agentLog): ?>
                                    <h4 class="agent-diag-log-title">Log recente</h4>
                                    <ul class="agent-diag-log">
                                        <?php foreach ($agentLog as $ev): ?>
                                            <li class="agent-diag-log-<?= h((string) $ev['level']) ?>">
                                                <?php if ($ev['at'] !== ''): ?><time><?= h(str_replace('T', ' ', $ev['at'])) ?></time><?php endif; ?>
                                                <?= h((string) $ev['msg']) ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php elseif (!$agentStatus['agent_alive']): ?>
                                    <p class="hint">Nenhum log — agente parado ou ainda não sincronizou.</p>
                                <?php endif; ?>
                            </details>
                        </div>

                        <div class="hotspot-panel">
                            <h3 class="hotspot-panel-title">Agente Windows</h3>
                            <ol class="hotspot-install-steps">
                                <li>Baixe e execute como administrador<?php if ($setupReady): ?> — <a href="/app/instalador/baixar">Baixar setup</a><?php else: ?> (indisponível)<?php endif; ?></li>
                                <li>URL do painel: <code class="admin-code-break"><?= h($panelUrl) ?></code></li>
                                <li>Cole o token na instalação ou em <em>Vincular hotspot</em> na bandeja</li>
                            </ol>
                            <dl class="hotspot-meta">
                                <dt>Token</dt>
                                <dd><code class="token"><?= h((string) $hot['token']) ?></code></dd>
                                <dt>SSID atual</dt>
                                <dd><?= h(setting_for_store((int) $hot['id'], 'wifi_ssid', 'WifiDaLoja')) ?></dd>
                            </dl>
                            <form method="post" action="/app/hotspots" class="hotspot-danger-action" onsubmit="return confirm('Gerar novo token? O PC precisará ser revinculado na bandeja.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="do" value="rotate">
                                <input type="hidden" name="id" value="<?= (int) $hot['id'] ?>">
                                <button class="btn ghost btn-sm" type="submit">Renovar token</button>
                            </form>
                        </div>
                    </aside>

                    <div class="hotspot-edit-main">
                        <form method="post" action="/app/hotspots" class="form hotspot-edit-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="do" value="save">
                            <input type="hidden" name="id" value="<?= (int) $hot['id'] ?>">

                            <div class="hotspot-panel">
                                <h3 class="hotspot-panel-title">Identificação</h3>
                                <div class="form-grid-2">
                                    <label>Nome<input name="name" value="<?= h((string) $hot['name']) ?>" required></label>
                                    <label>Localização<input name="location" value="<?= h((string) ($hot['location'] ?? '')) ?>"></label>
                                </div>
                                <label>Descrição<textarea name="description" rows="2"><?= h((string) ($hot['description'] ?? '')) ?></textarea></label>
                            </div>

                            <div class="hotspot-panel">
                                <h3 class="hotspot-panel-title">Rede Wi-Fi</h3>
                                <div class="form-grid-2">
                                    <label>SSID<input name="ssid" value="<?= h(setting_for_store((int) $hot['id'], 'wifi_ssid', 'WifiDaLoja')) ?>"></label>
                                    <label>Senha<input name="wifi_pass" type="password" value="" autocomplete="new-password" placeholder="Deixe em branco para manter"></label>
                                    <label>Situação
                                        <select name="hotspot_status">
                                            <?php foreach (['ativo' => 'Ativo', 'inativo' => 'Inativo', 'bloqueado' => 'Bloqueado'] as $v => $l): ?>
                                                <option value="<?= h($v) ?>" <?= ($hot['hotspot_status'] ?? 'ativo') === $v ? 'selected' : '' ?>><?= h($l) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label>Provider
                                        <select name="provider">
                                            <?php foreach (network_providers() as $p): ?>
                                                <option value="<?= h($p->key()) ?>" <?= ($hot['provider'] ?? 'windows') === $p->key() ? 'selected' : '' ?>><?= h($p->label()) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                </div>
                            </div>

                            <div class="hotspot-panel">
                                <h3 class="hotspot-panel-title">Portal cativo</h3>
                                <p class="hint hotspot-panel-lead">Tela que o cliente vê ao conectar no Wi-Fi.</p>
                                <label>Título<input name="portal_title" value="<?= h((string) ($pc['title'] ?? 'Bem-vindo')) ?>"></label>
                                <label>Descrição<textarea name="portal_subtitle" rows="2"><?= h((string) ($pc['subtitle'] ?? '')) ?></textarea></label>
                                <label>Texto do botão<input name="portal_button" value="<?= h((string) ($pc['button_label'] ?? 'Continuar')) ?>"></label>
                                <div class="form-grid-2">
                                    <label>Modo de aprovação
                                        <select name="approval_mode">
                                            <?php foreach (['instant' => 'Automático', 'manual' => 'Manual (balcão)'] as $v => $l): ?>
                                                <option value="<?= h($v) ?>" <?= $approval === $v ? 'selected' : '' ?>><?= h($l) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label>Duração do acesso (h)<input name="session_hours" type="number" min="1" max="24" value="<?= h(setting_for_store((int) $hot['id'], 'session_hours', '2')) ?>"></label>
                                </div>
                                <label>Texto do status WhatsApp<textarea name="status_template" rows="2" placeholder="Estou na {loja}! Código {codigo}"><?= h(setting_for_store((int) $hot['id'], 'status_template', 'Estou na {loja} agora! 🔥 Venha conferir. Código {codigo}')) ?></textarea></label>
                                <p class="hint">Variáveis: {loja}, {codigo}, {cidade}</p>
                            </div>

                            <div class="hotspot-panel">
                                <h3 class="hotspot-panel-title">Termos legais</h3>
                                <label>Termos de uso<textarea name="terms_html" rows="3"><?= h((string) ($hot['terms_html'] ?? '')) ?></textarea></label>
                                <label>Política de privacidade<textarea name="privacy_html" rows="3"><?= h((string) ($hot['privacy_html'] ?? '')) ?></textarea></label>
                            </div>

                            <div class="hotspot-edit-actions">
                                <button class="btn" type="submit">Salvar alterações</button>
                                <a class="btn ghost" href="/app/hotspots">Cancelar</a>
                            </div>
                        </form>
                    </div>
                </div>
            </section>
            <?php else: ?>
            <?php
            $stmt = db()->prepare('SELECT * FROM stores WHERE company_id = ? ORDER BY id DESC');
            $stmt->execute([$companyId]);
            $hotspots = $stmt->fetchAll() ?: [];
            ?>
            <section class="card">
                <div class="card-head"><h2>Hotspots</h2>
                    <?php if (company_within_hotspot_limit($companyId)): ?>
                        <a class="btn btn-sm" href="/app/hotspots?novo=1">Novo hotspot</a>
                    <?php endif; ?>
                </div>
                <p class="hint">Uso do plano: <?= h(plan_usage_label($limitUsage['hotspots']['used'], $limitUsage['hotspots']['max'])) ?> hotspots.</p>
                <?php if (!company_within_hotspot_limit($companyId)): ?>
                    <p class="alert"><?= h(company_limit_error('hotspots')) ?></p>
                <?php endif; ?>
                <?php if (!empty($_GET['novo'])): ?>
                    <form method="post" action="/app/hotspots" class="form form-inline hotspot-create-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="do" value="create">
                        <label>Nome<input name="name" required placeholder="Loja Principal"></label>
                        <label>Cidade<input name="city"></label>
                        <label>SSID<input name="ssid" placeholder="WifiDaLoja"></label>
                        <button class="btn" type="submit">Criar</button>
                    </form>
                <?php endif; ?>
                <div class="table-wrap">
                    <table class="saas-table">
                        <thead><tr><th>Nome</th><th>SSID</th><th>PC</th><th>Status</th><th>Provider</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($hotspots as $h): ?>
                            <?php $health = store_connection_health($h); ?>
                            <tr>
                                <td><strong><?= h((string) $h['name']) ?></strong><br><small><?= h((string) ($h['city'] ?? '')) ?></small></td>
                                <td><?= h(setting_for_store((int) $h['id'], 'wifi_ssid', 'WifiDaLoja')) ?></td>
                                <td><span class="tag conn-<?= h((string) $health['key']) ?>"><?= h((string) $health['label']) ?></span></td>
                                <td><span class="tag <?= (($h['hotspot_status'] ?? 'ativo') === 'ativo') ? 'online' : 'blocked' ?>"><?= h((string) ($h['hotspot_status'] ?? 'ativo')) ?></span></td>
                                <td><?= h(network_provider((string) ($h['provider'] ?? 'windows'))->label()) ?></td>
                                <td><a class="btn ghost btn-sm" href="/app/hotspots/<?= (int) $h['id'] ?>">Editar</a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$hotspots): ?>
                            <tr class="empty"><td colspan="6">Nenhum hotspot. Crie o primeiro para começar.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

        <?php elseif ($tab === 'clientes'): ?>
            <?php
            $q = trim((string) ($_GET['q'] ?? ''));
            if ($q !== '') {
                $stmt = db()->prepare('SELECT * FROM clients WHERE company_id = ? AND (name LIKE ? OR phone LIKE ? OR email LIKE ?) ORDER BY id DESC LIMIT 200');
                $like = '%' . $q . '%';
                $stmt->execute([$companyId, $like, $like, $like]);
            } else {
                $stmt = db()->prepare('SELECT * FROM clients WHERE company_id = ? ORDER BY id DESC LIMIT 200');
                $stmt->execute([$companyId]);
            }
            $clients = $stmt->fetchAll() ?: [];
            ?>
            <section class="card">
                <p class="hint">Clientes cadastrados: <?= h(plan_usage_label($limitUsage['clients']['used'], $limitUsage['clients']['max'])) ?>.</p>
                <?php if (!company_within_client_limit($companyId)): ?>
                    <p class="alert"><?= h(company_limit_error('clients')) ?> Novos cadastros no portal ficarão bloqueados até o upgrade.</p>
                <?php endif; ?>
                <form method="get" action="/app/clientes" class="form-inline" style="margin-bottom:12px">
                    <label>Buscar<input name="q" value="<?= h($q) ?>" placeholder="Nome, WhatsApp ou e-mail"></label>
                    <button class="btn ghost" type="submit">Filtrar</button>
                </form>
                <div class="table-wrap">
                    <table class="saas-table">
                        <thead><tr><th>Cliente</th><th>WhatsApp</th><th>Código</th><th>Acessos</th><th>Último</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($clients as $c): ?>
                            <tr>
                                <td><strong><?= h((string) ($c['name'] ?: '—')) ?></strong></td>
                                <td><?= h((string) ($c['phone'] ?? '')) ?></td>
                                <td><?= h((string) ($c['status_code'] ?? '')) ?></td>
                                <td><?= (int) ($c['access_count'] ?? 1) ?></td>
                                <td><?= h((string) ($c['last_access_at'] ?: $c['created_at'] ?? '')) ?></td>
                                <td><?= !empty($c['blocked']) ? 'Bloqueado' : h((string) ($c['state'] ?? '')) ?></td>
                                <td>
                                    <?php if (($c['state'] ?? '') === 'awaiting_approval'): ?>
                                        <form method="post" action="/app/clientes" class="form-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="do" value="approve">
                                            <input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>">
                                            <button class="btn btn-sm" type="submit">Aprovar Wi-Fi</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$clients): ?><tr class="empty"><td colspan="7">Nenhum cliente ainda.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        <?php elseif ($tab === 'acessos'): ?>
            <?php
            $stmt = db()->prepare(
                'SELECT a.*, c.name AS client_name, s.name AS hotspot_name FROM access_sessions a
                 LEFT JOIN clients c ON c.id = a.client_id
                 LEFT JOIN stores s ON s.id = a.hotspot_id
                 WHERE a.company_id = ? ORDER BY a.id DESC LIMIT 200'
            );
            $stmt->execute([$companyId]);
            $sessions = $stmt->fetchAll() ?: [];
            ?>
            <section class="card">
                <div class="card-head">
                    <h2>Histórico</h2>
                    <a class="btn ghost btn-sm" href="/app/relatorios?export=access">Exportar CSV</a>
                </div>
                <div class="table-wrap">
                    <table class="saas-table">
                        <thead><tr><th>Quando</th><th>Cliente</th><th>Hotspot</th><th>Dispositivo</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($sessions as $s): ?>
                            <tr>
                                <td><?= h((string) $s['started_at']) ?></td>
                                <td><?= h((string) ($s['client_name'] ?: '—')) ?></td>
                                <td><?= h((string) ($s['hotspot_name'] ?: '—')) ?></td>
                                <td><?= h(trim(($s['device'] ?? '') . ' ' . ($s['os_name'] ?? ''))) ?></td>
                                <td><?= h((string) $s['auth_status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$sessions): ?><tr class="empty"><td colspan="5">Nenhum acesso registrado.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        <?php elseif ($tab === 'campanhas'): ?>
            <?php if (!company_has_feature($companyId, 'campaigns')): ?>
                <section class="card card-narrow">
                    <p class="alert"><?= h(company_feature_error('campaigns')) ?></p>
                    <a class="btn" href="/app/assinatura">Ver planos</a>
                </section>
            <?php else: ?>
            <?php $camps = company_campaigns($companyId); ?>
            <section class="card">
                <h2>Nova campanha</h2>
                <form method="post" action="/app/campanhas" class="form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="save">
                    <label>Nome<input name="name" required></label>
                    <label>Tipo
                        <select name="type">
                            <?php foreach (['banner','oferta','cupom','link','pesquisa'] as $t): ?>
                                <option value="<?= $t ?>"><?= h(ucfirst($t)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Título<input name="title"></label>
                    <label>Descrição<textarea name="description" rows="3"></textarea></label>
                    <label>Botão<input name="button_label" placeholder="Pegar meu cupom"></label>
                    <label>URL<input name="button_url"></label>
                    <label>Início<input type="date" name="starts_at"></label>
                    <label>Fim<input type="date" name="ends_at"></label>
                    <button class="btn" type="submit">Salvar campanha</button>
                </form>
            </section>
            <section class="card">
                <div class="table-wrap">
                    <table class="saas-table">
                        <thead><tr><th>Nome</th><th>Tipo</th><th>Status</th><th>Período</th></tr></thead>
                        <tbody>
                        <?php foreach ($camps as $c): ?>
                            <tr>
                                <td><strong><?= h((string) $c['name']) ?></strong><br><small><?= h((string) $c['title']) ?></small></td>
                                <td><?= h((string) $c['type']) ?></td>
                                <td><?= h((string) $c['status']) ?></td>
                                <td><?= h(trim(($c['starts_at'] ?? '') . ' → ' . ($c['ends_at'] ?? ''))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$camps): ?><tr class="empty"><td colspan="4">Nenhuma campanha.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

        <?php elseif ($tab === 'cupons'): ?>
            <?php if (!company_has_feature($companyId, 'coupons')): ?>
                <section class="card card-narrow">
                    <p class="alert"><?= h(company_feature_error('coupons')) ?></p>
                    <a class="btn" href="/app/assinatura">Ver planos</a>
                </section>
            <?php else: ?>
            <?php $coupons = company_coupons($companyId); ?>
            <section class="card card-narrow">
                <form method="post" action="/app/cupons" class="form">
                    <?= csrf_field() ?>
                    <label>Código<input name="code" placeholder="WIFI10"></label>
                    <label>Título<input name="title" placeholder="10% de desconto"></label>
                    <label>Descrição<textarea name="description" rows="2"></textarea></label>
                    <label>Válido até<input type="date" name="valid_until"></label>
                    <button class="btn" type="submit">Criar cupom</button>
                </form>
            </section>
            <section class="card">
                <div class="table-wrap">
                    <table class="saas-table">
                        <thead><tr><th>Código</th><th>Título</th><th>Validade</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($coupons as $c): ?>
                            <tr>
                                <td><code><?= h((string) $c['code']) ?></code></td>
                                <td><?= h((string) $c['title']) ?></td>
                                <td><?= h((string) $c['valid_until']) ?></td>
                                <td><?= h((string) $c['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$coupons): ?><tr class="empty"><td colspan="4">Nenhum cupom.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

        <?php elseif ($tab === 'relatorios'): ?>
            <?php if (!company_has_feature($companyId, 'reports')): ?>
                <section class="card card-narrow">
                    <p class="alert"><?= h(company_feature_error('reports')) ?></p>
                    <a class="btn" href="/app/assinatura">Ver planos</a>
                </section>
            <?php else: ?>
            <?php
            $reportDays = company_report_days((int) ($_GET['days'] ?? 30));
            $report = company_report_summary($companyId, $reportDays);
            $accessChart = company_report_access_by_day($companyId, $reportDays);
            $hourChart = company_report_by_hour($companyId, $reportDays);
            $byHotspot = company_report_by_hotspot($companyId, $reportDays);
            $byDevice = company_report_breakdown($companyId, 'device', $reportDays);
            $byOs = company_report_breakdown($companyId, 'os_name', $reportDays);
            $topClients = company_report_top_clients($companyId, $reportDays);
            $campaignStats = company_report_campaigns($companyId, $reportDays);
            $couponStats = company_report_coupons($companyId, $reportDays);
            $accessMax = report_chart_max($accessChart);
            $hourMax = report_chart_max($hourChart);
            $hotspotMax = report_chart_max($byHotspot);
            ?>
            <section class="card card-narrow">
                <div class="report-toolbar">
                    <div>
                        <h2 style="margin:0">Período</h2>
                        <p class="hint" style="margin:4px 0 0"><?= h((string) $report['range']['label']) ?> · <?= h(date('d/m/Y', strtotime((string) $report['range']['start']) ?: time())) ?> a <?= h(date('d/m/Y', strtotime((string) $report['range']['end']) ?: time())) ?></p>
                    </div>
                    <div class="report-periods">
                        <?php foreach ([7 => '7 dias', 30 => '30 dias', 90 => '90 dias'] as $d => $lbl): ?>
                            <a class="btn btn-sm <?= $reportDays === $d ? '' : 'ghost' ?>" href="/app/relatorios?days=<?= $d ?>"><?= h($lbl) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <div class="stats">
                <article><span>Acessos</span><strong><?= (int) $report['accesses'] ?></strong></article>
                <article><span>Média / dia</span><strong><?= h((string) $report['avg_per_day']) ?></strong></article>
                <article><span>Clientes únicos</span><strong><?= (int) $report['unique_clients'] ?></strong></article>
                <article><span>Novos cadastros</span><strong><?= (int) $report['new_clients'] ?></strong></article>
                <article><span>Duração média</span><strong><?= h((string) $report['avg_duration_label']) ?></strong></article>
                <article><span>CTR campanhas</span><strong><?= h((string) $report['campaign_ctr']) ?>%</strong></article>
            </div>

            <section class="card">
                <h2>Acessos por dia</h2>
                <div class="report-chart">
                    <?php foreach ($accessChart as $bar): ?>
                        <?php $hgt = (int) round(((int) $bar['total'] / $accessMax) * 140); ?>
                        <div class="report-bar">
                            <div class="report-bar-track">
                                <div class="report-bar-fill" title="<?= (int) $bar['total'] ?>" style="height:<?= max(4, $hgt) ?>px"></div>
                            </div>
                            <strong><?= (int) $bar['total'] ?></strong>
                            <small><?= h((string) $bar['label']) ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="card">
                <h2>Horários de pico</h2>
                <p class="hint">Distribuição dos acessos por hora do dia.</p>
                <div class="report-chart report-chart-hours">
                    <?php foreach ($hourChart as $bar): ?>
                        <?php $hgt = (int) round(((int) $bar['total'] / $hourMax) * 100); ?>
                        <div class="report-bar">
                            <div class="report-bar-track report-bar-track-sm">
                                <div class="report-bar-fill" title="<?= (int) $bar['total'] ?>" style="height:<?= max(3, $hgt) ?>px"></div>
                            </div>
                            <small><?= h((string) $bar['label']) ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="report-grid">
                <section class="card">
                    <h2>Por hotspot</h2>
                    <?php if (!$byHotspot): ?>
                        <p class="hint">Sem dados no período.</p>
                    <?php else: ?>
                        <ul class="report-list">
                            <?php foreach ($byHotspot as $row): ?>
                                <?php $pct = (int) round(((int) $row['total'] / $hotspotMax) * 100); ?>
                                <li>
                                    <div class="report-list-head">
                                        <strong><?= h((string) $row['name']) ?></strong>
                                        <span><?= (int) $row['total'] ?> acessos · <?= (int) $row['unique_clients'] ?> clientes</span>
                                    </div>
                                    <div class="report-meter"><span style="width:<?= max(2, $pct) ?>%"></span></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
                <section class="card">
                    <h2>Dispositivos</h2>
                    <?php if (!$byDevice && !$byOs): ?>
                        <p class="hint">Sem dados de dispositivo no período.</p>
                    <?php else: ?>
                        <?php if ($byDevice): ?>
                            <h3 class="report-sub">Tipo</h3>
                            <ul class="report-list">
                                <?php $devMax = report_chart_max($byDevice); foreach ($byDevice as $row): ?>
                                    <?php $pct = (int) round(((int) $row['total'] / $devMax) * 100); ?>
                                    <li>
                                        <div class="report-list-head"><strong><?= h((string) $row['label']) ?></strong><span><?= (int) $row['total'] ?></span></div>
                                        <div class="report-meter"><span style="width:<?= max(2, $pct) ?>%"></span></div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if ($byOs): ?>
                            <h3 class="report-sub">Sistema</h3>
                            <ul class="report-list">
                                <?php $osMax = report_chart_max($byOs); foreach ($byOs as $row): ?>
                                    <?php $pct = (int) round(((int) $row['total'] / $osMax) * 100); ?>
                                    <li>
                                        <div class="report-list-head"><strong><?= h((string) $row['label']) ?></strong><span><?= (int) $row['total'] ?></span></div>
                                        <div class="report-meter"><span style="width:<?= max(2, $pct) ?>%"></span></div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            </div>

            <section class="card">
                <h2>Campanhas</h2>
                <p class="hint"><?= (int) $report['campaign_views'] ?> visualizações · <?= (int) $report['campaign_clicks'] ?> cliques · CTR <?= h((string) $report['campaign_ctr']) ?>%</p>
                <div class="table-wrap">
                    <table class="saas-table">
                        <thead><tr><th>Campanha</th><th>Status</th><th>Views</th><th>Cliques</th><th>CTR</th></tr></thead>
                        <tbody>
                        <?php foreach ($campaignStats as $c): ?>
                            <tr>
                                <td><strong><?= h((string) $c['name']) ?></strong><?php if ($c['title'] !== ''): ?><br><small><?= h((string) $c['title']) ?></small><?php endif; ?></td>
                                <td><?= h((string) $c['status']) ?></td>
                                <td><?= (int) $c['views'] ?></td>
                                <td><?= (int) $c['clicks'] ?></td>
                                <td><?= h((string) $c['ctr']) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$campaignStats): ?><tr class="empty"><td colspan="5">Nenhuma campanha no período.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="report-grid">
                <section class="card">
                    <h2>Clientes mais frequentes</h2>
                    <div class="table-wrap">
                        <table class="saas-table">
                            <thead><tr><th>Cliente</th><th>Visitas</th><th>Última</th></tr></thead>
                            <tbody>
                            <?php foreach ($topClients as $c): ?>
                                <tr>
                                    <td><strong><?= h((string) ($c['name'] ?: 'Sem nome')) ?></strong><br><small><?= h((string) $c['phone']) ?></small></td>
                                    <td><?= (int) $c['visits'] ?></td>
                                    <td><?= h($c['last_visit'] ? date('d/m/Y H:i', strtotime((string) $c['last_visit']) ?: time()) : '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$topClients): ?><tr class="empty"><td colspan="3">Sem acessos no período.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <section class="card">
                    <h2>Cupons</h2>
                    <p class="hint"><?= (int) $report['coupons_issued'] ?> emitidos no período.</p>
                    <div class="table-wrap">
                        <table class="saas-table">
                            <thead><tr><th>Código</th><th>Emitidos</th><th>Usados</th></tr></thead>
                            <tbody>
                            <?php foreach ($couponStats as $c): ?>
                                <tr>
                                    <td><code><?= h((string) $c['code']) ?></code><br><small><?= h((string) $c['title']) ?></small></td>
                                    <td><?= (int) $c['issued'] ?></td>
                                    <td><?= (int) $c['used'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$couponStats): ?><tr class="empty"><td colspan="3">Nenhum cupom.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <section class="card">
                <h2>Exportações</h2>
                <p class="hint">Arquivos CSV com separador ;</p>
                <div class="actions row">
                    <a class="btn" href="/app/relatorios?export=access&days=<?= $reportDays ?>">Acessos</a>
                    <a class="btn ghost" href="/app/relatorios?export=clients&days=<?= $reportDays ?>">Clientes</a>
                    <a class="btn ghost" href="/app/relatorios?export=campaigns&days=<?= $reportDays ?>">Campanhas</a>
                </div>
            </section>
            <?php endif; ?>

        <?php elseif ($tab === 'usuarios'): ?>
            <?php $users = company_users($companyId); ?>
            <section class="card card-narrow">
                <h2>Convidar usuário</h2>
                <p class="hint">Usuários: <?= h(plan_usage_label($limitUsage['users']['used'], $limitUsage['users']['max'])) ?>.</p>
                <?php if (company_within_user_limit($companyId)): ?>
                <form method="post" action="/app/usuarios" class="form">
                    <?= csrf_field() ?>
                    <label>Nome<input name="name" required></label>
                    <label>E-mail<input type="email" name="email" required></label>
                    <label>Senha inicial<input type="password" name="password" minlength="8" required></label>
                    <label>Perfil
                        <select name="role">
                            <option value="operator">Operador</option>
                            <option value="company_admin">Admin da empresa</option>
                        </select>
                    </label>
                    <button class="btn" type="submit">Criar usuário</button>
                </form>
                <?php else: ?>
                    <p class="alert"><?= h(company_limit_error('users')) ?></p>
                <?php endif; ?>
            </section>
            <section class="card">
                <div class="table-wrap">
                    <table class="saas-table">
                        <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th></tr></thead>
                        <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?= h((string) $u['name']) ?></td>
                                <td><?= h((string) $u['email']) ?></td>
                                <td><?= h((string) $u['role']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        <?php elseif ($tab === 'assinatura'): ?>
            <?php
            $companyPayments = company_payments($companyId, 12);
            $pendingPayment = company_pending_payment($companyId);
            $availablePlans = all_plans(true);
            $flashPayUrl = (string) ($_SESSION['flash_pay_url'] ?? '');
            unset($_SESSION['flash_pay_url']);
            ?>
            <?php if ($flashPayUrl): ?>
                <section class="card card-narrow">
                    <h2>Pagamento</h2>
                    <p class="hint">Use o link abaixo para concluir o pagamento.</p>
                    <p><a class="btn" href="<?= h($flashPayUrl) ?>" target="_blank" rel="noopener">Abrir checkout de pagamento</a></p>
                    <p class="hint"><code style="word-break:break-all"><?= h($flashPayUrl) ?></code></p>
                </section>
            <?php endif; ?>
            <section class="card card-narrow">
                <h2>Plano atual</h2>
                <?php if ($sub): ?>
                    <p><span class="tag <?= h((string) ($sub['tag_class'] ?? 'online')) ?>"><?= h((string) ($sub['billing_label'] ?? company_subscription_label($sub))) ?></span></p>
                    <p><strong><?= h((string) ($sub['plan_name'] ?? '')) ?></strong>
                        · <?= (int) ($sub['price_cents'] ?? 0) === 0 ? 'Grátis' : h(cents_label((int) $sub['price_cents'])) . '/mês' ?></p>
                    <p class="hint">
                        <?php if (($sub['billing_status'] ?? $sub['status'] ?? '') === 'trial'): ?>
                            Trial até <?= h(date('d/m/Y', strtotime((string) $sub['trial_ends_at']) ?: time())) ?>.
                        <?php elseif (($sub['billing_status'] ?? '') === 'pendente'): ?>
                            Trial encerrado. Escolha um plano e gere a cobrança para continuar.
                        <?php elseif (($sub['billing_status'] ?? '') === 'atrasada'): ?>
                            Pagamento em atraso. Regularize para evitar suspensão.
                        <?php elseif (($sub['billing_status'] ?? '') === 'suspensa'): ?>
                            Assinatura suspensa. Escolha um plano e pague para reativar.
                        <?php else: ?>
                            Vigência até <?= h((string) ($sub['ends_at'] ?? '—')) ?>.
                        <?php endif; ?>
                    </p>
                    <p class="hint">
                        Uso atual:
                        hotspots <?= h(plan_usage_label($limitUsage['hotspots']['used'], $limitUsage['hotspots']['max'])) ?>,
                        clientes <?= h(plan_usage_label($limitUsage['clients']['used'], $limitUsage['clients']['max'])) ?>,
                        usuários <?= h(plan_usage_label($limitUsage['users']['used'], $limitUsage['users']['max'])) ?>.
                    </p>
                <?php else: ?>
                    <p class="alert">Nenhuma assinatura encontrada.</p>
                <?php endif; ?>
                <?php if ($pendingPayment && !empty($pendingPayment['pay_url'])): ?>
                    <p class="hint">Cobrança pendente: <a href="<?= h((string) $pendingPayment['pay_url']) ?>" target="_blank" rel="noopener">Abrir link de pagamento</a></p>
                <?php endif; ?>
            </section>
            <section class="card">
                <h2>Planos disponíveis</h2>
                <?php if (!payment_configured()): ?>
                    <p class="hint"><?= h(payment_not_configured_message()) ?> Entre em contato com o suporte.</p>
                <?php endif; ?>
                <div class="table-wrap">
                    <table class="saas-table">
                        <thead><tr><th>Plano</th><th>Preço</th><th>Hotspots</th><th>Clientes</th><th>Usuários</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($availablePlans as $p): ?>
                            <tr>
                                <td><strong><?= h((string) $p['name']) ?></strong></td>
                                <td><?= (int) $p['price_cents'] === 0 ? 'Grátis' : h(cents_label((int) $p['price_cents'])) . '/mês' ?></td>
                                <td><?= (int) $p['max_hotspots'] === 0 ? '∞' : (int) $p['max_hotspots'] ?></td>
                                <td><?= (int) $p['max_clients'] === 0 ? '∞' : (int) $p['max_clients'] ?></td>
                                <td><?= (int) $p['max_users'] === 0 ? '∞' : (int) $p['max_users'] ?></td>
                                <td>
                                    <?php if ((int) ($sub['plan_id'] ?? 0) === (int) $p['id']): ?>
                                        <span class="tag online">Atual</span>
                                    <?php elseif ((int) $p['price_cents'] === 0): ?>
                                        <form method="post" action="/app/assinatura" style="display:inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="do" value="plan">
                                            <input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>">
                                            <button class="btn ghost btn-sm" type="submit">Selecionar</button>
                                        </form>
                                    <?php elseif (payment_configured()): ?>
                                        <form method="post" action="/app/assinatura" style="display:inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="do" value="charge">
                                            <input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>">
                                            <button class="btn btn-sm" type="submit">Assinar</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php if ($companyPayments): ?>
            <section class="card">
                <h2>Histórico de pagamentos</h2>
                <div class="table-wrap">
                    <table class="saas-table">
                        <thead><tr><th>Data</th><th>Valor</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($companyPayments as $p): ?>
                            <tr>
                                <td><?= h((string) $p['created_at']) ?></td>
                                <td><?= h(cents_label((int) $p['amount_cents'])) ?></td>
                                <td><?= h((string) $p['status']) ?></td>
                                <td><?php if (($p['status'] ?? '') === 'pending' && !empty($p['pay_url'])): ?>
                                    <a href="<?= h((string) $p['pay_url']) ?>" target="_blank" rel="noopener">Pagar</a>
                                <?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
<?php require __DIR__ . '/../partials/admin-shell.js.php'; ?>
</body>
</html>
