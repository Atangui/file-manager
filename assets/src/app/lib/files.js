// File-type helpers shared by the views.
import { t } from './i18n';

export const CAT_LABEL = {
  dir: () => t('Folder'),
  image: () => t('Image'),
  video: () => t('Video'),
  audio: () => t('Audio'),
  pdf: () => t('PDF document'),
  archive: () => t('Archive'),
  doc: () => t('Document'),
  sheet: () => t('Spreadsheet'),
  slides: () => t('Presentation'),
  font: () => t('Font'),
  code: () => t('Text / code'),
  file: () => t('File'),
};

export const typeLabel = (entry) => {
  if (entry.type === 'dir') return t('Folder');
  const base = (CAT_LABEL[entry.cat] || CAT_LABEL.file)();
  return entry.ext ? base + ' · ' + entry.ext.toUpperCase() : base;
};

const BROWSER_IMAGES = ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico', 'svg'];
const BROWSER_VIDEO = ['mp4', 'm4v', 'webm', 'ogv', 'mov'];
const BROWSER_AUDIO = ['mp3', 'm4a', 'aac', 'wav', 'ogg', 'oga', 'flac', 'opus'];

/** What kind of in-app preview is possible. */
export function previewKind(entry) {
  if (!entry || entry.type === 'dir') return null;
  const ext = entry.ext;
  if (BROWSER_IMAGES.includes(ext)) return 'image';
  if (BROWSER_VIDEO.includes(ext)) return 'video';
  if (BROWSER_AUDIO.includes(ext)) return 'audio';
  if (ext === 'pdf') return 'pdf';
  if (ext === 'zip') return 'zip';
  if (['woff', 'woff2', 'ttf', 'otf'].includes(ext)) return 'font';
  if (entry.cat === 'code' && entry.size < 2 * 1024 * 1024) return 'text';
  return null;
}

export const isEditable = (entry) => entry && entry.type === 'file' && entry.cat === 'code' && entry.size <= 5 * 1024 * 1024;

export const parentPath = (path) => {
  const clean = path.replace(/\/+$/, '');
  const i = clean.lastIndexOf('/');
  return i <= 0 ? '/' : clean.slice(0, i);
};

export const joinPath = (dir, name) => (dir === '/' ? '' : dir.replace(/\/+$/, '')) + '/' + name;

export const baseName = (path) => {
  const clean = path.replace(/\/+$/, '');
  return clean.slice(clean.lastIndexOf('/') + 1) || '/';
};

const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });

/** Folders first, then by key. */
export function sortEntries(entries, key = 'name', dir = 'asc') {
  const mul = dir === 'desc' ? -1 : 1;
  return [...entries].sort((a, b) => {
    if (a.type !== b.type) return a.type === 'dir' ? -1 : 1;
    let r = 0;
    if (key === 'size') r = (a.size || 0) - (b.size || 0);
    else if (key === 'mtime') r = a.mtime - b.mtime;
    else if (key === 'type') r = collator.compare(a.ext || '', b.ext || '');
    if (r === 0) r = collator.compare(a.name, b.name);
    return r * mul;
  });
}

/** Simple fuzzy score (subsequence match, bonus for consecutive / start-of-word hits). */
export function fuzzy(query, text) {
  if (!query) return 1;
  const q = query.toLowerCase();
  const s = text.toLowerCase();
  const direct = s.indexOf(q);
  if (direct !== -1) return 100 - direct + (direct === 0 ? 50 : 0);
  let score = 0;
  let qi = 0;
  let last = -2;
  for (let i = 0; i < s.length && qi < q.length; i++) {
    if (s[i] === q[qi]) {
      score += last === i - 1 ? 5 : 1;
      if (i === 0 || /[\s/_.-]/.test(s[i - 1])) score += 3;
      last = i;
      qi++;
    }
  }
  return qi === q.length ? score : 0;
}

/** A strong random password for share links. */
export function randomPassword(length = 14) {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
  const bytes = new Uint32Array(length);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (b) => chars[b % chars.length]).join('');
}

export async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch (e) {
    const area = document.createElement('textarea');
    area.value = text;
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.append(area);
    area.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
    area.remove();
    return ok;
  }
}
