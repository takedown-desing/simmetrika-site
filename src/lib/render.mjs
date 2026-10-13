// HTML-фрагменты, общие для markdown-директив и шаблонов Astro.
import { u } from './site.mjs';
import {
  products, showrooms, settings, fabrics, COLOR_HEX, COLOR_NAME, MATERIAL, CATEGORY, MECH, SHOWROOM,
  img, rub, variantPrice, priceText, sizeStr, sleepStr, bestSample, select, minPrice, parseRule, productColors,
} from './catalog.mjs';
import { byUrl, loadPages, titleOf } from './content.mjs';

export const esc = (s = '') => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const phoneHref = (p) => 'tel:' + String(p).replace(/[^\d+]/g, '');

export function picture(src, alt = '', { cls = '', eager = false, sizes = '(min-width: 1000px) 400px, 100vw' } = {}) {
  const i = img(src);
  if (!i) return `<span class="ph ${cls}" role="img" aria-label="${esc(alt)}"></span>`;
  return `<img class="${cls}" src="${u(i.l)}" srcset="${u(i.s)} 600w, ${u(i.l)} 1200w" sizes="${sizes}" alt="${esc(alt)}" width="${i.w}" height="${i.h}" ${eager ? 'fetchpriority="high"' : 'loading="lazy"'} decoding="async">`;
}

const ICON = {
  stock: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8L3.5 9.7l5.9-.9z" fill="currentColor"/></svg>',
  mech: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 15h16M6 15v3M18 15v3M5 15c0-3 1-5 3-5h8c2 0 3 2 3 5" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M9 7l3-3 3 3" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>',
  box: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h16v11H4zM4 8l2-4h12l2 4M9 12h6" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>',
};

export function cartPayload(p, material = 'rogozhka', color = null, extra = {}) {
  return esc(JSON.stringify({ id: p.id, name: p.h1, url: p.url, img: img(p.photos?.[0])?.s || '', material, color, price: variantPrice(p, material), ...extra }));
}

export function productCard(p, { sale = false, h = 'h3' } = {}) {
  const s = sale ? bestSample(p) : null;
  const mat = s ? s.material : 'rogozhka';
  const price = s ? s.sample_price : variantPrice(p, mat);
  const colors = productColors().slice(0, 8);
  const badges = [];
  if (p.mechanism !== 'none') badges.push(`<span class="pc-ico" title="Механизм ${esc(p.mechanism_name)}">${ICON.mech}</span>`);
  if (p.has_storage) badges.push(`<span class="pc-ico" title="Ящик для белья">${ICON.box}</span>`);
  const sub = p.category === 'kresla' ? 'Кресла и пуфы' : [CATEGORY[p.category]?.name.replace(' диваны и системы', '').replace(' диваны', ''), p.mechanism !== 'none' ? p.mechanism_name : 'без механизма'].filter(Boolean).join(' · ');
  return `<article class="pc" data-cat="${p.category}" data-mech="${p.mechanism}" data-len="${p.length_cm || ''}" data-price="${price || ''}" data-stock="${p.in_stock ? 1 : 0}" data-sleep="${p.has_sleeping_place ? 1 : 0}" data-hit="${p.is_hit ? 1 : 0}" data-sort="${products.indexOf(p)}">
  <a class="pc-photo" href="${u(p.url)}" tabindex="-1" aria-hidden="true">${picture(p.photos?.[0], p.h1, { sizes: '(min-width: 1100px) 380px, (min-width: 700px) 45vw, 92vw' })}
    ${p.in_stock ? `<span class="pc-stock" title="Есть в наличии">${ICON.stock}</span>` : ''}
    <span class="pc-icons">${badges.join('')}</span>
    ${s ? `<span class="pc-sale">−${s.discount_pct}%</span>` : ''}
  </a>
  <div class="pc-body">
    <${h} class="pc-name"><a href="${u(p.url)}">${esc(p.name)}</a></${h}>
    <p class="pc-sub">${esc(sub)}</p>
    <p class="pc-price">${s ? `<b class="is-sale">${rub(price)}</b> <s>${rub(s.old_price)}</s>` : p.price_from ? `от <b>${rub(price)}</b>` : '<b>цена по запросу</b>'}</p>
    <p class="pc-dims">${sizeStr(p)}${p.sleeping ? ` · спальное ${p.sleeping.w}×${p.sleeping.l}` : ''}</p>
    <p class="pc-dots" aria-label="Доступные цвета">${colors.map((c) => `<span style="background:${COLOR_HEX[c]}" title="${esc(COLOR_NAME[c])}"></span>`).join('')}<em>+${productColors().length - colors.length}</em></p>
    <p class="pc-actions"><a class="btn btn-sm btn-line" href="${u(p.url)}">Подробнее</a><button type="button" class="btn btn-sm btn-accent" data-add="${cartPayload(p, mat, s?.color || null, s ? { sample: s.showroom } : {})}">В заявку</button></p>
  </div>
</article>`;
}

