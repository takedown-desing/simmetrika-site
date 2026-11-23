<?php
// HTML-фрагменты для вставок в тексты (:::products, :::tiles …) и для шаблонов (порт src/lib/render.mjs).

function replace_first(string $search, string $repl, string $s): string
{
    $i = strpos($s, $search);
    return $i === false ? $s : substr($s, 0, $i) . $repl . substr($s, $i + strlen($search));
}
// у нового цвета может не быть своей страницы-подборки: тогда кружок без ссылки
function color_href($c): string { return isset(by_url()["/catalog/cvet/$c/"]) ? u("/catalog/cvet/$c/") : ''; }
function phone_href($p): string { return 'tel:' . preg_replace('/[^\d+]/', '', jsstr($p)); }

function picture($src, $alt = '', array $o = []): string
{
    $cls = $o['cls'] ?? '';
    $eager = $o['eager'] ?? false;
    $sizes = $o['sizes'] ?? '(min-width: 1000px) 400px, 100vw';
    $i = img($src);
    if (!$i) return '<span class="ph ' . $cls . '" role="img" aria-label="' . esc($alt) . '"></span>';
    $set = $i['s'] !== $i['l'] ? ' srcset="' . u($i['s']) . ' 600w, ' . u($i['l']) . ' 1200w" sizes="' . $sizes . '"' : '';
    $wh = $i['w'] ? ' width="' . jsstr($i['w']) . '" height="' . jsstr($i['h']) . '"' : '';
    $srcv = preg_match('/^https?:/', $i['l']) ? $i['l'] : u($i['l']);
    return '<img class="' . $cls . '" src="' . $srcv . '"' . $set . ' alt="' . esc($alt) . '"' . $wh . ' ' . ($eager ? 'fetchpriority="high"' : 'loading="lazy"') . ' decoding="async">';
}

const ICON = [
    'stock' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8L3.5 9.7l5.9-.9z" fill="currentColor"/></svg>',
    'mech' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 15h16M6 15v3M18 15v3M5 15c0-3 1-5 3-5h8c2 0 3 2 3 5" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M9 7l3-3 3 3" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>',
    'box' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h16v11H4zM4 8l2-4h12l2 4M9 12h6" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>',
];

