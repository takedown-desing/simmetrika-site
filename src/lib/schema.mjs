import { SITE, canonical } from './site.mjs';
import { showrooms, settings, img, variantPrice, products } from './catalog.mjs';
const ORG = `${SITE.canonicalOrigin}/#organization`;
export function graph(page, { product, faq, breadcrumbs, type } = {}) {
  const d = page?.data || {};
  const url = canonical(d.url || '/');
  const g = [
    { '@type': ['Organization', 'FurnitureStore'], '@id': ORG, name: 'SIMMETRIKA', alternateName: 'Симметрика', url: SITE.canonicalOrigin + '/', telephone: settings.phone_main, email: settings.email, sameAs: [settings.vk], areaServed: 'Москва и Московская область' },
    { '@type': 'WebSite', '@id': `${SITE.canonicalOrigin}/#website`, url: SITE.canonicalOrigin + '/', name: 'SIMMETRIKA', publisher: { '@id': ORG }, inLanguage: 'ru' },
  ];
  const pageType = { home: 'WebPage', hub: 'CollectionPage', category: 'CollectionPage', collection: 'CollectionPage', filter: 'CollectionPage', landing: 'CollectionPage', product: 'ItemPage', about: 'AboutPage', contact: 'ContactPage', 'news-list': 'CollectionPage', 'news-item': 'WebPage' }[type || d.type] || 'WebPage';
  g.push({ '@type': pageType, '@id': url + '#webpage', url, name: d.title, description: d.description, isPartOf: { '@id': `${SITE.canonicalOrigin}/#website` }, inLanguage: 'ru', dateModified: d.date_modified ? String(d.date_modified).slice(0, 10) : undefined });
  if (breadcrumbs?.length) g.push({ '@type': 'BreadcrumbList', itemListElement: breadcrumbs.map((b, i) => ({ '@type': 'ListItem', position: i + 1, name: b.name, item: canonical(b.url) })) });
  if (product) {
    const p = product;
    const prices = ['rogozhka', 'velyur', 'zhakkard'].map((m) => variantPrice(p, m));
    g.push({ '@type': 'Product', '@id': canonical(p.url) + '#product', name: p.h1, sku: p.id.replace('/', '-'), brand: { '@type': 'Brand', name: 'SIMMETRIKA' }, manufacturer: { '@id': ORG }, image: (p.photos || []).map((s) => img(s)?.l).filter(Boolean).map((x) => SITE.canonicalOrigin + x), description: d.description,
      additionalProperty: [
        { '@type': 'PropertyValue', name: 'Длина', value: p.length_cm, unitCode: 'CMT' },
        { '@type': 'PropertyValue', name: 'Глубина', value: p.depth_cm, unitCode: 'CMT' },
        { '@type': 'PropertyValue', name: 'Механизм', value: p.mechanism_name },
        ...(p.sleeping ? [{ '@type': 'PropertyValue', name: 'Спальное место', value: `${p.sleeping.w}×${p.sleeping.l} см` }] : []),
      ],
      offers: { '@type': 'AggregateOffer', priceCurrency: 'RUB', lowPrice: Math.min(...prices), highPrice: Math.max(...prices), offerCount: 3, availability: p.in_stock ? 'https://schema.org/InStock' : 'https://schema.org/PreOrder', seller: { '@id': ORG } } });
  }
  if (d.type === 'contact') for (const s of showrooms) g.push({ '@type': 'FurnitureStore', '@id': `${SITE.canonicalOrigin}/kontakty/#${s.slug}`, name: `SIMMETRIKA, ${s.name}`, address: { '@type': 'PostalAddress', streetAddress: s.address, addressCountry: 'RU' }, ...(s.lat ? { geo: { '@type': 'GeoCoordinates', latitude: s.lat, longitude: s.lon } } : {}), telephone: s.phone, openingHours: `Mo-Su ${s.hours.replace('–', '-')}`, parentOrganization: { '@id': ORG } });
  if (faq?.length) g.push({ '@type': 'FAQPage', mainEntity: faq.map((x) => ({ '@type': 'Question', name: x.q, acceptedAnswer: { '@type': 'Answer', text: x.a } })) });
  return { '@context': 'https://schema.org', '@graph': g };
}
