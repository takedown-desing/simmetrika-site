<?php
// Слой данных: записи админки лежат в MySQL (таблица entries: коллекция, имя файла записи, JSON).
// Здесь они собираются в каталог (товары, категории, ткани, шоурумы, настройки) и список страниц,
// ровно как в прежней Astro-версии (src/lib/cms.mjs, content.mjs, site.mjs).

const COLLECTIONS = ['categories', 'collections', 'news', 'pages', 'products'];
const UNDEF = "\0undef";

// незаполненные поля ("", null, пробелы, [], {}) убираем, чтобы шаблоны не рисовали пустые блоки
function prune($v)
{
    if (is_array($v)) {
        $list = array_is_list($v);
        $out = [];
        foreach ($v as $k => $x) {
            $x = prune($x);
            if ($x === UNDEF) continue;
            if ($list) $out[] = $x; else $out[$k] = $x;
        }
        return $out ? $out : UNDEF;
    }
    if ($v === '' || $v === null || (is_string($v) && preg_match('/^\s*$/u', $v))) return UNDEF;
    return $v;
}
function prune_obj($v): array { $x = prune($v); return is_array($x) ? $x : []; }

function js_trim(string $s): string { return preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', $s); }

// все записи из базы: [коллекция => [имя файла => данные]] (сырые, как в JSON-файлах)
function raw_entries(bool $reload = false): array
{
    static $rows = null;
    if ($rows !== null && !$reload) return $rows;
    $rows = [];
    foreach (db()->query('SELECT collection, file, data FROM entries') as $r) {
        $d = json_decode($r['data'], true);
        $rows[$r['collection']][$r['file']] = is_array($d) ? $d : [];
    }
    foreach ($rows as &$c) uksort($c, 'strcmp');
    unset($c);
    return $rows;
}
function read_col(string $col): array
{
    $out = [];
    foreach (raw_entries()[$col] ?? [] as $file => $d) $out[] = array_merge(['file' => $file], prune_obj($d));
    return $out;
}
function setting(string $name, array $def = []): array
{
    $all = raw_entries();
    return isset($all['settings'][$name]) ? prune_obj($all['settings'][$name]) : $def;
}

const TR = ['а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya'];
function translit($s = ''): string
{
    $s = strtr(mb_strtolower((string)$s), TR);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = preg_replace('/^-|-$/', '', $s);
    return substr($s, 0, 80);
}
function slug_of(array $e, $from = null): string
{
    $slug = $e['slug'] ?? '';
    if (is_string($slug) && $slug !== '' && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)) return $slug;
    return translit(truthy($slug) ? $slug : (truthy($from) ? $from : ($e['file'] ?? '')));
}
function norm_url($u = ''): string
{
    $s = js_trim((string)$u);
    if ($s === '') return '';
    if ($s[0] !== '/') $s = '/' . $s;
    if (substr($s, -1) !== '/') $s .= '/';
    return preg_replace('#/{2,}#', '/', $s);
}
function clean_rule($r): array
{
    $out = [];
    foreach (is_array($r) ? $r : [] as $k => $v) {
        if ($v === false || $v === null || $v === '' || (is_array($v) && !$v)) continue;
        $out[$k] = $v;
    }
    return $out;
}

function cms_reset(): void
{
    raw_entries(true);
    $GLOBALS['__cache'] = [];
}
function &cache_slot(string $name)
{
    if (!isset($GLOBALS['__cache'])) $GLOBALS['__cache'] = [];
    if (!array_key_exists($name, $GLOBALS['__cache'])) $GLOBALS['__cache'][$name] = null;
    return $GLOBALS['__cache'][$name];
}

