// Image tools: rotate, flip, resize, convert (server-side via WP_Image_Editor, versioned).
import { useState, useEffect } from 'preact/hooks';
import { Dialog } from '../components/Overlays';
import { Button, Field, Input, Switch, Segmented } from '../components/ui';
import { Icon } from '../components/Icon';
import { t } from '../lib/i18n';
import { post, streamUrl, get } from '../lib/api';
import { toast, toastError } from '../lib/ui';

export function ImageDialog({ close, entry }) {
  const [info, setInfo] = useState(null);
  const [tab, setTab] = useState('transform');
  const [width, setWidth] = useState('');
  const [height, setHeight] = useState('');
  const [format, setFormat] = useState('webp');
  const [quality, setQuality] = useState(82);
  const [copy, setCopy] = useState(false);
  const [busy, setBusy] = useState(false);
  const [version, setVersion] = useState(entry.mtime);
  const [changed, setChanged] = useState(false);

  useEffect(() => {
    get('fs/info', { path: entry.path }).then((d) => {
      setInfo(d);
      setWidth(String(d.width || ''));
    }).catch(() => {});
  }, [version]);

  const run = async (op, args) => {
    setBusy(true);
    try {
      const res = await post('fs/image', { path: entry.path, op, args: { ...args, copy } });
      setChanged(true);
      if (res.path !== entry.path) {
        toast(t('Saved as %s.', res.name), { type: 'success' });
        close(true);
        return;
      }
      setVersion(res.mtime + Math.random());
      toast(t('Image updated. The previous version was kept.'), { type: 'success' });
    } catch (e) {
      toastError(e);
    } finally {
      setBusy(false);
    }
  };

  const ratio = info && info.width && info.height ? info.height / info.width : null;
  return (
    <Dialog title={t('Image tools')} subtitle={entry.name} icon="image" size="lg" onClose={() => close(changed)}
      footer={<><span class="flr-grow" /><Button onClick={() => close(changed)}>{t('Done')}</Button></>}>
      <div class="flr-image-tools">
        <div class="flr-image-preview">
          <img src={streamUrl('fs/raw', { path: entry.path, v: version })} alt="" />
          {info && info.width ? <span class="flr-muted">{info.width} × {info.height} px</span> : null}
        </div>
        <div class="flr-image-controls">
          <Segmented value={tab} onChange={setTab} size="sm" options={[
            { value: 'transform', label: t('Rotate & flip') },
            { value: 'resize', label: t('Resize') },
            { value: 'convert', label: t('Convert') },
          ]} />
          {tab === 'transform' ? (
            <div class="flr-tool-grid">
              <Button icon="rotate-left" disabled={busy} onClick={() => run('rotate', { angle: -90 })}>{t('Rotate left')}</Button>
              <Button icon="rotate" disabled={busy} onClick={() => run('rotate', { angle: 90 })}>{t('Rotate right')}</Button>
              <Button icon="flip" disabled={busy} onClick={() => run('flip', { vertical: false })}>{t('Flip horizontally')}</Button>
              <Button icon="flip" class="flr-flip-v" disabled={busy} onClick={() => run('flip', { vertical: true })}>{t('Flip vertically')}</Button>
            </div>
          ) : null}
          {tab === 'resize' ? (
            <div class="flr-stack-form">
              <div class="flr-row">
                <Field label={t('Width (px)')}><Input type="number" min="1" value={width} onInput={(v) => { setWidth(v); if (ratio) setHeight(String(Math.round(Number(v) * ratio))); }} /></Field>
                <Field label={t('Height (px)')}><Input type="number" min="1" value={height} onInput={setHeight} placeholder={t('auto')} /></Field>
              </div>
              <Button variant="primary" loading={busy} disabled={!Number(width)} onClick={() => run('resize', { width: Number(width), height: Number(height) || 0 })}>{t('Resize')}</Button>
            </div>
          ) : null}
          {tab === 'convert' ? (
            <div class="flr-stack-form">
              <Segmented value={format} onChange={setFormat} options={[{ value: 'webp', label: 'WebP' }, { value: 'avif', label: 'AVIF' }, { value: 'jpg', label: 'JPEG' }, { value: 'png', label: 'PNG' }]} />
              <Field label={t('Quality: %d', quality)}><input type="range" min="40" max="100" value={quality} onInput={(e) => setQuality(Number(e.currentTarget.value))} class="flr-range" /></Field>
              <p class="flr-help"><Icon name="info" size={14} /> {t('A new file is created next to the original.')}</p>
              <Button variant="primary" loading={busy} onClick={() => run('convert', { format, quality })}>{t('Convert')}</Button>
            </div>
          ) : null}
          {tab !== 'convert' ? <Switch checked={copy} onChange={setCopy} label={t('Save as a copy')} description={t('Otherwise the original is replaced (a version is kept).')} /> : null}
        </div>
      </div>
    </Dialog>
  );
}
