// File/folder visual: colored type tile, or a lazy thumbnail for images.
import { useState } from 'preact/hooks';
import { Icon } from './Icon';
import { streamUrl } from '../lib/api';

const CAT_ICON = {
  dir: 'folder', image: 'image', video: 'play', audio: 'music', pdf: 'file-text', archive: 'archive',
  doc: 'file-text', sheet: 'grid', slides: 'monitor', font: 'text', code: 'code', file: 'file',
};

export function thumbUrl(entry, size = 320) {
  return streamUrl('fs/thumb', { path: entry.path, s: size, v: entry.mtime });
}

export function FileIcon({ entry, size = 'md', thumb = false }) {
  const [broken, setBroken] = useState(false);
  const cat = entry.type === 'dir' ? 'dir' : entry.cat || 'file';
  if (thumb && entry.thumb && !broken) {
    return (
      <span class={'flr-thumb flr-thumb--' + size}>
        <img src={thumbUrl(entry, size === 'lg' ? 320 : 64)} alt="" loading="lazy" decoding="async" draggable={false} onError={() => setBroken(true)} />
      </span>
    );
  }
  const ext = entry.type === 'dir' ? '' : (entry.ext || '').slice(0, 4).toUpperCase();
  return (
    <span class={'flr-tile flr-tile--' + cat + ' flr-tile--' + size} data-ext={ext || undefined}>
      <Icon name={CAT_ICON[cat] || 'file'} size={size === 'lg' ? 30 : size === 'sm' ? 15 : 18} />
      {ext && size !== 'sm' ? <b>{ext}</b> : null}
    </span>
  );
}
