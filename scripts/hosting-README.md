# SIMMETRIKA: установка на хостинг (simmetrika.addu.ru)

В архиве три вещи:

- `www/` — файлы сайта и админки, готовые к работе. Настройки уже в `www/config.php`.
- `simmetrika.sql` — дамп базы: все страницы, товары и настройки, заявок нет.
- `README.md` — эта инструкция.

## Требования

- PHP 8.0 или новее с расширениями `pdo_mysql`, `mbstring`, `gd` (с поддержкой WebP), `json`.
- MySQL 5.7+ или MariaDB 10.3+, кодировка базы `utf8mb4`.
- Apache с `mod_rewrite` (правила в `www/.htaccess`) или nginx (пример ниже).
- Настройки PHP: `max_input_vars` не меньше 5000, `upload_max_filesize` 20M, `post_max_size` 24M.
  Они уже записаны в `www/.user.ini`. Если хостинг его не читает, выставьте их в панели.
- Работающая функция `mail()`: заявки уходят письмом на simmetrika@mail.ru и всегда сохраняются в админке.

## Установка

1. Создайте пустую базу (`utf8mb4_unicode_ci`) и пользователя к ней.
2. Импортируйте дамп: `mysql -u USER -p DBNAME < simmetrika.sql` или phpMyAdmin → «Импорт».
3. Загрузите содержимое `www/` в корень сайта simmetrika.addu.ru, включая скрытые `.htaccess` и `.user.ini`.
4. В `www/config.php` впишите имя базы, пользователя и пароль (блок `db`). Остальное уже настроено.
5. Дайте PHP право записи в папки `storage/` (кэш, сессии, журналы) и `img/up/` (фото из админки).
6. Откройте https://simmetrika.addu.ru/ и https://simmetrika.addu.ru/admin/ (логин `admin`, пароль передан отдельно).

Повторный импорт `simmetrika.sql` стирает правки из админки и заявки: дамп только для первой установки.

## Проверка после установки

- Главная, каталог и товар открываются, несуществующий адрес отдаёт 404.
- `/robots.txt` и `/sitemap.xml` открываются, адреса в них на https://simmetrika.addu.ru.
- `/config.php`, `/app/`, `/storage/`, `/install/` отдают 403 или 404.
- В админке любое изменение сохраняется и сразу видно на сайте; загрузка фото работает.
- Тестовая заявка из корзины приходит на почту и видна в админке («Заявки»). Её можно пометить статусом «Спам».

## Индексация

`'preview' => false` в `config.php` открывает сайт поисковикам. Если simmetrika.addu.ru временный адрес,
поставьте `true`: на всех страницах появится noindex, а robots.txt закроет сайт целиком.

## nginx (если не Apache)

```nginx
root /path/to/www;
index index.php;
client_max_body_size 24m;

location ~ ^/(app|install|storage)(/|$) { deny all; }
location ~ ^/(config(\.sample)?\.php|admin/lib\.php)$ { deny all; }
location ~ /\.(ht|user\.ini|git|env) { deny all; }
location ~ ^/(img|images|js|css)/.*\.(php\d?|phtml|phar|pl|py|cgi|sh)$ { deny all; }

location ^~ /admin/ {
    try_files $uri $uri/ /admin/index.php?$query_string;
    location ~ ^/admin/index\.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php-fpm.sock; }
    location ~ \.php$ { deny all; }
}
location / { try_files $uri /index.php?$query_string; }
location = /index.php { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php-fpm.sock; }
location ~ \.php$ { deny all; }
location ~* \.(webp|jpe?g|png|svg|css|js)$ { expires 1y; access_log off; }
```

Для nginx значения из `.user.ini` задайте в настройках PHP-FPM.

## Пароль админки

Сменить: `php install/make-password.php 'новый-пароль'`, затем вставить полученную строку в
`config.php` → `admin_pass_hash`.
