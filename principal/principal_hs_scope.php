<?php
// principal/principal_hs_scope.php
//
// Shared, TOLERANT JHS/SHS matching for the Principal Reports pages.
//
// Why this exists: teachers who teach BOTH JHS and SHS were dropped from the
// Peer-to-Peer report because the scope only matched exact strings such as
// 'Grade 7 - JHS'. Real data is stored in several spellings ('Grade 11',
// 'Grade 11 - SHS', a value cut off by the VARCHAR(10) year_level column,
// 'JHS', 'Junior High School', education_level 'both', ...). Matching is now
// done on the normalised text instead of an exact list.
//
// College / higher-ed values never match (no 'higher_ed', 'college', '1st year'
// etc. is accepted), so the Principal confidentiality boundary is unchanged.

if (!function_exists('principal_hs_year_match_sql')) {
    /** SQL predicate: $expr (a year-level style column) denotes a JHS/SHS level. */
    function principal_hs_year_match_sql(string $expr): string {
        $v = "LOWER(TRIM(COALESCE($expr,'')))";
        return "($v REGEXP '(^|[^0-9])(7|8|9|10|11|12)([^0-9]|$)'"
             . " OR $v LIKE '%jhs%' OR $v LIKE '%shs%'"
             . " OR $v LIKE '%junior%high%' OR $v LIKE '%senior%high%'"
             . " OR $v LIKE '%basic%')";
    }
}
if (!function_exists('principal_hs_education_match_sql')) {
    /** SQL predicate: users.education_level (or similar) is Basic Ed. */
    function principal_hs_education_match_sql(string $expr): string {
        $v = "LOWER(TRIM(COALESCE($expr,'')))";
        return "($v IN ('junior_high','senior_high','basic_education','basic_ed','jhs','shs','both')"
             . " OR $v LIKE '%junior%' OR $v LIKE '%senior%' OR $v LIKE '%basic%')";
    }
}
if (!function_exists('principal_hs_scope_sql')) {
    /** SQL predicate on users alias $u: the account has a JHS/SHS teaching scope. */
    function principal_hs_scope_sql(string $u = 'u'): string {
        return "(
    EXISTS (SELECT 1 FROM user_year_levels hs_uyl WHERE hs_uyl.user_id=$u.id AND " . principal_hs_year_match_sql('hs_uyl.year_level') . ")
    OR EXISTS (SELECT 1 FROM teaching_assignments hs_ta WHERE hs_ta.user_id=$u.id AND " . principal_hs_year_match_sql('hs_ta.year_level') . ")
    OR " . principal_hs_year_match_sql("$u.year_level") . "
    OR ($u.role IN ('teacher','faculty') AND " . principal_hs_education_match_sql("$u.education_level") . ")
)";
    }
}
