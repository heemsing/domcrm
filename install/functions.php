<?php
/**
 * CRM «Учет рабочих» — функции установщика.
 * Самодостаточны: не требуют файлов основной системы.
 */

declare(strict_types=1);

/** Экранирование для вывода в HTML. */
function esc(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Корень проекта (на уровень выше /install). */
function install_root(): string
{
    return dirname(__DIR__);
}

/** Файл-блокировка установленной системы. */
function install_lock_file(): string
{
    return __DIR__ . '/installed.lock';
}

/** Проверка: система уже установлена? */
function install_is_locked(): bool
{
    if (is_file(install_lock_file())) {
        return true;
    }
    // Дополнительная защита: если конфиг уже существует и содержит признак установки.
    $cfg = install_root() . '/config/config.php';
    if (is_file($cfg)) {
        $content = @file_get_contents($cfg);
        if ($content !== false && strpos($content, 'INSTALL_KEY') !== false) {
            return true;
        }
    }
    return false;
}

/** Автоопределение базового URL сайта из текущего запроса (с поддержкой reverse proxy). */
function install_detect_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $host = preg_replace('/[^A-Za-z0-9\.\-\:\[\]]/', '', (string)$host);

    // Не доверяем порту при стандартных 80/443
    $port = (int)($_SERVER['SERVER_PORT'] ?? 0);
    $portPart = '';
    if (!$https && $port > 0 && $port !== 80) {
        $portPart = ':' . $port;
    } elseif ($https && $port > 0 && $port !== 443) {
        $portPart = ':' . $port;
    }

    // Отрезаем путь /install/... от URI
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/install/index.php');
    $path = preg_replace('#/install(/.*)?$#', '', $script);
    $path = rtrim($path, '/');

    return ($https ? 'https' : 'http') . '://' . $host . $portPart . $path;
}

/**
 * Подключение к MySQL через PDO без выбора БД (для проверки/создания).
 * @return array{0:?\PDO, 1:?string} [pdo|null, error|null]
 */
function install_connect_mysql(string $host, int $port, string $user, string $pass, ?string $dbName = null): array
{
    $dsnBase = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
    if ($dbName !== null && $dbName !== '') {
        $dsnBase .= ';dbname=' . $dbName;
    }
    try {
        $pdo = new PDO($dsnBase, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ]);
        return [$pdo, null];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        // Никогда не показываем пароль
        if ($pass !== '') {
            $msg = str_replace($pass, '******', $msg);
        }
        return [null, $msg];
    }
}

/** Понятное сообщение об ошибке подключения к MySQL. */
function install_mysql_error_message(string $rawError): string
{
    if (stripos($rawError, 'access denied') !== false) {
        return "Не удалось подключиться к MySQL.<br><br>Проверьте:<br>— хост;<br>— имя базы;<br>— пользователя;<br>— пароль;<br>— порт.";
    }
    if (stripos($rawError, 'unknown database') !== false) {
        return "База данных не найдена.<br><br>Создайте базу данных через панель хостинга или phpMyAdmin, после чего вернитесь к установке и продолжите.<br>Либо используйте кнопку «Создать базу данных автоматически», если пользователь MySQL имеет такие права.";
    }
    if (stripos($rawError, 'can\'t connect') !== false || stripos($rawError, 'connection refused') !== false || stripos($rawError, 'No such file') !== false || stripos($rawError, 'getaddrinfo') !== false) {
        return "Сервер MySQL недоступен по указанному адресу.<br><br>Проверьте:<br>— хост (обычно <code>localhost</code> или адрес с панели хостинга);<br>— порт (обычно <code>3306</code>);<br>— запущен ли сервер MySQL.";
    }
    return "Не удалось подключиться к MySQL.<br><br>Проверьте хост, имя базы, пользователя, пароль и порт.<br><small class='text-muted'>" . esc($rawError) . "</small>";
}

/** Валидация префикса таблиц. */
function install_valid_prefix(string $p): bool
{
    return (bool)preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,15}$/', $p);
}

/**
 * Токен-разборщик SQL-скрипта: понимает кавычки ('..', "..", бэктики), комментарии
 * (двойной дефис и блочные), и корректно разбивает скрипт на запросы по точке с
 * запятой, даже если она встречается в строке или тексте комментария.
 * @return string[] массив запросов без завершающей точки с запятой
 */
