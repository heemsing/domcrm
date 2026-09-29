<?php
/**
 * Шаг 5 — Итоговая проверка и запуск установки.
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
install_session_start();

if (install_is_locked()) { header('Location: index.php'); exit; }
foreach (['step1_ok', 'step3_ok', 'step4_ok'] as $need) {
    if (empty($_SESSION['install'][$need])) {
        header('Location: index.php?step=' . ($need === 'step1_ok' ? 1 : ($need === 'step3_ok' ? 3 : 4)));
        exit;
    }
}

$d = $_SESSION['install'];

// Проверка каталога uploads до запуска
$uploadErrors = install_create_upload_dirs($root = install_root());

$rows = [
    ['Проверка сервера', true, 'index.php?step=1'],
    ['Подключение MySQL', true, 'index.php?step=3'],
    ['База данных', true, 'index.php?step=3'],
    ['Конфигурация сайта', true, 'index.php?step=2'],
    ['Администратор', true, 'index.php?step=4'],
    ['Хранилище файлов', empty($uploadErrors), 'index.php?step=5'],
];
$canInstall = !in_array(false, array_column($rows, 1), true);

install_header('Установка', 5);
?>
<h2 class="h5 mb-3">Шаг 5. Установка</h2>
<p class="text-muted">Проверьте итоговые параметры и запустите установку.</p>

<table class="table table-sm summary-table">
  <tbody>
  <?php foreach ($rows as [$label, $ok, $href]): ?>
    <tr>
      <td><a href="<?= esc($href) ?>" class="text-decoration-none"><?= esc($label) ?></a></td>
      <td class="text-end">
        <?= $ok ? '<span class="badge bg-success">✓</span>' : '<span class="badge bg-danger">✗</span>' ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<div class="card bg-light border-0 mb-3">
  <div class="card-body small">
    <div class="row g-2">
      <div class="col-md-6"><b>Сайт:</b> <?= esc($d['site_name']) ?></div>
      <div class="col-md-6"><b>URL:</b> <?= esc($d['site_url']) ?></div>
      <div class="col-md-6"><b>Часовой пояс:</b> <?= esc($d['timezone']) ?></div>
      <div class="col-md-6"><b>MySQL:</b> <?= esc($d['db_user']) ?>@<?= esc($d['db_host']) ?>:<?= esc($d['db_port']) ?>/<?= esc($d['db_name']) ?></div>
      <div class="col-md-6"><b>Префикс таблиц:</b> <code><?= esc($d['table_prefix']) ?></code></div>
      <div class="col-md-6"><b>Администратор:</b> <?= esc($d['admin_name']) ?> (<?= esc($d['admin_user']) ?>)</div>
    </div>
  </div>
</div>

<?php if (!empty($uploadErrors)): ?>
  <div class="alert alert-danger py-2"><ul class="mb-0"><?php foreach ($uploadErrors as $e) echo '<li>' . esc($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<?php if (!$canInstall): ?>
  <div class="alert alert-warning">Исправьте отмеченные проблемы перед установкой.</div>
<?php else: ?>
<form method="post" action="install.php" id="runForm">
  <button class="btn btn-lg btn-success w-100 py-3" type="submit" id="runBtn">🚀 УСТАНОВИТЬ СИСТЕМУ</button>
  <div class="form-text text-center mt-2">Будут созданы таблицы, справочники, роли, права, администратор и конфигурация.</div>
</form>
<?php endif; ?>

<div class="d-flex justify-content-between mt-4">
  <a href="index.php?step=4" class="btn btn-outline-secondary">← Назад</a>
</div>
<?php install_footer(); ?>
