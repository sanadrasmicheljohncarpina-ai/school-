<?php
// student/db.php
// Place this file inside: htdocs/index/student/
// Include in every student PHP file with: require_once 'db.php';

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
// student/ is one level inside index/, so go up one level
// dirname(__DIR__) from student/ = htdocs/index/
define('UPLOAD_DIR', dirname(__DIR__) . '/image/');

// Relative URL used in HTML <img src=""> tags inside student pages
define('UPLOAD_URL', '../image/');

require_once dirname(__DIR__) . '/shared/maintenance_gate.php';
enforce_maintenance_gate($mysqli);
