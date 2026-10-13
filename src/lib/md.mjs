import { unified } from 'unified';
import remarkParse from 'remark-parse';
import remarkGfm from 'remark-gfm';
import remarkDirective from 'remark-directive';
import remarkRehype from 'remark-rehype';
import rehypeRaw from 'rehype-raw';
import rehypeStringify from 'rehype-stringify';
import { visit } from 'unist-util-visit';
import { u, SITE } from './site.mjs';
import * as R from './render.mjs';

const slugify = (s) => s.toLowerCase().replace(/ё/g, 'е').replace(/[^a-zа-я0-9]+/gi, '-').replace(/^-|-$/g, '').slice(0, 60);
const html = (value) => ({ type: 'html', value });
const textOf = (n) => (n.value || '') + (n.children || []).map(textOf).join('');

function remarkDirectives(ctx = {}) {
  return (tree) => {
    visit(tree, (node, index, parent) => {
      if (!['containerDirective', 'leafDirective', 'textDirective'].includes(node.type) || !parent) return;
      const a = node.attributes || {};
      const name = node.name;
      const data = (node.data ||= {});
      const idAttr = a.id ? { id: a.id } : {};
      const replace = (h) => { parent.children.splice(index, 1, html(h)); return index; };
      switch (name) {
        case 'products': return replace(R.productsGrid(String(a.rule || 'all').replace('{slug}', ctx.showroom || ''), a.limit));
        case 'filters': return replace(R.filtersHtml(a.set));
        case 'tiles': return replace(R.tiles(String(a.items || '').split(',').map((s) => s.trim())));
        case 'chips': return replace(R.chips(String(a.items || '').split(',').map((s) => s.trim())));
        case 'spec-table': return replace(R.specTable(a.rule, a.cols));
        case 'swatches': return replace(R.swatches(a.materials));
        case 'showrooms': return replace(R.showroomsHtml(a.mode));
        case 'map': return replace(R.mapHtml());
        case 'news': return replace(R.newsHtml(a.limit, a.type));
        case 'promo': return replace(R.promoHtml());
        case 'form': {
          const lead = textOf(node).trim();
          return replace(R.formHtml(a.type, { title: a.title, id: a.id || 'forma', lead }));
        }
        case 'steps': case 'stats': case 'compare': {
          data.hName = 'section';
          data.hProperties = { className: ['ig', `ig-${name}`], ...idAttr };
          if (a.title) node.children.unshift({ type: 'paragraph', data: { hProperties: { className: ['ig-title'] } }, children: [{ type: 'text', value: a.title }] });
          return;
        }
        case 'scheme': {
          data.hName = 'figure';
          data.hProperties = { className: ['ig', 'ig-scheme'], ...idAttr };
          const svg = R.schemeSvg(a.kind, a);
          if (svg) node.children.unshift(html(`<div class="scheme-svg">${svg}</div>`));
          if (a.title) node.children.unshift({ type: 'paragraph', data: { hProperties: { className: ['ig-title'] } }, children: [{ type: 'text', value: a.title }] });
          return;
        }
        case 'cta': {
          data.hName = 'aside';
          data.hProperties = { className: ['blk-cta'], ...idAttr };
          const href = a.href || '#forma';
          const btn = a.action === 'callback' || href === 'callback'
            ? `<p class="blk-cta-act"><button type="button" class="btn btn-accent" data-callback>${R.esc(a.label || 'Обратный звонок')}</button></p>`
            : `<p class="blk-cta-act"><a class="btn btn-accent" href="${u(href)}">${R.esc(a.label || 'Подробнее')}</a></p>`;
          node.children.push(html(btn));
          return;
        }
        case 'note': data.hName = 'aside'; data.hProperties = { className: ['blk-note'], ...idAttr }; return;
        case 'practice': data.hName = 'aside'; data.hProperties = { className: ['blk-practice'], ...idAttr };
          node.children.unshift({ type: 'paragraph', data: { hProperties: { className: ['blk-label'] } }, children: [{ type: 'text', value: 'Из опыта фабрики' }] }); return;
        default:
          if (node.type === 'textDirective') return replace(`:${name}`);
          data.hName = 'div'; data.hProperties = { className: [`blk-${name}`] };
      }
    });
  };
}

// ссылки → с base, внешние в новой вкладке, таблицы в обёртку, H2 с id, секции по H2
function rehypeSite() {
  return (tree) => {
    visit(tree, 'element', (node, index, parent) => {
      if (node.tagName === 'a' && typeof node.properties?.href === 'string') {
        const h = node.properties.href;
        if (h.startsWith('/') && !h.startsWith(SITE.base + '/')) node.properties.href = u(h);
        else if (/^https?:/.test(h)) { node.properties.target = '_blank'; node.properties.rel = ['nofollow', 'noopener']; }
      }
      if (node.tagName === 'table' && parent && !(parent.properties?.className || []).includes('tbl')) {
        parent.children[index] = { type: 'element', tagName: 'div', properties: { className: ['tbl'] }, children: [node] };
      }
      if ((node.tagName === 'h2' || node.tagName === 'h3') && !node.properties.id) {
        node.properties.id = slugify(node.children.map((c) => c.value || (c.children || []).map((x) => x.value || '').join('')).join(''));
      }
    });
    // секции: каждый H2 открывает <section class="sec">
    const out = []; let cur = null;
    for (const n of tree.children) {
      if (n.type === 'element' && n.tagName === 'h2') { cur = { type: 'element', tagName: 'section', properties: { className: ['sec'] }, children: [n] }; out.push(cur); }
      else if (cur) cur.children.push(n);
      else out.push(n);
    }
    tree.children = out;
  };
}

export function renderMd(md = '', ctx = {}) {
  if (!md) return '';
  const file = unified()
    .use(remarkParse).use(remarkGfm).use(remarkDirective).use(remarkDirectives, ctx)
    .use(remarkRehype, { allowDangerousHtml: true }).use(rehypeRaw).use(rehypeSite)
    .use(rehypeStringify).processSync(md);
  return String(file);
}
