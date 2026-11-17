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


// Keep large files in the browser download manager (native progress and resume).
if (document.body.classList.contains('gallery-page')) {
  const toast = document.createElement('div');
  toast.className = 'download-toast';
  toast.setAttribute('role', 'status');
  toast.setAttribute('aria-live', 'polite');
  document.body.appendChild(toast);
  let timer;
  document.addEventListener('click', event => {
    const link = event.target.closest('a[data-download-kind]');
    if (!link || event.defaultPrevented || event.button !== 0) return;
    toast.textContent = link.dataset.downloadKind === 'zip'
      ? 'Preparing ZIP. Follow the transfer in your browser downloads.'
      : 'Starting photo download. Follow the transfer in your browser downloads.';
    toast.classList.add('visible');
    clearTimeout(timer);
    timer = setTimeout(() => toast.classList.remove('visible'), 6000);
  });
}
