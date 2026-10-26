// Settings: General, Access & roles, Security, Sharing.
import { useEffect, useState } from 'preact/hooks';
import { Icon } from '../components/Icon';
import { Button, Card, Field, Input, Textarea, Switch, Select, Spinner, Badge, Segmented } from '../components/ui';
import { ViewHeader } from '../components/ViewHeader';
import { t } from '../lib/i18n';
import { get, post } from '../lib/api';
import { route, navigate } from '../lib/store';
import { toast, toastError } from '../lib/ui';
import { copyText } from '../lib/files';

const TABS = () => [
  { value: 'general', label: t('General'), icon: 'settings' },
  { value: 'access', label: t('Access & roles'), icon: 'users' },
  { value: 'security', label: t('Security'), icon: 'shield' },
  { value: 'sharing', label: t('Sharing'), icon: 'link' },
];

const OPS = () => ({
  download: t('Download & preview'), upload: t('Upload'), mkdir: t('Create folders'), mkfile: t('Create files'), rename: t('Rename'),
  move: t('Move'), copy: t('Copy & duplicate'), delete: t('Delete'), edit: t('Edit text files'), archive: t('Create ZIP'),
  extract: t('Extract ZIP'), chmod: t('Change permissions'), image: t('Edit images'), share: t('Create share links'),
});

const lines = (v) => (Array.isArray(v) ? v.join('\n') : v || '');
const list = (v) => v.split('\n').map((s) => s.trim()).filter(Boolean);

function Placeholders({ paths }) {
  return (
    <div class="flr-help flr-placeholders">
      {t('Placeholders:')} <code>{'{ABSPATH}'}</code> <code>{'{WP_CONTENT}'}</code> <code>{'{UPLOADS}'}</code> <code>{'{VAULT}'}</code> <code>{'{user_login}'}</code> <code>{'{user_id}'}</code> <code>{'{role}'}</code>
      <br />
      {t('Example: %s gives every user a private folder in the Vault.', '{VAULT}/clients/{user_login}')}
      {paths ? <><br />{t('Site root: %s', paths.abspath)}</> : null}
    </div>
  );
}

function General({ s, set, meta }) {
  const g = s.general;
  const up = (patch) => set({ ...s, general: { ...g, ...patch } });
  return (
    <div class="flr-settings-grid">
      <Card title={t('Root folder')} icon="folder" description={t('The highest folder administrators can browse.')}>
        <Field>
          <Input value={g.root} onInput={(v) => up({ root: v })} class="flr-mono" spellcheck={false} />
        </Field>
        <Placeholders paths={meta.paths} />
      </Card>
      <Card title={t('Safety net')} icon="restore">
        <Switch checked={g.trash} onChange={(v) => up({ trash: v })} label={t('Trash')} description={t('Deleted items go to a private trash and can be restored.')} />
        {g.trash ? <Field label={t('Empty items after (days, 0 = never)')}><Input type="number" min="0" value={g.trash_days} onInput={(v) => up({ trash_days: Number(v) })} class="flr-input--narrow" /></Field> : null}
        <Switch checked={g.versions} onChange={(v) => up({ versions: v })} label={t('File versions')} description={t('Keep previous versions of files edited in the browser.')} />
        {g.versions ? <Field label={t('Versions to keep per file')}><Input type="number" min="1" max="100" value={g.versions_keep} onInput={(v) => up({ versions_keep: Number(v) })} class="flr-input--narrow" /></Field> : null}
        <Switch checked={g.php_lint} onChange={(v) => up({ php_lint: v })} label={t('Check PHP syntax before saving')} description={t('Prevents the “white screen of death” after a typo in functions.php.')} />
        <Switch checked={g.read_only} onChange={(v) => up({ read_only: v })} label={t('Read-only mode')} description={t('Nobody can change files (useful on production sites).')} />
      </Card>
      <Card title={t('Uploads')} icon="upload">
        <Field label={t('Maximum file size (MB, 0 = unlimited)')} help={t('Large files are sent in chunks, so the PHP upload limit no longer applies.')}>
          <Input type="number" min="0" value={g.max_upload} onInput={(v) => up({ max_upload: Number(v) })} class="flr-input--narrow" />
        </Field>
      </Card>
      <Card title={t('Front-end file manager')} icon="monitor" description={t('Let logged-in users manage their files from a page of your site (client portal).')}>
        <Switch checked={g.frontend} onChange={(v) => up({ frontend: v })} label={t('Enable the [filora] shortcode')} description={t('Each user only sees the root folder of their role profile.')} />
        {g.frontend ? (
          <div class="flr-code-box"><pre>[filora height="75vh"]</pre><Button size="sm" icon="copy" onClick={async () => { if (await copyText('[filora height="75vh"]')) toast(t('Copied.'), { type: 'success' }); }}>{t('Copy')}</Button></div>
        ) : null}
      </Card>
      <Card title={t('Uninstall')} icon="trash">
        <Switch checked={g.cleanup} onChange={(v) => up({ cleanup: v })} label={t('Delete all data on uninstall')} description={t('Settings, share links, activity log, trash and versions. Files in the Vault are never deleted.')} />
      </Card>
    </div>
  );
}

