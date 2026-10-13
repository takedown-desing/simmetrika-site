// Собирает все ссылки на source/photos из текстов и каталога, делает WebP 600/1200 в public/img/p/.
// Исходники лежат в ../source/photos (в CI не нужны: готовые WebP закоммичены).
import fs from 'node:fs';
import path from 'node:path';
import sharp from 'sharp';
const SRC = path.resolve('../source/photos');
const OUT = 'public/img/p';
const MAP = 'src/data/images.json';
const refs = new Set();
const scan = (s) => { for (const m of s.matchAll(/source\/photos\/([A-Za-z0-9_\-.()]+\.(?:jpe?g|png))/gi)) refs.add(m[1]); };
for (const f of fs.readdirSync('content')) scan(fs.readFileSync(path.join('content', f), 'utf8'));
scan(fs.readFileSync('src/data/catalog.json', 'utf8'));
const map = fs.existsSync(MAP) ? JSON.parse(fs.readFileSync(MAP, 'utf8')) : {};
if (!fs.existsSync(SRC)) { console.log(`images: исходников нет, в карте ${Object.keys(map).length}`); process.exit(0); }
fs.mkdirSync(OUT, { recursive: true });
let made = 0;
for (const file of refs) {
  const key = `source/photos/${file}`;
  const base = path.parse(file).name.split('-')[0];
  const src = path.join(SRC, file);
  if (!fs.existsSync(src)) { console.warn('нет файла', file); continue; }
  const img = sharp(src).rotate();
  const meta = await img.metadata();
  const ratio = (meta.orientation >= 5 ? meta.width / meta.height : meta.height / meta.width);
  const out = {};
  for (const w of [600, 1200]) {
    const name = `${base}-${w}.webp`;
    const dst = path.join(OUT, name);
    if (!fs.existsSync(dst)) { await sharp(src).rotate().resize({ width: w, withoutEnlargement: true }).webp({ quality: 72 }).toFile(dst); made++; }
    out[w] = `/img/p/${name}`;
  }
  map[key] = { s: out[600], l: out[1200], w: 1200, h: Math.round(1200 * ratio) };
}
fs.writeFileSync(MAP, JSON.stringify(map, null, 1));
console.log(`images: ссылок ${refs.size}, новых файлов ${made}, в карте ${Object.keys(map).length}`);
