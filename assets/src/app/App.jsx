// App shell: sidebar + current view + global overlays.
import { useEffect, useState } from 'preact/hooks';
import { Sidebar } from './components/Sidebar';
import { DialogHost, ContextMenu, Toasts } from './components/Overlays';
import { UploadPanel } from './components/UploadPanel';
import { CommandPalette, paletteOpen } from './components/CommandPalette';
import { Empty, Spinner } from './components/ui';
import { FilesView } from './views/FilesView';
import { route, drawerOpen, fullscreen } from './lib/store';
import { C } from './lib/config';
import { t } from './lib/i18n';

const LOADERS = {
  shares: () => import('./views/SharesView').then((m) => m.SharesView),
  trash: () => import('./views/TrashView').then((m) => m.TrashView),
  activity: () => import('./views/ActivityView').then((m) => m.ActivityView),
  tools: () => import('./views/ToolsView').then((m) => m.ToolsView),
  settings: () => import('./views/SettingsView').then((m) => m.SettingsView),
};

function LazyView({ name }) {
  const [View, setView] = useState(null);
  useEffect(() => {
    let alive = true;
    setView(null);
    LOADERS[name]().then((Comp) => alive && setView(() => Comp)).catch(() => alive && setView(() => () => <Empty icon="alert" title={t('This screen could not be loaded.')} />));
    return () => { alive = false; };
  }, [name]);
  return View ? <View /> : <div class="flr-center-fill"><Spinner size={24} /></div>;
}

export function App() {
  const view = route.value.view;

  useEffect(() => {
    const onKey = (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        paletteOpen.value = !paletteOpen.value;
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  if (!C.profile) {
    return (
      <div class="flr-app">
        <Empty icon="lock" title={t('No access')}>{C.error || t('You do not have access to the file manager.')}</Empty>
      </div>
    );
  }

  return (
    <div class={'flr-app' + (fullscreen.value ? ' is-fullscreen' : '')}>
      <Sidebar />
      {drawerOpen.value ? <div class="flr-drawer-backdrop" onClick={() => { drawerOpen.value = false; }} /> : null}
      <main class="flr-main">
        {view === 'files' || !LOADERS[view] ? <FilesView /> : <LazyView name={view} key={view} />}
      </main>
      <UploadPanel />
      <CommandPalette />
      <DialogHost />
      <ContextMenu />
      <Toasts />
    </div>
  );
}
