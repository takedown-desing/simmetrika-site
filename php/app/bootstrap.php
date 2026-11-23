<?php
// Общая загрузка: настройки, база, мелкие помощники, совместимые с поведением JavaScript-версии сайта.
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('APP', __DIR__);
define('STORAGE', ROOT . '/storage');

ini_set('precision', '-1');
ini_set('serialize_precision', '-1');
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Moscow');

function cfg(?string $key = null)
{
    static $c = null;
    if ($c === null) {
        $file = ROOT . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            exit("Нет файла config.php. Скопируйте config.sample.php в config.php и заполните настройки (см. README.md).\n");
        }
        $c = require $file;
        $c += ['base' => '', 'origin' => '', 'canonical_origin' => '', 'preview' => false, 'cache' => true, 'debug' => false, 'mail_to' => '', 'mail_from' => ''];
        $c['base'] = rtrim((string)$c['base'], '/');
        $c['origin'] = rtrim((string)$c['origin'], '/');
        $c['canonical_origin'] = rtrim((string)$c['canonical_origin'], '/');
    }
    return $key === null ? $c : ($c[$key] ?? null);
}

if (cfg('debug')) { ini_set('display_errors', '1'); error_reporting(E_ALL); } else { ini_set('display_errors', '0'); }

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $d = cfg('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $d['host'], (int)($d['port'] ?? 3306), $d['name']);
    $pdo = new PDO($dsn, $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    // время заявок в базе и в админке по Москве, как у PHP, даже если сервер MySQL в другом поясе
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    return $pdo;
}

if (!function_exists('array_is_list')) {
    function array_is_list(array $a): bool { $i = 0; foreach ($a as $k => $_) { if ($k !== $i++) return false; } return true; }
}

// ── поведение JavaScript ──
// Math.round
function jsround($x): int { return (int)floor((float)$x + 0.5); }
// число в строке так, как его печатает JS (целые без «.0»)
function jsnum($x): string
{
    if (is_int($x)) return (string)$x;
    if (is_float($x)) {
        if (is_finite($x) && floor($x) == $x && abs($x) < 1e15) return (string)(int)$x;
        $s = (string)$x;
        return str_replace('E+', 'e+', $s);
    }
    return (string)$x;
}
// строковое представление значения при подстановке в шаблон `${v}`
function jsstr($v): string
{
    if ($v === null) return 'null';
    if ($v === true) return 'true';
    if ($v === false) return 'false';
    if (is_int($v) || is_float($v)) return jsnum($v);
    if (is_array($v)) return implode(',', array_map('jsstr', $v));
    return (string)$v;
}
// Number.prototype.toFixed(1) по точному двоичному значению
function tofixed1(float $x): string
{
    $s = sprintf('%.30F', abs($x));
    [$i, $f] = explode('.', $s);
    $keep = $f[0];
    $rest = substr($f, 1);
    $up = $rest !== '' && $rest[0] >= '5';
    $n = (int)($i . $keep);
    if ($up) $n++;
    $out = intdiv($n, 10) . '.' . ($n % 10);
    return ($x < 0 && $n > 0 ? '-' : '') . $out;
}
// целые float → int, чтобы json_encode печатал как JSON.stringify
function jsonable($v)
{
    if (is_float($v) && is_finite($v) && floor($v) == $v && abs($v) < 1e15) return (int)$v;
    if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = jsonable($x); }
    return $v;
}
function js_json($v): string
{
    $v = jsonable($v);
    if (is_array($v) && $v === []) return '[]';
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
// объект для JSON: пустой массив печатается как {}
function obj(array $a) { return $a === [] ? new stdClass() : $a; }
function encodeURIComponent(string $s): string
{
    return strtr(rawurlencode($s), ['%21' => '!', '%27' => "'", '%28' => '(', '%29' => ')', '%2A' => '*']);
}
// JS truthy
function truthy($v): bool
{
    if ($v === null || $v === false || $v === '' || $v === 0 || $v === 0.0) return false;
    if (is_float($v) && is_nan($v)) return false;
    return true; // в JS пустой массив и объект — истина
}
function ru_date_long(string $date): string
{
    static $M = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m)) return 'Invalid Date';
    return ((int)$m[3]) . ' ' . $M[(int)$m[2] - 1];
}
function str_ucfirst(string $s): string { return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1); }

// экранирование как в render.mjs: & < > "
function esc($s = ''): string
{
    return strtr(jsstr($s ?? ''), ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;']);
}
// экранирование текста в шаблонах страниц (как Astro)
function h($s): string
{
    if ($s === null || $s === false) return '';
    return htmlspecialchars(jsstr($s), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

require APP . '/cms.php';
require APP . '/catalog.php';
require APP . '/markdown.php';
require APP . '/render.php';
require APP . '/layout.php';
require APP . '/schema.php';
require APP . '/views.php';
