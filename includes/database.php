<?php
/**
 * Подключение к базе данных (PDO, singleton).
 */
declare(strict_types=1);
defined('CRM_APP') or die('Direct access denied');

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        DB_HOST, DB_PORT, DB_NAME
    );
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ]);
    } catch (PDOException $e) {
        error_log('[CRM DB] ' . str_replace(DB_PASS, '******', $e->getMessage()));
        http_response_code(500);
        if (!empty($_SESSION['user_id'])) {
            // Пользователю — человеческое сообщение без деталей
            exit('<!DOCTYPE html><meta charset="utf-8"><title>Ошибка базы данных</title>
                 <div style="font-family:sans-serif;max-width:520px;margin:80px auto;text-align:center">
                 <h3>Сервер временно недоступен</h3>
                 <p>Не удалось подключиться к базе данных. Обратитесь к администратору.</p></div>');
        }
        exit('Сервер временно недоступен.');
    }
    return $pdo;
}

/** Простой помощник подготовленного запроса. */
function db_query(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