function cart_payload(array $p, string $material = 'rogozhka', $color = null, array $extra = []): string
{
    $o = ['id' => $p['id'], 'name' => $p['h1'], 'url' => $p['url'], 'img' => img($p['photos'][0] ?? null)['s'] ?? '', 'material' => $material, 'color' => $color, 'price' => variant_price($p, $material)];
    return esc(js_json(array_merge($o, $extra)));
}
function product_index(array $p): int
{
    foreach (products() as $i => $x) if ($x['id'] === $p['id']) return $i;
    return -1;
}
function product_card(array $p, array $o = []): string
{
    $sale = $o['sale'] ?? false;
    $h = $o['h'] ?? 'h3';
    $s = $sale ? best_sample($p) : null;
    $mat = $s ? $s['material'] : 'rogozhka';
    $price = $s ? ($s['sample_price'] ?? null) : variant_price($p, $mat);
    $all = product_colors();
    $colors = array_slice($all, 0, 8);
    $badges = [];
    if ($p['mechanism'] !== 'none') $badges[] = '<span class="pc-ico" title="Механизм ' . esc($p['mechanism_name']) . '">' . ICON['mech'] . '</span>';
    if ($p['has_storage']) $badges[] = '<span class="pc-ico" title="Ящик для белья">' . ICON['box'] . '</span>';
    if ($p['category'] === 'kresla') $sub = 'Кресла и пуфы';
    else {
        $cn = CATEGORY()[$p['category']]['name'] ?? null;
        $cn = $cn !== null ? replace_first(' диваны', '', replace_first(' диваны и системы', '', $cn)) : null;
        $sub = implode(' · ', array_filter([$cn, $p['mechanism'] !== 'none' ? $p['mechanism_name'] : 'без механизма'], 'truthy'));
    }
    $hex = COLOR_HEX(); $cname = COLOR_NAME();
    $pu = u($p['url']);
    $priceHtml = $s ? '<b class="is-sale">' . rub($price) . '</b> <s>' . rub($s['old_price'] ?? null) . '</s>' : (truthy($p['price_from']) ? 'от <b>' . rub($price) . '</b>' : '<b>цена по запросу</b>');
    $dots = implode('', array_map(fn($c) => '<span style="background:' . $hex[$c] . '" title="' . esc($cname[$c] ?? '') . '"></span>', $colors));
    $payload = cart_payload($p, $mat, $s['color'] ?? null, $s ? ['sample' => $s['showroom']] : []);
    return '<article class="pc" data-cat="' . $p['category'] . '" data-mech="' . $p['mechanism'] . '" data-len="' . (truthy($p['length_cm']) ? jsstr($p['length_cm']) : '') . '" data-price="' . (truthy($price) ? jsstr($price) : '') . '" data-stock="' . ($p['in_stock'] ? 1 : 0) . '" data-sleep="' . ($p['has_sleeping_place'] ? 1 : 0) . '" data-hit="' . ($p['is_hit'] ? 1 : 0) . '" data-sort="' . product_index($p) . '">
  <a class="pc-photo" href="' . $pu . '" tabindex="-1" aria-hidden="true">' . picture($p['photos'][0] ?? null, $p['h1'], ['sizes' => '(min-width: 1100px) 380px, (min-width: 700px) 45vw, 92vw']) . '
    ' . ($p['in_stock'] ? '<span class="pc-stock" title="Есть в наличии">' . ICON['stock'] . '</span>' : '') . '
    <span class="pc-icons">' . implode('', $badges) . '</span>
    ' . ($s ? '<span class="pc-sale">−' . jsstr($s['discount_pct']) . '%</span>' : '') . '
  </a>
  <div class="pc-body">
    <' . $h . ' class="pc-name"><a href="' . $pu . '">' . esc($p['name']) . '</a></' . $h . '>
    <p class="pc-sub">' . esc($sub) . '</p>
    <p class="pc-price">' . $priceHtml . '</p>
    <p class="pc-dims">' . size_str($p) . ($p['sleeping'] ? ' · спальное ' . jsstr($p['sleeping']['w']) . '×' . jsstr($p['sleeping']['l']) : '') . '</p>
    <p class="pc-dots" aria-label="Доступные цвета">' . $dots . '<em>+' . (count($all) - count($colors)) . '</em></p>
    <p class="pc-actions"><a class="btn btn-sm btn-line" href="' . $pu . '">Подробнее</a><button type="button" class="btn btn-sm btn-accent" data-add="' . $payload . '">В заявку</button></p>
  </div>
</article>';
}

function products_grid($rule = 'all', $limit = null): string
{
    $list = select_products($rule, $limit);
    $n = count($list);
    if (truthy($limit) && jsnumber($limit) <= 9 && $n > 3 && $n % 3) $list = array_slice($list, 0, $n - ($n % 3));
    $sale = truthy(parse_rule($rule)['sample'] ?? null);
    if (!$list) return '<div class="empty"><p class="empty-t">Товары временно отсутствуют</p><p>Посмотрите другие разделы: <a href="' . u('/catalog/pryamye-divany/') . '">прямые</a>, <a href="' . u('/catalog/uglovye-divany/') . '">угловые</a>, <a href="' . u('/catalog/modulnye-divany/') . '">модульные диваны</a>.</p></div>';
    return '<div class="pgrid" data-grid>' . implode('', array_map(fn($p) => product_card($p, ['sale' => $sale]), $list)) . '</div>';
}