function cms(): array
{
    $c = &cache_slot('cms');
    if ($c !== null) return $c;
    $fab = setting('fabrics', ['fabrics' => [], 'colors' => [], 'mechanisms' => []]);
    $company = setting('company');
    $catsRaw = array_values(array_filter(read_col('categories'), fn($x) => !truthy($x['hidden'] ?? null)));
    usort($catsRaw, fn($a, $b) => ($a['order'] ?? 999) <=> ($b['order'] ?? 999));
    $categories = [];
    foreach ($catsRaw as $e) {
        $slug = slug_of($e, $e['name'] ?? null);
        $categories[] = ['slug' => $slug, 'name' => $e['name'] ?? null, 'h1' => truthy($e['h1'] ?? null) ? $e['h1'] : ($e['name'] ?? null), 'url' => "/catalog/$slug/", 'in_menu' => ($e['in_menu'] ?? null) !== false, '_entry' => $e];
    }
    $catBySlug = [];
    $catByFile = [];
    foreach ($categories as $cat) { $catBySlug[$cat['slug']] = $cat; $catByFile[$cat['_entry']['file']] = $cat; }
    $catOf = fn($v) => is_string($v) || is_int($v) ? ($catByFile[$v]['slug'] ?? $v) : $v;
    $TYPE = ['pryamye-divany' => 'Прямой', 'uglovye-divany' => 'Угловой', 'modulnye-divany' => 'Модульный', 'kresla' => 'Кресло'];
    $MECH = [];
    foreach ($fab['mechanisms'] ?? [] as $m) $MECH[$m['slug']] = $m['name'];

    $prodRaw = [];
    foreach (read_col('products') as $p) {
        $p['category'] = $catOf($p['category'] ?? null);
        if (truthy($p['hidden'] ?? null) || !isset($catBySlug[$p['category'] ?? ''])) continue;
        $prodRaw[] = $p;
    }
    usort($prodRaw, fn($a, $b) => ($a['order'] ?? 9999) <=> ($b['order'] ?? 9999));
    $products = [];
    foreach ($prodRaw as $p) {
        $slug = slug_of($p, $p['name'] ?? null);
        $mech = truthy($p['mechanism'] ?? null) ? $p['mechanism'] : 'none';
        $sl = $p['sleeping'] ?? null;
        $st = $p['in_stock'] ?? null;
        $prod = [
            'id' => "{$p['category']}/$slug", 'category' => $p['category'], 'slug' => $slug, 'name' => $p['name'] ?? null, 'type' => $TYPE[$p['category']] ?? '',
            'h1' => truthy($p['h1'] ?? null) ? $p['h1'] : ($p['name'] ?? null),
            'url' => "/catalog/{$p['category']}/$slug/", 'model_family' => truthy($p['model_family'] ?? null) ? $p['model_family'] : $slug,
            'length_cm' => $p['length_cm'] ?? null, 'depth_cm' => $p['depth_cm'] ?? null, 'height_cm' => $p['height_cm'] ?? null, 'seat_height_cm' => $p['seat_height_cm'] ?? null,
            'sleeping' => is_array($sl) && truthy($sl['w'] ?? null) && truthy($sl['l'] ?? null) ? $sl : null,
            'mechanism' => $mech, 'mechanism_name' => $MECH[$mech] ?? 'без механизма',
            'has_sleeping_place' => $mech !== 'none', 'has_storage' => truthy($p['has_storage'] ?? null), 'is_hit' => truthy($p['is_hit'] ?? null), 'tags' => $p['tags'] ?? [],
            'price_from' => truthy($p['price_from'] ?? null) ? $p['price_from'] : null,
            'lead_time_days' => truthy($p['lead_time_days'] ?? null) ? $p['lead_time_days'] : '14–21',
            'in_stock' => is_array($st) && truthy($st['material'] ?? null) && truthy($st['color'] ?? null) ? $st : null,
            'sides' => truthy($p['sides'] ?? null) ? ['left', 'right'] : null,
            'photos' => $p['photos'] ?? [],
            'samples' => array_values(array_filter($p['samples'] ?? [], fn($s) => truthy($s['showroom'] ?? null) && truthy($s['discount_pct'] ?? null))),
            'construction' => truthy($p['construction'] ?? null) ? $p['construction'] : '',
            'warranty_months' => truthy($p['warranty_months'] ?? null) ? $p['warranty_months'] : (truthy($company['warranty_months'] ?? null) ? $company['warranty_months'] : 18),
        ];
        if (!empty($p['modules'])) $prod['modules'] = $p['modules'];
        $prod['_entry'] = $p;
        $products[] = $prod;
    }
    $showrooms = array_values(array_filter(setting('showrooms', ['showrooms' => []])['showrooms'] ?? [], fn($s) => !truthy($s['hidden'] ?? null)));
    $colors = $hex = $plural = [];
    foreach ($fab['colors'] ?? [] as $x) {
        $colors[$x['slug']] = $x['name'];
        $hex[$x['slug']] = truthy($x['hex'] ?? null) ? $x['hex'] : '#c8c8c8';
        $plural[$x['slug']] = truthy($x['plural'] ?? null) ? $x['plural'] : $x['name'];
    }
    $catalog = [
        'categories' => array_map(function ($c) { unset($c['_entry']); return $c; }, $categories),
        'mechanisms' => $MECH, 'colors' => $colors, 'color_hex' => $hex, 'color_plural' => $plural,
        'fabrics' => $fab['fabrics'] ?? [], 'products' => $products, 'showrooms' => $showrooms,
        'settings' => array_merge($company, ['delivery' => setting('delivery')]),
    ];

    $byFile = [];
    foreach ($products as $p) $byFile[$p['_entry']['file']] = $p;
    $pages = [];
    $page = function ($col, $file, $data, $body) use (&$pages) {
        $pages[] = ['col' => $col, 'file' => $file, 'data' => $data, 'body' => js_trim(is_string($body) ? $body : '')];
    };
    foreach ($products as $p) {
        $e = $p['_entry'];
        $page('products', $e['file'], array_merge($e, ['page_id' => "product:{$p['id']}", 'type' => 'product', 'url' => $p['url'], 'h1' => $p['h1']]), $e['body'] ?? '');
    }
    foreach ($categories as $c) {
        $e = $c['_entry'];
        $page('categories', $e['file'], array_merge($e, [
            'page_id' => $c['slug'], 'type' => 'category', 'url' => $c['url'], 'h1' => truthy($e['h1'] ?? null) ? $e['h1'] : $c['name'],
            'title' => truthy($e['title'] ?? null) ? $e['title'] : "{$c['name']} от производителя | SIMMETRIKA",
            'listing' => ['rule' => ['category' => $c['slug']]],
        ]), $e['body'] ?? '');
    }
    foreach (read_col('collections') as $e) {
        if (truthy($e['hidden'] ?? null)) continue;
        $url = norm_url(truthy($e['url'] ?? null) ? $e['url'] : '/catalog/' . translit(truthy($e['h1'] ?? null) ? $e['h1'] : $e['file']) . '/');
        $rule0 = $e['listing']['rule'] ?? [];
        $rule0 = is_array($rule0) ? $rule0 : [];
        $cat = $rule0['category'] ?? null;
        unset($rule0['category']);
        $rule0['category'] = truthy($cat) ? $catOf($cat) : $cat;
        $listing = array_merge(is_array($e['listing'] ?? null) ? $e['listing'] : [], ['rule' => obj(clean_rule($rule0))]);
        $page('collections', $e['file'], array_merge($e, ['page_id' => "filter:{$e['file']}", 'type' => truthy($e['type'] ?? null) ? $e['type'] : 'filter', 'url' => $url, 'listing' => $listing]), $e['body'] ?? '');
    }
    $prodIds = array_column($products, 'id');
    foreach (read_col('news') as $e) {
        if (truthy($e['hidden'] ?? null)) continue;
        $slug = slug_of($e, $e['h1'] ?? null);
        $ids = [];
        foreach ($e['product_ids'] ?? [] as $x) {
            $id = $byFile[$x]['id'] ?? (in_array($x, $prodIds, true) ? $x : null);
            if (truthy($id)) $ids[] = $id;
        }
        $page('news', $e['file'], array_merge($e, ['product_ids' => $ids, 'page_id' => "news:$slug", 'type' => 'news-item', 'url' => "/news/$slug/"]), $e['body'] ?? '');
    }
    foreach (read_col('pages') as $e) {
        if (truthy($e['hidden'] ?? null)) continue;
        $d = array_merge($e, ['page_id' => truthy($e['id'] ?? null) ? $e['id'] : $e['file'], 'type' => truthy($e['type'] ?? null) ? $e['type'] : 'service', 'url' => truthy($e['url'] ?? null) ? norm_url($e['url']) : null]);
        if ($d['url'] === null) unset($d['url']);
        $page('pages', $e['file'], $d, $e['body'] ?? '');
    }
    $pages[] = ['col' => 'settings', 'file' => 'ui', 'data' => ['page_id' => '_ui', 'type' => 'ui', 'ui_texts' => setting('ui', [])], 'body' => ''];

    $c = [
        'catalog' => $catalog, 'pages' => $pages,
        'menu' => setting('menu', ['nav' => [], 'nav_right' => [], 'catalog_menu' => []]),
        'redirects' => setting('redirects', ['redirects' => []])['redirects'] ?? [],
    ];
    return $c;
}

