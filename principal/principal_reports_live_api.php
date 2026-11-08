<?php
// principal_reports_live_api.php
// Lightweight live-data signature for Principal Reports & Analytics.
// Every filter below mirrors principal_reports.php so a refresh is triggered
// by exactly the rows the page itself would list.
session_start();
require_once 'db.php';
require_once '../shared/system_settings_service.php';
require_once __DIR__ . '/school_head_structure_gate.php';   // sh_gate_applicable_role()

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}
$meId = (int)$_SESSION['user_id'];
// Release the session lock so this poll never blocks the Principal's other requests.
session_write_close();

$settings = get_system_settings($mysqli);
$periodId = (int)($settings['period_id'] ?? 0);
$activeEval = $_GET['eval_type'] ?? 'student';
if (!in_array($activeEval, ['student','peer','staff'], true)) $activeEval = 'student';
$group = $_GET['group'] ?? 'All';
// Peer-to-Peer has two lanes (Faculty / Staff), like principal_reports.php.
if ($activeEval === 'staff') $group = 'All';
if ($activeEval === 'peer' && !in_array($group, ['Faculty','Staff'], true)) $group = 'Faculty';

$hsLevels = "'Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12','Grade 7 - JHS','Grade 8 - JHS','Grade 9 - JHS','Grade 10 - JHS','Grade 11 - SHS','Grade 12 - SHS'";
$hsScope = "(
    EXISTS (SELECT 1 FROM user_year_levels s1 WHERE s1.user_id=u.id AND s1.year_level IN ($hsLevels))
    OR EXISTS (SELECT 1 FROM teaching_assignments s2 WHERE s2.user_id=u.id AND s2.year_level IN ($hsLevels))
    OR TRIM(COALESCE(u.year_level,'')) IN ($hsLevels)
    OR LOWER(TRIM(COALESCE(u.year_level,''))) IN ('jhs','shs','junior high school','senior high school')
    OR LOWER(TRIM(COALESCE(u.education_level,''))) IN ('junior_high','senior_high','jhs','shs')
)";
$anyTeaching = "(
    EXISTS (SELECT 1 FROM user_year_levels a1 WHERE a1.user_id=u.id)
    OR EXISTS (SELECT 1 FROM teaching_assignments a2 WHERE a2.user_id=u.id)
    OR TRIM(COALESCE(u.year_level,'')) <> ''
    OR LOWER(TRIM(COALESCE(u.education_level,''))) IN ('junior_high','senior_high','jhs','shs')
)";
$scope = "((
    (u.role IN ('teacher','faculty') AND $hsScope)
    OR (u.role='staff' AND ($hsScope OR NOT $anyTeaching))
) AND u.role NOT IN ('principal','dean','superadmin') AND u.id <> $meId)";

$eaRolesSql = "'ea','executive_assistant','admin','superadmin'";
$eaTargetPredicate = "(u.role IN ($eaRolesSql) OR LOWER(TRIM(COALESCE(u.designation,'')))='executive assistant')";
if ($activeEval === 'staff') {
    $whereRole = $eaTargetPredicate;
    $scope = "(u.is_active=1 AND $eaTargetPredicate AND u.role NOT IN ('principal','dean') AND u.id <> $meId)";
} else {
    switch ($group) {
        case 'Faculty':
        case 'Teacher':
            $whereRole = "((u.role IN ('teacher','faculty') OR u.sector='Teacher' OR $anyTeaching) AND $hsScope)";
            break;
        case 'Staff':
            $whereRole = "(u.role='staff' AND NOT $anyTeaching)";
            break;
        default:
            $whereRole = "u.role IN ('teacher','faculty','staff')";
            break;
    }
}

$hsTeacherIds = "SELECT ht.id FROM users ht
    WHERE ht.role IN ('teacher','faculty','staff')
      AND (
          EXISTS (SELECT 1 FROM user_year_levels h1 WHERE h1.user_id=ht.id AND h1.year_level IN ($hsLevels))
          OR EXISTS (SELECT 1 FROM teaching_assignments h2 WHERE h2.user_id=ht.id AND h2.year_level IN ($hsLevels))
          OR TRIM(COALESCE(ht.year_level,'')) IN ($hsLevels)
          OR LOWER(TRIM(COALESCE(ht.year_level,''))) IN ('jhs','shs','junior high school','senior high school')
          OR LOWER(TRIM(COALESCE(ht.education_level,''))) IN ('junior_high','senior_high','jhs','shs')
      )";
// Peer evaluators must be JHS/SHS teachers too (same rule as principal_reports.php).
require_once __DIR__ . '/principal_hs_scope.php';
$peerEvaluatorIds = "SELECT pe.id FROM users pe
    WHERE pe.role IN ('teacher','faculty','staff') AND " . principal_hs_scope_sql('pe');
$peerStaffLaneIds = "SELECT sx.id FROM users sx WHERE sx.role='staff'
    AND NOT EXISTS (SELECT 1 FROM user_year_levels sxy WHERE sxy.user_id=sx.id)
    AND NOT EXISTS (SELECT 1 FROM teaching_assignments sxt WHERE sxt.user_id=sx.id)";
$peerTargetIds = $group === 'Staff'
    ? $peerStaffLaneIds
    : "SELECT fx.id FROM users fx WHERE fx.id IN ($hsTeacherIds) AND fx.id NOT IN ($peerStaffLaneIds)";
