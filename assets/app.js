console.log('[app.js] loaded ✓');
document.addEventListener('click', async (event) => {
  const copyButton = event.target.closest('[data-copy]');
  if (copyButton) {
    const target = document.querySelector(copyButton.dataset.copy);
    if (target) {
      try {
        await navigator.clipboard.writeText(target.textContent.trim());
        const old = copyButton.textContent;
        copyButton.textContent = 'Copied';
        setTimeout(() => copyButton.textContent = old, 1400);
      } catch (_) {}
    }
  }
});

document.querySelectorAll('form[data-confirm]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (!window.confirm(form.dataset.confirm || 'Continue?')) event.preventDefault();
  });
});

// Dropzone: just log clicks, do NOT preventDefault (kills native label→input trigger)
document.querySelectorAll('.dropzone').forEach((zone) => {
  const inp = zone.querySelector('input[type="file"]');
  if (!inp) return;
  zone.addEventListener('click', () => {
    console.log('[DROPZONE] label clicked, input name:', inp.name);
  });
});

(function welcomeScreen() {
  const welcome = document.querySelector('.welcome-screen');
  if (!welcome) return;

  const minVisibleMs = 900;
  const hardTimeoutMs = 6000;
  const start = Date.now();
  let revealed = false;

  function reveal() {
    if (revealed) return;
    revealed = true;
    const elapsed = Date.now() - start;
    const wait = Math.max(0, minVisibleMs - elapsed);
    setTimeout(() => welcome.classList.add('hide'), wait);
  }

  const bg = document.querySelector('.background');
  if (!bg || bg.classList.contains('fallback')) {
    reveal();
  } else if (bg.tagName === 'IMG') {
    if (bg.complete && bg.naturalWidth > 0) {
      reveal();
    } else {
      bg.addEventListener('load', reveal, { once: true });
      bg.addEventListener('error', reveal, { once: true });
    }
  } else if (bg.tagName === 'VIDEO') {
    if (bg.readyState >= 3) {
      reveal();
    } else {
      bg.addEventListener('loadeddata', reveal, { once: true });
      bg.addEventListener('error', reveal, { once: true });
    }
  } else {
    reveal();
  }

  // Safety net: never let the welcome screen block the gallery indefinitely.
  setTimeout(reveal, hardTimeoutMs);
})();

(function debugBackground() {
  const bg = document.querySelector('.background');
  if (bg) {
    const cs = getComputedStyle(bg);
    console.group('%c[BG DEBUG]', 'color: #78d5ff; font-weight: bold;');
    console.log('element:', bg);
    console.log('src:', bg.src || bg.currentSrc || '(div/video)');
    console.log('naturalSize:', bg.naturalWidth + 'x' + bg.naturalHeight);
    console.log('rendered:', bg.getBoundingClientRect());
    console.log('position:', cs.position);
    console.log('inset:', cs.top, cs.right, cs.bottom, cs.left);
    console.log('width/height:', cs.width, cs.height);
    console.log('object-fit:', cs.objectFit);
    console.log('z-index:', cs.zIndex);
    console.groupEnd();
  } else {
    console.warn('[BG DEBUG] .background element NOT found in DOM');
  }
})();

document.querySelectorAll('input[type="file"]').forEach((input) => {
  input.addEventListener('change', () => {
    const files = Array.from(input.files || []);
    console.group('%c[UPLOAD DEBUG]', 'color: #4dd59b; font-weight: bold;');
    console.log('input name:', input.name);
    console.log('file count:', files.length);
    files.forEach((f, i) => {
      console.log(`  [${i}] ${f.name}  ${(f.size / 1024 / 1024).toFixed(2)} MB  ${f.type}`);
    });
    console.groupEnd();
  });
});

