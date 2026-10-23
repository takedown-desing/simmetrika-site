// Аудит перелинковки по dist: входящие ссылки на каждую страницу (уникальные страницы-источники),
// отдельно из основного контента <main> и всего (с шапкой/меню/подвалом). Выход: links-report.md
import fs from 'node:fs';
import path from 'node:path';
const DIST = 'dist', BASE = '/simmetrika-site';
const pages = [];
const isService = (root, p) => /^(admin|go)(\/|$)/.test(path.relative(root, p)) || /http-equiv="refresh"/.test(fs.readFileSync(p, 'utf8').slice(0, 800)); // админка, /go/ и переадресации
const walk = (d) => { for (const f of fs.readdirSync(d)) { const p = path.join(d, f); if (fs.statSync(p).isDirectory()) walk(p); else if (f === 'index.html' && !isService(DIST, p)) pages.push(p); } };
walk(DIST);
const urlOf = (f) => '/' + path.relative(DIST, f).replace(/index\.html$/, '');
const norm = (h) => { let u = h.split('#')[0].split('?')[0]; if (!u.startsWith(BASE)) return null; u = u.slice(BASE.length) || '/'; return u.endsWith('/') ? u : u + '/'; };
const all = new Map(), main = new Map(), outMain = new Map();
const urls = pages.map(urlOf);
for (const u of urls) { all.set(u, new Set()); main.set(u, new Set()); }
for (const f of pages) {
  const src = urlOf(f); const h = fs.readFileSync(f, 'utf8');
  const m = h.match(/<main[\s\S]*?<\/main>/)?.[0] || '';
  const robots = /noindex/.test(h.match(/<meta name="robots" content="([^"]*)"/)?.[1] || '');
  for (const [html, map] of [[h, all], [m, main]]) {
    for (const x of html.matchAll(/href="([^"]+)"/g)) { const t = norm(x[1]); if (t && t !== src && map.has(t)) map.get(t).add(src); }
  }
  outMain.set(src, new Set([...m.matchAll(/href="([^"]+)"/g)].map((x) => norm(x[1])).filter((t) => t && t !== src)).size);
}
const P1 = ['/', '/catalog/', '/catalog/pryamye-divany/', '/catalog/uglovye-divany/', '/catalog/modulnye-divany/', '/catalog/divany-krovati/', '/sale/', '/kontakty/'];
const rows = urls.sort().map((u) => ({ u, all: all.get(u).size, main: main.get(u).size, out: outMain.get(u) }));
const orphansMain = rows.filter((r) => r.main === 0 && !/zayavka/.test(r.u));
const orphansAll = rows.filter((r) => r.all === 0 && !/zayavka\/spasibo/.test(r.u));
const p1low = rows.filter((r) => P1.includes(r.u) && r.all < 8);
const md = [`# Аудит перелинковки (${new Date().toISOString().slice(0, 10)})`, '',
  `Страниц: ${rows.length}. Сирот (нет входящих вообще): ${orphansAll.length}. Без входящих из контента (только меню/подвал): ${orphansMain.length}. P1 с менее чем 8 входящими: ${p1low.length}.`, '',
  '| URL | Входящих всего | из контента | Исходящих из контента |', '|---|---:|---:|---:|',
  ...rows.map((r) => `| ${r.u} | ${r.all} | ${r.main} | ${r.out} |`)].join('\n');
fs.writeFileSync('links-report.md', md);
console.log(`страниц ${rows.length} | сирот ${orphansAll.length} ${orphansAll.map((r) => r.u).join(' ')} | только из меню ${orphansMain.length} ${orphansMain.map((r) => r.u).join(' ')} | P1<8: ${p1low.map((r) => r.u + ':' + r.all).join(' ') || 'нет'}`);
const low = rows.filter((r) => r.out < 5 && !/zayavka|privacy|404/.test(r.u));
console.log('исходящих из контента < 5:', low.map((r) => r.u + ':' + r.out).join(' ') || 'нет');
console.log('мин/медиана входящих из контента:', Math.min(...rows.map((r) => r.main)), rows.map((r) => r.main).sort((a, b) => a - b)[Math.floor(rows.length / 2)]);