const TILE_BG = ['#7C7471', '#98A0B0', '#A7C7CB', '#7B6D5E', '#22506A', '#4E5563', '#756463', '#667F63', '#626262'];
function rule_str($r): string
{
    $out = [];
    // как Object.entries в JS: у строки правила ("all") — по символам
    if (is_string($r)) $r = mb_str_split($r);
    foreach ($r as $k => $v) $out[] = "$k=" . jsstr($v);
    return implode(',', $out);
}
function page_rule(string $url)
{
    $p = by_url()[$url] ?? null;
    $r = $p['data']['listing']['rule'] ?? null;
    if (!truthy($r)) return null;
    return rule_str($r instanceof stdClass ? [] : $r);
}
function find_hero(array $data, string $slot = 'hero')
{
    foreach ($data['images'] ?? [] as $x) if (($x['slot'] ?? null) === $slot && truthy($x['src'] ?? null)) return $x['src'];
    return null;
}
function tiles(array $items = [], array $o = []): string
{
    $big = $o['big'] ?? false;
    $list = array_values(array_filter($items, fn($x) => truthy($x) && strpos($x, '{') === false));
    $prods = products();
    $html = '';
    foreach ($list as $i => $url) {
        $page = by_url()[$url] ?? null;
        $rule = page_rule($url);
        $sel = $rule !== null ? select_products($rule) : [];
        $photo = ($page ? find_hero($page['data']) : null) ?: (($sel[0]['photos'][0] ?? null) ?: ($prods[$i % count($prods)]['photos'][0] ?? null));
        $count = $rule !== null ? count($sel) : null;
        $sale = $url === '/sale/';
        $t = title_of($url);
        $html .= '<a class="tile" href="' . u($url) . '" style="--tile:' . TILE_BG[$i % count(TILE_BG)] . '">
      ' . ($count ? '<span class="tile-n">' . $count . ' шт.</span>' : '') . '
      <span class="tile-img">' . picture($photo, '', ['sizes' => '(min-width: 900px) 40vw, 92vw']) . '</span>
      <span class="tile-t">' . esc($t) . '</span>
      ' . ($sale ? '<span class="tile-sale">до −30%</span>' : '') . '
    </a>';
    }
    return '<div class="tiles' . ($big ? ' tiles-big' : '') . '" data-count="' . count($list) . '">' . $html . '</div>';
}
function chips(array $items = []): string
{
    $h = '';
    foreach ($items as $url) if (strpos($url, '{') === false) $h .= '<a class="chip" href="' . u($url) . '">' . esc(title_of($url)) . '</a>';
    return '<nav class="chips" aria-label="Подборки">' . $h . '</nav>';
}

function ymap_point(array $s): string { return 'https://yandex.ru/maps/?pt=' . jsstr($s['lon']) . ',' . jsstr($s['lat']) . '&z=16&l=map'; }
function showrooms_html($mode = 'cards'): string
{
    $h = '';
    foreach (showrooms() as $s) {
        $map = truthy($s['lat'] ?? null) ? ymap_point($s) : 'https://yandex.ru/maps/?text=' . encodeURIComponent((string)($s['address'] ?? ''));
        $h .= '<article class="show" id="' . $s['slug'] . '">
    <h3 class="show-t">' . esc($s['name']) . (truthy($s['is_main'] ?? null) ? ' <span class="tag">главный</span>' : '') . '</h3>
    <p class="show-a">' . esc($s['address'] ?? '') . '</p>
    <p class="show-h">Ежедневно ' . esc($s['hours'] ?? '') . '</p>
    <p class="show-p"><a href="' . phone_href($s['phone'] ?? '') . '">' . esc($s['phone'] ?? '') . '</a></p>
    <p class="show-m"><a href="' . $map . '" target="_blank" rel="noopener">Открыть на Яндекс.Картах</a></p>
  </article>';
    }
    return '<div class="shows shows-' . ($mode === 'list' ? 'list' : 'cards') . '">' . $h . '</div>';
}
function map_html(): string
{
    $pt = [];
    foreach (showrooms() as $i => $s) $pt[] = jsstr($s['lon']) . ',' . jsstr($s['lat']) . ',pm2rdl' . ($i + 1);
    $src = 'https://yandex.ru/map-widget/v1/?ll=37.55,55.75&z=9&pt=' . str_replace(['%2C', '%7E'], [',', '~'], encodeURIComponent(implode('~', $pt)));
    $leg = '';
    foreach (showrooms() as $s) $leg .= '<li><a href="' . ymap_point($s) . '" target="_blank" rel="noopener"><b>' . esc($s['name']) . '</b><span>' . esc($s['address'] ?? '') . '</span></a></li>';
    return '<figure class="map ymap">
    <div class="ymap-box"><iframe src="' . $src . '" title="Шоурумы SIMMETRIKA на Яндекс.Карте" loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade"></iframe></div>
    <ol class="ymap-legend">' . $leg . '</ol>
  </figure>';
}

