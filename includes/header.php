<?php
/**
 * Шапка интерфейса CRM (боковое меню + верхняя панель).
 * Ожидает переменную $pageTitle до подключения.
 */
defined('CRM_APP') or die('Direct access denied');

$pageTitle = $pageTitle ?? SITE_NAME;
$user = function_exists('current_user') ? current_user() : null;
$self = $_SERVER['SCRIPT_NAME'] ?? '';

/** Активный пункт меню. */
function nav_active(string $prefix): string
{
    global $self;
    return str_contains($self, '/' . $prefix) ? ' active' : '';
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($pageTitle) ?> — <?= e(SITE_NAME) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/css/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="crm-body">

<!-- Боковое меню -->
<nav class="crm-sidebar" id="crmSidebar">
  <div class="sidebar-brand">
    <span class="brand-icon">👷</span>
    <span class="brand-text"><?= e(SITE_NAME) ?></span>
  </div>
  <ul class="sidebar-menu list-unstyled mb-0">
    <li><a class="nav-link<?= nav_active('dashboard') ?>" href="<?= e(url('dashboard.php')) ?>"><span>📊</span> Dashboard</a></li>
    <?php if (can('workers.view')): ?><li><a class="nav-link<?= nav_active('workers') ?>" href="<?= e(url('workers/index.php')) ?>"><span>👥</span> Рабочие</a></li><?php endif; ?>
    <?php if (can('arrivals.view')): ?><li><a class="nav-link<?= nav_active('arrivals') ?>" href="<?= e(url('arrivals/index.php')) ?>"><span>🛬</span> Прибытия</a></li><?php endif; ?>
    <?php if (can('accommodation.view')): ?>
      <li class="nav-group">Проживание</li>
      <li><a class="nav-link<?= nav_active('accommodation') ?>" href="<?= e(url('accommodation/index.php')) ?>"><span>🏠</span> Заселения</a></li>
      <li><a class="nav-link<?= nav_active('objects/accommodation') ?>" href="<?= e(url('objects/accommodation.php')) ?>"><span>🏢</span> Объекты проживания</a></li>
    <?php endif; ?>
    <?php if (can('objects.view')): ?><li><a class="nav-link<?= nav_active('objects/jobs') ?>" href="<?= e(url('objects/jobs.php')) ?>"><span>🏗️</span> Рабочие объекты</a></li><?php endif; ?>
    <?php if (can('jobs.view')): ?><li><a class="nav-link<?= nav_active('jobs') ?>" href="<?= e(url('jobs/index.php')) ?>"><span>🧰</span> Назначения</a></li><?php endif; ?>
    <?php if (can('attendance.view')): ?><li><a class="nav-link<?= nav_active('attendance') ?>" href="<?= e(url('attendance/index.php')) ?>"><span>🗓️</span> Табель</a></li><?php endif; ?>
    <?php if (can('accruals.view')): ?><li><a class="nav-link<?= nav_active('accruals') ?>" href="<?= e(url('accruals/index.php')) ?>"><span>💰</span> Начисления</a></li><?php endif; ?>
    <?php if (can('payments.view')): ?><li><a class="nav-link<?= nav_active('payments') ?>" href="<?= e(url('payments/index.php')) ?>"><span>💸</span> Выплаты</a></li><?php endif; ?>
    <?php if (can('documents.view')): ?><li><a class="nav-link<?= nav_active('documents') ?>" href="<?= e(url('documents/index.php')) ?>"><span>📄</span> Документы</a></li><?php endif; ?>
    <?php if (can('reports.view')): ?><li><a class="nav-link<?= nav_active('reports') ?>" href="<?= e(url('reports/index.php')) ?>"><span>📈</span> Отчеты</a></li><?php endif; ?>
    <?php if (can('notifications.view')): ?><li><a class="nav-link<?= nav_active('notifications') ?>" href="<?= e(url('notifications/index.php')) ?>"><span>🔔</span> Уведомления</a></li><?php endif; ?>
    <?php if (can('users.manage')): ?><li class="nav-group">Администрирование</li>
      <li><a class="nav-link<?= nav_active('users') ?>" href="<?= e(url('users/index.php')) ?>"><span>🧑‍💼</span> Пользователи</a></li>
    <?php endif; ?>
    <?php if (can('settings.manage')): ?><li><a class="nav-link<?= nav_active('settings') ?>" href="<?= e(url('settings/index.php')) ?>"><span>⚙️</span> Настройки</a></li><?php endif; ?>
    <?php if (can('audit.view')): ?><li><a class="nav-link<?= nav_active('settings/audit') ?>" href="<?= e(url('settings/audit.php')) ?>"><span>🧾</span> Журнал действий</a></li><?php endif; ?>
  </ul>
</nav>

<!-- Основная область -->
<div class="crm-main">
  <header class="crm-topbar d-flex align-items-center gap-3 px-3">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="btnSidebarToggle">☰</button>
    <form class="top-search position-relative flex-grow-1" style="max-width:420px" autocomplete="off" onsubmit="return crmGlobalSearch(event)">
      <input type="search" id="globalSearch" class="form-control form-control-sm" placeholder="Поиск: ФИО, телефон, паспорт, СНИЛС, ИНН, ID…">
      <div class="search-results shadow" id="searchResults"></div>
    </form>
    <div class="ms-auto d-flex align-items-center gap-3">
      <a href="<?= e(url('notifications/index.php')) ?>" class="topbar-bell" title="Уведомления">🔔<?php
        try {
            $nc = (int)db_query('SELECT COUNT(*) c FROM ' . t('notifications') . ' WHERE is_read=0 AND (user_id IS NULL OR user_id=?)', [(int)($user['id'] ?? 0)])->fetch()['c'] ?? 0;
            if ($nc > 0) echo '<span class="badge bg-danger rounded-pill">' . $nc . '</span>';
        } catch (Throwable) {}
      ?></a>
      <div class="dropdown">
        <a class="dropdown-toggle text-white text-decoration-none small" data-bs-toggle="dropdown" href="#">
          👤 <?= e($user['full_name'] ?? '') ?> <span class="badge bg-light text-dark"><?= e($user['role_name'] ?? '') ?></span>
        </a>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="<?= e(url('settings/profile.php')) ?>">Мой профиль</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="<?= e(url('logout.php')) ?>">Выйти</a></li>
        </ul>
      </div>
    </div>
  </header>

  <main class="crm-content p-3 p-lg-4">
    <?php include CRM_ROOT . '/includes/alerts.php'; ?>
