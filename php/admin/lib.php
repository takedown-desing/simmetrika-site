<?php
// Админка: описание полей (app/data/admin-config.json), отрисовка форм, разбор и проверка сохранённых данных,
// загрузка фото, пересчёт карты адресов и автопереадресаций. Подключается только из admin/index.php.
if (!defined('ADMIN')) { http_response_code(403); exit; }

const NOV = "\0nov"; // «поле не заполнено»
const SLUG_RE = '/^[a-z0-9]+(-[a-z0-9]+)*$/';
const URL_RE = '#^/([a-z0-9]+(-[a-z0-9]+)*/)*$#';
const RESERVED_URLS = ['/admin/', '/api/', '/go/', '/img/', '/images/', '/js/', '/css/', '/app/', '/install/', '/storage/'];
const PROTECTED_PAGES = ['home', '404', 'zayavka', 'zayavka-spasibo', 'privacy', 'catalog'];
const LEAD_FORM_NAMES = ['cart' => 'Корзина', 'one_click' => 'В один клик', 'callback' => 'Обратный звонок', 'question' => 'Вопрос', 'custom_size' => 'Диван по размерам', 'sample_reserve' => 'Бронь образца', 'dealer' => 'Дизайнеры и дилеры'];
const LEAD_STATUS = ['new' => 'Новая', 'work' => 'В работе', 'done' => 'Обработана', 'spam' => 'Спам'];

function acfg(): array
{
    static $c = null;
    if ($c === null) $c = json_decode((string)file_get_contents(APP . '/data/admin-config.json'), true) ?: ['collections' => []];
    return $c;
}
function col_def(string $name): ?array
{
    foreach (acfg()['collections'] as $c) if ($c['name'] === $name) return $c;
    return null;
}
function file_def(array $col, string $file): ?array
{
    foreach ($col['files'] ?? [] as $f) if ($f['name'] === $file) return $f;
    return null;
}
function fields_of(array $col, string $file = ''): array
{
    if (isset($col['files'])) return file_def($col, $file)['fields'] ?? [];
    return $col['fields'] ?? [];
}
function same_val($a, $b): bool
{
    if (is_bool($a) || is_bool($b)) return $a === $b;
    if (is_array($a) || is_array($b)) return $a == $b;
    return (string)$a === (string)$b;
}
// значения по умолчанию для новой записи (вместе с вложенными блоками)
function fill_defaults(array $fields): array
{
    $o = [];
    foreach ($fields as $f) {
        if (array_key_exists('default', $f)) $o[$f['name']] = $f['default'];
        elseif (($f['widget'] ?? '') === 'object') { $sub = fill_defaults($f['fields'] ?? []); if ($sub) $o[$f['name']] = $sub; }
    }
    return $o;
}
function is_required(array $f): bool { return ($f['required'] ?? true) !== false && !in_array($f['widget'] ?? '', ['boolean', 'hidden', 'object', 'list'], true); }

// ── записи в базе ──
function entry_get(string $col, string $file): ?array
{
    $st = db()->prepare('SELECT data, updated_at FROM entries WHERE collection = ? AND file = ?');
    $st->execute([$col, $file]);
    $r = $st->fetch();
    if (!$r) return null;
    $d = json_decode($r['data'], true);
    return ['data' => is_array($d) ? $d : [], 'updated_at' => $r['updated_at']];
}
function entry_put(string $col, string $file, array $data): void
{
    $json = json_encode($data === [] ? new stdClass() : $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    db()->prepare('INSERT INTO entries (collection, file, data) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')->execute([$col, $file, $json]);
}
function entry_list(string $col): array
{
    $st = db()->prepare('SELECT file, data, updated_at FROM entries WHERE collection = ? ORDER BY file');
    $st->execute([$col]);
    $out = [];
    foreach ($st as $r) { $d = json_decode($r['data'], true); $out[$r['file']] = ['data' => is_array($d) ? $d : [], 'updated_at' => $r['updated_at']]; }
    return $out;
}
function tpl(string $t, array $d, string $file): string
{
    return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function ($m) use ($d, $file) {
        $k = preg_replace('/^fields\./', '', $m[1]);
        if ($k === 'slug' && strpos($m[1], 'fields.') !== 0) return $file;
        $v = $d[$k] ?? '';
        return is_scalar($v) ? (string)$v : '';
    }, $t);
}
function entry_title(array $col, array $d, string $file): string
{
    $id = $col['identifier_field'] ?? 'title';
    foreach (['h1', $id, 'name', 'title'] as $k) if (is_string($d[$k] ?? null) && trim($d[$k]) !== '') return trim($d[$k]);
    return $file;
}

