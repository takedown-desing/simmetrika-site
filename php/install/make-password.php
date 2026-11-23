<?php
// Хэш пароля для входа в админку: php install/make-password.php 'новый пароль'
// Полученную строку вставьте в config.php → 'admin_pass_hash'.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (empty($argv[1])) exit("Использование: php install/make-password.php 'пароль'\n");
if (mb_strlen($argv[1]) < 10) exit("Пароль короче 10 символов — придумайте длиннее\n");
echo password_hash($argv[1], PASSWORD_DEFAULT), "\n";
