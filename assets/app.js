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
