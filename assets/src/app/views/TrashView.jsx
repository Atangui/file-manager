// Trash: restore or permanently delete.
import { useEffect, useState } from 'preact/hooks';
import { Icon } from '../components/Icon';
import { Button, IconButton, Spinner, Empty } from '../components/ui';
import { ViewHeader } from '../components/ViewHeader';
import { t, tn, relative, formatDate, formatSize } from '../lib/i18n';
import { get, post } from '../lib/api';
import { toast, toastError } from '../lib/ui';
import { confirm } from '../dialogs/common';
import { navigate } from '../lib/store';
import { parentPath } from '../lib/files';

export function TrashView() {
  const [data, setData] = useState(null);
  const [sel, setSel] = useState(new Set());
  const reload = () => get('trash').then((d) => { setData(d); setSel(new Set()); }).catch((e) => { toastError(e); setData({ items: [] }); });
  useEffect(() => { reload(); }, []);

  const restore = async (ids) => {
    try {
      const res = await post('trash/restore', { ids });
      if (res.errors.length) toast(res.errors[0].message, { type: 'error' });
      else toast(tn('%d item restored.', '%d items restored.', res.done.length), { type: 'success', action: res.done.length === 1 ? { label: t('Show'), run: () => navigate('files', parentPath(res.done[0])) } : null });
      reload();
    } catch (e) {
      toastError(e);
    }
  };
  const purge = async (ids, all = false) => {
    const ok = await confirm({
      title: all ? t('Empty the trash?') : t('Delete permanently?'),
      message: all ? t('All items in the trash will be permanently deleted. This cannot be undone.') : tn('%d item will be permanently deleted. This cannot be undone.', '%d items will be permanently deleted. This cannot be undone.', ids.length),
      confirmLabel: all ? t('Empty trash') : t('Delete'),
      danger: true,
    });
    if (!ok) return;
    try {
      const res = await post('trash/purge', all ? { all: true } : { ids });
      toast(tn('%d item deleted.', '%d items deleted.', res.purged), { type: 'success' });
      reload();
    } catch (e) {
      toastError(e);
    }
  };

  const items = data ? data.items : [];
  const toggle = (id) => {
    const next = new Set(sel);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    setSel(next);
  };
  return (
    <div class="flr-view">
      <ViewHeader icon="trash" title={t('Trash')} subtitle={data && data.days ? t('Deleted items are kept for %d days, then removed automatically.', data.days) : t('Deleted items are kept until you empty the trash.')}>
        {sel.size ? (
          <>
            <Button icon="restore" onClick={() => restore([...sel])}>{t('Restore')} ({sel.size})</Button>
            <Button variant="danger-ghost" icon="trash" onClick={() => purge([...sel])}>{t('Delete')}</Button>
          </>
        ) : items.length ? <Button variant="danger-ghost" icon="trash" onClick={() => purge([], true)}>{t('Empty trash')}</Button> : null}
      </ViewHeader>
      <div class="flr-view-body">
        {!data ? <div class="flr-center-fill"><Spinner size={24} /></div> : !items.length ? (
          <Empty icon="trash" title={t('The trash is empty')}>{t('Deleted files and folders appear here and can be restored with one click.')}</Empty>
        ) : (
          <div class="flr-table-wrap">
            <table class="flr-table">
              <thead>
                <tr>
                  <th class="flr-col-check"><input type="checkbox" aria-label={t('Select all')} checked={sel.size === items.length} onChange={() => setSel(sel.size === items.length ? new Set() : new Set(items.map((i) => i.id)))} /></th>
                  <th>{t('Name')}</th>
                  <th class="flr-hide-md">{t('Original location')}</th>
                  <th>{t('Deleted')}</th>
                  <th class="flr-hide-md">{t('Size')}</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {items.map((it) => (
                  <tr key={it.id} class={sel.has(it.id) ? 'is-selected' : ''} onClick={() => toggle(it.id)}>
                    <td class="flr-col-check"><input type="checkbox" checked={sel.has(it.id)} onClick={(e) => e.stopPropagation()} onChange={() => toggle(it.id)} aria-label={it.name} /></td>
                    <td><div class="flr-cell-item"><span class={'flr-tile flr-tile--sm flr-tile--' + (it.type === 'dir' ? 'dir' : 'file')}><Icon name={it.type === 'dir' ? 'folder' : 'file'} size={15} /></span><strong>{it.name}</strong></div></td>
                    <td class="flr-hide-md flr-muted flr-ellipsis" title={it.path}>{parentPath(it.path)}</td>
                    <td class="flr-nowrap" title={formatDate(it.deleted_at)}>{relative(it.deleted_at)}{it.deletedBy ? <span class="flr-muted"> · {it.deletedBy}</span> : null}</td>
                    <td class="flr-hide-md flr-muted">{it.size == null ? '—' : formatSize(it.size)}</td>
                    <td class="flr-cell-actions" onClick={(e) => e.stopPropagation()}>
                      <IconButton icon="restore" size="sm" label={t('Restore')} onClick={() => restore([it.id])} />
                      <IconButton icon="trash" size="sm" label={t('Delete permanently')} onClick={() => purge([it.id])} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
