// Проверка данных админки перед сборкой (npm run validate, prebuild) и SEO-отчёт после неё (--report → seo-report/: только локально для тестов и SEO-специалиста, на сайт не публикуется).
// ОШИБКИ (сборка останавливается, сайт остаётся прежним): битый JSON, пустые обязательные поля, неверный адрес,
//   дубль адреса, значение не из списка, неизвестная вставка :::, незакрытая вставка, неверное правило отбора,
//   ссылка на несуществующую страницу, нет файла картинки, пустой вопрос/ответ FAQ, поле, которого нет в админке.
// ЗАМЕЧАНИЯ (сайт собирается): длины title/description, дубли title/description/H1, длинное тире,
//   главный запрос не в H1/title, подборка < 3 товаров, товар без фото или цены, тяжёлые картинки.
import fs from 'node:fs';
import path from 'node:path';

const REPORT = process.argv.includes('--report');
const QUIET = process.argv.includes('--quiet');
const errors = []; const warns = []; const seoRows = [];
const E = (where, msg) => errors.push({ where, msg });
const W = (where, msg) => warns.push({ where, msg });

const CONFIG = 'public/admin/config.yml';
if (!fs.existsSync(CONFIG)) { console.error('[validate] нет public/admin/config.yml: сначала node scripts/gen-cms-config.mjs'); process.exit(1); }
const cfg = JSON.parse(fs.readFileSync(CONFIG, 'utf8').replace(/^#.*\n/, ''));

// ── 1. JSON и соответствие полям админки ──
const entries = []; // { col, file, rel, data }
const read = (rel) => { try { return JSON.parse(fs.readFileSync(rel, 'utf8')); } catch (e) { E(rel, `файл не читается как JSON: ${e.message.split('\n')[0]}`); return null; } };
const empty = (v) => v === undefined || v === null || v === '' || (Array.isArray(v) && !v.length);
// целиком пустой пункт списка (Sveltia оставляет "" после удаления файла из медиатеки): на сайте отбрасывается, не ошибка
const blank = (v) => empty(v) || (typeof v === 'string' && !v.trim()) || (v && typeof v === 'object' && !Array.isArray(v) && Object.values(v).every(blank)) || (Array.isArray(v) && v.every(blank));
const optVals = (f) => (f.options || []).map((o) => (typeof o === 'object' ? o.value : o));
// допустимые значения связи (relation): из файлов коллекции или настроек, как их видит админка
const relCache = {};
function relValues(f) {
  const key = `${f.collection}|${f.file || ''}|${f.value_field}`;
  if (relCache[key]) return relCache[key];
  const c = cfg.collections.find((x) => x.name === f.collection);
  const pick = (obj, pathStr) => pathStr.split('.').reduce((acc, part) => acc.flatMap((v) => (part === '*' ? (Array.isArray(v) ? v : []) : v?.[part] !== undefined ? [v[part]] : [])), [obj]);
  let vals = [];
  if (f.file) { const fd = c?.files?.find((x) => x.name === f.file); try { vals = pick(JSON.parse(fs.readFileSync(fd.file, 'utf8')), f.value_field); } catch {} }
  else if (c?.folder && fs.existsSync(c.folder)) for (const file of fs.readdirSync(c.folder).filter((x) => x.endsWith('.json'))) {
    if (f.value_field === '{{slug}}') { vals.push(file.slice(0, -5)); continue; }
    try { vals.push(...pick(JSON.parse(fs.readFileSync(`${c.folder}/${file}`, 'utf8')), f.value_field)); } catch {}
  }
  return (relCache[key] = vals.filter((v) => v !== '' && v != null).map(String));
}
function checkFields(fields, data, where) {
  if (!data || typeof data !== 'object' || Array.isArray(data)) { E(where, 'ожидался объект'); return; }
  const known = new Set(fields.map((f) => f.name));
  for (const k of Object.keys(data)) if (!known.has(k)) E(where, `поле «${k}» не описано в админке (при сохранении из админки пропадёт): добавьте его в scripts/gen-cms-config.mjs`);
  for (const f of fields) checkValue(f, data[f.name], `${where} › ${f.label || f.name}`);
}
function checkValue(f, v, where) {
  if (f.widget === 'hidden') return;
  if (empty(v)) { if (f.required) E(where, 'обязательное поле не заполнено'); return; }
  switch (f.widget) {
    case 'string': case 'text': case 'image': case 'color':
      if (typeof v !== 'string') { E(where, `ожидался текст, а записано ${JSON.stringify(v).slice(0, 40)}`); break; }
      if (f.pattern && !new RegExp(f.pattern[0]).test(v)) E(where, `«${v.slice(0, 60)}»: ${f.pattern[1]}`);
      if (f.maxlength && v.length > f.maxlength) E(where, `длина ${v.length} знаков, больше допустимых ${f.maxlength}`);
      break;
    case 'number':
      if (typeof v !== 'number' || Number.isNaN(v)) E(where, `ожидалось число, а записано ${JSON.stringify(v)}`);
      else if (v < 0) E(where, 'число не может быть отрицательным'); break;
    case 'boolean': if (typeof v !== 'boolean') E(where, 'ожидалось да/нет'); break;
    case 'datetime': if (typeof v !== 'string' || !/^\d{4}-\d{2}-\d{2}/.test(v) || Number.isNaN(Date.parse(v.slice(0, 10)))) E(where, `дата в формате ГГГГ-ММ-ДД, а записано ${JSON.stringify(v)}`); break;
    case 'select': {
      const vals = optVals(f);
      for (const x of f.multiple ? [].concat(v) : [v]) if (!vals.includes(x)) E(where, `значение «${x}» не из списка (${vals.slice(0, 8).join(', ')}${vals.length > 8 ? '…' : ''})`);
      if (!f.multiple && Array.isArray(v)) E(where, 'ожидалось одно значение');
      break;
    }
    case 'relation': {
      const vals = relValues(f);
      for (const x of f.multiple ? [].concat(v) : [v]) if (!blank(x) && !vals.includes(String(x))) E(where, `значение «${x}» не найдено среди записей «${cfg.collections.find((c) => c.name === f.collection)?.label || f.collection}» (запись удалена?): выберите другое. Есть: ${vals.slice(0, 8).join(', ')}${vals.length > 8 ? '…' : ''}`);
      if (!f.multiple && Array.isArray(v)) E(where, 'ожидалось одно значение');
      break;
    }
    case 'object': checkFields(f.fields, v, where); break;
    case 'list':
      if (!Array.isArray(v)) { E(where, 'ожидался список'); break; }
      v.forEach((item, i) => { if (blank(item)) return; f.field ? checkValue({ ...f.field, required: true }, item, `${where} #${i + 1}`) : checkFields(f.fields, item, `${where} #${i + 1}`); });
      break;
  }
}
const colDefs = {};
for (const c of cfg.collections) {
  colDefs[c.name] = c;
  if (c.folder) {
    if (!fs.existsSync(c.folder)) continue;
    for (const f of fs.readdirSync(c.folder).filter((x) => x.endsWith('.json')).sort()) {
      const rel = `${c.folder}/${f}`; const data = read(rel);
      if (!data) continue;
      const file = f.slice(0, -5);
      if (!/^[a-z0-9]+(-[a-z0-9]+)*(--[a-z0-9]+(-[a-z0-9]+)*)*$/.test(file)) E(rel, 'имя файла записи: только латиница, цифры и дефис');
      entries.push({ col: c.name, file, rel, data });
      checkFields(c.fields, data, `${c.label} › ${data.h1 || data.name || file}`);
    }
  }
  for (const f of c.files || []) { const data = read(f.file); if (data) checkFields(f.fields, data, `${c.label} › ${f.label}`); }
}
const nonCmsJson = ['src/cms/settings/url-map.json', 'src/cms/settings/redirects-auto.json', 'src/cms/settings/ui.json'];
for (const p of nonCmsJson) if (fs.existsSync(p)) read(p);
if (errors.length) finish(); // с битым JSON дальше проверять нельзя

// ── 2. Адреса, связи, тексты (по собранной модели сайта) ──
const { cms, normUrl, redirectList } = await import(path.resolve('src/lib/cms.mjs'));
const site = cms();
const SLUG = /^[a-z0-9]+(-[a-z0-9]+)*$/;
const URL_RE = /^\/([a-z0-9]+(-[a-z0-9]+)*\/)*$/;
const RESERVED = ['/admin/', '/go/', '/img/', '/images/', '/js/', '/_astro/'];
const label = (e) => `${colDefs[e.col]?.label_singular || e.col} «${e.data.h1 || e.data.name || e.file}»`;
const where = (e) => `${label(e)} (${e.rel})`;

for (const e of entries) {
  const d = e.data;
  if (['products', 'categories', 'news'].includes(e.col) && d.slug && !SLUG.test(d.slug)) E(where(e), `адрес «${d.slug}»: только строчная латиница, цифры и дефис (например, divan-milano)`);
  if (['collections', 'pages'].includes(e.col) && d.url) {
    const raw = String(d.url).trim();
    if (!URL_RE.test(normUrl(raw))) E(where(e), `адрес «${raw}»: только строчная латиница, цифры, дефис и слэши (например, /garantiya/)`);
    if (RESERVED.some((r) => normUrl(raw).startsWith(r))) E(where(e), `адрес «${raw}» занят служебным разделом`);
  }
  if (e.col === 'pages' && d.id && !SLUG.test(d.id)) E(where(e), `код страницы «${d.id}»: только латиница, цифры, дефис`);
  if (e.col === 'pages' && d.id && d.id !== e.file) W(where(e), `код страницы «${d.id}» не совпадает с именем файла «${e.file}» (так бывает после переименования, на сайт не влияет)`);
  if (e.col === 'products' && d.category && d.slug && e.file !== `${d.category}--${d.slug}`) W(where(e), `имя файла «${e.file}» не совпадает с категорией и адресом (так бывает после смены категории или адреса: переадресация со старого адреса создаётся автоматически)`);
  if (e.col === 'products' && d.category && !d.hidden && entries.find((x) => x.col === 'categories' && (x.file === d.category || x.data.slug === d.category))?.data.hidden) W(where(e), `категория «${d.category}» скрыта: товар не показывается на сайте`);
  if (['pages', 'collections', 'news'].includes(e.col) && empty(d.h1) && !['ui'].includes(d.type)) E(where(e), 'не заполнен H1');
  for (const [i, q] of (d.faq || []).entries()) if (!blank(q) && (blank(q?.q) || blank(q?.a))) E(where(e), `FAQ #${i + 1}: пустой вопрос или ответ`);
}

// дубли адресов
const byUrl = new Map();
for (const p of site.pages) {
  const u = p.data.url; if (!u || p.col === 'settings' || String(u).includes('{')) continue;
  if (byUrl.has(u)) { const a = byUrl.get(u); E(`${p.col}/${p.file}.json`, `адрес ${u} уже занят записью ${a.col}/${a.file}.json: у двух страниц не может быть одного адреса`); }
  else byUrl.set(u, p);
}
const live = new Set(byUrl.keys()); live.add('/');
const { resolved } = redirectList();
const files = (p) => fs.existsSync(path.join('public', decodeURI(p)));
const linkOk = (href) => {
  if (!href || /^(https?:|mailto:|tel:|#|callback$)/.test(href)) return true;
  const p = href.split('#')[0].split('?')[0];
  if (!p) return true;
  if (/\.[a-z0-9]{2,5}$/i.test(p)) return files(p) || !!resolved[p];
  const n = normUrl(p);
  return live.has(n) || !!resolved[n];
};
const isRedirect = (href) => { const n = normUrl(String(href).split('#')[0].split('?')[0]); return !live.has(n) && !!resolved[n]; };

// правила отбора
const CAT = new Set(site.catalog.categories.map((c) => c.slug));
const MECH = new Set(Object.keys(site.catalog.mechanisms));
const MAT = new Set(site.catalog.fabrics.map((f) => f.material));
const COL = new Set(Object.keys(site.catalog.colors));
const SHOW = new Set(site.catalog.showrooms.map((s) => s.slug));
const TAGS = new Set(['dlya-gostinoy', 'dizaynerskie', 'bolshie']);
const BOOL = new Set(['true', 'false']);
const RULE = { all: null, category: CAT, mechanism: MECH, material: MAT, color: COL, tag: TAGS, showroom: SHOW, in_stock: BOOL, sample: BOOL, hit: BOOL, has_sleeping_place: BOOL, has_storage: BOOL, length_gte: 'num' };
function checkRule(rule, w) {
  if (!rule || rule === 'all') return;
  for (const part of String(rule).split(',')) {
    const [k, v0] = part.split('=').map((s) => s.trim()); const v = v0 ?? 'true';
    if (!(k in RULE)) { E(w, `правило «${rule}»: неизвестное условие «${k}» (можно: ${Object.keys(RULE).join(', ')})`); continue; }
    const allowed = RULE[k];
    if (allowed === 'num' ? !/^\d+$/.test(v) : allowed && !allowed.has(v) && v !== '{slug}') E(w, `правило «${rule}»: значение «${v}» для «${k}» не найдено`);
  }
}

// вставки ::: в тексте
const DIRS = { products: ['rule', 'limit'], filters: ['set'], tiles: ['items'], chips: ['items'], 'spec-table': ['rule', 'cols'], swatches: ['materials'], showrooms: ['mode'], map: [], news: ['limit', 'type'], promo: [], form: ['type', 'title', 'id'], steps: ['title', 'id'], stats: ['title', 'id'], compare: ['title', 'id'], scheme: ['kind', 'title', 'id', 'active'], cta: ['href', 'label', 'action', 'id'], note: ['id'], practice: ['id'] };
const FORMS = ['question', 'callback', 'custom_size', 'sample_reserve', 'dealer'];
const SCHEMES = /^(color-palette|mechanisms|mechanism-(all|pantograf|venetsiya|vykatnoy)|corner-side|modules|custom-dimensions|room-layout|delivery-zones|showrooms-mkad|.*(scale|ruler|sizes|sleeping).*)$/;
const attrsOf = (s = '') => Object.fromEntries([...s.matchAll(/([a-z][\w-]*)=(?:"([^"]*)"|'([^']*)'|([^\s}]+))/gi)].map((m) => [m[1], m[2] ?? m[3] ?? m[4]]));
function checkBody(md, w) {
  if (!md) return;
  const lines = md.split('\n');
  let depth = 0; let fence = false;
  lines.forEach((line, i) => {
    if (/^\s*```/.test(line)) fence = !fence;
    if (fence) return;
    const at = `${w}, строка ${i + 1}`;
    const m = line.match(/^\s*(:{2,})([a-z][\w-]*)\s*(\{[^}]*\}?)?\s*$/i);
    if (m) {
      const [, colons, name, rawAttrs = ''] = m;
      if (!(name in DIRS)) { E(at, `неизвестная вставка «${colons}${name}» (есть: ${Object.keys(DIRS).join(', ')})`); }
      if (rawAttrs && !rawAttrs.endsWith('}')) E(at, `вставка «${name}»: не закрыта фигурная скобка`);
      if ((rawAttrs.match(/"/g) || []).length % 2) E(at, `вставка «${name}»: непарные кавычки`);
      const a = attrsOf(rawAttrs);
      for (const k of Object.keys(a)) if (DIRS[name] && !DIRS[name].includes(k)) W(at, `вставка «${name}»: параметр «${k}» не используется`);
      if (['products', 'spec-table'].includes(name)) checkRule(a.rule, at);
      if (['tiles', 'chips'].includes(name)) {
        if (!a.items) E(at, `вставка «${name}»: не указан список адресов items="/адрес/,/адрес/"`);
        for (const it of String(a.items || '').split(',').map((s) => s.trim()).filter(Boolean)) if (!it.includes('{') && !linkOk(it)) E(at, `вставка «${name}»: страницы ${it} нет на сайте`);
      }
      if (name === 'form' && a.type && !FORMS.includes(a.type)) E(at, `форма «${a.type}» не существует (есть: ${FORMS.join(', ')})`);
      if (name === 'scheme' && a.kind && !SCHEMES.test(a.kind)) W(at, `схема «${a.kind}» без рисунка: покажется только текст`);
      if (name === 'cta' && a.href && a.href !== 'callback' && !linkOk(a.href)) E(at, `кнопка ведёт на несуществующую страницу ${a.href}`);
      if (colons.length >= 3) depth++;
    } else if (/^\s*:::\s*$/.test(line)) {
      if (depth === 0) E(at, 'лишняя закрывающая строка «:::»'); else depth--;
    } else if (/^\s*:::/.test(line)) E(at, `строка вставки не распознана: «${line.trim().slice(0, 60)}»`);
    for (const lm of line.matchAll(/\]\(([^)\s]+)(?:\s+"[^"]*")?\)/g)) {
      const href = lm[1];
      if (!linkOk(href)) E(at, `ссылка на несуществующую страницу ${href}`);
      else if (href.startsWith('/') && isRedirect(href)) W(at, `ссылка на старый адрес ${href}: на сайте она автоматически ведёт на ${resolved[normUrl(href.split('#')[0])]}, но лучше исправить в тексте`);
    }
    for (const im of line.matchAll(/!\[[^\]]*\]\(([^)\s]+)/g)) if (im[1].startsWith('/') && !files(im[1])) E(at, `нет картинки ${im[1]}`);
  });
  if (depth > 0) E(w, `вставка не закрыта: не хватает строки «:::» (${depth} шт.)`);
}

// картинки в полях
const checkImg = (src, w) => {
  if (!src || /^https?:/.test(src)) return;
  if (src.startsWith('source/')) return; // старые ключи каталога (images.json)
  if (!src.startsWith('/')) { E(w, `путь картинки «${src}» должен начинаться с /`); return; }
  if (!files(src)) { E(w, `нет файла картинки ${src}`); return; }
  const kb = fs.statSync(path.join('public', decodeURI(src))).size / 1024;
  if (kb > 700) W(w, `картинка ${src} весит ${Math.round(kb)} КБ: сожмите до 300–500 КБ (WebP/JPG, 1600 px)`);
};

const SEO_SKIP = new Set(['cart', 'error', 'ui']);
const seen = { title: new Map(), description: new Map(), h1: new Map() };
const entryOf = (p) => entries.find((e) => e.col === p.col && e.file === p.file);
for (const p of site.pages) {
  if (p.col === 'settings' || !p.data.url || String(p.data.url).includes('{')) continue;
  const d = p.data; const e = entryOf(p); const w = e ? where(e) : `${p.col}/${p.file}`;
  checkBody(p.body, w);
  for (const r of d.related || []) if (r.url && !linkOk(r.url)) E(w, `«Смотрите также»: страницы ${r.url} нет`);
  for (const b of [d.hero_cta?.primary, d.hero_cta?.secondary]) if (b?.href && !linkOk(b.href)) E(w, `кнопка «${b.label}»: страницы ${b.href} нет`);
  for (const im of d.images || []) checkImg(im.src, w);
  for (const ph of d.photos || []) checkImg(ph, w);
  checkImg(d.cover, w);
  for (const id of e?.data.product_ids || []) if (!site.catalog.products.some((x) => x.id === id || x._entry?.file === id)) W(w, `товар ${id} скрыт или удалён: в новости не покажется`);
  if (d.canonical && !/^https?:/.test(d.canonical) && !live.has(normUrl(d.canonical))) E(w, `canonical ведёт на несуществующую страницу ${d.canonical}`);

  const issues = [];
  const closed = d.noindex === true || /noindex/.test(d.robots || '') || !!d.canonical;
  if (!SEO_SKIP.has(d.type)) {
    const t = d.title || ''; const ds = d.description || ''; const h1 = d.h1 || '';
    if (!t) issues.push(`нет title (подставится «${h1 ? h1 + ' | SIMMETRIKA' : 'SIMMETRIKA'}»)`);
    else if (t.length > 65) issues.push(`title длинный: ${t.length} зн. (до 60–65)`);
    else if (t.length < 25) issues.push(`title короткий: ${t.length} зн. (50–60)`);
    if (!ds) issues.push('нет description');
    else if (ds.length > 170) issues.push(`description длинный: ${ds.length} зн. (140–160)`);
    else if (ds.length < 100) issues.push(`description короткий: ${ds.length} зн. (140–160)`);
    if (/—/.test(t + ds + h1)) issues.push('длинное тире «—» в title, description или H1: замените на «–» или запятую');
    if (d.primary_kw && !closed) {
      const yo = (s) => s.toLowerCase().replace(/ё/g, 'е');
      const kw = yo(d.primary_kw).split(/\s+/).filter((x) => x.length > 3).map((x) => x.slice(0, -2));
      const has = (s) => kw.every((k) => yo(s).includes(k));
      if (!has(h1)) issues.push(`главный запрос «${d.primary_kw}» не в H1`);
      if (t && !has(t)) issues.push(`главный запрос «${d.primary_kw}» не в title`);
    }
    if (!closed) for (const k of ['title', 'description', 'h1']) if (d[k]) { const key = d[k].trim().toLowerCase(); if (seen[k].has(key)) issues.push(`${k.toUpperCase() === 'H1' ? 'H1' : k} совпадает со страницей ${seen[k].get(key)}`); else seen[k].set(key, d.url); }
    if ((p.body || '').includes('—')) issues.push('длинное тире «—» в тексте');
  }
  if (p.col === 'products') {
    const pr = site.catalog.products.find((x) => x.url === d.url);
    if (pr && !pr.photos.length) issues.push('нет фото: в карточке будет серая заглушка');
    if (pr && !pr.price_from) issues.push('нет цены: «цена по запросу»');
  }
  if (['collections'].includes(p.col)) {
    const rule = Object.entries(d.listing?.rule || {}).map(([k, v]) => `${k}=${v}`).join(',');
    checkRule(rule, w);
    if (!rule) issues.push('правило отбора пустое: показываются все диваны');
  }
  for (const x of issues) W(w, x);
  seoRows.push({ url: d.url, col: p.col, file: p.file, title: d.title || '', desc: d.description || '', h1: d.h1 || '', closed, issues });
}
// страницы-сироты: на подборку или страницу нет ни одной ссылки из текстов, плиток, «Смотрите также» и меню
const inbound = new Map();
const addIn = (href, from) => { if (!href || !href.startsWith('/')) return; const n = normUrl(href.split('#')[0].split('?')[0]); const to = live.has(n) ? n : resolved[n]; if (to && to !== from) inbound.set(to, (inbound.get(to) || 0) + 1); };
for (const p of site.pages) {
  const d = p.data; const from = d.url;
  for (const m of (p.body || '').matchAll(/\]\((\/[^)\s]*)/g)) addIn(m[1], from);
  for (const m of (p.body || '').matchAll(/items="([^"]*)"/g)) m[1].split(',').forEach((x) => addIn(x.trim(), from));
  for (const m of (p.body || '').matchAll(/href="(\/[^"]*)"/g)) addIn(m[1], from);
  for (const r of d.related || []) addIn(r.url, from);
  for (const b of [d.hero_cta?.primary, d.hero_cta?.secondary]) addIn(b?.href, from);
}
for (const it of [...(site.menu.nav || []), ...(site.menu.nav_right || []), ...(site.menu.catalog_menu || []).flatMap((c) => c.items || [])]) addIn(it.url, '');
for (const c of site.catalog.categories) if (c.in_menu) addIn(c.url, ''); // категории сами дописываются в меню «Каталог товаров»
// раздел сам показывает чипы на вложенные подборки (src/pages/[...slug].astro)
for (const p of site.pages) if (['filter', 'collection'].includes(p.data.type) && p.data.url) { const par = p.data.url.replace(/[^/]+\/$/, ''); if (site.pages.some((x) => x.data.url === par && ['category', 'filter', 'collection'].includes(x.data.type))) addIn(p.data.url, par); }
for (const p of site.pages) if (['collections', 'pages'].includes(p.col) && p.data.url && !SEO_SKIP.has(p.data.type) && p.data.url !== '/' && !(inbound.get(p.data.url) > 0)) {
  const row = seoRows.find((r) => r.url === p.data.url); const msg = 'на страницу нет ссылок: поставьте ссылку из текста соседних страниц, плиток или меню, иначе поиск её почти не найдёт';
  if (row) row.issues.push(msg); W(where(entryOf(p) || { col: p.col, file: p.file, rel: `${p.col}/${p.file}.json`, data: p.data }), msg);
}
// меню и ручные переадресации
for (const [k, list] of Object.entries({ nav: site.menu.nav, nav_right: site.menu.nav_right })) for (const it of list || []) if (!linkOk(it.url)) E(`Меню сайта › ${it.title}`, `страницы ${it.url} нет`);
for (const col of site.menu.catalog_menu || []) for (const it of col.items || []) if (!linkOk(it.url)) E(`Меню «Каталог» › ${col.title} › ${it.title}`, `страницы ${it.url} нет`);
for (const r of site.redirects) {
  if (!r.from || !r.to) { E('Переадресации', `пустая строка: ${r.from || '?'} → ${r.to || '?'}`); continue; }
  if (!linkOk(r.to)) E(`Переадресации › ${r.from}`, `цель ${r.to} не существует`);
  if (live.has(normUrl(r.from))) W(`Переадресации › ${r.from}`, 'по этому адресу есть живая страница: переадресация не сработает');
}
for (const s of site.catalog.showrooms) if (typeof s.lat !== 'number' || typeof s.lon !== 'number') W(`Шоурум «${s.name}»`, 'нет координат: точка не покажется на карте');
for (const f of site.catalog.fabrics) for (const c of f.colors || []) if (!COL.has(c)) E(`Материал «${f.name}»`, `цвет «${c}» не найден в списке цветов`);
// коды в справочниках без повторов: второй цвет или шоурум с тем же кодом молча заменил бы первый
for (const [file, k, key, lbl] of [['fabrics', 'fabrics', 'material', 'Материалы обивки'], ['fabrics', 'colors', 'slug', 'Цвета'], ['fabrics', 'mechanisms', 'slug', 'Механизмы'], ['showrooms', 'showrooms', 'slug', 'Шоурумы']]) {
  let d = {}; try { d = JSON.parse(fs.readFileSync(`src/cms/settings/${file}.json`, 'utf8')); } catch {}
  const seen = new Set();
  for (const x of d[k] || []) { const v = x?.[key]; if (!v) continue; if (seen.has(v)) E(`Настройки › ${lbl} › ${x.name || v}`, `код «${v}» уже есть у другой записи: у каждой свой код`); seen.add(v); if (k === 'colors' && !x.hex) W(`Настройки › ${lbl} › ${x.name || v}`, 'не выбран «Цвет кружка»: на сайте кружок будет серым'); }
}
finish();

function finish() {
  const out = (arr, tag) => arr.forEach((x) => console[tag === 'ОШИБКА' ? 'error' : 'log'](`${tag}: ${x.where}: ${x.msg}`));
  out(errors, 'ОШИБКА');
  if (!QUIET) out(warns.slice(0, REPORT ? 0 : 400), 'замечание');
  console.log(`[validate-cms] ошибок: ${errors.length}, замечаний: ${warns.length}`);
  if (REPORT) writeReport();
  // в GitHub Actions причина видна на первой странице запуска
  if (process.env.GITHUB_ACTIONS && errors.length) {
    for (const x of errors) console.log(`::error title=Сайт не опубликован::${x.where}: ${x.msg}`);
    if (process.env.GITHUB_STEP_SUMMARY) fs.appendFileSync(process.env.GITHUB_STEP_SUMMARY, `## Сайт не опубликован, ошибок: ${errors.length}\n\nСайт работает в прежнем виде. Исправьте записи в админке и снова нажмите «Опубликовать изменения».\n\n${errors.map((x) => `- **${x.where}**: ${x.msg}`).join('\n')}\n`);
  }
  process.exit(errors.length ? 1 : 0);
}

function writeReport() {
  const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  const edit = (r) => `../#/collections/${r.col}/entries/${r.file}`;
  const rows = seoRows.sort((a, b) => b.issues.length - a.issues.length || a.url.localeCompare(b.url));
  // у закрытых от индексации страниц длина не важна: цифра серая, а не красная
  const len = (s, lo, hi, closed) => `<span class="${closed ? 'cl' : s.length > hi || s.length < lo ? 'bad' : 'ok'}">${s.length}</span>`;
  const html = `<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>SEO-проверка · SIMMETRIKA</title><link rel="icon" href="../../favicon.svg" type="image/svg+xml">
<style>body{font:14px/1.45 -apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;padding:16px;color:#222;background:#fafafa}h1{font-size:22px;margin:0 0 4px}.sum{margin:0 0 16px;color:#555}.err{background:#fde8ea;border-left:4px solid #c8102e;padding:8px 12px;margin:0 0 6px}.tbl{overflow-x:auto}table{border-collapse:collapse;width:100%;background:#fff;font-size:13px}th,td{border:1px solid #e3e3e3;padding:6px 8px;vertical-align:top;text-align:left}th{background:#f1f1f1;position:sticky;top:0}.bad{color:#c8102e;font-weight:600}.ok{color:#2e7d32}.cl{color:#888;font-size:12px}ul{margin:0;padding-left:18px}a{color:#0b57d0}input{padding:6px 10px;width:min(420px,100%);margin:0 0 10px;border:1px solid #ccc;border-radius:6px}</style></head><body>
<h1>SEO-проверка сайта</h1><p class="sum">Собрано ${new Date().toISOString().slice(0, 16).replace('T', ' ')} UTC. Страниц: ${rows.length}. Ошибок: <b class="${errors.length ? 'bad' : 'ok'}">${errors.length}</b>. Замечаний: ${warns.length}. Ошибки останавливают публикацию, замечания нет. Нормы: title 50–65 знаков, description 140–160, один H1 с главным запросом.</p>
${errors.map((x) => `<p class="err"><b>${esc(x.where)}</b>: ${esc(x.msg)}</p>`).join('')}
<input type="search" placeholder="Фильтр по адресу или тексту" oninput="for(const r of document.querySelectorAll('tbody tr'))r.hidden=!r.textContent.toLowerCase().includes(this.value.toLowerCase())">
<div class="tbl"><table><thead><tr><th>Страница</th><th>Title</th><th>зн.</th><th>Description</th><th>зн.</th><th>H1</th><th>Замечания</th></tr></thead><tbody>
${rows.map((r) => `<tr><td><a href="../../${esc(r.url.slice(1))}" target="_blank">${esc(r.url)}</a>${r.closed ? '<div class="cl">закрыта от индексации</div>' : ''}<div><a href="${edit(r)}">править</a></div></td><td>${esc(r.title)}</td><td>${len(r.title, 25, 65, r.closed)}</td><td>${esc(r.desc)}</td><td>${len(r.desc, 100, 170, r.closed)}</td><td>${esc(r.h1)}</td><td>${r.issues.length ? `<ul>${r.issues.map((i) => `<li>${esc(i)}</li>`).join('')}</ul>` : '<span class="ok">ok</span>'}</td></tr>`).join('\n')}
</tbody></table></div>
<h2>Прочие замечания</h2><ul>${warns.filter((w) => !rows.some((r) => r.issues.includes(w.msg))).map((x) => `<li><b>${esc(x.where)}</b>: ${esc(x.msg)}</li>`).join('')}</ul>
</body></html>`;
  fs.mkdirSync('seo-report', { recursive: true });
  fs.writeFileSync('seo-report/index.html', html);
  fs.writeFileSync('seo-report/report.json', JSON.stringify({ errors, warns, pages: rows }, null, 1));
  console.log('[validate-cms] отчёт: seo-report/index.html');
}
