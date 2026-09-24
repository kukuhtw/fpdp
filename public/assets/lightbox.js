(() => {
  const style = document.createElement('style');
  style.textContent = `
    .post-content img, .post-media img { cursor: zoom-in; }
    .lightbox-overlay {
      position: fixed; inset: 0; background: rgba(0, 0, 0, 0.85);
      display: flex; align-items: center; justify-content: center;
      z-index: 9999; padding: 32px; cursor: zoom-out;
    }
    .lightbox-overlay img {
      max-width: 100%; max-height: 100%; object-fit: contain;
      box-shadow: 0 8px 40px rgba(0, 0, 0, 0.5); border-radius: 4px; cursor: default;
    }
    .lightbox-close {
      position: absolute; top: 16px; right: 20px; width: 40px; height: 40px;
      border-radius: 50%; border: none; background: rgba(255, 255, 255, 0.15);
      color: #fff; font-size: 24px; line-height: 1; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
    }
    .lightbox-close:hover { background: rgba(255, 255, 255, 0.3); }
  `;
  document.head.append(style);

  let overlay = null;

  const onKeydown = (event) => {
    if (event.key === 'Escape') closeLightbox();
  };

  function closeLightbox() {
    if (!overlay) return;
    overlay.remove();
    overlay = null;
    document.removeEventListener('keydown', onKeydown);
  }

  function openLightbox(src, alt) {
    overlay = document.createElement('div');
    overlay.className = 'lightbox-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.addEventListener('click', closeLightbox);

    const img = document.createElement('img');
    img.src = src;
    img.alt = alt || '';
    img.addEventListener('click', (event) => event.stopPropagation());

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'lightbox-close';
    closeButton.setAttribute('aria-label', 'Tutup');
    closeButton.textContent = '×';
    closeButton.addEventListener('click', closeLightbox);

    overlay.append(img, closeButton);
    document.body.append(overlay);
    document.addEventListener('keydown', onKeydown);
  }

  document.addEventListener('click', (event) => {
    const img = event.target.closest('.post-content img, .post-media img');
    if (!img) return;
    event.preventDefault();
    openLightbox(img.currentSrc || img.src, img.alt);
  });
})();
