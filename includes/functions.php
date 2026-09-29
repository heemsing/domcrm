<?php
/**
 * Общие функции приложения: вывод, даты, деньги, flash-сообщения, аудит.
 */
declare(strict_types=1);
defined('CRM_APP') or die('Direct access denied');

/** Экранирование HTML (XSS). */
function e(null|string|int|float $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Redirect и выход. */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** Относительный URL от корня сайта (учитывает подкаталог установки). */
function url(string $path = ''): string
{
    $base = rtrim(SITE_URL, '/');
    return $base . '/' . ltrim($path, '/');
}

/** Flash-сообщения. */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_get(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** Форматирование денег: 12 345,67 ₽ */
function money(null|string|int|float $v): string
{
    return number_format((float)$v, 2, ',', ' ') . ' ₽';
}

/** Число с разделителями тысяч. */
function num(int|float $v): string
{
    return number_format($v, (float)$v != (int)$v ? 2 : 0, ',', ' ');
}

/** Дата d.m.Y из MySQL-дата/дата-время. */
function fdate(null|string $v, bool $withTime = false): string
{
    if (!$v || str_starts_with($v, '0000')) {
        return '—';
    }
    $ts = strtotime($v);
    if (!$ts) return '—';
    return date($withTime ? 'd.m.Y H:i' : 'd.m.Y', $ts);
}

/** Статусы рабочего — человекочитаемые. */
function worker_status_label(string $s): string
{
    return [
        'new' => 'Новый', 'arrived' => 'Прибыл', 'working' => 'Работает',
        'check_out' => 'Выбыл', 'fired' => 'Уволен', 'archived' => 'Архив',
    ][$s] ?? $s;
}

function worker_status_badge(string $s): string
{
    return [
        'new' => 'secondary', 'arrived' => 'info', 'working' => 'success',
        'check_out' => 'warning', 'fired' => 'danger', 'archived' => 'dark',
    ][$s] ?? 'secondary';
}

function attendance_status_label(string $s): string
{
    return [
        'worked' => 'Работал', 'day_off' => 'Выходной', 'absent' => 'Не вышел',
        'truancy' => 'Прогул', 'sick' => 'Больничный', 'vacation' => 'Отпуск',
    ][$s] ?? $s;
}

function accrual_type_label(string $s): string
{
    return [
        'salary' => 'Зарплата', 'bonus' => 'Премия', 'overtime' => 'Переработка',
        'compensation' => 'Компенсация', 'fine' => 'Штраф', 'deduction' => 'Удержание', 'other' => 'Другое',
    ][$s] ?? $s;
}

function payment_method_label(string $s): string
{
    return [
        'cash' => 'Наличные', 'card' => 'Карта', 'transfer' => 'Перевод',
        'advance' => 'Аванс', 'other' => 'Другое',
    ][$s] ?? $s;
}

function pay_type_label(string $s): string
{
    return ['hourly' => 'Часовая', 'daily' => 'Дневная', 'monthly' => 'Месячная', 'piece' => 'Сдельная'][$s] ?? $s;
}

/** ФИО рабочего из строки БД. */
function fio(array $w): string
{
    return trim(($w['last_name'] ?? '') . ' ' . ($w['first_name'] ?? '') . ' ' . ($w['middle_name'] ?? ''));
}

/** Запись в журнал действий. */
function audit_log(string $action, string $entity, ?int $entityId = null, ?array $old = null, ?array $new = null): void
{
    try {
        // Не логируем чувствительные поля
        foreach (['old_values', 'new_values'] as $k => $arr) {
            $$k = is_array($arr) ? array_diff_key($arr, array_flip(['password', 'password_hash', 'db_pass'])) : $arr;
        }
        db_query(
            'INSERT INTO ' . t('audit_logs') . ' (user_id, action, entity, entity_id, old_values, new_values, ip_address, user_agent)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $_SESSION['user_id'] ?? null,
                mb_substr($action, 0, 50),
                mb_substr($entity, 0, 50),
                $entityId,
                $old_values !== null ? json_encode($old_values, JSON_UNESCAPED_UNICODE) : null,
                $new_values !== null ? json_encode($new_values, JSON_UNESCAPED_UNICODE) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]
        );
    } catch (Throwable $ex) {
        error_log('[CRM AUDIT] ' . $ex->getMessage());
    }
}

/** Событие жизненного цикла рабочего. */
function worker_event(int $workerId, string $type, string $title, ?string $details = null, ?string $date = null, ?string $linkEntity = null, ?int $linkId = null): void
{
    try {
        db_query(
            'INSERT INTO ' . t('worker_status_history') . ' (worker_id, event_type, event_date, title, details, link_entity, link_id, user_id)
             VALUES (?,?,?,?,?,?,?,?)',
            [$workerId, mb_substr($type, 0, 50), $date ?: date('Y-m-d'), mb_substr($title, 0, 255), $details, $linkEntity, $linkId, $_SESSION['user_id'] ?? null]
        );
    } catch (Throwable $ex) {
        error_log('[CRM EVENTS] ' . $ex->getMessage());
    }
}

/** Валидация и сохранение загруженного файла (фото/документ). Возвращает [path|null, error|null]. */
function store_upload(array $file, string $subdir, array $allowedMime = null): array
{
    $allowedMime ??= [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf',
    ];
    $maxSize = 10 * 1024 * 1024; // 10 МБ

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [null, 'Файл не был загружен полностью. Проверьте лимиты upload_max_filesize/post_max_size.'];
    }
    if ($file['size'] > $maxSize) {
        return [null, 'Размер файла превышает 10 МБ.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return [null, 'Некорректная загрузка файла.'];
    }

    // MIME по содержимому (finfo), а не по имени
    $mime = null;
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($fi, $file['tmp_name']);
        finfo_close($fi);
    } else {
        $mime = mime_content_type($file['tmp_name']) ?: null;
    }
    if (!$mime || !isset($allowedMime[$mime])) {
        return [null, 'Допустимые форматы: JPG, PNG, WEBP, PDF. Получен: ' . e((string)$mime)];
    }
    // Двойная проверка расширения
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
        return [null, 'Недопустимое расширение файла.'];
    }

    $dir = rtrim(UPLOAD_DIR, '/') . '/' . trim($subdir, '/');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return [null, 'Хранилище файлов недоступно для записи. Обратитесь к администратору сервера.'];
    }

    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $allowedMime[$mime];
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return [null, 'Не удалось сохранить файл.'];
    }
    @chmod($dest, 0640);
    return [ltrim(trim($subdir, '/') . '/' . $name, '/'), null];
}

