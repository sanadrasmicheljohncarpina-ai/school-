<?php
/** Enforce system-wide maintenance for authenticated non-admin users. */
function enforce_maintenance_gate(mysqli $mysqli): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $role = strtolower((string)($_SESSION['role'] ?? ''));
    if (empty($_SESSION['user_id']) || in_array($role, ['admin', 'superadmin', 'registrar'], true)) {
        return;
    }

    // Keep account exit, login, and the status poll reachable. The status poll
    // lets the maintenance screen detect when an administrator turns the
    // system back on.
    $script = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    if (in_array($script, ['maintenance.php', 'eval_status.php', 'logout.php', 'staff_logout.php'], true)
        || str_ends_with($script, '_login.php')) {
        return;
    }

    try {
        $result = @$mysqli->query("SELECT setting_value FROM system_settings WHERE setting_key='maintenance' LIMIT 1");
        $maintenance = $result && ($row = $result->fetch_assoc()) && !empty($row['setting_value']);
    } catch (Throwable $e) {
        $maintenance = false;
    }
    if (!$maintenance) return;

    http_response_code(503);
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    $isFetch = in_array(strtolower((string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '')), ['cors', 'same-origin'], true)
        && empty($_SERVER['HTTP_SEC_FETCH_DEST']);
    if ($isAjax || $isFetch || str_contains($accept, 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'maintenance', 'message' => 'The system is temporarily unavailable for maintenance.']);
        exit;
    }

    $appRoot = dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index/')));
    header('Location: ' . rtrim($appRoot, '/') . '/maintenance.php', true, 302);
    exit;
}
