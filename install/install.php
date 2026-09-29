<?php

/**
 * CRM «Учет рабочих» — функции установщика.
 *
 * Самодостаточный файл.
 * Не требует подключения файлов основной системы.
 */

declare(strict_types=1);

/**
 * Экранирование для вывода в HTML.
 */
function esc(?string $s): string
{
    return htmlspecialchars(
        (string) $s,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * Возвращает корень проекта.
 */
function install_root(): string
{
    return dirname(__DIR__);
}

/**
 * Возвращает путь к файлу блокировки установки.
 */
function install_lock_file(): string
{
    return __DIR__ . '/installed.lock';
}

/**
 * Проверяет, была ли система уже установлена.
 */
function install_is_locked(): bool
{
    // Основной признак установленной системы.
    if (is_file(install_lock_file())) {
        return true;
    }

    // Дополнительная защита через конфигурационный файл.
    $configFile = install_root() . '/config/config.php';

    if (is_file($configFile)) {
        $content = @file_get_contents($configFile);

        if (
            $content !== false
            && strpos($content, 'INSTALL_KEY') !== false
        ) {
            return true;
        }
    }

    return false;
}

/**
 * Автоматически определяет URL сайта.
 *
 * Учитывает HTTPS и reverse proxy.
 */
function install_detect_url(): string
{
    $https =
        (
            !empty($_SERVER['HTTPS'])
            && strtolower((string) $_SERVER['HTTPS']) !== 'off'
        )
        || (
            isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https'
        )
        || (
            isset($_SERVER['HTTP_X_FORWARDED_SSL'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL']) === 'on'
        )
        || (
            isset($_SERVER['SERVER_PORT'])
            && (int) $_SERVER['SERVER_PORT'] === 443
        );

    $host =
        $_SERVER['HTTP_X_FORWARDED_HOST']
        ?? $_SERVER['HTTP_HOST']
        ?? $_SERVER['SERVER_NAME']
        ?? 'localhost';

    $host = preg_replace(
        '/[^A-Za-z0-9.\-:\[\]]/',
        '',
        (string) $host
    );

    if ($host === null || $host === '') {
        $host = 'localhost';
    }

    $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
    $portPart = '';

    if ($port > 0) {
        if (!$https && $port !== 80) {
            $portPart = ':' . $port;
        }

        if ($https && $port !== 443) {
            $portPart = ':' . $port;
        }
    }

    $script = str_replace(
        '\\',
        '/',
        (string) ($_SERVER['SCRIPT_NAME'] ?? '/install/index.php')
    );

    $path = preg_replace(
        '#/install(?:/.*)?$#i',
        '',
        $script
    );

    if ($path === null) {
        $path = '';
    }

    $path = rtrim($path, '/');

    return ($https ? 'https' : 'http')
        . '://'
        . $host
        . $portPart
        . $path;
}

/**
 * Подключение к MySQL через PDO.
 *
 * База данных может быть указана или не указана.
 *
 * @return array{0: ?PDO, 1: ?string}
 */
function install_connect_mysql(
    string $host,
    int $port,
    string $user,
    string $pass,
    ?string $dbName = null
): array {
    $dsn = 'mysql:host=' . $host
        . ';port=' . $port
        . ';charset=utf8mb4';

    if (
        $dbName !== null
        && trim($dbName) !== ''
    ) {
        $dsn .= ';dbname=' . $dbName;
    }

    try {
        $pdo = new PDO(
            $dsn,
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ]
        );

        return [$pdo, null];
    } catch (PDOException $e) {
        $msg = $e->getMessage();

        // Не показываем пароль в тексте ошибки.
        if ($pass !== '') {
            $msg = str_replace(
                $pass,
                '******',
                $msg
            );
        }

        return [null, $msg];
    }
}

/**
 * Преобразует техническую ошибку MySQL
 * в понятное пользователю сообщение.
 */
function install_mysql_error_message(
    string $rawError
): string {
    $error = strtolower($rawError);

    if (strpos($error, 'access denied') !== false) {
        return
            "Не удалось подключиться к MySQL.<br><br>"
            . "Проверьте:<br>"
            . "— хост;<br>"
            . "— имя базы;<br>"
            . "— пользователя;<br>"
            . "— пароль;<br>"
            . "— порт.";
    }

    if (strpos($error, 'unknown database') !== false) {
        return
            "База данных не найдена.<br><br>"
            . "Создайте базу данных через панель хостинга "
            . "или phpMyAdmin, после чего вернитесь "
            . "к установке и продолжите.<br><br>"
            . "Если сервер позволяет создавать базы данных "
            . "через MySQL, воспользуйтесь соответствующей "
            . "опцией установщика.";
    }

    if (
        strpos($error, "can't connect") !== false
        || strpos($error, 'connection refused') !== false
        || strpos($error, 'no such file') !== false
        || strpos($error, 'getaddrinfo') !== false
        || strpos($error, 'php_network_getaddresses') !== false
    ) {
        return
            "Сервер MySQL недоступен по указанному адресу.<br><br>"
            . "Проверьте:<br>"
            . "— хост;<br>"
            . "— порт;<br>"
            . "— запущен ли сервер MySQL.";
    }

    return
        "Не удалось подключиться к MySQL.<br><br>"
        . "Проверьте хост, имя базы, пользователя, пароль и порт.<br>"
        . "<small class=\"text-muted\">"
        . esc($rawError)
        . "</small>";
}

/**
 * Проверяет корректность префикса таблиц.
 */
function install_valid_prefix(string $p): bool
{
    return (bool) preg_match(
        '/^[a-zA-Z][a-zA-Z0-9_]{0,15}$/',
        $p
    );
}

/**
 * Разбивает SQL-файл на отдельные запросы.
 *
 * Учитывает:
 * - строковые значения;
 * - обратные кавычки;
 * - однострочные комментарии;
 * - блочные комментарии.
 *
 * @return string[]
 */
function install_split_sql(string $sql): array
{
    $out = [];
    $cur = '';

    $len = strlen($sql);
    $i = 0;

    $inStr = null;
    $inLineComment = false;
    $inBlockComment = false;

    while ($i < $len) {
        $c = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        // Однострочный комментарий.
        if ($inLineComment) {
            if ($c === "\n") {
                $inLineComment = false;
                $cur .= "\n";
            }

            $i++;
            continue;
        }

        // Блочный комментарий.
        if ($inBlockComment) {
            if (
                $c === '*'
                && $next === '/'
            ) {
                $inBlockComment = false;
                $i += 2;
                continue;
            }

            $i++;
            continue;
        }

        // Находимся внутри строки.
        if ($inStr !== null) {
            // Экранированный символ.
            if (
                $c === '\\'
                && $inStr !== '`'
            ) {
                $cur .= $c;

                if ($next !== '') {
                    $cur .= $next;
                    $i += 2;
                } else {
                    $i++;
                }

                continue;
            }

            // Удвоенная кавычка.
            if ($c === $inStr) {
                if ($next === $inStr) {
                    $cur .= $c . $next;
                    $i += 2;
                    continue;
                }

                $inStr = null;
                $cur .= $c;
                $i++;

                continue;
            }

            $cur .= $c;
            $i++;

            continue;
        }

        // Однострочный комментарий --.
        if (
            $c === '-'
            && $next === '-'
        ) {
            $inLineComment = true;
            $i += 2;
            continue;
        }

        // Однострочный комментарий #.
        if ($c === '#') {
            $inLineComment = true;
            $i++;
            continue;
        }

        // Блочный комментарий.
        if (
            $c === '/'
            && $next === '*'
        ) {
            $inBlockComment = true;
            $i += 2;
            continue;
        }

        // Начало строкового значения.
        if (
            $c === "'"
            || $c === '"'
            || $c === '`'
        ) {
            $inStr = $c;
            $cur .= $c;
            $i++;
            continue;
        }

        // Конец SQL-запроса.
        if ($c === ';') {
            $stmt = trim($cur);

            if ($stmt !== '') {
                $out[] = $stmt;
            }

            $cur = '';
            $i++;

            continue;
        }

        $cur .= $c;
        $i++;
    }

    $stmt = trim($cur);

    if ($stmt !== '') {
        $out[] = $stmt;
    }

    return $out;
}

/**
 * Проверяет наличие права CREATE TABLE.
 */
function install_check_create_privilege(
    PDO $pdo
): bool {
    try {
        $grants = $pdo
            ->query('SHOW GRANTS FOR CURRENT_USER()')
            ->fetchAll(PDO::FETCH_COLUMN);

        foreach ($grants as $grant) {
            $grantUpper = strtoupper((string) $grant);

            if (
                strpos($grantUpper, 'ALL PRIVILEGES') !== false
                || strpos($grantUpper, 'CREATE') !== false
            ) {
                return true;
            }
        }

        return false;
    } catch (PDOException $e) {
        // Проверка будет выполнена фактически.
        return true;
    }
}

/**
 * Фактически проверяет право CREATE TABLE.
 *
 * Создает тестовую таблицу и удаляет ее.
 *
 * @return array{0: bool, 1: ?string}
 */
function install_test_create_table(
    PDO $pdo,
    string $prefix
): array {
    $testTable = $prefix . '_install_probe';

    $safeTable = str_replace(
        '`',
        '``',
        $testTable
    );

    try {
        $pdo->exec(
            "CREATE TABLE `{$safeTable}` (
                id INT NOT NULL
            ) ENGINE=InnoDB"
        );

        $pdo->exec(
            "DROP TABLE `{$safeTable}`"
        );

        return [true, null];
    } catch (PDOException $e) {
        return [
            false,
            $e->getMessage(),
        ];
    }
}

/**
 * Создает каталоги для хранения файлов.
 *
 * @return string[]
 */
function install_create_upload_dirs(
    string $root
): array {
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
                $errors[] =
                    "Не удалось создать каталог {$d}. "
                    . "Проверьте права на запись в корень проекта.";

                continue;
            }
        }

        if (!is_writable($full)) {
            $errors[] =
                "Каталог {$d} недоступен для записи. "
                . "Установите права 755 или 775.";
        }
    }

    /**
     * Защита uploads от выполнения скриптов.
     */
    $uploadsDir = $root . '/uploads';

    if (
        is_dir($uploadsDir)
        && is_writable($uploadsDir)
    ) {
        $ht = <<<'HT'
Options -Indexes -ExecCGI

<IfModule mod_php.c>
    php_flag engine off
</IfModule>

RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps .phar
RemoveType .php .phtml .php3 .php4 .php5 .php7 .phps .phar

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule \.(php|phtml|php3|php4|php5|php7|phps|phar|cgi|pl|py|sh)$ - [F,L,NC]
</IfModule>

<FilesMatch "\.(php|phtml|php3|php4|php5|php7|phps|phar)$">
    Require all denied
</FilesMatch>

HT;

        @file_put_contents(
            $uploadsDir . '/.htaccess',
            $ht,
            LOCK_EX
        );
    }

    /**
     * Дополнительная защита каталогов от листинга.
     */
    $indexDirs = [
        '/uploads',
        '/uploads/workers',
        '/uploads/documents',
        '/uploads/photos',
    ];

    foreach ($indexDirs as $d) {
        $directory = $root . $d;
        $indexFile = $directory . '/index.html';

        if (
            is_dir($directory)
            && !file_exists($indexFile)
        ) {
            @file_put_contents(
                $indexFile,
                '',
                LOCK_EX
            );
        }
    }

    return $errors;
}

