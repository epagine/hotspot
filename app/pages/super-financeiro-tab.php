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

    $payGroups = platform_payments_grouped($payRows);

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

        <p class="admin-toolbar-note">Integração em <a href="/super/configuracoes/integracao">Configurações → Pagamentos</a> · Cron: <code class="admin-code-break"><?= h(payment_cron_url()) ?></code></p>

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

    <?php else: ?>

    <p class="admin-alert admin-alert-error">Pagamento online não configurado. Defina o provedor em <a href="/super/configuracoes/integracao">Configurações → Pagamentos</a>.</p>

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

        <p class="hint mb-3">Filtrando empresa: <strong><?= h((string) ($filterCo['trade_name'] ?? ('#' . $payCompanyId))) ?></strong>

            · <a href="/super/financeiro/cobrancas<?= $payFilter !== '' ? '?status=' . h($payFilter) : '' ?>">Ver todas</a></p>

    <?php endif; ?>



    <?php if ($payGroups === []): ?>

        <p class="admin-company-empty">Nenhuma cobrança encontrada.</p>

    <?php else: ?>

        <div class="admin-company-grid">

            <?php foreach ($payGroups as $group): ?>

            <section class="admin-company-card">

                <header class="admin-company-card-head">

                    <div>

                        <h3 class="admin-company-card-title"><?= h($group['label']) ?></h3>

                        <?php if ($group['legacy']): ?>

                            <span class="admin-company-card-kicker">Loja legada</span>

                        <?php elseif ($group['company_id'] > 0): ?>

                            <p class="admin-company-card-meta">Empresa #<?= (int) $group['company_id'] ?></p>

                        <?php endif; ?>

                    </div>

                    <?php if ($group['company_id'] > 0): ?>

                    <form method="post" action="/super/empresas" style="margin:0">

                        <?= csrf_field() ?>

                        <input type="hidden" name="do" value="impersonate">

                        <input type="hidden" name="id" value="<?= (int) $group['company_id'] ?>">

                        <button class="btn ghost btn-sm" type="submit">Abrir painel</button>

                    </form>

                    <?php endif; ?>

                </header>

                <div class="admin-company-card-body" style="padding-top:0;padding-bottom:0">

                    <div class="table-wrap">

                        <table class="saas-table">

                            <thead>

                            <tr>

                                <th>Data</th>

                                <th>Referência</th>

                                <th>Valor</th>

                                <th>Status</th>

                                <th></th>

                            </tr>

                            </thead>

                            <tbody>

                            <?php foreach ($group['payments'] as $p): ?>

                                <tr>

                                    <td><?= h((string) $p['created_at']) ?></td>

                                    <td>

                                        <code class="admin-code-break"><?= h((string) $p['reference_id']) ?></code>

                                        <?php if (!empty($p['plan_name'])): ?><br><small><?= h((string) $p['plan_name']) ?></small><?php endif; ?>

                                    </td>

                                    <td><?= h(cents_label((int) $p['amount_cents'])) ?></td>

                                    <td><span class="tag <?= ($p['status'] ?? '') === 'paid' ? 'online' : (($p['status'] ?? '') === 'pending' ? 'pending' : 'blocked') ?>"><?= h(payment_status_label((string) ($p['status'] ?? ''))) ?></span></td>

                                    <td class="table-actions">

                                        <?php if (($p['status'] ?? '') === 'pending' && !empty($p['pay_url'])): ?>

                                            <a class="btn ghost btn-sm" href="<?= h((string) $p['pay_url']) ?>" target="_blank" rel="noopener">Link</a>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

                <?php if ($group['company_id'] > 0 && $payCompanyId !== (int) $group['company_id']): ?>

                <footer class="admin-company-card-actions">

                    <a class="btn ghost btn-sm" href="/super/financeiro/cobrancas?empresa=<?= (int) $group['company_id'] ?><?= $payFilter !== '' ? '&status=' . h($payFilter) : '' ?>">Só esta empresa</a>

                    <a class="btn ghost btn-sm" href="/super/financeiro/assinaturas">Ver assinatura</a>

                </footer>

                <?php endif; ?>

            </section>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>



    <?php if ($payTotal > $payOffset + count($payRows)): ?>

        <p class="hint" style="margin-top:12px">

            <a href="/super/financeiro/cobrancas?offset=<?= $payOffset + $payLimit ?><?= $payFilter !== '' ? '&status=' . h($payFilter) : '' ?><?= $payCompanyId > 0 ? '&empresa=' . $payCompanyId : '' ?>">Carregar mais</a>

            · <?= (int) ($payOffset + count($payRows)) ?> de <?= $payTotal ?> cobrança(s)

        </p>

    <?php elseif ($payTotal > 0): ?>

        <p class="hint" style="margin-top:12px"><?= $payTotal ?> cobrança(s)</p>

    <?php endif; ?>



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



    <?php if ($subsRows === []): ?>

        <p class="admin-company-empty">Nenhuma assinatura encontrada.</p>

    <?php else: ?>

        <div class="admin-company-grid">

            <?php foreach ($subsRows as $row): ?>

                <?php

                $pending = $row['pending_payment'];

                $periodLabel = $row['period'] !== ''

                    ? date('d/m/Y', strtotime((string) $row['period']) ?: time())

                    : '—';

                $periodKey = ($row['billing_status'] ?? '') === 'trial' ? 'Trial até' : 'Vigência';

                ?>

            <section class="admin-company-card">

                <header class="admin-company-card-head">

                    <div>

                        <h3 class="admin-company-card-title"><?= h((string) $row['trade_name']) ?></h3>

                        <?php if ($row['email'] !== ''): ?>

                            <p class="admin-company-card-meta"><?= h((string) $row['email']) ?></p>

                        <?php endif; ?>

                    </div>

                    <span class="tag <?= h((string) $row['tag_class']) ?>"><?= h((string) $row['billing_label']) ?></span>

                </header>

                <div class="admin-company-card-body">

                    <dl class="admin-company-facts">

                        <div class="admin-company-fact">

                            <dt>Plano</dt>

                            <dd><?= h((string) $row['plan_name']) ?: '—' ?></dd>

                        </div>

                        <div class="admin-company-fact">

                            <dt><?= h($periodKey) ?></dt>

                            <dd><?= h($periodLabel) ?></dd>

                        </div>

                        <div class="admin-company-fact">

                            <dt>Cobrança aberta</dt>

                            <dd>

                                <?php if ($pending && !empty($pending['pay_url'])): ?>

                                    <?= h(cents_label((int) $pending['amount_cents'])) ?>

                                    · <a href="<?= h((string) $pending['pay_url']) ?>" target="_blank" rel="noopener">Abrir link</a>

                                <?php else: ?>

                                    <span class="hint">Nenhuma</span>

                                <?php endif; ?>

                            </dd>

                        </div>

                    </dl>

                </div>

                <footer class="admin-company-card-actions">

                    <a class="btn ghost btn-sm" href="/super/financeiro/cobrancas?empresa=<?= (int) $row['company_id'] ?>">Cobranças</a>

                    <form method="post" action="/super/empresas" style="margin:0">

                        <?= csrf_field() ?>

                        <input type="hidden" name="do" value="impersonate">

                        <input type="hidden" name="id" value="<?= (int) $row['company_id'] ?>">

                        <button class="btn ghost btn-sm" type="submit">Abrir painel</button>

                    </form>

                </footer>

            </section>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>



    <p class="admin-footnote">Lojas legadas usam o portal <a href="/cliente">/cliente</a>. Vincule em <a href="/super/empresas">Empresas</a>.</p>

<?php endif; ?>

