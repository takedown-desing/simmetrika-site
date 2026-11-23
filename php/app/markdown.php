<?php
// Markdown текстов страниц → HTML (порт src/lib/md.mjs: remark + gfm + директивы ::: + rehypeSite).
// Поддерживается то, что используется в текстах сайта: заголовки ## / ### (и {#якорь}), абзацы, **жирный**,
// [ссылки](/адрес/), адреса email, списки - и 1., таблицы | … |, вставки :::имя{атрибуты} … :::.

function md_slugify(string $s): string
{
    $s = str_replace('ё', 'е', mb_strtolower($s));
    $s = preg_replace('/[^a-zа-я0-9]+/iu', '-', $s);
    $s = preg_replace('/^-|-$/u', '', $s);
    return mb_substr($s, 0, 60);
}
function md_text(string $s): string { return strtr($s, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']); }
function md_attr(string $s): string { return strtr($s, ['&' => '&amp;', '"' => '&quot;']); }

// ── строчная разметка ──
// токены: ['t', текст] | ['strong', [токены]] | ['a', href, [токены]]
function md_inline_tokens(string $s): array
{
    $out = [];
    $buf = '';
    $n = strlen($s);
    $i = 0;
    while ($i < $n) {
        $c = $s[$i];
        if ($c === '*' && substr($s, $i, 2) === '**') {
            $j = strpos($s, '**', $i + 2);
            if ($j !== false && $j > $i + 2 && !ctype_space($s[$i + 2]) && !ctype_space($s[$j - 1])) {
                if ($buf !== '') { $out[] = ['t', $buf]; $buf = ''; }
                $out[] = ['strong', md_inline_tokens(substr($s, $i + 2, $j - $i - 2))];
                $i = $j + 2;
                continue;
            }
        }
        if ($c === '[' && preg_match('/\G\[([^\]]*)\]\(([^)\s]*)\)/', $s, $m, 0, $i)) {
            if ($buf !== '') { $out[] = ['t', $buf]; $buf = ''; }
            $out[] = ['a', $m[2], md_inline_tokens($m[1])];
            $i += strlen($m[0]);
            continue;
        }
        $buf .= $c;
        $i++;
    }
    if ($buf !== '') $out[] = ['t', $buf];
    // адреса почты в тексте становятся ссылками (как gfm autolink literal)
    $res = [];
    foreach ($out as $t) {
        if ($t[0] !== 't') { $res[] = $t; continue; }
        $parts = preg_split('/([A-Za-z0-9._+-]+@[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)+)/', $t[1], -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $k => $p) {
            if ($p === '') continue;
            if ($k % 2) {
                $tail = '';
                while (preg_match('/[.\-_]$/', $p)) { $tail = substr($p, -1) . $tail; $p = substr($p, 0, -1); }
                $res[] = ['a', 'mailto:' . $p, [['t', $p]]];
                if ($tail !== '') $res[] = ['t', $tail];
            } else $res[] = ['t', $p];
        }
    }
    return $res;
}
function md_tokens_text(array $toks): string
{
    $s = '';
    foreach ($toks as $t) $s .= $t[0] === 't' ? $t[1] : md_tokens_text($t[0] === 'a' ? $t[2] : $t[1]);
    return $s;
}
function md_href(string $h): string
{
    if (substr($h, 0, 1) === '/' && (base() === '' || strpos($h, base() . '/') !== 0)) return u($h);
    return $h;
}
function md_tokens_html(array $toks): string
{
    $s = '';
    foreach ($toks as $t) {
        if ($t[0] === 't') $s .= md_text($t[1]);
        elseif ($t[0] === 'strong') $s .= '<strong>' . md_tokens_html($t[1]) . '</strong>';
        else {
            $h = $t[1];
            $ext = (bool)preg_match('/^https?:/', $h);
            $s .= '<a href="' . md_attr(md_href($h)) . '"' . ($ext ? ' target="_blank" rel="nofollow noopener"' : '') . '>' . md_tokens_html($t[2]) . '</a>';
        }
    }
    return $s;
}
function md_inline(string $s): string { return md_tokens_html(md_inline_tokens($s)); }
// id заголовка по правилу rehypeSite: текст детей и внуков
function md_heading_id(array $toks): string
{
    $s = '';
    foreach ($toks as $t) {
        if ($t[0] === 't') { $s .= $t[1]; continue; }
        foreach ($t[0] === 'a' ? $t[2] : $t[1] as $x) if ($x[0] === 't') $s .= $x[1];
    }
    return md_slugify($s);
}

// ── готовые вставки (HTML из render.php): внешние ссылки в новой вкладке, id у H2/H3 ──
function md_raw(string $html): string
{
    $html = preg_replace_callback('/<a\s[^>]*>/', function ($m) {
        $tag = $m[0];
        if (!preg_match('/\shref="([^"]*)"/', $tag, $h)) return $tag;
        $href = html_entity_decode($h[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/^https?:/', $href)) {
            $tag = preg_replace('/\s(?:target|rel)="[^"]*"/', '', $tag);
            return substr($tag, 0, -1) . ' target="_blank" rel="nofollow noopener">';
        }
        if (substr($href, 0, 1) === '/' && base() !== '' && strpos($href, base() . '/') !== 0) {
            return str_replace($h[0], ' href="' . md_attr(u($href)) . '"', $tag);
        }
        return $tag;
    }, $html);
    return preg_replace_callback('/<(h[23])(\s[^>]*)?>([\s\S]*?)<\/\1>/', function ($m) {
        $attrs = $m[2] ?? '';
        if (preg_match('/\sid="/', $attrs)) return $m[0];
        // текст на первых двух уровнях вложенности
        $depth = 0; $txt = '';
        foreach (preg_split('/(<[^>]+>)/', $m[3], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $p) {
            if ($p[0] === '<') {
                if (substr($p, 1, 1) === '/') $depth--;
                elseif (substr($p, -2) !== '/>' && !preg_match('/^<(img|br|input|hr|wbr)\b/i', $p)) $depth++;
                continue;
            }
            if ($depth <= 1) $txt .= html_entity_decode($p, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '<' . $m[1] . $attrs . ' id="' . md_attr(md_slugify($txt)) . '">' . $m[3] . '</' . $m[1] . '>';
    }, $html);
}

// ── блоки ──
function md_attrs(string $s): array
{
    $a = [];
    if (preg_match_all('/([A-Za-z_][\w-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s}"\']+))/', $s, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) $a[$m[1]] = $m[2] !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''));
    }
    if (preg_match_all('/(?:^|\s)#([\w-]+)/', $s, $mm)) foreach ($mm[1] as $id) $a['id'] = $id;
    return $a;
}
const MD_LIST = '/^(?:([-*+])|(\d{1,9})[.)])[ \t]+(.*)$/';
function md_is_block_start(string $line): bool
{
    return (bool)(preg_match('/^#{1,6}[ \t]+/', $line) || preg_match('/^:{2,}[A-Za-z]/', $line) || preg_match('/^(?:[-*+]|1[.)])[ \t]+\S/', $line));
}
function md_table_row(string $line): array
{
    $l = trim($line);
    if ($l !== '' && $l[0] === '|') $l = substr($l, 1);
    if ($l !== '' && substr($l, -1) === '|') $l = substr($l, 0, -1);
    return array_map('trim', explode('|', $l));
}

// возвращает список блоков: ['html' => …, 'h2' => bool, 'node' => [тип, данные для textOf]]
function md_blocks(array $lines, array $ctx): array
{
    $out = [];
    $n = count($lines);
    $i = 0;
    while ($i < $n) {
        $line = $lines[$i];
        if (trim($line) === '') { $i++; continue; }
        // заголовок
        if (preg_match('/^(#{1,6})[ \t]+(.*?)[ \t]*$/', $line, $m)) {
            $lvl = strlen($m[1]);
            $text = preg_replace('/[ \t]+#+$/', '', $m[2]);
            $id = null;
            if (preg_match('/^(.*?)\s*\{#([\w-]+)\}$/u', $text, $mm)) { $text = $mm[1]; $id = $mm[2]; }
            $toks = md_inline_tokens($text);
            if ($id === null && ($lvl === 2 || $lvl === 3)) $id = md_heading_id($toks);
            $out[] = ['html' => "<h$lvl" . ($id !== null ? ' id="' . md_attr($id) . '"' : '') . '>' . md_tokens_html($toks) . "</h$lvl>", 'h2' => $lvl === 2, 'text' => md_tokens_text($toks)];
            $i++;
            continue;
        }
        // вставка :::имя{…} … :::
        if (preg_match('/^(:{3,})([A-Za-z][\w-]*)(?:\[([^\]]*)\])?(?:\{(.*)\})?\s*$/', $line, $m)) {
            $fence = strlen($m[1]);
            $depth = 1;
            $inner = [];
            $i++;
            while ($i < $n) {
                $l = $lines[$i];
                if (preg_match('/^(:{3,})\s*$/', $l, $c) && strlen($c[1]) >= $fence) { $depth--; if ($depth === 0) { $i++; break; } }
                elseif (preg_match('/^:{3,}[A-Za-z]/', $l)) $depth++;
                $inner[] = $l;
                $i++;
            }
            $out[] = md_directive($m[2], md_attrs($m[4] ?? ''), ($m[3] ?? '') !== '' ? $m[3] : null, $inner, $ctx);
            continue;
        }
        // однострочная вставка ::имя{…}
        if (preg_match('/^::([A-Za-z][\w-]*)(?:\[([^\]]*)\])?(?:\{(.*)\})?\s*$/', $line, $m)) {
            $out[] = md_directive($m[1], md_attrs($m[3] ?? ''), null, [], $ctx);
            $i++;
            continue;
        }
        // таблица
        if (strpos($line, '|') !== false && $i + 1 < $n && preg_match('/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/', $lines[$i + 1])) {
            $head = md_table_row($line);
            $i += 2;
            $rows = [];
            while ($i < $n && trim($lines[$i]) !== '' && strpos($lines[$i], '|') !== false && !md_is_block_start($lines[$i])) { $rows[] = md_table_row($lines[$i]); $i++; }
            $w = count($head);
            $h = "<table>\n<thead>\n<tr>\n" . implode("\n", array_map(fn($c) => '<th>' . md_inline($c) . '</th>', $head)) . "\n</tr>\n</thead>";
            $txt = implode('', $head);
            if ($rows) {
                $h .= "\n<tbody>";
                foreach ($rows as $r) {
                    $cells = [];
                    for ($k = 0; $k < $w; $k++) { $cells[] = '<td>' . md_inline($r[$k] ?? '') . '</td>'; $txt .= $r[$k] ?? ''; }
                    $h .= "\n<tr>\n" . implode("\n", $cells) . "\n</tr>";
                }
                $h .= "\n</tbody>";
            }
            $h .= "\n</table>";
            $out[] = ['html' => '<div class="tbl">' . $h . '</div>', 'h2' => false, 'text' => $txt, 'table' => $h];
            continue;
        }
        // список
        if (preg_match(MD_LIST, $line, $m)) {
            $ordered = ($m[1] ?? '') === '';
            $start = $ordered ? (int)$m[2] : null;
            $items = [];
            $loose = false;
            $blank = false;
            while ($i < $n) {
                $l = $lines[$i];
                if (trim($l) === '') { $blank = true; $i++; continue; }
                if (preg_match(MD_LIST, $l, $mm) && (($mm[1] ?? '') === '') === $ordered) {
                    if ($blank && $items) $loose = true;
                    $blank = false;
                    $items[] = [$mm[3]];
                    $i++;
                    continue;
                }
                if ($blank || md_is_block_start($l) || preg_match('/^:{3,}/', $l)) break;
                $items[count($items) - 1][] = trim($l); // продолжение пункта
                $i++;
            }
            // пустая строка в конце списка не делает его «свободным»
            $tag = $ordered ? 'ol' : 'ul';
            $txt = '';
            $lis = array_map(function ($it) use ($loose, &$txt) {
                $body = implode("\n", $it);
                $txt .= md_tokens_text(md_inline_tokens($body));
                return '<li>' . ($loose ? "\n<p>" . md_inline($body) . "</p>\n" : md_inline($body)) . '</li>';
            }, $items);
            $out[] = ['html' => "<$tag" . ($ordered && $start !== 1 ? ' start="' . $start . '"' : '') . ">\n" . implode("\n", $lis) . "\n</$tag>", 'h2' => false, 'text' => $txt];
            continue;
        }
        // абзац
        $para = [trim($line)];
        $i++;
        while ($i < $n && trim($lines[$i]) !== '' && !md_is_block_start($lines[$i])
            && !(strpos($lines[$i], '|') !== false && $i + 1 < $n && preg_match('/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)+\|?\s*$/', $lines[$i + 1]))) {
            $para[] = trim($lines[$i]);
            $i++;
        }
        $body = implode("\n", $para);
        $out[] = ['html' => '<p>' . md_inline($body) . '</p>', 'h2' => false, 'text' => md_tokens_text(md_inline_tokens($body))];
    }
    return $out;
}

function md_directive(string $name, array $a, $label, array $inner, array $ctx): array
{
    $raw = fn($h) => ['html' => md_raw($h), 'h2' => false, 'text' => ''];
    $g = fn($k, $def = null) => array_key_exists($k, $a) ? $a[$k] : $def;
    $items = fn() => array_map('js_trim', explode(',', (string)$g('items', '')));
    switch ($name) {
        case 'products': return $raw(products_grid(replace_first('{slug}', (string)($ctx['showroom'] ?? ''), (string)(truthy($g('rule')) ? $g('rule') : 'all')), $g('limit')));
        case 'filters': return $raw(filters_html($g('set', 'price,length,mechanism,stock')));
        case 'tiles': return $raw(tiles($items()));
        case 'chips': return $raw(chips($items()));
        case 'spec-table': return $raw(spec_table($g('rule', 'all'), $g('cols', 'name,size,sleeping,mechanism,price')));
        case 'swatches': return $raw(swatches($g('materials', 'velyur,rogozhka,zhakkard')));
        case 'showrooms': return $raw(showrooms_html($g('mode', 'cards')));
        case 'map': return $raw(map_html());
        case 'news': return $raw(news_html($g('limit', 3), $g('type', 'all')));
        case 'promo': return $raw(promo_html());
        case 'form': {
            $kids = md_blocks($inner, $ctx);
            $lead = js_trim(($label ?? '') . implode('', array_map(fn($b) => $b['text'], $kids)));
            return $raw(form_html($g('type', 'question'), ['title' => $g('title'), 'id' => truthy($g('id')) ? $g('id') : 'forma', 'lead' => $lead]));
        }
    }
    $kids = md_blocks($inner, $ctx);
    $body = array_map(fn($b) => $b['html'], $kids);
    if ($label !== null) array_unshift($body, '<p>' . md_inline($label) . '</p>');
    $idAttr = truthy($g('id')) ? ' id="' . md_attr($g('id')) . '"' : '';
    $title = truthy($g('title')) ? '<p class="ig-title">' . md_text($g('title')) . '</p>' : '';
    $txt = implode('', array_map(fn($b) => $b['text'], $kids));
    switch ($name) {
        case 'steps': case 'stats': case 'compare':
            if ($title) array_unshift($body, $title);
            return ['html' => '<section class="ig ig-' . $name . '"' . $idAttr . '>' . implode("\n", $body) . '</section>', 'h2' => false, 'text' => $txt];
        case 'scheme': {
            $svg = scheme_svg($g('kind', ''), $a, $ctx);
            if ($svg !== '') array_unshift($body, md_raw('<div class="scheme-svg">' . $svg . '</div>'));
            if ($title) array_unshift($body, $title);
            return ['html' => '<figure class="ig ig-scheme"' . $idAttr . '>' . implode("\n", $body) . '</figure>', 'h2' => false, 'text' => $txt];
        }
        case 'cta': {
            $href = truthy($g('href')) ? $g('href') : '#forma';
            $body[] = $g('action') === 'callback' || $href === 'callback'
                ? '<p class="blk-cta-act"><button type="button" class="btn btn-accent" data-callback>' . esc(truthy($g('label')) ? $g('label') : 'Обратный звонок') . '</button></p>'
                : '<p class="blk-cta-act"><a class="btn btn-accent" href="' . u($href) . '">' . esc(truthy($g('label')) ? $g('label') : 'Подробнее') . '</a></p>';
            return ['html' => '<aside class="blk-cta"' . $idAttr . '>' . implode("\n", $body) . '</aside>', 'h2' => false, 'text' => $txt];
        }
        case 'note':
            return ['html' => '<aside class="blk-note"' . $idAttr . '>' . implode("\n", $body) . '</aside>', 'h2' => false, 'text' => $txt];
        case 'practice':
            array_unshift($body, '<p class="blk-label">Из опыта фабрики</p>');
            return ['html' => '<aside class="blk-practice"' . $idAttr . '>' . implode("\n", $body) . '</aside>', 'h2' => false, 'text' => $txt];
    }
    return ['html' => '<div class="blk-' . md_attr($name) . '">' . implode("\n", $body) . '</div>', 'h2' => false, 'text' => $txt];
}

function render_md($md = '', array $ctx = []): string
{
    if (!truthy($md)) return '';
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", (string)$md));
    $blocks = md_blocks($lines, $ctx);
    // каждый H2 открывает <section class="sec">
    $out = '';
    $open = false;
    foreach ($blocks as $k => $b) {
        if ($b['h2']) {
            if ($open) $out .= '</section>';
            $out .= '<section class="sec">' . $b['html'];
            $open = true;
        } else {
            $out .= ($k ? "\n" : '') . $b['html'];
        }
    }
    if ($open) $out .= '</section>';
    return $out;
}
