<?php
/* Evaluate Others: per-group progress bar + call to action for the picker cards. */
if (!function_exists('fx_group_progress')) {
    function fx_group_progress(int $n, int $done): string {
        $out = '';
        if ($n > 0) {
            $pct = (int) round($done / $n * 100);
            $out .= '<div class="eval-group-progress' . ($pct === 100 ? ' complete' : '') . '" role="img" aria-label="' . $done . ' of ' . $n . ' evaluated">'
                  . '<span class="eval-group-bar"><i style="width:' . $pct . '%"></i></span>'
                  . '<span class="eval-group-progress-text"><strong>' . $done . '</strong> of ' . $n . ' evaluated</span></div>';
        } else {
            $out .= '<div class="eval-group-progress-text empty">No eligible members</div>';
        }
        return $out . '<span class="eval-group-cta"><span class="cta-select">Select group <i class="fa-solid fa-arrow-right"></i></span><span class="cta-selected"><i class="fa-solid fa-check"></i> Selected</span></span>';
    }
}

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
require_once '../shared/eligibility.php';
require_once '../shared/EvaluationContextService.php';
require_once '../shared/system_settings_service.php';
require_once '../shared/QuestionnaireService.php';
require_once '../shared/notification_message.php';
require_once __DIR__ . '/notification_links.php';
require_once __DIR__ . '/kept_feedback.php';
require_once __DIR__ . '/received_results.php';

// ── Current-period helpers (same rule the Dean dashboard uses) ───────────────
// A period that System Archive has archived is never "current", even if its is_active flag is
// on, and when several periods are active the one System Settings points to wins. The archive
// table is created lazily by System Archive, so every check is skipped when it does not exist.
if (!function_exists('pbi_not_archived_sql')) {
    function pbi_not_archived_sql(mysqli $db, string $alias = 'evaluation_periods'): string {
        static $hasArchive = null;
        if ($hasArchive === null) {
            try { $r = $db->query("SHOW TABLES LIKE 'system_archives'"); $hasArchive = (bool)($r && $r->num_rows > 0); }
            catch (Throwable $e) { $hasArchive = false; }
        }
        return $hasArchive
            ? " AND NOT EXISTS (SELECT 1 FROM system_archives sa WHERE sa.period_id={$alias}.id AND sa.status='archived')"
            : '';
    }
}
if (!function_exists('pbi_current_period_tail')) {
    function pbi_current_period_tail(mysqli $db): string {
        $pid = 0;
        try {
            if (function_exists('get_system_settings')) { $st = get_system_settings($db); $pid = (int)($st['period_id'] ?? 0); }
        } catch (Throwable $e) { $pid = 0; }
        return pbi_not_archived_sql($db) . ($pid > 0 ? " ORDER BY (id=" . $pid . ") DESC, id DESC" : " ORDER BY id DESC");
    }
}
qn_migrate_legacy_once($mysqli);

if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: faculty_login.php"); exit;
}

$user_id     = $_SESSION['user_id'];
$full_name   = $_SESSION['full_name'];
$designation = $_SESSION['designation'] ?? 'Teacher';
$page        = $_GET['page'] ?? 'dashboard';

// ── SECURITY QUESTIONS STATUS (for the Settings card) ────────
// none = not set up, ok = 3 current questions saved, retired = one of them is no longer offered.
$sq_status = 'none';
$sq_existing = [];
if ($page === 'settings') {
    try {
        require_once __DIR__ . '/security.php';
        security_ensure_tables($mysqli);
        $sq_existing = sq_load($mysqli, (int)$user_id);
        if (count($sq_existing) === 3) { $sq_status = sq_needs_update($sq_existing) ? 'retired' : 'ok'; }
    } catch (Throwable $e) { error_log('settings security-questions status failed: ' . $e->getMessage()); }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ── SECURITY QUESTIONS POPUP (AJAX, returns JSON) ─────────────
// Step 1 (sq_verify=1) only checks the current password; step 2 checks it again and saves.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['sq_ajax'] ?? '') === '1') {
    $sqOut = ['ok' => false, 'message' => 'Something went wrong. Please try again.'];
    try {
        if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
            $sqOut['message'] = 'Your session expired. Please reload the page and try again.';
        } else {
            require_once __DIR__ . '/security.php';
            security_ensure_tables($mysqli);
            $sqUid  = (int)$user_id;
            $sqRole = 'teacher';
            $sqKind = 'sqchange_' . $sqRole;
            $sqSt = $mysqli->prepare("SELECT username, password_hash FROM users WHERE id = ? AND role = ? AND is_active = 1 LIMIT 1");
            $sqSt->bind_param('is', $sqUid, $sqRole);
            $sqSt->execute();
            $sqMe = $sqSt->get_result()->fetch_assoc();
            $sqSt->close();
            $sqGiven = (string)($_POST['current_password'] ?? '');
            if (!$sqMe) {
                $sqOut['message'] = 'Your account could not be found. Please log in again.';
            } elseif (auth_is_locked($mysqli, $sqKind, (string)$sqUid)) {
                $sqOut['message'] = AUTH_LOCK_MESSAGE;
            } elseif ($sqGiven === '' || !password_verify($sqGiven, (string)$sqMe['password_hash'])) {
                auth_record($mysqli, $sqKind, (string)$sqUid, false);
                $sqOut['message'] = 'Your current password is not correct.';
            } elseif (($_POST['sq_verify'] ?? '') === '1') {
                auth_clear($mysqli, $sqKind, (string)$sqUid);
                $sqOut = ['ok' => true, 'message' => 'Password confirmed.'];
            } else {
                auth_clear($mysqli, $sqKind, (string)$sqUid);
                [$sqErr, $sqRows] = function_exists('sq_validate_update')
                    ? sq_validate_update($_POST['sq_question'] ?? [], $_POST['sq_answer'] ?? [], (string)$sqMe['username'], sq_load($mysqli, $sqUid))
                    : sq_validate($_POST['sq_question'] ?? [], $_POST['sq_answer'] ?? [], (string)$sqMe['username']);
                if ($sqErr !== null) {
                    $sqOut['message'] = $sqErr;
                } else {
                    $mysqli->begin_transaction();
                    try {
                        sq_save($mysqli, $sqUid, $sqRows);
                        $mysqli->commit();
                        $_SESSION['toast'] = 'Security questions updated successfully.';
                        $sqOut = ['ok' => true, 'message' => 'Security questions updated successfully.'];
                    } catch (Throwable $e) {
                        try { $mysqli->rollback(); } catch (Throwable $ignored) {}
                        error_log('security questions save failed: ' . $e->getMessage());
                        $sqOut['message'] = 'The security questions could not be saved. Please try again.';
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('security questions popup failed: ' . $e->getMessage());
    }
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($sqOut);
    exit;
}

// ── PROFILE PHOTO UPLOAD (handled inside the authenticated portal) ──
// Posting to this dashboard keeps the user's existing portal session and
// returns them to the correct role/page after the photo is saved.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo'])) {
    $returnPage = (string)($_POST['return_page'] ?? 'profile');
    if (!in_array($returnPage, ['settings', 'profile'], true)) $returnPage = 'profile';
    $returnUrl = 'faculty_dashboard.php?page=' . rawurlencode($returnPage);

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        $_SESSION['toast_error'] = 'Your session expired. Please refresh the page and try again.';
        header('Location: ' . $returnUrl);
        exit;
    }

    $upload = $_FILES['photo'];
    $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $_SESSION['toast_error'] = in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'Photo must be 10MB or smaller.'
            : ($uploadError === UPLOAD_ERR_NO_FILE ? 'Please choose a photo to upload.' : 'Photo upload failed. Please try again.');
        header('Location: ' . $returnUrl);
        exit;
    }

    if (!is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
        $_SESSION['toast_error'] = 'The uploaded photo could not be verified. Please try again.';
        header('Location: ' . $returnUrl);
        exit;
    }
    if ((int)($upload['size'] ?? 0) > 10 * 1024 * 1024) {
        $_SESSION['toast_error'] = 'Photo must be 10MB or smaller.';
        header('Location: ' . $returnUrl);
        exit;
    }

    $allowedPhotoTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $photoMime = '';
    try {
        $photoInfo = new finfo(FILEINFO_MIME_TYPE);
        $photoMime = $photoInfo->file((string)$upload['tmp_name']) ?: '';
    } catch (Throwable $photoCheckError) {
        $photoMime = '';
    }
    if (!isset($allowedPhotoTypes[$photoMime])) {
        $_SESSION['toast_error'] = 'Please upload a JPG, PNG, WEBP, or GIF image.';
        header('Location: ' . $returnUrl);
        exit;
    }

    $photoDir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR;
    if (!is_dir($photoDir) && !@mkdir($photoDir, 0755, true)) {
        $_SESSION['toast_error'] = 'The profile photo folder is not available.';
        header('Location: ' . $returnUrl);
        exit;
    }
    if (!is_writable($photoDir)) {
        $_SESSION['toast_error'] = 'The profile photo folder is not writable.';
        header('Location: ' . $returnUrl);
        exit;
    }

    $newPhoto = 'fac_' . bin2hex(random_bytes(12)) . '.' . $allowedPhotoTypes[$photoMime];
    $newPhotoPath = $photoDir . $newPhoto;
    $photoSavedToDisk = false;
    try {
        $oldPhotoStmt = $mysqli->prepare('SELECT photo FROM users WHERE id = ? LIMIT 1');
        $oldPhotoStmt->bind_param('i', $user_id);
        $oldPhotoStmt->execute();
        $oldPhoto = (string)($oldPhotoStmt->get_result()->fetch_assoc()['photo'] ?? '');
        $oldPhotoStmt->close();

        if (!move_uploaded_file((string)$upload['tmp_name'], $newPhotoPath)) {
            throw new RuntimeException('move_uploaded_file failed');
        }
        $photoSavedToDisk = true;

        $savePhotoStmt = $mysqli->prepare('UPDATE users SET photo = ? WHERE id = ?');
        $savePhotoStmt->bind_param('si', $newPhoto, $user_id);
        if (!$savePhotoStmt->execute()) {
            $savePhotoStmt->close();
            throw new RuntimeException('Photo database update failed');
        }
        $savePhotoStmt->close();

        // Delete only files created by this upload flow, and only after the DB update succeeds.
        $managedOldPhoto = str_starts_with($oldPhoto, 'fac_') || str_starts_with($oldPhoto, 'staff_');
        if ($oldPhoto !== '' && basename($oldPhoto) === $oldPhoto && $managedOldPhoto && is_file($photoDir . $oldPhoto)) {
            @unlink($photoDir . $oldPhoto);
        }
        $_SESSION['toast'] = 'Profile photo updated successfully.';
    } catch (Throwable $photoSaveError) {
        if ($photoSavedToDisk && is_file($newPhotoPath)) @unlink($newPhotoPath);
        error_log('[faculty_dashboard.php] Profile photo update failed for user ' . (int)$user_id . ': ' . $photoSaveError->getMessage());
        $_SESSION['toast_error'] = 'Unable to update your profile photo right now. Please try again.';
    }

    header('Location: ' . $returnUrl);
    exit;
}


function csrf_check(): bool {
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
}

// Centralized live schedule gate for Faculty/Staff evaluation access.
// Follow Schedule uses Asia/Manila and the configured [start, end) window.
// Force Open / Force Closed remain explicit overrides.
function fs_schedule_settings(mysqli $mysqli): array {
    $out = [];
    $rs = $mysqli->query("SELECT setting_key, setting_value FROM system_settings");
    if ($rs) {
        while ($row = $rs->fetch_assoc()) {
            $out[(string)$row['setting_key']] = (string)$row['setting_value'];
        }
        $rs->free();
    }
    return $out;
}

function fs_parse_manila_datetime(?string $raw): ?DateTimeImmutable {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    $tz = new DateTimeZone('Asia/Manila');
    $formats = ['Y-m-d\\TH:i:s', 'Y-m-d\\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $raw, $tz);
        if ($dt !== false) {
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors === false || ($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0) {
                return $dt;
            }
        }
    }
    try {
        return new DateTimeImmutable($raw, $tz);
    } catch (Throwable $e) {
        return null;
    }
}

function fs_schedule_is_open(mysqli $mysqli): bool {
    $s = fs_schedule_settings($mysqli);
    $mode = strtolower(trim((string)($s['control_mode'] ?? 'schedule')));

    if ($mode === 'open') return true;
    if ($mode === 'closed') return false;

    $start = fs_parse_manila_datetime($s['eval_start'] ?? '');
    $end   = fs_parse_manila_datetime($s['eval_end'] ?? '');
    if (!$start || !$end || $end <= $start) return false;

    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    return $now >= $start && $now < $end;
}


// ── FETCH PROFILE PHOTO ───────────────────────────────────────
$phRes = $mysqli->prepare("SELECT photo FROM users WHERE id=? LIMIT 1");
$phRes->bind_param("i", $user_id);
$phRes->execute();
$faculty_photo = $phRes->get_result()->fetch_assoc()['photo'] ?? '';
$phRes->close();

$account_username = '';
$userNameStmt = $mysqli->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
$userNameStmt->bind_param("i", $user_id);
$userNameStmt->execute();
$account_username = (string)($userNameStmt->get_result()->fetch_assoc()['username'] ?? '');
$userNameStmt->close();

// ── VALID SPECIFIC YEAR LEVELS ───────────────────────────────────
// Same exact list & string values as admin/manage_privileged_accounts.php
// uses for students and for teacher/staff assignment — keeping these
// identical is what lets a faculty member's picks match real students.
$year_levels = [
    'Grade 7','Grade 8','Grade 9','Grade 10',
    'Grade 11','Grade 12',
    '1st Year College','2nd Year College','3rd Year College','4th Year College',
];

// ── ENSURE user_year_levels TABLE EXISTS ────────────────────────
// Shared junction table with the admin page — a person can have more
// than one specific year level checked (e.g. Grade 8 AND Grade 10).
$mysqli->query("
    CREATE TABLE IF NOT EXISTS user_year_levels (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        year_level VARCHAR(30) NOT NULL,
        UNIQUE KEY uniq_user_year (user_id, year_level),
        CONSTRAINT fk_uyl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )
");

// ── FETCH MY ASSIGNED TEACHING LEVEL(S) ─────────────────────────
// A faculty member can be assigned to more than one SPECIFIC year
// level at once (e.g. Grade 8 AND Grade 10), so this is a list, not
// a single value.
$my_levels = [];
$lvlQ = $mysqli->prepare("SELECT year_level FROM user_year_levels WHERE user_id=?");
$lvlQ->bind_param("i", $user_id);
$lvlQ->execute();
$lvlRes = $lvlQ->get_result();
while ($lr = $lvlRes->fetch_assoc()) $my_levels[] = $lr['year_level'];
$lvlQ->close();

// Faculty peer evaluation is open to all eligible Faculty/Teaching Staff
// regardless of the year level(s) they teach. Dean/Principal eligibility below
// remains separately controlled by the evaluator's assigned year level.
$can_evaluate_faculty_peer = true;

// ── DEAN/PRINCIPAL EVALUATOR ELIGIBILITY (by own teaching assignment) ──
// Mirrors the admin-side Dean/Principal Evaluation targeting rule
// (Principal <- Faculty/Teaching Staff assigned to Grade 7-12, Dean <-
// assigned to College): a Faculty/Teaching Staff member's teaching
// assignment(s) determine which of the two they're allowed to evaluate
// here. JHS/SHS assignment -> can evaluate the Principal only. College
// assignment -> can evaluate the Dean only. Assigned to both at once ->
// can evaluate both. No assignment at all -> can evaluate neither yet.
$jhs_shs_levels          = ['Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12'];
$dean_principal_college_levels = ['1st Year College','2nd Year College','3rd Year College','4th Year College'];
$can_evaluate_principal  = (bool) array_intersect($my_levels, $jhs_shs_levels);
$can_evaluate_dean       = (bool) array_intersect($my_levels, $dean_principal_college_levels);

// ── ACTIVE PERIOD ─────────────────────────────────────────────
$period = null;
$pr = $mysqli->query("SELECT * FROM evaluation_periods WHERE is_active=1" . pbi_current_period_tail($mysqli) . " LIMIT 1");
if ($pr) $period = $pr->fetch_assoc();

// Match the Dean report's Higher Education scope: a Faculty peer submission
// must use a semester/summer period, never the globally active School Year.
$college_peer_period = null;
$cpp = $mysqli->query("SELECT * FROM evaluation_periods
    WHERE semester IN ('1st Semester','2nd Semester','Summer')" . pbi_not_archived_sql($mysqli) . "
    ORDER BY
                (CONVERT(TRIM(school_year) USING utf8mb4) COLLATE utf8mb4_unicode_ci =
                    CONVERT(TRIM((SELECT setting_value FROM system_settings WHERE setting_key='acad_year' LIMIT 1)) USING utf8mb4) COLLATE utf8mb4_unicode_ci) DESC,
                (CONVERT(TRIM(semester) USING utf8mb4) COLLATE utf8mb4_unicode_ci =
                    CONVERT(TRIM((SELECT setting_value FROM system_settings WHERE setting_key='acad_term' LIMIT 1)) USING utf8mb4) COLLATE utf8mb4_unicode_ci) DESC,
                is_active DESC,
                id DESC LIMIT 1");
if ($cpp) $college_peer_period = $cpp->fetch_assoc();

// The active-period flag is not the schedule clock. Always evaluate the
// live Asia/Manila schedule so a future period cannot appear open early.
$evaluation_open = fs_schedule_is_open($mysqli);

// ── DASHBOARD SCHEDULE SUMMARY ─────────────────────────────────────
// Read the same database-backed settings used by the admin Settings page so
// Faculty/Staff see the exact opening/closing date and time on their dashboard.
$dashboard_sys = ss_raw($mysqli);
$dashboard_structure_labels = ss_structure_labels();
$dashboard_structure = $dashboard_structure_labels[$dashboard_sys['acad_structure'] ?? ''] ?? ucfirst((string)($dashboard_sys['acad_structure'] ?? ''));
$dashboard_acad_year = trim((string)($dashboard_sys['acad_year'] ?? ($period['school_year'] ?? '')));
$dashboard_term = trim((string)($dashboard_sys['acad_term'] ?? ($period['semester'] ?? '')));
$dashboard_start_raw = trim((string)($dashboard_sys['eval_start'] ?? ''));
$dashboard_end_raw = trim((string)($dashboard_sys['eval_end'] ?? ''));
$dashboard_start = $dashboard_start_raw !== '' ? ss_parse_datetime($dashboard_start_raw) : null;
$dashboard_end = $dashboard_end_raw !== '' ? ss_parse_datetime($dashboard_end_raw) : null;
$dashboard_tz = new DateTimeZone((string)($dashboard_sys['schedule_timezone'] ?? 'Asia/Manila'));

$dashboard_status = 'Scheduled';
if (($dashboard_sys['control_mode'] ?? 'schedule') === 'open') {
    $dashboard_status = 'Open';
} elseif (($dashboard_sys['control_mode'] ?? 'schedule') === 'closed') {
    $dashboard_status = 'Closed';
} elseif ($dashboard_start && $dashboard_end) {
    $dashboard_now = new DateTimeImmutable('now', $dashboard_tz);
    if ($dashboard_now < $dashboard_start) {
        $dashboard_status = 'Scheduled';
    } elseif ($dashboard_now >= $dashboard_end) {
        $dashboard_status = 'Closed';
    } else {
        $dashboard_status = 'Open';
    }
}

$dashboard_window = 'Not configured';
if ($dashboard_start && $dashboard_end) {
    $dashboard_window = $dashboard_start->format('M j, Y') . ' – ' . $dashboard_end->format('M j, Y');
}
$dashboard_open_display = $dashboard_start ? $dashboard_start->format('M j, Y g:i A') : 'Not configured';
$dashboard_close_display = $dashboard_end ? $dashboard_end->format('M j, Y g:i A') : 'Not configured';
$dashboard_status_class = strtolower($dashboard_status);

// ── MY EVALUATION RESULTS ─────────────────────────────────────
// Mirrors staff_dashboard.php: all-time totals (not period-scoped) so the
// "Performance by Category" card behaves identically for Faculty accounts.
$my_avg   = null;
$my_total = 0;
$my_scores = [];

$live_sum = 0.0; $live_cnt = 0; $live_total = 0;
$res_stmt = $mysqli->prepare("
    SELECT AVG(qa.answer_score) as avg_score, COUNT(DISTINCT et.id) as total,
           SUM(qa.answer_score) AS sum_score, COUNT(qa.answer_score) AS cnt_score
    FROM evaluation_tracker et
    JOIN questionnaire_answers qa ON qa.tracker_id = et.id
    WHERE et.target_user_id = ?
");
$res_stmt->bind_param("i", $user_id);
$res_stmt->execute();
$res = $res_stmt->get_result();
if ($res) {
    $row      = $res->fetch_assoc();
    $my_avg   = $row['avg_score'] !== null ? round($row['avg_score'], 2) : null;
    $my_total = $row['total'] ?? 0;
    $live_sum   = (float)($row['sum_score'] ?? 0);
    $live_cnt   = (int)($row['cnt_score'] ?? 0);
    $live_total = (int)($row['total'] ?? 0);
}
$res_stmt->close();

$cat_stmt = $mysqli->prepare("
    SELECT COALESCE(uq.category, eq.category, 'General') AS category,
           AVG(qa.answer_score) AS avg_cat,
           SUM(qa.answer_score) AS sum_cat, COUNT(qa.answer_score) AS cnt_cat
    FROM questionnaire_answers qa
    JOIN evaluation_tracker et ON et.id = qa.tracker_id
    LEFT JOIN user_questions uq
      ON qa.question_source='user'
     AND uq.id = COALESCE(qa.user_question_id, qa.question_id)
    LEFT JOIN evaluation_questions eq
      ON qa.question_source='evaluation'
     AND eq.id = qa.question_id
    WHERE et.target_user_id = ?
    GROUP BY COALESCE(uq.category, eq.category, 'General')
");
$cat_stmt->bind_param("i", $user_id);
$cat_stmt->execute();
$cat_res = $cat_stmt->get_result();
if ($cat_res) $my_scores = $cat_res->fetch_all(MYSQLI_ASSOC);
$cat_stmt->close();

// Add feedback that was archived by the EA, so received evaluations never disappear.
$kept_combined = kept_feedback_combine($live_sum, $live_cnt, $live_total, $my_scores, kept_feedback_aggregate($mysqli, (int)$user_id));
$my_avg    = $kept_combined['avg'];
$my_total  = $kept_combined['total'];
$my_scores = $kept_combined['scores'];

// ── PEER EVALUATION GROUPING ────────────────────────────────────
// Grouping follows the account role plus the EA's year-level assignment
// (user_year_levels, set in Account Management → Assign Year Levels). It is
// NOT derived from designation, sector, secondary role, or teaching_assignments:
//   • role teacher/faculty                      -> Faculty group
//   • role staff WITH  >= 1 year-level row      -> Faculty group (Teaching Staff)
//   • role staff WITHOUT any year-level row     -> Staff group
// This is checked live, so a Staff account moves into the Faculty peer list
// (and is evaluated with the Faculty questionnaire) the moment the EA assigns
// a year level, and moves back to Staff if the assignment is cleared.
function peer_has_teaching_assignment(mysqli $mysqli, array $u): bool {
    $uid = (int)($u['id'] ?? 0);
    if ($uid <= 0) return false;
    $stmt = $mysqli->prepare('SELECT 1 FROM user_year_levels WHERE user_id=? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $found;
}
function resolve_peer_group(mysqli $mysqli, array $u): ?string {
    $role = strtolower(trim((string)($u['role'] ?? '')));
    if (in_array($role, ['teacher','faculty'], true)) return 'teacher';
    if ($role === 'staff') return peer_has_teaching_assignment($mysqli, $u) ? 'teacher' : 'staff';
    return null;
}

// Match the Dean report's canonical College assignment rule exactly.
// Human-readable role label for a Peer/Faculty evaluation target card.
// Actual Faculty keep showing their existing stored designation. A Staff
// account dynamically placed in the Faculty ('teacher') group because of
// an active year-level assignment is labeled "Teaching Staff" (plus their
// assigned level(s), from the same user_year_levels table Manage
// Registrations uses) so it's clear at a glance they're Teaching Staff and
// not an ordinary/Non-Teaching Staff member. Does not change any stored
// designation/role — display only.
function peer_display_label(mysqli $mysqli, array $p, string $peer_group, array $peer_group_labels): string {
    $role = strtolower(trim($p['role'] ?? ''));
    if ($peer_group === 'teacher' && $role === 'staff') {
        $lvlQ = $mysqli->prepare("SELECT year_level FROM user_year_levels WHERE user_id=?");
        $pid = (int)$p['id'];
        $lvlQ->bind_param("i", $pid);
        $lvlQ->execute();
        $lvlRes = $lvlQ->get_result();
        $levels = [];
        while ($lr = $lvlRes->fetch_assoc()) $levels[] = $lr['year_level'];
        $lvlQ->close();
        return $levels ? 'Teaching Staff · ' . implode(', ', $levels) : 'Teaching Staff';
    }
    return $p['designation'] ?: ($peer_group_labels[$peer_group] ?? '');
}
function eval_type_label($eval_type, $peer_group = null) {
    switch ($eval_type) {
        case 'student':               return 'Student Evaluation';
        case 'peer':
        case 'faculty_peer':          return 'Evaluate Others' . ($peer_group ? ' (' . $peer_group . ')' : '');
        case 'school_head':           return 'Dean / Principal Evaluate Others';
        case 'supervisor_to_teacher':
        case 'supervisor_to_staff':                              return 'Supervisor Evaluation';
        case 'upward_to_ea':                                     return 'Executive Assistant Review';
        default:                      return ucwords(str_replace('_', ' ', $eval_type ?: 'Evaluation'));
    }
}
$peer_group_labels = ['teacher' => 'Faculty', 'staff' => 'Staff', 'school_head' => 'Dean / Principal', 'principal' => 'Principal', 'dean' => 'Dean'];
$peer_school_head_display = 'Dean / Principal';
// Group values that route through the same Dean/Principal targeting +
// question-source pipeline as the old combined 'school_head' group.
$school_head_groups = ['principal', 'dean'];

// ── ADD peer_group COLUMN TO evaluation_tracker (idempotent) ──
// Shared table with the staff dashboard — the column may already exist
// if staff_dashboard.php has run first.
$colChk = $mysqli->query("SHOW COLUMNS FROM evaluation_tracker LIKE 'peer_group'");
if ($colChk && $colChk->num_rows === 0) {
    $mysqli->query("ALTER TABLE evaluation_tracker ADD COLUMN peer_group VARCHAR(20) NULL AFTER eval_type");
}

// ── EVALUATIONUATION ───────────────────────────────────────────
// Step 1: pick a designation group (Teacher or Staff) — a teacher may
// need to peer-evaluate either a fellow teacher or a staff member they
// worked with. Step 2: pick a specific person from that filtered list.
$peers_all       = [];   // eligible faculty/staff peers for the Teacher/Staff tabs
$peers            = [];   // targets shown for the selected evaluation group
$school_heads     = [];   // Dean + Principal targets for the Dean / Principal tab
$principal_targets = [];  // subset of $school_heads with role='principal'
$dean_targets       = []; // subset of $school_heads with role='dean'
$done_peers       = [];
$done_school_heads = [];
$peer_group       = null; // 'teacher' | 'staff' | 'school_head' | null

if ($page === 'peer') {
    $pr2 = $mysqli->prepare("SELECT u.id, u.full_name, u.designation, u.photo, u.role, u.sector, u.secondary_role
        FROM users u WHERE u.role IN ('teacher','staff','faculty')
          AND u.is_active=1 AND u.account_status='approved' AND u.id != ? ORDER BY u.full_name ASC");
    $pr2->bind_param("i", $user_id);
    $pr2->execute();
    $pr2res = $pr2->get_result();
    if ($pr2res) $peers_all = $pr2res->fetch_all(MYSQLI_ASSOC);
    $pr2->close();

    // Dean / Principal targets come from the same users source used by the
    // Questionnaire / privileged-account feature.  Do not infer school-head
    // status from the free-text designation: the account role is the source
    // of truth. Only active, approved Principal/Dean accounts are eligible —
    // and only for the roles this evaluator's own teaching assignment
    // qualifies them for (see $can_evaluate_principal / $can_evaluate_dean
    // above).
    $eligible_sh_roles = [];
    if ($can_evaluate_principal) $eligible_sh_roles[] = 'principal';
    if ($can_evaluate_dean)      $eligible_sh_roles[] = 'dean';

    if (!empty($eligible_sh_roles)) {
        $roleSlots = implode(',', array_fill(0, count($eligible_sh_roles), '?'));
        $sh = $mysqli->prepare("
            SELECT id, full_name, designation, photo, role
            FROM users
            WHERE role IN ($roleSlots)
              AND is_active=1
              AND account_status='approved'
              AND id != ?
            ORDER BY
              CASE WHEN role='dean' THEN 1 ELSE 2 END,
              full_name ASC
        ");
        $shTypes = str_repeat('s', count($eligible_sh_roles)) . 'i';
        $shArgs  = $eligible_sh_roles;
        $shArgs[] = $user_id;
        $sh->bind_param($shTypes, ...$shArgs);
        $sh->execute();
        $shRes = $sh->get_result();
        if ($shRes) $school_heads = $shRes->fetch_all(MYSQLI_ASSOC);
        $sh->close();
    }

    $principal_targets = array_values(array_filter($school_heads, fn($t) => strtolower($t['role'] ?? '') === 'principal'));
    $dean_targets       = array_values(array_filter($school_heads, fn($t) => strtolower($t['role'] ?? '') === 'dean'));

    $requestedPeerGroup = $_GET['group'] ?? '';
    $done_college_peers = [];
    if ($college_peer_period) {
        $collegeDoneStmt = $mysqli->prepare("SELECT target_user_id FROM evaluation_tracker WHERE evaluator_id=? AND period_id=? AND eval_type='faculty_peer'");
        $collegeDonePeriodId = (int)$college_peer_period['id'];
        $collegeDoneStmt->bind_param('ii', $user_id, $collegeDonePeriodId);
        $collegeDoneStmt->execute();
        $collegeDoneRes = $collegeDoneStmt->get_result();
        if ($collegeDoneRes) while ($r = $collegeDoneRes->fetch_assoc()) $done_college_peers[] = (int)$r['target_user_id'];
        $collegeDoneStmt->close();
    }
    if ($period || ($requestedPeerGroup === 'teacher' && $college_peer_period)) {
        $period_id_int = (int)($period['id'] ?? 0);
        $donePeriodId = ($requestedPeerGroup === 'teacher' && $college_peer_period)
            ? (int)$college_peer_period['id'] : $period_id_int;

        $dpStmt = $mysqli->prepare("SELECT target_user_id FROM evaluation_tracker WHERE evaluator_id=? AND period_id=? AND eval_type='faculty_peer'");
        $dpStmt->bind_param("ii", $user_id, $donePeriodId);
        $dpStmt->execute();
        $dp = $dpStmt->get_result();
        if ($dp) while ($r = $dp->fetch_assoc()) $done_peers[] = (int)$r['target_user_id'];
        $dpStmt->close();

        $shDone = $mysqli->prepare("SELECT target_user_id FROM evaluation_tracker WHERE evaluator_id=? AND period_id=? AND eval_type IN ('faculty_peer','school_head') AND (peer_group IN ('Dean / Principal','School Head','Principal','Dean') OR peer_group IS NULL)");
        $shDone->bind_param("ii", $user_id, $period_id_int);
        $shDone->execute();
        $shDoneRes = $shDone->get_result();
        if ($shDoneRes) while ($r = $shDoneRes->fetch_assoc()) $done_school_heads[] = (int)$r['target_user_id'];
        $shDone->close();
    }

    if (isset($_GET['group']) && in_array($_GET['group'], ['teacher', 'staff', 'principal', 'dean'], true)) {
        $peer_group = $_GET['group'];

        if (in_array($peer_group, $school_head_groups, true)) {
            $peers = array_values(array_filter($school_heads, function ($p) use ($peer_group) {
                return strtolower($p['role'] ?? '') === $peer_group;
            }));
        } else {
            // Classify with the same shared predicates + DB-backed
            // "Non-Teaching Staff" check admin/questionnaire.php's own
            // Peer-to-Peer tab uses (see resolve_peer_group() above), not
            // designation text. Keeps both portals' Peer-to-Peer rosters
            // in agreement.
            // Faculty category includes every eligible Faculty/Teaching Staff
            // member, regardless of whether they teach Grade 7–12 or College.
            $peers = array_values(array_filter($peers_all, function ($p) use ($mysqli, $peer_group) {
                return resolve_peer_group($mysqli, $p) === $peer_group;
            }));
        }
    }
}

// ── EVALUATION FORM ─────────────────────────────────────────────
$peer_target       = null;
$peer_questions    = [];
$peer_form_id      = 0;
$peer_eval_group   = null;
$peer_group_error  = '';

if ($page === 'peer_eval' && isset($_GET['tid'])) {
    $tid = intval($_GET['tid']);
    $req_group = $_GET['group'] ?? null;

    $tu = $mysqli->prepare("SELECT * FROM users WHERE id=? AND is_active=1 AND account_status='approved' LIMIT 1");
    $tu->bind_param("i", $tid);
    $tu->execute();
    $peer_target = $tu->get_result()->fetch_assoc();
    $tu->close();

    if ($peer_target && !in_array($req_group, ['teacher', 'staff', 'principal', 'dean'], true)) {
        $peer_group_error = "Please select an evaluation group.";
        $peer_target = null;
    } elseif ($peer_target) {
        if (in_array($req_group, $school_head_groups, true)) {
            $targetRole = strtolower(trim($peer_target['role'] ?? ''));
            $roleEligible = $req_group === 'principal' ? $can_evaluate_principal : $can_evaluate_dean;
            if ($targetRole !== $req_group) {
                $peer_group_error = "The selected user is not the " . ($req_group === 'principal' ? 'Principal' : 'Dean') . ".";
                $peer_target = null;
            } elseif (!$roleEligible) {
                $peer_group_error = $req_group === 'principal'
                    ? "Evaluating the Principal is only available to Faculty/Teaching Staff with a Grade 7-12 teaching assignment."
                    : "Evaluating the Dean is only available to Faculty/Teaching Staff with a College teaching assignment.";
                $peer_target = null;
            } else {
                $peer_eval_group = $req_group;
            }
        } else {
            $actual_group = resolve_peer_group($mysqli, $peer_target);
            if (!in_array($peer_target['role'] ?? '', ['teacher', 'staff', 'faculty'], true) || $actual_group !== $req_group) {
                $peer_group_error = "The selected user does not belong to the selected evaluation group.";
                $peer_target = null;
            } else {
                $peer_eval_group = $req_group;
            }
        }
    }

    if ($peer_target && !$evaluation_open) {
        $peer_group_error = 'Evaluation is currently closed. It will open at the scheduled time set by the administrator.';
        $peer_target = null;
    }

    if ($peer_target) {
        $form_type = 'faculty_peer';
        $fu = $mysqli->prepare("SELECT id FROM questionnaire_forms WHERE eval_type=? AND is_active=1 ORDER BY id DESC LIMIT 1");
        $fu->bind_param("s", $form_type);
        $fu->execute();
        $fuRes = $fu->get_result();
        if ($fuRes) {
            $fuRow = $fuRes->fetch_assoc();
            $peer_form_id = (int)($fuRow['id'] ?? 0);
        }
        $fu->close();

        // Questionnaire is the single source of truth for Peer-to-Peer questions.
        // Mirror admin/questionnaire.php exactly:
        //   Faculty -> shared evaluation_questions (Teacher / peer)
        //   Staff -> per-user user_questions (Staff / peer)
        //   Dean/Principal -> per-user user_questions (School / peer)
        if (in_array($peer_eval_group, $school_head_groups, true)) {
            $qs = $mysqli->prepare("
                SELECT * FROM user_questions
                WHERE user_id=? AND target_type=? AND eval_type='general'
                ORDER BY category ASC, id ASC
            ");
            $schoolHeadTargetType = $peer_eval_group === 'principal' ? 'Principal' : 'Dean';
            $qs->bind_param("is", $tid, $schoolHeadTargetType);
            $qs->execute();
            $peer_questions = $qs->get_result()->fetch_all(MYSQLI_ASSOC);
            $qs->close();
        } elseif ($peer_eval_group === 'staff') {
            $qs = $mysqli->prepare("
                SELECT * FROM user_questions
                WHERE user_id=? AND target_type='Staff' AND eval_type='general'
                ORDER BY category ASC, id ASC
            ");
            $qs->bind_param("i", $tid);
            $qs->execute();
            $peer_questions = $qs->get_result()->fetch_all(MYSQLI_ASSOC);
            $qs->close();
        } else {
            // Faculty is the shared Peer-to-Peer questionnaire bank.
            // Do not fall back to per-user questions: that would bypass the
            // questionnaire configured by the admin.
            $qs = $mysqli->prepare("
                SELECT * FROM evaluation_questions
                WHERE target_type='Faculty' AND eval_type='general' AND evaluator_role='shared' AND is_active=1
                ORDER BY category ASC, id ASC
            ");
            $qs->execute();
            $peer_questions = $qs->get_result()->fetch_all(MYSQLI_ASSOC);
            $qs->close();
        }

    }
}

// ── NOTIFICATIONS TABLE ───────────────────────────────────────
$mysqli->query("CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL DEFAULT 'designation_update',
    user_id INT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    extra_data TEXT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_unread (is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── ROLE CHANGE LOG TABLE ────────────────────────────────────
// This is what powers the admin dashboard's "System Audits" box and bell.
$mysqli->query("CREATE TABLE IF NOT EXISTS role_change_log (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,
    old_role         VARCHAR(60)  NOT NULL DEFAULT '',
    new_role         VARCHAR(60)  NOT NULL DEFAULT '',
    old_designation  VARCHAR(120) NULL,
    new_designation  VARCHAR(120) NULL,
    changed_at       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_changed (changed_at),
    INDEX idx_user    (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── AUTO-CREATE user_preferences table ───────────────────────
// Shared with the staff dashboard — idempotent, so safe to run here too.
$mysqli->query("CREATE TABLE IF NOT EXISTS user_preferences (
    user_id INT UNSIGNED PRIMARY KEY,
    email_on_designation_update TINYINT(1) NOT NULL DEFAULT 1,
    email_on_new_evaluation     TINYINT(1) NOT NULL DEFAULT 1,
    show_result_details         TINYINT(1) NOT NULL DEFAULT 1,
    compact_dashboard           TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Keep preferences compatible with older installations.
$mysqli->query("ALTER TABLE user_preferences ADD COLUMN IF NOT EXISTS show_result_details TINYINT(1) NOT NULL DEFAULT 1");
$mysqli->query("ALTER TABLE user_preferences ADD COLUMN IF NOT EXISTS compact_dashboard TINYINT(1) NOT NULL DEFAULT 0");
$mysqli->query("ALTER TABLE user_preferences ADD COLUMN IF NOT EXISTS notify_evaluation_schedule TINYINT(1) NOT NULL DEFAULT 1");
$mysqli->query("ALTER TABLE user_preferences ADD COLUMN IF NOT EXISTS notify_teaching_assignment TINYINT(1) NOT NULL DEFAULT 1");
$mysqli->query("ALTER TABLE user_preferences ADD COLUMN IF NOT EXISTS notify_academic_period TINYINT(1) NOT NULL DEFAULT 1");

$prefStmt = $mysqli->prepare("SELECT * FROM user_preferences WHERE user_id=? LIMIT 1");
$prefStmt->bind_param('i', $user_id);
$prefStmt->execute();
$user_prefs = array_merge(['show_result_details'=>1,'compact_dashboard'=>0,'notify_evaluation_schedule'=>1,'notify_teaching_assignment'=>1,'notify_academic_period'=>1], $prefStmt->get_result()->fetch_assoc() ?: []);
function notif_muted_types_sql(array $prefs): string {
    $map = ['notify_evaluation_schedule'=>'evaluation_schedule','notify_teaching_assignment'=>'teaching_assignment','notify_academic_period'=>'academic_period'];
    $m = [];
    foreach ($map as $key => $type) { if (isset($prefs[$key]) && (int)$prefs[$key] === 0) $m[] = "'" . $type . "'"; }
    return $m ? ' AND type NOT IN (' . implode(',', $m) . ')' : '';
}

$prefStmt->close();

// Save personal dashboard preferences.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_preferences'])) {
    if (!csrf_check()) { $_SESSION['toast_error'] = 'Your session expired. Please try again.'; header('Location: faculty_dashboard.php?page=settings'); exit; }
    $n1 = isset($_POST['notify_evaluation_schedule']) ? 1 : 0; $n2 = isset($_POST['notify_teaching_assignment']) ? 1 : 0; $n3 = isset($_POST['notify_academic_period']) ? 1 : 0;
$up = $mysqli->prepare("INSERT INTO user_preferences (user_id,notify_evaluation_schedule,notify_teaching_assignment,notify_academic_period) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE notify_evaluation_schedule=VALUES(notify_evaluation_schedule),notify_teaching_assignment=VALUES(notify_teaching_assignment),notify_academic_period=VALUES(notify_academic_period)");
$up->bind_param('iiii', $user_id, $n1, $n2, $n3); $up->execute(); $up->close();
    $_SESSION['toast'] = 'Settings saved successfully.';
    header('Location: faculty_dashboard.php?page=settings'); exit;
}

// ── UPDATE ACCOUNT DETAILS FROM SETTINGS ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account_name'])) {
    if (!csrf_check()) {
        $_SESSION['toast_error'] = 'Your session expired. Please try again.';
        header('Location: faculty_dashboard.php?page=settings'); exit;
    }
    $new_name = trim(preg_replace('/\s+/u', ' ', (string)($_POST['full_name'] ?? '')));
    $new_username = trim((string)($_POST['username'] ?? $account_username));
    $new_desig = trim((string)($_POST['designation'] ?? $designation));
    $profile_error = '';
    if ($new_name === '') $profile_error = 'Full name cannot be empty.';
    elseif (mb_strlen($new_name) > 150) $profile_error = 'Full name must be 150 characters or fewer.';
    elseif (preg_match('/[\x00-\x1F\x7F<>]/u', $new_name)) $profile_error = 'Full name contains invalid characters.';
    elseif ($new_username === '') $profile_error = 'Username cannot be empty.';
    elseif (mb_strlen($new_username) > 50) $profile_error = 'Username must be 50 characters or fewer.';
    elseif (preg_match('/\s/', $new_username)) $profile_error = 'Username cannot contain spaces.';
    elseif ($new_desig === '') $profile_error = 'Designation cannot be empty.';
    elseif (mb_strlen($new_desig) > 100) $profile_error = 'Designation must be 100 characters or fewer.';

    if ($profile_error !== '') {
        $_SESSION['toast_error'] = $profile_error;
    } else {
        $checkName = $mysqli->prepare('SELECT id FROM users WHERE username=? AND id<>? LIMIT 1');
        $checkName->bind_param('si', $new_username, $user_id);
        $checkName->execute();
        $duplicateUsername = (bool)$checkName->get_result()->fetch_assoc();
        $checkName->close();
        if ($duplicateUsername) {
            $_SESSION['toast_error'] = 'That username is already in use.';
        } else {
            $oldDesignation = (string)$designation;
            try {
                $updateProfile = $mysqli->prepare('UPDATE users SET full_name=?, username=?, designation=? WHERE id=?');
                $updateProfile->bind_param('sssi', $new_name, $new_username, $new_desig, $user_id);
                $ok = $updateProfile->execute();
                $updateProfile->close();
                if (!$ok) {
                    $_SESSION['toast_error'] = 'Could not update your profile. Please try again.';
                } else {
                    $_SESSION['full_name'] = $new_name;
                    $_SESSION['username'] = $new_username;
                    $_SESSION['designation'] = $new_desig;
                    $full_name = $new_name; $account_username = $new_username; $designation = $new_desig;
                    if ($new_desig !== $oldDesignation) {
                        try {
                            $audit = $mysqli->prepare("INSERT INTO role_change_log (user_id, old_role, new_role, old_designation, new_designation) VALUES (?, 'faculty', 'faculty', ?, ?)");
                            $audit->bind_param('iss', $user_id, $oldDesignation, $new_desig);
                            $audit->execute(); $audit->close();
                        } catch (Throwable $auditError) { error_log('[faculty settings] Could not record designation update: ' . $auditError->getMessage()); }
                    }
                    $_SESSION['toast'] = 'Profile details updated successfully.';
                }
            } catch (mysqli_sql_exception $e) {
                error_log('[faculty settings] Profile update failed for user ' . $user_id . ': ' . $e->getMessage());
                $_SESSION['toast_error'] = 'Unable to update your profile right now. Please try again.';
            }
        }
    }
    header('Location: faculty_dashboard.php?page=settings'); exit;
}

// ── ENSURE A UNIQUE CONSTRAINT BACKS THE DUPLICATE-EVAL CHECK ──
// The application-level duplicate check below (SELECT ... then INSERT) has
// a race window between two near-simultaneous submissions. This unique
// index makes the DB itself the source of truth: a second insert for the
// same (evaluator, target, eval_type, period) fails with a duplicate-key
// error instead of silently creating a second row. Shared with the staff
// dashboard's evaluation_tracker usage — idempotent, so safe to run here too.
$idxChk = $mysqli->query("SHOW INDEX FROM evaluation_tracker WHERE Key_name = 'uniq_eval_submission'");
if ($idxChk && $idxChk->num_rows === 0) {
    $mysqli->query("ALTER TABLE evaluation_tracker ADD UNIQUE INDEX uniq_eval_submission (evaluator_id, target_user_id, eval_type, period_id)");
}

// ── SUBMIT EVALUATION ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_peer'])) {
    if (!csrf_check()) {
        $_SESSION['toast_error'] = "Your session expired or the request could not be verified. Please try again.";
        header("Location: faculty_dashboard.php?page=peer"); exit;
    }

    $submitted_group = $_POST['group'] ?? '';
    $tid     = intval($_POST['target_id'] ?? 0);
    $pid     = intval($_POST['period_id'] ?? 0);
    $ratings = $_POST['ratings'] ?? [];
    $comment = trim($_POST['comment'] ?? '');

    if (!in_array($submitted_group, ['teacher', 'staff', 'principal', 'dean'], true)) {
        $_SESSION['toast_error'] = "Please select an evaluation group.";
        header("Location: faculty_dashboard.php?page=peer"); exit;
    }

    $eval_type = 'faculty_peer';
    $form_type = 'faculty_peer';
    $fid = 0;

    $formStmt = $mysqli->prepare("SELECT id FROM questionnaire_forms WHERE eval_type=? AND is_active=1 ORDER BY id DESC LIMIT 1");
    $formStmt->bind_param("s", $form_type);
    $formStmt->execute();
    $formRow = $formStmt->get_result()->fetch_assoc();
    $fid = (int)($formRow['id'] ?? 0);
    $formStmt->close();

    if ($fid <= 0) {
        $_SESSION['toast_error'] = "The Evaluation form is not available or is inactive.";
        header("Location: faculty_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
    }

    // Re-check the live schedule on the server. This blocks stale pages and
    // direct POSTs before the configured opening time and at/after closing.
    if (!fs_schedule_is_open($mysqli)) {
        $_SESSION['toast_error'] = "Evaluation is currently closed. Please return at the scheduled opening time.";
        header("Location: faculty_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
    }

    // Never trust a browser-supplied period. Faculty peer rows must use an HE
    // semester/summer period so the Dean Higher Education report can include them.
    $currentPeriod = null;
    if ($submitted_group === 'teacher') {
        $currentPeriodResult = $mysqli->query("SELECT * FROM evaluation_periods
            WHERE semester IN ('1st Semester','2nd Semester','Summer')" . pbi_not_archived_sql($mysqli) . "
            ORDER BY
                (CONVERT(TRIM(school_year) USING utf8mb4) COLLATE utf8mb4_unicode_ci =
                    CONVERT(TRIM((SELECT setting_value FROM system_settings WHERE setting_key='acad_year' LIMIT 1)) USING utf8mb4) COLLATE utf8mb4_unicode_ci) DESC,
                (CONVERT(TRIM(semester) USING utf8mb4) COLLATE utf8mb4_unicode_ci =
                    CONVERT(TRIM((SELECT setting_value FROM system_settings WHERE setting_key='acad_term' LIMIT 1)) USING utf8mb4) COLLATE utf8mb4_unicode_ci) DESC,
                is_active DESC,
                id DESC LIMIT 1");
    } else {
        $currentPeriodResult = $mysqli->query("SELECT * FROM evaluation_periods WHERE is_active=1" . pbi_current_period_tail($mysqli) . " LIMIT 1");
    }
    if ($currentPeriodResult) $currentPeriod = $currentPeriodResult->fetch_assoc();
    if (!$currentPeriod) {
        $_SESSION['toast_error'] = $submitted_group === 'teacher'
            ? "No semester period is configured for Faculty Evaluate Others."
            : "No evaluation period is currently open.";
        header("Location: faculty_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
    }
    $pid = (int)$currentPeriod['id'];

    // Re-validate the target server-side. Dean/Principal targets are strictly
    // limited to the specific role requested, and the evaluator must
    // currently be eligible for that role by their own teaching assignment —
    // re-derived here (not trusted from the submitted form), since an
    // assignment can change between page load and submit.
    if (in_array($submitted_group, $school_head_groups, true)) {
        $roleEligible = $submitted_group === 'principal' ? $can_evaluate_principal : $can_evaluate_dean;
        if (!$roleEligible) {
            $_SESSION['toast_error'] = $submitted_group === 'principal'
                ? "Evaluating the Principal is only available to Faculty/Teaching Staff with a Grade 7-12 teaching assignment."
                : "Evaluating the Dean is only available to Faculty/Teaching Staff with a College teaching assignment.";
            header("Location: faculty_dashboard.php?page=peer"); exit;
        }

        $tchk = $mysqli->prepare("SELECT id, full_name, designation, role FROM users WHERE id=? AND is_active=1 LIMIT 1");
        $tchk->bind_param("i", $tid);
        $tchk->execute();
        $tchkRow = $tchk->get_result()->fetch_assoc();
        $tchk->close();

        $targetRole = strtolower(trim($tchkRow['role'] ?? ''));

        if (!$tchkRow || $targetRole !== $submitted_group || $tid === (int)$user_id) {
            $_SESSION['toast_error'] = "The selected " . ($submitted_group === 'principal' ? 'Principal' : 'Dean') . " is no longer available.";
            header("Location: faculty_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
        }

        // Peer-to-Peer Questionnaire -> Dean / Principal uses the per-user
        // School target under eval_type='peer'. This is deliberately separate
        // from the Dean/Principal Evaluation (school_head) questionnaire.
        $validQStmt = $mysqli->prepare("
            SELECT id
            FROM user_questions
            WHERE user_id=? AND target_type=? AND eval_type='general'
        ");
        $targetType = $submitted_group === 'principal' ? 'Principal' : 'Dean';
        $validQStmt->bind_param("is", $tid, $targetType);
        $validQStmt->execute();
        $validQRes = $validQStmt->get_result();
        $valid_question_ids = [];
        if ($validQRes) while ($vq = $validQRes->fetch_assoc()) $valid_question_ids[] = (int)$vq['id'];
        $validQStmt->close();
        $question_source = 'user';

        if (empty($valid_question_ids)) {
            $_SESSION['toast_error'] = "No " . ($submitted_group === 'principal' ? 'Principal' : 'Dean') . " questions have been assigned to this target in the Peer-to-Peer Questionnaire.";
            header("Location: faculty_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
        }
    } else {
        [$eligible, $eligMsg] = canPeerEvaluate($mysqli, $user_id, $tid);
        if (!$eligible) {
            $_SESSION['toast_error'] = $eligMsg;
            header("Location: faculty_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
        }

        $tchk = $mysqli->prepare("SELECT id, designation, role, sector, secondary_role FROM users WHERE id=? AND role IN ('teacher','staff','faculty') AND is_active=1 LIMIT 1");
        $tchk->bind_param("i", $tid);
        $tchk->execute();
        $tchkRow = $tchk->get_result()->fetch_assoc();
        $tchk->close();

        if (!$tchkRow || resolve_peer_group($mysqli, $tchkRow) !== $submitted_group) {
            $_SESSION['toast_error'] = "The selected faculty member is no longer available in this evaluation group.";
            header("Location: faculty_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
        }

        // Match the exact Peer-to-Peer Questionnaire source used to build
        // the form. Faculty is the shared Teacher pool; Staff is the selected
        // person's Staff pool.
        $valid_question_ids = [];
        if ($submitted_group === 'teacher') {
            $validQStmt = $mysqli->prepare("SELECT id FROM evaluation_questions WHERE target_type='Faculty' AND eval_type='general' AND evaluator_role='shared' AND is_active=1");
            $validQStmt->execute();
            $validQRes = $validQStmt->get_result();
            if ($validQRes) while ($vq = $validQRes->fetch_assoc()) $valid_question_ids[] = (int)$vq['id'];
            $validQStmt->close();
            $question_source = 'evaluation';
        } else {
            $validQStmt = $mysqli->prepare("SELECT id FROM user_questions WHERE user_id=? AND target_type='Staff' AND eval_type='general'");
            $validQStmt->bind_param("i", $tid);
            $validQStmt->execute();
            $validQRes = $validQStmt->get_result();
            if ($validQRes) while ($vq = $validQRes->fetch_assoc()) $valid_question_ids[] = (int)$vq['id'];
            $validQStmt->close();
            $question_source = 'user';
        }
    }

    $ratings = array_filter($ratings, function ($val, $qid) use ($valid_question_ids) {
        return in_array((int)$qid, $valid_question_ids, true);
    }, ARRAY_FILTER_USE_BOTH);

    if (empty($valid_question_ids) || count($ratings) !== count($valid_question_ids)) {
        $_SESSION['toast_error'] = "Please rate all questions before submitting.";
        header("Location: faculty_dashboard.php?page=peer_eval&tid=" . $tid . "&group=" . urlencode($submitted_group)); exit;
    }

    // A written comment is mandatory. Enforced here as well as in the form so
    // a crafted POST can't skip it (whitespace-only doesn't count).
    if ($comment === '') {
        $_SESSION['toast_error'] = "Please write a comment before submitting. Comments and suggestions are required.";
        header("Location: faculty_dashboard.php?page=peer_eval&tid=" . $tid . "&group=" . urlencode($submitted_group)); exit;
    }

    $dup = $mysqli->prepare("SELECT id FROM evaluation_tracker WHERE evaluator_id=? AND target_user_id=? AND period_id=? AND eval_type=? LIMIT 1");
    $dup->bind_param("iiis", $user_id, $tid, $pid, $eval_type);
    $dup->execute();
    $dup->store_result();

    if ($dup->num_rows === 0) {
        $dup->close();
        try {
            $mysqli->begin_transaction();

            $overall = round(array_sum($ratings) / count($ratings), 2);
            $group_label = $peer_group_labels[$submitted_group] ?? ucfirst($submitted_group);

            $ins = $mysqli->prepare("
                INSERT INTO evaluation_tracker
                (evaluator_id,target_user_id,form_id,period_id,eval_type,peer_group,score,remarks,status,submitted_at)
                VALUES (?,?,?,?,?,?,?,?,'submitted',NOW())
            ");
            $ins->bind_param("iiiissds", $user_id, $tid, $fid, $pid, $eval_type, $group_label, $overall, $comment);
            $ins->execute();
            $tracker_id = $mysqli->insert_id;
            $ins->close();

            if ($question_source === 'user') {
                $ri = $mysqli->prepare("INSERT INTO questionnaire_answers (tracker_id,question_id,question_source,user_question_id,answer_score,submitted_at) VALUES (?,NULL,'user',?,?,NOW())");
            } else {
                $ri = $mysqli->prepare("INSERT INTO questionnaire_answers (tracker_id,question_id,question_source,user_question_id,answer_score,submitted_at) VALUES (?,?,'evaluation',NULL,?,NOW())");
            }
            foreach ($ratings as $qid => $rating) {
                $qid = intval($qid);
                $rating = min(5, max(1, intval($rating)));
                $ri->bind_param("iii", $tracker_id, $qid, $rating);
                $ri->execute();
            }
            $ri->close();

            $mysqli->commit();

            $notif_msg = NOTIF_EVAL_RECEIVED_MSG; // anonymous: evaluator identity is never shown
            $nins = $mysqli->prepare("INSERT INTO notifications (type, user_id, message) VALUES ('evaluation_received', ?, ?)");
            $nins->bind_param("is", $tid, $notif_msg);
            $nins->execute();
            $nins->close();

            $_SESSION['toast'] = "Evaluation submitted!";
        } catch (Exception $e) {
            $mysqli->rollback();
            error_log('[faculty_dashboard] submit evaluation failed for evaluator=' . $user_id . ' target=' . $tid . ': ' . $e->getMessage());
            $_SESSION['toast_error'] = (($mysqli->errno ?? 0) === 1062)
                ? "You already evaluated this person this period."
                : "Submission failed. Please try again.";
        }
    } else {
        $dup->close();
        $_SESSION['toast_error'] = "You already evaluated this person this period.";
    }

    header("Location: faculty_dashboard.php?page=peer&group=" . urlencode($submitted_group)); exit;
}

// ── UPDATE DESIGNATION ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_designation'])) {
    if (!csrf_check()) {
        $_SESSION['toast_error'] = "Your session expired or the request could not be verified. Please try again.";
        header("Location: faculty_dashboard.php?page=profile"); exit;
    }
    $new_desig = trim($_POST['new_designation'] ?? '');
    $old_desig = $designation;

    if (empty($new_desig)) {
        $_SESSION['toast_error'] = "Designation cannot be empty.";
    } elseif (preg_replace('/\s+/', ' ', mb_strtolower($new_desig)) === preg_replace('/\s+/', ' ', mb_strtolower(trim((string)$old_desig)))) {
        $_SESSION['toast_error'] = "That's already your current designation.";
    } else {
        $stmt = $mysqli->prepare("UPDATE users SET designation=? WHERE id=?");
        $stmt->bind_param("si", $new_desig, $user_id);
        $stmt->execute(); $stmt->close();

        $_SESSION['designation'] = $new_desig;
        $designation = $new_desig;

        // No self-notification here: the person just made this change themselves.
        // The admin is still informed through role_change_log below.

        // Also log to role_change_log so it shows up in the admin dashboard's
        // System Audits box and notification bell.
        $rstmt = $mysqli->prepare("INSERT INTO role_change_log (user_id, old_role, new_role, old_designation, new_designation) VALUES (?, 'faculty', 'faculty', ?, ?)");
        $rstmt->bind_param("iss", $user_id, $old_desig, $new_desig);
        $rstmt->execute(); $rstmt->close();

        $_SESSION['toast'] = "Your designation has been updated to \"$new_desig\".";
    }
    header("Location: faculty_dashboard.php?page=profile"); exit;
}

// ── UPDATE TEACHING LEVEL(S) ──────────────────────────────────
// Multi-select — a faculty member can be assigned to more than one
// specific year level at once (e.g. teaches both Grade 8 and Grade 10).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_levels'])) {
    if (!csrf_check()) {
        $_SESSION['toast_error'] = "Your session expired or the request could not be verified. Please try again.";
        header("Location: faculty_dashboard.php?page=profile"); exit;
    }
    $selected = array_values(array_intersect($_POST['levels'] ?? [], $year_levels));

    $mysqli->begin_transaction();
    try {
        $del = $mysqli->prepare("DELETE FROM user_year_levels WHERE user_id=?");
        $del->bind_param("i", $user_id);
        $del->execute();
        $del->close();

        if (!empty($selected)) {
            $ins = $mysqli->prepare("INSERT INTO user_year_levels (user_id, year_level) VALUES (?, ?)");
            foreach ($selected as $lvl) {
                $ins->bind_param("is", $user_id, $lvl);
                $ins->execute();
            }
            $ins->close();
        }
        $mysqli->commit();

        $_SESSION['toast'] = !empty($selected)
            ? "Your teaching level(s) updated to: " . implode(', ', $selected) . "."
            : "Your teaching level assignment has been cleared. Students won't be able to evaluate you until a level is set.";
    } catch (Exception $e) {
        $mysqli->rollback();
        error_log('[faculty_dashboard] update_levels failed for user_id=' . $user_id . ': ' . $e->getMessage());
        $_SESSION['toast_error'] = "Failed to update teaching levels. Please try again.";
    }
    header("Location: faculty_dashboard.php?page=profile"); exit;
}


// ── RECENT SUBMISSIONS ────────────────────────────────────────
// faculty_dashboard.php — recent_subs
$rsStmt = $mysqli->prepare("
    SELECT et.id AS tracker_id,
           (SELECT AVG(qa.answer_score) FROM questionnaire_answers qa WHERE qa.tracker_id = et.id) AS overall_score,
           et.eval_type, et.peer_group, et.submitted_at, ep.period_label, ep.semester
    FROM evaluation_tracker et
    LEFT JOIN evaluation_periods ep ON ep.id = et.period_id
    WHERE et.target_user_id = ?
    ORDER BY et.submitted_at DESC LIMIT 5
");
$rsStmt->bind_param("i", $user_id);
$rsStmt->execute();
$rs = $rsStmt->get_result();
if ($rs) $recent_subs = $rs->fetch_all(MYSQLI_ASSOC);
$rsStmt->close();
$recent_subs = kept_feedback_merge_lists($recent_subs ?? [], kept_feedback_rows($mysqli, (int)$user_id), 5);

// ── MARK NOTIFICATIONS READ ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_notifications_read'])) {
    if (!csrf_check()) {
        header("Location: faculty_dashboard.php?page=" . urlencode($page)); exit;
    }
    $mr = $mysqli->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0");
    $mr->bind_param("i", $user_id);
    $mr->execute(); $mr->close();
    header("Location: faculty_dashboard.php?page=" . urlencode($page)); exit;
}

// ── MY NOTIFICATIONS (bell) ──────────────────────────────────
$my_notifications = [];
$unread_count     = 0;
$nq = $mysqli->prepare("SELECT id, type, message, extra_data, is_read, created_at FROM notifications WHERE user_id=?" . notif_muted_types_sql($user_prefs) . " ORDER BY created_at DESC, id DESC");
$nq->bind_param("i", $user_id);
$nq->execute();
$nres = $nq->get_result();
if ($nres) $my_notifications = $nres->fetch_all(MYSQLI_ASSOC);
$nq->close();
foreach ($my_notifications as &$notification) {
    $notification['message'] = notif_apply_eval_wording($notification, notification_message_for_view($notification, (int)$user_id));
}
unset($notification);
foreach ($my_notifications as $n) if (empty($n['is_read'])) $unread_count++;

$desig_suggestions = ['Teacher','Registrar','Cashier','Bookkeeper','Librarian','Guidance','Nurse','Personnel','Adviser','Coordinator','Department Head'];

$toast       = $_SESSION['toast']       ?? ''; unset($_SESSION['toast']);
$toast_error = $_SESSION['toast_error'] ?? ''; unset($_SESSION['toast_error']);

// Helpers
$first_name = explode(',', $full_name)[0] ?? $full_name;
$parts      = explode(' ', trim($full_name));
$initials   = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
// Performance Level uses the same bands and wording as the Rating Legend on the reports
// (same as the Dean dashboard). No ratings yet -> "No Ratings Yet".
if ($my_avg === null)       { $perf_label = 'No Ratings Yet';                     $perf_color = '#6b7280'; }
elseif ($my_avg >= 4.5)     { $perf_label = 'Very Satisfied';                     $perf_color = '#32B98A'; }
elseif ($my_avg >= 3.5)     { $perf_label = 'Satisfied';                          $perf_color = '#6BCFA9'; }
elseif ($my_avg >= 2.5)     { $perf_label = 'Neither Satisfied nor Dissatisfied'; $perf_color = '#facc15'; }
elseif ($my_avg >= 1.5)     { $perf_label = 'Dissatisfied';                       $perf_color = '#fb923c'; }
else                        { $perf_label = 'Very Dissatisfied';                  $perf_color = '#E6788A'; }
$perf_long = strlen($perf_label) > 20;   // the long wording needs a smaller font to fit the card
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Faculty Dashboard — PBI</title>
<script>
// Prevent a flash of the wrong theme: if the saved preference is "dark" but the
// page markup below defaults to class="light-theme", hide the page until the
// real theme JS (further down) applies the correct class and reveals it.
(function(){
    try{
        var savedTheme = localStorage.getItem('pbi_theme');
        var systemDark = savedTheme === 'system' && window.matchMedia && !window.matchMedia('(prefers-color-scheme: light)').matches;
        if (savedTheme === 'dark' || systemDark) {
            document.write('<style id="theme-fouc-guard">body{visibility:hidden}</style>');
        }
    }catch(e){}
})();
</script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<style>
:root{
    --dark:#0A192F; --mid:#172A45; --inner:#0F1F3D;
    --accent:#2563EB; --hover:#1D4ED8;
    --teal:#2563EB; --teal-light:rgba(37,99,235,.14); --teal-hover:#1D4ED8;
    --light:#E0E6F0; --muted:#A0B3C6;
    --danger:#F05454; --success:#22C55E;
    --border:rgba(255,255,255,0.08);
    --radius:12px; --shadow:0 4px 24px rgba(0,0,0,.35);
    --sidebar-w:240px;
}
body.light-theme{
    --dark:#F3F6FB; --mid:#FFFFFF; --inner:#EEF2F8;
    --accent:#2563EB; --hover:#1D4ED8;
    --teal:#2563EB; --teal-light:rgba(37,99,235,.10); --teal-hover:#1D4ED8;
    --light:#16263B; --muted:#5B7186;
    --danger:#DC2626; --success:#16A34A;
    --border:rgba(15,31,61,0.10);
    --shadow:0 4px 24px rgba(15,31,61,.08);
}
body.light-theme .sidebar-brand:hover{background:rgba(15,31,61,.04);}
body.light-theme .nav-link:hover{background:rgba(15,31,61,.05);}
body.light-theme .cat-bar-bg{background:rgba(15,31,61,.08);}
body.light-theme .scale-legend-bar{background:rgba(15,31,61,.03);}
body.light-theme .btn-cancel-new:hover{background:rgba(15,31,61,.06);}
body.light-theme .suggestion-chip{background:rgba(15,31,61,.04);}
body.light-theme .notif-btn{background:rgba(15,31,61,.06);}
body.light-theme .notif-item{border-bottom:1px solid rgba(15,31,61,.07);}
body.light-theme .profile-dd-btn:hover{background:rgba(15,31,61,.06);}
body.light-theme .profile-dd-icon{background:rgba(15,31,61,.06);}
body.light-theme .dd-appearance-val{background:rgba(15,31,61,.06);}
body.light-theme .sidebar-title,
body.light-theme .nav-link.active,
body.light-theme .nav-page-title,
body.light-theme .welcome-text h2,
body.light-theme .stat-card-val,
body.light-theme .section-title,
body.light-theme .eval-modal-title,
body.light-theme .eval-info-value,
body.light-theme .peer-name,
body.light-theme .eval-name,
body.light-theme .q-text-new,
body.light-theme .profile-name,
body.light-theme .notif-header-title,
body.light-theme .eval-group-title,
body.light-theme .photo-modal-title{color:var(--light);}
body.light-theme .level-view-empty,
body.light-theme .peer-select-hint.warn,
body.light-theme .no-period-warn{color:#B45309;}
body.light-theme .notif-btn:hover,
body.light-theme .notif-btn.has-unread{color:#B45309;}
body.light-theme .info-note i{color:#2563EB;}
body.light-theme .btn-view-all-evals{color:#2563EB;}
body.light-theme .toast-success{color:#15803D;}
body.light-theme .toast-error{color:#B91C1C;}
body.light-theme .level-view-pill{color:#0F766E;}
.nav-icon-blue{color:#3B82F6;}
.nav-icon-purple{color:#8B5CF6;}
.nav-icon-green{color:#22C55E;}
.nav-icon-orange{color:#F97316;}
.profile-dd-icon.dd-icon-blue{background:rgba(59,130,246,.14);color:#3B82F6;}
.profile-dd-icon.dd-icon-amber{background:rgba(217,119,6,.14);color:#D97706;}
.profile-dd-icon.dd-icon-purple{background:rgba(139,92,246,.14);color:#8B5CF6;}
.profile-dd-btn:hover .profile-dd-icon.dd-icon-blue{background:rgba(59,130,246,.24);}
.profile-dd-btn:hover .profile-dd-icon.dd-icon-amber{background:rgba(217,119,6,.24);}
.profile-dd-btn:hover .profile-dd-icon.dd-icon-purple{background:rgba(139,92,246,.24);}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--dark);color:var(--light);min-height:100vh;display:flex;}
.sidebar{width:var(--sidebar-w);flex-shrink:0;background:var(--mid);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:40;transition:transform .3s;}
.sidebar-brand{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;cursor:pointer;transition:background .2s;position:relative;}
.sidebar-brand:hover{background:rgba(255,255,255,.04);}
.brand-avatar{width:42px;height:42px;border-radius:50%;flex-shrink:0;border:2px solid var(--teal);box-shadow:0 0 10px rgba(13,148,136,.3);overflow:hidden;background:var(--inner);display:flex;align-items:center;justify-content:center;}
.brand-avatar img{width:100%;height:100%;object-fit:cover;display:block;}
.brand-avatar .brand-initials{font-family:'Rajdhani',sans-serif;font-size:15px;font-weight:700;color:var(--teal-hover);line-height:1;}
.sidebar-title{font-family:'Rajdhani',sans-serif;font-size:14px;font-weight:700;letter-spacing:.4px;color:#fff;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:118px;}
.sidebar-sub{font-size:11px;color:var(--teal-hover);letter-spacing:.2px;margin-top:2px;font-weight:600;}
.sidebar-caret{font-size:11px;color:var(--muted);flex-shrink:0;transition:transform .2s;}
.sidebar-profile-dropdown{margin:0 12px;max-height:0;opacity:0;overflow:hidden;background:var(--inner);border-radius:10px;transition:max-height .2s ease,opacity .2s ease,margin .2s ease;}
.sidebar-profile-dropdown.open{max-height:320px;opacity:1;margin:8px 12px 10px;border:1px solid var(--border);}
.sidebar-nav{flex:1;padding:14px 12px;overflow-y:auto;}
.nav-section-label{font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:1.5px;color:#99F6E4;padding:0 8px;margin-bottom:7px;margin-top:18px;text-align:center;text-shadow:0 1px 8px rgba(45,212,191,.14);}
.nav-section-label:first-child{margin-top:0;}
.nav-link{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:9px;color:var(--muted);text-decoration:none;font-size:13.5px;font-weight:600;transition:all .2s;margin-bottom:2px;}
.nav-link:hover{background:rgba(255,255,255,.05);color:var(--light);}
.nav-link.active{background:rgba(13,148,136,.16);color:#fff;}
.nav-link.active i{color:var(--teal-hover);}
.nav-link i{font-size:14px;width:17px;text-align:center;flex-shrink:0;}
.nav-badge{margin-left:auto;background:var(--teal);color:#fff;font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;}
.sidebar-footer{padding:14px 16px;border-top:1px solid var(--border);}
.btn-logout-side{display:flex;align-items:center;gap:8px;width:100%;padding:9px 13px;border:1px solid rgba(240,84,84,.3);background:rgba(240,84,84,.07);border-radius:9px;color:#f87171;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;transition:all .2s;font-family:'DM Sans',sans-serif;}
.btn-logout-side:hover{background:rgba(240,84,84,.16);}
.top-nav{position:fixed;top:0;left:var(--sidebar-w);right:0;height:58px;z-index:30;background:var(--mid);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 26px;box-shadow:var(--shadow);}
.nav-page-title{font-family:'Rajdhani',sans-serif;font-size:19px;font-weight:700;letter-spacing:.4px;color:#fff;}
.period-badge{background:rgba(13,148,136,.14);border:1px solid rgba(13,148,136,.28);color:var(--teal-hover);padding:5px 13px;border-radius:20px;font-size:12px;font-weight:600;display:flex;align-items:center;gap:6px;}
.hamburger{display:none;background:none;border:none;color:var(--light);font-size:20px;cursor:pointer;padding:4px;}
.main{margin-left:var(--sidebar-w);margin-top:58px;padding:26px 28px;flex:1;min-height:calc(100vh - 58px);}
.toast{border-radius:9px;padding:11px 18px;font-size:13px;margin-bottom:20px;display:flex;align-items:center;gap:9px;animation:fadeUp .3s ease;}
.toast-success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.28);color:#86efac;}
.toast-error{background:rgba(240,84,84,.1);border:1px solid rgba(240,84,84,.28);color:#fca5a5;}
@keyframes fadeUp{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.evaluation-schedule-card{background:#fff;border:1px solid rgba(30,82,144,.14);border-radius:16px;padding:22px 26px;margin:0 0 22px;box-shadow:0 5px 18px rgba(15,35,60,.035);}
.schedule-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:20px;align-items:start;}
.schedule-summary-item{min-width:0;}
.schedule-label{display:block;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#E19A1D;margin-bottom:7px;}
.schedule-summary-item strong{display:block;font-size:15px;line-height:1.25;color:#10243E;font-weight:700;white-space:normal;}
.schedule-status{display:inline-flex;align-items:center;padding:6px 14px;border-radius:999px;font-size:12px;font-weight:700;border:1px solid #F3B0B0;background:#FFF1F1;color:#B42318;}
.schedule-status.open{border-color:#91E7B2;background:#E8FFF1;color:#168447;}
.schedule-status.closed{border-color:#F3B0B0;background:#FFF1F1;color:#B42318;}
.schedule-times{margin-top:19px;padding-top:16px;border-top:1px solid rgba(30,82,144,.10);}
.schedule-message{display:flex;align-items:center;gap:7px;flex-wrap:wrap;font-size:13px;color:#5C7187;}
.schedule-message i{color:#E19A1D;font-size:15px;margin-right:2px;}
.schedule-message strong{color:#10243E;}
@media(max-width:900px){.schedule-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
@media(max-width:600px){.evaluation-schedule-card{padding:18px 20px;}.schedule-summary-grid{grid-template-columns:1fr 1fr;gap:16px;}.schedule-message{display:block;line-height:1.7;}.schedule-message i{margin-right:5px;}}
.welcome-bar{background:linear-gradient(135deg, var(--mid) 0%, rgba(13,148,136,.12) 100%);border:1px solid rgba(13,148,136,.18);border-radius:var(--radius);padding:24px 28px;margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;}
.welcome-text h2{font-family:'Rajdhani',sans-serif;font-size:22px;font-weight:700;color:#fff;margin-bottom:5px;letter-spacing:.3px;}
.welcome-text p{font-size:13px;color:var(--muted);line-height:1.5;}
.welcome-text p strong{color:var(--teal-hover);}
.score-chip{background:rgba(13,148,136,.13);border:1px solid rgba(13,148,136,.25);border-radius:12px;padding:16px 26px;text-align:center;min-width:120px;flex:1 1 auto;max-width:340px;flex-shrink:0;align-self:stretch;display:flex;flex-direction:column;align-items:center;justify-content:center;}
.score-chip .sc-val{font-family:'Rajdhani',sans-serif;font-size:44px;font-weight:700;color:var(--teal-hover);line-height:1;}
.score-chip .sc-lbl{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:8px;}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
.stat-card{background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);padding:20px 22px;}
.stat-card-link{display:block;color:inherit;text-decoration:none;cursor:pointer;transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease;}
.stat-card-link:hover{transform:translateY(-2px);border-color:var(--teal-hover);box-shadow:0 8px 18px rgba(20,42,67,.10);}
.stat-card-link:focus-visible{outline:3px solid var(--teal-hover);outline-offset:3px;}
body.light-theme .stat-card-link:hover{border-color:#2563EB !important;box-shadow:0 8px 18px rgba(20,42,67,.10) !important;}
.stat-card-lbl{font-size:11px;text-transform:uppercase;letter-spacing:.9px;color:var(--muted);margin-bottom:12px;font-weight:700;}
.stat-card-val{font-size:28px;font-weight:700;color:#fff;line-height:1;}
.stat-card-val.teal{color:var(--teal-hover);}
.stat-card-val.gold{color:#F59E0B;}
.stat-card-val.sm{font-size:18px;margin-top:2px;}
.section-card{background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);padding:22px 24px;margin-bottom:20px;}
.section-title{font-family:'Rajdhani',sans-serif;font-size:17px;font-weight:700;color:#fff;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.section-title i{font-size:16px;}
.cat-row{display:flex;align-items:center;gap:12px;margin-bottom:11px;}
.cat-name{font-size:13px;color:var(--light);width:200px;flex-shrink:0;}
.cat-bar-bg{flex:1;height:6px;background:rgba(255,255,255,.06);border-radius:4px;overflow:hidden;}
.cat-bar-fill{height:100%;border-radius:4px;transition:width .5s ease;}
.cat-score{font-size:13px;font-weight:700;width:36px;text-align:right;flex-shrink:0;}
.eval-item{
    background:var(--inner);
    border:1px solid var(--border);
    border-radius:10px;
    padding:14px 18px;
    margin-bottom:8px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    flex-wrap:wrap;
    gap:10px;
}
.eval-item-left .anon{font-size:13px;color:var(--light);font-weight:600;display:flex;align-items:center;gap:5px;}
.eval-item-left .meta{font-size:12px;color:var(--muted);margin-top:2px;}
.btn-view-details{background:rgba(13,148,136,.13);border:1px solid rgba(13,148,136,.3);color:var(--teal-hover);font-size:11px;font-weight:700;padding:5px 12px;border-radius:20px;cursor:pointer;white-space:nowrap;font-family:'DM Sans',sans-serif;}
.btn-view-all-evals{
    width:100%;
    display:flex;
    align-items:center;
    gap:10px;
    background:rgba(43,108,176,.12);
    border:1px solid rgba(43,108,176,.3);
    color:#5b9bd8;
    font-size:13.5px;
    font-weight:700;
    padding:12px 16px;
    border-radius:10px;
    cursor:pointer;
    font-family:'DM Sans',sans-serif;
    transition:background .2s;
}
.btn-view-all-evals:hover{background:rgba(43,108,176,.2);}


/* Peer Evaluation questionnaire: modal structure, retaining the faculty theme variables. */
.peer-eval-overlay{position:fixed;inset:0;z-index:280;display:flex;align-items:flex-start;justify-content:center;padding:28px 16px;overflow-y:auto;background:rgba(8,20,39,.58);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px);}
.peer-eval-dialog{width:100%;max-width:960px;height:auto;max-height:calc(100vh - 56px);min-height:0;display:flex;flex-direction:column;overflow:hidden;background:var(--mid);border:1px solid var(--border);border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.35);}
.peer-eval-dialog-header{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:18px 24px;border-bottom:1px solid var(--border);background:var(--mid);flex:0 0 auto;}
.peer-eval-target{display:flex;align-items:center;gap:14px;min-width:0;}
.peer-eval-avatar{width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid var(--teal);box-shadow:0 0 0 3px rgba(37,99,235,.10);flex-shrink:0;}
.peer-eval-avatar-placeholder{display:flex;align-items:center;justify-content:center;background:var(--inner);color:var(--muted);font-size:20px;}
.peer-eval-target-copy{min-width:0;}
.peer-eval-target-copy h2{margin:0;color:var(--light);font-family:'Rajdhani',sans-serif;font-size:22px;font-weight:700;line-height:1.15;overflow-wrap:anywhere;}
.peer-eval-target-copy p{margin:2px 0 0;color:var(--muted);font-size:13px;}
.peer-eval-close{width:38px;height:38px;display:flex;align-items:center;justify-content:center;flex-shrink:0;border-radius:9px;color:var(--muted);font-size:22px;text-decoration:none;transition:background .15s,color .15s;}
.peer-eval-close:hover{background:var(--inner);color:var(--light);}
.peer-eval-form{display:flex;flex:1 1 auto;min-height:0;flex-direction:column;}
.peer-eval-scroll{padding:20px 24px;overflow-y:auto;overscroll-behavior:contain;min-height:0;flex:1 1 auto;}
.peer-eval-progress-banner{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px;padding:11px 16px;border:1px solid rgba(37,99,235,.2);border-radius:10px;background:rgba(37,99,235,.07);color:var(--light);font-size:13px;font-weight:600;}
.peer-eval-progress-banner>span{display:flex;align-items:center;gap:9px;}
.peer-eval-progress-banner>span i{color:var(--teal-hover);}
.peer-eval-progress-banner strong{color:var(--teal-hover);white-space:nowrap;font-size:13px;}
.peer-eval-scale{display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:10px 14px;margin-bottom:16px;border:1px solid var(--border);border-radius:10px;background:var(--inner);}
.peer-eval-scale-item{display:flex;align-items:center;gap:7px;color:var(--muted);font-size:12.5px;}
.peer-eval-scale-item span{width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;background:var(--teal);color:#fff;font-size:12px;font-weight:700;}
.peer-eval-scale-item em{font-style:normal;}
.peer-eval-question-section{margin-bottom:18px;}
.peer-eval-question-section .q-category-header{margin:18px 0 8px;padding:0 0 2px;border:0;font-size:12px;letter-spacing:.8px;}
.peer-eval-dialog .eval-form-wrap{margin:0;border-radius:14px;}
.peer-eval-dialog .eval-form-table th{padding:11px 8px;background:var(--inner);font-size:11px;}
.peer-eval-dialog .eval-form-table th:first-child{padding-left:16px;}
.peer-eval-dialog .eval-form-table td{padding:12px 8px;}
.peer-eval-dialog .eval-form-table td:first-child{padding-left:16px;}
.peer-eval-dialog .eval-form-qtext{font-size:14px;font-weight:500;}
.peer-eval-dialog .eval-form-qno{font-size:14px;}
.peer-eval-dialog .eval-form-rating label{width:36px;height:32px;border-radius:7px;font-size:13px;}
.peer-eval-dialog .eval-form-rating input:focus-visible+label{outline:3px solid rgba(37,99,235,.3);outline-offset:2px;}
.peer-eval-dialog .eval-question-row.eval-unanswered td{background:rgba(239,68,68,.055);}
.peer-eval-dialog .comment-box-new{margin:8px 0 0;}
.peer-eval-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 24px;border-top:1px solid var(--border);background:var(--mid);flex:0 0 auto;}
.peer-eval-footer .btn-cancel-new{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;white-space:nowrap;}
.peer-eval-footer .btn-submit-new{flex:1;justify-content:center;min-height:48px;box-shadow:0 6px 15px rgba(37,99,235,.17);}
.peer-eval-empty{padding:28px;color:var(--muted);display:flex;gap:10px;align-items:center;}
.peer-eval-empty i{color:#d97706;}
body.light-theme .peer-eval-dialog{background:#fff;border-color:#dce5f1;box-shadow:0 24px 80px rgba(15,31,61,.22);}
body.light-theme .peer-eval-dialog-header,body.light-theme .peer-eval-footer{background:#fff;border-color:#e1e8f2;}
body.light-theme .peer-eval-target-copy h2{color:#152b45;}
body.light-theme .peer-eval-target-copy p{color:#71839a;}
body.light-theme .peer-eval-progress-banner{background:#f2f6ff;border-color:#d7e3ff;}
body.light-theme .peer-eval-scale{background:#f7f9fc;border-color:#e0e8f3;}
body.light-theme .peer-eval-scale-item span{background:#2563eb;}
body.light-theme .peer-eval-dialog .eval-form-table th{background:#f3f7fd;color:#61758f;border-color:#e0e8f3;}
body.light-theme .peer-eval-dialog .eval-form-table td{border-color:#e9eef6;}
body.light-theme .peer-eval-dialog .eval-form-qtext{color:#243b53;}
body.light-theme .peer-eval-dialog .eval-form-rating label{background:#f4f7fc;border-color:#d6e2f2;color:#45617f;}
body.light-theme .peer-eval-dialog .eval-form-rating label:hover{background:#eaf1ff;border-color:#2563eb;color:#1d4ed8;}
body.light-theme .peer-eval-dialog .eval-form-rating input:checked+label{background:#2563eb;border-color:#2563eb;color:#fff;}
body.light-theme .peer-eval-dialog .q-category-header{color:#2563eb;}
body.light-theme .peer-eval-dialog .comment-box-label,body.light-theme .peer-eval-dialog .comment-box-icon{color:#15803D;}
body.light-theme .peer-eval-dialog .comment-box-new{background:#fff;border-color:#D9E4EF;border-radius:15px;padding:20px 20px 26px;}
body.light-theme .peer-eval-dialog .comment-textarea-new{box-sizing:border-box;min-height:113px;width:100%;background:#F8FAFC;color:#172033;border:1px solid #CBD5E1;border-radius:10px;padding:14px 18px;font-size:15px;line-height:1.5;}
body.light-theme .peer-eval-dialog .comment-textarea-new:focus{outline:none;border-color:#16A34A;box-shadow:0 0 0 3px rgba(22,163,74,.10);}
body.light-theme .peer-eval-dialog .comment-textarea-new::placeholder{color:#526C86;opacity:1;font-size:15px;font-weight:400;}
body.light-theme .peer-eval-footer .btn-submit-new{background:#2563eb;}
body.light-theme .peer-eval-footer .btn-submit-new:hover{background:#1d4ed8;}
@media(max-width:700px){
 .peer-eval-overlay{padding:8px;align-items:center;}
 .peer-eval-dialog{width:100%;height:calc(100vh - 16px);max-height:calc(100vh - 16px);border-radius:16px;}
 .peer-eval-dialog-header{padding:16px;min-height:0;}
 .peer-eval-avatar{width:52px;height:52px;}
 .peer-eval-target-copy h2{font-size:20px;}
 .peer-eval-target-copy p{font-size:12px;}
 .peer-eval-scroll{padding:16px 12px 20px;}
 .peer-eval-progress-banner{align-items:flex-start;flex-direction:column;gap:7px;padding:12px;font-size:12px;}
 .peer-eval-scale{gap:10px;padding:10px;}
 .peer-eval-scale-item{gap:5px;font-size:11px;}
 .peer-eval-scale-item span{width:26px;height:26px;}
 .peer-eval-dialog .eval-form-table th:not(:first-child){width:36px;}
 .peer-eval-dialog .eval-form-table th{padding:10px 2px;font-size:10px;}
 .peer-eval-dialog .eval-form-table td{padding:9px 2px;}
 .peer-eval-dialog .eval-form-table td:first-child{padding-left:8px;padding-right:5px;}
 .peer-eval-dialog .eval-form-qtext{font-size:12px;}
 .peer-eval-dialog .eval-form-rating label{width:29px;height:31px;border-radius:7px;font-size:12px;}
 .peer-eval-footer{padding:12px;gap:9px;min-height:0;}
 .peer-eval-footer .btn-cancel-new{padding:10px 14px;}
 .peer-eval-footer .btn-submit-new{padding:10px 12px;font-size:12px;min-height:44px;}
}
.eval-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:300;display:none;align-items:center;justify-content:center;padding:20px;}
.eval-modal-overlay.open{display:flex;}
.eval-modal{background:var(--mid);border:1px solid var(--border);border-radius:18px;width:100%;max-width:640px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.6);}
.eval-modal-header{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.eval-modal-title{font-family:'Rajdhani',sans-serif;font-size:19px;font-weight:700;color:#fff;}
.eval-modal-close{background:none;border:none;color:var(--muted);font-size:18px;cursor:pointer;}
.eval-modal-body{padding:22px 24px;overflow-y:auto;}
.eval-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px;}
.eval-info-item{background:var(--inner);border:1px solid var(--border);border-radius:10px;padding:12px 14px;}
.eval-info-label{font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);font-weight:700;margin-bottom:5px;}
.eval-info-value{font-size:13.5px;color:#fff;font-weight:600;}
.eval-modal-loading{text-align:center;padding:50px 20px;color:var(--muted);}
.eval-modal-loading i{font-size:26px;margin-bottom:10px;display:block;}
.eval-q-star{color:#374151;font-size:14px;}
.eval-q-star.filled{color:#facc15;}
.eval-comment-box{background:var(--inner);border:1px solid var(--border);border-radius:10px;padding:15px 17px;font-size:13.5px;color:var(--light);line-height:1.6;font-style:italic;}
.eval-comment-box.empty{color:var(--muted);font-style:normal;}
.score-pill{padding:4px 13px;border-radius:20px;font-size:13px;font-weight:700;}
.empty-state{text-align:center;padding:44px 20px;color:var(--muted);}
.empty-state i{font-size:36px;opacity:.25;display:block;margin-bottom:12px;}
.empty-state p{font-size:13px;}
.peer-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:16px;align-items:stretch;}
.peer-card{background:var(--mid);border:2px solid var(--border);border-radius:var(--radius);padding:18px 12px;display:flex;flex-direction:column;align-items:center;justify-content:flex-start;gap:8px;text-align:center;transition:all .2s;height:100%;box-sizing:border-box;}
.peer-card:hover:not(.done){border-color:var(--teal);transform:translateY(-3px);box-shadow:0 6px 20px rgba(0,0,0,.3);}
.peer-card.done{opacity:.6;cursor:not-allowed;border-color:rgba(34,197,94,.3);}
.peer-photo{width:68px;height:68px;border-radius:50%;object-fit:cover;border:2px solid var(--border);}
.peer-photo-ph{width:68px;height:68px;border-radius:50%;background:var(--inner);border:2px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:22px;}
.peer-photo,.peer-photo-ph{flex-shrink:0;}
.peer-name{font-size:13px;font-weight:600;color:#fff;line-height:1.3;min-height:2.6em;display:flex;align-items:center;justify-content:center;width:100%;}
.peer-desig{font-size:11px;color:var(--muted);line-height:1.35;width:100%;flex:1 1 auto;display:flex;align-items:flex-start;justify-content:center;}
.done-badge{background:rgba(34,197,94,.14);color:#4ade80;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700;margin-top:auto;display:inline-flex;align-items:center;gap:4px;min-height:29px;box-sizing:border-box;}
.btn-eval-peer{margin-top:auto;width:100%;padding:7px 0;border:none;border-radius:7px;background:var(--teal);color:#fff;font-size:12px;font-weight:700;cursor:pointer;transition:background .2s;font-family:'DM Sans',sans-serif;}
.btn-eval-peer:hover{background:var(--teal-hover);}
.btn-eval-peer:disabled{opacity:.45;cursor:not-allowed;}
.back-link{display:inline-flex;align-items:center;gap:7px;background:var(--inner);border:1px solid var(--border);color:var(--light);padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;margin-bottom:18px;transition:background .2s;}
.back-link:hover{background:var(--accent);}
.eval-header-bar{background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;gap:16px;}
.eval-photo-lg{width:58px;height:58px;border-radius:50%;object-fit:cover;border:2px solid var(--teal);}
.eval-photo-ph{width:58px;height:58px;border-radius:50%;background:var(--inner);border:2px solid var(--teal);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:20px;}
.eval-name{font-family:'Rajdhani',sans-serif;font-size:18px;font-weight:700;color:#fff;}
.eval-desig{font-size:12px;color:var(--muted);margin-top:2px;}
.scale-legend-bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:22px;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:10px;padding:12px 16px;}
.legend-pill{display:flex;align-items:center;gap:7px;padding:5px 13px;border-radius:8px;background:rgba(13,148,136,.12);border:1px solid rgba(13,148,136,.22);font-size:13px;font-weight:700;color:var(--teal-hover);}
.legend-pill .l-num{font-size:15px;font-weight:800;}
.legend-pill .l-lbl{font-size:11px;font-weight:600;color:var(--muted);}
.q-category-header{display:flex;align-items:center;gap:8px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;color:var(--teal-hover);margin:22px 0 10px;padding-bottom:8px;border-bottom:1px solid rgba(13,148,136,.18);}
.q-card-new{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:18px 20px;margin-bottom:10px;}
.q-no-new{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px;font-weight:700;}
.q-text-new{font-size:14px;font-weight:600;color:#fff;margin-bottom:14px;line-height:1.5;}
.eval-form-wrap{background:var(--mid);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin:0 0 10px}
.eval-form-table{width:100%;border-collapse:collapse;table-layout:fixed}
.eval-form-table th{background:rgba(255,255,255,.04);color:var(--muted);font-size:10px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;text-align:center;padding:10px 6px;border-bottom:1px solid var(--border)}
.eval-form-table th:first-child{text-align:left;width:auto;padding-left:16px}
.eval-form-table th:not(:first-child){width:56px}
.eval-form-table td{padding:12px 6px;border-bottom:1px solid var(--border);vertical-align:middle;text-align:center}
.eval-form-table tr:last-child td{border-bottom:none}
.eval-form-table td:first-child{text-align:left;padding-left:16px;padding-right:12px}
.eval-form-qtext{font-size:13px;font-weight:600;line-height:1.5;color:var(--light)}
.eval-form-qno{color:var(--teal-hover);font-weight:800;margin-right:6px}
.eval-form-rating{display:flex;justify-content:center;position:relative}
/* Keep the visually-hidden radio anchored to its own rating cell. Unanchored
   absolute inputs can sit at the page origin and make the viewport jump when
   a label activates/focuses them. */
.eval-form-rating input{position:absolute;left:0;top:0;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;opacity:0;pointer-events:none}
.eval-form-rating label{width:38px;height:34px;display:flex;align-items:center;justify-content:center;border-radius:8px;border:1px solid var(--border);background:var(--inner);color:var(--muted);font-size:14px;font-weight:800;cursor:pointer;transition:all .18s ease;font-family:'DM Sans',sans-serif}
.eval-form-rating label:hover{border-color:var(--teal);color:var(--teal-hover);background:rgba(13,148,136,.1)}
.eval-form-rating input:checked + label{background:var(--teal);border-color:var(--teal);color:#fff}
@media(max-width:700px){.eval-form-table th:not(:first-child){width:42px}.eval-form-rating label{width:30px;height:30px;font-size:12px}.eval-form-qtext{font-size:12px}}
.comment-box-new{background:var(--mid);border:1px solid var(--border);border-radius:15px;padding:20px 20px 26px;margin-bottom:10px;display:block;}
.comment-box-icon{color:var(--teal-hover);font-size:16px;flex-shrink:0;}
.comment-box-inner{width:100%;}
.comment-box-label{display:flex;align-items:center;gap:9px;font-size:15px;font-weight:700;color:var(--teal-hover);margin-bottom:11px;}
.comment-textarea-new{display:block;box-sizing:border-box;width:100%;background:var(--inner);border:1px solid var(--border);border-radius:10px;color:var(--light);padding:14px 18px;font-size:15px;font-family:'DM Sans',sans-serif;resize:vertical;outline:none;transition:border-color .2s;line-height:1.5;min-height:113px;}
.comment-textarea-new:focus{border-color:var(--teal);}
.comment-textarea-new::placeholder{color:var(--muted);opacity:1;font-size:15px;font-weight:400;}
/* required comment: red outlined box + inline message */
.comment-req{color:#ef4444;font-weight:800;}
.comment-box-new.invalid{border:1px solid #f87171!important;box-shadow:0 0 0 3px rgba(239,68,68,.12);}
.comment-error{margin-top:12px;font-size:13px;font-weight:700;color:#f87171;line-height:1.4;}
.comment-error[hidden]{display:none;}
body.light-theme .comment-error{color:#b91c1c;}
.submit-row-new{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:15px 20px;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;}
.btn-cancel-new{padding:10px 24px;background:var(--inner);border:1px solid var(--border);border-radius:var(--radius);color:var(--light);font-size:14px;font-weight:600;cursor:pointer;transition:background .2s;font-family:'DM Sans',sans-serif;}
.btn-cancel-new:hover{background:rgba(255,255,255,.06);}
.btn-submit-new{background:var(--teal);color:#fff;border:none;padding:11px 28px;border-radius:var(--radius);font-size:14px;font-weight:700;cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:8px;font-family:'DM Sans',sans-serif;}
.btn-submit-new:hover{background:var(--teal-hover);transform:translateY(-1px);}
.profile-card{background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);padding:26px;margin-bottom:20px;}
.profile-header{display:flex;align-items:center;gap:18px;margin-bottom:22px;padding-bottom:20px;border-bottom:1px solid var(--border);}
.profile-avatar-wrap{width:62px;height:62px;border-radius:50%;overflow:hidden;border:2.5px solid var(--teal);flex-shrink:0;background:var(--inner);display:flex;align-items:center;justify-content:center;color:var(--teal-hover);font-size:24px;}
.profile-avatar-wrap img{width:100%;height:100%;object-fit:cover;}
.profile-name{font-family:'Rajdhani',sans-serif;font-size:21px;font-weight:700;color:#fff;}
.profile-desig-badge{display:inline-flex;align-items:center;gap:5px;background:rgba(13,148,136,.14);border:1px solid rgba(13,148,136,.28);color:var(--teal-hover);font-size:12px;font-weight:700;padding:3px 12px;border-radius:20px;margin-top:6px;}
.fg-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:7px;display:block;}
.suggestion-chips{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:16px;}
.suggestion-chip{background:rgba(255,255,255,.04);border:1px solid var(--border);color:var(--muted);font-size:12px;font-weight:600;padding:5px 13px;border-radius:20px;cursor:pointer;transition:all .2s;}
.suggestion-chip:hover{background:rgba(13,148,136,.13);border-color:rgba(13,148,136,.35);color:var(--teal-hover);}
.suggestion-chip.is-current{background:rgba(13,148,136,.18);border-color:var(--teal);color:var(--teal-hover);cursor:default;}
.desig-input-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
.desig-input{flex:1;min-width:200px;background:var(--inner);border:1px solid var(--border);color:var(--light);padding:11px 15px;border-radius:10px;font-size:14px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s;}
.desig-input:focus{border-color:var(--teal);}
.btn-update-desig{background:var(--teal);color:#fff;border:none;padding:11px 22px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;transition:background .2s;font-family:'DM Sans',sans-serif;white-space:nowrap;}
.btn-update-desig:hover{background:var(--teal-hover);}
.info-note{background:rgba(43,108,176,.07);border:1px solid rgba(43,108,176,.18);border-radius:10px;padding:13px 17px;font-size:13px;color:var(--muted);display:flex;gap:10px;align-items:flex-start;margin-top:16px;}
.info-note i{color:#60a5fa;flex-shrink:0;margin-top:1px;}
.level-view-wrap{flex-shrink:0;background:var(--inner);border:1px solid var(--border);border-radius:12px;padding:14px 18px;min-width:220px;max-width:320px;transition:box-shadow .3s ease,border-color .3s ease;}
.level-view-wrap.flash{border-color:var(--teal);box-shadow:0 0 0 3px rgba(13,148,136,.25);}
.level-view-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);display:flex;align-items:center;gap:7px;margin-bottom:9px;}
.level-view-pills{display:flex;flex-wrap:wrap;gap:6px;}
.level-view-pill{background:rgba(13,148,136,.15);color:#5eead4;border:1px solid rgba(13,148,136,.35);font-size:12px;font-weight:700;padding:4px 12px;border-radius:20px;}
.level-view-empty{font-size:11.5px;color:#fcd34d;display:flex;align-items:center;gap:6px;line-height:1.5;}
.level-view-hint{font-size:11px;color:var(--muted);margin-top:9px;}
.toggle-switch{position:relative;display:inline-block;width:42px;height:24px;flex-shrink:0;cursor:pointer;}
.toggle-switch input{display:none;}
.toggle-slider{position:absolute;inset:0;background:var(--border);border-radius:20px;transition:background .2s;}
.toggle-slider::before{content:"";position:absolute;width:18px;height:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:transform .2s;}
.toggle-switch input:checked + .toggle-slider{background:var(--teal);}
.toggle-switch input:checked + .toggle-slider::before{transform:translateX(18px);}
.photo-upload-area{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-top:20px;padding-top:18px;border-top:1px solid var(--border);}
.photo-preview-circle{width:62px;height:62px;border-radius:50%;overflow:hidden;border:2px solid var(--teal);flex-shrink:0;background:var(--inner);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:22px;}
.photo-preview-circle img{width:100%;height:100%;object-fit:cover;}
.photo-upload-btn{display:inline-flex;align-items:center;gap:7px;background:rgba(13,148,136,.13);border:1px solid rgba(13,148,136,.35);color:var(--teal-hover);padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;}
.photo-upload-hint{font-size:11px;color:var(--muted);margin-top:5px;}
.no-period-warn{background:rgba(251,191,36,.07);border:1px solid rgba(251,191,36,.18);border-radius:10px;padding:16px 20px;margin-bottom:18px;display:flex;gap:10px;align-items:center;font-size:13px;color:#fcd34d;}

/* ── EVALUATION — STEP 1: DESIGNATION SELECT ── */
.eval-group-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px;margin-bottom:4px;}
.eval-group-card{background:var(--inner);border:1px solid var(--border);border-radius:14px;padding:30px 20px;display:flex;flex-direction:column;align-items:center;text-align:center;text-decoration:none;transition:all .2s;}
.eval-group-card:hover{border-color:var(--teal);transform:translateY(-3px);box-shadow:var(--shadow);}
.eval-group-icon{width:58px;height:58px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;margin-bottom:16px;}
.eval-group-icon.blue{background:rgba(59,130,246,.14);color:#3B82F6;}
.eval-group-icon.green{background:rgba(34,197,94,.14);color:#22C55E;}
.eval-group-icon.purple{background:rgba(139,92,246,.14);color:#8B5CF6;}
.eval-group-icon.gold{background:rgba(245,158,11,.14);color:#F59E0B;}
.eval-group-title{font-family:'Rajdhani',sans-serif;font-size:17px;font-weight:700;color:#fff;margin-bottom:4px;}
.eval-group-count{font-size:12.5px;color:var(--muted);}
.peer-select-hint{font-size:12px;color:var(--muted);display:flex;align-items:center;gap:6px;margin-top:14px;}
.peer-select-hint.warn{color:#fcd34d;}


/* Peer Evaluation refresh: category progress + compact roster, retaining the faculty blue theme. */
.peer-page-heading{font-family:'Rajdhani',sans-serif;font-size:29px;font-weight:800;color:var(--light);margin:0 0 12px;}
.peer-category-grid .eval-group-card{position:relative;min-height:170px;padding:22px 18px 18px;border-bottom:3px solid #2563EB;background:var(--inner);}
.peer-category-grid .eval-group-card:hover{border-color:#2563EB;}
.peer-category-grid .eval-group-card.is-selected{border-color:#2563EB;box-shadow:0 5px 18px rgba(37,99,235,.13);background:#F8FBFF;}
.peer-inline-categories{margin-bottom:22px;}
.peer-inline-categories .peer-page-heading{margin-bottom:14px;}
.peer-category-grid .eval-group-icon.blue{background:#E0F2FE;color:#0284C7;}
.peer-category-grid .eval-group-icon.green{background:#D1FAE5;color:#059669;}
.peer-category-grid .eval-group-icon.purple{background:#EDE9FE;color:#7C3AED;}
.peer-category-grid .eval-group-icon.gold{background:#DBEAFE;color:#2563EB;}
.peer-progress-pill{display:inline-flex;align-items:center;gap:5px;margin-top:10px;padding:4px 10px;border-radius:20px;background:#E0F2FE;color:#2563EB;font-size:11px;font-weight:800;}
.peer-target-section{padding:0!important;overflow:hidden;}
.peer-list-heading{display:flex;align-items:center;gap:10px;padding:13px 20px;border-bottom:1px solid var(--border);color:var(--light);font-size:16px;}
.peer-list-heading-icon{width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:9px;background:#DBEAFE;color:#2563EB;}
.peer-target-section>p{padding:0 20px;}
.peer-target-section .empty-state{padding:35px 20px;}
.peer-list-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:14px 20px;}
.peer-list-row{min-width:0;display:flex;align-items:center;gap:12px;padding:11px 13px;background:var(--inner);border:1px solid var(--border);border-radius:13px;}
.peer-list-row.done{opacity:.8;}
.peer-list-photo{width:40px;height:40px;border-radius:50%;object-fit:cover;flex:0 0 40px;border:1px solid #CBD5E1;}
.peer-list-photo-ph{display:flex;align-items:center;justify-content:center;background:#E8EEF7;color:#64748B;font-size:16px;}
.peer-list-person{flex:1;min-width:0;}
.peer-list-person .peer-name{display:block;min-height:0;text-align:left;color:var(--light);font-size:13px;line-height:1.35;}
.peer-list-person .peer-desig{display:block;text-align:left;color:var(--muted);font-size:11px;line-height:1.4;margin-top:2px;overflow-wrap:anywhere;}
.peer-status-pill{display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:20px;margin-top:5px;font-size:10px;font-weight:800;}
.peer-status-pill.is-pending{background:#FEF3C7;color:#B45309;}
.peer-status-pill.is-done{background:#D1FAE5;color:#047857;}
.peer-list-row .btn-eval-peer{width:auto;min-width:94px;margin:0;padding:8px 12px;white-space:nowrap;background:#2563EB;border-radius:7px;}
.peer-list-row .btn-eval-peer:hover{background:#1D4ED8;}
.peer-completed-label{font-size:11px;font-weight:800;color:#059669;white-space:nowrap;}
.peer-list-footer{display:flex;align-items:center;gap:15px;flex-wrap:wrap;padding:12px 20px;border-top:1px solid var(--border);font-size:11px;color:var(--muted);}
.peer-list-footer strong{color:#2563EB;}
.peer-period-label{margin-left:auto;}
body.light-theme .peer-page-heading{color:#13263F!important;}
body.light-theme .peer-category-grid .eval-group-card{background:#FFF!important;border-color:#D8E3F0!important;border-bottom-color:#2563EB!important;}
body.light-theme .peer-category-grid .eval-group-card.is-selected{background:#F2F7FF!important;border-color:#2563EB!important;}
body.light-theme .peer-category-grid .eval-group-title{color:#172B45!important;}
body.light-theme .peer-category-grid .eval-group-count{color:#71839A!important;}
body.light-theme .peer-target-section{background:#FFF!important;border-color:#DCE6F1!important;}
body.light-theme .peer-list-heading{color:#142A43!important;border-bottom-color:#DCE6F1!important;}
body.light-theme .peer-list-row{background:#F4F7FB!important;border-color:#D8E4F2!important;}
body.light-theme .peer-list-person .peer-name{color:#13263F!important;}
body.light-theme .peer-list-person .peer-desig{color:#60758D!important;}
body.light-theme .peer-list-footer{border-top-color:#DCE6F1!important;}
@media(max-width:760px){.peer-list-grid{grid-template-columns:1fr;padding:12px;}.peer-list-row{gap:9px;padding:10px;}.peer-list-row .btn-eval-peer{min-width:0;padding:8px;font-size:11px;}.peer-period-label{margin-left:0;}}

/* ── NOTIFICATION BELL ── */
.notif-wrap{position:relative;display:flex;align-items:center;}
.notif-btn{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.06);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:15px;cursor:pointer;transition:all .2s;position:relative;}
.notif-btn:hover,.notif-btn.has-unread{color:#facc15;border-color:rgba(250,204,21,.4);background:rgba(250,204,21,.08);}
.notif-badge{position:absolute;top:-4px;right:-4px;min-width:18px;height:18px;border-radius:9px;background:#ef4444;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 4px;border:2px solid var(--mid);}
.notif-dropdown{position:absolute;top:calc(100% + 10px);right:0;width:min(320px,calc(100vw - 24px));max-height:min(70vh,560px);display:flex;flex-direction:column;background:var(--mid);border:1px solid var(--border);border-radius:14px;box-shadow:0 16px 48px rgba(0,0,0,.55);opacity:0;visibility:hidden;transform:translateY(-8px);transition:all .2s ease;z-index:120;overflow:hidden;}
.notif-dropdown.show{opacity:1;visibility:visible;transform:translateY(0);}
.notif-header{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--border);}
.notif-header-title{font-size:13px;font-weight:700;color:#fff;display:flex;align-items:center;gap:7px;}
.notif-mark-read{font-size:11px;color:var(--teal-hover);cursor:pointer;font-weight:600;background:none;border:none;font-family:'DM Sans',sans-serif;padding:0;}
.notif-list{flex:1 1 auto;min-height:0;max-height:100%;overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;scrollbar-width:thin;scrollbar-color:rgba(148,163,184,.55) transparent;}
.notif-list::-webkit-scrollbar{width:7px;}
.notif-list::-webkit-scrollbar-track{background:transparent;}
.notif-list::-webkit-scrollbar-thumb{background:rgba(148,163,184,.55);border-radius:8px;}
.notif-item{display:flex;align-items:flex-start;gap:10px;padding:11px 14px;border-bottom:1px solid rgba(255,255,255,.05);position:relative;}
.notif-item:last-child{border-bottom:none;}
a.notif-item{text-decoration:none;color:inherit;cursor:pointer;transition:background .15s ease;}
a.notif-item:hover,a.notif-item:focus-visible{background:rgba(255,255,255,.07);outline:none;}
body.light-theme a.notif-item:hover,body.light-theme a.notif-item:focus-visible{background:rgba(15,31,61,.06);}
a.notif-item::after{content:'\f054';font-family:'Font Awesome 6 Free';font-weight:900;font-size:10px;color:var(--muted);align-self:center;margin-left:auto;padding-left:6px;opacity:0;transform:translateX(-3px);transition:opacity .15s ease,transform .15s ease;}
a.notif-item:hover::after,a.notif-item:focus-visible::after{opacity:1;transform:translateX(0);color:var(--teal-hover);}
a.notif-item:hover,body.light-theme a.notif-item:hover,a.notif-item:focus-visible,body.light-theme a.notif-item:focus-visible{background:rgba(128,128,128,.10);background:color-mix(in srgb,var(--teal) 12%,transparent);}
a.notif-item:focus-visible{outline:2px solid var(--teal);outline-offset:-2px;}
a.notif-item:active{background:color-mix(in srgb,var(--teal) 20%,transparent);}
.notif-item.unread{background:rgba(13,148,136,.07);}
.notif-item.unread::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--teal);border-radius:0 2px 2px 0;}
.notif-icon{width:30px;height:30px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:12px;margin-top:1px;}
.notif-text{font-size:12px;color:var(--light);line-height:1.45;margin-bottom:3px;word-break:break-word;}
.notif-meta{font-size:11px;color:var(--muted);}
.notif-empty{text-align:center;padding:32px 16px;color:var(--muted);font-size:13px;}
.notif-empty i{font-size:28px;display:block;margin-bottom:8px;opacity:.2;}
.live-notif-toast{position:fixed;top:74px;right:28px;z-index:250;max-width:380px;display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border:1px solid rgba(16,185,129,.35);border-radius:12px;background:var(--mid);box-shadow:0 16px 40px rgba(0,0,0,.25);color:var(--light);opacity:0;transform:translateY(-10px);pointer-events:none;transition:opacity .22s ease,transform .22s ease;}
.live-notif-toast.show{opacity:1;transform:translateY(0);pointer-events:auto;cursor:pointer;}
.live-notif-toast-icon{width:30px;height:30px;flex:0 0 30px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:rgba(16,185,129,.12);color:#10b981;}
.live-notif-toast strong{display:block;font-size:12px;color:#fff;margin-bottom:2px;}
.live-notif-toast span{display:block;font-size:12px;line-height:1.4;color:var(--light);}
@media (max-width:700px){.live-notif-toast{left:16px;right:16px;top:70px;max-width:none;}}


/* ── SIDEBAR PROFILE DROPDOWN ── */
.profile-dd-btn{width:100%;padding:9px 10px;border-radius:10px;border:none;background:none;color:var(--light);font-size:13.5px;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;display:flex;align-items:center;gap:12px;transition:background .18s;text-align:left;text-decoration:none;}
.profile-dd-btn:hover{background:rgba(255,255,255,.06);}
.profile-dd-icon{width:34px;height:34px;border-radius:9px;background:rgba(255,255,255,.06);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:14px;flex-shrink:0;transition:all .18s;}
.profile-dd-btn:hover .profile-dd-icon{background:rgba(13,148,136,.16);color:var(--teal-hover);}
.profile-dd-divider{height:1px;background:var(--border);margin:6px 4px;}
.profile-dd-btn.logout{color:#f87171;}
.profile-dd-btn.logout .profile-dd-icon{background:rgba(240,84,84,.1);color:#f87171;}
.profile-dd-btn.logout:hover{background:rgba(240,84,84,.08);}
.profile-dd-btn.logout:hover .profile-dd-icon{background:rgba(240,84,84,.18);color:#f87171;}
.dd-appearance-val{margin-left:auto;font-size:11px;color:var(--muted);font-weight:700;background:rgba(255,255,255,.06);padding:3px 9px;border-radius:20px;flex-shrink:0;}
.profile-dd-btn:hover .dd-appearance-val{color:var(--teal-hover);}

/* ── PROFILE PHOTO MODAL ── */
.photo-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:300;display:none;align-items:center;justify-content:center;padding:20px;}
.photo-modal-overlay.open{display:flex;}
.photo-modal{background:var(--mid);border:1px solid var(--border);border-radius:18px;width:100%;max-width:400px;box-shadow:0 20px 60px rgba(0,0,0,.6);overflow:hidden;}
.photo-modal-header{padding:20px 24px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.photo-modal-title{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;color:#fff;}
.photo-modal-body{padding:24px;}
.photo-upload-circle{width:110px;height:110px;border-radius:50%;border:3px dashed rgba(13,148,136,.5);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;cursor:pointer;transition:border-color .2s;overflow:hidden;position:relative;background:var(--inner);}
.photo-upload-circle:hover{border-color:var(--teal);}
.photo-upload-circle img{width:100%;height:100%;object-fit:cover;display:none;border-radius:50%;}
.photo-upload-circle .upload-icon-modal{color:var(--muted);font-size:32px;transition:color .2s;}
.photo-upload-circle:hover .upload-icon-modal{color:var(--teal);}
.photo-upload-hint-modal{text-align:center;font-size:12px;color:var(--muted);margin-bottom:20px;}
.photo-upload-hint-modal span{color:var(--teal-hover);font-weight:600;cursor:pointer;}
.photo-modal-footer{padding:0 24px 24px;display:flex;gap:10px;}
.btn-save-photo{flex:1;padding:11px;background:var(--teal);border:none;border-radius:var(--radius);color:#fff;font-size:14px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;}
.btn-save-photo:hover{background:var(--teal-hover);}
.btn-skip-photo{flex:1;padding:11px;background:var(--inner);border:1px solid var(--border);border-radius:var(--radius);color:var(--muted);font-size:14px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;}

@media(max-width:1024px){.stats-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:900px){.sidebar{transform:translateX(-100%);}.sidebar.open{transform:translateX(0);}.top-nav{left:0;}.main{margin-left:0;}.hamburger{display:block;}}
@media(max-width:600px){.main{padding:16px;}.stats-grid{grid-template-columns:1fr 1fr;}.peer-grid{grid-template-columns:repeat(auto-fill,minmax(130px,1fr));}.desig-input-row{flex-direction:column;}.welcome-bar{padding:18px 20px;}.welcome-text h2{font-size:19px;}.eval-group-grid{grid-template-columns:1fr;}}

/* STAFF-STYLE PROFILE / ROLE LAYOUT — Faculty theme */
.profile-hero{background:linear-gradient(135deg,var(--mid) 0%,rgba(13,148,136,.18) 100%);border:1px solid rgba(13,148,136,.25);border-radius:16px;padding:28px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;gap:22px;flex-wrap:wrap;}
.profile-hero-left{display:flex;align-items:center;gap:22px;flex-wrap:wrap;}
.profile-hero-avatar{width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid var(--teal);box-shadow:0 0 18px rgba(13,148,136,.4);flex-shrink:0;display:flex;align-items:center;justify-content:center;color:var(--teal-hover);font-size:30px;background:var(--inner);}
.profile-hero-name{font-family:'Rajdhani',sans-serif;font-size:26px;font-weight:700;color:#fff;margin-bottom:6px;}
.profile-hero-desig{display:inline-flex;align-items:center;gap:7px;background:rgba(13,148,136,.2);border:1px solid rgba(13,148,136,.35);color:var(--teal-hover);font-size:13px;font-weight:700;padding:5px 14px;border-radius:20px;}
.role-card{background:var(--mid);border:2px solid rgba(13,148,136,.3);border-radius:16px;padding:26px;margin-bottom:22px;position:relative;overflow:hidden;}
.role-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--teal),var(--teal-hover));}
.role-card-title{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;color:#fff;margin-bottom:4px;display:flex;align-items:center;gap:9px;}
.role-card-sub{font-size:13px;color:var(--muted);margin-bottom:22px;}
.role-chips-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:10px;display:flex;align-items:center;gap:6px;}
.role-chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;}
.role-chip{padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:2px solid var(--border);background:var(--inner);color:var(--muted);transition:all .22s;display:flex;align-items:center;gap:6px;}
.role-chip:hover{border-color:var(--teal);color:var(--teal-hover);background:rgba(13,148,136,.1);}
.role-chip.is-current{border-color:var(--teal);background:rgba(13,148,136,.18);color:var(--teal-hover);pointer-events:none;}
.role-chip.is-current::after{content:'✓';margin-left:2px;font-size:12px;}
.custom-role-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:8px;display:flex;align-items:center;gap:6px;}
.custom-role-row{display:flex;gap:10px;flex-wrap:wrap;}
.custom-role-input{flex:1;min-width:220px;background:var(--inner);border:2px solid var(--border);color:var(--light);padding:13px 16px;border-radius:10px;font-size:14px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s;}
.custom-role-input:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(13,148,136,.2);}
.custom-role-input::placeholder{color:var(--muted);}
.btn-save-role{background:var(--teal);color:#fff;border:none;padding:13px 28px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;transition:all .2s;font-family:'DM Sans',sans-serif;white-space:nowrap;}
.btn-save-role:hover{background:var(--teal-hover);transform:translateY(-1px);}

.settings-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:18px;}
.settings-card{background:var(--inner);border:1px solid var(--border);border-radius:14px;padding:18px;}
.settings-card.full{grid-column:1/-1;}
.settings-card-head{display:flex;align-items:center;gap:11px;margin-bottom:14px;}
.settings-card-head .sicon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:rgba(13,148,136,.12);color:var(--teal-hover);}
.settings-card-head h3{margin:0;color:var(--light);font-size:15px;}
.settings-card-head p{margin:3px 0 0;color:var(--muted);font-size:11.5px;}
.setting-row{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:12px 0;border-top:1px solid var(--border);}
.setting-row:first-of-type{border-top:0;}
.setting-row strong{display:block;color:var(--light);font-size:13px;}
.setting-row span{display:block;color:var(--muted);font-size:11px;margin-top:3px;line-height:1.45;}
.setting-toggle{position:relative;width:44px;height:24px;flex:0 0 44px;}
.setting-toggle input{display:none;}
.setting-toggle .slider{position:absolute;inset:0;border-radius:20px;background:var(--border);cursor:pointer;transition:.2s;}
.setting-toggle .slider:before{content:'';position:absolute;width:18px;height:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s;}
.setting-toggle input:checked+.slider{background:var(--teal);}
.setting-toggle input:checked+.slider:before{transform:translateX(20px);}
.settings-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:16px;}
.settings-save{border:0;border-radius:9px;background:var(--teal);color:#fff;font-weight:700;padding:10px 16px;cursor:pointer;}
.settings-save:hover{background:var(--teal-hover);}
.account-facts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;}
.account-fact{background:var(--mid);border:1px solid var(--border);border-radius:10px;padding:12px;}
.account-fact label{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);margin-bottom:4px;}
.account-fact b{color:var(--light);font-size:12.5px;}
.account-name-view{display:flex;align-items:center;justify-content:space-between;gap:8px;}
.account-name-edit{border:0;background:transparent;color:var(--teal-hover);cursor:pointer;padding:4px 6px;border-radius:6px;font-size:12px;}
.account-name-edit:hover{background:rgba(13,148,136,.14);}
.account-name-form input[type=text]{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);background:var(--inner);color:var(--light);border-radius:8px;font-size:12.5px;outline:none;}
.account-name-form input[type=text]:focus{border-color:var(--teal);}
.account-name-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:8px;}
.account-name-save,.account-name-cancel{border-radius:8px;font-size:11.5px;font-weight:700;padding:6px 12px;cursor:pointer;}
.account-name-save{border:0;background:var(--teal);color:#fff;}
.account-name-save:hover{background:var(--teal-hover);}
.account-name-cancel{background:transparent;color:var(--muted);border:1px solid var(--border);}
@media(max-width:760px){.settings-grid{grid-template-columns:1fr}.settings-card.full{grid-column:auto}.account-facts{grid-template-columns:1fr}}


/* ── Light theme refinement: white surface + coordinated text/tab/icon states ── */
body.light-theme .nav-section-label{
    color:#0F766E;
    text-shadow:none;
}
body.light-theme .nav-link{
    color:#52677A;
}
body.light-theme .nav-link:hover{
    background:rgba(15,31,61,.055);
    color:#16263B;
}
body.light-theme .nav-link.active{
    background:rgba(13,148,136,.11);
    color:#0F766E;
    font-weight:700;
}
body.light-theme .nav-link.active i{
    color:#0F766E;
}
body.light-theme .nav-badge{
    background:#0D9488;
    color:#FFFFFF;
}
body.light-theme .period-badge{
    background:rgba(13,148,136,.09);
    border-color:rgba(13,148,136,.22);
    color:#0F766E;
}
body.light-theme .sidebar-sub{
    color:#0F766E;
}
body.light-theme .brand-avatar{
    border-color:#0D9488;
    box-shadow:0 0 10px rgba(13,148,136,.16);
    background:#EEF2F8;
}
body.light-theme .brand-avatar .brand-initials{
    color:#0F766E;
}
body.light-theme .profile-dd-btn{
    color:#16263B;
}
body.light-theme .profile-dd-btn:hover{
    background:rgba(15,31,61,.055);
}
body.light-theme .profile-dd-icon{
    box-shadow:none;
}
body.light-theme .dd-appearance-val{
    color:#52677A;
    background:#EEF2F8;
}
/* Required-rating feedback for peer evaluations */
.eval-validation-alert{display:none;margin:0 0 12px;padding:11px 14px;border-radius:9px;border:1px solid rgba(240,84,84,.34);background:rgba(240,84,84,.10);color:#fca5a5;font-size:13px;line-height:1.5;}
.eval-validation-alert.show{display:block;}
.eval-validation-alert strong{color:#fff;}
.eval-question-row.eval-unanswered{background:rgba(240,84,84,.06);}
.eval-question-row.eval-unanswered td:first-child{border-left:3px solid #f87171;}
.eval-progress{display:flex;align-items:center;justify-content:center;width:max-content;padding:6px 10px;margin:0 0 12px;border-radius:7px;background:rgba(255,255,255,.05);color:var(--muted);font-size:12px;font-weight:600;}
.eval-progress.is-complete{color:#86efac;background:rgba(34,197,94,.10);}

body.light-theme .eval-modal,
body.light-theme .eval-item{
    box-shadow:0 6px 22px rgba(15,31,61,.055);
}
body.light-theme .peer-card:hover{
    border-color:rgba(13,148,136,.24);
}
body.light-theme .peer-card.done{
    background:#F8FAFD;
}
body.light-theme .peer-select-input{
    background-color:#FFFFFF;
    color:#16263B;
}
body.light-theme .peer-select-input::placeholder{
    color:#7A8B9B;
}
body.light-theme .eval-form-table th,
body.light-theme .compact-eval-table th{
    background:#EEF2F8;
    color:#5B7186;
}
body.light-theme .btn-eval-peer,
body.light-theme .btn-proceed-peer,
body.light-theme .btn-save-photo{
    color:#FFFFFF;
}

    
/* Core light surfaces — Faculty */
body.light-theme,
body.light-theme .main{background:#F3F6FB;color:#16263B;}
body.light-theme .sidebar,
body.light-theme .top-nav{background:#FFFFFF;}
body.light-theme .nav-page-title,
body.light-theme .sidebar-title,
body.light-theme .welcome-text h2,
body.light-theme .section-title,
body.light-theme .stat-card-val,
body.light-theme .peer-name,
body.light-theme .eval-name{color:#16263B;}

/* ── Final Faculty white + blue role theme ───────────────────────────── */
body.light-theme{
    --accent:#2563EB;
    --hover:#1D4ED8;
    --teal:#2563EB;
    --teal-light:rgba(37,99,235,.10);
    --teal-hover:#1D4ED8;
    --dark:#F3F6FB;
    --mid:#FFFFFF;
    --inner:#F8FAFD;
    --light:#16263B;
    --muted:#52677A;
    --border:rgba(15,31,61,.10);
    --shadow:0 6px 22px rgba(15,31,61,.055);
}
body.light-theme .nav-section-label{color:#1D4ED8;text-shadow:none;}
body.light-theme .nav-link{color:#52677A;}
body.light-theme .nav-link:hover{background:rgba(37,99,235,.055);color:#16263B;}
body.light-theme .nav-link.active{background:rgba(37,99,235,.10);color:#1D4ED8;border-left:3px solid #2563EB;padding-left:9px;}
body.light-theme .nav-link.active i{color:#2563EB;}
body.light-theme .nav-badge{background:#2563EB;color:#fff;}
body.light-theme .period-badge{background:rgba(37,99,235,.08);border-color:rgba(37,99,235,.20);color:#1D4ED8;}
body.light-theme .sidebar-sub{color:#1D4ED8;}
body.light-theme .brand-avatar{border-color:#2563EB;box-shadow:0 0 10px rgba(37,99,235,.16);background:#EEF2F8;}
body.light-theme .brand-avatar .brand-initials{color:#1D4ED8;}
body.light-theme .welcome-bar{background:linear-gradient(135deg,#FFFFFF 0%,rgba(37,99,235,.055) 100%);border-color:rgba(37,99,235,.16);}
body.light-theme .welcome-text p strong{color:#2563EB;}
body.light-theme .score-chip{background:rgba(37,99,235,.07);border-color:rgba(37,99,235,.18);}
body.light-theme .score-chip .sc-val{color:#1D4ED8;}
body.light-theme .stat-card-val.teal{color:#1D4ED8;}
body.light-theme .section-title i{color:#2563EB !important;}
body.light-theme .btn-view-details{background:rgba(37,99,235,.07);border-color:rgba(37,99,235,.20);color:#1D4ED8;}
body.light-theme .btn-view-all-evals{background:rgba(37,99,235,.07);border-color:rgba(37,99,235,.18);color:#1D4ED8;}
body.light-theme .btn-view-all-evals:hover{background:rgba(37,99,235,.12);}
body.light-theme .profile-hero{background:linear-gradient(135deg,#FFFFFF 0%,rgba(37,99,235,.06) 100%);border-color:rgba(37,99,235,.18);}
body.light-theme .profile-hero-avatar{border-color:#2563EB;box-shadow:0 0 18px rgba(37,99,235,.20);color:#1D4ED8;}
body.light-theme .profile-hero-desig,
body.light-theme .profile-desig-badge{color:#1D4ED8;}
body.light-theme .profile-desig-badge{background:rgba(37,99,235,.08);border-color:rgba(37,99,235,.20);}
body.light-theme .suggestion-chip:hover{background:rgba(37,99,235,.08);border-color:rgba(37,99,235,.24);color:#1D4ED8;}
body.light-theme .suggestion-chip.is-current{background:rgba(37,99,235,.11);border-color:#2563EB;color:#1D4ED8;}
body.light-theme .peer-card:hover{border-color:rgba(37,99,235,.24);box-shadow:0 8px 24px rgba(37,99,235,.08);}
body.light-theme .eval-group-card:hover{border-color:#2563EB;box-shadow:0 8px 24px rgba(37,99,235,.08);}
body.light-theme .level-view-wrap.flash{border-color:#2563EB;box-shadow:0 0 0 3px rgba(37,99,235,.14);}
body.light-theme .level-view-pill{background:rgba(37,99,235,.08);color:#1D4ED8;border-color:rgba(37,99,235,.20);}
body.light-theme .photo-upload-circle{border-color:rgba(37,99,235,.35);}
body.light-theme .photo-upload-btn{background:rgba(37,99,235,.07);border-color:rgba(37,99,235,.22);color:#1D4ED8;}
body.light-theme .btn-save-photo,
body.light-theme .btn-submit-new,
body.light-theme .btn-update-desig,
body.light-theme .btn-eval-peer,
body.light-theme .btn-proceed-peer{background:#2563EB;}
body.light-theme .eval-form-rating label:hover{border-color:#2563EB;color:#1D4ED8;background:rgba(37,99,235,.07);}
body.light-theme .eval-form-rating input:checked + label{background:#2563EB;border-color:#2563EB;color:#fff;}
body.light-theme .q-category-header{color:#1D4ED8;border-bottom-color:rgba(37,99,235,.18);}
body.light-theme .legend-pill{background:rgba(37,99,235,.07);border-color:rgba(37,99,235,.18);color:#1D4ED8;}
body.light-theme .scale-legend-num{background:#2563EB;}
body.light-theme .notif-item.unread{background:rgba(37,99,235,.05);}
body.light-theme .profile-dd-btn:hover .profile-dd-icon{background:rgba(37,99,235,.12);color:#1D4ED8;}
body.light-theme .settings-card-head .sicon{background:rgba(37,99,235,.09);color:#1D4ED8;}
body.light-theme .compact-eval-qno{color:#1D4ED8;}
body.light-theme .compact-eval-rating button:hover{border-color:#2563EB;background:rgba(37,99,235,.07);}
body.light-theme .compact-eval-rating button.selected{background:#2563EB;border-color:#2563EB;}


/* LIGHT CONTENT + DARK ROLE SIDEBAR */
body.light-theme .sidebar{
    background:#172A45 !important;
    border-right-color:rgba(255,255,255,.08) !important;
}
body.light-theme .sidebar-brand{
    background:#172A45 !important;
    border-bottom-color:rgba(255,255,255,.08) !important;
}
body.light-theme .sidebar-brand:hover{
    background:#1B3455 !important;
}
body.light-theme .sidebar-title{
    color:#FFFFFF !important;
}
body.light-theme .sidebar-sub{
    color:#60A5FA !important;
}
body.light-theme .sidebar-caret{
    color:#A0B3C6 !important;
}
body.light-theme .brand-avatar{
    border-color:#2563EB !important;
    background:#0F1F3D !important;
    box-shadow:0 0 12px rgba(37,99,235,.28) !important;
}
body.light-theme .brand-avatar .brand-initials{
    color:#60A5FA !important;
}
body.light-theme .sidebar-profile-dropdown{
    background:#0F1F3D !important;
    border-color:rgba(255,255,255,.08) !important;
}
body.light-theme .profile-dd-btn{
    color:#E0E6F0 !important;
}
body.light-theme .profile-dd-btn:hover{
    background:rgba(255,255,255,.06) !important;
}
body.light-theme .profile-dd-btn i{
    color:#A0B3C6 !important;
}
body.light-theme .dd-appearance-val{
    background:rgba(255,255,255,.08) !important;
    color:#A0B3C6 !important;
}
body.light-theme .nav-section-label{
    color:#93C5FD !important;
    text-shadow:0 1px 8px rgba(37,99,235,.12) !important;
}
body.light-theme .nav-link{
    color:#A0B3C6 !important;
}
body.light-theme .nav-link:hover{
    background:rgba(255,255,255,.06) !important;
    color:#FFFFFF !important;
}
body.light-theme .nav-link.active{
    background:rgba(37,99,235,.18) !important;
    color:#FFFFFF !important;
    border-left:3px solid #2563EB !important;
}
body.light-theme .nav-link.active i{
    color:#3B82F6 !important;
}
body.light-theme .nav-link .badge{
    background:#2563EB !important;
    color:#FFFFFF !important;
}
body.light-theme .nav-icon-blue{color:#3B82F6 !important;}
body.light-theme .nav-icon-purple{color:#8B5CF6 !important;}
body.light-theme .nav-icon-green{color:#22C55E !important;}
body.light-theme .nav-icon-orange{color:#F97316 !important;}
body.light-theme .profile-dd-icon.dd-icon-blue{color:#60A5FA !important;}
body.light-theme .profile-dd-icon.dd-icon-amber{color:#FBBF24 !important;}
body.light-theme .profile-dd-icon.dd-icon-purple{color:#A78BFA !important;}
body.light-theme .sidebar-footer{
    border-top-color:rgba(255,255,255,.08) !important;
    background:#172A45 !important;
}
body.light-theme .btn-logout-side{
    background:rgba(240,84,84,.08) !important;
    border-color:rgba(240,84,84,.30) !important;
    color:#FCA5A5 !important;
}
body.light-theme .btn-logout-side:hover{
    background:rgba(240,84,84,.16) !important;
}

/* ================================================================
   REFERENCE-STYLE LIGHT DASHBOARD
   Inspired by the Student Portal visual language: dark sidebar,
   airy light workspace, crisp white cards, soft borders/shadows,
   role accent used consistently throughout.
   ================================================================ */
body.light-theme{
    --light:#13263F;
    --muted:#536A82;
    --dark:#F4F7FB;
    --mid:#FFFFFF;
    --inner:#F7F9FC;
    --border:#DFE6EE;
    --accent:#2563EB;
    --hover:#1D4ED8;
    --teal:#2563EB;
    --teal-hover:#1D4ED8;
    --teal-light:rgba(37,99,235,.10);
    --shadow:0 8px 26px rgba(18,43,73,.06);
}
body.light-theme .sidebar{
    background:#081B2E !important;
    border-right:1px solid rgba(255,255,255,.08) !important;
}
body.light-theme .sidebar-brand,
body.light-theme .sidebar-footer{background:#081B2E !important;}
body.light-theme .sidebar-brand{border-bottom-color:rgba(255,255,255,.08) !important;}
body.light-theme .sidebar-title{color:#fff !important;}
body.light-theme .sidebar-sub{color:#60A5FA !important;}
body.light-theme .nav-section-label{color:#93C5FD !important;}
body.light-theme .nav-link{color:#B6C5D6 !important;}
body.light-theme .nav-link:hover{background:rgba(255,255,255,.06) !important;color:#fff !important;}
body.light-theme .nav-link.active{background:rgba(37,99,235,.16) !important;color:#fff !important;border-left:3px solid #2563EB !important;}
body.light-theme .nav-link.active i{color:#60A5FA !important;}
body.light-theme .sidebar-caret{color:#AFC0D2 !important;}
body.light-theme .brand-avatar{border-color:#2563EB !important;box-shadow:0 0 14px rgba(37,99,235,.25) !important;}
body.light-theme .sidebar-profile-dropdown{background:#0E2944 !important;border-color:rgba(255,255,255,.08) !important;}
body.light-theme .profile-dd-btn{color:#E6EDF5 !important;}
body.light-theme .profile-dd-btn:hover{background:rgba(255,255,255,.06) !important;}
body.light-theme .dd-appearance-val{background:rgba(255,255,255,.08) !important;color:#B6C5D6 !important;}
body.light-theme .sidebar-footer{border-top-color:rgba(255,255,255,.08) !important;}
body.light-theme .btn-logout-side{background:rgba(248,113,113,.08) !important;color:#FCA5A5 !important;border-color:rgba(248,113,113,.28) !important;}
body.light-theme .btn-logout-side:hover{background:rgba(248,113,113,.15) !important;}
body.light-theme .top-nav{background:#FFFFFF !important;border-bottom-color:#DEE6EF !important;box-shadow:0 2px 14px rgba(15,34,55,.04) !important;}
body.light-theme .nav-page-title{color:#13263F !important;}
body.light-theme .period-badge{background:rgba(37,99,235,.07) !important;border-color:rgba(37,99,235,.20) !important;color:#1D4ED8 !important;}
body.light-theme .main{background:#F4F7FB !important;color:#13263F !important;padding:24px 28px 42px !important;}

/* Dashboard shell */
body.light-theme .dashboard-shell{
    background:linear-gradient(180deg,#FFFFFF 0%,#F8FAFD 100%);
    border:1px solid #E1E8F0;
    border-radius:22px;
    padding:22px 28px 180px;
    box-shadow:0 10px 28px rgba(19,38,63,.045);
    min-height:calc(100vh - 112px);
}
body.light-theme .evaluation-schedule-card{background:#FFFFFF;border-color:rgba(30,82,144,.13);box-shadow:0 5px 18px rgba(30,82,144,.04);}
body.light-theme .schedule-summary-item strong{color:#10243E;}
body.light-theme .dashboard-intro{margin:2px 2px 18px;}
body.light-theme .dashboard-kicker{
    color:#D97706;font-size:11px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;
    display:flex;align-items:center;gap:7px;margin-bottom:5px;
}
body.light-theme .dashboard-intro h1{
    margin:0;font-family:'Rajdhani',sans-serif;font-size:28px;line-height:1.05;font-weight:800;
    letter-spacing:.2px;color:#10243E;
}
body.light-theme .dashboard-intro p{margin-top:7px;font-size:13px;color:#5C7187;}
body.light-theme .dashboard-intro .status-pill{
    display:inline-flex;align-items:center;gap:7px;margin-top:14px;padding:6px 12px;border-radius:20px;
    background:#E8FFF1;border:1px solid #91E7B2;color:#168447;font-size:11.5px;font-weight:700;
}

body.light-theme .dashboard-intro .status-pill.closed{background:#FFF1F1;border-color:#F3B0B0;color:#B42318;}

/* Welcome card */
body.light-theme .welcome-bar{
    background:linear-gradient(135deg,#FFFFFF 0%,#F5F9FF 100%) !important;
    border:1px solid rgba(37,99,235,.20) !important;
    border-radius:17px !important;padding:24px 28px !important;margin-bottom:20px !important;
    box-shadow:0 5px 18px rgba(37,99,235,.05);
}
body.light-theme .welcome-text h2{color:#11263F !important;font-size:23px !important;}
body.light-theme .welcome-text p{color:#5E7388 !important;}
body.light-theme .welcome-text p strong{color:#2563EB !important;}
body.light-theme .score-chip{
    background:#F0F5FF !important;border:1px solid #CADBFF !important;border-radius:15px !important;
    max-width:340px;min-width:230px;padding:16px 26px !important;
}
body.light-theme .score-chip .sc-val{color:#2563EB !important;font-size:39px !important;}
body.light-theme .score-chip .sc-lbl{color:#66798D !important;}

/* Statistic cards */
body.light-theme .stats-grid{gap:14px !important;margin-bottom:20px !important;}
body.light-theme .stat-card{
    position:relative;background:#FFFFFF !important;border:1px solid #E0E7EF !important;
    border-radius:15px !important;padding:18px 20px 20px !important;box-shadow:0 6px 18px rgba(20,42,67,.045) !important;
    overflow:hidden;
}
body.light-theme .stat-card::before{content:'';position:absolute;left:0;right:0;top:0;height:3px;background:#2563EB;}
body.light-theme .stat-card:nth-child(2)::before{background:#10B981;}
body.light-theme .stat-card:nth-child(3)::before{background:#F59E0B;}
body.light-theme .stat-card:nth-child(4)::before{background:#3B82F6;}
body.light-theme .stat-card-icon{
    width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;
    margin-bottom:12px;font-size:15px;
}
body.light-theme .stat-card-icon.blue{background:#E6EEFF;color:#2563EB;}
body.light-theme .stat-card-icon.green{background:#E5F9ED;color:#10B981;}
body.light-theme .stat-card-icon.gold{background:#FFF4D8;color:#D88900;}
body.light-theme .stat-card-icon.sky{background:#E5F0FF;color:#3478E5;}
body.light-theme .stat-card-lbl{color:#5E7388 !important;font-size:10.5px !important;letter-spacing:.9px !important;}
body.light-theme .stat-card-val{color:#12263F !important;font-size:28px !important;}
body.light-theme .stat-card-val.teal{color:#2563EB !important;}
body.light-theme .stat-card-val.gold{color:#D88900 !important;}

/* Section cards and expandable evaluation row */
body.light-theme .section-card{
    background:#FFFFFF !important;border:1px solid #E1E8F0 !important;border-radius:16px !important;
    padding:20px 22px !important;margin-bottom:18px !important;box-shadow:0 5px 18px rgba(20,42,67,.04) !important;
}
body.light-theme .section-title{color:#13263F !important;font-size:17px !important;}
body.light-theme .section-title i{color:#2563EB !important;}
body.light-theme .btn-view-all-evals{
    background:#F0F5FF !important;border-color:#CFDDFF !important;color:#2563EB !important;
    border-radius:10px !important;padding:12px 15px !important;
}
body.light-theme .btn-view-all-evals:hover{background:#E7EEFF !important;}
body.light-theme .eval-item{background:#F8FAFD !important;border:1px solid #E1E8F0 !important;}
body.light-theme .eval-item-left .anon{color:#20364F !important;}
body.light-theme .eval-item-left .meta{color:#6E8093 !important;}
body.light-theme .btn-view-details{background:#EFF4FF !important;border-color:#CEDBFF !important;color:#2563EB !important;}
body.light-theme .btn-view-details:hover{background:#E4ECFF !important;}
body.light-theme .empty-state{color:#66798D !important;}

/* Profile / role page visual language */
body.light-theme .profile-hero,
body.light-theme .role-card,
body.light-theme .students-grid > *,
body.light-theme .peer-card,
body.light-theme .ea-table-card,
body.light-theme .section-card{
    box-shadow:0 5px 18px rgba(20,42,67,.035);
}
body.light-theme .profile-hero{background:linear-gradient(135deg,#FFFFFF 0%,#F1F7FF 100%) !important;border-color:#CFE0FF !important;}
body.light-theme .profile-hero-name{color:#11263F !important;}
body.light-theme .profile-hero-desig{background:#EEF4FF !important;border-color:#CBDCFF !important;color:#2563EB !important;}
body.light-theme .role-card{background:#FFFFFF !important;border-color:#CFDAFF !important;}
body.light-theme .role-card-title{color:#13263F !important;}
body.light-theme .role-card::before{background:linear-gradient(90deg,#2563EB,#60A5FA) !important;}
body.light-theme .role-chip{background:#F7F9FC !important;border-color:#DDE5EE !important;color:#51677F !important;}
body.light-theme .role-chip:hover,.role-chip.is-current{background:#EEF4FF !important;border-color:#AFC8FF !important;color:#2563EB !important;}
body.light-theme .level-view-wrap{background:#F8FAFD !important;border-color:#DDE6EF !important;}
body.light-theme .level-view-pill{background:#E9F1FF !important;color:#2563EB !important;border-color:#C5D8FF !important;}

@media(max-width:900px){
    body.light-theme .dashboard-shell{padding:18px 16px 130px;border-radius:16px;}
    body.light-theme .main{padding:18px 14px 28px !important;}
}

/* Settings is now a first-class sidebar destination. */
.settings-page-head{
    display:flex;align-items:flex-start;justify-content:space-between;gap:20px;
    margin:0 0 18px;padding:0 2px;
}
.settings-page-kicker{
    display:flex;align-items:center;gap:7px;color:var(--teal);
    font-size:11px;font-weight:800;letter-spacing:1px;text-transform:uppercase;
    margin-bottom:5px;
}
.settings-page-title{
    margin:0;color:var(--light);font-family:'Rajdhani',sans-serif;
    font-size:28px;font-weight:700;
}
.settings-page-subtitle{margin:3px 0 0;color:var(--muted);font-size:12.5px;line-height:1.5;}
.settings-appearance-current{
    display:inline-flex;align-items:center;gap:7px;padding:8px 11px;border-radius:999px;
    background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.18);
    color:#1D4ED8;font-size:11px;font-weight:700;white-space:nowrap;
}
.appearance-choice-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:2px;}
.appearance-choice{
    border:1px solid var(--border);background:var(--mid);color:var(--light);
    border-radius:10px;padding:10px 14px;font:700 12px 'DM Sans',sans-serif;cursor:pointer;
    display:inline-flex;align-items:center;gap:8px;transition:.18s ease;
}
.appearance-choice:hover{border-color:var(--teal);color:var(--teal-hover);}
.appearance-choice.active{background:rgba(37,99,235,.10);border-color:rgba(37,99,235,.28);color:#1D4ED8;}
body.light-theme .settings-card{background:#FFFFFF;border-color:rgba(15,31,61,.10);box-shadow:0 5px 18px rgba(30,82,144,.06);}
body.light-theme .settings-card-head h3{color:#16263B;}
body.light-theme .settings-card-head p{color:#5B7186;}
body.light-theme .setting-row{border-top-color:rgba(15,31,61,.08);}
body.light-theme .setting-row strong{color:#16263B;}
body.light-theme .setting-row span{color:#5B7186;}
body.light-theme .account-fact{background:#F8FAFC;border-color:rgba(15,31,61,.08);}
body.light-theme .account-fact label{color:#5B7186;}
body.light-theme .account-fact b{color:#16263B;}
body.light-theme .account-name-form input[type=text]{background:#fff;color:#16263B;border-color:rgba(15,31,61,.18);}
body.light-theme .account-name-cancel{color:#5B7186;border-color:rgba(15,31,61,.18);}
body.light-theme .settings-page-title{color:#16263B;}
body.light-theme .settings-page-subtitle{color:#5B7186;}
body.light-theme .appearance-choice{background:#FFFFFF;color:#294765;border-color:#D8E3EF;}

/* Unified Student-style sidebar structure for Faculty; existing role accent retained. */
:root { --sidebar-w:248px; --role-accent:#2563EB; --role-accent-light:#60A5FA; }
.sidebar { width:var(--sidebar-w) !important; background:#0A192F !important; border-right:1px solid #172A45 !important; box-shadow:6px 0 20px rgba(0,0,0,.16); }
.sidebar-brand.portal-brand { min-height:68px; padding:14px 17px !important; border-bottom:1px solid rgba(255,255,255,.08) !important; display:flex; align-items:center; gap:11px; cursor:default; }
.portal-brand-logo { width:38px; height:38px; flex:0 0 38px; border-radius:11px; display:flex; align-items:center; justify-content:center; overflow:hidden; background:#0F1F3D; border:1px solid #2563EB; box-shadow:0 0 14px rgba(37,99,235,.18); }
.portal-brand-logo img { width:100%; height:100%; object-fit:cover; display:block; }
.portal-brand-copy { min-width:0; display:flex; flex-direction:column; line-height:1.2; }
.portal-brand-copy strong { font-family:'Rajdhani',sans-serif; font-size:15px; font-weight:700; color:#F8FAFC; letter-spacing:.25px; }
.portal-brand-copy span { margin-top:3px; font-size:10px; color:#8FA6BF; font-weight:600; letter-spacing:.15px; white-space:nowrap; }
.portal-sidebar-profile { padding:18px 16px 17px; text-align:center; border-bottom:1px solid rgba(255,255,255,.08); }
.portal-profile-avatar-wrap { margin:0 auto 11px; display:flex; justify-content:center; }
.portal-profile-avatar { width:64px; height:64px; border-radius:50%; object-fit:cover; border:2px solid #2563EB; background:#0F1F3D; box-shadow:0 0 15px rgba(37,99,235,.18); display:flex; align-items:center; justify-content:center; }
.portal-profile-fallback { color:#60A5FA; font-family:'Rajdhani',sans-serif; font-size:17px; font-weight:700; }
.portal-profile-name { color:#F8FAFC; font-size:13px; line-height:1.35; font-weight:700; word-break:break-word; }
.portal-profile-role { margin-top:3px; color:#8FA6BF; font-size:10px; text-transform:uppercase; letter-spacing:.7px; font-weight:700; line-height:1.35; }
.portal-sidebar-nav { flex:1; padding:16px 10px !important; overflow-y:auto; }
.portal-sidebar-nav .nav-section-label { padding:0 8px !important; margin:0 0 7px !important; font-size:9.5px !important; font-weight:800 !important; letter-spacing:1.25px !important; text-align:left !important; color:#8FA6BF !important; text-shadow:none !important; }
.portal-sidebar-nav .nav-section-label.sidebar-section-secondary { margin-top:17px !important; }
.portal-sidebar-nav .nav-link { min-height:40px; margin:2px 2px !important; padding:9px 11px !important; border-left:0 !important; border-radius:8px !important; gap:10px; color:#CBD8E8 !important; font-size:13px !important; font-weight:500 !important; line-height:1.2; }
.portal-sidebar-nav .nav-link i { width:18px; font-size:14px; color:#8FA6BF !important; text-align:center; flex:0 0 18px; }
.portal-sidebar-nav .nav-link:hover { background:rgba(255,255,255,.07) !important; color:#FFFFFF !important; }
.portal-sidebar-nav .nav-link:hover i { color:#60A5FA !important; }
.portal-sidebar-nav .nav-link.active { background:linear-gradient(90deg,rgba(37,99,235,.18),rgba(255,255,255,.025)) !important; color:#FFFFFF !important; font-weight:700 !important; box-shadow:inset 3px 0 0 #60A5FA; }
.portal-sidebar-nav .nav-link.active i { color:#60A5FA !important; }
.portal-sidebar-nav .side-nav-badge, .portal-sidebar-nav .nav-badge { margin-left:auto; background:rgba(37,99,235,.22) !important; color:#BFDBFE !important; border-radius:20px; padding:2px 7px; font-size:9px; font-weight:800; }
.sidebar-footer { padding:13px 14px 15px !important; border-top:1px solid rgba(255,255,255,.08) !important; }
.btn-logout-side { padding:9px 11px !important; border-radius:8px !important; }
@media(max-width:900px) { .sidebar { width:248px !important; transform:translateX(-100%); transition:transform .22s ease; } .sidebar.open { transform:translateX(0); box-shadow:12px 0 34px rgba(0,0,0,.45); } .top-nav { left:0 !important; } .main { margin-left:0 !important; } }
@media(max-width:520px) { .portal-sidebar-nav { padding-left:9px !important; padding-right:9px !important; } }


/* Compact dashboard layout: keep summary information visible without forced blank space. */
body.light-theme .main {
    padding: 14px 24px 16px !important;
}
body.light-theme .dashboard-shell {
    padding: 16px 24px 18px !important;
    min-height: 0 !important;
}
body.light-theme .dashboard-intro {
    margin: 0 2px 12px !important;
}
body.light-theme .dashboard-intro h1 {
    font-size: 26px !important;
}
body.light-theme .dashboard-intro p {
    margin-top: 5px !important;
    line-height: 1.35;
}
body.light-theme .dashboard-intro .status-pill {
    margin-top: 9px !important;
    padding: 5px 10px !important;
}
body.light-theme .evaluation-schedule-card {
    padding: 14px 22px !important;
    margin-bottom: 14px !important;
}
body.light-theme .schedule-summary-grid {
    gap: 16px;
}
body.light-theme .schedule-label {
    margin-bottom: 5px;
}
body.light-theme .welcome-bar {
    padding: 15px 22px !important;
    margin-bottom: 14px !important;
    gap: 12px;
}
body.light-theme .score-chip {
    padding: 10px 20px !important;
}
body.light-theme .score-chip .sc-val,
body.light-theme .score-chip .big {
    font-size: 34px !important;
}
body.light-theme .score-chip .sc-lbl,
body.light-theme .score-chip .lbl {
    margin-top: 5px !important;
}
body.light-theme .stats-grid {
    gap: 12px !important;
    margin-bottom: 0 !important;
}
body.light-theme .stat-card,
body.light-theme .stat-box {
    padding: 13px 16px 15px !important;
}
body.light-theme .stat-card-icon,
body.light-theme .stat-box-icon {
    width: 30px !important;
    height: 30px !important;
    margin-bottom: 8px !important;
}
body.light-theme .stat-card-lbl,
body.light-theme .stat-box-lbl {
    margin-bottom: 8px !important;
}
body.light-theme .stat-card-val,
body.light-theme .stat-box-val {
    font-size: 26px !important;
}

@media (max-width: 900px) {
    body.light-theme .main {
        padding: 14px 14px 18px !important;
    }
    body.light-theme .dashboard-shell {
        padding: 16px 14px 18px !important;
        border-radius: 16px;
    }
    body.light-theme .evaluation-schedule-card {
        padding: 13px 16px !important;
    }
    body.light-theme .welcome-bar {
        padding: 14px 16px !important;
    }
}

/* Expanded dashboard overview: use the available viewport while adding helpful shortcuts. */
body.light-theme .dashboard-shell {
    min-height: calc(100vh - 110px) !important;
    display: flex;
    flex-direction: column;
}
body.light-theme .dashboard-quick-access {
    margin-top: auto;
    padding-top: 22px;
}
body.light-theme .quick-access-heading {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 16px;
    margin: 0 2px 12px;
}
body.light-theme .quick-access-kicker {
    display: block;
    margin-bottom: 3px;
    color: #2563EB;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1.15px;
}
body.light-theme .quick-access-heading h2 {
    color: #10243E;
    font-family: 'Rajdhani', sans-serif;
    font-size: 19px;
    line-height: 1.15;
    font-weight: 800;
}
body.light-theme .quick-access-caption {
    color: #6B7D91;
    font-size: 11px;
    padding-bottom: 2px;
}
body.light-theme .quick-access-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}
body.light-theme .quick-access-card {
    min-width: 0;
    min-height: 92px;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 15px 16px;
    text-decoration: none;
    border: 1px solid #DEE7F2;
    border-radius: 14px;
    background: #FFFFFF;
    box-shadow: 0 4px 14px rgba(19, 38, 63, .035);
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}
body.light-theme .quick-access-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(19, 38, 63, .08);
    border-color: #BFD2F8;
}
body.light-theme .quick-access-icon {
    width: 42px;
    height: 42px;
    flex: 0 0 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    font-size: 17px;
}
body.light-theme .quick-blue .quick-access-icon { background: #EAF1FF; color: #2563EB; }
body.light-theme .quick-green .quick-access-icon { background: #E7F9EF; color: #0E9F6E; }
body.light-theme .quick-amber .quick-access-icon { background: #FFF4DB; color: #D97706; }
body.light-theme .quick-access-copy { min-width: 0; flex: 1; display: flex; flex-direction: column; gap: 4px; }
body.light-theme .quick-access-copy strong { color: #10243E; font-size: 12.5px; line-height: 1.25; font-weight: 800; }
body.light-theme .quick-access-copy small { color: #64788E; font-size: 11px; line-height: 1.4; }
body.light-theme .quick-access-arrow { color: #8AA0B8; font-size: 12px; flex: 0 0 auto; transition: transform .18s ease, color .18s ease; }
body.light-theme .quick-access-card:hover .quick-access-arrow { color: #2563EB; transform: translateX(3px); }
@media (max-width: 1100px) {
    body.light-theme .dashboard-shell { min-height: calc(100vh - 100px) !important; }
    body.light-theme .quick-access-grid { grid-template-columns: 1fr; }
    body.light-theme .quick-access-card { min-height: 76px; }
}
@media (max-width: 900px) {
    body.light-theme .dashboard-shell { min-height: 0 !important; }
    body.light-theme .dashboard-quick-access { margin-top: 22px; padding-top: 0; }
    body.light-theme .quick-access-heading { align-items: flex-start; flex-direction: column; gap: 4px; }
}


/* ===== Role & Designation — same visual language as the Dashboard ===== */
.rd-id{display:flex;align-items:center;gap:18px;flex-wrap:wrap;min-width:0;}
.rd-avatar-wrap{position:relative;flex-shrink:0;}
.rd-avatar{width:76px;height:76px;border-radius:50%;object-fit:cover;display:flex;align-items:center;justify-content:center;border:3px solid var(--teal);background:var(--inner);color:var(--teal-hover);font-size:28px;}
.rd-cam{position:absolute;bottom:-2px;right:-2px;width:28px;height:28px;border-radius:50%;background:var(--teal);border:2px solid var(--mid);color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:12px;padding:0;}
.rd-photo-link{margin-top:6px;background:none;border:none;color:var(--teal-hover);font-size:12px;font-weight:700;cursor:pointer;padding:0;font-family:inherit;}
.rd-photo-link:hover{text-decoration:underline;}

.rd-levels{flex:0 1 340px;min-width:230px;max-width:340px;align-self:stretch;background:rgba(13,148,136,.13);border:1px solid rgba(13,148,136,.25);border-radius:12px;padding:14px 20px;display:flex;flex-direction:column;justify-content:center;gap:9px;transition:box-shadow .3s ease,border-color .3s ease;}
.rd-levels.flash{border-color:var(--teal);box-shadow:0 0 0 3px rgba(13,148,136,.25);}
.rd-levels-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted);display:flex;align-items:center;gap:7px;}
.rd-levels-pills{display:flex;flex-wrap:wrap;gap:6px;}
.rd-level-pill{background:rgba(13,148,136,.15);color:#5eead4;border:1px solid rgba(13,148,136,.35);font-size:12px;font-weight:700;padding:4px 12px;border-radius:20px;}
.rd-levels-empty{font-size:11.5px;color:#fcd34d;display:flex;align-items:center;gap:6px;line-height:1.5;}
.rd-levels-hint{font-size:11px;color:var(--muted);}

.rd-card{position:relative;overflow:hidden;background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);padding:22px 24px 24px;}
.rd-card::before{content:'';position:absolute;left:0;right:0;top:0;height:3px;background:linear-gradient(90deg,var(--teal),var(--teal-hover));}
.rd-card-head{display:flex;align-items:flex-start;gap:14px;margin-bottom:20px;}
.rd-card-icon{width:42px;height:42px;flex:0 0 42px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;font-size:17px;background:rgba(13,148,136,.14);color:var(--teal-hover);}
.rd-card-head h2{margin:0 0 4px;font-family:'Rajdhani',sans-serif;font-size:21px;font-weight:800;color:#fff;line-height:1.15;}
.rd-card-head p{margin:0;font-size:13px;color:var(--muted);line-height:1.5;}
.rd-label{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1.1px;color:var(--muted);display:flex;align-items:center;gap:7px;margin-bottom:10px;}
.rd-label i{color:var(--teal-hover);}
.rd-chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:22px;}
.rd-chip{padding:9px 16px;border-radius:10px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;border:1px solid var(--border);background:var(--inner);color:var(--muted);transition:all .18s ease;display:inline-flex;align-items:center;gap:6px;}
.rd-chip:hover{border-color:var(--teal);color:var(--teal-hover);background:rgba(13,148,136,.1);transform:translateY(-1px);}
.rd-chip.is-current{border-color:var(--teal);background:rgba(13,148,136,.18);color:var(--teal-hover);}
.rd-chip.is-current::after{content:'✓';font-size:12px;}
.rd-input-row{display:flex;gap:10px;flex-wrap:wrap;}
.rd-input{flex:1;min-width:220px;background:var(--inner);border:1px solid var(--border);color:var(--light);padding:13px 16px;border-radius:12px;font-size:14px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s;}
.rd-input:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(13,148,136,.2);}
.rd-input::placeholder{color:var(--muted);}
.rd-save{background:var(--teal);color:#fff;border:none;padding:13px 26px;border-radius:12px;font-size:14px;font-weight:700;font-family:'DM Sans',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:8px;white-space:nowrap;transition:all .18s ease;}
.rd-save:hover{background:var(--teal-hover);transform:translateY(-1px);}
.rd-note{margin-top:16px;display:flex;gap:10px;align-items:flex-start;padding:12px 16px;border-radius:12px;font-size:13px;color:var(--muted);background:rgba(43,108,176,.07);border:1px solid rgba(43,108,176,.18);}
.rd-note i{color:#60a5fa;margin-top:2px;flex-shrink:0;}

/* Light theme: identical palette to the Dashboard cards */
body.light-theme .rd-shell{min-height:0 !important;}
body.light-theme .rd-avatar{border-color:#2563EB;color:#2563EB;background:#EEF4FF;box-shadow:0 4px 14px rgba(37,99,235,.18);}
body.light-theme .rd-cam{background:#2563EB;border-color:#fff;}
body.light-theme .rd-photo-link{color:#2563EB;}
body.light-theme .rd-levels{background:#F0F5FF;border:1px solid #CADBFF;border-radius:15px;}
body.light-theme .rd-levels.flash{border-color:#2563EB;box-shadow:0 0 0 3px rgba(37,99,235,.14);}
body.light-theme .rd-levels-label{color:#66798D;}
body.light-theme .rd-level-pill{background:#E9F1FF;color:#2563EB;border-color:#C5D8FF;}
body.light-theme .rd-levels-empty{color:#B45309;}
body.light-theme .rd-levels-hint{color:#66798D;}
body.light-theme .rd-card{background:#FFFFFF;border:1px solid #E0E7EF;border-radius:15px;box-shadow:0 6px 18px rgba(20,42,67,.045);}
body.light-theme .rd-card::before{background:#2563EB;}
body.light-theme .rd-card-icon{background:#E6EEFF;color:#2563EB;}
body.light-theme .rd-card-head h2{color:#10243E;}
body.light-theme .rd-card-head p{color:#5E7388;}
body.light-theme .rd-label{color:#5E7388;}
body.light-theme .rd-label i{color:#2563EB;}
body.light-theme .rd-chip{background:#F7F9FC;border-color:#DDE5EE;color:#51677F;}
body.light-theme .rd-chip:hover{background:#EEF4FF;border-color:#AFC8FF;color:#2563EB;}
body.light-theme .rd-chip.is-current{background:#EEF4FF;border-color:#AFC8FF;color:#2563EB;}
body.light-theme .rd-input{background:#F8FAFD;border-color:#DDE6EF;color:#13263F;}
body.light-theme .rd-input:focus{border-color:#2563EB;box-shadow:0 0 0 3px rgba(37,99,235,.14);}
body.light-theme .rd-save{background:#2563EB;box-shadow:0 6px 14px rgba(37,99,235,.22);}
body.light-theme .rd-save:hover{background:#1D4ED8;}
body.light-theme .rd-note{background:#F0F5FF;border-color:#CADBFF;color:#5E7388;}
body.light-theme .rd-note i{color:#2563EB;}
@media(max-width:700px){.rd-levels{flex:1 1 100%;max-width:none;}.rd-save{width:100%;justify-content:center;}.rd-card{padding:18px 18px 20px;}}
/* ===== Settings — same visual language as the Dashboard ===== */
.settings-intro{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap}
.settings-intro .dashboard-kicker{display:flex;align-items:center;gap:7px;color:#D97706;font-size:11px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;margin-bottom:5px;}
.settings-intro h1{margin:0;font-family:'Rajdhani',sans-serif;font-size:28px;line-height:1.05;font-weight:800;letter-spacing:.2px;color:var(--light);}
.settings-intro p{margin-top:7px;font-size:13px;line-height:1.4;color:var(--muted);}
.settings-shell .settings-grid{margin-top:0;gap:14px;}
.settings-shell .settings-card{--sc-bar:var(--teal);--sc-ico:var(--teal);--sc-ico-bg:color-mix(in srgb,var(--teal) 14%,transparent);position:relative;overflow:hidden;background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);padding:22px 24px 24px;}
.settings-shell .settings-card::before{content:'';position:absolute;left:0;right:0;top:0;height:3px;background:var(--sc-bar);}
.settings-shell .settings-card:nth-child(2),.settings-shell .settings-card:nth-child(5){--sc-bar:#10B981;--sc-ico:#10B981;--sc-ico-bg:#E5F9ED;}
.settings-shell .settings-card:nth-child(3){--sc-bar:#F59E0B;--sc-ico:#D88900;--sc-ico-bg:#FFF4D8;}
.settings-shell .settings-card:nth-child(4){--sc-bar:#3B82F6;--sc-ico:#3478E5;--sc-ico-bg:#E5F0FF;}
.settings-shell .settings-card-head{align-items:flex-start;gap:14px;margin-bottom:18px;}
.settings-shell .settings-card-head .sicon{width:42px;height:42px;flex:0 0 42px;border-radius:12px;font-size:17px;background:var(--sc-ico-bg);color:var(--sc-ico);}
.settings-shell .settings-card-head h3{margin:0 0 4px;font-family:'Rajdhani',sans-serif;font-size:21px;font-weight:800;line-height:1.15;}
.settings-shell .settings-card-head p{margin:0;font-size:13px;line-height:1.5;}
.settings-shell .setting-row strong{font-size:13.5px;}
.settings-shell .setting-row span{font-size:12px;}
body.light-theme .settings-shell{min-height:0 !important;display:block !important;}
body.light-theme .settings-intro h1{color:#10243E;}
body.light-theme .settings-intro p{color:#5C7187;}
body.light-theme .settings-shell .settings-card{background:#FFFFFF;border:1px solid #E0E7EF;border-radius:15px;box-shadow:0 6px 18px rgba(20,42,67,.045);}
body.light-theme .settings-shell .settings-card-head h3{color:#10243E;}
body.light-theme .settings-shell .settings-card-head p{color:#5E7388;}
body.light-theme .settings-shell .setting-row strong{color:#10243E;}
body.light-theme .settings-shell .setting-row span{color:#5E7388;}
@media(max-width:600px){.settings-intro h1{font-size:24px;}.settings-shell .settings-card{padding:18px 18px 20px;}}
/* Top-bar page title — dark title, icon in the portal accent colour */
.top-nav .nav-page-title,body.light-theme .top-nav .nav-page-title{display:flex;align-items:center;gap:9px;font-family:'Rajdhani',sans-serif !important;font-size:19px !important;font-weight:700 !important;letter-spacing:.4px !important;text-transform:none !important;background:transparent !important;text-shadow:none !important;-webkit-font-smoothing:antialiased;}
.top-nav .nav-page-title i{font-size:16px;color:var(--teal);}
.top-nav .nav-page-title span{background:transparent !important;}
@media(max-width:600px){.top-nav .nav-page-title{font-size:17px !important;}}
/* No in-page title blocks: pills sit flush, right-aligned where they were on the right */
.settings-intro{justify-content:flex-end;}
.dashboard-intro .status-pill{margin-top:0 !important;}
body.light-theme .dashboard-intro .status-pill{margin-top:0 !important;}

/* ================================================================
   DARK MODE — FACULTY DASHBOARD
   Mirrors the light-theme dashboard (shell card, status pill, compact
   schedule / welcome / stat cards, quick-access shortcuts) using
   dark-surface colours and the faculty blue accent. Scoped to
   body:not(.light-theme) so light mode is untouched.
   ================================================================ */
body:not(.light-theme) .main{padding:14px 24px 16px !important;}
body:not(.light-theme) .dashboard-shell{
    background:linear-gradient(180deg,#112240 0%,#0D1D36 100%);
    border:1px solid rgba(255,255,255,.08);border-radius:22px;
    padding:16px 24px 18px !important;box-shadow:0 10px 28px rgba(0,0,0,.30);
    min-height:calc(100vh - 110px) !important;display:flex;flex-direction:column;
}
body:not(.light-theme) .rd-shell,
body:not(.light-theme) .settings-shell{min-height:0 !important;}
body:not(.light-theme) .settings-shell{display:block !important;}

/* Intro + status pill */
body:not(.light-theme) .dashboard-intro{margin:0 2px 12px !important;}
body:not(.light-theme) .dashboard-intro .status-pill{
    display:inline-flex;align-items:center;gap:7px;padding:5px 10px !important;border-radius:20px;
    background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);color:#4ADE80;font-size:11.5px;font-weight:700;
}
body:not(.light-theme) .dashboard-intro .status-pill.closed{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.35);color:#F87171;}

/* Schedule card (was hard-coded white) */
body:not(.light-theme) .evaluation-schedule-card{
    background:var(--mid);border:1px solid rgba(255,255,255,.08);
    padding:14px 22px !important;margin-bottom:14px !important;box-shadow:0 5px 18px rgba(0,0,0,.20);
}
body:not(.light-theme) .schedule-summary-grid{gap:16px;}
body:not(.light-theme) .schedule-label{margin-bottom:5px;}
body:not(.light-theme) .schedule-summary-item strong,
body:not(.light-theme) .schedule-message strong{color:#F1F5F9;}
body:not(.light-theme) .schedule-message{color:#A0B3C6;}
body:not(.light-theme) .schedule-times{border-top-color:rgba(255,255,255,.08);}
body:not(.light-theme) .schedule-status{border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.12);color:#F87171;}
body:not(.light-theme) .schedule-status.open{border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.12);color:#4ADE80;}
body:not(.light-theme) .schedule-status.closed{border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.12);color:#F87171;}

/* Welcome bar + score chip */
body:not(.light-theme) .welcome-bar{
    background:linear-gradient(135deg,#172A45 0%,rgba(37,99,235,.14) 100%) !important;
    border:1px solid rgba(96,165,250,.25) !important;border-radius:17px !important;
    padding:15px 22px !important;margin-bottom:14px !important;gap:12px;box-shadow:0 5px 18px rgba(0,0,0,.18);
}
body:not(.light-theme) .welcome-text h2{color:#F8FAFC !important;font-size:23px !important;}
body:not(.light-theme) .welcome-text p{color:#A0B3C6 !important;}
body:not(.light-theme) .welcome-text p strong{color:#60A5FA !important;}
body:not(.light-theme) .score-chip{
    background:rgba(37,99,235,.14) !important;border:1px solid rgba(96,165,250,.30) !important;border-radius:15px !important;
    max-width:340px;min-width:230px;padding:10px 20px !important;
}
body:not(.light-theme) .score-chip .sc-val{color:#60A5FA !important;font-size:34px !important;}
body:not(.light-theme) .score-chip .sc-lbl{color:#A0B3C6 !important;margin-top:5px !important;}

/* Stat cards */
body:not(.light-theme) .stats-grid{gap:12px !important;margin-bottom:0 !important;}
body:not(.light-theme) .stat-card{
    position:relative;background:var(--mid) !important;border:1px solid rgba(255,255,255,.08) !important;
    border-radius:15px !important;padding:13px 16px 15px !important;overflow:hidden;box-shadow:0 6px 18px rgba(0,0,0,.20) !important;
}
body:not(.light-theme) .stat-card::before{content:'';position:absolute;left:0;right:0;top:0;height:3px;background:#2563EB;}
body:not(.light-theme) .stat-card:nth-child(2)::before{background:#10B981;}
body:not(.light-theme) .stat-card:nth-child(3)::before{background:#F59E0B;}
body:not(.light-theme) .stat-card:nth-child(4)::before{background:#3B82F6;}
body:not(.light-theme) .stat-card-icon{width:30px;height:30px;border-radius:10px;display:flex;align-items:center;justify-content:center;margin-bottom:8px;font-size:15px;}
body:not(.light-theme) .stat-card-icon.blue{background:rgba(37,99,235,.20);color:#60A5FA;}
body:not(.light-theme) .stat-card-icon.green{background:rgba(16,185,129,.16);color:#34D399;}
body:not(.light-theme) .stat-card-icon.gold{background:rgba(245,158,11,.16);color:#FBBF24;}
body:not(.light-theme) .stat-card-icon.sky{background:rgba(59,130,246,.16);color:#60A5FA;}
body:not(.light-theme) .stat-card-lbl{color:#A0B3C6 !important;font-size:10.5px !important;letter-spacing:.9px !important;margin-bottom:8px !important;}
body:not(.light-theme) .stat-card-val{color:#F1F5F9 !important;font-size:26px !important;}
body:not(.light-theme) .stat-card-val.teal{color:#60A5FA !important;}
body:not(.light-theme) .stat-card-val.gold{color:#FBBF24 !important;}
body:not(.light-theme) .stat-card-link:hover{border-color:rgba(96,165,250,.50) !important;box-shadow:0 8px 18px rgba(0,0,0,.30) !important;}

/* Quick access shortcuts */
body:not(.light-theme) .dashboard-quick-access{margin-top:auto;padding-top:22px;}
body:not(.light-theme) .quick-access-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin:0 2px 12px;}
body:not(.light-theme) .quick-access-kicker{display:block;margin-bottom:3px;color:#60A5FA;font-size:10px;font-weight:800;letter-spacing:1.15px;}
body:not(.light-theme) .quick-access-heading h2{color:#F8FAFC;font-family:'Rajdhani',sans-serif;font-size:19px;line-height:1.15;font-weight:800;}
body:not(.light-theme) .quick-access-caption{color:#8FA6BF;font-size:11px;padding-bottom:2px;}
body:not(.light-theme) .quick-access-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;}
body:not(.light-theme) .quick-access-card{
    min-width:0;min-height:92px;display:flex;align-items:center;gap:12px;padding:15px 16px;text-decoration:none;
    background:var(--mid);border:1px solid rgba(255,255,255,.08);border-radius:14px;box-shadow:0 4px 14px rgba(0,0,0,.20);
    transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease;
}
body:not(.light-theme) .quick-access-card:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,0,0,.32);border-color:rgba(96,165,250,.45);}
body:not(.light-theme) .quick-access-icon{width:42px;height:42px;flex:0 0 42px;display:inline-flex;align-items:center;justify-content:center;border-radius:12px;font-size:17px;}
body:not(.light-theme) .quick-blue .quick-access-icon{background:rgba(37,99,235,.20);color:#60A5FA;}
body:not(.light-theme) .quick-green .quick-access-icon{background:rgba(16,185,129,.16);color:#34D399;}
body:not(.light-theme) .quick-amber .quick-access-icon{background:rgba(245,158,11,.16);color:#FBBF24;}
body:not(.light-theme) .quick-access-copy{min-width:0;flex:1;display:flex;flex-direction:column;gap:4px;}
body:not(.light-theme) .quick-access-copy strong{color:#F1F5F9;font-size:12.5px;line-height:1.25;font-weight:800;}
body:not(.light-theme) .quick-access-copy small{color:#A0B3C6;font-size:11px;line-height:1.4;}
body:not(.light-theme) .quick-access-arrow{color:#8FA6BF;font-size:12px;flex:0 0 auto;transition:transform .18s ease,color .18s ease;}
body:not(.light-theme) .quick-access-card:hover .quick-access-arrow{color:#60A5FA;transform:translateX(3px);}

/* Header period badge — readable on dark */
body:not(.light-theme) .period-badge{background:rgba(37,99,235,.16);border-color:rgba(96,165,250,.30);color:#93C5FD;}

@media(max-width:1100px){
    body:not(.light-theme) .dashboard-shell{min-height:calc(100vh - 100px) !important;}
    body:not(.light-theme) .rd-shell,body:not(.light-theme) .settings-shell{min-height:0 !important;}
    body:not(.light-theme) .quick-access-grid{grid-template-columns:1fr;}
    body:not(.light-theme) .quick-access-card{min-height:76px;}
}
@media(max-width:900px){
    body:not(.light-theme) .main{padding:14px 14px 18px !important;}
    body:not(.light-theme) .dashboard-shell{padding:16px 14px 18px !important;border-radius:16px;min-height:0 !important;}
    body:not(.light-theme) .evaluation-schedule-card{padding:13px 16px !important;}
    body:not(.light-theme) .welcome-bar{padding:14px 16px !important;}
    body:not(.light-theme) .dashboard-quick-access{margin-top:22px;padding-top:0;}
    body:not(.light-theme) .quick-access-heading{align-items:flex-start;flex-direction:column;gap:4px;}
}

/* Role & Designation + Settings share the shell — keep accents in the blue theme */
body:not(.light-theme) .rd-avatar{color:#60A5FA;}
body:not(.light-theme) .rd-photo-link,
body:not(.light-theme) .rd-label i{color:#60A5FA;}
body:not(.light-theme) .rd-levels{background:rgba(37,99,235,.14);border-color:rgba(96,165,250,.30);border-radius:15px;}
body:not(.light-theme) .rd-level-pill{background:rgba(37,99,235,.20);color:#93C5FD;border-color:rgba(96,165,250,.35);}
body:not(.light-theme) .rd-card{border-radius:15px;box-shadow:0 6px 18px rgba(0,0,0,.20);}
body:not(.light-theme) .rd-card::before{background:#2563EB;}
body:not(.light-theme) .rd-card-icon{background:rgba(37,99,235,.20);color:#60A5FA;}
body:not(.light-theme) .rd-chip:hover,
body:not(.light-theme) .rd-chip.is-current{background:rgba(37,99,235,.18);border-color:rgba(96,165,250,.45);color:#93C5FD;}
body:not(.light-theme) .rd-note{background:rgba(37,99,235,.10);border-color:rgba(96,165,250,.25);}
body:not(.light-theme) .settings-shell .settings-card{--sc-ico:#60A5FA;--sc-ico-bg:rgba(37,99,235,.20);}
body:not(.light-theme) .settings-shell .settings-card:nth-child(2),
body:not(.light-theme) .settings-shell .settings-card:nth-child(5){--sc-ico:#34D399;--sc-ico-bg:rgba(16,185,129,.16);}
body:not(.light-theme) .settings-shell .settings-card:nth-child(3){--sc-ico:#FBBF24;--sc-ico-bg:rgba(245,158,11,.16);}
body:not(.light-theme) .settings-shell .settings-card:nth-child(4){--sc-ico:#60A5FA;--sc-ico-bg:rgba(59,130,246,.16);}

/* ================================================================
   CATEGORY PICKER — same look as the Executive Assistant's
   "Evaluate Others → Choose category" (large icon cards, no progress
   pills, no accent bottom border). Used by Faculty + Teaching Staff
   Peer-to-Peer Evaluation. Works in light and dark mode.
   ================================================================ */
.cat-heading{display:flex;align-items:center;gap:12px;margin:0 0 22px;font-family:'Rajdhani',sans-serif;font-size:26px;line-height:1.1;font-weight:800;color:var(--light);}
.cat-heading i{font-size:20px;color:var(--teal);}
body .section-card:has(.cat-select){padding:28px 34px 32px !important;}
.eval-group-grid.cat-select{grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:24px;margin-bottom:0;}
body .eval-group-grid.cat-select .eval-group-card.eval-group-card{
    min-height:230px;padding:38px 20px !important;justify-content:center;
    border:1px solid #E2E9F2 !important;border-bottom:1px solid #E2E9F2 !important;border-radius:16px;
    background:#FFFFFF !important;box-shadow:none;
    transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease;
}
body .eval-group-grid.cat-select .eval-group-card.eval-group-card:hover{transform:translateY(-2px);border-color:#C9D6E6 !important;box-shadow:0 8px 20px rgba(20,42,67,.07);}
body .eval-group-grid.cat-select .eval-group-card.eval-group-card.is-selected{border-color:var(--teal) !important;border-bottom-color:var(--teal) !important;box-shadow:0 0 0 1px var(--teal);}
.eval-group-grid.cat-select .eval-group-icon{width:80px;height:80px;font-size:28px;margin-bottom:22px;}
body .eval-group-grid.cat-select .eval-group-title.eval-group-title{font-family:'DM Sans',sans-serif;font-size:24px;font-weight:700;color:#10243E !important;margin-bottom:6px;}
body .eval-group-grid.cat-select .eval-group-count.eval-group-count{font-size:15px;color:#64788E !important;}

/* dark mode */
body:not(.light-theme) .eval-group-grid.cat-select .eval-group-card.eval-group-card{background:var(--inner) !important;border-color:rgba(255,255,255,.10) !important;border-bottom-color:rgba(255,255,255,.10) !important;}
body:not(.light-theme) .eval-group-grid.cat-select .eval-group-card.eval-group-card:hover{border-color:rgba(255,255,255,.22) !important;box-shadow:0 8px 20px rgba(0,0,0,.30);}
body:not(.light-theme) .eval-group-grid.cat-select .eval-group-card.eval-group-card.is-selected{border-color:var(--teal) !important;}
body:not(.light-theme) .eval-group-grid.cat-select .eval-group-title.eval-group-title{color:#F1F5F9 !important;}
body:not(.light-theme) .eval-group-grid.cat-select .eval-group-count.eval-group-count{color:#A0B3C6 !important;}

/* once a category is chosen the picker stays as a compact switcher above the list */
@media(max-width:600px){.cat-heading{font-size:22px;}body .section-card:has(.cat-select){padding:20px 18px 24px !important;}}

/* Peer-to-Peer Evaluation → same page frame as the Admin "Evaluate Others" screen */
.cat-heading{font-size:30px;gap:13px;}
.cat-heading i{font-size:22px;color:#2563EB;}
body .section-card:has(.cat-select){min-height:calc(100vh - 130px);padding:34px 36px 40px !important;border-radius:20px;}
body .eval-group-grid.cat-select .eval-group-title.eval-group-title{font-size:25px;}
body .eval-group-grid.cat-select .eval-group-count.eval-group-count{font-size:16px;}
body .eval-group-grid.cat-select .eval-group-card.eval-group-card{min-height:235px;border-radius:18px;}
@media(max-width:900px){body .section-card:has(.cat-select){min-height:0;padding:22px 18px 26px !important;}}

/* Category picker sizing — exact metrics from the admin "Evaluate Others" screen */
body .section-card:has(.cat-select){padding:24px 28px 22px !important;border-radius:14px;}
.cat-heading{font-size:26px;gap:11px;margin:0 0 14px;font-weight:700;letter-spacing:.1px;}
.cat-heading i{font-size:22px;}
.eval-group-grid.cat-select{gap:18px;}
body .eval-group-grid.cat-select .eval-group-card.eval-group-card{min-height:188px;padding:22px 18px !important;border-radius:14px;}
.eval-group-grid.cat-select .eval-group-icon{width:64px;height:64px;font-size:25px;margin-bottom:15px;}
body .eval-group-grid.cat-select .eval-group-title.eval-group-title{font-size:20px;margin-bottom:3px;}
body .eval-group-grid.cat-select .eval-group-count.eval-group-count{font-size:13.5px;}
/* compact switcher shown once a category is chosen */
@media(max-width:900px){body .section-card:has(.cat-select){padding:20px !important;}}
@media(max-width:560px){.cat-heading{font-size:23px;}body .section-card:has(.cat-select){padding:16px !important;}body .eval-group-grid.cat-select .eval-group-card.eval-group-card{min-height:150px;}}
.cat-heading{font-size:21px;gap:9px;margin:0 0 14px;}
.cat-heading i{font-size:16px;color:#2563EB !important;}
@media(max-width:560px){.cat-heading{font-size:19px;}.cat-heading i{font-size:15px;}}

/* ================================================================
   PEER-TO-PEER EVALUATION — step 2 roster, same as the Admin
   "Evaluate Others" screen: picker panel stays full-size on top, the
   selected category's people appear below in a roster panel with
   2-column person cards, status pills and Evaluate buttons.
   ================================================================ */
.peer-inline-categories{background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:24px 28px 22px;margin-bottom:20px;box-shadow:0 6px 18px rgba(20,42,67,.045);}
body:not(.light-theme) .peer-inline-categories{box-shadow:0 6px 18px rgba(0,0,0,.20);}
@media(max-width:900px){.peer-inline-categories{padding:20px;}}
@media(max-width:560px){.peer-inline-categories{padding:16px;}}

.roster-panel{
    --rp-panel:#FFFFFF;--rp-line:#DCE8F5;--rp-soft:#F8FBFF;--rp-card:#FFFFFF;--rp-card-border:#D7E3EF;
    --rp-text:#10243F;--rp-muted:#587089;--rp-muted2:#7E93AB;--rp-icon:#EDF4FD;--rp-icon-fg:#2F6EE2;
    --rp-photo:#F2F6FB;--rp-photo-border:#C9D9EA;--rp-hover:#F4F8FF;--rp-view-border:#9FB4CA;
    margin-top:0;background:var(--rp-panel);border:1px solid var(--rp-line);border-radius:14px;overflow:hidden;
    box-shadow:0 6px 18px rgba(20,42,67,.045);
}
body:not(.light-theme) .roster-panel{
    --rp-panel:var(--mid);--rp-line:rgba(255,255,255,.09);--rp-soft:var(--inner);--rp-card:var(--inner);--rp-card-border:rgba(255,255,255,.10);
    --rp-text:#F5F7FB;--rp-muted:#9CB0CA;--rp-muted2:#8298B4;--rp-icon:rgba(96,165,250,.16);--rp-icon-fg:#78AFFF;
    --rp-photo:#18304E;--rp-photo-border:#2B476A;--rp-hover:#142C4B;--rp-view-border:#34506F;
    box-shadow:0 6px 18px rgba(0,0,0,.20);
}
.roster-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 22px;border-bottom:1px solid var(--rp-line);background:var(--rp-soft);}
.roster-heading{display:flex;align-items:center;gap:11px;}
.roster-heading .roster-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:var(--rp-icon);color:var(--rp-icon-fg);font-size:15px;}
.roster-heading h2{margin:0;font-family:'DM Sans',sans-serif;font-size:20px;font-weight:700;color:var(--rp-text);}
.person-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;padding:18px;background:var(--rp-panel);}
.person-card{display:flex;align-items:center;gap:13px;background:var(--rp-card);border:1px solid var(--rp-card-border);border-radius:11px;padding:13px 14px;min-width:0;}
.person-photo{width:44px;height:44px;border-radius:50%;background:var(--rp-photo);border:1px solid var(--rp-photo-border);display:flex;align-items:center;justify-content:center;color:var(--rp-muted);flex-shrink:0;overflow:hidden;}
.person-photo img{width:100%;height:100%;object-fit:cover;display:block;}
.person-info{min-width:0;flex:1;}
.person-name{font-size:14.5px;font-weight:800;color:var(--rp-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.person-meta{margin-top:3px;color:var(--rp-muted);font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.person-card .status-pill{display:inline-flex;align-items:center;gap:5px;margin-top:7px;padding:4px 10px;border-radius:999px;font-size:11.5px;font-weight:800;}
.person-card .status-pill.done{background:rgba(52,211,153,.12);color:#11805F;border:1px solid rgba(52,211,153,.18);}
.person-card .status-pill.pending{background:rgba(245,158,11,.12);color:#A66000;border:1px solid rgba(245,158,11,.18);}
body:not(.light-theme) .person-card .status-pill.done{color:#6EE7B7;}
body:not(.light-theme) .person-card .status-pill.pending{color:#F8C465;}
.person-action{flex-shrink:0;}
.btn-action{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 14px;border-radius:8px;text-decoration:none;font-size:12.5px;font-weight:800;border:1px solid transparent;font-family:'DM Sans',sans-serif;}
.btn-action.evaluate{background:var(--teal);color:#fff;border-color:var(--teal);}
.btn-action.evaluate:hover{background:var(--teal-hover);border-color:var(--teal-hover);}
.btn-action.view{background:transparent;color:var(--rp-muted);border-color:var(--rp-view-border);}
.btn-action.is-disabled{cursor:not-allowed;opacity:.6;}
.btn-action.view.is-disabled{cursor:default;opacity:1;}
.roster-empty{text-align:center;padding:44px 20px;color:var(--rp-muted2);font-size:12.5px;}
.roster-empty i{display:block;margin-bottom:10px;font-size:26px;opacity:.45;}

/* roster header stats (replaces the old bottom summary bar) + a11y polish */
.roster-panel{scroll-margin-top:12px;}
.roster-head{flex-wrap:wrap;gap:12px 16px;}
.roster-stats{display:flex;align-items:center;flex-wrap:wrap;gap:8px 16px;color:var(--rp-muted);font-size:12.5px;}
.roster-stats strong{color:var(--rp-text);}
.roster-stats .period{display:inline-flex;align-items:center;gap:6px;}
.roster-panel a:focus-visible,.eval-group-card:focus-visible{outline:2px solid var(--teal);outline-offset:2px;}

/* ---- match the Admin "Evaluate Others" screen exactly ---- */
body .section-card.peer-shell{min-height:0 !important;}
.peer-shell .roster-panel{margin-top:22px;box-shadow:none;}
body .eval-group-grid.cat-select .eval-group-card.eval-group-card:hover{background:#F4F8FF !important;border-color:rgba(37,99,235,.45) !important;box-shadow:0 10px 24px rgba(16,24,40,.08) !important;transform:translateY(-2px);}
body .eval-group-grid.cat-select .eval-group-card.eval-group-card.is-selected{background:#F4F8FF !important;border-color:var(--teal) !important;border-bottom-color:var(--teal) !important;box-shadow:0 0 0 3px rgba(37,99,235,.14) !important;}
body:not(.light-theme) .eval-group-grid.cat-select .eval-group-card.eval-group-card:hover,
body:not(.light-theme) .eval-group-grid.cat-select .eval-group-card.eval-group-card.is-selected{background:#142C4B !important;}
.person-card{border-radius:12px;box-shadow:0 1px 2px rgba(16,24,40,.04);}
.btn-action{border-radius:10px;padding:10px 16px;font-weight:700;}
.roster-heading h2{font-size:22px;letter-spacing:-.005em;}

/* ===== All pages: the white shell has a fixed height and scrolls INSIDE itself (page never scrolls) ===== */
html body .dashboard-shell{height:calc(100vh - 110px);min-height:0 !important;overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:#B7C6D8 transparent;}
html body .dashboard-shell > *{flex-shrink:0;}
html body.light-theme .dashboard-shell{padding-bottom:28px !important;}
html body .dashboard-shell::-webkit-scrollbar{width:8px;}
html body .dashboard-shell::-webkit-scrollbar-thumb{background:#B7C6D8;border-radius:8px;}
html body .dashboard-shell::-webkit-scrollbar-track{background:transparent;}
html body:not(.light-theme) .dashboard-shell{scrollbar-color:#3A5578 transparent;}
html body:not(.light-theme) .dashboard-shell::-webkit-scrollbar-thumb{background:#3A5578;}
.main > .toast{position:fixed;top:72px;right:28px;z-index:300;margin:0;max-width:420px;box-shadow:0 10px 28px rgba(0,0,0,.18);}
@media(max-width:900px){html body .dashboard-shell{height:auto;overflow:visible;}.main > .toast{left:14px;right:14px;max-width:none;}}

/* fixed-height white box: whitespace stays put and the scrollbar lives INSIDE the box (same as the EA screen) */
body .section-card.peer-box{height:calc(100vh - 110px);min-height:0 !important;margin-bottom:0;overflow-y:auto;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:#B7C6D8 transparent;}
body .section-card.peer-box::-webkit-scrollbar{width:8px;}
body .section-card.peer-box::-webkit-scrollbar-thumb{background:#B7C6D8;border-radius:8px;}
body .section-card.peer-box::-webkit-scrollbar-track{background:transparent;}
body:not(.light-theme) .section-card.peer-box{scrollbar-color:#3A5578 transparent;}
body:not(.light-theme) .section-card.peer-box::-webkit-scrollbar-thumb{background:#3A5578;}
@media(max-width:900px){body .section-card.peer-box{height:auto;overflow:visible;}}
.compact-summary{display:flex;align-items:center;gap:18px;flex-wrap:wrap;padding:13px 18px;border-top:1px solid var(--rp-line);background:var(--rp-soft);color:var(--rp-muted);font-size:11.5px;}
.compact-summary strong{color:var(--rp-text);}
@media(max-width:900px){.person-grid{grid-template-columns:1fr;}}
@media(max-width:560px){.person-card{align-items:flex-start;flex-wrap:wrap;}.person-action{width:100%;}.person-action .btn-action{width:100%;}}
</style>
<style id="fx-polish">
/* ------------------------------------------------------------------
   Evaluate Others polish (same treatment as the admin EA page):
   group cards with progress, clearer roster, stronger contrast.
   Light/dark follow the page's own body.light-theme switch.
   Presentation only: links, filters, forms and evaluation popups are untouched.
   ------------------------------------------------------------------ */
html body:not(#fxp) .cat-heading{margin-bottom:6px!important;}
html body:not(#fxp) .cat-lead{margin:0 0 22px;font-size:14.5px;line-height:1.5;}
html body.light-theme:not(#fxp) .cat-lead{color:#475569;}
html body:not(.light-theme):not(#fxp) .cat-lead{color:#A0B3C6;}

/* group cards */
html body:not(#fxp) .eval-group-grid.cat-select{gap:22px!important;}
html body:not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card{--g:#2563EB;--g-ink:#1D4ED8;--g-bg:#E1EBFF;--g-ring:rgba(37,99,235,.26);
    position:relative;justify-content:flex-start!important;gap:0;min-height:320px!important;padding:32px 22px 24px!important;border-radius:18px!important;
    border-width:1px!important;border-style:solid!important;border-top:5px solid var(--g)!important;}
html body:not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card:has(.eval-group-icon.green){--g:#16A34A;--g-ink:#15803D;--g-bg:#DDF5E6;--g-ring:rgba(22,163,74,.26);}
html body:not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card:has(.eval-group-icon.purple){--g:#7C5FD9;--g-ink:#5F45B8;--g-bg:#ECE3FF;--g-ring:rgba(124,95,217,.26);}
html body:not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card:has(.eval-group-icon.gold){--g:#D97706;--g-ink:#B45309;--g-bg:#FFEFCF;--g-ring:rgba(217,119,6,.26);}
html body:not(#fxp) .eval-group-grid.cat-select .eval-group-icon{width:88px!important;height:88px!important;font-size:34px!important;margin-bottom:16px!important;}
html body:not(#fxp) .eval-group-grid.cat-select .eval-group-title.eval-group-title{font-size:24px!important;margin-bottom:2px!important;}
html body:not(#fxp) .eval-group-grid.cat-select .eval-group-count.eval-group-count{display:inline-flex;margin-top:10px;padding:6px 16px;border-radius:999px;font-size:13.5px!important;font-weight:700;}
html body:not(#fxp) .eval-group-progress{display:flex;flex-direction:column;align-items:center;gap:7px;width:100%;max-width:230px;margin-top:20px;}
html body:not(#fxp) .eval-group-bar{display:block;width:100%;height:8px;border-radius:999px;overflow:hidden;background:rgba(100,116,139,.22);}
html body:not(#fxp) .eval-group-bar i{display:block;height:100%;border-radius:999px;background:var(--g);transition:width .3s ease;}
html body:not(#fxp) .eval-group-progress.complete .eval-group-bar i{background:#16A34A;}
html body:not(#fxp) .eval-group-progress-text{font-size:13px;font-weight:500;}
html body:not(#fxp) .eval-group-progress-text strong{font-weight:800;}
html body:not(#fxp) .eval-group-progress-text.empty{margin-top:20px;}
html body:not(#fxp) .eval-group-cta{display:inline-flex;margin-top:auto;padding-top:20px;font-size:13px;font-weight:800;color:var(--g-ink);}
html body:not(#fxp) .eval-group-cta .cta-select,html body:not(#fxp) .eval-group-cta .cta-selected{display:inline-flex;align-items:center;gap:8px;}
html body:not(#fxp) .eval-group-cta .cta-selected{display:none;}
html body:not(#fxp) .eval-group-card.is-selected .eval-group-cta .cta-select{display:none;}
html body:not(#fxp) .eval-group-card.is-selected .eval-group-cta .cta-selected{display:inline-flex;}
html body:not(#fxp) .eval-group-cta i{font-size:12px;transition:transform .15s ease;}
html body:not(#fxp) .eval-group-card:hover .eval-group-cta .cta-select i{transform:translateX(4px);}
html body:not(#fxp) .eval-group-card:focus-visible{outline:3px solid var(--g);outline-offset:3px;}

html body.light-theme:not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card{background:#fff!important;border-color:#CDD8E6!important;border-top-color:var(--g)!important;box-shadow:0 1px 2px rgba(11,31,58,.06),0 8px 22px rgba(11,31,58,.06)!important;}
html body.light-theme:not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card:hover{background:#fbfdff!important;border-color:var(--g)!important;box-shadow:0 14px 32px rgba(11,31,58,.13)!important;transform:translateY(-3px)!important;}
html body.light-theme:not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card.is-selected{background:#fff!important;border-color:var(--g)!important;box-shadow:0 0 0 3px var(--g-ring),0 12px 28px rgba(11,31,58,.12)!important;}
html body.light-theme:not(#fxp) .eval-group-grid.cat-select .eval-group-title.eval-group-title{color:#0b1f3a!important;}
html body.light-theme:not(#fxp) .eval-group-grid.cat-select .eval-group-count.eval-group-count{background:var(--g-bg);color:var(--g-ink)!important;}
html body.light-theme:not(#fxp) .eval-group-progress-text{color:#475569;}
html body.light-theme:not(#fxp) .eval-group-progress-text strong{color:#0b1f3a;}
html body:not(.light-theme):not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card{border-top-color:var(--g)!important;}
html body:not(.light-theme):not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card:hover,html body:not(.light-theme):not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card.is-selected{border-color:var(--g)!important;}
html body:not(.light-theme):not(#fxp) .eval-group-grid.cat-select .eval-group-count.eval-group-count{background:rgba(255,255,255,.08);color:#E5ECF5!important;}
html body:not(.light-theme):not(#fxp) .eval-group-progress-text{color:#A0B3C6;}
html body:not(.light-theme):not(#fxp) .eval-group-progress-text strong{color:#F1F5F9;}
html body:not(.light-theme):not(#fxp) .eval-group-cta{color:var(--g);}

/* roster */
html body:not(#fxp) .roster-panel{border-radius:18px!important;}
html body:not(#fxp) .roster-head{padding:20px 26px!important;}
html body:not(#fxp) .roster-heading .roster-icon{width:42px!important;height:42px!important;font-size:17px!important;}
html body:not(#fxp) .roster-heading h2{font-size:24px!important;}
html body:not(#fxp) .roster-stats{gap:8px 10px!important;}
html body:not(#fxp) .roster-stats span{padding:6px 14px;border-radius:999px;font-size:13px;font-weight:600;border:1px solid transparent;}
html body:not(#fxp) .roster-stats strong{font-size:15px;font-weight:800;color:inherit;}
html body.light-theme:not(#fxp) .roster-stats span{background:#EEF2F7;color:#334155;border-color:#D3DCE8;}
html body.light-theme:not(#fxp) .roster-stats span:nth-child(2){background:#DDF4E6;color:#0A6A3B;border-color:#9ED8B7;}
html body.light-theme:not(#fxp) .roster-stats span:nth-child(3){background:#FFF1D4;color:#8A5300;border-color:#EBCB88;}
html body.light-theme:not(#fxp) .roster-stats span.period{background:#EEF2FF;color:#3730A3;border-color:#C7D2FE;}
html body:not(.light-theme):not(#fxp) .roster-stats span{background:rgba(255,255,255,.06);color:#C9D6E8;border-color:rgba(255,255,255,.10);}
html body:not(.light-theme):not(#fxp) .roster-stats span:nth-child(2){background:rgba(34,197,94,.14);color:#86EFAC;border-color:rgba(34,197,94,.3);}
html body:not(.light-theme):not(#fxp) .roster-stats span:nth-child(3){background:rgba(245,158,11,.14);color:#F8C465;border-color:rgba(245,158,11,.3);}
html body:not(#fxp) .roster-panel .person-grid{gap:14px!important;padding:22px!important;}
html body:not(#fxp) .roster-panel .person-card{gap:16px!important;padding:16px 18px!important;border-radius:14px!important;border-left-width:4px!important;transition:box-shadow .15s ease;}
html body:not(#fxp) .roster-panel .person-card.is-done{border-left-color:#16A34A!important;}
html body:not(#fxp) .roster-panel .person-card.is-pending{border-left-color:#F59E0B!important;}
html body:not(#fxp) .roster-panel .person-card:hover{box-shadow:0 6px 18px rgba(11,31,58,.10);}
html body:not(#fxp) .roster-panel .person-photo{width:56px!important;height:56px!important;}
html body:not(#fxp) .roster-panel .person-name{font-size:15.5px!important;}
html body:not(#fxp) .roster-panel .person-meta{font-size:13px!important;margin-top:2px!important;}
html body:not(#fxp) .roster-panel .person-card .status-pill{font-size:12px!important;padding:4px 11px!important;margin-top:8px!important;}
html body:not(#fxp) .roster-panel .btn-action{padding:11px 18px!important;border-radius:10px!important;font-size:13px!important;min-width:112px;}
html body.light-theme:not(#fxp) .roster-panel .person-card{background:#fff!important;border-color:#CDD8E6!important;}
html body.light-theme:not(#fxp) .roster-panel .person-name{color:#0b1f3a!important;}
html body.light-theme:not(#fxp) .roster-panel .person-meta{color:#475569!important;}
html body.light-theme:not(#fxp) .roster-panel .person-card .status-pill.done{background:#D6F2E4!important;color:#0A6A3B!important;border-color:#8ED0AD!important;}
html body.light-theme:not(#fxp) .roster-panel .person-card .status-pill.pending{background:#FFF1D4!important;color:#8A5300!important;border-color:#EBCB88!important;}
html body.light-theme:not(#fxp) .roster-panel .btn-action.view{background:#fff!important;color:#1e293b!important;border-color:#b6c3d6!important;}
html body:not(#fxp) .roster-empty{padding:56px 20px!important;font-size:14px!important;}

@media(max-width:900px){html body:not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card{min-height:250px!important;}}
@media(max-width:560px){html body:not(#fxp) .eval-group-grid.cat-select .eval-group-card.eval-group-card{min-height:0!important;}html body:not(#fxp) .roster-panel .person-card{flex-wrap:wrap;}}
</style>
</head>
<body class="light-theme">

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand portal-brand">
        <div class="portal-brand-logo"><img src="../image/pbi_logo" alt="PBI" onerror="this.style.display='none'"/></div>
        <div class="portal-brand-copy">
            <strong>Faculty Workspace</strong>
        </div>
    </div>

    <div class="portal-sidebar-profile">
        <div class="portal-profile-avatar-wrap">
            <?php if ($faculty_photo): ?>
            <img class="portal-profile-avatar" src="<?= UPLOAD_URL . htmlspecialchars($faculty_photo) ?>" alt=""/>
            <?php else: ?>
            <span class="portal-profile-avatar portal-profile-fallback"><?= htmlspecialchars($initials) ?></span>
            <?php endif; ?>
        </div>
        <div class="portal-profile-name"><?= htmlspecialchars($full_name) ?></div>
        <div class="portal-profile-role">FACULTY<?= $designation ? ' · ' . htmlspecialchars($designation) : '' ?></div>
    </div>

    <nav class="sidebar-nav portal-sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="faculty_dashboard.php?page=dashboard" class="nav-link <?= $page==='dashboard'?'active':'' ?>">
            <i class="fa-solid fa-house"></i><span>Dashboard</span>
        </a>
        <a href="faculty_dashboard.php?page=profile" class="nav-link <?= $page==='profile'?'active':'' ?>">
            <i class="fa-solid fa-id-badge"></i><span>Role &amp; Designation</span>
        </a>

        <div class="nav-section-label sidebar-section-secondary">Evaluation</div>
        <a href="faculty_dashboard.php?page=my_results" class="nav-link <?= $page==='my_results'?'active':'' ?>">
            <i class="fa-solid fa-chart-bar"></i><span>Evaluations Received</span>
        </a>
        <a href="faculty_dashboard.php?page=peer" class="nav-link <?= in_array($page,['peer','peer_eval'])?'active':'' ?>">
            <i class="fa-solid fa-users-viewfinder"></i><span>Evaluate Others</span>
            <?php if ($page==='peer' && (!empty($peers_all) || !empty($school_heads))): ?>
            <span class="side-nav-badge"><?= (count($peers_all) - count($done_peers)) + (count($school_heads) - count($done_school_heads)) ?></span>
            <?php endif; ?>
        </a>

        <div class="nav-section-label sidebar-section-secondary">Support</div>
        <a href="faculty_dashboard.php?page=settings" class="nav-link <?= $page==='settings'?'active':'' ?>">
            <i class="fa-solid fa-gear"></i><span>Settings</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php" class="btn-logout-side" onclick="return openLogoutModal(this)">
            <i class="fa-solid fa-power-off"></i><span>Log Out</span>
        </a>
    </div>
</aside>
    
<!-- PROFILE PHOTO MODAL -->
<div class="photo-modal-overlay" id="photoModal">
    <div class="photo-modal">
        <div class="photo-modal-header">
            <div class="photo-modal-title">Profile Photo</div>
            <button style="background:none;border:none;color:var(--muted);font-size:18px;cursor:pointer;" onclick="closePhotoModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="photo-modal-body">
            <form method="POST" action="faculty_dashboard.php" enctype="multipart/form-data" id="photoForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
                        <input type="hidden" name="return_page" value="<?= in_array($page, ['settings', 'profile'], true) ? htmlspecialchars($page) : 'profile' ?>"/>
                        <input type="hidden" name="portal_role" value="teacher"/>
                <div class="photo-upload-circle" id="photoCircle" onclick="document.getElementById('photoFileInput').click()">
                    <img id="photoPreviewImg" src="<?= $faculty_photo ? UPLOAD_URL.htmlspecialchars($faculty_photo) : '' ?>"
                         style="<?= $faculty_photo ? 'display:block' : '' ?>"/>
                    <i class="fa-solid fa-camera upload-icon-modal" id="uploadIconEl" style="<?= $faculty_photo ? 'display:none' : '' ?>"></i>
                </div>
                <div class="photo-upload-hint-modal">
                    Click to choose a photo<br>
                    <span onclick="document.getElementById('photoFileInput').click()">Browse files</span>
                    &nbsp;·&nbsp; JPG, PNG, WebP · Max 10MB
                </div>
                <input type="file" id="photoFileInput" name="photo" accept="image/jpeg,image/png,image/webp,image/gif"
                       onchange="previewPhoto(this)" style="display:none"/>
            </form>
        </div>
        <div class="photo-modal-footer">
            <button class="btn-skip-photo" onclick="closePhotoModal()">Skip / Cancel</button>
            <button class="btn-save-photo" onclick="submitPhoto()"><i class="fa-solid fa-check"></i> Save Photo</button>
        </div>
    </div>
</div>

<!-- EVALUATION DETAILS MODAL -->
<div class="eval-modal-overlay" id="evalDetailsModal">
    <div class="eval-modal">
        <div class="eval-modal-header">
            <div class="eval-modal-title"><i class="fa-solid fa-star" style="color:var(--teal-hover);margin-right:8px;"></i>Evaluation Details</div>
            <button class="eval-modal-close" onclick="closeEvalDetails()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="eval-modal-body" id="evalDetailsBody">
            <div class="eval-modal-loading"><i class="fa-solid fa-spinner fa-spin"></i>Loading evaluation…</div>
        </div>
    </div>
</div>

<nav class="top-nav">
    <div style="display:flex;align-items:center;gap:13px;">
        <button class="hamburger" onclick="document.getElementById('sidebar').classList.toggle('open')">
            <i class="fa-solid fa-bars"></i>
        </button>
        <div class="nav-page-title">
            <?php $titles=['dashboard'=>'Dashboard','profile'=>'Role & Designation','my_results'=>"Evaluations Received",'peer'=>'Evaluate Others','peer_eval'=>'Evaluate Others','settings'=>'Settings'];
            $title_icons=['dashboard'=>'fa-house','profile'=>'fa-id-badge','my_results'=>'fa-chart-bar','peer'=>'fa-clipboard-check','peer_eval'=>'fa-clipboard-check','settings'=>'fa-gear'];
            $title_key = isset($titles[$page]) ? $page : 'dashboard'; ?>
            <i class="fa-solid <?= $title_icons[$title_key] ?>"<?= $title_icons[$title_key] === 'fa-clipboard-check' ? ' style="color:#2563EB"' : '' ?> aria-hidden="true"></i>
            <span><?= htmlspecialchars($titles[$title_key]) ?></span>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:12px;">
        <?php if ($period): ?>
        <div class="period-badge">
            <i class="fa-solid fa-calendar-check"></i>
            <?= htmlspecialchars($period['period_label'] ?? ($period['semester'] ?? '')) ?>
        </div>
        <?php endif; ?>

        <div class="notif-wrap" id="notifWrap">
            <button class="notif-btn <?= $unread_count>0?'has-unread':'' ?>" id="notifBtn" onclick="toggleNotifDropdown(event)" title="Notifications">
                <i class="fa-regular fa-bell"></i>
                <?php if ($unread_count > 0): ?>
                <span class="notif-badge show" id="notifBadge"><?= $unread_count > 99 ? '99+' : $unread_count ?></span>
                <?php endif; ?>
            </button>
            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-header">
                    <span class="notif-header-title"><i class="fa-solid fa-bell" style="color:var(--teal-hover);"></i> Notifications</span>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
                        <input type="hidden" name="mark_notifications_read" value="1"/>
                        <button type="submit" class="notif-mark-read">Mark all read</button>
                    </form>
                </div>
                <div class="notif-list">
                    <?php if (empty($my_notifications)): ?>
                    <div class="notif-empty"><i class="fa-regular fa-bell-slash"></i>No notifications yet.</div>
                    <?php else: foreach ($my_notifications as $n):
                        $is_eval = $n['type'] === 'evaluation_received';
                        $n_icon  = $is_eval ? 'fa-star' : 'fa-id-badge';
                        $n_color = $is_eval ? '#facc15' : '#2B6CB0';
                    ?>
                    <a class="notif-item <?= empty($n['is_read'])?'unread':'' ?>" data-notification-id="<?= (int)$n['id'] ?>" href="<?= htmlspecialchars(notif_link_for((string)$n['type'], 'teacher')) ?>">
                        <div class="notif-icon" style="color:<?= $n_color ?>;background:<?= $n_color ?>22;"><i class="fa-solid <?= $n_icon ?>"></i></div>
                        <div style="flex:1;min-width:0;">
                            <div class="notif-text"><?= htmlspecialchars($n['message']) ?></div>
                            <div class="notif-meta"><?= date('M d, Y g:i A', strtotime($n['created_at'])) ?></div>
                        </div>
                    </a>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>
</nav>

<main class="main">

<?php if ($toast): ?>
<div class="toast toast-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($toast) ?></div>
<?php endif; ?>
<?php if ($toast_error): ?>
<div class="toast toast-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($toast_error) ?></div>
<?php endif; ?>

<?php if ($page === 'dashboard'): ?>

<div class="dashboard-shell">
    <div class="dashboard-intro">
        <?php
        // Countdown to opening: only while closed, on a schedule, and the start is still ahead.
        $fsOpensAtIso = '';
        if (!$evaluation_open && $dashboard_start && ($dashboard_sys['control_mode'] ?? 'schedule') === 'schedule') {
            try {
                $fsNowDt = new DateTimeImmutable('now', $dashboard_start->getTimezone());
                if ($dashboard_start > $fsNowDt) $fsOpensAtIso = $dashboard_start->format('c');
            } catch (Throwable $e) { $fsOpensAtIso = ''; }
        }
        ?>
        <style>
        .dashboard-intro .status-row{display:flex;align-items:center;flex-wrap:wrap;gap:10px;}
        .fs-open-cd{display:inline-flex;align-items:baseline;gap:5px;font-size:11.5px;font-weight:700;color:#B42318;}
        .fs-open-cd-sep{color:#F3B0B0;margin-right:3px;font-weight:400;}
        .fs-open-cd .fs-cd-unit[hidden]{display:none;}
        .fs-open-cd .fs-cd-unit b{font-weight:800;font-variant-numeric:tabular-nums;color:inherit;}
        body:not(.light-theme) .fs-open-cd,body:not(.light-theme) .fs-open-cd .fs-cd-unit b{color:#F87171;}
        body:not(.light-theme) .fs-open-cd-sep{color:#7F3A3A;}
        </style>
        <?php if ($period): ?>
        <div class="status-row">
            <span class="status-pill <?= $evaluation_open ? '' : 'closed' ?>">
                <i class="fa-solid <?= $evaluation_open ? 'fa-lock-open' : 'fa-lock' ?>"></i>
                <?= $evaluation_open ? 'Evaluation period is currently open' : ($fsOpensAtIso !== '' ? 'Evaluation is currently closed' : 'Evaluation is currently closed — waiting for the scheduled opening') ?>
                <?php if ($fsOpensAtIso !== ''): ?>
                <span class="fs-open-cd" id="fsOpenCountdown" data-open="<?= htmlspecialchars($fsOpensAtIso) ?>" aria-live="off">
                    <span class="fs-open-cd-sep" aria-hidden="true">|</span>
                    <span class="fs-open-cd-label">Opens in</span>
                    <span class="fs-cd-unit" id="fs-oc-days-wrap" hidden><b id="fs-oc-days">0</b> D</span>
                    <span class="fs-cd-unit" id="fs-oc-hours-wrap" hidden><b id="fs-oc-hours">0</b> H</span>
                    <span class="fs-cd-unit"><b id="fs-oc-mins">0</b> M</span>
                    <span class="fs-cd-unit"><b id="fs-oc-secs">0</b> s</span>
                </span>
                <?php endif; ?>
            </span>
            <?php if ($fsOpensAtIso !== ''): ?>
            <script>
            (function(){
                var row = document.getElementById('fsOpenCountdown');
                if (!row) return;
                var openAt = new Date(row.dataset.open).getTime();
                var el = function(id){ return document.getElementById(id); };
                var timer = null;
                function tick(){
                    var diff = Math.max(0, openAt - Date.now());
                    var days = Math.floor(diff / 86400000); diff -= days * 86400000;
                    var hours = Math.floor(diff / 3600000); diff -= hours * 3600000;
                    var mins = Math.floor(diff / 60000); diff -= mins * 60000;
                    var secs = Math.floor(diff / 1000);
                    el('fs-oc-days').textContent = days;
                    el('fs-oc-hours').textContent = hours;
                    el('fs-oc-mins').textContent = mins;
                    el('fs-oc-secs').textContent = secs;
                    el('fs-oc-days-wrap').hidden = days === 0;
                    el('fs-oc-hours-wrap').hidden = days === 0 && hours === 0;
                    if (openAt - Date.now() <= 0) {
                        clearInterval(timer);
                        // Reload once so the page switches to "open" (guard prevents a reload loop).
                        var key = 'fsOpenReload:' + row.dataset.open;
                        try { if (sessionStorage.getItem(key)) return; sessionStorage.setItem(key, '1'); } catch (e) {}
                        setTimeout(function(){ location.reload(); }, 1500);
                    }
                }
                tick();
                timer = setInterval(tick, 1000);
            })();
            </script>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="evaluation-schedule-card">
        <div class="schedule-summary-grid">
            <div class="schedule-summary-item">
                <span class="schedule-label">Academic Year</span>
                <strong><?= htmlspecialchars($dashboard_acad_year ?: '—') ?></strong>
            </div>
            <div class="schedule-summary-item">
                <span class="schedule-label">Evaluation Opens</span>
                <strong><?= htmlspecialchars($dashboard_open_display) ?></strong>
            </div>
            <div class="schedule-summary-item">
                <span class="schedule-label">Evaluation Closes</span>
                <strong><?= htmlspecialchars($dashboard_close_display) ?></strong>
            </div>
            <div class="schedule-summary-item schedule-status-item">
                <span class="schedule-label">Status</span>
                <span class="schedule-status <?= htmlspecialchars($dashboard_status_class) ?>">
                    <?= htmlspecialchars($dashboard_status) ?>
                </span>
            </div>
        </div>
    </div>

<div class="welcome-bar">
    <div class="welcome-text">
        <h2>Welcome, <?= htmlspecialchars($first_name) ?>!</h2>
        <p>
            <strong><?= htmlspecialchars($designation) ?></strong>
            &nbsp;·&nbsp;
            <?= $period ? htmlspecialchars($period['period_label'] ?? $period['semester']) : 'No active evaluation period' ?>
        </p>
    </div>
</div>

<div class="stats-grid">
    <a class="stat-card stat-card-link" href="faculty_dashboard.php?page=my_results" aria-label="View Evaluations Received" title="View Evaluations Received">
        <div class="stat-card-icon blue"><i class="fa-solid fa-users" aria-hidden="true"></i></div>
        <div class="stat-card-lbl">Evaluations Received</div>
        <div class="stat-card-val teal"><?= $my_total ?></div>
    </a>
    <div class="stat-card">
        <div class="stat-card-icon green"><i class="fa-solid fa-circle-check"></i></div>
        <div class="stat-card-lbl">Average Rating</div>
        <div class="stat-card-val gold"><?= $my_avg !== null ? number_format($my_avg, 2) . ' / 5' : '—' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon gold"><i class="fa-solid fa-award"></i></div>
        <div class="stat-card-lbl">Performance Level</div>
        <div class="stat-card-val sm" style="color:<?= $perf_color ?><?= $perf_long ? ';font-size:15px;line-height:1.25' : '' ?>"><?= htmlspecialchars($perf_label) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon sky"><i class="fa-solid fa-calendar"></i></div>
        <div class="stat-card-lbl">Current Period</div>
        <div class="stat-card-val sm" style="color:var(--teal-hover)">
            <?= $period ? htmlspecialchars($period['semester'] ?? '—') : 'None' ?>
        </div>
    </div>
</div>

<section class="dashboard-quick-access" aria-label="Quick access">
    <div class="quick-access-heading">
        <div>
            <span class="quick-access-kicker">WORKSPACE SHORTCUTS</span>
            <h2>Continue where you need to be</h2>
        </div>
        <span class="quick-access-caption"></span>
    </div>
    <div class="quick-access-grid">
        <a class="quick-access-card quick-blue" href="faculty_dashboard.php?page=my_results">
            <span class="quick-access-icon"><i class="fa-solid fa-chart-column"></i></span>
            <span class="quick-access-copy">
                <strong>Evaluations Received</strong>
                <small>Review your feedback and evaluation results.</small>
            </span>
            <i class="fa-solid fa-arrow-right quick-access-arrow" aria-hidden="true"></i>
        </a>
        <a class="quick-access-card quick-green" href="faculty_dashboard.php?page=peer">
            <span class="quick-access-icon"><i class="fa-solid fa-users"></i></span>
            <span class="quick-access-copy">
                <strong>Your Evaluation</strong>
                <small>Open the evaluation tasks assigned to you.</small>
            </span>
            <i class="fa-solid fa-arrow-right quick-access-arrow" aria-hidden="true"></i>
        </a>
        <a class="quick-access-card quick-amber" href="faculty_dashboard.php?page=profile">
            <span class="quick-access-icon"><i class="fa-solid fa-id-card"></i></span>
            <span class="quick-access-copy">
                <strong>Role &amp; Designation</strong>
                <small>View your role, designation, and assigned levels.</small>
            </span>
            <i class="fa-solid fa-arrow-right quick-access-arrow" aria-hidden="true"></i>
        </a>
    </div>
</section>

</div>

<?php elseif ($page === 'profile'): ?>
<!-- ROLE & DESIGNATION — built from the same shell/intro/welcome-bar/card language as the Dashboard -->
<div class="dashboard-shell rd-shell">

    <div class="welcome-bar">
        <div class="rd-id">
            <div class="rd-avatar-wrap">
                <?php if ($faculty_photo): ?>
                <img class="rd-avatar" src="<?= UPLOAD_URL . htmlspecialchars($faculty_photo) ?>" alt="<?= htmlspecialchars($full_name) ?>"/>
                <?php else: ?>
                <div class="rd-avatar"><i class="fa-solid fa-chalkboard-user"></i></div>
                <?php endif; ?>
                <button type="button" class="rd-cam" onclick="openPhotoModal()" title="Update Profile Photo" aria-label="Update Profile Photo">
                    <i class="fa-solid fa-camera"></i>
                </button>
            </div>
            <div class="welcome-text">
                <h2><?= htmlspecialchars($full_name) ?></h2>
                <p>
                    <strong><?= htmlspecialchars($designation) ?></strong>
                    &nbsp;·&nbsp;
                    <?= $period ? htmlspecialchars($period['period_label'] ?? $period['semester']) : 'No active evaluation period' ?>
                </p>
                <button type="button" class="rd-photo-link" onclick="openPhotoModal()">
                    <i class="fa-solid fa-camera"></i> Update Profile Photo
                </button>
            </div>
        </div>

        <div class="rd-levels" id="levelDdWrap">
            <div class="rd-levels-label"><i class="fa-solid fa-layer-group"></i> My Teaching Level(s)</div>
            <div class="rd-levels-pills">
                <?php if (empty($my_levels)): ?>
                <span class="rd-levels-empty"><i class="fa-solid fa-triangle-exclamation"></i> No level assigned yet — contact the admin.</span>
                <?php else: foreach ($my_levels as $lvl): ?>
                <span class="rd-level-pill"><?= htmlspecialchars($lvl) ?></span>
                <?php endforeach; endif; ?>
            </div>
            <div class="rd-levels-hint">Set by the admin — reach out to them to change this.</div>
        </div>
    </div>

    <section class="rd-card" aria-label="Assign or update my role">
        <div class="rd-card-head">
            <span class="rd-card-icon"><i class="fa-solid fa-tags"></i></span>
            <div>
                <h2>Assign / Update My Role</h2>
                <p>Your role determines which evaluation questions apply to you and how you appear in the questionnaire. Changes take effect immediately and the admin is notified.</p>
            </div>
        </div>

        <div class="rd-label"><i class="fa-solid fa-bolt"></i> Quick Pick</div>
        <div class="rd-chips">
            <?php foreach ($desig_suggestions as $d): ?>
            <button type="button" class="rd-chip <?= ($d === $designation) ? 'is-current' : '' ?>"
                    onclick="var i=document.getElementById('roleInput');i.value=<?= htmlspecialchars(json_encode($d), ENT_QUOTES) ?>;i.focus();">
                <?= htmlspecialchars($d) ?>
            </button>
            <?php endforeach; ?>
        </div>

        <div class="rd-label"><i class="fa-solid fa-pen-to-square"></i> Or type a custom role</div>
        <form method="POST" id="roleForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
            <input type="hidden" name="update_designation" value="1"/>
            <div class="rd-input-row">
                <input type="text" name="new_designation" id="roleInput" class="rd-input"
                       placeholder="e.g. Department Head, Bookkeeper…"
                       value="<?= htmlspecialchars($designation) ?>" required/>
                <button type="submit" class="rd-save">
                    <i class="fa-solid fa-floppy-disk"></i> Save &amp; Notify Admin
                </button>
            </div>
        </form>

        <div class="rd-note">
            <i class="fa-solid fa-circle-info"></i>
            <span>Your role change is logged and sent to the admin automatically so the admin can confirm or reassign you if needed.</span>
        </div>
    </section>

</div>

    <?php elseif ($page === 'settings'): ?>

<div class="dashboard-shell settings-shell principal-settings-layout" style="--ap-accent:#2563EB;--ap-accent-strong:#1D4ED8;--ap-accent-dark:#60A5FA;--ap-accent-rgb:37,99,235;">
    <div class="settings-tabs" role="tablist" aria-label="Settings sections">
        <button type="button" class="settings-tab active" id="settingsTabProfile" role="tab" aria-selected="true" aria-controls="settingsPanelProfile" data-settings-tab="profile"><i class="fa-solid fa-user-gear"></i> Profile &amp; Security</button>
        <button type="button" class="settings-tab" id="settingsTabAppearance" role="tab" aria-selected="false" aria-controls="settingsPanelAppearance" data-settings-tab="appearance"><i class="fa-solid fa-palette"></i> Appearance</button>
    </div>

    <div class="settings-layout-grid settings-tab-panel" id="settingsPanelProfile" role="tabpanel" aria-labelledby="settingsTabProfile">
        <div class="settings-card full ac-card ac-c5 sq-top-card <?= $sq_status === 'ok' ? 'is-set' : 'is-unset' ?>" id="security-questions-settings">
                <div class="sq-top-row">
                    <div class="ac-head">
                        <span class="ac-icon"><i class="fa-solid <?= $sq_status === 'ok' ? 'fa-shield-halved' : 'fa-triangle-exclamation' ?>"></i></span>
                        <div>
                            <h2>Security Questions</h2>
                            <p class="ac-sub">These questions are used to recover your account if you forget your password.</p>
                            <?php if ($sq_status === 'ok'): ?>
                            <div class="sq-status ok"><i class="fa-solid fa-circle-check"></i> Security questions are set</div>
                            <?php elseif ($sq_status === 'retired'): ?>
                            <div class="sq-status warn"><i class="fa-solid fa-triangle-exclamation"></i> One of your questions is no longer offered &mdash; please update them</div>
                            <?php else: ?>
                            <div class="sq-status warn"><i class="fa-solid fa-triangle-exclamation"></i> Not set up yet &mdash; you won&rsquo;t be able to recover your account without them</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="sq-top-action">
                        <button type="button" class="btn-primary" id="sqOpen"><i class="fa-solid fa-pen-to-square"></i> <?= $sq_status === 'none' ? 'Set Security Questions' : 'Update Security Questions' ?></button>
                    </div>
                </div>
            </div>

        <section class="settings-card full ac-card ac-c2">
            <div class="ac-head"><span class="ac-icon"><i class="fa-solid fa-id-badge"></i></span><div><h2>Profile Photo</h2><p class="ac-sub">Update the photo shown in your sidebar and profile.</p></div></div>
            <div class="profile-card">
                <?php if ($faculty_photo): ?><img class="profile-photo-lg" src="<?= UPLOAD_URL . htmlspecialchars($faculty_photo) ?>" alt="Faculty profile photo"/><?php else: ?><div class="profile-photo-lg profile-photo-fallback"><i class="fa-solid fa-user"></i></div><?php endif; ?>
                <div class="photo-form-wrap"><button class="btn-primary" type="button" onclick="openPhotoModal()"><i class="fa-solid fa-upload"></i> Upload New Photo</button><div class="photo-help">JPG, PNG, WEBP, or GIF only. Maximum file size: 10MB.</div></div>
            </div>
        </section>

        <section class="settings-card full ac-card ac-c3">
            <div class="ac-head"><span class="ac-icon"><i class="fa-solid fa-user-pen"></i></span><div><h2>Profile Details</h2><p class="ac-sub">Update your name, username, and designation.</p></div></div>
            <form method="post" action="faculty_dashboard.php?page=settings" class="profile-details-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"><input type="hidden" name="update_account_name" value="1">
                <div class="form-grid">
                    <div class="form-group"><label for="facultySettingsName">Full Name</label><input id="facultySettingsName" type="text" name="full_name" maxlength="150" required value="<?= htmlspecialchars($full_name) ?>" autocomplete="name"></div>
                    <div class="form-group"><label for="facultySettingsUsername">Username</label><input id="facultySettingsUsername" type="text" name="username" maxlength="50" pattern="\S+" title="Username cannot contain spaces" required value="<?= htmlspecialchars($account_username) ?>" autocomplete="username"></div>
                    <div class="form-group"><label for="facultySettingsDesignation">Designation</label><input id="facultySettingsDesignation" type="text" name="designation" maxlength="100" required value="<?= htmlspecialchars($designation) ?>"></div>
                </div>
                <button class="btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
            </form>
        </section>

        <section class="settings-card full ac-card ac-c4">
            <div class="ac-head"><span class="ac-icon"><i class="fa-solid fa-lock"></i></span><div><h2>Change Password</h2><p class="ac-sub">Your new password must be at least 8 characters and different from your current password.</p></div></div>
            <form method="post" action="faculty_dashboard.php?page=settings" class="profile-details-form" id="facultySettingsPasswordForm" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"><input type="hidden" name="update_password" value="1">
                <div class="form-grid">
                    <div class="form-group"><label for="facultyCurrentPassword">Current Password</label><input id="facultyCurrentPassword" type="password" name="current_password" required autocomplete="current-password"></div>
                    <div class="form-group"></div>
                    <div class="form-group"><label for="facultyNewPassword">New Password</label><input id="facultyNewPassword" type="password" name="new_password" minlength="8" required autocomplete="new-password"></div>
                    <div class="form-group"><label for="facultyConfirmPassword">Confirm New Password</label><input id="facultyConfirmPassword" type="password" name="confirm_password" minlength="8" required autocomplete="new-password"></div>
                </div>
                <button class="btn-primary" type="submit"><i class="fa-solid fa-key"></i> Update Password</button>
            </form>
        </section>

        
    </div>

    <?php
$sqIsNew = ($sq_status === 'none');
$sq_existing = (isset($sq_existing) && is_array($sq_existing)) ? $sq_existing : [];
$sqQuestionList = [];
try { $sqQuestionList = sq_questions(); } catch (Throwable $e) { error_log('sq_questions failed: ' . $e->getMessage()); }
?>
<style id="sqm-styles">
.sq-top-row{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.sq-top-row .ac-head{flex:1;min-width:260px;margin-bottom:0 !important}
.sq-top-row .sq-top-action{flex:0 0 auto}
.sq-top-row .sq-status{margin:10px 0 0}
.settings-shell .sq-top-card.is-unset{border:2px solid #E2A33B !important;box-shadow:0 0 0 4px rgba(226,163,59,.16) !important}
.settings-shell .sq-top-card.is-unset::before{background:#E2A33B !important}
.settings-shell .sq-top-card.is-unset .ac-icon{background:rgba(226,163,59,.18) !important;color:#D97706 !important}
.settings-shell .sq-top-card.is-unset .ac-icon i{color:#D97706 !important}

.sqm-overlay{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(11,31,58,.55);overscroll-behavior:contain}
.sqm-overlay[hidden]{display:none}
.sqm-modal{--sq-acc:#2563EB;--sq-acc-h:#3B82F6;--sq-line:rgba(128,140,160,.30);width:min(620px,100%);max-height:calc(100vh - 40px);overflow:auto;background:var(--mid,#fff);border:1px solid var(--sq-line);border-radius:16px;padding:20px 22px;box-shadow:0 24px 60px rgba(11,31,58,.35);color:var(--light,#0B1F3A)}
.sqm-modal,.sqm-modal *{box-sizing:border-box;font-family:'DM Sans',system-ui,-apple-system,"Segoe UI",sans-serif}
.sqm-modal:focus{outline:none}
.sqm-modal [hidden]{display:none !important}
.sqm-head{position:sticky;top:-20px;z-index:2;margin:-20px -22px 0;padding:20px 22px 6px;background:var(--mid,#fff);border-radius:16px 16px 0 0}
.sqm-head-row{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.sqm-head-text{flex:1;min-width:220px}
.sqm-modal h3{font-size:18px;font-weight:700;line-height:1.25;color:var(--light,#0B1F3A);margin:0 0 4px}
.sqm-text{font-size:13.5px;line-height:1.5;color:var(--muted,#405A76);margin:0}
.sqm-head-actions{display:flex;gap:10px;flex:0 0 auto}
.sqm-btn{height:40px;padding:0 16px;border:1px solid var(--sq-line);background:transparent;color:var(--light,#0B1F3A);border-radius:10px;font-weight:700;font-size:13px;cursor:pointer}
.sqm-btn:hover{border-color:var(--sq-acc)}
.sqm-btn.primary{background:var(--sq-acc);border-color:var(--sq-acc);color:#fff}
.sqm-btn.primary:hover:not(:disabled){background:var(--sq-acc-h);border-color:var(--sq-acc-h)}
.sqm-btn.danger{background:#D6455D;border-color:#D6455D;color:#fff}
.sqm-btn:disabled{opacity:.55;cursor:not-allowed}
.sqm-error{margin-top:12px;padding:10px 12px;border-radius:10px;background:rgba(240,84,84,.12);border:1px solid rgba(240,84,84,.35);color:#E05252;font-size:12.5px;line-height:1.45;font-weight:600}
.sqm-discard{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px;padding:10px 12px;border:1px solid #F5D48A;background:rgba(245,212,138,.16);border-radius:10px;color:#B07400;font-size:13px}
.sqm-discard span{flex:1;min-width:180px}
.sqm-sec{margin:12px 0 0}
.sqm-sec-title{font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--muted,#67819E);margin:0 0 6px}
.sqm-confirm{display:flex;gap:12px;align-items:center;padding:12px 14px;border:2px solid var(--sq-acc);border-radius:12px;background:color-mix(in srgb,var(--sq-acc) 10%,transparent);box-shadow:0 0 0 4px color-mix(in srgb,var(--sq-acc) 12%,transparent)}
.sqm-lock{flex:0 0 38px;height:38px;border-radius:50%;display:grid;place-items:center;background:var(--sq-acc);color:#fff;font-size:15px}
.sqm-confirm-body{flex:1;min-width:0}
.sqm-confirm-title{font-size:14px;font-weight:800;color:var(--light,#0B1F3A)}
.sqm-confirm-sub{font-size:12px;color:var(--muted,#5C6B8A);margin:1px 0 6px}
.sqm-modal label{display:flex;flex-direction:column;gap:5px;font-size:12px;font-weight:700;color:var(--muted,#405A76)}
.sqm-modal input[type=text],.sqm-modal input[type=password],.sqm-modal select{width:100%;height:38px;padding:0 12px;border:1px solid var(--sq-line);border-radius:10px;font:500 14px 'DM Sans',system-ui,sans-serif;color:var(--light,#0B1F3A);background:var(--inner,#fff)}
.sqm-modal select{appearance:none;-webkit-appearance:none;cursor:pointer;padding-right:34px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' fill='none' stroke='%2367819E' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center}
.sqm-modal input:focus,.sqm-modal select:focus{outline:2px solid color-mix(in srgb,var(--sq-acc) 35%,transparent);border-color:var(--sq-acc)}
.sqm-modal select.is-empty{color:var(--sq-acc);font-weight:700}
.sqm-modal select option{background:var(--mid,#fff);color:var(--light,#0B1F3A);font-weight:500}
.sqm-modal select option[value=""]{color:var(--sq-acc);font-weight:700}
.sqm-modal select option:disabled{color:#8A97A8}
.sqm-pw{position:relative}
.sqm-pw input{padding-right:44px !important}
.sqm-eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);border:0;background:none;color:var(--muted,#67819E);cursor:pointer;padding:8px;line-height:1;display:flex;align-items:center;justify-content:center}
.sqm-eye:hover{color:var(--sq-acc)}
.sqm-q{display:flex;gap:12px;align-items:flex-start;margin:0 0 8px}
.sqm-num{flex:0 0 auto;width:28px;height:28px;margin-top:5px;border-radius:50%;display:grid;place-items:center;background:color-mix(in srgb,var(--sq-acc) 14%,transparent);color:var(--sq-acc);font-weight:800;font-size:12.5px}
.sqm-q-fields{flex:1;min-width:0;display:grid;gap:6px}
.sqm-chosen{font-size:12.5px;color:var(--muted,#405A76);line-height:1.4;padding:0 2px}
.sqm-chosen,.sqm-chosen:empty{display:none!important}
.sqm-hint{margin-top:4px;padding:9px 13px;border-radius:10px;font-size:12.5px;line-height:1.45;border:1px solid var(--sq-line);background:var(--inner,#F7FAFF);color:var(--muted,#405A76)}
.sqm-hint b{display:block;font-size:13.5px;color:var(--light,#0B1F3A)}
#sqmGate[hidden],#sqmQsec[hidden]{display:none!important}
.sqm-verify-row{display:flex;align-items:center;gap:12px;margin-top:10px;flex-wrap:wrap}
.sqm-verified{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;color:#10B981}
.sqm-shake{animation:sqmShake .35s ease}
@keyframes sqmShake{0%,100%{transform:translateX(0)}25%{transform:translateX(-6px)}75%{transform:translateX(6px)}}
@media (max-height:720px){.sqm-modal{padding:16px 20px}.sqm-head{top:-16px;margin:-16px -20px 0;padding:16px 20px 4px}.sqm-sec{margin-top:8px}.sqm-modal input[type=text],.sqm-modal input[type=password],.sqm-modal select{height:34px}.sqm-q{margin-bottom:6px}.sqm-q-fields{gap:4px}.sqm-num{margin-top:3px}.sqm-confirm{padding:8px 12px}.sqm-hint{padding:6px 12px}}
</style>
<div class="sqm-overlay" id="sqmOverlay" hidden>
  <div class="sqm-modal" id="sqmModal" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="sqmTitle">
    <form id="sqmForm" autocomplete="off" novalidate>
      <input type="hidden" name="sq_ajax" value="1">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
      <div class="sqm-head">
        <div class="sqm-head-row">
          <div class="sqm-head-text">
            <h3 id="sqmTitle"><?= $sqIsNew ? 'Set security questions' : 'Update security questions' ?></h3>
            <p class="sqm-text">Used to recover your account if you forget your password. Choose 3 different questions.</p>
          </div>
          <div class="sqm-head-actions">
            <button type="button" class="sqm-btn" id="sqmCancel">Cancel</button>
            <button type="submit" class="sqm-btn primary" id="sqmSave" disabled>Save questions</button>
          </div>
        </div>
        <div class="sqm-discard" id="sqmDiscard" hidden>
          <span>You have unsaved changes. Discard them?</span>
          <button type="button" class="sqm-btn" id="sqmKeep">Keep editing</button>
          <button type="button" class="sqm-btn danger" id="sqmDiscardGo">Discard</button>
        </div>
        <div class="sqm-error" id="sqmError" role="alert" hidden></div>
      </div>

      <div class="sqm-sec" id="sqmGate">
        <div class="sqm-sec-title">Confirm it&rsquo;s you</div>
        <div class="sqm-confirm">
          <span class="sqm-lock"><i class="fa-solid fa-lock"></i></span>
          <div class="sqm-confirm-body">
            <div class="sqm-confirm-title">Enter your current password first</div>
            <div class="sqm-confirm-sub">Confirm your password to unlock your questions.</div>
            <label>Current password
              <span class="sqm-pw">
                <input type="password" id="sqmPw" name="current_password" autocomplete="current-password" placeholder="Enter your current password">
                <button type="button" class="sqm-eye" id="sqmEye" aria-label="Show password" title="Show password"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.9 10.9 0 0 1 12 19C5.5 19 2 12 2 12a18.6 18.6 0 0 1 5.06-5.94"/><path d="M9.9 4.24A10.9 10.9 0 0 1 12 4c6.5 0 10 8 10 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/><path d="M1 1l22 22"/></svg></button>
              </span>
            </label>
            <div class="sqm-verify-row">
              <button type="button" class="sqm-btn primary" id="sqmVerify" disabled>Continue</button>
              <span class="sqm-verified" id="sqmVerified" hidden>&#10003; Password confirmed</span>
            </div>
          </div>
        </div>
      </div>

      <div class="sqm-sec" id="sqmQsec" hidden>
        <div class="sqm-sec-title">Your questions</div>
        <?php for ($n = 1; $n <= 3; $n++): ?>
        <div class="sqm-q">
          <span class="sqm-num"><?= $n ?></span>
          <div class="sqm-q-fields">
            <?php $sqCur = (string)($sq_existing[$n]['question_key'] ?? ''); if (!isset($sqQuestionList[$sqCur])) $sqCur = ''; ?>
            <select name="sq_question[<?= $n ?>]" id="sqmQ<?= $n ?>" class="<?= $sqCur === '' ? 'is-empty' : '' ?>" data-orig="<?= htmlspecialchars($sqCur) ?>" aria-label="Question <?= $n ?>">
              <option value="">Choose a question</option>
              <?php foreach ($sqQuestionList as $k => $q): ?><option value="<?= htmlspecialchars((string)$k) ?>"<?= ((string)$k === $sqCur) ? ' selected' : '' ?>><?= htmlspecialchars($q) ?></option><?php endforeach; ?>
            </select>
            <div class="sqm-chosen" id="sqmChosen<?= $n ?>" aria-live="polite"></div>
            <input type="text" name="sq_answer[<?= $n ?>]" id="sqmA<?= $n ?>" maxlength="100" placeholder="Your answer" autocomplete="off" aria-label="Answer <?= $n ?>">
          </div>
        </div>
        <?php endfor; ?>
        <div class="sqm-hint"><b><?= $sqIsNew ? 'Pick a question and give an answer only you would know.' : 'Your current questions are shown. Change just one if you like, and leave the other answers as they are to keep them.' ?></b>For your security, existing answers are never shown.</div>
      </div>
    </form>
  </div>
</div>
<script>
(function(){
  var ov=document.getElementById('sqmOverlay'), md=document.getElementById('sqmModal'), form=document.getElementById('sqmForm');
  var openBtn=document.getElementById('sqOpen'); if(!ov||!form||!openBtn) return;
  var save=document.getElementById('sqmSave'), err=document.getElementById('sqmError'), disc=document.getElementById('sqmDiscard');
  var pw=document.getElementById('sqmPw'), eye=document.getElementById('sqmEye');
  var qs=[1,2,3].map(function(n){return document.getElementById('sqmQ'+n);}), as=[1,2,3].map(function(n){return document.getElementById('sqmA'+n);});
  var busy=false, verified=false;
  function resetGate(){
    verified=false; pw.readOnly=false;
    document.getElementById('sqmGate').hidden=false;
    document.getElementById('sqmQsec').hidden=true;
    document.getElementById('sqmVerified').hidden=true;
    document.getElementById('sqmVerify').hidden=false;
  }
  var SQM_EYE='<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  var SQM_SLASH='<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.9 10.9 0 0 1 12 19C5.5 19 2 12 2 12a18.6 18.6 0 0 1 5.06-5.94"/><path d="M9.9 4.24A10.9 10.9 0 0 1 12 4c6.5 0 10 8 10 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/><path d="M1 1l22 22"/></svg>';
  var orig=qs.map(function(s){return s.getAttribute('data-orig')||'';});
  function changedAt(i){ return qs[i].value!==orig[i] || as[i].value.trim()!==''; }
  function dirty(){ return (!verified && !!pw.value) || [0,1,2].some(changedAt); }
  function ready(){
    if(!verified || pw.value==='') return false;
    if(!qs.every(function(s){return s.value;})) return false;
    if(![0,1,2].some(changedAt)) return false;
    /* an answer is only optional for a question that is unchanged (its saved answer is kept) */
    return [0,1,2].every(function(i){ return as[i].value.trim()!=='' || (orig[i]!=='' && qs[i].value===orig[i]); });
  }
  function sync(){
    var chosen=qs.map(function(s){return s.value;}).filter(Boolean);
    qs.forEach(function(s,i){
      Array.prototype.forEach.call(s.options,function(o){ o.disabled=o.value!==''&&chosen.indexOf(o.value)!==-1&&o.value!==s.value; });
      s.classList.toggle('is-empty',!s.value);
      document.getElementById('sqmChosen'+(i+1)).textContent=s.value?s.options[s.selectedIndex].text:'';
    });
    as.forEach(function(a,i){ a.placeholder=(orig[i]!==''&&qs[i].value===orig[i])?'\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022':'Your answer'; });
    save.disabled=busy||!ready();
    document.getElementById('sqmVerify').disabled=busy||verified||pw.value==='';
  }
  function showErr(m){ err.textContent=m; err.hidden=!m; }
  function open(){
    form.reset(); resetGate(); showErr(''); disc.hidden=true; pw.type='password';
    eye.innerHTML=SQM_SLASH; eye.setAttribute('aria-label','Show password');
    sync(); ov.hidden=false; md.scrollTop=0;
    setTimeout(function(){ pw.focus({preventScroll:true}); },30);
  }
  function close(){ ov.hidden=true; openBtn.focus(); }
  function requestClose(){
    if(busy) return;
    if(dirty()){ disc.hidden=false; md.scrollTop=0; md.classList.remove('sqm-shake'); void md.offsetWidth; md.classList.add('sqm-shake'); }
    else close();
  }
  openBtn.addEventListener('click',open);
  document.getElementById('sqmCancel').addEventListener('click',requestClose);
  document.getElementById('sqmKeep').addEventListener('click',function(){ disc.hidden=true; });
  document.getElementById('sqmDiscardGo').addEventListener('click',close);
  ov.addEventListener('mousedown',function(e){ if(e.target===ov) requestClose(); });
  document.addEventListener('keydown',function(e){ if(!ov.hidden&&e.key==='Escape'){ e.preventDefault(); requestClose(); } });
  form.addEventListener('input',sync); form.addEventListener('change',sync);
  eye.addEventListener('click',function(){
    var show=pw.type==='password'; pw.type=show?'text':'password';
    eye.innerHTML=show?SQM_EYE:SQM_SLASH;
    eye.setAttribute('aria-label',show?'Hide password':'Show password');
  });
  /* Step 1: the password must be confirmed on the server before the questions appear. */
  function verifyPw(){
    var vb=document.getElementById('sqmVerify');
    if(busy||verified||pw.value==='') return;
    busy=true; vb.disabled=true; vb.textContent='Checking\u2026'; showErr('');
    var fd=new FormData(form); fd.append('sq_verify','1');
    fetch('faculty_dashboard.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){ return r.json(); })
      .then(function(d){
        if(d&&d.ok){
          verified=true; pw.readOnly=true;
          document.getElementById('sqmGate').hidden=true;
          document.getElementById('sqmQsec').hidden=false;
          document.getElementById('sqmVerified').hidden=false;
          vb.hidden=true;
          setTimeout(function(){ qs[0].focus({preventScroll:true}); },30);
        } else { showErr((d&&d.message)||'Your current password is not correct.'); pw.select(); }
      })
      .catch(function(){ showErr('Could not reach the server. Please try again.'); })
      .then(function(){ busy=false; vb.textContent='Continue'; sync(); });
  }
  document.getElementById('sqmVerify').addEventListener('click',verifyPw);
  pw.addEventListener('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); verifyPw(); } });
  form.addEventListener('submit',function(e){
    e.preventDefault(); if(busy||!ready()) return;
    busy=true; save.disabled=true; save.textContent='Saving\u2026'; showErr('');
    fetch('faculty_dashboard.php',{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){ return r.json(); })
      .then(function(d){
        if(d&&d.ok){ location.href='faculty_dashboard.php?page=settings'; return; }
        showErr((d&&d.message)||'The security questions could not be saved. Please try again.'); md.scrollTop=0;
      })
      .catch(function(){ showErr('Could not reach the server. Please try again.'); })
      .then(function(){ busy=false; save.textContent='Save questions'; sync(); });
  });
})();
</script>

    <div class="settings-layout-grid settings-tab-panel" id="settingsPanelAppearance" role="tabpanel" aria-labelledby="settingsTabAppearance" hidden>
        <section class="settings-card full ac-card ac-c1" id="principal-style-appearance">
            <div class="ac-head appearance-heading-row">
                <span class="ac-icon"><i class="fa-solid fa-palette"></i></span>
                <div><h2>Appearance</h2><p class="ac-sub">Choose your theme, content spacing, and motion preferences.</p></div>
                <span class="appearance-device-badge"><i class="fa-solid fa-laptop"></i> Saved on this device</span>
            </div>
            <div class="appearance-feature-grid">
                <section class="appearance-control-card appearance-theme-card" aria-labelledby="appearanceThemeHeading">
                    <div class="appearance-control-head">
                        <span class="appearance-control-icon"><i class="fa-solid fa-circle-half-stroke"></i></span>
                        <div><h3 id="appearanceThemeHeading">Theme mode</h3><p>Choose a look that suits your environment.</p></div>
                    </div>
                    <div class="appearance-theme-options" role="group" aria-label="Theme mode">
                        <button type="button" class="appearance-choice" id="appearanceLightBtn" data-theme-choice="light" aria-pressed="false" onclick="setAppearance('light')"><span class="appearance-choice-icon"><i class="fa-solid fa-sun"></i></span><span class="appearance-choice-copy"><strong>Light</strong><small>Bright and clean</small></span><span class="appearance-choice-check"><i class="fa-solid fa-check"></i></span></button>
                        <button type="button" class="appearance-choice" id="appearanceDarkBtn" data-theme-choice="dark" aria-pressed="false" onclick="setAppearance('dark')"><span class="appearance-choice-icon"><i class="fa-solid fa-moon"></i></span><span class="appearance-choice-copy"><strong>Dark</strong><small>Low-light friendly</small></span><span class="appearance-choice-check"><i class="fa-solid fa-check"></i></span></button>
                        <button type="button" class="appearance-choice" id="appearanceSystemBtn" data-theme-choice="system" aria-pressed="false" onclick="setAppearance('system')"><span class="appearance-choice-icon"><i class="fa-solid fa-desktop"></i></span><span class="appearance-choice-copy"><strong>System</strong><small>Follow device setting</small></span><span class="appearance-choice-check"><i class="fa-solid fa-check"></i></span></button>
                    </div>
                    <div class="appearance-inline-status"><i class="fa-solid fa-circle-info"></i> Current mode: <strong id="appearanceCurrentMode">Light</strong></div>
                </section>
                <section class="appearance-control-card" aria-labelledby="appearanceLayoutHeading">
                    <div class="appearance-control-head"><span class="appearance-control-icon"><i class="fa-solid fa-sliders"></i></span><div><h3 id="appearanceLayoutHeading">Interface spacing</h3><p>Choose how much space appears around tables and content.</p></div></div>
                    <div class="appearance-density-options" role="group" aria-label="Interface spacing">
                        <button type="button" class="appearance-density-choice" data-density-choice="comfortable" aria-pressed="false" onclick="setPrincipalDensity('comfortable')"><i class="fa-solid fa-expand"></i><span><strong>Comfortable</strong><small>More breathing room</small></span></button>
                        <button type="button" class="appearance-density-choice" data-density-choice="compact" aria-pressed="false" onclick="setPrincipalDensity('compact')"><i class="fa-solid fa-compress"></i><span><strong>Compact</strong><small>More content on screen</small></span></button>
                    </div>
                </section>
                <section class="appearance-control-card appearance-accessibility-card" aria-labelledby="appearanceAccessibilityHeading">
                    <div class="appearance-control-head"><span class="appearance-control-icon"><i class="fa-solid fa-universal-access"></i></span><div><h3 id="appearanceAccessibilityHeading">Accessibility</h3><p>Reduce interface movement for a calmer experience.</p></div></div>
                    <button type="button" class="appearance-motion-toggle" id="appearanceMotionBtn" aria-pressed="false" onclick="togglePrincipalReducedMotion()"><span class="appearance-motion-copy"><strong>Reduce animations</strong><small>Minimize transitions and motion effects</small></span><span class="appearance-switch-track" aria-hidden="true"><span class="appearance-switch-thumb"></span></span></button>
                    <div class="appearance-accessibility-note"><i class="fa-solid fa-shield-heart"></i> Your settings stay in this browser and do not change other users' views.</div>
                </section>
            </div>
        </section>
    </div>

    <script>
    (function(){
        var tabs = document.querySelectorAll('[data-settings-tab]');
        var panels = {profile: document.getElementById('settingsPanelProfile'), appearance: document.getElementById('settingsPanelAppearance')};
        if (!tabs.length || !panels.profile || !panels.appearance) return;
        function show(name, updateHash) {
            if (!panels[name]) name = 'profile';
            Object.keys(panels).forEach(function(k){ panels[k].hidden = (k !== name); });
            tabs.forEach(function(t){
                var on = t.getAttribute('data-settings-tab') === name;
                t.classList.toggle('active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
                t.tabIndex = on ? 0 : -1;
            });
            if (updateHash && window.history && history.replaceState) history.replaceState(null, '', name === 'profile' ? location.pathname + location.search : '#appearance');
        }
        tabs.forEach(function(t, i){
            t.addEventListener('click', function(){ show(t.getAttribute('data-settings-tab'), true); });
            t.addEventListener('keydown', function(e){
                if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
                var n = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
                n.focus(); show(n.getAttribute('data-settings-tab'), true);
            });
        });
        show(location.hash === '#appearance' ? 'appearance' : 'profile', false);
    })();
    </script>
</div>

<?php elseif ($page === 'my_results'): ?>

<?php render_received_results($mysqli, (int)$user_id); ?>

<!-- ══════════ EVALUATION — STEP 1: CHOOSE GROUP ══════════ -->
<?php elseif ($page === 'peer' && $peer_group === null): ?>

<?php
// Counts mirror the same shared-predicate + Non-Teaching-Staff grouping
// used to build the Step 2 lists below and admin/questionnaire.php's own
// Peer-to-Peer cards. The currently logged-in faculty account has already
// been excluded from $peers_all above.
$faculty_peer_targets = array_values(array_filter($peers_all, fn($p) => resolve_peer_group($mysqli, $p) === 'teacher'));
$teacher_count = count($faculty_peer_targets);
$staff_count = count(array_filter($peers_all, fn($p) => resolve_peer_group($mysqli, $p) === 'staff'));
$teacher_done_count = count(array_intersect(array_map('intval', array_column($faculty_peer_targets, 'id')), $done_college_peers));
$staff_done_count = count(array_intersect(array_map('intval', array_column(array_values(array_filter($peers_all, fn($p) => resolve_peer_group($mysqli, $p) === 'staff')), 'id')), $done_peers));
$principal_done_count = count(array_intersect(array_map('intval', array_column($principal_targets, 'id')), $done_school_heads));
$dean_done_count = count(array_intersect(array_map('intval', array_column($dean_targets, 'id')), $done_school_heads));
?>

<div class="section-card peer-box">

<?php if (!$evaluation_open): ?>
<div class="no-period-warn"><i class="fa-solid fa-clock"></i> Evaluation is currently closed until the scheduled opening time.</div>
<?php endif; ?>

    <h2 class="cat-heading"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i> Your Evaluation</h2>
    <p class="cat-lead">Choose a group, then evaluate each member. Your progress for the active period is shown on every card.</p>
    <div class="eval-group-grid peer-category-grid cat-select">
        <?php if ($can_evaluate_faculty_peer): ?>
        <a href="faculty_dashboard.php?page=peer&group=teacher#roster" class="eval-group-card">
            <div class="eval-group-icon blue"><i class="fa-solid fa-chalkboard-user"></i></div>
            <div class="eval-group-title">Faculty</div>
            <div class="eval-group-count"><?= $teacher_count ?> member<?= $teacher_count == 1 ? '' : 's' ?></div>
            <?= fx_group_progress((int)$teacher_count, (int)$teacher_done_count) ?>
        </a>
        <?php endif; ?>
        <a href="faculty_dashboard.php?page=peer&group=staff#roster" class="eval-group-card">
            <div class="eval-group-icon green"><i class="fa-solid fa-briefcase"></i></div>
            <div class="eval-group-title">Staff</div>
            <div class="eval-group-count"><?= $staff_count ?> member<?= $staff_count == 1 ? '' : 's' ?></div>
            <?= fx_group_progress((int)$staff_count, (int)$staff_done_count) ?>
        </a>
        <?php if ($can_evaluate_principal): ?>
        <a href="faculty_dashboard.php?page=peer&group=principal#roster" class="eval-group-card">
            <div class="eval-group-icon purple"><i class="fa-solid fa-user-tie"></i></div>
            <div class="eval-group-title">Principal</div>
            <div class="eval-group-count"><?= count($principal_targets) ?> member<?= count($principal_targets) == 1 ? '' : 's' ?></div>
            <?= fx_group_progress(count($principal_targets), (int)$principal_done_count) ?>
        </a>
        <?php endif; ?>
        <?php if ($can_evaluate_dean): ?>
        <a href="faculty_dashboard.php?page=peer&group=dean#roster" class="eval-group-card">
            <div class="eval-group-icon gold"><i class="fa-solid fa-graduation-cap"></i></div>
            <div class="eval-group-title">Dean</div>
            <div class="eval-group-count"><?= count($dean_targets) ?> member<?= count($dean_targets) == 1 ? '' : 's' ?></div>
            <?= fx_group_progress(count($dean_targets), (int)$dean_done_count) ?>
        </a>
        <?php endif; ?>
    </div>
    <?php if ($can_evaluate_principal && $can_evaluate_dean): ?>
   
    <?php elseif ($can_evaluate_principal): ?>
   
    <?php elseif ($can_evaluate_dean): ?>
    <div class="peer-select-hint"><i class="fa-solid fa-circle-info"></i> Your College teaching assignment makes you eligible to evaluate the Dean. Principal evaluation is only for those with a JHS/SHS teaching assignment.</div>
    <?php else: ?>
    <div class="peer-select-hint"><i class="fa-solid fa-circle-info"></i> Dean/Principal evaluation isn't available yet — it opens once your teaching assignment (Role &amp; Designation) is set.</div>
    <?php endif; ?>
</div>

<!-- ══════════ EVALUATION — STEP 2: TARGET LIST ══════════ -->
<?php elseif ($page === 'peer' && $peer_group !== null): ?>

<?php
$peer_teacher_targets = array_values(array_filter($peers_all, fn($p) => resolve_peer_group($mysqli, $p) === 'teacher'));
$peer_staff_targets = array_values(array_filter($peers_all, fn($p) => resolve_peer_group($mysqli, $p) === 'staff'));
$peer_teacher_done = count(array_intersect(array_map('intval', array_column($peer_teacher_targets, 'id')), $done_college_peers));
$peer_staff_done = count(array_intersect(array_map('intval', array_column($peer_staff_targets, 'id')), $done_peers));
$peer_principal_done = count(array_intersect(array_map('intval', array_column($principal_targets, 'id')), $done_school_heads));
$peer_dean_done = count(array_intersect(array_map('intval', array_column($dean_targets, 'id')), $done_school_heads));
?>
<div class="section-card peer-shell peer-box">
    <h2 class="cat-heading"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i> Your Evaluation</h2>
    <p class="cat-lead">Choose a group, then evaluate each member. Your progress for the active period is shown on every card.</p>
    <div class="eval-group-grid peer-category-grid cat-select">
        <?php if ($can_evaluate_faculty_peer): ?><a href="faculty_dashboard.php?page=peer<?= $peer_group === 'teacher' ? '' : '&group=teacher#roster' ?>" class="eval-group-card <?= $peer_group === 'teacher' ? 'is-selected' : '' ?>" <?= $peer_group === 'teacher' ? 'aria-current="true"' : '' ?>><div class="eval-group-icon blue"><i class="fa-solid fa-chalkboard-user"></i></div><div class="eval-group-title">Faculty</div><div class="eval-group-count"><?= count($peer_teacher_targets) ?> member<?= count($peer_teacher_targets) === 1 ? '' : 's' ?></div><?= fx_group_progress(count($peer_teacher_targets), (int)$peer_teacher_done) ?></a><?php endif; ?>
        <a href="faculty_dashboard.php?page=peer<?= $peer_group === 'staff' ? '' : '&group=staff#roster' ?>" class="eval-group-card <?= $peer_group === 'staff' ? 'is-selected' : '' ?>" <?= $peer_group === 'staff' ? 'aria-current="true"' : '' ?>><div class="eval-group-icon green"><i class="fa-solid fa-briefcase"></i></div><div class="eval-group-title">Staff</div><div class="eval-group-count"><?= count($peer_staff_targets) ?> member<?= count($peer_staff_targets) === 1 ? '' : 's' ?></div><?= fx_group_progress(count($peer_staff_targets), (int)$peer_staff_done) ?></a>
        <?php if ($can_evaluate_principal): ?><a href="faculty_dashboard.php?page=peer<?= $peer_group === 'principal' ? '' : '&group=principal#roster' ?>" class="eval-group-card <?= $peer_group === 'principal' ? 'is-selected' : '' ?>" <?= $peer_group === 'principal' ? 'aria-current="true"' : '' ?>><div class="eval-group-icon purple"><i class="fa-solid fa-user-tie"></i></div><div class="eval-group-title">Principal</div><div class="eval-group-count"><?= count($principal_targets) ?> <?= count($principal_targets) === 1 ? 'member' : 'members' ?></div><?= fx_group_progress(count($principal_targets), (int)$peer_principal_done) ?></a><?php endif; ?>
        <?php if ($can_evaluate_dean): ?><a href="faculty_dashboard.php?page=peer<?= $peer_group === 'dean' ? '' : '&group=dean#roster' ?>" class="eval-group-card <?= $peer_group === 'dean' ? 'is-selected' : '' ?>" <?= $peer_group === 'dean' ? 'aria-current="true"' : '' ?>><div class="eval-group-icon gold"><i class="fa-solid fa-graduation-cap"></i></div><div class="eval-group-title">Dean</div><div class="eval-group-count"><?= count($dean_targets) ?> <?= count($dean_targets) === 1 ? 'member' : 'members' ?></div><?= fx_group_progress(count($dean_targets), (int)$peer_dean_done) ?></a><?php endif; ?>
    </div>

<?php if (!$evaluation_open): ?>
<div class="no-period-warn"><i class="fa-solid fa-clock"></i> Evaluation is currently closed until the scheduled opening time.</div>
<?php endif; ?>

<?php
$roster_icons = ['teacher'=>'fa-chalkboard-user','staff'=>'fa-briefcase','principal'=>'fa-user-tie','dean'=>'fa-graduation-cap'];
$roster_icon  = $roster_icons[$peer_group] ?? 'fa-users';
$is_head_group = in_array($peer_group, $school_head_groups, true);
$sh_role_eligible = $peer_group === 'principal' ? $can_evaluate_principal : ($peer_group === 'dean' ? $can_evaluate_dean : true);
?>
<?php
$done_in_group = $is_head_group
    ? count(array_intersect(array_map('intval', array_column($peers, 'id')), $done_school_heads))
    : count(array_intersect(array_map('intval', array_column($peers, 'id')), $done_peers));
$displayPeerPeriod = ($peer_group === 'teacher' && $college_peer_period) ? $college_peer_period : $period;
?>
<section class="roster-panel" id="roster">
    <div class="roster-head">
        <div class="roster-heading">
            <div class="roster-icon"><i class="fa-solid <?= $roster_icon ?>"></i></div>
            <h2><?= htmlspecialchars($peer_group_labels[$peer_group] ?? ucfirst($peer_group)) ?></h2>
        </div>
        <div class="roster-stats">
            <span><strong><?= count($peers) ?></strong> <?= count($peers) === 1 ? 'member' : 'members' ?></span>
            <span><strong><?= $done_in_group ?></strong> completed</span>
            <span><strong><?= max(0, count($peers) - $done_in_group) ?></strong> pending</span>
            <span class="period"><i class="fa-regular fa-calendar"></i> <?= htmlspecialchars($displayPeerPeriod['period_label'] ?? $displayPeerPeriod['semester'] ?? 'Current Period') ?></span>
        </div>
    </div>
    <?php if ($is_head_group && !$sh_role_eligible): ?>
    <div class="roster-empty"><i class="fa-solid fa-lock"></i>
        Evaluating the <?= htmlspecialchars($peer_group_labels[$peer_group]) ?> is only available to Faculty/Teaching Staff with a <?= $peer_group === 'principal' ? 'Grade 7-12' : 'College' ?> teaching assignment.
    </div>
    <?php elseif (empty($peers)): ?>
    <div class="roster-empty"><i class="fa-solid fa-user-slash"></i>
        <?= $is_head_group ? 'No ' . htmlspecialchars($peer_group_labels[$peer_group]) . ' account was found.' : 'No registered ' . htmlspecialchars(strtolower($peer_group_labels[$peer_group])) . ' members found.' ?>
    </div>
    <?php else: ?>
    <div class="person-grid">
        <?php foreach ($peers as $p):
            $done = $is_head_group
                ? in_array((int)$p['id'], $done_school_heads, true)
                : in_array((int)$p['id'], $done_peers, true);
            $peer_label = peer_display_label($mysqli, $p, $peer_group, $peer_group_labels);
        ?>
        <article class="person-card <?= $done ? 'is-done' : 'is-pending' ?>">
            <div class="person-photo">
                <?php if (!empty($p['photo'])): ?><img src="<?= UPLOAD_URL . htmlspecialchars($p['photo']) ?>" alt="" onerror="this.parentNode.innerHTML='<i class=&quot;fa-solid fa-user&quot;></i>'" loading="lazy"/><?php else: ?><i class="fa-solid fa-user"></i><?php endif; ?>
            </div>
            <div class="person-info">
                <div class="person-name" title="<?= htmlspecialchars($p['full_name']) ?>"><?= htmlspecialchars($p['full_name']) ?></div>
                <div class="person-meta" title="<?= htmlspecialchars($peer_label) ?>"><?= htmlspecialchars($peer_label) ?></div>
                <span class="status-pill <?= $done ? 'done' : 'pending' ?>"><i class="fa-solid <?= $done ? 'fa-check' : 'fa-hourglass-half' ?>"></i> <?= $done ? 'Completed' : 'Pending' ?></span>
            </div>
            <div class="person-action">
                <?php if ($done): ?>
                <span class="btn-action view is-disabled"><i class="fa-solid fa-check"></i> Evaluated</span>
                <?php elseif (!$evaluation_open): ?>
                <span class="btn-action evaluate is-disabled" aria-disabled="true"><i class="fa-solid fa-clock"></i> Scheduled</span>
                <?php else: ?>
                <a class="btn-action evaluate" href="faculty_dashboard.php?page=peer_eval&tid=<?= (int)$p['id'] ?>&group=<?= urlencode($peer_group) ?>"><i class="fa-solid fa-pen"></i> Evaluate</a>
                <?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
</div>

<?php elseif ($page === 'peer_eval' && $peer_target): ?>

<div class="peer-eval-overlay">
  <section class="peer-eval-dialog" role="dialog" aria-modal="true" aria-labelledby="peerEvalTitle">
    <header class="peer-eval-dialog-header">
      <div class="peer-eval-target">
        <?php if ($peer_target['photo']): ?>
          <img class="peer-eval-avatar" src="<?= UPLOAD_URL . htmlspecialchars($peer_target['photo']) ?>" alt=""/>
        <?php else: ?>
          <div class="peer-eval-avatar peer-eval-avatar-placeholder"><i class="fa-solid fa-user"></i></div>
        <?php endif; ?>
        <div class="peer-eval-target-copy">
          <h2 id="peerEvalTitle"><?= htmlspecialchars($peer_target['full_name']) ?></h2>
          <p><?= htmlspecialchars(peer_display_label($mysqli, $peer_target, $peer_eval_group ?? 'teacher', $peer_group_labels)) ?></p>
        </div>
      </div>
      <a class="peer-eval-close" href="faculty_dashboard.php?page=peer&group=<?= urlencode($peer_eval_group) ?>" aria-label="Close evaluation"><i class="fa-solid fa-xmark"></i></a>
    </header>

    <?php if (empty($peer_questions)): ?>
      <div class="peer-eval-empty"><i class="fa-solid fa-triangle-exclamation"></i> No evaluation questions have been set up yet. Please contact the admin.</div>
      <footer class="peer-eval-footer"><a class="btn-cancel-new" href="faculty_dashboard.php?page=peer&group=<?= urlencode($peer_eval_group) ?>">Back to Evaluate Others</a></footer>
    <?php else: ?>
      <form class="peer-eval-form" method="POST" action="faculty_dashboard.php?page=peer_eval&tid=<?= $peer_target['id'] ?>&group=<?= urlencode($peer_eval_group) ?>" id="evalForm" novalidate>
        <div class="peer-eval-scroll" id="peerEvalScroll">
          <div class="eval-validation-alert" id="facultyEvalValidation" role="alert" aria-live="assertive"></div>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
          <input type="hidden" name="submit_peer" value="1"/>
          <input type="hidden" name="target_id" value="<?= $peer_target['id'] ?>"/>
          <input type="hidden" name="form_id" value="<?= $peer_form_id ?>"/>
          <input type="hidden" name="period_id" value="<?= $period['id'] ?? 0 ?>"/>
          <input type="hidden" name="group" value="<?= htmlspecialchars($peer_eval_group) ?>"/>

          <div class="peer-eval-scale" aria-label="Rating scale">
            <?php foreach ([5=>'Always',4=>'Often',3=>'Sometimes',2=>'Rarely',1=>'Never'] as $num=>$lbl): ?>
              <div class="peer-eval-scale-item"><span><?= $num ?></span><em><?= $lbl ?></em></div>
            <?php endforeach; ?>
          </div>

          <?php
          $grouped_qs = [];
          foreach ($peer_questions as $q) $grouped_qs[$q['category'] ?? 'General'][] = $q;
          $cat_icons = ['Work Performance'=>'fa-briefcase','Professional Ethics'=>'fa-scale-balanced','Interpersonal Skills'=>'fa-handshake','Teaching'=>'fa-chalkboard-user','Communication'=>'fa-comments','General'=>'fa-layer-group'];
          $qno = 1;
          foreach ($grouped_qs as $cat => $qs):
            $icon = $cat_icons[$cat] ?? 'fa-layer-group';
          ?>
            <section class="peer-eval-question-section">
              <h3 class="q-category-header"><i class="fa-solid <?= $icon ?>"></i><?= htmlspecialchars($cat) ?></h3>
              <div class="eval-form-wrap">
                <table class="eval-form-table">
                  <colgroup><col><col class="c-rate"><col class="c-rate"><col class="c-rate"><col class="c-rate"><col class="c-rate"></colgroup>
                  <tbody>
                    <?php foreach ($qs as $q): ?>
                      <tr class="eval-question-row">
                        <td><div class="eval-form-qtext"><span class="eval-form-qno"><?= $qno++ ?>.</span><?= htmlspecialchars($q['question_text']) ?><?= !empty($q['is_required']) ? ' *' : '' ?></div></td>
                        <?php foreach ([5,4,3,2,1] as $v): $optId = 'r_' . $q['id'] . '_' . $v; ?>
                          <td><div class="eval-form-rating"><input type="radio" name="ratings[<?= $q['id'] ?>]" id="<?= $optId ?>" value="<?= $v ?>"><label for="<?= $optId ?>"><?= $v ?></label></div></td>
                        <?php endforeach; ?>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </section>
          <?php endforeach; ?>

          <div class="comment-box-new">
            <div class="comment-box-inner">
              <div class="comment-box-label"><i class="fa-solid fa-comment-dots comment-box-icon"></i> Comments &amp; Suggestions <span class="comment-req" title="Required">*</span></div>
              <textarea name="comment" class="comment-textarea-new" required aria-required="true" placeholder="Share your thoughts, suggestions, or concerns about this person's performance."></textarea>
              <div class="comment-error" id="facultyCommentError" role="alert" hidden>Required \u2014 you can't submit this evaluation without a comment.</div>
            </div>
          </div>
        </div>
        <footer class="peer-eval-footer">
          <a class="btn-cancel-new" href="faculty_dashboard.php?page=peer&group=<?= urlencode($peer_eval_group) ?>">Cancel</a>
          <button type="submit" class="btn-submit-new"><i class="fa-solid fa-paper-plane"></i> Submit Evaluation</button>
        </footer>
      </form>
    <?php endif; ?>
  </section>
</div>

<?php elseif ($page === 'peer_eval' && !$peer_target): ?>

<div class="section-card peer-box">
<a href="faculty_dashboard.php?page=peer" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Evaluation Selection</a>

<div style="background:rgba(240,84,84,.08);border:1px solid rgba(240,84,84,.25);border-radius:10px;padding:18px;color:#fca5a5;font-size:13px;display:flex;gap:10px;">
    <i class="fa-solid fa-circle-exclamation"></i>
    <?= htmlspecialchars($peer_group_error ?: "Selected user does not exist, is inactive, or is not a valid Faculty/Staff account. Please choose someone from the list.") ?>
</div>
</div>

<?php endif; ?>

</main>

<script>
function toggleRecentEvals() {
    const list  = document.getElementById('recentEvalsList');
    const caret = document.getElementById('recentEvalsCaret');
    const isOpen = list.style.display !== 'none';
    list.style.display = isOpen ? 'none' : 'block';
    caret.style.transform = isOpen ? '' : 'rotate(180deg)';
}

function toggleAllEvals() {
    const list  = document.getElementById('allEvalsList');
    const caret = document.getElementById('allEvalsCaret');
    const isOpen = list.style.display !== 'none';
    list.style.display = isOpen ? 'none' : 'block';
    caret.style.transform = isOpen ? '' : 'rotate(180deg)';
}

function checkAll() {
    const form = document.getElementById('evalForm');
    if (!form) return false;

    const radios = [...form.querySelectorAll('input[type="radio"][name^="ratings["]')];
    const groups = [...new Set(radios.map(r => r.name))];
    const missing = groups.filter(name => !form.querySelector('input[name="' + CSS.escape(name) + '"]:checked'));
    const alertBox = document.getElementById('facultyEvalValidation');

    form.querySelectorAll('.eval-question-row.eval-unanswered').forEach(row => row.classList.remove('eval-unanswered'));

    if (!groups.length) {
        if (alertBox) {
            alertBox.innerHTML = '<strong>No questions found.</strong> Please close and try again.';
            alertBox.classList.add('show');
            alertBox.scrollIntoView({behavior:'smooth', block:'center'});
        }
        return false;
    }

    if (missing.length) {
        const labels = [];
        missing.forEach(name => {
            const input = form.querySelector('input[name="' + CSS.escape(name) + '"]');
            const row = input ? input.closest('.eval-question-row') : null;
            if (row) {
                row.classList.add('eval-unanswered');
                const q = row.querySelector('.eval-form-qno');
                if (q) labels.push(q.textContent.replace(/[^0-9]/g, ''));
            }
        });

        const plural = missing.length === 1 ? 'question is' : 'questions are';
        const detail = labels.length ? ' Missing: <strong>Q' + labels.join(', Q') + '.</strong>' : '';
        if (alertBox) {
            alertBox.innerHTML = '<strong>Please complete all required questions.</strong> ' + missing.length + ' ' + plural + ' unanswered.' + detail;
            alertBox.classList.add('show');
            alertBox.scrollIntoView({behavior:'smooth', block:'center'});
        }

        const firstRow = form.querySelector('.eval-question-row.eval-unanswered');
        if (firstRow) setTimeout(() => firstRow.scrollIntoView({behavior:'smooth', block:'center'}), 120);
        return false;
    }

    // A written comment is mandatory (whitespace-only doesn't count).
    const commentField = form.querySelector('textarea[name="comment"]');
    if (commentField && commentField.value.trim() === '') {
        if (alertBox) alertBox.classList.remove('show');
        const box = commentField.closest('.comment-box-new');
        const err = document.getElementById('facultyCommentError');
        if (box) { box.classList.add('invalid'); box.scrollIntoView({behavior:'smooth', block:'center'}); }
        if (err) err.hidden = false;
        setTimeout(() => commentField.focus({preventScroll:true}), 120);
        return false;
    }

    if (alertBox) alertBox.classList.remove('show');
    return true;
}

function updateFacultyEvalProgress() {
    const form = document.getElementById('evalForm');
    const counter = document.getElementById('facultyEvalProgress');
    if (!form) return;

    const groups = [...new Set([...form.querySelectorAll('input[type="radio"][name^="ratings["]')].map(r => r.name))];
    const answered = groups.filter(name => form.querySelector('input[name="' + CSS.escape(name) + '"]:checked')).length;
    if (counter) {
        counter.textContent = answered + ' of ' + groups.length + ' questions answered';
        counter.classList.toggle('is-complete', groups.length > 0 && answered === groups.length);
    }

    if (groups.length > 0 && answered === groups.length) {
        document.getElementById('facultyEvalValidation')?.classList.remove('show');
        form.querySelectorAll('.eval-question-row.eval-unanswered').forEach(row => row.classList.remove('eval-unanswered'));
    }
}

const facultyEvalForm = document.getElementById('evalForm');
if (facultyEvalForm) {
    facultyEvalForm.addEventListener('change', function(e) {
        if (!e.target.matches('input[type="radio"][name^="ratings["]')) return;
        const row = e.target.closest('.eval-question-row');
        if (row) row.classList.remove('eval-unanswered');
        updateFacultyEvalProgress();
    });
    facultyEvalForm.addEventListener('input', function(e) {
        if (e.target.matches('textarea[name="comment"]') && e.target.value.trim() !== '') {
            e.target.closest('.comment-box-new')?.classList.remove('invalid');
            const err = document.getElementById('facultyCommentError');
            if (err) err.hidden = true;
        }
    });
    facultyEvalForm.addEventListener('submit', function(e) {
        if (!checkAll()) e.preventDefault();
    });
    updateFacultyEvalProgress();
}
function openEvalDetails(trackerId) {
    const modal = document.getElementById('evalDetailsModal');
    const body  = document.getElementById('evalDetailsBody');
    body.innerHTML = '<div class="eval-modal-loading"><i class="fa-solid fa-spinner fa-spin"></i>Loading evaluation…</div>';
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';

    fetch('get_evaluation_details.php?tracker_id=' + encodeURIComponent(trackerId))
        .then(r => r.json())
        .then(data => {
            body.innerHTML = data.ok
                ? renderEvalDetails(data)
                : '<div class="eval-modal-loading"><i class="fa-solid fa-triangle-exclamation"></i>' + (data.error || 'Unable to load this evaluation.') + '</div>';
        })
        .catch(() => {
            body.innerHTML = '<div class="eval-modal-loading"><i class="fa-solid fa-triangle-exclamation"></i>Something went wrong loading this evaluation.</div>';
        });
}
function closeEvalDetails() {
    document.getElementById('evalDetailsModal').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('evalDetailsModal').addEventListener('click', function(e) {
    if (e.target === this) closeEvalDetails();
});

function starsHtml(score) {
    let h = '';
    for (let i = 1; i <= 5; i++) h += `<i class="fa-solid fa-star eval-q-star ${i <= score ? 'filled' : ''}"></i>`;
    return h;
}

function renderEvalDetails(data) {
    let html = `<div class="eval-info-grid">
        <div class="eval-info-item"><div class="eval-info-label">Evaluator</div><div class="eval-info-value"><i class="fa-solid fa-eye-slash" style="color:var(--muted);margin-right:5px"></i>Anonymous Evaluator</div></div>
        <div class="eval-info-item"><div class="eval-info-label">Period</div><div class="eval-info-value">${escapeHtml(data.period_label || '—')}</div></div>
        <div class="eval-info-item"><div class="eval-info-label">Submitted</div><div class="eval-info-value">${escapeHtml(data.submitted_at)}</div></div>
        <div class="eval-info-item"><div class="eval-info-label">Overall Score</div><div class="eval-info-value" style="color:var(--teal-hover)">${parseFloat(Number(data.overall_score).toFixed(2))} / 5</div></div>
    </div>`;

    if (data.categories && data.categories.length) {
        html += `<div class="section-title" style="font-size:14px;margin-bottom:12px;"><i class="fa-solid fa-layer-group" style="color:var(--teal)"></i> Performance by Category</div>`;
        data.categories.forEach(c => {
            const pct = Math.round((c.avg / 5) * 100);
            const col = c.avg >= 4 ? '#4ade80' : (c.avg >= 3 ? '#facc15' : '#f87171');
            html += `<div class="cat-row">
                <div class="cat-name">${escapeHtml(c.category)}</div>
                <div class="cat-bar-bg"><div class="cat-bar-fill" style="width:${pct}%;background:${col}"></div></div>
                <div class="cat-score" style="color:${col}">${c.avg.toFixed(2)}</div>
            </div>`;
        });
    }

    if (data.questions && data.questions.length) {
        html += `<div class="section-title" style="font-size:14px;margin:20px 0 12px;"><i class="fa-solid fa-list-check" style="color:var(--accent)"></i> Question-by-Question Results</div>`;
        let lastCat = null;
        data.questions.forEach((q, idx) => {
            if (q.category !== lastCat) {
                html += `<div class="q-category-header" style="margin-top:${lastCat===null?'0':'18px'}">${escapeHtml(q.category)}</div>`;
                lastCat = q.category;
            }
            html += `<div class="q-card-new" style="padding:14px 16px;">
                <div class="q-no-new">Question ${idx + 1}</div>
                <div class="q-text-new" style="margin-bottom:8px;">${escapeHtml(q.question_text)}</div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <div>${starsHtml(q.score)}</div>
                    <div style="font-size:12px;color:var(--muted);font-weight:700;">Score: ${q.score} / 5</div>
                </div>
            </div>`;
        });
    }

    html += `<div class="section-title" style="font-size:14px;margin:20px 0 10px;"><i class="fa-solid fa-comment-dots" style="color:var(--teal-hover)"></i> Comments / Feedback</div>`;
    html += data.comment
        ? `<div class="eval-comment-box">"${escapeHtml(data.comment)}"</div>`
        : `<div class="eval-comment-box empty">No written feedback was provided.</div>`;

    return html;
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}
document.addEventListener('click', function(e) {
    const sb = document.getElementById('sidebar');
    if (sb && sb.classList.contains('open') && !sb.contains(e.target) && !e.target.closest('.hamburger')) {
        sb.classList.remove('open');
    }
});

// ── Real-time notification bell ──
let notificationKnownIds = new Set();
let notificationFirstRefresh = true;
let notificationRefreshBusy = false;
const NOTIF_CSRF = <?= json_encode($csrf_token) ?>;

// Mark one notification read, then open the page it points to.
function openNotification(id, href) {
    let done = false;
    const go = () => { if (done) return; done = true; if (href) window.location.href = href; };
    if (!id) { go(); return; }
    const timer = setTimeout(go, 1200); // never let a slow request block navigation
    fetch('notifications_api.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ mark_notification_id: String(id), csrf_token: NOTIF_CSRF })
    }).catch(() => {}).finally(() => { clearTimeout(timer); go(); });
}

// Clicking an unread notification marks it read first; read ones just follow the link.
document.addEventListener('click', function(e) {
    // Every notification row is a link: close the bell, mark it read if needed, then open its page.
    const item = e.target.closest('a.notif-item');
    if (!item) return;
    if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return; // let new-tab clicks behave normally
    const href = item.getAttribute('href');
    if (!href) return;
    e.preventDefault();
    document.getElementById('notifDropdown')?.classList.remove('show');
    if (item.classList.contains('unread')) {
        openNotification(Number(item.dataset.notificationId) || 0, href);
    } else {
        window.location.href = href;
    }
});

function toggleNotifDropdown(e) {
    e.stopPropagation();
    document.getElementById('notifDropdown')?.classList.toggle('show');
    refreshNotifications();
}

document.addEventListener('click', function(e) {
    const wrap = document.getElementById('notifWrap');
    if (wrap && !wrap.contains(e.target)) {
        document.getElementById('notifDropdown')?.classList.remove('show');
    }
});

function escapeNotificationHtml(value) {
    return String(value ?? '').replace(/[&<>\"']/g, ch => ({
        '&':'&amp;', '<':'&lt;', '>':'&gt;', '\"':'&quot;', "'":'&#039;'
    }[ch] || ch));
}

function notificationVisual(type) {
    if (type === 'evaluation_received') {
        return { icon: 'fa-star', color: '#facc15' };
    }
    if (type === 'designation_update') {
        return { icon: 'fa-id-badge', color: '#2B6CB0' };
    }
    if (type === 'teaching_assignment') {
        return { icon: 'fa-chalkboard-user', color: '#2B6CB0' };
    }
    if (type === 'academic_period') {
        return { icon: 'fa-calendar-days', color: '#2B6CB0' };
    }
    if (type === 'evaluation_schedule') {
        return { icon: 'fa-clock', color: '#2B6CB0' };
    }
    return { icon: 'fa-bell', color: 'var(--teal-hover)' };
}

function renderLiveNotifications(items) {
    const list = document.querySelector('#notifDropdown .notif-list');
    if (!list) return;

    if (!items.length) {
        list.dataset.sig = '';
        list.innerHTML = '<div class="notif-empty"><i class="fa-regular fa-bell-slash"></i>No notifications yet.</div>';
        return;
    }

    const html = items.map(n => {
        const v = notificationVisual(n.type);
        const unread = Number(n.is_read) === 0;
        return `<a class="notif-item ${unread ? 'unread' : ''}" data-notification-id="${Number(n.id) || 0}" href="${escapeNotificationHtml(n.link || 'faculty_dashboard.php?page=dashboard')}">
` +
            `  <div class="notif-icon" style="color:${v.color};background:${v.color}22;"><i class="fa-solid ${v.icon}"></i></div>
` +
            `  <div style="flex:1;min-width:0;">
` +
            `    <div class="notif-text">${escapeNotificationHtml(n.message)}</div>
` +
            `    <div class="notif-meta">${escapeNotificationHtml(n.created_at_label)}</div>
` +
            `  </div>
` +
            `</a>`;
    }).join('');

    // Re-render only when something changed, and keep the user's scroll position
    if (list.dataset.sig === html) return;
    const prevTop = list.scrollTop;
    list.dataset.sig = html;
    list.innerHTML = html;
    list.scrollTop = prevTop;
}

function updateNotificationBadge(count) {
    const button = document.getElementById('notifBtn');
    const existing = document.getElementById('notifBadge');
    const safeCount = Math.max(0, Number(count) || 0);

    button?.classList.toggle('has-unread', safeCount > 0);

    if (safeCount > 0) {
        const label = safeCount > 99 ? '99+' : String(safeCount);
        if (existing) {
            existing.textContent = label;
        } else if (button) {
            const badge = document.createElement('span');
            badge.className = 'notif-badge show';
            badge.id = 'notifBadge';
            badge.textContent = label;
            button.appendChild(badge);
        }
    } else if (existing) {
        existing.remove();
    }
}

function showLiveNotificationToast(notification) {
    const existing = document.getElementById('liveNotifToast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.id = 'liveNotifToast';
    toast.className = 'live-notif-toast';
    toast.innerHTML = `<div class="live-notif-toast-icon"><i class="fa-solid fa-bell"></i></div>` +
        `<div><strong>New notification</strong><span>${escapeNotificationHtml(notification.message)}</span></div>`;
    toast.addEventListener('click', () => openNotification(Number(notification.id) || 0, notification.link || 'faculty_dashboard.php?page=dashboard'));
    document.body.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('show'));
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 250);
    }, 5000);
}

async function refreshNotifications(showNewToast = true) {
    if (notificationRefreshBusy || document.hidden) return;
    notificationRefreshBusy = true;
    try {
        const response = await fetch('notifications_api.php?_=' + Date.now(), {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json' }
        });
        if (!response.ok) throw new Error('Notification request failed');
        const data = await response.json();
        if (!data.success) throw new Error(data.message || 'Notification request failed');

        const incoming = Array.isArray(data.notifications) ? data.notifications : [];
        if (!notificationFirstRefresh && showNewToast) {
            const newUnread = incoming.filter(n => Number(n.is_read) === 0 && !notificationKnownIds.has(Number(n.id)));
            // Auto-refresh read-only pages when something relevant changes (never on forms, never every 2s blindly).
            const pageName = new URLSearchParams(window.location.search).get('page') || 'dashboard';
            const refreshTypes = pageName === 'my_results' ? ['evaluation_received']
                               : pageName === 'dashboard'  ? ['evaluation_received', 'evaluation_schedule', 'academic_period']
                               : [];
            const needsRefresh = newUnread.some(n => refreshTypes.includes(n.type));
            const ae = document.activeElement;
            const busyTyping = ae && /^(INPUT|TEXTAREA|SELECT)$/.test(ae.tagName);
            const modalOpen = document.querySelector('[class*="modal"].open, [class*="overlay"].open, [class*="modal"].show');
            if (needsRefresh && !busyTyping && !modalOpen) { window.location.reload(); return; }
            if (newUnread.length) showLiveNotificationToast(newUnread[0]);
        }

        notificationKnownIds = new Set(incoming.map(n => Number(n.id)));
        notificationFirstRefresh = false;
        renderLiveNotifications(incoming);
        updateNotificationBadge(data.unread_count);
    } catch (err) {
        // Keep the last rendered notification state when polling temporarily fails.
        console.debug('Notification polling:', err.message);
    } finally {
        notificationRefreshBusy = false;
    }
}

function startNotificationPolling() {
    refreshNotifications(false);
    setInterval(() => refreshNotifications(true), 2000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshNotifications(false);
    });

    const markReadForm = document.querySelector('#notifDropdown form');
    if (markReadForm) {
        markReadForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            try {
                const response = await fetch('notifications_api.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'Accept': 'application/json' },
                    body: new FormData(markReadForm)
                });
                if (!response.ok) throw new Error('Mark-read request failed');
                const data = await response.json();
                if (!data.success) throw new Error(data.message || 'Mark-read request failed');
                renderLiveNotifications(Array.isArray(data.notifications) ? data.notifications : []);
                updateNotificationBadge(data.unread_count);
            } catch (err) {
                console.debug('Mark notifications read:', err.message);
            }
        });
    }
}

document.addEventListener('DOMContentLoaded', startNotificationPolling);

// ── Appearance and accessibility preferences ──
function applyAppearance(mode) {
    const normalized = ['light', 'dark', 'system'].includes(mode) ? mode : 'light';
    const systemLight = !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches);
    const effectiveLight = normalized === 'light' || (normalized === 'system' && systemLight);
    document.body.classList.toggle('light-theme', effectiveLight);
    document.body.dataset.appearance = normalized;
    const value = document.getElementById('appearanceVal');
    if (value) value.textContent = normalized.charAt(0).toUpperCase() + normalized.slice(1);
    const current = document.getElementById('appearanceCurrentMode');
    if (current) current.textContent = normalized.charAt(0).toUpperCase() + normalized.slice(1);
    document.querySelectorAll('[data-theme-choice]').forEach(function(btn) {
        const selected = btn.getAttribute('data-theme-choice') === normalized;
        btn.classList.toggle('active', selected);
        btn.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
}
function setAppearance(mode) {
    const normalized = ['light', 'dark', 'system'].includes(mode) ? mode : 'light';
    try { localStorage.setItem('pbi_theme', normalized); } catch (e) {}
    applyAppearance(normalized);
}
function applyPrincipalDensity(mode) {
    const density = mode === 'compact' ? 'compact' : 'comfortable';
    document.body.classList.toggle('compact-density', density === 'compact');
    document.querySelectorAll('[data-density-choice]').forEach(function(btn) {
        const selected = btn.getAttribute('data-density-choice') === density;
        btn.classList.toggle('active', selected);
        btn.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
}
function setPrincipalDensity(mode) {
    const density = mode === 'compact' ? 'compact' : 'comfortable';
    try { localStorage.setItem('pbi_density', density); } catch (e) {}
    applyPrincipalDensity(density);
}
function applyPrincipalReducedMotion(enabled) {
    const on = !!enabled;
    document.body.classList.toggle('reduce-motion', on);
    const btn = document.getElementById('appearanceMotionBtn');
    if (btn) btn.setAttribute('aria-pressed', on ? 'true' : 'false');
}
function togglePrincipalReducedMotion() {
    const next = !document.body.classList.contains('reduce-motion');
    try { localStorage.setItem('pbi_reduced_motion', next ? '1' : '0'); } catch (e) {}
    applyPrincipalReducedMotion(next);
}
document.addEventListener('DOMContentLoaded', function() {
    let theme = 'light', density = 'comfortable', reduceMotion = false;
    try {
        const savedTheme = localStorage.getItem('pbi_theme');
        if (['light', 'dark', 'system'].includes(savedTheme)) theme = savedTheme;
        density = localStorage.getItem('pbi_density') === 'compact' ? 'compact' : 'comfortable';
        reduceMotion = localStorage.getItem('pbi_reduced_motion') === '1';
        if (!localStorage.getItem('pbi_theme')) localStorage.setItem('pbi_theme', theme);
    } catch (e) {}
    applyAppearance(theme);
    applyPrincipalDensity(density);
    applyPrincipalReducedMotion(reduceMotion);
    const passwordForm = document.getElementById('facultySettingsPasswordForm') || document.getElementById('staffSettingsPasswordForm');
    if (passwordForm) passwordForm.addEventListener('submit', function(e) {
        const newPassword = passwordForm.querySelector('[name="new_password"]').value;
        const confirmPassword = passwordForm.querySelector('[name="confirm_password"]').value;
        if (newPassword !== confirmPassword) {
            e.preventDefault();
            alert('New password and confirmation do not match.');
        }
    });
    document.body.style.visibility = 'visible';
});
if (window.matchMedia) {
    const systemAppearance = window.matchMedia('(prefers-color-scheme: light)');
    const handleSystemAppearance = function() {
        try { if (localStorage.getItem('pbi_theme') === 'system') applyAppearance('system'); } catch (e) {}
    };
    if (systemAppearance.addEventListener) systemAppearance.addEventListener('change', handleSystemAppearance);
    else if (systemAppearance.addListener) systemAppearance.addListener(handleSystemAppearance);
}
// ── Profile photo modal ──
function openPhotoModal() {
    document.getElementById('photoModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closePhotoModal() {
    document.getElementById('photoModal').classList.remove('open');
    document.body.style.overflow = '';
}
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        const r = new FileReader();
        r.onload = e => {
            const img = document.getElementById('photoPreviewImg');
            const ic  = document.getElementById('uploadIconEl');
            img.src = e.target.result;
            img.style.display = 'block';
            ic.style.display  = 'none';
        };
        r.readAsDataURL(input.files[0]);
    }
}
function submitPhoto() {
    const fileInput = document.getElementById('photoFileInput');
    if (!fileInput.files || !fileInput.files[0]) { closePhotoModal(); return; }
    document.getElementById('photoForm').submit();
}
document.getElementById('photoModal').addEventListener('click', function(e) {
    if (e.target === this) closePhotoModal();
});
</script>

<?php $mysqli->close(); ?>
<script src="../admin/eval_status_poll.js" defer></script>

<!-- Logout confirmation (replaces the browser's native confirm popup) -->
<style>
.lgo-overlay{position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(2,8,23,.55);backdrop-filter:blur(3px)}
.lgo-overlay.open{display:flex}
.lgo-box{width:100%;max-width:360px;background:var(--mid);color:var(--light);border:1px solid var(--border);border-top:3px solid var(--teal);border-radius:16px;padding:24px 22px 20px;box-shadow:0 24px 70px rgba(0,0,0,.35);text-align:center;font-family:inherit}
.lgo-icon{width:46px;height:46px;margin:0 auto 12px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:rgba(37,99,235,.14);color:var(--teal);font-size:18px}
.lgo-title{font-size:17px;font-weight:700;margin:0 0 6px;color:var(--light)}
.lgo-text{font-size:13.5px;line-height:1.5;color:var(--muted);margin:0 0 18px}
.lgo-actions{display:flex;gap:10px}
.lgo-actions a,.lgo-actions button{flex:1;padding:10px 12px;border-radius:10px;font:inherit;font-size:13.5px;font-weight:600;cursor:pointer;text-decoration:none;text-align:center;border:1px solid transparent;transition:background .15s,border-color .15s}
.lgo-cancel{background:transparent;color:var(--light);border-color:var(--border)}
.lgo-cancel:hover{background:var(--inner)}
.lgo-ok{background:var(--teal);color:#fff}
.lgo-ok:hover{background:var(--teal-hover)}
.lgo-actions a:focus-visible,.lgo-actions button:focus-visible{outline:3px solid rgba(37,99,235,.45);outline-offset:2px}
</style>
<div class="lgo-overlay" id="lgoOverlay" role="dialog" aria-modal="true" aria-labelledby="lgoTitle">
    <div class="lgo-box">
        <div class="lgo-icon"><i class="fa-solid fa-power-off"></i></div>
        <h3 class="lgo-title" id="lgoTitle">Log Out</h3>
        <p class="lgo-text">Are you sure you want to log out?</p>
        <div class="lgo-actions">
            <button type="button" class="lgo-cancel" id="lgoCancel">Cancel</button>
            <a href="#" class="lgo-ok" id="lgoOk">Log Out</a>
        </div>
    </div>
</div>
<script>
(function(){
    var ov=document.getElementById('lgoOverlay'),ok=document.getElementById('lgoOk'),cancel=document.getElementById('lgoCancel');
    if(!ov)return;
    window.openLogoutModal=function(link){
        ok.href=link.getAttribute('href');
        ov.classList.add('open');
        cancel.focus();
        return false;
    };
    function close(){ov.classList.remove('open');}
    cancel.addEventListener('click',close);
    ov.addEventListener('click',function(e){if(e.target===ov)close();});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});
})();
</script>
<!-- ══════════ LEAVE-WITHOUT-SUBMITTING GUARD (evaluation forms) ══════════ -->
<style>
.leave-guard-overlay{position:fixed;inset:0;z-index:100000;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(15,23,42,.42);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);}
.leave-guard-overlay.open{display:flex;}
.leave-guard-box{width:100%;max-width:525px;background:#fff;border-radius:22px;padding:32px 35px 30px;box-shadow:0 30px 80px rgba(15,23,42,.28);font-family:inherit;animation:leaveGuardIn .18s ease-out;}
@keyframes leaveGuardIn{from{opacity:0;transform:translateY(8px) scale(.98);}to{opacity:1;transform:none;}}
.leave-guard-title{margin:0 0 10px;font-size:19px;line-height:24px;font-weight:700;color:#0f172a;}
.leave-guard-text{margin:0 0 26px;font-size:15px;line-height:26px;color:#64748b;}
.leave-guard-actions{display:flex;gap:15px;}
.leave-guard-actions button{flex:1;height:46px;border-radius:12px;font:inherit;font-size:15.5px;font-weight:700;letter-spacing:.2px;cursor:pointer;transition:background .15s,box-shadow .15s,transform .1s;}
.leave-guard-actions button:active{transform:translateY(1px);}
.leave-guard-stay{background:#fff;color:#0f172a;border:1px solid #e2e8f0;}
.leave-guard-stay:hover{background:#f8fafc;}
.leave-guard-leave{background:var(--teal,#2563EB);color:#fff;border:0;box-shadow:0 10px 24px rgba(0,0,0,.18);box-shadow:0 10px 24px color-mix(in srgb,var(--teal,#2563EB) 38%,transparent);}
.leave-guard-leave:hover{background:var(--teal-hover,#1D4ED8);}
.leave-guard-actions button:focus-visible{outline:3px solid color-mix(in srgb,var(--teal,#2563EB) 45%,transparent);outline-offset:2px;}
@media(max-width:560px){.leave-guard-box{padding:26px 22px 24px;border-radius:18px;}.leave-guard-actions{gap:12px;}}
</style>
<div class="leave-guard-overlay" id="leaveGuardOverlay" aria-hidden="true">
    <div class="leave-guard-box" role="alertdialog" aria-modal="true" aria-labelledby="leaveGuardTitle" aria-describedby="leaveGuardText">
        <h3 class="leave-guard-title" id="leaveGuardTitle">Leave without submitting?</h3>
        <p class="leave-guard-text" id="leaveGuardText">You have an unsubmitted evaluation. If you leave now, your answers will be lost.</p>
        <div class="leave-guard-actions">
            <button type="button" class="leave-guard-stay" id="leaveGuardStay">Stay</button>
            <button type="button" class="leave-guard-leave" id="leaveGuardLeave">Leave</button>
        </div>
    </div>
</div>
<script>
(function(){
    // Works for the faculty/staff peer evaluation (#evalForm) and the staff evaluation (#staffEvalForm).
    var form = document.getElementById('evalForm') || document.getElementById('staffEvalForm');
    var ov = document.getElementById('leaveGuardOverlay');
    if (!form || !ov) return;
    var stay = document.getElementById('leaveGuardStay'),
        leave = document.getElementById('leaveGuardLeave'),
        pending = null, allow = false, lastFocus = null;

    function dirty() {
        if (form.querySelector('input[type="radio"]:checked')) return true;
        var tas = form.querySelectorAll('textarea');
        for (var i = 0; i < tas.length; i++) if (tas[i].value.trim() !== '') return true;
        return false;
    }
    function openGuard(url) {
        pending = url; lastFocus = document.activeElement;
        ov.classList.add('open'); ov.setAttribute('aria-hidden', 'false');
        stay.focus();
    }
    function closeGuard() {
        ov.classList.remove('open'); ov.setAttribute('aria-hidden', 'true'); pending = null;
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    // Intercept every link that would take the user away from the form (X, Cancel, sidebar, logout…).
    document.addEventListener('click', function(e) {
        if (allow || ov.contains(e.target)) return;
        var a = e.target.closest && e.target.closest('a[href]');
        if (!a || !dirty()) return;
        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#' || /^javascript:/i.test(href) || a.target === '_blank') return;
        e.preventDefault(); e.stopPropagation();
        openGuard(a.href);
    }, true);

    stay.addEventListener('click', closeGuard);
    leave.addEventListener('click', function() {
        allow = true;
        var url = pending; ov.classList.remove('open');
        if (url) window.location.href = url;
    });
    ov.addEventListener('click', function(e) { if (e.target === ov) closeGuard(); });
    // Accidental click on the dimmed area outside the evaluation card -> ask first.
    var evalOverlay = document.querySelector('.peer-eval-overlay, .staff-eval-modal-overlay');
    var closeLink = document.querySelector('.peer-eval-close, .staff-eval-modal-close');
    var downOnBackdrop = false;
    if (evalOverlay) {
        evalOverlay.addEventListener('mousedown', function(e) { downOnBackdrop = (e.target === evalOverlay); });
        evalOverlay.addEventListener('touchstart', function(e) { downOnBackdrop = (e.target === evalOverlay); }, {passive: true});
        evalOverlay.addEventListener('click', function(e) {
            // Only a press AND release on the backdrop counts (not a text-selection drag that ends outside the card).
            if (e.target !== evalOverlay || !downOnBackdrop) return;
            downOnBackdrop = false;
            if (allow || !dirty() || !closeLink) return;
            openGuard(closeLink.href);
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Escape') return;
        if (ov.classList.contains('open')) { closeGuard(); return; }
        if (!allow && dirty() && closeLink) openGuard(closeLink.href);
    });

    // A successful submit must never trigger the guard.
    document.addEventListener('submit', function(e) { if (!e.defaultPrevented) allow = true; });

    // Fallback for browser back / refresh / closing the tab.
    window.addEventListener('beforeunload', function(e) {
        if (allow || !dirty()) return;
        e.preventDefault(); e.returnValue = '';
    });
})();
</script>


<style id="principal-matched-account-settings">
/* Principal Account Settings visual language, recoloured for this role. */
.settings-shell.principal-settings-layout {
  --ap-card:var(--mid);--ap-soft:var(--inner);--ap-line:var(--border);
  --ap-text:var(--light);--ap-muted:var(--muted);
  --ap-accent-bg:rgba(var(--ap-accent-rgb),.10);--ap-ring:rgba(var(--ap-accent-rgb),.14);
  max-width:1480px;padding-top:24px !important;padding-bottom:30px !important;
}
.settings-shell.principal-settings-layout .settings-page-head{display:flex;align-items:center;justify-content:space-between;gap:20px;margin:0 0 22px;padding:0 2px;}
.settings-shell.principal-settings-layout .settings-page-kicker{display:flex;align-items:center;gap:7px;color:var(--ap-accent-dark);font-size:10.5px;font-weight:800;letter-spacing:1.05px;text-transform:uppercase;margin-bottom:5px;}
.settings-shell.principal-settings-layout .settings-page-title{margin:0;color:var(--ap-text);font-family:'Rajdhani',sans-serif;font-size:29px;font-weight:800;line-height:1.15;}
.settings-shell.principal-settings-layout .settings-page-subtitle{margin:5px 0 0;color:var(--ap-muted);font-size:13px;line-height:1.5;}
.settings-shell.principal-settings-layout .settings-appearance-current{display:inline-flex;align-items:center;gap:7px;padding:9px 12px;border-radius:999px;background:var(--ap-accent-bg);border:1px solid rgba(var(--ap-accent-rgb),.22);color:var(--ap-accent-dark);font-size:11.5px;font-weight:700;white-space:nowrap;}
.settings-shell.principal-settings-layout .settings-appearance-current strong{color:var(--ap-text);}
.settings-shell.principal-settings-layout .settings-layout-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;align-items:start;margin-top:0;}
.settings-shell.principal-settings-layout .settings-card.ac-card{position:relative !important;overflow:hidden !important;grid-column:auto;border-radius:15px !important;padding:22px 24px 24px !important;margin:0 !important;background:var(--ap-card) !important;color:var(--ap-text) !important;border:1px solid var(--ap-line) !important;box-shadow:0 2px 10px rgba(15,23,42,.055) !important;}
.settings-shell.principal-settings-layout .settings-card.ac-card.full{grid-column:1/-1;}
.settings-shell.principal-settings-layout .settings-card.ac-card::before{content:'';position:absolute;left:0;right:0;top:0;height:3px;background:var(--ap-card-accent,var(--ap-accent));}
.settings-shell.principal-settings-layout .ac-c1{--ap-card-accent:var(--ap-accent);}
.settings-shell.principal-settings-layout .ac-c2{--ap-card-accent:var(--ap-accent);}
.settings-shell.principal-settings-layout .ac-c3{--ap-card-accent:var(--ap-accent);}
.settings-shell.principal-settings-layout .ac-c4{--ap-card-accent:var(--ap-accent);}
.settings-shell.principal-settings-layout .ac-extra{--ap-card-accent:var(--ap-accent);}
.settings-shell.principal-settings-layout .ac-head{display:flex;align-items:flex-start;gap:14px;margin:0 0 18px;}
.settings-shell.principal-settings-layout .ac-icon{width:42px;height:42px;flex:0 0 42px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;font-size:17px;background:var(--ap-accent-bg) !important;color:var(--ap-accent-dark) !important;}
.settings-shell.principal-settings-layout .ac-icon i{font-size:17px;color:var(--ap-accent-dark) !important;}
.settings-shell.principal-settings-layout .ac-head h2{display:block;margin:0 0 4px;font-family:'Rajdhani',sans-serif;font-size:21px;font-weight:800;line-height:1.15;letter-spacing:0;color:var(--ap-text) !important;}
.settings-shell.principal-settings-layout .ac-head .ac-sub{margin:0;font-size:13px;line-height:1.5;color:var(--ap-muted) !important;opacity:1;}
.settings-shell.principal-settings-layout .ac-head.appearance-heading-row{justify-content:flex-start;}
.settings-shell.principal-settings-layout .appearance-device-badge{margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:999px;background:var(--ap-soft);border:1px solid var(--ap-line);color:var(--ap-muted);font-size:10.5px;font-weight:700;white-space:nowrap;}
.settings-shell.principal-settings-layout .appearance-feature-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;}
.settings-shell.principal-settings-layout .appearance-theme-card{grid-column:1/-1;}
.settings-shell.principal-settings-layout .appearance-control-card{display:block;background:var(--ap-soft);border:1px solid var(--ap-line);border-radius:14px;padding:18px;box-shadow:none;min-width:0;}
.settings-shell.principal-settings-layout .appearance-control-head{display:flex;align-items:flex-start;gap:12px;margin-bottom:14px;}
.settings-shell.principal-settings-layout .appearance-control-icon{width:34px;height:34px;flex:0 0 34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:var(--ap-accent-bg);color:var(--ap-accent-dark);font-size:14px;}
.settings-shell.principal-settings-layout .appearance-control-head h3{font-size:15px;font-weight:700;color:var(--ap-text);margin:0 0 2px;}
.settings-shell.principal-settings-layout .appearance-control-head p{font-size:12.5px;color:var(--ap-muted);margin:0;line-height:1.45;}
.settings-shell.principal-settings-layout .appearance-theme-options{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;}
.settings-shell.principal-settings-layout .appearance-choice{position:relative;display:flex;flex-direction:row;align-items:center;justify-content:flex-start;gap:12px;width:auto;height:auto;min-height:0;padding:14px;text-align:left;border-radius:12px;border:1.5px solid var(--ap-line);background:var(--ap-card);color:var(--ap-text);cursor:pointer;box-shadow:none;transition:border-color .18s,background .18s;}
.settings-shell.principal-settings-layout .appearance-choice::before,.settings-shell.principal-settings-layout .appearance-choice::after{display:none;content:none;}
.settings-shell.principal-settings-layout .appearance-choice:hover{border-color:var(--ap-accent);color:var(--ap-text);}
.settings-shell.principal-settings-layout .appearance-choice-icon{width:36px;height:36px;flex:0 0 36px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:var(--ap-soft);color:var(--ap-muted);font-size:15px;}
.settings-shell.principal-settings-layout .appearance-choice-copy{display:block;min-width:0;}
.settings-shell.principal-settings-layout .appearance-choice-copy strong,.settings-shell.principal-settings-layout .appearance-density-choice strong,.settings-shell.principal-settings-layout .appearance-motion-copy strong{display:block;font-size:13.5px;font-weight:700;color:var(--ap-text);}
.settings-shell.principal-settings-layout .appearance-choice-copy small,.settings-shell.principal-settings-layout .appearance-density-choice small,.settings-shell.principal-settings-layout .appearance-motion-copy small{display:block;font-size:11.5px;color:var(--ap-muted);margin-top:1px;}
.settings-shell.principal-settings-layout .appearance-choice-check{position:static;margin-left:auto;width:20px;height:20px;flex:0 0 20px;border:0;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:10px;background:var(--ap-accent);color:#fff;opacity:0;transform:scale(.6);transition:opacity .2s,transform .2s;}
.settings-shell.principal-settings-layout .appearance-choice[aria-pressed="true"],.settings-shell.principal-settings-layout .appearance-choice.active{border-color:var(--ap-accent);background:var(--ap-accent-bg);color:var(--ap-text);box-shadow:0 0 0 3px var(--ap-ring);}
.settings-shell.principal-settings-layout .appearance-choice[aria-pressed="true"] .appearance-choice-icon{background:var(--ap-accent);color:#fff;}
.settings-shell.principal-settings-layout .appearance-choice[aria-pressed="true"] .appearance-choice-check{opacity:1;transform:scale(1);}
.settings-shell.principal-settings-layout .appearance-inline-status{margin-top:14px;font-size:12.5px;color:var(--ap-muted);display:flex;align-items:center;gap:7px;}
.settings-shell.principal-settings-layout .appearance-inline-status i{color:var(--ap-accent-dark);}
.settings-shell.principal-settings-layout .appearance-inline-status strong{color:var(--ap-text);}
.settings-shell.principal-settings-layout .appearance-density-options{display:grid;grid-template-columns:1fr;gap:10px;}
.settings-shell.principal-settings-layout .appearance-density-choice{display:flex;align-items:center;gap:12px;width:100%;padding:12px 14px;text-align:left;border-radius:12px;border:1.5px solid var(--ap-line);background:var(--ap-card);color:var(--ap-muted);cursor:pointer;box-shadow:none;}
.settings-shell.principal-settings-layout .appearance-density-choice:hover{border-color:var(--ap-accent);}
.settings-shell.principal-settings-layout .appearance-density-choice>i{width:20px;flex:0 0 20px;background:transparent;border-radius:0;font-size:15px;text-align:center;color:var(--ap-muted);}
.settings-shell.principal-settings-layout .appearance-density-choice[aria-pressed="true"],.settings-shell.principal-settings-layout .appearance-density-choice.active{border-color:var(--ap-accent);background:var(--ap-accent-bg);}
.settings-shell.principal-settings-layout .appearance-density-choice[aria-pressed="true"]>i{color:var(--ap-accent-dark);}
.settings-shell.principal-settings-layout .appearance-motion-toggle{width:100%;display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 14px;text-align:left;border-radius:12px;border:1.5px solid var(--ap-line);background:var(--ap-card);cursor:pointer;box-shadow:none;color:var(--ap-text);}
.settings-shell.principal-settings-layout .appearance-motion-toggle:hover{border-color:var(--ap-accent);}
.settings-shell.principal-settings-layout .appearance-motion-toggle[aria-pressed="true"]{border-color:var(--ap-accent);background:var(--ap-accent-bg);}
.settings-shell.principal-settings-layout .appearance-switch-track{width:44px;height:24px;flex:0 0 44px;border-radius:999px;background:#CBD5E1;position:relative;transition:background .2s;}
.settings-shell.principal-settings-layout .appearance-switch-thumb{position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .2s;}
.settings-shell.principal-settings-layout .appearance-motion-toggle[aria-pressed="true"] .appearance-switch-track{background:var(--ap-accent);}
.settings-shell.principal-settings-layout .appearance-motion-toggle[aria-pressed="true"] .appearance-switch-thumb{transform:translateX(20px);}
.settings-shell.principal-settings-layout .appearance-accessibility-note{margin-top:12px;font-size:11.5px;color:var(--ap-muted);display:flex;gap:7px;align-items:flex-start;line-height:1.5;}
.settings-shell.principal-settings-layout .appearance-accessibility-note i{color:var(--ap-accent-dark);margin-top:2px;}
.settings-shell.principal-settings-layout .profile-card{display:flex;align-items:center;gap:24px;}
.settings-shell.principal-settings-layout .profile-photo-lg{width:96px;height:96px;flex:0 0 96px;border-radius:50%;object-fit:cover;border:3px solid var(--ap-accent);background:var(--ap-soft);display:flex;align-items:center;justify-content:center;color:var(--ap-accent-dark);font-size:28px;}
.settings-shell.principal-settings-layout .profile-photo-fallback{display:flex;}
.settings-shell.principal-settings-layout .photo-form-wrap{display:flex;flex-direction:column;align-items:flex-start;gap:3px;min-width:0;}
.settings-shell.principal-settings-layout .photo-help{font-size:12px;color:var(--ap-muted);margin-top:8px;line-height:1.5;}
.settings-shell.principal-settings-layout .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:17px 20px;margin-bottom:20px;}
.settings-shell.principal-settings-layout .form-group{display:flex;flex-direction:column;gap:7px;min-width:0;}
.settings-shell.principal-settings-layout .form-group label{font-size:11px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--ap-muted);}
.settings-shell.principal-settings-layout .form-group input{width:100%;box-sizing:border-box;padding:12px 13px;font:14px 'DM Sans',sans-serif;border-radius:9px;border:1px solid var(--ap-line);background:var(--ap-card);color:var(--ap-text);outline:none;}
.settings-shell.principal-settings-layout .form-group input:focus{border-color:var(--ap-accent);box-shadow:0 0 0 3px var(--ap-ring);}
.settings-shell.principal-settings-layout .form-group input[readonly]{background:var(--ap-soft);color:var(--ap-muted);cursor:default;}
.settings-shell.principal-settings-layout .field-note{font-size:11px;line-height:1.4;color:var(--ap-muted);}
.settings-shell.principal-settings-layout .btn-primary{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 17px;border:0;border-radius:9px;font:700 13.5px 'DM Sans',sans-serif;color:#fff;background:var(--ap-accent);box-shadow:0 4px 14px rgba(var(--ap-accent-rgb),.20);cursor:pointer;text-decoration:none;transition:transform .18s,background .18s;}
.settings-shell.principal-settings-layout .btn-primary:hover{background:var(--ap-accent-strong);color:#fff;transform:translateY(-1px);}
.settings-shell.principal-settings-layout .ac-c5{--ap-card-accent:var(--ap-accent);}
.settings-shell.principal-settings-layout a.btn-primary{text-decoration:none;}
.settings-shell.principal-settings-layout .sq-status{display:inline-flex;align-items:center;gap:7px;padding:5px 11px;margin-bottom:16px;border-radius:999px;font-size:12px;font-weight:600;background:var(--ap-accent-bg);border:1px solid var(--ap-line);color:var(--ap-muted);}
.settings-shell.principal-settings-layout .sq-status.ok{color:#10B981;border-color:rgba(16,185,129,.4);}
.settings-shell.principal-settings-layout .sq-status.warn{color:#D97706;border-color:rgba(217,119,6,.45);}
.settings-shell.principal-settings-layout .btn-primary:focus-visible,.settings-shell.principal-settings-layout button:focus-visible{outline:3px solid rgba(var(--ap-accent-rgb),.30);outline-offset:2px;}
.settings-shell.principal-settings-layout .settings-action-link{font-size:12px;padding:9px 12px;white-space:nowrap;}
.settings-shell.principal-settings-layout .settings-protected-icon{color:var(--ap-accent-dark);font-size:17px;flex:0 0 auto;}
.settings-shell.principal-settings-layout .setting-row{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:13px 0;border-top:1px solid var(--ap-line);}
.settings-shell.principal-settings-layout .setting-row:first-of-type{border-top:0;}
.settings-shell.principal-settings-layout .setting-row strong{display:block;color:var(--ap-text);font-size:13px;}
.settings-shell.principal-settings-layout .setting-row span{display:block;color:var(--ap-muted);font-size:11.5px;margin-top:3px;line-height:1.45;}
.settings-shell.principal-settings-layout .setting-toggle{position:relative;width:44px;height:24px;flex:0 0 44px;}
.settings-shell.principal-settings-layout .setting-toggle input{display:none;}
.settings-shell.principal-settings-layout .setting-toggle .slider{position:absolute;inset:0;border-radius:20px;background:var(--ap-line);cursor:pointer;transition:background .2s;}
.settings-shell.principal-settings-layout .setting-toggle .slider:before{content:'';position:absolute;width:18px;height:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:transform .2s;}
.settings-shell.principal-settings-layout .setting-toggle input:checked+.slider{background:var(--ap-accent);}
.settings-shell.principal-settings-layout .setting-toggle input:checked+.slider:before{transform:translateX(20px);}
.settings-shell.principal-settings-layout .settings-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:16px;}
.settings-shell.principal-settings-layout .account-facts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;}
.settings-shell.principal-settings-layout .account-fact{background:var(--ap-soft);border:1px solid var(--ap-line);border-radius:10px;padding:12px;min-width:0;}
.settings-shell.principal-settings-layout .account-fact label{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.7px;color:var(--ap-muted);margin-bottom:4px;}
.settings-shell.principal-settings-layout .account-fact b{color:var(--ap-text);font-size:12.5px;overflow-wrap:anywhere;}
body:not(.light-theme) .settings-shell.principal-settings-layout{--ap-text:#E0E6F0;--ap-muted:#A0B3C6;--ap-line:rgba(255,255,255,.10);--ap-accent-bg:rgba(var(--ap-accent-rgb),.16);--ap-ring:rgba(var(--ap-accent-rgb),.16);}
body:not(.light-theme) .settings-shell.principal-settings-layout .settings-card.ac-card{box-shadow:0 8px 24px rgba(0,0,0,.18) !important;}
body:not(.light-theme) .settings-shell.principal-settings-layout .appearance-choice-check{color:#fff;}
body.compact-density .settings-shell.principal-settings-layout .settings-card.ac-card{padding:17px 19px 19px !important;}
body.compact-density .settings-shell.principal-settings-layout .appearance-control-card{padding:14px;}
body.compact-density .settings-shell.principal-settings-layout .setting-row{padding:9px 0;}

/* ── Settings tabs (Profile & Security / Appearance) — uses each portal's own accent ── */
.settings-shell.principal-settings-layout .settings-tabs{display:inline-flex;gap:6px;padding:6px;margin:0 0 20px;background:var(--ap-card);border:1px solid var(--ap-line);border-radius:16px;box-shadow:0 2px 10px rgba(15,23,42,.045);max-width:100%;}
.settings-shell.principal-settings-layout .settings-tab{display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:11px 22px;border:0;border-radius:11px;background:transparent;color:var(--ap-muted);font:700 14px 'DM Sans',sans-serif;cursor:pointer;white-space:nowrap;transition:background .18s,color .18s,box-shadow .18s;}
.settings-shell.principal-settings-layout .settings-tab i{font-size:15px;}
.settings-shell.principal-settings-layout .settings-tab:hover{color:var(--ap-text);background:var(--ap-accent-bg);}
.settings-shell.principal-settings-layout .settings-tab.active{background:var(--ap-accent);color:#fff;box-shadow:0 6px 16px rgba(var(--ap-accent-rgb),.28);}
.settings-shell.principal-settings-layout .settings-tab:focus-visible{outline:3px solid rgba(var(--ap-accent-rgb),.30);outline-offset:2px;}
.settings-shell.principal-settings-layout .settings-tab-panel[hidden]{display:none !important;}
@media(max-width:560px){.settings-shell.principal-settings-layout .settings-tabs{display:flex;width:100%;box-sizing:border-box;}.settings-shell.principal-settings-layout .settings-tab{flex:1;padding:11px 10px;font-size:13px;}}
body.reduce-motion *,body.reduce-motion *::before,body.reduce-motion *::after{animation-duration:.01ms !important;animation-iteration-count:1 !important;scroll-behavior:auto !important;transition-duration:.01ms !important;}
@media(max-width:900px){.settings-shell.principal-settings-layout .appearance-theme-options{grid-template-columns:1fr;}.settings-shell.principal-settings-layout .settings-layout-grid{grid-template-columns:1fr;}.settings-shell.principal-settings-layout .settings-card.ac-card.full{grid-column:auto;}.settings-shell.principal-settings-layout .appearance-feature-grid{grid-template-columns:1fr;}.settings-shell.principal-settings-layout .appearance-theme-card{grid-column:auto;}}
@media(max-width:760px){.settings-shell.principal-settings-layout .settings-page-head{align-items:flex-start;flex-direction:column;}.settings-shell.principal-settings-layout .appearance-device-badge{display:none;}.settings-shell.principal-settings-layout .account-facts{grid-template-columns:1fr;}.settings-shell.principal-settings-layout .form-grid{grid-template-columns:1fr;}}
@media(max-width:560px){.settings-shell.principal-settings-layout .settings-card.ac-card{padding:18px 17px 20px !important;}.settings-shell.principal-settings-layout .profile-card{align-items:flex-start;flex-direction:column;}.settings-shell.principal-settings-layout .ac-head{gap:11px;}.settings-shell.principal-settings-layout .ac-icon{width:38px;height:38px;flex-basis:38px;}.settings-shell.principal-settings-layout .setting-row{align-items:flex-start;}}
</style>

<style id="faculty-form-ea-sizing">
/* Evaluation popup sized like the admin EA evaluation form. */
html body .peer-eval-dialog{max-width:780px;border-radius:18px;}
html body .peer-eval-dialog .peer-eval-dialog-header{padding:24px 28px 16px;}
html body .peer-eval-dialog .peer-eval-avatar{width:52px;height:52px;}
html body .peer-eval-dialog .peer-eval-target-copy h2{font-size:20px;}
html body .peer-eval-dialog .peer-eval-target-copy p{font-size:12px;}
html body .peer-eval-dialog .peer-eval-scroll{padding:24px 28px;}
html body .peer-eval-dialog .peer-eval-scale{gap:16px;margin-bottom:18px;padding:10px 14px;border-radius:8px;}
html body .peer-eval-dialog .peer-eval-scale-item{gap:5px;font-size:11px;}
html body .peer-eval-dialog .peer-eval-scale-item span{width:18px;height:18px;border-radius:4px;font-size:10px;}
html body .peer-eval-dialog .peer-eval-question-section .q-category-header{margin:18px 0 8px;padding:0;border:0;font-size:10px;letter-spacing:.9px;}
html body .peer-eval-dialog .peer-eval-question-section:first-of-type .q-category-header{margin-top:0;}
html body .peer-eval-dialog .eval-form-wrap{margin:0 0 16px;border-radius:12px;}
html body .peer-eval-dialog .eval-form-table td{padding:10px 6px;}
html body .peer-eval-dialog .eval-form-table td:first-child{padding-left:14px;padding-right:10px;}
html body .peer-eval-dialog .eval-form-table col.c-rate{width:52px;}
html body .peer-eval-dialog .eval-form-qtext{font-size:12.5px;font-weight:400;line-height:1.45;}
html body .peer-eval-dialog .eval-form-qno{font-size:12.5px;}
html body .peer-eval-dialog .eval-form-rating label{width:34px;height:30px;border-radius:7px;font-size:12px;font-weight:800;}
html body .peer-eval-dialog .peer-eval-footer{padding:16px 28px 24px;}
html body .peer-eval-dialog .peer-eval-footer .btn-submit-new{min-height:0;padding:13px;border-radius:10px;font-size:15px;box-shadow:none;}
html body .peer-eval-dialog .peer-eval-footer .btn-cancel-new{padding:13px 22px;border-radius:10px;font-size:14px;}
@media(max-width:700px){
 html body .peer-eval-dialog .peer-eval-dialog-header,html body .peer-eval-dialog .peer-eval-footer{padding-left:18px;padding-right:18px;}
 html body .peer-eval-dialog .peer-eval-scroll{padding:18px;}
 html body .peer-eval-dialog .eval-form-table col.c-rate{width:36px;}
 html body .peer-eval-dialog .eval-form-rating label{width:29px;height:30px;}
}
</style>
</body>
</html>