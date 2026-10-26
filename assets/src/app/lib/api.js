// REST client: nonce, JSON, chunked uploads, sudo (password re-confirmation) handling.
import { C } from './config';
import { t } from './i18n';

export class ApiError extends Error {
  constructor(message, code, status, data) {
    super(message);
    this.code = code;
    this.status = status;
    this.data = data || {};
  }
}

let sudoHandler = null;
/** Register a function returning a Promise that resolves once the user confirmed their password. */
export function setSudoHandler(fn) {
  sudoHandler = fn;
}

function appendQuery(u, query) {
  for (const [key, value] of Object.entries(query || {})) {
    if (value === undefined || value === null || value === false) continue;
    if (Array.isArray(value)) value.forEach((v) => u.searchParams.append(key + '[]', v));
    else u.searchParams.set(key, value === true ? '1' : String(value));
  }
}

/** Build an endpoint URL (works with pretty and plain permalinks). */
export function url(route, query, withNonce = false) {
  const base = C.rest || '/wp-json/filora/v1/';
  let u;
  if (base.indexOf('rest_route=') !== -1) {
    u = new URL(base, location.href);
    u.searchParams.set('rest_route', u.searchParams.get('rest_route').replace(/\/?$/, '/') + route.replace(/^\//, ''));
  } else {
    u = new URL(base.replace(/\/?$/, '/') + route.replace(/^\//, ''), location.href);
  }
  appendQuery(u, query);
  if (withNonce) u.searchParams.set('_wpnonce', C.nonce);
  return u.toString();
}

async function parse(res) {
  const text = await res.text();
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch (e) {
    // PHP notices or a security plugin page instead of JSON.
    throw new ApiError(t('The server returned an unexpected response.'), 'bad_response', res.status);
  }
}

export async function request(method, route, { query, json, form, signal } = {}, retried = false) {
  const headers = { 'X-WP-Nonce': C.nonce, Accept: 'application/json' };
  let body;
  if (json !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(json);
  } else if (form) {
    body = form;
  }
  let res;
  try {
    res = await fetch(url(route, query), { method, headers, body, credentials: 'same-origin', signal });
  } catch (e) {
    if (e.name === 'AbortError') throw e;
    throw new ApiError(t('Network error. Check your connection and try again.'), 'network', 0);
  }
  const data = await parse(res);
  if (res.ok) return data;
  const code = (data && data.code) || 'http_' + res.status;
  if (code === 'filora_sudo_required' && sudoHandler && !retried) {
    await sudoHandler();
    return request(method, route, { query, json, form, signal }, true);
  }
  if (code === 'rest_cookie_invalid_nonce') {
    throw new ApiError(t('Your session expired. Please reload the page.'), code, res.status);
  }
  throw new ApiError((data && data.message) || t('Request failed (%s).', res.status), code, res.status, data && data.data);
}

export const get = (route, query, opts = {}) => request('GET', route, { ...opts, query });
export const post = (route, json, opts = {}) => request('POST', route, { ...opts, json });
export const del = (route) => request('DELETE', route);

/** Multipart POST with upload progress (XHR), sudo-aware. */
export function upload(route, form, onProgress, signal, retried = false) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url(route));
    xhr.setRequestHeader('X-WP-Nonce', C.nonce);
    xhr.responseType = 'text';
    if (onProgress) xhr.upload.onprogress = (e) => e.lengthComputable && onProgress(e.loaded, e.total);
    if (signal) {
      if (signal.aborted) return reject(new DOMException('Aborted', 'AbortError'));
      signal.addEventListener('abort', () => xhr.abort(), { once: true });
    }
    xhr.onload = () => {
      let data = null;
      try { data = xhr.responseText ? JSON.parse(xhr.responseText) : null; } catch (e) { data = null; }
      if (xhr.status >= 200 && xhr.status < 300) return resolve(data);
      const code = (data && data.code) || 'http_' + xhr.status;
      if (code === 'filora_sudo_required' && sudoHandler && !retried) {
        sudoHandler().then(() => upload(route, form, onProgress, signal, true)).then(resolve, reject);
        return;
      }
      reject(new ApiError((data && data.message) || t('Upload failed (%s).', xhr.status), code, xhr.status, data && data.data));
    };
    xhr.onerror = () => reject(new ApiError(t('Network error. Check your connection and try again.'), 'network', 0));
    xhr.onabort = () => reject(new DOMException('Aborted', 'AbortError'));
    xhr.send(form);
  });
}

/** URL for streams (download / preview / thumbnail) — cookie auth needs the nonce in the query. */
export const streamUrl = (route, query) => url(route, query, true);
