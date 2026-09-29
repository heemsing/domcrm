<?php
/**
 * Аутентификация: вход, выход, текущий пользователь, rate limiting.
 */
declare(strict_types=1);
defined('CRM_APP') or die('Direct access denied');

define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCK_SECONDS', 900); // 15 минут

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

/** Требовать авторизацию на странице. */
function require_login(): void
{
    if (!is_logged_in()) {
        $self = $_SERVER['REQUEST_URI'] ?? '';
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
        redirect(url('login.php') . ($self ? '?next=' . urlencode($self) : ''));
    }
}

/** Данные текущего пользователя (кэш на запрос). */
function current_user(): ?array
{
    static $user = null;
    if ($user !== null) {
        return $user ?: null;
    }
    if (!is_logged_in()) {
        $user = false;
        return null;
    }
    $st = db_query('SELECT u.*, r.name AS role_name, r.slug AS role_slug
                    FROM ' . t('users') . ' u JOIN ' . t('roles') . ' r ON r.id = u.role_id
                    WHERE u.id = ? AND u.is_active = 1', [(int)$_SESSION['user_id']]);
    $row = $st->fetch();
    $user = $row ?: false;
    return $user ?: null;
}

/** IP клиента (с учетом обратного прокси — доверяем только внутреннему формату). */
function client_ip(): string
{
    // X-Forwarded-For подделывается клиентом; используем только если сервер настроен на доверие прокси.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $ip = '0.0.0.0';
    }
    return $ip;
}

/**
 * Попытка входа. Возвращает [ok(bool), error(string|null)].
 * Rate limiting: по учетной записи и по IP.
 */
function attempt_login(string $username, string $password): array
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return [false, 'Введите логин и пароль.'];
    }

    $ipKey = 'login_ip_' . md5(client_ip());
    $now = time();
    $ipAttempts = $_SESSION[$ipKey] ?? ['count' => 0, 'ts' => $now];
    if ($now - $ipAttempts['ts'] > LOGIN_LOCK_SECONDS) {
        $ipAttempts = ['count' => 0, 'ts' => $now];
    }
    if ($ipAttempts['count'] >= 20) {
        return [false, 'Слишком много попыток входа с этого адреса. Повторите позже.'];
    }

    $st = db_query('SELECT * FROM ' . t('users') . ' WHERE username = ? OR email = ?', [$username, $username]);
    $user = $st->fetch();

    if (!$user) {
        $ipAttempts['count']++;
        $_SESSION[$ipKey] = $ipAttempts;
        usleep(300000); // против перечисления пользователей
        return [false, 'Неверный логин или пароль.'];
    }

    // Блокировка учетной записи
    if (!empty($user['locked_until']) && strtotime((string)$user['locked_until']) > $now) {
        return [false, 'Учетная запись временно заблокирована после неудачных попыток. Повторите позже.'];
    }
    if ((int)$user['is_active'] !== 1) {
        return [false, 'Учетная запись отключена. Обратитесь к администратору.'];
    }

    if (!password_verify($password, $user['password_hash'])) {
        $ipAttempts['count']++;
        $_SESSION[$ipKey] = $ipAttempts;
        $fails = (int)$user['failed_logins'] + 1;
        $lock = $fails >= LOGIN_MAX_ATTEMPTS ? date('Y-m-d H:i:s', $now + LOGIN_LOCK_SECONDS) : null;
        db_query('UPDATE ' . t('users') . ' SET failed_logins = ?, locked_until = ? WHERE id = ?',
            [$lock ? 0 : $fails, $lock, (int)$user['id']]);
        audit_log('login_failed', 'user', (int)$user['id']);
        usleep(300000);
        return [false, 'Неверный логин или пароль.'];
    }

    // Успех: перегенерация сессии (fixation protection)
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role_slug'] = $user['role_slug'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    unset($_SESSION[$ipKey]);

    db_query('UPDATE ' . t('users') . ' SET last_login_at = NOW(), failed_logins = 0, locked_until = NULL WHERE id = ?', [(int)$user['id']]);
    audit_log('login', 'user', (int)$user['id']);

    return [true, null];
}

function logout_user(): void
{
    if (is_logged_in()) {
        audit_log('logout', 'user', (int)$_SESSION['user_id']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
