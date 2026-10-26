// File operations: API calls + feedback. Used by the toolbar, context menu, keyboard and palette.
import { batch } from '@preact/signals';
import { get, post, streamUrl } from './api';
import { can, isAdmin, C } from './config';
import { t, tn } from './i18n';
import { toast, toastError, openDialog } from './ui';
import { confirm, prompt } from '../dialogs/common';
import {
  cwd, load, refresh, selection, clipboard, navigate, prefs, setPref, selectedEntries, entries, focusPath, anchor, search, filterText, toggleDetails,
} from './store';
import { previewKind, isEditable, joinPath, parentPath, copyText, baseName } from './files';
import { enqueue, filesFromInput } from './upload';

const plural = (n) => tn('%d item', '%d items', n);

function reportErrors(result, okMessage) {
  const errors = (result && result.errors) || [];
  if (errors.length) {
    toast(errors.length === 1 ? errors[0].message : t('%1$d errors. First: %2$s', errors.length, errors[0].message), { type: 'error' });
  } else if (okMessage) {
    toast(okMessage, { type: 'success' });
  }
}

/* Navigation ----------------------------------------------------------------- */

export function openEntry(entry) {
  if (!entry) return;
  if (entry.type === 'dir') {
    navigate('files', entry.path);
    return;
  }
  if (isEditable(entry) && can('edit') && previewKind(entry) !== 'image') {
    edit(entry);
    return;
  }
  preview(entry);
}

export const goUp = () => {
  if (cwd.value !== '/') navigate('files', parentPath(cwd.value));
};

export async function preview(entry, list) {
  const { PreviewDialog } = await import('../dialogs/Preview');
  const items = (list || entries.value).filter((e) => e.type === 'file');
  openDialog(PreviewDialog, { items, start: Math.max(0, items.findIndex((e) => e.path === entry.path)) });
}

export async function edit(entry) {
  const { EditorDialog } = await import('../dialogs/Editor');
  const saved = await openDialog(EditorDialog, { entry });
  if (saved) refresh();
}

/* Create ----------------------------------------------------------------------- */

export async function newFolder() {
  const name = await prompt({ title: t('New folder'), label: t('Name'), value: t('New folder'), confirmLabel: t('Create'), icon: 'folder-plus' });
  if (!name) return;
  try {
    const entry = await post('fs/mkdir', { path: cwd.value, name });
    await refresh();
    selection.value = new Set([entry.path]);
    focusPath.value = entry.path;
  } catch (e) {
    toastError(e);
  }
}

export async function newFile() {
  const name = await prompt({ title: t('New file'), label: t('Name with extension'), value: 'untitled.txt', selectBase: true, confirmLabel: t('Create'), icon: 'file-plus' });
  if (!name) return;
  try {
    const entry = await post('fs/mkfile', { path: cwd.value, name });
    await refresh();
    selection.value = new Set([entry.path]);
    focusPath.value = entry.path;
    if (isEditable(entry) && can('edit')) edit(entry);
  } catch (e) {
    toastError(e);
  }
}

/* Upload ------------------------------------------------------------------------- */

function pick(directory) {
  const input = document.createElement('input');
  input.type = 'file';
  input.multiple = true;
  if (directory) input.webkitdirectory = true;
  input.addEventListener('change', () => enqueue(filesFromInput(input), cwd.value));
  input.click();
}
export const uploadFiles = () => pick(false);
export const uploadFolder = () => pick(true);

export async function uploadFromUrl() {
  const value = await prompt({ title: t('Upload from URL'), label: t('Public file URL'), placeholder: 'https://', confirmLabel: t('Download to this folder'), icon: 'globe', help: t('The server downloads the file directly. Private network addresses are blocked.') });
  if (!value) return;
  const id = toast(t('Downloading…'), { timeout: 0 });
  try {
    await post('fs/remote', { path: cwd.value, url: value });
    toast(t('File downloaded.'), { type: 'success' });
    refresh();
  } catch (e) {
    toastError(e);
  } finally {
    import('./ui').then((m) => m.dismiss(id));
  }
}

/* Modify ---------------------------------------------------------------------------- */