export function productsGrid(rule = 'all', limit) {
  let list = select(rule, limit);
  // витрины с лимитом: ровные ряды по 3 (правило «последний ряд заполнен»)
  if (limit && Number(limit) <= 9 && list.length > 3 && list.length % 3) list = list.slice(0, list.length - (list.length % 3));
  const sale = !!parseRule(rule).sample;
  if (!list.length) return `<div class="empty"><p class="empty-t">Товары временно отсутствуют</p><p>Посмотрите другие разделы: <a href="${u('/catalog/pryamye-divany/')}">прямые</a>, <a href="${u('/catalog/uglovye-divany/')}">угловые</a>, <a href="${u('/catalog/modulnye-divany/')}">модульные диваны</a>.</p></div>`;
  return `<div class="pgrid" data-grid>${list.map((p) => productCard(p, { sale })).join('')}</div>`;
}

// ── плитки разделов (стиль цветных плашек коллекций) ──
const TILE_BG = ['#7C7471', '#98A0B0', '#A7C7CB', '#7B6D5E', '#22506A', '#4E5563', '#756463', '#667F63', '#626262'];
function pageRule(url) {
  const p = byUrl()[url];
  const r = p?.data.listing?.rule;
  if (!r) return null;
  return Object.entries(r).map(([k, v]) => `${k}=${v}`).join(',');
}
export function tiles(items = [], { big = false } = {}) {
  const list = items.filter((x) => x && !x.includes('{'));
  return `<div class="tiles ${big ? 'tiles-big' : ''}" data-count="${list.length}">${list.map((url, i) => {
    const page = byUrl()[url];
    const rule = pageRule(url);
    const sel = rule ? select(rule) : [];
    const photo = page?.data.images?.find((x) => x.slot === 'hero' && x.src)?.src || sel[0]?.photos?.[0] || products[i % products.length].photos[0];
    const count = rule ? sel.length : null;
    const sale = url === '/sale/';
    const t = titleOf(url);
    return `<a class="tile" href="${u(url)}" style="--tile:${TILE_BG[i % TILE_BG.length]}">
      ${count ? `<span class="tile-n">${count} шт.</span>` : ''}
      <span class="tile-img">${picture(photo, '', { sizes: '(min-width: 900px) 40vw, 92vw' })}</span>
      <span class="tile-t">${esc(t)}</span>
      ${sale ? '<span class="tile-sale">до −30%</span>' : ''}
    </a>`;
  }).join('')}</div>`;
}
export function chips(items = []) {
  return `<nav class="chips" aria-label="Подборки">${items.filter((x) => !x.includes('{')).map((url) => `<a class="chip" href="${u(url)}">${esc(titleOf(url))}</a>`).join('')}</nav>`;
}

