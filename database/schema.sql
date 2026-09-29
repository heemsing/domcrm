-- ============================================================
-- CRM «Учет рабочих» — схема базы данных
-- Все имена таблиц содержат плейсхолдер {{PREFIX}}, который
-- установщик заменяет на выбранный префикс (по умолчанию crm_).
-- Инженевный ключ {{ENGINE}} заменяется на InnoDB.
-- Кодировка: utf8mb4, сортировка utf8mb4_unicode_ci
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------- Пользователи и роли ----------

CREATE TABLE IF NOT EXISTS {{PREFIX}}roles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(50) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_slug (slug)
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{PREFIX}}permissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(100) NOT NULL,
    section VARCHAR(50) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_perm_slug (slug),
    KEY idx_perm_section (section)
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{PREFIX}}role_permissions (
    role_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES {{PREFIX}}roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES {{PREFIX}}permissions (id) ON DELETE CASCADE
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{PREFIX}}users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id INT UNSIGNED NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME DEFAULT NULL,
    failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role_id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES {{PREFIX}}roles (id) ON DELETE RESTRICT
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Рабочие ----------

CREATE TABLE IF NOT EXISTS {{PREFIX}}workers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    last_name VARCHAR(100) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) DEFAULT NULL,
    gender ENUM('male','female') NOT NULL DEFAULT 'male',
    birth_date DATE DEFAULT NULL,
    citizenship VARCHAR(100) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    phone_extra VARCHAR(30) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    photo_path VARCHAR(255) DEFAULT NULL,
    comment TEXT DEFAULT NULL,
    status ENUM('new','arrived','working','check_out','fired','archived') NOT NULL DEFAULT 'new',
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    -- денормализованные поля для быстрого поиска по документам
    passport_number VARCHAR(30) DEFAULT NULL,
    snils VARCHAR(20) DEFAULT NULL,
    inn VARCHAR(20) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_workers_fio (last_name, first_name, middle_name),
    KEY idx_workers_phone (phone),
    KEY idx_workers_status (status),
    KEY idx_workers_deleted (deleted_at),
    KEY idx_workers_passport (passport_number),
    KEY idx_workers_snils (snils),
    KEY idx_workers_inn (inn),
    KEY idx_workers_created (created_at),
    CONSTRAINT fk_workers_creator FOREIGN KEY (created_by) REFERENCES {{PREFIX}}users (id) ON DELETE SET NULL
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{PREFIX}}worker_status_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    event_type VARCHAR(50) NOT NULL COMMENT 'arrival, checkin, assignment, attendance, payment, transfer, checkout...',
    event_date DATE NOT NULL,
    title VARCHAR(255) NOT NULL,
    details TEXT DEFAULT NULL,
    link_entity VARCHAR(50) DEFAULT NULL,
    link_id BIGINT UNSIGNED DEFAULT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_wsh_worker (worker_id, event_date),
    CONSTRAINT fk_wsh_worker FOREIGN KEY (worker_id) REFERENCES {{PREFIX}}workers (id) ON DELETE CASCADE,
    CONSTRAINT fk_wsh_user FOREIGN KEY (user_id) REFERENCES {{PREFIX}}users (id) ON DELETE SET NULL
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Прибытия ----------

CREATE TABLE IF NOT EXISTS {{PREFIX}}arrivals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    arrival_date DATE NOT NULL,
    arrival_time TIME DEFAULT NULL,
    source VARCHAR(150) DEFAULT NULL COMMENT 'Откуда прибыл / источник',
    registered_by INT UNSIGNED DEFAULT NULL,
    comment TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_arrivals_worker (worker_id, arrival_date),
    KEY idx_arrivals_date (arrival_date),
    CONSTRAINT fk_arr_worker FOREIGN KEY (worker_id) REFERENCES {{PREFIX}}workers (id) ON DELETE CASCADE,
    CONSTRAINT fk_arr_user FOREIGN KEY (registered_by) REFERENCES {{PREFIX}}users (id) ON DELETE SET NULL
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Документы ----------

