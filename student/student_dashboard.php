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
require_once 'security.php';
require_once '../shared/eligibility.php';
require_once '../shared/EvaluationContextService.php';
require_once '../shared/QuestionnaireService.php';
qn_migrate_legacy_once($mysqli);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: student_login.php"); exit;
}

$student_id   = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'];

// ── SECURITY QUESTIONS POPUP (AJAX, returns JSON) ─────────────
// Step 1 (sq_verify=1) only checks the current password; step 2 checks it again and saves.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['sq_ajax'] ?? '') === '1') {
    $sqOut = ['ok' => false, 'message' => 'Something went wrong. Please try again.'];
    try {
        if (!csrf_valid($_POST['csrf_token'] ?? '')) {
            $sqOut['message'] = 'Your session expired. Please reload the page and try again.';
        } else {
            security_ensure_tables($mysqli);
            $sqUid  = (int)$student_id;
            $sqKind = 'sqchange_student';
            $sqSt = $mysqli->prepare("SELECT username, password_hash FROM users WHERE id = ? AND role = 'student' LIMIT 1");
            $sqSt->bind_param('i', $sqUid);
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
                        $_SESSION['sq_flash'] = 'Security questions updated successfully.';
                        $sqOut = ['ok' => true, 'message' => 'Security questions updated successfully.'];
                    } catch (Throwable $e) {
                        try { $mysqli->rollback(); } catch (Throwable $ignored) {}
                        error_log('student security questions save failed: ' . $e->getMessage());
                        $sqOut['message'] = 'The security questions could not be saved. Please try again.';
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('student security questions popup failed: ' . $e->getMessage());
    }
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($sqOut);
    exit;
}

// ── FETCH STUDENT'S OWN PHOTO + EDUCATION LEVEL ───────────────
// Moved up here (was previously fetched further down, AFTER the
// submission handler) because the server-side eligibility re-check in
// the submission handler now needs $student_level/$student_year_level
// too -- it can no longer rely purely on shared/eligibility.php.
$phRes = $mysqli->prepare("SELECT photo, education_level, year_level, username, email FROM users WHERE id=? LIMIT 1");
$phRes->bind_param("i", $student_id);
$phRes->execute();
$phRow = $phRes->get_result()->fetch_assoc();
$phRes->close();
$student_photo      = $phRow['photo'] ?? '';
$student_level      = $phRow['education_level'] ?? null;
$student_year_level = $phRow['year_level'] ?? null;
$student_username   = $phRow['username'] ?? '';
$student_email       = $phRow['email'] ?? '';

// Same JHS/SHS/College -> year/grade level lists used at registration
// (student_register.php), reused here so Settings offers a self-edit
// dropdown that stays consistent with the values eligibility checks
// (teaching_assignments / user_year_levels) actually match against.
//
// NOTE: College values carry a " College" suffix (e.g. "3rd Year College")
// because that's the exact string the EA's Account Management assigns in
// teaching_assignments/user_year_levels for College-level targets -- a bare
// "3rd Year" (which is what student_register.php's own dropdown currently
// stores) does NOT match it and silently breaks the student's eligibility
// to evaluate Faculty/Staff assigned to that year level. JHS/SHS use plain
// "Grade N" on both sides, so no suffix is needed there.
$reg_year_levels = [
    'junior_high' => ['Grade 7','Grade 8','Grade 9','Grade 10'],
    'senior_high' => ['Grade 11','Grade 12'],
    'college'     => ['1st Year College','2nd Year College','3rd Year College','4th Year College'],
];
$year_level_options = $reg_year_levels[$student_level] ?? [];

// ── ROLE / ASSIGNMENT-BASED ELIGIBILITY ────────────────────────
// Only two personnel contexts are exposed to students:
//   • Faculty / Teacher / Teaching Staff -> matching teaching assignment.
//   • Staff / Non-teaching Staff          -> institution-wide Staff context.
// Principal and Dean are each handled as their own top-level category.
function normalizeLevelVariants($education_level) {
    $level_key = strtolower(trim($education_level ?? ''));
    $edu_bucket_map = [
        'elementary'             => ['basic education'],
        'junior_high'            => ['basic education'],
        'senior_high'            => ['basic education'],
        'basic education'        => ['basic education'],
        'college'                => ['college', 'higher education', 'college / university', 'college/university'],
        'higher education'       => ['college', 'higher education', 'college / university', 'college/university'],
        'college / university'   => ['college', 'higher education', 'college / university', 'college/university'],
        'college/university'     => ['college', 'higher education', 'college / university', 'college/university'],
    ];
    return array_values(array_unique(array_map('strtolower', $edu_bucket_map[$level_key] ?? [$level_key])));
}

function isMatchedViaAssignment($mysqli, $target_id, $level_variants, $student_year_level) {
    if (empty($level_variants) || $student_year_level === null || $student_year_level === '') return false;
    $placeholders = implode(',', array_fill(0, count($level_variants), '?'));
    $stmt = $mysqli->prepare(
        "SELECT 1 FROM (
            SELECT ta.user_id FROM teaching_assignments ta
            WHERE ta.user_id = ?
              AND LOWER(TRIM(ta.education_level)) IN ($placeholders)
              AND LOWER(TRIM(ta.year_level)) = LOWER(TRIM(?))
            UNION
            SELECT uyl.user_id FROM user_year_levels uyl
            WHERE uyl.user_id = ?
              AND LOWER(TRIM(uyl.year_level)) = LOWER(TRIM(?))
        ) AS matched"
    );
    $types  = 'i' . str_repeat('s', count($level_variants)) . 's' . 'is';
    $params = array_merge([$target_id], $level_variants, [$student_year_level, $target_id, $student_year_level]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $matches = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $matches;
}

function hasAnyYearLevelAssignment($mysqli, $target_id) {
    $stmt = $mysqli->prepare(
        "SELECT 1 FROM (
            SELECT user_id FROM user_year_levels WHERE user_id=?
            UNION ALL
            SELECT user_id FROM teaching_assignments WHERE user_id=?
        ) x LIMIT 1"
    );
    $stmt->bind_param('ii', $target_id, $target_id);
    $stmt->execute();
    $has_any = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $has_any;
}

// "Teaching person" for evaluation_context='teacher' purposes: a Teacher
// (role/secondary_role literally 'teacher'), OR a Staff member who carries
// an actual teaching/year-level assignment ("teaching staff"). Mirrors
// admin/questionnaire.php's is_teaching_staff rule, where such a Staff
// account is placed under the Teacher bucket, not Staff. Every check that
// gates a 'teacher' evaluation_context must use this instead of the plain
// ec_has_teacher_function() role check, or a teaching-staff member shown
// under the Faculty tab will fail eligibility when they try to evaluate.
function isTeachingPerson($mysqli, array $u): bool {
    return ec_has_teacher_function($u)
        || (ec_has_staff_function($u) && hasAnyYearLevelAssignment($mysqli, (int)($u['id'] ?? 0)));
}

function isCollegeEducationLevel($education_level) {
    $v = strtolower(trim((string)$education_level));
    return in_array($v, [
        'college',
        'higher education',
        'college / university',
        'college/university'
    ], true);
}

/**
 * College Teacher evaluations are semester-specific.  When the current
 * student is a College student, a Teacher is eligible only when the
 * Teacher's assigned_period matches the active evaluation period's
 * semester.  Non-college student flows keep the existing year-level rule.
 */
function teacherMatchesActiveSemester($mysqli, $target_id, $student_level, $active_period_semester) {
    if (!isCollegeEducationLevel($student_level)) return true;
    if ($active_period_semester === null || trim((string)$active_period_semester) === '') return false;

    $stmt = $mysqli->prepare("SELECT assigned_period FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $target_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row
        && trim((string)($row['assigned_period'] ?? '')) === trim((string)$active_period_semester);
}

function canStudentEvaluateTarget(
    $mysqli,
    $student_level,
    $student_year_level,
    $target_id,
    $evaluation_context = '',
    $active_period_semester = null
) {
    $stmt = $mysqli->prepare(
        "SELECT role, secondary_role, designation, assigned_period, is_active
         FROM users
         WHERE id=?
         LIMIT 1"
    );

    $stmt->bind_param('i', $target_id);
    $stmt->execute();

    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target || !(int)$target['is_active']) {
        return [
            false,
            'This person is not available for evaluation.'
        ];
    }
    $target['id'] = $target_id;

    /*
     * SCHOOL HEAD
     *
     * A student only evaluates the school head of their own department:
     * College students evaluate the Dean only; Junior High / Senior High
     * students evaluate the Principal only. A student whose education
     * level is neither may evaluate no school head at all. This mirrors
     * the Dashboard's tab visibility, but is re-checked here so a manually
     * crafted POST (e.g. a College student's browser posting the
     * Principal's id) can never slip through.
     */
    if (in_array($target['role'], ['principal', 'dean'], true)) {
        if ($evaluation_context !== 'school_head' && $evaluation_context !== '') {
            return [false, null];
        }
        $is_college_student = isCollegeEducationLevel($student_level);
        $is_jhs_shs_student = in_array(strtolower(trim((string)$student_level)), ['junior_high', 'senior_high'], true);

        if ($target['role'] === 'dean' && !$is_college_student) {
            return [
                false,
                'This person is not available for evaluation.'
            ];
        }
        if ($target['role'] === 'principal' && !$is_jhs_shs_student) {
            return [
                false,
                'This person is not available for evaluation.'
            ];
        }

        return [true, null];
    }

    /*
     * DETERMINE AVAILABLE CONTEXTS
     *
     * $has_teacher covers both actual Teachers and "teaching staff" --
     * Staff members with a real teaching/year-level assignment -- so they
     * pass evaluation_context='teacher' eligibility, matching where they
     * now appear on the Dashboard (Faculty tab, not Staff).
     */
    $has_teacher = isTeachingPerson($mysqli, $target);
    $has_staff   = ec_has_staff_function($target);

    /*
     * TEACHER / STAFF CONTEXT VALIDATION
     */
    if ($evaluation_context === 'teacher' && !$has_teacher) {
        return [
            false,
            'This person is not configured for a Teacher evaluation.'
        ];
    }

    // College Teachers are tied to the active evaluation semester.  This
    // prevents a Teacher assigned to 2nd Semester from being evaluated by
    // a College student while the active period is 1st Semester (and vice
    // versa).  Staff context is intentionally unaffected.
    if ($evaluation_context === 'teacher'
        && $has_teacher
        && !teacherMatchesActiveSemester($mysqli, $target_id, $student_level, $active_period_semester)) {
        return [
            false,
            'This teacher is not assigned to the current evaluation semester.'
        ];
    }

    if ($evaluation_context === 'staff' && !$has_staff) {
        return [
            false,
            'This person is not configured for a Staff evaluation.'
        ];
    }

    // Staff context is non-teaching staff ONLY (mirrors the Dashboard's
    // Staff tab / admin/questionnaire.php's isNonTeachingStaff rule). A
    // Staff member with an actual teaching/year-level assignment is
    // teaching staff -- evaluable only under 'teacher', never 'staff',
    // even when their assignment matches this student's year level.
    if ($evaluation_context === 'staff' && $has_staff && hasAnyYearLevelAssignment($mysqli, $target_id)) {
        return [
            false,
            'This person is not configured for a Staff evaluation.'
        ];
    }

    /*
     * If no context was explicitly supplied, determine the primary
     * context from the person's available functions.
     */
    if ($evaluation_context === '') {
        $evaluation_context = $has_teacher
            ? 'teacher'
            : 'staff';
    }

    /*
     * Student account must have education level and year level.
     */
    if (!$student_level || !$student_year_level) {
        return [
            false,
            'Your education level / year level is not set on your account. Please contact the registrar.'
        ];
    }

    /*
     * Check whether the target has ANY teaching/year-level assignment.
     */
    $has_assignment = hasAnyYearLevelAssignment(
        $mysqli,
        $target_id
    );

    /*
     * STAFF WITH NO ASSIGNMENT
     *
     * Non-teaching staff are institution-wide.
     *
     * Teachers without an assignment are NOT automatically
     * available to everybody.
     */
    if (!$has_assignment) {

        if ($evaluation_context === 'staff' && $has_staff) {
            return [true, null];
        }

        return [
            false,
            'This teacher is not assigned to a year level.'
        ];
    }

    /*
     * TEACHER / TEACHING STAFF
     *
     * Must match student's education level + year level.
     */
    $matched = isMatchedViaAssignment(
        $mysqli,
        $target_id,
        normalizeLevelVariants($student_level),
        $student_year_level
    );

    return [
        $matched,
        $matched
            ? null
            : 'This person is not assigned to your education level / year level.'
    ];
}

// ── ACTIVE EVALUATION PERIOD ──────────────────────────────────
// Fetched once up front so the Dashboard, Guidelines, and the submit
// handler below all agree on whether evaluations are currently open.
$activePeriodRow = $mysqli->query("SELECT id, semester FROM evaluation_periods WHERE is_active=1 LIMIT 1")->fetch_assoc();
$period_is_open  = (bool)$activePeriodRow;
$active_period_semester = $activePeriodRow['semester'] ?? null;

// ── LIVE SCHEDULE GATE ─────────────────────────────────────
// evaluation_periods.is_active only identifies the applicable period.
// Actual availability is controlled by system_settings.php's Follow
// Schedule / Force Open / Force Closed mode and the exact Asia/Manila
// opening/closing instants. This prevents an active period from opening
// Student evaluations early.
function student_live_schedule_is_open(mysqli $mysqli): bool {
    $rows = [];
    $res = $mysqli->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('control_mode','eval_start','eval_end','schedule_timezone')");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[$row['setting_key']] = (string)($row['setting_value'] ?? '');
        }
        $res->free();
    }

    $mode = strtolower(trim($rows['control_mode'] ?? 'schedule'));
    if ($mode === 'open') return true;
    if ($mode === 'closed') return false;

    $tzName = trim($rows['schedule_timezone'] ?? '') ?: 'Asia/Manila';
    try { $tz = new DateTimeZone($tzName); } catch (Throwable $e) { $tz = new DateTimeZone('Asia/Manila'); }

    $parse = static function(string $raw) use ($tz): ?DateTimeImmutable {
        $raw = trim($raw);
        if ($raw === '') return null;
        foreach (['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $fmt) {
            $dt = DateTimeImmutable::createFromFormat($fmt, $raw, $tz);
            $err = DateTimeImmutable::getLastErrors();
            if ($dt instanceof DateTimeImmutable && ($err === false || (($err['warning_count'] ?? 0) === 0 && ($err['error_count'] ?? 0) === 0))) {
                return $dt;
            }
        }
        try { return new DateTimeImmutable($raw, $tz); } catch (Throwable $e) { return null; }
    };

    $start = $parse($rows['eval_start'] ?? '');
    $end   = $parse($rows['eval_end'] ?? '');
    if (!$start || !$end || $end <= $start) return false;

    $now = new DateTimeImmutable('now', $tz);
    return $now >= $start && $now < $end;
}

$period_is_open = $period_is_open && student_live_schedule_is_open($mysqli);

// ── LEVEL-SCOPED PERIOD GATING ────────────────────────────────
// JH/SHS only evaluate once, at the end of the school year (a period
// whose semester = 'School Year'). College evaluates per-term (1st
// Semester / 2nd Semester / Summer). evaluation_periods.is_active is a
// single global flag with no level of its own, so activating a College
// term was also opening the JH/SHS window (and activating the School
// Year period was opening the College window). Close it again here
// whenever the active period's type doesn't match the student's own
// level -- every other use of $period_is_open below (dashboard status,
// card rendering, and the submit handler) reads this same variable, so
// gating it once here is enough.
if ($period_is_open) {
    $is_college_student    = isCollegeEducationLevel($student_level);
    $is_school_year_period = trim((string)$active_period_semester) === 'School Year';
    if ($is_college_student === $is_school_year_period) {
        $period_is_open = false;
    }
}

// ── CURRENT EVALUATION PERIOD CARD (DASHBOARD DISPLAY) ─────────
// Re-reads the same system_settings row student_live_schedule_is_open()
// already consulted above, this time keeping the values around so the
// dashboard card can show them (rather than just the open/closed
// boolean). Cheap, read-only, and kept deliberately separate from the
// gate function so a display change here can never affect the actual
// open/closed decision.
$periodSettings = [];
$psRes = $mysqli->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('control_mode','eval_start','eval_end','schedule_timezone','year_level_edit_mode')");
if ($psRes) {
    while ($row = $psRes->fetch_assoc()) { $periodSettings[$row['setting_key']] = (string)($row['setting_value'] ?? ''); }
    $psRes->free();
}
$period_control_mode = strtolower(trim($periodSettings['control_mode'] ?? 'schedule'));
$period_tz_name = trim($periodSettings['schedule_timezone'] ?? '') ?: 'Asia/Manila';
try { $period_tz = new DateTimeZone($period_tz_name); } catch (Throwable $e) { $period_tz = new DateTimeZone('Asia/Manila'); }

// Closed vs Scheduled (matches the Faculty page): "Scheduled" only while
// following the schedule and the configured start time is still ahead.
// Display-only; never affects $period_is_open.
$period_is_scheduled = false;
if (!$period_is_open && $period_control_mode === 'schedule') {
    $__sStart = trim((string)($periodSettings['eval_start'] ?? ''));
    if ($__sStart !== '') {
        try { $period_is_scheduled = (new DateTimeImmutable('now', $period_tz)) < new DateTimeImmutable($__sStart, $period_tz); }
        catch (Throwable $e) { $period_is_scheduled = false; }
    }
}

// ── YEAR-LEVEL SELF-EDIT GATE ────────────────────────────────
// A student changing their own Year Level immediately changes which
// Faculty/Staff roster they're eligible to evaluate (see
// isMatchedViaAssignment() / canStudentEvaluateTarget() above), so
// letting this be freely self-editable at any time is an access-control
// hole, not just a data-quality one -- a student could switch into
// another year level's roster, evaluate, and switch back.
//
// Editable only when BOTH hold:
//   1. The EA has explicitly turned editing on via system_settings
//      (year_level_edit_mode='open'), same admin-toggle pattern as
//      control_mode -- default is 'locked' so this is opt-in, not
//      opt-out.
//   2. No evaluation period is currently open ($period_is_open, already
//      computed above) -- a fail-safe independent of the EA remembering
//      to flip the switch back off after promotion season, since an
//      open evaluation period is exactly when this hole is exploitable.
// The EA's own edit of a student's year level via Account Management is
// a separate, admin-authenticated code path and is NOT subject to this
// gate.
$year_level_edit_open = (strtolower(trim($periodSettings['year_level_edit_mode'] ?? 'locked')) === 'open');
$year_level_editable   = $year_level_edit_open && !$period_is_open;
$year_level_locked_reason = !$year_level_edit_open
    ? 'Year level updates are currently closed. Contact the EA if this needs correcting.'
    : ($period_is_open ? 'Year level can\'t be changed while an evaluation period is open. Contact the EA if this is incorrect.' : '');

function student_dashboard_parse_period_dt(string $raw, DateTimeZone $tz): ?DateTimeImmutable {
    $raw = trim($raw);
    if ($raw === '') return null;
    foreach (['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $raw, $tz);
        $err = DateTimeImmutable::getLastErrors();
        if ($dt instanceof DateTimeImmutable && ($err === false || (($err['warning_count'] ?? 0) === 0 && ($err['error_count'] ?? 0) === 0))) {
            return $dt;
        }
    }
    try { return new DateTimeImmutable($raw, $tz); } catch (Throwable $e) { return null; }
}
function student_dashboard_format_period_dt(?DateTimeImmutable $dt, string $tzName): string {
    if (!$dt) return 'Not set';
    return $dt->format('F j, Y') . ' · ' . $dt->format('g:i A');
}
$period_opens_label  = student_dashboard_format_period_dt(student_dashboard_parse_period_dt($periodSettings['eval_start'] ?? '', $period_tz), $period_tz_name);
$period_closes_label = student_dashboard_format_period_dt(student_dashboard_parse_period_dt($periodSettings['eval_end'] ?? '', $period_tz), $period_tz_name);

$period_opens_short  = ($__dt = student_dashboard_parse_period_dt($periodSettings['eval_start'] ?? '', $period_tz)) ? $__dt->format('M j, Y g:i A') : 'Not set';
$period_closes_short = ($__dt = student_dashboard_parse_period_dt($periodSettings['eval_end'] ?? '', $period_tz)) ? $__dt->format('M j, Y g:i A') : 'Not set';
$edu_labels = ['elementary'=>'Elementary','junior_high'=>'Junior High School','senior_high'=>'Senior High School','college'=>'College'];
$student_edu_label = $edu_labels[strtolower(trim((string)$student_level))] ?? 'Student';

// Academic Year: not one of the columns this file already selects from
// evaluation_periods. Read it if the column exists; otherwise fall back
// to the Philippine school-year convention (year rolls over ~June) off
// today's date, so the card still shows something reasonable.
// NOTE: if evaluation_periods does carry an academic_year column under a
// different name, swap the column name below to match.
$academic_year_label = null;
$ayCol = $mysqli->query("SHOW COLUMNS FROM evaluation_periods LIKE 'academic_year'");
if ($ayCol && $ayCol->num_rows > 0 && $activePeriodRow) {
    $ayRow = $mysqli->query("SELECT academic_year FROM evaluation_periods WHERE id=" . (int)$activePeriodRow['id'] . " LIMIT 1")->fetch_assoc();
    $academic_year_label = $ayRow['academic_year'] ?? null;
}
if (!$academic_year_label) {
    $ayStart = ((int)date('n') >= 6) ? (int)date('Y') : (int)date('Y') - 1;
    $academic_year_label = $ayStart . '-' . ($ayStart + 1);
}

// ── EA NOTICES (bell) ───────────────────────────────────────────────
// What the Executive Assistant has set: academic period and evaluation
// schedule. Each id embeds the announced values, so an EA change creates a
// new unread notice and unchanged settings are never re-announced. Read
// state is kept per browser (localStorage), like the other dashboards.
$student_bell_notices = [];
if (!empty($active_period_semester)) {
    $bellPeriod = $academic_year_label . ' · ' . $active_period_semester;
    $student_bell_notices[] = [
        'id'   => 'ea-period-' . substr(md5($bellPeriod), 0, 10),
        'text' => 'The Executive Assistant set the academic period to ' . $bellPeriod . '.',
        'icon' => 'fa-calendar-days',
    ];
}
if ($period_opens_label !== 'Not set' && $period_closes_label !== 'Not set') {
    $bellRange = $period_opens_label . ' – ' . $period_closes_label;
    $student_bell_notices[] = [
        'id'   => 'ea-schedule-' . substr(md5($bellRange), 0, 10),
        'text' => 'The Executive Assistant set the evaluation schedule: ' . $bellRange . '.',
        'icon' => 'fa-clock',
    ];
}

// Academic Structure: derived from the period's own semester value --
// a 'School Year' period is the once-per-year Basic Ed (JHS/SHS) window;
// anything else (1st/2nd Semester, Summer) is the per-term Higher Ed
// (College) window. Mirrors the exact check already used for
// $is_school_year_period above.
$academic_structure_label = (trim((string)$active_period_semester) === 'School Year') ? 'Basic Education' : 'Higher Education';

$ctxCol=$mysqli->query("SHOW COLUMNS FROM evaluation_tracker LIKE 'evaluation_context'");
if ($ctxCol && $ctxCol->num_rows===0) {
    $mysqli->query("ALTER TABLE evaluation_tracker ADD COLUMN evaluation_context VARCHAR(30) NOT NULL DEFAULT 'teacher'");
    $mysqli->query("UPDATE evaluation_tracker et JOIN users u ON u.id=et.target_user_id SET et.evaluation_context=CASE WHEN u.role IN ('principal','dean') THEN 'school_head' WHEN u.role='staff' THEN 'staff' WHEN u.role='teacher' THEN 'teacher' ELSE et.evaluation_context END");
}

// ── HANDLE EVALUATION SUBMISSION ─────────────────────────────
$submit_error   = '';
$submit_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_evaluation'])) {
    $target_id = intval($_POST['target_user_id']);
    $evaluation_context = strtolower(trim($_POST['evaluation_context'] ?? 'teacher'));
    if (!in_array($evaluation_context,['teacher','staff','school_head'],true)) $evaluation_context='teacher';

    // SERVER-SIDE ELIGIBILITY RE-CHECK. The Evaluate view only ever renders
    // buttons for people already filtered into $all_users below, but
    // nothing previously stopped a POST with an arbitrary target_user_id
    // from being submitted directly, bypassing that filter entirely.
    // canStudentEvaluateTarget() (defined above) re-runs the EXACT same
    // rule the display query uses -- Teacher/Teaching-Staff must match
    // the student's education level + year level via teaching_assignments
    // or user_year_levels; non-teaching staff (zero user_year_levels
    // rows, ever) is always eligible; Principal/Dean is a singleton
    // bypass -- so a manually changed target_id can never slip through
    // with a person the student isn't actually authorized to evaluate.
    [$eligible, $eligibility_result] = ($target_id > 0) ? canStudentEvaluateTarget($mysqli,$student_level,$student_year_level,$target_id,$evaluation_context,$active_period_semester) : [false,null];
    $ctxStmt=$mysqli->prepare("SELECT role,secondary_role,designation FROM users WHERE id=? AND is_active=1 LIMIT 1");
    $ctxStmt->bind_param('i',$target_id); $ctxStmt->execute(); $ctxRow=$ctxStmt->get_result()->fetch_assoc(); $ctxStmt->close();
    if ($ctxRow) $ctxRow['id'] = $target_id;
    $has_teacher_context=$ctxRow && isTeachingPerson($mysqli,$ctxRow);
    $has_staff_context=$ctxRow && ec_has_staff_function($ctxRow);
    $context_allowed=($evaluation_context==='teacher'&&$has_teacher_context)||($evaluation_context==='staff'&&$has_staff_context)||($evaluation_context==='school_head'&&$ctxRow&&in_array($ctxRow['role'],['principal','dean'],true));

    if ($target_id <= 0) {
        $submit_error = "Invalid submission data. Please try again.";
    } elseif (!$eligible) {
        $submit_error = $eligibility_result;
    } elseif (!$context_allowed) {
        $submit_error = 'This evaluation context is not available for this person.';
    } elseif (!$period_is_open) {
        $submit_error = "No evaluation period is currently open. Please check back later.";
    } else {
        $period_id = (int)$activePeriodRow['id'];

        $chk = $mysqli->prepare(
            "SELECT id FROM evaluation_tracker WHERE evaluator_id=? AND target_user_id=? AND period_id=? AND evaluation_context=?"
        );
        $chk->bind_param("iiis",$student_id,$target_id,$period_id,$evaluation_context);
        $chk->execute();
        $chk->store_result();
        $already_done = $chk->num_rows > 0;
        $chk->close();

        if ($already_done) {
            $submit_error = "You have already evaluated this person this period.";
        } else {
            $ratings = $_POST['rating'] ?? [];
            $comment = trim($_POST['comment'] ?? '');

            // Build the exact set of question keys that this evaluation should contain.
            // This mirrors the same question sources used by the AJAX loader above, so
            // a crafted POST cannot submit a partial evaluation (or unrelated question ids).
            $expected_question_keys = [];
            try {
                if ($evaluation_context === 'teacher') {
                    $expected_questions = qn_get_faculty_questions($mysqli);
                    foreach ($expected_questions as $eq) {
                        $expected_question_keys[] = 'evaluation:' . (int)$eq['id'];
                    }
                } elseif ($evaluation_context === 'staff') {
                    $expected_questions_stmt = $mysqli->prepare(
                        "SELECT id FROM user_questions
                         WHERE user_id=? AND target_type='Staff' AND eval_type='general'
                         ORDER BY category,id"
                    );
                    $expected_questions_stmt->bind_param('i', $target_id);
                    $expected_questions_stmt->execute();
                    $expected_rows = $expected_questions_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $expected_questions_stmt->close();
                    foreach ($expected_rows as $eq) {
                        $expected_question_keys[] = 'user:' . (int)$eq['id'];
                    }
                } elseif ($evaluation_context === 'school_head') {
                    $expected_target_type = (($ctxRow['role'] ?? '') === 'dean') ? 'Dean' : 'Principal';
                    $expected_questions_stmt = $mysqli->prepare(
                        "SELECT id FROM user_questions
                         WHERE user_id=? AND target_type=? AND eval_type='general'
                         ORDER BY category,id"
                    );
                    $expected_questions_stmt->bind_param('is', $target_id, $expected_target_type);
                    $expected_questions_stmt->execute();
                    $expected_rows = $expected_questions_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $expected_questions_stmt->close();
                    foreach ($expected_rows as $eq) {
                        $expected_question_keys[] = 'user:' . (int)$eq['id'];
                    }
                }
            } catch (Throwable $qe) {
                $submit_error = 'Unable to verify the evaluation questions. Please close and try again.';
            }

            if (empty($submit_error)) {
                $submitted_question_keys = array_keys(is_array($ratings) ? $ratings : []);
                $missing_question_keys = array_values(array_diff($expected_question_keys, $submitted_question_keys));
                $unexpected_question_keys = array_values(array_diff($submitted_question_keys, $expected_question_keys));

                if (empty($expected_question_keys)) {
                    $submit_error = "No evaluation questions are configured for this person yet.";
                } elseif ($unexpected_question_keys) {
                    $submit_error = "Invalid evaluation question data. Please close and try again.";
                } elseif ($missing_question_keys) {
                    $missing_count = count($missing_question_keys);
                    $submit_error = "Please answer all questions before submitting. You have {$missing_count} unanswered question" . ($missing_count === 1 ? '' : 's') . " remaining.";
                } elseif ($comment === '') {
                    // Strict rule: a written comment is mandatory. Enforced here
                    // as well as in the form so a crafted POST can't skip it.
                    $submit_error = "Please write a comment before submitting. Comments, suggestions and areas for improvement are required.";
                }
            }

            if (empty($submit_error)) {
                try {
                    $mysqli->begin_transaction();

                    $trk = $mysqli->prepare(
                        "INSERT INTO evaluation_tracker (evaluator_id,target_user_id,remarks,eval_type,period_id,evaluation_context,status,submitted_at) VALUES (?, ?, ?, 'student', ?, ?, 'submitted', NOW())"
                    );
                    $trk->bind_param("iisis",$student_id,$target_id,$comment,$period_id,$evaluation_context);
                    $trk->execute();
                    $tracker_id = $mysqli->insert_id;
                    $trk->close();

                    // NOTE: `rating[q_id]` keys come straight from whichever
                    // table loadQuestions() pulled them from (evaluation_questions
                    // Faculty/Teacher questions come from evaluation_questions;
                    // Staff questions come from user_questions. The answer stores
                    // the source so the question id spaces remain unambiguous.
$ins = $mysqli->prepare(
    "INSERT INTO questionnaire_answers
     (
        tracker_id,
        question_id,
        question_source,
        user_question_id,
        answer_score,
        submitted_at
     )
     VALUES (?, ?, ?, ?, ?, NOW())"
);

foreach ($ratings as $question_key => $rating) {

    /*
     * Question keys now look like:
     *   evaluation:15
     *   user:7
     */

    $parts = explode(':', $question_key, 2);

    if (count($parts) !== 2) {
        throw new Exception("Invalid question reference.");
    }

    $source = $parts[0];
    $q_id   = intval($parts[1]);

    if (!in_array($source, ['evaluation', 'user'], true) || $q_id <= 0) {
        throw new Exception("Invalid question reference.");
    }

    $score = max(1, min(5, floatval($rating)));

    $question_id      = null;
    $user_question_id = null;

    if ($source === 'evaluation') {
        $question_id = $q_id;
    } else {
        $user_question_id = $q_id;
    }

    $ins->bind_param(
        "iisid",
        $tracker_id,
        $question_id,
        $source,
        $user_question_id,
        $score
    );

    if (!$ins->execute()) {
        throw new Exception(
            "Failed to save questionnaire answer: " . $ins->error
        );
    }
}

$ins->close();

                    $mysqli->commit();
                    $submit_success = "Evaluation submitted successfully. Thank you!";

                } catch (Exception $e) {
                    $mysqli->rollback();
                    $submit_error = "Submission failed: " . $e->getMessage();
                }
            }
        }
    }
}

