// Proves the demo seed is not shipped in a real build: builds both variants into scratch folders and searches the JavaScript for seed-only text.
// Usage: pnpm check:no-seed   (exit 1 when the real bundle contains the seed, or when the demo bundle lost it, which would mean this check proves nothing)
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readdirSync, readFileSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const MARKERS = ['Kigali Home & Mat Traders', 'Hassan Warsame', 'Juba Konyo-Konyo Traders'];
const root = new URL('..', import.meta.url).pathname;

function build(name, env) {
  const out = mkdtempSync(join(tmpdir(), `tanaad-${name}-`));
  const r = spawnSync('pnpm', ['exec', 'vite', 'build', '--outDir', out, '--emptyOutDir', '--logLevel', 'error'], { cwd: root, env: { ...process.env, ...env }, stdio: 'inherit' });
  if (r.status !== 0) { rmSync(out, { recursive: true, force: true }); console.error(`${name} build failed`); process.exit(1); }
  return out;
}
function scan(dir) {
  let bytes = 0; const hits = new Set();
  const walk = (d) => { for (const f of readdirSync(d)) { const p = join(d, f); if (statSync(p).isDirectory()) walk(p); else if (p.endsWith('.js')) { const t = readFileSync(p, 'utf8'); bytes += t.length; for (const m of MARKERS) if (t.includes(m)) hits.add(m); } } };
  walk(dir); return { bytes, hits: [...hits] };
}

const real = build('real', { VITE_API_URL: 'https://api.example.test/api/v1', VITE_DEMO: '0' });
const demo = build('demo', { VITE_DEMO: '1' });
const r = scan(real); const d = scan(demo);
rmSync(real, { recursive: true, force: true }); rmSync(demo, { recursive: true, force: true });
const kb = (n) => `${(n / 1024).toFixed(0)} kB`;
console.log(`real build JS ${kb(r.bytes)}, seed markers found: ${r.hits.length ? r.hits.join(', ') : 'none'}`);
console.log(`demo build JS ${kb(d.bytes)}, seed markers found: ${d.hits.length}/${MARKERS.length}`);
if (d.hits.length !== MARKERS.length) { console.error('FAIL: the demo build is missing the seed, so this check cannot prove anything.'); process.exit(1); }
if (r.hits.length) { console.error('FAIL: the real build contains the demo seed.'); process.exit(1); }
console.log('OK: the real build ships without the demo seed.');
