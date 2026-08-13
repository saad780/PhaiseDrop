const state = {
  links: [],
  selected: new Map(),
  pickerPath: '',
  ready: false,
  refreshTimer: null
};

const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
}[char]));

const csrf = () => String(document.querySelector('meta[name="csrf-token"]')?.content || window.csrfToken || '');

async function api(url, options = {}) {
  const headers = { ...(options.headers || {}) };
  if (options.body && typeof options.body !== 'string') {
    headers['Content-Type'] = 'application/json';
    headers['X-CSRF-Token'] = csrf();
    options.body = JSON.stringify(options.body);
  }
  const response = await fetch(url, { credentials: 'include', ...options, headers });
  const body = await response.json().catch(() => ({}));
  if (!response.ok || body.error) throw new Error(body.error || `Request failed (${response.status})`);
  return body;
}

function toast(message, tone = 'info') {
  if (typeof window.showToast === 'function') window.showToast(message, tone);
}

function formatBytes(bytes) {
  const n = Number(bytes || 0);
  if (!Number.isFinite(n) || n <= 0) return '0 B';
  if (n < 1024) return `${n} B`;
  if (n < 1048576) return `${(n / 1024).toFixed(1)} KB`;
  if (n < 1073741824) return `${(n / 1048576).toFixed(1)} MB`;
  return `${(n / 1073741824).toFixed(1)} GB`;
}

function relativeTime(timestamp) {
  const seconds = Math.max(0, Number(timestamp || 0) - Math.floor(Date.now() / 1000));
  if (!seconds) return 'now';
  if (seconds < 3600) return `${Math.ceil(seconds / 60)}m`;
  if (seconds < 86400) return `${Math.ceil(seconds / 3600)}h`;
  return `${Math.ceil(seconds / 86400)}d`;
}

function isAdmin() {
  const auth = window.__FR_AUTH_STATE || {};
  if (typeof auth.isAdmin !== 'undefined') return !!auth.isAdmin;
  return ['1', 'true'].includes(String(localStorage.getItem('isAdmin') || '').toLowerCase());
}

function shell() {
  return document.querySelector('.main-wrapper');
}

