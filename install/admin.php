<?php
/**
 * Шаг 4 — Учетная запись администратора.
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
install_session_start();

if (install_is_locked()) { header('Location: index.php'); exit; }
if (empty($_SESSION['install']['step3_ok'])) { header('Location: index.php?step=3'); exit; }

$errors = [];
$d = $_SESSION['install'] ?? [];
$fullName = $d['admin_name'] ?? '';
$username = $d['admin_user'] ?? 'admin';
$email    = $d['admin_email'] ?? '';

/** Оценка сложности пароля 0..4 + текст. */
function install_password_strength(string $p): array
{
    $score = 0;
    if (mb_strlen($p) >= 10) $score++;
    if (mb_strlen($p) >= 14) $score++;
    if (preg_match('/[a-zа-я]/u', $p) && preg_match('/[A-ZА-Я]/u', $p)) $score++;
    if (preg_match('/\d/', $p) && preg_match('/[^A-Za-zА-Яа-я0-9]/u', $p)) $score++;
    $labels = ['Очень слабый', 'Слабый', 'Средний', 'Хороший', 'Отличный'];
    return [$score, $labels[min($score, 4)]];
}

/** Проверка на слишком простой пароль. */
function install_password_rejected(string $p): ?string
{
    if (mb_strlen($p) < 10) return 'Пароль должен содержать не менее 10 символов.';
    $common = ['password', '1234567890', 'qwertyuiop', 'adminadmin', 'пароль123', '1111111111', 'aaaaaaaaaa'];
    foreach ($common as $c) {
        if (mb_strtolower($p) === $c) return 'Пароль слишком простой — используйте уникальную комбинацию.';
    }
    if (preg_match('/^(.)\1*$/', $p)) return 'Пароль не может состоять из одного повторяющегося символа.';
    [$score] = install_password_strength($p);
    if ($score < 2) return 'Пароль слишком простой. Добавьте заглавные буквы, цифры или спецсимволы.';
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    $pass     = (string)($_POST['password'] ?? '');
    $pass2    = (string)($_POST['password2'] ?? '');

    if (mb_strlen($fullName) < 3) $errors[] = 'Укажите имя администратора (не менее 3 символов).';
    if (!preg_match('/^[a-zA-Z0-9_.\-]{3,50}$/', $username)) $errors[] = 'Логин: 3–50 символов, латиница, цифры, «_», «-», «.».';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Некорректный email.';
    if ($err = install_password_rejected($pass)) $errors[] = $err;
    if ($pass !== $pass2) $errors[] = 'Пароли не совпадают.';
    if (mb_strtolower($username) === mb_strtolower($pass)) $errors[] = 'Пароль не должен совпадать с логином.';

    if (!$errors) {
        $_SESSION['install']['admin_name'] = $fullName;
        $_SESSION['install']['admin_user'] = $username;
        $_SESSION['install']['admin_email'] = $email;
        $_SESSION['install']['admin_pass'] = $pass; // хранится в сессии установщика до записи hash
        $_SESSION['install']['step4_ok'] = true;
        header('Location: index.php?step=5');
        exit;
    }
}

install_header('Администратор', 4);
?>
<h2 class="h5 mb-3">Шаг 4. Администратор</h2>
<p class="text-muted">Будет создана учетная запись с полными правами. Пароль хранится только в виде хеша (<code>password_hash()</code>).</p>

<?php if ($errors): ?>
  <div class="alert alert-danger py-2"><ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . esc($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<form method="post" id="adminForm" novalidate autocomplete="off">
  <div class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Имя администратора</label>
      <input type="text" name="full_name" class="form-control" value="<?= esc($fullName) ?>" required maxlength="150">
    </div>
    <div class="col-md-6">
      <label class="form-label">Логин</label>
      <input type="text" name="username" class="form-control" value="<?= esc($username) ?>" required maxlength="50">
    </div>
    <div class="col-md-12">
      <label class="form-label">Email</label>
      <input type="email" name="email" class="form-control" value="<?= esc($email) ?>" required maxlength="150">
    </div>
    <div class="col-md-6">
      <label class="form-label">Пароль</label>
      <input type="password" name="password" id="f_pass" class="form-control" required minlength="10" autocomplete="new-password">
      <div class="progress mt-2" style="height:6px;"><div class="progress-bar" id="pwBar" style="width:0"></div></div>
      <div class="form-text" id="pwLabel">Минимум 10 символов, буквы разного регистра, цифры и спецсимволы.</div>
    </div>
    <div class="col-md-6">
      <label class="form-label">Повтор пароля</label>
      <input type="password" name="password2" id="f_pass2" class="form-control" required minlength="10" autocomplete="new-password">
      <div class="invalid-feedback d-block" id="pwMatch" style="display:none!important"></div>
    </div>
  </div>
  <div class="d-flex justify-content-between mt-4">
    <a href="index.php?step=3" class="btn btn-outline-secondary">← Назад</a>
    <button class="btn btn-primary">Далее: проверка →</button>
  </div>
</form>

<script>
(function () {
  const pass = document.getElementById('f_pass');
  const pass2 = document.getElementById('f_pass2');
  const bar = document.getElementById('pwBar');
  const label = document.getElementById('pwLabel');

  function strength(p) {
    let s = 0;
    if (p.length >= 10) s++;
    if (p.length >= 14) s++;
    if (/[a-zа-я]/.test(p) && /[A-ZА-Я]/.test(p)) s++;
    if (/\d/.test(p) && /[^A-Za-zА-Яа-я0-9]/.test(p)) s++;
    return s;
  }

  pass.addEventListener('input', function () {
    const p = this.value;
    const s = strength(p);
    const conf = [
      ['bg-danger', 'Очень слабый'], ['bg-danger', 'Слабый'],
      ['bg-warning text-dark', 'Средний'], ['bg-info text-dark', 'Хороший'], ['bg-success', 'Отличный']
    ][s];
    bar.className = 'progress-bar ' + conf[0];
    bar.style.width = p ? ((s + 1) * 20) + '%' : '0';
    label.textContent = p ? 'Сложность: ' + conf[1] : 'Минимум 10 символов, буквы разного регистра, цифры и спецсимволы.';
  });

  document.getElementById('adminForm').addEventListener('submit', function (e) {
    if (pass.value !== pass2.value) {
      e.preventDefault();
      alert('Пароли не совпадают.');
    }
  });
})();
</script>
<?php install_footer(); ?>
