export const SITE = {
  origin: 'https://takedown-desing.github.io',
  base: '/simmetrika-site',
  canonicalOrigin: 'https://simmetrika-mebel.ru',
  name: 'SIMMETRIKA',
  preview: process.env.PREVIEW !== '0', // превью на GitHub Pages закрыто от индексации; PREVIEW=0 — боевой режим (robots по страницам, sitemap)
};
// ссылки на старые адреса (смена адреса, скрытие, удаление записи) сразу ведут на новый, без лишней переадресации
let REDIR = null;
const direct = (path) => { REDIR ||= redirectList().resolved; const [p0, q = ''] = path.split('?'); const n = /\.html?$/.test(p0) ? p0 : p0.endsWith('/') ? p0 : p0 + '/'; return REDIR[n] && !/^https?:/.test(REDIR[n]) ? REDIR[n] + (q ? '?' + q : '') : path; };
export const u = (p = '/') => {
  if (!p || /^(https?:|mailto:|tel:|#)/.test(p)) return p;
  let [path, hash = ''] = p.split('#');
  if (path.startsWith('/') && !path.startsWith(SITE.base + '/')) path = direct(path);
  const full = (SITE.base + (path.startsWith('/') ? path : '/' + path)).replace(/\/{2,}/g, '/');
  return full + (hash ? '#' + hash : '');
};
export const canonical = (p) => SITE.canonicalOrigin + p;
import { cms, redirectList, normUrl } from './cms.mjs';
// меню правится в админке: Настройки → Меню сайта
const MENU = cms().menu;
export const NAV = MENU.nav || [];
export const NAV_RIGHT = MENU.nav_right || [];
const MENU_COLS = MENU.catalog_menu || [];
// категории с галочкой «Показывать в меню», которых нет в меню вручную, дописываются в конец первой колонки
const inMenu = new Set(MENU_COLS.flatMap((g) => (g.items || []).map((i) => normUrl(i.url))));
const missing = cms().catalog.categories.filter((c) => c.in_menu && !inMenu.has(c.url)).map((c) => ({ title: c.name, url: c.url }));
export const CATALOG_MENU = missing.length && MENU_COLS.length ? MENU_COLS.map((g, i) => (i ? g : { ...g, items: [...(g.items || []), ...missing] })) : MENU_COLS;
// заголовок вкладки: если title пуст, «H1 | SIMMETRIKA»
export const pageTitle = (d = {}) => (d.title || (d.h1 ? `${d.h1} | SIMMETRIKA` : 'SIMMETRIKA')).replace(/—/g, '–');
