<?php
/**
 * Установщик CRM «Учет рабочих» — точка входа.
 * Маршрутизирует шаги мастера: 1..5 + завершение.
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
install_session_start();

// Блокировка показ stack trace
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;

if ($step < 1 || $step > 6) {
    $step = 1;
}

// AJAX-запросы установщика (проверка подключения к БД и т.п.) идут на
// index.php?ajax=... — обрабатывает их файл соответствующего шага.
// Без этой маршрутизации запрос получал обычный HTML мастера, и браузер
// показывал «Ошибка соединения с установщиком».
if (isset($_GET['ajax'])) {
    require __DIR__ . '/database.php';
    exit;
}

// Если система уже установлена — не показываем мастер нигде, кроме complete
if (install_is_locked() && $step !== 99) {
    ?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Система уже установлена</title>
<link rel="stylesheet" href="../assets/css/bootstrap.min.css">
<link rel="stylesheet" href="../assets/css/install.css">
</head>
<body class="install-body d-flex align-items-center justify-content-center">
<div class="install-card shadow-lg p-0 overflow-hidden" style="max-width:520px;">
  <div class="install-head">
    <div class="install-logo">🔒</div>
    <div><h1 class="h5 mb-0">Система уже установлена</h1></div>
  </div>
  <div class="p-4 text-center">
    <p class="mb-1"><strong>Повторная установка отключена в целях безопасности.</strong></p>
    <p class="text-muted small">Для переустановки удалите файл <code>/install/installed.lock</code> и конфигурацию <code>/config/config.php</code>, а также очистите таблицы базы данных.</p>
    <a href="../login.php" class="btn btn-primary mt-3">Перейти ко входу →</a>
  </div>
</div>
</body>
</html><?php
    exit;
}

switch ($step) {
    case 1: require __DIR__ . '/requirements.php'; break;
    case 2: require __DIR__ . '/site.php'; break;
    case 3: require __DIR__ . '/database.php'; break;
    case 4: require __DIR__ . '/admin.php'; break;
    case 5: require __DIR__ . '/summary.php'; break;
    case 6: require __DIR__ . '/complete.php'; break;
    default: require __DIR__ . '/requirements.php'; break;
}