/** Удалить файл из хранилища (только внутри UPLOAD_DIR). */
function delete_upload(?string $relPath): void
{
    if (!$relPath) return;
    $base = realpath(rtrim(UPLOAD_DIR, '/'));
    if ($base === false) return;
    $full = realpath($base . '/' . $relPath);
    if ($full !== false && str_starts_with($full, $base) && is_file($full)) {
        @unlink($full);
    }
}

/** Защита относительного пути файла от directory traversal. */
function safe_relative_path(string $p): ?string
{
    $p = str_replace('\\', '/', $p);
    if (preg_match('#(^/|\.\.)#', $p)) {
        return null;
    }
    return $p;
}

/** Текущая страница пагинации. */
function current_page(): int
{
    return max(1, (int)($_GET['page'] ?? 1));
}

/** Рендер пагинации. $total — всего записей, $perPage — на страницу. */
function render_pager(int $total, int $perPage, string $baseUrl): string
{
    $pages = (int)ceil($total / max(1, $perPage));
    if ($pages <= 1) return '';
    $cur = current_page();
    $html = '<nav><ul class="pagination pagination-sm mb-0">';
    $mk = static function (int $p, string $label, bool $active = false, bool $disabled = false) use ($baseUrl, $cur): string {
        $sep = strpos($baseUrl, '?') === false ? '?' : '&';
        if ($disabled) return '<li class="page-item disabled"><span class="page-link">' . e($label) . '</span></li>';
        if ($active) return '<li class="page-item active"><span class="page-link">' . e($label) . '</span></li>';
        return '<li class="page-item"><a class="page-link" href="' . e($baseUrl . $sep . 'page=' . $p) . '">' . e($label) . '</a></li>';
    };
    $html .= $mk(max(1, $cur - 1), '‹', false, $cur <= 1);
    $from = max(1, $cur - 2); $to = min($pages, $cur + 2);
    if ($from > 1) $html .= $mk(1, '1') . ($from > 2 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '');
    for ($i = $from; $i <= $to; $i++) $html .= $mk($i, (string)$i, $i === $cur);
    if ($to < $pages) $html .= ($to < $pages - 1 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '') . $mk($pages, (string)$pages);
    $html .= $mk(min($pages, $cur + 1), '›', false, $cur >= $pages);
    $html .= '</ul></nav>';
    return $html;
}
