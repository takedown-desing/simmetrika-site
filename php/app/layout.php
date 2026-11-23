<?php
// Каркас страницы: <head>, шапка, подвал, заголовок страницы, FAQ, «Смотрите также».

function attr_current($url, $current): string { return $current !== null && $current === $url ? ' aria-current="page"' : ''; }
function tel_href($p): string { return 'tel:' . preg_replace('/[^\d+]/', '', jsstr($p)); }

/**
 * @param array $o data, schema, home, og_image, body_class, noindex
 */
function base_layout(array $o, string $content): string
{
    $data = $o['data'] ?? [];
    $title = page_title($data);
    $desc = str_replace('—', '–', jsstr($data['description'] ?? ''));
    $og = !empty($o['og_image']) ? img($o['og_image']) : null;
    $closed = !empty($o['noindex']) || ($data['noindex'] ?? null) === true || preg_match('/noindex/', jsstr($data['robots'] ?? ''));
    $robots = cfg('preview') ? 'noindex, nofollow' : ($closed ? 'noindex, follow' : 'index, follow');
    $c = jsstr($data['canonical'] ?? '');
    if (preg_match('#^(/|https?:)#', $c)) $canon = preg_match('#^https?:#', $c) ? $c : canonical_url(norm_url($c));
    elseif (truthy($data['url'] ?? null) && strpos(jsstr($data['url']), '{') === false) $canon = canonical_url($data['url']);
    else $canon = '';
    $ogTitle = truthy($data['og_title'] ?? null) ? $data['og_title'] : $title;
    $ogDesc = truthy($data['og_description'] ?? null) ? $data['og_description'] : $desc;

    $hd = '<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . h($title) . '</title>
<meta name="description" content="' . h($desc) . '">
<meta name="robots" content="' . $robots . '">
' . ($canon !== '' ? '<link rel="canonical" href="' . h($canon) . '">' . "\n" : '') . '<meta property="og:type" content="website">
<meta property="og:title" content="' . h($ogTitle) . '">
<meta property="og:description" content="' . h($ogDesc) . '">
' . ($og ? '<meta property="og:image" content="' . h((preg_match('#^https?:#', $og['l']) ? '' : cfg('origin')) . u($og['l'])) . '">' . "\n" : '') . '<meta property="og:locale" content="ru_RU">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&amp;display=swap&amp;subset=cyrillic">
<link rel="icon" href="' . u('/favicon.svg') . '" type="image/svg+xml">
' . (!empty($o['schema']) ? '<script type="application/ld+json">' . str_replace('</', '<\/', js_json($o['schema'])) . '</script>' . "\n" : '') . '<link rel="stylesheet" href="' . u('/css/site.css') . '?v=' . asset_ver('/css/site.css') . '">
</head>
<body class="' . h($o['body_class'] ?? '') . '" data-base="' . h(base()) . '" data-lead-api="' . h(base() . '/api/lead/') . '">
<a class="skip" href="#main">К содержимому</a>
' . (cfg('preview') ? '<div class="preview-bar">Тестовая копия сайта SIMMETRIKA. Цены, сроки и условия предварительные.</div>' . "\n" : '')
        . site_header(!empty($o['home']), $data['url'] ?? null)
        . '<main id="main">' . $content . '</main>'
        . site_footer()
        . '<div class="modal" id="modal" role="dialog" aria-modal="true" aria-label="Форма заявки"><div class="modal-in"><button class="round modal-x" type="button" data-close aria-label="Закрыть"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2"/></svg></button><div data-modal-body></div></div></div>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script src="' . u('/js/app.js') . '?v=' . asset_ver('/js/app.js') . '" defer></script>
</body>
</html>
';
    return $hd;
}
// версия файла для сброса кэша браузера после обновления
function asset_ver(string $p): string
{
    $f = ROOT . $p;
    return is_file($f) ? (string)filemtime($f) : '1';
}

const LOGO = '<b>SIMME<i>T</i>RIKA</b><span>мебельная фабрика</span>';

