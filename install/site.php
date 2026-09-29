<?php
/**
 * Шаг 2 — Настройки сайта.
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
install_session_start();

if (install_is_locked()) { header('Location: index.php'); exit; }
if (empty($_SESSION['install']['step1_ok'])) { header('Location: index.php?step=1'); exit; }

$errors = [];
$data = $_SESSION['install'] ?? [];
$siteName = $data['site_name'] ?? 'Рабочий Дом';
$siteUrl  = $data['site_url'] ?? install_detect_url();
$timezone = $data['timezone'] ?? 'Europe/Moscow';

$zones = ['Europe/Moscow', 'Europe/Kaliningrad', 'Europe/Samara', 'Asia/Yekaterinburg',
          'Asia/Omsk', 'Asia/Krasnoyarsk', 'Asia/Irkutsk', 'Asia/Vladivostok',
          'Europe/Kiev', 'Europe/Prague', 'Europe/Berlin', 'Europe/London',
          'Asia/Tashkent', 'Asia/Bishkek', 'Asia/Almaty', 'UTC'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $siteName = trim((string)($_POST['site_name'] ?? ''));
    $siteUrl  = trim((string)($_POST['site_url'] ?? ''));
    $timezone = trim((string)($_POST['timezone'] ?? ''));

    if ($siteName === '' || mb_strlen($siteName) < 2) {
        $errors[] = 'Укажите название сайта (не менее 2 символов).';
    }
    if (!filter_var($siteUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $siteUrl)) {
        $errors[] = 'URL сайта должен начинаться с http:// или https://';
    }
    if (!in_array($timezone, $zones, true)) {
        $errors[] = 'Некорректный часовой пояс.';
    }

    if (!$errors) {
        $_SESSION['install']['site_name'] = $siteName;
        $_SESSION['install']['site_url'] = rtrim($siteUrl, '/');
        $_SESSION['install']['timezone'] = $timezone;
        header('Location: index.php?step=3');
        exit;
    }
}

install_header('Настройки сайта', 2);
?>
<h2 class="h5 mb-3">Шаг 2. Настройки сайта</h2>
<p class="text-muted">Основные параметры системы. URL определен автоматически — при необходимости исправьте его.</p>

<?php if ($errors): ?>
  <div class="alert alert-danger py-2"><ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . esc($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<form method="post" novalidate>
  <div class="mb-3">
    <label class="form-label">Название сайта</label>
    <input type="text" name="site_name" class="form-control" value="<?= esc($siteName) ?>" maxlength="100" required placeholder="Рабочий Дом">
    <div class="form-text">Отображается в шапке, заголовках и отчетах.</div>
  </div>
  <div class="mb-3">
    <label class="form-label">URL сайта</label>
    <input type="url" name="site_url" class="form-control" value="<?= esc($siteUrl) ?>" maxlength="200" required placeholder="https://example.ru">
    <div class="form-text">Без завершающего «/». Используется для редиректов и absolute-ссылок.</div>
  </div>
  <div class="mb-3">
    <label class="form-label">Часовой пояс</label>
    <select name="timezone" class="form-select">
      <?php foreach ($zones as $z): ?>
        <option value="<?= esc($z) ?>" <?= $z === $timezone ? 'selected' : '' ?>><?= esc($z) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="d-flex justify-content-between mt-4">
    <a href="index.php?step=1" class="btn btn-outline-secondary">← Назад</a>
    <button class="btn btn-primary">Далее: база данных →</button>
  </div>
</form>
<?php install_footer(); ?>
