<?php
/* =========================================================================
   principal_notifications.php — JSON feed for the dashboard bell
   -------------------------------------------------------------------------
   GET only. Builds the list from EXACTLY the same inputs the dashboard uses
   (get_school_head_settings('principal') + the structure gate), so the bell
   can never show something different from the page it sits on.

   Response:
     {"ok":true,"items":[{"id","text","level"}],"notifications":[...same...],
      "count":N,"ts":1699999999,"generated_at":"ISO-8601"}
   ("notifications"/"generated_at" are kept so principal_notifications_api.php
    callers keep working.)
   ========================================================================= */
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'domain' => '',
    'secure' => false, 'httponly' => true, 'samesite' => 'Lax',
]);
session_start();

// Any stray PHP notice/warning printed before the JSON would make the bell's
// response.json() throw and show "Offline" forever. Buffer and discard it.
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function principal_notif_respond(int $status, array $payload): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    principal_notif_respond(401, ['ok' => false, 'error' => 'unauthenticated']);
}
// The session lock is not needed for a read-only poll; release it so a slow
// feed never blocks the page the user is navigating to.
$uid = (int)$_SESSION['user_id'];
session_write_close();

require_once 'db.php';
require_once dirname(__DIR__) . '/shared/system_settings_service.php';
require_once 'principal_notifications_feed.php';
require_once 'school_head_structure_gate.php';

try {
    $settings = get_school_head_settings($mysqli, 'principal');
    $settings = sh_gate_apply($settings, 'principal');
    $items    = principal_build_notifications($mysqli, $uid, $settings);
    $mysqli->close();
    principal_notif_respond(200, [
        'ok'            => true,
        'items'         => $items,
        'notifications' => $items,
        'count'         => count($items),
        'ts'            => time(),
        'generated_at'  => date('c'),
    ]);
} catch (Throwable $e) {
    error_log('principal_notifications: ' . $e->getMessage());
    principal_notif_respond(500, ['ok' => false, 'error' => 'feed_failed']);
}
