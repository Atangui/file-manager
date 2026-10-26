// Global state (signals): routing, preferences, current folder, selection, clipboard.
import { signal, computed, batch, effect } from '@preact/signals';
import { C } from './config';
import { get, post } from './api';
import { sortEntries } from './files';

/* Routing ------------------------------------------------------------------ */
const VIEWS = ['files', 'shares', 'trash', 'activity', 'tools', 'settings'];

function parseHash() {
  const raw = location.hash.replace(/^#\/?/, '');
  const [view, ...rest] = raw.split('/');
  if (!VIEWS.includes(view)) return { view: C.route || 'files', path: '/', tab: '' };
  const decoded = rest.map((s) => {
    try { return decodeURIComponent(s); } catch (e) { return s; }
  });
  if (view === 'files') return { view, path: '/' + decoded.filter(Boolean).join('/'), tab: '' };
  return { view, path: '/', tab: decoded[0] || '' };
}

export const route = signal(parseHash());
window.addEventListener('hashchange', () => {
  route.value = parseHash();
});

export function hrefFor(view, pathOrTab = '') {
  if (view === 'files') {
    const segs = pathOrTab.split('/').filter(Boolean).map(encodeURIComponent);
    return '#/files' + (segs.length ? '/' + segs.join('/') : '');
  }
  return '#/' + view + (pathOrTab ? '/' + encodeURIComponent(pathOrTab) : '');
}

export function navigate(view, pathOrTab = '') {
  const href = hrefFor(view, pathOrTab);
  if (location.hash !== href) location.hash = href;
  else route.value = parseHash();
}

/* Preferences -------------------------------------------------------------- */
export const prefs = signal({ ...(C.prefs || {}) });
let prefTimer = null;
let pending = {};
export function setPref(key, value) {
  prefs.value = { ...prefs.value, [key]: value };
  pending[key] = value;
  clearTimeout(prefTimer);
  prefTimer = setTimeout(() => {
    const payload = pending;
    pending = {};
    post('prefs', payload).catch(() => {});
  }, 600);
}

const media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
const systemDark = signal(media ? media.matches : false);
if (media && media.addEventListener) media.addEventListener('change', (e) => { systemDark.value = e.matches; });
export const resolvedTheme = computed(() => {
  const p = prefs.value.theme || 'auto';
  return p === 'auto' ? (systemDark.value ? 'dark' : 'light') : p;
});

/* Files -------------------------------------------------------------------- */
export const cwd = signal('/');
export const listing = signal(null);
export const loading = signal(false);
export const loadError = signal(null);
export const selection = signal(new Set());
export const anchor = signal(null);
export const focusPath = signal(null);
export const clipboard = signal(null); // { mode: 'copy' | 'move', paths: [], entries: [] }
export const filterText = signal('');
export const search = signal(null); // { q, content, loading, results, partial }
export const drawerOpen = signal(false);
export const fullscreen = signal(false);
export const tree = signal({}); // path -> children
export const treeOpen = signal(new Set(['/']));
export const usage = signal(null);

/* Details panel: remembered on desktop, opt-in per session on phones. */
const narrowMq = window.matchMedia ? window.matchMedia('(max-width: 720px)') : null;
export const narrow = signal(narrowMq ? narrowMq.matches : false);
if (narrowMq && narrowMq.addEventListener) narrowMq.addEventListener('change', (e) => { narrow.value = e.matches; });
const detailsMobile = signal(false);
export const showDetails = computed(() => (narrow.value ? detailsMobile.value : !!prefs.value.details));
export function toggleDetails(force) {
  const next = typeof force === 'boolean' ? force : !showDetails.value;
  if (narrow.value) detailsMobile.value = next;
  else setPref('details', next);
}

export const entries = computed(() => {
  const s = search.value;
  let list = s ? s.results || [] : (listing.value && listing.value.entries) || [];
  const p = prefs.value;
  if (!p.showHidden) list = list.filter((e) => e.name[0] !== '.');
  const q = filterText.value.trim().toLowerCase();
  if (q && !s) list = list.filter((e) => e.name.toLowerCase().includes(q));
  return sortEntries(list, p.sort || 'name', p.dir || 'asc');
});

export const selectedEntries = computed(() => {
  const sel = selection.value;
  if (!sel.size) return [];
  return entries.value.filter((e) => sel.has(e.path));
});

let controller = null;
let seq = 0;

/** Load a folder. */
export async function load(path = cwd.value, { silent = false } = {}) {
  const id = ++seq;
  if (controller) controller.abort();
  controller = new AbortController();
  if (!silent) loading.value = true;
  try {
    const data = await get('fs/list', { path }, { signal: controller.signal });
    if (id !== seq) return;
    batch(() => {
      const changed = cwd.value !== data.path;
      listing.value = data;
      cwd.value = data.path;
      loadError.value = null;
      search.value = null;
      if (changed) {
        selection.value = new Set();
        anchor.value = null;
        focusPath.value = null;
        filterText.value = '';
      } else {
        const valid = new Set(data.entries.map((e) => e.path));
        const kept = new Set([...selection.value].filter((p) => valid.has(p)));
        if (kept.size !== selection.value.size) selection.value = kept;
      }
    });
    rememberRecent(data.path);
    // Keep the sidebar tree in sync with what we just saw.
    const dirs = data.entries.filter((e) => e.type === 'dir').map((e) => ({ name: e.name, path: e.path, hasChildren: true }));
    tree.value = { ...tree.value, [data.path]: dirs };
  } catch (err) {
    if (err.name === 'AbortError') return;
    if (id !== seq) return;
    loadError.value = err;
  } finally {
    if (id === seq) loading.value = false;
  }
}

export const refresh = () => load(cwd.value, { silent: true });

function rememberRecent(path) {
  const recent = prefs.value.recent || [];
  if (recent[0] === path) return;
  setPref('recent', [path, ...recent.filter((p) => p !== path)].slice(0, 12));
}

export async function loadTree(path) {
  try {
    const children = await get('fs/tree', { path });
    tree.value = { ...tree.value, [path]: children };
  } catch (e) {
    tree.value = { ...tree.value, [path]: [] };
  }
}

/* Selection helpers --------------------------------------------------------- */
export function select(paths, { add = false } = {}) {
  selection.value = new Set(add ? [...selection.value, ...paths] : paths);
}
export function toggleSelect(path) {
  const next = new Set(selection.value);
  if (next.has(path)) next.delete(path);
  else next.add(path);
  selection.value = next;
}
export function selectRange(toPath) {
  const list = entries.value;
  const from = anchor.value ? list.findIndex((e) => e.path === anchor.value) : 0;
  const to = list.findIndex((e) => e.path === toPath);
  if (to === -1) return;
  const [a, b] = from <= to ? [Math.max(0, from), to] : [to, from];
  selection.value = new Set(list.slice(a, b + 1).map((e) => e.path));
}
export const clearSelection = () => {
  selection.value = new Set();
};

/* Apply theme to the host element whenever it changes. */
export function bindTheme(el) {
  return effect(() => {
    el.dataset.theme = resolvedTheme.value;
    el.dataset.density = prefs.value.density || 'comfortable';
  });
}
