(() => {
  const form = document.querySelector('form[data-upload-queue]');
  if (!form || !window.XMLHttpRequest || !window.crypto?.getRandomValues) return;
  const input = form.querySelector('input[type=file]');
  const zone = form.querySelector('.dropzone');
  const submit = form.querySelector('button');
  const csrf = form.querySelector('[name=csrf]').value;
  const scope = new URL(form.action).pathname;
  input.required = false;
  const panel = document.createElement('section');
  panel.className = 'upload-queue';
  panel.innerHTML = '<p class="upload-summary" role="status" aria-live="polite">Select or drop photos to prepare the queue.</p><p class="muted upload-persistence">Files are kept in this browser until uploaded, so you can continue after a refresh.</p><div class="upload-actions"><button type="button" data-pause>Pause queue</button><button type="button" data-retry>Retry failed</button><button type="button" data-clear>Clear finished</button><button type="button" data-refresh>Refresh gallery</button></div><ol class="upload-items"></ol>';
  form.append(panel);
  const summary = panel.querySelector('.upload-summary');
  const persistence = panel.querySelector('.upload-persistence');
  const list = panel.querySelector('.upload-items');
  const pause = panel.querySelector('[data-pause]');
  let db = null;
  let items = [];
  let active = 0;
  let running = false;
  let adding = false;

  function warnStorage() {
    persistence.textContent = 'Browser storage is unavailable or full. Keep this page open; after a refresh, select the remaining files again.';
  }
  const ready = new Promise(resolve => {
    try {
      const req = indexedDB.open('gallery-upload-queue-v1', 2);
      req.onupgradeneeded = () => {
        const store = req.result.objectStoreNames.contains('files') ? req.transaction.objectStore('files') : req.result.createObjectStore('files', { keyPath: 'key' });
        if (!store.indexNames.contains('scope')) store.createIndex('scope', 'scope');
      };
      req.onerror = req.onblocked = () => { warnStorage(); resolve(); };
      req.onsuccess = () => { db = req.result; resolve(); };
    } catch (_) { warnStorage(); resolve(); }
  }).then(async () => {
    if (!db) return;
    const records = await new Promise(resolve => {
      const request = db.transaction('files').objectStore('files').index('scope').getAll(scope);
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => { warnStorage(); resolve([]); };
    });
    for (const record of records.sort((a, b) => (a.created || 0) - (b.created || 0))) {
      if (!['done', 'error'].includes(record.state)) record.state = record.file ? 'queued' : 'missing';
      addRow(record);
      items.push(record);
    }
    if (items.length) { summary.textContent = 'Saved queue restored. Click Upload photos to continue.'; update(); }
  });

  async function persist(item) {
    if (!db) return;
    await new Promise(resolve => {
      try {
        const tx = db.transaction('files', 'readwrite');
        tx.objectStore('files').put({ key: item.key, scope, id: item.id, name: item.name, size: item.size,
          modified: item.modified, created: item.created, state: item.state, error: item.error, file: item.state === 'done' ? null : item.file });
        tx.oncomplete = resolve;
        tx.onerror = tx.onabort = () => { warnStorage(); resolve(); };
      } catch (_) { warnStorage(); resolve(); }
    });
  }
  function update() {
    const done = items.filter(item => item.state === 'done').length;
    const failed = items.filter(item => item.state === 'error').length;
    summary.textContent = `${done} / ${items.length} uploaded · ${active} transferring` + (failed ? ` · ${failed} failed` : '') + (!running && done < items.length ? ' · paused — click Upload photos to continue' : '');
    pause.disabled = !running;
    submit.disabled = adding;
    for (const item of items) {
      item.status.textContent = item.state === 'uploading' ? `${item.percent || 0}%` : (item.error || item.state);
      item.progress.value = item.state === 'done' ? 100 : item.percent || 0;
      item.retry.hidden = item.state !== 'error';
      item.remove.disabled = item.state === 'uploading';
    }
  }
  function addRow(item) {
    const li = document.createElement('li');
    const name = document.createElement('span'); name.textContent = item.name;
    const status = document.createElement('span');
    const progress = document.createElement('progress'); progress.max = 100; progress.value = 0;
    progress.setAttribute('aria-label', 'Upload ' + item.name);
    const retry = document.createElement('button'); retry.type = 'button'; retry.textContent = 'Retry'; retry.hidden = true;
    retry.addEventListener('click', () => { item.state = 'queued'; item.error = ''; running = true; pump(); });
    const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = 'Remove from queue';
    remove.addEventListener('click', () => {
      if (item.state === 'uploading') return;
      if (db) db.transaction('files', 'readwrite').objectStore('files').delete(item.key);
      items = items.filter(candidate => candidate !== item); li.remove(); update();
    });
    li.append(name, status, progress, retry, remove); list.append(li);
    Object.assign(item, { row: li, status, progress, retry, remove });
  }
  async function enqueue(files) {
    await ready;
    adding = true; update();
    for (const file of files) {
      // Reselecting the same source after a refresh never uploads it twice.
      const old = items.find(item => item.name === file.name && item.size === file.size && item.modified === file.lastModified);
      if (old) {
        if (old.state !== 'done' && old.state !== 'uploading') { old.file = file; old.state = 'queued'; old.error = ''; await persist(old); }
        continue;
      }
      const id = Array.from(crypto.getRandomValues(new Uint8Array(16)), n => n.toString(16).padStart(2, '0')).join('');
      const item = { key: scope + ':' + id, scope, id, name: file.name, size: file.size, modified: file.lastModified, created: Date.now(), file, state: 'queued' };
      if (file.size === 0 || file.size > Number(form.dataset.maxBytes)) { item.state = 'error'; item.error = 'Empty file or exceeds the file size limit'; }
      addRow(item); items.push(item); await persist(item);
    }
    adding = false; input.value = ''; update(); if (running) pump();
  }
  function transfer(item) {
    active++; item.state = 'uploading'; item.error = ''; item.percent = 0; update();
    const xhr = new XMLHttpRequest();
    xhr.open('POST', form.action);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.timeout = 15 * 60 * 1000;
    const body = new FormData(); body.append('csrf', csrf); body.append('upload_id', item.id); body.append('photos[]', item.file, item.name);
    xhr.upload.onprogress = event => {
      if (event.lengthComputable) item.percent = Math.min(99, Math.round(event.loaded / event.total * 100));
      update();
    };
    async function finish(error) {
      item.state = error ? 'error' : 'done'; item.error = error || ''; item.percent = error ? 0 : 100;
      if (!error) item.file = null;
      await persist(item);
      active--; update(); pump();
    }
    xhr.onload = () => {
      let response;
      try { response = JSON.parse(xhr.responseText); } catch (_) {}
      const ok = xhr.status === 200 && response?.ok === true && response.upload_id === item.id;
      finish(ok ? null : response?.error || `Upload failed (${xhr.status}). Check your login and retry.`);
    };
    xhr.onerror = () => finish('Connection lost — retry this file');
    xhr.ontimeout = () => finish('Transfer timed out — retry this file');
    xhr.send(body);
  }
  function pump() {
    if (!running || adding) return;
    for (const item of items) {
      if (active >= 3) break;
      if (item.state === 'queued' && item.file) transfer(item);
    }
    if (active === 0 && !items.some(item => item.state === 'queued')) running = false;
    update();
  }
  input.addEventListener('change', () => enqueue(Array.from(input.files || [])));
  for (const event of ['dragenter', 'dragover']) zone.addEventListener(event, e => { e.preventDefault(); zone.classList.add('dragging'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('dragging'));
  zone.addEventListener('drop', e => { e.preventDefault(); zone.classList.remove('dragging'); enqueue(Array.from(e.dataTransfer.files)); });
  form.addEventListener('submit', async e => { e.preventDefault(); await ready; running = true; pump(); });
  pause.addEventListener('click', () => { running = false; update(); });
  panel.querySelector('[data-retry]').addEventListener('click', () => {
    for (const item of items) if (item.state === 'error' && item.file) { item.state = 'queued'; item.error = ''; }
    running = true; pump();
  });
  panel.querySelector('[data-clear]').addEventListener('click', () => {
    for (const item of items.filter(item => item.state === 'done')) {
      item.row.remove();
      if (db) db.transaction('files', 'readwrite').objectStore('files').delete(item.key);
    }
    items = items.filter(item => item.state !== 'done'); update();
  });
  panel.querySelector('[data-refresh]').addEventListener('click', () => window.location.reload());
  window.addEventListener('beforeunload', e => { if (active || adding) { e.preventDefault(); e.returnValue = ''; } });
})();
