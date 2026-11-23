<?php
// Первичная загрузка данных сайта в MySQL.
// Запуск из папки сайта:  php install/import.php            — создать таблицы и залить данные из install/data/
//                         php install/import.php --force    — перезаписать уже существующие записи
// Без --force существующие записи (уже исправленные в админке) не трогаются.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Только из командной строки\n"); }
require dirname(__DIR__) . '/app/bootstrap.php';

$force = in_array('--force', $argv, true);
$src = $argv[1] ?? null;
if ($src === null || $src[0] === '-') $src = __DIR__ . '/data';
if (!is_dir($src)) exit("Нет папки с данными: $src\n");

$pdo = db();
foreach (array_filter(array_map('trim', explode(';', file_get_contents(__DIR__ . '/schema.sql')))) as $sql) {
    if (preg_match('/^\s*(CREATE|SET)/mi', $sql)) $pdo->exec($sql);
}
$sql = $force
    ? 'INSERT INTO entries (collection, file, data) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)'
    : 'INSERT IGNORE INTO entries (collection, file, data) VALUES (?, ?, ?)';
$st = $pdo->prepare($sql);
$n = 0; $added = 0;
foreach (glob($src . '/*', GLOB_ONLYDIR) as $dir) {
    $col = basename($dir);
    foreach (glob($dir . '/*.json') as $f) {
        $json = file_get_contents($f);
        if (!is_array(json_decode($json, true))) { fwrite(STDERR, "Пропущен файл с ошибкой JSON: $f\n"); continue; }
        $st->execute([$col, basename($f, '.json'), $json]);
        $added += $st->rowCount() > 0 ? 1 : 0;
        $n++;
    }
}
echo "Файлов: $n, записано: $added" . ($force ? ' (режим --force)' : '') . "\n";
// старый кэш страниц больше не нужен
foreach (glob(STORAGE . '/cache/*') ?: [] as $f) @unlink($f);
