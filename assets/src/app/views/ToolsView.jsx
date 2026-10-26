// Security tools: checklist, core integrity, suspicious files, recent changes, disk usage, server info.
import { useEffect, useState } from 'preact/hooks';
import { Icon } from '../components/Icon';
import { Button, Card, Spinner, Badge, Select, Segmented } from '../components/ui';
import { ViewHeader } from '../components/ViewHeader';
import { t, tn, formatSize, relative, formatDate } from '../lib/i18n';
import { get, post } from '../lib/api';
import { C } from '../lib/config';
import { navigate } from '../lib/store';
import { parentPath, copyText } from '../lib/files';
import { toast, toastError } from '../lib/ui';

function Check({ ok, warn, title, children, action }) {
  const state = ok ? 'ok' : warn ? 'warn' : 'bad';
  return (
    <li class={'flr-check flr-check--' + state}>
      <span class="flr-check-icon"><Icon name={ok ? 'check' : 'alert'} size={15} /></span>
      <div>
        <strong>{title}</strong>
        {children ? <p>{children}</p> : null}
      </div>
      {action || null}
    </li>
  );
}

function Score({ value }) {
  const r = 34;
  const c = 2 * Math.PI * r;
  const tone = value >= 80 ? 'ok' : value >= 50 ? 'warn' : 'bad';
  return (
    <div class={'flr-score flr-score--' + tone}>
      <svg viewBox="0 0 80 80" width="96" height="96" aria-hidden="true">
        <circle cx="40" cy="40" r={r} class="flr-score-track" />
        <circle cx="40" cy="40" r={r} class="flr-score-bar" stroke-dasharray={c} stroke-dashoffset={c * (1 - value / 100)} />
      </svg>
      <span>{value}<small>%</small></span>
    </div>
  );
}

