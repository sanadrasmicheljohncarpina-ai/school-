<?php
/*
 * ══════════════════════════════════════════════════════════════════════
 * shared/ea_personnel_service.php
 * ══════════════════════════════════════════════════════════════════════
 * Single source of truth for reading Faculty / Staff / Executive
 * Assistant / Student data, per Dean Module refactor Step 17. Used by
 * dean_dashboard.php and dean_evaluation.php (and anywhere else in the
 * Dean module that needs a roster) so there is exactly one query per
 * role, not one per page.
 *
 * There is no separate "Personnel Registry" table — confirmed against
 * admin/manage_privileged_accounts.php: Executive Assistant, Faculty
 * (role='teacher'), Staff, Student, Principal, and Dean accounts all
 * live in the single `users` table, gated by role + account_status
 * ('pending'/'approved'/'blocked') + is_active. College scoping for
 * Faculty/Staff comes from the `user_year_levels` junction table.
 *
 * Confirmed decisions (do not re-derive):
 *   - Student college status uses `education_level = 'college'`, NOT
 *     the `year_level` pattern-matching used elsewhere in
 *     manage_privileged_accounts.php for its own filter UI.
 *   - "Executive Assistant" is the designation of the current
 *     Super Admin account. It is NOT a separate users.role value; the
 *     database role remains 'superadmin'.
 *   - "Included/visible in evaluation" has no dedicated column yet;
 *     account_status='approved' AND is_active=1 stands in for it.
 *
 * Still open (do not guess further — surface to the user instead):
 *   - No "evaluation_status" / "last_evaluation_date" column exists for
 *     Faculty/Staff/EA. That's the DEAN's own evaluation of them
 *     (Step 10) — a different flow from the student->teacher
 *     evaluations the original dashboard tracked via evaluation_tracker
 *     (eval_type='student'). Every row defaults to 'not_started' until
 *     the eval_type/table for Dean-initiated evaluations is confirmed.
 *   - "Program" isn't a column on teacher/staff rows (only `course` on
 *     students) — left null pending confirmation of where Faculty
 *     program assignment lives.
 *
 * ══════════════════════════════════════════════════════════════════════
 */

if (!defined('COLLEGE_LEVELS')) {
    define('COLLEGE_LEVELS', ['1st Year College', '2nd Year College', '3rd Year College', '4th Year College']);
}

/** Shared row-shape helper: everyone gets evaluation_status defaulted
 *  to 'not_started' until Dean-initiated evaluation tracking exists. */
function ea_rows(mysqli $mysqli, string $sql, string $types, array $params): array {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    foreach ($rows as &$r) {
        $r['evaluation_status']    = $r['evaluation_status'] ?? 'not_started'; // TODO(EA-integration): Dean-evaluation tracker
        $r['last_evaluation_date'] = $r['last_evaluation_date'] ?? null;
    }
    return $rows;
}

/** Faculty = role='teacher', approved+active — same criteria as the EA's
 *  Manage Registrations roster (admin/manage_privileged_accounts.php):
 *  role='teacher' AND account_status='approved' AND is_active=1. No
 *  College/user_year_levels gate — a teacher the EA has approved should
 *  show up here even if no year level has been assigned to them yet.
 *  "Program" isn't in the schema for teacher/staff (course is
 *  student-only) — left null pending confirmation. */
function ea_get_faculty(mysqli $mysqli, int $periodId): array {
    return ea_rows($mysqli, "
        SELECT DISTINCT u.id, u.full_name, u.photo, u.department, u.designation AS position, NULL AS program
        FROM users u
        WHERE u.role = 'teacher' AND u.account_status = 'approved' AND u.is_active = 1
        ORDER BY u.full_name ASC
    ", '', []);
}

/** Staff = approved and active staff; no College year-level requirement. */
function ea_get_staff(mysqli $mysqli, int $periodId): array {
    return ea_rows($mysqli, "
        SELECT DISTINCT u.id, u.full_name, u.photo, u.department, u.designation AS position
        FROM users u
        WHERE u.role = 'staff'
          AND u.account_status = 'approved'
          AND u.is_active = 1
        ORDER BY u.full_name ASC
    ", '', []);
}
/** Executive Assistant = the current Super Admin account (role='superadmin').
 *  "Executive Assistant" is the designation shown in the UI, not a
 *  separate database role. No College scoping; the account is system-wide. */
function ea_get_executive_assistants(mysqli $mysqli, int $periodId): array {
    return ea_rows($mysqli, "
        SELECT id, full_name, photo, designation AS position, role AS system_role
        FROM users
        WHERE role = 'superadmin' AND account_status = 'approved' AND is_active = 1
        ORDER BY full_name ASC
    ", '', []);
}

/**
 * Read-only — the Dean never stores or duplicates student records
 * (Step 7). Used only for the Higher Ed Student Participation card.
 * Filters on education_level (confirmed authoritative column), not the
 * year_level pattern-matching used in manage_privileged_accounts.php.
 */
function ea_get_students(mysqli $mysqli, int $periodId): array {
    return ea_rows($mysqli, "
        SELECT id, full_name
        FROM users
        WHERE role = 'student' AND account_status = 'approved' AND is_active = 1
          AND education_level = 'college'
    ", '', []);
}

/**
 * Builds the link to dean_evaluate.php for a given roster row.
 *
 * dean_evaluate.php (as of the Step-17 rewrite) routes purely on
 * ?tab=<faculty|staff|executive_assistant>&user_id=<id> — it does not
 * read a `form` parameter at all. $role here is always the same $tab
 * value dean_evaluation.php is already using ('faculty' | 'staff' |
 * 'executive_assistant'), so it's passed straight through instead of
 * mapped through a separate form-name lookup — that mapping was the
 * source of the "Unknown evaluation type." bug: this function was
 * still emitting the old form=faculty_evaluation_form style URL that
 * dean_evaluate.php stopped understanding once it was rewritten to
 * read ?tab=. One less place for the two files to drift out of sync.
 *
 * Role must be one of: 'faculty', 'staff', 'executive_assistant'.
 */
function ea_questionnaire_route(string $role, int $targetUserId): string {
    $validTabs = ['faculty', 'staff', 'executive_assistant'];
    $tab = in_array($role, $validTabs, true) ? $role : 'faculty';
    return "dean_evaluate.php?tab={$tab}&user_id={$targetUserId}";
}