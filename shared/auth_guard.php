<?php
// shared/auth_guard.php
// Include AFTER $mysqli exists. Logs out any user whose account was blocked.

if (session_status() === PHP_SESSION_NONE) session_start();

// Login / register / recovery pages are never guarded (prevents redirect loops)
$script = basename($_SERVER['SCRIPT_NAME']);
$isPublic = (bool)preg_match('/_(login|register|forgot_password|reset_password)\.php$/', $script);

if (!$isPublic && !empty($_SESSION['user_id']) && isset($mysqli)) {

    $loginPages = [          // role => login page (relative to that role's folder)
        'dean'       => 'dean_login.php',
        'principal'  => 'principal_login.php',
        'faculty'    => 'faculty_login.php',
        'staff'      => 'staff_login.php',
        'student'    => 'student_login.php',
        'superadmin' => 'admin_login.php',
    ];

    $g = $mysqli->prepare("SELECT is_active, account_status FROM users WHERE id = ? LIMIT 1");
    if ($g) {
        $g->bind_param('i', $_SESSION['user_id']);
        $g->execute();
        $row = $g->get_result()->fetch_assoc();
        $g->close();

        $blocked = !$row
            || $row['account_status'] === 'blocked'
            || ((int)$row['is_active'] === 0 && $row['account_status'] === 'approved');

        if ($blocked) {
            $role = $_SESSION['role'] ?? '';
            $_SESSION = [];
            session_destroy();
            session_start();
            $_SESSION['flash_error'] = 'This account has been blocked by the Executive Assistant. Please contact the administrator.';

            $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
                   || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
            if ($isAjax) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'blocked']);
            } else {
                header('Location: ' . ($loginPages[$role] ?? 'index.php'));
            }
            exit;
        }
    }
}