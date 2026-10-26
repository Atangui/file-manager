// Code editor (WordPress' bundled CodeMirror, loaded on demand) with find/replace, safe save
// (conflict detection + server-side PHP lint) and version history with diffs.
import { useEffect, useRef, useState } from 'preact/hooks';
import { Icon } from '../components/Icon';
import { FileIcon } from '../components/FileIcon';
import { Button, IconButton, Spinner, Badge, Kbd, modKey } from '../components/ui';
import { t, tn, formatSize, relative, formatDate } from '../lib/i18n';
import { get, post } from '../lib/api';
import { C } from '../lib/config';
import { resolvedTheme } from '../lib/store';
import { toast, toastError } from '../lib/ui';
import { confirm } from './common';
import { diffLines } from '../lib/diff';
import { addStylesheet, query } from '../lib/root';

let cmPromise = null;
function loadCodeMirror() {
  addStylesheet(C.codemirror.css);
  if (window.wp && window.wp.CodeMirror) return Promise.resolve(window.wp.CodeMirror);
  if (!cmPromise) {
    cmPromise = new Promise((resolve, reject) => {
      window.wp = window.wp || {};
      const s = document.createElement('script');
      s.src = C.codemirror.js;
      s.async = true;
      s.onload = () => (window.wp.CodeMirror ? resolve(window.wp.CodeMirror) : reject(new Error('CodeMirror')));
      s.onerror = () => {
        cmPromise = null;
        reject(new Error('CodeMirror'));
      };
      document.head.append(s);
    });
  }
  return cmPromise;
}

const MODES = {
  php: 'application/x-httpd-php', phtml: 'application/x-httpd-php', inc: 'application/x-httpd-php',
  js: 'text/javascript', mjs: 'text/javascript', cjs: 'text/javascript', jsx: 'text/jsx', ts: 'text/typescript', tsx: 'text/typescript-jsx',
  json: 'application/json', map: 'application/json',
  css: 'text/css', scss: 'text/x-scss', less: 'text/x-less', sass: 'text/x-sass',
  html: 'text/html', htm: 'text/html', twig: 'text/html', vue: 'text/html', svg: 'application/xml', xml: 'application/xml',
  md: 'text/x-markdown', markdown: 'text/x-markdown', sh: 'text/x-sh', bash: 'text/x-sh',
  sql: 'text/x-sql', yml: 'text/x-yaml', yaml: 'text/x-yaml', py: 'text/x-python',
};

function modeFor(entry) {
  return MODES[entry.ext] || 'text/plain';
}

function FindBar({ cm, onClose }) {
  const [q, setQ] = useState('');
  const [rep, setRep] = useState('');
  const [count, setCount] = useState(0);
  const input = useRef(null);
  const marks = useRef([]);
  useEffect(() => {
    setTimeout(() => input.current && input.current.focus(), 10);
    const sel = cm.getSelection();
    if (sel && sel.indexOf('\n') === -1) setQ(sel);
    return () => marks.current.forEach((m) => m.clear());
  }, []);
  useEffect(() => {
    marks.current.forEach((m) => m.clear());
    marks.current = [];
    if (!q || !cm.getSearchCursor) { setCount(0); return; }
    const cursor = cm.getSearchCursor(q, { line: 0, ch: 0 }, { caseFold: true });
    let n = 0;
    while (cursor.findNext() && n < 2000) {
      marks.current.push(cm.markText(cursor.from(), cursor.to(), { className: 'flr-cm-match' }));
      n++;
    }
    setCount(n);
  }, [q]);
  const find = (reverse) => {
    if (!q || !cm.getSearchCursor) return;
    let cursor = cm.getSearchCursor(q, reverse ? cm.getCursor('from') : cm.getCursor('to'), { caseFold: true });
    if (!(reverse ? cursor.findPrevious() : cursor.findNext())) {
      cursor = cm.getSearchCursor(q, reverse ? { line: cm.lastLine() } : { line: 0, ch: 0 }, { caseFold: true });
      if (!(reverse ? cursor.findPrevious() : cursor.findNext())) return;
    }
    cm.setSelection(cursor.from(), cursor.to());
    cm.scrollIntoView({ from: cursor.from(), to: cursor.to() }, 120);
  };
  const replaceOne = () => {
    const sel = cm.getSelection();
    if (sel && sel.toLowerCase() === q.toLowerCase()) cm.replaceSelection(rep);
    find(false);
  };
  const replaceAll = () => {
    if (!q || !cm.getSearchCursor) return;
    cm.operation(() => {
      const cursor = cm.getSearchCursor(q, { line: 0, ch: 0 }, { caseFold: true });
      let n = 0;
      while (cursor.findNext()) {
        cursor.replace(rep);
        n++;
      }
      toast(tn('%d replacement', '%d replacements', n));
    });
    setQ(q + '');
  };
  const onKey = (e) => {
    if (e.key === 'Enter') { e.preventDefault(); find(e.shiftKey); }
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); onClose(); }
  };
  return (
    <div class="flr-findbar" onKeyDown={onKey}>
      <div class="flr-findbar-field">
        <Icon name="search" size={15} />
        <input ref={input} value={q} onInput={(e) => setQ(e.currentTarget.value)} placeholder={t('Find')} spellcheck={false} />
        <span class="flr-findbar-count">{q ? count : ''}</span>
      </div>
      <IconButton icon="chevron-up" size="sm" label={t('Previous')} onClick={() => find(true)} />
      <IconButton icon="chevron-down" size="sm" label={t('Next')} onClick={() => find(false)} />
      {!cm.getOption('readOnly') ? (
        <>
          <div class="flr-findbar-field">
            <Icon name="rename" size={15} />
            <input value={rep} onInput={(e) => setRep(e.currentTarget.value)} placeholder={t('Replace with')} spellcheck={false} />
          </div>
          <Button size="sm" variant="ghost" onClick={replaceOne}>{t('Replace')}</Button>
          <Button size="sm" variant="ghost" onClick={replaceAll}>{t('All')}</Button>
        </>
      ) : null}
      <IconButton icon="x" size="sm" label={t('Close')} onClick={onClose} />
    </div>
  );
}

