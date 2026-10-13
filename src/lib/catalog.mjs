import fs from 'node:fs';
import path from 'node:path';
const CAT = JSON.parse(fs.readFileSync(path.resolve('src/data/catalog.json'), 'utf8'));
let IMG = {};
try { IMG = JSON.parse(fs.readFileSync(path.resolve('src/data/images.json'), 'utf8')); } catch {}
export const catalog = CAT;
export const products = CAT.products;
export const showrooms = CAT.showrooms;
export const settings = CAT.settings;
export const fabrics = CAT.fabrics;
export const COLOR_HEX = {
  seryy: '#8E9096', bezhevyy: '#D8C7AE', zelenyy: '#4E7A5A', siniy: '#2F4A73', belyy: '#F1EFEA',
  korichnevyy: '#6E4E3A', zheltyy: '#D9A33A', terrakot: '#B65A3A', bordovyy: '#7A1E2B', rozovyy: '#D9A3A6',
};
export const COLOR_NAME = CAT.colors; // slug -> «серый»
export const COLOR_PLURAL = { seryy: 'Серые', bezhevyy: 'Бежевые', zelenyy: 'Зелёные', siniy: 'Синие', belyy: 'Белые', korichnevyy: 'Коричневые', zheltyy: 'Жёлтые', terrakot: 'Терракотовые', bordovyy: 'Бордовые', rozovyy: 'Розовые' };
export const MATERIAL = Object.fromEntries(CAT.fabrics.map((f) => [f.material, f]));
export const CATEGORY = Object.fromEntries(CAT.categories.map((c) => [c.slug, c]));
export const MECH = CAT.mechanisms;
export const SHOWROOM = Object.fromEntries(CAT.showrooms.map((s) => [s.slug, s]));
export const img = (src) => (src && IMG[src]) || null;
export const rub = (n) => (n == null ? '' : Math.round(n).toLocaleString('ru-RU').replace(/ /g, ' ') + ' ₽');
export const variantPrice = (p, material = 'rogozhka') => (p.price_from ? Math.round((p.price_from * (MATERIAL[material]?.price_k || 1)) / 100) * 100 : null);
export const priceText = (p, material = 'rogozhka', from = true) => (p.price_from ? `${from ? 'от ' : ''}${rub(variantPrice(p, material))}` : 'цена по запросу');
export const productColors = () => Object.keys(COLOR_HEX).filter((c) => CAT.fabrics.some((f) => f.colors.includes(c)));
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
  for (const [k, v] of Object.entries(r)) {
    if (k === 'all') continue;
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
