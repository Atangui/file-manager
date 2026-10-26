// Sidebar: sections, favorites, lazy folder tree, storage meter, user & theme.
import { useEffect, useState } from 'preact/hooks';
import { Icon } from './Icon';
import { IconButton } from './ui';
import { C, can, isAdmin, profile } from '../lib/config';
import { t, formatSize } from '../lib/i18n';
import { route, hrefFor, prefs, setPref, tree, treeOpen, loadTree, cwd, drawerOpen, navigate } from '../lib/store';
import { baseName } from '../lib/files';
import { transfer, toggleFavorite } from '../lib/actions';
import { enqueue, filesFromDrop, hasFiles } from '../lib/upload';
import { openMenu } from '../lib/ui';

const DRAG_TYPE = 'application/x-filora-paths';

function dropHandlers(path, setOver) {
  const internal = (e) => Array.from(e.dataTransfer.types || []).includes(DRAG_TYPE);
  return {
    onDragOver: (e) => {
      if (internal(e) || (hasFiles(e) && can('upload'))) {
        e.preventDefault();
        setOver(true);
      }
    },
    onDragLeave: () => setOver(false),
    onDrop: async (e) => {
      e.preventDefault();
      e.stopPropagation();
      setOver(false);
      if (internal(e)) transfer(JSON.parse(e.dataTransfer.getData(DRAG_TYPE) || '[]'), path, e.altKey || e.ctrlKey ? 'copy' : 'move');
      else if (hasFiles(e)) enqueue(await filesFromDrop(e.dataTransfer), path);
    },
  };
}

function TreeNode({ node, depth }) {
  const [over, setOver] = useState(false);
  const open = treeOpen.value.has(node.path);
  const children = tree.value[node.path];
  const active = route.value.view === 'files' && cwd.value === node.path;
  const toggle = (e) => {
    e.preventDefault();
    e.stopPropagation();
    const next = new Set(treeOpen.value);
    if (open) next.delete(node.path);
    else {
      next.add(node.path);
      if (!children) loadTree(node.path);
    }
    treeOpen.value = next;
  };
  return (
    <li>
      <a
        href={hrefFor('files', node.path)}
        class={'flr-tree-item' + (active ? ' is-active' : '') + (over ? ' is-drop' : '')}
        style={{ paddingInlineStart: 8 + depth * 14 + 'px' }}
        onClick={() => { drawerOpen.value = false; }}
        {...dropHandlers(node.path, setOver)}
      >
        {node.hasChildren ? (
          <button type="button" class={'flr-tree-toggle' + (open ? ' is-open' : '')} onClick={toggle} aria-label={open ? t('Collapse') : t('Expand')}>
            <Icon name="chevron-right" size={14} />
          </button>
        ) : <span class="flr-tree-toggle is-leaf" />}
        <Icon name="folder" size={16} class="flr-tree-icon" />
        <span class="flr-tree-label">{node.name}</span>
      </a>
      {open && children && children.length ? (
        <ul>
          {children.map((c) => <TreeNode node={c} depth={depth + 1} key={c.path} />)}
        </ul>
      ) : null}
    </li>
  );
}

function NavItem({ view, icon, label, badge }) {
  const active = route.value.view === view;
  return (
    <a href={hrefFor(view)} class={'flr-nav-item' + (active ? ' is-active' : '')} aria-current={active ? 'page' : undefined} onClick={() => { drawerOpen.value = false; }}>
      <Icon name={icon} size={18} />
      <span>{label}</span>
      {badge ? <span class="flr-nav-badge">{badge}</span> : null}
    </a>
  );
}

/** Shortcut to a folder (favorites, recent folders, Vault); also a drop target. */
function Shortcut({ path, icon, label, title, menu, iconClass = '' }) {
  const [over, setOver] = useState(false);
  return (
    <a
      href={hrefFor('files', path)}
      class={'flr-tree-item flr-shortcut' + (cwd.value === path && route.value.view === 'files' ? ' is-active' : '') + (over ? ' is-drop' : '')}
      onClick={() => { drawerOpen.value = false; }}
      onContextMenu={menu ? (e) => openMenu(e, menu) : undefined}
      title={title || path}
      {...dropHandlers(path, setOver)}
    >
      <Icon name={icon} size={16} class={'flr-tree-icon ' + iconClass} filled={icon === 'star'} />
      <span class="flr-tree-label">{label || (path === '/' ? profile.rootName : baseName(path))}</span>
    </a>
  );
}

const THEMES = () => [
  { value: 'light', icon: 'sun', label: t('Light') },
  { value: 'dark', icon: 'moon', label: t('Dark') },
  { value: 'auto', icon: 'monitor', label: t('System') },
];

function ThemeSwitch() {
  const theme = prefs.value.theme || 'auto';
  return (
    <div class="flr-theme-switch" role="radiogroup" aria-label={t('Theme')}>
      {THEMES().map((o) => (
        <button type="button" role="radio" key={o.value} aria-checked={String(theme === o.value)} class={theme === o.value ? 'is-active' : ''} title={t('Theme: %s', o.label)} onClick={() => setPref('theme', o.value)}>
          <Icon name={o.icon} size={15} />
        </button>
      ))}
    </div>
  );
}

