<?php
// dean_reports_live_api.php
// Lightweight live-data signature for Dean Reports & Analytics.
session_start();
require_once 'db.php';
require_once '../shared/system_settings_service.php';
require_once __DIR__ . '/dean_period_resolver.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'dean') {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}
// Release the session lock right away so this poll never blocks the Dean's
// other requests (session files are locked for the whole request otherwise).
session_write_close();

$settings = get_system_settings($mysqli);
// Same set of Higher Ed periods the page itself uses.
$periodIds = dean_higher_ed_period_ids($mysqli, $settings);
$periodId  = $periodIds ? (int)max($periodIds) : 0;
$periodWhere = dean_period_sql($periodIds, 'et.period_id');
$activeEval = $_GET['eval_type'] ?? 'student';
if (!in_array($activeEval, ['student','peer'], true)) $activeEval = 'student';
$group = $_GET['group'] ?? 'All';
if ($activeEval === 'student' && $group === 'Principal') $group = 'All';
// Peer-to-Peer is College teachers only and has no Faculty/Staff split.
if ($activeEval === 'peer') $group = 'All';

$collegeLevels = "'1st Year College','2nd Year College','3rd Year College','4th Year College'";
$evaluatorClause = "et.evaluator_id IN (
    SELECT id FROM users
    WHERE role='student'
      AND (education_level='higher_ed' OR year_level IN ($collegeLevels))
)";

$scope = "(u.role='principal' OR u.role='staff' OR
    (u.role IN ('teacher','faculty') AND EXISTS (
        SELECT 1 FROM user_year_levels scope_uyl
        WHERE scope_uyl.user_id=u.id
          AND scope_uyl.year_level IN ($collegeLevels)
    )))";

// Match the main Dean Reports classification rules. Student Evaluation
// Faculty/Staff tabs are based on personnel function, not the tracker's
// legacy/default evaluation_context.
$teachingFunctionSql = "(
    u.role IN ('teacher','faculty')
    OR u.sector='Teacher'
    OR EXISTS (SELECT 1 FROM teaching_assignments ta WHERE ta.user_id=u.id)
    OR EXISTS (SELECT 1 FROM user_year_levels yl WHERE yl.user_id=u.id)
)";
$nonTeachingStaffSql = "(
    u.role='staff'
    AND COALESCE(u.sector,'') <> 'Teacher'
    AND NOT EXISTS (SELECT 1 FROM teaching_assignments ta WHERE ta.user_id=u.id)
    AND NOT EXISTS (SELECT 1 FROM user_year_levels yl WHERE yl.user_id=u.id)
)";

switch ($group) {
    case 'Faculty':
    case 'Teacher':
        $whereRole = $activeEval === 'student'
            ? $teachingFunctionSql
            : "u.role IN ('teacher','faculty')";
        break;
    case 'Staff':
        $whereRole = $activeEval === 'student'
            ? $nonTeachingStaffSql
            : "u.role='staff'";
        break;
    case 'Principal':
        $whereRole = $activeEval === 'student'
            ? $teachingFunctionSql
            : "u.role='principal'";
        break;
    default:
        $whereRole = $activeEval === 'student'
            ? "u.role IN ('teacher','staff','faculty')"
            : "u.role IN ('teacher','staff','faculty')"; // peer targets are further limited to College teachers below
        break;
}

if ($periodId <= 0) {
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

if ($activeEval === 'peer') {
    // Must stay identical to $peerEvalSql in dean_reports.php: both the evaluator
    // and the evaluated person are College teachers.
    $collegeTeacherIds = "SELECT ct.id FROM users ct
        WHERE ct.role IN ('teacher','faculty','staff')
          AND EXISTS (SELECT 1 FROM user_year_levels ctyl
                      WHERE ctyl.user_id=ct.id AND ctyl.year_level IN ($collegeLevels))";
    $evalClause = "et.eval_type IN ('peer','faculty_peer','staff_peer')
        AND et.evaluator_id IN ($collegeTeacherIds)
        AND et.target_user_id IN ($collegeTeacherIds)";
} else {
    $evalClause = "et.eval_type='student' AND $evaluatorClause AND (
        COALESCE(et.evaluation_context,'teacher') IN ('teacher','staff')
    )";
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
        WHERE $periodWhere
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
    'period_id' => $periodId,
    'total_responses' => (int)($row['total_responses'] ?? 0),
    'evaluated_targets' => (int)($row['evaluated_targets'] ?? 0),
    'latest_submission' => $row['latest_submission'] ?? null,
    'generated_at' => date('c'),
]);