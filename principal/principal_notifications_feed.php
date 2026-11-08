<?php
/* =========================================================================
   principal_notifications_feed.php
   -------------------------------------------------------------------------
   Single source of truth for the Principal's notification list.

   Used by:
     principal_dashboard.php  — initial server-side render of the bell
     principal_notifications.php — JSON endpoint polled by the bell for
                                   real-time updates

   Both paths MUST call the same function so the badge count can never drift
   from what the dropdown shows. Do not duplicate notification rules in the
   dashboard again.
   ========================================================================= */

// Academic Structure / Academic Term gate — required here (not just at the
// call sites) because principal_notifications.php builds the feed without
// passing a settings array of its own.
require_once __DIR__ . '/school_head_structure_gate.php';

// ── SAFE QUERY HELPERS (never fatal-error on a schema mismatch) ─────────
// Guarded with function_exists so this file can be included alongside a page
// that already declares them.
if (!function_exists('safe_scalar')) {
    function safe_scalar(mysqli $mysqli, string $sql, string $types = '', array $params = []) {
        try {
            $stmt = @$mysqli->prepare($sql);
            if (!$stmt) return null;
            if ($types !== '') { $stmt->bind_param($types, ...$params); }
            if (!@$stmt->execute()) { $stmt->close(); return null; }
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            return $row ? reset($row) : null;
        } catch (mysqli_sql_exception $e) {
            return null;
        }
    }
}
if (!function_exists('esc_list')) {
    function esc_list(mysqli $mysqli, array $vals): string {
        if (empty($vals)) return "''";
        return "'" . implode("','", array_map([$mysqli, 'real_escape_string'], $vals)) . "'";
    }
}

/**
 * Resolve the Principal's Basic Ed scope from their education_level.
 *
 * @return array{levels: string[], grades: string[], label: string}
 */
function principal_scope(?string $educationLevel): array {
    $lvl = $educationLevel ?: 'both';
    if ($lvl === 'junior_high') {
        return ['levels' => ['junior_high'], 'grades' => ['7','8','9','10'], 'label' => 'Junior High School'];
    }
    if ($lvl === 'senior_high') {
        return ['levels' => ['senior_high'], 'grades' => ['11','12'], 'label' => 'Senior High School'];
    }
    return [
        'levels' => ['junior_high','senior_high'],
        'grades' => ['7','8','9','10','11','12'],
        'label'  => 'Junior High & Senior High',
    ];
}

/**
 * Build the Principal's notification list.
 *
 * Every item carries a STABLE id: the bell uses it to tell "already seen"
 * from "new since last poll". Ids must therefore be derived from the
 * notification's meaning, never from the current time or array position —
 * a time-based id would make every item look new on every poll.
 *
 * @return array<int, array{id:string, text:string, level:string}>
 */
