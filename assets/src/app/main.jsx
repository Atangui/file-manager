// Entry point.
import { render } from 'preact';
import { effect } from '@preact/signals';
import { App } from './App';
import { bindTheme, fullscreen } from './lib/store';
import { C } from './lib/config';
import { setRoot } from './lib/root';
import './dialogs/common'; // Registers the sudo (password confirmation) handler.

const host = document.getElementById('filora-app');
if (host) {
  let mount = host;
  if (C.frontend && host.attachShadow && C.css) {
    // Front-end: render inside a shadow root so the theme's CSS cannot leak in (and ours cannot leak out).
    const shadow = host.attachShadow({ mode: 'open' });
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = C.css;
    shadow.append(link);
    mount = document.createElement('div');
    mount.className = 'flr flr-shadow-root';
    shadow.append(mount);
    setRoot(shadow);
  } else {
    host.classList.add('flr');
    host.textContent = '';
  }
  if (C.rtl) mount.setAttribute('dir', 'rtl');
  bindTheme(mount);
  effect(() => {
    host.classList.toggle('is-fullscreen', fullscreen.value);
    document.documentElement.classList.toggle('filora-fullscreen', fullscreen.value);
  });
  render(<App />, mount);
}
