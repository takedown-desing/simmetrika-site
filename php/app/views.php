<?php
// Шаблоны страниц (порт src/pages/index.astro, 404.astro, [...slug].astro).

// ── главная ──
const HOME_ICONS = [
    'straight' => '<svg viewBox="0 0 100 60"><path d="M10 26h80v18H10zM14 26V14h72v12M14 44v6M86 44v6M10 30H4v14h6M90 30h6v14h-6" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>',
    'corner' => '<svg viewBox="0 0 100 60"><path d="M8 24h60v16H8zM68 24h24v30H68zM12 24V14h76v10M8 40v8M92 54v4" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>',
    'modular' => '<svg viewBox="0 0 100 60"><path d="M6 24h28v18H6zM36 24h28v18H36zM66 24h28v18H66zM8 24V14h24v10M38 24V14h24v10M68 24V14h24v10" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>',
    'bed' => '<svg viewBox="0 0 100 60"><path d="M10 30h80v14H10zM10 30V16h80v14M16 44v6M84 44v6M20 30l8-10h44l8 10" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>',
    'sale' => '<svg viewBox="0 0 100 60"><circle cx="50" cy="30" r="22" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M40 40l20-20" stroke="currentColor" stroke-width="1.6"/><circle cx="41" cy="22" r="3" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="59" cy="38" r="3" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>',
    'ruler' => '<svg viewBox="0 0 100 60"><path d="M8 22h84v16H8zM18 22v7M28 22v5M38 22v7M48 22v5M58 22v7M68 22v5M78 22v7" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>',
];
const HOME_CATS = [
    ['Прямые диваны', '/catalog/pryamye-divany/', 'straight'], ['Угловые диваны', '/catalog/uglovye-divany/', 'corner'],
    ['Модульные системы', '/catalog/modulnye-divany/', 'modular'], ['Диваны-кровати', '/catalog/divany-krovati/', 'bed'],
    ['Распродажа образцов', '/sale/', 'sale'], ['Диваны на заказ', '/na-zakaz/', 'ruler'],
];
const CAT4 = ['/catalog/pryamye-divany/', '/catalog/uglovye-divany/', '/catalog/modulnye-divany/', '/catalog/divany-krovati/'];

function find_image(array $d, string $slot)
{
    foreach ($d['images'] ?? [] as $x) if (is_array($x) && ($x['slot'] ?? null) === $slot) return $x;
    return null;
}

