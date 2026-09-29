<?php
/**
 * CRM «Учет рабочих» — базовый bootstrap приложения.
 * Подключается из каждой страницы: require_once __DIR__ . '/includes/bootstrap.php';
 */

declare(strict_types=1);

define('CRM_APP', true);
define('CRM_ROOT', dirname(__DIR__));

// ---------- Проверка установки ----------
if (!is_file(CRM_ROOT . '/config/config.php')) {
    if (is_dir(CRM_ROOT . '/install')) {
        header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/') . '/install/');
        // Если находимся в подкаталоге — упрощенно редиректим на /install/
        header('Location: /install/');
        exit;
    }
    http_response_code(500);
    exit('Система не установлена. Отсутствует config/config.php.');
}

require_once CRM_ROOT . '/config/config.php';

// ---------- Окружение ----------
date_default_timezone_set(defined('TIMEZONE') ? TIMEZONE : 'Europe/Moscow');

error_reporting(E_ALL);
ini_set('display_errors', '0');           // никогда не показываем стектрейсы пользователю
ini_set('log_errors', '1');

// ---------- Безопасные заголовки ----------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header_remove('X-Powered-By');
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ---------- Сессии ----------
if (session_status() === PHP_SESSION_NONE) {
    session_name('CRMSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------- Ядро ----------
require_once CRM_ROOT . '/includes/database.php';
require_once CRM_ROOT . '/includes/functions.php';
require_once CRM_ROOT . '/includes/csrf.php';
require_once CRM_ROOT . '/includes/auth.php';
require_once CRM_ROOT . '/includes/permissions.php';

/** Таблица с префиксом. */
function t(string $name): string
{
    return TABLE_PREFIX . $name;
}

/** Получить настройку из БД (с кэшем на запрос). */
function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT `key`,`value` FROM ' . t('settings')) as $row) {
                $cache[$row['key']] = $row['value'];
            }
        } catch (Throwable $e) {
            error_log('[CRM] settings load: ' . $e->getMessage());
        }
    }
    return $cache[$key] ?? $default;
}
