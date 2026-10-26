// Right-hand details panel: preview, metadata, share links and quick actions.
import { useEffect, useState } from 'preact/hooks';
import { Icon } from './Icon';
import { FileIcon, thumbUrl } from './FileIcon';
import { Button, IconButton, Spinner, Badge } from './ui';
import { t, tn, formatSize, formatDate, relative } from '../lib/i18n';
import { get } from '../lib/api';
import { can, isAdmin } from '../lib/config';
import { selectedEntries, listing, toggleDetails } from '../lib/store';
import { typeLabel, previewKind, isEditable, copyText } from '../lib/files';
import { share, download, openEntry, edit, rename, remove, copyLink, isFavorite, toggleFavorite, moveToVault } from '../lib/actions';
import { toast } from '../lib/ui';

function Row({ label, children }) {
  return (
    <div class="flr-meta-row">
      <dt>{label}</dt>
      <dd>{children}</dd>
    </div>
  );
}

export function DetailsPanel() {
  const sel = selectedEntries.value;
  const entry = sel.length === 1 ? sel[0] : null;
  const dirEntry = !sel.length && listing.value ? listing.value.dir : null;
  const target = entry || dirEntry;
  const [info, setInfo] = useState(null);
  const [shares, setShares] = useState(null);
  const [sizing, setSizing] = useState(false);

  useEffect(() => {
    setInfo(null);
    setShares(null);
    if (!target) return undefined;
    let alive = true;
    const timer = setTimeout(() => {
      get('fs/info', { path: target.path }).then((d) => alive && setInfo(d)).catch(() => {});
      if (can('share') && target.path !== '/') get('shares', { path: target.path }).then((d) => alive && setShares(d)).catch(() => alive && setShares([]));
    }, 120);
    return () => {
      alive = false;
      clearTimeout(timer);
    };
  }, [target && target.path, target && target.mtime]);

  const computeSize = async () => {
    setSizing(true);
    try {
      setInfo(await get('fs/info', { path: target.path, size: 1 }));
    } finally {
      setSizing(false);
    }
  };

  const close = <IconButton icon="x" label={t('Close details')} size="sm" onClick={() => toggleDetails(false)} />;

  if (sel.length > 1) {
    const size = sel.reduce((s, e) => s + (e.size || 0), 0);
    const dirs = sel.filter((e) => e.type === 'dir').length;
    return (
      <aside class="flr-details">
        <header class="flr-details-head"><h3>{tn('%d item selected', '%d items selected', sel.length)}</h3>{close}</header>
        <div class="flr-details-multi">
          <div class="flr-stack">
            {sel.slice(0, 3).map((e, i) => <span key={e.path} style={{ '--i': i }}><FileIcon entry={e} size="lg" thumb /></span>)}
          </div>
          <p>{dirs ? tn('%d folder', '%d folders', dirs) + ' · ' : ''}{tn('%d file', '%d files', sel.length - dirs)}</p>
          <p class="flr-muted">{formatSize(size)}{dirs ? ' + ' + t('folders') : ''}</p>
          <div class="flr-details-actions">
            {can('download') ? <Button icon="download" onClick={() => download(sel)}>{t('Download as ZIP')}</Button> : null}
            {can('delete') ? <Button icon="trash" variant="danger-ghost" onClick={() => remove(sel)}>{t('Delete')}</Button> : null}
          </div>
        </div>
      </aside>
    );
  }

  if (!target) {
    return (
      <aside class="flr-details">
        <header class="flr-details-head"><h3>{t('Details')}</h3>{close}</header>
        <p class="flr-muted flr-pad">{t('Select an item to see its details.')}</p>
      </aside>
    );
  }

  const kind = previewKind(target);
  const data = info || target;
  return (
    <aside class="flr-details">
      <header class="flr-details-head">
        <h3 title={target.name}>{entry ? target.name : t('This folder')}</h3>
        {close}
      </header>
      <div class="flr-details-scroll">
        <div class={'flr-details-visual' + (kind === 'image' ? ' is-image' : '')}>
          {kind === 'image' && target.thumb ? (
            <img src={thumbUrl(target, 640)} alt="" />
          ) : (
            <FileIcon entry={target} size="lg" />
          )}
        </div>

        {entry ? (
          <div class="flr-details-actions">
            {target.type === 'dir' || kind ? <Button size="sm" icon={target.type === 'dir' ? 'folder' : 'eye'} onClick={() => openEntry(target)}>{t('Open')}</Button> : null}
            {isEditable(target) && can('edit') ? <Button size="sm" icon="code" onClick={() => edit(target)}>{t('Edit')}</Button> : null}
            {can('download') ? <Button size="sm" icon="download" onClick={() => download([target])}>{t('Download')}</Button> : null}
            {can('share') ? <Button size="sm" variant="primary" icon="share" onClick={() => share(target)}>{t('Share')}</Button> : null}
          </div>
        ) : null}

        <dl class="flr-meta">
          <Row label={t('Type')}>{typeLabel(data)}</Row>
          {data.type === 'file' ? <Row label={t('Size')}>{formatSize(data.size)}</Row> : (
            <Row label={t('Size')}>
              {data.stats ? (
                <span>{formatSize(data.stats.size)} · {tn('%d file', '%d files', data.stats.files)}{data.stats.partial ? ' (' + t('partial') + ')' : ''}</span>
              ) : (
                <button type="button" class="flr-link" onClick={computeSize} disabled={sizing}>{sizing ? <Spinner size={14} /> : t('Calculate')}</button>
              )}
            </Row>
          )}
          {data.width ? <Row label={t('Dimensions')}>{data.width} × {data.height} px</Row> : null}
          <Row label={t('Modified')}><span title={formatDate(data.mtime)}>{relative(data.mtime)}</span></Row>
          {data.perms ? <Row label={t('Permissions')}><code>{data.perms}</code>{data.owner ? ' · ' + data.owner : ''}</Row> : null}
          {data.mime ? <Row label={t('MIME type')}><code>{data.mime}</code></Row> : null}
          <Row label={t('Path')}>
            <button type="button" class="flr-copyable" title={t('Copy')} onClick={async () => { if (await copyText(data.absolute || data.path)) toast(t('Path copied.'), { type: 'success' }); }}>
              <code>{data.absolute || data.path}</code>
            </button>
          </Row>
          {data.url ? (
            <Row label={t('Public URL')}>
              <button type="button" class="flr-copyable" title={t('Copy')} onClick={() => copyLink(data)}><code>{data.url}</code></button>
            </Row>
          ) : null}
          {info && typeof info.versions === 'number' && info.versions > 0 ? (
            <Row label={t('Versions')}>
              <button type="button" class="flr-link" onClick={() => edit(target)}>{tn('%d saved version', '%d saved versions', info.versions)}</button>
            </Row>
          ) : null}
        </dl>

        <div class="flr-tags">
          {data.system ? <Badge tone="info" icon="shield">{t('Protected system item')}</Badge> : null}
          {data.locked ? <Badge tone="warning" icon="lock">{t('Read-only')}</Badge> : null}
          {info && info.sensitive ? <Badge tone="danger" icon="key">{t('Sensitive')}</Badge> : null}
          {info && info.vault ? <Badge tone="success" icon="shield-check">{t('In the Vault (private)')}</Badge> : null}
          {data.url && info && !info.vault && entry ? <Badge tone="neutral" icon="globe">{t('Public URL')}</Badge> : null}
        </div>

        {entry && can('share') && shares && shares.length ? (
          <div class="flr-details-section">
            <h4><Icon name="link" size={15} /> {t('Share links')}</h4>
            {shares.map((s) => (
              <div class="flr-share-mini" key={s.id}>
                <Badge tone={s.status === 'active' ? 'success' : 'neutral'}>{statusLabel(s.status)}</Badge>
                <span class="flr-muted">{s.expiresAt ? t('expires %s', relative(s.expiresAt)) : t('no expiration')}</span>
                <span class="flr-muted">{tn('%d download', '%d downloads', s.downloads)}</span>
                {s.url ? <IconButton icon="copy" size="sm" label={t('Copy link')} onClick={async () => { if (await copyText(s.url)) toast(t('Link copied.'), { type: 'success' }); }} /> : null}
              </div>
            ))}
            <Button size="sm" variant="ghost" icon="settings" onClick={() => share(target)}>{t('Manage links')}</Button>
          </div>
        ) : null}

        {entry ? (
          <div class="flr-details-links">
            <button type="button" class="flr-link" onClick={() => toggleFavorite(target.path)}>
              <Icon name="star" size={15} filled={isFavorite(target.path)} /> {isFavorite(target.path) ? t('Remove from favorites') : t('Add to favorites')}
            </button>
            {can('rename') && target.writable && !target.system ? <button type="button" class="flr-link" onClick={() => rename(target)}><Icon name="rename" size={15} /> {t('Rename')}</button> : null}
            {isAdmin && info && !info.vault && data.url && target.type === 'file' ? (
              <button type="button" class="flr-link" onClick={() => moveToVault([target])}><Icon name="shield" size={15} /> {t('Move to Vault (private)')}</button>
            ) : null}
          </div>
        ) : (
          <p class="flr-muted flr-pad">{t('Tip: select an item to see its details and share links.')}</p>
        )}
      </div>
    </aside>
  );
}

export function statusLabel(status) {
  return {
    active: t('Active'),
    disabled: t('Disabled'),
    expired: t('Expired'),
    exhausted: t('Limit reached'),
    missing: t('Missing file'),
    orphaned: t('Inactive'),
  }[status] || status;
}
