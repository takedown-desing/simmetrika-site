import { cms } from './cms.mjs';
let cache = null;

// ── типографика: неразрывные пробелы, чтобы не было висячих «%.», «₽», «в», «и» на отдельной строке ──
const NB = ' ';
const SHORT = 'в|во|и|с|со|к|ко|о|об|обо|у|а|на|по|за|от|до|не|ни|из|для|без|при|под|над|про|что|как|или|но|же|ли|бы|вы|мы|я|он|её|их|его|до';
const reShort = new RegExp(`(^|[\\s(«„"—–])(${SHORT})\\s+(?=[^\\s])`, 'gi');
export function typo(s) {
  if (typeof s !== 'string' || !s) return s;
  let t = s;
  t = t.replace(/(\d) (?=\d{3}(?!\d))/g, `$1${NB}`); // 1 500 → 1 500
  t = t.replace(/(\d)\s+(%|₽|руб|см|мм|м|км|кг|шт|дн|дня|дней|день|мес|месяц|месяца|месяцев|лет|года|год|ч|час|часа|часов|минут|этаж|этажа|места|мест|модели|моделей|моделях|исполнений|исполнениях|шоурумов|шоурума|ткани|тканей|цветов|цветовых|точек|товара|товаров|раза|года)(?=[\s.,;:!?)»]|$)/gi, `$1${NB}$2`);
  t = t.replace(/(№|§)\s+(\d)/g, `$1${NB}$2`);
  for (let i = 0; i < 2; i++) t = t.replace(reShort, (m, pre, w) => `${pre}${w}${NB}`);
  t = t.replace(/(\S) +([–—]) /g, `$1${NB}$2 `); // тире не переносится в начало строки
  return t;
}
const SKIP_KEYS = new Set(['_entry', 'photos', 'image', 'images', 'listing', 'samples', 'in_stock', 'sleeping', 'url', 'href', 'src', 'page_id', 'type', 'slug', 'cover', 'canonical', 'robots', 'rule', 'action', 'primary_kw', 'secondary_kws', 'assumptions', 'date', 'date_end', 'date_modified', 'title', 'description', 'og_title', 'og_description', 'x_robots_tag', 'external_links', 'anchors', 'placeholders']);
const deepTypo = (v, key) => {
  if (SKIP_KEYS.has(key)) return v;
  if (typeof v === 'string') return typo(v);
  if (Array.isArray(v)) return v.map((x) => deepTypo(x, key));
  if (v && typeof v === 'object' && !(v instanceof Date)) return Object.fromEntries(Object.entries(v).map(([k, x]) => [k, deepTypo(x, k)]));
  return v;
};
// в markdown типографим только строки текста, не трогая директивы, таблицы-разделители и ссылки
const typoMd = (md) => md.split('\n').map((line) => {
  if (/^\s*:::/.test(line) || /^\s*\|?\s*-{3,}/.test(line) || /^\s*<!--/.test(line)) return line;
  // не трогаем адреса ссылок (…)(…) и атрибуты {…}
  return line.split(/(\]\([^)]*\)|\{[^}]*\})/).map((part, i) => (i % 2 ? part : typo(part))).join('');
}).join('\n');

export function loadPages() {
  if (cache) return cache;
  cache = cms().pages.map((p) => ({ col: p.col, file: p.file, data: deepTypo(p.data), body: typoMd(p.body || '') }));
  return cache;
}
export const byUrl = () => Object.fromEntries(loadPages().filter((p) => p.data.url && !String(p.data.url).includes('{')).map((p) => [p.data.url, p]));
export const byId = () => Object.fromEntries(loadPages().map((p) => [p.data.page_id, p]));
export const ui = () => loadPages().find((p) => p.data.page_id === '_ui' || p.data.type === 'ui')?.data.ui_texts || {};
export const titleOf = (url) => {
  const p = byUrl()[url];
  return p?.data.h1 || p?.data.title?.split('|')[0].trim() || url;
};