export async function rename(entry) {
  const name = await prompt({ title: t('Rename'), label: t('New name'), value: entry.name, selectBase: entry.type === 'file', confirmLabel: t('Rename'), icon: 'rename' });
  if (!name || name === entry.name) return;
  try {
    const updated = await post('fs/rename', { path: entry.path, name });
    await refresh();
    selection.value = new Set([updated.path]);
    focusPath.value = updated.path;
  } catch (e) {
    toastError(e);
  }
}

export async function remove(list, permanent = false) {
  if (!list.length) return;
  const trash = C.trash && C.trash.enabled && !permanent;
  if (!trash) {
    const ok = await confirm({
      title: t('Delete permanently?'),
      message: list.length === 1 ? t('“%s” will be permanently deleted. This cannot be undone.', list[0].name) : t('%s will be permanently deleted. This cannot be undone.', plural(list.length)),
      confirmLabel: t('Delete'),
      danger: true,
    });
    if (!ok) return;
  }
  try {
    const result = await post('fs/delete', { paths: list.map((e) => e.path), permanent: !trash });
    selection.value = new Set();
    await refresh();
    if (result.errors && result.errors.length) {
      reportErrors(result);
    } else if (result.trash && result.trash.length) {
      toast(list.length === 1 ? t('“%s” moved to the trash.', list[0].name) : t('%s moved to the trash.', plural(list.length)), {
        action: { label: t('Undo'), run: () => undoDelete(result.trash) },
      });
    } else {
      toast(t('Deleted.'), { type: 'success' });
    }
  } catch (e) {
    toastError(e);
  }
}

async function undoDelete(ids) {
  try {
    await post('trash/restore', { ids });
    toast(t('Restored.'), { type: 'success' });
    refresh();
  } catch (e) {
    toastError(e);
  }
}

export async function duplicate(list) {
  try {
    const result = await post('fs/duplicate', { paths: list.map((e) => e.path) });
    reportErrors(result);
    await refresh();
    selection.value = new Set(result.done.map((d) => d.to));
  } catch (e) {
    toastError(e);
  }
}

export function copy(list, mode = 'copy') {
  if (!list.length) return;
  clipboard.value = { mode, paths: list.map((e) => e.path), names: list.map((e) => e.name) };
  toast(mode === 'move' ? t('Cut: %s. Open a folder and paste.', plural(list.length)) : t('Copied: %s. Open a folder and paste.', plural(list.length)));
}

export async function paste(dest = cwd.value) {
  const clip = clipboard.value;
  if (!clip) return;
  await transfer(clip.paths, dest, clip.mode);
  if (clip.mode === 'move') clipboard.value = null;
}

/** Copy or move paths into a folder (drag & drop / paste). */
export async function transfer(paths, dest, mode = 'move') {
  const list = paths.filter((p) => p !== dest && (mode === 'copy' || parentPath(p) !== dest));
  if (!list.length) return;
  try {
    const result = await post('fs/transfer', { paths: list, dest, mode, conflict: 'rename' });
    reportErrors(result, result.done.length ? (mode === 'move' ? t('Moved %s.', plural(result.done.length)) : t('Copied %s.', plural(result.done.length))) : null);
    await refresh();
  } catch (e) {
    toastError(e);
  }
}

export async function moveToFolder(list, mode = 'move') {
  const { FolderPicker } = await import('../dialogs/FolderPicker');
  const dest = await openDialog(FolderPicker, { title: mode === 'move' ? t('Move to…') : t('Copy to…'), confirmLabel: mode === 'move' ? t('Move here') : t('Copy here'), start: cwd.value });
  if (dest) transfer(list.map((e) => e.path), dest, mode);
}

export async function chmod(list) {
  const { ChmodDialog } = await import('../dialogs/Chmod');
  const done = await openDialog(ChmodDialog, { entries: list });
  if (done) refresh();
}

export async function archive(list) {
  const name = await prompt({ title: t('Create a ZIP archive'), label: t('Archive name'), value: (list.length === 1 ? list[0].name : 'archive') + '.zip', selectBase: true, confirmLabel: t('Compress'), icon: 'archive' });
  if (!name) return;
  const id = toast(t('Compressing…'), { timeout: 0 });
  try {
    const entry = await post('fs/archive', { paths: list.map((e) => e.path), name, dest: cwd.value });
    await refresh();
    selection.value = new Set([entry.path]);
    toast(t('Archive created.'), { type: 'success' });
  } catch (e) {
    toastError(e);
  } finally {
    import('./ui').then((m) => m.dismiss(id));
  }
}