function installShell() {
  if (document.getElementById('phaiseDashboard') || !shell()) return;
  const dashboard = document.createElement('section');
  dashboard.id = 'phaiseDashboard';
  dashboard.className = 'pd-dashboard';
  dashboard.innerHTML = `
    <div class="pd-dashboard-inner">
      <header class="pd-dashboard-hero">
        <div>
          <p class="pd-kicker">Private transfer workspace</p>
          <h2>Move files in either direction.</h2>
          <p>Create a short link to receive uploads or securely share anything already in your Drop folder.</p>
        </div>
        <button class="pd-refresh" id="pdRefreshLinks" type="button" aria-label="Refresh active links">
          <span class="material-icons" aria-hidden="true">refresh</span><span>Refresh</span>
        </button>
      </header>
      <div class="pd-create-grid">
        <button type="button" class="pd-create-card pd-create-receive" id="pdCreateUpload">
          <span class="pd-create-icon material-icons" aria-hidden="true">move_to_inbox</span>
          <span><strong>Request an upload</strong><small>Give someone a private place to send files or folders.</small></span>
          <span class="material-icons pd-create-arrow" aria-hidden="true">arrow_forward</span>
        </button>
        <button type="button" class="pd-create-card pd-create-share" id="pdCreateShare">
          <span class="pd-create-icon material-icons" aria-hidden="true">outbox</span>
          <span><strong>Share files</strong><small>Choose one or many NAS items and create a public download link.</small></span>
          <span class="material-icons pd-create-arrow" aria-hidden="true">arrow_forward</span>
        </button>
      </div>
      <div class="pd-stat-grid" aria-label="Link summary">
        <div class="pd-stat"><span>Active links</span><strong id="pdStatTotal">—</strong></div>
        <div class="pd-stat"><span>Receiving</span><strong id="pdStatReceive">—</strong></div>
        <div class="pd-stat"><span>Sharing</span><strong id="pdStatShare">—</strong></div>
        <div class="pd-stat"><span>Expiring within 24h</span><strong id="pdStatSoon">—</strong></div>
      </div>
      <section class="pd-links-panel">
        <div class="pd-panel-heading">
          <div><p class="pd-kicker">Live access</p><h3>Active links</h3></div>
          <div class="pd-link-tools">
            <label class="pd-search"><span class="material-icons" aria-hidden="true">search</span><input id="pdLinkSearch" type="search" placeholder="Search links" aria-label="Search active links"></label>
            <select id="pdLinkFilter" aria-label="Filter links"><option value="all">All links</option><option value="upload">Receiving</option><option value="share">Sharing</option><option value="legacy">Legacy</option></select>
          </div>
        </div>
        <div id="pdLinksList" class="pd-links-list"><div class="pd-loading">Loading active links…</div></div>
      </section>
    </div>`;
  shell().insertBefore(dashboard, shell().firstChild);

  const nav = document.createElement('nav');
  nav.className = 'pd-workspace-nav';
  nav.setAttribute('aria-label', 'Workspace');
  nav.innerHTML = `
    <button id="pdNavDashboard" type="button" class="is-active"><span class="material-icons">space_dashboard</span><span>Dashboard</span></button>
    <button id="pdNavFiles" type="button"><span class="material-icons">folder</span><span>Files</span></button>
    <button id="pdNavSettings" type="button"><span class="material-icons">tune</span><span>Settings</span></button>`;
  document.querySelector('.header-title')?.insertAdjacentElement('afterend', nav);

  document.getElementById('pdNavDashboard')?.addEventListener('click', showDashboard);
  document.getElementById('pdNavFiles')?.addEventListener('click', showFiles);
  document.getElementById('pdNavSettings')?.addEventListener('click', async () => {
    const mod = await import('./adminPanel.js?v={{APP_QVER}}');
    if (typeof mod.openAdminPanel === 'function') mod.openAdminPanel();
  });
  document.getElementById('pdCreateUpload')?.addEventListener('click', openUploadCreator);
  document.getElementById('pdCreateShare')?.addEventListener('click', () => openShareCreator());
  document.getElementById('pdRefreshLinks')?.addEventListener('click', refreshLinks);
  document.getElementById('pdLinkSearch')?.addEventListener('input', renderLinks);
  document.getElementById('pdLinkFilter')?.addEventListener('change', renderLinks);

  window.PhaiseDrop = {
    openUpload: openUploadCreator,
    openShare: (items = []) => openShareCreator(items),
    showDashboard,
    showFiles,
    refresh: refreshLinks
  };
}

function setNav(active) {
  document.getElementById('pdNavDashboard')?.classList.toggle('is-active', active === 'dashboard');
  document.getElementById('pdNavFiles')?.classList.toggle('is-active', active === 'files');
}

function showDashboard() {
  document.body.classList.add('pd-dashboard-mode');
  setNav('dashboard');
  refreshLinks();
}

async function showFiles(folder = '') {
  document.body.classList.remove('pd-dashboard-mode');
  setNav('files');
  if (folder) {
    window.currentFolder = folder;
    try { localStorage.setItem('lastOpenedFolder', folder); } catch (_) {}
    try {
      const folders = await import('./folderManager.js?v={{APP_QVER}}');
      if (typeof folders.loadFolderTree === 'function') await folders.loadFolderTree(folder);
    } catch (_) {}
  }
}

