// Share dialog: create and manage secure links for a file or folder.
import { useEffect, useState } from 'preact/hooks';
import { Dialog } from '../components/Overlays';
import { Icon } from '../components/Icon';
import { FileIcon } from '../components/FileIcon';
import { Button, IconButton, Field, Input, Textarea, Switch, Segmented, Select, Badge, Spinner } from '../components/ui';
import { statusLabel } from '../components/DetailsPanel';
import { t, tn, formatDate, relative } from '../lib/i18n';
import { get, post, del } from '../lib/api';
import { C, can, isAdmin } from '../lib/config';
import { toast, toastError } from '../lib/ui';
import { copyText, randomPassword } from '../lib/files';
import { confirm } from './common';
import { moveToVault } from '../lib/actions';

const S = C.sharing || {};

function expiryChoices() {
  const max = S.maxDays || 0;
  const all = [
    { value: '1d', label: t('1 day'), days: 1 },
    { value: '7d', label: t('7 days'), days: 7 },
    { value: '30d', label: t('30 days'), days: 30 },
    { value: '90d', label: t('90 days'), days: 90 },
    { value: 'never', label: t('Never'), days: Infinity },
    { value: 'custom', label: t('Date…'), days: 0 },
  ];
  return all.filter((c) => !max || c.value === 'custom' || c.days <= max);
}

function defaultExpiry() {
  const d = S.defaultDays;
  if (!d) return S.maxDays ? S.maxDays + 'd' : 'never';
  const preset = ['1d', '7d', '30d', '90d'].find((v) => v === d + 'd');
  return preset || d + 'd';
}

