<?php
/**
 * Шаг 1 — Проверка сервера.
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
install_session_start();

if (install_is_locked()) {
    header('Location: index.php?locked=1');
    exit;
}

$root = install_root();

$checks = [];
$criticalFail = false;
$hasWarning = false;

// PHP version
$phpOk = PHP_VERSION_ID >= 80200;
$checks[] = [
    'name' => 'Версия PHP',
    'value' => PHP_VERSION,
    'ok' => $phpOk,
    'critical' => true,
    'warn' => !$phpOk && PHP_VERSION_ID >= 80000,
    'hint' => $phpOk ? null : 'Требуется PHP 8.2 или новее. Обновите версию PHP в панели хостинга.',
];

// Extensions
$exts = [
    ['PDO', 'pdo', true],
    ['PDO MySQL', 'pdo_mysql', true],
    ['JSON', 'json', true],
    ['mbstring', 'mbstring', true],
    ['fileinfo', 'fileinfo', true],
    ['OpenSSL', 'openssl', false, 'Нужен для защищенных соединений и генерации ключей.'],
    ['cURL', 'curl', false, 'Опционально: нужен для внешних интеграций.'],
    ['Session', '', true], // special
    ['Zip (экспорт XLSX)', 'zip', false, 'Опционально: экспорт Excel.'],
    ['GD или Imagick (обработка фото)', (extension_loaded('gd') || extension_loaded('imagick')) ? 'gd' : 'no_gd', false, 'Опционально: миниатюры фотографий.'],
];
foreach ($exts as [$label, $ext, $critical, $hint]) {
    if ($label === 'Session') {
        $ok = function_exists('session_start');
    } else {
        $ok = $ext !== '' && $ext !== 'no_gd' && extension_loaded($ext);
    }
    if (!$ok && $critical) {
        $criticalFail = true;
    }
    if (!$ok && !$critical) {
        $hasWarning = true;
    }
    $checks[] = [
        'name' => $label,
        'value' => $ok ? 'Поддерживается' : 'Не поддерживается',
        'ok' => $ok,
        'critical' => $critical,
        'warn' => !$ok && !$critical,
        'hint' => $ok ? null : ($hint ?? 'Обязательное расширение. Включите его в php.ini или панели хостинга.'),
    ];
}

// MySQL driver present
$mysqlDriver = extension_loaded('pdo_mysql');
$checks[] = [
    'name' => 'Драйвер MySQL/MariaDB',
    'value' => $mysqlDriver ? 'PDO MySQL активен' : 'Отсутствует',
    'ok' => $mysqlDriver,
    'critical' => true,
    'warn' => false,
    'hint' => $mysqlDriver ? null : 'Установите расширение pdo_mysql.',
];

//Writable dirs
$writableTargets = [
    'Корень проекта' => $root,
    'Каталог /config' => $root . '/config',
    'Каталог /uploads' => $root . '/uploads',
    'Каталог /database' => $root . '/database',
];
foreach ($writableTargets as $label => $dir) {
    if (!is_dir($dir)) {
        // config/uploads могут быть созданы установщиком — проверим родителя
        $dirOk = is_writable(dirname($dir));
        $status = $dirOk ? 'Будет создан автоматически' : 'Нет прав на создание';
        $ok = $dirOk;
    } else {
        $ok = is_writable($dir);
        $status = $ok ? 'Доступен для записи' : 'Недоступен для записи';
    }
    if (!$ok) {
        $criticalFail = true;
    }
    $checks[] = [
        'name' => $label,
        'value' => $status,
        'ok' => $ok,
        'critical' => true,
        'warn' => false,
        'hint' => $ok ? null : "Проверьте права доступа к каталогу {$dir}. Требуется запись (755/775).",
    ];
}

// Uploads enabled
$uploadOn = filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN);
$checks[] = [
    'name' => 'Загрузка файлов (file_uploads)',
    'value' => $uploadOn ? 'Включена' : 'Отключена',
    'ok' => $uploadOn,
    'critical' => false,
    'warn' => !$uploadOn,
    'hint' => $uploadOn ? null : 'Включите file_uploads = On в php.ini — без этого нельзя загружать документы и фото.',
];

// Sizes
$umf = ini_get('upload_max_filesize');
$pms = ini_get('post_max_size');
$ml = ini_get('memory_limit');

$toBytes = static function (string $v): int {
    $v = trim($v);
    if ($v === '' || $v === '-1') return PHP_INT_MAX;
    $unit = strtolower(substr($v, -1));
    $num = (int)$v;
    return match ($unit) {
        'g' => $num * 1024 ** 3,
        'm' => $num * 1024 ** 2,
        'k' => $num * 1024,
        default => $num,
    };
};

$umfWarn = $toBytes((string)$umf) < 2 * 1024 * 1024;
$pmsWarn = $toBytes((string)$pms) < 2 * 1024 * 1024;
$mlWarn = $toBytes((string)$ml) < 128 * 1024 * 1024 && (string)$ml !== '-1';
if ($umfWarn || $pmsWarn || $mlWarn) $hasWarning = true;

$checks[] = ['name' => 'upload_max_filesize', 'value' => (string)$umf, 'ok' => !$umfWarn, 'critical' => false, 'warn' => $umfWarn, 'hint' => $umfWarn ? 'Рекомендуется не менее 8M для загрузки документов (рекомендация).' : null];
$checks[] = ['name' => 'post_max_size', 'value' => (string)$pms, 'ok' => !$pmsWarn, 'critical' => false, 'warn' => $pmsWarn, 'hint' => $pmsWarn ? 'Рекомендуется не менее 8M.' : null];
$checks[] = ['name' => 'memory_limit', 'value' => (string)$ml, 'ok' => !$mlWarn, 'critical' => false, 'warn' => $mlWarn, 'hint' => $mlWarn ? 'Рекомендуется не менее 128M.' : null];

// Session works
$sessionOk = isset($_SESSION['install_check']) ? true : false;
if (!isset($_SESSION['install_check'])) {
    $_SESSION['install_check'] = 1;
}
$checks[] = ['name' => 'Сессии PHP', 'value' => $sessionOk || isset($_SESSION['install_check']) ? 'Работают' : 'Ошибка', 'ok' => true, 'critical' => true, 'warn' => false, 'hint' => null];

// .htaccess
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? '';
$isApache = stripos($serverSoftware, 'apache') !== false;
$htaccessExists = is_file($root . '//.htaccess');
if ($isApache) {
    $checks[] = [
        'name' => '.htaccess (Apache)',
        'value' => $htaccessExists ? 'Присутствует' : 'Отсутствует',
        'ok' => $htaccessExists,
        'critical' => false,
        'warn' => !$htaccessExists,
        'hint' => $htaccessExists ? null : 'Файл .htaccess из архива не был загружен. Защита служебных каталогов будет работать хуже. Загрузите .htaccess через FTP (включая показ скрытых файлов).',
    ];
} else {
    $checks[] = [
        'name' => 'Веб-сервер',
        'value' => $serverSoftware ?: 'Не определен',
        'ok' => true,
        'critical' => false,
        'warn' => false,
        'hint' => 'Сервер не Apache — убедитесь, что каталоги /config и /install закрыты от внешнего доступа правилами вашего сервера.',
    ];
}

// Save step 1 result
if (!$criticalFail) {
    $_SESSION['install']['step1_ok'] = true;
} else {
    unset($_SESSION['install']['step1_ok']);
}
install_header('Проверка сервера', 1); ?>

<h2 class="h5 mb-3">Шаг 1. Проверка сервера</h2>
<p class="text-muted">Проверяем окружение перед установкой. Критические пункты должны быть отмечены «✓».</p>

<table class="table table-sm align-middle req-table">
  <thead><tr><th>Проверка</th><th>Результат</th><th class="text-end">Статус</th></tr></thead>
  <tbody>
  <?php foreach ($checks as $c): ?>
    <tr>
      <td><?= esc($c['name']) ?></td>
      <td class="text-muted small"><?= esc($c['value']) ?>
        <?php if (!empty($c['hint'])): ?><div class="small text-secondary"><?= $c['hint'] ?></div><?php endif; ?>
      </td>
      <td class="text-end">
        <?php if ($c['ok']): ?>
          <span class="badge bg-success">✓ Поддерживается</span>
        <?php elseif ($c['warn']): ?>
          <span class="badge bg-warning text-dark">⚠ Предупреждение</span>
        <?php else: ?>
          <span class="badge bg-danger">✗ Не поддерживается</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php if ($criticalFail): ?>
  <div class="alert alert-danger">
    <strong>Установка не может быть продолжена.</strong><br>
    На сервере отсутствуют критические компоненты. Исправьте отмеченные пункты и обновите страницу.
  </div>
<?php else: ?>
  <?php if ($hasWarning): ?>
    <div class="alert alert-warning py-2">Есть некритичные предупреждения — установку можно продолжить, но рекомендуется их устранить.</div>
  <?php endif; ?>
  <div class="alert alert-success py-2">✓ Окружение подходит для установки.</div>
<?php endif; ?>

<div class="d-flex justify-content-between mt-4">
  <a href="index.php" class="btn btn-outline-secondary btn-sm">↻ Обновить проверку</a>
  <?php if (!$criticalFail): ?>
    <a href="index.php?step=2" class="btn btn-primary">Далее: настройки сайта →</a>
  <?php endif; ?>
</div>

<?php install_footer(); ?>
