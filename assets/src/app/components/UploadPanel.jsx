// Floating upload queue.
import { Icon } from './Icon';
import { IconButton } from './ui';
import { t, tn, formatSize } from '../lib/i18n';
import { uploads, uploadStats, uploadPanelOpen, cancelUpload, retryUpload, clearUploads, cancelAll } from '../lib/upload';

export function UploadPanel() {
  const list = uploads.value;
  if (!list.length) return null;
  const stats = uploadStats.value;
  const open = uploadPanelOpen.value;
  const title = stats.active
    ? tn('Uploading %d file', 'Uploading %d files', stats.active) + ' · ' + stats.percent + '%'
    : stats.errors
      ? t('%1$d uploaded, %2$d failed.', stats.done, stats.errors)
      : tn('%d file uploaded', '%d files uploaded', stats.done);
  return (
    <section class={'flr-uploads' + (open ? '' : ' is-collapsed')} aria-label={t('Uploads')}>
      <header class="flr-uploads-head">
        <span class={'flr-uploads-ring' + (stats.active ? ' is-active' : stats.errors ? ' is-error' : ' is-done')} style={{ '--p': stats.percent }}>
          <Icon name={stats.active ? 'upload' : stats.errors ? 'alert' : 'check'} size={14} />
        </span>
        <strong>{title}</strong>
        {stats.active ? <IconButton icon="x" size="sm" label={t('Cancel all')} onClick={cancelAll} /> : <IconButton icon="trash" size="sm" label={t('Clear list')} onClick={clearUploads} />}
        <IconButton icon={open ? 'chevron-down' : 'chevron-up'} size="sm" label={open ? t('Collapse') : t('Expand')} onClick={() => { uploadPanelOpen.value = !open; }} />
      </header>
      {open ? (
        <ul class="flr-uploads-list">
          {list.slice().reverse().map((u) => (
            <li key={u.id} class={'is-' + u.status}>
              <div class="flr-upload-line">
                <span class="flr-upload-name" title={u.relpath}>{u.relpath}</span>
                {u.status === 'uploading' || u.status === 'queued' ? (
                  <IconButton icon="x" size="sm" label={t('Cancel')} onClick={() => cancelUpload(u.id)} />
                ) : u.status === 'error' ? (
                  <IconButton icon="refresh" size="sm" label={t('Retry')} onClick={() => retryUpload(u.id)} />
                ) : u.status === 'done' ? (
                  <Icon name="check" size={16} class="flr-ok" />
                ) : null}
              </div>
              <div class="flr-upload-sub">
                {u.status === 'error' ? <span class="flr-err">{u.error}</span> : u.status === 'canceled' ? t('Canceled') : u.status === 'queued' ? t('Waiting…') : formatSize(Math.min(u.loaded, u.size)) + ' / ' + formatSize(u.size)}
              </div>
              {u.status === 'uploading' ? <div class="flr-progress"><i style={{ width: (u.size ? (u.loaded / u.size) * 100 : 100) + '%' }} /></div> : null}
            </li>
          ))}
        </ul>
      ) : null}
    </section>
  );
}