function toLocalInput(ts) {
  const d = new Date(ts * 1000);
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

const MODE_INFO = () => ({
  view: { icon: 'eye', label: t('View only'), help: t('Visitors can browse and preview, without a download button.') },
  download: { icon: 'download', label: t('View & download'), help: t('Visitors can preview and download files (or everything as a ZIP).') },
  upload: { icon: 'upload', label: t('Collaborate'), help: t('Visitors can also upload files into this folder.') },
  dropbox: { icon: 'inbox', label: t('File request'), help: t('Visitors can only send files. They never see the folder’s content.') },
});

function blankForm(entry) {
  return {
    label: '',
    mode: 'download',
    expires: defaultExpiry(),
    customDate: toLocalInput(Date.now() / 1000 + 14 * 86400),
    usePassword: !!S.forcePassword,
    password: S.forcePassword ? randomPassword() : '',
    useEmails: false,
    emails: '',
    useLimit: false,
    maxDownloads: 1,
    ips: '',
    message: '',
    notify: S.notify || 'uploads',
    allowZip: true,
    askName: false,
    uploadExts: '',
    advanced: false,
    isDir: entry.type === 'dir',
  };
}

function formFromShare(share) {
  const exp = share.expiresAt ? 'custom' : 'never';
  const customDate = share.expiresAt ? toLocalInput(share.expiresAt) : toLocalInput(Date.now() / 1000 + 14 * 86400);
  return {
    label: share.label,
    mode: share.mode,
    expires: exp,
    customDate,
    expiresOrig: exp + '|' + customDate,
    usePassword: share.hasPassword,
    password: '',
    passwordSet: share.hasPassword,
    useEmails: share.emails.length > 0,
    emails: share.emails.join('\n'),
    useLimit: !!share.maxDownloads,
    maxDownloads: share.maxDownloads || 1,
    ips: share.ips.join('\n'),
    message: share.options.message || '',
    notify: share.options.notify || 'uploads',
    allowZip: share.options.allow_zip !== false,
    askName: !!share.options.ask_name,
    uploadExts: '',
    advanced: !!(share.ips.length || share.options.message),
    isDir: share.type === 'dir',
  };
}

function payload(form, editing) {
  const data = {
    label: form.label,
    mode: form.mode,
    max_downloads: form.useLimit ? Number(form.maxDownloads) || 1 : 0,
    emails: form.useEmails ? form.emails : '',
    ips: form.ips,
    options: { message: form.message, notify: form.notify, allow_zip: form.allowZip, ask_name: form.askName },
  };
  if (form.uploadExts.trim()) data.options.upload_exts = form.uploadExts;
  if (editing && form.expires + '|' + form.customDate === form.expiresOrig) {
    // Unchanged: do not resend (an expired link can still be edited).
  } else if (form.expires === 'custom') {
    const ts = Math.floor(new Date(form.customDate).getTime() / 1000);
    data.expires = String(ts);
  } else {
    data.expires = form.expires === 'never' ? 'never' : form.expires;
  }
  if (!form.usePassword) {
    if (!editing || form.passwordSet) data.password = '';
  } else if (form.password) {
    data.password = form.password;
  }
  if (editing && !form.usePassword && !form.passwordSet) delete data.password;
  return data;
}

function ShareForm({ form, setForm, entry, editing }) {
  const modes = MODE_INFO();
  const set = (patch) => setForm({ ...form, ...patch });
  const allowed = ['view', 'download'];
  if (form.isDir && S.allowUpload && can('upload')) allowed.push('upload', 'dropbox');
  const choices = expiryChoices();
  return (
    <div class="flr-share-form">
      <div class="flr-mode-grid" role="radiogroup" aria-label={t('Access')}>
        {allowed.map((m) => (
          <button type="button" role="radio" aria-checked={String(form.mode === m)} class={'flr-mode' + (form.mode === m ? ' is-active' : '')} onClick={() => set({ mode: m })} key={m}>
            <Icon name={modes[m].icon} size={20} />
            <strong>{modes[m].label}</strong>
            <span>{modes[m].help}</span>
          </button>
        ))}
      </div>

      <Field label={t('Link expires')}>
        <Segmented value={form.expires} onChange={(v) => set({ expires: v })} options={choices} size="sm" label={t('Link expires')} />
        {form.expires === 'custom' ? (
          <Input type="datetime-local" value={form.customDate} onInput={(v) => set({ customDate: v })} class="flr-mt" min={toLocalInput(Date.now() / 1000 + 300)} />
        ) : null}
      </Field>

      <div class="flr-share-protect">
        <Switch
          checked={form.usePassword}
          disabled={S.forcePassword}
          onChange={(v) => set({ usePassword: v, password: v && !form.password && !form.passwordSet ? randomPassword() : form.password })}
          label={t('Password')}
          description={form.passwordSet && form.usePassword && !form.password ? t('A password is set. Type a new one to change it.') : t('Visitors must enter it to open the link.')}
        />
        {form.usePassword ? (
          <div class="flr-row flr-pw-row">
            <Input value={form.password} onInput={(v) => set({ password: v })} placeholder={form.passwordSet ? '••••••••' : t('Password')} autocomplete="new-password" spellcheck={false} />
            <IconButton icon="sparkles" label={t('Generate a strong password')} onClick={() => set({ password: randomPassword() })} />
            <IconButton icon="copy" label={t('Copy password')} disabled={!form.password} onClick={async () => { if (await copyText(form.password)) toast(t('Password copied.'), { type: 'success' }); }} />
          </div>
        ) : null}

        <Switch
          checked={form.useEmails}
          onChange={(v) => set({ useEmails: v })}
          label={t('Only specific people')}
          description={t('Visitors confirm their email address with a one-time code. Allows *@company.com.')}
        />
        {form.useEmails ? (
          <Textarea rows={2} value={form.emails} onInput={(v) => set({ emails: v })} placeholder={'client@company.com\n*@partner.com'} spellcheck={false} />
        ) : null}

        {form.mode !== 'dropbox' ? (
          <>
            <Switch checked={form.useLimit} onChange={(v) => set({ useLimit: v })} label={t('Download limit')} description={t('The link stops working after this many downloads. Previews are disabled.')} />
            {form.useLimit ? <Input type="number" min="1" max="100000" value={form.maxDownloads} onInput={(v) => set({ maxDownloads: v })} class="flr-input--narrow" /> : null}
          </>
        ) : null}
      </div>

      <button type="button" class="flr-disclosure" aria-expanded={String(form.advanced)} onClick={() => set({ advanced: !form.advanced })}>
        <Icon name="chevron-right" size={16} /> {t('More options')}
      </button>
      {form.advanced ? (
        <div class="flr-share-advanced">
          <Field label={t('Name shown to visitors')} help={t('Defaults to the file or folder name.')}>
            <Input value={form.label} onInput={(v) => set({ label: v })} maxLength={190} placeholder={entry.name} />
          </Field>
          <Field label={t('Message for visitors')}>
            <Textarea rows={3} value={form.message} onInput={(v) => set({ message: v })} placeholder={t('Hello, here are the files we discussed…')} />
          </Field>
          <Field label={t('Notify me by email')}>
            <Select value={form.notify} onChange={(v) => set({ notify: v })} options={[
              { value: 'none', label: t('Never') },
              { value: 'uploads', label: t('When files are uploaded') },
              { value: 'all', label: t('On every visit, download and upload') },
            ]} />
          </Field>
          <Field label={t('Allowed IP addresses')} help={t('One IP or range per line (e.g. 203.0.113.0/24). Leave empty to allow everyone.')}>
            <Textarea rows={2} value={form.ips} onInput={(v) => set({ ips: v })} placeholder="203.0.113.10" spellcheck={false} />
          </Field>
          {form.isDir && form.mode !== 'view' && form.mode !== 'dropbox' ? (
            <Switch checked={form.allowZip} onChange={(v) => set({ allowZip: v })} label={t('“Download all” as ZIP')} />
          ) : null}
          {form.mode === 'upload' || form.mode === 'dropbox' ? (
            <>
              <Switch checked={form.askName} onChange={(v) => set({ askName: v })} label={t('Ask uploaders for their name')} />
              <Field label={t('Allowed file types for uploads')} help={t('Comma separated. Leave empty to use the site default: %s', S.uploadExts || '*')}>
                <Input value={form.uploadExts} onInput={(v) => set({ uploadExts: v })} placeholder="pdf, jpg, png, zip" spellcheck={false} />
              </Field>
            </>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

function QrCode({ url }) {
  const [svg, setSvg] = useState('');
  useEffect(() => {
    import('uqr').then(({ renderSVG }) => setSvg(renderSVG(url, { ecc: 'M', border: 2 })));
  }, [url]);
  return svg ? <div class="flr-qr" dangerouslySetInnerHTML={{ __html: svg }} /> : <Spinner />;
}

function ShareResult({ share, password, onDone, onEdit }) {
  const [qr, setQr] = useState(false);
  const subject = t('Files shared with you: %s', share.label || share.name);
  const body = t('Hello,\n\nHere is a secure link to access the files:\n%s\n\n', share.url) + (share.expiresAt ? t('The link is available until %s.', formatDate(share.expiresAt, false)) : '');
  return (
    <div class="flr-share-result">
      <div class="flr-success-mark"><Icon name="check" size={26} /></div>
      <h3>{t('Your link is ready')}</h3>
      <div class="flr-link-box">
        <Icon name="link" size={18} />
        <input readOnly value={share.url} onFocus={(e) => e.currentTarget.select()} aria-label={t('Share link')} />
        <Button variant="primary" icon="copy" onClick={async () => { if (await copyText(share.url)) toast(t('Link copied.'), { type: 'success' }); }} data-autofocus>{t('Copy')}</Button>
      </div>
      {password ? (
        <div class="flr-callout flr-callout--warning">
          <Icon name="key" size={18} />
          <div>
            <strong>{t('Password: %s', password)}</strong>
            <p>{t('Send the password through another channel (SMS, phone…) than the link. It will not be shown again.')}</p>
          </div>
          <IconButton icon="copy" size="sm" label={t('Copy password')} onClick={async () => { if (await copyText(password)) toast(t('Password copied.'), { type: 'success' }); }} />
        </div>
      ) : null}
      <div class="flr-row flr-row--center">
        <Button variant="ghost" icon="qr" onClick={() => setQr(!qr)}>{t('QR code')}</Button>
        <a class="flr-btn flr-btn--ghost" href={'mailto:?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(body)}><Icon name="mail" size={18} /><span class="flr-btn-label">{t('Send by email')}</span></a>
        <a class="flr-btn flr-btn--ghost" href={share.url} target="_blank" rel="noopener noreferrer"><Icon name="external" size={18} /><span class="flr-btn-label">{t('Open')}</span></a>
      </div>
      {qr ? <QrCode url={share.url} /> : null}
      <div class="flr-row flr-row--center flr-mt">
        <Button variant="ghost" onClick={onEdit}>{t('Link settings')}</Button>
        <Button onClick={onDone}>{t('Done')}</Button>
      </div>
    </div>
  );
}

function ShareRow({ share, onEdit }) {
  const protections = [];
  if (share.hasPassword) protections.push(<Icon name="lock" size={14} key="pw" title={t('Password')} />);
  if (share.emails.length) protections.push(<Icon name="mail" size={14} key="em" title={t('Only specific people')} />);
  if (share.ips.length) protections.push(<Icon name="globe" size={14} key="ip" title={t('IP restricted')} />);
  return (
    <div class="flr-share-row">
      <div class="flr-share-row-main">
        <div class="flr-row">
          <Badge tone={share.status === 'active' ? 'success' : share.status === 'disabled' ? 'neutral' : 'warning'}>{statusLabel(share.status)}</Badge>
          <strong>{MODE_INFO()[share.mode].label}</strong>
          <span class="flr-protect-icons">{protections}</span>
        </div>
        <span class="flr-muted">
          {share.expiresAt ? (share.expiresAt * 1000 < Date.now() ? t('expired %s', relative(share.expiresAt)) : t('expires %s', relative(share.expiresAt))) : t('no expiration')}
          {' · '}{tn('%d view', '%d views', share.views)} · {tn('%d download', '%d downloads', share.downloads)}
          {share.uploads ? ' · ' + tn('%d upload', '%d uploads', share.uploads) : ''}
        </span>
      </div>
      {share.url ? <IconButton icon="copy" label={t('Copy link')} onClick={async () => { if (await copyText(share.url)) toast(t('Link copied.'), { type: 'success' }); }} /> : null}
      <IconButton icon="settings" label={t('Edit link')} onClick={onEdit} />
    </div>
  );
}

function ShareLog({ share }) {
  const [log, setLog] = useState(null);
  useEffect(() => {
    get('shares/' + share.id + '/activity').then(setLog).catch(() => setLog({ items: [] }));
  }, [share.id]);
  if (!log) return <Spinner />;
  if (!log.items.length) return <p class="flr-muted">{t('No activity yet.')}</p>;
  return (
    <ul class="flr-share-log">
      {log.items.slice(0, 50).map((it) => (
        <li key={it.id}>
          <span class="flr-muted" title={formatDate(it.time)}>{relative(it.time)}</span>
          <span>{actionLabel(it.action)}{it.details && it.details.email ? ' · ' + it.details.email : ''}{it.details && it.details.name ? ' · ' + it.details.name : ''}</span>
          {it.path ? <code>{it.path}</code> : null}
          {it.ip ? <span class="flr-muted">{it.ip}</span> : null}
        </li>
      ))}
    </ul>
  );
}

export function actionLabel(action) {
  const map = {
    'share.view': t('Opened'),
    'share.download': t('Downloaded'),
    'share.download_zip': t('Downloaded as ZIP'),
    'share.upload': t('Uploaded'),
    'share.unlock': t('Unlocked with password'),
    'share.unlock_failed': t('Wrong password'),
    'share.otp_sent': t('Code sent'),
    'share.otp_refused': t('Unauthorized email'),
    'share.otp_verified': t('Email verified'),
    'share.otp_failed': t('Wrong code'),
    'share.ip_denied': t('Blocked IP'),
    'share.create': t('Link created'),
    'share.update': t('Link updated'),
    'share.delete': t('Link deleted'),
    'share.regenerate': t('Link regenerated'),
  };
  return map[action] || action;
}

export function ShareDialog({ close, entry: initial, shareId }) {
  const [entry, setEntry] = useState(initial);
  const [info, setInfo] = useState(null);
  const [shares, setShares] = useState(null);
  const [mode, setMode] = useState('loading'); // list | create | edit | result
  const [form, setForm] = useState(() => blankForm(initial));
  const [editing, setEditing] = useState(null);
  const [result, setResult] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [showLog, setShowLog] = useState(false);

  const reload = async (path = entry.path) => {
    const [i, list] = await Promise.all([get('fs/info', { path }).catch(() => null), get('shares', { path }).catch(() => [])]);
    setInfo(i);
    setShares(list);
    return list;
  };

  useEffect(() => {
    reload().then((list) => {
      const target = shareId ? list.find((s) => s.id === shareId) : null;
      if (target) startEdit(target);
      else setMode(list.length ? 'list' : 'create');
    });
  }, []);

  const startEdit = (share) => {
    setEditing(share);
    setForm(formFromShare(share));
    setError('');
    setShowLog(false);
    setMode('edit');
  };

  const submit = async () => {
    setBusy(true);
    setError('');
    try {
      const data = payload(form, mode === 'edit');
      if (mode === 'edit') {
        const updated = await post('shares/' + editing.id, data);
        toast(t('Link updated.'), { type: 'success' });
        setEditing(updated);
        await reload();
        setMode('list');
      } else {
        const created = await post('shares', { ...data, path: entry.path });
        setResult({ share: created, password: form.usePassword ? form.password : '' });
        await reload();
        setMode('result');
      }
    } catch (e) {
      if (e.name !== 'AbortError') setError(e.message);
    } finally {
      setBusy(false);
    }
  };

  const shareAction = async (kind) => {
    try {
      if (kind === 'delete') {
        if (!(await confirm({ title: t('Delete this link?'), message: t('Anyone using it will immediately lose access.'), confirmLabel: t('Delete'), danger: true }))) return;
        await del('shares/' + editing.id);
        toast(t('Link deleted.'), { type: 'success' });
        const list = await reload();
        setMode(list.length ? 'list' : 'create');
        setForm(blankForm(entry));
        return;
      }
      if (kind === 'regenerate') {
        if (!(await confirm({ title: t('Generate a new link?'), message: t('The current link will stop working immediately. You will need to send the new one.'), confirmLabel: t('Generate') }))) return;
        const updated = await post('shares/' + editing.id + '/regenerate');
        setResult({ share: updated, password: '' });
        await reload();
        setMode('result');
        return;
      }
      const patch = kind === 'toggle' ? { status: editing.status === 'disabled' ? 'active' : 'disabled' } : kind === 'revoke' ? { revoke_sessions: true } : { reset_counters: true };
      const updated = await post('shares/' + editing.id, patch);
      setEditing(updated);
      setForm(formFromShare(updated));
      await reload();
      toast(kind === 'toggle' ? (updated.status === 'disabled' ? t('Link disabled.') : t('Link enabled.')) : kind === 'revoke' ? t('All visitors were signed out.') : t('Download counter reset.'), { type: 'success' });
    } catch (e) {
      toastError(e);
    }
  };

  const toVault = async () => {
    const res = await moveToVault([entry]);
    if (res && res.done && res.done.length) {
      const moved = { ...entry, path: res.done[0].to };
      setEntry(moved);
      await reload(moved.path);
    }
  };

  const exposed = info && info.url && !info.vault && entry.type === 'file';
  const title = mode === 'edit' ? t('Link settings') : t('Share “%s”', entry.name);
  let footer = null;
  if (mode === 'create' || mode === 'edit') {
    footer = (
      <>
        {mode === 'edit' ? <Button variant="ghost" icon="arrow-left" onClick={() => setMode('list')}>{t('Back')}</Button> : shares && shares.length ? <Button variant="ghost" icon="arrow-left" onClick={() => setMode('list')}>{t('Back')}</Button> : <span />}
        <span class="flr-grow" />
        <Button variant="ghost" onClick={() => close()}>{t('Cancel')}</Button>
        <Button variant="primary" icon={mode === 'edit' ? 'check' : 'link'} loading={busy} onClick={submit}>{mode === 'edit' ? t('Save changes') : t('Create link')}</Button>
      </>
    );
  } else if (mode === 'list') {
    footer = (
      <>
        <span class="flr-grow" />
        <Button variant="ghost" onClick={() => close()}>{t('Close')}</Button>
        <Button variant="primary" icon="plus" onClick={() => { setForm(blankForm(entry)); setError(''); setMode('create'); }}>{t('New link')}</Button>
      </>
    );
  }

  return (
    <Dialog title={title} subtitle={entry.path} icon="share" onClose={() => close()} size="lg" footer={footer} class="flr-share-dialog">
      {mode === 'loading' ? <div class="flr-center-fill"><Spinner size={24} /></div> : null}

      {exposed && mode !== 'result' ? (
        <div class="flr-callout flr-callout--info">
          <Icon name="globe" size={18} />
          <div>
            <strong>{t('This file also has a public URL')}</strong>
            <p>{t('Anyone who guesses its direct address can download it, with or without the share link. For confidential files, move it to the Vault: it will only be reachable through secure links.')}</p>
          </div>
          {isAdmin ? <Button size="sm" icon="shield" onClick={toVault}>{t('Move to Vault')}</Button> : null}
        </div>
      ) : null}

      {mode === 'list' && shares ? (
        <div class="flr-share-list">
          <div class="flr-share-target"><FileIcon entry={entry} size="md" /><div><strong>{entry.name}</strong><span class="flr-muted">{tn('%d active link', '%d active links', shares.filter((s) => s.status === 'active').length)}</span></div></div>
          {shares.map((s) => <ShareRow share={s} key={s.id} onEdit={() => startEdit(s)} />)}
        </div>
      ) : null}

      {(mode === 'create' || mode === 'edit') ? (
        <>
          {mode === 'edit' && editing ? (
            <div class="flr-edit-head">
              <div class="flr-link-box flr-link-box--sm">
                <Icon name="link" size={16} />
                <input readOnly value={editing.url || t('(link hidden: security keys changed — generate a new link)')} onFocus={(e) => e.currentTarget.select()} />
                {editing.url ? <IconButton icon="copy" label={t('Copy link')} onClick={async () => { if (await copyText(editing.url)) toast(t('Link copied.'), { type: 'success' }); }} /> : null}
              </div>
              <div class="flr-row flr-row--wrap">
                <Button size="sm" variant="ghost" icon={editing.status === 'disabled' ? 'unlock' : 'lock'} onClick={() => shareAction('toggle')}>{editing.status === 'disabled' ? t('Enable') : t('Disable')}</Button>
                <Button size="sm" variant="ghost" icon="refresh" onClick={() => shareAction('regenerate')}>{t('New URL')}</Button>
                <Button size="sm" variant="ghost" icon="logout" onClick={() => shareAction('revoke')}>{t('Sign out visitors')}</Button>
                {editing.maxDownloads ? <Button size="sm" variant="ghost" icon="restore" onClick={() => shareAction('reset')}>{t('Reset counter')}</Button> : null}
                <Button size="sm" variant="ghost" icon="activity" onClick={() => setShowLog(!showLog)}>{t('Activity')}</Button>
                <Button size="sm" variant="danger-ghost" icon="trash" onClick={() => shareAction('delete')}>{t('Delete')}</Button>
              </div>
              <p class="flr-muted flr-small">
                {t('Created %1$s by %2$s', formatDate(editing.createdAt), editing.owner)}
                {editing.lastAccess ? ' · ' + t('last access %s', relative(editing.lastAccess)) : ''}
              </p>
              {showLog ? <ShareLog share={editing} /> : null}
            </div>
          ) : null}
          <ShareForm form={form} setForm={setForm} entry={entry} editing={mode === 'edit'} />
          {error ? <p class="flr-callout flr-callout--danger" role="alert"><Icon name="alert" size={18} /> {error}</p> : null}
        </>
      ) : null}

      {mode === 'result' && result ? (
        <ShareResult share={result.share} password={result.password} onDone={() => close(true)} onEdit={() => startEdit(result.share)} />
      ) : null}
    </Dialog>
  );
}
