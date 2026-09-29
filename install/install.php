<?php
/**
 * Непосредственно установка: таблицы, справочники, роли, права, администратор,
 * конфиг, блокировка. Вызывается POST с шага 5.
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
install_session_start();

if (install_is_locked()) { header('Location: index.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?step=5'); exit; }
foreach (['step1_ok', 'step3_ok', 'step4_ok'] as $need) {
    if (empty($_SESSION['install'][$need])) { header('Location: index.php?step=1'); exit; }
}

$d = $_SESSION['install'];
$root = install_root();
$log = [];      // шаги для отладки
$fatalError = null;

function inst_log(array &$log, string $msg): void { $log[] = $msg; }

try {
    // ---------- 1. Подключение к БД ----------
    [$pdo, $err] = install_connect_mysql($d['db_host'], (int)$d['db_port'], $d['db_user'], $d['db_pass'], $d['db_name']);
    if (!$pdo) {
        throw new RuntimeException(install_mysql_error_message((string)$err));
    }
    inst_log($log, 'Подключение к MySQL — OK');

    $prefix = $d['table_prefix'];

    // ---------- 2. Определение существующих таблиц (идемпотентность) ----------
    $stmt = $pdo->query("SHOW TABLES");
    $existing = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $ourTables = array_values(array_filter($existing, static fn($t) => str_starts_with($t, $prefix)));
    if ($ourTables) {
        inst_log($log, 'Найдено существующих таблиц с префиксом ' . $prefix . ': ' . count($ourTables) . '. Используется CREATE TABLE IF NOT EXISTS — данные будут сохранены.');
    }

    // ---------- 3. Создание таблиц из схемы ----------
    $schemaFile = $root . '/database/schema.sql';
    if (!is_file($schemaFile)) {
        throw new RuntimeException('Файл схемы database/schema.sql не найден. Загрузите файлы проекта заново через FTP.');
    }
    $sql = file_get_contents($schemaFile);
    if ($sql === false) {
        throw new RuntimeException('Не удалось прочитать database/schema.sql. Проверьте права на файл.');
    }
    $sql = strtr($sql, ['{{PREFIX}}' => $prefix, '{{ENGINE}}' => 'InnoDB']);
    $statements = install_split_sql($sql);
    $created = 0;
    foreach ($statements as $s) {
        try {
            $pdo->exec($s);
            $created++;
        } catch (PDOException $e) {
            $m = $e->getMessage();
            if (stripos($m, 'already exists') !== false) {
                continue; // повторная установка поверх существующей структуры
            }
            if (stripos($m, 'CREATE') !== false && stripos($m, 'denied') !== false) {
                throw new RuntimeException('MySQL подключение успешно, но пользователь не имеет права CREATE TABLE.<br><small class="text-muted">' . esc($m) . '</small>');
            }
            throw new RuntimeException('Ошибка при создании таблицы:<br><small class="text-muted">' . esc(str_replace($d['db_pass'], '******', $m)) . '</small>');
        }
    }
    inst_log($log, 'Таблицы и индексы созданы (' . $created . ' запросов), включая foreign keys.');

    // ---------- 4. Роли ----------
    $roles = [
        ['Administrator', 'admin', 'Полный доступ ко всем разделам системы', 1],
        ['Manager', 'manager', 'Работа с рабочими, табелем и финансами', 1],
        ['Accountant', 'accountant', 'Начисления, выплаты, отчеты', 1],
        ['Viewer', 'viewer', 'Только просмотр', 1],
    ];
    $st = $pdo->prepare('INSERT INTO ' . $prefix . 'roles (name, slug, description, is_system) VALUES (?,?,?,?)
                         ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description)');
    foreach ($roles as $r) {
        $st->execute($r);
    }
    inst_log($log, 'Роли созданы: admin, manager, accountant, viewer.');

    // ---------- 5. Права ----------
    $permissions = [];
    $sections = [
        'dashboard' => ['view'],
        'workers' => ['view', 'create', 'edit', 'delete', 'import', 'export'],
        'arrivals' => ['view', 'create', 'edit'],
        'accommodation' => ['view', 'create', 'edit', 'delete'],
        'objects' => ['view', 'create', 'edit', 'delete'],
        'jobs' => ['view', 'create', 'edit', 'delete'],
        'attendance' => ['view', 'create', 'edit', 'delete'],
        'accruals' => ['view', 'create', 'edit', 'delete'],
        'payments' => ['view', 'create', 'edit', 'delete'],
        'documents' => ['view', 'upload', 'delete'],
        'reports' => ['view', 'export'],
        'notifications' => ['view', 'manage'],
        'users' => ['manage'],
        'settings' => ['manage'],
        'audit' => ['view'],
    ];
    foreach ($sections as $sec => $actions) {
        foreach ($actions as $a) {
            $permissions[] = [$sec . '.' . $a, $sec];
        }
    }
    $st = $pdo->prepare('INSERT INTO ' . $prefix . 'permissions (slug, section) VALUES (?,?)
                         ON DUPLICATE KEY UPDATE section=VALUES(section)');
    foreach ($permissions as $p) {
        $st->execute($p);
    }
    inst_log($log, 'Права доступа созданы: ' . count($permissions) . ' шт.');

    // role_permissions: admin = все; manager/accountant/viewer по матрице
    $allPermIds = $pdo->query('SELECT id, slug FROM ' . $prefix . 'permissions')->fetchAll(PDO::FETCH_KEY_PAIR); // id => slug
    $roleIds = $pdo->query('SELECT id, slug FROM ' . $prefix . 'roles')->fetchAll(PDO::FETCH_KEY_PAIR);

    $matrix = [
        'admin' => array_values($allPermIds),
        'manager' => array_values(array_filter($allPermIds, static fn($s) => !in_array($s, ['users.manage', 'settings.manage', 'audit.view', 'workers.delete'], true))),
        'accountant' => ['dashboard.view', 'workers.view', 'attendance.view', 'accruals.view', 'accruals.create', 'accruals.edit', 'payments.view', 'payments.create', 'payments.edit', 'reports.view', 'reports.export', 'notifications.view', 'documents.view'],
        'viewer' => ['dashboard.view', 'workers.view', 'arrivals.view', 'accommodation.view', 'objects.view', 'jobs.view', 'attendance.view', 'accruals.view', 'payments.view', 'documents.view', 'reports.view', 'notifications.view'],
    ];
    $stLink = $pdo->prepare('INSERT IGNORE INTO ' . $prefix . 'role_permissions (role_id, permission_id) VALUES (?,?)');
    $slugToId = array_flip($allPermIds);
    foreach ($matrix as $roleSlug => $permSlugs) {
        $rid = $roleIds[$roleSlug] ?? null;
        if (!$rid) continue;
        foreach ($permSlugs as $ps) {
            if (isset($slugToId[$ps])) {
                $stLink->execute([$rid, $slugToId[$ps]]);
            }
        }
    }
    inst_log($log, 'Связи ролей и прав настроены.');

    // ---------- 6. Справочники ----------
    $docTypes = [
        ['passport', 'Паспорт РФ', 0, 10],
        ['passport_foreign', 'Заграничный паспорт', 1, 15],
        ['snils', 'СНИЛС', 0, 20],
        ['inn', 'ИНН', 0, 30],
        ['migration_card', 'Миграционная карта', 1, 40],
        ['patent', 'Патент', 1, 50],
        ['work_permit', 'Разрешение на работу', 1, 60],
        ['contract', 'Договор', 1, 70],
        ['medical_book', 'Медкнижка', 1, 80],
        ['other', 'Другой документ', 0, 90],
    ];
    $st = $pdo->prepare('INSERT INTO ' . $prefix . 'document_types (slug, name, requires_expiry, sort_order) VALUES (?,?,?,?)
                         ON DUPLICATE KEY UPDATE name=VALUES(name)');
    foreach ($docTypes as $t) {
        $st->execute($t);
    }

    $positions = ['Разнорабочий', 'Грузчик', 'Строитель', 'Монтажник', 'Сварщик', 'Маляр',
                  'Штукатур', 'Плиточник', 'Электрик', 'Сантехник', 'Плотник', 'Кровельщик',
                  'Водитель', 'Уборщик', 'Охранник'];
    $st = $pdo->prepare('INSERT IGNORE INTO ' . $prefix . 'positions (name, sort_order) VALUES (?,?)');
    $i = 10;
    foreach ($positions as $p) {
        $st->execute([$p, $i]);
        $i += 10;
    }
    inst_log($log, 'Справочники созданы: типы документов, должности.');

    // ---------- 7. Настройки ----------
    $settings = [
        'site_name' => $d['site_name'],
        'site_url' => $d['site_url'],
        'timezone' => $d['timezone'],
        'db_version' => '1',
        'installed_at' => date('Y-m-d H:i:s'),
        'docs_expiry_warning_days' => '30',
        'date_format' => 'd.m.Y',
        'currency' => 'RUB',
    ];
    $st = $pdo->prepare('INSERT INTO ' . $prefix . 'settings (`key`, `value`) VALUES (?,?)
                         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
    foreach ($settings as $k => $v) {
        $st->execute([$k, $v]);
    }
    inst_log($log, 'Настройки записаны.');

    // ---------- 8. Администратор ----------
    $hash = password_hash($d['admin_pass'], PASSWORD_DEFAULT);
    if ($hash === false) {
        throw new RuntimeException('Не удалось создать хеш пароля.');
    }
    $st = $pdo->prepare('SELECT id FROM ' . $prefix . 'users WHERE username = ? OR email = ?');
    $st->execute([$d['admin_user'], $d['admin_email']]);
    $existingAdmin = $st->fetchColumn();
    if ($existingAdmin) {
        $st = $pdo->prepare('UPDATE ' . $prefix . 'users SET password_hash=?, full_name=?, role_id=? WHERE id=?');
        $st->execute([$hash, $d['admin_name'], $roleIds['admin'], (int)$existingAdmin]);
        inst_log($log, 'Пользователь с таким логином уже существовал — пароль обновлен.');
    } else {
        $st = $pdo->prepare('INSERT INTO ' . $prefix . 'users (role_id, full_name, username, email, password_hash) VALUES (?,?,?,?,?)');
        $st->execute([$roleIds['admin'], $d['admin_name'], $d['admin_user'], $d['admin_email'], $hash]);
        inst_log($log, 'Администратор создан.');
    }
    // Пароль больше не нужен нигде
    unset($_SESSION['install']['admin_pass']);

    // ---------- 9. Каталог uploads ----------
    $uploadErrors = install_create_upload_dirs($root);
    if ($uploadErrors) {
        throw new RuntimeException(implode('<br>', $uploadErrors));
    }
    inst_log($log, 'Хранилище /uploads готово.');

    // ---------- 10. Конфиг ----------
    $installKey = bin2hex(random_bytes(16));
    [$ok, $cfgErr] = install_write_config($root, [
        'db_host' => $d['db_host'],
        'db_port' => (int)$d['db_port'],
        'db_name' => $d['db_name'],
        'db_user' => $d['db_user'],
        'db_pass' => $d['db_pass'],
        'table_prefix' => $prefix,
        'site_name' => $d['site_name'],
        'site_url' => $d['site_url'],
        'timezone' => $d['timezone'],
        'install_key' => $installKey,
    ]);
    if (!$ok) {
        throw new RuntimeException((string)$cfgErr);
    }
    inst_log($log, 'config.php создан и проверен.');

    // Проверка конфигурации: перечитаем и подключимся
    if (!is_file($root . '/config/config.php')) {
        throw new RuntimeException('Конфигурационный файл не был создан.');
    }
    define('CRM_APP', true);
    require $root . '/config/config.php';
    if (!defined('DB_HOST') || DB_NAME !== $d['db_name']) {
        throw new RuntimeException('Проверка конфигурации не пройдена: config.php поврежден.');
    }
    inst_log($log, 'Проверка конфигурации — OK.');

    // ---------- 11. Lock-файл ----------
    $lockContent = json_encode([
        'installed_at' => date('c'),
        'app_version' => '1.0.0',
        'db_version' => 1,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    if (@file_put_contents(install_lock_file(), $lockContent) === false) {
        // Пытаемся хотя бы создать пустой
        @touch(install_lock_file());
    }
    if (!is_file(install_lock_file())) {
        inst_log($log, '⚠ Не удалось создать installed.lock — вручную создайте этот файл или удалите каталог /install/.');
    } else {
        @chmod(install_lock_file(), 0644);
        inst_log($log, 'Установка заблокирована (installed.lock).');
    }

    // Очистка сессии установщика
    $_SESSION = [];
    session_destroy();

    header('Location: index.php?step=6');
    exit;

} catch (RuntimeException $e) {
    $fatalError = $e->getMessage();
} catch (Throwable $e) {
    // Пользователю — человеческое сообщение, детали в лог
    error_log('[CRM INSTALL] ' . $e->getMessage());
    $fatalError = 'Во время установки произошла непредвиденная ошибка.<br>Проверьте часть базы данных и повторите установку — процесс идемпотентен и продолжится корректно.';
}

install_header('Ошибка установки', 5);
?>
<h2 class="h5 mb-3 text-danger">✗ Установка не завершена</h2>
<div class="alert alert-danger"><?= $fatalError ?></div>

<p class="text-muted small">Система не помечена как установленная. Устраните проблему и запустите установку повторно —
созданные ранее таблицы не будут испорчены (используется <code>IF NOT EXISTS</code>).</p>

<?php if ($log): ?>
<details class="mb-3"><summary class="text-muted small">Технический журнал (последние шаги)</summary>
<ul class="small text-secondary mt-2"><?php foreach ($log as $l) echo '<li>' . esc($l) . '</li>'; ?></ul></details>
<?php endif; ?>

<a href="index.php?step=5" class="btn btn-primary">← Вернуться к установке и повторить</a>
<?php install_footer(); ?>