function view_home(): ?string
{
    $page = by_url()['/'] ?? null;
    if (!$page) return null;
    $d = $page['data'];
    $ss = split_sections(render_md($page['body']));
    $hero = find_image($d, 'tile-pryamye') ?: find_image($d, 'hero');
    $kind = function ($t0) {
        $t = preg_replace('/\x{00a0}|&nbsp;|&#xA0;|&#160;/iu', ' ', $t0);
        if (preg_match('/^Каталог/u', $t)) return 'catalog';
        if (preg_match('/Распродажа/u', $t)) return 'panel';
        if (preg_match('/Почему|что производим/u', $t)) return 'cards';
        if (preg_match('/под ваш размер/u', $t)) return 'dark';
        if (preg_match('/шоурум/iu', $t)) return 'panel';
        if (preg_match('/Задать вопрос/u', $t)) return 'panel';
        return 'plain';
    };
    $body = fn($s) => preg_replace('/<h2[^>]*>[\s\S]*?<\/h2>/', '', inner_sec($s['html']), 1);
    $lead = fn($h) => preg_match('/^\s*(<p>[\s\S]*?<\/p>)/', $h, $m) ? [$m[1], substr($h, strlen($m[0]))] : ['', $h];

    $c = '<section class="hero">
    <div class="hero-img">' . ($hero ? picture($hero['src'] ?? null, $hero['alt'] ?? '', ['eager' => true, 'sizes' => '100vw']) : '') . '</div>
    <div class="wrap hero-in">
      <h1>' . h($d['h1'] ?? '') . '</h1>
      <p class="hero-lede">' . h($d['lede'] ?? '') . '</p>
      <div class="hero-btns"><a class="btn btn-accent" href="' . u('/catalog/') . '">Выбрать диван</a><button class="btn btn-white" type="button" data-callback>Обратный звонок</button></div>
      <p class="hero-note">Заявка на сайте без оплаты: менеджер перезвонит и согласует ткань, цену и срок</p>
    </div>
  </section>
  <nav class="cats" aria-label="Разделы каталога"><div class="wrap cats-in">';
    foreach (HOME_CATS as [$t, $url, $ic]) $c .= '<a class="cat" href="' . u($url) . '"><span>' . h($t) . '</span>' . HOME_ICONS[$ic] . '</a>';
    $c .= '</div></nav>';
    if ($ss['intro'] !== '') $c .= '<div class="band"><div class="wrap home-sec"><div class="sec-lead">' . $ss['intro'] . '</div></div></div>';
    foreach ($ss['sections'] as $s) {
        $k = $kind($s['title']);
        [$l, $rest] = $lead($body($s));
        // заголовок из готового HTML: в Astro {s.title} экранируется повторно, здесь сущности уже на месте
        $t = $s['title'];
        if ($k === 'dark') { $c .= '<section class="dark"><div class="wrap home-sec"><h2>' . $t . '</h2><div class="sec-body">' . $l . $rest . '</div></div></section>'; continue; }
        if ($k === 'catalog') { $c .= '<section class="band"><div class="wrap home-sec"><h2 class="sec-h">' . $t . '</h2><div class="sec-lead">' . $l . '</div>' . tiles(CAT4) . '<div class="sec-body prose">' . cardify($rest, true) . '</div></div></section>'; continue; }
        $c .= '<section class="band ' . ($k === 'panel' ? 'band-panel' : '') . '"><div class="wrap home-sec prose"><h2 class="sec-h">' . $t . '</h2><div class="sec-lead">' . $l . '</div><div class="sec-body">' . ($k === 'cards' ? cardify($rest, true) : $rest) . '</div></div></section>';
    }
    $c .= '<div class="wrap facts">';
    foreach ($d['highlights'] ?? [] as $x) $c .= '<div class="fact"><span>' . h($x['label'] ?? '') . '</span><b>' . h($x['value'] ?? '') . '</b></div>';
    $c .= '</div>' . faq_html($d['faq'] ?? null, $d['faq_title'] ?? null) . related_html($d['related'] ?? null);
    return base_layout(['data' => $d, 'schema' => schema_graph($page, ['faq' => $d['faq'] ?? null, 'type' => 'home']), 'home' => true, 'og_image' => $hero['src'] ?? null], $c);
}

// ── 404 ──
function view_404(): string
{
    $p = by_id()['404'] ?? null;
    $d = $p['data'] ?? ['h1' => 'Страница не найдена', 'title' => 'Страница не найдена | SIMMETRIKA'];
    $lead = 'Такой страницы нет или она переехала.';
    foreach (explode("\n", $p['body'] ?? '') as $l) {
        if (js_trim($l) !== '' && strpos($l, ':::') !== 0 && strpos($l, '#') !== 0) { $lead = $l; break; }
    }
    $lead = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $lead);
    $s = settings();
    unset($d['url']);
    $c = '<div class="phead"><div class="wrap"><h1>' . h($d['h1'] ?? '') . '</h1><p class="lede">' . h($lead) . '</p>
    <p class="phead-btns"><a class="btn btn-accent" href="' . u('/catalog/') . '">Перейти в каталог</a><a class="btn btn-line" href="' . h(tel_href($s['phone_main'])) . '">' . h($s['phone_main']) . '</a></p></div></div>
  <div class="wrap prose" style="padding-bottom:64px">' . tiles(array_merge(CAT4, ['/sale/'])) . form_html('callback') . '</div>';
    return base_layout(['data' => $d, 'noindex' => true], $c);
}

// ── страницы из админки ──
const IC = [
    'size' => '<svg viewBox="0 0 24 24"><path d="M3 9h18v8H3zM3 13h18M7 9V6h10v3" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>',
    'bed' => '<svg viewBox="0 0 24 24"><path d="M3 18V8M3 14h18v4M21 18v-4M7 11h4" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>',
    'mech' => '<svg viewBox="0 0 24 24"><path d="M4 16h16M5 16c0-3 1-5 3-5h8c2 0 3 2 3 5M9 7l3-3 3 3" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>',
    'box' => '<svg viewBox="0 0 24 24"><path d="M4 8h16v11H4zM4 8l2-4h12l2 4M9 12h6" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>',
    'fabric' => '<svg viewBox="0 0 24 24"><circle cx="8" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="16" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>',
    'time' => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M12 7v5l3 2" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>',
    'shield' => '<svg viewBox="0 0 24 24"><path d="M12 3l7 3v6c0 4-3 7-7 9-4-2-7-5-7-9V6z" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>',
];