export function showroomsHtml(mode = 'cards') {
  return `<div class="shows shows-${mode === 'list' ? 'list' : 'cards'}">${showrooms.map((s) => `<article class="show" id="${s.slug}">
    <h3 class="show-t">${esc(s.name)}${s.is_main ? ' <span class="tag">главный</span>' : ''}</h3>
    <p class="show-a">${esc(s.address)}</p>
    <p class="show-h">Ежедневно ${esc(s.hours)}</p>
    <p class="show-p"><a href="${phoneHref(s.phone)}">${esc(s.phone)}</a></p>
    <p class="show-m"><a href="${s.lat ? ymapPoint(s) : `https://yandex.ru/maps/?text=${encodeURIComponent(s.address)}`}" target="_blank" rel="noopener">Открыть на Яндекс.Картах</a></p>
  </article>`).join('')}</div>`;
}
// интерактивная Яндекс.Карта (виджет без API-ключа), метки пронумерованы как в списке шоурумов
const ymapPoint = (s) => `https://yandex.ru/maps/?pt=${s.lon},${s.lat}&z=16&l=map`;
export function mapHtml() {
  const pt = showrooms.map((s, i) => `${s.lon},${s.lat},pm2rdl${i + 1}`).join('~');
  const src = `https://yandex.ru/map-widget/v1/?ll=37.55,55.75&z=9&pt=${encodeURIComponent(pt).replace(/%2C/g, ',').replace(/%7E/g, '~')}`;
  return `<figure class="map ymap">
    <div class="ymap-box"><iframe src="${src}" title="Шоурумы SIMMETRIKA на Яндекс.Карте" loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade"></iframe></div>
    <ol class="ymap-legend">${showrooms.map((s) => `<li><a href="${ymapPoint(s)}" target="_blank" rel="noopener"><b>${esc(s.name)}</b><span>${esc(s.address)}</span></a></li>`).join('')}</ol>
  </figure>`;
}
export function mapSvg() {
  const pts = [[140, 70], [255, 120], [185, 55], [95, 175], [230, 205]];
  return `<figure class="map"><svg viewBox="0 0 360 260" role="img" aria-label="Схема расположения шоурумов относительно МКАД">
    <ellipse cx="180" cy="130" rx="120" ry="95" fill="none" stroke="currentColor" stroke-width="2" opacity=".35"/>
    <text x="180" y="134" text-anchor="middle" font-size="12" fill="currentColor" opacity=".6">Москва, МКАД</text>
    ${showrooms.map((s, i) => `<g><circle cx="${pts[i][0]}" cy="${pts[i][1]}" r="7" fill="var(--c-accent)"/><text x="${pts[i][0] + 11}" y="${pts[i][1] + 4}" font-size="11" fill="currentColor">${esc(s.name.split(',')[0].replace(/[«»]/g, ''))}</text></g>`).join('')}
  </svg><figcaption>Схема без масштаба. Точные адреса и ссылки на карту в карточках шоурумов.</figcaption></figure>`;
}

export function swatches(materials = 'velyur,rogozhka,zhakkard') {
  return `<div class="sw-wrap">${materials.split(',').map((m) => MATERIAL[m.trim()]).filter(Boolean).map((f) => `<div class="sw-row">
    <p class="sw-name"><b>${esc(f.name)}</b> ${esc(f.props)}</p>
    <p class="sw-dots">${f.colors.map((c) => `<a href="${u(`/catalog/cvet/${c}/`)}" class="sw" style="--sw:${COLOR_HEX[c]}" title="${esc(COLOR_NAME[c])}"><span class="sr">${esc(COLOR_NAME[c])}</span></a>`).join('')}</p>
  </div>`).join('')}</div>`;
}