// ── карта адресов и автопереадресации ──
function url_map(): array
{
    $out = [];
    foreach (cms()['pages'] as $p) if (truthy($p['data']['url'] ?? null) && $p['col'] !== 'settings') $out["{$p['col']}/{$p['file']}"] = $p['data']['url'];
    return $out;
}
function redirect_list(): array
{
    $c = &cache_slot('redirect_list');
    if ($c !== null) return $c;
    $now = url_map();
    $live = array_flip(array_values($now));
    $oldMap = setting('url-map', []);
    $auto = setting('redirects-auto', []);
    $parentOf = function ($u) use ($live) {
        $parts = array_values(array_filter(explode('/', $u), fn($x) => $x !== ''));
        while ($parts) {
            array_pop($parts);
            $p = '/' . implode('/', $parts) . ($parts ? '/' : '');
            if (isset($live[$p]) || $p === '/') return $p;
        }
        return '/';
    };
    foreach ($oldMap as $key => $oldUrl) {
        $cur = $now[$key] ?? null;
        if ($cur && $cur !== $oldUrl) $auto[$oldUrl] = $cur;
        elseif (!$cur && !isset($live[$oldUrl])) $auto[$oldUrl] = $parentOf($oldUrl);
    }
    foreach (cms()['redirects'] as $r) {
        if (!truthy($r['from'] ?? null) || !truthy($r['to'] ?? null)) continue;
        $f = js_trim((string)$r['from']);
        $auto[preg_match('/\.html?$/', $f) ? '/' . preg_replace('#^/#', '', $f) : norm_url($r['from'])] = $r['to'];
    }
    $out = [];
    foreach ($auto as $from => $to) {
        $from = (string)$from;
        if (isset($live[$from])) continue;
        $seen = [$from => 1];
        while (isset($auto[$to]) && !isset($live[$to]) && !isset($seen[$to])) { $seen[$to] = 1; $to = $auto[$to]; }
        if ($to !== $from) $out[$from] = $to;
    }
    $autoLeft = [];
    foreach ($auto as $f => $t) if (!isset($live[(string)$f])) $autoLeft[(string)$f] = $t;
    $c = ['auto' => $autoLeft, 'resolved' => $out];
    return $c;
}