function Versions({ entry, current, onRestore, onLoad, onClose }) {
  const [list, setList] = useState(null);
  const [active, setActive] = useState(null);
  const [content, setContent] = useState(null);
  useEffect(() => {
    get('fs/versions', { path: entry.path }).then(setList).catch(() => setList([]));
  }, [entry.path]);
  useEffect(() => {
    if (!active) return;
    setContent(null);
    get('fs/version', { path: entry.path, id: active.id }).then((d) => setContent(d.content)).catch(toastError);
  }, [active]);
  const diff = content != null ? diffLines(content, current()) : null;
  return (
    <div class="flr-versions">
      <header>
        <strong><Icon name="history" size={16} /> {t('Version history')}</strong>
        <IconButton icon="x" size="sm" label={t('Close')} onClick={onClose} />
      </header>
      {!list ? <div class="flr-center-fill"><Spinner /></div> : !list.length ? (
        <p class="flr-muted flr-pad">{t('No previous versions yet. A version is saved automatically each time you save this file.')}</p>
      ) : (
        <div class="flr-versions-body">
          <ul class="flr-versions-list">
            {list.map((v) => (
              <li key={v.id}>
                <button type="button" class={active && active.id === v.id ? 'is-active' : ''} onClick={() => setActive(v)}>
                  <strong title={formatDate(v.time)}>{relative(v.time)}</strong>
                  <span>{v.by || '—'} · {formatSize(v.size)}{v.reason && v.reason !== 'edit' ? ' · ' + v.reason : ''}</span>
                </button>
              </li>
            ))}
          </ul>
          {active ? (
            <div class="flr-versions-diff">
              <div class="flr-versions-actions">
                {diff && !diff.tooLarge ? <span class="flr-muted"><b class="flr-add">+{diff.added}</b> <b class="flr-del">−{diff.removed}</b> {t('vs. editor')}</span> : null}
                <Button size="sm" variant="ghost" onClick={() => content != null && onLoad(content)}>{t('Load in editor')}</Button>
                <Button size="sm" variant="primary" icon="restore" onClick={() => onRestore(active)}>{t('Restore')}</Button>
              </div>
              {content == null ? <Spinner /> : diff.tooLarge ? <p class="flr-muted">{t('The difference is too large to display.')}</p> : !diff.hunks.length ? (
                <p class="flr-muted flr-pad">{t('Identical to the editor content.')}</p>
              ) : (
                <div class="flr-diff">
                  {diff.hunks.map((h, i) => (
                    <div class="flr-diff-hunk" key={i}>
                      {h.lines.map((l, j) => (
                        <div key={j} class={'flr-diff-line' + (l.type === '+' ? ' is-add' : l.type === '-' ? ' is-del' : '')}>
                          <span class="flr-diff-sign">{l.type}</span>
                          <span class="flr-diff-num">{l.type === '+' ? l.b : l.a}</span>
                          <code>{l.text || ' '}</code>
                        </div>
                      ))}
                    </div>
                  ))}
                </div>
              )}
            </div>
          ) : <p class="flr-muted flr-pad">{t('Select a version to compare it with the editor.')}</p>}
        </div>
      )}
    </div>
  );
}

