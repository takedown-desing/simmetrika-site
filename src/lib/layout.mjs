// Помощники раскладки готового HTML текста: секции и карточки из H3.
export const splitSections = (html) => {
  const parts = html.split(/(?=<section class="sec">)/);
  const intro = parts[0].startsWith('<section') ? '' : parts.shift();
  return { intro, sections: parts.map((s) => {
    const h = s.match(/<h2[^>]*>([\s\S]*?)<\/h2>/);
    return { html: s, title: h ? h[1].replace(/<[^>]+>/g, '') : '' };
  }) };
};
export const inner = (sec) => sec.replace(/^<section class="sec">/, '').replace(/<\/section>\s*$/, '');

const HEAVY = /<table|class="(?:ig |ig"|pgrid|tiles|shows|lead-form|filters|map|news|sw-wrap)/;
// H3 из текста (у них есть id) + блоки до следующего H3 → карточка, если блоки короткие и без тяжёлых вставок
export const cardify = (html, { force = false } = {}) => {
  const i = html.search(/<h3 id=/);
  if (i < 0) return html;
  const head = html.slice(0, i);
  const chunks = html.slice(i).split(/(?=<h3 id=)/);
  if (chunks.length < 2) return html;
  // хвост последнего чанка после закрытия секции не трогаем
  const ok = force || chunks.every((c) => !HEAVY.test(c) && c.replace(/<[^>]+>/g, '').length < 900);
  if (!ok) return html;
  const n = chunks.length;
  return `${head}<div class="cards" data-n="${n}">${chunks.map((c) => `<div class="card">${c}</div>`).join('')}</div>`;
};
// для страниц: карточки внутри каждой секции отдельно
export const cardifySections = (html) => html.split(/(?=<section class="sec">)/).map((s) => {
  if (!s.startsWith('<section class="sec">')) return s;
  return '<section class="sec">' + cardify(inner(s)) + '</section>';
}).join('');