// ── адреса сайта ──
function base(): string { return cfg('base'); }
function direct(string $path): string
{
    $res = redirect_list()['resolved'];
    $parts = explode('?', $path);
    $p0 = $parts[0];
    $q = $parts[1] ?? '';
    $n = preg_match('/\.html?$/', $p0) ? $p0 : (substr($p0, -1) === '/' ? $p0 : $p0 . '/');
    if (isset($res[$n]) && !preg_match('/^https?:/', $res[$n])) return $res[$n] . ($q !== '' ? '?' . $q : '');
    return $path;
}
function u($p = '/')
{
    if ($p === null || $p === '' || preg_match('/^(https?:|mailto:|tel:|#)/', (string)$p)) return $p;
    $p = (string)$p;
    $parts = explode('#', $p);
    $path = $parts[0];
    $hash = $parts[1] ?? '';
    $b = base();
    if (substr($path, 0, 1) === '/' && ($b === '' || strpos($path, $b . '/') !== 0)) $path = direct($path);
    $full = preg_replace('#/{2,}#', '/', $b . (substr($path, 0, 1) === '/' ? $path : '/' . $path));
    return $full . ($hash !== '' ? '#' . $hash : '');
}
function canonical_origin(): string { return cfg('canonical_origin') ?: cfg('origin'); }
function canonical_url(string $p): string { return canonical_origin() . $p; }
function page_title(array $d = []): string
{
    $t = truthy($d['title'] ?? null) ? $d['title'] : (truthy($d['h1'] ?? null) ? "{$d['h1']} | SIMMETRIKA" : 'SIMMETRIKA');
    return str_replace('—', '–', (string)$t);
}
function menus(): array
{
    $c = &cache_slot('menus');
    if ($c !== null) return $c;
    $M = cms()['menu'];
    $cols = $M['catalog_menu'] ?? [];
    $in = [];
    foreach ($cols as $g) foreach ($g['items'] ?? [] as $i) $in[norm_url($i['url'] ?? '')] = 1;
    $missing = [];
    foreach (cms()['catalog']['categories'] as $cat) if ($cat['in_menu'] && !isset($in[$cat['url']])) $missing[] = ['title' => $cat['name'], 'url' => $cat['url']];
    if ($missing && $cols) $cols[0]['items'] = array_merge($cols[0]['items'] ?? [], $missing);
    $c = ['nav' => $M['nav'] ?? [], 'nav_right' => $M['nav_right'] ?? [], 'catalog' => $cols];
    return $c;
}

