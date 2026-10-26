/* Filora — public share page enhancements (preview lightbox, filter, chunked uploads). */
(() => {
  const dataEl = document.getElementById('fs-data');
  if (!dataEl) return;
  const D = JSON.parse(dataEl.textContent || '{}');
  const T = D.i18n || {};
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  const store = {
    get(key) { try { return localStorage.getItem('filora:' + key); } catch (e) { return null; } },
    set(key, value) { try { localStorage.setItem('filora:' + key, value); } catch (e) { /* private mode */ } },
  };

  const link = (params) => {
    const qs = new URLSearchParams(params).toString();
    return D.base + (D.base.indexOf('?') === -1 ? '?' : '&') + qs;
  };

  const h = (tag, attrs, ...children) => {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (v === false || v == null) continue;
      if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
      else if (k === 'html') el.innerHTML = v;
      else el.setAttribute(k, v === true ? '' : v);
    }
    for (const c of children.flat()) if (c != null && c !== false) el.append(c);
    return el;
  };
  const icon = (id) => {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'fs-ico');
    svg.setAttribute('aria-hidden', 'true');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', '#i-' + id);
    svg.append(use);
    return svg;
  };
  const formatSize = (bytes) => {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
    return (bytes / Math.pow(1024, i)).toFixed(i ? 1 : 0).replace(/\.0$/, '') + ' ' + units[i];
  };

  /* View toggle & search ------------------------------------------------ */
  const content = $('#fs-items');
  if (content) {
    const setView = (view) => {
      content.dataset.view = view;
      $$('.fs-seg button').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.view === view)));
      store.set('view', view);
    };
    setView(store.get('view') === 'list' ? 'list' : 'grid');
    $$('.fs-seg button').forEach((b) => b.addEventListener('click', () => setView(b.dataset.view)));

    const filter = $('#fs-filter');
    const noRes = $('#fs-nores');
    if (filter) {
      const fold = (str) => str.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
      filter.addEventListener('input', () => {
        const q = fold(filter.value.trim());
        let shown = 0;
        $$('.fs-item', content).forEach((li) => {
          const ok = !q || fold(li.dataset.name).indexOf(q) !== -1;
          li.hidden = !ok;
          if (ok) shown++;
        });
        $$('.fs-section', content).forEach((sec) => { sec.hidden = !$$('.fs-item', sec).some((li) => !li.hidden); });
        if (noRes) noRes.hidden = shown !== 0;
      });
      filter.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { filter.value = ''; filter.dispatchEvent(new Event('input')); }
      });
    }
  }

  /* Viewer ------------------------------------------------------------------ */
  const files = D.files || [];
  let lb = null;
  let current = -1;
  let lastFocus = null;

  const docArt = (f) => {
    const inner = f.cat === 'video' || f.cat === 'audio' || f.cat === 'image'
      ? h('span', { class: 'fs-doc-play' }, icon(f.cat === 'video' ? 'play' : f.cat === 'audio' ? 'music' : 'image'))
      : h('span', { class: 'fs-doc-lines' });
    return h('span', { class: 'fs-doc fs-doc--xl fs-doc--' + f.cat }, inner, f.ext ? h('b', { class: 'fs-doc-badge' }, f.ext.slice(0, 4).toUpperCase()) : null);
  };
  const downloadBtn = (f, label) => (D.canDownload
    ? h('a', { class: 'fs-btn fs-btn--primary', href: link({ a: 'dl', p: f.rel }), rel: 'nofollow', title: T.download }, icon('download'), h('span', {}, label || T.download))
    : null);

  const closePreview = () => {
    if (!lb) return;
    lb.remove();
    lb = null;
    current = -1;
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onKey);
    if (lastFocus && lastFocus.focus) lastFocus.focus({ preventScroll: true });
  };
  const onKey = (e) => {
    if (e.key === 'Escape') closePreview();
    else if (e.key === 'ArrowRight') step(document.dir === 'rtl' ? -1 : 1);
    else if (e.key === 'ArrowLeft') step(document.dir === 'rtl' ? 1 : -1);
  };
  const step = (delta) => {
    if (files.length < 2 || current < 0) return;
    openPreview((current + delta + files.length) % files.length);
  };

  const INLINE_IMAGES = ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'bmp'];
  const stageFor = (f) => {
    const raw = link({ a: 'raw', p: f.rel });
    if (D.canPreview) {
      if (f.cat === 'image' && INLINE_IMAGES.indexOf(f.ext) !== -1) return h('img', { src: raw, alt: f.label || f.name });
      if (f.cat === 'video') return h('video', { src: raw, controls: true, autoplay: true, playsinline: true });
      if (f.cat === 'audio') return h('div', { class: 'fs-lb-empty' }, docArt(f), h('audio', { src: raw, controls: true, autoplay: true }));
      if (f.cat === 'pdf') return h('iframe', { src: raw, title: f.name });
      if (f.cat === 'code' && f.size < 2 * 1024 * 1024) {
        const plain = ['txt', 'md', 'markdown', 'log', 'text', ''].indexOf(f.ext) !== -1;
        const pre = h('pre', { class: 'fs-lb-paper' + (plain ? '' : ' is-code') }, T.loading || '…');
        fetch(raw, { credentials: 'same-origin' })
          .then((r) => (r.ok ? r.text() : Promise.reject()))
          .then((txt) => { pre.textContent = txt; })
          .catch(() => { pre.textContent = T.noPreview; });
        return pre;
      }
    }
    return h('div', { class: 'fs-lb-empty' }, docArt(f), h('p', {}, T.noPreview), downloadBtn(f));
  };

  const openPreview = (index) => {
    const f = files[index];
    if (!f) return;
    if (!lb) lastFocus = document.activeElement;
    if (lb) lb.remove();
    current = index;
    const sub = [f.type, f.sizeH].filter(Boolean).join(' · ') + (files.length > 1 ? ' · ' + (T.position || '%1$s / %2$s').replace('%1$s', index + 1).replace('%2$s', files.length) : '');
    const stage = h('div', { class: 'fs-lb-stage', onclick: (e) => { if (e.target === stage) closePreview(); } }, stageFor(f));
    if (files.length > 1) {
      stage.append(
        h('button', { class: 'fs-lb-nav fs-lb-nav--prev', type: 'button', 'aria-label': T.previous, title: T.previous, onclick: () => step(-1) }, icon('chevron')),
        h('button', { class: 'fs-lb-nav fs-lb-nav--next', type: 'button', 'aria-label': T.next, title: T.next, onclick: () => step(1) }, icon('chevron'))
      );
    }
    lb = h('div', { class: 'fs-lb', role: 'dialog', 'aria-modal': 'true', 'aria-label': f.label || f.name },
      h('div', { class: 'fs-lb-top' },
        h('div', { class: 'fs-lb-title' }, h('strong', { title: f.name }, f.label || f.name), h('small', {}, sub)),
        downloadBtn(f),
        h('button', { class: 'fs-btn fs-lb-icon', type: 'button', 'aria-label': T.close, title: T.close, onclick: closePreview }, icon('x'))),
      stage);
    let touchX = null;
    lb.addEventListener('touchstart', (e) => { touchX = e.touches[0].clientX; }, { passive: true });
    lb.addEventListener('touchend', (e) => {
      if (touchX === null) return;
      const dx = e.changedTouches[0].clientX - touchX;
      if (Math.abs(dx) > 60) step(dx < 0 ? 1 : -1);
      touchX = null;
    });
    document.body.append(lb);
    document.body.style.overflow = 'hidden';
    document.removeEventListener('keydown', onKey);
    document.addEventListener('keydown', onKey);
    const closeBtn = $('.fs-lb-icon', lb);
    if (closeBtn) closeBtn.focus({ preventScroll: true });
  };

  $$('[data-preview]').forEach((a) => {
    a.addEventListener('click', (e) => {
      if (e.metaKey || e.ctrlKey || e.shiftKey) return;
      const rel = a.closest('.fs-item').dataset.rel;
      const index = files.findIndex((f) => f.rel === rel);
      if (index !== -1) {
        e.preventDefault();
        openPreview(index);
      }
    });
  });
  if (D.open) {
    const index = files.findIndex((f) => f.rel === D.open);
    if (index !== -1) openPreview(index);
  }

  /* Uploads --------------------------------------------------------------- */
  const drop = $('#fs-drop');
  if (!drop || !D.canUpload) return;
  const input = $('#fs-file');
  const queueEl = $('#fs-queue');
  const nameInput = $('#fs-visitor');
  const exts = D.exts && D.exts !== '*' ? D.exts.split(',') : null;
  const queue = [];
  let running = 0;
  let uploadedSomething = false;

  const rid = () => {
    const a = new Uint8Array(12);
    crypto.getRandomValues(a);
    return Array.from(a, (b) => b.toString(16).padStart(2, '0')).join('');
  };

  const post = (url, form, onProgress) => new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url);
    xhr.setRequestHeader('X-Filora', '1');
    xhr.setRequestHeader('X-Filora-Ticket', D.ticket);
    xhr.responseType = 'json';
    if (onProgress) xhr.upload.onprogress = (e) => e.lengthComputable && onProgress(e.loaded);
    xhr.onload = () => {
      const body = xhr.response || {};
      if (xhr.status >= 200 && xhr.status < 300) resolve(body);
      else reject(Object.assign(new Error(body.error || ('HTTP ' + xhr.status)), { status: xhr.status }));
    };
    xhr.onerror = () => reject(Object.assign(new Error('Network error'), { status: 0 }));
    xhr.send(form);
  });

  const addRow = (file) => {
    const bar = h('i');
    const state = h('span', { class: 'fs-q-state' }, T.uploading);
    const row = h('li', { class: 'fs-q' }, h('span', { class: 'fs-q-name', title: file.name }, file.name), state, h('span', { class: 'fs-q-bar' }, bar));
    queueEl.append(row);
    return { row, bar, state };
  };

  const uploadOne = async (job) => {
    const { file, ui } = job;
    const chunk = Math.max(256 * 1024, D.chunk || 2 * 1024 * 1024);
    const total = Math.max(1, Math.ceil(file.size / chunk));
    const id = rid();
    const url = link({ a: 'upload', p: D.dir || '' });
    for (let i = 0; i < total; i++) {
      const start = i * chunk;
      const blob = file.slice(start, Math.min(file.size, start + chunk));
      let attempt = 0;
      for (;;) {
        const form = new FormData();
        form.append('upload_id', id);
        form.append('index', String(i));
        form.append('total', String(total));
        form.append('size', String(file.size));
        form.append('name', file.name);
        form.append('ft', D.ticket);
        if (nameInput) form.append('visitor_name', nameInput.value.trim());
        if (file.size > 0) form.append('chunk', blob, 'chunk');
        try {
          await post(url, form, (loaded) => {
            ui.bar.style.width = Math.min(100, ((start + loaded) / Math.max(1, file.size)) * 100) + '%';
          });
          break;
        } catch (err) {
          attempt++;
          if (attempt >= 3 || (err.status >= 400 && err.status < 500)) throw err;
          await new Promise((r) => setTimeout(r, 800 * attempt));
        }
      }
    }
  };

  const pump = () => {
    while (running < 2 && queue.length) {
      const job = queue.shift();
      running++;
      uploadOne(job)
        .then(() => {
          uploadedSomething = true;
          job.ui.row.classList.add('is-done');
          job.ui.bar.style.width = '100%';
          job.ui.state.textContent = T.done;
          const mine = $('#fs-mine');
          if (mine) {
            mine.hidden = false;
            $('ul', mine).append(h('li', {}, icon('check'), h('span', {}, job.file.name), h('small', {}, formatSize(job.file.size))));
          }
        })
        .catch((err) => {
          job.ui.row.classList.add('is-error');
          job.ui.state.textContent = err.message || T.failed;
        })
        .finally(() => {
          running--;
          if (!running && !queue.length) finished();
          else pump();
        });
    }
  };

  const finished = () => {
    if (!uploadedSomething) return;
    uploadedSomething = false;
    const form = new FormData();
    form.append('ft', D.ticket);
    if (nameInput) form.append('visitor_name', nameInput.value.trim());
    post(link({ a: 'uploaded' }), form).catch(() => {});
    if (D.mode === 'upload') setTimeout(() => window.location.reload(), 1400);
  };

  const enqueue = (fileList) => {
    if (nameInput && !nameInput.value.trim()) {
      nameInput.focus();
      nameInput.setCustomValidity(T.nameNeeded);
      nameInput.reportValidity();
      return;
    }
    for (const file of fileList) {
      const ui = addRow(file);
      const ext = (file.name.split('.').pop() || '').toLowerCase();
      if (exts && (file.name.indexOf('.') === -1 || exts.indexOf(ext) === -1)) {
        ui.row.classList.add('is-error');
        ui.state.textContent = T.badType;
        continue;
      }
      if (D.maxUpload && file.size > D.maxUpload) {
        ui.row.classList.add('is-error');
        ui.state.textContent = T.tooLarge + ' (' + formatSize(D.maxUpload) + ')';
        continue;
      }
      queue.push({ file, ui });
    }
    pump();
  };

  if (nameInput) nameInput.addEventListener('input', () => nameInput.setCustomValidity(''));
  $('#fs-browse').addEventListener('click', () => input.click());
  $$('[data-pick]').forEach((b) => b.addEventListener('click', () => {
    drop.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (nameInput && !nameInput.value.trim()) nameInput.focus({ preventScroll: true });
    else input.click();
  }));
  input.addEventListener('change', () => { enqueue(Array.from(input.files)); input.value = ''; });
  ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
  ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); if (ev === 'drop' || !drop.contains(e.relatedTarget)) drop.classList.remove('is-over'); }));
  drop.addEventListener('drop', (e) => {
    const items = Array.from(e.dataTransfer.files || []);
    if (items.length) enqueue(items);
  });
  window.addEventListener('beforeunload', (e) => {
    if (running || queue.length) { e.preventDefault(); e.returnValue = ''; }
  });
})();
