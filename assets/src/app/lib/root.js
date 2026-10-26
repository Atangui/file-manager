// DOM root helpers: the app may live in the document (wp-admin) or in a shadow root
// (front-end embed, isolated from the theme's CSS).
let root = document;

export const setRoot = (r) => {
  root = r;
};
export const getRoot = () => root;

/** The real element that originated an event, even across the shadow boundary. */
export const eventTarget = (e) => (e.composedPath ? e.composedPath()[0] : e.target);

/** The focused element inside the app. */
export const activeElement = () => (root.activeElement !== undefined && root !== document ? root.activeElement : document.activeElement);

export const query = (selector) => root.querySelector(selector);

/** Is the user typing in a field? */
export const isTyping = (e) => {
  const el = eventTarget(e);
  return !!el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT' || el.isContentEditable);
};

/** Add a stylesheet to the app root (needed inside a shadow root). */
export function addStylesheet(href) {
  const target = root === document ? document.head : root;
  if (target.querySelector('link[href="' + href + '"]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = href;
  target.append(link);
}
