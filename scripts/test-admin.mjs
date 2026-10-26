// Сквозной тест админки (npm run test:admin). Работает на временной копии сайта, рабочую папку не трогает.
// Записи сохраняются так, как их сохраняет Sveltia: все поля из config.yml, незаполненные — пустыми ("", null, [], false).
// Сценарии: правка товара (title, цена), новая страница, новая категория с товаром, новость, подборка, меню, телефон,
// смена адреса товара (переадресация, цепочки), скрытие и возврат товара, удаление новости, боевой режим (robots, canonical, sitemap),
// и негативные: каждая типичная ошибка редактора должна остановить сборку с понятным сообщением.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import * as yaml from 'js-yaml';

const ROOT = process.cwd();
const TMP = fs.mkdtempSync(path.join(process.env.TEST_DIR || os.tmpdir(), 'simmetrika-admin-'));
const BASE = '/simmetrika-site';
let pass = 0; const fails = [];
const ok = (cond, msg) => { if (cond) pass++; else fails.push(msg); console.log(`${cond ? '  ✓' : '  ✗'} ${msg}`); };
const step = (t) => console.log(`\n▸ ${t}`);

// ── копия сайта ──
fs.cpSync(ROOT, TMP, { recursive: true, filter: (src) => !/[/\\](node_modules|dist|\.git|\.astro)([/\\]|$)/.test(path.relative(ROOT, src) ? '/' + path.relative(ROOT, src) : '') });
fs.symlinkSync(path.join(ROOT, 'node_modules'), path.join(TMP, 'node_modules'), 'dir');
const run = (cmd, args, env = {}) => { const r = spawnSync(cmd, args, { cwd: TMP, encoding: 'utf8', env: { ...process.env, ...env } }); return { code: r.status, out: (r.stdout || '') + (r.stderr || '') }; };
const build = (env) => run('npm', ['run', 'build'], env);
const validate = () => run('sh', ['-c', 'node scripts/gen-cms-config.mjs && node scripts/validate-cms.mjs --quiet']);
const P = (...a) => path.join(TMP, ...a);
const J = (rel) => JSON.parse(fs.readFileSync(P('src/cms', rel), 'utf8'));
const save = (rel, obj) => { fs.mkdirSync(path.dirname(P('src/cms', rel)), { recursive: true }); fs.writeFileSync(P('src/cms', rel), JSON.stringify(obj, null, 2) + '\n'); };
const edit = (rel, fn) => { const d = J(rel); fn(d); save(rel, d); };
const page = (url) => { const f = url.endsWith('.html') ? P('dist', url) : P('dist', url, 'index.html'); return fs.existsSync(f) ? fs.readFileSync(f, 'utf8') : ''; };
const refreshTo = (h) => (h.slice(0, 800).match(/http-equiv="refresh" content="0; url=([^"]+)"/) || [])[1] || null;
const h1Of = (h) => ((h.match(/<h1[^>]*>([\s\S]*?)<\/h1>/) || [])[1] || '').replace(/<[^>]+>/g, '').replace(/&nbsp;|\u00a0/g, ' ').trim();
const titleOf = (h) => (h.match(/<title>([^<]*)<\/title>/) || [])[1] || '';
const robotsOf = (h) => (h.match(/<meta name="robots" content="([^"]+)"/) || [])[1] || '';
const canonOf = (h) => (h.match(/<link rel="canonical" href="([^"]+)"/) || [])[1] || '';
// карточки первой сетки товаров на странице (до конца её секции)
const cardLinks = (h) => { const i = h.indexOf('data-grid>'); if (i < 0) return []; const part = h.slice(i, h.indexOf('</section>', i) > 0 ? h.indexOf('</section>', i) : undefined); return [...part.matchAll(/<a class="pc-photo" href="([^"]+)"/g)].map((m) => m[1]); };
const hasLink = (h, url) => h.includes(`href="${BASE}${url}"`);

// ── «как Sveltia»: пустая запись по полям конфига ──
const cfgOf = () => JSON.parse(fs.readFileSync(P('public/admin/config.yml'), 'utf8').replace(/^#.*\n/, ''));
const blankOf = (fields) => Object.fromEntries(fields.filter((f) => f.widget !== 'hidden').map((f) => [f.name,
  f.widget === 'object' ? blankOf(f.fields) : f.widget === 'list' ? [] : f.widget === 'number' ? null : f.widget === 'boolean' ? (f.default ?? false)
    : ['select', 'relation'].includes(f.widget) ? (f.multiple ? [] : (f.default ?? '')) : '']));
const blank = (col) => blankOf(cfgOf().collections.find((c) => c.name === col).fields);

try {
  // ═══ 0. Исходная сборка ═══
  step('Исходная сборка копии');
  let r = build();
  ok(r.code === 0, `сборка проходит (код ${r.code})`);
  if (r.code !== 0) throw new Error(r.out.slice(-3000));
  const cfgText = fs.readFileSync(P('public/admin/config.yml'), 'utf8');
  let cfg;
  try { cfg = yaml.load(cfgText); ok(true, 'config.yml админки читается как YAML'); } catch (e) { ok(false, 'config.yml не YAML: ' + e.message); }
  const WIDGETS = new Set(['string', 'text', 'number', 'boolean', 'select', 'image', 'hidden', 'list', 'object', 'datetime', 'color', 'markdown', 'relation']);
  const badW = []; const dupF = [];
  const walkF = (fields, w) => { const names = new Set(); for (const f of fields) { if (names.has(f.name)) dupF.push(`${w}.${f.name}`); names.add(f.name); if (!WIDGETS.has(f.widget)) badW.push(`${w}.${f.name}:${f.widget}`); if (f.fields) walkF(f.fields, `${w}.${f.name}`); if (f.field) walkF([f.field], `${w}.${f.name}`); } };
  for (const c of cfg.collections) { if (c.fields) walkF(c.fields, c.name); for (const f of c.files || []) walkF(f.fields, `${c.name}/${f.name}`); if (c.folder) ok(fs.existsSync(P(c.folder)), `папка коллекции «${c.label}» есть: ${c.folder}`); for (const f of c.files || []) ok(fs.existsSync(P(f.file)), `файл настроек «${f.label}» есть`); }
  ok(!badW.length, `все поля админки с известными типами ${badW.join(', ')}`);
  ok(!dupF.length, `имена полей не повторяются ${dupF.join(', ')}`);
  // строгая проверка по официальной JSON-схеме Sveltia той же версии, что подключена в admin/index.html
  const ver = (fs.readFileSync(P('public/admin/index.html'), 'utf8').match(/@sveltia\/cms@([\d.]+)/) || [])[1];
  const cache = path.join(ROOT, 'node_modules/.cache/sveltia-schema-' + ver + '.json');
  if (!fs.existsSync(cache)) { try { const res = await fetch(`https://unpkg.com/@sveltia/cms@${ver}/schema/sveltia-cms.json`); if (res.ok) { fs.mkdirSync(path.dirname(cache), { recursive: true }); fs.writeFileSync(cache, await res.text()); } } catch {} }
  if (fs.existsSync(cache)) {
    const { default: Ajv } = await import('ajv'); const { default: addFormats } = await import('ajv-formats');
    const ajv = new Ajv({ allErrors: true, strict: false }); addFormats(ajv);
    const check = ajv.compile(JSON.parse(fs.readFileSync(cache, 'utf8')));
    const valid = check(cfg);
    ok(valid, `config.yml соответствует схеме Sveltia ${ver}${valid ? '' : ': ' + [...new Set(check.errors.map((e) => `${e.instancePath} ${e.message} ${JSON.stringify(e.params)}`))].slice(0, 5).join('; ')}`);
  } else console.log(`  ! схема Sveltia ${ver} недоступна (нет сети): проверка конфига по схеме пропущена`);
  ok(cfg.backend.repo === 'takedown-desing/simmetrika-site' && cfg.backend.name === 'github', 'бэкенд: GitHub, репозиторий сайта');
  ok(fs.existsSync(P('dist/admin/index.html')) && fs.existsSync(P('dist/admin/config.yml')), 'админка попадает в сборку: /admin/ и config.yml');
  ok(fs.existsSync(P('dist/admin/seo/index.html')), 'SEO-отчёт собран: /admin/seo/');
  ok(!fs.existsSync(P('dist/cms-state.json')), 'служебный cms-state.json удалён из сборки');
  ok(refreshTo(page('/go/products/pryamye-divany--edzhio/')) === `${BASE}/catalog/pryamye-divany/edzhio/`, 'кнопка «Открыть на сайте»: /go/ ведёт на карточку');
  ok(refreshTo(page('/straight.html')) === `${BASE}/catalog/pryamye-divany/`, 'ручная переадресация со старого сайта: /straight.html → прямые диваны');

  // ═══ 1. Правки и новые записи ═══
  step('Правки и новые записи (как из админки)');
  edit('products/pryamye-divany--edzhio.json', (d) => { d.title = 'Прямой диван Эджио ТЕСТ от фабрики | SIMMETRIKA'; d.price_from = 123400; d.description = 'Тестовое описание прямого дивана Эджио: размеры, ткани, цена от 123 400 ₽, изготовление 14–21 день, доставка по Москве.'; });
  save('pages/garantiya.json', { ...blank('pages'), id: 'garantiya', url: '/garantiya/', type: 'service', h1: 'Гарантия на диваны SIMMETRIKA', title: 'Гарантия на диваны 18 месяцев | SIMMETRIKA', description: 'Гарантия фабрики SIMMETRIKA на диваны 18 месяцев: что входит, как обратиться, сроки ремонта и замены. Шоурумы в Москве и Химках.', lede: 'Что входит в гарантию и как ей воспользоваться.', body: '## Что входит в гарантию\n\nКаркас, механизм и швы. Подберите модель в [каталоге диванов](/catalog/).\n\n:::cta{href="/kontakty/" label="Связаться с фабрикой"}\n**Вопрос по гарантии?**\n:::\n', faq: [{ q: 'Сколько длится гарантия?', a: 'Гарантия на диваны фабрики 18 месяцев с даты доставки.' }] });
  save('categories/sofy.json', { ...blank('categories'), name: 'Софы', slug: 'sofy', order: 5, in_menu: true, h1: 'Софы от производителя', title: 'Софы от производителя в Москве | SIMMETRIKA', description: 'Софы от фабрики SIMMETRIKA: компактные модели для кухни и гостиной, ткани велюр, рогожка и жаккард, изготовление 14–21 день.' });
  save('products/sofy--sofa-test.json', { ...blank('products'), name: 'Тест', slug: 'sofa-test', category: 'sofy', h1: 'Софа Тест', title: 'Софа Тест от производителя | SIMMETRIKA', description: 'Софа Тест от фабрики SIMMETRIKA: длина 180 см, обивка велюр, рогожка или жаккард, изготовление под заказ 14–21 день, доставка по Москве.', mechanism: 'none', length_cm: 180, depth_cm: 90, height_cm: 85, lead_time_days: '14–21', warranty_months: 18, body: 'Компактная софа для кухни.' });
  save('news/test-novost.json', { ...blank('news'), slug: 'test-novost', news_type: 'news', date: '2026-10-22', h1: 'Тестовая новость фабрики', title: 'Тестовая новость фабрики SIMMETRIKA | Новости', description: 'Тестовая новость фабрики SIMMETRIKA: проверяем, что новость из админки появляется в разделе новостей и на сайте без ошибок.', announce: 'Проверка публикации новости из админки.', body: 'Текст новости со ссылкой на [распродажу](/sale/).', product_ids: ['pryamye-divany--edzhio', 'uglovye-divany--milano'] });
  const colRule = (over) => ({ ...blank('collections').listing, rule: { ...blank('collections').listing.rule, ...over } });
  save('collections/catalog-pryamye-divany-v-nalichii.json', { ...blank('collections'), url: '/catalog/pryamye-divany/v-nalichii/', type: 'filter', h1: 'Прямые диваны в наличии', title: 'Прямые диваны в наличии в Москве | SIMMETRIKA', description: 'Прямые диваны в наличии на складе фабрики SIMMETRIKA: доставка по Москве за 1–3 дня, самовывоз из шоурума, цены от производителя.', listing: colRule({ category: 'pryamye-divany', in_stock: true }) });
  save('collections/catalog-uglovye-divany-ot-330.json', { ...blank('collections'), url: '/catalog/uglovye-divany/ot-330-sm/', type: 'filter', h1: 'Угловые диваны от 330 см', title: 'Большие угловые диваны от 330 см | SIMMETRIKA', description: 'Угловые диваны длиной от 330 см от фабрики SIMMETRIKA: для просторной гостиной, ткани на выбор, изготовление 14–21 день.', listing: colRule({ category: 'uglovye-divany', length_gte: 330 }) });
  edit('settings/menu.json', (d) => d.nav.push({ title: 'Гарантия', url: '/garantiya/', accent: false }));
  // страница, на которую не ссылается ничто (ни текст, ни меню, ни чип раздела)
  save('pages/sirota.json', { ...blank('pages'), id: 'sirota', url: '/sirota/', type: 'service', h1: 'Страница-сирота', title: 'Страница-сирота для проверки | SIMMETRIKA', body: 'Текст.' });
  // новый цвет без своей страницы и без «Цвета кружка»
  edit('settings/fabrics.json', (d) => d.colors.push({ slug: 'grafit', name: 'Графит', plural: 'Графитовые', hex: '' }));
  // подборка без title: в <title> подставится «H1 | SIMMETRIKA»; ссылку на неё даёт чип раздела, а не текст
  save('collections/catalog-pryamye-divany-rogozhka.json', { ...blank('collections'), url: '/catalog/pryamye-divany/rogozhka/', type: 'filter', h1: 'Прямые диваны в рогожке', listing: colRule({ category: 'pryamye-divany', material: 'rogozhka' }) });
  edit('settings/company.json', (d) => { d.phone_main = '+7 (999) 111-22-33'; });
  // смена адреса, скрытие, удаление
  edit('products/uglovye-divany--milano.json', (d) => { d.slug = 'milano-plus'; });
  edit('products/pryamye-divany--teo.json', (d) => { d.hidden = true; });
  fs.rmSync(P('src/cms/news/novyy-sayt-i-katalog.json'));

  r = build();
  ok(r.code === 0, `сборка после правок проходит (код ${r.code})`);
  if (r.code !== 0) throw new Error(r.out.slice(-3000));

  let h = page('/catalog/pryamye-divany/edzhio/');
  ok(titleOf(h).includes('ТЕСТ'), 'товар: новый title в <title>');
  ok(/123[\s ]400|123&nbsp;400/.test(h), 'товар: новая цена 123 400 ₽ в карточке');
  ok(/123[\s ]400|123&nbsp;400/.test(page('/catalog/pryamye-divany/')), 'товар: новая цена и в списке категории');

  h = page('/garantiya/');
  ok(!!h, 'новая страница /garantiya/ создана');
  ok(h1Of(h) === 'Гарантия на диваны SIMMETRIKA' && (h.match(/<h1[\s>]/g) || []).length === 1, 'новая страница: один H1 из админки');
  ok(/"FAQPage"/.test(h) && h.includes('Сколько длится гарантия?'), 'новая страница: FAQ в тексте и в микроразметке');
  ok(hasLink(h, '/catalog/') && hasLink(h, '/kontakty/'), 'новая страница: ссылки из текста и кнопки с адресом превью');
  ok(!/<a class="btn[^"]*" href="[^"]*">\s*<\/a>/.test(h) && !/class="phead-btns"/.test(h), 'новая страница: пустые кнопки из незаполненных полей не выводятся');
  ok(!/class="rel-i"/.test(h), 'новая страница: пустой блок «Смотрите также» не выводится');
  ok(hasLink(page('/'), '/garantiya/'), 'меню: новый пункт «Гарантия» в шапке');
  ok(refreshTo(page('/go/pages/garantiya/')) === `${BASE}/garantiya/`, 'новая страница: /go/ для кнопки «Открыть на сайте»');

  h = page('/catalog/sofy/');
  ok(!!h && h1Of(h) === 'Софы от производителя', 'новая категория /catalog/sofy/ создана');
  ok(hasLink(h, '/catalog/sofy/sofa-test/'), 'новая категория без текста всё равно показывает свои товары');
  h = page('/catalog/sofy/sofa-test/');
  ok(!!h && h1Of(h) === 'Софа Тест', 'новый товар в новой категории: карточка создана');
  ok(h.includes('цена по запросу'), 'новый товар без цены: «цена по запросу»');
  ok(/"Product"/.test(h), 'новый товар: микроразметка Product');
  const catField = cfgOf().collections.find((c) => c.name === 'products').fields.find((f) => f.name === 'category');
  ok(catField.widget === 'relation' && catField.collection === 'categories', 'категория товара: связь с коллекцией категорий (новая категория доступна сразу, без пересборки)');
  const cmenu = (h) => (h || '').slice((h || '').indexOf('id="cmenu"'));
  ok(hasLink(cmenu(page('/')), '/catalog/sofy/'), 'новая категория сама попала в меню «Каталог товаров» (в «Меню сайта» её не добавляли)');
  h = page('/catalog/cvet/seryy/');
  ok(h.includes('Графит') && !h.includes('/catalog/cvet/grafit/'), 'новый цвет без своей подборки: в палитре без битой ссылки');
  h = page('/catalog/pryamye-divany/rogozhka/');
  ok(titleOf(h).replace(/\u00a0/g, ' ') === 'Прямые диваны в рогожке | SIMMETRIKA', 'подборка без title: в <title> «H1 | SIMMETRIKA»');
  ok(hasLink(page('/catalog/pryamye-divany/'), '/catalog/pryamye-divany/rogozhka/'), 'раздел сам показывает чип на новую вложенную подборку');

  h = page('/news/test-novost/');
  ok(!!h && h1Of(h) === 'Тестовая новость фабрики', 'новость /news/test-novost/ создана');
  ok(hasLink(page('/news/'), '/news/test-novost/'), 'новость в списке /news/');
  const newsCards = [...(h.split('Товары по теме')[1] || '').matchAll(/<a class="pc-photo" href="([^"]+)"/g)].map((m) => m[1]);
  ok(newsCards.includes(`${BASE}/catalog/pryamye-divany/edzhio/`) && newsCards.includes(`${BASE}/catalog/uglovye-divany/milano-plus/`), 'новость: блок «Товары по теме» с карточками, ссылка на товар после смены адреса новая');

  const prods = fs.readdirSync(P('src/cms/products')).map((f) => J('products/' + f)).filter((p) => !p.hidden);
  const stockN = prods.filter((p) => p.category === 'pryamye-divany' && p.in_stock?.material && p.in_stock?.color).length;
  h = page('/catalog/pryamye-divany/v-nalichii/');
  const stockLinks = new Set(cardLinks(h).filter((x) => x.startsWith(BASE + '/catalog/pryamye-divany/')));
  ok(!!h && stockLinks.size === stockN, `подборка по правилу (прямые + в наличии): ${stockLinks.size} карточек, ожидалось ${stockN}`);
  const longN = prods.filter((p) => p.category === 'uglovye-divany' && p.length_cm >= 330).length;
  ok(new Set(cardLinks(page('/catalog/uglovye-divany/ot-330-sm/'))).size === longN, `подборка «длина от 330»: ${longN} карточек`);
  ok(fs.readFileSync(P('dist/admin/seo/index.html'), 'utf8').includes('/garantiya/'), 'SEO-отчёт видит новую страницу');
  const rep = JSON.parse(fs.readFileSync(P('dist/admin/seo/report.json'), 'utf8'));
  ok(rep.pages.find((x) => x.url === '/sirota/')?.issues.some((i) => i.includes('нет ссылок')), 'SEO-отчёт: страница без входящих ссылок помечена');
  ok(!rep.pages.find((x) => x.url === '/garantiya/')?.issues.some((i) => i.includes('нет ссылок')), 'SEO-отчёт: страница со ссылкой из меню не считается сиротой');
  ok(!rep.pages.find((x) => x.url === '/catalog/pryamye-divany/rogozhka/')?.issues.some((i) => i.includes('нет ссылок')), 'SEO-отчёт: подборка с чипом в разделе не считается сиротой');
  ok(page('/').includes('111-22-33'), 'настройки: новый телефон в шапке');

  // смена адреса / скрытие / удаление
  ok(h1Of(page('/catalog/uglovye-divany/milano-plus/')).length > 0, 'смена адреса: товар открывается по новому адресу');
  ok(refreshTo(page('/catalog/uglovye-divany/milano/')) === `${BASE}/catalog/uglovye-divany/milano-plus/`, 'смена адреса: со старого адреса переадресация на новый');
  ok(!fs.readdirSync(P('dist'), { recursive: true }).filter((f) => f.endsWith('index.html') && !/^(go|admin)\//.test(f) && !refreshTo(page('/' + f.replace(/index\.html$/, '')))).some((f) => hasLink(page('/' + f.replace(/index\.html$/, '')), '/catalog/uglovye-divany/milano/')), 'смена адреса: на сайте не осталось ссылок на старый адрес (в текстах подменяются автоматически)');
  ok(refreshTo(page('/catalog/pryamye-divany/teo/')) === `${BASE}/catalog/pryamye-divany/`, 'скрытый товар: адрес переадресует в категорию');
  ok(!cardLinks(page('/catalog/pryamye-divany/')).includes(`${BASE}/catalog/pryamye-divany/teo/`) && !hasLink(page('/catalog/pryamye-divany/'), '/catalog/pryamye-divany/teo/'), 'скрытый товар: пропал из категории, ссылки в текстах ведут в категорию');
  ok(refreshTo(page('/news/novyy-sayt-i-katalog/')) === `${BASE}/news/`, 'удалённая новость: адрес переадресует в /news/');
  const auto = J('settings/redirects-auto.json');
  ok(auto['/catalog/uglovye-divany/milano/'] === '/catalog/uglovye-divany/milano-plus/', 'переадресации сохранены в settings/redirects-auto.json (для следующих сборок)');
  r = run('node', ['scripts/qa-dist.mjs']);
  ok(r.code === 0, `QA сборки после правок: ${(r.out.match(/QA:[^\n]*/) || [''])[0]}`);

  // ═══ 2. Повторная сборка и цепочка адресов ═══
  step('Повторная сборка без правок, затем ещё одна смена адреса');
  r = build();
  ok(r.code === 0 && refreshTo(page('/catalog/uglovye-divany/milano/')) === `${BASE}/catalog/uglovye-divany/milano-plus/`, 'повторная сборка: переадресации не теряются');
  edit('products/uglovye-divany--milano.json', (d) => { d.slug = 'milano-3'; });
  edit('products/pryamye-divany--teo.json', (d) => { d.hidden = false; });
  r = build();
  ok(r.code === 0, 'сборка после второй смены адреса');
  ok(refreshTo(page('/catalog/uglovye-divany/milano/')) === `${BASE}/catalog/uglovye-divany/milano-3/`, 'цепочка схлопнута: самый старый адрес ведёт сразу на последний');
  ok(refreshTo(page('/catalog/uglovye-divany/milano-plus/')) === `${BASE}/catalog/uglovye-divany/milano-3/`, 'промежуточный адрес тоже ведёт на последний');
  h = page('/catalog/pryamye-divany/teo/');
  ok(!!h && !refreshTo(h) && h1Of(h).length > 0, 'товар вернули на сайт: снова живая страница, без переадресации');
  // смена адреса категории с товарами: товары переезжают вместе с ней, со старых адресов переадресации
  edit('categories/sofy.json', (d) => { d.slug = 'divany-sofy'; });
  r = build();
  ok(r.code === 0, 'смена адреса категории с товарами: сборка проходит (товары ссылаются на категорию по имени файла)');
  ok(h1Of(page('/catalog/divany-sofy/sofa-test/')) === 'Софа Тест', 'товар переехал на адрес новой категории');
  ok(refreshTo(page('/catalog/sofy/')) === `${BASE}/catalog/divany-sofy/` && refreshTo(page('/catalog/sofy/sofa-test/')) === `${BASE}/catalog/divany-sofy/sofa-test/`, 'со старых адресов категории и товара переадресации на новые');
  ok(hasLink(cmenu(page('/')), '/catalog/divany-sofy/') && !hasLink(cmenu(page('/')), '/catalog/sofy/'), 'меню «Каталог товаров»: категория по новому адресу');
  r = run('node', ['scripts/qa-dist.mjs']);
  ok(r.code === 0, `QA: ${(r.out.match(/QA:[^\n]*/) || [''])[0]}`);
  r = run('node', ['scripts/links-audit.mjs']);
  console.log('    перелинковка:', (r.out.match(/страниц[^\n]*/) || [''])[0].slice(0, 200));

  // ═══ 3. Боевой режим: robots, canonical, sitemap ═══
  step('Боевой режим (PREVIEW=0): robots, canonical, noindex, sitemap');
  edit('pages/garantiya.json', (d) => { d.noindex = true; });
  edit('news/test-novost.json', (d) => { d.canonical = '/news/'; });
  r = build({ PREVIEW: '0' });
  ok(r.code === 0, 'боевая сборка проходит');
  ok(robotsOf(page('/catalog/pryamye-divany/')) === 'index, follow', 'обычная страница: index, follow');
  ok(robotsOf(page('/garantiya/')) === 'noindex, follow', 'галочка «Закрыть от индексации»: noindex, follow');
  ok(canonOf(page('/news/test-novost/')) === 'https://simmetrika-mebel.ru/news/', 'поле Canonical из админки попадает в <link rel=canonical>');
  ok(canonOf(page('/catalog/pryamye-divany/edzhio/')) === 'https://simmetrika-mebel.ru/catalog/pryamye-divany/edzhio/', 'по умолчанию canonical на себя');
  const thin = longN < 3;
  ok(robotsOf(page('/catalog/uglovye-divany/ot-330-sm/')) === (thin ? 'noindex, follow' : 'index, follow'), `подборка из ${longN} товаров: ${thin ? 'закрыта (меньше 3)' : 'открыта'}`);
  ok(robotsOf(page('/catalog/pryamye-divany/v-nalichii/')) === (stockN < 3 ? 'noindex, follow' : 'index, follow'), `подборка из ${stockN} товаров: правило 3 товаров`);
  const smFile = fs.readdirSync(P('dist')).find((f) => /^sitemap-0\.xml$/.test(f));
  const sm = smFile ? fs.readFileSync(P('dist', smFile), 'utf8') : '';
  ok(!!sm, 'sitemap собран');
  ok(sm.includes('/catalog/divany-sofy/sofa-test/') && sm.includes('/catalog/divany-sofy/') && !sm.includes('/catalog/sofy/'), 'sitemap: новые товар и категория по новому адресу, старого адреса нет');
  ok(!sm.includes('/garantiya/') && !sm.includes('/news/test-novost/'), 'sitemap: страницы с noindex и чужим canonical исключены');
  ok(!sm.includes('/admin/') && !sm.includes('/go/') && !sm.includes('/zayavka/'), 'sitemap: админка, /go/ и заявка исключены');
  ok(thin ? !sm.includes('/ot-330-sm/') : true, 'sitemap: подборка меньше 3 товаров исключена');
  r = build(); // вернуть превью-режим для негативных тестов не требуется, но держим dist в виде превью
  ok(r.code === 0, 'превью-сборка снова проходит');

  // ═══ 4. Ошибки редактора: должны останавливать сборку ═══
  step('Ошибки редактора: проверка останавливает публикацию и объясняет причину');
  const neg = (name, rel, mutate, expect) => {
    const file = P('src/cms', rel);
    const had = fs.existsSync(file); const before = had ? fs.readFileSync(file, 'utf8') : null;
    if (mutate === 'broken') fs.writeFileSync(file, before.slice(0, Math.floor(before.length / 2)));
    else if (mutate === 'delete') fs.rmSync(file);
    else if (typeof mutate === 'function') { const d = had ? JSON.parse(before) : {}; mutate(d); fs.mkdirSync(path.dirname(file), { recursive: true }); fs.writeFileSync(file, JSON.stringify(d, null, 2)); }
    const res = validate();
    ok(res.code === 1 && res.out.includes(expect), `${name} → «${expect}»${res.code === 1 ? '' : ' (проверка НЕ остановила)'}`);
    if (had) fs.writeFileSync(file, before); else fs.rmSync(file);
  };
  neg('битый JSON', 'products/pryamye-divany--kloud.json', 'broken', 'не читается как JSON');
  neg('два раза один адрес', 'collections/dubl.json', (d) => Object.assign(d, blank('collections'), { url: '/sale/', h1: 'Дубль', type: 'filter' }), 'уже занят');
  neg('адрес кириллицей', 'pages/garantiya.json', (d) => { d.url = '/гарантия/'; }, 'строчная латиница');
  neg('служебный адрес', 'pages/garantiya.json', (d) => { d.url = '/admin/'; }, 'служебным разделом');
  neg('адрес товара с пробелом', 'products/sofy--sofa-test.json', (d) => { d.slug = 'Sofa Test'; }, 'строчная латиница');
  neg('нет H1 у страницы', 'pages/garantiya.json', (d) => { d.h1 = ''; }, 'не заполнен H1');
  neg('нет обязательного поля', 'products/sofy--sofa-test.json', (d) => { d.name = ''; }, 'обязательное поле не заполнено');
  neg('категория не из списка', 'products/sofy--sofa-test.json', (d) => { d.category = 'nesushchestvuet'; }, 'не найдено');
  neg('цвет не из списка в правиле', 'collections/catalog-pryamye-divany-v-nalichii.json', (d) => { d.listing.rule.color = 'fioletovyy'; }, 'не найдено');
  neg('текст вместо числа', 'products/sofy--sofa-test.json', (d) => { d.price_from = 'дорого'; }, 'ожидалось число');
  neg('неизвестная вставка', 'pages/garantiya.json', (d) => { d.body += '\n:::productz{rule="all"}\n:::\n'; }, 'неизвестная вставка');
  neg('незакрытая вставка', 'pages/garantiya.json', (d) => { d.body += '\n:::note\nзаметка без закрытия\n'; }, 'не хватает строки «:::»');
  neg('неверное правило в тексте', 'pages/garantiya.json', (d) => { d.body += '\n:::products{rule="colour=seryy"}\n:::\n'; }, 'неизвестное условие');
  neg('несуществующая форма', 'pages/garantiya.json', (d) => { d.body += '\n:::form{type="zakaz"}\n:::\n'; }, 'не существует');
  neg('битая ссылка в тексте', 'pages/garantiya.json', (d) => { d.body += '\nСм. [страницу](/net-takoy-stranicy/).\n'; }, 'несуществующую страницу');
  neg('битая ссылка в плитках', 'pages/garantiya.json', (d) => { d.body += '\n:::tiles{items="/catalog/,/net-razdela/"}\n:::\n'; }, 'страницы /net-razdela/ нет');
  neg('битая ссылка в меню', 'settings/menu.json', (d) => d.nav.push({ title: 'Нет', url: '/nope/' }), 'страницы /nope/ нет');
  neg('два цвета с одним кодом', 'settings/fabrics.json', (d) => d.colors.push({ ...d.colors[0], name: 'Дубль' }), 'уже есть у другой записи');
  neg('код цвета кириллицей', 'settings/fabrics.json', (d) => d.colors.push({ slug: 'серый-2', name: 'Серый 2' }), 'строчная латиница');
  neg('удалён цвет, который используется', 'settings/fabrics.json', (d) => { d.colors = d.colors.filter((c) => c.slug !== 'seryy'); }, 'запись удалена?');
  neg('образец без шоурума', 'products/sofy--sofa-test.json', (d) => { d.samples = [{ material: 'velyur', discount_pct: 20 }]; }, 'обязательное поле не заполнено');
  neg('нет файла фото', 'products/sofy--sofa-test.json', (d) => { d.photos = ['/images/uploads/net-takogo.jpg']; }, 'нет файла картинки');
  // пустые пункты списков (Sveltia оставляет "" после удаления файла из медиатеки) не должны останавливать публикацию
  edit('products/sofy--sofa-test.json', (d) => { d.photos = ['', '']; d.faq = [{ q: '', a: '' }]; });
  r = validate();
  ok(r.code === 0, 'пустые пункты в фото и FAQ не считаются ошибкой (на сайте отбрасываются)');
  edit('products/sofy--sofa-test.json', (d) => { d.photos = []; d.faq = []; });
  neg('несуществующий цвет в наличии', 'products/sofy--sofa-test.json', (d) => { d.in_stock = { material: 'velyur', color: 'fioletovyy' }; }, 'не найдено');
  neg('товар в новости удалён', 'news/test-novost.json', (d) => { d.product_ids = ['net-takogo--tovara']; }, 'не найдено');
  neg('удалили категорию, а товары в ней остались', 'categories/sofy.json', 'delete', 'запись удалена');
  neg('пустой ответ FAQ', 'pages/garantiya.json', (d) => { d.faq = [{ q: 'Вопрос?', a: '' }]; }, 'обязательное поле не заполнено');
  neg('поле, которого нет в админке', 'products/sofy--sofa-test.json', (d) => { d.foo = 1; }, 'не описано в админке');
  neg('переадресация в никуда', 'settings/redirects.json', (d) => d.redirects.push({ from: '/old/', to: '/net-celi/' }), 'не существует');
  neg('неверная дата новости', 'news/test-novost.json', (d) => { d.date = '22.10.2026'; }, 'ГГГГ-ММ-ДД');
  // и сама сборка (как в GitHub Actions) должна упасть, не выкладывая сломанный сайт
  const kloud = P('src/cms/products/pryamye-divany--kloud.json'); const kb = fs.readFileSync(kloud, 'utf8');
  fs.writeFileSync(kloud, '{"name": ');
  r = build();
  ok(r.code !== 0, 'с ошибкой в данных npm run build завершается с ошибкой: сайт не обновляется');
  fs.writeFileSync(kloud, kb);
  r = validate();
  ok(r.code === 0, 'после исправления проверка снова зелёная');
} catch (e) {
  fails.push('прервано: ' + e.message.slice(0, 3000));
  console.error(e.message);
}

console.log(`\nИтого: пройдено ${pass}, ошибок ${fails.length}`);
if (fails.length) { console.log('Не прошло:\n- ' + fails.join('\n- ')); console.log(`Копия для разбора: ${TMP}`); process.exit(1); }
fs.rmSync(TMP, { recursive: true, force: true });