function swatches($materials = 'velyur,rogozhka,zhakkard'): string
{
    $M = MATERIAL(); $hex = COLOR_HEX(); $cn = COLOR_NAME();
    $rows = '';
    foreach (explode(',', $materials) as $m) {
        $f = $M[js_trim($m)] ?? null;
        if (!$f) continue;
        $dots = '';
        foreach ($f['colors'] ?? [] as $c) {
            $h = color_href($c);
            $a = 'class="sw" style="--sw:' . ($hex[$c] ?? 'undefined') . '" title="' . esc($cn[$c] ?? '') . '"><span class="sr">' . esc($cn[$c] ?? '') . '</span>';
            $dots .= $h !== '' ? '<a href="' . $h . '" ' . $a . '</a>' : '<span ' . $a . '</span>';
        }
        $rows .= '<div class="sw-row">
    <p class="sw-name"><b>' . esc($f['name']) . '</b> ' . esc($f['props'] ?? '') . '</p>
    <p class="sw-dots">' . $dots . '</p>
  </div>';
    }
    return '<div class="sw-wrap">' . $rows . '</div>';
}

function spec_cols(): array
{
    return [
        'name' => ['Модель', fn($p) => '<a href="' . u($p['url']) . '">' . esc($p['h1']) . '</a>'],
        'category' => ['Категория', fn($p) => esc(CATEGORY()[$p['category']]['name'] ?? '')],
        'size' => ['Д×Г×В', fn($p) => size_str($p)],
        'sleeping' => ['Спальное место', fn($p) => sleep_str($p)],
        'mechanism' => ['Механизм', fn($p) => esc($p['mechanism_name'])],
        'side' => ['Угол', fn($p) => $p['sides'] ? 'левый / правый' : '—'],
        'price' => ['Цена от', fn($p) => truthy($p['price_from']) ? rub(variant_price($p)) : 'по запросу'],
    ];
}
function spec_table($rule = 'all', $cols = 'name,size,sleeping,mechanism,price'): string
{
    $COLS = spec_cols();
    $c = array_values(array_filter(array_map('js_trim', explode(',', $cols)), fn($x) => isset($COLS[$x])));
    $list = select_products($rule);
    $th = implode('', array_map(fn($k) => '<th>' . $COLS[$k][0] . '</th>', $c));
    $rows = '';
    foreach ($list as $p) $rows .= '<tr>' . implode('', array_map(fn($k) => '<td data-l="' . $COLS[$k][0] . '">' . $COLS[$k][1]($p) . '</td>', $c)) . '</tr>';
    return '<div class="tbl"><table class="spec"><thead><tr>' . $th . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
}

function filters_html($set = 'price,length,mechanism,stock'): string
{
    $f = array_map('js_trim', explode(',', $set));
    $opt = function ($name, $label, $opts) {
        return '<label class="flt"><span class="sr">' . $label . '</span><select data-f="' . $name . '" aria-label="' . $label . '"><option value="">' . $label . '</option>' . implode('', array_map(fn($o) => '<option value="' . $o[0] . '">' . $o[1] . '</option>', $opts)) . '</select></label>';
    };
    $parts = [];
    if (in_array('category', $f, true) || in_array('type', $f, true)) $parts[] = $opt('cat', 'Тип', array_map(fn($c) => [$c['slug'], $c['name']], array_values(CATEGORY())));
    if (in_array('mechanism', $f, true) || in_array('sleeping', $f, true)) $parts[] = $opt('mech', 'Механизм', [['pantograf', 'Пантограф'], ['venetsiya', 'Венеция'], ['vykatnoy', 'Выкатной'], ['none', 'Без механизма']]);
    if (in_array('price', $f, true)) $parts[] = $opt('price', 'Цена', [['0-100000', 'до 100 000 ₽'], ['100000-160000', '100–160 тыс. ₽'], ['160000-999999', 'от 160 000 ₽']]);
    if (in_array('length', $f, true)) $parts[] = $opt('len', 'Длина', [['0-220', 'до 220 см'], ['220-260', '220–260 см'], ['260-999', 'от 260 см']]);
    if (in_array('stock', $f, true)) $parts[] = '<label class="flt flt-check"><input type="checkbox" data-f="stock"> В наличии</label>';
    $parts[] = '<label class="flt"><span class="sr">Сортировка</span><select data-sort aria-label="Сортировка"><option value="pop">По популярности</option><option value="asc">Сначала дешевле</option><option value="desc">Сначала дороже</option></select></label>';
    return '<div class="filters" data-filters role="search" aria-label="Фильтры">' . implode('', $parts) . '<button type="button" class="btn btn-sm btn-line" data-reset>Сбросить</button><span class="flt-n" data-found aria-live="polite"></span></div>';
}

