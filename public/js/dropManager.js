// Phaise Drop admin launcher. The API remains the source of truth for authorization.
document.addEventListener('DOMContentLoaded', function () {
  const openBtn = document.getElementById('createDropBtn');
  const modal = document.getElementById('createDropModal');
  const closeBtn = document.getElementById('cancelCreateDrop');
  const submitBtn = document.getElementById('submitCreateDrop');
  const copyBtn = document.getElementById('copyDropLinkBtn');
  const result = document.getElementById('dropCreateResult');
  const linkInput = document.getElementById('dropCreatedLink');
  const folderHint = document.getElementById('dropCreatedFolder');
  if (!openBtn || !modal || !closeBtn || !submitBtn || !result || !linkInput) return;

  function isAdmin() {
    const value = String(localStorage.getItem('isAdmin') || '').toLowerCase();
    return value === '1' || value === 'true';
  }

  function syncVisibility() {
    openBtn.style.display = isAdmin() ? '' : 'none';
  }
  syncVisibility();
  window.setInterval(syncVisibility, 1500);

  function notify(message, tone) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, tone || 'info');
    }
  }

  function csrfToken() {
    return String(document.querySelector('meta[name="csrf-token"]')?.content || window.csrfToken || '');
  }

  function resetResult() {
    result.hidden = true;
    linkInput.value = '';
    if (folderHint) folderHint.textContent = '';
    submitBtn.hidden = false;
  }

  openBtn.addEventListener('click', function () {
    resetResult();
    modal.style.display = 'block';
    window.setTimeout(() => document.getElementById('dropTitle')?.focus(), 0);
  });

  closeBtn.addEventListener('click', function () {
    modal.style.display = 'none';
  });

  modal.addEventListener('click', function (event) {
    if (event.target === modal) modal.style.display = 'none';
  });

  submitBtn.addEventListener('click', async function () {
    if (submitBtn.dataset.busy === '1') return;
    const title = String(document.getElementById('dropTitle')?.value || '').trim();
    if (!title) {
      notify('Enter a name for this drop.', 'warning');
      document.getElementById('dropTitle')?.focus();
      return;
    }
    const csrf = csrfToken();
    if (!csrf) {
      notify('Your session token is missing. Reload the page and try again.', 'error');
      return;
    }

    const asInt = (id, fallback) => {
      const value = Number.parseInt(String(document.getElementById(id)?.value || ''), 10);
      return Number.isFinite(value) ? value : fallback;
    };
    const payload = {
      title,
      instructions: String(document.getElementById('dropInstructions')?.value || '').trim(),
      closeMode: String(document.getElementById('dropCloseMode')?.value || 'single'),
      expiresDays: asInt('dropExpiresDays', 7),
      idleHours: asInt('dropIdleHours', 48),
      maxFileSizeMb: asInt('dropMaxFileGb', 25) * 1024,
      maxTotalMb: asInt('dropMaxTotalGb', 100) * 1024
    };

    submitBtn.dataset.busy = '1';
    submitBtn.disabled = true;
    const priorText = submitBtn.textContent;
    submitBtn.textContent = 'Creating…';
    try {
      const response = await fetch('/api/folder/createDrop.php', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf
        },
        body: JSON.stringify(payload)
      });
      const body = await response.json().catch(() => ({}));
      if (!response.ok || !body.link || !/^[a-z]{4}$/.test(String(body.shortCode || ''))) {
        throw new Error(String(body.error || 'Could not create this drop.'));
      }
      linkInput.value = String(body.link);
      if (folderHint) folderHint.textContent = 'NAS folder: ' + String(body.folder || '');
      result.hidden = false;
      submitBtn.hidden = true;
      notify('Secure drop created.', 'success');
      linkInput.select();
    } catch (error) {
      notify(error && error.message ? error.message : 'Could not create this drop.', 'error');
    } finally {
      delete submitBtn.dataset.busy;
      submitBtn.disabled = false;
      submitBtn.textContent = priorText;
    }
  });

  if (copyBtn) {
    copyBtn.addEventListener('click', async function () {
      if (!linkInput.value) return;
      try {
        await navigator.clipboard.writeText(linkInput.value);
        copyBtn.textContent = 'Copied';
        window.setTimeout(() => { copyBtn.textContent = 'Copy'; }, 1500);
      } catch (error) {
        linkInput.select();
        document.execCommand('copy');
      }
    });
  }

});
