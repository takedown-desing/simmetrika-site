-- Таблицы сайта SIMMETRIKA (MySQL 5.7+ / MariaDB 10.3+, кодировка utf8mb4)
SET NAMES utf8mb4;

-- Все тексты, товары и настройки сайта: одна запись = один JSON-файл из админки
CREATE TABLE IF NOT EXISTS entries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  collection VARCHAR(32) NOT NULL,
  file VARCHAR(190) NOT NULL,
  data LONGTEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY collection_file (collection, file)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Заявки с форм сайта
CREATE TABLE IF NOT EXISTS leads (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  number VARCHAR(20) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  form VARCHAR(40) NOT NULL DEFAULT '',
  name VARCHAR(190) NOT NULL DEFAULT '',
  phone VARCHAR(64) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL DEFAULT '',
  message TEXT NULL,
  page VARCHAR(500) NOT NULL DEFAULT '',
  payload LONGTEXT NULL,
  ip VARCHAR(64) NOT NULL DEFAULT '',
  mailed TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'new',
  PRIMARY KEY (id),
  UNIQUE KEY number (number),
  KEY created_at (created_at),
  KEY ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