function site_header(bool $home, $current): string
{
    $s = settings();
    $tel = tel_href($s['phone_main']);
    $M = menus();
    $nav = '';
    foreach ($M['nav'] as $n) $nav .= '<a href="' . h(u($n['url'])) . '" class="' . (truthy($n['accent'] ?? null) ? 'is-accent' : '') . '"' . attr_current($n['url'], $current) . '>' . h($n['title']) . '</a>';
    $navR = '';
    foreach ($M['nav_right'] as $n) $navR .= '<a href="' . h(u($n['url'])) . '"' . attr_current($n['url'], $current) . '>' . h($n['title']) . '</a>';
    $cols = '';
    foreach ($M['catalog'] as $g) {
        $li = '';
        foreach ($g['items'] ?? [] as $i) $li .= '<li><a href="' . h(u($i['url'])) . '">' . h($i['title']) . '</a></li>';
        $cols .= '<div><p>' . h($g['title'] ?? '') . '</p><ul>' . $li . '</ul></div>';
    }
    return '<header class="hdr ' . ($home ? 'hdr-home' : '') . '">
  <div class="wrap hdr-in">
    <div class="hdr-l">
      <button class="hdr-cat" type="button" data-menu aria-expanded="false" aria-controls="cmenu">
        <span class="round" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 8h14M5 12h14M5 16h14" stroke="currentColor" stroke-width="1.6"/></svg></span>
        <span class="hdr-cat-l">Каталог<br>товаров</span><span class="sr">Открыть каталог</span>
      </button>
      <nav class="hdr-nav" aria-label="Основное меню">' . $nav . '</nav>
    </div>
    <a class="logo" href="' . u('/') . '" aria-label="SIMMETRIKA, на главную">' . LOGO . '</a>
    <div class="hdr-r">
      <nav class="hdr-nav" aria-label="Покупателям">' . $navR . '</nav>
      <a class="hdr-phone" href="' . h($tel) . '"><b>' . h($s['phone_main']) . '</b><span>ежедневно ' . h($s['work_hours_calls']) . '</span></a>
      <a class="round hdr-mob" href="' . h($tel) . '" aria-label="' . h('Позвонить ' . $s['phone_main']) . '"><svg viewBox="0 0 24 24"><path d="M6.6 3.5l3 .4 1.2 4-2 1.6a12 12 0 0 0 5.7 5.7l1.6-2 4 1.2.4 3c-.1 1-1 1.8-2 1.8A16.5 16.5 0 0 1 4.8 5.5c0-1 .8-1.9 1.8-2z" fill="none" stroke="currentColor" stroke-width="1.5"/></svg></a>
      <a class="round" href="' . u('/zayavka/') . '" aria-label="Заявка"><svg viewBox="0 0 24 24"><path d="M3 4h2.5l2.2 10.5h10.6L20.5 7H7" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="9.5" cy="19" r="1.5" fill="currentColor"/><circle cx="17" cy="19" r="1.5" fill="currentColor"/></svg><span class="cart-n" data-cart-n data-n="0"></span></a>
    </div>
  </div>
</header>
<div class="cmenu" id="cmenu" data-cmenu>
  <div class="cmenu-in"><div class="wrap">
    <div class="cmenu-top"><a class="logo" href="' . u('/') . '">' . LOGO . '</a><button class="round" type="button" data-menu-close aria-label="Закрыть каталог"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2"/></svg></button></div>
    <div class="cmenu-grid">' . $cols . '</div>
  </div></div>
</div>
';
}

function site_footer(): string
{
    $s = settings();
    $li = fn($url, $t) => '<li><a href="' . u($url) . '">' . h($t) . '</a></li>';
    $rooms = '';
    foreach (showrooms() as $r) $rooms .= $li('/kontakty/#' . $r['slug'], $r['name']);
    return '<div class="ftop"><div class="wrap ftop-in"><span>Новинки, образцы на распродаже и акции фабрики в сообществе</span><a class="round" href="' . h($s['vk']) . '" target="_blank" rel="noopener" aria-label="SIMMETRIKA ВКонтакте"><svg viewBox="0 0 24 24"><path d="M3 7h3c.2 4 1.8 6 3 6.3V7h2.8v3.6c1.2-.1 2.4-1.6 2.8-3.6h2.8c-.3 1.9-1.6 3.4-2.6 4.1 1 .5 2.6 1.8 3.2 4.4h-3c-.4-1.5-1.6-2.7-3.2-2.8v2.8H11C6 15.5 3.2 12 3 7z" fill="currentColor"/></svg></a></div></div>
<footer class="ftr">
  <div class="wrap">
    <div class="ftr-grid">
      <div>
        <h2>Москва и Московская область</h2>
        <p><a class="ftr-phone" href="' . h(tel_href($s['phone_main'])) . '">' . h($s['phone_main']) . '</a></p>
        <p><a href="' . h(tel_href($s['phone_second'])) . '">' . h($s['phone_second']) . '</a></p>
        <p>Звонки ежедневно ' . h($s['work_hours_calls']) . '</p>
        <p><a href="mailto:' . h($s['email']) . '">' . h($s['email']) . '</a></p>
      </div>
      <div><h2>Каталог</h2><ul>'
        . $li('/catalog/pryamye-divany/', 'Прямые диваны') . $li('/catalog/uglovye-divany/', 'Угловые диваны') . $li('/catalog/modulnye-divany/', 'Модульные диваны')
        . $li('/catalog/divany-krovati/', 'Диваны-кровати') . $li('/catalog/kresla/', 'Кресла и пуфы') . $li('/catalog/v-nalichii/', 'В наличии') . $li('/sale/', 'Распродажа образцов') . '</ul></div>
      <div><h2>Покупателям</h2><ul>'
        . $li('/na-zakaz/', 'Диваны на заказ') . $li('/dostavka/', 'Доставка и оплата') . $li('/o-kompanii/', 'О фабрике') . $li('/news/', 'Новости и акции') . $li('/kontakty/', 'Шоурумы и контакты') . '</ul></div>
      <div><h2>Шоурумы</h2><ul>' . $rooms . '</ul></div>
    </div>
    <div class="ftr-bottom"><span>© ' . date('Y') . ' SIMMETRIKA, мебельная фабрика</span><a href="' . u('/privacy/') . '">Политика конфиденциальности</a></div>
  </div>
</footer>
';
}