/**
 * Записывает конфигурационный файл.
 *
 * @param string $root
 * @param array<string,mixed> $data
 *
 * @return array{0: bool, 1: ?string}
 */
function install_write_config(
    string $root,
    array $data
): array {
    $dir = $root . '/config';

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    if (
        !is_dir($dir)
        || !is_writable($dir)
    ) {
        return [
            false,
            "Невозможно создать config.php.<br><br>"
            . "Проверьте права каталога "
            . "<code>config</code>.<br>"
            . "Каталог должен быть доступен для записи.",
        ];
    }

    /**
     * Безопасное преобразование значения PHP.
     */
    $q = static function (
        mixed $value
    ): string {
        return var_export(
            (string) $value,
            true
        );
    };

    $createdAt = date('Y-m-d H:i:s');

    $php = "<?php\n\n";

    $php .= "/**\n";
    $php .= " * Конфигурационный файл CRM «Учет рабочих».\n";
    $php .= " * Создан установщиком {$createdAt}.\n";
    $php .= " *\n";
    $php .= " * Не редактируйте файл без необходимости.\n";
    $php .= " */\n\n";

    $php .= "defined('CRM_APP') || define('CRM_APP', true);\n\n";

    $php .= "// База данных\n";

    $php .= "define('DB_HOST', "
        . $q($data['db_host'] ?? '')
        . ");\n";

    $php .= "define('DB_PORT', "
        . $q($data['db_port'] ?? 3306)
        . ");\n";

    $php .= "define('DB_NAME', "
        . $q($data['db_name'] ?? '')
        . ");\n";

    $php .= "define('DB_USER', "
        . $q($data['db_user'] ?? '')
        . ");\n";

    $php .= "define('DB_PASS', "
        . $q($data['db_pass'] ?? '')
        . ");\n";

    $php .= "define('TABLE_PREFIX', "
        . $q($data['table_prefix'] ?? 'crm_')
        . ");\n\n";

    $php .= "// Сайт\n";

    $php .= "define('SITE_NAME', "
        . $q($data['site_name'] ?? '')
        . ");\n";

    $php .= "define('SITE_URL', "
        . $q($data['site_url'] ?? '')
        . ");\n";

    $php .= "define('TIMEZONE', "
        . $q($data['timezone'] ?? 'Europe/Moscow')
        . ");\n\n";

    $php .= "// Хранилище файлов\n";

    $php .= "define('UPLOAD_DIR', "
        . $q($root . '/uploads')
        . ");\n\n";

    $php .= "// Ключ установки\n";

    $php .= "define('INSTALL_KEY', "
        . $q($data['install_key'] ?? '')
        . ");\n\n";

    $php .= "// Версии\n";
    $php .= "define('APP_VERSION', '1.0.0');\n";
    $php .= "define('DB_VERSION', 1);\n";

    $tmp = $dir . '/config.php.tmp';
    $final = $dir . '/config.php';

    if (
        @file_put_contents(
            $tmp,
            $php,
            LOCK_EX
        ) === false
    ) {
        return [
            false,
            "Невозможно создать config.php.<br><br>"
            . "Проверьте права каталога "
            . "<code>config</code>.",
        ];
    }

    @chmod($tmp, 0640);

    if (is_file($final)) {
        @unlink($final);
    }

    if (!@rename($tmp, $final)) {
        @unlink($tmp);

        return [
            false,
            "Невозможно создать config.php.<br><br>"
            . "Проверьте права каталога "
            . "<code>config</code>.",
        ];
    }

    @chmod($final, 0640);

    /**
     * Защита каталога config на Apache.
     */
    $configHtaccess = <<<'HT'
Options -Indexes

<IfModule mod_authz_core.c>
    Require all denied
</IfModule>

<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>

HT;

    @file_put_contents(
        $dir . '/.htaccess',
        $configHtaccess,
        LOCK_EX
    );

    $indexFile = $dir . '/index.html';

    if (!file_exists($indexFile)) {
        @file_put_contents(
            $indexFile,
            '',
            LOCK_EX
        );
    }

    return [true, null];
}

