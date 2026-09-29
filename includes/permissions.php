<?php
/**
 * Права доступа: серверная проверка на каждой странице.
 */
declare(strict_types=1);
defined('CRM_APP') or die('Direct access denied');

/** Все права текущей роли (кэш на запрос). */
function user_permissions(): array
{
    static $perms = null;
    if ($perms !== null) {
        return $perms;
    }
    $u = current_user();
    if (!$u) {
        return $perms = [];
    }
    try {
        $st = db_query(
            'SELECT p.slug FROM ' . t('permissions') . ' p
             JOIN ' . t('role_permissions') . ' rp ON rp.permission_id = p.id
             WHERE rp.role_id = ?',
            [(int)$u['role_id']]
        );
        $perms = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        error_log('[CRM PERMS] ' . $e->getMessage());
        $perms = [];
    }
    return $perms;
}

/** Есть ли у текущего пользователя право. */
function can(string $permission): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    // Администратор — все права автоматически (защита от потери доступа при правках справочника)
    if ($u['role_slug'] === 'admin') {
        return true;
    }
    return in_array($permission, user_permissions(), true);
}

/** Требовать право; при отсутствии — 403. */
function require_permission(string $permission): void
{
    require_login();
    if (!can($permission)) {
        http_response_code(403);
        $title = 'Доступ запрещен';
        include CRM_ROOT . '/includes/header.php';
        echo '<div class="alert alert-danger"><h5>⛔ Доступ запрещен</h5>
              У вашей роли нет права <code>' . e($permission) . '</code>. Обратитесь к администратору.</div>';
        include CRM_ROOT . '/includes/footer.php';
        exit;
    }
}
