<?php

declare(strict_types=1);

$plans = all_plans();
$featureCatalog = plan_feature_catalog();
?>
<section class="card">
    <div class="card-head">
        <div>
            <h2>Planos SaaS</h2>
            <p class="hint">Limites e recursos exibidos na landing e no painel das empresas.</p>
        </div>
        <button type="button" class="btn" id="plan-add-btn">Novo plano</button>
    </div>

    <?php if ($plans === []): ?>
        <p class="hint">Nenhum plano cadastrado. Clique em <strong>Novo plano</strong> para começar.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="saas-table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Plano</th>
                    <th>Preço</th>
                    <th>Limites</th>
                    <th>Recursos</th>
                    <th>Assinaturas</th>
                    <th>Status</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($plans as $p):
                    $pid = (int) $p['id'];
                    $subs = plan_active_subscriptions_count($pid);
                    $priceCents = (int) ($p['price_cents'] ?? 0);
                    $priceLabel = $priceCents === 0 ? 'Grátis' : cents_label($priceCents) . ' / ' . strtolower(plan_billing_label((string) ($p['billing_period'] ?? 'mensal')));
                    $planJson = htmlspecialchars(json_encode([
                        'id' => $pid,
                        'code' => (string) $p['code'],
                        'name' => (string) $p['name'],
                        'price_reais' => number_format($priceCents / 100, 2, ',', ''),
                        'billing_period' => (string) ($p['billing_period'] ?? 'mensal'),
                        'max_hotspots' => (int) ($p['max_hotspots'] ?? 0),
                        'max_clients' => (int) ($p['max_clients'] ?? 0),
                        'max_users' => (int) ($p['max_users'] ?? 0),
                        'sort_order' => (int) ($p['sort_order'] ?? 0),
                        'active' => !empty($p['active']),
                        'features' => plan_features_from_row($p),
                        'subscriptions' => $subs,
                    ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                ?>
                    <tr>
                        <td><?= (int) ($p['sort_order'] ?? 0) ?></td>
                        <td>
                            <strong><?= h((string) $p['name']) ?></strong><br>
                            <small><code><?= h((string) $p['code']) ?></code></small>
                        </td>
                        <td><?= h($priceLabel) ?></td>
                        <td class="plan-limits-cell">
                            <span>Hotspots <?= h(plan_limit_label((int) ($p['max_hotspots'] ?? 0))) ?></span>
                            <span>Clientes <?= h(plan_limit_label((int) ($p['max_clients'] ?? 0))) ?></span>
                            <span>Usuários <?= h(plan_limit_label((int) ($p['max_users'] ?? 0))) ?></span>
                        </td>
                        <td><small><?= h(plan_features_summary($p)) ?></small></td>
                        <td><?= $subs ?></td>
                        <td>
                            <?php if (!empty($p['active'])): ?>
                                <span class="badge badge-ok">Ativo</span>
                            <?php else: ?>
                                <span class="badge badge-muted">Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td class="table-actions">
                            <button type="button" class="btn ghost btn-sm plan-edit-btn" data-plan="<?= $planJson ?>">Editar</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<div class="app-modal" id="plan-modal" hidden aria-hidden="true">
    <div class="app-modal-backdrop" data-close-modal tabindex="-1"></div>
    <div class="app-modal-panel" role="dialog" aria-modal="true" aria-labelledby="plan-modal-title">
        <header class="app-modal-head">
            <div>
                <h2 id="plan-modal-title">Novo plano</h2>
                <p class="hint" id="plan-modal-lead">Defina limites, preço e recursos incluídos.</p>
            </div>
            <button type="button" class="app-modal-close" data-close-modal aria-label="Fechar">&times;</button>
        </header>
        <form method="post" action="/super/planos" class="form app-modal-form" id="plan-form">
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="/super/planos">
            <input type="hidden" name="id" id="plan-id" value="0">

            <div class="form-grid-2">
                <label>Nome
                    <input name="name" id="plan-name" required maxlength="120" placeholder="Profissional">
                </label>
                <label>Código
                    <input name="code" id="plan-code" required maxlength="60" pattern="[a-z0-9_]+" placeholder="profissional">
                    <small class="hint">Letras minúsculas, números e _ (único)</small>
                </label>
            </div>

            <div class="form-grid-2">
                <label>Preço (R$)
                    <input name="price_reais" id="plan-price" inputmode="decimal" placeholder="49,90" value="0,00">
                </label>
                <label>Período de cobrança
                    <select name="billing_period" id="plan-billing">
                        <option value="mensal">Mensal</option>
                        <option value="trimestral">Trimestral</option>
                        <option value="anual">Anual</option>
                    </select>
                </label>
            </div>

            <div class="form-grid-3">
                <label>Máx. hotspots
                    <input name="max_hotspots" id="plan-hotspots" type="number" min="0" value="1">
                    <small class="hint">0 = ilimitado</small>
                </label>
                <label>Máx. clientes
                    <input name="max_clients" id="plan-clients" type="number" min="0" value="0">
                    <small class="hint">0 = ilimitado</small>
                </label>
                <label>Máx. usuários
                    <input name="max_users" id="plan-users" type="number" min="0" value="2">
                    <small class="hint">0 = ilimitado</small>
                </label>
            </div>

            <label>Ordem na listagem
                <input name="sort_order" id="plan-sort" type="number" min="0" value="0">
            </label>

            <fieldset class="plan-features-field">
                <legend>Recursos incluídos</legend>
                <div class="plan-features-grid">
                    <?php foreach ($featureCatalog as $key => $label): ?>
                        <label class="check">
                            <input type="checkbox" name="features[]" value="<?= h($key) ?>" class="plan-feature-cb">
                            <?= h($label) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <label class="check">
                <input type="hidden" name="active" value="0">
                <input type="checkbox" name="active" id="plan-active" value="1" checked>
                Plano ativo (visível na landing e no painel)
            </label>

            <p class="hint" id="plan-subs-hint" hidden></p>

            <div class="app-modal-actions">
                <button type="button" class="btn ghost" data-close-modal>Cancelar</button>
                <button type="submit" class="btn" id="plan-submit-btn">Salvar plano</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('plan-modal');
    var form = document.getElementById('plan-form');
    if (!modal || !form) return;

    var title = document.getElementById('plan-modal-title');
    var lead = document.getElementById('plan-modal-lead');
    var submitBtn = document.getElementById('plan-submit-btn');
    var subsHint = document.getElementById('plan-subs-hint');
    var codeInput = document.getElementById('plan-code');

    function setFeatures(selected) {
        var set = {};
        (selected || []).forEach(function (f) { set[f] = true; });
        modal.querySelectorAll('.plan-feature-cb').forEach(function (cb) {
            cb.checked = !!set[cb.value];
        });
    }

    function openModal(plan) {
        var isEdit = !!(plan && plan.id);
        title.textContent = isEdit ? 'Editar plano' : 'Novo plano';
        lead.textContent = isEdit
            ? 'Alterações valem para novas assinaturas; empresas atuais mantêm o plano contratado até trocar.'
            : 'Defina limites, preço e recursos incluídos.';
        submitBtn.textContent = isEdit ? 'Salvar alterações' : 'Criar plano';

        document.getElementById('plan-id').value = isEdit ? String(plan.id) : '0';
        document.getElementById('plan-name').value = plan ? (plan.name || '') : '';
        codeInput.value = plan ? (plan.code || '') : '';
        codeInput.readOnly = isEdit && (plan.subscriptions || 0) > 0;
        document.getElementById('plan-price').value = plan ? (plan.price_reais || '0,00') : '0,00';
        document.getElementById('plan-billing').value = plan ? (plan.billing_period || 'mensal') : 'mensal';
        document.getElementById('plan-hotspots').value = plan ? String(plan.max_hotspots ?? 1) : '1';
        document.getElementById('plan-clients').value = plan ? String(plan.max_clients ?? 0) : '0';
        document.getElementById('plan-users').value = plan ? String(plan.max_users ?? 2) : '2';
        document.getElementById('plan-sort').value = plan ? String(plan.sort_order ?? 0) : '0';
        document.getElementById('plan-active').checked = plan ? !!plan.active : true;
        setFeatures(plan ? plan.features : []);

        if (isEdit && plan.subscriptions > 0) {
            subsHint.hidden = false;
            subsHint.textContent = plan.subscriptions + ' assinatura(s) usam este plano. O código não pode ser alterado.';
        } else {
            subsHint.hidden = true;
            subsHint.textContent = '';
        }

        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        document.getElementById('plan-name').focus();
    }

    function closeModal() {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        codeInput.readOnly = false;
    }

    document.getElementById('plan-add-btn')?.addEventListener('click', function () {
        openModal(null);
    });

    modal.querySelectorAll('[data-close-modal]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });

    document.querySelectorAll('.plan-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            try {
                openModal(JSON.parse(btn.getAttribute('data-plan') || '{}'));
            } catch (e) {}
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });

    document.getElementById('plan-name')?.addEventListener('input', function () {
        if (document.getElementById('plan-id').value !== '0') return;
        var slug = this.value.toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
        if (slug && !codeInput.dataset.touched) {
            codeInput.value = slug;
        }
    });
    codeInput?.addEventListener('input', function () {
        codeInput.dataset.touched = '1';
    });
})();
</script>
