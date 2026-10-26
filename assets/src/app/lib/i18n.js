// Translation helpers. Strings are extracted by bin/extract-strings.mjs and translated server-side.
import { C } from './config';

const S = C.i18n || {};
export const locale = C.locale || document.documentElement.lang || 'en';
const plural = (() => {
  try { return new Intl.PluralRules(locale); } catch (e) { return new Intl.PluralRules('en'); }
})();

function sprintf(str, args) {
  let i = 0;
  return str.replace(/%(?:(\d+)\$)?([sd%])/g, (m, pos, type) => {
    if (type === '%') return '%';
    const v = pos ? args[pos - 1] : args[i++];
    return type === 'd' ? formatNumber(v) : String(v ?? '');
  });
}

/** Translate a string; extra arguments fill %s / %d / %1$s placeholders. */
export function t(text, ...args) {
  const tr = S[text];
  return sprintf(typeof tr === 'string' && tr ? tr : text, args);
}

/** Translate with plural forms; n is the first placeholder. */
export function tn(single, pluralText, n, ...args) {
  const tr = S[single];
  const forms = Array.isArray(tr) ? tr : [single, pluralText];
  return sprintf(plural.select(n) === 'one' ? forms[0] : forms[1], [n, ...args]);
}

const nf = new Intl.NumberFormat(locale);
export function formatNumber(n) {
  return nf.format(Math.round(Number(n) || 0));
}

const nf1 = new Intl.NumberFormat(locale, { maximumFractionDigits: 1 });
export function formatSize(bytes) {
  if (bytes == null) return '—';
  const units = [t('B'), t('KB'), t('MB'), t('GB'), t('TB')];
  let n = Number(bytes);
  let i = 0;
  while (n >= 1024 && i < units.length - 1) {
    n /= 1024;
    i++;
  }
  return (i ? nf1.format(n) : formatNumber(n)) + ' ' + units[i];
}

const df = new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' });
const dfDay = new Intl.DateTimeFormat(locale, { dateStyle: 'medium' });
export function formatDate(ts, withTime = true) {
  if (!ts) return '—';
  return (withTime ? df : dfDay).format(new Date(ts * 1000));
}

const rtf = (() => {
  try { return new Intl.RelativeTimeFormat(locale, { numeric: 'auto' }); } catch (e) { return null; }
})();
export function relative(ts) {
  if (!ts) return '—';
  const diff = ts * 1000 - Date.now();
  const abs = Math.abs(diff);
  if (!rtf || abs > 7 * 864e5) return formatDate(ts, abs > 7 * 864e5 ? false : true);
  const steps = [
    [60e3, 1e3, 'second'],
    [36e5, 60e3, 'minute'],
    [864e5, 36e5, 'hour'],
    [Infinity, 864e5, 'day'],
  ];
  for (const [limit, unit, name] of steps) {
    if (abs < limit) {
      if (name === 'second' && abs < 45e3) return t('just now');
      return rtf.format(Math.round(diff / unit), name);
    }
  }
  return formatDate(ts);
}
