// Minimal line diff (Myers O(ND)) producing unified hunks. Loaded lazily with the editor.

function myers(a, b) {
  const n = a.length;
  const m = b.length;
  const max = n + m;
  const v = new Int32Array(2 * max + 2);
  const trace = [];
  const limit = Math.max(200, Math.floor(2e7 / Math.max(1, max)));
  for (let d = 0; d <= max; d++) {
    if (d > limit) return null; // Too different: keep memory bounded (~80 MB max).
    trace.push(v.slice());
    for (let k = -d; k <= d; k += 2) {
      let x;
      if (k === -d || (k !== d && v[max + k - 1] < v[max + k + 1])) x = v[max + k + 1];
      else x = v[max + k - 1] + 1;
      let y = x - k;
      while (x < n && y < m && a[x] === b[y]) {
        x++;
        y++;
      }
      v[max + k] = x;
      if (x >= n && y >= m) return backtrack(trace, a, b, max);
    }
  }
  return [];
}

function backtrack(trace, a, b, max) {
  const ops = [];
  let x = a.length;
  let y = b.length;
  for (let d = trace.length - 1; d >= 0; d--) {
    const v = trace[d];
    const k = x - y;
    let prevK;
    if (k === -d || (k !== d && v[max + k - 1] < v[max + k + 1])) prevK = k + 1;
    else prevK = k - 1;
    const prevX = v[max + prevK];
    const prevY = prevX - prevK;
    while (x > prevX && y > prevY) {
      ops.push({ type: ' ', text: a[x - 1], a: x, b: y });
      x--;
      y--;
    }
    if (d > 0) {
      if (x === prevX) ops.push({ type: '+', text: b[y - 1], b: y });
      else ops.push({ type: '-', text: a[x - 1], a: x });
    }
    x = prevX;
    y = prevY;
  }
  return ops.reverse();
}

/**
 * Diff two texts. Returns { hunks: [{ lines: [{type, text, a, b}] }], added, removed } with 3 lines of context.
 * Very large inputs fall back to a "too large" marker to keep the UI responsive.
 */
export function diffLines(before, after, context = 3) {
  const a = before.split('\n');
  const b = after.split('\n');
  const ops = a.length + b.length > 60000 ? null : myers(a, b);
  if (!ops) return { tooLarge: true, hunks: [], added: 0, removed: 0 };
  let added = 0;
  let removed = 0;
  const hunks = [];
  let current = null;
  let lastChange = -Infinity;
  ops.forEach((op, i) => {
    if (op.type !== ' ') {
      if (op.type === '+') added++;
      else removed++;
      if (!current || i - lastChange > context * 2) {
        current = { lines: [] };
        hunks.push(current);
        for (let j = Math.max(0, i - context); j < i; j++) if (ops[j].type === ' ') current.lines.push(ops[j]);
      } else {
        for (let j = lastChange + 1; j < i; j++) current.lines.push(ops[j]);
      }
      current.lines.push(op);
      lastChange = i;
    }
  });
  // Add trailing context to each hunk.
  hunks.forEach((h) => {
    const lastOp = h.lines[h.lines.length - 1];
    const idx = ops.indexOf(lastOp);
    for (let j = idx + 1; j < Math.min(ops.length, idx + 1 + context); j++) {
      if (ops[j].type !== ' ') break;
      h.lines.push(ops[j]);
    }
  });
  return { hunks, added, removed };
}
