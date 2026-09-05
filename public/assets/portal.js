const shareBtn = document.getElementById('share');
const confirmBtn = document.getElementById('confirm');
const refreshBtn = document.getElementById('refresh');
const portalRoot = (document.body && document.body.dataset.portalRoot) || '';

async function shareStatus() {
  const text = shareBtn.dataset.text;
  const code = shareBtn.dataset.code;
  const imageUrl = `${portalRoot}/arte/${code}.png`;
  try {
    const res = await fetch(imageUrl);
    const blob = await res.blob();
    const file = new File([blob], `status-${code}.png`, { type: 'image/png' });
    if (navigator.canShare && navigator.canShare({ files: [file], text })) {
      await navigator.share({ files: [file], text, title: 'Status' });
      return;
    }
    if (navigator.share) {
      await navigator.share({ text, title: 'Status' });
      return;
    }
  } catch (err) {
    if (err && err.name === 'AbortError') {
      return;
    }
  }
  window.location.href = 'https://wa.me/?text=' + encodeURIComponent(text);
}

async function confirmPosted() {
  confirmBtn.disabled = true;
  const phoneEl = document.getElementById('phone');
  const phone = phoneEl ? phoneEl.value : '';
  const res = await fetch(`${portalRoot}/confirmar`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ phone }),
  });
  const data = await res.json();
  if (!data.ok) {
    confirmBtn.disabled = false;
    alert(data.message || 'Não foi possível liberar o Wi-Fi.');
    return;
  }
  window.location.reload();
}

if (shareBtn) {
  shareBtn.addEventListener('click', shareStatus);
}
if (confirmBtn) {
  confirmBtn.addEventListener('click', confirmPosted);
}
if (refreshBtn) {
  refreshBtn.addEventListener('click', () => window.location.reload());
}