function RoleCard({ role, conf, onChange, paths }) {
  const ops = OPS();
  const c = conf;
  const up = (patch) => onChange({ ...c, ...patch });
  return (
    <div class={'flr-role' + (c.enabled ? ' is-enabled' : '')}>
      <header>
        <Switch checked={c.enabled} onChange={(v) => up({ enabled: v })} label={role.name} description={c.enabled ? t('Has access to the file manager') : t('No access')} />
      </header>
      {c.enabled ? (
        <div class="flr-role-body">
          <Field label={t('Root folder')}>
            <Input value={c.root} onInput={(v) => up({ root: v })} class="flr-mono" spellcheck={false} />
          </Field>
          <Switch checked={c.create_root} onChange={(v) => up({ create_root: v })} label={t('Create the folder automatically if missing')} />
          <Field label={t('Allowed actions')}>
            <div class="flr-chips">
              {Object.keys(ops).map((op) => (
                <label key={op} class={'flr-chip' + (c.ops.includes(op) ? ' is-on' : '')}>
                  <input type="checkbox" checked={c.ops.includes(op)} onChange={(e) => up({ ops: e.currentTarget.checked ? [...c.ops, op] : c.ops.filter((x) => x !== op) })} />
                  {ops[op]}
                </label>
              ))}
            </div>
          </Field>
          <div class="flr-row flr-row--top">
            <Field label={t('Hidden items')} help={t('One pattern per line, e.g. *.log or private/')}>
              <Textarea rows={3} value={lines(c.hidden)} onInput={(v) => up({ hidden: list(v) })} class="flr-mono" spellcheck={false} />
            </Field>
            <Field label={t('Read-only items')} help={t('Visible but cannot be changed.')}>
              <Textarea rows={3} value={lines(c.locked)} onInput={(v) => up({ locked: list(v) })} class="flr-mono" spellcheck={false} />
            </Field>
          </div>
          <Field label={t('Allowed upload types')} help={t('Comma separated extensions, or * for any safe type. PHP, scripts and server configuration files are always refused.')}>
            <Input value={c.upload_exts} onInput={(v) => up({ upload_exts: v })} class="flr-mono" spellcheck={false} />
          </Field>
          <div class="flr-row">
            <Field label={t('Max file size (MB)')}><Input type="number" min="0" value={c.max_upload} onInput={(v) => up({ max_upload: Number(v) })} class="flr-input--narrow" /></Field>
            <Field label={t('Storage quota (MB)')}><Input type="number" min="0" value={c.quota} onInput={(v) => up({ quota: Number(v) })} class="flr-input--narrow" /></Field>
          </div>
        </div>
      ) : null}
    </div>
  );
}