const FORM_TITLE = ['question' => 'Задать вопрос фабрике', 'callback' => 'Обратный звонок', 'custom_size' => 'Рассчитать диван по вашим размерам', 'sample_reserve' => 'Забронировать образец', 'dealer' => 'Заявка для дизайнеров и дилеров'];
function form_html($type = 'question', array $o = []): string
{
    $title = $o['title'] ?? null; $id = $o['id'] ?? null; $lead = $o['lead'] ?? null;
    $f = fn($name, $label, $attrs = '') => '<label class="fld"><span>' . $label . '</span><input name="' . $name . '" ' . $attrs . '></label>';
    $extra = '';
    if ($type === 'custom_size') {
        $extra = '<div class="fld-row">' . $f('length', 'Длина, см', 'inputmode="numeric"') . $f('depth', 'Глубина, см', 'inputmode="numeric"') . '<label class="fld"><span>Модель</span><select name="model"><option value="">Любая</option>' . implode('', array_map(fn($p) => '<option>' . esc($p['h1']) . '</option>', products())) . '</select></label></div>';
    } elseif ($type === 'sample_reserve') {
        $opts = '';
        foreach (products() as $p) if (!empty($p['samples'])) $opts .= '<option>' . esc($p['h1']) . ', ' . esc(SHOWROOM()[$p['samples'][0]['showroom']]['name'] ?? '') . '</option>';
        $extra = '<label class="fld"><span>Образец</span><select name="sample">' . $opts . '</select></label>';
    } elseif ($type === 'dealer') {
        $extra = $f('company', 'Компания или студия');
    }
    $phone = $f('phone', 'Телефон *', 'type="tel" required autocomplete="tel" data-mask placeholder="+7 (___) ___-__-__"');
    $fields = $type === 'callback'
        ? '<div class="fld-row">' . $f('name', 'Имя', 'autocomplete="name"') . $phone . '</div>'
        : '<div class="fld-row">' . $f('name', 'Имя', 'autocomplete="name"') . $phone . $f('email', 'Email', 'type="email" autocomplete="email"') . '</div>' . $extra . '<label class="fld"><span>Комментарий</span><textarea name="comment" rows="3"></textarea></label>';
    $promise = (string)(settings()['callback_promise'] ?? '');
    return '<form class="lead-form" data-form="' . $type . '" ' . (truthy($id) ? 'id="' . esc($id) . '"' : '') . ' novalidate>
    <p class="lf-t">' . esc(truthy($title) ? $title : (FORM_TITLE[$type] ?? 'Оставить заявку')) . '</p>
    ' . (truthy($lead) ? '<p class="lf-l">' . esc($lead) . '</p>' : '') . '
    ' . $fields . '
    <label class="fld-check"><input type="checkbox" name="consent" required> Согласен на обработку персональных данных по <a href="' . u('/privacy/#soglasie') . '">политике конфиденциальности</a></label>
    <p class="lf-err" data-err role="alert"></p>
    <button class="btn btn-accent" type="submit">Отправить</button>
    <p class="lf-ok" data-ok hidden>Спасибо, заявка принята. ' . esc(str_ucfirst($promise)) . '.</p>
  </form>';
}

