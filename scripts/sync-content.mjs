// Копирует тексты и данные из рабочей папки проекта (../drafts) в сайт.
// В CI папки ../drafts нет: тогда используются уже закоммиченные копии в content/ и src/data/.
import fs from 'node:fs';
import path from 'node:path';
const SRC = path.resolve('../drafts');
if (!fs.existsSync(SRC)) { console.log('sync: ../drafts нет, использую закоммиченные копии'); process.exit(0); }
fs.mkdirSync('content', { recursive: true });
for (const f of fs.readdirSync('content')) fs.rmSync(path.join('content', f));
let n = 0;
for (const f of fs.readdirSync(path.join(SRC, 'content'))) {
  if (!f.endsWith('.md') || f === 'README.md') continue;
  fs.copyFileSync(path.join(SRC, 'content', f), path.join('content', f)); n++;
}
fs.copyFileSync(path.join(SRC, 'data', 'catalog.json'), 'src/data/catalog.json');
console.log(`sync: ${n} файлов текста + catalog.json`);
