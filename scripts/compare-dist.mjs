// Сравнение двух сборок постранично: title, description, canonical, H1, число H2, FAQ, ссылок, карточек товаров, картинок, JSON-LD типов.
import fs from 'node:fs';
import path from 'node:path';
const [A, B] = process.argv.slice(2);
const isService = (root, p) => /^(admin|go)(\/|$)/.test(path.relative(root, p)) || /http-equiv="refresh"/.test(fs.readFileSync(p, 'utf8').slice(0, 800)); // админка, /go/ и переадресации
const list = (d) => { const out = {}; const walk = (x) => { for (const f of fs.readdirSync(x)) { const p = path.join(x, f); if (fs.statSync(p).isDirectory()) walk(p); else if (f === 'index.html' && !isService(d, p)) out['/' + path.relative(d, p).replace(/index\.html$/, '')] = fs.readFileSync(p, 'utf8'); } }; walk(d); return out; };
const sig = (h) => ({
  title: (h.match(/<title>([^<]*)/) || [])[1], desc: (h.match(/name="description" content="([^"]*)/) || [])[1], canon: (h.match(/rel="canonical" href="([^"]*)/) || [])[1],
  h1: ((h.match(/<h1[^>]*>([\s\S]*?)<\/h1>/) || [])[1] || '').replace(/<[^>]+>/g, '').trim(), h2: (h.match(/<h2/g) || []).length,
  faq: (h.match(/<summary>/g) || []).length, links: (h.match(/<a /g) || []).length, cards: (h.match(/class="pc"/g) || []).length, imgs: (h.match(/<img /g) || []).length,
  ld: [...h.matchAll(/"@type":("[^"]+"|\[[^\]]+\])/g)].map((m) => m[1]).sort().join(','),
});
const a = list(A), b = list(B);
let diff = 0;
for (const u of new Set([...Object.keys(a), ...Object.keys(b)])) {
  if (!a[u] || !b[u]) { console.log(`${a[u] ? 'пропала' : 'новая'}: ${u}`); diff++; continue; }
  const x = sig(a[u]), y = sig(b[u]);
  const d = Object.keys(x).filter((k) => x[k] !== y[k]);
  if (d.length) { diff++; console.log(u, d.map((k) => `${k}: «${String(x[k]).slice(0, 60)}» → «${String(y[k]).slice(0, 60)}»`).join(' | ')); }
}
console.log(`страниц: было ${Object.keys(a).length}, стало ${Object.keys(b).length}, отличий: ${diff}`);
