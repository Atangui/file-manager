// UI state shared across the app: toasts, dialogs (promise based), context menus.
import { signal } from '@preact/signals';

let uid = 0;

/* Toasts ------------------------------------------------------------------ */
export const toasts = signal([]);

/**
 * Show a toast. Options: type (info|success|error|warning), action {label, run}, timeout (ms, 0 = sticky).
 * Returns the toast id.
 */
export function toast(message, opts = {}) {
  const id = ++uid;
  const item = { id, message, type: opts.type || 'info', action: opts.action || null, progress: opts.progress };
  toasts.value = [...toasts.value.slice(-4), item];
  const timeout = opts.timeout ?? (opts.type === 'error' ? 7000 : opts.action ? 6500 : 3500);
  if (timeout) setTimeout(() => dismiss(id), timeout);
  return id;
}
export const dismiss = (id) => {
  toasts.value = toasts.value.filter((x) => x.id !== id);
};
export const toastError = (err) => {
  if (err && err.name === 'AbortError') return;
  toast((err && err.message) || String(err), { type: 'error' });
};

/* Dialogs ----------------------------------------------------------------- */
export const dialogs = signal([]);

/** Open a dialog component; resolves with the value passed to close(). */
export function openDialog(Component, props = {}) {
  return new Promise((resolve) => {
    const id = ++uid;
    dialogs.value = [...dialogs.value, { id, Component, props, resolve }];
  });
}
export function closeDialog(id, value) {
  const d = dialogs.value.find((x) => x.id === id);
  dialogs.value = dialogs.value.filter((x) => x.id !== id);
  if (d) d.resolve(value);
}

/* Context menus ----------------------------------------------------------- */
// { x, y, items: [{ label, icon, run, danger, disabled, kbd } | '-' ], anchor }
export const menu = signal(null);
export function openMenu(event, items, anchorEl) {
  event.preventDefault();
  event.stopPropagation();
  let x = event.clientX;
  let y = event.clientY;
  if (anchorEl || !x) {
    const r = (anchorEl || event.currentTarget).getBoundingClientRect();
    x = r.left;
    y = r.bottom + 4;
  }
  menu.value = { x, y, items: items.filter(Boolean) };
}
export const closeMenu = () => {
  menu.value = null;
};
