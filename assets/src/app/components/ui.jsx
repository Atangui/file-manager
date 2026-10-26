// Small, reusable UI primitives.
import { Icon } from './Icon';

export function Button({ variant = 'default', size, icon, iconRight, loading, children, class: cls = '', ...rest }) {
  const classes = ['flr-btn', 'flr-btn--' + variant, size ? 'flr-btn--' + size : '', !children ? 'flr-btn--icon' : '', cls].filter(Boolean).join(' ');
  return (
    <button type="button" class={classes} disabled={loading || rest.disabled} aria-busy={loading ? 'true' : undefined} {...rest}>
      {loading ? <Spinner size={16} /> : icon ? <Icon name={icon} size={size === 'sm' ? 16 : 18} /> : null}
      {children != null && children !== false ? <span class="flr-btn-label">{children}</span> : null}
      {iconRight ? <Icon name={iconRight} size={16} /> : null}
    </button>
  );
}

export function IconButton({ icon, label, size, active, class: cls = '', ...rest }) {
  return (
    <button
      type="button"
      class={'flr-iconbtn' + (size ? ' flr-iconbtn--' + size : '') + (active ? ' is-active' : '') + (cls ? ' ' + cls : '')}
      aria-label={label}
      title={label}
      aria-pressed={active === undefined ? undefined : String(!!active)}
      {...rest}
    >
      <Icon name={icon} size={size === 'sm' ? 16 : 19} />
    </button>
  );
}

export const Spinner = ({ size = 18 }) => <span class="flr-spinner" style={{ width: size + 'px', height: size + 'px' }} role="progressbar" />;

export function Switch({ checked, onChange, label, description, disabled }) {
  return (
    <label class={'flr-switch' + (disabled ? ' is-disabled' : '')}>
      <span class="flr-switch-text">
        {label ? <span class="flr-switch-label">{label}</span> : null}
        {description ? <span class="flr-help">{description}</span> : null}
      </span>
      <input type="checkbox" role="switch" checked={!!checked} disabled={disabled} onChange={(e) => onChange(e.currentTarget.checked)} />
      <span class="flr-switch-track" aria-hidden="true"><span class="flr-switch-thumb" /></span>
    </label>
  );
}

export function Field({ label, help, error, children, inline, class: cls = '' }) {
  return (
    <div class={'flr-field' + (inline ? ' flr-field--inline' : '') + (cls ? ' ' + cls : '')}>
      {label ? <label class="flr-label">{label}</label> : null}
      <div class="flr-field-control">{children}</div>
      {error ? <p class="flr-error-text">{error}</p> : help ? <p class="flr-help">{help}</p> : null}
    </div>
  );
}

export const Input = ({ class: cls = '', onInput, ...rest }) => (
  <input class={'flr-input ' + cls} onInput={onInput ? (e) => onInput(e.currentTarget.value, e) : undefined} {...rest} />
);

export const Textarea = ({ class: cls = '', onInput, ...rest }) => (
  <textarea class={'flr-input flr-textarea ' + cls} onInput={onInput ? (e) => onInput(e.currentTarget.value, e) : undefined} {...rest} />
);

export function Select({ value, onChange, options, class: cls = '', ...rest }) {
  return (
    <span class={'flr-select ' + cls}>
      <select value={value} onChange={(e) => onChange(e.currentTarget.value)} {...rest}>
        {options.map((o) => (
          <option value={o.value} key={o.value}>{o.label}</option>
        ))}
      </select>
      <Icon name="chevron-down" size={16} />
    </span>
  );
}

export function Segmented({ value, onChange, options, size, label }) {
  return (
    <div class={'flr-seg' + (size ? ' flr-seg--' + size : '')} role="radiogroup" aria-label={label}>
      {options.map((o) => (
        <button
          type="button"
          role="radio"
          aria-checked={String(value === o.value)}
          class={value === o.value ? 'is-active' : ''}
          onClick={() => onChange(o.value)}
          title={o.title || (o.icon && !o.label ? o.value : undefined)}
          key={o.value}
        >
          {o.icon ? <Icon name={o.icon} size={16} /> : null}
          {o.label ? <span>{o.label}</span> : null}
        </button>
      ))}
    </div>
  );
}

export const Badge = ({ tone = 'neutral', children, icon }) => (
  <span class={'flr-badge flr-badge--' + tone}>
    {icon ? <Icon name={icon} size={13} /> : null}
    {children}
  </span>
);

export const Kbd = ({ children }) => <kbd class="flr-kbd">{children}</kbd>;

export function Empty({ icon = 'folder', title, children, action }) {
  return (
    <div class="flr-empty">
      <div class="flr-empty-icon"><Icon name={icon} size={30} /></div>
      {title ? <h3>{title}</h3> : null}
      {children ? <p>{children}</p> : null}
      {action || null}
    </div>
  );
}

export function Card({ title, icon, actions, children, class: cls = '', description }) {
  return (
    <section class={'flr-card ' + cls}>
      {title ? (
        <header class="flr-card-head">
          {icon ? <span class="flr-card-icon"><Icon name={icon} size={18} /></span> : null}
          <div class="flr-card-titles">
            <h3>{title}</h3>
            {description ? <p class="flr-help">{description}</p> : null}
          </div>
          {actions ? <div class="flr-card-actions">{actions}</div> : null}
        </header>
      ) : null}
      <div class="flr-card-body">{children}</div>
    </section>
  );
}

export const isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
export const modKey = isMac ? '⌘' : 'Ctrl';
