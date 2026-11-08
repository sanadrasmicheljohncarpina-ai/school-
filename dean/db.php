<?php
// dean/db.php
// Place this file inside: htdocs/index/dean/
// Include in every dean PHP file with: require_once 'db.php';

// Production values are loaded from hosting environment variables or root .env.php.
// XAMPP defaults remain available automatically for local development.
require_once dirname(__DIR__) . '/shared/app_config.php';
require_once __DIR__ . '/../shared/auth_guard.php';
$cfg = app_config();

$mysqli = new mysqli(
    $cfg['DB_HOST'],
    $cfg['DB_USER'],
    $cfg['DB_PASS'],
    $cfg['DB_NAME'],
    $cfg['DB_PORT']
);
if ($mysqli->connect_errno) {
    if (app_is_production()) {
        http_response_code(503);
        exit('Database service is temporarily unavailable.');
    }
    exit('Database connection failed: ' . $mysqli->connect_error);
}
$mysqli->set_charset('utf8mb4');

// Absolute path to the shared image folder
// dean/ is one level inside index/, so go up one level
// dirname(__DIR__) from dean/ = htdocs/index/
define('UPLOAD_DIR', dirname(__DIR__) . '/image/');

// Relative URL used in HTML <img src=""> tags inside dean pages
define('UPLOAD_URL', '../image/');

// ---- Self-healing schema check ----
// Runs once per login session instead of on every request / live-poll.
$__schemaKey  = 'dean_db_schema_v1';
$__hasSession = session_status() === PHP_SESSION_ACTIVE;
if (!$__hasSession || empty($_SESSION[$__schemaKey])) {
$col = $mysqli->query("SHOW COLUMNS FROM users LIKE 'education_level'")->fetch_assoc();
if ($col && stripos($col['Type'], 'enum') !== false) {
    $mysqli->query("ALTER TABLE users MODIFY COLUMN education_level VARCHAR(20) NULL");
}
// (portable: "ADD COLUMN IF NOT EXISTS" is MariaDB-only syntax)
$__eid = $mysqli->query("SHOW COLUMNS FROM users LIKE 'employee_id'");
if ($__eid && $__eid->num_rows === 0) {
    $mysqli->query("ALTER TABLE users ADD COLUMN employee_id VARCHAR(50) NULL");
}

// ---- Evaluation reminders (Evaluation Tracker "Send Reminder" / "Send
// Bulk Reminder"). Self-healing like the columns above: created on first
// use so no manual migration step is needed.
$mysqli->query("
    CREATE TABLE IF NOT EXISTS evaluation_reminders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        period_id INT NOT NULL,
        sender_id INT NOT NULL,
        recipient_id INT NOT NULL,
        eval_type VARCHAR(20) NOT NULL DEFAULT 'student',
        level VARCHAR(20) NOT NULL DEFAULT 'college',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_recipient_period (recipient_id, period_id),
        INDEX idx_period (period_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ---- Evaluation answers (per-question breakdown behind dean_evaluate.php's
// submit and dean_results.php's category-breakdown read). This table did
// not exist in the DB at all — confirmed via SHOW TABLES — so both those
// pages' evaluation_answers queries were failing/returning empty. Same
// self-healing pattern as evaluation_reminders above.
$mysqli->query("
    CREATE TABLE IF NOT EXISTS evaluation_answers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tracker_id INT UNSIGNED NOT NULL,
        category VARCHAR(100) NOT NULL,
        question VARCHAR(255) NOT NULL,
        score TINYINT UNSIGNED NOT NULL,
        INDEX idx_tracker (tracker_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if ($__hasSession && $mysqli->errno === 0) {
    $_SESSION[$__schemaKey] = 1;
}
}

// ---- Shared service layer ----
require_once dirname(__DIR__) . '/shared/AuthenticationService.php';

require_once dirname(__DIR__) . '/shared/maintenance_gate.php';
enforce_maintenance_gate($mysqli);