function renderLinks() {
  const host = document.getElementById('pdLinksList');
  if (!host) return;
  const query = String(document.getElementById('pdLinkSearch')?.value || '').trim().toLowerCase();
  const filter = String(document.getElementById('pdLinkFilter')?.value || 'all');
  const links = state.links.filter((link) => {
    const legacy = !!link.legacy;
    if (filter === 'legacy' && !legacy) return false;
    if (filter === 'upload' && link.type !== 'upload') return false;
    if (filter === 'share' && link.type !== 'share') return false;
    if (query && !`${link.title} ${link.code} ${link.folder}`.toLowerCase().includes(query)) return false;
    return true;
  });
  if (!links.length) {
    host.innerHTML = `<div class="pd-empty-links"><span class="material-icons">link_off</span><strong>No matching active links</strong><p>Create a request or share to get started.</p></div>`;
    return;
  }
  host.innerHTML = links.map((link) => {
    const receive = link.type === 'upload';
    const expiresAt = Number(link.expiresAt || 0);
    const idleAt = Number(link.idleExpiresAt || 0);
    const deadline = idleAt > 0 ? Math.min(expiresAt || idleAt, idleAt) : expiresAt;
    const usage = receive && Number(link.maxTotalBytes || 0) > 0
      ? `<div class="pd-usage"><span style="width:${Math.min(100, (Number(link.acceptedBytes || 0) / Number(link.maxTotalBytes)) * 100)}%"></span></div><small>${formatBytes(link.acceptedBytes)} of ${formatBytes(link.maxTotalBytes)}</small>`
      : `<small>${Number(link.itemCount || 0)} item${Number(link.itemCount || 0) === 1 ? '' : 's'}</small>`;
    return `<article class="pd-link-row" data-id="${esc(link.id)}" data-type="${esc(link.type)}">
      <div class="pd-link-kind ${receive ? 'is-receive' : 'is-share'}"><span class="material-icons">${receive ? 'move_to_inbox' : 'outbox'}</span></div>
      <div class="pd-link-main"><div class="pd-link-title"><strong>${esc(link.title || 'Untitled link')}</strong>${link.legacy ? '<span class="pd-badge">Legacy</span>' : ''}${link.pinProtected ? '<span class="pd-badge pd-badge-lock">PIN</span>' : ''}</div><span>${receive ? 'Receiving into ' + esc(link.folder || 'Drop') : 'Sharing files'}</span></div>
      <div class="pd-code-wrap"><span>Public code</span><strong>${esc(link.code || 'long link')}</strong></div>
      <div class="pd-link-usage">${usage}</div>
      <div class="pd-link-expiry"><span>Closes in</span><strong>${relativeTime(deadline)}</strong></div>
      <div class="pd-row-actions">
        <button type="button" data-action="copy" title="Copy public URL"><span class="material-icons">content_copy</span></button>
        <button type="button" data-action="preview" title="Preview public page"><span class="material-icons">open_in_new</span></button>
        ${receive && link.folder ? '<button type="button" data-action="folder" title="Open destination folder"><span class="material-icons">folder_open</span></button>' : ''}
        ${link.editable ? '<button type="button" data-action="edit" title="Edit settings"><span class="material-icons">edit</span></button>' : ''}
        <button type="button" data-action="revoke" class="is-danger" title="Close link"><span class="material-icons">link_off</span></button>
      </div>
    </article>`;
  }).join('');
  host.querySelectorAll('.pd-link-row').forEach((row) => {
    const link = state.links.find((entry) => entry.id === row.dataset.id && entry.type === row.dataset.type);
    if (!link) return;
    row.querySelector('[data-action="copy"]')?.addEventListener('click', () => copyLink(link));
    row.querySelector('[data-action="preview"]')?.addEventListener('click', () => window.open(link.url, '_blank', 'noopener'));
    row.querySelector('[data-action="folder"]')?.addEventListener('click', () => showFiles(link.folder));
    row.querySelector('[data-action="edit"]')?.addEventListener('click', () => openEdit(link));
    row.querySelector('[data-action="revoke"]')?.addEventListener('click', () => revokeLink(link));
  });
}