(function downloadStatusPopup() {
  if (!document.body.classList.contains('gallery-page')) return;

  const streamSupported =
    typeof fetch === 'function' &&
    typeof ReadableStream !== 'undefined' &&
    typeof Blob !== 'undefined' &&
    typeof URL !== 'undefined' &&
    typeof URL.createObjectURL === 'function';

  const toast = document.createElement('div');
  toast.className = 'download-toast';
  toast.setAttribute('role', 'status');
  toast.setAttribute('aria-live', 'polite');
  toast.innerHTML = [
    '<div class="download-toast-title"></div>',
    '<div class="download-toast-sub"></div>',
    '<div class="download-progress" aria-hidden="true"><span class="download-progress-fill"></span></div>'
  ].join('');
  document.body.appendChild(toast);

  const titleEl = toast.querySelector('.download-toast-title');
  const subEl = toast.querySelector('.download-toast-sub');
  const progressEl = toast.querySelector('.download-progress');
  const progressFillEl = toast.querySelector('.download-progress-fill');

  let hideTimer = null;
  let downloadInProgress = false;

  function formatBytes(bytes) {
    const value = Number(bytes);
    if (!Number.isFinite(value) || value <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let idx = 0;
    let out = value;
    while (out >= 1024 && idx < units.length - 1) {
      out /= 1024;
      idx += 1;
    }
    const precision = idx === 0 ? 0 : out >= 100 ? 0 : out >= 10 ? 1 : 2;
    return out.toFixed(precision) + ' ' + units[idx];
  }

  function formatEta(seconds) {
    if (!Number.isFinite(seconds) || seconds <= 0) return 'few seconds';
    const value = Math.max(1, Math.round(seconds));
    if (value < 60) return value + ' s';
    const mins = Math.floor(value / 60);
    const secs = value % 60;
    return mins + ' min ' + String(secs).padStart(2, '0') + ' s';
  }

  function parseFileName(response, fallbackHref) {
    const raw = response.headers.get('content-disposition') || '';
    const utf = raw.match(/filename\*=UTF-8''([^;]+)/i);
    if (utf && utf[1]) {
      try {
        return decodeURIComponent(utf[1]);
      } catch (_) {}
    }
    const ascii = raw.match(/filename="?([^";]+)"?/i);
    if (ascii && ascii[1]) return ascii[1];
    try {
      const url = new URL(fallbackHref, window.location.origin);
      const parts = url.pathname.split('/').filter(Boolean);
      return parts[parts.length - 1] || 'download';
    } catch (_) {
      return 'download';
    }
  }

  function setProgress(percent, indeterminate) {
    if (indeterminate) {
      progressEl.classList.add('indeterminate');
      progressFillEl.style.width = '35%';
      return;
    }
    progressEl.classList.remove('indeterminate');
    const p = Math.max(0, Math.min(100, percent));
    progressFillEl.style.width = p + '%';
  }

  function showToast(title, subtitle, sticky) {
    titleEl.textContent = title;
    subEl.textContent = subtitle;
    toast.classList.add('visible');
    if (hideTimer) clearTimeout(hideTimer);
    if (!sticky) {
      hideTimer = setTimeout(() => toast.classList.remove('visible'), 5000);
    }
  }

  async function streamDownload(link) {
    if (downloadInProgress) {
      showToast('Download already running', 'Please wait for the current transfer to finish.', false);
      return;
    }

    const href = link.href;
    const kind = link.dataset.downloadKind || 'download';
    const hintedSize = Number(link.dataset.downloadSize || '0');

    const allowStream = (link.dataset.downloadStream || '1') !== '0' && kind !== 'zip';

    if (!streamSupported || !allowStream) {
      if (!allowStream) {
        setProgress(0, true);
        showToast('Starting browser ZIP download', 'Large ZIP files are streamed by the browser to avoid high memory usage.', false);
      } else {
        showToast('Starting browser download', 'Live progress is not supported in this browser.', false);
      }
      window.location.href = href;
      return;
    }

    downloadInProgress = true;
    setProgress(0, true);
    showToast('Preparing download...', 'Connecting to server...', true);

    try {
      const response = await fetch(href, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store'
      });

      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }

      if (!response.body || typeof response.body.getReader !== 'function') {
        throw new Error('no_stream_reader');
      }

      const total = Number(response.headers.get('content-length') || '0') || (Number.isFinite(hintedSize) ? hintedSize : 0);
      const fileName = parseFileName(response, href);
      const reader = response.body.getReader();
      const chunks = [];
      let received = 0;
      const startedAt = performance.now();

      setProgress(2, !total);
      showToast('Downloading...', total > 0 ? '0% · 0 B / ' + formatBytes(total) : 'Receiving data stream...', true);

      while (true) {
        const part = await reader.read();
        if (part.done) break;
        if (!part.value) continue;
        chunks.push(part.value);
        received += part.value.length;

        const elapsedSec = Math.max(0.2, (performance.now() - startedAt) / 1000);
        const speed = received / elapsedSec;

        if (total > 0) {
          const pct = Math.min(100, (received / total) * 100);
          const eta = speed > 0 ? (total - received) / speed : 0;
          setProgress(pct, false);
          showToast(
            'Downloading...',
            Math.round(pct) + '% · ' + formatBytes(received) + ' / ' + formatBytes(total) + ' · ' + formatBytes(speed) + '/s · ETA ' + formatEta(eta),
            true
          );
        } else {
          setProgress(0, true);
          showToast('Downloading...', formatBytes(received) + ' received · ' + formatBytes(speed) + '/s', true);
        }
      }

      const blob = new Blob(chunks, { type: response.headers.get('content-type') || 'application/octet-stream' });
      const objectUrl = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = objectUrl;
      a.download = fileName;
      a.rel = 'noopener';
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(objectUrl), 60_000);

      setProgress(100, false);
      showToast('Photo downloaded', 'Saved as ' + fileName, false);
    } catch (error) {
      console.error('[download-stream]', error);
      setProgress(0, true);
      showToast('Could not stream this download', 'Switching to browser download mode...', false);
      window.location.href = href;
    } finally {
      downloadInProgress = false;
    }
  }

  document.addEventListener('click', (event) => {
    const link = event.target.closest('a[data-download-kind]');
    if (!link) return;
    if (event.defaultPrevented) return;
    if (event.button !== 0) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (link.target && link.target !== '_self') {
      showToast('Download is opening in a new tab', 'Live progress is not available in this mode.', false);
      return;
    }

    event.preventDefault();
    streamDownload(link).catch(() => {
      window.location.href = link.href;
    });
  });
})();