function install_split_sql(string $sql): array
{
    $out = [];
    $cur = '';
    $len = strlen($sql);
    $i = 0;
    $inStr = null;   // текущий символ-ограничитель строки: ' " `
    $inLineComment = false;
    $inBlockComment = false;

    while ($i < $len) {
        $c = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if ($inLineComment) {
            if ($c === "\n") { $inLineComment = false; $cur .= "\n"; }
            $i++;
            continue;
        }
        if ($inBlockComment) {
            if ($c === '*' && $next === '/') { $inBlockComment = false; $i += 2; continue; }
            $i++;
            continue;
        }
        if ($inStr !== null) {
            if ($c === '\\' && $inStr !== '`') { // экранирование в '...' и "..."
                $cur .= $c . $next;
                $i += 2;
                continue;
            }
            if ($c === $inStr) {
                if ($next === $inStr) { // удвоенная кавычка
                    $cur .= $c . $next;
                    $i += 2;
                    continue;
                }
                $inStr = null;
            }
            $cur .= $c;
            $i++;
            continue;
        }

        // вне строк/комментариев
        if ($c === '-' && $next === '-') { $inLineComment = true; $i += 2; continue; }
        if ($c === '#') { $inLineComment = true; $i++; continue; }
        if ($c === '/' && $next === '*') { $inBlockComment = true; $i += 2; continue; }
        if ($c === "'" || $c === '"' || $c === '`') { $inStr = $c; $cur .= $c; $i++; continue; }
        if ($c === ';') {
            $stmt = trim($cur);
            if ($stmt !== '') $out[] = $stmt;
            $cur = '';
            $i++;
            continue;
        }
        $cur .= $c;
        $i++;
    }
    $stmt = trim($cur);
    if ($stmt !== '') $out[] = $stmt;
    return $out;
}

/** Проверяет наличие прав CREATE TABLE у пользователя MySQL в текущей БД. */
function install_check_create_privilege(PDO $pdo): bool
{
    try {
        $grants = $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($grants as $g) {
            $gu = strtoupper($g);
            if (strpos($gu, 'ALL PRIVILEGES') !== false || strpos($gu, 'CREATE') !== false) {
                return true;
            }
        }
        return false;
    } catch (PDOException $e) {
        // Если SHOW GRANTS запрещен, попробуем фактическую проверку через создание тестовой таблицы ниже.
        return true;
    }
}

/**
 * Фактическая проверка права CREATE TABLE: создаем и удаляем тестовую таблицу.
 * @return array{0:bool, 1:?string}
 */
function install_test_create_table(PDO $pdo, string $prefix): array
{
    $testTable = $prefix . '_install_probe';
    try {
        $pdo->exec("CREATE TABLE `$testTable` (id INT) ENGINE=InnoDB");
        $pdo->exec("DROP TABLE `$testTable`");
        return [true, null];
    } catch (PDOException $e) {
        return [false, $e->getMessage()];
    }
}

/** Создание каталогов хранилища файлов. Возвращает список ошибок. */
function install_create_upload_dirs(string $root): array
{
    $dirs = [
        '/uploads',
        '/uploads/workers',
        '/uploads/documents',
        '/uploads/photos',
        '/uploads/tmp',
        '/config',
    ];
    $errors = [];
    foreach ($dirs as $d) {
        $full = $root . $d;
        if (!is_dir($full)) {
            if (!@mkdir($full, 0775, true)) {
                $errors[] = "Не удалось создать каталог {$d}. Проверьте права на запись в корень проекта.";
                continue;
            }
        }
        if (!is_writable($full)) {
            $errors[] = "Каталог {$d} недоступен для записи. Установите права 755 или 775 для этого каталога.";
        }
    }
    // .htaccess внутри uploads — запрет исполнения PHP
    if (is_dir($root . '/uploads') && is_writable($root . '/uploads')) {
        $ht = <<<'HT'
# Запрет исполнения скриптов в хранилище файлов
Options -Indexes -ExecCGI
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps
RemoveType .php .phtml .php3 .php4 .php5 .php7 .phps
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule \.(php|phtml|phar|cgi|pl|py|sh)$ - [F,L]
</IfModule>
<FilesMatch "\.(php|phtml|phar)$">
    Require all denied
</FilesMatch>
HT;
        @file_put_contents($root . '/uploads/.htaccess', $ht);
    }
    // index.html protection
    foreach (['/uploads', '/uploads/workers', '/uploads/documents', '/uploads/photos'] as $d) {
        $idx = $root . $d . '/index.html';
        if (is_dir($root . $d) && !file_exists($idx)) {
            @file_put_contents($idx, '');
        }
    }
    return $errors;
}