async function refreshLinks() {
  const refresh = document.getElementById('pdRefreshLinks');
  refresh?.classList.add('is-spinning');
  try {
    const result = await api('/api/links/list.php');
    state.links = Array.isArray(result.links) ? result.links : [];
    const now = Date.now() / 1000;
    document.getElementById('pdStatTotal').textContent = state.links.length;
    document.getElementById('pdStatReceive').textContent = state.links.filter((x) => x.type === 'upload').length;
    document.getElementById('pdStatShare').textContent = state.links.filter((x) => x.type === 'share').length;
    document.getElementById('pdStatSoon').textContent = state.links.filter((x) => Number(x.expiresAt || 0) - now <= 86400).length;
    renderLinks();
  } catch (error) {
    const host = document.getElementById('pdLinksList');
    if (host) host.innerHTML = `<div class="pd-empty-links"><strong>Could not load active links</strong><p>${esc(error.message)}</p></div>`;
  } finally {
    refresh?.classList.remove('is-spinning');
  }
}

async function copyLink(link) {
  try {
    await navigator.clipboard.writeText(link.url);
    toast('Link copied.', 'success');
  } catch (_) {
    window.prompt('Copy this link:', link.url);
  }
}

function modal({ title, subtitle = '', body, wide = false }) {
  const overlay = document.createElement('div');
  overlay.className = 'pd-modal-backdrop';
  overlay.innerHTML = `<section class="pd-modal${wide ? ' pd-modal-wide' : ''}" role="dialog" aria-modal="true" aria-labelledby="pdModalTitle">
    <header><div><p class="pd-kicker">Phaise Drop</p><h3 id="pdModalTitle">${esc(title)}</h3>${subtitle ? `<p>${esc(subtitle)}</p>` : ''}</div><button type="button" class="pd-modal-close" aria-label="Close"><span class="material-icons">close</span></button></header>
    <div class="pd-modal-body">${body}</div></section>`;
  document.body.appendChild(overlay);
  const close = () => overlay.remove();
  overlay.querySelector('.pd-modal-close')?.addEventListener('click', close);
  overlay.addEventListener('click', (event) => { if (event.target === overlay) close(); });
  document.addEventListener('keydown', function onKey(event) {
    if (event.key === 'Escape') { close(); document.removeEventListener('keydown', onKey); }
  });
  return { overlay, close };
}

function resultMarkup() {
  return `<div class="pd-created-result" hidden><span class="material-icons">check_circle</span><div><strong>Link ready</strong><p class="pd-created-url"></p><small class="pd-created-detail"></small></div><button type="button" class="pd-copy-created">Copy link</button></div>`;
}

function openUploadCreator() {
  const { overlay, close } = modal({
    title: 'Request an upload',
    subtitle: 'Create a private, upload-only destination. Senders never see other NAS files.',
    body: `<form id="pdUploadForm" class="pd-form">
      <label><span>Request name</span><input name="title" maxlength="120" required placeholder="e.g. Smith project originals" autofocus></label>
      <label><span>Instructions <small>optional</small></span><textarea name="instructions" maxlength="1000" rows="3" placeholder="What should the sender upload?"></textarea></label>
      <div class="pd-field-grid">
        <label><span>Hard expiry</span><div class="pd-unit-field"><input name="expiry" type="number" min="1" max="720" value="48" required><b>hours</b></div></label>
        <label><span>Inactivity timeout</span><div class="pd-unit-field"><input name="idle" type="number" min="1" max="720" value="48" required><b>hours</b></div></label>
        <label><span>Total capacity</span><div class="pd-unit-field"><input name="total" type="number" min="1" max="1953" value="100" required><b>GB</b></div></label>
      </div>
      <details class="pd-advanced"><summary>Advanced limits</summary><label><span>Maximum per file</span><div class="pd-unit-field"><input name="perFile" type="number" min="1" max="100" value="100"><b>GB</b></div></label></details>
      ${resultMarkup()}
      <footer class="pd-form-actions"><button type="button" class="pd-button-ghost" data-cancel>Cancel</button><button type="submit" class="pd-button-primary">Create upload link</button></footer>
    </form>`
  });
  const form = overlay.querySelector('#pdUploadForm');
  overlay.querySelector('[data-cancel]')?.addEventListener('click', close);
  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submit = form.querySelector('[type="submit"]');
    submit.disabled = true; submit.textContent = 'Creating…';
    const data = new FormData(form);
    const total = Number(data.get('total'));
    const perFile = Math.min(total, Number(data.get('perFile') || total));
    try {
      const result = await api('/api/links/createUpload.php', { method: 'POST', body: {
        title: data.get('title'), instructions: data.get('instructions'),
        expiresInSeconds: Number(data.get('expiry')) * 3600,
        idleTimeoutSeconds: Number(data.get('idle')) * 3600,
        maxTotalBytes: total * 1073741824,
        maxFileBytes: perFile * 1073741824
      }});
      showCreated(form, result, `Destination: ${result.folder}`);
      await refreshLinks();
    } catch (error) {
      toast(error.message, 'error'); submit.disabled = false; submit.textContent = 'Create upload link';
    }
  });
}

