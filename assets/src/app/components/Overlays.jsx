// Dialog shell, dialog host, context menu and toasts.
import { useEffect, useRef, useState, useLayoutEffect } from 'preact/hooks';
import { dialogs, closeDialog, menu, closeMenu, toasts, dismiss } from '../lib/ui';
import { Icon } from './Icon';
import { Button, IconButton } from './ui';
import { t } from '../lib/i18n';
import { activeElement, eventTarget } from '../lib/root';

const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

export function Dialog({ title, subtitle, icon, onClose, children, footer, size = 'md', class: cls = '', dismissable = true, bodyClass = '' }) {
  const ref = useRef(null);
  useEffect(() => {
    const el = ref.current;
    const previous = activeElement();
    const first = el && (el.querySelector('[data-autofocus]') || el.querySelector('.flr-dialog-body ' + FOCUSABLE) || el.querySelector(FOCUSABLE));
    if (first) setTimeout(() => first.focus({ preventScroll: true }), 30);
    const onKey = (e) => {
      if (e.key === 'Escape' && dismissable) {
        e.stopPropagation();
        onClose();
      } else if (e.key === 'Tab' && el) {
        const items = Array.from(el.querySelectorAll(FOCUSABLE)).filter((n) => n.offsetParent !== null);
        if (!items.length) return;
        const firstEl = items[0];
        const lastEl = items[items.length - 1];
        const current = activeElement();
        if (e.shiftKey && current === firstEl) {
          e.preventDefault();
          lastEl.focus();
        } else if (!e.shiftKey && current === lastEl) {
          e.preventDefault();
          firstEl.focus();
        }
      }
    };
    el.addEventListener('keydown', onKey);
    return () => {
      el.removeEventListener('keydown', onKey);
      if (previous && previous.focus) previous.focus({ preventScroll: true });
    };
  }, []);
  return (
    <div class="flr-backdrop" onMouseDown={(e) => { if (e.target === e.currentTarget && dismissable) onClose(); }}>
      <div class={'flr-dialog flr-dialog--' + size + ' ' + cls} role="dialog" aria-modal="true" aria-label={typeof title === 'string' ? title : undefined} ref={ref}>
        {title ? (
          <header class="flr-dialog-head">
            {icon ? <span class="flr-dialog-icon"><Icon name={icon} size={20} /></span> : null}
            <div class="flr-dialog-titles">
              <h2>{title}</h2>
              {subtitle ? <p>{subtitle}</p> : null}
            </div>
            {dismissable ? <IconButton icon="x" label={t('Close')} onClick={onClose} /> : null}
          </header>
        ) : null}
        <div class={'flr-dialog-body ' + bodyClass}>{children}</div>
        {footer ? <footer class="flr-dialog-foot">{footer}</footer> : null}
      </div>
    </div>
  );
}

export function DialogHost() {
  return (
    <>
      {dialogs.value.map((d) => (
        <d.Component key={d.id} {...d.props} close={(value) => closeDialog(d.id, value)} />
      ))}
    </>
  );
}

export function ContextMenu() {
  const m = menu.value;
  const ref = useRef(null);
  const [pos, setPos] = useState(null);
  const [active, setActive] = useState(-1);

  useLayoutEffect(() => {
    if (!m || !ref.current) {
      setPos(null);
      return;
    }
    const r = ref.current.getBoundingClientRect();
    let x = m.x;
    let y = m.y;
    if (x + r.width > window.innerWidth - 8) x = Math.max(8, window.innerWidth - r.width - 8);
    if (y + r.height > window.innerHeight - 8) y = Math.max(8, Math.min(m.y - r.height, window.innerHeight - r.height - 8));
    setPos({ left: x, top: y });
    setActive(-1);
    ref.current.focus({ preventScroll: true });
  }, [m]);

  useEffect(() => {
    if (!m) return;
    const close = (e) => {
      if (ref.current && (e.composedPath ? e.composedPath().includes(ref.current) : ref.current.contains(eventTarget(e)))) return;
      closeMenu();
    };
    const onScroll = () => closeMenu();
    window.addEventListener('mousedown', close, true);
    window.addEventListener('resize', onScroll);
    window.addEventListener('blur', onScroll);
    return () => {
      window.removeEventListener('mousedown', close, true);
      window.removeEventListener('resize', onScroll);
      window.removeEventListener('blur', onScroll);
    };
  }, [m]);

  if (!m) return null;
  const items = m.items;
  const actionable = items.map((it, i) => (it !== '-' && !it.disabled ? i : -1)).filter((i) => i !== -1);
  const run = (it) => {
    closeMenu();
    if (it && it.run) it.run();
  };
  const onKey = (e) => {
    if (e.key === 'Escape') {
      e.preventDefault();
      closeMenu();
    } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const idx = actionable.indexOf(active);
      const next = e.key === 'ArrowDown' ? actionable[(idx + 1) % actionable.length] : actionable[(idx - 1 + actionable.length) % actionable.length];
      setActive(next);
    } else if (e.key === 'Enter' && active >= 0) {
      e.preventDefault();
      run(items[active]);
    }
  };
  return (
    <div
      class="flr-menu"
      ref={ref}
      tabIndex={-1}
      role="menu"
      onKeyDown={onKey}
      onContextMenu={(e) => e.preventDefault()}
      style={pos ? { left: pos.left + 'px', top: pos.top + 'px' } : { visibility: 'hidden', left: 0, top: 0 }}
    >
      {items.map((it, i) =>
        it === '-' ? (
          <div class="flr-menu-sep" role="separator" key={'s' + i} />
        ) : it.header ? (
          <div class="flr-menu-header" key={'h' + i}>{it.header}</div>
        ) : (
          <button
            type="button"
            role="menuitem"
            key={i}
            class={'flr-menu-item' + (it.danger ? ' is-danger' : '') + (active === i ? ' is-active' : '')}
            disabled={it.disabled}
            onMouseEnter={() => setActive(i)}
            onClick={() => run(it)}
          >
            <Icon name={it.icon || 'more-h'} size={17} class={it.icon ? '' : 'is-blank'} />
            <span class="flr-menu-label">{it.label}</span>
            {it.kbd ? <span class="flr-menu-kbd">{it.kbd}</span> : null}
          </button>
        )
      )}
    </div>
  );
}

export function Toasts() {
  return (
    <div class="flr-toasts" role="status" aria-live="polite">
      {toasts.value.map((x) => (
        <div class={'flr-toast flr-toast--' + x.type} key={x.id}>
          <Icon name={x.type === 'error' ? 'x-circle' : x.type === 'success' ? 'check-circle' : x.type === 'warning' ? 'alert' : 'info'} size={18} />
          <span class="flr-toast-msg">{x.message}</span>
          {x.action ? (
            <Button size="sm" variant="ghost" onClick={() => { dismiss(x.id); x.action.run(); }}>
              {x.action.label}
            </Button>
          ) : null}
          <button type="button" class="flr-toast-close" aria-label={t('Close')} onClick={() => dismiss(x.id)}>
            <Icon name="x" size={14} />
          </button>
        </div>
      ))}
    </div>
  );
}
