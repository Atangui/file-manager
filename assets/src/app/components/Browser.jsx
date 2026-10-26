// Virtualized list / grid of entries with selection, keyboard, context menu and drag & drop.
import { useEffect, useRef, useState, useCallback } from 'preact/hooks';
import { Icon } from './Icon';
import { FileIcon } from './FileIcon';
import { Spinner, Empty, Button } from './ui';
import { t, formatSize, relative, formatDate, tn } from '../lib/i18n';
import { can, isAdmin } from '../lib/config';
import {
  entries, selection, anchor, focusPath, prefs, setPref, select, toggleSelect, selectRange, clearSelection,
  loading, loadError, listing, cwd, search, refresh, clipboard,
} from '../lib/store';
import { openMenu } from '../lib/ui';
import { itemsFor, itemsForFolder, openEntry, transfer, newFolder, uploadFiles } from '../lib/actions';
import { enqueue, filesFromDrop, hasFiles } from '../lib/upload';
import { parentPath } from '../lib/files';
import { query } from '../lib/root';

const DRAG_TYPE = 'application/x-filora-paths';

/** Track scroll position and size of a scroll container. */
function useViewport(ref) {
  const [vp, setVp] = useState({ top: 0, height: 600, width: 800 });
  useEffect(() => {
    const el = ref.current;
    if (!el) return undefined;
    let frame = 0;
    const measure = () => {
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(() => setVp({ top: el.scrollTop, height: el.clientHeight, width: el.clientWidth }));
    };
    measure();
    el.addEventListener('scroll', measure, { passive: true });
    const ro = new ResizeObserver(measure);
    ro.observe(el);
    return () => {
      el.removeEventListener('scroll', measure);
      ro.disconnect();
      cancelAnimationFrame(frame);
    };
  }, []);
  return vp;
}

let dragGhost = null;
function setDragImage(e, count, name) {
  if (!dragGhost) {
    dragGhost = document.createElement('div');
    dragGhost.className = 'flr-drag-ghost';
    document.body.append(dragGhost);
  }
  dragGhost.textContent = count > 1 ? tn('%d item', '%d items', count) : name;
  e.dataTransfer.setDragImage(dragGhost, -12, -8);
}

