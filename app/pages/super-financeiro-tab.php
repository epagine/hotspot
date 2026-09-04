<?php

declare(strict_types=1);

/** @var string $finSec */
/** @var string $flashOk */
/** @var string $flashErr */

$payStatus = payment_status_filter(preg_replace('/[^a-z]/', '', (string) ($_GET['status'] ?? '')) ?: null);
$payFilter = (string) ($_GET['status'] ?? '');
$payCompanyId = (int) ($_GET['empresa'] ?? 0);
$payOffset = max(0, (int) ($_GET['offset'] ?? 0));
$payLimit = 50;

$billingFilter = preg_replace('/[^a-z]/', '', (string) ($_GET['filtro'] ?? '')) ?: null;
if ($billingFilter === 'todas') {
    $billingFilter = null;
}

$finNav = [
    ['cobrancas', 'Cobranças'],
    ['assinaturas', 'Assinaturas'],
];
?>
<nav class="admin-config-nav">
    <?php foreach ($finNav as [$secKey, $secLabel]): ?>
        <a href="/super/financeiro/<?= h($secKey) ?>"
           class="admin-config-tab<?= $finSec === $secKey ? ' is-active' : '' ?>"><?= h($secLabel) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($finSec === 'cobrancas'): ?>
    <?php
    $payKpis = platform_payment_kpis();
    $payData = platform_payments($payFilter !== '' ? $payFilter : null, $payLimit, $payOffset, $payCompanyId > 0 ? $payCompanyId : null);
    $payRows = $payData['rows'];
    $payTotal = (int) $payData['total'];
    $statusFilters = [
        '' => 'Todas',
        'pending' => 'Em aberto',
        'paid' => 'Pagas',
        'expired' => 'Expiradas',
    ];
    ?>
    <section class="admin-stat-panel admin-stat-panel-compact">
        <div class="admin-stat-item">
            <span class="admin-stat-label">Em aberto</span>
            <strong class="admin-stat-value"><?= (int) $payKpis['pending_count'] ?></strong>
            <span class="admin-stat-meta"><?= h(cents_label((int) $payKpis['pending_cents'])) ?></span>
        </div>
        <div class="admin-stat-item">
            <span class="admin-stat-label">Pagas (30d)</span>
            <strong class="admin-stat-value"><?= (int) $payKpis['paid_30d_count'] ?></strong>
        </div>
        <div class="admin-stat-item">
            <span class="admin-stat-label">Expiradas</span>
            <strong class="admin-stat-value"><?= (int) $payKpis['expired_count'] ?></strong>
        </div>
    </section>

    <?php if (payment_configured()): ?>
    <div class="admin-toolbar">
        <p class="admin-toolbar-note">Cron: <code class="admin-code-break"><?= h(payment_cron_url()) ?></code></p>
        <div class="admin-toolbar-actions">
            <form method="post" action="/super/financeiro/cobrancas">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="test">
                <input type="hidden" name="return_to" value="/super/financeiro/cobrancas">
                <button class="btn ghost btn-sm" type="submit">Testar</button>
            </form>
            <form method="post" action="/super/financeiro/cobrancas">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="run">
                <input type="hidden" name="return_to" value="/super/financeiro/cobrancas">
                <button class="btn btn-sm" type="submit">Gerar cobranças</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <nav class="admin-filter-nav">
        <?php foreach ($statusFilters as $key => $label): ?>
            <?php
            $href = '/super/financeiro/cobrancas';
            if ($key !== '') {
                $href .= '?status=' . rawurlencode($key);
            }
            if ($payCompanyId > 0) {
                $href .= ($key !== '' ? '&' : '?') . 'empresa=' . $payCompanyId;
            }
            ?>
            <a href="<?= h($href) ?>" class="admin-filter-tab<?= $payFilter === $key ? ' is-active' : '' ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($payCompanyId > 0): ?>
        <?php $filterCo = find_company($payCompanyId); ?>
        <p class="hint">Filtrando empresa: <strong><?= h((string) ($filterCo['trade_name'] ?? ('#' . $payCompanyId))) ?></strong>
            · <a href="/super/financeiro/cobrancas">Ver todas</a></p>
    <?php endif; ?>

    <section class="card">
        <div class="table-wrap">
            <table class="saas-table">
                <thead>
                <tr>
                    <th>Data</th>
                    <th>Empresa / Loja</th>
                    <th>Referência</th>
                    <th>Valor</th>
                    <th>Status</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($payRows as $p): ?>
                    <tr>
                        <td><?= h((string) $p['created_at']) ?></td>
                        <td>
                            <strong><?= h(platform_payment_client_label($p)) ?></strong>
                            <?php if (!empty($p['plan_name'])): ?><br><small><?= h((string) $p['plan_name']) ?></small><?php endif; ?>
                        </td>
                        <td><code class="admin-code-break"><?= h((string) $p['reference_id']) ?></code></td>
                        <td><?= h(cents_label((int) $p['amount_cents'])) ?></td>
                        <td><span class="tag <?= ($p['status'] ?? '') === 'paid' ? 'online' : (($p['status'] ?? '') === 'pending' ? 'pending' : 'blocked') ?>"><?= h(payment_status_label((string) ($p['status'] ?? ''))) ?></span></td>
                        <td class="table-actions">
                            <?php if (($p['status'] ?? '') === 'pending' && !empty($p['pay_url'])): ?>
                                <a class="btn ghost btn-sm" href="<?= h((string) $p['pay_url']) ?>" target="_blank" rel="noopener">Abrir link</a>
                            <?php endif; ?>
                            <?php if ((int) ($p['company_id'] ?? 0) > 0): ?>
                                <a class="btn ghost btn-sm" href="/super/financeiro/cobrancas?empresa=<?= (int) $p['company_id'] ?>">Filtrar</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($payRows === []): ?>
                    <tr class="empty"><td colspan="6">Nenhuma cobrança encontrada.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($payTotal > $payOffset + count($payRows)): ?>
            <p class="hint" style="margin-top:12px">
                <a href="/super/financeiro/cobrancas?offset=<?= $payOffset + $payLimit ?><?= $payFilter !== '' ? '&status=' . h($payFilter) : '' ?><?= $payCompanyId > 0 ? '&empresa=' . $payCompanyId : '' ?>">Carregar mais</a>
                · <?= (int) ($payOffset + count($payRows)) ?> de <?= $payTotal ?>
            </p>
        <?php elseif ($payTotal > 0): ?>
            <p class="hint" style="margin-top:12px"><?= $payTotal ?> registro(s)</p>
        <?php endif; ?>
    </section>

