// Слой данных админки: читает src/cms/<коллекция>/*.json и собирает
//  1) каталог (товары, категории, ткани, шоурумы, настройки) в прежнем формате catalog.json,
//  2) список страниц [{ col, file, data, body }] в прежнем формате content/*.md.
// Адреса: товар /catalog/<категория>/<slug>/, категория /catalog/<slug>/, новость /news/<slug>/, подборки и страницы — поле url.
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve('src/cms');
// Sveltia сохраняет незаполненные поля пустыми ("", null, [], {}); убираем их, чтобы шаблоны не рисовали пустые кнопки, плитки и подписи
const prune = (v) => {
  if (Array.isArray(v)) { const a = v.map(prune).filter((x) => x !== undefined); return a.length ? a : undefined; }
  if (v && typeof v === 'object') { const o = Object.fromEntries(Object.entries(v).map(([k, x]) => [k, prune(x)]).filter(([, x]) => x !== undefined)); return Object.keys(o).length ? o : undefined; }
  if (v === '' || v === null || (typeof v === 'string' && !v.trim())) return undefined;
  return v;
};
const readJson = (p) => prune(JSON.parse(fs.readFileSync(p, 'utf8'))) || {};
const readCol = (col) => {
  const dir = path.join(ROOT, col);
  if (!fs.existsSync(dir)) return [];
  return fs.readdirSync(dir).filter((f) => f.endsWith('.json')).sort().map((f) => ({ file: f.replace(/\.json$/, ''), ...readJson(path.join(dir, f)) }));
};
const setting = (name, def = {}) => { const p = path.join(ROOT, 'settings', name + '.json'); return fs.existsSync(p) ? readJson(p) : def; };

// транслит для адресов новых записей, если поле slug пустое
const TR = { а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z', и: 'i', й: 'y', к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r', с: 's', т: 't', у: 'u', ф: 'f', х: 'h', ц: 'ts', ч: 'ch', ш: 'sh', щ: 'sch', ъ: '', ы: 'y', ь: '', э: 'e', ю: 'yu', я: 'ya' };
export const translit = (s = '') => String(s).toLowerCase().split('').map((c) => TR[c] ?? c).join('').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 80);
export const slugOf = (e, from) => (e.slug && /^[a-z0-9]+(-[a-z0-9]+)*$/.test(e.slug) ? e.slug : translit(e.slug || from || e.file));
// путь страницы: добавляем слэши, убираем лишние
export const normUrl = (u = '') => { let s = String(u).trim(); if (!s) return ''; if (!s.startsWith('/')) s = '/' + s; if (!s.endsWith('/')) s += '/'; return s.replace(/\/{2,}/g, '/'); };
// правило отбора подборки: убираем пустые и выключенные условия (галочки «нет» не должны фильтровать)
export const cleanRule = (r = {}) => Object.fromEntries(Object.entries(r || {}).filter(([, v]) => v !== false && v !== null && v !== undefined && v !== '' && !(Array.isArray(v) && !v.length)));