export async function extract(entry, folder = true) {
  const id = toast(t('Extracting…'), { timeout: 0 });
  try {
    const result = await post('fs/extract', { path: entry.path, folder });
    await refresh();
    if (result.skipped && result.skipped.length) {
      toast(t('%1$s extracted, %2$d skipped for security reasons.', plural(result.files), result.skipped.length), { type: 'warning' });
    } else {
      toast(t('%s extracted.', plural(result.files)), { type: 'success' });
    }
  } catch (e) {
    toastError(e);
  } finally {
    import('./ui').then((m) => m.dismiss(id));
  }
}

export async function editImage(entry) {
  const { ImageDialog } = await import('../dialogs/ImageTools');
  const result = await openDialog(ImageDialog, { entry });
  if (result) refresh();
}

export async function moveToVault(list) {
  const ok = await confirm({
    title: t('Move to the Vault?'),
    message: t('Files in the Vault cannot be opened with a direct URL: only through the file manager and share links. Existing direct links to these files will stop working.'),
    confirmLabel: t('Move to Vault'),
    icon: 'shield',
  });
  if (!ok) return false;
  try {
    const result = await post('fs/vault', { paths: list.map((e) => e.path) });
    reportErrors(result, t('Moved to the Vault.'));
    await refresh();
    return result;
  } catch (e) {
    toastError(e);
    return false;
  }
}

/* Download & links ------------------------------------------------------------------- */

export function download(list) {
  if (!list.length) return;
  const href = list.length === 1 && list[0].type === 'file'
    ? streamUrl('fs/download', { path: list[0].path })
    : streamUrl('fs/download', { paths: list.map((e) => e.path) });
  const a = document.createElement('a');
  a.href = href;
  a.rel = 'noopener';
  a.download = '';
  document.body.append(a);
  a.click();
  a.remove();
}

export async function copyLink(entry) {
  if (!entry.url) return;
  if (await copyText(entry.url)) toast(t('Public URL copied.'), { type: 'success' });
}
export async function copyPath(entry) {
  if (await copyText(entry.path)) toast(t('Path copied.'), { type: 'success' });
}

export async function share(entry) {
  const { ShareDialog } = await import('../dialogs/Share');
  await openDialog(ShareDialog, { entry });
  refresh();
}

export function toggleFavorite(path) {
  const favs = prefs.value.favorites || [];
  setPref('favorites', favs.includes(path) ? favs.filter((p) => p !== path) : [...favs, path]);
}

export const isFavorite = (path) => (prefs.value.favorites || []).includes(path);

/* Search ------------------------------------------------------------------------------ */

let searchController = null;
export async function runSearch(q, content = false) {
  if (!q.trim()) {
    search.value = null;
    return;
  }
  if (searchController) searchController.abort();
  searchController = new AbortController();
  search.value = { q, content, loading: true, results: [], partial: false };
  try {
    const data = await get('fs/search', { path: cwd.value, q, content }, { signal: searchController.signal });
    search.value = { q, content, loading: false, results: data.results, partial: data.partial };
  } catch (e) {
    if (e.name !== 'AbortError') {
      search.value = null;
      toastError(e);
    }
  }
}
export const clearSearch = () => {
  batch(() => {
    search.value = null;
    filterText.value = '';
  });
};

/* Menus --------------------------------------------------------------------------------- */

