<?php
// Real-time notification endpoint for Faculty and Staff dashboards.
// Supports lightweight polling (GET) and AJAX "mark all read" (POST).
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
require_once 'db.php';
require_once dirname(__DIR__) . '/shared/notification_message.php';
require_once __DIR__ . '/notification_links.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['teacher', 'staff'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

function notif_muted_types_sql(array $prefs): string {
    $map = ['notify_evaluation_schedule'=>'evaluation_schedule','notify_teaching_assignment'=>'teaching_assignment','notify_academic_period'=>'academic_period'];
    $m = [];
    foreach ($map as $key => $type) { if (isset($prefs[$key]) && (int)$prefs[$key] === 0) $m[] = "'" . $type . "'"; }
    return $m ? ' AND type NOT IN (' . implode(',', $m) . ')' : '';
}

// ── Auto-notify when someone evaluates this user ─────────────────────────
// Evaluations can be submitted from other portals that never write a notification row,
// so every poll looks at evaluation_tracker itself and creates any missing
// "evaluation_received" notification (one per evaluation, evaluator never named).
function notif_sync_received_evaluations(mysqli $db, int $userId): void {
    try {
        if (empty($_SESSION['notif_sync_schema'])) {
            $c = $db->query("SHOW COLUMNS FROM notifications LIKE 'tracker_id'");
            if ($c && $c->num_rows === 0) {
                $db->query("ALTER TABLE notifications ADD COLUMN tracker_id INT UNSIGNED NULL");
            }
            try {
                $i = $db->query("SHOW INDEX FROM notifications WHERE Key_name = 'uniq_notif_user_tracker'");
                if ($i && $i->num_rows === 0) {
                    $db->query("ALTER TABLE notifications ADD UNIQUE INDEX uniq_notif_user_tracker (user_id, tracker_id)");
                }
            } catch (Throwable $e) { /* index is only a race guard */ }
            $_SESSION['notif_sync_schema'] = 1;
        }

        $msg = NOTIF_EVAL_RECEIVED_MSG;
        $sql = "INSERT IGNORE INTO notifications (type, user_id, message, tracker_id, created_at)
                SELECT 'evaluation_received', et.target_user_id, ?, et.id, et.submitted_at
                FROM evaluation_tracker et
                WHERE et.target_user_id = ?
                  AND et.status NOT IN ('draft','in_progress')
                  AND et.submitted_at IS NOT NULL
                  AND et.submitted_at >= (NOW() - INTERVAL 7 DAY)
                  AND et.submitted_at <= (NOW() - INTERVAL 2 SECOND)
                  AND NOT EXISTS (
                        SELECT 1 FROM notifications n
                        WHERE n.user_id = et.target_user_id
                          AND n.type = 'evaluation_received'
                          AND (n.tracker_id = et.id
                               OR (n.tracker_id IS NULL
                                   AND ABS(TIMESTAMPDIFF(SECOND, n.created_at, et.submitted_at)) <= 10))
                  )";
        $st = $db->prepare($sql);
        if ($st) {
            $st->bind_param('si', $msg, $userId);
            $st->execute();
            $st->close();
        }
    } catch (Throwable $e) {
        error_log('[notifications_api] evaluation sync failed for user=' . $userId . ': ' . $e->getMessage());
    }
}

$prefs = [];
try {
    $pst = $mysqli->prepare('SELECT * FROM user_preferences WHERE user_id=? LIMIT 1');
    if ($pst) { $pst->bind_param('i', $user_id); $pst->execute(); $prefs = $pst->get_result()->fetch_assoc() ?: []; $pst->close(); }
} catch (Throwable $e) { $prefs = []; }
$muted_sql = notif_muted_types_sql($prefs);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_notifications_read'])) {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }

    $stmt = $mysqli->prepare('UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0');
    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->close();
    }
}

// Mark a single notification as read (used when a notification is clicked).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_notification_id'])) {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }

    $nid = (int)$_POST['mark_notification_id'];
    if ($nid > 0) {
        $stmt = $mysqli->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?');
        if ($stmt) {
            $stmt->bind_param('ii', $nid, $user_id);
            $stmt->execute();
            $stmt->close();
        }
    }
}

notif_sync_received_evaluations($mysqli, $user_id);

$notifications = [];
$unread_count = 0;
$stmt = $mysqli->prepare('SELECT id, type, message, extra_data, is_read, created_at FROM notifications WHERE user_id=?' . $muted_sql . ' ORDER BY created_at DESC, id DESC');
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['is_read'] = (int)$row['is_read'];
            $row['message'] = notif_apply_eval_wording($row, notification_message_for_view($row, $user_id));
            unset($row['extra_data']);
            $row['created_at_label'] = date('M d, Y g:i A', strtotime($row['created_at']));
            $row['link'] = notif_link_for((string)$row['type'], (string)($_SESSION['role'] ?? ''));
            $notifications[] = $row;
            if (!$row['is_read']) $unread_count++;
        }
    }
    $stmt->close();
}

$mysqli->close();
echo json_encode([
    'success' => true,
    'unread_count' => $unread_count,
    'notifications' => $notifications,
], JSON_UNESCAPED_SLASHES);