function Access({ s, set, meta }) {
  const roles = s.roles || {};
  const g = s.general;
  const setRole = (key, conf) => set({ ...s, roles: { ...roles, [key]: conf } });
  const admins = meta.admins || [];
  return (
    <div class="flr-settings-grid">
      {!meta.multisite ? (
        <Card title={t('Administrators')} icon="shield" description={t('Administrators have full access. Restrict it to specific accounts (e.g. agency vs. client admins).')}>
          <div class="flr-chips">
            <label class={'flr-chip' + (!g.admin_users.length ? ' is-on' : '')}>
              <input type="checkbox" checked={!g.admin_users.length} onChange={() => set({ ...s, general: { ...g, admin_users: [] } })} />
              {t('All administrators')}
            </label>
            {admins.map((a) => (
              <label key={a.id} class={'flr-chip' + (g.admin_users.includes(a.id) ? ' is-on' : '')}>
                <input type="checkbox" checked={g.admin_users.includes(a.id)} onChange={(e) => set({ ...s, general: { ...g, admin_users: e.currentTarget.checked ? [...g.admin_users, a.id] : g.admin_users.filter((x) => x !== a.id) } })} />
                {a.name} <span class="flr-muted">{a.email}</span>
              </label>
            ))}
          </div>
        </Card>
      ) : (
        <Card title={t('Multisite')} icon="globe"><p class="flr-muted">{t('Super admins have full access. Give site administrators a role profile below if needed.')}</p></Card>
      )}
      <Card title={t('Role profiles')} icon="users" description={t('Give other roles their own space, permissions and limits. Non-administrators can never see secrets or upload executable files.')} class="flr-span-2">
        <Placeholders />
        <div class="flr-roles">
          {meta.roles.map((role) => (
            <RoleCard key={role.key} role={role} conf={roles[role.key] || { ...meta.roleDefaults }} paths={meta.paths} onChange={(conf) => setRole(role.key, conf)} />
          ))}
        </div>
      </Card>
    </div>
  );
}

function Security({ s, set, meta }) {
  const sec = s.security;
  const up = (patch) => set({ ...s, security: { ...sec, ...patch } });
  return (
    <div class="flr-settings-grid">
      <Card title={t('Sensitive files')} icon="key" description={t('Configuration files, keys, database dumps… are always hidden from non-administrators and can never be shared.')}>
        <Switch checked={sec.sensitive} onChange={(v) => up({ sensitive: v })} label={t('Protect sensitive files')} />
        <Field label={t('Administrators')}>
          <Select value={sec.admin_sensitive} onChange={(v) => up({ admin_sensitive: v })} options={[
            { value: 'full', label: t('Full access (edit wp-config.php…)') },
            { value: 'readonly', label: t('Read-only') },
            { value: 'hidden', label: t('Hidden') },
          ]} />
        </Field>
        <Field label={t('Patterns')} help={t('One per line.')}>
          <Textarea rows={6} value={lines(sec.sensitive_patterns)} onInput={(v) => up({ sensitive_patterns: list(v) })} class="flr-mono" spellcheck={false} />
        </Field>
        <Button size="sm" variant="ghost" icon="restore" onClick={() => up({ sensitive_patterns: meta.sensitive })}>{t('Reset to defaults')}</Button>
      </Card>
      <Card title={t('Password confirmation')} icon="shield" description={t('Like GitHub’s sudo mode: changes require the user’s password again after a while. Protects your server if a session cookie is stolen.')}>
        <Switch checked={sec.sudo} onChange={(v) => up({ sudo: v })} label={t('Require password for changes')} />
        {sec.sudo ? <Field label={t('Remember for (minutes)')}><Input type="number" min="1" max="720" value={sec.sudo_minutes} onInput={(v) => up({ sudo_minutes: Number(v) })} class="flr-input--narrow" /></Field> : null}
        <Switch checked={sec.respect_constants} onChange={(v) => up({ respect_constants: v })} label={t('Respect DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS')} description={t('When defined in wp-config.php, PHP editing (or all changes) is disabled.')} />
      </Card>
      <Card title={t('Activity log')} icon="activity">
        <Switch checked={sec.log} onChange={(v) => up({ log: v })} label={t('Record activity')} />
        <Field label={t('Keep entries for (days, 0 = forever)')}><Input type="number" min="0" value={sec.log_days} onInput={(v) => up({ log_days: Number(v) })} class="flr-input--narrow" /></Field>
        <Field label={t('IP addresses (GDPR)')}>
          <Select value={sec.log_ip} onChange={(v) => up({ log_ip: v })} options={[
            { value: 'full', label: t('Store full IP') },
            { value: 'anonymized', label: t('Anonymize (last part removed)') },
            { value: 'none', label: t('Do not store') },
          ]} />
        </Field>
      </Card>
      <Card title={t('Reverse proxy / CDN')} icon="globe" description={t('Only change this if your site is behind Cloudflare or a load balancer. Otherwise visitors could fake their IP to bypass limits.')}>
        <Field label={t('Real visitor IP header')}>
          <Select value={sec.proxy_header} onChange={(v) => up({ proxy_header: v })} options={[
            { value: '', label: t('None (REMOTE_ADDR) — recommended') },
            { value: 'HTTP_CF_CONNECTING_IP', label: 'CF-Connecting-IP (Cloudflare)' },
            { value: 'HTTP_X_FORWARDED_FOR', label: 'X-Forwarded-For' },
            { value: 'HTTP_X_REAL_IP', label: 'X-Real-IP' },
          ]} />
        </Field>
      </Card>
    </div>
  );
}

