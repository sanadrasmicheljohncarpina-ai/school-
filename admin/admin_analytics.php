<?php
// admin/admin_analytics.php
session_start();
require_once 'db.php';
require_once '../shared/EvaluationContextService.php';
require_once '../shared/QuestionnaireService.php';

// ── AUTH GUARD ───────────────────────────────────────────────
// Evaluation scores and archive/restore actions are sensitive —
// require an authenticated admin-level session before anything else runs.
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['superadmin','admin'])) {
    header("Location: admin_login.php");
    exit;
}

// ── CURRENT-QUESTIONNAIRE FILTER ─────────────────────────────
// Reports must only score the questions that are in the person's CURRENT
// questionnaire (admin/questionnaire.php). Older submissions can reference
// question IDs from previous/edited/deleted banks or test questions (e.g.
// "testing"); pooling those made a 17-question Faculty questionnaire show as
// 29 rows and skewed the averages. Matching is done on question TEXT so a
// legacy answer to a question that still exists (same wording, older ID) is
// kept, while answers to questions no longer in the questionnaire are left
// out of the aggregates. Nothing is deleted or modified in the database.
//   $qa  = alias of questionnaire_answers in the calling query
//   $uid = SQL expression for the evaluated person's users.id (e.g. 'u.id' or an int)
// If the person has no current questions at all, no filtering is applied so a
// report never goes blank.
if (!function_exists('aa_current_q_sql')) {
    function aa_current_q_sql(string $qa, $uid): string {
        $uid = is_int($uid) ? (string)$uid : (string)$uid;
        $qtext = "COALESCE(
            (SELECT eqx.question_text FROM evaluation_questions eqx
              WHERE $qa.question_source='evaluation' AND eqx.id=$qa.question_id LIMIT 1),
            (SELECT uqx.question_text FROM user_questions uqx
              WHERE $qa.question_source='user' AND uqx.id=COALESCE($qa.user_question_id, $qa.question_id) LIMIT 1))";
        $qtext = "CONVERT($qtext USING utf8mb4) COLLATE utf8mb4_unicode_ci";
        $isFaculty = "EXISTS (SELECT 1 FROM users tux WHERE tux.id=$uid
                       AND (tux.role IN ('teacher','faculty') OR tux.secondary_role='teacher'))";
        $facBank = "SELECT CONVERT(fqx.question_text USING utf8mb4) COLLATE utf8mb4_unicode_ci FROM evaluation_questions fqx
                     WHERE fqx.target_type='Faculty' AND fqx.eval_type='general'
                       AND fqx.evaluator_role='shared' AND fqx.is_active=1";
        $personBank = "SELECT CONVERT(pqx.question_text USING utf8mb4) COLLATE utf8mb4_unicode_ci FROM user_questions pqx
                        WHERE pqx.user_id=$uid AND pqx.eval_type='general'";
        return "(
            ($isFaculty AND $qtext IN ($facBank))
            OR ($qa.question_source='evaluation' AND $qtext IN ($facBank))
            OR $qtext IN ($personBank)
            OR (NOT ($isFaculty AND EXISTS ($facBank)) AND NOT EXISTS ($personBank))
        )";
    }
}

// A submission only counts as an evaluation if it has at least one scored answer
// to a question in the person's CURRENT questionnaire. Empty/abandoned submissions
// (e.g. a tracker row saved with zero answers) used to inflate "Total Evaluations"
// and the "Evaluated by: N" figure while contributing nothing to any score.
//   $et  = alias of evaluation_tracker in the calling query
//   $uid = SQL expression / int for the evaluated person's users.id
if (!function_exists('aa_scored_tracker_sql')) {
    function aa_scored_tracker_sql(string $et, $uid): string {
        return "EXISTS (SELECT 1 FROM questionnaire_answers qsc WHERE qsc.tracker_id=$et.id AND qsc.answer_score IS NOT NULL AND " . aa_current_q_sql('qsc', $uid) . ")";
    }
}

// Merge per-question rows that share the same wording (old + new question IDs
// for the same question) into one row, using a response-weighted average.
if (!function_exists('aa_merge_question_rows')) {
    function aa_merge_question_rows(array $rows): array {
        usort($rows, fn($a, $b) => (int)$b['q_id'] <=> (int)$a['q_id']); // newest ID first
        $merged = [];
        foreach ($rows as $r) {
            $key = mb_strtolower(trim((string)$r['question_text']));
            $n   = (int)$r['total_responses'];
            $sum = (float)$r['avg_score'] * $n;
            if (!isset($merged[$key])) {
                $merged[$key] = $r; // category/q_id come from the newest (current) row
                $merged[$key]['_n'] = $n;
                $merged[$key]['_sum'] = $sum;
            } else {
                $merged[$key]['_n']   += $n;
                $merged[$key]['_sum'] += $sum;
            }
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

// ── ENSURE TABLES EXIST ───────────────────────────────────────
$mysqli->query("CREATE TABLE IF NOT EXISTS analytics_archive (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    target_user_id INT UNSIGNED NOT NULL,
    archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_target (target_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// evaluation_tracker must accept the same evaluation directions exposed by
// admin/questionnaire.php. Older installations used a small ENUM here, while
// the current questionnaire uses student / peer / school_head / ea / staff.
$col = $mysqli->query("SHOW COLUMNS FROM evaluation_tracker LIKE 'eval_type'");
if ($col && $col->num_rows === 0) {
    $mysqli->query("ALTER TABLE evaluation_tracker ADD COLUMN eval_type VARCHAR(30) NOT NULL DEFAULT 'student' AFTER remarks");
    $mysqli->query("ALTER TABLE evaluation_tracker ADD INDEX idx_eval_type (eval_type)");
} else if ($col) {
    $colRow = $col->fetch_assoc();
    if ($colRow && stripos($colRow['Type'] ?? '', 'enum') === 0) {
        $mysqli->query("ALTER TABLE evaluation_tracker MODIFY eval_type VARCHAR(30) NOT NULL DEFAULT 'student'");
    }
}

// evaluator_id column — for peer evals this is the teacher/staff doing the rating
// (for student evals this mirrors student_id; we add it only if missing)
$col2 = $mysqli->query("SHOW COLUMNS FROM evaluation_tracker LIKE 'evaluator_id'");
if ($col2 && $col2->num_rows === 0) {
    $mysqli->query("ALTER TABLE evaluation_tracker ADD COLUMN evaluator_id INT UNSIGNED NULL DEFAULT NULL AFTER eval_type");
    $mysqli->query("ALTER TABLE evaluation_tracker ADD INDEX id_evaluator (evaluator_id)");
}

// education_level / year_level — lets the Evaluators List be filtered to a
// specific grade (Basic Ed) or year (Higher Ed) of student evaluators.
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
// person using the current primary evaluation role.
$ctxCol = $mysqli->query("SHOW COLUMNS FROM evaluation_tracker LIKE 'evaluation_context'");
if ($ctxCol && $ctxCol->num_rows === 0) {
    $mysqli->query("ALTER TABLE evaluation_tracker ADD COLUMN evaluation_context VARCHAR(30) NOT NULL DEFAULT 'teacher' AFTER period_id");
    $mysqli->query("ALTER TABLE evaluation_tracker ADD INDEX idx_eval_context (evaluation_context)");
    $mysqli->query("UPDATE evaluation_tracker et JOIN users u ON u.id=et.target_user_id SET et.evaluation_context=CASE WHEN u.role IN ('principal','dean') THEN 'school_head' WHEN u.role='staff' THEN 'staff' WHEN u.role='teacher' THEN 'teacher' ELSE et.evaluation_context END WHERE et.evaluation_context='teacher'");
}

// ── ARCHIVE / RESTORE ─────────────────────────────────────────
if (isset($_GET['archive_id'])) {
    $aid  = intval($_GET['archive_id']);
    $stmt = $mysqli->prepare("INSERT IGNORE INTO analytics_archive (target_user_id) VALUES (?)");
    $stmt->bind_param("i", $aid); $stmt->execute(); $stmt->close();
    $_SESSION['toast'] = "Personnel archived. Their data is kept and can be restored anytime.";
    header("Location: admin_analytics.php?group=".urlencode($_GET['group']??'All')."&eval_type=".urlencode($_GET['eval_type']??'student')."&evaluator=".urlencode($_GET['evaluator']??'All')); exit;
}
if (isset($_GET['restore_id'])) {
    $rid  = intval($_GET['restore_id']);
    $stmt = $mysqli->prepare("DELETE FROM analytics_archive WHERE target_user_id=?");
    $stmt->bind_param("i", $rid); $stmt->execute(); $stmt->close();
    $_SESSION['toast'] = "Personnel restored to the main list.";
    header("Location: admin_analytics.php?group=".urlencode($_GET['group']??'All')."&eval_type=".urlencode($_GET['eval_type']??'student')."&evaluator=".urlencode($_GET['evaluator']??'All')."&view=archived"); exit;
}

$toast = $_SESSION['toast'] ?? ''; unset($_SESSION['toast']);

// ── ACTIVE EVAL TYPE ──────────────────────────────────────────
// Legacy multi-role links are redirected to the Student Evaluation view.
$requestedEvalType = $_GET['eval_type'] ?? 'student';
$legacyMultiRoleLink = false;
$activeEval = $requestedEvalType === 'multi_role' ? 'student' : $requestedEvalType;
// IMPORTANT: these values intentionally match Questionnaire's eval_type values.
// Analytics is a results view of the same evaluation directions, not a second
// independent classification system.
if (!in_array($activeEval, ['student','peer','schoolhead','ea','staff'], true)) $activeEval = 'student';

// ── GROUP FILTER (evaluation-specific target groups) ──────────
$groupFilter = $_GET['group'] ?? 'All';
if (($groupFilter ?? '') === 'MultiRole') $groupFilter = 'All';
// School Head Evaluation's targets are Faculty and EA (Principal/Dean are
// the evaluators, filtered separately below via $evaluatorFilter) —
// Dean / Principal Evaluation uses its own target groups: Faculty, Staff, EA.
// Faculty includes both teacher-role accounts and teaching Staff; Staff is
// reserved for non-teaching Staff.
$allowedGroups = match ($activeEval) {
    'student'    => ['All','Teacher','Staff','Dean','Principal'],
    'peer'       => ['All','Teacher','Staff','Dean','Principal'],
    // EA is intentionally NOT a target group here: the EA must never see
    // who (Dean/Principal) evaluated them, so EA results are not reported.
    'schoolhead' => ['All','Faculty','Staff'],
    // EA submissions are kept under their own eval_type. The current project
    // contains both the Questionnaire definition (EA as a target) and the
    // existing EA-evaluation submission flow (EA as evaluator), so Analytics
    // keeps every real `ea` submission visible instead of discarding it.
    'ea'         => ['All','Staff','Dean','Principal'],
    // Staff Evaluation in Questionnaire targets Dean, Principal, and EA.
    'staff'      => ['All','Dean','Principal','EA'],
    default      => ['All']
};
if (!in_array($groupFilter, $allowedGroups, true)) $groupFilter = 'All';
// Dean / Principal Evaluation now includes an All target tab alongside
// Faculty, Staff, and EA, so no forced redirect off of 'All' is needed here.

// School Head Evaluation's evaluator filter (Principal / Dean / All) — a
// second, independent axis from the target group filter above. Only
// meaningful for the schoolhead tab; forced to 'All' everywhere else so a
// stray query param can't silently filter other tabs' SQL paths.
$evaluatorFilter = $_GET['evaluator'] ?? 'All';
if (!in_array($evaluatorFilter, ['All','Principal','Dean'], true)) $evaluatorFilter = 'All';
if ($activeEval !== 'schoolhead') $evaluatorFilter = 'All';

// Faculty and Staff are independent top-level group tabs (no more merged
// "Faculty" tab with a nested Teacher/Staff sub-toggle). The underlying
// group values remain Teacher/Staff so all existing filtering, scoring,
// archive, and report logic continues to work unchanged.
$isMultiRole = false;
$multiRoleFilter = 'all';
$mrFilterRoleSql = '1=0';

// "School Head Evaluation" reports evaluations SUBMITTED BY the active
// Principal/Dean — they are the EVALUATORS, not the evaluated personnel.
// The people they evaluate (the targets) are Faculty (Faculty Members +
// Teaching Staff, same has_teacher rule questionnaire.php/Peer above use)
// and the current EA. This mirrors admin/questionnaire.php's School Head
// Evaluation tab, which now assigns questions to target_type IN
// ('Faculty','EA') under eval_type='school_head' rather than to Principal/
// Dean directly.
//
// Two evaluator portals write genuine School Head Evaluation rows, each
// under its own eval_type: dean_evaluate.php uses a single 'school_head'
// for every target, while principal_evaluate.php splits into three —
// 'supervisor_to_teacher', 'supervisor_to_staff', 'upward_to_ea' — because
// those exact strings are also the lookup key into questionnaire_forms for
// which form to render, so they can't be collapsed to 'school_head'
// without breaking Principal's own evaluate flow. All four are recognized
// here as the same report. Older installations may have recorded the
// previous (reversed) EA/student-evaluates-Principal/Dean relationship
// under eval_type IN ('ea','student','principal') with target_user_id =
// the Principal/Dean — those rows are NOT deleted (see analytics_archive/
// history preservation), but they no longer fit this corrected report and
// are intentionally excluded from it: showing them here would silently
// reintroduce the exact "Dean/Principal treated as targets" bug this fixes.
$schoolheadTypes = ['school_head', 'supervisor_to_teacher', 'supervisor_to_staff', 'upward_to_ea'];

// Build SQL literals only from a closed set of server-defined values.
// This avoids unquoted identifiers such as `student` ever reaching MySQL
// when the active analytics tab is changed.
$sqlQuote = static function (string $value) use ($mysqli): string {
    return "'" . $mysqli->real_escape_string($value) . "'";
};
$schoolheadTypeSql = '(' . implode(',', array_map($sqlQuote, $schoolheadTypes)) . ')';
$studentTypeSql = $sqlQuote('student');
$peerTypesSql = implode(',', array_map($sqlQuote, ['peer','faculty_peer','staff_peer']));
$schoolheadContextSql = $sqlQuote('school_head');
$multiRoleContextSql = $sqlQuote('__removed__');
$teacherContextSql = $sqlQuote('teacher');
$staffContextSql = $sqlQuote('staff');
$multiRoleQuestionTypeSql = $sqlQuote('__removed__');

// Evaluation targets under School Head Evaluation: every Teacher and Staff
// account (teaching or non-teaching — dean_evaluate.php rates both under
// its single merged "Faculty" tab, it doesn't distinguish) plus the current
// EA (role='superadmin'). Principal/Dean are never targets here. Matches
// dean_evaluate.php's own target-roster query exactly (role IN
// ('teacher','staff') OR role='superadmin').
// The EA (role='superadmin') is deliberately excluded: evaluations of the EA
// are confidential and the EA must not see who evaluated them.
$schoolheadTargetPoolSql = "role IN ('teacher','staff') AND is_active=1";
$schoolheadTargetSql = "et.target_user_id IN (SELECT id FROM users WHERE $schoolheadTargetPoolSql)";
$schoolheadTargetPlainSql = "target_user_id IN (SELECT id FROM users WHERE $schoolheadTargetPoolSql)";

// Evaluators under School Head Evaluation: the active Principal and/or
// Dean, per $evaluatorFilter. This is the reverse direction from the old
// model and is what actually distinguishes a real School Head Evaluation
// submission from a stale/legacy row.
$schoolheadEvaluatorRoles = $evaluatorFilter === 'Principal' ? "'principal'" : ($evaluatorFilter === 'Dean' ? "'dean'" : "'principal','dean'");
$schoolheadEvaluatorSql = "et.evaluator_id IN (SELECT id FROM users WHERE role IN ($schoolheadEvaluatorRoles) AND is_active=1)";
$schoolheadEvaluatorPlainSql = "evaluator_id IN (SELECT id FROM users WHERE role IN ($schoolheadEvaluatorRoles) AND is_active=1)";

$studentSchoolHeadTargetSql = "et.target_user_id IN (SELECT id FROM users WHERE role IN ('principal','dean') AND is_active=1 AND account_status='approved')";
$studentSchoolHeadTargetPlainSql = "target_user_id IN (SELECT id FROM users WHERE role IN ('principal','dean') AND is_active=1 AND account_status='approved')";

// Some Student -> Dean/Principal submissions from the older student
// questionnaire writer were saved with eval_type='school_head' instead of
// eval_type='student'. They are still genuine Student Evaluation records when
// evaluator_id belongs to a student and the target is a Principal/Dean. Keep
// those records visible here without pulling Principal/Dean's own
// School-Head-Evaluation records into the Student Evaluation report.
$studentSchoolHeadLegacySql = "et.eval_type='school_head'
    AND et.evaluator_id IN (SELECT id FROM users WHERE role='student')
    AND $studentSchoolHeadTargetSql";
$studentSchoolHeadLegacyPlainSql = "eval_type='school_head'
    AND evaluator_id IN (SELECT id FROM users WHERE role='student')
    AND $studentSchoolHeadTargetPlainSql";

// EA Evaluation / Staff Evaluation use the same evaluation_tracker table as
// every other direction. These are deliberately keyed by eval_type so a
// submission appears in Analytics under the same direction that Questionnaire
// assigned. The target-role filters only prevent unrelated personnel from
// leaking into the wrong evaluation tab.
$eaTargetSql = "et.target_user_id IN (SELECT id FROM users WHERE role IN ('staff','principal','dean') AND is_active=1)";
$eaTargetPlainSql = "target_user_id IN (SELECT id FROM users WHERE role IN ('teacher','staff','principal','dean','superadmin') AND is_active=1)";
$staffEvalTargetSql = "et.target_user_id IN (SELECT id FROM users WHERE role IN ('principal','dean','superadmin') AND is_active=1 AND account_status='approved')";
$staffEvalTargetPlainSql = "target_user_id IN (SELECT id FROM users WHERE role IN ('principal','dean','superadmin') AND is_active=1 AND account_status='approved')";
// Student Evaluation has three target groups: Faculty, non-teaching Staff,
// and the active Dean/Principal (School Head). For School Head targets, the
// target's actual role is authoritative. Older/newer student-evaluation
// writers can leave evaluation_context at the default 'teacher' (or another
// value), so the report must NOT require evaluation_context='school_head' to
// recognize a genuine Student -> Dean/Principal submission. This is what
// caused a newly submitted Dean evaluation to disappear from the report.
$studentTargetSql = "(
    et.eval_type=$studentTypeSql AND (
        (COALESCE(et.evaluation_context,'teacher') IN ($teacherContextSql,$staffContextSql)
         AND et.target_user_id IN (SELECT id FROM users WHERE role IN ('teacher','staff') AND is_active=1 AND account_status='approved'))
        OR
        ($studentSchoolHeadTargetSql)
    )
    OR
    $studentSchoolHeadLegacySql
)";
$studentTargetPlainSql = "(
    eval_type=$studentTypeSql AND (
        (COALESCE(evaluation_context,'teacher') IN ($teacherContextSql,$staffContextSql)
         AND target_user_id IN (SELECT id FROM users WHERE role IN ('teacher','staff') AND is_active=1 AND account_status='approved'))
        OR
        ($studentSchoolHeadTargetPlainSql)
    )
    OR
    $studentSchoolHeadLegacyPlainSql
)";


// Multi-Role is primarily identified by the explicit evaluation_context. The
// EXISTS fallback also recognizes older submissions saved before the context
// column existed, provided their answers point to a Multi-Role question
// assigned to that personnel. This clause is unchanged from before — only
// how it's triggered (via $isMultiRole) has moved.
$multiRoleClauseSql      = "et.eval_type=$studentTypeSql AND (
        et.evaluation_context=$multiRoleContextSql
        OR EXISTS (
            SELECT 1
            FROM questionnaire_answers qam
            JOIN user_questions uqm ON uqm.id = qam.user_question_id
            WHERE qam.tracker_id = et.id
              AND uqm.target_type = $multiRoleQuestionTypeSql
              AND uqm.eval_type = $studentTypeSql
        )
    )";
$multiRoleClausePlainSql = "eval_type=$studentTypeSql AND (
        evaluation_context=$multiRoleContextSql
        OR EXISTS (
            SELECT 1
            FROM questionnaire_answers qam
            JOIN user_questions uqm ON uqm.id = qam.user_question_id
            WHERE qam.tracker_id = evaluation_tracker.id
              AND uqm.target_type = $multiRoleQuestionTypeSql
              AND uqm.eval_type = $studentTypeSql
        )
    )";

if ($isMultiRole) {
    $evalTypeSql      = $multiRoleClauseSql;
    $evalTypePlainSql = $multiRoleClausePlainSql;
} else {
    $evalTypeSql = match ($activeEval) {
        'schoolhead' => "et.eval_type IN $schoolheadTypeSql AND $schoolheadEvaluatorSql AND $schoolheadTargetSql",
        'peer'       => "et.eval_type IN ($peerTypesSql)",
        'ea'         => "et.eval_type='ea' AND $eaTargetSql",
        'staff'      => "et.eval_type='staff' AND $staffEvalTargetSql",
        // Plain Student Evaluation includes the normal Faculty/Staff contexts
        // plus the dedicated Student Evaluation -> School Head context.
        // Multi-Role remains isolated in its own group filter.
        default      => $studentTargetSql
    };
    $evalTypePlainSql = match ($activeEval) {
        'schoolhead' => "eval_type IN $schoolheadTypeSql AND $schoolheadEvaluatorPlainSql AND $schoolheadTargetPlainSql",
        'peer'       => "eval_type IN ($peerTypesSql)",
        'ea'         => "eval_type='ea' AND $eaTargetPlainSql",
        'staff'      => "eval_type='staff' AND $staffEvalTargetPlainSql",
        default      => $studentTargetPlainSql
    };
}

// ── YEAR LEVEL FILTER (Evaluators List, Student Evaluation only) ──────
// One dropdown, backed by two columns: education_level ('basic_ed'/'higher_ed')
// is derived from whichever specific year_level is picked, so the UI only
// needs to carry $yearLevel around. Meaningless outside eval_type=student
// (Peer/School Head evaluators aren't students), so it's forced to 'All' there
// to keep a stray query param from silently filtering those SQL paths.
$basicEdYears  = ['Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12'];
$higherEdYears = ['1st Year College','2nd Year College','3rd Year College','4th Year College'];
$allYearLevels = array_merge($basicEdYears, $higherEdYears);
$yearLevel = $_GET['year_level'] ?? 'All';
if ($yearLevel !== 'All' && !in_array($yearLevel, $allYearLevels, true)) $yearLevel = 'All';
if ($activeEval !== 'student') $yearLevel = 'All';
$educationLevel = $yearLevel === 'All' ? 'All' : (in_array($yearLevel, $basicEdYears, true) ? 'basic_ed' : 'higher_ed');
$yearLevelSql = ($activeEval === 'student' && $yearLevel !== 'All') ? " AND u.year_level=" . $sqlQuote($yearLevel) : "";

// ── VIEWS ─────────────────────────────────────────────────────
$view       = $_GET['view']       ?? 'list';
$target_id  = intval($_GET['target_id']  ?? 0);
$student_id = intval($_GET['student_id'] ?? 0);  // evaluator for peer
$tracker_id = intval($_GET['tracker_id'] ?? 0);

// ── HELPERS ───────────────────────────────────────────────────
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
    if ($s >= 4.5)  return '#32B98A';
    if ($s >= 3.5)  return '#6BCFA9';
    if ($s >= 2.5)  return '#facc15';
    if ($s >= 1.5)  return '#fb923c';
    return '#E6788A';
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
// Display label for a School Head Evaluation TARGET (never Principal/Dean —
// those are evaluators). role='superadmin' is the current EA; everything
// else reaching this report is already known to be a Faculty/Teaching
// Staff account via $schoolheadTargetSql / ec_resolve_schoolhead_target_group().
function schoolheadTargetLabel($role) {
    return $role === 'superadmin' ? 'EA' : 'Faculty';
}

// Peer-to-Peer canonical grouping, mirrored directly from admin/questionnaire.php's
// own Faculty/Staff/School Head bucketing so the two features can never disagree:
//   - School Head: role is Principal or Dean, peer-reviewed by colleagues
//     (target_type='School', eval_type='peer' in the question bank) --
//     entirely separate from the top-level School Head Evaluation tab, where
//     the EA/students evaluate the Dean/Principal instead.
//   - Faculty: exactly questionnaire.php's own has_teacher rule, plus the same
//     year-level rule the Faculty/Staff dashboards use (a Staff account with >= 1
//     user_year_levels row is Teaching Staff -> Faculty) --
//     is_teaching_staff (Teacher role, an explicit secondary Teacher role, a
//     Teacher sector, or an actual teaching_assignments row) OR
//     ec_has_teacher_function($u). That second half matters: it's an
//     independent OR in questionnaire.php, so some teaching Staff are only
//     caught by ec_has_teacher_function() and not by the SQL predicate alone
//     -- skipping it is what left Faculty undercounted here before. It's a
//     pure array function (no $mysqli), so $u below carries the same fields
//     questionnaire.php's own user query builds, for identical results.
//   - Staff (Non-Teaching Staff): a Staff account with no teaching
//     assignment AND no year-level scope at all -- questionnaire.php's
//     isNonTeachingStaff() predicate.
//   - Anything else (e.g. a Staff account scoped to a year level but with no
//     actual teaching assignment) has no Peer-to-Peer context and is
//     dropped, same as before.
function ec_resolve_peer_group_full($p, $mysqli) {
    $role = strtolower(trim((string)($p['role'] ?? '')));
    if ($role === 'principal' || $role === 'dean') return 'school_head';

    // Faculty accounts always belong to the Faculty reporting category.
    // Staff accounts are promoted to Faculty only when the existing
    // teaching/year-level assignment architecture identifies them as teaching.
    if ($role === 'faculty') return 'teacher';

    $uid = (int)($p['id'] ?? 0);
    if ($uid <= 0) return null;

    $stmt = $mysqli->prepare(
        "SELECT id, full_name, designation, photo, source, role, sector,
                EXISTS(SELECT 1 FROM teaching_assignments ta WHERE ta.user_id=u.id) AS has_teaching_assignment,
                (u.role='teacher' OR u.sector='Teacher'
                 OR EXISTS(SELECT 1 FROM teaching_assignments ta2 WHERE ta2.user_id=u.id)
                 OR EXISTS(SELECT 1 FROM user_year_levels yl2 WHERE yl2.user_id=u.id)) AS is_teaching_staff,
                EXISTS(SELECT 1 FROM user_year_levels yl WHERE yl.user_id=u.id) AS has_year_level
         FROM users u WHERE u.id=? LIMIT 1"
    );
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$u) return null;

    // Exactly questionnaire.php's own has_teacher rule: the SQL predicate
    // OR'd with the same external ec_has_teacher_function() call, given the
    // same field set that function expects.
    $has_teacher = ((int)($u['is_teaching_staff'] ?? 0) === 1) || ec_has_teacher_function($u);
    if ($has_teacher) return 'teacher';

    $secondary = strtolower(trim((string)($p['secondary_role'] ?? '')));
    if ($role === 'staff' || $secondary === 'staff') {
        return (
            (int)($u['has_teaching_assignment'] ?? 0) === 0
            && (int)($u['has_year_level'] ?? 0) === 0
        ) ? 'staff' : null;
    }
    return null;
}

