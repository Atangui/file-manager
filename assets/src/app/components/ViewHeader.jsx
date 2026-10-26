// Header used by the secondary screens.
import { Icon } from './Icon';
import { IconButton } from './ui';
import { drawerOpen } from '../lib/store';
import { t } from '../lib/i18n';

export function ViewHeader({ icon, title, subtitle, children }) {
  return (
    <header class="flr-view-head">
      <IconButton icon="menu" label={t('Menu')} class="flr-menu-btn" onClick={() => { drawerOpen.value = true; }} />
      <span class="flr-view-icon"><Icon name={icon} size={20} /></span>
      <div class="flr-view-titles">
        <h1>{title}</h1>
        {subtitle ? <p>{subtitle}</p> : null}
      </div>
      <div class="flr-view-actions">{children}</div>
    </header>
  );
}
