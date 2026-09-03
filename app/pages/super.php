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
                <a class="<?= $cfgSec === 'integracao' ? 'active' : '' ?>" href="/super?tab=configuracoes&sec=integracao">Pagamentos</a>
                <a class="<?= $cfgSec === 'whatsapp' ? 'active' : '' ?>" href="/super?tab=configuracoes&sec=whatsapp">WhatsApp</a>
                <a class="<?= $cfgSec === 'sistema' ? 'active' : '' ?>" href="/super?tab=configuracoes&sec=sistema">Sistema</a>
            </nav>
            <?php if ($cfgSec === 'sistema'): ?>
            <?php
                $migRows = migrations_status(db());
                $migPending = migrations_pending_count(db());
            ?>
            <section class="card">
                <h2>Migrations do banco</h2>
                <p class="hint">Atualizações de schema rodam automaticamente ao abrir o painel. Use este painel ou <code>php scripts/migrate.php</code> no deploy.</p>
                <p>Driver: <strong><?= h(db_driver()) ?></strong>
                    · Pendentes: <strong><?= (int) $migPending ?></strong></p>
                <?php if ($migPending > 0): ?>
                    <form method="post" action="/super/migrations" class="form" style="margin:12px 0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="return_to" value="/super?tab=configuracoes&sec=sistema">
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
                    <input type="hidden" name="return_to" value="/super?tab=configuracoes&sec=whatsapp">
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
                        <input type="hidden" name="return_to" value="/super?tab=configuracoes&sec=whatsapp">
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
                    <input type="hidden" name="return_to" value="/super?tab=configuracoes&sec=integracao">
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
                            <input type="hidden" name="return_to" value="/super?tab=configuracoes&sec=integracao">
                            <button class="btn ghost" type="submit">Testar <?= h(payment_provider_label()) ?></button>
                        </form>
                        <form method="post" action="/super/pagseguro">
                            <?= csrf_field() ?>
                            <input type="hidden" name="do" value="run">
                            <input type="hidden" name="return_to" value="/super?tab=configuracoes&sec=integracao">
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
        <?php endif; ?>
    </main>
</div>
</body>
</html>
