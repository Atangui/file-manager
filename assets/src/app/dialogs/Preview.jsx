// Quick-look lightbox: images, video, audio, PDF, text, ZIP contents and fonts.
import { useEffect, useState } from 'preact/hooks';
import { Icon } from '../components/Icon';
import { FileIcon } from '../components/FileIcon';
import { Button, IconButton, Spinner } from '../components/ui';
import { t, tn, formatSize, relative } from '../lib/i18n';
import { get, streamUrl } from '../lib/api';
import { can, C } from '../lib/config';
import { previewKind, isEditable } from '../lib/files';
import { download, edit, share, extract, editImage } from '../lib/actions';

function TextPreview({ entry }) {
  const [text, setText] = useState(null);
  useEffect(() => {
    let alive = true;
    fetch(streamUrl('fs/raw', { path: entry.path }), { credentials: 'same-origin' })
      .then((r) => (r.ok ? r.text() : Promise.reject(new Error(String(r.status)))))
      .then((s) => alive && setText(s.length > 400000 ? s.slice(0, 400000) + '\n…' : s))
      .catch(() => alive && setText(false));
    return () => { alive = false; };
  }, [entry.path]);
  if (text === null) return <Spinner size={26} />;
  if (text === false) return <p class="flr-muted">{t('This file cannot be previewed.')}</p>;
  return <pre class="flr-preview-text">{text}</pre>;
}

function ZipPreview({ entry }) {
  const [data, setData] = useState(null);
  useEffect(() => {
    get('fs/zip-entries', { path: entry.path }).then(setData).catch((e) => setData({ error: e.message }));
  }, [entry.path]);
  if (!data) return <Spinner size={26} />;
  if (data.error) return <p class="flr-muted">{data.error}</p>;
  const total = data.entries.reduce((s, e) => s + e.size, 0);
  return (
    <div class="flr-preview-zip">
      <p class="flr-muted">{tn('%d entry', '%d entries', data.total)} · {formatSize(total)} {t('uncompressed')}</p>
      <ul>
        {data.entries.slice(0, 500).map((e) => (
          <li key={e.name}><Icon name={e.name.endsWith('/') ? 'folder' : 'file'} size={15} /><span>{e.name}</span><small>{e.name.endsWith('/') ? '' : formatSize(e.size)}</small></li>
        ))}
      </ul>
      {can('extract') && C.zip ? <Button variant="primary" icon="extract" onClick={() => extract(entry, true)}>{t('Extract to a folder')}</Button> : null}
    </div>
  );
}

function FontPreview({ entry }) {
  const [family, setFamily] = useState(null);
  useEffect(() => {
    const name = 'flr-font-' + Math.random().toString(36).slice(2);
    const face = new FontFace(name, `url(${streamUrl('fs/raw', { path: entry.path })})`);
    face.load().then((f) => { document.fonts.add(f); setFamily(name); }).catch(() => setFamily(false));
  }, [entry.path]);
  if (family === null) return <Spinner size={26} />;
  if (family === false) return <p class="flr-muted">{t('This font cannot be previewed.')}</p>;
  return (
    <div class="flr-preview-font" style={{ fontFamily: family }}>
      <p style={{ fontSize: '56px' }}>Aa Bb Cc</p>
      <p style={{ fontSize: '28px' }}>The quick brown fox jumps over the lazy dog</p>
      <p style={{ fontSize: '18px' }}>ABCDEFGHIJKLMNOPQRSTUVWXYZ abcdefghijklmnopqrstuvwxyz 0123456789 àéèçù</p>
    </div>
  );
}

export function PreviewDialog({ close, items, start }) {
  const [index, setIndex] = useState(start || 0);
  const entry = items[index];
  const kind = previewKind(entry);
  const raw = entry ? streamUrl('fs/raw', { path: entry.path, v: entry.mtime }) : '';

  useEffect(() => {
    const onKey = (e) => {
      if (e.key === 'Escape') { e.preventDefault(); close(); }
      else if (e.key === 'ArrowRight') setIndex((i) => (i + 1) % items.length);
      else if (e.key === 'ArrowLeft') setIndex((i) => (i - 1 + items.length) % items.length);
    };
    window.addEventListener('keydown', onKey, true);
    return () => window.removeEventListener('keydown', onKey, true);
  }, [items.length]);

  if (!entry) return null;
  let stage;
  if (!can('download') && kind && kind !== 'zip') {
    stage = <div class="flr-preview-none"><FileIcon entry={entry} size="lg" /><p>{t('You are not allowed to open this file.')}</p></div>;
  } else if (kind === 'image') stage = <img key={entry.path} src={raw} alt={entry.name} class="flr-preview-img" />;
  else if (kind === 'video') stage = <video key={entry.path} src={raw} controls autoPlay playsInline class="flr-preview-media" />;
  else if (kind === 'audio') stage = <div class="flr-preview-audio"><FileIcon entry={entry} size="lg" /><audio key={entry.path} src={raw} controls autoPlay /></div>;
  else if (kind === 'pdf') stage = <iframe key={entry.path} src={raw} title={entry.name} class="flr-preview-pdf" />;
  else if (kind === 'text') stage = <TextPreview entry={entry} key={entry.path} />;
  else if (kind === 'zip') stage = <ZipPreview entry={entry} key={entry.path} />;
  else if (kind === 'font') stage = <FontPreview entry={entry} key={entry.path} />;
  else stage = <div class="flr-preview-none"><FileIcon entry={entry} size="lg" /><p>{t('No preview available for this file.')}</p></div>;

  return (
    <div class="flr-lightbox" role="dialog" aria-modal="true" aria-label={entry.name}>
      <header class="flr-lightbox-head">
        <div class="flr-lightbox-title">
          <strong title={entry.name}>{entry.name}</strong>
          <span>{formatSize(entry.size)} · {relative(entry.mtime)}{items.length > 1 ? ' · ' + (index + 1) + ' / ' + items.length : ''}</span>
        </div>
        {kind === 'image' && can('image') && entry.writable && entry.ext !== 'svg' ? <Button size="sm" variant="glass" icon="image" onClick={() => { close(); editImage(entry); }}><span class="flr-hide-sm">{t('Edit image')}</span></Button> : null}
        {isEditable(entry) && can('edit') ? <Button size="sm" variant="glass" icon="code" onClick={() => { close(); edit(entry); }}><span class="flr-hide-sm">{t('Edit')}</span></Button> : null}
        {can('share') ? <Button size="sm" variant="glass" icon="share" onClick={() => share(entry)}><span class="flr-hide-sm">{t('Share')}</span></Button> : null}
        {can('download') ? <Button size="sm" variant="primary" icon="download" onClick={() => download([entry])}><span class="flr-hide-sm">{t('Download')}</span></Button> : null}
        <IconButton icon="x" label={t('Close')} class="flr-lightbox-close" onClick={() => close()} data-autofocus />
      </header>
      <div class="flr-lightbox-stage" onClick={(e) => e.target === e.currentTarget && close()}>
        {stage}
        {items.length > 1 ? (
          <>
            <button type="button" class="flr-lightbox-nav is-prev" aria-label={t('Previous')} onClick={() => setIndex((index - 1 + items.length) % items.length)}><Icon name="chevron-left" size={24} /></button>
            <button type="button" class="flr-lightbox-nav is-next" aria-label={t('Next')} onClick={() => setIndex((index + 1) % items.length)}><Icon name="chevron-right" size={24} /></button>
          </>
        ) : null}
      </div>
    </div>
  );
}
