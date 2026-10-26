// Compiles languages/*.po into .mo files (GNU gettext binary format), without needing gettext installed.
// Usage: node bin/compile-mo.mjs
import { readFileSync, writeFileSync, readdirSync } from 'node:fs';

function parsePo(text) {
  const entries = [];
  let cur = null;
  let field = null;
  const unesc = (s) => s.replace(/\\(n|t|"|\\)/g, (_, c) => ({ n: '\n', t: '\t', '"': '"', '\\': '\\' }[c]));
  const flush = () => {
    if (cur && cur.msgid !== undefined) entries.push(cur);
    cur = { msgstr: [] };
    field = null;
  };
  cur = { msgstr: [] };
  for (const raw of text.split(/\r?\n/)) {
    const line = raw.trim();
    if (!line) { flush(); continue; }
    if (line.startsWith('#')) {
      if (line.startsWith('#,') && line.includes('fuzzy')) cur.fuzzy = true;
      continue;
    }
    let m;
    if ((m = line.match(/^msgctxt\s+"(.*)"$/))) { cur.ctx = unesc(m[1]); field = ['ctx']; }
    else if ((m = line.match(/^msgid\s+"(.*)"$/))) { if (cur.msgid !== undefined) flush(); cur.msgid = unesc(m[1]); field = ['msgid']; }
    else if ((m = line.match(/^msgid_plural\s+"(.*)"$/))) { cur.plural = unesc(m[1]); field = ['plural']; }
    else if ((m = line.match(/^msgstr\s+"(.*)"$/))) { cur.msgstr[0] = unesc(m[1]); field = ['msgstr', 0]; }
    else if ((m = line.match(/^msgstr\[(\d+)\]\s+"(.*)"$/))) { cur.msgstr[Number(m[1])] = unesc(m[2]); field = ['msgstr', Number(m[1])]; }
    else if ((m = line.match(/^"(.*)"$/)) && field) {
      const v = unesc(m[1]);
      if (field[0] === 'msgstr') cur.msgstr[field[1]] += v;
      else cur[field[0]] += v;
    }
  }
  flush();
  return entries;
}

function compile(entries) {
  const pairs = [];
  for (const e of entries) {
    if (e.fuzzy && e.msgid !== '') continue;
    const translated = e.msgstr.filter((s) => s !== undefined);
    if (e.msgid !== '' && !translated.some(Boolean)) continue;
    let key = (e.ctx !== undefined ? e.ctx + '\u0004' : '') + e.msgid;
    if (e.plural !== undefined) key += '\u0000' + e.plural;
    pairs.push([key, translated.join('\u0000')]);
  }
  pairs.sort((a, b) => Buffer.compare(Buffer.from(a[0], 'utf8'), Buffer.from(b[0], 'utf8')));
  const n = pairs.length;
  const keys = pairs.map((p) => Buffer.from(p[0], 'utf8'));
  const vals = pairs.map((p) => Buffer.from(p[1], 'utf8'));
  const headerSize = 28;
  const origTable = headerSize;
  const transTable = origTable + n * 8;
  let offset = transTable + n * 8;
  const parts = [];
  const origEntries = [];
  const transEntries = [];
  for (const k of keys) { origEntries.push([k.length, offset]); parts.push(k, Buffer.from([0])); offset += k.length + 1; }
  for (const v of vals) { transEntries.push([v.length, offset]); parts.push(v, Buffer.from([0])); offset += v.length + 1; }
  const head = Buffer.alloc(headerSize + n * 16);
  head.writeUInt32LE(0x950412de, 0);
  head.writeUInt32LE(0, 4);
  head.writeUInt32LE(n, 8);
  head.writeUInt32LE(origTable, 12);
  head.writeUInt32LE(transTable, 16);
  head.writeUInt32LE(0, 20);
  head.writeUInt32LE(offset, 24);
  origEntries.forEach(([len, off], i) => { head.writeUInt32LE(len, origTable + i * 8); head.writeUInt32LE(off, origTable + i * 8 + 4); });
  transEntries.forEach(([len, off], i) => { head.writeUInt32LE(len, transTable + i * 8); head.writeUInt32LE(off, transTable + i * 8 + 4); });
  return Buffer.concat([head, ...parts]);
}

for (const name of readdirSync('languages').filter((f) => f.endsWith('.po'))) {
  const entries = parsePo(readFileSync('languages/' + name, 'utf8'));
  const mo = compile(entries);
  writeFileSync('languages/' + name.replace(/\.po$/, '.mo'), mo);
  const total = entries.filter((e) => e.msgid !== '').length;
  const done = entries.filter((e) => e.msgid !== '' && e.msgstr.some(Boolean) && !e.fuzzy).length;
  console.log(`${name}: ${done}/${total} translated → ${name.replace(/\.po$/, '.mo')}`);
}