const COLS = {
  name: ['Модель', (p) => `<a href="${u(p.url)}">${esc(p.h1)}</a>`],
  category: ['Категория', (p) => esc(CATEGORY[p.category]?.name || '')],
  size: ['Д×Г×В', (p) => sizeStr(p)],
  sleeping: ['Спальное место', (p) => sleepStr(p)],
  mechanism: ['Механизм', (p) => esc(p.mechanism_name)],
  side: ['Угол', (p) => (p.sides ? 'левый / правый' : '—')],
  price: ['Цена от', (p) => (p.price_from ? rub(variantPrice(p)) : 'по запросу')],
};
export function specTable(rule = 'all', cols = 'name,size,sleeping,mechanism,price') {
  const c = cols.split(',').map((x) => x.trim()).filter((x) => COLS[x]);
  const list = select(rule);
  return `<div class="tbl"><table class="spec"><thead><tr>${c.map((k) => `<th>${COLS[k][0]}</th>`).join('')}</tr></thead><tbody>${list.map((p) => `<tr>${c.map((k) => `<td data-l="${COLS[k][0]}">${COLS[k][1](p)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
}

export function filtersHtml(set = 'price,length,mechanism,stock') {
  const f = set.split(',').map((s) => s.trim());
  const opt = (name, label, opts) => `<label class="flt"><span class="sr">${label}</span><select data-f="${name}" aria-label="${label}"><option value="">${label}</option>${opts.map(([v, t]) => `<option value="${v}">${t}</option>`).join('')}</select></label>`;
  const parts = [];
  if (f.includes('category') || f.includes('type')) parts.push(opt('cat', 'Тип', Object.values(CATEGORY).map((c) => [c.slug, c.name])));
  if (f.includes('mechanism') || f.includes('sleeping')) parts.push(opt('mech', 'Механизм', [['pantograf', 'Пантограф'], ['venetsiya', 'Венеция'], ['vykatnoy', 'Выкатной'], ['none', 'Без механизма']]));
  if (f.includes('price')) parts.push(opt('price', 'Цена', [['0-100000', 'до 100 000 ₽'], ['100000-160000', '100–160 тыс. ₽'], ['160000-999999', 'от 160 000 ₽']]));
  if (f.includes('length')) parts.push(opt('len', 'Длина', [['0-220', 'до 220 см'], ['220-260', '220–260 см'], ['260-999', 'от 260 см']]));
  if (f.includes('stock')) parts.push(`<label class="flt flt-check"><input type="checkbox" data-f="stock"> В наличии</label>`);
  parts.push(`<label class="flt"><span class="sr">Сортировка</span><select data-sort aria-label="Сортировка"><option value="pop">По популярности</option><option value="asc">Сначала дешевле</option><option value="desc">Сначала дороже</option></select></label>`);
  return `<div class="filters" data-filters role="search" aria-label="Фильтры">${parts.join('')}<button type="button" class="btn btn-sm btn-line" data-reset>Сбросить</button><span class="flt-n" data-found aria-live="polite"></span></div>`;
}

const FORM_TITLE = { question: 'Задать вопрос фабрике', callback: 'Обратный звонок', custom_size: 'Рассчитать диван по вашим размерам', sample_reserve: 'Забронировать образец', dealer: 'Заявка для дизайнеров и дилеров' };
export function formHtml(type = 'question', { title, id, lead } = {}) {
  const f = (name, label, attrs = '') => `<label class="fld"><span>${label}</span><input name="${name}" ${attrs}></label>`;
  const extra = {
    custom_size: `<div class="fld-row">${f('length', 'Длина, см', 'inputmode="numeric"')}${f('depth', 'Глубина, см', 'inputmode="numeric"')}<label class="fld"><span>Модель</span><select name="model"><option value="">Любая</option>${products.map((p) => `<option>${esc(p.h1)}</option>`).join('')}</select></label></div>`,
    sample_reserve: `<label class="fld"><span>Образец</span><select name="sample">${products.filter((p) => p.samples?.length).map((p) => `<option>${esc(p.h1)}, ${esc(SHOWROOM[p.samples[0].showroom].name)}</option>`).join('')}</select></label>`,
    dealer: f('company', 'Компания или студия'),
  }[type] || '';
  const fields = type === 'callback'
    ? `<div class="fld-row">${f('name', 'Имя', 'autocomplete="name"')}${f('phone', 'Телефон *', 'type="tel" required autocomplete="tel" data-mask placeholder="+7 (___) ___-__-__"')}</div>`
    : `<div class="fld-row">${f('name', 'Имя', 'autocomplete="name"')}${f('phone', 'Телефон *', 'type="tel" required autocomplete="tel" data-mask placeholder="+7 (___) ___-__-__"')}${f('email', 'Email', 'type="email" autocomplete="email"')}</div>${extra}<label class="fld"><span>Комментарий</span><textarea name="comment" rows="3"></textarea></label>`;
  return `<form class="lead-form" data-form="${type}" ${id ? `id="${esc(id)}"` : ''} novalidate>
    <p class="lf-t">${esc(title || FORM_TITLE[type] || 'Оставить заявку')}</p>
    ${lead ? `<p class="lf-l">${esc(lead)}</p>` : ''}
    ${fields}
    <label class="fld-check"><input type="checkbox" name="consent" required> Согласен на обработку персональных данных по <a href="${u('/privacy/#soglasie')}">политике конфиденциальности</a></label>
    <p class="lf-err" data-err role="alert"></p>
    <button class="btn btn-accent" type="submit">Отправить</button>
    <p class="lf-ok" data-ok hidden>Спасибо, заявка принята. ${esc(settings.callback_promise[0].toUpperCase() + settings.callback_promise.slice(1))}.</p>
  </form>`;
}

export function newsHtml(limit = 3, type = 'all') {
  const items = loadPages().filter((p) => p.data.type === 'news-item' && (type === 'all' || p.data.news_type === type))
    .sort((a, b) => String(b.data.date).localeCompare(String(a.data.date))).slice(0, Number(limit));
  return `<div class="news">${items.map((p) => `<a class="news-i" href="${u(p.data.url)}">
    <span class="news-img">${picture(p.data.cover, p.data.cover_alt || '', { sizes: '(min-width: 900px) 30vw, 92vw' })}</span>
    <span class="news-meta">${p.data.news_type === 'promo' ? '<b>Акция</b> · ' : ''}${new Date(p.data.date).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })}</span>
    <span class="news-t">${esc(p.data.h1)}</span><span class="news-a">${esc(p.data.announce || '')}</span></a>`).join('')}</div>`;
}
export function promoHtml() {
  const promos = loadPages().filter((p) => p.data.type === 'news-item' && p.data.news_type === 'promo');
  return `<div class="promo">${promos.map((p) => `<a class="promo-i" href="${u(p.data.url)}"><span class="promo-t">${esc(p.data.h1)}</span><span class="promo-a">${esc(p.data.announce || '')}</span><span class="btn btn-sm btn-white">Подробнее</span></a>`).join('')}</div>`;
}

// ── схемы-инфографики (упрощённые SVG; подписи из текста директивы идут под схемой) ──
export function schemeSvg(kind = '', attrs = {}) {
  const C = 'currentColor';
  const sofa = (x, y, w, d, fill = 'var(--c-photo)') => `<rect x="${x}" y="${y}" width="${w}" height="${d}" fill="${fill}" stroke="${C}" stroke-width="1.5"/>`;
  if (kind.startsWith('mechanism')) {
    const one = (x, label, open) => `<g transform="translate(${x},0)">${sofa(10, 40, 120, 30)}${sofa(10, 20, 120, 20, 'none')}<rect x="10" y="${open ? 70 : 70}" width="${open}" height="14" fill="var(--c-accent)" opacity=".18" stroke="var(--c-accent)"/><text x="70" y="110" text-anchor="middle" font-size="12" fill="${C}">${label}</text></g>`;
    const list = kind === 'mechanism-pantograf' ? [['Пантограф: сиденье вперёд и вниз', 120]] : kind === 'mechanism-venetsiya' ? [['Венеция: сиденье вперёд, спинка вниз', 120]] : kind === 'mechanism-vykatnoy' ? [['Выкатной: нижняя секция выкатывается', 120]] : [['Пантограф', 120], ['Венеция', 120], ['Выкатной', 120]];
    return `<svg viewBox="0 0 ${list.length * 150} 120" role="img" aria-label="Схема механизмов трансформации">${list.map((l, i) => one(i * 150, l[0], l[1])).join('')}</svg>`;
  }
  if (kind === 'corner-side') return `<svg viewBox="0 0 360 140" role="img" aria-label="Левый и правый угол, вид стоя лицом к дивану"><g>${sofa(20, 30, 130, 30)}${sofa(20, 60, 40, 60)}<text x="85" y="135" text-anchor="middle" font-size="12" fill="${C}">Левый угол</text></g><g>${sofa(210, 30, 130, 30)}${sofa(300, 60, 40, 60)}<text x="275" y="135" text-anchor="middle" font-size="12" fill="${C}">Правый угол</text></g></svg>`;
  if (kind === 'color-palette') return `<svg viewBox="0 0 400 70" role="img" aria-label="Цветовые группы обивки">${Object.entries(COLOR_HEX).map(([k, v], i) => `<circle cx="${20 + i * 40}" cy="30" r="${k === attrs.active ? 17 : 13}" fill="${v}" stroke="${k === attrs.active ? 'var(--c-accent)' : '#ccc'}" stroke-width="${k === attrs.active ? 3 : 1}"/>`).join('')}<text x="200" y="66" text-anchor="middle" font-size="11" fill="${C}">10 цветовых групп обивки</text></svg>`;
  if (/scale|ruler|sizes|sleeping/.test(kind)) {
    const list = products.slice().sort((a, b) => a.length_cm - b.length_cm).filter((p, i, a) => a.findIndex((q) => q.name === p.name && q.category === p.category) === i).slice(0, 10);
    const max = Math.max(...list.map((p) => p.length_cm));
    return `<svg viewBox="0 0 420 ${list.length * 24 + 10}" role="img" aria-label="Длина моделей, см">${list.map((p, i) => `<text x="0" y="${i * 24 + 16}" font-size="11" fill="${C}">${esc(p.name)}${p.category === 'uglovye-divany' ? ' угл.' : p.category === 'modulnye-divany' ? ' мод.' : ''}</text><rect x="110" y="${i * 24 + 5}" width="${(p.length_cm / max) * 260}" height="14" fill="${p.category === 'uglovye-divany' ? 'var(--c-accent)' : '#8E9096'}" opacity=".75"/><text x="${115 + (p.length_cm / max) * 260}" y="${i * 24 + 16}" font-size="11" fill="${C}">${p.length_cm}</text>`).join('')}</svg>`;
  }
  if (kind === 'delivery-zones' || kind === 'showrooms-mkad') return mapSvg().replace(/<\/?figure[^>]*>|<figcaption>.*<\/figcaption>/g, '');
  if (kind === 'modules') return `<svg viewBox="0 0 400 130" role="img" aria-label="Модули модульной системы">${sofa(10, 20, 80, 50)}${sofa(95, 20, 100, 50)}${sofa(200, 20, 50, 50)}${sofa(200, 70, 50, 50)}${sofa(300, 40, 80, 80)}<g font-size="11" fill="${C}"><text x="50" y="90" text-anchor="middle">секция</text><text x="145" y="90" text-anchor="middle">секция</text><text x="225" y="128" text-anchor="middle">угол</text><text x="340" y="128" text-anchor="middle">оттоманка</text></g></svg>`;
  if (kind === 'custom-dimensions' || kind === 'room-layout') return `<svg viewBox="0 0 400 150" role="img" aria-label="Размеры, которые можно изменить">${sofa(60, 40, 260, 60)}<path d="M60 120h260M60 115v10M320 115v10" stroke="var(--c-accent)" stroke-width="2"/><text x="190" y="140" text-anchor="middle" font-size="12" fill="${C}">длина ±30 см</text><path d="M340 40v60M335 40h10M335 100h10" stroke="var(--c-accent)" stroke-width="2"/><text x="350" y="75" font-size="12" fill="${C}">глубина ±10</text></svg>`;
  return '';
}
