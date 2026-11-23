<?php
// Каталог: товары, ткани, цены, правила подборок (порт src/lib/catalog.mjs и layout.mjs).

function C(): array { return cms()['catalog']; }
function products(): array { return C()['products']; }
function showrooms(): array { return C()['showrooms']; }
function settings(): array { return C()['settings']; }
function fabrics(): array { return C()['fabrics']; }
function COLOR_HEX(): array { return C()['color_hex']; }
function COLOR_NAME(): array { return C()['colors']; }
function MATERIAL(): array
{
    $c = &cache_slot('MATERIAL');
    if ($c === null) { $c = []; foreach (fabrics() as $f) $c[$f['material']] = $f; }
    return $c;
}
function CATEGORY(): array
{
    $c = &cache_slot('CATEGORY');
    if ($c === null) { $c = []; foreach (C()['categories'] as $x) $c[$x['slug']] = $x; }
    return $c;
}
function SHOWROOM(): array
{
    $c = &cache_slot('SHOWROOM');
    if ($c === null) { $c = []; foreach (showrooms() as $s) $c[$s['slug']] = $s; }
    return $c;
}

// готовые размеры фото фабрики: /img/p/<имя>-1200.webp и -600.webp (файл app/data/images.json)
function images_map(): array
{
    static $m = null;
    if ($m === null) {
        $m = [];
        $f = APP . '/data/images.json';
        if (is_file($f)) $m = json_decode(file_get_contents($f), true) ?: [];
    }
    return $m;
}
function img($src)
{
    if (!truthy($src) || !is_string($src)) return null;
    $IMG = images_map();
    if (isset($IMG[$src])) return $IMG[$src];
    static $byL = null;
    if ($byL === null) { $byL = []; foreach ($IMG as $v) $byL[$v['l']] = $v; }
    if (isset($byL[$src])) return $byL[$src];
    if (preg_match('#^/img/p/.+-1200\.webp$#', $src) && is_file(ROOT . str_replace('-1200.webp', '-600.webp', $src))) return ['s' => str_replace('-1200.webp', '-600.webp', $src), 'l' => $src, 'w' => 1200, 'h' => 900];
    // фото, загруженные через админку: /img/up/<имя>-1200.webp и уменьшенная копия -600
    if (preg_match('#^/img/up/[\w.-]+-1200\.(webp|jpg)$#', $src) && is_file(ROOT . $src)) {
        $sz = @getimagesize(ROOT . $src) ?: [null, null];
        $small = preg_replace('/-1200\.(webp|jpg)$/', '-600.$1', $src);
        return ['s' => is_file(ROOT . $small) ? $small : $src, 'l' => $src, 'w' => $sz[0], 'h' => $sz[1]];
    }
    if (preg_match('#^(/|https?:)#', $src)) return ['s' => $src, 'l' => $src, 'w' => null, 'h' => null];
    return null;
}
function rub($n): string
{
    if ($n === null) return '';
    return number_format(jsround($n), 0, '', NB) . NB . '₽';
}
function variant_price(array $p, string $material = 'rogozhka')
{
    if (!truthy($p['price_from'] ?? null)) return null;
    $k = MATERIAL()[$material]['price_k'] ?? null;
    $k = truthy($k) ? $k : 1;
    return jsround($p['price_from'] * $k / 100) * 100;
}
function price_text(array $p, string $material = 'rogozhka', bool $from = true): string
{
    return truthy($p['price_from'] ?? null) ? ($from ? 'от ' : '') . rub(variant_price($p, $material)) : 'цена по запросу';
}
function product_colors(): array
{
    $c = &cache_slot('product_colors');
    if ($c !== null) return $c;
    $c = [];
    foreach (array_keys(COLOR_HEX()) as $col) {
        foreach (fabrics() as $f) if (in_array($col, $f['colors'] ?? [], true)) { $c[] = (string)$col; break; }
    }
    return $c;
}
function plural($n, $one, $few, $many)
{
    $a = $n % 10; $b = $n % 100;
    return $a === 1 && $b !== 11 ? $one : ($a >= 2 && $a <= 4 && ($b < 12 || $b > 14) ? $few : $many);
}
function size_str(array $p): string
{
    if (!truthy($p['length_cm'] ?? null)) return 'размеры по запросу';
    $parts = array_filter([$p['length_cm'], $p['depth_cm'], $p['height_cm']], 'truthy');
    return implode('×', array_map('jsstr', $parts)) . ' см';
}
function sleep_str(array $p): string
{
    return $p['sleeping'] ? jsstr($p['sleeping']['w']) . '×' . jsstr($p['sleeping']['l']) . ' см' : 'нет';
}
function best_sample(array $p)
{
    $s = $p['samples'] ?? [];
    usort($s, fn($a, $b) => js_cmp_num($b['discount_pct'] - $a['discount_pct']));
    return $s[0] ?? null;
}
// результат функции сравнения JS → -1/0/1 (NaN считается равенством)
function js_cmp_num($d): int
{
    if (!is_int($d) && !is_float($d)) return 0;
    if (is_float($d) && is_nan($d)) return 0;
    return $d < 0 ? -1 : ($d > 0 ? 1 : 0);
}
// Number(x) из JS для простых случаев
function jsnumber($v)
{
    if (is_int($v) || is_float($v)) return $v;
    if ($v === null || $v === false) return 0;
    if ($v === true) return 1;
    $s = js_trim((string)$v);
    if ($s === '') return 0;
    if (is_numeric($s)) return $s + 0;
    return NAN;
}

