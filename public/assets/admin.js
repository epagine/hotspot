function asList(value) {
  if (!value) return [];
  if (Array.isArray(value)) return value;
  if (typeof value === 'object') return [value];
  return [];
}

function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, (ch) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  }[ch]));
}

function stateLabel(state) {
  return {
    online: 'online',
    pending: 'aguardando',
    awaiting_approval: 'no balcão',
    blocked: 'bloqueado',
    expired: 'encerrado',
  }[state] || state;
}

function formatWhen(value) {
  if (!value) return '';
  const d = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return esc(value);
  const pad = (n) => String(n).padStart(2, '0');
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function matchesFilter(row, filter, q) {
  const state = row.state;
  if (filter === 'online' && state !== 'online') return false;
  if (filter === 'blocked' && state !== 'blocked') return false;
  if (filter === 'fila' && state !== 'pending' && state !== 'awaiting_approval') return false;
  if (!q) return true;
  const blob = `${row.ip} ${row.status_code} ${row.phone || ''}`.toLowerCase();
  return blob.includes(q);
}

function renderClients(clients) {
  const body = document.getElementById('client-rows');
  if (!body) return;
  const filter = (document.getElementById('filter-state') || {}).value || '';
  const q = ((document.getElementById('filter-q') || {}).value || '').trim().toLowerCase();
  const rows = (clients || []).filter((row) => matchesFilter(row, filter, q));
  if (!rows.length) {
    body.innerHTML = '<tr class="empty"><td colspan="7">Nenhum cliente neste filtro.</td></tr>';
    return;
  }
  body.innerHTML = rows.map((c) => {
    const auth = c.state === 'online' && c.authorized_at
      ? `<br><small>liberado ${esc(formatWhen(c.authorized_at).slice(-5))}</small>`
      : '';
    let buttons = '';
    if (c.state !== 'online') buttons += '<button name="do" value="allow">Liberar</button>';
    if (c.state === 'online') buttons += '<button name="do" value="kick">Encerrar</button>';
    if (c.state !== 'blocked') buttons += '<button name="do" value="block">Bloquear</button>';
    return `<tr>
      <td>${esc(formatWhen(c.created_at))}</td>
      <td>${esc(c.ip)}<br><small>${esc(c.mac || '')}</small></td>
      <td><strong>${esc(c.status_code)}</strong></td>
      <td>${esc(c.phone || '')}</td>
      <td><span class="tag ${esc(c.state)}">${esc(c.label || stateLabel(c.state))}</span></td>
      <td>${esc(c.remaining || '—')}${auth}</td>
      <td>
        <form class="inline" method="post" action="/admin/action">
          <input type="hidden" name="id" value="${Number(c.id)}">
          <input type="hidden" name="store_id" value="${Number(document.body.dataset.store || 0)}">${buttons}
        </form>
      </td>
    </tr>`;
  }).join('');
}

function renderHotspot(data) {
  const hot = data.hotspot || {};
  const k = data.kpis || {};
  const el = document.getElementById('net-state');
  const err = document.getElementById('net-error');
  const real = document.getElementById('net-real');
  if (el) {
    if (hot.hotspot_on) {
      el.textContent = `Rede ligada${hot.ssid ? ' · ' + hot.ssid : ''} · portal ${hot.portal_ip || data.portal_ip || ''}`;
    } else if (!hot.agent_alive) {
      el.textContent = 'Serviço parado. O PC da loja precisa do agente (instalador + token).';
    } else {
      el.textContent = 'Rede desligada.';
    }
  }
  if (real) {
    real.textContent = `Internet: ${(k.internet_alias || '')} ${k.internet_ip || 'não detectada'}`;
  }
  if (err) {
    if (hot.error) {
      err.textContent = hot.error;
      err.classList.remove('hidden');
    } else {
      err.textContent = '';
      err.classList.add('hidden');
    }
  }
  const box = document.getElementById('live-devices');
  if (box) {
    const wifi = asList(hot.wifi_adapters);
    const neigh = asList(hot.neighbors);
    const wifiLine = wifi.length
      ? wifi.map((w) => `${w.name} (${w.status})`).join(', ')
      : 'nenhum adaptador Wi-Fi neste PC';
    const rows = neigh.slice(0, 12).map((n) => `<li>${esc(n.ip)} · ${esc(n.mac || '')}</li>`).join('');
    box.innerHTML = `<p class="hint">Wi-Fi: ${esc(wifiLine)}</p>` +
      (rows ? `<p class="hint">Aparelhos vistos na rede local</p><ul class="steps">${rows}</ul>` : '<p class="hint">Nenhum aparelho vizinho no momento.</p>');
  }
}

function renderKpis(data) {
  const k = data.kpis || {};
  const set = (id, value) => {
    const node = document.getElementById(id);
    if (node) node.textContent = value;
  };
  set('kpi-slots', k.slots ?? '0/8');
  set('kpi-online', k.online ?? 0);
  set('kpi-pending', k.pending ?? 0);
  set('kpi-wifi', k.windows_clients ?? 0);
  set('kpi-visits', k.visits_today ?? 0);
  set('kpi-ssid', k.ssid ?? '');
  const today = document.getElementById('today-line');
  if (today) {
    today.textContent = `${k.visits_today ?? 0} viram o portal · ${k.online_today ?? 0} ficaram online`;
  }
}

async function postHotspot(action) {
  const storeId = Number(document.body.dataset.store || 0);
  const res = await fetch('/admin/hotspot', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action, store_id: storeId }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.ok === false) {
    const err = document.getElementById('net-error');
    if (err) {
      err.textContent = data.message || data.error || 'Não foi possível enviar o comando.';
      err.classList.remove('hidden');
    }
  }
  return data;
}

async function refresh() {
  const storeId = Number(document.body.dataset.store || 0);
  const res = await fetch('/admin/status?store=' + storeId, { headers: { Accept: 'application/json' } });
  if (!res.ok) return;
  const data = await res.json();
  renderKpis(data);
  renderHotspot(data);
  renderClients(data.clients || []);
}

document.getElementById('btn-start')?.addEventListener('click', () => postHotspot('start').then(() => setTimeout(refresh, 1500)));
document.getElementById('btn-stop')?.addEventListener('click', () => postHotspot('stop').then(() => setTimeout(refresh, 1500)));
document.getElementById('btn-install-agent')?.addEventListener('click', async () => {
  await postHotspot('install-agent');
  const hint = document.getElementById('install-hint');
  if (hint) hint.classList.remove('hidden');
});
document.getElementById('filter-state')?.addEventListener('change', () => refresh());
document.getElementById('filter-q')?.addEventListener('input', () => refresh());

setInterval(refresh, 4000);
