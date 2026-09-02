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

function app_nav(string $tab, string $key, string $label, string $perm, ?string $feature = null): void
{
    if (!user_can($perm)) {
        return;
    }
    if ($feature !== null && !company_has_feature(current_company_id(), $feature)) {
        return;
    }
    $active = $tab === $key ? ' active' : '';
    echo '<a class="' . $active . '" href="/app?tab=' . h($key) . '">' . h($label) . '</a>';
}
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> · <?= h((string) $company['trade_name']) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="app">
<aside class="app-side">
    <a class="app-brand" href="/app">
        <img class="app-logo app-logo-side" src="<?= h(platform_logo_url()) ?>" alt="WiFi da Loja">
        <div>
            <strong><?= h((string) $company['trade_name']) ?></strong>
            <small>Painel da empresa</small>
        </div>
    </a>
    <nav class="app-nav">
        <div class="app-nav-label">Principal</div>
        <?php app_nav($tab, 'dashboard', 'Dashboard', 'dashboard'); ?>
        <?php app_nav($tab, 'hotspots', 'Hotspots', 'hotspots'); ?>
        <?php app_nav($tab, 'clientes', 'Clientes', 'clients'); ?>
        <?php app_nav($tab, 'acessos', 'Acessos', 'access'); ?>
        <div class="app-nav-label">Marketing</div>
        <?php app_nav($tab, 'campanhas', 'Campanhas', 'campaigns', 'campaigns'); ?>
        <?php app_nav($tab, 'cupons', 'Cupons', 'coupons', 'coupons'); ?>
        <?php app_nav($tab, 'relatorios', 'Relatórios', 'reports', 'reports'); ?>
        <div class="app-nav-label">Conta</div>
        <?php app_nav($tab, 'empresa', 'Empresa', 'company'); ?>
        <?php app_nav($tab, 'usuarios', 'Usuários', 'users'); ?>
        <?php app_nav($tab, 'assinatura', 'Assinatura', 'billing'); ?>
    </nav>
    <div class="app-side-foot">
        <div class="app-user"><?= h((string) ($user['name'] ?? $user['email'] ?? '')) ?></div>
        <a class="btn ghost btn-sm" href="/sair">Sair</a>
    </div>
