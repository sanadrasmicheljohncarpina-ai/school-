<?php
// session_bootstrap.php — include this BEFORE session_start() everywhere
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
require_once 'db.php';
require_once dirname(__DIR__) . '/shared/system_settings_service.php';
require_once 'school_head_structure_gate.php';

// ── AUTH GUARD ────────────────────────────────────────────
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'dean') {
    header("Location: dean_login.php");
    exit;
}

// ── PHASE 2 REWRITE (kept) ───────────────────────────────────────────
// This page shows ONLY Higher Education student evaluation-submission
// participation — no evaluation scores. Students fetched the same way
// Manage Privileged Accounts does (role='student', account_status=
// 'approved', is_active=1).
//
// REDESIGN NOTE (mockup-driven): added Reset button, colored-initial
// avatars, sortable Student/Year Level columns, pagination
// (5/page), and Export List. No ID Number column — student accounts
// don't collect a student ID number at registration, so the mockup's
// "2024-123456" column was dropped rather than faked. The submission-
// status lookup was rewritten from one query per student to two bulk
// queries total, since pagination implies this page now expects
// hundreds of rows rather than a handful.
//
// FIX (this pass) — two compounding bugs that made College students never
// show up here at all:
//   1. This page was still selecting/filtering on `users.course`, a column
//      that was dropped from `users` in the DB redesign (student
//      course/program is no longer stored on the user row at all). Every
//      query referencing it was silently throwing and getting swallowed by
//      safe_rows()'s try/catch, returning an empty array — so NO students
//      showed up, not just a College-specific problem. The Program
//      dropdown/column/filter/export field are removed below; there is
//      currently no replacement source for student program, matching the
//      choice already made on dean_evaluate.php for the same removed
//      column.
//   2. College-membership was being decided by `education_level='college'`
//      alone. manage_privileged_accounts.php — the actual admin approval
//      flow — never checks education_level for students; it classifies
//      them purely from the year_level string via classify_student_level()
//      (pattern match: "college" in the string, or a leading "1st/2nd/3rd/
//      4th Year"). That's the real source of truth for "is this student
//      College", so this page now matches it (year_level pattern, with
//      education_level='college' kept as a harmless OR fallback) instead
//      of trusting education_level alone.

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
function safe_rows(mysqli $mysqli, string $sql, string $types = '', array $params = []): array {
    try {
        $stmt = @$mysqli->prepare($sql);
        if (!$stmt) return [];
        if ($types !== '') { $stmt->bind_param($types, ...$params); }
        if (!@$stmt->execute()) { $stmt->close(); return []; }
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    } catch (mysqli_sql_exception $e) {
        return [];
    }
}

// ── DEAN PROFILE (for sidebar) ─────────────────────────────
$stmt = $mysqli->prepare("SELECT full_name, designation, photo FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();
$photo_src = !empty($me['photo']) ? UPLOAD_URL . $me['photo'] : UPLOAD_URL . 'pbi_logo';

// ── GLOBAL SYSTEM SETTINGS (single source of truth) ───────────────────
$settings = get_school_head_settings($mysqli, 'dean');
// Academic Structure / Academic Term gate: Dean owns College, Principal
// owns School Year. Narrow-only — existing scheduling, Force Open and
// Force Closed still decide open/closed while College is active.
$settings = sh_gate_apply($settings, 'dean');
$structureActive = !empty($settings['school_head_applicable']);
$period_id_int   = $settings['period_id'] ?? 0;
$hasPeriod       = $period_id_int > 0;
$evalOpen        = $settings['is_open_for_submission'];

const HIGHER_ED_LABEL = 'Higher Education';
const REMINDER_COOLDOWN_HOURS = 24; // must match dean_send_reminder.php

// ── TAB + FILTER + PAGE INPUT (GET) ───────────────────────────────────
// The Dean tracker has three tabs: Students (College only), Faculty (College
// teachers only) and Staff (non-teaching staff). Layout mirrors the EA tracker.
$tab = $_GET['tab'] ?? 'students';
if (!in_array($tab, ['students','faculty','staff'], true)) $tab = 'students';

$search    = trim($_GET['search'] ?? '');
$yearLevel = trim($_GET['year_level'] ?? '');
$status    = trim($_GET['status'] ?? '');

// Keep the old query-string values working after the tracker redesign.
if ($status === 'pending')   $status = 'not_started';
if ($status === 'submitted') $status = 'completed';
$validStatus = ['', 'not_started', 'in_progress', 'completed'];
if (!in_array($status, $validStatus, true)) $status = '';

$yearLevelOptions = [
    '1st_year' => '1st Year College',
    '2nd_year' => '2nd Year College',
    '3rd_year' => '3rd Year College',
    '4th_year' => '4th Year College',
];
$yearLevelRegex = [
    '1st_year' => '(^|[^0-9a-z])(1st|first)[[:space:]_-]*year([^0-9a-z]|$)',
    '2nd_year' => '(^|[^0-9a-z])(2nd|second)[[:space:]_-]*year([^0-9a-z]|$)',
    '3rd_year' => '(^|[^0-9a-z])(3rd|third)[[:space:]_-]*year([^0-9a-z]|$)',
    '4th_year' => '(^|[^0-9a-z])(4th|fourth)[[:space:]_-]*year([^0-9a-z]|$)',
];
if ($tab !== 'students' || !isset($yearLevelRegex[$yearLevel])) $yearLevel = '';

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 5;

// Column sorting (header arrows)
$sortOptions = ['name', 'level', 'required', 'completed', 'status', 'progress'];
$sort = trim($_GET['sort'] ?? '');
if (!in_array($sort, $sortOptions, true)) $sort = '';
$dir = (($_GET['dir'] ?? 'asc') === 'desc') ? 'desc' : 'asc';

// ── SCOPE SQL (same rules as dean_reports.php) ────────────────────────
// Faculty = College teachers only. A staff-role account with a College year
// level is teaching staff and counts as Faculty.
function dean_college_faculty_sql(string $a = 'u'): string {
    $rx = '(^|[^0-9a-z])(1st|2nd|3rd|4th|college)[[:space:]_-]*year([^0-9a-z]|$)';
    return "($a.role IN ('teacher','faculty','staff') AND $a.is_active=1 AND $a.account_status='approved'
        AND (EXISTS (SELECT 1 FROM user_year_levels fyl WHERE fyl.user_id=$a.id
                     AND LOWER(COALESCE(fyl.year_level,'')) REGEXP '$rx')
             OR ($a.role IN ('teacher','faculty') AND $a.academic_level='college')))";
}
// Staff = non-teaching staff (no teaching assignment / year level).
function dean_nonteaching_staff_sql(string $a = 'u'): string {
    return "($a.role='staff' AND $a.is_active=1 AND $a.account_status='approved'
        AND COALESCE($a.sector,'')<>'Teacher'
        AND NOT EXISTS (SELECT 1 FROM user_year_levels sy WHERE sy.user_id=$a.id)
        AND NOT EXISTS (SELECT 1 FROM teaching_assignments sta WHERE sta.user_id=$a.id))";
}
const DEAN_PEER_TYPES_SQL = "'peer','faculty_peer','staff_peer'";
const DEAN_EA_ROLES_SQL   = "'ea','executive_assistant','admin'";

// ── PER-TARGET HELPERS (expandable row breakdown) ─────────────────────
function dean_type_rank(string $type): int {
    return ['Faculty' => 1, 'Staff' => 2, 'Multi-Role' => 3, 'Dean' => 4, 'Executive Assistant' => 5][$type] ?? 9;
}
/** Every required target for one row and where the person stands on it. */
function dean_build_details(array $defs, array $contexts): array {
    $out = [];
    foreach ($defs as $key => $def) {
        $out[] = ['label' => $def['label'], 'type' => $def['type'], 'state' => $contexts[$key]['state'] ?? 'pending'];
    }
    usort($out, function ($a, $b) {
        $c = dean_type_rank($a['type']) <=> dean_type_rank($b['type']);
        return $c !== 0 ? $c : strnatcasecmp($a['label'], $b['label']);
    });
    return $out;
}
/**
 * Which Student Evaluation target does a submitted row belong to?
 * The Dean's CURRENT College scope is the final authority, so a stale bucket
 * can never turn a Non-Teaching Staff person into a Faculty row.
 */
function dean_context_for_submission(array $row, int $deanId, array $facultyIds, array $staffIds, array $multiIds, bool $deanRequired): ?string {
    $tid     = (int)$row['target_user_id'];
    $bucket  = strtolower(trim((string)($row['eval_bucket'] ?? '')));
    $context = strtolower(trim((string)($row['evaluation_context'] ?? '')));

    if ($deanRequired && $tid === $deanId &&
        (in_array($bucket, ['school head', 'school_head', 'dean', 'principal'], true) ||
         in_array($context, ['school_head', 'dean', 'principal'], true) || $bucket === '' || $context === '')) {
        return 'school_head:' . $tid;
    }
    $explicitMulti = in_array($bucket, ['multi-role', 'multi_role'], true)
        || in_array($context, ['multi-role', 'multi_role'], true)
        || !empty($row['multi_answer']);
    if ($explicitMulti && isset($multiIds[$tid])) return 'multi:' . $tid;

    $explicitStaff   = in_array($bucket, ['staff', 'non-teaching staff', 'non_teaching_staff'], true) || $context === 'staff';
    $explicitFaculty = in_array($bucket, ['faculty', 'teacher'], true) || in_array($context, ['faculty', 'teacher'], true);

    if (isset($staffIds[$tid]) && ($explicitStaff || (!$explicitFaculty && !$explicitMulti))) return 'staff:' . $tid;
    if (isset($facultyIds[$tid]) && ($explicitFaculty || (!$explicitStaff && !$explicitMulti))) return 'faculty:' . $tid;
    return null;
}

// Defaults so the page still renders the structure-mismatch state.
$rowsAll        = [];
$pageRows       = [];
$cards          = [];
$tabCounts      = ['students' => 0, 'faculty' => 0, 'staff' => 0];
$totalAssigned  = 0;
$requiredTotal  = 0;
$totalPages     = 1;
$statusCounts  = ['total' => 0, 'completed' => 0, 'in_progress' => 0, 'not_started' => 0];

