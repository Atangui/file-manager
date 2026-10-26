// Keyboard shortcuts cheat sheet (opened with "?" or from the command palette).
import { Dialog } from '../components/Overlays';
import { Kbd, modKey, keyLabel, Button } from '../components/ui';
import { t } from '../lib/i18n';
import { can } from '../lib/config';

const keys = (combo) => combo.split(' ').map((k, i) => <Kbd key={i}>{keyLabel(k)}</Kbd>);

function groups() {
  const browse = [
    ['↑ ↓', t('Move between items')],
    ['Shift+↑ Shift+↓', t('Extend the selection')],
    ['Home End', t('First / last item')],
    ['Enter', t('Open folder or file')],
    ['Space', t('Quick preview')],
    ['Backspace', t('Parent folder')],
    [modKey + '+A', t('Select all')],
    ['Esc', t('Clear selection and search')],
    [modKey + '+F', t('Filter this folder')],
    ['I', t('Details panel')],
    ['R', t('Refresh')],
    [modKey + '+.', t('Show or hide hidden files')],
  ];
  const edit = [];
  if (can('mkdir')) edit.push(['Shift+N', t('New folder')]);
  if (can('upload')) edit.push(['U', t('Upload files')]);
  if (can('rename')) edit.push(['F2', t('Rename')]);
  if (can('copy')) edit.push([modKey + '+C', t('Copy')], [modKey + '+D', t('Duplicate')]);
  if (can('move')) edit.push([modKey + '+X', t('Cut')]);
  if (can('copy') || can('move')) edit.push([modKey + '+V', t('Paste')]);
  if (can('delete')) edit.push(['Del', t('Delete')], ['Shift+Del', t('Delete permanently')]);
  const general = [
    [modKey + '+K', t('Command palette')],
    ['?', t('Keyboard shortcuts')],
    ['← →', t('Previous / next file in preview')],
    [modKey + '+S', t('Save in the editor')],
  ];
  return [
    { title: t('Browse'), items: browse },
    { title: t('Organize'), items: edit },
    { title: t('General'), items: general },
  ].filter((g) => g.items.length);
}

export function ShortcutsDialog({ close }) {
  return (
    <Dialog
      title={t('Keyboard shortcuts')}
      icon="keyboard"
      size="lg"
      onClose={() => close()}
      footer={<Button variant="primary" onClick={() => close()} data-autofocus>{t('Done')}</Button>}
    >
      <div class="flr-shortcuts">
        {groups().map((g) => (
          <section key={g.title}>
            <h3>{g.title}</h3>
            <dl>
              {g.items.map(([combo, label]) => (
                <div class="flr-shortcut" key={label}>
                  <dt>{label}</dt>
                  <dd>{keys(combo)}</dd>
                </div>
              ))}
            </dl>
          </section>
        ))}
      </div>
      <p class="flr-muted flr-shortcuts-tip">{t('Tip: drag on an empty area to select several items, and drop files from your computer anywhere to upload them.')}</p>
    </Dialog>
  );
}
