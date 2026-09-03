<?php

declare(strict_types=1);

$plans = all_plans();
$featureCatalog = plan_feature_catalog();
?>
<section class="bg-white border border-line rounded-card p-6 shadow-sm mb-4">
    <div class="flex flex-wrap justify-between items-end gap-4 mb-4">
        <div>
            <h2 class="text-lg font-bold">Planos SaaS</h2>
            <p class="text-sm text-muted mt-1">Limites e recursos exibidos na landing e no painel das empresas.</p>
        </div>
        <button type="button" class="bg-accent hover:bg-accent/90 text-white font-bold text-sm py-2.5 px-4 rounded-btn transition" id="plan-add-btn">Novo plano</button>
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

<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4" id="plan-modal" hidden inert>
    <div class="absolute inset-0 bg-ink/40 backdrop-blur-sm" data-close-modal></div>
    <div class="relative z-10 w-full max-w-3xl max-h-[min(90vh,640px)] flex flex-col bg-white border border-line rounded-card shadow-xl overflow-hidden" role="dialog" aria-modal="true" aria-labelledby="plan-modal-title">
        <header class="shrink-0 flex justify-between items-center gap-3 px-5 py-3 border-b border-line">
            <div class="min-w-0">
                <h2 id="plan-modal-title" class="text-base font-bold leading-tight">Novo plano</h2>
                <p class="text-xs text-muted mt-0.5 truncate" id="plan-modal-lead">Limites, preço e recursos.</p>
            </div>
            <button type="button" class="text-muted hover:text-ink text-2xl leading-none p-1 bg-transparent border-0 cursor-pointer shrink-0" data-close-modal aria-label="Fechar">&times;</button>
        </header>
        <form method="post" action="/super/planos" class="app-modal-form flex flex-col min-h-0 flex-1 m-0" id="plan-form">
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="/super/planos">
            <input type="hidden" name="id" id="plan-id" value="0">

            <div class="flex-1 overflow-y-auto px-5 py-4 grid md:grid-cols-[1.15fr_0.85fr] gap-5">
                <div class="grid gap-3 content-start">
                    <div class="grid sm:grid-cols-2 gap-3">
                        <label class="text-xs font-semibold text-muted">Nome
                            <input name="name" id="plan-name" required maxlength="120" placeholder="Profissional">
                        </label>
                        <label class="text-xs font-semibold text-muted">Código
                            <input name="code" id="plan-code" required maxlength="60" pattern="[a-z0-9_]+" placeholder="profissional" title="Letras minúsculas, números e _">
                        </label>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-3">
                        <label class="text-xs font-semibold text-muted">Preço (R$)
                            <input name="price_reais" id="plan-price" inputmode="decimal" placeholder="49,90" value="0,00">
                        </label>
                        <label class="text-xs font-semibold text-muted">Cobrança
                            <select name="billing_period" id="plan-billing">
                                <option value="mensal">Mensal</option>
                                <option value="trimestral">Trimestral</option>
                                <option value="anual">Anual</option>
                            </select>
                        </label>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-muted mb-2">Limites <span class="font-normal">(0 = ilimitado)</span></p>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <label class="text-[11px] text-muted">Hotspots
                                <input name="max_hotspots" id="plan-hotspots" type="number" min="0" value="1">
                            </label>
                            <label class="text-[11px] text-muted">Clientes
                                <input name="max_clients" id="plan-clients" type="number" min="0" value="0">
                            </label>
                            <label class="text-[11px] text-muted">Usuários
                                <input name="max_users" id="plan-users" type="number" min="0" value="2">
                            </label>
                            <label class="text-[11px] text-muted">Ordem
                                <input name="sort_order" id="plan-sort" type="number" min="0" value="0">
                            </label>
                        </div>
                    </div>
                    <p class="hint text-xs m-0" id="plan-subs-hint" hidden></p>
                </div>

                <fieldset class="plan-features-field m-0 p-3 min-h-0">
                    <legend class="text-xs font-semibold px-1">Recursos</legend>
                    <div class="plan-features-grid">
                        <?php foreach ($featureCatalog as $key => $label): ?>
                            <label class="check text-sm">
                                <input type="checkbox" name="features[]" value="<?= h($key) ?>" class="plan-feature-cb">
                                <?= h($label) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            </div>

            <div class="shrink-0 flex flex-wrap items-center justify-between gap-3 px-5 py-3 border-t border-line bg-surface/80">
                <label class="check m-0 text-sm">
                    <input type="hidden" name="active" value="0">
                    <input type="checkbox" name="active" id="plan-active" value="1" checked>
                    Plano ativo
                </label>
                <div class="flex gap-2 ml-auto">
                    <button type="button" class="text-sm font-semibold text-ink border border-line bg-white px-4 py-2 rounded-btn hover:bg-hover transition" data-close-modal>Cancelar</button>
                    <button type="submit" class="text-sm font-bold bg-accent hover:bg-accent/90 text-white px-4 py-2 rounded-btn transition" id="plan-submit-btn">Salvar plano</button>
                </div>
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
    var lastOpener = null;

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
            ? 'Vale para novas assinaturas.'
            : 'Limites, preço e recursos.';
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

        modal.inert = false;
        modal.hidden = false;
        modal.removeAttribute('aria-hidden');
        document.body.classList.add('modal-open');
        document.getElementById('plan-name').focus();
    }

    function closeModal() {
        var active = document.activeElement;
        if (active && modal.contains(active) && typeof active.blur === 'function') {
            active.blur();
        }
        if (lastOpener && document.contains(lastOpener) && typeof lastOpener.focus === 'function') {
            lastOpener.focus();
        }
        modal.hidden = true;
        modal.inert = true;
        document.body.classList.remove('modal-open');
        codeInput.readOnly = false;
    }

    document.getElementById('plan-add-btn')?.addEventListener('click', function () {
        lastOpener = this;
        openModal(null);
    });

    modal.querySelectorAll('[data-close-modal]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });

    document.querySelectorAll('.plan-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            lastOpener = btn;
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
