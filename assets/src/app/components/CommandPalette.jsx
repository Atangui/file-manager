// ⌘K / Ctrl+K command palette: actions, sections, favorites, recent folders and items of the current folder.
import { signal } from '@preact/signals';
import { useEffect, useLayoutEffect, useRef, useState } from 'preact/hooks';
import { Icon } from './Icon';
import { FileIcon } from './FileIcon';
import { Kbd, modKey } from './ui';
import { t } from '../lib/i18n';
import { C, can, isAdmin, profile } from '../lib/config';
import { navigate, prefs, setPref, entries, cwd, route, fullscreen, refresh, toggleDetails } from '../lib/store';
import { fuzzy, baseName } from '../lib/files';
import { newFolder, newFile, uploadFiles, uploadFolder, uploadFromUrl, openEntry, share, runSearch, openShortcuts } from '../lib/actions';

export const paletteOpen = signal(false);

function commands() {
  const list = [];
  const add = (label, icon, run, group = t('Actions'), hint) => list.push({ label, icon, run, group, hint });
  if (route.value.view === 'files') {
    if (can('mkdir')) add(t('New folder'), 'folder-plus', newFolder);
    if (can('mkfile')) add(t('New file'), 'file-plus', newFile);
    if (can('upload')) {
      add(t('Upload files'), 'upload', uploadFiles);
      add(t('Upload a folder'), 'cloud-upload', uploadFolder);
      if (isAdmin) add(t('Upload from URL'), 'globe', uploadFromUrl);
    }
    if (can('share') && cwd.value !== '/') add(t('Share this folder…'), 'share', () => share({ path: cwd.value, name: baseName(cwd.value), type: 'dir' }));
    add(t('Refresh'), 'refresh', () => refresh());
  }
  add(prefs.value.view === 'grid' ? t('Switch to list view') : t('Switch to grid view'), prefs.value.view === 'grid' ? 'list' : 'grid', () => setPref('view', prefs.value.view === 'grid' ? 'list' : 'grid'), t('View'));
  add(prefs.value.showHidden ? t('Hide hidden files') : t('Show hidden files'), prefs.value.showHidden ? 'eye-off' : 'eye', () => setPref('showHidden', !prefs.value.showHidden), t('View'));
  add(t('Toggle details panel'), 'panel', () => toggleDetails(), t('View'));
  add(prefs.value.density === 'compact' ? t('Comfortable density') : t('Compact density'), 'list', () => setPref('density', prefs.value.density === 'compact' ? 'comfortable' : 'compact'), t('View'));
  add(t('Light theme'), 'sun', () => setPref('theme', 'light'), t('View'));
  add(t('Dark theme'), 'moon', () => setPref('theme', 'dark'), t('View'));
  add(t('System theme'), 'monitor', () => setPref('theme', 'auto'), t('View'));
  if (!C.frontend) add(fullscreen.value ? t('Exit full screen') : t('Full screen'), fullscreen.value ? 'compress' : 'expand', () => { fullscreen.value = !fullscreen.value; }, t('View'));
  add(t('Keyboard shortcuts'), 'keyboard', openShortcuts, t('Help'), '?');
  const go = t('Go to');
  add(t('Files'), 'folder', () => navigate('files', '/'), go);
  if (can('share')) add(t('Shared links'), 'link', () => navigate('shares'), go);
  if (C.trash && C.trash.enabled && can('delete')) add(t('Trash'), 'trash', () => navigate('trash'), go);
  add(t('Activity'), 'activity', () => navigate('activity'), go);
  if (isAdmin) add(t('Security tools'), 'shield-check', () => navigate('tools'), go);
  if (C.user && C.user.canSettings) add(t('Settings'), 'settings', () => navigate('settings'), go);
  for (const p of prefs.value.favorites || []) add(p === '/' ? profile.rootName : baseName(p), 'star', () => navigate('files', p), t('Favorites'), p);
  for (const p of (prefs.value.recent || []).slice(0, 8)) add(p === '/' ? profile.rootName : baseName(p), 'clock', () => navigate('files', p), t('Recent folders'), p);
  return list;
}