export function Sidebar() {
  useEffect(() => {
    if (!tree.value['/']) loadTree('/');
  }, []);
  const favorites = prefs.value.favorites || [];
  const recent = (prefs.value.recent || []).filter((p) => p !== '/' && !favorites.includes(p)).slice(0, 5);
  const rootChildren = tree.value['/'] || [];
  return (
    <aside class={'flr-sidebar' + (drawerOpen.value ? ' is-open' : '')}>
      <div class="flr-brand">
        <span class="flr-logo" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M3 7.5A2.5 2.5 0 0 1 5.5 5h3.6a2 2 0 0 1 1.4.6l1.3 1.3a2 2 0 0 0 1.4.6h5.3A2.5 2.5 0 0 1 21 10v7.5a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5z" opacity=".35" /><path fill="currentColor" d="M3 10.5A2.5 2.5 0 0 1 5.5 8h13a2.5 2.5 0 0 1 2.5 2.5v7a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5z" /></svg>
        </span>
        <span class="flr-brand-name">Filora</span>
        <IconButton icon="x" label={t('Close menu')} class="flr-drawer-close" onClick={() => { drawerOpen.value = false; }} />
      </div>

      <nav class="flr-nav" aria-label={t('Sections')}>
        <NavItem view="files" icon="folder" label={t('Files')} />
        {can('share') ? <NavItem view="shares" icon="link" label={t('Shared links')} /> : null}
        {C.trash && C.trash.enabled && can('delete') ? <NavItem view="trash" icon="trash" label={t('Trash')} /> : null}
        <NavItem view="activity" icon="activity" label={t('Activity')} />
        {isAdmin ? <NavItem view="tools" icon="shield-check" label={t('Security tools')} /> : null}
        {C.user && C.user.canSettings ? <NavItem view="settings" icon="settings" label={t('Settings')} /> : null}
      </nav>

      <div class="flr-side-scroll">
        <div class="flr-side-group">
          <h4>{t('Quick access')}</h4>
          {profile.vault ? (
            <Shortcut path={profile.vault} icon="lock" iconClass="is-vault" label={t('Vault (private)')} title={t('Private folder: its files can only be reached through your share links, never by a direct web address.')} />
          ) : null}
          {favorites.map((p) => <Shortcut path={p} key={p} icon="star" iconClass="is-star" menu={[{ label: t('Remove from favorites'), icon: 'star', run: () => toggleFavorite(p) }]} />)}
          {!favorites.length ? <p class="flr-side-hint">{t('Right-click a folder, then “Add to favorites” to pin it here.')}</p> : null}
        </div>
        {recent.length ? (
          <div class="flr-side-group">
            <h4>{t('Recent')}</h4>
            {recent.map((p) => <Shortcut path={p} key={p} icon="clock" />)}
          </div>
        ) : null}
        <div class="flr-side-group">
          <h4>
            <a href={hrefFor('files', '/')} onClick={() => navigate('files', '/')}>{profile.rootName || t('Folders')}</a>
          </h4>
          <ul class="flr-tree">
            {rootChildren.map((node) => <TreeNode node={node} depth={0} key={node.path} />)}
          </ul>
        </div>
      </div>

      <div class="flr-side-foot">
        {profile.readOnly ? (
          <div class="flr-readonly" title={profile.readOnlyWhy}><Icon name="lock" size={14} /> {t('Read-only')}</div>
        ) : null}
        {profile.quota ? <QuotaMeter /> : null}
        <div class="flr-user">
          <Avatar />
          <span class="flr-user-name">{C.user ? C.user.name : ''}</span>
          <ThemeSwitch />
        </div>
      </div>
    </aside>
  );
}

function Avatar() {
  const [failed, setFailed] = useState(false);
  const name = (C.user && C.user.name) || '?';
  if (!C.user || !C.user.avatar || failed) {
    return <span class="flr-avatar" aria-hidden="true">{name.trim().charAt(0).toUpperCase()}</span>;
  }
  return <img src={C.user.avatar} alt="" width="28" height="28" onError={() => setFailed(true)} />;
}

function QuotaMeter() {
  const [usage, setUsage] = useState(null);
  useEffect(() => {
    import('../lib/api').then(({ get }) => get('tools/usage', { path: '/' }).then((d) => setUsage(d)).catch(() => {}));
  }, []);
  if (!usage || !usage.quota) return null;
  const pct = Math.min(100, Math.round((usage.used / usage.quota) * 100));
  return (
    <div class={'flr-quota' + (pct > 90 ? ' is-full' : '')}>
      <div class="flr-quota-bar"><i style={{ width: pct + '%' }} /></div>
      <span>{t('%1$s of %2$s used', formatSize(usage.used), formatSize(usage.quota))}</span>
    </div>
  );
}
