<?php
/** Вход в систему. */
require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect(url('dashboard.php'));
}

$error = null;
$username = '';
$next = $_GET['next'] ?? $_POST['next'] ?? '';
// Разрешаем редирект только на внутренние относительные пути
if ($next && !preg_match('#^/[a-zA-Z0-9_\-./?=&%]*$#', $next)) {
    $next = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = (string)($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    [$ok, $error] = attempt_login($username, $password);
    if ($ok) {
        redirect($next ?: url('dashboard.php'));
    }
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Вход — <?= e(SITE_NAME) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/css/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/install.css')) ?>">
</head>
<body class="install-body d-flex align-items-center justify-content-center">
<div class="install-card shadow-lg overflow-hidden" style="max-width:420px;width:100%">
  <div class="install-head">
    <div class="install-logo">👷</div>
    <div><h1 class="h5 mb-0"><?= e(SITE_NAME) ?></h1><div class="text-white-50 small">CRM учета рабочих</div></div>
  </div>
  <div class="p-4">
    <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
    <form method="post" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <div class="mb-3">
        <label class="form-label">Логин или email</label>
        <input type="text" name="username" class="form-control" value="<?= e($username) ?>" required autofocus autocomplete="username">
      </div>
      <div class="mb-3">
        <label class="form-label">Пароль</label>
        <input type="password" name="password" class="form-control" required autocomplete="current-password">
      </div>
      <button class="btn btn-primary w-100">Войти</button>
    </form>
    <div class="text-center text-muted small mt-3">Несколько неудачных попыток подряд временно блокируют вход.</div>
  </div>
</div>
</body>
</html>