function PathList({ items, empty, tone = 'warning' }) {
  if (!items.length) return <p class="flr-ok-text"><Icon name="check-circle" size={16} /> {empty}</p>;
  return (
    <ul class="flr-path-list">
      {items.slice(0, 200).map((p) => {
        const path = typeof p === 'string' ? '/' + p.replace(/^\//, '') : p.path;
        return (
          <li key={path}>
            <Badge tone={tone}>{typeof p === 'string' ? '!' : formatSize(p.size)}</Badge>
            <button type="button" class="flr-link flr-mono" onClick={() => navigate('files', parentPath(path))}>{path}</button>
            {p.mtime ? <span class="flr-muted">{relative(p.mtime)}</span> : null}
            {p.suspicious ? <Badge tone="danger" icon="alert">{p.suspicious}</Badge> : null}
          </li>
        );
      })}
    </ul>
  );
}

function Integrity() {
  const [res, setRes] = useState(null);
  const [busy, setBusy] = useState(false);
  const run = async () => {
    setBusy(true);
    try { setRes(await get('tools/integrity')); } catch (e) { setRes({ error: e.message }); } finally { setBusy(false); }
  };
  return (
    <Card title={t('WordPress core integrity')} icon="shield-check" description={t('Compares every core file with the official checksums from WordPress.org to detect tampering.')}
      actions={<Button variant="primary" size="sm" icon="zap" loading={busy} onClick={run}>{res ? t('Scan again') : t('Scan now')}</Button>}>
      {!res ? <p class="flr-muted">{t('Takes a few seconds. Nothing is modified.')}</p> : res.error ? <p class="flr-callout flr-callout--danger"><Icon name="alert" size={16} /> {res.error}</p> : (
        <div class="flr-stack-form">
          <p>{t('WordPress %1$s — %2$d files verified.', res.version, res.checked)}</p>
          <h4>{t('Modified core files')}</h4>
          <PathList items={res.modified} tone="danger" empty={t('No modified core file.')} />
          <h4>{t('Unknown files in core folders')}</h4>
          <PathList items={res.unknown} empty={t('No unexpected file.')} />
          {res.missing.length ? <><h4>{t('Missing core files')}</h4><PathList items={res.missing} empty="" /></> : null}
        </div>
      )}
    </Card>
  );
}

function Suspicious() {
  const [res, setRes] = useState(null);
  const [busy, setBusy] = useState(false);
  const run = async () => {
    setBusy(true);
    try { setRes(await get('tools/suspicious')); } catch (e) { toastError(e); } finally { setBusy(false); }
  };
  return (
    <Card title={t('PHP files in uploads')} icon="alert" description={t('The uploads folder should only contain media. PHP files there are the most common sign of a hacked site.')}
      actions={<Button size="sm" icon="search" loading={busy} onClick={run}>{res ? t('Scan again') : t('Scan')}</Button>}>
      {!res ? <p class="flr-muted">{t('Run a scan to check.')}</p> : <PathList items={res} tone="danger" empty={t('Nothing suspicious found.')} />}
    </Card>
  );
}

function Recent() {
  const [days, setDays] = useState('1');
  const [res, setRes] = useState(null);
  const [busy, setBusy] = useState(false);
  const run = async (d = days) => {
    setBusy(true);
    try { setRes(await get('tools/recent', { days: d })); } catch (e) { toastError(e); } finally { setBusy(false); }
  };
  return (
    <Card title={t('Recently modified files')} icon="clock" description={t('Useful after an incident, or to review what a plugin update changed.')}
      actions={<><Segmented size="sm" value={days} onChange={(v) => { setDays(v); run(v); }} options={[{ value: '1', label: t('24 h') }, { value: '7', label: t('7 days') }, { value: '30', label: t('30 days') }]} /><Button size="sm" icon="search" loading={busy} onClick={() => run()}>{t('Scan')}</Button></>}>
      {!res ? <p class="flr-muted">{t('Choose a period to list changed files.')}</p> : (
        <>
          {res.partial ? <p class="flr-muted">{t('Partial results (time limit reached).')}</p> : null}
          <PathList items={res.results} tone="neutral" empty={t('No file changed in this period.')} />
        </>
      )}
    </Card>
  );
}

function Usage() {
  const [path, setPath] = useState('/');
  const [res, setRes] = useState(null);
  const [busy, setBusy] = useState(false);
  const run = async (p = path) => {
    setBusy(true);
    setPath(p);
    try { setRes(await get('tools/usage', { path: p })); } catch (e) { toastError(e); } finally { setBusy(false); }
  };
  const max = res && res.items.length ? res.items[0].size : 1;
  return (
    <Card title={t('Disk usage')} icon="drive" description={t('Find what takes space. Click a folder to drill down.')}
      actions={<Button size="sm" icon="search" loading={busy} onClick={() => run(path)}>{res ? t('Refresh') : t('Analyze')}</Button>}>
      {!res ? <p class="flr-muted">{t('Analyzing large sites can take a moment.')}</p> : (
        <div class="flr-usage">
          <div class="flr-row">
            {res.path !== '/' ? <Button size="sm" variant="ghost" icon="arrow-up" onClick={() => run(parentPath(res.path))}>{t('Up')}</Button> : null}
            <code>{res.path}</code>
            <span class="flr-muted">{formatSize(res.total)}</span>
          </div>
          <ul>
            {res.items.slice(0, 25).map((it) => (
              <li key={it.path}>
                <button type="button" disabled={it.type !== 'dir'} onClick={() => run(it.path)}>
                  <Icon name={it.type === 'dir' ? 'folder' : 'file'} size={15} />
                  <span class="flr-ellipsis">{it.name}</span>
                  <span class="flr-usage-bar"><i style={{ width: Math.max(1, (it.size / max) * 100) + '%' }} /></span>
                  <span class="flr-muted flr-nowrap">{formatSize(it.size)}</span>
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </Card>
  );
}

export function ToolsView() {
  const [server, setServer] = useState(null);
  const [testing, setTesting] = useState(false);
  useEffect(() => {
    get('tools/server').then(setServer).catch(toastError);
  }, []);
  const retest = async () => {
    setTesting(true);
    try {
      const protection = await post('tools/selftest');
      setServer({ ...server, protection });
    } catch (e) {
      toastError(e);
    } finally {
      setTesting(false);
    }
  };

  const checks = server ? [
    { key: 'https', ok: server.https, title: t('HTTPS'), text: server.https ? t('Share links and passwords travel encrypted.') : t('Without HTTPS, links, passwords and files can be intercepted. Enable SSL on your host.') },
    { key: 'vault', ok: server.protection.status === 'protected', warn: server.protection.status === 'unknown', title: t('Vault protection'), text: server.protection.status === 'protected' ? t('Files in the Vault cannot be opened with a direct URL.') : server.protection.status === 'exposed' ? t('Your server ignores .htaccess (Nginx?). Add the rule below to your server configuration.') : t('The automatic test could not reach your site. Check manually.') },
    { key: 'sudo', ok: C.sudo, warn: true, title: t('Password confirmation (sudo mode)'), text: C.sudo ? t('Sensitive actions require the password again.') : t('Recommended: protects files even if an admin session cookie is stolen.') },
    { key: 'trash', ok: C.trash && C.trash.enabled, warn: true, title: t('Trash'), text: t('Deleted items can be restored.') },
    { key: 'versions', ok: C.versions, warn: true, title: t('File versions'), text: t('Edited files keep previous versions.') },
    { key: 'force', ok: C.sharing.forcePassword || C.sharing.maxDays > 0, warn: true, title: t('Share link policy'), text: t('Require a password or a maximum lifetime for every link.') },
    { key: 'zip', ok: server.zip, warn: true, title: t('ZIP support'), text: server.zip ? t('ZipArchive is available.') : t('Install the PHP zip extension to compress and extract archives.') },
    { key: 'php', ok: Number(server.php.split('.')[0]) >= 8, warn: true, title: t('PHP %s', server.php), text: t('PHP 8.1 or newer is recommended for security updates.') },
  ] : [];
  const score = checks.length ? Math.round((checks.filter((c) => c.ok).length / checks.length) * 100) : 0;

  return (
    <div class="flr-view">
      <ViewHeader icon="shield-check" title={t('Security tools')} subtitle={t('Check your setup, detect tampered files and keep your server healthy.')} />
      <div class="flr-view-body flr-tools">
        {!server ? <div class="flr-center-fill"><Spinner size={24} /></div> : (
          <>
            <Card class="flr-score-card">
              <div class="flr-score-wrap">
                <Score value={score} />
                <div>
                  <h2>{score >= 80 ? t('Well protected') : score >= 50 ? t('Good, can be improved') : t('Needs attention')}</h2>
                  <p class="flr-muted">{t('Security checklist for the file manager and share links.')}</p>
                  {C.user.canSettings ? <Button size="sm" variant="ghost" icon="settings" onClick={() => navigate('settings', 'security')}>{t('Open security settings')}</Button> : null}
                </div>
              </div>
              <ul class="flr-checks">
                {checks.map((c) => (
                  <Check key={c.key} ok={c.ok} warn={c.warn} title={c.title}
                    action={c.key === 'vault' ? <Button size="sm" variant="ghost" icon="refresh" loading={testing} onClick={retest}>{t('Test again')}</Button> : null}>
                    {c.text}
                  </Check>
                ))}
              </ul>
              {server.protection.status === 'exposed' ? (
                <div class="flr-code-box">
                  <pre>{server.nginx}</pre>
                  <Button size="sm" icon="copy" onClick={async () => { if (await copyText(server.nginx)) toast(t('Copied.'), { type: 'success' }); }}>{t('Copy')}</Button>
                </div>
              ) : null}
            </Card>
            <div class="flr-tools-grid">
              <Integrity />
              <Suspicious />
              <Recent />
              <Usage />
            </div>
            <Card title={t('Server')} icon="terminal">
              <dl class="flr-kv">
                <dt>{t('PHP')}</dt><dd>{server.php}</dd>
                <dt>{t('WordPress')}</dt><dd>{server.wp}</dd>
                <dt>{t('Web server')}</dt><dd>{server.server || '—'}</dd>
                <dt>{t('PHP upload limit')}</dt><dd>{formatSize(server.uploadMax)} <span class="flr-muted">— {t('bypassed with %s chunks', formatSize(server.chunkSize))}</span></dd>
                <dt>{t('Memory limit')}</dt><dd>{server.memory}</dd>
                <dt>{t('Max execution time')}</dt><dd>{server.maxExecution ? server.maxExecution + ' s' : t('unlimited')}</dd>
                <dt>{t('Image editing')}</dt><dd>{server.imageEditor ? t('Available') + (server.webp ? ' · WebP' : '') : t('Unavailable')}</dd>
                <dt>{t('Encryption')}</dt><dd>{server.sodium ? 'libsodium' : 'OpenSSL'}</dd>
                <dt>{t('Disk space')}</dt><dd>{server.diskFree != null ? t('%1$s free of %2$s', formatSize(server.diskFree), formatSize(server.diskTotal)) : '—'}</dd>
                <dt>{t('Private storage')}</dt><dd>{server.privateDir ? t('Outside the web root (FILORA_PRIVATE_DIR)') : t('Protected folder in uploads')}</dd>
              </dl>
            </Card>
          </>
        )}
      </div>
    </div>
  );
}
