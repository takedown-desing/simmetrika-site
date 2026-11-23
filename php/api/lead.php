<?php
// Приём заявок с форм сайта: POST JSON → таблица leads + письмо на mail_to. Ответ: {"ok":true,"no":"2026-000001"}
// Подключается из index.php по адресу /api/lead/.
if (!defined('ROOT')) { require dirname(__DIR__) . '/app/bootstrap.php'; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$fail = function (int $code, string $msg) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE); exit; };

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $fail(405, 'Только POST');
$raw = file_get_contents('php://input', false, null, 0, 65536);
$in = json_decode((string)$raw, true);
if (!is_array($in)) $fail(400, 'Неверный формат заявки');

const LEAD_FORMS = ['cart', 'one_click', 'callback', 'question', 'custom_size', 'sample_reserve', 'dealer'];
$clip = fn($v, int $n) => mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', is_scalar($v) ? (string)$v : '')), 0, $n);
$type = (string)($in['type'] ?? '');
if (!in_array($type, LEAD_FORMS, true)) $fail(400, 'Неизвестная форма');
$data = is_array($in['data'] ?? null) ? $in['data'] : [];
$phone = $clip($data['phone'] ?? '', 40);
$digits = preg_replace('/\D/', '', $phone);
if (strlen($digits) !== 11) $fail(422, 'Введите телефон полностью: 10 цифр после +7.');
if (empty($data['consent'])) $fail(422, 'Нужно согласие на обработку персональных данных.');
// поле-ловушка для ботов: люди его не видят и не заполняют
if (!empty($data['website'])) { echo json_encode(['ok' => true, 'no' => date('Y') . '-000000']); exit; }

$name = $clip($data['name'] ?? '', 120);
$email = $clip($data['email'] ?? '', 120);
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
$fields = [];
foreach (['channel' => 'Способ связи', 'length' => 'Длина, см', 'depth' => 'Глубина, см', 'model' => 'Модель', 'sample' => 'Образец', 'company' => 'Компания', 'comment' => 'Комментарий'] as $k => $label) {
    $v = $clip($data[$k] ?? '', $k === 'comment' ? 2000 : 200);
    if ($v !== '') $fields[$label] = $v;
}
$items = [];
foreach (array_slice(is_array($in['items'] ?? null) ? $in['items'] : [], 0, 20) as $it) {
    if (!is_array($it)) continue;
    $items[] = [
        'name' => $clip($it['name'] ?? '', 200), 'url' => $clip($it['url'] ?? '', 300), 'material' => $clip($it['material'] ?? '', 40),
        'color' => $clip($it['color'] ?? '', 40), 'side' => $clip($it['side'] ?? '', 10), 'sample' => !empty($it['sample']),
        'qty' => max(1, min(9, (int)($it['qty'] ?? 1))), 'price' => is_numeric($it['price'] ?? null) ? (int)round((float)$it['price']) : null,
    ];
}
$source = $clip($in['source'] ?? '', 300);
$utm = [];
foreach (is_array($in['utm'] ?? null) ? $in['utm'] : [] as $k => $v) if (preg_match('/^utm_[a-z_]{1,20}$/', (string)$k)) $utm[$k] = $clip($v, 200);
$ip = $clip($_SERVER['REMOTE_ADDR'] ?? '', 64);