// ── источники значений для «связей» и списков выбора ──
function rel_options(array $f): array
{
    $out = [];
    $c = $f['collection'] ?? '';
    $disp = $f['display_fields'][0] ?? 'name';
    if ($c === 'settings') {
        $d = entry_get('settings', (string)($f['file'] ?? ''))['data'] ?? [];
        [$list, , $vk] = array_pad(explode('.', (string)$f['value_field']), 3, '');
        $dk = explode('.', (string)$disp)[2] ?? $vk;
        foreach ($d[$list] ?? [] as $it) {
            if (!is_array($it) || !isset($it[$vk]) || $it[$vk] === '') continue;
            $out[(string)$it[$vk]] = (string)($it[$dk] ?? $it[$vk]);
        }
        return $out;
    }
    foreach (entry_list($c) as $file => $e) {
        $lbl = '';
        foreach (array_merge($f['display_fields'] ?? [], $f['search_fields'] ?? [], ['name', 'h1']) as $k) if (is_string($e['data'][$k] ?? null) && $e['data'][$k] !== '') { $lbl = $e['data'][$k]; break; }
        $out[(string)$file] = $lbl !== '' ? $lbl : (string)$file;
    }
    asort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}
function select_options(array $f): array
{
    if (($f['widget'] ?? '') === 'relation') return rel_options($f);
    $out = [];
    foreach ($f['options'] ?? [] as $o) {
        if (is_array($o)) $out[(string)$o['value']] = (string)($o['label'] ?? $o['value']);
        else $out[(string)$o] = (string)$o;
    }
    return $out;
}

