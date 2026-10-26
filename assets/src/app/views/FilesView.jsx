// The main file browser screen.
import { useEffect, useRef } from 'preact/hooks';
import { Icon } from '../components/Icon';
import { Button, IconButton, Kbd, modKey, Badge } from '../components/ui';
import { Breadcrumbs } from '../components/Breadcrumbs';
import { Browser, moveFocus, gridColumns } from '../components/Browser';
import { DetailsPanel } from '../components/DetailsPanel';
import { paletteOpen } from '../components/CommandPalette';
import { t, tn, formatSize } from '../lib/i18n';
import { C, can, isAdmin, profile } from '../lib/config';
import {
  route, cwd, load, listing, entries, selection, selectedEntries, focusPath, prefs, setPref, clipboard, filterText, search,
  clearSelection, select, anchor, drawerOpen, fullscreen, refresh, loading, showDetails, toggleDetails,
} from '../lib/store';
import { openMenu, dialogs, menu } from '../lib/ui';
import {
  itemsFor, newFolder, newFile, uploadFiles, uploadFolder, uploadFromUrl, openEntry, goUp, rename, remove, copy, paste,
  download, share, preview, runSearch, clearSearch, moveToFolder, duplicate,
} from '../lib/actions';
import { onUploaded } from '../lib/upload';

function NewMenu(e) {
  openMenu(e, [
    can('mkdir') && { label: t('New folder'), icon: 'folder-plus', run: newFolder, kbd: 'Shift+N' },
    can('mkfile') && { label: t('New file'), icon: 'file-plus', run: newFile },
    (can('mkdir') || can('mkfile')) && can('upload') && '-',
    can('upload') && { label: t('Upload files'), icon: 'upload', run: uploadFiles, kbd: 'U' },
    can('upload') && { label: t('Upload a folder'), icon: 'cloud-upload', run: uploadFolder },
    can('upload') && isAdmin && { label: t('Upload from URL'), icon: 'globe', run: uploadFromUrl },
  ], e.currentTarget);
}

function ViewMenu(e) {
  const p = prefs.value;
  const sortItem = (key, label) => ({
    label, icon: p.sort === key ? (p.dir === 'asc' ? 'chevron-up' : 'chevron-down') : null,
    run: () => (p.sort === key ? setPref('dir', p.dir === 'asc' ? 'desc' : 'asc') : (setPref('sort', key), setPref('dir', key === 'name' || key === 'type' ? 'asc' : 'desc'))),
  });
  openMenu(e, [
    { header: t('Sort by') },
    sortItem('name', t('Name')),
    sortItem('mtime', t('Date modified')),
    sortItem('size', t('Size')),
    sortItem('type', t('Type')),
    '-',
    { label: p.showHidden ? t('Hide hidden files') : t('Show hidden files'), icon: p.showHidden ? 'eye-off' : 'eye', run: () => setPref('showHidden', !p.showHidden), kbd: modKey + '+.' },
    { label: p.density === 'compact' ? t('Comfortable density') : t('Compact density'), icon: 'list', run: () => setPref('density', p.density === 'compact' ? 'comfortable' : 'compact') },
    { label: t('Details panel'), icon: 'panel', run: () => toggleDetails(), kbd: 'I' },
    !C.frontend && { label: fullscreen.value ? t('Exit full screen') : t('Full screen'), icon: fullscreen.value ? 'compress' : 'expand', run: () => { fullscreen.value = !fullscreen.value; } },
  ], e.currentTarget);
}