// адреса, по которым открываются страницы из админки (кроме главной, шаблонов и служебных)
function page_routes(): array
{
    $out = [];
    foreach (load_pages() as $p) {
        $url = $p['data']['url'] ?? null;
        if (!truthy($url) || $url === '/' || strpos(jsstr($url), '{') !== false || in_array($p['data']['type'] ?? null, ['ui', 'error'], true)) continue;
        $out[$url] = $p;
    }
    return $out;
}

function auto_crumbs(array $d): array
{
    $out = [['name' => 'Главная', 'url' => '/']];
    $parts = array_values(array_filter(explode('/', jsstr($d['url'] ?? '')), fn($x) => $x !== ''));
    for ($i = 1; $i < count($parts); $i++) {
        $pre = '/' . implode('/', array_slice($parts, 0, $i)) . '/';
        if (isset(by_url()[$pre])) $out[] = ['name' => title_of($pre), 'url' => $pre];
    }
    $out[] = ['name' => $d['h1'] ?? '', 'url' => $d['url'] ?? ''];
    return $out;
}

function view_page(array $page): string
{
    $d = $page['data'];
    $type = $d['type'] ?? null;
    $isProduct = $type === 'product';
    $isCart = $type === 'cart';
    $p = null;
    if ($isProduct) foreach (products() as $x) if ($x['url'] === ($d['url'] ?? null)) { $p = $x; break; }
    $CAT = CATEGORY();
    $crumbs = $p
        ? [['name' => 'Главная', 'url' => '/'], ['name' => 'Каталог', 'url' => '/catalog/'], ['name' => $CAT[$p['category']]['name'], 'url' => $CAT[$p['category']]['url']], ['name' => $d['h1'], 'url' => $d['url']]]
        : (truthy($d['breadcrumbs'] ?? null) ? $d['breadcrumbs'] : auto_crumbs($d));
    $body = $page['body'] ?? '';

    $listingRule = $d['listing']['rule'] ?? null;
    $ruleStr = '';
    if (is_string($listingRule) ? $listingRule !== '' : (is_array($listingRule) && count($listingRule) > 0)) $ruleStr = rule_str($listingRule);
    $thin = in_array($type, ['filter', 'collection'], true) && $ruleStr !== '' && count(select_products($ruleStr)) < 3;
    $schema = $isCart ? null : schema_graph($page, ['faq' => $d['faq'] ?? null, 'breadcrumbs' => $crumbs, 'product' => $p, 'type' => $type]);
    $heroImg = find_image($d, 'hero');
    $og = (truthy($heroImg['src'] ?? null) ? $heroImg['src'] : null) ?: (truthy($d['cover'] ?? null) ? $d['cover'] : null) ?: ($p['photos'][0] ?? null);
    $layout = ['data' => $d, 'schema' => $schema, 'og_image' => $og, 'noindex' => $isCart || $thin, 'body_class' => $isProduct ? 'has-mbar' : ''];

    if ($isProduct && $p) return base_layout($layout, product_view($page, $p, $crumbs));
    if ($isCart) return base_layout($layout, ($d['page_id'] ?? null) === 'zayavka' ? cart_view($d) : thanks_view($d));

    $kidsHtml = '';
    if (in_array($type, ['category', 'filter', 'collection'], true) && truthy($d['url'] ?? null)) {
        $kids = [];
        $du = $d['url'];
        foreach (by_url() as $k => $x) {
            $k = (string)$k;
            if ($k === $du || strpos($k, $du) !== 0) continue;
            if (count(array_filter(explode('/', substr($k, strlen($du))), fn($s) => $s !== '')) !== 1) continue;
            if (!in_array($x['data']['type'] ?? null, ['filter', 'collection'], true)) continue;
            if (strpos($body, $k) !== false) continue;
            $kids[] = $k;
        }
        if ($kids) $kidsHtml = chips($kids);
    }
    $autoGrid = $ruleStr !== '' && !preg_match('/:::products/', $body) ? '<section class="sec">' . filters_html() . products_grid($ruleStr) . '</section>' : '';
    $newsGrid = '';
    if ($type === 'news-item') {
        $cards = '';
        foreach ($d['product_ids'] ?? [] as $id) foreach (products() as $x) if ($x['id'] === $id) { $cards .= product_card($x); break; }
        if ($cards !== '') $newsGrid = '<section class="sec"><h2>Товары по теме</h2><div class="pgrid">' . $cards . '</div></section>';
    }
    $ctx = ['category' => is_array($listingRule) ? ($listingRule['category'] ?? null) : null, 'rule' => $listingRule];
    $bodyHtml = $kidsHtml . $autoGrid . cardify_sections(render_md($body, $ctx)) . $newsGrid;
    $c = page_head($d, $crumbs) . '<div class="wrap prose prose-wrap">' . $bodyHtml . '</div>' . faq_html($d['faq'] ?? null, $d['faq_title'] ?? null) . related_html($d['related'] ?? null);
    return base_layout($layout, $c);
}

