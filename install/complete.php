<?php
/**
 * Шаг 6 — Завершение установки.
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

if (!install_is_locked()) {
    header('Location: index.php');
    exit;
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Установка завершена — CRM учета рабочих</title>
<link rel="stylesheet" href="../assets/css/bootstrap.min.css">
<link rel="stylesheet" href="../assets/css/install.css">
</head>
<body class="install-body d-flex align-items-center justify-content-center">
<div class="install-card shadow-lg overflow-hidden" style="max-width:640px;">
  <div class="install-head">
    <div class="install-logo">✅</div>
    <div><h1 class="h4 mb-0">✓ Установка завершена</h1>
      <div class="text-white-50 small">Система успешно установлена</div></div>
  </div>
  <div class="p-4">
    <p class="mb-3">CRM «Учет рабочих» готова к работе. Войдите под учетной записью администратора, созданной на шаге установки.</p>

    <div class="alert alert-warning small py-2">
      <b>Рекомендуется удалить каталог <code>/install/</code></b> с сервера через FTP.<br>
      Система не зависит от этого: повторная установка заблокирована файлом <code>installed.lock</code>,
      даже если каталог останется на месте.
    </div>

    <div class="d-grid gap-2">
      <a href="../login.php" class="btn btn-lg btn-success">Войти в систему →</a>
      <a href="../index.php" class="btn btn-outline-secondary">На главную (будет перенаправлено на вход)</a>
    </div>
  </div>
</div>
</body>
</html>
