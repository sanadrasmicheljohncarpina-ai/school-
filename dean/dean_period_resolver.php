<?php
/* =========================================================================
   dean_period_resolver.php
   -------------------------------------------------------------------------
   The Dean portal is Higher Education only. System settings hold a single
   "active" period that the Executive Assistant flips between Higher Ed
   (semester terms) and Basic Ed (School Year), and each structure can own a
   different period row. If a Dean page followed only the active period, the
   Dean's records would appear and disappear (and swap between the Faculty and
   Staff tabs) every time the EA switched structures.

   dean_higher_ed_period_ids() returns EVERY period that belongs to the Dean's
   Higher Ed records, so the reports stay the same whatever structure is
   currently active:
     1. the active period, while College / Higher Ed is the active structure;
     2. every semester-based period (semester / trimester / quarter), using the
        same Higher Ed vs Basic Ed rule as school_head_structure_gate.php;
     3. every period that already holds College evaluation data - evaluations
        submitted by College students, or peer evaluations between College
        teachers - whatever that period is labelled.
   Evaluator/target filters elsewhere still restrict each tab to College
   students, College teachers and non-teaching staff.
   ========================================================================= */
require_once __DIR__ . '/school_head_structure_gate.php';

if (!function_exists('dean_higher_ed_period_ids')) {
    /** @return int[] period ids, newest first (empty when none). */
    function dean_higher_ed_period_ids(mysqli $db, array $settings): array
    {
        $ids = [];

        // 1) Active period while Higher Ed is (or may be) the active structure.
        $active = (int)($settings['period_id'] ?? 0);
        if ($active > 0 && sh_gate_applicable_role($settings) !== 'principal') {
            $ids[$active] = true;
        }

        // 2) Semester-based periods are Higher Ed by definition.
        try {
            $res = $db->query("SELECT * FROM evaluation_periods");
            while ($res && ($row = $res->fetch_assoc())) {
                $label = trim((string)($row['period_label'] ?? ''));
                $sem   = trim((string)($row['semester'] ?? ''));
                $owner = sh_gate_owner_from_term($label) ?? sh_gate_owner_from_term($sem);
                if ($owner === 'dean') $ids[(int)$row['id']] = true;
            }
        } catch (Throwable $e) { /* table/columns differ - rule 3 still applies */ }

        // 3) Any period that already holds College evaluation data.
        $levels = "'1st Year College','2nd Year College','3rd Year College','4th Year College'";
        try {
            $res = $db->query("
                SELECT DISTINCT et.period_id AS pid
                FROM evaluation_tracker et
                WHERE et.period_id > 0
                  AND (
                      (et.eval_type='student' AND et.evaluator_id IN (
                          SELECT id FROM users
                          WHERE role='student'
                            AND (education_level='higher_ed' OR year_level IN ($levels))))
                      OR (et.eval_type IN ('peer','faculty_peer','staff_peer') AND et.evaluator_id IN (
                          SELECT ct.id FROM users ct
                          WHERE ct.role IN ('teacher','faculty','staff')
                            AND EXISTS (SELECT 1 FROM user_year_levels y
                                        WHERE y.user_id=ct.id AND y.year_level IN ($levels))))
                  )");
            while ($res && ($row = $res->fetch_assoc())) {
                if ((int)$row['pid'] > 0) $ids[(int)$row['pid']] = true;
            }
        } catch (Throwable $e) { /* leave whatever rules 1-2 found */ }

        $out = array_keys($ids);
        rsort($out);
        return $out;
    }
}

if (!function_exists('dean_period_sql')) {
    /** SQL boolean on period_id for a list of ids ("1=0" when empty). */
    function dean_period_sql(array $ids, string $col = 'et.period_id'): string
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
        return $ids ? "$col IN (" . implode(',', $ids) . ")" : '1=0';
    }
}