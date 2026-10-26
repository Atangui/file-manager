// Upload queue: chunked, resumable per chunk, parallel across files, folder-aware.
import { signal, computed } from '@preact/signals';
import { C } from './config';
import { upload as xhrUpload } from './api';
import { t } from './i18n';
import { toast } from './ui';

export const uploads = signal([]);
export const uploadPanelOpen = signal(true);
let listeners = [];
/** Called with the destination folder each time a file finishes. */
export const onUploaded = (fn) => {
  listeners.push(fn);
  return () => { listeners = listeners.filter((f) => f !== fn); };
};

export const uploadStats = computed(() => {
  const list = uploads.value;
  const active = list.filter((u) => u.status === 'queued' || u.status === 'uploading');
  const total = list.reduce((s, u) => s + (u.status === 'canceled' ? 0 : u.size), 0);
  const loaded = list.reduce((s, u) => s + (u.status === 'canceled' ? 0 : u.status === 'done' ? u.size : u.loaded), 0);
  return {
    active: active.length,
    done: list.filter((u) => u.status === 'done').length,
    errors: list.filter((u) => u.status === 'error').length,
    percent: total ? Math.round((loaded / total) * 100) : 0,
  };
});

let seq = 0;
const update = (id, patch) => {
  uploads.value = uploads.value.map((u) => (u.id === id ? { ...u, ...patch } : u));
};
const rid = () => {
  const a = new Uint8Array(12);
  crypto.getRandomValues(a);
  return Array.from(a, (b) => b.toString(16).padStart(2, '0')).join('');
};

const controllers = new Map();
const files = new Map();
let running = 0;
const MAX = 3;

/** Queue files: items are { file, relpath } (relpath keeps folder structure). */
export function enqueue(items, dest, conflict = 'rename') {
  const allowed = C.profile && C.profile.uploadExts && C.profile.uploadExts !== '*' ? C.profile.uploadExts.split(',') : null;
  const max = (C.profile && C.profile.maxUpload) || 0;
  const added = [];
  for (const { file, relpath } of items) {
    const id = ++seq;
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    let error = '';
    if (allowed && (file.name.indexOf('.') === -1 || !allowed.includes(ext))) error = t('This file type is not allowed.');
    else if (max && file.size > max) error = t('This file is too large.');
    files.set(id, file);
    added.push({ id, name: file.name, relpath: relpath || file.name, size: file.size, loaded: 0, dest, conflict, status: error ? 'error' : 'queued', error });
  }
  if (!added.length) return;
  uploads.value = [...uploads.value, ...added];
  uploadPanelOpen.value = true;
  pump();
}

function pump() {
  while (running < MAX) {
    const next = uploads.value.find((u) => u.status === 'queued');
    if (!next) break;
    running++;
    update(next.id, { status: 'uploading' });
    run(next).finally(() => {
      running--;
      pump();
    });
  }
}

async function run(item) {
  const file = files.get(item.id);
  const controller = new AbortController();
  controllers.set(item.id, controller);
  const chunk = Math.max(256 * 1024, C.chunk || 2 * 1024 * 1024);
  const total = Math.max(1, Math.ceil(file.size / chunk));
  const uploadId = rid();
  try {
    let result = null;
    for (let i = 0; i < total; i++) {
      const start = i * chunk;
      const blob = file.slice(start, Math.min(file.size, start + chunk));
      for (let attempt = 0; ; attempt++) {
        const form = new FormData();
        form.append('upload_id', uploadId);
        form.append('index', String(i));
        form.append('total', String(total));
        form.append('size', String(file.size));
        form.append('name', file.name);
        form.append('relpath', item.relpath);
        form.append('path', item.dest);
        form.append('conflict', item.conflict);
        if (file.size > 0) form.append('chunk', blob, 'chunk');
        try {
          result = await xhrUpload('fs/upload', form, (loaded) => update(item.id, { loaded: start + loaded }), controller.signal);
          break;
        } catch (err) {
          if (err.name === 'AbortError' || attempt >= 2 || (err.status >= 400 && err.status < 500)) throw err;
          await new Promise((r) => setTimeout(r, 700 * (attempt + 1)));
        }
      }
    }
    update(item.id, { status: 'done', loaded: file.size, entry: result && result.result });
    listeners.forEach((fn) => fn(item.dest));
  } catch (err) {
    if (err.name === 'AbortError') update(item.id, { status: 'canceled' });
    else update(item.id, { status: 'error', error: err.message });
  } finally {
    controllers.delete(item.id);
    if (!uploads.value.some((u) => u.status === 'queued' || u.status === 'uploading')) {
      const stats = uploadStats.value;
      if (stats.errors) toast(t('%1$d uploaded, %2$d failed.', stats.done, stats.errors), { type: 'warning' });
      else if (stats.done) toast(t('Upload complete.'), { type: 'success' });
    }
  }
}

export function cancelUpload(id) {
  const c = controllers.get(id);
  if (c) c.abort();
  else update(id, { status: 'canceled' });
}
export function retryUpload(id) {
  update(id, { status: 'queued', loaded: 0, error: '' });
  pump();
}
export function clearUploads() {
  uploads.value = uploads.value.filter((u) => u.status === 'queued' || u.status === 'uploading');
  for (const id of [...files.keys()]) if (!uploads.value.some((u) => u.id === id)) files.delete(id);
}
export function cancelAll() {
  uploads.value.forEach((u) => (u.status === 'queued' || u.status === 'uploading') && cancelUpload(u.id));
}

/* Drag & drop helpers --------------------------------------------------------- */

function readEntries(reader) {
  return new Promise((resolve) => reader.readEntries(resolve, () => resolve([])));
}
async function walk(entry, prefix, out) {
  if (entry.isFile) {
    const file = await new Promise((resolve) => entry.file(resolve, () => resolve(null)));
    if (file) out.push({ file, relpath: prefix + file.name });
    return;
  }
  if (entry.isDirectory) {
    const reader = entry.createReader();
    for (;;) {
      const batch = await readEntries(reader);
      if (!batch.length) break;
      for (const child of batch) await walk(child, prefix + entry.name + '/', out);
    }
  }
}

/** Extract files (including folder trees) from a drop event. */
export async function filesFromDrop(dataTransfer) {
  const items = Array.from(dataTransfer.items || []);
  const entries = items.map((i) => (i.webkitGetAsEntry ? i.webkitGetAsEntry() : null)).filter(Boolean);
  if (entries.length) {
    const out = [];
    for (const entry of entries) await walk(entry, '', out);
    return out;
  }
  return Array.from(dataTransfer.files || []).map((file) => ({ file, relpath: file.name }));
}

/** Files picked with <input type=file webkitdirectory>. */
export const filesFromInput = (input) => Array.from(input.files || []).map((file) => ({ file, relpath: file.webkitRelativePath || file.name }));

export const hasFiles = (e) => e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files');