// Student Evaluation reporting grouping:
//   - School Head: active Principal/Dean targets
//   - Faculty: Faculty/Teacher accounts + Staff accounts with the same active
//     teaching/year-level logic used by Account Management/questionnaire.php
//   - Staff: only Non-Teaching Staff (no teaching_assignments and no user_year_levels)
//   - null: not a Student Evaluation target
function ec_resolve_student_group_full($p, $mysqli) {
    $role = strtolower(trim((string)($p['role'] ?? '')));
    if ($role === 'principal' || $role === 'dean') return 'school_head';

    $uid = (int)($p['id'] ?? 0);
    if ($uid <= 0) return null;

    $stmt = $mysqli->prepare(
        "SELECT id, full_name, designation, photo, source, role, sector,
                EXISTS(SELECT 1 FROM teaching_assignments ta WHERE ta.user_id=u.id) AS has_teaching_assignment,
                (u.role='teacher' OR u.sector='Teacher'
                 OR EXISTS(SELECT 1 FROM teaching_assignments ta2 WHERE ta2.user_id=u.id)
                 OR EXISTS(SELECT 1 FROM user_year_levels yl2 WHERE yl2.user_id=u.id)) AS is_teaching_staff,
                EXISTS(SELECT 1 FROM user_year_levels yl WHERE yl.user_id=u.id) AS has_year_level
         FROM users u WHERE u.id=? LIMIT 1"
    );
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$u) return null;

    // Exactly mirror the existing questionnaire.php teaching rule.
    $has_teacher = ((int)($u['is_teaching_staff'] ?? 0) === 1) || ec_has_teacher_function($u);
    if ($has_teacher) return 'teacher';

    $has_staff = ec_has_staff_function($u);
    if ($role === 'staff' || $has_staff) {
        return (
            (int)($u['has_teaching_assignment'] ?? 0) === 0
            && (int)($u['has_year_level'] ?? 0) === 0
        ) ? 'staff' : null;
    }
    return null;
}

// Dean / Principal Evaluation target grouping mirrors Questionnaire exactly:
// Faculty = teachers and Staff with a teaching/year-level assignment;
// Staff = Staff without a teaching assignment; EA is separate.
function ec_resolve_schoolhead_target_group($p, $mysqli) {
    $role = strtolower(trim((string)($p['role'] ?? '')));
    if ($role === 'superadmin') return 'ea';
    $g = ec_resolve_student_group_full($p, $mysqli);
    if ($g === 'teacher') return 'faculty';
    if ($g === 'staff') return 'staff';
    return null;
}

// EA Evaluation can contain legacy/current submissions where the EA is the
// evaluator and the target is Principal, Dean, Staff, or another personnel
// context. Keep all such rows visible while giving the Analytics UI a stable
// target grouping.
function ec_resolve_ea_group($p, $mysqli) {
    $role = strtolower(trim((string)($p['role'] ?? '')));
    if ($role === 'principal') return 'Principal';
    if ($role === 'dean') return 'Dean';
    if ($role === 'staff') return 'Staff';
    return null;
}

function analytics_role_theme($label) {
    $key = strtolower(trim((string)$label));
    return match ($key) {
        'faculty', 'teacher' => [
            'bg' => 'rgba(37,99,235,.10)', 'color' => '#2563EB', 'border' => 'rgba(37,99,235,.28)'
        ],
        'staff' => [
            'bg' => 'rgba(124,58,237,.10)', 'color' => '#7C3AED', 'border' => 'rgba(124,58,237,.28)'
        ],
        'executive assistant', 'ea' => [
            'bg' => 'rgba(15,159,110,.10)', 'color' => '#0F9F6E', 'border' => 'rgba(15,159,110,.28)'
        ],
        'principal' => [
            'bg' => 'rgba(217,119,6,.11)', 'color' => '#C77A08', 'border' => 'rgba(217,119,6,.30)'
        ],
        'dean' => [
            'bg' => 'rgba(139,92,246,.12)', 'color' => '#8B5CF6', 'border' => 'rgba(139,92,246,.32)'
        ],
        'school head', 'dean / principal' => [
            'bg' => 'rgba(199,122,8,.10)', 'color' => '#C77A08', 'border' => 'rgba(199,122,8,.30)'
        ],
        default => [
            'bg' => 'rgba(30,82,144,.10)', 'color' => 'var(--ec,#1E5290)', 'border' => 'rgba(30,82,144,.25)'
        ],
    };
}

function analytics_target_group_label($activeEval, $p, $mysqli, $isMultiRole=false, $groupFilter='All') {
    $role = strtolower(trim((string)($p['role'] ?? '')));
    if ($activeEval === 'schoolhead') {
        // Match Questionnaire -> Dean / Principal Evaluation scope exactly:
        // Faculty, Staff, and Executive Assistant are the evaluated targets.
        // Do not infer the label from raw role alone; a Staff account with a
        // teaching assignment belongs to Faculty, while non-teaching Staff
        // stays under Staff.
        $schoolheadGroup = ec_resolve_schoolhead_target_group($p, $mysqli);
        return match ($schoolheadGroup) {
            'faculty' => 'Faculty',
            'staff'   => 'Staff',
            'ea'      => 'Executive Assistant',
            default   => 'Personnel',
        };
    }
    if ($activeEval === 'staff') {
        return match ($role) { 'principal'=>'Principal', 'dean'=>'Dean', 'superadmin'=>'Executive Assistant', default=>'Staff' };
    }
    if ($activeEval === 'ea') {
        return match (ec_resolve_ea_group($p, $mysqli)) { 'Principal'=>'Principal', 'Dean'=>'Dean', 'Staff'=>'Staff', default=>'Personnel' };
    }
    if ($activeEval === 'student' && in_array($groupFilter, ['Dean','Principal'], true)) {
        return $role === 'principal' ? 'Principal' : 'Dean';
    }
    if ($activeEval === 'student') {
        $sg = ec_resolve_student_group_full($p, $mysqli);
        // Principal/Dean targets resolve to 'school_head'; without this they
        // fell through to 'Staff' on the All / Faculty / Staff views.
        if ($sg === 'school_head') return $role === 'principal' ? 'Principal' : 'Dean';
        return $sg === 'teacher' ? 'Faculty' : 'Staff';
    }
    if ($activeEval === 'peer') {
        $g = ec_resolve_peer_group_full($p, $mysqli);
        if ($g === 'school_head') return $role === 'principal' ? 'Principal' : 'Dean';
        return $g === 'teacher' ? 'Faculty' : 'Staff';
    }
    return ucfirst($role);
}

// Eval type UI config — names/colors stay separate, but the database key is
// the exact eval_type used by Questionnaire/submissions.
$evalConfig = [
    'student' => ['label'=>'Student Evaluation','color'=>'#2563EB','bg'=>'rgba(37,99,235,.08)','border'=>'rgba(59,130,246,.25)','icon'=>'fa-graduation-cap','evaluator'=>'student','evaluators'=>'students','desc'=>'Evaluated by students using the Student Evaluation questionnaire.'],
    'peer' => ['label'=>'Peer-to-Peer Evaluation','color'=>'#4968C8','bg'=>'rgba(73,104,200,.08)','border'=>'rgba(124,58,237,.25)','icon'=>'fa-people-arrows','evaluator'=>'colleague','evaluators'=>'colleagues','desc'=>'Evaluated by fellow faculty and staff using the Peer-to-Peer questionnaire.'],
    'schoolhead' => ['label'=>'Dean / Principal Evaluation','color'=>'#C77A08','bg'=>'rgba(217,119,6,.08)','border'=>'rgba(217,119,6,.25)','icon'=>'fa-user-tie','evaluator'=>'evaluator','evaluators'=>'evaluators','desc'=>'Evaluated by the active Dean or Principal using the Dean / Principal questionnaire.'],
    'ea' => ['label'=>'Executive Assistant Evaluation','color'=>'#0F9F6E','bg'=>'rgba(15,159,110,.08)','border'=>'rgba(15,159,110,.22)','icon'=>'fa-user-shield','evaluator'=>'evaluator','evaluators'=>'evaluators','desc'=>'Results recorded under the Executive Assistant evaluation direction.'],
    'staff' => ['label'=>'Staff Evaluation','color'=>'#0891B2','bg'=>'rgba(8,145,178,.08)','border'=>'rgba(8,145,178,.22)','icon'=>'fa-users','evaluator'=>'staff member','evaluators'=>'staff members','desc'=>'Staff members evaluate the Dean, Principal, and Executive Assistant.'],
];
$cfg = $evalConfig[$activeEval];
$evalLabel = $cfg['label'];
$evalColor = $cfg['color'];
$evalColorBg = $cfg['bg'];
$evalColorBorder = $cfg['border'];
$evalIcon = $cfg['icon'];
$evaluatorNoun = $cfg['evaluator'];
$evaluatorNounP = $cfg['evaluators'];
// In evaluation_tracker: student_id = the evaluator (student or peer teacher)
// eval_type filters which set we show; peer reports include legacy `peer` plus current `faculty_peer` and `staff_peer` tracker values

// ══════════════════════════════════════════════════════════════
// SHARED CSS HEAD (used across all sub-views)
// ══════════════════════════════════════════════════════════════
function pageHead($title, $evalColor, $evalColorBg, $evalColorBorder) { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title><?= htmlspecialchars($title) ?> — PBI Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<style>
:root{
  --dark:#F8FAFC;--mid:#FFFFFF;--inner:#F4F8FF;--accent:#2563EB;
  --gold:#C77A08;--gold-h:#D69612;--teal:#0E7490;--violet:#4968C8;
  --light:#0B1F3A;--muted:#67819E;--danger:#E6788A;
  --border:#B9CDE5;--radius:10px;
  --card-shadow:0 1px 2px rgba(30,82,144,.08),0 4px 12px rgba(30,82,144,.08);
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
.toast{position:fixed;top:20px;right:20px;z-index:999;background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.35);color:#6BCFA9;padding:12px 20px;border-radius:8px;font-size:13px;display:flex;align-items:center;gap:8px;animation:slideIn .3s ease,fadeOut .4s ease 3s forwards;}
@keyframes slideIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:none}}
@keyframes fadeOut{to{opacity:0;pointer-events:none}}
.back-btn{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;background:var(--mid);border:1px solid var(--border);border-radius:var(--radius);color:var(--light);font-size:13px;font-weight:600;text-decoration:none;margin-bottom:22px;transition:background .2s;}
.back-btn:hover{background:var(--accent);}

