<?php
// Dean Reports & Analytics — based on the EA Reports & Analytics workflow, with Dean scope/theme
session_start();
require_once 'db.php';
require_once '../shared/EvaluationContextService.php';
require_once '../shared/system_settings_service.php';

// ── CURRENT-QUESTIONNAIRE FILTER ─────────────────────────────
// Answers saved under older question banks (before the generalized-
// questionnaire migration copied them to new IDs) made a 17-question
// questionnaire show as 29 rows. Matching is on question TEXT so a legacy
// answer to a question that still exists is kept; answers to questions no
// longer in the questionnaire are left out of the aggregates. Answers whose
// text can't be resolved are kept, and if a person has no current questions
// nothing is filtered, so a report never goes blank. No data is modified.
if (!function_exists('rp_current_q_core')) {
    function rp_current_q_core(string $textExpr, string $srcExpr, string $uid): string {
        $q = "CONVERT($textExpr USING utf8mb4) COLLATE utf8mb4_unicode_ci";
        $isFaculty = "EXISTS (SELECT 1 FROM users tux WHERE tux.id=$uid
                       AND (tux.role IN ('teacher','faculty') OR tux.secondary_role='teacher'))";
        $facBank = "SELECT CONVERT(fqx.question_text USING utf8mb4) COLLATE utf8mb4_unicode_ci FROM evaluation_questions fqx
                     WHERE fqx.target_type='Faculty' AND fqx.eval_type='general'
                       AND fqx.evaluator_role='shared' AND fqx.is_active=1";
        $personBank = "SELECT CONVERT(pqx.question_text USING utf8mb4) COLLATE utf8mb4_unicode_ci FROM user_questions pqx
                        WHERE pqx.user_id=$uid AND pqx.eval_type='general'";
        return "(
            $textExpr IS NULL
            OR ($isFaculty AND $q IN ($facBank))
            OR ($srcExpr='evaluation' AND $q IN ($facBank))
            OR $q IN ($personBank)
            OR (NOT ($isFaculty AND EXISTS ($facBank)) AND NOT EXISTS ($personBank))
        )";
    }
}
// $qa = alias of questionnaire_answers; $uid = SQL expr / int of the evaluated person's users.id
if (!function_exists('rp_current_q_sql')) {
    function rp_current_q_sql(string $qa, $uid): string {
        $text = "COALESCE(
            (SELECT eqx.question_text FROM evaluation_questions eqx
              WHERE $qa.question_source='evaluation' AND eqx.id=$qa.question_id LIMIT 1),
            (SELECT uqx.question_text FROM user_questions uqx
              WHERE $qa.question_source='user' AND uqx.id=COALESCE($qa.user_question_id, $qa.question_id) LIMIT 1))";
        return rp_current_q_core($text, "$qa.question_source", (string)$uid);
    }
}
// Merge rows that share the same wording (old + new IDs) with a response-weighted average.
if (!function_exists('rp_merge_question_rows')) {
    function rp_merge_question_rows(array $rows): array {
        usort($rows, fn($a, $b) => (int)$b['q_id'] <=> (int)$a['q_id']);
        $merged = [];
        foreach ($rows as $r) {
            $key = mb_strtolower(trim((string)$r['question_text']));
            $n = (int)$r['total_responses'];
            $sum = (float)$r['avg_score'] * $n;
            if (!isset($merged[$key])) { $merged[$key] = $r; $merged[$key]['_n'] = $n; $merged[$key]['_sum'] = $sum; }
            else { $merged[$key]['_n'] += $n; $merged[$key]['_sum'] += $sum; }
        }
        $out = [];
        foreach ($merged as $r) {
            $r['total_responses'] = $r['_n'];
            $r['avg_score'] = $r['_n'] > 0 ? round($r['_sum'] / $r['_n'], 2) : null;
            unset($r['_n'], $r['_sum']);
            $out[] = $r;
        }
        usort($out, fn($a, $b) => [$a['category'], (int)$a['q_id']] <=> [$b['category'], (int)$b['q_id']]);
        return $out;
    }
}

// ── AUTH GUARD ───────────────────────────────────────────────
// Evaluation scores and archive/restore actions are sensitive —
// require an authenticated admin-level session before anything else runs.
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['dean'])) {
    header("Location: dean_login.php");
    exit;
}

// ── EXECUTIVE PROFILE (shared portal layout) ─────────────────
$stmt = $mysqli->prepare("SELECT full_name, username, email, designation, photo, department FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();
$photo_src = !empty($me['photo']) ? '../image/' . $me['photo'] : '../image/pbi_logo';

// ── ENSURE TABLES EXIST (once per session) ───────────────────
if (empty($_SESSION['dean_reports_schema_v1'])) {
// evaluation_tracker already has student evals; add eval_type column if missing
$col = $mysqli->query("SHOW COLUMNS FROM evaluation_tracker LIKE 'eval_type'");
if ($col && $col->num_rows === 0) {
    $mysqli->query("ALTER TABLE evaluation_tracker ADD COLUMN eval_type ENUM('student','peer') NOT NULL DEFAULT 'student' AFTER remarks");
    $mysqli->query("ALTER TABLE evaluation_tracker ADD INDEX idx_eval_type (eval_type)");
}

// evaluator_id column — for peer evals this is the teacher/staff doing the rating
// (for student evals this mirrors student_id; we add it only if missing)
$col2 = $mysqli->query("SHOW COLUMNS FROM evaluation_tracker LIKE 'evaluator_id'");
if ($col2 && $col2->num_rows === 0) {
    $mysqli->query("ALTER TABLE evaluation_tracker ADD COLUMN evaluator_id INT UNSIGNED NULL DEFAULT NULL AFTER eval_type");
    $mysqli->query("ALTER TABLE evaluation_tracker ADD INDEX id_evaluator (evaluator_id)");
}

// Student evaluator school-level metadata — kept in sync with the EA analytics
// model so the Dean can apply the same Student Evaluation logic, but only to
// College evaluators for the Higher Education report.
$elCol = $mysqli->query("SHOW COLUMNS FROM users LIKE 'education_level'");
if ($elCol && $elCol->num_rows === 0) {
    $mysqli->query("ALTER TABLE users ADD COLUMN education_level ENUM('basic_ed','higher_ed') NULL DEFAULT NULL AFTER role");
    $mysqli->query("ALTER TABLE users ADD INDEX idx_education_level (education_level)");
}
$ylCol = $mysqli->query("SHOW COLUMNS FROM users LIKE 'year_level'");
if ($ylCol && $ylCol->num_rows === 0) {
    $mysqli->query("ALTER TABLE users ADD COLUMN year_level VARCHAR(30) NULL DEFAULT NULL AFTER education_level");
    $mysqli->query("ALTER TABLE users ADD INDEX idx_year_level (year_level)");
}

// Evaluation context keeps separate questionnaires/analytics for the same
// person when they can be evaluated as Teacher, Staff, or Multi-Role.
$ctxCol = $mysqli->query("SHOW COLUMNS FROM evaluation_tracker LIKE 'evaluation_context'");
if ($ctxCol && $ctxCol->num_rows === 0) {
    $mysqli->query("ALTER TABLE evaluation_tracker ADD COLUMN evaluation_context VARCHAR(30) NOT NULL DEFAULT 'teacher' AFTER period_id");
    $mysqli->query("ALTER TABLE evaluation_tracker ADD INDEX idx_eval_context (evaluation_context)");
    $mysqli->query("UPDATE evaluation_tracker et JOIN users u ON u.id=et.target_user_id SET et.evaluation_context=CASE WHEN u.role IN ('principal','dean') THEN 'school_head' WHEN u.role='staff' THEN 'staff' WHEN u.role='teacher' THEN 'teacher' ELSE et.evaluation_context END WHERE et.evaluation_context='teacher'");
}

$_SESSION['dean_reports_schema_v1'] = 1;
}

// Portal scope: Reports & Analytics is a pixel/function clone of the EA page,
// but its dataset is restricted to the current executive's education division.
$reportScopeSql = "(u.role='principal' OR u.role='staff' OR (u.role IN ('teacher','faculty') AND EXISTS (SELECT 1 FROM user_year_levels scope_uyl WHERE scope_uyl.user_id=u.id AND (LOWER(TRIM(scope_uyl.year_level)) LIKE '%college%' OR LOWER(TRIM(scope_uyl.year_level)) REGEXP '^(1st|2nd|3rd|4th)[[:space:]]*year([[:space:]]|$)'))))";
// Student evaluators are independently restricted below to College. Keep the
// portal's Higher Education scope explicit here as well for any summary queries.
// ── STAFF EVALUATION (non-teaching staff evaluating the Executive Assistant) ──
// The EA account role(s). Adjust this list if your EA accounts use a different users.role value.
// The Dean's Evaluate Others page identifies the active Executive Assistant
// as an approved superadmin account. Include that role here or Staff Evaluation
// will filter out the real target before it reaches the report.
$eaRoles = ['ea','executive_assistant','admin','superadmin'];
$eaRolesSql = implode(',', array_map(static fn($r) => "'" . addslashes($r) . "'", $eaRoles));
$eaScopeSql = "(u.role IN ($eaRolesSql) AND u.is_active=1 AND u.account_status='approved')";
$reportStudentScopeSql = "(education_level='higher_ed' OR year_level IN ('1st Year College','2nd Year College','3rd Year College','4th Year College'))";

$toast = $_SESSION['toast'] ?? ''; unset($_SESSION['toast']);

// ── ACTIVE EVAL TYPE ──────────────────────────────────────────
// Multi-Role is a filter inside Student Evaluation, not a separate
// top-level evaluation type. Legacy multi_role links are redirected into
// Student Evaluation with the Multi-Role filter selected.
$requestedEvalType = $_GET['eval_type'] ?? 'student';
$legacyMultiRoleLink = ($requestedEvalType === 'multi_role');
// School Head Evaluation belongs to the EA analytics view, not the Dean portal.
// Redirect any legacy/deep link to the normal Student Evaluation view instead of
// allowing an unsupported school-head mode to be activated.
$legacySchoolHeadLink = ($requestedEvalType === 'schoolhead' || $requestedEvalType === 'school_head');
$activeEval = ($legacyMultiRoleLink || $legacySchoolHeadLink) ? 'student' : $requestedEvalType;
if (!in_array($activeEval, ['student','peer','staff'], true)) $activeEval = 'student';

$groupFilter = $_GET['group'] ?? 'All';
$allowedGroups = $activeEval === 'student'
    ? ['All','Faculty','Staff']
    : ($activeEval === 'peer' ? ['Faculty','Staff'] : ['All','Faculty','Teacher']);
if (!in_array($groupFilter, $allowedGroups, true)) $groupFilter = $activeEval === 'peer' ? 'Faculty' : 'All';
if ($activeEval === 'staff') $groupFilter = 'All';
// Scope used for the people being evaluated: Staff Evaluation targets the EA only.
$viewScopeSql = $activeEval === 'staff' ? $eaScopeSql : $reportScopeSql;
$groupForOtherTabs = in_array($groupFilter, ['Faculty','Teacher','Staff'], true) ? $groupFilter : 'All';

$settings = get_system_settings($mysqli);

// ── HIGHER-ED PERIOD RESOLUTION ──────────────────────────────
// The Dean portal is Higher Ed only. The active period in system settings is
// flipped by the EA between Higher Ed (semester) and Basic Ed (School Year), so
// the Dean pages must not follow it blindly: they read ALL Higher Ed periods so
// Faculty and Staff records stay visible whatever structure is active.
// See dean_period_resolver.php.
require_once __DIR__ . '/dean_period_resolver.php';
$periodIds      = dean_higher_ed_period_ids($mysqli, $settings);
$period_id_int  = $periodIds ? (int)max($periodIds) : 0;   // newest Higher Ed period
$periodSql      = dean_period_sql($periodIds, 'et.period_id');
$periodPlainSql = dean_period_sql($periodIds, 'period_id');

$sqlQuote = static function (string $value) use ($mysqli): string {
    return "'" . $mysqli->real_escape_string($value) . "'";
};
$studentTypeSql = $sqlQuote('student');
$peerTypesSql = implode(',', array_map($sqlQuote, ['peer','faculty_peer','staff_peer']));
$teacherContextSql = $sqlQuote('teacher');
$staffContextSql = $sqlQuote('staff');

// IMPORTANT: Dean Student Evaluation is Higher Education only.
// Match the EA Student Evaluation evaluator model (evaluation_tracker.evaluator_id)
// while excluding JHS/SHS student submissions. A student is treated as College
// when the canonical education_level says higher_ed OR their assigned year_level
// is one of the four College levels. The year_level fallback also keeps legacy
// rows visible when education_level was not populated.
$collegeLevelsSql = "'1st Year College','2nd Year College','3rd Year College','4th Year College'";
$collegeStudentEvaluatorSql = "et.evaluator_id IN (
    SELECT id FROM users
    WHERE role='student'
      AND (education_level='higher_ed' OR year_level IN ($collegeLevelsSql))
)";
$collegeStudentEvaluatorPlainSql = "evaluator_id IN (
    SELECT id FROM users
    WHERE role='student'
      AND (education_level='higher_ed' OR year_level IN ($collegeLevelsSql))
)";

// Match the Dean Evaluate Others gate: a level is College if its label contains
// "College" or starts with 1st/2nd/3rd/4th Year. Exact IN() matching missed valid
// legacy labels such as "1st Year" and could hide valid peer submissions.
$collegeTeacherYearFilterSql = "(LOWER(TRIM(ctyl.year_level)) LIKE '%college%'
    OR LOWER(TRIM(ctyl.year_level)) REGEXP '^(1st|2nd|3rd|4th)[[:space:]]*year([[:space:]]|$)')";

// Build evaluator-ID expressions from columns that actually exist in this
// installation. Some schemas only have evaluator_id; never emit SQL referring
// to a missing student_id column.
$trackerColumns = [];
$trackerColumnsRes = $mysqli->query("SHOW COLUMNS FROM evaluation_tracker");
if ($trackerColumnsRes) {
    while ($trackerColumn = $trackerColumnsRes->fetch_assoc()) {
        $trackerColumns[] = (string)$trackerColumn['Field'];
    }
    $trackerColumnsRes->free();
}
$trackerEvaluatorPartsSql = [];
$trackerEvaluatorPartsPlainSql = [];
if (in_array('evaluator_id', $trackerColumns, true)) {
    $trackerEvaluatorPartsSql[] = 'NULLIF(et.evaluator_id,0)';
    $trackerEvaluatorPartsPlainSql[] = 'NULLIF(evaluator_id,0)';
}
if (in_array('student_id', $trackerColumns, true)) {
    $trackerEvaluatorPartsSql[] = 'NULLIF(et.student_id,0)';
    $trackerEvaluatorPartsPlainSql[] = 'NULLIF(student_id,0)';
}
$trackerEvaluatorIdSql = count($trackerEvaluatorPartsSql) > 1
    ? 'COALESCE(' . implode(', ', $trackerEvaluatorPartsSql) . ')'
    : ($trackerEvaluatorPartsSql[0] ?? 'NULL');
$trackerEvaluatorIdPlainSql = count($trackerEvaluatorPartsPlainSql) > 1
    ? 'COALESCE(' . implode(', ', $trackerEvaluatorPartsPlainSql) . ')'
    : ($trackerEvaluatorPartsPlainSql[0] ?? 'NULL');

// IMPORTANT: Peer-to-Peer mirrors the Faculty portal's Peer Evaluation > Faculty
// group: a Faculty member rating another Faculty member, where Faculty = every
// Teacher/Faculty account PLUS every Staff account with an explicit teaching
// identity (Teaching Staff). Like the Faculty portal, this does NOT depend on the
// year level(s) taught (Grade 7-12 or College). The evaluator/target roles are
// the reliable signal; requiring a hard-coded list of eval_type values hid legacy
// submissions. A Staff account still needs an explicit teaching identity.
$facultyPeerIdsSql = "SELECT ct.id FROM users ct
    WHERE (
        ct.role IN ('teacher','faculty')
        OR (ct.role='staff' AND (
            LOWER(TRIM(COALESCE(ct.sector,'')))='teacher'
            OR LOWER(TRIM(COALESCE(ct.secondary_role,''))) IN ('teacher','faculty')
            OR LOWER(COALESCE(ct.designation,'')) REGEXP '(^|[^[:alpha:]])(teacher|teaching staff|faculty|instructor|lecturer|professor)([^[:alpha:]]|$)'
            OR EXISTS (SELECT 1 FROM teaching_assignments ctta WHERE ctta.user_id=ct.id)
        ))
    )";
// ── PEER-TO-PEER: two lanes (Faculty / Staff) ──────────────────────
// Mirrors the faculty portal's Peer Evaluation page. EVALUATORS are Faculty /
// Teaching Staff who teach College students (a College year level assigned).
// TARGETS depend on the lane the evaluator picked in the portal:
//   Faculty -> Faculty / Teaching Staff  (peer_group 'Faculty')
//   Staff   -> non-teaching Staff        (peer_group 'Staff')
$collegeFacultyEvaluatorIdsSql = "SELECT ce.id FROM users ce
    WHERE ce.id IN ($facultyPeerIdsSql)
      AND EXISTS (SELECT 1 FROM user_year_levels ctyl
                  WHERE ctyl.user_id=ce.id AND $collegeTeacherYearFilterSql)";
$peerTargetIdsSql = [
    'Faculty' => $facultyPeerIdsSql,
    // Staff accounts WITHOUT a teaching identity (same split as the portal).
    'Staff'   => "SELECT st.id FROM users st WHERE st.role='staff' AND st.id NOT IN ($facultyPeerIdsSql)",
];
// Staff submissions must be the Peer-to-Peer type, because Faculty also
// submit separate Dean/Principal (school_head) evaluations against the same people.
$peerLaneSql = static function (string $group, bool $plain) use ($periodSql, $periodPlainSql, $trackerEvaluatorIdSql, $trackerEvaluatorIdPlainSql, $collegeFacultyEvaluatorIdsSql, $peerTargetIdsSql, $peerTypesSql): string {
    $pre  = $plain ? '' : 'et.';
    $per  = $plain ? $periodPlainSql : $periodSql;
    $evr  = $plain ? $trackerEvaluatorIdPlainSql : $trackerEvaluatorIdSql;
    $sql  = "$per AND $evr IN ($collegeFacultyEvaluatorIdsSql) AND {$pre}target_user_id IN ({$peerTargetIdsSql[$group]})";
    if ($group !== 'Faculty') $sql .= " AND {$pre}eval_type IN ($peerTypesSql)";
    return $sql;
};
$peerEvalSql      = $peerLaneSql($activeEval === 'peer' ? $groupFilter : 'Faculty', false);
$peerEvalPlainSql = $peerLaneSql($activeEval === 'peer' ? $groupFilter : 'Faculty', true);
// Combined (all three lanes) for the top "Peer-to-Peer" badge.
$peerEvalAllSql = "(" . implode(') OR (', array_map(fn($g) => $peerLaneSql($g, false), array_keys($peerTargetIdsSql))) . ")";
// Each lane's targets are scoped to that lane's roster.
if ($activeEval === 'peer') {
    $viewScopeSql = "u.id IN ({$peerTargetIdsSql[$groupFilter]})";
}

// Staff Evaluation: non-teaching staff evaluating the Executive Assistant.
// The staff-to-EA evaluator/target pair identifies this report lane even if a
// legacy eval_type column defaulted to "student" or contains an older label.
$nonTeachingStaffIdsSql = "SELECT nts.id FROM users nts
    WHERE nts.role='staff'
      AND COALESCE(nts.sector,'') <> 'Teacher'
      AND NOT EXISTS (SELECT 1 FROM user_year_levels ntyl WHERE ntyl.user_id=nts.id)
      AND NOT EXISTS (SELECT 1 FROM teaching_assignments ntta WHERE ntta.user_id=nts.id)";
$eaTargetIdsSql = "SELECT eat.id FROM users eat
    WHERE eat.role IN ($eaRolesSql) AND eat.is_active=1 AND eat.account_status='approved'";
$staffEvalSql = "$periodSql
    AND $trackerEvaluatorIdSql IN ($nonTeachingStaffIdsSql)
    AND et.target_user_id IN ($eaTargetIdsSql)";
$staffEvalPlainSql = "$periodPlainSql
    AND $trackerEvaluatorIdPlainSql IN ($nonTeachingStaffIdsSql)
    AND target_user_id IN ($eaTargetIdsSql)";

$evalTypeSql = match ($activeEval) {
    'peer' => $peerEvalSql,
    'staff' => $staffEvalSql,
    default => "$periodSql AND et.eval_type=$studentTypeSql AND $collegeStudentEvaluatorSql AND (COALESCE(et.evaluation_context,'teacher') IN ($teacherContextSql,$staffContextSql))"
};
$evalTypePlainSql = match ($activeEval) {
    'peer' => $peerEvalPlainSql,
    'staff' => $staffEvalPlainSql,
    default => "$periodPlainSql AND eval_type=$studentTypeSql AND $collegeStudentEvaluatorPlainSql AND (COALESCE(evaluation_context,'teacher') IN ($teacherContextSql,$staffContextSql))"
};

// ── VIEWS ─────────────────────────────────────────────────────
$view       = $_GET['view']       ?? 'list';
$target_id  = intval($_GET['target_id']  ?? 0);
$student_id = intval($_GET['student_id'] ?? 0);  // evaluator for peer
$tracker_id = intval($_GET['tracker_id'] ?? 0);
if (($view === 'list') && isset($_GET['group']) && (string)$_GET['group'] !== (string)$groupFilter) {
    $canonicalParams = $_GET;
    $canonicalParams['group'] = $groupFilter;
    // Relative redirect: stays in the current folder even when the address has a
    // double slash (e.g. localhost//index/dean/), which parse_url() would misread
    // as a host name and send the browser to the wrong path.
    header('Location: dean_reports.php?' . http_build_query($canonicalParams), true, 302);
    exit;
}