export function EditorDialog({ close, entry }) {
  const host = useRef(null);
  const cmRef = useRef(null);
  const [state, setState] = useState({ loading: true });
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const [cursor, setCursor] = useState({ line: 1, ch: 1 });
  const [findOpen, setFindOpen] = useState(false);
  const [historyOpen, setHistoryOpen] = useState(false);
  const [wrap, setWrap] = useState(false);
  const meta = useRef({ hash: null, encoding: 'utf-8', saved: '' });
  const savedOnce = useRef(false);
  const dataRef = useRef(null);

  // Load file + CodeMirror in parallel.
  useEffect(() => {
    let alive = true;
    Promise.all([get('fs/read', { path: entry.path }), loadCodeMirror().catch(() => null)])
      .then(([data, CM]) => {
        if (!alive) return;
        meta.current = { hash: data.hash, encoding: data.encoding, saved: data.content };
        dataRef.current = data;
        setState({ loading: false, data, plain: !CM });
        setTimeout(() => {
          if (!host.current) return;
          if (CM) {
            const usesTabs = /^\t/m.test(data.content);
            const cm = CM(host.current, {
              value: data.content,
              mode: modeFor(entry),
              theme: 'filora',
              lineNumbers: true,
              lineWrapping: false,
              indentUnit: usesTabs ? 4 : /\.(js|jsx|ts|json|css|scss|yml|yaml|vue)$/i.test(entry.name) ? 2 : 4,
              indentWithTabs: usesTabs,
              tabSize: 4,
              matchBrackets: true,
              autoCloseBrackets: true,
              autoCloseTags: true,
              styleActiveLine: true,
              readOnly: data.readonly,
              direction: 'ltr',
              extraKeys: {
                'Ctrl-F': () => setFindOpen(true),
                'Cmd-F': () => setFindOpen(true),
                Tab: (c) => (c.somethingSelected() ? c.indentSelection('add') : c.replaceSelection(c.getOption('indentWithTabs') ? '\t' : ' '.repeat(c.getOption('indentUnit')))),
                'Shift-Tab': (c) => c.indentSelection('subtract'),
              },
            });
            cm.on('change', () => setDirty(cm.getValue() !== meta.current.saved));
            cm.on('cursorActivity', () => {
              const p = cm.getCursor();
              setCursor({ line: p.line + 1, ch: p.ch + 1 });
            });
            cmRef.current = cm;
            setTimeout(() => { cm.refresh(); cm.focus(); }, 30);
          } else {
            const ta = host.current.querySelector('textarea');
            if (ta) ta.focus();
          }
        }, 0);
      })
      .catch((e) => {
        if (!alive) return;
        setState({ loading: false, failed: e.message });
      });
    return () => { alive = false; };
  }, []);

  const value = () => (cmRef.current ? cmRef.current.getValue() : host.current && host.current.querySelector('textarea') ? host.current.querySelector('textarea').value : '');

  const save = async (force = false) => {
    if (!dataRef.current || dataRef.current.readonly || saving) return;
    const content = value();
    setSaving(true);
    setError(null);
    try {
      const res = await post('fs/write', { path: entry.path, content, hash: force ? null : meta.current.hash, encoding: meta.current.encoding });
      meta.current.hash = res.hash;
      meta.current.saved = content;
      savedOnce.current = true;
      setDirty(false);
      toast(t('Saved.'), { type: 'success', timeout: 1800 });
    } catch (e) {
      if (e.code === 'filora_conflict') {
        const ok = await confirm({ title: t('File changed on the server'), message: t('Someone (or something) modified this file since you opened it. Overwrite their changes with yours?'), confirmLabel: t('Overwrite'), danger: true });
        if (ok) {
          setSaving(false);
          return save(true);
        }
      } else if (e.code === 'filora_syntax_error') {
        setError({ message: e.message, line: e.data && e.data.line });
        if (cmRef.current && e.data && e.data.line) {
          const line = e.data.line - 1;
          cmRef.current.addLineClass(line, 'background', 'flr-cm-error-line');
          cmRef.current.scrollIntoView({ line, ch: 0 }, 120);
          const once = () => { cmRef.current.removeLineClass(line, 'background', 'flr-cm-error-line'); cmRef.current.off('change', once); };
          cmRef.current.on('change', once);
        }
      } else {
        toastError(e);
      }
    } finally {
      setSaving(false);
    }
  };

  const requestClose = async () => {
    if (dirty) {
      const ok = await confirm({ title: t('Discard changes?'), message: t('Your unsaved changes will be lost.'), confirmLabel: t('Discard'), danger: true });
      if (!ok) return;
    }
    close(savedOnce.current);
  };

  useEffect(() => {
    const onKey = (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        save();
      } else if (e.key === 'Escape' && !findOpen && !historyOpen && !query('.flr-dialog')) {
        e.preventDefault();
        requestClose();
      }
    };
    window.addEventListener('keydown', onKey);
    const beforeUnload = (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } };
    window.addEventListener('beforeunload', beforeUnload);
    return () => {
      window.removeEventListener('keydown', onKey);
      window.removeEventListener('beforeunload', beforeUnload);
    };
  });

  useEffect(() => {
    if (cmRef.current) cmRef.current.setOption('lineWrapping', wrap);
  }, [wrap]);

  const restore = async (version) => {
    const ok = await confirm({ title: t('Restore this version?'), message: t('The current content will be saved as a new version first, so you can undo this.'), confirmLabel: t('Restore') });
    if (!ok) return;
    try {
      await post('fs/versions/restore', { path: entry.path, id: version.id });
      const data = await get('fs/read', { path: entry.path });
      meta.current = { hash: data.hash, encoding: data.encoding, saved: data.content };
      if (cmRef.current) cmRef.current.setValue(data.content);
      setDirty(false);
      savedOnce.current = true;
      setHistoryOpen(false);
      toast(t('Version restored.'), { type: 'success' });
    } catch (e) {
      toastError(e);
    }
  };

  const data = state.data;
  return (
    <div class={'flr-editor' + (resolvedTheme.value === 'dark' ? ' is-dark' : '')} role="dialog" aria-modal="true" aria-label={entry.name}>
      <header class="flr-editor-head">
        <FileIcon entry={entry} size="sm" />
        <div class="flr-editor-title">
          <strong>{entry.name}{dirty ? <span class="flr-dirty" title={t('Unsaved changes')}>●</span> : null}</strong>
          <span>{entry.path}</span>
        </div>
        {data && data.readonly ? <Badge tone="warning" icon="lock">{t('Read-only')}</Badge> : null}
        <IconButton icon="search" label={t('Find & replace') + ' (' + modKey + '+F)'} active={findOpen} onClick={() => setFindOpen(!findOpen)} disabled={!cmRef.current} />
        <IconButton icon="wrap" label={t('Word wrap')} active={wrap} onClick={() => setWrap(!wrap)} />
        {C.versions ? <IconButton icon="history" label={t('Version history')} active={historyOpen} onClick={() => setHistoryOpen(!historyOpen)} /> : null}
        {data && !data.readonly ? (
          <Button variant="primary" icon="check" loading={saving} disabled={!dirty && !saving} onClick={() => save()}>
            {t('Save')} <Kbd>{modKey === '⌘' ? '⌘S' : 'Ctrl+S'}</Kbd>
          </Button>
        ) : null}
        <IconButton icon="x" label={t('Close')} onClick={requestClose} />
      </header>
      {error ? (
        <div class="flr-editor-error" role="alert">
          <Icon name="alert" size={18} />
          <span>{error.message}</span>
          {error.line && cmRef.current ? <button type="button" class="flr-link" onClick={() => { cmRef.current.setCursor({ line: error.line - 1, ch: 0 }); cmRef.current.focus(); }}>{t('Go to line %d', error.line)}</button> : null}
          <IconButton icon="x" size="sm" label={t('Close')} onClick={() => setError(null)} />
        </div>
      ) : null}
      {findOpen && cmRef.current ? <FindBar cm={cmRef.current} onClose={() => { setFindOpen(false); cmRef.current.focus(); }} /> : null}
      <div class="flr-editor-body">
        <div class="flr-editor-code" ref={host}>
          {state.loading ? <div class="flr-center-fill"><Spinner size={26} /></div> : null}
          {state.failed ? <div class="flr-center-fill"><p class="flr-muted">{state.failed}</p></div> : null}
          {state.plain && data ? (
            <textarea class="flr-plain-editor" spellcheck={false} defaultValue={data.content} readOnly={data.readonly}
              onInput={(e) => setDirty(e.currentTarget.value !== meta.current.saved)} />
          ) : null}
        </div>
        {historyOpen ? (
          <Versions entry={entry} current={value} onClose={() => setHistoryOpen(false)} onRestore={restore}
            onLoad={(content) => { if (cmRef.current) cmRef.current.setValue(content); setHistoryOpen(false); }} />
        ) : null}
      </div>
      <footer class="flr-editor-foot">
        <span>{t('Line %1$d, column %2$d', cursor.line, cursor.ch)}</span>
        <span>{modeFor(entry).replace(/^(text|application)\//, '')}</span>
        <span>{(meta.current.encoding || 'utf-8').toUpperCase()}</span>
        {data ? <span>{formatSize(data.entry.size)}</span> : null}
        {entry.ext === 'php' ? <span class="flr-muted"><Icon name="shield-check" size={13} /> {t('PHP syntax is checked before saving')}</span> : null}
      </footer>
    </div>
  );
}