CREATE TABLE IF NOT EXISTS {{PREFIX}}document_types (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    requires_expiry TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 100,
    PRIMARY KEY (id),
    UNIQUE KEY uq_doctypes_slug (slug)
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{PREFIX}}documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    type_id INT UNSIGNED NOT NULL,
    series VARCHAR(10) DEFAULT NULL,
    number VARCHAR(60) DEFAULT NULL,
    issue_date DATE DEFAULT NULL,
    expiry_date DATE DEFAULT NULL,
    issued_by VARCHAR(255) DEFAULT NULL,
    dept_code VARCHAR(30) DEFAULT NULL,
    birth_place VARCHAR(255) DEFAULT NULL,
    registration_address VARCHAR(255) DEFAULT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    file_name VARCHAR(255) DEFAULT NULL,
    file_mime VARCHAR(100) DEFAULT NULL,
    file_size INT UNSIGNED DEFAULT NULL,
    comment TEXT DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_docs_worker (worker_id),
    KEY idx_docs_type (type_id),
    KEY idx_docs_expiry (expiry_date),
    KEY idx_docs_number (number),
    CONSTRAINT fk_docs_worker FOREIGN KEY (worker_id) REFERENCES {{PREFIX}}workers (id) ON DELETE CASCADE,
    CONSTRAINT fk_docs_type FOREIGN KEY (type_id) REFERENCES {{PREFIX}}document_types (id) ON DELETE RESTRICT,
    CONSTRAINT fk_docs_user FOREIGN KEY (created_by) REFERENCES {{PREFIX}}users (id) ON DELETE SET NULL
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Проживание ----------

CREATE TABLE IF NOT EXISTS {{PREFIX}}accommodation_objects (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    address VARCHAR(255) NOT NULL,
    city VARCHAR(100) DEFAULT NULL,
    contact_person VARCHAR(150) DEFAULT NULL,
    contact_phone VARCHAR(30) DEFAULT NULL,
    capacity INT UNSIGNED NOT NULL DEFAULT 0,
    comment TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_accobj_active (is_active)
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{PREFIX}}rooms (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    object_id INT UNSIGNED NOT NULL,
    room_number VARCHAR(20) NOT NULL,
    capacity INT UNSIGNED NOT NULL DEFAULT 1,
    comment VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_room (object_id, room_number),
    CONSTRAINT fk_rooms_obj FOREIGN KEY (object_id) REFERENCES {{PREFIX}}accommodation_objects (id) ON DELETE CASCADE
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{PREFIX}}accommodations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    object_id INT UNSIGNED NOT NULL,
    room_id INT UNSIGNED DEFAULT NULL,
    bed_number INT UNSIGNED NOT NULL DEFAULT 1,
    checkin_date DATE NOT NULL,
    checkout_date DATE DEFAULT NULL,
    comment TEXT DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_accom_worker (worker_id, checkin_date),
    KEY idx_accom_object (object_id, checkout_date),
    KEY idx_accom_active (checkout_date),
    CONSTRAINT fk_accom_worker FOREIGN KEY (worker_id) REFERENCES {{PREFIX}}workers (id) ON DELETE CASCADE,
    CONSTRAINT fk_accom_obj FOREIGN KEY (object_id) REFERENCES {{PREFIX}}accommodation_objects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_accom_room FOREIGN KEY (room_id) REFERENCES {{PREFIX}}rooms (id) ON DELETE SET NULL,
    CONSTRAINT fk_accom_user FOREIGN KEY (created_by) REFERENCES {{PREFIX}}users (id) ON DELETE SET NULL
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Рабочие объекты, должности, назначения ----------

CREATE TABLE IF NOT EXISTS {{PREFIX}}job_objects (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    client VARCHAR(150) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    contact_person VARCHAR(150) DEFAULT NULL,
    contact_phone VARCHAR(30) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_jobobj_status (status)
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{PREFIX}}positions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    PRIMARY KEY (id),
    UNIQUE KEY uq_positions_name (name)
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{PREFIX}}worker_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    object_id INT UNSIGNED NOT NULL,
    position_id INT UNSIGNED NOT NULL,
    rate DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Ставка за час или день в зависимости от pay_type',
    pay_type ENUM('hourly','daily','monthly','piece') NOT NULL DEFAULT 'daily',
    start_date DATE NOT NULL,
    end_date DATE DEFAULT NULL,
    status ENUM('active','completed','terminated') NOT NULL DEFAULT 'active',
    comment TEXT DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_assign_worker (worker_id, start_date),
    KEY idx_assign_object (object_id, status),
    KEY idx_assign_active (status, end_date),
    CONSTRAINT fk_assign_worker FOREIGN KEY (worker_id) REFERENCES {{PREFIX}}workers (id) ON DELETE CASCADE,
    CONSTRAINT fk_assign_obj FOREIGN KEY (object_id) REFERENCES {{PREFIX}}job_objects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_assign_pos FOREIGN KEY (position_id) REFERENCES {{PREFIX}}positions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_assign_user FOREIGN KEY (created_by) REFERENCES {{PREFIX}}users (id) ON DELETE SET NULL
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Табель ----------

