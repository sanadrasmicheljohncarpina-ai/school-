<?php
// faculty/staff_logout.php
// Dedicated Staff logout endpoint. Always returns to Staff Login.

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once 'db.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId > 0) {
    $stmt = $mysqli->prepare("UPDATE users SET is_logged_in = 0 WHERE id = ? AND role = 'staff' LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
}
session_destroy();

header('Location: staff_login.php');
exit;
