// Choose a destination folder (move to / copy to).
import { useEffect, useState } from 'preact/hooks';
import { Dialog } from '../components/Overlays';
import { Icon } from '../components/Icon';
import { Button, Spinner } from '../components/ui';
import { t } from '../lib/i18n';
import { get } from '../lib/api';
import { profile } from '../lib/config';
import { parentPath, baseName } from '../lib/files';

export function FolderPicker({ close, title, confirmLabel, start = '/' }) {
  const [path, setPath] = useState(start);
  const [dirs, setDirs] = useState(null);
  useEffect(() => {
    setDirs(null);
    get('fs/tree', { path }).then(setDirs).catch(() => setDirs([]));
  }, [path]);
  const parts = path.split('/').filter(Boolean);
  return (
    <Dialog title={title} icon="folder" size="md" onClose={() => close(null)}
      footer={<><span class="flr-muted flr-ellipsis">{path}</span><span class="flr-grow" /><Button variant="ghost" onClick={() => close(null)}>{t('Cancel')}</Button><Button variant="primary" onClick={() => close(path)}>{confirmLabel}</Button></>}>
      <div class="flr-picker">
        <div class="flr-picker-crumbs">
          <button type="button" class="flr-crumb" onClick={() => setPath('/')}><Icon name="home" size={15} /> {profile.rootName}</button>
          {parts.map((p, i) => (
            <span key={i}><Icon name="chevron-right" size={13} class="flr-crumb-sep" /><button type="button" class="flr-crumb" onClick={() => setPath('/' + parts.slice(0, i + 1).join('/'))}>{p}</button></span>
          ))}
        </div>
        <ul class="flr-picker-list">
          {path !== '/' ? (
            <li><button type="button" onDblClick={() => setPath(parentPath(path))} onClick={() => setPath(parentPath(path))}><Icon name="arrow-up" size={17} /> <span>..</span></button></li>
          ) : null}
          {dirs === null ? <li class="flr-pad"><Spinner /></li> : dirs.length ? dirs.map((d) => (
            <li key={d.path}>
              <button type="button" onClick={() => setPath(d.path)}>
                <Icon name="folder" size={17} class="flr-folder-ico" />
                <span>{d.name}</span>
                {d.hasChildren ? <Icon name="chevron-right" size={15} class="flr-muted" /> : null}
              </button>
            </li>
          )) : <li class="flr-muted flr-pad">{t('No sub-folders.')} {t('Click “%1$s” to choose “%2$s”.', confirmLabel, path === '/' ? profile.rootName : baseName(path))}</li>}
        </ul>
      </div>
    </Dialog>
  );
}
