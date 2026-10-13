// QA собранного dist: H1, meta, JSON-LD, FAQ = FAQPage, внутренние ссылки и картинки, длинные тире. Выход: qa-report.md
import fs from 'node:fs';
import path from 'node:path';
const DIST = 'dist';
const BASE = '/simmetrika-site';
const files = [];
const walk = (d) => { for (const f of fs.readdirSync(d)) { const p = path.join(d, f); if (fs.statSync(p).isDirectory()) walk(p); else if (f.endsWith('.html')) files.push(p); } };
walk(DIST);
const exists = (href) => {
  let p = href.split('#')[0].split('?')[0];
  if (!p.startsWith(BASE)) return true;
  p = p.slice(BASE.length) || '/';
  const f = path.join(DIST, p);
  return fs.existsSync(f) && (fs.statSync(f).isFile() || fs.existsSync(path.join(f, 'index.html')));
};
const strip = (s) => s.replace(/<script[\s\S]*?<\/script>|<style[\s\S]*?<\/style>/g, '').replace(/<[^>]+>/g, ' ').replace(/&[a-z#0-9]+;/g, ' ');
const dec = (s) => s.replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&#39;/g, "'").trim();
const rows = []; let crit = 0, warn = 0;
for (const f of files) {
  const h = fs.readFileSync(f, 'utf8');
  const url = '/' + path.relative(DIST, f).replace(/index\.html$/, '');
  const issues = [];
  const h1 = (h.match(/<h1[\s>]/g) || []).length;
  if (h1 !== 1 && !url.includes('404')) issues.push(['CRIT', `H1: ${h1}`]);
  const title = (h.match(/<title>([^<]*)<\/title>/) || [])[1] || '';
  const desc = (h.match(/<meta name="description" content="([^"]*)"/) || [])[1] || '';
  if (!title) issues.push(['CRIT', 'нет title']);
  if (/—/.test(title + desc)) issues.push(['CRIT', 'тире в title/description']);
  const text = strip(h);
  const dashes = (text.match(/—/g) || []).length;
  if (dashes) issues.push(['WARN', `длинных тире в тексте: ${dashes}`]);
  const ld = [...h.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)];
  for (const m of ld) {
    try {
      const j = JSON.parse(m[1]);
      const faq = (j['@graph'] || []).find((x) => x['@type'] === 'FAQPage');
      if (faq) {
        const sums = [...h.matchAll(/<summary>([\s\S]*?)<\/summary>/g)].map((x) => dec(x[1]));
        const names = faq.mainEntity.map((q) => q.name);
        const miss = names.filter((n) => !sums.includes(n));
        if (miss.length) issues.push(['CRIT', `FAQPage ≠ HTML: ${miss.length}`]);
      }
      const pt = (j['@graph'] || []).find((x) => /Page$/.test([].concat(x['@type'])[0]));
      if (pt && pt.name !== dec(title)) issues.push(['WARN', 'WebPage.name ≠ title']);
    } catch (e) { issues.push(['CRIT', 'JSON-LD не парсится']); }
  }
  const hrefs = [...h.matchAll(/href="([^"]+)"/g)].map((x) => x[1]).filter((x) => x.startsWith('/') && !x.startsWith('//'));
  const broken = [...new Set(hrefs.filter((x) => !exists(x)))];
  if (broken.length) issues.push(['CRIT', `битые ссылки: ${broken.slice(0, 4).join(', ')}${broken.length > 4 ? '…' : ''}`]);
  const imgs = [...h.matchAll(/<img[^>]+src="([^"]+)"/g)].map((x) => x[1]).filter((x) => x.startsWith('/'));
  const badImg = imgs.filter((x) => !exists(x));
  if (badImg.length) issues.push(['CRIT', `нет картинок: ${badImg.length}`]);
  const noAlt = (h.match(/<img(?![^>]*\balt=)[^>]*>/g) || []).length;
  if (noAlt) issues.push(['WARN', `img без alt: ${noAlt}`]);
  crit += issues.filter((x) => x[0] === 'CRIT').length; warn += issues.filter((x) => x[0] === 'WARN').length;
  rows.push([url, title.length, desc.length, issues.map((x) => `${x[0]} ${x[1]}`).join('; ') || 'ok']);
}
const md = [`# QA-отчёт сборки (${new Date().toISOString().slice(0, 16)})`, '', `Страниц: ${files.length}. CRITICAL: ${crit}. WARN: ${warn}.`, '', '| URL | title | desc | Проверка |', '|---|---:|---:|---|', ...rows.sort().map((r) => `| ${r[0]} | ${r[1]} | ${r[2]} | ${r[3]} |`)].join('\n');
fs.writeFileSync('qa-report.md', md);
console.log(`QA: страниц ${files.length}, CRITICAL ${crit}, WARN ${warn}`);
rows.filter((r) => r[3] !== 'ok').slice(0, 30).forEach((r) => console.log(r[0], '→', r[3]));
process.exit(crit ? 1 : 0);
