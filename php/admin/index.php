<?php
// Админка сайта SIMMETRIKA: /admin/. Вход по логину и паролю из config.php, правка всех разделов сайта, фото, заявки.
define('ADMIN', 1);
require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, max-age=0');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$GLOBALS['__admin_tok'] = 0;
$A = base() . '/admin/';

// ── сессия ──
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$sdir = STORAGE . '/sessions';
if (!is_dir($sdir)) @mkdir($sdir, 0770, true);
if (is_dir($sdir) && is_writable($sdir)) session_save_path($sdir);
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', '28800');
session_name('simadm');
session_set_cookie_params(['lifetime' => 0, 'path' => $A, 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];

function au(array $q = []): string { return $GLOBALS['A'] . ($q ? '?' . http_build_query($q) : ''); }
function back(array $q = []): void { header('Location: ' . au($q), true, 303); exit; }
function flash(string $msg, string $kind = 'ok'): void { $_SESSION['flash'][] = [$kind, $msg]; }
function alog(string $msg): void { @file_put_contents(STORAGE . '/logs/admin.log', date('c') . ' ' . ($_SESSION['user'] ?? '-') . ' ' . ($_SERVER['REMOTE_ADDR'] ?? '') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX); }
function post_count($a): int { $n = 0; foreach ((array)$a as $v) $n += is_array($v) ? post_count($v) : 1; return $n; }

// ── оформление ──
function layout(string $title, string $body, string $active = ''): void
{
    global $A, $CSRF;
    header('Content-Type: text/html; charset=utf-8');
    $logged = !empty($_SESSION['user']);
    $nav = '';
    if ($logged) {
        $newLeads = 0;
        try { $newLeads = (int)db()->query("SELECT COUNT(*) FROM leads WHERE status = 'new'")->fetchColumn(); } catch (Throwable $e) {}
        $items = [['', 'Обзор', au()], ['leads', 'Заявки' . ($newLeads ? ' <b class="badge">' . $newLeads . '</b>' : ''), au(['view' => 'leads'])]];
        foreach (acfg()['collections'] as $c) if (!isset($c['files'])) $items[] = [$c['name'], h($c['label']), au(['c' => $c['name']])];
        $nav .= '<nav class="side"><a class="brand" href="' . h(au()) . '">SIMMETRIKA<small>админка сайта</small></a>';
        foreach ($items as [$k, $l, $u]) $nav .= '<a href="' . h($u) . '"' . ($active === $k ? ' class="on"' : '') . '>' . $l . '</a>';
        $nav .= '<span class="side-h">Настройки</span>';
        foreach (col_def('settings')['files'] ?? [] as $f) $nav .= '<a href="' . h(au(['c' => 'settings', 'f' => $f['name']])) . '"' . ($active === 'settings/' . $f['name'] ? ' class="on"' : '') . '>' . h($f['label']) . '</a>';
        $nav .= '<span class="side-h"></span><a href="' . h(base() . '/') . '" target="_blank" rel="noopener">Открыть сайт ↗</a>'
            . '<form method="post" action="' . h(au()) . '" class="logout"><input type="hidden" name="csrf" value="' . h($CSRF) . '"><button name="action" value="logout">Выйти</button></form></nav>';
    }
    $fl = '';
    foreach ($_SESSION['flash'] ?? [] as [$k, $m]) $fl .= '<div class="flash ' . h($k) . '">' . h($m) . '</div>';
    unset($_SESSION['flash']);
    $v = @filemtime(__DIR__ . '/admin.js') ?: 1;
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
        . '<title>' . h($title) . ' · админка SIMMETRIKA</title><link rel="icon" href="' . h(base()) . '/favicon.svg"><link rel="stylesheet" href="admin.css?v=' . $v . '"></head>'
        . '<body class="' . ($logged ? 'in' : 'out') . '" data-base="' . h(base()) . '" data-csrf="' . h($CSRF) . '" data-admin="' . h($A) . '">'
        . ($logged ? '<button type="button" class="menu-btn" data-menu aria-label="Меню">☰ Меню</button>' : '') . $nav . '<main>' . $fl . $body . '</main><script src="admin.js?v=' . $v . '"></script></body></html>';
    exit;
}
function not_found(string $msg = 'Такой записи нет'): void
{
    http_response_code(404);
    layout('Не найдено', '<h1>Не найдено</h1><p>' . h($msg) . '. <a href="' . h(au()) . '">На главную админки</a></p>');
}

// ── вход ──
function login_fails(?string $ip = null, bool $add = false): int
{
    $file = STORAGE . '/logs/login-fails.json';
    $all = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    $now = time();
    foreach ($all as $k => $ts) { $all[$k] = array_values(array_filter((array)$ts, fn($t) => $t > $now - 900)); if (!$all[$k]) unset($all[$k]); }
    if ($add && $ip !== null) $all[$ip][] = $now;
    if ($add || count($all) < 1000) @file_put_contents($file, json_encode($all), LOCK_EX);
    return $ip !== null ? count($all[$ip] ?? []) : 0;
}
function login_page(string $err = ''): void
{
    global $CSRF;
    $noHash = (string)cfg('admin_pass_hash') === '';
    $body = '<form method="post" action="' . h(au()) . '" class="login"><h1>Вход в админку</h1>'
        . ($noHash ? '<p class="flash err">Пароль ещё не задан. Программисту: выполнить <code>php install/make-password.php \'пароль\'</code> и вписать хэш в config.php.</p>' : '')
        . ($err !== '' ? '<p class="flash err">' . h($err) . '</p>' : '')
        . '<input type="hidden" name="csrf" value="' . h($CSRF) . '"><input type="hidden" name="action" value="login"><input type="hidden" name="to" value="' . h($_POST['to'] ?? ($_SERVER['QUERY_STRING'] ?? '')) . '">'
        . '<label class="fld"><span class="lbl">Логин</span><input name="user" autocomplete="username" required value="' . h($_POST['user'] ?? '') . '"></label>'
        . '<label class="fld"><span class="lbl">Пароль</span><input type="password" name="pass" autocomplete="current-password" required></label>'
        . '<button class="btn" type="submit">Войти</button></form>';
    layout('Вход', $body);
}

$act = (string)($_POST['action'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($isPost && !hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) {
    if ($act === 'upload') { header('Content-Type: application/json; charset=utf-8'); http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Сессия устарела: обновите страницу'], JSON_UNESCAPED_UNICODE); exit; }
    if ($isPost && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) { flash('Данные не дошли: форма или файл больше, чем разрешает хостинг (post_max_size).', 'err'); back(); }
    flash('Страница устарела. Повторите действие.', 'err');
    back();
}
if ($act === 'login') {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (login_fails($ip) >= 5) login_page('Слишком много неудачных попыток. Подождите 15 минут.');
    $hash = (string)cfg('admin_pass_hash');
    $okUser = hash_equals((string)cfg('admin_user'), (string)($_POST['user'] ?? ''));
    $okPass = $hash !== '' && password_verify((string)($_POST['pass'] ?? ''), $hash);
    if (!$okUser || !$okPass) { login_fails($ip, true); alog('login failed'); login_page('Неверный логин или пароль'); }
    session_regenerate_id(true);
    $_SESSION['user'] = (string)cfg('admin_user');
    $_SESSION['seen'] = time();
    alog('login');
    parse_str((string)($_POST['to'] ?? ''), $to);
    back(array_intersect_key($to, ['c' => 1, 'f' => 1, 'view' => 1, 'id' => 1]));
}
if (empty($_SESSION['user']) || time() - (int)($_SESSION['seen'] ?? 0) > 28800) {
    unset($_SESSION['user']);
    if ($act === 'upload') { header('Content-Type: application/json; charset=utf-8'); http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Вы вышли из админки: войдите снова'], JSON_UNESCAPED_UNICODE); exit; }
    login_page();
}
$_SESSION['seen'] = time();

// ── действия ──
if ($act === 'logout') { alog('logout'); $_SESSION = []; session_destroy(); header('Location: ' . $A, true, 303); exit; }

if ($act === 'upload') {
    header('Content-Type: application/json; charset=utf-8');
    ob_start(); // предупреждения PHP на хостинге не должны ломать ответ в JSON
    try {
        $r = upload_image($_FILES['photo'] ?? []);
        alog('upload ' . $r['path']);
        ob_end_clean();
        echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        if (ob_get_level()) ob_end_clean();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Ошибка обработки фото'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($act === 'lead_status') {
    $id = (int)($_POST['id'] ?? 0);
    $st = (string)($_POST['status'] ?? '');
    if (isset(LEAD_STATUS[$st])) { db()->prepare('UPDATE leads SET status = ? WHERE id = ?')->execute([$st, $id]); flash('Статус заявки: ' . LEAD_STATUS[$st]); }
    back(array_filter(['view' => 'leads', 'id' => $_POST['back_id'] ?? null, 'status' => $_POST['back_status'] ?? null, 'p' => $_POST['back_p'] ?? null]));
}

if ($act === 'delete') {
    $c = (string)($_POST['c'] ?? '');
    $f = (string)($_POST['file'] ?? '');
    $def = col_def($c);
    if (!$def || isset($def['files']) || !entry_get($c, $f)) not_found();
    if ($c === 'pages' && in_array($f, PROTECTED_PAGES, true)) { flash('Эту служебную страницу удалить нельзя, её можно только скрыть или изменить.', 'err'); back(['c' => $c, 'f' => $f]); }
    if ($c === 'categories') {
        $n = 0;
        foreach (entry_list('products') as $p) if (($p['data']['category'] ?? '') === $f) $n++;
        if ($n) { flash("В категории ещё $n " . plural($n, 'товар', 'товара', 'товаров') . '. Сначала перенесите их в другую категорию или удалите.', 'err'); back(['c' => $c, 'f' => $f]); }
    }
    db()->prepare('DELETE FROM entries WHERE collection = ? AND file = ?')->execute([$c, $f]);
    sync_url_state();
    clear_page_cache();
    alog("delete $c/$f");
    flash('Запись удалена. Если у неё был адрес, он автоматически переадресуется на раздел выше.');
    back(['c' => $c]);
}

$saveErrs = [];
$posted = null;
if ($act === 'save') {
    $c = (string)($_POST['c'] ?? '');
    $f = (string)($_POST['file'] ?? '');
    $isNew = !empty($_POST['new']);
    $def = col_def($c);
    if (!$def) not_found();
    $isSettings = isset($def['files']);
    if ($isSettings && !file_def($def, $f)) not_found();
    $fields = fields_of($def, $f);
    $old = $isNew ? null : entry_get($c, $f);
    if (!$isNew && !$old && !$isSettings) not_found('Запись удалена или переименована');
    $max = (int)ini_get('max_input_vars');
    if ($max > 0 && post_count($_POST) >= $max) $saveErrs[] = "Форма слишком большая для настроек хостинга (max_input_vars = $max). Сохранение отменено, чтобы ничего не потерять. Программисту: увеличить max_input_vars до 5000.";
    if (!$isNew && $old && (string)($_POST['stamp'] ?? '') !== (string)$old['updated_at']) $saveErrs[] = 'Эту запись уже изменили в другой вкладке или другой человек (' . $old['updated_at'] . '). Откройте запись заново, чтобы не затереть чужие правки.';
    $base0 = $isNew ? fill_defaults($fields) : ($old['data'] ?? []);
    $data = parse_obj($fields, $_POST['f'] ?? [], $base0, '', $saveErrs);
    // сохранили без правок (отличия только в пустых значениях) — оставляем запись как была, дату не трогаем
    if (!$isNew && $old && prune_obj($data) == prune_obj($old['data'])) $data = $old['data'];
    if (!$isNew && $old && $data != $old['data'] && array_key_exists('date_modified', $data) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$data['date_modified'])) $data['date_modified'] = date('Y-m-d');
    $file = $f;
    if ($isNew) {
        $file = new_file_name($c, $data);
        // пустой адрес уже отмечен как незаполненное обязательное поле — второе сообщение не нужно
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*(--[a-z0-9]+(-[a-z0-9]+)*)*$/', $file)) { if (!$saveErrs) $saveErrs[] = 'Заполните адрес (код) записи латиницей: из него получается имя записи'; }
        elseif (entry_get($c, $file)) $saveErrs[] = "Запись «{$file}» уже есть. Измените адрес или код.";
    }
    if (!$saveErrs) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            entry_put($c, $file, $data);
            cms_reset();
            $saveErrs = check_entry($c, $file, $data);
        } catch (Throwable $e) {
            $saveErrs[] = 'Ошибка при проверке: ' . $e->getMessage();
            @file_put_contents(STORAGE . '/logs/error.log', date('c') . ' admin save: ' . $e . "\n", FILE_APPEND);
        }
        if ($saveErrs) { $pdo->rollBack(); cms_reset(); }
        else {
            $pdo->commit();
            sync_url_state();
            clear_page_cache();
            alog(($isNew ? 'create' : 'save') . " $c/$file");
            flash($isNew ? 'Запись создана и уже на сайте.' : 'Сохранено. Изменения уже на сайте.');
            back(['c' => $c, 'f' => $file]);
        }
    }
    $posted = ['c' => $c, 'f' => $f, 'new' => $isNew, 'data' => $data, 'stamp' => (string)($_POST['stamp'] ?? '')];
}
if ($isPost && $posted === null) back();

// ── страницы админки ──
$view = (string)($_GET['view'] ?? '');
$c = (string)($posted['c'] ?? ($_GET['c'] ?? ''));
$f = (string)($posted['f'] ?? ($_GET['f'] ?? ''));

if ($view === 'leads') {
    $id = (int)($_GET['id'] ?? 0);
    $statusSel = function (array $l, array $keep) use ($CSRF): string {
        $h = '<form method="post" class="inline"><input type="hidden" name="csrf" value="' . h($CSRF) . '"><input type="hidden" name="action" value="lead_status"><input type="hidden" name="id" value="' . (int)$l['id'] . '">';
        foreach ($keep as $k => $v) if ($v !== null && $v !== '') $h .= '<input type="hidden" name="back_' . h($k) . '" value="' . h($v) . '">';
        $h .= '<select name="status" data-autosubmit aria-label="Статус">';
        foreach (LEAD_STATUS as $k => $lbl) $h .= '<option value="' . $k . '"' . ($l['status'] === $k ? ' selected' : '') . '>' . $lbl . '</option>';
        return $h . '</select><noscript><button>OK</button></noscript></form>';
    };
    if ($id) {
        $st = db()->prepare('SELECT * FROM leads WHERE id = ?');
        $st->execute([$id]);
        $l = $st->fetch();
        if (!$l) not_found('Заявки нет');
        $p = json_decode((string)$l['payload'], true) ?: [];
        $rows = [['Номер', $l['number']], ['Дата', date('d.m.Y H:i', strtotime($l['created_at']))], ['Форма', LEAD_FORM_NAMES[$l['form']] ?? $l['form']], ['Имя', $l['name'] ?: '—'],
            ['Телефон', '<a href="tel:+' . h(preg_replace('/\D/', '', $l['phone'])) . '">' . h($l['phone']) . '</a>'], ['Email', $l['email'] !== '' ? '<a href="mailto:' . h($l['email']) . '">' . h($l['email']) . '</a>' : '—']];
        foreach ($p['fields'] ?? [] as $k => $v) $rows[] = [$k, nl2br(h($v))];
        $rows[] = ['Страница', $l['page'] !== '' ? '<a href="' . h(base() . $l['page']) . '" target="_blank" rel="noopener">' . h($l['page']) . '</a>' : '—'];
        foreach ($p['utm'] ?? [] as $k => $v) $rows[] = [$k, $v];
        $rows[] = ['Письмо', $l['mailed'] ? 'отправлено на ' . h((string)cfg('mail_to')) : 'не отправлено (смотрите заявку здесь)'];
        $b = '<p class="crumbs"><a href="' . h(au(['view' => 'leads'])) . '">← Все заявки</a></p><h1>Заявка № ' . h($l['number']) . '</h1><div class="lead-status">Статус: ' . $statusSel($l, ['id' => $id]) . '</div><table class="kv">';
        foreach ($rows as [$k, $v]) $b .= '<tr><th>' . h($k) . '</th><td>' . (in_array($k, ['Телефон', 'Email', 'Страница', 'Письмо'], true) || isset($p['fields'][$k]) ? $v : h($v)) . '</td></tr>';
        $b .= '</table>';
        if (!empty($p['items'])) {
            $mat = [];
            foreach (C()['fabrics'] as $fb) $mat[$fb['material']] = $fb['name'];
            $b .= '<h2>Товары</h2><table class="list"><thead><tr><th>Товар</th><th>Вариант</th><th>Кол-во</th><th>Цена, около</th></tr></thead><tbody>';
            $sum = 0;
            foreach ($p['items'] as $it) {
                $opt = array_filter([$mat[$it['material'] ?? ''] ?? ($it['material'] ?? ''), C()['colors'][$it['color'] ?? ''] ?? ($it['color'] ?? ''), ($it['side'] ?? '') === 'left' ? 'левый угол' : (($it['side'] ?? '') === 'right' ? 'правый угол' : ''), !empty($it['sample']) ? 'выставочный образец' : '']);
                $sum += (int)($it['price'] ?? 0) * (int)($it['qty'] ?? 1);
                $b .= '<tr><td>' . (!empty($it['url']) ? '<a href="' . h(base() . $it['url']) . '" target="_blank" rel="noopener">' . h($it['name'] ?? '') . '</a>' : h($it['name'] ?? '')) . '</td><td>' . h(implode(', ', $opt)) . '</td><td>' . (int)($it['qty'] ?? 1) . '</td><td>' . (!empty($it['price']) ? h(rub((int)$it['price'] * (int)($it['qty'] ?? 1))) : '—') . '</td></tr>';
            }
            $b .= '</tbody></table>' . ($sum ? '<p><b>Итого ориентировочно: ' . h(rub($sum)) . '</b></p>' : '');
        }
        layout('Заявка ' . $l['number'], $b, 'leads');
    }
    $status = (string)($_GET['status'] ?? '');
    $page = max(1, (int)($_GET['p'] ?? 1));
    $per = 50;
    $where = isset(LEAD_STATUS[$status]) ? 'WHERE status = ' . db()->quote($status) : '';
    $total = (int)db()->query("SELECT COUNT(*) FROM leads $where")->fetchColumn();
    $list = db()->query("SELECT * FROM leads $where ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per))->fetchAll();
    $b = '<h1>Заявки</h1><p class="muted">Заявки со всех форм сайта. Каждая приходит и на почту ' . h((string)cfg('mail_to') ?: '(почта не указана в config.php)') . '.</p><p class="tabs">';
    foreach (['' => 'Все'] + LEAD_STATUS as $k => $lbl) $b .= '<a href="' . h(au(array_filter(['view' => 'leads', 'status' => $k]))) . '"' . ($status === $k ? ' class="on"' : '') . '>' . $lbl . '</a>';
    $b .= '</p>';
    if (!$list) $b .= '<p class="empty">Заявок пока нет.</p>';
    else {
        $b .= '<div class="tbl"><table class="list leads"><thead><tr><th>№</th><th>Дата</th><th>Форма</th><th>Имя и телефон</th><th>Что</th><th>Статус</th></tr></thead><tbody>';
        foreach ($list as $l) {
            $p = json_decode((string)$l['payload'], true) ?: [];
            $what = implode(', ', array_map(fn($it) => ($it['name'] ?? '') . ((int)($it['qty'] ?? 1) > 1 ? ' × ' . (int)$it['qty'] : ''), $p['items'] ?? []));
            if ($what === '') $what = mb_strimwidth(str_replace("\n", '; ', (string)$l['message']), 0, 90, '…');
            $b .= '<tr class="st-' . h($l['status']) . '"><td><a href="' . h(au(['view' => 'leads', 'id' => $l['id']])) . '">' . h($l['number']) . '</a></td><td>' . date('d.m.Y H:i', strtotime($l['created_at'])) . '</td><td>' . h(LEAD_FORM_NAMES[$l['form']] ?? $l['form']) . '</td>'
                . '<td>' . h($l['name'] ?: '—') . '<br><a href="tel:+' . h(preg_replace('/\D/', '', $l['phone'])) . '">' . h($l['phone']) . '</a></td><td>' . h($what) . '</td><td>' . $statusSel($l, ['status' => $status, 'p' => $page > 1 ? $page : null]) . '</td></tr>';
        }
        $b .= '</tbody></table></div>';
        if ($total > $per) {
            $b .= '<p class="pager">';
            for ($i = 1; $i <= (int)ceil($total / $per); $i++) $b .= '<a href="' . h(au(array_filter(['view' => 'leads', 'status' => $status, 'p' => $i > 1 ? $i : null]))) . '"' . ($i === $page ? ' class="on"' : '') . '>' . $i . '</a>';
            $b .= '</p>';
        }
    }
    layout('Заявки', $b, 'leads');
}

if ($c === '') {
    $b = '<h1>Админка сайта SIMMETRIKA</h1><div class="cards">';
    $cnt = [];
    foreach (db()->query('SELECT collection, COUNT(*) n FROM entries GROUP BY collection') as $r) $cnt[$r['collection']] = (int)$r['n'];
    $newLeads = (int)db()->query("SELECT COUNT(*) FROM leads WHERE status = 'new'")->fetchColumn();
    $b .= '<a class="card" href="' . h(au(['view' => 'leads'])) . '"><b>' . $newLeads . '</b><span>' . plural($newLeads, 'новая заявка', 'новые заявки', 'новых заявок') . '</span></a>';
    foreach (acfg()['collections'] as $col) if (!isset($col['files'])) $b .= '<a class="card" href="' . h(au(['c' => $col['name']])) . '"><b>' . ($cnt[$col['name']] ?? 0) . '</b><span>' . h($col['label']) . '</span></a>';
    $b .= '</div><h2>Как это работает</h2><ul class="howto">'
        . '<li>Слева разделы сайта. Откройте раздел, выберите запись, поправьте поля и нажмите «Сохранить». Изменение сразу появляется на сайте.</li>'
        . '<li>Поля со звёздочкой обязательные. Если что-то заполнено неверно, админка не сохранит запись и покажет, что исправить.</li>'
        . '<li>Фото: кнопка «Загрузить фото…» рядом с полем. Подходят JPG, PNG и WebP, админка сама уменьшит фото до 1200 px.</li>'
        . '<li>Если поменять адрес страницы или товара, старый адрес сам переадресуется на новый.</li>'
        . '<li>Скрыть товар или страницу, не удаляя: галочка «Скрыть с сайта».</li></ul>';
    $last = db()->query('SELECT collection, file, updated_at FROM entries WHERE collection <> \'settings\' ORDER BY updated_at DESC LIMIT 8')->fetchAll();
    if ($last) {
        $b .= '<h2>Последние изменения</h2><ul class="recent">';
        foreach ($last as $r) {
            $def = col_def($r['collection']);
            if (!$def) continue;
            $e = entry_get($r['collection'], $r['file']);
            $b .= '<li><a href="' . h(au(['c' => $r['collection'], 'f' => $r['file']])) . '">' . h(entry_title($def, $e['data'] ?? [], $r['file'])) . '</a> <span class="muted">' . h($def['label_singular'] ?? $def['label']) . ', ' . date('d.m.Y H:i', strtotime($r['updated_at'])) . '</span></li>';
        }
        $b .= '</ul>';
    }
    layout('Обзор', $b, '');
}

$def = col_def($c);
if (!$def) not_found('Нет такого раздела');
$isSettings = isset($def['files']);

// список записей раздела
if (!$isSettings && $f === '' && empty($_GET['new'])) {
    $rows = entry_list($c);
    $catNames = [];
    foreach (entry_list('categories') as $cf => $ce) $catNames[$cf] = ['name' => $ce['data']['name'] ?? $cf, 'order' => $ce['data']['order'] ?? 999];
    $items = [];
    foreach ($rows as $file => $e) {
        $d = $e['data'];
        $items[] = ['file' => $file, 'd' => $d, 'title' => entry_title($def, $d, $file), 'sub' => tpl((string)($def['summary'] ?? ''), $d, $file), 'upd' => $e['updated_at'],
            'group' => $c === 'products' ? (string)($d['category'] ?? '') : '', 'sort' => $c === 'news' ? (string)($d['date'] ?? '') : ''];
    }
    if ($c === 'products') usort($items, fn($a, $b) => [($catNames[$a['group']]['order'] ?? 999), $a['group'], $a['d']['order'] ?? 9999, $a['title']] <=> [($catNames[$b['group']]['order'] ?? 999), $b['group'], $b['d']['order'] ?? 9999, $b['title']]);
    elseif ($c === 'news') usort($items, fn($a, $b) => strcmp($b['sort'], $a['sort']));
    elseif ($c === 'categories') usort($items, fn($a, $b) => [$a['d']['order'] ?? 999, $a['title']] <=> [$b['d']['order'] ?? 999, $b['title']]);
    else usort($items, fn($a, $b) => strcmp((string)($a['d']['url'] ?? $a['file']), (string)($b['d']['url'] ?? $b['file'])));
    $b = '<div class="head"><h1>' . h($def['label']) . '</h1><a class="btn" href="' . h(au(['c' => $c, 'new' => 1])) . '">+ ' . h($def['label_singular'] ?? 'Запись') . '</a></div>'
        . (!empty($def['description']) ? '<p class="muted">' . h($def['description']) . '</p>' : '')
        . '<input type="search" class="filter" placeholder="Поиск по названию или адресу" data-filter aria-label="Поиск">';
    $group = null;
    $b .= '<div class="tbl"><table class="list" data-list><tbody>';
    foreach ($items as $it) {
        if ($c === 'products' && $it['group'] !== $group) {
            $group = $it['group'];
            $b .= '<tr class="grp"><th colspan="3">' . h($catNames[$group]['name'] ?? ($group !== '' ? $group : 'Без категории')) . '</th></tr>';
        }
        $hidden = !empty($it['d']['hidden']);
        $price = $c === 'products' ? (isset($it['d']['price_from']) && $it['d']['price_from'] !== '' ? 'от ' . rub($it['d']['price_from']) : 'цена по запросу') : '';
        $b .= '<tr' . ($hidden ? ' class="is-hidden"' : '') . '><td><a href="' . h(au(['c' => $c, 'f' => $it['file']])) . '">' . h($it['title']) . '</a>' . ($hidden ? ' <span class="tag">скрыто</span>' : '')
            . '<br><small class="muted">' . h($c === 'products' ? $price : $it['sub']) . '</small></td><td class="nowrap muted">' . date('d.m.Y', strtotime($it['upd'])) . '</td>'
            . '<td class="nowrap">' . ($hidden ? '' : '<a href="' . h(base() . '/go/' . $c . '/' . rawurlencode($it['file']) . '/') . '" target="_blank" rel="noopener" title="Открыть на сайте">на сайте ↗</a>') . '</td></tr>';
    }
    $b .= '</tbody></table></div>';
    layout($def['label'], $b, $c);
}

// форма записи
$fields = fields_of($def, $f);
if ($isSettings && !$fields) not_found('Нет такого раздела настроек');
$isNew = $posted ? $posted['new'] : (!$isSettings && !empty($_GET['new']));
if ($posted) { $data = $posted['data']; $stamp = $posted['stamp']; }
elseif ($isNew) { $data = fill_defaults($fields); $stamp = ''; }
else {
    $e = entry_get($c, $f);
    if (!$e && !$isSettings) not_found();
    $data = $e['data'] ?? [];
    $stamp = (string)($e['updated_at'] ?? '');
}
$fd = $isSettings ? file_def($def, $f) : null;
$title = $isSettings ? $fd['label'] : ($isNew ? 'Новая запись: ' . mb_strtolower($def['label_singular'] ?? '') : entry_title($def, $data, $f));
$b = '<p class="crumbs">' . ($isSettings ? 'Настройки' : '<a href="' . h(au(['c' => $c])) . '">← ' . h($def['label']) . '</a>') . '</p>'
    . '<div class="head"><h1>' . h($title) . '</h1>' . (!$isSettings && !$isNew && empty($data['hidden']) ? '<a class="btn ghost" href="' . h(base() . '/go/' . $c . '/' . rawurlencode($f) . '/') . '" target="_blank" rel="noopener">Открыть на сайте ↗</a>' : '') . '</div>';
if (!empty($fd['description'] ?? $def['description'] ?? '') && ($isSettings || $isNew)) $b .= '<p class="muted">' . h($isSettings ? ($fd['description'] ?? '') : $def['description']) . '</p>';
if ($saveErrs) {
    $b .= '<div class="flash err"><b>Не сохранено. Исправьте:</b><ul>';
    foreach (array_unique($saveErrs) as $er) $b .= '<li>' . h($er) . '</li>';
    $b .= '</ul></div>';
}
$b .= '<form method="post" action="' . h(au(array_filter(['c' => $c, 'f' => $isNew ? null : $f, 'new' => $isNew ? 1 : null]))) . '" class="edit" data-edit novalidate>'
    . '<input type="hidden" name="csrf" value="' . h($CSRF) . '"><input type="hidden" name="action" value="save"><input type="hidden" name="c" value="' . h($c) . '"><input type="hidden" name="file" value="' . h($f) . '">'
    . '<input type="hidden" name="stamp" value="' . h($stamp) . '">' . ($isNew ? '<input type="hidden" name="new" value="1">' : '')
    . render_fields($fields, $data, 'f')
    . '<div class="savebar"><button class="btn" type="submit">Сохранить</button><span class="muted">Ctrl+S</span></div></form>';
if (!$isSettings && !$isNew) {
    $prot = $c === 'pages' && in_array($f, PROTECTED_PAGES, true);
    $b .= '<details class="danger"><summary>Удаление</summary>' . ($prot ? '<p>Это служебная страница сайта, её нельзя удалить.</p>'
        : '<p>Запись исчезнет с сайта, старый адрес будет переадресован на раздел выше. Чтобы временно убрать запись, лучше поставьте галочку «Скрыть с сайта».</p>'
        . '<form method="post" action="' . h(au()) . '" data-confirm="Удалить «' . h($title) . '» насовсем?"><input type="hidden" name="csrf" value="' . h($CSRF) . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="c" value="' . h($c) . '"><input type="hidden" name="file" value="' . h($f) . '"><button class="btn red">Удалить запись</button></form>') . '</details>';
}
layout($title, $b, $isSettings ? 'settings/' . $f : $c);