// ── HANDLE SETTINGS: PROFILE DETAILS ─────────────────────────
$profile_error   = '';
$profile_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!csrf_valid($_POST['csrf_token'] ?? '')) {
        $profile_error = "Your session expired. Please refresh the page and try again.";
    } else {
        $new_full_name  = trim($_POST['full_name'] ?? '');
        $new_username   = trim($_POST['username'] ?? '');
        $new_year_level = trim($_POST['year_level'] ?? '');

        if ($new_full_name === '') {
            $profile_error = "Full name is required.";
        } elseif (mb_strlen($new_full_name) > 100) {
            $profile_error = "Full name is too long.";
        } elseif ($new_username === '') {
            $profile_error = "Username is required.";
        } elseif (mb_strlen($new_username) > 50) {
            $profile_error = "Username is too long.";
        } elseif (preg_match('/\s/', $new_username)) {
            $profile_error = "Username cannot contain spaces.";
        } elseif (!empty($year_level_options) && !in_array($new_year_level, $year_level_options, true)) {
            $profile_error = "Please select a valid year/grade level.";
        } elseif ($new_year_level !== $student_year_level && !$year_level_editable) {
            // Real gate lives here, not just in the disabled <select> below --
            // disabling the field client-side doesn't stop a crafted POST.
            // Only blocks actual changes; re-saving other profile fields with
            // the same year level still goes through even while locked.
            $profile_error = $year_level_locked_reason ?: "Year level updates are currently closed.";
        } else {
            // Username must stay unique across all accounts, not just students.
            $dupChk = $mysqli->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
            $dupChk->bind_param("si", $new_username, $student_id);
            $dupChk->execute();
            $dupChk->store_result();
            if ($dupChk->num_rows > 0) {
                $profile_error = "That username is already taken. Please choose another.";
                $dupChk->close();
            } else {
                $dupChk->close();
                $upd = $mysqli->prepare("UPDATE users SET full_name = ?, username = ?, year_level = ? WHERE id = ? AND role = 'student' LIMIT 1");
                $upd->bind_param("sssi", $new_full_name, $new_username, $new_year_level, $student_id);
                $upd->execute();
                $upd->close();

                // Keep everything the rest of this page reads from in sync
                // for the current render, and refresh the session so the
                // sidebar/header (which read $_SESSION['full_name']) and the
                // next login (which reads username) reflect the change too.
                $student_name              = $new_full_name;
                $student_username          = $new_username;
                $student_year_level        = $new_year_level;
                $_SESSION['full_name']     = $new_full_name;
                $profile_success           = "Profile updated.";
            }
        }
    }
}

// ── HANDLE SETTINGS: CHANGE PASSWORD ──────────────────────────
$password_error   = '';
$password_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password      = $_POST['new_password']      ?? '';
    $confirm_password  = $_POST['confirm_password']  ?? '';

    if (!csrf_valid($_POST['csrf_token'] ?? '')) {
        $password_error = "Your session expired. Please refresh the page and try again.";
    } elseif (auth_is_locked($mysqli, 'pwchange', (string)$student_id)) {
        $password_error = AUTH_LOCK_MESSAGE;
    } else {
        $pwStmt = $mysqli->prepare("SELECT username, password_hash FROM users WHERE id = ? AND role = 'student' LIMIT 1");
        $pwStmt->bind_param("i", $student_id);
        $pwStmt->execute();
        $pwRow = $pwStmt->get_result()->fetch_assoc();
        $pwStmt->close();

        if (!$pwRow || !password_verify($current_password, $pwRow['password_hash'] ?? '')) {
            auth_record($mysqli, 'pwchange', (string)$student_id, false);
            $password_error = "Current password is incorrect.";
        } elseif (strlen($new_password) < 8) {
            $password_error = "New password must be at least 8 characters.";
        } elseif (strlen($new_password) > 72) {
            $password_error = "New password must be 72 characters or fewer.";
        } elseif (mb_strtolower($new_password) === mb_strtolower($pwRow['username'])) {
            $password_error = "Your password cannot be the same as your username.";
        } elseif ($new_password !== $confirm_password) {
            $password_error = "New passwords do not match.";
        } elseif (password_verify($new_password, $pwRow['password_hash'] ?? '')) {
            $password_error = "New password must be different from your current password.";
        } else {
            auth_clear($mysqli, 'pwchange', (string)$student_id);
            $newHash = password_hash($new_password, PASSWORD_DEFAULT);
            $upd = $mysqli->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'student' LIMIT 1");
            $upd->bind_param("si", $newHash, $student_id);
            $upd->execute();
            $upd->close();
            $password_success = "Password updated successfully.";
        }
    }
}

$sq_flash = (string)($_SESSION['sq_flash'] ?? ''); unset($_SESSION['sq_flash']);
$land_on_settings = ($profile_error || $profile_success || $password_error || $password_success || $sq_flash !== '');

// ── SECURITY QUESTIONS STATUS (for the Settings card) ────────
$sq_status = 'none'; // none | ok | retired
try {
    $sq_existing = sq_load($mysqli, (int)$_SESSION['user_id']);
    if (count($sq_existing) === 3) { $sq_status = sq_needs_update($sq_existing) ? 'retired' : 'ok'; }
} catch (Throwable $e) { error_log('dashboard sq_load failed: ' . $e->getMessage()); }