export function CommandPalette() {
  const [q, setQ] = useState('');
  const [active, setActive] = useState(0);
  const input = useRef(null);
  const listRef = useRef(null);
  const open = paletteOpen.value;

  useLayoutEffect(() => {
    if (open) {
      setQ('');
      setActive(0);
      if (input.current) input.current.focus();
    }
  }, [open]);

  useEffect(() => {
    const el = listRef.current && listRef.current.querySelector('.is-active');
    if (el) el.scrollIntoView({ block: 'nearest' });
  });

  if (!open) return null;
  const close = () => { paletteOpen.value = false; };

  let items = commands()
    .map((c) => ({ ...c, score: fuzzy(q, c.label + ' ' + (c.hint || '')) }))
    .filter((c) => c.score > 0);
  if (q && route.value.view === 'files') {
    const files = entries.value
      .map((e) => ({ label: e.name, entry: e, group: t('In this folder'), run: () => openEntry(e), score: fuzzy(q, e.name) }))
      .filter((c) => c.score > 0)
      .slice(0, 30);
    items = items.concat(files);
  }
  if (q) items.sort((a, b) => b.score - a.score);
  if (q.startsWith('/')) {
    items.unshift({ label: t('Go to %s', q), icon: 'arrow-right', group: t('Path'), run: () => navigate('files', q) });
  } else if (q.length > 1 && route.value.view === 'files') {
    items.push({ label: t('Search “%s” in subfolders', q), icon: 'search', group: t('Search'), run: () => runSearch(q, false) });
    items.push({ label: t('Search “%s” inside files', q), icon: 'file-text', group: t('Search'), run: () => runSearch(q, true) });
  }
  items = items.slice(0, 60);
  const current = Math.min(active, items.length - 1);

  const run = (item) => {
    close();
    if (item) setTimeout(() => item.run(), 10);
  };
  const onKey = (e) => {
    if (e.key === 'Escape') {
      e.preventDefault();
      close();
    } else if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActive((current + 1) % Math.max(1, items.length));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActive((current - 1 + items.length) % Math.max(1, items.length));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      run(items[current]);
    }
  };

  let lastGroup = null;
  return (
    <div class="flr-backdrop flr-backdrop--top" onMouseDown={(e) => e.target === e.currentTarget && close()}>
      <div class="flr-palette" role="dialog" aria-modal="true" aria-label={t('Command palette')}>
        <div class="flr-palette-input">
          <Icon name="search" size={18} />
          <input
            ref={input}
            value={q}
            onInput={(e) => { setQ(e.currentTarget.value); setActive(0); }}
            onKeyDown={onKey}
            placeholder={t('Type a command, a file name or a path (/…)')}
            spellcheck={false}
            aria-autocomplete="list"
          />
          <Kbd>Esc</Kbd>
        </div>
        <div class="flr-palette-list" ref={listRef} role="listbox">
          {items.length ? items.map((item, i) => {
            const header = item.group !== lastGroup ? <div class="flr-palette-group">{item.group}</div> : null;
            lastGroup = item.group;
            return (
              <div key={item.group + item.label + i}>
                {header}
                <button
                  type="button"
                  role="option"
                  aria-selected={String(i === current)}
                  class={'flr-palette-item' + (i === current ? ' is-active' : '')}
                  onMouseEnter={() => setActive(i)}
                  onClick={() => run(item)}
                >
                  {item.entry ? <FileIcon entry={item.entry} size="sm" /> : <Icon name={item.icon || 'arrow-right'} size={17} />}
                  <span class="flr-palette-label">{item.label}</span>
                  {item.hint ? <span class="flr-palette-hint">{item.hint}</span> : null}
                </button>
              </div>
            );
          }) : <p class="flr-muted flr-pad">{t('No matching command.')}</p>}
        </div>
        <footer class="flr-palette-foot">
          <span><Kbd>↑</Kbd><Kbd>↓</Kbd> {t('navigate')}</span>
          <span><Kbd>↵</Kbd> {t('run')}</span>
          <span><Kbd>{modKey}</Kbd><Kbd>K</Kbd> {t('toggle')}</span>
        </footer>
      </div>
    </div>
  );
}