function showCreated(form, result, detail = '') {
  const box = form.querySelector('.pd-created-result');
  box.hidden = false;
  box.querySelector('.pd-created-url').textContent = result.url;
  box.querySelector('.pd-created-detail').textContent = detail;
  form.querySelectorAll('label, details').forEach((el) => { el.hidden = true; });
  const submit = form.querySelector('[type="submit"]');
  if (submit) submit.hidden = true;
  const cancel = form.querySelector('[data-cancel]');
  if (cancel) cancel.textContent = 'Done';
  box.querySelector('.pd-copy-created')?.addEventListener('click', async () => {
    await navigator.clipboard.writeText(result.url); toast('Link copied.', 'success');
  });
}

function itemCovered(path) {
  for (const item of state.selected.values()) {
    if (item.type === 'folder' && path.startsWith(`${item.path}/`)) return true;
  }
  return false;
}

function toggleSelected(item, checked) {
  if (checked) {
    for (const [path] of state.selected) {
      if (path.startsWith(`${item.path}/`)) state.selected.delete(path);
    }
    if (!itemCovered(item.path)) state.selected.set(item.path, item);
  } else {
    state.selected.delete(item.path);
  }
  renderSelectedTray();
}

function renderSelectedTray() {
  const host = document.getElementById('pdSelectedItems');
  if (!host) return;
  if (!state.selected.size) {
    host.innerHTML = '<span class="pd-selection-empty">Nothing selected yet</span>';
    return;
  }
  host.innerHTML = Array.from(state.selected.values()).map((item) => `<span class="pd-selected-chip"><span class="material-icons">${item.type === 'folder' ? 'folder' : 'draft'}</span>${esc(item.name)}<button type="button" data-path="${esc(item.path)}" aria-label="Remove ${esc(item.name)}">×</button></span>`).join('');
  host.querySelectorAll('button').forEach((button) => button.addEventListener('click', () => {
    state.selected.delete(button.dataset.path); renderSelectedTray(); loadPicker(state.pickerPath);
  }));
}

async function loadPicker(path = '') {
  const host = document.getElementById('pdPickerList');
  if (!host) return;
  host.innerHTML = '<div class="pd-loading">Loading folder…</div>';
  try {
    const result = await api(`/api/links/browse.php?path=${encodeURIComponent(path)}`);
    state.pickerPath = result.path || '';
    const crumbs = document.getElementById('pdPickerCrumbs');
    if (crumbs) {
      const parts = state.pickerPath ? state.pickerPath.split('/') : [];
      let acc = '';
      crumbs.innerHTML = `<button type="button" data-path="">Drop</button>` + parts.map((part) => {
        acc = acc ? `${acc}/${part}` : part;
        return `<span>/</span><button type="button" data-path="${esc(acc)}">${esc(part)}</button>`;
      }).join('');
      crumbs.querySelectorAll('button').forEach((button) => button.addEventListener('click', () => loadPicker(button.dataset.path || '')));
    }
    host.innerHTML = (result.entries || []).map((item) => {
      const covered = itemCovered(item.path);
      const selected = state.selected.has(item.path);
      return `<div class="pd-picker-row${covered ? ' is-covered' : ''}"><label><input type="checkbox" data-path="${esc(item.path)}" ${selected ? 'checked' : ''} ${covered ? 'disabled' : ''}><span class="material-icons">${item.type === 'folder' ? 'folder' : 'draft'}</span><span><strong>${esc(item.name)}</strong><small>${item.type === 'folder' ? 'Folder' : formatBytes(item.size)}</small></span></label>${item.type === 'folder' ? `<button type="button" data-open="${esc(item.path)}">Open<span class="material-icons">chevron_right</span></button>` : ''}</div>`;
    }).join('') || '<div class="pd-list-empty">This folder is empty.</div>';
    host.querySelectorAll('input[type="checkbox"]').forEach((input) => input.addEventListener('change', () => {
      const item = (result.entries || []).find((entry) => entry.path === input.dataset.path);
      if (item) toggleSelected(item, input.checked);
      loadPicker(state.pickerPath);
    }));
    host.querySelectorAll('[data-open]').forEach((button) => button.addEventListener('click', () => loadPicker(button.dataset.open)));
  } catch (error) {
    host.innerHTML = `<div class="pd-list-empty">${esc(error.message)}</div>`;
  }
}