/** Безопасная запись конфигурационного файла. */
function install_write_config(string $root, array $data): array
{
    $dir = $root . '/config';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return [false, "Невозможно создать config.php.<br><br>Проверьте права каталога <code>config</code>. Каталог должен существовать и быть доступен для записи (права 755 или 775)."];
    }

    $qt = static fn($v) => "'" . addslashes((string)$v) . "'";

    $php = "<?php\n";
    $php .= "/**\n";
    $php .= " * Конфигурационный файл CRM «Учет рабочих».\n";
    $php .= " * Создан установщиком " . date('Y-m-d H:i:s') . ".\n";
    $php .= " * НЕ РАЗМЕЩАЙТЕ ЭТОТ ФАЙЛ В ПУБЛИЧНОМ ДОСТУПЕ!\n";
    $php .= " */\n\n";
    $php .= "defined('CRM_APP') or die('Direct access denied');\n\n";
    $php .= "// База данных\n";
    $php .= "define('DB_HOST', " . $qt($data['db_host']) . ");\n";
    $php .= "define('DB_PORT', " . $qt($data['db_port']) . ");\n";
    $php .= "define('DB_NAME', " . $qt($data['db_name']) . ");\n";
    $php .= "define('DB_USER', " . $qt($data['db_user']) . ");\n";
    $php .= "define('DB_PASS', " . $qt($data['db_pass']) . ");\n";
    $php .= "define('TABLE_PREFIX', " . $qt($data['table_prefix']) . ");\n\n";
    $php .= "// Сайт\n";
    $php .= "define('SITE_NAME', " . $qt($data['site_name']) . ");\n";
    $php .= "define('SITE_URL', " . $qt($data['site_url']) . ");\n";
    $php .= "define('TIMEZONE', " . $qt($data['timezone']) . ");\n\n";
    $php .= "// Хранилище файлов (вне прямого веб-доступа, отдача только через scripts)\n";
    $php .= "define('UPLOAD_DIR', " . $qt($root . '/uploads') . ");\n\n";
    $php .= "// Ключ установки\n";
    $php .= "define('INSTALL_KEY', " . $qt($data['install_key']) . ");\n\n";
    $php .= "// Версии\n";
    $php .= "define('APP_VERSION', '1.0.0');\n";
    $php .= "define('DB_VERSION', 1);\n";

    $tmp = $dir . '/config.php.tmp';
    if (@file_put_contents($tmp, $php) === false) {
        return [false, "Невозможно создать config.php.<br><br>Проверьте права каталога <code>config</code>."];
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $dir . '/config.php')) {
        @unlink($tmp);
        return [false, "Невозможно создать config.php.<br><br>Проверьте права каталога <code>config</code>."];
    }
    // .htaccess в config — двойная защита от прямой выдачи
    @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    return [true, null];
}

/** Сессия установщика: сохранение шага и данных. */
function install_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('CRM_INSTALL');
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ]);
    }
}

/** Общая страница-мастер (HTML-каркас). */
function install_header(string $title, int $activeStep): void
{
    $steps = [
        1 => 'Проверка сервера',
        2 => 'Настройки сайта',
        3 => 'База данных',
        4 => 'Администратор',
        5 => 'Установка',
    ];
    ?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= esc($title) ?> — Установка CRM учета рабочих</title>
<link rel="stylesheet" href="../assets/css/bootstrap.min.css">
<link rel="stylesheet" href="../assets/css/install.css">
</head>
<body class="install-body">
<div class="install-wrap">
  <div class="install-card shadow-lg">
    <div class="install-head">
      <div class="install-logo">👷</div>
      <div>
        <h1 class="h4 mb-0">Установка CRM учета рабочих</h1>
        <div class="text-white-50 small">Мастер автоматической установки · v1.0.0</div>
      </div>
    </div>
    <div class="row g-0">
      <div class="col-md-4 border-end install-steps-col">
        <ul class="install-steps list-unstyled mb-0">
          <?php foreach ($steps as $n => $label): ?>
            <li class="<?= $n === $activeStep ? 'active' : ($n < $activeStep ? 'done' : '') ?>">
              <span class="step-num"><?= $n < $activeStep ? '✓' : $n ?></span>
              Шаг <?= $n ?>. <?= esc($label) ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="col-md-8">
        <div class="install-content p-4"><?php
}

function install_footer(): void
{
    ?></div></div></div></div>
<script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html><?php
}
