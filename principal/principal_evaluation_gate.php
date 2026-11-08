<?php
// principal_evaluation_gate.php
// Centralizes the Principal-vs-Dean academic configuration gate.
//
// Academic configuration rule:
//   - College / University (semester-based) => Principal evaluation CLOSED.
//   - Basic Education + School Year => Principal evaluation AVAILABLE,
//     subject only to the existing schedule / Force Open / Force Closed mode.
//
// This helper does not replace the existing evaluation-window controls. It
// only determines whether the Principal channel applies to the current
// academic configuration.

function principal_evaluation_is_applicable(array $systemSettings, array $schoolHeadSettings = []): bool
{
    $structure = strtolower(trim((string)(
        $systemSettings['acad_structure']
        ?? $systemSettings['academic_structure']
        ?? $systemSettings['structure']
        ?? ''
    )));

    $term = trim((string)(
        $systemSettings['acad_term']
        ?? $systemSettings['academic_term']
        ?? $systemSettings['semester']
        ?? ''
    ));

    // The admin System Settings uses "college" / "jhs" / "shs" as the
    // structure values. College is Dean territory, never Principal.
    if ($structure === 'college' || $structure === 'university') {
        return false;
    }

    // If the shared settings service exposes the role-specific applicability
    // flag, honor it as an additional structure-level guard. This preserves
    // the existing Principal scope rules.
    if (!empty($schoolHeadSettings) && array_key_exists('school_head_applicable', $schoolHeadSettings)
        && empty($schoolHeadSettings['school_head_applicable'])) {
        return false;
    }

    // Principal evaluation is the School Year channel. Semester / Summer
    // periods belong to the Dean evaluation.
    return strcasecmp($term, 'School Year') === 0;
}

function principal_evaluation_is_open(array $systemSettings, array $schoolHeadSettings = []): bool
{
    // Existing scheduling / Force Open / Force Closed behavior remains
    // authoritative once the Principal channel is applicable.
    return principal_evaluation_is_applicable($systemSettings, $schoolHeadSettings)
        && !empty($schoolHeadSettings['school_head_is_open']);
}