function openShareCreator(initialItems = []) {
  state.selected = new Map();
  initialItems.forEach((item) => { if (item?.path) state.selected.set(item.path, item); });
  const { overlay, close } = modal({
    title: 'Share files',
    subtitle: 'Choose any combination of files and folders. Recipients get one simple four-letter link.',
    wide: true,
    body: `<form id="pdShareForm" class="pd-form pd-share-form">
      <div class="pd-share-fields"><label><span>Share name</span><input name="title" maxlength="120" required placeholder="e.g. Final project delivery" autofocus></label>
      <label><span>Recipient note <small>optional</small></span><textarea name="note" maxlength="1000" rows="2" placeholder="Add a short message"></textarea></label>
      <div class="pd-field-grid"><label><span>Hard expiry</span><div class="pd-unit-field"><input name="expiry" type="number" min="1" max="720" value="48" required><b>hours</b></div></label>
      <label><span>Optional PIN</span><input name="pin" inputmode="numeric" pattern="[0-9]{4,12}" maxlength="12" placeholder="4–12 digits"></label></div></div>
      <div class="pd-picker"><div class="pd-picker-head"><div id="pdPickerCrumbs" class="pd-picker-crumbs"></div><small>Select across folders; your choices stay here.</small></div><div id="pdPickerList" class="pd-picker-list"></div><div id="pdSelectedItems" class="pd-selected-items"></div></div>
      ${resultMarkup()}
      <footer class="pd-form-actions"><button type="button" class="pd-button-ghost" data-cancel>Cancel</button><button type="submit" class="pd-button-primary">Create share link</button></footer>
    </form>`
  });
  const form = overlay.querySelector('#pdShareForm');
  overlay.querySelector('[data-cancel]')?.addEventListener('click', close);
  renderSelectedTray(); loadPicker('');
  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!state.selected.size) { toast('Select at least one file or folder.', 'warning'); return; }
    const submit = form.querySelector('[type="submit"]');
    submit.disabled = true; submit.textContent = 'Creating…';
    const data = new FormData(form);
    try {
      const result = await api('/api/links/createShare.php', { method: 'POST', body: {
        title: data.get('title'), note: data.get('note'), pin: data.get('pin'),
        expiresInSeconds: Number(data.get('expiry')) * 3600,
        items: Array.from(state.selected.values()).map(({ path, type }) => ({ path, type }))
      }});
      form.querySelector('.pd-share-fields').hidden = true;
      form.querySelector('.pd-picker').hidden = true;
      showCreated(form, result, `${state.selected.size} selected item${state.selected.size === 1 ? '' : 's'}`);
      await refreshLinks();
    } catch (error) {
      toast(error.message, 'error'); submit.disabled = false; submit.textContent = 'Create share link';
    }
  });
}