function principal_build_notifications(mysqli $mysqli, int $userId, ?array $settings = null): array {
    if ($settings === null) {
        $settings = get_system_settings($mysqli);
    }

    // Academic Structure / Academic Term gate (narrow-only). Applied here
    // as well as at the call sites so the feed is correct no matter which
    // settings array a caller hands it.
    if (function_exists('sh_gate_apply')) {
        $settings = sh_gate_apply($settings, 'principal');
    }

    $edu = safe_scalar($mysqli, "SELECT education_level FROM users WHERE id=? LIMIT 1", "i", [$userId]);
    $scope           = principal_scope(is_string($edu) ? $edu : null);
    $scopeAcademicIn = esc_list($mysqli, $scope['levels']);
    $scopeGradesIn   = esc_list($mysqli, $scope['grades']);

    $structureActive = array_key_exists('school_head_applicable', $settings)
        ? !empty($settings['school_head_applicable'])
        : ss_school_head_role_applicable($settings, 'principal');
    $period_id_int   = (int)($settings['period_id'] ?? 0);
    $hasPeriod       = $period_id_int > 0;

    $daysRemaining = null;
    if (!empty($settings['eval_end'])) {
        $daysRemaining = (int)ceil((strtotime($settings['eval_end']) - strtotime(date('Y-m-d'))) / 86400);
    }

    $out = [];

    // Schedule-driven notices straight from the shared service.
    foreach ((array)($settings['notifications'] ?? []) as $n) {
        if (!is_string($n) || $n === '') continue;
        $out[] = ['id' => 'sys-' . substr(md5($n), 0, 10), 'text' => $n, 'level' => 'info'];
    }

    // Executive Assistant notices: academic period and evaluation schedule.
    // The id includes the announced values, so a change by the EA produces a
    // new id (fresh unread notice) while unchanged settings are not re-announced.
    if ($hasPeriod) {
        $eaYear = trim((string)($settings['academic_year'] ?? ''));
        $eaTerm = trim((string)($settings['academic_term'] ?? ''));
        if ($eaYear !== '') {
            $eaPeriod = $eaTerm !== '' ? $eaYear . ' · ' . $eaTerm : $eaYear;
            $out[] = [
                'id'    => 'ea-period-' . substr(md5($eaPeriod), 0, 10),
                'text'  => 'The Executive Assistant set the academic period to ' . $eaPeriod . '.',
                'level' => 'info',
                'icon'  => 'fa-calendar-days',
            ];
        }
        $eaStart = trim((string)($settings['eval_start_display'] ?? ''));
        $eaEnd   = trim((string)($settings['eval_end_display'] ?? ''));
        if ($eaStart !== '' && $eaEnd !== '') {
            $eaRange = $eaStart . ' – ' . $eaEnd;
            $out[] = [
                'id'    => 'ea-schedule-' . substr(md5($eaRange), 0, 10),
                'text'  => 'The Executive Assistant set the evaluation schedule: ' . $eaRange . '.',
                'level' => 'info',
                'icon'  => 'fa-clock',
            ];
        }
    }

    if (!$structureActive) {
        $out[] = [
            'id'    => 'structure-inactive',
            'text'  => 'Basic Education is not the active academic structure right now.',
            'level' => 'warn',
        ];
        return $out;
    }

    $teacherCount = (int)(safe_scalar($mysqli, "
        SELECT COUNT(*) c FROM users
        WHERE role='teacher' AND is_active=1 AND account_status='approved'
          AND academic_level IN ($scopeAcademicIn)
    ") ?? 0);

    $staffCount = (int)(safe_scalar($mysqli, "
        SELECT COUNT(*) c FROM users
        WHERE role='staff' AND is_active=1 AND account_status='approved'
          AND academic_level IN ($scopeAcademicIn)
    ") ?? 0);

    $studentCount = (int)(safe_scalar($mysqli, "
        SELECT COUNT(*) c FROM users
        WHERE role='student' AND is_active=1 AND account_status='approved'
          AND grade_level IN ($scopeGradesIn)
    ") ?? 0);

    $studentsSubmitted = 0;
    $targetsEvaluated  = 0;
    if ($hasPeriod) {
        $studentsSubmitted = (int)(safe_scalar($mysqli, "
            SELECT COUNT(DISTINCT et.evaluator_id) c
            FROM evaluation_tracker et
            INNER JOIN users u ON u.id = et.evaluator_id
            WHERE et.eval_type='student' AND et.period_id=?
              AND u.role='student' AND u.grade_level IN ($scopeGradesIn)
        ", "i", [$period_id_int]) ?? 0);

        $targetsEvaluated = (int)(safe_scalar($mysqli, "
            SELECT COUNT(DISTINCT et.target_user_id) c
            FROM evaluation_tracker et
            INNER JOIN users u ON u.id = et.target_user_id
            WHERE et.eval_type='student' AND et.period_id=?
              AND u.role IN ('teacher','staff') AND u.academic_level IN ($scopeAcademicIn)
        ", "i", [$period_id_int]) ?? 0);
    }

    $totalTargets         = $teacherCount + $staffCount;
    $evaluationCompletion = $totalTargets > 0 ? (int)round($targetsEvaluated / $totalTargets * 100) : 0;

    if ($teacherCount === 0 && $staffCount === 0) {
        $out[] = [
            'id'    => 'no-roster',
            'text'  => 'No approved and active Teacher or Staff accounts found for your scope.',
            'level' => 'warn',
        ];
    }

    if (!$hasPeriod) {
        $out[] = [
            'id'    => 'no-period',
            'text'  => 'No active evaluation period yet — figures stay at 0% until one is opened.',
            'level' => 'info',
        ];
    }

    // Recent submitted evaluation events for the Principal bell.
    // Up to 500 are kept for the current period so older notices do not drop off
    // as new ones arrive.
    //
    // The Principal only receives submissions from JHS/SHS people:
    //   * Student Evaluation -> the evaluator must be an active JHS/SHS student
    //   * Peer-to-Peer       -> the evaluator AND the person evaluated must be
    //                           JHS/SHS teaching personnel
    // The person being evaluated must also be inside the Principal Reports
    // scope (JHS/SHS Faculty/Teaching Staff, or non-teaching Staff for student
    // evaluations), which is what lets each notice link straight to that
    // person's report. Evaluations whose target is the Principal themself are
    // intentionally NOT announced here. These rules mirror principal_reports.php.
    if ($hasPeriod) {
        try {
            $hsLevels = "'Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12','Grade 7 - JHS','Grade 8 - JHS','Grade 9 - JHS','Grade 10 - JHS','Grade 11 - SHS','Grade 12 - SHS'";
            $hsScope = static function (string $a) use ($hsLevels): string {
                return "(
                    EXISTS (SELECT 1 FROM user_year_levels {$a}_y1 WHERE {$a}_y1.user_id={$a}.id AND {$a}_y1.year_level IN ($hsLevels))
                    OR EXISTS (SELECT 1 FROM teaching_assignments {$a}_t1 WHERE {$a}_t1.user_id={$a}.id AND {$a}_t1.year_level IN ($hsLevels))
                    OR TRIM(COALESCE({$a}.year_level,'')) IN ($hsLevels)
                    OR LOWER(TRIM(COALESCE({$a}.year_level,''))) IN ('jhs','shs','junior high school','senior high school')
                    OR LOWER(TRIM(COALESCE({$a}.education_level,''))) IN ('junior_high','senior_high','jhs','shs')
                )";
            };
            $anyTeaching = "(
                EXISTS (SELECT 1 FROM user_year_levels tg_y WHERE tg_y.user_id=target.id)
                OR EXISTS (SELECT 1 FROM teaching_assignments tg_t WHERE tg_t.user_id=target.id)
                OR TRIM(COALESCE(target.year_level,'')) <> ''
                OR LOWER(TRIM(COALESCE(target.education_level,''))) IN ('junior_high','senior_high','jhs','shs')
            )";
            // Only notify about evaluations received by in-scope personnel.
            // Direct submissions whose target is the Principal (or another
            // school-head account) must never appear in this notification list.
            $targetScope = "(
                (target.role IN ('teacher','faculty') AND " . $hsScope('target') . ")
                OR (target.role='staff' AND (" . $hsScope('target') . " OR NOT $anyTeaching))
            ) AND target.id <> " . (int)$userId . " AND target.role NOT IN ('principal','dean')";
            $hsStudents = "SELECT id FROM users
                WHERE role='student' AND is_active=1
                  AND COALESCE(education_level,'') <> 'higher_ed'
                  AND (education_level IN ('junior_high','senior_high','basic_education','basic_ed','jhs','shs')
                       OR year_level IN ($hsLevels))";
            $hsTeachers = "SELECT ht.id FROM users ht
                WHERE ht.role IN ('teacher','faculty','staff') AND " . $hsScope('ht');
            // Evaluator side: tolerant JHS/SHS matching, same as principal_reports.php.
            require_once __DIR__ . '/principal_hs_scope.php';
            $hsPeerEvaluators = "SELECT pe.id FROM users pe
                WHERE pe.role IN ('teacher','faculty','staff') AND " . principal_hs_scope_sql('pe');

            // These two columns are added by the Reports page on older installs.
            // Detect them here so the bell can still report new submissions before
            // anyone has opened Reports & Analytics (a missing column previously
            // caused the entire event query to fail silently).
            $hasEvalContext = false;
            $contextCol = @$mysqli->query("SHOW COLUMNS FROM evaluation_tracker LIKE 'evaluation_context'");
            if ($contextCol) { $hasEvalContext = $contextCol->num_rows > 0; $contextCol->free(); }
            $hasPeerGroup = false;
            $peerCol = @$mysqli->query("SHOW COLUMNS FROM evaluation_tracker LIKE 'peer_group'");
            if ($peerCol) { $hasPeerGroup = $peerCol->num_rows > 0; $peerCol->free(); }

            $studentContextFilter = $hasEvalContext
                ? "AND COALESCE(et.evaluation_context,'teacher') IN ('teacher','staff')"
                : '';
            $directPrincipalContextFilter = $hasEvalContext
                ? "AND COALESCE(et.evaluation_context,'') <> 'school_head'"
                : '';
            $peerTypeFilter = "et.eval_type IN ('peer','faculty_peer','staff_peer')";
            if ($hasPeerGroup) {
                $peerTypeFilter = "($peerTypeFilter OR LOWER(TRIM(COALESCE(et.peer_group,''))) IN ('faculty','teacher','staff','teaching staff'))";
            }

            $recentSql = "SELECT et.id, et.eval_type, et.submitted_at, et.target_user_id,
                    target.full_name AS target_name,
                    (target.role='staff'
                     AND NOT EXISTS (SELECT 1 FROM user_year_levels sl_y WHERE sl_y.user_id=target.id)
                     AND NOT EXISTS (SELECT 1 FROM teaching_assignments sl_t WHERE sl_t.user_id=target.id)) AS staff_lane
                FROM evaluation_tracker et
                JOIN users target ON target.id=et.target_user_id
                WHERE et.period_id={$period_id_int}
                  AND et.submitted_at IS NOT NULL
                  AND et.status IN ('submitted','approved')
                  AND target.is_active=1
                  AND target.account_status='approved'
                  AND ($targetScope)
                  $directPrincipalContextFilter
                  AND (
                        (et.eval_type='student'
                         $studentContextFilter
                         AND et.evaluator_id IN ($hsStudents))
                     OR (($peerTypeFilter)
                         AND et.evaluator_id IN ($hsPeerEvaluators)
                         AND (et.target_user_id IN ($hsTeachers)
                              OR (target.role='staff'
                                  AND NOT EXISTS (SELECT 1 FROM user_year_levels pl_y WHERE pl_y.user_id=target.id)
                                  AND NOT EXISTS (SELECT 1 FROM teaching_assignments pl_t WHERE pl_t.user_id=target.id))))
                  )
                ORDER BY et.submitted_at DESC, et.id DESC
                LIMIT 500";
            $recent = $mysqli->query($recentSql);
            if ($recent) {
                while ($event = $recent->fetch_assoc()) {
                    $isPeer = ($event['eval_type'] ?? '') !== 'student';
                    $kind   = $isPeer ? 'Peer-to-Peer' : 'Student';
                    $out[] = [
                        'id' => 'evaluation-' . (int)$event['id'],
                        'text' => "New {$kind} evaluation received for " . ($event['target_name'] ?? 'personnel') . '.',
                        'level' => 'good',
                        'created_at' => $event['submitted_at'] ?? null,
                        // Opens that person's evaluator list in Reports & Analytics.
                        'url' => 'principal_reports.php?view=students&target_id=' . (int)$event['target_user_id']
                               . '&group=' . ($isPeer ? (!empty($event['staff_lane']) ? 'Staff' : 'Faculty') : 'All')
                               . '&eval_type=' . ($isPeer ? 'peer' : 'student'),
                    ];
                }
                $recent->free();
            }
        } catch (Throwable $e) {
            error_log('principal notification evaluation events: ' . $e->getMessage());
        }
    }

    if ($hasPeriod && $daysRemaining !== null && $daysRemaining >= 0 && $daysRemaining <= 3) {
        $out[] = [
            'id'    => 'closing-' . $daysRemaining,
            'text'  => "Evaluation closes in {$daysRemaining} day" . ($daysRemaining === 1 ? '' : 's') . '.',
            'level' => 'warn',
        ];
    }

    if ($hasPeriod) {
        $notSubmitted = $studentCount - $studentsSubmitted;
        if ($notSubmitted > 0) {
            // Id deliberately excludes the count, so the item does not
            // re-alert every time one more student submits.
            $out[] = [
                'id'    => 'students-pending',
                'text'  => "{$notSubmitted} student" . ($notSubmitted === 1 ? '' : 's') . ' have not submitted.',
                'level' => 'info',
            ];
        }
    }

    if ($totalTargets > 0 && $evaluationCompletion === 100) {
        $out[] = [
            'id'    => 'targets-complete',
            'text'  => 'Teacher and staff evaluations are complete.',
            'level' => 'good',
        ];
    }

    if (empty($out)) {
        $out[] = ['id' => 'all-clear', 'text' => 'No urgent items right now.', 'level' => 'good'];
    }

    return $out;
}