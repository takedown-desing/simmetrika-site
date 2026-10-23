// Сжимает фото, загруженные в админке (public/images/uploads): длинная сторона до 1600 px, JPEG/WebP качество 80.
// Файл перезаписывается под тем же именем, поэтому ссылки в записях не меняются. Уже сжатые (до 1600 px и до 400 КБ) не трогает.
import fs from 'node:fs';
import path from 'node:path';
import sharp from 'sharp';
const DIR = 'public/images/uploads';
if (!fs.existsSync(DIR)) process.exit(0);
let n = 0;
for (const f of fs.readdirSync(DIR)) {
  const p = path.join(DIR, f);
  if (!/\.(jpe?g|png|webp)$/i.test(f) || !fs.statSync(p).isFile()) continue;
  const buf = fs.readFileSync(p);
  const meta = await sharp(buf).metadata();
  const big = Math.max(meta.width || 0, meta.height || 0) > 1600;
  if (!big && buf.length < 400 * 1024) continue;
  let img = sharp(buf).rotate().resize({ width: 1600, height: 1600, fit: 'inside', withoutEnlargement: true });
  img = /\.png$/i.test(f) ? img.png({ compressionLevel: 9, palette: true }) : /\.webp$/i.test(f) ? img.webp({ quality: 80 }) : img.jpeg({ quality: 80, mozjpeg: true });
  const out = await img.toBuffer();
  if (out.length < buf.length || big) { fs.writeFileSync(p, out); n++; console.log(`[optimize-uploads] ${f}: ${Math.round(buf.length / 1024)} → ${Math.round(out.length / 1024)} КБ`); }
}
console.log(`[optimize-uploads] сжато файлов: ${n}`);