/** Context-menu items for a selection. */
export function itemsFor(list) {
  const one = list.length === 1 ? list[0] : null;
  const files = list.filter((e) => e.type === 'file');
  const kind = one ? previewKind(one) : null;
  const writable = list.every((e) => e.writable && !e.system);
  const items = [];
  if (one) {
    items.push({ label: one.type === 'dir' ? t('Open') : t('Open / preview'), icon: one.type === 'dir' ? 'folder' : 'eye', run: () => openEntry(one), kbd: '↵' });
    if (one.type === 'file' && isEditable(one) && can('edit')) items.push({ label: t('Edit'), icon: 'code', run: () => edit(one) });
    if (kind === 'image' && can('image') && one.writable && one.ext !== 'svg') items.push({ label: t('Image tools'), icon: 'image', run: () => editImage(one) });
  }
  if (can('download')) items.push({ label: list.length > 1 || (one && one.type === 'dir') ? t('Download as ZIP') : t('Download'), icon: 'download', run: () => download(list) });
  if (one && can('share')) items.push({ label: t('Share…'), icon: 'share', run: () => share(one) });
  if (one) items.push({ label: t('Details'), icon: 'info', run: () => toggleDetails(true), kbd: 'I' });
  items.push('-');
  if (one && can('rename')) items.push({ label: t('Rename'), icon: 'rename', run: () => rename(one), disabled: !writable, kbd: 'F2' });
  if (can('copy')) items.push({ label: t('Duplicate'), icon: 'copy', run: () => duplicate(list) });
  if (can('copy')) items.push({ label: t('Copy'), icon: 'copy', run: () => copy(list, 'copy'), kbd: 'Ctrl+C' });
  if (can('move')) items.push({ label: t('Cut'), icon: 'cut', run: () => copy(list, 'move'), disabled: !writable, kbd: 'Ctrl+X' });
  if (can('move')) items.push({ label: t('Move to…'), icon: 'arrow-right', run: () => moveToFolder(list, 'move'), disabled: !writable });
  if (can('copy')) items.push({ label: t('Copy to…'), icon: 'copy', run: () => moveToFolder(list, 'copy') });
  if (clipboard.value && one && one.type === 'dir' && can(clipboard.value.mode === 'move' ? 'move' : 'copy')) items.push({ label: t('Paste into this folder'), icon: 'paste', run: () => paste(one.path) });
  items.push('-');
  if (can('archive') && C.zip) items.push({ label: t('Compress to ZIP'), icon: 'archive', run: () => archive(list) });
  if (one && one.ext === 'zip' && can('extract') && C.zip) items.push({ label: t('Extract here'), icon: 'extract', run: () => extract(one, true) });
  if (one) items.push({ label: isFavorite(one.path) ? t('Remove from favorites') : t('Add to favorites'), icon: 'star', run: () => toggleFavorite(one.path) });
  if (one && one.url) items.push({ label: t('Copy public URL'), icon: 'link', run: () => copyLink(one) });
  if (one) items.push({ label: t('Copy path'), icon: 'terminal', run: () => copyPath(one) });
  if (can('chmod')) items.push({ label: t('Permissions…'), icon: 'key', run: () => chmod(list) });
  if (isAdmin && files.length === list.length && list.some((e) => e.url)) items.push({ label: t('Move to Vault (private)'), icon: 'shield', run: () => moveToVault(list) });
  if (can('delete')) {
    items.push('-');
    items.push({ label: C.trash && C.trash.enabled ? t('Move to trash') : t('Delete'), icon: 'trash', run: () => remove(list), danger: true, disabled: !writable, kbd: 'Del' });
  }
  return items.filter((it, i, arr) => !(it === '-' && (i === 0 || arr[i - 1] === '-' || i === arr.length - 1)));
}

/** Context-menu items for the empty area of a folder. */
export function itemsForFolder() {
  const items = [];
  if (can('mkdir')) items.push({ label: t('New folder'), icon: 'folder-plus', run: newFolder });
  if (can('mkfile')) items.push({ label: t('New file'), icon: 'file-plus', run: newFile });
  if (can('upload')) {
    items.push({ label: t('Upload files'), icon: 'upload', run: uploadFiles });
    items.push({ label: t('Upload a folder'), icon: 'cloud-upload', run: uploadFolder });
  }
  if (clipboard.value) items.push({ label: t('Paste'), icon: 'paste', run: () => paste(), kbd: 'Ctrl+V' });
  items.push('-');
  items.push({ label: t('Refresh'), icon: 'refresh', run: () => refresh() });
  items.push({ label: t('Select all'), icon: 'check', run: () => { selection.value = new Set(entries.value.map((e) => e.path)); }, kbd: 'Ctrl+A' });
  if (can('share') && cwd.value !== '/') items.push({ label: t('Share this folder…'), icon: 'share', run: () => share({ path: cwd.value, name: baseName(cwd.value), type: 'dir' }) });
  return items;
}

export { joinPath, anchor, selectedEntries };
