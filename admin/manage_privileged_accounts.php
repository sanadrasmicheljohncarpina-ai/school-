<?php
// admin/manage_privileged_accounts.php
// Superadmin-only. This page NO LONGER creates accounts directly.
//
// NEW MODEL (self-registration + approval):
//   - Executive Assistant / School Head / Faculty / Staff / Student all
//     self-register through their own existing registration pages
//     (executive_register.php, etc).
//   - Every new row in `users` starts life with account_status = 'pending'
//     (that's the column DEFAULT below, so this works automatically even
//     though the registration pages themselves were not touched here).
//   - This page is where the Super Admin reviews pending registrations and
//     Approves or Blocks them, individually or in bulk.
//   - Only account_status = 'approved' should be allowed to log in.
//     IMPORTANT: the actual login files (student_login.php, etc.) are NOT
//     part of this change — they still need a check added for
//     account_status = 'approved'. Flag this to Michel; happy to wire it
//     up once those files are shared.
//
// Role label note: the underlying ENUM/role value for this role is
// 'teacher' (per the earlier login-bug fix — DB uses 'teacher', never
// 'faculty'). This page just *displays* the label "Teacher" for that role,
// the same way 'superadmin' is displayed as "System Admin" elsewhere.
// Never change the stored role value to 'faculty'.
//
// ── PATCH (this pass) ────────────────────────────────────────────
// Added 'principal' and 'dean' to the roles this page recognizes. Without
// this, a self-registered Principal/Dean account lands in account_status
// = 'pending' and has NO admin UI path to ever become 'approved' — every
// query here was scoped to role IN (
// 'teacher','staff','student'), silently excluding them from every tab,
// count, and approve/block/edit/delete action.

session_start();
require_once 'db.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'superadmin') {
    header("Location: admin_dashboard.php");
    exit;
}

// ── CSRF TOKEN ───────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function verify_csrf(): bool {
    $sent = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    return is_string($sent) && $sent !== '' && hash_equals($_SESSION['csrf_token'], $sent);
}

function reject_csrf(string $viewRole, string $viewStatus): void {
    $_SESSION['toast_error'] = "Your session expired or the request looked invalid. Please try again.";
    header("Location: manage_privileged_accounts.php?role=" . urlencode($viewRole) . "&status=" . urlencode($viewStatus));
    exit;
}

// ── ENSURE year_level COLUMN EXISTS AND ALLOWS NULL ───────────
$col = $mysqli->query("SHOW COLUMNS FROM users LIKE 'year_level'");
if ($col && $col->num_rows === 0) {
    $mysqli->query("ALTER TABLE users ADD COLUMN year_level VARCHAR(30) NULL DEFAULT NULL");
} else {
    $mysqli->query("ALTER TABLE users MODIFY COLUMN year_level VARCHAR(30) NULL DEFAULT NULL");
}

// ── ONE-TIME NORMALIZATION: bare "Nth Year" → "Nth Year College" ──
// Some students self-registered before the "...College" suffix was
// standardized, so their year_level is stored as e.g. "4th Year" instead
// of "4th Year College". Bring existing rows in line with the canonical
// $year_levels list so display and exact-match filters agree. This is
// idempotent (no-ops once everything is normalized), so it's safe to run
// on every page load and will also quietly fix any future stragglers
// coming from the student self-registration page.
$mysqli->query("
    UPDATE users
    SET year_level = CONCAT(year_level, ' College')
    WHERE year_level REGEXP '^(1st|2nd|3rd|4th) Year$'
");

// ── ENSURE assigned_period COLUMN EXISTS ───────────────────────
$col2 = $mysqli->query("SHOW COLUMNS FROM users LIKE 'assigned_period'");
if ($col2 && $col2->num_rows === 0) {
    $mysqli->query("ALTER TABLE users ADD COLUMN assigned_period VARCHAR(20) NULL DEFAULT NULL");
}

// ── ENSURE account_status COLUMN EXISTS ─────────────────────────
// New accounts default to 'pending' the moment they're inserted by ANY
// registration page, with no changes needed there. Existing accounts
// (created before this feature existed) are backfilled to 'approved' once,
// below, so nobody who could already log in gets locked out retroactively.
$col3 = $mysqli->query("SHOW COLUMNS FROM users LIKE 'account_status'");
$account_status_is_new = ($col3 && $col3->num_rows === 0);
if ($account_status_is_new) {
    $mysqli->query("ALTER TABLE users ADD COLUMN account_status VARCHAR(10) NOT NULL DEFAULT 'pending'");
    // One-time backfill: anyone who already existed before this column was
    // added is presumed already-vetted (they were let in under the old
    // model), so grandfather them in as approved rather than pending.
    $mysqli->query("UPDATE users SET account_status = 'approved' WHERE account_status = 'pending'");
}

// ── ENSURE archive columns exist (used by ALL roles now — Principal, Dean,
//    Faculty, Staff and Student accounts are archived, never deleted) ──
$col4 = $mysqli->query("SHOW COLUMNS FROM users LIKE 'is_archived'");
if ($col4 && $col4->num_rows === 0) {
    $mysqli->query("ALTER TABLE users ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0");
}
$col5 = $mysqli->query("SHOW COLUMNS FROM users LIKE 'archived_previous_active'");
if ($col5 && $col5->num_rows === 0) {
    $mysqli->query("ALTER TABLE users ADD COLUMN archived_previous_active TINYINT(1) NULL DEFAULT NULL");
}

// ── ENSURE user_year_levels TABLE EXISTS ────────────────────────
$mysqli->query("
    CREATE TABLE IF NOT EXISTS user_year_levels (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        year_level VARCHAR(30) NOT NULL,
        UNIQUE KEY uniq_user_year (user_id, year_level),
        CONSTRAINT fk_uyl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )
");

$valid_roles = ['principal', 'dean', 'teacher', 'staff', 'student'];

$role_labels = [
    'principal'             => 'Principal',
    'dean'                  => 'Dean',
    'teacher'               => 'Faculty',   // display label only — role value stays 'teacher'
    'staff'                 => 'Staff',
    'student'               => 'Student',
];
$role_icons = [
    'principal'             => 'fa-user-tie',
    'dean'                  => 'fa-graduation-cap',
    'teacher'               => 'fa-chalkboard-user',
    'staff'                 => 'fa-id-badge',
    'student'               => 'fa-user-graduate',
];

$valid_statuses = ['pending', 'approved', 'blocked'];
$status_labels = [
    'pending'  => 'Pending Approval',
    'approved' => 'Approved',
    'blocked'  => 'Blocked',
];
$status_icons = [
    'pending'  => 'fa-hourglass-half',
    'approved' => 'fa-circle-check',
    'blocked'  => 'fa-ban',
];

// Year level options — grouped by school level
$year_levels = [
    'Grade 7','Grade 8','Grade 9','Grade 10',
    'Grade 11','Grade 12',
    '1st Year College','2nd Year College','3rd Year College','4th Year College',
];
$college_levels = ['1st Year College','2nd Year College','3rd Year College','4th Year College'];
$period_options = ['1st Semester','2nd Semester','Summer'];

// Active evaluation period's semester — used to flag College teachers/staff
// whose assigned_period doesn't match the term that's actually running
// right now (e.g. assigned to "2nd Semester" while the active period is
// "1st Semester"). Same real-world consequence as having no period set at
// all: they won't be visible to any student this term, so it needs the
// same kind of warning, not the normal "assigned" pill.
$active_period_row     = $mysqli->query("SELECT semester FROM evaluation_periods WHERE is_active=1 LIMIT 1")->fetch_assoc();
$active_period_semester = $active_period_row['semester'] ?? null;

// School-level groupings — used for the Student "Filter by School Level" control
$school_levels = [
    'junior_high' => ['label' => 'Junior High School', 'levels' => ['Grade 7','Grade 8','Grade 9','Grade 10']],
    'senior_high' => ['label' => 'Senior High School', 'levels' => ['Grade 11','Grade 12']],
    'college'     => ['label' => 'College',             'levels' => $college_levels],
];

// Classifies a raw year_level string into junior_high / senior_high / college.
// Done by pattern rather than exact match against $school_levels[...]['levels']
// because some existing rows store college levels as "4th Year" instead of
// the canonical "4th Year College" — an exact-match filter would silently
// exclude those students from the College bucket.
function classify_student_level(?string $yl): ?string {
    if ($yl === null || trim($yl) === '') return null;
    $yl = trim($yl);
    if (preg_match('/^Grade\s*(7|8|9|10)\b/i', $yl)) return 'junior_high';
    if (preg_match('/^Grade\s*(11|12)\b/i', $yl)) return 'senior_high';
    if (preg_match('/college/i', $yl) || preg_match('/^(1st|2nd|3rd|4th)\s*Year\b/i', $yl)) return 'college';
    return null;
}

// Maps a raw year_level string to the CSS class for its color-coded pill —
// blue for Junior High, pink for Senior High, teal for College — reusing
// classify_student_level()'s bucketing so both stay in agreement.
function level_pill_class(?string $yl): string {
    return match (classify_student_level($yl)) {
        'junior_high' => 'pill-jhs',
        'senior_high' => 'pill-shs',
        'college'     => 'pill-col',
        default       => '',
    };
}

// Normalizes a stored year_level for display, so even if a legacy/edge-case
// row slips through the migration above (e.g. inserted directly by another
// script, or by the student self-registration page after this page last
// ran), the UI still shows the canonical "...College" label instead of a
// bare "Nth Year" — and the Edit modal's <select> can actually match it
// against one of its <option value="..."> entries.
function normalize_year_level(?string $yl): ?string {
    if ($yl === null || trim($yl) === '') return $yl;
    $yl = trim($yl);
    if (preg_match('/^(1st|2nd|3rd|4th)\s*Year$/i', $yl)) {
        return $yl . ' College';
    }
    return $yl;
}

// Archived view: available for every role, plus an "All" tab (role=all) that
// lists archived accounts across all five roles. "all" is only valid here.
$viewArchived = (($_GET['archived'] ?? '') === '1');
$viewRole = $_GET['role'] ?? 'teacher';
if (!in_array($viewRole, $valid_roles, true) && !($viewArchived && $viewRole === 'all')) $viewRole = 'teacher';

$viewStatus = $_GET['status'] ?? 'pending';
if (!in_array($viewStatus, $valid_statuses, true)) $viewStatus = 'pending';

$ylFilter = trim($_GET['yl'] ?? '');
if ($ylFilter !== '' && !in_array($ylFilter, $year_levels, true)) $ylFilter = '';

$levelFilter = trim($_GET['level'] ?? '');
if ($levelFilter !== '' && !array_key_exists($levelFilter, $school_levels)) $levelFilter = '';

$toast       = $_SESSION['toast']       ?? ''; unset($_SESSION['toast']);
$toast_error = $_SESSION['toast_error'] ?? ''; unset($_SESSION['toast_error']);

function redirect_back(string $viewRole, string $viewStatus, string $ylFilter = '', string $levelFilter = '', bool $showArchived = false): void {
    $url = "manage_privileged_accounts.php?role=" . urlencode($viewRole) . "&status=" . urlencode($viewStatus);
    if ($ylFilter !== '') $url .= "&yl=" . urlencode($ylFilter);
    if ($levelFilter !== '') $url .= "&level=" . urlencode($levelFilter);
    if ($showArchived) $url .= "&archived=1";
    header("Location: $url");
    exit;
}

// ── APPROVE (single) ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_account') {
    if (!verify_csrf()) reject_csrf($viewRole, $viewStatus);
    $uid = intval($_POST['user_id'] ?? 0);
    $stmt = $mysqli->prepare("UPDATE users SET account_status='approved', is_active=1 WHERE id=? AND role IN ('principal','dean','teacher','staff','student') AND COALESCE(is_archived,0)=0");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $stmt->close();
    $_SESSION['toast'] = "Account approved. They can now log in.";
    redirect_back($viewRole, $viewStatus, $ylFilter, $levelFilter);
}

// ── BLOCK (single) ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'block_account') {
    if (!verify_csrf()) reject_csrf($viewRole, $viewStatus);
    $uid = intval($_POST['user_id'] ?? 0);
    if ($uid === (int)$_SESSION['user_id']) {
        $_SESSION['toast_error'] = "You can't block your own account.";
    } else {
        $stmt = $mysqli->prepare("UPDATE users SET account_status='blocked', is_active=0 WHERE id=? AND role IN ('principal','dean','teacher','staff','student') AND COALESCE(is_archived,0)=0");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->close();
        $_SESSION['toast'] = "Account blocked. They can no longer log in.";
    }
    redirect_back($viewRole, $viewStatus, $ylFilter, $levelFilter);
}

