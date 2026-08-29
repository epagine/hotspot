<?php

declare(strict_types=1);

require_admin();

pagseguro_maybe_run_billing();

$tab = preg_replace('/[^a-z]/', '', (string) ($_GET['tab'] ?? 'clientes')) ?: 'clientes';
if ($tab === 'conta') {
    $tab = 'config';
}
if (!in_array($tab, ['clientes', 'instalador', 'config'], true)) {
    $tab = 'clientes';
}

$saas = saas_overview();
$k = $saas['kpis'];
$rows = $saas['clients'];
$fichaId = (int) ($_GET['id'] ?? 0);
$ficha = $fichaId > 0 ? find_store($fichaId) : null;
$fichaHealth = $ficha ? store_connection_health($ficha) : null;
$fichaStatus = $ficha ? store_status_payload($ficha) : [];
$fichaPayments = $ficha ? store_payments((int) $ficha['id']) : [];
$setupFile = installer_setup_path();
$setupReady = $setupFile !== null;
$me = setting('admin_user', 'admin');

function saas_nav(string $tab): void
{
    $items = [
        'clientes' => 'Clientes',
        'instalador' => 'Instalador',
        'config' => 'Configuração',
    ];
    foreach ($items as $key => $label) {
        $active = $tab === $key ? ' active' : '';
        echo '<a class="saas-link' . $active . '" href="/admin?tab=' . h($key) . '">' . h($label) . '</a>';
    }
}
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestão · Wi-Fi da loja</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="saas" data-tab="<?= h($tab) ?>">
<header class="saas-bar">
    <div class="saas-bar-inner">
        <div class="saas-brand">
            <span class="saas-mark">WL</span>
            <div>
                <p class="eyebrow">Wi-Fi da loja</p>
                <strong>Gestão</strong>
            </div>
        </div>
        <nav class="saas-nav">
            <?php saas_nav($tab); ?>
        </nav>
        <div class="saas-user">
            <span><?= h($me) ?></span>
            <a class="btn ghost" href="/admin/logout">Sair</a>
        </div>
    </div>
</header>

<main class="saas-main">
<?php if (!empty($_SESSION['flash_error'])): ?>
    <p class="alert flash-global"><?= h((string) $_SESSION['flash_error']) ?></p>
    <?php unset($_SESSION['flash_error']); ?>