// ── отрисовка формы ──
function render_fields(array $fields, $data, string $prefix): string
{
    $data = is_array($data) ? $data : [];
    $h = '';
    foreach ($fields as $f) {
        if (($f['widget'] ?? '') === 'hidden') continue;
        $h .= render_field($f, array_key_exists($f['name'], $data) ? $data[$f['name']] : NOV, $prefix . '[' . $f['name'] . ']');
    }
    return $h;
}
function keep_input($data, string $prefix): string
{
    if (!is_array($data) || !$data) return '';
    return '<input type="hidden" name="' . h($prefix . '[__keep]') . '" value="' . h(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '">';
}
function field_label(array $f): string
{
    return '<span class="lbl">' . h($f['label'] ?? $f['name']) . (is_required($f) ? ' <i class="req" title="обязательное поле">*</i>' : '') . '</span>';
}
function field_hint(array $f): string
{
    $hint = (string)($f['hint'] ?? '');
    if ($hint === '') return '';
    if (mb_strlen($hint) > 220) return '<details class="hint"><summary>Подсказка</summary><div>' . h($hint) . '</div></details>';
    return '<small class="hint">' . h($hint) . '</small>';
}
function render_field(array $f, $v, string $name): string
{
    $w = $f['widget'] ?? 'string';
    $isNov = $v === NOV;
    if ($isNov) $v = $f['default'] ?? null;
    $n = h($name);
    $sv = is_scalar($v) ? (string)$v : '';
    if ($w === 'boolean') {
        $on = $v === true || $v === 'true';
        return '<div class="fld fld-bool"><label class="check"><input type="hidden" name="' . $n . '" value="0"><input type="checkbox" name="' . $n . '" value="1"' . ($on ? ' checked' : '') . '> ' . h($f['label'] ?? $f['name']) . '</label>' . field_hint($f) . '</div>';
    }
    if ($w === 'object') {
        $open = empty($f['collapsed']) || !$isNov;
        return '<details class="fld obj"' . ($open ? ' open' : '') . '><summary>' . h($f['label'] ?? $f['name']) . '</summary>' . field_hint($f) . '<div class="obj-body">'
            . render_fields($f['fields'] ?? [], is_array($v) ? $v : [], $name) . keep_input(is_array($v) ? $v : null, $name) . '</div></details>';
    }
    if ($w === 'list') {
        $tok = '__R' . (++$GLOBALS['__admin_tok']) . '__';
        $rows = is_array($v) ? array_values($v) : [];
        $h = '<div class="fld list" data-tok="' . $tok . '"><div class="list-head">' . field_label($f) . ' <span class="count">' . count($rows) . '</span></div>' . field_hint($f) . '<div class="rows">';
        foreach ($rows as $i => $row) $h .= list_row($f, $row, $name . '[' . $i . ']', !empty($f['collapsed']) && count($rows) > 3);
        $one = mb_strtolower((string)($f['label_singular'] ?? ($f['field']['label'] ?? 'пункт')));
        return $h . '</div><template>' . list_row($f, NOV, $name . '[' . $tok . ']', false) . '</template><button type="button" class="btn-add" data-add>+ Добавить ' . h($one) . '</button></div>';
    }
    $input = '';
    switch ($w) {
        case 'text':
            $big = in_array($f['name'], ['body', 'construction', 'legal'], true);
            $input = '<textarea name="' . $n . '" rows="' . ($big ? 16 : 3) . '"' . ($big ? ' class="mono"' : '') . ">\n" . h($sv) . '</textarea>';
            break;
        case 'number':
            $input = '<input type="number" step="any" inputmode="decimal" name="' . $n . '" value="' . h($sv) . '"' . (isset($f['min']) ? ' min="' . h($f['min']) . '"' : '') . (isset($f['max']) ? ' max="' . h($f['max']) . '"' : '') . '>';
            break;
        case 'datetime':
            $input = '<input type="date" name="' . $n . '" value="' . h(mb_substr($sv, 0, 10)) . '">';
            break;
        case 'color':
            $input = '<span class="color"><input type="text" name="' . $n . '" value="' . h($sv) . '" placeholder="#c8c8c8" data-color><input type="color" value="' . h(preg_match('/^#[0-9a-f]{6}$/i', $sv) ? $sv : '#c8c8c8') . '" tabindex="-1" aria-label="Выбрать цвет"></span>';
            break;
        case 'image':
            $pv = $sv !== '' ? (img($sv)['s'] ?? $sv) : '';
            $src = $pv !== '' && $pv[0] === '/' ? base() . $pv : $pv;
            $input = '<span class="img-field"><img alt="" src="' . h($src) . '"' . ($src === '' ? ' hidden' : '') . '><span class="img-ctl"><input type="text" name="' . $n . '" value="' . h($sv) . '" placeholder="/img/up/foto-1200.webp" data-image><button type="button" class="btn-sm" data-upload>Загрузить фото…</button></span></span>';
            break;
        case 'select':
        case 'relation':
            $opts = select_options($f);
            if (!empty($f['multiple'])) {
                $cur = array_map('strval', is_array($v) ? $v : ($sv !== '' ? [$sv] : []));
                $input = '<span class="checks">';
                foreach ($opts as $val => $lbl) $input .= '<label class="check"><input type="checkbox" name="' . $n . '[]" value="' . h($val) . '"' . (in_array((string)$val, $cur, true) ? ' checked' : '') . '> ' . h($lbl) . '</label>';
                foreach (array_diff($cur, array_map('strval', array_keys($opts))) as $miss) $input .= '<label class="check miss"><input type="checkbox" name="' . $n . '[]" value="' . h($miss) . '" checked> ' . h($miss) . ' (нет в списке)</label>';
                $input .= '</span>';
            } else {
                $input = '<select name="' . $n . '"><option value="">—</option>';
                foreach ($opts as $val => $lbl) $input .= '<option value="' . h($val) . '"' . ((string)$val === $sv ? ' selected' : '') . '>' . h($lbl) . '</option>';
                if ($sv !== '' && !array_key_exists($sv, $opts)) $input .= '<option value="' . h($sv) . '" selected>' . h($sv) . ' (нет в списке)</option>';
                $input .= '</select>';
            }
            break;
        default:
            $input = '<input type="text" name="' . $n . '" value="' . h($sv) . '"' . (isset($f['maxlength']) ? ' maxlength="' . (int)$f['maxlength'] . '"' : '') . '>';
    }
    $tag = $w === 'image' || $w === 'color' || (in_array($w, ['select', 'relation'], true) && !empty($f['multiple'])) ? 'div' : 'label';
    return '<' . $tag . ' class="fld fld-' . h($w) . '">' . field_label($f) . $input . field_hint($f) . '</' . $tag . '>';
}
function row_title(array $f, $row): string
{
    if (!is_array($row)) return is_scalar($row) && $row !== NOV ? (string)$row : '';
    foreach ($f['fields'] ?? [] as $sf) if (in_array($sf['widget'] ?? '', ['string', 'text'], true) && is_string($row[$sf['name']] ?? null) && trim($row[$sf['name']]) !== '') return trim($row[$sf['name']]);
    return '';
}
function list_row(array $f, $row, string $name, bool $collapsed): string
{
    $t = row_title($f, $row);
    $h = '<details class="row"' . ($collapsed ? '' : ' open') . '><summary><span class="row-title">' . h(mb_strimwidth($t !== '' ? $t : 'Новый пункт', 0, 90, '…')) . '</span>'
        . '<span class="row-ctl"><button type="button" data-up title="Выше">↑</button><button type="button" data-down title="Ниже">↓</button><button type="button" data-del title="Удалить пункт">✕</button></span></summary><div class="row-body">';
    if (isset($f['field'])) $h .= render_field(array_merge($f['field'], ['label' => $f['field']['label'] ?? '']), $row, $name);
    else $h .= render_fields($f['fields'] ?? [], is_array($row) ? $row : [], $name) . keep_input(is_array($row) ? $row : null, $name);
    return $h . '</div></details>';
}

// ── разбор присланной формы ──
function parse_obj(array $fields, $in, array $base, string $where, array &$errs): array
{
    $in = is_array($in) ? $in : [];
    if (isset($in['__keep'])) { $k = json_decode((string)$in['__keep'], true); if (is_array($k)) $base = $k; }
    $out = $base;
    foreach ($fields as $f) {
        if (($f['widget'] ?? '') === 'hidden') continue;
        $label = ($where === '' ? '' : $where . ' › ') . ($f['label'] ?? $f['name']);
        $absent = !array_key_exists($f['name'], $base);
        $v = parse_value($f, $in[$f['name']] ?? null, $absent ? NOV : $base[$f['name']], $label, $errs);
        // значение по умолчанию, которого в записи не было, не дописываем (сайт подставит его сам)
        if ($absent && $v !== NOV && array_key_exists('default', $f) && same_val($v, $f['default'])) { unset($out[$f['name']]); continue; }
        if ($v === NOV) {
            unset($out[$f['name']]);
            if (is_required($f)) $errs[] = "$label: обязательное поле не заполнено";
        } else $out[$f['name']] = $v;
    }
    return $out;
}
function parse_value(array $f, $raw, $old, string $label, array &$errs)
{
    $w = $f['widget'] ?? 'string';
    if ($w === 'object') {
        $o = parse_obj($f['fields'] ?? [], $raw, is_array($old) ? $old : [], $label, $errs);
        return $o ? $o : NOV;
    }
    if ($w === 'list') {
        $out = [];
        $i = 0;
        foreach (is_array($raw) ? $raw : [] as $row) {
            $i++;
            $e2 = [];
            if (isset($f['field'])) $v = parse_value($f['field'], $row, NOV, "$label, пункт $i", $e2);
            else { $v = parse_obj($f['fields'] ?? [], $row, [], "$label, пункт $i", $e2); if (!array_filter($v, fn($x) => $x !== false)) $v = NOV; }
            if ($v === NOV) continue; // пустой пункт просто убираем
            array_push($errs, ...$e2);
            $out[] = $v;
        }
        return $out ? $out : NOV;
    }
    if ($w === 'boolean') {
        if ($raw === null) return NOV;
        $on = $raw === '1' || (is_array($raw) && in_array('1', $raw, true));
        if (!$on && $old === NOV && !array_key_exists('default', $f)) return NOV; // не добавляем лишнее «нет»
        return $on;
    }
    if (in_array($w, ['select', 'relation'], true)) {
        $opts = select_options($f);
        if (!empty($f['multiple'])) {
            $vals = array_values(array_unique(array_filter(array_map('strval', is_array($raw) ? $raw : []), fn($x) => $x !== '')));
            if (!$vals) return NOV;
            $was = is_array($old) ? array_map('strval', $old) : [];
            foreach ($vals as $x) if (!array_key_exists($x, $opts) && !in_array($x, $was, true)) $errs[] = "$label: значения «{$x}» нет в списке, снимите галочку";
            if (is_array($old) && count($old) === count($vals) && !array_diff(array_map('strval', $old), $vals)) return $old; // тот же набор: порядок не трогаем
            return $vals;
        }
        $s = is_string($raw) ? trim($raw) : '';
        if ($s === '') return NOV;
        if (!array_key_exists($s, $opts) && $s !== $old) $errs[] = "$label: значения «{$s}» нет в списке, выберите другое";
        return $s;
    }
    $s = is_string($raw) ? str_replace("\r\n", "\n", $raw) : '';
    if (preg_match('/^\s*$/u', $s)) return NOV;
    if (!preg_match('//u', $s)) { $errs[] = "$label: текст в неверной кодировке"; return NOV; }
    switch ($w) {
        case 'number':
            $s = str_replace([' ', ','], ['', '.'], trim($s));
            if (!is_numeric($s)) { $errs[] = "$label: нужно число, а введено «{$s}»"; return NOV; }
            $num = preg_match('/^-?\d+$/', $s) ? (int)$s : (float)$s;
            if ($num < 0) $errs[] = "$label: число не может быть отрицательным";
            if (isset($f['min']) && $num < $f['min']) $errs[] = "$label: не меньше {$f['min']}";
            if (isset($f['max']) && $num > $f['max']) $errs[] = "$label: не больше {$f['max']}";
            return is_int($old) || is_float($old) ? ($old == $num ? $old : $num) : $num;
        case 'datetime':
            $s = trim($s);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $s) || !strtotime(substr($s, 0, 10))) $errs[] = "$label: дата в формате ГГГГ-ММ-ДД";
            return $s;
        case 'color':
            $s = trim($s);
            if (!preg_match('/^#[0-9a-f]{3,8}$/i', $s)) $errs[] = "$label: цвет в формате #a1b2c3";
            return $s;
        case 'image':
            $s = trim($s);
            if (preg_match('#^https?://#', $s) || strpos($s, 'source/') === 0) return $s;
            if ($s[0] !== '/') $errs[] = "$label: путь к фото должен начинаться с /";
            elseif (!is_file(ROOT . rawurldecode(parse_url($s, PHP_URL_PATH) ?: ''))) $errs[] = "$label: нет файла {$s}";
            return $s;
    }
    if (!empty($f['pattern'][0]) && !@preg_match('~' . str_replace('~', '\~', $f['pattern'][0]) . '~u', $s)) $errs[] = "$label: " . ($f['pattern'][1] ?? 'неверный формат');
    if (isset($f['maxlength']) && mb_strlen($s) > $f['maxlength']) $errs[] = "$label: длиннее {$f['maxlength']} знаков";
    return $s;
}