function news_items($type = 'all'): array
{
    $items = array_values(array_filter(load_pages(), fn($p) => ($p['data']['type'] ?? null) === 'news-item' && ($type === 'all' || ($p['data']['news_type'] ?? null) === $type)));
    usort($items, fn($a, $b) => strcmp(jsstr($b['data']['date'] ?? 'undefined'), jsstr($a['data']['date'] ?? 'undefined')));
    return $items;
}
function news_html($limit = 3, $type = 'all'): string
{
    $items = array_slice(news_items($type), 0, (int)jsnumber($limit));
    $h = '';
    foreach ($items as $p) {
        $d = $p['data'];
        $h .= '<a class="news-i" href="' . u($d['url']) . '">
    <span class="news-img">' . picture($d['cover'] ?? null, truthy($d['cover_alt'] ?? null) ? $d['cover_alt'] : '', ['sizes' => '(min-width: 900px) 30vw, 92vw']) . '</span>
    <span class="news-meta">' . (($d['news_type'] ?? null) === 'promo' ? '<b>Акция</b> · ' : '') . ru_date_long(jsstr($d['date'] ?? '')) . '</span>
    <span class="news-t">' . esc($d['h1'] ?? '') . '</span><span class="news-a">' . esc(truthy($d['announce'] ?? null) ? $d['announce'] : '') . '</span></a>';
    }
    return '<div class="news">' . $h . '</div>';
}
function promo_html(): string
{
    $h = '';
    foreach (load_pages() as $p) {
        $d = $p['data'];
        if (($d['type'] ?? null) !== 'news-item' || ($d['news_type'] ?? null) !== 'promo') continue;
        $h .= '<a class="promo-i" href="' . u($d['url']) . '"><span class="promo-t">' . esc($d['h1'] ?? '') . '</span><span class="promo-a">' . esc(truthy($d['announce'] ?? null) ? $d['announce'] : '') . '</span><span class="btn btn-sm btn-white">Подробнее</span></a>';
    }
    return '<div class="promo">' . $h . '</div>';
}

