// Activity log (audit trail) with filters and CSV export.
import { useEffect, useState } from 'preact/hooks';
import { Icon } from '../components/Icon';
import { Button, Spinner, Empty, Select, Badge } from '../components/ui';
import { ViewHeader } from '../components/ViewHeader';
import { actionLabel } from '../dialogs/Share';
import { t, relative, formatDate } from '../lib/i18n';
import { get, streamUrl } from '../lib/api';
import { isAdmin } from '../lib/config';
import { toastError } from '../lib/ui';

const LABELS = () => ({
  upload: t('Uploaded'), download: t('Downloaded'), edit: t('Edited'), mkdir: t('Created folder'), mkfile: t('Created file'),
  rename: t('Renamed'), move: t('Moved'), copy: t('Copied'), trash: t('Moved to trash'), delete: t('Deleted'), restore: t('Restored'),
  purge: t('Deleted permanently'), chmod: t('Changed permissions'), archive: t('Compressed'), extract: t('Extracted'), image: t('Edited image'),
  restore_version: t('Restored a version'), 'settings.update': t('Changed settings'), sudo: t('Confirmed password'), 'sudo.failed': t('Wrong password (confirmation)'),
  'tools.integrity': t('Ran integrity check'),
});

export const describe = (action) => LABELS()[action] || actionLabel(action);

const TONE = (action) => {
  if (/failed|denied|refused|delete|purge/.test(action)) return 'danger';
  if (/^share\./.test(action)) return 'info';
  if (/upload|create|mkdir|mkfile|restore|extract|archive/.test(action)) return 'success';
  if (/edit|rename|move|chmod|image|settings/.test(action)) return 'warning';
  return 'neutral';
};

export function ActivityView() {
  const [data, setData] = useState(null);
  const [items, setItems] = useState([]);
  const [page, setPage] = useState(1);
  const [filters, setFilters] = useState({ action: '', user: '', period: '' });
  const [loading, setLoading] = useState(false);

  const query = () => {
    const q = { action: filters.action, user: filters.user, page, per_page: 60 };
    if (filters.period) q.from = Math.floor(Date.now() / 1000) - Number(filters.period) * 86400;
    return q;
  };

  useEffect(() => {
    setLoading(true);
    get('activity', query())
      .then((d) => {
        setData(d);
        setItems(page === 1 ? d.items : (prev) => [...prev, ...d.items]);
      })
      .catch(toastError)
      .finally(() => setLoading(false));
  }, [filters, page]);

  const set = (patch) => {
    setPage(1);
    setFilters({ ...filters, ...patch });
  };

  return (
    <div class="flr-view">
      <ViewHeader icon="activity" title={t('Activity')} subtitle={isAdmin ? t('Everything that happened in the file manager and on share links.') : t('Your recent actions.')}>
        <a class="flr-btn flr-btn--default" href={streamUrl('activity/export', { action: filters.action, user: filters.user })}><Icon name="download" size={18} /><span class="flr-btn-label">{t('Export CSV')}</span></a>
      </ViewHeader>
      <div class="flr-view-toolbar">
        <Select value={filters.action} onChange={(v) => set({ action: v })} options={[
          { value: '', label: t('All actions') },
          { value: 'share.', label: t('Share links') },
          { value: 'upload', label: t('Uploads') },
          { value: 'download', label: t('Downloads') },
          { value: 'edit', label: t('Edits') },
          { value: 'trash', label: t('Deletions') },
          { value: 'share.unlock_failed', label: t('Failed unlock attempts') },
          { value: 'settings.update', label: t('Settings changes') },
        ]} />
        {isAdmin && data && data.users && data.users.length ? (
          <Select value={filters.user} onChange={(v) => set({ user: v })} options={[{ value: '', label: t('All users') }, ...data.users.map((u) => ({ value: String(u.id), label: u.name }))]} />
        ) : null}
        <Select value={filters.period} onChange={(v) => set({ period: v })} options={[
          { value: '', label: t('Any time') },
          { value: '1', label: t('Last 24 hours') },
          { value: '7', label: t('Last 7 days') },
          { value: '30', label: t('Last 30 days') },
        ]} />
        {data ? <span class="flr-muted">{t('%d events', data.total)}</span> : null}
      </div>
      <div class="flr-view-body">
        {!data ? <div class="flr-center-fill"><Spinner size={24} /></div> : !items.length ? (
          <Empty icon="activity" title={t('No activity')}>{t('Actions on files and share links will appear here.')}</Empty>
        ) : (
          <>
            <ol class="flr-timeline">
              {items.map((it) => (
                <li key={it.id}>
                  <span class={'flr-dot flr-dot--' + TONE(it.action)} />
                  <div class="flr-timeline-main">
                    <div class="flr-row flr-row--wrap">
                      <strong>{it.user || (it.shareId ? (it.details && it.details.email) || t('Visitor') : t('System'))}</strong>
                      <Badge tone={TONE(it.action)}>{describe(it.action)}</Badge>
                      {it.path ? <code class="flr-ellipsis" title={it.path}>{it.path}</code> : null}
                    </div>
                    <span class="flr-muted flr-small">
                      <span title={formatDate(it.time)}>{relative(it.time)}</span>
                      {it.details && it.details.from ? ' · ' + t('from %s', it.details.from) : ''}
                      {it.details && it.details.name ? ' · ' + it.details.name : ''}
                      {it.ip ? ' · ' + it.ip : ''}
                      {it.shareId ? ' · ' + t('link #%d', it.shareId) : ''}
                    </span>
                  </div>
                </li>
              ))}
            </ol>
            {items.length < data.total ? (
              <div class="flr-row flr-row--center flr-pad">
                <Button loading={loading} onClick={() => setPage(page + 1)}>{t('Load more')}</Button>
              </div>
            ) : null}
          </>
        )}
      </div>
    </div>
  );
}