// ── BULK APPROVE ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_approve') {
    if (!verify_csrf()) reject_csrf($viewRole, $viewStatus);
    $uids = array_values(array_unique(array_filter(array_map('intval', $_POST['user_ids'] ?? []))));
    if (empty($uids)) {
        $_SESSION['toast_error'] = "No accounts selected.";
    } else {
        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $stmt = $mysqli->prepare("UPDATE users SET account_status='approved', is_active=1 WHERE id IN ($placeholders) AND role IN ('principal','dean','teacher','staff','student') AND COALESCE(is_archived,0)=0");
        $stmt->bind_param(str_repeat('i', count($uids)), ...$uids);
        $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();
        $_SESSION['toast'] = "$n account" . ($n === 1 ? '' : 's') . " approved.";
    }
    redirect_back($viewRole, $viewStatus, $ylFilter, $levelFilter);
}

// ── BULK BLOCK ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_block') {
    if (!verify_csrf()) reject_csrf($viewRole, $viewStatus);
    $uids = array_values(array_unique(array_filter(array_map('intval', $_POST['user_ids'] ?? []))));
    $uids = array_values(array_diff($uids, [(int)$_SESSION['user_id']])); // never block yourself
    if (empty($uids)) {
        $_SESSION['toast_error'] = "No eligible accounts selected.";
    } else {
        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $stmt = $mysqli->prepare("UPDATE users SET account_status='blocked', is_active=0 WHERE id IN ($placeholders) AND role IN ('principal','dean','teacher','staff','student') AND COALESCE(is_archived,0)=0");
        $stmt->bind_param(str_repeat('i', count($uids)), ...$uids);
        $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();
        $_SESSION['toast'] = "$n account" . ($n === 1 ? '' : 's') . " blocked.";
    }
    redirect_back($viewRole, $viewStatus, $ylFilter, $levelFilter);
}

// ── EDIT NAME / YEAR LEVEL ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_account') {
    if (!verify_csrf()) reject_csrf($viewRole, $viewStatus);

    $uid       = intval($_POST['user_id'] ?? 0);
    $full_name = trim($_POST['full_name'] ?? '');

$rowStmt = $mysqli->prepare("SELECT role FROM users WHERE id=? AND role IN ('principal','dean','teacher','staff','student') LIMIT 1");
    $rowStmt->bind_param("i", $uid);
    $rowStmt->execute();
    $rowChk = $rowStmt->get_result()->fetch_assoc();
    $rowStmt->close();

    if (!$rowChk) {
        $_SESSION['toast_error'] = "That account can't be edited here.";
        redirect_back($viewRole, $viewStatus, $ylFilter, $levelFilter);
    }
    $isStudentRow = $rowChk['role'] === 'student';

    if ($full_name !== '') {
        
        if ($isStudentRow) {
            $year_level = in_array($_POST['year_level'] ?? '', $year_levels, true) ? $_POST['year_level'] : null;
            $stmt = $mysqli->prepare("UPDATE users SET full_name = ?, year_level = ? WHERE id = ?");
            $stmt->bind_param("ssi", $full_name, $year_level, $uid);
        } else {
            $stmt = $mysqli->prepare("UPDATE users SET full_name = ? WHERE id = ?");
            $stmt->bind_param("si", $full_name, $uid);
        }
        $stmt->execute();
        $stmt->close();
        $_SESSION['toast'] = "Account updated.";
    }
    redirect_back($viewRole, $viewStatus, $ylFilter, $levelFilter);
}

// ── RESET PASSWORD ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    if (!verify_csrf()) reject_csrf($viewRole, $viewStatus);

    $uid      = intval($_POST['user_id'] ?? 0);
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $_SESSION['toast_error'] = "Password must be at least 8 characters.";
    } elseif ($password !== $confirm) {
        $_SESSION['toast_error'] = "Passwords do not match.";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role IN ('principal','dean','teacher','staff','student')");
        $stmt->bind_param("si", $hash, $uid);
        $stmt->execute();
        $stmt->close();
        $_SESSION['toast'] = "Password reset. Share the new password with them directly.";
    }
    redirect_back($viewRole, $viewStatus, $ylFilter, $levelFilter);
}

// ── Notify a Teacher/Staff member when the EA changes their year-level (teaching) assignment ──
function pbi_notify_year_level_change(mysqli $mysqli, int $uid, array $old, array $new): void {
    sort($old); sort($new);
    if ($old === $new) return; // nothing actually changed
    if (empty($new)) {
        $msg = 'The Executive Assistant removed your teaching assignment.';
    } elseif (empty($old)) {
        $msg = 'The Executive Assistant assigned you to teach: ' . implode(', ', $new) . '.';
    } else {
        $msg = 'The Executive Assistant updated your teaching assignment: ' . implode(', ', $new) . '.';
    }
    $stmt = $mysqli->prepare("INSERT INTO notifications (type, user_id, message) VALUES ('teaching_assignment', ?, ?)");
    if (!$stmt) return; // notifications table missing: never block the assignment itself
    $stmt->bind_param("is", $uid, $msg);
    $stmt->execute();
    $stmt->close();
}

function pbi_current_year_levels(mysqli $mysqli, array $uids): array {
    $out = [];
    foreach ($uids as $u) $out[(int)$u] = [];
    if (empty($uids)) return $out;
    $ph = implode(',', array_fill(0, count($uids), '?'));
    $stmt = $mysqli->prepare("SELECT user_id, year_level FROM user_year_levels WHERE user_id IN ($ph)");
    if (!$stmt) return $out;
    $ids = array_map('intval', $uids);
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($r = $res->fetch_assoc())) $out[(int)$r['user_id']][] = $r['year_level'];
    $stmt->close();
    return $out;
}

// ── ASSIGN YEAR LEVELS (single, Teacher/Staff only) ──────────────
// Replaces self-select: the Super Admin now sets which year level(s) an
// employee is responsible for / teaching. Supports multiple per employee.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_year_levels') {
    if (!verify_csrf()) reject_csrf($viewRole, $viewStatus);

    $uid = intval($_POST['user_id'] ?? 0);
    $selected = array_values(array_intersect($_POST['year_levels'] ?? [], $year_levels));
    $oldLevels = pbi_current_year_levels($mysqli, [$uid])[$uid] ?? [];

    $mysqli->begin_transaction();
    $del = $mysqli->prepare("DELETE FROM user_year_levels WHERE user_id=?");
    $del->bind_param("i", $uid);
    $del->execute();
    $del->close();

    if (!empty($selected)) {
        $ins = $mysqli->prepare("INSERT INTO user_year_levels (user_id, year_level) VALUES (?, ?)");
        foreach ($selected as $yl) {
            $ins->bind_param("is", $uid, $yl);
            $ins->execute();
        }
        $ins->close();
    }
    $mysqli->commit();
    pbi_notify_year_level_change($mysqli, $uid, $oldLevels, $selected);

    $_SESSION['toast'] = !empty($selected)
        ? "Year level(s) assigned: " . implode(', ', $selected) . "."
        : "Year level assignments cleared.";
    redirect_back($viewRole, $viewStatus, $ylFilter, $levelFilter);
}

// ── BULK ASSIGN YEAR LEVELS (Teacher/Staff only) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_assign_year_levels') {
    if (!verify_csrf()) reject_csrf($viewRole, $viewStatus);

    $uids     = array_values(array_unique(array_filter(array_map('intval', $_POST['user_ids'] ?? []))));
    $selected = array_values(array_intersect($_POST['year_levels'] ?? [], $year_levels));

    if (empty($uids)) {
        $_SESSION['toast_error'] = "No accounts selected.";
    } else {
        $oldByUser = pbi_current_year_levels($mysqli, $uids);
        $mysqli->begin_transaction();
        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $del = $mysqli->prepare("DELETE FROM user_year_levels WHERE user_id IN ($placeholders)");
        $del->bind_param(str_repeat('i', count($uids)), ...$uids);
        $del->execute();
        $del->close();

        if (!empty($selected)) {
            $ins = $mysqli->prepare("INSERT INTO user_year_levels (user_id, year_level) VALUES (?, ?)");
            foreach ($uids as $u) {
                foreach ($selected as $yl) {
                    $ins->bind_param("is", $u, $yl);
                    $ins->execute();
                }
            }
            $ins->close();
        }
        $mysqli->commit();
        foreach ($uids as $u) {
            pbi_notify_year_level_change($mysqli, (int)$u, $oldByUser[(int)$u] ?? [], $selected);
        }

        $n = count($uids);
        $_SESSION['toast'] = !empty($selected)
            ? "Year level(s) assigned to $n account" . ($n === 1 ? '' : 's') . ": " . implode(', ', $selected) . "."
            : "Year level assignments cleared for $n account" . ($n === 1 ? '' : 's') . ".";
    }
    redirect_back($viewRole, $viewStatus, $ylFilter, $levelFilter);
}

// ── ASSIGN PERIOD (College teacher/staff only) ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_period') {
    if (!verify_csrf()) reject_csrf($viewRole, $viewStatus);

    $uid    = intval($_POST['user_id'] ?? 0);
    $period = $_POST['period'] ?? '';
    $period = in_array($period, $period_options, true) ? $period : null;

    $hasCollege = false;
    $chk = $mysqli->prepare("SELECT 1 FROM user_year_levels WHERE user_id=? AND year_level IN ('1st Year College','2nd Year College','3rd Year College','4th Year College') LIMIT 1");
    $chk->bind_param("i", $uid);
    $chk->execute();
    $hasCollege = $chk->get_result()->num_rows > 0;
    $chk->close();

    if (!$hasCollege) {
        $_SESSION['toast_error'] = "That person has no College year level assigned yet — period assignment is College-only.";
    } else {
        $stmt = $mysqli->prepare("UPDATE users SET assigned_period = ? WHERE id = ?");
        $stmt->bind_param("si", $period, $uid);
        $stmt->execute();
        $stmt->close();
        $_SESSION['toast'] = $period ? "Period assigned: $period." : "Period assignment cleared.";
    }
    redirect_back($viewRole, $viewStatus, $ylFilter, $levelFilter);
}

