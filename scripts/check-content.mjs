// Быстрая проверка текстов перед сборкой (CI): длинные тире и длины title/description в шапках content/*.md
import fs from 'node:fs';
import * as yaml from 'js-yaml';
let bad = 0;
for (const f of fs.readdirSync('content').filter((x) => x.endsWith('.md'))) {
  const raw = fs.readFileSync('content/' + f, 'utf8');
  if (raw.includes('—')) { console.error(`${f}: длинное тире`); bad++; }
  for (const ch of raw.split('<!-- page-break -->')) {
    const m = ch.match(/^\s*---\n([\s\S]*?)\n---/); if (!m) continue;
    const d = yaml.load(m[1]) || {};
    if (!d.title || ['ui', 'cart', 'error', 'legal'].includes(d.type) || String(d.page_id).includes('{')) continue;
    if (d.title.length > 65) { console.error(`${f} ${d.page_id}: title ${d.title.length}`); bad++; }
    if (d.description && (d.description.length < 110 || d.description.length > 165)) { console.error(`${f} ${d.page_id}: description ${d.description.length}`); bad++; }
  }
}
console.log(bad ? `check:content: ошибок ${bad}` : 'check:content: ok');
process.exit(bad ? 1 : 0);
