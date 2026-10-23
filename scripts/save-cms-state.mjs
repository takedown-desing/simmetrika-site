// После сборки (npm run postbuild):
//  1) сохраняет карту адресов и автоматические переадресации в src/cms/settings — следующая сборка узнает прежние адреса записей;
//  2) создаёт в dist страницы-переадресации со старых адресов (смена адреса, скрытие, удаление, ручные из «Настройки → Переадресации»).
// На GitHub Pages нет серверных 301, поэтому здесь meta refresh + canonical; на боевом хостинге те же пары выгружаются в 301 (dist/admin/redirects.txt).
import fs from 'node:fs';
import path from 'node:path';
const BASE = (process.env.BASE_PATH ?? '/simmetrika-site').replace(/\/+$/, '');
const ORIGIN = 'https://simmetrika-mebel.ru';
const src = 'dist/cms-state.json';
if (!fs.existsSync(src)) { console.log('[save-cms-state] нет dist/cms-state.json, пропуск'); process.exit(0); }
const st = JSON.parse(fs.readFileSync(src, 'utf8'));
fs.rmSync(src);
const write = (p, o) => fs.writeFileSync(p, JSON.stringify(o, null, 2) + '\n');
write('src/cms/settings/url-map.json', st.urlMap);
write('src/cms/settings/redirects-auto.json', st.redirectsAuto);

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
let made = 0; const skipped = [];
const lines = [];
for (const [from, to] of Object.entries(st.resolved)) {
  const file = /\.html?$/.test(from) ? path.join('dist', from) : path.join('dist', from, 'index.html');
  if (fs.existsSync(file)) { skipped.push(from); continue; } // по адресу уже есть живая страница
  const href = /^https?:/.test(to) ? to : BASE + to;
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, `<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="robots" content="noindex, follow"><meta http-equiv="refresh" content="0; url=${esc(href)}"><link rel="canonical" href="${esc(/^https?:/.test(to) ? to : ORIGIN + to)}"><title>Страница переехала</title></head><body><p>Страница переехала: <a href="${esc(href)}">${esc(href)}</a></p></body></html>\n`);
  lines.push(`${from} ${to} 301`);
  made++;
}
fs.mkdirSync('dist/admin', { recursive: true });
fs.writeFileSync('dist/admin/redirects.txt', '# старый адрес → новый, код (для 301 на боевом хостинге)\n' + lines.join('\n') + '\n');
console.log('[save-cms-state] адресов:', Object.keys(st.urlMap).length, '| переадресаций:', made, skipped.length ? `| пропущено (живые страницы): ${skipped.join(', ')}` : '');