if ($structureActive) {
    // ── ROSTERS (unfiltered, used for tab badges + summary cards) ───────
    $studentRoster = safe_rows($mysqli, "
        SELECT id, full_name, photo, year_level FROM users
        WHERE role='student' AND is_active=1 AND account_status='approved'
          AND LOWER(COALESCE(year_level,'')) REGEXP '^(1st|2nd|3rd|4th)[[:space:]_-]*year([[:space:]_-]*college)?\$'
        ORDER BY full_name ASC");
    $facultyRoster = safe_rows($mysqli, "
        SELECT u.id, u.full_name, u.photo, u.designation,
               (SELECT GROUP_CONCAT(DISTINCT yl.year_level ORDER BY yl.year_level SEPARATOR ', ')
                  FROM user_year_levels yl WHERE yl.user_id=u.id) AS levels
        FROM users u WHERE " . dean_college_faculty_sql('u') . " ORDER BY u.full_name ASC");
    $staffRoster = safe_rows($mysqli, "
        SELECT u.id, u.full_name, u.photo, u.designation
        FROM users u WHERE " . dean_nonteaching_staff_sql('u') . " ORDER BY u.full_name ASC");

    $tabCounts = [
        'students' => count($studentRoster),
        'faculty'  => count($facultyRoster),
        'staff'    => count($staffRoster),
    ];

    $stateOf = static function (int $completed, int $required, bool $started = false): array {
        if ($required > 0 && $completed >= $required) return ['completed', 'Completed'];
        if ($completed > 0 || $started) return ['in_progress', 'In Progress'];
        return ['not_started', 'Not Started'];
    };

    if ($tab === 'students') {
        // ── QUESTIONNAIRE CONFIGURATION / REQUIRED TARGETS ─────────────
        // Required = the configured College Student-Evaluation targets, listed
        // one by one so every row can show exactly who still has to be
        // evaluated. A student with zero submissions shows 0 / N, not 0 / 0.
        $deanId = (int)$_SESSION['user_id'];
        $deanName = trim((string)($me['full_name'] ?? '')) ?: 'Dean';

        $teacherQuestionCount = (int)(safe_scalar($mysqli,
            "SELECT COUNT(*) FROM evaluation_questions WHERE target_type='Faculty' AND eval_type='general' AND evaluator_role='shared' AND is_active=1"
        ) ?? 0);
        $multiRoleQuestionCount = $teacherQuestionCount;
        if ($multiRoleQuestionCount === 0) {
            $multiRoleQuestionCount = (int)(safe_scalar($mysqli,
                "SELECT COUNT(*) FROM user_questions WHERE eval_type='general' AND target_type='Staff'"
            ) ?? 0);
        }

        // Faculty targets: College teachers only.
        $facultyIds = [];
        $targetNames = [];
        if ($teacherQuestionCount > 0) {
            foreach ($facultyRoster as $f) { $facultyIds[(int)$f['id']] = true; $targetNames[(int)$f['id']] = (string)$f['full_name']; }
        }

        // Staff targets: non-teaching staff who have a Staff question set.
        $staffIds = [];
        $staffWithQuestions = [];
        foreach (safe_rows($mysqli, "SELECT DISTINCT user_id FROM user_questions WHERE eval_type='general' AND target_type='Staff'") as $qr) {
            $staffWithQuestions[(int)$qr['user_id']] = true;
        }
        foreach ($staffRoster as $s) {
            if (isset($staffWithQuestions[(int)$s['id']])) { $staffIds[(int)$s['id']] = true; $targetNames[(int)$s['id']] = (string)$s['full_name']; }
        }

        // Multi-Role targets: College personnel who are both teaching and staff.
        $multiIds = [];
        if ($multiRoleQuestionCount > 0) {
            $multiRows = safe_rows($mysqli, "
                SELECT u.id, u.full_name FROM users u
                WHERE u.role IN ('teacher','staff')
                  AND u.is_active=1
                  AND u.account_status='approved'
                  AND (
                      (u.role='teacher' AND LOWER(COALESCE(u.secondary_role,''))='staff')
                      OR (u.role='staff' AND (
                            LOWER(COALESCE(u.secondary_role,''))='teacher'
                            OR EXISTS (SELECT 1 FROM teaching_assignments ta WHERE ta.user_id=u.id)
                            OR EXISTS (SELECT 1 FROM user_year_levels yl WHERE yl.user_id=u.id)
                      ))
                      OR LOWER(COALESCE(u.designation,'')) REGEXP 'teacher.*staff|staff.*teacher'
                  )
                  AND (
                      u.academic_level='college'
                      OR EXISTS (
                          SELECT 1 FROM user_year_levels yl2
                          WHERE yl2.user_id=u.id
                            AND LOWER(COALESCE(yl2.year_level,'')) REGEXP '(^|[^0-9a-z])(college|1st|2nd|3rd|4th)[[:space:]_-]*year([^0-9a-z]|\\$)'
                      )
                      OR EXISTS (SELECT 1 FROM teaching_assignments ta2 WHERE ta2.user_id=u.id)
                  )
                ORDER BY u.full_name ASC
            ");
            foreach ($multiRows as $m) { $multiIds[(int)$m['id']] = true; $targetNames[(int)$m['id']] = (string)$m['full_name']; }
        }

        // College students evaluate the Dean as the College School Head, but
        // only when this Dean's own question set exists.
        $deanHeadRequired = (int)(safe_scalar($mysqli,
            "SELECT COUNT(*) FROM user_questions WHERE user_id=? AND eval_type='general' AND target_type='Dean'",
            'i', [$deanId]
        ) ?? 0) > 0;

        $studentTargetDefs = [];
        foreach ($facultyIds as $uid => $_) $studentTargetDefs['faculty:' . $uid] = ['label' => $targetNames[$uid], 'type' => 'Faculty'];
        foreach ($staffIds   as $uid => $_) $studentTargetDefs['staff:' . $uid]   = ['label' => $targetNames[$uid], 'type' => 'Staff'];
        foreach ($multiIds   as $uid => $_) $studentTargetDefs['multi:' . $uid]   = ['label' => $targetNames[$uid], 'type' => 'Multi-Role'];
        if ($deanHeadRequired) $studentTargetDefs['school_head:' . $deanId] = ['label' => $deanName, 'type' => 'Dean'];
        $requiredTotal = count($studentTargetDefs);

        // ── PER-STUDENT, PER-TARGET PROGRESS ───────────────────────────
        $studentContextMap = [];
        $allIds = array_map('intval', array_column($studentRoster, 'id'));
        if ($hasPeriod && $requiredTotal > 0 && !empty($allIds)) {
            $ph = implode(',', array_fill(0, count($allIds), '?'));
            $submissionRows = safe_rows($mysqli, "
                SELECT et.id, et.evaluator_id, et.target_user_id, et.eval_bucket,
                       et.evaluation_context, et.status, et.submitted_at, et.updated_at,
                       EXISTS(
                           SELECT 1
                           FROM questionnaire_answers qam
                           JOIN user_questions uqm ON uqm.id=qam.user_question_id
                           WHERE qam.tracker_id=et.id
                             AND ((uqm.eval_type='student' AND uqm.target_type='Multi-Role')
                               OR (uqm.eval_type='general' AND uqm.target_type IN ('Staff','Dean','Principal')))
                       ) AS multi_answer
                FROM evaluation_tracker et
                WHERE et.eval_type='student'
                  AND et.period_id=?
                  AND et.evaluator_id IN ($ph)
                  AND et.status IN ('submitted','approved','in_progress')
                ORDER BY et.updated_at DESC, et.id DESC
            ", 'i' . str_repeat('i', count($allIds)), array_merge([$period_id_int], $allIds));

            foreach ($submissionRows as $row) {
                $contextKey = dean_context_for_submission($row, $deanId, $facultyIds, $staffIds, $multiIds, $deanHeadRequired);
                if ($contextKey === null) continue;
                $sid = (int)$row['evaluator_id'];
                $isCompleted = in_array($row['status'], ['submitted', 'approved'], true);
                $timeValue = $row['submitted_at'] ?: ($row['updated_at'] ?? null);
                if (!isset($studentContextMap[$sid][$contextKey])) {
                    $studentContextMap[$sid][$contextKey] = ['state' => $isCompleted ? 'completed' : 'in_progress', 'at' => $timeValue];
                } elseif ($isCompleted && $studentContextMap[$sid][$contextKey]['state'] !== 'completed') {
                    $studentContextMap[$sid][$contextKey] = ['state' => 'completed', 'at' => $timeValue];
                }
            }
        }

        foreach ($studentRoster as $s) {
            $sid = (int)$s['id'];
            $rawYear = trim((string)($s['year_level'] ?? ''));
            $collegeYear = 'College';
            $yearKey = '';
            foreach ($yearLevelRegex as $key => $rx) {
                if ($rawYear !== '' && preg_match('/' . $rx . '/i', $rawYear)) {
                    $collegeYear = $yearLevelOptions[$key];
                    $yearKey = $key;
                    break;
                }
            }
            $contexts = $studentContextMap[$sid] ?? [];
            $completed = 0; $started = false; $latest = null;
            foreach ($contexts as $c) {
                if ($c['state'] === 'completed') $completed++;
                if ($c['state'] === 'in_progress') $started = true;
                if (!empty($c['at']) && (!$latest || strtotime((string)$c['at']) > strtotime((string)$latest))) $latest = $c['at'];
            }
            $completed = min($requiredTotal, $completed);
            [$state, $stateLabel] = $stateOf($completed, $requiredTotal, $started);
            $rowsAll[] = [
                'key' => 's' . $sid, 'noun' => 'student',
                'id' => $sid, 'name' => $s['full_name'],
                'sub' => $collegeYear, 'level' => $collegeYear, 'year_key' => $yearKey,
                'status' => $state, 'status_label' => $stateLabel,
                'required' => $requiredTotal, 'completed' => $completed,
                'progress' => $requiredTotal > 0 ? min(100, (int)round($completed / $requiredTotal * 100)) : 0,
                'submitted_at' => $latest,
                'details' => dean_build_details($studentTargetDefs, $contexts),
            ];
        }

        // Summary cards: one per College year level (unfiltered roster).
        foreach ($yearLevelOptions as $key => $lbl) {
            $in   = array_filter($rowsAll, fn($r) => $r['year_key'] === $key);
            $done = count(array_filter($in, fn($r) => $r['status'] === 'completed'));
            $cards[] = ['key' => $key, 'label' => $lbl, 'value' => count($in), 'completed' => $done, 'pending' => count($in) - $done];
        }
        // A year level is selected: the summary row shows only that level's card.
        if ($yearLevel !== '') {
            $cards = array_values(array_filter($cards, fn($c) => ($c['key'] ?? '') === $yearLevel));
        }

    } else {
        // ── FACULTY / STAFF TABS ───────────────────────────────────────
        $isFaculty = ($tab === 'faculty');
        $people    = $isFaculty ? $facultyRoster : $staffRoster;
        $ids       = array_map('intval', array_column($people, 'id'));

        // Targets: Faculty peer-to-peer (every OTHER College teacher), or the
        // Executive Assistant(s) for non-teaching staff.
        $personTargets = [];
        if ($isFaculty) {
            foreach ($facultyRoster as $f) $personTargets[(int)$f['id']] = ['label' => (string)$f['full_name'], 'type' => 'Faculty'];
        } else {
            foreach (safe_rows($mysqli, "SELECT id, full_name FROM users WHERE role IN (" . DEAN_EA_ROLES_SQL . ") AND is_active=1 ORDER BY full_name ASC") as $ea) {
                $personTargets[(int)$ea['id']] = ['label' => (string)$ea['full_name'], 'type' => 'Executive Assistant'];
            }
        }

        $stateMap = [];   // [evaluator_id][target_user_id] => ['state' => ..., 'at' => ...]
        if ($hasPeriod && $ids && $personTargets) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            if ($isFaculty) {
                $sql = "SELECT et.evaluator_id, et.target_user_id, et.status, et.submitted_at, et.updated_at
                        FROM evaluation_tracker et
                        WHERE et.period_id=? AND et.eval_type IN (" . DEAN_PEER_TYPES_SQL . ")
                          AND et.status IN ('submitted','approved','in_progress')
                          AND et.evaluator_id IN ($ph) AND et.target_user_id IN ($ph)
                          AND et.target_user_id<>et.evaluator_id
                        ORDER BY et.updated_at DESC, et.id DESC";
                $types  = 'i' . str_repeat('i', count($ids) * 2);
                $params = array_merge([$period_id_int], $ids, $ids);
            } else {
                $sql = "SELECT et.evaluator_id, et.target_user_id, et.status, et.submitted_at, et.updated_at
                        FROM evaluation_tracker et
                        WHERE et.period_id=? AND et.eval_type<>'student'
                          AND et.status IN ('submitted','approved','in_progress')
                          AND et.evaluator_id IN ($ph)
                          AND et.target_user_id IN (SELECT id FROM users WHERE role IN (" . DEAN_EA_ROLES_SQL . "))
                        ORDER BY et.updated_at DESC, et.id DESC";
                $types  = 'i' . str_repeat('i', count($ids));
                $params = array_merge([$period_id_int], $ids);
            }
            foreach (safe_rows($mysqli, $sql, $types, $params) as $r) {
                $eid = (int)$r['evaluator_id']; $tid = (int)$r['target_user_id'];
                if ($tid === $eid || !isset($personTargets[$tid])) continue;
                $isDone = in_array($r['status'], ['submitted', 'approved'], true);
                $at = $r['submitted_at'] ?: ($r['updated_at'] ?? null);
                if (!isset($stateMap[$eid][$tid])) {
                    $stateMap[$eid][$tid] = ['state' => $isDone ? 'completed' : 'in_progress', 'at' => $at];
                } elseif ($isDone && $stateMap[$eid][$tid]['state'] !== 'completed') {
                    $stateMap[$eid][$tid] = ['state' => 'completed', 'at' => $at];
                }
            }
        }

        foreach ($people as $p) {
            $pid  = (int)$p['id'];
            $defs = $personTargets;
            unset($defs[$pid]);                       // nobody evaluates themselves
            $contexts = $stateMap[$pid] ?? [];
            $req = count($defs);
            $completed = 0; $started = false; $latest = null;
            foreach ($contexts as $tid => $c) {
                if (!isset($defs[$tid])) continue;
                if ($c['state'] === 'completed') $completed++;
                if ($c['state'] === 'in_progress') $started = true;
                if (!empty($c['at']) && (!$latest || strtotime((string)$c['at']) > strtotime((string)$latest))) $latest = $c['at'];
            }
            $completed = min($req, $completed);
            [$state, $stateLabel] = $stateOf($completed, $req, $started);
            $desig = trim((string)($p['designation'] ?? ''));
            $rowsAll[] = [
                'key' => ($isFaculty ? 'f' : 'e') . $pid, 'noun' => $isFaculty ? 'faculty member' : 'staff member',
                'id' => $pid, 'name' => $p['full_name'],
                'sub' => $desig !== '' ? $desig : ($isFaculty ? 'College Faculty' : 'Non-Teaching Staff'),
                'level' => $isFaculty ? 'College' : 'Staff', 'year_key' => '',
                'status' => $state, 'status_label' => $stateLabel,
                'required' => $req, 'completed' => $completed,
                'progress' => $req > 0 ? min(100, (int)round($completed / $req * 100)) : 0,
                'submitted_at' => $latest,
                'details' => dean_build_details($defs, $contexts),
            ];
        }
        $requiredTotal = $rowsAll ? $rowsAll[0]['required'] : 0;

        $total = count($rowsAll);
        $cnt = ['not_started' => 0, 'in_progress' => 0, 'completed' => 0];
        foreach ($rowsAll as $r) $cnt[$r['status']]++;
        $noun = $isFaculty ? 'faculty' : 'staff';
        $cards = [
            ['label' => 'Not Started', 'value' => $cnt['not_started'], 'completed' => $cnt['completed'], 'pending' => $total - $cnt['completed'], 'note' => $total . ' ' . $noun . ' total'],
            ['label' => 'In Progress', 'value' => $cnt['in_progress'], 'completed' => $cnt['completed'], 'pending' => $total - $cnt['completed'], 'note' => $total . ' ' . $noun . ' total'],
            ['label' => 'Completed',   'value' => $cnt['completed'],   'completed' => $cnt['completed'], 'pending' => $total - $cnt['completed'], 'note' => $total . ' ' . $noun . ' total'],
        ];
    }

    // ── FILTERS (applied to the active tab only) ────────────────────────
    // Status chip counts follow the search + year-level filters but NOT the
    // status filter itself, so every chip keeps showing its real total.
    $scoped = array_values(array_filter($rowsAll, function ($r) use ($search, $yearLevel) {
        if ($search !== '' && stripos($r['name'], $search) === false) return false;
        if ($yearLevel !== '' && $r['year_key'] !== $yearLevel) return false;
        return true;
    }));
    $statusCounts = ['total' => count($scoped), 'completed' => 0, 'in_progress' => 0, 'not_started' => 0];
    foreach ($scoped as $r) $statusCounts[$r['status']]++;
    $rowsAll = $status !== ''
        ? array_values(array_filter($scoped, function ($r) use ($status) { return $r['status'] === $status; }))
        : $scoped;

    if ($sort !== '') {
        $statusRank = ['not_started' => 0, 'in_progress' => 1, 'completed' => 2];
        usort($rowsAll, function ($a, $b) use ($sort, $dir, $statusRank) {
            switch ($sort) {
                case 'name':      $c = strnatcasecmp($a['name'], $b['name']); break;
                case 'level':     $c = strnatcasecmp($a['level'], $b['level']); break;
                case 'required':  $c = $a['required'] <=> $b['required']; break;
                case 'completed': $c = $a['completed'] <=> $b['completed']; break;
                case 'status':    $c = $statusRank[$a['status']] <=> $statusRank[$b['status']]; break;
                default:          $c = $a['progress'] <=> $b['progress'];
            }
            if ($c === 0) $c = strnatcasecmp($a['name'], $b['name']);
            return $dir === 'desc' ? -$c : $c;
        });
    }
    $totalAssigned = count($rowsAll);

    // ── EXPORT (full filtered set of the active tab) ────────────────────
    if (($_GET['export'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="dean_tracker_' . $tab . '_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, [['students' => 'Student', 'faculty' => 'Faculty', 'staff' => 'Staff'][$tab], 'Level', 'Required', 'Completed', 'Status', 'Progress']);
        foreach ($rowsAll as $r) {
            fputcsv($out, [$r['name'], $r['level'], $r['required'], $r['completed'] . ' / ' . $r['required'],
                           $r['status_label'], $r['progress'] . '%']);
        }
        fclose($out);
        $mysqli->close();
        exit;
    }

    $totalPages = max(1, (int)ceil($totalAssigned / $perPage));
    $page = max(1, min($totalPages, $page));
    $pageRows = array_slice($rowsAll, ($page - 1) * $perPage, $perPage);

    // ── LIVE JSON ENDPOINT ───────────────────────────────────────────────
    if (($_GET['ajax'] ?? '') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode([
            'ok' => true, 'tab' => $tab, 'structureActive' => $structureActive,
            'hasPeriod' => $hasPeriod, 'evalOpen' => $evalOpen,
            'counts' => $tabCounts, 'statusCounts' => $statusCounts, 'cards' => $cards,
            'total' => $totalAssigned, 'totalPages' => $totalPages, 'page' => $page,
            'rows' => $pageRows,
            'updatedLabel' => date('M j, Y g:i A'), 'checkedAt' => date('c'),
        ], JSON_UNESCAPED_SLASHES);
        $mysqli->close();
        exit;
    }
}

$tabNoun  = ['students' => 'student', 'faculty' => 'faculty member', 'staff' => 'staff member'][$tab];
$tabNounP = ['students' => 'students', 'faculty' => 'faculty', 'staff' => 'staff'][$tab];
$tabHead  = ['students' => 'Student', 'faculty' => 'Faculty', 'staff' => 'Staff'][$tab];

// Rebuilds the current query string with overrides — used by sorting,
// filtering and pagination links.
function tracker_qs(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    if (!isset($overrides['page'])) $params['page'] = 1;
    return htmlspecialchars('?' . http_build_query($params));
}

/** Sortable column header (arrow icon, toggles asc/desc). */
function tracker_th(string $label, string $key, string $sort, string $dir): string {
    $on   = $sort === $key;
    $next = ($on && $dir === 'asc') ? 'desc' : 'asc';
    $icon = !$on ? 'fa-sort' : ($dir === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
    return '<th class="sortable' . ($on ? ' sorted' : '') . '"><a href="' . tracker_qs(['sort' => $key, 'dir' => $next]) . '">' . dean_h($label) . ' <i class="fa-solid ' . $icon . '"></i></a></th>';
}

// Small status helpers keep the table markup readable.
function tracker_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $first = $parts[0][0] ?? '';
    $last  = count($parts) > 1 ? $parts[count($parts) - 1][0] : '';
    return strtoupper($first . $last);
}

function dean_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Expanded panel: every target this person must evaluate and its state. */
function dean_render_detail(array $s): string {
    $details = $s['details'] ?? [];
    if (empty($details)) {
        return '<p class="trk-detail-empty">No required evaluation targets are configured for this ' . dean_h($s['noun']) . ' yet.</p>';
    }
    $caption = ['faculty member' => 'Peer evaluations the faculty member must submit', 'staff member' => 'Evaluations the staff member must submit'][$s['noun']] ?? 'Targets the student must evaluate';
    $h = '<div class="trk-detail-head"><strong>' . (int)$s['completed'] . ' of ' . (int)$s['required'] . ' required evaluations completed</strong><span>' . dean_h($caption) . '</span></div><ul class="trk-targets">';
    foreach ($details as $d) {
        $st = $d['state'];
        $icon = $st === 'completed' ? 'fa-circle-check' : ($st === 'in_progress' ? 'fa-circle-half-stroke' : 'fa-circle');
        $txt  = $st === 'completed' ? 'Done' : ($st === 'in_progress' ? 'In progress' : 'Pending');
        $h .= '<li class="trk-target ' . dean_h($st) . '"><i class="fa-solid ' . $icon . '"></i><span class="trk-target-name" title="' . dean_h($d['label'] . ' (' . $d['type'] . ')') . '">' . dean_h($d['label']) . '</span><span class="trk-target-type">' . dean_h($d['type']) . '</span><span class="trk-target-state">' . $txt . '</span></li>';
    }
    return $h . '</ul>';
}

/** One clickable table row plus its hidden detail row. */
function dean_render_row(array $s): string {
    $p = (int)$s['progress'];
    $last = !empty($s['submitted_at']) ? '<div class="progress-last">Last: ' . dean_h(date('M j, Y g:i A', strtotime((string)$s['submitted_at']))) . '</div>' : '';
    return '<tr class="trk-row" data-key="' . dean_h($s['key']) . '" tabindex="0" aria-expanded="false">'
        . '<td><div class="stu-cell"><span class="stu-avatar"><i class="fa-solid fa-user"></i></span><div class="stu-copy"><div class="stu-name">' . dean_h($s['name']) . '</div><div class="stu-sub">' . dean_h($s['sub']) . '</div></div></div></td>'
        . '<td><span class="level-pill">' . dean_h($s['level']) . '</span></td>'
        . '<td><span class="req-number">' . number_format($s['required']) . '</span></td>'
        . '<td><span class="completed-number">' . number_format($s['completed']) . ' / ' . number_format($s['required']) . '</span></td>'
        . '<td><span class="status-pill ' . dean_h($s['status']) . '">' . dean_h($s['status_label']) . '</span></td>'
        . '<td><div class="progress-cell"><span class="progress-pct">' . $p . '%</span><div class="progress-main"><div class="progress-track" aria-label="' . $p . ' percent complete"><div class="progress-fill" style="width:' . $p . '%;"></div></div>' . $last . '</div><span class="progress-chevron" aria-hidden="true">›</span></div></td>'
        . '</tr>'
        . '<tr class="trk-detail" data-key="' . dean_h($s['key']) . '" hidden><td colspan="6">' . dean_render_detail($s) . '</td></tr>';
}

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en" class="dean-internal-scroll-page">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PBI — Evaluation Tracker</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
:root{
  --page:#F5F8FC; --card:#FFFFFF; --line:#DCE7F1; --line-strong:#9FB2C3;
  --text:#12263A; --muted:#6D8194; --muted-2:#8CA0B1;
  --teal:#19B39D; --teal-soft:#E9F8F5; --teal-border:#74CFC3;
  --green:#0F9F6E; --green-soft:#EAF8F2;
  --amber:#B7791F; --amber-soft:#FFF8E8;
  --blue:#2563EB; --shadow:0 4px 16px rgba(28,64,92,.07);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html{background:var(--page);}
body{min-height:100vh;background:var(--page);font-family:'DM Sans',system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--text);display:flex;}

/* Keep the existing Dean navigation; only the tracker content is restyled. */
.sidebar{width:250px;flex-shrink:0;background:#0F1F33;border-right:1px solid #060E18;min-height:100vh;padding:28px 20px;display:flex;flex-direction:column;}
.sb-profile{text-align:center;margin-bottom:26px}.sb-photo{width:72px;height:72px;border-radius:50%;object-fit:cover;border:2.5px solid #7C5FD9;box-shadow:0 0 18px rgba(124,95,217,.4);margin:0 auto 10px;display:block}.sb-name{font-weight:700;font-size:15px;color:#fff}.sb-role{font-size:11px;color:#9C85F0;text-transform:uppercase;letter-spacing:.6px;margin-top:2px}.sb-scope{font-size:10px;color:#A0B3C6;margin-top:4px}.sb-nav{display:flex;flex-direction:column;gap:4px;margin-top:10px}.sb-nav a{display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;color:#A0B3C6;text-decoration:none;font-size:14px;font-weight:500;transition:background .2s,color .2s}.sb-nav a:hover,.sb-nav a.active{background:rgba(124,95,217,.15);color:#fff}.sb-nav a i{width:18px;text-align:center;color:#9C85F0}.sb-logout{margin-top:auto}.sb-logout a{display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;color:#fca5a5;text-decoration:none;font-size:14px;font-weight:500}.sb-logout a:hover{background:rgba(240,84,84,.12)}

.main{flex:1;min-width:0;padding:34px 40px 42px;}
.page-header{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;flex-wrap:wrap;margin-bottom:20px;}
.page-title{font-size:28px;font-weight:700;letter-spacing:-.02em;color:var(--text)}
.page-sub{font-size:13px;color:var(--muted);margin-top:5px}
.period-badge{display:inline-flex;align-items:center;gap:8px;padding:8px 13px;border-radius:999px;border:1px solid var(--line);background:#fff;color:#587086;font-size:12px;font-weight:700;box-shadow:0 1px 2px rgba(15,23,42,.03)}
.period-badge i{color:var(--teal)}
.period-badge.closed{background:#FFF4F5;border-color:#F2C7CC;color:#A94250}.period-badge.closed i{color:#D6455D}.period-badge.amber{background:#FFF8E8;border-color:#F4D7A0;color:#9A650F}.period-badge.gray{color:var(--muted)}

.structure-note{display:flex;align-items:flex-start;gap:12px;padding:16px 18px;background:#fff;border:1px solid var(--line);border-radius:12px;margin-bottom:18px;box-shadow:var(--shadow)}
.structure-note i{color:var(--blue);font-size:17px;margin-top:2px}.structure-note p{font-size:13px;color:#445B70;line-height:1.6}.structure-note p b{color:var(--text)}

.tracker-card{background:var(--card);border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow);overflow:visible}
.tracker-toolbar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 18px 14px;border-bottom:1px solid #E7EEF4;flex-wrap:wrap}
.year-level-bar{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 18px;border-bottom:1px solid #E7EEF4;background:#F8FBFD;flex-wrap:wrap}.year-level-label{display:flex;align-items:center;gap:8px;font-size:11px;font-weight:800;letter-spacing:.04em;color:#61768A;text-transform:uppercase;white-space:nowrap}.year-level-label i{color:#19A995;font-size:13px}.year-level-options{display:flex;align-items:center;gap:7px;flex-wrap:wrap}.year-level-option{height:32px;padding:0 12px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #C9D7E2;border-radius:999px;background:#fff;color:#587086;text-decoration:none;font-size:11px;font-weight:700;transition:.18s ease}.year-level-option:hover{border-color:#8ACFC5;background:#F1FAF8;color:#138D7D}.year-level-option.active{border-color:#6AC8BC;background:#E7F7F4;color:#118E7E;box-shadow:0 1px 2px rgba(25,179,157,.08)}
.tracker-heading{display:flex;align-items:center;gap:10px}.tracker-heading h2{font-size:18px;font-weight:700;color:var(--text)}.tracker-heading .count{font-size:12px;color:var(--muted)}
.toolbar-actions{display:flex;align-items:center;gap:8px;position:relative}
.filter-wrap{position:relative}.filter-toggle,.export-btn{height:36px;padding:0 13px;display:inline-flex;align-items:center;gap:8px;border-radius:8px;font-size:12px;font-weight:700;font-family:inherit;text-decoration:none;cursor:pointer}
.filter-toggle{background:#fff;border:1px solid #C9D7E2;color:#334C60}.filter-toggle:hover{background:#F8FAFC;border-color:#AFC1D0}.filter-toggle.active{border-color:var(--teal-border);color:#128D7E;background:var(--teal-soft)}
.filter-count{min-width:18px;height:18px;padding:0 5px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;background:var(--teal);color:#fff;font-size:10px;line-height:1}
.export-btn{background:#F4FBFA;border:1px solid #BEE6DF;color:#138D7D}.export-btn:hover{background:#E8F7F4}

.filter-menu{position:absolute;right:0;top:44px;width:310px;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 12px 30px rgba(30,70,100,.12);padding:14px;z-index:30;display:none}
.filter-menu.open{display:block}.filter-menu-title{font-size:12px;font-weight:800;color:var(--text);margin-bottom:10px}.filter-field{display:flex;flex-direction:column;gap:6px;margin-bottom:10px}.filter-field label{font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#71869A}.filter-field input,.filter-field select{width:100%;height:36px;background:#fff;border:1px solid #C8D6E1;border-radius:8px;color:var(--text);font:12px 'DM Sans',sans-serif;padding:0 11px;outline:none}.filter-field input:focus,.filter-field select:focus{border-color:var(--teal-border);box-shadow:0 0 0 3px rgba(25,179,157,.10)}
.filter-menu-actions{display:flex;justify-content:flex-end;gap:8px;padding-top:3px}.filter-apply,.filter-clear{height:34px;padding:0 12px;border-radius:8px;font:700 12px 'DM Sans',sans-serif;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px}.filter-apply{border:1px solid var(--teal);background:var(--teal);color:#fff}.filter-clear{border:1px solid #C8D6E1;background:#fff;color:#61768A}

.table-wrap{overflow:auto}
table.data{width:100%;border-collapse:separate;border-spacing:0;font-size:13px;min-width:840px}
table.data th{height:58px;text-align:left;color:#657A8E;font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;padding:0 15px;border-bottom:1px solid var(--line-strong);background:#FCFDFE;white-space:nowrap}
table.data th:first-child{padding-left:16px;width:32%}table.data th:nth-child(2){width:18%}table.data th:nth-child(3){width:10%}table.data th:nth-child(4){width:11%}table.data th:nth-child(5){width:13%}table.data th:nth-child(6){width:24%}
table.data td{height:92px;padding:0 15px;border-bottom:1px solid #E4EDF4;vertical-align:middle;background:#fff;color:var(--text)}
table.data tbody tr:hover td{background:#FBFEFD}table.data tbody tr:last-child td{border-bottom:none}
.stu-cell{display:flex;align-items:center;gap:12px;min-width:0}.stu-avatar{width:48px;height:48px;border-radius:50%;flex:0 0 48px;display:flex;align-items:center;justify-content:center;background:var(--teal-soft);border:1px solid #B6E6DE;color:#159C8A;font-size:18px}.stu-copy{min-width:0}.stu-name{font-size:14px;font-weight:700;color:#10263A;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.stu-sub{font-size:11px;color:#8092A3;margin-top:3px}
.level-pill{display:inline-flex;align-items:center;justify-content:center;min-width:82px;height:50px;padding:0 16px;border-radius:14px;border:1px solid var(--teal-border);background:var(--teal-soft);color:#19A995;font-size:12px;font-weight:800}
.req-number,.completed-number{font-size:14px;color:#344D60;font-weight:500}.completed-number{font-weight:500}
.status-pill{display:inline-flex;align-items:center;justify-content:center;padding:7px 12px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap}.status-pill.not_started{background:#F3F7FA;color:#6E8396}.status-pill.in_progress{background:var(--amber-soft);color:var(--amber)}.status-pill.completed{background:var(--green-soft);color:var(--green)}
.progress-cell{display:flex;align-items:center;gap:10px;min-width:0}.progress-pct{width:36px;flex:0 0 36px;font-size:12px;font-weight:600;color:#607589}.progress-main{min-width:130px;flex:1}.progress-track{width:100%;height:7px;border-radius:999px;background:#DDE7EF;overflow:hidden}.progress-fill{height:100%;border-radius:inherit;background:#15A57D;transition:width .2s}.progress-last{font-size:10.5px;color:#7C8FA0;margin-top:6px;white-space:nowrap}.progress-chevron{width:14px;flex:0 0 14px;color:#486176;font-size:17px;text-align:right}
.empty-note{color:#8295A6;font-size:13px;padding:26px 16px;text-align:center}.table-empty{padding:18px 0 8px}
.table-footer{display:flex;justify-content:space-between;align-items:center;padding:13px 16px 14px;font-size:12px;color:#7890A3;gap:12px;flex-wrap:wrap}.pagination{display:flex;align-items:center;gap:5px}.page-btn{min-width:30px;height:30px;padding:0 8px;display:flex;align-items:center;justify-content:center;border-radius:7px;background:#fff;border:1px solid #C9D7E2;color:#6D8194;text-decoration:none;font-size:12px;font-weight:700}.page-btn.active{background:#E8F7F4;color:#118E7E;border-color:#8BD6CB}.page-btn.disabled{opacity:.35;pointer-events:none}.page-ellipsis{color:#91A2B0;padding:0 3px}

.info-banner{display:flex;align-items:flex-start;gap:12px;background:#fff;border:1px solid var(--line);border-radius:12px;padding:15px 17px;margin-top:16px;box-shadow:var(--shadow)}.info-banner i{color:var(--blue);font-size:15px;margin-top:2px}.info-banner b{display:block;color:var(--text);font-size:12.5px;margin-bottom:2px}.info-banner p{font-size:12px;color:#74899B}.info-banner.closed{background:#FFF7F7;border-color:#F2D1D5}.info-banner.closed i{color:#D6455D}
.live-tracker{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border-radius:999px;background:#ECFDF5;border:1px solid #A7F3D0;color:#0F9F6E;font-size:10.5px;font-weight:800;letter-spacing:.02em}.live-tracker.offline{background:#F8FAFC;border-color:#DCE7F1;color:#8092A2}.live-dot{width:6px;height:6px;border-radius:50%;background:#0F9F6E;display:inline-block;animation:livePulse 2s ease-in-out infinite}.live-tracker.offline .live-dot{background:#9AA9B5;animation:none}@keyframes livePulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.8)}}.tracker-table-state.is-empty-state .table-footer{display:none}.tracker-live-updated{font-size:10.5px;color:#7A8FA1;margin-left:4px;white-space:nowrap}

@media(max-width:1000px){.main{padding:26px 22px 36px}.period-badge{width:100%;justify-content:flex-start}.tracker-toolbar{align-items:flex-start}.toolbar-actions{width:100%;justify-content:flex-end}.filter-menu{right:0}}
@media(max-width:768px){body{flex-direction:column}.sidebar{width:100%;min-height:auto}.main{padding:20px 14px 30px}.page-title{font-size:24px}.tracker-toolbar{padding:15px 14px}.toolbar-actions{justify-content:stretch}.filter-wrap,.filter-toggle,.export-btn{flex:1}.filter-toggle,.export-btn{justify-content:center}.filter-menu{width:min(310px,calc(100vw - 28px));right:0}.year-level-bar{align-items:flex-start;padding:12px 14px}.year-level-options{width:100%}.year-level-option{flex:1;min-width:92px}}
/* Dark theme: this page's own local classes (export button, live badge,
   status pills, filter controls, pagination, student rows) aren't part of
   includes/dean_light_theme.css, so they need overrides here. */
html.dark-theme .export-btn{background:rgba(45,212,191,.14)!important;border-color:rgba(45,212,191,.35)!important;color:#5EEAD4!important;}
html.dark-theme .export-btn:hover{background:rgba(45,212,191,.22)!important;}
html.dark-theme .live-tracker{background:rgba(16,185,129,.16)!important;border-color:rgba(16,185,129,.35)!important;color:#4ADE80!important;}
html.dark-theme .live-tracker.offline{background:#0F1F3D!important;border-color:rgba(255,255,255,.1)!important;color:#A0B3C6!important;}
html.dark-theme .live-tracker.offline .live-dot{background:#5B7186!important;}
html.dark-theme .status-pill.not_started{background:#0F1F3D!important;color:#A0B3C6!important;}
html.dark-theme .status-pill.in_progress{background:rgba(217,119,6,.18)!important;color:#FBBF24!important;}
html.dark-theme .status-pill.completed{background:rgba(16,185,129,.16)!important;color:#4ADE80!important;}
html.dark-theme .filter-toggle{background:#0F1F3D!important;border-color:rgba(255,255,255,.14)!important;color:#A0B3C6!important;}
html.dark-theme .filter-toggle:hover{background:#1D3350!important;border-color:rgba(255,255,255,.24)!important;}
html.dark-theme .filter-toggle.active{border-color:rgba(45,212,191,.4)!important;color:#5EEAD4!important;background:rgba(45,212,191,.14)!important;}
html.dark-theme .filter-count{background:#2DD4BF!important;color:#0A192F!important;}
html.dark-theme .filter-menu{background:#172A45!important;border-color:rgba(255,255,255,.1)!important;box-shadow:0 12px 30px rgba(0,0,0,.5)!important;}
html.dark-theme .filter-menu-title{color:#E0E6F0!important;}
html.dark-theme .filter-field label{color:#A0B3C6!important;}
html.dark-theme .filter-field input,html.dark-theme .filter-field select{background:#0F1F3D!important;border-color:rgba(255,255,255,.16)!important;color:#E0E6F0!important;}
html.dark-theme .filter-field input:focus,html.dark-theme .filter-field select:focus{border-color:#2DD4BF!important;box-shadow:0 0 0 3px rgba(45,212,191,.18)!important;}
html.dark-theme .filter-apply{background:#0D9488!important;border-color:#0D9488!important;color:#fff!important;}
html.dark-theme .filter-clear{background:#0F1F3D!important;border-color:rgba(255,255,255,.16)!important;color:#A0B3C6!important;}
html.dark-theme .page-btn{background:#172A45!important;border-color:rgba(255,255,255,.14)!important;color:#A0B3C6!important;}
html.dark-theme .page-btn.active{background:rgba(45,212,191,.18)!important;color:#5EEAD4!important;border-color:rgba(45,212,191,.4)!important;}
html.dark-theme .page-ellipsis{color:#5B7186!important;}
html.dark-theme .structure-note{background:#172A45!important;border-color:rgba(255,255,255,.1)!important;}
html.dark-theme .structure-note p{color:#A0B3C6!important;}
html.dark-theme .structure-note p b{color:#E0E6F0!important;}
html.dark-theme .info-banner{background:#172A45!important;border-color:rgba(255,255,255,.1)!important;}
html.dark-theme .info-banner b{color:#E0E6F0!important;}
html.dark-theme .info-banner p{color:#A0B3C6!important;}
html.dark-theme .info-banner.closed{background:rgba(240,84,84,.12)!important;border-color:rgba(240,84,84,.3)!important;}
html.dark-theme .empty-note{color:#A0B3C6!important;}
html.dark-theme .stu-name{color:#E0E6F0!important;}
html.dark-theme .stu-sub{color:#A0B3C6!important;}
html.dark-theme .stu-avatar{background:rgba(45,212,191,.14)!important;border-color:rgba(45,212,191,.35)!important;color:#5EEAD4!important;}
html.dark-theme .level-pill{background:rgba(45,212,191,.14)!important;border-color:rgba(45,212,191,.35)!important;color:#5EEAD4!important;}
html.dark-theme .req-number,html.dark-theme .completed-number{color:#A0B3C6!important;}
html.dark-theme .progress-pct{color:#A0B3C6!important;}
html.dark-theme .progress-track{background:rgba(255,255,255,.08)!important;}
html.dark-theme .progress-fill{background:#2DD4BF!important;}
html.dark-theme .progress-last{color:#8092A2!important;}
html.dark-theme .progress-chevron{color:#A0B3C6!important;}
html.dark-theme .table-footer{color:#A0B3C6!important;}
html.dark-theme .tracker-toolbar{border-bottom-color:rgba(255,255,255,.08)!important;}
html.dark-theme .tracker-heading h2{color:#E0E6F0!important;}
html.dark-theme .tracker-heading .count{color:#A0B3C6!important;}
html.dark-theme .year-level-bar{background:#10223D!important;border-bottom-color:rgba(255,255,255,.08)!important;}
html.dark-theme .year-level-label{color:#A0B3C6!important;}
html.dark-theme .year-level-label i{color:#5EEAD4!important;}
html.dark-theme .year-level-option{background:#0F1F3D!important;border-color:rgba(255,255,255,.14)!important;color:#A0B3C6!important;}
html.dark-theme .year-level-option:hover{background:#17304C!important;border-color:rgba(45,212,191,.35)!important;color:#5EEAD4!important;}
html.dark-theme .year-level-option.active{background:rgba(45,212,191,.14)!important;border-color:rgba(45,212,191,.42)!important;color:#5EEAD4!important;}
</style>
<link rel="stylesheet" href="includes/dean_light_theme.css?v=dashboard-ui-20261009"/>

<style>
/* Keep the Dean sidebar fixed and scroll this feature workspace internally. */
html.dean-internal-scroll-page,
html.dean-internal-scroll-page body {
  overflow: hidden !important;
  height: 100% !important;
}

main.main.dean-internal-scroll {
  height: calc(100vh - 20px) !important;
  max-height: calc(100vh - 20px) !important;
  min-height: 0 !important;
  overflow-y: scroll !important;
  overflow-x: hidden !important;
  scrollbar-gutter: stable;
  overscroll-behavior: contain;
  scrollbar-width: thin;
  scrollbar-color: #AEBAC8 #EEF2F6;
}

main.main.dean-internal-scroll::-webkit-scrollbar {
  width: 10px;
}

main.main.dean-internal-scroll::-webkit-scrollbar-track {
  background: #EEF2F6;
  border-radius: 10px;
}

main.main.dean-internal-scroll::-webkit-scrollbar-thumb {
  background: #AEBAC8;
  border: 2px solid #EEF2F6;
  border-radius: 10px;
}

main.main.dean-internal-scroll::-webkit-scrollbar-thumb:hover {
  background: #8F9CAB;
}

html.dark-theme main.main.dean-internal-scroll {
  scrollbar-color: #2A4468 #0F1F3D;
}

html.dark-theme main.main.dean-internal-scroll::-webkit-scrollbar-track {
  background: #0F1F3D;
}

html.dark-theme main.main.dean-internal-scroll::-webkit-scrollbar-thumb {
  background: #2A4468;
  border-color: #0F1F3D;
}

html.dark-theme main.main.dean-internal-scroll::-webkit-scrollbar-thumb:hover {
  background: #385A86;
}

@media (max-width: 768px) {
  html.dean-internal-scroll-page,
  html.dean-internal-scroll-page body {
    overflow: auto !important;
    height: auto !important;
  }

  main.main.dean-internal-scroll {
    height: auto !important;
    max-height: none !important;
    min-height: calc(100vh - 12px) !important;
    overflow: visible !important;
    scrollbar-gutter: auto;
  }
}
</style>
<style id="dean-tracker-ea-style">
/* EA-style tracker layout: header, pill tabs, summary cards, chip filters */
.ea-header{display:flex;justify-content:flex-end;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:16px}
.ea-title-wrap{display:flex;align-items:center;gap:12px}
.ea-title-icon{width:38px;height:38px;border-radius:10px;background:#F1EDFC;border:1px solid #D9CFF7;color:#7C5FD9;display:inline-flex;align-items:center;justify-content:center;font-size:16px}
.ea-title-wrap .page-title{font-size:21px}
.ea-updated{display:inline-flex;align-items:center;gap:10px;font-size:12px;color:var(--muted)}
.ea-updated i{color:#7C5FD9;font-size:13px}.ea-updated i.spin{animation:eaSpin .9s linear infinite}.ea-updated.offline i{color:#8092A2}
@keyframes eaSpin{to{transform:rotate(360deg)}}

.ea-tabs{display:inline-flex;gap:8px;padding:0;background:transparent;border:0;border-radius:0;box-shadow:none;margin-bottom:16px;flex-wrap:wrap}
.ea-tab{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:12px;border:1px solid var(--line);background:#fff;box-shadow:0 2px 4px rgba(30,82,144,.05);text-decoration:none;font-size:13px;font-weight:700;color:#5A7083;transition:background .15s,border-color .15s,color .15s}
.ea-tab:hover{background:#F4F0FD;border-color:#D6CBF4;color:#7C5FD9}
.ea-tab.active{background:#7C5FD9;border-color:#7C5FD9;color:#fff;box-shadow:0 2px 8px rgba(124,95,217,.28)}
.ea-count{min-width:22px;height:19px;padding:0 6px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;background:#EDE7FB;color:#7C5FD9}
.ea-tab.active .ea-count{background:rgba(255,255,255,.22);color:#fff}

.ea-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px}
.ea-card{background:#fff;border:1px solid var(--line);border-top:3px solid #7C5FD9;border-radius:12px;box-shadow:var(--shadow);padding:14px 16px 12px;display:flex;flex-direction:column;gap:6px}
.ea-card{text-decoration:none;color:inherit}
.ea-cards.is-filtered{grid-template-columns:calc((100% - 36px)/4)}
@media(max-width:900px){.ea-cards.is-filtered{grid-template-columns:1fr}}
.ea-card.is-filter{cursor:pointer;transition:transform .15s,box-shadow .15s,border-color .15s}
.ea-card.is-filter:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(124,95,217,.16);border-color:#CFC2F4}
.ea-card.is-filter.active{background:#F4F0FD;border-color:#7C5FD9;box-shadow:0 0 0 2px rgba(124,95,217,.22)}
.ea-card.is-filter.active .ea-card-label{color:#6A4CC4}
html.dark-theme .ea-card.is-filter.active{background:rgba(124,95,217,.18)!important;border-color:#9C85F0!important}
html.dark-theme .ea-card.is-filter:hover{border-color:rgba(156,133,240,.5)!important}
.ea-card-top{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
.ea-card-label{font-size:10.5px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:#5F7488}
.ea-card-value{font-size:24px;font-weight:700;color:var(--text);line-height:1.1;margin-top:4px}
.ea-card-icon{width:34px;height:30px;border-radius:8px;background:#EAF1FE;border:1px solid #C9D9F7;color:#2563EB;display:inline-flex;align-items:center;justify-content:center;font-size:12px}
.ea-card-foot{border-top:1px solid #E4EDF4;padding-top:9px;margin-top:2px;font-size:12px;color:#6D8194;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.ea-card-foot b{color:#7C5FD9;font-weight:700}.ea-card-foot .sep{color:#C5D2DD}

.ea-filterbar{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:12px}
.ea-chips{display:flex;gap:10px;flex-wrap:wrap}
.ea-chip{height:36px;padding:0 14px;display:inline-flex;align-items:center;border-radius:10px;border:1px solid #C9D7E2;background:#fff;color:#334C60;font-size:12px;font-weight:700;text-decoration:none;transition:all .15s}
.ea-chip:hover{border-color:#B7A3EC;color:#7C5FD9}
.ea-chip.active{background:#7C5FD9;border-color:#7C5FD9;color:#fff}
.ea-controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto}
.ea-select{height:36px;padding:0 28px 0 12px;border:1px solid #C9D7E2;border-radius:8px;background:#fff;color:#12263A;font:500 13px 'DM Sans',sans-serif;cursor:pointer}
.ea-search{position:relative;width:240px;max-width:100%}
.ea-search i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#2563EB;font-size:13px;pointer-events:none}
.ea-search input{width:100%;height:36px;padding:0 12px 0 34px;border:1px solid #C9D7E2;border-radius:8px;background:#fff;color:#12263A;font:500 13px 'DM Sans',sans-serif}
.ea-search input:focus,.ea-select:focus{outline:none;border-color:#7C5FD9;box-shadow:0 0 0 3px rgba(124,95,217,.15)}
/* Tabs on the left, status filter + search on the opposite (right) side, same row */
.ea-toprow{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:16px}
.ea-toprow .ea-tabs{margin-bottom:0}
.ea-toprow .ea-controls{margin-left:auto}
.ea-toprow .ea-search{width:310px}
.ea-search input{text-overflow:ellipsis}
.ea-filterbar:not(:has(.ea-chips)){margin:0}
@media(max-width:768px){.ea-toprow .ea-search{width:100%}.ea-toprow .ea-controls{width:100%}}
.ea-export{height:36px;padding:0 13px;display:inline-flex;align-items:center;gap:7px;border-radius:8px;border:1px solid #D9CFF7;background:#F4F0FD;color:#7C5FD9;font-size:12px;font-weight:700;text-decoration:none}
.ea-export:hover{background:#EAE3FB}

.tracker-card{overflow:hidden}
table.data{min-width:760px}
table.data th{height:46px}
table.data td{height:72px}
.ea-header + .ea-tabs ~ .tracker-card .stu-avatar{width:40px;height:40px;flex-basis:40px;font-size:15px}
.tracker-card .level-pill{height:40px;min-width:74px;font-size:11px;border-radius:11px}
.tracker-card .stu-name{font-size:13px}
.stu-sub{font-size:12px;color:var(--muted);margin-top:2px}

@media(max-width:768px){.ea-tab{padding:10px 14px;font-size:14px}.ea-search{width:100%}.ea-controls{width:100%;margin-left:0}.ea-select{flex:1}}

html.dark-theme .ea-title-icon{background:rgba(124,95,217,.14)!important;border-color:rgba(124,95,217,.35)!important;color:#B7A3EC!important}
html.dark-theme .ea-updated{color:#A0B3C6!important}
html.dark-theme .ea-card{background:#172A45!important;border-color:rgba(255,255,255,.1)!important}
html.dark-theme .ea-tabs{background:transparent!important;border:0!important}
html.dark-theme .ea-tab{background:#172A45!important;border-color:rgba(255,255,255,.1)!important}
html.dark-theme .ea-card{border-top-color:#9C85F0!important}
html.dark-theme .ea-tab{color:#A0B3C6!important}
html.dark-theme .ea-tab:hover{background:rgba(124,95,217,.12)!important;color:#B7A3EC!important}
html.dark-theme .ea-tab.active{background:#7C5FD9!important;border-color:#7C5FD9!important;color:#fff!important}
html.dark-theme .ea-count{background:rgba(124,95,217,.18)!important;color:#B7A3EC!important}
html.dark-theme .ea-tab.active .ea-count{background:rgba(255,255,255,.22)!important;color:#fff!important}
html.dark-theme .ea-card-label,html.dark-theme .ea-card-foot{color:#A0B3C6!important}
html.dark-theme .ea-card-value{color:#E0E6F0!important}
html.dark-theme .ea-card-foot{border-top-color:rgba(255,255,255,.08)!important}
html.dark-theme .ea-card-icon{background:rgba(59,130,246,.16)!important;border-color:rgba(59,130,246,.35)!important;color:#93C5FD!important}
html.dark-theme .ea-chip,html.dark-theme .ea-select,html.dark-theme .ea-search input{background:#0F1F3D!important;border-color:rgba(255,255,255,.14)!important;color:#E0E6F0!important}
html.dark-theme .ea-chip{color:#A0B3C6!important}
html.dark-theme .ea-chip.active{background:#7C5FD9!important;border-color:#7C5FD9!important;color:#fff!important}
html.dark-theme .ea-export{background:rgba(124,95,217,.14)!important;border-color:rgba(124,95,217,.35)!important;color:#B7A3EC!important}

/* Dean violet accents for the shared table components */
.tracker-card .level-pill{background:#F1EDFC;border-color:#CFC2F4;color:#6A4CC4}
.tracker-card .stu-avatar{background:#F1EDFC;border-color:#D9CFF7;color:#7C5FD9}
.tracker-card .progress-fill{background:#7C5FD9}
.tracker-card .page-btn.active{background:#F1EDFC;border-color:#CFC2F4;color:#6A4CC4}
html.dark-theme .tracker-card .level-pill,html.dark-theme .tracker-card .stu-avatar{background:rgba(124,95,217,.18)!important;border-color:rgba(156,133,240,.4)!important;color:#B7A3EC!important}
html.dark-theme .tracker-card .progress-fill{background:#9C85F0!important}
html.dark-theme .tracker-card .page-btn.active{background:rgba(124,95,217,.2)!important;border-color:rgba(156,133,240,.45)!important;color:#B7A3EC!important}
</style>
<style id="dean-tracker-expand">
/* Clickable rows + per-row breakdown of every evaluation target */
table.data tbody tr.trk-row{cursor:pointer}
table.data tbody tr.trk-row:focus-visible{outline:2px solid #7C5FD9;outline-offset:-2px}
table.data tbody tr.trk-row.open td{background:#FAF8FE}
.progress-chevron{display:inline-block;transition:transform .18s;font-size:18px;line-height:1}
tr.trk-row.open .progress-chevron{transform:rotate(90deg)}
tr.trk-detail[hidden]{display:none}
table.data tbody tr.trk-detail td{height:auto;padding:16px 22px 20px;background:#F5F8FC;border-bottom:1px solid #E4EDF4}
.trk-detail-head{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;margin:0 0 12px}
.trk-detail-head strong{font-size:13.5px;color:var(--text)}
.trk-detail-head span{font-size:12px;color:var(--muted)}
.trk-detail-empty{margin:0;font-size:13px;color:var(--muted)}
.trk-targets{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:8px}
.trk-target{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:10px;background:#fff;border:1px solid var(--line);font-size:13px;min-width:0}
.trk-target i{flex:0 0 auto;font-size:14px}
.trk-target.completed i{color:var(--green)}
.trk-target.in_progress i{color:var(--amber)}
.trk-target.pending i{color:var(--muted);opacity:.55}
.trk-target-name{flex:1;min-width:0;font-weight:600;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.trk-target-type{flex:0 0 auto;padding:2px 8px;border-radius:999px;background:#F1EDFC;color:#6A4CC4;font-size:10.5px;font-weight:800}
.trk-target-state{flex:0 0 auto;font-size:11.5px;color:var(--muted)}
html.dark-theme table.data tbody tr.trk-row.open td{background:#1D3350 !important}
html.dark-theme table.data tbody tr.trk-detail td{background:#10223D !important;border-bottom-color:rgba(255,255,255,.08) !important}
html.dark-theme .trk-detail-head strong,html.dark-theme .trk-target-name{color:#E0E6F0 !important}
html.dark-theme .trk-detail-head span,html.dark-theme .trk-detail-empty,html.dark-theme .trk-target-state{color:#A0B3C6 !important}
html.dark-theme .trk-target{background:#172A45 !important;border-color:rgba(255,255,255,.1) !important}
html.dark-theme .trk-target-type{background:rgba(124,95,217,.18) !important;color:#B7A3EC !important}
html.dark-theme .trk-target.completed i{color:#4ADE80 !important}
html.dark-theme .trk-target.in_progress i{color:#FBBF24 !important}
</style>
<style id="dean-tracker-ref-ui">
/* Reference-style tracker: status chips, sortable headers, live badge, round chevron */
.ea-toprow .ea-controls{gap:10px;align-items:center}
.ea-statusbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:0 0 14px}
.ea-stats{display:flex;gap:10px;flex-wrap:wrap}
.stat-chip{display:inline-flex;align-items:center;gap:7px;height:38px;padding:0 16px;border-radius:999px;border:1px solid #C9D7E2;background:#fff;color:#334C60;font-size:13px;font-weight:600;text-decoration:none;transition:transform .15s,box-shadow .15s,border-color .15s}
.stat-chip b{font-size:14px;font-weight:700}
.stat-chip:hover{transform:translateY(-1px);box-shadow:var(--shadow)}
.stat-chip.stat-completed{background:var(--green-soft);border-color:#BFE8D6;color:var(--green)}
.stat-chip.stat-in_progress{background:var(--amber-soft);border-color:#F4D7A0;color:var(--amber)}
.stat-chip.stat-not_started{background:#F3F7FA;border-color:#C9D7E2;color:#5F7488}
.stat-chip.active{border-color:#7C5FD9;box-shadow:0 0 0 3px rgba(124,95,217,.22)}
.clear-filters{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700;color:#7C5FD9;text-decoration:none;padding:6px 4px}
.clear-filters:hover{color:#6A4CC4;text-decoration:underline}
table.data th.sortable a{color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:7px}
table.data th.sortable i{font-size:10px;opacity:.4;transition:opacity .15s}
table.data th.sortable a:hover i,table.data th.sortable.sorted i{opacity:1;color:#7C5FD9}
table.data th.sortable.sorted a{color:#6A4CC4}
.live-tracker{height:28px;padding:0 10px;gap:7px;font-size:11px;white-space:nowrap}
.live-tracker.syncing .live-dot{animation-duration:.6s}
.progress-chevron{width:30px!important;height:30px;flex:0 0 30px!important;border-radius:50%;background:#F1EDFC;border:1px solid #D9CFF7;color:#7C5FD9!important;display:inline-flex!important;align-items:center;justify-content:center;font-size:18px;line-height:1;padding-bottom:2px}
@media(max-width:768px){.stat-chip{flex:1;justify-content:center;padding:0 10px}.live-tracker{display:none}}
html.dark-theme .stat-chip{background:#172A45!important;border-color:rgba(255,255,255,.14)!important;color:#A0B3C6!important}
html.dark-theme .stat-chip.stat-completed{background:rgba(16,185,129,.16)!important;border-color:rgba(16,185,129,.35)!important;color:#4ADE80!important}
html.dark-theme .stat-chip.stat-in_progress{background:rgba(217,119,6,.18)!important;border-color:rgba(217,119,6,.4)!important;color:#FBBF24!important}
html.dark-theme .stat-chip.active{border-color:#9C85F0!important;box-shadow:0 0 0 3px rgba(156,133,240,.28)!important}
html.dark-theme .clear-filters{color:#B7A3EC!important}
html.dark-theme table.data th.sortable.sorted a,html.dark-theme table.data th.sortable a:hover i{color:#B7A3EC!important}
html.dark-theme .progress-chevron{background:rgba(124,95,217,.18)!important;border-color:rgba(156,133,240,.4)!important;color:#B7A3EC!important}
</style>
</head>
<body>

<?php
$active = 'tracker';
$sidebarScope = HIGHER_ED_LABEL . ' Division';
include __DIR__ . '/includes/dean_sidebar.php'; if (is_file(__DIR__ . '/dean_header_dark.php')) include __DIR__ . '/dean_header_dark.php';
?>

<main class="main dean-internal-scroll">
    <?php if (!$structureActive): ?>
    <div class="structure-note">
        <i class="fa-solid fa-circle-info"></i>
        <p>
            <b><?= HIGHER_ED_LABEL ?> is not the active academic structure.</b><br>
            The current evaluation period is configured for <b><?= htmlspecialchars($settings['academic_structure_label']) ?></b>.
            Tracker data is unavailable until the Executive Assistant switches it back.
        </p>
    </div>
    <?php else: ?>

    <div class="ea-toprow">
    <div class="ea-tabs" role="tablist" aria-label="Tracker categories">
        <?php foreach (['students' => 'Students', 'faculty' => 'Faculty', 'staff' => 'Staff'] as $tk => $tl): ?>
        <a class="ea-tab<?= $tab === $tk ? ' active' : '' ?>" role="tab" data-tab="<?= $tk ?>" href="?tab=<?= $tk ?>"><?= $tl ?></a>
        <?php endforeach; ?>
    </div>
        <div class="ea-controls">
            <label class="ea-search"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" form="trackerFilterForm" name="search" id="filterSearch" placeholder="Search <?= htmlspecialchars($tabNoun) ?> by name..." value="<?= htmlspecialchars($search) ?>"></label>
            <span class="live-tracker" id="trackerLiveStatus" title="Live updates on"><span class="live-dot"></span><span>Live</span></span>
        </div>
    </div>


    <form class="ea-filterbar" method="GET" action="dean_evaluation_tracker.php" id="trackerFilterForm">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
        <input type="hidden" name="page" value="1">
        <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>"><?php endif; ?>
        <?php if ($sort !== ''): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>"><input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>"><?php endif; ?>
        <?php if ($tab === 'students'): ?>
        <?php if ($yearLevel !== ''): ?><input type="hidden" name="year_level" value="<?= htmlspecialchars($yearLevel) ?>"><?php endif; ?>
        <div class="ea-chips" aria-label="Filter students by college year level">
            <a class="ea-chip<?= $yearLevel === '' ? ' active' : '' ?>" href="<?= tracker_qs(['year_level' => '', 'page' => 1]) ?>">All Levels</a>
            <?php foreach ($yearLevelOptions as $val => $lbl): ?>
            <a class="ea-chip<?= $yearLevel === $val ? ' active' : '' ?>" href="<?= tracker_qs(['year_level' => $val, 'page' => 1]) ?>"><?= htmlspecialchars($lbl) ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </form>

    <?php
    $statChips = ['total' => ['Total', ''], 'completed' => ['Completed', 'completed'], 'in_progress' => ['In progress', 'in_progress'], 'not_started' => ['Not started', 'not_started']];
    $hasFilters = ($search !== '' || $yearLevel !== '' || $status !== '' || $sort !== '');
    ?>
    <div class="ea-statusbar" id="trackerStatusBar">
        <div class="ea-stats" role="group" aria-label="Filter by status">
            <?php foreach ($statChips as $k => [$lbl, $val]):
                $isActive = ($val !== '' && $status === $val); ?>
            <a class="stat-chip stat-<?= $k ?><?= $isActive ? ' active' : '' ?>" href="<?= tracker_qs(['status' => $isActive ? '' : $val, 'page' => 1]) ?>"><b id="stat_<?= $k ?>"><?= (int)$statusCounts[$k] ?></b> <?= $lbl ?></a>
            <?php endforeach; ?>
        </div>
        <?php if ($hasFilters): ?>
        <a class="clear-filters" href="?tab=<?= htmlspecialchars($tab) ?>"><i class="fa-solid fa-xmark"></i> Clear filters</a>
        <?php endif; ?>
    </div>

    <div class="tracker-card">
        <div class="tracker-table-state<?= (!$hasPeriod || empty($pageRows)) ? ' is-empty-state' : '' ?>" id="trackerTableState">
        <?php if (!$hasPeriod): ?>
            <div class="table-empty"><p class="empty-note">No active evaluation period right now.</p></div>
        <?php else: ?>
        <div class="table-wrap" id="trackerTableWrap">
            <table class="data">
                <thead>
                    <tr>
                        <?= tracker_th($tabHead, 'name', $sort, $dir) ?>
                        <?= tracker_th('Level', 'level', $sort, $dir) ?>
                        <?= tracker_th('Required', 'required', $sort, $dir) ?>
                        <?= tracker_th('Completed', 'completed', $sort, $dir) ?>
                        <?= tracker_th('Status', 'status', $sort, $dir) ?>
                        <?= tracker_th('Progress', 'progress', $sort, $dir) ?>
                    </tr>
                </thead>
                <tbody id="trackerTableBody">
                    <?php if (empty($pageRows)): ?>
                    <tr><td colspan="6"><p class="empty-note">No <?= htmlspecialchars($tabNounP) ?> match the current filters.</p></td></tr>
                    <?php else: ?>
                    <?php foreach ($pageRows as $s) echo dean_render_row($s); ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer" id="trackerTableFooter">
            <div id="trackerShowingText"><?php if ($totalAssigned > 0): ?>Showing <?= (($page - 1) * $perPage) + 1 ?>–<?= min($totalAssigned, $page * $perPage) ?> of <?= $totalAssigned ?> <?= htmlspecialchars($tabNounP) ?><?php else: ?>No <?= htmlspecialchars($tabNounP) ?> to display<?php endif; ?></div>
            <div class="pagination" id="trackerPagination">
                <?php if ($totalPages > 1): ?>
                <?php
                $shown = [];
                for ($i = 1; $i <= $totalPages; $i++) {
                    if ($i === 1 || $i === $totalPages || abs($i - $page) <= 2) $shown[] = $i;
                }
                $prev = null;
                ?>
                <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= tracker_qs(['page' => max(1, $page - 1)]) ?>" aria-label="Previous page"><i class="fa-solid fa-chevron-left"></i></a>
                <?php foreach ($shown as $i): ?>
                    <?php if ($prev !== null && $i - $prev > 1): ?><span class="page-ellipsis">…</span><?php endif; ?>
                    <a class="page-btn <?= $i === $page ? 'active' : '' ?>" href="<?= tracker_qs(['page' => $i]) ?>"><?= $i ?></a>
                <?php $prev = $i; endforeach; ?>
                <a class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= tracker_qs(['page' => min($totalPages, $page + 1)]) ?>" aria-label="Next page"><i class="fa-solid fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>
</main>
<script>
(function(){
    const form = document.getElementById('trackerFilterForm');
    // (status filtering now lives in the status chips, not a dropdown)

    const liveStatus = document.getElementById('trackerLiveStatus');
    const cardsEl = document.getElementById('eaCards');
    const stateEl = document.getElementById('trackerTableState');
    const bodyEl = document.getElementById('trackerTableBody');
    const footerEl = document.getElementById('trackerTableFooter');
    const showingEl = document.getElementById('trackerShowingText');
    const paginationEl = document.getElementById('trackerPagination');
    if (!bodyEl || !stateEl) return;

    const PER_PAGE = <?= (int)$perPage ?>;
    const NOUN_P = <?= json_encode($tabNounP) ?>;
    const CARD_ICON = <?= json_encode($tab === 'students' ? 'graduation-cap' : ($tab === 'faculty' ? 'chalkboard-user' : 'briefcase')) ?>;
    let busy = false, lastSignature = '';

    function esc(v){
        return String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }
    function setUpdated(label, offline){
        if (!liveStatus) return;
        liveStatus.classList.toggle('offline', !!offline);
        liveStatus.title = label;
        liveStatus.innerHTML = '<span class="live-dot"></span><span>' + (offline ? 'Offline' : 'Live') + '</span>';
    }
    function formatDate(value){
        const d = new Date(value);
        if (Number.isNaN(d.getTime())) return value;
        return d.toLocaleString(undefined,{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'});
    }
    const CAPTION = <?= json_encode(['students' => 'Targets the student must evaluate', 'faculty' => 'Peer evaluations the faculty member must submit', 'staff' => 'Evaluations the staff member must submit'][$tab]) ?>;
    const openKeys = new Set();
    function detailHtml(s){
        const d = s.details || [];
        if (!d.length) return '<p class="trk-detail-empty">No required evaluation targets are configured for this ' + esc(s.noun) + ' yet.</p>';
        let h = '<div class="trk-detail-head"><strong>' + Number(s.completed || 0) + ' of ' + Number(s.required || 0) + ' required evaluations completed</strong><span>' + esc(CAPTION) + '</span></div><ul class="trk-targets">';
        d.forEach(t => {
            const icon = t.state === 'completed' ? 'fa-circle-check' : (t.state === 'in_progress' ? 'fa-circle-half-stroke' : 'fa-circle');
            const txt  = t.state === 'completed' ? 'Done' : (t.state === 'in_progress' ? 'In progress' : 'Pending');
            h += '<li class="trk-target ' + esc(t.state) + '"><i class="fa-solid ' + icon + '"></i><span class="trk-target-name" title="' + esc(t.label + ' (' + t.type + ')') + '">' + esc(t.label) + '</span><span class="trk-target-type">' + esc(t.type) + '</span><span class="trk-target-state">' + txt + '</span></li>';
        });
        return h + '</ul>';
    }
    function rowHtml(s){
        const last = s.submitted_at ? '<div class="progress-last">Last: ' + esc(formatDate(s.submitted_at)) + '</div>' : '';
        const p = Number(s.progress || 0);
        const isOpen = openKeys.has(s.key);
        return '<tr class="trk-row' + (isOpen ? ' open' : '') + '" data-key="' + esc(s.key) + '" tabindex="0" aria-expanded="' + (isOpen ? 'true' : 'false') + '">' +
            '<td><div class="stu-cell"><span class="stu-avatar"><i class="fa-solid fa-user"></i></span><div class="stu-copy"><div class="stu-name">' + esc(s.name) + '</div><div class="stu-sub">' + esc(s.sub) + '</div></div></div></td>' +
            '<td><span class="level-pill">' + esc(s.level) + '</span></td>' +
            '<td><span class="req-number">' + Number(s.required || 0).toLocaleString() + '</span></td>' +
            '<td><span class="completed-number">' + Number(s.completed || 0).toLocaleString() + ' / ' + Number(s.required || 0).toLocaleString() + '</span></td>' +
            '<td><span class="status-pill ' + esc(s.status) + '">' + esc(s.status_label) + '</span></td>' +
            '<td><div class="progress-cell"><span class="progress-pct">' + p + '%</span><div class="progress-main"><div class="progress-track" aria-label="' + p + ' percent complete"><div class="progress-fill" style="width:' + p + '%"></div></div>' + last + '</div><span class="progress-chevron" aria-hidden="true">›</span></div></td>' +
            '</tr>' +
            '<tr class="trk-detail" data-key="' + esc(s.key) + '"' + (isOpen ? '' : ' hidden') + '><td colspan="6">' + detailHtml(s) + '</td></tr>';
    }
    function toggleRow(row){
        const key = row.dataset.key;
        const on = !row.classList.contains('open');
        row.classList.toggle('open', on);
        row.setAttribute('aria-expanded', on ? 'true' : 'false');
        const detail = row.nextElementSibling;
        if (detail && detail.classList.contains('trk-detail')) detail.hidden = !on;
        if (on) openKeys.add(key); else openKeys.delete(key);
    }
    bodyEl.addEventListener('click', e => {
        const row = e.target.closest('tr.trk-row');
        if (row && bodyEl.contains(row)) toggleRow(row);
    });
    bodyEl.addEventListener('keydown', e => {
        if ((e.key === 'Enter' || e.key === ' ') && e.target.classList && e.target.classList.contains('trk-row')) {
            e.preventDefault();
            toggleRow(e.target);
        }
    });
    function cardHtml(c){
        const inner = '<div class="ea-card-top"><div><div class="ea-card-label">' + esc(c.label) + '</div><div class="ea-card-value">' + Number(c.value || 0) + '</div></div><span class="ea-card-icon"><i class="fa-solid fa-' + CARD_ICON + '"></i></span></div>' +
            '<div class="ea-card-foot"><b>' + Number(c.completed || 0) + ' completed</b><span class="sep">|</span><span>' + Number(c.pending || 0) + ' pending</span></div>';
        if (!c.key) return '<div class="ea-card">' + inner + '</div>';
        const cur = new URL(window.location.href).searchParams.get('year_level') || '';
        const active = cur === c.key;
        const u = new URL(window.location.href);
        u.searchParams.delete('ajax'); u.searchParams.delete('export'); u.searchParams.delete('_ts');
        u.searchParams.set('page','1');
        if (active) u.searchParams.delete('year_level'); else u.searchParams.set('year_level', c.key);
        return '<a class="ea-card is-filter' + (active ? ' active' : '') + '" data-year="' + esc(c.key) + '" href="' + esc(u.pathname + u.search) + '" title="' + (active ? 'Click to clear filter' : 'Filter by ' + esc(c.label)) + '">' + inner + '</a>';
    }
    function pageUrl(page){
        const u = new URL(window.location.href);
        u.searchParams.set('page', String(page));
        u.searchParams.delete('ajax');
        return u.toString();
    }
    function renderPagination(page,totalPages){
        if (!paginationEl) return;
        if (totalPages <= 1){ paginationEl.innerHTML=''; return; }
        const shown=[];
        for(let i=1;i<=totalPages;i++) if(i===1||i===totalPages||Math.abs(i-page)<=2) shown.push(i);
        let html='<a class="page-btn ' + (page<=1?'disabled':'') + '" href="' + esc(pageUrl(Math.max(1,page-1))) + '" aria-label="Previous page"><i class="fa-solid fa-chevron-left"></i></a>';
        let prev=null;
        shown.forEach(i=>{
            if(prev!==null && i-prev>1) html+='<span class="page-ellipsis">…</span>';
            html+='<a class="page-btn ' + (i===page?'active':'') + '" href="' + esc(pageUrl(i)) + '">' + i + '</a>';
            prev=i;
        });
        html+='<a class="page-btn ' + (page>=totalPages?'disabled':'') + '" href="' + esc(pageUrl(Math.min(totalPages,page+1))) + '" aria-label="Next page"><i class="fa-solid fa-chevron-right"></i></a>';
        paginationEl.innerHTML=html;
    }
    async function refresh(){
        if (busy) return;
        busy = true;
        if (liveStatus) liveStatus.classList.add('syncing');
        try {
            const u = new URL(window.location.href);
            u.searchParams.set('ajax','1');
            u.searchParams.delete('export');
            u.searchParams.set('_ts', Date.now().toString());
            const res = await fetch(u.toString(), {credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});
            if(!res.ok) throw new Error('Tracker update failed');
            const d = await res.json();
            if(!d.ok) throw new Error('Tracker update failed');

            const sig = JSON.stringify([d.counts,d.statusCounts,d.cards,d.total,d.totalPages,d.page,d.rows]);
            if(sig !== lastSignature){
                lastSignature = sig;
                Object.keys(d.counts || {}).forEach(k=>{ const el=document.getElementById('tabCount_'+k); if(el) el.textContent=Number(d.counts[k]||0); });
                Object.keys(d.statusCounts || {}).forEach(k=>{ const el=document.getElementById('stat_'+k); if(el) el.textContent=Number(d.statusCounts[k]||0); });
                if(cardsEl && d.cards) cardsEl.innerHTML = d.cards.map(cardHtml).join('');
                if(d.rows && d.rows.length){
                    stateEl.classList.remove('is-empty-state');
                    bodyEl.innerHTML = d.rows.map(rowHtml).join('');
                    if(footerEl) footerEl.style.display='flex';
                    if(showingEl){
                        const first=((Number(d.page||1)-1)*PER_PAGE)+1;
                        const last=Math.min(Number(d.total||0),Number(d.page||1)*PER_PAGE);
                        showingEl.textContent='Showing ' + first + '–' + last + ' of ' + Number(d.total||0).toLocaleString() + ' ' + NOUN_P;
                    }
                    renderPagination(Number(d.page||1),Number(d.totalPages||1));
                }else{
                    stateEl.classList.add('is-empty-state');
                    bodyEl.innerHTML='<tr><td colspan="6"><p class="empty-note">No ' + esc(NOUN_P) + ' match the current filters.</p></td></tr>';
                    if(footerEl) footerEl.style.display='none';
                    if(showingEl) showingEl.textContent='No ' + NOUN_P + ' to display';
                    if(paginationEl) paginationEl.innerHTML='';
                }
            }
            setUpdated('Last updated: ' + (d.updatedLabel || new Date().toLocaleString()), false);
        } catch(e){
            setUpdated('Live updates paused', true);
        } finally {
            if (liveStatus) liveStatus.classList.remove('syncing');
            busy = false;
        }
    }
    setInterval(refresh,5000);
})();
</script>

<style id="dean-tracker-dark-final">
/* Final dark-mode correction for the tracker table.
   The shared light-theme layer changes text/borders but the tracker page
   itself hardcodes white table cells, so the row surfaces need an explicit
   dark background when the shared dark-theme class is active. */
html.dark-theme table.data tbody td {
  background:#172A45 !important;
  color:#E0E6F0 !important;
  border-bottom-color:rgba(255,255,255,.08) !important;
}
html.dark-theme table.data tbody tr:hover td {
  background:#1D3350 !important;
}
html.dark-theme table.data thead th {
  background:#0F1F3D !important;
  color:#A0B3C6 !important;
  border-bottom-color:rgba(255,255,255,.12) !important;
}
</style>
</body>
<link rel="stylesheet" href="includes/dean_light_theme.css?v=dashboard-ui-20261009" id="dean-light-theme-final"/>
</html>