// ── схемы-инфографики ──
function bars_html(string $kind, array $ctx = []): string
{
    $sleep = (bool)preg_match('/sleeping/', $kind);
    $list = array_values(array_filter(products(), fn($p) => $p['category'] !== 'kresla' && ($sleep ? (bool)$p['sleeping'] : truthy($p['length_cm']))));
    $cat = $ctx['category'] ?? null;
    if (truthy($cat) && $cat !== 'kresla') {
        $has = false;
        foreach ($list as $p) if ($p['category'] === $cat) { $has = true; break; }
        if ($has) $list = array_values(array_filter($list, fn($p) => $p['category'] === $cat));
    }
    $rule = $ctx['rule'] ?? null;
    if (is_array($rule) && $rule) {
        $r = rule_str($rule);
        $f = array_values(array_filter($list, fn($p) => match_rule($p, $r)));
        if (count($f) >= 2) $list = $f;
    }
    $val = fn($p) => $sleep ? $p['sleeping']['l'] : $p['length_cm'];
    usort($list, fn($a, $b) => js_cmp_num($val($a) - $val($b)));
    $vals = array_map($val, $list);
    $max = $vals ? max($vals) : -INF;
    $min = $vals ? min($vals) : INF;
    $cats = array_values(array_unique(array_map(fn($p) => $p['category'], $list)));
    $unit = $sleep ? 'длина спального места, см' : 'длина дивана, см';
    $bars = '';
    foreach ($list as $p) {
        $t = $p['category'] === 'uglovye-divany' ? 'угловой' : ($p['category'] === 'modulnye-divany' ? 'модульный' : 'прямой');
        $w = tofixed1(max(8, ($val($p) / $max) * 100));
        $bars .= '<a class="bar bar-' . $p['category'] . '" href="' . u($p['url']) . '"><span class="bar-n">' . esc($p['name']) . ' <i>' . $t . '</i></span><span class="bar-t"><span class="bar-f" style="width:' . $w . '%"></span></span><b class="bar-v">' . jsstr($val($p)) . ($sleep ? ' <i>×' . jsstr($p['sleeping']['w']) . '</i>' : '') . '</b></a>';
    }
    $leg = count($cats) > 1 ? '<p class="bars-leg">' . implode('', array_map(fn($c) => '<span class="bar-' . $c . '"><i></i>' . esc(CATEGORY()[$c]['name']) . '</span>', $cats)) . '</p>' : '';
    return '<div class="bars" role="img" aria-label="' . esc($unit) . '">
    <p class="bars-cap">' . esc(str_ucfirst($unit)) . ': от ' . jsstr($min) . ' до ' . jsstr($max) . '</p>
    ' . $bars . '
    ' . $leg . '
  </div>';
}
function palette_html($active): string
{
    $h = '';
    $cn = COLOR_NAME();
    foreach (COLOR_HEX() as $k => $v) {
        $k = (string)$k;
        $href = color_href($k);
        $t = $href !== '' ? 'a' : 'span';
        $fabs = [];
        foreach (fabrics() as $f) if (in_array($k, $f['colors'] ?? [], true)) $fabs[] = mb_strtolower($f['name']);
        $h .= '<' . $t . ' class="pal-i ' . ($k === $active ? 'is-on' : '') . '"' . ($href !== '' ? ' href="' . $href . '"' : '') . '><span style="background:' . $v . '"></span><b>' . esc($cn[$k] ?? '') . '</b><i>' . esc(implode(', ', $fabs)) . '</i></' . $t . '>';
    }
    return '<div class="pal">' . $h . '</div>';
}
function svg_lbl($x, $y, $t, $a = 'middle', $sz = 15): string
{
    return '<text x="' . jsstr($x) . '" y="' . jsstr($y) . '" text-anchor="' . $a . '" font-size="' . $sz . '" fill="currentColor">' . esc($t) . '</text>';
}
function map_wide(bool $withZone = false): string
{
    $X = fn($lon) => 450 + ($lon - 37.62) * 1100;
    $Y = fn($lat) => 175 - ($lat - 55.75) * 760;
    $cx = 450; $cy = 175; $rx = 242; $ry = 129;
    $zone = $withZone ? '<ellipse cx="' . $cx . '" cy="' . $cy . '" rx="420" ry="162" fill="rgba(196,0,5,.04)" stroke="var(--c-accent)" stroke-width="1.5" stroke-dasharray="6 6"/>' . svg_lbl(890, 26, 'Московская область до 60 км от МКАД', 'end', 13) . svg_lbl(890, 44, '1 500 ₽ + 40 ₽ за км', 'end', 13) : '';
    $pts = '';
    foreach (showrooms() as $i => $s) {
        $x = $X($s['lon']); $y = $Y($s['lat']); $right = $x > 450;
        $name = preg_replace('/, .*/u', '', preg_replace('/[«»]/u', '', $s['name']), 1);
        $pts .= '<g><circle cx="' . jsstr($x) . '" cy="' . jsstr($y) . '" r="10" fill="var(--c-accent)"/><text x="' . jsstr($x) . '" y="' . jsstr($y + 4) . '" text-anchor="middle" font-size="11" font-weight="700" fill="#fff">' . ($i + 1) . '</text>' . svg_lbl($x + ($right ? 16 : -16), $y + 5, $name, $right ? 'start' : 'end', 13) . '</g>';
    }
    return '<svg viewBox="0 0 900 350" role="img" aria-label="Шоурумы SIMMETRIKA относительно МКАД">
    ' . $zone . '
    <ellipse cx="' . $cx . '" cy="' . $cy . '" rx="' . $rx . '" ry="' . $ry . '" fill="' . ($withZone ? 'rgba(196,0,5,.07)' : 'rgba(0,0,0,.03)') . '" stroke="currentColor" stroke-width="2" opacity=".7"/>
    ' . svg_lbl($cx, $cy - 6, $withZone ? 'В пределах МКАД' : 'Москва', 'middle', 14) . ($withZone ? svg_lbl($cx, $cy + 14, '1 500 ₽', 'middle', 14) : svg_lbl($cx, $cy + 14, 'МКАД', 'middle', 13)) . '
    ' . $pts . '
  </svg>';
}
const C0 = 'currentColor';
function sofa0($x, $y, $w, $d, $fill = 'var(--c-photo)'): string
{
    return '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $d . '" fill="' . $fill . '" stroke="' . C0 . '" stroke-width="1.5"/>';
}
const MECH_OLD = [['pantograf', 'Пантограф'], ['venetsiya', 'Венеция'], ['vykatnoy', 'Выкатной']];
function mech_old($active): string
{
    $one = fn($x, $label, $dim) => '<g transform="translate(' . $x . ',0)" ' . ($dim ? 'opacity=".3"' : '') . '>' . sofa0(10, 40, 120, 30) . sofa0(10, 20, 120, 20, 'none') . '<rect x="10" y="70" width="120" height="14" fill="var(--c-accent)" opacity=".18" stroke="var(--c-accent)"/><text x="70" y="110" text-anchor="middle" font-size="12" fill="' . C0 . '"' . ($dim ? '' : ' font-weight="700"') . '>' . $label . '</text></g>';
    $h = '';
    foreach (MECH_OLD as $i => [$k, $t]) $h .= $one($i * 150, $t, truthy($active) && $active !== $k);
    return '<svg class="sofa-old" viewBox="0 0 450 120" role="img" aria-label="Схема механизмов трансформации">' . $h . '</svg>';
}
function scheme_svg($kind = '', array $attrs = [], array $ctx = []): string
{
    $kind = (string)$kind;
    if (preg_match('/scale|ruler|sizes|sleeping/', $kind)) return bars_html($kind, $ctx);
    if ($kind === 'color-palette') return palette_html($attrs['active'] ?? null);
    if ($kind === 'mechanisms' || $kind === 'mechanism-all') return mech_old(null);
    if (strpos($kind, 'mechanism-') === 0) return mech_old(explode('-', $kind)[1]);
    if ($kind === 'corner-side') return '<svg class="sofa-old" viewBox="0 0 360 140" role="img" aria-label="Левый и правый угол, вид стоя лицом к дивану"><g>' . sofa0(20, 30, 130, 30) . sofa0(20, 60, 40, 60) . '<text x="85" y="135" text-anchor="middle" font-size="12" fill="' . C0 . '">Левый угол</text></g><g>' . sofa0(210, 30, 130, 30) . sofa0(300, 60, 40, 60) . '<text x="275" y="135" text-anchor="middle" font-size="12" fill="' . C0 . '">Правый угол</text></g></svg>';
    if ($kind === 'modules') return '<svg class="sofa-old" viewBox="0 0 400 130" role="img" aria-label="Модули модульной системы">' . sofa0(10, 20, 80, 50) . sofa0(95, 20, 100, 50) . sofa0(200, 20, 50, 50) . sofa0(200, 70, 50, 50) . sofa0(300, 40, 80, 80) . '<g font-size="11" fill="' . C0 . '"><text x="50" y="90" text-anchor="middle">секция</text><text x="145" y="90" text-anchor="middle">секция</text><text x="225" y="128" text-anchor="middle">угол</text><text x="340" y="128" text-anchor="middle">оттоманка</text></g></svg>';
    if ($kind === 'custom-dimensions' || $kind === 'room-layout') return '<svg class="sofa-old" viewBox="0 0 430 150" role="img" aria-label="Размеры, которые можно изменить">' . sofa0(60, 40, 260, 60) . '<path d="M60 120h260M60 115v10M320 115v10" stroke="var(--c-accent)" stroke-width="2"/><text x="190" y="140" text-anchor="middle" font-size="12" fill="' . C0 . '">длина ±30 см</text><path d="M340 40v60M335 40h10M335 100h10" stroke="var(--c-accent)" stroke-width="2"/><text x="350" y="75" font-size="12" fill="' . C0 . '">глубина ±10</text></svg>';
    if ($kind === 'delivery-zones') return '<div class="wide-map">' . map_wide(true) . '</div>';
    if ($kind === 'showrooms-mkad') return '<div class="wide-map">' . map_wide(false) . '</div>';
    return '';
}