function product_view(array $page, array $p, array $crumbs): string
{
    $d = $page['data'];
    $s = settings();
    $dl = $s['delivery'];
    $CAT = CATEGORY();
    $MAT = MATERIAL();
    $CN = COLOR_NAME();
    $CH = COLOR_HEX();
    $fab = array_values($MAT);
    $photos = $p['photos'] ?? [];

    $raw = preg_replace('/^##\s+[^\n]*\n+/', '', (string)(truthy($d['description_md'] ?? null) ? $d['description_md'] : ($page['body'] ?? '')), 1);
    $html = render_md($raw);
    $i3 = preg_match('/<h3 id=/', $html, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : -1;
    $descIntro = $i3 < 0 ? $html : substr($html, 0, $i3);
    $descCards = $i3 < 0 ? '' : cardify(substr($html, $i3), true);
    $similar = array_slice(array_values(array_filter(select_products('category=' . $p['category']), fn($x) => $x['id'] !== $p['id'])), 0, 3);
    $family = array_values(array_filter(products(), fn($x) => ($x['model_family'] ?? null) === ($p['model_family'] ?? null) && $x['id'] !== $p['id']));

    $nf = count(fabrics());
    $nc = count(product_colors());
    $sleep = truthy($p['sleeping'] ?? null) ? jsstr($p['sleeping']['w']) . '×' . jsstr($p['sleeping']['l']) . ' см' : null;
    $keySpecs = [
        [IC['size'], 'Габариты, Д×Г×В', size_str($p)],
        [IC['bed'], 'Спальное место', $sleep ?? 'нет'],
        [IC['mech'], 'Механизм', $p['mechanism_name'] ?? ''],
    ];
    if ($p['category'] !== 'kresla') $keySpecs[] = [IC['box'], 'Ящик для белья', truthy($p['has_storage'] ?? null) ? 'есть' : 'нет'];
    $keySpecs[] = [IC['fabric'], 'Обивка', $nf . ' ' . plural($nf, 'ткань', 'ткани', 'тканей') . ', ' . $nc . ' ' . plural($nc, 'цвет', 'цвета', 'цветов')];
    $keySpecs[] = [IC['time'], 'Изготовление', truthy($p['in_stock'] ?? null) ? 'в наличии, ' . jsstr($dl['in_stock_days']) . ' дня' : jsstr($dl['made_to_order_days']) . ' дней'];
    $keySpecs[] = [IC['shield'], 'Гарантия', jsstr($p['warranty_months'] ?? '') . ' месяцев'];

    $k = [];
    foreach ($fab as $f) $k[$f['material']] = $f['price_k'] ?? null;
    $dp = ['id' => $p['id'], 'name' => $p['h1'], 'url' => $p['url'], 'base' => $p['price_from'] ?? null, 'k' => obj($k), 'img' => img($photos[0] ?? null)['s'] ?? '', 'sides' => truthy($p['sides'] ?? null)];
    $alt = truthy($d['alt_template'] ?? null) ? str_replace_first_n('{n}', '1', $d['alt_template']) : '';
    if ($alt === '') $alt = $p['h1'];

    $c = '<div class="wrap" style="padding-top:24px">' . crumbs_html($crumbs) . '</div>
    <div class="wrap pp" data-product="' . h(js_json($dp)) . '">
      <div class="gal">
        <div class="gal-main" data-zoom>' . (truthy($photos[0] ?? null) ? picture($photos[0], $alt, ['eager' => true, 'sizes' => '(min-width: 900px) 640px, 100vw']) : '') . '</div>';
    if (count($photos) > 1) {
        $c .= '<div class="gal-th" role="list">';
        foreach ($photos as $i => $ph) $c .= '<button type="button" role="listitem" data-thumb="' . h(u(img($ph)['l'] ?? '')) . '" aria-current="' . ($i === 0 ? 'true' : 'false') . '" aria-label="Фото ' . ($i + 1) . '">' . picture($ph, '', ['sizes' => '92px']) . '</button>';
        $c .= '</div>';
    }
    $sub = $p['category'] === 'kresla'
        ? size_str($p) . ' · обивка в тон дивану'
        : size_str($p) . ($sleep ? ', спальное место ' . $sleep : ', без спального места') . ' · ' . (($p['mechanism'] ?? null) !== 'none' ? 'механизм ' . jsstr($p['mechanism_name'] ?? '') : 'нераскладной');
    $c .= '</div>
      <div class="buy">
        <p class="eyebrow">' . h($CAT[$p['category']]['name'] ?? '') . '</p>
        <h1>' . h($d['h1'] ?? '') . '</h1>
        <p class="buy-sub">' . h($sub) . '</p>
        <p class="buy-price"><span data-price>' . h(price_text($p, 'rogozhka', false)) . '</span> <small>' . (truthy($p['price_from'] ?? null) ? 'ориентировочно, в выбранной ткани' : 'рассчитает менеджер в выбранной ткани') . '</small></p>
        <p class="buy-note">Финальную цену с учётом ткани, размера и доставки согласует менеджер.</p>
        <div class="opt"><p class="opt-t">Материал обивки</p><div class="seg" data-material>';
    foreach ($fab as $i => $f) $c .= '<button type="button" data-v="' . h($f['material']) . '" aria-pressed="' . ($i === 1 ? 'true' : 'false') . '" data-colors="' . h(implode(',', array_map('jsstr', $f['colors'] ?? []))) . '">' . h($f['name']) . '</button>';
    $rc = $MAT['rogozhka']['colors'] ?? [];
    $c .= '</div></div>
        <div class="opt"><p class="opt-t">Цвет: <span data-color-name>' . h($CN[$rc[0] ?? ''] ?? '') . '</span></p><div class="colors" data-colors-box>';
    foreach ($rc as $i => $col) $c .= '<button type="button" style="--sw:' . h($CH[$col] ?? '') . '" data-v="' . h($col) . '" data-n="' . h($CN[$col] ?? '') . '" aria-pressed="' . ($i === 0 ? 'true' : 'false') . '" aria-label="' . h($CN[$col] ?? '') . '"></button>';
    $c .= '</div></div>';
    if (truthy($p['sides'] ?? null)) $c .= '<div class="opt"><p class="opt-t">Сторона угла (стоя лицом к дивану)</p><div class="seg" data-side><button type="button" data-v="left" aria-pressed="true">Левый</button><button type="button" data-v="right" aria-pressed="false">Правый</button></div></div>';
    $st = $p['in_stock'] ?? null;
    $c .= '<p class="stock ' . (truthy($st) ? '' : 'is-order') . '">' . h(truthy($st) ? 'В наличии: ' . mb_strtolower($MAT[$st['material']]['name'] ?? '') . ', ' . ($CN[$st['color']] ?? '') . '. Доставка ' . jsstr($dl['in_stock_days']) . ' дня' : 'Под заказ, изготовление ' . jsstr($dl['made_to_order_days']) . ' дней') . '</p>
        <div class="buy-btns"><button type="button" class="btn btn-accent" data-add-product>Добавить в заявку</button><button type="button" class="btn btn-line" data-oneclick>Заказать в один клик</button></div>';
    $smp = $p['samples'] ?? [];
    if ($smp) $c .= '<p class="blk-note" style="margin-top:18px">Выставочный образец со скидкой ' . h($smp[0]['discount_pct']) . '% стоит в шоуруме ' . h(SHOWROOM()[$smp[0]['showroom']]['name'] ?? '') . ': ' . h(rub($smp[0]['sample_price'] ?? null)) . ' вместо ' . h(rub($smp[0]['old_price'] ?? null)) . '. <a href="' . u('/sale/') . '">Все образцы</a></p>';
    $c .= '<p class="buy-more"><a href="#opisanie">Характеристики и описание</a> · <a href="' . u('/dostavka/') . '">Доставка и оплата</a></p>
      </div>
    </div>
    <div class="wrap prose">
      <section class="sec pdesc" id="opisanie"><h2>Описание</h2>
        <ul class="kspec" aria-label="Ключевые характеристики">';
    foreach ($keySpecs as [$ico, $label, $value]) $c .= '<li><span class="kspec-ico">' . $ico . '</span><span class="kspec-l">' . h($label) . '</span><b>' . h($value) . '</b></li>';
    $c .= '</ul>
        <div class="pdesc-intro">' . $descIntro . '</div>';
    $feat = $d['features'] ?? [];
    if (is_array($feat) && $feat) {
        $c .= '<ul class="feat">';
        foreach (array_values($feat) as $i => $f) {
            $c .= '<li><span class="feat-n">' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) . '</span>';
            if (is_string($f)) $c .= '<p>' . h($f) . '</p>';
            else { if (truthy($f['title'] ?? null)) $c .= '<b>' . h($f['title']) . '</b>'; if (truthy($f['text'] ?? null)) $c .= '<p>' . h($f['text']) . '</p>'; }
            $c .= '</li>';
        }
        $c .= '</ul>';
    }
    if ($descCards !== '') $c .= '<div class="pdesc-cards">' . $descCards . '</div>';
    $c .= '</section>
      <section class="sec"><h2>Характеристики</h2>
        <div class="tbl"><table><tbody>
          <tr><th>Категория</th><td>' . h($CAT[$p['category']]['name'] ?? '') . '</td></tr>
          <tr><th>Габариты (Д×Г×В)</th><td>' . h(size_str($p)) . '</td></tr>
          <tr><th>Спальное место</th><td>' . h(sleep_str($p)) . '</td></tr>
          <tr><th>Механизм трансформации</th><td>' . h($p['mechanism_name'] ?? '') . '</td></tr>'
        . (truthy($p['sides'] ?? null) ? '<tr><th>Сторона угла</th><td>левая или правая, цена одинаковая</td></tr>' : '')
        . (truthy($p['modules'] ?? null) ? '<tr><th>Модули</th><td>' . h(implode(', ', array_map('jsstr', $p['modules']))) . '</td></tr>' : '')
        . '<tr><th>Конструкция</th><td>' . h(preg_replace('/\s*\[.*?\]/', '', jsstr($p['construction'] ?? ''), 1)) . '</td></tr>
          <tr><th>Материалы обивки</th><td>велюр, рогожка, жаккард</td></tr>
          <tr><th>Изготовление под заказ</th><td>' . h($dl['made_to_order_days']) . ' дней</td></tr>
          <tr><th>Гарантия</th><td>' . h($p['warranty_months'] ?? '') . ' месяцев</td></tr>
        </tbody></table></div>
      </section>';
    if ($family) $c .= '<section class="sec"><h2>Эта модель в других исполнениях</h2><div class="pgrid">' . implode('', array_map(fn($x) => product_card($x), $family)) . '</div></section>';
    $c .= '<section class="sec"><h2>Доставка и где посмотреть</h2>
        <p>Доставка по Москве в пределах МКАД ' . h(rub($dl['moscow_mkad_rub'] ?? null)) . ', за МКАД плюс ' . h($dl['outside_mkad_per_km_rub'] ?? '') . ' ₽ за километр, самовывоз из шоурума бесплатно. ' . (in_array($p['category'], ['uglovye-divany', 'modulnye-divany'], true) ? 'Сборка включена. ' : '') . 'Подробнее на странице <a href="' . u('/dostavka/') . '">доставка и сборка</a>. Нужен другой размер: <a href="' . u('/na-zakaz/') . '">диваны на заказ</a>.</p>
        ' . showrooms_html('list') . '
      </section>';
    if ($similar) $c .= '<section class="sec"><h2>Похожие модели</h2><div class="pgrid">' . implode('', array_map(fn($x) => product_card($x), $similar)) . '</div></section>';
    $c .= '</div>' . faq_html($d['faq'] ?? null, truthy($d['faq_title'] ?? null) ? $d['faq_title'] : 'Вопросы о модели ' . jsstr($p['name'] ?? ''))
        . '<div class="mbar"><button type="button" class="btn btn-accent" data-add-product>В заявку</button><button type="button" class="btn btn-line" data-oneclick>В один клик</button></div>';
    return $c;
}
function str_replace_first_n(string $search, string $repl, $s): string { return replace_first($search, $repl, jsstr($s)); }

