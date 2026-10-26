// Breadcrumbs: every segment is a link and a drop target (move/upload into a parent folder).
import { Fragment } from 'preact';
import { useState } from 'preact/hooks';
import { Icon } from './Icon';
import { cwd, hrefFor } from '../lib/store';
import { profile, can } from '../lib/config';
import { openMenu } from '../lib/ui';
import { navigate } from '../lib/store';
import { transfer } from '../lib/actions';
import { enqueue, filesFromDrop, hasFiles } from '../lib/upload';
import { t } from '../lib/i18n';

const DRAG_TYPE = 'application/x-filora-paths';

function Crumb({ path, label, current, icon }) {
  const [over, setOver] = useState(false);
  const internal = (e) => Array.from(e.dataTransfer.types || []).includes(DRAG_TYPE);
  return (
    <a
      href={hrefFor('files', path)}
      class={'flr-crumb' + (current ? ' is-current' : '') + (over ? ' is-drop' : '')}
      aria-current={current ? 'page' : undefined}
      onDragOver={(e) => {
        if (current) return;
        if (internal(e) || (hasFiles(e) && can('upload'))) {
          e.preventDefault();
          setOver(true);
        }
      }}
      onDragLeave={() => setOver(false)}
      onDrop={async (e) => {
        e.preventDefault();
        setOver(false);
        if (internal(e)) transfer(JSON.parse(e.dataTransfer.getData(DRAG_TYPE) || '[]'), path, e.altKey || e.ctrlKey ? 'copy' : 'move');
        else if (hasFiles(e)) enqueue(await filesFromDrop(e.dataTransfer), path);
      }}
    >
      {icon ? <Icon name={icon} size={16} /> : null}
      <span>{label}</span>
    </a>
  );
}

export function Breadcrumbs() {
  const parts = cwd.value.split('/').filter(Boolean);
  const crumbs = [{ path: '/', label: profile.rootName || t('Root'), icon: 'home' }];
  let acc = '';
  for (const p of parts) {
    acc += '/' + p;
    crumbs.push({ path: acc, label: p });
  }
  // Collapse the middle of long paths into a menu.
  let shown = crumbs;
  let hidden = [];
  if (crumbs.length > 4) {
    shown = [crumbs[0], null, ...crumbs.slice(-2)];
    hidden = crumbs.slice(1, -2);
  }
  return (
    <nav class="flr-crumbs" aria-label={t('Breadcrumb')}>
      {shown.map((c, i) => (
        <Fragment key={c ? c.path : 'more'}>
          {i > 0 ? <Icon name="chevron-right" size={14} class="flr-crumb-sep" /> : null}
          {c ? (
            <Crumb path={c.path} label={c.label} icon={c.icon} current={i === shown.length - 1} />
          ) : (
            <button
              type="button"
              class="flr-crumb flr-crumb--more"
              aria-label={t('Show full path')}
              onClick={(e) => openMenu(e, hidden.map((h) => ({ label: h.label, icon: 'folder', run: () => navigate('files', h.path) })), e.currentTarget)}
            >
              …
            </button>
          )}
        </Fragment>
      ))}
    </nav>
  );
}
