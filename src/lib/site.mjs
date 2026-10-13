export const SITE = {
  origin: 'https://takedown-desing.github.io',
  base: '/simmetrika-site',
  canonicalOrigin: 'https://simmetrika-mebel.ru',
  name: 'SIMMETRIKA',
  preview: true, // превью на GitHub Pages закрыто от индексации
};
export const u = (p = '/') => {
  if (!p || /^(https?:|mailto:|tel:|#)/.test(p)) return p;
  const [path, hash = ''] = p.split('#');
  const full = (SITE.base + (path.startsWith('/') ? path : '/' + path)).replace(/\/{2,}/g, '/');
  return full + (hash ? '#' + hash : '');
};
export const canonical = (p) => SITE.canonicalOrigin + p;
export const NAV = [
  { title: 'Прямые', url: '/catalog/pryamye-divany/' },
  { title: 'Угловые', url: '/catalog/uglovye-divany/' },
  { title: 'Модульные', url: '/catalog/modulnye-divany/' },
  { title: 'Распродажа', url: '/sale/', accent: true },
];
export const NAV_RIGHT = [
  { title: 'О фабрике', url: '/o-kompanii/' },
  { title: 'Доставка', url: '/dostavka/' },
  { title: 'Шоурумы', url: '/kontakty/' },
];
export const CATALOG_MENU = [
  { title: 'Каталог', items: [
    { title: 'Все диваны', url: '/catalog/' },
    { title: 'Прямые диваны', url: '/catalog/pryamye-divany/' },
    { title: 'Угловые диваны', url: '/catalog/uglovye-divany/' },
    { title: 'Модульные диваны и системы', url: '/catalog/modulnye-divany/' },
    { title: 'Диваны-кровати', url: '/catalog/divany-krovati/' },
    { title: 'Распродажа образцов', url: '/sale/' },
  ]},
  { title: 'Механизм', items: [
    { title: 'Пантограф', url: '/catalog/divany-krovati/pantograf/' },
    { title: 'Венеция', url: '/catalog/divany-krovati/venetsiya/' },
    { title: 'Выкатные', url: '/catalog/divany-krovati/vykatnye/' },
  ]},
  { title: 'Подборки', items: [
    { title: 'Для гостиной', url: '/catalog/divany-dlya-gostinoy/' },
    { title: 'Большие от 250 см', url: '/catalog/bolshie-divany/' },
    { title: 'Дизайнерские', url: '/catalog/dizaynerskie-divany/' },
    { title: 'В наличии', url: '/catalog/v-nalichii/' },
  ]},
  { title: 'Ткань и цвет', items: [
    { title: 'Велюр', url: '/catalog/velyur/' },
    { title: 'Рогожка', url: '/catalog/rogozhka/' },
    { title: 'Серые диваны', url: '/catalog/cvet/seryy/' },
    { title: 'Бежевые диваны', url: '/catalog/cvet/bezhevyy/' },
    { title: 'Зелёные диваны', url: '/catalog/cvet/zelenyy/' },
  ]},
  { title: 'Покупателям', items: [
    { title: 'Диваны на заказ', url: '/na-zakaz/' },
    { title: 'Доставка и оплата', url: '/dostavka/' },
    { title: 'Новости и акции', url: '/news/' },
    { title: 'О фабрике', url: '/o-kompanii/' },
    { title: 'Шоурумы и контакты', url: '/kontakty/' },
  ]},
];