// ── проверки уровня сайта (как scripts/validate-cms.mjs) ──
function site_ctx(): array
{
    $live = ['/' => 1];
    foreach (cms()['pages'] as $p) { $u = $p['data']['url'] ?? null; if (truthy($u) && $p['col'] !== 'settings' && strpos(jsstr($u), '{') === false) $live[$u] = 1; }
    $C = C();
    return [
        'live' => $live, 'resolved' => redirect_list()['resolved'],
        'rule' => ['all' => null, 'category' => array_column($C['categories'], 'slug'), 'mechanism' => array_keys($C['mechanisms']), 'material' => array_column($C['fabrics'], 'material'),
            'color' => array_keys($C['colors']), 'tag' => ['dlya-gostinoy', 'dizaynerskie', 'bolshie'], 'showroom' => array_column($C['showrooms'], 'slug'),
            'in_stock' => 'bool', 'sample' => 'bool', 'hit' => 'bool', 'has_sleeping_place' => 'bool', 'has_storage' => 'bool', 'length_gte' => 'num'],
    ];
}
function link_ok(string $href, array $ctx): bool
{
    if ($href === '' || preg_match('/^(https?:|mailto:|tel:|#|callback$)/', $href)) return true;
    $p = explode('?', explode('#', $href)[0])[0];
    if ($p === '') return true;
    if (preg_match('/\.[a-z0-9]{2,5}$/i', $p)) return is_file(ROOT . rawurldecode($p)) || isset($ctx['resolved'][$p]);
    $n = norm_url($p);
    return isset($ctx['live'][$n]) || isset($ctx['resolved'][$n]);
}
function check_rule($rule, string $w, array $ctx, array &$errs): void
{
    if (!is_string($rule) || $rule === '' || $rule === 'all') return;
    foreach (explode(',', $rule) as $part) {
        $kv = array_map('trim', explode('=', $part, 2));
        $k = $kv[0]; $v = $kv[1] ?? 'true';
        if (!array_key_exists($k, $ctx['rule'])) { $errs[] = "$w: правило «{$rule}»: неизвестное условие «{$k}»"; continue; }
        $a = $ctx['rule'][$k];
        $ok = $a === null || ($a === 'num' ? preg_match('/^\d+$/', $v) : ($a === 'bool' ? in_array($v, ['true', 'false'], true) : (in_array($v, $a, true) || $v === '{slug}')));
        if (!$ok) $errs[] = "$w: правило «{$rule}»: значение «{$v}» для «{$k}» не найдено";
    }
}
const DIRECTIVES = ['products', 'filters', 'tiles', 'chips', 'spec-table', 'swatches', 'showrooms', 'map', 'news', 'promo', 'form', 'steps', 'stats', 'compare', 'scheme', 'cta', 'note', 'practice'];
function check_body(string $md, string $w, array $ctx, array &$errs): void
{
    if ($md === '') return;
    $depth = 0; $fence = false;
    foreach (explode("\n", $md) as $i => $line) {
        if (preg_match('/^\s*```/', $line)) $fence = !$fence;
        if ($fence) continue;
        $at = "$w, строка " . ($i + 1);
        if (preg_match('/^\s*(:{2,})([a-z][\w-]*)\s*(\{[^}]*\}?)?\s*$/i', $line, $m)) {
            $name = $m[2]; $raw = $m[3] ?? '';
            if (!in_array($name, DIRECTIVES, true)) $errs[] = "$at: неизвестная вставка «{$m[1]}{$name}»";
            if ($raw !== '' && substr($raw, -1) !== '}') $errs[] = "$at: вставка «{$name}»: не закрыта фигурная скобка";
            if (substr_count($raw, '"') % 2) $errs[] = "$at: вставка «{$name}»: непарные кавычки";
            preg_match_all('/([a-z][\w-]*)=(?:"([^"]*)"|\'([^\']*)\'|([^\s}]+))/i', $raw, $mm, PREG_SET_ORDER);
            $a = [];
            foreach ($mm as $x) $a[$x[1]] = ($x[2] ?? '') !== '' ? $x[2] : (($x[3] ?? '') !== '' ? $x[3] : ($x[4] ?? ''));
            if (in_array($name, ['products', 'spec-table'], true)) check_rule($a['rule'] ?? null, $at, $ctx, $errs);
            if (in_array($name, ['tiles', 'chips'], true)) {
                if (empty($a['items'])) $errs[] = "$at: вставка «{$name}»: не указан список адресов items=\"/адрес/,/адрес/\"";
                foreach (array_filter(array_map('trim', explode(',', (string)($a['items'] ?? '')))) as $it) if (strpos($it, '{') === false && !link_ok($it, $ctx)) $errs[] = "$at: вставка «{$name}»: страницы {$it} нет на сайте";
            }
            if ($name === 'form' && !empty($a['type']) && !in_array($a['type'], ['question', 'callback', 'custom_size', 'sample_reserve', 'dealer'], true)) $errs[] = "$at: формы «{$a['type']}» не существует";
            if ($name === 'cta' && !empty($a['href']) && $a['href'] !== 'callback' && !link_ok($a['href'], $ctx)) $errs[] = "$at: кнопка ведёт на несуществующую страницу {$a['href']}";
            if (strlen($m[1]) >= 3) $depth++;
        } elseif (preg_match('/^\s*:::\s*$/', $line)) {
            if ($depth === 0) $errs[] = "$at: лишняя закрывающая строка «:::»"; else $depth--;
        } elseif (preg_match('/^\s*:::/', $line)) $errs[] = "$at: строка вставки не распознана: «" . mb_substr(trim($line), 0, 60) . '»';
        preg_match_all('/\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $line, $lm);
        foreach ($lm[1] as $href) if (!link_ok($href, $ctx)) $errs[] = "$at: ссылка на несуществующую страницу {$href}";
    }
    if ($depth > 0) $errs[] = "$w: вставка не закрыта: не хватает строки «:::» ($depth шт.)";
}
// проверка одной записи после сохранения (внутри транзакции, данные уже записаны)
function check_entry(string $col, string $file, array $d): array
{
    $errs = [];
    $ctx = site_ctx();
    if ($col === 'settings') {
        if ($file === 'menu') {
            foreach (['nav', 'nav_right'] as $k) foreach ($d[$k] ?? [] as $it) if (!link_ok((string)($it['url'] ?? ''), $ctx)) $errs[] = "Меню › {$it['title']}: страницы {$it['url']} нет";
            foreach ($d['catalog_menu'] ?? [] as $c) foreach ($c['items'] ?? [] as $it) if (!link_ok((string)($it['url'] ?? ''), $ctx)) $errs[] = "Меню «Каталог» › {$c['title']} › {$it['title']}: страницы {$it['url']} нет";
        }
        if ($file === 'redirects') foreach ($d['redirects'] ?? [] as $r) {
            if (empty($r['from']) || empty($r['to'])) { $errs[] = 'Переадресации: пустая строка ' . ($r['from'] ?? '?') . ' → ' . ($r['to'] ?? '?'); continue; }
            if (!link_ok((string)$r['to'], $ctx)) $errs[] = "Переадресации › {$r['from']}: цель {$r['to']} не существует";
        }
        if ($file === 'fabrics') {
            foreach (['fabrics' => 'material', 'colors' => 'slug', 'mechanisms' => 'slug'] as $k => $key) {
                $seen = [];
                foreach ($d[$k] ?? [] as $x) { $v = $x[$key] ?? ''; if ($v === '') continue; if (isset($seen[$v])) $errs[] = "Ткани и цвета: код «{$v}» повторяется, у каждой записи свой код"; $seen[$v] = 1; }
            }
            $cols = array_column($d['colors'] ?? [], 'slug');
            foreach ($d['fabrics'] ?? [] as $f) foreach ($f['colors'] ?? [] as $c) if (!in_array($c, $cols, true)) $errs[] = "Материал «{$f['name']}»: цвет «{$c}» не найден в списке цветов";
        }
        if ($file === 'showrooms') {
            $seen = [];
            foreach ($d['showrooms'] ?? [] as $s) { $v = $s['slug'] ?? ''; if (isset($seen[$v])) $errs[] = "Шоурумы: код «{$v}» повторяется"; $seen[$v] = 1; }
        }
        return $errs;
    }
    $w = 'Запись';
    if (in_array($col, ['products', 'categories', 'news'], true) && isset($d['slug']) && !preg_match(SLUG_RE, (string)$d['slug'])) $errs[] = "Адрес «{$d['slug']}»: только строчная латиница, цифры и дефис (например, divan-milano)";
    if (in_array($col, ['collections', 'pages'], true) && !empty($d['url'])) {
        $u = norm_url((string)$d['url']);
        if (!preg_match(URL_RE, $u)) $errs[] = "Адрес «{$d['url']}»: только строчная латиница, цифры, дефис и слэши (например, /garantiya/)";
        foreach (RESERVED_URLS as $r) if (strpos($u, $r) === 0) $errs[] = "Адрес «{$d['url']}» занят служебным разделом";
    }
    if ($col === 'pages' && !empty($d['id']) && !preg_match(SLUG_RE, (string)$d['id'])) $errs[] = "Код страницы «{$d['id']}»: только латиница, цифры, дефис";
    if (in_array($col, ['pages', 'collections', 'news'], true) && !truthy($d['h1'] ?? null) && ($d['type'] ?? '') !== 'ui') $errs[] = 'Не заполнен заголовок H1';
    $mine = null; $seen = [];
    foreach (cms()['pages'] as $p) {
        $u = $p['data']['url'] ?? null;
        if (!truthy($u) || $p['col'] === 'settings' || strpos(jsstr($u), '{') !== false) continue;
        if ($p['col'] === $col && $p['file'] === $file) $mine = $p;
        $seen[$u][] = $p;
    }
    if ($mine) {
        $u = $mine['data']['url'];
        foreach ($seen[$u] as $o) if ($o['col'] !== $col || $o['file'] !== $file) $errs[] = "Адрес $u уже занят: «" . ($o['data']['h1'] ?? $o['file']) . '» (' . $o['col'] . '). У двух страниц не может быть одного адреса';
        $pd = $mine['data'];
        check_body((string)$mine['body'], 'Текст страницы', $ctx, $errs);
        foreach ($pd['related'] ?? [] as $r) if (!empty($r['url']) && !link_ok((string)$r['url'], $ctx)) $errs[] = "«Смотрите также»: страницы {$r['url']} нет";
        foreach ([$pd['hero_cta']['primary'] ?? null, $pd['hero_cta']['secondary'] ?? null] as $b) if (!empty($b['href']) && !link_ok((string)$b['href'], $ctx)) $errs[] = "Кнопка «" . ($b['label'] ?? '') . "»: страницы {$b['href']} нет";
        if (!empty($pd['canonical']) && !preg_match('/^https?:/', (string)$pd['canonical']) && !isset($ctx['live'][norm_url((string)$pd['canonical'])])) $errs[] = "Canonical ведёт на несуществующую страницу {$pd['canonical']}";
        if (in_array($pd['type'] ?? '', ['filter', 'collection'], true)) check_rule(is_string($pd['listing']['rule'] ?? null) ? $pd['listing']['rule'] : null, 'Правило подборки', $ctx, $errs);
    }
    return $errs;
}

// имя записи (как «файл» в прежней админке) для новой записи
function new_file_name(string $col, array $d): string
{
    switch ($col) {
        case 'products': return translit((string)($d['category'] ?? '')) . '--' . translit((string)($d['slug'] ?? ''));
        case 'pages': return translit((string)($d['id'] ?? ''));
        case 'collections':
            $u = trim(preg_replace('#^/catalog/#', '/', norm_url((string)($d['url'] ?? ''))), '/');
            return implode('--', array_map('translit', explode('/', $u)));
        default: return translit((string)($d['slug'] ?? ($d['name'] ?? '')));
    }
}

// после каждого сохранения: запоминаем адреса записей и автопереадресации (как scripts/save-cms-state.mjs)
function sync_url_state(): void
{
    cms_reset();
    $manual = [];
    foreach (cms()['redirects'] as $r) {
        if (!truthy($r['from'] ?? null)) continue;
        $f = js_trim((string)$r['from']);
        $manual[preg_match('/\.html?$/', $f) ? '/' . preg_replace('#^/#', '', $f) : norm_url($f)] = 1;
    }
    $auto = array_diff_key(redirect_list()['auto'], $manual);
    entry_put('settings', 'redirects-auto', $auto);
    entry_put('settings', 'url-map', url_map());
    cms_reset();
}
function clear_page_cache(): int
{
    $n = 0;
    foreach (glob(STORAGE . '/cache/*.html') ?: [] as $f) if (@unlink($f)) $n++;
    return $n;
}

// ── загрузка фото: WebP 1200 и 600 px в /img/up/ ──
function upload_image(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $map = [UPLOAD_ERR_INI_SIZE => 'файл больше, чем разрешает хостинг (upload_max_filesize)', UPLOAD_ERR_FORM_SIZE => 'файл слишком большой', UPLOAD_ERR_NO_FILE => 'файл не выбран'];
        throw new RuntimeException('Фото не загружено: ' . ($map[$file['error'] ?? 4] ?? 'ошибка загрузки №' . (int)($file['error'] ?? 0)));
    }
    if ($file['size'] > 20 * 1024 * 1024) throw new RuntimeException('Фото больше 20 МБ, уменьшите его');
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) throw new RuntimeException('Это не фото: подойдут JPG, PNG или WebP');
    if ($info[0] * $info[1] > 40000000) throw new RuntimeException('Фото слишком большое по пикселям (больше 40 Мп)');
    if (!function_exists('imagecreatefromstring')) throw new RuntimeException('На хостинге не включено расширение PHP GD: обратитесь к программисту');
    @ini_set('memory_limit', '512M');
    $im = @imagecreatefromstring((string)file_get_contents($file['tmp_name']));
    if (!$im) throw new RuntimeException('Не удалось прочитать фото');
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($file['tmp_name'])['Orientation'] ?? 1);
        $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($rot) { $r = imagerotate($im, $rot, 0); if ($r) $im = $r; }
    }
    $base = translit(pathinfo((string)$file['name'], PATHINFO_FILENAME));
    $base = substr($base !== '' ? $base : 'foto', 0, 40) . '-' . bin2hex(random_bytes(3));
    $dir = ROOT . '/img/up';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new RuntimeException('Нет папки img/up для фото: создайте её и дайте права на запись');
    if (!is_writable($dir)) throw new RuntimeException('Папка img/up закрыта для записи: дайте права 775');
    $webp = function_exists('imagewebp');
    $ext = $webp ? 'webp' : 'jpg';
    foreach ([1200, 600] as $W) {
        $w = imagesx($im); $hh = imagesy($im);
        $k = min(1, $W / $w, ($W * 4 / 3) / $hh);
        $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($hh * $k));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false); imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, $webp ? 127 : 0));
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $hh);
        $path = "$dir/$base-$W.$ext";
        $ok = $webp ? imagewebp($dst, $path, 82) : imagejpeg($dst, $path, 85);
        if (!$ok) throw new RuntimeException('Не удалось сохранить фото');
    }
    return ['path' => "/img/up/$base-1200.$ext", 'preview' => base() . "/img/up/$base-600.$ext"];
}