function cart_view(array $d): string
{
    $ui = is_array($d['ui_texts'] ?? null) ? $d['ui_texts'] : [];
    $lede = truthy($ui['hero']['lede'] ?? null) ? $ui['hero']['lede'] : 'Выбранные модели. Отправьте заявку, менеджер перезвонит и согласует ткань, цену и срок.';
    return page_head(['h1' => $d['h1'] ?? '', 'lede' => $lede], [['name' => 'Главная', 'url' => '/'], ['name' => $d['h1'] ?? '', 'url' => $d['url'] ?? '']]) . '
      <div class="wrap cart" data-cart>
        <div>
          <div class="cart-list" data-cart-list></div>
          <div class="empty" data-cart-empty hidden><p class="empty-t">В заявке пока ничего нет</p><p>Выберите диван в каталоге и нажмите «В заявку».</p><p class="phead-btns" style="justify-content:center"><a class="btn btn-accent" href="' . u('/catalog/') . '">Перейти в каталог</a><a class="btn btn-line" href="' . u('/catalog/v-nalichii/') . '">Диваны в наличии</a></p></div>
        </div>
        <aside class="cart-sum" data-cart-sum>
          <p>Позиций: <b data-cart-count>0</b></p>
          <p class="tot" data-cart-total>0 ₽</p>
          <p class="buy-note">Сумма ориентировочная. Финальную стоимость с учётом ткани, размера и доставки согласует менеджер.</p>
          <form class="lead-form" data-form="cart" novalidate style="padding:0;background:none;margin:18px 0 0">
            <div class="fld-row" style="grid-template-columns:1fr">
              <label class="fld"><span>Имя</span><input name="name" autocomplete="name"></label>
              <label class="fld"><span>Телефон *</span><input name="phone" type="tel" required autocomplete="tel" data-mask placeholder="+7 (___) ___-__-__"></label>
              <label class="fld"><span>Email</span><input name="email" type="email" autocomplete="email"></label>
              <label class="fld"><span>Удобный способ связи</span><select name="channel"><option>Звонок</option><option>WhatsApp</option><option>Telegram</option></select></label>
              <label class="fld"><span>Комментарий</span><textarea name="comment" rows="3"></textarea></label>
            </div>
            <label class="fld-check"><input type="checkbox" name="consent" required> Согласен на обработку персональных данных по <a href="' . u('/privacy/#soglasie') . '">политике конфиденциальности</a></label>
            <p class="lf-err" data-err role="alert"></p>
            <button class="btn btn-accent" type="submit">Отправить заявку</button>
          </form>
        </aside>
      </div>
      ' . faq_html($d['faq'] ?? null, 'Вопросы о заявке');
}

