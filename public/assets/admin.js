function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, (ch) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  }[ch]));
}

function renderSaas(data) {
  const k = data.kpis || {};
  const set = (id, value) => {
    const node = document.getElementById(id);
    if (node) node.textContent = value;
  };
  set('kpi-ativos', k.ativos ?? 0);
  set('kpi-ok', k.ok ?? 0);
  set('kpi-erro', k.erro ?? 0);
  set('kpi-offline', k.offline ?? 0);
  set('kpi-atrasados', k.atrasados ?? 0);
  set('kpi-total', k.total ?? 0);

  const body = document.getElementById('saas-rows');
  if (!body) return;
  const rows = data.clients || [];
  if (!rows.length) {
    body.innerHTML = '<tr class="empty"><td colspan="4">Nenhum cliente cadastrado.</td></tr>';
    return;
  }
  body.innerHTML = rows.map((r) => {
    const health = r.health || {};
    const city = r.city || '—';
    const extra = r.contact ? ` · ${esc(r.contact)}` : '';
    return `<tr>
      <td><strong>${esc(r.name)}</strong><br><small>${esc(city)}${extra}</small></td>
      <td><span class="tag ${r.active ? 'online' : 'blocked'}">${r.active ? 'Ativo' : 'Suspenso'}</span></td>
      <td><span class="tag conn-${esc(health.key)}">${esc(health.label)}</span><br><small>${esc(health.detail)}</small></td>
      <td><a class="btn ghost btn-sm" href="/admin/clientes/${Number(r.id)}">Abrir</a></td>
    </tr>`;
  }).join('');
}

async function refresh() {
  const res = await fetch('/admin/estado', { headers: { Accept: 'application/json' } });
  if (!res.ok) return;
  renderSaas(await res.json());
}

document.querySelector('.row-focus')?.scrollIntoView({ block: 'center' });
setInterval(refresh, 5000);
