<?php
/**
 * Шаг 3 — Подключение MySQL.
 * AJAX: ?ajax=test (проверка подключения), ?ajax=create_db (создание БД).
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
install_session_start();

if (install_is_locked()) { header('Location: index.php'); exit; }
if (empty($_SESSION['install']['step1_ok'])) { header('Location: index.php?step=1'); exit; }

$d = $_SESSION['install'] ?? [];
$dbHost = $d['db_host'] ?? 'localhost';
$dbPort = $d['db_port'] ?? '3306';
$dbName = $d['db_name'] ?? '';
$dbUser = $d['db_user'] ?? '';
$dbPass = $d['db_pass'] ?? '';
$prefix = $d['table_prefix'] ?? 'crm_';

// ---------- AJAX endpoints ----------
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['ajax'];

    if ($action === 'test') {
        $host = trim((string)($_POST['db_host'] ?? ''));
        $port = (int)($_POST['db_port'] ?? 3306);
        $name = trim((string)($_POST['db_name'] ?? ''));
        $user = trim((string)($_POST['db_user'] ?? ''));
        $pass = (string)($_POST['db_pass'] ?? '');

        if ($host === '' || $name === '' || $user === '') {
            echo json_encode(['ok' => false, 'html' => 'Заполните хост, имя базы и пользователя.']);
            exit;
        }
        [$pdo, $err] = install_connect_mysql($host, $port, $user, $pass, $name);
        if (!$pdo) {
            echo json_encode(['ok' => false, 'html' => install_mysql_error_message((string)$err)]);
            exit;
        }
        // Проверка прав CREATE TABLE фактическим созданием тестовой таблицы
        $prefixProbe = install_valid_prefix(trim((string)($_POST['table_prefix'] ?? 'crm_')))
            ? trim((string)$_POST['table_prefix']) : 'crm_';
        [$canCreate, $createErr] = install_test_create_table($pdo, $prefixProbe);
        if (!$canCreate) {
            echo json_encode([
                'ok' => false,
                'html' => "MySQL подключение успешно,<br>но пользователь не имеет права CREATE TABLE.<br><small class='text-muted'>" . esc((string)$createErr) . "</small>",
            ]);
            exit;
        }
        $ver = '';
        try { $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn(); } catch (Throwable) {}
        echo json_encode(['ok' => true, 'html' => '✓ Подключение успешно' . ($ver ? '. Сервер: <b>' . esc($ver) . '</b>' : '') . '. Права на создание таблиц подтверждены.']);
        exit;
    }

    if ($action === 'create_db') {
        $host = trim((string)($_POST['db_host'] ?? ''));
        $port = (int)($_POST['db_port'] ?? 3306);
        $name = trim((string)($_POST['db_name'] ?? ''));
        $user = trim((string)($_POST['db_user'] ?? ''));
        $pass = (string)($_POST['db_pass'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9_]{2,40}$/', $name)) {
            echo json_encode(['ok' => false, 'html' => 'Имя базы должно содержать только латиницу, цифры и «_» (2–40 символов).']);
            exit;
        }
        // подключаемся без выбора БД
        [$pdo, $err] = install_connect_mysql($host, $port, $user, $pass, null);
        if (!$pdo) {
            echo json_encode(['ok' => false, 'html' => install_mysql_error_message((string)$err)]);
            exit;
        }
        try {
            $pdo->exec('CREATE DATABASE `' . str_replace('`', '', $name) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            echo json_encode(['ok' => true, 'html' => '✓ База данных <b>' . esc($name) . '</b> создана.']);
        } catch (PDOException $e) {
            echo json_encode([
                'ok' => false,
                'soft' => true,
                'html' => "Не удалось автоматически создать базу данных.<br>Создайте базу данных через панель хостинга или phpMyAdmin, после чего вернитесь к установке и продолжите.<br><small class='text-muted'>" . esc(str_replace($pass, '******', $e->getMessage())) . "</small>",
            ]);
        }
        exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'html' => 'Неизвестное действие.']);
    exit;
}

// ---------- Форма ----------
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['ajax'])) {
    $dbHost = trim((string)($_POST['db_host'] ?? ''));
    $dbPort = trim((string)($_POST['db_port'] ?? '3306'));
    $dbName = trim((string)($_POST['db_name'] ?? ''));
    $dbUser = trim((string)($_POST['db_user'] ?? ''));
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $prefix = trim((string)($_POST['table_prefix'] ?? 'crm_'));

    if ($dbHost === '') $errors[] = 'Укажите хост базы данных.';
    if (!preg_match('/^\d{1,5}$/', $dbPort) || (int)$dbPort < 1 || (int)$dbPort > 65535) $errors[] = 'Некорректный порт.';
    if (!preg_match('/^[a-zA-Z0-9_]{2,40}$/', $dbName)) $errors[] = 'Некорректное имя базы (допустимы латиница, цифры, «_»).';
    if ($dbUser === '') $errors[] = 'Укажите пользователя MySQL.';
    if (!install_valid_prefix($prefix)) $errors[] = 'Префикс таблиц: латинская буква, затем буквы/цифры/«_», до 16 символов (например crm_).';

    if (!$errors) {
        // финальная реальная проверка перед сохранением
        [$pdo, $err] = install_connect_mysql($dbHost, (int)$dbPort, $dbUser, $dbPass, $dbName);
        if (!$pdo) {
            $errors[] = install_mysql_error_message((string)$err);
        } else {
            $pp = install_valid_prefix($prefix) ? $prefix : 'crm_';
            [$canCreate, $cErr] = install_test_create_table($pdo, $pp);
            if (!$canCreate) {
                $errors[] = 'MySQL подключение успешно, но пользователь не имеет права CREATE TABLE.';
            } else {
                $_SESSION['install']['db_host'] = $dbHost;
                $_SESSION['install']['db_port'] = $dbPort;
                $_SESSION['install']['db_name'] = $dbName;
                $_SESSION['install']['db_user'] = $dbUser;
                $_SESSION['install']['db_pass'] = $dbPass;
                $_SESSION['install']['table_prefix'] = $prefix;
                $_SESSION['install']['step3_ok'] = true;
                header('Location: index.php?step=4');
                exit;
            }
        }
    }
}

install_header('Подключение MySQL', 3);
?>
<h2 class="h5 mb-3">Шаг 3. Подключение MySQL</h2>
<p class="text-muted">Укажите данные созданной базы данных. Если прав на создание базы нет — создайте её в панели хостинга (Timeweb, Beget, ISPmanager, cPanel).</p>

<div id="dbResult"></div>

<form method="post" id="dbForm" novalidate autocomplete="off">
  <div class="row g-3">
    <div class="col-md-8">
      <label class="form-label">Хост БД</label>
      <input type="text" name="db_host" id="f_host" class="form-control" value="<?= esc($dbHost) ?>" required>
    </div>
    <div class="col-md-4">
      <label class="form-label">Порт</label>
      <input type="number" name="db_port" id="f_port" class="form-control" value="<?= esc($dbPort) ?>" min="1" max="65535" required>
    </div>
    <div class="col-md-6">
      <label class="form-label">Имя базы данных</label>
      <input type="text" name="db_name" id="f_name" class="form-control" value="<?= esc($dbName) ?>" required>
    </div>
    <div class="col-md-6">
      <label class="form-label">Префикс таблиц</label>
      <input type="text" name="table_prefix" id="f_prefix" class="form-control" value="<?= esc($prefix) ?>" required>
      <div class="form-text">Например <code>crm_</code> → <code><?= esc($prefix ?: 'crm_') ?>workers</code>. Позволяет ставить несколько систем в одну БД.</div>
    </div>
    <div class="col-md-6">
      <label class="form-label">Пользователь</label>
      <input type="text" name="db_user" id="f_user" class="form-control" value="<?= esc($dbUser) ?>" required>
    </div>
    <div class="col-md-6">
      <label class="form-label">Пароль</label>
      <input type="password" name="db_pass" id="f_pass" class="form-control" value="<?= esc($dbPass) ?>">
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger mt-3 py-2"><ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . $e . '</li>'; ?></ul></div>
  <?php endif; ?>

  <div class="d-flex flex-wrap gap-2 mt-4">
    <button type="button" class="btn btn-outline-primary" id="btnTest">Проверить подключение</button>
    <button type="button" class="btn btn-outline-secondary" id="btnCreateDb">Создать базу данных автоматически</button>
  </div>
  <div class="d-flex justify-content-between mt-4">
    <a href="index.php?step=2" class="btn btn-outline-secondary">← Назад</a>
    <button class="btn btn-primary" id="btnNext">Далее: администратор →</button>
  </div>
</form>

<script>
(function () {
  const form = document.getElementById('dbForm');
  const out = document.getElementById('dbResult');
  const data = () => new FormData(form);

  function show(html, ok) {
    out.innerHTML = '<div class="alert mt-3 py-2 ' + (ok ? 'alert-success' : 'alert-danger') + '">' + html + '</div>';
    out.scrollIntoView({behavior: 'smooth', block: 'nearest'});
  }

  async function ajax(url) {
    try {
      const r = await fetch(url, {method: 'POST', body: data()});
      const j = await r.json();
      return j;
    } catch (e) {
      return {ok: false, html: 'Ошибка соединения с установщиком. Обновите страницу.'};
    }
  }

  document.getElementById('btnTest').addEventListener('click', async function () {
    this.disabled = true;
    const j = await ajax('?ajax=test');
    show(j.html, j.ok);
    this.disabled = false;
  });

  document.getElementById('btnCreateDb').addEventListener('click', async function () {
    this.disabled = true;
    const j = await ajax('?ajax=create_db');
    show(j.html, j.ok);
    this.disabled = false;
  });

  // Кнопка «Далее» сначала реально проверяет подключение
  document.getElementById('btnNext').addEventListener('click', async function (e) {
    if (this.dataset.checked === '1') return; // пропускаем для повторной отправки
    e.preventDefault();
    this.disabled = true;
    const j = await ajax('?ajax=test');
    if (j.ok) {
      this.dataset.checked = '1';
      this.disabled = false;
      form.requestSubmit();
    } else {
      show(j.html, false);
      this.disabled = false;
    }
  });
})();
</script>
<?php install_footer(); ?>