export function Browser() {
  const ref = useRef(null);
  const vp = useViewport(ref);
  const [dropTarget, setDropTarget] = useState(null);
  const [overlay, setOverlay] = useState(false);
  const dragDepth = useRef(0);

  const list = entries.value;
  const view = prefs.value.view === 'grid' ? 'grid' : 'list';
  const compact = prefs.value.density === 'compact';
  const searching = !!search.value;
  const sel = selection.value;
  const showPerms = isAdmin || can('chmod');

  // Layout maths.
  const rowH = compact ? 34 : 44;
  const minCard = compact ? 132 : 164;
  const cols = view === 'grid' ? Math.max(1, Math.floor((vp.width - 24) / minCard)) : 1;
  const cardH = compact ? 150 : 184;
  const itemH = view === 'grid' ? cardH : rowH;
  const rows = Math.ceil(list.length / cols);
  const headerH = view === 'list' ? 36 : 0;
  const pad = view === 'grid' ? 12 : 0;
  const overscan = 6;
  const first = Math.max(0, Math.floor((vp.top - headerH - pad) / itemH) - overscan);
  const last = Math.min(rows, Math.ceil((vp.top + vp.height) / itemH) + overscan);
  const visible = list.slice(first * cols, last * cols);

  // Keep the focused item in view.
  useEffect(() => {
    const el = ref.current;
    const path = focusPath.value;
    if (!el || !path) return;
    const index = list.findIndex((e) => e.path === path);
    if (index === -1) return;
    const row = Math.floor(index / cols);
    const top = headerH + pad + row * itemH;
    if (top < el.scrollTop + headerH) el.scrollTop = top - headerH;
    else if (top + itemH > el.scrollTop + el.clientHeight) el.scrollTop = top + itemH - el.clientHeight;
  }, [focusPath.value, cols, itemH]);

  // Reset scroll when changing folder.
  useEffect(() => {
    if (ref.current) ref.current.scrollTop = 0;
  }, [cwd.value, searching]);

  const onItemClick = (e, entry) => {
    e.stopPropagation();
    if (e.shiftKey) {
      selectRange(entry.path);
    } else if (e.metaKey || e.ctrlKey) {
      toggleSelect(entry.path);
      anchor.value = entry.path;
    } else {
      select([entry.path]);
      anchor.value = entry.path;
    }
    focusPath.value = entry.path;
  };

  const onItemContext = (e, entry) => {
    if (!sel.has(entry.path)) {
      select([entry.path]);
      anchor.value = entry.path;
    }
    focusPath.value = entry.path;
    const chosen = selection.value.has(entry.path) ? list.filter((x) => selection.value.has(x.path)) : [entry];
    openMenu(e, itemsFor(chosen));
  };

  const onCheck = (e, entry) => {
    e.stopPropagation();
    if (e.shiftKey) selectRange(entry.path);
    else toggleSelect(entry.path);
    anchor.value = entry.path;
  };

  /* Drag & drop ------------------------------------------------------------ */
  const onDragStart = (e, entry) => {
    if (!can('move') && !can('copy')) {
      e.preventDefault();
      return;
    }
    let paths = sel.has(entry.path) ? [...sel] : [entry.path];
    if (!sel.has(entry.path)) select([entry.path]);
    e.dataTransfer.effectAllowed = 'copyMove';
    e.dataTransfer.setData(DRAG_TYPE, JSON.stringify(paths));
    e.dataTransfer.setData('text/plain', paths.join('\n'));
    setDragImage(e, paths.length, entry.name);
  };
  const isInternal = (e) => Array.from(e.dataTransfer.types || []).includes(DRAG_TYPE);

  const onFolderDragOver = (e, entry) => {
    if (entry.type !== 'dir') return;
    const internal = isInternal(e);
    if (!internal && !(hasFiles(e) && can('upload'))) return;
    if (internal && sel.has(entry.path)) return;
    e.preventDefault();
    e.stopPropagation();
    e.dataTransfer.dropEffect = internal && (e.altKey || e.ctrlKey || e.metaKey) ? 'copy' : internal ? 'move' : 'copy';
    if (dropTarget !== entry.path) setDropTarget(entry.path);
  };
  const onFolderDrop = async (e, entry) => {
    if (entry.type !== 'dir') return;
    e.preventDefault();
    e.stopPropagation();
    setDropTarget(null);
    setOverlay(false);
    dragDepth.current = 0;
    if (isInternal(e)) {
      const paths = JSON.parse(e.dataTransfer.getData(DRAG_TYPE) || '[]');
      transfer(paths, entry.path, e.altKey || e.ctrlKey || e.metaKey ? 'copy' : 'move');
    } else if (hasFiles(e) && can('upload')) {
      enqueue(await filesFromDrop(e.dataTransfer), entry.path);
    }
  };

  // Desktop files dropped on the empty area → upload to the current folder.
  const onAreaDragEnter = (e) => {
    if (!hasFiles(e) || !can('upload') || searching) return;
    dragDepth.current++;
    setOverlay(true);
  };
  const onAreaDragLeave = (e) => {
    if (!hasFiles(e)) return;
    dragDepth.current = Math.max(0, dragDepth.current - 1);
    if (!dragDepth.current) setOverlay(false);
  };
  const onAreaDragOver = (e) => {
    if ((hasFiles(e) && can('upload') && !searching) || isInternal(e)) {
      e.preventDefault();
      if (dropTarget) setDropTarget(null);
    }
  };
  const onAreaDrop = async (e) => {
    e.preventDefault();
    setOverlay(false);
    dragDepth.current = 0;
    if (hasFiles(e) && can('upload') && !searching) enqueue(await filesFromDrop(e.dataTransfer), cwd.value);
  };

  const sortBy = (key) => {
    const p = prefs.value;
    if (p.sort === key) setPref('dir', p.dir === 'asc' ? 'desc' : 'asc');
    else {
      setPref('sort', key);
      setPref('dir', key === 'name' || key === 'type' ? 'asc' : 'desc');
    }
  };

  const itemProps = (entry) => ({
    key: entry.path,
    'data-path': entry.path,
    'aria-selected': String(sel.has(entry.path)),
    class: [
      sel.has(entry.path) ? 'is-selected' : '',
      focusPath.value === entry.path ? 'is-focused' : '',
      dropTarget === entry.path ? 'is-drop' : '',
      clipboard.value && clipboard.value.mode === 'move' && clipboard.value.paths.includes(entry.path) ? 'is-cut' : '',
    ].filter(Boolean).join(' '),
    draggable: true,
    onClick: (e) => onItemClick(e, entry),
    onDblClick: () => openEntry(entry),
    onContextMenu: (e) => onItemContext(e, entry),
    onDragStart: (e) => onDragStart(e, entry),
    onDragOver: (e) => onFolderDragOver(e, entry),
    onDragLeave: () => dropTarget === entry.path && setDropTarget(null),
    onDrop: (e) => onFolderDrop(e, entry),
  });

  const badges = (entry) => (
    <>
      {entry.shared ? <span class="flr-mini-badge is-shared" title={t('Shared with a link')}><Icon name="link" size={12} /></span> : null}
      {entry.locked ? <span class="flr-mini-badge" title={t('Locked (read-only)')}><Icon name="lock" size={12} /></span> : null}
      {entry.sensitive ? <span class="flr-mini-badge is-sensitive" title={t('Sensitive file: hidden from non-administrators and never shareable')}><Icon name="key" size={12} /></span> : null}
      {entry.system ? <span class="flr-mini-badge" title={t('WordPress system item (protected)')}><Icon name="shield" size={12} /></span> : null}
      {entry.link ? <span class="flr-mini-badge" title={t('Symbolic link')}><Icon name="external" size={12} /></span> : null}
    </>
  );

  /* Render states ------------------------------------------------------------ */
  let body;
  if (loadError.value && !listing.value) {
    body = (
      <Empty icon="alert" title={t('This folder cannot be opened')} action={<Button icon="refresh" onClick={() => refresh()}>{t('Retry')}</Button>}>
        {loadError.value.message}
      </Empty>
    );
  } else if (!listing.value || (search.value && search.value.loading)) {
    body = <div class="flr-center-fill"><Spinner size={26} /></div>;
  } else if (!list.length) {
    body = searching ? (
      <Empty icon="search" title={t('No results')}>{t('Try another name, or search inside file contents.')}</Empty>
    ) : (
      <Empty
        icon="cloud-upload"
        title={t('This folder is empty')}
        action={can('upload') || can('mkdir') ? (
          <div class="flr-row">
            {can('upload') ? <Button variant="primary" icon="upload" onClick={uploadFiles}>{t('Upload files')}</Button> : null}
            {can('mkdir') ? <Button icon="folder-plus" onClick={newFolder}>{t('New folder')}</Button> : null}
          </div>
        ) : null}
      >
        {can('upload') ? t('Drag and drop files or folders here to upload them.') : null}
      </Empty>
    );
  } else if (view === 'list') {
    const sortKey = prefs.value.sort || 'name';
    const sortDir = prefs.value.dir || 'asc';
    const th = (key, label, cls) => (
      <button type="button" class={'flr-th ' + cls + (sortKey === key ? ' is-sorted' : '')} onClick={() => sortBy(key)}>
        <span>{label}</span>
        {sortKey === key ? <Icon name={sortDir === 'asc' ? 'chevron-up' : 'chevron-down'} size={14} /> : null}
      </button>
    );
    const allSelected = sel.size > 0 && sel.size === list.length;
    body = (
      <div class={'flr-list' + (showPerms ? ' has-perms' : '') + (searching ? ' has-loc' : '')} role="grid" aria-multiselectable="true" style={{ height: headerH + rows * rowH + 'px' }}>
        <div class="flr-list-head" role="row">
          <span class="flr-col-check">
            <input type="checkbox" aria-label={t('Select all')} checked={allSelected} indeterminate={sel.size > 0 && !allSelected}
              onChange={() => (allSelected ? clearSelection() : select(list.map((e) => e.path)))} onClick={(e) => e.stopPropagation()} />
          </span>
          {th('name', t('Name'), 'flr-col-name')}
          {searching ? <span class="flr-th flr-col-loc">{t('Location')}</span> : null}
          {th('size', t('Size'), 'flr-col-size')}
          {th('mtime', t('Modified'), 'flr-col-date')}
          {showPerms ? <span class="flr-th flr-col-perms">{t('Permissions')}</span> : null}
          <span class="flr-col-more" />
        </div>
        {visible.map((entry, i) => { const props = itemProps(entry); return (
          <div role="row" {...props} class={'flr-row-item ' + props.class} style={{ transform: `translateY(${headerH + (first + i) * rowH}px)`, height: rowH + 'px' }}>
            <span class="flr-col-check" onClick={(e) => onCheck(e, entry)} onDblClick={(e) => e.stopPropagation()}>
              <input type="checkbox" tabIndex={-1} checked={sel.has(entry.path)} aria-label={entry.name} onClick={(e) => e.preventDefault()} />
            </span>
            <span class="flr-col-name">
              <FileIcon entry={entry} size="sm" thumb={!compact} />
              <span class="flr-name" title={entry.name}>{entry.name}</span>
              {badges(entry)}
              {entry.matches ? <span class="flr-match">{entry.matches.length ? 'L' + entry.matches[0].line + ': ' + entry.matches[0].text : ''}</span> : null}
            </span>
            {searching ? <span class="flr-col-loc" title={parentPath(entry.path)}>{parentPath(entry.path)}</span> : null}
            <span class="flr-col-size">{entry.type === 'dir' ? '—' : formatSize(entry.size)}</span>
            <span class="flr-col-date" title={formatDate(entry.mtime)}>{relative(entry.mtime)}</span>
            {showPerms ? <span class="flr-col-perms"><code>{entry.perms}</code></span> : null}
            <span class="flr-col-more">
              <button type="button" class="flr-row-more" aria-label={t('Actions')} onClick={(e) => { e.stopPropagation(); onItemContext(e, entry); }}>
                <Icon name="more-h" size={18} />
              </button>
            </span>
          </div>
        ); })}
      </div>
    );
  } else {
    body = (
      <div class="flr-grid" role="grid" aria-multiselectable="true" style={{ height: pad * 2 + rows * cardH + 'px', '--cols': cols }}>
        {visible.map((entry, i) => {
          const idx = first * cols + i;
          const row = Math.floor(idx / cols);
          const col = idx % cols;
          const props = itemProps(entry);
          return (
            <div role="gridcell" {...props} class={'flr-card-item ' + props.class}
              style={{ transform: `translate(${col * 100}%, ${pad + row * cardH}px)`, height: cardH + 'px' }}>
              <div class="flr-card-inner">
                <span class="flr-card-check" onClick={(e) => onCheck(e, entry)} onDblClick={(e) => e.stopPropagation()}>
                  <Icon name="check" size={14} />
                </span>
                <span class="flr-card-visual"><FileIcon entry={entry} size="lg" thumb /></span>
                <span class="flr-card-name" title={entry.name}>{entry.name}</span>
                <span class="flr-card-meta">
                  {badges(entry)}
                  <span>{entry.type === 'dir' ? relative(entry.mtime) : formatSize(entry.size)}</span>
                </span>
              </div>
            </div>
          );
        })}
      </div>
    );
  }

  return (
    <div
      class={'flr-browser flr-browser--' + view + (overlay ? ' is-dropping' : '')}
      ref={ref}
      tabIndex={0}
      onClick={(e) => { if (e.target === e.currentTarget || e.target.classList.contains('flr-list') || e.target.classList.contains('flr-grid')) clearSelection(); }}
      onContextMenu={(e) => { if (!e.target.closest('[data-path]')) openMenu(e, itemsForFolder()); }}
      onDragEnter={onAreaDragEnter}
      onDragLeave={onAreaDragLeave}
      onDragOver={onAreaDragOver}
      onDrop={onAreaDrop}
      data-browser
    >
      {body}
      {loading.value && listing.value ? <div class="flr-loading-bar" /> : null}
      {overlay ? (
        <div class="flr-drop-overlay">
          <div><Icon name="cloud-upload" size={34} /><strong>{t('Drop to upload')}</strong><span>{t('into %s', cwd.value)}</span></div>
        </div>
      ) : null}
    </div>
  );
}

/** Keyboard navigation inside the browser (arrow keys move the focus, Shift extends). */
export function moveFocus(delta, extend) {
  const list = entries.value;
  if (!list.length) return;
  const idx = list.findIndex((e) => e.path === focusPath.value);
  const next = Math.max(0, Math.min(list.length - 1, idx === -1 ? 0 : idx + delta));
  const path = list[next].path;
  focusPath.value = path;
  if (extend) selectRange(path);
  else {
    select([path]);
    anchor.value = path;
  }
}

/** Columns currently displayed in grid view (for up/down arrows). */
export function gridColumns() {
  const el = query('[data-browser]');
  const grid = el && el.querySelector('.flr-grid');
  if (!grid) return 1;
  return Number(getComputedStyle(grid).getPropertyValue('--cols')) || 1;
}