function openEdit(link) {
  const receive = link.type === 'upload';
  const remainingHours = Math.max(1, Math.ceil((Number(link.expiresAt) - Date.now() / 1000) / 3600));
  const { overlay, close } = modal({
    title: `Edit ${receive ? 'upload request' : 'share'}`,
    subtitle: 'Changes apply immediately. Closed links cannot be reopened.',
    body: `<form id="pdEditForm" class="pd-form"><label><span>Name</span><input name="title" maxlength="120" required value="${esc(link.title)}"></label>
      <label><span>Expires from now</span><div class="pd-unit-field"><input name="expiry" type="number" min="1" max="720" value="${remainingHours}" required><b>hours</b></div></label>
      ${receive ? `<label><span>Instructions <small>optional</small></span><textarea name="instructions" maxlength="1000" rows="3">${esc(link.instructions || '')}</textarea></label><div class="pd-field-grid"><label><span>Inactivity timeout</span><div class="pd-unit-field"><input name="idle" type="number" min="1" max="720" value="${Math.max(1, Math.round(Number(link.idleTimeoutSeconds || 172800) / 3600))}"><b>hours</b></div></label><label><span>Total capacity</span><div class="pd-unit-field"><input name="total" type="number" min="1" max="1953" value="${Math.max(1, Math.round(Number(link.maxTotalBytes || 0) / 1073741824))}"><b>GB</b></div></label><label><span>Maximum per file</span><div class="pd-unit-field"><input name="perFile" type="number" min="1" max="100" value="${Math.max(1, Math.round(Number(link.maxFileBytes || 0) / 1073741824))}"><b>GB</b></div></label></div>` : `<label><span>Recipient note <small>optional</small></span><textarea name="note" maxlength="1000" rows="3">${esc(link.note || '')}</textarea></label><label><span>PIN</span><select name="pinAction"><option value="keep">Keep current PIN setting</option><option value="replace">Set a new PIN</option><option value="remove">Remove PIN</option></select></label><label class="pd-new-pin" hidden><span>New PIN</span><input name="pin" inputmode="numeric" pattern="[0-9]{4,12}" maxlength="12" placeholder="4–12 digits"></label>`}
      <footer class="pd-form-actions"><button type="button" class="pd-button-ghost" data-cancel>Cancel</button><button type="submit" class="pd-button-primary">Save changes</button></footer></form>`
  });
  const form = overlay.querySelector('#pdEditForm');
  form?.querySelector('[name="pinAction"]')?.addEventListener('change', (event) => {
    const field = form.querySelector('.pd-new-pin');
    if (field) field.hidden = event.target.value !== 'replace';
  });
  overlay.querySelector('[data-cancel]')?.addEventListener('click', close);
  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const data = new FormData(form);
    const payload = { id: link.id, type: link.type, title: data.get('title'), expiresInSeconds: Number(data.get('expiry')) * 3600 };
    if (receive) {
      payload.instructions = data.get('instructions');
      payload.idleTimeoutSeconds = Number(data.get('idle')) * 3600;
      payload.maxTotalBytes = Number(data.get('total')) * 1073741824;
      payload.maxFileBytes = Number(data.get('perFile')) * 1073741824;
    } else {
      payload.note = data.get('note');
      const pinAction = data.get('pinAction');
      if (pinAction === 'remove') payload.pin = '';
      if (pinAction === 'replace') payload.pin = data.get('pin');
    }
    try { await api('/api/links/update.php', { method: 'POST', body: payload }); close(); toast('Link updated.', 'success'); await refreshLinks(); }
    catch (error) { toast(error.message, 'error'); }
  });
}

async function revokeLink(link) {
  if (!window.confirm(`Close “${link.title}” now? Files on the NAS will not be deleted.`)) return;
  try {
    await api('/api/links/revoke.php', { method: 'POST', body: { id: link.id, type: link.type } });
    toast('Link closed. NAS files were left untouched.', 'success'); await refreshLinks();
  } catch (error) { toast(error.message, 'error'); }
}

export function initDropDashboard() {
  if (state.ready || !isAdmin()) return;
  state.ready = true;
  installShell();
  showDashboard();
  window.addEventListener('focus', refreshLinks);
  state.refreshTimer = window.setInterval(() => { if (!document.hidden && document.body.classList.contains('pd-dashboard-mode')) refreshLinks(); }, 30000);
}