function SearchBox() {
  const ref = useRef(null);
  const s = search.value;
  useEffect(() => {
    const onKey = (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f' && route.value.view === 'files' && !dialogs.value.length) {
        e.preventDefault();
        ref.current && ref.current.focus();
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);
  return (
    <div class={'flr-search' + (s ? ' is-searching' : '')}>
      <Icon name="search" size={17} />
      <input
        ref={ref}
        type="search"
        value={s ? s.q : filterText.value}
        placeholder={t('Filter or search…')}
        title={t('Type to filter this folder. Press Enter to search subfolders, Shift+Enter to search inside files.')}
        aria-label={t('Search')}
        onInput={(e) => {
          if (search.value) search.value = null;
          filterText.value = e.currentTarget.value;
        }}
        onKeyDown={(e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            runSearch(e.currentTarget.value, e.altKey || e.shiftKey);
          } else if (e.key === 'Escape') {
            clearSearch();
            e.currentTarget.blur();
          }
        }}
      />
      {filterText.value || s ? <IconButton icon="x" size="sm" label={t('Clear search')} onClick={clearSearch} /> : <span class="flr-kbds"><Kbd>{modKey}</Kbd><Kbd>F</Kbd></span>}
    </div>
  );
}

function SelectionBar() {
  const sel = selectedEntries.value;
  if (!sel.length) return null;
  const one = sel.length === 1 ? sel[0] : null;
  const writable = sel.every((e) => e.writable && !e.system);
  return (
    <div class="flr-selbar" role="toolbar" aria-label={t('Selection actions')}>
      <IconButton icon="x" size="sm" label={t('Clear selection')} onClick={clearSelection} />
      <strong>{tn('%d selected', '%d selected', sel.length)}</strong>
      <div class="flr-selbar-actions">
        {can('download') ? <Button size="sm" variant="ghost" icon="download" onClick={() => download(sel)}>{t('Download')}</Button> : null}
        {one && can('share') ? <Button size="sm" variant="ghost" icon="share" onClick={() => share(one)}>{t('Share')}</Button> : null}
        {can('copy') ? <Button size="sm" variant="ghost" icon="copy" class="flr-hide-sm" onClick={() => copy(sel, 'copy')}>{t('Copy')}</Button> : null}
        {can('move') ? <Button size="sm" variant="ghost" icon="arrow-right" class="flr-hide-sm" disabled={!writable} onClick={() => moveToFolder(sel, 'move')}>{t('Move')}</Button> : null}
        {one && can('rename') ? <Button size="sm" variant="ghost" icon="rename" class="flr-hide-sm" disabled={!writable} onClick={() => rename(one)}>{t('Rename')}</Button> : null}
        {can('delete') ? <Button size="sm" variant="danger-ghost" icon="trash" disabled={!writable} onClick={() => remove(sel)}>{t('Delete')}</Button> : null}
        <IconButton icon="more-h" size="sm" label={t('More actions')} onClick={(e) => openMenu(e, itemsFor(sel), e.currentTarget)} />
      </div>
    </div>
  );
}

function StatusBar() {
  const list = entries.value;
  const sel = selectedEntries.value;
  const dirs = list.filter((e) => e.type === 'dir').length;
  const scope = sel.length ? sel : list;
  const size = scope.reduce((s, e) => s + (e.size || 0), 0);
  const sizeText = scope.some((e) => e.type === 'file') ? ' · ' + formatSize(size) : '';
  const clip = clipboard.value;
  return (
    <footer class="flr-status">
      <span>
        {sel.length
          ? tn('%d selected', '%d selected', sel.length) + sizeText
          : tn('%d folder', '%d folders', dirs) + ', ' + tn('%d file', '%d files', list.length - dirs) + sizeText}
        {listing.value && listing.value.truncated ? ' · ' + t('list truncated') : ''}
      </span>
      <span class="flr-status-right">
        {clip ? (
          <span class="flr-clip">
            <Icon name={clip.mode === 'move' ? 'cut' : 'copy'} size={14} />
            {tn('%d item in clipboard', '%d items in clipboard', clip.paths.length)}
            {can(clip.mode === 'move' ? 'move' : 'copy') ? <button type="button" class="flr-link" onClick={() => paste()}>{t('Paste here')}</button> : null}
            <button type="button" class="flr-link flr-muted" onClick={() => { clipboard.value = null; }}>{t('Clear')}</button>
          </span>
        ) : null}
        {profile.readOnly ? <Badge tone="warning" icon="lock">{t('Read-only')}</Badge> : null}
        <button type="button" class="flr-status-kbd" onClick={() => { paletteOpen.value = true; }}><Kbd>{modKey}</Kbd><Kbd>K</Kbd></button>
      </span>
    </footer>
  );
}

/** Keyboard shortcuts for the browser. */
function useShortcuts() {
  useEffect(() => {
    const onKey = (e) => {
      if (route.value.view !== 'files' || dialogs.value.length || menu.value || paletteOpen.value) return;
      const target = e.target;
      const typing = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable);
      if (typing) return;
      const mod = e.ctrlKey || e.metaKey;
      const sel = selectedEntries.value;
      const key = e.key;
      const grid = prefs.value.view === 'grid';
      const focused = entries.value.find((x) => x.path === focusPath.value) || (sel.length === 1 ? sel[0] : null);
      let handled = true;
      if (key === 'ArrowDown') moveFocus(grid ? gridColumns() : 1, e.shiftKey);
      else if (key === 'ArrowUp') moveFocus(grid ? -gridColumns() : -1, e.shiftKey);
      else if (key === 'ArrowRight' && grid) moveFocus(1, e.shiftKey);
      else if (key === 'ArrowLeft' && grid) moveFocus(-1, e.shiftKey);
      else if (key === 'Home') moveFocus(-1e9, e.shiftKey);
      else if (key === 'End') moveFocus(1e9, e.shiftKey);
      else if (key === 'Enter' && focused) openEntry(focused);
      else if (key === ' ' && focused && focused.type === 'file') preview(focused);
      else if ((key === 'Backspace' || (e.altKey && key === 'ArrowUp'))) goUp();
      else if (key === 'F2' && focused && can('rename')) rename(focused);
      else if (key === 'Delete' && sel.length && can('delete')) remove(sel, e.shiftKey);
      else if (mod && key.toLowerCase() === 'a') select(entries.value.map((x) => x.path));
      else if (mod && key.toLowerCase() === 'c' && sel.length && can('copy')) copy(sel, 'copy');
      else if (mod && key.toLowerCase() === 'x' && sel.length && can('move')) copy(sel, 'move');
      else if (mod && key.toLowerCase() === 'v' && clipboard.value) paste();
      else if (mod && key.toLowerCase() === 'd' && sel.length && can('copy')) duplicate(sel);
      else if (mod && key === '.') setPref('showHidden', !prefs.value.showHidden);
      else if (key === 'Escape') { clearSelection(); clearSearch(); }
      else if (!mod && key.toLowerCase() === 'i') toggleDetails();
      else if (!mod && key === 'N' && e.shiftKey && can('mkdir')) newFolder();
      else if (!mod && key.toLowerCase() === 'u' && can('upload')) uploadFiles();
      else if (!mod && key.toLowerCase() === 'r' && !e.shiftKey) refresh();
      else handled = false;
      if (handled) e.preventDefault();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);
}

export function FilesView() {
  const r = route.value;
  useEffect(() => {
    load(r.path);
  }, [r.path]);
  useEffect(() => onUploaded((dest) => {
    if (dest === cwd.value) {
      clearTimeout(window.__flrRefresh);
      window.__flrRefresh = setTimeout(() => refresh(), 400);
    }
  }), []);
  useShortcuts();
  const s = search.value;
  return (
    <div class={'flr-files' + (showDetails.value ? ' has-details' : '')}>
      <header class="flr-topbar">
        <IconButton icon="menu" label={t('Menu')} class="flr-menu-btn" onClick={() => { drawerOpen.value = true; }} />
        <IconButton icon="arrow-up" label={t('Parent folder')} size="sm" class="flr-up-btn" disabled={cwd.value === '/'} onClick={goUp} />
        <Breadcrumbs />
        <div class="flr-topbar-actions">
          <SearchBox />
          <div class="flr-seg flr-seg--sm flr-hide-sm" role="radiogroup" aria-label={t('View')}>
            <button type="button" role="radio" aria-checked={String(prefs.value.view !== 'grid')} class={prefs.value.view !== 'grid' ? 'is-active' : ''} title={t('List')} onClick={() => setPref('view', 'list')}><Icon name="list" size={16} /></button>
            <button type="button" role="radio" aria-checked={String(prefs.value.view === 'grid')} class={prefs.value.view === 'grid' ? 'is-active' : ''} title={t('Grid')} onClick={() => setPref('view', 'grid')}><Icon name="grid" size={16} /></button>
          </div>
          <IconButton icon="sort" label={t('View options')} onClick={ViewMenu} />
          <IconButton icon="panel" label={t('Details panel')} active={!!showDetails.value} class="flr-hide-sm" onClick={() => toggleDetails()} />
          {can('mkdir') || can('mkfile') || can('upload') ? (
            <Button variant="primary" icon="plus" iconRight="chevron-down" onClick={NewMenu}><span class="flr-hide-sm">{t('New')}</span></Button>
          ) : null}
        </div>
      </header>
      {s ? (
        <div class="flr-searchbar">
          <Icon name="search" size={16} />
          <span>
            {s.loading ? t('Searching…') : tn('%d result', '%d results', s.results.length)}
            {' · '}{s.content ? t('in file contents') : t('in names')}{' · '}{cwd.value}
            {s.partial ? ' · ' + t('partial results') : ''}
          </span>
          <button type="button" class="flr-link" onClick={() => runSearch(s.q, !s.content)}>{s.content ? t('Search names instead') : t('Search inside files instead')}</button>
          <button type="button" class="flr-link" onClick={clearSearch}>{t('Close')}</button>
        </div>
      ) : null}
      <SelectionBar />
      <div class="flr-files-body">
        <Browser />
        {showDetails.value ? <DetailsPanel /> : null}
      </div>
      <StatusBar />
    </div>
  );
}
