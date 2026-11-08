<?php
/**
 * dashboard_counts.php
 * Real-time JSON endpoint for the admin dashboard.
 * Returns live user/eval counts + recent activity feed (role changes, evaluation
 * submissions, new users).
 *
 * Called by the admin dashboard every 10 seconds via fetch().
 * Also used by the notification poller to push role-change alerts.
 *
 * Pass ?mark_read=1 to mark all current role-change notifications as seen
 * (updates the session's "last seen" timestamp so the unread badge clears).
 *
 * ── System Logs box (feed_full) ───────────────────────────────────────────
 * Contains real-person activity from role/designation changes and evaluation
 * submissions. The displayed performer is the actual user tied to the event,
 * while the Source column names the specific module/process that created it.
 * "New user registered" events are intentionally excluded from feed_full;
 * they only show up in the bell dropdown (feed), not the audit box.
 */
session_start();

// This endpoint is polled by the dashboard every 10 seconds. Never allow a
// PHP warning/notice or a database exception to turn the response into HTML,
// because the browser would then fail JSON parsing and leave the dashboard's
// loading row on screen forever.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

require_once 'db.php';

// Guard — only authenticated admins
if (
    empty($_SESSION['user_id']) ||
    !in_array($_SESSION['role'] ?? '', ['admin', 'superadmin', 'registrar'])
) {
    http_response_code(403);
    dashboard_json(['error' => 'Unauthorized']);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function dashboard_query($mysqli, $sql) {
    try {
        return $mysqli->query($sql);
    } catch (Throwable $e) {
        error_log('dashboard_counts query failed: ' . $e->getMessage());
        return false;
    }
}

function dashboard_json($payload) {
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function _humanize($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    return ucwords(str_replace(['_', '-'], ' ', strtolower($value)));
}

/**
 * Maps a raw evaluation_tracker.eval_type value to the ?eval_type= tab
 * admin_analytics.php actually understands (student/peer/schoolhead/ea/staff),
 * so a notification click can deep-link straight into the right report tab.
 * Kept in sync with the eval_type grouping in admin_analytics.php.
 */
function _analyticsTabForEvalType($evalType) {
    $evalType = strtolower(trim((string)$evalType));
    if (in_array($evalType, ['school_head', 'supervisor_to_teacher', 'supervisor_to_staff', 'upward_to_ea'], true)) {
        return 'schoolhead';
    }
    if (in_array($evalType, ['peer', 'faculty_peer', 'staff_peer'], true)) {
        return 'peer';
    }
    if ($evalType === 'ea') return 'ea';
    if ($evalType === 'staff') return 'staff';
    return 'student';
}

function _formatTargetType($row) {
    $role = trim((string)($row['target_role'] ?? ''));
    $designation = trim((string)($row['target_designation'] ?? ''));

    // Prefer the actual designation stored for the evaluated person. When it
    // is not available, use the person's actual role value from users and
    // humanize it. No fixed role-to-label lookup is needed here.
    if ($designation !== '') return $designation;
    return _humanize($role) ?: 'Employee';
}

function _formatEvaluationSource($row) {
    $evalType   = strtolower(trim((string)($row['eval_type'] ?? '')));
    $context    = strtolower(trim((string)($row['evaluation_context'] ?? '')));
    $peerGroup  = trim((string)($row['peer_group'] ?? ''));
    $formType   = trim((string)($row['form_type'] ?? ''));
    $evaluator  = trim((string)($row['evaluator_role'] ?? ''));
    $targetType = _formatTargetType($row);

    /*
     * The Source is derived from values already stored in the database:
     * eval_type / evaluation_context / peer_group / evaluator role.
     * There is intentionally no lookup table in PHP that translates fixed
     * values such as "student" => "Student Evaluation".
     */
    $prefix = '';

    if ($formType !== '') {
        $prefix = _humanize($formType);
    }

    if ($prefix === '') {
        if ($evalType !== '' && str_contains($evalType, '_peer')) {
            // Example database value: staff_peer -> Staff Peer Evaluation.
            $prefix = _humanize($evalType) . ' Evaluation';
        } elseif ($evalType !== '' && str_contains($evalType, '_to_')) {
            // Example database value: student_to_faculty. Keep the real
            // direction stored in the data while presenting it compactly.
            [$from] = explode('_to_', $evalType, 2);
            $fromLabel = _humanize($from);
            $prefix = ($fromLabel !== '' ? $fromLabel . ' Evaluation' : 'Evaluation');
        } elseif ($evalType !== '') {
            $typeLabel = _humanize($evalType);
            $prefix = $typeLabel !== '' ? $typeLabel . ' Evaluation' : '';
        }
    }

    // When the tracker only has a generic eval_type such as "student",
    // use the actual evaluator role/context instead of a hardcoded label.
    if ($prefix === '' || in_array($evalType, ['student','ea','peer'], true)) {
        $roleLabel = _humanize($evaluator);
        if ($peerGroup !== '' || $context === 'peer' || ($evalType !== '' && str_contains($evalType, 'peer'))) {
            $prefix = ($roleLabel !== '' ? $roleLabel . ' ' : '') . 'Peer Evaluation';
        } elseif ($roleLabel !== '') {
            $prefix = $roleLabel . ' Evaluation';
        } elseif ($evalType !== '') {
            $prefix = _humanize($evalType) . ' Evaluation';
        } else {
            $prefix = 'Evaluation';
        }
    }

    return trim($prefix) . ' · ' . $targetType;
}

/* ── Mark as read (bell "Mark all read" button) ─────────────────────────── */
if (isset($_GET['mark_read'])) {
    $_SESSION['rcl_last_seen'] = time();
}

/* ── Feed size ───────────────────────────────────────────────────────────── */
// System Logs page passes ?feed_limit=N (and grows it via "Load more").
// Default stays 12 so the dashboard/bell behave exactly as before.
$feedLimit = isset($_GET['feed_limit']) ? (int)$_GET['feed_limit'] : 12;
$feedLimit = max(12, min($feedLimit, 1000));

// Bell dropdown keeps a long scrollable history instead of only the newest
// few. Each source query fetches at least this many rows so older
// notifications don't fall off the list.
$bellLimit  = 100;
$queryLimit = max($feedLimit, $bellLimit);

/* ── Counts ──────────────────────────────────────────────────────────────── */
$counts = [
    'total'    => 0,
    'teacher'  => 0,
    'staff'    => 0,
    'students' => 0,
    'evals'    => 0,
    'evals_submitted' => 0,
    'submitted' => 0,
    'pending_students' => 0,
    'submission_pct' => 0,
];

$activePeriodId = 0;
// Use the SAME current period as the dashboard page: the one System Settings points to, and only
// while it is active and not archived. (This used to take the highest-ID active period, which could
// differ from the page, for example after a restore left two periods active.)
$settingsPeriodId = null;
$settingsService = dirname(__DIR__) . '/shared/system_settings_service.php';
if (is_file($settingsService)) {
    try {
        require_once $settingsService;
        if (function_exists('get_system_settings')) {
            $dashSettings = get_system_settings($mysqli);
            $settingsPeriodId = (int)($dashSettings['period_id'] ?? 0);
        }
    } catch (Throwable $e) {
        error_log('dashboard_counts settings read failed: ' . $e->getMessage());
    }
}
if ($settingsPeriodId !== null) {
    if ($settingsPeriodId > 0) {
        $periodResult = dashboard_query($mysqli, "SELECT ep.id FROM evaluation_periods ep
            WHERE ep.id=" . (int)$settingsPeriodId . " AND ep.is_active=1
              AND NOT EXISTS (SELECT 1 FROM system_archives sa WHERE sa.period_id=ep.id AND sa.status='archived') LIMIT 1");
        if (!$periodResult) { // system_archives missing: plain active-period check
            $periodResult = dashboard_query($mysqli, "SELECT id FROM evaluation_periods WHERE id=" . (int)$settingsPeriodId . " AND is_active=1 LIMIT 1");
        }
        if ($periodResult) $activePeriodId = (int)($periodResult->fetch_assoc()['id'] ?? 0);
    }
} else {
    // Settings service unavailable: keep the previous behavior.
    $periodResult = dashboard_query($mysqli, "SELECT id FROM evaluation_periods WHERE is_active=1 ORDER BY id DESC LIMIT 1");
    if ($periodResult) $activePeriodId = (int)($periodResult->fetch_assoc()['id'] ?? 0);
}

$rows = [
    ['teacher',  "SELECT COUNT(*) AS c FROM users WHERE role='teacher' AND is_active=1"],
    ['staff',    "SELECT COUNT(*) AS c FROM users WHERE role='staff' AND is_active=1"],
    ['students', "SELECT COUNT(*) AS c FROM users WHERE role='student' AND is_active=1"],
];
foreach ($rows as [$key, $sql]) {
    $r = dashboard_query($mysqli, $sql);
    if ($r) $counts[$key] = (int)($r->fetch_assoc()['c'] ?? 0);
}

if ($activePeriodId > 0) {
    $stmt = $mysqli->prepare("SELECT COUNT(*) AS c FROM evaluation_tracker WHERE period_id=? AND status IN ('submitted','approved','archived')");
    if ($stmt) {
        $stmt->bind_param('i', $activePeriodId);
        if ($stmt->execute()) $counts['evals'] = $counts['evals_submitted'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
    }

    $stmt = $mysqli->prepare("SELECT COUNT(DISTINCT et.evaluator_id) AS c FROM evaluation_tracker et INNER JOIN users u ON u.id=et.evaluator_id WHERE et.period_id=? AND et.status IN ('submitted','approved','archived') AND u.role='student' AND u.is_active=1");
    if ($stmt) {
        $stmt->bind_param('i', $activePeriodId);
        if ($stmt->execute()) $counts['submitted'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
    }
}
$counts['submitted'] = min($counts['submitted'], $counts['students']);
$counts['pending_students'] = max(0, $counts['students'] - $counts['submitted']);
$counts['submission_pct'] = $counts['students'] > 0
    ? (int)round(($counts['submitted'] / $counts['students']) * 100)
    : 0;
$counts['total']         = $counts['teacher'] + $counts['staff'] + $counts['students'];
$counts['faculty_staff'] = $counts['teacher'] + $counts['staff']; // for the "Teacher" card

/* ── Recent Activity Feed ────────────────────────────────────────────────── */
// $feed holds EVERYTHING (role changes, evaluation submissions, new registrations) —
// this is what powers the bell dropdown.
//
// $auditFeed holds ONLY role_change + audit entries — this is what powers the
// System Logs box on the dashboard.

$feed = [];

/* ── 1. role_change_log → real person + real source ───────────────────── */
$rclExists = dashboard_query($mysqli, "SHOW TABLES LIKE 'role_change_log'");
if ($rclExists && $rclExists->num_rows > 0) {
    // Backward-compatible migration for older databases.
    $actorCol = dashboard_query($mysqli, "SHOW COLUMNS FROM role_change_log LIKE 'performed_by_id'");
    if ($actorCol && $actorCol->num_rows === 0) {
        dashboard_query($mysqli, "ALTER TABLE role_change_log ADD COLUMN performed_by_id INT UNSIGNED NULL AFTER user_id, ADD INDEX idx_performed_by (performed_by_id)");
    }
    $q = dashboard_query($mysqli, "
        SELECT rcl.user_id, rcl.old_role, rcl.new_role,
               rcl.old_designation, rcl.new_designation,
               rcl.changed_at, u.full_name,
               u.role AS current_role, u.account_status AS current_status,
               COALESCE(actor.full_name, u.full_name) AS actor_name
        FROM role_change_log rcl
        LEFT JOIN users u ON u.id = rcl.user_id
        LEFT JOIN users actor ON actor.id = rcl.performed_by_id
        ORDER BY rcl.changed_at DESC
        LIMIT " . $queryLimit . "
    ");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $ts = strtotime($row['changed_at']);
            $person = $row['full_name'] ?: ('User #' . (int)$row['user_id']);
            $oldLabel = _formatRole($row['old_role'], $row['old_designation']);
            $newLabel = _formatRole($row['new_role'], $row['new_designation']);
            $text = $person . ' updated their role/designation from ' . $oldLabel . ' → ' . $newLabel;
            $actor = $row['actor_name'] ?: $person;

            $roleChanged = strtolower((string)$row['old_role']) !== strtolower((string)$row['new_role']);
            $designationChanged = trim((string)$row['old_designation']) !== trim((string)$row['new_designation']);
            if ($roleChanged && $designationChanged) {
                $source = 'Personnel Registry · Role & Designation Update';
            } elseif ($roleChanged) {
                $source = 'Personnel Registry · Role Update';
            } elseif ($designationChanged) {
                $source = 'Personnel Registry · Designation Update';
            } else {
                $source = 'Personnel Registry · Personnel Update';
            }

            $feed[] = [
                'id'          => 'rcl_' . $row['user_id'] . '_' . $ts,
                'type'        => 'role_change',
                'text'        => htmlspecialchars($text),
                'meta'        => htmlspecialchars($source),
                'actor'       => htmlspecialchars($actor),
                'time'        => _timeAgo($ts),
                'ts'          => $ts,
                'color'       => '#f59e0b',
                'icon'        => 'fa-user-pen',
                'user'        => htmlspecialchars($person),
                'new_role'    => htmlspecialchars($newLabel),
                // Lets the dashboard link this notification to that account
                // in Account Management (manage_privileged_accounts.php).
                'account_user_id' => (int)$row['user_id'],
                'account_role'     => strtolower((string)($row['current_role'] ?? '')),
                'account_status'   => strtolower((string)($row['current_status'] ?? '')),
            ];
        }
    }
}

/* ── 2. evaluation_tracker → real evaluator + evaluated employee ───────── */
$levelFilter = $_GET['level'] ?? '';
$validLevels = ['junior_high', 'senior_high', 'college'];
if (!in_array($levelFilter, $validLevels, true)) $levelFilter = '';

$levelClause = $levelFilter ? " AND et.level = '" . $mysqli->real_escape_string($levelFilter) . "'" : "";
$evSubQ = dashboard_query($mysqli, "
    SELECT et.id, et.target_user_id, et.eval_bucket, et.form_type, et.eval_type, et.evaluation_context, et.peer_group, et.status, et.submitted_at, et.level,
           evaluator.full_name AS evaluator_name,
           evaluator.role AS evaluator_role,
           target.full_name AS target_name,
           target.role AS target_role,
           target.designation AS target_designation
    FROM evaluation_tracker et
    LEFT JOIN users evaluator ON evaluator.id = et.evaluator_id
    LEFT JOIN users target ON target.id = et.target_user_id
    WHERE et.status IN ('submitted','approved','archived')
      -- Confidential: evaluations OF the Executive Assistant (upward_to_ea, or
      -- school_head rows whose target is the EA account) must not reveal the
      -- evaluator in System Logs or the live bell notifications. The rows stay
      -- in evaluation_tracker, so results/analytics are unaffected.
      AND et.eval_type <> 'upward_to_ea'
      AND NOT (et.eval_type = 'school_head'
               AND COALESCE(target.role, '') IN ('admin','superadmin','executive_assistant'))" . $levelClause . "
    ORDER BY et.submitted_at DESC
    LIMIT " . $queryLimit . "
");
if ($evSubQ) {
    while ($row = $evSubQ->fetch_assoc()) {
        $ts = strtotime($row['submitted_at']);
        $evaluator = $row['evaluator_name'] ?: ('User #' . (int)($row['evaluator_id'] ?? 0));
        $target = $row['target_name'] ?: ('User #' . (int)($row['target_user_id'] ?? 0));
        $bucket = trim((string)($row['eval_bucket'] ?? '')) ?: 'Employee';
        $levelLabel = _formatLevel($row['level']);
        $source = _formatEvaluationSource($row);

        $feed[] = [
            'id'              => 'evt_' . $row['id'],
            'type'            => 'audit',
            'text'            => htmlspecialchars($evaluator . ' evaluated ' . $target),
            'actor'           => htmlspecialchars($evaluator),
            'meta'            => htmlspecialchars($source),
            'time'            => _timeAgo($ts),
            'ts'              => $ts,
            'color'           => '#14b8a6',
            'icon'            => 'fa-file-circle-check',
            'level'           => $row['level'],
            // Lets the dashboard link this notification straight to that
            // person's Evaluation Report (admin_analytics.php?view=students).
            'target_user_id'  => (int)$row['target_user_id'],
            'analytics_tab'   => _analyticsTabForEvalType($row['eval_type']),
        ];
    }
}

/* ── 3. Synthetic from users → bell dropdown only, NOT the audit box ── */
$q = dashboard_query($mysqli, "SELECT id, full_name, role, account_status, created_at FROM users ORDER BY created_at DESC LIMIT 20");
if ($q) {
    while ($row = $q->fetch_assoc()) {
        $ts = strtotime($row['created_at']);
        $feed[] = [
            'id'    => 'usr_' . md5($row['full_name'] . $ts),
            'type'  => 'new_user',
            'text'  => 'New ' . ucfirst($row['role']) . ' account registered: ' . htmlspecialchars($row['full_name']),
            'meta'  => 'User Registration',
            'time'  => _timeAgo($ts),
            'ts'    => $ts,
            'color' => '#22c55e',
            'icon'  => 'fa-user-plus',
            // Lets the dashboard link this notification to that account
            // in Account Management (manage_privileged_accounts.php).
            'account_user_id' => (int)$row['id'],
            'account_role'     => strtolower((string)($row['role'] ?? '')),
            'account_status'   => strtolower((string)($row['account_status'] ?? '')),
        ];
    }
}

// Sort combined feed by timestamp descending.
usort($feed, fn($a, $b) => $b['ts'] - $a['ts']);

// Bell dropdown: newest $bellLimit of EVERYTHING (role changes, submissions,
// new registrations). The panel is scrollable, so nothing is cut off at 6.
$feedShort = array_slice($feed, 0, $bellLimit);

// System Logs box: role changes + evaluation activity; new-user registrations
// remain in the bell feed and are deliberately left out here.
$auditFeed = array_values(array_filter($feed, fn($e) => in_array($e['type'], ['role_change', 'audit'])));
$auditFeed = array_slice($auditFeed, 0, $feedLimit);

/* ── Unread role-change count (for badge) ── */
$unreadRoleChanges = 0;
if ($rclExists && $rclExists->num_rows > 0) {
    $lastSeen = $_SESSION['rcl_last_seen'] ?? 0;
    $q = dashboard_query($mysqli, "SELECT COUNT(*) AS c FROM role_change_log WHERE UNIX_TIMESTAMP(changed_at) > " . (int)$lastSeen);
    if ($q) $unreadRoleChanges = (int)($q->fetch_assoc()['c'] ?? 0);
}

dashboard_json([
    'counts'              => $counts,
    'feed'                => $feedShort,
    'feed_full'           => $auditFeed,
    'unread_role_changes' => $unreadRoleChanges,
    'server_time'         => time(),
]);

/* ── Helpers ── */
function _timeAgo(int $ts): string {
    $diff = time() - $ts;
    if ($diff < 60)   return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $ts);
}
function _formatRole(string $role, ?string $designation): string {
    if (!empty($designation)) return ucwords(str_replace('_', ' ', $designation));
    return ucfirst($role);
}
function _formatLevel(?string $level): string {
    switch ($level) {
        case 'junior_high': return 'Junior High';
        case 'senior_high': return 'Senior High';
        case 'college':     return 'College';
        default:            return '';
    }
}