<?php elseif (!empty($_SESSION['flash_ok'])): ?>
    <p class="hint flash-ok"><?= h((string) $_SESSION['flash_ok']) ?></p>
    <?php unset($_SESSION['flash_ok']); ?>
    <?php if (!empty($_SESSION['flash_pay_url'])): ?>
        <p class="pay-box">Link PagSeguro<br><a href="<?= h((string) $_SESSION['flash_pay_url']) ?>" target="_blank" rel="noopener"><?= h((string) $_SESSION['flash_pay_url']) ?></a></p>
        <?php unset($_SESSION['flash_pay_url']); ?>
    <?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'clientes'): ?>
    <div class="stats" id="saas-kpis">
        <article><strong id="kpi-ativos"><?= (int) $k['ativos'] ?></strong><span>ativos</span></article>
        <article><strong id="kpi-ok"><?= (int) $k['ok'] ?></strong><span>PC ok</span></article>
        <article><strong id="kpi-erro"><?= (int) $k['erro'] ?></strong><span>PC com erro</span></article>
        <article><strong id="kpi-offline"><?= (int) $k['offline'] ?></strong><span>PC offline</span></article>
        <article><strong id="kpi-atrasados"><?= (int) $k['atrasados'] ?></strong><span>atrasados</span></article>
        <article><strong id="kpi-total"><?= (int) $k['total'] ?></strong><span>no total</span></article>
    </div>

    <?php if ($ficha): ?>
        <section class="card">
            <p class="eyebrow"><a href="/admin?tab=clientes">← Clientes</a></p>
            <div class="ficha-head">
                <div>
                    <h1><?= h((string) $ficha['name']) ?></h1>
                    <p>
                        <span class="tag <?= !empty($ficha['active']) ? 'online' : 'blocked' ?>"><?= !empty($ficha['active']) ? 'Ativo' : 'Suspenso' ?></span>
                        <span class="tag conn-<?= h($fichaHealth['key']) ?>"><?= h($fichaHealth['label']) ?></span>
                        <span class="tag <?= ($ficha['billing_status'] ?? '') === 'atrasado' ? 'blocked' : (($ficha['billing_status'] ?? '') === 'em_dia' ? 'online' : 'pending') ?>"><?= h(billing_label((string) ($ficha['billing_status'] ?? 'em_dia'))) ?></span>
                    </p>
                </div>
            </div>
            <div class="conn-banner conn-<?= h($fichaHealth['key']) ?>">
                <strong>Conexão do PC</strong>
                <span id="ficha-conn"><?= h($fichaHealth['detail']) ?></span>
                <?php
                $fichaBits = [];
                if (!empty($fichaStatus['ssid'])) {
                    $fichaBits[] = 'rede ' . h((string) $fichaStatus['ssid']);
                }
                if (!empty($fichaStatus['internet_ip'])) {
                    $fichaBits[] = 'IP ' . h((string) $fichaStatus['internet_ip']);
                }
                if (!empty($ficha['last_seen_at'])) {
                    $fichaBits[] = 'contato ' . h(date('d/m H:i', parse_time_any((string) $ficha['last_seen_at']) ?: time()));
                }
                ?>
                <?php if ($fichaBits): ?>
                    <small><?= implode(' · ', $fichaBits) ?></small>
                <?php endif; ?>
            </div>

            <form method="post" action="/admin/stores" class="form">
                <input type="hidden" name="do" value="save">
                <input type="hidden" name="id" value="<?= (int) $ficha['id'] ?>">
                <div class="form-grid">
                    <fieldset>
                        <legend>Situação</legend>
                        <label>Nome<input name="name" value="<?= h((string) $ficha['name']) ?>" required></label>
                        <label>Cidade<input name="city" value="<?= h((string) ($ficha['city'] ?? '')) ?>"></label>
                        <label>Contato<input name="contact" value="<?= h((string) ($ficha['contact'] ?? '')) ?>" placeholder="Telefone ou responsável"></label>
                        <label>Contrato
                            <select name="active">
                                <option value="1" <?= !empty($ficha['active']) ? 'selected' : '' ?>>Ativo</option>
                                <option value="0" <?= empty($ficha['active']) ? 'selected' : '' ?>>Suspenso</option>
                            </select>
                        </label>
                        <p class="hint">Suspender desliga o hotspot no PC da loja. Rede, portal e visitantes ficam no Windows.</p>
                    </fieldset>
                    <fieldset>
                        <legend>Financeiro</legend>
                        <label>Plano
                            <select name="plan">
                                <?php foreach (['mensal' => 'Mensal', 'trimestral' => 'Trimestral', 'anual' => 'Anual'] as $val => $lab): ?>
                                    <option value="<?= h($val) ?>" <?= ($ficha['plan'] ?? 'mensal') === $val ? 'selected' : '' ?>><?= h($lab) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Valor (R$)<input name="monthly_fee" value="<?= h((string) ($ficha['monthly_fee'] ?? '')) ?>" placeholder="0,00"></label>
                        <p class="hint"><?php
                            $pm = plan_meta((string) ($ficha['plan'] ?? 'mensal'));
                            echo 'Plano ' . h($pm['label']) . ': cobrança a cada ' . (int) $pm['months'] . ' ' . ((int) $pm['months'] === 1 ? 'mês' : 'meses') . '.';
                        ?></p>
                        <label class="check"><input type="checkbox" name="auto_billing" value="1" <?= ((int) ($ficha['auto_billing'] ?? 1) === 1) ? 'checked' : '' ?>> Cobrança automática neste plano</label>
                        <p class="hint">O painel gera o link perto do vencimento. No PagSeguro, o primeiro pagamento com cartão cria a assinatura no intervalo do plano.</p>
                        <label>Pago até<input name="paid_until" type="date" value="<?= h((string) ($ficha['paid_until'] ?? '')) ?>"></label>
                        <label>Cobrança
                            <select name="billing_status">
                                <?php foreach (['em_dia' => 'Em dia', 'atrasado' => 'Atrasado', 'cortesia' => 'Cortesia', 'cancelado' => 'Cancelado'] as $val => $lab): ?>
                                    <option value="<?= h($val) ?>" <?= ($ficha['billing_status'] ?? 'em_dia') === $val ? 'selected' : '' ?>><?= h($lab) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Observações<textarea name="notes" rows="3"><?= h((string) ($ficha['notes'] ?? '')) ?></textarea></label>
                    </fieldset>
                </div>
                <details class="vinculo">
                    <summary>Vínculo do PC da loja</summary>
                    <p class="hint">Cole no instalador Windows. Não altera SSID, senha ou o portal.</p>
                    <p>Token<br><code class="token"><?= h((string) $ficha['token']) ?></code></p>
                    <p>URL do painel<br><code><?= h(guess_panel_url()) ?></code></p>
                    <div class="actions row">
                        <button class="btn ghost" name="do" value="rotate">Gerar novo token</button>
                        <?php if ($setupReady): ?>
                            <a class="btn ghost" href="/admin/instalador">Baixar instalador</a>
                        <?php endif; ?>
                    </div>
                </details>
                <div class="actions row">
                    <button class="btn" type="submit">Salvar</button>
                </div>
            </form>
            <div class="pagseguro-box">
                <h2>PagSeguro</h2>
                <?php if (!pagseguro_configured()): ?>
                    <p class="hint">Salve o token em Configuração para gerar o link de cobrança.</p>
                <?php else: ?>
                    <form method="post" action="/admin/pagseguro" class="form">
                        <input type="hidden" name="do" value="charge">
                        <input type="hidden" name="id" value="<?= (int) $ficha['id'] ?>">
                        <p class="hint"><?= ((int) ($ficha['auto_billing'] ?? 1) === 1)
                            ? 'Checkout recorrente no intervalo do plano (cartão). Depois o PagSeguro cobra sozinho; o webhook atualiza Pago até.'
                            : 'Cobrança avulsa (Pix, cartão ou boleto). Ative a cobrança automática no plano para recorrência.' ?></p>
                        <button class="btn ghost" type="submit">Gerar cobrança agora</button>
                    </form>
                <?php endif; ?>
                <?php if ($fichaPayments): ?>
                    <table class="saas-table">
                        <thead>
                        <tr>
                            <th>Quando</th>
                            <th>Valor</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($fichaPayments as $p): ?>
                            <tr>
                                <td><?= h(date('d/m/Y H:i', strtotime((string) $p['created_at']) ?: time())) ?></td>
                                <td><?= h(cents_label((int) $p['amount_cents'])) ?></td>
                                <td><span class="tag <?= ($p['status'] ?? '') === 'paid' ? 'online' : 'pending' ?>"><?= ($p['status'] ?? '') === 'paid' ? 'Pago' : 'Aguardando' ?></span></td>
                                <td>
                                    <?php if (($p['status'] ?? '') !== 'paid' && ($p['pay_url'] ?? '') !== ''): ?>
                                        <a class="btn ghost" href="<?= h((string) $p['pay_url']) ?>" target="_blank" rel="noopener">Abrir link</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>
    <?php else: ?>
        <section class="card">
            <div class="card-head">
                <div>
                    <h1>Clientes</h1>
                    <p class="lead">Contrato, cobrança e se o PC da loja está falando com este painel.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table class="saas-table">
                    <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Contrato</th>
                        <th>PC da loja</th>
                        <th>Financeiro</th>
                        <th>Pago até</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody id="saas-rows">
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>
                                <strong><?= h($r['name']) ?></strong>
                                <br><small><?= h($r['city'] !== '' ? $r['city'] : '—') ?><?= $r['contact'] !== '' ? ' · ' . h($r['contact']) : '' ?></small>
                            </td>
                            <td><span class="tag <?= $r['active'] ? 'online' : 'blocked' ?>"><?= $r['active'] ? 'Ativo' : 'Suspenso' ?></span></td>
                            <td>
                                <span class="tag conn-<?= h($r['health']['key']) ?>"><?= h($r['health']['label']) ?></span>
                                <br><small><?= h($r['health']['detail']) ?></small>
                            </td>
                            <td>
                                <span class="tag <?= $r['billing_status'] === 'atrasado' ? 'blocked' : ($r['billing_status'] === 'em_dia' ? 'online' : 'pending') ?>"><?= h($r['billing_label']) ?></span>
                                <br><small><?= h($r['plan']) ?><?= $r['monthly_fee'] !== '' ? ' · R$ ' . h($r['monthly_fee']) : '' ?></small>
                            </td>
                            <td><?= h($r['paid_until'] !== '' ? date('d/m/Y', strtotime($r['paid_until']) ?: time()) : '—') ?></td>
                            <td><a class="btn ghost" href="/admin?tab=clientes&amp;id=<?= (int) $r['id'] ?>">Abrir</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr class="empty"><td colspan="6">Nenhum cliente cadastrado.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <section class="card">
            <h2>Novo cliente</h2>
            <form method="post" action="/admin/stores" class="form form-inline">
                <input type="hidden" name="do" value="create">
                <label>Nome<input name="name" required placeholder="Ex.: Loja Centro"></label>
                <label>Cidade<input name="city" placeholder="Opcional"></label>
                <label>Contato<input name="contact" placeholder="Opcional"></label>
                <button class="btn" type="submit">Cadastrar</button>
            </form>
        </section>
    <?php endif; ?>