function parse_rule($rule = 'all'): array
{
    if (!truthy($rule) || $rule === 'all') return [];
    $out = [];
    foreach (explode(',', jsstr($rule)) as $part) {
        $kv = array_map('js_trim', explode('=', $part));
        $k = $kv[0];
        if ($k !== '') $out[$k] = $kv[1] ?? 'true';
    }
    return $out;
}
function match_rule(array $p, $rule): bool
{
    $r = is_string($rule) ? parse_rule($rule) : (is_array($rule) ? $rule : []);
    if (!truthy($r['category'] ?? null) && $p['category'] === 'kresla') return false;
    foreach ($r as $k => $v0) {
        $k = (string)$k;
        if ($k === 'all') continue;
        $v = $v0 === true ? 'true' : ($v0 === false ? 'false' : jsstr($v0));
        if ($k === 'category' && $p['category'] !== $v) return false;
        if ($k === 'mechanism' && $p['mechanism'] !== $v) return false;
        if ($k === 'tag' && !in_array($v, array_map('jsstr', $p['tags'] ?: []), true)) return false;
        if ($k === 'in_stock' && (bool)$p['in_stock'] !== ($v === 'true')) return false;
        if ($k === 'sample' && (count($p['samples'] ?? []) > 0) !== ($v === 'true')) return false;
        if ($k === 'hit' && (bool)$p['is_hit'] !== ($v === 'true')) return false;
        if ($k === 'has_sleeping_place' && (bool)$p['has_sleeping_place'] !== ($v === 'true')) return false;
        if ($k === 'has_storage' && (bool)$p['has_storage'] !== ($v === 'true')) return false;
        if ($k === 'length_gte') {
            $n = jsnumber($v);
            $len = $p['length_cm'];
            if ($len === null || is_nan((float)$n) || !(jsnumber($len) >= $n)) return false;
        }
        if ($k === 'showroom') {
            $ok = false;
            foreach ($p['samples'] ?? [] as $s) if (($s['showroom'] ?? null) === $v) { $ok = true; break; }
            if (!$ok) return false;
        }
        if ($k === 'material' && !isset(MATERIAL()[$v])) return false;
    }
    return true;
}
function select_products($rule, $limit = null): array
{
    $list = array_values(array_filter(products(), fn($p) => match_rule($p, $rule)));
    $r = parse_rule($rule);
    if (truthy($r['sample'] ?? null)) {
        usort($list, fn($a, $b) => js_cmp_num((best_sample($b)['discount_pct'] ?? 0) - (best_sample($a)['discount_pct'] ?? 0)));
    } else {
        usort($list, function ($a, $b) {
            $d = (int)$b['is_hit'] - (int)$a['is_hit'];
            if ($d) return $d;
            $pa = $a['price_from']; $pb = $b['price_from'];
            return js_cmp_num((float)($pa ?? 0) - (float)($pb ?? 0));
        });
    }
    if (truthy($limit)) return array_slice($list, 0, (int)jsnumber($limit));
    return $list;
}
function min_price(array $list, $material = null)
{
    $v = array_filter(array_map(fn($p) => variant_price($p, $material ?: 'rogozhka'), $list), 'truthy');
    return $v ? min($v) : INF;
}

// ── раскладка готового HTML: секции по H2 и карточки по H3 (layout.mjs) ──
function split_sections(string $html): array
{
    $parts = preg_split('/(?=<section class="sec">)/', $html, -1, PREG_SPLIT_NO_EMPTY);
    $intro = '';
    if ($parts && strpos($parts[0], '<section') !== 0) $intro = array_shift($parts);
    $sections = [];
    foreach ($parts as $s) {
        if ($s === '') continue;
        $title = preg_match('/<h2[^>]*>([\s\S]*?)<\/h2>/', $s, $m) ? preg_replace('/<[^>]+>/', '', $m[1]) : '';
        $sections[] = ['html' => $s, 'title' => $title];
    }
    return ['intro' => $intro, 'sections' => $sections];
}
function inner_sec(string $sec): string
{
    $s = preg_replace('/^<section class="sec">/', '', $sec);
    return preg_replace('/<\/section>\s*$/', '', $s);
}
function cardify(string $html, bool $force = false): string
{
    if (!preg_match('/<h3 id=/', $html, $m, PREG_OFFSET_CAPTURE)) return $html;
    $i = $m[0][1];
    $head = substr($html, 0, $i);
    $chunks = preg_split('/(?=<h3 id=)/', substr($html, $i));
    $chunks = array_values(array_filter($chunks, fn($c) => $c !== ''));
    if (count($chunks) < 2) return $html;
    $HEAVY = '/<table|class="(?:ig |ig"|pgrid|tiles|shows|lead-form|filters|map|news|sw-wrap)/';
    $ok = $force;
    if (!$ok) {
        $ok = true;
        foreach ($chunks as $c) if (preg_match($HEAVY, $c) || js_len(preg_replace('/<[^>]+>/', '', $c)) >= 900) { $ok = false; break; }
    }
    if (!$ok) return $html;
    $n = count($chunks);
    return $head . '<div class="cards" data-n="' . $n . '">' . implode('', array_map(fn($c) => '<div class="card">' . $c . '</div>', $chunks)) . '</div>';
}
// длина строки в JS (единицы UTF-16)
function js_len(string $s): int { return intdiv(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2); }
function cardify_sections(string $html): string
{
    $parts = preg_split('/(?=<section class="sec">)/', $html, -1, PREG_SPLIT_NO_EMPTY);
    $out = '';
    foreach ($parts as $s) {
        if (strpos($s, '<section class="sec">') !== 0) { $out .= $s; continue; }
        $out .= '<section class="sec">' . cardify(inner_sec($s)) . '</section>';
    }
    return $out;
}
