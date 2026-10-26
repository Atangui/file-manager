// Builds languages/filora.pot from the PHP sources (including the generated JS string table).
// Usage: node bin/make-pot.mjs
import { readFileSync, writeFileSync, readdirSync, statSync, mkdirSync } from 'node:fs';
import { join, relative } from 'node:path';

const ROOTS = ['filora.php', 'uninstall.php', 'includes', 'templates'];
const files = [];
const walk = (p) => {
  if (statSync(p).isDirectory()) readdirSync(p).forEach((n) => walk(join(p, n)));
  else if (p.endsWith('.php')) files.push(p);
};
ROOTS.forEach(walk);

// PHP string literal (single or double quoted).
const LIT = String.raw`(?:'((?:\\.|[^'\\])*)'|"((?:\\.|[^"\\])*)")`;
const unq = (single, double) => {
  if (single !== undefined) return single.replace(/\\(['\\])/g, '$1');
  return double.replace(/\\(["\\$])/g, '$1').replace(/\\n/g, '\n').replace(/\\t/g, '\t');
};
const FUNCS = {
  single: ['__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e'],
  context: ['_x', '_ex', 'esc_html_x', 'esc_attr_x'],
  plural: ['_n', '_n_noop'],
};

const entries = new Map(); // key -> { msgid, plural, ctx, refs:Set, comments:Set }
const add = (msgid, extra, ref, comment) => {
  const key = (extra.ctx || '') + '\u0004' + msgid;
  if (!entries.has(key)) entries.set(key, { msgid, plural: extra.plural, ctx: extra.ctx, refs: new Set(), comments: new Set() });
  const e = entries.get(key);
  e.refs.add(ref);
  if (comment) e.comments.add(comment);
};

for (const file of files.sort()) {
  const code = readFileSync(file, 'utf8');
  const lineOf = (idx) => code.slice(0, idx).split('\n').length;
  // Nearest "/* translators: … */" comment, only if no statement or other string sits in between.
  const commentBefore = (idx) => {
    const before = code.slice(Math.max(0, idx - 600), idx);
    const start = before.lastIndexOf('/* translators:');
    if (start === -1) return '';
    const end = before.indexOf('*/', start);
    if (end === -1) return '';
    const gap = before.slice(end + 2);
    if (/[;{}]/.test(gap) || gap.includes("'filora'")) return '';
    return 'translators: ' + before.slice(start + 15, end).trim();
  };
  const ref = (idx) => relative('.', file) + ':' + lineOf(idx);
  for (const fn of FUNCS.single) {
    const re = new RegExp(String.raw`(?<![\w$>:])` + fn + String.raw`\(\s*` + LIT + String.raw`\s*,\s*'filora'\s*\)`, 'g');
    for (const m of code.matchAll(re)) add(unq(m[1], m[2]), {}, ref(m.index), commentBefore(m.index));
  }
  for (const fn of FUNCS.context) {
    const re = new RegExp(String.raw`(?<![\w$>:])` + fn + String.raw`\(\s*` + LIT + String.raw`\s*,\s*` + LIT + String.raw`\s*,\s*'filora'\s*\)`, 'g');
    for (const m of code.matchAll(re)) add(unq(m[1], m[2]), { ctx: unq(m[3], m[4]) }, ref(m.index), commentBefore(m.index));
  }
  for (const fn of FUNCS.plural) {
    const re = new RegExp(String.raw`(?<![\w$>:])` + fn + String.raw`\(\s*` + LIT + String.raw`\s*,\s*` + LIT + String.raw`\s*,[^;]*?'filora'\s*\)`, 'g');
    for (const m of code.matchAll(re)) add(unq(m[1], m[2]), { plural: unq(m[3], m[4]) }, ref(m.index), commentBefore(m.index));
  }
}

const esc = (s) => s.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\t/g, '\\t');
const date = new Date().toISOString().replace('T', ' ').slice(0, 16) + '+0000';
let out = `# Copyright (C) ${new Date().getFullYear()} Filora contributors
# This file is distributed under the GPL-2.0-or-later.
msgid ""
msgstr ""
"Project-Id-Version: Filora 1.0.1\\n"
"Report-Msgid-Bugs-To: https://github.com/atangui/file-manager/issues\\n"
"POT-Creation-Date: ${date}\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"X-Domain: filora\\n"

`;
for (const e of entries.values()) {
  for (const c of e.comments) out += `#. ${c.replace(/\n/g, ' ')}\n`;
  out += `#: ${[...e.refs].slice(0, 4).join(' ')}\n`;
  if (/%(\d\$)?[sd]/.test(e.msgid)) out += '#, php-format\n';
  if (e.ctx) out += `msgctxt "${esc(e.ctx)}"\n`;
  out += `msgid "${esc(e.msgid)}"\n`;
  if (e.plural !== undefined) out += `msgid_plural "${esc(e.plural)}"\nmsgstr[0] ""\nmsgstr[1] ""\n\n`;
  else out += 'msgstr ""\n\n';
}
mkdirSync('languages', { recursive: true });
writeFileSync('languages/filora.pot', out);
console.log(`POT: ${entries.size} entries from ${files.length} files → languages/filora.pot`);
