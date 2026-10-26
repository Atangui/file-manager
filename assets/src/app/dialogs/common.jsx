// Confirm / prompt / password (sudo) dialogs.
import { useState, useRef, useEffect } from 'preact/hooks';
import { Dialog } from '../components/Overlays';
import { Button, Field, Input } from '../components/ui';
import { openDialog } from '../lib/ui';
import { t } from '../lib/i18n';
import { post, setSudoHandler } from '../lib/api';

function ConfirmDialog({ close, title, message, confirmLabel, danger, icon, extra }) {
  return (
    <Dialog
      title={title}
      icon={icon || (danger ? 'alert' : 'info')}
      onClose={() => close(false)}
      size="sm"
      class={danger ? 'is-danger' : ''}
      footer={
        <>
          <Button variant="ghost" onClick={() => close(false)}>{t('Cancel')}</Button>
          <Button variant={danger ? 'danger' : 'primary'} onClick={() => close(true)} data-autofocus>
            {confirmLabel || t('Confirm')}
          </Button>
        </>
      }
    >
      {message ? <p class="flr-dialog-text">{message}</p> : null}
      {extra || null}
    </Dialog>
  );
}

/** Ask for confirmation. Resolves true/false. */
export const confirm = (opts) => openDialog(ConfirmDialog, opts).then(Boolean);

function PromptDialog({ close, title, label, value = '', placeholder, confirmLabel, selectBase, icon, help, validate }) {
  const [text, setText] = useState(value);
  const [error, setError] = useState('');
  const ref = useRef(null);
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    setTimeout(() => {
      el.focus();
      if (selectBase && value.lastIndexOf('.') > 0) el.setSelectionRange(0, value.lastIndexOf('.'));
      else el.select();
    }, 40);
  }, []);
  const submit = (e) => {
    e.preventDefault();
    const v = text.trim();
    if (!v) return;
    const err = validate ? validate(v) : '';
    if (err) {
      setError(err);
      return;
    }
    close(v);
  };
  return (
    <Dialog
      title={title}
      icon={icon || 'edit'}
      size="sm"
      onClose={() => close(null)}
      footer={
        <>
          <Button variant="ghost" onClick={() => close(null)}>{t('Cancel')}</Button>
          <Button variant="primary" onClick={submit} disabled={!text.trim()}>{confirmLabel || t('OK')}</Button>
        </>
      }
    >
      <form onSubmit={submit}>
        <Field label={label} help={help} error={error}>
          <input
            ref={ref}
            class="flr-input"
            value={text}
            placeholder={placeholder}
            onInput={(e) => { setText(e.currentTarget.value); setError(''); }}
            spellcheck={false}
            autocomplete="off"
          />
        </Field>
      </form>
    </Dialog>
  );
}

/** Ask for a value. Resolves the string or null. */
export const prompt = (opts) => openDialog(PromptDialog, opts);

function SudoDialog({ close }) {
  const [pw, setPw] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const submit = async (e) => {
    e.preventDefault();
    if (!pw) return;
    setBusy(true);
    setError('');
    try {
      await post('sudo', { password: pw });
      close(true);
    } catch (err) {
      setError(err.message);
      setBusy(false);
    }
  };
  return (
    <Dialog
      title={t('Confirm it’s you')}
      subtitle={t('For your security, sensitive actions require your password.')}
      icon="shield"
      size="sm"
      onClose={() => close(false)}
      footer={
        <>
          <Button variant="ghost" onClick={() => close(false)}>{t('Cancel')}</Button>
          <Button variant="primary" onClick={submit} loading={busy}>{t('Confirm')}</Button>
        </>
      }
    >
      <form onSubmit={submit}>
        <Field label={t('Your WordPress password')} error={error}>
          <Input type="password" value={pw} onInput={setPw} autocomplete="current-password" data-autofocus />
        </Field>
      </form>
    </Dialog>
  );
}

let sudoPromise = null;
setSudoHandler(() => {
  if (!sudoPromise) {
    sudoPromise = openDialog(SudoDialog, {}).then((ok) => {
      sudoPromise = null;
      if (!ok) {
        const err = new Error(t('Action cancelled.'));
        err.name = 'AbortError';
        throw err;
      }
    });
  }
  return sudoPromise;
});