// ── BULK ASSIGN PERIOD (College teacher/staff only) ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_assign_period') {
    if (!verify_csrf()) reject_csrf($viewRole, $viewStatus);

    $uids   = array_values(array_unique(array_filter(array_map('intval', $_POST['user_ids'] ?? []))));
    $period = $_POST['period'] ?? '';
    $period = in_array($period, $period_options, true) ? $period : null;

    if (empty($uids)) {
        $_SESSION['toast_error'] = "No accounts selected.";
    } else {
        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $collegeStmt = $mysqli->prepare(
            "SELECT DISTINCT user_id FROM user_year_levels
             WHERE user_id IN ($placeholders)
             AND year_level IN ('1st Year College','2nd Year College','3rd Year College','4th Year College')"
        );
        $collegeStmt->bind_param(str_repeat('i', count($uids)), ...$uids);
        $collegeStmt->execute();
        $collegeIds = array_column($collegeStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'user_id');
        $collegeStmt->close();

        $skipped = count($uids) - count($collegeIds);

        if (empty($collegeIds)) {
            $_SESSION['toast_error'] = "None of the selected accounts have a College year level assigned — period assignment is College-only.";
        } else {
            $ph2   = implode(',', array_fill(0, count($collegeIds), '?'));
            $types = 's' . str_repeat('i', count($collegeIds));
            $stmt  = $mysqli->prepare("UPDATE users SET assigned_period = ? WHERE id IN ($ph2)");
            $stmt->bind_param($types, $period, ...$collegeIds);
            $stmt->execute();
            $stmt->close();

            $n = count($collegeIds);
            $msg = $period
                ? "Period '$period' assigned to $n account" . ($n === 1 ? '' : 's') . "."
                : "Period assignment cleared for $n account" . ($n === 1 ? '' : 's') . ".";
            if ($skipped > 0) {
                $msg .= " ($skipped skipped — no College year level.)";
            }
            $_SESSION['toast'] = $msg;
        }
    }
    redirect_back($viewRole, $viewStatus, $ylFilter, $levelFilter);
}


// ── ARCHIVE ACCOUNT (all roles; preserves evaluation-linked records) ──
// Replaces the old hard DELETE for Principal / Dean / Faculty / Staff, so
// evaluation history is never orphaned or lost. Same flow students use.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive_account') {
    if (!verify_csrf()) reject_csrf($viewRole, $viewStatus);

    $id = intval($_POST['archive_id'] ?? 0);
    if ($id === (int)$_SESSION['user_id']) {
        $_SESSION['toast_error'] = "You can't archive your own account.";
    } else {
        $stmt = $mysqli->prepare("UPDATE users SET is_archived=1, archived_previous_active=is_active, is_active=0 WHERE id=? AND role IN ('principal','dean','teacher','staff','student') AND COALESCE(is_archived,0)=0");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed > 0) {
            $_SESSION['toast'] = "Account archived. Evaluation records and account data were preserved.";
        } else {
            $_SESSION['toast_error'] = "Account was not found or is already archived.";
        }
    }
    redirect_back($viewRole, $viewStatus, '', '', true);
}

// ── RESTORE ARCHIVED ACCOUNT (all roles) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_account') {
    if (!verify_csrf()) reject_csrf($viewRole, $viewStatus);

    $id = intval($_POST['archive_id'] ?? 0);
    if ($id === (int)$_SESSION['user_id']) {
        $_SESSION['toast_error'] = "You can't restore your own account from this page.";
        redirect_back($viewRole, $viewStatus, '', $levelFilter, true);
    }

    $lookup = $mysqli->prepare("SELECT role, account_status FROM users WHERE id=? AND role IN ('principal','dean','teacher','staff','student') AND is_archived=1 LIMIT 1");
    $lookup->bind_param("i", $id);
    $lookup->execute();
    $archivedAccount = $lookup->get_result()->fetch_assoc();
    $lookup->close();

    if (!$archivedAccount) {
        $_SESSION['toast_error'] = "Archived account not found.";
        redirect_back($viewRole, $viewStatus, '', $levelFilter, true);
    }

    $stmt = $mysqli->prepare("UPDATE users SET is_archived=0, is_active=IF(account_status='approved', COALESCE(archived_previous_active,1), 0), archived_previous_active=NULL WHERE id=? AND role IN ('principal','dean','teacher','staff','student') AND is_archived=1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    if ($changed > 0) {
        $_SESSION['toast'] = "Account restored from archive.";
    } else {
        $_SESSION['toast_error'] = "Account could not be restored.";
    }
    $restoreStatus = in_array($archivedAccount['account_status'], $valid_statuses, true) ? $archivedAccount['account_status'] : $viewStatus;
    // Land on the restored account's own role tab (the "All" archive view has no live tab).
    redirect_back($archivedAccount['role'], $restoreStatus, '', '', false);
}

// NOTE: the old 'delete_account' handler was removed on purpose. No role can
// be hard-deleted from this page anymore — everyone is archived instead.

