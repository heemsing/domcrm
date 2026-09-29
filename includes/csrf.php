<?php
/**
 * CSRF-защита: токены на сессию, проверка POST-запросов.
 */
declare(strict_types=1);
defined('CRM_APP') or die('Direct access denied');

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Скрытое поле для форм. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Проверка токена из запроса (POST/_csrf или заголовок X-CSRF-Token). Прерывает при ошибке. */
function csrf_verify(): void
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        if (is_ajax_request()) {
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['ok' => false, 'error' => 'Сессия истекла. Обновите страницу.'], JSON_UNESCAPED_UNICODE));
        }
        exit('<!DOCTYPE html><meta charset="utf-8"><title>Запрос отклонен</title>
              <div style="font-family:sans-serif;max-width:520px;margin:80px auto;text-align:center">
              <h3>Запрос отклонен (CSRF)</h3><p>Страница устарела. Вернитесь и повторите действие.</p>
              <a href="javascript:history.back()">← Назад</a></div>');
    }
}

function is_ajax_request(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}
