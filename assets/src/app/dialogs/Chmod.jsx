// Permissions editor (octal + checkbox matrix).
import { useState } from 'preact/hooks';
import { Dialog } from '../components/Overlays';
import { Button, Field, Input, Switch } from '../components/ui';
import { t, tn } from '../lib/i18n';
import { post } from '../lib/api';
import { toast, toastError } from '../lib/ui';

const WHO = () => [t('Owner'), t('Group'), t('Others')];
const WHAT = () => [t('Read'), t('Write'), t('Execute')];

export function ChmodDialog({ close, entries }) {
  const first = entries[0];
  const initial = (first.perms || (first.type === 'dir' ? '0755' : '0644')).slice(-3);
  const [mode, setMode] = useState(initial);
  const [recursive, setRecursive] = useState(false);
  const [fileMode, setFileMode] = useState('644');
  const [dirMode, setDirMode] = useState('755');
  const [busy, setBusy] = useState(false);
  const valid = /^[0-7]{3}$/.test(mode);
  const digits = valid ? mode.split('').map(Number) : [0, 0, 0];
  const toggle = (who, bit) => {
    const d = [...digits];
    d[who] ^= [4, 2, 1][bit];
    setMode(d.join(''));
  };
  const hasDir = entries.some((e) => e.type === 'dir');
  const submit = async () => {
    setBusy(true);
    try {
      const res = await post('fs/chmod', { paths: entries.map((e) => e.path), mode, recursive, fileMode, dirMode });
      if (res.errors.length) toast(res.errors[0].message, { type: 'error' });
      else toast(t('Permissions updated.'), { type: 'success' });
      close(true);
    } catch (e) {
      toastError(e);
      setBusy(false);
    }
  };
  return (
    <Dialog title={t('Permissions')} subtitle={entries.length === 1 ? first.name : tn('%d item', '%d items', entries.length)} icon="key" size="sm" onClose={() => close(false)}
      footer={<><Button variant="ghost" onClick={() => close(false)}>{t('Cancel')}</Button><Button variant="primary" disabled={!valid} loading={busy} onClick={submit}>{t('Apply')}</Button></>}>
      <table class="flr-perm-table">
        <thead><tr><th /> {WHAT().map((w) => <th key={w}>{w}</th>)}</tr></thead>
        <tbody>
          {WHO().map((who, i) => (
            <tr key={who}>
              <th>{who}</th>
              {[0, 1, 2].map((bit) => (
                <td key={bit}><input type="checkbox" aria-label={who + ' ' + WHAT()[bit]} checked={!!(digits[i] & [4, 2, 1][bit])} onChange={() => toggle(i, bit)} /></td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
      <Field label={t('Octal value')} error={!valid ? t('Use three digits from 0 to 7, e.g. 644.') : ''}>
        <Input value={mode} onInput={setMode} maxLength={3} class="flr-input--narrow flr-mono" />
      </Field>
      {hasDir ? (
        <>
          <Switch checked={recursive} onChange={setRecursive} label={t('Apply to folder contents')} />
          {recursive ? (
            <div class="flr-row">
              <Field label={t('Files')}><Input value={fileMode} onInput={setFileMode} maxLength={3} class="flr-input--narrow flr-mono" /></Field>
              <Field label={t('Folders')}><Input value={dirMode} onInput={setDirMode} maxLength={3} class="flr-input--narrow flr-mono" /></Field>
            </div>
          ) : null}
        </>
      ) : null}
      <p class="flr-help">{t('Recommended: 644 for files, 755 for folders, 600 or 640 for wp-config.php.')}</p>
    </Dialog>
  );
}
