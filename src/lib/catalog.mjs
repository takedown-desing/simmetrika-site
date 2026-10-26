import fs from 'node:fs';
import path from 'node:path';
import { cms } from './cms.mjs';
const CAT = cms().catalog;
let IMG = {};
try { IMG = JSON.parse(fs.readFileSync(path.resolve('src/data/images.json'), 'utf8')); } catch {}
export const catalog = CAT;
export const products = CAT.products;
export const showrooms = CAT.showrooms;
export const settings = CAT.settings;
export const fabrics = CAT.fabrics;
export const COLOR_HEX = CAT.color_hex;
export const COLOR_NAME = CAT.colors; // slug -> «серый»
export const COLOR_PLURAL = CAT.color_plural;
export const MATERIAL = Object.fromEntries(CAT.fabrics.map((f) => [f.material, f]));
export const CATEGORY = Object.fromEntries(CAT.categories.map((c) => [c.slug, c]));
export const MECH = CAT.mechanisms;
export const SHOWROOM = Object.fromEntries(CAT.showrooms.map((s) => [s.slug, s]));
const IMG_BY_L = Object.fromEntries(Object.values(IMG).map((v) => [v.l, v]));
// фото: готовые WebP фабрики (/img/p/<имя>-1200.webp + -600) или загруженные в админке файлы (/images/uploads/…)
export const img = (src) => {
  if (!src) return null;
  if (IMG[src]) return IMG[src];
  if (IMG_BY_L[src]) return IMG_BY_L[src];
  if (/^\/img\/p\/.+-1200\.webp$/.test(src)) return { s: src.replace('-1200.webp', '-600.webp'), l: src, w: 1200, h: 900 };
  if (/^(\/|https?:)/.test(src)) return { s: src, l: src, w: null, h: null };
  return null;
};
export const rub = (n) => (n == null ? '' : Math.round(n).toLocaleString('ru-RU').replace(/[\u00a0\u202f ]/g, '\u00a0') + '\u00a0₽');
export const variantPrice = (p, material = 'rogozhka') => (p.price_from ? Math.round((p.price_from * (MATERIAL[material]?.price_k || 1)) / 100) * 100 : null);
export const priceText = (p, material = 'rogozhka', from = true) => (p.price_from ? `${from ? 'от ' : ''}${rub(variantPrice(p, material))}` : 'цена по запросу');
export const productColors = () => Object.keys(COLOR_HEX).filter((c) => CAT.fabrics.some((f) => f.colors.includes(c)));
export const plural = (n, one, few, many) => { const a = n % 10, b = n % 100; return a === 1 && b !== 11 ? one : a >= 2 && a <= 4 && (b < 12 || b > 14) ? few : many; };
export const sizeStr = (p) => (p.length_cm ? [p.length_cm, p.depth_cm, p.height_cm].filter(Boolean).join('×') + ' см' : 'размеры по запросу');
export const sleepStr = (p) => (p.sleeping ? `${p.sleeping.w}×${p.sleeping.l} см` : 'нет');
export const bestSample = (p) => (p.samples || []).slice().sort((a, b) => b.discount_pct - a.discount_pct)[0] || null;

export function parseRule(rule = 'all') {
  if (!rule || rule === 'all') return {};
  const out = {};
  for (const part of String(rule).split(',')) {
    const [k, v] = part.split('=').map((s) => s.trim());
    if (k) out[k] = v ?? 'true';
  }
  return out;
}
export function matchRule(p, rule) {
  const r = typeof rule === 'string' ? parseRule(rule) : rule || {};
  // кресла и пуфы показываем только в их разделе; общие подборки и «все диваны» только с диванами
  if (!r.category && p.category === 'kresla') return false;
  for (const [k, v0] of Object.entries(r)) {
    if (k === 'all') continue;
    const v = v0 === true ? 'true' : v0 === false ? 'false' : String(v0); // правило из админки приходит с true/false
    if (k === 'category' && p.category !== v) return false;
    if (k === 'mechanism' && p.mechanism !== v) return false;
    if (k === 'tag' && !(p.tags || []).includes(v)) return false;
    if (k === 'in_stock' && !!p.in_stock !== (v === 'true')) return false;
    if (k === 'sample' && (p.samples?.length > 0) !== (v === 'true')) return false;
    if (k === 'hit' && !!p.is_hit !== (v === 'true')) return false;
    if (k === 'has_sleeping_place' && !!p.has_sleeping_place !== (v === 'true')) return false;
    if (k === 'has_storage' && !!p.has_storage !== (v === 'true')) return false;
    if (k === 'length_gte' && !(p.length_cm >= Number(v))) return false;
    if (k === 'showroom' && !(p.samples || []).some((s) => s.showroom === v)) return false;
    // material / color: любая ткань справочника доступна любой модели (допущение Q-33)
    if (k === 'material' && !MATERIAL[v]) return false;
  }
  return true;
}
export const select = (rule, limit) => {
  const list = products.filter((p) => matchRule(p, rule));
  const r = parseRule(rule);
  if (r.sample) list.sort((a, b) => (bestSample(b)?.discount_pct || 0) - (bestSample(a)?.discount_pct || 0));
  else list.sort((a, b) => (b.is_hit - a.is_hit) || (a.price_from - b.price_from));
  return limit ? list.slice(0, Number(limit)) : list;
};
export const minPrice = (list, material) => Math.min(...list.map((p) => variantPrice(p, material || 'rogozhka')).filter(Boolean));
