<?php
/** Выход из системы. */
require_once __DIR__ . '/includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}
logout_user();
redirect(url('login.php'));
