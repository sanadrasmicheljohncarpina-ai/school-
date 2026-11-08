<?php
// principal/db.php
// Place this file inside: htdocs/index/principal/
// Include in every principal PHP file with: require_once 'db.php';

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
// principal/ is one level inside index/, so go up one level
// dirname(__DIR__) from principal/ = htdocs/index/
define('UPLOAD_DIR', dirname(__DIR__) . '/image/');

// Relative URL used in HTML <img src=""> tags inside principal pages
define('UPLOAD_URL', '../image/');

require_once dirname(__DIR__) . '/shared/maintenance_gate.php';
enforce_maintenance_gate($mysqli);

// ---- Self-healing schema check (matches your ALTER TABLE-on-load pattern) ----
// Confirms education_level is VARCHAR (not the old 3-value ENUM) and that
// employee_id exists, in case this environment hasn't been migrated yet.
// Runs once per login session instead of on every request / live-poll.
$__schemaKey  = 'principal_db_schema_v1';
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
if ($__hasSession && $mysqli->errno === 0) {
    $_SESSION[$__schemaKey] = 1;
}
}