$nonTeachingStaffIds = "SELECT nts.id FROM users nts WHERE nts.role='staff'
    AND NOT EXISTS (SELECT 1 FROM user_year_levels ntyl WHERE ntyl.user_id=nts.id)";
$eaTargetIds = "SELECT eat.id FROM users eat WHERE eat.role IN ($eaRolesSql)
    OR LOWER(TRIM(COALESCE(eat.designation,'')))='executive assistant'";

if ($activeEval === 'peer') {
    // Match the actual faculty/staff submit flow: peer submissions are stored
    // as faculty_peer; the other values remain supported for legacy data.
    $evalClause = "(
            et.eval_type IN ('peer','faculty_peer','staff_peer')
            OR LOWER(TRIM(COALESCE(et.peer_group,''))) IN ('faculty','teacher','staff','teaching staff')
        )
        AND et.status IN ('submitted','approved')
        AND et.evaluator_id IN ($peerEvaluatorIds)
        AND et.target_user_id IN ($peerTargetIds)";
    // The lane's target list is authoritative for who is evaluated.
    $whereRole = "u.role IN ('teacher','faculty','staff')";
} elseif ($activeEval === 'staff') {
    // Non-teaching Staff -> Executive Assistant submissions use eval_type='staff'
    // and peer_group='Staff Evaluation'; retain the app's known legacy values.
    $evalClause = "(
            et.eval_type='staff'
            OR LOWER(TRIM(COALESCE(et.peer_group,'')))='staff evaluation'
        )
        AND et.status IN ('submitted','approved')
        AND et.evaluator_id IN ($nonTeachingStaffIds)
        AND et.target_user_id IN ($eaTargetIds)";
} else {
    // Must stay identical to $principalStudentEvaluatorSql in principal_reports.php.
    $evalClause = "et.eval_type='student'
        AND et.evaluator_id IN (
            SELECT id FROM users
            WHERE role='student'
              AND is_active=1
              AND COALESCE(education_level,'') <> 'higher_ed'
              AND (
                  education_level IN ('junior_high','senior_high','basic_education','basic_ed','jhs','shs')
                  OR year_level IN ($hsLevels)
              )
        )
        AND COALESCE(et.evaluation_context,'teacher') IN ('teacher','staff')";
}

// Use the configured period while it contains matching data; otherwise fall
// back to the latest period with a completed matching submission. This covers
// differences between system settings and evaluation_periods.is_active.
$queryPeriodId = $periodId;
$activeHasData = false;
if ($periodId > 0) {
    $q = $mysqli->query("SELECT 1 FROM evaluation_tracker et WHERE et.period_id=$periodId AND $evalClause LIMIT 1");
    $activeHasData = $q && $q->num_rows > 0;
}
if (!$activeHasData && ($activeEval !== 'student' || (function_exists('sh_gate_applicable_role') && sh_gate_applicable_role($settings) !== 'principal'))) {
    $q = $mysqli->query("SELECT et.period_id FROM evaluation_tracker et
        WHERE et.period_id IS NOT NULL AND $evalClause
        ORDER BY et.submitted_at DESC, et.id DESC LIMIT 1");
    $latest = $q ? $q->fetch_assoc() : null;
    if ($latest && (int)$latest['period_id'] > 0) $queryPeriodId = (int)$latest['period_id'];
}

// Peer-to-Peer records are static across all periods (see principal_reports.php).
$staticPeriod = ($activeEval === 'peer');
if ($queryPeriodId <= 0 && !$staticPeriod) {
    echo json_encode([
        'signature' => 'no-period',
        'period_id' => 0,
        'total_responses' => 0,
        'evaluated_targets' => 0,
        'latest_submission' => null,
        'generated_at' => date('c'),
    ]);
    exit;
}


$sql = "SELECT
            COUNT(DISTINCT et.id) AS total_responses,
            COUNT(DISTINCT et.target_user_id) AS evaluated_targets,
            COALESCE(MAX(et.id),0) AS max_tracker_id,
            MAX(et.submitted_at) AS latest_submission,
            COALESCE(COUNT(qa.id),0) AS answer_count,
            COALESCE(SUM(qa.answer_score),0) AS score_sum
        FROM evaluation_tracker et
        JOIN users u ON u.id=et.target_user_id
        LEFT JOIN questionnaire_answers qa ON qa.tracker_id=et.id
        WHERE " . ($staticPeriod ? '1=1' : "et.period_id=$queryPeriodId") . "
          AND $evalClause
          AND $whereRole
          AND u.is_active=1
          AND $scope";

$res = $mysqli->query($sql);
$row = $res ? ($res->fetch_assoc() ?: []) : [];

$signatureParts = [
    (int)($row['total_responses'] ?? 0),
    (int)($row['evaluated_targets'] ?? 0),
    (int)($row['max_tracker_id'] ?? 0),
    (string)($row['latest_submission'] ?? ''),
    (int)($row['answer_count'] ?? 0),
    number_format((float)($row['score_sum'] ?? 0), 4, '.', ''),
];

$mysqli->close();
echo json_encode([
    'signature' => implode('|', $signatureParts),
    'period_id' => $queryPeriodId,
    'total_responses' => (int)($row['total_responses'] ?? 0),
    'evaluated_targets' => (int)($row['evaluated_targets'] ?? 0),
    'latest_submission' => $row['latest_submission'] ?? null,
    'generated_at' => date('c'),
]);