// ── типографика: неразрывные пробелы ──
const NB = "\u{00A0}";
function typo($s)
{
    if (!is_string($s) || $s === '') return $s;
    static $short = 'в|во|и|с|со|к|ко|о|об|обо|у|а|на|по|за|от|до|не|ни|из|для|без|при|под|над|про|что|как|или|но|же|ли|бы|вы|мы|я|он|её|их|его|до';
    $t = $s;
    $t = preg_replace('/(\d) (?=\d{3}(?!\d))/u', '$1' . NB, $t);
    $t = preg_replace('/(\d)\s+(%|₽|руб|см|мм|м|км|кг|шт|дн|дня|дней|день|мес|месяц|месяца|месяцев|лет|года|год|ч|час|часа|часов|минут|этаж|этажа|места|мест|модели|моделей|моделях|исполнений|исполнениях|шоурумов|шоурума|ткани|тканей|цветов|цветовых|точек|товара|товаров|раза|года)(?=[\s.,;:!?)»]|$)/iu', '$1' . NB . '$2', $t);
    $t = preg_replace('/(№|§)\s+(\d)/u', '$1' . NB . '$2', $t);
    for ($i = 0; $i < 2; $i++) $t = preg_replace('/(^|[\s(«„"—–])(' . $short . ')\s+(?=[^\s])/iu', '$1$2' . NB, $t);
    $t = preg_replace('/(\S) +([–—]) /u', '$1' . NB . '$2 ', $t);
    return $t;
}
const SKIP_KEYS = ['_entry', 'photos', 'image', 'images', 'listing', 'samples', 'in_stock', 'sleeping', 'url', 'href', 'src', 'page_id', 'type', 'slug', 'cover', 'canonical', 'robots', 'rule', 'action', 'primary_kw', 'secondary_kws', 'assumptions', 'date', 'date_end', 'date_modified', 'title', 'description', 'og_title', 'og_description', 'x_robots_tag', 'external_links', 'anchors', 'placeholders'];
function deep_typo($v, $key = null)
{
    if ($key !== null && in_array((string)$key, SKIP_KEYS, true)) return $v;
    if (is_string($v)) return typo($v);
    if (is_array($v)) {
        if (array_is_list($v)) return array_map(fn($x) => deep_typo($x, $key), $v);
        $o = [];
        foreach ($v as $k => $x) $o[$k] = deep_typo($x, $k);
        return $o;
    }
    return $v;
}
function typo_md(string $md): string
{
    $lines = explode("\n", $md);
    foreach ($lines as &$line) {
        if (preg_match('/^\s*:::/u', $line) || preg_match('/^\s*\|?\s*-{3,}/u', $line) || preg_match('/^\s*<!--/u', $line)) continue;
        $parts = preg_split('/(\]\([^)]*\)|\{[^}]*\})/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => &$part) if ($i % 2 === 0) $part = typo($part);
        unset($part);
        $line = implode('', $parts);
    }
    unset($line);
    return implode("\n", $lines);
}
function load_pages(): array
{
    $c = &cache_slot('load_pages');
    if ($c !== null) return $c;
    $c = array_map(fn($p) => ['col' => $p['col'], 'file' => $p['file'], 'data' => deep_typo($p['data']), 'body' => typo_md($p['body'] ?? '')], cms()['pages']);
    return $c;
}
function by_url(): array
{
    $c = &cache_slot('by_url');
    if ($c !== null) return $c;
    $c = [];
    foreach (load_pages() as $p) {
        $url = $p['data']['url'] ?? null;
        if (truthy($url) && strpos((string)$url, '{') === false) $c[$url] = $p;
    }
    return $c;
}
function by_id(): array
{
    $o = [];
    foreach (load_pages() as $p) $o[jsstr($p['data']['page_id'] ?? null)] = $p;
    return $o;
}
function title_of(string $url): string
{
    $p = by_url()[$url] ?? null;
    if ($p) {
        if (truthy($p['data']['h1'] ?? null)) return (string)$p['data']['h1'];
        if (truthy($p['data']['title'] ?? null)) { $t = js_trim(explode('|', (string)$p['data']['title'])[0]); if ($t !== '') return $t; }
    }
    return $url;
}
