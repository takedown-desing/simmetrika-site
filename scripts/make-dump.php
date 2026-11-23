<?php
// Дамп базы для хостинга: таблицы + все записи сайта из src/cms, заявок нет.
//   php scripts/make-dump.php src/cms php/install/schema.sql > simmetrika.sql
// Записи кладутся как есть (тот же JSON, что загружает install/import.php).
[$_, $src, $schema] = $argv + [null, 'src/cms', 'php/install/schema.sql'];
if (!is_dir($src) || !is_file($schema)) { fwrite(STDERR, "Нет $src или $schema\n"); exit(1); }
$q = fn(string $s) => "'" . strtr($s, ["\\" => "\\\\", "'" => "\\'", "\0" => "\\0", "\n" => "\\n", "\r" => "\\r", "\x1a" => "\\Z"]) . "'";
$out = "-- База сайта SIMMETRIKA (https://simmetrika.addu.ru), " . date('Y-m-d') . "\n"
    . "-- Импорт в пустую базу utf8mb4: mysql -u USER -p DBNAME < simmetrika.sql (или phpMyAdmin → Импорт).\n"
    . "-- Только для первой установки: повторный импорт удалит правки из админки и заявки.\n"
    . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nDROP TABLE IF EXISTS entries;\nDROP TABLE IF EXISTS leads;\n\n";
$out .= trim(preg_replace('/^SET NAMES[^\n]*\n/m', '', preg_replace('/^--[^\n]*\n/m', '', file_get_contents($schema)))) . "\n\n";
$rows = [];
foreach (glob("$src/*", GLOB_ONLYDIR) as $dir) {
    foreach (glob("$dir/*.json") as $f) {
        $json = file_get_contents($f);
        if (!is_array(json_decode($json, true))) { fwrite(STDERR, "Ошибка JSON: $f\n"); exit(1); }
        $rows[] = '(' . $q(basename($dir)) . ', ' . $q(basename($f, '.json')) . ', ' . $q($json) . ')';
    }
}
foreach (array_chunk($rows, 20) as $ch) $out .= "INSERT INTO entries (collection, file, data) VALUES\n" . implode(",\n", $ch) . ";\n";
$out .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";
echo $out;
fwrite(STDERR, 'Записей: ' . count($rows) . "\n");