CREATE TABLE IF NOT EXISTS {{PREFIX}}attendance (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    object_id INT UNSIGNED DEFAULT NULL,
    position_id INT UNSIGNED DEFAULT NULL,
    hours DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    shifts DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    rate DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    accrued DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('worked','day_off','absent','truancy','sick','vacation') NOT NULL DEFAULT 'worked',
    comment VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance (worker_id, work_date),
    KEY idx_att_date (work_date),
    KEY idx_att_object (object_id, work_date),
    CONSTRAINT fk_att_worker FOREIGN KEY (worker_id) REFERENCES {{PREFIX}}workers (id) ON DELETE CASCADE,
    CONSTRAINT fk_att_obj FOREIGN KEY (object_id) REFERENCES {{PREFIX}}job_objects (id) ON DELETE SET NULL,
    CONSTRAINT fk_att_pos FOREIGN KEY (position_id) REFERENCES {{PREFIX}}positions (id) ON DELETE SET NULL,
    CONSTRAINT fk_att_user FOREIGN KEY (created_by) REFERENCES {{PREFIX}}users (id) ON DELETE SET NULL
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Финансы ----------

CREATE TABLE IF NOT EXISTS {{PREFIX}}accruals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    accrual_date DATE NOT NULL,
    period_start DATE DEFAULT NULL,
    period_end DATE DEFAULT NULL,
    amount DECIMAL(12,2) NOT NULL,
    type ENUM('salary','bonus','overtime','compensation','fine','deduction','other') NOT NULL DEFAULT 'salary',
    basis VARCHAR(255) DEFAULT NULL COMMENT 'Основание',
    comment TEXT DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_acc_worker (worker_id, accrual_date),
    KEY idx_acc_date (accrual_date),
    KEY idx_acc_type (type),
    CONSTRAINT fk_accr_worker FOREIGN KEY (worker_id) REFERENCES {{PREFIX}}workers (id) ON DELETE CASCADE,
    CONSTRAINT fk_accr_user FOREIGN KEY (created_by) REFERENCES {{PREFIX}}users (id) ON DELETE SET NULL
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{PREFIX}}payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    method ENUM('cash','card','transfer','advance','other') NOT NULL DEFAULT 'cash',
    purpose VARCHAR(255) DEFAULT NULL,
    comment TEXT DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pay_worker (worker_id, payment_date),
    KEY idx_pay_date (payment_date),
    KEY idx_pay_method (method),
    CONSTRAINT fk_pay_worker FOREIGN KEY (worker_id) REFERENCES {{PREFIX}}workers (id) ON DELETE CASCADE,
    CONSTRAINT fk_pay_user FOREIGN KEY (created_by) REFERENCES {{PREFIX}}users (id) ON DELETE SET NULL
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {{PREFIX}}financial_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    txn_date DATE NOT NULL,
    direction ENUM('in','out') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    entity_type VARCHAR(30) NOT NULL COMMENT 'accrual|payment',
    entity_id BIGINT UNSIGNED NOT NULL,
    balance_after DECIMAL(14,2) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ft_worker (worker_id, txn_date),
    KEY idx_ft_entity (entity_type, entity_id),
    CONSTRAINT fk_ft_worker FOREIGN KEY (worker_id) REFERENCES {{PREFIX}}workers (id) ON DELETE CASCADE
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Уведомления ----------

CREATE TABLE IF NOT EXISTS {{PREFIX}}notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED DEFAULT NULL COMMENT 'NULL — системное уведомление для всех',
    severity ENUM('info','warning','danger') NOT NULL DEFAULT 'info',
    category VARCHAR(50) NOT NULL COMMENT 'doc_expiry, no_assignment, absent, no_beds, debt...',
    title VARCHAR(255) NOT NULL,
    body TEXT DEFAULT NULL,
    link VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notif_user (user_id, is_read),
    KEY idx_notif_cat (category),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES {{PREFIX}}users (id) ON DELETE CASCADE
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Журнал действий ----------

CREATE TABLE IF NOT EXISTS {{PREFIX}}audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED DEFAULT NULL,
    action VARCHAR(50) NOT NULL COMMENT 'create, update, delete, login, logout, download...',
    entity VARCHAR(50) NOT NULL,
    entity_id BIGINT UNSIGNED DEFAULT NULL,
    old_values JSON DEFAULT NULL,
    new_values JSON DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_user (user_id, created_at),
    KEY idx_audit_entity (entity, entity_id),
    KEY idx_audit_date (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES {{PREFIX}}users (id) ON DELETE SET NULL
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Настройки ----------

CREATE TABLE IF NOT EXISTS {{PREFIX}}settings (
    `key` VARCHAR(100) NOT NULL,
    `value` TEXT DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE={{ENGINE}} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