// ── FETCH QUESTIONS FOR A TARGET (AJAX) ──────────────────────
// Reads from the same question source the admin assigns for this
// person's student-evaluation context:
//   - Staff   -> user_questions (per-person, target_type='Staff')
//   - Teacher -> qn_get_faculty_questions() -- the same shared-bank helper
//                the admin Faculty question-bank editor itself calls
if (isset($_GET['get_submission'])) {
    // Read-only: the logged-in student's OWN submitted evaluation for this
    // person in the current period (evaluator_id is always the session user).
    header('Content-Type: application/json; charset=utf-8');
    $out = ['success' => false, 'error' => 'No submitted evaluation was found.'];
    try {
        $vt_id  = intval($_GET['target_id'] ?? 0);
        $vt_ctx = strtolower(trim($_GET['context'] ?? 'teacher'));
        if (!in_array($vt_ctx, ['teacher','staff','school_head'], true)) $vt_ctx = 'teacher';
        if ($vt_id > 0 && $period_is_open) {
            $vt_period = (int)$activePeriodRow['id'];
            $vs = $mysqli->prepare("SELECT id, remarks, submitted_at FROM evaluation_tracker
                                    WHERE evaluator_id=? AND target_user_id=? AND period_id=? AND evaluation_context=?
                                    ORDER BY submitted_at DESC, id DESC LIMIT 1");
            $vs->bind_param('iiis', $student_id, $vt_id, $vt_period, $vt_ctx);
            $vs->execute();
            $vtrk = $vs->get_result()->fetch_assoc();
            $vs->close();
            if ($vtrk) {
                $va = $mysqli->prepare("SELECT qa.answer_score AS score,
                                               COALESCE(uq.question_text, eq.question_text) AS question,
                                               COALESCE(uq.category, eq.category, 'General') AS category
                                        FROM questionnaire_answers qa
                                        LEFT JOIN user_questions uq ON qa.question_source='user' AND uq.id=qa.user_question_id
                                        LEFT JOIN evaluation_questions eq ON qa.question_source='evaluation' AND eq.id=qa.question_id
                                        WHERE qa.tracker_id=?
                                        ORDER BY category, COALESCE(eq.id,0), qa.id");
                $vtid = (int)$vtrk['id'];
                $va->bind_param('i', $vtid);
                $va->execute();
                $vrows = $va->get_result()->fetch_all(MYSQLI_ASSOC);
                $va->close();
                $vsum = 0; $vans = [];
                foreach ($vrows as $vr) {
                    $vsum += (float)$vr['score'];
                    $vans[] = ['category' => (string)$vr['category'], 'question' => (string)$vr['question'], 'score' => (float)$vr['score']];
                }
                $out = [
                    'success'      => true,
                    'score'        => count($vans) ? round($vsum / count($vans), 2) : null,
                    'submitted_at' => $vtrk['submitted_at'] ? date('M j, Y g:i A', strtotime($vtrk['submitted_at'])) : '',
                    'comment'      => (string)($vtrk['remarks'] ?? ''),
                    'answers'      => $vans,
                ];
            }
        }
    } catch (Throwable $ve) {
        $out = ['success' => false, 'error' => 'Could not load your submitted evaluation.'];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (isset($_GET['get_questions'])) {
    header('Content-Type: application/json');
    try {
        $target_id = intval($_GET['target_id']);

        $userRow = $mysqli->query(
            "SELECT designation, role, secondary_role FROM users WHERE id=$target_id AND is_active=1 LIMIT 1"
        )->fetch_assoc();

        if (!$userRow) throw new Exception("User not found.");
        $userRow['id'] = $target_id;

        $designation = $userRow['designation'] ?? '';
        $role        = $userRow['role'] ?? 'teacher';

        // ── PRINCIPAL / DEAN → SCHOOL HEAD ───────────────────────
        // Questionnaire stores Principal and Dean as per-user question sets.
        // IMPORTANT: this must read the STUDENT-EVALUATION pool
        // (eval_type='student'), which is what the EA assigns under
        // Questionnaire → Student Evaluation → School Head → Principal/Dean.
        // eval_type='school_head' is a DIFFERENT, unrelated pool used only
        // when the EA evaluates the Principal/Dean directly (Questionnaire
        // → School Head Evaluation). Reading that pool here was the bug:
        // it made students see questions the EA never assigned for student
        // evaluation, just because the EA's-own-evaluation pool happened
        // to have rows in it.
        if (in_array($role, ['principal', 'dean'], true)) {
            // Same department rule as canStudentEvaluateTarget(): College
            // students may only fetch Dean questions, JHS/SHS students may
            // only fetch Principal questions. Without this check, a College
            // student could still pull up the Principal's question set (or
            // vice versa) by requesting this endpoint directly with the
            // right target_id, even though that tab is hidden from them.
            $is_college_student = isCollegeEducationLevel($student_level);
            $is_jhs_shs_student = in_array(strtolower(trim((string)$student_level)), ['junior_high', 'senior_high'], true);
            if ($role === 'dean' && !$is_college_student) {
                throw new Exception('This person is not available for evaluation.');
            }
            if ($role === 'principal' && !$is_jhs_shs_student) {
                throw new Exception('This person is not available for evaluation.');
            }

            $pd_target_type = ucfirst($role);
            $pdq = $mysqli->prepare(
                "SELECT id, question_text, category,
                        'user' AS question_source
                 FROM user_questions
                 WHERE user_id = ? AND target_type = ? AND eval_type = 'general'
                 ORDER BY category, id"
            );
            $pdq->bind_param("is", $target_id, $pd_target_type);
            $pdq->execute();
            $pd_questions = $pdq->get_result()->fetch_all(MYSQLI_ASSOC);
            $pdq->close();

            if (empty($pd_questions)) {
                throw new Exception(
                    "No questions have been set up for this person yet. " .
                    "Please ask the admin to add questions under Questionnaire → Dean / Principal → $pd_target_type."
                );
            }

            echo json_encode(['success' => true, 'questions' => $pd_questions]);
            exit;
        }

        $requested_context = $_GET['context'] ?? '';
        $has_teacher_context = isTeachingPerson($mysqli, $userRow);
        $has_staff_context   = ec_has_staff_function($userRow);
        $has_assignment      = hasAnyYearLevelAssignment($mysqli, $target_id);

        if ($requested_context === 'staff') {
            // Staff context is non-teaching staff only.
            if (!$has_staff_context || $has_assignment) {
                throw new Exception('This person is not configured for a Staff evaluation.');
            }
            $q = $mysqli->prepare(
                "SELECT id,question_text,category,'user' AS question_source
                 FROM user_questions
                 WHERE user_id=? AND target_type='Staff' AND eval_type='general'
                 ORDER BY category,id"
            );
            $q->bind_param('i',$target_id);
            $q->execute();
            $questions=$q->get_result()->fetch_all(MYSQLI_ASSOC);
            $q->close();
            if (empty($questions)) throw new Exception('No Staff questions have been set up for this person yet.');
            echo json_encode(['success'=>true,'questions'=>$questions]); exit;
        }

        // Default personnel context is Teacher / Faculty.
        if ($requested_context !== 'teacher') {
            throw new Exception('Invalid evaluation context.');
        }
        if (!$has_teacher_context) {
            throw new Exception('This person is not configured for a Teacher evaluation.');
        }
        // Pulled straight from the same helper the admin Faculty question-bank
        // editor uses (QuestionnaireService.php: qn_get_faculty_questions()),
        // instead of a hand-rolled query here duplicating its filter logic.
        // This guarantees the student modal always shows exactly what the
        // admin bank shows -- no separate WHERE clause to fall out of sync.
        $questions = array_map(
            static function (array $q): array {
                $q['question_source'] = 'evaluation';
                return $q;
            },
            qn_get_faculty_questions($mysqli)
        );
        if (empty($questions)) throw new Exception('No Teacher questions have been set up yet.');

        echo json_encode(['success'=>true,'questions'=>$questions]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}


// ── FETCH EVALUATEES ──────────────────────────────────────────────
// Build contexts per person instead of first filtering people and then
// guessing their category.  This is the authoritative student visibility rule.
// Faculty, Staff, Principal, and Dean are all independent top-level
// categories, each shown as its own tab.
$grouped = ['Faculty'=>[], 'Staff'=>[]];

$ures = $mysqli->query("SELECT id, full_name, designation, photo, role, secondary_role, assigned_period
                        FROM users
                        WHERE role IN ('teacher','staff','faculty')
                          AND is_active=1
                          AND (account_status='approved' OR source='admin_nologin')
                        ORDER BY full_name ASC");
if (!$ures) throw new Exception('Failed to load evaluation personnel: '.$mysqli->error);

$level_variants = normalizeLevelVariants($student_level);
foreach ($ures->fetch_all(MYSQLI_ASSOC) as $u) {
    $has_teacher = ec_has_teacher_function($u);
    $has_staff   = ec_has_staff_function($u);
    $has_assign  = hasAnyYearLevelAssignment($mysqli, (int)$u['id']);
    $base_match  = ($student_level && $student_year_level && $has_assign)
        ? isMatchedViaAssignment($mysqli, (int)$u['id'], $level_variants, $student_year_level)
        : false;

    // Faculty includes actual Teachers and Staff members who carry a real
    // teaching/year-level assignment. College faculty also respects the
    // active semester.
    $semester_match = teacherMatchesActiveSemester($mysqli, (int)$u['id'], $student_level, $active_period_semester);
    $is_teaching_person = $has_teacher || ($has_staff && $has_assign);
    if ($is_teaching_person && $base_match && $semester_match) {
        $grouped['Faculty'][] = $u;
    }

    // Staff means non-teaching staff only. A person with a teaching
    // assignment belongs in Faculty, not Staff.
    if ($has_staff && !$has_assign) {
        $grouped['Staff'][] = $u;
    }
}

// ── SINGLETON PRINCIPAL / DEAN CATEGORIES ─────────────────────
// Principal and Dean aren't tied to teaching_assignments/user_year_levels
// like Teacher/Staff -- there's exactly ONE active user per role. Unlike
// Teacher/Staff though, they are NOT both open to every student: a
// student only ever evaluates the school head of their own department.
//   • College students          -> Dean only. Principal must not appear.
//   • Junior High / Senior High -> Principal only. Dean must not appear.
// A student whose education_level is neither (e.g. unset) sees neither
// tab -- there's no school head defined for them to evaluate.
// Each eligible role gets its own top-level category (Principal, Dean),
// shown as its own tab alongside Faculty and Staff, with the person's
// `designation` stamped as their role ("Principal"/"Dean") so they're
// distinguishable on the card -- but only when designation is blank, so
// an admin-set title (e.g. "Principal, Senior High") isn't clobbered.
$is_college_student = isCollegeEducationLevel($student_level);
$is_jhs_shs_student  = in_array(strtolower(trim((string)$student_level)), ['junior_high', 'senior_high'], true);
$eligible_school_head_roles = [];
if ($is_jhs_shs_student)  $eligible_school_head_roles['principal'] = 'Principal';
if ($is_college_student)  $eligible_school_head_roles['dean']      = 'Dean';

foreach ($eligible_school_head_roles as $role_value => $role_label) {
    $singleton = $mysqli->prepare(
        "SELECT id, full_name, designation, photo, role
         FROM users
         WHERE role = ? AND is_active = 1
         LIMIT 1"
    );
    $singleton->bind_param("s", $role_value);
    $singleton->execute();
    $singleton_row = $singleton->get_result()->fetch_assoc();
    $singleton->close();

    if ($singleton_row) {
        if (trim($singleton_row['designation'] ?? '') === '') {
            $singleton_row['designation'] = $role_label;
        }
        $all_users[]            = $singleton_row;
        $grouped[$role_label]   = [$singleton_row];
    }
}

// ── QUESTION-SET AVAILABILITY PRECHECK ─────────────────────────
// A person with zero configured questions for their evaluation context
// used to still show an "Evaluate" button -- the student would open the
// modal, hit a dead-end "No questions have been set up..." error, and
// nothing would ever get submitted, with no signal to the student (or
// the admin) that anything was wrong. This stamps `_has_questions` onto
// every person in $grouped so the button can be disabled up front
// instead of failing after the fact.
//
// Teacher/Faculty draws from the same shared Faculty question bank the
// admin editor manages, via qn_get_faculty_questions() -- same source as
// the actual fetch below, so this precheck can never drift out of sync
// with what the modal ends up showing. Staff, Principal, and Dean are
// per-person sets in user_questions, so they're looked up individually.
$facultyHasQuestions = count(qn_get_faculty_questions($mysqli)) > 0;

$perUserQCounts = [];
$puq = $mysqli->query(
    "SELECT user_id, target_type, COUNT(*) AS c
     FROM user_questions
     WHERE eval_type='general' AND target_type IN ('Staff','Principal','Dean')
     GROUP BY user_id, target_type"
);
if ($puq) {
    while ($row = $puq->fetch_assoc()) {
        $perUserQCounts[(int)$row['user_id']][$row['target_type']] = (int)$row['c'];
    }
}

if (!empty($grouped['Faculty'])) {
    foreach ($grouped['Faculty'] as &$fp) { $fp['_has_questions'] = $facultyHasQuestions; }
    unset($fp);
}
if (!empty($grouped['Staff'])) {
    foreach ($grouped['Staff'] as &$sp) {
        $sp['_has_questions'] = ($perUserQCounts[(int)$sp['id']]['Staff'] ?? 0) > 0;
    }
    unset($sp);
}
foreach (['Principal', 'Dean'] as $pd_label) {
    if (!empty($grouped[$pd_label])) {
        foreach ($grouped[$pd_label] as &$hp) {
            $hp['_has_questions'] = ($perUserQCounts[(int)$hp['id']][$pd_label] ?? 0) > 0;
        }
        unset($hp);
    }
}

// Drop any category that ended up with nobody in it, so students only
// see categories with people in them. Faculty, Staff, Principal, and
// Dean are all independent -- any one can be empty while the others
// still show.
if (empty($grouped['Faculty']))   unset($grouped['Faculty']);
if (empty($grouped['Staff']))     unset($grouped['Staff']);
if (empty($grouped['Principal'])) unset($grouped['Principal']);
if (empty($grouped['Dean']))      unset($grouped['Dean']);

// ── FETCH ALREADY EVALUATED IDs (CURRENT PERIOD ONLY) ─────────
// evaluation_tracker.period_id scopes a submission to one evaluation
// period, and the submit handler's dedupe check above is already
// period-scoped (evaluator_id + target_user_id + period_id). But this
// lookup previously had NO period_id filter -- it pulled every person
// this student has EVER evaluated, in any past period. That meant once
// a period ended and a new one opened, every teacher/staff member would
// still show as "Done" (Evaluate button hidden, Completed/Pending/
// Progress stuck) forever, even though a fresh evaluation for the new
// period was both expected and allowed by the backend. Scoping this to
// $activePeriodRow's id makes "Done" reset for each new period.
$done_ids=[];
if($period_is_open){
    $current_period_id=(int)$activePeriodRow['id'];
    $dres=$mysqli->prepare("SELECT target_user_id,evaluation_context FROM evaluation_tracker WHERE evaluator_id=? AND period_id=?");
    $dres->bind_param('ii',$student_id,$current_period_id); $dres->execute(); $dres->bind_result($done_target_id,$done_context);
    while($dres->fetch()) $done_ids[$done_target_id.'|'.($done_context?:'teacher')]=true;
    $dres->close();
}

// ── FETCH EVALUATION HISTORY (for the History view) ──────────
// One row per past submission by this student, with the target's
// name/photo/designation and the average score they gave, so the
// History view doesn't need another round trip.
$history = [];
$hres = $mysqli->prepare(
    "SELECT et.id, et.target_user_id, u.full_name, u.designation, u.photo,
            et.remarks, et.status, et.submitted_at,
            AVG(qa.answer_score) AS avg_score, COUNT(qa.id) AS answer_count
     FROM evaluation_tracker et
     JOIN users u ON u.id = et.target_user_id
     LEFT JOIN questionnaire_answers qa ON qa.tracker_id = et.id
     WHERE et.evaluator_id = ?
     GROUP BY et.id
     ORDER BY et.submitted_at DESC"
);
$hres->bind_param("i", $student_id);
$hres->execute();
$history = $hres->get_result()->fetch_all(MYSQLI_ASSOC);
$hres->close();

// ── GROUP ICONS / COLORS ──
$group_icons = [
    'Faculty'            => 'fa-people-group',
    'Staff'              => 'fa-briefcase',
    'Principal'          => 'fa-user-tie',
    'Dean'               => 'fa-user-graduate',
];
$group_colors = [
    'Faculty'            => '#00E5FF',
    'Teacher'            => '#00E5FF',
    'Staff'              => '#10b981',
    'Principal'          => '#8B5CF6',
    'Dean'               => '#F59E0B',
];

// Progress counts evaluation contexts, not unique accounts.
$total_evaluatees=count($grouped['Faculty']??[])+count($grouped['Staff']??[])+count($grouped['Principal']??[])+count($grouped['Dean']??[]);
// Count only submissions that match someone on this student's CURRENT evaluation list (same
// person + context match the cards and group progress use). $done_ids can also hold submissions
// for people no longer on the list or for another context, which previously pushed Completed
// above To Evaluate (e.g. 13 of 7 = 186%).
$total_done = 0;
foreach (['Faculty' => 'teacher', 'Staff' => 'staff', 'Principal' => 'school_head', 'Dean' => 'school_head'] as $done_group => $done_ctx) {
    foreach (($grouped[$done_group] ?? []) as $done_person) {
        if (isset($done_ids[$done_person['id'] . '|' . $done_ctx])) $total_done++;
    }
}
$total_pending     = max(0, $total_evaluatees - $total_done);
$pct              = $total_evaluatees > 0 ? min(100, (int)round(($total_done / $total_evaluatees) * 100)) : 0;

// ── LIVE NOTICES (bell): evaluation status + the student's own progress ──
// Ids embed the values they announce, so a change (opened/closed, one more
// evaluation done) creates a fresh unread notice and unchanged state is
// never re-announced.
$live_notices = [];
$live_notices[] = [
    'id'   => 'eval-status-' . substr(md5(($period_is_open ? 'open' : 'closed') . '|' . $period_closes_short), 0, 10),
    'text' => $period_is_open
        ? 'Evaluation is open' . ($period_closes_short !== 'Not set' ? ' and closes on ' . $period_closes_short . '.' : '.')
        : 'Evaluation is currently closed.',
    'icon' => $period_is_open ? 'fa-lock-open' : 'fa-lock',
];
if ($period_is_open && $total_evaluatees > 0) {
    $live_notices[] = [
        'id'   => 'eval-progress-' . substr(md5(((int)($activePeriodRow['id'] ?? 0)) . "|$total_done|$total_evaluatees"), 0, 10),
        'text' => $total_pending > 0
            ? "You have $total_pending evaluation" . ($total_pending !== 1 ? 's' : '') . " left to complete ($total_done of $total_evaluatees done)."
            : 'You have completed all your evaluations. Thank you!',
        'icon' => $total_pending > 0 ? 'fa-hourglass-half' : 'fa-circle-check',
    ];
}
$student_bell_notices = array_merge($live_notices, $student_bell_notices);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Student Evaluation</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link rel="stylesheet" href="student_ui.css"/>
<style>
:root{
    --dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;
    --gold:#D97706;--gold-h:#F59E0B;
    --light:#E0E6F0;--muted:#A0B3C6;
    --border:rgba(255,255,255,0.08);--radius:10px;
    --sidebar-w:230px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--dark);color:var(--light);min-height:100vh;}

/* ── TOPNAV ── */
.topnav{background:var(--mid);border-bottom:1px solid var(--border);padding:14px 32px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.nav-brand{display:flex;align-items:center;gap:12px;}
.hamburger-btn{display:none;background:none;border:1px solid var(--border);color:var(--light);font-size:16px;width:38px;height:38px;border-radius:8px;cursor:pointer;align-items:center;justify-content:center;}
.hamburger-btn:hover{border-color:var(--gold);color:var(--gold-h);}
.nav-logo{width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid var(--gold);}
.nav-right{display:flex;align-items:center;gap:16px;}

/* Profile dropdown */
.nav-profile{position:relative;}
.profile-trigger{display:flex;align-items:center;gap:10px;cursor:pointer;padding:6px 10px;border-radius:var(--radius);transition:background .2s;}
.profile-trigger:hover{background:rgba(255,255,255,.06);}
.profile-avatar{width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid var(--gold);flex-shrink:0;}
.profile-avatar-ph{width:36px;height:36px;border-radius:50%;background:var(--inner);border:2px solid var(--gold);display:flex;align-items:center;justify-content:center;color:var(--gold);font-size:15px;flex-shrink:0;}
.profile-name{font-size:13px;font-weight:600;color:var(--light);max-width:120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.profile-caret{font-size:11px;color:var(--muted);transition:transform .2s;}
.profile-dropdown{position:absolute;top:calc(100% + 8px);right:0;background:var(--mid);border:1px solid var(--border);border-radius:12px;width:240px;box-shadow:0 12px 40px rgba(0,0,0,.5);z-index:100;display:none;overflow:hidden;}
.profile-dropdown.open{display:block;animation:fadeDown .18s ease;}
@keyframes fadeDown{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.profile-dd-header{padding:16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;}
.profile-dd-avatar{width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid var(--gold);flex-shrink:0;}
.profile-dd-avatar-ph{width:44px;height:44px;border-radius:50%;background:var(--inner);border:2px solid var(--gold);display:flex;align-items:center;justify-content:center;color:var(--gold);font-size:18px;flex-shrink:0;}
.profile-dd-name{font-size:13px;font-weight:700;color:#fff;line-height:1.3;}
.profile-dd-role{font-size:11px;color:var(--muted);text-transform:capitalize;}
.profile-dd-body{padding:10px;}
.profile-dd-btn{width:100%;padding:10px 12px;border-radius:8px;border:none;background:none;color:var(--light);font-size:13px;font-family:'DM Sans',sans-serif;cursor:pointer;display:flex;align-items:center;gap:10px;transition:background .18s;text-align:left;}
.profile-dd-btn:hover{background:rgba(255,255,255,.06);}
.profile-dd-btn i{width:16px;text-align:center;color:var(--muted);}
.profile-dd-divider{height:1px;background:var(--border);margin:6px 0;}
.profile-dd-btn.logout{color:#f87171;}
.profile-dd-btn.logout i{color:#f87171;}

/* ── PHOTO MODAL ── */
.photo-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:300;display:none;align-items:center;justify-content:center;padding:20px;}
.photo-modal-overlay.open{display:flex;}
.photo-modal{background:var(--mid);border:1px solid var(--border);border-radius:18px;width:100%;max-width:400px;box-shadow:0 20px 60px rgba(0,0,0,.6);overflow:hidden;}
.photo-modal-header{padding:20px 24px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.photo-modal-title{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;color:#fff;}
.photo-modal-body{padding:24px;}
.photo-upload-circle{width:110px;height:110px;border-radius:50%;border:3px dashed rgba(217,119,6,.5);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;cursor:pointer;transition:border-color .2s;overflow:hidden;position:relative;background:var(--inner);}
.photo-upload-circle:hover{border-color:var(--gold);}
.photo-upload-circle img{width:100%;height:100%;object-fit:cover;display:none;border-radius:50%;}
.photo-upload-circle .upload-icon{color:var(--muted);font-size:32px;transition:color .2s;}
.photo-upload-circle:hover .upload-icon{color:var(--gold);}
.photo-upload-hint{text-align:center;font-size:12px;color:var(--muted);margin-bottom:20px;}
.photo-upload-hint span{color:var(--gold-h);font-weight:600;cursor:pointer;}
.photo-modal-footer{padding:0 24px 24px;display:flex;gap:10px;}
.btn-save-photo{flex:1;padding:11px;background:var(--gold);border:none;border-radius:var(--radius);color:#fff;font-size:14px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;}
.btn-save-photo:hover{background:var(--gold-h);}
.btn-skip-photo{flex:1;padding:11px;background:var(--inner);border:1px solid var(--border);border-radius:var(--radius);color:var(--muted);font-size:14px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;}

/* ── APP SHELL: SIDEBAR + MAIN ── */
.app-shell{display:flex;align-items:flex-start;}

.sidebar{width:var(--sidebar-w);flex-shrink:0;background:var(--mid);border-right:1px solid var(--border);min-height:100vh;position:sticky;top:0;padding:20px 0;}
.sb-logo-wrap{display:flex;justify-content:center;padding:4px 0 14px;}
.sb-logo{width:60px;height:60px;border-radius:50%;object-fit:cover;border:2px solid var(--gold);box-shadow:0 0 18px rgba(217,119,6,.3);}
.sb-profile{text-align:center;padding:0 18px 18px;margin-bottom:12px;border-bottom:1px solid rgba(255,255,255,.08);}
.sb-profile-name{font-size:14px;font-weight:700;color:#fff;line-height:1.35;word-break:break-word;}
.sb-profile-role{font-size:11px;color:#8ea3bd;margin-top:3px;text-transform:capitalize;}
.side-section-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1.3px;color:var(--muted);padding:0 20px;margin-bottom:8px;}
.side-nav-item{display:flex;align-items:center;gap:12px;padding:11px 20px;color:var(--light);font-size:13.5px;font-weight:600;cursor:pointer;border-left:3px solid transparent;transition:all .18s;}
.side-nav-item i{width:18px;text-align:center;color:var(--muted);font-size:15px;transition:color .18s;}
.side-nav-item:hover{background:rgba(255,255,255,.05);}
.side-nav-item.active{background:rgba(217,119,6,.12);border-left-color:var(--gold);color:#fff;}
.side-nav-item.active i{color:var(--gold-h);}
.side-nav-badge{margin-left:auto;background:rgba(217,119,6,.2);color:var(--gold-h);font-size:10px;font-weight:700;border-radius:20px;padding:2px 8px;}

.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:79;}
.sidebar-overlay.open{display:block;}

.main{flex:1;min-width:0;max-width:1000px;margin:0 auto;padding:36px 28px;}
.content-topbar{display:flex;align-items:center;margin-bottom:22px;}
.content-topbar .nav-profile{margin-left:auto;}
.page-title{font-family:'Rajdhani',sans-serif;font-size:28px;font-weight:700;color:#fff;margin-bottom:4px;}
.page-sub{font-size:13px;color:var(--muted);margin-bottom:32px;}

.view-content{display:none;}
.view-content.active{display:block;animation:fadeIn .18s ease;}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}

/* ── DASHBOARD VIEW ── */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:28px;}
.stat-card{background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;}
.stat-card i{font-size:18px;color:var(--gold-h);margin-bottom:10px;display:block;}
.stat-num{font-family:'Rajdhani',sans-serif;font-size:26px;font-weight:700;color:#fff;line-height:1;}
.stat-lbl{font-size:12px;color:var(--muted);margin-top:6px;}
.period-pill{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;border-radius:20px;padding:4px 12px;margin-bottom:20px;}
.period-pill.open{background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.3);color:#4ade80;}
.period-pill.closed{background:rgba(240,84,84,.1);border:1px solid rgba(240,84,84,.3);color:#fca5a5;}
.period-info-card{background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:22px 24px;margin-bottom:22px;}
.period-info-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px;}
.period-info-title{display:flex;align-items:center;gap:10px;font-family:'Rajdhani',sans-serif;font-size:17px;font-weight:700;color:#fff;}
.period-info-title i{color:var(--gold-h);font-size:16px;}
.period-info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:20px 24px;}
.period-info-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:6px;}
.period-info-val{font-size:14px;font-weight:700;color:#fff;line-height:1.4;}
.period-info-val.status-open{color:#4ade80;}
.period-info-val.status-closed{color:#fca5a5;}
.period-info-note{margin-top:18px;padding-top:14px;border-top:1px solid var(--border);font-size:12px;color:var(--muted);}
.period-info-note strong{color:var(--light);}
.dash-cta{background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:26px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;}
.dash-cta-icon{width:52px;height:52px;border-radius:12px;background:rgba(217,119,6,.15);display:flex;align-items:center;justify-content:center;font-size:22px;color:var(--gold-h);flex-shrink:0;}
.dash-cta-text{flex:1;min-width:180px;}
.dash-cta-text h3{font-family:'Rajdhani',sans-serif;font-size:17px;color:#fff;margin-bottom:3px;}
.dash-cta-text p{font-size:12.5px;color:var(--muted);}
.btn-primary-cta{padding:11px 22px;background:var(--gold);border:none;border-radius:var(--radius);color:#fff;font-size:13.5px;font-weight:700;cursor:pointer;white-space:nowrap;}
.btn-primary-cta:hover{background:var(--gold-h);}

.progress-wrap{background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);padding:16px 20px;margin-bottom:28px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;}
.progress-label{font-size:13px;color:var(--muted);white-space:nowrap;}
.progress-label span{color:var(--gold-h);font-weight:700;}
.progress-bar-bg{flex:1;min-width:120px;height:8px;background:rgba(255,255,255,.08);border-radius:20px;overflow:hidden;}
.progress-bar-fill{height:100%;background:linear-gradient(90deg,var(--gold),var(--gold-h));border-radius:20px;transition:width .4s ease;}
.progress-pct{font-size:12px;font-weight:700;color:var(--gold-h);white-space:nowrap;}
.alert{border-radius:8px;padding:12px 16px;font-size:13px;margin-bottom:20px;display:flex;align-items:center;gap:8px;}
.alert-success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#86efac;}
.alert-error{background:rgba(240,84,84,.1);border:1px solid rgba(240,84,84,.3);color:#fca5a5;}
.section-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-bottom:14px;}

/* ── CATEGORY CARDS ── */
.category-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:16px;margin-bottom:10px;}
.cat-btn{background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:24px 18px 20px;text-align:center;cursor:pointer;transition:all .22s;position:relative;user-select:none;}
.cat-btn:hover{transform:translateY(-3px);box-shadow:0 6px 20px rgba(0,0,0,.35);}
.cat-btn.active{transform:translateY(-3px);box-shadow:0 6px 20px rgba(0,0,0,.35);}
.cat-btn.all-done{opacity:.55;}
.cat-icon{font-size:32px;margin-bottom:11px;}
.cat-name{font-family:'Rajdhani',sans-serif;font-size:18px;font-weight:700;color:#fff;margin-bottom:3px;}
.cat-meta{font-size:12px;color:var(--muted);}
.cat-done-pill{margin-top:9px;display:inline-block;background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.3);color:#4ade80;border-radius:20px;font-size:10px;font-weight:700;padding:2px 9px;}
.cat-active-arrow{position:absolute;bottom:-10px;left:50%;transform:translateX(-50%);width:0;height:0;border-left:9px solid transparent;border-right:9px solid transparent;border-top:10px solid var(--gold);display:none;filter:drop-shadow(0 2px 4px rgba(0,0,0,.4));}
.cat-btn.active .cat-active-arrow{display:block;}

/* ── MEMBERS PANEL ── */
.members-panel{display:none;border-radius:14px;margin-bottom:32px;overflow:hidden;animation:slideDown .22s ease;background:var(--mid);border:1px solid var(--border);}
.members-panel.open{display:block;}
@keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
.panel-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;}
.panel-header-icon{font-size:16px;}
.panel-header-title{font-family:'Rajdhani',sans-serif;font-size:17px;font-weight:700;color:#fff;}
.panel-header-count{font-size:12px;color:var(--muted);margin-left:2px;}
.panel-close-btn{margin-left:auto;background:none;border:none;color:var(--muted);font-size:18px;cursor:pointer;padding:4px 8px;border-radius:6px;transition:color .2s;}
.panel-close-btn:hover{color:#fff;}

/* ── SUBGROUP (Teacher / Staff inside the Faculty panel) ── */
.subgroup-header{display:flex;align-items:center;gap:8px;padding:14px 18px 6px;font-family:'Rajdhani',sans-serif;font-size:14px;font-weight:700;color:#fff;}
.subgroup-header i{font-size:13px;}
.subgroup-header .subgroup-count{font-size:11px;font-weight:500;color:var(--muted);margin-left:2px;}
.subgroup-divider{height:1px;background:var(--border);margin:4px 18px 0;}

.members-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;padding:10px 18px 18px;}
.person-card{background:var(--inner);border:1px solid var(--border);border-radius:12px;padding:18px 14px;text-align:center;transition:all .22s;position:relative;}
.person-card:hover:not(.done){border-color:var(--gold);transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.4);}
.person-card.done{opacity:.55;}
.person-avatar{width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid var(--border);margin:0 auto 10px;display:block;background:var(--mid);}
.person-avatar-ph{width:64px;height:64px;border-radius:50%;background:var(--mid);border:2px solid var(--border);margin:0 auto 10px;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:22px;}
.person-name{font-size:13px;font-weight:600;color:#fff;margin-bottom:3px;line-height:1.3;}
.person-desig{font-size:11px;color:var(--muted);margin-bottom:2px;}
.person-teaching-line{font-size:10.5px;color:var(--gold-h);font-weight:600;margin-bottom:2px;}
.done-badge{position:absolute;top:9px;right:9px;background:rgba(34,197,94,.2);border:1px solid rgba(34,197,94,.4);color:#4ade80;border-radius:20px;font-size:10px;font-weight:700;padding:2px 8px;}
.eval-btn{margin-top:11px;width:100%;padding:8px;background:var(--gold);border:none;border-radius:7px;color:#fff;font-size:12px;font-weight:700;cursor:pointer;transition:background .2s;}
.eval-btn:hover{background:var(--gold-h);}
.eval-btn:disabled{background:var(--inner);color:var(--muted);cursor:not-allowed;}

/* ── REMINDER BANNER (Dean/EA → college students only) ── */
.reminder-banner{background:rgba(139,92,246,.1);border:1px solid rgba(139,92,246,.3);border-radius:12px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:flex-start;gap:12px;}
.reminder-banner i.bell-ic{color:#a78bfa;font-size:16px;margin-top:2px;}
.reminder-banner .rb-body{flex:1;}
.reminder-banner .rb-title{font-size:13px;font-weight:700;color:#fff;margin-bottom:2px;}
.reminder-banner .rb-msg{font-size:12.5px;color:var(--muted);line-height:1.5;}
.reminder-banner .rb-meta{font-size:11px;color:#a78bfa;margin-top:4px;}
.reminder-banner .rb-dismiss{background:none;border:none;color:var(--muted);cursor:pointer;font-size:14px;padding:4px 6px;flex-shrink:0;}
.reminder-banner .rb-dismiss:hover{color:#fff;}

/* ── HISTORY VIEW ── */
.history-list{display:flex;flex-direction:column;gap:12px;}
.history-card{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
.history-avatar{width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid var(--border);flex-shrink:0;}
.history-avatar-ph{width:48px;height:48px;border-radius:50%;background:var(--inner);border:2px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:18px;flex-shrink:0;}
.history-info{flex:1;min-width:160px;}
.history-name{font-size:14px;font-weight:700;color:#fff;}
.history-desig{font-size:11.5px;color:var(--muted);}
.history-comment{font-size:12px;color:var(--muted);margin-top:4px;font-style:italic;max-width:420px;}
.history-meta{display:flex;flex-direction:column;align-items:flex-end;gap:4px;}
.history-score{display:flex;align-items:center;gap:6px;font-family:'Rajdhani',sans-serif;font-weight:700;color:var(--gold-h);font-size:15px;}
.history-date{font-size:11px;color:var(--muted);}

/* ── GUIDELINES VIEW ── */
.gl-card{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:20px 22px;margin-bottom:16px;}
.gl-card h3{font-family:'Rajdhani',sans-serif;font-size:16px;color:#fff;margin-bottom:10px;display:flex;align-items:center;gap:8px;}
.gl-card h3 i{color:var(--gold-h);}
.gl-card p, .gl-card li{font-size:13px;color:var(--muted);line-height:1.7;}
.gl-card ul{padding-left:18px;}
.gl-scale-row{display:flex;align-items:center;gap:10px;padding:6px 0;}
.gl-scale-num{width:26px;height:26px;border-radius:6px;background:var(--gold);color:#fff;font-weight:700;font-size:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}

/* ── SETTINGS VIEW ── */
.settings-row{display:flex;align-items:center;gap:16px;flex-wrap:wrap;}
.settings-row .profile-dd-avatar,.settings-row .profile-dd-avatar-ph,.settings-row .profile-dd-avatar-logo{width:64px;height:64px;font-size:24px;}
.profile-dd-avatar-logo{width:44px;height:44px;border-radius:50%;object-fit:contain;padding:6px;background:var(--inner);border:2px solid var(--gold);flex-shrink:0;}
.settings-row .profile-dd-name{font-size:16px;}
.settings-info{flex:1;min-width:160px;}
.settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;}
.settings-field label{display:block;font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--muted);margin-bottom:6px;}
.settings-field .pw-wrap{position:relative;}
.settings-field input,.settings-field select{width:100%;padding:11px 14px;background:var(--inner);border:1px solid var(--border);border-radius:9px;color:#fff;font-size:13.5px;}
.settings-field input:focus,.settings-field select:focus{outline:none;border-color:var(--gold-h);}
.settings-field input[readonly]{color:var(--muted);cursor:not-allowed;}
.settings-field select:disabled{color:var(--muted);cursor:not-allowed;opacity:.7;}
.settings-field-note{display:flex;align-items:center;gap:6px;font-size:11px;color:var(--muted);margin-top:7px;}
.settings-field-note i{color:var(--gold-h);}
.settings-field .pw-wrap input{padding-right:40px;}
.settings-field .pw-toggle{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:14px;}
.settings-field .pw-toggle:hover{color:#fff;}
.settings-hint{font-size:11.5px;color:var(--muted);margin:-8px 0 16px;}
.appearance-choice-row{display:flex;gap:10px;flex-wrap:wrap;}
.appearance-choice{border:1px solid var(--border);background:var(--inner);color:var(--light);border-radius:10px;padding:10px 16px;font:700 12.5px 'DM Sans',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:.18s ease;}
.appearance-choice:hover{border-color:var(--gold-h);color:var(--gold-h);}
.appearance-choice.active{background:rgba(217,119,6,.14);border-color:var(--gold-h);color:var(--gold-h);}
@media(max-width:600px){.settings-grid{grid-template-columns:1fr;}}
.btn-logout{padding:11px 22px;background:rgba(220,38,38,.08);border:1px solid rgba(220,38,38,.25);border-radius:var(--radius);color:#dc2626;font-weight:700;font-size:13.5px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;text-decoration:none;transition:background .2s;}
.btn-logout:hover{background:rgba(220,38,38,.15);}

/* ── EVAL MODAL ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:200;display:none;align-items:flex-start;justify-content:center;padding:28px 16px;overflow-y:auto;}
.modal-overlay.open{display:flex;}
.modal{background:var(--mid);border:1px solid var(--border);border-radius:16px;width:100%;max-width:960px;max-height:calc(100vh - 56px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.6);}
#evalForm{display:flex;flex-direction:column;min-height:0;flex:1;}
.modal-header{padding:18px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:14px;position:sticky;top:0;background:var(--mid);z-index:1;}
.modal-avatar{width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid var(--gold);}
.modal-avatar-ph{width:56px;height:56px;border-radius:50%;background:var(--inner);border:2px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:20px;}
.modal-name{font-family:'Rajdhani',sans-serif;font-size:22px;font-weight:700;color:#fff;}
.modal-desig{font-size:13px;color:var(--muted);}
.modal-close{margin-left:auto;background:none;border:none;color:var(--muted);font-size:18px;cursor:pointer;padding:4px 8px;border-radius:6px;transition:color .2s;}
.modal-close:hover{color:#fff;}
.modal-body{padding:20px 24px;overflow-y:auto;flex:1;min-height:120px;}
.q-category{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:var(--gold-h);margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid rgba(217,119,6,.18);}
.q-item{margin-bottom:18px;background:var(--inner);border-radius:10px;padding:14px 16px;}
.q-text{font-size:14px;color:var(--light);margin-bottom:12px;line-height:1.5;display:flex;gap:6px;align-items:flex-start;}
.q-num-badge{color:var(--gold-h);font-weight:700;font-size:14px;flex-shrink:0;min-width:22px;}
.rating-row{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}
.r-btn{flex:1;min-width:66px;padding:8px 4px;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:7px;color:var(--muted);font-size:13px;font-weight:700;cursor:pointer;transition:all .18s;text-align:center;}
.r-btn:hover{border-color:var(--gold);color:var(--gold-h);}
.r-btn.selected{background:var(--gold);border-color:var(--gold);color:#fff;}
.r-val{font-size:10px;display:block;margin-top:2px;font-weight:400;}
.comment-box{margin-top:24px;background:var(--inner);border-radius:10px;padding:16px;}
.comment-label{font-size:12px;font-weight:700;color:var(--gold-h);margin-bottom:10px;display:flex;align-items:center;gap:7px;}
.comment-optional{font-size:11px;color:var(--muted);font-weight:400;}
.comment-textarea{width:100%;background:var(--dark);border:1px solid var(--border);border-radius:8px;color:var(--light);padding:12px 14px;font-size:13px;font-family:'DM Sans',sans-serif;resize:vertical;outline:none;transition:border-color .2s;line-height:1.5;}
.comment-textarea:focus{border-color:var(--gold);}
.comment-textarea::placeholder{color:#64748b;opacity:1;}
.scale-legend{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:18px;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:10px;padding:10px 14px;margin-bottom:16px;}
.legend-item{font-size:12.5px;color:var(--muted);display:flex;align-items:center;gap:5px;}
.legend-dot{width:22px;height:22px;border-radius:6px;background:var(--gold);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;flex-shrink:0;}
.modal-footer{padding:16px 24px;display:flex;gap:12px;border-top:1px solid var(--border);}
.btn-submit{flex:1;padding:13px;background:var(--gold);border:none;border-radius:var(--radius);color:#fff;font-size:15px;font-weight:700;cursor:pointer;transition:background .2s;}
.btn-submit:hover{background:var(--gold-h);}
.btn-cancel-modal{padding:13px 22px;background:var(--inner);border:1px solid var(--border);border-radius:var(--radius);color:var(--light);font-size:14px;font-weight:600;cursor:pointer;transition:background .2s;}
.btn-cancel-modal:hover{background:rgba(255,255,255,.06);}
.loading-qs{text-align:center;padding:40px;color:var(--muted);}
.loading-qs i{font-size:28px;animation:spin 1s linear infinite;display:block;margin-bottom:10px;}
.eval-validation{display:flex;align-items:flex-start;gap:9px;background:rgba(240,84,84,.11);border:1px solid rgba(240,84,84,.42);color:#ff9b9b;border-radius:9px;padding:11px 13px;margin:0 0 16px;font-size:12.5px;line-height:1.5;}
.eval-validation[hidden]{display:none!important;}
.eval-validation i{color:#ff7f7f;flex-shrink:0;margin-top:2px;}
.eval-validation strong{color:#ffd0d0;}
.eval-progress{display:flex;align-items:center;justify-content:space-between;gap:10px;background:rgba(0,229,255,.06);border:1px solid rgba(0,229,255,.16);border-radius:10px;padding:11px 16px;margin:0 0 14px;color:var(--muted);font-size:13px;font-weight:600;}
.eval-progress .progress-status{color:#00E5FF;font-weight:800;white-space:nowrap;}
.eval-form-table tr.unanswered-question td{background:rgba(240,84,84,.06);}
.eval-form-table tr.unanswered-question td:first-child{box-shadow:inset 3px 0 0 #ef4444;}
.eval-form-table tr.unanswered-question .eval-form-qtext{color:#ffd1d1;}
.eval-form-table tr.unanswered-question .eval-form-qno{color:#ff7f7f;}
@media(max-width:700px){.eval-progress{font-size:10.5px;}.eval-validation{font-size:12px;}}
@keyframes spin{to{transform:rotate(360deg)}}
.empty{text-align:center;padding:48px 20px;color:var(--muted);}
.empty i{font-size:36px;margin-bottom:12px;display:block;opacity:.3;}

@media(max-width:900px){
    .sidebar{position:fixed;top:0;left:0;height:100vh;z-index:80;transform:translateX(-100%);transition:transform .22s ease;padding-top:20px;}
    .sidebar.open{transform:translateX(0);box-shadow:0 0 40px rgba(0,0,0,.5);}
    .hamburger-btn{display:flex;}
    .main{padding:24px 16px;max-width:100%;}
}
@media(max-width:600px){
    .category-grid{grid-template-columns:1fr;}
    .members-grid{grid-template-columns:repeat(auto-fill,minmax(130px,1fr));}
    .modal-body,.modal-header,.modal-footer{padding-left:18px;padding-right:18px;}
    .content-topbar{margin-bottom:16px;}
    .progress-wrap{flex-direction:column;align-items:flex-start;gap:8px;}
    .history-meta{align-items:flex-start;width:100%;}
}

/* Compact evaluation questionnaire table — rating cells are native radio+label
   inputs (same markup pattern as the EA evaluation table), not JS-toggled
   buttons. Colors/theme are unchanged from before. */
.eval-form-table{width:100%;border-collapse:collapse;table-layout:fixed}.eval-form-table th{background:rgba(255,255,255,.04);color:var(--muted);font-size:11px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;text-align:center;padding:11px 8px;border-bottom:1px solid var(--border)}.eval-form-table th:first-child{text-align:left;width:auto;padding-left:16px}.eval-form-table th:not(:first-child){width:58px}.eval-form-table td{padding:12px 8px;border-bottom:1px solid rgba(255,255,255,.06);vertical-align:middle;text-align:center}.eval-form-table tr:last-child td{border-bottom:none}.eval-form-table td:first-child{text-align:left;padding-left:16px;padding-right:10px}.eval-form-wrap{background:var(--mid);border:1px solid var(--border);border-radius:12px;overflow-x:auto;margin:0 0 16px}.eval-form-qtext{font-size:14px;line-height:1.45;color:var(--light)}.eval-form-qno{color:#00E5FF;font-weight:800;margin-right:6px}.eval-form-rating{display:flex;justify-content:center;position:relative}.eval-form-rating input{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:1px;height:1px;margin:0;padding:0;overflow:hidden;clip-path:inset(50%);white-space:nowrap;border:0;opacity:0;pointer-events:none}.eval-form-rating input:focus-visible + label{outline:2px solid #00E5FF;outline-offset:2px}.eval-form-rating label{width:36px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:7px;border:1px solid var(--border);background:var(--inner);color:var(--muted);font-size:13px;font-weight:800;cursor:pointer;transition:all .15s ease}.eval-form-rating label:hover{border-color:#00E5FF;background:rgba(0,229,255,.08)}.eval-form-rating input:checked + label{background:#00E5FF;border-color:#00E5FF;color:#07131f}.eval-form-cat{font-size:12px;text-transform:uppercase;letter-spacing:.8px;font-weight:800;color:#00E5FF;margin:18px 0 8px}.eval-form-cat:first-child{margin-top:0}@media(max-width:768px){.eval-form-table th:not(:first-child){width:46px}.eval-form-rating label{width:30px;height:28px}}

/* ══════════════════════════════════════════════════════════════
   LIGHT THEME OVERRIDE — matches the Principal/EA dashboards'
   white workspace + dark-navy amber sidebar look. Nothing above
   this block was removed -- every class, tab, icon and piece of
   text still renders exactly as before; only the colors change.
   Scoped under body.light-theme so Dark (the page's original navy
   + gold look) is reachable again via the Appearance toggle.
   ══════════════════════════════════════════════════════════════ */
body.light-theme{
    --dark:#ffffff;--mid:#ffffff;--inner:#f5f7fb;
    --light:#172033;--muted:#64748b;--border:#e2e8f0;
    color-scheme:light;
}
body.light-theme{background:#FFFFFF!important;color:#172033!important;}

/* The sidebar stays the dark-navy "chrome" (matching the reference
   screenshot) in both Light and Dark; the rest of the page -- including
   the logo, which now lives in the sidebar -- is part of the light
   workspace only when Light is active. The .main column itself is a
   pale inset panel (#F3F6FA) so it reads as a distinct workspace sitting
   on the pure-white outer page, with the white cards (--mid) floating a
   shade lighter on top of it. */
body.light-theme .sidebar{background:#0A192F!important;border-right:1px solid #172A45!important;}
body.light-theme .main{background:#F3F6FA;border-radius:22px;margin:20px auto;min-height:calc(100vh - 40px);}
@media(max-width:900px){body.light-theme .main{margin:16px;border-radius:18px;min-height:calc(100vh - 32px);}}
body.light-theme .hamburger-btn{color:#475569!important;border-color:#e2e8f0!important;}
body.light-theme .profile-name{color:#172033!important;}
body.light-theme .profile-caret{color:#64748b!important;}
body.light-theme .profile-trigger:hover{background:rgba(15,23,42,.05)!important;}
body.light-theme .side-section-label{color:#A0B3C6!important;}
body.light-theme .side-nav-item{color:#E0E6F0!important;}
body.light-theme .side-nav-item i{color:#A0B3C6!important;}
body.light-theme .side-nav-item.active i{color:var(--gold-h)!important;}

/* Headings/labels that were hardcoded to white text for the old dark
   cards now need to read dark-on-white on the new light cards. */
body.light-theme .page-title,body.light-theme .stat-num,body.light-theme .dash-cta-text h3,body.light-theme .cat-name,body.light-theme .panel-header-title,
body.light-theme .subgroup-header,body.light-theme .person-name,body.light-theme .history-name,body.light-theme .gl-card h3,body.light-theme .modal-name,
body.light-theme .photo-modal-title,body.light-theme .profile-dd-name,body.light-theme .reminder-banner .rb-title{color:#0f172a!important;}
body.light-theme .panel-close-btn:hover,body.light-theme .modal-close:hover,body.light-theme .reminder-banner .rb-dismiss:hover{color:#0f172a!important;}

/* Settings form fields (Profile Details / Change Password) were styled
   for the old dark card too -- white text on the new near-white input
   background is unreadable, so force dark text + a light-but-visible
   input background here as well. */
body.light-theme .settings-field label{color:#64748b!important;}
body.light-theme .settings-field input,body.light-theme .settings-field select{background:#ffffff!important;border:1px solid #cbd5e1!important;color:#0f172a!important;}
body.light-theme .settings-field input::placeholder{color:#94a3b8!important;}
body.light-theme .settings-field input:focus,body.light-theme .settings-field select:focus{border-color:#D97706!important;box-shadow:0 0 0 3px rgba(217,119,6,.12)!important;}
body.light-theme .settings-field input[readonly]{background:#eef2f7!important;border-color:#e2e8f0!important;color:#64748b!important;box-shadow:none!important;}
body.light-theme .settings-field select:disabled{background:#eef2f7!important;border-color:#e2e8f0!important;color:#64748b!important;}
body.light-theme .settings-field-note{color:#64748b!important;}
body.light-theme .settings-field-note i{color:#D97706!important;}
body.light-theme .settings-field .pw-toggle{color:#94a3b8!important;}
body.light-theme .settings-field .pw-toggle:hover{color:#0f172a!important;}
body.light-theme .settings-hint{color:#64748b!important;}

/* Status pills/badges used pale, low-opacity text meant for a dark
   backdrop -- darken them so they stay legible on white/near-white. */
body.light-theme .period-pill.open,body.light-theme .cat-done-pill,body.light-theme .done-badge,body.light-theme .alert-success{color:#16a34a!important;}
body.light-theme .period-pill.closed,body.light-theme .alert-error{color:#dc2626!important;}
body.light-theme .reminder-banner .rb-meta{color:#7c3aed!important;}

/* Fill in tracks/rows that were a faint white-on-dark wash and would
   otherwise vanish (white-on-white) now that their card is white. */
body.light-theme .progress-bar-bg{background:#e2e8f0!important;}
body.light-theme .scale-legend{background:#f8fafc!important;}
body.light-theme .r-btn{background:#f8fafc!important;}
body.light-theme .profile-dd-btn:hover{background:rgba(15,23,42,.05)!important;}
body.light-theme .eval-form-table th{background:#f8fafc!important;}
body.light-theme .eval-form-table td{border-bottom:1px solid #eef2f7!important;}

/* ══════════════════════════════════════════════════════════════
   ALIGNED STUDENT WORKSPACE
   Keeps the Student Dashboard's original dark navy + gold theme,
   while adopting the shared EA dashboard layout, spacing, hierarchy,
   and card structure.
   ══════════════════════════════════════════════════════════════ */
.app-shell{align-items:stretch;min-height:100vh;background:var(--dark);}
.sidebar{width:248px;min-height:100vh;padding:20px 0 18px;position:sticky;top:0;background:#0A192F!important;border-right:1px solid #172A45!important;box-shadow:6px 0 20px rgba(0,0,0,.16);z-index:20;}
.sb-profile-wrap{padding:0 18px 18px;margin-bottom:18px;border-bottom:1px solid rgba(255,255,255,.08);text-align:center;}
.sb-avatar-wrap{width:76px;height:76px;margin:0 auto 11px;border-radius:50%;padding:3px;background:linear-gradient(135deg,var(--gold-h),var(--gold));box-shadow:0 6px 18px rgba(217,119,6,.16);}
.sb-avatar{width:70px;height:70px;border-radius:50%;object-fit:cover;border:2px solid #0A192F;display:block;background:var(--inner);}
.sb-avatar-fallback{object-fit:contain;padding:5px;}
.sb-logo-wrap{display:none;}
.sb-profile-name{font-size:15px;font-weight:700;color:#fff;line-height:1.3;word-break:break-word;}
.sb-profile-role{font-size:10px;color:var(--gold-h);margin-top:4px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;}
.side-section-label{font-size:9.5px;font-weight:800;letter-spacing:1.2px;color:#9FB2C7!important;padding:0 18px;margin:0 0 7px;text-transform:uppercase;}
.side-section-secondary{margin-top:19px;}
.side-nav-item{margin:2px 12px;padding:9px 12px;border-left:0;border-radius:8px;gap:10px;color:#DCE7F3!important;font-size:13px;font-weight:600;line-height:1.2;transition:all .18s ease;min-height:40px;}
.side-nav-item span:not(.side-nav-badge){color:inherit!important;}
.side-nav-item i{width:18px;color:#9FB2C7!important;font-size:14px;}
.side-nav-item:hover{background:rgba(255,255,255,.06)!important;color:#F7FAFC!important;}
.side-nav-item:hover i{color:#C7D6E6!important;}
.side-nav-item.active{background:rgba(217,119,6,.14)!important;color:#FFFFFF!important;border-left:0;box-shadow:inset 3px 0 0 var(--gold);}
.side-nav-item.active i{color:var(--gold-h)!important;}
.side-nav-badge{background:rgba(217,119,6,.20)!important;color:#FFD08A!important;padding:2px 7px;font-size:9px;}
.sb-profile-role{color:#C5D4E4!important;}
.main{flex:1;max-width:none;margin:18px 22px 18px 20px;padding:26px 26px 34px;background:var(--inner);border:1px solid var(--border);border-radius:22px;min-height:calc(100vh - 36px);box-shadow:0 0 0 1px rgba(255,255,255,.02);overflow:hidden;}
.content-topbar{display:block;margin-bottom:25px;}
.hamburger-btn{position:absolute;left:16px;top:18px;display:none;}
.workspace-heading{width:100%;}
.workspace-kicker{font-size:11px;font-weight:800;color:var(--muted);letter-spacing:.85px;text-transform:uppercase;margin-bottom:3px;display:flex;align-items:center;gap:6px;}
.workspace-kicker i{font-size:12px;color:var(--gold-h);}
.workspace-title-row{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;}
.workspace-title{font-family:'Rajdhani',sans-serif;font-size:29px;font-weight:700;color:#fff;line-height:1.1;}
.workspace-subtitle{font-size:13px;color:var(--muted);margin-top:6px;}
.workspace-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end;}
.workspace-pill{display:inline-flex;align-items:center;gap:7px;padding:9px 13px;border:1px solid var(--border);border-radius:18px;background:var(--mid);color:var(--light);font-size:12px;font-weight:700;box-shadow:0 2px 6px rgba(0,0,0,.12);white-space:nowrap;}
.workspace-pill i{color:var(--gold-h);}
.workspace-pill.muted{background:rgba(255,255,255,.04);color:var(--muted);font-weight:600;}
.notif-bell-wrap{position:relative;}
.notif-bell{position:relative;width:38px;height:38px;border-radius:50%;border:1px solid var(--border);background:var(--mid);color:var(--light);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:15px;}
.notif-bell .notif-badge{position:absolute;top:-4px;right:-4px;min-width:17px;height:17px;padding:0 4px;border-radius:9px;background:#ef4444;color:#fff;font-size:10px;font-weight:700;display:none;align-items:center;justify-content:center;}
.notif-dropdown{position:absolute;top:calc(100% + 8px);right:0;width:320px;max-width:calc(100vw - 32px);background:var(--mid);border:1px solid var(--border);border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,.5);z-index:120;display:none;overflow:hidden;}
.notif-dropdown.open{display:block;}
.notif-dd-head{padding:12px 14px;font-size:13px;font-weight:700;color:var(--light);border-bottom:1px solid var(--border);}
.notif-dd-list{list-style:none;margin:0;padding:6px;max-height:320px;overflow-y:auto;}
.notif-dd-list li{display:flex;gap:10px;align-items:flex-start;padding:10px;border-radius:8px;font-size:12.5px;line-height:1.5;color:var(--light);}
.notif-dd-list li i{color:var(--gold-h);margin-top:3px;}
.notif-dd-list li.unseen{background:rgba(217,154,43,.08);}
.notif-dd-empty{padding:18px 14px;font-size:12.5px;color:var(--muted);text-align:center;}
body.light-theme .notif-bell{color:#1E293B!important;background:#FFFFFF!important;}
body.light-theme .notif-dropdown{background:#FFFFFF!important;}
body.light-theme .notif-dd-head,body.light-theme .notif-dd-list li{color:#1E293B!important;}
.view-content.active{animation:fadeIn .16s ease;}
.dashboard-welcome{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;margin-bottom:15px;}
.page-title{font-family:'Rajdhani',sans-serif;font-size:25px;line-height:1.2;font-weight:700;color:#fff!important;margin-bottom:4px;}
.page-sub{font-size:13px;color:var(--muted);margin-bottom:0;}
.year-level-badge{font-size:13px;font-weight:600;color:var(--muted);}
.period-pill{margin-bottom:0;padding:7px 13px;font-size:11px;border-radius:18px;white-space:nowrap;}
.period-pill.open{background:rgba(34,197,94,.13);border:1px solid rgba(34,197,94,.28);color:#4ade80!important;}
.period-pill.closed{background:rgba(240,84,84,.10);border:1px solid rgba(240,84,84,.25);color:#fca5a5!important;}
.stat-grid{grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px;}
.stat-card{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:16px 18px;box-shadow:0 5px 14px rgba(0,0,0,.16);min-height:102px;position:relative;overflow:hidden;}
.stat-card:after{content:"";position:absolute;inset:auto 0 0 0;height:2px;background:linear-gradient(90deg,rgba(217,119,6,.28),rgba(245,158,11,.04));}
.stat-card i{display:flex;align-items:center;justify-content:center;width:31px;height:31px;border-radius:9px;background:rgba(217,119,6,.14);color:var(--gold-h);margin-bottom:11px;font-size:14px;}
.stat-card:nth-child(1) i{background:rgba(217,119,6,.14);color:var(--gold-h);}
.stat-card:nth-child(2) i{background:rgba(74,222,128,.10);color:#4ade80;}
.stat-card:nth-child(3) i{background:rgba(250,204,21,.10);color:#facc15;}
.stat-card:nth-child(4) i{background:rgba(96,165,250,.10);color:#60a5fa;}
.stat-num{font-family:'Rajdhani',sans-serif;font-size:27px;font-weight:700;color:#fff!important;line-height:1;}
.stat-lbl{font-size:11px;color:var(--muted);margin-top:6px;}
.progress-wrap{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:14px 17px;margin-bottom:14px;gap:14px;box-shadow:0 5px 14px rgba(0,0,0,.14);}
.progress-label{font-size:12px;color:var(--muted);}
.progress-label span{color:var(--gold-h);}
.progress-bar-bg{height:9px;background:rgba(255,255,255,.08)!important;}
.progress-bar-fill{background:linear-gradient(90deg,var(--gold),var(--gold-h))!important;}
.progress-pct{font-size:11px;color:var(--gold-h);}
.dash-cta{background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:19px 20px;gap:15px;box-shadow:0 5px 14px rgba(0,0,0,.15);}
.dash-cta-icon{width:46px;height:46px;border-radius:11px;background:rgba(217,119,6,.14);color:var(--gold-h);font-size:20px;}
.dash-cta-text h3{font-family:'Rajdhani',sans-serif;font-size:17px;font-weight:700;color:#fff!important;margin-bottom:4px;}
.dash-cta-text p{font-size:12px;color:var(--muted);}
.btn-primary-cta{padding:10px 18px;background:var(--gold);border-radius:9px;font-size:12.5px;box-shadow:0 4px 10px rgba(217,119,6,.18);}
.btn-primary-cta:hover{background:var(--gold-h);}
.alert{border:1px solid var(--border);background:var(--mid);box-shadow:0 5px 14px rgba(0,0,0,.12);}
.section-label{font-size:11px;font-weight:800;letter-spacing:1.1px;color:var(--muted);text-transform:uppercase;}
.category-grid{gap:12px;}
.cat-btn,.person-card,.members-panel,.history-card,.gl-card{background:var(--mid)!important;border-color:var(--border)!important;box-shadow:0 5px 14px rgba(0,0,0,.12);}
.cat-btn:hover,.person-card:hover{box-shadow:0 9px 20px rgba(0,0,0,.2);transform:translateY(-1px);}
.panel-header{background:var(--mid)!important;border-color:var(--border)!important;}
.modal,.photo-modal{background:var(--mid);border-color:var(--border);box-shadow:0 24px 60px rgba(0,0,0,.55);}
.modal-header,.photo-modal-header{background:var(--mid);border-color:var(--border);}
.modal-body,.photo-modal-body{background:var(--mid);}
.q-item,.comment-box{background:var(--inner)!important;}
.comment-textarea{background:var(--inner);color:var(--light);border-color:var(--border);}
.eval-form-wrap{background:var(--mid);border-color:var(--border);}
.eval-form-table th{background:var(--inner)!important;}
.eval-form-table td{border-bottom-color:var(--border)!important;}
@media(max-width:1100px){.workspace-title-row{flex-direction:column;}.workspace-meta{justify-content:flex-start;}.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
@media(max-width:900px){
    .sidebar{position:fixed;left:0;top:0;height:100vh;transform:translateX(-100%);transition:transform .22s ease;padding-top:20px;}
    .sidebar.open{transform:translateX(0);box-shadow:12px 0 34px rgba(0,0,0,.45);}
    .hamburger-btn{display:flex;position:fixed;left:16px;top:16px;z-index:81;background:var(--mid)!important;border-color:var(--border)!important;color:var(--light)!important;}
    .main{margin:12px;border-radius:18px;min-height:calc(100vh - 24px);padding:72px 16px 28px;}
    .content-topbar{margin-bottom:18px;}
}
@media(max-width:600px){
    .stat-grid{grid-template-columns:1fr 1fr;gap:10px;}
    .workspace-title{font-size:24px;}
    .workspace-subtitle{font-size:12.5px;}
    .dashboard-welcome{align-items:flex-start;flex-direction:column;}
    .workspace-pill{padding:8px 10px;font-size:11px;}
    .dash-cta{align-items:flex-start;}
    .btn-primary-cta{width:100%;}
}
@media(max-width:430px){.stat-grid{grid-template-columns:1fr;}.workspace-meta{width:100%;}.workspace-pill{flex:1;justify-content:center;}}

/* ══════════════════════════════════════════════════════════════
   TEXT CONTRAST & CONSISTENCY FIX
   1) Sidebar: readable light-on-navy text with a clear hierarchy
      (section label < tab < hovered tab < active tab) and a cleaner
      active-tab highlight (the old amber wash turned muddy grey).
   2) Workspace: the "aligned workspace" block above re-applied white
      text to headings that now sit on the white/pale workspace, and
      used pale green/red/amber that wash out on white. Those are set
      back to dark, high-contrast tones here.
   ══════════════════════════════════════════════════════════════ */

/* ── Sidebar ── */
.sb-profile-name{color:#FFFFFF!important;letter-spacing:.1px;}
.sb-profile-role{color:#F5B54A!important;letter-spacing:.9px;}
.side-section-label{color:#8FA6BF!important;font-size:10px;letter-spacing:1.4px;}
.side-nav-item{color:#CBD8E8!important;font-weight:500;letter-spacing:.1px;}
.side-nav-item span:not(.side-nav-badge){color:inherit!important;}
.side-nav-item i{color:#8FA6BF!important;}
.side-nav-item:hover{background:rgba(255,255,255,.07)!important;color:#FFFFFF!important;}
.side-nav-item:hover i{color:#F5B54A!important;}
.side-nav-item.active{background:linear-gradient(90deg,rgba(245,158,11,.20),rgba(245,158,11,.05))!important;color:#FFFFFF!important;font-weight:700;box-shadow:inset 3px 0 0 var(--gold-h);}
.side-nav-item.active i{color:#FBBF24!important;}
.side-nav-badge{background:rgba(245,158,11,.22)!important;color:#FFD9A0!important;font-weight:800;font-size:10px;}
.side-nav-item:focus-visible{outline:2px solid #FBBF24;outline-offset:2px;}

/* ── Workspace (white / pale) — only when Light is active ── */
body.light-theme .workspace-title,body.light-theme .page-title,body.light-theme .stat-num,body.light-theme .dash-cta-text h3{color:#0F172A!important;}
body.light-theme .workspace-subtitle,body.light-theme .page-sub,body.light-theme .stat-lbl,body.light-theme .dash-cta-text p,body.light-theme .year-level-badge,body.light-theme .progress-label{color:#475569!important;}
body.light-theme .workspace-kicker{color:#64748B!important;}
body.light-theme .workspace-kicker i,body.light-theme .workspace-pill i{color:#D97706!important;}
body.light-theme .workspace-pill{color:#1E293B!important;}
body.light-theme .workspace-pill.muted{color:#475569!important;background:#FFFFFF!important;}
body.light-theme .progress-label span,body.light-theme .progress-pct{color:#B45309!important;}
body.light-theme .period-pill.open{background:#DCFCE7!important;border-color:#86EFAC!important;color:#15803D!important;}
body.light-theme .period-pill.closed{background:#FEE2E2!important;border-color:#FCA5A5!important;color:#B91C1C!important;}
body.light-theme .period-info-card{background:#FFFFFF!important;border-color:#E2E8F0!important;box-shadow:0 5px 14px rgba(0,0,0,.06);}
body.light-theme .period-info-title{color:#0F172A!important;}
body.light-theme .period-info-lbl{color:#64748B!important;}
body.light-theme .period-info-val{color:#0F172A!important;}
body.light-theme .period-info-val.status-open{color:#15803D!important;}
body.light-theme .period-info-val.status-closed{color:#B91C1C!important;}
body.light-theme .period-info-note{color:#475569!important;border-top-color:#E2E8F0!important;}
body.light-theme .period-info-note strong{color:#1E293B!important;}
body.light-theme .stat-card:nth-child(1) i{background:#FEF3C7!important;color:#B45309!important;}
body.light-theme .stat-card:nth-child(2) i{background:#DCFCE7!important;color:#15803D!important;}
body.light-theme .stat-card:nth-child(3) i{background:#FEF9C3!important;color:#A16207!important;}
body.light-theme .stat-card:nth-child(4) i{background:#DBEAFE!important;color:#1D4ED8!important;}
body.light-theme .dash-cta-icon{background:#FEF3C7!important;color:#B45309!important;}

@media print{html,body{background:#fff!important;}}

/* ── Sidebar Log Out (sits directly below Settings) ── */
.side-logout-wrap{margin:14px 12px 0;padding-top:12px;border-top:1px solid rgba(255,255,255,.08);}
body .sidebar .side-nav-item.side-nav-logout{
    margin:0;text-decoration:none;
    color:#FCA5A5!important;
    background:rgba(239,68,68,.08)!important;
    border:1px solid rgba(239,68,68,.22);
}
body .sidebar .side-nav-item.side-nav-logout i{color:#F87171!important;}
body .sidebar .side-nav-item.side-nav-logout span{color:inherit!important;}
body .sidebar .side-nav-item.side-nav-logout:hover{
    background:rgba(239,68,68,.16)!important;
    border-color:rgba(239,68,68,.40);
    color:#FECACA!important;
}
body .sidebar .side-nav-item.side-nav-logout:hover i{color:#FCA5A5!important;}
body .sidebar .side-nav-item.side-nav-logout:focus-visible{outline:2px solid #F87171;outline-offset:2px;}

/* ── Log Out confirmation modal ── */
.logout-modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:400;display:none;align-items:center;justify-content:center;padding:20px;}
.logout-modal-overlay.open{display:flex;}
/* Same confirm dialog as the Executive Assistant portal: white card, bold question, Cancel + amber OK. Stays white in both themes. */
.logout-modal{background:#fff;border:0;border-radius:18px;width:100%;max-width:475px;box-shadow:0 20px 60px rgba(15,23,42,.30);padding:32px 30px 28px;text-align:left;}
.logout-modal-title{font-family:'DM Sans',sans-serif;font-size:17px;font-weight:700;color:#0f172a!important;line-height:1.4;margin:0 0 24px;}
.logout-modal-actions{display:flex;gap:12px;}
.logout-modal-actions .btn-logout-cancel,.logout-modal-actions .btn-logout-confirm{flex:1;height:46px;padding:0 16px;border-radius:10px;font-family:'DM Sans',sans-serif;font-size:15.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;transition:background .15s,border-color .15s;}
.logout-modal-actions .btn-logout-cancel{background:#fff;border:1px solid #e2e8f0;color:#0f172a;}
.logout-modal-actions .btn-logout-cancel:hover{background:#f8fafc;border-color:#cbd5e1;}
.logout-modal-actions .btn-logout-confirm{background:#D97706;border:1px solid #D97706;color:#fff;}
.logout-modal-actions .btn-logout-confirm:hover{background:#B45309;border-color:#B45309;}
.logout-modal-actions .btn-logout-cancel:focus-visible,.logout-modal-actions .btn-logout-confirm:focus-visible{outline:2px solid #D97706;outline-offset:2px;}
@media(max-width:480px){.logout-modal{padding:26px 22px 22px;}}

/* ── Sidebar: fixed in place on desktop (never scrolls with the page) ── */
@media(min-width:901px){
    .app-shell{padding-left:248px;}
    .sidebar{
        position:fixed!important;top:0;left:0;bottom:0;
        height:100vh;min-height:0!important;
        overflow-y:auto;overscroll-behavior:contain;
        z-index:60;
    }
    .sidebar::-webkit-scrollbar{width:0;height:0;}
    .sidebar{scrollbar-width:none;}
}

/* ── Evaluation others: even person cards, Evaluate buttons aligned ── */
.members-grid{align-items:stretch;}
.person-card{display:flex;flex-direction:column;align-items:stretch;height:100%;}
.person-card .person-avatar,.person-card .person-avatar-ph{flex-shrink:0;}
.person-card .person-name{min-height:2.6em;display:flex;align-items:center;justify-content:center;}
.person-card .person-desig{margin-bottom:11px;line-height:1.35;}
.person-card .eval-btn{margin-top:auto;flex-shrink:0;}
.person-card .person-desig + .eval-btn{margin-top:auto;}

/* ── Current Evaluation Period card: single aligned row ── */
.period-info-grid{grid-template-columns:1fr 1.2fr 1fr .8fr 1.6fr 1.6fr;align-items:start;gap:20px 24px;}
.period-info-val{white-space:nowrap;}
@media(max-width:1280px){
    .period-info-grid{grid-template-columns:repeat(3,minmax(0,1fr));}
}
@media(max-width:600px){
    .period-info-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
    .period-info-val{white-space:normal;}
}

/* Dashboard-only heading: other tabs keep just the semester/updated/bell row */
.content-topbar.not-dashboard .workspace-kicker,
.content-topbar.not-dashboard .workspace-title-block{display:none;}
.workspace-meta{margin-left:auto;}
@media(max-width:1100px){.workspace-meta{margin-left:0;}}
.content-topbar.not-dashboard .workspace-title-row{justify-content:flex-end;}

/* ── Evaluation others: large category tabs (matches the EA evaluation page) ── */
/* Always a 3-column track so 2 tabs keep the same size as 3 (no stretching). */
body .category-grid{grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:16px!important;margin-bottom:22px;}
@media(max-width:760px){body .category-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;}}
body .category-grid .cat-btn{
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    min-height:185px!important;padding:24px 18px 22px!important;
    border-radius:16px;overflow:visible;
    border:1px solid var(--border)!important;
}
body .category-grid .cat-btn::after{
    content:"";position:absolute;left:0;right:0;bottom:0;height:4px;
    background:var(--cat-color,#D97706);border-radius:0 0 16px 16px;
}
body .category-grid .cat-icon{
    width:64px;height:64px;margin:0 0 12px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;font-size:26px;
    background:color-mix(in srgb,var(--cat-color,#D97706) 14%,transparent);
}
body .category-grid .cat-name{font-size:21px;margin-bottom:3px;}
body .category-grid .cat-meta{font-size:13px;}
body .category-grid .cat-done-pill{margin-top:10px;font-size:11px;padding:3px 11px;}
body.light-theme .category-grid .cat-btn.active{
    background:color-mix(in srgb,var(--cat-color,#D97706) 9%,#fff)!important;
}
body .category-grid .cat-btn.active{
    border-color:color-mix(in srgb,var(--cat-color,#D97706) 45%,transparent)!important;
}
@media(max-width:600px){
    body .category-grid{grid-template-columns:1fr!important;gap:12px!important;}
    body .category-grid .cat-btn{min-height:180px!important;padding:22px 16px 22px!important;}
    body .category-grid .cat-icon{width:64px;height:64px;font-size:26px;}
    body .category-grid .cat-name{font-size:21px;}
}

/* ── Evaluation others: member list (rows, badges, footer) ── */
body .ev-panel{border-radius:16px;margin-bottom:32px;background:var(--mid)!important;border:1px solid var(--border)!important;box-shadow:0 10px 24px rgba(15,23,42,.06)!important;overflow:hidden;}
.ev-head{display:flex;align-items:center;gap:14px;padding:18px 26px;border-bottom:1px solid var(--border);}
.ev-head-icon{width:46px;height:46px;border-radius:12px;background:#eaf1ff;color:#2563eb;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
.ev-head-title{font-family:'Rajdhani',sans-serif;font-size:22px;font-weight:700;color:var(--light);}
.ev-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 18px;padding:22px 22px 24px;}
.ev-row{display:flex;align-items:center;gap:14px;padding:16px 18px;background:var(--mid);border:1px solid var(--border);border-radius:14px;min-width:0;transition:border-color .2s,box-shadow .2s;}
.ev-row:hover{border-color:#bfd0ee;box-shadow:0 6px 16px rgba(15,23,42,.06);}
.ev-avatar{width:54px;height:54px;border-radius:50%;object-fit:cover;flex-shrink:0;border:1px solid var(--border);background:var(--inner);}
.ev-avatar-ph{display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:20px;}
.ev-info{flex:1;min-width:0;display:flex;flex-direction:column;gap:3px;align-items:flex-start;}
.ev-name{font-size:15px;font-weight:700;color:var(--light);line-height:1.3;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.ev-desig{font-size:13px;color:var(--muted);max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.ev-badge{display:inline-flex;align-items:center;gap:5px;margin-top:3px;padding:3px 10px;border-radius:999px;font-size:11.5px;font-weight:700;line-height:1.4;}
.ev-badge i{font-size:10px;}
.ev-badge-pending{background:#fff4d6;color:#b45309;border:1px solid #fde7a8;}
.ev-badge-done{background:#e4f8ec;color:#15803d;border:1px solid #bbf0d0;}
.ev-btn{flex-shrink:0;display:inline-flex;align-items:center;gap:7px;padding:0 20px;height:42px;border-radius:9px;font-size:13.5px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .2s,border-color .2s;}
.ev-btn-eval{background:#2563eb;border:1px solid #2563eb;color:#fff;}
.ev-btn-eval:hover:not(:disabled){background:#1d4ed8;border-color:#1d4ed8;}
.ev-btn-eval:disabled{background:#f1f5f9;border-color:#e2e8f0;color:#94a3b8;cursor:not-allowed;}
.ev-btn-view{background:transparent;border:1px solid #cbd5e1;color:#475569;}
.ev-btn-view:hover{background:#f1f5f9;border-color:#94a3b8;}
.ev-foot{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px 20px;padding:14px 26px;border-top:1px solid var(--border);background:var(--inner);font-size:13px;color:var(--muted);}
.ev-foot-stats{display:flex;gap:18px;flex-wrap:wrap;}
.ev-foot-stats strong{color:#2563eb;font-weight:700;}
.ev-foot-stats strong.c-done{color:#15803d;}
.ev-foot-stats strong.c-pend{color:#b45309;}
.ev-foot-period i{margin-right:6px;}
@media(max-width:900px){.ev-grid{grid-template-columns:1fr;padding:16px 14px 18px;}.ev-head,.ev-foot{padding-left:16px;padding-right:16px;}}
@media(max-width:430px){.ev-row{flex-wrap:wrap;}.ev-btn{width:100%;justify-content:center;}}

/* ── Evaluation others: compact sizing ── */
.ev-head{gap:11px;padding:13px 20px;}
.ev-head-icon{width:36px;height:36px;border-radius:10px;font-size:14px;}
.ev-head-title{font-size:18px;}
.ev-grid{gap:10px 14px;padding:16px 18px 18px;}
.ev-row{gap:11px;padding:11px 14px;border-radius:12px;}
.ev-avatar{width:42px;height:42px;}
.ev-avatar-ph{font-size:16px;}
.ev-info{gap:2px;}
.ev-name{font-size:13.5px;}
.ev-desig{font-size:11.5px;}
.ev-badge{margin-top:2px;padding:2px 8px;font-size:10.5px;}
.ev-badge i{font-size:9px;}
.ev-btn{height:34px;padding:0 15px;font-size:12.5px;gap:6px;border-radius:8px;}
.ev-foot{padding:10px 20px;font-size:12px;}
.ev-foot-stats{gap:14px;}
body .category-grid{gap:14px!important;margin-bottom:18px;}
body .category-grid .cat-btn{min-height:160px!important;padding:20px 16px 18px!important;border-radius:14px;}
body .category-grid .cat-btn::after{border-radius:0 0 14px 14px;height:3px;}
body .category-grid .cat-icon{width:56px;height:56px;font-size:23px;margin-bottom:10px;}
body .category-grid .cat-name{font-size:19px;}
body .category-grid .cat-meta{font-size:12.5px;}
body .category-grid .cat-done-pill{margin-top:8px;font-size:10.5px;padding:3px 10px;}

/* Note banner under the page subtitle was pulled up over the text */
body.light-theme .student-eval-note{margin:14px 0 22px!important;}
.page-sub{margin-bottom:0;line-height:1.5;}
</style>
<style id="student-title-icons">
/* ── Top header bar (same layout as the Faculty portal): icon + page title on the
   left, semester pill + notifications on the right. One accent only (Student gold). ── */
.student-topbar{position:fixed;top:0;left:248px;right:0;height:58px;z-index:35;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:0 26px;background:#FFFFFF;color:#13263F;border-bottom:1px solid #DEE6EF;box-shadow:0 2px 14px rgba(15,34,55,.04);box-sizing:border-box;}
.student-topbar *{box-sizing:border-box;}
.stb-left,.stb-right{display:flex;align-items:center;gap:13px;min-width:0;}
.stb-title{display:inline-flex;align-items:center;gap:11px;font-family:'Rajdhani',sans-serif;font-size:21px;font-weight:700;letter-spacing:.3px;line-height:1.2;color:#13263F;white-space:nowrap;}
.stb-icon{font-size:19px;color:var(--gold);}
.student-topbar .hamburger-btn{position:static!important;display:none;width:36px;height:36px;margin:0;background:#F8FAFC!important;border:1px solid #D9E2EC!important;color:#334155!important;}
.student-topbar .workspace-pill{box-shadow:none;background:rgba(217,119,6,.08)!important;border-color:rgba(217,119,6,.28)!important;color:#9A5A06!important;}
.student-topbar .workspace-pill i{color:var(--gold)!important;}
.student-topbar .notif-bell{width:36px;height:36px;background:#FFFFFF!important;border-color:#D9E2EC!important;color:#64748B!important;}
body:not(.light-theme) .student-topbar{background:#0F1F3D;color:#E0E6F0;border-bottom-color:rgba(255,255,255,.10);box-shadow:0 2px 14px rgba(0,0,0,.22);}
body:not(.light-theme) .stb-title{color:#F8FAFC;}
body:not(.light-theme) .student-topbar .hamburger-btn{background:#172A45!important;border-color:rgba(255,255,255,.12)!important;color:#D0DAE6!important;}
body:not(.light-theme) .student-topbar .workspace-pill{background:rgba(217,119,6,.16)!important;border-color:rgba(245,158,11,.34)!important;color:#FBBF24!important;}
body:not(.light-theme) .student-topbar .notif-bell{background:#172A45!important;border-color:rgba(255,255,255,.12)!important;color:#D0DAE6!important;}

/* ── Evaluate Personnel: same layout as the Admin "Evaluate Others" page ──
   white card → "Evaluation" heading → step label → large category cards. */
.eval-main-card{background:#FFFFFF;border:1px solid #E2E8F0;border-radius:18px;padding:26px 30px 30px;box-shadow:0 2px 12px rgba(15,23,42,.045);}
body:not(.light-theme) .eval-main-card{background:var(--mid);border-color:var(--border);box-shadow:0 8px 24px rgba(0,0,0,.2);}
.eval-card-title{display:flex;align-items:center;gap:12px;font-family:'Rajdhani',sans-serif;font-size:30px;font-weight:700;line-height:1.1;color:#0F172A;margin-bottom:20px;}
.eval-card-title i{font-size:21px;color:#2563EB;}
body:not(.light-theme) .eval-card-title{color:#F8FAFC;}
.eval-step-label{display:flex;align-items:center;gap:9px;font-size:12px;font-weight:800;letter-spacing:1.1px;text-transform:uppercase;color:#64748B;margin-bottom:14px;}
.eval-step-label i{font-size:12px;color:#64748B;}
body:not(.light-theme) .eval-step-label,body:not(.light-theme) .eval-step-label i{color:var(--muted);}
/* Fixed 3-column track: a card is always one third wide, so two categories keep the same size as three. */
.eval-main-card .category-grid{grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:18px!important;margin-bottom:20px;}
.eval-main-card .category-grid .cat-btn{min-height:215px!important;padding:28px 18px 24px!important;border-radius:15px;}
.eval-main-card .category-grid .cat-btn::after{border-radius:0 0 15px 15px;}
.eval-main-card .category-grid .cat-icon{width:72px;height:72px;font-size:28px;margin-bottom:14px;}
.eval-main-card .category-grid .cat-name{font-size:22px;margin-bottom:4px;}
.eval-main-card .category-grid .cat-meta{font-size:14px;}
@media(max-width:900px){.eval-main-card .category-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;}}
.eval-main-card .ev-panel{margin-top:6px;margin-bottom:0;}
@media(max-width:600px){
  .eval-main-card{padding:20px 16px 22px;}
  .eval-card-title{font-size:25px;}
  .eval-main-card .category-grid{grid-template-columns:1fr!important;}
  .eval-main-card .category-grid .cat-btn{min-height:170px!important;}
}

/* Dashboard intro: small gold label with the status pill underneath, left-aligned. */
.dashboard-welcome{display:flex!important;flex-direction:column!important;align-items:flex-start!important;justify-content:flex-start!important;gap:10px!important;margin-bottom:16px!important;}
.dash-kicker{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:800;letter-spacing:1.1px;text-transform:uppercase;color:var(--gold);}
.dash-kicker i{font-size:13px;color:var(--gold);}
.dashboard-welcome .period-pill{margin:0;}

/* Make room for the bar. student_ui.css forces `margin:24px !important` on .main with a
   more specific selector, so these use the same selectors to win. */
@media(min-width:901px){
  body:has(.app-shell) .main,
  body.light-theme:has(.app-shell) .main{margin-top:82px!important;min-height:calc(100vh - 106px)!important;}
}
/* The in-page heading is Dashboard-only now; other tabs get their title from the bar. */
.content-topbar.not-dashboard{display:none!important;}
.view-content:not(#view-dashboard) > .page-title{display:none;}

@media(max-width:900px){
  .student-topbar{left:0;padding:0 14px;}
  .student-topbar .hamburger-btn{display:flex;}
  body:has(.app-shell) .main,
  body.light-theme:has(.app-shell) .main{margin-top:74px!important;min-height:calc(100vh - 90px)!important;padding-top:22px!important;}
}
@media(max-width:600px){.stb-title{font-size:18px;}.student-topbar .workspace-pill{padding:7px 10px;font-size:11px;}}

/* ── Dashboard: staff-style layout (white shell, info row, welcome card, top-bordered stats, shortcuts) ── */
.sd-shell{--sd-card:#fff;--sd-line:#e2e8f0;--sd-ink:#0f172a;--sd-sub:#64748b;background:#fff;border:1px solid #e6ebf2;border-radius:22px;padding:30px 32px 34px;box-shadow:0 8px 24px rgba(15,23,42,.05);}
body:not(.light-theme) .sd-shell{--sd-card:var(--inner);--sd-line:var(--border);--sd-ink:#f8fafc;--sd-sub:var(--muted);background:var(--mid);border-color:var(--border);box-shadow:0 8px 24px rgba(0,0,0,.2);}
.sd-open-pill{display:inline-flex;align-items:center;gap:8px;padding:7px 14px;border-radius:999px;font-size:13px;font-weight:600;margin-bottom:18px;}
.sd-open-pill.open{background:#ecfdf3;border:1px solid #86efac;color:#15803d;}
.sd-open-pill.closed{background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;}
body:not(.light-theme) .sd-open-pill.open{background:rgba(34,197,94,.13);color:#4ade80;border-color:rgba(34,197,94,.3);}
body:not(.light-theme) .sd-open-pill.closed{background:rgba(240,84,84,.1);color:#fca5a5;border-color:rgba(240,84,84,.3);}
.sd-period{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:20px 24px;align-items:center;padding:22px 26px;border:1px solid var(--sd-line);border-radius:16px;background:var(--sd-card);margin-bottom:18px;}
.sd-lbl{font-size:11.5px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:#d97706;margin-bottom:8px;}
.sd-val{font-size:17px;font-weight:600;color:var(--sd-ink);white-space:nowrap;}
.sd-status{display:inline-block;padding:6px 18px;border-radius:999px;font-size:14px;font-weight:600;}
.sd-status.open{background:#ecfdf3;border:1px solid #86efac;color:#15803d;}
.sd-status.closed{background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;}
.sd-status.scheduled{background:#fffbeb;border:1px solid #fcd34d;color:#b45309;}
.sd-note{margin:-6px 0 18px;font-size:12.5px;color:var(--sd-sub);}.sd-note strong{color:var(--sd-ink);}
.sd-welcome{display:flex;align-items:center;justify-content:space-between;gap:24px;padding:28px 30px;border:1px solid #bbf0d0;border-radius:16px;background:linear-gradient(135deg,#f0fdf6 0%,#fff 70%);margin-bottom:18px;}
body:not(.light-theme) .sd-welcome{background:linear-gradient(135deg,rgba(34,197,94,.1),var(--inner) 70%);border-color:rgba(34,197,94,.25);}
.sd-welcome-text{min-width:0;flex:1;}
.sd-welcome h2{font-family:'Rajdhani',sans-serif;font-size:30px;font-weight:700;color:var(--sd-ink);line-height:1.15;}
.sd-welcome p{font-size:15px;color:var(--sd-sub);margin-top:8px;}
.sd-bar{height:8px;max-width:420px;margin-top:16px;background:#e2e8f0;border-radius:20px;overflow:hidden;}
body:not(.light-theme) .sd-bar{background:rgba(255,255,255,.1);}
.sd-bar div{height:100%;background:linear-gradient(90deg,#d97706,#f59e0b);border-radius:20px;transition:width .4s ease;}
.sd-score{flex-shrink:0;min-width:288px;text-align:center;padding:22px 28px;border:1px solid #bbf0d0;border-radius:16px;background:#f0fdf6;}
body:not(.light-theme) .sd-score{background:rgba(34,197,94,.08);border-color:rgba(34,197,94,.25);}
.sd-score-num{font-family:'Rajdhani',sans-serif;font-size:50px;font-weight:700;line-height:1;color:#15803d;}
body:not(.light-theme) .sd-score-num{color:#4ade80;}
.sd-score-lbl{margin-top:10px;font-size:13px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--sd-sub);}
.sd-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:34px;}
.sd-stat{background:var(--sd-card);border:1px solid var(--sd-line);border-top:4px solid var(--c);border-radius:16px;padding:20px 22px 22px;box-shadow:0 4px 14px rgba(15,23,42,.04);}
.sd-stat .sd-lbl{color:var(--sd-sub);font-size:12px;letter-spacing:1.3px;margin:14px 0 6px;}
.sd-stat-val{font-size:34px;font-weight:700;line-height:1.1;color:var(--cv);}
.s-amber{--c:#f59e0b;--cv:#d97706;--ci:#fef3c7;--cf:#b45309}.s-green{--c:#22c55e;--cv:#15803d;--ci:#dcfce7;--cf:#15803d}.s-yellow{--c:#eab308;--cv:#ca8a04;--ci:#fef9c3;--cf:#a16207}.s-blue{--c:#3b82f6;--cv:#2563eb;--ci:#dbeafe;--cf:#1d4ed8}
.sd-ico{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:10px;background:var(--ci);color:var(--cf);font-size:16px;}
body:not(.light-theme) .sd-stat-val{color:#f8fafc;}
.sd-sc-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:16px;}
.sd-kicker{font-size:12px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:#15803d;margin-bottom:4px;}
body:not(.light-theme) .sd-kicker{color:#4ade80;}
.sd-sc-head h3{font-family:'Rajdhani',sans-serif;font-size:24px;font-weight:700;color:var(--sd-ink);}
.sd-sc-hint{font-size:13px;color:var(--sd-sub);}
.sd-shortcuts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;}
.sd-sc{display:flex;align-items:center;gap:16px;text-align:left;font:inherit;cursor:pointer;padding:24px 22px;background:var(--sd-card);border:1px solid var(--sd-line);border-radius:16px;color:var(--sd-ink);transition:border-color .2s,box-shadow .2s,transform .2s;}
.sd-sc:hover{border-color:#f0c27a;box-shadow:0 8px 20px rgba(15,23,42,.07);transform:translateY(-1px);}
.sd-sc:focus-visible{outline:2px solid #d97706;outline-offset:2px;}
.sd-sc-ico{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.sd-sc-ico.c-green{background:#dcfce7;color:#15803d}.sd-sc-ico.c-blue{background:#dbeafe;color:#2563eb}.sd-sc-ico.c-amber{background:#fef3c7;color:#d97706}
.sd-sc-body{flex:1;min-width:0;}
.sd-sc-body strong{display:block;font-size:15px;font-weight:700;color:var(--sd-ink);}
.sd-sc-body small{display:block;margin-top:5px;font-size:13px;line-height:1.4;color:var(--sd-sub);}
.sd-arrow{color:#94a3b8;font-size:14px;flex-shrink:0;}
@media(max-width:1200px){.sd-period{grid-template-columns:repeat(2,minmax(0,1fr));}.sd-stats{grid-template-columns:repeat(2,minmax(0,1fr));}.sd-shortcuts{grid-template-columns:1fr;}.sd-welcome{flex-direction:column;align-items:stretch;}.sd-score{min-width:0;}}
@media(max-width:600px){.sd-shell{padding:20px 16px 24px;border-radius:18px;}.sd-period{padding:18px;}.sd-val{white-space:normal;}.sd-welcome{padding:20px;}.sd-welcome h2{font-size:25px;}.sd-stats{gap:10px;}.sd-sc-head{flex-direction:column;align-items:flex-start;gap:4px;}}

/* Pale workspace color (#f1f4f8) fills the whole page: no white margin around the content panel. */
html:has(body.light-theme .app-shell),body.light-theme,body.light-theme:has(.app-shell){background:#f1f4f8!important;}
body.light-theme:has(.app-shell) .app-shell{background:#f1f4f8!important;}
body.light-theme:has(.app-shell) .main{background:#f1f4f8!important;border-color:transparent!important;box-shadow:none!important;}

/* ── Dashboard: match the Principal dashboard (compact, uniform gold) ── */
@media(min-width:901px){body:has(.app-shell) .main,body.light-theme:has(.app-shell) .main{margin:78px 34px 24px 34px!important;padding:0!important;min-height:calc(100vh - 102px)!important;}}
.sd-shell{padding:22px 32px 28px;border-radius:22px;--gold-d:#b7791f;}
.sd-kick-top{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;letter-spacing:1.3px;text-transform:uppercase;color:var(--gold-d);margin-bottom:12px;}
.sd-open-pill{font-size:12.5px;padding:6px 14px;margin-bottom:14px;}
.sd-period{padding:18px 26px;border-radius:16px;margin-bottom:16px;}
.sd-lbl{font-size:11px;letter-spacing:1.2px;color:var(--gold-d);margin-bottom:6px;}
.sd-val{font-size:16px;font-weight:700;}
.sd-status{font-size:12px;font-weight:700;padding:5px 15px;}
.sd-welcome{padding:22px 26px;margin-bottom:16px;background:linear-gradient(135deg,#fffaf0 0%,#fff 75%);border-color:#f3e2b8;}
.sd-welcome h2{font-size:28px;}
.sd-welcome p{font-size:14px;margin-top:6px;}
.sd-em{color:var(--gold-d);font-weight:700;}
.sd-score{min-width:350px;padding:18px 26px;background:#fff8e8;border-color:#f3e2b8;}
.sd-score-num{font-size:46px;color:var(--gold-d);}
.sd-score-lbl{font-size:12px;margin-top:8px;}
.sd-stats{gap:16px;margin-bottom:28px;}
.sd-stat,.sd-stat.s-amber,.sd-stat.s-green,.sd-stat.s-yellow,.sd-stat.s-blue{--c:#d99a2b;--cv:var(--gold-d);--ci:#fdf0d5;--cf:var(--gold-d);padding:16px 20px 18px;border-top-width:5px;}
.sd-stat .sd-lbl{color:var(--sd-sub);font-size:11.5px;margin:10px 0 4px;}
.sd-stat-val{font-size:30px;}
.sd-ico{width:34px;height:34px;font-size:15px;}
.sd-sc-head{margin-bottom:12px;}
.sd-kicker{font-size:11.5px;color:var(--gold-d);margin-bottom:2px;}
.sd-sc-head h3{font-size:22px;}
.sd-sc-hint{font-size:12.5px;}
.sd-sc{padding:18px 20px;gap:14px;}
.sd-sc-ico,.sd-sc-ico.c-green,.sd-sc-ico.c-blue,.sd-sc-ico.c-amber{width:44px;height:44px;font-size:18px;background:#fdf0d5;color:var(--gold-d);}
.sd-sc-body strong{font-size:14.5px;}.sd-sc-body small{font-size:12.5px;margin-top:3px;}
.sd-arrow{color:var(--gold-d);}
.sd-sc:hover{border-color:#e9c987;}
body:not(.light-theme) .sd-welcome{background:linear-gradient(135deg,rgba(217,154,43,.12),var(--inner) 75%);border-color:rgba(217,154,43,.3);}
body:not(.light-theme) .sd-score{background:rgba(217,154,43,.1);border-color:rgba(217,154,43,.3);}
body:not(.light-theme) .sd-score-num,body:not(.light-theme) .sd-kick-top,body:not(.light-theme) .sd-kicker,body:not(.light-theme) .sd-em,body:not(.light-theme) .sd-arrow{color:#f5b54a;}
body:not(.light-theme) .sd-stat-val{color:#f5b54a;}
body:not(.light-theme) .sd-sc-ico{background:rgba(217,154,43,.16);color:#f5b54a;}
@media(max-width:1200px){.sd-score{min-width:0;}}
@media(max-width:900px){body:has(.app-shell) .main,body.light-theme:has(.app-shell) .main{padding:0!important;margin:74px 14px 18px!important;}}

/* ── Dashboard: compact sizing tuned to the Principal dashboard (≈12% smaller) ── */
@media(min-width:901px){body:has(.app-shell) .main,body.light-theme:has(.app-shell) .main{margin:74px 28px 22px 28px!important;min-height:calc(100vh - 96px)!important;}}
.sd-shell{padding:18px 26px 22px;border-radius:20px;}
.sd-kick-top{font-size:10.5px;letter-spacing:1.2px;margin-bottom:9px;}
.sd-open-pill{font-size:11px;padding:5px 12px;margin-bottom:11px;}
.sd-period{padding:14px 22px;gap:14px 20px;margin-bottom:14px;border-radius:14px;}
.sd-lbl{font-size:10px;letter-spacing:1.1px;margin-bottom:5px;}
.sd-val{font-size:14px;}
.sd-status{font-size:10.5px;padding:4px 13px;}
.sd-note{font-size:11px;margin:-4px 0 14px;}
.sd-welcome{padding:16px 22px;margin-bottom:14px;border-radius:14px;}
.sd-welcome h2{font-size:24px;}
.sd-welcome p{font-size:12.5px;margin-top:5px;}
.sd-score{min-width:280px;padding:12px 22px;border-radius:14px;}
.sd-score-num{font-size:40px;}
.sd-score-lbl{font-size:10.5px;margin-top:6px;}
.sd-stats{gap:14px;margin-bottom:22px;}
.sd-stat,.sd-stat.s-amber,.sd-stat.s-green,.sd-stat.s-yellow,.sd-stat.s-blue{padding:13px 17px 15px;border-top-width:4px;border-radius:14px;}
.sd-stat .sd-lbl{font-size:10px;margin:8px 0 3px;}
.sd-stat-val{font-size:26px;}
.sd-ico{width:30px;height:30px;font-size:13px;border-radius:9px;}
.sd-sc-head{margin-bottom:9px;}
.sd-kicker{font-size:10px;}
.sd-sc-head h3{font-size:19px;}
.sd-sc-hint{font-size:11px;}
.sd-shortcuts{gap:14px;}
.sd-sc{padding:14px 17px;gap:12px;border-radius:14px;}
.sd-sc-ico,.sd-sc-ico.c-green,.sd-sc-ico.c-blue,.sd-sc-ico.c-amber{width:38px;height:38px;font-size:15px;border-radius:11px;}
.sd-sc-body strong{font-size:13px;}
.sd-sc-body small{font-size:11px;margin-top:2px;}
.sd-arrow{font-size:12px;}
@media(max-width:1200px){.sd-score{min-width:0;}}

/* ── Other tabs (Evaluate / History / Guidelines / Settings): same look as the Dashboard ── */
.sd-page-sub{font-size:12px;color:var(--sd-sub);margin:-3px 0 14px;}
.sd-shell .alert{font-size:12px;padding:9px 12px;border-radius:10px;margin-bottom:12px;box-shadow:none;}
.sd-shell .empty{padding:30px 16px;font-size:13px;}
/* Evaluate */
body .sd-shell .eval-main-card{background:none!important;border:0!important;box-shadow:none!important;padding:0!important;border-radius:0!important;}
.sd-shell .eval-card-title{font-size:19px;margin-bottom:12px;gap:9px;color:var(--sd-ink)!important;}
.sd-shell .eval-card-title i{font-size:15px;color:var(--gold-d)!important;}
.sd-shell .category-grid{gap:14px!important;margin-bottom:14px!important;}
.sd-shell .cat-btn{--cat-color:#d99a2b!important;background:var(--sd-card)!important;border-color:var(--sd-line)!important;box-shadow:0 4px 14px rgba(15,23,42,.04)!important;min-height:140px!important;padding:16px 14px 14px!important;border-radius:14px!important;}
.sd-shell .cat-btn::after{height:4px!important;border-radius:0 0 14px 14px!important;}
.sd-shell .cat-icon{color:var(--gold-d)!important;width:46px!important;height:46px!important;font-size:19px!important;margin-bottom:8px!important;background:#fdf0d5!important;}
.sd-shell .cat-name{font-size:17px!important;color:var(--sd-ink)!important;}
.sd-shell .cat-meta{font-size:11.5px!important;}
.sd-shell .cat-done-pill{font-size:10px!important;margin-top:6px!important;}
.sd-shell .cat-active-arrow{border-top-color:#d99a2b!important;}
.sd-shell .ev-panel{border-radius:14px;margin:4px 0 0;box-shadow:none!important;background:var(--sd-card)!important;border-color:var(--sd-line)!important;}
.sd-shell .ev-head{padding:11px 16px;gap:10px;border-color:var(--sd-line);}
.sd-shell .ev-head-icon{width:32px;height:32px;font-size:13px;background:#fdf0d5;color:var(--gold-d);}
.sd-shell .ev-head-title{font-size:17px;color:var(--sd-ink);}
.sd-shell .ev-grid{padding:14px 16px 16px;gap:10px 12px;}
.sd-shell .ev-row{padding:10px 13px;gap:11px;border-radius:12px;background:var(--sd-card);border-color:var(--sd-line);}
.sd-shell .ev-row:hover{border-color:#e9c987;}
.sd-shell .ev-avatar{width:38px;height:38px;}
.sd-shell .ev-name{font-size:13px;color:var(--sd-ink);}
.sd-shell .ev-desig{font-size:11px;}
.sd-shell .ev-badge{font-size:10px;padding:2px 8px;}
.sd-shell .ev-btn{height:32px;padding:0 14px;font-size:12px;}
.sd-shell .ev-btn-eval{background:#d99a2b;border-color:#d99a2b;}
.sd-shell .ev-btn-eval:hover:not(:disabled){background:var(--gold-d);border-color:var(--gold-d);}
.sd-shell .ev-btn-eval:disabled{background:#f1f5f9;border-color:#e2e8f0;color:#94a3b8;}
.sd-shell .ev-foot{padding:9px 16px;font-size:11.5px;background:var(--sd-card);border-color:var(--sd-line);}
.sd-shell .ev-foot-stats strong{color:var(--gold-d);}
/* History */
.sd-shell .history-list{gap:10px;}
.sd-shell .history-card,.sd-shell .gl-card{background:var(--sd-card)!important;border-color:var(--sd-line)!important;box-shadow:0 4px 14px rgba(15,23,42,.04)!important;border-radius:14px!important;}
.sd-shell .history-card{padding:12px 16px;gap:12px;}
.sd-shell .history-avatar,.sd-shell .history-avatar-ph{width:40px;height:40px;}
.sd-shell .history-name{font-size:13.5px;color:var(--sd-ink)!important;}
.sd-shell .history-desig{font-size:11px;}.sd-shell .history-comment{font-size:11.5px;}
.sd-shell .history-score{font-size:14px;color:var(--gold-d);}.sd-shell .history-date{font-size:11px;}
/* Guidelines + Settings cards */
.sd-shell .gl-card{padding:16px 18px;margin-bottom:12px;}
.sd-shell .gl-card h3{font-size:15px;margin-bottom:9px;color:var(--sd-ink)!important;}
.sd-shell .gl-card h3 i{color:var(--gold-d);}
.sd-shell .gl-card p,.sd-shell .gl-card li{font-size:12.5px;line-height:1.6;}
.sd-shell .gl-scale-row{padding:4px 0;}
.sd-shell .gl-scale-num{width:24px;height:24px;font-size:11.5px;background:#d99a2b;}
.sd-shell .settings-grid{gap:14px;margin-bottom:12px;}
.sd-shell .settings-field label{font-size:10px;letter-spacing:1px;margin-bottom:5px;}
.sd-shell .settings-field input,.sd-shell .settings-field select{padding:9px 12px;font-size:13px;border-radius:9px;}
.sd-shell .settings-hint,.sd-shell .settings-field-note{font-size:11px;}
.sd-shell .settings-row .profile-dd-avatar,.sd-shell .settings-row .profile-dd-avatar-ph,.sd-shell .settings-row .profile-dd-avatar-logo{width:54px;height:54px;}
.sd-shell .settings-row .profile-dd-name{font-size:15px;color:var(--sd-ink)!important;}
.sd-shell .profile-dd-role{font-size:11.5px;}
.sd-shell .appearance-choice{padding:8px 14px;font-size:12px;}
body.light-theme:has(.app-shell) .sd-shell .btn-primary-cta,.sd-shell .btn-primary-cta{min-height:36px;padding:8px 14px!important;font-size:12.5px;border-radius:9px;background:#d99a2b;box-shadow:none;}
.sd-shell .btn-primary-cta:hover{background:var(--gold-d);}
@media(max-width:600px){.sd-shell .ev-grid{grid-template-columns:1fr;}}

/* ── Fixed-height white box: the page itself never scrolls; content scrolls inside the box (desktop) ── */
@media(min-width:901px){
  html:has(.app-shell),body:has(.app-shell){height:100%;overflow:hidden!important;}
  body:has(.app-shell) .main,body.light-theme:has(.app-shell) .main{height:calc(100vh - 96px)!important;min-height:0!important;overflow:visible!important;display:flex!important;flex-direction:column;}
  .main > .alert{flex:none;}
  .main > .view-content.active{flex:1;min-height:0;display:flex;flex-direction:column;}
  .main > .view-content.active > .sd-shell{flex:1;min-height:0;overflow-y:auto;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:#d7dee8 transparent;}
  .sd-shell::-webkit-scrollbar{width:8px;}
  .sd-shell::-webkit-scrollbar-thumb{background:#d7dee8;border-radius:8px;}
  body:not(.light-theme) .sd-shell{scrollbar-color:rgba(255,255,255,.18) transparent;}
  body:not(.light-theme) .sd-shell::-webkit-scrollbar-thumb{background:rgba(255,255,255,.18);}
}

/* ── Notifications: always-available bell, scrollable list ── */
.notif-bell{transition:border-color .15s,box-shadow .15s;}
.notif-bell:hover{border-color:#d99a2b!important;}
.notif-bell:focus-visible{outline:2px solid #d99a2b;outline-offset:2px;}
.notif-dropdown{width:350px;z-index:300;border-radius:14px;box-shadow:0 16px 44px rgba(15,23,42,.22);}
.notif-dd-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 16px;font-size:13px;}
.notif-dd-count{min-width:22px;padding:1px 8px;border-radius:999px;background:#fdf0d5;color:#b7791f;font-size:11px;font-weight:700;text-align:center;}
.notif-dd-count:empty{display:none;}
.notif-dd-list{max-height:min(60vh,340px);overflow-y:auto;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:#d7dee8 transparent;padding:8px;display:flex;flex-direction:column;gap:2px;}
.notif-dd-list::-webkit-scrollbar{width:7px;}
.notif-dd-list::-webkit-scrollbar-thumb{background:#d7dee8;border-radius:7px;}
body:not(.light-theme) .notif-dd-list{scrollbar-color:rgba(255,255,255,.2) transparent;}
body:not(.light-theme) .notif-dd-list::-webkit-scrollbar-thumb{background:rgba(255,255,255,.2);}
.notif-dd-list li{flex:none;align-items:flex-start;gap:10px;padding:10px 11px;font-size:12.5px;line-height:1.5;}
.notif-dd-list li i{flex:none;width:28px;height:28px;margin:0;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:#fdf0d5;color:#b7791f;font-size:12px;}
.notif-dd-list li span{flex:1;min-width:0;word-break:break-word;}
.notif-dd-list li.unseen{background:#fff8e8;}
.notif-dd-list li.unseen span{font-weight:600;}
body:not(.light-theme) .notif-dd-list li.unseen{background:rgba(217,154,43,.12);}
@media(max-width:600px){.notif-dropdown{position:fixed;top:64px;left:14px;right:14px;width:auto;max-width:none;}}
@media print{.student-topbar{display:none!important;}body:has(.app-shell) .main,body.light-theme:has(.app-shell) .main{margin-top:18px!important;}}
</style>

<style id="student-settings-principal-match">
/* ================================================================
   Student settings — interface matched to the Principal settings page:
   coloured top-bar cards with icon tiles, full-width Theme card with
   Light/Dark/System, Interface spacing + Accessibility side by side,
   inline Profile Photo uploader, SVG password eye toggles.
   ================================================================ */
#view-settings{--ap-card:#FFFFFF;--ap-soft:#F8FAFC;--ap-line:#E2E8F0;--ap-text:#0F172A;--ap-muted:#64748B;--ap-accent:#D97706;--ap-accent-bg:rgba(217,119,6,.10);--ap-ring:rgba(217,119,6,.14);--ap-input-bg:#FFFFFF;--ap-input-line:#CBD5E1;--ap-track:#CBD5E1;}
body:not(.light-theme) #view-settings{--ap-card:rgba(10,25,47,.55);--ap-soft:rgba(10,25,47,.45);--ap-line:rgba(255,255,255,.10);--ap-text:#E0E6F0;--ap-muted:#A0B3C6;--ap-accent-bg:rgba(217,119,6,.16);--ap-input-bg:#0F1F3D;--ap-input-line:rgba(255,255,255,.14);--ap-track:#334766;}

/* Section cards */
#view-settings .ac-card{position:relative;overflow:hidden;background:var(--ap-card);border:1px solid var(--ap-line);border-radius:15px;padding:22px 24px 24px;margin-bottom:20px;box-shadow:0 1px 5px rgba(15,23,42,.035);}
#view-settings .ac-card::before{content:'';position:absolute;left:0;right:0;top:0;height:3px;background:var(--ac-c);}
#view-settings .ac-c1{--ac-c:#D97706;}
#view-settings .ac-c2{--ac-c:#10B981;}
#view-settings .ac-c3{--ac-c:#D88900;}
#view-settings .ac-c4{--ac-c:#3B82F6;}
#view-settings .ac-head{display:flex;align-items:flex-start;gap:14px;margin-bottom:18px;}
#view-settings .ac-icon{width:42px;height:42px;flex:0 0 42px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;font-size:17px;background:color-mix(in srgb,var(--ac-c) 14%,transparent);color:var(--ac-c);}
#view-settings .ac-icon i{font-size:17px;color:var(--ac-c);}
#view-settings .ac-head h2{display:block;margin:0 0 4px;font-family:'Rajdhani',sans-serif;font-size:21px;font-weight:800;line-height:1.15;letter-spacing:0;color:var(--ap-text);}
#view-settings .ac-head .ac-sub{margin:0;font-size:13px;line-height:1.5;color:var(--ap-muted);}
#view-settings .ac-head.appearance-heading-row .appearance-device-badge{margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:999px;font-size:11.5px;font-weight:600;white-space:nowrap;background:var(--ap-soft);border:1px solid var(--ap-line);color:var(--ap-muted);}

/* Appearance grid */
#view-settings .appearance-feature-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;}
#view-settings .appearance-theme-card{grid-column:1 / -1;}
#view-settings .appearance-control-card{display:block;background:var(--ap-soft);border:1px solid var(--ap-line);border-radius:14px;padding:18px;}
#view-settings .appearance-control-head{display:flex;align-items:flex-start;gap:12px;margin-bottom:14px;}
#view-settings .appearance-control-icon{width:34px;height:34px;flex:0 0 34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:var(--ap-accent-bg);color:var(--ap-accent);font-size:14px;}
#view-settings .appearance-control-head h3{font-family:'DM Sans',sans-serif;font-size:15px;font-weight:700;color:var(--ap-text);margin:0 0 2px;}
#view-settings .appearance-control-head p{font-size:12.5px;color:var(--ap-muted);margin:0;line-height:1.45;}

/* Theme options (3 wide horizontal cards) */
#view-settings .appearance-theme-options{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;}
#view-settings .appearance-choice{position:relative;display:flex;flex-direction:row;align-items:center;justify-content:flex-start;gap:12px;width:auto;height:auto;min-height:0;padding:14px;text-align:left;border-radius:12px;border:1.5px solid var(--ap-line);background:var(--ap-card);color:var(--ap-text);font-family:'DM Sans',sans-serif;cursor:pointer;box-shadow:none;transition:.18s ease;}
#view-settings .appearance-choice:hover{border-color:var(--ap-accent);color:var(--ap-text);}
#view-settings .appearance-choice-icon{width:36px;height:36px;flex:0 0 36px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:var(--ap-soft);color:var(--ap-muted);font-size:15px;}
#view-settings .appearance-choice-copy{display:block;min-width:0;}
#view-settings .appearance-choice-copy strong,#view-settings .appearance-density-choice strong,#view-settings .appearance-motion-copy strong{display:block;font-size:13.5px;font-weight:700;color:var(--ap-text);}
#view-settings .appearance-choice-copy small,#view-settings .appearance-density-choice small,#view-settings .appearance-motion-copy small{display:block;font-size:11.5px;font-weight:400;color:var(--ap-muted);margin-top:1px;}
#view-settings .appearance-choice-check{margin-left:auto;width:20px;height:20px;flex:0 0 20px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:10px;background:var(--ap-accent);color:#fff;opacity:0;transform:scale(.6);transition:.2s;}
#view-settings .appearance-choice[aria-pressed="true"]{border-color:var(--ap-accent);background:var(--ap-accent-bg);box-shadow:0 0 0 3px var(--ap-ring);}
#view-settings .appearance-choice[aria-pressed="true"] .appearance-choice-icon{background:var(--ap-accent);color:#fff;}
#view-settings .appearance-choice[aria-pressed="true"] .appearance-choice-check{opacity:1;transform:scale(1);}
#view-settings .appearance-inline-status{margin-top:14px;font-size:12.5px;color:var(--ap-muted);display:flex;align-items:center;gap:7px;}
#view-settings .appearance-inline-status i{color:var(--ap-accent);}
#view-settings .appearance-inline-status strong{color:var(--ap-text);}

/* Interface spacing */
#view-settings .appearance-density-options{display:grid;grid-template-columns:1fr;gap:10px;}
#view-settings .appearance-density-choice{display:flex;align-items:center;gap:12px;width:auto;padding:12px 14px;text-align:left;border-radius:12px;border:1.5px solid var(--ap-line);background:var(--ap-card);color:var(--ap-muted);font-family:'DM Sans',sans-serif;cursor:pointer;transition:.18s ease;}
#view-settings .appearance-density-choice:hover{border-color:var(--ap-accent);}
#view-settings .appearance-density-choice > i{width:20px;flex:0 0 20px;font-size:15px;text-align:center;color:var(--ap-muted);}
#view-settings .appearance-density-choice[aria-pressed="true"]{border-color:var(--ap-accent);background:var(--ap-accent-bg);}
#view-settings .appearance-density-choice[aria-pressed="true"] > i{color:var(--ap-accent);}

/* Accessibility toggle */
#view-settings .appearance-motion-toggle{width:100%;display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 14px;text-align:left;border-radius:12px;border:1.5px solid var(--ap-line);background:var(--ap-card);font-family:'DM Sans',sans-serif;cursor:pointer;transition:.18s ease;}
#view-settings .appearance-motion-toggle:hover{border-color:var(--ap-accent);}
#view-settings .appearance-motion-toggle[aria-pressed="true"]{border-color:var(--ap-accent);background:var(--ap-accent-bg);}
#view-settings .appearance-switch-track{width:44px;height:24px;flex:0 0 44px;border-radius:999px;background:var(--ap-track);position:relative;transition:background .2s;}
#view-settings .appearance-switch-thumb{position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .2s;}
#view-settings .appearance-motion-toggle[aria-pressed="true"] .appearance-switch-track{background:var(--ap-accent);}
#view-settings .appearance-motion-toggle[aria-pressed="true"] .appearance-switch-thumb{transform:translateX(20px);}
#view-settings .appearance-accessibility-note{margin-top:12px;font-size:11.5px;color:var(--ap-muted);display:flex;gap:7px;align-items:flex-start;line-height:1.5;}
#view-settings .appearance-accessibility-note i{color:var(--ap-accent);margin-top:2px;}

/* Profile photo */
#view-settings .profile-card{display:flex;align-items:center;gap:24px;}
#view-settings .profile-photo-lg{width:96px;height:96px;border-radius:50%;object-fit:cover;border:3px solid var(--ap-accent);flex:0 0 96px;background:var(--ap-soft);}
#view-settings .photo-form-wrap input[type="file"]{font:13px 'DM Sans',sans-serif;color:var(--ap-text);padding:2px 4px;width:auto;max-width:100%;border:1px solid var(--ap-input-line);border-radius:6px;background:var(--ap-input-bg);}
#view-settings .photo-help{font-size:12px;color:var(--ap-muted);margin-top:8px;line-height:1.5;}

/* Forms */
#view-settings .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:17px 20px;margin-bottom:20px;}
#view-settings .form-group{display:flex;flex-direction:column;gap:7px;}
#view-settings .form-group label{font-size:11px;font-weight:600;letter-spacing:.8px;text-transform:uppercase;color:var(--ap-muted);margin:0;}
#view-settings .form-group input,#view-settings .form-group select{width:100%;padding:12px 13px;font:14px 'DM Sans',sans-serif;border-radius:9px;background:var(--ap-input-bg);color:var(--ap-text);border:1px solid var(--ap-input-line);}
#view-settings .form-group input:focus,#view-settings .form-group select:focus{outline:none;border-color:var(--ap-accent);box-shadow:0 0 0 3px var(--ap-ring);}
#view-settings .form-group input[readonly],#view-settings .form-group select:disabled{background:var(--ap-soft);color:var(--ap-muted);cursor:not-allowed;opacity:.85;}
#view-settings .alert{border-radius:10px;padding:12px 15px;font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:9px;}

/* Buttons */
#view-settings .ac-btn{display:inline-flex;align-items:center;gap:8px;padding:11px 17px;border:0;border-radius:9px;font:600 14px 'DM Sans',sans-serif;color:#fff;background:var(--ap-accent);box-shadow:0 4px 14px rgba(217,119,6,.25);cursor:pointer;transition:.18s ease;}
#view-settings .ac-btn:hover{background:#F59E0B;color:#fff;transform:translateY(-1px);}
#view-settings .ac-btn:disabled{opacity:.6;cursor:not-allowed;transform:none;}
#view-settings a.ac-btn{text-decoration:none;}
#view-settings .ac-c5{--ac-c:#8B5CF6;}
#view-settings .sq-status{display:inline-flex;align-items:center;gap:7px;padding:5px 11px;margin-bottom:16px;border-radius:999px;font-size:12px;font-weight:600;background:var(--ap-soft);border:1px solid var(--ap-line);color:var(--ap-muted);}
#view-settings .sq-status.ok{color:#10B981;border-color:rgba(16,185,129,.35);}
#view-settings .sq-status.warn{color:#D97706;border-color:rgba(217,119,6,.4);}

/* Password eye toggle */
#view-settings .pw-wrap{position:relative;display:block;}
#view-settings .pw-wrap input{width:100%;padding-right:46px;}
#view-settings .pw-toggle{position:absolute;top:50%;right:8px;transform:translateY(-50%);width:34px;height:34px;display:flex;align-items:center;justify-content:center;border:0;border-radius:8px;background:transparent;color:var(--ap-muted);cursor:pointer;padding:0;}
#view-settings .pw-toggle .pw-eye{display:none;}
#view-settings .pw-toggle .pw-eye-off{display:block;}
#view-settings .pw-toggle[aria-pressed="true"] .pw-eye{display:block;}
#view-settings .pw-toggle[aria-pressed="true"] .pw-eye-off{display:none;}
#view-settings .pw-toggle:hover{color:#B45309;background:rgba(217,154,43,.12);}
#view-settings .pw-toggle:focus-visible{outline:2px solid #D99A2B;outline-offset:1px;}

/* Interface spacing = Compact (applies across the student portal) */
body.density-compact .sd-shell{padding:16px 22px 20px;}
body.density-compact #view-settings .ac-card{padding:16px 18px 18px;margin-bottom:14px;}
body.density-compact #view-settings .ac-head{margin-bottom:12px;}
body.density-compact #view-settings .form-grid{gap:12px 16px;margin-bottom:14px;}
body.density-compact #view-settings .appearance-feature-grid{gap:12px;}
body.density-compact .sd-shell .gl-card,body.density-compact .sd-shell .history-card{padding-top:10px;padding-bottom:10px;margin-bottom:8px;}
body.density-compact .sd-shell .ev-row{padding-top:7px;padding-bottom:7px;}
body.density-compact .sd-shell .ev-grid{gap:7px 10px;}
body.density-compact .sd-shell .sd-stats,body.density-compact .sd-shell .sd-shortcuts{gap:10px;}

/* Accessibility = Reduce animations */
body.reduce-motion *,body.reduce-motion *::before,body.reduce-motion *::after{animation-duration:.001ms!important;animation-iteration-count:1!important;transition-duration:.001ms!important;scroll-behavior:auto!important;}

@media (max-width:900px){#view-settings .appearance-theme-options{grid-template-columns:1fr;}#view-settings .form-grid{grid-template-columns:1fr;}}
@media (max-width:768px){#view-settings .appearance-feature-grid{grid-template-columns:1fr;}#view-settings .appearance-device-badge{display:none;}}
@media (max-width:650px){#view-settings .profile-card{align-items:flex-start;flex-direction:column;}#view-settings .ac-card{padding:18px 18px 20px;}}
/* ══════════════════════════════════════════════════════════════
   EVALUATION FORM — Principal-style size and look
   (mirrors .de-modal in principal_evaluations.php)
   Size + amber rating colours apply in both themes; the white
   Principal look is scoped to body.light-theme.
   ══════════════════════════════════════════════════════════════ */
:is(#evalModal,#viewEvalModal).modal-overlay{align-items:center!important;padding:10px 20px!important;background:rgba(10,25,47,.55)!important;-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);}
:is(#evalModal,#viewEvalModal) .modal{max-width:780px!important;height:96vh;max-height:calc(100vh - 20px)!important;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.35);}
:is(#evalModal,#viewEvalModal) .modal-header{padding:20px 28px;}
:is(#evalModal,#viewEvalModal) .modal-avatar,:is(#evalModal,#viewEvalModal) .modal-avatar-ph{width:52px;height:52px;}
:is(#evalModal,#viewEvalModal) .modal-body{padding:24px 28px 18px;}
:is(#evalModal,#viewEvalModal) .modal-footer{padding:20px 28px;gap:12px;}
:is(#evalModal,#viewEvalModal) .btn-cancel-modal,:is(#evalModal,#viewEvalModal) .btn-submit{padding:13px 20px;font-size:15px;border-radius:12px;}
:is(#evalModal,#viewEvalModal) .btn-submit{background:#D99A2B;box-shadow:0 8px 20px rgba(217,154,43,.30);}
:is(#evalModal,#viewEvalModal) .btn-submit:hover{background:#B45309;}

/* amber rating cells, 40x36 like the Principal form */
:is(#evalModal,#viewEvalModal) .eval-form-rating label{width:40px;height:36px;border-radius:9px;font-size:13px;font-weight:600;}
:is(#evalModal,#viewEvalModal) .eval-form-rating label:hover{border-color:#D99A2B;background:rgba(217,154,43,.08);}
:is(#evalModal,#viewEvalModal) .eval-form-rating input:checked + label{background:#D99A2B;border-color:#D99A2B;color:#fff;box-shadow:0 4px 12px rgba(217,154,43,.35);}
:is(#evalModal,#viewEvalModal) .eval-form-rating input:focus-visible + label{outline:2px solid #B45309;outline-offset:2px;}
:is(#evalModal,#viewEvalModal) .eval-progress{background:#FFF7ED;border:1px solid #FED7AA;font-size:13px;font-weight:600;}
:is(#evalModal,#viewEvalModal) .eval-progress .progress-status{color:#B45309;font-weight:700;}

/* category heading: dot instead of icon; question numbers bold black */
:is(#evalModal,#viewEvalModal) .eval-form-cat{display:flex;align-items:center;gap:9px;margin:18px 0 8px;font-size:12px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;color:#000;}
:is(#evalModal,#viewEvalModal) .eval-form-cat i{display:none;}
:is(#evalModal,#viewEvalModal) .eval-form-cat::before{content:"";width:9px;height:9px;border-radius:50%;background:#D99A2B;flex:0 0 auto;}
:is(#evalModal,#viewEvalModal) .eval-form-qno{color:#000;font-weight:800;}
body:not(.light-theme) :is(#evalModal,#viewEvalModal) .eval-form-cat,body:not(.light-theme) :is(#evalModal,#viewEvalModal) .eval-form-qno{color:var(--light);}

/* white Principal look (light theme) */
body.light-theme :is(#evalModal,#viewEvalModal) .modal{background:#fff;border:0;}
body.light-theme :is(#evalModal,#viewEvalModal) .modal-header{background:#fff;border-bottom:1px solid #D9E4EF;}
body.light-theme :is(#evalModal,#viewEvalModal) .modal-avatar,body.light-theme :is(#evalModal,#viewEvalModal) .modal-avatar-ph{border:2px solid #D9E4EF;}
body.light-theme :is(#evalModal,#viewEvalModal) .modal-name{color:#172033;}
body.light-theme :is(#evalModal,#viewEvalModal) .modal-desig{color:#64748B;}
body.light-theme :is(#evalModal,#viewEvalModal) .modal-footer{border-top:1px solid #D9E4EF;}
body.light-theme :is(#evalModal,#viewEvalModal) .btn-cancel-modal{background:#F5F7FB;color:#172033;border:1px solid #CBD5E1;}
body.light-theme :is(#evalModal,#viewEvalModal) .scale-legend{background:#F5F7FB;border:1px solid #D9E4EF;}
body.light-theme :is(#evalModal,#viewEvalModal) .eval-form-wrap{background:#fff;border:1px solid #D9E4EF;border-radius:12px;}
body.light-theme :is(#evalModal,#viewEvalModal) .eval-form-table th{background:#F4F8FF!important;color:#64748B!important;border-bottom:1px solid #D9E4EF!important;}
body.light-theme :is(#evalModal,#viewEvalModal) .eval-form-table td{border-bottom:1px solid #E8EEF5!important;}
body.light-theme :is(#evalModal,#viewEvalModal) .eval-form-rating label{border:1px solid #CBD5E1;background:#F5F7FB;color:#64748B;}
body.light-theme :is(#evalModal,#viewEvalModal) .eval-form-rating input:checked + label{background:#D99A2B;border-color:#D99A2B;color:#fff;}
body.light-theme :is(#evalModal,#viewEvalModal) .comment-box{background:transparent;padding:0;margin-top:20px;}
body.light-theme :is(#evalModal,#viewEvalModal) .comment-label{font-size:13px;color:#B45309;}
body.light-theme :is(#evalModal,#viewEvalModal) .comment-textarea{min-height:96px;background:#F5F7FB;color:#172033;border:1px solid #CBD5E1;border-radius:10px;padding:12px;font-size:13.5px;}
body.light-theme :is(#evalModal,#viewEvalModal) .comment-textarea:focus{border-color:#D99A2B;}
@media(max-width:640px){
    :is(#evalModal,#viewEvalModal) .modal-header{padding:16px 18px;}
    :is(#evalModal,#viewEvalModal) .modal-body{padding:18px;}
    :is(#evalModal,#viewEvalModal) .modal-footer{padding:14px 18px;gap:10px;}
}

/* read-only "View" modal — same look as the Principal's submitted-evaluation view */
#viewEvalModal .modal-body{padding:24px 28px 18px;}
#viewEvalModal .vw-summary{display:flex;align-items:baseline;gap:8px;margin-bottom:6px;}
#viewEvalModal .vw-summary .num{font-family:'Rajdhani',sans-serif;font-size:34px;font-weight:700;color:var(--light);}
#viewEvalModal .vw-summary .of{font-size:13px;color:var(--muted);}
#viewEvalModal .vw-sub{font-size:13px;color:var(--muted);margin-bottom:6px;}
#viewEvalModal .eval-form-cat,#viewEvalModal .eval-form-qno{color:#F0B84D;}
body.light-theme #viewEvalModal .eval-form-cat,body.light-theme #viewEvalModal .eval-form-qno{color:#B45309;}
#viewEvalModal .vw-table th:not(:first-child){width:88px;line-height:1.3;}
#viewEvalModal .vw-chip{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:28px;padding:0 8px;border-radius:8px;background:#D99A2B;color:#fff;font-weight:700;font-size:13px;}
#viewEvalModal .vw-comment{margin-top:20px;}
#viewEvalModal .vw-comment-label{font-size:13px;font-weight:700;color:#F0B84D;margin-bottom:8px;}
body.light-theme #viewEvalModal .vw-comment-label{color:#B45309;}
#viewEvalModal .vw-comment-text{font-size:13.5px;line-height:1.55;white-space:pre-wrap;color:var(--light);background:var(--inner);border:1px solid var(--border);border-radius:10px;padding:12px 14px;}
#viewEvalModal .vw-comment-text.vw-none{color:var(--muted);font-style:italic;}
#viewEvalModal .modal-footer .btn-cancel-modal{flex:0 0 auto;}

/* required comment — red outlined box + inline message (matches the Principal's form) */
.comment-req{color:#ef4444;font-weight:800;}
.comment-box.invalid{border:1px solid #f87171!important;box-shadow:0 0 0 3px rgba(239,68,68,.12);}
body.light-theme #evalModal .comment-box.invalid{padding:16px 20px 14px;border-radius:14px;background:#F8FAFF;}
.comment-error{margin-top:12px;font-size:13px;font-weight:700;color:#b91c1c;line-height:1.4;}
.comment-error[hidden]{display:none;}
body:not(.light-theme) .comment-error{color:#f87171;}

/* ══════════════════════════════════════════════════════════════
   EVALUATE PERSONNEL — matches the Executive Assistant's
   "Evaluate Others" page (ea_evaluation.php): header + period chip,
   group cards with progress, roster panel with stat pills.
   Loaded last on purpose.
   ══════════════════════════════════════════════════════════════ */
html body .sd-shell .eval-panel-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px 16px;margin-bottom:6px;}
html body .sd-shell .eval-main-card .eval-card-title{font-size:26px!important;margin:0!important;gap:11px;}
html body .sd-shell .eval-main-card .eval-card-title i{font-size:22px!important;}
html body .sd-shell .period-chip{display:inline-flex;align-items:center;gap:8px;padding:7px 14px;border-radius:999px;font-size:12.5px;font-weight:700;border:1px solid #C7D2FE;background:#EEF2FF;color:#3730A3;}
html body:not(.light-theme) .sd-shell .period-chip{background:rgba(129,140,248,.14);color:#C7D2FE;border-color:rgba(129,140,248,.35);}
html body .sd-shell .eval-panel-lead{margin:0 0 22px;font-size:14.5px;line-height:1.5;color:var(--sd-sub);}

/* group cards */
html body .sd-shell .eval-main-card .category-grid{grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:22px!important;margin-bottom:24px!important;}
html body .sd-shell .eval-main-card .cat-btn{--cat-color:var(--g,#2563EB)!important;position:relative;display:flex!important;flex-direction:column;align-items:center;justify-content:flex-start!important;min-height:320px!important;padding:36px 22px 24px!important;border-radius:18px!important;overflow:hidden;opacity:1!important;transform:none;background:var(--sd-card)!important;border:1px solid var(--sd-line)!important;box-shadow:0 1px 2px rgba(11,31,58,.06),0 8px 22px rgba(11,31,58,.06)!important;transition:transform .16s ease,border-color .16s ease,box-shadow .16s ease;}
html body .sd-shell .eval-main-card .cat-btn::before{content:"";position:absolute;left:0;right:0;top:0;height:5px;background:var(--g);}
html body .sd-shell .eval-main-card .cat-btn::after{display:none!important;}
html body .sd-shell .eval-main-card .cat-btn:hover{transform:translateY(-3px);border-color:var(--g)!important;box-shadow:0 14px 32px rgba(11,31,58,.13)!important;}
html body .sd-shell .eval-main-card .cat-btn.active{transform:none;border-color:var(--g)!important;box-shadow:0 0 0 3px color-mix(in srgb,var(--g) 22%,transparent),0 12px 28px rgba(11,31,58,.12)!important;}
html body .sd-shell .eval-main-card .cat-btn:focus-visible{outline:3px solid var(--g);outline-offset:3px;}
html body .sd-shell .eval-main-card .cat-icon{width:88px!important;height:88px!important;font-size:34px!important;margin-bottom:16px!important;border-radius:50%;display:flex;align-items:center;justify-content:center;color:var(--g)!important;background:var(--g-bg)!important;}
html body:not(.light-theme) .sd-shell .eval-main-card .cat-icon{background:color-mix(in srgb,var(--g) 16%,transparent)!important;}
html body .sd-shell .eval-main-card .cat-name{font-family:'Rajdhani',sans-serif;font-size:24px!important;font-weight:700;margin:0 0 2px!important;color:var(--sd-ink)!important;}
html body .sd-shell .eval-main-card .cat-meta{display:inline-flex;align-items:baseline;gap:6px;margin-top:10px;padding:6px 16px;border-radius:999px;font-size:13.5px!important;font-weight:700;background:var(--g-bg);color:var(--g-ink);}
html body .sd-shell .eval-main-card .cat-meta strong{font-size:19px;font-weight:800;line-height:1;}
html body:not(.light-theme) .sd-shell .eval-main-card .cat-meta{background:rgba(255,255,255,.08);color:var(--sd-ink);}
html body .sd-shell .group-progress{display:flex;flex-direction:column;align-items:center;gap:7px;width:100%;max-width:230px;margin-top:20px;}
html body .sd-shell .group-progress-bar{display:block;width:100%;height:8px;border-radius:999px;overflow:hidden;background:rgba(100,116,139,.2);}
html body .sd-shell .group-progress-bar i{display:block;height:100%;border-radius:999px;background:var(--g);transition:width .3s ease;}
html body .sd-shell .group-progress.complete .group-progress-bar i{background:#16A34A;}
html body .sd-shell .group-progress-text{font-size:13px;font-weight:500;color:var(--sd-sub);}
html body .sd-shell .group-progress-text strong{color:var(--sd-ink);font-weight:800;}
html body .sd-shell .group-cta{display:inline-flex;align-items:center;margin-top:auto;padding-top:20px;font-size:13px;font-weight:800;color:var(--g-ink);}
html body:not(.light-theme) .sd-shell .group-cta{color:var(--g);}
html body .sd-shell .group-cta i{font-size:12px;margin-left:6px;transition:transform .15s ease;}
html body .sd-shell .cat-btn:hover .cta-off i{transform:translateX(4px);}
html body .sd-shell .group-cta .cta-on{display:none;}
html body .sd-shell .cat-btn.active .group-cta .cta-on{display:inline;}
html body .sd-shell .cat-btn.active .group-cta .cta-off{display:none;}

/* roster panel */
html body .sd-shell .ev-panel{margin:24px 0 0!important;border-radius:18px!important;overflow:hidden;background:var(--sd-card)!important;border:1px solid var(--sd-line)!important;}
html body .sd-shell .ev-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px 16px;padding:20px 26px!important;border-bottom:1px solid var(--sd-line);background:#f8fbff;}
html body:not(.light-theme) .sd-shell .ev-head{background:rgba(255,255,255,.03);}
html body .sd-shell .ev-head-left{display:flex;align-items:center;gap:11px;}
html body .sd-shell .ev-head-icon{width:42px!important;height:42px!important;border-radius:10px!important;font-size:17px!important;background:#edf4fd!important;color:#2F6EE2!important;}
html body:not(.light-theme) .sd-shell .ev-head-icon{background:#1D3A60!important;color:#78AFFF!important;}
html body .sd-shell .ev-head-title{font-family:'Rajdhani',sans-serif;font-size:24px!important;font-weight:700;color:var(--sd-ink)!important;}
html body .sd-shell .ev-stats{display:flex;align-items:center;flex-wrap:wrap;gap:8px 10px;}
html body .sd-shell .ev-stats span{padding:6px 14px;border-radius:999px;font-size:13px;font-weight:600;border:1px solid #D3DCE8;background:#EEF2F7;color:#334155;}
html body .sd-shell .ev-stats strong{font-size:15px;font-weight:800;color:inherit;}
html body .sd-shell .ev-stats .s-done{background:#DDF4E6;color:#0A6A3B;border-color:#9ED8B7;}
html body .sd-shell .ev-stats .s-pend{background:#FFF1D4;color:#8A5300;border-color:#EBCB88;}
html body .sd-shell .ev-stats .s-period{background:#EEF2FF;color:#3730A3;border-color:#C7D2FE;}
html body .sd-shell .ev-stats .s-period i{margin-right:6px;}
html body:not(.light-theme) .sd-shell .ev-stats span{background:rgba(255,255,255,.06);color:var(--sd-ink);border-color:var(--sd-line);}
html body:not(.light-theme) .sd-shell .ev-stats .s-done{background:rgba(52,211,153,.12);color:#6EE7B7;border-color:rgba(52,211,153,.25);}
html body:not(.light-theme) .sd-shell .ev-stats .s-pend{background:rgba(245,158,11,.12);color:#F8C465;border-color:rgba(245,158,11,.28);}
html body:not(.light-theme) .sd-shell .ev-stats .s-period{background:rgba(129,140,248,.14);color:#C7D2FE;border-color:rgba(129,140,248,.35);}
html body .sd-shell .ev-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px!important;padding:22px!important;}
html body .sd-shell .ev-row{gap:16px!important;padding:16px 18px!important;border-radius:14px!important;border-left:4px solid #F59E0B!important;}
html body .sd-shell .ev-row.is-done{border-left-color:#16A34A!important;}
html body .sd-shell .ev-row:hover{box-shadow:0 6px 18px rgba(11,31,58,.10);}
html body .sd-shell .ev-avatar{width:56px!important;height:56px!important;}
html body .sd-shell .ev-name{font-size:15.5px!important;font-weight:800;}
html body .sd-shell .ev-desig{font-size:13px!important;margin-top:2px;}
html body .sd-shell .ev-badge{display:inline-flex;align-items:center;gap:5px;margin-top:8px;padding:4px 11px!important;border-radius:999px;font-size:12px!important;font-weight:800;}
html body .sd-shell .ev-badge-done{background:#D6F2E4!important;color:#0A6A3B!important;border:1px solid #8ED0AD!important;}
html body .sd-shell .ev-badge-pending{background:#FFF1D4!important;color:#8A5300!important;border:1px solid #EBCB88!important;}
html body:not(.light-theme) .sd-shell .ev-badge-done{background:rgba(52,211,153,.12)!important;color:#6EE7B7!important;border-color:rgba(52,211,153,.25)!important;}
html body:not(.light-theme) .sd-shell .ev-badge-pending{background:rgba(245,158,11,.12)!important;color:#F8C465!important;border-color:rgba(245,158,11,.28)!important;}
html body .sd-shell .ev-btn{height:auto!important;min-width:112px;padding:11px 18px!important;border-radius:10px!important;font-size:13px!important;font-weight:800;justify-content:center;}
html body .sd-shell .ev-btn-eval{background:#0E9F6E!important;border:1px solid #0E9F6E!important;color:#fff!important;}
html body .sd-shell .ev-btn-eval:hover:not(:disabled){background:#0B8A5F!important;border-color:#0B8A5F!important;}
html body .sd-shell .ev-btn-eval:disabled{background:#f1f5f9!important;border-color:#e2e8f0!important;color:#94a3b8!important;}
html body .sd-shell .ev-btn-view{background:#fff!important;color:#1e293b!important;border:1px solid #b6c3d6!important;}
html body .sd-shell .ev-btn-view:hover{background:#F1F5FB!important;}
html body:not(.light-theme) .sd-shell .ev-btn-view{background:transparent!important;color:#B1C1D4!important;border-color:#34506F!important;}
html body:not(.light-theme) .sd-shell .ev-btn-view:hover{background:#172E4C!important;color:#fff!important;}
html body:not(.light-theme) .sd-shell .ev-btn-eval:disabled{background:rgba(255,255,255,.06)!important;border-color:var(--sd-line)!important;color:var(--muted)!important;}
@media(max-width:900px){
  html body .sd-shell .eval-main-card .category-grid{grid-template-columns:1fr!important;}
  html body .sd-shell .eval-main-card .cat-btn{min-height:260px!important;}
  html body .sd-shell .ev-grid{grid-template-columns:1fr;}
}
@media(max-width:560px){
  html body .sd-shell .eval-main-card .cat-btn{min-height:0!important;}
  html body .sd-shell .ev-head{align-items:flex-start;flex-direction:column;}
  html body .sd-shell .ev-row{flex-wrap:wrap;}
  html body .sd-shell .ev-btn{width:100%;}
}

/* ══════════════════════════════════════════════════════════════
   EVALUATION FORM — same size as the EA form (ea_evaluate.php)
   780px wide, 96vh tall, 28px side padding, compact 34x30 rating cells.
   ══════════════════════════════════════════════════════════════ */
:is(#evalModal,#viewEvalModal).modal-overlay{padding:10px 20px!important;}
:is(#evalModal,#viewEvalModal) .modal{max-width:780px!important;height:96vh;max-height:calc(100vh - 20px)!important;border-radius:18px!important;}
:is(#evalModal,#viewEvalModal) .modal-header{padding:24px 28px 16px!important;gap:14px;}
:is(#evalModal,#viewEvalModal) .modal-avatar,:is(#evalModal,#viewEvalModal) .modal-avatar-ph{width:52px!important;height:52px!important;}
:is(#evalModal,#viewEvalModal) .modal-name{font-size:20px!important;}
:is(#evalModal,#viewEvalModal) .modal-desig{font-size:12px!important;}
:is(#evalModal,#viewEvalModal) .modal-body{padding:24px 28px!important;}
:is(#evalModal,#viewEvalModal) .modal-footer{padding:16px 28px 24px!important;gap:12px;}
:is(#evalModal,#viewEvalModal) .btn-cancel-modal{padding:13px 22px!important;font-size:14px!important;border-radius:10px!important;}
:is(#evalModal,#viewEvalModal) .btn-submit{padding:13px!important;font-size:15px!important;border-radius:10px!important;}
:is(#evalModal,#viewEvalModal) .scale-legend{gap:16px;margin-bottom:18px;padding:10px 14px;border-radius:8px;}
:is(#evalModal,#viewEvalModal) .legend-item{font-size:11px;}
:is(#evalModal,#viewEvalModal) .eval-progress{padding:9px 12px!important;font-size:11.5px!important;border-radius:8px;margin-bottom:14px;}
:is(#evalModal,#viewEvalModal) .eval-form-cat{font-size:10px!important;letter-spacing:.9px!important;margin:18px 0 8px!important;}
:is(#evalModal,#viewEvalModal) .eval-form-table{table-layout:fixed;min-width:650px;}
:is(#evalModal,#viewEvalModal) .eval-form-table th{font-size:10px;padding:10px 6px;}
:is(#evalModal,#viewEvalModal) .eval-form-table th:first-child{min-width:360px;padding-left:14px;}
:is(#evalModal,#viewEvalModal) .eval-form-table th:not(:first-child){width:52px;}
:is(#evalModal,#viewEvalModal) .eval-form-table td{padding:10px 6px;}
:is(#evalModal,#viewEvalModal) .eval-form-table td:first-child{min-width:360px;padding-left:14px;padding-right:10px;}
:is(#evalModal,#viewEvalModal) .eval-form-qtext{font-size:12.5px;line-height:1.45;}
:is(#evalModal,#viewEvalModal) .eval-form-rating label,:is(#evalModal,#viewEvalModal) .eval-form-static span{width:34px!important;height:30px!important;border-radius:7px!important;font-size:12px!important;font-weight:800!important;}
:is(#evalModal,#viewEvalModal) .comment-label{font-size:12px!important;}
:is(#evalModal,#viewEvalModal) .comment-textarea{min-height:100px!important;font-size:13px!important;padding:12px 14px!important;}
@media(max-width:700px){
  :is(#evalModal,#viewEvalModal).modal-overlay{padding:10px!important;}
  :is(#evalModal,#viewEvalModal) .modal-header{padding:16px 18px!important;}
  :is(#evalModal,#viewEvalModal) .modal-body{padding:18px!important;}
  :is(#evalModal,#viewEvalModal) .modal-footer{padding:14px 18px!important;}
}

/* Evaluation form: no "Question 5 4 3 2 1" header row and no "x / y answered" strip */
#evalModal .eval-form-table thead{display:none;}
#evalModal .eval-progress{display:none!important;}
#evalModal .eval-form-table td:not(:first-child){width:52px;}  /* keeps the rating columns aligned when the header is hidden */

/* ── Evaluate tab: one accent only (the student portal's amber) ── */
html body .sd-shell .period-chip{background:#FDF0D5;color:#8A5300;border-color:#F0D49A;}
html body:not(.light-theme) .sd-shell .period-chip{background:rgba(217,154,43,.14);color:#F0B84D;border-color:rgba(217,154,43,.35);}
html body .sd-shell .group-progress.complete .group-progress-bar i{background:var(--g);}
html body .sd-shell .ev-head-icon{background:#FDF0D5!important;color:#B45309!important;}
html body:not(.light-theme) .sd-shell .ev-head-icon{background:rgba(217,154,43,.16)!important;color:#F0B84D!important;}
html body .sd-shell .ev-stats .s-done{background:#FDF0D5;color:#8A5300;border-color:#F0D49A;}
html body .sd-shell .ev-stats .s-pend{background:#EEF2F7;color:#475569;border-color:#D3DCE8;}
html body .sd-shell .ev-stats .s-period{background:transparent;color:#8A5300;border-color:#F0D49A;}
html body:not(.light-theme) .sd-shell .ev-stats .s-done{background:rgba(217,154,43,.14);color:#F0B84D;border-color:rgba(217,154,43,.35);}
html body:not(.light-theme) .sd-shell .ev-stats .s-pend{background:rgba(255,255,255,.06);color:var(--sd-sub);border-color:var(--sd-line);}
html body:not(.light-theme) .sd-shell .ev-stats .s-period{background:transparent;color:#F0B84D;border-color:rgba(217,154,43,.35);}
html body .sd-shell .ev-row{border-left-color:#CBD5E1!important;}
html body .sd-shell .ev-row.is-done{border-left-color:#D99A2B!important;}
html body:not(.light-theme) .sd-shell .ev-row{border-left-color:rgba(255,255,255,.18)!important;}
html body:not(.light-theme) .sd-shell .ev-row.is-done{border-left-color:#D99A2B!important;}
html body .sd-shell .ev-badge-done{background:#FDF0D5!important;color:#8A5300!important;border:1px solid #F0D49A!important;}
html body .sd-shell .ev-badge-pending{background:#EEF2F7!important;color:#475569!important;border:1px solid #D3DCE8!important;}
html body:not(.light-theme) .sd-shell .ev-badge-done{background:rgba(217,154,43,.14)!important;color:#F0B84D!important;border-color:rgba(217,154,43,.35)!important;}
html body:not(.light-theme) .sd-shell .ev-badge-pending{background:rgba(255,255,255,.06)!important;color:var(--sd-sub)!important;border-color:var(--sd-line)!important;}
html body .sd-shell .ev-btn-eval{background:#D99A2B!important;border-color:#D99A2B!important;color:#fff!important;}
html body .sd-shell .ev-btn-eval:hover:not(:disabled){background:#B45309!important;border-color:#B45309!important;}
html body .sd-shell .ev-btn-view:hover{border-color:#D99A2B!important;}

/* ── Settings tabs: Profile & Security / Appearance (same as the Principal's account settings) ── */
#view-settings .settings-tabs{display:flex;gap:6px;margin:0 0 18px;padding:5px;border:1px solid #D9E4EF;border-radius:12px;background:#F4F8FF;width:fit-content;max-width:100%;overflow-x:auto;}
#view-settings .settings-tab{appearance:none;border:0;background:transparent;color:#64748B;font:inherit;font-size:13.5px;font-weight:600;padding:9px 18px;border-radius:9px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;white-space:nowrap;transition:background .2s,color .2s,box-shadow .2s;}
#view-settings .settings-tab i{color:#D99A2B;}
#view-settings .settings-tab:hover{color:#172033;background:rgba(217,154,43,.12);}
#view-settings .settings-tab[aria-selected="true"]{background:#FFFFFF;color:#172033;box-shadow:0 1px 5px rgba(15,23,42,.10);}
#view-settings .settings-tab:focus-visible{outline:2px solid #D99A2B;outline-offset:2px;}
#view-settings .settings-tabpanel[hidden]{display:none!important;}
body:not(.light-theme) #view-settings .settings-tabs{background:rgba(10,25,47,.55);border-color:rgba(255,255,255,.10);}
body:not(.light-theme) #view-settings .settings-tab{color:#A0B3C6;}
body:not(.light-theme) #view-settings .settings-tab:hover{color:#fff;background:rgba(217,154,43,.15);}
body:not(.light-theme) #view-settings .settings-tab[aria-selected="true"]{background:rgba(217,154,43,.18);color:#fff;box-shadow:none;}

/* "Leave without submitting?" dialog (same as the EA evaluation form) */
.leave-dlg-overlay{position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(9,17,30,.55);}
.leave-dlg{width:100%;max-width:420px;background:#fff;color:#10243f;border:1px solid #d7e3ef;border-radius:18px;box-shadow:0 30px 80px rgba(15,23,42,.35);padding:26px 28px 24px;font-family:'Inter',Segoe UI,Arial,sans-serif;}
body:not(.light-theme) .leave-dlg{background:var(--mid);color:var(--light);border-color:var(--border);}
.leave-dlg h3{margin:0 0 8px;font-size:16px;font-weight:700;}
.leave-dlg p{margin:0 0 22px;font-size:13.5px;line-height:1.55;color:#66809c;}
body:not(.light-theme) .leave-dlg p{color:var(--muted);}
.leave-dlg-actions{display:flex;gap:12px;}
.leave-dlg-actions button{flex:1;padding:12px 16px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;}
.leave-dlg-stay{background:#f8fbff;border:1px solid #dce8f5;color:#10243f;}
.leave-dlg-stay:hover{background:#eef4fb;}
body:not(.light-theme) .leave-dlg-stay{background:var(--inner);border-color:var(--border);color:var(--light);}
.leave-dlg-leave{background:#D97706;border:1px solid #D97706;color:#fff;}
.leave-dlg-leave:hover{background:#B45F04;}
</style>
</head>
<body class="light-theme">

<!-- LOG OUT CONFIRMATION MODAL -->
<div class="logout-modal-overlay" id="logoutModal" role="dialog" aria-modal="true" aria-labelledby="logoutModalTitle" onclick="if(event.target===this)closeLogoutConfirm()">
    <div class="logout-modal">
        <div class="logout-modal-title" id="logoutModalTitle">Are you sure you want to log out?</div>
        <div class="logout-modal-actions">
            <button type="button" class="btn-logout-cancel" id="logoutCancelBtn" onclick="closeLogoutConfirm()">Cancel</button>
            <a href="../logout.php" class="btn-logout-confirm">OK</a>
        </div>
    </div>
</div>

<!-- PHOTO UPLOAD MODAL -->
<div class="photo-modal-overlay" id="photoModal">
    <div class="photo-modal">
        <div class="photo-modal-header">
            <div class="photo-modal-title">Profile Photo</div>
            <button style="background:none;border:none;color:var(--muted);font-size:18px;cursor:pointer;" onclick="closePhotoModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="photo-modal-body">
            <form method="POST" action="update_photo.php" enctype="multipart/form-data" id="photoForm">
                <div class="photo-upload-circle" id="photoCircle" onclick="document.getElementById('photoFileInput').click()">
                    <img id="photoPreviewImg" src="<?= $student_photo ? '../image/'.htmlspecialchars($student_photo) : '' ?>"
                         style="<?= $student_photo ? 'display:block' : '' ?>"/>
                    <i class="fa-solid fa-camera upload-icon" id="uploadIconEl" style="<?= $student_photo ? 'display:none' : '' ?>"></i>
                </div>
                <div class="photo-upload-hint">
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

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="student-sidebar-brand">
            <img src="../image/pbi_logo" alt="PBI"/>
            <div>
                <strong>Student Workspace</strong>
            </div>
        </div>
        <div class="sb-profile-wrap">
            <div class="sb-avatar-wrap">
                <?php if ($student_photo): ?>
                <img class="sb-avatar" src="../image/<?= htmlspecialchars($student_photo) ?>" alt=""
                     onerror="this.onerror=null;this.src='../image/pbi_logo';this.classList.add('sb-avatar-fallback');"/>
                <?php else: ?>
                <img class="sb-avatar sb-avatar-fallback" src="../image/pbi_logo" alt="PBI" onerror="this.style.display='none'"/>
                <?php endif; ?>
            </div>
            <div class="sb-profile-name"><?= htmlspecialchars($student_name) ?></div>
            <div class="sb-profile-role">STUDENT<?= $student_year_level ? ' · ' . htmlspecialchars($student_year_level) : '' ?></div>
        </div>

        <div class="side-section-label">Main</div>
        <div class="side-nav-item<?= $land_on_settings ? '' : ' active' ?>" id="nav-dashboard" onclick="switchView('dashboard')">
            <i class="fa-solid fa-house"></i><span>Dashboard</span>
        </div>
        <div class="side-nav-item" id="nav-evaluate" onclick="switchView('evaluate')">
            <i class="fa-solid fa-clipboard-check"></i><span>Evaluate Personnel</span>
        </div>
        <div class="side-nav-item" id="nav-history" onclick="switchView('history')">
            <i class="fa-solid fa-clock-rotate-left"></i><span>Evaluation History</span>
        </div>

        <div class="side-section-label side-section-secondary">Support</div>
        <div class="side-nav-item" id="nav-guidelines" onclick="switchView('guidelines')">
            <i class="fa-solid fa-circle-info"></i><span>Guidelines</span>
        </div>
        <div class="side-nav-item<?= $land_on_settings ? ' active' : '' ?>" id="nav-settings" onclick="switchView('settings')">
            <i class="fa-solid fa-gear"></i><span>Settings</span>
        </div>

        <div class="side-logout-wrap">
            <a href="../logout.php" class="side-nav-item side-nav-logout" id="sideLogoutLink" onclick="openLogoutConfirm(event)">
                <i class="fa-solid fa-right-from-bracket"></i><span>Log Out</span>
            </a>
        </div>
    </aside>

    <header class="student-topbar" id="studentTopbar" aria-label="Student page header">
        <div class="stb-left">
            <button class="hamburger-btn" onclick="toggleSidebar()" aria-label="Open navigation"><i class="fa-solid fa-bars"></i></button>
            <div class="stb-title">
                <i class="fa-solid <?= $land_on_settings ? 'fa-gear' : 'fa-house' ?> stb-icon" id="wsTitleIcon" aria-hidden="true"></i>
                <span id="wsTitleText"><?= $land_on_settings ? 'Settings' : 'Dashboard' ?></span>
            </div>
        </div>
        <div class="stb-right">
            <div class="workspace-pill">
                <i class="fa-regular fa-calendar"></i>
                <?= htmlspecialchars($active_period_semester ? $academic_year_label . ' — ' . $active_period_semester : 'No active period') ?>
            </div>
            <div class="notif-bell-wrap">
                <button type="button" class="notif-bell" id="notifBellBtn" aria-haspopup="true" aria-expanded="false" aria-label="Notifications">
                    <i class="fa-solid fa-bell"></i>
                    <span class="notif-badge" id="notifBadge"></span>
                </button>
                <div class="notif-dropdown" id="notifDropdown" role="menu" aria-hidden="true">
                    <div class="notif-dd-head"><span>Notifications</span><span class="notif-dd-count" id="notifCount"></span></div>
                    <ul class="notif-dd-list" id="notifList"></ul>
                </div>
            </div>
        </div>
    </header>

    <div class="main">

        <?php if (!$student_year_level): ?>
        <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> Your account has no year level set. Please contact the admin so faculty/staff can be assigned to you correctly.</div>
        <?php endif; ?>

        <!-- ══════════════ DASHBOARD VIEW ══════════════ -->
        <div class="view-content<?= $land_on_settings ? '' : ' active' ?>" id="view-dashboard">
          <div class="sd-shell">
            <div class="sd-kick-top"><i class="fa-solid fa-chart-line"></i> Dashboard Overview</div>
            <?php
            // Countdown to opening: only while closed, following the schedule, and the scheduled start is still ahead.
            $sdOpensAtIso = '';
            if (!$period_is_open && $period_control_mode === 'schedule') {
                try {
                    $sdOpensDt = student_dashboard_parse_period_dt($periodSettings['eval_start'] ?? '', $period_tz);
                    $sdNowDt   = new DateTimeImmutable('now', $period_tz);
                    if ($sdOpensDt && $sdOpensDt > $sdNowDt) $sdOpensAtIso = $sdOpensDt->format('c');
                } catch (Throwable $e) { $sdOpensAtIso = ''; }
            }
            ?>
            <style>
            .sd-open-cd{display:inline-flex;align-items:baseline;gap:5px;font-size:inherit;font-weight:700;color:inherit;}
            .sd-open-cd-sep{opacity:.45;margin-right:3px;font-weight:400;}
            .sd-open-cd .sd-cd-unit[hidden]{display:none;}
            .sd-open-cd .sd-cd-unit b{font-weight:800;font-variant-numeric:tabular-nums;color:inherit;}
            </style>
            <div class="sd-open-pill <?= $period_is_open ? 'open' : 'closed' ?>">
                <i class="fa-solid <?= $period_is_open ? 'fa-lock-open' : 'fa-lock' ?>"></i>
                Evaluation period is currently <?= $period_is_open ? 'open' : 'closed' ?>
                <?php if ($sdOpensAtIso !== ''): ?>
                <span class="sd-open-cd" id="sdOpenCountdown" data-open="<?= htmlspecialchars($sdOpensAtIso) ?>" aria-live="off">
                    <span class="sd-open-cd-sep" aria-hidden="true">|</span>
                    <span class="sd-open-cd-label">Opens in</span>
                    <span class="sd-cd-unit" id="sd-oc-days-wrap" hidden><b id="sd-oc-days">0</b> D</span>
                    <span class="sd-cd-unit" id="sd-oc-hours-wrap" hidden><b id="sd-oc-hours">0</b> H</span>
                    <span class="sd-cd-unit"><b id="sd-oc-mins">0</b> M</span>
                    <span class="sd-cd-unit"><b id="sd-oc-secs">0</b> s</span>
                </span>
                <?php endif; ?>
            </div>
            <?php if ($sdOpensAtIso !== ''): ?>
            <script>
            (function(){
                var row = document.getElementById('sdOpenCountdown');
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
                    el('sd-oc-days').textContent = days;
                    el('sd-oc-hours').textContent = hours;
                    el('sd-oc-mins').textContent = mins;
                    el('sd-oc-secs').textContent = secs;
                    el('sd-oc-days-wrap').hidden = days === 0;
                    el('sd-oc-hours-wrap').hidden = days === 0 && hours === 0;
                    if (openAt - Date.now() <= 0) {
                        clearInterval(timer);
                        // Reload once so the page switches to "open" (guard prevents a reload loop).
                        var key = 'sdOpenReload:' + row.dataset.open;
                        try { if (sessionStorage.getItem(key)) return; sessionStorage.setItem(key, '1'); } catch (e) {}
                        setTimeout(function(){ location.reload(); }, 1500);
                    }
                }
                tick();
                timer = setInterval(tick, 1000);
            })();
            </script>
            <?php endif; ?>

            <div class="sd-period">
                <div><div class="sd-lbl">Academic Year</div><div class="sd-val"><?= htmlspecialchars($academic_year_label) ?></div></div>
                <div><div class="sd-lbl">Evaluation Opens</div><div class="sd-val"><?= htmlspecialchars($period_opens_short) ?></div></div>
                <div><div class="sd-lbl">Evaluation Closes</div><div class="sd-val"><?= htmlspecialchars($period_closes_short) ?></div></div>
                <div><div class="sd-lbl">Status</div><span class="sd-status <?= $period_is_open ? 'open' : ($period_is_scheduled ? 'scheduled' : 'closed') ?>"><?= $period_is_open ? 'Open' : ($period_is_scheduled ? 'Scheduled' : 'Closed') ?></span></div>
            </div>
            <?php if ($period_control_mode === 'open'): ?>
            <div class="sd-note"><strong>Evaluation is open.</strong> Force Open is overriding the configured schedule.</div>
            <?php elseif ($period_control_mode === 'closed'): ?>
            <div class="sd-note"><strong>Evaluation is closed.</strong> Force Closed is overriding the configured schedule.</div>
            <?php endif; ?>

            <div class="sd-welcome">
                <div class="sd-welcome-text">
                    <h2>Welcome, <?= htmlspecialchars($student_name) ?>!</h2>
                    <p><span class="sd-em"><?= htmlspecialchars($student_edu_label . ($student_year_level ? ' · ' . $student_year_level : '')) ?></span> &nbsp;·&nbsp; <?= htmlspecialchars($academic_year_label . ($active_period_semester ? ' — ' . $active_period_semester : '')) ?></p>
                </div>
                <div class="sd-score"><div class="sd-score-num"><?= (int)$pct ?>%</div><div class="sd-score-lbl">Evaluation Completion</div></div>
            </div>

            <div class="sd-stats">
                <div class="sd-stat s-amber"><span class="sd-ico"><i class="fa-solid fa-users"></i></span><div class="sd-lbl">To Evaluate</div><div class="sd-stat-val"><?= $total_evaluatees ?></div></div>
                <div class="sd-stat s-green"><span class="sd-ico"><i class="fa-solid fa-circle-check"></i></span><div class="sd-lbl">Completed</div><div class="sd-stat-val"><?= $total_done ?></div></div>
                <div class="sd-stat s-yellow"><span class="sd-ico"><i class="fa-solid fa-hourglass-half"></i></span><div class="sd-lbl">Pending</div><div class="sd-stat-val"><?= $total_pending ?></div></div>
                <div class="sd-stat s-blue"><span class="sd-ico"><i class="fa-solid fa-percent"></i></span><div class="sd-lbl">Progress</div><div class="sd-stat-val"><?= $pct ?>%</div></div>
            </div>

            <div class="sd-sc-head">
                <div>
                    <div class="sd-kicker">Workspace Shortcuts</div>
                    <h3>Continue where you need to be</h3>
                </div>
                <span class="sd-sc-hint">Your most-used student tools</span>
            </div>
            <div class="sd-shortcuts">
                <button type="button" class="sd-sc" onclick="switchView('evaluate')">
                    <span class="sd-sc-ico c-green"><i class="fa-solid fa-clipboard-check"></i></span>
                    <span class="sd-sc-body"><strong>Evaluate Personnel</strong><small><?= $total_pending > 0 ? "You have $total_pending evaluation" . ($total_pending !== 1 ? 's' : '') . ' left to complete.' : 'All evaluations complete. Thank you!' ?></small></span>
                    <i class="fa-solid fa-arrow-right sd-arrow"></i>
                </button>
                <button type="button" class="sd-sc" onclick="switchView('history')">
                    <span class="sd-sc-ico c-blue"><i class="fa-solid fa-clock-rotate-left"></i></span>
                    <span class="sd-sc-body"><strong>Evaluation History</strong><small>Review the evaluations you've submitted.</small></span>
                    <i class="fa-solid fa-arrow-right sd-arrow"></i>
                </button>
                <button type="button" class="sd-sc" onclick="switchView('guidelines')">
                    <span class="sd-sc-ico c-amber"><i class="fa-solid fa-circle-info"></i></span>
                    <span class="sd-sc-body"><strong>Guidelines</strong><small>Read the rating scale and evaluation rules.</small></span>
                    <i class="fa-solid fa-arrow-right sd-arrow"></i>
                </button>
            </div>
          </div>
        </div>

        <!-- ══════════════ EVALUATE VIEW ══════════════ -->
        <div class="view-content" id="view-evaluate">
          <div class="sd-shell">
          

 
            <?php if ($submit_success): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($submit_success) ?></div>
            <?php endif; ?>
            <?php if ($submit_error): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($submit_error) ?></div>
            <?php endif; ?>

            <?php
            // Build the top-level display list: Faculty, Staff, Principal, and Dean.
            // Principal and Dean each get their own tab now, though both still
            // submit under evaluation_context='school_head' -- the backend tells
            // them apart by the target's actual role, not by which tab it came from.
            $top_level = [];
            if (!empty($grouped['Faculty'])) {
                $top_level['Faculty']=array_map(fn($p)=>array_merge($p,['_evaluation_context'=>'teacher']),$grouped['Faculty']);
            }
            if (!empty($grouped['Staff'])) {
                $top_level['Staff']=array_map(fn($p)=>array_merge($p,['_evaluation_context'=>'staff']),$grouped['Staff']);
            }
            if (!empty($grouped['Principal'])) {
                $top_level['Principal']=array_map(fn($p)=>array_merge($p,['_evaluation_context'=>'school_head']),$grouped['Principal']);
            }
            if (!empty($grouped['Dean'])) {
                $top_level['Dean']=array_map(fn($p)=>array_merge($p,['_evaluation_context'=>'school_head']),$grouped['Dean']);
            }
            ?>

            <?php if (empty($top_level)): ?>
            <div class="empty"><i class="fa-solid fa-users-slash"></i><p>No evaluations are available right now.</p></div>
            <?php else: ?>

            <?php
            // Per-group accent colours (same palette as the EA "Evaluate Others" page).
            $ea_theme = [   // single portal accent (amber) for every group
                'Principal' => ['#D99A2B', '#B45309', '#FDF0D5'],
                'Dean'      => ['#D99A2B', '#B45309', '#FDF0D5'],
                'Staff'     => ['#D99A2B', '#B45309', '#FDF0D5'],
                'Faculty'   => ['#D99A2B', '#B45309', '#FDF0D5'],
            ];
            $ea_period_label = trim((string)$academic_year_label . ($active_period_semester ? ' — ' . $active_period_semester : ''), ' —');
            ?>
            <div class="eval-main-card">
            <div class="eval-panel-head">
                <div class="eval-card-title"><i class="fa-solid fa-clipboard-check"></i> Your Evaluation</div>
                <?php if ($ea_period_label !== ''): ?><span class="period-chip"><i class="fa-regular fa-calendar"></i> <?= htmlspecialchars($ea_period_label) ?></span><?php endif; ?>
            </div>
            <p class="eval-panel-lead">Choose a group, then evaluate each member. Your progress for the active period is shown on every card.</p>
            <div class="category-grid">
                <?php foreach ($top_level as $group_name => $persons):
                    $slug     = strtolower(str_replace([' ','-'], '_', $group_name));
                    $total    = count($persons);
                    $done_ct=count(array_filter($persons,fn($p)=>isset($done_ids[$p['id'].'|'.($p['_evaluation_context']??'teacher')])));
                    $all_done = ($total > 0 && $done_ct === $total);
                    $icon     = $group_icons[$group_name] ?? 'fa-user';
                    $th       = $ea_theme[$group_name] ?? ['#D97706', '#b45309', '#ffefcf'];
                    $grp_pct  = $total > 0 ? (int)round($done_ct / $total * 100) : 0;
                ?>
                <div class="cat-btn <?= $all_done ? 'all-done' : '' ?>"
                     id="catbtn_<?= $slug ?>" onclick="togglePanel('<?= $slug ?>')"
                     role="button" tabindex="0"
                     onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();togglePanel('<?= $slug ?>');}"
                     style="--g:<?= $th[0] ?>;--g-ink:<?= $th[1] ?>;--g-bg:<?= $th[2] ?>;">
                    <div class="cat-icon"><i class="fa-solid <?= $icon ?>"></i></div>
                    <div class="cat-name"><?= htmlspecialchars($group_name) ?></div>
                    <div class="cat-meta"><strong><?= $total ?></strong> <?= $total === 1 ? 'member' : 'members' ?></div>
                    <div class="group-progress<?= $grp_pct === 100 ? ' complete' : '' ?>" role="img" aria-label="<?= $done_ct ?> of <?= $total ?> evaluated">
                        <span class="group-progress-bar"><i style="width:<?= $grp_pct ?>%"></i></span>
                        <span class="group-progress-text"><strong><?= $done_ct ?></strong> of <?= $total ?> evaluated</span>
                    </div>
                    <span class="group-cta">
                        <span class="cta-off">Select group <i class="fa-solid fa-arrow-right"></i></span>
                        <span class="cta-on">Selected <i class="fa-solid fa-check"></i></span>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>

            <?php
            // Reusable person-card renderer for Faculty, Staff, Principal, and Dean.
            function render_person_card($p, $done_ids, $period_is_open) {
                $context=$p['_evaluation_context']??'teacher';
                $is_done=isset($done_ids[$p['id'].'|'.$context]);
                // Defaults to true for any group that doesn't stamp this flag,
                // so nothing new gets hidden unless we've actually confirmed
                // there are zero questions configured for this person.
                $has_questions = $p['_has_questions'] ?? true;
                $eval_args = json_encode([
                    'id'    => $p['id'],
                    'name'  => $p['full_name'],
                    'desig' => $p['designation'] ?: ($p['role'] === 'teacher' ? 'Teacher' : 'Personnel'),
                    'photo'=>$p['photo']?'../image/'.$p['photo']:'',
                    'context'=>$p['_evaluation_context']??'teacher',
                ]);
                ob_start();
                ?>
                <div class="ev-row <?= $is_done ? 'done is-done' : 'is-pending' ?>">
                    <?php if ($p['photo']): ?>
                    <img class="ev-avatar" src="../image/<?= htmlspecialchars($p['photo']) ?>"
                         alt="<?= htmlspecialchars($p['full_name']) ?>"/>
                    <?php else: ?>
                    <div class="ev-avatar ev-avatar-ph"><i class="fa-solid fa-user"></i></div>
                    <?php endif; ?>
                    <div class="ev-info">
                        <div class="ev-name"><?= htmlspecialchars($p['full_name']) ?></div>
                        <div class="ev-desig" title="<?= htmlspecialchars($p['designation'] ?: '') ?>"><?= htmlspecialchars($p['designation'] ?: '—') ?></div>
                        <?php if ($is_done): ?>
                        <span class="ev-badge ev-badge-done"><i class="fa-solid fa-check"></i> Completed</span>
                        <?php else: ?>
                        <span class="ev-badge ev-badge-pending"><i class="fa-solid fa-hourglass-half"></i> Pending</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($is_done): ?>
                    <button type="button" class="ev-btn ev-btn-view" onclick="openViewFromData(this)"
                        data-eval='<?= htmlspecialchars($eval_args, ENT_QUOTES) ?>'>
                        <i class="fa-regular fa-eye"></i> View
                    </button>
                    <?php elseif ($has_questions && !$period_is_open): ?>
                    <?php global $period_is_scheduled; ?>
                    <button type="button" class="ev-btn ev-btn-eval" disabled aria-disabled="true"
                        title="<?= $period_is_scheduled ? 'Evaluation opens at the scheduled time' : 'No evaluation period is currently open' ?>">
                        <i class="fa-solid <?= $period_is_scheduled ? 'fa-clock' : 'fa-lock' ?>"></i> <?= $period_is_scheduled ? 'Scheduled' : 'Closed' ?>
                    </button>
                    <?php elseif ($has_questions): ?>
                    <button type="button" class="ev-btn ev-btn-eval" onclick="openEvalFromData(this)"
                        data-eval='<?= htmlspecialchars($eval_args, ENT_QUOTES) ?>'>
                        <i class="fa-solid fa-pen"></i> Evaluate
                    </button>
                    <?php else: ?>
                    <button type="button" class="ev-btn ev-btn-eval" disabled title="The admin hasn't set up evaluation questions for this person yet">
                        <i class="fa-solid fa-hourglass-half"></i> Not available
                    </button>
                    <?php endif; ?>
                </div>
                <?php
                return ob_get_clean();
            }
            ?>

            <?php foreach ($top_level as $group_name => $persons):
                $slug  = strtolower(str_replace([' ','-'], '_', $group_name));
                $icon  = $group_icons[$group_name] ?? 'fa-user';
                $color = $group_colors[$group_name] ?? '#D97706';
            ?>
            <?php
                $panel_total = count($persons);
                $panel_done  = count(array_filter($persons, fn($p)=>isset($done_ids[$p['id'].'|'.($p['_evaluation_context']??'teacher')])));
                $panel_pend  = $panel_total - $panel_done;
            ?>
            <div class="members-panel ev-panel" id="panel_<?= $slug ?>">
                <div class="ev-head">
                    <div class="ev-head-left">
                        <span class="ev-head-icon"><i class="fa-solid <?= $icon ?>"></i></span>
                        <span class="ev-head-title"><?= htmlspecialchars($group_name) ?></span>
                    </div>
                    <div class="ev-stats">
                        <span><strong><?= $panel_total ?></strong> <?= $panel_total === 1 ? 'member' : 'members' ?></span>
                        <span class="s-done"><strong><?= $panel_done ?></strong> completed</span>
                        <span class="s-pend"><strong><?= $panel_pend ?></strong> pending</span>
                        <?php if ($ea_period_label !== ''): ?><span class="s-period"><i class="fa-regular fa-calendar"></i> <?= htmlspecialchars($ea_period_label) ?></span><?php endif; ?>
                    </div>
                </div>

                <div class="ev-grid">
                    <?php foreach ($persons as $p) echo render_person_card($p, $done_ids, $period_is_open); ?>
                </div>
            </div>
            <?php endforeach; ?>
            </div><!-- /.eval-main-card -->

            <?php endif; ?>
          </div>
        </div>

        <!-- ══════════════ HISTORY VIEW ══════════════ -->
        <div class="view-content" id="view-history">
          <div class="sd-shell">
            <div class="sd-kick-top"><i class="fa-solid fa-clock-rotate-left"></i> Evaluation History</div>
            <div class="sd-page-sub">Faculty, staff, and school heads you've already evaluated.</div>


            <?php if (empty($history)): ?>
            <div class="empty"><i class="fa-solid fa-clock-rotate-left"></i><p>You haven't submitted any evaluations yet.</p></div>
            <?php else: ?>
            <div class="history-list">
                <?php foreach ($history as $h):
                    $avg = $h['avg_score'] !== null ? round($h['avg_score'], 1) : null;
                ?>
                <div class="history-card">
                    <?php if ($h['photo']): ?>
                    <img class="history-avatar" src="../image/<?= htmlspecialchars($h['photo']) ?>" alt=""/>
                    <?php else: ?>
                    <div class="history-avatar-ph"><i class="fa-solid fa-user"></i></div>
                    <?php endif; ?>
                    <div class="history-info">
                        <div class="history-name"><?= htmlspecialchars($h['full_name']) ?></div>
                        <div class="history-desig"><?= htmlspecialchars($h['designation'] ?: '—') ?></div>
                        <?php if (!empty($h['remarks'])): ?>
                        <div class="history-comment">"<?= htmlspecialchars(mb_strimwidth($h['remarks'], 0, 120, '…')) ?>"</div>
                        <?php endif; ?>
                    </div>
                    <div class="history-meta">
                        <?php if ($avg !== null): ?>
                        <div class="history-score"><i class="fa-solid fa-star"></i> <?= $avg ?> / 5</div>
                        <?php endif; ?>
                        <div class="history-date"><?= htmlspecialchars(date('M j, Y', strtotime($h['submitted_at']))) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- ══════════════ GUIDELINES VIEW ══════════════ -->
        <div class="view-content" id="view-guidelines">
          <div class="sd-shell">
            <div class="sd-kick-top"><i class="fa-solid fa-circle-info"></i> Guidelines</div>
            <div class="sd-page-sub">How to evaluate faculty and staff fairly and accurately.</div>


            <div class="gl-card">
                <h3><i class="fa-solid fa-scale-balanced"></i> Rating Scale</h3>
                <div class="gl-scale-row"><div class="gl-scale-num">5</div><p><strong>Always</strong> — consistently demonstrates this</p></div>
                <div class="gl-scale-row"><div class="gl-scale-num">4</div><p><strong>Often</strong> — usually demonstrates this</p></div>
                <div class="gl-scale-row"><div class="gl-scale-num">3</div><p><strong>Sometimes</strong> — demonstrates this about half the time</p></div>
                <div class="gl-scale-row"><div class="gl-scale-num">2</div><p><strong>Rarely</strong> — seldom demonstrates this</p></div>
                <div class="gl-scale-row"><div class="gl-scale-num">1</div><p><strong>Never</strong> — does not demonstrate this</p></div>
            </div>

            <div class="gl-card">
                <h3><i class="fa-solid fa-circle-check"></i> Rules</h3>
                <ul>
                    <li>You can only evaluate faculty and staff assigned to your education level.</li>
                    <li>Each person can only be evaluated once per evaluation period.</li>
                    <li>Submissions cannot be edited once submitted, so review your ratings before sending.</li>
                    <li>Evaluations can only be submitted while an evaluation period is open.</li>
                </ul>
            </div>

            <div class="gl-card">
                <h3><i class="fa-solid fa-shield-halved"></i> Confidentiality</h3>
                <p>Your individual ratings and comments are used to help faculty and staff improve. Please answer honestly and constructively.</p>
            </div>
          </div>
        </div>

        <!-- ══════════════ SETTINGS VIEW ══════════════ -->
        <div class="view-content<?= $land_on_settings ? ' active' : '' ?>" id="view-settings">
          <div class="sd-shell">
            <div class="sd-kick-top"><i class="fa-solid fa-gear"></i> Settings</div>
            <div class="sd-page-sub">Manage your profile and account.</div>


            <div class="settings-tabs" role="tablist" aria-label="Account settings sections">
                <button type="button" class="settings-tab" role="tab" id="tabBtnProfile" data-tab="profile" aria-controls="tabPanelProfile" aria-selected="true"><i class="fa-solid fa-user"></i> Profile &amp; Security</button>
                <button type="button" class="settings-tab" role="tab" id="tabBtnAppearance" data-tab="appearance" aria-controls="tabPanelAppearance" aria-selected="false" tabindex="-1"><i class="fa-solid fa-palette"></i> Appearance</button>
            </div>

            <div class="settings-tabpanel" role="tabpanel" id="tabPanelProfile" aria-labelledby="tabBtnProfile" data-panel="profile">
            <div class="ac-card ac-c5 sq-top-card <?= $sq_status === 'ok' ? 'is-set' : 'is-unset' ?>" id="security-questions-settings">
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
                            <button type="button" class="ac-btn" id="sqOpen"><i class="fa-solid fa-pen-to-square"></i> <?= $sq_status === 'none' ? 'Set Security Questions' : 'Update Security Questions' ?></button>
                        </div>
                    </div>
                <?php if ($sq_flash !== ''): ?>
                <div class="alert alert-success" style="margin-top:14px;"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($sq_flash) ?></div>
                <?php endif; ?>
            </div>

            <div class="ac-card ac-c2">
                <div class="ac-head">
                    <span class="ac-icon"><i class="fa-solid fa-id-badge"></i></span>
                    <div>
                        <h2>Profile Photo</h2>
                        <p class="ac-sub">Update the photo shown in your sidebar and profile.</p>
                    </div>
                </div>
                <div class="profile-card">
                    <?php if ($student_photo): ?>
                    <img class="profile-photo-lg" id="settingsPhotoPreview" src="../image/<?= htmlspecialchars($student_photo) ?>" alt="Student profile photo"
                         onerror="this.onerror=null;this.src='../image/pbi_logo';"/>
                    <?php else: ?>
                    <img class="profile-photo-lg" id="settingsPhotoPreview" src="../image/pbi_logo" alt="Student profile photo"/>
                    <?php endif; ?>
                    <div class="photo-form-wrap">
                        <form method="POST" action="update_photo.php" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:10px;">
                            <input type="file" name="photo" id="settingsPhotoInput" accept="image/jpeg,image/png,image/webp,image/gif" required/>
                            <button class="ac-btn" type="submit" style="width:fit-content;"><i class="fa-solid fa-upload"></i> Upload New Photo</button>
                        </form>
                        <div class="photo-help">JPG, PNG, or WEBP only. Maximum file size: 10MB.</div>
                    </div>
                </div>
            </div>

            <div class="ac-card ac-c3">
                <div class="ac-head">
                    <span class="ac-icon"><i class="fa-solid fa-user-pen"></i></span>
                    <div>
                        <h2>Profile Details</h2>
                        <p class="ac-sub">Update your name, username, and year level.</p>
                    </div>
                </div>
                <?php if ($profile_success): ?>
                <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($profile_success) ?></div>
                <?php endif; ?>
                <?php if ($profile_error): ?>
                <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($profile_error) ?></div>
                <?php endif; ?>
                <form method="POST" action="student_dashboard.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="update_profile" value="1"/>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="set_fullname">Full Name</label>
                            <input type="text" id="set_fullname" name="full_name" maxlength="100" required value="<?= htmlspecialchars($student_name) ?>"/>
                        </div>
                        <div class="form-group">
                            <label for="set_username">Username</label>
                            <input type="text" id="set_username" name="username" maxlength="50" required autocomplete="username" value="<?= htmlspecialchars($student_username) ?>"/>
                        </div>
                        <div class="form-group">
                            <label for="set_yearlevel">Year Level</label>
                            <?php if (!empty($year_level_options)): ?>
                            <?php if (!$year_level_editable): ?>
                            <input type="hidden" name="year_level" value="<?= htmlspecialchars($student_year_level ?? '') ?>"/>
                            <?php endif; ?>
                            <select id="set_yearlevel" name="year_level" required<?= !$year_level_editable ? ' disabled' : '' ?>>
                                <?php
                                $current_yl_matched = false;
                                foreach ($year_level_options as $opt):
                                    $isSel = (strcasecmp($opt, (string)$student_year_level) === 0);
                                    if ($isSel) $current_yl_matched = true;
                                ?>
                                <option value="<?= htmlspecialchars($opt) ?>"<?= $isSel ? ' selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                                <?php if (!$current_yl_matched && $student_year_level): ?>
                                <option value="<?= htmlspecialchars($student_year_level) ?>" selected><?= htmlspecialchars($student_year_level) ?></option>
                                <?php endif; ?>
                            </select>
                            <?php else: ?>
                            <input type="text" id="set_yearlevel" name="year_level" maxlength="30"<?= !$year_level_editable ? ' readonly' : '' ?> value="<?= htmlspecialchars($student_year_level ?? '') ?>"/>
                            <?php endif; ?>
                            <?php if (!$year_level_editable): ?>
                            <div class="photo-help"><i class="fa-solid fa-lock" style="color:var(--ap-accent);"></i> <?= htmlspecialchars($year_level_locked_reason) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="photo-help" style="margin:-6px 0 16px;">Changing your Username changes what you sign in with. Changing your Year Level changes which faculty &amp; staff you're eligible to evaluate.</div>
                    <button class="ac-btn" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
                </form>
            </div>

            <div class="ac-card ac-c4">
                <div class="ac-head">
                    <span class="ac-icon"><i class="fa-solid fa-lock"></i></span>
                    <div>
                        <h2>Change Password</h2>
                        <p class="ac-sub">Your new password must be at least 8 characters and different from your current password.</p>
                    </div>
                </div>
                <?php if ($password_success): ?>
                <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($password_success) ?></div>
                <?php endif; ?>
                <?php if ($password_error): ?>
                <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($password_error) ?></div>
                <?php endif; ?>
                <form method="POST" action="student_dashboard.php" id="changePwForm" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="change_password" value="1"/>
                    <div class="form-grid">
                        <div class="form-group"><label for="cur_pw">Current Password</label><div class="pw-wrap"><input type="password" id="cur_pw" name="current_password" required autocomplete="current-password"/><button type="button" class="pw-toggle" aria-label="Show password" aria-pressed="false" tabindex="0"><svg class="pw-eye" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg><svg class="pw-eye-off" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19C5 19 1 12 1 12a18.5 18.5 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/><line x1="1" y1="1" x2="23" y2="23"/></svg></button></div></div>
                        <div class="form-group"></div>
                        <div class="form-group"><label for="new_pw">New Password</label><div class="pw-wrap"><input type="password" id="new_pw" name="new_password" required minlength="8" maxlength="72" autocomplete="new-password"/><button type="button" class="pw-toggle" aria-label="Show password" aria-pressed="false" tabindex="0"><svg class="pw-eye" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg><svg class="pw-eye-off" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19C5 19 1 12 1 12a18.5 18.5 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/><line x1="1" y1="1" x2="23" y2="23"/></svg></button></div></div>
                        <div class="form-group"><label for="confirm_pw">Confirm New Password</label><div class="pw-wrap"><input type="password" id="confirm_pw" name="confirm_password" required minlength="8" maxlength="72" autocomplete="new-password"/><button type="button" class="pw-toggle" aria-label="Show password" aria-pressed="false" tabindex="0"><svg class="pw-eye" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg><svg class="pw-eye-off" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19C5 19 1 12 1 12a18.5 18.5 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/><line x1="1" y1="1" x2="23" y2="23"/></svg></button></div></div>
                    </div>
                    <div class="alert alert-error" id="pwMismatch" style="display:none;"><i class="fa-solid fa-circle-exclamation"></i> New passwords do not match.</div>
                    <button class="ac-btn" type="submit" id="updatePwBtn"><i class="fa-solid fa-key"></i> Update Password</button>
                </form>
            </div>

            
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
#view-settings .sq-top-card.is-unset{border:2px solid #E2A33B !important;box-shadow:0 0 0 4px rgba(226,163,59,.16) !important}
#view-settings .sq-top-card.is-unset::before{background:#E2A33B !important}
#view-settings .sq-top-card.is-unset .ac-icon{background:rgba(226,163,59,.18) !important;color:#D97706 !important}
#view-settings .sq-top-card.is-unset .ac-icon i{color:#D97706 !important}

.sqm-overlay{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(11,31,58,.55);overscroll-behavior:contain}
.sqm-overlay[hidden]{display:none}
.sqm-modal{--sq-acc:#D97706;--sq-acc-h:#F59E0B;--sq-line:rgba(128,140,160,.30);width:min(620px,100%);max-height:calc(100vh - 40px);overflow:auto;background:var(--mid,#fff);border:1px solid var(--sq-line);border-radius:16px;padding:20px 22px;box-shadow:0 24px 60px rgba(11,31,58,.35);color:var(--light,#0B1F3A)}
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
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
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
    fetch('student_dashboard.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
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
    fetch('student_dashboard.php',{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){ return r.json(); })
      .then(function(d){
        if(d&&d.ok){ location.href='student_dashboard.php'; return; }
        showErr((d&&d.message)||'The security questions could not be saved. Please try again.'); md.scrollTop=0;
      })
      .catch(function(){ showErr('Could not reach the server. Please try again.'); })
      .then(function(){ busy=false; save.textContent='Save questions'; sync(); });
  });
})();
</script>

            <div class="settings-tabpanel" role="tabpanel" id="tabPanelAppearance" aria-labelledby="tabBtnAppearance" data-panel="appearance" hidden>
            <div class="ac-card ac-c1" id="student-appearance-settings">
                <div class="ac-head appearance-heading-row">
                    <span class="ac-icon"><i class="fa-solid fa-palette"></i></span>
                    <div>
                        <h2>Appearance</h2>
                        <p class="ac-sub">Choose your theme, content spacing, and motion preferences.</p>
                    </div>
                    <span class="appearance-device-badge"><i class="fa-solid fa-laptop"></i> Saved on this device</span>
                </div>

                <div class="appearance-feature-grid">
                    <section class="appearance-control-card appearance-theme-card" aria-labelledby="appearanceThemeHeading">
                        <div class="appearance-control-head">
                            <span class="appearance-control-icon"><i class="fa-solid fa-circle-half-stroke"></i></span>
                            <div>
                                <h3 id="appearanceThemeHeading">Theme mode</h3>
                                <p>Choose a look that suits your environment.</p>
                            </div>
                        </div>
                        <div class="appearance-theme-options" role="group" aria-label="Theme mode">
                            <button type="button" class="appearance-choice" id="appearanceLightBtn" data-theme-choice="light" aria-pressed="false" onclick="setAppearance('light')">
                                <span class="appearance-choice-icon"><i class="fa-solid fa-sun"></i></span>
                                <span class="appearance-choice-copy"><strong>Light</strong><small>Bright and clean</small></span>
                                <span class="appearance-choice-check"><i class="fa-solid fa-check"></i></span>
                            </button>
                            <button type="button" class="appearance-choice" id="appearanceDarkBtn" data-theme-choice="dark" aria-pressed="false" onclick="setAppearance('dark')">
                                <span class="appearance-choice-icon"><i class="fa-solid fa-moon"></i></span>
                                <span class="appearance-choice-copy"><strong>Dark</strong><small>Low-light friendly</small></span>
                                <span class="appearance-choice-check"><i class="fa-solid fa-check"></i></span>
                            </button>
                            <button type="button" class="appearance-choice" id="appearanceSystemBtn" data-theme-choice="system" aria-pressed="false" onclick="setAppearance('system')">
                                <span class="appearance-choice-icon"><i class="fa-solid fa-desktop"></i></span>
                                <span class="appearance-choice-copy"><strong>System</strong><small>Follow device setting</small></span>
                                <span class="appearance-choice-check"><i class="fa-solid fa-check"></i></span>
                            </button>
                        </div>
                        <div class="appearance-inline-status"><i class="fa-solid fa-circle-info"></i> Current mode: <strong id="appearanceCurrentMode">Light</strong></div>
                    </section>

                    <section class="appearance-control-card" aria-labelledby="appearanceLayoutHeading">
                        <div class="appearance-control-head">
                            <span class="appearance-control-icon"><i class="fa-solid fa-sliders"></i></span>
                            <div>
                                <h3 id="appearanceLayoutHeading">Interface spacing</h3>
                                <p>Choose how much space appears around cards and content.</p>
                            </div>
                        </div>
                        <div class="appearance-density-options" role="group" aria-label="Interface spacing">
                            <button type="button" class="appearance-density-choice" data-density-choice="comfortable" aria-pressed="false" onclick="setStudentDensity('comfortable')"><i class="fa-solid fa-expand"></i><span><strong>Comfortable</strong><small>More breathing room</small></span></button>
                            <button type="button" class="appearance-density-choice" data-density-choice="compact" aria-pressed="false" onclick="setStudentDensity('compact')"><i class="fa-solid fa-compress"></i><span><strong>Compact</strong><small>More content on screen</small></span></button>
                        </div>
                    </section>

                    <section class="appearance-control-card appearance-accessibility-card" aria-labelledby="appearanceAccessibilityHeading">
                        <div class="appearance-control-head">
                            <span class="appearance-control-icon"><i class="fa-solid fa-universal-access"></i></span>
                            <div>
                                <h3 id="appearanceAccessibilityHeading">Accessibility</h3>
                                <p>Reduce interface movement for a calmer experience.</p>
                            </div>
                        </div>
                        <button type="button" class="appearance-motion-toggle" id="appearanceMotionBtn" aria-pressed="false" onclick="toggleStudentReducedMotion()">
                            <span class="appearance-motion-copy"><strong>Reduce animations</strong><small>Minimize transitions and motion effects</small></span>
                            <span class="appearance-switch-track" aria-hidden="true"><span class="appearance-switch-thumb"></span></span>
                        </button>
                        <div class="appearance-accessibility-note"><i class="fa-solid fa-shield-heart"></i> Your settings stay in this browser and do not change other users' views.</div>
                    </section>
                </div>
            </div>
            </div>

            <script>
            (function(){
                var root=document.getElementById('view-settings');
                if(!root)return;
                var tabs=[].slice.call(root.querySelectorAll('.settings-tab'));
                var panels=[].slice.call(root.querySelectorAll('.settings-tabpanel'));
                function show(name,focus){
                    tabs.forEach(function(t){
                        var on=t.dataset.tab===name;
                        t.setAttribute('aria-selected',on?'true':'false');
                        t.tabIndex=on?0:-1;
                        if(on&&focus)t.focus();
                    });
                    panels.forEach(function(p){p.hidden=p.dataset.panel!==name;});
                }
                tabs.forEach(function(t,i){
                    t.addEventListener('click',function(){show(t.dataset.tab);});
                    t.addEventListener('keydown',function(e){
                        var n=null;
                        if(e.key==='ArrowRight')n=tabs[(i+1)%tabs.length];
                        if(e.key==='ArrowLeft')n=tabs[(i-1+tabs.length)%tabs.length];
                        if(n){e.preventDefault();show(n.dataset.tab,true);}
                    });
                });
                show('profile'); // Profile & Security first (profile/password messages land here after saving)
            })();
            </script>
          </div>
        </div>

    </div>
</div>

<!-- EVALUATION MODAL -->
<div class="modal-overlay" id="evalModal">
    <div class="modal">
        <div class="modal-header">
            <div id="modalAvatarWrap"></div>
            <div>
                <div class="modal-name" id="modalName"></div>
                <div class="modal-desig" id="modalDesig"></div>
            </div>
            <button class="modal-close" type="button" onclick="requestCloseEval()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="student_dashboard.php" id="evalForm" novalidate>
            <input type="hidden" name="submit_evaluation" value="1"/>
            <input type="hidden" name="target_user_id" id="targetUserId"/>
            <input type="hidden" name="evaluation_context" id="evaluationContext" value="teacher"/>
            <div class="modal-body" id="modalBody">
                <div class="loading-qs"><i class="fa-solid fa-spinner"></i> Loading questions...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" onclick="requestCloseEval()">Cancel</button>
                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-paper-plane"></i> Submit Evaluation
                </button>
            </div>
        </form>
    </div>
</div>

<!-- VIEW SUBMITTED EVALUATION MODAL (read-only) -->
<div class="modal-overlay" id="viewEvalModal">
    <div class="modal">
        <div class="modal-header">
            <div id="viewAvatarWrap"></div>
            <div>
                <div class="modal-name" id="viewName"></div>
                <div class="modal-desig" id="viewDesig"></div>
            </div>
            <button class="modal-close" type="button" onclick="closeViewEval()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="viewBody">
            <div class="loading-qs"><i class="fa-solid fa-spinner"></i> Loading your evaluation...</div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel-modal" onclick="closeViewEval()">Close</button>
        </div>
    </div>
</div>

<script>
let activePanel     = null;
let questionsLoaded = false;

// ── Top header (icon + title) follows the active tab ──
const WS_HEADERS = {
    dashboard:  {icon: 'fa-house',             title: 'Dashboard'},
    evaluate:   {icon: 'fa-clipboard-check',    title: 'Evaluate Personnel'},
    history:    {icon: 'fa-clock-rotate-left', title: 'Evaluation History'},
    guidelines: {icon: 'fa-circle-info',       title: 'Guidelines'},
    settings:   {icon: 'fa-gear',              title: 'Settings'}
};
function updateWorkspaceHeader(view) {
    const cfg = WS_HEADERS[view]; if (!cfg) return;
    const t = document.getElementById('wsTitleText'), i = document.getElementById('wsTitleIcon');
    if (t) t.textContent = cfg.title;
    if (i) i.className = 'fa-solid ' + cfg.icon + ' stb-icon';
}

// ── Sidebar view switching ──
function switchView(view) {
    // The "Dashboard Overview / Student Evaluation Workspace" heading belongs
    // to the Dashboard tab only; every other tab has its own page title.
    const topbar = document.getElementById('contentTopbar');
    if (topbar) topbar.classList.toggle('not-dashboard', view !== 'dashboard');
    updateWorkspaceHeader(view);
    document.querySelectorAll('.view-content').forEach(v => v.classList.remove('active'));
    document.getElementById('view-' + view)?.classList.add('active');
    document.querySelectorAll('.side-nav-item').forEach(i => i.classList.remove('active'));
    document.getElementById('nav-' + view)?.classList.add('active');
    closeSidebarMobile();
    document.querySelectorAll('.sd-shell').forEach(e => { e.scrollTop = 0; });
    window.scrollTo({top: 0, behavior: 'smooth'});
}

// ── Settings: change password form guard ──
const changePwForm = document.getElementById('changePwForm');
if (changePwForm) {
    changePwForm.addEventListener('submit', (ev) => {
        const np = document.getElementById('new_pw').value, cp = document.getElementById('confirm_pw').value;
        if (np !== cp) {
            ev.preventDefault();
            document.getElementById('pwMismatch').style.display = 'flex';
            return;
        }
        document.getElementById('pwMismatch').style.display = 'none';
        document.getElementById('updatePwBtn').disabled = true;
    });
}

// ── Mobile sidebar drawer ──
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('open');
}
function closeSidebarMobile() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('open');
}

// ── Photo modal ──
function openLogoutConfirm(e) {
    if (e) e.preventDefault();
    document.getElementById('logoutModal').classList.add('open');
    var c = document.getElementById('logoutCancelBtn'); if (c) c.focus();
}
function closeLogoutConfirm() {
    document.getElementById('logoutModal').classList.remove('open');
}
document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeLogoutConfirm();
});

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

// ── Category panel toggle (Evaluate view) ──
function togglePanel(slug) {
    if (activePanel && activePanel !== slug) _closePanel(activePanel);
    if (activePanel === slug) { _closePanel(slug); activePanel = null; }
    else { _openPanel(slug); activePanel = slug; }
}
function _openPanel(slug) {
    document.getElementById('panel_' + slug)?.classList.add('open');
    document.getElementById('catbtn_' + slug)?.classList.add('active');
    setTimeout(() => document.getElementById('panel_' + slug)?.scrollIntoView({behavior:'smooth',block:'nearest'}), 50);
}
function _closePanel(slug) {
    document.getElementById('panel_' + slug)?.classList.remove('open');
    document.getElementById('catbtn_' + slug)?.classList.remove('active');
}
function closePanel(slug) { _closePanel(slug); if (activePanel === slug) activePanel = null; }

// Escape text inserted into HTML generated by the evaluation modal.
// The table-style renderer calls this helper for category/question text.
function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// ── Eval modal ──
function openEvalFromData(btn) {
    const d = JSON.parse(btn.getAttribute('data-eval'));
    openEval(d.id,d.name,d.desig,d.photo,d.context||'teacher');
}
function openEval(id,name,desig,photo,context){
    questionsLoaded = false;
    const submitBtn = document.querySelector('#evalForm .btn-submit');
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Evaluation';
    }
    document.getElementById('targetUserId').value=id;
    const ctxInput=document.getElementById('evaluationContext'); if(ctxInput)ctxInput.value=context||'teacher';
    document.getElementById('modalName').textContent  = name;
    document.getElementById('modalDesig').textContent = desig || 'Faculty / Staff';
    const wrap = document.getElementById('modalAvatarWrap');
    if (photo) {
        const img = document.createElement('img');
        img.className = 'modal-avatar'; img.src = photo; img.alt = name;
        img.onerror = () => img.outerHTML = '<div class="modal-avatar-ph"><i class="fa-solid fa-user"></i></div>';
        wrap.innerHTML = ''; wrap.appendChild(img);
    } else {
        wrap.innerHTML = '<div class="modal-avatar-ph"><i class="fa-solid fa-user"></i></div>';
    }
    document.getElementById('modalBody').innerHTML =
        '<div class="loading-qs"><i class="fa-solid fa-spinner"></i> Loading questions...</div>';
    document.getElementById('evalModal').classList.add('open');
    document.body.style.overflow = 'hidden';
    loadQuestions(id,context||'teacher');
}
function openViewFromData(btn) {
    const d = JSON.parse(btn.getAttribute('data-eval'));
    openViewEval(d.id, d.name, d.desig, d.photo, d.context || 'teacher');
}
function openViewEval(id, name, desig, photo, context) {
    document.getElementById('viewName').textContent  = name;
    document.getElementById('viewDesig').textContent = desig || 'Faculty / Staff';
    const wrap = document.getElementById('viewAvatarWrap');
    if (photo) {
        const img = document.createElement('img');
        img.className = 'modal-avatar'; img.src = photo; img.alt = name;
        img.onerror = () => img.outerHTML = '<div class="modal-avatar-ph"><i class="fa-solid fa-user"></i></div>';
        wrap.innerHTML = ''; wrap.appendChild(img);
    } else {
        wrap.innerHTML = '<div class="modal-avatar-ph"><i class="fa-solid fa-user"></i></div>';
    }
    const body = document.getElementById('viewBody');
    body.innerHTML = '<div class="loading-qs"><i class="fa-solid fa-spinner"></i> Loading your evaluation...</div>';
    document.getElementById('viewEvalModal').classList.add('open');
    document.body.style.overflow = 'hidden';

    fetch(`student_dashboard.php?get_submission=1&target_id=${encodeURIComponent(id)}&context=${encodeURIComponent(context || 'teacher')}`)
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                body.innerHTML = `<div class="empty"><i class="fa-solid fa-triangle-exclamation"></i><p style="color:#dc2626;margin-top:10px;font-size:13px">${escapeHtml(d.error || 'Could not load your evaluation.')}</p></div>`;
                return;
            }
            let html = '';
            if (d.score !== null && d.score !== undefined) {
                html += `<div class="vw-summary"><span class="num">${Number(d.score).toFixed(2)}</span><span class="of">/ 5.00 overall</span></div>`;
            }
            if (d.submitted_at) html += `<div class="vw-sub">Submitted ${escapeHtml(d.submitted_at)}</div>`;

            const groups = []; const idx = {};
            (d.answers || []).forEach(a => {
                if (!(a.category in idx)) { idx[a.category] = groups.length; groups.push({cat: a.category, rows: []}); }
                groups[idx[a.category]].rows.push(a);
            });
            let n = 0;
            groups.forEach(g => {
                html += `<div class="eval-form-cat">${escapeHtml(g.cat)}</div>`;
                html += `<div class="eval-form-wrap"><table class="eval-form-table vw-table"><thead><tr><th>Question</th><th>Your rating</th></tr></thead><tbody>`;
                g.rows.forEach(a => {
                    n++;
                    html += `<tr><td><div class="eval-form-qtext"><span class="eval-form-qno">${n}.</span>${escapeHtml(a.question)}</div></td><td><span class="vw-chip">${escapeHtml(String(Math.round(a.score * 100) / 100))}</span></td></tr>`;
                });
                html += `</tbody></table></div>`;
            });

            html += `<div class="vw-comment"><div class="vw-comment-label">Your comment</div>` +
                    (d.comment && d.comment.trim() !== ''
                        ? `<div class="vw-comment-text">${escapeHtml(d.comment)}</div>`
                        : `<div class="vw-comment-text vw-none">No comment was left for this evaluation.</div>`) +
                    `</div>`;
            body.innerHTML = html;
        })
        .catch(() => {
            body.innerHTML = `<div class="empty"><i class="fa-solid fa-triangle-exclamation"></i><p style="color:#dc2626;margin-top:10px;font-size:13px">Failed to load your evaluation.</p></div>`;
        });
}
function closeViewEval() {
    document.getElementById('viewEvalModal').classList.remove('open');
    if (!document.getElementById('evalModal').classList.contains('open')) document.body.style.overflow = '';
}
document.getElementById('viewEvalModal').addEventListener('click', function(e) {
    if (e.target === this) closeViewEval();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('viewEvalModal').classList.contains('open')) closeViewEval();
});

function closeEval() {
    evalSubmitting = false;
    document.getElementById('evalModal').classList.remove('open');
    document.body.style.overflow = '';
    questionsLoaded = false;
    const submitBtn = document.querySelector('#evalForm .btn-submit');
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Evaluation';
    }
}

function loadQuestions(id,context){
    fetch(`student_dashboard.php?get_questions=1&target_id=${id}&context=${encodeURIComponent(context||'teacher')}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('modalBody').innerHTML =
                    `<div class="empty"><i class="fa-solid fa-triangle-exclamation"></i>
                     <p style="color:#dc2626;margin-top:10px;font-size:13px">${data.error}</p></div>`;
                return;
            }
            const questions = data.questions;
            const legend = `<div class="scale-legend">
                <div class="legend-item"><div class="legend-dot">5</div> Always</div>
                <div class="legend-item"><div class="legend-dot">4</div> Often</div>
                <div class="legend-item"><div class="legend-dot">3</div> Sometimes</div>
                <div class="legend-item"><div class="legend-dot">2</div> Rarely</div>
                <div class="legend-item"><div class="legend-dot">1</div> Never</div>
            </div>`;
            const labels  = {5:'Always',4:'Often',3:'Sometimes',2:'Rarely',1:'Never'};
            const grouped = {};
            questions.forEach(q => {
                const cat = q.category || 'General';
                if (!grouped[cat]) grouped[cat] = [];
                grouped[cat].push(q);
            });
            const totalQuestionCount = questions.length;
            let html = `<div class="eval-validation" id="evalValidation" role="alert" aria-live="assertive" hidden></div>`;
            html += `<div class="eval-progress" id="evalProgress"><span>Please rate every question before submitting.</span><span class="progress-status" id="evalProgressStatus">0 / ${totalQuestionCount} answered</span></div>`;
            html += legend;
            let qNum = 1;
            for (const [cat, qs] of Object.entries(grouped)) {
                html += `<div class="eval-form-cat"><i class="fa-solid fa-layer-group" style="margin-right:5px"></i>${escapeHtml(cat)}</div>`;
                html += `<div class="eval-form-wrap"><table class="eval-form-table"><thead><tr><th>Question</th><th>5</th><th>4</th><th>3</th><th>2</th><th>1</th></tr></thead><tbody>`;
                qs.forEach(q => {
                    const qkey = `${q.question_source}:${q.id}`;
                    const thisQNum = qNum++;
                    html += `<tr data-question-key="${escapeHtml(qkey)}" data-question-number="${thisQNum}"><td><div class="eval-form-qtext"><span class="eval-form-qno">${thisQNum}.</span>${escapeHtml(q.question_text)}</div></td>`;
                    [5,4,3,2,1].forEach(v => {
                        const optId = `r_${q.question_source}_${q.id}_${v}`;
                        html += `<td><div class="eval-form-rating"><input type="radio" name="rating[${qkey}]" id="${optId}" value="${v}" required aria-label="Question ${thisQNum}, rating ${v}"><label for="${optId}">${v}</label></div></td>`;
                    });
                    html += `</tr>`;
                });
                html += `</tbody></table></div>`;
            }
            html += `<div class="comment-box">
                <div class="comment-label">
                    <i class="fa-solid fa-comment-dots"></i>
                    Comments, Suggestions &amp; Areas for Improvement <span class="comment-req" title="Required">*</span>
                </div>
                <textarea name="comment" class="comment-textarea" required aria-required="true"
                    placeholder="Share your thoughts, suggestions, or concerns about this person's performance...."
                    rows="4"></textarea>
                <div class="comment-error" id="commentError" role="alert" hidden>Required \u2014 you can't submit this evaluation without a comment.</div>
            </div>`;
            document.getElementById('modalBody').innerHTML = html;
            questionsLoaded = true;
        })
        .catch(err => {
            document.getElementById('modalBody').innerHTML =
                `<div class="empty"><i class="fa-solid fa-triangle-exclamation"></i>
                 <p style="color:#dc2626;margin-top:10px;font-size:13px">
                    Failed to load questions.<br><small>${err.message}</small></p></div>`;
        });
}

document.getElementById('evalModal').addEventListener('click', function(e) {
    if (e.target === this) requestCloseEval();   // click outside the form → ask first
});

/* ── Unsaved-evaluation guard ─────────────────────────────────────
   Clicking outside the form, Cancel, the X, Escape, refreshing or closing
   the tab never discards a started evaluation silently: the styled
   "Leave without submitting?" dialog appears first. Nothing is asked
   when no rating or comment has been entered yet. */
let evalSubmitting = false;
function evalIsDirty() {
    const f = document.getElementById('evalForm');
    if (!f || evalSubmitting) return false;
    if (!document.getElementById('evalModal').classList.contains('open')) return false;
    if (f.querySelector('input[type="radio"][name^="rating["]:checked')) return true;
    const c = f.querySelector('textarea[name="comment"]');
    return !!(c && c.value.trim() !== '');
}
function askEvalLeave() {
    return new Promise(function(resolve) {
        const ov = document.createElement('div');
        ov.className = 'leave-dlg-overlay';
        ov.setAttribute('role', 'alertdialog');
        ov.setAttribute('aria-modal', 'true');
        ov.setAttribute('aria-labelledby', 'leaveDlgTitle');
        ov.innerHTML = '<div class="leave-dlg">'
            + '<h3 id="leaveDlgTitle">Leave without submitting?</h3>'
            + '<p>You have an unsubmitted evaluation. If you leave now, your answers will be lost.</p>'
            + '<div class="leave-dlg-actions">'
            + '<button type="button" class="leave-dlg-stay">Stay</button>'
            + '<button type="button" class="leave-dlg-leave">Leave</button>'
            + '</div></div>';
        function done(v) { document.removeEventListener('keydown', onKey, true); ov.remove(); resolve(v); }
        function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); done(false); } }
        ov.querySelector('.leave-dlg-stay').addEventListener('click', function() { done(false); });
        ov.querySelector('.leave-dlg-leave').addEventListener('click', function() { done(true); });
        // clicking the dark area around the dialog counts as "Stay", never as leaving
        ov.addEventListener('click', function(e) { if (e.target === ov) done(false); });
        document.addEventListener('keydown', onKey, true);
        document.body.appendChild(ov);
        ov.querySelector('.leave-dlg-stay').focus();
    });
}
function requestCloseEval() {
    if (document.querySelector('.leave-dlg-overlay')) return;
    if (!evalIsDirty()) { closeEval(); return; }
    askEvalLeave().then(function(ok) { if (ok) closeEval(); });
}
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    if (document.querySelector('.leave-dlg-overlay')) return;
    if (document.getElementById('evalModal').classList.contains('open')) requestCloseEval();
});
// refresh, closing the tab, browser back
window.addEventListener('beforeunload', function(e) {
    if (evalIsDirty()) { e.preventDefault(); e.returnValue = ''; }
});

const evalForm = document.getElementById('evalForm');

function getUnansweredEvaluationQuestions(form) {
    const radios = form.querySelectorAll('input[type="radio"][name^="rating["]');
    const names = [...new Set([...radios].map(r => r.name))];
    return names.filter(name => !form.querySelector(`input[name="${CSS.escape(name)}"]:checked`));
}

function updateEvaluationProgress(form) {
    const status = form.querySelector('#evalProgressStatus');
    const radios = [...form.querySelectorAll('input[type="radio"][name^="rating["]')];
    if (!status || !radios.length) return;
    const names = [...new Set(radios.map(r => r.name))];
    const answered = names.filter(name => form.querySelector(`input[name="${CSS.escape(name)}"]:checked`)).length;
    status.textContent = `${answered} / ${names.length} answered`;
    status.style.color = answered === names.length ? '#22c55e' : (document.body.classList.contains('light-theme') ? '#B45309' : '#F0B84D');
}

function showEvaluationValidation(form, unanswered) {
    const box = form.querySelector('#evalValidation');
    if (!box) return;
    const rows = unanswered.map(name => form.querySelector(`input[name="${CSS.escape(name)}"]`)?.closest('tr')).filter(Boolean);
    form.querySelectorAll('.unanswered-question').forEach(row => row.classList.remove('unanswered-question'));
    rows.forEach(row => row.classList.add('unanswered-question'));

    const numbers = rows.map(row => row.dataset.questionNumber).filter(Boolean);
    let detail = '';
    if (numbers.length <= 6 && numbers.length > 0) {
        detail = ` Missing: ${numbers.map(n => `Question ${n}`).join(', ')}.`;
    }
    box.innerHTML = `<i class="fa-solid fa-circle-exclamation"></i><div><strong>Please complete all required questions.</strong> You have ${unanswered.length} unanswered question${unanswered.length === 1 ? '' : 's'}.${detail}</div>`;
    box.hidden = false;

    const firstRow = rows[0];
    const firstRadio = firstRow?.querySelector('input[type="radio"]');
    if (firstRow) firstRow.scrollIntoView({behavior:'smooth', block:'center'});
    if (firstRadio) setTimeout(() => firstRadio.focus({preventScroll:true}), 120);
}

function clearEvaluationValidation(form) {
    const box = form.querySelector('#evalValidation');
    if (box) box.hidden = true;
    form.querySelectorAll('.unanswered-question').forEach(row => row.classList.remove('unanswered-question'));
    const cb = form.querySelector('.comment-box');
    if (cb) cb.classList.remove('invalid');
    const ce = form.querySelector('#commentError');
    if (ce) ce.hidden = true;
}

// A written comment is mandatory (whitespace-only doesn't count).
function evaluationCommentMissing(form) {
    const cm = form.querySelector('textarea[name="comment"]');
    return !!cm && cm.value.trim() === '';
}

function showCommentValidation(form) {
    const top = form.querySelector('#evalValidation');
    if (top) top.hidden = true;
    const cb = form.querySelector('.comment-box');
    const ce = form.querySelector('#commentError');
    const cm = form.querySelector('textarea[name="comment"]');
    if (cb) cb.classList.add('invalid');
    if (ce) ce.hidden = false;
    if (cb) cb.scrollIntoView({behavior:'smooth', block:'center'});
    if (cm) setTimeout(() => cm.focus({preventScroll:true}), 120);
}

if (evalForm) {
    evalForm.addEventListener('change', function(e) {
        const radio = e.target.closest('input[type="radio"][name^="rating["]');
        if (!radio) return;
        const row = radio.closest('tr');
        if (row) row.classList.remove('unanswered-question');
        updateEvaluationProgress(this);
        const unanswered = getUnansweredEvaluationQuestions(this);
        const box = this.querySelector('#evalValidation');
        if (box && !unanswered.length) clearEvaluationValidation(this);
    });

    evalForm.addEventListener('input', function(e) {
        if (e.target.matches('textarea[name="comment"]') && e.target.value.trim() !== '') {
            const cb = this.querySelector('.comment-box');
            if (cb) cb.classList.remove('invalid');
            const ce = this.querySelector('#commentError');
            if (ce) ce.hidden = true;
        }
    });

    evalForm.addEventListener('submit', function(e) {
        if (!questionsLoaded) { e.preventDefault(); alert('Questions are still loading. Please wait.'); return; }
        const radios = this.querySelectorAll('input[type="radio"][name^="rating["]');
        if (!radios.length) { e.preventDefault(); alert('No questions found. Please close and try again.'); return; }

        const unanswered = getUnansweredEvaluationQuestions(this);
        updateEvaluationProgress(this);
        if (unanswered.length) {
            e.preventDefault();
            showEvaluationValidation(this, unanswered);
            return;
        }

        if (evaluationCommentMissing(this)) {
            e.preventDefault();
            showCommentValidation(this);
            return;
        }

        clearEvaluationValidation(this);
        evalSubmitting = true;
        const submitBtn = this.querySelector('.btn-submit');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting Evaluation...';
        }
    });
}

// If the page reloaded after a submit-evaluation POST (e.g. validation
// error/success), keep the user on the Evaluate view instead of bouncing
// them back to Dashboard, since that's where the alert is relevant.
<?php if ($submit_success || $submit_error): ?>
switchView('evaluate');
<?php endif; ?>

// ── Appearance: theme (Light / Dark / System), spacing, reduced motion ──
// Per-device preferences, stored in localStorage and applied as body classes.
const PBI_THEME_KEY = 'pbi_theme', PBI_DENSITY_KEY = 'pbi_density', PBI_MOTION_KEY = 'pbi_reduce_motion';
function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
const systemDarkMQ = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

function getThemeChoice() {
    const v = lsGet(PBI_THEME_KEY);
    return (v === 'dark' || v === 'system') ? v : 'light';
}
function applyAppearance(choice) {
    const resolved = choice === 'system' ? ((systemDarkMQ && systemDarkMQ.matches) ? 'dark' : 'light') : choice;
    document.body.classList.toggle('light-theme', resolved === 'light');
    document.querySelectorAll('[data-theme-choice]').forEach(function (b) {
        b.setAttribute('aria-pressed', b.dataset.themeChoice === choice ? 'true' : 'false');
    });
    const label = document.getElementById('appearanceCurrentMode');
    if (label) {
        const name = resolved === 'dark' ? 'Dark' : 'Light';
        label.textContent = choice === 'system' ? 'System (' + name + ')' : name;
    }
}
function setAppearance(mode) {
    const choice = (mode === 'dark' || mode === 'system') ? mode : 'light';
    lsSet(PBI_THEME_KEY, choice);
    applyAppearance(choice);
}
function applyStudentDensity(d) {
    const density = d === 'compact' ? 'compact' : 'comfortable';
    document.body.classList.toggle('density-compact', density === 'compact');
    document.querySelectorAll('[data-density-choice]').forEach(function (b) {
        b.setAttribute('aria-pressed', b.dataset.densityChoice === density ? 'true' : 'false');
    });
}
function setStudentDensity(d) {
    const density = d === 'compact' ? 'compact' : 'comfortable';
    lsSet(PBI_DENSITY_KEY, density);
    applyStudentDensity(density);
}
function applyStudentReducedMotion(on) {
    document.body.classList.toggle('reduce-motion', !!on);
    const btn = document.getElementById('appearanceMotionBtn');
    if (btn) btn.setAttribute('aria-pressed', on ? 'true' : 'false');
}
function toggleStudentReducedMotion() {
    const on = !document.body.classList.contains('reduce-motion');
    lsSet(PBI_MOTION_KEY, on ? '1' : '0');
    applyStudentReducedMotion(on);
}
document.addEventListener('DOMContentLoaded', function () {
    const choice = getThemeChoice();
    lsSet(PBI_THEME_KEY, choice);
    applyAppearance(choice);
    applyStudentDensity(lsGet(PBI_DENSITY_KEY) === 'compact' ? 'compact' : 'comfortable');
    applyStudentReducedMotion(lsGet(PBI_MOTION_KEY) === '1');

    // Follow the device setting live while "System" is selected.
    if (systemDarkMQ) {
        const onChange = function () { if (getThemeChoice() === 'system') applyAppearance('system'); };
        if (systemDarkMQ.addEventListener) systemDarkMQ.addEventListener('change', onChange);
        else if (systemDarkMQ.addListener) systemDarkMQ.addListener(onChange);
    }

    // Password show/hide (SVG eye toggles)
    document.querySelectorAll('#view-settings .pw-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = btn.parentElement.querySelector('input');
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    });

    // Live preview of the chosen profile photo
    const photoInput = document.getElementById('settingsPhotoInput');
    const photoPrev  = document.getElementById('settingsPhotoPreview');
    if (photoInput && photoPrev) {
        photoInput.addEventListener('change', function () {
            if (photoInput.files && photoInput.files[0]) {
                const r = new FileReader();
                r.onload = function (e) { photoPrev.src = e.target.result; };
                r.readAsDataURL(photoInput.files[0]);
            }
        });
    }
});
</script>
<script>
(function(){
    const items = <?= json_encode($student_bell_notices, JSON_UNESCAPED_UNICODE) ?>;
    const STORE_KEY = 'pbiStudentNotifications_<?= (int)$student_id ?>';
    const btn = document.getElementById('notifBellBtn');
    const dd  = document.getElementById('notifDropdown');
    const list = document.getElementById('notifList');
    const badge = document.getElementById('notifBadge');
    if (!btn || !dd || !list || !badge) return;

    let seen = {};
    try { seen = JSON.parse(localStorage.getItem(STORE_KEY) || '{}') || {}; } catch(e){ seen = {}; }
    function save(){ try { localStorage.setItem(STORE_KEY, JSON.stringify(seen)); } catch(e){} }

    function render(){
        list.innerHTML = '';
        if (!items.length) {
            const li = document.createElement('li');
            li.className = 'notif-dd-empty';
            li.textContent = 'No notifications yet.';
            list.appendChild(li);
        }
        const cnt = document.getElementById('notifCount'); if (cnt) cnt.textContent = items.length ? String(items.length) : '';
        let unread = 0;
        items.forEach(function(n){
            const isNew = !seen[n.id];
            if (isNew) unread++;
            const li = document.createElement('li');
            if (isNew) li.className = 'unseen';
            const icon = document.createElement('i');
            icon.className = 'fa-solid ' + (/^fa-[a-z0-9-]+$/.test(n.icon || '') ? n.icon : 'fa-circle-info');
            const span = document.createElement('span');
            span.textContent = n.text;
            li.appendChild(icon); li.appendChild(span);
            list.appendChild(li);
        });
        if (unread > 0) { badge.textContent = unread > 9 ? '9+' : String(unread); badge.style.display = 'flex'; }
        else { badge.style.display = 'none'; }
    }
    function close(){ dd.classList.remove('open'); btn.setAttribute('aria-expanded','false'); dd.setAttribute('aria-hidden','true'); }

    btn.addEventListener('click', function(e){
        e.stopPropagation();
        const open = dd.classList.toggle('open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        dd.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (open) {
            // Keep the "new" highlight for this view, then mark everything read.
            items.forEach(function(n){ seen[n.id] = 1; });
            save();
            badge.style.display = 'none';
        } else {
            render();
        }
    });
    document.addEventListener('click', function(e){
        if (!dd.contains(e.target) && e.target !== btn) { if (dd.classList.contains('open')) { close(); render(); } }
    });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') { close(); render(); } });
    render();
})();
</script>
<script src="../admin/eval_status_poll.js" data-busy-selector="#evalModal.open" defer></script>
</body>
</html>