</aside>
<div class="app-body">
    <header class="app-top">
        <div>
            <h1><?= h($pageTitle) ?></h1>
            <p class="lead">
                <?php if ($sub && ($sub['billing_status'] ?? $sub['status'] ?? '') === 'trial'): ?>
                    Trial até <?= h(date('d/m/Y', strtotime((string) $sub['trial_ends_at']) ?: time())) ?>
                    · plano <?= h((string) ($sub['plan_name'] ?? '')) ?>
                <?php else: ?>
                    <?= h((string) ($sub['billing_label'] ?? company_subscription_label($sub))) ?>
                    <?php if ($sub): ?> · <?= h((string) ($sub['plan_name'] ?? '')) ?><?php endif; ?>
                <?php endif; ?>
            </p>
        </div>
    </header>
    <main class="app-main">
        <?php if ($flashErr): ?><p class="alert"><?= h($flashErr) ?></p><?php endif; ?>
        <?php if ($flashOk): ?><p class="hint flash-ok"><?= h($flashOk) ?></p><?php endif; ?>
        <?php if (!$serviceOk && $tab !== 'assinatura'): ?>
            <p class="alert">Sua assinatura está <?= h((string) ($sub['billing_label'] ?? 'inativa')) ?>. Escolha um plano em <a href="/app?tab=assinatura">Assinatura</a> para continuar usando o serviço.</p>
        <?php endif; ?>

        <?php if ($tab === 'dashboard'): ?>
            <div class="stats">
                <article><span>Clientes</span><strong><?= h(plan_usage_label($limitUsage['clients']['used'], $limitUsage['clients']['max'])) ?></strong></article>
                <article><span>Acessos hoje</span><strong><?= (int) $kpis['access_today'] ?></strong></article>
                <article><span>7 dias</span><strong><?= (int) $kpis['access_7d'] ?></strong></article>
                <article><span>30 dias</span><strong><?= (int) $kpis['access_30d'] ?></strong></article>
                <article><span>Hotspots</span><strong><?= h(plan_usage_label($limitUsage['hotspots']['used'], $limitUsage['hotspots']['max'])) ?></strong></article>
                <article><span>Usuários</span><strong><?= h(plan_usage_label($limitUsage['users']['used'], $limitUsage['users']['max'])) ?></strong></article>
            </div>
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
            $stmt = db()->prepare('SELECT * FROM stores WHERE company_id = ? ORDER BY id DESC');
            $stmt->execute([$companyId]);
            $hotspots = $stmt->fetchAll() ?: [];
            ?>
            <section class="card">
                <div class="card-head"><h2>Hotspots</h2>
                    <?php if (company_within_hotspot_limit($companyId)): ?>
                        <a class="btn btn-sm" href="/app?tab=hotspots&novo=1">Novo hotspot</a>
                    <?php endif; ?>
                </div>
                <p class="hint">Uso do plano: <?= h(plan_usage_label($limitUsage['hotspots']['used'], $limitUsage['hotspots']['max'])) ?> hotspots.</p>
                <?php if (!company_within_hotspot_limit($companyId)): ?>
                    <p class="alert"><?= h(company_limit_error('hotspots')) ?></p>
                <?php endif; ?>
                <?php if (!empty($_GET['novo'])): ?>
                    <form method="post" action="/app/hotspots" class="form form-inline" style="margin-bottom:20px">
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
                                <td><a class="btn ghost btn-sm" href="/app?tab=hotspots&id=<?= (int) $h['id'] ?>">Abrir</a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$hotspots): ?>
                            <tr class="empty"><td colspan="5">Nenhum hotspot. Crie o primeiro para começar.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php
            $hid = (int) ($_GET['id'] ?? 0);
            $hot = $hid > 0 ? find_store($hid) : null;
            if ($hot && (int) ($hot['company_id'] ?? 0) === $companyId):
                $pc = portal_config_for((int) $hot['id']);
                $health = store_connection_health($hot);
                $setupFile = installer_setup_path();
            ?>
            <section class="card">
                <h2><?= h((string) $hot['name']) ?></h2>
                <p class="hint">
                    PC: <span class="tag conn-<?= h((string) $health['key']) ?>"><?= h((string) $health['label']) ?></span>
                    · <?= h((string) $health['detail']) ?>
                    <?php if (!empty($hot['last_seen_at'])): ?>
                        · último contato <?= h((string) $hot['last_seen_at']) ?>
                    <?php endif; ?>
                </p>
                <form method="post" action="/app/hotspots" class="form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="save">
                    <input type="hidden" name="id" value="<?= (int) $hot['id'] ?>">
                    <div class="form-grid">
                        <fieldset>
                            <legend>Hotspot</legend>
                            <label>Nome<input name="name" value="<?= h((string) $hot['name']) ?>" required></label>
                            <label>Descrição<textarea name="description" rows="2"><?= h((string) ($hot['description'] ?? '')) ?></textarea></label>
                            <label>Localização<input name="location" value="<?= h((string) ($hot['location'] ?? '')) ?>"></label>
                            <label>SSID<input name="ssid" value="<?= h(setting_for_store((int) $hot['id'], 'wifi_ssid', 'WifiDaLoja')) ?>"></label>
                            <label>Senha Wi-Fi<input name="wifi_pass" value="<?= h(setting_for_store((int) $hot['id'], 'wifi_pass', '')) ?>"></label>
                            <label>Status
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
                            <p class="hint">Token do agente: <code class="token"><?= h((string) $hot['token']) ?></code></p>
                            <p class="hint">Portal: <a href="/portal/<?= h((string) $hot['token']) ?>" target="_blank">/portal/<?= h((string) $hot['token']) ?></a></p>
                            <?php if ($setupFile): ?>
                                <p class="hint"><a class="btn ghost btn-sm" href="/super/instalador/baixar">Baixar instalador Windows</a></p>
                            <?php endif; ?>
                        </fieldset>
                        <fieldset>
                            <legend>Portal</legend>
                            <label>Título<input name="portal_title" value="<?= h((string) ($pc['title'] ?? 'Bem-vindo')) ?>"></label>
                            <label>Descrição<textarea name="portal_subtitle" rows="3"><?= h((string) ($pc['subtitle'] ?? '')) ?></textarea></label>
                            <label>Texto do botão<input name="portal_button" value="<?= h((string) ($pc['button_label'] ?? 'Conectar à internet')) ?>"></label>
                            <label>Termos<textarea name="terms_html" rows="3"><?= h((string) ($hot['terms_html'] ?? '')) ?></textarea></label>
                            <label>Privacidade<textarea name="privacy_html" rows="3"><?= h((string) ($hot['privacy_html'] ?? '')) ?></textarea></label>
                        </fieldset>
                    </div>
                    <div class="actions row">
                        <button class="btn" type="submit">Salvar hotspot</button>
                    </div>
                </form>
                <form method="post" action="/app/hotspots" class="form-inline" style="margin-top:12px" onsubmit="return confirm('Gerar novo token? O PC da loja precisará ser reconfigurado.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="rotate">
                    <input type="hidden" name="id" value="<?= (int) $hot['id'] ?>">
                    <button class="btn ghost" type="submit">Renovar token do agente</button>
                </form>
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
                <form method="get" action="/app" class="form-inline" style="margin-bottom:12px">
                    <input type="hidden" name="tab" value="clientes">
                    <label>Buscar<input name="q" value="<?= h($q) ?>" placeholder="Nome, WhatsApp ou e-mail"></label>
                    <button class="btn ghost" type="submit">Filtrar</button>
                </form>
                <div class="table-wrap">
                    <table class="saas-table">
                        <thead><tr><th>Cliente</th><th>WhatsApp</th><th>Acessos</th><th>Último</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($clients as $c): ?>
                            <tr>
                                <td><strong><?= h((string) ($c['name'] ?: '—')) ?></strong></td>
                                <td><?= h((string) ($c['phone'] ?? '')) ?></td>
                                <td><?= (int) ($c['access_count'] ?? 1) ?></td>
                                <td><?= h((string) ($c['last_access_at'] ?: $c['created_at'] ?? '')) ?></td>
                                <td><?= !empty($c['blocked']) ? 'Bloqueado' : h((string) ($c['state'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$clients): ?><tr class="empty"><td colspan="5">Nenhum cliente ainda.</td></tr><?php endif; ?>
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
                    <a class="btn" href="/app?tab=assinatura">Ver planos</a>
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
                    <a class="btn" href="/app?tab=assinatura">Ver planos</a>
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
                    <a class="btn" href="/app?tab=assinatura">Ver planos</a>
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
                            <a class="btn btn-sm <?= $reportDays === $d ? '' : 'ghost' ?>" href="/app?tab=relatorios&days=<?= $d ?>"><?= h($lbl) ?></a>
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
</body>
</html>
