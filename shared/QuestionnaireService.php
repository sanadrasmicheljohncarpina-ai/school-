<?php
/**
 * Centralized questionnaire service.
 *
 * The Questionnaire feature is target-centric, not evaluator-centric.
 * Four canonical targets are supported:
 *   faculty | staff | school_head | ea
 *
 * The current database is kept backward compatible by storing the new
 * centralized question sets with eval_type='general'. Legacy rows remain
 * untouched for historical submissions and are only used by the one-time
 * migration below.
 */

if (!function_exists('qn_ensure_schema')) {
    function qn_ensure_schema(mysqli $mysqli): void {
        // evaluator_role was introduced by the previous Questionnaire build.
        // Keep this idempotent for older databases.
        foreach (['question_categories', 'evaluation_questions'] as $table) {
            $col = $mysqli->query("SHOW COLUMNS FROM `$table` LIKE 'evaluator_role'");
            if ($col && $col->num_rows === 0) {
                $mysqli->query("ALTER TABLE `$table` ADD COLUMN evaluator_role VARCHAR(20) NOT NULL DEFAULT 'shared'");
            }
        }

        $mysqli->query("CREATE TABLE IF NOT EXISTS questionnaire_migrations (
            migration_key VARCHAR(120) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (migration_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('qn_scope_labels')) {
    function qn_scope_labels(): array {
        return [
            'faculty'     => 'Faculty',
            'staff'       => 'Staff',
            'school_head' => 'Dean / Principal',
            'ea'          => 'Executive Assistant (EA)',
        ];
    }
}

if (!function_exists('qn_scope_is_valid')) {
    function qn_scope_is_valid(string $scope): bool {
        return in_array($scope, ['faculty', 'staff', 'school_head', 'ea'], true);
    }
}

if (!function_exists('qn_scope_for_target')) {
    function qn_scope_for_target(mysqli $mysqli, int $targetId): ?string {
        $stmt = $mysqli->prepare("SELECT role, secondary_role FROM users WHERE id=? AND is_active=1 AND account_status='approved' LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$u) return null;

        $role = strtolower((string)($u['role'] ?? ''));
        $secondary = strtolower((string)($u['secondary_role'] ?? ''));

        if ($role === 'superadmin') return 'ea';
        if (in_array($role, ['principal', 'dean'], true)) return 'school_head';
        if ($role === 'teacher' || $secondary === 'teacher') return 'faculty';
        if ($role === 'staff' || $secondary === 'staff') return 'staff';
        return null;
    }
}

if (!function_exists('qn_canonical_target_type')) {
    function qn_canonical_target_type(string $scope, ?string $role = null): string {
        return match ($scope) {
            'faculty' => 'Faculty',
            'staff' => 'Staff',
            'school_head' => in_array(strtolower((string)$role), ['principal','dean'], true)
                ? ucfirst(strtolower((string)$role))
                : 'Dean',
            'ea' => 'EA',
            default => 'Faculty',
        };
    }
}

if (!function_exists('qn_get_faculty_questions')) {
    function qn_get_faculty_questions(mysqli $mysqli): array {
        qn_ensure_schema($mysqli);
        $sql = "SELECT id, category, question_text, 0 AS sort_order
                FROM evaluation_questions
                WHERE target_type='Faculty'
                  AND eval_type='general'
                  AND evaluator_role='shared'
                  AND is_active=1
                ORDER BY category ASC, id ASC";
        $res = $mysqli->query($sql);
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('qn_get_person_questions')) {
    function qn_get_person_questions(mysqli $mysqli, int $targetId, string $targetType): array {
        qn_ensure_schema($mysqli);
        $stmt = $mysqli->prepare("SELECT id, category, question_text, 0 AS sort_order
                                  FROM user_questions
                                  WHERE user_id=?
                                    AND target_type=?
                                    AND eval_type='general'
                                  ORDER BY category ASC, id ASC");
        if (!$stmt) return [];
        $stmt->bind_param('is', $targetId, $targetType);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('qn_question_counts')) {
    function qn_question_counts(mysqli $mysqli): array {
        qn_ensure_schema($mysqli);
        $out = ['faculty' => 0, 'staff' => [], 'school_head' => [], 'ea' => []];

        $r = $mysqli->query("SELECT COUNT(*) AS c
                             FROM evaluation_questions
                             WHERE target_type='Faculty' AND eval_type='general'
                               AND evaluator_role='shared' AND is_active=1");
        $out['faculty'] = (int)($r?->fetch_assoc()['c'] ?? 0);

        $r = $mysqli->query("SELECT user_id, target_type, COUNT(*) AS c
                             FROM user_questions
                             WHERE eval_type='general'
                             GROUP BY user_id, target_type");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $uid = (int)$row['user_id'];
                $type = (string)$row['target_type'];
                $out[$type === 'Staff' ? 'staff' : ($type === 'EA' ? 'ea' : 'school_head')][$uid] = (int)$row['c'];
            }
        }

        return $out;
    }
}

if (!function_exists('qn_ensure_shared_category')) {
    function qn_ensure_shared_category(mysqli $mysqli, string $targetType, string $categoryName, int $sortOrder = 0): ?int {
        $categoryName = trim($categoryName);
        if ($categoryName === '') $categoryName = 'General';

        $stmt = $mysqli->prepare("SELECT id FROM question_categories
                                  WHERE target_type=? AND eval_type='general'
                                    AND evaluator_role='shared' AND category_name=?
                                  LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('ss', $targetType, $categoryName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int)$row['id'];

        $stmt = $mysqli->prepare("INSERT INTO question_categories
            (target_type, category_name, eval_type, evaluator_role)
            VALUES (?,?,'general','shared')");
        if (!$stmt) return null;
        $stmt->bind_param('ss', $targetType, $categoryName);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id > 0 ? $id : null;
    }
}

if (!function_exists('qn_ensure_user_category')) {
    function qn_ensure_user_category(mysqli $mysqli, int $userId, string $targetType, string $categoryName, int $sortOrder = 0): ?int {
        $categoryName = trim($categoryName);
        if ($categoryName === '') $categoryName = 'General';

        $stmt = $mysqli->prepare("SELECT id FROM user_question_categories
                                  WHERE user_id=? AND target_type=?
                                    AND eval_type='general' AND category_name=?
                                  LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('iss', $userId, $targetType, $categoryName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int)$row['id'];

        $stmt = $mysqli->prepare("INSERT INTO user_question_categories
            (user_id, target_type, eval_type, category_name)
            VALUES (?,?, 'general', ?)");
        if (!$stmt) return null;
        $stmt->bind_param('iss', $userId, $targetType, $categoryName);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id > 0 ? $id : null;
    }
}

if (!function_exists('qn_ensure_shared_question')) {
    function qn_ensure_shared_question(mysqli $mysqli, string $targetType, string $category, string $questionText, int $sortOrder = 0): ?int {
        $questionText = trim($questionText);
        if ($questionText === '') return null;

        $stmt = $mysqli->prepare("SELECT id FROM evaluation_questions
                                  WHERE target_type=? AND eval_type='general'
                                    AND evaluator_role='shared' AND question_text=?
                                  ORDER BY id LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('ss', $targetType, $questionText);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $id = (int)$row['id'];
        } else {
            $categoryId = qn_ensure_shared_category($mysqli, $targetType, $category, $sortOrder);
            $stmt = $mysqli->prepare("INSERT INTO evaluation_questions
                (target_type, question_text, category, eval_type, evaluator_role)
                VALUES (?,?,?,'general','shared')");
            if (!$stmt) return null;
            $stmt->bind_param('sss', $targetType, $questionText, $category);
            $stmt->execute();
            $id = (int)$stmt->insert_id;
            $stmt->close();
        }

        $categoryId = qn_ensure_shared_category($mysqli, $targetType, $category, $sortOrder);
        if ($categoryId && $id) {
            $stmt = $mysqli->prepare("INSERT IGNORE INTO evaluation_question_categories (question_id, category_id)
                                      VALUES (?,?)");
            if ($stmt) {
                $stmt->bind_param('ii', $id, $categoryId);
                $stmt->execute();
                $stmt->close();
            }
        }
        return $id > 0 ? $id : null;
    }
}

if (!function_exists('qn_ensure_user_question')) {
    function qn_ensure_user_question(mysqli $mysqli, int $userId, string $targetType, string $category, string $questionText, int $sortOrder = 0): ?int {
        $questionText = trim($questionText);
        if ($questionText === '') return null;

        $stmt = $mysqli->prepare("SELECT id FROM user_questions
                                  WHERE user_id=? AND target_type=?
                                    AND eval_type='general'
                                    AND question_text=?
                                  ORDER BY id LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('iss', $userId, $targetType, $questionText);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) return (int)$row['id'];

        qn_ensure_user_category($mysqli, $userId, $targetType, $category, $sortOrder);
        $stmt = $mysqli->prepare("INSERT INTO user_questions
            (user_id, target_type, eval_type, category, question_text)
            VALUES (?,?, 'general', ?, ?)");
        if (!$stmt) return null;
        $stmt->bind_param('isss', $userId, $targetType, $category, $questionText);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id > 0 ? $id : null;
    }
}

if (!function_exists('qn_migrate_legacy_once')) {
    function qn_migrate_legacy_once(mysqli $mysqli): void {
        qn_ensure_schema($mysqli);
        $key = 'generalized_questionnaire_v1';
        $chk = $mysqli->prepare("SELECT 1 FROM questionnaire_migrations WHERE migration_key=? LIMIT 1");
        if (!$chk) return;
        $chk->bind_param('s', $key);
        $chk->execute();
        $done = (bool)$chk->get_result()->fetch_row();
        $chk->close();
        if ($done) return;

        $mysqli->begin_transaction();
        try {
            // FACULTY: merge all legacy shared Faculty/Teacher banks into one
            // canonical Faculty bank. Identical question text is deduplicated.
            $src = $mysqli->query("SELECT q.id, q.category, q.question_text, q.evaluator_role
                                   FROM evaluation_questions q
                                   WHERE (q.target_type='Teacher' AND q.eval_type IN ('student','peer'))
                                      OR (q.target_type='Faculty' AND q.eval_type='school_head')
                                      OR (q.target_type='Faculty' AND q.eval_type='staff')");
            $sort = 0;
            if ($src) {
                while ($row = $src->fetch_assoc()) {
                    $newId = qn_ensure_shared_question($mysqli, 'Faculty',
                        (string)($row['category'] ?? 'General'),
                        (string)$row['question_text'], $sort++);
                    if (!$newId) continue;

                    $links = $mysqli->prepare("SELECT c.category_name, 0 AS sort_order
                        FROM evaluation_question_categories a
                        JOIN question_categories c ON c.id=a.category_id
                        WHERE a.question_id=?");
                    if ($links) {
                        $oldId = (int)$row['id'];
                        $links->bind_param('i', $oldId);
                        $links->execute();
                        $lr = $links->get_result();
                        while ($cat = $lr->fetch_assoc()) {
                            $cid = qn_ensure_shared_category($mysqli, 'Faculty', (string)$cat['category_name'], (int)$cat['sort_order']);
                            if ($cid) {
                                $li = $mysqli->prepare("INSERT IGNORE INTO evaluation_question_categories (question_id, category_id) VALUES (?,?)");
                                if ($li) {
                                    $li->bind_param('ii', $newId, $cid);
                                    $li->execute();
                                    $li->close();
                                }
                            }
                        }
                        $links->close();
                    }
                }
            }

            // STAFF: merge every person's legacy Staff question set into that
            // person's one centralized Staff set.
            $src = $mysqli->query("SELECT user_id, category, question_text, 0 AS sort_order
                                   FROM user_questions
                                   WHERE target_type='Staff'
                                     AND eval_type IN ('student','peer','school_head','ea')");
            if ($src) {
                while ($row = $src->fetch_assoc()) {
                    qn_ensure_user_question($mysqli,
                        (int)$row['user_id'], 'Staff',
                        (string)($row['category'] ?? 'General'),
                        (string)$row['question_text'], (int)($row['sort_order'] ?? 0));
                }
            }

            // DEAN / PRINCIPAL: merge all person-specific legacy pools. The
            // old Peer school-head bucket used target_type='School', so resolve
            // the real target role from users before copying it.
            $src = $mysqli->query("SELECT uq.user_id, uq.target_type, uq.category, uq.question_text, 0 AS sort_order
                                   FROM user_questions uq
                                   WHERE uq.eval_type IN ('student','peer','school_head','ea')
                                     AND uq.target_type IN ('Dean','Principal','School')");
            if ($src) {
                while ($row = $src->fetch_assoc()) {
                    $uid = (int)$row['user_id'];
                    $type = (string)$row['target_type'];
                    if ($type === 'School') {
                        $rr = $mysqli->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
                        if ($rr) {
                            $rr->bind_param('i', $uid);
                            $rr->execute();
                            $roleRow = $rr->get_result()->fetch_assoc();
                            $rr->close();
                            $type = (($roleRow['role'] ?? '') === 'principal') ? 'Principal' : ((($roleRow['role'] ?? '') === 'dean') ? 'Dean' : '');
                        }
                    }
                    if (!in_array($type, ['Dean','Principal'], true)) continue;
                    qn_ensure_user_question($mysqli, $uid, $type,
                        (string)($row['category'] ?? 'General'),
                        (string)$row['question_text'], (int)($row['sort_order'] ?? 0));
                }
            }

            // Staff Evaluation used a shared bank for Dean/Principal targets.
            // Centralize those questions into each actual Dean/Principal target
            // so their questionnaire is target-centric regardless of evaluator.
            $sharedLeaders = $mysqli->query("SELECT target_type, category, question_text
                                             FROM evaluation_questions
                                             WHERE eval_type='staff'
                                               AND target_type IN ('Dean','Principal')
                                               AND is_active=1");
            if ($sharedLeaders) {
                while ($row = $sharedLeaders->fetch_assoc()) {
                    $role = (string)$row['target_type'];
                    $u = $mysqli->prepare("SELECT id FROM users WHERE role=? AND is_active=1 AND account_status='approved'");
                    if (!$u) continue;
                    $roleDb = strtolower($role);
                    $u->bind_param('s', $roleDb);
                    $u->execute();
                    $ur = $u->get_result();
                    while ($target = $ur->fetch_assoc()) {
                        qn_ensure_user_question($mysqli, (int)$target['id'], $role,
                            (string)($row['category'] ?? 'General'),
                            (string)$row['question_text'], 0);
                    }
                    $u->close();
                }
            }

            // EA: merge all legacy EA-target questions into the single active
            // EA person's question set.
            $eaUsers = $mysqli->query("SELECT id FROM users WHERE role='superadmin' AND is_active=1 AND account_status='approved'");
            $eaRows = [];
            if ($eaUsers) $eaRows = $eaUsers->fetch_all(MYSQLI_ASSOC);
            foreach ($eaRows as $ea) {
                $eaId = (int)$ea['id'];

                $src = $mysqli->query("SELECT category, question_text, 0 AS sort_order
                    FROM evaluation_questions
                    WHERE target_type='EA' AND eval_type IN ('school_head','staff','peer','student')
                      AND is_active=1");
                if ($src) {
                    while ($row = $src->fetch_assoc()) {
                        qn_ensure_user_question($mysqli, $eaId, 'EA',
                            (string)($row['category'] ?? 'General'),
                            (string)$row['question_text'], 0);
                    }
                }

                $stmt = $mysqli->prepare("SELECT category, question_text, 0 AS sort_order
                    FROM user_questions
                    WHERE user_id=? AND target_type='EA' AND eval_type <> 'general'");
                if ($stmt) {
                    $stmt->bind_param('i', $eaId);
                    $stmt->execute();
                    $ur = $stmt->get_result();
                    while ($row = $ur->fetch_assoc()) {
                        qn_ensure_user_question($mysqli, $eaId, 'EA',
                            (string)($row['category'] ?? 'General'),
                            (string)$row['question_text'], (int)($row['sort_order'] ?? 0));
                    }
                    $stmt->close();
                }
            }

            $ins = $mysqli->prepare("INSERT INTO questionnaire_migrations (migration_key) VALUES (?)");
            if (!$ins) throw new RuntimeException('Unable to record questionnaire migration.');
            $ins->bind_param('s', $key);
            $ins->execute();
            $ins->close();
            $mysqli->commit();
        } catch (Throwable $e) {
            $mysqli->rollback();
            // Never break the evaluator page because migration cannot run.
            // The administrator can reopen Questionnaire and the migration
            // will retry because no marker was written.
        }
    }
}
