// All share links: status, protections, usage, quick actions.
import { useEffect, useState } from 'preact/hooks';
import { Icon } from '../components/Icon';
import { Button, IconButton, Badge, Spinner, Empty, Segmented } from '../components/ui';
import { statusLabel } from '../components/DetailsPanel';
import { ViewHeader } from '../components/ViewHeader';
import { t, tn, relative, formatDate } from '../lib/i18n';
import { get, del, post } from '../lib/api';
import { isAdmin } from '../lib/config';
import { openDialog, openMenu, toast, toastError } from '../lib/ui';
import { copyText } from '../lib/files';
import { navigate } from '../lib/store';
import { confirm } from '../dialogs/common';

const MODE = () => ({ view: t('View only'), download: t('View & download'), upload: t('Collaborate'), dropbox: t('File request') });
const MODE_ICON = { view: 'eye', download: 'download', upload: 'upload', dropbox: 'inbox' };

export function SharesView() {
  const [list, setList] = useState(null);
  const [filter, setFilter] = useState('all');
  const [q, setQ] = useState('');

  const reload = () => get('shares').then(setList).catch((e) => { toastError(e); setList([]); });
  useEffect(() => { reload(); }, []);

  const edit = async (share) => {
    const { ShareDialog } = await import('../dialogs/Share');
    await openDialog(ShareDialog, { entry: { path: share.path || share.location, name: share.name, type: share.type }, shareId: share.id });
    reload();
  };
  const remove = async (share) => {
    if (!(await confirm({ title: t('Delete this link?'), message: t('Anyone using it will immediately lose access.'), confirmLabel: t('Delete'), danger: true }))) return;
    try {
      await del('shares/' + share.id);
      toast(t('Link deleted.'), { type: 'success' });
      reload();
    } catch (e) {
      toastError(e);
    }
  };
  const toggle = async (share) => {
    try {
      await post('shares/' + share.id, { status: share.status === 'disabled' ? 'active' : 'disabled' });
      reload();
    } catch (e) {
      toastError(e);
    }
  };

  const counts = { all: 0, active: 0, expired: 0, disabled: 0 };
  (list || []).forEach((s) => {
    counts.all++;
    if (s.status === 'active') counts.active++;
    else if (s.status === 'disabled') counts.disabled++;
    else counts.expired++;
  });
  const shown = (list || []).filter((s) => {
    if (filter === 'active' && s.status !== 'active') return false;
    if (filter === 'disabled' && s.status !== 'disabled') return false;
    if (filter === 'expired' && (s.status === 'active' || s.status === 'disabled')) return false;
    const hay = (s.label + ' ' + s.name + ' ' + (s.location || '') + ' ' + s.owner + ' ' + s.emails.join(' ')).toLowerCase();
    return !q || hay.includes(q.toLowerCase());
  });

  return (
    <div class="flr-view">
      <ViewHeader icon="link" title={t('Shared links')} subtitle={t('Every secure link created for files and folders. Links can be disabled or revoked at any time.')}>
        <label class="flr-search flr-search--sm">
          <Icon name="search" size={16} />
          <input type="search" value={q} onInput={(e) => setQ(e.currentTarget.value)} placeholder={t('Search links…')} />
        </label>
      </ViewHeader>
      <div class="flr-view-toolbar">
        <Segmented value={filter} onChange={setFilter} size="sm" options={[
          { value: 'all', label: t('All') + ' · ' + counts.all },
          { value: 'active', label: t('Active') + ' · ' + counts.active },
          { value: 'expired', label: t('Expired / inactive') + ' · ' + counts.expired },
          { value: 'disabled', label: t('Disabled') + ' · ' + counts.disabled },
        ]} />
      </div>
      <div class="flr-view-body">
        {!list ? <div class="flr-center-fill"><Spinner size={24} /></div> : !shown.length ? (
          <Empty icon="link" title={list.length ? t('No matching link') : t('No shared links yet')} action={list.length ? null : <Button variant="primary" icon="folder" onClick={() => navigate('files', '/')}>{t('Browse files')}</Button>}>
            {list.length ? null : t('Select a file or folder, then click “Share” to create a secure link for a client or a partner.')}
          </Empty>
        ) : (
          <div class="flr-table-wrap">
            <table class="flr-table">
              <thead>
                <tr>
                  <th>{t('Item')}</th>
                  <th>{t('Access')}</th>
                  <th class="flr-hide-md">{t('Expires')}</th>
                  <th class="flr-hide-md">{t('Usage')}</th>
                  {isAdmin ? <th class="flr-hide-lg">{t('Created by')}</th> : null}
                  <th aria-label={t('Actions')} />
                </tr>
              </thead>
              <tbody>
                {shown.map((s) => (
                  <tr key={s.id} class={s.status !== 'active' ? 'is-muted' : ''} onDblClick={() => edit(s)}>
                    <td>
                      <div class="flr-cell-item">
                        <span class={'flr-tile flr-tile--sm flr-tile--' + (s.type === 'dir' ? 'dir' : 'file')}><Icon name={s.type === 'dir' ? 'folder' : 'file'} size={15} /></span>
                        <div>
                          <strong>{s.label || s.name}</strong>
                          <span class="flr-muted flr-ellipsis" title={s.location}>{s.location || '—'}</span>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div class="flr-row flr-row--wrap">
                        <Badge tone={s.status === 'active' ? 'success' : s.status === 'disabled' ? 'neutral' : 'warning'}>{statusLabel(s.status)}</Badge>
                        <span class="flr-inline flr-muted"><Icon name={MODE_ICON[s.mode]} size={14} /> {MODE()[s.mode]}</span>
                        {s.hasPassword ? <span class="flr-inline" title={t('Password')}><Icon name="lock" size={14} /></span> : null}
                        {s.emails.length ? <span class="flr-inline" title={s.emails.join(', ')}><Icon name="mail" size={14} /></span> : null}
                        {s.ips.length ? <span class="flr-inline" title={s.ips.join(', ')}><Icon name="globe" size={14} /></span> : null}
                        {s.exposed ? <span title={t('Also reachable through a public URL')} class="flr-inline flr-warn-icon"><Icon name="alert" size={14} /></span> : null}
                      </div>
                    </td>
                    <td class="flr-hide-md flr-nowrap" title={s.expiresAt ? formatDate(s.expiresAt) : ''}>{s.expiresAt ? relative(s.expiresAt) : t('Never')}</td>
                    <td class="flr-hide-md flr-nowrap flr-muted">
                      <span class="flr-inline" title={t('Views')}><Icon name="eye" size={13} /> {s.views}</span>
                      <span class="flr-inline" title={t('Downloads')}><Icon name="download" size={13} /> {s.maxDownloads ? s.downloads + '/' + s.maxDownloads : s.downloads}</span>
                      {s.uploads ? <span class="flr-inline" title={t('Uploads')}><Icon name="upload" size={13} /> {s.uploads}</span> : null}
                    </td>
                    {isAdmin ? <td class="flr-hide-lg">{s.owner}</td> : null}
                    <td class="flr-cell-actions">
                      {s.url ? <IconButton icon="copy" size="sm" label={t('Copy link')} onClick={async () => { if (await copyText(s.url)) toast(t('Link copied.'), { type: 'success' }); }} /> : null}
                      <IconButton icon="settings" size="sm" label={t('Edit link')} onClick={() => edit(s)} />
                      <IconButton icon="more-h" size="sm" label={t('More actions')} onClick={(e) => openMenu(e, [
                        s.url && { label: t('Open link'), icon: 'external', run: () => window.open(s.url, '_blank', 'noopener,noreferrer') },
                        s.path && { label: t('Show in files'), icon: 'folder', run: () => navigate('files', s.type === 'dir' ? s.path : s.path.replace(/\/[^/]*$/, '') || '/') },
                        { label: s.status === 'disabled' ? t('Enable') : t('Disable'), icon: s.status === 'disabled' ? 'unlock' : 'lock', run: () => toggle(s) },
                        '-',
                        { label: t('Delete'), icon: 'trash', danger: true, run: () => remove(s) },
                      ], e.currentTarget)} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
