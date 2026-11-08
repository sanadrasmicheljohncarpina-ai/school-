<?php
/**
 * Shared School Head Evaluation assignment service.
 *
 * The EA configures evaluator-specific target/question assignments here.
 * This table is the single source of truth consumed by Dean/Principal
 * evaluation pages.
 */

function sh_ensure_assignment_table(mysqli $mysqli): void {
    $mysqli->query("CREATE TABLE IF NOT EXISTS school_head_evaluation_assignments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        evaluator_id INT UNSIGNED NOT NULL,
        target_user_id INT UNSIGNED NOT NULL,
        question_id INT UNSIGNED NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_sh_assignment (evaluator_id, target_user_id, question_id),
        KEY idx_sh_evaluator_target (evaluator_id, target_user_id),
        KEY idx_sh_target (target_user_id),
        KEY idx_sh_question (question_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function sh_is_evaluator(mysqli $mysqli, int $evaluatorId): bool {
    $stmt = $mysqli->prepare("SELECT 1 FROM users WHERE id=? AND role IN ('dean','principal') AND is_active=1 AND account_status='approved' LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $evaluatorId);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

function sh_is_valid_target(mysqli $mysqli, int $targetId): bool {
    $stmt = $mysqli->prepare("SELECT id, role, is_active, account_status FROM users WHERE id=? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $targetId);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$u || empty($u['is_active']) || ($u['account_status'] ?? '') !== 'approved') return false;

    // Must match dean_evaluation.php's roster query exactly: every active,
    // approved Teacher or Staff account (Faculty tab), plus the single EA
    // (superadmin) account — everyone the Dean sees on the roster must be a
    // valid evaluation target. Whether a Staff member has a teaching
    // assignment/year level only affects their "Teaching Staff" vs "Staff"
    // label elsewhere; it is not an eligibility requirement here.
    $role = strtolower((string)$u['role']);
    return in_array($role, ['superadmin', 'teacher', 'staff'], true);
}

function sh_target_type(mysqli $mysqli, int $targetId): ?string {
    $stmt = $mysqli->prepare("SELECT role FROM users WHERE id=? AND is_active=1 AND account_status='approved' LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $targetId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;

    $role = strtolower((string)$row['role']);
    if ($role === 'superadmin') return 'EA';
    if ($role === 'teacher') return 'Faculty';
    if ($role === 'staff' && sh_is_valid_target($mysqli, $targetId)) return 'Faculty';
    return null;
}

/** Return only target people with at least one currently assigned question. */
function sh_get_assigned_targets(mysqli $mysqli, int $evaluatorId): array {
    sh_ensure_assignment_table($mysqli);
    if (!sh_is_evaluator($mysqli, $evaluatorId)) return [];

    $stmt = $mysqli->prepare("SELECT
        u.id, u.full_name, u.photo, u.department, u.designation, u.role, u.secondary_role,
        CASE WHEN u.role='superadmin' THEN 'EA' ELSE 'Faculty' END AS target_group,
        COUNT(DISTINCT a.question_id) AS assigned_questions
      FROM school_head_evaluation_assignments a
      JOIN users u ON u.id=a.target_user_id
      JOIN evaluation_questions q ON q.id=a.question_id AND q.eval_type='school_head'
      WHERE a.evaluator_id=?
        AND u.is_active=1 AND u.account_status='approved'
        AND q.target_type IN ('Faculty','EA')
      GROUP BY u.id
      HAVING COUNT(DISTINCT a.question_id) > 0
      ORDER BY target_group, u.full_name");
    if (!$stmt) return [];
    $stmt->bind_param('i', $evaluatorId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$row) {
        $row['role_label'] = ($row['role'] === 'staff') ? 'Teaching Staff' : (($row['role'] === 'superadmin') ? 'Executive Assistant' : 'Faculty');
        if ($row['role'] === 'staff') $row['target_group'] = 'Faculty';
        $row['assigned_questions'] = (int)$row['assigned_questions'];
    }
    unset($row);
    return $rows;
}

function sh_get_assigned_questions(mysqli $mysqli, int $evaluatorId, int $targetId): array {
    sh_ensure_assignment_table($mysqli);
    if (!sh_is_evaluator($mysqli, $evaluatorId) || !sh_is_valid_target($mysqli, $targetId)) return [];

    $stmt = $mysqli->prepare("SELECT DISTINCT q.id, q.target_type, q.category, q.question_text
        FROM school_head_evaluation_assignments a
        JOIN evaluation_questions q ON q.id=a.question_id
        WHERE a.evaluator_id=? AND a.target_user_id=? AND q.eval_type='school_head'
          AND q.target_type IN ('Faculty','EA')
        ORDER BY q.category, q.id");
    if (!$stmt) return [];
    $stmt->bind_param('ii', $evaluatorId, $targetId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function sh_get_assignment(mysqli $mysqli, int $evaluatorId, int $targetId): ?array {
    if (!sh_is_evaluator($mysqli, $evaluatorId) || !sh_is_valid_target($mysqli, $targetId)) return null;
    $stmt = $mysqli->prepare("SELECT id, full_name, photo, department, designation, role, secondary_role
        FROM users WHERE id=? AND is_active=1 AND account_status='approved' LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $targetId);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$target) return null;

    // A target with no questions assigned yet is still a valid, evaluable
    // target — the Dean can see this person on the roster, so opening their
    // evaluate page must not 403. An empty $questions array here just means
    // the EA hasn't assigned any School Head Evaluation questions to this
    // person yet; the caller shows a "nothing to rate yet" notice for that,
    // it does not treat it as "not authorized".
    $questions = sh_get_assigned_questions($mysqli, $evaluatorId, $targetId);
    $target['target_group'] = ($target['role'] === 'superadmin') ? 'EA' : 'Faculty';
    $target['role_label'] = ($target['role'] === 'staff') ? 'Teaching Staff' : (($target['role'] === 'superadmin') ? 'Executive Assistant' : 'Faculty');
    $target['questions'] = $questions;
    return $target;
}

function sh_save_assignment(mysqli $mysqli, int $evaluatorId, int $targetId, array $questionIds): array {
    sh_ensure_assignment_table($mysqli);
    if (!sh_is_evaluator($mysqli, $evaluatorId)) return [false, 'Invalid School Head evaluator.'];
    if (!sh_is_valid_target($mysqli, $targetId)) return [false, 'Invalid School Head evaluation target.'];

    $targetType = sh_target_type($mysqli, $targetId);
    if ($targetType === null) return [false, 'This person is not an eligible School Head Evaluation target.'];

    $cleanIds = array_values(array_unique(array_filter(array_map('intval', $questionIds), fn($v) => $v > 0)));
    if ($cleanIds) {
        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $types = 's' . str_repeat('i', count($cleanIds));
        $params = array_merge([$targetType], $cleanIds);
        $stmt = $mysqli->prepare("SELECT id FROM evaluation_questions WHERE eval_type='school_head' AND target_type=? AND id IN ($placeholders)");
        if (!$stmt) return [false, 'Unable to validate the selected questions.'];
        $bindArgs = [$types];
        foreach ($params as $idx => $value) {
            $bindArgs[] = &$params[$idx];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindArgs);
        $stmt->execute();
        $valid = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $validIds = array_map('intval', array_column($valid, 'id'));
        if (count($validIds) !== count($cleanIds)) return [false, 'One or more selected questions are not valid for this target.'];
    } else {
        $validIds = [];
    }

    $mysqli->begin_transaction();
    try {
        $del = $mysqli->prepare("DELETE FROM school_head_evaluation_assignments WHERE evaluator_id=? AND target_user_id=?");
        if (!$del) throw new RuntimeException('Unable to replace assignment.');
        $del->bind_param('ii', $evaluatorId, $targetId);
        $del->execute();
        $del->close();

        if ($validIds) {
            $ins = $mysqli->prepare("INSERT INTO school_head_evaluation_assignments (evaluator_id,target_user_id,question_id) VALUES (?,?,?)");
            if (!$ins) throw new RuntimeException('Unable to save assignment.');
            foreach ($validIds as $qid) {
                $ins->bind_param('iii', $evaluatorId, $targetId, $qid);
                $ins->execute();
            }
            $ins->close();
        }
        $mysqli->commit();
        return [true, count($validIds).' question'.(count($validIds) === 1 ? '' : 's').' assigned.'];
    } catch (Throwable $e) {
        $mysqli->rollback();
        return [false, 'Unable to save the assignment.'];
    }
}
