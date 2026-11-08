<?php
// session_bootstrap.php — include this BEFORE session_start() everywhere
session_set_cookie_params([
    'lifetime' => 0,        // session cookie, dies when browser closes
    'path'     => '/',      // available across the whole site, not just /admin/
    'domain'   => '',       // let the browser infer it — avoids localhost vs IP mismatches
    'secure'   => false,    // set true only if you're on https
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
require_once 'db.php';
require_once dirname(__DIR__) . '/shared/system_settings_service.php';

// Guard
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin','superadmin','registrar'])) {
    header("Location: admin_login.php"); exit;
}

// Fetch admin info
$admin_photo    = null;
$admin_fullname = $_SESSION['full_name'] ?? 'Admin';

$admin_username = '';
$admin_email    = '';

$stmt = $mysqli->prepare("SELECT full_name, username, email, photo FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
    
if ($row) {
    $admin_fullname = $row['full_name'];
    $admin_username = $row['username'];
    $admin_email    = $row['email'] ?? '';
    $admin_photo    = $row['photo'];
    
}

// Build the profile-photo URL from the value stored in users.photo.
// The database normally stores only the generated filename, but older records
// may contain image/..., uploads/..., ./..., or an already-qualified URL.
function build_profile_photo_url($photo) {
    $photo = trim((string)$photo);
    if ($photo === '') return null;

    if (preg_match('#^(?:https?:)?//#i', $photo) || str_starts_with($photo, 'data:image/')) {
        return $photo;
    }

    $photo = str_replace('\\', '/', $photo);
    $photo = ltrim($photo, '/');
    $photo = preg_replace('#^(?:\./|\../)+#', '', $photo);

    // Stored path already includes the project image directory.
    if (preg_match('#^(?:image|uploads)/#i', $photo)) {
        return '../' . $photo;
    }

    // Normal/current format: users.photo contains only the filename.
    return '../image/' . $photo;
}

$photo_src = build_profile_photo_url($admin_photo);
$parts     = explode(' ', trim($admin_fullname));
$initials  = strtoupper(substr($parts[0],0,1) . (isset($parts[1]) ? substr($parts[1],0,1) : ''));

$hourNow      = (int)date('G');
$greetingWord = $hourNow < 12 ? 'Good morning' : ($hourNow < 18 ? 'Good afternoon' : 'Good evening');
$admin_firstname = $parts[0] ?? $admin_fullname;
// Display-only label for the role badge. Session/DB value stays 'superadmin' —
// only the text shown on screen changes.
function display_role($role) {
    $map = ['superadmin' => 'Executive Assistant', 'admin' => 'Admin', 'registrar' => 'Registrar'];
    return $map[$role] ?? ucfirst($role);
}

// Executive Assistants and Admins share this workspace.  The interface stays
// the same because their tools overlap, while the displayed context reflects
// the account that is currently signed in.
$isExecutiveAssistant = ($_SESSION['role'] ?? '') === 'superadmin';
$workspaceTitle = $isExecutiveAssistant ? 'Executive Assistant Workspace' : 'Administrative Workspace';
$workspaceSubtitle = $isExecutiveAssistant
    ? 'Employee Performance Evaluation & Management System.'
    : 'Manage evaluation operations, personnel, and reporting.';

// Keep dashboard submission metrics scoped to the period selected in System
// Settings. This is the same period synchronization used by evaluation forms.
$dashboardSettings = get_system_settings($mysqli);
$dashboardPeriodId = (int)($dashboardSettings['period_id'] ?? 0);

// Only treat that period as current while it is active and has not been archived (same rule the
// Dean dashboard uses). System Archive deactivates a period and clears its live rows, so a stale
// setting can never bring its figures back; restoring the archive makes it current again.
if ($dashboardPeriodId > 0) {
    $periodIsCurrent = false;
    try {
        $pc = $mysqli->prepare("SELECT ep.id FROM evaluation_periods ep
            WHERE ep.id=? AND ep.is_active=1
              AND NOT EXISTS (SELECT 1 FROM system_archives sa WHERE sa.period_id=ep.id AND sa.status='archived')
            LIMIT 1");
    } catch (Throwable $e) {
        $pc = false; // system_archives not created yet: fall back to the plain active-period check
    }
    if (!$pc) {
        try { $pc = $mysqli->prepare("SELECT id FROM evaluation_periods WHERE id=? AND is_active=1 LIMIT 1"); }
        catch (Throwable $e) { $pc = false; }
    }
    if ($pc) {
        try {
            $pc->bind_param('i', $dashboardPeriodId);
            if ($pc->execute()) $periodIsCurrent = (bool)$pc->get_result()->fetch_assoc();
        } catch (Throwable $e) { $periodIsCurrent = false; }
        $pc->close();
        if (!$periodIsCurrent) $dashboardPeriodId = 0;
    }
}

// Live counts
$totalUsers  = 0; $activeEvals = 0; $periodSubmittedEvaluationCount = 0;
$eq = $mysqli->query("SELECT COUNT(*) as c FROM evaluation_tracker"); if ($eq) $activeEvals = (int)$eq->fetch_assoc()['c'];
if ($dashboardPeriodId > 0) {
    $periodSubmittedStmt = $mysqli->prepare("SELECT COUNT(*) AS c FROM evaluation_tracker WHERE period_id=? AND status IN ('submitted','approved','archived')");
    if ($periodSubmittedStmt) {
        $periodSubmittedStmt->bind_param('i', $dashboardPeriodId);
        $periodSubmittedStmt->execute();
        $periodSubmittedEvaluationCount = (int)($periodSubmittedStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $periodSubmittedStmt->close();
    }
}

// Sector breakdown (Teacher / Staff / Student) — only counts ACTIVE accounts.
// Self-registration has been removed; the system admin now creates every account
// directly, so inactive rows are stale leftover self-registered accounts that no
// longer represent real users and should not be counted.
$teacherCount = 0; $staffCount = 0; $studentCount = 0;
foreach (['teacher' => 'teacherCount', 'staff' => 'staffCount', 'student' => 'studentCount'] as $r => $var) {
    $cr = $mysqli->query(
        "SELECT COUNT(*) as c FROM users
         WHERE role='" . $mysqli->real_escape_string($r) . "' AND is_active=1"
    );
    if ($cr) $$var = (int)($cr->fetch_assoc()['c'] ?? 0);
}
$facultyCount = $teacherCount + $staffCount;
// "Total Users" only counts Faculty + Staff + Students (excludes admin/superadmin/registrar accounts)
$totalUsers = $facultyCount + $studentCount;

// Student participation for the current period: count each currently active
// student once if they submitted at least one evaluation during this period.
// This measures participation, not completion of every eligible target.
$submittedCount = 0;
if ($dashboardPeriodId > 0) {
    $subStmt = $mysqli->prepare(
        "SELECT COUNT(DISTINCT et.evaluator_id) AS c
         FROM evaluation_tracker et
         INNER JOIN users u ON u.id = et.evaluator_id
         WHERE et.period_id=? AND et.status IN ('submitted','approved','archived') AND u.role='student' AND u.is_active=1"
    );
    if ($subStmt) {
        $subStmt->bind_param('i', $dashboardPeriodId);
        $subStmt->execute();
        $submittedCount = (int)($subStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $subStmt->close();
    }
}
$submittedCount   = min($submittedCount, $studentCount);
$submissionPct    = $studentCount > 0 ? min(100, round(($submittedCount / $studentCount) * 100)) : 0;
$pendingEvalCount = max(0, $studentCount - $submittedCount);

// Inactive counts (for the Personnel Overview panel's Active/Inactive line)
$teacherInactive = 0; $staffInactive = 0;
foreach (['teacher' => 'teacherInactive', 'staff' => 'staffInactive'] as $r => $var) {
    $cr = $mysqli->query(
        "SELECT COUNT(*) as c FROM users
         WHERE role='" . $mysqli->real_escape_string($r) . "' AND is_active=0"
    );
    if ($cr) $$var = (int)($cr->fetch_assoc()['c'] ?? 0);
}

// Personnel accounts awaiting verification (for the Needs Attention panel)
$pendingRegCount = 0;
$prq = $mysqli->query("SELECT COUNT(*) as c FROM users WHERE account_status='pending'");
if ($prq) $pendingRegCount = (int)($prq->fetch_assoc()['c'] ?? 0);

// ── Widget data: submissions-over-time (last 6 months) ──
$monthlyBuckets = [];
for ($i = 5; $i >= 0; $i--) {
    $ts  = strtotime("-$i months");
    $key = date('Y-m', $ts);
    $monthlyBuckets[$key] = ['label' => date('M', $ts), 'count' => 0];
}
$msQ = $mysqli->query("
    SELECT DATE_FORMAT(submitted_at,'%Y-%m') AS ym, COUNT(*) AS c
    FROM evaluation_tracker
    WHERE status IN ('submitted','approved','archived')
      AND submitted_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym
");
if ($msQ) {
    while ($r = $msQ->fetch_assoc()) {
        if (isset($monthlyBuckets[$r['ym']])) $monthlyBuckets[$r['ym']]['count'] = (int)$r['c'];
    }
}
$submissionTrendLabels = array_column($monthlyBuckets, 'label');
$submissionTrendValues = array_column($monthlyBuckets, 'count');

// ── Widget data: users by role (%) ──
$rolePctFaculty  = $totalUsers > 0 ? round(($teacherCount / $totalUsers) * 100) : 0;
$rolePctStaff    = $totalUsers > 0 ? round(($staffCount   / $totalUsers) * 100) : 0;
$rolePctStudents = $totalUsers > 0 ? round(($studentCount / $totalUsers) * 100) : 0;

// ── Widget data: evaluation status overview ──
$evalCompleted  = 0;
$r = $mysqli->query("SELECT COUNT(*) AS c FROM evaluation_tracker WHERE status IN ('submitted','approved','archived')");
if ($r) $evalCompleted = (int)$r->fetch_assoc()['c'];
$evalInProgress = 0;
$r = $mysqli->query("SELECT COUNT(*) AS c FROM evaluation_tracker WHERE status='in_progress'");
if ($r) $evalInProgress = (int)$r->fetch_assoc()['c'];
$evalPending = 0;
$r = $mysqli->query("SELECT COUNT(*) AS c FROM evaluation_tracker WHERE status='draft'");
if ($r) $evalPending = (int)$r->fetch_assoc()['c'];
$evalStatusTotal = max(1, $evalCompleted + $evalInProgress + $evalPending);
$evalCompletedPct  = round(($evalCompleted  / $evalStatusTotal) * 100, 1);
$evalInProgressPct = round(($evalInProgress / $evalStatusTotal) * 100, 1);
$evalPendingPct    = round(($evalPending    / $evalStatusTotal) * 100, 1);

// ── Time-based greeting ──
$serverHour = (int)date('G');
$greeting   = $serverHour < 12 ? 'Good morning' : ($serverHour < 18 ? 'Good afternoon' : 'Good evening');
$firstName  = trim(explode(' ', trim($admin_fullname))[0] ?? $admin_fullname);

// ── Academic Structure → Term rules ──
// The Academic Term dropdown is constrained by the selected Academic Structure so
// invalid combinations (e.g. "Summer" for Junior High School) can't be saved.
$STRUCTURE_TERMS = [
    'college' => ['1st Semester', '2nd Semester', 'Summer'],
    'jhs'     => ['School Year'],
    'shs'     => ['School Year'],
];
$STRUCTURE_LABELS = [
    'college' => 'College',
    'jhs'     => 'Junior High School',
    'shs'     => 'Senior High School',
];

// Fetch system settings from DB (if table exists)
$sys = ss_raw($mysqli);[
    'acad_year'      => date('Y').'-'.(date('Y')+1),
    'acad_structure' => 'college',
    'acad_term'      => '1st Semester',
    'auto_schedule'  => 1,
    'control_mode'   => 'schedule',   // schedule | open | closed
    'eval_start'     => '',
    'eval_end'       => '',
    'maintenance'    => 0,
    'rule_only_during_period' => 1,
    'rule_edit_after_submit'  => 0,
    'rule_one_submission'     => 1,
    'rule_require_all'        => 1,
    'rule_auto_lock'          => 1,
    'rule_countdown'          => 1,
    'rule_prevent_late'       => 1,
    'publish_state'           => 'published', // draft | published
    'notify_eval_open'        => 1,
    'notify_eval_closing'     => 1,
    'notify_faculty_complete' => 1,
    'notify_reminders'        => 0,
];
$stbl = $mysqli->query("SHOW TABLES LIKE 'system_settings'");
if ($stbl && $stbl->num_rows > 0) {
    $sr = $mysqli->query("SELECT setting_key, setting_value FROM system_settings");
    if ($sr) while ($sr_row = $sr->fetch_assoc()) $sys[$sr_row['setting_key']] = $sr_row['setting_value'];
}
// Guard against a stale/invalid Structure+Term combination left over in the DB
if (!isset($STRUCTURE_TERMS[$sys['acad_structure']])) $sys['acad_structure'] = 'college';
if (!in_array($sys['acad_term'], $STRUCTURE_TERMS[$sys['acad_structure']], true)) {
    $sys['acad_term'] = $STRUCTURE_TERMS[$sys['acad_structure']][0];
}

// Handle settings save
$settings_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    $tab = $_POST['tab'] ?? 'profile';

    if ($tab === 'profile') {
        $new_fullname = trim($_POST['settings_fullname'] ?? '');
        $new_email    = trim($_POST['settings_email']    ?? '');
        if ($new_fullname && $new_email) {
            $upd = $mysqli->prepare("UPDATE users SET full_name=?, email=? WHERE id=?");
            $upd->bind_param("ssi", $new_fullname, $new_email, $_SESSION['user_id']);
            $upd->execute(); $upd->close();
            $_SESSION['full_name'] = $new_fullname;
            $admin_fullname = $new_fullname;
            $settings_msg = 'ok:Profile updated successfully.';
        } else { $settings_msg = 'error:Name and email are required.'; }
    }

    if ($tab === 'security') {
        $new_pw     = $_POST['settings_password']   ?? '';
        $confirm_pw = $_POST['settings_confirm_pw'] ?? '';
        if ($new_pw) {
            if (strlen($new_pw) < 8) { $settings_msg = 'error:Password must be at least 8 characters.'; }
            elseif ($new_pw !== $confirm_pw) { $settings_msg = 'error:Passwords do not match.'; }
            else {
                $hash = password_hash($new_pw, PASSWORD_DEFAULT);
                // Try password_hash column first, fallback to password
                $col_check = $mysqli->query("SHOW COLUMNS FROM users LIKE 'password_hash'");
                $col = ($col_check && $col_check->num_rows > 0) ? 'password_hash' : 'password';
                $upd = $mysqli->prepare("UPDATE users SET $col=? WHERE id=?");
                $upd->bind_param("si", $hash, $_SESSION['user_id']);
                $upd->execute(); $upd->close();
                $settings_msg = 'ok:Password changed successfully.';
            }
        } else { $settings_msg = 'error:Please enter a new password.'; }
    }

    if ($tab === 'system') {
        $acad_year      = trim($_POST['sys_acad_year'] ?? '');
        $acad_structure = $_POST['sys_acad_structure'] ?? 'college';
        if (!isset($STRUCTURE_TERMS[$acad_structure])) $acad_structure = 'college';

        // Server-side guard: never trust the submitted Term without checking it
        // against the submitted Structure, even though the dropdown is constrained
        // client-side too — this is what actually prevents an invalid combination
        // (e.g. Summer + Junior High School) from being persisted.
        $acad_term = $_POST['sys_acad_term'] ?? $STRUCTURE_TERMS[$acad_structure][0];
        if (!in_array($acad_term, $STRUCTURE_TERMS[$acad_structure], true)) {
            $acad_term = $STRUCTURE_TERMS[$acad_structure][0];
        }


        $control_mode = $_POST['sys_control_mode'] ?? 'schedule';
        if (!in_array($control_mode, ['schedule', 'open', 'closed'], true)) $control_mode = 'schedule';

        $maintenance = isset($_POST['sys_maintenance']) ? 1 : 0;
        $auto_schedule = isset($_POST['sys_auto_schedule']) ? 1 : 0;

        $eval_start = trim($_POST['eval_start_date'] ?? '');
        $eval_end   = trim($_POST['eval_end_date']   ?? '');

        $publish_state = $_POST['sys_publish_state'] ?? 'published';
        if (!in_array($publish_state, ['draft', 'published'], true)) $publish_state = 'published';

// Fetch system settings from DB (if table exists) — start from defaults,
// then let whatever ss_raw() returns override them.
$rule_only_during_period = isset($_POST['rule_only_during_period']) ? 1 : 0;
        $rule_edit_after_submit  = isset($_POST['rule_edit_after_submit'])  ? 1 : 0;
        $rule_one_submission     = isset($_POST['rule_one_submission'])     ? 1 : 0;
        $rule_require_all        = isset($_POST['rule_require_all'])        ? 1 : 0;
        $rule_auto_lock          = isset($_POST['rule_auto_lock'])          ? 1 : 0;
        $rule_countdown          = isset($_POST['rule_countdown'])          ? 1 : 0;
        $rule_prevent_late       = isset($_POST['rule_prevent_late'])       ? 1 : 0;

        $notify_eval_open        = isset($_POST['notify_eval_open'])        ? 1 : 0;
        $notify_eval_closing     = isset($_POST['notify_eval_closing'])     ? 1 : 0;
        $notify_faculty_complete = isset($_POST['notify_faculty_complete']) ? 1 : 0;
        $notify_reminders        = isset($_POST['notify_reminders'])        ? 1 : 0;

        $new_sys = [
            'acad_year'               => $acad_year,
            'acad_structure'          => $acad_structure,
            'acad_term'               => $acad_term,
            'auto_schedule'           => $auto_schedule,
            'control_mode'            => $control_mode,
            'eval_start'              => $eval_start,
            'eval_end'                => $eval_end,
            'maintenance'             => $maintenance,
            'rule_only_during_period' => $rule_only_during_period,
            'rule_edit_after_submit'  => $rule_edit_after_submit,
            'rule_one_submission'     => $rule_one_submission,
            'rule_require_all'        => $rule_require_all,
            'rule_auto_lock'          => $rule_auto_lock,
            'rule_countdown'          => $rule_countdown,
            'rule_prevent_late'       => $rule_prevent_late,
            'publish_state'           => $publish_state,
            'notify_eval_open'        => $notify_eval_open,
            'notify_eval_closing'     => $notify_eval_closing,
            'notify_faculty_complete' => $notify_faculty_complete,
            'notify_reminders'        => $notify_reminders,
        ];

        // Upsert into system_settings if table exists
        $stbl2 = $mysqli->query("SHOW TABLES LIKE 'system_settings'");
        if ($stbl2 && $stbl2->num_rows > 0) {
            foreach ($new_sys as $k => $v) {
                $mysqli->query("INSERT INTO system_settings (setting_key,setting_value) VALUES ('".
                    $mysqli->real_escape_string($k)."','".
                    $mysqli->real_escape_string($v)."') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            }
        }
        $sys = array_merge($sys, $new_sys);
        $settings_msg = 'ok:System settings saved.';
        ss_sync_evaluation_period($mysqli, $sys);
    }

    // Photo upload (any tab)
    if (!empty($_FILES['settings_photo']['name']) && $_FILES['settings_photo']['error'] === 0) {
        $ft      = mime_content_type($_FILES['settings_photo']['tmp_name']);
        $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
        if (in_array($ft, $allowed) && $_FILES['settings_photo']['size'] <= 5*1024*1024) {
            $ext  = pathinfo($_FILES['settings_photo']['name'], PATHINFO_EXTENSION);
            $dir  = __DIR__ . '/../image/';
            $fname= 'adm_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
            if (move_uploaded_file($_FILES['settings_photo']['tmp_name'], $dir.$fname)) {
                $upd = $mysqli->prepare("UPDATE users SET photo=? WHERE id=?");
                $upd->bind_param("si", $fname, $_SESSION['user_id']);
                $upd->execute(); $upd->close();
                $photo_src   = '../image/' . $fname;
                $admin_photo = $fname;
                if (!$settings_msg) $settings_msg = 'ok:Photo updated.';
            }
        } else { $settings_msg = 'error:Invalid file. Use JPG/PNG/WebP under 5MB.'; }
    }
}
$sm_parts = explode(':', $settings_msg, 2);
$sm_type  = $sm_parts[0] ?? '';
$sm_text  = $sm_parts[1] ?? '';

// ── Evaluation status: computed once, reused in the settings modal and the
// dashboard period box. Precedence: Maintenance > manual override (Force
// Open/Closed) > the automatic schedule > a manual "not automatic" fallback.
//
// compute_eval_health() returns the richer version used by the new "Current
// Configuration" summary panel: a color class, a short label, a plain-language
// headline + sub-message, and (when a schedule is configured) the derived
// duration/remaining/elapsed day counts so the admin never has to do the math.
function compute_eval_health(array $sys): array {
    $base = ['duration_days' => null, 'remaining_days' => null, 'elapsed_days' => null, 'days_until_start' => null];

    if (!empty($sys['maintenance'])) {
        return array_merge($base, ['label' => 'MAINTENANCE', 'cls' => 'gray',
            'headline' => 'System is in maintenance mode.', 'sub' => 'All non-admin access is locked.']);
    }
    if (($sys['publish_state'] ?? 'published') === 'draft') {
        return array_merge($base, ['label' => 'DRAFT', 'cls' => 'gray',
            'headline' => 'Evaluation is saved as a draft.', 'sub' => 'Not visible to students or faculty yet.']);
    }

    $mode = $sys['control_mode'] ?? 'schedule';
    if ($mode === 'open') {
        return array_merge($base, ['label' => 'LIVE · FORCED OPEN', 'cls' => 'green',
            'headline' => 'Evaluation is manually forced open.', 'sub' => 'Accepting submissions regardless of the schedule.']);
    }
    if ($mode === 'closed') {
        return array_merge($base, ['label' => 'CLOSED · FORCED', 'cls' => 'red',
            'headline' => 'Evaluation is manually forced closed.', 'sub' => 'Blocking submissions regardless of the schedule.']);
    }
    if (empty($sys['auto_schedule'])) {
        return array_merge($base, ['label' => 'CLOSED · MANUAL', 'cls' => 'gray',
            'headline' => 'Automatic scheduling is off.', 'sub' => 'Status will not change until a schedule is enabled.']);
    }

    $start = $sys['eval_start'] ?? '';
    $end   = $sys['eval_end']   ?? '';
    if (!$start || !$end) {
        return array_merge($base, ['label' => 'NOT CONFIGURED', 'cls' => 'gray',
            'headline' => 'No evaluation window is configured.', 'sub' => 'Set an opening and closing date to enable scheduling.']);
    }

    $startDt = ss_parse_datetime($start);
    $endDt   = ss_parse_datetime($end);
    if (!$startDt || !$endDt || $endDt <= $startDt) {
        return array_merge($base, ['label' => 'NOT CONFIGURED', 'cls' => 'gray',
            'headline' => 'The configured dates could not be read.', 'sub' => 'Re-check the opening and closing date fields.']);
    }

    $nowDt = ss_now();
    $ts = $startDt->getTimestamp();
    $te = $endDt->getTimestamp();
    $now = $nowDt->getTimestamp();
    $duration_days = max(0, (int)round(($te - $ts) / 86400));

    if ($now < $ts) {
        $days = max(1, (int)ceil(($ts - $now) / 86400));
        return array_merge($base, ['label' => 'SCHEDULED', 'cls' => 'yellow', 'duration_days' => $duration_days,
            'days_until_start' => $days,
            'headline' => 'Evaluation has not started yet.', 'sub' => 'Starts in ' . $days . ' day' . ($days === 1 ? '' : 's') . '.']);
    }
    if ($now >= $te) {
        $days = max(1, (int)floor(($now - $te) / 86400));
        return array_merge($base, ['label' => 'CLOSED', 'cls' => 'red', 'duration_days' => $duration_days,
            'elapsed_days' => $days,
            'headline' => 'Evaluation period has ended.', 'sub' => 'Ended ' . ($days === 1 ? 'today' : $days . ' days ago') . '.']);
    }
    $remaining = max(0, (int)ceil(($te - $now) / 86400));
    return array_merge($base, ['label' => 'LIVE', 'cls' => 'green', 'duration_days' => $duration_days,
        'remaining_days' => $remaining,
        'headline' => 'Evaluation is currently accepting submissions.', 'sub' => $remaining . ' day' . ($remaining === 1 ? '' : 's') . ' remaining.']);

}
function compute_eval_status(array $sys): array {
    $h = compute_eval_health($sys);
    // Older call sites just want a short badge label/color; map the richer
    // health labels back onto the original badge vocabulary they expect.
    $map = ['green' => 'open', 'red' => 'closed', 'yellow' => 'amber', 'gray' => 'gray'];
    $label = $h['label'];
    if ($label === 'LIVE') $label = 'OPEN';
    if ($label === 'LIVE · FORCED OPEN') $label = 'FORCED OPEN';
    if ($label === 'CLOSED · FORCED') $label = 'FORCED CLOSED';
    if ($label === 'SCHEDULED') $label = 'UPCOMING';
    if ($label === 'CLOSED') $label = 'CLOSED · ENDED';
    if ($label === 'DRAFT') $label = 'DRAFT · HIDDEN';
    return ['label' => $label, 'cls' => $map[$h['cls']] ?? 'gray'];
}

$evalHealth = compute_eval_health($sys);
$evalStatus = compute_eval_status($sys);

// ── Derived values for the "Current Configuration" summary panel ──
$RULE_KEYS = ['rule_only_during_period','rule_edit_after_submit','rule_one_submission','rule_require_all','rule_auto_lock','rule_countdown','rule_prevent_late'];
$activeRuleCount = count(array_filter($RULE_KEYS, fn($k) => !empty($sys[$k])));
$totalRuleCount  = count($RULE_KEYS);
$CONTROL_MODE_LABELS = ['schedule' => 'Follow Schedule', 'open' => 'Force Open', 'closed' => 'Force Closed'];
$controlModeLabel = $CONTROL_MODE_LABELS[$sys['control_mode']] ?? 'Follow Schedule';

$NOTIFY_KEYS = ['notify_eval_open','notify_eval_closing','notify_faculty_complete','notify_reminders'];
$notifyOnCount = count(array_filter($NOTIFY_KEYS, fn($k) => !empty($sys[$k])));

// System Health checklist — each item is [label, color-class, status-text].
// "Questionnaires" has no dedicated table wired in yet, so it's approximated
// from evaluation-tracker activity; swap in a real questionnaire-count query
// once that table is available.
$HEALTH_ITEMS = [
    ['Evaluation Window', $evalHealth['cls'] === 'gray' ? 'gray' : $evalHealth['cls'], $evalHealth['label']],
    ['Questionnaires',    $activeEvals > 0 ? 'green' : 'gray',                          $activeEvals > 0 ? 'Published' : 'Not Configured'],
    ['Faculty Registry',  $facultyCount > 0 ? 'green' : 'red',                          $facultyCount > 0 ? 'Complete' : 'Empty'],
    ['Student Registry',  $studentCount > 0 ? 'green' : 'red',                          $studentCount > 0 ? 'Complete' : 'Empty'],
    ['Automatic Schedule', !empty($sys['auto_schedule']) ? 'green' : 'yellow',          !empty($sys['auto_schedule']) ? 'Enabled' : 'Disabled'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>PBI Admin Master Workspace</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Rajdhani:wght@600;700&display=swap" rel="stylesheet"/>
    <style>
    :root{
        --dark-blue:#FFFFFF;--blue-mid:#FBF7EC;--blue-accent:var(--accent,#0F9F6E);
        --blue-hover:color-mix(in srgb,var(--accent,#0F9F6E) 80%,#000);--light:#FFFFFF;--muted:#67819E;
        --radius:8px;--shadow:0 4px 12px rgba(15,23,42,.08);
        --indicator-bg:color-mix(in srgb,var(--accent,#0F9F6E) 10%,transparent);
        --sev-green:#3F8F4A;--sev-yellow:#B7791F;--sev-red:#C24141;--sev-blue:#8B5E15;
        --page-bg:#FFFFFF;--card-bg:#FFFFFF;--card-border:#E8DFC4;
        --dashboard-bg:#FFFFFF;
        --text-dark:#0B1F3A;--text-dim:#67819E;--track-bg:#E8DFC4;
        --card-shadow:0 2px 4px rgba(30,82,144,.06),0 6px 16px rgba(30,82,144,.08);
        --sidebar-w:250px;--topbar-h:82px;--sidebar-scale:0.9;
        --sidebar-bg:#0F1E33;--sidebar-active:rgba(240,202,90,.20);--sidebar-active-solid:rgba(240,202,90,.16);
        --sidebar-text:#F3F7FC;--sidebar-text-dim:#8FA3C0;--sidebar-border:rgba(255,255,255,.08);
        --sidebar-card-bg:rgba(255,255,255,.05);--sidebar-hover-bg:rgba(255,255,255,.07);
        --sidebar-logo-bg:rgba(240,202,90,.16);--sidebar-logo-border:rgba(240,202,90,.45);--sidebar-icon-active:#F0CA5A;
        --accent-soft-bg:color-mix(in srgb,var(--accent,#0F9F6E) 11%,#fff);--accent-soft-border:color-mix(in srgb,var(--accent,#0F9F6E) 30%,#fff);
        --pbi-green:#16A34A;--pbi-green-dark:#0F7A38;--pbi-green-bg:#E8F8EE;--page-bg-tint:#F3FAF5;
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Inter',sans-serif;color:var(--text-dark);min-height:100vh;background:var(--dashboard-bg);background-attachment:fixed;}
    a{text-decoration:none;color:inherit;}

    /* ── TOPBAR (sits to the right of the sidebar) ── */
    .nude-nav{
        background:#FFFFFF;padding:14px 28px;
        display:flex;align-items:center;justify-content:space-between;
        position:fixed;top:0;left:calc(var(--sidebar-w) * var(--sidebar-scale));right:0;z-index:200;
        min-height:var(--topbar-h);
        box-shadow:var(--shadow);border-bottom:1px solid #E8DFC4;
        gap:20px;
    }
    .nude-nav .nav-container{flex-grow:0;}
    .topbar-greeting-wrap{display:flex;flex-direction:column;gap:2px;min-width:0;}
    .topbar-greeting{font-size:15px;font-weight:700;color:var(--text-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .topbar-greeting .wave{display:inline-block;}

    /* ── BRAND / AVATAR ── */
    .nav-brand{display:flex;align-items:center;gap:14px;flex-shrink:0;position:relative;}
    .brand-avatar-wrap{position:relative;cursor:pointer;}
    .brand-avatar{
        width:48px;height:48px;border-radius:50%;
        border:2px solid var(--blue-accent);
        box-shadow:0 0 8px color-mix(in srgb,var(--blue-accent) 60%,transparent);
        overflow:hidden;display:flex;align-items:center;justify-content:center;
        background:var(--blue-accent);flex-shrink:0;
        font-size:17px;font-weight:700;color:#FFFFFF;letter-spacing:.5px;
        transition:box-shadow .2s,border-color .2s;
    }
    .brand-avatar:hover{box-shadow:0 0 14px color-mix(in srgb,var(--blue-accent) 90%,transparent);border-color:var(--blue-accent);}
    .brand-avatar img{width:100%;height:100%;object-fit:cover;display:block;}
    /* small settings gear badge on avatar */
    .avatar-gear{
        position:absolute;bottom:-2px;right:-2px;
        width:17px;height:17px;border-radius:50%;
        background:var(--blue-accent);border:2px solid #FFFFFF;
        display:flex;align-items:center;justify-content:center;
        font-size:8px;color:#FFFFFF;pointer-events:none;
    }

    .brand-text{display:flex;flex-direction:column;gap:4px;}
    .brand-name{font-size:15px;font-weight:700;line-height:1.1;color:#0B1F3A;white-space:nowrap;max-width:220px;overflow:hidden;text-overflow:ellipsis;}
    .brand-role{display:inline-flex;align-items:center;width:fit-content;font-size:10.5px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--blue-accent);background:var(--accent-soft-bg);border:1px solid var(--accent-soft-border);padding:2px 8px;border-radius:20px;line-height:1.3;}
    #digital-clock{display:none;}
    .nav-divider{width:1px;height:34px;background:#E8DFC4;flex-shrink:0;}

    /* ── PROFILE DROPDOWN ── */
    .profile-dropdown{
        position:absolute;top:calc(100% + 12px);left:0;width:260px;
        background:#FFFFFF;border:1px solid #E8DFC4;
        border-radius:14px;box-shadow:0 16px 48px rgba(30,82,144,.13);
        opacity:0;visibility:hidden;transform:translateY(-8px);
        transition:all .25s cubic-bezier(.22,1,.36,1);z-index:9999;overflow:hidden;
    }
    .profile-dropdown.show{opacity:1;visibility:visible;transform:translateY(0);}
    .pd-head{
        padding:16px 16px 14px;display:flex;align-items:center;gap:11px;
        background:linear-gradient(135deg,var(--accent-soft-bg),#F8FAFC);
        border-bottom:1px solid var(--accent-soft-border);
    }
    .pd-head-avatar{
        width:46px;height:46px;border-radius:50%;overflow:hidden;flex-shrink:0;
        border:2px solid var(--blue-accent);
        background:var(--blue-accent);display:flex;align-items:center;justify-content:center;
        font-size:16px;font-weight:700;color:#FFFFFF;
    }
    .pd-head-avatar img{width:100%;height:100%;object-fit:cover;display:block;}
    .pd-head-name{font-size:13px;font-weight:700;color:#0B1F3A;line-height:1.3;}
    .pd-head-role{font-size:11px;color:var(--muted);}
    .pd-head-badge{display:inline-flex;align-items:center;gap:3px;margin-top:4px;font-size:10px;font-weight:600;padding:2px 8px;border-radius:20px;background:color-mix(in srgb,var(--blue-accent) 30%,transparent);color:var(--blue-accent);border:1px solid rgba(15,159,110,.35);text-transform:uppercase;}
    .pd-menu{padding:6px 0;}
    .pd-item{
        display:flex;align-items:center;gap:11px;padding:9px 16px;
        font-size:13px;font-weight:500;color:#294765;cursor:pointer;
        transition:background .15s;border:none;background:none;
        width:100%;text-align:left;font-family:'Inter',sans-serif;
    }
    .pd-item:hover{background:#F8FAFC;}
    .pd-icon{width:26px;height:26px;border-radius:6px;background:#FBF7EC;color:#67819E;display:flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0;transition:all .15s;}
    .pd-item:hover .pd-icon{background:color-mix(in srgb,var(--blue-accent) 25%,transparent);color:var(--blue-accent);}
    .pd-divider{height:1px;background:#E8DFC4;margin:4px 12px;}
    .pd-item.danger{color:#D18C96;}
    .pd-item.danger .pd-icon{color:#BF616A;background:rgba(191,97,106,.1);}
    .pd-item.danger:hover{background:rgba(191,97,106,.07);}

    /* ── NAV MENU (now horizontal, lives in the top navbar) ── */
    .nav-container{display:flex;align-items:center;justify-content:flex-end;gap:14px;}
    .nav-menu{display:flex;flex-direction:row;align-items:center;list-style:none;position:relative;gap:4px;overflow-x:auto;scrollbar-width:none;-ms-overflow-style:none;}
    .nav-menu::-webkit-scrollbar{display:none;}
    .nav-menu li{list-style:none;flex-shrink:0;}
    .indicator{position:absolute;top:0;left:0;width:0;height:100%;background:var(--indicator-bg);border-radius:var(--radius);transition:transform .3s cubic-bezier(.25,.46,.45,.94),width .3s cubic-bezier(.25,.46,.45,.94);z-index:0;opacity:0;pointer-events:none;}
    .nav-item{display:flex;align-items:center;justify-content:center;gap:9px;color:#0B1F3A;font-size:13.5px;font-weight:650;padding:9px 15px;margin:0;border-radius:var(--radius);transition:all .2s ease;position:relative;z-index:1;white-space:nowrap;}
    .nav-item .icon{width:16px;text-align:center;font-size:15px;transition:color .2s ease,transform .2s ease;}
    /* One primary color keeps navigation, icons, and controls cohesive on the white workspace. */
    .nav-item .icon{color:var(--blue-accent);}
    .nav-item:hover{color:#0B1F3A;background:#F1F5FF;}
    .nav-item:hover .icon{transform:translateY(-1px);}
    .nav-item.active{color:#0B1F3A;font-weight:750;background:var(--accent-soft-bg);}
    .nav-item.active .icon{filter:saturate(1.08);}
    .nav-item.logout{color:#E8927C;font-weight:600;margin-left:0;}
    .nav-item.logout .icon{color:#E8927C;}
    .nav-item.logout:hover{background:#FCEBEC;color:#B42318;}

    /* ── SIDEBAR ── */
    .pbi-sidebar{
        position:fixed;top:0;left:0;bottom:0;width:var(--sidebar-w);
        background:var(--sidebar-bg);
        padding:22px 16px 18px;
        display:flex;flex-direction:column;overflow-y:auto;z-index:250;
        border-right:1px solid var(--sidebar-border);
        box-shadow:2px 0 18px rgba(0,0,0,.28);
        zoom:var(--sidebar-scale);
    }
    .pbi-sidebar::-webkit-scrollbar{width:5px;}
    .pbi-sidebar::-webkit-scrollbar-thumb{background:rgba(255,255,255,.16);border-radius:4px;}
    .sidebar-brand{display:flex;align-items:center;gap:11px;padding:0 4px 20px;margin-bottom:14px;border-bottom:1px solid var(--sidebar-border);}
    .sidebar-logo{width:44px;height:44px;border-radius:10px;background:var(--sidebar-logo-bg);border:1.5px solid var(--sidebar-logo-border);display:flex;align-items:center;justify-content:center;font-size:19px;color:var(--sidebar-icon-active);flex-shrink:0;overflow:hidden;}
    .sidebar-logo img{width:100%;height:100%;object-fit:cover;display:block;}
    .sidebar-title{font-size:13px;font-weight:700;color:#FFFFFF;line-height:1.3;letter-spacing:.2px;}
    .sidebar-subtitle{font-size:10.5px;color:var(--sidebar-text-dim);line-height:1.4;margin-top:1px;}

    /* ── SIDEBAR: CENTERED PROFILE HEADER ── */
    .sidebar-profile{display:flex;flex-direction:column;align-items:center;text-align:center;padding:6px 8px 24px;margin-bottom:10px;border-bottom:1px solid var(--sidebar-border);}
    .sidebar-profile-logo{width:84px;height:84px;border-radius:50%;background:var(--sidebar-logo-bg);border:3px solid var(--sidebar-logo-border);display:flex;align-items:center;justify-content:center;font-size:32px;color:var(--sidebar-icon-active);font-weight:800;flex-shrink:0;overflow:hidden;margin-bottom:14px;box-sizing:border-box;padding:0;}
    .sidebar-profile-logo img{width:100%;height:100%;object-fit:cover;display:block;}
    .sidebar-profile-initials{width:100%;height:100%;align-items:center;justify-content:center;color:var(--sidebar-icon-active);font-size:28px;font-weight:800;letter-spacing:.5px;}
    .sidebar-profile-name{font-size:16px;font-weight:800;color:var(--sidebar-text);line-height:1.3;}
    .sidebar-profile-role{font-size:12px;font-weight:700;letter-spacing:.6px;color:var(--sidebar-icon-active);margin-top:3px;text-transform:uppercase;}

    .sidebar-nav{display:flex;flex-direction:column;gap:4px;}
    /* Compact sidebar navigation: keep the profile/logo block unchanged, while making only the feature links smaller. */
    .pbi-sidebar .nav-menu{display:flex;flex-direction:column;gap:5px;list-style:none;}
    .pbi-sidebar .nav-menu li{list-style:none;}
    .pbi-sidebar .nav-item{display:flex;align-items:center;justify-content:flex-start;gap:10px;color:var(--sidebar-text-dim);font-size:13px;font-weight:600;padding:8px 12px;border-radius:10px;transition:all .18s ease;white-space:nowrap;}
    .pbi-sidebar .nav-item .nav-icon-badge{width:30px;height:30px;border-radius:50%;background:var(--sidebar-logo-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .18s ease;}
    /* No fixed width/text-align here on purpose: different FontAwesome glyphs
       (house, gear, clock-rotate-left, etc.) have different natural widths,
       so forcing them into a fixed box and text-align:center left several
       icons visibly off-center inside their circular badge. Letting the
       badge's own flex centering (align-items/justify-content: center) do
       all the centering keeps every icon dead-center regardless of glyph
       shape. */
    .pbi-sidebar .nav-item .icon{display:flex;align-items:center;justify-content:center;color:var(--sidebar-icon-active);font-size:14px;line-height:1;transition:color .18s ease;}
    .pbi-sidebar .nav-item:hover{background:var(--sidebar-hover-bg);color:var(--sidebar-text);}
    .pbi-sidebar .nav-item.active{background:var(--sidebar-active-solid);color:var(--sidebar-text);font-weight:700;box-shadow:none;}
    .pbi-sidebar .nav-item.active .nav-icon-badge{background:rgba(240,202,90,.20);}
    .pbi-sidebar .nav-item.active .icon{color:var(--sidebar-icon-active);}


    /* ── SIDEBAR: CURRENT STATUS BOX ── */
    .sidebar-status{margin-top:auto;padding-top:16px;}
    .sidebar-status-card{background:var(--sidebar-card-bg);border:1px solid var(--sidebar-border);border-radius:12px;padding:14px 15px;margin-bottom:12px;}
    .sidebar-status-title{font-size:10.5px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--sidebar-text-dim);margin-bottom:10px;}
    .sidebar-status-row{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:9px;}
    .sidebar-status-row:last-of-type{margin-bottom:0;}
    .sidebar-status-label{font-size:11px;color:var(--sidebar-text-dim);}
    .sidebar-status-pill{display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:20px;text-transform:uppercase;letter-spacing:.3px;}
    .sidebar-status-pill.open{background:rgba(34,197,94,.16);color:#6EE7A6;border:1px solid rgba(110,231,166,.3);}
    .sidebar-status-pill.closed{background:rgba(239,68,68,.16);color:#F3A6A6;border:1px solid rgba(243,166,166,.3);}
    .sidebar-status-year{font-size:14px;font-weight:700;color:#FFFFFF;line-height:1.3;}
    .sidebar-status-sub{font-size:10.5px;color:var(--sidebar-text-dim);margin-top:2px;}
    .sidebar-help{display:flex;align-items:center;gap:10px;background:var(--sidebar-card-bg);border:1px solid var(--sidebar-border);border-radius:12px;padding:11px 13px;cursor:pointer;transition:background .18s ease;}
    .sidebar-help:hover{background:var(--sidebar-hover-bg);}
    .sidebar-help i{font-size:15px;color:var(--sidebar-icon-active);flex-shrink:0;}
    .sidebar-help-text{font-size:11.5px;font-weight:600;color:#FFFFFF;line-height:1.3;}
    .sidebar-help-sub{font-size:10.5px;color:var(--sidebar-text-dim);font-weight:500;}
    .sidebar-logout{margin-top:auto;padding-top:14px;border-top:1px solid var(--sidebar-border);}

    /* ── NOTIFICATION BELL ── */
    .notif-wrap{position:relative;display:flex;align-items:center;margin-left:4px;}
    .notif-btn{width:36px;height:36px;border-radius:50%;background:#F8FAFC;border:1px solid #E8DFC4;display:flex;align-items:center;justify-content:center;color:var(--text-dim);font-size:15px;cursor:pointer;transition:all .2s;position:relative;}
    .notif-btn:hover,.notif-btn.has-unread{color:var(--blue-accent);border-color:var(--accent-soft-border);background:var(--accent-soft-bg);}
    .notif-badge{position:absolute;top:-4px;right:-4px;min-width:18px;height:18px;border-radius:9px;background:#BF616A;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 4px;border:2px solid #FFFFFF;opacity:0;transform:scale(0);transition:opacity .2s,transform .2s;pointer-events:none;}
    .notif-badge.show{opacity:1;transform:scale(1);}

    /* Notification panel — fixed so it never breaks layout */
    .notif-dropdown{
        position:fixed;top:calc(var(--topbar-h) - 4px);right:16px;width:320px;
        background:#FFFFFF;border:1px solid #E8DFC4;
        border-radius:14px;box-shadow:0 16px 48px rgba(15,23,42,.14);
        opacity:0;visibility:hidden;transform:translateY(-8px);
        transition:all .25s cubic-bezier(.22,1,.36,1);z-index:9998;overflow:hidden;
    }
    .notif-dropdown.show{opacity:1;visibility:visible;transform:translateY(0);}
    .notif-header{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #E8DFC4;}
    .notif-header-title{font-size:13px;font-weight:700;color:#0B1F3A;display:flex;align-items:center;gap:7px;}
    .notif-mark-read{font-size:11px;color:var(--blue-accent);cursor:pointer;font-weight:600;background:none;border:none;font-family:'Inter',sans-serif;padding:0;}
    .notif-list{max-height:min(360px,calc(100vh - 170px));overflow-y:auto;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:#D9CBA0 transparent;}
    .notif-list::-webkit-scrollbar{width:4px;}
    .notif-list::-webkit-scrollbar-thumb{background:#D9CBA0;border-radius:4px;}
    .notif-list::-webkit-scrollbar-thumb:hover{background:#91A6BE;}
    .notif-item{display:flex;align-items:flex-start;gap:10px;padding:11px 14px;border-bottom:1px solid #FBF7EC;position:relative;transition:background .15s;}
    .notif-item:last-child{border-bottom:none;}
    .notif-item:hover{background:#F8FAFC;}
    .notif-item.clickable{cursor:pointer;}
    .notif-item.clickable:hover{background:var(--accent-soft-bg,#F0F6FF);}
    .notif-item.clickable:active{background:var(--accent-soft-bg,#FBF1D6);}
    .notif-item.unread{background:color-mix(in srgb,var(--blue-accent) 8%,transparent);}
    .notif-item.unread::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--blue-accent);border-radius:0 2px 2px 0;}
    .notif-icon{width:30px;height:30px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:12px;margin-top:1px;}
    .notif-text{font-size:12px;color:var(--text-dark);line-height:1.45;margin-bottom:3px;word-break:break-word;}
    .notif-meta{font-size:11px;color:var(--muted);}
    .notif-dot{width:6px;height:6px;border-radius:50%;background:#81A1C1;flex-shrink:0;margin-top:4px;}
    .notif-empty{text-align:center;padding:32px 16px;color:var(--muted);font-size:13px;}
    .notif-empty i{font-size:28px;display:block;margin-bottom:8px;opacity:.2;}
    .notif-footer{padding:9px 14px;border-top:1px solid #E8DFC4;text-align:center;font-size:11px;color:var(--muted);display:flex;align-items:center;justify-content:center;gap:8px;}
    .live-dot{display:inline-flex;align-items:center;gap:4px;font-size:11px;color:#0F9F6E;font-weight:600;padding:2px 8px;border-radius:20px;background:#ECFDF5;border:1px solid #A7F3D0;}
    .live-dot::before{content:'';width:6px;height:6px;border-radius:50%;background:#0F9F6E;display:inline-block;animation:livePulse 2s ease-in-out infinite;}
    @keyframes livePulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.8)}}
    @keyframes countFlash{0%{color:#0B1F3A}50%{color:#67819E}100%{color:#0B1F3A}}
    .count-updated{animation:countFlash .6s ease;}

    /* ── SETTINGS MODAL (light theme) ── */
    .modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;opacity:0;visibility:hidden;transition:all .25s;}
    .modal-overlay.show{opacity:1;visibility:visible;}

    /* ── CUSTOM CONFIRM MODAL (replaces native browser confirm()) ── */
    .pbi-confirm-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:10001;display:flex;align-items:center;justify-content:center;padding:16px;opacity:0;visibility:hidden;transition:all .2s;}
    .pbi-confirm-overlay.show{opacity:1;visibility:visible;}
    .pbi-confirm-card{background:var(--card-bg);border:1px solid var(--card-border);border-radius:16px;width:100%;max-width:380px;box-shadow:0 24px 64px rgba(15,23,42,.35);transform:scale(.96);transition:transform .2s cubic-bezier(.22,1,.36,1);padding:26px 24px 22px;}
    .pbi-confirm-overlay.show .pbi-confirm-card{transform:scale(1);}
    .pbi-confirm-msg{font-size:14px;color:var(--text-dark);line-height:1.6;margin-bottom:22px;font-weight:600;}
    .pbi-confirm-actions{display:flex;gap:10px;}
    .pbi-confirm-btn{flex:1;padding:10px;border-radius:9px;font-size:13.5px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:all .15s;}
    .pbi-confirm-btn.cancel{background:var(--page-bg);border:1px solid var(--card-border);color:var(--text-dark);}
    .pbi-confirm-btn.cancel:hover{background:#EEF3FA;}
    .pbi-confirm-btn.ok{background:var(--blue-accent);border:1px solid var(--blue-accent);color:#fff;}
    .pbi-confirm-btn.ok:hover{filter:brightness(1.08);}
    .pbi-unsaved-card{max-width:440px;}
    .pbi-unsaved-title{display:flex;align-items:center;gap:10px;font-size:16px;font-weight:800;color:var(--text-dark);margin-bottom:8px;}
    .pbi-unsaved-title i{color:#D97706;}
    .pbi-unsaved-sub{font-size:13px;color:var(--text-dim);line-height:1.5;margin-bottom:12px;}
    .pbi-unsaved-list{list-style:none;margin:0 0 18px;padding:10px 12px;max-height:190px;overflow:auto;background:#FFFBEB;border:1px solid #FDE68A;border-radius:10px;font-size:12.5px;color:#7A5A0A;line-height:1.5;}
    .pbi-unsaved-list li{padding:3px 0;}
    .pbi-unsaved-list li + li{border-top:1px dashed #F5E1A4;}
    .pbi-unsaved-actions{display:flex;gap:8px;flex-wrap:wrap;}
    .pbi-unsaved-actions .pbi-confirm-btn{flex:1 1 auto;white-space:nowrap;}
    .pbi-confirm-btn.danger{background:#fff;border:1px solid #FECACA;color:#B91C1C;}
    .pbi-confirm-btn.danger:hover{background:#FEF2F2;}
    .pbi-confirm-btn:disabled{opacity:.6;cursor:wait;}
    .settings-modal{background:var(--card-bg);border:1px solid var(--card-border);border-radius:18px;width:100%;max-width:580px;max-height:92vh;overflow-y:auto;box-shadow:0 24px 64px rgba(15,23,42,.35);transform:scale(.96);transition:transform .25s cubic-bezier(.22,1,.36,1);}
    .modal-overlay.show .settings-modal{transform:scale(1);}
    .sm-header{padding:22px 24px 18px;border-bottom:1px solid var(--card-border);display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,rgba(73,104,200,.08),transparent);}
    .sm-title{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--text-dark);}
    .sm-close{width:30px;height:30px;border-radius:7px;border:1px solid var(--card-border);background:var(--page-bg);color:var(--text-dim);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;font-size:13px;}
    .sm-close:hover{background:#FCE9E9;color:#D6455D;border-color:#F5C2C2;}
    .sm-tabs{display:flex;gap:3px;padding:14px 24px 0;border-bottom:1px solid var(--card-border);flex-wrap:wrap;}
    .sm-tab{padding:7px 14px;font-size:12px;font-weight:600;color:var(--text-dim);cursor:pointer;border-radius:7px 7px 0 0;border:1px solid transparent;border-bottom:none;transition:all .2s;background:none;font-family:'Inter',sans-serif;}
    .sm-tab.active{background:var(--page-bg);color:var(--text-dark);border-color:var(--card-border);}
    .sm-tab:hover:not(.active){color:var(--text-dark);}
    .sm-body{padding:22px 24px;}
    .sm-section{display:none;}
    .sm-section.active{display:block;}

    /* form elements */
    .sf-group{margin-bottom:16px;}
    .sf-label{display:block;font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:var(--text-dim);margin-bottom:6px;}
    .sf-input{width:100%;padding:9px 12px;background:var(--page-bg);border:1px solid var(--card-border);border-radius:var(--radius);color:var(--text-dark);font-size:13px;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s;}
    .sf-input:focus{border-color:#6887E8;box-shadow:0 0 0 3px rgba(139,92,246,.15);}
    .sf-input:disabled{opacity:.5;cursor:not-allowed;}
    .sf-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
    .sf-hint{font-size:11px;color:var(--text-dim);margin-top:4px;}
    .sf-alert{display:flex;align-items:center;gap:8px;padding:9px 13px;border-radius:8px;font-size:13px;margin-bottom:14px;}
    .sf-alert.ok{background:#EAF6E9;border:1px solid #BFE3BD;color:#227A22;}
    .sf-alert.err{background:#FCEBEB;border:1px solid #F3B9B9;color:#B02020;}

    /* photo row */
    .sf-photo-row{display:flex;align-items:center;gap:14px;padding:13px 14px;background:var(--page-bg);border:1px dashed #C7B4F5;border-radius:9px;margin-bottom:16px;cursor:pointer;}
    .sf-photo-preview{width:58px;height:58px;border-radius:50%;border:2px solid #6887E8;overflow:hidden;background:#6887E8;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:700;color:#fff;flex-shrink:0;}
    .sf-photo-preview img{width:100%;height:100%;object-fit:cover;display:block;}
    .sf-photo-info p{font-size:13px;font-weight:600;color:var(--text-dark);margin-bottom:2px;}
    .sf-photo-info span{font-size:11px;color:var(--text-dim);}
    .sf-choose-btn{display:inline-flex;align-items:center;gap:5px;margin-top:7px;padding:5px 12px;background:#F1EBFE;border:1px solid #D8C7FA;border-radius:6px;color:#4968C8;font-size:11px;font-weight:600;cursor:pointer;transition:all .2s;}
    .sf-choose-btn:hover{background:#E5D9FB;}

    /* toggle switch */
    .sf-toggle-row{display:flex;align-items:center;justify-content:space-between;padding:11px 0;border-bottom:1px solid var(--card-border);gap:14px;}
    .sf-toggle-row:last-child{border-bottom:none;}
    .sf-toggle-label{font-size:13px;color:var(--text-dark);}
    .sf-toggle-sub{font-size:11px;color:var(--text-dim);margin-top:1px;}
    .toggle-sw{position:relative;width:40px;height:22px;flex-shrink:0;}
    .toggle-sw input{opacity:0;width:0;height:0;}
    .toggle-slider{position:absolute;cursor:pointer;inset:0;background:#D5DAE3;border-radius:22px;transition:.3s;}
    .toggle-slider::before{content:'';position:absolute;height:16px;width:16px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.3s;}
    .toggle-sw input:checked + .toggle-slider{background:#6887E8;}
    .toggle-sw input:checked + .toggle-slider::before{transform:translateX(18px);}

    /* period/semester section */
    .period-card{background:var(--page-bg);border:1px solid var(--card-border);border-radius:10px;padding:16px;margin-bottom:14px;}
    .period-card-title{font-size:12px;font-weight:700;color:#4968C8;text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;display:flex;align-items:center;gap:6px;}
    .period-status{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap;}
    .period-status.open{background:#E4F7E4;color:#1E8E1E;border:1px solid #BFE3BD;}
    .period-status.closed{background:#FBEAE6;color:#C2542F;border:1px solid #F0C7B9;}
    .period-status.amber{background:#FDF3DE;color:#B87A08;border:1px solid #F3DDA1;}
    .period-status.gray{background:#EEF1F6;color:var(--text-dim);border:1px solid var(--card-border);}
    .period-status.green{background:#E4F7E4;color:#1E8E1E;border:1px solid #BFE3BD;}
    .period-status.yellow{background:#FDF3DE;color:#B87A08;border:1px solid #F3DDA1;}
    .period-status.red{background:#FBEAE6;color:#C2542F;border:1px solid #F0C7B9;}
    .period-status.blue{background:#FBF1D6;color:#C9A227;border:1px solid #E8CE85;}

    /* Evaluation Access: control-mode radio cards */
    .ctrl-modes{display:flex;flex-direction:column;gap:8px;}
    .ctrl-option{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border:1px solid var(--card-border);border-radius:8px;background:var(--page-bg);cursor:pointer;transition:all .2s;}
    .ctrl-option:hover{border-color:#C7B4F5;}
    .ctrl-option.selected{border-color:#6887E8;background:var(--card-bg);}
    .ctrl-option input{margin-top:3px;accent-color:#6887E8;flex-shrink:0;}
    .ctrl-option-title{font-size:13px;font-weight:600;color:var(--text-dark);}
    .ctrl-option-sub{font-size:11px;color:var(--text-dim);margin-top:1px;}

    /* Submission Settings: grouped subsections within one card */
    .rule-subgroup{margin-bottom:16px;}
    .rule-subgroup-title{font-size:11px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.7px;margin-bottom:2px;padding-bottom:6px;border-bottom:1px solid var(--card-border);}

    /* Unsaved-changes indicator in the System & Period footer */
    .unsaved-indicator{display:none;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#B87A08;margin-right:auto;padding:5px 10px;border-radius:20px;background:#FDF3DE;border:1px solid #F3DDA1;}
    .unsaved-indicator.show{display:inline-flex;}
    .unsaved-indicator i{animation:livePulse 1.6s ease-in-out infinite;}

    /* ── CURRENT CONFIGURATION SUMMARY (top of System & Period tab) ── */
    .cfg-summary{background:linear-gradient(135deg,rgba(139,92,246,.08),var(--page-bg));border:1px solid var(--card-border);border-radius:12px;padding:18px;margin-bottom:16px;}
    .cfg-headline-row{display:flex;align-items:flex-start;gap:14px;margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid var(--card-border);}
    .cfg-headline-badge{flex-shrink:0;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;letter-spacing:.4px;white-space:nowrap;}
    .cfg-headline-text .cfg-headline{font-size:14px;font-weight:700;color:var(--text-dark);margin-bottom:2px;}
    .cfg-headline-text .cfg-sub{font-size:12px;color:var(--text-dim);}
    .cfg-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px 20px;margin-bottom:16px;}
    .cfg-item{display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:12px;padding:6px 0;border-bottom:1px solid var(--card-border);}
    .cfg-label{color:var(--text-dim);font-weight:600;}
    .cfg-value{color:var(--text-dark);font-weight:700;text-align:right;}
    .cfg-metric-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px;}
    .cfg-metric{background:var(--card-bg);border:1px solid var(--card-border);border-radius:8px;padding:10px 12px;text-align:center;}
    .cfg-metric-value{font-size:18px;font-weight:700;color:var(--text-dark);}
    .cfg-metric-label{font-size:10px;color:var(--text-dim);text-transform:uppercase;letter-spacing:.5px;margin-top:2px;}

    /* System Health checklist */
    .health-list{display:flex;flex-direction:column;gap:0;}
    .health-item{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 0;border-bottom:1px solid var(--card-border);font-size:13px;}
    .health-item:last-child{border-bottom:none;}
    .health-item-name{color:var(--text-dark);font-weight:600;}
    .health-item-status{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;}
    .health-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
    .health-dot.green{background:#22A722;box-shadow:0 0 6px rgba(34,167,34,.5);}
    .health-dot.yellow{background:#D9A62A;box-shadow:0 0 6px rgba(217,166,42,.5);}
    .health-dot.red{background:#DC4444;box-shadow:0 0 6px rgba(220,68,68,.5);}
    .health-dot.gray{background:#AEB6C4;}
    .health-item-status.green{color:#1E8E1E;}
    .health-item-status.yellow{color:#B87A08;}
    .health-item-status.red{color:#C2302F;}
    .health-item-status.gray{color:var(--text-dim);}

    /* Sticky so the primary action stays reachable on long tabs (System & Period)
       without having to scroll all the way to the bottom of the modal. Sticks
       to the bottom of .settings-modal, its nearest scrolling ancestor. */
    .sm-footer{position:sticky;bottom:0;padding:14px 24px;border-top:1px solid var(--card-border);display:flex;align-items:center;justify-content:flex-end;gap:10px;background:var(--card-bg);box-shadow:0 -8px 20px rgba(30,82,144,.08);z-index:5;}
    .sf-btn{padding:9px 20px;border-radius:var(--radius);font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;font-family:'Inter',sans-serif;}
    .sf-btn-cancel{background:var(--page-bg);border:1px solid var(--card-border);color:var(--text-dim);}
    .sf-btn-cancel:hover{background:var(--card-border);color:var(--text-dark);}
    .sf-btn-discard{background:#FBEAE6;border:1px solid #F0C7B9;color:#C2542F;}
    .sf-btn-discard:hover{background:#F6D8D0;color:#A5391B;}
    .sf-btn-save{background:#6887E8;border:1px solid transparent;color:#fff;box-shadow:0 3px 10px rgba(139,92,246,.35);}
    .sf-btn-save:hover{background:#4968C8;}

    /* ── PAGES ── */
    .page-content{margin-top:var(--topbar-h);margin-left:calc(var(--sidebar-w) * var(--sidebar-scale));padding:16px 20px;}
    .page{display:none;background:transparent;}
    .page:has(>iframe){background:#FFFFFF;}
    .page.active{display:block;}
    .iframe-box{width:100%;height:calc(100vh - var(--topbar-h) - 20px);border:none;border-radius:var(--radius);background:#FFFFFF;}
    html[data-theme="dark"] .page:has(>iframe){background:var(--bg)!important;}
    html[data-theme="dark"] .iframe-box{background:var(--bg)!important;}

    /* ── DASHBOARD (light card theme) ── */
    /* ── GROUNDED DASHBOARD WORKSPACE ── */
    #dashboard.active{background:#F3F6FA;border:1px solid #DCE5EF;border-radius:18px;box-shadow:inset 0 1px 0 rgba(255,255,255,.9);min-height:calc(100vh - var(--topbar-h) - 28px);overflow:hidden;}
    .pbi-dashboard-container{padding:24px 24px 34px;}
    .pbi-dashboard-heading{padding:2px 4px 20px;margin:0;}
    .pbi-heading-topline{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;}
    .pbi-dashboard-greeting{margin:0 0 2px;color:#64748B;font-size:12px;font-weight:600;}
    .pbi-dashboard-kicker{margin:0 0 5px;color:#C9A227;font-size:11px;font-weight:800;letter-spacing:.85px;text-transform:uppercase;display:flex;align-items:center;gap:7px;}
    .pbi-dashboard-meta{display:flex;flex-direction:column;align-items:flex-end;gap:8px;padding-top:4px;white-space:nowrap;}
    .pbi-meta-period,.pbi-meta-updated{display:inline-flex;align-items:center;gap:7px;border:1px solid #D7E2EF;background:#FFFFFF;border-radius:999px;padding:7px 11px;color:#526783;font-size:11px;font-weight:600;box-shadow:0 2px 7px rgba(15,23,42,.04);}
    .pbi-meta-period i{color:#C9A227;}
    .pbi-meta-updated{background:#EEF4FA;color:#71839B;border-color:#DFE8F2;}
    .pbi-meta-updated i{color:#64748B;}
    .pbi-stats-row{gap:14px;margin-bottom:16px;}
    .pbi-stat-card{box-shadow:0 4px 12px rgba(15,23,42,.07);}
    .pbi-period-box{box-shadow:0 5px 15px rgba(15,23,42,.07);margin-bottom:16px;}
    .pbi-panel{box-shadow:0 5px 15px rgba(15,23,42,.07);}
    @media(max-width:900px){.pbi-heading-topline{flex-direction:column;}.pbi-dashboard-meta{align-items:flex-start;flex-direction:row;flex-wrap:wrap;}.pbi-dashboard-container{padding:18px 14px 28px;}}

    /* Existing dashboard styles */
    .pbi-dashboard-heading{margin:0 0 30px;}
    .pbi-dashboard-heading{margin:0!important;padding:2px 4px 20px;}
    .pbi-dashboard-kicker{display:flex;align-items:center;gap:7px;margin:0 0 7px;color:var(--blue-accent);font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;}
    .pbi-system-title{font-size:24px;font-weight:700;margin:0 0 5px;color:var(--text-dark);}
    .pbi-system-subtitle{font-size:14px;color:var(--text-dim);margin:0;}
    /* ── STAT CARDS (top row) ── */
    .pbi-stats-row{display:flex;gap:18px;margin-bottom:22px;flex-wrap:wrap;}
    .pbi-stat-card{background:var(--card-bg);border:1px solid var(--card-border);border-top:3px solid var(--blue-accent);border-radius:12px;padding:18px 20px;flex:1;min-width:180px;position:relative;overflow:hidden;box-shadow:var(--card-shadow);transition:transform .2s ease,box-shadow .2s ease;}
    .pbi-stat-card:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(15,23,42,.1);}
    .pbi-stat-card.stat-blue{border-top-color:#C9A227;}
    .pbi-stat-card.stat-blue .pbi-stat-icon{background:#FBF1D6;color:#C9A227;}
    .pbi-stat-card.stat-purple{border-top-color:#4968C8;}
    .pbi-stat-card.stat-purple .pbi-stat-icon{background:#F1EBFE;color:#4968C8;}
    .pbi-stat-card.stat-green{border-top-color:#0F9F6E;}
    .pbi-stat-card.stat-green .pbi-stat-icon{background:#E8F8F1;color:#0F9F6E;}
    .pbi-stat-card.stat-orange{border-top-color:#C77A08;}
    .pbi-stat-card.stat-orange .pbi-stat-icon{background:#FDF0DF;color:#C77A08;}
    .pbi-stat-icon{position:absolute;top:14px;right:14px;width:38px;height:38px;border-radius:10px;font-size:15px;display:flex;align-items:center;justify-content:center;}
    .pbi-stat-label{font-size:11px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px;}
    .pbi-stat-value{font-size:30px;font-weight:700;color:var(--text-dark);line-height:1;margin-bottom:6px;}
    .pbi-stat-sub{font-size:12px;color:var(--text-dim);}
    .pbi-stat-trend{font-size:11px;font-weight:700;margin-top:8px;display:inline-flex;align-items:center;gap:4px;}
    .pbi-stat-trend.up{color:#0F9F6E;}
    .pbi-stat-trend.flat{color:var(--text-dim);}

    /* ── EVALUATION PERIOD CARD ── */
    .pbi-period-box{background:var(--card-bg);border:1px solid var(--card-border);border-radius:12px;padding:18px 22px;margin-bottom:26px;box-shadow:var(--card-shadow);}
    .pbi-period-top{display:flex;align-items:center;flex-wrap:wrap;gap:18px 32px;margin-bottom:14px;}
    .pbi-period-block .pbi-period-label{font-size:11px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.7px;margin-bottom:4px;}
    .pbi-period-value{font-size:19px;font-weight:700;color:var(--text-dark);display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
    .pbi-period-sub{font-size:12px;color:var(--text-dim);margin-top:2px;}
    .pbi-progress-wrap{flex:1;min-width:220px;}
    .pbi-progress-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;font-size:12px;color:var(--text-dim);}
    .pbi-progress-top strong{color:var(--text-dark);font-size:13px;}
    .pbi-progress-track{width:100%;height:12px;border-radius:6px;background:var(--track-bg);overflow:hidden;}
    .pbi-progress-fill{height:100%;background:linear-gradient(90deg,#C9A227,#F0CA5A);border-radius:6px;transition:width .4s ease;}
    .pbi-progress-note{font-size:11px;color:var(--text-dim);margin-top:6px;}

    .pbi-sector-row{display:flex;gap:20px;margin-bottom:30px;}
    .pbi-sector-card{background:var(--card-bg);border:1px solid var(--card-border);border-top:4px solid var(--blue-accent);color:var(--text-dark);border-radius:12px;padding:18px 20px;box-shadow:var(--card-shadow);flex:1;display:flex;flex-direction:column;gap:10px;min-height:0;transition:transform .2s ease,box-shadow .2s ease;}
    .pbi-sector-card:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(15,23,42,.1);}
    .pbi-sector-card.teacher-card{border-top-color:#C9A227;}
    .pbi-sector-card.staff-card{border-top-color:#4968C8;}
    .pbi-sector-card.student-card{border-top-color:#0F9F6E;}
    .pbi-sector-top{display:flex;align-items:center;justify-content:space-between;}
    .pbi-sector-card h3{margin:0;font-size:13px;font-weight:700;letter-spacing:.8px;color:var(--text-dim);text-transform:uppercase;}
    .pbi-sector-count{font-size:32px;font-weight:700;line-height:1;}
    .pbi-sector-card.teacher-card .pbi-sector-count{color:#C9A227;}
    .pbi-sector-card.staff-card .pbi-sector-count{color:#4968C8;}
    .pbi-sector-card.student-card .pbi-sector-count{color:#0F9F6E;}
    .pbi-sector-meta{display:flex;gap:14px;flex-wrap:wrap;font-size:11px;color:var(--text-dim);border-top:1px solid var(--card-border);padding-top:10px;}
    .pbi-sector-meta span{display:flex;align-items:center;gap:5px;}
    .pbi-sector-meta i{font-size:10px;opacity:.8;}
    .pbi-view-btn{background:#C9A227;color:#fff;border:none;padding:8px 18px;font-size:13px;font-weight:600;border-radius:6px;cursor:pointer;transition:background .2s;align-self:flex-start;}
    .pbi-view-btn:hover{background:#C9A227;}
    .pbi-sector-card.staff-card .pbi-view-btn{background:#4968C8;}
    .pbi-sector-card.staff-card .pbi-view-btn:hover{background:#6D28D9;}
    .pbi-sector-card.student-card .pbi-view-btn{background:#0F9F6E;}
    .pbi-sector-card.student-card .pbi-view-btn:hover{background:#047857;}

    /* ── GREETING ── */
    .pbi-dashboard-greeting{font-size:14px;color:var(--text-dim);margin:0 0 6px;}
    .pbi-dashboard-greeting .wave{display:inline-block;}

    /* ── STAT CARDS (redesigned: icon circle + trend) ── */
    .pbi-stat-card{border-top:none;}
    .pbi-stat-card .pbi-stat-icon-circle{width:46px;height:46px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:17px;margin-bottom:14px;}
    .pbi-stat-card.stat-teal .pbi-stat-icon-circle{background:var(--pbi-green-bg);color:var(--pbi-green-dark);}
    .pbi-stat-card.stat-skyblue .pbi-stat-icon-circle{background:#FBF1D6;color:#C9A227;}
    .pbi-stat-card.stat-violet .pbi-stat-icon-circle{background:#F1EBFE;color:#6D28D9;}
    .pbi-stat-card.stat-gold .pbi-stat-icon-circle{background:#FDF3DE;color:#B87A08;}
    .pbi-stat-card .pbi-stat-trend.up{color:var(--pbi-green-dark);}

    /* ── COMPLETION RATE DONUT ── */
    .pbi-completion-card{display:flex;align-items:center;gap:16px;}
    .pbi-completion-ring{--pct:0;width:64px;height:64px;border-radius:50%;flex-shrink:0;
        background:conic-gradient(#EAB308 calc(var(--pct)*1%), var(--track-bg) 0);
        display:flex;align-items:center;justify-content:center;}
    .pbi-completion-ring::before{content:'';position:absolute;}
    .pbi-completion-ring-inner{width:48px;height:48px;border-radius:50%;background:var(--card-bg);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:var(--text-dark);}
    .pbi-completion-text .pbi-stat-label{margin-bottom:4px;}
    .pbi-completion-text .pbi-stat-value{font-size:26px;}

    /* ── EVALUATION PERIOD (redesigned) ── */
    .pbi-period-badge{display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:700;padding:3px 10px;border-radius:20px;text-transform:uppercase;letter-spacing:.4px;margin-left:10px;vertical-align:middle;}
    .pbi-period-badge.green{background:#E4F7E4;color:#1E8E1E;border:1px solid #BFE3BD;}
    .pbi-period-badge.red{background:#FBEAE6;color:#C2542F;border:1px solid #F0C7B9;}
    .pbi-period-badge.yellow{background:#FDF3DE;color:#B87A08;border:1px solid #F3DDA1;}
    .pbi-period-badge.gray{background:#EEF1F6;color:var(--text-dim);border:1px solid var(--card-border);}
    .pbi-period-meta{font-size:12px;color:var(--text-dim);margin-top:6px;}
    .pbi-period-meta i{margin-right:4px;}
    .pbi-period-meta .sep{margin:0 8px;opacity:.5;}
    .pbi-period-tiles{display:grid;grid-template-columns:1.4fr 1fr 1fr 1fr;gap:14px;margin-top:16px;}
    .pbi-period-tile{border-radius:10px;padding:14px 16px;}
    .pbi-period-tile.tile-progress{background:#FBF7EC;border:1px solid var(--card-border);}
    .pbi-period-tile.tile-submitted{background:var(--pbi-green-bg);}
    .pbi-period-tile.tile-pending{background:#FDF3DE;}
    .pbi-period-tile.tile-total{background:#EEF1F6;}
    .pbi-period-tile-label{font-size:11px;color:var(--text-dim);margin-bottom:6px;}
    .pbi-period-tile-value{font-size:26px;font-weight:700;color:var(--text-dark);line-height:1;}
    .pbi-period-tile-value.green{color:var(--pbi-green-dark);}
    .pbi-period-tile-value.gold{color:#B87A08;}
    .pbi-period-tile-sub{font-size:11px;color:var(--text-dim);margin-top:4px;}

    /* ── BOTTOM 3-COLUMN ROW ── */
    .pbi-bottom-row{display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;}
    .pbi-panel{background:var(--card-bg);border:1px solid var(--card-border);border-radius:12px;padding:18px 20px;box-shadow:var(--card-shadow);}
    .pbi-panel-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
    .pbi-panel-title{font-size:11px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--text-dim);}
    .pbi-panel-viewall{font-size:12px;font-weight:600;color:var(--pbi-green-dark);background:transparent;border:1px solid var(--card-border);border-radius:7px;padding:5px 11px;cursor:pointer;}
    .pbi-panel-viewall:hover{background:var(--pbi-green-bg);}

    /* Personnel Overview list */
    .pbi-personnel-item{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--card-border);}
    .pbi-personnel-item:last-child{border-bottom:none;padding-bottom:0;}
    .pbi-personnel-icon{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;}
    .pbi-personnel-icon.teal{background:var(--pbi-green-bg);color:var(--pbi-green-dark);}
    .pbi-personnel-icon.skyblue{background:#FBF1D6;color:#C9A227;}
    .pbi-personnel-icon.violet{background:#F1EBFE;color:#6D28D9;}
    .pbi-personnel-name{font-size:13px;font-weight:700;color:var(--text-dark);}
    .pbi-personnel-count{font-size:18px;font-weight:700;color:var(--text-dark);margin-left:auto;}
    .pbi-personnel-meta{font-size:11px;color:var(--text-dim);}

    /* Needs Attention list */
    .pbi-attention-item{display:flex;align-items:flex-start;gap:10px;padding:9px 0;border-bottom:1px solid var(--card-border);font-size:12.5px;color:var(--text-dark);}
    .pbi-attention-item:last-child{border-bottom:none;padding-bottom:0;}
    .pbi-attention-item.is-clickable{cursor:pointer;border-radius:8px;margin:0 -8px;padding:9px 8px;transition:background .15s;}
    .pbi-attention-item.is-clickable:last-child{margin-bottom:-9px;}
    .pbi-attention-item.is-clickable:hover{background:var(--sidebar-hover-bg,#F1F5FF);}
    .pbi-attention-item.is-clickable:hover .chev{opacity:1;color:var(--blue-accent);transform:translateX(2px);}
    .pbi-attention-item .chev{transition:transform .15s,opacity .15s,color .15s;}
    .pbi-attention-item i.dot{margin-top:2px;font-size:13px;flex-shrink:0;}
    .pbi-attention-item .warn{color:#C2542F;}
    .pbi-attention-item .info{color:#C9A227;}
    .pbi-attention-item .ok{color:var(--pbi-green-dark);}
    .pbi-attention-item .chev{margin-left:auto;color:var(--text-dim);opacity:.6;}

    /* Quick Access */
    .pbi-quickaccess-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
    .pbi-quick-btn{display:flex;align-items:center;gap:10px;padding:13px 14px;border-radius:10px;border:1px solid var(--card-border);background:var(--card-bg);cursor:pointer;font-size:12.5px;font-weight:700;color:var(--text-dark);text-align:left;transition:transform .15s ease,box-shadow .15s ease;}
    .pbi-quick-btn:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(15,23,42,.08);}
    .pbi-quick-btn i{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
    .pbi-quick-btn.qa-teal i{background:var(--pbi-green-bg);color:var(--pbi-green-dark);}
    .pbi-quick-btn.qa-skyblue i{background:#FBF1D6;color:#C9A227;}
    .pbi-quick-btn.qa-violet i{background:#F1EBFE;color:#6D28D9;}
    .pbi-quick-btn.qa-orange i{background:#FDF0DF;color:#C77A08;}
    .pbi-quick-btn.qa-gray i{background:#EEF1F6;color:var(--text-dim);}

    @media (max-width:1100px){
        .pbi-period-tiles{grid-template-columns:1fr 1fr;}
        .pbi-bottom-row{grid-template-columns:1fr;}
    }
    .pbi-cards-row{display:flex;gap:20px;margin-bottom:30px;}
    .pbi-card{background:var(--card-bg);border:1px solid var(--card-border);border-radius:12px;padding:22px;min-width:200px;box-shadow:var(--card-shadow);flex:1;}
    .pbi-card-title{font-size:13px;font-weight:600;color:var(--text-dim);margin-bottom:10px;text-transform:uppercase;letter-spacing:.5px;}
    .pbi-card-value{font-size:32px;font-weight:700;color:var(--text-dark);}
    .pbi-dashboard-grid{display:grid;grid-template-columns:1fr;gap:25px;}
    .pbi-section-box{border:1px solid var(--card-border);padding:20px;border-radius:12px;background:var(--card-bg);box-shadow:var(--card-shadow);}
    .pbi-section-heading{font-size:18px;font-weight:600;margin:0;color:var(--text-dark);}
    .pbi-section-head-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
    .pbi-view-all-link{font-size:12px;font-weight:700;color:#C9A227;}
    .pbi-view-all-link:hover{color:#C9A227;text-decoration:underline;}

    /* ── SYSTEM LOGS (flat table, matches the simple log view) ── */
    .logs-table-wrap{overflow-x:auto;}
    .logs-table{width:100%;border-collapse:collapse;font-size:12.5px;}
    .logs-table thead th{
        text-align:left;font-size:10.5px;font-weight:700;color:var(--text-dim);
        text-transform:uppercase;letter-spacing:.6px;padding:0 10px 10px;
        border-bottom:1px solid var(--card-border);white-space:nowrap;
    }
    .logs-table tbody td{padding:11px 10px;border-bottom:1px solid var(--card-border);vertical-align:top;color:var(--text-dark);}
    .logs-table tbody tr:last-child td{border-bottom:none;}
    .logs-table tbody tr:hover td{background:var(--page-bg);}
    .log-datetime{color:var(--text-dim);white-space:nowrap;}
    .log-action{display:inline-flex;align-items:center;gap:6px;font-weight:600;white-space:nowrap;}
    .log-action-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
    .log-user{font-weight:600;white-space:nowrap;}
    .log-details{color:var(--text-dim);}
    .logs-empty{padding:24px;text-align:center;color:var(--text-dim);font-size:13px;}
    .logs-error{color:#b91c1c;}
    .logs-retry{margin-left:8px;padding:5px 10px;border:1px solid currentColor;border-radius:6px;background:transparent;color:inherit;font-weight:600;cursor:pointer;}

    /* ── QUICK ACTIONS (simplified: icon + title + sub, matches the picture) ── */
    .pbi-actions-stack{display:flex;flex-direction:column;gap:10px;}
    .pbi-action-btn{background:var(--card-bg);color:var(--text-dark);border:1px solid var(--card-border);padding:13px 14px;font-size:14px;font-weight:600;text-align:left;border-radius:10px;cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:12px;width:100%;font-family:'Inter',sans-serif;}
    .pbi-action-btn:hover{background:var(--page-bg);border-color:var(--blue-accent);transform:translateY(-1px);}
    .pbi-action-icon{width:36px;height:36px;border-radius:9px;background:#FBF1D6;color:#C9A227;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
    .pbi-action-text{display:flex;flex-direction:column;gap:1px;}
    .pbi-action-title{font-size:13.5px;font-weight:700;color:var(--text-dark);}
    .pbi-action-sub{font-size:11.5px;font-weight:500;color:var(--text-dim);}
    .pbi-action-btn .fa-chevron-right{margin-left:auto;color:var(--text-dim);font-size:12px;}
    .pbi-actions-stack>*:nth-child(1) .pbi-action-icon{background:#FBF1D6;color:#C9A227;}
    .pbi-actions-stack>*:nth-child(2) .pbi-action-icon{background:#E8F8F1;color:#0F9F6E;}
    .pbi-actions-stack>*:nth-child(3) .pbi-action-icon{background:#F1EBFE;color:#4968C8;}
    .pbi-actions-stack>*:nth-child(4) .pbi-action-icon{background:#FDF0DF;color:#C77A08;}
    .pbi-dropdown-container{position:relative;width:100%;}
    .pbi-dropdown-menu{display:none;background:var(--card-bg);border:1px solid var(--card-border);border-radius:6px;margin-top:5px;width:100%;box-sizing:border-box;z-index:10;position:absolute;box-shadow:0 8px 24px rgba(30,82,144,.13);}
    .pbi-dropdown-menu.show{display:block;}
    .pbi-dropdown-item{display:flex;align-items:center;gap:10px;padding:12px 20px;color:var(--text-dark);font-size:14px;font-weight:600;border-bottom:1px solid var(--card-border);}
    .pbi-dropdown-item:last-child{border-bottom:none;}
    .pbi-dropdown-item:hover{background:var(--page-bg);color:#5B9BFA;}

    /* ── RESPONSIVE ── */
    @media(max-width:900px){
        :root{--sidebar-w:74px;}
        .sidebar-profile-name,.sidebar-profile-role,.pbi-sidebar .nav-item span,.sidebar-status,.sidebar-help-text,.sidebar-help-sub{display:none;}
        .sidebar-profile{padding:0 0 16px;}
        .sidebar-profile-logo{width:44px;height:44px;font-size:19px;margin-bottom:0;padding:0;}
        .pbi-sidebar .nav-item{justify-content:center;padding:11px;}
        .sidebar-help{justify-content:center;padding:11px;}
        .nav-item{padding:9px 11px;}
        .page-content{padding:20px;}
        .iframe-box{height:600px;}
        .pbi-dashboard-grid{grid-template-columns:1fr;}
        .logs-table{font-size:11px;}
    }
    @media(max-width:600px){.logs-table thead th:nth-child(4),.logs-table tbody td:nth-child(4){display:none;}}
    @media(max-width:550px){.sf-row{grid-template-columns:1fr;}.notif-dropdown{right:8px;width:calc(100vw - 16px);}}
    
    /* ── FULL-PAGE DASHBOARD LAYOUT ── */
    .page-content{
        margin-top:var(--topbar-h);
        margin-left:calc(var(--sidebar-w) * var(--sidebar-scale));
        width:calc(100% - (var(--sidebar-w) * var(--sidebar-scale)));
        min-height:calc(100vh - var(--topbar-h));
        padding:0 24px 28px;
    }
    #dashboard.page.active{
        width:100%;
        min-height:calc(100vh - var(--topbar-h));
    }
    .pbi-dashboard-container{
        width:100%;
        max-width:none;
        min-height:calc(100vh - var(--topbar-h));
        padding:24px 0 32px;
    }
    .pbi-stats-row{
        display:grid;
        grid-template-columns:repeat(4,minmax(0,1fr));
        gap:18px;
        margin-bottom:20px;
    }
    .pbi-stat-card{
        min-width:0;
        width:100%;
    }
    .pbi-period-box{
        width:100%;
        margin-bottom:20px;
    }
    .pbi-sector-row{
        display:grid;
        grid-template-columns:repeat(3,minmax(0,1fr));
        gap:18px;
        margin-bottom:20px;
    }
    .pbi-sector-card{
        width:100%;
        min-width:0;
    }
    .pbi-dashboard-grid{
        width:100%;
        grid-template-columns:1fr;
        gap:18px;
    }
    .pbi-section-box{
        min-width:0;
        width:100%;
    }
    @media(max-width:1100px){
        .pbi-stats-row{grid-template-columns:repeat(2,minmax(0,1fr));}
        .pbi-sector-row{grid-template-columns:repeat(3,minmax(0,1fr));}
        .pbi-dashboard-grid{grid-template-columns:1fr;}
    }
    @media(max-width:700px){
        .page-content{padding:0 12px 20px;}
        .pbi-dashboard-container{padding:16px 0 24px;}
        .pbi-stats-row,.pbi-sector-row{grid-template-columns:1fr;}
        .pbi-period-top{gap:14px;}
        .pbi-progress-wrap{min-width:100%;}
    }


/* ── SHARP LIGHT ADMIN UI ── */
html { background:#F8FAFC; }
body {
  color:#0B1F3A !important;
  background:#F8FAFC !important;
  -webkit-font-smoothing:antialiased;
  text-rendering:optimizeLegibility;
}
h1,h2,h3,h4,h5,h6 { color:#0B1F3A; letter-spacing:-.01em; }
p, .subtitle, .description, .helper, .muted, small { color:#67819E; }
label, th { color:#294765; font-weight:600; }
td { color:#0B1F3A; }
input, select, textarea {
  color:#0B1F3A;
  background:#FFFFFF;
  border-color:#D9CBA0;
}
input::placeholder, textarea::placeholder { color:#91A6BE; }
.card, .panel, .section, .table-card, .content-card {
  border-color:#D9CBA0;
  box-shadow:0 4px 14px rgba(30,82,144,.09);
}
button, .btn { font-weight:700; }
a { color:inherit; }

/* ── FINAL DASHBOARD COMPACT VIEW ──
   Keep the overview visible in one desktop viewport while preserving
   the Aura/light visual treatment. The workspace heading is intentionally
   hidden so the KPI cards can start higher on the page.
*/
.pbi-dashboard-heading{display:none!important;}
.page-content{padding:8px 18px 12px!important;}
.pbi-dashboard-container{padding:8px 0 12px!important;max-width:1500px!important;}
.pbi-stats-row{gap:10px!important;margin-bottom:10px!important;}
.pbi-stat-card{padding:10px 12px!important;min-height:0!important;border-radius:11px!important;}
.pbi-stat-card .pbi-stat-icon-circle{width:30px!important;height:30px!important;font-size:12px!important;margin-bottom:7px!important;border-radius:8px!important;}
.pbi-stat-label{font-size:9px!important;margin-bottom:3px!important;line-height:1.2!important;}
.pbi-stat-value{font-size:21px!important;line-height:1.05!important;margin-bottom:3px!important;}
.pbi-stat-sub{font-size:9.5px!important;line-height:1.2!important;}
.pbi-stat-trend{font-size:8.5px!important;margin-top:4px!important;line-height:1.2!important;}
.pbi-completion-card{display:flex!important;align-items:center!important;gap:10px!important;}
.pbi-completion-ring{transform:scale(1.05)!important;transform-origin:left center!important;}
.pbi-period-box{padding:11px 13px!important;margin-bottom:10px!important;border-radius:11px!important;}
.pbi-period-top{margin-bottom:7px!important;gap:8px 16px!important;}
.pbi-period-label{font-size:9px!important;line-height:1.2!important;}
.pbi-period-value{font-size:15px!important;line-height:1.15!important;}
.pbi-period-meta{font-size:9.5px!important;margin-top:3px!important;line-height:1.2!important;}
.pbi-period-tiles{gap:7px!important;margin-top:8px!important;}
.pbi-period-tile{padding:8px 10px!important;border-radius:9px!important;}
.pbi-period-tile-label{font-size:9px!important;margin-bottom:3px!important;}
.pbi-period-tile-value{font-size:16px!important;line-height:1.1!important;}
.pbi-period-tile-sub{font-size:9px!important;margin-top:2px!important;line-height:1.15!important;}
.pbi-progress-track{height:6px!important;margin-top:5px!important;}
.pbi-progress-note{font-size:9px!important;margin-top:3px!important;}
.pbi-bottom-row{gap:10px!important;}
.pbi-panel{padding:10px 12px!important;border-radius:11px!important;}
.pbi-panel-head{margin-bottom:6px!important;}
.pbi-panel-title{font-size:9.5px!important;}
.pbi-panel-viewall{font-size:9.5px!important;padding:3px 7px!important;}
.pbi-personnel-item{padding:6px 0!important;gap:7px!important;}
.pbi-personnel-icon{width:28px!important;height:28px!important;font-size:12px!important;}
.pbi-personnel-name{font-size:11px!important;line-height:1.1!important;}
.pbi-personnel-meta{font-size:8.8px!important;line-height:1.15!important;}
.pbi-personnel-count{font-size:15px!important;}
.pbi-attention-item{padding:6px 0!important;font-size:10.5px!important;line-height:1.25!important;}
.pbi-quickaccess-grid{gap:6px!important;}
.pbi-quick-btn{min-height:40px!important;padding:7px 8px!important;border-radius:8px!important;gap:7px!important;}
.pbi-quick-btn i{width:26px!important;height:26px!important;border-radius:7px!important;font-size:11px!important;}
.pbi-quick-btn .qa-title{font-size:10.5px!important;}
.pbi-quick-btn .qa-sub{font-size:8.5px!important;}
@media(max-width:900px){
  .page-content{padding:10px 12px 16px!important;}
  .pbi-dashboard-container{padding:8px 0 16px!important;}
}

</style>
    <link rel="stylesheet" href="admin_ui_theme.css">

    <link rel="stylesheet" href="admin_aura_theme.css">

<style id="friendly-dashboard-overrides">
/* ------------------------------------------------------------------
   Friendly dashboard refresh
   Presentation-only: keeps existing IDs, PHP values and JS actions.
   ------------------------------------------------------------------ */
:root{
    --fd-bg:#f5f7fb;
    --fd-surface:#ffffff;
    --fd-border:#e5eaf1;
    --fd-text:#162033;
    --fd-muted:#667085;
    --fd-primary:#1f6feb;
    --fd-primary-soft:#edf5ff;
    --fd-success:#169c72;
    --fd-success-soft:#eaf8f3;
    --fd-warning:#c47b13;
    --fd-warning-soft:#fff6e7;
}
body{background:var(--dashboard-bg)!important;background-attachment:fixed!important;color:var(--fd-text);}
.page-content{padding:24px 28px 34px!important;}
.pbi-dashboard-container{max-width:1440px;margin:0 auto!important;}

/* Clean, single-purpose page heading */
.pbi-dashboard-heading{
    margin:0 0 18px!important;
    padding:4px 2px 0;
}
.pbi-dashboard-greeting{display:none!important;}
.pbi-system-title{
    font-size:28px!important;
    line-height:1.18!important;
    letter-spacing:-.5px!important;
    color:var(--fd-text)!important;
    margin:0 0 6px!important;
}
.pbi-system-subtitle{
    font-size:13px!important;
    color:var(--fd-muted)!important;
    margin:0!important;
}
.pbi-system-subtitle + .pbi-system-subtitle{display:none!important;}

/* KPI cards */
.pbi-stats-row{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px!important;margin:0 0 18px!important;}
.pbi-stat-card{
    min-width:0!important;
    border:1px solid var(--fd-border)!important;
    border-top:0!important;
    border-radius:14px!important;
    padding:18px!important;
    background:var(--fd-surface)!important;
    box-shadow:0 3px 14px rgba(16,24,40,.05)!important;
    transition:transform .18s ease,box-shadow .18s ease!important;
}
.pbi-stat-card:hover{transform:translateY(-2px)!important;box-shadow:0 10px 24px rgba(16,24,40,.08)!important;}
.pbi-stat-icon-circle{width:40px!important;height:40px!important;border-radius:11px!important;font-size:15px!important;margin-bottom:13px!important;}
.pbi-stat-label{font-size:10.5px!important;letter-spacing:.5px!important;color:#718096!important;}
.pbi-stat-value{font-size:29px!important;color:var(--fd-text)!important;}
.pbi-stat-sub{font-size:11.5px!important;color:var(--fd-muted)!important;}
.pbi-stat-trend{margin-top:7px!important;font-size:10.5px!important;}

/* Current period becomes the dashboard's primary orientation block */
.pbi-period-box{
    margin:0 0 18px!important;
    padding:19px 20px!important;
    border:1px solid var(--fd-border)!important;
    border-radius:14px!important;
    background:var(--fd-surface)!important;
    box-shadow:0 3px 14px rgba(16,24,40,.05)!important;
}
.pbi-period-top{margin-bottom:13px!important;}
.pbi-period-label{font-size:10.5px!important;letter-spacing:.55px!important;color:#7b8798!important;}
.pbi-period-value{font-size:18px!important;color:var(--fd-text)!important;}
.pbi-period-meta{font-size:11.5px!important;color:var(--fd-muted)!important;}
.pbi-period-tiles{gap:10px!important;}
.pbi-period-tile{border-radius:11px!important;border:1px solid var(--fd-border)!important;background:#fbfcfe!important;}
.pbi-period-tile-value{font-size:19px!important;}
.pbi-progress-track{height:9px!important;border-radius:999px!important;background:#edf1f6!important;}
.pbi-progress-fill{border-radius:999px!important;}

/* Two-column working area, with clearer card hierarchy */
.pbi-bottom-row{display:grid!important;grid-template-columns:1.05fr 1.15fr!important;gap:14px!important;margin:0!important;}
.pbi-panel{
    min-width:0!important;
    padding:17px!important;
    border:1px solid var(--fd-border)!important;
    border-radius:14px!important;
    background:var(--fd-surface)!important;
    box-shadow:0 3px 14px rgba(16,24,40,.05)!important;
}
.pbi-panel-head{margin-bottom:12px!important;}
.pbi-panel-title{font-size:11px!important;letter-spacing:.55px!important;color:#596579!important;}
.pbi-panel-viewall{font-size:11px!important;padding:5px 10px!important;}
.pbi-personnel-item{padding:11px 0!important;}
.pbi-personnel-icon{width:36px!important;height:36px!important;}
.pbi-personnel-name{font-size:12.5px!important;}
.pbi-personnel-meta{font-size:10.5px!important;}
.pbi-personnel-count{font-size:17px!important;}
.pbi-attention-item{padding:10px 0!important;font-size:12px!important;line-height:1.4!important;}

/* Action launcher: use plain language and obvious click targets */
.pbi-quickaccess-grid{grid-template-columns:1fr!important;gap:9px!important;}
.pbi-quick-btn{
    width:100%!important;
    min-height:54px!important;
    padding:10px 11px!important;
    border-radius:11px!important;
    border:1px solid var(--fd-border)!important;
    background:#fff!important;
    font-size:12px!important;
    font-weight:700!important;
}
.pbi-quick-btn:hover{transform:translateX(2px)!important;box-shadow:0 5px 14px rgba(16,24,40,.07)!important;background:#fbfdff!important;}
.pbi-quick-btn i{width:34px!important;height:34px!important;border-radius:9px!important;}
.pbi-quick-btn .qa-copy{display:flex;flex-direction:column;align-items:flex-start;gap:2px;line-height:1.15;}
.pbi-quick-btn .qa-title{font-size:12px;font-weight:750;color:var(--fd-text);}
.pbi-quick-btn .qa-sub{font-size:10px;font-weight:500;color:var(--fd-muted);}

/* Improve mobile behavior */
@media(max-width:1180px){
    .pbi-stats-row{grid-template-columns:repeat(2,minmax(0,1fr))!important;}
    .pbi-bottom-row{grid-template-columns:1fr 1fr!important;}
}
@media(max-width:760px){
    .page-content{padding:18px 14px 24px!important;}
    .pbi-system-title{font-size:23px!important;}
    .pbi-stats-row{grid-template-columns:1fr 1fr!important;gap:10px!important;}
    .pbi-bottom-row{grid-template-columns:1fr!important;}
    .pbi-bottom-row .pbi-panel:last-child{grid-column:auto;}
    .pbi-period-top{display:block!important;}
    .pbi-period-tiles{grid-template-columns:1fr 1fr!important;}
}
@media(max-width:500px){
    .pbi-stats-row{grid-template-columns:1fr!important;}
    .pbi-period-tiles{grid-template-columns:1fr!important;}
}
/* ── FINAL DASHBOARD OVERVIEW POLISH ── */
.pbi-dashboard-heading{display:flex!important;align-items:flex-end;justify-content:space-between;gap:24px;margin:0 0 16px!important;padding:0 2px!important;}
.pbi-dashboard-heading-main{min-width:0;}
.pbi-dashboard-kicker{display:flex!important;align-items:center;gap:7px;margin:0 0 5px!important;color:#C9A227!important;font-size:10px!important;font-weight:800!important;letter-spacing:.7px!important;text-transform:uppercase;}
.pbi-dashboard-kicker i{font-size:11px;}
.pbi-system-title{font-size:27px!important;line-height:1.15!important;margin:0 0 4px!important;color:var(--fd-text)!important;letter-spacing:-.4px!important;}
.pbi-system-subtitle{font-size:12.5px!important;color:var(--fd-muted)!important;margin:0!important;}
.pbi-dashboard-heading-meta{display:flex;flex-direction:column;align-items:flex-end;gap:5px;flex-shrink:0;padding-bottom:2px;}
.pbi-dashboard-period-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #dce6f2;border-radius:999px;background:#fff;color:#344054;font-size:10.5px;font-weight:700;white-space:nowrap;}
.pbi-dashboard-period-chip i{color:#C9A227;}
.pbi-dashboard-updated{font-size:10px;color:#8793a5;white-space:nowrap;}
.pbi-dashboard-container{background:#f5f7fb!important;border:1px solid #e3e9f2!important;border-radius:16px!important;padding:20px 22px 24px!important;box-sizing:border-box;}
.pbi-stats-row{margin-bottom:16px!important;}
.pbi-stat-card{box-shadow:0 2px 9px rgba(16,24,40,.045)!important;}
.pbi-period-box,.pbi-panel{box-shadow:0 2px 9px rgba(16,24,40,.045)!important;}
@media(max-width:760px){
  .pbi-dashboard-heading{align-items:flex-start;flex-direction:column;gap:10px;}
  .pbi-dashboard-heading-meta{align-items:flex-start;}
  .pbi-dashboard-container{padding:16px!important;border-radius:12px!important;}
}

/* ── COMPACT 100% DESKTOP SCALE ── */
:root{--sidebar-w:270px;--topbar-h:70px;}
.nude-nav{padding:10px 22px!important;min-height:var(--topbar-h)!important;}
.nav-brand{gap:10px!important;}
.brand-text{gap:2px!important;}
.brand-name{font-size:14px!important;max-width:190px!important;}
.brand-role{font-size:9.5px!important;padding:2px 7px!important;}
.nav-container{gap:8px!important;}
.nav-menu{gap:2px!important;}
.nav-item{font-size:12.5px!important;padding:7px 10px!important;gap:7px!important;}
.notif-btn{width:32px!important;height:32px!important;font-size:13px!important;}
.profile-trigger{min-height:32px!important;}
.pbi-sidebar{padding:24px 18px!important;}
.sidebar-profile{padding-bottom:20px!important;margin-bottom:14px!important;}
.sidebar-profile-logo{width:84px!important;height:84px!important;font-size:32px!important;margin-bottom:12px!important;padding:0!important;}
.sidebar-profile-name{font-size:17px!important;}
.sidebar-profile-role{font-size:12.5px!important;}
.sidebar-nav{gap:5px!important;}
.pbi-sidebar .nav-item{font-size:14.5px!important;padding:12px 14px!important;gap:12px!important;border-radius:10px!important;margin:0 5px!important;width:auto!important;justify-content:flex-start!important;}
.pbi-sidebar .nav-item .nav-icon-badge{width:38px!important;height:38px!important;}
.pbi-sidebar .nav-item .icon{font-size:16px!important;width:18px!important;}
.sidebar-status{padding-top:14px!important;}
.sidebar-status-card{padding:14px 15px!important;margin-bottom:10px!important;border-radius:11px!important;}
.sidebar-help{padding:12px 14px!important;border-radius:11px!important;}
.page-content{padding:18px 22px 24px!important;}
.pbi-dashboard-container{padding:18px 0 24px!important;max-width:1500px!important;}
.pbi-dashboard-heading{margin-bottom:14px!important;padding-top:0!important;}
.pbi-system-title{font-size:23px!important;margin-bottom:4px!important;}
.pbi-system-subtitle{font-size:12px!important;}
.pbi-stats-row{gap:16px!important;margin-bottom:20px!important;}
.pbi-stat-card{padding:20px 22px!important;border-radius:13px!important;}
.pbi-stat-card .pbi-stat-icon-circle{width:44px!important;height:44px!important;font-size:17px!important;margin-bottom:14px!important;border-radius:12px!important;}
.pbi-stat-label{font-size:11px!important;margin-bottom:7px!important;}
.pbi-stat-value{font-size:32px!important;margin-bottom:6px!important;}
.pbi-stat-sub{font-size:12px!important;}
.pbi-stat-trend{font-size:11px!important;margin-top:7px!important;}
.pbi-period-box{padding:20px 24px!important;margin-bottom:20px!important;border-radius:13px!important;}
.pbi-period-top{gap:14px 26px!important;margin-bottom:14px!important;}
.pbi-period-value{font-size:19px!important;gap:10px!important;}
.pbi-period-label{font-size:11px!important;}
.pbi-period-meta{font-size:12px!important;margin-top:6px!important;}
.pbi-period-tiles{gap:12px!important;margin-top:16px!important;}
.pbi-period-tile{padding:14px 16px!important;border-radius:11px!important;}
.pbi-period-tile-label{font-size:11px!important;margin-bottom:6px!important;}
.pbi-period-tile-value{font-size:21px!important;}
.pbi-period-tile-sub{font-size:11px!important;margin-top:3px!important;}
.pbi-progress-track{height:9px!important;}
.pbi-progress-note{font-size:11px!important;margin-top:6px!important;}
.pbi-bottom-row{gap:16px!important;}
.pbi-panel{padding:18px 20px!important;border-radius:13px!important;}
.pbi-panel-head{margin-bottom:13px!important;}
.pbi-panel-title{font-size:11.5px!important;}
.pbi-panel-viewall{font-size:11.5px!important;padding:6px 11px!important;}
.pbi-personnel-item{padding:11px 0!important;gap:12px!important;}
.pbi-personnel-icon{width:38px!important;height:38px!important;font-size:16px!important;}
.pbi-personnel-name{font-size:13px!important;}
.pbi-personnel-meta{font-size:11px!important;}
.pbi-personnel-count{font-size:18px!important;}
.pbi-attention-item{padding:11px 0!important;font-size:12.5px!important;}
.pbi-quickaccess-grid{gap:10px!important;}
.pbi-quick-btn{min-height:54px!important;padding:11px 13px!important;border-radius:11px!important;font-size:12.5px!important;gap:10px!important;}
.pbi-quick-btn i{width:34px!important;height:34px!important;border-radius:9px!important;font-size:14px!important;}
.pbi-quick-btn .qa-title{font-size:12.5px!important;}
.pbi-quick-btn .qa-sub{font-size:10.5px!important;}

/* ── TOPBAR: clean white navigation on the light workspace ── */
.nude-nav{
    background:#FFFFFF!important;
    border-bottom:none!important;
    box-shadow:none!important;
    backdrop-filter:none!important;
    -webkit-backdrop-filter:none!important;
    justify-content:flex-end!important;
}
.notif-btn{background:#F8FAFC!important;border:1px solid #E8DFC4!important;color:#0B1F3A!important;}
.notif-btn:hover,.notif-btn.has-unread{background:var(--accent-soft-bg)!important;border-color:var(--accent-soft-border)!important;color:var(--blue-accent)!important;}
.notif-badge{border-color:#FFFFFF!important;}
.nav-divider{background:#E8DFC4!important;}
.brand-name{color:#0B1F3A!important;}
.brand-role{background:var(--accent-soft-bg)!important;border-color:var(--accent-soft-border)!important;color:var(--blue-accent)!important;}
@media(max-width:1180px){
  :root{--sidebar-w:220px;}
  .pbi-stats-row{grid-template-columns:repeat(4,minmax(0,1fr))!important;}
  .pbi-bottom-row{grid-template-columns:1fr 1fr!important;}
}
@media(max-width:900px){
  :root{--sidebar-w:70px;}
  .pbi-sidebar{padding:16px 10px!important;}
  .page-content{padding:16px!important;}
}
@media(max-width:760px){
  .pbi-stats-row{grid-template-columns:repeat(2,minmax(0,1fr))!important;}
}
@media(max-width:500px){
  .pbi-stats-row{grid-template-columns:1fr!important;}
}
</style>

    <style id="final-kpi-compact-fix">
/* ── FINAL TOP KPI COMPACT VIEW ──
       Compress only the four dashboard KPI cards so the overview fits
       comfortably in one desktop viewport without changing their content.
    */
    .pbi-stats-row{
        gap:10px!important;
        margin-bottom:10px!important;
    }

    .pbi-stats-row > .pbi-stat-card{
        min-height:82px!important;
        height:82px!important;
        padding:9px 11px!important;
        border-radius:10px!important;
        overflow:hidden!important;
    }

    /* First three KPI cards: icon on the left, information stacked compactly. */
    .pbi-stats-row > .pbi-stat-card:not(.pbi-completion-card){
        display:grid!important;
        grid-template-columns:30px minmax(0,1fr)!important;
        grid-template-rows:auto auto auto!important;
        column-gap:9px!important;
        align-content:center!important;
        align-items:center!important;
    }

    .pbi-stats-row > .pbi-stat-card:not(.pbi-completion-card) .pbi-stat-icon-circle{
        grid-column:1;
        grid-row:1 / 4;
        width:28px!important;
        height:28px!important;
        margin:0!important;
        border-radius:8px!important;
        font-size:12px!important;
    }

    .pbi-stats-row > .pbi-stat-card:not(.pbi-completion-card) .pbi-stat-label{
        grid-column:2;
        grid-row:1;
        font-size:8.5px!important;
        line-height:1.1!important;
        margin:0!important;
        letter-spacing:.45px!important;
    }

    .pbi-stats-row > .pbi-stat-card:not(.pbi-completion-card) .pbi-stat-value{
        grid-column:2;
        grid-row:2;
        font-size:21px!important;
        line-height:1!important;
        margin:2px 0!important;
    }

    .pbi-stats-row > .pbi-stat-card:not(.pbi-completion-card) .pbi-stat-sub{
        grid-column:2;
        grid-row:3;
        font-size:8.5px!important;
        line-height:1.1!important;
        margin:0!important;
        white-space:nowrap!important;
        overflow:hidden!important;
        text-overflow:ellipsis!important;
        display:inline-block!important;
        max-width:58%!important;
    }

    .pbi-stats-row > .pbi-stat-card:not(.pbi-completion-card) .pbi-stat-trend{
        grid-column:2;
        grid-row:3;
        justify-self:end!important;
        font-size:8px!important;
        line-height:1.1!important;
        margin:0!important;
        white-space:nowrap!important;
    }

    /* Completion card */
    .pbi-stats-row > .pbi-completion-card{
        gap:9px!important;
        display:flex!important;
        align-items:center!important;
        justify-content:flex-start!important;
    }

    .pbi-stats-row > .pbi-completion-card .pbi-completion-ring{
        width:50px!important;
        height:50px!important;
        transform:none!important;
    }

    .pbi-stats-row > .pbi-completion-card .pbi-completion-ring-inner{
        width:38px!important;
        height:38px!important;
        font-size:11px!important;
    }

    .pbi-stats-row > .pbi-completion-card .pbi-completion-text{
        min-width:0!important;
    }

    .pbi-stats-row > .pbi-completion-card .pbi-stat-label{
        font-size:8.5px!important;
        margin-bottom:2px!important;
        line-height:1.1!important;
    }

    .pbi-stats-row > .pbi-completion-card .pbi-stat-sub{
        font-size:8.5px!important;
        line-height:1.15!important;
        white-space:nowrap!important;
    }

    /* Give the period block a little breathing room while preserving its design. */
    .pbi-period-box{
        padding:10px 12px!important;
        margin-bottom:10px!important;
    }

    .pbi-period-tiles{
        gap:7px!important;
        margin-top:7px!important;
    }

    .pbi-period-tile{
        padding:7px 9px!important;
    }

    @media(max-width:900px){
        .pbi-stats-row > .pbi-stat-card{
            height:auto!important;
            min-height:82px!important;
        }
    }

    /* ── STATIC DESKTOP VIEW: NO SCROLLING ──
       The dashboard is compressed to fit the viewport, so both the page
       and the sidebar are locked — nothing scrolls on desktop.
       Mobile/tablet still scroll, since the dense layout won't fit a phone screen.
    */
    @media(min-width:901px){
        html, body{
            height:auto!important;
            min-height:100%!important;
            overflow-y:auto!important;
            overflow-x:hidden!important;
        }
        .pbi-sidebar{
            overflow:hidden!important;
        }
    }
    /* ── STRAIGHTENED SIDEBAR NAVIGATION ── */
<style id="pbi-sidebar-straightened">
.pbi-sidebar{box-sizing:border-box;}
.pbi-sidebar .sidebar-nav{width:100%;margin:0;padding:0;}
.pbi-sidebar .nav-menu{width:100%;margin:0;padding:0;display:flex;flex-direction:column;gap:5px;list-style:none;}
.pbi-sidebar .nav-menu li{width:100%;margin:0;padding:0;list-style:none;}
.pbi-sidebar .nav-menu .nav-section-label{
    width:auto;
    margin:8px 10px 2px;
    padding:0 2px;
    color:#B9D7F5;
    font-size:11px;
    font-weight:900;
    letter-spacing:.16em;
    line-height:1.25;
    text-align:center;
    text-transform:uppercase;
    text-shadow:0 1px 8px rgba(125,211,252,.12);
    list-style:none;
}
.pbi-sidebar .nav-menu .nav-section-label:first-child{margin-top:0;}
.pbi-sidebar .nav-item,
.pbi-sidebar .nav-item:focus,
.pbi-sidebar .nav-item:active{
    box-sizing:border-box;
    width:100%;
    min-height:42px;
    margin:0;
    padding:5px 12px;
    display:flex;
    align-items:center;
    justify-content:flex-start;
    gap:10px;
    border:0;
    outline:none;
    text-decoration:none;
    border-radius:11px;
    white-space:nowrap;
    transform:none;
}
.pbi-sidebar .nav-item:focus-visible{
    outline:2px solid rgba(240,202,90,.55);
    outline-offset:2px;
}
.pbi-sidebar .nav-item .nav-icon-badge{
    width:32px;
    height:32px;
    min-width:32px;
    min-height:32px;
    margin:0;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:50%;
}
.pbi-sidebar .nav-item .icon{
    width:16px;
    height:16px;
    display:flex;
    align-items:center;
    justify-content:center;
    margin:0;
    font-size:14px;
    line-height:1;
}
.pbi-sidebar .nav-item > span:last-child{
    display:flex;
    align-items:center;
    min-width:0;
    line-height:1.2;
    margin:0;
}
@media(max-width:900px){
    .pbi-sidebar .nav-menu .nav-section-label{margin-left:10px;margin-right:10px;}
}
.pbi-sidebar .nav-item:hover,
.pbi-sidebar .nav-item.active{transform:none;}
</style>
<style id="pbi-dashboard-hidden-scrollbar">
/* PBI DASHBOARD HIDDEN OUTER SCROLLBAR — dashboard/navigation stay clean */
html, body { scrollbar-width:none !important; -ms-overflow-style:none !important; }
html::-webkit-scrollbar, body::-webkit-scrollbar { display:none !important; width:0 !important; height:0 !important; }
.pbi-sidebar { scrollbar-width:none !important; -ms-overflow-style:none !important; }
.pbi-sidebar::-webkit-scrollbar { display:none !important; width:0 !important; }
.nav-menu { scrollbar-width:none !important; -ms-overflow-style:none !important; }
.nav-menu::-webkit-scrollbar { display:none !important; width:0 !important; height:0 !important; }
</style>

<style id="sidebar-navigation-balanced-spacing">
/* BALANCED SIDEBAR NAV: slightly relaxed from the ultra-compact version, while remaining tighter than the original. */
@media(min-width:901px){
  .pbi-sidebar .sidebar-nav{gap:4px!important;}
  .pbi-sidebar .nav-menu{gap:4px!important;}
  .pbi-sidebar .nav-menu .nav-section-label{
      margin:8px 10px 3px!important;
      padding:0 2px!important;
      color:#B9D7F5!important;
      font-size:11px!important;
      font-weight:900!important;
      letter-spacing:.16em!important;
      line-height:1.25!important;
      text-align:center!important;
      text-shadow:0 1px 8px rgba(125,211,252,.12)!important;
  }
  .pbi-sidebar .nav-menu .nav-section-label:first-child{margin-top:0!important;}
  .pbi-sidebar .nav-item,
  .pbi-sidebar .nav-item:focus,
  .pbi-sidebar .nav-item:active{
      min-height:42px!important;
      height:42px!important;
      padding:4px 12px!important;
      gap:9px!important;
      border-radius:10px!important;
  }
  .pbi-sidebar .nav-item .nav-icon-badge{
      width:32px!important;
      height:32px!important;
      min-width:32px!important;
      min-height:32px!important;
  }
  .pbi-sidebar .nav-item .icon{
      width:15px!important;
      height:15px!important;
      font-size:13px!important;
  }
}
</style>

<link rel="stylesheet" href="admin_appearance.css">
<script src="admin_appearance.js"></script>
</head>
<body>

<!-- ═══ SIDEBAR ══════════════════════════════════════════════════════════ -->
<aside class="pbi-sidebar">
    <div class="sidebar-profile">
        <div class="sidebar-profile-logo" title="<?= htmlspecialchars($admin_fullname, ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($photo_src): ?>
                <img src="<?= htmlspecialchars($photo_src, ENT_QUOTES, 'UTF-8') ?>"
                     alt="<?= htmlspecialchars($admin_fullname, ENT_QUOTES, 'UTF-8') ?>"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"/>
                <span class="sidebar-profile-initials" style="display:none;"><?= htmlspecialchars($initials) ?></span>
            <?php else: ?>
                <span class="sidebar-profile-initials"><?= htmlspecialchars($initials) ?></span>
            <?php endif; ?>
        </div>
        <div class="sidebar-profile-name"><?= htmlspecialchars($admin_fullname) ?></div>
        <div class="sidebar-profile-role"><?= htmlspecialchars(strtoupper(display_role($_SESSION['role'] ?? ''))) ?></div>
    </div>

    <nav class="sidebar-nav">
        <ul class="nav-menu">
            <li class="nav-section-label">MAIN</li>
            <li><a href="#" id="link-dashboard" onclick="showPage('dashboard',this);return false;" class="nav-item"><span class="nav-icon-badge"><i class="fa-solid fa-house icon"></i></span> <span>Dashboard</span></a></li>
            <li><a href="#" id="link-reports"   onclick="showPage('reports',this);return false;"   class="nav-item"><span class="nav-icon-badge"><i class="fa-solid fa-file-signature icon"></i></span> <span>Questionnaire</span></a></li>
            <li><a href="#" id="link-tracker" onclick="showPage('tracker',this);return false;" class="nav-item"><span class="nav-icon-badge"><i class="fa-solid fa-satellite-dish icon"></i></span> <span>Evaluation Tracker</span></a></li>
            <li><a href="#" id="link-ea-eval" onclick="showPage('ea_eval',this);return false;" class="nav-item"><span class="nav-icon-badge"><i class="fa-solid fa-clipboard-check icon"></i></span> <span><?= $isExecutiveAssistant ? 'Evaluate Others' : 'EA Evaluations' ?></span></a></li>
            <?php if ($isExecutiveAssistant): ?>
            <li><a href="#" id="link-ea-results" onclick="showPage('ea_results',this);return false;" class="nav-item"><span class="nav-icon-badge"><i class="fa-solid fa-star-half-stroke icon"></i></span> <span>Evaluation Received</span></a></li>
            <?php endif; ?>
            <li><a href="#" id="link-analytics" onclick="showPage('analytics',this);return false;" class="nav-item"><span class="nav-icon-badge"><i class="fa-solid fa-chart-line icon"></i></span> <span>Evaluation Reports</span></a></li>

            <li class="nav-section-label">ADMINISTRATION</li>
            <?php if (($_SESSION['role'] ?? '') === 'superadmin'): ?>
            <li><a href="#" id="link-registrations" onclick="showPage('registrations',this);return false;" class="nav-item"><span class="nav-icon-badge"><i class="fa-solid fa-users-gear icon"></i></span> <span>Account Management</span></a></li>
            <?php endif; ?>
            <li><a href="#" id="link-system_logs" onclick="showPage('system_logs',this);return false;" class="nav-item"><span class="nav-icon-badge"><i class="fa-solid fa-clock-rotate-left icon"></i></span> <span>System Logs</span></a></li>
            <li><a href="#" id="link-settings" onclick="showPage('settings',this);return false;" class="nav-item"><span class="nav-icon-badge"><i class="fa-solid fa-gear icon"></i></span> <span>Settings</span></a></li>

            <li class="nav-section-label">ACCOUNT</li>
            <li><a href="../logout.php" id="link-logout" class="nav-item logout" onclick="return pbiConfirmLogout(this)"><span class="nav-icon-badge"><i class="fa-solid fa-power-off icon"></i></span> <span>Log Out</span></a></li>
        </ul>
    </nav>

</aside>

<!-- TOPBAR INTENTIONALLY EMPTY: the notification control now lives inside the dashboard header. -->
<nav class="nude-nav" aria-hidden="true" style="display:none;"></nav>

<!-- Custom confirm modal: replaces the native "localhost says" browser confirm() dialog -->
<div class="pbi-confirm-overlay" id="pbiConfirmOverlay">
    <div class="pbi-confirm-card">
        <div class="pbi-confirm-msg">Are you sure you want to log out?</div>
        <div class="pbi-confirm-actions">
            <button type="button" class="pbi-confirm-btn cancel" id="pbiConfirmCancel">Cancel</button>
            <button type="button" class="pbi-confirm-btn ok" id="pbiConfirmOk">OK</button>
        </div>
    </div>
</div>

<!-- Unsaved Settings changes: shown when leaving Settings via the sidebar -->
<div class="pbi-confirm-overlay" id="pbiUnsavedOverlay">
    <div class="pbi-confirm-card pbi-unsaved-card">
        <div class="pbi-unsaved-title"><i class="fa-solid fa-triangle-exclamation"></i> Unsaved changes</div>
        <div class="pbi-unsaved-sub">You edited <strong>System &amp; Period Settings</strong> but haven't saved yet. These changes are not live until you save:</div>
        <ul class="pbi-unsaved-list" id="pbiUnsavedList"></ul>
        <div class="pbi-unsaved-actions">
            <button type="button" class="pbi-confirm-btn cancel" id="pbiUnsavedStay">Keep editing</button>
            <button type="button" class="pbi-confirm-btn danger" id="pbiUnsavedDiscard">Discard changes</button>
            <button type="button" class="pbi-confirm-btn ok" id="pbiUnsavedSave">Save &amp; continue</button>
        </div>
    </div>
</div>

<!-- Notification dropdown — OUTSIDE nav, position:fixed -->
<div class="notif-dropdown" id="notifDropdown">
    <div class="notif-header">
        <span class="notif-header-title"><i class="fa-solid fa-bell" style="color:var(--blue-accent);"></i> Notifications</span>
        <button class="notif-mark-read" onclick="markAllRead()">Mark all read</button>
    </div>
    <div class="notif-list" id="notifList">
        <div class="notif-empty"><i class="fa-regular fa-bell-slash"></i>Loading…</div>
    </div>
    <div class="notif-footer">Live updates &nbsp;<span class="live-dot">LIVE</span></div>
</div>

<!-- ═══ SETTINGS MODAL ═══════════════════════════════════════════════════ -->
<div class="modal-overlay" id="settingsOverlay" onclick="closeSettings()">
<div class="settings-modal" onclick="event.stopPropagation()">
    <div class="sm-header">
        <div class="sm-title"><i class="fa-solid fa-gear" style="margin-right:8px;color:var(--blue-accent);"></i>Settings</div>
        <button class="sm-close" onclick="closeSettings()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="sm-tabs">
        <button class="sm-tab active" id="stab-profile"    onclick="switchTab('profile')"><i class="fa-solid fa-user" style="margin-right:4px;"></i>Profile</button>
        <button class="sm-tab"        id="stab-security"   onclick="switchTab('security')"><i class="fa-solid fa-lock" style="margin-right:4px;"></i>Security</button>
        <button class="sm-tab"        id="stab-system"     onclick="switchTab('system')"><i class="fa-solid fa-sliders" style="margin-right:4px;"></i>System &amp; Period</button>
        <button class="sm-tab"        id="stab-appearance" onclick="switchTab('appearance')"><i class="fa-solid fa-palette" style="margin-right:4px;"></i>Appearance</button>
        <button class="sm-tab"        id="stab-archive" onclick="switchTab('archive')"><i class="fa-solid fa-box-archive" style="margin-right:4px;"></i>System Archive</button>
    </div>

    <?php if ($sm_text): ?>
    <div style="padding:0 24px;margin-top:14px;">
        <div class="sf-alert <?= $sm_type==='ok'?'ok':'err' ?>">
            <i class="fa-solid <?= $sm_type==='ok'?'fa-circle-check':'fa-circle-exclamation' ?>"></i>
            <?= htmlspecialchars($sm_text) ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── PROFILE TAB ── -->
    <form method="POST" action="" enctype="multipart/form-data" id="formProfile">
    <input type="hidden" name="action" value="save_settings"/>
    <input type="hidden" name="tab"    value="profile"/>
    <div class="sm-section active sm-body" id="ssec-profile">
        <div class="sf-photo-row" onclick="document.getElementById('sfPhotoInput').click()">
            <div class="sf-photo-preview">
                <?php if ($photo_src): ?>
                    <img src="<?= htmlspecialchars($photo_src, ENT_QUOTES, 'UTF-8') ?>" alt="" id="sfPhotoImg"
                         onerror="this.style.display='none';document.getElementById('sfPhotoInitials')?.style.removeProperty('display');"/>
                <?php else: ?>
                    <span id="sfPhotoInitials"><?= $initials ?></span>
                    <img src="" alt="" id="sfPhotoImg" style="display:none;"/>
                <?php endif; ?>
            </div>
            <div class="sf-photo-info">
                <p>Profile Photo</p>
                <span>JPG, PNG, WebP · max 5 MB</span><br>
                <span class="sf-choose-btn" onclick="event.stopPropagation();document.getElementById('sfPhotoInput').click()">
                    <i class="fa-solid fa-camera"></i> Change Photo
                </span>
            </div>
            <input type="file" id="sfPhotoInput" name="settings_photo" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;" onchange="previewPhoto(this)"/>
        </div>
        <div class="sf-row">
            <div class="sf-group">
                <label class="sf-label">Full Name</label>
                <input class="sf-input" type="text" name="settings_fullname" value="<?= htmlspecialchars($admin_fullname) ?>" required/>
            </div>
            <div class="sf-group">
                <label class="sf-label">Username</label>
                <input class="sf-input" type="text" value="@<?= htmlspecialchars($admin_username) ?>" disabled/>
                <div class="sf-hint">Cannot be changed.</div>
            </div>
        </div>
        <div class="sf-group">
            <label class="sf-label">Email Address</label>
            <input class="sf-input" type="email" name="settings_email" value="<?= htmlspecialchars($admin_email) ?>"/>
        </div>
        <div class="sf-group">
            <label class="sf-label">Role</label>
            <input class="sf-input" type="text" value="<?= display_role($_SESSION['role']??'admin') ?>" disabled/>
            <div class="sf-hint">Role changes require a System Admin.</div>
        </div>
    </div>
    <div class="sm-footer" id="footer-profile">
        <button type="button" class="sf-btn sf-btn-cancel" onclick="closeSettings()">Cancel</button>
        <button type="submit" class="sf-btn sf-btn-save"><i class="fa-solid fa-floppy-disk" style="margin-right:5px;"></i>Save Profile</button>
    </div>
    </form>

    <!-- ── SECURITY TAB ── -->
    <form method="POST" action="" id="formSecurity">
    <input type="hidden" name="action" value="save_settings"/>
    <input type="hidden" name="tab"    value="security"/>
    <div class="sm-section sm-body" id="ssec-security">
        <div style="padding:11px 13px;background:rgba(201,162,39,.1);border:1px solid rgba(201,162,39,.25);border-radius:8px;margin-bottom:16px;font-size:13px;color:#C9A227;display:flex;gap:9px;">
            <i class="fa-solid fa-circle-info" style="margin-top:1px;flex-shrink:0;"></i>
            Leave blank to keep your current password.
        </div>
        <div class="sf-group">
            <label class="sf-label">New Password</label>
            <div style="position:relative;">
                <input class="sf-input" type="password" id="sfPw1" name="settings_password" placeholder="Min. 8 characters" style="padding-right:38px;" oninput="pwStrength(this.value)"/>
                <button type="button" onclick="togglePw('sfPw1','sfEye1')" style="position:absolute;right:11px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:13px;"><i class="fa-solid fa-eye" id="sfEye1"></i></button>
            </div>
            <div style="height:3px;border-radius:2px;margin-top:5px;background:rgba(255,255,255,.08);transition:all .3s;" id="sfPwBar"></div>
            <div style="font-size:11px;margin-top:3px;" id="sfPwHint"></div>
        </div>
        <div class="sf-group">
            <label class="sf-label">Confirm Password</label>
            <div style="position:relative;">
                <input class="sf-input" type="password" id="sfPw2" name="settings_confirm_pw" placeholder="Repeat new password" style="padding-right:38px;"/>
                <button type="button" onclick="togglePw('sfPw2','sfEye2')" style="position:absolute;right:11px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:13px;"><i class="fa-solid fa-eye" id="sfEye2"></i></button>
            </div>
        </div>
    </div>
    <div class="sm-footer" id="footer-security" style="display:none;">
        <button type="button" class="sf-btn sf-btn-cancel" onclick="closeSettings()">Cancel</button>
        <button type="submit" class="sf-btn sf-btn-save"><i class="fa-solid fa-shield-halved" style="margin-right:5px;"></i>Change Password</button>
    </div>
    </form>

    <!-- ── SYSTEM & PERIOD TAB ── -->
    <form method="POST" action="" id="formSystem">
    <input type="hidden" name="action" value="save_settings"/>
    <input type="hidden" name="tab"    value="system"/>
    <div class="sm-section sm-body" id="ssec-system">

        <!-- Current Configuration — a read-at-a-glance summary so an admin
             doesn't have to open every card below just to answer "what's the
             system doing right now?" -->
        <div class="cfg-summary">
            <div class="cfg-headline-row">
                <span class="cfg-headline-badge period-status <?= $evalHealth['cls'] ?>"><?= htmlspecialchars($evalHealth['label']) ?></span>
                <div class="cfg-headline-text">
                    <div class="cfg-headline"><?= htmlspecialchars($evalHealth['headline']) ?></div>
                    <div class="cfg-sub"><?= htmlspecialchars($evalHealth['sub']) ?></div>
                </div>
            </div>

            <div class="cfg-grid">
                <div class="cfg-item"><span class="cfg-label">Academic Year</span><span class="cfg-value"><?= htmlspecialchars($sys['acad_year']) ?></span></div>
                <div class="cfg-item"><span class="cfg-label">Structure</span><span class="cfg-value"><?= htmlspecialchars($STRUCTURE_LABELS[$sys['acad_structure']] ?? 'College') ?></span></div>
                <div class="cfg-item"><span class="cfg-label">Current Term</span><span class="cfg-value"><?= htmlspecialchars($sys['acad_term']) ?></span></div>
                <div class="cfg-item"><span class="cfg-label">Submission Window</span><span class="cfg-value">
                    <?= $sys['eval_start'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($sys['eval_start']))) : '—' ?>
                    → <?= $sys['eval_end'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($sys['eval_end']))) : '—' ?>
                </span></div>
                <div class="cfg-item"><span class="cfg-label">Submission Mode</span><span class="cfg-value"><?= htmlspecialchars($controlModeLabel) ?></span></div>
                <div class="cfg-item"><span class="cfg-label">Student Editing</span><span class="cfg-value"><?= !empty($sys['rule_edit_after_submit']) ? 'Enabled' : 'Disabled' ?></span></div>
                <div class="cfg-item"><span class="cfg-label">Current Rule Set</span><span class="cfg-value"><?= $activeRuleCount ?> / <?= $totalRuleCount ?> Active Rules</span></div>
            </div>

            <div class="cfg-metric-row">
                <div class="cfg-metric"><div class="cfg-metric-value"><?= $evalHealth['duration_days'] !== null ? $evalHealth['duration_days'] : '—' ?></div><div class="cfg-metric-label">Duration (days)</div></div>
                <div class="cfg-metric"><div class="cfg-metric-value"><?php
                    if ($evalHealth['remaining_days'] !== null) echo $evalHealth['remaining_days'];
                    elseif ($evalHealth['days_until_start'] !== null) echo $evalHealth['days_until_start'];
                    elseif ($evalHealth['elapsed_days'] !== null) echo $evalHealth['elapsed_days'];
                    else echo '—';
                ?></div><div class="cfg-metric-label"><?php
                    if ($evalHealth['remaining_days'] !== null) echo 'Remaining';
                    elseif ($evalHealth['days_until_start'] !== null) echo 'Until Start';
                    elseif ($evalHealth['elapsed_days'] !== null) echo 'Days Ago (Ended)';
                    else echo 'Remaining';
                ?></div></div>
                <div class="cfg-metric"><div class="cfg-metric-value"><?= number_format($studentCount) ?></div><div class="cfg-metric-label">Participants</div></div>
                <div class="cfg-metric"><div class="cfg-metric-value"><?= number_format($submittedCount) ?></div><div class="cfg-metric-label">Completed</div></div>
            </div>
        </div>

        <!-- System Health — a quick pre-flight checklist covering the parts of
             the system that determine whether students can evaluate at all. -->
        <div class="period-card">
            <div class="period-card-title"><i class="fa-solid fa-heart-pulse"></i> System Health</div>
            <div class="health-list">
                <?php foreach ($HEALTH_ITEMS as [$hName, $hCls, $hText]): ?>
                <div class="health-item">
                    <span class="health-item-name"><?= htmlspecialchars($hName) ?></span>
                    <span class="health-item-status <?= $hCls ?>"><span class="health-dot <?= $hCls ?>"></span><?= htmlspecialchars($hText) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Academic Configuration -->
        <div class="period-card">
            <div class="period-card-title">
                <i class="fa-solid fa-graduation-cap"></i> Academic Configuration
            </div>
            <div class="sf-row">
                <div class="sf-group">
                    <label class="sf-label">Academic Year</label>
                    <input class="sf-input" type="text" name="sys_acad_year" value="<?= htmlspecialchars($sys['acad_year']) ?>" placeholder="e.g. 2026-2027"/>
                </div>
                <div class="sf-group">
                    <label class="sf-label">Academic Structure</label>
                    <select class="sf-input" id="sysAcadStructure" name="sys_acad_structure" onchange="onStructureChange()">
                        <?php foreach ($STRUCTURE_LABELS as $val => $label): ?>
                        <option value="<?= $val ?>" <?= ($sys['acad_structure']===$val)?'selected':'' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="sf-group" style="margin-bottom:0;">
                <label class="sf-label">Academic Term</label>
                <select class="sf-input" id="sysAcadTerm" name="sys_acad_term"></select>
                <div class="sf-hint">Choices update automatically with Academic Structure — prevents invalid combinations like Summer for Junior High School.</div>
            </div>
        </div>

        <!-- Evaluation Schedule -->
        <div class="period-card">
            <div class="period-card-title">
                <i class="fa-solid fa-calendar-days"></i> Evaluation Schedule
            </div>
            <div class="sf-row">
                <div class="sf-group">
                    <label class="sf-label">Evaluation Opens</label>
                    <input class="sf-input" type="datetime-local" name="eval_start_date" value="<?= htmlspecialchars($sys['eval_start'] ?? '') ?>"/>
                </div>
                <div class="sf-group">
                    <label class="sf-label">Evaluation Closes</label>
                    <input class="sf-input" type="datetime-local" name="eval_end_date" value="<?= htmlspecialchars($sys['eval_end'] ?? '') ?>"/>
                </div>
            </div>
            <div class="sf-toggle-row" style="border-bottom:none;padding-bottom:0;">
                <div><div class="sf-toggle-label">Automatic Schedule</div><div class="sf-toggle-sub">Open and close submissions automatically at the times above</div></div>
                <label class="toggle-sw"><input type="checkbox" name="sys_auto_schedule" <?= !empty($sys['auto_schedule'])?'checked':'' ?>><span class="toggle-slider"></span></label>
            </div>
        </div>

        <!-- Publish Evaluation -->
        <div class="period-card">
            <div class="period-card-title"><i class="fa-solid fa-eye"></i> Publish Evaluation</div>
            <div class="ctrl-modes" id="publishModes">
                <label class="ctrl-option" data-mode="draft">
                    <input type="radio" name="sys_publish_state" value="draft" <?= ($sys['publish_state']==='draft')?'checked':'' ?> onchange="onPublishChange()">
                    <div>
                        <div class="ctrl-option-title">Save as Draft</div>
                        <div class="ctrl-option-sub">Configure everything without making it visible to students or faculty yet</div>
                    </div>
                </label>
                <label class="ctrl-option" data-mode="published">
                    <input type="radio" name="sys_publish_state" value="published" <?= ($sys['publish_state']!=='draft')?'checked':'' ?> onchange="onPublishChange()">
                    <div>
                        <div class="ctrl-option-title">Publish Immediately</div>
                        <div class="ctrl-option-sub">Make the evaluation period visible, subject to the access rules below</div>
                    </div>
                </label>
            </div>
        </div>

        <!-- Evaluation Access -->
        <div class="period-card">
            <div class="period-card-title">
                <i class="fa-solid fa-toggle-on"></i> Evaluation Access
                <span class="period-status <?= $evalStatus['cls'] ?>" style="margin-left:auto;">
                    <i class="fa-solid fa-circle" style="font-size:7px;"></i>
                    <?= htmlspecialchars($evalStatus['label']) ?>
                </span>
            </div>
            <div class="ctrl-modes" id="ctrlModes">
                <label class="ctrl-option" data-mode="schedule">
                    <input type="radio" name="sys_control_mode" value="schedule" <?= ($sys['control_mode']==='schedule')?'checked':'' ?> onchange="onControlModeChange()">
                    <div>
                        <div class="ctrl-option-title">Follow Schedule</div>
                        <div class="ctrl-option-sub">Status is determined automatically from the evaluation window</div>
                    </div>
                </label>
                <label class="ctrl-option" data-mode="open">
                    <input type="radio" name="sys_control_mode" value="open" <?= ($sys['control_mode']==='open')?'checked':'' ?> onchange="onControlModeChange()">
                    <div>
                        <div class="ctrl-option-title">Force Open</div>
                        <div class="ctrl-option-sub">Allow submissions regardless of the scheduled period</div>
                    </div>
                </label>
                <label class="ctrl-option" data-mode="closed">
                    <input type="radio" name="sys_control_mode" value="closed" <?= ($sys['control_mode']==='closed')?'checked':'' ?> onchange="onControlModeChange()">
                    <div>
                        <div class="ctrl-option-title">Force Closed</div>
                        <div class="ctrl-option-sub">Block submissions regardless of the scheduled period</div>
                    </div>
                </label>
            </div>
        </div>

        <!-- Submission Settings (grouped) -->
        <div class="period-card">
            <div class="period-card-title"><i class="fa-solid fa-list-check"></i> Submission Settings</div>
            <?php
            $RULE_GROUPS = [
                'Submission Rules' => [
                    'rule_only_during_period' => ['Allow submissions only during the evaluation period', 'Blocks submissions outside the configured window'],
                    'rule_one_submission'     => ['Allow only one submission', 'Prevents duplicate or repeated submissions'],
                    'rule_prevent_late'       => ['Prevent submissions after the closing time', 'Hard stop once the window closes'],
                ],
                'Editing' => [
                    'rule_edit_after_submit'  => ['Allow students to edit after submission', 'Off by default to protect evaluation integrity'],
                    'rule_auto_lock'          => ['Automatically lock an evaluation after submission', 'Prevents further changes once submitted'],
                ],
                'Validation' => [
                    'rule_require_all'        => ['Require all required evaluations before submission', 'Blocks partial submissions'],
                ],
                'User Experience' => [
                    'rule_countdown'          => ['Show a countdown before the evaluation closes', 'Warns students the window is ending'],
                ],
            ];
            $groupNames = array_keys($RULE_GROUPS); $lastGroup = end($groupNames);
            foreach ($RULE_GROUPS as $groupName => $rules):
                $ruleKeys = array_keys($rules); $lastKey = end($ruleKeys);
            ?>
            <div class="rule-subgroup" <?= ($groupName===$lastGroup)?'style="margin-bottom:0;"':'' ?>>
                <div class="rule-subgroup-title"><?= htmlspecialchars($groupName) ?></div>
                <?php foreach ($rules as $key => $labelSub): [$label, $sub] = $labelSub; ?>
                <div class="sf-toggle-row" <?= ($key===$lastKey)?'style="border-bottom:none;"':'' ?>>
                    <div><div class="sf-toggle-label"><?= htmlspecialchars($label) ?></div><div class="sf-toggle-sub"><?= htmlspecialchars($sub) ?></div></div>
                    <label class="toggle-sw"><input type="checkbox" name="<?= $key ?>" <?= !empty($sys[$key])?'checked':'' ?>><span class="toggle-slider"></span></label>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Notifications -->
        <div class="period-card">
            <div class="period-card-title"><i class="fa-solid fa-bell"></i> Notifications</div>
            <?php
            $NOTIFS = [
                'notify_eval_open'        => ['Notify students when evaluation opens', 'Sent as soon as the period becomes accessible'],
                'notify_eval_closing'     => ['Notify students before closing', 'A heads-up reminder ahead of the deadline'],
                'notify_faculty_complete' => ['Notify faculty after completion', 'Sent once a student finishes their submissions'],
                'notify_reminders'        => ['Send reminder emails', 'Periodic nudges to students who have not submitted yet'],
            ];
            $notifKeys = array_keys($NOTIFS); $lastNotif = end($notifKeys);
            foreach ($NOTIFS as $key => $labelSub): [$label, $sub] = $labelSub;
            ?>
            <div class="sf-toggle-row" <?= ($key===$lastNotif)?'style="border-bottom:none;"':'' ?>>
                <div><div class="sf-toggle-label"><?= htmlspecialchars($label) ?></div><div class="sf-toggle-sub"><?= htmlspecialchars($sub) ?></div></div>
                <label class="toggle-sw"><input type="checkbox" name="<?= $key ?>" <?= !empty($sys[$key])?'checked':'' ?>><span class="toggle-slider"></span></label>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Maintenance -->
        <div class="sf-toggle-row">
            <div><div class="sf-toggle-label">Maintenance Mode</div><div class="sf-toggle-sub">Lock system for all non-admins; admins keep access</div></div>
            <label class="toggle-sw"><input type="checkbox" name="sys_maintenance" <?= !empty($sys['maintenance'])?'checked':'' ?>><span class="toggle-slider"></span></label>
        </div>
    </div>
    <div class="sm-footer" id="footer-system" style="display:none;">
        <span class="unsaved-indicator" id="unsavedIndicator"><i class="fa-solid fa-circle" style="font-size:7px;"></i> Unsaved changes</span>
        <button type="button" class="sf-btn sf-btn-discard" id="discardBtn" style="display:none;" onclick="discardSystemChanges()"><i class="fa-solid fa-rotate-left" style="margin-right:5px;"></i>Discard</button>
        <button type="button" class="sf-btn sf-btn-cancel" onclick="closeSettings()">Cancel</button>
        <button type="submit" class="sf-btn sf-btn-save"><i class="fa-solid fa-floppy-disk" style="margin-right:5px;"></i>Save System Settings</button>
    </div>
    </form>

    <!-- ── APPEARANCE TAB ── -->
    <div class="sm-section sm-body" id="ssec-appearance">
        <div class="sf-toggle-row">
            <div><div class="sf-toggle-label">Compact Navigation</div><div class="sf-toggle-sub">Show icons only in the navbar</div></div>
            <label class="toggle-sw"><input type="checkbox" id="togCompact" onchange="applyCompact(this.checked)"><span class="toggle-slider"></span></label>
        </div>
        <div class="sf-group" style="margin-top:16px;">
            <label class="sf-label">Accent Color</label>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px;">
                <?php foreach(['#2f6ee2'=>'Blue','#0F9F6E'=>'Green','#8b5cf6'=>'Purple','#e2792f'=>'Amber','#e23f6c'=>'Rose'] as $hex=>$name): ?>
                <label style="cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px;">
                    <input type="radio" name="accent_color" value="<?= $hex ?>" style="display:none;" onchange="applyAccent('<?= $hex ?>')" <?= $hex==='#0F9F6E'?'checked':'' ?>>
                    <span style="width:28px;height:28px;border-radius:50%;background:<?= $hex ?>;display:block;border:3px solid transparent;transition:border-color .2s;" class="clr-swatch" data-color="<?= $hex ?>"></span>
                    <span style="font-size:10px;color:var(--muted);"><?= $name ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="sm-section sm-body" id="ssec-archive">
        <div style="padding:13px 15px;background:#EEF4FF;border:1px solid #C9D9EF;border-radius:10px;color:#2855A4;display:flex;gap:10px;align-items:flex-start;font-size:12px;line-height:1.5;">
            <i class="fa-solid fa-box-archive" style="margin-top:2px;"></i>
            <div><strong>System Archive</strong><br>Safely close an evaluation year, preserve its history, and start the next cycle with clean live dashboards.</div>
        </div>
        <div style="margin-top:14px;padding:14px;border:1px solid var(--card-border);border-radius:10px;background:var(--inner-bg,#F8FAFC);">
            <div style="font-weight:700;font-size:13px;color:var(--text-dark);margin-bottom:5px;">What this does</div>
            <div style="font-size:12px;color:var(--text-dim);line-height:1.55;">Evaluation submissions, results, tracker rows, reminders, generated reports, and evaluation notifications are stored in a protected archive. Questions, user accounts, assignments, permissions, settings, and system logs remain intact.</div>
        </div>
        <button type="button" class="sf-btn sf-btn-save" style="margin-top:14px;" onclick="openArchive()"><i class="fa-solid fa-arrow-up-right-from-square" style="margin-right:5px;"></i>Open System Archive</button>
    </div>

    <div class="sm-footer" id="footer-appearance" style="display:none;">
        <button type="button" class="sf-btn sf-btn-save" onclick="closeSettings()">Done</button>
    </div>

</div><!-- /.settings-modal -->
</div><!-- /.modal-overlay -->

<!-- ═══ PAGE CONTENT ═══════════════════════════════════════════════════════ -->
<div class="page-content">
    <div id="dashboard" class="page">
        <div class="pbi-dashboard-container">
            <!-- Dashboard heading removed; the notification bell now lives in the page header bar. -->

            <!-- ── STAT CARDS ── -->
            <div class="pbi-stats-row">
                <div class="pbi-stat-card stat-teal">
                    <div class="pbi-stat-icon-circle"><i class="fa-solid fa-users"></i></div>
                    <div class="pbi-stat-label">Total Personnel</div>
                    <div class="pbi-stat-value" id="cnt-faculty"><?= number_format($facultyCount) ?></div>
                    <div class="pbi-stat-sub">Faculty + Staff</div>
                    <div class="pbi-stat-trend flat" id="trend-faculty"><i class="fa-solid fa-minus"></i> </div>
                </div>
                <div class="pbi-stat-card stat-skyblue">
                    <div class="pbi-stat-icon-circle"><i class="fa-solid fa-graduation-cap"></i></div>
                    <div class="pbi-stat-label">Active Students</div>
                    <div class="pbi-stat-value" id="cnt-students"><?= number_format($studentCount) ?></div>
                    <div class="pbi-stat-sub">Active student accounts</div>
                    <div class="pbi-stat-trend flat" id="trend-students"><i class="fa-solid fa-minus"></i></div>
                </div>
                <div class="pbi-stat-card stat-violet">
                    <div class="pbi-stat-icon-circle"><i class="fa-solid fa-clipboard-check"></i></div>
                    <div class="pbi-stat-label">Evaluations Submitted</div>
                    <div class="pbi-stat-value" id="cnt-evals"><?= number_format($periodSubmittedEvaluationCount) ?></div>
                    <div class="pbi-stat-sub">Submissions in the current period</div>
                    <div class="pbi-stat-trend flat" id="trend-evals"><i class="fa-solid fa-minus"></i></div>
                </div>
                <div class="pbi-stat-card stat-gold pbi-completion-card">
                    <div class="pbi-completion-ring" id="completion-ring" style="--pct:<?= min(100,$submissionPct) ?>;">
                        <div class="pbi-completion-ring-inner" id="completion-ring-pct"><?= $submissionPct ?>%</div>
                    </div>
                    <div class="pbi-completion-text">
                        <div class="pbi-stat-label">Student Participation</div>
                        <div class="pbi-stat-sub" id="completion-note"><?= $submittedCount ?> of <?= $studentCount ?> active students submitted</div>
                    </div>
                </div>
            </div>

            <!-- ── EVALUATION PERIOD ── -->
            <div class="pbi-period-box">
                <div class="pbi-period-block">
                    <div class="pbi-period-label">Current Status
                        <span class="pbi-period-badge <?= $evalStatus['cls'] === 'open' ? 'green' : ($evalStatus['cls'] === 'closed' ? 'red' : ($evalStatus['cls'] === 'amber' ? 'yellow' : 'gray')) ?>">
                            <i class="fa-solid fa-circle" style="font-size:6px;"></i> <?= htmlspecialchars($evalStatus['label']) ?>
                        </span>
                    </div>
                    <div class="pbi-period-value">
                        <?= htmlspecialchars($sys['acad_year']) ?> · <?= htmlspecialchars($STRUCTURE_LABELS[$sys['acad_structure']] ?? 'College') ?> · <?= htmlspecialchars($sys['acad_term']) ?>
                    </div>
                    <div class="pbi-period-meta">
                        <i class="fa-regular fa-calendar"></i><?= !empty($sys['eval_start']) ? 'Period started: ' . htmlspecialchars(date('M j, Y', strtotime($sys['eval_start']))) : 'No set start date' ?>
                        <span class="sep">|</span>
                        <i class="fa-regular fa-clock"></i><?= !empty($sys['eval_end']) ? 'Deadline: ' . htmlspecialchars(date('M j, Y', strtotime($sys['eval_end']))) : 'No set deadline' ?>
                    </div>
                </div>

                <div class="pbi-period-tiles">
                    <div class="pbi-period-tile tile-progress">
                        <div class="pbi-period-tile-label">Student Participation</div>
                        <div class="pbi-period-tile-value" id="progress-pct"><?= $submissionPct ?>%</div>
                        <div class="pbi-progress-track" style="margin-top:8px;"><div class="pbi-progress-fill" id="progress-fill" style="width:<?= min(100,$submissionPct) ?>%;"></div></div>
                        <div class="pbi-period-tile-sub" id="progress-note"><?= $submittedCount ?> of <?= $studentCount ?> active students submitted</div>
                    </div>
                    <div class="pbi-period-tile tile-submitted">
                        <div class="pbi-period-tile-label">Students Submitted</div>
                        <div class="pbi-period-tile-value green" id="cnt-submitted-tile"><?= number_format($submittedCount) ?></div>
                    </div>
                    <div class="pbi-period-tile tile-pending">
                        <div class="pbi-period-tile-label">No Submission Yet</div>
                        <div class="pbi-period-tile-value gold" id="cnt-pending-tile"><?= number_format($pendingEvalCount) ?></div>
                    </div>
                    <div class="pbi-period-tile tile-total">
                        <div class="pbi-period-tile-label">Active Students</div>
                        <div class="pbi-period-tile-value" id="cnt-student"><?= number_format($studentCount) ?></div>
                    </div>
                </div>
            </div>

            <!-- ── PERSONNEL OVERVIEW / NEEDS ATTENTION / QUICK ACCESS ── -->
            <div class="pbi-bottom-row">

                <div class="pbi-panel">
                    <div class="pbi-panel-head">
                        <span class="pbi-panel-title">Personnel Overview</span>
                        <button type="button" class="pbi-panel-viewall" onclick="showPage('registrations', document.getElementById('link-registrations'))">View All</button>
                    </div>
                    <div class="pbi-personnel-item">
                        <div class="pbi-personnel-icon teal"><i class="fa-solid fa-chalkboard-user"></i></div>
                        <div>
                            <div class="pbi-personnel-name">Faculty</div>
                            <div class="pbi-personnel-meta">Active: <?= $teacherCount ?> &nbsp; Inactive: <?= $teacherInactive ?></div>
                        </div>
                        <div class="pbi-personnel-count" id="cnt-teacher"><?= $teacherCount ?></div>
                    </div>
                    <div class="pbi-personnel-item">
                        <div class="pbi-personnel-icon skyblue"><i class="fa-solid fa-user-tie"></i></div>
                        <div>
                            <div class="pbi-personnel-name">Staff</div>
                            <div class="pbi-personnel-meta">Active: <?= $staffCount ?> &nbsp; Inactive: <?= $staffInactive ?></div>
                        </div>
                        <div class="pbi-personnel-count" id="cnt-staff"><?= $staffCount ?></div>
                    </div>
                    <div class="pbi-personnel-item">
                        <div class="pbi-personnel-icon violet"><i class="fa-solid fa-graduation-cap"></i></div>
                        <div>
                            <div class="pbi-personnel-name">Students</div>
                            <div class="pbi-personnel-meta">Enrolled this period</div>
                        </div>
                        <div class="pbi-personnel-count"><?= number_format($studentCount) ?></div>
                    </div>
                </div>

                <div class="pbi-panel">
                    <div class="pbi-panel-head">
                        <span class="pbi-panel-title">Needs Attention</span>
                    </div>
                    <?php if ($pendingEvalCount > 0): ?>
                    <div class="pbi-attention-item is-clickable" onclick="showPage('tracker', document.getElementById('link-tracker'))">
                        <i class="fa-solid fa-triangle-exclamation dot warn"></i>
                        <span><?= number_format($pendingEvalCount) ?> active student<?= $pendingEvalCount===1?'':'s' ?> have not submitted an evaluation this period.</span>
                        <i class="fa-solid fa-chevron-right chev"></i>
                    </div>
                    <?php endif; ?>
                    <?php if ($pendingRegCount > 0): ?>
                    <div class="pbi-attention-item is-clickable" onclick="showPage('registrations', document.getElementById('link-registrations'))">
                        <i class="fa-solid fa-circle-info dot info"></i>
                        <span><?= number_format($pendingRegCount) ?> personnel account<?= $pendingRegCount===1?'':'s' ?> pending verification.</span>
                        <i class="fa-solid fa-chevron-right chev"></i>
                    </div>
                    <?php else: ?>
                    <div class="pbi-attention-item is-clickable" onclick="showPage('registrations', document.getElementById('link-registrations'))">
                        <i class="fa-solid fa-circle-check dot ok"></i>
                        <span>All personnel accounts are verified.</span>
                        <i class="fa-solid fa-chevron-right chev"></i>
                    </div>
                    <?php endif; ?>
                    <div class="pbi-attention-item is-clickable" onclick="showPage('reports', document.getElementById('link-reports'))">
                        <i class="fa-solid fa-circle-check dot <?= $activeEvals > 0 ? 'ok' : 'warn' ?>"></i>
                        <span><?= $activeEvals > 0 ? 'All questionnaires are updated and ready.' : 'No questionnaires configured yet.' ?></span>
                        <i class="fa-solid fa-chevron-right chev"></i>
                    </div>
                    <div class="pbi-attention-item is-clickable" onclick="showPage('settings', document.getElementById('link-settings'))">
                        <i class="fa-solid fa-circle-info dot <?= !empty($sys['maintenance']) ? 'warn' : 'info' ?>"></i>
                        <span><?= !empty($sys['maintenance']) ? 'System is in maintenance mode.' : 'No system issues reported.' ?></span>
                        <i class="fa-solid fa-chevron-right chev"></i>
                    </div>
                </div>

            </div>

        </div>
    </div>

    <div id="reports"       class="page"><iframe src="questionnaire.php"              class="iframe-box"></iframe></div>
    <div id="analytics"     class="page"><iframe src="admin_analytics.php"            class="iframe-box" id="analyticsFrame"></iframe></div>
    <div id="registrations" class="page"><iframe src="manage_privileged_accounts.php" class="iframe-box" id="registrationsFrame"></iframe></div>
    <div id="tracker"       class="page"><iframe src="evaluation_tracker.php"        class="iframe-box"></iframe></div>
    <div id="ea_eval"       class="page"><iframe id="eaEvalFrame" src="ea_evaluation.php"             class="iframe-box"></iframe></div>
    <?php if ($isExecutiveAssistant): ?>
    <div id="ea_results"   class="page"><iframe src="ea_results.php"               class="iframe-box"></iframe></div>
    <?php endif; ?>
    <div id="system_logs"   class="page"><iframe src="system_logs.php"              class="iframe-box"></iframe></div>
    <div id="settings"      class="page"><iframe src="settings.php"                   class="iframe-box" id="settingsFrame"></iframe></div>
</div>

<script>
/* ── CLOCK ── */
function updateClock(){const n=new Date(),t=[n.getHours(),n.getMinutes(),n.getSeconds()].map(x=>String(x).padStart(2,'0')).join(':');const e=document.getElementById('digital-clock');if(e)e.textContent=t;}
setInterval(updateClock,1000);updateClock();

document.addEventListener('click',function(e){
    if(!document.getElementById('notifWrap')?.contains(e.target)&&!document.getElementById('notifDropdown')?.contains(e.target)){
        _notifOpen=false;document.getElementById('notifDropdown')?.classList.remove('show');
    }
    const dc=document.querySelector('.pbi-dropdown-container'),menu=document.getElementById('quick-role-menu');
    if(dc&&!dc.contains(e.target)&&menu)menu.classList.remove('show');
});

/* ── CUSTOM CONFIRM MODAL (no native browser confirm()) ── */
function pbiConfirm(message, opts){
    opts = opts || {};
    var overlay=document.getElementById('pbiConfirmOverlay');
    overlay.querySelector('.pbi-confirm-msg').textContent = message;
    var okBtn=document.getElementById('pbiConfirmOk'), cancelBtn=document.getElementById('pbiConfirmCancel');
    okBtn.textContent = opts.okLabel || 'OK';
    cancelBtn.textContent = opts.cancelLabel || 'Cancel';
    overlay.classList.add('show');
    return new Promise(function(resolve){
        okBtn.onclick=function(){ overlay.classList.remove('show'); resolve(true); };
        cancelBtn.onclick=function(){ overlay.classList.remove('show'); resolve(false); };
    });
}
function pbiConfirmLogout(link){
    var cur=document.querySelector('.page.active');
    var go=function(){
        pbiConfirm('Are you sure you want to log out?', {okLabel:'OK'}).then(function(ok){
            if(ok) window.location.href=link.href;
        });
    };
    if(cur&&cur.id==='settings') pbiGuardUnsaved(go); else go();
    return false; // stop the link's default navigation until confirmed
}

/* ── FULL-PAGE DIM FOR MODALS INSIDE AN IFRAME PAGE ──
   Pages such as Account Management run in an iframe, so their own modal
   overlay can only dim the iframe (and leaves its scrollbar/edges bright).
   Instead they call window.parent.pbiFrameDim(true, frameEl, rect, onBackdrop)
   when a modal opens and pbiFrameDim(false) when it closes. ONE plain dim —
   the same colour as the logout dialog — is drawn over the entire window
   (sidebar, top bar, iframe content and scrollbar), with a rounded cut-out
   exactly where the modal card is, so only the card shows through undimmed.
   rect = {x,y,w,h,r} of the modal card in the iframe's own coordinates. */
var _pbiFrameDimEl=null, _pbiFrameDimTarget=null, _pbiFrameDimRect=null;
function _pbiFrameDimLayout(){
    if(!_pbiFrameDimEl||!_pbiFrameDimTarget) return;
    var f=_pbiFrameDimTarget.getBoundingClientRect();
    var rc=_pbiFrameDimRect;
    var x,y,w,h,rad;
    if(rc){ x=f.left+rc.x; y=f.top+rc.y; w=rc.w; h=rc.h; rad=rc.r||0; }
    else  { x=f.left; y=f.top; w=f.width; h=f.height; rad=parseFloat(getComputedStyle(_pbiFrameDimTarget).borderTopLeftRadius)||0; }
    rad=Math.max(0,Math.min(rad,w/2,h/2));
    var W=window.innerWidth, H=window.innerHeight;
    var hole='M'+(x+rad)+' '+y+'H'+(x+w-rad)+'A'+rad+' '+rad+' 0 0 1 '+(x+w)+' '+(y+rad)+
             'V'+(y+h-rad)+'A'+rad+' '+rad+' 0 0 1 '+(x+w-rad)+' '+(y+h)+
             'H'+(x+rad)+'A'+rad+' '+rad+' 0 0 1 '+x+' '+(y+h-rad)+
             'V'+(y+rad)+'A'+rad+' '+rad+' 0 0 1 '+(x+rad)+' '+y+'Z';
    _pbiFrameDimEl.style.clipPath='path(evenodd,"M0 0H'+W+'V'+H+'H0Z '+hole+'")';
}
function pbiFrameDim(on, frameEl, rect, onBackdrop){
    if(on){
        if(!_pbiFrameDimEl){
            _pbiFrameDimEl=document.createElement('div');
            _pbiFrameDimEl.id='pbiFrameDim';
            _pbiFrameDimEl.style.cssText='position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:10000;';
            document.body.appendChild(_pbiFrameDimEl);
            window.addEventListener('resize',_pbiFrameDimLayout);
        }
        _pbiFrameDimEl.onclick=function(){ if(typeof onBackdrop==='function') onBackdrop(); };
        _pbiFrameDimTarget=frameEl||document.querySelector('.page.active iframe')||document.querySelector('iframe');
        _pbiFrameDimRect=rect||null;
        _pbiFrameDimLayout();
    } else if(_pbiFrameDimEl){
        window.removeEventListener('resize',_pbiFrameDimLayout);
        _pbiFrameDimEl.remove();
        _pbiFrameDimEl=null; _pbiFrameDimTarget=null; _pbiFrameDimRect=null;
    }
}

/* ── SETTINGS MODAL ── */
function openSettings(tab){
    document.getElementById('settingsOverlay').classList.add('show');
    document.body.style.overflow='hidden';
    switchTab(tab||'profile');
}
async function closeSettings(){
    if(_systemDirty && document.getElementById('ssec-system')?.classList.contains('active')){
        if(!(await pbiConfirm('You have unsaved changes in System & Period settings. Close without saving?', {okLabel:'Close without saving'}))) return;
    }
    document.getElementById('settingsOverlay').classList.remove('show');document.body.style.overflow='';
}
function switchTab(tab){
    document.querySelectorAll('.sm-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.sm-section').forEach(s=>s.classList.remove('active'));
    document.querySelectorAll('[id^="footer-"]').forEach(f=>f.style.display='none');
    document.getElementById('stab-'+tab)?.classList.add('active');
    document.getElementById('ssec-'+tab)?.classList.add('active');
    const footer=document.getElementById('footer-'+tab);
    if(footer)footer.style.display='flex';
}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeSettings();});

function openArchive(){
    document.getElementById('settingsOverlay')?.classList.remove('show');
    document.body.style.overflow='';
    showPage('settings', document.getElementById('link-settings'));
    const frame=document.getElementById('settingsFrame');
    if(frame){ frame.src='settings.php?tab=archive'; }
}

/* ── NAV / PAGES ── */

/* ── IFRAME APPEARANCE SYNC ──────────────────────────────────────
   Feature pages are loaded in same-origin iframes.  Their own HTML/CSS
   can otherwise start in the light palette before the shared appearance
   engine gets a chance to apply the saved theme.  Keep every feature iframe
   synchronized with the dashboard's actual data-theme attribute. */
(function initIframeAppearanceSync(){
    function currentTheme(){
        return document.documentElement.getAttribute('data-theme')
            || (localStorage.getItem('pbiTheme') || 'light');
    }

    function syncFrame(frame){
        if(!frame) return;
        function apply(){
            try{
                var doc = frame.contentDocument || (frame.contentWindow && frame.contentWindow.document);
                if(!doc || !doc.documentElement) return;
                var theme = currentTheme();
                doc.documentElement.setAttribute('data-theme', theme === 'dark' ? 'dark' : 'light');
                doc.documentElement.style.colorScheme = theme === 'dark' ? 'dark' : 'light';
            }catch(e){}
        }
        frame.addEventListener('load', apply, {once:false});
        apply();
    }

    function syncAllFrames(){
        document.querySelectorAll('iframe.iframe-box').forEach(syncFrame);
    }

    var root = document.documentElement;
    try{
        new MutationObserver(function(mutations){
            for(var i=0;i<mutations.length;i++){
                if(mutations[i].attributeName === 'data-theme'){
                    syncAllFrames();
                    break;
                }
            }
        }).observe(root,{attributes:true});
    }catch(e){}

    window.addEventListener('storage', function(e){
        if(e.key === 'pbiTheme') syncAllFrames();
    });

    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', syncAllFrames, {once:true});
    }else{
        syncAllFrames();
    }

    window.addEventListener('load', syncAllFrames);
})();

/* ── UNSAVED SETTINGS GUARD ──────────────────────────────────────
   Settings lives in an iframe that stays loaded while you visit other tabs, so
   edits would silently sit there unsaved. Before leaving the Settings page (any
   sidebar link, dashboard shortcut, or logout) ask the Settings page whether it
   has unsaved changes and, if so, offer Save / Discard / Keep editing. */
function pbiSettingsWin(){
    var f=document.getElementById('settingsFrame');
    try{ return f ? f.contentWindow : null; }catch(e){ return null; }
}
function pbiGuardUnsaved(proceed){
    var w=pbiSettingsWin(), st=null;
    try{ st=(w&&typeof w.pbiUnsavedState==='function') ? w.pbiUnsavedState() : null; }catch(e){ st=null; }
    if(!st||!st.dirty){ proceed(); return; }

    var ov=document.getElementById('pbiUnsavedOverlay'), list=document.getElementById('pbiUnsavedList');
    var stay=document.getElementById('pbiUnsavedStay'), discard=document.getElementById('pbiUnsavedDiscard'), save=document.getElementById('pbiUnsavedSave');
    list.innerHTML='';
    (st.changes&&st.changes.length?st.changes:['Some fields were changed']).forEach(function(c){
        var li=document.createElement('li'); li.textContent=c; list.appendChild(li);
    });
    function close(){ ov.classList.remove('show'); save.disabled=false; discard.disabled=false; save.textContent='Save & continue'; }
    ov.classList.add('show');
    ov._close=close;
    stay.onclick=close;
    discard.onclick=function(){
        close();
        try{ w.pbiDiscardSystemSettings(); }catch(e){}
        proceed();
    };
    save.onclick=function(){
        save.disabled=true; discard.disabled=true; save.textContent='Saving…';
        Promise.resolve(w.pbiSaveSystemSettings()).then(function(ok){
            close();
            if(ok) proceed();   // if validation/saving failed, stay on Settings so it can be fixed
        }).catch(close);
    };
}
document.addEventListener('keydown',function(e){
    if(e.key!=='Escape') return;
    var ov=document.getElementById('pbiUnsavedOverlay');
    if(ov&&ov.classList.contains('show')&&ov._close) ov._close();
});
document.addEventListener('click',function(e){
    var ov=document.getElementById('pbiUnsavedOverlay');
    if(ov&&e.target===ov&&ov._close) ov._close();
});


// Called by Settings > My Account (nested iframe) after the admin edits their profile,
// so the sidebar name/photo update immediately without a page reload.
window.pbiUpdateProfile=function(d){
    d=d||{};
    if(d.full_name){
        var n=document.querySelector('.sidebar-profile-name'); if(n) n.textContent=d.full_name;
        var p=d.full_name.trim().split(/\s+/), ini=((p[0]||'')[0]||'').toUpperCase()+((p[1]||'')[0]||'').toUpperCase();
        document.querySelectorAll('.sidebar-profile-initials').forEach(function(x){x.textContent=ini;});
    }
    if('photo_url' in d){
        var logo=document.querySelector('.sidebar-profile-logo'); if(!logo) return;
        var img=logo.querySelector('img'), ini2=logo.querySelector('.sidebar-profile-initials');
        if(d.photo_url){
            if(!img){ img=document.createElement('img'); logo.insertBefore(img, logo.firstChild); }
            img.style.display=''; img.src=d.photo_url+'?t='+Date.now();
            if(ini2) ini2.style.display='none';
        } else {
            if(img) img.remove();
            if(ini2) ini2.style.display='flex';
        }
    }
};

// ── Full-page dim for modals that open inside a feature iframe ─────────────
// A modal inside an iframe can only dim the iframe itself. The page posts
// {type:'pbi-modal-dim', open:bool}; we dim everything OUTSIDE that iframe
// (sidebar, top bar, margins) with the same tint so the whole page looks dimmed.
(function(){
    var dim=null, activeFrame=null;
    var TINT='rgba(15,23,42,.55)', BLUR='none'; // identical to the logout confirm overlay (flat tint, no blur)
    (function(){
        var st=document.createElement('style');
        st.id='pbi-frame-dim-style';
        // While a popup is open the frame stops looking like a bordered card, so the
        // whole screen reads as one plain dimmed background.
        st.textContent='html body .iframe-box.pbi-frame-dimmed{border-width:0!important;border-radius:0!important;box-shadow:none!important;}';
        document.head.appendChild(st);
    })();
    function syncInner(f){
        // Give the popup backdrop inside the iframe the exact same tint/blur as the outer dim.
        try{
            var d=f.contentDocument;
            if(!d||d.getElementById('pbi-dim-sync')) return;
            var s=d.createElement('style');
            s.id='pbi-dim-sync';
            // Flat tint over the whole frame; hide the frame's scrollbar so the dim has no light strip at the edge.
            s.textContent='.qxm-modal-backdrop{background:'+TINT+'!important;-webkit-backdrop-filter:none!important;backdrop-filter:none!important;}'+
                          'html:has(.qxm-modal-backdrop.open),html:has(.qxm-modal-backdrop.open) body{scrollbar-width:none!important;}'+
                          'html:has(.qxm-modal-backdrop.open)::-webkit-scrollbar,html:has(.qxm-modal-backdrop.open) body::-webkit-scrollbar{display:none!important;}';
            d.head.appendChild(s);
        }catch(err){}
    }
    function ensure(){
        if(dim) return dim;
        dim=document.createElement('div');
        dim.id='pbiGlobalDim';
        dim.style.cssText='position:fixed;inset:0;z-index:9990;display:none;background:'+TINT+';-webkit-backdrop-filter:'+BLUR+';backdrop-filter:'+BLUR+';';
        document.body.appendChild(dim);
        return dim;
    }
    function layout(){
        if(!dim||!activeFrame||dim.style.display==='none') return;
        var r=activeFrame.getBoundingClientRect(), W=window.innerWidth, H=window.innerHeight;
        var rad=0; // square cut-out: no visible rounded box around the frame
        var x=r.left,y=r.top,w=r.width,h=r.height;
        var hole='M'+(x+rad)+' '+y+'H'+(x+w-rad)+'A'+rad+' '+rad+' 0 0 1 '+(x+w)+' '+(y+rad)+
                 'V'+(y+h-rad)+'A'+rad+' '+rad+' 0 0 1 '+(x+w-rad)+' '+(y+h)+'H'+(x+rad)+
                 'A'+rad+' '+rad+' 0 0 1 '+x+' '+(y+h-rad)+'V'+(y+rad)+'A'+rad+' '+rad+' 0 0 1 '+(x+rad)+' '+y+'Z';
        dim.style.clipPath='path(evenodd,"M0 0H'+W+'V'+H+'H0Z'+hole+'")';
    }
    function hide(){
        if(dim) dim.style.display='none';
        if(activeFrame) activeFrame.classList.remove('pbi-frame-dimmed');
        activeFrame=null;
    }
    window.addEventListener('message',function(e){
        if(!e.data||e.data.type!=='pbi-modal-dim'||e.origin!==location.origin) return;
        if(!e.data.open){ hide(); return; }
        var f=Array.prototype.find.call(document.querySelectorAll('iframe'),function(i){return i.contentWindow===e.source;});
        if(!f) return;
        if(activeFrame&&activeFrame!==f) activeFrame.classList.remove('pbi-frame-dimmed');
        activeFrame=f; f.classList.add('pbi-frame-dimmed'); syncInner(f);
        ensure().style.display='block'; layout();
    });
    window.addEventListener('resize',layout);
    // A form submit / navigation inside the frame reloads it: never leave the dim stuck on.
    document.addEventListener('load',function(e){ if(e.target&&e.target.tagName==='IFRAME') hide(); },true);
})();
function showPage(pageId,element){
    var cur=document.querySelector('.page.active');
    if(cur&&cur.id==='settings'&&pageId!=='settings'){
        pbiGuardUnsaved(function(){ _showPageNow(pageId,element); });
        return;
    }
    _showPageNow(pageId,element);
}
function _showPageNow(pageId,element){
    document.querySelectorAll('.page').forEach(p=>p.classList.remove('active'));
    document.getElementById(pageId)?.classList.add('active');
    document.querySelectorAll('.nav-menu .nav-item:not(.logout)').forEach(l=>l.classList.remove('active'));
    if(element&&!element.classList.contains('logout')){element.classList.add('active');moveIndicator(element);}
    localStorage.setItem('activePage',pageId);
}
function openSector(name){
    const frame = document.getElementById('registrationsFrame');
    if(frame) frame.src = 'manage_privileged_accounts.php?role=' + encodeURIComponent(name.toLowerCase());
    showPage('registrations', document.getElementById('link-registrations'));
}
function moveIndicator(el){
    const ind=document.querySelector('.indicator'),menu=document.querySelector('.nav-menu');
    if(!ind||!el||el.classList.contains('logout')||!menu){if(ind)ind.style.opacity=0;return;}
    const mr=menu.getBoundingClientRect(),er=el.getBoundingClientRect();
    ind.style.height=er.height+'px';
    ind.style.transform=`translateY(${er.top-mr.top}px)`;ind.style.opacity=1;
}
window.addEventListener('resize',()=>{const a=document.querySelector('.nav-item.active');if(a)moveIndicator(a);});

/* ── DASHBOARD DROPDOWN ── */
function toggleRoleMenu(){document.getElementById('quick-role-menu').classList.toggle('show');}
function switchRole(role){
    document.getElementById('quick-role-menu').classList.remove('show');
    if(role==='evaluator')window.location.href='choose_role.php';
    else alert('You are currently managing the Master Admin Dashboard panel.');
}

/* ── PHOTO PREVIEW ── */
function previewPhoto(input){
    if(!input.files||!input.files[0])return;
    const reader=new FileReader();
    reader.onload=e=>{
        const img=document.getElementById('sfPhotoImg'),ini=document.getElementById('sfPhotoInitials');
        img.src=e.target.result;img.style.display='block';if(ini)ini.style.display='none';
    };
    reader.readAsDataURL(input.files[0]);
}

/* ── PASSWORD STRENGTH ── */
function pwStrength(val){
    const bar=document.getElementById('sfPwBar'),hint=document.getElementById('sfPwHint');let s=0;
    if(val.length>=8)s++;if(/[A-Z]/.test(val))s++;if(/[0-9]/.test(val))s++;if(/[^A-Za-z0-9]/.test(val))s++;
    const c=['#BF616A','#f97316','#eab308','#A3BE8C'],l=['Weak','Fair','Good','Strong'];
    if(!val){bar.style.background='rgba(255,255,255,.08)';hint.textContent='';return;}
    bar.style.background=c[s-1]||c[0];hint.textContent=l[s-1]||'Weak';hint.style.color=c[s-1]||c[0];
}
function togglePw(id,iconId){const el=document.getElementById(id),ic=document.getElementById(iconId);el.type=el.type==='password'?'text':'password';ic.className=el.type==='password'?'fa-solid fa-eye':'fa-solid fa-eye-slash';}

/* ── APPEARANCE ── */
function markAccentSwatches(color){document.querySelectorAll('.clr-swatch').forEach(s=>s.style.borderColor=(s.dataset.color||'').toLowerCase()===String(color||'').toLowerCase()?'#fff':'transparent');}
/* Accent is shared with Settings > Appearance: same localStorage key (pbiAccent), applied by admin_appearance.js as --accent. */
function applyAccent(color){
    try{localStorage.setItem('pbiAccent',color);}catch(e){}
    if(window.PBIAppearance&&PBIAppearance.apply) PBIAppearance.apply(); else document.documentElement.style.setProperty('--accent',color);
    markAccentSwatches(color);
}
function applyCompact(on){document.querySelectorAll('.nav-item span').forEach(s=>s.style.display=on?'none':'');document.querySelectorAll('.nav-section-label').forEach(s=>s.style.display=on?'none':'');localStorage.setItem('pbi_compact',on?'1':'0');}

/* ── SYSTEM & PERIOD: dynamic Academic Term dropdown + control-mode highlight ──
   The valid Term choices depend on the selected Academic Structure. This now
   comes straight from the shared service (ss_structure_terms()) instead of a
   hardcoded JS object, so PHP and JS can never drift out of sync — add a new
   structure/term in one place (the service) and both sides pick it up. */
const TERM_OPTIONS = <?= json_encode(ss_structure_terms()) ?>;
function populateAcadTerms(selected){
    const structureSel = document.getElementById('sysAcadStructure');
    const termSel = document.getElementById('sysAcadTerm');
    if(!structureSel || !termSel) return;
    const options = TERM_OPTIONS[structureSel.value] || TERM_OPTIONS.college;
    termSel.innerHTML = '';
    options.forEach(opt=>{
        const o=document.createElement('option');
        o.value=opt; o.textContent=opt;
        termSel.appendChild(o);
    });
    if(selected && options.includes(selected)) termSel.value = selected;
    termSel.disabled = options.length === 1;
}
function onStructureChange(){ populateAcadTerms(); }
function onControlModeChange(){
    document.querySelectorAll('.ctrl-option').forEach(opt=>{
        opt.classList.toggle('selected', opt.querySelector('input').checked);
    });
}
function onPublishChange(){ onControlModeChange(); }
const INITIAL_ACAD_TERM = '<?= htmlspecialchars($sys['acad_term']) ?>';
populateAcadTerms(INITIAL_ACAD_TERM);
onControlModeChange();

/* ── UNSAVED CHANGES: System & Period tab ──
   Tracks real edits (input/change events only fire on an actual user
   interaction, so the flag never flips just from opening the tab or from the
   page's own initial render) so an admin can't accidentally lose
   configuration by closing the modal or navigating away without saving.
   Save writes to the server and reloads the page, which naturally clears all
   of this; Discard reverts the form in place without a reload. */
let _systemDirty = false;
function markSystemDirty(){
    if(_systemDirty) return;
    _systemDirty = true;
    document.getElementById('unsavedIndicator')?.classList.add('show');
    document.getElementById('discardBtn')?.style.setProperty('display','inline-flex');
}
function markSystemClean(){
    _systemDirty = false;
    document.getElementById('unsavedIndicator')?.classList.remove('show');
    const db=document.getElementById('discardBtn'); if(db) db.style.display='none';
}
function discardSystemChanges(){
    if(!_formSystemEl) return;
    _formSystemEl.reset(); // restores every plain input/select/checkbox to the values the page was rendered with
    populateAcadTerms(INITIAL_ACAD_TERM); // the Term dropdown is built dynamically, so reset() alone can't restore it
    onControlModeChange();
    onPublishChange();
    markSystemClean();
}
const _formSystemEl = document.getElementById('formSystem');
if(_formSystemEl){
    _formSystemEl.addEventListener('input', markSystemDirty);
    _formSystemEl.addEventListener('change', markSystemDirty);
}
// Only warns on an actual tab close/navigation while there is a real unsaved
// edit — never fires just because the settings modal happens to be open.
window.addEventListener('beforeunload', function(e){
    if(_systemDirty){ e.preventDefault(); e.returnValue = ''; }
});

/* ── DASHBOARD DATA: counts + bell + System Logs, all from dashboard_counts.php ──
   System Logs is rendered from the backend `feed_full` data. Each row carries
   the real actor and a specific source label (for example Student Evaluation ·
   Faculty, Peer Evaluation · Teacher, or Personnel Registry · Role Update).
   Nothing here is hardcoded — every row rendered comes from that feed; the
   helpers below only add presentation: a severity color for the action dot
   (using an optional `severity` field from the backend, or `color` if the
   backend already supplies one, with a sensible type-based fallback). */
let _notifOpen=false,_prevCounts={};
function escH(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

const SEVERITY_COLORS = { green:'#0F9F6E', yellow:'#C77A08', red:'#D6455D', blue:'#8B5E15', info:'#8B5E15' };
function activityColor(a){
    if(a.color) return a.color;                                  // backend-supplied color wins
    if(a.severity && SEVERITY_COLORS[a.severity]) return SEVERITY_COLORS[a.severity];
    return a.type==='role_change' ? '#4968C8' : '#8B5E15';        // fallback: violet for people, blue for automation
}

function toggleNotifDropdown(e){
    e.stopPropagation();_notifOpen=!_notifOpen;
    document.getElementById('notifDropdown').classList.toggle('show',_notifOpen);
}
// Clicking an "X evaluated Y" notification jumps straight to that person's
// Evaluation Report: switches to the Evaluation Reports tab and points its
// iframe at admin_analytics.php's per-target view (view=students&target_id=).
function goToEvaluationReport(targetUserId, analyticsTab){
    if(!targetUserId) return;
    const frame = document.getElementById('analyticsFrame');
    if(frame){
        const params = new URLSearchParams({ view:'students', target_id:targetUserId, eval_type:analyticsTab||'student' });
        frame.src = 'admin_analytics.php?' + params.toString();
    }
    showPage('analytics', document.getElementById('link-analytics'));
    _notifOpen=false;
    document.getElementById('notifDropdown')?.classList.remove('show');
}
// Clicking a role-change or new-account notification jumps to that account
// in Account Management, scrolled to and flashed via ?highlight=.
// manage_privileged_accounts.php only manages these five roles under these
// three statuses — anything else (e.g. an EA/superadmin account) has no
// page to land on, so those notifications are left non-clickable.
const ACCOUNT_MGMT_ROLES = ['principal','dean','teacher','staff','student'];
const ACCOUNT_MGMT_STATUSES = ['pending','approved','blocked'];
function goToAccountManagement(userId, role, status){
    if(!userId || !ACCOUNT_MGMT_ROLES.includes(role) || !ACCOUNT_MGMT_STATUSES.includes(status)) return;
    const frame = document.getElementById('registrationsFrame');
    if(frame){
        const params = new URLSearchParams({ role:role, status:status, highlight:userId });
        frame.src = 'manage_privileged_accounts.php?' + params.toString();
    }
    showPage('registrations', document.getElementById('link-registrations'));
    _notifOpen=false;
    document.getElementById('notifDropdown')?.classList.remove('show');
}
// Delegated click/keyboard handling for notification items (set up once —
// #notifList's innerHTML is replaced on every poll, so listeners bound
// directly to .notif-item would be lost each refresh anyway). Reading
// target/role/status back off data-* attributes, rather than building an
// inline onclick="..." string with JSON.stringify()'d arguments, sidesteps
// the quoting bug that made these items unclickable: JSON.stringify() wraps
// string args in double quotes, and those close the onclick="..." attribute
// early, silently truncating the handler into broken JS.
(function(){
    const list = document.getElementById('notifList');
    if(!list) return;
    function activate(item){
        const action = item.getAttribute('data-action');
        if(action==='report'){
            goToEvaluationReport(item.getAttribute('data-target-user-id'), item.getAttribute('data-analytics-tab'));
        } else if(action==='account'){
            goToAccountManagement(item.getAttribute('data-account-user-id'), item.getAttribute('data-account-role'), item.getAttribute('data-account-status'));
        }
    }
    list.addEventListener('click', function(e){
        const item = e.target.closest('.notif-item[data-action]');
        if(item) activate(item);
    });
    list.addEventListener('keydown', function(e){
        if(e.key !== 'Enter') return;
        const item = e.target.closest('.notif-item[data-action]');
        if(item) activate(item);
    });
})();
function markAllRead(){
    fetch('dashboard_counts.php?mark_read=1',{credentials:'same-origin'}).then(r=>r.ok?r.json():null).then(d=>{
        document.getElementById('notifBadge').classList.remove('show');
        document.getElementById('notifBtn').classList.remove('has-unread');
        if(d)renderDashboard(d); // repaint immediately with the fresh unread count
    }).catch(()=>{});
}

function renderTrend(id,delta){
    const el=document.getElementById(id);if(!el)return;
    if(delta===undefined||delta===null||delta===0){
        el.className='pbi-stat-trend flat';
        el.innerHTML='<i class="fa-solid fa-minus"></i> No change';
        return;
    }
    const up=delta>0;
    el.className='pbi-stat-trend '+(up?'up':'flat');
    el.innerHTML=`<i class="fa-solid ${up?'fa-arrow-up':'fa-arrow-down'}"></i> ${up?'+':''}${delta} this period`;
}

function renderCounts(counts){
    if(!counts)return;
    const map=[
        ['cnt-total',counts.total],['cnt-evals',counts.evals],
        ['cnt-faculty',counts.faculty],['cnt-students',counts.students],
        ['cnt-teacher',counts.teacher],['cnt-staff',counts.staff],['cnt-student',counts.students]
    ];
    map.forEach(([id,val])=>{
        const el=document.getElementById(id);if(!el||val===undefined)return;
        if(_prevCounts[id]!==undefined&&_prevCounts[id]!==val){el.classList.remove('count-updated');void el.offsetWidth;el.classList.add('count-updated');el.addEventListener('animationend',()=>el.classList.remove('count-updated'),{once:true});}
        el.textContent=val;_prevCounts[id]=val;
    });
    if(counts.submission_pct!==undefined){
        const fill=document.getElementById('progress-fill'),pct=document.getElementById('progress-pct'),note=document.getElementById('progress-note');
        if(fill)fill.style.width=Math.min(100,counts.submission_pct)+'%';
        if(pct)pct.textContent=counts.submission_pct+'%';
        if(note&&counts.submitted!==undefined&&counts.students!==undefined)note.textContent=counts.submitted+' of '+counts.students+' active students submitted';
        const completionNote=document.getElementById('completion-note');
        if(completionNote&&counts.submitted!==undefined&&counts.students!==undefined)completionNote.textContent=counts.submitted+' of '+counts.students+' active students submitted';
        const submittedTile=document.getElementById('cnt-submitted-tile');
        if(submittedTile&&counts.submitted!==undefined)submittedTile.textContent=counts.submitted;
        const pendingTile=document.getElementById('cnt-pending-tile');
        if(pendingTile&&counts.pending_students!==undefined)pendingTile.textContent=counts.pending_students;
    }
    if(counts.trend_total!==undefined)renderTrend('trend-total',counts.trend_total);
    if(counts.trend_faculty!==undefined)renderTrend('trend-faculty',counts.trend_faculty);
    if(counts.trend_students!==undefined)renderTrend('trend-students',counts.trend_students);
    if(counts.trend_evals!==undefined)renderTrend('trend-evals',counts.trend_evals);
}
// Notifications are kept for the whole session: items that drop out of the
// server's latest feed are retained (below the current ones) instead of
// vanishing, so the list only ever grows and is simply scrolled.
const NOTIF_KEEP_MAX=500;
let _notifStore=[],_notifLastHtml='';
function notifKey(e,n){
    if(e.id) return 'id:'+e.id;   // server-supplied stable id
    return [e.type,e.text,e.meta,e.target_user_id||e.account_user_id||'',n].join('|');
}
function mergeNotifFeed(feed){
    const seen={},fresh=[];
    (feed||[]).forEach(e=>{
        const base=notifKey(e,''); seen[base]=(seen[base]||0)+1;
        fresh.push({k:notifKey(e,seen[base]),e:e});
    });
    const freshKeys=new Set(fresh.map(x=>x.k));
    const kept=_notifStore.filter(x=>!freshKeys.has(x.k));
    _notifStore=fresh.concat(kept).slice(0,NOTIF_KEEP_MAX);
    return _notifStore.map(x=>x.e);
}
// The signed-in user never gets a notification about their own actions
// (e.g. the EA submitting an evaluation must not notify the EA). Matched by
// actor id when the backend supplies one, otherwise by actor name, otherwise
// by the "<Me> evaluated ..." text. The System Logs table is unaffected.
const ME_ID = <?= json_encode((string)($_SESSION['user_id'] ?? '')) ?>;
const ME_NAME = <?= json_encode(trim((string)($_SESSION['full_name'] ?? ''))) ?>.toLowerCase();
function isOwnAction(e){
    if(!e) return false;
    const aid = e.actor_user_id ?? e.actor_id ?? e.evaluator_id ?? null;
    if(aid!==null && aid!=='' && ME_ID!=='' && String(aid)===ME_ID) return true;
    if(ME_NAME==='') return false;
    const actor = String(e.actor||'').trim().toLowerCase();
    if(actor===ME_NAME) return true;
    return String(e.text||'').trim().toLowerCase().startsWith(ME_NAME+' evaluated ');
}
function renderBell(feed,unreadCount){
    if(Array.isArray(feed)){
        const before=feed.length;
        feed=feed.filter(e=>!isOwnAction(e));
        unreadCount=Math.max(0,(unreadCount||0)-(before-feed.length));
    }
    const badge=document.getElementById('notifBadge'),btn=document.getElementById('notifBtn'),list=document.getElementById('notifList');
    if(unreadCount>0){badge.textContent=unreadCount>99?'99+':unreadCount;badge.classList.add('show');btn.classList.add('has-unread');}
    else{badge.classList.remove('show');btn.classList.remove('has-unread');}
    if(!feed||feed.length===0){list.innerHTML='<div class="notif-empty"><i class="fa-regular fa-bell-slash"></i>No recent activity</div>';return;}
    feed=mergeNotifFeed(feed);
    const html=feed.map(e=>{
        // Evaluation-submission notifications link to that person's report;
        // role-change/new-account ones link to their Account Management row.
        // Both require the backend to have supplied a usable id/role/status.
        const toReport = e.type==='audit' && e.target_user_id;
        const toAccount = (e.type==='role_change'||e.type==='new_user') && e.account_user_id
            && ACCOUNT_MGMT_ROLES.includes(e.account_role) && ACCOUNT_MGMT_STATUSES.includes(e.account_status);
        const clickable = toReport || toAccount;
        let dataAttrs = '';
        if (toReport) {
            dataAttrs = `data-action="report" data-target-user-id="${escH(e.target_user_id)}" data-analytics-tab="${escH(e.analytics_tab||'student')}"`;
        } else if (toAccount) {
            dataAttrs = `data-action="account" data-account-user-id="${escH(e.account_user_id)}" data-account-role="${escH(e.account_role)}" data-account-status="${escH(e.account_status)}"`;
        }
        const attrs = clickable ? `tabindex="0" role="button" ${dataAttrs}` : '';
        return `
        <div class="notif-item ${e.type==='role_change'?'unread':''} ${clickable?'clickable':''}" ${attrs}>
            <div class="notif-icon" style="color:${e.color};background:${e.color}22;"><i class="fa-solid ${e.icon}"></i></div>
            <div style="flex:1;min-width:0;"><div class="notif-text">${escH(e.text)}</div><div class="notif-meta">${escH(e.meta)} · ${e.time}</div></div>
            ${clickable?'<i class="fa-solid fa-chevron-right" style="font-size:10px;color:var(--muted);margin-top:5px;flex-shrink:0;"></i>':''}
        </div>`;
    }).join('');
    // Nothing changed since the last poll: leave the DOM (and the scroll
    // position) completely untouched.
    if(html===_notifLastHtml && list.children.length) return;
    _notifLastHtml=html;
    // Repaint, but keep the reader exactly where they were scrolled to.
    const prevTop=list.scrollTop;
    list.innerHTML=html;
    list.scrollTop=prevTop;
}
function renderLogsTable(feedFull){
    const body=document.getElementById('logsTableBody');if(!body)return;
    feedFull=feedFull||[];
    if(feedFull.length===0){
        body.innerHTML='<tr><td colspan="4" class="logs-empty">No recent activity yet.</td></tr>';
        return;
    }
    body.innerHTML=feedFull.map(a=>{
        const color=activityColor(a);
        const user=a.actor ? escH(a.actor) : '—';
        return `
        <tr>
            <td class="log-datetime">${escH(a.date||a.time||'—')}</td>
            <td><span class="log-action"><span class="log-action-dot" style="background:${color};"></span>${escH(a.text)}</span></td>
            <td class="log-user">${user}</td>
            <td class="log-details">${escH(a.meta||'')}</td>
        </tr>`;
    }).join('');
}
function renderDashboard(d){
    renderCounts(d.counts);
    renderBell(d.feed, d.unread_role_changes);
    renderLogsTable(d.feed_full);
}
let _dashboardRequest = null;
function renderDashboardLoadError(message){
    const body=document.getElementById('logsTableBody');
    if(body){
        body.innerHTML='<tr><td colspan="4" class="logs-empty logs-error">'
            +escH(message||'Unable to load recent activity.')
            +' <button type="button" class="logs-retry" onclick="refreshDashboard()">Retry</button></td></tr>';
    }
}

function refreshDashboard(){
    if(_dashboardRequest) _dashboardRequest.abort();
    const controller=new AbortController();
    _dashboardRequest=controller;
    const timeout=setTimeout(()=>controller.abort(),8000);

    fetch('dashboard_counts.php',{
        credentials:'same-origin',
        cache:'no-store',
        signal:controller.signal
    })
    .then(async r=>{
        const raw=await r.text();
        let d=null;
        try{ d=raw ? JSON.parse(raw) : null; }catch(e){
            throw new Error('The activity service returned an invalid response.');
        }
        if(!r.ok){
            throw new Error(d?.error || 'The activity service could not be reached.');
        }
        return d;
    })
    .then(d=>{
        if(d) renderDashboard(d);
    })
    .catch(err=>{
        if(err?.name==='AbortError'){
            renderDashboardLoadError('Recent activity is taking too long to load.');
        }else{
            renderDashboardLoadError(err?.message || 'Unable to load recent activity.');
        }
    })
    .finally(()=>{
        clearTimeout(timeout);
        if(_dashboardRequest===controller) _dashboardRequest=null;
    });
}

/* ── POLLING ── */
setInterval(refreshDashboard,10000);
refreshDashboard();

/* ── INIT ── */
window.onload=function(){
    const saved=localStorage.getItem('activePage')||'dashboard';
    const link=document.getElementById('link-'+saved)||document.getElementById('link-dashboard');
    showPage(saved,link);
    const compact=localStorage.getItem('pbi_compact');
    try{const a=(window.PBIAppearance?PBIAppearance.read().accent:null); if(a) markAccentSwatches(a);}catch(e){}
    if(compact==='1'){document.getElementById('togCompact').checked=true;applyCompact(true);}
    <?php if($settings_msg):?>openSettings('system');<?php endif;?>
};
</script>
<style id="topbar-force-boundary">
/* Forced last: the top bar is the opaque primary boundary for the scrolling workspace. */
html body .nude-nav,
html body nav.nude-nav{
    position:fixed!important;
    top:0!important;
    right:0!important;
    left:calc(var(--sidebar-w) * var(--sidebar-scale))!important;
    height:var(--topbar-h)!important;
    min-height:var(--topbar-h)!important;
    box-sizing:border-box!important;
    z-index:20!important;
    background:transparent!important;
    background-image:none!important;
    color:#0B1F3A!important;
    border-bottom:none!important;
    box-shadow:none!important;
    backdrop-filter:none!important;
    -webkit-backdrop-filter:none!important;
}
html body .nude-nav .brand-name,
html body .nude-nav .brand-text,
html body .nude-nav .topbar-greeting{color:#0B1F3A!important;}
html body .nude-nav .brand-role{background:var(--accent-soft-bg)!important;border-color:var(--accent-soft-border)!important;color:var(--blue-accent)!important;}
html body .nude-nav .nav-divider{background:#E8DFC4!important;}
html body .nude-nav .notif-btn{background:#F8FAFC!important;border-color:#E8DFC4!important;color:#0B1F3A!important;}
</style>
<style id="dashboard-notification-edge-final">
/* Notification stays inside the dashboard workspace and is pinned to the far-right edge. */
.nude-nav{display:none!important;}
.page-content{margin-top:0!important;padding-top:6px!important;}
.pbi-dashboard-container{padding-top:8px!important;}
/* The top bar is hidden on the dashboard, so drop the space that was reserved for it.
   admin_aura_theme.css sets margin-top:var(--topbar-h) with higher specificity, so match it here.
   To nudge the workspace lower/higher, change --dash-top-gap (currently 14px). */
:root{--dash-top-gap:14px;}
html body:has(.pbi-dashboard-container) .page-content{
    margin-top:0!important;
    padding-top:var(--dash-top-gap)!important;
    padding-bottom:var(--dash-top-gap)!important;
    scroll-margin-top:0!important;
}
/* Vertically center the dashboard when it is the active page.
   The card hugs its content (no forced full-height stretch), and any spare
   viewport height is split evenly above and below. On short screens it simply scrolls. */
html body:has(#dashboard.active) .page-content{
    box-sizing:border-box!important;
    min-height:100vh!important;
    display:flex!important;
    flex-direction:column!important;
    justify-content:center!important;
}
/* Every other tab (Questionnaire, Tracker, Reports, Accounts, Logs, Settings…) is an iframe.
   Size it to the viewport minus equal top/bottom gaps so it sits centered like the dashboard. */
html body:has(.pbi-dashboard-container) .page-content{min-height:0!important;}
html body:has(.pbi-dashboard-container) .iframe-box{
    display:block!important;
    height:calc(100vh - (var(--dash-top-gap) * 2))!important;
    margin:0 auto!important;
}
html body:has(#dashboard.active) #dashboard.page.active,
html body:has(#dashboard.active) #dashboard.active .pbi-dashboard-container{
    min-height:0!important;
    height:auto!important;
}
html body:has(.pbi-dashboard-container) .pbi-dashboard-container,
html.density-comfortable body:has(.pbi-dashboard-container) .pbi-dashboard-container{
    padding-top:8px!important;
}
.pbi-dashboard-heading{margin-bottom:12px!important;position:relative!important;}
.pbi-dashboard-meta{
    display:flex!important;
    flex-direction:row!important;
    align-items:center!important;
    justify-content:flex-end!important;
    gap:8px!important;
    flex-wrap:wrap!important;
    white-space:nowrap!important;
    padding-right:56px!important;
}
.dashboard-notification-control{
    position:absolute!important;
    top:9px!important;
    right:14px!important;
    display:flex!important;
    align-items:center!important;
    justify-content:center!important;
    margin:0!important;
    order:initial!important;
    z-index:20!important;
}
.dashboard-notification-control .notif-wrap{margin-left:0!important;}
.dashboard-notification-control .notif-btn{
    width:36px!important;
    height:36px!important;
    background:#fff!important;
    border:1px solid #E8DFC4!important;
    box-shadow:0 2px 8px rgba(30,82,144,.08)!important;
}
.dashboard-notification-control .notif-btn:hover,
.dashboard-notification-control .notif-btn.has-unread{
    background:var(--accent-soft-bg)!important;
    border-color:var(--accent-soft-border)!important;
}
@media(max-width:700px){
  .page-content{padding-top:4px!important;}
  .pbi-dashboard-meta{justify-content:flex-start!important;white-space:normal!important;padding-right:0!important;}
  .dashboard-notification-control{top:8px!important;right:8px!important;}
}
</style>

<style id="dashboard-grounded-layout">
/* ------------------------------------------------------------------
   Grounded dashboard layout (loaded last on purpose)
   - Removes the "floating" look: no vertically-centred bordered card.
   - The workspace is anchored to the top and fills the area beside the sidebar.
   - Consistent outer margins and one shared rhythm between sections.
   Tweak --dg-gutter (side margin) and --dg-gap (space between sections) below.
   ------------------------------------------------------------------ */
:root{--dg-gutter:40px;--dg-gap:20px;--dg-top:30px;--dg-max:1480px;--dg-bg:#F4F7FB;}

/* Tinted workspace edge-to-edge (light theme only; dark theme keeps its own colours) */
html:not([data-theme="dark"]) body:has(#dashboard.active){background:var(--dg-bg)!important;}

/* Anchor to the top instead of centring; let the page fill the viewport */
html body:has(#dashboard.active) .page-content{
    display:block!important;
    justify-content:flex-start!important;
    min-height:100vh!important;
    padding:var(--dg-top) var(--dg-gutter) 48px!important;
}

/* The workspace itself is no longer a bordered, rounded, floating panel */
html body #dashboard.active,
html body #dashboard.page.active{
    background:transparent!important;
    border:0!important;
    border-radius:0!important;
    box-shadow:none!important;
    overflow:visible!important;
    min-height:0!important;
    height:auto!important;
}

/* Centred content column with equal side margins */
html body:has(#dashboard.active) .pbi-dashboard-container{
    width:100%!important;
    max-width:var(--dg-max)!important;
    margin:0 auto!important;
    padding:0!important;
    min-height:0!important;
}

/* Heading gets room to breathe above the cards */
html body:has(#dashboard.active) .pbi-dashboard-heading{
    margin:0 0 26px!important;
    padding:0!important;
}
html body:has(#dashboard.active) .dashboard-notification-control{top:2px!important;right:0!important;}

/* One consistent vertical rhythm between sections */
html body:has(#dashboard.active) .pbi-stats-row{gap:var(--dg-gap)!important;margin:0 0 var(--dg-gap)!important;}
html body:has(#dashboard.active) .pbi-period-box{margin:0 0 var(--dg-gap)!important;}
html body:has(#dashboard.active) .pbi-bottom-row{gap:var(--dg-gap)!important;margin:0!important;align-items:stretch!important;}

/* Matching card surface everywhere: same radius, border and soft shadow */
html body:has(#dashboard.active) .pbi-stat-card,
html body:has(#dashboard.active) .pbi-period-box,
html body:has(#dashboard.active) .pbi-panel{
    border:1px solid #E3EAF3!important;
    border-radius:16px!important;
    background:#FFFFFF!important;
    box-shadow:0 1px 2px rgba(16,24,40,.04),0 6px 18px rgba(16,24,40,.05)!important;
}
html body:has(#dashboard.active) .pbi-period-box{padding:22px 26px!important;}
html body:has(#dashboard.active) .pbi-panel{padding:22px 24px!important;}

/* Smaller screens: tighter margins */
@media(max-width:1180px){
    :root{--dg-gutter:28px;--dg-gap:16px;}
}
@media(max-width:760px){
    :root{--dg-gutter:16px;--dg-gap:12px;--dg-top:18px;}
    html body:has(#dashboard.active) .pbi-panel,
    html body:has(#dashboard.active) .pbi-period-box{padding:16px!important;}
}
</style>


<!-- ═══ PAGE HEADER (faculty-style: accent-colored icon + page title) ═══ -->
<style id="admin-page-header">
:root{--adm-hdr-h:58px;}
.pbi-admin-topbar{
    position:fixed;top:0;right:0;left:calc(var(--sidebar-w) * var(--sidebar-scale));
    height:var(--adm-hdr-h);padding:0 28px;display:flex;align-items:center;gap:12px;
    background:#FFFFFF;border-bottom:1px solid var(--accent-soft-border,#B9E4D3);
    box-shadow:0 2px 8px rgba(22,48,76,.035);z-index:200;box-sizing:border-box;
}
.pbi-admin-topbar-icon{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;color:var(--blue-accent,#0F9F6E);font-size:22px;line-height:1;}
.pbi-admin-topbar-icon i{color:var(--blue-accent,#0F9F6E);}
.pbi-admin-topbar-title{min-width:0;font-family:'Rajdhani','Inter',Arial,sans-serif;font-size:22px;line-height:1.1;font-weight:700;letter-spacing:.25px;color:var(--text-dark,#0B1F3A);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
html[data-theme="dark"] .pbi-admin-topbar{background:var(--panel-bg,#121f33);border-bottom-color:var(--panel-border,#22344d);box-shadow:0 2px 14px rgba(0,0,0,.35);}
html[data-theme="dark"] .pbi-admin-topbar-title{color:var(--text,#e7eef8);}
.pbi-admin-topbar{justify-content:flex-start;}
.pbi-admin-topbar .pbi-topbar-notif{position:static!important;margin:0 0 0 auto!important;top:auto!important;right:auto!important;display:flex!important;align-items:center;}
.pbi-admin-topbar .notif-wrap{margin:0!important;}
.notif-dropdown{top:calc(var(--adm-hdr-h) + 6px)!important;}
/* Make room for the header (these match the specificity of the layout rules above). */
html body .page-content,
html body:has(.pbi-dashboard-container) .page-content,
html body:has(#dashboard.active) .page-content{margin-top:var(--adm-hdr-h)!important;}
html body:has(#dashboard.active) .page-content{min-height:calc(100vh - var(--adm-hdr-h))!important;}
html body:has(.pbi-dashboard-container) .iframe-box{height:calc(100vh - var(--adm-hdr-h) - (var(--dash-top-gap,14px) * 2))!important;}
@media(max-width:900px){
    .pbi-admin-topbar{left:0;padding:0 16px;}
    .pbi-admin-topbar-icon,.pbi-admin-topbar-title{font-size:20px;}
}
</style>
<header class="pbi-admin-topbar" id="pbiAdminTopbar" aria-label="Admin workspace header">
    <span class="pbi-admin-topbar-icon" aria-hidden="true"><i class="fa-solid fa-house" id="pbiAdminTopbarIcon"></i></span>
    <div class="pbi-admin-topbar-title" id="pbiAdminTopbarTitle">Dashboard</div>
    <div class="dashboard-notification-control pbi-topbar-notif">
        <div class="notif-wrap" id="notifWrap">
            <button class="notif-btn" id="notifBtn" onclick="toggleNotifDropdown(event)" title="Notifications" aria-label="Notifications">
                <i class="fa-regular fa-bell"></i>
                <span class="notif-badge" id="notifBadge">0</span>
            </button>
        </div>
    </div>
</header>
<script>
(function(){
    var META={
        dashboard:    ['fa-house','Dashboard'],
        reports:      ['fa-file-signature','Questionnaire'],
        tracker:      ['fa-satellite-dish','Evaluation Tracker'],
        ea_eval:      ['fa-clipboard-check',<?= json_encode($isExecutiveAssistant ? 'Evaluate Others' : 'EA Evaluations') ?>],
        ea_results:   ['fa-star-half-stroke','Evaluation Received'],
        analytics:    ['fa-chart-line','Evaluation Reports'],
        registrations:['fa-users-gear','Account Management'],
        system_logs:  ['fa-clock-rotate-left','System Logs'],
        settings:     ['fa-gear','Settings']
    };
    var icon=document.getElementById('pbiAdminTopbarIcon'),title=document.getElementById('pbiAdminTopbarTitle');
    function update(id){
        var m=META[id]||META.dashboard;
        if(icon) icon.className='fa-solid '+m[0];
        if(title) title.textContent=m[1];
    }
    if(typeof _showPageNow==='function'){
        var orig=_showPageNow;
        _showPageNow=function(pageId,element){ orig(pageId,element); update(pageId); };
    }
    try{ update(localStorage.getItem('activePage')||'dashboard'); }catch(e){ update('dashboard'); }
})();
</script>

<style id="admin-dashboard-white-panel">
/* White rounded workspace card for the dashboard, matching the Questionnaire / tracker pages. */
html body:has(#dashboard.active) .page-content{
    padding:var(--dash-top-gap,14px) 22px!important;
}
html body:has(#dashboard.active) .pbi-dashboard-container{
    max-width:none!important;
    box-sizing:border-box!important;
    background:#FFFFFF!important;
    border:1px solid #E3EAF3!important;
    border-radius:18px!important;
    box-shadow:0 1px 2px rgba(16,24,40,.04),0 6px 18px rgba(16,24,40,.05)!important;
    padding:26px 28px 30px!important;
    min-height:calc(100vh - var(--adm-hdr-h) - (var(--dash-top-gap,14px) * 2))!important;
}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-dashboard-container{
    background:var(--panel-bg,#121f33)!important;
    border-color:var(--panel-border,#22344d)!important;
    box-shadow:0 6px 22px rgba(0,0,0,.35)!important;
}
@media(max-width:760px){
    html body:has(#dashboard.active) .page-content{padding:var(--dash-top-gap,14px) 12px!important;}
    html body:has(#dashboard.active) .pbi-dashboard-container{padding:16px!important;border-radius:14px!important;}
}
</style>
<style id="admin-unified-workspace">
/* Every sidebar tab now uses the same workspace as the Dashboard:
   soft #F4F7FB canvas + one white, rounded, bordered card.  For the iframe
   tabs the <iframe> itself is that card (its inner look comes from
   admin_unified_ui.css, loaded by each feature page). */
html:not([data-theme="dark"]) body:has(.pbi-dashboard-container){background:#F4F7FB!important;}
html body:has(.pbi-dashboard-container) .page-content{
    padding:var(--dash-top-gap,14px) 22px!important;
    margin-top:var(--adm-hdr-h)!important;
}
html body .page:has(>iframe){background:transparent!important;}
html body .iframe-box{
    background:#FFFFFF!important;
    border:1px solid #E3EAF3!important;
    border-radius:18px!important;
    box-shadow:0 1px 2px rgba(16,24,40,.04),0 6px 18px rgba(16,24,40,.05)!important;
    box-sizing:border-box!important;
}
html[data-theme="dark"] body .iframe-box{
    background:var(--panel-bg,#121f33)!important;
    border-color:var(--panel-border,#22344d)!important;
    box-shadow:0 6px 22px rgba(0,0,0,.35)!important;
}
@media(max-width:760px){
    html body:has(.pbi-dashboard-container) .page-content{padding:var(--dash-top-gap,14px) 12px!important;}
    html body .iframe-box{border-radius:14px!important;}
}
</style>

<style id="dashboard-polish">
/* ------------------------------------------------------------------
   Dashboard polish (loaded after the layout blocks, before dark mode)
   Goals: cleaner spacing, readable type sizes, higher contrast.
   - Undoes the 8-9px "compact" text so nothing needs squinting.
   - Layout rules apply to both themes; colour rules are light-theme only
     (dark mode keeps the palette from #dashboard-dark-mode).
   - Pure presentation: no markup, IDs, PHP or JS were changed.
   ------------------------------------------------------------------ */

/* Scroll inside the card, like the Evaluation Tracker: the page itself stays
   locked (header, sidebar and card frame never move) and only the dashboard
   content scrolls, with a slim visible scrollbar. Desktop only; tablets and
   phones keep normal page scrolling. */
@media(min-width:901px){
    html body:has(#dashboard.active) #dashboard.active .pbi-dashboard-container{
        height:auto!important;
        min-height:0!important;
        overflow:visible!important;
        scrollbar-width:auto;
        scrollbar-color:#8FA2BC #E4EAF3;
    }
    html body:has(#dashboard.active) .pbi-dashboard-container::-webkit-scrollbar{width:12px;display:block!important;}
    html body:has(#dashboard.active) .pbi-dashboard-container::-webkit-scrollbar-track{background:#E4EAF3;border-radius:8px;margin:14px 0;}
    html body:has(#dashboard.active) .pbi-dashboard-container::-webkit-scrollbar-thumb{background:#8FA2BC;border-radius:8px;border:2px solid #E4EAF3;}
    html body:has(#dashboard.active) .pbi-dashboard-container::-webkit-scrollbar-thumb:hover{background:#6C819F;}
    html[data-theme="dark"] body:has(#dashboard.active) .pbi-dashboard-container{scrollbar-color:#4A6488 #1B2E48;}
    html[data-theme="dark"] body:has(#dashboard.active) .pbi-dashboard-container::-webkit-scrollbar-track{background:#1B2E48;}
    html[data-theme="dark"] body:has(#dashboard.active) .pbi-dashboard-container::-webkit-scrollbar-thumb{background:#4A6488;border-color:#1B2E48;}
    html[data-theme="dark"] body:has(#dashboard.active) .pbi-dashboard-container::-webkit-scrollbar-thumb:hover{background:#6384B0;}
}

/* ── KPI cards ───────────────────────────────────────────── */
html body:has(#dashboard.active) .pbi-stats-row{gap:18px!important;margin:0 0 18px!important;}
html body:has(#dashboard.active) .pbi-stats-row > .pbi-stat-card{
    height:auto!important;min-height:108px!important;padding:20px 22px!important;
    border-radius:14px!important;overflow:visible!important;
}
html body:has(#dashboard.active) .pbi-stats-row > .pbi-stat-card:not(.pbi-completion-card){
    grid-template-columns:48px minmax(0,1fr)!important;column-gap:16px!important;row-gap:3px!important;
}
html body:has(#dashboard.active) .pbi-stats-row > .pbi-stat-card:not(.pbi-completion-card) .pbi-stat-icon-circle{
    width:48px!important;height:48px!important;border-radius:13px!important;font-size:19px!important;
}
html body:has(#dashboard.active) .pbi-stats-row > .pbi-stat-card .pbi-stat-label{
    font-size:11.5px!important;font-weight:700!important;letter-spacing:.7px!important;line-height:1.25!important;
}
html body:has(#dashboard.active) .pbi-stats-row > .pbi-stat-card:not(.pbi-completion-card) .pbi-stat-value{
    font-size:30px!important;font-weight:800!important;line-height:1.1!important;margin:1px 0 2px!important;
}
html body:has(#dashboard.active) .pbi-stats-row > .pbi-stat-card:not(.pbi-completion-card) .pbi-stat-sub{
    display:block!important;max-width:100%!important;font-size:12px!important;font-weight:500!important;line-height:1.3!important;
}
/* The "–" placeholder trend adds noise; it still appears if JS ever marks it up/down. */
html body:has(#dashboard.active) .pbi-stats-row > .pbi-stat-card .pbi-stat-trend.flat{display:none!important;}

html body:has(#dashboard.active) .pbi-stats-row > .pbi-completion-card{gap:16px!important;}
html body:has(#dashboard.active) .pbi-stats-row > .pbi-completion-card .pbi-completion-ring{width:70px!important;height:70px!important;}
html body:has(#dashboard.active) .pbi-stats-row > .pbi-completion-card .pbi-completion-ring-inner{width:54px!important;height:54px!important;font-size:15px!important;font-weight:800!important;}
html body:has(#dashboard.active) .pbi-stats-row > .pbi-completion-card .pbi-stat-label{font-size:11.5px!important;margin-bottom:4px!important;}
html body:has(#dashboard.active) .pbi-stats-row > .pbi-completion-card .pbi-stat-sub{font-size:12px!important;font-weight:500!important;white-space:normal!important;line-height:1.35!important;}

/* ── Current status panel ────────────────────────────────── */
html body:has(#dashboard.active) .pbi-period-box{padding:26px 30px!important;}
html body:has(#dashboard.active) .pbi-period-label{font-size:12px!important;font-weight:700!important;letter-spacing:.9px!important;display:flex;align-items:center;gap:10px;}
html body:has(#dashboard.active) .pbi-period-badge{font-size:11.5px!important;font-weight:700!important;padding:4px 11px!important;border-radius:999px!important;}
html body:has(#dashboard.active) .pbi-period-value{font-size:25px!important;font-weight:800!important;line-height:1.25!important;margin:8px 0 10px!important;}
html body:has(#dashboard.active) .pbi-period-meta{font-size:13.5px!important;font-weight:500!important;display:flex;align-items:center;flex-wrap:wrap;gap:8px 14px;}
html body:has(#dashboard.active) .pbi-period-meta i{margin-right:6px!important;}
html body:has(#dashboard.active) .pbi-period-tiles{gap:16px!important;margin-top:20px!important;}
html body:has(#dashboard.active) .pbi-period-tile{padding:16px 18px!important;border-radius:12px!important;}
html body:has(#dashboard.active) .pbi-period-tile-label{font-size:12.5px!important;font-weight:600!important;margin-bottom:8px!important;}
html body:has(#dashboard.active) .pbi-period-tile-value{font-size:32px!important;font-weight:800!important;line-height:1!important;}
html body:has(#dashboard.active) .pbi-period-tile-sub{font-size:12px!important;margin-top:8px!important;line-height:1.3!important;}
html body:has(#dashboard.active) .pbi-progress-track{height:8px!important;border-radius:999px!important;margin-top:12px!important;}
html body:has(#dashboard.active) .pbi-progress-fill{border-radius:999px!important;}

/* ── Bottom panels ───────────────────────────────────────── */
html body:has(#dashboard.active) .pbi-panel{padding:26px 28px!important;}
html body:has(#dashboard.active) .pbi-panel-head{margin-bottom:10px!important;min-height:36px;}
html body:has(#dashboard.active) .pbi-panel-title{font-size:12.5px!important;font-weight:700!important;letter-spacing:.9px!important;}
html body:has(#dashboard.active) .pbi-panel-viewall{font-size:12.5px!important;font-weight:700!important;padding:7px 14px!important;border-radius:8px!important;}

html body:has(#dashboard.active) .pbi-personnel-item{padding:15px 0!important;gap:15px!important;}
html body:has(#dashboard.active) .pbi-personnel-icon{width:46px!important;height:46px!important;font-size:18px!important;}
html body:has(#dashboard.active) .pbi-personnel-name{font-size:15.5px!important;font-weight:700!important;line-height:1.25!important;}
html body:has(#dashboard.active) .pbi-personnel-meta{font-size:12.5px!important;font-weight:500!important;margin-top:2px!important;}
html body:has(#dashboard.active) .pbi-personnel-count{font-size:24px!important;font-weight:800!important;}

html body:has(#dashboard.active) .pbi-attention-item{padding:15px 10px!important;margin:0 -10px!important;gap:12px!important;font-size:14px!important;font-weight:500!important;line-height:1.45!important;align-items:center!important;border-radius:10px!important;}
html body:has(#dashboard.active) .pbi-attention-item i.dot{font-size:16px!important;margin-top:0!important;width:20px;text-align:center;}
html body:has(#dashboard.active) .pbi-attention-item .chev{font-size:12px!important;opacity:.85!important;}

/* ── Sidebar legibility (dark in both themes) ────────────── */
.pbi-sidebar .nav-item{color:#D2DDEC!important;}
.pbi-sidebar .nav-item:hover,.pbi-sidebar .nav-item.active{color:#FFFFFF!important;}
.pbi-sidebar .nav-menu .nav-section-label{color:#AFC0D8!important;}
.pbi-sidebar .sidebar-profile-role{color:#F5D56A!important;}

/* ══ LIGHT THEME COLOURS (higher contrast) ═════════════════ */
html:not([data-theme="dark"]) body:has(#dashboard.active){
    --dp-ink:#0B1F3A;      /* primary text */
    --dp-sub:#3E5068;      /* labels */
    --dp-meta:#4A5C74;     /* captions */
    --dp-line:#D3DCE8;     /* borders */
    --dp-divider:#DCE4EF;  /* list dividers */
    background:#E6ECF4!important;
}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-dashboard-container{
    background:#F3F6FB!important;border-color:#D3DCE8!important;
}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-stat-card,
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-period-box,
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-panel{
    background:#FFFFFF!important;border:1px solid var(--dp-line)!important;
    box-shadow:0 1px 2px rgba(11,31,58,.07),0 6px 16px rgba(11,31,58,.06)!important;
}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-stat-card:hover{box-shadow:0 2px 4px rgba(11,31,58,.08),0 12px 26px rgba(11,31,58,.10)!important;}

html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-stat-label,
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-period-label,
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-panel-title,
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-period-tile-label{color:var(--dp-sub)!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-stat-sub,
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-period-meta,
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-period-tile-sub,
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-personnel-meta{color:var(--dp-meta)!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-period-meta .sep{color:#9AABC0!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-stat-value,
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-period-value,
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-period-tile-value,
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-personnel-name,
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-personnel-count,
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-attention-item{color:var(--dp-ink)!important;}

/* Icon chips: stronger tint + deeper glyph */
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-stat-card.stat-teal .pbi-stat-icon-circle,
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-personnel-icon.teal{background:#D3F0E2!important;color:#087548!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-stat-card.stat-skyblue .pbi-stat-icon-circle,
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-personnel-icon.skyblue{background:#F8E8B0!important;color:#7A5C00!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-stat-card.stat-violet .pbi-stat-icon-circle,
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-personnel-icon.violet{background:#E3D9FB!important;color:#5521B5!important;}

/* Status badge + tiles */
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-period-badge.green{background:#D6F2E4!important;color:#0A6A3B!important;border:1px solid #8ED0AD!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-period-badge.red{background:#FBE0E0!important;color:#A11F2E!important;border:1px solid #EDA3AA!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-period-badge.yellow{background:#FCEBC6!important;color:#7A4B00!important;border:1px solid #E8C374!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-period-tile{background:#F7F9FC!important;border:1px solid #D3DCE8!important;box-shadow:none!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-period-tile.tile-progress{background:#FFF8E2!important;border-color:#E6D294!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-period-tile.tile-submitted{background:#E4F6EC!important;border-color:#A6DABF!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-period-tile.tile-pending{background:#FFF1D4!important;border-color:#EBCB88!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-period-tile-value.green{color:#0A6A3B!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-period-tile-value.gold{color:#8A5300!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-progress-track{background:#E7DDB8!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-progress-fill{background:linear-gradient(90deg,#B98A00,#E4B52A)!important;}

/* Participation ring */
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-completion-ring{background:conic-gradient(#C08F00 calc(var(--pct)*1%),#E4E9F1 0)!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-completion-ring-inner{background:#FFFFFF!important;color:var(--dp-ink)!important;}

/* Lists */
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-personnel-item,
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-attention-item{border-bottom:1px solid var(--dp-divider)!important;box-shadow:none!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-personnel-item:last-child,
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-attention-item:last-child{border-bottom:none!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-attention-item.is-clickable:hover{background:#EAF1FB!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-attention-item .warn{color:#B53512!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-attention-item .info{color:#A66F00!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-attention-item .ok{color:#0A7A47!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-attention-item .chev{color:#6B7E96!important;}

/* View All button */
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-panel-viewall{background:#FFFFFF!important;color:#0A6A3B!important;border:1px solid #8ED0AD!important;}
html:not([data-theme="dark"]) body:has(#dashboard.active) .pbi-panel-viewall:hover{background:#E4F6EC!important;}

/* Narrow screens: stack the tiles and relax the sizes */
@media(max-width:1180px){
    html body:has(#dashboard.active) .pbi-stats-row{grid-template-columns:repeat(2,minmax(0,1fr))!important;}
}
@media(max-width:760px){
    html body:has(#dashboard.active) .pbi-stats-row{grid-template-columns:1fr!important;gap:12px!important;}
    html body:has(#dashboard.active) .pbi-period-box,
    html body:has(#dashboard.active) .pbi-panel{padding:18px!important;}
    html body:has(#dashboard.active) .pbi-period-value{font-size:20px!important;}
}
</style>

<style id="dashboard-dark-mode">
/* ------------------------------------------------------------------
   Dashboard dark mode (loaded last on purpose).
   The grounded-layout block above forces white card surfaces in every theme,
   which left light text on white in dark mode. These rules re-colour the
   dashboard cards, status panel and lists to match the other dark pages.
   Light theme is untouched: every rule here is scoped to data-theme="dark".
   ------------------------------------------------------------------ */
html[data-theme="dark"] body:has(#dashboard.active){
    --dd-card:#172A45;      /* cards / panels sitting on the dark workspace */
    --dd-inset:#0F1F3D;     /* tiles and tracks inside a card */
    --dd-line:#284260;      /* borders and dividers */
    --dd-text:#E7ECF3;      /* primary text */
    --dd-muted:#9FB2C9;     /* labels and captions */
}

/* Card surfaces */
html[data-theme="dark"] body:has(#dashboard.active) .pbi-stat-card,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-period-box,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-panel{
    background:var(--dd-card)!important;
    border-color:var(--dd-line)!important;
    box-shadow:0 6px 18px rgba(0,0,0,.28)!important;
}

/* Muted text */
html[data-theme="dark"] body:has(#dashboard.active) .pbi-stat-label,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-stat-sub,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-stat-trend.flat,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-period-label,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-period-meta,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-period-tile-label,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-period-tile-sub,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-panel-title,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-personnel-meta,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-attention-item .chev{
    color:var(--dd-muted)!important;
}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-period-meta .sep{color:#5B7391!important;}

/* Primary text */
html[data-theme="dark"] body:has(#dashboard.active) .pbi-stat-value,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-period-value,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-period-tile-value,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-personnel-name,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-personnel-count,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-attention-item,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-completion-ring-inner{
    color:var(--dd-text)!important;
}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-period-tile-value.green{color:#52E49A!important;}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-period-tile-value.gold{color:#F4B73F!important;}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-stat-trend.up{color:#52E49A!important;}

/* Status badge (Forced Open / Closed / Scheduled / ...) */
html[data-theme="dark"] body:has(#dashboard.active) .pbi-period-badge.green{background:rgba(15,159,110,.16)!important;color:#52E49A!important;border-color:rgba(82,228,154,.38)!important;}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-period-badge.red{background:rgba(214,69,93,.16)!important;color:#FF8FA1!important;border-color:rgba(255,143,161,.38)!important;}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-period-badge.yellow{background:rgba(199,122,8,.18)!important;color:#F4B73F!important;border-color:rgba(244,183,63,.38)!important;}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-period-badge.gray{background:rgba(159,178,201,.12)!important;color:var(--dd-muted)!important;border-color:var(--dd-line)!important;}

/* Status tiles inside the period box */
html[data-theme="dark"] body:has(#dashboard.active) .pbi-period-tile{
    background:var(--dd-inset)!important;
    border:1px solid var(--dd-line)!important;
}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-period-tile.tile-submitted{background:rgba(15,159,110,.14)!important;border-color:rgba(82,228,154,.30)!important;}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-period-tile.tile-pending{background:rgba(199,122,8,.16)!important;border-color:rgba(244,183,63,.30)!important;}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-progress-track{background:#22385A!important;}

/* Participation ring */
html[data-theme="dark"] body:has(#dashboard.active) .pbi-completion-ring{
    background:conic-gradient(#EAB308 calc(var(--pct)*1%), #2A405C 0)!important;
}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-completion-ring-inner{background:var(--dd-card)!important;}

/* Icon circles */
html[data-theme="dark"] body:has(#dashboard.active) .pbi-stat-card.stat-teal .pbi-stat-icon-circle,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-personnel-icon.teal{background:rgba(15,159,110,.20)!important;color:#3DDC97!important;}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-stat-card.stat-skyblue .pbi-stat-icon-circle,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-personnel-icon.skyblue{background:rgba(201,162,39,.20)!important;color:#E3B93C!important;}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-stat-card.stat-violet .pbi-stat-icon-circle,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-personnel-icon.violet{background:rgba(139,108,246,.22)!important;color:#A995FF!important;}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-stat-card.stat-gold .pbi-stat-icon-circle{background:rgba(199,122,8,.22)!important;color:#F4B73F!important;}

/* Personnel overview + Needs attention lists */
html[data-theme="dark"] body:has(#dashboard.active) .pbi-personnel-item,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-attention-item{border-bottom-color:var(--dd-line)!important;box-shadow:none!important;}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-attention-item:last-child,
html[data-theme="dark"] body:has(#dashboard.active) .pbi-personnel-item:last-child{border-bottom:none!important;}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-attention-item.is-clickable:hover{background:rgba(255,255,255,.05)!important;}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-attention-item.is-clickable:hover .chev{color:#52E49A!important;}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-attention-item .warn{color:#FF8F6B!important;}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-attention-item .info{color:#E3B93C!important;}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-attention-item .ok{color:#3DDC97!important;}

/* View All button */
html[data-theme="dark"] body:has(#dashboard.active) .pbi-panel-viewall{
    background:transparent!important;
    color:#52E49A!important;
    border-color:var(--dd-line)!important;
}
html[data-theme="dark"] body:has(#dashboard.active) .pbi-panel-viewall:hover{background:rgba(15,159,110,.16)!important;}
</style>
<script src="eval_status_poll.js" defer></script>

<!-- EA evaluation popup: lives at the top level so the blur covers the whole screen (sidebar + header included). -->
<style>
.ea-eval-overlay{position:fixed;inset:0;z-index:1000;display:none;align-items:center;justify-content:center;padding:10px 20px;background:rgba(9,17,30,.64);-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px)}
.ea-eval-overlay.open{display:flex}
.ea-eval-wrap{width:100%;max-width:780px;height:96vh;max-height:calc(100vh - 20px)}
.ea-eval-wrap iframe{width:100%;height:100%;border:0;background:transparent;border-radius:18px}
@media(max-width:700px){.ea-eval-overlay{padding:10px}}
</style>
<div class="ea-eval-overlay" id="eaEvalOverlay" aria-hidden="true">
  <div class="ea-eval-wrap"><iframe id="eaEvalPopupFrame" title="Evaluation form" allowtransparency="true"></iframe></div>
</div>
<script>
(function(){
  var overlay=document.getElementById('eaEvalOverlay'), frame=document.getElementById('eaEvalPopupFrame');
  window.openEvalModal=function(url){
    frame.src=url; overlay.classList.add('open'); overlay.setAttribute('aria-hidden','false');
    document.body.style.overflow='hidden';
  };
  window.closeEvalModal=function(){
    overlay.classList.remove('open'); overlay.setAttribute('aria-hidden','true');
    document.body.style.overflow=''; frame.src='about:blank';
  };
  // After a successful submit: close the popup and refresh the Evaluate Others list.
  window.pbiEvalDone=function(url){
    window.closeEvalModal();
    var list=document.getElementById('eaEvalFrame'); if(list) list.src=url;
  };
  overlay.addEventListener('click',function(e){ if(e.target===overlay) window.closeEvalModal(); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape'&&overlay.classList.contains('open')) window.closeEvalModal(); });
})();
</script>
</body>
</html>
<?php if($mysqli->ping())$mysqli->close();?>