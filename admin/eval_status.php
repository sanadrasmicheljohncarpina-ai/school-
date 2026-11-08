<?php
/**
 * admin/eval_status.php
 *
 * Tiny polling endpoint used by eval_status_poll.js on EVERY dashboard
 * (student, faculty, staff, dean, principal, EA/admin).
 *
 * Why it exists: the evaluation schedule (open at start time / close at end
 * time) is only applied when a page runs ss_sync_from_database() on load, so
 * users had to reload to see the change. This endpoint runs that same sync on
 * each poll and returns a compact "state token". When the token changes, the
 * browser knows the evaluation opened/closed (or the EA changed a setting)
 * and refreshes the page.
 *
 * Any logged-in user may call it. It returns no personal data - only the
 * global evaluation state that every user is already allowed to see.
 */
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'domain' => '',
    'secure' => false, 'httponly' => true, 'samesite' => 'Lax',
]);
session_start();

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function es_json($payload, int $code = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Any authenticated user. Read what we need, then release the session lock so
// a 10-second poll never blocks the user's other requests.
$loggedIn = !empty($_SESSION['user_id']);
$sessionRole = strtolower((string)($_SESSION['role'] ?? ''));
session_write_close();
if (!$loggedIn) es_json(['error' => 'unauthorized'], 401);

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/shared/system_settings_service.php';

try {
    // Same call every page makes on load: applies the schedule to
    // evaluation_periods.is_active according to the current time.
    ss_sync_from_database($mysqli);

    $sys = ss_raw($mysqli);

    // Derive the state from the SAME rules as compute_eval_health()
    // in admin_dashboard.php (precedence: maintenance > draft > manual
    // override > automatic schedule).
    $state = 'unconfigured';
    $nextTransition = null;
    if (!empty($sys['maintenance'])) {
        $state = 'maintenance';
    } elseif (($sys['publish_state'] ?? 'published') === 'draft') {
        $state = 'draft';
    } else {
        $mode = $sys['control_mode'] ?? 'schedule';
        if ($mode === 'open') {
            $state = 'open';
        } elseif ($mode === 'closed') {
            $state = 'closed';
        } elseif (empty($sys['auto_schedule'])) {
            $state = 'manual';
        } else {
            $startDt = !empty($sys['eval_start']) ? ss_parse_datetime($sys['eval_start']) : null;
            $endDt   = !empty($sys['eval_end'])   ? ss_parse_datetime($sys['eval_end'])   : null;
            if ($startDt && $endDt && $endDt > $startDt) {
                $now = ss_now()->getTimestamp();
                if ($now < $startDt->getTimestamp()) {
                    $state = 'scheduled';
                    $nextTransition = $startDt->getTimestamp();
                } elseif ($now >= $endDt->getTimestamp()) {
                    $state = 'ended';
                } else {
                    $state = 'open';
                    $nextTransition = $endDt->getTimestamp();
                }
            }
        }
    }

    // Active evaluation period (also flips when a new period is activated).
    $periodId = 0;
    $isActive = 0;
    $res = $mysqli->query("SELECT id, is_active FROM evaluation_periods WHERE is_active=1 ORDER BY id DESC LIMIT 1");
    if ($res && ($row = $res->fetch_assoc())) {
        $periodId = (int)$row['id'];
        $isActive = (int)$row['is_active'];
    }

    // Opt-in (?watch=submissions): also fingerprint the evaluation_tracker so
    // the EA's Evaluation Tracker / Reports refresh when a submission arrives.
    // Only admin-side roles get this; students/faculty pages never pass it, so
    // they are not refreshed every time somebody else submits. The fingerprint
    // is just counts + newest id/time - no names or scores.
    $dataFingerprint = '';
    $watch = ($_GET['watch'] ?? '') === 'submissions'
        && in_array($sessionRole, ['admin', 'superadmin', 'registrar', 'executive_assistant'], true);
    if ($watch) {
        $fp = $mysqli->query("
            SELECT COUNT(*) AS c,
                   COALESCE(MAX(id), 0) AS max_id,
                   COALESCE(MAX(submitted_at), '') AS last_at,
                   COALESCE(SUM(status IN ('submitted','approved','archived')), 0) AS done,
                   COALESCE(SUM(status = 'submitted'), 0) AS n_submitted,
                   COALESCE(SUM(status = 'approved'), 0)  AS n_approved,
                   COALESCE(SUM(status = 'archived'), 0)  AS n_archived
            FROM evaluation_tracker
        ");
        if ($fp && ($f = $fp->fetch_assoc())) {
            $dataFingerprint = implode(',', $f);
        }
    }

    // Anything in this list changing means the user's screen may be stale.
    $token = md5(implode('|', [
        $dataFingerprint,
        $state,
        $periodId,
        $isActive,
        $sys['control_mode']  ?? '',
        $sys['auto_schedule'] ?? '',
        $sys['eval_start']    ?? '',
        $sys['eval_end']      ?? '',
        $sys['schedule_timezone'] ?? '',
        // Dean vs Principal applicability depends on the academic structure /
        // term (College = Dean, Basic Ed + School Year = Principal), so a
        // change there must refresh their screens too.
        $sys['acad_structure'] ?? ($sys['academic_structure'] ?? ($sys['structure'] ?? '')),
        $sys['acad_term']      ?? ($sys['academic_term']      ?? ($sys['semester']  ?? '')),
        $sys['publish_state'] ?? '',
        !empty($sys['maintenance']) ? 1 : 0,
    ]));

    if ($mysqli->ping()) $mysqli->close();

    es_json([
        'state'       => $state,
        'is_open'     => $state === 'open',
        'period_id'   => $periodId,
        'token'       => $token,
        'server_time' => time(),
        'server_time_ms' => (int) floor(microtime(true) * 1000),
        'next_transition' => $nextTransition,
        'maintenance' => !empty($sys['maintenance']),
    ]);
} catch (Throwable $e) {
    error_log('eval_status failed: ' . $e->getMessage());
    es_json(['error' => 'unavailable'], 500);
}
