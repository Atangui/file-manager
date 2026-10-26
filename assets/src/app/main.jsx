// Entry point.
import { render } from 'preact';
import { effect } from '@preact/signals';
import { App } from './App';
import { bindTheme, fullscreen } from './lib/store';
import { C } from './lib/config';
import './dialogs/common'; // Registers the sudo (password confirmation) handler.

const host = document.getElementById('filora-app');
if (host) {
  host.classList.add('flr');
  if (C.rtl) host.setAttribute('dir', 'rtl');
  bindTheme(host);
  effect(() => {
    host.classList.toggle('is-fullscreen', fullscreen.value);
    document.documentElement.classList.toggle('filora-fullscreen', fullscreen.value);
  });
  host.textContent = '';
  render(<App />, host);
}
