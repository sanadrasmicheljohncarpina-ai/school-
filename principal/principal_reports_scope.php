<?php
// principal/principal_reports_scope.php
//
// Single source of truth for the Principal "Evaluation Reports" data scope.
// It is included by BOTH principal_reports.php and principal_reports_live_api.php
// so the report page and its live-update signature can never drift apart
// (previously each file carried its own copy of the scope rules and they had
// already diverged: different evaluator scope, different target scope, no
// period/status filter on the page).
//
// The including script must define these BEFORE requiring this file:
//   $mysqli         open mysqli connection
//   $myUserId       (int) logged-in principal's users.id
//   $period_id_int  (int) active evaluation period id, 0 when none is open
//
// It defines:
//   $principalHighSchoolLevelsSql   quoted JHS/SHS grade labels
//   $reportScopeSql                 who may appear as a report TARGET (u.*)
//   $principalStudentEvaluatorSql   which students count as EVALUATORS (et.*)
//   $periodSql / $statusSql         current period + counted statuses (et.*)
//   $studentEvalSql / $peerEvalSql  full tracker filter for each report tab

if (!isset($mysqli, $myUserId, $period_id_int)) {
    http_response_code(500);
    exit('principal_reports_scope.php: missing required context.');
}
$myUserId      = (int)$myUserId;
$period_id_int = (int)$period_id_int;

// ── CONFIDENTIALITY BOUNDARY (do not remove) ─────────────────────────
// Principal Reports is limited to Basic Education. Faculty/Teaching Staff are
// included only when they have a JHS/SHS teaching scope; genuine Non-Teaching
// Staff remain eligible even when they have no grade assignment. College-only
// teaching personnel are excluded from this portal.
$principalHighSchoolLevelsSql = "'Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12','Grade 7 - JHS','Grade 8 - JHS','Grade 9 - JHS','Grade 10 - JHS','Grade 11 - SHS','Grade 12 - SHS'";
$principalHighSchoolScopeSql = "(
    EXISTS (SELECT 1 FROM user_year_levels scope_uyl WHERE scope_uyl.user_id=u.id AND scope_uyl.year_level IN ($principalHighSchoolLevelsSql))
    OR EXISTS (SELECT 1 FROM teaching_assignments scope_ta WHERE scope_ta.user_id=u.id AND scope_ta.year_level IN ($principalHighSchoolLevelsSql))
)";
$principalAnyTeachingScopeSql = "(
    EXISTS (SELECT 1 FROM user_year_levels any_uyl WHERE any_uyl.user_id=u.id)
    OR EXISTS (SELECT 1 FROM teaching_assignments any_ta WHERE any_ta.user_id=u.id)
)";
$reportScopeSql = "(
    (u.role IN ('teacher','faculty') AND $principalHighSchoolScopeSql)
    OR
    (u.role='staff' AND (
        $principalHighSchoolScopeSql
        OR NOT $principalAnyTeachingScopeSql
    ))
)";
// Hard, redundant exclusion: even if the role list above is ever loosened,
// no Principal, Dean, EA or superadmin — and never the logged-in Principal —
// can appear as a report target.
$reportScopeSql = "($reportScopeSql) AND u.role NOT IN ('principal','dean','superadmin') AND u.id <> $myUserId";

// Principal Student Evaluation reports only use active JHS/SHS student evaluators.
$principalStudentEvaluatorSql = "et.evaluator_id IN (
    SELECT id FROM users
    WHERE role='student'
      AND is_active=1
      AND (
          education_level IN ('junior_high','senior_high','basic_education','jhs','shs')
          OR year_level IN ($principalHighSchoolLevelsSql)
      )
)";

// Only the active evaluation period is reported (no period => empty report),
// and only finished evaluations count. The other portal pages (Results,
// Evaluations) already apply the same status rule; drafts / in-progress rows
// must never leak into scores.
$periodSql = $period_id_int > 0 ? "et.period_id=" . $period_id_int : "1=0";
$statusSql = "et.status IN ('submitted','approved')";

$studentEvalSql = "$periodSql AND $statusSql AND et.eval_type='student' AND $principalStudentEvaluatorSql AND COALESCE(et.evaluation_context,'teacher') IN ('teacher','staff')";
$peerEvalSql    = "$periodSql AND $statusSql AND et.eval_type IN ('peer','faculty_peer','staff_peer')";