// ── FETCH DATA ───────────────────────────────────────────────────
// Shows self-registered accounts for the selected role + approval status.
// Newest-first when reviewing Pending (so the newest registrations surface
// first for triage); alphabetical once already Approved/Blocked.
$orderClause = $viewStatus === 'pending' ? 'u.created_at DESC' : 'u.full_name ASC';
// Archived view is available for every role. All other views exclude archived rows.
if ($viewArchived && $viewRole === 'all') {
    $stmt = $mysqli->prepare("SELECT u.* FROM users u WHERE u.role IN ('principal','dean','teacher','staff','student') AND COALESCE(u.is_archived,0)=1 ORDER BY FIELD(u.role,'principal','dean','teacher','staff','student'), u.full_name ASC");
} elseif ($viewArchived) {
    $stmt = $mysqli->prepare("SELECT u.* FROM users u WHERE u.role=? AND COALESCE(u.is_archived,0)=1 ORDER BY u.full_name ASC");
    $stmt->bind_param("s", $viewRole);
} else {
    $stmt = $mysqli->prepare("SELECT u.* FROM users u WHERE u.role=? AND u.account_status=? AND COALESCE(u.is_archived,0)=0 ORDER BY $orderClause");
    $stmt->bind_param("ss", $viewRole, $viewStatus);
}
$stmt->execute();
$entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// For Teacher/Staff, pull assigned year levels (multiple per person) and
// attach to each row, regardless of status (useful context while reviewing
// a pending registration too).
if (($viewRole === 'teacher' || $viewRole === 'staff') && $entries) {
    $ids = array_column($entries, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $ylStmt = $mysqli->prepare("SELECT user_id, year_level FROM user_year_levels WHERE user_id IN ($placeholders) ORDER BY year_level ASC");
    $ylStmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $ylStmt->execute();
    $ylRows = $ylStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ylStmt->close();

    $ylByUser = [];
    foreach ($ylRows as $row) {
        $ylByUser[$row['user_id']][] = $row['year_level'];
    }
    foreach ($entries as &$u) {
        $u['year_levels'] = $ylByUser[$u['id']] ?? [];
        $u['has_college']  = (bool) array_intersect($u['year_levels'], $college_levels);
    }
    unset($u);

    if ($ylFilter !== '') {
        $entries = array_values(array_filter($entries, fn($u) => in_array($ylFilter, $u['year_levels'], true)));
    }
}

// For Student, filter by school-level grouping (Junior High / Senior High / College).
// Uses classify_student_level() (pattern match) rather than an exact-value
// lookup against $school_levels[...]['levels'], so college students whose
// year_level is stored as "4th Year" (not "4th Year College") still count.
if ($viewRole === 'student' && $entries && $levelFilter !== '') {
    $entries = array_values(array_filter($entries, fn($u) => classify_student_level($u['year_level']) === $levelFilter));
}

// Counts: role x status grid, in one query. The tab a person appears under
// follows their account `role` only: Staff stay under Staff even after the EA
// assigns them a year level to teach, and Faculty is strictly role = 'teacher'.
$countGrid = [];
foreach ($valid_roles as $r) { foreach ($valid_statuses as $s) { $countGrid[$r][$s] = 0; } }
$cq = $mysqli->query("SELECT u.role AS report_role, u.account_status, COUNT(*) c
    FROM users u
    WHERE u.role IN ('" . implode("','", $valid_roles) . "')
      AND COALESCE(u.is_archived,0)=0
    GROUP BY u.role, u.account_status");
if ($cq) {
    while ($row = $cq->fetch_assoc()) {
        if (isset($countGrid[$row['report_role']][$row['account_status']])) {
            $countGrid[$row['report_role']][$row['account_status']] = (int)$row['c'];
        }
    }
}
$roleTotals = [];
foreach ($valid_roles as $r) { $roleTotals[$r] = array_sum($countGrid[$r]); }
// Archived counts per role (feeds the Archived tab badge and the archive-mode role tabs).
$archivedByRole = array_fill_keys($valid_roles, 0);
$aq = $mysqli->query("SELECT role, COUNT(*) c FROM users
    WHERE role IN ('" . implode("','", $valid_roles) . "') AND COALESCE(is_archived,0)=1
    GROUP BY role");
if ($aq) {
    while ($row = $aq->fetch_assoc()) {
        if (isset($archivedByRole[$row['role']])) $archivedByRole[$row['role']] = (int)$row['c'];
    }
}
$archivedTotal = array_sum($archivedByRole);
// The Archived tab badge always shows the overall archived total ($archivedTotal), regardless of the role tab being viewed.

// Pending/Approved/Blocked counts for the current view ("all" only exists in the archive view).
$viewCounts = ['pending' => 0, 'approved' => 0, 'blocked' => 0];
if ($viewRole !== 'all') {
    $viewCounts = $countGrid[$viewRole];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Account Management — PBI Super Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<style>
:root{
  --page-bg:#F8FAFC;--card-bg:#FFFFFF;--inner:#F4F8FF;--card-border:#B9CDE5;
  --text-dark:#0B1F3A;--text-dim:#67819E;--track-bg:#B9CDE5;
  --page-text-dark:#0B1F3A;--page-text-dim:#67819E;
  --radius:10px;--card-shadow:0 1px 2px rgba(30,82,144,.05),0 4px 12px rgba(30,82,144,.06);
  --accent:#2563EB;--accent-bg:rgba(37,99,235,.08);--accent-border:rgba(37,99,235,.16);--hover:#5B9BFA;
  --teal:#0E7490;--teal-bg:rgba(13,148,136,.14);--teal-border:rgba(13,148,136,.32);
  --pink:#EC4899;--pink-bg:rgba(236,72,153,.14);--pink-border:rgba(236,72,153,.32);
  --amber:#C77A08;--amber-bg:rgba(217,119,6,.08);--amber-border:rgba(217,119,6,.24);
  --success:#0F9F6E;--success-bg:rgba(5,150,105,.1);--success-border:rgba(5,150,105,.25);
  --danger:#D6455D;--danger-bg:rgba(220,38,38,.08);--danger-border:rgba(220,38,38,.22);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html{scrollbar-width:thin;scrollbar-color:#B8CCE5 transparent;}
body{font-family:'Inter',sans-serif;background:var(--page-bg);color:var(--text-dark);min-height:100vh;padding:36px 28px;}
::-webkit-scrollbar{width:8px;height:8px;}
::-webkit-scrollbar-button{display:none;height:0;width:0;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:#B8CCE5;border-radius:8px;}
::-webkit-scrollbar-thumb:hover{background:#A6B2C4;}

.toast{position:fixed;top:20px;right:20px;z-index:9999;background:#E8F8F1;border:1px solid #BEEBD8;color:#0D7A4E;padding:12px 20px;border-radius:8px;font-size:13px;display:flex;align-items:center;gap:8px;animation:slideIn .3s ease,fadeOut .4s ease 4s forwards;max-width:420px;box-shadow:var(--card-shadow);}
.toast.error{background:var(--danger-bg);border-color:var(--danger-border);color:var(--danger);}
@keyframes slideIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:none}}
@keyframes fadeOut{to{opacity:0;pointer-events:none}}

.back-link{display:inline-flex;align-items:center;gap:8px;color:var(--page-text-dim);text-decoration:none;font-size:13px;margin-bottom:20px;}
.back-link:hover{color:var(--page-text-dark);}

.page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;padding:22px 26px;border-radius:14px;background:var(--card-bg);border:1px solid var(--card-border);gap:16px;flex-wrap:wrap;box-shadow:var(--card-shadow);}
.page-header h1{font-family:'Rajdhani',sans-serif;font-size:28px;font-weight:700;color:var(--text-dark);margin-bottom:3px;}
.page-header p{font-size:13px;color:var(--text-dim);}

.info-banner{background:var(--accent-bg);border:1px solid var(--accent-border);border-radius:var(--radius);padding:14px 18px;margin-bottom:24px;display:flex;gap:12px;align-items:flex-start;font-size:13px;color:var(--page-text-dim);line-height:1.6;}
.info-banner i{flex-shrink:0;margin-top:2px;color:var(--accent);}
.info-banner strong{color:var(--page-text-dark);}

.sector-tabs{display:flex;gap:4px;background:var(--card-bg);border:1px solid var(--card-border);border-radius:var(--radius);padding:4px;margin-bottom:14px;width:fit-content;flex-wrap:wrap;box-shadow:var(--card-shadow);}
.sector-tab{padding:9px 20px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;transition:all .22s;background:transparent;color:var(--text-dim);display:flex;align-items:center;gap:7px;text-decoration:none;font-family:'Inter',sans-serif;}
.sector-tab.active{background:var(--accent);color:#fff;}
.sector-tab:not(.active):hover{color:var(--text-dark);background:rgba(37,99,235,.05);}
.tab-badge{background:rgba(30,82,144,.13);border-radius:20px;padding:1px 8px;font-size:11px;font-weight:700;color:var(--text-dim);}
.sector-tab.active .tab-badge{background:rgba(255,255,255,.28);color:#fff;}
.tab-badge.pending-badge{background:var(--amber-bg);color:var(--amber);}

.status-tabs{display:flex;gap:4px;background:var(--card-bg);border:1px solid var(--card-border);border-radius:var(--radius);padding:4px;margin-bottom:24px;width:fit-content;flex-wrap:wrap;box-shadow:var(--card-shadow);}
.status-tab{padding:8px 16px;border:none;border-radius:7px;font-size:12.5px;font-weight:600;cursor:pointer;transition:all .22s;background:transparent;color:var(--text-dim);display:flex;align-items:center;gap:6px;text-decoration:none;font-family:'Inter',sans-serif;}
.status-tab.active[data-s="pending"]{background:var(--amber-bg);color:var(--amber);}
.status-tab.active[data-s="approved"]{background:var(--success-bg);color:var(--success);}
.status-tab.active[data-s="blocked"]{background:var(--danger-bg);color:var(--danger);}
.status-tab:not(.active):hover{color:var(--text-dark);background:rgba(37,99,235,.05);}
html.pbi-parent-dims .modal-overlay{background:transparent !important;}
.archive-panel{background:var(--card-bg);border:1px solid var(--card-border);border-radius:14px;padding:22px 24px 24px;margin-bottom:24px;box-shadow:var(--card-shadow);}
.archive-panel-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:16px;}
.archive-panel-title{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;color:var(--text-dark);display:flex;align-items:center;gap:8px;}
.archive-panel-title i{color:var(--text-dim);font-size:16px;}
.archive-panel-sub{font-size:12.5px;color:var(--text-dim);margin-top:3px;max-width:640px;line-height:1.5;}
.archive-panel-total{font-size:12px;font-weight:600;color:var(--text-dim);white-space:nowrap;}
.archive-panel-total span{font-size:22px;font-weight:700;color:var(--text-dark);margin-right:3px;}
.archive-panel .archive-subtabs{margin-bottom:16px;}
.archive-panel .table-wrap{box-shadow:none;}
/* Status row: approval-state tabs on the left, Archived on the opposite (right) side */
.role-row{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
.role-row .sector-tabs{margin-bottom:0;}
.role-row .archive-tab-wrap{margin:0 0 0 auto;}
.status-row{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:24px;}
.status-row .status-tabs{margin-bottom:0;}
.status-row .archive-tab-wrap{margin-left:auto;}
.role-pill{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:var(--inner);border:1px solid var(--card-border);color:var(--text-dark);}

.stats-row{display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap;}
.stat-card{background:var(--card-bg);border:1px solid var(--card-border);border-top:4px solid var(--accent);border-radius:14px;padding:16px 22px;flex:1;min-width:130px;box-shadow:var(--card-shadow);}
.stat-card.amber-card{border-top-color:var(--amber);}
.stat-card.green-card{border-top-color:var(--success);}
.stat-card.red-card{border-top-color:var(--danger);}
.stat-label{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--text-dim);margin-bottom:6px;}
.stat-value{font-size:26px;font-weight:700;color:var(--text-dark);}
.stat-value.amber{color:var(--amber);}
.stat-value.green{color:var(--success);}
.stat-value.red{color:var(--danger);}

.table-wrap{background:var(--card-bg);border:1px solid var(--card-border);border-radius:14px;overflow:hidden;box-shadow:var(--card-shadow);}
table{width:100%;border-collapse:collapse;}
thead tr{background:var(--inner);}
thead th{padding:12px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-dim);text-align:left;white-space:nowrap;border-bottom:1px solid var(--card-border);}
tbody tr{border-bottom:1px solid var(--card-border);}
tbody tr:last-child{border-bottom:none;}
tbody tr:hover{background:var(--page-bg);}
tbody tr.row-selected{background:var(--accent-bg);}
tbody tr.row-highlight{animation:rowHighlightFlash 1.8s ease-out;}
@keyframes rowHighlightFlash{0%{background:var(--accent-bg,#E6F0FF);}100%{background:transparent;}}
tbody td{padding:14px 16px;font-size:14px;vertical-align:middle;}
.user-name{font-weight:600;color:var(--text-dark);}
.user-username{font-size:12px;color:var(--text-dim);}
.designation-cell{max-width:220px;font-size:13px;font-weight:600;color:var(--text-dark);white-space:normal;word-break:break-word;line-height:1.35;}
.year-level-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:var(--accent-bg);color:#93C5FD;}
.year-level-pill.pill-jhs{
    background:rgba(40,105,196,.13) !important;
    color:#4B91FF !important;
    border:1px solid #2869C4 !important;
}
.year-level-pill.pill-shs{background:var(--pink-bg);color:var(--pink);}
.year-level-pill.pill-col{background:var(--teal-bg);color:var(--teal);}
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.status-pill.pending{background:var(--amber-bg);color:var(--amber);}
.status-pill.approved{background:var(--success-bg);color:var(--success);}
.status-pill.blocked{background:var(--danger-bg);color:var(--danger);}
.status-pill.active-sub{background:var(--success-bg);color:var(--success);}
.status-pill.inactive-sub{background:var(--danger-bg);color:var(--danger);}

.action-wrap{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}
.btn-icon{background:var(--inner);border:1px solid var(--card-border);border-radius:6px;padding:6px 10px;color:var(--text-dim);cursor:pointer;font-size:13px;transition:all .2s;font-family:'Inter',sans-serif;}
.btn-icon:hover{background:rgba(37,99,235,.06);color:var(--text-dark);}
.btn-icon.approve{border-color:var(--success-border);color:var(--success);}
.btn-icon.approve:hover{background:var(--success-bg);}
.btn-icon.block{border-color:var(--danger-border);color:var(--danger);}
.btn-icon.block:hover{background:var(--danger-bg);}
.btn-icon.edit:hover{border-color:var(--accent-border);color:#93C5FD;}
.btn-icon.key:hover{border-color:var(--amber-border);color:var(--amber);}
.btn-icon.danger:hover{border-color:var(--danger-border);color:var(--danger);}
.action-divider{display:inline-block;width:1px;align-self:stretch;margin:2px 5px;background:var(--card-border);}

.empty-state{text-align:center;padding:56px 20px;color:var(--text-dim);}
.empty-state i{font-size:40px;margin-bottom:14px;display:block;opacity:.3;}

.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55) !important;backdrop-filter:none !important;-webkit-backdrop-filter:none !important;z-index:200;display:none;align-items:center;justify-content:center;padding:20px;}
.modal-overlay.open{display:flex;}
.modal{background:var(--card-bg);border:1px solid var(--card-border);border-radius:16px;padding:30px;width:100%;max-width:440px;box-shadow:0 24px 64px rgba(0,0,0,.45);max-height:90vh;overflow-y:auto;}
.modal-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;}
.modal-title{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;color:var(--text-dark);}
.modal-sub{font-size:12px;color:var(--text-dim);margin-top:3px;}
.modal-close{background:none;border:none;color:var(--text-dim);font-size:18px;cursor:pointer;padding:3px 7px;}
.modal-close:hover{color:var(--text-dark);}
.fg{display:flex;flex-direction:column;gap:5px;margin-bottom:14px;}
.fg label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--text-dim);}
.fg input,.fg select{background:var(--inner);border:1px solid var(--card-border);border-radius:8px;padding:10px 12px;color:var(--text-dark);font-size:13px;font-family:'Inter',sans-serif;outline:none;width:100%;}
.fg input:focus,.fg select:focus{border-color:var(--accent);}
.fg .hint{font-size:11px;color:var(--text-dim);}
.pw-wrap{position:relative;}
.pw-wrap input{padding-right:40px;}
.pw-toggle{position:absolute;top:50%;right:10px;transform:translateY(-50%);background:none;border:none;color:var(--text-dim);cursor:pointer;padding:4px;font-size:14px;display:flex;align-items:center;}
.pw-toggle:hover{color:var(--text-dark);}
.modal-actions{display:flex;gap:10px;margin-top:20px;}
.btn-cancel{flex:1;padding:10px;background:var(--inner);border:1px solid var(--card-border);border-radius:var(--radius);color:var(--text-dark);font-size:14px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;}
.btn-cancel:hover{background:var(--card-border);}
.btn-submit{flex:2;padding:10px;background:var(--accent);border:none;border-radius:var(--radius);color:#fff;font-size:14px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;}
.btn-submit:hover{background:var(--hover);}
.btn-submit.green{background:var(--success);}
.btn-submit.green:hover{opacity:.88;}
.btn-confirm-del{flex:1;padding:10px;background:var(--danger);border:none;border-radius:var(--radius);color:#fff;font-size:14px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;}
.btn-confirm-del:hover{opacity:.88;}
.btn-confirm-approve{flex:1;padding:10px;background:var(--success);border:none;border-radius:var(--radius);color:#fff;font-size:14px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;}
.btn-confirm-approve:hover{opacity:.88;}

.yl-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:6px;}
.yl-check{display:flex;align-items:center;gap:8px;background:var(--inner);border:1px solid var(--card-border);border-radius:8px;padding:8px 10px;font-size:12.5px;cursor:pointer;color:var(--text-dark);}
.yl-check input{width:auto;}
.yl-group-label{font-size:10px;text-transform:uppercase;letter-spacing:.6px;color:var(--text-dim);margin:10px 0 6px;font-weight:700;}

.bulk-bar{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:var(--card-bg);border:1px solid var(--card-border);border-radius:12px;padding:12px 18px;display:none;align-items:center;gap:12px;box-shadow:0 24px 64px rgba(30,82,144,.11);z-index:150;flex-wrap:wrap;justify-content:center;}
.bulk-bar.show{display:flex;}
.bulk-bar span{font-size:13px;font-weight:600;color:var(--text-dark);white-space:nowrap;}
.bulk-bar select{background:var(--inner);border:1px solid var(--card-border);border-radius:8px;padding:8px 12px;color:var(--text-dark);font-size:13px;font-family:'Inter',sans-serif;}
.bulk-btn{padding:8px 16px;border:none;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;display:inline-flex;align-items:center;gap:6px;}
.bulk-btn.approve{background:var(--success);color:#F4F8FF;}
.bulk-btn.block{background:var(--danger);color:#fff;}
.bulk-btn.neutral{background:var(--accent);color:#fff;}
.bulk-btn.clear{background:transparent;border:1px solid var(--card-border);color:var(--text-dim);}
.rowCb:disabled{cursor:not-allowed;opacity:.35;}

@media(max-width:860px){body{padding:20px 14px;}}
@media(max-width:560px){.sector-tabs,.status-tabs{width:100%;}.sector-tab{flex:1;justify-content:center;padding:8px;font-size:12px;}.bulk-bar{width:calc(100% - 32px);}.yl-grid{grid-template-columns:1fr;}}

/* Admin Module light design system — matches the dashboard */
:root{
  --page-bg:#FFFFFF; --card-bg:#FFFFFF; --card-border:#D8E5F4;
  --inner:#F7FAFF; --text-dark:#0B1F3A; --text-dim:#67819E;
  --light:#0B1F3A; --muted:#67819E; --dark:#FFFFFF; --mid:#FFFFFF;
  --border:#D8E5F4; --accent:#2563EB; --blue:#2563EB;
  --gold:#C77A08; --gold-h:#D69612; --teal:#0E7490; --violet:#4968C8;
  --danger:#D6455D; --success:#0F9F6E; --radius:12px;
  --card-shadow:0 2px 4px rgba(30,82,144,.06),0 6px 16px rgba(30,82,144,.08);
}
html{background:#FFFFFF;color-scheme:light;}
body{background:#FFFFFF !important;color:#0B1F3A !important;}
a{color:inherit;}
.page-header h1,.page-title,.et-title,.section-title{color:#0B1F3A !important;}
.page-header p,.page-sub,.et-sub,.et-updated,.muted,.hint{color:#67819E !important;}
input,select,textarea{background:#fff !important;color:#0B1F3A !important;border-color:#B9CDE5 !important;}
button{font-family:inherit;}
.table-wrap,.content-panel,.create-panel,.period-card,.stat-card,.sector-card,.person-row,
.sum-card,.standing-panel,.eval-card,.eval-banner,.info-banner,.section,.shell .section,
.history-card,.gl-card,.amber-card,.green-card,.red-card{
  background:#fff !important;border-color:#D8E5F4 !important;box-shadow:0 2px 4px rgba(30,82,144,.05),0 6px 16px rgba(30,82,144,.06) !important;
}
.sector-tabs,.eval-switcher,.tabs,.level-tabs,.status-tabs{
  background:#fff !important;border-color:#D8E5F4 !important;box-shadow:0 2px 4px rgba(30,82,144,.05) !important;
}
.sector-tab,.eval-tab,.tab,.level-tab,.status-tab{color:#67819E !important;}
.sector-tab:hover,.eval-tab:hover,.tab:hover,.level-tab:hover,.status-tab:hover{color:#0B1F3A !important;background:#F7FAFF !important;}
thead tr{background:#F8FAFC !important;}
tbody tr:hover{background:#F8FAFC !important;}
.btn-cancel,.btn-icon,.btn-back{background:#fff !important;color:#0B1F3A !important;border-color:#B9CDE5 !important;}
.empty-state,.empty-cta{color:#67819E !important;}
::-webkit-scrollbar-track{background:#fff;}
::-webkit-scrollbar-thumb{background:#B9CDE5;border:2px solid #fff;}

body{padding:28px !important;}
.page-header{padding:22px 26px !important;}
.status-tab.active,.sector-tab.active{background:#2563EB !important;color:#fff !important;}
.status-tab.active[data-s="archived"]{background:#475569 !important;color:#fff !important;}
/* Separated tabs: each tab is its own bordered pill instead of sitting inside one shared bar */
.sector-tabs,.status-tabs{background:transparent !important;border:0 !important;box-shadow:none !important;padding:0 !important;gap:8px !important;}
.sector-tab,.status-tab{border:1px solid #D8E5F4 !important;border-radius:12px !important;padding:10px 18px !important;background:#fff !important;box-shadow:0 2px 4px rgba(30,82,144,.05) !important;}
.sector-tab:not(.active):hover,.status-tab:not(.active):hover{background:#F7FAFF !important;border-color:#B9CDE5 !important;}
.sector-tab.active,.status-tab.active{border-color:#2563EB !important;background:#2563EB !important;}
.status-tab.active[data-s="archived"]{border-color:#475569 !important;background:#475569 !important;}
.info-banner{background:#E6F0FF !important;color:#67819E !important;}


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
  border-color:#B9CDE5;
}
input::placeholder, textarea::placeholder { color:#91A6BE; }
.card, .panel, .section, .table-card, .content-card {
  border-color:#B9CDE5;
  box-shadow:0 4px 14px rgba(30,82,144,.09);
}
button, .btn { font-weight:700; }
a { color:inherit; }

/* Persistent role and registration-status icon coding */
.role-executive_assistant > i{color:#0F9E9A !important;}
.role-school_head > i{color:#C77A08 !important;}
.role-principal > i{color:#2563EB !important;}
.role-dean > i{color:#4968C8 !important;}
.role-teacher > i{color:#16A34A !important;}
.role-staff > i{color:#0F9E9A !important;}
.role-student > i{color:#2563EB !important;}
.status-pending > i{color:#C77A08 !important;}
.status-approved > i{color:#16A34A !important;}
.status-blocked > i{color:#EF4444 !important;}

</style>
    <link rel="stylesheet" href="admin_ui_theme.css">
    <link rel="stylesheet" href="admin_compact_ui.css">
<style id="pbi-feature-scrollbar">

/* PBI FEATURE SCROLLBAR — consistent with the compact page scrollbar */
html, body {
  scrollbar-width: thin !important;
  scrollbar-color: #888 transparent !important;
}
html::-webkit-scrollbar, body::-webkit-scrollbar,
.feature-compact ::-webkit-scrollbar {
  width: 10px !important;
  height: 10px !important;
}
html::-webkit-scrollbar-track, body::-webkit-scrollbar-track,
.feature-compact ::-webkit-scrollbar-track {
  background: transparent !important;
}
html::-webkit-scrollbar-thumb, body::-webkit-scrollbar-thumb,
.feature-compact ::-webkit-scrollbar-thumb {
  background: #888 !important;
  border-radius: 999px !important;
  border: 2px solid transparent !important;
  background-clip: padding-box !important;
}
html::-webkit-scrollbar-thumb:hover, body::-webkit-scrollbar-thumb:hover,
.feature-compact ::-webkit-scrollbar-thumb:hover {
  background: #777 !important;
  background-clip: padding-box !important;
}
html::-webkit-scrollbar-button, body::-webkit-scrollbar-button,
.feature-compact ::-webkit-scrollbar-button {
  display: block !important;
  width: 10px !important;
  height: 10px !important;
  background-color: transparent !important;
}
/* Small native-looking arrow hints on classic scrollbars */
html::-webkit-scrollbar-button:single-button:vertical:decrement,
body::-webkit-scrollbar-button:single-button:vertical:decrement,
.feature-compact ::-webkit-scrollbar-button:single-button:vertical:decrement {
  background:
    linear-gradient(135deg, transparent 50%, #777 50%) 3px 5px/5px 5px no-repeat !important;
}
html::-webkit-scrollbar-button:single-button:vertical:increment,
body::-webkit-scrollbar-button:single-button:vertical:increment,
.feature-compact ::-webkit-scrollbar-button:single-button:vertical:increment {
  background:
    linear-gradient(315deg, transparent 50%, #777 50%) 3px 0/5px 5px no-repeat !important;
}
html::-webkit-scrollbar-button:single-button:horizontal:decrement,
body::-webkit-scrollbar-button:single-button:horizontal:decrement,
.feature-compact ::-webkit-scrollbar-button:single-button:horizontal:decrement {
  background:
    linear-gradient(45deg, transparent 50%, #777 50%) 5px 3px/5px 5px no-repeat !important;
}
html::-webkit-scrollbar-button:single-button:horizontal:increment,
body::-webkit-scrollbar-button:single-button:horizontal:increment,
.feature-compact ::-webkit-scrollbar-button:single-button:horizontal:increment {
  background:
    linear-gradient(225deg, transparent 50%, #777 50%) 0 3px/5px 5px no-repeat !important;
}

</style>

<link rel="stylesheet" href="admin_appearance.css">
<script src="admin_appearance.js"></script>
<link rel="stylesheet" href="admin_unified_ui.css?v=<?= (int)@filemtime(__DIR__ . '/admin_unified_ui.css') ?>">
<style>
/* Separated tabs: strip the shared container the theme stylesheets add around the tab pills */
html body .sector-tabs,html body .status-tabs,
html[data-theme="dark"] body .sector-tabs,html[data-theme="dark"] body .status-tabs,
html.dark-theme body .sector-tabs,html.dark-theme body .status-tabs{background:transparent !important;border:0 !important;box-shadow:none !important;padding:0 !important;border-radius:0 !important;gap:8px !important;}
</style>
</head>
<body class="feature-compact">

<?php if ($toast): ?>
<div class="toast"><i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($toast) ?></div>
<?php endif; ?>
<?php if ($toast_error): ?>
<div class="toast error"><i class="fa-solid fa-circle-exclamation"></i><?= htmlspecialchars($toast_error) ?></div>
<?php endif; ?>




<div class="role-row">
<div class="sector-tabs">
    <?php foreach ($valid_roles as $r):
        $pendingN = $countGrid[$r]['pending']; ?>
    <a class="sector-tab role-<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?> <?= (!$viewArchived && $viewRole===$r)?'active':'' ?>" href="manage_privileged_accounts.php?role=<?= $r ?>&status=<?= $viewStatus ?>">
        <i class="fa-solid <?= $role_icons[$r] ?>"></i> <?= $role_labels[$r] ?>
    </a>
    <?php endforeach; ?>
</div>
    <!-- Archived sits opposite the Student tab (right side) -->
    <div class="status-tabs archive-tab-wrap">
        <a class="status-tab status-archived <?= $viewArchived ? 'active' : '' ?>" data-s="archived" href="manage_privileged_accounts.php?role=all&status=<?= htmlspecialchars($viewStatus, ENT_QUOTES, 'UTF-8') ?>&archived=1">
            <i class="fa-solid fa-box-archive"></i> Archived
            <span class="tab-badge"><?= $archivedTotal ?></span>
        </a>
    </div>
</div>

<?php $statusLinkRole = ($viewRole === 'all') ? 'teacher' : $viewRole; ?>
<div class="status-row">
    <div class="status-tabs">
        <?php foreach ($valid_statuses as $s): ?>
        <a class="status-tab status-<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?> <?= (!$viewArchived && $viewStatus===$s)?'active':'' ?>" data-s="<?= $s ?>" href="manage_privileged_accounts.php?role=<?= $statusLinkRole ?>&status=<?= $s ?>">
            <i class="fa-solid <?= $status_icons[$s] ?>"></i> <?= $status_labels[$s] ?>
            <?php if (!$viewArchived): ?><span class="tab-badge"><?= $viewCounts[$s] ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($viewArchived): ?>
<!-- ARCHIVE PANEL: a framed section inside the page with its own role filter tabs -->
<div class="archive-panel">
    <div class="archive-panel-head">
        <div>
            <div class="archive-panel-title"><i class="fa-solid fa-box-archive"></i> Archived Accounts</div>
            <div class="archive-panel-sub">Archived accounts can't log in and are hidden from the regular lists. Their evaluation records are preserved, and you can restore them any time.</div>
        </div>
        <div class="archive-panel-total"><span><?= $archivedTotal ?></span> total</div>
    </div>
    <div class="sector-tabs archive-subtabs">
        <a class="sector-tab role-all <?= $viewRole==='all'?'active':'' ?>" href="manage_privileged_accounts.php?role=all&status=<?= $viewStatus ?>&archived=1">
            <i class="fa-solid fa-layer-group"></i> All
            <span class="tab-badge"><?= $archivedTotal ?></span>
        </a>
        <?php foreach ($valid_roles as $r): ?>
        <a class="sector-tab role-<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?> <?= $viewRole===$r?'active':'' ?>" href="manage_privileged_accounts.php?role=<?= $r ?>&status=<?= $viewStatus ?>&archived=1">
            <i class="fa-solid <?= $role_icons[$r] ?>"></i> <?= $role_labels[$r] ?>
            <span class="tab-badge"><?= $archivedByRole[$r] ?></span>
        </a>
        <?php endforeach; ?>
    </div>
<?php else: ?>
<div class="stats-row">
    <div class="stat-card amber-card"><div class="stat-label">Pending</div><div class="stat-value amber"><?= $viewCounts['pending'] ?></div></div>
    <div class="stat-card green-card"><div class="stat-label">Approved</div><div class="stat-value green"><?= $viewCounts['approved'] ?></div></div>
    <div class="stat-card red-card"><div class="stat-label">Blocked</div><div class="stat-value red"><?= $viewCounts['blocked'] ?></div></div>
</div>
<?php endif; ?>

<?php if ($viewRole === 'student'): ?>
<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
    <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-dim);">Filter by School Level</label>
    <select id="levelFilterSelect" onchange="applyLevelFilter(this.value)" style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:8px;padding:8px 12px;color:var(--text-dark);font-size:13px;font-family:'Inter',sans-serif;">
        <option value="">All Students</option>
        <?php foreach ($school_levels as $key => $sl): ?>
        <option value="<?= $key ?>" <?= $levelFilter===$key?'selected':'' ?>><?= htmlspecialchars($sl['label']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($levelFilter !== ''): ?>
    <a href="manage_privileged_accounts.php?role=student&status=<?= $viewStatus ?><?= $viewArchived ? '&archived=1' : '' ?>" style="font-size:12px;color:var(--text-dim);text-decoration:none;">Clear</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($viewRole === 'teacher' || $viewRole === 'staff'): ?>
<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
    <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-dim);">Filter by Year Level</label>
    <select id="ylFilterSelect" onchange="applyYlFilter(this.value)" style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:8px;padding:8px 12px;color:var(--text-dark);font-size:13px;font-family:'Inter',sans-serif;">
        <option value="">All Year Levels</option>
        <optgroup label="Junior High School">
            <option value="Grade 7" <?= $ylFilter==='Grade 7'?'selected':'' ?>>Grade 7</option>
            <option value="Grade 8" <?= $ylFilter==='Grade 8'?'selected':'' ?>>Grade 8</option>
            <option value="Grade 9" <?= $ylFilter==='Grade 9'?'selected':'' ?>>Grade 9</option>
            <option value="Grade 10" <?= $ylFilter==='Grade 10'?'selected':'' ?>>Grade 10</option>
        </optgroup>
        <optgroup label="Senior High School">
            <option value="Grade 11" <?= $ylFilter==='Grade 11'?'selected':'' ?>>Grade 11</option>
            <option value="Grade 12" <?= $ylFilter==='Grade 12'?'selected':'' ?>>Grade 12</option>
        </optgroup>
        <optgroup label="College">
            <option value="1st Year College" <?= $ylFilter==='1st Year College'?'selected':'' ?>>1st Year</option>
            <option value="2nd Year College" <?= $ylFilter==='2nd Year College'?'selected':'' ?>>2nd Year</option>
            <option value="3rd Year College" <?= $ylFilter==='3rd Year College'?'selected':'' ?>>3rd Year</option>
            <option value="4th Year College" <?= $ylFilter==='4th Year College'?'selected':'' ?>>4th Year</option>
        </optgroup>
    </select>
    <?php if ($ylFilter !== ''): ?>
    <a href="manage_privileged_accounts.php?role=<?= $viewRole ?>&status=<?= $viewStatus ?>" style="font-size:12px;color:var(--text-dim);text-decoration:none;">Clear</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php $showDesig = in_array($viewRole, ['principal','dean','teacher','staff'], true); ?>
<div class="table-wrap">
<table>
    <thead>
        <tr>
            <th style="width:36px;"><input type="checkbox" id="selectAllCb" onchange="toggleSelectAll(this)" <?= $viewArchived ? 'disabled' : '' ?>/></th>
            <th>#</th><th>Name</th><th>Username</th>
            <?php if ($viewRole === 'all'): ?><th>Role</th><?php endif; ?>
            <?php if ($showDesig): ?><th>Designation</th><?php endif; ?>
            <?php if ($viewRole === 'student'): ?><th>Year Level</th><?php endif; ?>
            <?php if ($viewRole === 'teacher' || $viewRole === 'staff'): ?><th>Year Level(s)</th><th>Period</th><?php endif; ?>
            <th>Status</th><th>Registered</th><th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php
    $colspan = 7;
    if ($viewRole === 'all') $colspan = 8; // +1 for Role
    if ($viewRole === 'student') $colspan = 8;
    if ($viewRole === 'teacher' || $viewRole === 'staff') $colspan = 10; // +1 for Designation
    if ($viewRole === 'principal' || $viewRole === 'dean') $colspan = 8; // +1 for Designation
    ?>
    <?php if (empty($entries)): ?>
    <tr><td colspan="<?= $colspan ?>">
        <div class="empty-state">
            <i class="fa-solid fa-user-slash"></i>
            <p><?= $viewArchived ? ($viewRole === 'all' ? 'No archived accounts.' : 'No archived ' . strtolower($role_labels[$viewRole]) . ' accounts.') : ($levelFilter !== '' ? 'No '.$role_labels[$viewRole].' match that school level.' : ($ylFilter !== '' ? 'No '.$role_labels[$viewRole].' match that year level.' : 'No '.strtolower($status_labels[$viewStatus]).' '.$role_labels[$viewRole].' accounts.')) ?></p>
        </div>
    </td></tr>
    <?php else: foreach ($entries as $i => $u): ?>
    <tr id="row-<?= $u['id'] ?>">
        <td>
            <input type="checkbox" class="rowCb" value="<?= $u['id'] ?>" onchange="updateBulkBar()" <?= $viewArchived ? 'disabled' : '' ?>/>
        </td>
        <td style="color:var(--text-dim);font-size:13px;"><?= $i+1 ?></td>
        <td><div class="user-name"><?= htmlspecialchars($u['full_name']) ?></div></td>
        <td><span class="user-username">@<?= htmlspecialchars($u['username']) ?></span></td>
        <?php if ($viewRole === 'all'): ?>
        <td><span class="role-pill role-<?= htmlspecialchars($u['role'], ENT_QUOTES, 'UTF-8') ?>"><i class="fa-solid <?= $role_icons[$u['role']] ?? 'fa-user' ?>"></i> <?= htmlspecialchars($role_labels[$u['role']] ?? ucfirst($u['role'])) ?></span></td>
        <?php endif; ?>
        <?php if ($showDesig): ?>
        <?php $desig = trim((string)($u['designation'] ?? '')); ?>
        <td class="designation-cell"<?= $desig !== '' ? ' title="' . htmlspecialchars($desig) . '"' : '' ?>>
            <?php if ($desig !== ''): ?>
                <?= htmlspecialchars($desig) ?>
            <?php else: ?>
                <span style="color:var(--text-dim);font-size:12px;">—</span>
            <?php endif; ?>
        </td>
        <?php endif; ?>
        <?php if ($viewRole === 'student'): ?>
        <td>
            <?php if (!empty($u['year_level'])): ?>
            <span class="year-level-pill <?= level_pill_class($u['year_level']) ?>"><i class="fa-solid fa-layer-group" style="font-size:9px"></i> <?= htmlspecialchars(normalize_year_level($u['year_level'])) ?></span>
            <?php else: ?>
            <span style="color:var(--text-dim);font-size:12px;">—</span>
            <?php endif; ?>
        </td>
        <?php endif; ?>
        <?php if ($viewRole === 'teacher' || $viewRole === 'staff'): ?>
        <td>
            <?php if (!empty($u['year_levels'])): ?>
                <?php foreach ($u['year_levels'] as $yl): ?>
                <span class="year-level-pill <?= level_pill_class($yl) ?>" style="margin:2px 3px 2px 0;"><i class="fa-solid fa-layer-group" style="font-size:9px"></i> <?= htmlspecialchars($yl) ?></span>
                <?php endforeach; ?>
            <?php else: ?>
                <span style="color:var(--text-dim);font-size:12px;">Not assigned yet</span>
            <?php endif; ?>
        </td>
        <td>
            <?php if ($u['has_college']): ?>
                <?php if (empty($u['assigned_period'])): ?>
                <span class="year-level-pill" style="background:#3f1d1d;color:#fca5a5;" title="This person has a College year level but no period assigned yet — they will not appear to any student for evaluation until a period is set."><i class="fa-solid fa-triangle-exclamation" style="font-size:9px"></i> No period — won't show to students</span>
                <?php elseif ($active_period_semester !== null && trim((string)$u['assigned_period']) !== trim((string)$active_period_semester)): ?>
                <span class="year-level-pill" style="background:#3f2d0f;color:#fbbf24;" title="Assigned to <?= htmlspecialchars($u['assigned_period']) ?>, but the active evaluation period this term is <?= htmlspecialchars($active_period_semester) ?> — they will not appear to any student until the active period matches or their assignment is updated."><i class="fa-solid fa-triangle-exclamation" style="font-size:9px"></i> <?= htmlspecialchars($u['assigned_period']) ?> — not active this term</span>
                <?php else: ?>
                <span class="year-level-pill" style="background:var(--teal-bg);color:var(--teal);"><i class="fa-solid fa-calendar-days" style="font-size:9px"></i> <?= htmlspecialchars($u['assigned_period']) ?></span>
                <?php endif; ?>
            <?php else: ?>
                <span style="color:var(--text-dim);font-size:12px;">—</span>
            <?php endif; ?>
        </td>
        <?php endif; ?>
        <td>
            <span class="status-pill <?= $u['account_status'] ?>"><i class="fa-solid <?= $status_icons[$u['account_status']] ?>" style="font-size:9px"></i> <?= $status_labels[$u['account_status']] ?></span>
            <?php if ($u['account_status'] === 'approved'): ?>
                <span class="status-pill <?= $u['is_active'] ? 'active-sub' : 'inactive-sub' ?>" style="margin-top:4px;"><i class="fa-solid fa-circle" style="font-size:7px"></i> <?= $u['is_active'] ? 'Active' : 'Inactive' ?></span>
            <?php endif; ?>
        </td>
        <td style="font-size:13px;color:var(--text-dim);"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
        <td>
            <div class="action-wrap">
                <?php if ($viewArchived): ?>
                <button class="btn-icon" title="Restore Account" style="border-color:var(--success-border);color:var(--success);"
                        onclick='confirmArchive(<?= (int)$u["id"] ?>, <?= json_encode($u["full_name"], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>, true)'>
                    <i class="fa-solid fa-box-open"></i>
                </button>
                <?php else: ?>
                    <?php if ($u['account_status'] !== 'approved'): ?>
                    <button class="btn-icon approve" title="Approve" onclick='openConfirmModal("approve_account", [<?= $u["id"] ?>], <?= json_encode("Approve " . $u["full_name"] . "'s account? They will be able to log in immediately.", JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                        <i class="fa-solid fa-check"></i>
                    </button>
                    <?php endif; ?>
                    <?php if ($u['account_status'] !== 'blocked'): ?>
                    <button class="btn-icon block" title="Block" onclick='openConfirmModal("block_account", [<?= $u["id"] ?>], <?= json_encode("Block " . $u["full_name"] . "'s account? They will lose access immediately.", JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                        <i class="fa-solid fa-ban"></i>
                    </button>
                    <?php endif; ?>
                    <?php if ($viewRole === 'teacher' || $viewRole === 'staff'): ?>
                    <button class="btn-icon" title="Assign Year Levels" style="border-color:var(--accent-border);color:var(--accent);"
                            onclick='openYlModal(<?= json_encode(["id"=>$u["id"],"full_name"=>$u["full_name"],"year_levels"=>$u["year_levels"]], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>
                        <i class="fa-solid fa-layer-group"></i>
                    </button>
                    <?php if ($u['has_college']): ?>
                    <button class="btn-icon" title="Assign Period" style="border-color:var(--teal-border);color:var(--teal);"
                            onclick='openPeriodModal(<?= json_encode(["id"=>$u["id"],"full_name"=>$u["full_name"],"assigned_period"=>$u["assigned_period"]], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>
                        <i class="fa-solid fa-calendar-days"></i>
                    </button>
                    <?php endif; ?>
                    <?php endif; ?>
                    <span class="action-divider"></span>
                    <button class="btn-icon" title="Archive Account" style="border-color:var(--amber-border);color:var(--amber);"
                            onclick='confirmArchive(<?= (int)$u["id"] ?>, <?= json_encode($u["full_name"], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>, false)'>
                        <i class="fa-solid fa-box-archive"></i>
                    </button>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
<?php if ($viewArchived): ?>
</div><!-- /.archive-panel -->
<?php endif; ?>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editModal">
<div class="modal">
    <div class="modal-head">
        <div><div class="modal-title">Edit Account</div></div>
        <button class="modal-close" onclick="closeModal('editModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST" action="manage_privileged_accounts.php?role=<?= $viewRole ?>&status=<?= $viewStatus ?>">
        <input type="hidden" name="action" value="edit_account"/>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
        <input type="hidden" name="user_id" id="editUserId"/>
        <div class="fg"><label>Full Name</label><input type="text" name="full_name" id="editFullName" required/></div>
        <div class="fg" id="editYearLevelGroup" style="display:none;">
            <label>Year Level</label>
            <select name="year_level" id="editYearLevel">
                <option value="">Select year level</option>
                <optgroup label="Junior High School">
                    <option value="Grade 7">Grade 7</option>
                    <option value="Grade 8">Grade 8</option>
                    <option value="Grade 9">Grade 9</option>
                    <option value="Grade 10">Grade 10</option>
                </optgroup>
                <optgroup label="Senior High School">
                    <option value="Grade 11">Grade 11</option>
                    <option value="Grade 12">Grade 12</option>
                </optgroup>
                <optgroup label="College">
                    <option value="1st Year College">1st Year</option>
                    <option value="2nd Year College">2nd Year</option>
                    <option value="3rd Year College">3rd Year</option>
                    <option value="4th Year College">4th Year</option>
                </optgroup>
            </select>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button>
            <button type="submit" class="btn-submit"><i class="fa-solid fa-floppy-disk"></i> Save</button>
        </div>
    </form>
</div>
</div>

<!-- RESET PASSWORD MODAL -->
<div class="modal-overlay" id="resetModal">
<div class="modal">
    <div class="modal-head">
        <div><div class="modal-title">Reset Password</div><div class="modal-sub" id="resetSub"></div></div>
        <button class="modal-close" onclick="closeModal('resetModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST" action="manage_privileged_accounts.php?role=<?= $viewRole ?>&status=<?= $viewStatus ?>">
        <input type="hidden" name="action" value="reset_password"/>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
        <input type="hidden" name="user_id" id="resetUserId"/>
        <div class="fg"><label>New Password</label>
            <div class="pw-wrap">
                <input type="password" name="password" id="resetPassword" required minlength="8"/>
                <button type="button" class="pw-toggle" onclick="togglePw('resetPassword', this)"><i class="fa-solid fa-eye"></i></button>
            </div>
        </div>
        <div class="fg"><label>Confirm New Password</label>
            <div class="pw-wrap">
                <input type="password" name="confirm_password" id="resetConfirmPassword" required minlength="8"/>
                <button type="button" class="pw-toggle" onclick="togglePw('resetConfirmPassword', this)"><i class="fa-solid fa-eye"></i></button>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeModal('resetModal')">Cancel</button>
            <button type="submit" class="btn-submit"><i class="fa-solid fa-key"></i> Reset Password</button>
        </div>
    </form>
</div>
</div>

<!-- ASSIGN YEAR LEVELS MODAL (single user, Teacher/Staff) -->
<div class="modal-overlay" id="ylModal">
<div class="modal">
    <div class="modal-head">
        <div><div class="modal-title">Assign Year Levels</div><div class="modal-sub" id="ylSub"></div></div>
        <button class="modal-close" onclick="closeModal('ylModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST" action="manage_privileged_accounts.php?role=<?= $viewRole ?>&status=<?= $viewStatus ?><?= $ylFilter ? '&yl='.urlencode($ylFilter) : '' ?>">
        <input type="hidden" name="action" value="assign_year_levels"/>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
        <input type="hidden" name="user_id" id="ylUserId"/>
        <div class="fg">
            <label>Year Level(s) Responsible For / Teaching</label>
            <span class="hint">Check all that apply — an employee can be assigned multiple year levels.</span>
        </div>
        <div class="yl-group-label">Junior High School</div>
        <div class="yl-grid">
            <label class="yl-check"><input type="checkbox" name="year_levels[]" value="Grade 7" class="ylCheckbox"/> Grade 7</label>
            <label class="yl-check"><input type="checkbox" name="year_levels[]" value="Grade 8" class="ylCheckbox"/> Grade 8</label>
            <label class="yl-check"><input type="checkbox" name="year_levels[]" value="Grade 9" class="ylCheckbox"/> Grade 9</label>
            <label class="yl-check"><input type="checkbox" name="year_levels[]" value="Grade 10" class="ylCheckbox"/> Grade 10</label>
        </div>
        <div class="yl-group-label">Senior High School</div>
        <div class="yl-grid">
            <label class="yl-check"><input type="checkbox" name="year_levels[]" value="Grade 11" class="ylCheckbox"/> Grade 11</label>
            <label class="yl-check"><input type="checkbox" name="year_levels[]" value="Grade 12" class="ylCheckbox"/> Grade 12</label>
        </div>
        <div class="yl-group-label">College</div>
        <div class="yl-grid">
            <label class="yl-check"><input type="checkbox" name="year_levels[]" value="1st Year College" class="ylCheckbox"/> 1st Year</label>
            <label class="yl-check"><input type="checkbox" name="year_levels[]" value="2nd Year College" class="ylCheckbox"/> 2nd Year</label>
            <label class="yl-check"><input type="checkbox" name="year_levels[]" value="3rd Year College" class="ylCheckbox"/> 3rd Year</label>
            <label class="yl-check"><input type="checkbox" name="year_levels[]" value="4th Year College" class="ylCheckbox"/> 4th Year</label>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeModal('ylModal')">Cancel</button>
            <button type="submit" class="btn-submit"><i class="fa-solid fa-layer-group"></i> Save</button>
        </div>
    </form>
</div>
</div>

<!-- ASSIGN PERIOD MODAL (College Teacher/Staff only, single user) -->
<div class="modal-overlay" id="periodModal">
<div class="modal">
    <div class="modal-head">
        <div><div class="modal-title">Assign Period</div><div class="modal-sub" id="periodSub"></div></div>
        <button class="modal-close" onclick="closeModal('periodModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST" action="manage_privileged_accounts.php?role=<?= $viewRole ?>&status=<?= $viewStatus ?><?= $ylFilter ? '&yl='.urlencode($ylFilter) : '' ?>">
        <input type="hidden" name="action" value="assign_period"/>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
        <input type="hidden" name="user_id" id="periodUserId"/>
        <div class="fg">
            <label>Period</label>
            <select name="period" id="periodSelect">
                <option value="">— Unassigned —</option>
                <?php foreach ($period_options as $p): ?>
                <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="hint">College only — this person has at least one College year level assigned</span>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeModal('periodModal')">Cancel</button>
            <button type="submit" class="btn-submit"><i class="fa-solid fa-calendar-days"></i> Save</button>
        </div>
    </form>
</div>
</div>

<!-- CONFIRM MODAL (single or bulk approve / block) -->
<div class="modal-overlay" id="confirmModal">
<div class="modal" style="max-width:400px;">
    <div class="modal-title" id="confirmTitle" style="margin-bottom:8px;">Confirm</div>
    <p id="confirmText" style="font-size:13px;color:var(--text-dim);margin-bottom:20px;line-height:1.6;"></p>
    <form method="POST" id="confirmForm" action="manage_privileged_accounts.php?role=<?= $viewRole ?>&status=<?= $viewStatus ?><?= $ylFilter ? '&yl='.urlencode($ylFilter) : '' ?><?= $levelFilter ? '&level='.urlencode($levelFilter) : '' ?>">
        <input type="hidden" name="action" id="confirmActionInput"/>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
        <input type="hidden" name="user_id" id="confirmSingleUserId"/>
        <div id="confirmBulkIdsContainer"></div>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeModal('confirmModal')">Cancel</button>
            <button type="submit" class="btn-confirm-approve" id="confirmSubmitBtn">Yes, Confirm</button>
        </div>
    </form>
</div>
</div>

<!-- STUDENT ARCHIVE / RESTORE MODAL -->
<div class="modal-overlay" id="archiveModal">
<div class="modal" style="max-width:400px;">
    <div class="modal-title" id="archiveModalTitle" style="margin-bottom:8px;">Archive Account</div>
    <p id="archiveSubText" style="font-size:13px;color:var(--text-dim);margin-bottom:20px;line-height:1.6;"></p>
    <div class="modal-actions">
        <button class="btn-cancel" onclick="closeModal('archiveModal')">Cancel</button>
        <button class="btn-confirm-del" id="archiveSubmitBtn" onclick="doArchive()">Yes, Archive</button>
    </div>
</div>
</div>
<form method="POST" id="archiveForm" action="manage_privileged_accounts.php?role=<?= $viewRole ?>&status=<?= $viewStatus ?><?= $viewArchived ? '&archived=1' : '' ?>" style="display:none;">
    <input type="hidden" name="action" id="archiveActionInput" value="archive_account"/>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
    <input type="hidden" name="archive_id" id="archiveIdInput"/>
</form>

<!-- BULK ACTION BAR -->
<?php if (!$viewArchived): ?>
<div class="bulk-bar" id="bulkBar">
    <span id="bulkCount">0 selected</span>
    <?php if ($viewStatus !== 'approved'): ?>
    <button class="bulk-btn approve" onclick="bulkConfirm('bulk_approve')"><i class="fa-solid fa-check"></i> Approve Selected</button>
    <?php endif; ?>
    <?php if ($viewStatus !== 'blocked'): ?>
    <button class="bulk-btn block" onclick="bulkConfirm('bulk_block')"><i class="fa-solid fa-ban"></i> Block Selected</button>
    <?php endif; ?>
    <?php if ($viewRole === 'teacher' || $viewRole === 'staff'): ?>
    <button class="bulk-btn neutral" onclick="openBulkYlModal()"><i class="fa-solid fa-layer-group"></i> Assign Year Levels</button>
    <select id="bulkPeriodSelect">
        <option value="">— Period: Unassigned —</option>
        <?php foreach ($period_options as $p): ?>
        <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="bulk-btn neutral" onclick="submitBulkPeriod()"><i class="fa-solid fa-calendar-days"></i> Assign Period</button>
    <?php endif; ?>
    <button class="bulk-btn clear" onclick="clearSelection()">Clear</button>
</div>
<?php endif; ?>

<form method="POST" id="bulkPeriodForm" action="manage_privileged_accounts.php?role=<?= $viewRole ?>&status=<?= $viewStatus ?><?= $ylFilter ? '&yl='.urlencode($ylFilter) : '' ?>" style="display:none;">
    <input type="hidden" name="action" value="bulk_assign_period"/>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
    <input type="hidden" name="period" id="bulkPeriodInput"/>
    <div id="bulkPeriodUserIdsContainer"></div>
</form>

<!-- BULK ASSIGN YEAR LEVELS MODAL -->
<div class="modal-overlay" id="bulkYlModal">
<div class="modal">
    <div class="modal-head">
        <div><div class="modal-title">Assign Year Levels</div><div class="modal-sub" id="bulkYlSub"></div></div>
        <button class="modal-close" onclick="closeModal('bulkYlModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST" id="bulkYlForm" action="manage_privileged_accounts.php?role=<?= $viewRole ?>&status=<?= $viewStatus ?><?= $ylFilter ? '&yl='.urlencode($ylFilter) : '' ?>">
        <input type="hidden" name="action" value="bulk_assign_year_levels"/>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"/>
        <div id="bulkYlUserIdsContainer"></div>
        <div class="fg">
            <label>Year Level(s)</label>
            <span class="hint">This replaces the current assignment for everyone selected.</span>
        </div>
        <div class="yl-group-label">Junior High School</div>
        <div class="yl-grid">
            <label class="yl-check"><input type="checkbox" name="year_levels[]" value="Grade 7"/> Grade 7</label>
            <label class="yl-check"><input type="checkbox" name="year_levels[]" value="Grade 8"/> Grade 8</label>
            <label class="yl-check"><input type="checkbox" name="year_levels[]" value="Grade 9"/> Grade 9</label>
            <label class="yl-check"><input type="checkbox" name="year_levels[]" value="Grade 10"/> Grade 10</label>
        </div>
        <div class="yl-group-label">Senior High School</div>
        <div class="yl-grid">
            <label class="yl-check"><input type="checkbox" name="year_levels[]" value="Grade 11"/> Grade 11</label>
            <label class="yl-check"><input type="checkbox" name="year_levels[]" value="Grade 12"/> Grade 12</label>
        </div>
        <div class="yl-group-label">College</div>
        <div class="yl-grid">
            <label class="yl-check"><input type="checkbox" name="year_levels[]" value="1st Year College"/> 1st Year</label>
            <label class="yl-check"><input type="checkbox" name="year_levels[]" value="2nd Year College"/> 2nd Year</label>
            <label class="yl-check"><input type="checkbox" name="year_levels[]" value="3rd Year College"/> 3rd Year</label>
            <label class="yl-check"><input type="checkbox" name="year_levels[]" value="4th Year College"/> 4th Year</label>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeModal('bulkYlModal')">Cancel</button>
            <button type="submit" class="btn-submit"><i class="fa-solid fa-layer-group"></i> Apply to Selected</button>
        </div>
    </form>
</div>
</div>

<script>
let _archiveId = null;
// ── Full-page dim ────────────────────────────────────────────────
// This page runs inside the dashboard's iframe. While a modal is open we ask
// the dashboard (admin_dashboard.php -> pbiFrameDim) to draw ONE plain dim over
// the whole window — sidebar, top bar, this page and its scrollbar — leaving
// only the modal card undimmed, exactly like the logout dialog. This page's
// own overlay then goes transparent. Opened on its own (no dashboard), nothing
// changes and the normal overlay dim is used.
function pbiModalRect(){
    const m = document.querySelector('.modal-overlay.open .modal');
    if (!m) return null;
    const r = m.getBoundingClientRect();
    return {x:r.left, y:r.top, w:r.width, h:r.height, r:parseFloat(getComputedStyle(m).borderTopLeftRadius) || 0};
}
function pbiCloseAllModals(){
    document.querySelectorAll('.modal-overlay.open').forEach(el => closeModal(el.id));
}
function pbiParentDim(on){
    try {
        if (window.parent !== window && typeof window.parent.pbiFrameDim === 'function') {
            window.parent.pbiFrameDim(on, window.frameElement, on ? pbiModalRect() : null, pbiCloseAllModals);
            document.documentElement.classList.toggle('pbi-parent-dims', !!on);
        }
    } catch (e) { /* cross-origin or no parent */ }
}
// Form submits reload the iframe; never leave the dashboard dimmed behind.
window.addEventListener('pagehide', () => pbiParentDim(false));
window.addEventListener('resize', () => { if (document.querySelector('.modal-overlay.open')) pbiParentDim(true); });
pbiParentDim(false);

function openModal(id){document.getElementById(id).classList.add('open'); pbiParentDim(true);}
function closeModal(id){
    document.getElementById(id).classList.remove('open');
    if (!document.querySelector('.modal-overlay.open')) pbiParentDim(false);
}
document.querySelectorAll('.modal-overlay').forEach(el=>el.addEventListener('click',e=>{if(e.target===el)closeModal(el.id);}));

function openEditModal(u){
    document.getElementById('editUserId').value=u.id;
    document.getElementById('editFullName').value=u.full_name;
    const ylGroup = document.getElementById('editYearLevelGroup');
    if (u.role === 'student') {
        ylGroup.style.display = 'block';
        document.getElementById('editYearLevel').value = u.year_level || '';
    } else {
        ylGroup.style.display = 'none';
    }
    openModal('editModal');
}

function openResetModal(u){
    document.getElementById('resetUserId').value=u.id;
    document.getElementById('resetSub').textContent='For: '+u.full_name;
    openModal('resetModal');
}

function openYlModal(u){
    document.getElementById('ylUserId').value=u.id;
    document.getElementById('ylSub').textContent='For: '+u.full_name;
    document.querySelectorAll('#ylModal .ylCheckbox').forEach(cb => {
        cb.checked = (u.year_levels || []).includes(cb.value);
    });
    openModal('ylModal');
}

function openPeriodModal(u){
    document.getElementById('periodUserId').value=u.id;
    document.getElementById('periodSub').textContent='For: '+u.full_name;
    document.getElementById('periodSelect').value=u.assigned_period || '';
    openModal('periodModal');
}

// ── SINGLE / BULK approve & block confirmation ─────────────────
function openConfirmModal(action, ids, message){
    const titles = {approve_account:'Approve Account', bulk_approve:'Approve Accounts', block_account:'Block Account', bulk_block:'Block Accounts'};
    document.getElementById('confirmTitle').textContent = titles[action] || 'Confirm';
    document.getElementById('confirmText').textContent = message;
    document.getElementById('confirmActionInput').value = action;

    const btn = document.getElementById('confirmSubmitBtn');
    const btnStyles = {
        approve_account:['btn-confirm-approve','Yes, Approve'], bulk_approve:['btn-confirm-approve','Yes, Approve'],
        block_account:['btn-confirm-del','Yes, Block'], bulk_block:['btn-confirm-del','Yes, Block']
    };
    const [cls, label] = btnStyles[action] || ['btn-confirm-approve','Yes, Confirm'];
    btn.className = cls;
    btn.textContent = label;

    const singleField = document.getElementById('confirmSingleUserId');
    const bulkContainer = document.getElementById('confirmBulkIdsContainer');
    bulkContainer.innerHTML = '';
    singleField.value = '';

    if (action === 'approve_account' || action === 'block_account') {
        singleField.value = ids[0];
    } else {
        ids.forEach(id => {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'user_ids[]'; inp.value = id;
            bulkContainer.appendChild(inp);
        });
    }
    openModal('confirmModal');
}

function bulkConfirm(action){
    const ids = getSelectedIds();
    if (ids.length === 0) return;
    const verb = action === 'bulk_approve' ? 'approve' : 'block';
    const consequence = action === 'bulk_approve' ? 'They will be able to log in immediately.' : 'They will lose access immediately.';
    openConfirmModal(action, ids, `Are you sure you want to ${verb} these ${ids.length} user account(s)? ${consequence}`);
}

function applyYlFilter(val){
    const url = new URL(window.location.href);
    if (val) url.searchParams.set('yl', val); else url.searchParams.delete('yl');
    window.location.href = url.toString();
}

function applyLevelFilter(val){
    const url = new URL(window.location.href);
    if (val) url.searchParams.set('level', val); else url.searchParams.delete('level');
    window.location.href = url.toString();
}

function confirmArchive(id, name, restore = false){
    _archiveId = id;
    document.getElementById('archiveModalTitle').textContent = restore ? 'Restore Account' : 'Archive Account';
    document.getElementById('archiveSubText').textContent = restore
        ? `Restore "${name}" from the archive? The account will return to its original approval-status tab.`
        : `Archive "${name}"? This removes the account from the regular status tabs and disables access, while preserving account details and evaluation records.`;
    document.getElementById('archiveActionInput').value = restore ? 'restore_account' : 'archive_account';
    const submit = document.getElementById('archiveSubmitBtn');
    submit.textContent = restore ? 'Yes, Restore' : 'Yes, Archive';
    submit.className = restore ? 'btn-confirm-approve' : 'btn-confirm-del';
    openModal('archiveModal');
}
function doArchive(){
    if (_archiveId === null) return;
    document.getElementById('archiveIdInput').value = _archiveId;
    document.getElementById('archiveForm').submit();
}

function togglePw(inputId, btn){
    const input = document.getElementById(inputId);
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye');
    }
}

// ── BULK SELECT ───────────────────────────────────────────────
function getRowCbs(){ return Array.from(document.querySelectorAll('.rowCb')); }
function getSelectedIds(){ return getRowCbs().filter(cb => cb.checked).map(cb => cb.value); }

function toggleSelectAll(cb){
    getRowCbs().forEach(el => { el.checked = cb.checked; syncRowHighlight(el); });
    updateBulkBar();
}
function syncRowHighlight(cb){
    const row = cb.closest('tr');
    if (row) row.classList.toggle('row-selected', cb.checked);
}
document.querySelectorAll('.rowCb').forEach(cb => cb.addEventListener('change', () => syncRowHighlight(cb)));

function updateBulkBar(){
    const bar = document.getElementById('bulkBar');
    const ids = getSelectedIds();
    if (ids.length > 0) {
        bar.classList.add('show');
        document.getElementById('bulkCount').textContent = ids.length + ' selected';
    } else {
        bar.classList.remove('show');
    }
    const all = getRowCbs();
    const selectAllCb = document.getElementById('selectAllCb');
    if (selectAllCb && all.length > 0) {
        selectAllCb.checked = all.every(cb => cb.checked);
    }
}
function clearSelection(){
    getRowCbs().forEach(cb => { cb.checked = false; syncRowHighlight(cb); });
    const selectAllCb = document.getElementById('selectAllCb');
    if (selectAllCb) selectAllCb.checked = false;
    updateBulkBar();
}

function submitBulkPeriod(){
    const ids = getSelectedIds();
    if (ids.length === 0) return;
    const period = document.getElementById('bulkPeriodSelect').value;
    document.getElementById('bulkPeriodInput').value = period;
    const container = document.getElementById('bulkPeriodUserIdsContainer');
    container.innerHTML = '';
    ids.forEach(id => {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'user_ids[]'; inp.value = id;
        container.appendChild(inp);
    });
    document.getElementById('bulkPeriodForm').submit();
}

function openBulkYlModal(){
    const ids = getSelectedIds();
    if (ids.length === 0) return;
    document.getElementById('bulkYlSub').textContent = `Applies to ${ids.length} selected account(s)`;
    const container = document.getElementById('bulkYlUserIdsContainer');
    container.innerHTML = '';
    ids.forEach(id => {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'user_ids[]'; inp.value = id;
        container.appendChild(inp);
    });
    document.querySelectorAll('#bulkYlForm input[type=checkbox]').forEach(cb => cb.checked = false);
    openModal('bulkYlModal');
}

// Deep-link support: when arriving here via ?highlight=<user_id> (e.g. from
// the dashboard's notification bell), scroll that row into view and flash it
// so it's obvious which account the notification was about.
(function () {
    const params = new URLSearchParams(window.location.search);
    const highlightId = params.get('highlight');
    if (!highlightId) return;
    const row = document.getElementById('row-' + highlightId);
    if (!row) return;
    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    row.classList.add('row-highlight');
    row.addEventListener('animationend', () => row.classList.remove('row-highlight'), { once: true });
})();
</script>

<?php $mysqli->close(); ?>
</body>
</html>