// Собирает PHP-версию сайта для хостинга в build/simmetrika-php/ (и архив, если передан --zip <путь>).
// Код берётся из php/, данные (тексты, товары, настройки) — из src/cms, картинки — из public/, стили — src/styles.
//   node scripts/build-php.mjs                    — только папка
//   node scripts/build-php.mjs --zip ../site.zip  — папка и архив для хостинга (нужен build/hosting/config.php)
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const OUT = path.resolve('build/simmetrika-php');
const zipArg = process.argv.indexOf('--zip');
const ZIP = zipArg > 0 ? path.resolve(process.argv[zipArg + 1]) : null;

const cp = (from, to) => fs.cpSync(path.resolve(from), path.join(OUT, to), { recursive: true });
const keep = new Set(['config.php', 'storage']); // локальные настройки и данные не затираем
if (fs.existsSync(OUT)) for (const f of fs.readdirSync(OUT)) if (!keep.has(f)) fs.rmSync(path.join(OUT, f), { recursive: true, force: true });
fs.mkdirSync(OUT, { recursive: true });

// код
for (const f of fs.readdirSync('php')) if (f !== 'storage') cp(`php/${f}`, f);
for (const d of ['storage', 'storage/cache', 'storage/logs', 'storage/sessions']) fs.mkdirSync(path.join(OUT, d), { recursive: true });
fs.writeFileSync(path.join(OUT, 'storage/.htaccess'), 'Require all denied\nDeny from all\n');

// статика
cp('public/img', 'img');
cp('public/images', 'images');
cp('public/js', 'js');
cp('public/favicon.svg', 'favicon.svg');
fs.mkdirSync(path.join(OUT, 'css'), { recursive: true });
cp('src/styles/global.css', 'css/site.css');
// сюда админка кладёт загруженные фото; выполнять что-либо в этой папке нельзя
fs.mkdirSync(path.join(OUT, 'img/up'), { recursive: true });
fs.writeFileSync(path.join(OUT, 'img/up/.htaccess'), '<FilesMatch "\\.(php\\d?|phtml|phar|pl|py|cgi|sh)$">\n  Require all denied\n</FilesMatch>\nOptions -Indexes\n');

// данные для первичной загрузки в MySQL и описание полей админки
cp('src/cms', 'install/data');
fs.mkdirSync(path.join(OUT, 'app/data'), { recursive: true });
cp('src/data/images.json', 'app/data/images.json');
execFileSync('node', ['scripts/gen-cms-config.mjs'], { env: { ...process.env, BASE_PATH: '' }, stdio: 'ignore' });
const yml = fs.readFileSync('public/admin/config.yml', 'utf8').replace(/^#.*\n/gm, '');
fs.writeFileSync(path.join(OUT, 'app/data/admin-config.json'), JSON.stringify(JSON.parse(yml), null, 1));
// вернуть config.yml для сборки на GitHub Pages
execFileSync('node', ['scripts/gen-cms-config.mjs'], { stdio: 'ignore' });

// служебные файлы
for (const f of fs.readdirSync(OUT, { recursive: true })) if (/(^|\/)\.DS_Store$/.test(f)) fs.rmSync(path.join(OUT, f));

// архив для хостинга: www/ (сайт с готовым config.php), simmetrika.sql (дамп базы без заявок), README.md.
// Боевой config.php с хэшем пароля лежит вне git: build/hosting/config.php (репозиторий публичный).
if (ZIP) {
  const HOST_CFG = path.resolve('build/hosting/config.php');
  if (!fs.existsSync(HOST_CFG)) { console.error('Нет build/hosting/config.php: создайте из php/config.sample.php'); process.exit(1); }
  const PKG = path.resolve('build/hosting-pkg/simmetrika-php');
  fs.rmSync(path.dirname(PKG), { recursive: true, force: true });
  const WWW = path.join(PKG, 'www');
  fs.cpSync(OUT, WWW, { recursive: true });
  // в архив не идут: локальный конфиг, данные для импорта (они в дампе), кэш/сессии/журналы, тестовые фото
  for (const f of ['config.php', 'config.sample.php', 'install/data', 'install/import.php', 'install/schema.sql']) fs.rmSync(path.join(WWW, f), { recursive: true, force: true });
  for (const d of ['cache', 'logs', 'sessions', 'ratelimit']) { fs.rmSync(path.join(WWW, 'storage', d), { recursive: true, force: true }); fs.mkdirSync(path.join(WWW, 'storage', d)); }
  for (const f of fs.readdirSync(path.join(WWW, 'img/up'))) if (f !== '.htaccess') fs.rmSync(path.join(WWW, 'img/up', f), { recursive: true, force: true });
  fs.copyFileSync(HOST_CFG, path.join(WWW, 'config.php'));
  fs.writeFileSync(path.join(PKG, 'simmetrika.sql'), execFileSync('php', ['scripts/make-dump.php', 'src/cms', 'php/install/schema.sql'], { maxBuffer: 64 << 20 }));
  fs.copyFileSync('scripts/hosting-README.md', path.join(PKG, 'README.md'));
  fs.rmSync(ZIP, { force: true });
  execFileSync('zip', ['-qr', '-X', ZIP, path.basename(PKG)], { cwd: path.dirname(PKG) });
  console.log('Архив:', ZIP, (fs.statSync(ZIP).size / 1048576).toFixed(1) + ' МБ');
}
console.log('Готово:', OUT);