/**
 * Запускает сессию установщика.
 */
function install_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('CRM_INSTALL');

        $secure = (
            !empty($_SERVER['HTTPS'])
            && strtolower((string) $_SERVER['HTTPS']) !== 'off'
        );

        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => $secure,
        ]);
    }
}

/**
 * Генерирует криптографически случайный ключ.
 */
function install_generate_key(
    int $length = 64
): string {
    try {
        $bytes = max(
            16,
            (int) ceil($length / 2)
        );

        return substr(
            bin2hex(random_bytes($bytes)),
            0,
            $length
        );
    } catch (Throwable $e) {
        return hash(
            'sha256',
            uniqid('', true)
            . microtime(true)
            . mt_rand()
        );
    }
}

/**
 * Выводит начало страницы установщика.
 */
function install_header(
    string $title,
    int $activeStep
): void {
    $steps = [
        1 => 'Проверка сервера',
        2 => 'Настройки сайта',
        3 => 'База данных',
        4 => 'Администратор',
        5 => 'Установка',
    ];
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <meta
        name="robots"
        content="noindex,nofollow"
    >

    <title>
        <?= esc($title) ?>
        — Установка CRM учета рабочих
    </title>

    <link
        rel="stylesheet"
        href="../assets/css/bootstrap.min.css"
    >

    <link
        rel="stylesheet"
        href="../assets/css/install.css"
    >
</head>

<body class="install-body">

<div class="install-wrap">

    <div class="install-card shadow-lg">

        <div class="install-head">

            <div class="install-logo">
                👷
            </div>

            <div>
                <h1 class="h4 mb-0">
                    Установка CRM учета рабочих
                </h1>

                <div class="text-white-50 small">
                    Мастер автоматической установки · v1.0.0
                </div>
            </div>

        </div>

        <div class="row g-0">

            <div class="col-md-4 border-end install-steps-col">

                <ul class="install-steps list-unstyled mb-0">

                    <?php foreach ($steps as $n => $label): ?>

                        <li
                            class="<?= $n === $activeStep
                                ? 'active'
                                : ($n < $activeStep ? 'done' : '') ?>"
                        >

                            <span class="step-num">
                                <?= $n < $activeStep ? '✓' : $n ?>
                            </span>

                            Шаг <?= $n ?>.
                            <?= esc($label) ?>

                        </li>

                    <?php endforeach; ?>

                </ul>

            </div>

            <div class="col-md-8">

                <div class="install-content p-4">

<?php
}

/**
 * Выводит конец страницы установщика.
 */
function install_footer(): void
{
    ?>
                </div>

            </div>

        </div>

    </div>

</div>

<script src="../assets/js/bootstrap.bundle.min.js"></script>

</body>
</html>
<?php
}