function thanks_view(array $d): string
{
    return page_head(['h1' => $d['h1'] ?? '', 'lede' => 'Заявка принята. Мы ' . jsstr(settings()['callback_promise'] ?? '') . '.'], []) . '
      <div class="wrap prose" style="padding-block:24px 64px" data-thanks>
        <p class="lede">Номер заявки: <b data-lead-no>…</b></p>
        <div class="ig ig-steps"><ol><li><p><b>Звонок менеджера.</b> Уточним ткань, цвет, сторону угла и размер.</p></li><li><p><b>Цена и срок.</b> Назовём точную стоимость и дату изготовления или доставки.</p></li><li><p><b>Доставка или самовывоз.</b> Согласуем день и адрес, оплата при получении.</p></li></ol></div>
        <p class="phead-btns"><a class="btn btn-accent" href="' . u('/catalog/') . '">Вернуться в каталог</a><a class="btn btn-line" href="' . u('/kontakty/') . '">Шоурумы</a></p>
      </div>';
}

// /go/<коллекция>/<файл>/ — кнопка «Открыть на сайте» в админке
function go_target(string $col, string $file): ?string
{
    if ($col === 'settings') return null;
    foreach (cms()['pages'] as $p) {
        if ($p['col'] !== $col || $p['file'] !== $file) continue;
        $url = $p['data']['url'] ?? null;
        if (!truthy($url) || strpos(jsstr($url), '{') !== false) return null;
        return ($p['data']['type'] ?? null) === 'error' ? '/404/' : $url;
    }
    return null;
}
