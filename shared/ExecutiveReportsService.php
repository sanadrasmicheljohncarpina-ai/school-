<?php
/**
 * Shared Reports & Analytics logic for Dean and Principal portals.
 *
 * Mirrors the EA Reports & Analytics evaluation separation:
 *   Student Evaluation  -> Teacher/Staff contexts only
 *   Multi-Role          -> Multi-Role context only
 *   Peer-to-Peer        -> peer submissions (legacy `peer`, `faculty_peer`, or `staff_peer`)
 *
 * PERIOD BEHAVIOR:
 *   As in the EA Reports & Analytics page, stored submitted/approved results
 *   are reported without an additional period_id WHERE clause. This ensures
 *   evaluations already submitted before a term/status switch remain visible
 *   to the executive reports instead of suddenly disappearing.
 *
 * CONFIDENTIALITY:
 *   Executive-direct evaluations (target role = dean/principal) are never
 *   included in these portal reports, and evaluator identities are never
 *   rendered. These reports are target-performance reports only.
 */

if (!function_exists('ea_reports_context_sql')) {
    function ea_reports_context_sql(mysqli $mysqli, string $mode, string $alias = 'et'): string {
        if ($mode === 'multi_role') {
            return "$alias.eval_type='student' AND (
                $alias.evaluation_context='multi_role'
                OR EXISTS (
                    SELECT 1
                    FROM questionnaire_answers qam
                    JOIN user_questions uqm ON uqm.id = qam.user_question_id
                    WHERE qam.tracker_id = $alias.id
                      AND uqm.target_type='Multi-Role'
                      AND uqm.eval_type='student'
                )
            )";
        }
        if ($mode === 'peer') {
            return "$alias.eval_type IN ('peer','faculty_peer','staff_peer')";
        }
        return "$alias.eval_type='student'
            AND COALESCE($alias.evaluation_context,'teacher') IN ('teacher','staff')";
    }

    function ea_reports_multi_role(array $u): bool {
        if (function_exists('ec_has_additional_role')) {
            return ec_has_additional_role($u);
        }
        $secondary = strtolower(trim((string)($u['secondary_role'] ?? '')));
        if ($secondary !== '' && !in_array($secondary, ['teacher','staff'], true)) return true;
        $d = strtolower(trim((string)($u['designation'] ?? '')));
        if ($d === '') return false;
        if (count(preg_split('/\s*[,\/|;]+\s*/', $d)) > 1) return true;
        foreach (['coordinator','head','manager','officer','director','supervisor','manager','adviser','advisor','chair','lead','program','sports','sdrmm'] as $marker) {
            if (strpos($d, $marker) !== false) return true;
        }
        return false;
    }

    function ea_reports_people(mysqli $mysqli, string $mode, string $scopeSql, string $scopeTypes = '', array $scopeParams = [], int $periodId = 0): array {
        $ctx = ea_reports_context_sql($mysqli, $mode, 'et');
        $sql = "
            SELECT u.id, u.full_name, u.photo, u.designation, u.department,
                   u.role, u.secondary_role,
                   COUNT(DISTINCT et.id) AS total_responses,
                   AVG(qa.answer_score) AS avg_score
            FROM users u
            JOIN evaluation_tracker et
              ON et.target_user_id=u.id
             AND $ctx
             AND et.status IN ('submitted','approved')
            LEFT JOIN questionnaire_answers qa ON qa.tracker_id=et.id
            WHERE u.role IN ('teacher','staff')
              AND u.is_active=1
              AND u.account_status='approved'
              AND u.id NOT IN (SELECT id FROM users WHERE role IN ('dean','principal'))
              AND $scopeSql
            GROUP BY u.id
            ORDER BY avg_score DESC, u.full_name ASC
        ";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) return [];        if ($scopeTypes !== '') $stmt->bind_param($scopeTypes, ...$scopeParams);
        if (!$stmt->execute()) { $stmt->close(); return []; }
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        if ($mode === 'multi_role') {
            $rows = array_values(array_filter($rows, 'ea_reports_multi_role'));
        } else {
            // Base Student / Peer tabs must not leak a person's Multi-Role
            // evaluation into their ordinary Staff/Teacher result.
            // Their target context is already separated by evaluation_context.
        }
        return $rows;
    }

    function ea_reports_counts(mysqli $mysqli, string $mode, string $scopeSql, string $scopeTypes = '', array $scopeParams = [], int $periodId = 0): array {
        $ctx = ea_reports_context_sql($mysqli, $mode, 'et');
        $sql = "
            SELECT
                COUNT(DISTINCT et.id) AS responses,
                COUNT(DISTINCT et.evaluator_id) AS evaluators,
                COUNT(DISTINCT et.target_user_id) AS evaluated_people,
                AVG(qa.answer_score) AS avg_score
            FROM evaluation_tracker et
            JOIN users target ON target.id=et.target_user_id
            LEFT JOIN questionnaire_answers qa ON qa.tracker_id=et.id
            WHERE $ctx
              AND et.status IN ('submitted','approved')
              AND target.role IN ('teacher','staff')
              AND target.id NOT IN (SELECT id FROM users WHERE role IN ('dean','principal'))
              AND $scopeSql
        ";
        $stmt=$mysqli->prepare($sql);
        if(!$stmt) return ['responses'=>0,'evaluators'=>0,'evaluated_people'=>0,'avg_score'=>null];        if($scopeTypes!=='') $stmt->bind_param($scopeTypes,...$scopeParams);
        if(!$stmt->execute()){ $stmt->close(); return ['responses'=>0,'evaluators'=>0,'evaluated_people'=>0,'avg_score'=>null]; }
        $row=$stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return [
            'responses'=>(int)($row['responses']??0),
            'evaluators'=>(int)($row['evaluators']??0),
            'evaluated_people'=>(int)($row['evaluated_people']??0),
            'avg_score'=>$row['avg_score']!==null ? round((float)$row['avg_score'],2) : null
        ];
    }
}
?>