<?php elseif ($tab === 'instalador'): ?>
    <section class="card card-narrow">
        <h1>Instalador Windows</h1>
        <p class="lead">Arquivo que a loja executa no PC. Rede Wi-Fi e portal ficam nesse programa, não neste painel.</p>
        <?php if ($setupReady): ?>
            <div class="actions row">
                <a class="btn" href="/admin/instalador">Baixar WiFiDaLoja-Setup.exe</a>
            </div>
            <p class="hint"><?= h(basename($setupFile)) ?> · <?= h((string) round((int) filesize($setupFile) / 1048576, 1)) ?> MB</p>
        <?php else: ?>
            <p class="hint">Ainda não há arquivo publicado. Envie o .exe gerado com Empacotar.ps1.</p>
        <?php endif; ?>
        <form method="post" action="/admin/instalador" class="form" enctype="multipart/form-data">
            <label>Publicar .exe
                <input name="setup" type="file" accept=".exe,application/vnd.microsoft.portable-executable" required>
            </label>
            <button class="btn <?= $setupReady ? 'ghost' : '' ?>" type="submit"><?= $setupReady ? 'Substituir arquivo' : 'Enviar arquivo' ?></button>
        </form>
        <p class="hint">URL deste painel: <code><?= h(guess_panel_url()) ?></code></p>
    </section>

