#!/usr/bin/env node
// cross-platform safe deploy (no overwrite of configs)
import { cp, mkdir, stat, copyFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const DEST_ARG = process.argv.slice(2).find(a => a.startsWith('--dest='))?.split('=')[1] || process.argv[2] || process.env.DEST;
if (!DEST_ARG) {
  console.error('Usage: node scripts/deploy-safe.mjs <dest>  or  DEST=/path node scripts/deploy-safe.mjs  or  pnpm run deploy:safe -- --dest=/path');
  process.exit(1);
}
let dest = resolve(DEST_ARG);
let destSayanet = dest.endsWith('_sayanet') ? dest : join(dest, '_sayanet');
const build = resolve('build/_sayanet');

async function ensureBuild() {
  if (!existsSync(build)) {
    console.log('build/_sayanet missing, running pnpm run build...');
    const res = spawnSync('pnpm', ['run', 'build'], { stdio: 'inherit' });
    if (res.status !== 0) process.exit(res.status ?? 1);
  }
}

const PRESERVE = [
  'private/conf/options.json',
  'private/conf/types.json',
  'private/conf/options.example.json'
];

async function copyRecursive(src, dst, preserveSet) {
  const { readdir, lstat } = await import('node:fs/promises');
  await mkdir(dst, { recursive: true });
  const entries = await readdir(src, { withFileTypes: true });
  for (const ent of entries) {
    const s = join(src, ent.name);
    const d = join(dst, ent.name);
    const rel = d.slice(destSayanet.length + 1).replace(/\\/g, '/');
    // skip cache entirely
    if (rel === 'private/cache' || rel.startsWith('private/cache/')) continue;
    if (preserveSet.has(rel) && existsSync(d)) {
      // preserve existing, save new as .new
      try {
        await copyFile(s, d + '.new');
        console.log(`  preserved ${rel} (new as ${rel}.new)`);
      } catch {}
      continue;
    }
    if (ent.isDirectory()) {
      await copyRecursive(s, d, preserveSet);
    } else {
      await mkdir(dirname(d), { recursive: true });
      await cp(s, d, { recursive: false });
    }
  }
}

await ensureBuild();
console.log(`Deploying ${build} -> ${destSayanet} (preserving ${PRESERVE.join(', ')} + cache)`);

await mkdir(destSayanet, { recursive: true });
const preserveSet = new Set(PRESERVE);
await copyRecursive(build, destSayanet, preserveSet);

// ensure cache dirs
await mkdir(join(destSayanet, 'private/cache'), { recursive: true });
await mkdir(join(destSayanet, 'public/cache'), { recursive: true });

console.log('Deploy done.');
