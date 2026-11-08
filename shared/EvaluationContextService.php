<?php
/**
 * Shared evaluation-context business rules.
 *
 * A person's base Teacher/Staff function and their additional Multi-Role
 * responsibility are separate evaluation contexts.  Teaching assignments
 * control the visibility of the base Teacher/Staff context; they do NOT
 * create or remove Multi-Role.
 */

function ec_designation_tokens(string $designation): array {
    $designation = trim($designation);
    if ($designation === '') return [];
    $parts = preg_split('/\s*[,\/|;]+\s*/', $designation);
    return array_values(array_filter(array_map('trim', $parts ?: []), fn($t) => $t !== ''));
}

function ec_has_teacher_function(array $user): bool {
    $role = strtolower(trim((string)($user['role'] ?? '')));
    $secondary = strtolower(trim((string)($user['secondary_role'] ?? '')));
    return in_array($role, ['teacher','faculty'], true) || $secondary === 'teacher';
}

function ec_has_staff_function(array $user): bool {
    $role = strtolower(trim((string)($user['role'] ?? '')));
    $secondary = strtolower(trim((string)($user['secondary_role'] ?? '')));
    return $role === 'staff' || $secondary === 'staff';
}

/**
 * Canonical "non-teaching" check: true only when the person has NO row in
 * teaching_assignments and NO row in user_year_levels. This is the DB-truth
 * predicate admin/questionnaire.php and ea_evaluation.php use to decide who
 * lands in the Non-Teaching Staff bucket — a leadership/office title alone
 * (e.g. Personnel/Department Head) does not disqualify someone from being
 * "non-teaching" if they have no actual teaching assignment or year-level
 * scope on record.
 */
function ec_is_non_teaching_staff(mysqli $mysqli, int $userId): bool {
    $stmt = $mysqli->prepare(
        "SELECT
            (SELECT COUNT(*) FROM teaching_assignments WHERE user_id = ?) AS ta_count,
            (SELECT COUNT(*) FROM user_year_levels WHERE user_id = ?) AS uyl_count"
    );
    $stmt->bind_param("ii", $userId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['ta_count'] ?? 0) === 0 && (int)($row['uyl_count'] ?? 0) === 0;
}

/**
 * Resolves a user to the peer-evaluation group ('teacher' | 'staff' | null)
 * using the same canonical rule everywhere: a designation like "Coordinator"
 * or "Department Head" never flips someone into Faculty — that's a separate
 * Multi-Role context (see ec_has_additional_role()) and doesn't affect this.
 *
 *   - 'teacher' if ec_has_teacher_function() is true
 *   - 'staff'   only if ec_has_staff_function() is true AND
 *               ec_is_non_teaching_staff() is true
 *   - null      otherwise (e.g. a Staff account that actually teaches but
 *               isn't marked secondary_role='teacher' — falls out of both
 *               tabs, matching the admin panel's own edge case rather than
 *               inventing new behavior)
 *
 * $user must include 'id', 'role', and 'secondary_role'.
 */
function ec_resolve_peer_group(array $user, mysqli $mysqli): ?string {
    if (ec_has_teacher_function($user)) return 'teacher';
    if (ec_has_staff_function($user) && ec_is_non_teaching_staff($mysqli, (int)($user['id'] ?? 0))) {
        return 'staff';
    }
    return null;
}

function ec_has_additional_role(array $user): bool {
    $secondary = strtolower(trim((string)($user['secondary_role'] ?? '')));
    if ($secondary !== '' && !in_array($secondary, ['teacher','staff'], true)) {
        return true;
    }

    $designation = trim((string)($user['designation'] ?? ''));
    if ($designation === '') return false;

    $tokens = ec_designation_tokens($designation);
    if (count($tokens) > 1) return true;

    $d = strtolower($designation);

    // A single explicit responsibility is enough to create a Multi-Role
    // evaluation context.  This intentionally supports custom titles.
    $additional_markers = [
        'coordinator', 'committee', 'department head', 'program head',
        'project head', 'project coordinator', 'executive assistant',
        'student activit', 'physical plant', 'computer lab', 'custodian',
        'chair', 'director', 'manager', 'officer', 'adviser', 'advisor',
        'lead', 'supervisor', 'formation services', 'sports program',
        'yearbook', 'sdrmm', 'scholarship', 'admission',
    ];
    foreach ($additional_markers as $marker) {
        if (strpos($d, $marker) !== false) return true;
    }

    // Common base-only designations are not Multi-Role by themselves.
    return false;
}

function ec_context_label(string $context): string {
    return [
        'teacher' => 'Teacher',
        'staff' => 'Staff',
        'multi_role' => 'Multi-Role',
        'school_head' => 'School Head',
    ][$context] ?? ucwords(str_replace('_', ' ', $context));
}