function Sharing({ s, set, meta }) {
  const sh = s.sharing;
  const up = (patch) => set({ ...s, sharing: { ...sh, ...patch } });
  const example = meta.permalinks ? meta.home + sh.slug + '/Xk2…' : meta.home + '?filora_share=Xk2…';
  return (
    <div class="flr-settings-grid">
      <Card title={t('Share links')} icon="link">
        <Switch checked={sh.enabled} onChange={(v) => up({ enabled: v })} label={t('Enable share links')} description={t('Disabling immediately blocks every existing link.')} />
        <Field label={t('URL prefix')} help={example}>
          <Input value={sh.slug} onInput={(v) => up({ slug: v })} class="flr-mono flr-input--narrow" spellcheck={false} />
        </Field>
        <div class="flr-row">
          <Field label={t('Default lifetime (days, 0 = never)')}><Input type="number" min="0" value={sh.default_days} onInput={(v) => up({ default_days: Number(v) })} class="flr-input--narrow" /></Field>
          <Field label={t('Maximum lifetime (days, 0 = no limit)')}><Input type="number" min="0" value={sh.max_days} onInput={(v) => up({ max_days: Number(v) })} class="flr-input--narrow" /></Field>
        </div>
        <Switch checked={sh.force_password} onChange={(v) => up({ force_password: v })} label={t('Require a password on every link')} />
        <Field label={t('Visitor session duration (hours)')} help={t('How long a visitor stays unlocked after entering the password or code.')}>
          <Input type="number" min="1" value={sh.session_hours} onInput={(v) => up({ session_hours: Number(v) })} class="flr-input--narrow" />
        </Field>
      </Card>
      <Card title={t('Uploads from visitors')} icon="inbox">
        <Switch checked={sh.allow_upload} onChange={(v) => up({ allow_upload: v })} label={t('Allow upload links and file requests')} />
        <Field label={t('Allowed file types')} help={t('PHP, HTML, SVG, scripts and server files are always refused.')}>
          <Textarea rows={3} value={sh.upload_exts} onInput={(v) => up({ upload_exts: v })} class="flr-mono" spellcheck={false} />
        </Field>
        <div class="flr-row">
          <Field label={t('Max file size (MB)')}><Input type="number" min="1" value={sh.upload_max} onInput={(v) => up({ upload_max: Number(v) })} class="flr-input--narrow" /></Field>
          <Field label={t('Max ZIP size (MB)')}><Input type="number" min="1" value={sh.zip_max} onInput={(v) => up({ zip_max: Number(v) })} class="flr-input--narrow" /></Field>
        </div>
        <Field label={t('Default notifications')}>
          <Select value={sh.notify} onChange={(v) => up({ notify: v })} options={[{ value: 'none', label: t('Never') }, { value: 'uploads', label: t('When files are uploaded') }, { value: 'all', label: t('On every visit, download and upload') }]} />
        </Field>
      </Card>
      <Card title={t('Branding of the share page')} icon="palette">
        <div class="flr-row">
          <Field label={t('Accent color')}><input type="color" class="flr-color" value={sh.accent} onInput={(e) => up({ accent: e.currentTarget.value })} /></Field>
          <Field label={t('Logo URL')} help={t('Defaults to the site icon.')}><Input value={sh.logo} onInput={(v) => up({ logo: v })} placeholder="https://" /></Field>
        </div>
        <Field label={t('Footer text')}><Input value={sh.footer} onInput={(v) => up({ footer: v })} placeholder={t('Private link — please do not forward it.')} /></Field>
        <Switch checked={sh.show_owner} onChange={(v) => up({ show_owner: v })} label={t('Show who shared the files')} />
        <div class="flr-brand-preview" style={{ '--accent': sh.accent }}>
          <span class="flr-brand-preview-dot" />
          <span>{t('Preview')}</span>
          <span class="flr-brand-preview-btn">{t('Download all')}</span>
        </div>
      </Card>
    </div>
  );
}

