<?php
// Настройки сайта SIMMETRIKA. Скопируйте файл в config.php и заполните.
return [
    // база MySQL / MariaDB (кодировка utf8mb4)
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'simmetrika',
        'user' => 'simmetrika',
        'pass' => '',
    ],
    // адрес сайта без слэша в конце: для canonical, og:image, sitemap.xml и robots.txt
    'origin' => 'https://simmetrika.addu.ru',
    // другой домен для rel=canonical и разметки, если он отличается от origin; обычно пусто
    'canonical_origin' => '',
    // подкаталог, если сайт лежит не в корне домена (например '/shop'); в корне оставить ''
    'base' => '',
    // true — закрыть сайт от индексации (тестовая копия): noindex на всех страницах, robots.txt Disallow, полоса «Превью»
    'preview' => false,
    // куда отправлять заявки с сайта (через функцию mail() хостинга); пусто — заявки только в админке
    'mail_to' => 'simmetrika@mail.ru',
    // адрес отправителя писем, лучше ящик на домене сайта
    'mail_from' => 'noreply@simmetrika.addu.ru',
    // вход в админку /admin/: логин и хэш пароля.
    // Хэш получить командой: php install/make-password.php 'ваш-пароль'
    'admin_user' => 'admin',
    'admin_pass_hash' => '',
    // кэш готовых страниц в storage/cache (сбрасывается при каждом сохранении в админке)
    'cache' => true,
    // показывать подробные ошибки PHP (только для отладки)
    'debug' => false,
];
