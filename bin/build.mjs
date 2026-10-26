// Builds assets/dist from assets/src. Usage: node bin/build.mjs [--watch]
import * as esbuild from 'esbuild';
import { rmSync, mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const watch = process.argv.includes('--watch');
const target = ['chrome100', 'firefox100', 'safari15.4', 'edge100'];

rmSync('assets/dist', { recursive: true, force: true });
mkdirSync('assets/dist', { recursive: true });

const builds = [
  {
    entryPoints: { app: 'assets/src/app/main.jsx' },
    bundle: true,
    splitting: true,
    format: 'esm',
    outdir: 'assets/dist',
    chunkNames: 'chunks/[name]-[hash]',
    jsx: 'automatic',
    jsxImportSource: 'preact',
    minify: true,
    sourcemap: false,
    target,
    legalComments: 'none',
    define: { 'process.env.NODE_ENV': '"production"' },
  },
  {
    entryPoints: { app: 'assets/src/app/styles/app.css' },
    bundle: true,
    outdir: 'assets/dist',
    minify: true,
    target,
  },
  {
    entryPoints: { share: 'assets/src/share/share.js' },
    bundle: true,
    format: 'iife',
    outdir: 'assets/dist',
    minify: true,
    target,
  },
  {
    entryPoints: { share: 'assets/src/share/share.css' },
    bundle: true,
    outdir: 'assets/dist',
    minify: true,
    target,
  },
];

if (watch) {
  for (const options of builds) {
    const ctx = await esbuild.context(options);
    await ctx.watch();
  }
  console.log('Watching assets/src…');
} else {
  await Promise.all(builds.map((options) => esbuild.build(options)));
  try {
    execFileSync('node', ['bin/extract-strings.mjs'], { stdio: 'inherit' });
  } catch (e) {
    process.exitCode = 1;
  }
  console.log('Built assets/dist');
}