// ── HELPERS ───────────────────────────────────────────────────
// Resolve a target into the same Student Evaluation groups used by the
// Executive Assistant report. This helper is local to the Dean report so the
// roster/count/table labels stay consistent without depending on admin_analytics.php.
function ec_resolve_student_group_full($p, $mysqli) {
    static $memo = [];
    $role = strtolower(trim((string)($p['role'] ?? '')));
    if ($role === 'principal' || $role === 'dean') return 'school_head';

    $uid = (int)($p['id'] ?? 0);
    if ($uid <= 0) return null;
    if (array_key_exists($uid, $memo)) return $memo[$uid];

    // Callers that already selected the three classification flags (the roster
    // query) let us skip a per-user round trip; everyone else falls back to it.
    if (isset($p['is_teaching_staff'], $p['has_teaching_assignment'], $p['has_year_level'])) {
        // Keep exactly the columns the per-user query returns so the shared
        // ec_has_*_function() helpers see the same input as before.
        $u = array_intersect_key($p, array_flip(['id','full_name','designation','photo','source','role','sector','has_teaching_assignment','is_teaching_staff','has_year_level']));
    } else {
    $stmt = $mysqli->prepare(
        "SELECT id, full_name, designation, photo, source, role, sector,
                EXISTS(SELECT 1 FROM teaching_assignments ta WHERE ta.user_id=u.id) AS has_teaching_assignment,
                (u.role='teacher' OR u.sector='Teacher'
                 OR EXISTS(SELECT 1 FROM teaching_assignments ta2 WHERE ta2.user_id=u.id)
                 OR EXISTS(SELECT 1 FROM user_year_levels yl2 WHERE yl2.user_id=u.id)) AS is_teaching_staff,
                EXISTS(SELECT 1 FROM user_year_levels yl WHERE yl.user_id=u.id) AS has_year_level
         FROM users u WHERE u.id=? LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    }
    if (!$u) return null;

    // Mirror the shared Evaluation Context / Questionnaire teaching rule.
    $has_teacher = ((int)($u['is_teaching_staff'] ?? 0) === 1)
        || (function_exists('ec_has_teacher_function') && ec_has_teacher_function($u));
    if ($has_teacher) return $memo[$uid] = 'teacher';

    $has_staff = function_exists('ec_has_staff_function') && ec_has_staff_function($u);
    if ($role === 'staff' || $has_staff) {
        return $memo[$uid] = (
            (int)($u['has_teaching_assignment'] ?? 0) === 0
            && (int)($u['has_year_level'] ?? 0) === 0
        ) ? 'staff' : null;
    }
    return $memo[$uid] = null;
}

// Fetch one row without fatalling when a query fails (query() returns false).
function db_row(mysqli $db, string $sql): array {
    $r = $db->query($sql);
    if (!$r) return [];
    $row = $r->fetch_assoc();
    $r->free();
    return $row ?: [];
}

function scoreLabel($s) {
    if ($s === null) return '—';
    if ($s >= 4.5)  return 'Always';
    if ($s >= 3.5)  return 'Often';
    if ($s >= 2.5)  return 'Sometimes';
    if ($s >= 1.5)  return 'Rarely';
    return 'Never';
}
function scoreColor($s) {
    if ($s === null) return '#6b7280';
    if ($s >= 4.5)  return '#4ade80';
    if ($s >= 3.5)  return '#86efac';
    if ($s >= 2.5)  return '#facc15';
    if ($s >= 1.5)  return '#fb923c';
    return '#f87171';
}

// Satisfaction wording used by the cumulative report (print / Generate Report).
// Same score bands as scoreLabel(), different labels.
function scoreSatisfactionLabel($s) {
    if ($s === null) return '—';
    if ($s >= 4.5)  return 'Very Satisfied';
    if ($s >= 3.5)  return 'Satisfied';
    if ($s >= 2.5)  return 'Neither Satisfied nor Dissatisfied';
    if ($s >= 1.5)  return 'Dissatisfied';
    return 'Very Dissatisfied';
}

// Eval type UI config
$evalLabel      = match ($activeEval) { 'peer' => 'Peer-to-Peer Evaluation', 'staff' => 'Staff Evaluation', default => 'Student Evaluation' };
$evalColor      = match ($activeEval) { 'peer' => '#7C3AED', 'staff' => '#0D9488', default => '#3B82F6' };
$evalColorBg    = match ($activeEval) { 'peer' => 'rgba(124,58,237,.08)', 'staff' => 'rgba(13,148,136,.08)', default => 'rgba(59,130,246,.08)' };
$evalColorBorder= match ($activeEval) { 'peer' => 'rgba(124,58,237,.25)', 'staff' => 'rgba(13,148,136,.25)', default => 'rgba(59,130,246,.25)' };
$evalIcon       = match ($activeEval) { 'peer' => 'fa-people-arrows', 'staff' => 'fa-briefcase', default => 'fa-graduation-cap' };
// Label for "who evaluated"
$evaluatorNoun  = match ($activeEval) { 'peer' => 'colleague', 'staff' => 'staff member', default => 'student' };
$evaluatorNounP = match ($activeEval) { 'peer' => 'colleagues', 'staff' => 'staff', default => 'students' };
// In evaluation_tracker: student_id = the evaluator (student or peer teacher)
// eval_type filters which set we show

// ══════════════════════════════════════════════════════════════
function render_exec_sidebar(string $active, array $me, string $photo_src): void {
    // Single source of truth: includes/dean_sidebar.php (same nav as every
    // other Dean page). Variables stay local to this function.
    $sidebarScope = 'Higher Education Division';
    include __DIR__ . '/includes/dean_sidebar.php';
    // The top header (white bar) must follow dark mode. Styled here as well as in
    // includes/dean_portal_header.php so it works no matter which copy of the
    // shared header/theme files is deployed. ?>
<style id="dean-header-dark">
html.dark-theme body header.dean-portal-topbar,
html.dark-theme body .dean-portal-topbar{background:#0F1F3D !important;color:#E0E6F0 !important;border-bottom:1px solid rgba(255,255,255,.08) !important;box-shadow:0 2px 14px rgba(0,0,0,.35) !important;}
html.dark-theme body .dean-portal-topbar .dean-header-page-title{color:#E0E6F0 !important;}
html.dark-theme body .dean-portal-topbar .dean-header-period{background:rgba(156,133,240,.16) !important;border-color:rgba(156,133,240,.38) !important;color:#C4B5FD !important;}
html.dark-theme body .dean-portal-topbar .dean-header-bell{background:#172A45 !important;border-color:rgba(255,255,255,.12) !important;color:#FBBF24 !important;}
html.dark-theme body .dean-portal-topbar .dean-header-notif-badge{border-color:#0F1F3D !important;}
html.dark-theme body .dean-portal-topbar .dean-header-notif-dropdown{background:#172A45 !important;border-color:rgba(255,255,255,.12) !important;color:#E0E6F0 !important;box-shadow:0 18px 48px rgba(0,0,0,.55) !important;}
html.dark-theme body .dean-portal-topbar .dean-header-notif-heading{border-bottom-color:rgba(255,255,255,.08) !important;color:#A0B3C6 !important;}
html.dark-theme body .dean-portal-topbar .dean-header-notif-list li{background:#0F1F3D !important;border-color:rgba(255,255,255,.08) !important;color:#CBD5E1 !important;}
html.dark-theme body .dean-portal-topbar .dean-header-notif-list li.notif-link:hover{background:#1D3350 !important;}
html.dark-theme body .dean-portal-topbar .dean-header-notif-empty{color:#A0B3C6 !important;}
</style>
<script>
// Belt and braces: inline !important styles beat any stylesheet, and the
// MutationObserver re-applies them live when the Dark/Light toggle is used.
(function () {
    var bar = document.querySelector('.dean-portal-topbar');
    if (!bar) return;
    var rules = [
        ['', {'background':'#0F1F3D','color':'#E0E6F0','border-bottom-color':'rgba(255,255,255,.08)'}],
        ['.dean-header-page-title', {'color':'#E0E6F0'}],
        ['.dean-header-period', {'background':'rgba(156,133,240,.16)','border-color':'rgba(156,133,240,.38)','color':'#C4B5FD'}],
        ['.dean-header-bell', {'background':'#172A45','border-color':'rgba(255,255,255,.12)','color':'#FBBF24'}],
        ['.dean-header-notif-dropdown', {'background':'#172A45','border-color':'rgba(255,255,255,.12)','color':'#E0E6F0'}]
    ];
    function apply() {
        var dark = document.documentElement.classList.contains('dark-theme');
        rules.forEach(function (r) {
            var el = r[0] ? bar.querySelector(r[0]) : bar;
            if (!el) return;
            Object.keys(r[1]).forEach(function (prop) {
                if (dark) el.style.setProperty(prop, r[1][prop], 'important');
                else el.style.removeProperty(prop);
            });
        });
    }
    new MutationObserver(apply).observe(document.documentElement, {attributes: true, attributeFilter: ['class']});
    apply();
})();
</script>
<?php
}

// SHARED CSS HEAD (used across all sub-views)
// ══════════════════════════════════════════════════════════════
function pageHead($title, $evalColor, $evalColorBg, $evalColorBorder) { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<script>
// Apply the saved portal theme before the report stylesheet is parsed.
// This prevents the report sheet from briefly or permanently inheriting
// the light white surface when the portal is already using dark mode.
(function(){
  try {
    if (localStorage.getItem('pbi_theme') === 'dark') {
      document.documentElement.classList.add('dark-theme');
    }
  } catch (e) {}
})();
</script>
<title><?= htmlspecialchars($title) ?> — PBI Evaluation Reports</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<style>
:root{
  --dark:#F8FAFC;--mid:#FFFFFF;--inner:#F1F5F9;--accent:#3B82F6;
  --gold:#D97706;--gold-h:#F59E0B;--teal:#0D9488;--violet:#7C3AED;
  --light:#0F172A;--muted:#475569;--danger:#F87171;
  --border:#CBD5E1;--radius:10px;
  --card-shadow:0 1px 2px rgba(15,23,42,.06),0 4px 12px rgba(15,23,42,.06);
  --ec:<?= $evalColor ?>;
  --ec-bg:<?= $evalColorBg ?>;
  --ec-bd:<?= $evalColorBorder ?>;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:var(--dark);color:var(--light);min-height:100vh;padding:28px;}

/* ── CUSTOM SCROLLBAR ── */
html,body{scrollbar-width:thin;scrollbar-color:var(--light) transparent;}
::-webkit-scrollbar{width:10px;height:10px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:var(--light);border-radius:20px;border:2px solid var(--dark);background-clip:padding-box;}
::-webkit-scrollbar-thumb:hover{background:#fff;background-clip:padding-box;}
.toast{position:fixed;top:20px;right:20px;z-index:999;background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.35);color:#86efac;padding:12px 20px;border-radius:8px;font-size:13px;display:flex;align-items:center;gap:8px;animation:slideIn .3s ease,fadeOut .4s ease 3s forwards;}
@keyframes slideIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:none}}
@keyframes fadeOut{to{opacity:0;pointer-events:none}}
.back-btn{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);color:var(--light);font-size:13px;font-weight:600;text-decoration:none;margin-bottom:22px;transition:background .2s;}
.back-btn:hover{background:var(--accent);}

/* ── EVAL SWITCHER ── */
.eval-switcher{display:flex;gap:0;background:var(--mid);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:26px;width:fit-content;}
.eval-tab{display:flex;align-items:center;gap:9px;padding:12px 24px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;color:var(--muted);border:none;background:none;font-family:'Inter',sans-serif;transition:all .2s;position:relative;}
.eval-tab:hover{color:var(--light);background:rgba(255,255,255,.04);}
.eval-tab.student.active{color:var(--accent);background:rgba(59,130,246,.07);}
.eval-tab.student.active::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:var(--accent);border-radius:2px 2px 0 0;}
.eval-tab.peer.active{color:var(--violet);background:rgba(124,58,237,.07);}
.eval-tab.peer.active::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:var(--violet);border-radius:2px 2px 0 0;}
.eval-divider{width:1px;background:var(--border);margin:8px 0;}
.tab-badge{font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;background:rgba(15,23,42,.12);color:var(--muted);}
.eval-tab.student.active .tab-badge{background:rgba(59,130,246,.15);color:var(--accent);}
.eval-tab.peer.active .tab-badge{background:rgba(124,58,237,.15);color:var(--violet);}
.eval-tab.multi-role.active{color:#F59E0B;background:rgba(245,158,11,.07);}
.eval-tab.multi-role.active::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:#F59E0B;border-radius:2px 2px 0 0;}
.eval-tab.multi-role.active .tab-badge{background:rgba(245,158,11,.15);color:#F59E0B;}

/* ── EVAL TYPE BANNER ── */
.eval-banner{display:flex;align-items:center;gap:14px;padding:13px 18px;border-radius:10px;margin-bottom:20px;border:1px solid var(--ec-bd);background:var(--ec-bg);}
.eval-banner-icon{width:40px;height:40px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:18px;color:var(--ec);background:rgba(255,255,255,.05);border:1px solid var(--ec-bd);flex-shrink:0;}
.eval-banner-title{font-size:14px;font-weight:700;color:var(--ec);}
.eval-banner-desc{font-size:12px;color:var(--muted);margin-top:1px;}

/* Admin Module light design system — matches the dashboard */
:root{
  --page-bg:#FFFFFF; --card-bg:#FFFFFF; --card-border:#E2E8F0;
  --inner:#F4F7FB; --text-dark:#172033; --text-dim:#475569;
  --light:#172033; --muted:#475569; --dark:#FFFFFF; --mid:#FFFFFF;
  --border:#E2E8F0; --accent:#3B82F6; --blue:#3B82F6;
  --gold:#D97706; --gold-h:#F59E0B; --teal:#0D9488; --violet:#7C3AED;
  --danger:#DC2626; --success:#059669; --radius:12px;
  --card-shadow:0 2px 4px rgba(15,23,42,.05),0 6px 16px rgba(15,23,42,.06);
}
html{background:#fff;color-scheme:light;}
body{background:#fff !important;color:#172033 !important;}
a{color:inherit;}
.page-header h1,.page-title,.et-title,.section-title{color:#172033 !important;}
.page-header p,.page-sub,.et-sub,.et-updated,.muted,.hint{color:#475569 !important;}
input,select,textarea{background:#fff !important;color:#172033 !important;border-color:#CBD5E1 !important;}
button{font-family:inherit;}
.table-wrap,.content-panel,.create-panel,.period-card,.stat-card,.sector-card,.person-row,
.sum-card,.standing-panel,.eval-card,.eval-banner,.info-banner,.section,.shell .section,
.history-card,.gl-card,.amber-card,.green-card,.red-card{
  background:#fff !important;border-color:#E2E8F0 !important;box-shadow:0 2px 4px rgba(15,23,42,.04),0 6px 16px rgba(15,23,42,.05) !important;
}
.sector-tabs,.eval-switcher,.tabs,.level-tabs,.status-tabs{
  background:#fff !important;border-color:#E2E8F0 !important;box-shadow:0 2px 4px rgba(15,23,42,.04) !important;
}
.sector-tab,.eval-tab,.tab,.level-tab,.status-tab{color:#475569 !important;}
.sector-tab:hover,.eval-tab:hover,.tab:hover,.level-tab:hover,.status-tab:hover{color:#172033 !important;background:#F4F7FB !important;}
thead tr{background:#F8FAFC !important;}
tbody tr:hover{background:#F8FAFC !important;}
.btn-cancel,.btn-icon,.btn-back{background:#fff !important;color:#172033 !important;border-color:#CBD5E1 !important;}
.empty-state,.empty-cta{color:#475569 !important;}
::-webkit-scrollbar-track{background:#fff;}
::-webkit-scrollbar-thumb{background:#CBD5E1;border:2px solid #fff;}

body{padding:28px !important;}
.eval-tab.student.active{background:#EFF6FF !important;color:#2563EB !important;}
.eval-tab.peer.active{background:#F5F3FF !important;color:#7C3AED !important;}
.eval-tab.multi-role.active{background:#FFF7ED !important;color:#D97706 !important;}
.eval-banner{background:var(--ec-bg) !important;}
.avg-bar-bg,.eval-bar-bg,.score-bar-bg{background:#E2E8F0 !important;}
.comment-text,.comment-section{background:#F8FAFC !important;color:#334155 !important;}


/* ── SHARP LIGHT ADMIN UI ── */
html { background:#F8FAFC; }
body {
  color:#0F172A !important;
  background:#F8FAFC !important;
  -webkit-font-smoothing:antialiased;
  text-rendering:optimizeLegibility;
}
h1,h2,h3,h4,h5,h6 { color:#0F172A; letter-spacing:-.01em; }
p, .subtitle, .description, .helper, .muted, small { color:#475569; }
label, th { color:#334155; font-weight:600; }
td { color:#0F172A; }
input, select, textarea {
  color:#0F172A;
  background:#FFFFFF;
  border-color:#CBD5E1;
}
input::placeholder, textarea::placeholder { color:#94A3B8; }
.card, .panel, .section, .table-card, .content-card {
  border-color:#CBD5E1;
  box-shadow:0 4px 14px rgba(15,23,42,.07);
}
button, .btn { font-weight:700; }
a { color:inherit; }

/* Persistent evaluation-tab icon coding */
.eval-tab.student > i{color:#2563EB !important;}
.eval-tab.peer > i{color:#7C3AED !important;}
.eval-tab.multi-role > i{color:#D97706 !important;}

</style>
<style>
:root{--portal-accent:#7C5FD9;--portal-accent-h:#9C85F0;--portal-accent-glow:rgba(124,95,217,.4);--portal-accent-bg:rgba(124,95,217,.15);}
html{background:#0A192F !important;color-scheme:dark;}
body{min-height:100vh;display:flex !important;padding:0 !important;background:linear-gradient(rgba(5,18,36,.72),rgba(5,18,36,.82)),url('../background.png') center center / cover no-repeat fixed !important;background-color:#0A192F !important;color:#E0E6F0 !important;font-family:'DM Sans',sans-serif !important;}
.sidebar{width:250px;flex:0 0 250px;min-height:100vh;background:#0F1F33;border-right:1px solid #060E18;padding:28px 20px;display:flex;flex-direction:column;position:sticky;top:0;height:100vh;z-index:20;}
.sb-nav .sb-section-title{width:100%;padding:0 6px;margin:8px 0 3px;color:#C4B5FD;font-size:11px;font-weight:900;letter-spacing:1.5px;line-height:1.25;text-align:center;text-transform:uppercase;text-shadow:0 1px 8px rgba(167,139,250,.16)}.sb-nav .sb-section-title:first-child{margin-top:0}.sb-profile{text-align:center;margin-bottom:26px}.sb-photo{width:72px;height:72px;border-radius:50%;object-fit:cover;border:2.5px solid var(--portal-accent);box-shadow:0 0 18px var(--portal-accent-glow);margin:0 auto 10px;display:block}.sb-name{font-weight:700;font-size:15px;color:#fff}.sb-role{font-size:11px;color:var(--portal-accent-h);text-transform:uppercase;letter-spacing:.6px;margin-top:2px}.sb-scope{font-size:10px;color:#A0B3C6;margin-top:4px}.sb-nav{display:flex;flex-direction:column;gap:4px;margin-top:10px}.sb-nav a{display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;color:#A0B3C6;text-decoration:none;font-size:14px;font-weight:500}.sb-nav a:hover,.sb-nav a.active{background:var(--portal-accent-bg);color:#fff}.sb-nav a i{width:18px;text-align:center;color:var(--portal-accent-h)}.sb-logout{margin-top:auto}.sb-logout a{display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;color:#fca5a5;text-decoration:none;font-size:14px}
.main{flex:1;min-width:0;padding:36px 44px !important;}
.page-header{background:rgba(23,42,69,.85) !important;border:1px solid rgba(255,255,255,.08) !important;color:#E0E6F0 !important;box-shadow:0 8px 32px rgba(0,0,0,.45) !important}.page-header h1,.page-title,.section-title,.sheet-name,.target-name,.person-name,.standing-name{color:#fff !important}.page-header p,.page-sub,.standing-desig,.person-meta,.sum-label,.sum-sub,.pstat-lbl,.muted,.no-data{color:#A0B3C6 !important}
.eval-switcher,.eval-banner,.group-tab,.desig-subtabs,.sum-card,.standing-panel,.person-row,.no-evaluated,.target-card,.eval-card,.comment-section,.avg-summary,.top-bar,.history-card,.section,.content-panel,.no-archived{background:rgba(23,42,69,.85) !important;border-color:rgba(255,255,255,.08) !important;box-shadow:0 8px 32px rgba(0,0,0,.24) !important;color:#E0E6F0 !important}
.eval-tab{color:#A0B3C6 !important}.eval-tab:hover{color:#fff !important;background:rgba(255,255,255,.04) !important}.eval-tab.student.active,.eval-tab.multi-role.active,.eval-tab.peer.active{background:var(--portal-accent-bg) !important;color:var(--portal-accent-h) !important}.eval-tab.student.active::after,.eval-tab.multi-role.active::after,.eval-tab.peer.active::after{background:var(--portal-accent) !important}.tab-badge,.tab-count{background:rgba(255,255,255,.10) !important;color:#E0E6F0 !important}
.group-tab.active-all,.group-tab.active-teacher,.group-tab.active-staff,.desig-subtab.active{background:var(--portal-accent-bg) !important;border-color:rgba(255,255,255,.16) !important;color:var(--portal-accent-h) !important}
.sum-value,.person-name,.section-title,.standing-name,.sheet-name,.target-name{color:#fff !important}.person-link,.back-btn,.btn-archived-link{color:#E0E6F0 !important}.person-row:hover{border-color:rgba(255,255,255,.16) !important}.person-photo-ph,.standing-photo-ph{background:#0F1F3D !important;border-color:rgba(255,255,255,.10) !important;color:#A0B3C6 !important}
.score-bar-bg,.avg-bar-bg,.eval-bar-bg{background:rgba(255,255,255,.08) !important}.btn-print{background:var(--portal-accent) !important}.btn-archived-link{background:rgba(23,42,69,.85) !important;border-color:rgba(255,255,255,.10) !important}.btn-archived-link:hover{color:#fff !important}.back-btn{background:rgba(23,42,69,.85) !important;border-color:rgba(255,255,255,.10) !important}.back-btn:hover{background:var(--portal-accent-bg) !important;color:#fff !important}
input,select,textarea{background:#0F1F3D !important;color:#E0E6F0 !important;border-color:rgba(255,255,255,.12) !important}.comment-text{background:#0F1F3D !important;color:#E0E6F0 !important}.eval-banner-title{color:var(--portal-accent-h) !important}.eval-banner-desc{color:#A0B3C6 !important}
/* FINAL LIGHT THEME OVERRIDE — reports matches Dean dashboard */
html{background:#F8FAFC !important;color-scheme:light !important;}
body{background:#F8FAFC !important;color:#172033 !important;font-family:'Inter',sans-serif !important;}
.main{background:#F8FAFC !important;color:#172033 !important;}
.page-header{background:#FFFFFF !important;border:1px solid #E2E8F0 !important;color:#172033 !important;box-shadow:0 2px 4px rgba(15,23,42,.04),0 6px 16px rgba(15,23,42,.05) !important;}
.page-header h1,.page-title,.section-title,.sheet-name,.target-name,.person-name,.standing-name,.sum-value{color:#172033 !important;}
.page-header p,.page-sub,.standing-desig,.person-meta,.sum-label,.sum-sub,.pstat-lbl,.muted,.no-data,.eval-banner-desc{color:#475569 !important;}
.eval-switcher,.eval-banner,.group-tab,.desig-subtabs,.sum-card,.standing-panel,.person-row,.no-evaluated,.target-card,.eval-card,.comment-section,.avg-summary,.top-bar,.history-card,.section,.content-panel,.no-archived,.table-wrap,.create-panel,.period-card,.stat-card,.sector-card,.gl-card,.amber-card,.green-card,.red-card{
  background:#FFFFFF !important;border-color:#E2E8F0 !important;box-shadow:0 2px 4px rgba(15,23,42,.04),0 6px 16px rgba(15,23,42,.05) !important;color:#172033 !important;
}
.eval-tab{color:#475569 !important;}
.eval-tab:hover{color:#172033 !important;background:#F4F7FB !important;}
.eval-tab.student.active,.eval-tab.multi-role.active,.eval-tab.peer.active{background:#F5F3FF !important;color:#7C5FD9 !important;}
.eval-tab.student.active::after,.eval-tab.multi-role.active::after,.eval-tab.peer.active::after{background:#7C5FD9 !important;}
.tab-badge,.tab-count{background:#F1F5F9 !important;color:#475569 !important;}
.group-tab.active-all,.group-tab.active-teacher,.group-tab.active-staff,.desig-subtab.active{background:#F5F3FF !important;border-color:#E2E8F0 !important;color:#7C5FD9 !important;}
.sum-value,.person-name,.section-title,.standing-name,.sheet-name,.target-name{color:#172033 !important;}
.person-link,.back-btn,.btn-archived-link{color:#334155 !important;}
.person-row:hover{border-color:#CBD5E1 !important;}
.person-photo-ph,.standing-photo-ph{background:#F1F5F9 !important;border-color:#E2E8F0 !important;color:#64748B !important;}
.score-bar-bg,.avg-bar-bg,.eval-bar-bg{background:#E2E8F0 !important;}
.btn-print{background:#7C5FD9 !important;color:#fff !important;}
.btn-archived-link,.back-btn{background:#FFFFFF !important;border-color:#CBD5E1 !important;}
.btn-archived-link:hover,.back-btn:hover{background:#F5F3FF !important;color:#7C5FD9 !important;}
input,select,textarea{background:#FFFFFF !important;color:#172033 !important;border-color:#CBD5E1 !important;}
.comment-text{background:#F8FAFC !important;color:#334155 !important;}
.eval-banner-title{color:#7C5FD9 !important;}
:::-webkit-scrollbar-track{background:#F8FAFC !important;}
:::-webkit-scrollbar-thumb{background:#CBD5E1 !important;border:2px solid #F8FAFC !important;}

@media(max-width:900px){body{display:block !important}.sidebar{position:relative;width:100%;height:auto;min-height:0}.main{padding:24px !important}}
</style>


<?php } // end pageHead

// ══════════════════════════════════════════════════════════════
// VIEW: EVALUATION SHEET
// ══════════════════════════════════════════════════════════════
if ($view === 'sheet' && $target_id && $tracker_id) {
    $tgt = db_row($mysqli, "SELECT id,full_name,designation,photo,role FROM users u WHERE u.id=$target_id AND $viewScopeSql LIMIT 1");
    if (!$tgt) { http_response_code(404); exit('Personnel not found in this report scope.'); }
    $trk = db_row($mysqli, "SELECT * FROM evaluation_tracker et WHERE et.id=$tracker_id AND et.target_user_id=$target_id AND $evalTypeSql LIMIT 1");
    if (!$trk) { http_response_code(404); exit('Evaluation not found in this report scope.'); }
    // Identify the evaluator from the tracker row itself so a hand-edited
    // ?student_id= can never show a name that doesn't match the evaluation.
    $sheetEvaluatorId = (int)($trk['evaluator_id'] ?? $trk['student_id'] ?? 0);
    $stu = db_row($mysqli, "SELECT id,full_name,photo FROM users WHERE id=" . $sheetEvaluatorId . " LIMIT 1");

    $answers = [];
    // Student Teacher/Multi-Role questionnaires come from evaluation_questions;
    // Staff questionnaires are stored in user_questions. Keep both sources
    // separate so a multi-role evaluation can never be mixed with the person's
    // Staff evaluation even when the same question IDs exist in both tables.
    $aq = $mysqli->query("
        SELECT qa.question_id AS q_id, eq.question_text, eq.category, qa.answer_score
        FROM questionnaire_answers qa
        JOIN evaluation_questions eq ON eq.id = qa.question_id
        WHERE qa.tracker_id = $tracker_id AND qa.question_source='evaluation'
        UNION ALL
        SELECT qa.user_question_id AS q_id, uq.question_text, uq.category, qa.answer_score
        FROM questionnaire_answers qa
        JOIN user_questions uq ON uq.id = qa.user_question_id
        WHERE qa.tracker_id = $tracker_id AND qa.question_source='user'
        ORDER BY category, q_id
    ");
    if ($aq) $answers = $aq->fetch_all(MYSQLI_ASSOC);

    $grouped_ans = [];
    foreach ($answers as $a) $grouped_ans[$a['category']][] = $a;

    $scores = array_filter(array_column($answers,'answer_score'), fn($s) => $s !== null);
    $avg    = count($scores) ? round(array_sum($scores)/count($scores),2) : null;
    $remark = $trk['remarks'] ?? '';

    // "Generate Report" (Evaluators List) opens this sheet with &report=1 so the
    // clean cumulative report layout is shown on screen, exactly as it prints.
    $reportMode = (($_GET['report'] ?? '') === '1');

    // ── CUMULATIVE REPORT DATA (print / Generate Report layout) ───────────
    // The report is the cumulative result for this person under the current
    // evaluation type: every submission is pooled, each question shows its
    // average rating across all evaluators, and the overall rating is the
    // average of those per-question ratings. It uses the same evaluator set as
    // the Evaluators List (same period, College-only scope, year-level filter).
    $cumYear = trim((string)($_GET['year_level'] ?? ''));
    $cumYearWhere = '';
    if ($activeEval === 'student' && $cumYear !== '') {
        $cumYearWhere = " AND TRIM(COALESCE(u.year_level,'')) = '" . $mysqli->real_escape_string($cumYear) . "'";
    }
    $cumTrackers = [];
    $cq = $mysqli->query("
        SELECT et.id AS tracker_id, et.submitted_at, et.remarks, u.role AS evaluator_role
        FROM evaluation_tracker et
        JOIN users u ON u.id = {$trackerEvaluatorIdSql}
        WHERE et.target_user_id = $target_id AND $evalTypeSql $cumYearWhere
        ORDER BY et.submitted_at DESC
    ");
    if ($cq) $cumTrackers = $cq->fetch_all(MYSQLI_ASSOC);
    if (empty($cumTrackers) && $trk) {
        // Safety net: never print an empty report for a valid sheet link.
        $cumTrackers = [[
            'tracker_id'     => (int)$trk['id'],
            'submitted_at'   => $trk['submitted_at'],
            'remarks'        => $trk['remarks'] ?? '',
            'evaluator_role' => '',
        ]];
    }

    $cumByCat = [];
    $cumOverall = null;
    if (!empty($cumTrackers)) {
        $cumIds = implode(',', array_map('intval', array_column($cumTrackers, 'tracker_id')));
        // Same two question sources / joins as the individual sheet above, so the
        // cumulative numbers always agree with what "View" shows per evaluator.
        $cqb = $mysqli->query("
            SELECT category, q_id, question_text,
                   COUNT(answer_score) AS total_responses,
                   AVG(answer_score)   AS avg_score
            FROM (
                SELECT qa.question_id AS q_id, eq.question_text, eq.category, qa.answer_score, qa.question_source AS src
                FROM questionnaire_answers qa
                JOIN evaluation_questions eq ON eq.id = qa.question_id
                WHERE qa.tracker_id IN ($cumIds) AND qa.question_source='evaluation'
                UNION ALL
                SELECT qa.user_question_id AS q_id, uq.question_text, uq.category, qa.answer_score, qa.question_source AS src
                FROM questionnaire_answers qa
                JOIN user_questions uq ON uq.id = qa.user_question_id
                WHERE qa.tracker_id IN ($cumIds) AND qa.question_source='user'
            ) cum_answers
            WHERE answer_score IS NOT NULL
              AND " . rp_current_q_core('question_text', 'src', (string)(int)$target_id) . "
            GROUP BY category, q_id, question_text
            ORDER BY category, q_id
        ");
        if ($cqb) {
            foreach (rp_merge_question_rows($cqb->fetch_all(MYSQLI_ASSOC)) as $cRow) {
                $cRow['avg_score'] = round((float)$cRow['avg_score'], 2);
                $cumByCat[$cRow['category']][] = $cRow;
            }
        }
        // Overall = flat average across every individual answer pooled together
        // (same method the roster/Evaluated Personnel list uses: AVG(qa.answer_score)
        // over all rows — see line ~1581). Previously this was the average of the
        // rounded per-question averages instead, which only matches the roster
        // figure when every question has the same number of responses — it
        // silently drifts whenever a question is skipped by an evaluator or
        // added/removed between evaluation cycles. Kept as its own direct query
        // (not derived from $cumByCat's rounded per-question values) so it is
        // exactly the same figure the roster shows.
        $cumOverallRes = $mysqli->query("
            SELECT AVG(answer_score) AS overall_avg
            FROM (
                SELECT qa.answer_score
                FROM questionnaire_answers qa
                WHERE qa.tracker_id IN ($cumIds) AND qa.question_source='evaluation' AND qa.answer_score IS NOT NULL
                  AND " . rp_current_q_sql('qa', (int)$target_id) . "
                UNION ALL
                SELECT qa.answer_score
                FROM questionnaire_answers qa
                WHERE qa.tracker_id IN ($cumIds) AND qa.question_source='user' AND qa.answer_score IS NOT NULL
                  AND " . rp_current_q_sql('qa', (int)$target_id) . "
            ) cum_all_answers
        ");
        if ($cumOverallRes) {
            $cumOverallRow = $cumOverallRes->fetch_assoc();
            $cumOverall = $cumOverallRow && $cumOverallRow['overall_avg'] !== null
                ? round((float)$cumOverallRow['overall_avg'], 2)
                : null;
        }
    }

    // Date shown in the header: the evaluation's actual open and close times from
    // System Settings (same source as the admin dashboard), one per line.
    // Falls back to the submission dates only if no schedule has been saved.
    $cumDateText = '';
    $cumSched = ['eval_start' => '', 'eval_end' => ''];
    if ($cumSchedRes = $mysqli->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('eval_start','eval_end')")) {
        while ($cumSchedRow = $cumSchedRes->fetch_assoc()) $cumSched[$cumSchedRow['setting_key']] = trim((string)$cumSchedRow['setting_value']);
    }
    $cumFmtSched = static function (string $v): string {
        if ($v === '') return '';
        try { return (new DateTime($v, new DateTimeZone('Asia/Manila')))->format('M j, Y, g:i A'); }
        catch (Exception $e) { return ''; }
    };
    $cumOpen  = $cumFmtSched($cumSched['eval_start']);
    $cumClose = $cumFmtSched($cumSched['eval_end']);
    if ($cumOpen !== '' && $cumClose !== '') {
        $cumDateText = $cumOpen . "\n" . $cumClose;
    } else {
        $cumDates = array_filter(array_column($cumTrackers, 'submitted_at'));
        if ($cumDates) {
            $cumFrom = strtotime(min($cumDates));
            $cumTo   = strtotime(max($cumDates));
            $cumDateText = (date('Y-m-d', $cumFrom) === date('Y-m-d', $cumTo))
                ? date('M d, Y', $cumTo)
                : date('M d, Y', $cumFrom) . ' – ' . date('M d, Y', $cumTo);
        } else {
            $cumDateText = '—';
        }
    }

    // Who evaluated, with counts — e.g. "4 Students" or "3 Faculty, 1 Staff".
    $cumRoleLabels = ['student'=>'Students','teacher'=>'Faculty','faculty'=>'Faculty','staff'=>'Staff','dean'=>'Dean','principal'=>'Principal'];
    $cumEvalCounts = [];
    foreach ($cumTrackers as $ct) {
        $r = strtolower(trim((string)($ct['evaluator_role'] ?? '')));
        $lbl = $cumRoleLabels[$r] ?? ucfirst($evaluatorNounP);
        $cumEvalCounts[$lbl] = ($cumEvalCounts[$lbl] ?? 0) + 1;
    }
    $cumEvalParts = [];
    foreach ($cumEvalCounts as $lbl => $n) {
        if ($n === 1 && $lbl === 'Students') $lbl = 'Student';
        $cumEvalParts[] = $n . ' ' . $lbl;
    }
    $cumEvaluatedBy = implode(', ', $cumEvalParts);

    // Comments from every evaluator, anonymous.
    $cumRemarks = array_values(array_filter(
        array_map(fn($t) => trim((string)($t['remarks'] ?? '')), $cumTrackers),
        fn($r) => $r !== ''
    ));

    $cumRoleText = $activeEval === 'staff' ? 'Executive Assistant' : ($tgt['role']==='principal' ? 'Principal'
                 : ($tgt['role']==='dean' ? 'Dean'
                 : (in_array($tgt['role'], ['teacher','faculty'], true) ? 'Faculty' : 'Staff')));

    $scaleItems  = [5=>'Always',4=>'Often',3=>'Sometimes',2=>'Rarely',1=>'Never'];
    $scaleColors = [5=>'#4ade80',4=>'#86efac',3=>'#facc15',2=>'#fb923c',1=>'#f87171'];

    pageHead('Evaluation Sheet', $evalColor, $evalColorBg, $evalColorBorder);
    ?>
<style>
.sheet-header{background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:22px 26px;margin-bottom:20px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;}
.sheet-avatar{width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid var(--ec);}
.sheet-avatar-ph{width:64px;height:64px;border-radius:50%;background:var(--inner);border:2px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:26px;}
.sheet-name{font-family:'Rajdhani',sans-serif;font-size:22px;font-weight:700;color:var(--light);}
.sheet-desig{font-size:13px;color:var(--muted);margin-top:2px;}
.sheet-eval-by{margin-left:auto;text-align:right;}
.eval-by-label{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:3px;}
.eval-by-name{font-size:14px;font-weight:600;color:var(--ec);}
.eval-by-date{font-size:11px;color:var(--muted);margin-top:2px;}
.scale-bar{display:flex;gap:6px;margin-bottom:20px;background:var(--mid);border:1px solid var(--border);border-radius:10px;padding:12px 18px;flex-wrap:wrap;}
.scale-item{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);}
.scale-dot{width:22px;height:22px;border-radius:5px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;flex-shrink:0;}
.eval-type-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;border:1px solid var(--ec-bd);background:var(--ec-bg);color:var(--ec);margin-bottom:18px;}
.cat-section{margin-bottom:28px;}
.cat-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:var(--ec);margin-bottom:12px;padding-bottom:6px;border-bottom:1px solid var(--ec-bd);display:flex;align-items:center;gap:7px;}
.q-table{width:100%;border-collapse:collapse;background:var(--mid);border-radius:12px;overflow:hidden;border:1px solid var(--border);}
.q-table thead tr{background:var(--inner);}
.q-table th{padding:11px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);text-align:left;}
.q-table th.rating-col{text-align:center;width:90px;}
.q-table td{padding:13px 16px;border-top:1px solid var(--border);font-size:13px;color:var(--light);line-height:1.5;vertical-align:top;}
.q-table td.q-num{width:40px;color:var(--muted);font-weight:700;font-size:13px;padding-top:14px;}
.q-table td.rating-cell{text-align:center;padding-top:12px;}
.q-table tr:hover td{background:rgba(59,130,246,.06);}
.rating-badge{display:inline-flex;flex-direction:column;align-items:center;gap:2px;background:var(--inner);border-radius:8px;padding:6px 12px;}
.rating-num{font-size:16px;font-weight:700;}
.rating-lbl{font-size:9px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;}
.comment-section{background:var(--mid);border:1px solid var(--ec-bd);border-radius:12px;padding:20px 24px;margin-bottom:24px;}
.comment-title{font-size:13px;font-weight:700;color:var(--ec);margin-bottom:10px;display:flex;align-items:center;gap:8px;}
.comment-text{font-size:14px;color:var(--light);line-height:1.6;font-style:italic;}
.no-comment{font-size:13px;color:var(--muted);font-style:italic;}
.avg-summary{background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:24px;display:flex;align-items:center;gap:24px;flex-wrap:wrap;}
.avg-score-big{font-family:'Rajdhani',sans-serif;font-size:56px;font-weight:700;line-height:1;}
.avg-score-label{font-size:14px;font-weight:700;margin-top:4px;}
.avg-breakdown{flex:1;min-width:200px;}
.avg-bar-row{display:flex;align-items:center;gap:10px;margin-bottom:7px;}
.avg-bar-label{font-size:12px;color:var(--muted);width:100px;text-align:right;}
.avg-bar-bg{flex:1;height:7px;background:rgba(15,23,42,.12);border-radius:4px;overflow:hidden;}
.avg-bar-fill{height:100%;border-radius:4px;}
.avg-bar-val{font-size:12px;font-weight:700;width:28px;}
.avg-out-of{font-size:13px;color:var(--muted);margin-top:6px;}
.btn-print{background:var(--accent);color:#fff;border:none;padding:10px 20px;border-radius:var(--radius);font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:opacity .2s;font-family:'Inter',sans-serif;}
.btn-print:hover{opacity:.85;}
.top-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;}

/* ── PRINT: hide evaluator identity entirely ── */
@media print{
  .no-print{display:none!important;}
  body{background:#fff!important;color:#000!important;padding:0;}
  body *{color:#000!important;}
  /* Hide the "Evaluated by" block in the sheet header */
  .sheet-eval-by{display:none!important;}
  /* Anonymity notice shown only in print */
  .print-anon-notice{display:block!important;}
}
/* Hidden on screen, visible only when printing */
.print-anon-notice{
  display:none;
  margin-top:10px;
  font-size:11px;
  color:#555;
  font-style:italic;
  border-top:1px solid #ddd;
  padding-top:8px;
}

/* ── SHARP LIGHT ADMIN UI ── */
html { background:#F8FAFC; }
body {
  color:#0F172A !important;
  background:#F8FAFC !important;
  -webkit-font-smoothing:antialiased;
  text-rendering:optimizeLegibility;
}
h1,h2,h3,h4,h5,h6 { color:#0F172A; letter-spacing:-.01em; }
p, .subtitle, .description, .helper, .muted, small { color:#475569; }
label, th { color:#334155; font-weight:600; }
td { color:#0F172A; }
input, select, textarea {
  color:#0F172A;
  background:#FFFFFF;
  border-color:#CBD5E1;
}
input::placeholder, textarea::placeholder { color:#94A3B8; }
.card, .panel, .section, .table-card, .content-card {
  border-color:#CBD5E1;
  box-shadow:0 4px 14px rgba(15,23,42,.07);
}
button, .btn { font-weight:700; }
a { color:inherit; }
</style>
<link rel="stylesheet" href="includes/dean_light_theme.css?v=dashboard-ui-20261009"/>
<style id="dean-reports-scrollbar-match">
/* Evaluation Reports: same thin light-gray scrollbar as Evaluation Received.
   The old rule used scrollbar-color:var(--light), which is near-black in the light theme. */
html:not(.dark-theme),html:not(.dark-theme) *{scrollbar-width:thin!important;scrollbar-color:#CBD5E1 transparent!important;}
html:not(.dark-theme) ::-webkit-scrollbar{width:8px;height:8px;}
html:not(.dark-theme) ::-webkit-scrollbar-track{background:transparent!important;}
html:not(.dark-theme) ::-webkit-scrollbar-thumb{background:#CBD5E1!important;border:0!important;border-radius:20px;}
html:not(.dark-theme) ::-webkit-scrollbar-thumb:hover{background:#A9B6C8!important;}
</style>
</head><body class="<?= $reportMode ? 'report-mode' : '' ?>">
</div>
<?php render_exec_sidebar('reports', $me, $photo_src); ?>
<main class="main">


<div class="person-actions no-print" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <a href="?view=students&target_id=<?= $target_id ?>&group=<?= urlencode($groupFilter) ?>&eval_type=<?= $activeEval ?><?= $cumYear !== '' ? '&year_level=' . urlencode($cumYear) : '' ?>" class="back-btn">
        <i class="fa-solid fa-arrow-left"></i> Back to <?= ucfirst($evaluatorNounP) ?> List
    </a>
    <button class="btn-print no-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
</div>

<!-- Eval type chip -->
<div class="eval-type-chip no-print">
    <i class="fa-solid <?= $evalIcon ?>"></i> <?= $evalLabel ?>
</div>

<div class="individual-sheet">
<!-- SHEET HEADER -->
<div class="sheet-header">
    <?php if ($tgt['photo']): ?><img class="sheet-avatar" src="../image/<?= htmlspecialchars($tgt['photo']) ?>" alt=""/>
    <?php else: ?><div class="sheet-avatar-ph"><i class="fa-solid fa-user"></i></div><?php endif; ?>
    <div>
        <div class="sheet-name"><?= htmlspecialchars($tgt['full_name']) ?></div>
        <div class="sheet-desig"><?= htmlspecialchars($tgt['designation']) ?> · <?= $activeEval==='staff' ? 'Executive Assistant' : ($tgt['role']==='teacher'?'Teacher':'Staff') ?></div>
    </div>

    <!-- Visible on screen: shows evaluator identity -->
    <div class="sheet-eval-by no-print">
        <div class="eval-by-label">Evaluated by <?= $evaluatorNoun ?></div>
        <div class="eval-by-name"><?= htmlspecialchars($stu['full_name'] ?? 'Unknown') ?></div>
        <div class="eval-by-date"><i class="fa-solid fa-clock" style="margin-right:4px"></i><?= $trk ? date('F d, Y g:i A', strtotime($trk['submitted_at'])) : '—' ?></div>
    </div>

    <!-- Visible only when printing: no name, just date -->
    <div class="sheet-eval-by" style="margin-left:auto;text-align:right;">
        <p class="print-anon-notice">
            Evaluator identity is kept confidential to protect <?= $evaluatorNoun ?> privacy.<br>
            Date submitted: <?= $trk ? date('F d, Y', strtotime($trk['submitted_at'])) : '—' ?>
        </p>
    </div>
</div>

<!-- RATING SCALE -->
<div class="scale-bar no-print">
    <span style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-right:6px;">Rating Scale:</span>
    <?php foreach ($scaleItems as $n => $lbl): ?>
    <div class="scale-item">
        <div class="scale-dot" style="background:<?= $scaleColors[$n] ?>"><?= $n ?></div> <?= $lbl ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- QUESTIONS + RATINGS -->
<?php $qNum = 1; foreach ($grouped_ans as $cat => $qs): ?>
<div class="cat-section">
    <div class="cat-title"><i class="fa-solid fa-layer-group" style="font-size:10px"></i> <?= htmlspecialchars($cat) ?></div>
    <table class="q-table">
        <thead><tr>
            <th style="width:40px">No.</th>
            <th>Statement / Question</th>
            <th class="rating-col">Rating</th>
        </tr></thead>
        <tbody>
        <?php foreach ($qs as $q):
            $s = $q['answer_score']; $sc = scoreColor($s); $sl = scoreLabel($s); ?>
        <tr>
            <td class="q-num"><?= $qNum++ ?></td>
            <td><?= htmlspecialchars($q['question_text']) ?></td>
            <td class="rating-cell">
                <div class="rating-badge" style="border:1px solid <?= $sc ?>33">
                    <span class="rating-num" style="color:<?= $sc ?>"><?= $s !== null ? intval($s) : '—' ?></span>
                    <span class="rating-lbl" style="color:<?= $sc ?>"><?= $sl ?></span>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endforeach; ?>

<?php if (empty($answers)): ?>
<div style="text-align:center;padding:40px;color:var(--muted);background:var(--mid);border-radius:12px;border:1px solid var(--border);">
    <i class="fa-solid fa-clipboard-question" style="font-size:32px;opacity:.3;display:block;margin-bottom:12px;"></i>
    No answers found for this evaluation.
</div>
<?php endif; ?>

<!-- COMMENTS & SUGGESTIONS -->
<div class="comment-section">
    <div class="comment-title"><i class="fa-solid fa-comment-dots"></i> Comments, Suggestions &amp; Areas for Improvement</div>
    <?php if (!empty($remark)): ?>
    <div class="comment-text">"<?= nl2br(htmlspecialchars($remark)) ?>"</div>
    <?php else: ?>
    <div class="no-comment">No comments or suggestions were provided.</div>
    <?php endif; ?>
</div>

<!-- AVERAGE SUMMARY -->
<?php if ($avg !== null): ?>
<div class="avg-summary">
    <div>
        <div class="avg-score-big" style="color:<?= scoreColor($avg) ?>"><?= number_format($avg,2) ?></div>
        <div class="avg-score-label" style="color:<?= scoreColor($avg) ?>"><?= scoreLabel($avg) ?></div>
        <div class="avg-out-of">out of 5.00</div>
    </div>
    <div class="avg-breakdown">
        <?php foreach ($scaleItems as $n => $lbl):
            $cnt = count(array_filter($scores, fn($s) => intval(round($s)) === $n));
            $pct = count($scores) ? round(($cnt/count($scores))*100) : 0;
        ?>
        <div class="avg-bar-row">
            <div class="avg-bar-label"><?= $n ?> — <?= $lbl ?></div>
            <div class="avg-bar-bg"><div class="avg-bar-fill" style="width:<?= $pct ?>%;background:<?= $scaleColors[$n] ?>"></div></div>
            <div class="avg-bar-val" style="color:<?= $scaleColors[$n] ?>"><?= $cnt ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="text-align:center;">
        <div style="font-size:12px;color:var(--muted);margin-bottom:4px;">Based on</div>
        <div style="font-family:'Rajdhani',sans-serif;font-size:28px;font-weight:700;color:var(--light);"><?= count($scores) ?></div>
        <div style="font-size:12px;color:var(--muted);">question<?= count($scores)!==1?'s':'' ?></div>
    </div>
</div>
<?php endif; ?>

</div><!-- /.individual-sheet -->

<!-- CUMULATIVE REPORT — this is what prints / shows in "Generate Report".
     Every question shows the average rating across ALL evaluators, with the
     total (overall cumulative) score under the last question. -->
<div class="cumulative-sheet">
    <div class="cum-topbar">
        <span class="cum-type"><?= htmlspecialchars($evalLabel) ?></span>
        <span class="cum-by">Evaluated by: <strong><?= htmlspecialchars($cumEvaluatedBy) ?></strong></span>
    </div>

    <div class="sheet-header">
        <?php if ($tgt['photo']): ?><img class="sheet-avatar" src="../image/<?= htmlspecialchars($tgt['photo']) ?>" alt=""/>
        <?php else: ?><div class="sheet-avatar-ph"><i class="fa-solid fa-user"></i></div><?php endif; ?>
        <div>
            <div class="sheet-name"><?= htmlspecialchars($tgt['full_name']) ?></div>
            <div class="sheet-desig"><?= htmlspecialchars($tgt['designation']) ?> · <?= $cumRoleText ?></div>
        </div>
        <div class="cum-date">
            <div class="cum-date-label">Date Evaluated</div>
            <div class="cum-date-val"><?= nl2br(htmlspecialchars($cumDateText)) ?></div>
        </div>
    </div>

    <?php $cumNum = 1; foreach ($cumByCat as $cat => $cRows): ?>
    <div class="cat-section">
        <div class="cat-title"><i class="fa-solid fa-layer-group" style="font-size:10px"></i> <?= htmlspecialchars($cat) ?></div>
        <table class="q-table">
            <thead><tr>
                <th style="width:40px">No.</th>
                <th>Statement / Question</th>
                <th class="rating-col" style="width:150px">Avg. Rating</th>
            </tr></thead>
            <tbody>
            <?php foreach ($cRows as $cRow):
                $cs = $cRow['avg_score']; ?>
            <tr>
                <td class="q-num"><?= $cumNum++ ?></td>
                <td><?= htmlspecialchars($cRow['question_text']) ?></td>
                <td class="rating-cell">
                    <div class="rating-badge">
                        <span class="rating-num"><?= number_format($cs, 2) ?></span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <?php if (empty($cumByCat)): ?>
    <div class="cum-empty">No answers found for this evaluation.</div>
    <?php endif; ?>

    <?php if ($cumOverall !== null): ?>
    <!-- RATING LEGEND: same card style as Total Score, placed right before it -->
    <div class="cum-total cum-legend">
        <div class="cum-total-label">Rating Legend</div>
        <div class="cum-legend-rows">
            <?php foreach ([
                ['4.50 &ndash; 5.00', 'Very Satisfied',                     '#32B98A'],
                ['3.50 &ndash; 4.49', 'Satisfied',                          '#6BCFA9'],
                ['2.50 &ndash; 3.49', 'Neither Satisfied nor Dissatisfied', '#facc15'],
                ['1.50 &ndash; 2.49', 'Dissatisfied',                       '#fb923c'],
                ['1.00 &ndash; 1.49', 'Very Dissatisfied',                  '#E6788A'],
            ] as $lg): ?>
            <div class="cum-legend-row">
                <span class="cum-legend-dot" style="--c:<?= $lg[2] ?>"></span>
                <span class="cum-legend-range"><?= $lg[0] ?></span>
                <span class="cum-legend-text"><?= $lg[1] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- TOTAL SCORE: sits directly under the last question -->
    <div class="cum-total">
        <div class="cum-total-label">Total Score</div>
        <div class="cum-total-result">
            <div class="avg-score-big"><?= number_format($cumOverall, 2) ?></div>
            <div class="avg-score-label"><?= scoreSatisfactionLabel($cumOverall) ?></div>
            <div class="avg-out-of">Overall cumulative rating · out of 5.00</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="cum-comments-page">
    <div class="comment-section">
        <div class="comment-title"><i class="fa-solid fa-comment-dots"></i> Comments, Suggestions &amp; Areas for Improvement</div>
        <?php if (!empty($cumRemarks)): foreach ($cumRemarks as $cr): ?>
        <div class="cum-remark">"<?= nl2br(htmlspecialchars($cr)) ?>"</div>
        <?php endforeach; else: ?>
        <div class="no-comment">No comments or suggestions were provided.</div>
        <?php endif; ?>
    </div>
    </div><!-- /.cum-comments-page -->
</div><!-- /.cumulative-sheet -->

</main>

<style id="executive-original-theme">
/* Scoped to html.dark-theme: in light mode the sheet follows the light report design. */
html.dark-theme{
 --dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;
 --violet:#7C5FD9;--violet-h:#9C85F0;--violet-dark:#5F45B8;
 --light:#E0E6F0;--muted:#A0B3C6;--border:rgba(255,255,255,.08);
 --accent:#7C5FD9;--accent-h:#9C85F0;--good:#10B981;--danger:#f05454;
 --page-bg:#0A192F;--card-bg:rgba(23,42,69,.85);--card-border:rgba(255,255,255,.08);
}
html.dark-theme{background:var(--dark)!important;color-scheme:dark!important;}
html.dark-theme body{background:linear-gradient(rgba(5,18,36,.72),rgba(5,18,36,.82)),url('../background.png') center center/cover no-repeat fixed!important;background-color:var(--dark)!important;color:var(--light)!important;}
html.dark-theme .main,
html.dark-theme .content,
html.dark-theme .page-content{color:var(--light)!important;}
html.dark-theme .page-title,
html.dark-theme .page-header h1,
html.dark-theme .section-title,
html.dark-theme .sheet-name,
html.dark-theme .target-name{color:#fff!important;}
html.dark-theme .page-sub,
html.dark-theme .sheet-desig,
html.dark-theme .target-desig,
html.dark-theme .muted,
html.dark-theme .hint,
html.dark-theme .helper,
html.dark-theme .description,
html.dark-theme p{color:var(--muted)!important;}
/* Cards/panels */
html.dark-theme .card,
html.dark-theme .panel,
html.dark-theme .section,
html.dark-theme .table-card,
html.dark-theme .content-card,
html.dark-theme .stat-card,
html.dark-theme .sum-card,
html.dark-theme .standing-panel,
html.dark-theme .eval-card,
html.dark-theme .eval-banner,
html.dark-theme .info-banner,
html.dark-theme .history-card,
html.dark-theme .gl-card,
html.dark-theme .amber-card,
html.dark-theme .green-card,
html.dark-theme .red-card,
html.dark-theme .person-row,
html.dark-theme .target-card,
html.dark-theme .no-eval,
html.dark-theme .no-data,
html.dark-theme .no-evaluated,
html.dark-theme .comment-section,
html.dark-theme .avg-summary,
html.dark-theme .cat-section,
html.dark-theme .people-list,
html.dark-theme .evaluator-grid{
 background:rgba(23,42,69,.85)!important;border-color:rgba(255,255,255,.08)!important;box-shadow:0 8px 32px rgba(0,0,0,.45)!important;color:var(--light)!important;
}
html.dark-theme .table-wrap{background:transparent!important;color:var(--light)!important;}
html.dark-theme table.data th,
html.dark-theme table.data td,
html.dark-theme th,
html.dark-theme td{color:var(--light)!important;border-color:rgba(255,255,255,.08)!important;}
html.dark-theme .q-table{color:var(--light)!important;}
html.dark-theme .q-table th{color:var(--muted)!important;background:rgba(15,31,61,.65)!important;border-color:rgba(255,255,255,.08)!important;}
html.dark-theme .q-table td{color:var(--light)!important;border-color:rgba(255,255,255,.06)!important;}
/* Tabs and filters */
html.dark-theme .eval-switcher,
html.dark-theme .tabs,
html.dark-theme .level-tabs,
html.dark-theme .status-tabs{background:rgba(23,42,69,.85)!important;border-color:rgba(255,255,255,.08)!important;box-shadow:none!important;}
html.dark-theme .eval-tab,
html.dark-theme .tab,
html.dark-theme .level-tab,
html.dark-theme .status-tab,
html.dark-theme .group-tab,
html.dark-theme .desig-subtab{color:var(--muted)!important;background:transparent!important;}
html.dark-theme .eval-tab:hover,
html.dark-theme .tab:hover,
html.dark-theme .level-tab:hover,
html.dark-theme .status-tab:hover,
html.dark-theme .group-tab:hover,
html.dark-theme .desig-subtab:hover{color:#fff!important;background:rgba(124,95,217,.08)!important;}
html.dark-theme .eval-tab.student.active,
html.dark-theme .group-tab.active,
html.dark-theme .desig-subtab.active{background:rgba(124,95,217,.14)!important;color:var(--violet-h)!important;border-color:rgba(124,95,217,.35)!important;}
html.dark-theme .eval-tab.student.active::after{background:var(--violet)!important;}
html.dark-theme .eval-tab.peer.active{background:rgba(124,95,217,.14)!important;color:var(--violet-h)!important;}
html.dark-theme .eval-tab.peer.active::after{background:var(--violet)!important;}
html.dark-theme .tab-badge{background:rgba(255,255,255,.08)!important;color:var(--muted)!important;}
html.dark-theme .eval-tab.student.active .tab-badge,
html.dark-theme .eval-tab.peer.active .tab-badge{background:rgba(124,95,217,.18)!important;color:var(--violet-h)!important;}
/* Buttons/links */
html.dark-theme .btn-print,
html.dark-theme .btn-print.no-print,
html.dark-theme .back-btn,
html.dark-theme .btn,
html.dark-theme .action-btn,
html.dark-theme .btn-archive,
html.dark-theme .btn-restore,
html.dark-theme .btn-solid,
html.dark-theme .btn-archived-link{background:rgba(124,95,217,.12)!important;border:1px solid rgba(124,95,217,.35)!important;color:var(--violet-h)!important;}
html.dark-theme .btn-print:hover,
html.dark-theme .back-btn:hover,
html.dark-theme .btn:hover,
html.dark-theme .action-btn:hover,
html.dark-theme .btn-archive:hover,
html.dark-theme .btn-restore:hover,
html.dark-theme .btn-solid:hover,
html.dark-theme .btn-archived-link:hover{background:rgba(124,95,217,.22)!important;color:#fff!important;}
html.dark-theme .btn-solid{background:var(--violet)!important;color:#fff!important;}
/* accents */
html.dark-theme .eval-banner{background:rgba(124,95,217,.08)!important;border-color:rgba(124,95,217,.25)!important;}
html.dark-theme .eval-banner-title,
html.dark-theme .eval-banner-icon,
html.dark-theme .cat-title,
html.dark-theme .comment-title,
html.dark-theme .section h2 i,
html.dark-theme .section h2{color:var(--violet-h)!important;}
html.dark-theme .period-badge{background:rgba(124,95,217,.14)!important;border-color:rgba(124,95,217,.3)!important;color:var(--violet-h)!important;}
html.dark-theme .bar-fill,
html.dark-theme .avg-bar-fill{background:linear-gradient(90deg,var(--violet-dark),var(--violet-h))!important;}
html.dark-theme .score-bar-bg,
html.dark-theme .eval-bar-bg,
html.dark-theme .avg-bar-bg,
html.dark-theme .bar-wrap{background:rgba(255,255,255,.08)!important;}
html.dark-theme .standing-title.top,
html.dark-theme .standing-score,
html.dark-theme .pstat-val,
html.dark-theme .avg-score-big,
html.dark-theme .avg-score-label{color:#4ade80!important;}
html.dark-theme .standing-title.low{color:#f87171!important;}
html.dark-theme .person-row:hover,
html.dark-theme .standing-item:hover{border-color:rgba(124,95,217,.4)!important;background:rgba(124,95,217,.05)!important;}
html.dark-theme .comment-text{background:rgba(15,31,61,.7)!important;color:var(--light)!important;}
html.dark-theme .empty-note,
html.dark-theme .no-comment,
html.dark-theme .no-eval{color:var(--muted)!important;}
html.dark-theme label,
html.dark-theme th{color:var(--muted)!important;}
html.dark-theme input,
html.dark-theme select,
html.dark-theme textarea{background:#0F1F3D!important;color:var(--light)!important;border-color:rgba(255,255,255,.12)!important;}
html.dark-theme input::placeholder,
html.dark-theme textarea::placeholder{color:#7890a8!important;}
</style>
<style id="dean-sheet-dark-final">
/* Scoped to html.dark-theme: in light mode the sheet follows the light report design. */
/* Final dark theme for the individual evaluation sheet.  The Dean portal
   uses the navy/violet workspace, so the report sheet follows that theme
   instead of reverting to the older white report design. */
html.dark-theme,
html.dark-theme body{
  background:#0A192F !important;
  color:#E0E6F0 !important;
  color-scheme:dark !important;
}
html.dark-theme .main{
  background:transparent !important;
  color:#E0E6F0 !important;
}
html.dark-theme .individual-sheet,
html.dark-theme .sheet-header,
html.dark-theme .scale-bar,
html.dark-theme .cat-section,
html.dark-theme .comment-section,
html.dark-theme .avg-summary{
  background:rgba(23,42,69,.85) !important;
  border-color:rgba(255,255,255,.08) !important;
  box-shadow:0 8px 32px rgba(0,0,0,.28) !important;
  color:#E0E6F0 !important;
}
html.dark-theme .sheet-header{
  border-radius:14px !important;
}
html.dark-theme .sheet-name,
html.dark-theme .avg-score-big,
html.dark-theme .avg-score-label{
  color:#fff !important;
}
html.dark-theme .sheet-desig,
html.dark-theme .eval-by-date,
html.dark-theme .avg-out-of,
html.dark-theme .no-comment,
html.dark-theme .scale-item,
html.dark-theme .scale-bar > span,
html.dark-theme .eval-by-label,
html.dark-theme .avg-bar-label{
  color:#A0B3C6 !important;
}
html.dark-theme .eval-by-name{ color:#C4B5FD !important; }
html.dark-theme .sheet-avatar-ph{
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.10) !important;
  color:#A0B3C6 !important;
}
html.dark-theme .eval-type-chip{
  background:rgba(124,95,217,.14) !important;
  border-color:rgba(124,95,217,.35) !important;
  color:#C4B5FD !important;
}
html.dark-theme .scale-bar{ align-items:center; }
html.dark-theme .scale-dot{ box-shadow:0 2px 8px rgba(0,0,0,.18); }
html.dark-theme .cat-title,
html.dark-theme .comment-title{
  color:#C4B5FD !important;
  border-color:rgba(124,95,217,.30) !important;
}
html.dark-theme .q-table{
  background:#172A45 !important;
  border-color:rgba(255,255,255,.08) !important;
  color:#E0E6F0 !important;
}
html.dark-theme .q-table thead tr,
html.dark-theme .q-table thead th{
  background:#0F1F3D !important;
}
html.dark-theme .q-table th{
  color:#A0B3C6 !important;
  border-color:rgba(255,255,255,.08) !important;
}
html.dark-theme .q-table td{
  background:#172A45 !important;
  color:#E0E6F0 !important;
  border-color:rgba(255,255,255,.06) !important;
}
html.dark-theme .q-table tr:hover td{
  background:rgba(124,95,217,.07) !important;
}
html.dark-theme .q-table td.q-num{ color:#8EA5BB !important; }
html.dark-theme .rating-badge{
  background:#0F1F3D !important;
  border-radius:8px !important;
}
html.dark-theme .comment-text{
  background:#0F1F3D !important;
  color:#E0E6F0 !important;
  border-color:rgba(255,255,255,.08) !important;
}
html.dark-theme .avg-bar-bg{
  background:rgba(255,255,255,.08) !important;
}
html.dark-theme .avg-bar-val{ color:#E0E6F0 !important; }
html.dark-theme .detail-actions .back-btn,
html.dark-theme .back-btn{
  background:#0F1F3D !important;
  color:#E0E6F0 !important;
  border-color:rgba(255,255,255,.14) !important;
}
html.dark-theme .detail-actions .back-btn:hover,
html.dark-theme .back-btn:hover{
  background:rgba(124,95,217,.22) !important;
  color:#fff !important;
}
html.dark-theme .btn-print,
html.dark-theme .detail-actions .btn-print{
  background:#7C5FD9 !important;
  color:#fff !important;
}
html.dark-theme .btn-print:hover{ opacity:.9; }
html.dark-theme .individual-sheet > div[style*="No answers found"]{
  background:#172A45 !important;
  color:#A0B3C6 !important;
  border-color:rgba(255,255,255,.08) !important;
}
@media print {
  html, body, .main { background:#fff !important; color:#000 !important; }
  .sheet-header,.scale-bar,.cat-section,.comment-section,.avg-summary,.q-table { box-shadow:none !important; }
  .q-table td,.q-table th { color:#000 !important; }
}
</style>

<!-- CUMULATIVE REPORT + PRINT LAYOUT. Kept at the very end of the document so
     neither the dark/light theme blocks above nor dean_light_theme.css can
     override it. Selectors are scoped to .cumulative-sheet. -->
<style id="dean-cumulative-report">
.cumulative-sheet{display:none;}
body.report-mode .individual-sheet{display:none!important;}
body.report-mode .cumulative-sheet{display:block!important;}
body.report-mode .eval-type-chip{display:none!important;}

html body .cumulative-sheet,
html body .cumulative-sheet *{color:#000!important;}
html body .cumulative-sheet{background:#fff!important;font-family:'Inter',sans-serif;}
html body .cumulative-sheet .cum-topbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;font-size:12px;margin:0 0 10px;padding:8px 14px;border:1px solid #ddd!important;background:#f5f5f5!important;border-radius:0!important;box-shadow:none!important;}
html body .cumulative-sheet .cum-type{font-weight:700;text-transform:uppercase;letter-spacing:.8px;}
html body .cumulative-sheet .sheet-header{display:flex;align-items:center;flex-wrap:wrap;gap:12px!important;padding:12px 16px!important;margin:0 0 10px!important;background:#fff!important;border:1px solid #ddd!important;border-radius:0!important;box-shadow:none!important;}
html body .cumulative-sheet .sheet-avatar,
html body .cumulative-sheet .sheet-avatar-ph{width:46px!important;height:46px!important;font-size:20px;}
html body .cumulative-sheet .sheet-avatar-ph{background:#f1f5f9!important;}
html body .cumulative-sheet .sheet-name{font-size:18px!important;font-family:'Rajdhani',sans-serif;font-weight:700;}
html body .cumulative-sheet .sheet-desig{font-size:11px!important;}
html body .cumulative-sheet .cum-date{margin-left:auto;text-align:right;}
html body .cumulative-sheet .cum-date-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-bottom:2px;}
html body .cumulative-sheet .cum-date-val{font-size:14px;font-weight:700;}

html body .cumulative-sheet .cat-section{margin:0 0 12px!important;background:transparent!important;border:0!important;box-shadow:none!important;}
html body .cumulative-sheet .cat-title{display:flex;align-items:center;gap:7px;margin:0 0 6px!important;padding:0 0 3px!important;background:none!important;border:0!important;border-bottom:1px solid #ddd!important;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;}
html body .cumulative-sheet .q-table{width:100%;border-collapse:collapse;background:#fff!important;border:1px solid #ddd!important;border-radius:0!important;box-shadow:none!important;}
html body .cumulative-sheet .q-table thead tr{background:#f5f5f5!important;}
html body .cumulative-sheet .q-table th{padding:6px 10px!important;background:#f5f5f5!important;border:0!important;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;text-align:left;}
html body .cumulative-sheet .q-table th.rating-col{text-align:center;}
html body .cumulative-sheet .q-table td{padding:6px 10px!important;background:#fff!important;border:0!important;border-top:1px solid #eee!important;font-size:13px;line-height:1.25!important;vertical-align:middle!important;}
html body .cumulative-sheet .q-table td.q-num{width:40px;font-weight:700;padding-top:6px!important;}
html body .cumulative-sheet .q-table td.rating-cell{text-align:center;padding-top:6px!important;}
html body .cumulative-sheet .q-table tr:hover td{background:#fff!important;}
/* every rating badge is the same shape, whatever the label */
html body .cumulative-sheet .rating-badge{display:inline-flex;flex-direction:column;justify-content:center;align-items:center;width:96px;height:30px;box-sizing:border-box;padding:2px 6px!important;gap:0!important;background:#fff!important;border:1px solid #ccc!important;border-radius:8px;}
html body .cumulative-sheet .rating-num{font-size:14px!important;font-weight:700;line-height:1.1;}
html body .cumulative-sheet .rating-lbl{font-size:8px!important;font-weight:600;text-transform:uppercase;letter-spacing:.5px;text-align:center;line-height:1.1;}

/* Total Score: label left, overall result right under the rating column */
html body .cumulative-sheet .cum-total{display:flex;flex-direction:column;justify-content:flex-start;align-items:flex-start;text-align:left;gap:8px;margin:0 0 12px;padding:12px 14px;background:#fff!important;border:1px solid #ddd!important;border-radius:0!important;box-shadow:none!important;}
html body .cumulative-sheet .cum-total-label{font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:1px;}
html body .cumulative-sheet .cum-total-result{text-align:left;margin:0;}
html body .cumulative-sheet .cum-legend-rows{display:flex;flex-direction:column;gap:5px;min-width:340px;}
html body .cumulative-sheet .cum-legend-row{display:flex;align-items:center;gap:10px;font-size:13px;}
html body .cumulative-sheet .cum-legend-dot{width:12px;height:12px;border-radius:3px;flex-shrink:0;background-color:var(--c)!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
html body .cumulative-sheet .cum-legend-range{font-weight:700;width:92px;flex-shrink:0;}
html body .cumulative-sheet .cum-legend-text{flex:1;text-align:left;}
@media(max-width:640px){html body .cumulative-sheet .cum-legend-rows{min-width:0;width:100%;}}
html body .cumulative-sheet .avg-score-big{font-family:'Rajdhani',sans-serif;font-size:42px!important;font-weight:700;line-height:1;}
html body .cumulative-sheet .avg-score-label{font-size:14px;font-weight:700;margin-top:4px;}
html body .cumulative-sheet .avg-out-of{font-size:13px;margin-top:6px;}

html body .cumulative-sheet .comment-section{margin:0 0 12px!important;padding:10px 14px!important;background:#fff!important;border:1px solid #ddd!important;border-radius:0!important;box-shadow:none!important;}
html body .cumulative-sheet .comment-title{display:flex;align-items:center;gap:8px;margin:0 0 5px!important;padding:0!important;background:none!important;border:0!important;font-size:13px;font-weight:700;}
html body .cumulative-sheet .cum-remark{font-size:13px;line-height:1.4;font-style:italic;margin-bottom:5px;}
html body .cumulative-sheet .no-comment{font-size:13px;font-style:italic;}
html body .cumulative-sheet .cum-empty{text-align:center;padding:24px;font-style:italic;border:1px solid #ddd;margin-bottom:12px;}

/* Print: page margins + show only the cumulative report.
   margin:0 on @page + real padding on body, instead of relying on @page
   margins alone — some browsers' print dialogs (e.g. "Margins: None")
   ignore @page margins entirely, printing content flush to the edge of
   the paper. Padding on body can't be overridden that way, so this
   guarantees a safe margin regardless of the browser's print-dialog
   margin setting. */
@page{margin:0!important;}
@media print{
  html,body{width:100%!important;height:auto!important;background:#fff!important;}
  body{display:block!important;padding:16mm 14mm!important;margin:0!important;min-height:0!important;}
  .sidebar,.sb-logout,.no-print{display:none!important;}
  /* top header bar (page title, semester chip, notification bell) must not print */
  .dean-portal-topbar,header.dean-portal-topbar,.dean-header-notif-dropdown,
  header.topbar,.portal-topbar{display:none!important;visibility:hidden!important;height:0!important;margin:0!important;padding:0!important;border:0!important;}
  html body .cumulative-sheet .rating-lbl{display:none!important;}
  .main,main.main{margin:0!important;padding:0!important;min-height:0!important;background:#fff!important;border:0!important;border-radius:0!important;}
  .individual-sheet{display:none!important;}
  .cumulative-sheet{display:block!important;}
  html body .cumulative-sheet .cum-topbar{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  /* long tables may continue on the next page, but never split a row or strand a title */
  html body .cumulative-sheet .cat-section{break-inside:auto!important;page-break-inside:auto!important;}
  html body .cumulative-sheet .cat-title{break-after:avoid;page-break-after:avoid;}
  html body .cumulative-sheet .q-table thead{display:table-header-group;}
  html body .cumulative-sheet .q-table tr{break-inside:avoid;page-break-inside:avoid;}
  html body .cumulative-sheet .cum-total,
  html body .cumulative-sheet .cum-legend,
  html body .cumulative-sheet .comment-section{break-inside:avoid;page-break-inside:avoid;}

  /* Start the report near the top of the page: kill any offset left behind by the
     (now hidden) top header, with enough specificity to beat the theme files. */
  html body{padding-top:7mm!important;padding-bottom:10mm!important;}
  html body main.main,html body .main{margin-top:0!important;padding-top:0!important;top:0!important;}
  html body main.main::before,html body .main::before,html body::before{display:none!important;content:none!important;}
  html body .cumulative-sheet{margin-top:0!important;padding-top:0!important;}
  html body .cumulative-sheet .cum-topbar{margin-top:0!important;}

  /* Compact spacing so a full 30-question evaluation fits on one page */
  html body{padding-left:12mm!important;padding-right:12mm!important;}
  html body .cumulative-sheet .cum-topbar{margin:0 0 4px!important;padding:4px 10px!important;font-size:10px!important;}
  html body .cumulative-sheet .sheet-header{margin:0 0 4px!important;padding:6px 10px!important;gap:8px!important;}
  html body .cumulative-sheet .sheet-avatar,
  html body .cumulative-sheet .sheet-avatar-ph{width:32px!important;height:32px!important;font-size:14px!important;}
  html body .cumulative-sheet .sheet-name{font-size:14px!important;line-height:1.1!important;}
  html body .cumulative-sheet .sheet-desig{font-size:9px!important;line-height:1.2!important;}
  html body .cumulative-sheet .cum-date-label{font-size:8px!important;margin-bottom:0!important;}
  html body .cumulative-sheet .cum-date-val{font-size:11px!important;}
  html body .cumulative-sheet .cat-section{margin:0 0 5px!important;}
  html body .cumulative-sheet .cat-title{margin:0 0 3px!important;padding:0 0 2px!important;font-size:9px!important;letter-spacing:1px!important;}
  html body .cumulative-sheet .q-table th{padding:3px 8px!important;font-size:8.5px!important;}
  html body .cumulative-sheet .q-table td{padding:2px 8px!important;font-size:10.5px!important;line-height:1.15!important;}
  html body .cumulative-sheet .q-table td.q-num{padding-top:2px!important;width:30px!important;}
  html body .cumulative-sheet .q-table td.rating-cell{padding-top:2px!important;}
  html body .cumulative-sheet .rating-badge{width:62px!important;height:18px!important;padding:0 4px!important;border-radius:5px!important;}
  html body .cumulative-sheet .rating-num{font-size:11px!important;line-height:1!important;}
  html body .cumulative-sheet .cum-total{margin:0 0 5px!important;padding:6px 12px!important;gap:3px!important;}
  html body .cumulative-sheet .cum-total-label{font-size:11px!important;}
  html body .cumulative-sheet .cum-legend-rows{gap:1px!important;min-width:300px!important;}
  html body .cumulative-sheet .cum-legend-row{font-size:9.5px!important;gap:6px!important;line-height:1.2!important;}
  html body .cumulative-sheet .cum-legend-dot{width:8px!important;height:8px!important;background-color:var(--c)!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;}
  html body .cumulative-sheet .cum-legend-range{width:68px!important;}
  html body .cumulative-sheet .avg-score-big{font-size:28px!important;}
  html body .cumulative-sheet .avg-score-label{font-size:11px!important;margin-top:2px!important;}
  html body .cumulative-sheet .avg-out-of{font-size:9px!important;margin-top:2px!important;}

  /* Comments always go on their own page, never on the same page as the ratings.
     Padding (not margin) is used for the top gap because margins are dropped at a page break. */
  html body .cumulative-sheet .cum-comments-page{break-before:page!important;page-break-before:always!important;padding-top:14mm!important;margin:0!important;}
  html body .cumulative-sheet .cum-comments-page .comment-section{margin-top:0!important;}
}
</style>


<style id="dean-reports-dark-final">
/* -------------------------------------------------------------------------
   Final dark-mode layer for Dean Evaluation Reports.
   This is intentionally loaded last because this page contains several
   legacy light-theme blocks and view-specific inline styles.
   ------------------------------------------------------------------------- */
html.dark-theme body {
  background:#0A192F !important;
  background-image:none !important;
  color:#E0E6F0 !important;
}
html.dark-theme .main {
  background:#0F1F3D !important;
  color:#E0E6F0 !important;
}

/* Main analytics tabs / filters */
html.dark-theme .reports-top-tab,
html.dark-theme .reports-filter-tab {
  background:#172A45 !important;
  border-color:rgba(255,255,255,.12) !important;
  color:#A0B3C6 !important;
  box-shadow:none !important;
}
html.dark-theme .reports-top-tab:hover,
html.dark-theme .reports-filter-tab:hover {
  background:#1D3350 !important;
  color:#E0E6F0 !important;
}
html.dark-theme .reports-top-tab.active,
html.dark-theme .reports-top-tab.peer.active,
html.dark-theme .reports-filter-tab.active {
  background:rgba(124,95,217,.16) !important;
  border-color:rgba(156,133,240,.38) !important;
  color:#C4B5FD !important;
  box-shadow:none !important;
}
html.dark-theme .reports-top-badge,
html.dark-theme .reports-filter-tab b {
  background:#0F1F3D !important;
  color:#A0B3C6 !important;
}
html.dark-theme .reports-top-tab.active .reports-top-badge,
html.dark-theme .reports-filter-tab.active b {
  background:rgba(124,95,217,.22) !important;
  color:#C4B5FD !important;
}

html.dark-theme .reports-sub-filters,
html.dark-theme .reports-designation-filters,
html.dark-theme .reports-peer-note {
  background:#172A45 !important;
  border-color:rgba(255,255,255,.08) !important;
  color:#A0B3C6 !important;
}
html.dark-theme .reports-sub-label { color:#A0B3C6 !important; }
html.dark-theme .reports-sub-tab,
html.dark-theme .reports-designation-pill {
  color:#A0B3C6 !important;
}
html.dark-theme .reports-sub-tab:hover,
html.dark-theme .reports-designation-pill:hover {
  background:#1D3350 !important;
  color:#E0E6F0 !important;
}
html.dark-theme .reports-sub-tab.active,
html.dark-theme .reports-designation-pill.active {
  background:rgba(124,95,217,.16) !important;
  border-color:rgba(156,133,240,.35) !important;
  color:#C4B5FD !important;
}
html.dark-theme .reports-sub-tab b,
html.dark-theme .reports-designation-pill b {
  background:#0F1F3D !important;
  color:#A0B3C6 !important;
}

/* Report data panel */
html.dark-theme .reports-data-panel {
  background:#172A45 !important;
  border-color:rgba(255,255,255,.08) !important;
  box-shadow:0 8px 28px rgba(0,0,0,.28) !important;
}
html.dark-theme .reports-data-title h2 { color:#E0E6F0 !important; }
html.dark-theme .reports-data-title span { color:#A0B3C6 !important; }
html.dark-theme .reports-data-title i { color:#9C85F0 !important; }
html.dark-theme .reports-action-btn {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.14) !important;
  color:#E0E6F0 !important;
}
html.dark-theme .reports-action-btn:hover { background:#1D3350 !important; }
html.dark-theme .reports-search-box {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.14) !important;
}
html.dark-theme .reports-search-box i { color:#8EA5BB !important; }
html.dark-theme .reports-search-box input {
  background:transparent !important;
  color:#E0E6F0 !important;
}
html.dark-theme .reports-search-box input::placeholder { color:#6B84A0 !important; }
html.dark-theme .reports-active-filter-note {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.08) !important;
  color:#A0B3C6 !important;
}
html.dark-theme .reports-active-filter-note button { color:#C4B5FD !important; }

/* Main report table */
html.dark-theme .reports-table th {
  background:#0F1F3D !important;
  color:#A0B3C6 !important;
  border-top-color:rgba(255,255,255,.08) !important;
  border-bottom-color:rgba(255,255,255,.10) !important;
}
html.dark-theme .reports-table td {
  background:#172A45 !important;
  color:#E0E6F0 !important;
  border-bottom-color:rgba(255,255,255,.08) !important;
}
html.dark-theme .reports-table tbody tr:hover td {
  background:#1D3350 !important;
}
html.dark-theme .reports-person-name { color:#E0E6F0 !important; }
html.dark-theme .reports-designation-cell { color:#B6C6D6 !important; }
html.dark-theme .reports-person-photo { border-color:rgba(255,255,255,.12) !important; }
html.dark-theme .reports-person-photo.placeholder {
  background:#0F1F3D !important;
  color:#8EA5BB !important;
}
html.dark-theme .reports-role-badge.faculty {
  background:rgba(37,99,216,.16) !important;
  border-color:rgba(96,165,250,.32) !important;
  color:#93C5FD !important;
}
html.dark-theme .reports-role-badge.staff,
html.dark-theme .reports-role-badge.dean {
  background:rgba(124,95,217,.16) !important;
  border-color:rgba(156,133,240,.32) !important;
  color:#C4B5FD !important;
}
html.dark-theme .reports-role-badge.principal {
  background:rgba(217,119,6,.16) !important;
  border-color:rgba(217,119,6,.32) !important;
  color:#FBBF24 !important;
}
html.dark-theme .reports-score.high { color:#4ADE80 !important; }
html.dark-theme .reports-score.mid { color:#FBBF24 !important; }
html.dark-theme .reports-score.low { color:#FCA5A5 !important; }
html.dark-theme .reports-date { color:#A0B3C6 !important; }
html.dark-theme .reports-view-btn {
  background:rgba(59,130,246,.14) !important;
  border-color:rgba(96,165,250,.32) !important;
  color:#93C5FD !important;
}
html.dark-theme .reports-view-btn:hover { background:rgba(59,130,246,.22) !important; }
html.dark-theme .reports-archive-btn {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.14) !important;
  color:#A0B3C6 !important;
}
html.dark-theme .reports-archive-btn:hover { background:#1D3350 !important; color:#E0E6F0 !important; }
html.dark-theme .reports-empty-state,
html.dark-theme .reports-no-search { color:#A0B3C6 !important; }
html.dark-theme .reports-empty-state h3 { color:#E0E6F0 !important; }
html.dark-theme .reports-empty-state i { color:#71879C !important; }
html.dark-theme .reports-empty-state p { color:#A0B3C6 !important; }
html.dark-theme .reports-live-status {
  background:rgba(16,185,129,.14) !important;
  border-color:rgba(16,185,129,.32) !important;
  color:#4ADE80 !important;
}
html.dark-theme .reports-live-status.stale {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.10) !important;
  color:#8EA5BB !important;
}

/* Shared report/detail controls */
html.dark-theme .back-btn,
html.dark-theme .detail-back,
html.dark-theme .btn-archived-link {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.14) !important;
  color:#E0E6F0 !important;
}
html.dark-theme .back-btn:hover,
html.dark-theme .detail-back:hover,
html.dark-theme .btn-archived-link:hover {
  background:#1D3350 !important;
  color:#C4B5FD !important;
}

/* Detail / individual evaluation view */
html.dark-theme .detail-eval-banner {
  background:rgba(59,130,246,.10) !important;
  border-color:rgba(96,165,250,.24) !important;
}
html.dark-theme .detail-eval-icon {
  background:rgba(59,130,246,.14) !important;
  border-color:rgba(96,165,250,.28) !important;
  color:#93C5FD !important;
}
html.dark-theme .detail-eval-title { color:#93C5FD !important; }
html.dark-theme .detail-eval-sub { color:#A0B3C6 !important; }
html.dark-theme .detail-person-card,
html.dark-theme .detail-results-panel,
html.dark-theme .detail-legend {
  background:#172A45 !important;
  border-color:rgba(255,255,255,.08) !important;
  box-shadow:0 8px 28px rgba(0,0,0,.25) !important;
}
html.dark-theme .detail-person-avatar.ph {
  background:#0F1F3D !important;
  color:#8EA5BB !important;
}
html.dark-theme .detail-person-name { color:#E0E6F0 !important; }
html.dark-theme .detail-person-desig,
html.dark-theme .detail-stat-label,
html.dark-theme .detail-legend-text,
html.dark-theme .detail-results-title span,
html.dark-theme .detail-year-filter label { color:#A0B3C6 !important; }
html.dark-theme .detail-stat-value { color:#93C5FD !important; }
html.dark-theme .detail-stat-value.score { color:#4ADE80 !important; }
html.dark-theme .detail-legend-title { color:#E0E6F0 !important; }
html.dark-theme .detail-year-filter select {
  background:#0F1F3D !important;
  color:#E0E6F0 !important;
  border-color:rgba(255,255,255,.14) !important;
}
html.dark-theme .detail-results-title h2 { color:#E0E6F0 !important; }
html.dark-theme .detail-results-title i { color:#9C85F0 !important; }
html.dark-theme .detail-results-table th {
  background:#0F1F3D !important;
  color:#A0B3C6 !important;
  border-bottom-color:rgba(255,255,255,.10) !important;
}
html.dark-theme .detail-results-table td {
  background:#172A45 !important;
  color:#E0E6F0 !important;
  border-bottom-color:rgba(255,255,255,.08) !important;
}
html.dark-theme .detail-results-table tbody tr:hover td { background:#1D3350 !important; }
html.dark-theme .detail-evaluator,
html.dark-theme .detail-date,
html.dark-theme .detail-role { color:#B8C7D7 !important; }
html.dark-theme .detail-rating {
  background:rgba(16,185,129,.14) !important;
  color:#4ADE80 !important;
}
html.dark-theme .detail-view-btn {
  background:rgba(59,130,246,.14) !important;
  border-color:rgba(96,165,250,.32) !important;
  color:#93C5FD !important;
}
html.dark-theme .detail-view-btn:hover { background:rgba(59,130,246,.22) !important; }
html.dark-theme .detail-empty { color:#A0B3C6 !important; }
html.dark-theme .detail-empty h3 { color:#E0E6F0 !important; }
html.dark-theme .detail-empty p { color:#A0B3C6 !important; }

/* Individual sheet view */
html.dark-theme .sheet-header,
html.dark-theme .scale-bar,
html.dark-theme .comment-section,
html.dark-theme .avg-summary {
  background:#172A45 !important;
  border-color:rgba(255,255,255,.08) !important;
  color:#E0E6F0 !important;
}
html.dark-theme .sheet-name,
html.dark-theme .avg-score-big,
html.dark-theme .avg-score-label { color:#E0E6F0 !important; }
html.dark-theme .sheet-desig,
html.dark-theme .eval-by-label,
html.dark-theme .eval-by-date,
html.dark-theme .avg-out-of,
html.dark-theme .scale-item,
html.dark-theme .avg-bar-label { color:#A0B3C6 !important; }
html.dark-theme .eval-by-name { color:#93C5FD !important; }
html.dark-theme .sheet-avatar-ph {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.12) !important;
  color:#8EA5BB !important;
}
html.dark-theme .eval-type-chip {
  background:rgba(59,130,246,.12) !important;
  border-color:rgba(96,165,250,.28) !important;
  color:#93C5FD !important;
}
html.dark-theme .q-table {
  background:#172A45 !important;
  border-color:rgba(255,255,255,.08) !important;
}
html.dark-theme .q-table thead tr,
html.dark-theme .q-table th {
  background:#0F1F3D !important;
  color:#A0B3C6 !important;
}
html.dark-theme .q-table td {
  background:#172A45 !important;
  color:#E0E6F0 !important;
  border-top-color:rgba(255,255,255,.08) !important;
}
html.dark-theme .q-table tr:hover td { background:#1D3350 !important; }
html.dark-theme .rating-badge {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.08) !important;
  color:#E0E6F0 !important;
}
html.dark-theme .comment-text { color:#D7E0E9 !important; }
html.dark-theme .avg-bar-bg { background:rgba(255,255,255,.08) !important; }

/* Archived personnel */
html.dark-theme .person-row,
html.dark-theme .no-archived {
  background:#172A45 !important;
  border-color:rgba(255,255,255,.08) !important;
  color:#E0E6F0 !important;
}
html.dark-theme .person-name { color:#E0E6F0 !important; }
html.dark-theme .person-meta { color:#A0B3C6 !important; }
html.dark-theme .person-photo-ph {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.12) !important;
  color:#8EA5BB !important;
}
html.dark-theme .archived-badge {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.10) !important;
  color:#A0B3C6 !important;
}
html.dark-theme .btn-restore {
  background:rgba(124,95,217,.16) !important;
  border:1px solid rgba(156,133,240,.35) !important;
  color:#C4B5FD !important;
}
html.dark-theme .btn-restore:hover { background:rgba(124,95,217,.24) !important; }

/* Keep dark-theme scrollbars readable */
html.dark-theme ::-webkit-scrollbar-track { background:#0F1F3D !important; }
html.dark-theme ::-webkit-scrollbar-thumb {
  background:#2A4468 !important;
  border:2px solid #0F1F3D !important;
}
</style>

<style id="dean-report-page-dark-v5">
/* Scoped to html.dark-theme: in light mode the sheet follows the light report design. */
/* Whole report page is dark on screen: html, body, the .main workspace and every
   surface/gap around and inside the evaluation sheet. No dependency on the
   html.dark-theme class. Print output is untouched (screen only). */
@media screen {
  html.dark-theme:has(body.report-mode),
html.dark-theme body.report-mode{
    background:#0A192F !important;
    color:#E0E6F0 !important;
    color-scheme:dark !important;
  }
  html.dark-theme body.report-mode{
    background:linear-gradient(rgba(5,18,36,.72),rgba(5,18,36,.82)),url('../background.png') center center/cover no-repeat fixed !important;
    background-color:#0A192F !important;
  }
  html.dark-theme body.report-mode .main{
    background:#0A192F !important;
    color:#E0E6F0 !important;
    border-color:rgba(255,255,255,.08) !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet,
html.dark-theme body.report-mode .individual-sheet{
    background:#0A192F !important;
    box-shadow:none !important;
    border-color:rgba(255,255,255,.08) !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet *,
html.dark-theme body.report-mode .individual-sheet *{ color:#E0E6F0 !important; }
  html.dark-theme body.report-mode .cumulative-sheet .cum-topbar,
html.dark-theme body.report-mode .cumulative-sheet .cum-topbar *{ background:#0F1F3D !important; }
  html.dark-theme body.report-mode .cumulative-sheet .sheet-header,
html.dark-theme body.report-mode .cumulative-sheet .cum-total,
html.dark-theme body.report-mode .cumulative-sheet .comment-section,
html.dark-theme body.report-mode .cumulative-sheet .cum-empty,
html.dark-theme body.report-mode .cumulative-sheet .q-table,
html.dark-theme body.report-mode .cumulative-sheet .q-table tbody,
html.dark-theme body.report-mode .cumulative-sheet .q-table tr,
html.dark-theme body.report-mode .cumulative-sheet .q-table td{
    background:#172A45 !important;
    border-color:rgba(255,255,255,.08) !important;
    box-shadow:none !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .cat-section,
html.dark-theme body.report-mode .cumulative-sheet .cat-title{
    background:transparent !important;
    box-shadow:none !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .cat-title,
html.dark-theme body.report-mode .cumulative-sheet .cat-title *,
html.dark-theme body.report-mode .cumulative-sheet .cum-total-label,
html.dark-theme body.report-mode .cumulative-sheet .comment-title{ color:#C4B5FD !important; }
  html.dark-theme body.report-mode .cumulative-sheet .cat-title{ border-bottom-color:rgba(255,255,255,.12) !important; }
  html.dark-theme body.report-mode .cumulative-sheet .q-table thead,
html.dark-theme body.report-mode .cumulative-sheet .q-table thead tr,
html.dark-theme body.report-mode .cumulative-sheet .q-table th{
    background:#0F1F3D !important;
    color:#A0B3C6 !important;
    border-color:rgba(255,255,255,.10) !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .q-table tr:hover td{ background:#1D3350 !important; }
  html.dark-theme body.report-mode .cumulative-sheet .rating-badge{
    background:#0F1F3D !important;
    border-color:rgba(255,255,255,.12) !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .sheet-desig,
html.dark-theme body.report-mode .cumulative-sheet .cum-by,
html.dark-theme body.report-mode .cumulative-sheet .cum-by *,
html.dark-theme body.report-mode .cumulative-sheet .cum-date-label,
html.dark-theme body.report-mode .cumulative-sheet .cum-remark,
html.dark-theme body.report-mode .cumulative-sheet .no-comment,
html.dark-theme body.report-mode .cumulative-sheet .avg-out-of,
html.dark-theme body.report-mode .cumulative-sheet .rating-lbl,
html.dark-theme body.report-mode .cumulative-sheet .q-num{ color:#A0B3C6 !important; }
  html.dark-theme body.report-mode .cumulative-sheet .avg-score-big,
html.dark-theme body.report-mode .cumulative-sheet .avg-score-label{ color:#4ADE80 !important; }
  html.dark-theme body.report-mode .cumulative-sheet .sheet-avatar-ph{
    background:#0F1F3D !important;
    border-color:rgba(255,255,255,.12) !important;
  }
}
</style>
</body></html>
<?php $mysqli->close(); exit; }

// ══════════════════════════════════════════════════════════════
// VIEW: TARGET EVALUATION DETAILS
// Shows the selected person's evaluation summary and each received evaluation.
// ══════════════════════════════════════════════════════════════
if ($view === 'students' && $target_id) {
    $tgt = db_row($mysqli, "SELECT id,full_name,designation,photo,role FROM users u WHERE u.id=$target_id AND $viewScopeSql LIMIT 1");
    if (!$tgt) { http_response_code(404); exit('Personnel not found in this report scope.'); }

    // Keep the selected year level only for Student Evaluation.
    $selectedYearLevel = trim((string)($_GET['year_level'] ?? ''));
    $availableYearLevels = [];
    if ($activeEval === 'student') {
        // Always offer every College year level, 1st through 4th, in order --
        // not just the levels that happen to have evaluations for this person.
        $availableYearLevels = ['1st Year College','2nd Year College','3rd Year College','4th Year College'];
        if ($selectedYearLevel !== '' && !in_array($selectedYearLevel, $availableYearLevels, true)) {
            $selectedYearLevel = '';
        }
    } else {
        $selectedYearLevel = '';
    }

    $studentYearWhere = '';
    if ($activeEval === 'student' && $selectedYearLevel !== '') {
        $studentYearWhere = " AND TRIM(COALESCE(u.year_level,'')) = '" . $mysqli->real_escape_string($selectedYearLevel) . "'";
    }

    $evaluators = [];
    $eq = $mysqli->query("
        SELECT et.id AS tracker_id,
               et.submitted_at,
               et.remarks,
               u.id AS student_id,
               u.full_name,
               u.photo,
               u.role AS evaluator_role,
               " . ($activeEval === 'student' ? "u.year_level" : "NULL AS year_level") . ",
               (SELECT AVG(qa.answer_score)
                  FROM questionnaire_answers qa
                 WHERE qa.tracker_id=et.id AND " . rp_current_q_sql('qa', (int)$target_id) . ") AS avg_score
          FROM evaluation_tracker et
          JOIN users u ON u.id = {$trackerEvaluatorIdSql}
         WHERE et.target_user_id = $target_id
           AND $evalTypeSql
           $studentYearWhere
         ORDER BY et.submitted_at DESC
    ");
    if ($eq) $evaluators = $eq->fetch_all(MYSQLI_ASSOC);

    // Pooled average over every individual answer — the same figure the roster
    // and the cumulative sheet use (was: mean of per-evaluator means, which
    // drifts whenever evaluators answer a different number of questions).
    $overallAvg = null;
    if (!empty($evaluators)) {
        $evTrackerIds = implode(',', array_map('intval', array_column($evaluators, 'tracker_id')));
        $oa = db_row($mysqli, "SELECT AVG(qa.answer_score) AS a FROM questionnaire_answers qa WHERE qa.tracker_id IN ($evTrackerIds) AND qa.answer_score IS NOT NULL AND " . rp_current_q_sql('qa', (int)$target_id));
        if (isset($oa['a']) && $oa['a'] !== null) $overallAvg = round((float)$oa['a'], 2);
    }

    $scaleItems  = [5=>'Always',4=>'Often',3=>'Sometimes',2=>'Rarely',1=>'Never'];
    pageHead('Evaluation Details', $evalColor, $evalColorBg, $evalColorBorder);
    ?>
<style>
.detail-page{max-width:none;}
.detail-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;}
.detail-back{display:inline-flex;align-items:center;gap:8px;padding:9px 15px;border:1px solid #BCD0E5;border-radius:10px;background:#fff;color:#173956;text-decoration:none;font-size:12px;font-weight:800;}
.detail-back:hover{background:#F4F8FC;border-color:#8FAFCB;}
.detail-print{border:0;border-radius:12px;padding:12px 20px;background:#2D66E1;color:#fff;font:800 12px 'Inter',sans-serif;display:inline-flex;align-items:center;gap:8px;cursor:pointer;box-shadow:0 6px 16px rgba(45,102,225,.18);}
.detail-print:hover{background:#2459C9;}
.detail-eval-banner{display:flex;align-items:center;gap:14px;padding:14px 18px;border:1px solid #CFE0F0;border-radius:14px;background:#EFF6FF;margin-bottom:22px;}
.detail-eval-icon{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;background:#E0ECFF;border:1px solid #BFD7FF;color:#2B67DE;font-size:18px;flex:0 0 auto;}
.detail-eval-title{font-size:17px;font-weight:800;color:#1558CF;line-height:1.15;}
.detail-eval-sub{margin-top:3px;font-size:12px;color:#5C7590;}
.detail-person-card{display:flex;align-items:center;gap:20px;padding:28px 30px;border:1px solid #CFE0F0;border-radius:16px;background:#fff;box-shadow:0 5px 18px rgba(28,64,92,.05);margin-bottom:30px;flex-wrap:wrap;}
.detail-person-avatar{width:74px;height:74px;border-radius:50%;object-fit:cover;border:2px solid #2B67DE;flex:0 0 auto;}
.detail-person-avatar.ph{display:flex;align-items:center;justify-content:center;background:#F1F5F9;color:#6C8195;font-size:26px;}
.detail-person-meta{min-width:280px;flex:1;}
.detail-person-name{font-family:'Rajdhani',sans-serif;font-size:28px;font-weight:800;line-height:1.05;color:#0F2944;}
.detail-person-desig{margin-top:5px;font-size:13px;color:#5F7890;line-height:1.35;}
.detail-stats{display:flex;align-items:flex-start;gap:42px;margin-left:auto;flex-wrap:wrap;}
.detail-stat{text-align:center;min-width:104px;}
.detail-stat-value{font-family:'Rajdhani',sans-serif;font-size:31px;font-weight:800;line-height:1;color:#2B67DE;}
.detail-stat-value.score{color:#10B981;}
.detail-stat-label{margin-top:5px;font-size:10px;color:#72879A;text-transform:uppercase;letter-spacing:.06em;}
.detail-legend{width:auto;min-width:320px;border:1px solid #CFE0F0;border-radius:13px;background:#F8FBFE;padding:15px 17px;}
.detail-legend-title{font-size:13px;font-weight:800;color:#122E4A;margin-bottom:8px;}
.detail-legend-row{display:flex;align-items:center;justify-content:flex-start;gap:10px;font-size:12px;line-height:1.55;}
.detail-legend-dot{width:11px;height:11px;border-radius:3px;flex-shrink:0;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
.detail-legend-score{font-weight:800;min-width:92px;color:#122E4A;}
.detail-legend-text{color:#647B90;text-align:left;}
html.dark-theme .detail-legend-score{color:#E0E6F0 !important;}
.detail-results-head{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:12px;}
.detail-head-actions{display:flex;align-items:center;justify-content:flex-end;gap:16px;flex-wrap:wrap;}
html body .detail-head-actions a.detail-generate-btn,html body .detail-head-actions a.detail-generate-btn:link,html body .detail-head-actions a.detail-generate-btn:visited{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:42px;padding:0 20px;border-radius:10px;background:#7C3AED!important;border:1px solid #7C3AED;color:#fff!important;-webkit-text-fill-color:#fff!important;font:700 13px 'Inter',sans-serif;text-decoration:none!important;white-space:nowrap;box-shadow:0 6px 16px rgba(124,58,237,.22);transition:background .15s;}
html body .detail-head-actions a.detail-generate-btn:hover,html body .detail-head-actions a.detail-generate-btn:focus-visible{background:#6D28D9!important;border-color:#6D28D9;opacity:1;}
html body .detail-head-actions a.detail-generate-btn i{color:#fff!important;}
.detail-results-title{display:flex;align-items:center;gap:10px;}
.detail-results-title i{color:#2B67DE;font-size:19px;}
.detail-results-title h2{font-family:'Rajdhani',sans-serif;font-size:23px;font-weight:800;color:#122E4A;margin:0;}
.detail-results-title span{font-size:11px;color:#70869A;}
.detail-year-filter{display:flex;align-items:center;gap:10px;}
.detail-year-filter label{font-size:10px;font-weight:800;color:#70869A;text-transform:uppercase;letter-spacing:.07em;}
.detail-year-filter select{min-width:210px;height:42px;padding:0 14px;border:1px solid #BFD2E5;border-radius:10px;background:#fff;color:#102C47;font:700 12px 'Inter',sans-serif;outline:none;}
.detail-results-panel{border:1px solid #CFE0F0;border-radius:16px;background:#fff;box-shadow:0 5px 18px rgba(28,64,92,.04);overflow:hidden;}
.detail-results-table{width:100%;border-collapse:collapse;}
.detail-results-table th{padding:12px 16px;background:#F7FAFD;color:#60778D!important;border-bottom:1px solid #D8E4EE;font-size:10px!important;font-weight:800!important;letter-spacing:.07em;text-align:left;white-space:nowrap;}
.detail-results-table td{padding:17px 16px;border-bottom:1px solid #E0E9F1;color:#173956!important;font-size:13px!important;vertical-align:middle;}
.detail-results-table tbody tr:hover td{background:#FAFCFE!important;}
.detail-results-table tbody tr:last-child td{border-bottom:0;}
.detail-no{width:56px;text-align:center;}
.detail-evaluator{font-weight:700;color:#102C47!important;}
.detail-role{color:#526D84!important;}
.detail-avg{font-size:16px;font-weight:800;color:#10B981;}
.detail-rating{display:inline-flex;align-items:center;padding:5px 11px;border-radius:999px;background:#E8F6F1;color:#10B981;font-size:11px;font-weight:800;}
.detail-date{white-space:nowrap;color:#31516C!important;}
.detail-view-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:8px 16px;border:1px solid #AFCBFF;border-radius:10px;background:#F1F6FF;color:#2363D4;text-decoration:none;font-size:11px;font-weight:800;}
.detail-view-btn:hover{background:#E7F0FF;}
.detail-report-btn{background:#2D66E1!important;border-color:#2D66E1!important;color:#fff!important;margin-right:6px;}
.detail-report-btn:hover{background:#2459C9!important;}
.detail-empty{text-align:center;padding:54px 22px;color:#70869A;}
.detail-empty i{display:block;font-size:32px;margin-bottom:10px;color:#A4B5C5;}
.detail-empty h3{font-family:'Rajdhani',sans-serif;font-size:19px;color:#163450;margin:0 0 4px;}
.detail-empty p{font-size:12px;color:#71869A;margin:0;}
@media(max-width:1000px){
  .detail-stats{width:100%;margin-left:0;justify-content:flex-start;gap:26px;}
  .detail-legend{margin-left:auto;}
}
@media(max-width:760px){
  .detail-actions{align-items:stretch;flex-direction:column;}
  .detail-print,.detail-back{justify-content:center;}
  .detail-person-card{padding:22px;}
  .detail-person-meta{min-width:220px;}
  .detail-legend{width:100%;margin-left:0;}
  .detail-results-head{align-items:flex-start;flex-direction:column;}
  .detail-year-filter{width:100%;}
  .detail-year-filter select{flex:1;min-width:0;}
  .detail-results-panel{overflow-x:auto;}
  .detail-results-table{min-width:820px;}
}
@media print{
  .no-print,.detail-actions,.detail-year-filter{display:none!important;}
  body{background:#fff!important;color:#000!important;padding:0;}
  .detail-eval-banner,.detail-person-card,.detail-results-panel{box-shadow:none!important;}
  .detail-results-table th,.detail-results-table td{color:#000!important;}
}
</style>
<style id="detail-icon-actions">
/* Evaluation Results > Actions: plain blue icons (no button chrome). */
html body a.detail-icon-btn,
html body a.detail-icon-btn:link,
html body a.detail-icon-btn:visited{
    display:inline-flex !important;align-items:center;justify-content:center;
    width:30px;height:30px;padding:0 !important;margin:0 2px 0 0 !important;
    background:transparent !important;border:0 !important;box-shadow:none !important;
    border-radius:8px;font-size:15px;line-height:1;text-decoration:none !important;
    color:#2363D4 !important;cursor:pointer;transition:background-color .15s;
}
html body a.detail-icon-btn i{margin:0 !important;color:inherit !important}
html body a.detail-icon-btn:hover,
html body a.detail-icon-btn:focus-visible{background:rgba(59,130,246,.14) !important}
html.dark-theme body a.detail-icon-btn,
html.dark-theme body a.detail-icon-btn:link,
html.dark-theme body a.detail-icon-btn:visited{color:#6EA3FF !important}
</style>
<link rel="stylesheet" href="includes/dean_light_theme.css?v=dashboard-ui-20261009"/>
</head><body>
<?php render_exec_sidebar('reports', $me, $photo_src); ?>
<main class="main detail-page">

<div class="detail-actions no-print">
    <a href="?group=<?= urlencode($groupFilter) ?>&eval_type=<?= $activeEval ?>" class="detail-back"><i class="fa-solid fa-arrow-left"></i> Back to Analytics</a>
</div>

<div class="detail-person-card">
    <?php if ($tgt['photo']): ?><img class="detail-person-avatar" src="../image/<?= htmlspecialchars($tgt['photo']) ?>" alt=""/>
    <?php else: ?><div class="detail-person-avatar ph"><i class="fa-solid fa-user"></i></div><?php endif; ?>
    <div class="detail-person-meta">
        <div class="detail-person-name"><?= htmlspecialchars($tgt['full_name']) ?></div>
        <div class="detail-person-desig">
            · <?= $activeEval==='staff' ? 'Executive Assistant' : ($tgt['role']==='teacher' || $tgt['role']==='faculty' ? 'Faculty' : 'Staff') ?><br>
            <?= $activeEval === 'staff' ? 'Non-Teaching Staff Evaluation' : ($activeEval === 'peer' ? 'Peer-to-Peer Evaluation' : ($selectedYearLevel !== '' ? htmlspecialchars($selectedYearLevel) : 'College Students Only')) ?>
        </div>
    </div>
    <div class="detail-stats">
        <div class="detail-stat">
            <div class="detail-stat-value"><?= count($evaluators) ?></div>
            <div class="detail-stat-label">Total Evaluations</div>
        </div>
        <div class="detail-stat">
            <div class="detail-stat-value score"><?= $overallAvg !== null ? number_format($overallAvg,2) . ' / 5.00' : '—' ?></div>
            <div class="detail-stat-label">Overall Average Score</div>
        </div>
    </div>
    <div class="detail-legend">
        <div class="detail-legend-title">Rating Legend</div>
        <div class="detail-legend-row"><span class="detail-legend-dot" style="background:#32B98A"></span><span class="detail-legend-score">4.50 &ndash; 5.00</span><span class="detail-legend-text">Very Satisfied</span></div>
        <div class="detail-legend-row"><span class="detail-legend-dot" style="background:#6BCFA9"></span><span class="detail-legend-score">3.50 &ndash; 4.49</span><span class="detail-legend-text">Satisfied</span></div>
        <div class="detail-legend-row"><span class="detail-legend-dot" style="background:#facc15"></span><span class="detail-legend-score">2.50 &ndash; 3.49</span><span class="detail-legend-text">Neither Satisfied nor Dissatisfied</span></div>
        <div class="detail-legend-row"><span class="detail-legend-dot" style="background:#fb923c"></span><span class="detail-legend-score">1.50 &ndash; 2.49</span><span class="detail-legend-text">Dissatisfied</span></div>
        <div class="detail-legend-row"><span class="detail-legend-dot" style="background:#E6788A"></span><span class="detail-legend-score">1.00 &ndash; 1.49</span><span class="detail-legend-text">Very Dissatisfied</span></div>
    </div>
</div>

<div class="detail-results-head">
    <div class="detail-results-title">
        <i class="fa-solid fa-list-check"></i>
        <h2>Evaluation Results</h2>
        <span>(<?= count($evaluators) ?>)</span>
    </div>
    <div class="detail-head-actions no-print">
    <?php if (!empty($evaluators)):
        $firstEv = reset($evaluators);
        $genQs = '?view=sheet&target_id=' . $target_id . '&student_id=' . (int)$firstEv['student_id'] . '&tracker_id=' . (int)$firstEv['tracker_id']
            . '&group=' . urlencode($groupFilter) . '&eval_type=' . $activeEval
            . ($selectedYearLevel !== '' ? '&year_level=' . urlencode($selectedYearLevel) : '') . '&report=1';
    ?>
    <a class="detail-generate-btn no-print" href="<?= htmlspecialchars($genQs, ENT_QUOTES, 'UTF-8') ?>"><i class="fa-solid fa-file-lines"></i> Generate Report</a>
    <?php endif; ?>
        <?php if ($activeEval === 'student' && !empty($availableYearLevels)): ?>
    <form method="get" class="detail-year-filter no-print">
        <input type="hidden" name="view" value="students"/>
        <input type="hidden" name="target_id" value="<?= $target_id ?>"/>
        <input type="hidden" name="group" value="<?= htmlspecialchars($groupFilter) ?>"/>
        <input type="hidden" name="eval_type" value="<?= htmlspecialchars($activeEval) ?>"/>
        <label for="detailYearLevel">College Year Level</label>
        <select id="detailYearLevel" name="year_level" onchange="this.form.submit()">
            <option value="">All College Year Levels</option>
            <?php foreach ($availableYearLevels as $yl): ?>
                <option value="<?= htmlspecialchars($yl) ?>" <?= $selectedYearLevel===$yl?'selected':'' ?>><?= htmlspecialchars($yl) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>
    </div>
</div>

<div class="detail-results-panel">
<?php if (empty($evaluators)): ?>
    <div class="detail-empty">
        <i class="fa-solid fa-clipboard-question"></i>
        <h3>No Evaluation Results</h3>
        <p>No <?= $evaluatorNounP ?> have evaluated this person for the selected report scope.</p>
    </div>
<?php else: ?>
    <div style="overflow-x:auto;">
    <table class="detail-results-table">
        <thead><tr>
            <th class="detail-no">NO.</th>
            <th>EVALUATOR</th>
            <th>ROLE</th>
            <th>AVERAGE SCORE</th>
            <th>RATING</th>
            <th>DATE EVALUATED</th>
            <th>ACTIONS</th>
        </tr></thead>
        <tbody>
        <?php foreach ($evaluators as $i => $ev):
            $sc = $ev['avg_score'] !== null ? round((float)$ev['avg_score'],2) : null;
            $roleLabel = match ($activeEval) { 'peer' => 'Faculty / Peer', 'staff' => 'Non-Teaching Staff', default => 'Student' };
            $dateEval = !empty($ev['submitted_at']) ? date('M d, Y', strtotime($ev['submitted_at'])) : '—';
        ?>
        <tr>
            <td class="detail-no"><?= $i + 1 ?></td>
            <td class="detail-evaluator"><?= htmlspecialchars($ev['full_name']) ?></td>
            <td class="detail-role"><?= htmlspecialchars($roleLabel) ?></td>
            <td><span class="detail-avg" style="color:<?= scoreColor($sc) ?>"><?= $sc !== null ? number_format($sc,2) : '—' ?></span></td>
            <td><span class="detail-rating" style="color:<?= scoreColor($sc) ?>;background:<?= scoreColor($sc) ?>18;"><?= htmlspecialchars(scoreSatisfactionLabel($sc)) ?></span></td>
            <td class="detail-date"><?= htmlspecialchars($dateEval) ?></td>
            <td>
                <?php $sheetQs = '?view=sheet&target_id=' . $target_id . '&student_id=' . (int)$ev['student_id'] . '&tracker_id=' . (int)$ev['tracker_id']
                    . '&group=' . urlencode($groupFilter) . '&eval_type=' . $activeEval
                    . ($selectedYearLevel !== '' ? '&year_level=' . urlencode($selectedYearLevel) : ''); ?>
                <a class="detail-icon-btn" title="View" aria-label="View" href="<?= $sheetQs ?>"><i class="fa-solid fa-eye"></i></a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</div>

</main>

<style id="dean-reports-dark-final">
/* -------------------------------------------------------------------------
   Final dark-mode layer for Dean Evaluation Reports.
   This is intentionally loaded last because this page contains several
   legacy light-theme blocks and view-specific inline styles.
   ------------------------------------------------------------------------- */
html.dark-theme body {
  background:#0A192F !important;
  background-image:none !important;
  color:#E0E6F0 !important;
}
html.dark-theme .main {
  background:#0F1F3D !important;
  color:#E0E6F0 !important;
}

/* Main analytics tabs / filters */
html.dark-theme .reports-top-tab,
html.dark-theme .reports-filter-tab {
  background:#172A45 !important;
  border-color:rgba(255,255,255,.12) !important;
  color:#A0B3C6 !important;
  box-shadow:none !important;
}
html.dark-theme .reports-top-tab:hover,
html.dark-theme .reports-filter-tab:hover {
  background:#1D3350 !important;
  color:#E0E6F0 !important;
}
html.dark-theme .reports-top-tab.active,
html.dark-theme .reports-top-tab.peer.active,
html.dark-theme .reports-filter-tab.active {
  background:rgba(124,95,217,.16) !important;
  border-color:rgba(156,133,240,.38) !important;
  color:#C4B5FD !important;
  box-shadow:none !important;
}
html.dark-theme .reports-top-badge,
html.dark-theme .reports-filter-tab b {
  background:#0F1F3D !important;
  color:#A0B3C6 !important;
}
html.dark-theme .reports-top-tab.active .reports-top-badge,
html.dark-theme .reports-filter-tab.active b {
  background:rgba(124,95,217,.22) !important;
  color:#C4B5FD !important;
}

html.dark-theme .reports-sub-filters,
html.dark-theme .reports-designation-filters,
html.dark-theme .reports-peer-note {
  background:#172A45 !important;
  border-color:rgba(255,255,255,.08) !important;
  color:#A0B3C6 !important;
}
html.dark-theme .reports-sub-label { color:#A0B3C6 !important; }
html.dark-theme .reports-sub-tab,
html.dark-theme .reports-designation-pill {
  color:#A0B3C6 !important;
}
html.dark-theme .reports-sub-tab:hover,
html.dark-theme .reports-designation-pill:hover {
  background:#1D3350 !important;
  color:#E0E6F0 !important;
}
html.dark-theme .reports-sub-tab.active,
html.dark-theme .reports-designation-pill.active {
  background:rgba(124,95,217,.16) !important;
  border-color:rgba(156,133,240,.35) !important;
  color:#C4B5FD !important;
}
html.dark-theme .reports-sub-tab b,
html.dark-theme .reports-designation-pill b {
  background:#0F1F3D !important;
  color:#A0B3C6 !important;
}

/* Report data panel */
html.dark-theme .reports-data-panel {
  background:#172A45 !important;
  border-color:rgba(255,255,255,.08) !important;
  box-shadow:0 8px 28px rgba(0,0,0,.28) !important;
}
html.dark-theme .reports-data-title h2 { color:#E0E6F0 !important; }
html.dark-theme .reports-data-title span { color:#A0B3C6 !important; }
html.dark-theme .reports-data-title i { color:#9C85F0 !important; }
html.dark-theme .reports-action-btn {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.14) !important;
  color:#E0E6F0 !important;
}
html.dark-theme .reports-action-btn:hover { background:#1D3350 !important; }
html.dark-theme .reports-search-box {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.14) !important;
}
html.dark-theme .reports-search-box i { color:#8EA5BB !important; }
html.dark-theme .reports-search-box input {
  background:transparent !important;
  color:#E0E6F0 !important;
}
html.dark-theme .reports-search-box input::placeholder { color:#6B84A0 !important; }
html.dark-theme .reports-active-filter-note {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.08) !important;
  color:#A0B3C6 !important;
}
html.dark-theme .reports-active-filter-note button { color:#C4B5FD !important; }

/* Main report table */
html.dark-theme .reports-table th {
  background:#0F1F3D !important;
  color:#A0B3C6 !important;
  border-top-color:rgba(255,255,255,.08) !important;
  border-bottom-color:rgba(255,255,255,.10) !important;
}
html.dark-theme .reports-table td {
  background:#172A45 !important;
  color:#E0E6F0 !important;
  border-bottom-color:rgba(255,255,255,.08) !important;
}
html.dark-theme .reports-table tbody tr:hover td {
  background:#1D3350 !important;
}
html.dark-theme .reports-person-name { color:#E0E6F0 !important; }
html.dark-theme .reports-designation-cell { color:#B6C6D6 !important; }
html.dark-theme .reports-person-photo { border-color:rgba(255,255,255,.12) !important; }
html.dark-theme .reports-person-photo.placeholder {
  background:#0F1F3D !important;
  color:#8EA5BB !important;
}
html.dark-theme .reports-role-badge.faculty {
  background:rgba(37,99,216,.16) !important;
  border-color:rgba(96,165,250,.32) !important;
  color:#93C5FD !important;
}
html.dark-theme .reports-role-badge.staff,
html.dark-theme .reports-role-badge.dean {
  background:rgba(124,95,217,.16) !important;
  border-color:rgba(156,133,240,.32) !important;
  color:#C4B5FD !important;
}
html.dark-theme .reports-role-badge.principal {
  background:rgba(217,119,6,.16) !important;
  border-color:rgba(217,119,6,.32) !important;
  color:#FBBF24 !important;
}
html.dark-theme .reports-score.high { color:#4ADE80 !important; }
html.dark-theme .reports-score.mid { color:#FBBF24 !important; }
html.dark-theme .reports-score.low { color:#FCA5A5 !important; }
html.dark-theme .reports-date { color:#A0B3C6 !important; }
html.dark-theme .reports-view-btn {
  background:rgba(59,130,246,.14) !important;
  border-color:rgba(96,165,250,.32) !important;
  color:#93C5FD !important;
}
html.dark-theme .reports-view-btn:hover { background:rgba(59,130,246,.22) !important; }
html.dark-theme .reports-archive-btn {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.14) !important;
  color:#A0B3C6 !important;
}
html.dark-theme .reports-archive-btn:hover { background:#1D3350 !important; color:#E0E6F0 !important; }
html.dark-theme .reports-empty-state,
html.dark-theme .reports-no-search { color:#A0B3C6 !important; }
html.dark-theme .reports-empty-state h3 { color:#E0E6F0 !important; }
html.dark-theme .reports-empty-state i { color:#71879C !important; }
html.dark-theme .reports-empty-state p { color:#A0B3C6 !important; }
html.dark-theme .reports-live-status {
  background:rgba(16,185,129,.14) !important;
  border-color:rgba(16,185,129,.32) !important;
  color:#4ADE80 !important;
}
html.dark-theme .reports-live-status.stale {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.10) !important;
  color:#8EA5BB !important;
}

/* Shared report/detail controls */
html.dark-theme .back-btn,
html.dark-theme .detail-back,
html.dark-theme .btn-archived-link {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.14) !important;
  color:#E0E6F0 !important;
}
html.dark-theme .back-btn:hover,
html.dark-theme .detail-back:hover,
html.dark-theme .btn-archived-link:hover {
  background:#1D3350 !important;
  color:#C4B5FD !important;
}

/* Detail / individual evaluation view */
html.dark-theme .detail-eval-banner {
  background:rgba(59,130,246,.10) !important;
  border-color:rgba(96,165,250,.24) !important;
}
html.dark-theme .detail-eval-icon {
  background:rgba(59,130,246,.14) !important;
  border-color:rgba(96,165,250,.28) !important;
  color:#93C5FD !important;
}
html.dark-theme .detail-eval-title { color:#93C5FD !important; }
html.dark-theme .detail-eval-sub { color:#A0B3C6 !important; }
html.dark-theme .detail-person-card,
html.dark-theme .detail-results-panel,
html.dark-theme .detail-legend {
  background:#172A45 !important;
  border-color:rgba(255,255,255,.08) !important;
  box-shadow:0 8px 28px rgba(0,0,0,.25) !important;
}
html.dark-theme .detail-person-avatar.ph {
  background:#0F1F3D !important;
  color:#8EA5BB !important;
}
html.dark-theme .detail-person-name { color:#E0E6F0 !important; }
html.dark-theme .detail-person-desig,
html.dark-theme .detail-stat-label,
html.dark-theme .detail-legend-text,
html.dark-theme .detail-results-title span,
html.dark-theme .detail-year-filter label { color:#A0B3C6 !important; }
html.dark-theme .detail-stat-value { color:#93C5FD !important; }
html.dark-theme .detail-stat-value.score { color:#4ADE80 !important; }
html.dark-theme .detail-legend-title { color:#E0E6F0 !important; }
html.dark-theme .detail-year-filter select {
  background:#0F1F3D !important;
  color:#E0E6F0 !important;
  border-color:rgba(255,255,255,.14) !important;
}
html.dark-theme .detail-results-title h2 { color:#E0E6F0 !important; }
html.dark-theme .detail-results-title i { color:#9C85F0 !important; }
html.dark-theme .detail-results-table th {
  background:#0F1F3D !important;
  color:#A0B3C6 !important;
  border-bottom-color:rgba(255,255,255,.10) !important;
}
html.dark-theme .detail-results-table td {
  background:#172A45 !important;
  color:#E0E6F0 !important;
  border-bottom-color:rgba(255,255,255,.08) !important;
}
html.dark-theme .detail-results-table tbody tr:hover td { background:#1D3350 !important; }
html.dark-theme .detail-evaluator,
html.dark-theme .detail-date,
html.dark-theme .detail-role { color:#B8C7D7 !important; }
html.dark-theme .detail-rating {
  background:rgba(16,185,129,.14) !important;
  color:#4ADE80 !important;
}
html.dark-theme .detail-view-btn {
  background:rgba(59,130,246,.14) !important;
  border-color:rgba(96,165,250,.32) !important;
  color:#93C5FD !important;
}
html.dark-theme .detail-view-btn:hover { background:rgba(59,130,246,.22) !important; }
html.dark-theme .detail-empty { color:#A0B3C6 !important; }
html.dark-theme .detail-empty h3 { color:#E0E6F0 !important; }
html.dark-theme .detail-empty p { color:#A0B3C6 !important; }

/* Individual sheet view */
html.dark-theme .sheet-header,
html.dark-theme .scale-bar,
html.dark-theme .comment-section,
html.dark-theme .avg-summary {
  background:#172A45 !important;
  border-color:rgba(255,255,255,.08) !important;
  color:#E0E6F0 !important;
}
html.dark-theme .sheet-name,
html.dark-theme .avg-score-big,
html.dark-theme .avg-score-label { color:#E0E6F0 !important; }
html.dark-theme .sheet-desig,
html.dark-theme .eval-by-label,
html.dark-theme .eval-by-date,
html.dark-theme .avg-out-of,
html.dark-theme .scale-item,
html.dark-theme .avg-bar-label { color:#A0B3C6 !important; }
html.dark-theme .eval-by-name { color:#93C5FD !important; }
html.dark-theme .sheet-avatar-ph {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.12) !important;
  color:#8EA5BB !important;
}
html.dark-theme .eval-type-chip {
  background:rgba(59,130,246,.12) !important;
  border-color:rgba(96,165,250,.28) !important;
  color:#93C5FD !important;
}
html.dark-theme .q-table {
  background:#172A45 !important;
  border-color:rgba(255,255,255,.08) !important;
}
html.dark-theme .q-table thead tr,
html.dark-theme .q-table th {
  background:#0F1F3D !important;
  color:#A0B3C6 !important;
}
html.dark-theme .q-table td {
  background:#172A45 !important;
  color:#E0E6F0 !important;
  border-top-color:rgba(255,255,255,.08) !important;
}
html.dark-theme .q-table tr:hover td { background:#1D3350 !important; }
html.dark-theme .rating-badge {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.08) !important;
  color:#E0E6F0 !important;
}
html.dark-theme .comment-text { color:#D7E0E9 !important; }
html.dark-theme .avg-bar-bg { background:rgba(255,255,255,.08) !important; }

/* Archived personnel */
html.dark-theme .person-row,
html.dark-theme .no-archived {
  background:#172A45 !important;
  border-color:rgba(255,255,255,.08) !important;
  color:#E0E6F0 !important;
}
html.dark-theme .person-name { color:#E0E6F0 !important; }
html.dark-theme .person-meta { color:#A0B3C6 !important; }
html.dark-theme .person-photo-ph {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.12) !important;
  color:#8EA5BB !important;
}
html.dark-theme .archived-badge {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.10) !important;
  color:#A0B3C6 !important;
}
html.dark-theme .btn-restore {
  background:rgba(124,95,217,.16) !important;
  border:1px solid rgba(156,133,240,.35) !important;
  color:#C4B5FD !important;
}
html.dark-theme .btn-restore:hover { background:rgba(124,95,217,.24) !important; }

/* Keep dark-theme scrollbars readable */
html.dark-theme ::-webkit-scrollbar-track { background:#0F1F3D !important; }
html.dark-theme ::-webkit-scrollbar-thumb {
  background:#2A4468 !important;
  border:2px solid #0F1F3D !important;
}
</style>

</body></html>
<?php $mysqli->close(); exit; }


// ══════════════════════════════════════════════════════════════
// VIEW: MAIN LIST
// ══════════════════════════════════════════════════════════════
$whereRole = match ($activeEval) {
    // The Student Evaluation report must use the person's actual personnel
    // classification, not the tracker row's default evaluation_context.
    // A Staff account can be a Teaching Staff member and therefore belongs
    // under Faculty; a true Non-Teaching Staff account belongs under Staff.
    'student' => "u.role IN ('teacher','staff','faculty')",
    // Peer targets are restricted to Faculty / Teaching Staff by $peerEvalSql itself.
    'peer'    => "u.role IN ('teacher','faculty','staff')",
    'staff'   => "u.role IN ($eaRolesSql)",
    default   => "u.role IN ('teacher','staff')"
};

$people = [];
$res = $mysqli->query(" 
    SELECT u.id, u.full_name, u.designation, u.photo, u.role, u.secondary_role, u.source, u.account_status,
           COUNT(DISTINCT et.id) AS total_responses,
           MAX(et.submitted_at) AS last_evaluated,
           AVG(qa.answer_score)  AS avg_score,
           SUM(qa.answer_score)  AS score_sum,
           COUNT(qa.answer_score) AS score_cnt
    FROM users u
    JOIN evaluation_tracker et ON et.target_user_id=u.id AND $evalTypeSql
    LEFT JOIN questionnaire_answers qa ON qa.tracker_id=et.id AND " . rp_current_q_sql('qa', 'u.id') . "
    WHERE $whereRole AND u.is_active=1 AND $viewScopeSql
    GROUP BY u.id
    ORDER BY avg_score DESC, u.full_name ASC
");
if ($res) $people = $res->fetch_all(MYSQLI_ASSOC);

// ── Canonical Student Evaluation grouping ────────────────────────────────
// IMPORTANT: do NOT infer the Faculty/Staff tab from evaluation_tracker's
// eval_bucket/evaluation_context. Legacy Student Evaluation tracker rows can
// carry the default "teacher" context even when the target is a true
// Non-Teaching Staff member. The report tabs instead use the same personnel
// classification rule as the questionnaire roster:
//   • Faculty  = Teacher/Faculty personnel OR Teaching Staff (staff account
//                with teaching function/assignment/year-level scope)
//   • Staff    = Non-Teaching Staff only (staff account with no teaching
//                function/assignment/year-level scope)
// This also means a teaching Staff account such as Amelia appears only under
// Faculty, while genuine Non-Teaching Staff accounts appear only under Staff.
if ($activeEval === 'student') {
    foreach ($people as &$person) {
        $person['student_resolved_group'] = ec_resolve_student_group_full($person, $mysqli) ?? '';
    }
    unset($person);

    $people = array_values(array_filter($people, function (array $p) use ($groupFilter) {
        $resolved = strtolower(trim((string)($p['student_resolved_group'] ?? '')));
        $rawRole  = strtolower(trim((string)($p['role'] ?? '')));
        if ($groupFilter === 'Faculty') return $resolved === 'teacher';
        if ($groupFilter === 'Staff') return $resolved === 'staff';
        return true;
    }));
}

$top4 = array_slice($people, 0, 4);
$low4 = array_slice(array_reverse($people), 0, 4);

$totalResponses = array_sum(array_column($people,'total_responses'));
$poolCnt        = array_sum(array_column($people,'score_cnt'));
$overallAvg     = $poolCnt > 0 ? round(array_sum(array_column($people,'score_sum')) / $poolCnt, 2) : null;

$totalStudents = db_row($mysqli, "SELECT COUNT(*) AS c
    FROM users us
    WHERE us.role='student'
      AND us.is_active=1
      AND (us.education_level='higher_ed' OR us.year_level IN ('1st Year College','2nd Year College','3rd Year College','4th Year College'))")['c'] ?? 0;
$facCount = 0;
$staffCount = 0;
$totalFacStaff = 0;

// Match the EA Student Evaluation roster counts for the Higher Education Dean:
// Faculty and non-teaching Staff. College Student Evaluation does not
// include Principal targets in the Dean portal.
$studentRosterQ = $mysqli->query("SELECT id, full_name, designation, photo, role, secondary_role, sector, source,
           EXISTS(SELECT 1 FROM teaching_assignments ta WHERE ta.user_id=u.id) AS has_teaching_assignment,
           (u.role='teacher' OR u.sector='Teacher'
            OR EXISTS(SELECT 1 FROM teaching_assignments ta2 WHERE ta2.user_id=u.id)
            OR EXISTS(SELECT 1 FROM user_year_levels yl2 WHERE yl2.user_id=u.id)) AS is_teaching_staff,
           EXISTS(SELECT 1 FROM user_year_levels yl WHERE yl.user_id=u.id) AS has_year_level
    FROM users u
    WHERE u.is_active=1
      AND u.account_status='approved'
      AND u.role IN ('teacher','faculty','staff')
      AND $reportScopeSql");
if ($studentRosterQ) {
    while ($rosterUser = $studentRosterQ->fetch_assoc()) {
        $g = ec_resolve_student_group_full($rosterUser, $mysqli);
        if ($g === 'teacher') {
            $facCount++;
        } elseif ($g === 'staff') {
            $staffCount++;
        }
    }
    $studentRosterQ->free();
}
$totalFacStaff = $facCount + $staffCount;

if ($groupFilter === 'Teacher' || $groupFilter === 'Staff') {
    $totalFacStaff = $groupFilter === 'Teacher' ? $facCount : $staffCount;
}

$studentEvalCount = db_row($mysqli, "SELECT COUNT(DISTINCT et.id) as c
    FROM evaluation_tracker et
    JOIN users u ON u.id=et.target_user_id
    WHERE et.eval_type='student'
      AND $collegeStudentEvaluatorSql
      AND COALESCE(et.evaluation_context,'teacher') IN ('teacher','staff')
      AND $periodSql
      AND $reportScopeSql")['c'] ?? 0;
$multiRoleEvalCount = db_row($mysqli, "SELECT COUNT(DISTINCT et.id) AS c
    FROM evaluation_tracker et JOIN users u ON u.id=et.target_user_id
    WHERE $periodSql
      AND et.eval_type='student'
      AND $collegeStudentEvaluatorSql
      AND (et.evaluation_context='multi_role' OR EXISTS (
        SELECT 1 FROM questionnaire_answers qam JOIN user_questions uqm ON uqm.id=qam.user_question_id
        WHERE qam.tracker_id=et.id AND uqm.target_type='Multi-Role' AND uqm.eval_type='student'
    )) AND $reportScopeSql")['c'] ?? 0;
$staffEvalCount = db_row($mysqli, "SELECT COUNT(DISTINCT et.id) AS c FROM evaluation_tracker et WHERE $staffEvalSql")['c'] ?? 0;
$peerEvalCount = db_row($mysqli, "SELECT COUNT(DISTINCT et.id) AS c FROM evaluation_tracker et WHERE $peerEvalAllSql")['c'] ?? 0;
// Per-lane counts of people evaluated (badges on the Faculty / Staff / Dean tabs).
$peerLaneCounts = [];
foreach ($peerTargetIdsSql as $laneName => $laneTargetsSql) {
    $peerLaneCounts[$laneName] = (int)(db_row($mysqli, "SELECT COUNT(DISTINCT et.target_user_id) AS c
        FROM evaluation_tracker et
        JOIN users u ON u.id=et.target_user_id AND u.is_active=1
        WHERE " . $peerLaneSql($laneName, false))['c'] ?? 0);
}

$staffDesigCounts = [];
$sdq = $mysqli->query("SELECT designation, COUNT(*) as c FROM users u WHERE u.role='staff' AND u.is_active=1 AND $reportScopeSql GROUP BY designation ORDER BY designation");
if ($sdq) while ($r = $sdq->fetch_assoc()) $staffDesigCounts[$r['designation']] = $r['c'];

pageHead('Reports & Analytics', $evalColor, $evalColorBg, $evalColorBorder);
?>
<style>
.page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:22px 26px;flex-wrap:wrap;gap:14px;}
.page-header h1{font-family:'Rajdhani',sans-serif;font-size:28px;font-weight:700;color:var(--light);margin-bottom:3px;}
.page-header p{font-size:13px;color:var(--muted);}
.header-actions{display:flex;gap:10px;flex-wrap:wrap;}
.btn-print{background:var(--accent);color:#fff;border:none;padding:10px 20px;border-radius:var(--radius);font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:7px;transition:opacity .2s;font-family:'Inter',sans-serif;}
.btn-archived-link{background:var(--mid);color:var(--muted);border:1px solid var(--border);padding:10px 20px;border-radius:var(--radius);font-size:13px;font-weight:600;display:flex;align-items:center;gap:7px;text-decoration:none;transition:all .2s;}
.btn-print:hover{opacity:.85;}
.btn-archived-link{background:var(--mid);color:var(--muted);border:1px solid var(--border);padding:10px 20px;border-radius:var(--radius);font-size:13px;font-weight:600;display:flex;align-items:center;gap:7px;text-decoration:none;transition:all .2s;}
.btn-archived-link:hover{color:var(--light);}
.archived-count-badge{background:rgba(160,179,198,.2);border-radius:20px;padding:1px 8px;font-size:11px;font-weight:700;}
.group-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;}
.group-tab{display:flex;align-items:center;gap:8px;padding:10px 22px;border-radius:var(--radius);font-size:14px;font-weight:600;cursor:pointer;border:1px solid var(--border);background:var(--mid);color:var(--muted);text-decoration:none;transition:all .22s;}
.group-tab:hover{color:var(--light);}
.group-tab.active-all{background:rgba(59,130,246,.2);border-color:rgba(59,130,246,.5);color:#3B82F6;}
.group-tab.active-teacher{background:rgba(13,148,136,.2);border-color:rgba(13,148,136,.5);color:#0D9488;}
.group-tab.active-staff{background:rgba(217,119,6,.2);border-color:rgba(217,119,6,.5);color:#F59E0B;}.group-tab.active-faculty,.group-tab.active-multi-role{background:rgba(124,95,217,.14);border-color:rgba(124,95,217,.35);color:var(--violet-h);}
.group-tab.faculty-tab > i,.group-tab.multi-role-tab > i{color:var(--violet-h);}
.faculty-subtabs{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin:-6px 0 20px;padding:8px 10px;background:rgba(23,42,69,.55);border:1px solid var(--border);border-radius:12px;width:max-content;max-width:100%;}
.subtabs-label{font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.7px;margin:0 4px 0 2px;}
.faculty-subtab{display:flex;align-items:center;gap:7px;padding:8px 16px;border-radius:10px;border:1px solid transparent;color:var(--muted);text-decoration:none;font-size:13px;font-weight:700;transition:all .2s;}
.faculty-subtab:hover{color:#fff;background:rgba(124,95,217,.08);}
.faculty-subtab.active{background:rgba(124,95,217,.14);border-color:rgba(124,95,217,.35);color:var(--violet-h);}
.faculty-subtab i{color:var(--violet-h);}

.tab-count{background:rgba(255,255,255,.12);border-radius:20px;padding:1px 8px;font-size:11px;font-weight:700;}
.desig-subtabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:20px;padding:12px 16px;background:rgba(217,119,6,.06);border:1px solid rgba(217,119,6,.15);border-radius:10px;}
.desig-subtab{padding:5px 14px;border-radius:20px;font-size:12px;font-weight:600;background:rgba(255,255,255,.06);border:1px solid var(--border);color:var(--muted);cursor:pointer;text-decoration:none;transition:all .2s;}
.desig-subtab:hover{color:var(--light);}
.desig-subtab.active{background:rgba(217,119,6,.2);border-color:rgba(217,119,6,.4);color:#F59E0B;}
.summary-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;margin-bottom:24px;}
.sum-card{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:18px 20px;}
.sum-label{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:8px;}
.sum-value{font-size:28px;font-weight:700;color:var(--light);}
.sum-sub{font-size:12px;color:var(--muted);margin-top:4px;}
.sum-card.highlight .sum-value{color:var(--ec);}
.standings-row{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px;}
.standing-panel{background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:22px;}
.standing-title{font-size:15px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.standing-title.top{color:#4ade80;}
.standing-title.low{color:#f87171;}
.standing-item{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);}
.standing-item:last-child{border-bottom:none;}
.standing-rank{font-size:13px;font-weight:700;color:var(--muted);width:22px;text-align:center;flex-shrink:0;}
.standing-photo{width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid var(--border);}
.standing-photo-ph{width:38px;height:38px;border-radius:50%;background:var(--inner);border:2px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:14px;}
.standing-info{flex:1;}
.standing-name{font-size:13px;font-weight:600;color:var(--light);}
.standing-desig{font-size:11px;color:var(--muted);}
.standing-score{font-size:15px;font-weight:700;}
.score-top{color:#4ade80;}
.score-low{color:#f87171;}
.no-data{text-align:center;padding:24px;color:var(--muted);font-size:13px;}
.section-title{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;color:var(--light);margin-bottom:16px;display:flex;align-items:center;gap:10px;}
.people-list{display:flex;flex-direction:column;gap:14px;}
.person-row{background:var(--mid);border:1px solid var(--border);border-radius:14px;overflow:hidden;transition:all .2s;}
.person-row:hover{border-color:rgba(255,255,255,.2);box-shadow:0 4px 16px rgba(15,23,42,.1);}
.person-header{display:flex;align-items:center;gap:16px;padding:18px 22px;}
.person-link{display:flex;align-items:center;gap:16px;flex:1;text-decoration:none;color:inherit;min-width:0;}
.person-photo{width:52px;height:52px;border-radius:50%;object-fit:cover;border:2px solid var(--border);flex-shrink:0;}
.person-photo-ph{width:52px;height:52px;border-radius:50%;background:var(--inner);border:2px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:20px;flex-shrink:0;}
.person-info{flex:1;min-width:0;}
.person-name{font-size:15px;font-weight:700;color:var(--light);margin-bottom:4px;}
.person-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.group-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 11px;border-radius:20px;font-size:12px;font-weight:700;}
.group-badge.teacher{background:rgba(13,148,136,.18);color:#0D9488;border:1px solid rgba(13,148,136,.3);}
.group-badge.staff{background:rgba(217,119,6,.15);color:#F59E0B;border:1px solid rgba(217,119,6,.3);}
.desig-pill{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;background:rgba(255,255,255,.07);color:var(--muted);border:1px solid var(--border);}
.person-stats{display:flex;gap:20px;align-items:center;flex-shrink:0;flex-wrap:wrap;}
.pstat{display:flex;flex-direction:column;align-items:center;gap:2px;}
.pstat-val{font-size:18px;font-weight:700;color:var(--light);}
.pstat-lbl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;}
.pstat-val.good{color:#4ade80;}
.pstat-val.mid{color:var(--gold-h);}
.pstat-val.poor{color:#f87171;}
.score-bar-wrap{display:flex;align-items:center;gap:10px;min-width:140px;}
.score-bar-bg{flex:1;height:6px;background:rgba(255,255,255,.07);border-radius:3px;overflow:hidden;}
.score-bar-fill{height:100%;border-radius:3px;}
.arrow-icon{color:var(--muted);font-size:14px;flex-shrink:0;margin-left:4px;}
.person-actions{display:flex;align-items:center;gap:8px;flex-shrink:0;margin-left:12px;}
.btn-archive{background:none;border:1px solid var(--border);color:var(--muted);padding:9px 11px;border-radius:8px;font-size:13px;cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:6px;font-family:'Inter',sans-serif;}
.btn-archive:hover{background:rgba(240,84,84,.12);border-color:rgba(240,84,84,.4);color:#f87171;}
.btn-archive span{font-size:12px;font-weight:600;}
.no-evaluated{text-align:center;padding:48px;background:var(--mid);border-radius:14px;border:1px solid var(--border);color:var(--muted);}
.no-evaluated i{font-size:36px;opacity:.3;display:block;margin-bottom:14px;}
/* peer note */
.peer-info-note{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:10px;margin-bottom:20px;font-size:12px;color:var(--muted);line-height:1.6;background:rgba(124,58,237,.06);border:1px solid rgba(124,58,237,.18);}
.peer-info-note i{color:#7C3AED;margin-top:1px;flex-shrink:0;}
@media(max-width:800px){.standings-row{grid-template-columns:1fr;}body{padding:16px;}}
@media(max-width:560px){.summary-row{grid-template-columns:1fr 1fr;}.person-header{flex-wrap:wrap;}.btn-archive span{display:none;}.eval-switcher{width:100%;}.eval-tab{flex:1;justify-content:center;}}

/* ── SHARP LIGHT ADMIN UI ── */
html { background:#F8FAFC; }
body {
  color:#0F172A !important;
  background:#F8FAFC !important;
  -webkit-font-smoothing:antialiased;
  text-rendering:optimizeLegibility;
}
h1,h2,h3,h4,h5,h6 { color:#0F172A; letter-spacing:-.01em; }
p, .subtitle, .description, .helper, .muted, small { color:#475569; }
label, th { color:#334155; font-weight:600; }
td { color:#0F172A; }
input, select, textarea {
  color:#0F172A;
  background:#FFFFFF;
  border-color:#CBD5E1;
}
input::placeholder, textarea::placeholder { color:#94A3B8; }
.card, .panel, .section, .table-card, .content-card {
  border-color:#CBD5E1;
  box-shadow:0 4px 14px rgba(15,23,42,.07);
}
button, .btn { font-weight:700; }
a { color:inherit; }
</style>
</head><body>
<?php render_exec_sidebar('reports', $me, $photo_src); ?>
<main class="main">


<?php if ($toast): ?><div class="toast"><i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($toast) ?></div><?php endif; ?>

<!-- ── REPORTS REDESIGN ── -->
<style id="rp-eye-only">
/* Actions column: plain eye icon only (no "View" text, no pill) */
html body:not(#rap) .reports-table .reports-view-btn{min-width:0!important;width:36px;height:36px;padding:0!important;gap:0;background:transparent!important;border:0!important;box-shadow:none!important;border-radius:50%;color:#2363D4!important;font-size:17px;}
html body:not(#rap) .reports-table .reports-view-btn:hover{background:rgba(35,99,212,.10)!important;}
html body:not(#rap) .reports-table .reports-view-btn:focus-visible{outline:2px solid #2363D4;outline-offset:2px;}
html.dark-theme body:not(#rap) .reports-table .reports-view-btn{background:transparent!important;border:0!important;color:#93C5FD!important;}
html.dark-theme body:not(#rap) .reports-table .reports-view-btn:hover{background:rgba(59,130,246,.22)!important;}
html body:not(#rap) .reports-table .col-actions{width:120px;}
</style>
<style id="rp-generate-single">
/* One "Generate Report" button above the results table (same as the admin report);
   rows keep just the eye icon. */
html body:not(#rap) .detail-head-actions{display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
html body a.detail-generate-btn,html body a.detail-generate-btn:link,html body a.detail-generate-btn:visited{
    display:inline-flex;align-items:center;gap:8px;padding:9px 18px;border-radius:8px;
    background:#7C3AED!important;color:#fff!important;-webkit-text-fill-color:#fff!important;
    border:1px solid #7C3AED;font-size:13px;font-weight:700;text-decoration:none!important;
    font-family:'Inter',sans-serif;white-space:nowrap;transition:opacity .15s;}
html body a.detail-generate-btn:hover,html body a.detail-generate-btn:focus-visible{opacity:.88;}
html body a.detail-generate-btn i{color:#fff!important;}
@media print{.detail-head-actions{display:none!important;}}
</style>
<style id="rp-roles">
/* ------------------------------------------------------------------
   Evaluators vs. evaluated personnel (same treatment as Evaluation Reports)
   Indigo = EVALUATORS (who submitted), teal = BEING EVALUATED (who was rated).
   Presentation only; links, filters, search and queries are unchanged.
   ------------------------------------------------------------------ */
html body:not(#rap) .ra-role-label{display:flex;align-items:center;flex-wrap:wrap;gap:10px 12px;margin:0 0 10px;}
html body:not(#rap) .ra-role-badge{display:inline-flex;align-items:center;gap:8px;padding:6px 13px;border-radius:999px;font-size:12px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;border:1px solid transparent;}
html body:not(#rap) .ra-role-hint{font-size:13px;font-weight:500;}
html body:not(#rap) .ra-role-evaluated{margin-top:4px;}
html body:not(#rap) .reports-redesign .reports-top-row{margin-bottom:22px!important;}
html body:not(#rap) .reports-redesign .reports-top-switcher{padding:5px!important;gap:4px!important;border-radius:16px!important;}
html body:not(#rap) .reports-redesign .reports-top-tab{min-height:44px!important;border-radius:12px!important;}
html body:not(#rap) .reports-redesign .reports-filter-tabs{margin-bottom:16px!important;}
html body:not(#rap) .reports-redesign .reports-filter-tab{min-height:44px!important;}
html body:not(#rap) .reports-redesign .reports-data-panel{border-left:5px solid #0F766E!important;}
html body:not(#rap) .reports-redesign .reports-data-sub{margin:-8px 28px 16px;padding-left:29px;font-size:13px;font-weight:500;}

/* Light */
html:not(.dark-theme) body:not(#rap)  .ra-role-evaluators .ra-role-badge{background:#E0E7FF;color:#3730A3;border-color:#B4C0F7;}
html:not(.dark-theme) body:not(#rap)  .ra-role-evaluated .ra-role-badge{background:#D5F3EE;color:#0B5F57;border-color:#8CD5C9;}
html:not(.dark-theme) body:not(#rap)  .ra-role-hint{color:#475569;}
html:not(.dark-theme) body:not(#rap) .reports-redesign .reports-data-sub{color:#475569;}
html:not(.dark-theme) body:not(#rap) .reports-redesign .reports-top-switcher{background:#EEF2FF!important;border:1px solid #C7D2FE!important;}
html:not(.dark-theme) body:not(#rap) .reports-redesign .reports-top-tab:not(.active){background:transparent!important;border-color:transparent!important;box-shadow:none!important;}
html:not(.dark-theme) body:not(#rap) .reports-redesign .reports-top-tab:not(.active):hover{background:rgba(255,255,255,.85)!important;}
html:not(.dark-theme) body:not(#rap) .reports-redesign .reports-filter-tab:not(.active){border-color:#C9D6E4!important;color:#334155!important;}
html:not(.dark-theme) body:not(#rap) .reports-redesign .reports-filter-tab:not(.active):hover{background:#F0FAF8!important;border-color:#8CD5C9!important;color:#0B5F57!important;}
html:not(.dark-theme) body:not(#rap) .reports-redesign .reports-filter-tab.active{background:#DDF4F0!important;border-color:#5CBFB0!important;color:#0B5F57!important;box-shadow:0 2px 8px rgba(15,118,110,.14)!important;}
html:not(.dark-theme) body:not(#rap) .reports-redesign .reports-filter-tab.active b{background:#BFE9E2!important;color:#0B5F57!important;}

/* Dark */
html.dark-theme body:not(#rap)  .ra-role-evaluators .ra-role-badge{background:rgba(129,140,248,.18);color:#C7D2FE;border-color:rgba(129,140,248,.4);}
html.dark-theme body:not(#rap)  .ra-role-evaluated .ra-role-badge{background:rgba(45,212,191,.15);color:#99F6E4;border-color:rgba(45,212,191,.38);}
html.dark-theme body:not(#rap)  .ra-role-hint{color:#A0B3C6;}
html.dark-theme body:not(#rap) .reports-redesign .reports-data-sub{color:#A0B3C6;}
html.dark-theme body:not(#rap) .reports-redesign .reports-top-switcher{background:rgba(129,140,248,.08)!important;border:1px solid rgba(129,140,248,.28)!important;}
html.dark-theme body:not(#rap) .reports-redesign .reports-filter-tab.active{background:rgba(45,212,191,.14)!important;border-color:rgba(45,212,191,.4)!important;color:#99F6E4!important;box-shadow:none!important;}
html.dark-theme body:not(#rap) .reports-redesign .reports-filter-tab.active b{background:rgba(45,212,191,.22)!important;color:#99F6E4!important;}
html.dark-theme body:not(#rap) .reports-redesign .reports-data-panel{border-left-color:#2DD4BF!important;}

@media(max-width:900px){html body:not(#rap) .reports-redesign .reports-top-switcher{width:100%;overflow-x:auto;}}
@media print{.ra-role-label,.reports-data-sub{display:none!important;}}
</style>
<div class="reports-redesign">

    <!-- Top-level report types: keep the current two data sets only. -->
    <?php
// Who is doing the evaluating on the active tab (used in the section captions).
$raWho = ['student'=>'students','peer'=>'colleagues','staff'=>'staff members'][$activeEval] ?? $evaluatorNounP;
?>
    <div class="ra-role-label ra-role-evaluators no-print">
        <span class="ra-role-badge"><i class="fa-solid fa-pen-to-square"></i> Evaluators</span>
        <span class="ra-role-hint">Who submitted the evaluations &mdash; choose one to see its results</span>
    </div>
    <div class="reports-top-row">
    <div class="reports-top-switcher">
        <a href="?group=<?= urlencode($groupFilter) ?>&eval_type=student" class="reports-top-tab student <?= $activeEval==='student'?'active':'' ?>">
            <i class="fa-solid fa-graduation-cap"></i>
            <span>Student Evaluation</span>
        </a>
        <div class="reports-top-divider"></div>
        <a href="?group=<?= urlencode($groupForOtherTabs) ?>&eval_type=peer" class="reports-top-tab peer <?= $activeEval==='peer'?'active':'' ?>">
            <i class="fa-solid fa-people-arrows"></i>
            <span>Peer-to-Peer</span>
        </a>
        <div class="reports-top-divider"></div>
        <a href="?group=All&eval_type=staff" class="reports-top-tab staff <?= $activeEval==='staff'?'active':'' ?>">
            <i class="fa-solid fa-briefcase"></i>
            <span>Staff Evaluation</span>
        </a>
    </div>
            <div class="reports-data-actions">
                <button type="button" class="reports-action-btn reports-filters-btn" onclick="toggleReportsFilters()"><i class="fa-solid fa-filter"></i> Filters</button>
                <div class="reports-search-box">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input id="reportsSearch" type="search" placeholder="Search name, designation..." autocomplete="off" aria-label="Search evaluated users">
                </div>
            </div>
    </div>

    <div class="ra-role-label ra-role-evaluated no-print">
        <span class="ra-role-badge"><i class="fa-solid fa-user-check"></i> Being evaluated</span>
        <span class="ra-role-hint">Personnel rated by <?= htmlspecialchars($raWho) ?> &mdash; filter by group</span>
    </div>
    <?php if ($activeEval === 'student'): ?>
    <div class="reports-filter-tabs">
        <a href="?group=All&eval_type=student" class="reports-filter-tab <?= $groupFilter==='All'?'active':'' ?>">
            <i class="fa-solid fa-users"></i><span>All</span>
        </a>
        <a href="?group=Faculty&eval_type=student" class="reports-filter-tab <?= $groupFilter==='Faculty'?'active':'' ?>">
            <i class="fa-solid fa-chalkboard-user"></i><span>Faculty</span>
        </a>
        <a href="?group=Staff&eval_type=student" class="reports-filter-tab <?= $groupFilter==='Staff'?'active':'' ?>">
            <i class="fa-solid fa-briefcase"></i><span>Staff</span>
        </a>
    </div>
    <?php elseif ($activeEval === 'staff'): ?>
    <div class="reports-filter-tabs">
        <a href="?group=All&eval_type=staff" class="reports-filter-tab active">
            <i class="fa-solid fa-user-tie"></i><span>Executive Assistant</span>
        </a>
    </div>
    <div class="reports-peer-note"><i class="fa-solid fa-circle-info"></i><span><strong>Staff Evaluations</strong> — results submitted by non-teaching staff for the Executive Assistant.</span></div>
    <?php else: ?>
    <div class="reports-filter-tabs">
        <?php foreach (['Faculty' => 'fa-chalkboard-user', 'Staff' => 'fa-id-badge'] as $laneName => $laneIcon): ?>
        <a href="?group=<?= $laneName ?>&eval_type=peer" class="reports-filter-tab <?= $groupFilter===$laneName?'active':'' ?>">
            <i class="fa-solid <?= $laneIcon ?>"></i><span><?= $laneName ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <div class="reports-peer-note"><i class="fa-solid fa-circle-info"></i><span><strong>Peer-to-Peer Evaluations</strong> — <?= $groupFilter==='Staff' ? 'results submitted by College Faculty / Teaching Staff for non-teaching Staff.' : 'results submitted by fellow College Faculty / Teaching Staff.' ?></span></div>
    <?php endif; ?>

    <!-- Main data panel styled after the supplied reference layout. -->
    <section class="reports-data-panel">
        <div class="reports-data-head">
            <div class="reports-data-title">
                <i class="fa-solid fa-list-check"></i>
                <h2>Users Being Evaluated</h2>
                <span>(<?= count($people) ?> evaluated)</span>
                <span class="reports-live-status" id="reportsLiveStatus"><span class="reports-live-dot"></span> Live</span>
            </div>
        </div>
        <div class="reports-data-sub">Rated by <?= htmlspecialchars($raWho) ?> &middot; scores are the average of the ratings they gave</div>

        <div class="reports-active-filter-note" id="reportsFilterNote" hidden>
            <span>Use the tabs above to narrow the current report.</span>
            <button type="button" onclick="clearReportsSearch()"><i class="fa-solid fa-xmark"></i> Clear search</button>
        </div>

        <?php if (empty($people)): ?>
        <div class="reports-empty-state">
            <i class="fa-solid fa-hourglass-half"></i>
            <h3>No <?= $activeEval==='peer' ? 'peer-to-peer' : ($activeEval==='staff' ? 'staff' : 'student') ?> evaluations yet</h3>
            <p>Personnel will appear here once <?= $evaluatorNounP ?> have evaluated them.</p>
        </div>
        <?php else: ?>
        <div class="reports-table-wrap">
            <table class="reports-table" id="reportsTable">
                <thead>
                    <tr>
                        <th class="col-no">NO.</th>
                        <th>NAME</th>
                        <th>DESIGNATION</th>
                        <th>ROLE</th>
                        <th>OVERALL AVG. SCORE</th>
                        <th>LAST EVALUATED</th>
                        <th class="col-actions">ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($people as $i => $p):
                    $avg = $p['avg_score'] !== null ? round((float)$p['avg_score'], 2) : null;
                    $rawRole = strtolower(trim((string)($p['role'] ?? '')));
                    if ($activeEval === 'student') {
                        $resolvedGroup = strtolower(trim((string)($p['student_resolved_group'] ?? '')));
                        if ($resolvedGroup === 'teacher') {
                            $roleLabel = 'Faculty';
                        } elseif ($resolvedGroup === 'staff') {
                            $roleLabel = 'Staff';
                        } elseif ($resolvedGroup === 'school_head' && $rawRole === 'principal') {
                            $roleLabel = 'Principal';
                        } elseif ($resolvedGroup === 'school_head' && $rawRole === 'dean') {
                            $roleLabel = 'Dean';
                        } else {
                            $roleLabel = ucfirst($rawRole);
                        }
                    } elseif ($activeEval === 'staff') {
                        $roleLabel = 'Executive Assistant';
                    } else {
                        // In Peer-to-Peer, every row has already passed the explicit
                        // Faculty-group filter. A role='staff' account
                        // admitted via sector/secondary-role/teaching assignment is
                        // displayed as Teaching Staff, not ordinary Non-Teaching Staff.
                        if ($activeEval === 'peer' && $rawRole === 'dean') $roleLabel = 'Dean';
                        elseif ($activeEval === 'peer' && $groupFilter === 'Staff') $roleLabel = 'Staff';
                        else $roleLabel = in_array($rawRole, ['teacher','faculty'], true) ? 'Faculty' : 'Teaching Staff';
                    }
                    $isFac = in_array($roleLabel, ['Faculty','Teaching Staff'], true);
                    $lastEval = !empty($p['last_evaluated']) ? date('M d, Y', strtotime($p['last_evaluated'])) : '—';
                    $searchText = trim(($p['full_name'] ?? '') . ' ' . ($p['designation'] ?? '') . ' ' . $roleLabel);
                ?>
                    <tr class="reports-person-row" data-search="<?= htmlspecialchars(strtolower($searchText)) ?>" data-desig="<?= htmlspecialchars((string)($p['designation'] ?? '')) ?>">
                        <td class="col-no"><?= $i + 1 ?></td>
                        <td>
                            <a class="reports-person-link" href="?view=students&target_id=<?= $p['id'] ?>&group=<?= urlencode($groupFilter) ?>&eval_type=<?= $activeEval ?>">
                                <?php if($p['photo']): ?><img class="reports-person-photo" src="../image/<?= htmlspecialchars($p['photo']) ?>" alt=""/><?php else: ?><span class="reports-person-photo placeholder"><i class="fa-solid fa-user"></i></span><?php endif; ?>
                                <span class="reports-person-name"><?= htmlspecialchars($p['full_name']) ?></span>
                            </a>
                        </td>
                        <td class="reports-designation-cell"><?= !empty($p['designation']) ? htmlspecialchars((string)($p['designation'] ?? '')) : '—' ?></td>
                        <td><span class="reports-role-badge <?= $isFac ? 'faculty' : (in_array($roleLabel, ['Dean','Principal'], true) ? strtolower($roleLabel) : 'staff') ?>"><i class="fa-solid <?= $roleLabel === 'Dean' ? 'fa-graduation-cap' : ($roleLabel === 'Principal' ? 'fa-user-tie' : ($isFac ? 'fa-chalkboard-user' : 'fa-briefcase')) ?>"></i><?= htmlspecialchars($roleLabel) ?></span></td>
                        <td><span class="reports-score <?= $avg !== null && $avg >= 4.5 ? 'high' : ($avg !== null && $avg >= 3.5 ? 'mid' : 'low') ?>"><?= $avg !== null ? number_format($avg,2) : '—' ?></span></td>
                        <td class="reports-date"><?= htmlspecialchars($lastEval) ?></td>
                        <td class="col-actions">
                            <div class="reports-row-actions">
                                <a class="reports-view-btn" title="View" aria-label="View evaluation" href="?view=students&target_id=<?= $p['id'] ?>&group=<?= urlencode($groupFilter) ?>&eval_type=<?= $activeEval ?>"><i class="fa-solid fa-eye"></i></a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="reports-no-search" id="reportsNoSearch" hidden>
            <i class="fa-solid fa-magnifying-glass"></i> No evaluated users match your search.
        </div>
        <?php endif; ?>
    </section>
</div>

<style>
/* ── Reference-style Reports layout: visual-only redesign, current data preserved ── */
.reports-redesign{display:flex;flex-direction:column;gap:0;margin-top:-2px;}
.reports-top-switcher{display:flex;align-items:center;justify-content:flex-start;gap:7px;width:max-content;max-width:100%;margin:0 0 0 0;border:0;border-radius:0;background:transparent;overflow:visible;box-shadow:none;}
.reports-top-tab{display:inline-flex;flex:0 0 auto;min-width:0;align-items:center;justify-content:center;gap:7px;min-height:38px;padding:0 16px;border:1px solid #CFE0F0;border-radius:11px;color:#56708A;text-decoration:none;font-size:12.5px;font-weight:700;letter-spacing:.01em;transition:.18s;background:#fff;position:relative;white-space:nowrap;box-shadow:0 1px 3px rgba(27,67,106,.03);}
.reports-top-tab.student{color:#2764D4;}
.reports-top-tab i{font-size:12.5px;}
.reports-top-tab.active{background:#E9F2FF;color:#2161CF;border-color:#7CB0FF;box-shadow:0 2px 7px rgba(45,102,225,.08);}
.reports-top-tab.peer.active{background:#EEE9FF;color:#6547BE;border-color:#D4C7FF;box-shadow:0 2px 7px rgba(101,71,190,.08);}
.reports-top-tab.staff{color:#0F766E;}
.reports-top-tab.staff.active{background:#E6F7F5;color:#0F766E;border-color:#8ED8CF;box-shadow:0 2px 7px rgba(13,148,136,.10);}
.reports-top-tab.staff.active .reports-top-badge{background:#CDEFEA;color:#0F766E;}
.reports-top-tab:hover{background:#F7FAFD;color:#274B6C;}
.reports-top-badge{min-width:23px;padding:4px 7px;border-radius:999px;background:#ECF2F7;color:#58718B;font-size:10px;text-align:center;line-height:1;}
.reports-top-tab.student.active .reports-top-badge{background:#D4E5FF;color:#2161CF;}
.reports-top-tab.peer.active .reports-top-badge{background:#E6DFFF;color:#6547BE;}
.reports-top-divider{display:none;}
.reports-toolbar{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;padding:34px 0 22px;min-height:102px;}
.reports-toolbar-spacer{flex:1;min-height:1px;}
.reports-toolbar-actions{display:flex;align-items:center;gap:10px;}
.reports-print-btn{border:0;border-radius:13px;padding:12px 20px;background:#2D66E1;color:#fff;font:700 13px 'Inter',sans-serif;display:inline-flex;align-items:center;gap:8px;cursor:pointer;box-shadow:0 6px 16px rgba(45,102,225,.18);}
.reports-print-btn:hover{background:#2459C9;}
.reports-archive-link{display:inline-flex;align-items:center;gap:7px;padding:11px 15px;border:1px solid #BCD0E5;border-radius:11px;background:#fff;color:#38546E;text-decoration:none;font-size:12px;font-weight:700;}
.reports-archive-link:hover{background:#F6F9FC;border-color:#8FAFCB;color:#173957;}
.reports-archive-count{padding:2px 7px;border-radius:999px;background:#EDF2F7;color:#64748B;font-size:10px;}
.reports-filter-tabs{display:flex;gap:7px;flex-wrap:wrap;margin-top:20px;margin-bottom:12px;}
.reports-filter-tab{display:inline-flex;align-items:center;gap:7px;min-height:38px;padding:0 16px;border:1px solid #CFE0F0;border-radius:11px;background:#fff;color:#56708A;text-decoration:none;font-size:12.5px;font-weight:700;box-shadow:0 1px 3px rgba(27,67,106,.03);}
.reports-filter-tab:hover{background:#F7FAFD;color:#274B6C;}
.reports-filter-tab.active{background:#E9F2FF;border-color:#7CB0FF;color:#2161CF;box-shadow:0 2px 7px rgba(45,102,225,.08);}
.reports-filter-tab i{color:inherit;}
.reports-filter-tab b{font-size:10px;min-width:23px;text-align:center;padding:4px 6px;border-radius:999px;background:#ECF2F7;color:#58718B;}
.reports-filter-tab.active b{background:#D4E5FF;color:#2161CF;}
.reports-sub-filters,.reports-designation-filters{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-bottom:14px;padding:10px 12px;border:1px solid #D6E4F0;border-radius:12px;background:#F8FBFE;}
.reports-sub-label{font-size:10px;font-weight:800;letter-spacing:.08em;color:#71859A;margin-right:2px;}
.reports-sub-tab,.reports-designation-pill{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border:1px solid transparent;border-radius:9px;color:#617A91;text-decoration:none;font-size:12px;font-weight:700;}
.reports-sub-tab:hover,.reports-designation-pill:hover{background:#EEF5FB;color:#244969;}
.reports-sub-tab.active,.reports-designation-pill.active{background:#EEE9FF;border-color:#D4C7FF;color:#6547BE;}
.reports-sub-tab b,.reports-designation-pill b{font-size:10px;padding:2px 6px;border-radius:999px;background:#EDF2F7;color:#64748B;}
.reports-peer-note{display:flex;align-items:center;gap:9px;margin:1px 0 14px;padding:10px 13px;border:1px solid #E5DAFF;border-radius:10px;background:#FAF8FF;color:#6C6380;font-size:11px;}
.reports-peer-note i{color:#7C5FD9;}
.reports-peer-note strong{color:#6641BB;}
.reports-data-panel{border:1px solid #CFE0F0;border-radius:15px;background:#fff;box-shadow:0 6px 18px rgba(28,64,92,.06);overflow:hidden;}
.reports-data-head{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:22px 28px 18px;}
.reports-data-title{display:flex;align-items:center;gap:11px;min-width:0;}
.reports-data-title i{color:#2B67DE;font-size:18px;}
.reports-data-title h2{font-family:'Rajdhani',sans-serif;font-size:23px;font-weight:700;color:#122E4A;margin:0;}
.reports-data-title span{color:#637B92;font-size:11px;white-space:nowrap;}
.reports-data-actions{display:flex;align-items:center;gap:10px;}
.reports-action-btn{height:41px;padding:0 15px;border:1px solid #C6D8E8;border-radius:10px;background:#fff;color:#27445F;font:700 12px 'Inter',sans-serif;display:inline-flex;align-items:center;gap:7px;cursor:pointer;}
.reports-action-btn:hover{background:#F5F9FD;border-color:#9DB9D2;}
.reports-filters-btn{padding:0 16px;}
.reports-search-box{display:flex;align-items:center;gap:8px;width:275px;height:41px;padding:0 12px;border:1px solid #B9CFE3;border-radius:10px;background:#fff;}
.reports-search-box i{color:#6A8299;font-size:13px;}
.reports-search-box input{width:100%;border:0!important;outline:0!important;background:transparent!important;padding:0!important;box-shadow:none!important;color:#173956!important;font-size:12px!important;}
.reports-search-box input::placeholder{color:#91A4B6!important;}
.reports-active-filter-note{margin:0 28px 12px;padding:9px 12px;border-radius:9px;background:#F8FBFE;border:1px solid #DCE8F2;color:#617B92;font-size:11px;}
.reports-active-filter-note button{float:right;border:0;background:none;color:#5C45B5;font-size:11px;font-weight:700;cursor:pointer;}
.reports-table-wrap{overflow-x:auto;}
.reports-table{width:100%;border-collapse:collapse;min-width:1020px;}
.reports-table th{padding:12px 16px;background:#F7FAFD;color:#60778D!important;border-top:1px solid #DDE8F1;border-bottom:1px solid #D8E4EE;font-size:10px!important;font-weight:800!important;letter-spacing:.07em;text-align:left;white-space:nowrap;}
.reports-table td{padding:16px;border-bottom:1px solid #E0E9F1;color:#173956!important;font-size:13px!important;vertical-align:middle;}
.reports-table tbody tr:hover td{background:#F9FBFD!important;}
.reports-table .col-no{width:58px;text-align:center;}
.reports-table .col-actions{width:225px;}
.reports-person-link{display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;min-width:240px;}
.reports-person-photo{width:42px;height:42px;border-radius:50%;object-fit:cover;border:1px solid #C9D8E6;flex:0 0 auto;}
.reports-person-photo.placeholder{display:inline-flex;align-items:center;justify-content:center;background:#F2F6F9;color:#6E8294;font-size:15px;}
.reports-person-name{font-size:13px;font-weight:800;color:#0F2944;line-height:1.2;}
.reports-designation-cell{max-width:310px;line-height:1.35;color:#21435F!important;}
.reports-role-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap;border:1px solid;}
.reports-role-badge.faculty{background:#EFF5FF;border-color:#A9C9FF;color:#2563D8;}
.reports-role-badge.staff{background:#F3ECFF;border-color:#D0B8FF;color:#733DD0;}
.reports-role-badge.dean{background:#F0EBFF;border-color:#D8C8FF;color:#7450C9;}
.reports-role-badge.principal{background:#FFF4E3;border-color:#F1C98B;color:#B96A00;}
.reports-score{font-size:15px;font-weight:800;}
.reports-score.high{color:#08A06A;}
.reports-score.mid{color:#B06B00;}
.reports-score.low{color:#CF3D3D;}
.reports-date{color:#2E4B64!important;white-space:nowrap;}
.reports-row-actions{display:flex;align-items:center;gap:7px;}
.reports-view-btn,.reports-archive-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-width:72px;padding:8px 11px;border-radius:9px;text-decoration:none;font-size:11px;font-weight:800;cursor:pointer;font-family:'Inter',sans-serif;}
.reports-view-btn{background:#F1F6FF;border:1px solid #B5D0FF;color:#2363D4;}
.reports-view-btn:hover{background:#E6F0FF;}
.reports-archive-btn{background:#fff;border:1px solid #C7D7E5;color:#687E92;}
.reports-archive-btn:hover{background:#F7FAFC;color:#314E68;border-color:#AABFD2;}
.reports-empty-state{text-align:center;padding:64px 24px;color:#6B8195;}
.reports-empty-state i{display:block;font-size:34px;margin-bottom:13px;color:#9CB0C1;}
.reports-empty-state h3{font-family:'Rajdhani',sans-serif;font-size:20px;color:#163450;margin-bottom:5px;}
.reports-empty-state p{font-size:12px;color:#71869A;}
.reports-no-search{text-align:center;padding:26px;color:#71869A;font-size:12px;}
.reports-redesign [hidden]{display:none!important;}
.reports-live-status{display:inline-flex;align-items:center;gap:5px;margin-left:3px;padding:4px 8px;border-radius:999px;background:#ECFDF5;border:1px solid #BBE7D2;color:#0F8A61;font-size:10px;font-weight:800;white-space:nowrap;}
.reports-live-dot{width:6px;height:6px;border-radius:50%;background:#10B981;box-shadow:0 0 0 0 rgba(16,185,129,.45);animation:reportsLivePulse 2s infinite;}
.reports-live-status.stale{background:#F8FAFC;border-color:#D6E0EA;color:#71869A;}
.reports-live-status.stale .reports-live-dot{background:#94A3B8;box-shadow:none;animation:none;}
@keyframes reportsLivePulse{0%{box-shadow:0 0 0 0 rgba(16,185,129,.4);}70%{box-shadow:0 0 0 6px rgba(16,185,129,0);}100%{box-shadow:0 0 0 0 rgba(16,185,129,0);}}
@media (max-width:900px){
  .reports-toolbar{align-items:flex-start;flex-direction:column;padding-bottom:16px;}
  .reports-toolbar-actions{width:100%;justify-content:flex-end;}
  .reports-data-head{align-items:flex-start;flex-direction:column;}
  .reports-data-actions{width:100%;flex-wrap:wrap;}
  .reports-search-box{flex:1;min-width:210px;}
}
@media (max-width:640px){
  .reports-top-switcher{width:100%;max-width:none;flex-wrap:wrap;}
  .reports-top-tab{flex:0 0 auto;min-height:44px;padding:0 14px;font-size:12px;gap:7px;}
  .reports-toolbar-actions{justify-content:stretch;}
  .reports-archive-link,.reports-print-btn{flex:1;justify-content:center;}
  .reports-data-head{padding:18px 16px;}
  .reports-search-box{order:3;width:100%;flex-basis:100%;}
  .reports-action-btn{flex:1;justify-content:center;}
}
@media print{
  .reports-toolbar-actions,.reports-data-actions,.reports-row-actions,.reports-sub-filters,.reports-designation-filters{display:none!important;}
  .reports-redesign{margin:0;}
  .reports-top-switcher{box-shadow:none;}
  .reports-data-panel{box-shadow:none;border-color:#CBD5E1;}
}
</style>

<script>
function filterByDesig(desig, evt) {
    if (evt) evt.preventDefault();
    document.querySelectorAll('.reports-designation-pill').forEach(t => t.classList.remove('active'));
    if (evt && evt.target) {
        const pill = evt.target.closest('.reports-designation-pill');
        if (pill) pill.classList.add('active');
    }
    document.querySelectorAll('#reportsTable tbody .reports-person-row').forEach(row => {
        row.style.display = (desig === 'all' || row.dataset.desig === desig) ? '' : 'none';
    });
}
function toggleReportsFilters() {
    const designation = document.getElementById('designationFilters');
    const note = document.getElementById('reportsFilterNote');
    if (designation) {
        designation.hidden = !designation.hidden;
        if (!designation.hidden) designation.scrollIntoView({behavior:'smooth', block:'nearest'});
        if (note) note.hidden = true;
        return;
    }
    if (note) note.hidden = !note.hidden;
}
function clearReportsSearch() {
    const input = document.getElementById('reportsSearch');
    if (input) { input.value = ''; input.dispatchEvent(new Event('input')); input.focus(); }
}
document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('reportsSearch');
    const noSearch = document.getElementById('reportsNoSearch');
    if (!input) return;
    input.addEventListener('input', () => {
        const q = input.value.trim().toLowerCase();
        let visible = 0;
        document.querySelectorAll('#reportsTable tbody .reports-person-row').forEach(row => {
            const match = !q || row.dataset.search.includes(q);
            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        if (noSearch) noSearch.hidden = visible !== 0 || !q;
        const note = document.getElementById('reportsFilterNote');
        if (note) note.hidden = !q;
    });
});
// ── LIVE REPORT UPDATES ────────────────────────────────────────────────
// Reports reads the same evaluation_tracker records used by the tracker/results
// pages. Polling the lightweight endpoint makes newly submitted evaluations
// appear without requiring the Dean to manually reload the report.
(function(){
    const status = document.getElementById('reportsLiveStatus');
    const search = document.getElementById('reportsSearch');
    const storageKey = 'pbiDeanReportsSearch';
    if (search) {
        const saved = sessionStorage.getItem(storageKey);
        if (saved && !search.value) {
            search.value = saved;
            search.dispatchEvent(new Event('input'));
        }
        search.addEventListener('input', function(){ sessionStorage.setItem(storageKey, search.value); });
    }
    if (status) status.title = 'Live — checks for new evaluations every 30 seconds';
    const base = 'dean_reports_live_api.php?eval_type=<?= urlencode($activeEval) ?>&group=<?= urlencode($groupFilter) ?>';
    let lastSignature = null;
    let busy = false;
    function pollReports(){
        if (busy || document.hidden) return;
        busy = true;
        fetch(base, {credentials:'same-origin', cache:'no-store'})
            .then(function(r){ if(!r.ok) throw new Error('status'); return r.json(); })
            .then(function(data){
                if (status) status.classList.remove('stale');
                const sig = data && data.signature ? data.signature : '';
                if (lastSignature === null) {
                    lastSignature = sig;
                    return;
                }
                if (sig && sig !== lastSignature) {
                    if (status) {
                        status.classList.add('updating');
                        status.title = 'New evaluation/report data detected — refreshing…';
                        status.innerHTML = '<span class=\"reports-live-dot\"></span> Updating…';
                    }
                    setTimeout(function(){ window.location.reload(); }, 450);
                }
            })
            .catch(function(){ if (status) status.classList.add('stale'); })
            .finally(function(){ busy = false; });
    }
    pollReports();
    setInterval(pollReports, 30000);
    document.addEventListener('visibilitychange', function(){ if (!document.hidden) pollReports(); });
})();
</script>
<?php $mysqli->close(); ?>

<style id="executive-original-theme-3">
:root{
 --dark:#0A192F;--mid:#172A45;--inner:#0F1F3D;
 --violet:#7C5FD9;--violet-h:#9C85F0;--violet-dark:#5F45B8;
 --light:#E0E6F0;--muted:#A0B3C6;--border:rgba(255,255,255,.08);
 --accent:#7C5FD9;--accent-h:#9C85F0;--good:#10B981;--danger:#f05454;
 --page-bg:#0A192F;--card-bg:rgba(23,42,69,.85);--card-border:rgba(255,255,255,.08);
}
html{background:var(--dark)!important;color-scheme:dark!important;}
body{background:linear-gradient(rgba(5,18,36,.72),rgba(5,18,36,.82)),url('../background.png') center center/cover no-repeat fixed!important;background-color:var(--dark)!important;color:var(--light)!important;}
.main,.content,.page-content{color:var(--light)!important;}
.page-title,.page-header h1,.section-title,.sheet-name,.target-name{color:#fff!important;}
.page-sub,.sheet-desig,.target-desig,.muted,.hint,.helper,.description,p{color:var(--muted)!important;}
/* Cards/panels */
.card,.panel,.section,.table-card,.content-card,.stat-card,.sum-card,.standing-panel,.eval-card,.eval-banner,.info-banner,.history-card,.gl-card,.amber-card,.green-card,.red-card,.person-row,.target-card,.no-eval,.no-data,.no-evaluated,.comment-section,.avg-summary,.cat-section,.people-list,.evaluator-grid{
 background:rgba(23,42,69,.85)!important;border-color:rgba(255,255,255,.08)!important;box-shadow:0 8px 32px rgba(0,0,0,.45)!important;color:var(--light)!important;
}
.table-wrap{background:transparent!important;color:var(--light)!important;}
table.data th,table.data td,th,td{color:var(--light)!important;border-color:rgba(255,255,255,.08)!important;}
.q-table{color:var(--light)!important;}
.q-table th{color:var(--muted)!important;background:rgba(15,31,61,.65)!important;border-color:rgba(255,255,255,.08)!important;}
.q-table td{color:var(--light)!important;border-color:rgba(255,255,255,.06)!important;}
/* Tabs and filters */
.eval-switcher,.tabs,.level-tabs,.status-tabs{background:rgba(23,42,69,.85)!important;border-color:rgba(255,255,255,.08)!important;box-shadow:none!important;}
.eval-tab,.tab,.level-tab,.status-tab,.group-tab,.desig-subtab{color:var(--muted)!important;background:transparent!important;}
.eval-tab:hover,.tab:hover,.level-tab:hover,.status-tab:hover,.group-tab:hover,.desig-subtab:hover{color:#fff!important;background:rgba(124,95,217,.08)!important;}
.eval-tab.student.active,.group-tab.active,.desig-subtab.active{background:rgba(124,95,217,.14)!important;color:var(--violet-h)!important;border-color:rgba(124,95,217,.35)!important;}
.eval-tab.student.active::after{background:var(--violet)!important;}
.eval-tab.peer.active{background:rgba(124,95,217,.14)!important;color:var(--violet-h)!important;}
.eval-tab.peer.active::after{background:var(--violet)!important;}
.tab-badge{background:rgba(255,255,255,.08)!important;color:var(--muted)!important;}
.eval-tab.student.active .tab-badge,.eval-tab.peer.active .tab-badge{background:rgba(124,95,217,.18)!important;color:var(--violet-h)!important;}
/* Buttons/links */
.btn-print,.btn-print.no-print,.back-btn,.btn,.action-btn,.btn-archive,.btn-restore,.btn-solid,.btn-archived-link{background:rgba(124,95,217,.12)!important;border:1px solid rgba(124,95,217,.35)!important;color:var(--violet-h)!important;}
.btn-print:hover,.back-btn:hover,.btn:hover,.action-btn:hover,.btn-archive:hover,.btn-restore:hover,.btn-solid:hover,.btn-archived-link:hover{background:rgba(124,95,217,.22)!important;color:#fff!important;}
.btn-solid{background:var(--violet)!important;color:#fff!important;}
/* accents */
.eval-banner{background:rgba(124,95,217,.08)!important;border-color:rgba(124,95,217,.25)!important;}
.eval-banner-title,.eval-banner-icon,.cat-title,.comment-title,.section h2 i,.section h2{color:var(--violet-h)!important;}
.period-badge{background:rgba(124,95,217,.14)!important;border-color:rgba(124,95,217,.3)!important;color:var(--violet-h)!important;}
.bar-fill,.avg-bar-fill{background:linear-gradient(90deg,var(--violet-dark),var(--violet-h))!important;}
.score-bar-bg,.eval-bar-bg,.avg-bar-bg,.bar-wrap{background:rgba(255,255,255,.08)!important;}
.standing-title.top,.standing-score,.pstat-val,.avg-score-big,.avg-score-label{color:#4ade80!important;}
.standing-title.low{color:#f87171!important;}
.person-row:hover,.standing-item:hover{border-color:rgba(124,95,217,.4)!important;background:rgba(124,95,217,.05)!important;}
.comment-text{background:rgba(15,31,61,.7)!important;color:var(--light)!important;}
.empty-note,.no-comment,.no-eval{color:var(--muted)!important;}
label,th{color:var(--muted)!important;}
input,select,textarea{background:#0F1F3D!important;color:var(--light)!important;border-color:rgba(255,255,255,.12)!important;}
input::placeholder,textarea::placeholder{color:#7890a8!important;}
</style>


<style id="dean-reports-dark-final">
/* -------------------------------------------------------------------------
   Final dark-mode layer for Dean Evaluation Reports.
   This is intentionally loaded last because this page contains several
   legacy light-theme blocks and view-specific inline styles.
   ------------------------------------------------------------------------- */
html.dark-theme body {
  background:#0A192F !important;
  background-image:none !important;
  color:#E0E6F0 !important;
}
html.dark-theme .main {
  background:#0F1F3D !important;
  color:#E0E6F0 !important;
}

/* Main analytics tabs / filters */
html.dark-theme .reports-top-tab,
html.dark-theme .reports-filter-tab {
  background:#172A45 !important;
  border-color:rgba(255,255,255,.12) !important;
  color:#A0B3C6 !important;
  box-shadow:none !important;
}
html.dark-theme .reports-top-tab:hover,
html.dark-theme .reports-filter-tab:hover {
  background:#1D3350 !important;
  color:#E0E6F0 !important;
}
html.dark-theme .reports-top-tab.active,
html.dark-theme .reports-top-tab.peer.active,
html.dark-theme .reports-filter-tab.active {
  background:rgba(124,95,217,.16) !important;
  border-color:rgba(156,133,240,.38) !important;
  color:#C4B5FD !important;
  box-shadow:none !important;
}
html.dark-theme .reports-top-badge,
html.dark-theme .reports-filter-tab b {
  background:#0F1F3D !important;
  color:#A0B3C6 !important;
}
html.dark-theme .reports-top-tab.active .reports-top-badge,
html.dark-theme .reports-filter-tab.active b {
  background:rgba(124,95,217,.22) !important;
  color:#C4B5FD !important;
}

html.dark-theme .reports-sub-filters,
html.dark-theme .reports-designation-filters,
html.dark-theme .reports-peer-note {
  background:#172A45 !important;
  border-color:rgba(255,255,255,.08) !important;
  color:#A0B3C6 !important;
}
html.dark-theme .reports-sub-label { color:#A0B3C6 !important; }
html.dark-theme .reports-sub-tab,
html.dark-theme .reports-designation-pill {
  color:#A0B3C6 !important;
}
html.dark-theme .reports-sub-tab:hover,
html.dark-theme .reports-designation-pill:hover {
  background:#1D3350 !important;
  color:#E0E6F0 !important;
}
html.dark-theme .reports-sub-tab.active,
html.dark-theme .reports-designation-pill.active {
  background:rgba(124,95,217,.16) !important;
  border-color:rgba(156,133,240,.35) !important;
  color:#C4B5FD !important;
}
html.dark-theme .reports-sub-tab b,
html.dark-theme .reports-designation-pill b {
  background:#0F1F3D !important;
  color:#A0B3C6 !important;
}

/* Report data panel */
html.dark-theme .reports-data-panel {
  background:#172A45 !important;
  border-color:rgba(255,255,255,.08) !important;
  box-shadow:0 8px 28px rgba(0,0,0,.28) !important;
}
html.dark-theme .reports-data-title h2 { color:#E0E6F0 !important; }
html.dark-theme .reports-data-title span { color:#A0B3C6 !important; }
html.dark-theme .reports-data-title i { color:#9C85F0 !important; }
html.dark-theme .reports-action-btn {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.14) !important;
  color:#E0E6F0 !important;
}
html.dark-theme .reports-action-btn:hover { background:#1D3350 !important; }
html.dark-theme .reports-search-box {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.14) !important;
}
html.dark-theme .reports-search-box i { color:#8EA5BB !important; }
html.dark-theme .reports-search-box input {
  background:transparent !important;
  color:#E0E6F0 !important;
}
html.dark-theme .reports-search-box input::placeholder { color:#6B84A0 !important; }
html.dark-theme .reports-active-filter-note {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.08) !important;
  color:#A0B3C6 !important;
}
html.dark-theme .reports-active-filter-note button { color:#C4B5FD !important; }

/* Main report table */
html.dark-theme .reports-table th {
  background:#0F1F3D !important;
  color:#A0B3C6 !important;
  border-top-color:rgba(255,255,255,.08) !important;
  border-bottom-color:rgba(255,255,255,.10) !important;
}
html.dark-theme .reports-table td {
  background:#172A45 !important;
  color:#E0E6F0 !important;
  border-bottom-color:rgba(255,255,255,.08) !important;
}
html.dark-theme .reports-table tbody tr:hover td {
  background:#1D3350 !important;
}
html.dark-theme .reports-person-name { color:#E0E6F0 !important; }
html.dark-theme .reports-designation-cell { color:#B6C6D6 !important; }
html.dark-theme .reports-person-photo { border-color:rgba(255,255,255,.12) !important; }
html.dark-theme .reports-person-photo.placeholder {
  background:#0F1F3D !important;
  color:#8EA5BB !important;
}
html.dark-theme .reports-role-badge.faculty {
  background:rgba(37,99,216,.16) !important;
  border-color:rgba(96,165,250,.32) !important;
  color:#93C5FD !important;
}
html.dark-theme .reports-role-badge.staff,
html.dark-theme .reports-role-badge.dean {
  background:rgba(124,95,217,.16) !important;
  border-color:rgba(156,133,240,.32) !important;
  color:#C4B5FD !important;
}
html.dark-theme .reports-role-badge.principal {
  background:rgba(217,119,6,.16) !important;
  border-color:rgba(217,119,6,.32) !important;
  color:#FBBF24 !important;
}
html.dark-theme .reports-score.high { color:#4ADE80 !important; }
html.dark-theme .reports-score.mid { color:#FBBF24 !important; }
html.dark-theme .reports-score.low { color:#FCA5A5 !important; }
html.dark-theme .reports-date { color:#A0B3C6 !important; }
html.dark-theme .reports-view-btn {
  background:rgba(59,130,246,.14) !important;
  border-color:rgba(96,165,250,.32) !important;
  color:#93C5FD !important;
}
html.dark-theme .reports-view-btn:hover { background:rgba(59,130,246,.22) !important; }
html.dark-theme .reports-archive-btn {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.14) !important;
  color:#A0B3C6 !important;
}
html.dark-theme .reports-archive-btn:hover { background:#1D3350 !important; color:#E0E6F0 !important; }
html.dark-theme .reports-empty-state,
html.dark-theme .reports-no-search { color:#A0B3C6 !important; }
html.dark-theme .reports-empty-state h3 { color:#E0E6F0 !important; }
html.dark-theme .reports-empty-state i { color:#71879C !important; }
html.dark-theme .reports-empty-state p { color:#A0B3C6 !important; }
html.dark-theme .reports-live-status {
  background:rgba(16,185,129,.14) !important;
  border-color:rgba(16,185,129,.32) !important;
  color:#4ADE80 !important;
}
html.dark-theme .reports-live-status.stale {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.10) !important;
  color:#8EA5BB !important;
}

/* Shared report/detail controls */
html.dark-theme .back-btn,
html.dark-theme .detail-back,
html.dark-theme .btn-archived-link {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.14) !important;
  color:#E0E6F0 !important;
}
html.dark-theme .back-btn:hover,
html.dark-theme .detail-back:hover,
html.dark-theme .btn-archived-link:hover {
  background:#1D3350 !important;
  color:#C4B5FD !important;
}

/* Detail / individual evaluation view */
html.dark-theme .detail-eval-banner {
  background:rgba(59,130,246,.10) !important;
  border-color:rgba(96,165,250,.24) !important;
}
html.dark-theme .detail-eval-icon {
  background:rgba(59,130,246,.14) !important;
  border-color:rgba(96,165,250,.28) !important;
  color:#93C5FD !important;
}
html.dark-theme .detail-eval-title { color:#93C5FD !important; }
html.dark-theme .detail-eval-sub { color:#A0B3C6 !important; }
html.dark-theme .detail-person-card,
html.dark-theme .detail-results-panel,
html.dark-theme .detail-legend {
  background:#172A45 !important;
  border-color:rgba(255,255,255,.08) !important;
  box-shadow:0 8px 28px rgba(0,0,0,.25) !important;
}
html.dark-theme .detail-person-avatar.ph {
  background:#0F1F3D !important;
  color:#8EA5BB !important;
}
html.dark-theme .detail-person-name { color:#E0E6F0 !important; }
html.dark-theme .detail-person-desig,
html.dark-theme .detail-stat-label,
html.dark-theme .detail-legend-text,
html.dark-theme .detail-results-title span,
html.dark-theme .detail-year-filter label { color:#A0B3C6 !important; }
html.dark-theme .detail-stat-value { color:#93C5FD !important; }
html.dark-theme .detail-stat-value.score { color:#4ADE80 !important; }
html.dark-theme .detail-legend-title { color:#E0E6F0 !important; }
html.dark-theme .detail-year-filter select {
  background:#0F1F3D !important;
  color:#E0E6F0 !important;
  border-color:rgba(255,255,255,.14) !important;
}
html.dark-theme .detail-results-title h2 { color:#E0E6F0 !important; }
html.dark-theme .detail-results-title i { color:#9C85F0 !important; }
html.dark-theme .detail-results-table th {
  background:#0F1F3D !important;
  color:#A0B3C6 !important;
  border-bottom-color:rgba(255,255,255,.10) !important;
}
html.dark-theme .detail-results-table td {
  background:#172A45 !important;
  color:#E0E6F0 !important;
  border-bottom-color:rgba(255,255,255,.08) !important;
}
html.dark-theme .detail-results-table tbody tr:hover td { background:#1D3350 !important; }
html.dark-theme .detail-evaluator,
html.dark-theme .detail-date,
html.dark-theme .detail-role { color:#B8C7D7 !important; }
html.dark-theme .detail-rating {
  background:rgba(16,185,129,.14) !important;
  color:#4ADE80 !important;
}
html.dark-theme .detail-view-btn {
  background:rgba(59,130,246,.14) !important;
  border-color:rgba(96,165,250,.32) !important;
  color:#93C5FD !important;
}
html.dark-theme .detail-view-btn:hover { background:rgba(59,130,246,.22) !important; }
html.dark-theme .detail-empty { color:#A0B3C6 !important; }
html.dark-theme .detail-empty h3 { color:#E0E6F0 !important; }
html.dark-theme .detail-empty p { color:#A0B3C6 !important; }

/* Individual sheet view */
html.dark-theme .sheet-header,
html.dark-theme .scale-bar,
html.dark-theme .comment-section,
html.dark-theme .avg-summary {
  background:#172A45 !important;
  border-color:rgba(255,255,255,.08) !important;
  color:#E0E6F0 !important;
}
html.dark-theme .sheet-name,
html.dark-theme .avg-score-big,
html.dark-theme .avg-score-label { color:#E0E6F0 !important; }
html.dark-theme .sheet-desig,
html.dark-theme .eval-by-label,
html.dark-theme .eval-by-date,
html.dark-theme .avg-out-of,
html.dark-theme .scale-item,
html.dark-theme .avg-bar-label { color:#A0B3C6 !important; }
html.dark-theme .eval-by-name { color:#93C5FD !important; }
html.dark-theme .sheet-avatar-ph {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.12) !important;
  color:#8EA5BB !important;
}
html.dark-theme .eval-type-chip {
  background:rgba(59,130,246,.12) !important;
  border-color:rgba(96,165,250,.28) !important;
  color:#93C5FD !important;
}
html.dark-theme .q-table {
  background:#172A45 !important;
  border-color:rgba(255,255,255,.08) !important;
}
html.dark-theme .q-table thead tr,
html.dark-theme .q-table th {
  background:#0F1F3D !important;
  color:#A0B3C6 !important;
}
html.dark-theme .q-table td {
  background:#172A45 !important;
  color:#E0E6F0 !important;
  border-top-color:rgba(255,255,255,.08) !important;
}
html.dark-theme .q-table tr:hover td { background:#1D3350 !important; }
html.dark-theme .rating-badge {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.08) !important;
  color:#E0E6F0 !important;
}
html.dark-theme .comment-text { color:#D7E0E9 !important; }
html.dark-theme .avg-bar-bg { background:rgba(255,255,255,.08) !important; }

/* Archived personnel */
html.dark-theme .person-row,
html.dark-theme .no-archived {
  background:#172A45 !important;
  border-color:rgba(255,255,255,.08) !important;
  color:#E0E6F0 !important;
}
html.dark-theme .person-name { color:#E0E6F0 !important; }
html.dark-theme .person-meta { color:#A0B3C6 !important; }
html.dark-theme .person-photo-ph {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.12) !important;
  color:#8EA5BB !important;
}
html.dark-theme .archived-badge {
  background:#0F1F3D !important;
  border-color:rgba(255,255,255,.10) !important;
  color:#A0B3C6 !important;
}
html.dark-theme .btn-restore {
  background:rgba(124,95,217,.16) !important;
  border:1px solid rgba(156,133,240,.35) !important;
  color:#C4B5FD !important;
}
html.dark-theme .btn-restore:hover { background:rgba(124,95,217,.24) !important; }

/* Keep dark-theme scrollbars readable */
html.dark-theme ::-webkit-scrollbar-track { background:#0F1F3D !important; }
html.dark-theme ::-webkit-scrollbar-thumb {
  background:#2A4468 !important;
  border:2px solid #0F1F3D !important;
}
</style>
<style id="dean-cumulative-dark-screen-final">
/* Final dark-mode correction for the generated/cumulative report sheet.
   The cumulative report intentionally keeps a white print layout, but on-screen
   it must inherit the active Dean dark theme instead of leaving white panels. */
@media screen {
  body.report-mode .cumulative-sheet,
  body.report-mode .cumulative-sheet * {
    color:#E0E6F0 !important;
  }

  body.report-mode .cumulative-sheet {
    background:#172A45 !important;
    border-color:rgba(255,255,255,.08) !important;
    box-shadow:0 8px 32px rgba(0,0,0,.45) !important;
  }

  body.report-mode .cumulative-sheet .cum-topbar,
  body.report-mode .cumulative-sheet .sheet-header,
  body.report-mode .cumulative-sheet .cum-total,
  body.report-mode .cumulative-sheet .comment-section {
    background:#172A45 !important;
    border-color:rgba(255,255,255,.08) !important;
    box-shadow:none !important;
  }

  body.report-mode .cumulative-sheet .cum-topbar {
    background:#0F1F3D !important;
  }

  body.report-mode .cumulative-sheet .cat-section {
    background:transparent !important;
    border:0 !important;
    box-shadow:none !important;
  }

  body.report-mode .cumulative-sheet .cat-title {
    background:transparent !important;
    color:#C4B5FD !important;
    border-bottom-color:rgba(255,255,255,.12) !important;
  }

  body.report-mode .cumulative-sheet .q-table {
    background:#172A45 !important;
    border-color:rgba(255,255,255,.08) !important;
  }

  body.report-mode .cumulative-sheet .q-table thead tr,
  body.report-mode .cumulative-sheet .q-table th {
    background:#0F1F3D !important;
    color:#A0B3C6 !important;
    border-color:rgba(255,255,255,.08) !important;
  }

  body.report-mode .cumulative-sheet .q-table td,
  body.report-mode .cumulative-sheet .q-table tbody tr {
    background:#172A45 !important;
    color:#E0E6F0 !important;
    border-color:rgba(255,255,255,.07) !important;
  }

  body.report-mode .cumulative-sheet .q-table tr:hover td {
    background:#1D3350 !important;
  }

  body.report-mode .cumulative-sheet .rating-badge {
    background:#0F1F3D !important;
    color:#E0E6F0 !important;
    border-color:rgba(255,255,255,.12) !important;
  }

  body.report-mode .cumulative-sheet .rating-num {
    color:#E0E6F0 !important;
  }

  body.report-mode .cumulative-sheet .rating-lbl {
    color:#A0B3C6 !important;
  }

  body.report-mode .cumulative-sheet .sheet-avatar-ph {
    background:#0F1F3D !important;
    border-color:rgba(255,255,255,.12) !important;
    color:#8EA5BB !important;
  }

  body.report-mode .cumulative-sheet .sheet-name {
    color:#E0E6F0 !important;
  }

  body.report-mode .cumulative-sheet .sheet-desig,
  body.report-mode .cumulative-sheet .cum-by,
  body.report-mode .cumulative-sheet .cum-date-label,
  body.report-mode .cumulative-sheet .cum-remark,
  body.report-mode .cumulative-sheet .no-comment,
  body.report-mode .cumulative-sheet .avg-out-of {
    color:#A0B3C6 !important;
  }

  body.report-mode .cumulative-sheet .cum-date-val {
    color:#E0E6F0 !important;
  }

  body.report-mode .cumulative-sheet .cum-total-label {
    color:#C4B5FD !important;
  }

  body.report-mode .cumulative-sheet .avg-score-big {
    color:#4ADE80 !important;
  }

  body.report-mode .cumulative-sheet .avg-score-label {
    color:#4ADE80 !important;
  }

  body.report-mode .cumulative-sheet .comment-title {
    background:transparent !important;
    color:#C4B5FD !important;
    border:0 !important;
  }
}
</style>

</body>
<link rel="stylesheet" href="includes/dean_light_theme.css?v=dashboard-ui-20261009" id="dean-light-theme-final"/>
<style id="dean-generated-sheet-dark-final-v4">
@media screen {
  html.dark-theme body.report-mode .cumulative-sheet,
  html.dark-theme body.report-mode .cumulative-sheet *,
  html.dark-theme body.report-mode .individual-sheet,
  html.dark-theme body.report-mode .individual-sheet * {
    box-sizing:border-box;
  }

  /* Generated cumulative report */
  html.dark-theme body.report-mode .cumulative-sheet {
    background:#172A45 !important;
    color:#E0E6F0 !important;
    border-color:rgba(255,255,255,.08) !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .cum-topbar,
  html.dark-theme body.report-mode .cumulative-sheet .sheet-header,
  html.dark-theme body.report-mode .cumulative-sheet .cum-total,
  html.dark-theme body.report-mode .cumulative-sheet .comment-section {
    background:#172A45 !important;
    color:#E0E6F0 !important;
    border-color:rgba(255,255,255,.08) !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .cum-topbar {
    background:#0F1F3D !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .cat-section {
    background:transparent !important;
    border:0 !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .cat-title {
    background:transparent !important;
    color:#C4B5FD !important;
    border-bottom-color:rgba(255,255,255,.12) !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .q-table,
  html.dark-theme body.report-mode .cumulative-sheet .q-table tbody,
  html.dark-theme body.report-mode .cumulative-sheet .q-table tr,
  html.dark-theme body.report-mode .cumulative-sheet .q-table td {
    background:#172A45 !important;
    color:#E0E6F0 !important;
    border-color:rgba(255,255,255,.08) !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .q-table thead tr,
  html.dark-theme body.report-mode .cumulative-sheet .q-table th {
    background:#0F1F3D !important;
    color:#A0B3C6 !important;
    border-color:rgba(255,255,255,.10) !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .q-table tr:hover td {
    background:#1D3350 !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .rating-badge {
    background:#0F1F3D !important;
    color:#E0E6F0 !important;
    border-color:rgba(255,255,255,.12) !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .sheet-name,
  html.dark-theme body.report-mode .cumulative-sheet .cum-date-val,
  html.dark-theme body.report-mode .cumulative-sheet .rating-num {
    color:#E0E6F0 !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .sheet-desig,
  html.dark-theme body.report-mode .cumulative-sheet .cum-by,
  html.dark-theme body.report-mode .cumulative-sheet .cum-date-label,
  html.dark-theme body.report-mode .cumulative-sheet .cum-remark,
  html.dark-theme body.report-mode .cumulative-sheet .no-comment,
  html.dark-theme body.report-mode .cumulative-sheet .avg-out-of,
  html.dark-theme body.report-mode .cumulative-sheet .rating-lbl {
    color:#A0B3C6 !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .cum-total-label,
  html.dark-theme body.report-mode .cumulative-sheet .comment-title {
    color:#C4B5FD !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .avg-score-big,
  html.dark-theme body.report-mode .cumulative-sheet .avg-score-label {
    color:#4ADE80 !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .sheet-avatar-ph {
    background:#0F1F3D !important;
    border-color:rgba(255,255,255,.12) !important;
    color:#8EA5BB !important;
  }

  /* Generated individual report/detail sheet, kept dark as a fallback if
     the page is opened directly without the cumulative switch. */
  html.dark-theme body.report-mode .individual-sheet,
  html.dark-theme body.report-mode .individual-sheet .sheet-header,
  html.dark-theme body.report-mode .individual-sheet .scale-bar,
  html.dark-theme body.report-mode .individual-sheet .comment-section,
  html.dark-theme body.report-mode .individual-sheet .avg-summary {
    background:#172A45 !important;
    color:#E0E6F0 !important;
    border-color:rgba(255,255,255,.08) !important;
  }
  html.dark-theme body.report-mode .individual-sheet .q-table,
  html.dark-theme body.report-mode .individual-sheet .q-table tbody,
  html.dark-theme body.report-mode .individual-sheet .q-table tr,
  html.dark-theme body.report-mode .individual-sheet .q-table td {
    background:#172A45 !important;
    color:#E0E6F0 !important;
    border-color:rgba(255,255,255,.08) !important;
  }
  html.dark-theme body.report-mode .individual-sheet .q-table thead tr,
  html.dark-theme body.report-mode .individual-sheet .q-table th {
    background:#0F1F3D !important;
    color:#A0B3C6 !important;
  }
}
</style>
<style id="dean-generated-sheet-dark-final-v3">
/* Final screen-only dark-mode override for generated evaluation sheets.
   Loaded after dean_light_theme.css so no legacy light/report rule can paint
   these report surfaces white. Printing intentionally remains unchanged. */
@media screen {
  html.dark-theme,
  html.dark-theme body { color-scheme:dark !important; }
  html.dark-theme body.report-mode .individual-sheet,
  html.dark-theme body.report-mode .individual-sheet .sheet-header,
  html.dark-theme body.report-mode .individual-sheet .scale-bar,
  html.dark-theme body.report-mode .individual-sheet .cat-section,
  html.dark-theme body.report-mode .individual-sheet .comment-section,
  html.dark-theme body.report-mode .individual-sheet .avg-summary {
    background:#172A45 !important;
    color:#E0E6F0 !important;
    border-color:rgba(255,255,255,.08) !important;
    box-shadow:none !important;
  }
  html.dark-theme body.report-mode .individual-sheet .cat-section {
    background:transparent !important;
    border-color:rgba(255,255,255,.08) !important;
  }
  html.dark-theme body.report-mode .individual-sheet .cat-title,
  html.dark-theme body.report-mode .individual-sheet .comment-title {
    background:#172A45 !important;
    color:#C4B5FD !important;
    border-color:rgba(255,255,255,.10) !important;
  }
  html.dark-theme body.report-mode .individual-sheet .q-table,
  html.dark-theme body.report-mode .individual-sheet .q-table tbody,
  html.dark-theme body.report-mode .individual-sheet .q-table tr,
  html.dark-theme body.report-mode .individual-sheet .q-table td {
    background:#172A45 !important;
    color:#E0E6F0 !important;
    border-color:rgba(255,255,255,.08) !important;
  }
  html.dark-theme body.report-mode .individual-sheet .q-table thead,
  html.dark-theme body.report-mode .individual-sheet .q-table thead tr,
  html.dark-theme body.report-mode .individual-sheet .q-table th {
    background:#0F1F3D !important;
    color:#A0B3C6 !important;
    border-color:rgba(255,255,255,.10) !important;
  }
  html.dark-theme body.report-mode .individual-sheet .q-table tr:hover td {
    background:#1D3350 !important;
  }
  html.dark-theme body.report-mode .individual-sheet .sheet-name,
  html.dark-theme body.report-mode .individual-sheet .sheet-desig,
  html.dark-theme body.report-mode .individual-sheet .eval-by-label,
  html.dark-theme body.report-mode .individual-sheet .eval-by-date,
  html.dark-theme body.report-mode .individual-sheet .avg-out-of,
  html.dark-theme body.report-mode .individual-sheet .scale-item,
  html.dark-theme body.report-mode .individual-sheet .avg-bar-label,
  html.dark-theme body.report-mode .individual-sheet .avg-bar-val,
  html.dark-theme body.report-mode .individual-sheet .q-num,
  html.dark-theme body.report-mode .individual-sheet .rating-cell {
    color:#E0E6F0 !important;
  }
  html.dark-theme body.report-mode .individual-sheet .sheet-desig,
  html.dark-theme body.report-mode .individual-sheet .eval-by-label,
  html.dark-theme body.report-mode .individual-sheet .eval-by-date,
  html.dark-theme body.report-mode .individual-sheet .avg-out-of,
  html.dark-theme body.report-mode .individual-sheet .scale-item,
  html.dark-theme body.report-mode .individual-sheet .avg-bar-label {
    color:#A0B3C6 !important;
  }
  html.dark-theme body.report-mode .individual-sheet .sheet-avatar-ph {
    background:#0F1F3D !important;
    color:#8EA5BB !important;
    border-color:rgba(255,255,255,.12) !important;
  }
  html.dark-theme body.report-mode .individual-sheet .rating-badge {
    background:#0F1F3D !important;
    color:#E0E6F0 !important;
    border-color:rgba(255,255,255,.12) !important;
  }
  html.dark-theme body.report-mode .individual-sheet .comment-text,
  html.dark-theme body.report-mode .individual-sheet .avg-bar-bg {
    background:#0F1F3D !important;
    color:#E0E6F0 !important;
  }
  /* Cumulative generated report */
  html.dark-theme body.report-mode .cumulative-sheet,
  html.dark-theme body.report-mode .cumulative-sheet .cum-topbar,
  html.dark-theme body.report-mode .cumulative-sheet .sheet-header,
  html.dark-theme body.report-mode .cumulative-sheet .cat-section,
  html.dark-theme body.report-mode .cumulative-sheet .cum-total,
  html.dark-theme body.report-mode .cumulative-sheet .comment-section {
    background:#172A45 !important;
    color:#E0E6F0 !important;
    border-color:rgba(255,255,255,.08) !important;
    box-shadow:none !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .cum-topbar,
  html.dark-theme body.report-mode .cumulative-sheet .q-table thead tr,
  html.dark-theme body.report-mode .cumulative-sheet .q-table th,
  html.dark-theme body.report-mode .cumulative-sheet .rating-badge {
    background:#0F1F3D !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .cat-title,
  html.dark-theme body.report-mode .cumulative-sheet .comment-title {
    background:transparent !important;
    color:#C4B5FD !important;
    border-color:rgba(255,255,255,.10) !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .q-table,
  html.dark-theme body.report-mode .cumulative-sheet .q-table tbody,
  html.dark-theme body.report-mode .cumulative-sheet .q-table tr,
  html.dark-theme body.report-mode .cumulative-sheet .q-table td {
    background:#172A45 !important;
    color:#E0E6F0 !important;
    border-color:rgba(255,255,255,.08) !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .q-table th {
    color:#A0B3C6 !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .sheet-name,
  html.dark-theme body.report-mode .cumulative-sheet .rating-num,
  html.dark-theme body.report-mode .cumulative-sheet .rating-lbl,
  html.dark-theme body.report-mode .cumulative-sheet .cum-date-val {
    color:#E0E6F0 !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .sheet-desig,
  html.dark-theme body.report-mode .cumulative-sheet .cum-by,
  html.dark-theme body.report-mode .cumulative-sheet .cum-date-label,
  html.dark-theme body.report-mode .cumulative-sheet .cum-remark,
  html.dark-theme body.report-mode .cumulative-sheet .no-comment,
  html.dark-theme body.report-mode .cumulative-sheet .avg-out-of,
  html.dark-theme body.report-mode .cumulative-sheet .rating-lbl {
    color:#A0B3C6 !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .cum-total-label {
    color:#C4B5FD !important;
  }
  html.dark-theme body.report-mode .cumulative-sheet .sheet-avatar-ph {
    background:#0F1F3D !important;
    border-color:rgba(255,255,255,.12) !important;
    color:#8EA5BB !important;
  }
}
</style>

<style id="reports-top-actions-layout">
/* Filters + search sit in the top row, opposite the report-type tabs (Filters first). */
.reports-redesign .reports-top-row{display:flex;align-items:center;justify-content:space-between;gap:12px 16px;flex-wrap:wrap;margin:0 0 18px;}
.reports-redesign .reports-top-row .reports-top-switcher{margin:0;}
.reports-redesign .reports-top-row .reports-data-actions{margin-left:auto;display:flex;align-items:center;gap:10px;}
.reports-redesign .reports-action-btn.reports-filters-btn{height:36px;padding:0 15px;border:1px solid #C9D7E2;border-radius:8px;background:#fff;color:#12263A;font:500 13px 'DM Sans','Inter',sans-serif;gap:7px;}
.reports-redesign .reports-action-btn.reports-filters-btn i{color:#2563EB;font-size:12px;}
/* Search: same design as the Evaluation Tracker search */
.reports-redesign .reports-search-box{position:relative;display:block;width:310px;max-width:100%;height:36px;padding:0;border:0!important;border-radius:0;background:transparent!important;}
.reports-redesign .reports-search-box i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#2563EB;font-size:13px;pointer-events:none;}
.reports-redesign .reports-search-box input[type="search"]{display:block;width:100%;height:36px;padding:0 12px 0 34px!important;border:1px solid #C9D7E2!important;border-radius:8px!important;background:#fff!important;color:#12263A!important;font:500 13px 'DM Sans','Inter',sans-serif!important;text-overflow:ellipsis;outline:0!important;box-shadow:none!important;-webkit-appearance:none;appearance:none;}
.reports-redesign .reports-search-box input[type="search"]::placeholder{color:#8A9BAD!important;opacity:1;}
.reports-redesign .reports-search-box input[type="search"]:focus{border-color:#7C5FD9!important;box-shadow:0 0 0 3px rgba(124,95,217,.15)!important;}
.reports-redesign .reports-search-box input[type="search"]::-webkit-search-cancel-button{-webkit-appearance:none;}
html.dark-theme .reports-redesign .reports-action-btn.reports-filters-btn,
html.dark-theme .reports-redesign .reports-search-box input[type="search"]{background:#0F1F3D!important;border-color:rgba(255,255,255,.14)!important;color:#E0E6F0!important;}
html.dark-theme .reports-redesign .reports-search-box input[type="search"]::placeholder{color:#6B84A0!important;}
@media(max-width:900px){
  .reports-redesign .reports-top-row .reports-data-actions{width:100%;margin-left:0;}
  .reports-redesign .reports-search-box{flex:1;width:auto;min-width:0;order:0;flex-basis:auto;}
}
</style>


</html>