export function SettingsView() {
  const [meta, setMeta] = useState(null);
  const [draft, setDraft] = useState(null);
  const [saving, setSaving] = useState(false);
  const tab = TABS().some((x) => x.value === route.value.tab) ? route.value.tab : 'general';

  useEffect(() => {
    get('settings').then((d) => {
      setMeta(d);
      setDraft(JSON.parse(JSON.stringify(d.settings)));
    }).catch(toastError);
  }, []);

  const dirty = meta && draft && JSON.stringify(meta.settings) !== JSON.stringify(draft);
  const save = async () => {
    setSaving(true);
    try {
      const d = await post('settings', draft);
      setMeta(d);
      setDraft(JSON.parse(JSON.stringify(d.settings)));
      toast(t('Settings saved. Reload the page to apply every change.'), { type: 'success', action: { label: t('Reload'), run: () => location.reload() } });
    } catch (e) {
      toastError(e);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div class="flr-view">
      <ViewHeader icon="settings" title={t('Settings')} subtitle={t('Configure access, security and sharing.')} />
      <div class="flr-view-toolbar flr-tabs" role="tablist">
        {TABS().map((x) => (
          <button type="button" role="tab" aria-selected={String(tab === x.value)} class={'flr-tab' + (tab === x.value ? ' is-active' : '')} key={x.value} onClick={() => navigate('settings', x.value)}>
            <Icon name={x.icon} size={16} /> {x.label}
          </button>
        ))}
      </div>
      <div class="flr-view-body">
        {!draft ? <div class="flr-center-fill"><Spinner size={24} /></div> : (
          <>
            {tab === 'general' ? <General s={draft} set={setDraft} meta={meta} /> : null}
            {tab === 'access' ? <Access s={draft} set={setDraft} meta={meta} /> : null}
            {tab === 'security' ? <Security s={draft} set={setDraft} meta={meta} /> : null}
            {tab === 'sharing' ? <Sharing s={draft} set={setDraft} meta={meta} /> : null}
          </>
        )}
      </div>
      {dirty ? (
        <div class="flr-savebar" role="region" aria-label={t('Unsaved changes')}>
          <Icon name="info" size={18} />
          <span>{t('You have unsaved changes.')}</span>
          <span class="flr-grow" />
          <Button variant="ghost" onClick={() => setDraft(JSON.parse(JSON.stringify(meta.settings)))}>{t('Discard')}</Button>
          <Button variant="primary" icon="check" loading={saving} onClick={save}>{t('Save changes')}</Button>
        </div>
      ) : null}
    </div>
  );
}