let cache = null;
export function cms() {
  if (cache) return cache;
  const fab = setting('fabrics', { fabrics: [], colors: [], mechanisms: [] });
  const company = setting('company');
  const categoriesRaw = readCol('categories').filter((c) => !c.hidden).sort((a, b) => (a.order ?? 999) - (b.order ?? 999));
  const categories = categoriesRaw.map((c) => { const slug = slugOf(c, c.name); return { slug, name: c.name, h1: c.h1 || c.name, url: `/catalog/${slug}/`, in_menu: c.in_menu !== false, _entry: c }; });
  const catBySlug = Object.fromEntries(categories.map((c) => [c.slug, c]));
  // товары и правила подборок ссылаются на категорию по имени файла записи (оно не меняется при смене адреса категории);
  // старые данные со slug тоже понимаем
  const catByFile = Object.fromEntries(categories.map((c) => [c._entry.file, c]));
  const catOf = (v) => catByFile[v]?.slug || (catBySlug[v] ? v : v);
  const TYPE = { 'pryamye-divany': 'Прямой', 'uglovye-divany': 'Угловой', 'modulnye-divany': 'Модульный', kresla: 'Кресло' };
  const MECH = Object.fromEntries((fab.mechanisms || []).map((m) => [m.slug, m.name]));
  const productsRaw = readCol('products').map((p) => ({ ...p, category: catOf(p.category) })).filter((p) => !p.hidden && catBySlug[p.category]).sort((a, b) => (a.order ?? 9999) - (b.order ?? 9999));
  const products = productsRaw.map((p) => {
    const slug = slugOf(p, p.name);
    const mechanism = p.mechanism || 'none';
    return {
      id: `${p.category}/${slug}`, category: p.category, slug, name: p.name, type: TYPE[p.category] || '', h1: p.h1 || p.name,
      url: `/catalog/${p.category}/${slug}/`, model_family: p.model_family || slug,
      length_cm: p.length_cm ?? null, depth_cm: p.depth_cm ?? null, height_cm: p.height_cm ?? null, seat_height_cm: p.seat_height_cm ?? null,
      sleeping: p.sleeping?.w && p.sleeping?.l ? p.sleeping : null, mechanism, mechanism_name: MECH[mechanism] || 'без механизма',
      has_sleeping_place: mechanism !== 'none', has_storage: !!p.has_storage, is_hit: !!p.is_hit, tags: p.tags || [],
      price_from: p.price_from || null, lead_time_days: p.lead_time_days || '14–21',
      in_stock: p.in_stock?.material && p.in_stock?.color ? p.in_stock : null, sides: p.sides ? ['left', 'right'] : null,
      photos: p.photos || [], samples: (p.samples || []).filter((s) => s.showroom && s.discount_pct), construction: p.construction || '',
      warranty_months: p.warranty_months || company.warranty_months || 18, modules: p.modules?.length ? p.modules : undefined,
      _entry: p,
    };
  });
  const showrooms = (setting('showrooms', { showrooms: [] }).showrooms || []).filter((s) => !s.hidden);
  const catalog = {
    categories: categories.map(({ _entry, ...c }) => c),
    mechanisms: MECH,
    colors: Object.fromEntries((fab.colors || []).map((c) => [c.slug, c.name])),
    color_hex: Object.fromEntries((fab.colors || []).map((c) => [c.slug, c.hex || '#c8c8c8'])), // без «Цвета кружка»: серый
    color_plural: Object.fromEntries((fab.colors || []).map((c) => [c.slug, c.plural || c.name])),
    fabrics: fab.fabrics || [],
    products,
    showrooms,
    settings: { ...company, delivery: setting('delivery') },
  };

  const byFile = Object.fromEntries(products.map((p) => [p._entry.file, p]));
  // страницы
  const pages = [];
  const page = (col, file, data, body) => pages.push({ col, file, data, body: (body || '').trim() });
  for (const p of products) {
    const e = p._entry;
    page('products', e.file, { ...e, page_id: `product:${p.id}`, type: 'product', url: p.url, h1: p.h1 }, e.body);
  }
  for (const c of categories) {
    const e = c._entry;
    page('categories', e.file, { ...e, page_id: c.slug, type: 'category', url: c.url, h1: e.h1 || c.name, title: e.title || `${c.name} от производителя | SIMMETRIKA`, listing: { rule: { category: c.slug } } }, e.body);
  }
  for (const e of readCol('collections').filter((x) => !x.hidden)) {
    const url = normUrl(e.url || `/catalog/${translit(e.h1 || e.file)}/`);
    page('collections', e.file, { ...e, page_id: `filter:${e.file}`, type: e.type || 'filter', url, listing: { ...(e.listing || {}), rule: (({ category, ...r }) => cleanRule({ ...r, category: category ? catOf(category) : category }))(e.listing?.rule || {}) } }, e.body);
  }
  for (const e of readCol('news').filter((x) => !x.hidden)) {
    const slug = slugOf(e, e.h1);
    // product_ids: имена файлов товаров (pryamye-divany--edzhio) → id товара на сайте (pryamye-divany/edzhio); скрытые отпадают
    const product_ids = (e.product_ids || []).map((x) => byFile[x]?.id || (products.some((p) => p.id === x) ? x : null)).filter(Boolean);
    page('news', e.file, { ...e, product_ids, page_id: `news:${slug}`, type: 'news-item', url: `/news/${slug}/` }, e.body);
  }
  for (const e of readCol('pages').filter((x) => !x.hidden)) {
    page('pages', e.file, { ...e, page_id: e.id || e.file, type: e.type || 'service', url: e.url ? normUrl(e.url) : undefined }, e.body);
  }
  const ui = setting('ui', {});
  pages.push({ col: 'settings', file: 'ui', data: { page_id: '_ui', type: 'ui', ui_texts: ui }, body: '' });

  cache = { catalog, pages, menu: setting('menu', { nav: [], nav_right: [], catalog_menu: [] }), redirects: setting('redirects', { redirects: [] }).redirects || [] };
  return cache;
}

// ── карта адресов и автопереадресации (смена адреса записи в админке не даёт 404) ──
export function urlMap() {
  return Object.fromEntries(cms().pages.filter((p) => p.data.url && p.col !== 'settings').map((p) => [`${p.col}/${p.file}`, p.data.url]));
}
export function redirectList() {
  const now = urlMap();
  const live = new Set(Object.values(now));
  const oldMap = setting('url-map', {});
  const auto = { ...setting('redirects-auto', {}) };
  const parentOf = (u) => { const parts = u.split('/').filter(Boolean); while (parts.length) { parts.pop(); const p = '/' + parts.join('/') + (parts.length ? '/' : ''); if (live.has(p) || p === '/') return p; } return '/'; };
  for (const [key, oldUrl] of Object.entries(oldMap)) {
    const cur = now[key];
    if (cur && cur !== oldUrl) auto[oldUrl] = cur; // адрес записи сменили
    else if (!cur && !live.has(oldUrl)) auto[oldUrl] = parentOf(oldUrl); // запись удалили или скрыли
  }
  for (const r of cms().redirects) if (r.from && r.to) auto[/\.html?$/.test(r.from.trim()) ? '/' + r.from.trim().replace(/^\//, '') : normUrl(r.from)] = r.to;
  // схлопываем цепочки и убираем переадресации с живых адресов
  const out = {};
  for (const [from, to0] of Object.entries(auto)) {
    if (live.has(from)) continue;
    let to = to0; const seen = new Set([from]);
    while (auto[to] && !live.has(to) && !seen.has(to)) { seen.add(to); to = auto[to]; }
    if (to !== from) out[from] = to;
  }
  return { auto: Object.fromEntries(Object.entries(auto).filter(([f]) => !live.has(f))), resolved: out };
}
