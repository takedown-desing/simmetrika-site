<?php
// Единая точка входа сайта: страницы, редиректы, robots.txt, sitemap.xml, приём заявок.
// Работает с .htaccess (Apache) или правилом try_files (nginx), а также со встроенным сервером: php -S localhost:8000 index.php

// встроенный сервер PHP отдаёт существующие файлы сам (кроме служебных папок)
if (PHP_SAPI === 'cli-server') {
    $p = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
    $cb = is_file(__DIR__ . '/config.php') ? rtrim((string)((require __DIR__ . '/config.php')['base'] ?? ''), '/') : '';
    if ($cb !== '' && strpos($p, $cb . '/') === 0) $p = substr($p, strlen($cb));
    if (preg_match('#^/(app|install|storage)(/|$)|^/config(\.sample)?\.php$#', $p)) { http_response_code(403); exit; }
    if ($p !== '/' && is_file(__DIR__ . $p) && substr($p, -4) !== '.php') {
        if ($cb === '') return false;
        $types = ['css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml', 'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf', 'json' => 'application/json', 'txt' => 'text/plain'];
        header('Content-Type: ' . ($types[strtolower(pathinfo($p, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
        readfile(__DIR__ . $p);
        exit;
    }
    if ($p !== '/' && is_dir(__DIR__ . $p) && is_file(rtrim(__DIR__ . $p, '/') . '/index.php')) {
        if (substr($p, -1) !== '/') { header('Location: ' . $cb . $p . '/', true, 301); exit; }
        $_SERVER['SCRIPT_NAME'] = $cb . $p . 'index.php';
        chdir(__DIR__ . $p);
        require __DIR__ . $p . 'index.php';
        exit;
    }
}

require __DIR__ . '/app/bootstrap.php';

function send_html(string $html, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    if (cfg('preview')) header('X-Robots-Tag: noindex, nofollow');
    echo $html;
}
function go(string $to, int $code = 301): void
{
    if (!preg_match('#^https?:#', $to)) $to = base() . $to;
    header('Location: ' . $to, true, $code);
    exit;
}

try {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = rawurldecode(parse_url($uri, PHP_URL_PATH) ?: '/');
    $query = (string)(parse_url($uri, PHP_URL_QUERY) ?? '');
    $b = base();
    if ($b !== '') {
        if ($path === $b) go('/');
        if (strpos($path, $b . '/') === 0) $path = substr($path, strlen($b));
    }
    if ($path === '' ) $path = '/';
    $path = preg_replace('#/{2,}#', '/', $path);
    $qs = $query !== '' ? '?' . $query : '';

    // служебные адреса
    if ($path === '/api/lead/' || $path === '/api/lead') { require ROOT . '/api/lead.php'; exit; }
    if ($path === '/robots.txt') {
        header('Content-Type: text/plain; charset=utf-8');
        if (cfg('preview')) { echo "# Тестовая копия сайта закрыта от индексации\nUser-agent: *\nDisallow: /\n"; exit; }
        $bb = $b === '' ? '' : $b;
        echo "User-agent: *\nDisallow: $bb/admin/\nDisallow: $bb/api/\nDisallow: $bb/go/\nDisallow: $bb/zayavka/\n\n"
            . "User-agent: Yandex\nDisallow: $bb/admin/\nDisallow: $bb/api/\nDisallow: $bb/go/\nDisallow: $bb/zayavka/\nClean-param: material&color&side $bb/catalog/\n\n"
            . 'Sitemap: ' . cfg('origin') . "$bb/sitemap.xml\n";
        exit;
    }
    if ($path === '/sitemap.xml' || $path === '/sitemap-index.xml') {
        if (cfg('preview')) { send_html(view_404(), 404); exit; }
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (sitemap_urls() as $u) echo '<url><loc>' . h(cfg('origin') . $b . $u) . "</loc></url>\n";
        echo "</urlset>\n";
        exit;
    }

    // редиректы из админки и после смены адресов (одним шагом)
    $res = redirect_list()['resolved'];
    if (isset($res[$path])) go($res[$path] . $qs);
    if (preg_match('/\.html?$/', $path)) {
        if ($path === '/index.html') go('/' . $qs);
        if ($path === '/404.html') { send_html(view_404(), 404); exit; }
    } elseif (preg_match('#/[^/]+\.[a-z0-9]{1,5}$#i', $path)) {
        send_html(view_404(), 404); exit;
    } elseif (substr($path, -1) !== '/') {
        go($path . '/' . $qs);
    }
    if (isset($res[$path])) go($res[$path] . $qs);

    // /go/<коллекция>/<запись>/ — переход из админки на страницу записи
    if (preg_match('#^/go/([a-z0-9_-]+)/([^/]+)/$#i', $path, $m)) {
        $to = go_target($m[1], $m[2]);
        if ($to === null) { send_html(view_404(), 404); exit; }
        go($to, 302);
    }

    // готовая страница из кэша
    $cacheFile = null;
    if (cfg('cache') && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $cacheFile = STORAGE . '/cache/' . md5($path) . '.html';
        if (is_file($cacheFile)) { send_html(file_get_contents($cacheFile)); exit; }
    }

    if ($path === '/') $html = view_home();
    else {
        $routes = page_routes();
        $html = isset($routes[$path]) ? view_page($routes[$path]) : null;
    }
    if ($html === null) { send_html(view_404(), 404); exit; }
    if ($cacheFile) { @mkdir(dirname($cacheFile), 0775, true); @file_put_contents($cacheFile, $html, LOCK_EX); }
    send_html($html);
} catch (Throwable $e) {
    error_log('SIMMETRIKA: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    @file_put_contents(STORAGE . '/logs/error.log', date('c') . ' ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND);
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=utf-8'); }
    echo cfg('debug') ? '<pre>' . h((string)$e) . '</pre>' : '<!doctype html><meta charset="utf-8"><title>Ошибка</title><p style="font:16px sans-serif;padding:40px">Сайт временно недоступен. Позвоните нам: +7 929 677-78-75.</p>';
}

// индексируемые страницы для sitemap.xml (то же правило, что в сборке Astro)
function sitemap_urls(): array
{
    $closed = [];
    foreach (cms()['pages'] as $p) {
        $d = $p['data'];
        if (!truthy($d['url'] ?? null)) continue;
        $lr = $d['listing']['rule'] ?? null;
        $rule = (is_string($lr) ? $lr !== '' : (is_array($lr) && $lr)) ? rule_str($lr) : '';
        if (($d['noindex'] ?? null) === true || preg_match('/noindex/', jsstr($d['robots'] ?? '')) || truthy($d['canonical'] ?? null)
            || in_array($d['type'] ?? null, ['cart', 'error', 'ui'], true)
            || (in_array($d['type'] ?? null, ['filter', 'collection'], true) && $rule !== '' && count(select_products($rule)) < 3)) $closed[$d['url']] = 1;
    }
    $out = isset($closed['/']) ? [] : ['/'];
    foreach (array_keys(page_routes()) as $u) if (!isset($closed[$u])) $out[] = (string)$u;
    sort($out, SORT_STRING);
    return array_values(array_unique($out));
}
