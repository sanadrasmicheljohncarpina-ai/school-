<?php
// dean_notifications_api.php
// Lightweight JSON endpoint that dean_dashboard.php's notification bell
// polls on an interval, so a Dean sees new notices (period opened/closed,
// pending-evaluation counts changing, etc.) without reloading the page.
//
// KEEP IN SYNC: the notification-building logic below mirrors the
// "── NOTIFICATIONS ──" block in dean_dashboard.php. If you change what
// counts as a notification there, change it here too (or the bell and a
// fresh page load will disagree).

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
require_once dirname(__DIR__) . '/shared/system_settings_service.php';
require_once dirname(__DIR__) . '/shared/ea_personnel_service.php';
require_once 'school_head_structure_gate.php';
require_once __DIR__ . '/dean_ea_notices.php';

header('Content-Type: application/json');

// ── AUTH GUARD ── this is an AJAX endpoint, not a page load, so an
// unauthenticated or non-dean poll gets a 401 instead of a redirect.
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'dean') {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

$settings         = get_school_head_settings($mysqli, 'dean');
// Academic Structure / Academic Term gate: Dean owns College, Principal
// owns School Year. Narrow-only — existing scheduling, Force Open and
// Force Closed still decide open/closed while College is active.
$settings = sh_gate_apply($settings, 'dean');
$structureActive  = !empty($settings['school_head_applicable']);
$period_id_int    = $settings['period_id'] ?? 0;
$evalOpen         = !empty($settings['school_head_is_open']);

$notifications = $settings['notifications'];

// Executive Assistant notices: academic period and evaluation schedule.
foreach (dean_ea_notices($settings) as $eaNotice) {
    $notifications[] = $eaNotice;
}

// Recent submitted evaluations are durable notifications: the same events
// are returned on every poll while they remain among the latest activity, so
// a transient change in pending counts cannot make them disappear.
if ($period_id_int > 0) {
    $collegeLevels = "'1st Year College','2nd Year College','3rd Year College','4th Year College'";
    $recentEvalSql = "SELECT et.id, et.eval_type, et.submitted_at, et.target_user_id, target.role AS target_role, target.full_name AS target_name
        FROM evaluation_tracker et
        JOIN users target ON target.id=et.target_user_id
        WHERE et.period_id=" . (int)$period_id_int . "
          AND et.submitted_at IS NOT NULL
          AND target.is_active=1
          AND target.account_status='approved'
          AND et.target_user_id <> " . (int)($_SESSION['user_id'] ?? 0) . "
          AND (target.role IN ('principal','staff') OR (target.role IN ('teacher','faculty') AND EXISTS (
              SELECT 1 FROM user_year_levels uyl
              WHERE uyl.user_id=target.id AND uyl.year_level IN ($collegeLevels)
          )))
          AND et.eval_type IN ('student','peer','faculty_peer','staff_peer')
        ORDER BY et.submitted_at DESC, et.id DESC
        LIMIT 500";
    $recentEvalQ = $mysqli->query($recentEvalSql);
    if ($recentEvalQ) {
        $recent = $recentEvalQ->fetch_all(MYSQLI_ASSOC);
        foreach ($recent as $ev) {
            $kind = in_array($ev['eval_type'] ?? '', ['peer','faculty_peer','staff_peer'], true)
                ? 'Peer-to-Peer' : 'Student';
            // Where a click on this notice goes: the Reports page for this kind of
            // evaluation (Student -> that person's evaluator list; Peer -> the
            // Faculty/Staff lane the person belongs to).
            if ($kind === 'Peer-to-Peer') {
                $lane = (($ev['target_role'] ?? '') === 'staff') ? 'Staff' : 'Faculty';
                $evUrl = 'dean_reports.php?eval_type=peer&group=' . $lane;
            } else {
                $evUrl = 'dean_reports.php?view=students&target_id=' . (int)$ev['target_user_id'] . '&group=All&eval_type=student';
            }
            $notifications[] = [
                'key' => 'evaluation:' . (int)$ev['id'],
                'url' => $evUrl,
                'text' => "New {$kind} evaluation received for " . ($ev['target_name'] ?? 'personnel') . ".",
                'type' => 'evaluation',
                'created_at' => $ev['submitted_at'] ?? null,
            ];
        }
        $recentEvalQ->free();
    }
}

if ($structureActive) {
    $facultyList = ea_get_faculty($mysqli, $period_id_int);
    $staffList   = ea_get_staff($mysqli, $period_id_int);
    $eaList      = ea_get_executive_assistants($mysqli, $period_id_int);

    $countByStatus = function (array $rows, string $status): int {
        return count(array_filter($rows, fn($r) => ($r['evaluation_status'] ?? '') === $status));
    };

    $facultyCompleted = $countByStatus($facultyList, 'completed');
    $staffCompleted   = $countByStatus($staffList, 'completed');
    $eaCompleted      = $countByStatus($eaList, 'completed');

    $facultyPending = max(0, count($facultyList) - $facultyCompleted);
    $staffPending   = max(0, count($staffList) - $staffCompleted);
    $eaPending      = max(0, count($eaList) - $eaCompleted);

    $totalAssigned  = count($facultyList) + count($staffList) + count($eaList);
    $totalCompleted = $facultyCompleted + $staffCompleted + $eaCompleted;
    $overallCompletionPct = $totalAssigned > 0 ? (int) round($totalCompleted / $totalAssigned * 100) : 0;

    if ($evalOpen && ($facultyPending + $staffPending + $eaPending) > 0) {
        $remaining = $facultyPending + $staffPending + $eaPending;
        $notifications[] = "{$remaining} evaluation" . ($remaining === 1 ? '' : 's') . " still pending.";
    }
    if ($totalAssigned > 0 && $overallCompletionPct === 100) {
        $notifications[] = "All Higher Education evaluations are complete.";
    }
}

if (empty($notifications)) {
    $notifications[] = "No urgent items right now.";
}

$normalized = [];
foreach ($notifications as $idx => $n) {
    if (is_array($n)) {
        $normalized[] = $n;
    } else {
        $text = (string)$n;
        $normalized[] = [
            'key' => 'notice:' . hash('sha256', $text),
            'text' => $text,
            'type' => 'notice',
            'created_at' => null,
            // Pending / completion counts are answered by the Evaluation Tracker.
            'url' => (stripos($text, 'evaluation') !== false && (stripos($text, 'pending') !== false || stripos($text, 'complete') !== false))
                ? 'dean_evaluation_tracker.php' : null,
        ];
    }
}

$mysqli->close();

echo json_encode([
    'notifications' => $normalized,
    'count'          => count($normalized),
    'generated_at'   => date('c'),
]);