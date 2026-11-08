<?php
/* =========================================================================
   school_head_structure_gate.php
   -------------------------------------------------------------------------
   Decides WHICH school head's evaluation is applicable for the currently
   configured Academic Structure / Academic Term, so the Dean Evaluation
   and the Principal Evaluation can never both be open at once.

   RULE (as configured by the Executive Assistant):
     Academic Structure = "College"      -> Dean Evaluation applicable
                                            Principal Evaluation closed
     Academic Term      = "School Year"  -> Principal Evaluation applicable
                                            Dean Evaluation closed

   PRECEDENCE
     Academic Structure is the primary selector, because it names the
     division outright. Academic Term is only consulted when the structure
     is blank or doesn't name a division (e.g. it's stored as a bare code).
     Flip SH_GATE_STRUCTURE_WINS to false to make the Term the primary
     selector instead — nothing else needs to change.

   WHAT THIS GATE MAY AND MAY NOT DO
     It may only ever NARROW access, never widen it. For the applicable
     role the settings array is returned completely untouched, so existing
     scheduling, Force Open and Force Closed behavior continues to decide
     open/closed exactly as before. For the non-applicable role the gate
     forces unavailable + closed, which is what "the two must not be open
     at the same time" requires — a Force Open on the wrong division does
     not survive it.

     If neither the structure nor the term identifies a division, the gate
     stays out of the way entirely and the old behavior applies.

   This file is deliberately self-contained (no DB access, no shared
   includes) and is guarded with function_exists so it is safe to include
   from any page, in any order, and alongside the Principal portal's
   identical copy.
   ========================================================================= */

if (!defined('SH_GATE_STRUCTURE_WINS')) {
    define('SH_GATE_STRUCTURE_WINS', true);
}

if (!function_exists('sh_gate_owner_from_structure')) {
    /**
     * Which school head does the Academic Structure label point at?
     *
     * @return string|null 'dean', 'principal', or null when it names neither.
     */
    function sh_gate_owner_from_structure(?string $structure): ?string
    {
        $s = strtolower(trim((string)$structure));
        if ($s === '') return null;

        // Higher Education / Tertiary -> Dean
        if (preg_match('/\b(college|higher\s*ed|higher\s*education|tertiary)\b/', $s)) {
            return 'dean';
        }
        // Basic Education / JHS / SHS / Elementary -> Principal
        if (preg_match('/\b(basic\s*ed|basic\s*education|elementary|grade\s*school|junior\s*high|senior\s*high|high\s*school|k-?12)\b/', $s)) {
            return 'principal';
        }
        return null;
    }
}

if (!function_exists('sh_gate_owner_from_term')) {
    /**
     * Which school head does the Academic Term point at?
     *
     * Basic Education runs on a School Year; Higher Education runs on
     * semesters/trimesters. That is the whole distinction.
     *
     * @return string|null 'dean', 'principal', or null when it says neither.
     */
    function sh_gate_owner_from_term(?string $term): ?string
    {
        $t = strtolower(trim((string)$term));
        if ($t === '') return null;

        if (preg_match('/school\s*year|\bs\.?y\.?\b|whole\s*year|full\s*year|annual/', $t)) {
            return 'principal';
        }
        if (preg_match('/semester|\bsem\b|trimester|\btrim\b|quarter|summer|midyear|mid-?year/', $t)) {
            return 'dean';
        }
        return null;
    }
}

if (!function_exists('sh_gate_applicable_role')) {
    /**
     * The single school head role the current configuration applies to.
     *
     * @param array $settings Settings array from get_school_head_settings()
     *                        or get_system_settings(). Both carry
     *                        'academic_structure_label' and 'academic_term'.
     * @return string|null 'dean', 'principal', or null if indeterminate.
     */
    function sh_gate_applicable_role(array $settings): ?string
    {
        $byStructure = sh_gate_owner_from_structure($settings['academic_structure_label'] ?? null);
        $byTerm      = sh_gate_owner_from_term($settings['academic_term'] ?? null);

        return SH_GATE_STRUCTURE_WINS
            ? ($byStructure ?? $byTerm)
            : ($byTerm ?? $byStructure);
    }
}

if (!function_exists('sh_gate_apply')) {
    /**
     * Narrow a settings array to the calling role.
     *
     * Returns $settings unchanged when the role IS the applicable one (so
     * schedule / Force Open / Force Closed keep full control), and forces
     * unavailable + closed when it is not.
     *
     * @param array  $settings Settings array to filter.
     * @param string $role     'dean' or 'principal' — the calling portal.
     */
    function sh_gate_apply(array $settings, string $role): array
    {
        $owner = sh_gate_applicable_role($settings);

        // Indeterminate configuration, or this role is the applicable one:
        // leave every existing rule exactly as it was.
        if ($owner === null || $owner === $role) {
            return $settings;
        }

        // The other school head owns this period. Close this one down.
        $settings['school_head_applicable'] = false;
        $settings['school_head_is_open']    = false;

        // dean_evaluation_tracker.php reads this key directly instead of
        // school_head_is_open, so it has to be narrowed too or the tracker
        // would still consider submissions open.
        if (array_key_exists('is_open_for_submission', $settings)) {
            $settings['is_open_for_submission'] = false;
        }

        // Keep the period badge honest — otherwise a page could show
        // "Open" next to a "not the active academic structure" banner.
        if (isset($settings['status']) && is_array($settings['status'])) {
            $settings['status']['cls']   = 'closed';
            $settings['status']['label'] = 'Closed';
        }

        return $settings;
    }
}
