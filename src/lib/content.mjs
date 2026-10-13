import fs from 'node:fs';
import path from 'node:path';
import * as yaml from 'js-yaml';
const DIR = path.resolve('content');
let cache = null;
export function loadPages() {
  if (cache) return cache;
  const pages = [];
  for (const f of fs.readdirSync(DIR).filter((x) => x.endsWith('.md')).sort()) {
    const raw = fs.readFileSync(path.join(DIR, f), 'utf8');
    for (const chunk of raw.split('<!-- page-break -->')) {
      const m = chunk.match(/^\s*---\n([\s\S]*?)\n---\n?([\s\S]*)$/);
      if (!m) continue;
      const data = yaml.load(m[1]) || {};
      pages.push({ file: f, data, body: (m[2] || '').trim() });
    }
  }
  cache = pages;
  return pages;
}
export const byUrl = () => Object.fromEntries(loadPages().filter((p) => p.data.url && !String(p.data.url).includes('{')).map((p) => [p.data.url, p]));
export const byId = () => Object.fromEntries(loadPages().map((p) => [p.data.page_id, p]));
export const ui = () => loadPages().find((p) => p.data.page_id === '_ui' || p.data.type === 'ui')?.data.ui_texts || {};
export const titleOf = (url) => {
  const p = byUrl()[url];
  return p?.data.h1 || p?.data.title?.split('|')[0].trim() || url;
};