/* ── EVAL SWITCHER ── */
.eval-switcher{display:flex;gap:0;background:var(--mid);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:26px;width:fit-content;}
.eval-tab{display:flex;align-items:center;gap:9px;padding:12px 24px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;color:var(--muted);border:none;background:none;font-family:'Inter',sans-serif;transition:all .2s;position:relative;}
.eval-tab:hover{color:var(--light);background:rgba(37,99,235,.05);}
.eval-tab.student.active{color:var(--accent);background:rgba(37,99,235,.08);}
.eval-tab.student.active::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:var(--accent);border-radius:2px 2px 0 0;}
.eval-tab.peer.active{color:var(--violet);background:rgba(73,104,200,.08);}
.eval-tab.peer.active::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:var(--violet);border-radius:2px 2px 0 0;}
.eval-divider{width:1px;background:var(--border);margin:8px 0;}
.tab-badge{font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;background:rgba(30,82,144,.13);color:var(--muted);}
.eval-tab.student.active .tab-badge{background:rgba(37,99,235,.10);color:var(--accent);}
.eval-tab.peer.active .tab-badge{background:rgba(73,104,200,.10);color:var(--violet);}
.eval-tab.schoolhead.active{color:#C77A08;background:rgba(217,119,6,.07);}
.eval-tab.schoolhead.active::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:#C77A08;border-radius:2px 2px 0 0;}
.eval-tab.schoolhead.active .tab-badge{background:rgba(217,119,6,.15);color:#C77A08;}
.eval-tab.ea.active{color:#0F9F6E;background:rgba(15,159,110,.08);}
.eval-tab.ea.active::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:#0F9F6E;border-radius:2px 2px 0 0;}
.eval-tab.ea.active .tab-badge{background:rgba(15,159,110,.15);color:#0F9F6E;}
.eval-tab.staff-eval.active{color:#0E7490;background:rgba(8,145,178,.08);}
.eval-tab.staff-eval.active::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:#0E7490;border-radius:2px 2px 0 0;}
.eval-tab.staff-eval.active .tab-badge{background:rgba(8,145,178,.15);color:#0E7490;}

/* Plain report tabs: role-specific icon colors remain visible even when the tab is inactive. */
.eval-tab.schoolhead > i{color:#C77A08 !important;}
.eval-tab.ea > i{color:#0F9F6E !important;}
.eval-tab.staff-eval > i{color:#0E7490 !important;}

/* ── EVAL TYPE BANNER ── */
.eval-banner{display:flex;align-items:center;gap:14px;padding:13px 18px;border-radius:10px;margin-bottom:20px;border:1px solid var(--ec-bd);background:var(--ec-bg);}
.eval-banner-icon{width:40px;height:40px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:18px;color:var(--ec);background:rgba(37,99,235,.05);border:1px solid var(--ec-bd);flex-shrink:0;}
.eval-banner-title{font-size:14px;font-weight:700;color:var(--ec);}
.eval-banner-desc{font-size:12px;color:var(--muted);margin-top:1px;}

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
.eval-tab.student.active{background:#E6F0FF !important;color:#2563EB !important;}
.eval-tab.peer.active{background:#F5F3FF !important;color:#4968C8 !important;}
.eval-banner{background:var(--ec-bg) !important;}
.avg-bar-bg,.eval-bar-bg,.score-bar-bg{background:#D8E5F4 !important;}
.comment-text,.comment-section{background:#F8FAFC !important;color:#294765 !important;}


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

/* Persistent evaluation-tab icon coding */
.eval-tab.student > i{color:#2563EB !important;}
.eval-tab.peer > i{color:#4968C8 !important;}

</style>
<?php } // end pageHead

// ══════════════════════════════════════════════════════════════
// VIEW: EVALUATION SHEET
// ══════════════════════════════════════════════════════════════
if ($view === 'sheet' && $target_id && ($tracker_id || ($_GET['report'] ?? '') === '1')) {
    // The single "Generate Report" button (top of the Evaluators List) links
    // here with &report=1 and NO tracker_id: the cumulative report pools every
    // evaluator for this person, so it doesn't belong to any one submission.
    // The per-row "View" action still passes a tracker_id for the individual sheet.
    // report=1 makes the sheet render in its clean, print-style layout
    // by default on screen — the same look the browser's print preview
    // already produced — instead of the normal interactive dashboard sheet
    // that "View" opens. See body.report-mode CSS below.
    $reportMode = (($_GET['report'] ?? '') === '1');
    $tgt = $mysqli->query("SELECT id,full_name,designation,photo,role FROM users WHERE id=$target_id LIMIT 1")->fetch_assoc();
    $stu = $mysqli->query("SELECT id,full_name,photo FROM users WHERE id=$student_id LIMIT 1")->fetch_assoc();
    $trk = $mysqli->query("SELECT * FROM evaluation_tracker WHERE id=$tracker_id LIMIT 1")->fetch_assoc();

    $answers = [];
    // Resolve submitted answers without dropping rows when a legacy answer
    // has a missing question_source mapping, a NULL user_question_id, or the
    // original question was later removed from its question bank. The answer
    // row itself is authoritative for the score.
    $aq = $mysqli->query("
        SELECT
            qa.id AS answer_id,
            COALESCE(qa.question_id, qa.user_question_id) AS q_id,
            COALESCE(eq.question_text, uq.question_text, CONCAT('Question #', COALESCE(qa.question_id, qa.user_question_id, qa.id))) AS question_text,
            COALESCE(eq.category, uq.category, 'General') AS category,
            qa.answer_score
        FROM questionnaire_answers qa
        LEFT JOIN evaluation_questions eq
            ON qa.question_source = 'evaluation'
           AND eq.id = qa.question_id
        LEFT JOIN user_questions uq
            ON qa.question_source = 'user'
           AND uq.id = COALESCE(qa.user_question_id, qa.question_id)
        WHERE qa.tracker_id = $tracker_id
        ORDER BY category, q_id, qa.id
    ");
    if ($aq) $answers = $aq->fetch_all(MYSQLI_ASSOC);

    // Some older Student → Faculty submissions reference question IDs from a
    // previous question bank. If those rows were later replaced, the direct
    // ID join above cannot recover their text and would show "Question #...".
    // For that case, use the faculty member's current Student Evaluation
    // questions in their configured order as a display fallback. This affects
    // only answers whose question text could not be resolved by either ID join.
    $missingTextIndexes = [];
    foreach ($answers as $i => $answer) {
        if (strpos((string)($answer['question_text'] ?? ''), 'Question #') === 0) {
            $missingTextIndexes[] = $i;
        }
    }

    if (!empty($missingTextIndexes)) {
        $fallbackQuestions = [];
        $targetRole = strtolower((string)($tgt['role'] ?? ''));
        if (in_array($targetRole, ['teacher','faculty'], true)) {
            $fallbackQuestions = qn_get_faculty_questions($mysqli);
        } elseif ($targetRole === 'staff') {
            $fallbackQuestions = qn_get_person_questions($mysqli, $target_id, 'Staff');
        } elseif (in_array($targetRole, ['dean','principal'], true)) {
            $fallbackQuestions = qn_get_person_questions($mysqli, $target_id, ucfirst($targetRole));
        } elseif ($targetRole === 'superadmin') {
            $fallbackQuestions = qn_get_person_questions($mysqli, $target_id, 'EA');
        }

        // Map the unresolved submitted answers to the corresponding Student
        // Evaluation questions by their display order, without changing scores
        // or the stored answer records.
        foreach ($missingTextIndexes as $pos => $answerIndex) {
            if (!empty($fallbackQuestions[$pos]['question_text'])) {
                $answers[$answerIndex]['question_text'] = $fallbackQuestions[$pos]['question_text'];
                $answers[$answerIndex]['category'] = $fallbackQuestions[$pos]['category'] ?? 'General';
            }
        }
    }

    $grouped_ans = [];
    foreach ($answers as $a) $grouped_ans[$a['category']][] = $a;

    $scores = array_filter(array_column($answers,'answer_score'), fn($s) => $s !== null);
    $avg    = count($scores) ? round(array_sum($scores)/count($scores),2) : null;
    $remark = $trk['remarks'] ?? '';

    // ── CUMULATIVE REPORT DATA (print / "Generate Report" layout) ─────────
    // The printed report is the cumulative result for this person under the
    // current evaluation type: every submission is pooled, each question shows
    // its average rating across all evaluators, and the overall rating is the
    // average of those per-question ratings. Uses the same evaluator set as
    // the Evaluators List, so totals always agree with it.
    $cumTrackers = [];
    $cq = $mysqli->query("
        SELECT et.id AS tracker_id, et.period_id, et.submitted_at, et.remarks, u.role AS evaluator_role
        FROM evaluation_tracker et
        JOIN users u ON u.id = et.evaluator_id
        WHERE et.target_user_id = $target_id AND $evalTypeSql $yearLevelSql AND " . aa_scored_tracker_sql('et', (int)$target_id) . "
        ORDER BY et.submitted_at DESC
    ");
    if ($cq) $cumTrackers = $cq->fetch_all(MYSQLI_ASSOC);
    if (empty($cumTrackers) && $trk) {
        // Safety net: never print an empty report for a valid sheet link.
        $cumTrackers = [[
            'tracker_id'     => (int)$trk['id'],
            'period_id'      => (int)($trk['period_id'] ?? 0),
            'submitted_at'   => $trk['submitted_at'],
            'remarks'        => $trk['remarks'] ?? '',
            'evaluator_role' => '',
        ]];
    }

    $cumByCat = [];
    $cumOverall = null;
    if (!empty($cumTrackers)) {
        $cumIds = implode(',', array_map('intval', array_column($cumTrackers, 'tracker_id')));
        $cqb = $mysqli->query("
            SELECT
                COALESCE(qa.question_id, qa.user_question_id) AS q_id,
                COALESCE(eq.question_text, uq.question_text, CONCAT('Question #', COALESCE(qa.question_id, qa.user_question_id, qa.id))) AS question_text,
                COALESCE(eq.category, uq.category, 'General') AS category,
                COUNT(qa.answer_score) AS total_responses,
                AVG(qa.answer_score) AS avg_score
            FROM questionnaire_answers qa
            LEFT JOIN evaluation_questions eq
                ON qa.question_source = 'evaluation' AND eq.id = qa.question_id
            LEFT JOIN user_questions uq
                ON qa.question_source = 'user' AND uq.id = COALESCE(qa.user_question_id, qa.question_id)
            WHERE qa.tracker_id IN ($cumIds) AND qa.answer_score IS NOT NULL
              AND " . aa_current_q_sql('qa', (int)$target_id) . "
            GROUP BY category, q_id, question_text
            ORDER BY category, q_id
        ");
        if ($cqb) {
            foreach (aa_merge_question_rows($cqb->fetch_all(MYSQLI_ASSOC)) as $cRow) {
                $cRow['avg_score'] = round((float)$cRow['avg_score'], 2);
                $cumByCat[$cRow['category']][] = $cRow;
            }
        }
        // Overall = flat average across every individual answer pooled together
        // (same method the Evaluated Personnel roster uses: AVG(qa.answer_score)
        // over all rows). Previously this was computed as the average of the
        // rounded per-question averages instead, which only matches the roster
        // figure when every question has the same number of responses — it
        // silently drifts from the roster's number whenever a question is
        // skipped by an evaluator or added/removed between evaluation cycles.
        // Kept as its own direct query (not derived from $cumByCat's rounded
        // per-question values) so it is exactly the same figure the roster shows.
        $cumOverallRes = $mysqli->query("
            SELECT AVG(qa.answer_score) AS overall_avg
            FROM questionnaire_answers qa
            WHERE qa.tracker_id IN ($cumIds) AND qa.answer_score IS NOT NULL
              AND " . aa_current_q_sql('qa', (int)$target_id) . "
        ");
        if ($cumOverallRes) {
            $cumOverallRow = $cumOverallRes->fetch_assoc();
            $cumOverall = $cumOverallRow && $cumOverallRow['overall_avg'] !== null
                ? round((float)$cumOverallRow['overall_avg'], 2)
                : null;
        }
    }

    // Date(s) this person was evaluated.
    $cumDates = array_filter(array_column($cumTrackers, 'submitted_at'));
    if ($cumDates) {
        $cumFrom = strtotime(min($cumDates));
        $cumTo   = strtotime(max($cumDates));
        $cumDateText = (date('Y-m-d', $cumFrom) === date('Y-m-d', $cumTo))
            ? date('F d, Y', $cumTo)
            : date('M d, Y', $cumFrom) . ' – ' . date('M d, Y', $cumTo);
    } else {
        $cumDateText = '—';
    }

    // Prefer the actual evaluation window (Opens / Closes) of the period these
    // submissions belong to (evaluation_tracker.period_id -> evaluation_periods
    // date_start / date_end). For the currently active period the live schedule
    // in system_settings (eval_start / eval_end) is used when set, since it holds
    // the exact times. Falls back to the submission-date range above.
    $cumOpenRaw = $cumCloseRaw = '';
    $cumPeriodIds = array_values(array_unique(array_filter(array_map('intval', array_column($cumTrackers, 'period_id')))));
    if ($cumPeriodIds) {
        $cpIn = implode(',', $cumPeriodIds);
        $cpq = $mysqli->query("SELECT MIN(date_start) AS ds, MAX(date_end) AS de, MAX(is_active) AS act FROM evaluation_periods WHERE id IN ($cpIn)");
        if ($cpq && ($cpr = $cpq->fetch_assoc())) {
            $cumOpenRaw  = trim((string)($cpr['ds'] ?? ''));
            $cumCloseRaw = trim((string)($cpr['de'] ?? ''));
            if ((int)($cpr['act'] ?? 0) === 1 || !$cumOpenRaw || !$cumCloseRaw) {
                $csq = $mysqli->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('eval_start','eval_end')");
                $cumSched = [];
                if ($csq) while ($csr = $csq->fetch_assoc()) $cumSched[$csr['setting_key']] = trim((string)$csr['setting_value']);
                if (!empty($cumSched['eval_start']) && !empty($cumSched['eval_end'])) {
                    $cumOpenRaw  = $cumSched['eval_start'];
                    $cumCloseRaw = $cumSched['eval_end'];
                }
            }
        }
    }
    if ($cumOpenRaw && $cumCloseRaw && strpos($cumOpenRaw, '0000-00-00') !== 0 && strpos($cumCloseRaw, '0000-00-00') !== 0) {
        try {
            $cumTz    = new DateTimeZone('Asia/Manila');
            $cumOpen  = new DateTime($cumOpenRaw, $cumTz);
            $cumClose = new DateTime($cumCloseRaw, $cumTz);
            if ($cumClose > $cumOpen) {
                $cumDateText = $cumOpen->format('M j, Y, g:i A') . "\n" . $cumClose->format('M j, Y, g:i A');
            }
        } catch (Exception $e) { /* keep the submission-date fallback */ }
    }

    // Who evaluated (Students, Faculty, Staff, Dean, Principal ...).
    $cumRoleLabels = ['student'=>'Students','teacher'=>'Faculty','faculty'=>'Faculty','staff'=>'Staff','dean'=>'Dean','principal'=>'Principal','superadmin'=>'Executive Assistant'];
    $cumEvalCounts = [];
    foreach ($cumTrackers as $ct) {
        $r = strtolower(trim((string)($ct['evaluator_role'] ?? '')));
        $lbl = $cumRoleLabels[$r] ?? ucfirst($evaluatorNounP);
        $cumEvalCounts[$lbl] = ($cumEvalCounts[$lbl] ?? 0) + 1;
    }
    // e.g. "4 Students" or "3 Faculty, 1 Staff"
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

    $scaleItems  = [5=>'Always',4=>'Often',3=>'Sometimes',2=>'Rarely',1=>'Never'];
    $scaleColors = [5=>'#32B98A',4=>'#6BCFA9',3=>'#facc15',2=>'#fb923c',1=>'#E6788A'];

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
.avg-bar-bg{flex:1;height:7px;background:rgba(30,82,144,.13);border-radius:4px;overflow:hidden;}
.avg-bar-fill{height:100%;border-radius:4px;}
.avg-bar-val{font-size:12px;font-weight:700;width:28px;}
.avg-out-of{font-size:13px;color:var(--muted);margin-top:6px;}
.btn-print{background:var(--accent);color:#fff;border:none;padding:10px 20px;border-radius:var(--radius);font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:opacity .2s;font-family:'Inter',sans-serif;}
.btn-print:hover{opacity:.85;}
.top-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;}

/* ── PRINT: hide evaluator identity entirely ── */
@media print{
  html,body{width:100%!important;height:auto!important;}
  .no-print{display:none!important;}
  body{background:#fff!important;color:#000!important;padding:0!important;margin:0!important;min-height:0!important;}
  body *{color:#000!important;}

  /* Compact the evaluation sheet for printing */
  .sheet-header{padding:12px 16px!important;margin-bottom:10px!important;gap:12px!important;border-radius:0!important;}
  .sheet-avatar,.sheet-avatar-ph{width:46px!important;height:46px!important;}
  .sheet-name{font-size:18px!important;}
  .sheet-desig{font-size:11px!important;}
  .cat-section{margin-bottom:12px!important;break-inside:avoid;page-break-inside:avoid;}
  .cat-title{margin-bottom:6px!important;padding-bottom:3px!important;}
  .q-table th{padding:6px 10px!important;}
  .q-table td{padding:6px 10px!important;line-height:1.25!important;vertical-align:middle!important;}
  .q-table td.q-num{padding-top:6px!important;}
  .q-table td.rating-cell{padding-top:5px!important;}
  .rating-badge{padding:3px 8px!important;gap:0!important;}
  .rating-num{font-size:14px!important;}
  .rating-lbl{font-size:8px!important;}
  .comment-section{padding:10px 14px!important;margin-bottom:12px!important;border-radius:0!important;}
  .comment-title{margin-bottom:5px!important;}
  .comment-text{line-height:1.35!important;}
  .avg-summary{padding:12px 14px!important;gap:14px!important;border-radius:0!important;break-inside:avoid;page-break-inside:avoid;}
  .avg-score-big{font-size:42px!important;}
  .avg-bar-row{margin-bottom:4px!important;}

  /* Hide the "Evaluated by" block in the sheet header */
  .sheet-eval-by{display:none!important;}
  /* Anonymity notice shown only in print */
  .print-anon-notice{display:block!important;}
}
/* ── CUMULATIVE REPORT: shown only in the print / Generate Report layout ── */
.cumulative-sheet{display:none;}
.cum-topbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;font-size:12px;margin-bottom:10px;padding:8px 14px;border:1px solid #ddd;background:#f5f5f5;}
.cum-type{font-weight:700;text-transform:uppercase;letter-spacing:.8px;}
.cum-date{margin-left:auto;text-align:right;}
.cum-date-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-bottom:2px;}
.cum-date-val{font-size:14px;font-weight:700;}
.cumulative-sheet .rating-badge{width:128px;height:38px;box-sizing:border-box;justify-content:center;align-items:center;padding:2px 6px!important;gap:0!important;}
.cumulative-sheet .rating-num{font-size:14px!important;line-height:1.1;}
.cumulative-sheet .rating-lbl{font-size:8px!important;text-align:center;line-height:1.1;}
.cum-total{margin-bottom:12px;justify-content:space-between;}
.cum-total-label{font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:1px;align-self:center;}
.cum-total{flex-direction:column;align-items:flex-start!important;justify-content:flex-start;gap:6px!important;}
.cum-total-label{align-self:flex-start;}
.cum-total-result{text-align:left;margin-right:0;}
.cum-legend-rows{min-width:0;}
.cum-legend-text{flex:0 1 auto;text-align:left;}
.cum-legend-rows{display:flex;flex-direction:column;gap:5px;min-width:340px;}
.cum-legend-row{display:flex;align-items:center;gap:10px;font-size:13px;}
.cum-legend-dot{width:12px;height:12px;border-radius:3px;flex-shrink:0;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
.cum-legend-range{font-weight:700;width:92px;flex-shrink:0;}

.cum-remark{font-size:13px;line-height:1.4;font-style:italic;margin-bottom:5px;}
@media print{
  .individual-sheet{display:none!important;}
  .cumulative-sheet{display:block!important;}
  .cum-topbar{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
}
body.report-mode .individual-sheet{display:none!important;}
body.report-mode .cumulative-sheet{display:block!important;}

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
.feature-compact ::-webkit-scrollbar { width: 10px !important; height: 10px !important; }
html::-webkit-scrollbar-track, body::-webkit-scrollbar-track,
.feature-compact ::-webkit-scrollbar-track { background: transparent !important; }
html::-webkit-scrollbar-thumb, body::-webkit-scrollbar-thumb,
.feature-compact ::-webkit-scrollbar-thumb {
  background: #888 !important; border-radius: 999px !important;
  border: 2px solid transparent !important; background-clip: padding-box !important;
}
html::-webkit-scrollbar-thumb:hover, body::-webkit-scrollbar-thumb:hover,
.feature-compact ::-webkit-scrollbar-thumb:hover { background: #777 !important; background-clip: padding-box !important; }
html::-webkit-scrollbar-button, body::-webkit-scrollbar-button,
.feature-compact ::-webkit-scrollbar-button { display: block !important; width: 10px !important; height: 10px !important; background-color: transparent !important; }

</style>
<script>
/* Evaluation Report is often rendered inside the dark admin shell as an iframe.
   Keep this document synchronized with the parent theme before and after first
   paint, including theme changes made while this iframe remains open. */
(function () {
  function getParentTheme() {
    try {
      if (window.parent && window.parent !== window) {
        var t = window.parent.document.documentElement.getAttribute('data-theme');
        if (t === 'dark' || t === 'light') return t;
      }
    } catch (e) {}
    var saved = null;
    try { saved = localStorage.getItem('pbiTheme'); } catch (e) {}
    return saved === 'dark' ? 'dark' : 'light';
  }

  function syncTheme() {
    var theme = getParentTheme();
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.style.colorScheme = theme;
  }

  syncTheme();

  try {
    var parentRoot = window.parent && window.parent !== window
      ? window.parent.document.documentElement
      : null;
    if (parentRoot && window.MutationObserver) {
      new MutationObserver(syncTheme).observe(parentRoot, {
        attributes: true,
        attributeFilter: ['data-theme']
      });
    }
  } catch (e) {}

  window.addEventListener('storage', function (e) {
    if (e.key === 'pbiTheme') syncTheme();
  });
})();
</script>

<style id="evaluation-report-dark-overrides">
/* Evaluation Report: page-local light rules must not repaint report surfaces in dark mode. */
html[data-theme="dark"] .target-card,
html[data-theme="dark"] .sheet-header,
html[data-theme="dark"] .scale-bar,
html[data-theme="dark"] .comment-section,
html[data-theme="dark"] .avg-summary,
html[data-theme="dark"] .eval-card,
html[data-theme="dark"] .sum-card,
html[data-theme="dark"] .standing-panel,
html[data-theme="dark"] .person-row,
html[data-theme="dark"] .no-evaluated,
html[data-theme="dark"] .no-eval,
html[data-theme="dark"] .score-legend,
html[data-theme="dark"] .peer-info-note,
html[data-theme="dark"] .results-table-wrap,
html[data-theme="dark"] .evaluator-grid,
html[data-theme="dark"] .desig-subtabs,
html[data-theme="dark"] .ra-table-card,
html[data-theme="dark"] .ra-filters-panel {
    background: var(--panel-bg) !important;
    color: var(--text) !important;
    border-color: var(--panel-border) !important;
}

html[data-theme="dark"] .target-avatar-ph,
html[data-theme="dark"] .eval-avatar-ph,
html[data-theme="dark"] .person-photo-ph,
html[data-theme="dark"] .sheet-avatar-ph,
html[data-theme="dark"] .rating-badge,
html[data-theme="dark"] .score-legend,
html[data-theme="dark"] .avg-bar-bg {
    background: var(--input-bg) !important;
    color: var(--text) !important;
    border-color: var(--panel-border) !important;
}

html[data-theme="dark"] .target-name,
html[data-theme="dark"] .sheet-name,
html[data-theme="dark"] .section-title,
html[data-theme="dark"] .sum-value,
html[data-theme="dark"] .person-name,
html[data-theme="dark"] .score-legend-title,
html[data-theme="dark"] .ra-name-text a,
html[data-theme="dark"] table.results-table td,
html[data-theme="dark"] table.ra-table td {
    color: var(--text) !important;
}

html[data-theme="dark"] .target-desig,
html[data-theme="dark"] .sheet-desig,
html[data-theme="dark"] .eval-by-label,
html[data-theme="dark"] .eval-by-date,
html[data-theme="dark"] .eval-desc,
html[data-theme="dark"] .no-evaluated,
html[data-theme="dark"] .no-eval,
html[data-theme="dark"] .score-legend-row,
html[data-theme="dark"] .sum-label,
html[data-theme="dark"] .sum-sub,
html[data-theme="dark"] .pstat-lbl,
html[data-theme="dark"] .standing-rank,
html[data-theme="dark"] .standing-desig,
html[data-theme="dark"] .ra-pager-info {
    color: var(--muted) !important;
}

html[data-theme="dark"] table.results-table,
html[data-theme="dark"] table.ra-table,
html[data-theme="dark"] .q-table {
    background: var(--panel-bg) !important;
    color: var(--text) !important;
}

html[data-theme="dark"] table.results-table thead tr,
html[data-theme="dark"] table.ra-table thead tr,
html[data-theme="dark"] .q-table thead tr {
    background: var(--input-bg) !important;
}

html[data-theme="dark"] table.results-table th,
html[data-theme="dark"] table.results-table td,
html[data-theme="dark"] table.ra-table th,
html[data-theme="dark"] table.ra-table td,
html[data-theme="dark"] .q-table th,
html[data-theme="dark"] .q-table td {
    color: var(--text) !important;
    border-color: var(--panel-border) !important;
}

html[data-theme="dark"] table.results-table tbody tr:hover,
html[data-theme="dark"] table.ra-table tbody tr:hover,
html[data-theme="dark"] .q-table tr:hover td {
    background: var(--input-bg) !important;
}

html[data-theme="dark"] .score-bar-bg,
html[data-theme="dark"] .avg-bar-bg,
html[data-theme="dark"] .eval-bar-bg {
    background: var(--panel-border) !important;
}

html[data-theme="dark"] .faculty-subtabs,
html[data-theme="dark"] .desig-subtabs,
html[data-theme="dark"] .ra-filters-panel {
    background: var(--panel-bg) !important;
    border-color: var(--panel-border) !important;
    color: var(--text) !important;
}

html[data-theme="dark"] .faculty-subtab:hover,
html[data-theme="dark"] .desig-subtab:hover {
    background: var(--input-bg) !important;
    color: var(--text) !important;
}

/* Evaluation Report roster/list view. The page-specific light rules below
   use var(--mid)/var(--inner); explicitly remap every visible report surface
   so the list view follows the dark admin appearance as well. */
html[data-theme="dark"] .ra-table-card{
    background:var(--panel-bg) !important;
    color:var(--text) !important;
    border-color:var(--panel-border) !important;
}
html[data-theme="dark"] .ra-tool-btn{
    background:var(--input-bg) !important;
    color:var(--text) !important;
    border-color:var(--panel-border) !important;
}
html[data-theme="dark"] .ra-tool-btn:hover{
    background:var(--panel-bg) !important;
    color:var(--ec) !important;
}
html[data-theme="dark"] .ra-search,
html[data-theme="dark"] .ra-filter-row select{
    background:var(--input-bg) !important;
    color:var(--text) !important;
    border-color:var(--panel-border) !important;
}
html[data-theme="dark"] .ra-search::placeholder{
    color:var(--muted) !important;
}
html[data-theme="dark"] .ra-filter-clear{
    color:var(--muted) !important;
    border-color:var(--panel-border) !important;
}
html[data-theme="dark"] .ra-filter-clear:hover{
    color:var(--ec) !important;
    border-color:var(--ec) !important;
}
html[data-theme="dark"] .ra-photo-ph{
    background:var(--input-bg) !important;
    color:var(--muted) !important;
    border-color:var(--panel-border) !important;
}
html[data-theme="dark"] .ra-table-wrap,
html[data-theme="dark"] table.ra-table{
    background:var(--panel-bg) !important;
}
html[data-theme="dark"] table.ra-table th,
html[data-theme="dark"] table.ra-table td{
    color:var(--text) !important;
    border-color:var(--panel-border) !important;
}
html[data-theme="dark"] table.ra-table th{
    background:var(--input-bg) !important;
}
html[data-theme="dark"] table.ra-table tbody tr:hover{
    background:var(--input-bg) !important;
}
html[data-theme="dark"] .ra-name-text a{
    color:var(--text) !important;
}
html[data-theme="dark"] .ra-name-text a:hover{
    color:var(--ec) !important;
}
html[data-theme="dark"] .ra-icon-btn,
html[data-theme="dark"] .ra-pager-btns button{
    background:var(--input-bg) !important;
    color:var(--text) !important;
    border-color:var(--panel-border) !important;
}
html[data-theme="dark"] .ra-pager-btns button.active{
    background:var(--ec) !important;
    border-color:var(--ec) !important;
    color:#fff !important;
}
html[data-theme="dark"] .ra-pager-info{
    color:var(--muted) !important;
}

/* The report's broad light reset sits later in the document than the shared
   stylesheet, so keep the root/background dark once the dark theme is active. */
html[data-theme="dark"]{
    background:var(--bg) !important;
    color-scheme:dark !important;
}
html[data-theme="dark"] body{
    background:var(--bg) !important;
    color:var(--text) !important;
}

/* ── REPORT MODE: the "Generate Report" action's clean, document-style
   look — the same appearance the print stylesheet below already produces —
   applied directly on screen (via body.report-mode) so a person doesn't
   have to open the print dialog just to see it. "View" doesn't add this
   class, so it keeps the normal interactive dashboard-styled sheet. */
/* Layout-only rules (theme independent) */
body.report-mode .btn-print{color:#fff!important;}
body.report-mode .eval-type-chip{display:none!important;}
body.report-mode .scale-bar{display:none!important;}
body.report-mode .score-legend{display:none!important;}
body.report-mode .sheet-eval-by.no-print{display:none!important;}
body.report-mode .print-anon-notice{display:block!important;}
body.report-mode .sheet-header{border:1px solid #ddd;padding:12px 16px!important;margin-bottom:10px!important;gap:12px!important;border-radius:0!important;}
body.report-mode .cat-section{margin-bottom:12px!important;}
body.report-mode .cat-title{margin-bottom:6px!important;padding-bottom:3px!important;}
body.report-mode .q-table{border-radius:0!important;}
body.report-mode .q-table th,body.report-mode .q-table td{padding:6px 10px!important;}
body.report-mode .q-table tr:hover td{background:transparent!important;}
body.report-mode .rating-badge{padding:3px 8px!important;gap:0!important;}
body.report-mode .comment-section{border-radius:0!important;padding:10px 14px!important;margin-bottom:12px!important;}
body.report-mode .avg-summary{border-radius:0!important;padding:12px 14px!important;gap:14px!important;}

/* LIGHT theme: white paper look */
html:not([data-theme="dark"]) body.report-mode{background:#fff!important;color:#000!important;}
html:not([data-theme="dark"]) body.report-mode *{color:#000!important;}
html:not([data-theme="dark"]) body.report-mode .btn-print{color:#fff!important;}
html:not([data-theme="dark"]) body.report-mode .back-btn{color:#294765!important;}
html:not([data-theme="dark"]) body.report-mode .sheet-header,
html:not([data-theme="dark"]) body.report-mode .q-table,
html:not([data-theme="dark"]) body.report-mode .rating-badge,
html:not([data-theme="dark"]) body.report-mode .comment-section,
html:not([data-theme="dark"]) body.report-mode .avg-summary{background:#fff!important;}
html:not([data-theme="dark"]) body.report-mode .cat-title{border-color:#ddd!important;}
html:not([data-theme="dark"]) body.report-mode .q-table{border:1px solid #ddd!important;}
html:not([data-theme="dark"]) body.report-mode .q-table thead tr{background:#f5f5f5!important;}
html:not([data-theme="dark"]) body.report-mode .q-table th,
html:not([data-theme="dark"]) body.report-mode .q-table td{border-color:#eee!important;}
html:not([data-theme="dark"]) body.report-mode .rating-badge{border:1px solid #ccc!important;}
html:not([data-theme="dark"]) body.report-mode .comment-section,
html:not([data-theme="dark"]) body.report-mode .avg-summary{border:1px solid #ddd!important;}

/* DARK theme (screen only; printing always stays white, see below) */
@media screen{
html[data-theme="dark"] body.report-mode{background:var(--bg)!important;color:var(--text)!important;}
html[data-theme="dark"] body.report-mode .cum-topbar,
html[data-theme="dark"] body.report-mode .sheet-header,
html[data-theme="dark"] body.report-mode .q-table,
html[data-theme="dark"] body.report-mode .comment-section,
html[data-theme="dark"] body.report-mode .avg-summary{background:var(--panel-bg)!important;border:1px solid var(--panel-border)!important;color:var(--text)!important;}
html[data-theme="dark"] body.report-mode .q-table thead tr{background:var(--input-bg)!important;}
html[data-theme="dark"] body.report-mode .q-table th,
html[data-theme="dark"] body.report-mode .q-table td{border-color:var(--panel-border)!important;color:var(--text)!important;}
html[data-theme="dark"] body.report-mode .cat-title{color:var(--text)!important;border-color:var(--panel-border)!important;}
html[data-theme="dark"] body.report-mode .rating-badge{background:var(--input-bg)!important;border:1px solid var(--panel-border)!important;}
html[data-theme="dark"] body.report-mode .sheet-name,
html[data-theme="dark"] body.report-mode .cum-type,
html[data-theme="dark"] body.report-mode .cum-by,
html[data-theme="dark"] body.report-mode .cum-date,
html[data-theme="dark"] body.report-mode .cum-date-val,
html[data-theme="dark"] body.report-mode .cum-total-label,
html[data-theme="dark"] body.report-mode .comment-title,
html[data-theme="dark"] body.report-mode .cum-remark{color:var(--text)!important;}
html[data-theme="dark"] body.report-mode .sheet-desig,
html[data-theme="dark"] body.report-mode .cum-date-label,
html[data-theme="dark"] body.report-mode .avg-out-of,
html[data-theme="dark"] body.report-mode .no-comment{color:var(--muted)!important;}
html[data-theme="dark"] body.report-mode .sheet-avatar-ph{background:var(--input-bg)!important;color:var(--muted)!important;}
html[data-theme="dark"] body.report-mode .back-btn{background:var(--panel-bg)!important;color:var(--text)!important;border-color:var(--panel-border)!important;}
/* rating numbers / total score keep their inline score colours */
}

/* Printing in dark mode: force the white paper look regardless of theme */
@media print{
html[data-theme="dark"],html[data-theme="dark"] body{background:#fff!important;color:#000!important;}
html[data-theme="dark"] .sheet-header,
html[data-theme="dark"] .cum-topbar,
html[data-theme="dark"] .q-table,
html[data-theme="dark"] .q-table thead tr,
html[data-theme="dark"] .rating-badge,
html[data-theme="dark"] .comment-section,
html[data-theme="dark"] .avg-summary{background:#fff!important;border-color:#ddd!important;}
}


/* ── PRINT (cumulative report): start near the top, compact enough for 30 questions,
   no per-question satisfaction label, comments always on their own page ── */
@page{margin:0!important;}
@media print{
  html body{padding:7mm 12mm 10mm!important;margin:0!important;}
  /* any page header / top bar must not print */
  .topbar,.top-header,.portal-topbar,.dean-portal-topbar,.admin-topbar,header.topbar{display:none!important;}
  body .cumulative-sheet{margin:0!important;padding:0!important;}
  body .cumulative-sheet .rating-lbl{display:none!important;}

  body .cumulative-sheet .cum-topbar{margin:0 0 4px!important;padding:4px 10px!important;font-size:10px!important;}
  body .cumulative-sheet .sheet-header{margin:0 0 4px!important;padding:6px 10px!important;gap:8px!important;}
  body .cumulative-sheet .sheet-avatar,
  body .cumulative-sheet .sheet-avatar-ph{width:32px!important;height:32px!important;font-size:14px!important;}
  body .cumulative-sheet .sheet-name{font-size:14px!important;line-height:1.1!important;}
  body .cumulative-sheet .sheet-desig{font-size:9px!important;line-height:1.2!important;}
  body .cumulative-sheet .cum-date-label{font-size:8px!important;margin-bottom:0!important;}
  body .cumulative-sheet .cum-date-val{font-size:11px!important;}
  body .cumulative-sheet .cat-section{margin:0 0 5px!important;break-inside:auto!important;page-break-inside:auto!important;}
  body .cumulative-sheet .cat-title{margin:0 0 3px!important;padding:0 0 2px!important;font-size:9px!important;letter-spacing:1px!important;break-after:avoid;page-break-after:avoid;}
  body .cumulative-sheet .q-table thead{display:table-header-group;}
  body .cumulative-sheet .q-table tr{break-inside:avoid;page-break-inside:avoid;}
  body .cumulative-sheet .q-table th{padding:3px 8px!important;font-size:8.5px!important;}
  body .cumulative-sheet .q-table td{padding:2px 8px!important;font-size:10.5px!important;line-height:1.15!important;}
  body .cumulative-sheet .q-table td.q-num{padding-top:2px!important;width:30px!important;}
  body .cumulative-sheet .q-table td.rating-cell{padding-top:2px!important;}
  body .cumulative-sheet .rating-badge{width:62px!important;height:18px!important;padding:0 4px!important;border-radius:5px!important;}
  body .cumulative-sheet .rating-num{font-size:11px!important;line-height:1!important;}
  body .cumulative-sheet .cum-total{margin:0 0 5px!important;padding:6px 12px!important;}
  body .cumulative-sheet .cum-total-label{font-size:11px!important;}
  body .cumulative-sheet .cum-legend-rows{gap:1px!important;min-width:300px!important;}
  body .cumulative-sheet .cum-legend-row{font-size:9.5px!important;gap:6px!important;}
  body .cumulative-sheet .cum-legend-dot{width:8px!important;height:8px!important;}
  body .cumulative-sheet .cum-legend-range{width:70px!important;}
  body .cumulative-sheet .avg-score-big{font-size:28px!important;}
  body .cumulative-sheet .avg-score-label{font-size:11px!important;margin-top:2px!important;}
  body .cumulative-sheet .avg-out-of{font-size:9px!important;margin-top:2px!important;}

  /* Comments on their own page; padding (not margin) gives the top gap since margins are dropped at a page break */
  body .cumulative-sheet .cum-comments-page{break-before:page!important;page-break-before:always!important;padding-top:14mm!important;margin:0!important;}
  body .cumulative-sheet .cum-comments-page .comment-section{margin-top:0!important;}
}
</style>

<link rel="stylesheet" href="admin_appearance.css">
<script src="admin_appearance.js"></script>
<link rel="stylesheet" href="admin_unified_ui.css?v=<?= (int)@filemtime(__DIR__ . '/admin_unified_ui.css') ?>">
</head>
<body class="feature-compact<?= $reportMode ? ' report-mode' : '' ?>">
<script src="admin_ajax.php"></script>
<script>
// Confirm dialog helper: uses the dashboard's PBI.confirm when it is reachable
// (this page runs in an iframe, so PBI may only exist in the parent window),
// otherwise falls back to a built-in modal. Never uses the native browser confirm.
if (!window.saConfirm) (function(){
    const css = document.createElement('style');
    css.textContent = `
    .sa-modal-backdrop{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(16,39,70,.55);backdrop-filter:blur(2px);opacity:0;transition:opacity .15s ease}
    .sa-modal-backdrop.show{opacity:1}
    .sa-modal{width:100%;max-width:440px;max-height:calc(100vh - 36px);display:flex;flex-direction:column;background:var(--mid,#fff);color:var(--light,#0B1F3A);border-radius:16px;box-shadow:0 24px 60px rgba(10,30,60,.35);transform:translateY(8px) scale(.98);transition:transform .15s ease;overflow:hidden;font-family:Inter,system-ui,sans-serif}
    .sa-modal-backdrop.show .sa-modal{transform:none}
    .sa-modal-head{display:flex;gap:12px;align-items:center;padding:20px 22px 6px}
    .sa-modal-icon{flex:0 0 38px;height:38px;border-radius:50%;display:grid;place-items:center;font-size:16px;background:rgba(214,69,93,.12);color:#D6455D}
    .sa-modal.safe .sa-modal-icon{background:rgba(37,99,235,.12);color:#2563EB}
    .sa-modal-title{margin:0;font-size:16px;font-weight:800;letter-spacing:-.01em}
    .sa-modal-body{padding:8px 22px 18px;overflow:auto;white-space:pre-line;font-size:13px;line-height:1.55;color:var(--muted,#67819E)}
    .sa-modal-foot{display:flex;justify-content:flex-end;gap:10px;padding:14px 22px 20px;border-top:1px solid var(--border,#D8E5F4)}
    .sa-btn{border:1px solid var(--border,#B9CDE5);background:transparent;color:inherit;border-radius:10px;padding:10px 18px;font:700 13px Inter,system-ui,sans-serif;cursor:pointer}
    .sa-btn.ok{border-color:transparent;color:#fff;background:#D6455D}
    .sa-modal.safe .sa-btn.ok{background:#2563EB}
    .sa-btn:focus-visible{outline:3px solid rgba(37,99,235,.35);outline-offset:2px}`;
    document.head.appendChild(css);

    function builtin(message, opts){
        opts = opts || {};
        const danger = opts.danger !== false;
        let title, body;
        const text = String(message);
        if (text.indexOf('\n') > -1) { const i = text.indexOf('\n'); title = text.slice(0, i); body = text.slice(i).trim(); }
        else { const m = text.match(/^(.*?\?)\s+(.+)$/); title = m ? m[1] : text; body = m ? m[2] : ''; }
        return new Promise(resolve => {
            const back = document.createElement('div');
            back.className = 'sa-modal-backdrop';
            back.innerHTML = `
              <div class="sa-modal ${danger ? '' : 'safe'}" role="alertdialog" aria-modal="true">
                <div class="sa-modal-head"><div class="sa-modal-icon"><i class="fa-solid ${danger ? 'fa-triangle-exclamation' : 'fa-circle-question'}"></i></div><h3 class="sa-modal-title"></h3></div>
                <div class="sa-modal-body"></div>
                <div class="sa-modal-foot"><button type="button" class="sa-btn cancel"></button><button type="button" class="sa-btn ok"></button></div>
              </div>`;
            back.querySelector('.sa-modal-title').textContent = title;
            const bodyEl = back.querySelector('.sa-modal-body');
            if (body) bodyEl.textContent = body; else bodyEl.remove();
            const okBtn = back.querySelector('.ok'), noBtn = back.querySelector('.cancel');
            okBtn.textContent = opts.okLabel || 'OK';
            noBtn.textContent = opts.cancelLabel || 'Cancel';
            function close(v){ document.removeEventListener('keydown', onKey, true); back.classList.remove('show'); setTimeout(() => back.remove(), 150); resolve(v); }
            function onKey(e){
                if (e.key === 'Escape'){ e.preventDefault(); close(false); }
                else if (e.key === 'Tab'){ e.preventDefault(); (document.activeElement === okBtn ? noBtn : okBtn).focus(); }
            }
            okBtn.addEventListener('click', () => close(true));
            noBtn.addEventListener('click', () => close(false));
            back.addEventListener('mousedown', e => { if (e.target === back) close(false); });
            document.addEventListener('keydown', onKey, true);
            document.body.appendChild(back);
            requestAnimationFrame(() => { back.classList.add('show'); noBtn.focus(); });
        });
    }

    function findPBI(){
        for (const w of [window, window.parent, window.top]) {
            try { if (w && w.PBI && typeof w.PBI.confirm === 'function') return w.PBI; } catch (e) {}
        }
        return null;
    }

    window.saConfirm = async function(message, opts){
        try {
            const pbi = findPBI();
            if (pbi) return await pbi.confirm(message, opts);
        } catch (err) { console.error('PBI.confirm failed, using built-in modal', err); }
        return builtin(message, opts);
    };
})();

// Navigates to a link's href only after the custom (non-native) confirm modal is accepted.
function confirmNav(link, message, opts){
    (async () => {
        if (await window.saConfirm(message, opts)) {
            window.location.href = link.href;
        }
    })();
    return false;
}
</script>

<div class="person-actions no-print" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <a href="?view=students&target_id=<?= $target_id ?>&group=<?= urlencode($groupFilter) ?>&eval_type=<?= $activeEval ?>&evaluator=<?= urlencode($evaluatorFilter) ?>&year_level=<?= urlencode($yearLevel) ?>" class="back-btn">
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
        <div class="sheet-desig"><?= htmlspecialchars($tgt['designation']) ?> · <?= in_array($tgt['role'], ['principal','dean'], true) ? ($tgt['role']==='principal'?'Principal':'Dean') : ($tgt['role']==='teacher'?'Faculty':'Staff') ?></div>
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
     overall cumulative rating at the end. -->
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
            <div class="sheet-desig"><?= htmlspecialchars($tgt['designation']) ?> · <?= in_array($tgt['role'], ['principal','dean'], true) ? ($tgt['role']==='principal'?'Principal':'Dean') : ($tgt['role']==='teacher'?'Faculty':'Staff') ?></div>
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
                $cs = $cRow['avg_score']; $cc = scoreColor($cs); ?>
            <tr>
                <td class="q-num"><?= $cumNum++ ?></td>
                <td><?= htmlspecialchars($cRow['question_text']) ?></td>
                <td class="rating-cell">
                    <div class="rating-badge" style="border:1px solid <?= $cc ?>33">
                        <span class="rating-num" style="color:<?= $cc ?>"><?= number_format($cs, 2) ?></span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <?php if (empty($cumByCat)): ?>
    <div style="text-align:center;padding:24px;font-style:italic;border:1px solid #ddd;margin-bottom:12px;">No answers found for this evaluation.</div>
    <?php endif; ?>

    <?php if ($cumOverall !== null): ?>
    <!-- RATING LEGEND: same card style as Total Score, placed right before it.
         Uses the satisfaction wording (same bands as scoreSatisfactionLabel()). -->
    <div class="avg-summary cum-total cum-legend">
        <div class="cum-total-label">Rating Legend</div>
        <div class="cum-legend-rows">
            <?php foreach ([
                ['4.50 – 5.00', 'Very Satisfied',                    '#32B98A'],
                ['3.50 – 4.49', 'Satisfied',                         '#6BCFA9'],
                ['2.50 – 3.49', 'Neither Satisfied nor Dissatisfied','#facc15'],
                ['1.50 – 2.49', 'Dissatisfied',                      '#fb923c'],
                ['1.00 – 1.49', 'Very Dissatisfied',                 '#E6788A'],
            ] as $lg): ?>
            <div class="cum-legend-row">
                <span class="cum-legend-dot" style="background:<?= $lg[2] ?>"></span>
                <span class="cum-legend-range"><?= $lg[0] ?></span>
                <span class="cum-legend-text"><?= $lg[1] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- TOTAL SCORE: sits directly under the legend -->
    <div class="avg-summary cum-total">
        <div class="cum-total-label">Total Score</div>
        <div class="cum-total-result">
            <div class="avg-score-big" style="color:<?= scoreColor($cumOverall) ?>"><?= number_format($cumOverall, 2) ?></div>
            <div class="avg-score-label" style="color:<?= scoreColor($cumOverall) ?>"><?= scoreSatisfactionLabel($cumOverall) ?></div>
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

<div class="no-print" style="display:flex;justify-content:center;margin:28px 0 8px;">
    <button class="btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
</div>

<!-- Print page margins. Kept at the very end of the document so no
     stylesheet loaded earlier (admin_ui_theme.css etc.) can override it. -->
<style>
/* Print margins.
   - Top/bottom: real @page margin, so EVERY printed page keeps its margin.
   - Left/right: padding on <body>, because the browser's print-dialog setting
     "Margins: None/Minimum" ignores @page entirely, and admin_unified_ui.css
     forces `html body{padding:0!important}` in print. `html body` below has the
     same specificity and comes later, so the side margin always survives. */
@page{margin:12mm 0;}
@media print{
  html body{width:100%!important;box-sizing:border-box!important;margin:0!important;padding:6mm 16mm!important;}
  /* let long tables continue onto the next page instead of leaving a big
     blank gap, but never split a single row or strand a section title */
  .cumulative-sheet .cat-section{break-inside:auto!important;page-break-inside:auto!important;}
  .cumulative-sheet .cat-title{break-after:avoid;page-break-after:avoid;}
  .cumulative-sheet .q-table thead{display:table-header-group;}
  .cumulative-sheet .q-table tr{break-inside:avoid;page-break-inside:avoid;}
}
</style>

</body></html>
<?php $mysqli->close(); exit; }

// ══════════════════════════════════════════════════════════════
// VIEW: EVALUATORS LIST (students who evaluated / peers who evaluated)
// ══════════════════════════════════════════════════════════════
if ($view === 'students' && $target_id) {
    $tgt = $mysqli->query("SELECT id,full_name,designation,photo,role FROM users WHERE id=$target_id LIMIT 1")->fetch_assoc();

    // For peer eval, evaluator_id in tracker = the peer who did the evaluating
    $evaluators = [];
    $eq = $mysqli->query("
        SELECT et.id as tracker_id, et.submitted_at, et.remarks,
               u.id as student_id, u.full_name, u.photo, u.role, u.education_level, u.year_level,
               (SELECT AVG(qa.answer_score) FROM questionnaire_answers qa WHERE qa.tracker_id=et.id AND " . aa_current_q_sql('qa', (int)$target_id) . ") as avg_score
        FROM evaluation_tracker et
        JOIN users u ON u.id = et.evaluator_id
        WHERE et.target_user_id = $target_id AND $evalTypeSql $yearLevelSql AND " . aa_scored_tracker_sql('et', (int)$target_id) . "
        ORDER BY et.submitted_at DESC
    ");
    if ($eq) $evaluators = $eq->fetch_all(MYSQLI_ASSOC);

    $allScores  = array_filter(array_column($evaluators,'avg_score'), fn($s) => $s !== null);
    $overallAvg = count($allScores) ? round(array_sum($allScores)/count($allScores),2) : null;

    // "Evaluation Results" rows — one row per evaluator (their name, role,
    // score, and the date they submitted), so the EA can drill into each
    // individual's full answer sheet via the View action.
    $resultRows = [];
    foreach ($evaluators as $ev) {
        $resultRows[] = [
            'id'         => 'ev-'.$ev['tracker_id'],
            'label'      => $ev['full_name'],
            'role'       => ucfirst($ev['role'] ?? $evaluatorNoun),
            'avg'        => $ev['avg_score'] !== null ? round($ev['avg_score'],2) : null,
            'date'       => $ev['submitted_at'],
            'student_id' => $ev['student_id'],
            'tracker_id' => $ev['tracker_id'],
        ];
    }

    // Print-only per-question breakdown: how every evaluator rated each
    // individual question, plus the overall average underneath. Aggregated
    // across every submission counted above so it always matches the
    // Total Evaluations / Overall Average figures on screen.
    $questionBreakdown = [];
    if (!empty($evaluators)) {
        $trackerIds = implode(',', array_map('intval', array_column($evaluators, 'tracker_id')));
        $qb = $mysqli->query("
            SELECT
                COALESCE(qa.question_id, qa.user_question_id) AS q_id,
                COALESCE(eq.question_text, uq.question_text, CONCAT('Question #', COALESCE(qa.question_id, qa.user_question_id, qa.id))) AS question_text,
                COALESCE(eq.category, uq.category, 'General') AS category,
                COUNT(qa.answer_score) AS total_responses,
                AVG(qa.answer_score) AS avg_score
            FROM questionnaire_answers qa
            LEFT JOIN evaluation_questions eq
                ON qa.question_source = 'evaluation' AND eq.id = qa.question_id
            LEFT JOIN user_questions uq
                ON qa.question_source = 'user' AND uq.id = COALESCE(qa.user_question_id, qa.question_id)
            WHERE qa.tracker_id IN ($trackerIds) AND qa.answer_score IS NOT NULL
              AND " . aa_current_q_sql('qa', (int)$target_id) . "
            GROUP BY category, q_id, question_text
            ORDER BY category, q_id
        ");
        if ($qb) $questionBreakdown = aa_merge_question_rows($qb->fetch_all(MYSQLI_ASSOC));
    }
    $questionBreakdownByCat = [];
    foreach ($questionBreakdown as $qRow) $questionBreakdownByCat[$qRow['category']][] = $qRow;

    pageHead('Evaluators List', $evalColor, $evalColorBg, $evalColorBorder);
    ?>
<style>
.target-card{background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:22px 26px;margin-bottom:24px;display:flex;align-items:center;gap:18px;flex-wrap:wrap;}
.target-avatar{width:60px;height:60px;border-radius:50%;object-fit:cover;border:2px solid var(--ec);}
.target-avatar-ph{width:60px;height:60px;border-radius:50%;background:var(--inner);border:2px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:24px;}
.target-name{font-family:'Rajdhani',sans-serif;font-size:22px;font-weight:700;color:var(--light);}
.target-desig{font-size:13px;color:var(--muted);}
.target-stats{margin-left:auto;display:flex;gap:24px;flex-wrap:wrap;}
.tstat{text-align:center;}
.tstat-val{font-family:'Rajdhani',sans-serif;font-size:28px;font-weight:700;}
.tstat-lbl{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;}
.score-legend{background:var(--inner);border:1px solid var(--border);border-radius:10px;padding:12px 16px;font-size:12px;min-width:180px;}
.score-legend-title{font-weight:700;color:var(--light);margin-bottom:6px;}
.score-legend-row{display:flex;justify-content:flex-start;text-align:left;gap:10px;color:var(--muted);padding:1px 0;}
.score-legend-row span{font-weight:700;flex:0 0 78px;}
.results-table-wrap{overflow-x:auto;}
table.results-table{width:100%;border-collapse:collapse;font-size:13px;}
table.results-table th{text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);border-bottom:1px solid var(--border);white-space:nowrap;}
table.results-table td{padding:12px;border-bottom:1px solid var(--border);vertical-align:middle;}
table.results-table tbody tr:hover{background:var(--inner);}
table.results-table tfoot td{font-weight:700;border-top:2px solid var(--border);border-bottom:none;}
.rt-rating-pill{font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;display:inline-block;}
.rt-print-btn{padding:6px 14px;border-radius:7px;border:1px solid var(--ec-bd,var(--border));background:var(--ec-bg,transparent);color:var(--ec,inherit);font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;}
.rt-print-btn:hover{opacity:.85;}
.rt-generate-btn{background:var(--accent);border-color:var(--accent);color:#fff;margin-right:6px;}
@media print{
  .score-legend{display:none!important;}
}
.section-title{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;color:var(--light);margin-bottom:16px;display:flex;align-items:center;gap:10px;}
.evaluator-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;}
.eval-card{background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:20px;cursor:pointer;transition:all .22s;text-decoration:none;display:block;}
.eval-card:hover{border-color:var(--ec);transform:translateY(-2px);box-shadow:0 10px 24px rgba(30,82,144,.13);}
.eval-card-top{display:flex;align-items:center;gap:12px;margin-bottom:14px;}
.eval-avatar{width:46px;height:46px;border-radius:50%;object-fit:cover;border:2px solid var(--border);}
.eval-avatar-ph{width:46px;height:46px;border-radius:50%;background:var(--inner);border:2px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:18px;}
.eval-name{font-size:14px;font-weight:700;color:var(--light);}
.eval-date{font-size:11px;color:var(--muted);margin-top:2px;}
.eval-score-row{display:flex;align-items:center;justify-content:space-between;}
.eval-score{font-family:'Rajdhani',sans-serif;font-size:26px;font-weight:700;}
.eval-score-lbl{font-size:12px;font-weight:600;margin-top:1px;}
.eval-bar-bg{flex:1;height:6px;background:rgba(30,82,144,.13);border-radius:3px;overflow:hidden;margin:0 12px;}
.eval-bar-fill{height:100%;border-radius:3px;}
.view-eval-btn{margin-top:12px;width:100%;padding:9px;background:var(--ec-bg);border:1px solid var(--ec-bd);border-radius:8px;color:var(--ec);font-size:12px;font-weight:700;text-align:center;}
.eval-remark{margin-top:10px;font-size:12px;color:var(--muted);font-style:italic;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.no-eval{text-align:center;padding:48px;background:var(--mid);border-radius:14px;border:1px solid var(--border);color:var(--muted);}
.no-eval i{font-size:36px;opacity:.3;display:block;margin-bottom:12px;}
/* Peer evaluator role badge */
.evaluator-role-badge{font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;background:rgba(73,104,200,.09);color:#4968C8;border:1px solid rgba(124,58,237,.25);margin-left:auto;}

/* ── PRINT: hide evaluator cards entirely, show summary only ── */
@media print{
  .no-print{display:none!important;}
  body{background:#fff!important;color:#000!important;padding:0;}
  body *{color:#000!important;}
  /* Hide the on-screen results table — names, dates, per-row actions */
  .evaluator-grid,.results-table-wrap{display:none!important;}
  /* Show the print-only anonymised summary block */
  .print-eval-summary{display:block!important;}
  .target-card{border:1px solid #ccc!important;border-radius:0!important;}
  .section-title{color:#000!important;}
}
/* Hidden on screen, shown only when printing */
.print-eval-summary{
  display:none;
  border:1px solid #ccc;
  border-radius:8px;
  padding:20px 24px;
  margin-top:16px;
  font-size:13px;
  color:#333;
  line-height:1.8;
}
.print-eval-summary h3{font-size:15px;font-weight:700;margin-bottom:10px;color:#000;}
.print-eval-summary p{margin:0;}
.print-question-table{width:100%;border-collapse:collapse;margin-top:10px;font-size:12px;}
.print-question-table th{text-align:left;padding:6px 8px;border-bottom:2px solid #999;font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:#555;}
.print-question-table td{padding:6px 8px;border-bottom:1px solid #ddd;vertical-align:top;}
.print-question-table tr.print-cat-row td{padding-top:12px;font-weight:700;color:#000;border-bottom:1px solid #000;text-transform:uppercase;font-size:10.5px;letter-spacing:.4px;}

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
</style>
<?php include __DIR__.'/analytics_dark_head.php'; ?>
<style id="generate-report-dark-fix">
/* Generate Report button: loaded after analytics_dark_head.php so the dark theme's
   link colour cannot turn the label the same blue as the button background. */
html[data-theme="dark"] table.results-table td a.rt-generate-btn,
html[data-theme="dark"] table.results-table td a.rt-generate-btn:link,
html[data-theme="dark"] table.results-table td a.rt-generate-btn:visited,
html[data-theme="dark"] table.results-table td a.rt-generate-btn:hover,
html[data-theme="dark"] table.results-table td a.rt-generate-btn:focus,
html[data-theme="dark"] table.results-table td a.rt-generate-btn *{
    color:#fff !important;
    -webkit-text-fill-color:#fff !important;
    text-decoration:none !important;
}
html[data-theme="dark"] table.results-table td a.rt-generate-btn{
    background:var(--accent,#2563EB) !important;
    border-color:var(--accent,#2563EB) !important;
}
html[data-theme="dark"] table.results-table td a.rt-generate-btn:hover{opacity:.88;}
</style>
<style id="rt-icon-actions">
/* Evaluators List > Actions: plain icons (no button chrome). */
html body table.results-table td a.rt-icon-btn,
html body table.results-table td a.rt-icon-btn:link,
html body table.results-table td a.rt-icon-btn:visited{
    display:inline-flex !important;align-items:center;justify-content:center;
    width:30px;height:30px;padding:0 !important;margin:0 2px 0 0 !important;
    background:transparent !important;border:0 !important;box-shadow:none !important;
    border-radius:8px;font-size:15px;line-height:1;text-decoration:none !important;
    color:var(--ec,var(--accent,#2563EB)) !important;-webkit-text-fill-color:currentColor !important;
    cursor:pointer;transition:opacity .15s,background-color .15s;
}
html body table.results-table td a.rt-icon-btn i{margin:0 !important;color:inherit !important}
html body table.results-table td a.rt-icon-btn:hover,
html body table.results-table td a.rt-icon-btn:focus-visible{background:var(--ec-bg,color-mix(in srgb,var(--accent,#2563EB) 12%,transparent)) !important;opacity:1}
</style>
<link rel="stylesheet" href="admin_unified_ui.css?v=<?= (int)@filemtime(__DIR__ . '/admin_unified_ui.css') ?>">
</head><body>
<script src="admin_ajax.php"></script>
<script>
// Confirm dialog helper: uses the dashboard's PBI.confirm when it is reachable
// (this page runs in an iframe, so PBI may only exist in the parent window),
// otherwise falls back to a built-in modal. Never uses the native browser confirm.
if (!window.saConfirm) (function(){
    const css = document.createElement('style');
    css.textContent = `
    .sa-modal-backdrop{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(16,39,70,.55);backdrop-filter:blur(2px);opacity:0;transition:opacity .15s ease}
    .sa-modal-backdrop.show{opacity:1}
    .sa-modal{width:100%;max-width:440px;max-height:calc(100vh - 36px);display:flex;flex-direction:column;background:var(--mid,#fff);color:var(--light,#0B1F3A);border-radius:16px;box-shadow:0 24px 60px rgba(10,30,60,.35);transform:translateY(8px) scale(.98);transition:transform .15s ease;overflow:hidden;font-family:Inter,system-ui,sans-serif}
    .sa-modal-backdrop.show .sa-modal{transform:none}
    .sa-modal-head{display:flex;gap:12px;align-items:center;padding:20px 22px 6px}
    .sa-modal-icon{flex:0 0 38px;height:38px;border-radius:50%;display:grid;place-items:center;font-size:16px;background:rgba(214,69,93,.12);color:#D6455D}
    .sa-modal.safe .sa-modal-icon{background:rgba(37,99,235,.12);color:#2563EB}
    .sa-modal-title{margin:0;font-size:16px;font-weight:800;letter-spacing:-.01em}
    .sa-modal-body{padding:8px 22px 18px;overflow:auto;white-space:pre-line;font-size:13px;line-height:1.55;color:var(--muted,#67819E)}
    .sa-modal-foot{display:flex;justify-content:flex-end;gap:10px;padding:14px 22px 20px;border-top:1px solid var(--border,#D8E5F4)}
    .sa-btn{border:1px solid var(--border,#B9CDE5);background:transparent;color:inherit;border-radius:10px;padding:10px 18px;font:700 13px Inter,system-ui,sans-serif;cursor:pointer}
    .sa-btn.ok{border-color:transparent;color:#fff;background:#D6455D}
    .sa-modal.safe .sa-btn.ok{background:#2563EB}
    .sa-btn:focus-visible{outline:3px solid rgba(37,99,235,.35);outline-offset:2px}`;
    document.head.appendChild(css);

    function builtin(message, opts){
        opts = opts || {};
        const danger = opts.danger !== false;
        let title, body;
        const text = String(message);
        if (text.indexOf('\n') > -1) { const i = text.indexOf('\n'); title = text.slice(0, i); body = text.slice(i).trim(); }
        else { const m = text.match(/^(.*?\?)\s+(.+)$/); title = m ? m[1] : text; body = m ? m[2] : ''; }
        return new Promise(resolve => {
            const back = document.createElement('div');
            back.className = 'sa-modal-backdrop';
            back.innerHTML = `
              <div class="sa-modal ${danger ? '' : 'safe'}" role="alertdialog" aria-modal="true">
                <div class="sa-modal-head"><div class="sa-modal-icon"><i class="fa-solid ${danger ? 'fa-triangle-exclamation' : 'fa-circle-question'}"></i></div><h3 class="sa-modal-title"></h3></div>
                <div class="sa-modal-body"></div>
                <div class="sa-modal-foot"><button type="button" class="sa-btn cancel"></button><button type="button" class="sa-btn ok"></button></div>
              </div>`;
            back.querySelector('.sa-modal-title').textContent = title;
            const bodyEl = back.querySelector('.sa-modal-body');
            if (body) bodyEl.textContent = body; else bodyEl.remove();
            const okBtn = back.querySelector('.ok'), noBtn = back.querySelector('.cancel');
            okBtn.textContent = opts.okLabel || 'OK';
            noBtn.textContent = opts.cancelLabel || 'Cancel';
            function close(v){ document.removeEventListener('keydown', onKey, true); back.classList.remove('show'); setTimeout(() => back.remove(), 150); resolve(v); }
            function onKey(e){
                if (e.key === 'Escape'){ e.preventDefault(); close(false); }
                else if (e.key === 'Tab'){ e.preventDefault(); (document.activeElement === okBtn ? noBtn : okBtn).focus(); }
            }
            okBtn.addEventListener('click', () => close(true));
            noBtn.addEventListener('click', () => close(false));
            back.addEventListener('mousedown', e => { if (e.target === back) close(false); });
            document.addEventListener('keydown', onKey, true);
            document.body.appendChild(back);
            requestAnimationFrame(() => { back.classList.add('show'); noBtn.focus(); });
        });
    }

    function findPBI(){
        for (const w of [window, window.parent, window.top]) {
            try { if (w && w.PBI && typeof w.PBI.confirm === 'function') return w.PBI; } catch (e) {}
        }
        return null;
    }

    window.saConfirm = async function(message, opts){
        try {
            const pbi = findPBI();
            if (pbi) return await pbi.confirm(message, opts);
        } catch (err) { console.error('PBI.confirm failed, using built-in modal', err); }
        return builtin(message, opts);
    };
})();

// Navigates to a link's href only after the custom (non-native) confirm modal is accepted.
function confirmNav(link, message, opts){
    (async () => {
        if (await window.saConfirm(message, opts)) {
            window.location.href = link.href;
        }
    })();
    return false;
}
</script>
<div class="top-bar no-print">
    <a href="?group=<?= urlencode($groupFilter) ?>&eval_type=<?= $activeEval ?>&evaluator=<?= urlencode($evaluatorFilter) ?>" class="back-btn" style="margin-bottom:0;">
        <i class="fa-solid fa-arrow-left"></i> Back to Analytics
    </a>
</div>

<div class="target-card">
    <?php if ($tgt['photo']): ?><img class="target-avatar" src="../image/<?= htmlspecialchars($tgt['photo']) ?>" alt=""/>
    <?php else: ?><div class="target-avatar-ph"><i class="fa-solid fa-user"></i></div><?php endif; ?>
    <div>
        <div class="target-name"><?= htmlspecialchars($tgt['full_name']) ?></div>
        <div class="target-desig"><?= htmlspecialchars($tgt['designation']) ?> · <?= htmlspecialchars(analytics_target_group_label($activeEval, $tgt, $mysqli, $isMultiRole, $groupFilter)) ?></div>
        <div class="target-desig">All Year Levels</div>
    </div>
    <div class="target-stats">
        <div class="tstat">
            <div class="tstat-val" style="color:var(--ec)"><?= count($evaluators) ?></div>
            <div class="tstat-lbl">Total Evaluations</div>
        </div>
        <div class="tstat">
            <div class="tstat-val" style="color:<?= scoreColor($overallAvg) ?>"><?= $overallAvg !== null ? number_format($overallAvg,2).' / 5.00' : '—' ?></div>
            <div class="tstat-lbl">Overall Average Score</div>
        </div>
    </div>
    <div class="score-legend no-print">
        <div class="score-legend-title">Rating Legend</div>
        <div class="score-legend-row"><span style="color:#32B98A">4.50 - 5.00</span> Very Satisfied</div>
        <div class="score-legend-row"><span style="color:#6BCFA9">3.50 - 4.49</span> Satisfied</div>
        <div class="score-legend-row"><span style="color:#facc15">2.50 - 3.49</span> Neither Satisfied nor Dissatisfied</div>
        <div class="score-legend-row"><span style="color:#fb923c">1.50 - 2.49</span> Dissatisfied</div>
        <div class="score-legend-row"><span style="color:#E6788A">1.00 - 1.49</span> Very Dissatisfied</div>
    </div>
</div>

<div class="section-title" style="flex-wrap:wrap;gap:14px;justify-content:space-between;">
    <span style="display:flex;align-items:center;gap:10px;">
        <i class="fa-solid fa-list-check" style="color:var(--accent)"></i>
        Evaluation Results
        <span style="font-size:13px;font-weight:400;color:var(--muted)">(<?= count($resultRows) ?>)</span>
    </span>
    <span class="no-print" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
    <?php if (!empty($resultRows)): ?>
    <!-- One Generate Report button for the whole list: opens the cumulative
         report (average rating per question across ALL evaluators + overall).
         Carries the current year-level filter so the report matches the list. -->
    <a class="rt-generate-top" href="?view=sheet&target_id=<?= $target_id ?>&group=<?= urlencode($groupFilter) ?>&eval_type=<?= $activeEval ?>&evaluator=<?= urlencode($evaluatorFilter) ?>&year_level=<?= urlencode($yearLevel) ?>&report=1">
        <i class="fa-solid fa-file-lines"></i> Generate Report
    </a>
    <style>
    html body a.rt-generate-top,html body a.rt-generate-top:link,html body a.rt-generate-top:visited{
        display:inline-flex;align-items:center;gap:8px;padding:9px 18px;border-radius:8px;
        background:var(--accent,#2563EB) !important;color:#fff !important;-webkit-text-fill-color:#fff !important;
        border:1px solid var(--accent,#2563EB);font-size:13px;font-weight:700;text-decoration:none !important;
        font-family:'Inter',sans-serif;}
    html body a.rt-generate-top:hover{opacity:.88;}
    html body a.rt-generate-top i{color:#fff !important;}
    </style>
    <?php endif; ?>
    <?php if ($activeEval === 'student'): ?>
    <span style="display:flex;align-items:center;gap:8px;">
        <label for="yearLevelSelect" style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;">Year Level</label>
        <select id="yearLevelSelect" onchange="applyYearLevelFilter(this.value)" style="padding:8px 14px;border-radius:8px;border:1px solid var(--border);font-size:13px;font-weight:600;background:var(--mid);color:var(--light);">
            <option value="All" <?= $yearLevel==='All' ? 'selected' : '' ?>>All Year Levels</option>
            <optgroup label="Basic Education">
                <?php foreach ($basicEdYears as $g): ?>
                <option value="<?= htmlspecialchars($g) ?>" <?= $yearLevel===$g ? 'selected' : '' ?>><?= htmlspecialchars($g) ?></option>
                <?php endforeach; ?>
            </optgroup>
            <optgroup label="Higher Education">
                <?php foreach ($higherEdYears as $y): ?>
                <option value="<?= htmlspecialchars($y) ?>" <?= $yearLevel===$y ? 'selected' : '' ?>><?= htmlspecialchars($y) ?></option>
                <?php endforeach; ?>
            </optgroup>
        </select>
    </span>
    <script>
    function applyYearLevelFilter(val) {
        const url = new URL(window.location.href);
        if (val === 'All') { url.searchParams.delete('year_level'); }
        else { url.searchParams.set('year_level', val); }
        window.location.href = url.toString();
    }
    </script>
    <?php endif; ?>
    </span>
</div>

<?php if (empty($resultRows)): ?>
<div class="no-eval">
    <i class="fa-solid fa-users-slash"></i>
    <p>No <?= $evaluatorNounP ?> have evaluated this person yet.</p>
</div>
<?php else: ?>

<div class="results-table-wrap">
<table class="results-table" id="resultsTable">
    <thead>
        <tr>
            <th>No.</th>
            <th>Evaluator</th>
            <th>Role</th>
            <th>Average Score</th>
            <th>Rating</th>
            <th>Date Evaluated</th>
            <th class="no-print">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($resultRows as $i => $row): $rc = scoreColor($row['avg']); ?>
        <tr data-row-id="<?= htmlspecialchars($row['id']) ?>">
            <td><?= $i+1 ?></td>
            <td><?= htmlspecialchars($row['label']) ?></td>
            <td><?= htmlspecialchars($row['role']) ?></td>
            <td style="font-weight:700;color:<?= $rc ?>"><?= $row['avg'] !== null ? number_format($row['avg'],2) : '—' ?></td>
            <td><span class="rt-rating-pill" style="background:<?= $rc ?>1A;color:<?= $rc ?>"><?= scoreSatisfactionLabel($row['avg']) ?></span></td>
            <td><?= !empty($row['date']) ? date('M d, Y', strtotime($row['date'])) : '—' ?></td>
            <td class="no-print" style="white-space:nowrap;">
                <a class="rt-icon-btn rt-icon-view" title="View" aria-label="View" href="?view=sheet&target_id=<?= $target_id ?>&student_id=<?= $row['student_id'] ?>&tracker_id=<?= $row['tracker_id'] ?>&group=<?= urlencode($groupFilter) ?>&eval_type=<?= $activeEval ?>&evaluator=<?= urlencode($evaluatorFilter) ?>"><i class="fa-solid fa-eye"></i></a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<!-- Print-only: anonymised summary, no names or photos (Peer/School Head
     rows show real evaluator names on screen above, per the master spec's
     "EA retains full access to evaluator identity" rule — this summary is
     the printed hand-out version, kept anonymous like before). -->
<div class="print-eval-summary">
    <h3>Evaluation Summary — <?= htmlspecialchars($tgt['full_name']) ?></h3>
    <p><strong>Evaluation type:</strong> <?= $evalLabel ?></p>

    <?php if (!empty($questionBreakdownByCat)): ?>
    <table class="print-question-table">
        <thead>
            <tr><th>Question</th><th>Total Ratings</th><th>Average Score</th><th>Rating</th></tr>
        </thead>
        <tbody>
        <?php foreach ($questionBreakdownByCat as $cat => $qRows): ?>
            <tr class="print-cat-row"><td colspan="4"><?= htmlspecialchars($cat) ?></td></tr>
            <?php foreach ($qRows as $qRow): $qAvg = $qRow['avg_score'] !== null ? round($qRow['avg_score'],2) : null; ?>
            <tr>
                <td><?= htmlspecialchars($qRow['question_text']) ?></td>
                <td><?= (int)$qRow['total_responses'] ?></td>
                <td><?= $qAvg !== null ? number_format($qAvg,2) : '—' ?></td>
                <td><?= $qAvg !== null ? scoreLabel($qAvg) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <p style="margin-top:14px;"><strong>Total evaluations received:</strong> <?= count($evaluators) ?></p>
    <p><strong>Total average score:</strong> <?= $overallAvg !== null ? number_format($overallAvg,2).' / 5.00 ('.scoreLabel($overallAvg).')' : '—' ?></p>
    <p style="margin-top:12px;font-size:11px;color:#888;font-style:italic;">
        Individual <?= $evaluatorNoun ?> identities are not shown in this report to protect their privacy and encourage honest feedback.
    </p>
</div>

<?php endif; ?>
</body></html>
<?php $mysqli->close(); exit; }

// ══════════════════════════════════════════════════════════════
// VIEW: ARCHIVED PERSONNEL
// ══════════════════════════════════════════════════════════════
if ($view === 'archived') {
    // Peer tab: don't filter Teacher/Staff by raw role in SQL — the canonical
    // grouping (ec_resolve_peer_group, same predicate as faculty_dashboard.php
    // / admin/questionnaire.php) needs a per-person teaching_assignments /
    // user_year_levels lookup that isn't a static column, so pull every
    // faculty/staff/teacher candidate here and apply the Teacher/Staff pill
    // in PHP below instead.
    $whereRoleArc = match ($activeEval) {
        'schoolhead' => "u.role IN ('teacher','staff')",
        'peer'       => $isMultiRole ? $mrFilterRoleSql : "u.role IN ('teacher','staff','faculty','principal','dean')",
        'student'    => $isMultiRole ? $mrFilterRoleSql : "u.role IN ('teacher','staff','principal','dean')",
        'ea'         => "u.role IN ('teacher','staff','principal','dean','superadmin')",
        'staff'      => "u.role IN ('principal','dean','superadmin')",
        default      => "u.role IN ('teacher','staff')"
    };
    $archived = [];
    $res = $mysqli->query("
        SELECT u.id,u.full_name,u.designation,u.photo,u.role,u.secondary_role,aa.archived_at,
               COUNT(DISTINCT et.id) AS total_responses,
               AVG(qa.answer_score)  AS avg_score
        FROM analytics_archive aa
        JOIN users u ON u.id=aa.target_user_id
        LEFT JOIN evaluation_tracker et ON et.target_user_id=u.id AND $evalTypeSql AND " . aa_scored_tracker_sql('et', 'u.id') . "
        LEFT JOIN questionnaire_answers qa ON qa.tracker_id=et.id AND " . aa_current_q_sql('qa', 'u.id') . "
        WHERE $whereRoleArc
        GROUP BY u.id ORDER BY aa.archived_at DESC
    ");
    if ($res) $archived = $res->fetch_all(MYSQLI_ASSOC);

    if ($activeEval === 'peer') {
        // Same canonical rule as the dashboard roster: null (not a valid
        // Teacher, Staff, or School Head peer target) is dropped entirely;
        // the pill then narrows to just 'teacher', 'staff', 'dean', or 'principal'.
        $archived = array_values(array_filter($archived, function ($p) use ($groupFilter, $mysqli) {
            $g = ec_resolve_peer_group_full($p, $mysqli);
            if ($g === null) return false;
            if ($groupFilter === 'Teacher') return $g === 'teacher';
            if ($groupFilter === 'Staff') return $g === 'staff';
            if ($groupFilter === 'Dean') return $g === 'school_head' && strtolower(trim((string)($p['role'] ?? ''))) === 'dean';
            if ($groupFilter === 'Principal') return $g === 'school_head' && strtolower(trim((string)($p['role'] ?? ''))) === 'principal';
            return true;
        }));
    }

    if ($activeEval === 'student' && !$isMultiRole) {
        // Student Evaluation uses the same canonical Faculty/Teaching Staff vs
        // Non-Teaching Staff vs School Head classification as questionnaire.php.
        $archived = array_values(array_filter($archived, function ($p) use ($groupFilter, $mysqli) {
            $g = ec_resolve_student_group_full($p, $mysqli);
            if ($g === null) return false;
            if ($groupFilter === 'Teacher') return $g === 'teacher';
            if ($groupFilter === 'Staff') return $g === 'staff';
            if ($groupFilter === 'Dean') return $g === 'school_head' && strtolower(trim((string)($p['role'] ?? ''))) === 'dean';
            if ($groupFilter === 'Principal') return $g === 'school_head' && strtolower(trim((string)($p['role'] ?? ''))) === 'principal';
            return true;
        }));
    }

    if ($activeEval === 'schoolhead') {
        // Same has_teacher/EA rule as the main roster below: null (not a
        // valid Faculty or EA target) is dropped entirely; the pill then
        // narrows to just 'faculty', 'staff', or 'ea'.
        $archived = array_values(array_filter($archived, function ($p) use ($groupFilter, $mysqli) {
            $g = ec_resolve_schoolhead_target_group($p, $mysqli);
            if ($g === null) return false;
            if ($groupFilter === 'Faculty') return $g === 'faculty';
            if ($groupFilter === 'Staff') return $g === 'staff';
            if ($groupFilter === 'EA') return $g === 'ea';
            return true;
        }));
    }

    if ($activeEval === 'ea') {
        $archived = array_values(array_filter($archived, function ($p) use ($groupFilter, $mysqli) {
            $g = ec_resolve_ea_group($p, $mysqli);
            if ($g === null) return false;
            return match ($groupFilter) {
                'Staff' => $g === 'Staff',
                'Dean' => $g === 'Dean',
                'Principal' => $g === 'Principal',
                default => true,
            };
        }));
    }

    if ($activeEval === 'staff') {
        $archived = array_values(array_filter($archived, function ($p) use ($groupFilter) {
            $role = strtolower(trim((string)($p['role'] ?? '')));
            return match ($groupFilter) {
                'Dean' => $role === 'dean',
                'Principal' => $role === 'principal',
                'EA' => $role === 'superadmin',
                default => in_array($role, ['principal','dean','superadmin'], true),
            };
        }));
    }


    pageHead('Archived Personnel', $evalColor, $evalColorBg, $evalColorBorder);
    ?>
<style>
.page-title{font-family:'Rajdhani',sans-serif;font-size:28px;font-weight:700;color:var(--light);margin-bottom:3px;}
.page-sub{font-size:13px;color:var(--muted);margin-bottom:24px;}
.people-list{display:flex;flex-direction:column;gap:14px;}
.person-row{background:var(--mid);border:1px solid var(--border);border-radius:14px;overflow:hidden;opacity:.85;}
.person-header{display:flex;align-items:center;gap:16px;padding:18px 22px;}
.person-photo{width:52px;height:52px;border-radius:50%;object-fit:cover;border:2px solid var(--border);filter:grayscale(.4);}
.person-photo-ph{width:52px;height:52px;border-radius:50%;background:var(--inner);border:2px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:20px;}
.person-name{font-size:15px;font-weight:700;color:var(--light);margin-bottom:4px;}
.person-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:12px;color:var(--muted);}
.archived-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 11px;border-radius:20px;font-size:11px;font-weight:700;background:rgba(160,179,198,.15);color:var(--muted);border:1px solid var(--border);}
.btn-restore{background:var(--teal);color:#fff;border:none;padding:9px 18px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:background .2s;white-space:nowrap;}
.btn-restore:hover{background:#14b89f;}
.no-archived{text-align:center;padding:48px;background:var(--mid);border-radius:14px;border:1px solid var(--border);color:var(--muted);}
.no-archived i{font-size:36px;opacity:.3;display:block;margin-bottom:14px;}

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
</style>
<?php include __DIR__.'/analytics_dark_head.php'; ?>
<link rel="stylesheet" href="admin_unified_ui.css?v=<?= (int)@filemtime(__DIR__ . '/admin_unified_ui.css') ?>">
</head><body>
<script src="admin_ajax.php"></script>
<script>
// Confirm dialog helper: uses the dashboard's PBI.confirm when it is reachable
// (this page runs in an iframe, so PBI may only exist in the parent window),
// otherwise falls back to a built-in modal. Never uses the native browser confirm.
if (!window.saConfirm) (function(){
    const css = document.createElement('style');
    css.textContent = `
    .sa-modal-backdrop{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(16,39,70,.55);backdrop-filter:blur(2px);opacity:0;transition:opacity .15s ease}
    .sa-modal-backdrop.show{opacity:1}
    .sa-modal{width:100%;max-width:440px;max-height:calc(100vh - 36px);display:flex;flex-direction:column;background:var(--mid,#fff);color:var(--light,#0B1F3A);border-radius:16px;box-shadow:0 24px 60px rgba(10,30,60,.35);transform:translateY(8px) scale(.98);transition:transform .15s ease;overflow:hidden;font-family:Inter,system-ui,sans-serif}
    .sa-modal-backdrop.show .sa-modal{transform:none}
    .sa-modal-head{display:flex;gap:12px;align-items:center;padding:20px 22px 6px}
    .sa-modal-icon{flex:0 0 38px;height:38px;border-radius:50%;display:grid;place-items:center;font-size:16px;background:rgba(214,69,93,.12);color:#D6455D}
    .sa-modal.safe .sa-modal-icon{background:rgba(37,99,235,.12);color:#2563EB}
    .sa-modal-title{margin:0;font-size:16px;font-weight:800;letter-spacing:-.01em}
    .sa-modal-body{padding:8px 22px 18px;overflow:auto;white-space:pre-line;font-size:13px;line-height:1.55;color:var(--muted,#67819E)}
    .sa-modal-foot{display:flex;justify-content:flex-end;gap:10px;padding:14px 22px 20px;border-top:1px solid var(--border,#D8E5F4)}
    .sa-btn{border:1px solid var(--border,#B9CDE5);background:transparent;color:inherit;border-radius:10px;padding:10px 18px;font:700 13px Inter,system-ui,sans-serif;cursor:pointer}
    .sa-btn.ok{border-color:transparent;color:#fff;background:#D6455D}
    .sa-modal.safe .sa-btn.ok{background:#2563EB}
    .sa-btn:focus-visible{outline:3px solid rgba(37,99,235,.35);outline-offset:2px}`;
    document.head.appendChild(css);

    function builtin(message, opts){
        opts = opts || {};
        const danger = opts.danger !== false;
        let title, body;
        const text = String(message);
        if (text.indexOf('\n') > -1) { const i = text.indexOf('\n'); title = text.slice(0, i); body = text.slice(i).trim(); }
        else { const m = text.match(/^(.*?\?)\s+(.+)$/); title = m ? m[1] : text; body = m ? m[2] : ''; }
        return new Promise(resolve => {
            const back = document.createElement('div');
            back.className = 'sa-modal-backdrop';
            back.innerHTML = `
              <div class="sa-modal ${danger ? '' : 'safe'}" role="alertdialog" aria-modal="true">
                <div class="sa-modal-head"><div class="sa-modal-icon"><i class="fa-solid ${danger ? 'fa-triangle-exclamation' : 'fa-circle-question'}"></i></div><h3 class="sa-modal-title"></h3></div>
                <div class="sa-modal-body"></div>
                <div class="sa-modal-foot"><button type="button" class="sa-btn cancel"></button><button type="button" class="sa-btn ok"></button></div>
              </div>`;
            back.querySelector('.sa-modal-title').textContent = title;
            const bodyEl = back.querySelector('.sa-modal-body');
            if (body) bodyEl.textContent = body; else bodyEl.remove();
            const okBtn = back.querySelector('.ok'), noBtn = back.querySelector('.cancel');
            okBtn.textContent = opts.okLabel || 'OK';
            noBtn.textContent = opts.cancelLabel || 'Cancel';
            function close(v){ document.removeEventListener('keydown', onKey, true); back.classList.remove('show'); setTimeout(() => back.remove(), 150); resolve(v); }
            function onKey(e){
                if (e.key === 'Escape'){ e.preventDefault(); close(false); }
                else if (e.key === 'Tab'){ e.preventDefault(); (document.activeElement === okBtn ? noBtn : okBtn).focus(); }
            }
            okBtn.addEventListener('click', () => close(true));
            noBtn.addEventListener('click', () => close(false));
            back.addEventListener('mousedown', e => { if (e.target === back) close(false); });
            document.addEventListener('keydown', onKey, true);
            document.body.appendChild(back);
            requestAnimationFrame(() => { back.classList.add('show'); noBtn.focus(); });
        });
    }

    function findPBI(){
        for (const w of [window, window.parent, window.top]) {
            try { if (w && w.PBI && typeof w.PBI.confirm === 'function') return w.PBI; } catch (e) {}
        }
        return null;
    }

    window.saConfirm = async function(message, opts){
        try {
            const pbi = findPBI();
            if (pbi) return await pbi.confirm(message, opts);
        } catch (err) { console.error('PBI.confirm failed, using built-in modal', err); }
        return builtin(message, opts);
    };
})();

// Navigates to a link's href only after the custom (non-native) confirm modal is accepted.
function confirmNav(link, message, opts){
    (async () => {
        if (await window.saConfirm(message, opts)) {
            window.location.href = link.href;
        }
    })();
    return false;
}
</script>
<?php if ($toast): ?><div class="toast"><i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($toast) ?></div><?php endif; ?>
<a href="?group=<?= urlencode($groupFilter) ?>&eval_type=<?= $activeEval ?>&evaluator=<?= urlencode($evaluatorFilter) ?>" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back to Analytics</a>
<div class="page-title">Archived Personnel</div>
<div class="page-sub">Hidden from the main list. Evaluation data is preserved and can be restored anytime.</div>
<?php if (empty($archived)): ?>
<div class="no-archived"><i class="fa-solid fa-box-archive"></i><p>No archived personnel.</p></div>
<?php else: ?>
<div class="people-list">
<?php foreach ($archived as $p):
    $avg = $p['avg_score'] !== null ? round($p['avg_score'],2) : null;
?>
<div class="person-row">
    <div class="person-header">
        <?php if($p['photo']): ?><img class="person-photo" src="../image/<?= htmlspecialchars($p['photo']) ?>" alt=""/>
        <?php else: ?><div class="person-photo-ph"><i class="fa-solid fa-user"></i></div><?php endif; ?>
        <div style="flex:1;">
            <div class="person-name"><?= htmlspecialchars($p['full_name']) ?></div>
            <div class="person-meta">
                <span class="archived-badge"><i class="fa-solid fa-box-archive"></i> Archived <?= date('M d, Y', strtotime($p['archived_at'])) ?></span>
                <span><?= ($activeEval==='schoolhead' ? schoolheadTargetLabel($p['role']) : ($activeEval==='student' && in_array($groupFilter,['Dean','Principal'],true) ? ($p['role']==='principal'?'Principal':'Dean') : ($activeEval==='student' ? ($p['role']==='teacher'?'Faculty':'Staff') : ($p['role']==='teacher'?'Faculty':'Staff')))) ?> · <?= htmlspecialchars($p['designation']) ?></span>
                <span><?= $p['total_responses'] ?> evaluation<?= $p['total_responses']!=1?'s':'' ?></span>
                <?php if ($avg !== null): ?><span style="color:<?= scoreColor($avg) ?>;font-weight:700;"><?= number_format($avg,2) ?> avg</span><?php endif; ?>
            </div>
        </div>
        <a class="btn-restore" href="?restore_id=<?= $p['id'] ?>&group=<?= urlencode($groupFilter) ?>&eval_type=<?= $activeEval ?>&evaluator=<?= urlencode($evaluatorFilter) ?>"
           onclick="return confirmNav(this, 'Restore <?= htmlspecialchars(addslashes($p['full_name'])) ?> to the analytics list?', {okLabel:'Restore', danger:false})">
            <i class="fa-solid fa-rotate-left"></i> Restore
        </a>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php $mysqli->close(); ?>
</body></html>
<?php exit; }

// ══════════════════════════════════════════════════════════════
// VIEW: MAIN LIST
// ══════════════════════════════════════════════════════════════
// Peer tab: same reasoning as the archived view above — pull every
// faculty/staff/teacher candidate and let ec_resolve_peer_group() (below)
// decide Teacher vs Staff vs excluded, instead of filtering by raw role.
$whereRole = match ($activeEval) {
    'schoolhead' => "u.role IN ('teacher','staff')",
    'peer'       => $isMultiRole ? $mrFilterRoleSql : "u.role IN ('teacher','staff','faculty','principal','dean')",
    'student'    => $isMultiRole ? $mrFilterRoleSql : "u.role IN ('teacher','staff','principal','dean')",
    'ea'         => "u.role IN ('teacher','staff','principal','dean','superadmin')",
    'staff'      => "u.role IN ('principal','dean','superadmin')",
    default      => "u.role IN ('teacher','staff')"
};

$people = [];
// NOTE: analytics_archive has no eval_type/context column — it only keys on
// target_user_id, so "archived" is global across every report tab. Excluding
// archived people outright (old WHERE aa.id IS NULL) meant a person archived
// once, anywhere, stayed permanently invisible even after brand-new
// submissions came in under a completely different evaluation type. Instead,
// only keep someone hidden if nothing has been submitted for them SINCE they
// were archived — a fresh submission after the archive date makes them
// reappear. Multi-Role keeps its prior behavior of ignoring archive status
// entirely.
$res = $mysqli->query("
    SELECT u.id, u.full_name, u.designation, u.photo, u.role, u.secondary_role,
           MAX(aa.archived_at)   AS archived_at,
           COUNT(DISTINCT et.id) AS total_responses,
           AVG(qa.answer_score)  AS avg_score,
           MAX(et.submitted_at)  AS last_evaluated
    FROM users u
    JOIN evaluation_tracker et ON et.target_user_id=u.id AND $evalTypeSql AND " . aa_scored_tracker_sql('et', 'u.id') . "
    LEFT JOIN questionnaire_answers qa ON qa.tracker_id=et.id AND " . aa_current_q_sql('qa', 'u.id') . "
    LEFT JOIN analytics_archive aa ON aa.target_user_id=u.id
    WHERE $whereRole AND u.is_active=1
    GROUP BY u.id
    HAVING (MAX(aa.archived_at) IS NULL OR " . ($isMultiRole ? '1=1' : 'MAX(et.submitted_at) > MAX(aa.archived_at)') . ")
    ORDER BY avg_score DESC, u.full_name ASC
");
if ($res) $people = $res->fetch_all(MYSQLI_ASSOC);

if ($activeEval === 'student' && !$isMultiRole) {
    $people = array_values(array_filter($people, function ($p) use ($groupFilter, $mysqli) {
        $g = ec_resolve_student_group_full($p, $mysqli);
        $role = strtolower(trim((string)($p['role'] ?? '')));
        if ($g === null) return false;
        if ($groupFilter === 'Teacher') return $g === 'teacher';
        if ($groupFilter === 'Staff') return $g === 'staff';
        if ($groupFilter === 'Dean') return $g === 'school_head' && $role === 'dean';
        if ($groupFilter === 'Principal') return $g === 'school_head' && $role === 'principal';
        return true;
    }));
}

if ($activeEval === 'peer') {
    $people = array_values(array_filter($people, function ($p) use ($groupFilter, $mysqli) {
        $g = ec_resolve_peer_group_full($p, $mysqli);
        $role = strtolower(trim((string)($p['role'] ?? '')));
        if ($g === null) return false;
        if ($groupFilter === 'Teacher') return $g === 'teacher';
        if ($groupFilter === 'Staff') return $g === 'staff';
        if ($groupFilter === 'Dean') return $g === 'school_head' && $role === 'dean';
        if ($groupFilter === 'Principal') return $g === 'school_head' && $role === 'principal';
        return true;
    }));
}

if ($activeEval === 'schoolhead') {
    $people = array_values(array_filter($people, function ($p) use ($groupFilter, $mysqli) {
        $g = ec_resolve_schoolhead_target_group($p, $mysqli);
        if ($g === null) return false;
        if ($groupFilter === 'Faculty') return $g === 'faculty';
        if ($groupFilter === 'Staff') return $g === 'staff';
        if ($groupFilter === 'EA') return $g === 'ea';
        return true;
    }));
}

if ($activeEval === 'ea') {
    // Build the global EA report counts before applying the selected tab.
    // This keeps All / Staff / Dean / Principal as true evaluated-personnel
    // counts rather than mixing roster counts with submission counts.
    $eaTargetRoleCounts = ['Staff'=>0,'Dean'=>0,'Principal'=>0];
    foreach ($people as $eaPerson) {
        $g = ec_resolve_ea_group($eaPerson, $mysqli);
        if ($g !== null && isset($eaTargetRoleCounts[$g])) {
            $eaTargetRoleCounts[$g]++;
        }
    }
    $totalFacStaff = array_sum($eaTargetRoleCounts);

    $people = array_values(array_filter($people, function ($p) use ($groupFilter, $mysqli) {
        $g = ec_resolve_ea_group($p, $mysqli);
        if ($g === null) return false;
        return match ($groupFilter) {
            'Staff' => $g === 'Staff',
            'Dean' => $g === 'Dean',
            'Principal' => $g === 'Principal',
            default => true,
        };
    }));
}

if ($activeEval === 'staff') {
    $people = array_values(array_filter($people, function ($p) use ($groupFilter) {
        $role = strtolower(trim((string)($p['role'] ?? '')));
        return match ($groupFilter) {
            'Dean' => $role === 'dean',
            'Principal' => $role === 'principal',
            'EA' => $role === 'superadmin',
            default => in_array($role, ['principal','dean','superadmin'], true),
        };
    }));
}

$top4 = array_slice($people, 0, 4);
$low4 = array_slice(array_reverse($people), 0, 4);

$totalResponses = array_sum(array_column($people,'total_responses'));
$scores         = array_filter(array_column($people,'avg_score'), fn($s) => $s !== null);
$overallAvg     = count($scores) ? round(array_sum($scores)/count($scores),2) : null;

$totalStudents = $mysqli->query("SELECT COUNT(*) as c FROM users WHERE role='student'")->fetch_assoc()['c'] ?? 0;
$facCount = 0;
$staffCount = 0;
$schoolHeadCount = 0;
$studentDeanCount = 0;
$studentPrincipalCount = 0;
$studentMultiRoleFacCount = 0;
$studentMultiRoleStaffCount = 0;
$studentMultiRoleTargetCount = 0;

// Student Evaluation target counts must always be initialized.
// These values drive the All / Faculty / Staff / Dean-Principal tabs.
$allTargetIds = [];
$studentCandidates = $mysqli->query("SELECT id, full_name, designation, photo, role, secondary_role, sector, source
    FROM users
    WHERE role IN ('teacher','staff','principal','dean')
      AND is_active=1
      AND (role IN ('teacher','staff') OR account_status='approved')");
if ($studentCandidates) {
    while ($u = $studentCandidates->fetch_assoc()) {
        $g = ec_resolve_student_group_full($u, $mysqli);
        if ($g === 'teacher') {
            $facCount++;
            $allTargetIds[(int)$u['id']] = true;
        } elseif ($g === 'staff') {
            $staffCount++;
            $allTargetIds[(int)$u['id']] = true;
        } elseif ($g === 'school_head') {
            $schoolHeadCount++;
            if (($u['role'] ?? '') === 'dean') $studentDeanCount++;
            if (($u['role'] ?? '') === 'principal') $studentPrincipalCount++;
            $allTargetIds[(int)$u['id']] = true;
        }
    }
    $studentCandidates->free();
}
$totalFacStaff = count($allTargetIds);

if ($activeEval === 'ea') {
    // Executive Assistant Evaluation follows the Questionnaire scopes exactly:
    // Staff, Dean, and Principal.  These report badges count only personnel
    // who actually have an EA-evaluation submission in the current result set,
    // so the tabs and the "Evaluated Personnel" table always agree.
    $totalFacStaff = 0;
    $facCount = 0;
    $staffCount = 0;
    $eaTargetRoleCounts = ['Staff'=>0,'Dean'=>0,'Principal'=>0];
}
if ($activeEval === 'staff') {
    $staffEvalTargetCount = (int)($mysqli->query("SELECT COUNT(DISTINCT et.target_user_id) AS c FROM evaluation_tracker et WHERE $evalTypePlainSql")->fetch_assoc()['c'] ?? 0);
    $totalFacStaff = $staffEvalTargetCount;
    $facCount = $staffCount = 0;
    $staffEvalTargetCounts = ['Dean'=>0,'Principal'=>0,'EA'=>0];
    $staffTargets = $mysqli->query("SELECT id, role FROM users WHERE role IN ('principal','dean','superadmin') AND is_active=1 AND account_status='approved'");
    if ($staffTargets) {
        while ($u = $staffTargets->fetch_assoc()) {
            if ($u['role'] === 'principal') $staffEvalTargetCounts['Principal']++;
            elseif ($u['role'] === 'dean') $staffEvalTargetCounts['Dean']++;
            elseif ($u['role'] === 'superadmin') $staffEvalTargetCounts['EA']++;
        }
        $staffTargets->free();
    }
}
if ($activeEval === 'schoolhead') {
    // Dean / Principal Evaluation reports the PERSONNEL BEING EVALUATED.
    // Count each evaluated person by the same Questionnaire-aligned grouping
    // used by ec_resolve_schoolhead_target_group():
    //   Faculty = Teachers + teaching Staff
    //   Staff   = non-teaching Staff
    //   EA      = Executive Assistant
    //
    // $people has already been restricted to the selected evaluator (All /
    // Principal / Dean), so these counts automatically stay consistent with
    // the current evaluator filter and with the rows shown in the table.
    $schoolheadGroupCounts = [
        'Faculty' => 0,
        'Staff' => 0,
        'Executive Assistant' => 0,
    ];
    foreach ($people as $schoolheadPerson) {
        $g = ec_resolve_schoolhead_target_group($schoolheadPerson, $mysqli);
        if ($g === 'faculty') {
            $schoolheadGroupCounts['Faculty']++;
        } elseif ($g === 'staff') {
            $schoolheadGroupCounts['Staff']++;
        } elseif ($g === 'ea') {
            $schoolheadGroupCounts['Executive Assistant']++;
        }
    }
    $totalFacStaff = array_sum($schoolheadGroupCounts);
    $facCount = $schoolheadGroupCounts['Faculty'];
    $staffCount = $schoolheadGroupCounts['Staff'];
}
if ($activeEval === 'peer') {
    // Canonical counts — use the exact same resolved grouping as the
    // Peer-to-Peer roster filter. This is deliberately NOT a raw role count:
    // Staff with an existing year-level/teaching assignment are Faculty
    // (Teaching Staff), while non-teaching Staff remain Staff.
    // Faculty-role accounts are also resolved directly to Faculty.
    // listed. A Staff account that actually teaches (has a
    // teaching_assignments row, a Teacher sector, or a secondary Teacher
    // role) is counted under Faculty, not Staff. Principal/Dean are counted
    // separately as "School Head" — Peer-to-Peer's third context, distinct
    // from the $schoolHeadCount used by the top-level School Head
    // Evaluation tab below.
    $facCount = 0;
    $staffCount = 0;
    $peerSchoolHeadCount = 0;
    $peerDeanCount = 0;
    $peerPrincipalCount = 0;
    $peerCandidates = $mysqli->query("SELECT id, role, sector, source
        FROM users
        WHERE role IN ('teacher','staff','faculty','principal','dean')
          AND is_active=1
          AND (role NOT IN ('principal','dean') OR account_status='approved')");
    if ($peerCandidates) {
        while ($u = $peerCandidates->fetch_assoc()) {
            $g = ec_resolve_peer_group_full($u, $mysqli);
            if ($g === 'teacher') $facCount++;
            elseif ($g === 'staff') $staffCount++;
            elseif ($g === 'school_head') {
                $peerSchoolHeadCount++;
                if (($u['role'] ?? '') === 'dean') $peerDeanCount++;
                if (($u['role'] ?? '') === 'principal') $peerPrincipalCount++;
            }
        }
        $peerCandidates->free();
    }
    $totalFacStaff = $facCount + $staffCount + $peerSchoolHeadCount;
}
if ($isMultiRole) {
    // Reuse the questionnaire-aligned Multi-Role roster computed above.
    $facCount = $studentMultiRoleFacCount;
    $staffCount = $studentMultiRoleStaffCount;
    $totalFacStaff = $studentMultiRoleTargetCount;
}

$archivedCount = $mysqli->query("SELECT COUNT(*) as c FROM analytics_archive")->fetch_assoc()['c'] ?? 0;

// Count evaluations per eval_type for tab badges
$studentEvalCount = $mysqli->query("SELECT COUNT(DISTINCT et.id) as c
    FROM evaluation_tracker et
    JOIN users target ON target.id=et.target_user_id
    WHERE $studentTargetPlainSql")->fetch_assoc()['c'] ?? 0;
$multiRoleEvalCount = $mysqli->query("SELECT COUNT(DISTINCT et.id) AS c
    FROM evaluation_tracker et
    WHERE et.eval_type='student' AND (
        et.evaluation_context='multi_role'
        OR EXISTS (
            SELECT 1
            FROM questionnaire_answers qam
            JOIN user_questions uqm ON uqm.id = qam.user_question_id
            WHERE qam.tracker_id = et.id
              AND uqm.target_type = 'Multi-Role'
              AND uqm.eval_type = 'student'
        )
    )")->fetch_assoc()['c'] ?? 0;
$peerEvalCount    = $mysqli->query("SELECT COUNT(DISTINCT id) as c FROM evaluation_tracker WHERE eval_type IN ($peerTypesSql)")->fetch_assoc()['c'] ?? 0;
// Same evaluator/target direction as $evalTypeSql above (Principal/Dean are
// the EVALUATORS, Faculty/EA are the targets) — this badge previously
// filtered by target.role IN ('principal','dean'), the old reversed model,
// which meant it could never match a genuine post-fix submission and would
// silently show 0 even when School Head Evaluation had real data.
//
// Built from both roles directly (not $schoolheadEvaluatorSql) so this stays
// an unfiltered grand total, like $studentEvalCount/$peerEvalCount/
// $multiRoleEvalCount above — it must not shrink just because the
// Principal/Dean evaluator pill happens to be narrowed while on that tab.
$schoolheadEvaluatorAnySql = "et.evaluator_id IN (SELECT id FROM users WHERE role IN ('principal','dean') AND is_active=1)";
$schoolHeadEvalCount = $mysqli->query("SELECT COUNT(DISTINCT et.id) AS c
    FROM evaluation_tracker et
    WHERE et.eval_type IN $schoolheadTypeSql
      AND $schoolheadEvaluatorAnySql
      AND $schoolheadTargetSql
")->fetch_assoc()['c'] ?? 0;

// Per-evaluator counts, used to give Principal and Dean their own tabs
// (query the target rule directly rather than $schoolheadTargetSql/
// $schoolheadEvaluatorSql, which are built off the live $evaluatorFilter and
// would otherwise collapse to whichever evaluator is currently selected).
$principalEvalCount = $mysqli->query("SELECT COUNT(DISTINCT et.id) AS c
    FROM evaluation_tracker et
    WHERE et.eval_type IN $schoolheadTypeSql
      AND et.evaluator_id IN (SELECT id FROM users WHERE role='principal' AND is_active=1)
      AND $schoolheadTargetSql
")->fetch_assoc()['c'] ?? 0;
$deanEvalCount = $mysqli->query("SELECT COUNT(DISTINCT et.id) AS c
    FROM evaluation_tracker et
    WHERE et.eval_type IN $schoolheadTypeSql
      AND et.evaluator_id IN (SELECT id FROM users WHERE role='dean' AND is_active=1)
      AND $schoolheadTargetSql
")->fetch_assoc()['c'] ?? 0;
// How many School Head accounts are actually active/available to evaluate
// right now (0, 1, or 2) — distinct from $schoolHeadCount, which counts the
// Faculty/EA being evaluated, not the evaluators doing the evaluating.
$activeSchoolHeadEvaluators = $mysqli->query("SELECT COUNT(*) AS c FROM users WHERE role IN ('principal','dean') AND is_active=1")->fetch_assoc()['c'] ?? 0;

$eaEvalCount = $mysqli->query("SELECT COUNT(DISTINCT et.id) AS c FROM evaluation_tracker et WHERE et.eval_type='ea' AND $eaTargetPlainSql")->fetch_assoc()['c'] ?? 0;
$staffEvalCount = $mysqli->query("SELECT COUNT(DISTINCT et.id) AS c FROM evaluation_tracker et WHERE et.eval_type='staff' AND $staffEvalTargetPlainSql")->fetch_assoc()['c'] ?? 0;

$staffDesigCounts = [];
if ($activeEval === 'peer') {
    // Peer tab's Staff pill uses the canonical non-teaching-staff predicate,
    // so this breakdown must match it rather than every role='staff' row.
    $sdq = $mysqli->query("SELECT id, designation, role FROM users WHERE role IN ('teacher','staff','faculty') AND is_active=1");
    if ($sdq) {
        while ($r = $sdq->fetch_assoc()) {
            if (ec_resolve_peer_group($r, $mysqli) !== 'staff') continue;
            $d = $r['designation'];
            $staffDesigCounts[$d] = ($staffDesigCounts[$d] ?? 0) + 1;
        }
        ksort($staffDesigCounts);
    }
} elseif ($activeEval === 'student') {
    $sdq = $mysqli->query("SELECT id, designation, role, sector, source
        FROM users
        WHERE role IN ('teacher','staff') AND is_active=1 AND account_status='approved'");
    if ($sdq) {
        while ($r = $sdq->fetch_assoc()) {
            if (ec_resolve_student_group_full($r, $mysqli) !== 'staff') continue;
            $d = $r['designation'];
            $staffDesigCounts[$d] = ($staffDesigCounts[$d] ?? 0) + 1;
        }
        ksort($staffDesigCounts);
    }
} else {
    $sdq = $mysqli->query("SELECT designation, COUNT(*) as c FROM users WHERE role='staff' AND is_active=1 GROUP BY designation ORDER BY designation");
    if ($sdq) while ($r = $sdq->fetch_assoc()) $staffDesigCounts[$r['designation']] = $r['c'];
}

pageHead('Evaluation Report', $evalColor, $evalColorBg, $evalColorBorder);
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
.group-tab.active-all{background:rgba(59,130,246,.2);border-color:rgba(59,130,246,.5);color:#2563EB;}
.group-tab.active-faculty{background:rgba(14,116,144,.09);border-color:rgba(14,116,144,.30);color:#0E7490;}
.group-tab.active-faculty i{color:#0E7490;}
.group-tab.active-multirole{background:rgba(14,116,144,.09);border-color:rgba(14,116,144,.30);color:#0E7490;}
.group-tab.active-multirole i{color:#4968C8;}
.group-tab .all-icon{color:#2563EB;}
.group-tab .teacher-icon{color:#0E7490;}
.group-tab .staff-icon{color:#4968C8;}
.group-tab .multirole-icon{color:#4968C8;}
.group-tab.active-all i{color:#2563EB;}
.faculty-subtabs{display:flex;align-items:center;gap:8px;margin:-8px 0 20px 0;padding:8px 10px;border:1px solid var(--border);border-radius:12px;background:#F8FAFC;width:fit-content;box-shadow:0 2px 8px rgba(30,82,144,.05);}
.faculty-subtabs-label{font-size:11px;color:var(--muted);font-weight:800;text-transform:uppercase;letter-spacing:.7px;margin-right:2px;}
.faculty-subtab{display:flex;align-items:center;gap:7px;padding:8px 15px;border-radius:9px;border:1px solid transparent;color:var(--muted);text-decoration:none;font-size:13px;font-weight:700;transition:all .2s;}
.faculty-subtab:hover{background:#EEF2F7;color:#0B1F3A;}
/* Faculty and Multi-Role sub-tabs use one consistent tab color.
   Icons remain individually color-coded for quick visual recognition. */
.faculty-subtab.active-teacher,
.faculty-subtab.active-staff{background:#E8F8FB;border-color:rgba(14,116,144,.24);color:#0E7490;}
.faculty-subtab.active-mr-all{background:#E8F8FB;border-color:rgba(14,116,144,.24);color:#0E7490;}
.faculty-subtab i{color:#67819E;transition:color .2s;}
.faculty-subtab .teacher-icon{color:#2563EB;}
.faculty-subtab .staff-icon{color:#4968C8;}
.faculty-subtab .mr-all-icon{color:#0E7490;}
.faculty-subtab.active-teacher i{color:#2563EB;}
.faculty-subtab.active-staff i{color:#4968C8;}
.faculty-subtab.active-mr-all i{color:#0E7490;}
.tab-count{background:rgba(255,255,255,.12);border-radius:20px;padding:1px 8px;font-size:11px;font-weight:700;}
.group-tab.active-schoolhead{background:rgba(199,122,8,.10);border-color:rgba(199,122,8,.34);color:#C77A08;}
.group-tab.active-schoolhead i{color:#C77A08;}
.group-tab .schoolhead-icon{color:#C77A08;}
/* Role-specific report tab themes */
.group-tab.active-faculty{background:rgba(37,99,235,.10);border-color:rgba(37,99,235,.30);color:#2563EB;}
.group-tab.active-faculty i{color:#2563EB;}
.group-tab .teacher-icon{color:#2563EB;}
.group-tab.active-staff{background:rgba(124,58,237,.10);border-color:rgba(124,58,237,.30);color:#7C3AED;}
.group-tab.active-staff i{color:#7C3AED;}
.group-tab .staff-icon{color:#7C3AED;}
.group-tab.active-ea{background:rgba(15,159,110,.10);border-color:rgba(15,159,110,.30);color:#0F9F6E;}
.group-tab.active-ea i{color:#0F9F6E;}
.group-tab .ea-icon{color:#0F9F6E;}
.group-tab.active-principal{background:rgba(217,119,6,.11);border-color:rgba(217,119,6,.30);color:#C77A08;}
.group-tab.active-principal i{color:#C77A08;}
.group-tab .principal-icon{color:#C77A08;}
.group-tab.active-dean{background:rgba(139,92,246,.12);border-color:rgba(139,92,246,.34);color:#8B5CF6;}
.group-tab.active-dean i{color:#8B5CF6;}
.group-tab .dean-icon{color:#8B5CF6;}
.desig-subtabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:20px;padding:12px 16px;background:rgba(217,119,6,.06);border:1px solid rgba(217,119,6,.15);border-radius:10px;}
.desig-subtab{padding:5px 14px;border-radius:20px;font-size:12px;font-weight:600;background:rgba(37,99,235,.06);border:1px solid var(--border);color:var(--muted);cursor:pointer;text-decoration:none;transition:all .2s;}
.desig-subtab:hover{color:var(--light);}
.desig-subtab.active{background:rgba(217,119,6,.2);border-color:rgba(217,119,6,.4);color:#D69612;}
.summary-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;margin-bottom:24px;}
.sum-card{background:var(--mid);border:1px solid var(--border);border-radius:12px;padding:18px 20px;}
.sum-label{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:8px;}
.sum-value{font-size:28px;font-weight:700;color:var(--light);}
.sum-sub{font-size:12px;color:var(--muted);margin-top:4px;}
.sum-card.highlight .sum-value{color:var(--ec);}
.standings-row{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px;}
.standing-panel{background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:22px;}
.standing-title{font-size:15px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.standing-title.top{color:#32B98A;}
.standing-title.low{color:#E6788A;}
.standing-item{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);}
.standing-item:last-child{border-bottom:none;}
.standing-rank{font-size:13px;font-weight:700;color:var(--muted);width:22px;text-align:center;flex-shrink:0;}
.standing-photo{width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid var(--border);}
.standing-photo-ph{width:38px;height:38px;border-radius:50%;background:var(--inner);border:2px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:14px;}
.standing-info{flex:1;}
.standing-name{font-size:13px;font-weight:600;color:var(--light);}
.standing-desig{font-size:11px;color:var(--muted);}
.standing-score{font-size:15px;font-weight:700;}
.score-top{color:#32B98A;}
.score-low{color:#E6788A;}
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
.group-badge.teacher{background:rgba(14,116,144,.12);color:#0E7490;border:1px solid rgba(13,148,136,.3);}
.group-badge.staff{background:rgba(217,119,6,.15);color:#D69612;border:1px solid rgba(217,119,6,.3);}
.group-badge.schoolhead{background:rgba(217,119,6,.10);color:#C77A08;border:1px solid rgba(217,119,6,.28);}
.desig-pill{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;background:rgba(255,255,255,.07);color:var(--muted);border:1px solid var(--border);}
.person-stats{display:flex;gap:20px;align-items:center;flex-shrink:0;flex-wrap:wrap;}
.pstat{display:flex;flex-direction:column;align-items:center;gap:2px;}
.pstat-val{font-size:18px;font-weight:700;color:var(--light);}
.pstat-lbl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;}
.pstat-val.good{color:#32B98A;}
.pstat-val.mid{color:var(--gold-h);}
.pstat-val.poor{color:#E6788A;}
.score-bar-wrap{display:flex;align-items:center;gap:10px;min-width:140px;}
.score-bar-bg{flex:1;height:6px;background:rgba(255,255,255,.07);border-radius:3px;overflow:hidden;}
.score-bar-fill{height:100%;border-radius:3px;}
.arrow-icon{color:var(--muted);font-size:14px;flex-shrink:0;margin-left:4px;}
.person-actions{display:flex;align-items:center;gap:8px;flex-shrink:0;margin-left:12px;}
.btn-archive{background:none;border:1px solid var(--border);color:var(--muted);padding:9px 11px;border-radius:8px;font-size:13px;cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:6px;font-family:'Inter',sans-serif;}
.btn-archive:hover{background:rgba(240,84,84,.12);border-color:rgba(240,84,84,.4);color:#E6788A;}
.btn-archive span{font-size:12px;font-weight:600;}
.no-evaluated{text-align:center;padding:48px;background:var(--mid);border-radius:14px;border:1px solid var(--border);color:var(--muted);}
.no-evaluated i{font-size:36px;opacity:.3;display:block;margin-bottom:14px;}
/* peer note */
.peer-info-note{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:10px;margin-bottom:20px;font-size:12px;color:var(--muted);line-height:1.6;background:rgba(124,58,237,.06);border:1px solid rgba(73,104,200,.12);}
.peer-info-note i{color:#4968C8;margin-top:1px;flex-shrink:0;}
@media(max-width:800px){.standings-row{grid-template-columns:1fr;}body{padding:16px;}}
@media(max-width:560px){.summary-row{grid-template-columns:1fr 1fr;}.person-header{flex-wrap:wrap;}.btn-archive span{display:none;}.eval-switcher{width:100%;}.eval-tab{flex:1;justify-content:center;}}

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
</style>
<?php include __DIR__.'/analytics_dark_head.php'; ?>
<link rel="stylesheet" href="admin_unified_ui.css?v=<?= (int)@filemtime(__DIR__ . '/admin_unified_ui.css') ?>">
<style>
/* Separated tabs: each evaluation tab is its own bordered pill, no shared bar behind them */
html body .eval-switcher,html[data-theme="dark"] body .eval-switcher,html.dark-theme body .eval-switcher{background:transparent !important;border:0 !important;box-shadow:none !important;padding:0 !important;border-radius:0 !important;overflow:visible !important;gap:8px !important;}
html body .eval-tab{border:1px solid #D8E5F4 !important;border-radius:12px !important;background:#fff !important;box-shadow:0 2px 4px rgba(30,82,144,.05) !important;padding:11px 20px !important;}
html body .eval-tab.active{border-color:currentColor !important;}
html body .eval-tab.active::after{display:none !important;}
</style>
</head>
<body>
<script src="admin_ajax.php"></script>
<script>
// Confirm dialog helper: uses the dashboard's PBI.confirm when it is reachable
// (this page runs in an iframe, so PBI may only exist in the parent window),
// otherwise falls back to a built-in modal. Never uses the native browser confirm.
if (!window.saConfirm) (function(){
    const css = document.createElement('style');
    css.textContent = `
    .sa-modal-backdrop{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(16,39,70,.55);backdrop-filter:blur(2px);opacity:0;transition:opacity .15s ease}
    .sa-modal-backdrop.show{opacity:1}
    .sa-modal{width:100%;max-width:440px;max-height:calc(100vh - 36px);display:flex;flex-direction:column;background:var(--mid,#fff);color:var(--light,#0B1F3A);border-radius:16px;box-shadow:0 24px 60px rgba(10,30,60,.35);transform:translateY(8px) scale(.98);transition:transform .15s ease;overflow:hidden;font-family:Inter,system-ui,sans-serif}
    .sa-modal-backdrop.show .sa-modal{transform:none}
    .sa-modal-head{display:flex;gap:12px;align-items:center;padding:20px 22px 6px}
    .sa-modal-icon{flex:0 0 38px;height:38px;border-radius:50%;display:grid;place-items:center;font-size:16px;background:rgba(214,69,93,.12);color:#D6455D}
    .sa-modal.safe .sa-modal-icon{background:rgba(37,99,235,.12);color:#2563EB}
    .sa-modal-title{margin:0;font-size:16px;font-weight:800;letter-spacing:-.01em}
    .sa-modal-body{padding:8px 22px 18px;overflow:auto;white-space:pre-line;font-size:13px;line-height:1.55;color:var(--muted,#67819E)}
    .sa-modal-foot{display:flex;justify-content:flex-end;gap:10px;padding:14px 22px 20px;border-top:1px solid var(--border,#D8E5F4)}
    .sa-btn{border:1px solid var(--border,#B9CDE5);background:transparent;color:inherit;border-radius:10px;padding:10px 18px;font:700 13px Inter,system-ui,sans-serif;cursor:pointer}
    .sa-btn.ok{border-color:transparent;color:#fff;background:#D6455D}
    .sa-modal.safe .sa-btn.ok{background:#2563EB}
    .sa-btn:focus-visible{outline:3px solid rgba(37,99,235,.35);outline-offset:2px}`;
    document.head.appendChild(css);

    function builtin(message, opts){
        opts = opts || {};
        const danger = opts.danger !== false;
        let title, body;
        const text = String(message);
        if (text.indexOf('\n') > -1) { const i = text.indexOf('\n'); title = text.slice(0, i); body = text.slice(i).trim(); }
        else { const m = text.match(/^(.*?\?)\s+(.+)$/); title = m ? m[1] : text; body = m ? m[2] : ''; }
        return new Promise(resolve => {
            const back = document.createElement('div');
            back.className = 'sa-modal-backdrop';
            back.innerHTML = `
              <div class="sa-modal ${danger ? '' : 'safe'}" role="alertdialog" aria-modal="true">
                <div class="sa-modal-head"><div class="sa-modal-icon"><i class="fa-solid ${danger ? 'fa-triangle-exclamation' : 'fa-circle-question'}"></i></div><h3 class="sa-modal-title"></h3></div>
                <div class="sa-modal-body"></div>
                <div class="sa-modal-foot"><button type="button" class="sa-btn cancel"></button><button type="button" class="sa-btn ok"></button></div>
              </div>`;
            back.querySelector('.sa-modal-title').textContent = title;
            const bodyEl = back.querySelector('.sa-modal-body');
            if (body) bodyEl.textContent = body; else bodyEl.remove();
            const okBtn = back.querySelector('.ok'), noBtn = back.querySelector('.cancel');
            okBtn.textContent = opts.okLabel || 'OK';
            noBtn.textContent = opts.cancelLabel || 'Cancel';
            function close(v){ document.removeEventListener('keydown', onKey, true); back.classList.remove('show'); setTimeout(() => back.remove(), 150); resolve(v); }
            function onKey(e){
                if (e.key === 'Escape'){ e.preventDefault(); close(false); }
                else if (e.key === 'Tab'){ e.preventDefault(); (document.activeElement === okBtn ? noBtn : okBtn).focus(); }
            }
            okBtn.addEventListener('click', () => close(true));
            noBtn.addEventListener('click', () => close(false));
            back.addEventListener('mousedown', e => { if (e.target === back) close(false); });
            document.addEventListener('keydown', onKey, true);
            document.body.appendChild(back);
            requestAnimationFrame(() => { back.classList.add('show'); noBtn.focus(); });
        });
    }

    function findPBI(){
        for (const w of [window, window.parent, window.top]) {
            try { if (w && w.PBI && typeof w.PBI.confirm === 'function') return w.PBI; } catch (e) {}
        }
        return null;
    }

    window.saConfirm = async function(message, opts){
        try {
            const pbi = findPBI();
            if (pbi) return await pbi.confirm(message, opts);
        } catch (err) { console.error('PBI.confirm failed, using built-in modal', err); }
        return builtin(message, opts);
    };
})();

// Navigates to a link's href only after the custom (non-native) confirm modal is accepted.
function confirmNav(link, message, opts){
    (async () => {
        if (await window.saConfirm(message, opts)) {
            window.location.href = link.href;
        }
    })();
    return false;
}
</script>

<?php if ($toast): ?><div class="toast"><i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($toast) ?></div><?php endif; ?>

<!-- ── EVAL TYPE SWITCHER ── -->
<?php
// Peer-to-Peer and School Head don't support the Multi-Role pill, so switching
// to them resets the group filter to All rather than carrying over a filter
// that wouldn't apply.
$groupForOtherTabs = $groupFilter;
?>
<?php
// Who is doing the evaluating on the active tab (used in the section captions).
$raWho = ['student'=>'students','peer'=>'colleagues','schoolhead'=>'the Dean / Principal','ea'=>'the Executive Assistant','staff'=>'staff members'][$activeEval] ?? $evaluatorNounP;
?>
<div class="ra-role-label ra-role-evaluators no-print">
    <span class="ra-role-badge"><i class="fa-solid fa-pen-to-square"></i> Evaluators</span>
    <span class="ra-role-hint">Who submitted the evaluations &mdash; choose one to see its results</span>
</div>
<div class="ra-topbar">
<div class="eval-switcher">
    <a href="?group=All&eval_type=student" class="eval-tab student <?= $activeEval==='student'?'active':'' ?>">
        <i class="fa-solid fa-graduation-cap"></i> Student Evaluation
    </a>
    <div class="eval-divider"></div>
    <a href="?group=All&eval_type=peer" class="eval-tab peer <?= $activeEval==='peer'?'active':'' ?>">
        <i class="fa-solid fa-people-arrows"></i> Peer-to-Peer
    </a>
    <div class="eval-divider"></div>
    <a href="?group=Faculty&eval_type=schoolhead&evaluator=All" class="eval-tab schoolhead <?= $activeEval==='schoolhead'?'active':'' ?>">
        <i class="fa-solid fa-user-tie"></i> Dean / Principal
    </a>
    <div class="eval-divider"></div>
    <a href="?group=All&eval_type=ea" class="eval-tab ea <?= $activeEval==='ea'?'active':'' ?>">
        <i class="fa-solid fa-user-shield"></i> Executive Assistant
    </a>
</div>
        <div class="ra-table-tools ra-top-tools no-print">
            <div class="ra-filters-wrap">
                <button class="ra-tool-btn" id="raFiltersToggle" onclick="raToggleFilters()"><i class="fa-solid fa-filter"></i> Filters</button>
                <div class="ra-filters-panel" id="raFiltersPanel">
                    <div class="ra-filter-row">
                        <label>Min score</label>
                        <select id="raMinScore" onchange="raFilterTable()">
                            <option value="0">Any</option>
                            <option value="4.5">4.50 (Excellent)</option>
                            <option value="3.5">3.50 (Very Good)</option>
                            <option value="2.5">2.50 (Good)</option>
                        </select>
                    </div>
                    <div class="ra-filter-row">
                        <label>Role</label>
                        <select id="raRoleFilter" onchange="raFilterTable()">
                            <option value="all">All</option>
                            <?php
                            if ($activeEval === 'schoolhead') {
                                $reportRoles = [];
                                foreach ($people as $rp) {
                                    $rrg = ec_resolve_schoolhead_target_group($rp, $mysqli);
                                    $label = match ($rrg) {
                                        'faculty' => 'Faculty',
                                        'staff' => 'Staff',
                                        'ea' => 'Executive Assistant',
                                        default => null,
                                    };
                                    if ($label !== null) $reportRoles[$label] = true;
                                }
                                foreach (array_keys($reportRoles) as $reportRoleLabel):
                            ?>
                            <option value="<?= htmlspecialchars(strtolower($reportRoleLabel)) ?>"><?= htmlspecialchars($reportRoleLabel) ?></option>
                            <?php endforeach; ?>
                            <?php } else { ?>
                            <?php foreach (array_unique(array_column($people, 'role')) as $r): ?>
                            <option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars(ucfirst($r)) ?></option>
                            <?php endforeach; ?>
                            <?php } ?>
                        </select>
                    </div>
                    <button class="ra-filter-clear" onclick="raClearFilters()">Clear filters</button>
                </div>
            </div>
            <div class="ra-search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="raSearch" class="ra-search" placeholder="Search name, designation..." oninput="raFilterTable()">
            </div>
        </div>
</div>

<!-- GROUP TABS -->
<div class="ra-role-label ra-role-evaluated no-print">
    <span class="ra-role-badge"><i class="fa-solid fa-user-check"></i> Being evaluated</span>
    <span class="ra-role-hint">Personnel rated by <?= htmlspecialchars($raWho) ?> &mdash; filter by group</span>
</div>
<?php if ($activeEval === 'schoolhead'): ?>
<div class="group-tabs">
    <a href="?group=All&eval_type=schoolhead&evaluator=<?= urlencode($evaluatorFilter) ?>"
       class="group-tab <?= $groupFilter==='All'?'active-all':'' ?>">
        <i class="fa-solid fa-users all-icon"></i> All
    </a>
    <a href="?group=Faculty&eval_type=schoolhead&evaluator=<?= urlencode($evaluatorFilter) ?>"
       class="group-tab <?= $groupFilter==='Faculty'?'active-faculty':'' ?>">
        <i class="fa-solid fa-chalkboard-user teacher-icon"></i> Faculty
    </a>
    <a href="?group=Staff&eval_type=schoolhead&evaluator=<?= urlencode($evaluatorFilter) ?>"
       class="group-tab <?= $groupFilter==='Staff'?'active-staff':'' ?>">
        <i class="fa-solid fa-briefcase staff-icon"></i> Staff
    </a>
</div>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;font-size:12px;color:var(--muted);margin:-6px 0 18px 2px;">
    </div>
    <div style="display:flex;align-items:center;gap:7px;">
        <span style="font-weight:700;">Evaluated by:</span>
        <select onchange="location.href='?group=<?= urlencode($groupFilter) ?>&eval_type=schoolhead&evaluator='+encodeURIComponent(this.value)" style="padding:6px 10px;border:1px solid var(--border);border-radius:8px;background:var(--inner);color:var(--light);font-size:12px;">
            <option value="All" <?= $evaluatorFilter==='All'?'selected':'' ?>>All</option>
            <option value="Principal" <?= $evaluatorFilter==='Principal'?'selected':'' ?>>Principal</option>
            <option value="Dean" <?= $evaluatorFilter==='Dean'?'selected':'' ?>>Dean</option>
        </select>
    </div>
</div>
<?php else: ?>
<div class="group-tabs">
    <a href="?group=All&eval_type=<?= $activeEval ?>"
       class="group-tab <?= $groupFilter==='All'?'active-all':'' ?>">
        <i class="fa-solid fa-users all-icon"></i> All
    </a>

    <?php if ($activeEval !== 'ea' && $activeEval !== 'staff'): ?>
    <!-- Faculty and Staff tabs apply to Student / Peer / other personnel
         contexts. EA Evaluation has its own Questionnaire-aligned scopes
         below: Staff, Dean, and Principal. -->
    <a href="?group=Teacher&eval_type=<?= $activeEval ?>"
       class="group-tab <?= $groupFilter==='Teacher'?'active-faculty':'' ?>">
        <i class="fa-solid fa-chalkboard-user teacher-icon"></i> Faculty
    </a>

    <a href="?group=Staff&eval_type=<?= $activeEval ?>"
       class="group-tab <?= $groupFilter==='Staff'?'active-staff':'' ?>">
        <i class="fa-solid fa-briefcase staff-icon"></i> Staff
    </a>
    <?php endif; ?>

    <?php if ($activeEval === 'peer'): ?>
    <!-- Peer-to-Peer school leadership targets are separated into Dean and Principal. -->
    <a href="?group=Dean&eval_type=peer"
       class="group-tab <?= $groupFilter==='Dean'?'active-dean':'' ?>">
        <i class="fa-solid fa-graduation-cap dean-icon"></i> Dean
    </a>
    <a href="?group=Principal&eval_type=peer"
       class="group-tab <?= $groupFilter==='Principal'?'active-principal':'' ?>">
        <i class="fa-solid fa-user-tie principal-icon"></i> Principal
    </a>
    <?php endif; ?>

    <?php if ($activeEval === 'student'): ?>
    <a href="?group=Dean&eval_type=student"
       class="group-tab <?= $groupFilter==='Dean'?'active-dean':'' ?>">
        <i class="fa-solid fa-graduation-cap dean-icon"></i> Dean
    </a>
    <a href="?group=Principal&eval_type=student"
       class="group-tab <?= $groupFilter==='Principal'?'active-principal':'' ?>">
        <i class="fa-solid fa-user-tie principal-icon"></i> Principal
    </a>
    <?php endif; ?>

    <?php if ($activeEval === 'ea'): ?>
    <!-- Executive Assistant Evaluation: exact Questionnaire scopes. -->
    <a href="?group=Staff&eval_type=ea" class="group-tab <?= $groupFilter==='Staff'?'active-staff':'' ?>"><i class="fa-solid fa-briefcase staff-icon"></i> Staff</a>
    <a href="?group=Dean&eval_type=ea" class="group-tab <?= $groupFilter==='Dean'?'active-dean':'' ?>"><i class="fa-solid fa-graduation-cap dean-icon"></i> Dean</a>
    <a href="?group=Principal&eval_type=ea" class="group-tab <?= $groupFilter==='Principal'?'active-schoolhead':'' ?>"><i class="fa-solid fa-user-tie schoolhead-icon"></i> Principal</a>
    <?php endif; ?>

    <?php if ($activeEval === 'staff'): ?>
    <a href="?group=Dean&eval_type=staff" class="group-tab <?= $groupFilter==='Dean'?'active-staff':'' ?>"><i class="fa-solid fa-graduation-cap staff-icon"></i> Dean</a>
    <a href="?group=Principal&eval_type=staff" class="group-tab <?= $groupFilter==='Principal'?'active-principal':'' ?>"><i class="fa-solid fa-user-tie principal-icon"></i> Principal</a>
    <a href="?group=EA&eval_type=staff" class="group-tab <?= $groupFilter==='EA'?'active-ea':'' ?>"><i class="fa-solid fa-user-shield ea-icon"></i> Executive Assistant</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$isMultiRole && !in_array($activeEval, ['student','peer','schoolhead','ea'], true) && $groupFilter === 'Staff' && !empty($staffDesigCounts)): ?>
<div class="desig-subtabs">
    <span style="font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-right:4px;">By Role:</span>
    <?php foreach ($staffDesigCounts as $desig => $cnt): ?>
    <a href="#" class="desig-subtab" onclick="filterByDesig('<?= htmlspecialchars(addslashes($desig)) ?>');return false;"><?= htmlspecialchars($desig) ?> <span style="opacity:.6">(<?= $cnt ?>)</span></a>
    <?php endforeach; ?>
    <a href="#" class="desig-subtab active" onclick="filterByDesig('all');return false;">Show All</a>
</div>
<?php endif; ?>

<!-- PEOPLE TABLE -->
<style>
.ra-table-card{background:var(--mid);border:1px solid var(--border);border-radius:14px;padding:22px 24px;}
.ra-table-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px;}
.ra-table-head .section-title{margin-bottom:0;}
.ra-table-tools{display:flex;align-items:center;gap:10px;}
.ra-topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:26px;}
.ra-topbar .eval-switcher{margin-bottom:0;}
.ra-top-tools{margin-left:auto;}
@media(max-width:560px){.ra-top-tools{width:100%;}.ra-top-tools .ra-search-wrap{flex:1;}.ra-top-tools .ra-search{width:100%;min-width:0;box-sizing:border-box;}}
.ra-tool-btn{padding:9px 14px;border-radius:8px;border:1px solid var(--border);background:var(--inner);color:var(--light);font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;}
.ra-tool-btn:hover{border-color:var(--ec);color:var(--ec);}
.ra-filters-wrap{position:relative;}
.ra-filters-panel{display:none;position:absolute;right:0;top:calc(100% + 6px);background:var(--mid);border:1px solid var(--border);border-radius:10px;padding:14px;min-width:220px;box-shadow:0 8px 24px rgba(0,0,0,.18);z-index:20;}
.ra-filters-panel.open{display:block;}
.ra-filter-row{display:flex;flex-direction:column;gap:4px;margin-bottom:12px;}
.ra-filter-row label{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);}
.ra-filter-row select{padding:7px 10px;border-radius:7px;border:1px solid var(--border);background:var(--inner);color:var(--light);font-size:13px;}
.ra-filter-clear{width:100%;padding:7px;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:12px;font-weight:600;cursor:pointer;}
.ra-filter-clear:hover{color:var(--ec);border-color:var(--ec);}
.ra-search-wrap{position:relative;}
.ra-search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;z-index:1;pointer-events:none;}
html body .ra-search-wrap .ra-search{padding:9px 12px 9px 36px!important;}
.ra-search{padding:9px 12px 9px 32px;border-radius:8px;border:1px solid var(--border);font-size:13px;background:var(--inner);color:var(--light);min-width:220px;}
.ra-table-wrap{overflow-x:auto;}
table.ra-table{width:100%;border-collapse:collapse;font-size:13px;}
table.ra-table th{text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);border-bottom:1px solid var(--border);white-space:nowrap;}
table.ra-table td{padding:12px;border-bottom:1px solid var(--border);vertical-align:middle;}
table.ra-table tbody tr:hover{background:var(--inner);}
.ra-name-cell{display:flex;align-items:center;gap:10px;}
.ra-photo{width:34px;height:34px;border-radius:50%;object-fit:cover;border:1px solid var(--border);flex-shrink:0;}
.ra-photo-ph{width:34px;height:34px;border-radius:50%;background:var(--inner);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:13px;flex-shrink:0;}
.ra-name-text a{font-weight:700;color:var(--light);text-decoration:none;}
.ra-name-text a:hover{color:var(--ec);}
.ra-role-badge{font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap;display:inline-flex;align-items:center;gap:5px;}
.ra-score{font-weight:700;}
.ra-view-btn{width:34px;height:34px;padding:0;justify-content:center;border-radius:7px;border:1px solid var(--ec-bd,var(--border));background:var(--ec-bg,transparent);color:var(--ec,inherit);font-size:12px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.ra-view-btn:hover{opacity:.85;}
.ra-icon-btn{width:34px;height:34px;padding:0;justify-content:center;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;}
.ra-icon-btn:hover{border-color:var(--ec);color:var(--ec);}
.ra-actions-cell{display:flex;gap:8px;white-space:nowrap;}
.ra-pager{display:flex;align-items:center;justify-content:space-between;margin-top:16px;flex-wrap:wrap;gap:10px;}
.ra-pager-info{font-size:12px;color:var(--muted);}
.ra-pager-btns{display:flex;gap:6px;}
.ra-pager-btns button{width:30px;height:30px;border-radius:7px;border:1px solid var(--border);background:var(--inner);color:var(--light);font-size:12px;font-weight:700;cursor:pointer;}
.ra-pager-btns button.active{background:var(--ec,#1E5290);border-color:var(--ec,#1E5290);color:#fff;}
.ra-pager-btns button:disabled{opacity:.4;cursor:not-allowed;}
</style>

<style id="ra-roles">
/* ------------------------------------------------------------------
   Evaluators vs. evaluated personnel
   Row 1 (indigo)  = EVALUATORS: who submitted the evaluations.
   Row 2 + table (teal) = BEING EVALUATED: the people who were rated.
   Presentation only; links, filters and queries are unchanged.
   ------------------------------------------------------------------ */
html body:not(#rap) .ra-role-label{display:flex;align-items:center;flex-wrap:wrap;gap:10px 12px;margin:0 0 10px;}
html body:not(#rap) .ra-role-badge{display:inline-flex;align-items:center;gap:8px;padding:6px 13px;border-radius:999px;font-size:12px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;border:1px solid transparent;}
html body:not(#rap) .ra-role-hint{font-size:13px;font-weight:500;}
html body:not(#rap) .ra-topbar{margin-bottom:22px!important;}
html body:not(#rap) .ra-role-evaluated{margin-top:2px;}

/* Evaluator tabs: one tinted strip, solid fill for the selected evaluator */
html body:not(#rap) .eval-switcher{padding:5px!important;gap:4px!important;border-radius:14px!important;}
html body:not(#rap) .eval-switcher .eval-divider{display:none!important;}
html body:not(#rap) .eval-tab{padding:11px 20px!important;border-radius:10px!important;font-size:14px!important;font-weight:700!important;}
html body:not(#rap) .eval-tab::after{display:none!important;}
html body:not(#rap) .eval-tab.student{--tc:#2563EB;}
html body:not(#rap) .eval-tab.peer{--tc:#4968C8;}
html body:not(#rap) .eval-tab.schoolhead{--tc:#B8700A;}
html body:not(#rap) .eval-tab.ea{--tc:#0F9F6E;}
html body:not(#rap) .eval-tab.staff-eval{--tc:#0E7490;}
html body:not(#rap) .eval-tab.active{background:var(--tc)!important;color:#fff!important;box-shadow:0 3px 10px rgba(11,31,58,.22);}
html body:not(#rap) .eval-tab.active > i{color:#fff!important;}

/* Target-group tabs: teal family */
html body:not(#rap) .group-tab{padding:9px 20px!important;font-size:14px!important;font-weight:700!important;}
html body:not(#rap) .group-tab[class*="active-"]{box-shadow:0 2px 8px rgba(15,118,110,.14);}

/* Table card: teal accent + caption */
html body:not(#rap) .ra-table-card{border-left:5px solid #0F766E!important;}
html body:not(#rap) .ra-table-head{flex-direction:column!important;align-items:flex-start!important;gap:4px!important;margin-bottom:18px!important;}
html body:not(#rap) .ra-table-sub{font-size:13px;font-weight:500;padding-left:2px;}
html body:not(#rap) .ra-table th{font-size:12px!important;letter-spacing:.06em;}

/* ── Light theme colours ── */
html:not([data-theme="dark"]) body:not(#rap) .ra-role-evaluators .ra-role-badge{background:#E0E7FF;color:#3730A3;border-color:#B4C0F7;}
html:not([data-theme="dark"]) body:not(#rap) .ra-role-evaluated .ra-role-badge{background:#D5F3EE;color:#0B5F57;border-color:#8CD5C9;}
html:not([data-theme="dark"]) body:not(#rap) .ra-role-hint,html:not([data-theme="dark"]) body:not(#rap) .ra-table-sub{color:#475569;}
html:not([data-theme="dark"]) body:not(#rap) .eval-switcher{background:#EEF2FF!important;border:1px solid #C7D2FE!important;box-shadow:none!important;}
html:not([data-theme="dark"]) body:not(#rap) .eval-tab:not(.active){color:#3B4A63!important;}
html:not([data-theme="dark"]) body:not(#rap) .eval-tab:not(.active):hover{background:#E0E7FF!important;color:#1E1B4B!important;}
html:not([data-theme="dark"]) body:not(#rap) .group-tab{background:#fff!important;border-color:#C9D6E4!important;color:#334155!important;}
html:not([data-theme="dark"]) body:not(#rap) .group-tab:hover{background:#F0FAF8!important;border-color:#8CD5C9!important;color:#0B5F57!important;}
html:not([data-theme="dark"]) body:not(#rap) .group-tab[class*="active-"]{background:#DDF4F0!important;border-color:#5CBFB0!important;color:#0B5F57!important;}
html:not([data-theme="dark"]) body:not(#rap) .ra-table-card{border-left-color:#0F766E!important;}

/* ── Dark theme ── */
html[data-theme="dark"] body:not(#rap) .ra-role-evaluators .ra-role-badge{background:rgba(129,140,248,.18);color:#C7D2FE;border-color:rgba(129,140,248,.4);}
html[data-theme="dark"] body:not(#rap) .ra-role-evaluated .ra-role-badge{background:rgba(45,212,191,.15);color:#99F6E4;border-color:rgba(45,212,191,.38);}
html[data-theme="dark"] body:not(#rap) .ra-role-hint,html[data-theme="dark"] body:not(#rap) .ra-table-sub{color:var(--muted);}
html[data-theme="dark"] body:not(#rap) .eval-switcher{background:rgba(129,140,248,.08)!important;border:1px solid rgba(129,140,248,.28)!important;}
html[data-theme="dark"] body:not(#rap) .ra-table-card{border-left-color:#2DD4BF!important;}

@media(max-width:900px){html body:not(#rap) .eval-switcher{width:100%;overflow-x:auto;}html body:not(#rap) .eval-tab{padding:10px 14px!important;white-space:nowrap;}}
@media print{.ra-role-label{display:none!important;}}
</style>
<div class="ra-table-card">
    <div class="ra-table-head">
        <div class="section-title">
            <i class="fa-solid fa-list-check" style="color:var(--accent)"></i>
            <?= $activeEval==='student' ? 'Evaluated Personnel' : 'Evaluated Personnel' ?>
            <span style="font-size:13px;font-weight:400;color:var(--muted)">(<?= count($people) ?> evaluated)</span>
        </div>
        <div class="ra-table-sub">Rated by <?= htmlspecialchars($raWho) ?> &middot; scores are the average of the ratings they gave</div>
    </div>

    <?php if (empty($people)): ?>
    <div class="no-evaluated">
        <i class="fa-solid fa-hourglass-half"></i>
        <p>No <?= htmlspecialchars($evalLabel) ?> evaluations have been submitted yet.<br>
        <small style="font-size:12px;opacity:.6">Personnel will appear here once <?= $evaluatorNounP ?> have evaluated them.</small></p>
    </div>
    <?php else: ?>
    <div class="ra-table-wrap">
    <table class="ra-table" id="raTable">
        <thead>
            <tr>
                <th>No.</th>
                <th>Name</th>
                <th>Designation</th>
                <th>Role</th>
                <th>Overall Avg. Score</th>
                <th>Last Evaluated</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php $rowNum = 0; foreach ($people as $p):
            $rowNum++;
            $avg   = $p['avg_score'] !== null ? round($p['avg_score'],2) : null;
            $color = scoreColor($avg);
            // Two different meanings hide behind "school head" here. Student
            // Evaluation's School Head pill really does list Principal/Dean as
            // the evaluated people. The School Head Evaluation tab is the
            // opposite direction -- Principal/Dean are the evaluators there,
            // and $p is one of the Faculty/EA people they evaluated -- so it
            // must NOT be labeled Principal/Dean here (the old reversed-model
            // bug).
            $isSchoolHeadTargetPill = $activeEval === 'student' && in_array($groupFilter, ['Dean','Principal'], true);
            $isSchoolHeadEvalTab = $activeEval === 'schoolhead';
            // Use the same resolved target grouping as Questionnaire for
            // Dean / Principal Evaluation so Faculty / Staff / EA labels,
            // icons, filtering, and badges all refer to the same scope.
            $schoolheadGroup = $isSchoolHeadEvalTab ? ec_resolve_schoolhead_target_group($p, $mysqli) : null;
            $isFac = $isSchoolHeadEvalTab
                ? $schoolheadGroup === 'faculty'
                : ($activeEval === 'student' && !$isSchoolHeadTargetPill
                    ? ec_resolve_student_group_full($p, $mysqli) === 'teacher'
                    : $p['role'] === 'teacher');
            $isEA = $isSchoolHeadEvalTab
                ? $schoolheadGroup === 'ea'
                : (($activeEval === 'ea' || $activeEval === 'staff') && $p['role'] === 'superadmin');
            $isArchived = !empty($p['archived_at']);
            $roleLabel = analytics_target_group_label($activeEval, $p, $mysqli, $isMultiRole, $groupFilter);
            $roleIcon  = match (strtolower(trim((string)$roleLabel))) {
                'faculty', 'teacher' => 'fa-chalkboard-user',
                'staff' => 'fa-briefcase',
                'executive assistant', 'ea' => 'fa-user-shield',
                'principal' => 'fa-user-tie',
                'dean' => 'fa-graduation-cap',
                'school head', 'dean / principal' => 'fa-user-tie',
                default => ($isMultiRole ? 'fa-people-group' : 'fa-user'),
            };
            $roleTheme = analytics_role_theme($roleLabel);
            $roleBadgeStyle = 'background:' . $roleTheme['bg']
                . ';color:' . $roleTheme['color']
                . ';border:1px solid ' . $roleTheme['border'] . ';';
        ?>
            <tr data-search="<?= htmlspecialchars(strtolower($p['full_name'].' '.$p['designation'])) ?>" data-desig="<?= htmlspecialchars($p['designation']) ?>" data-score="<?= $avg !== null ? $avg : '' ?>" data-role="<?= htmlspecialchars(strtolower($isSchoolHeadEvalTab ? $roleLabel : $p['role'])) ?>">
                <td><?= $rowNum ?></td>
                <td>
                    <div class="ra-name-cell">
                        <?php if($p['photo']): ?><img class="ra-photo" src="../image/<?= htmlspecialchars($p['photo']) ?>" alt=""/>
                        <?php else: ?><div class="ra-photo-ph"><i class="fa-solid fa-user"></i></div><?php endif; ?>
                        <div class="ra-name-text">
                            <a href="?view=students&target_id=<?= $p['id'] ?>&group=<?= urlencode($groupFilter) ?>&eval_type=<?= $activeEval ?>&evaluator=<?= urlencode($evaluatorFilter) ?>"><?= htmlspecialchars($p['full_name']) ?></a>
                            <?php if ($isArchived && $isMultiRole): ?>
                            <div><span style="font-size:11px;color:var(--muted);"><i class="fa-solid fa-box-archive"></i> Archived</span></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td><?= htmlspecialchars($p['designation']) ?></td>
                <td><span class="ra-role-badge" style="<?= $roleBadgeStyle ?>"><i class="fa-solid <?= $roleIcon ?>"></i> <?= $roleLabel ?></span></td>
                <td class="ra-score" style="color:<?= $color ?>"><?= $avg !== null ? number_format($avg,2) : '—' ?></td>
                <td><?= !empty($p['last_evaluated']) ? date('M d, Y', strtotime($p['last_evaluated'])) : '—' ?></td>
                <td>
                    <div class="ra-actions-cell no-print">
                        <a class="ra-view-btn" title="View" aria-label="View" href="?view=students&target_id=<?= $p['id'] ?>&group=<?= urlencode($groupFilter) ?>&eval_type=<?= $activeEval ?>&evaluator=<?= urlencode($evaluatorFilter) ?>"><i class="fa-solid fa-eye"></i></a>
                        <?php if ($isArchived && $isMultiRole): ?>
                        <a class="ra-icon-btn" title="Restore" aria-label="Restore" href="?restore_id=<?= $p['id'] ?>&group=<?= urlencode($groupFilter) ?>&eval_type=<?= $activeEval ?>&evaluator=<?= urlencode($evaluatorFilter) ?>&view=archived"><i class="fa-solid fa-rotate-left"></i></a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="ra-pager no-print">
        <div class="ra-pager-info" id="raPagerInfo"></div>
        <div class="ra-pager-btns" id="raPagerBtns"></div>
    </div>
    <?php endif; ?>
</div>

<script>
(function(){
    const pageSize = 10;
    let currentPage = 1;
    const table = document.getElementById('raTable');
    if (!table) return;
    const allRows = Array.from(table.querySelectorAll('tbody tr'));

    function visibleRows() {
        return allRows.filter(r => r.dataset.matched !== 'false');
    }

    function render() {
        const vis = visibleRows();
        const totalPages = Math.max(1, Math.ceil(vis.length / pageSize));
        if (currentPage > totalPages) currentPage = totalPages;
        allRows.forEach(r => r.style.display = 'none');
        const start = (currentPage - 1) * pageSize;
        vis.slice(start, start + pageSize).forEach(r => r.style.display = '');

        const info = document.getElementById('raPagerInfo');
        if (vis.length === 0) {
            info.textContent = 'No matching entries';
        } else {
            info.textContent = `Showing ${start + 1} to ${Math.min(start + pageSize, vis.length)} of ${vis.length} entries`;
        }

        const btnsWrap = document.getElementById('raPagerBtns');
        btnsWrap.innerHTML = '';
        const prev = document.createElement('button');
        prev.innerHTML = '<i class="fa-solid fa-chevron-left"></i>';
        prev.disabled = currentPage === 1;
        prev.onclick = () => { currentPage--; render(); };
        btnsWrap.appendChild(prev);
        for (let i = 1; i <= totalPages; i++) {
            const b = document.createElement('button');
            b.textContent = i;
            if (i === currentPage) b.classList.add('active');
            b.onclick = () => { currentPage = i; render(); };
            btnsWrap.appendChild(b);
        }
        const next = document.createElement('button');
        next.innerHTML = '<i class="fa-solid fa-chevron-right"></i>';
        next.disabled = currentPage === totalPages;
        next.onclick = () => { currentPage++; render(); };
        btnsWrap.appendChild(next);
    }

    let activeDesig = 'all';

    window.raFilterTable = function() {
        const q = document.getElementById('raSearch').value.trim().toLowerCase();
        const minScore = parseFloat(document.getElementById('raMinScore').value) || 0;
        const roleVal = document.getElementById('raRoleFilter').value;
        allRows.forEach(r => {
            const matchesSearch = !q || r.dataset.search.includes(q);
            const matchesDesig = activeDesig === 'all' || r.dataset.desig === activeDesig;
            const rowScore = parseFloat(r.dataset.score);
            const matchesScore = minScore === 0 || (!isNaN(rowScore) && rowScore >= minScore);
            const matchesRole = roleVal === 'all' || r.dataset.role === roleVal;
            r.dataset.matched = (matchesSearch && matchesDesig && matchesScore && matchesRole) ? 'true' : 'false';
        });
        currentPage = 1;
        render();
    };

    window.raToggleFilters = function() {
        document.getElementById('raFiltersPanel').classList.toggle('open');
    };

    window.raClearFilters = function() {
        document.getElementById('raMinScore').value = '0';
        document.getElementById('raRoleFilter').value = 'all';
        raFilterTable();
    };

    document.addEventListener('click', function(e) {
        const wrap = document.querySelector('.ra-filters-wrap');
        if (wrap && !wrap.contains(e.target)) {
            document.getElementById('raFiltersPanel').classList.remove('open');
        }
    });

    window.filterByDesig = function(desig) {
        activeDesig = desig;
        document.querySelectorAll('.desig-subtabs .desig-subtab').forEach(t => t.classList.remove('active'));
        if (window.event && window.event.target) {
            window.event.target.closest('.desig-subtab').classList.add('active');
        }
        raFilterTable();
    };

    allRows.forEach(r => r.dataset.matched = 'true');
    render();
})();

</script>
<?php $mysqli->close(); ?>
<script src="eval_status_poll.js" data-watch="submissions" defer></script>
</body>
</html>