<?php else: ?>
    <section class="card card-narrow">
        <h1>PagSeguro</h1>
        <p class="lead">Receba as mensalidades na sua conta PagBank. O token fica só neste painel.</p>
        <ol class="steps">
            <li>Abra a conta em <a href="https://acesso.pagseguro.uol.com.br/" target="_blank" rel="noopener">PagSeguro / PagBank</a>.</li>
            <li>Em sandbox use o <a href="https://sandbox.pagseguro.uol.com.br/" target="_blank" rel="noopener">ambiente de testes</a>. Em produção: Vendas → Integrações → Gerar token.</li>
            <li>Cole o token abaixo. Cadastre esta URL de notificação se o painel pedir: <code><?= h(pagseguro_webhook_url()) ?></code></li>
        </ol>
        <form method="post" action="/admin/pagseguro" class="form">
            <input type="hidden" name="do" value="save">
            <label>Ambiente
                <select name="pagseguro_env">
                    <option value="sandbox" <?= pagseguro_env() === 'sandbox' ? 'selected' : '' ?>>Sandbox (testes)</option>
                    <option value="production" <?= pagseguro_env() === 'production' ? 'selected' : '' ?>>Produção</option>
                </select>
            </label>
            <label>Token da API
                <input name="pagseguro_token" type="password" autocomplete="off" placeholder="<?= pagseguro_configured() ? h(pagseguro_mask_token()) : 'Cole o token Bearer' ?>">
            </label>
            <p class="hint"><?= pagseguro_configured() ? 'Token salvo (' . h(pagseguro_mask_token()) . '). Deixe em branco para manter.' : 'O token não aparece inteiro depois de salvar.' ?></p>
            <label class="check"><input type="hidden" name="pagseguro_auto" value="0"><input type="checkbox" name="pagseguro_auto" value="1" <?= pagseguro_auto_enabled() ? 'checked' : '' ?>> Gerar cobrança automática pelos planos</label>
            <label>Antecedência (dias)
                <input name="pagseguro_advance_days" type="number" min="0" max="30" value="<?= (int) pagseguro_advance_days() ?>">
            </label>
            <p class="hint">Mensal, trimestral e anual definem o intervalo no PagSeguro. O agendamento do hosting deve chamar o cron abaixo todo dia.</p>
            <div class="actions row">
                <button class="btn" type="submit">Salvar integração</button>
            </div>
        </form>
        <?php if (pagseguro_configured()): ?>
            <p class="hint">Cron diário:<br><code><?= h(pagseguro_cron_url()) ?></code></p>
            <div class="actions row">
                <form method="post" action="/admin/pagseguro">
                    <input type="hidden" name="do" value="test">
                    <button class="btn ghost" type="submit">Testar token</button>
                </form>
                <form method="post" action="/admin/pagseguro">
                    <input type="hidden" name="do" value="run">
                    <button class="btn ghost" type="submit">Gerar cobranças agora</button>
                </form>
            </div>
        <?php endif; ?>
        <p class="hint">Webhook: <code><?= h(pagseguro_webhook_url()) ?></code> — precisa ser HTTPS público para o PagSeguro avisar o pagamento.</p>
    </section>
    <section class="card card-narrow">
        <h1>Conta</h1>
        <p class="lead">Acesso a este painel. Não altera o hotspot das lojas.</p>
        <form method="post" action="/admin/save" class="form">
            <label>Usuário<input name="admin_user" value="<?= h(setting('admin_user')) ?>" required></label>
            <label>Nova senha (em branco mantém a atual)<input name="admin_pass" type="password" autocomplete="new-password"></label>
            <button class="btn" type="submit">Salvar conta</button>
        </form>
    </section>
<?php endif; ?>
</main>
<script src="/assets/admin.js"></script>
</body>
</html>
