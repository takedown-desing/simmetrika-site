import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';
import { SITE } from './src/lib/site.mjs';
import { cms } from './src/lib/cms.mjs';
import { select } from './src/lib/catalog.mjs';

// в sitemap только индексируемые страницы: без noindex, служебных и подборок, где меньше 3 товаров
const closed = new Set(cms().pages.filter((p) => {
  const d = p.data;
  const rule = d.listing?.rule && Object.keys(d.listing.rule).length ? Object.entries(d.listing.rule).map(([k, v]) => `${k}=${v}`).join(',') : '';
  return d.noindex === true || /noindex/.test(d.robots || '') || d.canonical || ['cart', 'error', 'ui'].includes(d.type)
    || (['filter', 'collection'].includes(d.type) && rule && select(rule).length < 3);
}).map((p) => p.data.url).filter(Boolean));

export default defineConfig({
  site: SITE.origin,
  base: SITE.base,
  trailingSlash: 'always',
  build: { format: 'directory' },
  integrations: SITE.preview ? [] : [sitemap({ filter: (page) => !closed.has(new URL(page).pathname.replace(SITE.base, '') || '/') && !/\/(admin|go)\//.test(page) })],
});