function crumbs_html(array $crumbs): string
{
    if (!$crumbs) return '';
    $n = count($crumbs);
    $li = '';
    foreach ($crumbs as $i => $c) $li .= '<li>' . ($i < $n - 1 ? '<a href="' . h(u($c['url'])) . '">' . h($c['name']) . '</a>' : '<span aria-current="page">' . h($c['name']) . '</span>') . '</li>';
    return '<ol class="crumbs">' . $li . '</ol>';
}

function head_btn($b, string $cls): string
{
    if (!is_array($b) || !truthy($b['label'] ?? null)) return '';
    $esc = fn($s) => strtr(jsstr($s ?? ''), ['&' => '&amp;', '<' => '&lt;', '"' => '&quot;']);
    $action = $b['action'] ?? null;
    if (!truthy($b['href'] ?? null) && $action !== 'callback') return '';
    if ($action === 'callback') return '<button type="button" class="btn ' . $cls . '" data-callback>' . $esc($b['label']) . '</button>';
    return '<a class="btn ' . $cls . '" href="' . $esc(u($b['href'])) . '">' . $esc($b['label']) . '</a>';
}

function page_head(array $data, array $crumbs = [], string $slot = ''): string
{
    $cta = is_array($data['hero_cta'] ?? null) ? $data['hero_cta'] : [];
    $h = '<div class="phead"><div class="wrap">' . crumbs_html($crumbs);
    if (truthy($data['eyebrow'] ?? null)) $h .= '<p class="eyebrow">' . h($data['eyebrow']) . '</p>';
    $h .= '<h1>' . h($data['h1'] ?? '') . '</h1>';
    if (truthy($data['lede'] ?? null)) $h .= '<p class="lede">' . h($data['lede']) . '</p>';
    $hl = $data['highlights'] ?? [];
    if (is_array($hl) && count($hl) > 0) {
        $h .= '<div class="hl">';
        foreach ($hl as $x) $h .= '<div><b>' . h($x['value'] ?? '') . '</b><span>' . h($x['label'] ?? '') . '</span></div>';
        $h .= '</div>';
    }
    $btns = head_btn($cta['primary'] ?? null, 'btn-accent') . head_btn($cta['secondary'] ?? null, 'btn-line');
    if (head_btn($cta['primary'] ?? null, '') !== '' || head_btn($cta['secondary'] ?? null, '') !== '') $h .= '<div class="phead-btns">' . $btns . '</div>';
    return $h . $slot . '</div></div>';
}

function faq_html($items, $title = null): string
{
    if (!is_array($items) || !$items) return '';
    $title = truthy($title) ? $title : 'Вопросы и ответы';
    $l = '';
    foreach ($items as $i => $x) $l .= '<details' . ($i === 0 ? ' open' : '') . '><summary>' . h($x['q'] ?? '') . '</summary><p>' . h($x['a'] ?? '') . '</p></details>';
    return '<section class="faq" aria-labelledby="faq-t"><div class="wrap">
  <h2 id="faq-t">' . h($title) . '</h2>
  <div class="faq-list">' . $l . '</div>
</div></section>';
}

function related_html($items): string
{
    $items = array_values(array_filter(is_array($items) ? $items : [], fn($r) => is_array($r) && truthy($r['url'] ?? null) && truthy($r['title'] ?? null)));
    if (!$items) return '';
    $l = '';
    foreach ($items as $r) $l .= '<a class="rel-i" href="' . h(u($r['url'])) . '"><b>' . h($r['title']) . '</b><span>' . h($r['text'] ?? '') . '</span></a>';
    return '<section class="rel" aria-labelledby="rel-t"><div class="wrap">
  <h2 class="sec-title" id="rel-t">Смотрите также</h2>
  <div class="rel-grid">' . $l . '</div>
</div></section>';
}