<?php else: ?>
    <?php
    $subsData = platform_subscriptions_overview($billingFilter);
    $subsRows = $subsData['rows'];
    $sitFilters = [
        '' => 'Todas',
        'trial' => 'Trial',
        'ativa' => 'Ativa',
        'pendente' => 'Pendente',
        'atrasada' => 'Atrasada',
        'suspensa' => 'Suspensa',
    ];
    ?>

    <nav class="admin-filter-nav">
        <?php
        $activeFiltro = $billingFilter ?? '';
        foreach ($sitFilters as $key => $label):
            $href = $key === '' ? '/super/financeiro/assinaturas' : '/super/financeiro/assinaturas?filtro=' . rawurlencode($key);
        ?>
            <a href="<?= h($href) ?>" class="admin-filter-tab<?= $activeFiltro === $key ? ' is-active' : '' ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <section class="card">
        <div class="table-wrap">
            <table class="saas-table">
                <thead>
                <tr>
                    <th>Empresa</th>
                    <th>Plano</th>
                    <th>Status</th>
                    <th>Vigência / Trial</th>
                    <th>Cobrança aberta</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($subsRows as $row): ?>
                    <tr>
                        <td>
                            <strong><?= h((string) $row['trade_name']) ?></strong>
                            <?php if ($row['email'] !== ''): ?><br><small><?= h((string) $row['email']) ?></small><?php endif; ?>
                        </td>
                        <td><?= h((string) $row['plan_name']) ?></td>
                        <td><span class="tag <?= h((string) $row['tag_class']) ?>"><?= h((string) $row['billing_label']) ?></span></td>
                        <td><?= h($row['period'] !== '' ? date('d/m/Y', strtotime((string) $row['period']) ?: time()) : '—') ?></td>
                        <td>
                            <?php $pending = $row['pending_payment']; ?>
                            <?php if ($pending && !empty($pending['pay_url'])): ?>
                                <a href="<?= h((string) $pending['pay_url']) ?>" target="_blank" rel="noopener">Abrir link</a>
                            <?php else: ?>
                                <span class="hint">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="table-actions">
                            <a class="btn ghost btn-sm" href="/super/financeiro/cobrancas?empresa=<?= (int) $row['company_id'] ?>">Cobranças</a>
                            <form method="post" action="/super/empresas" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="do" value="impersonate">
                                <input type="hidden" name="id" value="<?= (int) $row['company_id'] ?>">
                                <button class="btn ghost btn-sm" type="submit">Abrir</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($subsRows === []): ?>
                    <tr class="empty"><td colspan="6">Nenhuma assinatura encontrada.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <p class="admin-footnote">Lojas legadas usam o portal <a href="/cliente">/cliente</a>. Vincule em <a href="/super/empresas">Empresas</a>.</p>
<?php endif; ?>