try {
    $pdo = db();
    // защита от потока заявок: не больше 5 за 10 минут и 30 за сутки с одного адреса
    $st = $pdo->prepare('SELECT SUM(created_at > NOW() - INTERVAL 10 MINUTE) AS m10, COUNT(*) AS d1 FROM leads WHERE ip = ? AND created_at > NOW() - INTERVAL 1 DAY');
    $st->execute([$ip]);
    $rl = $st->fetch() ?: ['m10' => 0, 'd1' => 0];
    if ((int)$rl['m10'] >= 5 || (int)$rl['d1'] >= 30) $fail(429, 'Слишком много заявок подряд. Позвоните нам, пожалуйста.');

    $payload = json_encode(['fields' => $fields, 'items' => $items, 'utm' => $utm], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $message = '';
    foreach ($fields as $k => $v) $message .= "$k: $v\n";
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO leads (number, form, name, phone, email, message, page, payload, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['tmp-' . bin2hex(random_bytes(6)), $type, $name, $phone, $email, rtrim($message), $source, $payload, $ip]);
    $id = (int)$pdo->lastInsertId();
    $no = date('Y') . '-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE leads SET number = ? WHERE id = ?')->execute([$no, $id]);
    $pdo->commit();
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    @file_put_contents(STORAGE . '/logs/error.log', date('c') . ' lead: ' . $e->getMessage() . "\n", FILE_APPEND);
    $fail(500, 'Не удалось сохранить заявку');
}

// письмо менеджеру
$to = (string)cfg('mail_to');
if ($to !== '') {
    $forms = ['cart' => 'Заявка из корзины', 'one_click' => 'Заказ в один клик', 'callback' => 'Обратный звонок', 'question' => 'Вопрос фабрике', 'custom_size' => 'Диван по размерам', 'sample_reserve' => 'Бронь образца', 'dealer' => 'Дизайнеры и дилеры'];
    $mat = ['velyur' => 'велюр', 'rogozhka' => 'рогожка', 'zhakkard' => 'жаккард'];
    $t = ($forms[$type] ?? $type) . " № $no\n\nИмя: " . ($name ?: '—') . "\nТелефон: $phone\n" . ($email ? "Email: $email\n" : '');
    foreach ($fields as $k => $v) $t .= "$k: $v\n";
    if ($items) {
        $t .= "\nТовары:\n";
        $sum = 0;
        foreach ($items as $i => $it) {
            $opts = array_filter([$mat[$it['material']] ?? $it['material'], $it['color'], $it['side'] === 'left' ? 'левый угол' : ($it['side'] === 'right' ? 'правый угол' : ''), $it['sample'] ? 'выставочный образец' : '']);
            $t .= ($i + 1) . '. ' . $it['name'] . ($opts ? ' (' . implode(', ', $opts) . ')' : '') . ' × ' . $it['qty'] . ($it['price'] ? ', около ' . number_format($it['price'] * $it['qty'], 0, '', ' ') . ' ₽' : '') . "\n";
            if ($it['url'] !== '') $t .= '   ' . cfg('origin') . $it['url'] . "\n";
            $sum += (int)$it['price'] * $it['qty'];
        }
        if ($sum) $t .= 'Итого ориентировочно: ' . number_format($sum, 0, '', ' ') . " ₽\n";
    }
    $t .= "\nСтраница: " . cfg('origin') . $source . "\n";
    foreach ($utm as $k => $v) $t .= "$k: $v\n";
    $t .= "\nВсе заявки: " . cfg('origin') . base() . "/admin/?view=leads\n";
    $from = (string)cfg('mail_from');
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) $from = '';
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n"
        . ($from !== '' ? 'From: =?UTF-8?B?' . base64_encode('Сайт SIMMETRIKA') . "?= <$from>\r\n" : '')
        . ($email !== '' ? "Reply-To: $email\r\n" : '');
    $subject = '=?UTF-8?B?' . base64_encode(($forms[$type] ?? 'Заявка') . " № $no, $phone") . '?=';
    $ok = false;
    foreach (array_filter(array_map('trim', explode(',', $to))) as $addr) {
        $ok = @mail($addr, $subject, $t, $headers, $from !== '' ? '-f' . $from : '') || $ok;
    }
    if ($ok) { try { db()->prepare('UPDATE leads SET mailed = 1 WHERE id = ?')->execute([$id]); } catch (Throwable $e) {} }
}

echo json_encode(['ok' => true, 'no' => $no], JSON_UNESCAPED_UNICODE);
